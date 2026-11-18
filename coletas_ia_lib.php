<?php
/**
 * v3.50.0 — Gemini como assistente de endereço e de rota nas Coletas.
 *
 * Princípio: a IA SUGERE, a pessoa decide. Nada aqui grava no cadastro nem inicia rota.
 *  - col_ia_endereco: normaliza/corrige o endereço usando evidências (CEP oficial, CNPJ, coletas anteriores).
 *  - col_ia_rota: revisa a rota (coordenada incompatível com a cidade, ordem ruim) e sugere uma ordem,
 *    que o servidor valida (permutação exata) e mede com o mesmo estimador de km da tela.
 * Sem chave do Gemini, o endereço ainda é conferido só pelas evidências (CEP/CNPJ) e a rota por regras.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/coletas_lib.php';
require_once __DIR__ . '/gemini_import.php';

/** Chama o Gemini (JSON). Ponto único, com gancho de teste. */
function col_ia_chamar(string $prompt, int $tokens = 1500): ?array {
    if (!empty($GLOBALS['COL_IA_STUB']) && is_callable($GLOBALS['COL_IA_STUB'])) return ($GLOBALS['COL_IA_STUB'])($prompt);
    if (!rd_gemini_ready()) return null;
    $txt = rd_gemini_call([['text' => $prompt]], $tokens);
    $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($txt)));
    $j = json_decode($txt, true);
    return is_array($j) ? $j : null;
}

function col_ia_disponivel(): bool { return !empty($GLOBALS['COL_IA_STUB']) || rd_gemini_ready(); }

/** CEP oficial (ViaCEP → BrasilAPI). Falha silenciosa. */
function col_ia_cep(string $cep): ?array {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;
    $r = col_http_get('https://viacep.com.br/ws/' . $cep . '/json/', 8);
    $j = ($r['ok'] ?? false) && (int)($r['http'] ?? 0) === 200 ? json_decode((string)$r['corpo'], true) : null;
    if (is_array($j) && empty($j['erro']) && !empty($j['localidade'])) {
        return ['logradouro' => (string)($j['logradouro'] ?? ''), 'bairro' => (string)($j['bairro'] ?? ''), 'cidade' => (string)$j['localidade'], 'uf' => strtoupper((string)($j['uf'] ?? '')), 'cep' => $cep, 'fonte' => 'ViaCEP'];
    }
    $r = col_http_get('https://brasilapi.com.br/api/cep/v1/' . $cep, 8);
    $j = ($r['ok'] ?? false) && (int)($r['http'] ?? 0) === 200 ? json_decode((string)$r['corpo'], true) : null;
    if (is_array($j) && !empty($j['city'])) {
        return ['logradouro' => (string)($j['street'] ?? ''), 'bairro' => (string)($j['neighborhood'] ?? ''), 'cidade' => (string)$j['city'], 'uf' => strtoupper((string)($j['state'] ?? '')), 'cep' => $cep, 'fonte' => 'BrasilAPI'];
    }
    return null;
}

function col_ia_txt($v, int $max): string { return mb_substr(trim(strip_tags((string)$v)), 0, $max); }

/**
 * Confere o endereço de uma coleta (ou de campos enviados pelo formulário).
 * @return array{atual:array,evidencias:array,alertas:array,sugestao:?array,origem:string,ia:bool,mudancas:array}
 */
function col_ia_endereco(array $in): array {
    $campos = ['endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cep'];
    $atual = [];
    foreach ($campos as $f) $atual[$f] = col_ia_txt($in[$f] ?? '', $f === 'endereco' ? 240 : 80);
    $atual['uf'] = strtoupper(substr($atual['uf'], 0, 2));
    $atual['cep'] = preg_replace('/\D/', '', $atual['cep']);
    $cnpj = preg_replace('/\D/', '', (string)($in['cnpj'] ?? ''));
    $fornecedor = col_ia_txt($in['fornecedor'] ?? '', 200);

    $evid = []; $alertas = [];
    $cep = col_ia_cep($atual['cep']);
    if ($cep) {
        $evid['cep'] = $cep;
        if ($atual['uf'] !== '' && $cep['uf'] !== '' && $atual['uf'] !== $cep['uf']) $alertas[] = "O CEP {$atual['cep']} pertence a {$cep['cidade']}/{$cep['uf']}, mas o cadastro diz {$atual['cidade']}/{$atual['uf']}.";
        elseif ($atual['cidade'] !== '' && col_norm_txt($cep['cidade']) !== col_norm_txt($atual['cidade'])) $alertas[] = "O CEP {$atual['cep']} pertence a {$cep['cidade']}/{$cep['uf']}, mas o cadastro diz {$atual['cidade']}/{$atual['uf']}.";
    } elseif ($atual['cep'] !== '' && strlen($atual['cep']) !== 8) {
        $alertas[] = 'CEP com tamanho inválido (precisa de 8 dígitos).';
    }
    if (strlen($cnpj) === 14) {
        require_once __DIR__ . '/coletas_import.php';
        $db = col_read();
        $cache = (array)($db['cnpjcache'] ?? []);
        $e = coli_endereco_cnpj($cnpj, $cache);
        if ($e) {
            $evid['cnpj'] = array_intersect_key($e, array_flip(['endereco', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'fonte']));
            if ($atual['uf'] !== '' && strtoupper($e['uf']) !== '' && strtoupper($e['uf']) !== $atual['uf'])
                $alertas[] = "O cadastro do CNPJ aponta {$e['cidade']}/{$e['uf']}; a coleta está em {$atual['cidade']}/{$atual['uf']} (pode ser filial/outro local de retirada).";
        }
    }

    // Sugestão determinística (sem IA): o CEP oficial manda em cidade/UF e, se faltar, em logradouro/bairro.
    $det = $atual; $mudou = false;
    if ($cep) {
        foreach (['cidade' => 'cidade', 'uf' => 'uf'] as $a => $b) if ($cep[$b] !== '' && col_norm_txt($det[$a]) !== col_norm_txt($cep[$b])) { $det[$a] = $cep[$b]; $mudou = true; }
        if ($det['endereco'] === '' && $cep['logradouro'] !== '') { $det['endereco'] = $cep['logradouro']; $mudou = true; }
        if ($det['bairro'] === '' && $cep['bairro'] !== '') { $det['bairro'] = $cep['bairro']; $mudou = true; }
    }

    $sug = $mudou ? $det : null; $origem = $mudou ? 'cep' : 'nenhuma'; $ia = false; $obs = ''; $conf = $mudou ? 'alta' : '';
    $iaErro = '';
    if (col_ia_disponivel()) {
        $prompt = "Você corrige endereços brasileiros de FORNECEDORES para uma rota de coleta de materiais.\n"
            . "Receba o endereço digitado (pode ter erros, abreviações, cidade/UF trocadas) e as EVIDÊNCIAS oficiais. "
            . "Regras: (1) NUNCA invente rua, número, bairro ou CEP; só use o que está no digitado ou nas evidências; "
            . "(2) CEP oficial (ViaCEP/BrasilAPI) vale mais que o texto digitado para cidade e UF; "
            . "(3) o endereço do CNPJ é só uma pista (a retirada pode ser em outra unidade) — não o imponha se o digitado for coerente com o CEP; "
            . "(4) mantenha número e complemento do digitado; separe número do logradouro; expanda abreviações (R. → Rua, Av. → Avenida); "
            . "(5) se não houver base para corrigir, devolva os campos como estão e confianca \"baixa\". "
            . "Responda SOMENTE JSON: {\"endereco\":\"\",\"numero\":\"\",\"complemento\":\"\",\"bairro\":\"\",\"cidade\":\"\",\"uf\":\"\",\"cep\":\"\",\"confianca\":\"alta|media|baixa\",\"observacao\":\"o que foi mudado e por quê, em 1-2 frases\"}\n\n"
            . json_encode(['fornecedor' => $fornecedor, 'digitado' => $atual, 'evidencias' => $evid], JSON_UNESCAPED_UNICODE);
        try {
            $j = col_ia_chamar($prompt, 900);
            if (is_array($j)) {
                $s = [];
                foreach ($campos as $f) $s[$f] = col_ia_txt($j[$f] ?? $atual[$f], $f === 'endereco' ? 240 : 80);
                $s['uf'] = strtoupper(substr($s['uf'], 0, 2));
                $s['cep'] = preg_replace('/\D/', '', $s['cep']);
                if ($s['uf'] !== '' && !isset(COL_UFS[$s['uf']])) $s['uf'] = $atual['uf'];
                if ($s['cep'] !== '' && strlen($s['cep']) !== 8) $s['cep'] = $atual['cep'];
                // Trava: a IA não pode contrariar o CEP oficial em cidade/UF.
                if ($cep) { if ($cep['uf'] !== '') $s['uf'] = $cep['uf']; if ($cep['cidade'] !== '') $s['cidade'] = $cep['cidade']; }
                $conf = in_array($j['confianca'] ?? '', ['alta', 'media', 'baixa'], true) ? $j['confianca'] : 'baixa';
                $obs = col_ia_txt($j['observacao'] ?? '', 400);
                $sug = $s; $origem = 'gemini'; $ia = true;
            }
        } catch (Throwable $e) { $iaErro = $e->getMessage(); }
    }

    $mud = [];
    if ($sug) foreach ($campos as $f) if (col_norm_txt($sug[$f]) !== col_norm_txt($atual[$f]) || ($f === 'cep' && $sug[$f] !== $atual[$f])) $mud[$f] = ['de' => $atual[$f], 'para' => $sug[$f]];
    if ($sug && !$mud) { $sug = null; if ($origem === 'gemini') $origem = 'sem_mudancas'; }
    return ['atual' => $atual, 'evidencias' => $evid, 'alertas' => $alertas, 'sugestao' => $sug, 'mudancas' => $mud, 'origem' => $origem,
        'ia' => $ia, 'ia_disponivel' => col_ia_disponivel(), 'ia_erro' => $iaErro, 'confianca' => $conf, 'observacao' => $obs];
}

/** Revisão da rota: regras locais + Gemini. Não grava nada. */
function col_ia_rota(array $ids): array {
    $db = col_read();
    $atual = col_ordenar_paradas($db, $ids, false);
    $origem = $atual['origem'];
    $alertas = col_rota_alertas($atual);

    $out = ['km_atual' => $atual['km_estimado'], 'ordem_atual' => array_column($atual['paradas'], 'id'), 'alertas' => $alertas,
        'problemas_ia' => [], 'resumo' => '', 'ordem_sugerida' => null, 'km_sugerido' => null, 'ia' => false, 'ia_disponivel' => col_ia_disponivel(), 'ia_erro' => ''];
    if (count($atual['paradas']) < 1 || !col_ia_disponivel()) return $out;

    $stops = array_map(fn($p) => ['id' => $p['id'], 'pc' => $p['pc'] ?? '', 'fornecedor' => $p['fornecedor'] ?? '',
        'endereco' => $p['endereco_completo'] ?? '', 'cep' => $p['cep'] ?? '', 'lat' => round((float)$p['lat'], 5), 'lng' => round((float)$p['lng'], 5),
        'prioridade' => $p['prioridade'] ?? 'NORMAL', 'dia' => $p['agendada_data'] ?? '', 'hora' => $p['agendada_hora'] ?? ''], $atual['paradas']);
    $prompt = "Você revisa uma ROTA DE COLETA de materiais (um caminhão sai da base, passa pelas paradas e volta).\n"
        . "Base: {$origem['nome']} (lat {$origem['lat']}, lng {$origem['lng']}), Minas Gerais.\n"
        . "Tarefas: (A) para cada parada, verifique se as coordenadas (lat/lng) são compatíveis com a cidade/UF/CEP do endereço escrito; "
        . "aponte as incompatíveis (ex.: endereço em Contagem/MG com coordenadas em outro estado); "
        . "(B) sugira a melhor ORDEM de visita, que reduza a quilometragem, respeitando prioridade (URGENTE e ALTA antes, salvo grande desvio) e o horário/dia marcado; "
        . "(C) resuma em 1-2 frases. Use SOMENTE os ids fornecidos, cada um exatamente uma vez em ordem_sugerida. Não invente endereços.\n"
        . "Responda SOMENTE JSON: {\"problemas\":[{\"id\":\"\",\"descricao\":\"\"}],\"ordem_sugerida\":[\"id\",...],\"resumo\":\"\"}\n\n"
        . json_encode(['paradas' => $stops], JSON_UNESCAPED_UNICODE);
    try {
        $j = col_ia_chamar($prompt, 1500);
        if (!is_array($j)) return $out;
        $out['ia'] = true;
        $validos = array_flip($out['ordem_atual']);
        foreach ((array)($j['problemas'] ?? []) as $pr) {
            $pid = (string)($pr['id'] ?? '');
            if (isset($validos[$pid])) $out['problemas_ia'][] = ['id' => $pid, 'descricao' => col_ia_txt($pr['descricao'] ?? '', 300)];
            if (count($out['problemas_ia']) >= 20) break;
        }
        $out['resumo'] = col_ia_txt($j['resumo'] ?? '', 500);
        $ord = array_values(array_unique(array_map('strval', (array)($j['ordem_sugerida'] ?? []))));
        $ord = array_values(array_filter($ord, fn($i) => isset($validos[$i])));
        foreach ($out['ordem_atual'] as $i) if (!in_array($i, $ord, true)) $ord[] = $i;   // o que a IA esqueceu vai ao fim
        if ($ord !== $out['ordem_atual']) {
            $novo = col_ordenar_paradas($db, $ord, false);
            $out['ordem_sugerida'] = array_column($novo['paradas'], 'id');
            $out['km_sugerido'] = $novo['km_estimado'];
            $out['economia_km'] = round($atual['km_estimado'] - $novo['km_estimado'], 1);
        }
    } catch (Throwable $e) { $out['ia_erro'] = $e->getMessage(); }
    return $out;
}
