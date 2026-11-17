<?php
/**
 * Recebimento Drilling — Indicadores de gestão (v3.15.0)
 * Somente leitura. Junta dados que o sistema já controla:
 *  1 custo de locação por obra · 2 consumo de material por obra/setor (+ remessas por NF)
 *  3 ferramentas emprestadas/atrasadas · 4 PCs urgentes por solicitante · 5 devoluções a fornecedor
 *  6 risco de ruptura · 7 capital parado · 8 motivos de atraso das coletas
 *  9 divergências no recebimento · 10 ranking de fornecedores
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
if (!function_exists('respond')) {
    function respond($payload, $code = 200) { throw new RuntimeException((string)($payload['error'] ?? 'erro')); }
}
$ROOTG = dirname(__DIR__);
require_once $ROOTG . '/estoque_lib.php';
require_once $ROOTG . '/locacoes_lib.php';
require_once $ROOTG . '/ferramentas_lib.php';
require_once $ROOTG . '/rd_obras.php';
require_once $ROOTG . '/coletas_lib.php';

function kg_chave(string $s): string {
    $s = mb_strtoupper(trim($s));
    $s = strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
    $s = preg_replace('/^OBRA\s*[·:\-]?\s*/u', '', $s);
    $s = preg_replace('/[\s\-\/]+(MG|MT|PE|BA|SP|SC|AL|RJ|PR|GO|ES)$/u', '', $s);
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', $s));
}
/** Une grafias diferentes da mesma obra (ex.: "SINOP-MT", "OBRA SINOP", "Obra · Obra Sinop" → "Obra Sinop"). */
function kg_norm_obra(string $s): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        $lista = function_exists('kpi_obras_padrao') ? kpi_obras_padrao() : [];
        foreach (rd_obras_lista() as $o) $lista[] = $o;
        foreach ($lista as $o) {
            if (($o['id'] ?? '') === 'drilling-deposito') continue;
            $nome = $o['name'];
            foreach (array_merge([$o['name'], str_replace('-', ' ', (string)($o['id'] ?? ''))], (array)($o['aliases'] ?? [])) as $k) {
                $k = kg_chave((string)$k); if (strlen($k) >= 3) $mapa[$k] = $nome;
            }
        }
    }
    $k = kg_chave(preg_replace('/^OBRA\s*·\s*/iu', '', $s));
    if ($k === '') return 'Sem obra/setor';
    if (preg_match('/^(MATRIZ|DEPOSITO|SEDE|DRILLING)/', $k)) return 'Matriz';
    if (isset($mapa[$k])) return $mapa[$k];
    foreach ($mapa as $alias => $nome) if (strlen($alias) >= 4 && (str_contains($k, $alias) || str_contains($alias, $k))) return $nome;
    return mb_convert_case(mb_strtolower(trim(preg_replace('/^OBRA\s*·\s*/iu', '', $s))), MB_CASE_TITLE, 'UTF-8');
}
function kg_mes_ok(?string $data, string $mes, string $periodo): bool {
    if (!$data) return false;
    $m = substr($data, 0, 7);
    if ($periodo === 'mes') return $m === $mes;
    if ($periodo === 'ano') return substr($m, 0, 4) === substr($mes, 0, 4) && $m <= $mes;
    return $m >= date('Y-m', strtotime($mes . '-01 -11 month')) && $m <= $mes;   // 12 meses
}
function kg_top(array $a, string $campo, int $n = 12): array {
    $a = array_values($a); usort($a, fn($x, $y) => $y[$campo] <=> $x[$campo]); return array_slice($a, 0, $n);
}
function kg_r(array $rows): array { return array_map(fn($r) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $r), $rows); }

function kpi_gestao(string $mes, string $periodo): array {
    $mes = preg_match('/^\d{4}-\d{2}$/', $mes) ? $mes : date('Y-m');
    $periodo = in_array($periodo, ['mes', 'ano', '12m'], true) ? $periodo : 'mes';
    $out = ['mes' => $mes, 'periodo' => $periodo, 'gerado_em' => date('c')];
    $pedidos = kpi_json_file(DATA_DIR . '/items.json', []);
    $est = est_read();
    $obrasNomes = [];
    foreach (rd_obras_lista() as $o) $obrasNomes[$o['id']] = $o['name'];

    // ---------- 1. Locações ----------
    try {
        $lp = loc_painel(loc_read(), ['mes' => $mes]);
        $porObra = [];
        foreach ($lp['locacoes'] as $l) {
            $c = $l['custo'];
            foreach ($c['segmentos'] as $s) {
                $dias = 0;
                for ($d = $s['de']; $d <= $s['ate']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) if (kg_mes_ok($d, $mes, $periodo)) $dias++;
                if (!$dias) continue;
                $k = kg_norm_obra($s['local']);
                $porObra[$k] = $porObra[$k] ?? ['obra' => $k, 'valor' => 0.0, 'equipamentos' => 0];
                $porObra[$k]['valor'] += $dias * $c['valor_dia'];
            }
            foreach ($l['extras'] ?? [] as $x) if (kg_mes_ok($x['data'], $mes, $periodo)) {
                $k = kg_norm_obra($x['local'] ?: $l['local_atual']); $porObra[$k] = $porObra[$k] ?? ['obra' => $k, 'valor' => 0.0, 'equipamentos' => 0];
                $porObra[$k]['valor'] += $x['valor'];
            }
            if ($l['status'] === 'ATIVA') { $k = kg_norm_obra($l['local_atual']); $porObra[$k] = $porObra[$k] ?? ['obra' => $k, 'valor' => 0.0, 'equipamentos' => 0]; $porObra[$k]['equipamentos'] += (int)$l['quantidade']; }
        }
        $semValor = count(array_filter($lp['locacoes'], fn($l) => $l['status'] === 'ATIVA' && (float)$l['tarifa_valor'] <= 0));
        $out['locacoes'] = ['por_obra' => kg_r(kg_top($porObra, 'valor', 20)), 'total' => round(array_sum(array_column($porObra, 'valor')), 2),
            'ativos' => $lp['kpis']['equipamentos_ativos'], 'custo_dia' => $lp['kpis']['custo_dia_atual'], 'vencidas' => $lp['kpis']['vencidas'],
            'sem_valor' => $semValor, 'mensal' => $lp['mensal'], 'por_fornecedor' => kg_r(array_slice($lp['por_fornecedor'], 0, 8))];
    } catch (Throwable $e) { $out['locacoes'] = ['erro' => $e->getMessage()]; }

    // ---------- 2. Consumo por obra / setor ----------
    $cons = []; $porSetor = [];
    foreach ($est['movimentos'] as $m) {
        if (!in_array($m['tipo'] ?? '', ['SAIDA', 'BAIXA_PERDA'], true) || !empty($m['cancelado']) || !kg_mes_ok($m['em'] ?? '', $mes, $periodo)) continue;
        $custo = (float)($est['itens'][$m['codigo']]['custo_medio'] ?? 0);
        $v = abs((float)$m['quantidade']) * $custo;
        $sc = (string)($m['setor_codigo'] ?? '');
        $obra = $sc === 'OBRA' ? kg_norm_obra((string)($m['obra'] ?? '')) : ($sc ? (RD_SETORES[$sc] ?? $sc) : kg_norm_obra((string)($m['obra'] ?? $m['setor'] ?? '')));
        $cons[$obra] = $cons[$obra] ?? ['obra' => $obra, 'estoque' => 0.0, 'remessas' => 0.0, 'locacao' => 0.0, 'total' => 0.0, 'saidas' => 0];
        $cons[$obra]['estoque'] += $v; $cons[$obra]['saidas']++;
        $setorNome = $sc ? (RD_SETORES[$sc] ?? $sc) : ($sc === '' && ($m['obra'] ?? '') !== '' ? 'Obra' : 'Não informado');
        $porSetor[$setorNome] = ($porSetor[$setorNome] ?? 0) + $v;
    }
    $kb = kpi_json_file(KPI_METAS_FILE, []);
    foreach ((array)($kb['movimentos'] ?? []) as $r) {
        if (($r['kind'] ?? 'remessa') !== 'remessa' && ($r['kind'] ?? '') !== 'transferencia') continue;
        $data = !empty($r['date']) ? $r['date'] : (($r['month'] ?? '') . '-15');
        if (!kg_mes_ok($data, $mes, $periodo)) continue;
        $id = (string)($r['destId'] ?? ''); if ($id === '' || $id === 'drilling-deposito') continue;
        $obra = kg_norm_obra($obrasNomes[$id] ?? $id);
        $cons[$obra] = $cons[$obra] ?? ['obra' => $obra, 'estoque' => 0.0, 'remessas' => 0.0, 'locacao' => 0.0, 'total' => 0.0, 'saidas' => 0];
        $cons[$obra]['remessas'] += (float)($r['value'] ?? 0) + (float)($r['freight'] ?? 0);
    }
    foreach (($out['locacoes']['por_obra'] ?? []) as $l) {
        $cons[$l['obra']] = $cons[$l['obra']] ?? ['obra' => $l['obra'], 'estoque' => 0.0, 'remessas' => 0.0, 'locacao' => 0.0, 'total' => 0.0, 'saidas' => 0];
        $cons[$l['obra']]['locacao'] += $l['valor'];
    }
    foreach ($cons as &$c) $c['total'] = $c['estoque'] + $c['remessas'] + $c['locacao']; unset($c);
    arsort($porSetor);
    $out['custo_obra'] = ['por_obra' => kg_r(kg_top($cons, 'total', 25)), 'total' => round(array_sum(array_column($cons, 'total')), 2),
        'por_setor' => array_map(fn($k, $v) => ['setor' => $k, 'valor' => round($v, 2)], array_keys($porSetor), $porSetor)];

    // ---------- 3. Ferramentas ----------
    try {
        $fp = fer_painel(fer_read());
        $dest = [];
        foreach ($fp['abertos'] as $e) {
            $k = kg_norm_obra((string)$e['obra']);
            $dest[$k] = $dest[$k] ?? ['destino' => $k, 'unidades' => 0, 'atrasados' => 0];
            foreach ($e['itens'] as $i) $dest[$k]['unidades'] += max(0, $i['qtd'] - $i['devolvido']);
            if ($e['atrasado']) $dest[$k]['atrasados']++;
        }
        $atr = array_values(array_filter($fp['abertos'], fn($e) => $e['atrasado']));
        $out['ferramentas'] = ['abertos' => count($fp['abertos']), 'atrasados' => count($atr), 'por_destino' => kg_top($dest, 'unidades', 15),
            'lista_atrasados' => array_map(fn($e) => ['id' => $e['id'], 'responsavel' => $e['responsavel'] ?? ($e['assinatura']['nome'] ?? ''), 'destino' => $e['obra'],
                'previsto' => $e['previsto'], 'dias' => $e['dias_atraso'], 'itens' => implode(', ', array_map(fn($i) => ($i['qtd'] - $i['devolvido']) . '× ' . $i['nome'], array_filter($e['itens'], fn($i) => $i['qtd'] > $i['devolvido'])))], array_slice($atr, 0, 15)),
            'kpis' => $fp['kpis']];
    } catch (Throwable $e) { $out['ferramentas'] = ['erro' => $e->getMessage()]; }

    // ---------- 4, 5, 9, 10. Pedidos ----------
    $sol = []; $nPc = 0; $nUrg = 0; $dev = []; $devTotal = 0.0; $devQtd = 0; $div = []; $nFin = 0; $nDiv = 0; $forn = [];
    $col = kpi_json_file(DATA_DIR . '/coletas.json', []);
    $colPorPc = [];
    foreach ((array)($col['coletas'] ?? []) as $c) if ((int)($c['pc'] ?? 0) && ($c['status'] ?? '') !== 'CANCELADA') $colPorPc[(int)$c['pc']] = $c;
    foreach ($pedidos as $p) {
        $pc = (int)($p['pc'] ?? 0);
        $f = mb_strtoupper(trim((string)($p['fornecedor'] ?? ''))) ?: 'NÃO INFORMADO';
        $criado = substr((string)($p['importacao_em'] ?? $p['novo_pedido_em'] ?? ''), 0, 10);
        $fin = substr((string)($p['primeira_confirmacao_em'] ?? $p['entregas'][0]['confirmado_em'] ?? $p['recebimento_finalizado_em'] ?? ''), 0, 10);
        $forn[$f] = $forn[$f] ?? ['fornecedor' => $f, 'pcs' => 0, 'finalizados' => 0, 'no_prazo' => 0, 'completos' => 0, 'divergencias' => 0, 'coletas_atrasadas' => 0, 'devolucoes' => 0.0, 'urgentes' => 0];
        if (kg_mes_ok($criado, $mes, $periodo)) {
            $nPc++; $forn[$f]['pcs']++;
            $s = trim((string)($p['solicitante'] ?? '')) ?: 'Não informado';
            $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
            $sol[$s] = $sol[$s] ?? ['solicitante' => $s, 'pcs' => 0, 'urgentes' => 0];
            $sol[$s]['pcs']++;
            if (($p['urgente'] ?? '') === '1') { $nUrg++; $sol[$s]['urgentes']++; $forn[$f]['urgentes']++; }
        }
        foreach ((array)($p['devolucoes'] ?? []) as $d) if (kg_mes_ok($d['data'] ?? '', $mes, $periodo)) {
            $v = (float)($d['valor'] ?? 0); $devTotal += $v; $devQtd++; $forn[$f]['devolucoes'] += $v;
            $dev[$f] = $dev[$f] ?? ['fornecedor' => $f, 'valor' => 0.0, 'qtd' => 0]; $dev[$f]['valor'] += $v; $dev[$f]['qtd']++;
        }
        if ($fin !== '' && kg_mes_ok($fin, $mes, $periodo)) {
            $nFin++; $forn[$f]['finalizados']++;
            $linhasDiv = [];
            foreach ((array)($p['insumos'] ?? []) as $l) {
                $ped = (float)($l['quantidade_pedida'] ?? 0); $rec = (float)($l['quantidade_recebida'] ?? 0);
                if ($ped > 0 && abs($ped - $rec) > 0.0001) $linhasDiv[] = ['descricao' => $l['descricao'], 'pedida' => $ped, 'recebida' => $rec];
            }
            $st = (string)($p['entregas'][0]['status'] ?? 'SIM');
            $temDiv = $linhasDiv || ($st !== '' && $st !== 'SIM');
            if ($temDiv) {
                $nDiv++; $forn[$f]['divergencias']++;
                $div[$f] = $div[$f] ?? ['fornecedor' => $f, 'pcs' => 0, 'linhas' => 0, 'exemplos' => []];
                $div[$f]['pcs']++; $div[$f]['linhas'] += count($linhasDiv);
                if (count($div[$f]['exemplos']) < 3) $div[$f]['exemplos'][] = 'PC ' . $pc . ($linhasDiv ? ': ' . $linhasDiv[0]['descricao'] . ' (' . $linhasDiv[0]['recebida'] . '/' . $linhasDiv[0]['pedida'] . ')' : ' (' . $st . ')');
            } else $forn[$f]['completos']++;
            $c = $colPorPc[$pc] ?? null;
            $due = $c && !empty($c['agendada_data']) ? $c['agendada_data'] : null;
            if (!$due || $fin <= $due) $forn[$f]['no_prazo']++;
        }
        $c = $colPorPc[$pc] ?? null;
        if ($c && !empty($c['agendada_data']) && kg_mes_ok($c['agendada_data'], $mes, $periodo)) {
            $feito = in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true);
            if (!$feito && $c['agendada_data'] < date('Y-m-d') || !empty($c['motivo_atraso'])) $forn[$f]['coletas_atrasadas']++;
        }
    }
    $out['urgentes'] = ['pcs' => $nPc, 'urgentes' => $nUrg, 'pct' => $nPc ? round($nUrg / $nPc * 100, 1) : 0,
        'por_solicitante' => array_map(fn($r) => $r + ['pct' => $r['pcs'] ? round($r['urgentes'] / $r['pcs'] * 100, 1) : 0], kg_top($sol, 'urgentes', 15))];
    $out['devolucoes'] = ['valor' => round($devTotal, 2), 'qtd' => $devQtd, 'por_fornecedor' => kg_r(kg_top($dev, 'valor', 10))];
    $out['divergencias'] = ['finalizados' => $nFin, 'com_divergencia' => $nDiv, 'pct' => $nFin ? round($nDiv / $nFin * 100, 1) : 0, 'por_fornecedor' => kg_top($div, 'pcs', 10)];

    // ranking de fornecedores (só quem teve pedido finalizado no período)
    $rank = [];
    foreach ($forn as $r) {
        if ($r['finalizados'] < 1) continue;
        $prazo = $r['no_prazo'] / $r['finalizados'] * 100; $compl = $r['completos'] / $r['finalizados'] * 100;
        $pen = min(20, $r['coletas_atrasadas'] * 5) + ($r['devolucoes'] > 0 ? min(15, 5 + $r['devolucoes'] / 1000) : 0);
        $nota = max(0, round(0.5 * $prazo + 0.5 * $compl - $pen, 1));
        $rank[] = $r + ['pct_prazo' => round($prazo, 1), 'pct_completo' => round($compl, 1), 'nota' => $nota,
            'classe' => $nota >= 90 ? 'A' : ($nota >= 75 ? 'B' : ($nota >= 60 ? 'C' : 'D'))];
    }
    usort($rank, fn($a, $b) => [$b['nota'], $b['finalizados']] <=> [$a['nota'], $a['finalizados']]);
    $out['fornecedores'] = kg_r($rank);

    // ---------- 6, 7. Estoque ----------
    $an = est_dashboard_analise($est, 90);
    $out['ruptura'] = array_values(array_filter($an['cobertura'], fn($c) => $c['dias'] <= 15));
    $out['capital_parado'] = ['valor' => $an['kpis']['valor_parado'], 'itens' => $an['kpis']['itens_parados'], 'valor_total' => $an['kpis']['valor_total'],
        'pct' => $an['kpis']['valor_total'] > 0 ? round($an['kpis']['valor_parado'] / $an['kpis']['valor_total'] * 100, 1) : 0, 'top' => $an['parados']];

    // ---------- 8. Motivos de atraso das coletas ----------
    $mot = []; $nAtr = 0;
    foreach ((array)($col['coletas'] ?? []) as $c) {
        $m = trim((string)($c['motivo_atraso'] ?? ''));
        if ($m === '') continue;
        $ref = $c['agendada_data'] ?? substr((string)($c['criado_em'] ?? ''), 0, 10);
        if (!kg_mes_ok($ref, $mes, $periodo)) continue;
        $nAtr++;
        $rot = defined('COL_MOTIVOS_ATRASO') ? (COL_MOTIVOS_ATRASO[$m] ?? $m) : $m;
        $mot[$rot] = ($mot[$rot] ?? 0) + 1;
    }
    arsort($mot);
    $out['motivos_atraso'] = ['total' => $nAtr, 'motivos' => array_map(fn($k, $v) => ['motivo' => $k, 'qtd' => $v], array_keys($mot), $mot)];
    return $out;
}

/* ================================================================== */
/* v3.17.0 — Coletas por fornecedor (idas, compras, km e insumos)       */
/* ================================================================== */
const KG_FATOR_ESTRADA = 1.3;   // distância em linha reta → trajeto real aproximado

function kg_forn_chave(string $cnpj, string $nome): string {
    $c = preg_replace('/\D/', '', $cnpj);
    return strlen($c) === 14 ? 'C' . $c : 'N' . kg_chave($nome);
}

function kg_valor($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    $v = preg_replace('/[^\d,.\-]/', '', (string)$v);
    if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);   // "2.480" = 2480
    return (float)$v;
}
/** Dia útil anterior (sexta quando a data cai na segunda). */
function kg_dia_util_anterior(string $d): string {
    $t = strtotime($d . ' 12:00 -1 day');
    while ((int)date('N', $t) >= 6) $t -= 86400;
    return date('Y-m-d', $t);
}
/**
 * v3.20.2: data real da coleta.
 * O PC pode ter sido criado/importado dias antes (aguardando aprovação, NF etc.), então a data do pedido NÃO serve.
 * Regra: a coleta acontece no dia útil ANTERIOR ao recebimento feito pelo conferente; nunca antes da emissão da NF.
 * Se o motorista registrou a coleta (status Coletado) antes do dia do recebimento, vale a data registrada.
 */
function kg_data_coleta(?array $p, ?string $coletadoEm): array {
    $rec = '';
    if ($p) foreach (['recebimento_iniciado_em', 'primeira_confirmacao_em', 'recebimento_finalizado_em', 'data'] as $k)
        if (!empty($p[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$p[$k])) { $rec = substr((string)$p[$k], 0, 10); break; }
    $col = $coletadoEm ? substr($coletadoEm, 0, 10) : '';
    if ($rec === '') return [$col, $col !== '' ? 'registro da coleta' : ''];
    if ($col !== '' && $col < $rec) return [$col, 'registro da coleta'];
    $d = kg_dia_util_anterior($rec);
    $nf = $p ? substr((string)($p['emissaoNF'] ?? ''), 0, 10) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nf) && $nf > $d && $nf <= $rec) $d = $nf;   // não coleta antes de a NF existir
    return [$d, 'recebimento − 1 dia útil'];
}
function kpi_coletas_fornecedor(int $dias, ?string $de = null, ?string $ate = null): array {
    $ate = $ate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : date('Y-m-d');
    $de = $de && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? $de : date('Y-m-d', strtotime($ate . ' -' . max(1, min(730, $dias)) . ' day'));
    $col = kpi_json_file(DATA_DIR . '/coletas.json', []);
    $pedidos = []; foreach (kpi_json_file(DATA_DIR . '/items.json', []) as $p) $pedidos[(int)($p['pc'] ?? 0)] = $p;
    // v3.20.2: origem SEMPRE a Drilling do Brasil (São José da Lapa), independente da configuração do mapa
    $origem = defined('COL_ORIGEM_PADRAO') ? COL_ORIGEM_PADRAO : ['lat' => -19.6986, 'lng' => -43.9586, 'nome' => 'Drilling do Brasil — Depósito (São José da Lapa)'];

    // coordenadas conhecidas por CNPJ / nome (de qualquer coleta)
    $geo = [];
    foreach ((array)($col['coletas'] ?? []) as $c) if (isset($c['lat'], $c['lng']) && $c['lat'] !== null) {
        $geo[kg_forn_chave((string)($c['cnpj'] ?? ''), (string)$c['fornecedor'])] = ['lat' => $c['lat'], 'lng' => $c['lng']];
        $geo['N' . kg_chave((string)$c['fornecedor'])] = ['lat' => $c['lat'], 'lng' => $c['lng']];
    }
    foreach ((array)($col['fornecedores'] ?? []) as $cn => $x) if (!empty($x['lat'])) $geo['C' . $cn] ??= ['lat' => $x['lat'], 'lng' => $x['lng']];
    // eventos de coleta realizados no período
    $ev = []; $comRegistro = [];
    foreach ((array)($col['coletas'] ?? []) as $c) {
        if (!in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true)) continue;
        $pc = (int)($c['pc'] ?? 0); if ($pc) $comRegistro[$pc] = true;
        $quando = $c['coletado_em'] ?? null;
        if (!$quando) foreach ((array)($c['historico'] ?? []) as $h) if (($h['acao'] ?? '') === 'status' && in_array($h['para'] ?? '', ['Coletado', 'Em transporte', 'Chegou ao almoxarifado'], true)) { $quando = $h['em']; break; }
        [$quando, $fonteData] = kg_data_coleta($pc ? ($pedidos[$pc] ?? null) : null, $quando ?: ($c['atualizado_em'] ?? null));
        if ($quando < $de || $quando > $ate) continue;
        $ev[] = ['data' => $quando, 'pc' => $pc, 'fornecedor' => (string)$c['fornecedor'], 'cnpj' => (string)($c['cnpj'] ?? ''), 'coleta' => $c['id'],
            'km_real' => isset($c['km_real']) ? (float)$c['km_real'] : null, 'geo' => (isset($c['lat']) && $c['lat'] !== null) ? ['lat' => $c['lat'], 'lng' => $c['lng']] : null, 'obs' => (string)($c['observacao'] ?? '')];
    }
    // PCs marcados "coleta Drilling" sem registro no módulo Coletas
    foreach ($pedidos as $pc => $p) {
        if (($p['origem_entrega'] ?? '') !== 'COLETA_DRILLING' || isset($comRegistro[$pc])) continue;
        [$quando, $fonteData] = kg_data_coleta($p, null);
        if ($quando === '' || $quando < $de || $quando > $ate) continue;
        $ev[] = ['data' => $quando, 'pc' => $pc, 'fornecedor' => (string)$p['fornecedor'], 'cnpj' => (string)($p['cnpjEmitente'] ?? ''), 'coleta' => null, 'km_real' => null, 'geo' => null, 'obs' => ''];
    }
    // agrupa por fornecedor (CNPJ; sem CNPJ, pelo nome — usando o CNPJ já visto para o mesmo nome)
    $cnpjPorNome = [];
    foreach ($ev as $i => $e) {
        $p = $pedidos[$e['pc']] ?? null;
        if ($e['cnpj'] === '' && $p) $ev[$i]['cnpj'] = $e['cnpj'] = (string)($p['cnpjEmitente'] ?? '');
        $c = preg_replace('/\D/', '', $e['cnpj']);
        if (strlen($c) === 14) $cnpjPorNome[kg_chave($e['fornecedor'])] = $c;
    }
    foreach ((array)($col['coletas'] ?? []) as $c) { $cc = preg_replace('/\D/', '', (string)($c['cnpj'] ?? '')); if (strlen($cc) === 14) $cnpjPorNome[kg_chave((string)$c['fornecedor'])] ??= $cc; }
    $F = [];
    foreach ($ev as $e) {
        $pc = $e['pc'];
        if (strlen(preg_replace('/\D/', '', $e['cnpj'])) !== 14) $e['cnpj'] = $cnpjPorNome[kg_chave($e['fornecedor'])] ?? '';
        $k = kg_forn_chave($e['cnpj'], $e['fornecedor']);
        if (!$e['geo']) $e['geo'] = $geo[$k] ?? $geo['N' . kg_chave($e['fornecedor'])] ?? null;
        $F[$k] = $F[$k] ?? ['chave' => $k, 'fornecedor' => $e['fornecedor'], 'cnpj' => preg_replace('/\D/', '', $e['cnpj']), 'geo' => $e['geo'], 'eventos' => [], 'pcs' => [], 'dias' => []];
        if (!$F[$k]['geo'] && $e['geo']) $F[$k]['geo'] = $e['geo'];
        $F[$k]['eventos'][] = $e;
        if ($pc) $F[$k]['pcs'][$pc] = $e['data'];
        $F[$k]['dias'][$e['data']][] = $e;
    }
    // km por dia: rota do dia (Matriz → fornecedores → Matriz). Km real informado vale para a viagem do dia.
    $porDia = [];
    foreach ($F as $k => $f) foreach ($f['dias'] as $d => $_) $porDia[$d][] = $k;
    $kmF = []; $kmTipo = []; $diaRes = [];
    foreach ($porDia as $d => $chaves) {
        $ida = [];
        foreach ($chaves as $k) $ida[$k] = ($origem && $F[$k]['geo']) ? 2 * col_distancia_km($origem, $F[$k]['geo']) * KG_FATOR_ESTRADA : null;
        $real = null;
        foreach ($chaves as $k) foreach ($F[$k]['dias'][$d] as $e) if ($e['km_real'] !== null) $real = max($real ?? 0, $e['km_real']);
        // rota estimada (vizinho mais próximo) quando há vários fornecedores no mesmo dia
        $pts = array_filter($chaves, fn($k) => $ida[$k] !== null);
        $rota = null;
        if ($origem && $pts) {
            $atual = $origem; $rest = $pts; $rota = 0.0;
            while ($rest) { $best = null; $bd = INF; foreach ($rest as $k) { $dd = col_distancia_km($atual, $F[$k]['geo']); if ($dd < $bd) { $bd = $dd; $best = $k; } }
                $rota += $bd; $atual = $F[$best]['geo']; $rest = array_values(array_diff($rest, [$best])); }
            $rota = ($rota + col_distancia_km($atual, $origem)) * KG_FATOR_ESTRADA;
        }
        $total = $real ?? $rota;
        $nCol = 0; foreach ($chaves as $k) $nCol += count($F[$k]['dias'][$d]);   // v3.43.0: resumo do dia
        $diaRes[$d] = ['data' => $d, 'coletas' => $nCol, 'fornecedores' => count($chaves), 'km' => $total !== null ? round($total, 1) : null,
            'km_tipo' => $total === null ? 'sem endereço' : ($real !== null ? 'real' : 'estimado')];
        $soma = array_sum(array_filter($ida, fn($v) => $v !== null));
        foreach ($chaves as $k) {
            if ($total === null || $ida[$k] === null) { if ($real !== null && count($chaves) === 1) { $kmF[$k][$d] = $real; $kmTipo[$k]['real'] = true; } continue; }
            $kmF[$k][$d] = $soma > 0 ? $total * $ida[$k] / $soma : $total / count($chaves);
            $kmTipo[$k][$real !== null ? 'real' : 'estimado'] = true;
        }
    }
    // monta saída
    $out = []; $totIdas = 0; $totPcs = 0; $totKm = 0.0; $totValor = 0.0;
    foreach ($F as $k => $f) {
        $idas = count($f['dias']); $pcs = array_keys($f['pcs']);
        $valor = 0.0; $ins = []; $lista = [];
        foreach ($pcs as $pc) {
            $p = $pedidos[$pc] ?? null; $data = $f['pcs'][$pc];
            $valor += kg_valor($p['valorNF'] ?? 0);
            $lista[] = ['pc' => $pc, 'data' => $data, 'valor' => kg_valor($p['valorNF'] ?? 0), 'nf' => (string)($p['numeroNF'] ?? ''), 'itens' => count((array)($p['insumos'] ?? []))];
            foreach ((array)($p['insumos'] ?? []) as $l) {
                $q = (float)($l['quantidade_recebida'] ?? 0) ?: (float)($l['quantidade_pedida'] ?? 0);
                $u = mb_strtoupper(trim((string)($l['unidade'] ?? 'UN'))) ?: 'UN';
                $sku = function_exists('est_sap_code') ? est_sap_code((string)($l['id'] ?? '')) : '';
                $ch = $sku !== '' ? $sku . '|' . $u : kg_chave((string)$l['descricao']) . '|' . $u;
                $ins[$ch] = $ins[$ch] ?? ['descricao' => trim(preg_replace('/\s+/', ' ', (string)$l['descricao'])), 'sku' => $sku, 'unidade' => $u, 'quantidade' => 0.0, 'compras' => 0, 'datas' => [], 'pcs' => []];
                $ins[$ch]['quantidade'] += $q; $ins[$ch]['pcs'][$pc] = true; $ins[$ch]['compras'] = count($ins[$ch]['pcs']); $ins[$ch]['datas'][] = $data;
            }
        }
        // coletas sem PC: usa a observação
        foreach ($f['eventos'] as $e) if (!$e['pc'] && $e['obs'] !== '') $lista[] = ['pc' => null, 'data' => $e['data'], 'valor' => 0, 'nf' => '', 'itens' => 0, 'obs' => mb_substr($e['obs'], 0, 200)];
        usort($lista, fn($a, $b) => strcmp($b['data'], $a['data']));
        // v3.21.1: cada ida (dia) com os PCs, os insumos coletados naquele dia e os km daquela ida
        $idasL = [];
        foreach ($f['dias'] as $dia => $evs) {
            $its = []; $pcsDia = []; $vDia = 0.0;
            foreach ($evs as $e) {
                if (!$e['pc']) continue;
                $pp = $pedidos[$e['pc']] ?? null; $pcsDia[] = $e['pc']; $vDia += kg_valor($pp['valorNF'] ?? 0);
                foreach ((array)($pp['insumos'] ?? []) as $l) {
                    $q = (float)($l['quantidade_recebida'] ?? 0) ?: (float)($l['quantidade_pedida'] ?? 0);
                    $its[] = ['pc' => $e['pc'], 'descricao' => trim(preg_replace('/\s+/', ' ', (string)$l['descricao'])), 'quantidade' => round($q, 3), 'unidade' => mb_strtoupper((string)($l['unidade'] ?? 'UN'))];
                }
            }
            $obs = implode(' · ', array_filter(array_map(fn($e) => $e['pc'] ? '' : $e['obs'], $evs)));
            $idasL[] = ['data' => $dia, 'pcs' => array_values(array_unique($pcsDia)), 'km' => isset($kmF[$k][$dia]) ? round($kmF[$k][$dia], 1) : null,
                'valor' => round($vDia, 2), 'itens' => $its, 'obs' => mb_substr($obs, 0, 300)];
        }
        usort($idasL, fn($a, $b) => strcmp($b['data'], $a['data']));
        $insL = array_values($ins);
        foreach ($insL as &$i) {
            sort($i['datas']); $i['datas'] = array_values(array_unique($i['datas']));
            $n = count($i['datas']);
            $i['intervalo_medio_dias'] = $n > 1 ? round((strtotime(end($i['datas'])) - strtotime($i['datas'][0])) / 86400 / ($n - 1), 1) : null;
            $i['recorrente'] = $i['compras'] >= 3;
            $i['quantidade'] = round($i['quantidade'], 3); unset($i['pcs']);
        }
        unset($i);
        usort($insL, fn($a, $b) => [$b['compras'], $b['quantidade']] <=> [$a['compras'], $a['quantidade']]);
        $km = isset($kmF[$k]) ? array_sum($kmF[$k]) : null;
        $kmIda = $km !== null && $idas ? $km / $idas : null;
        // sugestão: juntar compras — 1 ida a cada 30 dias seria suficiente para os recorrentes?
        $periodoDias = max(1, (strtotime($ate) - strtotime($de)) / 86400);
        $idasIdeais = max(1, (int)ceil($periodoDias / 30));
        $sug = null;
        $rec = count(array_filter($insL, fn($i) => $i['recorrente']));
        if ($idas > $idasIdeais && $rec) $sug = ['idas_possiveis' => $idasIdeais, 'idas_a_menos' => $idas - $idasIdeais,
            'km_a_menos' => $kmIda !== null ? round(($idas - $idasIdeais) * $kmIda) : null, 'insumos_recorrentes' => $rec];
        $out[] = ['fornecedor' => $f['fornecedor'], 'cnpj' => $f['cnpj'], 'idas' => $idas, 'pcs' => count($pcs), 'valor' => round($valor, 2),
            'km' => $km !== null ? round($km, 1) : null, 'km_tipo' => isset($kmTipo[$k]['real']) ? (isset($kmTipo[$k]['estimado']) ? 'misto' : 'real') : ($km !== null ? 'estimado' : 'sem endereço'),
            'km_por_ida' => $kmIda !== null ? round($kmIda, 1) : null, 'ultima_ida' => max(array_keys($f['dias'])),
            'insumos' => array_slice($insL, 0, 80), 'idas_lista' => $idasL, 'insumos_total' => count($insL), 'compras' => $lista, 'sugestao' => $sug];
        $totIdas += $idas; $totPcs += count($pcs); $totKm += $km ?? 0; $totValor += $valor;
    }
    usort($out, fn($a, $b) => [$b['idas'], $b['pcs']] <=> [$a['idas'], $a['pcs']]);
    return ['de' => $de, 'ate' => $ate, 'fator_estrada' => KG_FATOR_ESTRADA, 'origem' => $origem['nome'] ?? 'Matriz',
        'totais' => ['fornecedores' => count($out), 'idas' => $totIdas, 'pcs' => $totPcs, 'km' => round($totKm, 1), 'valor' => round($totValor, 2),
            'sem_endereco' => count(array_filter($out, fn($f) => $f['km'] === null)),
            'km_a_menos' => array_sum(array_map(fn($f) => (int)($f['sugestao']['km_a_menos'] ?? 0), $out)),
            'idas_a_menos' => array_sum(array_map(fn($f) => (int)($f['sugestao']['idas_a_menos'] ?? 0), $out))],
        'fornecedores' => $out, 'dias_resumo' => kg_resumo_dias($diaRes)['dias'], 'meses' => kg_resumo_dias($diaRes)['meses']];
}

/** v3.43.0: coletas, fornecedores e km por dia + totais por mês (km = rota do dia: depósito → fornecedores → depósito). */
function kg_resumo_dias(array $dia): array {
    krsort($dia); $m = [];
    foreach ($dia as $d => $x) {
        $k = substr($d, 0, 7);
        $m[$k] = $m[$k] ?? ['mes' => $k, 'dias' => 0, 'coletas' => 0, 'fornecedores_idas' => 0, 'km' => 0.0, 'dias_sem_km' => 0];
        $m[$k]['dias']++; $m[$k]['coletas'] += $x['coletas']; $m[$k]['fornecedores_idas'] += $x['fornecedores'];
        if ($x['km'] === null) $m[$k]['dias_sem_km']++; else $m[$k]['km'] += $x['km'];
    }
    foreach ($m as &$r) { $r['km'] = round($r['km'], 1); $n = $r['dias'] - $r['dias_sem_km'];
        $r['km_medio_dia'] = $n > 0 ? round($r['km'] / $n, 1) : null; $r['coletas_dia'] = round($r['coletas'] / max(1, $r['dias']), 1); $r['fornecedores_dia'] = round($r['fornecedores_idas'] / max(1, $r['dias']), 1); }
    unset($r);
    return ['dias' => array_values($dia), 'meses' => array_values($m)];
}
