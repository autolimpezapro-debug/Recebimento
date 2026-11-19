<?php
/**
 * Recebimento Drilling — Inventário e Acuracidade (v3.55.0).
 * Dois tipos de contagem sobre o mesmo motor: GERAL (balanço) e CICLICA (plano por curva ABC).
 *  - Contagem CEGA (quem conta não vê o saldo do sistema), saldo congelado na criação e corrigido pelas movimentações durante a contagem.
 *  - 1ª contagem dentro da tolerância = OK; fora = RECONTAR; 2ª contagem fora = APROVAÇÃO do gestor (com motivo). Só então ajusta.
 *  - Ajustes viram AJUSTE_INVENTARIO auditado no Estoque (nunca apaga movimentos). Acuracidade (IRA) = acertos da 1ª contagem / itens contados.
 * Dados: data/contagens.json. A aba antiga "Inventário" do Estoque continua funcionando.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';
require_once __DIR__ . '/romaneios_lib.php';   // prt_str/prt_valor/prt_fail

const CNT_FILE = DATA_DIR . '/contagens.json';
const CNT_LOCK = DATA_DIR . '/.contagens.lock';
const CNT_CFG = ['tolerancia_pct' => 2.0, 'tolerancia_abs' => 0.0, 'meta_acuracidade' => 98.0, 'freq_dias' => ['A' => 30, 'B' => 90, 'C' => 180], 'qtd_dia' => 20, 'cega' => true];

function cnt_read(): array {
    $v = ['versao' => 1, 'seq' => 0, 'config' => CNT_CFG, 'contagens' => []];
    if (!is_file(CNT_FILE)) return $v;
    $d = json_decode((string)file_get_contents(CNT_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/contagens.json. Preserve o arquivo e restaure o backup.', 500);
    $d += $v; $d['config'] = array_replace_recursive(CNT_CFG, (array)$d['config']); return $d;
}
function cnt_write(array $d): void {
    $tmp = CNT_FILE . '.tmp'; $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar a contagem.', 500);
    if (is_file(CNT_FILE)) @copy(CNT_FILE, CNT_FILE . '.backup.json');
    if (!rename($tmp, CNT_FILE)) prt_fail('Falha ao persistir a contagem.', 500);
}
function cnt_with_lock(callable $fn) {
    $fp = @fopen(CNT_LOCK, 'c'); if (!$fp) prt_fail('Não foi possível travar as contagens.', 500);
    flock($fp, LOCK_EX); try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function cnt_ts(?string $s): int { $t = $s ? strtotime($s) : false; return $t ?: 0; }

/** Curva ABC por giro (nº de saídas em 12 meses) e valor em estoque: A = top 20% do giro ou top 10% do valor; B = demais com saída em 12 meses; C = sem giro. */
function cnt_abc(array $db): array {
    $corte = time() - 365 * 86400; $giro = []; $val = [];
    foreach ($db['movimentos'] ?? [] as $m) if (($m['tipo'] ?? '') === 'SAIDA' && cnt_ts($m['em'] ?? '') >= $corte) $giro[$m['codigo']] = ($giro[$m['codigo']] ?? 0) + 1;
    foreach ($db['itens'] ?? [] as $c => $it) $val[$c] = max(0, (float)$it['saldo']) * (float)($it['custo_medio'] ?? 0);
    arsort($giro); arsort($val); $n = max(1, count($db['itens'] ?? []));
    $topG = array_slice(array_keys($giro), 0, (int)ceil($n * .20)); $topG2 = array_keys($giro);   // B = teve alguma saída em 12 meses
    $topV = array_slice(array_keys(array_filter($val)), 0, (int)ceil($n * .10)); $out = [];
    foreach ($db['itens'] ?? [] as $c => $_) $out[$c] = (in_array($c, $topG, true) || in_array($c, $topV, true)) ? 'A' : (in_array($c, $topG2, true) ? 'B' : 'C');
    return $out;
}
/** Variação líquida do item por movimentações no intervalo (t0, t1]. */
function cnt_delta(array $db, string $cod, int $t0, int $t1): float {
    $s = 0.0;
    foreach ($db['movimentos'] ?? [] as $m) {
        if (($m['codigo'] ?? '') !== $cod) continue; $t = cnt_ts($m['em'] ?? ''); if ($t <= $t0 || $t > $t1) continue;
        if (isset($m['saldo_posterior'], $m['saldo_anterior'])) $s += (float)$m['saldo_posterior'] - (float)$m['saldo_anterior'];
    }
    return round($s, 4);
}
function cnt_dentro(float $dif, float $ref, array $cfg): bool {
    $d = abs($dif); if ($d < 0.00001) return true;
    return $d <= (float)$cfg['tolerancia_abs'] + 0.00001 || ($ref > 0 && $d <= $ref * (float)$cfg['tolerancia_pct'] / 100 + 0.00001);
}
/** Recalcula status do item a partir das rodadas. */
function cnt_status_item(array &$l, array $cfg): void {
    if (in_array($l['status'] ?? '', ['APROVADO', 'REJEITADO', 'AJUSTADO', 'NAO_CONTADO'], true) && !empty($l['decisao'])) return;
    $r = $l['rodadas'] ?? [];
    if (!$r) { $l['status'] = 'PENDENTE'; $l['diferenca'] = null; return; }
    $u = end($r); $l['diferenca'] = round($u['qtd'] - $u['ref'], 4); $l['contado'] = $u['qtd'];
    $ok = cnt_dentro($l['diferenca'], $u['ref'], $cfg);
    $l['status'] = count($r) === 1 ? ($ok ? 'OK' : 'RECONTAR') : ($ok ? 'OK_RECONTAGEM' : 'APROVACAO');
}
function cnt_view(array $c, bool $cego = true): array {
    $v = $c; $v['itens'] = array_values($c['itens']);
    $cont = array_count_values(array_column($v['itens'], 'status'));
    $v['resumo'] = ['total' => count($v['itens']), 'pendente' => $cont['PENDENTE'] ?? 0, 'recontar' => $cont['RECONTAR'] ?? 0, 'aprovacao' => $cont['APROVACAO'] ?? 0,
        'ok' => ($cont['OK'] ?? 0) + ($cont['OK_RECONTAGEM'] ?? 0) + ($cont['APROVADO'] ?? 0) + ($cont['AJUSTADO'] ?? 0)];
    $v['resumo']['contados'] = $v['resumo']['total'] - $v['resumo']['pendente'];
    if ($cego && ($c['status'] ?? '') === 'CONTANDO') foreach ($v['itens'] as &$l) {
        unset($l['saldo_congelado'], $l['diferenca'], $l['contado'], $l['valor_dif']);
        $l['rodadas'] = array_map(fn($r) => ['em' => $r['em'], 'por' => $r['por']], $l['rodadas'] ?? []); $l['n_rodadas'] = count($l['rodadas']);
    } unset($l);
    return $v;
}
function &cnt_get(array &$d, string $id) { if (!isset($d['contagens'][$id])) prt_fail('Contagem não encontrada.', 404); return $d['contagens'][$id]; }

function cnt_ultima_contagem(array $d): array {
    $u = [];
    foreach ($d['contagens'] as $c) foreach ($c['itens'] as $cod => $l) foreach ($l['rodadas'] ?? [] as $r) { $t = substr((string)$r['em'], 0, 10); if (!isset($u[$cod]) || $t > $u[$cod]['data']) $u[$cod] = ['data' => $t, 'div' => ($l['diferenca'] ?? 0) != 0]; }
    return $u;
}
/** Plano cíclico: itens vencidos segundo a frequência da classe (A/B/C), os nunca contados primeiro dentro da classe. */
function cnt_sugestao(array $d, array $db, int $n): array {
    $abc = cnt_abc($db); $ult = cnt_ultima_contagem($d); $cfg = $d['config']; $hoje = strtotime(date('Y-m-d')); $lista = []; $venc = ['A' => 0, 'B' => 0, 'C' => 0]; $emAberto = [];
    foreach ($d['contagens'] as $c) if (($c['status'] ?? '') === 'CONTANDO') foreach ($c['itens'] as $cod => $_) $emAberto[$cod] = true;
    foreach ($db['itens'] as $cod => $it) {
        if (isset($emAberto[$cod])) continue;
        if ((float)$it['saldo'] <= 0 && empty($it['ultimo_mov'])) { /* item zerado: só entra se nunca contado e com histórico */ }
        $cl = $abc[$cod] ?? 'C'; $freq = (int)($cfg['freq_dias'][$cl] ?? 180); $dt = $ult[$cod]['data'] ?? null;
        $dias = $dt ? (int)floor(($hoje - strtotime($dt)) / 86400) : 9999;
        if ($dias < $freq) continue;
        $venc[$cl]++;
        $lista[] = ['codigo' => $cod, 'descricao' => $it['descricao'], 'unidade' => $it['unidade'] ?? 'UN', 'localizacao' => $it['localizacao'] ?? '', 'abc' => $cl, 'dias' => $dt ? $dias : null, 'freq' => $freq,
            'atraso' => $dt ? round($dias / $freq, 2) : 99, 'ultima_divergente' => !empty($ult[$cod]['div']), 'saldo' => (float)$it['saldo']];
    }
    usort($lista, fn($a, $b) => strcmp($a['abc'], $b['abc']) ?: ($b['ultima_divergente'] <=> $a['ultima_divergente']) ?: ($b['atraso'] <=> $a['atraso']) ?: strcmp($a['localizacao'], $b['localizacao']));
    return ['vencidos' => $venc, 'total_vencidos' => array_sum($venc), 'sugeridos' => array_slice($lista, 0, max(1, min(200, $n)))];
}
function cnt_criar(array &$d, array $db, array $in, string $user): array {
    $tipo = ($in['tipo'] ?? '') === 'CICLICA' ? 'CICLICA' : 'GERAL'; $esc = (string)($in['escopo'] ?? 'TODOS'); $filtro = prt_str($in['filtro'] ?? '', 200);
    $abc = cnt_abc($db); $sel = [];
    $lista = array_filter(array_map('trim', preg_split('/[,;\n]+/', $filtro)));
    foreach ($db['itens'] as $cod => $it) {
        if ($esc === 'CATEGORIA' && ($it['categoria'] ?? '') !== $filtro) continue;
        if ($esc === 'LOCALIZACAO' && mb_strtoupper((string)($it['localizacao'] ?? '')) !== mb_strtoupper($filtro)) continue;
        if ($esc === 'ABC' && ($abc[$cod] ?? 'C') !== strtoupper($filtro)) continue;
        if ($esc === 'ITENS' && !in_array($cod, $lista, true)) continue;
        if ($esc === 'LISTA' && !in_array($cod, (array)($in['codigos'] ?? []), true)) continue;
        if (!empty($in['so_com_saldo']) && (float)$it['saldo'] <= 0) continue;
        $sel[$cod] = $it;
    }
    if (!$sel) prt_fail('Nenhum item atende ao escopo escolhido.');
    $d['seq']++; $id = 'CNT-' . date('Y') . '-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT); $t0 = date('c'); $itens = [];
    foreach ($sel as $cod => $it) $itens[$cod] = ['codigo' => $cod, 'sku' => preg_match('/^EST-/', $cod) ? '' : $cod, 'descricao' => $it['descricao'], 'unidade' => $it['unidade'] ?? 'UN', 'localizacao' => $it['localizacao'] ?? '', 'categoria' => $it['categoria'] ?? '',
        'abc' => $abc[$cod] ?? 'C', 'custo' => round((float)($it['custo_medio'] ?? 0), 4), 'saldo_congelado' => round((float)$it['saldo'], 4), 'rodadas' => [], 'status' => 'PENDENTE', 'diferenca' => null];
    uasort($itens, fn($a, $b) => strcmp($a['localizacao'], $b['localizacao']) ?: strcmp($a['descricao'], $b['descricao']));
    $d['contagens'][$id] = ['id' => $id, 'tipo' => $tipo, 'nome' => prt_str($in['nome'] ?? '', 100) ?: ($tipo === 'CICLICA' ? 'Contagem cíclica ' . date('d/m') : 'Inventário geral ' . date('d/m/Y')), 'escopo' => $esc, 'filtro' => $filtro, 'status' => 'CONTANDO',
        'criado_em' => $t0, 'criado_por' => $user, 'congelado_em' => $t0, 'cega' => (bool)$d['config']['cega'], 'itens' => $itens, 'historico' => [['em' => $t0, 'por' => $user, 'acao' => 'Contagem criada (' . count($itens) . ' itens)']]];
    return $d['contagens'][$id];
}
/** itens: [{codigo, qtd, em?}] — em = hora em que contou (contagem offline). Idempotente por (codigo, em). */
function cnt_contar(array &$d, array $db, string $id, array $itens, string $user): array {
    $c = &cnt_get($d, $id); if ($c['status'] !== 'CONTANDO') prt_fail('Esta contagem já foi encerrada.', 409);
    $t0 = cnt_ts($c['congelado_em']); $agora = time(); $salvos = 0; $dup = 0; $ign = [];
    foreach ($itens as $x) {
        $cod = (string)($x['codigo'] ?? ''); if (!isset($c['itens'][$cod])) { $ign[] = $cod; continue; }
        $q = $x['qtd'] ?? null; if ($q === null || $q === '') continue; if (!is_numeric(str_replace(',', '.', (string)$q)) || (float)str_replace(',', '.', (string)$q) < 0) prt_fail('Quantidade inválida em ' . $c['itens'][$cod]['descricao']);
        $q = round((float)str_replace(',', '.', (string)$q), 4);
        $tc = cnt_ts((string)($x['em'] ?? '')) ?: $agora; if ($tc > $agora) $tc = $agora; if ($tc < $t0) $tc = $t0;
        $l = &$c['itens'][$cod]; $em = date('c', $tc);
        foreach ($l['rodadas'] as $r) if ($r['em'] === $em && abs($r['qtd'] - $q) < 0.00001) { $dup++; continue 2; }
        $l['saldo_congelado'] ??= 0; $ref = round($l['saldo_congelado'] + cnt_delta($db, $cod, $t0, $tc), 4);
        $rod = ['qtd' => $q, 'ref' => $ref, 'em' => $em, 'por' => $user, 'obs' => prt_str($x['obs'] ?? '', 120)];
        $n = count($l['rodadas']);
        if ($n === 0) $l['rodadas'][] = $rod;
        elseif ($l['status'] === 'RECONTAR' && $n === 1) $l['rodadas'][] = $rod;
        elseif ($n === 1) $l['rodadas'][0] = $rod;                       // ainda OK: permite corrigir a 1ª contagem
        else $l['rodadas'][$n - 1] = $rod;                               // corrige a recontagem
        unset($l['decisao']); cnt_status_item($l, $d['config']); $salvos++; unset($l);
    }
    $c['atualizado_em'] = date('c');
    return ['salvos' => $salvos, 'duplicados' => $dup, 'ignorados' => $ign];
}
function cnt_decidir(array &$d, string $id, string $cod, string $acao, string $motivo, string $user): void {
    $c = &cnt_get($d, $id); if ($c['status'] !== 'CONTANDO') prt_fail('Esta contagem já foi encerrada.', 409);
    if (!isset($c['itens'][$cod])) prt_fail('Item não encontrado nesta contagem.', 404); $l = &$c['itens'][$cod];
    if ($l['status'] !== 'APROVACAO') prt_fail('Só item que continuou divergente após a recontagem precisa de decisão.');
    if (mb_strlen(trim($motivo)) < 3) prt_fail('Informe o motivo da decisão (ex.: avaria, saída sem baixa, erro de lançamento).');
    $l['status'] = $acao === 'aprovar' ? 'APROVADO' : 'REJEITADO'; $l['decisao'] = ['acao' => $acao, 'motivo' => prt_str($motivo, 200), 'por' => $user, 'em' => date('c')];
    $c['historico'][] = ['em' => date('c'), 'por' => $user, 'acao' => ($acao === 'aprovar' ? 'Ajuste aprovado' : 'Ajuste rejeitado') . ' — ' . $cod . ': ' . $l['decisao']['motivo']];
}
function cnt_estatisticas(array $c): array {
    $cont = 0; $ac = 0; $vSist = 0.0; $vAbs = 0.0; $vLiq = 0.0; $ex = 0;
    foreach ($c['itens'] as $l) { if (empty($l['rodadas'])) continue; $r1 = $l['rodadas'][0]; $cont++; $dif1 = $r1['qtd'] - $r1['ref'];
        if (cnt_dentro($dif1, $r1['ref'], $c['config_aplicada'] ?? CNT_CFG)) $ac++; if (abs($dif1) < 0.00001) $ex++;
        $vSist += abs($r1['ref']) * $l['custo']; $vAbs += abs($dif1) * $l['custo']; $vLiq += $dif1 * $l['custo']; }
    return ['contados' => $cont, 'acertos' => $ac, 'exatos' => $ex, 'ira_itens' => $cont ? round($ac / $cont * 100, 2) : null, 'ira_valor' => $vSist > 0 ? round(max(0, 1 - $vAbs / $vSist) * 100, 2) : null, 'dif_valor_abs' => round($vAbs, 2), 'dif_valor_liq' => round($vLiq, 2)];
}
function cnt_aplicar(array &$d, array &$db, string $id, bool $ignorarPendentes, string $user): array {
    $c = &cnt_get($d, $id); if ($c['status'] !== 'CONTANDO') prt_fail('Esta contagem já foi encerrada.', 409);
    $r = array_count_values(array_column($c['itens'], 'status'));
    if (($r['RECONTAR'] ?? 0) || ($r['APROVACAO'] ?? 0)) prt_fail(($r['RECONTAR'] ?? 0) . ' item(ns) aguardando recontagem e ' . ($r['APROVACAO'] ?? 0) . ' aguardando decisão do gestor. Resolva antes de aplicar.', 422);
    if (($r['PENDENTE'] ?? 0) && !$ignorarPendentes) prt_fail($r['PENDENTE'] . ' item(ns) ainda não contados. Conte, ou aplique ignorando os pendentes.', 422);
    $ajustes = 0; $movs = []; $agora = time(); $t0 = cnt_ts($c['congelado_em']);
    foreach ($c['itens'] as $cod => &$l) {
        if ($l['status'] === 'PENDENTE') { $l['status'] = 'NAO_CONTADO'; $l['decisao'] = ['acao' => 'ignorado', 'por' => $user, 'em' => date('c'), 'motivo' => 'não contado']; continue; }
        if (!in_array($l['status'], ['OK', 'OK_RECONTAGEM', 'APROVADO'], true)) continue;
        $dif = (float)$l['diferenca'];
        if (abs($dif) >= 0.00001) {
            $atual = (float)$db['itens'][$cod]['saldo']; $novo = max(0, round($atual + $dif, 4));   // aplica a DIFERENÇA sobre o saldo de hoje (preserva o que se moveu depois da contagem)
            $m = est_apply_movement($db, ['tipo' => 'AJUSTE_INVENTARIO', 'codigo' => $cod, 'quantidade' => $novo, 'origem' => 'INVENTARIO', 'documento' => $id, 'usuario' => $user,
                'observacao' => 'Inventário ' . $id . ': ' . ($dif > 0 ? '+' : '') . est_num($dif) . ' (contado ' . est_num($l['contado']) . ' × sistema ' . est_num($l['rodadas'][count($l['rodadas']) - 1]['ref']) . ')' . (!empty($l['decisao']['motivo']) ? ' · ' . $l['decisao']['motivo'] : '')]);
            $l['movimento'] = $m['id']; $movs[] = $m['id']; $ajustes++;
        }
        $l['status'] = 'AJUSTADO'; $l['ajustado_em'] = date('c');
    } unset($l);
    $c['config_aplicada'] = $d['config']; $c['estatisticas'] = cnt_estatisticas($c);
    $c['status'] = 'APLICADA'; $c['aplicada_em'] = date('c'); $c['aplicada_por'] = $user; $c['historico'][] = ['em' => date('c'), 'por' => $user, 'acao' => 'Aplicada: ' . $ajustes . ' ajuste(s) no estoque'];
    return ['ajustes' => $ajustes, 'movimentos' => $movs, 'estatisticas' => $c['estatisticas']];
}
function cnt_painel(array $d, array $db): array {
    $cfg = $d['config']; $lim = date('Y-m-d', strtotime('-90 days')); $ac = 0; $co = 0; $vA = 0.0; $vS = 0.0; $meses = []; $abc = []; $loc = []; $cont = []; $itensDiv = []; $abertas = 0; $lista = [];
    foreach (array_reverse($d['contagens']) as $c) {
        $e = $c['estatisticas'] ?? null;
        $lista[] = ['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'status' => $c['status'], 'criado_em' => $c['criado_em'], 'aplicada_em' => $c['aplicada_em'] ?? null, 'criado_por' => $c['criado_por'], 'resumo' => cnt_view($c)['resumo'], 'estatisticas' => $e];
        if ($c['status'] === 'CONTANDO') $abertas++;
        if ($c['status'] !== 'APLICADA') continue;
        $m = substr((string)$c['aplicada_em'], 0, 7); $meses[$m] ??= ['mes' => $m, 'contados' => 0, 'acertos' => 0, 'dif_abs' => 0.0]; $meses[$m]['contados'] += $e['contados']; $meses[$m]['acertos'] += $e['acertos']; $meses[$m]['dif_abs'] += $e['dif_valor_abs'];
        $recente = substr((string)$c['aplicada_em'], 0, 10) >= $lim; if ($recente) { $co += $e['contados']; $ac += $e['acertos']; $vA += $e['dif_valor_abs']; }
        foreach ($c['itens'] as $cod => $l) { if (empty($l['rodadas'])) continue; $r1 = $l['rodadas'][0]; $ok = cnt_dentro($r1['qtd'] - $r1['ref'], $r1['ref'], $c['config_aplicada'] ?? $cfg);
            foreach ([['abc', $l['abc']], ['loc', $l['localizacao'] ?: '(sem endereço)'], ['cont', $r1['por']]] as [$k, $v]) { $a = &${$k}; $a[$v] ??= ['chave' => $v, 'contados' => 0, 'acertos' => 0]; $a[$v]['contados']++; $a[$v]['acertos'] += $ok ? 1 : 0; unset($a); }
            if (!$ok) { $itensDiv[$cod] ??= ['codigo' => $cod, 'descricao' => $l['descricao'], 'divergencias' => 0, 'valor_abs' => 0.0, 'ultima' => '']; $itensDiv[$cod]['divergencias']++; $itensDiv[$cod]['valor_abs'] += abs($r1['qtd'] - $r1['ref']) * $l['custo']; $itensDiv[$cod]['ultima'] = substr((string)$c['aplicada_em'], 0, 10); } }
    }
    $pct = fn($x) => array_values(array_map(fn($r) => $r + ['ira' => $r['contados'] ? round($r['acertos'] / $r['contados'] * 100, 1) : null], $x));
    ksort($meses); $sug = cnt_sugestao($d, $db, 1); uasort($itensDiv, fn($a, $b) => $b['divergencias'] <=> $a['divergencias'] ?: $b['valor_abs'] <=> $a['valor_abs']);
    return ['config' => $cfg, 'kpis' => ['ira_90d' => $co ? round($ac / $co * 100, 2) : null, 'contados_90d' => $co, 'dif_valor_abs_90d' => round($vA, 2), 'meta' => $cfg['meta_acuracidade'], 'abertas' => $abertas, 'vencidos' => $sug['vencidos'], 'total_vencidos' => $sug['total_vencidos'], 'itens_cadastrados' => count($db['itens'])],
        'meses' => array_map(fn($m) => $m + ['ira' => $m['contados'] ? round($m['acertos'] / $m['contados'] * 100, 1) : null], array_values($meses)), 'por_abc' => $pct($abc), 'por_local' => array_slice($pct($loc), 0, 30), 'por_contador' => $pct($cont),
        'itens_divergentes' => array_slice(array_values($itensDiv), 0, 15), 'contagens' => $lista];
}
function cnt_csv(array $c): string {
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"'; $n = fn($v) => $v === null ? '' : str_replace('.', ',', (string)$v);
    $o = "\xEF\xBB\xBF" . 'Contagem;' . $q($c['id']) . ';Nome;' . $q($c['nome']) . ';Status;' . $q($c['status']) . "\r\nCodigo;Descricao;Un;Endereco;ABC;Saldo sistema (ref);1a contagem;2a contagem;Diferenca;Custo;Valor dif;Status;Contador;Decisao\r\n";
    foreach ($c['itens'] as $l) { $r = $l['rodadas'] ?? []; $u = $r ? end($r) : null;
        $o .= implode(';', [$q($l['codigo']), $q($l['descricao']), $q($l['unidade']), $q($l['localizacao']), $l['abc'], $n($u['ref'] ?? $l['saldo_congelado'] ?? null), $n($r[0]['qtd'] ?? null), $n($r[1]['qtd'] ?? null), $n($l['diferenca']), $n($l['custo']), $n(isset($l['diferenca']) ? round($l['diferenca'] * $l['custo'], 2) : null), $l['status'], $q($r[0]['por'] ?? ''), $q($l['decisao']['motivo'] ?? '')]) . "\r\n"; }
    return $o;
}
function cnt_folha_html(array $c): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $linhas = '';
    foreach ($c['itens'] as $i => $l) $linhas .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($l['localizacao'] ?: '—') . '</td><td>' . $e($l['codigo']) . '</td><td>' . $e($l['descricao']) . '</td><td>' . $e($l['unidade']) . '</td><td class="b"></td><td class="b"></td><td></td></tr>';
    return '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Folha de contagem ' . $e($c['id']) . '</title><style>body{font:12px Arial;margin:14px}h1{font-size:16px;margin:0}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #666;padding:5px 6px}th{background:#eee;text-align:left}td.b{width:90px}@media print{button{display:none}tr{page-break-inside:avoid}}</style><button onclick="print()">Imprimir</button>'
        . '<h1>Drilling do Brasil — Folha de contagem ' . $e($c['id']) . '</h1><p>' . $e($c['nome']) . ' · ' . $e(date('d/m/Y H:i', strtotime($c['criado_em']))) . ' · ' . count($c['itens']) . ' itens · Contador: ____________________ Data: ___/___/_____</p>'
        . '<table><thead><tr><th>#</th><th>Endereço</th><th>Código</th><th>Material</th><th>Un.</th><th>1ª contagem</th><th>Recontagem</th><th>Obs.</th></tr></thead><tbody>' . $linhas . '</tbody></table></html>';
}
