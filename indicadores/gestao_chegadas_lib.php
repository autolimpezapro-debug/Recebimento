<?php
/**
 * Recebimento Drilling — Indicador "Chegadas sem NF / sem pedido de compra" (v3.54.2). Somente leitura.
 * Fontes: data/chegadas.json (módulo Chegadas / NF pendente), data/items.json (PCs recebidos sem NF no sistema) e
 * data/estoque.json (entradas manuais = material que entrou sem pedido de compra; ignora a carga histórica da planilha).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

function kpi_chegadas_nf(?string $de, ?string $ate): array {
    date_default_timezone_set('America/Sao_Paulo');
    $ate = $ate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : date('Y-m-d');
    $de = $de && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? $de : date('Y-m-d', strtotime($ate . ' -90 day'));
    $rel = function (string $f) { $r = @file_get_contents(DATA_DIR . '/' . $f); $d = $r ? json_decode($r, true) : null; return is_array($d) ? $d : []; };
    $tipos = ['MATERIAL_SEM_NF' => 'Material sem NF', 'NF_SEM_MATERIAL' => 'NF sem material', 'ONLINE_SEM_NF' => 'Compra online sem NF', 'OUTRO' => 'Outra divergência'];
    $hoje = strtotime(date('Y-m-d') . ' 00:00');

    // 1) Ocorrências registradas no módulo Chegadas
    $reg = []; foreach ((array)($rel('chegadas.json')['registros'] ?? []) as $r) {
        $d = (string)($r['data_chegada'] ?? ''); if ($d < $de || $d > $ate || ($r['status'] ?? '') === 'CANCELADO') continue; $reg[] = $r;
    }
    $porTipo = array_fill_keys(array_keys($tipos), 0); $abertas = 0; $vencidas = 0; $resolvidas = 0; $semPc = 0; $diasRes = []; $forn = []; $mes = []; $lista = []; $aging = ['0-2' => 0, '3-5' => 0, '6-10' => 0, '11+' => 0];
    foreach ($reg as $r) {
        $t = $r['tipo'] ?? 'OUTRO'; $porTipo[$t] = ($porTipo[$t] ?? 0) + 1;
        if (empty($r['pc'])) $semPc++;
        $f = trim((string)($r['fornecedor'] ?? '')) ?: '(não informado)'; $m = substr((string)$r['data_chegada'], 0, 7);
        $forn[$f] ??= ['fornecedor' => $f, 'total' => 0, 'abertas' => 0, 'sem_nf' => 0, 'nf_sem_material' => 0, 'online' => 0];
        $forn[$f]['total']++; $mes[$m] ??= ['mes' => $m, 'total' => 0, 'sem_nf' => 0, 'nf_sem_material' => 0, 'online' => 0, 'sem_pc' => 0]; $mes[$m]['total']++; if (empty($r['pc'])) $mes[$m]['sem_pc']++;
        $k = $t === 'MATERIAL_SEM_NF' ? 'sem_nf' : ($t === 'NF_SEM_MATERIAL' ? 'nf_sem_material' : ($t === 'ONLINE_SEM_NF' ? 'online' : null));
        if ($k) { $forn[$f][$k]++; $mes[$m][$k]++; }
        $ini = strtotime($r['data_chegada'] . ' 00:00');
        if (($r['status'] ?? '') === 'ABERTO') {
            $abertas++; $forn[$f]['abertas']++; $dias = max(0, (int)floor(($hoje - $ini) / 86400)); $venc = $dias > (int)($r['prazo_dias'] ?? 5); if ($venc) $vencidas++;
            $aging[$dias <= 2 ? '0-2' : ($dias <= 5 ? '3-5' : ($dias <= 10 ? '6-10' : '11+'))]++;
            $lista[] = ['id' => $r['id'], 'tipo' => $tipos[$t] ?? $t, 'data' => $r['data_chegada'], 'dias' => $dias, 'vencido' => $venc, 'fornecedor' => $f, 'pc' => $r['pc'] ?? null, 'nf' => $r['nf_numero'] ?? '', 'cobrancas' => count((array)($r['cobrancas'] ?? []))];
        } elseif (($r['status'] ?? '') === 'RESOLVIDO') {
            $resolvidas++; $fim = strtotime(substr((string)($r['resolvido_em'] ?? ''), 0, 10) . ' 00:00'); if ($fim) $diasRes[] = max(0, (int)floor(($fim - $ini) / 86400));
        }
    }
    usort($lista, fn($a, $b) => $b['dias'] <=> $a['dias']);
    uasort($forn, fn($a, $b) => $b['total'] <=> $a['total']); ksort($mes);

    // 2) Pedidos recebidos no período e quantos estão sem NF no sistema
    $recebidos = 0; $semNfSis = []; $manualSis = 0;
    foreach ((array)$rel('items.json') as $p) {
        $d = substr((string)($p['primeira_confirmacao_em'] ?? $p['recebimento_finalizado_em'] ?? ''), 0, 10); if ($d === '' || $d < $de || $d > $ate) continue;
        $recebidos++;
        if (empty($p['numeroNF']) && empty($p['chaveNF']))
            $semNfSis[] = ['pc' => (int)$p['pc'], 'fornecedor' => (string)($p['fornecedor'] ?? ''), 'recebido_em' => $d, 'dias' => max(0, (int)floor(($hoje - strtotime($d . ' 00:00')) / 86400)), 'finalizado' => ($p['recebimento_estado'] ?? '') === 'finalizado'];
    }
    usort($semNfSis, fn($a, $b) => $b['dias'] <=> $a['dias']);

    // 3) Material que entrou no estoque sem pedido de compra (entrada manual feita no sistema; a carga da planilha antiga não conta)
    $manual = [];
    foreach ((array)($rel('estoque.json')['movimentos'] ?? []) as $m) {
        if (($m['tipo'] ?? '') !== 'ENTRADA_MANUAL' || !empty($m['origem_planilha']) || !empty($m['historico'])) continue;
        $d = substr((string)($m['em'] ?? ''), 0, 10); if ($d < $de || $d > $ate) continue;
        $manual[] = ['data' => $d, 'codigo' => (string)($m['codigo'] ?? ''), 'quantidade' => (float)($m['quantidade'] ?? 0), 'fornecedor' => (string)($m['fornecedor'] ?? ''), 'documento' => (string)($m['numero_nf'] ?? $m['documento'] ?? ''), 'obs' => mb_substr((string)($m['observacao'] ?? ''), 0, 80), 'usuario' => (string)($m['usuario'] ?? '')];
    }
    usort($manual, fn($a, $b) => strcmp($b['data'], $a['data']));

    return [
        'periodo' => ['de' => $de, 'ate' => $ate],
        'totais' => ['ocorrencias' => count($reg), 'abertas' => $abertas, 'vencidas' => $vencidas, 'resolvidas' => $resolvidas, 'sem_pc' => $semPc, 'tempo_medio_resolucao_dias' => $diasRes ? round(array_sum($diasRes) / count($diasRes), 1) : null,
            'pcs_recebidos' => $recebidos, 'pcs_sem_nf_sistema' => count($semNfSis), 'pct_sem_nf' => $recebidos ? round(count($semNfSis) / $recebidos * 100, 1) : null,
            'entradas_sem_pc' => count($manual), 'sem_pedido_total' => $semPc + count($manual)],
        'por_tipo' => array_map(fn($k, $v) => ['tipo' => $k, 'rotulo' => $tipos[$k] ?? $k, 'qtd' => $v], array_keys($porTipo), array_values($porTipo)),
        'aging_abertas' => $aging, 'meses' => array_values($mes), 'fornecedores' => array_slice(array_values($forn), 0, 25), 'abertas' => array_slice($lista, 0, 40),
        'pcs_sem_nf' => array_slice($semNfSis, 0, 40), 'entradas_sem_pc' => array_slice($manual, 0, 40),
        'nota' => 'Ocorrências vêm do módulo "Chegadas / NF pendente" (lançamento manual). "Sem pedido de compra" = chegada registrada sem PC + entrada manual no estoque (a carga da planilha antiga não conta).',
    ];
}
