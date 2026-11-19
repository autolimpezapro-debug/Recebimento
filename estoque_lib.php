<?php
/**
 * Recebimento Drilling — Núcleo do Módulo de Estoque (v3.0.0)
 * -----------------------------------------------------------
 * Mesma filosofia do restante do sistema: standalone, JSON no servidor,
 * gravação atômica (tmp + rename) e trava exclusiva (.write.lock).
 *
 * Estrutura de data/estoque.json:
 * {
 *   "versao": 1,
 *   "itens": {
 *      "SKU": {
 *         codigo, descricao, unidade, categoria, localizacao,
 *         minimo, saldo, custo_medio, reservado,
 *         criado_em, atualizado_em, aliases[]
 *      }
 *   },
 *   "requisicoes": { "REQ-...": {...} },
 *   "movimentos": [ { id, tipo, ... } ],      // append-only, até MAX_MOVIMENTOS
 *   "recebimentos": { "PC-123": {...} },      // controle de baixa por PC
 *   "sequencia": { "mov": 0, "req": 0, "entrada": 0 },
 *   "ultima_saida_custo": "data/estoque/ultima_saida.json"
 * }
 *
 * Regras de ouro:
 *  - Saldo NUNCA é digitado pelo operador: é sempre soma algébrica de movimentos.
 *  - Toda movimentação passa por est_apply_movement() — ponto único de cálculo.
 *  - Entrada pendente não altera saldo; só o recebimento (conferido) altera.
 *  - Saída só é efetivada se houver saldo disponível (saldo - reservado).
 *  - Nenhum movimento é apagado: correção = movimento de estorno/ajuste.
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const ESTOQUE_FILE       = DATA_DIR . '/estoque.json';
const ESTOQUE_LOCK       = DATA_DIR . '/.estoque.lock';
const ESTOQUE_DIR        = DATA_DIR . '/estoque';
const ESTOQUE_MAX_MOV    = 5000;   // histórico mantido no JSON principal
const ESTOQUE_MAX_LINHAS = 300;    // linhas por documento (entrada/requisição)
const ESTOQUE_SNAPSHOT_DIAS = 30;  // retenção de arquivo de última saída

const EST_MOV_TIPOS = [
    'ENTRADA_RECEBIMENTO' => 'Entrada por recebimento conferido',
    'ENTRADA_MANUAL'      => 'Entrada manual',
    'SAIDA'               => 'Saída para consumo',
    'TRANSFERENCIA'       => 'Transferência de endereço',
    'ESTORNO_SAIDA'       => 'Estorno de saída',
    'ESTORNO_ENTRADA'     => 'Estorno de entrada',
    'AJUSTE_POSITIVO'     => 'Ajuste positivo de inventário',
    'AJUSTE_NEGATIVO'     => 'Ajuste negativo de inventário',
    'AJUSTE_INVENTARIO'   => 'Ajuste por contagem de inventário',
    'BAIXA_PERDA'         => 'Baixa por perda, quebra ou vencimento',
    'INVENTARIO_SAP'      => 'Saldo do inventário SAP (carga/conciliação)',
];

/** Origem de cada movimento, para a composição do saldo por origem. */
const EST_MOV_ORIGEM = [
    'INVENTARIO_SAP' => 'inventario', 'AJUSTE_INVENTARIO' => 'ajuste', 'AJUSTE_POSITIVO' => 'ajuste', 'AJUSTE_NEGATIVO' => 'ajuste',
    'ENTRADA_RECEBIMENTO' => 'pedidos', 'ESTORNO_ENTRADA' => 'pedidos', 'ENTRADA_MANUAL' => 'manual',
    'SAIDA' => 'saidas', 'ESTORNO_SAIDA' => 'saidas', 'BAIXA_PERDA' => 'perdas', 'TRANSFERENCIA' => 'transferencia',
];

/* ------------------------------------------------------------------ */
/* Infraestrutura                                                      */
/* ------------------------------------------------------------------ */

function est_fail($msg, $code = 422) { respond(['ok' => false, 'error' => $msg], $code); }

function est_now() { return date('c'); }

function est_empty_db() {
    return ['versao' => 2, 'itens' => [], 'requisicoes' => [], 'movimentos' => [],
            'recebimentos' => [], 'inventarios' => [], 'reservas' => [],
            'sequencia' => ['mov' => 0, 'req' => 0, 'entrada' => 0, 'inv' => 0],
            'ultima_saida_custo' => 'data/estoque/ultima_saida.json'];
}

/** Lê o banco. Nunca recria por cima de um arquivo corrompido. */
function est_read() {
    est_backup_once();
    if (!is_file(ESTOQUE_FILE)) return est_empty_db();
    $raw = @file_get_contents(ESTOQUE_FILE);
    if ($raw === false || $raw === '') return est_empty_db();
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        est_fail('Falha ao ler data/estoque.json. Preserve o arquivo e restaure o backup; não houve reinicialização automática.', 500);
    }
    return array_merge(est_empty_db(), $data);
}

/** Gravação atômica com trava exclusiva própria (não concorre com a trava de pedidos). */
function est_write(array $db) {
    if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
    $tmp = ESTOQUE_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        est_fail('Não foi possível gravar o estoque. Verifique permissões de data/', 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, ESTOQUE_FILE)) est_fail('Falha ao persistir o estoque (rename)', 500);
}

/** Executa $fn com trava exclusiva. Retorna o valor de $fn. */
function est_with_lock(callable $fn) {
    $fp = @fopen(ESTOQUE_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) est_fail('Não foi possível bloquear o estoque para gravação', 503);
    try { return $fn(); }
    finally { flock($fp, LOCK_UN); fclose($fp); }
}

/* ------------------------------------------------------------------ */
/* Normalização e chaves                                               */
/* ------------------------------------------------------------------ */

function est_str($v, $max = 280) { return mb_substr(trim(str_replace("\xC2\xA0", ' ', (string)$v)), 0, $max); }

/** Chave de busca tolerante a acentos, caixa, plural simples e pontuação. */
function est_norm_key($text) {
    $t = mb_strtolower(trim((string)$text), 'UTF-8');
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $t);
        if ($conv !== false) $t = $conv;
    }
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $t = preg_replace('/\b(un|und|unid|unidade|pç|pc|peca|peça|pcs)\b/', ' ', (string)$t);
    $t = preg_replace('/\s+/', ' ', (string)$t);
    return trim((string)$t);
}

/** Gera um código interno estável a partir do texto (sem depender de biblioteca externa). */
function est_slug_code($text, $prefix = 'EST') {
    $key = est_norm_key($text);
    $words = array_values(array_filter(explode(' ', $key)));
    $base = '';
    foreach (array_slice($words, 0, 3) as $w) $base .= mb_substr($w, 0, 4);
    $base = $base === '' ? 'ITEM' : mb_strtoupper($base, 'UTF-8');
    return $prefix . '-' . $base . '-' . substr(md5($key), 0, 5);
}

/**
 * Normaliza códigos. Códigos de origem (sku_ma002816, item_3, nf_1) permanecem em
 * minúsculas para que o vínculo com o pedido continue exato; os demais são maiúsculos.
 */
function est_sanitize_code($code) {
    // v3.28.0: códigos do SAP podem ter espaço ("EE 150 01") — manter o espaço simples, senão o item não é achado.
    $code = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9._ -]/', '', (string)$code)));
    // v3.28.0: código provisório gerado pelo sistema (EST-NOME-a1b2c) termina em minúsculas — não pode virar maiúscula,
    // senão o item some na consulta ("Item não encontrado").
    if (preg_match('/^(EST)-([A-Za-z0-9]+)-([0-9a-fA-F]{5})$/', $code, $m)) return strtoupper($m[1] . '-' . $m[2]) . '-' . strtolower($m[3]);
    if (!preg_match('/^(sku|item|nf|linha|l)[-_]/i', $code)) $code = strtoupper($code);
    return mb_substr($code, 0, 70);
}

/**
 * Código SAP a partir do id da linha do pedido: sku_ma000053_3 → MA000053.
 * Retorna '' quando não parece um código de material.
 */
function est_sap_code($raw) {
    $c = strtoupper(trim((string)$raw));
    $c = preg_replace('/^SKU[_-]/', '', $c);
    $c = preg_replace('/_\d{1,2}$/', '', $c);
    return preg_match('/^[A-Z]{2}\d{6}$/', $c) ? $c : '';
}

/** Índice de busca: código + descrição + apelidos. */
function est_item_index(array $itens) {
    $idx = [];
    foreach ($itens as $code => $it) {
        $keys = [est_norm_key($it['descricao'] ?? ''), est_norm_key($code)];
        foreach (($it['aliases'] ?? []) as $al) $keys[] = est_norm_key($al);
        foreach (array_filter(array_unique($keys)) as $k) $idx[$k][] = $code;
    }
    return $idx;
}

/**
 * Localiza o item de estoque correspondente a uma descrição/código de insumo.
 * 1) código exato  2) descrição normalizada idêntica  3) apelido exato
 * 4) único contido dentro do outro (evita casar vários)  → null se ambíguo.
 */
function est_match_item(array $db, $descricao, $codigoSugerido = '') {
    // v3.57.0: cadastro unificado nunca recebe entrada — segue o redirecionamento para o item mantido.
    $c = est_match_item_bruto($db, $descricao, $codigoSugerido); $guard = 0;
    while ($c !== null && !empty($db['itens'][$c]['unificado_para']) && $guard++ < 5) $c = $db['itens'][$c]['unificado_para'];
    return $c;
}
function est_match_item_bruto(array $db, $descricao, $codigoSugerido = '') {
    $itens = $db['itens'] ?? [];
    if (!$itens) return null;
    // v3.30.0: código exato do item (inclusive com espaço, como "EE 150 01") casa direto, antes de qualquer limpeza.
    $direto = est_sanitize_code($codigoSugerido);
    if ($direto !== '' && isset($itens[$direto])) return $direto;
    // Código interno enviado pela interface (vem do próprio estoque) casa direto,
    // tolerando diferença de caixa. Só depois vem o id de origem do pedido (sku_*).
    $raw = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$codigoSugerido);
    if ($raw !== '' && !preg_match('/^(sku|item|nf|linha|l)[-_]/i', $raw)) {
        if (isset($itens[$raw])) return $raw;
        $up = strtoupper($raw);
        if (isset($itens[$up])) return $up;
        foreach ($itens as $c => $it) {
            if (strtoupper($c) === $up) return $c;
        }
        return null; // código explícito que não existe: não inventa correspondência
    }
    // Identificadores de origem (sku_*, item_*, nf_*) só casam quando o MESMO id já
    // foi vinculado a um item: nunca fundem materiais diferentes com a mesma descrição.
    $originId = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$codigoSugerido));
    if ($originId !== '' && preg_match('/^(sku|item|nf|linha|l)[-_]/', $originId)) {
        // v3.9: a linha do PC traz o código SAP (sku_UC000370) → casa com o item do inventário.
        $sap = est_sap_code($originId);
        if ($sap !== '' && isset($itens[$sap])) return $sap;
        foreach ($itens as $c => $it) {
            if (in_array($originId, array_map('strtolower', (array)($it['origem_ids'] ?? [])), true)) return $c;
        }
        return null;
    }
    $code = est_sanitize_code($codigoSugerido);
    if ($code !== '' && isset($itens[$code])) return $code;

    $target = est_norm_key($descricao);
    if ($target === '') return null;
    $exact = [];
    $partial = [];
    foreach ($itens as $c => $it) {
        $cand = [est_norm_key($it['descricao'] ?? '')];
        foreach (($it['aliases'] ?? []) as $al) $cand[] = est_norm_key($al);
        foreach ($cand as $k) {
            if ($k === '') continue;
            if ($k === $target) { $exact[$c] = true; continue; }
            $short = mb_strlen($k) < mb_strlen($target) ? $k : $target;
            $long  = $short === $k ? $target : $k;
            if (mb_strlen($short) >= 8 && mb_strpos($long, $short) !== false) $partial[$c] = true;
        }
    }
    if (count($exact) === 1) return array_key_first($exact);
    if (count($exact) > 1) return null;
    if (count($partial) === 1) return array_key_first($partial);
    return null;
}

function est_normalize_line(array $line, $i = 0) {
    $qty = isset($line['quantidade']) ? (float)$line['quantidade'] : (float)($line['quantidade_pedida'] ?? 0);
    $unit = strtoupper(est_str($line['unidade'] ?? 'UN', 12));
    return [
        // ID sempre em minúsculas: evita colisão quando o mesmo pedido chega com
        // identificadores de caixa diferente (sku_X / SKU_X) e duplica itens.
        'id'          => strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($line['id'] ?? ('l' . ($i + 1)))) ?: ('l' . ($i + 1))),
        'codigo'      => est_sanitize_code($line['codigo'] ?? ''),
        'descricao'   => est_str($line['descricao'] ?? '', 600),
        'unidade'     => $unit === '' ? 'UN' : $unit,
        'quantidade'  => round(max(0, $qty), 4),
        'observacao'  => est_str($line['observacao'] ?? '', 400),
        'lote'        => est_str($line['lote'] ?? '', 60),
        'validade'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($line['validade'] ?? '')) ? $line['validade'] : '',
        'localizacao' => est_str($line['localizacao'] ?? '', 60),
        'valor_unitario' => (isset($line['valor_unitario']) && is_numeric($line['valor_unitario']) && (float)$line['valor_unitario'] > 0)
            ? round((float)$line['valor_unitario'], 4) : null,
    ];
}

function est_lines_input(array $input, $key = 'linhas') {
    if (!isset($input[$key]) || !is_array($input[$key])) est_fail('Informe as linhas do documento');
    $lines = $input[$key];
    if (count($lines) > ESTOQUE_MAX_LINHAS) est_fail('Limite de ' . ESTOQUE_MAX_LINHAS . ' linhas por documento');
    $out = [];
    foreach (array_values($lines) as $i => $line) {
        if (!is_array($line)) est_fail('Linha inválida no documento');
        $n = est_normalize_line($line, $i);
        if (mb_strlen($n['descricao']) < 2) est_fail('Descrição obrigatória em cada linha');
        if ($n['quantidade'] <= 0 || $n['quantidade'] > 100000000) est_fail('Quantidade deve ser maior que zero: ' . $n['descricao']);
        $out[] = $n;
    }
    if (!$out) est_fail('O documento precisa de pelo menos uma linha');
    return $out;
}

/* ------------------------------------------------------------------ */
/* Item de estoque                                                     */
/* ------------------------------------------------------------------ */

function est_new_item(array $data, $code) {
    $now = est_now();
    return [
        'codigo'       => $code,
        'descricao'    => est_str($data['descricao'] ?? '', 600),
        'unidade'      => strtoupper(est_str($data['unidade'] ?? 'UN', 12)) ?: 'UN',
        'categoria'    => est_str($data['categoria'] ?? '', 80),
        'localizacao'  => est_str($data['localizacao'] ?? '', 80),
        'minimo'       => round(max(0, (float)($data['minimo'] ?? 0)), 4),
        'maximo'       => round(max(0, (float)($data['maximo'] ?? 0)), 4),
        'ponto_pedido' => round(max(0, (float)($data['ponto_pedido'] ?? 0)), 4),
        'saldo'        => 0.0,
        'reservado'    => 0.0,
        'custo_medio'  => 0.0,
        'fornecedor_padrao' => est_str($data['fornecedor_padrao'] ?? '', 200),
        'criado_em'    => $now,
        'atualizado_em'=> $now,
        'aliases'      => [],
        'origem_ids'   => [],
    ];
}

/**
 * Resolve (ou cria, se $criar) o item de estoque de uma linha de documento.
 * Retorna ['codigo'=>..., 'criado'=>bool].
 */
function est_resolve_line_item(array &$db, array $line, $criar = true, $extra = []) {
    $code = est_match_item($db, $line['descricao'], $line['codigo'] ?? '');
    $criado = false;
    if ($code === null) {
        if (!$criar) return null;
        $code = est_sanitize_code($line['codigo'] ?? '');
        // Código de origem (sku_*, item_*) nunca vira código de item: colidiria com
        // materiais diferentes que compartilham o mesmo id de origem.
        // v3.9: com código SAP na linha do pedido, o item nasce com o código SAP.
        $sap = est_sap_code($line['codigo'] ?? '') ?: est_sap_code($line['id'] ?? '');
        if ($sap !== '' && !isset($db['itens'][$sap])) {
            $code = $sap;
        } elseif ($code === '' || isset($db['itens'][$code]) || preg_match('/^(sku|item|nf|linha|l)[-_]/i', $code)) {
            $code = est_slug_code($line['descricao']);
        }
        $n = 2;
        $base = $code;
        while (isset($db['itens'][$code])) { $code = $base . '-' . $n++; }
        $db['itens'][$code] = est_new_item(array_merge($line, $extra), $code);
        $ids = array_filter([preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($line['codigo'] ?? ''))),
                             preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($line['id'] ?? '')))]);
        if ($ids) $db['itens'][$code]['origem_ids'] = array_values(array_unique($ids));
        $criado = true;
    } else {
        $it = &$db['itens'][$code];
        if (($it['unidade'] ?? 'UN') === '' || ($it['unidade'] ?? '') === 'UN') {
            if (!empty($line['unidade'])) $it['unidade'] = $line['unidade'];
        }
        if (!empty($line['localizacao']) && empty($it['localizacao'])) $it['localizacao'] = $line['localizacao'];
        if (!empty($extra['categoria']) && empty($it['categoria'])) $it['categoria'] = $extra['categoria'];
        if (!empty($extra['minimo']) && empty($it['minimo'])) $it['minimo'] = (float)$extra['minimo'];
        // Guarda os ids de origem (código e id da linha do pedido) para os próximos recebimentos.
        $atual = array_map('strtolower', (array)($it['origem_ids'] ?? []));
        foreach ([$line['codigo'] ?? '', $line['id'] ?? ''] as $origem) {
            $originId = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$origem));
            if ($originId !== '' && !in_array($originId, $atual, true) && count($atual) < 40) {
                $it['origem_ids'][] = $originId;
                $atual[] = $originId;
            }
        }
        // Registra apelido para acelerar próximos recebimentos com a mesma grafia.
        $alias = est_str($line['descricao'], 300);
        $k = est_norm_key($alias);
        $known = array_map('est_norm_key', array_merge([$it['descricao']], $it['aliases'] ?? []));
        if ($alias !== '' && !in_array($k, $known, true) && count($it['aliases'] ?? []) < 25) $it['aliases'][] = $alias;
        unset($it);
    }
    return ['codigo' => $code, 'criado' => $criado];
}

/* ------------------------------------------------------------------ */
/* Movimentos — ponto único de alteração de saldo                      */
/* ------------------------------------------------------------------ */

/**
 * Aplica um movimento e recalcula o saldo do item. Nunca chamar fora do lock.
 * $sign = +1 (entrada) / -1 (saída). Ajustes definem o saldo para o valor alvo.
 */
function est_apply_movement(array &$db, array $mov) {
    $code = $mov['codigo'];
    if (!isset($db['itens'][$code])) est_fail('Item inexistente no estoque: ' . $code);
    $item = &$db['itens'][$code];
    $qty = round((float)$mov['quantidade'], 4);
    $saldoAntes = round((float)$item['saldo'], 4);
    $disponivel = round($saldoAntes - (float)$item['reservado'], 4);

    switch ($mov['tipo']) {
        case 'ENTRADA_RECEBIMENTO':
        case 'ENTRADA_MANUAL':
        case 'ESTORNO_SAIDA':
        case 'AJUSTE_POSITIVO':
            $saldoDepois = round($saldoAntes + $qty, 4);
            break;
        case 'SAIDA':
        case 'ESTORNO_ENTRADA':
            if (empty($mov['historico']) && $qty > $disponivel + 0.00001) {
                est_fail(sprintf('Saldo insuficiente para %s: disponível %s %s, solicitado %s %s',
                    $item['descricao'], est_num($disponivel), $item['unidade'], est_num($qty), $item['unidade']), 409);
            }
            $saldoDepois = round($saldoAntes - $qty, 4);
            break;
        case 'AJUSTE_NEGATIVO':
        case 'BAIXA_PERDA':
            if ($qty > $disponivel + 0.00001) {
                est_fail(sprintf('Ajuste/baixa maior que o disponível em %s', $item['descricao']), 409);
            }
            $saldoDepois = round($saldoAntes - $qty, 4);
            break;
        case 'INVENTARIO_SAP':
            // Carga/conciliação do inventário SAP: define o saldo final calculado pela conciliação.
            $saldoDepois = round(max(0, (float)$mov['saldo_alvo']), 4);
            break;
        case 'AJUSTE_INVENTARIO':
            // Define o saldo para o valor contado; a quantidade do movimento é a diferença.
            $saldoDepois = round($qty, 4);
            if ($saldoDepois < 0) est_fail('Saldo de inventário não pode ser negativo', 422);
            break;
        case 'TRANSFERENCIA':
            // Transferência não altera saldo: apenas muda a localização do item.
            $saldoDepois = $saldoAntes;
            break;
        default:
            est_fail('Tipo de movimento inválido: ' . $mov['tipo']);
    }

    // Recebimento de PC sem preço na NF: valoriza pelo custo médio vigente (preço SAP).
    if ($mov['tipo'] === 'ENTRADA_RECEBIMENTO' && (!isset($mov['valor_total']) || !(float)$mov['valor_total']) && (float)$item['custo_medio'] > 0) {
        $mov['valor_total'] = round($qty * (float)$item['custo_medio'], 2);
        $mov['valor_estimado'] = true;
    }
    if ($mov['tipo'] === 'INVENTARIO_SAP' && isset($mov['custo_unitario']) && (float)$mov['custo_unitario'] > 0) {
        $item['custo_medio'] = round((float)$mov['custo_unitario'], 4);
    }
    // Custo médio ponderado: só entradas com valor alteram o médio.
    $valorTotal = isset($mov['valor_total']) ? (float)$mov['valor_total'] : null;
    if (in_array($mov['tipo'], ['ENTRADA_RECEBIMENTO', 'ENTRADA_MANUAL', 'AJUSTE_POSITIVO'], true) && $valorTotal !== null && $valorTotal > 0 && $qty > 0) {
        $estoqueAnterior = max(0, $saldoAntes) * (float)$item['custo_medio'];
        $item['custo_medio'] = round(($estoqueAnterior + $valorTotal) / max(0.0001, max(0, $saldoAntes) + $qty), 4);
    }

    $db['sequencia']['mov'] = (int)($db['sequencia']['mov'] ?? 0) + 1;
    $mov['id'] = 'MOV-' . str_pad((string)$db['sequencia']['mov'], 6, '0', STR_PAD_LEFT);
    // Carga de histórico (planilha de importados): mantém a data original do lançamento.
    $mov['em'] = (!empty($mov['historico']) && !empty($mov['data_original']) && strtotime((string)$mov['data_original']))
        ? date('c', strtotime((string)$mov['data_original'])) : est_now();
    unset($mov['data_original']);
    $mov['saldo_anterior'] = $saldoAntes;
    $mov['saldo_posterior'] = $saldoDepois;
    $mov['usuario'] = est_str($mov['usuario'] ?? '', 120);

    $item['saldo'] = $saldoDepois;
    est_conciliacao_registrar($item, $mov, $saldoAntes, $saldoDepois);
    if (!empty($mov['localizacao_destino'])) $item['localizacao'] = est_str($mov['localizacao_destino'], 80);
    if (!empty($mov['lote'])) $item['ultimo_lote'] = est_str($mov['lote'], 60);
    if (!empty($mov['validade'])) $item['validade'] = $mov['validade'];
    $item['atualizado_em'] = $mov['em'];
    unset($item);

    $db['movimentos'][] = $mov;
    if (count($db['movimentos']) > ESTOQUE_MAX_MOV) {
        // v3.9: os mais antigos vão para o arquivo histórico (nunca são apagados).
        $sobra = array_slice($db['movimentos'], 0, count($db['movimentos']) - ESTOQUE_MAX_MOV);
        est_arquivar_movimentos($sobra);
        $db['movimentos'] = array_slice($db['movimentos'], -ESTOQUE_MAX_MOV);
    }
    return $mov;
}

/**
 * Primeira execução do módulo: guarda o estado da base de pedidos (items.json)
 * e os arquivos de controle antes de qualquer coisa acontecer no estoque.
 * A base de pedidos NÃO é copiada para data/estoque e NÃO é alterada aqui.
 */
function est_backup_once() {
    $marca = ESTOQUE_DIR . '/.instalacao_v300.json';
    if (is_file($marca)) return;
    if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
    $copia = ESTOQUE_DIR . '/pedidos_antes_estoque_v300.json';
    if (is_file(ITEMS_FILE) && !is_file($copia)) {
        @copy(ITEMS_FILE, $copia);
    }
    $resumo = ['instalado_em' => date('c'), 'versao' => '3.0.0',
        'copia_pedidos' => is_file($copia) ? 'data/estoque/' . basename($copia) : null,
        'hash_pedidos_instalacao' => is_file(ITEMS_FILE) ? sha1_file(ITEMS_FILE) : null];
    @file_put_contents($marca, json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Consistência entre a base de pedidos (somente leitura) e o estoque.
 * Compara o hash atual de items.json com o hash gravado na última verificação.
 */
function est_check_pedidos_integridade(array $db) {
    $marca = ESTOQUE_DIR . '/.instalacao_v300.json';
    $info = is_file($marca) ? json_decode((string)@file_get_contents($marca), true) : [];
    $info = is_array($info) ? $info : [];
    $atual = is_file(ITEMS_FILE) ? sha1_file(ITEMS_FILE) : null;
    $pedidos = function_exists('read_items') ? read_items() : [];
    $total = count($pedidos);
    $conf = 0; $linhas = 0;
    foreach ($pedidos as $p) {
        if (in_array($p['recebimento_estado'] ?? '', ['em_andamento', 'finalizado'], true)) $conf++;
        $linhas += count($p['insumos'] ?? []);
    }
    return [
        'pedidos_total' => $total,
        'pedidos_com_conferencia' => $conf,
        'linhas_de_insumo' => $linhas,
        'hash_atual' => $atual,
        'hash_instalacao' => $info['hash_pedidos_instalacao'] ?? null,
        'copia_seguranca' => $info['copia_pedidos'] ?? null,
        'alterado_desde_instalacao' => $atual !== null && ($info['hash_pedidos_instalacao'] ?? null) !== null
            ? $atual !== $info['hash_pedidos_instalacao'] : null,
        'finalizados_sem_entrada' => est_finalizados_sem_entrada($db, $pedidos),
        'protecao' => 'O módulo de estoque apenas lê os pedidos. Nenhuma conferência, foto, NF ou histórico é alterado por ele.',
    ];
}

/** v3.51.4: PCs com recebimento finalizado e insumos, mas sem entrada no estoque. */
function est_finalizados_sem_entrada(array $db, array $pedidos): array {
    $r = [];
    foreach ($pedidos as $p) {
        if (($p['recebimento_estado'] ?? '') !== 'finalizado' || empty($p['insumos'])) continue;
        if (isset($db['recebimentos']['PC-' . (int)$p['pc']])) continue;
        $r[] = ['pc' => (int)$p['pc'], 'fornecedor' => (string)($p['fornecedor'] ?? ''), 'finalizado_em' => (string)($p['recebimento_finalizado_em'] ?? ''), 'insumos' => count($p['insumos'])];
    }
    usort($r, fn($a, $b) => $a['pc'] <=> $b['pc']);
    return $r;
}

function est_num($n) {
    $n = (float)$n;
    $s = number_format($n, 4, ',', '.');
    return rtrim(rtrim($s, '0'), ',');
}

/* ------------------------------------------------------------------ */
/* Entradas (documento de compra / recebimento)                        */
/* ---------------------------------------------------------------- */
/**
 * Cria ou atualiza um documento de entrada em status 'pendente'.
 * Idempotente por PC: reenviar o mesmo PC não duplica linhas nem movimentos.
 * Linhas já recebidas não podem ser alteradas/removidas (trava de auditoria).
 */
function est_save_entrada(array &$db, array $input, $usuario) {
    $pc = (int)($input['pc'] ?? 0);
    if ($pc <= 0) est_fail('Informe o PC (pedido de compra) da entrada');
    $key = 'PC-' . $pc;
    $linhas = est_lines_input($input);
    $fornecedor = est_str($input['fornecedor'] ?? '', 200);
    $nf = est_str($input['numero_nf'] ?? '', 60);
    $now = est_now();

    $doc = $db['recebimentos'][$key] ?? null;
    if ($doc && ($doc['status'] ?? '') === 'recebido') {
        est_fail('A entrada do PC ' . $pc . ' já foi recebida e conferida. Use uma nova entrada manual para complementos.', 409);
    }
    if (!$doc) {
        $db['sequencia']['entrada'] = (int)($db['sequencia']['entrada'] ?? 0) + 1;
        $doc = [
            'pc'          => $pc,
            'entrada_id'  => 'ENT-' . date('Y') . '-' . str_pad((string)$db['sequencia']['entrada'], 4, '0', STR_PAD_LEFT),
            'fornecedor'  => $fornecedor,
            'numero_nf'   => $nf,
            'origem'      => est_str($input['origem'] ?? 'MANUAL', 30),
            'observacao'  => est_str($input['observacao'] ?? '', 1000),
            'status'      => 'pendente',
            'linhas'      => [],
            'criado_em'   => $now,
            'atualizado_em' => $now,
            'recebido_em' => null,
            'usuario'     => est_str($usuario, 120),
            'nota_anexada'=> false,
        ];
    } else {
        if ($fornecedor !== '') $doc['fornecedor'] = $fornecedor;
        if ($nf !== '') $doc['numero_nf'] = $nf;
        if (!empty($input['observacao'])) $doc['observacao'] = est_str($input['observacao'], 1000);
        $doc['atualizado_em'] = $now;
    }

    // Preserva linhas já recebidas; reescreve somente as pendentes.
    $old = [];
    foreach ($doc['linhas'] as $l) $old[$l['id']] = $l;
    $recebidas = array_filter($old, fn($l) => (float)($l['quantidade_recebida'] ?? 0) > 0);
    $newLines = [];
    foreach ($linhas as $line) {
        $prev = $old[$line['id']] ?? null;
        $line['quantidade_recebida'] = $prev ? (float)($prev['quantidade_recebida'] ?? 0) : 0.0;
        $line['quantidade_pendente'] = round(max(0, $line['quantidade'] - $line['quantidade_recebida']), 4);
        $line['conferido'] = $prev ? (bool)($prev['conferido'] ?? false) : false;
        $line['conferido_em'] = $prev['conferido_em'] ?? null;
        $line['divergencia_motivo'] = $prev['divergencia_motivo'] ?? '';
        $line['recebido_em'] = $prev['recebido_em'] ?? null;
        $newLines[] = $line;
    }
    // Reinsere linhas recebidas que o formulário não enviou (nunca somem).
    $ids = array_column($newLines, 'id');
    foreach ($recebidas as $id => $l) if (!in_array($id, $ids, true)) $newLines[] = $l;

    $doc['linhas'] = $newLines;
    $doc['status'] = 'pendente';
    $db['recebimentos'][$key] = $doc;
    return $doc;
}

/** Importa as linhas de um pedido de compra (items.json) como entrada pendente. */
function est_entrada_from_pedido(array &$db, array $pedido, $usuario) {
    $linhas = [];
    foreach (($pedido['insumos'] ?? []) as $i => $ins) {
        $linhas[] = [
            'id'         => (string)($ins['id'] ?? ('l' . ($i + 1))),
            'codigo'     => '',
            'descricao'  => (string)($ins['descricao'] ?? ''),
            'unidade'    => (string)($ins['unidade'] ?? 'UN'),
            'quantidade' => (float)($ins['quantidade_pedida'] ?? 0),
        ];
    }
    if (!$linhas) est_fail('O PC ' . (int)($pedido['pc'] ?? 0) . ' não possui insumos detalhados. Cadastre os itens antes de gerar a entrada.', 409);
    return est_save_entrada($db, [
        'pc'         => (int)$pedido['pc'],
        'fornecedor' => (string)($pedido['fornecedor'] ?? ''),
        'numero_nf'  => (string)($pedido['numeroNF'] ?? ''),
        'origem'     => 'PEDIDO_COMPRA',
        'observacao' => 'Entrada gerada a partir dos insumos do pedido de compra.',
        'linhas'     => $linhas,
    ], $usuario);
}

/* ------------------------------------------------------------------ */
/* Conferência                                                         */
/* ------------------------------------------------------------------ */

function est_confere(array &$db, array $input, $usuario) {
    $pc = (int)($input['pc'] ?? 0);
    $key = 'PC-' . $pc;
    if (!isset($db['recebimentos'][$key])) est_fail('Entrada não encontrada para o PC ' . $pc, 404);
    $doc = &$db['recebimentos'][$key];
    if (($doc['status'] ?? '') === 'recebido') est_fail('Entrada já recebida. Use estorno auditado para corrigir.', 409);
    if (!isset($input['linhas']) || !is_array($input['linhas'])) est_fail('Envie a conferência por linha');

    $byId = [];
    foreach ($doc['linhas'] as $i => $l) $byId[$l['id']] = $i;

    foreach ($input['linhas'] as $given) {
        if (!is_array($given)) est_fail('Linha de conferência inválida');
        $id = (string)($given['id'] ?? '');
        if (!isset($byId[$id])) est_fail('Linha não pertence a esta entrada: ' . $id);
        $i = $byId[$id];
        $q = $given['quantidade_conferida'] ?? null;
        if (!is_numeric($q)) est_fail('Quantidade conferida inválida em: ' . $doc['linhas'][$i]['descricao']);
        $q = round((float)$q, 4);
        $alvo = (float)$doc['linhas'][$i]['quantidade'];
        if ($q < 0) est_fail('Quantidade conferida não pode ser negativa');
        if ($q > $alvo + 0.00001) {
            est_fail(sprintf('Quantidade conferida (%s) maior que a pedida (%s) em "%s". Registre o excedente como entrada manual.',
                est_num($q), est_num($alvo), $doc['linhas'][$i]['descricao']), 422);
        }
        $motivo = est_str($given['divergencia_motivo'] ?? '', 400);
        if ($q < $alvo - 0.00001 && mb_strlen($motivo) < 3) {
            est_fail('Informe o motivo da divergência em: ' . $doc['linhas'][$i]['descricao']);
        }
        $doc['linhas'][$i]['quantidade_conferida'] = $q;
        $doc['linhas'][$i]['divergencia_motivo'] = $motivo;
        $doc['linhas'][$i]['conferido'] = true;
        $doc['linhas'][$i]['conferido_em'] = est_now();
        $doc['linhas'][$i]['conferido_por'] = est_str($usuario, 120);
    }
    $doc['atualizado_em'] = est_now();
    $doc['status'] = est_doc_all_confirmed($doc) ? 'conferido' : 'pendente';
    unset($doc);
    return $db['recebimentos'][$key];
}

function est_doc_all_confirmed(array $doc) {
    if (!$doc['linhas']) return false;
    foreach ($doc['linhas'] as $l) if (empty($l['conferido'])) return false;
    return true;
}

/* ------------------------------------------------------------------ */
/* Recebimento — gera os movimentos de entrada                         */
/* ------------------------------------------------------------------ */

/**
 * Efetiva a entrada conferida: cria/atualiza itens de estoque, aplica os
 * movimentos de ENTRADA_RECEBIMENTO e marca o documento como recebido.
 * Idempotente: um documento já recebido não gera novos movimentos.
 */
function est_receber(array &$db, $pc, $usuario, $localizacao = '') {
    $key = 'PC-' . (int)$pc;
    if (!isset($db['recebimentos'][$key])) est_fail('Entrada não encontrada para o PC ' . (int)$pc, 404);
    $doc = &$db['recebimentos'][$key];
    if (($doc['status'] ?? '') === 'recebido') return $doc; // idempotente
    if (!est_doc_all_confirmed($doc)) est_fail('Confira todas as linhas antes de receber', 409);

    $agora = est_now();
    foreach ($doc['linhas'] as $i => $line) {
        $q = round((float)($line['quantidade_conferida'] ?? 0), 4);
        if ($q <= 0) {
            $doc['linhas'][$i]['quantidade_recebida'] = 0.0;
            $doc['linhas'][$i]['quantidade_pendente'] = round((float)$line['quantidade'], 4);
            $doc['linhas'][$i]['recebido_em'] = $agora;
            continue; // "não chegou": nada entra no estoque
        }
        $resolved = est_resolve_line_item($db, $line, true, [
            'localizacao' => $localizacao ?: ($line['localizacao'] ?? ''),
        ]);
        $code = $resolved['codigo'];
        $valorUnit = isset($line['valor_unitario']) ? (float)$line['valor_unitario'] : 0.0;
        $mov = est_apply_movement($db, [
            'tipo'         => 'ENTRADA_RECEBIMENTO',
            'codigo'       => $code,
            'quantidade'   => $q,
            'valor_total'  => $valorUnit > 0 ? round($valorUnit * $q, 4) : null,
            'pc'           => (int)$pc,
            'documento'    => $doc['entrada_id'],
            'numero_nf'    => $doc['numero_nf'] ?? '',
            'fornecedor'   => $doc['fornecedor'] ?? '',
            'linha_id'     => $line['id'],
            'descricao_origem' => $line['descricao'],
            'lote'         => $line['lote'] ?? '',
            'validade'     => $line['validade'] ?? '',
            'usuario'      => $usuario,
            'observacao'   => $line['observacao'] ?? '',
        ]);
        $db['itens'][$code]['ultima_entrada_em'] = $mov['em'];
        $db['itens'][$code]['ultima_entrada_nf'] = $doc['numero_nf'] ?? '';
        $doc['linhas'][$i]['codigo'] = $code;
        $doc['linhas'][$i]['quantidade_recebida'] = $q;
        $doc['linhas'][$i]['quantidade_pendente'] = round(max(0, (float)$line['quantidade'] - $q), 4);
        $doc['linhas'][$i]['recebido_em'] = $agora;
        $doc['linhas'][$i]['movimento_id'] = $mov['id'];
    }
    $doc['status'] = 'recebido';
    $doc['recebido_em'] = $agora;
    $doc['recebido_por'] = est_str($usuario, 120);
    $doc['atualizado_em'] = $agora;
    unset($doc);
    return $db['recebimentos'][$key];
}

/**
 * Avisa os operadores logados sobre a movimentação (usa o sistema de notificações
 * quando disponível; silencioso se ainda não estiver instalado).
 */
function est_notify(array $data) {
    if (!is_file(__DIR__ . '/notify_lib.php')) return false;
    try {
        require_once __DIR__ . '/notify_lib.php';
        if (!function_exists('notif_publish')) return false;
        notif_publish($data);
        return true;
    } catch (Throwable $e) {
        error_log('Estoque: notificação não enviada: ' . $e->getMessage());
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Saídas / requisições                                                */
/* ------------------------------------------------------------------ */

function est_save_requisicao(array &$db, array $input, $usuario, $efetivar = false) {
    $fotosIn = [];   // v3.28.0: foto do produto por linha (data URL), gravada só ao efetivar
    foreach (array_values((array)($input['linhas'] ?? [])) as $i => $l) if (is_array($l) && !empty($l['foto'])) $fotosIn[$i] = (string)$l['foto'];
    $linhas = est_lines_input($input);
    // v3.30.0: origem da linha (PC e linha do PC) e pedido de foto automática; a validação é feita no servidor.
    $extras = [];
    foreach (array_values((array)($input['linhas'] ?? [])) as $i => $l) if (is_array($l)) $extras[$i] = [
        'pc' => max(0, (int)($l['pc'] ?? 0)), 'linha_pc' => preg_replace('/[^A-Za-z0-9_-]/', '', strtolower((string)($l['linha_pc'] ?? ''))),
        'foto_auto' => !empty($l['foto_auto']), 'foto_escolhida' => (string)($l['foto_escolhida'] ?? '')];
    $now = est_now();
    $id = est_sanitize_code($input['id'] ?? '');
    // Somente rascunho explícito reutiliza o documento; toda saída efetivada é um novo documento.
    if (!$efetivar && $id !== '' && isset($db['requisicoes'][$id])) {
        $req = $db['requisicoes'][$id];
        if (($req['status'] ?? '') === 'atendida') est_fail('Requisição já atendida não pode ser alterada', 409);
    } else {
        $db['sequencia']['req'] = (int)($db['sequencia']['req'] ?? 0) + 1;
        $id = 'REQ-' . date('Y') . '-' . str_pad((string)$db['sequencia']['req'], 4, '0', STR_PAD_LEFT);
        $req = ['id' => $id, 'criado_em' => $now, 'movimentos' => []];
    }
    $req['solicitante'] = est_str($input['solicitante'] ?? '', 160);
    $req['setor']       = est_str($input['setor'] ?? '', 120);
    $req['finalidade']  = est_str($input['finalidade'] ?? '', 400);
    $req['os']          = est_os($input['os'] ?? '');                      // v3.28.0: ordem de serviço
    $req['equipamento'] = est_str($input['equipamento'] ?? '', 120);       // v3.28.0: equipamento aplicado
    $req['usuario']     = est_str($usuario, 120);
    $req['atualizado_em'] = $now;
    $req['status']      = $efetivar ? 'atendida' : 'rascunho';
    $req['linhas']      = $linhas;

    if (!$efetivar) {
        $db['requisicoes'][$id] = $req;
        return $req;
    }
    // v3.28.0: saída exige setor de destino (e qual obra, quando for obra)
    if (isset($input['setor_codigo']) || $efetivar) {
        require_once __DIR__ . '/rd_obras.php';
        [$sc, $oid, $rot] = rd_setor_destino($input['setor_codigo'] ?? '', $input['obra_id'] ?? '', $input['obra_nome'] ?? '');
        if ($sc === '') est_fail('Informe o setor de destino da saída (Obra, Caldeiraria, Manutenção, Expedição, Logística ou ADM).');
        if ($sc === 'OBRA' && $rot === '') est_fail('Escolha qual obra recebe o material.');
        $req['setor'] = $rot; $req['setor_codigo'] = $sc; $req['obra_id'] = $oid;
        $req['obra'] = $sc === 'OBRA' ? trim(preg_replace('/^OBRA · /u', '', $rot)) : '';
    }
    // v3.32.0: OS e equipamento são SEMPRE opcionais na saída (muitas saídas não têm OS); quando informados, são gravados.
    $req['atendida_em'] = $now;
    // v3.30.0: linhas vindas de um PC — o servidor confere o que foi recebido e o que já saiu daquele PC.
    $infoPc = []; $usado = [];
    foreach ($linhas as $i => $ln) {
        $e = $extras[$i] ?? null;
        if (!$e || $e['pc'] <= 0 || $e['linha_pc'] === '') continue;
        $infoPc[$e['pc']] ??= est_pc_saida_info($db, $e['pc']);
        $alvo = null; foreach ($infoPc[$e['pc']]['linhas'] as $x) if ($x['linha_id'] === $e['linha_pc']) { $alvo = $x; break; }
        if (!$alvo) est_fail('PC ' . $e['pc'] . ': o material "' . $ln['descricao'] . '" não consta como recebido neste pedido.', 409);
        $k = $e['pc'] . '|' . $alvo['linha_id']; $usado[$k] = ($usado[$k] ?? 0) + (float)$ln['quantidade'];
        if ($usado[$k] > $alvo['pendente'] + 0.00001) {
            est_fail(sprintf('PC %d — %s: recebido %s, já retirado %s. Só restam %s %s para dar saída por este pedido.', $e['pc'], $alvo['descricao'],
                est_num($alvo['recebida']), est_num($alvo['retirada']), est_num($alvo['pendente']), $alvo['unidade']), 409);
        }
        $linhas[$i]['codigo'] = $alvo['codigo']; $linhas[$i]['pc'] = $e['pc']; $linhas[$i]['linha_pc'] = $alvo['linha_id'];
    }
    // Foto: a tirada na hora vale; senão, se o operador manteve a foto sugerida, usa a do cadastro ou a do recebimento.
    foreach ($linhas as $i => $ln) {
        if (isset($fotosIn[$i])) { $linhas[$i]['foto'] = est_foto_saida_salvar($fotosIn[$i], $id, $i + 1); continue; }
        if (empty($extras[$i]['foto_auto'])) continue;
        $cod = est_match_item($db, $ln['descricao'], $linhas[$i]['codigo'] ?? '');
        if ($cod === null) continue;
        $fa = est_foto_auto($db, $cod, (int)($linhas[$i]['pc'] ?? 0));
        $rel = $fa['rel'];
        // v3.44.0: o operador pode ter escolhido outra das fotos do recebimento — só vale se for uma das fotos deste PC/item
        $esc = est_foto_rel_ok((string)($extras[$i]['foto_escolhida'] ?? ''));
        if ($esc !== '' && (in_array($esc, $fa['fotos'], true) || $esc === est_foto_do_item($db['itens'][$cod] ?? []))) $rel = $esc;
        $arq = $rel !== '' ? est_foto_copiar($rel, $id, $i + 1) : '';
        if ($arq !== '') { $linhas[$i]['foto'] = $arq; $linhas[$i]['foto_origem'] = $fa['origem']; }
    }
    $req['linhas'] = $linhas;
    $req['assinatura'] = est_assinatura_salvar($db, (array)($input['assinatura'] ?? []), $id, $usuario);
    $req['movimentos'] = est_execute_saida($db, $linhas, ['assinatura_id' => $req['assinatura']['id'], 'recebedor' => $req['assinatura']['nome'],
        'origem' => 'REQUISICAO', 'documento' => $id,
        'solicitante' => $req['solicitante'], 'setor' => $req['setor'], 'setor_codigo' => $req['setor_codigo'] ?? '', 'obra' => $req['obra'] ?? '',
        'finalidade' => $req['finalidade'], 'usuario' => $usuario, 'os' => $req['os'], 'equipamento' => $req['equipamento'],
    ]);
    $db['requisicoes'][$id] = $req;
    return $req;
}

/**
 * Executa saída de várias linhas de forma atômica: valida TODOS os saldos antes
 * de aplicar qualquer movimento (se uma linha falhar, nada é debitado).
 */
function est_execute_saida(array &$db, array $linhas, array $ctx) {
    $plano = [];
    $demanda = [];
    foreach ($linhas as $line) {
        $code = est_match_item($db, $line['descricao'], $line['codigo'] ?? '');
        if ($code === null) est_fail('Item não encontrado no estoque: "' . $line['descricao'] . '". Receba o material antes de dar saída.', 404);
        $demanda[$code] = ($demanda[$code] ?? 0) + (float)$line['quantidade'];
        $plano[] = ['line' => $line, 'codigo' => $code];
    }
    foreach ($demanda as $code => $qtd) {
        $it = $db['itens'][$code];
        $disp = round((float)$it['saldo'] - (float)$it['reservado'], 4);
        if ($qtd > $disp + 0.00001) {
            est_fail(sprintf('Saldo insuficiente: %s — disponível %s %s, pedido %s %s',
                $it['descricao'], est_num($disp), $it['unidade'], est_num($qtd), $it['unidade']), 409);
        }
    }
    $movs = [];
    foreach ($plano as $p) {
        $mv = [
            'tipo'       => 'SAIDA',
            'codigo'     => $p['codigo'],
            'quantidade' => (float)$p['line']['quantidade'],
            'origem'     => $ctx['origem'] ?? 'REQUISICAO',
            'documento'  => $ctx['documento'] ?? '',
            'solicitante'=> $ctx['solicitante'] ?? '',
            'setor'      => $ctx['setor'] ?? '',
            'setor_codigo' => $ctx['setor_codigo'] ?? '',
            'obra'       => $ctx['obra'] ?? '',
            'finalidade' => $ctx['finalidade'] ?? ($p['line']['observacao'] ?? ''),
            'descricao_origem' => $p['line']['descricao'],
            'usuario'    => $ctx['usuario'] ?? '',
            'assinatura_id' => $ctx['assinatura_id'] ?? null,
            'recebedor'  => $ctx['recebedor'] ?? '',
            'foto'       => $p['line']['foto'] ?? null,
            'os'         => $ctx['os'] ?? '',
            'equipamento'=> $ctx['equipamento'] ?? '',
        ];
        if (!empty($p['line']['pc'])) { $mv['pc'] = (int)$p['line']['pc']; $mv['linha_id'] = (string)($p['line']['linha_pc'] ?? ''); }   // v3.30.0
        $movs[] = est_apply_movement($db, $mv);
    }
    return $movs;
}

/** Estorno auditado de um movimento de entrada (reverte o saldo com movimento contrário). */
function est_estorno(array &$db, $movId, $motivo, $usuario) {
    if (mb_strlen(trim((string)$motivo)) < 8) est_fail('Informe o motivo do estorno (mínimo 8 caracteres)');
    $orig = null;
    foreach ($db['movimentos'] as $m) if (($m['id'] ?? '') === $movId) { $orig = $m; break; }
    if (!$orig) est_fail('Movimento não encontrado', 404);
    if (!in_array($orig['tipo'], ['ENTRADA_RECEBIMENTO', 'ENTRADA_MANUAL'], true)) est_fail('Somente entradas podem ser estornadas por esta rotina', 409);
    foreach ($db['movimentos'] as $m) {
        if (($m['tipo'] ?? '') === 'ESTORNO_ENTRADA' && ($m['movimento_origem'] ?? '') === $movId) {
            est_fail('Este movimento já foi estornado', 409);
        }
    }
    return est_apply_movement($db, [
        'tipo'             => 'ESTORNO_ENTRADA',
        'codigo'           => $orig['codigo'],
        'quantidade'       => (float)$orig['quantidade'],
        'movimento_origem' => $movId,
        'pc'               => $orig['pc'] ?? null,
        'documento'        => $orig['documento'] ?? '',
        'motivo'           => est_str($motivo, 500),
        'usuario'          => $usuario,
    ]);
}

/* ------------------------------------------------------------------ */
/* Consultas / indicadores                                             */
/* ------------------------------------------------------------------ */

function est_item_view(array $it) {
    $saldo = round((float)$it['saldo'], 4);
    $min = (float)($it['minimo'] ?? 0);
    $status = 'OK';
    if ($saldo <= 0) $status = 'SEM ESTOQUE';
    elseif ($min > 0 && $saldo <= $min) $status = 'CRÍTICO';
    elseif ($min > 0 && $saldo <= $min * 1.2) $status = 'ATENÇÃO';
    $it['saldo'] = $saldo;
    $it['disponivel'] = round($saldo - (float)($it['reservado'] ?? 0), 4);
    $it['valor_estoque'] = round($saldo * (float)($it['custo_medio'] ?? 0), 2);
    $it['status'] = $status;
    $it['minimo'] = $min;
    $it['maximo'] = (float)($it['maximo'] ?? 0);
    $it['ponto_pedido'] = (float)($it['ponto_pedido'] ?? 0);
    // Ponto de pedido atingido (reposição), independente do status de mínimo.
    $it['repor'] = $it['ponto_pedido'] > 0 ? $saldo <= $it['ponto_pedido'] : ($min > 0 && $saldo <= $min);
    $it['composicao'] = est_composicao($it);
    $it['origem_saldo'] = empty($it['conciliacao']['inventario_id']) ? 'pedidos' : ((float)($it['conciliacao']['pedidos_conciliados'] ?? 0) + (float)($it['conciliacao']['pedidos_pendentes'] ?? 0) > 0 ? 'inventario+pedidos' : 'inventario');
    return $it;
}

function est_dashboard(array $db) {
    foreach ($db['itens'] ?? [] as $k => $it) if (!empty($it['unificado_para'])) unset($db['itens'][$k]);   // v3.57.0
    $itens = array_map('est_item_view', array_values($db['itens'] ?? []));
    $crit = 0; $zero = 0; $valor = 0.0;
    foreach ($itens as $i) {
        if ($i['status'] === 'CRÍTICO' || $i['status'] === 'SEM ESTOQUE') $crit++;
        if ($i['status'] === 'SEM ESTOQUE') $zero++;
        $valor += $i['valor_estoque'];
    }
    $pendentes = 0; $conferidos = 0; $recebidos = 0;
    foreach (($db['recebimentos'] ?? []) as $d) {
        if (($d['status'] ?? '') === 'recebido') $recebidos++;
        elseif (($d['status'] ?? '') === 'conferido') $conferidos++;
        else $pendentes++;
    }
    $mes = date('Y-m');
    $entradasMes = 0.0; $saidasMes = 0.0; $ultimos = [];
    foreach (($db['movimentos'] ?? []) as $m) {
        if (substr((string)($m['em'] ?? ''), 0, 7) !== $mes) continue;
        if (in_array($m['tipo'], ['ENTRADA_RECEBIMENTO', 'ENTRADA_MANUAL'], true)) $entradasMes += (float)$m['quantidade'];
        if ($m['tipo'] === 'SAIDA') $saidasMes += (float)$m['quantidade'];
    }
    $ultimos = [];
    foreach (array_reverse($db['movimentos'] ?? []) as $m) {
        $m['descricao'] = $db['itens'][$m['codigo']]['descricao'] ?? ($m['descricao_origem'] ?? $m['codigo']);
        $m['unidade'] = $db['itens'][$m['codigo']]['unidade'] ?? 'UN';
        $ultimos[] = $m;
        if (count($ultimos) >= 12) break;
    }
    $abaixo = array_values(array_filter($itens, fn($i) => in_array($i['status'], ['CRÍTICO', 'SEM ESTOQUE'], true)));
    usort($abaixo, fn($a, $b) => ($a['saldo'] <=> $b['saldo']));
    return [
        'total_itens' => count($itens),
        'itens_criticos' => $crit,
        'itens_zerados' => $zero,
        'valor_estoque' => round($valor, 2),
        'entradas_pendentes' => $pendentes,
        'entradas_conferidas' => $conferidos,
        'entradas_recebidas' => $recebidos,
        'qtd_entradas_mes' => round($entradasMes, 4),
        'qtd_saidas_mes' => round($saidasMes, 4),
        'requisicoes_rascunho' => count(array_filter($db['requisicoes'] ?? [], fn($r) => ($r['status'] ?? '') === 'rascunho')),
        'abaixo_minimo' => array_slice($abaixo, 0, 10),
        'ultimos_movimentos' => $ultimos,
    ];
}

/* ================================================================== */
/* INVENTÁRIO CÍCLICO (contagem)                                       */
/* ================================================================== */

/**
 * Cria ou atualiza uma contagem de inventário em rascunho.
 * $escopo: 'TODOS' | 'CATEGORIA' | 'LOCALIZACAO' | 'ITENS'
 */
function est_inventario_criar(array &$db, array $input, $usuario) {
    $escopo = strtoupper((string)($input['escopo'] ?? 'TODOS'));
    if (!in_array($escopo, ['TODOS', 'CATEGORIA', 'LOCALIZACAO', 'ITENS'], true)) $escopo = 'TODOS';
    $filtro = est_str($input['filtro'] ?? '', 120);
    $itens = [];
    foreach ($db['itens'] as $code => $it) {
        if ($escopo === 'CATEGORIA' && ($it['categoria'] ?? '') !== $filtro) continue;
        if ($escopo === 'LOCALIZACAO' && ($it['localizacao'] ?? '') !== $filtro) continue;
        if ($escopo === 'ITENS') {
            $lista = array_filter(array_map('trim', explode(',', $filtro)));
            if (!in_array($code, $lista, true)) continue;
        }
        $itens[$code] = [
            'codigo' => $code,
            'descricao' => $it['descricao'],
            'unidade' => $it['unidade'] ?? 'UN',
            'localizacao' => $it['localizacao'] ?? '',
            'saldo_sistema' => round((float)$it['saldo'], 4),
            'contado' => null,
            'diferenca' => null,
            'contado_em' => null,
            'contado_por' => '',
        ];
    }
    if (!$itens) est_fail('Nenhum item atende ao escopo da contagem', 422);
    $db['sequencia']['inv'] = (int)($db['sequencia']['inv'] ?? 0) + 1;
    $inv = [
        'id' => 'INV-' . date('Y') . '-' . str_pad((string)$db['sequencia']['inv'], 4, '0', STR_PAD_LEFT),
        'escopo' => $escopo,
        'filtro' => $filtro,
        'status' => 'rascunho',
        'criado_em' => est_now(),
        'criado_por' => est_str($usuario, 120),
        'atualizado_em' => est_now(),
        'aplicado_em' => null,
        'itens' => $itens,
        'observacao' => est_str($input['observacao'] ?? '', 600),
    ];
    $db['inventarios'][$inv['id']] = $inv;
    return $inv;
}

/** Grava as quantidades contadas de uma contagem em rascunho. */
function est_inventario_contar(array &$db, array $input, $usuario) {
    $id = est_sanitize_code($input['id'] ?? '');
    if ($id === '' || !isset($db['inventarios'][$id])) est_fail('Contagem não encontrada', 404);
    $inv = &$db['inventarios'][$id];
    if (($inv['status'] ?? '') === 'aplicado') est_fail('Esta contagem já foi aplicada ao estoque', 409);
    if (!isset($input['itens']) || !is_array($input['itens'])) est_fail('Envie as quantidades contadas');
    $now = est_now();
    foreach ($input['itens'] as $linha) {
        if (!is_array($linha)) continue;
        $code = (string)($linha['codigo'] ?? '');
        if (!isset($inv['itens'][$code])) continue;
        $q = $linha['contado'] ?? null;
        if ($q === null || $q === '') { $inv['itens'][$code]['contado'] = null; $inv['itens'][$code]['diferenca'] = null; continue; }
        if (!is_numeric($q) || (float)$q < 0) est_fail('Quantidade contada inválida em: ' . $inv['itens'][$code]['descricao']);
        $q = round((float)$q, 4);
        $inv['itens'][$code]['contado'] = $q;
        $inv['itens'][$code]['diferenca'] = round($q - (float)$inv['itens'][$code]['saldo_sistema'], 4);
        $inv['itens'][$code]['contado_em'] = $now;
        $inv['itens'][$code]['contado_por'] = est_str($usuario, 120);
    }
    $inv['atualizado_em'] = $now;
    unset($inv);
    return $db['inventarios'][$id];
}

/**
 * Aplica a contagem: para cada item com divergência, lança um movimento
 * AJUSTE_INVENTARIO. Nunca apaga movimentos; tudo fica auditado.
 */
function est_inventario_aplicar(array &$db, $id, $usuario) {
    $id = est_sanitize_code($id);
    if (!isset($db['inventarios'][$id])) est_fail('Contagem não encontrada', 404);
    $inv = &$db['inventarios'][$id];
    if (($inv['status'] ?? '') === 'aplicado') est_fail('Esta contagem já foi aplicada', 409);
    $pendentes = 0; $movs = []; $ajustados = 0;
    foreach ($inv['itens'] as $code => $linha) {
        if ($linha['contado'] === null) { $pendentes++; continue; }
    }
    if ($pendentes > 0) est_fail($pendentes . ' item(ns) ainda sem contagem. Conte todos antes de aplicar.', 422);
    foreach ($inv['itens'] as $code => $linha) {
        $dif = round((float)$linha['contado'] - (float)$linha['saldo_sistema'], 4);
        if (abs($dif) < 0.00001) continue;
        $movs[] = est_apply_movement($db, [
            'tipo' => 'AJUSTE_INVENTARIO',
            'codigo' => $code,
            'quantidade' => (float)$linha['contado'],
            'origem' => 'INVENTARIO',
            'documento' => $id,
            'observacao' => 'Contagem de inventário (sistema ' . est_num($linha['saldo_sistema']) . ' → contado ' . est_num($linha['contado']) . ')',
            'usuario' => $usuario,
        ]);
        $ajustados++;
    }
    $inv['status'] = 'aplicado';
    $inv['aplicado_em'] = est_now();
    $inv['aplicado_por'] = est_str($usuario, 120);
    $inv['atualizado_em'] = est_now();
    $inv['movimentos'] = array_column($movs, 'id');
    unset($inv);
    return ['inventario' => $db['inventarios'][$id], 'ajustados' => $ajustados, 'movimentos' => array_column($movs, 'id')];
}

function est_inventarios_listar(array $db) {
    $out = [];
    foreach ($db['inventarios'] ?? [] as $inv) {
        $contados = 0; $diverg = 0; $difTotal = 0.0;
        foreach ($inv['itens'] as $l) {
            if ($l['contado'] !== null) $contados++;
            if ($l['diferenca'] !== null && abs((float)$l['diferenca']) > 0.00001) { $diverg++; $difTotal += (float)$l['diferenca']; }
        }
        $out[] = [
            'id' => $inv['id'], 'escopo' => $inv['escopo'], 'filtro' => $inv['filtro'],
            'status' => $inv['status'], 'criado_em' => $inv['criado_em'], 'criado_por' => $inv['criado_por'],
            'aplicado_em' => $inv['aplicado_em'] ?? null,
            'total_itens' => count($inv['itens']), 'contados' => $contados,
            'divergencias' => $diverg, 'diferenca_total' => round($difTotal, 4),
        ];
    }
    usort($out, fn($a, $b) => strcmp((string)$b['criado_em'], (string)$a['criado_em']));
    return ['inventarios' => $out, 'total' => count($out)];
}

/* ================================================================== */
/* RESERVAS (material separado para um PC/obra)                        */
/* ================================================================== */

function est_reservar(array &$db, array $input, $usuario) {
    $pc = (int)($input['pc'] ?? 0);
    if ($pc <= 0) est_fail('Informe o PC da reserva', 422);
    $linhas = est_lines_input($input);
    $now = est_now();
    $id = 'RSV-' . $pc;
    $res = $db['reservas'][$id] ?? ['id' => $id, 'pc' => $pc, 'criado_em' => $now, 'itens' => [], 'status' => 'ativa'];
    if (($res['status'] ?? '') === 'cancelada') $res['status'] = 'ativa';
    foreach ($linhas as $line) {
        $code = est_match_item($db, $line['descricao'], $line['codigo'] ?? '');
        if ($code === null) est_fail('Item não encontrado no estoque: ' . $line['descricao'], 404);
        $it = $db['itens'][$code];
        $disp = round((float)$it['saldo'] - (float)$it['reservado'], 4);
        $jaReservado = 0.0;
        foreach ($res['itens'] as $r) if ($r['codigo'] === $code) $jaReservado += (float)$r['quantidade'];
        if ((float)$line['quantidade'] > $disp + $jaReservado + 0.00001) {
            est_fail(sprintf('Saldo insuficiente para reservar %s: disponível %s %s', $it['descricao'], est_num($disp), $it['unidade']), 409);
        }
        $res['itens'][] = ['codigo' => $code, 'quantidade' => (float)$line['quantidade'], 'descricao' => $line['descricao']];
    }
    $res['atualizado_em'] = $now;
    $res['usuario'] = est_str($usuario, 120);
    $db['reservas'][$id] = $res;
    est_recalcular_reservas($db);
    return $res;
}

/** Recalcula o campo `reservado` de cada item a partir das reservas ativas. */
function est_recalcular_reservas(array &$db) {
    foreach ($db['itens'] as $code => &$it) $it['reservado'] = 0.0;
    unset($it);
    foreach ($db['reservas'] ?? [] as $res) {
        if (($res['status'] ?? '') !== 'ativa') continue;
        foreach ($res['itens'] as $l) {
            if (!isset($db['itens'][$l['codigo']])) continue;
            $db['itens'][$l['codigo']]['reservado'] = round((float)$db['itens'][$l['codigo']]['reservado'] + (float)$l['quantidade'], 4);
        }
    }
    // Nunca deixar reservado maior que o saldo.
    foreach ($db['itens'] as $code => &$it) {
        $it['reservado'] = min(round((float)$it['saldo'], 4), round((float)$it['reservado'], 4));
    }
    unset($it);
}

function est_reserva_cancelar(array &$db, $id) {
    $id = est_sanitize_code($id);
    if (!isset($db['reservas'][$id])) est_fail('Reserva não encontrada', 404);
    $db['reservas'][$id]['status'] = 'cancelada';
    $db['reservas'][$id]['atualizado_em'] = est_now();
    est_recalcular_reservas($db);
    return $db['reservas'][$id];
}

/* ================================================================== */
/* TRANSFERÊNCIA DE ENDEREÇO E BAIXA POR PERDA                         */
/* ================================================================== */

function est_transferir(array &$db, array $input, $usuario) {
    $de = est_str($input['de'] ?? '', 80);
    $para = est_str($input['para'] ?? '', 80);
    if ($para === '') est_fail('Informe o endereço de destino', 422);
    $codigos = [];
    foreach ($db['itens'] as $code => $it) {
        if ($de !== '' && ($it['localizacao'] ?? '') !== $de) continue;
        if (in_array($code, (array)($input['codigos'] ?? []), true) || empty($input['codigos'])) $codigos[] = $code;
    }
    if (!$codigos) est_fail('Nenhum item para transferir', 422);
    $movs = [];
    foreach ($codigos as $code) {
        $movs[] = est_apply_movement($db, [
            'tipo' => 'TRANSFERENCIA', 'codigo' => $code, 'quantidade' => 0,
            'localizacao_destino' => $para, 'localizacao_origem' => $db['itens'][$code]['localizacao'] ?? '',
            'observacao' => 'Transferência de endereço', 'usuario' => $usuario,
        ]);
    }
    return $movs;
}

function est_baixa_perda(array &$db, array $input, $usuario) {
    $motivo = est_str($input['motivo'] ?? '', 400);
    if (mb_strlen($motivo) < 4) est_fail('Informe o motivo da baixa (perda, quebra, vencimento)', 422);
    $linhas = est_lines_input($input);
    $movs = [];
    foreach ($linhas as $line) {
        $code = est_match_item($db, $line['descricao'], $line['codigo'] ?? '');
        if ($code === null) est_fail('Item não encontrado: ' . $line['descricao'], 404);
        $movs[] = est_apply_movement($db, [
            'tipo' => 'BAIXA_PERDA', 'codigo' => $code, 'quantidade' => (float)$line['quantidade'],
            'motivo' => $motivo, 'documento' => 'BAIXA-' . date('Ymd'), 'usuario' => $usuario,
            'descricao_origem' => $line['descricao'],
        ]);
    }
    return $movs;
}

/* ================================================================== */
/* RELATÓRIOS DE ESTOQUE                                               */
/* ================================================================== */

/** Curva ABC por valor movimentado (saídas) em um período. */
function est_curva_abc(array $db, $de = '', $ate = '') {
    $valor = [];
    foreach ($db['movimentos'] as $m) {
        if (($m['tipo'] ?? '') !== 'SAIDA') continue;
        $dia = substr((string)($m['em'] ?? ''), 0, 10);
        if ($de !== '' && $dia < $de) continue;
        if ($ate !== '' && $dia > $ate) continue;
        $it = $db['itens'][$m['codigo']] ?? null;
        $custo = $it ? (float)$it['custo_medio'] : 0.0;
        $valor[$m['codigo']] = ($valor[$m['codigo']] ?? 0) + (float)$m['quantidade'] * $custo;
    }
    arsort($valor);
    $total = array_sum($valor);
    $acum = 0; $out = [];
    foreach ($valor as $code => $v) {
        $acum += $v;
        $pct = $total > 0 ? ($v / $total) * 100 : 0;
        $acumPct = $total > 0 ? ($acum / $total) * 100 : 0;
        $it = $db['itens'][$code] ?? [];
        $out[] = [
            'codigo' => $code, 'descricao' => $it['descricao'] ?? $code,
            'valor' => round($v, 2), 'percentual' => round($pct, 2),
            'acumulado' => round($acumPct, 2),
            'classe' => $acumPct <= 80 ? 'A' : ($acumPct <= 95 ? 'B' : 'C'),
        ];
    }
    return ['itens' => $out, 'total' => round($total, 2), 'periodo' => ['de' => $de, 'ate' => $ate]];
}

/** Itens abaixo do ponto de pedido com sugestão de compra (até o máximo). */
function est_reposicao(array $db) {
    $out = [];
    foreach ($db['itens'] as $code => $it) {
        $v = est_item_view($it);
        $ponto = (float)($it['ponto_pedido'] ?? 0);
        $min = (float)($it['minimo'] ?? 0);
        $limiar = $ponto > 0 ? $ponto : ($min > 0 ? $min : 0);
        if ($limiar <= 0) continue;
        if ((float)$v['saldo'] > $limiar) continue;
        $max = (float)($it['maximo'] ?? 0);
        $sugerido = $max > 0 ? max(0, round($max - (float)$v['saldo'], 4)) : max(0, round($limiar * 2 - (float)$v['saldo'], 4));
        $out[] = [
            'codigo' => $code, 'descricao' => $v['descricao'], 'unidade' => $v['unidade'],
            'saldo' => $v['saldo'], 'minimo' => $min, 'ponto_pedido' => $ponto, 'maximo' => $max,
            'sugerido' => $sugerido, 'fornecedor' => $it['fornecedor_padrao'] ?? '',
            'custo_estimado' => round($sugerido * (float)$v['custo_medio'], 2),
            'urgencia' => (float)$v['saldo'] <= 0 ? 'ZERADO' : 'ABAIXO DO PONTO',
        ];
    }
    usort($out, fn($a, $b) => ($a['saldo'] <=> $b['saldo']) ?: strcmp($a['descricao'], $b['descricao']));
    return ['itens' => $out, 'total' => count($out)];
}

/** Extrato (kardex) de um item com saldo corrido. */
function est_kardex(array $db, $codigo, $limite = 200) {
    $limite = max(1, min(2000, (int)$limite));
    // Usa a mesma resolução de código da interface (caixa exata ou tolerante).
    $code = est_match_item($db, '', (string)$codigo);
    if ($code === null || !isset($db['itens'][$code])) est_fail('Item não encontrado', 404);
    $movs = array_values(array_filter($db['movimentos'], fn($m) => ($m['codigo'] ?? '') === $code));
    $movs = array_slice($movs, -$limite);
    $out = [];
    foreach ($movs as $m) {
        $out[] = [
            'id' => $m['id'] ?? '', 'em' => $m['em'] ?? '', 'tipo' => $m['tipo'] ?? '',
            'tipo_label' => EST_MOV_TIPOS[$m['tipo']] ?? $m['tipo'],
            'entrada' => in_array($m['tipo'], ['ENTRADA_RECEBIMENTO', 'ENTRADA_MANUAL', 'ESTORNO_SAIDA', 'AJUSTE_POSITIVO'], true) ? (float)$m['quantidade'] : 0,
            'saida' => in_array($m['tipo'], ['SAIDA', 'ESTORNO_ENTRADA', 'AJUSTE_NEGATIVO', 'BAIXA_PERDA'], true) ? (float)$m['quantidade'] : 0,
            'saldo' => $m['saldo_posterior'], 'documento' => $m['documento'] ?? '',
            'pc' => $m['pc'] ?? null, 'usuario' => $m['usuario'] ?? '',
            'observacao' => $m['observacao'] ?? ($m['motivo'] ?? ''),
        ];
    }
    return ['item' => est_item_view($db['itens'][$code]), 'movimentos' => $out];
}

/* ================================================================== */
/* v3.9 — Inventário SAP: carga, conciliação com pedidos e origem       */
/* ================================================================== */

/**
 * Composição do saldo por origem, desde a última conciliação com o inventário SAP.
 *   saldo = inventario_qtd + pedidos_conciliados + pedidos_pendentes + manual − saídas − perdas ± ajustes
 * 'acumulado' guarda os totais desde sempre (nunca zera).
 */
function est_conciliacao_registrar(array &$item, array $mov, $antes, $depois) {
    $c = $item['conciliacao'] ?? null;
    if (!is_array($c)) {
        // Item anterior à v3.9: tudo o que existe veio de pedidos/entradas ainda não conciliados.
        $c = ['inventario_id' => null, 'inventario_em' => null, 'inventario_qtd' => 0.0, 'inventario_preco' => 0.0,
              'pedidos_conciliados' => 0.0, 'pedidos_pendentes' => max(0.0, (float)$antes), 'manual' => 0.0,
              'saidas' => 0.0, 'perdas' => 0.0, 'ajustes' => 0.0];
    }
    $delta = round((float)$depois - (float)$antes, 4);
    $orig = EST_MOV_ORIGEM[$mov['tipo']] ?? 'ajuste';
    if ($mov['tipo'] === 'INVENTARIO_SAP') {
        $c = ['inventario_id' => $mov['documento'] ?? null, 'inventario_em' => $mov['em'] ?? est_now(),
              'inventario_qtd' => round((float)($mov['qtd_sap'] ?? 0), 4), 'inventario_preco' => round((float)($mov['custo_unitario'] ?? 0), 4),
              'pedidos_conciliados' => round((float)($mov['pedidos_somados'] ?? 0), 4), 'pedidos_pendentes' => 0.0,
              'manual' => 0.0, 'saidas' => 0.0, 'perdas' => 0.0, 'ajustes' => 0.0];
    } else {
        $map = ['pedidos' => 'pedidos_pendentes', 'manual' => 'manual', 'ajuste' => 'ajustes', 'perdas' => 'perdas', 'saidas' => 'saidas'];
        if (isset($map[$orig])) {
            $k = $map[$orig];
            $v = in_array($k, ['saidas', 'perdas'], true) ? -$delta : $delta;
            $c[$k] = round((float)($c[$k] ?? 0) + $v, 4);
        }
    }
    $item['conciliacao'] = $c;
    $ac = $item['acumulado'] ?? [];
    $ac[$orig] = round((float)($ac[$orig] ?? 0) + $delta, 4);
    $item['acumulado'] = $ac;
}

/** Movimentos que saem do JSON principal vão para um arquivo anual (append-only). */
function est_arquivar_movimentos(array $movs) {
    if (!$movs) return;
    if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
    $f = ESTOQUE_DIR . '/movimentos_arquivo_' . date('Y') . '.jsonl';
    $txt = '';
    foreach ($movs as $m) $txt .= json_encode($m, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($f, $txt, FILE_APPEND | LOCK_EX);
}

/** "R$ 7.056,53" / "1.234,5" / 221 → float */
function est_num_br($v) {
    if (is_int($v) || is_float($v)) return (float)$v;
    $t = trim(str_replace(['R$', ' ', "\xC2\xA0"], '', (string)$v));
    if ($t === '' || $t === '-') return 0.0;
    if (strpos($t, ',') !== false) $t = str_replace(['.', ','], ['', '.'], $t);
    return is_numeric($t) ? (float)$t : 0.0;
}

/** Normaliza as linhas enviadas pela tela (planilha "Inventário no relatório do depósito"). */
function est_sap_linhas(array $rows) {
    if (count($rows) > 20000) est_fail('Planilha grande demais (máximo 20.000 linhas).');
    $out = []; $dup = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $cod = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($r['codigo'] ?? ''))));
        if ($cod === '' || stripos($cod, 'DEPÓSITO') === 0 || stripos($cod, 'DEPOSITO') === 0 || mb_strlen($cod) > 40) continue;
        if (!preg_match('/^[A-Z0-9 ._\/-]+$/', $cod)) continue;
        if (isset($out[$cod])) { $dup[] = $cod; continue; }
        $un = strtoupper(est_str($r['unidade'] ?? '', 12));
        $un = ['LITRO' => 'L', 'QUILO' => 'KG', 'MT' => 'M'][$un] ?? $un;
        $out[$cod] = ['codigo' => $cod, 'descricao' => est_str($r['descricao'] ?? '', 600),
            'unidade' => $un ?: 'UN', 'qtd' => round(max(0, est_num_br($r['em_estoque'] ?? 0)), 4),
            'preco' => round(max(0, est_num_br($r['preco'] ?? 0)), 4), 'total' => round(max(0, est_num_br($r['total'] ?? 0)), 2),
            'pedido_sap' => round(max(0, est_num_br($r['pedido'] ?? 0)), 4)];
    }
    if (!$out) est_fail('Nenhum item reconhecido. Use a planilha "Inventário no relatório do depósito" exportada do SAP.');
    return [$out, $dup];
}

/** Item antigo (criado por PC, código EST-…) que tem o código SAP nos ids de origem. */
function est_sap_item_antigo(array $db, $sap) {
    foreach ($db['itens'] as $c => $it) {
        if ($c === $sap || est_sap_code($c) !== '') continue;
        foreach ((array)($it['origem_ids'] ?? []) as $o) {
            if (est_sap_code($o) === $sap) return $c;
        }
    }
    return null;
}

/** Troca o código de um item (unificação com o código SAP), mantendo histórico e referências. */
function est_renomear_item(array &$db, $de, $para) {
    $it = $db['itens'][$de];
    $it['codigo'] = $para;
    $it['aliases'] = array_values(array_unique(array_merge((array)($it['aliases'] ?? []), [$de])));
    $it['codigo_anterior'] = $de;
    unset($db['itens'][$de]);
    $db['itens'][$para] = $it;
    foreach ($db['movimentos'] as &$m) if (($m['codigo'] ?? '') === $de) { $m['codigo'] = $para; $m['codigo_anterior'] = $de; }
    unset($m);
    foreach ($db['recebimentos'] as &$doc) foreach ($doc['linhas'] as &$l) if (($l['codigo'] ?? '') === $de) $l['codigo'] = $para;
    unset($doc, $l);
    foreach ($db['reservas'] as &$r) if (($r['codigo'] ?? '') === $de) $r['codigo'] = $para;
    unset($r);
}

/**
 * Calcula a conciliação sem gravar (prévia) ou grava ($aplicar = true).
 * Opções: somar_pedidos (padrão true) — soma ao saldo SAP o que entrou por PC desde a
 *         última conciliação; false quando a planilha já inclui esses recebimentos.
 */
function est_sap_conciliar(array &$db, array $rows, array $opt, $usuario, $aplicar) {
    [$linhas, $dup] = est_sap_linhas($rows);
    $somar = !array_key_exists('somar_pedidos', $opt) || !empty($opt['somar_pedidos']);
    $primeira = empty($db['inventarios_sap']);
    $id = 'INV-SAP-' . date('Ymd-His');
    $tot = ['linhas' => count($linhas), 'com_saldo' => 0, 'valor_sap' => 0.0, 'novos' => 0, 'existentes' => 0, 'unificados' => 0,
            'pedidos_qtd' => 0.0, 'pedidos_valor' => 0.0, 'valor_final' => 0.0, 'divergencias' => 0, 'conferidos' => 0,
            'valor_divergencia_pos' => 0.0, 'valor_divergencia_neg' => 0.0, 'sem_preco_com_saldo' => 0, 'fora_da_planilha' => 0,
            'possivel_duplicidade' => 0];
    $detalhe = [];
    $unificar = [];
    $tot['importados_preservados'] = 0;
    foreach ($linhas as $cod => $l) {
        if (!empty($db['itens'][$cod]['importado'])) { $tot['importados_preservados']++; unset($linhas[$cod]); continue; }
    }
    foreach ($linhas as $cod => $l) {
        $atual = $db['itens'][$cod] ?? null;
        $antigo = $atual ? null : est_sap_item_antigo($db, $cod);
        if ($antigo) { $unificar[$antigo] = $cod; $atual = $db['itens'][$antigo]; $tot['unificados']++; }
        if ($atual) $tot['existentes']++; else $tot['novos']++;
        $conc = $atual['conciliacao'] ?? null;
        $saldoAtual = (float)($atual['saldo'] ?? 0);
        $pend = !$atual ? 0.0 : (is_array($conc) ? max(0.0, (float)($conc['pedidos_pendentes'] ?? 0)) : max(0.0, $saldoAtual));
        if (!$somar) $pend = 0.0;
        $preco = $l['preco'] > 0 ? $l['preco'] : (float)($atual['custo_medio'] ?? 0);
        $final = round($l['qtd'] + $pend, 4);
        // Divergência (IRA): só a partir da 2ª conciliação, compara o SAP com a parte SAP esperada.
        $esperado = null; $dif = 0.0;
        if (!$primeira && is_array($conc) && $conc['inventario_id']) {
            $esperado = round($saldoAtual - (float)$conc['pedidos_pendentes'], 4);
            $dif = round($l['qtd'] - $esperado, 4);
            $tot['conferidos']++;
            if (abs($dif) > 0.0001) {
                $tot['divergencias']++;
                if ($dif > 0) $tot['valor_divergencia_pos'] += $dif * $preco; else $tot['valor_divergencia_neg'] += -$dif * $preco;
            }
        }
        // SAP já mostra pelo menos o que entrou pelos PCs: provável que o SAP já tenha lançado a NF.
        $dupl = $somar && $pend > 0 && $l['qtd'] >= $pend;
        if ($dupl) $tot['possivel_duplicidade']++;
        if ($l['qtd'] > 0) $tot['com_saldo']++;
        if ($l['qtd'] > 0 && $l['preco'] <= 0) $tot['sem_preco_com_saldo']++;
        $tot['valor_sap'] += $l['total'] > 0 ? $l['total'] : $l['qtd'] * $l['preco'];
        $tot['pedidos_qtd'] += $pend;
        $tot['pedidos_valor'] += $pend * $preco;
        $tot['valor_final'] += $final * $preco;
        if ($l['qtd'] > 0 || $pend > 0 || abs($dif) > 0.0001 || $saldoAtual > 0) {
            $detalhe[] = ['codigo' => $cod, 'descricao' => $l['descricao'] ?: ($atual['descricao'] ?? ''), 'unidade' => $l['unidade'],
                'qtd_sap' => $l['qtd'], 'pedidos' => $pend, 'saldo_atual' => $saldoAtual, 'saldo_final' => $final,
                'preco' => $preco, 'valor_final' => round($final * $preco, 2), 'esperado' => $esperado, 'diferenca' => $dif,
                'situacao' => !$atual ? 'novo' : ($antigo ? 'unificado' : 'existente'), 'codigo_anterior' => $antigo,
                'possivel_duplicidade' => $dupl];
        }
    }
    foreach ($db['itens'] as $c => $it) if (!isset($linhas[$c]) && !isset($unificar[$c]) && (float)$it['saldo'] > 0) $tot['fora_da_planilha']++;
    foreach (['valor_sap', 'pedidos_valor', 'valor_final', 'valor_divergencia_pos', 'valor_divergencia_neg'] as $k) $tot[$k] = round($tot[$k], 2);
    usort($detalhe, fn($a, $b) => $b['valor_final'] <=> $a['valor_final']);
    $res = ['id' => $id, 'primeira_carga' => $primeira, 'somar_pedidos' => $somar, 'totais' => $tot,
            'duplicados' => array_slice($dup, 0, 50), 'linhas' => $detalhe];
    if (!$aplicar) { $res['linhas'] = array_slice($detalhe, 0, 400); $res['linhas_total'] = count($detalhe); return $res; }

    // ---------- gravação ----------
    est_backup_once();
    if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
    // Cópia integral da planilha recebida: a origem fica guardada para sempre.
    @file_put_contents(ESTOQUE_DIR . '/' . strtolower($id) . '.json', json_encode(['id' => $id, 'em' => est_now(), 'usuario' => $usuario,
        'arquivo' => est_str($opt['arquivo'] ?? '', 200), 'deposito' => est_str($opt['deposito'] ?? '', 40), 'linhas' => array_values($linhas)],
        JSON_UNESCAPED_UNICODE), LOCK_EX);
    foreach ($unificar as $de => $para) est_renomear_item($db, $de, $para);
    $porCod = [];
    foreach ($detalhe as $d) $porCod[$d['codigo']] = $d;
    foreach ($linhas as $cod => $l) {
        if (!isset($db['itens'][$cod])) {
            $db['itens'][$cod] = est_new_item(['descricao' => $l['descricao'], 'unidade' => $l['unidade']], $cod);
        }
        $it = &$db['itens'][$cod];
        if ($l['descricao'] !== '' && $it['descricao'] !== $l['descricao']) {
            if ($it['descricao'] !== '' && count($it['aliases'] ?? []) < 25) $it['aliases'][] = $it['descricao'];
            $it['descricao'] = $l['descricao'];
        }
        $it['codigo_sap'] = $cod;
        $it['preco_sap'] = $l['preco'];
        $it['deposito_sap'] = est_str($opt['deposito'] ?? '', 40);
        if ($l['pedido_sap'] > 0) $it['pedido_sap'] = $l['pedido_sap'];
        unset($it);
        $d = $porCod[$cod] ?? null;
        $saldoAtual = (float)$db['itens'][$cod]['saldo'];
        $final = $d ? $d['saldo_final'] : 0.0;
        if (!$d) { // item só de cadastro (sem saldo no SAP nem no sistema): guarda o preço, sem movimento
            if ($l['preco'] > 0) $db['itens'][$cod]['custo_medio'] = $l['preco'];
            continue;
        }
        est_apply_movement($db, ['tipo' => 'INVENTARIO_SAP', 'codigo' => $cod, 'quantidade' => round($final - $saldoAtual, 4),
            'saldo_alvo' => $final, 'qtd_sap' => $l['qtd'], 'pedidos_somados' => $d['pedidos'] ?? 0,
            'custo_unitario' => $d['preco'] ?? $l['preco'], 'valor_total' => round($final * ($d['preco'] ?? $l['preco']), 2),
            'diferenca_inventario' => $d['diferenca'] ?? 0, 'documento' => $id, 'usuario' => $usuario,
            'observacao' => 'Inventário SAP ' . ($opt['deposito'] ?? '') . ': ' . est_num($l['qtd']) . ' + PCs ' . est_num($d['pedidos'] ?? 0)]);
    }
    $reg = $res;
    unset($reg['linhas']);
    $reg['em'] = est_now();
    $reg['usuario'] = $usuario;
    $reg['arquivo'] = est_str($opt['arquivo'] ?? '', 200);
    $reg['deposito'] = est_str($opt['deposito'] ?? '', 40);
    $reg['data_base'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($opt['data_base'] ?? '')) ? $opt['data_base'] : date('Y-m-d');
    $reg['divergentes'] = array_values(array_slice(array_filter($detalhe, fn($x) => abs($x['diferenca']) > 0.0001), 0, 500));
    $db['inventarios_sap'][] = $reg;
    return $res;
}

function est_sap_historico(array $db) {
    $out = [];
    foreach (array_reverse($db['inventarios_sap'] ?? []) as $r) { unset($r['divergentes']); $out[] = $r; }
    return $out;
}

/** Composição legível do saldo de um item, para a ficha. */
function est_composicao(array $it) {
    $c = $it['conciliacao'] ?? null;
    if (!is_array($c)) return [['origem' => 'Pedidos de compra / entradas', 'qtd' => (float)$it['saldo']]];
    $l = [];
    if ($c['inventario_id']) $l[] = ['origem' => 'Inventário SAP (' . substr((string)$c['inventario_em'], 0, 10) . ')', 'qtd' => (float)$c['inventario_qtd']];
    if ((float)$c['pedidos_conciliados']) $l[] = ['origem' => 'Pedidos de compra somados na conciliação', 'qtd' => (float)$c['pedidos_conciliados']];
    if ((float)$c['pedidos_pendentes']) $l[] = ['origem' => 'Pedidos de compra recebidos depois', 'qtd' => (float)$c['pedidos_pendentes']];
    if ((float)$c['manual']) $l[] = ['origem' => 'Entradas manuais', 'qtd' => (float)$c['manual']];
    if ((float)$c['ajustes']) $l[] = ['origem' => 'Ajustes', 'qtd' => (float)$c['ajustes']];
    if ((float)$c['saidas']) $l[] = ['origem' => 'Saídas', 'qtd' => -(float)$c['saidas']];
    if ((float)$c['perdas']) $l[] = ['origem' => 'Perdas / baixas', 'qtd' => -(float)$c['perdas']];
    return $l;
}


/* ================================================================== */
/* v3.9.1 — Materiais críticos IMPORTADOS (controle pela planilha)       */
/* ================================================================== */

/** Códigos SAP já existentes para os importados; os demais recebem IMP-NNN. */
const EST_IMP_SAP = [
    'BROCA DE BITS COMUM' => 'CO000002', 'BROCA DE BITS PARA ROCHA DURA' => 'CO000003', 'BROCA ESPECIAL MODELO BAUER - DENTE' => 'CO000004',
    'BASE DE BITS COMUM' => 'CO000005', 'BASE DE BITS ROCHA DURA' => 'CO000006', 'BASE PARA BROCA MODELO BAUER - DENTE' => 'CO000007',
    'ROLLER BITS' => 'CO000008', 'BASE PARA ROLLER BITS' => 'CO000009', 'POLIMERO POLYMUD 25KG' => 'CO000173', 'BARRILHA' => 'CO000057',
];
/** Fotos oficiais do catálogo (assets/importados). */
const EST_IMP_FOTOS = [
    'BASE BROCA GUIA DR100' => 'base-broca-guia-dr100.jpg',
    'BROCA GUIA DR100' => 'broca-guia-dr100.jpg',
    'BASE DE BITS COMUM' => 'base-de-bits-comum.jpg',
    'BROCA DE BITS COMUM' => 'broca-de-bits-comum.jpg',
    'BASE DE BITS ROCHA DURA' => 'base-de-bits-rocha-dura.jpg',
    'BROCA DE BITS PARA ROCHA DURA' => 'broca-de-bits-para-rocha-dura.jpg',
    'BASE PARA BROCA MODELO BAUER - DENTE' => 'base-para-broca-modelo-bauer-dente.jpg',
    'BROCA ESPECIAL MODELO BAUER - DENTE' => 'broca-especial-modelo-bauer-dente.png',
    'BASE PARA ROLLER BITS' => 'base-para-roller-bits.jpg',
    'ROLLER BITS' => 'roller-bits.jpg',
    'BASE PASTILHAS (BITS) ESTACA SECANTE' => 'base-pastilhas-bits-estaca-secante.jpg',
    'PASTILHAS (BITS) ESTACA SECANTE' => 'pastilhas-bits-estaca-secante.jpg',
    'BASE GUIA PILOTO' => 'base-guia-piloto.jpg',
    'BROCA GUIA RP4-200 BITS PILOTO' => 'broca-guia-rp4-200-bits-piloto.jpg',
    'BROCA FISH TAIL' => 'broca-fish-tail.jpg',
    'BITS PARA DR100' => 'bits-para-dr100.jpg',
];
/** Pares base ↔ broca (exibidos lado a lado). */
const EST_IMP_PARES = [
    'BASE BROCA GUIA DR100' => 'BROCA GUIA DR100', 'BASE DE BITS COMUM' => 'BROCA DE BITS COMUM', 'BASE DE BITS ROCHA DURA' => 'BROCA DE BITS PARA ROCHA DURA',
    'BASE PARA BROCA MODELO BAUER - DENTE' => 'BROCA ESPECIAL MODELO BAUER - DENTE', 'BASE PARA ROLLER BITS' => 'ROLLER BITS',
    'BASE PASTILHAS (BITS) ESTACA SECANTE' => 'PASTILHAS (BITS) ESTACA SECANTE', 'BASE GUIA PILOTO' => 'BROCA GUIA RP4-200 BITS PILOTO',
];

function est_imp_nome($v) { return strtoupper(trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', (string)$v)))); }
function est_imp_obra($v) {
    $o = est_imp_nome($v);
    $fix = ['SÃO BERNADO DO CAMPOS' => 'SÃO BERNARDO DO CAMPO', 'SAO BERNADO DO CAMPOS' => 'SÃO BERNARDO DO CAMPO', 'DEPÓSITO' => 'DEPOSITO', '-' => ''];
    return $fix[$o] ?? $o;
}

/**
 * Carga única da planilha "CONTROLE IMPORTADOS": cadastro, parâmetros, histórico com data
 * original, obra e solicitante. O saldo final de cada produto fica IGUAL à aba ESTOQUE;
 * diferenças viram um ajuste explícito "Conciliação com a aba ESTOQUE".
 */
function est_importados_carga(array &$db, array $in, $usuario, $aplicar) {
    $produtos = []; $movs = [];
    foreach ((array)($in['produtos'] ?? []) as $p) {
        $n = est_imp_nome($p['nome'] ?? '');
        if ($n === '' || $n === 'PRODUTO' || $n === 'ITEM' || !preg_match('/\p{L}{3}/u', $n)) continue;
        $produtos[$n] = ['nome' => $n, 'saldo' => isset($p['saldo']) && is_numeric($p['saldo']) ? (float)$p['saldo'] : null,
            'disparo' => (float)($p['disparo'] ?? 0), 'recomendado' => (float)($p['recomendado'] ?? 0), 'alerta' => est_str($p['alerta'] ?? '', 20)];
    }
    foreach ((array)($in['movimentos'] ?? []) as $m) {
        $n = est_imp_nome($m['produto'] ?? '');
        if (!preg_match('/\p{L}{3}/u', $n)) continue;
        $tipo = est_imp_nome($m['tipo'] ?? '');
        $data = (string)($m['data'] ?? '');
        if ($n === '' || !in_array($tipo, ['ENTRADA', 'SAÍDA', 'SAIDA'], true) || !strtotime($data) || (float)($m['quantidade'] ?? 0) <= 0) continue;
        $movs[] = ['produto' => $n, 'tipo' => $tipo === 'ENTRADA' ? 'ENTRADA' : 'SAIDA', 'situacao' => est_imp_nome($m['situacao'] ?? ''),
            'data' => date('Y-m-d', strtotime($data)), 'qtd' => round((float)$m['quantidade'], 4), 'obra' => est_imp_obra($m['obra'] ?? ''),
            'solicitante' => est_str(est_imp_nome($m['solicitante'] ?? '') === '-' ? '' : ($m['solicitante'] ?? ''), 80), 'obs' => est_str($m['observacao'] ?? '', 300)];
        if (!isset($produtos[$n])) $produtos[$n] = ['nome' => $n, 'saldo' => null, 'disparo' => 0, 'recomendado' => 0, 'alerta' => ''];
    }
    if (!$produtos) est_fail('Nenhum produto reconhecido. Use a planilha CONTROLE IMPORTADOS (abas MOVIMENTAÇÃO, ESTOQUE e CONSUMO MENSAL).');
    usort($movs, fn($a, $b) => strcmp($a['data'], $b['data']));
    // códigos
    $seq = (int)($db['sequencia']['imp'] ?? 0);
    $mapa = [];
    foreach ($produtos as $n => $p) {
        $existe = null;
        foreach ($db['itens'] as $c => $it) if (!empty($it['importado']) && est_imp_nome($it['nome_planilha'] ?? '') === $n) { $existe = $c; break; }
        $mapa[$n] = $existe ?? (EST_IMP_SAP[$n] ?? null);
        if ($mapa[$n] === null) { $seq++; $mapa[$n] = 'IMP-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT); }
    }
    // prévia
    $replay = [];
    foreach ($movs as $m) $replay[$m['produto']] = ($replay[$m['produto']] ?? 0) + ($m['tipo'] === 'ENTRADA' ? $m['qtd'] : -$m['qtd']);
    $linhas = [];
    foreach ($produtos as $n => $p) {
        $final = $p['saldo'] ?? ($replay[$n] ?? 0);
        $linhas[] = ['codigo' => $mapa[$n], 'nome' => $n, 'saldo_sistema_hoje' => (float)($db['itens'][$mapa[$n]]['saldo'] ?? 0),
            'soma_movimentos' => round($replay[$n] ?? 0, 4), 'saldo_planilha' => $final, 'ajuste' => round($final - ($replay[$n] ?? 0), 4),
            'disparo' => $p['disparo'], 'recomendado' => $p['recomendado'], 'foto' => EST_IMP_FOTOS[$n] ?? '', 'sap' => isset(EST_IMP_SAP[$n])];
    }
    $res = ['produtos' => count($produtos), 'movimentos' => count($movs), 'linhas' => $linhas,
            'ja_carregado' => !empty($db['importados_carga']), 'periodo' => $movs ? [$movs[0]['data'], end($movs)['data']] : null];
    if (!$aplicar) return $res;
    if (!empty($db['importados_carga']) && empty($in['recarregar'])) est_fail('A planilha de importados já foi carregada em ' . substr($db['importados_carga']['em'], 0, 10) . '. Os lançamentos novos são feitos pelo próprio módulo.', 409);

    est_backup_once();
    if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
    $id = 'IMP-CARGA-' . date('Ymd-His');
    @file_put_contents(ESTOQUE_DIR . '/' . strtolower($id) . '.json', json_encode(['id' => $id, 'em' => est_now(), 'usuario' => $usuario,
        'arquivo' => est_str($in['arquivo'] ?? '', 200), 'produtos' => array_values($produtos), 'movimentos' => $movs], JSON_UNESCAPED_UNICODE), LOCK_EX);
    $db['sequencia']['imp'] = $seq;
    $inicio = $movs ? date('Y-m-d', strtotime($movs[0]['data'] . ' -1 day')) : date('Y-m-d');
    foreach ($produtos as $n => $p) {
        $c = $mapa[$n];
        if (!isset($db['itens'][$c])) $db['itens'][$c] = est_new_item(['descricao' => $n, 'unidade' => 'UN', 'categoria' => 'IMPORTADOS'], $c);
        $it = &$db['itens'][$c];
        $it['importado'] = true; $it['nome_planilha'] = $n; $it['categoria'] = 'IMPORTADOS';
        if ($it['descricao'] === '' ) $it['descricao'] = $n;
        $it['ponto_pedido'] = $p['disparo']; $it['minimo'] = $p['disparo']; $it['maximo'] = $p['recomendado'];
        $it['fabricavel'] = str_starts_with($n, 'BASE ');
        $it['lead_time_dias'] = (int)($it['lead_time_dias'] ?? 90) ?: 90;
        if (!empty(EST_IMP_FOTOS[$n]) && empty($it['foto'])) $it['foto_padrao'] = 'assets/importados/' . EST_IMP_FOTOS[$n];
        if (isset(EST_IMP_PARES[$n])) $it['par'] = $mapa[EST_IMP_PARES[$n]] ?? null;
        foreach (EST_IMP_PARES as $b => $br) if ($br === $n) $it['par'] = $mapa[$b] ?? null;
        $saldoAntes = (float)$it['saldo'];
        unset($it);
        // O saldo vindo do SAP (se houver) é substituído pelo controle da planilha.
        if ($saldoAntes != 0.0) est_apply_movement($db, ['tipo' => 'AJUSTE_INVENTARIO', 'codigo' => $c, 'quantidade' => 0, 'historico' => true,
            'data_original' => $inicio, 'documento' => $id, 'usuario' => $usuario,
            'observacao' => 'Saldo anterior (' . est_num($saldoAntes) . ') substituído pelo controle da planilha de importados']);
    }
    foreach ($movs as $m) {
        $c = $mapa[$m['produto']];
        $tipo = $m['tipo'] === 'ENTRADA' ? ($m['situacao'] === 'AJUSTE' ? 'AJUSTE_POSITIVO' : 'ENTRADA_MANUAL') : 'SAIDA';
        est_apply_movement($db, ['tipo' => $tipo, 'codigo' => $c, 'quantidade' => $m['qtd'], 'historico' => true, 'data_original' => $m['data'],
            'situacao' => $m['situacao'], 'obra' => $m['obra'], 'solicitante' => $m['solicitante'], 'setor' => $m['obra'],
            'observacao' => $m['obs'], 'documento' => $id, 'usuario' => $usuario, 'origem_planilha' => true]);
    }
    foreach ($linhas as $l) {
        $c = $l['codigo'];
        $atual = (float)$db['itens'][$c]['saldo'];
        if (abs($atual - $l['saldo_planilha']) > 0.0001) {
            est_apply_movement($db, ['tipo' => 'AJUSTE_INVENTARIO', 'codigo' => $c, 'quantidade' => $l['saldo_planilha'], 'historico' => true,
                'data_original' => end($movs)['data'] ?? date('Y-m-d'), 'documento' => $id, 'usuario' => $usuario,
                'observacao' => 'Conciliação com a aba ESTOQUE da planilha: ' . est_num($atual) . ' → ' . est_num($l['saldo_planilha'])]);
        }
    }
    $db['importados_carga'] = ['id' => $id, 'em' => est_now(), 'usuario' => $usuario, 'produtos' => count($produtos), 'movimentos' => count($movs)];
    return $res;
}

/** Visão do módulo Importados: saldo, consumo, cobertura, status e sugestão de compra/fabricação. */
function est_importados_view(array $db, $codigo = null) {
    $hoje = time();
    $meses = [];
    for ($i = 5; $i >= 0; $i--) $meses[] = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01'))));
    $out = [];
    foreach ($db['itens'] as $c => $it) {
        if (empty($it['importado']) || ($codigo !== null && $c !== $codigo)) continue;
        $cons = array_fill_keys($meses, 0.0); $obras = []; $ult = null; $movs = [];
        foreach ($db['movimentos'] as $m) {
            if (($m['codigo'] ?? '') !== $c) continue;
            if ($codigo !== null) $movs[] = $m;
            if (!empty($m['cancelado'])) continue;
            $ult = $m;
            if (($m['tipo'] ?? '') !== 'SAIDA') continue;
            $mes = substr((string)$m['em'], 0, 7);
            if (isset($cons[$mes])) $cons[$mes] += (float)$m['quantidade'];
            $o = (string)($m['obra'] ?? $m['setor'] ?? '') ?: '—';
            if (strtotime((string)$m['em']) >= strtotime('-6 months')) $obras[$o] = ($obras[$o] ?? 0) + (float)$m['quantidade'];
        }
        $ult3 = array_slice(array_values($cons), -4, 3); // 3 meses fechados
        $media = round(array_sum($ult3) / 3, 2);
        $saldo = (float)$it['saldo'];
        $disparo = (float)($it['ponto_pedido'] ?? 0);
        $rec = (float)($it['maximo'] ?? 0);
        $lead = (int)($it['lead_time_dias'] ?? 90);
        $cobDias = $media > 0 ? round($saldo / $media * 30) : null;
        $acao = !empty($it['fabricavel']) ? 'FABRICAR' : 'COMPRAR';
        $status = $saldo <= 0 ? 'SEM ESTOQUE' : ($disparo > 0 && $saldo <= $disparo ? $acao : (($cobDias !== null && $cobDias < $lead) ? 'ATENÇÃO' : 'OK'));
        arsort($obras);
        $v = ['codigo' => $c, 'nome' => $it['descricao'], 'nome_planilha' => $it['nome_planilha'] ?? '', 'saldo' => $saldo, 'unidade' => $it['unidade'],
            'disparo' => $disparo, 'recomendado' => $rec, 'lead_time_dias' => $lead, 'fabricavel' => !empty($it['fabricavel']),
            'foto' => $it['foto'] ?? ($it['foto_padrao'] ?? ''), 'foto_original' => $it['foto_padrao'] ?? '', 'foto_trocada' => !empty($it['foto']),
            'localizacao' => $it['localizacao'] ?? '', 'par' => $it['par'] ?? null, 'custo_medio' => (float)$it['custo_medio'],
            'consumo' => $cons, 'media_mensal' => $media, 'cobertura_dias' => $cobDias, 'status' => $status, 'acao' => $acao,
            'sugestao' => max(0, round($rec - $saldo, 2)), 'ruptura_prevista' => $cobDias !== null ? date('Y-m-d', $hoje + $cobDias * 86400) : null,
            'pedir_ate' => $cobDias !== null ? date('Y-m-d', $hoje + max(0, $cobDias - $lead) * 86400) : null,
            'obras' => array_slice($obras, 0, 8, true), 'ultimo_movimento' => $ult ? ['em' => $ult['em'], 'tipo' => $ult['tipo'], 'obra' => $ult['obra'] ?? ''] : null];
        // Ordem real de lançamento (mais recente primeiro): a coluna de saldo fica coerente linha a linha.
        if ($codigo !== null) $v['movimentos'] = array_slice(array_reverse($movs), 0, 400);
        $out[] = $v;
    }
    $ordem = ['SEM ESTOQUE' => 0, 'COMPRAR' => 1, 'FABRICAR' => 1, 'ATENÇÃO' => 2, 'OK' => 3];
    usort($out, fn($a, $b) => [$ordem[$a['status']], $a['nome']] <=> [$ordem[$b['status']], $b['nome']]);
    return $codigo !== null ? ($out[0] ?? null) : ['itens' => $out, 'carga' => $db['importados_carga'] ?? null, 'meses' => $meses];
}

/** Entrada/saída do dia a dia, já com obra e solicitante. */
function est_importado_mov(array &$db, array $in, $usuario) {
    $c = (string)($in['codigo'] ?? '');
    if (empty($db['itens'][$c]['importado'])) est_fail('Item importado não encontrado', 404);
    $sit = est_imp_nome($in['situacao'] ?? '');
    $q = round((float)($in['quantidade'] ?? 0), 4);
    if ($sit !== 'CONTAGEM' && ($q <= 0 || $q > 1000000)) est_fail('Informe a quantidade');
    $map = ['COMPRA' => 'ENTRADA_MANUAL', 'FABRICAÇÃO' => 'ENTRADA_MANUAL', 'DEVOLUÇÃO' => 'ENTRADA_MANUAL', 'AJUSTE +' => 'AJUSTE_POSITIVO',
            'ENVIO PARA OBRA' => 'SAIDA', 'RESERVA PARA OBRA' => 'SAIDA', 'AJUSTE -' => 'AJUSTE_NEGATIVO', 'PERDA' => 'BAIXA_PERDA',
            'CONTAGEM' => 'AJUSTE_INVENTARIO'];
    if (!isset($map[$sit])) est_fail('Situação inválida');
    // v3.10.2: "Contagem" corrige o saldo para o valor contado (ex.: conferência física dos itens da planilha).
    if ($sit === 'CONTAGEM') {
        $alvo = round((float)($in['quantidade'] ?? -1), 4);
        if (!is_numeric($in['quantidade'] ?? null) || $alvo < 0) est_fail('Informe o saldo contado (0 ou mais).');
        $motivo = est_str($in['observacao'] ?? '', 300);
        if (mb_strlen($motivo) < 3) est_fail('Informe o motivo da correção (ex.: contagem física de 12/11).');
        $antes = (float)$db['itens'][$c]['saldo'];
        if (abs($antes - $alvo) < 0.00001) est_fail('O saldo já é ' . est_num($alvo) . '. Nada a corrigir.');
        return est_apply_movement($db, ['tipo' => 'AJUSTE_INVENTARIO', 'codigo' => $c, 'quantidade' => $alvo, 'situacao' => 'CONTAGEM',
            'observacao' => 'Contagem: ' . est_num($antes) . ' → ' . est_num($alvo) . ' · ' . $motivo, 'usuario' => $usuario, 'documento' => 'IMP-CONTAGEM',
            'solicitante' => est_str($in['solicitante'] ?? '', 80)]);
    }
    $obra = est_imp_obra($in['obra'] ?? '');
    if ($map[$sit] === 'SAIDA' && $obra === '') est_fail('Informe a obra de destino');
    $ass = null;
    if ($map[$sit] === 'SAIDA') {
        $disp = (float)$db['itens'][$c]['saldo'] - (float)$db['itens'][$c]['reservado'];
        if ($q > $disp + 0.00001) est_fail('Saldo insuficiente: disponível ' . est_num($disp), 409);
        $ass = est_assinatura_salvar($db, (array)($in['assinatura'] ?? []), 'IMP-' . $c, $usuario);
    }
    return est_apply_movement($db, ['os' => est_os($in['os'] ?? ''), 'equipamento' => est_str($in['equipamento'] ?? '', 120), 'assinatura_id' => $ass['id'] ?? null, 'recebedor' => $ass['nome'] ?? '', 'tipo' => $map[$sit], 'codigo' => $c, 'quantidade' => $q, 'situacao' => $sit, 'obra' => $obra, 'setor' => $obra,
        'solicitante' => est_str($in['solicitante'] ?? '', 80), 'observacao' => est_str($in['observacao'] ?? '', 300),
        'numero_nf' => est_str($in['numero_nf'] ?? '', 40), 'usuario' => $usuario, 'documento' => 'IMP-MOV']);
}

function est_importado_config(array &$db, array $in) {
    $c = (string)($in['codigo'] ?? '');
    if (empty($db['itens'][$c]['importado'])) est_fail('Item importado não encontrado', 404);
    $it = &$db['itens'][$c];
    foreach (['disparo' => 'ponto_pedido', 'recomendado' => 'maximo'] as $k => $f) if (isset($in[$k]) && is_numeric($in[$k]) && $in[$k] >= 0) $it[$f] = round((float)$in[$k], 4);
    $it['minimo'] = $it['ponto_pedido'];
    if (isset($in['lead_time_dias']) && is_numeric($in['lead_time_dias'])) $it['lead_time_dias'] = max(1, min(720, (int)$in['lead_time_dias']));
    if (array_key_exists('fabricavel', $in)) $it['fabricavel'] = !empty($in['fabricavel']);
    if (!empty($in['nome'])) $it['descricao'] = est_str($in['nome'], 200);
    if (!empty($in['unidade'])) $it['unidade'] = strtoupper(est_str($in['unidade'], 8));
    if (array_key_exists('localizacao', $in)) $it['localizacao'] = est_str($in['localizacao'], 80);
    if (!empty($in['restaurar_foto'])) unset($it['foto']);
    $it['atualizado_em'] = est_now();
    return $it;
}

/** Foto enviada pelo celular (data URL JPEG/PNG já reduzida no navegador). */
function est_importado_foto(array &$db, array $in) {
    $c = (string)($in['codigo'] ?? '');
    if (empty($db['itens'][$c]['importado'])) est_fail('Item importado não encontrado', 404);
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', (string)($in['imagem'] ?? ''), $m)) est_fail('Imagem inválida');
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024 || !@getimagesizefromstring($bin)) est_fail('Imagem inválida ou maior que 3 MB');
    $dir = DATA_DIR . '/photos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nome = 'imp_' . preg_replace('/[^A-Za-z0-9-]/', '', $c) . '_' . bin2hex(random_bytes(6)) . '.' . ($m[1] === 'png' ? 'png' : ($m[1] === 'webp' ? 'webp' : 'jpg'));
    if (@file_put_contents($dir . '/' . $nome, $bin, LOCK_EX) === false) est_fail('Falha ao gravar a foto', 500);
    $db['itens'][$c]['foto'] = 'data/photos/' . $nome;
    $db['itens'][$c]['atualizado_em'] = est_now();
    return $db['itens'][$c]['foto'];
}


/* ================================================================== */
/* v3.9.1 — Assinatura de quem retira material (comprovante de entrega)  */
/* ================================================================== */
const EST_ASS_DIR = DATA_DIR . '/assinaturas';

/** Valida e grava a assinatura desenhada na tela. Obrigatória em toda saída de material. */
function est_assinatura_salvar(array &$db, array $a, $documento, $usuario) {
    $nome = est_str($a['nome'] ?? '', 120);
    // v3.9.3: quem retira vem do cadastro de funcionários; fora dele, só terceiro identificado.
    $fid = (string)($a['funcionario_id'] ?? '');
    $func = null;
    if ($fid !== '') {
        $func = $db['funcionarios'][$fid] ?? null;
        if (!$func) est_fail('Funcionário não encontrado no cadastro.', 422);
        if (empty($func['ativo'])) est_fail('Funcionário inativo no cadastro: ' . $func['nome'] . '. Fale com o almoxarifado.', 422);
        $nome = $func['nome'];
    }
    if (mb_strlen($nome) < 3) est_fail('Informe o nome completo de quem está retirando o material.', 422);
    if (empty($a['aceite'])) est_fail('Quem retira precisa confirmar o recebimento.', 422);
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string)($a['imagem'] ?? ''), $m)) est_fail('Assinatura obrigatória: assine na tela antes de confirmar.', 422);
    $png = base64_decode($m[1], true);
    $info = $png !== false ? @getimagesizefromstring($png) : false;
    if (!$info || $info[2] !== IMAGETYPE_PNG || strlen($png) > 700 * 1024 || strlen($png) < 900) est_fail('Assinatura inválida ou em branco. Assine novamente.', 422);
    if (!is_dir(EST_ASS_DIR)) { @mkdir(EST_ASS_DIR, 0750, true); @file_put_contents(EST_ASS_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n"); }
    $db['sequencia']['ass'] = (int)($db['sequencia']['ass'] ?? 0) + 1;
    $id = 'ASS-' . date('Y') . '-' . str_pad((string)$db['sequencia']['ass'], 5, '0', STR_PAD_LEFT);
    $arq = $id . '-' . bin2hex(random_bytes(4)) . '.png';
    if (@file_put_contents(EST_ASS_DIR . '/' . $arq, $png, LOCK_EX) === false) est_fail('Não foi possível gravar a assinatura.', 500);
    $reg = ['id' => $id, 'nome' => $nome, 'funcionario_id' => $func ? $fid : null, 'matricula' => $func['matricula'] ?? '',
            'terceiro' => $func === null, 'documento_pessoa' => est_str($a['documento'] ?? '', 40), 'em' => est_now(),
            'referencia' => (string)$documento, 'arquivo' => $arq, 'sha256' => hash('sha256', $png), 'usuario' => est_str($usuario, 120),
            'dispositivo' => est_str($_SERVER['HTTP_USER_AGENT'] ?? '', 160), 'ip' => est_str($_SERVER['REMOTE_ADDR'] ?? '', 45)];
    $db['assinaturas'][$id] = $reg;
    return $reg;
}

/** Comprovante de entrega (HTML pronto para imprimir/salvar em PDF). */
function est_comprovante_html(array $db, $doc) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $n = fn($v) => est_num($v);
    $req = $db['requisicoes'][$doc] ?? null;
    $movs = [];
    if ($req) { $movs = (array)($req['movimentos'] ?? []); $ass = $req['assinatura'] ?? null; }
    else {
        foreach ($db['movimentos'] as $m) if (($m['id'] ?? '') === $doc) $movs = [$m];
        $ass = $movs && !empty($movs[0]['assinatura_id']) ? ($db['assinaturas'][$movs[0]['assinatura_id']] ?? null) : null;
    }
    if (!$movs) return null;
    $img = '';
    if ($ass && is_file(EST_ASS_DIR . '/' . $ass['arquivo'])) $img = 'data:image/png;base64,' . base64_encode((string)file_get_contents(EST_ASS_DIR . '/' . $ass['arquivo']));
    $m0 = $movs[0];
    $linhas = ''; $pcsDoc = [];
    foreach ($movs as $m) {
        $it = $db['itens'][$m['codigo']] ?? [];
        $fp = !empty($m['foto']) ? est_foto_saida_path((string)$m['foto']) : null;
        $fimg = $fp ? '<br><img src="data:' . (mime_content_type($fp) ?: 'image/jpeg') . ';base64,' . base64_encode((string)file_get_contents($fp)) . '" alt="Foto do produto" style="max-width:220px;max-height:160px;border:1px solid #ccc;border-radius:6px;margin-top:6px">' : '';
        if (!empty($m['pc'])) $pcsDoc[(int)$m['pc']] = true;
        $linhas .= '<tr><td>' . $h($m['codigo']) . '</td><td>' . $h($it['descricao'] ?? $m['descricao_origem'] ?? '') . $fimg . '</td><td>' . (!empty($m['pc']) ? 'PC ' . (int)$m['pc'] : '—') . '</td><td style="text-align:right">' . $n($m['quantidade']) . ' ' . $h($it['unidade'] ?? '') . '</td></tr>';
    }
    $destino = $req['setor'] ?? ($m0['obra'] ?? $m0['setor'] ?? '');
    $solic = $req['solicitante'] ?? ($m0['solicitante'] ?? '');
    $fin = $req['finalidade'] ?? ($m0['observacao'] ?? '');
    $osC = $req['os'] ?? ($m0['os'] ?? ''); $eqC = $req['equipamento'] ?? ($m0['equipamento'] ?? '');
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprovante ' . $h($doc) . '</title>
<style>body{font:13px/1.45 Arial,sans-serif;color:#111;max-width:760px;margin:24px auto;padding:0 16px}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse;margin:14px 0}td,th{border:1px solid #bbb;padding:6px 8px;text-align:left}th{background:#eef2f7;font-size:11px;text-transform:uppercase}.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1d4ed8;padding-bottom:10px;margin-bottom:12px}.g{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px}.ass{margin-top:22px;border:1px solid #bbb;border-radius:8px;padding:12px}.ass img{max-width:100%;height:150px;object-fit:contain;display:block;margin:0 auto;border-bottom:1px solid #333}.mut{color:#555;font-size:11px}button{padding:8px 14px;margin-top:14px}@media print{button{display:none}}</style></head><body>
<div class="top"><div><h1>Comprovante de entrega de material</h1><div class="mut">Drilling do Brasil — Almoxarifado · Recebimento Drilling</div></div><div style="text-align:right"><b>' . $h($doc) . '</b><br>' . $h(date('d/m/Y H:i', strtotime($m0['em']))) . '</div></div>
<div class="g"><div><b>Destino / obra:</b> ' . $h($destino ?: '—') . '</div>' . ($pcsDoc ? '<div><b>Pedidos (PC):</b> ' . $h(implode(', ', array_keys($pcsDoc))) . '</div>' : '') . '<div><b>Solicitante:</b> ' . $h($solic ?: '—') . '</div><div><b>Entregue por:</b> ' . $h($m0['usuario'] ?? '') . '</div><div><b>Ordem de serviço:</b> ' . $h($osC ?: '—') . '</div><div><b>Equipamento aplicado:</b> ' . $h($eqC ?: '—') . '</div><div><b>Finalidade / obs.:</b> ' . $h($fin ?: '—') . '</div></div>
<table><thead><tr><th>Código</th><th>Material</th><th>Pedido</th><th style="text-align:right">Quantidade</th></tr></thead><tbody>' . $linhas . '</tbody></table>
<div class="ass"><p>Declaro que recebi os materiais acima, em perfeito estado e nas quantidades indicadas.</p>' . ($img ? '<img src="' . $img . '" alt="Assinatura">' : '<p><b>Sem assinatura registrada</b> (saída anterior à exigência de assinatura).</p>') .
($ass ? '<p style="text-align:center;margin:6px 0 0"><b>' . $h($ass['nome']) . '</b>' . ($ass['documento_pessoa'] ? ' · ' . $h($ass['documento_pessoa']) : '') . '</p><p class="mut" style="text-align:center">Assinado em ' . $h(date('d/m/Y H:i:s', strtotime($ass['em']))) . ' (horário do servidor) · ' . $h($ass['id']) . '<br>Código de verificação SHA-256: ' . $h(substr($ass['sha256'], 0, 32)) . '…</p>' : '') . '</div>
<button onclick="print()">Imprimir / salvar em PDF</button></body></html>';
}


/* ================================================================== */
/* v3.9.3 — Cadastro de funcionários autorizados a retirar material      */
/* ================================================================== */
function est_func_nome($v) { return mb_strtoupper(trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', (string)$v))), 'UTF-8'); }
function est_func_chave($v) { return est_norm_key(est_func_nome($v)); }

function est_funcionarios_lista(array $db) {
    $ret = [];
    foreach ($db['assinaturas'] ?? [] as $a) {
        $k = $a['funcionario_id'] ?? '';
        if ($k === '') continue;
        $ret[$k]['qtd'] = ($ret[$k]['qtd'] ?? 0) + 1;
        if (($ret[$k]['ultima'] ?? '') < $a['em']) $ret[$k]['ultima'] = $a['em'];
    }
    $out = [];
    foreach ($db['funcionarios'] ?? [] as $id => $f) $out[] = $f + ['retiradas' => $ret[$id]['qtd'] ?? 0, 'ultima_retirada' => $ret[$id]['ultima'] ?? null];
    usort($out, fn($a, $b) => [!$a['ativo'], $a['nome']] <=> [!$b['ativo'], $b['nome']]);
    $terceiros = 0;
    foreach ($db['assinaturas'] ?? [] as $a) if (!empty($a['terceiro'])) $terceiros++;
    return ['funcionarios' => $out, 'retiradas_terceiros' => $terceiros, 'importacao' => $db['funcionarios_importacao'] ?? null];
}

function est_funcionario_salvar(array &$db, array $in, $usuario) {
    $nome = est_func_nome($in['nome'] ?? '');
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) est_fail('Informe nome e sobrenome.', 422);
    $id = (string)($in['id'] ?? '');
    foreach ($db['funcionarios'] ?? [] as $k => $f) if ($k !== $id && est_func_chave($f['nome']) === est_func_chave($nome)) est_fail('Já existe um funcionário com este nome.', 409);
    $now = est_now();
    if ($id === '' || !isset($db['funcionarios'][$id])) {
        $db['sequencia']['func'] = (int)($db['sequencia']['func'] ?? 0) + 1;
        $id = 'FUN-' . str_pad((string)$db['sequencia']['func'], 4, '0', STR_PAD_LEFT);
        $db['funcionarios'][$id] = ['id' => $id, 'criado_em' => $now, 'criado_por' => $usuario, 'origem' => 'manual'];
    }
    $f = &$db['funcionarios'][$id];
    $f['nome'] = $nome;
    $f['matricula'] = est_str($in['matricula'] ?? ($f['matricula'] ?? ''), 30);
    $f['setor'] = est_str($in['setor'] ?? ($f['setor'] ?? ''), 80);
    $f['funcao'] = est_str($in['funcao'] ?? ($f['funcao'] ?? ''), 80);
    $f['ativo'] = array_key_exists('ativo', $in) ? !empty($in['ativo']) : ($f['ativo'] ?? true);
    // v3.28.0: e-mail para avisar quando o pedido que a pessoa solicitou chegar no almoxarifado.
    if (array_key_exists('email', $in)) {
        $em = trim((string)$in['email']);
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) est_fail('E-mail inválido.', 422);
        $f['email'] = $em;
    }
    if (array_key_exists('apelidos', $in)) $f['apelidos'] = est_str($in['apelidos'], 160);
    $now = est_now();
    $f['atualizado_em'] = $now; $f['atualizado_por'] = $usuario;
    $r = $f; unset($f);
    return $r;
}

/** Importa a planilha "Funcionários depósito" (aba Ativos). Mantém quem já existe; opcionalmente inativa quem saiu da lista. */
function est_funcionarios_importar(array &$db, array $in, $usuario, $aplicar) {
    $nomes = [];
    foreach ((array)($in['nomes'] ?? []) as $n) {
        $n = est_func_nome($n);
        if (mb_strlen($n) < 5 || !str_contains($n, ' ') || preg_match('/^NOME DO|EMPREGADO$/u', $n)) continue;
        $nomes[est_func_chave($n)] = $n;
    }
    if (!$nomes) est_fail('Nenhum nome encontrado na planilha.', 422);
    $porChave = [];
    foreach ($db['funcionarios'] ?? [] as $id => $f) $porChave[est_func_chave($f['nome'])] = $id;
    $novos = array_values(array_diff_key($nomes, $porChave));
    $reativar = []; $inativar = [];
    foreach ($porChave as $k => $id) {
        $f = $db['funcionarios'][$id];
        if (isset($nomes[$k]) && empty($f['ativo'])) $reativar[] = $f['nome'];
        if (!isset($nomes[$k]) && !empty($f['ativo']) && ($f['origem'] ?? '') === 'planilha') $inativar[] = $f['nome'];
    }
    $res = ['na_planilha' => count($nomes), 'novos' => $novos, 'ja_cadastrados' => count($nomes) - count($novos), 'reativar' => $reativar,
            'inativar' => $inativar, 'data_lista' => est_str($in['data_lista'] ?? '', 10)];
    if (!$aplicar) return $res;
    $now = est_now();
    foreach ($novos as $n) {
        $db['sequencia']['func'] = (int)($db['sequencia']['func'] ?? 0) + 1;
        $id = 'FUN-' . str_pad((string)$db['sequencia']['func'], 4, '0', STR_PAD_LEFT);
        $db['funcionarios'][$id] = ['id' => $id, 'nome' => $n, 'matricula' => '', 'setor' => 'Depósito', 'funcao' => '', 'ativo' => true,
            'origem' => 'planilha', 'criado_em' => $now, 'criado_por' => $usuario];
    }
    foreach ($db['funcionarios'] as $id => &$f) {
        $k = est_func_chave($f['nome']);
        if (isset($nomes[$k]) && empty($f['ativo'])) { $f['ativo'] = true; $f['atualizado_em'] = $now; }
        if (!empty($in['inativar_ausentes']) && !isset($nomes[$k]) && !empty($f['ativo']) && ($f['origem'] ?? '') === 'planilha') { $f['ativo'] = false; $f['atualizado_em'] = $now; $f['inativado_por_lista'] = $res['data_lista'] ?: substr($now, 0, 10); }
    }
    unset($f);
    $db['funcionarios_importacao'] = ['em' => $now, 'usuario' => $usuario, 'arquivo' => est_str($in['arquivo'] ?? '', 160), 'data_lista' => $res['data_lista'], 'nomes' => count($nomes)];
    return $res;
}

/** Retiradas assinadas de um funcionário (para a ficha). */
function est_funcionario_retiradas(array $db, $fid) {
    $ass = [];
    foreach ($db['assinaturas'] ?? [] as $a) if (($a['funcionario_id'] ?? '') === $fid) $ass[$a['id']] = $a;
    $out = [];
    foreach ($db['movimentos'] as $m) {
        if (empty($m['assinatura_id']) || !isset($ass[$m['assinatura_id']])) continue;
        $it = $db['itens'][$m['codigo']] ?? [];
        $out[] = ['em' => $m['em'], 'mov' => $m['id'], 'documento' => $m['documento'] ?? '', 'codigo' => $m['codigo'], 'descricao' => $it['descricao'] ?? '',
                  'quantidade' => $m['quantidade'], 'unidade' => $it['unidade'] ?? '', 'destino' => $m['obra'] ?? ($m['setor'] ?? '')];
    }
    return array_reverse(array_slice($out, -300));
}


/* ================================================================== */
/* v3.28.0 — Edição/exclusão de movimentações dos importados (auditada)  */
/* ================================================================== */
const EST_IMP_ENTRADAS = ['ENTRADA_MANUAL', 'AJUSTE_POSITIVO', 'ENTRADA_RECEBIMENTO', 'ESTORNO_SAIDA'];
const EST_IMP_SAIDAS = ['SAIDA', 'AJUSTE_NEGATIVO', 'BAIXA_PERDA', 'ESTORNO_ENTRADA'];

/** Grava o efeito original (delta) dos ajustes absolutos, para que editar um movimento anterior
 *  propague corretamente até o saldo atual. */
function est_fixar_deltas(array &$db, $codigo) {
    foreach ($db['movimentos'] as &$m) {
        if (($m['codigo'] ?? '') !== $codigo || isset($m['delta_fixo'])) continue;
        if (in_array($m['tipo'], ['AJUSTE_INVENTARIO', 'INVENTARIO_SAP'], true)) $m['delta_fixo'] = round((float)$m['saldo_posterior'] - (float)$m['saldo_anterior'], 4);
    }
    unset($m);
}

/** Recalcula saldo anterior/posterior de todos os movimentos do item, na ordem em que foram lançados. */
function est_recalcular_item(array &$db, $codigo, $minAntes = 0.0) {
    $saldo = null; $negativo = null;
    $limite = min(0.0, (float)$minAntes) - 0.00001;   // só bloqueia se piorar o que já existia (histórico da planilha)
    foreach ($db['movimentos'] as &$m) {
        if (($m['codigo'] ?? '') !== $codigo) continue;
        if ($saldo === null) $saldo = (float)$m['saldo_anterior'];      // base (movimentos arquivados vêm antes)
        $antes = $saldo;
        if (empty($m['cancelado'])) {
            $q = (float)$m['quantidade'];
            if (in_array($m['tipo'], EST_IMP_ENTRADAS, true)) $saldo += $q;
            elseif (in_array($m['tipo'], EST_IMP_SAIDAS, true)) $saldo -= $q;
            elseif (isset($m['delta_fixo'])) $saldo += (float)$m['delta_fixo'];
        }
        $m['saldo_anterior'] = round($antes, 4);
        $m['saldo_posterior'] = round($saldo, 4);
        if ($saldo < $limite && $negativo === null) $negativo = $m;
    }
    unset($m);
    if ($negativo) est_fail('A alteração deixaria o saldo negativo em ' . substr((string)$negativo['em'], 0, 10) . ' (' . est_num($negativo['saldo_posterior']) . '). Revise as quantidades.', 409);
    if ($saldo !== null) { $db['itens'][$codigo]['saldo'] = round($saldo, 4); $db['itens'][$codigo]['atualizado_em'] = est_now(); }
}

function est_importado_mov_editar(array &$db, array $in, $usuario) {
    $id = (string)($in['id'] ?? '');
    $k = null;
    foreach ($db['movimentos'] as $i => $m) if (($m['id'] ?? '') === $id) { $k = $i; break; }
    if ($k === null) est_fail('Movimentação não encontrada.', 404);
    $m = $db['movimentos'][$k];
    $c = $m['codigo'];
    if (empty($db['itens'][$c]['importado'])) est_fail('Só movimentações de itens importados podem ser editadas aqui.', 422);
    if (!empty($m['cancelado'])) est_fail('Movimentação já excluída.', 409);
    $motivo = est_str($in['motivo'] ?? '', 300);
    if (mb_strlen($motivo) < 3) est_fail('Informe o motivo da alteração.');
    $acao = (string)($in['acao'] ?? 'editar');
    est_fixar_deltas($db, $c);
    $minAntes = 0.0;
    foreach ($db['movimentos'] as $mm) if (($mm['codigo'] ?? '') === $c && empty($mm['cancelado'])) $minAntes = min($minAntes, (float)$mm['saldo_posterior']);
    $mov = &$db['movimentos'][$k];
    $antes = array_intersect_key($mov, array_flip(['em', 'quantidade', 'obra', 'solicitante', 'observacao', 'situacao']));
    if ($acao === 'excluir') {
        $mov['cancelado'] = true; $mov['cancelado_em'] = est_now(); $mov['cancelado_por'] = $usuario; $mov['cancelado_motivo'] = $motivo;
    } else {
        if (!empty($mov['assinatura_id'])) est_fail('Saída assinada não pode ser alterada (o comprovante é um documento). Exclua e lance de novo com nova assinatura.', 409);
        if (in_array($mov['tipo'], ['AJUSTE_INVENTARIO', 'INVENTARIO_SAP'], true)) {
            if (isset($in['quantidade']) || isset($in['data'])) est_fail('Ajuste de saldo/contagem: só a observação pode ser editada. Para mudar o valor, exclua e faça nova contagem.', 422);
        } else {
            if (isset($in['quantidade'])) {
                $q = round((float)$in['quantidade'], 4);
                if (!is_numeric($in['quantidade']) || $q <= 0 || $q > 1000000) est_fail('Quantidade inválida.');
                $mov['quantidade'] = $q;
            }
            if (!empty($in['data'])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['data']) || $in['data'] > date('Y-m-d')) est_fail('Data inválida (não pode ser futura).');
                $hora = substr((string)$mov['em'], 10) ?: 'T12:00:00-03:00';
                $mov['em'] = $in['data'] . $hora;
            }
            if (array_key_exists('obra', $in)) $mov['obra'] = $mov['setor'] = est_imp_obra($in['obra']);
            if (array_key_exists('solicitante', $in)) $mov['solicitante'] = est_str($in['solicitante'], 80);
            if (!empty($in['situacao'])) $mov['situacao'] = est_imp_nome($in['situacao']);
        }
        if (array_key_exists('observacao', $in)) $mov['observacao'] = est_str($in['observacao'], 300);
    }
    $mov['edicoes'][] = ['em' => est_now(), 'por' => $usuario, 'acao' => $acao, 'motivo' => $motivo, 'antes' => $antes];
    unset($mov);
    est_recalcular_item($db, $c, $minAntes);
    return $db['movimentos'][$k];
}


/* ================================================================== */
/* v3.28.0 — SKU único: um item de estoque por SKU                       */
/* ================================================================== */
/** SKUs encontrados nos ids de origem de um item (sku_ma002784, sku_MA002784_2 …). */
function est_skus_do_item(array $it) {
    $out = [];
    foreach ((array)($it['origem_ids'] ?? []) as $o) { $s = est_sap_code($o); if ($s !== '') $out[$s] = true; }
    return array_keys($out);
}

/**
 * Garante SKU único no estoque: item com código provisório (EST-…) que tem SKU passa a usar
 * o SKU como código; se já existir item com aquele SKU, os dois são unificados (movimentos,
 * reservas e entradas passam para o SKU; saldo recalculado). Nada é apagado do histórico.
 */
function est_unificar_sku(array &$db, $usuario = 'sistema') {
    $feitos = [];
    foreach (array_keys($db['itens']) as $cod) {
        if (!isset($db['itens'][$cod]) || est_sap_code($cod) !== '') continue;
        $skus = est_skus_do_item($db['itens'][$cod]);
        if (count($skus) !== 1) continue;                       // sem SKU ou ambíguo: não mexe
        $sku = $skus[0];
        if (!isset($db['itens'][$sku])) {
            est_renomear_item($db, $cod, $sku);
            $feitos[] = ['de' => $cod, 'para' => $sku, 'acao' => 'renomeado'];
            continue;
        }
        // unificação: o item com código SKU recebe tudo do item provisório
        est_fixar_deltas($db, $cod); est_fixar_deltas($db, $sku);
        $velho = $db['itens'][$cod];
        $alvo = &$db['itens'][$sku];
        $alvo['aliases'] = array_values(array_unique(array_merge((array)($alvo['aliases'] ?? []), (array)($velho['aliases'] ?? []), [$cod, $velho['descricao']])));
        $alvo['aliases'] = array_slice(array_filter($alvo['aliases'], fn($a) => $a !== $alvo['descricao']), 0, 40);
        $alvo['origem_ids'] = array_values(array_unique(array_merge((array)($alvo['origem_ids'] ?? []), (array)($velho['origem_ids'] ?? []))));
        foreach (['minimo', 'maximo', 'ponto_pedido'] as $k) if (empty($alvo[$k]) && !empty($velho[$k])) $alvo[$k] = $velho[$k];
        if (empty($alvo['localizacao']) && !empty($velho['localizacao'])) $alvo['localizacao'] = $velho['localizacao'];
        $alvo['reservado'] = (float)($alvo['reservado'] ?? 0) + (float)($velho['reservado'] ?? 0);
        unset($alvo);
        foreach ($db['movimentos'] as &$m) if (($m['codigo'] ?? '') === $cod) { $m['codigo'] = $sku; $m['codigo_anterior'] = $cod; }
        unset($m);
        foreach ($db['recebimentos'] as &$doc) foreach ($doc['linhas'] as &$l) if (($l['codigo'] ?? '') === $cod) $l['codigo'] = $sku;
        unset($doc, $l);
        foreach ($db['reservas'] as &$r) if (($r['codigo'] ?? '') === $cod) $r['codigo'] = $sku;
        unset($r);
        unset($db['itens'][$cod]);
        $GLOBALS['RD_EST_UNIFICANDO'] = true;
        try { est_recalcular_item($db, $sku, -1e12); } finally { $GLOBALS['RD_EST_UNIFICANDO'] = false; }
        $feitos[] = ['de' => $cod, 'para' => $sku, 'acao' => 'unificado'];
    }
    if ($feitos) $db['unificacoes_sku'][] = ['em' => est_now(), 'usuario' => $usuario, 'itens' => $feitos];
    return $feitos;
}

/** Relatório de SKUs: itens de estoque × linhas de pedido (para a aba Integridade). */
function est_relatorio_sku(array $db, array $pedidos) {
    $desc = [];
    foreach ($pedidos as $p) foreach ((array)($p['insumos'] ?? []) as $l) {
        $s = est_sap_code($l['id'] ?? '');
        if ($s === '') continue;
        $desc[$s][mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string)$l['descricao'])))][] = (int)$p['pc'];
    }
    $varias = [];
    foreach ($desc as $s => $d) if (count($d) > 1) $varias[] = ['sku' => $s, 'descricoes' => array_map(fn($k, $v) => ['descricao' => $k, 'pcs' => array_values(array_unique($v))], array_keys($d), $d)];
    $provisorios = [];
    foreach ($db['itens'] as $c => $it) if (est_sap_code($c) === '' && est_skus_do_item($it)) $provisorios[] = ['codigo' => $c, 'skus' => est_skus_do_item($it)];
    return ['skus_em_pedidos' => count($desc), 'skus_com_varias_descricoes' => $varias, 'itens_com_codigo_provisorio' => $provisorios];
}

/* ================================================================== */
/* v3.28.0 — Dashboard analítico do estoque                             */
/* ================================================================== */
function est_familia($codigo, array $it = []) {
    if (strtoupper((string)($it['categoria'] ?? '')) === 'IMPORTADOS') return 'Importados';
    $nomes = ['MA' => 'Materiais (MA)', 'UC' => 'Uniformes/EPI (UC)', 'CO' => 'Consumo (CO)', 'MP' => 'Matéria-prima (MP)',
              'SC' => 'Serviços/Comp. (SC)', 'FE' => 'Ferramentas (FE)', 'SP' => 'Peças (SP)'];
    $p = strtoupper(substr((string)$codigo, 0, 2));
    if (preg_match('/^[A-Z]{2}\d{6}$/', strtoupper((string)$codigo))) return $nomes[$p] ?? ('Família ' . $p);
    return 'Sem SKU';
}

function est_dashboard_analise(array $db, $dias = 90) {
    $dias = max(7, min(730, (int)$dias));
    $agora = time(); $ini = $agora - $dias * 86400;
    $itens = $db['itens'] ?? [];
    $ENT = ['ENTRADA_RECEBIMENTO', 'ENTRADA_MANUAL', 'AJUSTE_POSITIVO', 'TRANSFERENCIA_ENTRADA'];
    $SAI = ['SAIDA', 'BAIXA_PERDA', 'AJUSTE_NEGATIVO', 'TRANSFERENCIA_SAIDA'];

    // ---- composição atual ----
    $fam = []; $origem = ['inventario' => 0.0, 'pedidos' => 0.0, 'inventario+pedidos' => 0.0];
    $topValor = []; $valorTotal = 0.0; $comSaldo = 0; $zerados = 0; $negativos = 0; $semCusto = 0; $semMin = 0;
    $impValor = 0.0; $impItens = 0; $reservado = 0.0;
    foreach ($itens as $c => $it) {
        $v = est_item_view($it); $val = max(0, $v['valor_estoque']);
        $valorTotal += $val;
        if ($v['saldo'] > 0) $comSaldo++; elseif ($v['saldo'] < 0) $negativos++; else $zerados++;
        if ($v['saldo'] > 0 && (float)($it['custo_medio'] ?? 0) <= 0) $semCusto++;
        if ($v['saldo'] > 0 && $v['minimo'] <= 0) $semMin++;
        $reservado += (float)($it['reservado'] ?? 0) * (float)($it['custo_medio'] ?? 0);
        $f = est_familia($c, $it);
        $fam[$f] = $fam[$f] ?? ['familia' => $f, 'itens' => 0, 'valor' => 0.0];
        $fam[$f]['itens']++; $fam[$f]['valor'] += $val;
        $origem[$v['origem_saldo']] = ($origem[$v['origem_saldo']] ?? 0) + $val;
        if ($f === 'Importados') { $impValor += $val; $impItens++; }
        if ($val > 0) $topValor[] = ['codigo' => $c, 'descricao' => $it['descricao'], 'unidade' => $it['unidade'] ?? 'UN', 'saldo' => $v['saldo'], 'valor' => $val];
    }
    usort($topValor, fn($a, $b) => $b['valor'] <=> $a['valor']);
    $fam = array_values($fam); usort($fam, fn($a, $b) => $b['valor'] <=> $a['valor']);

    // ---- movimentos ----
    $meses = [];
    for ($i = 11; $i >= 0; $i--) { $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $meses[$k] = ['mes' => $k, 'entradas' => 0.0, 'saidas' => 0.0, 'inventario' => 0.0, 'qtd_mov' => 0]; }
    $consumo = []; $obras = []; $pessoas = []; $ultimoMov = []; $tipos = [];
    $entPer = 0.0; $saiPer = 0.0; $nMovPer = 0; $diasAtivos = [];
    foreach (($db['movimentos'] ?? []) as $m) {
        $c = $m['codigo'] ?? ''; $t = strtotime((string)($m['em'] ?? '')) ?: 0;
        $custo = (float)($itens[$c]['custo_medio'] ?? 0);
        $q = abs((float)($m['quantidade'] ?? 0)); $val = $q * $custo;
        $tipo = (string)($m['tipo'] ?? '');
        // v3.52.0: carga/ajuste de inventário NÃO é movimento do item — antes toda importação SAP "mexia" nos 422 itens e o capital parado dava sempre 0.
        if ($t && !in_array($tipo, ['INVENTARIO_SAP', 'AJUSTE_INVENTARIO'], true) && (!isset($ultimoMov[$c]) || $t > $ultimoMov[$c])) $ultimoMov[$c] = $t;
        $mk = $t ? date('Y-m', $t) : '';
        if (isset($meses[$mk])) {
            $meses[$mk]['qtd_mov']++;
            if (in_array($tipo, $ENT, true)) $meses[$mk]['entradas'] += $val;
            elseif (in_array($tipo, $SAI, true)) $meses[$mk]['saidas'] += $val;
            elseif ($tipo === 'INVENTARIO_SAP' || $tipo === 'AJUSTE_INVENTARIO') $meses[$mk]['inventario'] += $val;
        }
        if ($t < $ini) continue;
        $nMovPer++; $tipos[$tipo] = ($tipos[$tipo] ?? 0) + 1; $diasAtivos[date('Y-m-d', $t)] = true;
        if (in_array($tipo, $ENT, true)) $entPer += $val;
        if ($tipo === 'SAIDA' || $tipo === 'BAIXA_PERDA') {
            $saiPer += $val;
            $consumo[$c] = $consumo[$c] ?? ['codigo' => $c, 'descricao' => $itens[$c]['descricao'] ?? $c, 'unidade' => $itens[$c]['unidade'] ?? 'UN', 'quantidade' => 0.0, 'valor' => 0.0, 'saidas' => 0];
            $consumo[$c]['quantidade'] += $q; $consumo[$c]['valor'] += $val; $consumo[$c]['saidas']++;
            $o = trim((string)($m['obra'] ?? $m['setor'] ?? '')) ?: 'Sem obra/setor';
            $o = mb_strtoupper($o);
            $obras[$o] = $obras[$o] ?? ['obra' => $o, 'valor' => 0.0, 'saidas' => 0];
            $obras[$o]['valor'] += $val; $obras[$o]['saidas']++;
            $p = trim((string)($m['funcionario'] ?? $m['retirado_por'] ?? $m['solicitante'] ?? '')) ?: 'Não informado';
            $p = mb_strtoupper($p);
            $pessoas[$p] = $pessoas[$p] ?? ['nome' => $p, 'valor' => 0.0, 'saidas' => 0];
            $pessoas[$p]['valor'] += $val; $pessoas[$p]['saidas']++;
        }
    }
    $ord = function (array $a, $k = 'valor', $n = 10) { $a = array_values($a); usort($a, fn($x, $y) => $y[$k] <=> $x[$k]); return array_slice($a, 0, $n); };

    // ---- cobertura (dias até zerar pelo consumo do período) ----
    $cobertura = [];
    foreach ($consumo as $c => $x) {
        $saldo = (float)($itens[$c]['saldo'] ?? 0);
        $porDia = $x['quantidade'] / $dias;
        if ($porDia <= 0) continue;
        $d = $saldo > 0 ? $saldo / $porDia : 0;
        if ($d <= 45) $cobertura[] = ['codigo' => $c, 'descricao' => $x['descricao'], 'unidade' => $x['unidade'], 'saldo' => round($saldo, 4), 'consumo_dia' => round($porDia, 3), 'dias' => round($d, 1)];
    }
    usort($cobertura, fn($a, $b) => $a['dias'] <=> $b['dias']);

    // ---- itens parados (com saldo e sem movimento há > 180 dias) ----
    $parados = []; $paradoValor = 0.0; $lim = $agora - 180 * 86400;
    foreach ($itens as $c => $it) {
        if ((float)$it['saldo'] <= 0) continue;
        $u = $ultimoMov[$c] ?? (strtotime((string)($it['criado_em'] ?? '')) ?: 0);
        if ($u && $u > $lim) continue;
        $val = (float)$it['saldo'] * (float)($it['custo_medio'] ?? 0);
        $paradoValor += $val;
        $parados[] = ['codigo' => $c, 'descricao' => $it['descricao'], 'saldo' => (float)$it['saldo'], 'unidade' => $it['unidade'] ?? 'UN', 'valor' => round($val, 2), 'ultimo' => $u ? date('c', $u) : null];
    }
    usort($parados, fn($a, $b) => $b['valor'] <=> $a['valor']);

    // ---- curva ABC por valor de consumo ----
    $abc = ['A' => ['itens' => 0, 'valor' => 0.0], 'B' => ['itens' => 0, 'valor' => 0.0], 'C' => ['itens' => 0, 'valor' => 0.0]];
    $cons = array_values($consumo); usort($cons, fn($a, $b) => $b['valor'] <=> $a['valor']);
    $tot = array_sum(array_column($cons, 'valor')); $acc = 0.0;
    foreach ($cons as $x) { $acc += $x['valor']; $cl = $tot <= 0 ? 'C' : ($acc / $tot <= 0.8 ? 'A' : ($acc / $tot <= 0.95 ? 'B' : 'C')); $abc[$cl]['itens']++; $abc[$cl]['valor'] += $x['valor']; }

    // ---- outros módulos ----
    $reqAbertas = count(array_filter($db['requisicoes'] ?? [], fn($r) => in_array($r['status'] ?? '', ['rascunho', 'aberta', 'pendente'], true)));
    $resAbertas = count(array_filter($db['reservas'] ?? [], fn($r) => ($r['status'] ?? 'ativa') === 'ativa'));
    $invAbertos = count(array_filter($db['inventarios'] ?? [], fn($r) => ($r['status'] ?? '') !== 'aplicado' && ($r['status'] ?? '') !== 'cancelado'));
    $repor = 0; foreach ($itens as $it) if (est_item_view($it)['repor']) $repor++;

    $giro = $valorTotal > 0 ? ($saiPer / $valorTotal) * (365 / $dias) : 0;
    return [
        'periodo_dias' => $dias, 'gerado_em' => date('c'),
        'kpis' => [
            'valor_total' => round($valorTotal, 2), 'itens_total' => count($itens), 'itens_com_saldo' => $comSaldo,
            'itens_zerados' => $zerados, 'itens_negativos' => $negativos, 'itens_sem_custo' => $semCusto, 'itens_sem_minimo' => $semMin,
            'itens_repor' => $repor, 'valor_entradas' => round($entPer, 2), 'valor_saidas' => round($saiPer, 2),
            'movimentos' => $nMovPer, 'dias_ativos' => count($diasAtivos), 'giro_anual' => round($giro, 2),
            'cobertura_media_dias' => $saiPer > 0 ? round($valorTotal / ($saiPer / $dias)) : null,
            'valor_parado' => round($paradoValor, 2), 'itens_parados' => count($parados),
            'importados_valor' => round($impValor, 2), 'importados_itens' => $impItens, 'valor_reservado' => round($reservado, 2),
            'requisicoes_abertas' => $reqAbertas, 'reservas_ativas' => $resAbertas, 'inventarios_abertos' => $invAbertos,
        ],
        'mensal' => array_values(array_map(fn($m) => ['mes' => $m['mes'], 'entradas' => round($m['entradas'], 2), 'saidas' => round($m['saidas'], 2), 'inventario' => round($m['inventario'], 2), 'qtd_mov' => $m['qtd_mov']], $meses)),
        'familias' => array_map(fn($f) => ['familia' => $f['familia'], 'itens' => $f['itens'], 'valor' => round($f['valor'], 2)], $fam),
        'origem' => array_map(fn($v) => round($v, 2), $origem),
        'top_valor' => array_slice($topValor, 0, 10),
        'top_consumo' => $ord($consumo),
        'obras' => $ord($obras, 'valor', 12),
        'pessoas' => $ord($pessoas, 'saidas', 10),
        'cobertura' => array_slice($cobertura, 0, 12),
        'parados' => array_slice($parados, 0, 10),
        'abc' => array_map(fn($x) => ['itens' => $x['itens'], 'valor' => round($x['valor'], 2)], $abc),
        'tipos' => $tipos,
    ];
}


/* ================================================================== */
/* v3.28.0 — Foto do produto na saída de material                        */
/* ================================================================== */
const EST_FOTO_SAIDA_DIR = DATA_DIR . '/estoque/fotos_saida';

/** Grava a foto (JPEG/PNG/WEBP até 3 MB) e devolve o nome do arquivo. */
function est_foto_saida_salvar(string $dataUrl, string $doc, int $linha): string {
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) est_fail('Foto do produto inválida (linha ' . $linha . '). Tire a foto novamente.', 422);
    $bin = base64_decode($m[2], true);
    $info = $bin !== false ? @getimagesizefromstring($bin) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || strlen($bin) > 3 * 1024 * 1024)
        est_fail('Foto do produto inválida ou maior que 3 MB (linha ' . $linha . ').', 422);
    if (!is_dir(EST_FOTO_SAIDA_DIR)) { @mkdir(EST_FOTO_SAIDA_DIR, 0750, true); @file_put_contents(EST_FOTO_SAIDA_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n"); }
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $arq = preg_replace('/[^A-Za-z0-9-]/', '', $doc) . '-L' . $linha . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@file_put_contents(EST_FOTO_SAIDA_DIR . '/' . $arq, $bin, LOCK_EX) === false) est_fail('Não foi possível gravar a foto do produto.', 500);
    return $arq;
}
function est_foto_saida_path(string $arq): ?string {
    if (!preg_match('/^[A-Za-z0-9-]+-L\d+-[0-9a-f]{8}\.(jpg|png|webp)$/', $arq)) return null;
    $p = EST_FOTO_SAIDA_DIR . '/' . $arq;
    return is_file($p) ? $p : null;
}


/** v3.28.0: número da OS normalizado ("os 1234" → "1234"). */
function est_os($v) {
    $v = trim(preg_replace('/^\s*O\.?\s*S\.?\s*(N[º°o.]?\s*)?[:#-]?\s*/iu', '', (string)$v));
    return mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $v), 0, 40));
}
/** Sugestões de equipamento: já usados em saídas + ferramentas (patrimônio) + equipamentos locados. */
function est_equipamentos_sugestoes(array $db) {
    $out = [];
    foreach (array_reverse($db['movimentos'] ?? []) as $m) if (!empty($m['equipamento'])) $out[$m['equipamento']] = ($out[$m['equipamento']] ?? 0) + 1;
    $j = fn($f) => json_decode((string)@file_get_contents(DATA_DIR . '/' . $f), true) ?: [];
    foreach ((array)($j('ferramentas.json')['ferramentas'] ?? []) as $f) if (is_array($f) && !empty($f['nome']) && ($f['ativo'] ?? true)) {
        $k = trim($f['nome']) . (!empty($f['patrimonio']) && stripos($f['nome'], (string)$f['patrimonio']) === false ? ' · PAT ' . $f['patrimonio'] : ''); $out[$k] = $out[$k] ?? 0;
    }
    foreach ((array)($j('locacoes.json')['locacoes'] ?? []) as $l) if (is_array($l) && !empty($l['equipamento']) && ($l['status'] ?? '') !== 'DEVOLVIDO') {
        $k = trim($l['equipamento']) . (!empty($l['patrimonio']) ? ' · PAT ' . $l['patrimonio'] : '') . ' (locado)'; $out[$k] = $out[$k] ?? 0;
    }
    arsort($out);
    return array_slice(array_keys($out), 0, 600);
}

/** v3.32.0: a OS NUNCA é obrigatória na saída (nem por setor). Mantida só por compatibilidade com telas/rotas antigas. */
function est_os_setores(array $db) { return []; }
function est_os_setores_salvar(array &$db, array $in) { $db['config']['os_setores'] = []; return []; }


/* ------------------------------------------------------------------ */
/* v3.30.0 — Saída por nome, SKU ou número do PC, com foto automática   */
/* ------------------------------------------------------------------ */

/** Índice dos pedidos (lido uma vez por requisição): pc → fotos do recebimento + dados de destino. */
function est_pedidos_idx(): array {
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    if (!function_exists('read_items')) return $idx;
    foreach (read_items() as $p) {
        $fotos = [];
        foreach ((array)($p['fotosProdutos'] ?? []) as $f) if (is_string($f) && ($r = est_foto_rel_ok($f)) !== '') $fotos[] = $r;
        $idx[(int)$p['pc']] = ['fotos' => $fotos, 'fornecedor' => (string)($p['fornecedor'] ?? ''), 'solicitante' => (string)($p['solicitante'] ?? ''),
            'urgente' => ($p['urgente'] ?? '') === '1', 'destino_setor' => (string)($p['destino_setor'] ?? ''), 'obra_id' => (string)($p['obra_id'] ?? ''),
            'obra' => (string)($p['obra'] ?? ''), 'numero_nf' => (string)($p['numeroNF'] ?? ''), 'situacao' => (string)($p['situacao'] ?? ''),
            'os' => (string)($p['os_pc'] ?? ''), 'equipamento' => (string)($p['equipamento_pc'] ?? ''), 'os_origem' => (string)($p['os_origem'] ?? ''),
            'finalizado' => (($p['recebimento_estado'] ?? '') === 'finalizado'), 'n_insumos' => count((array)($p['insumos'] ?? []))];
    }
    return $idx;
}
/** Só caminhos de foto conhecidos e existentes (cadastro do item ou recebimento do PC). */
function est_foto_rel_ok(string $rel): string {
    $rel = trim($rel);
    if (!preg_match('~^(data/photos|assets/importados)/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$~i', $rel)) return '';
    return is_file(dirname(DATA_DIR) . '/' . $rel) ? $rel : '';
}
/** Foto de cadastro do item (a trocada pelo usuário ou a padrão do catálogo). */
function est_foto_do_item(array $it): string {
    foreach (['foto', 'foto_padrao'] as $k) { $r = est_foto_rel_ok((string)($it[$k] ?? '')); if ($r !== '') return $r; }
    return '';
}
/**
 * Foto sugerida para a saída: 1º a de cadastro do item; 2º a do recebimento (do PC informado ou, sem PC,
 * do último PC que deu entrada neste item). Retorna ['rel'=>'', 'origem'=>'cadastro|recebimento', 'pc'=>n].
 */
function est_foto_auto(array $db, string $codigo, int $pc = 0): array {
    $vazio = ['rel' => '', 'origem' => '', 'pc' => 0, 'fotos' => []];
    $it = $db['itens'][$codigo] ?? null;
    if (!$it) return $vazio;
    $cad = est_foto_do_item($it);
    $idx = est_pedidos_idx();
    if ($pc > 0) {   // v3.44.0: saída por PC — a foto do RECEBIMENTO deste PC vem primeiro; a de cadastro só se o PC não tiver foto
        $f = $idx[$pc]['fotos'] ?? [];
        if ($f) return ['rel' => $f[0], 'origem' => 'recebimento', 'pc' => $pc, 'fotos' => $f];
        return $cad !== '' ? ['rel' => $cad, 'origem' => 'cadastro', 'pc' => 0, 'fotos' => []] : $vazio;
    }
    if ($cad !== '') return ['rel' => $cad, 'origem' => 'cadastro', 'pc' => 0, 'fotos' => []];
    $n = 0;
    foreach (array_reverse($db['movimentos'] ?? []) as $m) {
        if (($m['tipo'] ?? '') !== 'ENTRADA_RECEBIMENTO' || ($m['codigo'] ?? '') !== $codigo || (int)($m['pc'] ?? 0) <= 0) continue;
        $f = $idx[(int)$m['pc']]['fotos'] ?? [];
        if ($f) return ['rel' => $f[0], 'origem' => 'recebimento', 'pc' => (int)$m['pc'], 'fotos' => $f];
        if (++$n >= 6) break;
    }
    return $vazio;
}
/** Copia a foto escolhida para a pasta das fotos de saída (fica vinculada ao comprovante). '' se não der — nunca barra a saída. */
function est_foto_copiar(string $rel, string $doc, int $linha): string {
    $rel = est_foto_rel_ok($rel); if ($rel === '') return '';
    $src = dirname(DATA_DIR) . '/' . $rel;
    $info = @getimagesize($src);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || (int)@filesize($src) > 3 * 1024 * 1024) return '';
    if (!is_dir(EST_FOTO_SAIDA_DIR)) { @mkdir(EST_FOTO_SAIDA_DIR, 0750, true); @file_put_contents(EST_FOTO_SAIDA_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n"); }
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $arq = preg_replace('/[^A-Za-z0-9-]/', '', $doc) . '-L' . $linha . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    return @copy($src, EST_FOTO_SAIDA_DIR . '/' . $arq) ? $arq : '';
}
/** Quanto já saiu de cada linha de um PC (soma das saídas gravadas com aquele PC). Movimentos já arquivados (>5000) não entram. */
function est_retirado_pc(array $db, int $pc): array {
    $r = [];
    foreach ($db['movimentos'] ?? [] as $m) {
        if (($m['tipo'] ?? '') !== 'SAIDA' || (int)($m['pc'] ?? 0) !== $pc || ($m['linha_id'] ?? '') === '') continue;
        $r[$m['linha_id']] = ($r[$m['linha_id']] ?? 0) + (float)$m['quantidade'];
    }
    return $r;
}
/** O que o PC trouxe para o estoque e o que ainda pode sair por ele. status: ok | nao_encontrado | sem_entrada | sem_linhas */
function est_pc_saida_info(array $db, int $pc): array {
    $out = ['pc' => $pc, 'status' => 'nao_encontrado', 'mensagem' => 'PC ' . $pc . ' não encontrado.', 'linhas' => [], 'fotos_pc' => []];
    $ped = est_pedidos_idx()[$pc] ?? null;
    $doc = $db['recebimentos']['PC-' . $pc] ?? null;
    if ($ped) $out += ['fornecedor' => $ped['fornecedor'], 'solicitante' => $ped['solicitante'], 'urgente' => $ped['urgente'], 'numero_nf' => $ped['numero_nf'],
        'destino' => ['setor_codigo' => $ped['destino_setor'], 'obra_id' => $ped['obra_id'], 'obra' => $ped['obra']],
        'os' => $ped['os'], 'equipamento' => $ped['equipamento'], 'os_origem' => $ped['os_origem']];   // v3.32.0: OS lida do pedido (se houver)
    if (!$doc) {
        if ($ped) {
            $out['status'] = 'sem_entrada';
            if (!empty($ped['finalizado']) && (int)($ped['n_insumos'] ?? 0) > 0) {
                // v3.51.4: recebimento JÁ finalizado, mas a entrada no estoque nunca foi gerada
                // (ex.: PC finalizado antes da integração). Não mande "finalizar" de novo.
                $out['pode_gerar_entrada'] = true;
                $out['mensagem'] = 'O PC ' . $pc . ' (' . $ped['fornecedor'] . ') já teve o recebimento finalizado, mas a entrada no estoque ainda não foi gerada. Gere a entrada, confira e confirme o recebimento para liberar a saída.';
            } elseif (!empty($ped['finalizado'])) {
                $out['mensagem'] = 'O PC ' . $pc . ' (' . $ped['fornecedor'] . ') foi finalizado sem insumos detalhados. Cadastre os insumos no pedido ou lance uma Entrada manual no estoque.';
            } else {
                $out['mensagem'] = 'O PC ' . $pc . ' (' . $ped['fornecedor'] . ') existe, mas o recebimento ainda não foi finalizado. Finalize o recebimento primeiro.';
            }
        }
        return $out;
    }
    $out['fornecedor'] = $out['fornecedor'] ?? (string)($doc['fornecedor'] ?? '');
    $out['numero_nf'] = ($out['numero_nf'] ?? '') ?: (string)($doc['numero_nf'] ?? '');
    $out['fotos_pc'] = $ped['fotos'] ?? [];
    $ret = est_retirado_pc($db, $pc);
    foreach ((array)($doc['linhas'] ?? []) as $l) {
        $rec = round((float)($l['quantidade_recebida'] ?? 0), 4);
        $code = (string)($l['codigo'] ?? '');
        if ($rec <= 0 || $code === '' || !isset($db['itens'][$code])) continue;
        $it = $db['itens'][$code];
        $lid = (string)($l['id'] ?? '');
        $retirada = round((float)($ret[$lid] ?? 0), 4);
        $pend = round(max(0, $rec - $retirada), 4);
        $disp = round((float)$it['saldo'] - (float)($it['reservado'] ?? 0), 4);
        $fa = est_foto_auto($db, $code, $pc);
        $st = $pend <= 0 ? 'retirada_total' : ($disp <= 0 ? 'sem_saldo' : ($disp + 0.00001 < $pend ? 'saldo_parcial' : 'ok'));
        $out['linhas'][] = ['linha_id' => $lid, 'codigo' => $code, 'sku' => est_sap_code($lid) ?: (est_sap_code($code) ?: (str_starts_with($code, 'EST-') ? '' : $code)),
            'descricao' => (string)$it['descricao'], 'unidade' => (string)($it['unidade'] ?? 'UN'), 'recebida' => $rec, 'retirada' => $retirada, 'pendente' => $pend,
            'saldo' => round((float)$it['saldo'], 4), 'disponivel' => $disp, 'qtd_sugerida' => round(min($pend, max(0, $disp)), 4),
            'foto' => $fa['rel'], 'foto_origem' => $fa['origem'], 'fotos' => $fa['fotos'], 'status' => $st, 'localizacao' => (string)($it['localizacao'] ?? '')];
    }
    if (!$out['linhas']) { $out['status'] = 'sem_linhas'; $out['mensagem'] = 'O PC ' . $pc . ' ainda não teve nenhum material recebido e conferido no estoque.'; return $out; }
    $out['status'] = 'ok'; $out['mensagem'] = '';
    return $out;
}
/** Busca única da saída: número do PC, SKU/código, nome (com apelidos). Retorna o PC (se houver) e até 12 itens. */
function est_buscar_saida(array $db, string $q): array {
    foreach ($db['itens'] as $k => $it) if (!empty($it['unificado_para'])) unset($db['itens'][$k]);   // v3.57.0: unificados não aparecem
    $q = trim($q);
    $out = ['q' => $q, 'pc' => null, 'itens' => []];
    if ($q === '') return $out;
    if (preg_match('/^\s*(?:pc|pedido)?\s*[#nº°.:-]*\s*(\d{3,7})\s*$/iu', $q, $m)) $out['pc'] = est_pc_saida_info($db, (int)$m[1]);
    $nq = est_norm_key($q);
    $tok = array_values(array_filter(explode(' ', $nq)));
    $cq = strtoupper(preg_replace('/\s+/', '', $q));
    $res = [];
    foreach ($db['itens'] as $code => $it) {
        $cc = strtoupper(preg_replace('/\s+/', '', (string)$code)); $score = 0;
        $skus = [$cc];
        foreach ((array)($it['origem_ids'] ?? []) as $oid) { $s = est_sap_code($oid); if ($s !== '') $skus[] = $s; }
        if ($cq !== '' && in_array($cq, $skus, true)) $score = 100;
        elseif (strlen($cq) >= 3 && array_filter($skus, fn($k) => str_starts_with($k, $cq))) $score = 80;
        $dn = est_norm_key((string)($it['descricao'] ?? ''));
        $hay = $dn . ' ' . implode(' ', array_map('est_norm_key', (array)($it['aliases'] ?? [])));
        if (!$score && $nq !== '') {
            if ($dn === $nq) $score = 95;
            elseif ($tok && !array_filter($tok, fn($t) => mb_strpos($hay, $t) === false)) $score = str_starts_with($dn, $nq) ? 70 : 50;
            elseif (strlen($cq) >= 3 && array_filter($skus, fn($k) => str_contains($k, $cq))) $score = 40;
        }
        if ($score) $res[] = [$score, $dn, $code, $it];
    }
    usort($res, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    foreach (array_slice($res, 0, 12) as [$score, $dn, $code, $it]) {
        $v = est_item_view($it); $fa = est_foto_auto($db, (string)$code);
        $out['itens'][] = ['codigo' => (string)$code, 'sku' => str_starts_with((string)$code, 'EST-') ? '' : (string)$code, 'descricao' => (string)$it['descricao'],
            'unidade' => (string)($it['unidade'] ?? 'UN'), 'saldo' => $v['saldo'], 'disponivel' => $v['disponivel'], 'status' => $v['status'],
            'localizacao' => (string)($it['localizacao'] ?? ''), 'foto' => $fa['rel'], 'foto_origem' => $fa['origem'], 'foto_pc' => $fa['pc']];
    }
    return $out;
}
