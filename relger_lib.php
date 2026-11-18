<?php
/**
 * Recebimento Drilling — Relatório gerencial (v3.53.0).
 * Materiais que SAÍRAM do estoque com a respectiva OS (obrigatório), por OS / obra / setor / material, mais o que ENTROU (pedidos recebidos).
 * Somente leitura. Valor = custo médio do item (ou custo do último inventário SAP); sem custo, o valor fica 0 e é sinalizado.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';

function rg_data(string $v, string $padrao): string { $v = substr(trim($v), 0, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $padrao; }

function rg_relatorio(string $ini, string $fim): array {
    date_default_timezone_set('America/Sao_Paulo');
    $fim = rg_data($fim, date('Y-m-d')); $ini = rg_data($ini, date('Y-m-01'));
    if ($ini > $fim) [$ini, $fim] = [$fim, $ini];
    $db = est_read(); $itens = $db['itens'] ?? [];
    $custoInv = [];
    foreach ($db['movimentos'] ?? [] as $m) if (($m['tipo'] ?? '') === 'INVENTARIO_SAP' && (float)($m['custo_unitario'] ?? 0) > 0) $custoInv[$m['codigo']] = (float)$m['custo_unitario'];
    $saidas = []; $entradas = [];
    foreach ($db['movimentos'] ?? [] as $m) {
        $dia = substr((string)($m['em'] ?? ''), 0, 10); if ($dia < $ini || $dia > $fim) continue;
        $t = (string)($m['tipo'] ?? ''); $cod = (string)($m['codigo'] ?? ''); $it = $itens[$cod] ?? [];
        $q = (float)($m['quantidade'] ?? 0);
        if ($t === 'SAIDA' || $t === 'ESTORNO_SAIDA') {
            $sinal = $t === 'SAIDA' ? 1 : -1;
            $cu = (float)($it['custo_medio'] ?? 0); $fonte = 'custo médio';
            if ($cu <= 0 && isset($custoInv[$cod])) { $cu = $custoInv[$cod]; $fonte = 'inventário SAP'; }
            if ($cu <= 0) $fonte = 'sem custo';
            $os = trim((string)($m['os'] ?? ''));
            $saidas[] = ['em' => (string)$m['em'], 'dia' => $dia, 'mov' => (string)($m['id'] ?? ''), 'documento' => (string)($m['documento'] ?? ''), 'tipo' => $t,
                'sku' => preg_match('/^EST-/', $cod) ? '' : $cod, 'codigo' => $cod, 'descricao' => (string)($it['descricao'] ?? ($m['descricao_origem'] ?? $cod)), 'unidade' => (string)($it['unidade'] ?? 'UN'),
                'quantidade' => round($sinal * $q, 4), 'os' => $os, 'equipamento' => trim((string)($m['equipamento'] ?? '')), 'obra' => trim((string)($m['obra'] ?? '')), 'setor' => trim((string)($m['setor'] ?? '')),
                'finalidade' => (string)($m['finalidade'] ?? ($m['situacao'] ?? '')), 'solicitante' => (string)($m['solicitante'] ?? ''), 'recebedor' => (string)($m['recebedor'] ?? ''), 'pc' => $m['pc'] ?? null,
                'custo_unit' => round($cu, 4), 'valor' => round($sinal * $q * $cu, 2), 'valor_fonte' => $fonte, 'origem_planilha' => !empty($m['origem_planilha'])];
        } elseif ($t === 'ENTRADA_RECEBIMENTO') {
            $entradas[] = ['dia' => $dia, 'pc' => $m['pc'] ?? null, 'fornecedor' => (string)($m['fornecedor'] ?? ''), 'nf' => (string)($m['numero_nf'] ?? ''), 'sku' => preg_match('/^EST-/', $cod) ? '' : $cod,
                'descricao' => (string)($it['descricao'] ?? ($m['descricao_origem'] ?? $cod)), 'unidade' => (string)($it['unidade'] ?? 'UN'), 'quantidade' => $q];
        }
    }
    usort($saidas, fn($a, $b) => strcmp($b['em'], $a['em']));
    $agr = function (callable $chave) use ($saidas): array {
        $g = [];
        foreach ($saidas as $s) {
            $k = $chave($s); $g[$k] ??= ['chave' => $k, 'movimentos' => 0, 'valor' => 0.0, 'materiais' => [], 'obras' => [], 'equipamentos' => [], 'ultima' => ''];
            $g[$k]['movimentos']++; $g[$k]['valor'] += $s['valor']; if ($s['dia'] > $g[$k]['ultima']) $g[$k]['ultima'] = $s['dia'];
            $mk = $s['codigo']; $g[$k]['materiais'][$mk] ??= ['sku' => $s['sku'], 'descricao' => $s['descricao'], 'unidade' => $s['unidade'], 'quantidade' => 0.0, 'valor' => 0.0];
            $g[$k]['materiais'][$mk]['quantidade'] += $s['quantidade']; $g[$k]['materiais'][$mk]['valor'] += $s['valor'];
            if ($s['obra'] !== '') $g[$k]['obras'][$s['obra']] = 1; if ($s['equipamento'] !== '') $g[$k]['equipamentos'][$s['equipamento']] = 1;
        }
        foreach ($g as &$x) { $x['valor'] = round($x['valor'], 2); $x['materiais'] = array_values(array_map(fn($m) => $m + [], $x['materiais'])); $x['obras'] = array_keys($x['obras']); $x['equipamentos'] = array_keys($x['equipamentos']); } unset($x);
        uasort($g, fn($a, $b) => $b['valor'] <=> $a['valor'] ?: $b['movimentos'] <=> $a['movimentos']);
        return array_values($g);
    };
    $porOs = $agr(fn($s) => $s['os'] !== '' ? $s['os'] : '(sem OS)');
    $porObra = $agr(fn($s) => $s['obra'] !== '' ? $s['obra'] : '(sem obra)');
    $porSetor = $agr(fn($s) => $s['setor'] !== '' ? $s['setor'] : '(sem setor)');
    $porMat = $agr(fn($s) => $s['codigo']);
    $semOs = array_values(array_filter($saidas, fn($s) => $s['os'] === '' && !$s['origem_planilha']));
    $semOsTodas = array_filter($saidas, fn($s) => $s['os'] === '');
    $pcs = []; $forn = [];
    foreach ($entradas as $e) { if ($e['pc']) $pcs[$e['pc']] = 1; $f = $e['fornecedor'] ?: '(sem fornecedor)'; $forn[$f] = ($forn[$f] ?? 0) + 1; }
    arsort($forn);
    $dias = (int)((strtotime($fim) - strtotime($ini)) / 86400) + 1;
    return [
        'periodo' => ['ini' => $ini, 'fim' => $fim, 'dias' => $dias],
        'resumo' => ['saidas_mov' => count($saidas), 'saidas_valor' => round(array_sum(array_column($saidas, 'valor')), 2), 'os_distintas' => count(array_filter($porOs, fn($x) => $x['chave'] !== '(sem OS)')),
            'saidas_sem_os' => count($semOsTodas), 'saidas_sem_os_sistema' => count($semOs), 'saidas_sem_custo' => count(array_filter($saidas, fn($s) => $s['valor_fonte'] === 'sem custo')),
            'entradas_mov' => count($entradas), 'pcs_recebidos' => count($pcs), 'fornecedores_recebidos' => count($forn), 'materiais_distintos' => count($porMat)],
        'saidas' => $saidas, 'por_os' => $porOs, 'por_obra' => $porObra, 'por_setor' => $porSetor, 'top_materiais' => array_slice($porMat, 0, 15),
        'entradas' => $entradas, 'fornecedores' => array_map(fn($k, $v) => ['fornecedor' => $k, 'itens' => $v], array_keys($forn), array_values($forn)),
        'nota' => 'OS só existe nas saídas feitas pelo sistema (requisição/saída com OS). Saídas importadas da planilha antiga não trazem OS e aparecem como "(sem OS)".',
    ];
}
function rg_csv(array $r): string {
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"'; $n = fn($v) => str_replace('.', ',', (string)$v);
    $o = "\xEF\xBB\xBF" . 'Periodo;' . $q($r['periodo']['ini'] . ' a ' . $r['periodo']['fim']) . "\r\n";
    $o .= "Data;Movimento;Documento;SKU;Material;Un;Quantidade;OS;Equipamento;Obra;Setor;Finalidade;Solicitante;Recebedor;PC;Custo unit;Valor;Fonte do custo\r\n";
    foreach ($r['saidas'] as $s) $o .= implode(';', [$q(substr($s['dia'], 8, 2) . '/' . substr($s['dia'], 5, 2) . '/' . substr($s['dia'], 0, 4)), $q($s['mov']), $q($s['documento']), $q($s['sku']), $q($s['descricao']), $q($s['unidade']), $n($s['quantidade']),
        $q($s['os'] !== '' ? $s['os'] : '(sem OS)'), $q($s['equipamento']), $q($s['obra']), $q($s['setor']), $q($s['finalidade']), $q($s['solicitante']), $q($s['recebedor']), $q($s['pc']), $n($s['custo_unit']), $n(number_format($s['valor'], 2, '.', '')), $q($s['valor_fonte'])]) . "\r\n";
    return $o;
}
