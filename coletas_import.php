<?php
/**
 * Recebimento Drilling — Importar pedidos de coleta (v3.13.0)
 *
 * Reaproveita SÓ O LEITOR da importação de pedidos (PDF do SAP / ZIP do WhatsApp, com Gemini
 * quando a leitura nativa falha). O resultado é gravado APENAS em data/coletas.json:
 * não cria nem altera PC, estoque, indicadores ou notificações.
 * Endereço: 1) de coletas anteriores do mesmo CNPJ; 2) consulta pública do CNPJ (BrasilAPI).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/coletas_lib.php';
require_once __DIR__ . '/whatsapp_import_lib.php';

const COLI_MAX_ARQUIVOS = 30;

/** PCs citados em mensagens do chat que falam de coleta. */
function coli_pcs_do_chat(string $txt): array {
    $pcs = [];
    foreach (preg_split('/\R/u', $txt) as $l) {
        if (!preg_match('/colet/iu', $l)) continue;
        if (preg_match_all('/\b(\d{4,5})\b/', $l, $m)) foreach ($m[1] as $n) $pcs[(int)$n] = true;
    }
    return $pcs;
}

/** Endereço já usado em coletas anteriores do mesmo CNPJ (ou mesmo fornecedor). */
function coli_endereco_local(array $db, string $cnpj, string $forn): ?array {
    $best = null;
    foreach ($db['coletas'] as $c) {
        if (empty($c['endereco']) && empty($c['cidade'])) continue;
        $mesmo = ($cnpj !== '' && ($c['cnpj'] ?? '') === $cnpj) || ($cnpj === '' && $forn !== '' && mb_strtoupper($c['fornecedor'] ?? '') === mb_strtoupper($forn));
        if (!$mesmo) continue;
        if (!$best || strcmp((string)$c['atualizado_em'], (string)$best['atualizado_em']) > 0) $best = $c;
    }
    if (!$best) return null;
    $e = array_intersect_key($best, array_flip(['endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cep', 'contato']));
    if (!empty($best['geo_confirmado']) && $best['lat'] !== null) $e += ['lat' => $best['lat'], 'lng' => $best['lng'], 'geo_confirmado' => true];
    return $e + ['fonte' => 'coleta anterior ' . $best['id']];
}

/** Consulta pública do CNPJ (BrasilAPI). Falha silenciosa. Cache em coletas.json → cnpjcache. */
function coli_endereco_cnpj(string $cnpj, array &$cache): ?array {
    if (strlen($cnpj) !== 14) return null;
    if (isset($cache[$cnpj])) return $cache[$cnpj] ?: null;
    $r = col_http_get('https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, 8);
    $j = ($r['ok'] ?? false) && (int)($r['http'] ?? 0) === 200 ? json_decode($r['corpo'], true) : null;
    if (!is_array($j) || empty($j['municipio'])) { if (($r['http'] ?? 0) === 404) $cache[$cnpj] = false; return null; }
    $log = trim(($j['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($j['logradouro'] ?? ''));
    $e = ['endereco' => $log, 'numero' => (string)($j['numero'] ?? ''), 'complemento' => (string)($j['complemento'] ?? ''),
          'bairro' => (string)($j['bairro'] ?? ''), 'cidade' => mb_convert_case((string)$j['municipio'], MB_CASE_TITLE, 'UTF-8'),
          'uf' => (string)($j['uf'] ?? ''), 'cep' => preg_replace('/\D/', '', (string)($j['cep'] ?? '')),
          'contato' => trim((string)($j['ddd_telefone_1'] ?? '')), 'fonte' => 'cadastro do CNPJ'];
    $cache[$cnpj] = $e;
    return $e;
}

/** Lê os arquivos enviados e devolve a prévia (nada é gravado). */
function coli_previa(array $files): array {
    $lidos = []; $avisos = []; $chatPcs = []; $ia = 0; $n = 0;
    foreach ($files as $f) {
        if (++$n > COLI_MAX_ARQUIVOS) { $avisos[] = ['arquivo' => $f['name'], 'erro' => 'Limite de ' . COLI_MAX_ARQUIVOS . ' arquivos por vez.']; break; }
        if (empty($f['path']) && (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))) { $avisos[] = ['arquivo' => $f['name'], 'erro' => 'Falha no envio do arquivo.']; continue; }
        $f['tmp_name'] = $f['path'] ?? $f['tmp_name'];
        $nome = (string)$f['name'];
        try {
            if (preg_match('/\.zip$/i', $nome)) {
                foreach (wa_zip_entries($f['tmp_name']) as $e) if (preg_match('/\.txt$/i', $e['name'])) $chatPcs += coli_pcs_do_chat($e['bytes']);
                $p = wa_zip_preview($f['tmp_name']);
                $ia += (int)$p['ia_aplicada'];
                foreach ($p['pedidos'] as $r) $lidos[] = $r;
                foreach ($p['avisos'] as $a) $avisos[] = ['arquivo' => $a['pdf'], 'erro' => $a['erro']];
            } elseif (preg_match('/\.pdf$/i', $nome)) {
                $bytes = (string)file_get_contents($f['tmp_name']);
                if (strlen($bytes) > 30 * 1024 * 1024 || !str_starts_with($bytes, '%PDF-')) throw new RuntimeException('PDF inválido ou maior que 30 MB.');
                $r = wa_pdf_best_effort($bytes, $nome, $ia < WA_GEMINI_MAX_PDF_POR_LOTE);
                if (($r['leitura_origem'] ?? '') === 'gemini') $ia++;
                if (!empty($r['pc']) && !empty($r['insumos'])) $lidos[] = $r; else $avisos[] = ['arquivo' => $nome, 'erro' => $r['erro'] ?? 'Pedido não reconhecido.'];
            } else $avisos[] = ['arquivo' => $nome, 'erro' => 'Envie PDF do pedido ou o ZIP exportado do WhatsApp.'];
        } catch (Throwable $e) { $avisos[] = ['arquivo' => $nome, 'erro' => $e->getMessage()]; }
    }
    $db = col_read();
    $abertas = [];
    foreach ($db['coletas'] as $c) if (!in_array($c['status'], ['CHEGOU'], true) && (int)$c['pc'] > 0) $abertas[(int)$c['pc']] = $c['id'];
    $cadastrados = [];
    if (function_exists('read_items')) foreach (read_items() as $p) $cadastrados[(int)$p['pc']] = true;
    $out = []; $vistos = [];
    foreach ($lidos as $r) {
        $pc = (int)$r['pc'];
        if (isset($vistos[$pc])) continue; $vistos[$pc] = true;
        $cnpj = preg_replace('/\D/', '', (string)($r['cnpj'] ?? ''));
        $end = coli_endereco_local($db, $cnpj, (string)$r['fornecedor']);
        $out[] = ['pc' => $pc, 'fornecedor' => (string)$r['fornecedor'], 'cnpj' => $cnpj, 'solicitante' => (string)($r['solicitante'] ?? ''),
            'destino' => (string)($r['destino'] ?? ''), 'arquivo' => (string)($r['pdf'] ?? ''), 'lido_por_ia' => ($r['leitura_origem'] ?? '') === 'gemini',
            'itens' => array_map(fn($i) => ['descricao' => $i['descricao'], 'quantidade' => $i['quantidade_pedida'], 'unidade' => $i['unidade']], array_slice($r['insumos'], 0, 60)),
            'coleta_existente' => $abertas[$pc] ?? null, 'pc_no_sistema' => isset($cadastrados[$pc]),
            'citado_como_coleta' => isset($chatPcs[$pc]), 'endereco' => $end, 'urgente_chat' => $r['urgente_chat'] ?? null];
    }
    usort($out, fn($a, $b) => [!$a['citado_como_coleta'], $a['pc']] <=> [!$b['citado_como_coleta'], $b['pc']]);
    return ['pedidos' => $out, 'avisos' => $avisos, 'ia_aplicada' => $ia, 'tem_chat' => (bool)$chatPcs];
}

/** Cria as coletas escolhidas. Só grava em coletas.json. */
function coli_confirmar(array $body, string $usuario): array {
    $sel = array_slice((array)($body['pedidos'] ?? []), 0, 100);
    if (!$sel) respond(['ok' => false, 'error' => 'Selecione ao menos um pedido.'], 422);
    $db = col_read();
    $cache = (array)($db['cnpjcache'] ?? []);
    $criadas = []; $ignoradas = []; $consultas = 0;
    foreach ($sel as $r) {
        $pc = (int)($r['pc'] ?? 0);
        $forn = col_str($r['fornecedor'] ?? '', 200);
        if ($pc <= 0 && $forn === '') continue;
        $db = col_read();
        $existe = null;
        foreach ($db['coletas'] as $c) if ((int)$c['pc'] === $pc && $pc > 0 && $c['status'] !== 'CHEGOU') { $existe = $c; break; }
        if ($existe && empty($body['atualizar_existentes'])) { $ignoradas[] = ['pc' => $pc, 'coleta' => $existe['id']]; continue; }
        $cnpj = preg_replace('/\D/', '', (string)($r['cnpj'] ?? ''));
        $itens = array_map(fn($i) => rtrim(rtrim(number_format((float)($i['quantidade'] ?? 0), 3, ',', ''), '0'), ',') . ' ' . ($i['unidade'] ?? '') . ' ' . ($i['descricao'] ?? ''), (array)($r['itens'] ?? []));
        $in = ['pc' => $pc, 'numero_pc' => (string)$pc, 'fornecedor' => $forn, 'cnpj' => $cnpj,
               'observacao' => col_str(implode(' · ', $itens) . (!empty($r['solicitante']) ? ' | Solicitante: ' . $r['solicitante'] : ''), 600)];
        if (!empty($r['urgente_chat'])) $in['prioridade'] = 'URGENTE';   // v3.18.0: urgência lida do chat
        if ($existe) $in['id'] = $existe['id'];
        $end = coli_endereco_local($db, $cnpj, $forn);
        if (!$end && (!$existe || empty($existe['endereco'])) && $consultas < 20) { $consultas++; $end = coli_endereco_cnpj($cnpj, $cache); }
        if ($end && (!$existe || empty($existe['endereco']))) { $fonte = $end['fonte']; unset($end['fonte']); $in += $end; } else $fonte = '';
        $res = col_salvar($in, $usuario);
        $criadas[] = ['pc' => $pc, 'coleta' => $res['coleta']['id'], 'novo' => $res['novo'], 'endereco' => $fonte ?: ($existe['endereco'] ?? '' ? 'mantido' : 'sem endereço')];
    }
    col_with_lock(function () use ($cache) { $db = col_read(); $db['cnpjcache'] = array_slice($cache, -500, null, true); col_write($db); });
    return ['criadas' => $criadas, 'ignoradas' => $ignoradas];
}

/**
 * v3.18.1: localiza fornecedores (com CNPJ nos pedidos) que ainda não têm endereço/coordenadas.
 * CNPJ → cadastro público (BrasilAPI) → coordenadas (mesmo geocodificador das Coletas).
 * Grava só em coletas.json → 'fornecedores'. Processa no máximo $limite por chamada.
 */
function coli_localizar_fornecedores(int $limite = 12): array {
    $cnpjs = [];
    if (function_exists('read_items')) foreach (read_items() as $p) {
        $c = preg_replace('/\D/', '', (string)($p['cnpjEmitente'] ?? ''));
        if (strlen($c) === 14) $cnpjs[$c] = (string)$p['fornecedor'];
    }
    $db = col_read();
    foreach ($db['coletas'] as $c) { $x = preg_replace('/\D/', '', (string)($c['cnpj'] ?? '')); if (strlen($x) === 14) $cnpjs[$x] ??= (string)$c['fornecedor']; }
    $conhecidos = (array)($db['fornecedores'] ?? []);
    foreach ($db['coletas'] as $c) { $x = preg_replace('/\D/', '', (string)($c['cnpj'] ?? '')); if (strlen($x) === 14 && isset($c['lat']) && $c['lat'] !== null) $conhecidos[$x] ??= ['lat' => $c['lat'], 'lng' => $c['lng'], 'fonte' => 'coleta ' . $c['id']]; }
    $pend = array_values(array_filter(array_keys($cnpjs), fn($c) => empty($conhecidos[$c]['lat']) && (empty($conhecidos[$c]['falhou_em']) || strtotime($conhecidos[$c]['falhou_em']) < time() - 7 * 86400)));
    $cache = (array)($db['cnpjcache'] ?? []);
    $ok = 0; $falha = []; $feitos = 0;
    foreach (array_slice($pend, 0, $limite) as $cnpj) {
        $feitos++;
        $e = coli_endereco_cnpj($cnpj, $cache);
        if (!$e) { $conhecidos[$cnpj] = ['nome' => $cnpjs[$cnpj], 'falhou_em' => date('c'), 'motivo' => 'CNPJ não encontrado na consulta pública']; $falha[] = $cnpjs[$cnpj]; continue; }
        $g = col_geocode($e);
        $conhecidos[$cnpj] = ['nome' => $cnpjs[$cnpj]] + array_intersect_key($e, array_flip(['endereco', 'numero', 'bairro', 'cidade', 'uf', 'cep']))
            + (($g['ok'] ?? false) ? ['lat' => $g['lat'], 'lng' => $g['lng'], 'precisao' => $g['precisao'] ?? '', 'fonte' => 'CNPJ + ' . ($g['origem'] ?? 'mapa')] : ['falhou_em' => date('c'), 'motivo' => $g['mensagem'] ?? 'endereço não localizado no mapa']);
        if ($g['ok'] ?? false) $ok++; else $falha[] = $cnpjs[$cnpj];
        usleep(1100000);   // respeita o limite dos serviços públicos (1 consulta/s)
    }
    col_with_lock(function () use ($conhecidos, $cache) { $db = col_read(); $db['fornecedores'] = $conhecidos; $db['cnpjcache'] = array_slice($cache, -500, null, true); col_write($db); });
    return ['processados' => $feitos, 'localizados' => $ok, 'falhas' => $falha, 'restantes' => max(0, count($pend) - $feitos),
            'total_com_coordenadas' => count(array_filter($conhecidos, fn($x) => !empty($x['lat'])))];
}

/** v3.24.0: monta a rota a partir dos números de PC digitados. Usa a coleta aberta do PC ou cria uma
 *  (fornecedor do pedido + endereço de coleta anterior, do cadastro de fornecedores ou do CNPJ). */
function coli_rota_por_pcs(array $pcs, string $usuario): array {
    $pcs = array_values(array_unique(array_filter(array_map('intval', $pcs), fn($n) => $n > 0)));
    if (!$pcs) respond(['ok' => false, 'error' => 'Digite ao menos um número de PC.'], 422);
    if (count($pcs) > 40) respond(['ok' => false, 'error' => 'No máximo 40 PCs por rota.'], 422);
    $pedidos = [];
    if (function_exists('read_items')) foreach (read_items() as $p) $pedidos[(int)$p['pc']] = $p;
    $ids = []; $criadas = []; $avisos = []; $cnpjConsultas = 0;
    foreach ($pcs as $pc) {
        $db = col_read();
        $aberta = null;
        foreach ($db['coletas'] as $c) if ((int)($c['pc'] ?? 0) === $pc && !in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU', 'CANCELADA'], true)) { $aberta = $c; break; }
        if ($aberta) { $ids[] = $aberta['id']; continue; }
        $p = $pedidos[$pc] ?? null;
        if (!$p) { $avisos[] = "PC $pc não está cadastrado no sistema."; continue; }
        $forn = (string)($p['fornecedor'] ?? ''); $cnpj = preg_replace('/\D/', '', (string)($p['cnpjEmitente'] ?? ''));
        $e = coli_endereco_local($db, $cnpj, $forn);
        if (!$e && strlen($cnpj) === 14 && !empty($db['fornecedores'][$cnpj]['endereco'])) $e = $db['fornecedores'][$cnpj] + ['fonte' => 'cadastro de fornecedores'];
        if (!$e && strlen($cnpj) === 14 && $cnpjConsultas < 6) { $cache = (array)($db['cnpjcache'] ?? []); $cnpjConsultas++; $e = coli_endereco_cnpj($cnpj, $cache);
            col_with_lock(function () use ($cache) { $d = col_read(); $d['cnpjcache'] = array_slice($cache, -500, null, true); col_write($d); }); }
        $in = ['pc' => $pc, 'numero_pc' => (string)$pc, 'fornecedor' => $forn, 'cnpj' => $cnpj, 'prioridade' => ($p['urgente'] ?? '') === '1' ? 'URGENTE' : 'NORMAL',
            'observacao' => mb_substr((string)($p['material'] ?? ''), 0, 900), 'agendada_data' => date('Y-m-d')];
        if ($e) foreach (['endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cep', 'contato', 'lat', 'lng'] as $k) if (isset($e[$k]) && $e[$k] !== '') $in[$k] = $e[$k];
        $r = col_salvar($in, $usuario);
        $id = $r['coleta']['id'];
        if (!$r['coleta']['tem_coordenadas'] && !empty($in['cidade'])) {
            col_with_lock(function () use ($id) { $d = col_read(); $c = $d['coletas'][$id]; col_geocodificar($d, $c, false); $c['atualizado_em'] = col_now(); $d['coletas'][$id] = $c; col_write($d); });
        }
        $ids[] = $id; $criadas[] = $pc;
        if (!$e) $avisos[] = "PC $pc ($forn): sem endereço do fornecedor — abra a coleta e preencha.";
    }
    $db = col_read();
    return ['ids' => $ids, 'criadas' => $criadas, 'avisos' => $avisos] + col_ordenar_paradas($db, $ids, true);
}
