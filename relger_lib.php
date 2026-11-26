<?php
/**
 * Recebimento Drilling — Relatório gerencial (v3.53.0).
 * Materiais que SAÍRAM do estoque com a respectiva OS (obrigatório), por OS / obra / setor / material, mais o que ENTROU (pedidos recebidos).
 * Somente leitura. Valor = custo médio do item (ou custo do último inventário SAP); sem custo, o valor fica 0 e é sinalizado.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';

function rg_data(string $v, string $padrao): string { $v = substr(trim($v), 0, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $padrao; }

function rg_relatorio(string $ini, string $fim, bool $soComOs = false): array {   // $soComOs (v3.58.1): só saídas que têm OS/OM
    date_default_timezone_set('America/Sao_Paulo');
    $fim = rg_data($fim, date('Y-m-d')); $ini = rg_data($ini, date('Y-m-01'));
    if ($ini > $fim) [$ini, $fim] = [$fim, $ini];
    $db = est_read(); $itens = $db['itens'] ?? [];
    $custoInv = [];
    foreach ($db['movimentos'] ?? [] as $m) if (($m['tipo'] ?? '') === 'INVENTARIO_SAP' && (float)($m['custo_unitario'] ?? 0) > 0) $custoInv[$m['codigo']] = (float)$m['custo_unitario'];
    // v3.60.1: SKU e preço vindos do PC — índice dos pedidos (linhas e itens da NF)
    $pcsIdx = [];
    foreach ((function_exists('read_items') ? read_items() : (json_decode((string)@file_get_contents(DATA_DIR . '/items.json'), true) ?: [])) as $p) {
        $n = (int)($p['pc'] ?? 0); if ($n <= 0) continue;
        $nf = [];
        foreach (preg_split('/\r?\n/', (string)($p['itensNF'] ?? '')) as $l) {
            $c = array_map('trim', explode('|', $l)); if (count($c) < 5) continue;
            $qt = (float)str_replace(',', '.', $c[2]); $vt = (float)str_replace(',', '.', $c[4]);
            if ($qt > 0 && $vt > 0) $nf[] = ['desc' => mb_strtoupper($c[1]), 'unit' => $vt / $qt];
        }
        $pcsIdx[$n] = ['linhas' => array_values((array)($p['insumos'] ?? [])), 'nf' => $nf, 'nfnum' => (string)($p['numeroNF'] ?? '')];
    }
    $rgPcLinha = function ($pc, string $linhaId, string $desc) use ($pcsIdx): ?array {
        $P = $pcsIdx[(int)$pc] ?? null; if (!$P) return null;
        foreach ($P['linhas'] as $l) if ($linhaId !== '' && strtolower((string)($l['id'] ?? '')) === strtolower($linhaId)) return $l;
        $d = mb_strtoupper(trim($desc));
        foreach ($P['linhas'] as $l) if ($d !== '' && mb_strtoupper(trim((string)($l['descricao'] ?? ''))) === $d) return $l;
        return count($P['linhas']) === 1 ? $P['linhas'][0] : null;
    };
    $rgPcPreco = function ($pc, string $desc) use ($pcsIdx): float {   // preço unitário pela NF vinculada ao PC
        $P = $pcsIdx[(int)$pc] ?? null; if (!$P || !$P['nf']) return 0.0;
        if (count($P['nf']) === 1) return $P['nf'][0]['unit'];
        $w = array_filter(preg_split('/[^A-Z0-9]+/', mb_strtoupper($desc)), fn($x) => strlen($x) > 2);
        $melhor = 0.0; $pts = 0;
        foreach ($P['nf'] as $l) { $k = 0; foreach ($w as $x) if (strpos($l['desc'], $x) !== false) $k++; if ($k > $pts) { $pts = $k; $melhor = $l['unit']; } }
        return $pts >= 2 ? $melhor : 0.0;
    };
    $saidas = []; $entradas = [];
    foreach ($db['movimentos'] ?? [] as $m) {
        $dia = substr((string)($m['em'] ?? ''), 0, 10); if ($dia < $ini || $dia > $fim) continue;
        $t = (string)($m['tipo'] ?? ''); $cod = (string)($m['codigo'] ?? ''); $it = $itens[$cod] ?? [];
        $q = (float)($m['quantidade'] ?? 0);
        if ($t === 'SAIDA' || $t === 'ESTORNO_SAIDA') {
            if ($soComOs && trim((string)($m['os'] ?? '')) === '') continue;
            $sinal = $t === 'SAIDA' ? 1 : -1;
            $cu = (float)($it['custo_medio'] ?? 0); $fonte = 'custo médio';
            if ($cu <= 0 && isset($custoInv[$cod])) { $cu = $custoInv[$cod]; $fonte = 'inventário SAP'; }
            $descM = (string)($it['descricao'] ?? ($m['descricao_origem'] ?? $cod));
            $linhaPc = !empty($m['pc']) ? $rgPcLinha($m['pc'], (string)($m['linha_id'] ?? ''), (string)($m['descricao_origem'] ?? $descM)) : null;
            $skuPc = $linhaPc ? trim((string)($linhaPc['sku'] ?? '')) : '';
            if ($cu <= 0 && !empty($m['pc'])) { $pp = $rgPcPreco($m['pc'], (string)($m['descricao_origem'] ?? $descM)); if ($pp > 0) { $cu = $pp; $fonte = 'NF do PC ' . (int)$m['pc']; } }
            if ($cu <= 0) $fonte = 'sem custo';
            $os = trim((string)($m['os'] ?? ''));
            $saidas[] = ['em' => (string)$m['em'], 'dia' => $dia, 'mov' => (string)($m['id'] ?? ''), 'documento' => (string)($m['documento'] ?? ''), 'tipo' => $t,
                'sku' => preg_match('/^EST-/', $cod) ? $skuPc : $cod, 'sku_pc' => $skuPc, 'codigo' => $cod, 'descricao' => $descM, 'unidade' => (string)($it['unidade'] ?? 'UN'),
                'quantidade' => round($sinal * $q, 4), 'os' => $os, 'equipamento' => trim((string)($m['equipamento'] ?? '')), 'obra' => trim((string)($m['obra'] ?? '')), 'setor' => trim((string)($m['setor'] ?? '')),
                'finalidade' => (string)($m['finalidade'] ?? ($m['situacao'] ?? '')), 'solicitante' => (string)($m['solicitante'] ?? ''), 'recebedor' => (string)($m['recebedor'] ?? ''), 'pc' => $m['pc'] ?? null,
                'aplicacao' => trim(implode(' — ', array_filter([trim((string)($m['equipamento'] ?? '')), trim((string)($m['finalidade'] ?? ''))], fn($x) => $x !== ''))),
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
        'periodo' => ['ini' => $ini, 'fim' => $fim, 'dias' => $dias, 'so_com_os' => $soComOs],
        'resumo' => ['saidas_mov' => count($saidas), 'saidas_valor' => round(array_sum(array_column($saidas, 'valor')), 2), 'os_distintas' => count(array_filter($porOs, fn($x) => $x['chave'] !== '(sem OS)')),
            'saidas_sem_os' => count($semOsTodas), 'saidas_sem_os_sistema' => count($semOs), 'saidas_sem_custo' => count(array_filter($saidas, fn($s) => $s['valor_fonte'] === 'sem custo')),
            'entradas_mov' => count($entradas), 'pcs_recebidos' => count($pcs), 'fornecedores_recebidos' => count($forn), 'materiais_distintos' => count($porMat)],
        'saidas' => $saidas, 'por_os' => $porOs, 'por_obra' => $porObra, 'por_setor' => $porSetor, 'top_materiais' => array_slice($porMat, 0, 15),
        'entradas' => $entradas, 'fornecedores' => array_map(fn($k, $v) => ['fornecedor' => $k, 'itens' => $v], array_keys($forn), array_values($forn)),
        'nota' => 'OS só existe nas saídas feitas pelo sistema (requisição/saída com OS). Saídas importadas da planilha antiga não trazem OS e aparecem como "(sem OS)".',
    ];
}
function rg_csv(array $r): string {   // texto que começa com = + - @ ganha ' na frente (não vira fórmula no Excel)
    $q = fn($v) => '"' . str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v) . '"';   $n = fn($v) => str_replace('.', ',', (string)$v);
    $o = "\xEF\xBB\xBF" . 'Periodo;' . $q($r['periodo']['ini'] . ' a ' . $r['periodo']['fim']) . "\r\n";
    $o .= "Data;Hora;PC;SKU do PC;SKU;Material;Un;Quantidade;Aplicacao;Setor;OS;Valor unit;Valor total;Fonte do valor;Equipamento;Obra;Finalidade;Solicitante;Recebedor;Movimento;Documento\r\n";
    foreach ($r['saidas'] as $s) $o .= implode(';', [$q(substr($s['dia'], 8, 2) . '/' . substr($s['dia'], 5, 2) . '/' . substr($s['dia'], 0, 4)), $q(substr($s['em'], 11, 5)), $q($s['pc']), $q($s['sku_pc'] ?? ''), $q($s['sku']), $q($s['descricao']), $q($s['unidade']), $n($s['quantidade']),
        $q($s['aplicacao'] ?? ''), $q($s['setor']), $q($s['os'] !== '' ? $s['os'] : '(sem OS)'), $n($s['custo_unit']), $n(number_format($s['valor'], 2, '.', '')), $q($s['valor_fonte']), $q($s['equipamento']), $q($s['obra']), $q($s['finalidade']), $q($s['solicitante']), $q($s['recebedor']), $q($s['mov']), $q($s['documento'])]) . "\r\n";
    return $o;
}
