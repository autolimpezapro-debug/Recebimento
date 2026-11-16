<?php
/**
 * Recebimento Drilling — Etiquetas e Identificação de Materiais (v3.6.0)
 * ----------------------------------------------------------------------
 * Módulo NATIVO portado do "SKU Auto Fill" (FOR-COM-001 rev.1). Regras de ouro:
 *
 *  - NÃO existe cadastro paralelo: o catálogo oficial é data/estoque.json → itens
 *    (o mesmo do módulo de Estoque). UM SKU = UM item oficial.
 *  - Etiqueta identifica o material; NUNCA gera movimento nem altera saldo.
 *    Este arquivo só chama est_apply_movement? NÃO. Só cria/atualiza cadastro (saldo 0).
 *  - Fila de impressão, histórico e importações ficam no SERVIDOR (data/etiquetas.json),
 *    por usuário. O navegador não é fonte oficial.
 *  - Editar uma etiqueta NÃO altera o cadastro. Divergências são apenas sinalizadas.
 *  - Atualizar cadastro oficial exige permissão e ação explícita; o valor anterior é
 *    preservado (apelido + histórico de cadastro).
 *
 * Estrutura de data/etiquetas.json:
 * { versao, sequencia:{job,import}, filas:{ usuario:{rev, itens[], atualizado_em} },
 *   jobs:[ {id,usuario,em,tipo,escopo,total,folhas,almoxarifado[],skus[],itens[snapshot]} ],
 *   historico:[ {em,usuario,tipo,detalhe} ], importacoes:[ {...} ] }
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const ETQ_FILE = DATA_DIR . '/etiquetas.json';
const ETQ_LOCK = DATA_DIR . '/.etiquetas.lock';
const ETQ_MAX_FILA = 3000;
const ETQ_MAX_JOBS = 300;
const ETQ_MAX_HIST = 2000;
const ETQ_MAX_SNAPSHOT = 600;     // etiquetas guardadas por trabalho para reimpressão
const ETQ_IMPORT_MAX_LINHAS = 5000;

/** Permissão → perfis que a possuem (nenhuma é concedida automaticamente a "consulta"). */
const ETQ_PERMS = [
    'visualizar'        => ['gestor', 'conferente', 'consulta', 'importador'],
    'pesquisar'         => ['gestor', 'conferente', 'consulta', 'importador'],
    'historico'         => ['gestor', 'conferente', 'consulta'],
    'gerar'             => ['gestor', 'conferente'],
    'editar'            => ['gestor', 'conferente'],
    'fila'              => ['gestor', 'conferente'],
    'imprimir'          => ['gestor', 'conferente'],
    'exportar_pdf'      => ['gestor', 'conferente'],
    'importar'          => ['gestor', 'importador'],
    'cadastrar'         => ['gestor'],
    'atualizar_cadastro'=> ['gestor'],
];

const ETQ_ORIGENS = ['PEDIDO_COMPRA', 'IMPORTACAO_PLANILHA', 'CADASTRO_MANUAL', 'MIGRACAO'];

/* ------------------------------------------------------------------ */
/* Infraestrutura                                                      */
/* ------------------------------------------------------------------ */

function etq_fail($msg, $code = 422) { respond(['ok' => false, 'error' => $msg], $code); }
function etq_now() { return date('c'); }

function etq_empty() {
    return ['versao' => 1, 'sequencia' => ['job' => 0, 'import' => 0], 'filas' => [], 'jobs' => [], 'historico' => [], 'importacoes' => []];
}

function etq_read() {
    if (!is_file(ETQ_FILE)) return etq_empty();
    $raw = @file_get_contents(ETQ_FILE);
    if ($raw === false || $raw === '') return etq_empty();
    $d = json_decode($raw, true);
    // Nunca recria por cima de arquivo corrompido: preserva e avisa.
    if (!is_array($d)) etq_fail('Falha ao ler data/etiquetas.json. Preserve o arquivo e restaure o backup.', 500);
    return array_merge(etq_empty(), $d);
}

function etq_write(array $db) {
    $tmp = ETQ_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) etq_fail('Não foi possível gravar as etiquetas.', 500);
    @chmod($tmp, 0640);
    if (!@rename($tmp, ETQ_FILE)) etq_fail('Falha ao persistir as etiquetas.', 500);
}

function etq_with_lock(callable $fn) {
    $fp = @fopen(ETQ_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) etq_fail('Trava de etiquetas indisponível.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function etq_str($v, $max = 200) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); }
function etq_up($v, $max = 200) { return mb_strtoupper(etq_str($v, $max), 'UTF-8'); }

function etq_hist(array &$db, $usuario, $tipo, $detalhe) {
    $db['historico'][] = ['em' => etq_now(), 'usuario' => $usuario, 'tipo' => $tipo, 'detalhe' => etq_str($detalhe, 500)];
    if (count($db['historico']) > ETQ_MAX_HIST) $db['historico'] = array_slice($db['historico'], -ETQ_MAX_HIST);
    if (function_exists('append_event')) append_event(0, 'etiquetas', etq_str($detalhe, 200));
}

/* ------------------------------------------------------------------ */
/* SKU: normalização e catálogo oficial                                */
/* ------------------------------------------------------------------ */

/** SKU é TEXTO: preserva zeros à esquerda e letras; só maiúsculas e caracteres válidos. */
function etq_sku($v) {
    $s = strtoupper(trim((string)$v));
    $s = preg_replace('/\s+/', '', $s);
    return substr(preg_replace('/[^A-Z0-9._\-\/]/', '', $s), 0, 40);
}

function etq_sku_valido($sku) { return $sku !== '' && preg_match('/^[A-Z0-9][A-Z0-9._\-\/]{1,39}$/D', $sku) === 1; }

/** Chave de busca tolerante a acento/caixa/pontuação (mesma lógica do estoque). */
function etq_key($t) {
    return function_exists('est_norm_key') ? est_norm_key($t) : trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$t)));
}

/** SKU "de origem" de um item do estoque (campo sku, código, ou id de origem do pedido). */
function etq_item_skus(array $it, $code) {
    $out = [];
    if (!empty($it['sku'])) $out[] = etq_sku($it['sku']);
    $c = etq_sku($code);
    if ($c !== '' && !preg_match('/^EST-/', $c)) $out[] = $c;
    foreach ((array)($it['origem_ids'] ?? []) as $o) {
        if (preg_match('/^sku[_-]([a-z0-9._\-]+?)(?:_\d+)?$/i', (string)$o, $m)) $out[] = etq_sku($m[1]);
        elseif (preg_match('/^[a-z]{1,5}\d{4,10}$/i', (string)$o)) $out[] = etq_sku($o);
    }
    return array_values(array_unique(array_filter($out)));
}

/** Índice SKU → código do item oficial. Detecta SKUs ambíguos (mesmo SKU em 2 itens). */
function etq_catalog_index(array $db) {
    $idx = []; $dup = [];
    foreach (($db['itens'] ?? []) as $code => $it) {
        foreach (etq_item_skus($it, $code) as $s) {
            if (isset($idx[$s]) && $idx[$s] !== $code) $dup[$s][] = $code;
            else $idx[$s] = $code;
        }
    }
    foreach ($dup as $s => $list) $dup[$s] = array_values(array_unique(array_merge([$idx[$s]], $list)));
    return [$idx, $dup];
}

function etq_find_official(array $db, $sku) {
    [$idx] = etq_catalog_index($db);
    $sku = etq_sku($sku);
    return isset($idx[$sku]) ? $idx[$sku] : null;
}

function etq_official_view($code, array $it, $sku = '') {
    $skus = etq_item_skus($it, $code);
    return [
        'oficial' => true,
        'codigo' => $code,
        'sku' => $sku !== '' ? $sku : ($skus[0] ?? etq_sku($code)),
        'descricao' => (string)($it['descricao'] ?? ''),
        'unidade' => (string)($it['unidade'] ?? 'UN'),
        'categoria' => (string)($it['categoria'] ?? ''),
        'localizacao' => (string)($it['localizacao'] ?? ''),
        'aliases' => array_values((array)($it['aliases'] ?? [])),
        'origem_cadastro' => (string)($it['origem_cadastro'] ?? 'MIGRACAO'),
    ];
}

/** Materiais que aparecem em pedidos de compra mas ainda NÃO estão no catálogo oficial. */
function etq_pedido_candidates(array $db) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $file = defined('ITEMS_FILE') ? ITEMS_FILE : DATA_DIR . '/items.json';
    $rows = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($rows)) return $cache;
    [$idx] = etq_catalog_index($db);
    foreach ($rows as $p) {
        foreach ((array)($p['insumos'] ?? []) as $l) {
            if (!preg_match('/^sku[_-]([A-Za-z0-9._\-]+?)(?:_\d+)?$/', (string)($l['id'] ?? ''), $m)) continue;
            $sku = etq_sku($m[1]);
            if (!etq_sku_valido($sku) || isset($idx[$sku]) || isset($cache[$sku])) continue;
            $cache[$sku] = ['oficial' => false, 'codigo' => '', 'sku' => $sku, 'descricao' => (string)($l['descricao'] ?? ''),
                'unidade' => (string)($l['unidade'] ?? 'UN'), 'categoria' => '', 'localizacao' => '', 'aliases' => [],
                'origem_cadastro' => 'PEDIDO_COMPRA', 'pc' => (int)($p['pc'] ?? 0)];
        }
    }
    return $cache;
}

/** Pesquisa por SKU, código interno, descrição, apelido e categoria. */
function etq_buscar(array $db, $q, $limite = 60) {
    $qk = etq_key($q);
    $rows = [];
    foreach (($db['itens'] ?? []) as $code => $it) $rows[] = etq_official_view($code, $it);
    foreach (etq_pedido_candidates($db) as $c) $rows[] = $c;
    if ($qk === '') { usort($rows, fn($a, $b) => strcmp($a['sku'], $b['sku'])); return array_slice($rows, 0, $limite); }
    $tokens = array_values(array_filter(explode(' ', $qk)));
    $exact = $pref = $rest = [];
    foreach ($rows as $r) {
        $skuK = etq_key($r['sku']); $codK = etq_key($r['codigo']);
        $hay = $skuK . ' ' . $codK . ' ' . etq_key($r['descricao']) . ' ' . etq_key($r['categoria']) . ' ' . etq_key(implode(' ', $r['aliases']));
        foreach ($tokens as $t) if (mb_strpos($hay, $t) === false) continue 2;
        if ($skuK === $qk || $codK === $qk) $exact[] = $r;
        elseif (mb_strpos($skuK, $qk) === 0 || mb_strpos($codK, $qk) === 0) $pref[] = $r;
        else $rest[] = $r;
    }
    return array_slice(array_merge($exact, $pref, $rest), 0, $limite);
}

/* ------------------------------------------------------------------ */
/* Cadastro no catálogo oficial (SEM movimento, saldo permanece 0)     */
/* ------------------------------------------------------------------ */

/** Cria item oficial. Falha se o SKU já existir (um SKU = um material). Chamar dentro de est_with_lock. */
function etq_criar_item(array &$db, $sku, $descricao, $unidade, $categoria, $origem, $usuario, $extra = []) {
    $sku = etq_sku($sku);
    if (!etq_sku_valido($sku)) etq_fail('SKU inválido: use letras, números e . _ - / (2 a 40 caracteres).');
    $descricao = etq_up($descricao, 600);
    if (mb_strlen($descricao) < 3) etq_fail('Informe a descrição do material.');
    if (!in_array($origem, ETQ_ORIGENS, true)) $origem = 'CADASTRO_MANUAL';
    [$idx] = etq_catalog_index($db);
    if (isset($idx[$sku])) etq_fail('SKU ' . $sku . ' já existe no catálogo oficial (' . $idx[$sku] . ').', 409);
    $code = $sku;
    if (isset($db['itens'][$code])) etq_fail('Código ' . $code . ' já em uso no estoque.', 409);
    $it = est_new_item(['descricao' => $descricao, 'unidade' => $unidade ?: 'UN', 'categoria' => $categoria] + $extra, $code);
    $it['sku'] = $sku;
    $it['origem_cadastro'] = $origem;
    $it['cadastrado_por'] = $usuario;
    $it['origem_ids'] = ['sku_' . strtolower($sku)];
    $db['itens'][$code] = $it;
    return $code;
}

/** Atualiza campos autorizados de item existente; guarda o valor anterior. */
function etq_atualizar_item(array &$db, $code, array $campos, $usuario) {
    if (!isset($db['itens'][$code])) return [];
    $it = &$db['itens'][$code];
    $mudou = [];
    foreach ($campos as $campo => $valor) {
        if (!in_array($campo, ['descricao', 'unidade', 'categoria'], true)) continue;
        $novo = $campo === 'unidade' ? strtoupper(etq_str($valor, 12)) : ($campo === 'descricao' ? etq_up($valor, 600) : etq_str($valor, 80));
        if ($novo === '' || $novo === (string)($it[$campo] ?? '')) continue;
        $it['historico_cadastro'][] = ['em' => etq_now(), 'usuario' => $usuario, 'campo' => $campo, 'de' => $it[$campo] ?? '', 'para' => $novo];
        if (count($it['historico_cadastro']) > 50) $it['historico_cadastro'] = array_slice($it['historico_cadastro'], -50);
        if ($campo === 'descricao') {   // descrição antiga vira apelido: pedidos anteriores continuam casando
            $old = (string)($it['descricao'] ?? '');
            $known = array_map('etq_key', array_merge([$old], (array)($it['aliases'] ?? [])));
            if ($old !== '' && count($it['aliases'] ?? []) < 25) $it['aliases'][] = $old;
        }
        $it[$campo] = $novo;
        $mudou[] = $campo;
    }
    if ($mudou) $it['atualizado_em'] = etq_now();
    return $mudou;
}

/* ------------------------------------------------------------------ */
/* Importação de planilha (linhas já lidas no navegador)               */
/* ------------------------------------------------------------------ */

/** Classifica as linhas contra o catálogo oficial. NÃO grava nada. */
function etq_classificar_import(array $db, array $linhas) {
    [$idx] = etq_catalog_index($db);
    $res = ['novos' => [], 'existentes' => [], 'divergentes' => [], 'duplicados' => [], 'invalidos' => [], 'vazias' => 0];
    $vistos = [];
    foreach ($linhas as $n => $l) {
        $linhaNum = (int)($l['linha'] ?? ($n + 2));
        $sku = etq_sku($l['sku'] ?? '');
        $desc = etq_up($l['descricao'] ?? '', 600);
        $un = strtoupper(etq_str($l['unidade'] ?? '', 12));
        $cat = etq_str($l['categoria'] ?? '', 80);
        if ($sku === '' && $desc === '') { $res['vazias']++; continue; }
        if (!etq_sku_valido($sku) || mb_strlen($desc) < 3) {
            $res['invalidos'][] = ['linha' => $linhaNum, 'sku' => $sku ?: etq_str($l['sku'] ?? '', 40), 'descricao' => $desc,
                'motivo' => !etq_sku_valido($sku) ? 'SKU ausente ou inválido' : 'Descrição ausente ou curta demais'];
            continue;
        }
        if (isset($vistos[$sku])) {
            $prim = $vistos[$sku];
            $res['duplicados'][] = ['linha' => $linhaNum, 'sku' => $sku, 'descricao' => $desc, 'primeira_linha' => $prim['linha'],
                'mesma_descricao' => etq_key($prim['descricao']) === etq_key($desc)];
            continue;   // a primeira ocorrência vale; a repetida só é informada
        }
        $vistos[$sku] = ['linha' => $linhaNum, 'descricao' => $desc];
        $row = ['linha' => $linhaNum, 'sku' => $sku, 'descricao' => $desc, 'unidade' => $un, 'categoria' => $cat];
        if (isset($idx[$sku])) {
            $it = $db['itens'][$idx[$sku]];
            $row['codigo'] = $idx[$sku];
            $row['descricao_oficial'] = (string)$it['descricao'];
            $row['unidade_oficial'] = (string)($it['unidade'] ?? '');
            $row['categoria_oficial'] = (string)($it['categoria'] ?? '');
            $mesma = etq_key($it['descricao']) === etq_key($desc);
            $dif = [];
            if (!$mesma) $dif[] = 'descricao';
            if ($un !== '' && $un !== strtoupper((string)($it['unidade'] ?? ''))) $dif[] = 'unidade';
            if ($cat !== '' && $cat !== (string)($it['categoria'] ?? '')) $dif[] = 'categoria';
            $row['diferencas'] = $dif;
            if ($dif) $res['divergentes'][] = $row; else $res['existentes'][] = $row;
        } else {
            $res['novos'][] = $row;
        }
    }
    return $res;
}

/* ------------------------------------------------------------------ */
/* Fila (por usuário, no servidor)                                     */
/* ------------------------------------------------------------------ */

function etq_id() { return bin2hex(random_bytes(6)); }

function etq_normalizar_etiqueta(array $e) {
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['id'] ?? ''));
    if ($id === '' || strlen($id) > 24) $id = etq_id();
    return [
        'id' => $id,
        'almoxarifado' => etq_up($e['almoxarifado'] ?? '', 40) ?: 'DRILLING',
        'item' => etq_up($e['item'] ?? '', 300),
        'insumo' => etq_up($e['insumo'] ?? '', 40),
        'data' => !empty($e['data_manual']) ? etq_str($e['data'] ?? '', 20) : '',
        'data_manual' => !empty($e['data_manual']) && etq_str($e['data'] ?? '', 20) !== '',
        'oficial' => !empty($e['oficial']),
        'editada' => !empty($e['editada']),
    ];
}

function etq_fila_get(array $db, $usuario) {
    $f = $db['filas'][$usuario] ?? null;
    return is_array($f) ? $f : ['rev' => 0, 'itens' => [], 'atualizado_em' => null];
}

function etq_fila_set(array &$db, $usuario, array $itens, $expectedRev) {
    $atual = etq_fila_get($db, $usuario);
    if ($expectedRev !== null && (int)$expectedRev !== (int)$atual['rev'])
        etq_fail('A fila foi alterada em outro dispositivo. Recarregue para continuar.', 409);
    if (count($itens) > ETQ_MAX_FILA) etq_fail('Limite de ' . ETQ_MAX_FILA . ' etiquetas na fila.');
    $norm = []; $ids = [];
    foreach ($itens as $e) {
        if (!is_array($e)) continue;
        $n = etq_normalizar_etiqueta($e);
        if (isset($ids[$n['id']])) $n['id'] = etq_id();
        $ids[$n['id']] = true;
        $norm[] = $n;
    }
    $db['filas'][$usuario] = ['rev' => (int)$atual['rev'] + 1, 'itens' => $norm, 'atualizado_em' => etq_now()];
    return $db['filas'][$usuario];
}

/* ------------------------------------------------------------------ */
/* Trabalhos de impressão (histórico / reimpressão)                    */
/* ------------------------------------------------------------------ */

function etq_registrar_job(array &$db, $usuario, $tipo, array $etiquetas, array $escopo, $origemJob = '') {
    $db['sequencia']['job'] = (int)($db['sequencia']['job'] ?? 0) + 1;
    $id = 'ETQ-' . date('Y') . '-' . str_pad((string)$db['sequencia']['job'], 5, '0', STR_PAD_LEFT);
    // DATA = dia da impressão (servidor, America/Sao_Paulo), salvo data digitada manualmente.
    $hoje = date('d/m/Y');
    $norm = array_map(function ($e) use ($hoje) { $n = etq_normalizar_etiqueta($e); if (empty($n['data_manual'])) $n['data'] = $hoje; return $n; }, array_slice($etiquetas, 0, ETQ_MAX_SNAPSHOT));
    $skus = []; $almox = [];
    foreach ($etiquetas as $e) {
        $s = etq_sku($e['insumo'] ?? ''); if ($s !== '') $skus[$s] = ($skus[$s] ?? 0) + 1;
        $a = etq_up($e['almoxarifado'] ?? '', 40) ?: 'DRILLING'; $almox[$a] = true;
    }
    $job = [
        'id' => $id, 'usuario' => $usuario, 'em' => etq_now(),
        'tipo' => in_array($tipo, ['impressao', 'pdf', 'reimpressao'], true) ? $tipo : 'impressao',
        'origem_job' => $origemJob, 'escopo' => $escopo, 'total' => count($etiquetas),
        'folhas' => max(1, (int)ceil(count($etiquetas) / 6)),
        'almoxarifados' => array_keys($almox), 'skus' => $skus, 'itens' => $norm,
        'snapshot_completo' => count($etiquetas) <= ETQ_MAX_SNAPSHOT,
    ];
    $db['jobs'][] = $job;
    if (count($db['jobs']) > ETQ_MAX_JOBS) $db['jobs'] = array_slice($db['jobs'], -ETQ_MAX_JOBS);
    return $job;
}

function etq_job_resumo(array $j) {
    unset($j['itens']);
    return $j;
}
