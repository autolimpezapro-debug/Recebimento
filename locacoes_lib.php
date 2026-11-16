<?php
/**
 * Recebimento Drilling — Locação de equipamentos (v3.12.0)
 *
 * Equipamentos ALUGADOS DE FORNECEDORES (ex.: Locafaz): máquina de solda, furadeira, lixadeira…
 * Cada locação tem tarifa (diária/semanal/quinzenal/mensal), local atual (Matriz ou obra),
 * histórico de transferências e custos extras (frete, manutenção, avaria…).
 * O custo é rateado por local conforme os dias que o equipamento passou em cada um.
 *
 * data/locacoes.json — gravação atômica + trava própria + cópia .backup.json.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const LOC_FILE = DATA_DIR . '/locacoes.json';
const LOC_LOCK = DATA_DIR . '/.locacoes.lock';
const LOC_MATRIZ = 'MATRIZ';
const LOC_DIR = DATA_DIR . '/locacoes_docs';      // v3.35.0: documentos da locação (NF, comprovante, contrato) — bloqueado para acesso direto
const LOC_MAX_DOC = 15 * 1024 * 1024;
const LOC_DOC_TIPOS = ['NF_REMESSA' => 'NF de remessa', 'NF_DEVOLUCAO' => 'NF de devolução', 'COMPROVANTE' => 'Comprovante de devolução', 'CHECKLIST' => 'Checklist de entrega / retorno', 'CONTRATO' => 'Contrato', 'FATURA' => 'Fatura / boleto', 'OUTRO' => 'Outro'];
const LOC_TARIFAS = ['DIARIA' => 1, 'SEMANAL' => 7, 'QUINZENAL' => 15, 'MENSAL' => 30];
const LOC_EXTRAS = ['FRETE_ENTREGA' => 'Frete de entrega', 'FRETE_RETIRADA' => 'Frete de retirada', 'FRETE_TRANSFERENCIA' => 'Frete de transferência',
    'MANUTENCAO' => 'Manutenção', 'AVARIA' => 'Avaria / dano', 'SEGURO' => 'Seguro', 'CONSUMIVEL' => 'Consumível / acessório',
    'AJUSTE_FATURA' => 'Ajuste de fatura (+/−)', 'OUTRO' => 'Outro'];

function loc_fail($m, $c = 422) { respond(['ok' => false, 'error' => $m], $c); }
function loc_str($v, $n = 200) { return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)$v))), 0, $n); }
function loc_local($v) { $v = mb_strtoupper(loc_str($v, 80)); return $v === '' || in_array($v, ['MATRIZ', 'SEDE', 'DEPOSITO', 'DEPÓSITO'], true) ? LOC_MATRIZ : $v; }
function loc_data($v, $obrig = true, $campo = 'data') {
    $v = substr(trim((string)$v), 0, 10);
    if ($v === '' && !$obrig) return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) loc_fail("Informe uma $campo válida.");
    return $v;
}
function loc_num($v) { return round((float)str_replace(',', '.', (string)$v), 2); }
function loc_hoje() { return date('Y-m-d'); }
function loc_dias($a, $b) { return (int)round((strtotime($b . ' 12:00') - strtotime($a . ' 12:00')) / 86400); }
function loc_mais($d, $n) { return date('Y-m-d', strtotime($d . ' 12:00 ' . ($n >= 0 ? '+' : '') . $n . ' day')); }

function loc_read() {
    $vazio = ['versao' => 1, 'seq' => 0, 'locacoes' => [], 'fornecedores' => []];
    if (!is_file(LOC_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(LOC_FILE), true);
    if (!is_array($d)) loc_fail('Falha ao ler data/locacoes.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + $vazio;
}
function loc_write(array $d) {
    $tmp = LOC_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) loc_fail('Não foi possível gravar as locações.', 500);
    if (is_file(LOC_FILE)) @copy(LOC_FILE, LOC_FILE . '.backup.json');
    if (!rename($tmp, LOC_FILE)) loc_fail('Falha ao persistir as locações.', 500);
}
function loc_with_lock(callable $fn) {
    $fp = @fopen(LOC_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) loc_fail('Não foi possível bloquear as locações para gravação.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function &loc_get(array &$d, $id) {
    foreach ($d['locacoes'] as $k => &$l) if ($l['id'] === $id) return $l;
    loc_fail('Locação não encontrada.', 404);
}

/** Valor por dia conforme a tarifa contratada. */
function loc_valor_dia(array $l) {
    $p = LOC_TARIFAS[$l['tarifa_tipo'] ?? 'DIARIA'] ?? 1;
    return (float)$l['tarifa_valor'] * (float)($l['quantidade'] ?? 1) / $p;
}

/**
 * Segmentos [inicio, fim] por local. Dias contados de forma inclusiva (retirada e devolução contam).
 * Uma transferência na data X: o dia X passa a contar para o novo local.
 */
function loc_segmentos(array $l, $ate = null) {
    if (empty($l['inicio'])) return [];                     // sem data: controla só equipamento/fornecedor/patrimônio
    $fim = $l['fim'] ?: ($l['status'] === 'DEVOLVIDA' ? $l['inicio'] : ($ate ?: loc_hoje()));
    if ($fim < $l['inicio']) $fim = $l['inicio'];
    $movs = array_values(array_filter($l['movimentos'] ?? [], fn($m) => in_array($m['tipo'], ['INICIO', 'TRANSFERENCIA'], true) && !empty($m['data'])));
    usort($movs, fn($a, $b) => strcmp($a['data'], $b['data']) ?: strcmp($a['em'] ?? '', $b['em'] ?? ''));
    $seg = [];
    foreach ($movs as $i => $m) {
        $a = max($m['data'], $l['inicio']);
        $b = isset($movs[$i + 1]) ? loc_mais($movs[$i + 1]['data'], -1) : $fim;
        if ($b > $fim) $b = $fim;
        if ($b < $a) continue;
        $seg[] = ['local' => $m['local'], 'de' => $a, 'ate' => $b, 'dias' => loc_dias($a, $b) + 1];
    }
    return $seg;
}

/** Custo completo de uma locação: aluguel rateado por local e por mês + extras. */
function loc_custo(array $l, $ate = null) {
    $vd = loc_valor_dia($l);
    $seg = loc_segmentos($l, $ate);
    $dias = array_sum(array_column($seg, 'dias'));
    $porLocal = []; $porMes = [];
    foreach ($seg as $s) {
        $v = $s['dias'] * $vd;
        $porLocal[$s['local']] = ($porLocal[$s['local']] ?? 0) + $v;
        // rateio mensal
        $c = $s['de'];
        while ($c <= $s['ate']) {
            $fimMes = date('Y-m-t', strtotime($c . ' 12:00'));
            $b = min($fimMes, $s['ate']);
            $n = loc_dias($c, $b) + 1;
            $mk = substr($c, 0, 7);
            $porMes[$mk] = ($porMes[$mk] ?? 0) + $n * $vd;
            $c = loc_mais($b, 1);
        }
    }
    // período mínimo de cobrança (ex.: mínimo 7 dias) — diferença vai para o último local
    $min = (int)($l['minimo_dias'] ?? 0);
    if ($l['fim'] && $min > $dias && $seg) {
        $dif = ($min - $dias) * $vd; $ult = end($seg);
        $porLocal[$ult['local']] += $dif;
        $mk = substr($ult['ate'], 0, 7); $porMes[$mk] = ($porMes[$mk] ?? 0) + $dif;
    }
    $aluguel = array_sum($porLocal);
    $extras = 0.0;
    foreach (($l['extras'] ?? []) as $x) {
        $extras += $x['valor'];
        $loc = $x['local'] ?: ($seg ? end($seg)['local'] : LOC_MATRIZ);
        $porLocal[$loc] = ($porLocal[$loc] ?? 0) + $x['valor'];
        $mk = substr((string)$x['data'], 0, 7); $porMes[$mk] = ($porMes[$mk] ?? 0) + $x['valor'];
    }
    return ['valor_dia' => round($vd, 4), 'dias' => max($dias, $l['fim'] ? $min : 0, $dias), 'dias_corridos' => $dias,
        'aluguel' => round($aluguel, 2), 'extras' => round($extras, 2), 'total' => round($aluguel + $extras, 2),
        'por_local' => array_map(fn($v) => round($v, 2), $porLocal), 'por_mes' => array_map(fn($v) => round($v, 2), $porMes), 'segmentos' => $seg];
}

/* ---------------- v3.35.0: documentos anexados à locação ---------------- */
function loc_doc_dir(): void {
    if (!is_dir(LOC_DIR)) @mkdir(LOC_DIR, 0750, true);
    if (!is_file(LOC_DIR . '/.htaccess')) @file_put_contents(LOC_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n");
}
function loc_doc_mime(string $arq): array {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arq) ?: '';
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    return [$mime, $ext];
}
/** Grava o registro de um documento (o arquivo já está em LOC_DIR). */
function loc_doc_registrar(array &$l, string $nome, string $arquivo, string $mime, int $bytes, string $tipo, string $user): void {
    if (count($l['documentos'] ?? []) >= 30) loc_fail('Limite de 30 documentos por locação.');
    $tipo = isset(LOC_DOC_TIPOS[$tipo]) ? $tipo : 'OUTRO';
    $l['documentos'][] = ['arquivo' => $arquivo, 'nome' => loc_str($nome, 120), 'mime' => $mime, 'bytes' => $bytes, 'tipo' => $tipo, 'em' => date('c'), 'por' => $user];
    $l['atualizado_em'] = date('c');
}
/** Upload (multipart: id, tipo, arquivo). */
function loc_doc_add(array &$d, string $user): array {
    $l = &loc_get($d, loc_str($_POST['id'] ?? '', 20));
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) loc_fail(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Arquivo maior que o limite do servidor.' : 'Arquivo não recebido.');
    if ($f['size'] > LOC_MAX_DOC) loc_fail('Arquivo maior que 15 MB.');
    [$mime, $ext] = loc_doc_mime($f['tmp_name']);
    if (!$ext) loc_fail('Formato não aceito (' . $mime . '). Use PDF, JPG, PNG ou WEBP.');
    loc_doc_dir();
    $nome = preg_replace('/[^A-Za-z0-9-]/', '', $l['id']) . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], LOC_DIR . '/' . $nome)) loc_fail('Não foi possível gravar o arquivo.', 500);
    @chmod(LOC_DIR . '/' . $nome, 0640);
    loc_doc_registrar($l, (string)($f['name'] ?? $nome), $nome, $mime, (int)$f['size'], (string)($_POST['tipo'] ?? 'OUTRO'), $user);
    return loc_view($l);
}
/** Carga inicial: copia um arquivo já existente no pacote para os documentos da locação. */
function loc_doc_importar(array &$l, string $origem, string $nomeExibicao, string $tipo, string $user): bool {
    if (!is_file($origem) || filesize($origem) > LOC_MAX_DOC) return false;
    [$mime, $ext] = loc_doc_mime($origem); if (!$ext) return false;
    loc_doc_dir();
    $nome = preg_replace('/[^A-Za-z0-9-]/', '', $l['id']) . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@copy($origem, LOC_DIR . '/' . $nome)) return false;
    @chmod(LOC_DIR . '/' . $nome, 0640);
    loc_doc_registrar($l, $nomeExibicao, $nome, $mime, (int)filesize($origem), $tipo, $user);
    return true;
}
function loc_doc_remover(array &$d, array $in, string $user): array {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20)); $i = (int)($in['i'] ?? -1);
    if (!isset($l['documentos'][$i])) loc_fail('Documento não encontrado.', 404);
    $a = $l['documentos'][$i]; array_splice($l['documentos'], $i, 1);
    $l['documentos_removidos'][] = $a + ['removido_em' => date('c'), 'removido_por' => $user];   // o arquivo continua guardado no servidor
    $l['atualizado_em'] = date('c');
    return loc_view($l);
}
function loc_doc_servir(array $d): void {
    $id = (string)($_GET['id'] ?? ''); $i = (int)($_GET['i'] ?? -1); $a = null;
    foreach ($d['locacoes'] as $l) if ($l['id'] === $id) { $a = $l['documentos'][$i] ?? null; break; }
    if (!$a) { http_response_code(404); exit('Documento não encontrado'); }
    $p = LOC_DIR . '/' . basename($a['arquivo']);
    if (!is_file($p)) { http_response_code(404); exit('Arquivo não encontrado no servidor'); }
    header('Content-Type: ' . $a['mime']); header('Content-Length: ' . filesize($p)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . (!empty($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $a['nome']) . '"');
    readfile($p); exit;
}

function loc_view(array $l) {
    $l['documentos'] = array_values(array_map(fn($a, $i) => $a + ['idx' => $i, 'tipo_label' => LOC_DOC_TIPOS[$a['tipo'] ?? ''] ?? 'Outro',
        'url' => 'api.php?action=locacoes_arquivo&id=' . rawurlencode($l['id']) . '&i=' . $i], (array)($l['documentos'] ?? []), array_keys((array)($l['documentos'] ?? []))));
    unset($l['documentos_removidos']);
    $c = loc_custo($l);
    $l['custo'] = $c;
    $hoje = loc_hoje();
    $l['vencida'] = $l['status'] === 'ATIVA' && !empty($l['previsao_devolucao']) && $l['previsao_devolucao'] < $hoje;
    $l['dias_vencida'] = $l['vencida'] ? loc_dias($l['previsao_devolucao'], $hoje) : 0;
    $l['vence_em'] = $l['status'] === 'ATIVA' && !empty($l['previsao_devolucao']) && !$l['vencida'] ? loc_dias($hoje, $l['previsao_devolucao']) : null;
    return $l;
}

function loc_painel(array $d, array $q = []) {
    $mes = preg_match('/^\d{4}-\d{2}$/', (string)($q['mes'] ?? '')) ? $q['mes'] : date('Y-m');
    $views = array_map('loc_view', $d['locacoes']);
    usort($views, fn($a, $b) => [$a['status'] !== 'ATIVA', (string)$b['inicio'], $b['id']] <=> [$b['status'] !== 'ATIVA', (string)$a['inicio'], $a['id']]);
    $k = ['ativas' => 0, 'equipamentos_ativos' => 0, 'vencidas' => 0, 'vencem_7' => 0, 'custo_dia_atual' => 0.0, 'custo_mes' => 0.0,
          'custo_total' => 0.0, 'custo_ativas' => 0.0, 'na_matriz' => 0, 'em_obras' => 0, 'mes' => $mes, 'projecao_mes' => 0.0];
    $locais = []; $forn = []; $meses = []; $tipos = [];
    $diasMes = (int)date('t', strtotime($mes . '-01')); $hojeMes = $mes === date('Y-m') ? (int)date('j') : $diasMes;
    foreach ($views as $v) {
        $c = $v['custo'];
        $k['custo_total'] += $c['total']; $k['custo_mes'] += $c['por_mes'][$mes] ?? 0;
        if ($v['status'] === 'ATIVA') {
            $k['ativas']++; $k['equipamentos_ativos'] += (int)$v['quantidade'];
            $k['custo_dia_atual'] += $c['valor_dia']; $k['custo_ativas'] += $c['total'];
            if ($v['vencida']) $k['vencidas']++; elseif ($v['vence_em'] !== null && $v['vence_em'] <= 7) $k['vencem_7']++;
            if ($v['local_atual'] === LOC_MATRIZ) $k['na_matriz']++; else $k['em_obras']++;
            if ($mes === date('Y-m')) $k['projecao_mes'] += $c['valor_dia'] * ($diasMes - $hojeMes);
        }
        foreach ($c['por_local'] as $lc => $val) {
            $locais[$lc] = $locais[$lc] ?? ['local' => $lc, 'total' => 0.0, 'mes' => 0.0, 'ativos' => 0, 'custo_dia' => 0.0];
            $locais[$lc]['total'] += $val;
        }
        // custo do mês por local (rateio por segmentos dentro do mês)
        foreach ($c['segmentos'] as $s) {
            $a = max($s['de'], $mes . '-01'); $b = min($s['ate'], date('Y-m-t', strtotime($mes . '-01')));
            if ($b >= $a) $locais[$s['local']]['mes'] += (loc_dias($a, $b) + 1) * $c['valor_dia'];
        }
        foreach ($v['extras'] ?? [] as $x) if (substr($x['data'], 0, 7) === $mes) {
            $lc = $x['local'] ?: $v['local_atual']; $locais[$lc] = $locais[$lc] ?? ['local' => $lc, 'total' => 0.0, 'mes' => 0.0, 'ativos' => 0, 'custo_dia' => 0.0];
            $locais[$lc]['mes'] += $x['valor'];
        }
        if ($v['status'] === 'ATIVA') { $locais[$v['local_atual']]['ativos'] = ($locais[$v['local_atual']]['ativos'] ?? 0) + (int)$v['quantidade']; $locais[$v['local_atual']]['custo_dia'] += $c['valor_dia']; }
        $f = $v['fornecedor'];
        $forn[$f] = $forn[$f] ?? ['fornecedor' => $f, 'total' => 0.0, 'mes' => 0.0, 'ativas' => 0, 'locacoes' => 0];
        $forn[$f]['total'] += $c['total']; $forn[$f]['mes'] += $c['por_mes'][$mes] ?? 0; $forn[$f]['locacoes']++;
        if ($v['status'] === 'ATIVA') $forn[$f]['ativas']++;
        $t = $v['categoria'] ?: 'Outros';
        $tipos[$t] = $tipos[$t] ?? ['categoria' => $t, 'total' => 0.0, 'ativas' => 0];
        $tipos[$t]['total'] += $c['total']; if ($v['status'] === 'ATIVA') $tipos[$t]['ativas']++;
        foreach ($c['por_mes'] as $mk => $val) $meses[$mk] = ($meses[$mk] ?? 0) + $val;
    }
    $ord = function ($a, $campo) { $a = array_values($a); usort($a, fn($x, $y) => $y[$campo] <=> $x[$campo]); return $a; };
    $round = fn($rows) => array_map(fn($r) => array_map(fn($x) => is_float($x) ? round($x, 2) : $x, $r), $rows);
    ksort($meses);
    $serie = [];
    for ($i = 11; $i >= 0; $i--) { $mk = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $serie[] = ['mes' => $mk, 'valor' => round($meses[$mk] ?? 0, 2)]; }
    foreach ($k as $n => $x) if (is_float($x)) $k[$n] = round($x, 2);
    $k['projecao_mes'] = round($k['custo_mes'] + $k['projecao_mes'], 2);
    $nomes = [];
    foreach ($d['locacoes'] as $l) { $nomes['fornecedores'][$l['fornecedor']] = 1; $nomes['equipamentos'][$l['equipamento']] = 1; if ($l['categoria']) $nomes['categorias'][$l['categoria']] = 1;
        foreach ($l['movimentos'] ?? [] as $m) $nomes['locais'][$m['local']] = 1; }
    foreach (loc_obras_conhecidas() as $o) $nomes['locais'][$o] = 1;
    return ['kpis' => $k, 'locacoes' => $views, 'por_local' => $round($ord($locais, 'total')), 'por_fornecedor' => $round($ord($forn, 'total')),
        'por_categoria' => $round($ord($tipos, 'total')), 'mensal' => $serie,
        'sugestoes' => array_map(fn($x) => array_keys($x), $nomes + ['fornecedores' => [], 'equipamentos' => [], 'categorias' => [], 'locais' => []]),
        'tarifas' => array_keys(LOC_TARIFAS), 'extras' => LOC_EXTRAS];
}

/** Obras já conhecidas no sistema (indicadores + saídas do estoque) para sugerir no campo local. */
function loc_obras_conhecidas() {
    $o = [LOC_MATRIZ => 1];
    require_once __DIR__ . '/rd_obras.php';
    foreach (rd_obras_lista() as $x) $o[mb_strtoupper($x['name'])] = 1;
    foreach (['CALDEIRARIA', 'MANUTENÇÃO', 'EXPEDIÇÃO', 'LOGÍSTICA', 'ADM'] as $x) $o[$x] = 1;
    $k = is_file(DATA_DIR . '/indicadores.json') ? json_decode((string)@file_get_contents(DATA_DIR . '/indicadores.json'), true) : null;
    foreach ((array)($k['obras'] ?? []) as $x) if (!empty($x['name'])) $o[mb_strtoupper($x['name'])] = 1;
    $e = is_file(DATA_DIR . '/estoque.json') ? json_decode((string)@file_get_contents(DATA_DIR . '/estoque.json'), true) : null;
    foreach (array_slice((array)($e['movimentos'] ?? []), -3000) as $m) { $x = trim((string)($m['obra'] ?? '')); if ($x !== '') $o[mb_strtoupper($x)] = 1; }
    return array_slice(array_keys($o), 0, 300);
}

/* ------------------------------ escrita ------------------------------ */

function loc_salvar(array &$d, array $in, $user) {
    $id = loc_str($in['id'] ?? '', 20);
    $f = [
        'fornecedor' => mb_strtoupper(loc_str($in['fornecedor'] ?? '', 120)),
        'equipamento' => loc_str($in['equipamento'] ?? '', 160),
        'categoria' => loc_str($in['categoria'] ?? '', 60),
        'patrimonio' => loc_str($in['patrimonio'] ?? '', 60),
        'contrato' => loc_str($in['contrato'] ?? '', 80),
        'pc' => preg_replace('/\D/', '', (string)($in['pc'] ?? '')),
        'quantidade' => max(1, min(999, (int)($in['quantidade'] ?? 1))),
        'tarifa_tipo' => isset(LOC_TARIFAS[$in['tarifa_tipo'] ?? '']) ? $in['tarifa_tipo'] : 'DIARIA',
        'tarifa_valor' => loc_num($in['tarifa_valor'] ?? 0),
        'minimo_dias' => max(0, min(365, (int)($in['minimo_dias'] ?? 0))),
        'previsao_devolucao' => loc_data($in['previsao_devolucao'] ?? '', false, 'previsão de devolução'),
        'responsavel' => loc_str($in['responsavel'] ?? '', 80),
        'observacao' => loc_str($in['observacao'] ?? '', 600),
    ];
    if ($f['fornecedor'] === '') loc_fail('Informe o fornecedor (locadora).');
    if ($f['equipamento'] === '') loc_fail('Informe o equipamento.');
    if ($f['tarifa_valor'] < 0) loc_fail('Valor da locação inválido.');
    if ($id === '') {
        $inicio = loc_data($in['inicio'] ?? '', false, 'data de início');
        $local = loc_local($in['local'] ?? LOC_MATRIZ);
        $d['seq'] = (int)$d['seq'] + 1;
        $l = $f + ['id' => sprintf('LOC-%04d', $d['seq']), 'inicio' => $inicio, 'fim' => null, 'status' => 'ATIVA', 'local_atual' => $local,
            'movimentos' => [['tipo' => 'INICIO', 'data' => $inicio, 'local' => $local, 'obs' => 'Retirada na locadora', 'usuario' => $user, 'em' => date('c')]],
            'extras' => [], 'criado_em' => date('c'), 'criado_por' => $user];
        $frete = loc_num($in['frete_entrega'] ?? 0);
        if ($frete != 0) $l['extras'][] = ['id' => 'X1', 'tipo' => 'FRETE_ENTREGA', 'data' => $inicio ?: loc_hoje(), 'valor' => $frete, 'local' => $local, 'descricao' => 'Frete de entrega', 'usuario' => $user, 'em' => date('c')];
        if ($inicio && $l['previsao_devolucao'] && $l['previsao_devolucao'] < $inicio) loc_fail('A previsão de devolução é anterior ao início.');
        $d['locacoes'][] = $l;
        return loc_view($l);
    }
    $l = &loc_get($d, $id);
    $hist = [];
    foreach ($f as $c => $v) if (($l[$c] ?? null) !== $v) { $hist[] = $c; $l[$c] = $v; }
    // início pode ser corrigido (move também o movimento INICIO)
    if (array_key_exists('inicio', $in)) {
        $ini = loc_data($in['inicio'], false, 'data de início');
        if ($ini !== $l['inicio']) {
            if ($ini) foreach ($l['movimentos'] as $m) if ($m['tipo'] === 'TRANSFERENCIA' && !empty($m['data']) && $m['data'] < $ini) loc_fail('Há transferência anterior a essa data de início.');
            if ($ini && $l['fim'] && $l['fim'] < $ini) loc_fail('A data de início é posterior à devolução.');
            $l['inicio'] = $ini; foreach ($l['movimentos'] as &$m) if ($m['tipo'] === 'INICIO') $m['data'] = $ini; unset($m);
            $hist[] = 'inicio';
        }
    }
    if ($l['status'] === 'DEVOLVIDA' && array_key_exists('nf_devolucao', $in)) $l['nf_devolucao'] = loc_str($in['nf_devolucao'], 40);
    if ($l['status'] === 'DEVOLVIDA' && !empty($in['fim'])) {
        $fim = loc_data($in['fim'], true, 'data de devolução');
        if ($l['inicio'] && $fim < $l['inicio']) loc_fail('A devolução não pode ser antes do início.');
        if ($fim !== $l['fim']) { $l['fim'] = $fim; foreach ($l['movimentos'] as &$m) if ($m['tipo'] === 'DEVOLUCAO') $m['data'] = $fim; unset($m); $hist[] = 'fim'; }
    }
    if ($hist) $l['edicoes'][] = ['em' => date('c'), 'usuario' => $user, 'campos' => $hist];
    $l['atualizado_em'] = date('c');
    return loc_view($l);
}

function loc_transferir(array &$d, array $in, $user) {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20));
    if ($l['status'] !== 'ATIVA') loc_fail('Só é possível transferir uma locação ativa.');
    $data = loc_data($in['data'] ?? loc_hoje(), true, 'data da transferência');
    $local = loc_local($in['local'] ?? '');
    if ($l['inicio'] && $data < $l['inicio']) loc_fail('A transferência não pode ser antes do início da locação.');
    $ult = '';
    foreach ($l['movimentos'] as $m) if ((string)$m['data'] > $ult) $ult = (string)$m['data'];
    if ($data < $ult) loc_fail('Já existe movimentação posterior a essa data (' . date('d/m/Y', strtotime($ult)) . ').');
    if ($local === $l['local_atual']) loc_fail('O equipamento já está em ' . $local . '.');
    $l['movimentos'][] = ['tipo' => 'TRANSFERENCIA', 'data' => $data, 'local' => $local, 'de' => $l['local_atual'], 'obs' => loc_str($in['obs'] ?? '', 300), 'usuario' => $user, 'em' => date('c')];
    $l['local_atual'] = $local;
    $frete = loc_num($in['frete'] ?? 0);
    if ($frete != 0) loc_add_extra($l, ['tipo' => 'FRETE_TRANSFERENCIA', 'data' => $data, 'valor' => $frete, 'local' => $local, 'descricao' => 'Frete de transferência'], $user);
    return loc_view($l);
}

function loc_add_extra(array &$l, array $in, $user) {
    $tipo = isset(LOC_EXTRAS[$in['tipo'] ?? '']) ? $in['tipo'] : 'OUTRO';
    $valor = loc_num($in['valor'] ?? 0);
    if ($valor == 0) loc_fail('Informe o valor do custo.');
    if ($valor < 0 && $tipo !== 'AJUSTE_FATURA') loc_fail('Valor negativo só em "Ajuste de fatura".');
    $data = loc_data($in['data'] ?? loc_hoje(), true, 'data do custo');
    $n = 1; foreach ($l['extras'] as $x) $n = max($n, (int)substr($x['id'], 1) + 1);
    $x = ['id' => 'X' . $n, 'tipo' => $tipo, 'data' => $data, 'valor' => $valor, 'local' => isset($in['local']) && trim((string)$in['local']) !== '' ? loc_local($in['local']) : $l['local_atual'],
        'descricao' => loc_str($in['descricao'] ?? '', 200) ?: LOC_EXTRAS[$tipo], 'documento' => loc_str($in['documento'] ?? '', 60), 'usuario' => $user, 'em' => date('c')];
    $l['extras'][] = $x;
    return $x;
}

function loc_extra(array &$d, array $in, $user) {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20));
    if (!empty($in['remover'])) {
        $antes = count($l['extras']);
        $l['extras'] = array_values(array_filter($l['extras'], fn($x) => $x['id'] !== $in['remover']));
        if ($antes === count($l['extras'])) loc_fail('Custo não encontrado.');
        $l['edicoes'][] = ['em' => date('c'), 'usuario' => $user, 'campos' => ['extra removido ' . $in['remover']]];
        return loc_view($l);
    }
    loc_add_extra($l, $in, $user);
    return loc_view($l);
}

function loc_devolver(array &$d, array $in, $user) {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20));
    if ($l['status'] !== 'ATIVA') loc_fail('Esta locação já foi encerrada.');
    $data = loc_data($in['data'] ?? '', false, 'data de devolução');
    $ult = '';
    foreach ($l['movimentos'] as $m) if ((string)$m['data'] > $ult) $ult = (string)$m['data'];
    if ($data && $data < $ult) loc_fail('A devolução não pode ser antes da última movimentação (' . date('d/m/Y', strtotime($ult)) . ').');
    if (!$data && $l['inicio']) loc_fail('Informe a data da devolução (a locação tem data de início e custo diário).');
    $l['fim'] = $data; $l['status'] = 'DEVOLVIDA';
    $l['nf_devolucao'] = loc_str($in['nf_devolucao'] ?? '', 40);
    $l['movimentos'][] = ['tipo' => 'DEVOLUCAO', 'data' => $data, 'local' => $l['local_atual'], 'obs' => loc_str($in['obs'] ?? '', 300), 'usuario' => $user, 'em' => date('c')];
    $frete = loc_num($in['frete'] ?? 0);
    if ($frete != 0) loc_add_extra($l, ['tipo' => 'FRETE_RETIRADA', 'data' => $data ?: loc_hoje(), 'valor' => $frete, 'descricao' => 'Frete de devolução'], $user);
    $av = loc_num($in['avaria'] ?? 0);
    if ($av > 0) loc_add_extra($l, ['tipo' => 'AVARIA', 'data' => $data ?: loc_hoje(), 'valor' => $av, 'descricao' => loc_str($in['avaria_desc'] ?? '', 200) ?: 'Avaria na devolução'], $user);
    return loc_view($l);
}

function loc_reabrir(array &$d, array $in, $user) {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20));
    if ($l['status'] === 'ATIVA') loc_fail('A locação já está ativa.');
    $l['status'] = 'ATIVA'; $l['fim'] = null;
    $l['movimentos'] = array_values(array_filter($l['movimentos'], fn($m) => $m['tipo'] !== 'DEVOLUCAO'));
    $l['edicoes'][] = ['em' => date('c'), 'usuario' => $user, 'campos' => ['reaberta']];
    return loc_view($l);
}

function loc_excluir(array &$d, array $in, $user) {
    $id = loc_str($in['id'] ?? '', 20);
    $l = loc_get($d, $id);
    $d['excluidas'][] = $l + ['excluida_em' => date('c'), 'excluida_por' => $user];
    $d['locacoes'] = array_values(array_filter($d['locacoes'], fn($x) => $x['id'] !== $id));
    return ['id' => $id];
}

/** Desfaz a última transferência (lançada por engano). */
function loc_desfazer_transferencia(array &$d, array $in, $user) {
    $l = &loc_get($d, loc_str($in['id'] ?? '', 20));
    if ($l['status'] !== 'ATIVA') loc_fail('Reabra a locação antes de alterar as transferências.');
    for ($i = count($l['movimentos']) - 1; $i >= 0; $i--) if ($l['movimentos'][$i]['tipo'] === 'TRANSFERENCIA') {
        $m = $l['movimentos'][$i]; array_splice($l['movimentos'], $i, 1);
        $l['local_atual'] = $m['de'] ?? LOC_MATRIZ;
        $l['edicoes'][] = ['em' => date('c'), 'usuario' => $user, 'campos' => ['transferência desfeita: ' . $m['local'] . ' em ' . $m['data']]];
        return loc_view($l);
    }
    loc_fail('Não há transferência para desfazer.');
}

function loc_csv(array $d) {
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Fornecedor', 'Equipamento', 'Categoria', 'Patrimônio', 'Contrato', 'PC', 'Qtd', 'Tarifa', 'Valor tarifa', 'Valor/dia', 'Início', 'Previsão', 'Devolução', 'Status', 'NF devolução', 'Local atual', 'Dias', 'Aluguel', 'Extras', 'Total', 'Rateio por local'], ';');
    foreach ($d['locacoes'] as $l) {
        $v = loc_view($l); $c = $v['custo'];
        $rat = implode(' | ', array_map(fn($k, $x) => $k . ': ' . number_format($x, 2, ',', '.'), array_keys($c['por_local']), $c['por_local']));
        fputcsv($out, [$v['id'], $v['fornecedor'], $v['equipamento'], $v['categoria'], $v['patrimonio'], $v['contrato'], $v['pc'], $v['quantidade'], $v['tarifa_tipo'],
            number_format($v['tarifa_valor'], 2, ',', '.'), number_format($c['valor_dia'], 2, ',', '.'), $v['inicio'], $v['previsao_devolucao'], $v['fim'], $v['status'], $v['nf_devolucao'] ?? '', $v['local_atual'],
            $c['dias'], number_format($c['aluguel'], 2, ',', '.'), number_format($c['extras'], 2, ',', '.'), number_format($c['total'], 2, ',', '.'), $rat], ';');
    }
    rewind($out);
    return stream_get_contents($out);
}
