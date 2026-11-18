<?php
/**
 * Recebimento Drilling — API do Painel de Indicadores (v3.9.0)
 *
 * Mesma tela do SGA (front-end em /indicadores), mas SEM digitação: todos os números
 * são calculados a partir dos dados do Recebimento Drilling (pedidos, estoque,
 * inventário SAP, coletas e devoluções). Somente leitura dos dados operacionais.
 * A única coisa gravada aqui são as METAS (data/indicadores.json).
 */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$ROOT = dirname(__DIR__);
if (!defined('DATA_DIR')) define('DATA_DIR', $ROOT . '/data');
const KPI_METAS_FILE = DATA_DIR . '/indicadores.json';

require_once $ROOT . '/auth_users.php';
require_once $ROOT . '/perm_lib.php';

function kpi_out(int $code, array $p): never { http_response_code($code); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function kpi_json_file(string $f, $padrao) { $r = @file_get_contents($f); $d = $r ? json_decode($r, true) : null; return is_array($d) ? $d : $padrao; }
function kpi_ts(?string $s): ?int { if (!$s) return null; $t = strtotime($s); return $t === false ? null : $t; }
function kpi_mes_de(?int $t): ?string { return $t === null ? null : date('Y-m', $t); }
function kpi_fim_mes(string $m): int { return strtotime($m . '-01 00:00:00 +1 month') - 1; }

/* ------------------------------------------------------------------ */
/* Acesso: usa a sessão e as permissões do Recebimento Drilling        */
/* ------------------------------------------------------------------ */
rd_auth_session();
$ident = rd_login_info();
$exigir = function_exists('rd_exigir_login') ? rd_exigir_login() : false;
// v3.28.0: LINK PÚBLICO — quem tem o link vê os indicadores sem login, somente leitura.
function kpi_link_publico(): array { $b = kpi_json_file(KPI_METAS_FILE, []); return (array)($b['link_publico'] ?? []); }
$kpiToken = (string)($_SERVER['HTTP_X_KPI_LINK'] ?? $_GET['p'] ?? '');
$kpiLink = kpi_link_publico();
$publico = !$ident && $kpiToken !== '' && !empty($kpiLink['ativo']) && preg_match('/^[A-Za-z0-9_-]{32,64}$/', $kpiToken)
    && hash_equals((string)($kpiLink['token'] ?? ''), $kpiToken);
if ($kpiToken !== '' && !$ident && !$publico) kpi_out(403, ['ok' => false, 'erro' => 'Este link público foi desativado ou é inválido. Peça um novo link ao administrador.']);
if (!$publico) {
    if ($exigir && !$ident) kpi_out(401, ['ok' => false, 'erro' => 'Entre no Recebimento Drilling para ver os indicadores.']);
    if (!rd_perm('dashboard', 'ver')) kpi_out(403, ['ok' => false, 'erro' => 'Seu perfil não tem acesso ao Dashboard/Indicadores.']);
}
$podeMetas = !$publico && (!$exigir || ($ident && (rd_e_admin_principal($ident['username']) || rd_perm('administracao', 'admin'))));
if (empty($_SESSION['kpi_csrf'])) $_SESSION['kpi_csrf'] = bin2hex(random_bytes(32));

/* ------------------------------------------------------------------ */
/* Metas                                                               */
/* ------------------------------------------------------------------ */
function kpi_metas_padrao(): array {
    return ['totalValue' => 700000, 'deadStockValue' => 120000, 'inventoryLossValue' => 15000, 'otif' => 95,
        'avgFulfillmentMinutes' => 60, 'accuracy' => 98, 'criticalItemsCount' => 15, 'stockouts' => 5,
        'collectionsCompleted' => 30, 'collectionsFulfillmentPct' => 95, 'collectionsPending' => 8, 'collectionsLate' => 3,
        'collectionsSlaPct' => 90, 'collectionsAvgHours' => 48, 'returnsCount' => 3, 'returnsValue' => 5000, 'emergencyPurchases' => 5];
}
function kpi_metas(): array {
    $m = kpi_json_file(KPI_METAS_FILE, []);
    return array_merge(kpi_metas_padrao(), array_map('floatval', array_filter((array)($m['metas'] ?? []), 'is_numeric')));
}
/**
 * Leitura de DANFE em PDF no servidor (PHP puro: streams Flate + operadores Tj; sem pdftotext).
 * Completa o que o leitor do navegador não pega: transportadora, frete, quem paga, placa, motorista
 * e os itens com descrição limpa. Retorna [nf => dados].
 */
const KPI_PDF_CACHE = DATA_DIR . '/indicadores_danfe.json';
function kpi_num_br(string $v): float { return (float)str_replace(['.', ','], ['', '.'], trim($v)); }
function kpi_ler_danfe(string $raw): array {
    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) return [];
    $out = [];
    foreach ($m[1] as $st) {
        $d = @gzuncompress($st);
        if ($d === false) $d = @gzinflate($st);
        if ($d === false || !preg_match_all('/\((.*?)(?<!\\\\)\)\s*Tj/s', $d, $t)) continue;
        $txt = implode("\n", array_map('stripcslashes', $t[1]));
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'ISO-8859-1');
        if (!preg_match('/N[ºo°]\.\s*([\d.]+)/u', $txt, $n)) continue;
        $nf = ltrim(str_replace('.', '', $n[1]), '0');
        if ($nf === '' || isset($out[$nf])) continue;
        $r = ['nf' => $nf];
        if (preg_match('/TRANSPORTADOR \/ VOLUMES TRANSPORTADOS\nNOME \/ RAZÃO SOCIAL\n([^\n]*)\nFRETE POR CONTA\n([^\n]*)/u', $txt, $x)) {
            $r['carrier'] = trim($x[1]); $r['frete_por_conta'] = trim($x[2]);
        }
        if (preg_match('/VALOR DO FRETE\n([\d.,]+)/u', $txt, $x)) $r['frete_nf'] = kpi_num_br($x[1]);
        if (preg_match('/NATUREZA DA OPERAÇÃO\n([^\n]+)/u', $txt, $x)) $r['natureza'] = trim($x[1]);
        if (preg_match('/MOTORISTA:\s*([^\n]+)/u', $txt, $x)) $r['motorista'] = trim($x[1]);
        if (preg_match('/PLACA:\s*([^\n]+)/u', $txt, $x)) $r['placa'] = trim($x[1]);
        if (preg_match('/DATA DA EMISSÃO\n(\d{2})\/(\d{2})\/(\d{4})/u', $txt, $x)) $r['emissao'] = "$x[3]-$x[2]-$x[1]";
        // Itens: ancora no NCM (8 dígitos) seguido de CST, CFOP, UN, QTD, VALOR UNIT, VALOR TOTAL.
        $itens = [];
        $L = explode("\n", $txt);
        $ini = array_search('ALÍQ. IPI', $L, true);
        for ($k = ($ini === false ? 0 : $ini + 1); $k < count($L) - 6; $k++) {
            if (!preg_match('/^\d{8}$/', $L[$k]) || !preg_match('/^\d{3}$/', $L[$k + 1]) || !preg_match('/^\d{4}$/', $L[$k + 2])) continue;
            if (!preg_match('/^[\d.]+,\d+$/', $L[$k + 4] ?? '')) continue;
            $desc = trim($L[$k - 1] ?? '');
            $itens[] = ['description' => preg_replace('/\s+/', ' ', $desc), 'qty' => kpi_num_br($L[$k + 4]),
                'unit' => ($L[$k + 3] === 'PÇ' ? 'PC' : mb_substr($L[$k + 3], 0, 8)), 'code' => trim($L[$k - 2] ?? '')];
            $k += 6;
        }
        if ($itens) $r['items'] = $itens;
        $out[$nf] = $r;
    }
    return $out;
}
/** Aplica a leitura do PDF às remessas NOVAS (nunca sobrescreve o que já foi editado à mão). */
function kpi_enriquecer(array $novos): array {
    $cache = kpi_json_file(KPI_PDF_CACHE, []);
    foreach ($novos as &$m) {
        $d = $cache[ltrim((string)$m['nf'], '0')] ?? null;
        if (!$d) continue;
        if (($m['carrier'] ?? '') === '' && !empty($d['carrier'])) $m['carrier'] = mb_substr($d['carrier'], 0, 200);
        $proprio = stripos((string)($d['frete_por_conta'] ?? ''), 'Próprio') !== false || stripos((string)($d['carrier'] ?? ''), 'DRILLING') !== false;
        if (!empty($d['carrier']) || !empty($d['frete_por_conta'])) $m['transport'] = $proprio && empty($d['carrier']) ? 'proprio' : (stripos((string)($d['carrier'] ?? ''), 'DRILLING') !== false ? 'proprio' : (!empty($d['carrier']) ? 'terceiro' : 'proprio'));
        if (($m['carrier'] ?? '') === '' && empty($d['carrier']) && $proprio) $m['carrier'] = 'Drilling (veículo próprio)';
        if ((float)($m['freight'] ?? 0) <= 0 && !empty($d['frete_nf'])) $m['freight'] = round((float)$d['frete_nf'], 2);
        if (!empty($d['items'])) $m['items'] = array_map(fn($i) => ['description' => mb_substr($i['description'], 0, 180), 'qty' => $i['qty'], 'unit' => $i['unit']], $d['items']);
        foreach (['placa' => 'plate', 'motorista' => 'driver', 'frete_por_conta' => 'freightBy'] as $k => $c) if (!empty($d[$k])) $m[$c] = mb_substr($d[$k], 0, 120);
    }
    return $novos;
}

function kpi_hist(array &$b, string $op, int $antes, int $depois): void {
    $b['historico'][] = ['em' => date('c'), 'op' => $op, 'antes' => $antes, 'depois' => $depois, 'usuario' => rd_login_info()['username'] ?? ''];
    $b['historico'] = array_slice($b['historico'], -300);
}

/** Remessas entre obras (NF de remessa) e cadastro de obras: única base digitada/importada do painel. */
function kpi_obras_padrao(): array {
    return [
        ['id' => 'drilling-deposito', 'name' => 'Drilling — Depósito', 'city' => 'São José da Lapa/MG', 'aliases' => ['DEPOSITO', 'DEPÓSITO', 'SAO JOSE DA LAPA', 'DRILLING']],
        ['id' => 'btec-congonhas', 'name' => 'BTEC MRB Terminal Avante', 'city' => 'Congonhas/MG', 'aliases' => ['CONGONHAS', 'BTEC', 'AVANTE']],
        ['id' => 'aura-belvedere', 'name' => 'Aura Belvedere Mall', 'city' => 'Belo Horizonte/MG', 'aliases' => ['AURA', 'BELVEDERE MALL']],
        ['id' => 'grande-sertao', 'name' => 'Grande Sertão', 'city' => 'São João do Paraíso/MG', 'aliases' => ['SAO JOAO DO PARAISO', 'GRANDE SERTAO']],
        ['id' => 'capibaribe', 'name' => 'Ponte Capibaribe', 'city' => 'Recife/PE', 'aliases' => ['RECIFE', 'CAPIBARIBE', 'CORDEIRO']],
        ['id' => 'aro-jardins', 'name' => 'ARO GSA Jardins Belvedere', 'city' => 'Belo Horizonte/MG', 'aliases' => ['JARDINS BELVEDERE', 'ARO GSA']],
        ['id' => 'itaparica', 'name' => 'Ponte Salvador Itaparica', 'city' => 'Itaparica/BA', 'aliases' => ['ITAPARICA', 'VERA CRUZ', 'PARAGUASSU']],
        ['id' => 'sinop', 'name' => 'Obra Sinop', 'city' => 'Sinop/MT', 'aliases' => ['SINOP']],
        ['id' => 'phv-sinval', 'name' => 'PHV Engenharia Ed. Sinval', 'city' => 'Belo Horizonte/MG', 'aliases' => ['SINVAL', 'PHV']],
        ['id' => 'witplan-panamera', 'name' => 'Witplan BTS Panamera Corp', 'city' => 'Belo Horizonte/MG', 'aliases' => ['PANAMERA', 'WITPLAN', 'BTS', 'NOVA LIMA']],
        ['id' => 'agaspar-grauna', 'name' => 'Construtora Agaspar – Ponte Graúna', 'city' => 'São Paulo/SP', 'aliases' => ['AGASPAR', 'GRAUNA']],
        ['id' => 'telar-blumenau', 'name' => 'Telar Blumenau', 'city' => 'Blumenau/SC', 'aliases' => ['TELAR', 'BLUMENAU', 'BLUMENAL']],
        ['id' => 'penedo', 'name' => 'Ponte Penedo – Neópolis', 'city' => 'Penedo/AL', 'aliases' => ['PENEDO', 'NEOPOLIS']],
        ['id' => 'nexa', 'name' => 'Nexa', 'city' => 'Minas Gerais', 'aliases' => ['NEXA']],
        ['id' => 'sogel', 'name' => 'Sogel', 'city' => 'Minas Gerais', 'aliases' => ['SOGEL']],
        ['id' => 'aterpa-vale', 'name' => 'Aterpa (Vale)', 'city' => 'Minas Gerais', 'aliases' => ['ATERPA', 'VALE']],
        ['id' => 'rivoli', 'name' => 'Rivoli', 'city' => 'BA', 'aliases' => ['RIVOLI']],
        ['id' => 'conartes', 'name' => 'Conartes', 'city' => '', 'aliases' => ['CONARTES']],
        ['id' => 'governador-valadares', 'name' => 'Governador Valadares', 'city' => 'Governador Valadares/MG', 'aliases' => ['GOVERNADOR VALADARES', 'VALADARES']],
    ];
}
function kpi_base(): array { $b = kpi_json_file(KPI_METAS_FILE, []); return $b; }
function kpi_gravar_base(array $b): void {
    $b['atualizado_em'] = date('c');
    $tmp = KPI_METAS_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !@rename($tmp, KPI_METAS_FILE))
        kpi_out(500, ['ok' => false, 'erro' => 'Não foi possível gravar os dados do painel.']);
}
function kpi_txt($v, int $n): string { return mb_substr(trim((string)$v), 0, $n); }
function kpi_limpar_obras(array $obras): array {
    $out = [];
    foreach (array_slice($obras, 0, 300) as $o) {
        if (!is_array($o)) continue;
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($o['id'] ?? '')));
        if ($id === '' || isset($out[$id])) continue;
        $out[$id] = ['id' => $id, 'name' => kpi_txt($o['name'] ?? $id, 200), 'city' => kpi_txt($o['city'] ?? '', 120),
            'aliases' => array_values(array_slice(array_filter(array_map(fn($a) => kpi_txt($a, 80), (array)($o['aliases'] ?? [])), 'strlen'), 0, 30))];
    }
    return array_values($out);
}
function kpi_limpar_movimentos(array $movs): array {
    $out = [];
    foreach (array_slice($movs, 0, 20000) as $m) {
        if (!is_array($m)) continue;
        $nf = preg_replace('/[^0-9A-Za-z._\/-]/', '', (string)($m['nf'] ?? ''));
        $mes = (string)($m['month'] ?? '');
        if ($nf === '' || !preg_match('/^\d{4}-\d{2}$/', $mes)) continue;
        $mv = ['nf' => mb_substr($nf, 0, 40), 'month' => $mes, 'kind' => kpi_txt($m['kind'] ?? 'remessa', 32),
            'originId' => isset($m['originId']) ? kpi_txt($m['originId'], 80) : null, 'destId' => isset($m['destId']) ? kpi_txt($m['destId'], 80) : null,
            'value' => round((float)($m['value'] ?? 0), 2), 'carrier' => kpi_txt($m['carrier'] ?? '', 200), 'freight' => round(max(0, (float)($m['freight'] ?? 0)), 2)];
        if (in_array($m['transport'] ?? '', ['proprio', 'terceiro'], true)) $mv['transport'] = $m['transport'];
        foreach (['plate', 'driver', 'freightBy'] as $k) if (!empty($m[$k])) $mv[$k] = kpi_txt($m[$k], 120);
        $it = [];
        foreach (array_slice((array)($m['items'] ?? []), 0, 200) as $i) {
            if (!is_array($i) || trim((string)($i['description'] ?? '')) === '') continue;
            $x = ['description' => kpi_txt($i['description'], 180), 'qty' => (float)($i['qty'] ?? 0)];
            if (!empty($i['unit'])) $x['unit'] = kpi_txt($i['unit'], 8);
            $it[] = $x;
        }
        if ($it) $mv['items'] = $it;
        $out[$mv['nf']] = $mv;
    }
    return array_values($out);
}

function kpi_salvar_metas(array $t): void {
    $ok = [];
    foreach (kpi_metas_padrao() as $k => $_) if (isset($t[$k]) && is_numeric($t[$k]) && is_finite((float)$t[$k]) && (float)$t[$k] >= 0) $ok[$k] = (float)$t[$k];
    $atual = kpi_json_file(KPI_METAS_FILE, []);
    $atual['metas'] = array_merge((array)($atual['metas'] ?? []), $ok);
    $atual['atualizado_em'] = date('c');
    $tmp = KPI_METAS_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($atual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !@rename($tmp, KPI_METAS_FILE))
        kpi_out(500, ['ok' => false, 'erro' => 'Não foi possível gravar as metas.']);
}

/* ------------------------------------------------------------------ */
/* Cálculo                                                             */
/* ------------------------------------------------------------------ */
function kpi_dados(): array {
    $pedidos = kpi_json_file(DATA_DIR . '/items.json', []);
    $est = kpi_json_file(DATA_DIR . '/estoque.json', []);
    $col = kpi_json_file(DATA_DIR . '/coletas.json', []);
    $itens = (array)($est['itens'] ?? []);
    $movs = (array)($est['movimentos'] ?? []);
    foreach (glob(DATA_DIR . '/estoque/movimentos_arquivo_*.jsonl') ?: [] as $f) {
        foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) { $m = json_decode($l, true); if (is_array($m)) $movs[] = $m; }
    }
    // v3.28.0: NÃO reordenar por data — o saldo de cada movimento vale na ordem em que foi lançado
    // (ex.: carga do SAP lançada antes da planilha de importados, mas com data posterior).
    $movs = array_values(array_filter($movs, fn($m) => empty($m['cancelado'])));
    $agora = time();
    $mesAtual = date('Y-m');

    // Datas de cada PC
    $pcData = [];
    foreach ($pedidos as $p) {
        $pc = (int)($p['pc'] ?? 0);
        $criado = kpi_ts($p['importacao_em'] ?? null) ?? kpi_ts($p['novo_pedido_em'] ?? null);
        $fin = kpi_ts($p['primeira_confirmacao_em'] ?? null) ?? kpi_ts($p['entregas'][0]['confirmado_em'] ?? null) ?? kpi_ts($p['recebimento_finalizado_em'] ?? null);
        $pcData[$pc] = ['criado' => $criado, 'ini' => kpi_ts($p['recebimento_iniciado_em'] ?? null), 'fin' => $fin,
            'completo' => (($p['entregas'][0]['status'] ?? $p['chegou'] ?? '') === 'SIM'), 'urgente' => ($p['urgente'] ?? '') === '1',
            'origem' => (string)($p['origem_entrega'] ?? ''), 'p' => $p];
    }

    // Coletas: registro do módulo Coletas; PC "coleta interna" sem registro também conta.
    $rotuloConcluido = ['Coletado', 'Em transporte', 'Chegou ao almoxarifado'];
    $coletas = []; $comRegistro = [];
    foreach ((array)($col['coletas'] ?? []) as $c) {
        if (($c['status'] ?? '') === 'CANCELADA') continue;
        $pc = (int)($c['pc'] ?? 0);
        if ($pc) $comRegistro[$pc] = true;
        $done = null; $chegou = null;
        foreach ((array)($c['historico'] ?? []) as $h) {
            if (($h['acao'] ?? '') !== 'status') continue;
            if ($done === null && in_array($h['para'] ?? '', $rotuloConcluido, true)) $done = kpi_ts($h['em'] ?? null);
            if ($chegou === null && ($h['para'] ?? '') === 'Chegou ao almoxarifado') $chegou = kpi_ts($h['em'] ?? null);
        }
        if ($done === null && in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true)) $done = kpi_ts($c['atualizado_em'] ?? null);
        if ($done === null && $pc && !empty($pcData[$pc]['fin'])) $done = $pcData[$pc]['fin'];
        $due = !empty($c['agendada_data']) ? strtotime($c['agendada_data'] . ' 23:59:59') : null;
        $coletas[] = ['req' => kpi_ts($c['criado_em'] ?? null), 'done' => $done, 'due' => $due, 'chegou' => $chegou ?? ($pc ? ($pcData[$pc]['ini'] ?? null) : null),
            'urgente' => in_array($c['prioridade'] ?? '', ['ALTA', 'URGENTE'], true) || ($pc && !empty($pcData[$pc]['urgente'])),
            'motivo' => (string)($c['motivo_atraso'] ?? ''), 'pc' => $pc];
    }
    foreach ($pcData as $pc => $d) {
        if ($d['origem'] !== 'COLETA_DRILLING' || isset($comRegistro[$pc]) || !$d['criado']) continue;
        $coletas[] = ['req' => $d['criado'], 'done' => $d['fin'], 'due' => null, 'chegou' => $d['ini'], 'urgente' => $d['urgente'], 'motivo' => '', 'pc' => $pc];
    }
    $prazoPc = [];
    foreach ($coletas as $c) if ($c['pc'] && $c['due']) $prazoPc[$c['pc']] = $c['due'];

    // Meses com dados
    $marcas = [];
    foreach ($pcData as $d) foreach (['criado', 'fin'] as $k) if ($d[$k]) $marcas[] = $d[$k];
    foreach ($coletas as $c) if ($c['req']) $marcas[] = $c['req'];
    foreach ($movs as $m) if ($t = kpi_ts($m['em'] ?? null)) $marcas[] = $t;
    $ini = $marcas ? max(min($marcas), strtotime('-17 months', strtotime(date('Y-m-01')))) : time();
    $meses = [];
    for ($t = strtotime(date('Y-m-01', $ini)); date('Y-m', $t) <= $mesAtual; $t = strtotime('+1 month', $t)) $meses[] = date('Y-m', $t);

    // Linha do tempo de saldo por item
    $linha = [];
    foreach ($movs as $m) { $c = (string)($m['codigo'] ?? ''); if ($c !== '' && ($t = kpi_ts($m['em'] ?? null))) $linha[$c][] = [$t, (float)($m['saldo_posterior'] ?? 0)]; }
    $saidas = [];
    foreach ($movs as $m) if (($m['tipo'] ?? '') === 'SAIDA' && ($t = kpi_ts($m['em'] ?? null))) $saidas[(string)$m['codigo']][] = $t;

    // Acuracidade por mês (inventários SAP a partir da 2ª carga + contagens cíclicas)
    $ira = [];
    foreach ((array)($est['inventarios_sap'] ?? []) as $inv) {
        $t = (int)($inv['totais']['conferidos'] ?? 0);
        if ($t <= 0 || !empty($inv['primeira_carga'])) continue;
        $m = kpi_mes_de(kpi_ts($inv['em'] ?? null));
        $ira[$m] = [($ira[$m][0] ?? 0) + $t - (int)$inv['totais']['divergencias'], ($ira[$m][1] ?? 0) + $t];
    }
    foreach ((array)($est['inventarios'] ?? []) as $inv) {
        if (($inv['status'] ?? '') !== 'aplicado') continue;
        $m = kpi_mes_de(kpi_ts($inv['aplicado_em'] ?? $inv['atualizado_em'] ?? null));
        $tot = 0; $ok = 0;
        foreach ((array)($inv['linhas'] ?? []) as $l) { if (!isset($l['contado']) || $l['contado'] === null) continue; $tot++; if (abs((float)$l['contado'] - (float)($l['saldo_sistema'] ?? $l['esperado'] ?? 0)) < 0.0001) $ok++; }
        if ($tot) $ira[$m] = [($ira[$m][0] ?? 0) + $ok, ($ira[$m][1] ?? 0) + $tot];
    }

    $records = [];
    $iraAnterior = 0.0;
    foreach ($meses as $mes) {
        $fim = min(kpi_fim_mes($mes), $agora);
        $ini = strtotime($mes . '-01 00:00:00');
        $dentro = fn($t) => $t !== null && $t >= $ini && $t <= $fim;

        // ---- estoque no fim do mês
        $total = 0.0; $parado = 0.0; $criticos = 0;
        foreach ($itens as $cod => $it) {
            $saldo = 0.0;
            // último lançamento (na ordem real) com data até o fim do mês
            foreach ($linha[$cod] ?? [] as [$t, $s]) if ($t <= $fim) $saldo = $s;
            if ($mes === date('Y-m')) $saldo = (float)($it['saldo'] ?? 0);
            if ($saldo <= 0) { if ((float)($it['minimo'] ?? 0) > 0) $criticos++; continue; }
            $custo = (float)($it['custo_medio'] ?? 0);
            $total += $saldo * $custo;
            $girou = false;
            foreach ($saidas[$cod] ?? [] as $t) if ($t <= $fim && $t > $fim - 90 * 86400) { $girou = true; break; }
            if (!$girou) $parado += $saldo * $custo;
            $min = max((float)($it['minimo'] ?? 0), (float)($it['ponto_pedido'] ?? 0));
            if ($min > 0 && $saldo <= $min) $criticos++;
        }
        // ---- perdas, rupturas
        $perdas = 0.0; $rupturas = 0;
        foreach ($movs as $m) {
            $t = kpi_ts($m['em'] ?? null);
            if (!$dentro($t)) continue;
            $custo = (float)($itens[$m['codigo'] ?? '']['custo_medio'] ?? 0);
            $tipo = $m['tipo'] ?? '';
            if (in_array($tipo, ['AJUSTE_NEGATIVO', 'BAIXA_PERDA'], true)) $perdas += (float)$m['quantidade'] * $custo;
            if ($tipo === 'AJUSTE_INVENTARIO' && (float)$m['saldo_posterior'] < (float)$m['saldo_anterior']) $perdas += ((float)$m['saldo_anterior'] - (float)$m['saldo_posterior']) * $custo;
            if ($tipo === 'INVENTARIO_SAP' && (float)($m['diferenca_inventario'] ?? 0) < 0) $perdas += -(float)$m['diferenca_inventario'] * (float)($m['custo_unitario'] ?? $custo);
            if ((float)($m['saldo_anterior'] ?? 0) > 0 && (float)($m['saldo_posterior'] ?? 0) <= 0 && in_array($tipo, ['SAIDA', 'BAIXA_PERDA', 'AJUSTE_NEGATIVO'], true)) $rupturas++;
        }
        // ---- OTIF e tempo de atendimento (pedidos de compra)
        $fin = 0; $otifOk = 0; $minutos = [];
        $pcsMes = 0; $urgentes = 0; $devQtd = 0; $devValor = 0.0;
        foreach ($pcData as $pc => $d) {
            if ($dentro($d['criado'])) { $pcsMes++; if ($d['urgente']) $urgentes++; }
            foreach ((array)($d['p']['devolucoes'] ?? []) as $dv) if ($dentro(kpi_ts(($dv['data'] ?? '') . ' 12:00:00'))) { $devQtd++; $devValor += (float)($dv['valor'] ?? 0); }
            if (!$dentro($d['fin'])) continue;
            $fin++;
            $noPrazo = !isset($prazoPc[$pc]) || $d['fin'] <= $prazoPc[$pc];
            if ($d['completo'] && $noPrazo) $otifOk++;
            if ($d['ini'] && $d['fin'] > $d['ini']) $minutos[] = ($d['fin'] - $d['ini']) / 60;
        }
        // ---- coletas
        $sol = 0; $real = 0; $noPrazo = 0; $pend = 0; $atras = 0; $horas = []; $urg = 0;
        foreach ($coletas as $c) {
            // Coletas "a atender no mês": abertas no início do mês + solicitadas no mês.
            if ($dentro($c['req'])) { $sol++; if ($c['urgente']) $urg++; }
            elseif ($c['req'] && $c['req'] < $ini && (!$c['done'] || $c['done'] >= $ini)) $sol++;
            if ($dentro($c['done'])) {
                $real++;
                if (!$c['due'] || $c['done'] <= $c['due']) $noPrazo++;
                if ($c['req'] && $c['done'] > $c['req']) $horas[] = ($c['done'] - $c['req']) / 3600;
            }
            if ($c['req'] && $c['req'] <= $fim && (!$c['done'] || $c['done'] > $fim)) {
                $pend++;
                if ($c['due'] && $c['due'] < $fim) $atras++;
            }
        }
        if (isset($ira[$mes]) && $ira[$mes][1] > 0) $iraAnterior = round($ira[$mes][0] / $ira[$mes][1] * 100, 1);
        $records[] = ['month' => $mes,
            'totalValue' => round($total, 2), 'deadStockValue' => round($parado, 2), 'inventoryLossValue' => round($perdas, 2),
            'otif' => $fin ? round($otifOk / $fin * 100, 1) : 0, 'avgFulfillmentMinutes' => $minutos ? round(array_sum($minutos) / count($minutos)) : 0,
            'accuracy' => $iraAnterior, 'criticalItemsCount' => $criticos, 'stockouts' => $rupturas,
            'collectionsRequested' => $sol, 'collectionsCompleted' => $real, 'collectionsOnTime' => $noPrazo,
            'collectionsPending' => $pend, 'collectionsLate' => $atras, 'collectionsAvgHours' => $horas ? round(array_sum($horas) / count($horas), 1) : 0,
            'collectionsUrgent' => $urg, 'returnsCount' => $devQtd, 'returnsValue' => round($devValor, 2), 'emergencyPurchases' => $urgentes];
    }

    // ---- Fluxo da coleta (média das últimas 90 dias, em horas)
    $desde = $agora - 90 * 86400;
    $acc = ['solicitacao' => [], 'agendamento' => [], 'coleta' => [], 'transporte' => [], 'conferencia' => [], 'estoque' => []];
    $entradaEst = [];
    foreach ((array)($est['recebimentos'] ?? []) as $r) if (!empty($r['recebido_em'])) $entradaEst[(int)$r['pc']] = kpi_ts($r['recebido_em']);
    foreach ($coletas as $c) {
        if (!$c['req'] || $c['req'] < $desde) continue;
        $pcC = $c['pc'] ? ($pcData[$c['pc']]['criado'] ?? null) : null;
        if ($pcC && $c['req'] > $pcC) $acc['solicitacao'][] = ($c['req'] - $pcC) / 3600;
        if ($c['due'] && $c['due'] - 15 * 3600 > $c['req']) $acc['agendamento'][] = ($c['due'] - 15 * 3600 - $c['req']) / 3600;
        if ($c['done'] && $c['done'] > $c['req']) $acc['coleta'][] = ($c['done'] - $c['req']) / 3600;
        if ($c['done'] && $c['chegou'] && $c['chegou'] > $c['done']) $acc['transporte'][] = ($c['chegou'] - $c['done']) / 3600;
    }
    foreach ($pcData as $pc => $d) {
        if (!$d['fin'] || $d['fin'] < $desde) continue;
        if ($d['ini'] && $d['fin'] > $d['ini']) $acc['conferencia'][] = ($d['fin'] - $d['ini']) / 3600;
        if (!empty($entradaEst[$pc]) && $entradaEst[$pc] > $d['fin']) $acc['estoque'][] = ($entradaEst[$pc] - $d['fin']) / 3600;
    }
    $rot = ['solicitacao' => 'PC → coleta aberta', 'agendamento' => 'Agendamento', 'coleta' => 'Coleta', 'transporte' => 'Transporte',
        'conferencia' => 'Conferência', 'estoque' => 'Entrada no estoque'];
    $stages = [];
    foreach ($rot as $k => $l) $stages[] = ['key' => $k, 'label' => $l, 'hours' => $acc[$k] ? round(array_sum($acc[$k]) / count($acc[$k]), 1) : 0];

    // ---- Motivos de atraso (últimos 90 dias)
    $motRot = ['FORNECEDOR' => 'Fornecedor', 'TRANSPORTADORA' => 'Transportadora', 'PLANEJAMENTO' => 'Planejamento',
        'DOCUMENTACAO' => 'Documentação fiscal', 'OBRA' => 'Obra / solicitante', 'OUTRO' => 'Outro'];
    $mot = array_fill_keys(array_keys($motRot), 0);
    foreach ((array)($col['coletas'] ?? []) as $c) {
        $m = (string)($c['motivo_atraso'] ?? '');
        if (isset($mot[$m]) && (kpi_ts($c['atualizado_em'] ?? null) ?? 0) >= $desde) $mot[$m]++;
    }
    $reasons = [];
    foreach ($motRot as $k => $l) $reasons[] = ['key' => strtolower($k), 'label' => $l, 'count' => $mot[$k]];

    // ---- Itens críticos: abaixo do mínimo + divergências da última conciliação SAP
    $crit = [];
    foreach ($itens as $cod => $it) {
        $min = max((float)($it['minimo'] ?? 0), (float)($it['ponto_pedido'] ?? 0));
        $s = (float)($it['saldo'] ?? 0);
        if ($min > 0 && $s <= $min) $crit[] = ['id' => 'min-' . $cod, 'code' => (string)$cod, 'name' => (string)$it['descricao'], 'kind' => 'abaixo_minimo',
            'value' => round(max(0, $min - $s) * (float)($it['custo_medio'] ?? 0), 2), 'qty' => $s];
    }
    $ultimo = $est['inventarios_sap'] ?? [];
    $ultimo = $ultimo ? end($ultimo) : null;
    foreach ((array)($ultimo['divergentes'] ?? []) as $d) {
        $crit[] = ['id' => 'div-' . $d['codigo'], 'code' => (string)$d['codigo'], 'name' => (string)$d['descricao'], 'kind' => 'divergencia',
            'value' => round(abs((float)$d['diferenca']) * (float)$d['preco'], 2), 'qty' => (float)$d['diferenca']];
    }
    usort($crit, fn($a, $b) => $b['value'] <=> $a['value']);

    // ---- Pedidos de compra
    $orders = [];
    foreach ($pcData as $pc => $d) {
        if (!$d['criado']) continue;
        $orders[] = ['number' => (string)$pc, 'supplier' => (string)($d['p']['fornecedor'] ?? ''), 'destination' => '',
            'date' => date('Y-m-d', $d['criado']), 'month' => date('Y-m', $d['criado']), 'sender' => (string)($d['p']['solicitante'] ?? ''), 'urgent' => $d['urgente']];
    }
    usort($orders, fn($a, $b) => strcmp($a['date'] . $a['number'], $b['date'] . $b['number']));

    return ['records' => $records, 'targets' => kpi_metas(), 'criticalItems' => array_slice($crit, 0, 60),
        'stages' => $stages, 'delayReasons' => $reasons,
        'obras' => kpi_base()['obras'] ?? kpi_obras_padrao(), 'movements' => kpi_base()['movimentos'] ?? [], 'purchaseOrders' => $orders];
}

/* ------------------------------------------------------------------ */
/* Rotas (compatíveis com o front-end do SGA)                          */
/* ------------------------------------------------------------------ */
function kpi_resposta(bool $podeMetas, ?array $ident): array {
    global $publico;
    $nome = $publico ? 'visitante' : ($ident['username'] ?? 'operador');
    $papel = $publico ? 'publico' : ($podeMetas ? 'gestor' : 'leitor');
    return ['ok' => true, 'csrf' => $_SESSION['kpi_csrf'], 'usuario' => ['usuario' => $nome, 'papel' => $papel],
        'usuarios' => [['usuario' => $nome, 'papel' => $papel]], 'dados' => $publico ? kpi_anonimizar(kpi_dados()) : kpi_dados(),
        'publico' => $publico, 'link_publico' => $podeMetas ? kpi_link_info() : null];
}
/** No link público, nomes de pessoas não são expostos. */
function kpi_anonimizar(array $d): array {
    foreach ($d['purchaseOrders'] ?? [] as $i => $p) $d['purchaseOrders'][$i]['sender'] = '';
    return $d;
}
function kpi_link_info(): array {
    $l = kpi_link_publico();
    return ['ativo' => !empty($l['ativo']), 'token' => !empty($l['ativo']) ? ($l['token'] ?? '') : '', 'criado_em' => $l['criado_em'] ?? null, 'criado_por' => $l['criado_por'] ?? null];
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($metodo === 'GET' && ($_GET['op'] ?? '') === 'gestao') {      // v3.28.0: indicadores de gestão
        require_once __DIR__ . '/gestao_lib.php';
        $g = kpi_gestao((string)($_GET['mes'] ?? date('Y-m')), (string)($_GET['periodo'] ?? 'mes'));
        if ($publico) {                                                 // link público: sem nomes de pessoas
            foreach ($g['ferramentas']['lista_atrasados'] ?? [] as $i => $e) $g['ferramentas']['lista_atrasados'][$i]['responsavel'] = 'Colaborador';
            $n = 0; foreach ($g['urgentes']['por_solicitante'] ?? [] as $i => $e) $g['urgentes']['por_solicitante'][$i]['solicitante'] = 'Solicitante ' . (++$n);
        }
        kpi_out(200, ['ok' => true, 'publico' => $publico] + $g);
    }
    if ($metodo === 'GET' && ($_GET['op'] ?? '') === 'recebimentos_fornecedor') {   // v3.52.0
        require_once __DIR__ . '/gestao_lib.php';
        kpi_out(200, ['ok' => true, 'publico' => $publico] + kpi_recebimentos_fornecedor($_GET['de'] ?? null, $_GET['ate'] ?? null));
    }
    if ($metodo === 'GET' && ($_GET['op'] ?? '') === 'coletas_fornecedor') {   // v3.17.0
        require_once __DIR__ . '/gestao_lib.php';
        kpi_out(200, ['ok' => true, 'publico' => $publico] + kpi_coletas_fornecedor((int)($_GET['dias'] ?? 90), $_GET['de'] ?? null, $_GET['ate'] ?? null));
    }
    if ($publico && $metodo !== 'GET') kpi_out(403, ['ok' => false, 'erro' => 'O link público é somente leitura.']);
    if ($metodo === 'GET') kpi_out(200, kpi_resposta($podeMetas, $ident));
    if ($metodo !== 'POST') kpi_out(405, ['ok' => false, 'erro' => 'Método não aceito.']);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'kpi-sync') kpi_out(400, ['ok' => false, 'erro' => 'Cabeçalho X-Requested-With ausente.']);
    if (($_GET['op'] ?? '') === 'ler_danfe') {
        if (!hash_equals((string)$_SESSION['kpi_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) kpi_out(403, ['ok' => false, 'erro' => 'Token CSRF inválido.']);
        if (!$podeMetas) kpi_out(403, ['ok' => false, 'erro' => 'Somente o administrador importa remessas.']);
        $f = $_FILES['pdf'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 40 * 1024 * 1024) kpi_out(422, ['ok' => false, 'erro' => 'PDF não recebido (máx. 40 MB).']);
        $lidas = kpi_ler_danfe((string)file_get_contents($f['tmp_name']));
        $cache = kpi_json_file(KPI_PDF_CACHE, []);
        foreach ($lidas as $nf => $d) $cache[$nf] = $d + ['lido_em' => date('c')];
        if (count($cache) > 5000) $cache = array_slice($cache, -5000, null, true);
        @file_put_contents(KPI_PDF_CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
        kpi_out(200, ['ok' => true, 'notas' => count($lidas), 'com_transportadora' => count(array_filter($lidas, fn($d) => !empty($d['carrier'])))]);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) kpi_out(400, ['ok' => false, 'erro' => 'JSON inválido.']);
    $op = (string)($body['op'] ?? '');
    $dados = is_array($body['dados'] ?? null) ? $body['dados'] : [];
    if ($op === 'login') {
        if ($ident) kpi_out(200, kpi_resposta($podeMetas, $ident));
        kpi_out(401, ['ok' => false, 'erro' => 'Entre pelo Recebimento Drilling; o painel usa o mesmo login.']);
    }
    if (!hash_equals((string)$_SESSION['kpi_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) kpi_out(403, ['ok' => false, 'erro' => 'Token CSRF inválido.']);
    if ($op === 'logout') kpi_out(200, ['ok' => true]);
    if ($op === 'link_publico') {                                       // v3.28.0: gerar / desativar link público
        if (!$podeMetas) kpi_out(403, ['ok' => false, 'erro' => 'Somente o administrador gerencia o link público.']);
        $b = kpi_base();
        if (($body['acao'] ?? '') === 'gerar') $b['link_publico'] = ['ativo' => true, 'token' => rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '='),
            'criado_em' => date('c'), 'criado_por' => $ident['username'] ?? 'admin'];
        elseif (($body['acao'] ?? '') === 'desativar') $b['link_publico'] = ['ativo' => false, 'desativado_em' => date('c'), 'desativado_por' => $ident['username'] ?? 'admin'];
        else kpi_out(422, ['ok' => false, 'erro' => 'Ação inválida.']);
        kpi_gravar_base($b);
        kpi_out(200, ['ok' => true, 'link_publico' => kpi_link_info()]);
    }
    if ($op === 'salvar_metas' || $op === 'salvar_estado') {
        if (!$podeMetas) kpi_out(403, ['ok' => false, 'erro' => 'Somente o administrador altera o painel.']);
        $t = $op === 'salvar_metas' ? ($dados['targets'] ?? $dados) : ($dados['targets'] ?? []);
        if (is_array($t) && $t) kpi_salvar_metas($t);
        if ($op === 'salvar_estado') {
            // Do estado do painel só aproveita remessas por obra e cadastro de obras;
            // os demais números são sempre recalculados a partir do sistema.
            $b = kpi_base();
            if (isset($dados['movements']) && is_array($dados['movements'])) {
                $novo = kpi_limpar_movimentos($dados['movements']);
                $antes = count($b['movimentos'] ?? []);
                $velhos = []; foreach ((array)($b['movimentos'] ?? []) as $v) $velhos[$v['nf']] = $v;
                $novos = array_filter($novo, fn($x) => !isset($velhos[$x['nf']]));
                $enr = []; foreach (kpi_enriquecer(array_values($novos)) as $x) $enr[$x['nf']] = $x;
                foreach ($novo as &$x) {
                    if (isset($enr[$x['nf']])) { $x = $enr[$x['nf']]; continue; }
                    // O front-end não conhece placa/motorista: preserva o que já estava gravado.
                    foreach (['plate', 'driver', 'freightBy'] as $k) if (empty($x[$k]) && !empty($velhos[$x['nf']][$k])) $x[$k] = $velhos[$x['nf']][$k];
                }
                unset($x);
                // Proteção: um estado vazio vindo do navegador não apaga remessas já importadas.
                if ($novo || $antes === 0) { $b['movimentos'] = $novo; if (count($novo) !== $antes) kpi_hist($b, 'remessas', $antes, count($novo)); }
            }
            if (isset($dados['obras']) && is_array($dados['obras']) && $dados['obras']) $b['obras'] = kpi_limpar_obras($dados['obras']);
            kpi_gravar_base($b);
        }
        kpi_out(200, kpi_resposta($podeMetas, $ident));
    }
    if (in_array($op, ['registrar_movimentos', 'atualizar_movimento', 'limpar_movimentos', 'salvar_obras'], true)) {
        if (!$podeMetas) kpi_out(403, ['ok' => false, 'erro' => 'Somente o administrador altera o painel.']);
        $b = kpi_base();
        $mov = []; foreach ((array)($b['movimentos'] ?? []) as $m) $mov[$m['nf']] = $m;
        $antes = count($mov);
        if ($op === 'salvar_obras') $b['obras'] = kpi_limpar_obras((array)($dados['obras'] ?? $dados));
        if ($op === 'registrar_movimentos' || $op === 'atualizar_movimento') {
            $lista = $op === 'atualizar_movimento' ? [$dados['movement'] ?? $dados] : (array)($dados['movements'] ?? $dados);
            foreach (kpi_limpar_movimentos($lista) as $m) $mov[$m['nf']] = $m;
        }
        if ($op === 'limpar_movimentos') {
            $mes = (string)($dados['month'] ?? '');
            foreach ($mov as $k => $m) if ($mes === '' || $m['month'] === $mes) unset($mov[$k]);
        }
        $b['movimentos'] = array_values($mov);
        kpi_hist($b, $op, $antes, count($mov));
        kpi_gravar_base($b);
        kpi_out(200, kpi_resposta($podeMetas, $ident));
    }
    // Lançamentos manuais não existem aqui: tudo vem do sistema.
    kpi_out(200, kpi_resposta($podeMetas, $ident) + ['aviso' => 'Os indicadores são calculados automaticamente a partir do Recebimento Drilling; lançamentos manuais não são gravados.']);
} catch (Throwable $e) {
    error_log('Indicadores: ' . $e->getMessage() . ' @' . $e->getLine());
    kpi_out(500, ['ok' => false, 'erro' => 'Falha ao calcular os indicadores.']);
}
