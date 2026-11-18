<?php
/**
 * Recebimento Drilling — Espelho de NF (v3.53.0).
 * Documento para o Fiscal EMITIR a nota (ou para enviar material a uma obra) com os materiais que entraram por pedido de compra:
 * SKU, descrição, unidade (medida), quantidade, valor unitário e total, PC e NF de origem. Reaproveita os helpers e o gerador de PDF
 * do Protocolo/Romaneio. NÃO movimenta o Estoque e NÃO é nota fiscal. Dados em data/espelhos_nf.json.
 *   RASCUNHO → ENVIADO (ao Fiscal) → NF_EMITIDA (nº da nota) · CANCELADO · reabrir (ENVIADO → RASCUNHO, com motivo)
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/romaneios_lib.php';   // prt_str/prt_valor, PrtPdf, rom_fmt_*

const ESP_FILE = DATA_DIR . '/espelhos_nf.json';
const ESP_LOCK = DATA_DIR . '/.espelhos_nf.lock';
const ESP_STATUS = ['RASCUNHO' => 'Rascunho (ainda não enviado ao Fiscal)', 'ENVIADO' => 'Enviado ao Fiscal (aguardando a nota)', 'NF_EMITIDA' => 'Nota emitida', 'CANCELADO' => 'Cancelado'];
const ESP_NATUREZAS = ['Remessa de material para obra', 'Transferência entre filiais/obras', 'Devolução ao fornecedor', 'Remessa para conserto', 'Venda', 'Outra'];

function esp_read(): array {
    $vazio = ['versao' => 1, 'seq' => 0, 'espelhos' => []];
    if (!is_file(ESP_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(ESP_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/espelhos_nf.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + $vazio;
}
function esp_write(array $d): void {
    $tmp = ESP_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar o espelho.', 500);
    if (is_file(ESP_FILE)) @copy(ESP_FILE, ESP_FILE . '.backup.json');
    if (!rename($tmp, ESP_FILE)) prt_fail('Falha ao persistir o espelho.', 500);
}
function esp_with_lock(callable $fn) {
    $fp = @fopen(ESP_LOCK, 'c');
    if (!$fp) prt_fail('Não foi possível travar o arquivo de espelhos.', 500);
    flock($fp, LOCK_EX);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function esp_fmt_val($v): string { return number_format((float)$v, 2, ',', '.'); }

/* ------------------------------------------------------------------ */
/* Validação                                                            */
/* ------------------------------------------------------------------ */
function esp_norm(array $in): array {
    $data = substr(trim((string)($in['data'] ?? '')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');
    $dest = prt_str($in['destinatario'] ?? '', 140);
    if (mb_strlen($dest) < 2) prt_fail('Informe o destinatário (obra, cliente ou filial).');
    $itens = []; $pcs = [];
    foreach (array_slice((array)($in['itens'] ?? []), 0, 300) as $i => $it) {
        if (!is_array($it)) continue;
        $desc = prt_str($it['descricao'] ?? '', 160); $sku = prt_str($it['sku'] ?? '', 40); $qRaw = trim((string)($it['quantidade'] ?? ''));
        if ($desc === '' && $sku === '' && $qRaw === '') continue;
        $q = round(prt_valor($qRaw), 3);
        if ($desc === '' || $q <= 0) prt_fail('Item ' . ($i + 1) . ': informe a descrição e uma quantidade maior que zero.');
        $vu = round(max(0, prt_valor((string)($it['valor_unit'] ?? '0'))), 4);
        $pc = (int)($it['pc'] ?? 0); if ($pc > 0) $pcs[$pc] = true;
        $itens[] = ['sku' => $sku, 'descricao' => $desc, 'unidade' => mb_strtoupper(prt_str($it['unidade'] ?? '', 8)) ?: 'UN', 'quantidade' => $q,
            'valor_unit' => $vu, 'valor_total' => round($q * $vu, 2), 'pc' => $pc ?: null, 'nf_origem' => prt_str($it['nf_origem'] ?? '', 30),
            'fornecedor' => prt_str($it['fornecedor'] ?? '', 100), 'ncm' => preg_replace('/\D/', '', (string)($it['ncm'] ?? '')), 'obs' => prt_str($it['obs'] ?? '', 120)];
    }
    if (!$itens) prt_fail('Inclua ao menos um item no espelho.');
    $nat = prt_str($in['natureza'] ?? '', 80) ?: ESP_NATUREZAS[0];
    return [
        'data' => $data, 'destinatario' => $dest, 'obra_id' => prt_str($in['obra_id'] ?? '', 60), 'destinatario_doc' => prt_str($in['destinatario_doc'] ?? '', 24),
        'destinatario_endereco' => prt_str($in['destinatario_endereco'] ?? '', 200), 'natureza' => $nat, 'cfop' => preg_replace('/\D/', '', (string)($in['cfop'] ?? '')),
        'os' => prt_str($in['os'] ?? '', 30), 'solicitante' => prt_str($in['solicitante'] ?? '', 80), 'transportadora' => prt_str($in['transportadora'] ?? '', 80),
        'placa' => mb_strtoupper(prt_str($in['placa'] ?? '', 12)), 'frete' => round(max(0, prt_valor((string)($in['frete'] ?? '0'))), 2),
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 600), 'itens' => $itens, 'pcs' => array_keys($pcs),
    ];
}
function &esp_get(array &$d, string $id) {
    foreach ($d['espelhos'] as $k => &$r) if ($r['id'] === $id) return $r;
    prt_fail('Espelho não encontrado.', 404);
}
function esp_view(array $r): array {
    $r['status_label'] = ESP_STATUS[$r['status'] ?? ''] ?? ($r['status'] ?? '');
    $r['qtd_itens'] = count($r['itens'] ?? []);
    $r['total_itens'] = round(array_sum(array_map(fn($i) => (float)$i['valor_total'], $r['itens'] ?? [])), 2);
    $r['total_geral'] = round($r['total_itens'] + (float)($r['frete'] ?? 0), 2);
    $r['itens_sem_valor'] = count(array_filter($r['itens'] ?? [], fn($i) => (float)$i['valor_unit'] <= 0));
    return $r;
}
function esp_painel(array $d): array {
    $lista = array_reverse($d['espelhos']); $k = ['RASCUNHO' => 0, 'ENVIADO' => 0, 'NF_EMITIDA' => 0, 'CANCELADO' => 0]; $valor = 0.0;
    foreach ($lista as $r) { $k[$r['status']] = ($k[$r['status']] ?? 0) + 1; if (!in_array($r['status'], ['CANCELADO', 'RASCUNHO'], true)) $valor += esp_view($r)['total_geral']; }
    $dest = []; foreach ($lista as $r) if ($r['status'] !== 'CANCELADO') $dest[$r['destinatario']] = 1;
    return ['kpis' => ['rascunhos' => $k['RASCUNHO'], 'enviados' => $k['ENVIADO'], 'emitidas' => $k['NF_EMITIDA'], 'cancelados' => $k['CANCELADO'], 'valor_em_aberto' => round($valor, 2)],
        'destinatarios' => array_keys($dest), 'naturezas' => ESP_NATUREZAS, 'espelhos' => array_map('esp_view', $lista)];
}
function esp_salvar(array &$d, array $in, string $user): array {
    $n = esp_norm($in); $id = prt_str($in['id'] ?? '', 20); $agora = date('c');
    if ($id !== '') {
        $r = &esp_get($d, $id);
        if ($r['status'] !== 'RASCUNHO') prt_fail('Só rascunho pode ser editado. Use "Reabrir" (volta para rascunho, com motivo) ou "Duplicar".');
        $r = array_merge($r, $n); $r['atualizado_em'] = $agora;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Rascunho atualizado (' . count($n['itens']) . ' item(ns))'];
        return $r;
    }
    $d['seq'] = (int)$d['seq'] + 1;
    $r = ['id' => 'ESP-' . date('Y') . '-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT), 'status' => 'RASCUNHO', 'criado_em' => $agora, 'criado_por' => $user] + $n
        + ['historico' => [['em' => $agora, 'por' => $user, 'acao' => 'Espelho criado (' . count($n['itens']) . ' item(ns))']]];
    $d['espelhos'][] = $r;
    return $r;
}
/** acao: enviar | concluir (nf_numero, nf_data) | reabrir (motivo) | cancelar (motivo) | duplicar */
function esp_status(array &$d, array $in, string $user): array {
    $id = prt_str($in['id'] ?? '', 20); $ac = (string)($in['acao'] ?? ''); $agora = date('c');
    if ($ac === 'duplicar') {
        $o = esp_get($d, $id); $c = $o;
        foreach (['id', 'status', 'criado_em', 'criado_por', 'historico', 'enviado_em', 'enviado_por', 'nf_numero', 'nf_data', 'nf_em', 'cancelado_em', 'motivo_cancelamento', 'atualizado_em'] as $k) unset($c[$k]);
        $c['data'] = date('Y-m-d');
        $n = esp_salvar($d, $c, $user); $nova = &esp_get($d, $n['id']);
        $nova['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Duplicado de ' . $id];
        return $nova;
    }
    $r = &esp_get($d, $id); $st = $r['status'];
    if ($ac === 'enviar') {
        if ($st !== 'RASCUNHO') prt_fail('Só um rascunho pode ser enviado ao Fiscal.');
        if (esp_view($r)['itens_sem_valor'] > 0 && empty($in['confirmar_sem_valor'])) prt_fail('Há item sem valor unitário. O Fiscal precisa do valor — preencha, ou confirme o envio mesmo assim.', 409);
        $r['status'] = 'ENVIADO'; $r['enviado_em'] = $agora; $r['enviado_por'] = $user;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Enviado ao Fiscal'];
    } elseif ($ac === 'concluir') {
        if ($st !== 'ENVIADO') prt_fail('Só um espelho enviado ao Fiscal pode ser concluído.');
        $nf = prt_str($in['nf_numero'] ?? '', 30); if ($nf === '') prt_fail('Informe o número da nota emitida.');
        $dt = substr(trim((string)($in['nf_data'] ?? '')), 0, 10); $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) ? $dt : date('Y-m-d');
        $r['status'] = 'NF_EMITIDA'; $r['nf_numero'] = $nf; $r['nf_data'] = $dt; $r['nf_em'] = $agora;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Nota emitida nº ' . $nf . ' em ' . $dt];
    } elseif ($ac === 'reabrir') {
        if ($st !== 'ENVIADO') prt_fail('Só um espelho enviado pode ser reaberto.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo.');
        $r['status'] = 'RASCUNHO'; $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Reaberto: ' . $m];
    } elseif ($ac === 'cancelar') {
        if (!in_array($st, ['RASCUNHO', 'ENVIADO'], true)) prt_fail('Este espelho não pode mais ser cancelado.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo do cancelamento.');
        $r['status'] = 'CANCELADO'; $r['cancelado_em'] = $agora; $r['motivo_cancelamento'] = $m;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Cancelado: ' . $m];
    } else prt_fail('Ação inválida.');
    return $r;
}

/* ------------------------------------------------------------------ */
/* Itens vindos dos pedidos de compra                                   */
/* ------------------------------------------------------------------ */
function esp_nk(string $s): string { return preg_replace('/[^A-Z0-9]+/', ' ', strtr(mb_strtoupper(trim($s)), ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C'])); }
/** "código | descrição | qtd | un | valor total" (uma linha por item) → lista com valor unitário. */
function esp_parse_itens_nf(string $txt): array {
    $r = [];
    foreach (preg_split('/\r?\n/', $txt) as $ln) {
        $p = array_map('trim', explode('|', $ln)); if (count($p) < 5) continue;
        $q = (float)str_replace(',', '.', $p[2]); $tot = (float)str_replace(',', '.', $p[4]);
        if ($q > 0 && $tot > 0) $r[] = ['cod' => esp_nk($p[0]), 'desc' => esp_nk($p[1]), 'unit' => $tot / $q];
    }
    return $r;
}
/** @return array<int,array> pedidos recebidos que casam com a busca ($q: números de PC e/ou palavras de fornecedor/material). */
function esp_itens_pc(string $q, int $limite = 25): array {
    require_once __DIR__ . '/estoque_lib.php';
    $est = est_read();
    $porSku = []; foreach (($est['itens'] ?? []) as $c => $it) { $porSku[strtoupper($c)] = $it; foreach ((array)($it['origem_ids'] ?? []) as $o) $porSku[strtoupper(est_sap_code((string)$o) ?: (string)$o)] ??= $it; }
    $jaUsado = [];
    foreach (esp_read()['espelhos'] as $e) if (($e['status'] ?? '') !== 'CANCELADO') foreach ($e['itens'] as $i) if (!empty($i['pc'])) { $k = $i['pc'] . '|' . esp_nk($i['sku'] . ' ' . $i['descricao']); $jaUsado[$k] = ($jaUsado[$k] ?? 0) + (float)$i['quantidade']; }
    $nums = []; $palavras = [];
    foreach (preg_split('/[\s,;]+/', trim($q)) as $t) { if ($t === '') continue; if (preg_match('/^\d{3,8}$/', $t)) $nums[(int)$t] = true; else $palavras[] = esp_nk($t); }
    $out = [];
    $ped = function_exists('read_items') ? read_items() : [];
    usort($ped, fn($a, $b) => strcmp((string)($b['primeira_confirmacao_em'] ?? ''), (string)($a['primeira_confirmacao_em'] ?? '')));
    foreach ($ped as $p) {
        $pc = (int)($p['pc'] ?? 0);
        if (!in_array($p['recebimento_estado'] ?? '', ['finalizado', 'em_andamento'], true) && empty($p['entregas'])) continue;
        if ($nums && !isset($nums[$pc])) continue;
        $hay = esp_nk(($p['fornecedor'] ?? '') . ' ' . implode(' ', array_map(fn($l) => ($l['descricao'] ?? '') . ' ' . ($l['sku'] ?? ''), (array)($p['insumos'] ?? []))));
        $ok = true; foreach ($palavras as $w) if ($w !== '' && !str_contains($hay, $w)) { $ok = false; break; }
        if (!$ok) continue;
        $nfl = esp_parse_itens_nf((string)($p['itensNF'] ?? ''));
        $linhas = [];
        foreach ((array)($p['insumos'] ?? []) as $l) {
            $rec = (float)($l['quantidade_recebida'] ?? 0); if ($rec <= 0) continue;
            $sku = trim((string)($l['sku'] ?? '')) ?: est_sap_code((string)($l['id'] ?? ''));
            $dn = esp_nk((string)($l['descricao'] ?? '')); $vu = 0.0; $fonte = 'sem valor — preencher';
            foreach ($nfl as $x) if (($sku !== '' && $x['cod'] === esp_nk($sku)) || ($dn !== '' && ($x['desc'] === $dn || str_starts_with($x['desc'], $dn) || str_starts_with($dn, $x['desc'])))) { $vu = round($x['unit'], 4); $fonte = 'NF do pedido'; break; }
            if ($vu <= 0 && $sku !== '' && isset($porSku[strtoupper($sku)]) && (float)($porSku[strtoupper($sku)]['custo_medio'] ?? 0) > 0) { $vu = round((float)$porSku[strtoupper($sku)]['custo_medio'], 4); $fonte = 'custo médio do estoque'; }
            $ja = $jaUsado[$pc . '|' . esp_nk($sku . ' ' . ($l['descricao'] ?? ''))] ?? 0;
            $linhas[] = ['sku' => $sku, 'descricao' => trim(preg_replace('/\s+/', ' ', (string)$l['descricao'])), 'unidade' => mb_strtoupper((string)($l['unidade'] ?? 'UN')) ?: 'UN',
                'qtd_recebida' => $rec, 'ja_espelhado' => $ja, 'disponivel' => max(0, round($rec - $ja, 3)), 'valor_unit' => $vu, 'valor_fonte' => $fonte];
        }
        if (!$linhas) continue;
        $out[] = ['pc' => $pc, 'fornecedor' => (string)($p['fornecedor'] ?? ''), 'nf' => (string)($p['numeroNF'] ?? ''), 'recebido_em' => substr((string)($p['primeira_confirmacao_em'] ?? ''), 0, 10), 'linhas' => $linhas];
        if (count($out) >= $limite) break;
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Documentos: impressão (2 vias), PDF e CSV                             */
/* ------------------------------------------------------------------ */
function esp_html_impressao(array $r): string {
    $r = esp_view($r);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linhas = '';
    foreach ($r['itens'] as $i => $it)
        $linhas .= '<tr><td class="c">' . ($i + 1) . '</td><td>' . $e($it['sku'] ?: '—') . '</td><td><b>' . $e($it['descricao']) . '</b>' . ($it['ncm'] ? '<br><small>NCM ' . $e($it['ncm']) . '</small>' : '') . '</td><td class="c">' . $e($it['unidade']) . '</td><td class="r"><b>' . $e(rom_fmt_qtd($it['quantidade'])) . '</b></td><td class="r">' . $e(esp_fmt_val($it['valor_unit'])) . '</td><td class="r"><b>' . $e(esp_fmt_val($it['valor_total'])) . '</b></td><td>' . ($it['pc'] ? 'PC ' . $e($it['pc']) : '') . ($it['nf_origem'] ? '<br><small>NF ' . $e($it['nf_origem']) . '</small>' : '') . '</td></tr>';
    $rasc = $r['status'] === 'RASCUNHO' ? '<div class="rasc">RASCUNHO</div>' : ($r['status'] === 'CANCELADO' ? '<div class="rasc">CANCELADO</div>' : '');
    $meta = fn($k, $v, $cls = '') => '<div class="' . $cls . '"><small>' . $e($k) . '</small><b>' . ($v !== '' ? $e($v) : '—') . '</b></div>';
    $via = function (string $titulo) use ($e, $r, $linhas, $meta, $rasc): string {
        return '<section class="via">' . $rasc . '<header><div><div class="emp">DRILLING DO BRASIL</div><div class="sub">Espelho para emissão de nota fiscal</div></div><div class="num"><small>' . $e($titulo) . '</small><br><b>' . $e($r['id']) . '</b></div></header>'
            . '<h1>ESPELHO DE NOTA FISCAL</h1><div class="meta">' . $meta('Data', rom_fmt_data($r['data'])) . $meta('Natureza da operação', $r['natureza'], 'w2') . $meta('CFOP', $r['cfop']) . $meta('Destinatário', $r['destinatario'], 'w2') . $meta('CNPJ / CPF', $r['destinatario_doc']) . $meta('OS', $r['os'])
            . $meta('Endereço de entrega', $r['destinatario_endereco'], 'w4') . $meta('Solicitante', $r['solicitante']) . $meta('Transportadora', $r['transportadora']) . $meta('Placa', $r['placa']) . $meta('Frete (R$)', $r['frete'] > 0 ? esp_fmt_val($r['frete']) : '') . '</div>'
            . '<table><thead><tr><th>#</th><th>SKU</th><th>Descrição</th><th>Un.</th><th>Qtd.</th><th>Vl. unit.</th><th>Vl. total</th><th>Origem</th></tr></thead><tbody>' . $linhas . '</tbody>'
            . '<tfoot><tr><td colspan="6" class="r">Total dos itens</td><td class="r"><b>' . $e(esp_fmt_val($r['total_itens'])) . '</b></td><td></td></tr>' . ($r['frete'] > 0 ? '<tr><td colspan="6" class="r">Frete</td><td class="r">' . $e(esp_fmt_val($r['frete'])) . '</td><td></td></tr><tr><td colspan="6" class="r"><b>Total geral</b></td><td class="r"><b>' . $e(esp_fmt_val($r['total_geral'])) . '</b></td><td></td></tr>' : '') . '</tfoot></table>'
            . ($r['obs'] !== '' ? '<p class="obs"><b>Observações:</b> ' . nl2br($e($r['obs'])) . '</p>' : '')
            . '<p class="peq">Espelho para conferência e emissão. Não é nota fiscal e não tem valor fiscal.' . (!empty($r['nf_numero']) ? ' Nota emitida nº ' . $e($r['nf_numero']) . ' em ' . $e(rom_fmt_data($r['nf_data'])) . '.' : '') . '</p>'
            . '<div class="ass"><div><div class="lin"></div><b>Solicitante / Recebimento</b><small>Nome, assinatura e data</small></div><div><div class="lin"></div><b>Fiscal (emissão)</b><small>Nome, assinatura e data</small></div></div>'
            . '<footer>' . $e($r['id']) . ' · ' . $e($titulo) . '</footer></section>';
    };
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($r['id']) . ' - Espelho de NF</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:12px/1.35 Arial,Helvetica,sans-serif;color:#111}.bar{position:sticky;top:0;z-index:5;background:#1f2a37;color:#fff;padding:10px 14px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.bar button,.bar a{background:#2e7d32;color:#fff;border:0;border-radius:6px;padding:8px 14px;font:inherit;text-decoration:none;cursor:pointer}'
        . '.via{position:relative;width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:14mm 13mm;display:flex;flex-direction:column;box-shadow:0 1px 6px #0003}.rasc{position:absolute;top:44%;left:0;right:0;text-align:center;font-size:54px;font-weight:800;color:#c6282833;transform:rotate(-24deg);pointer-events:none}'
        . 'header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px}.emp{font-size:18px;font-weight:800}.sub{color:#555}.num{text-align:right}.num b{font-size:18px}.num small{color:#555}'
        . 'h1{font-size:15px;text-align:center;margin:14px 0 10px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}.meta div{border:1px solid #bbb;border-radius:4px;padding:5px 7px}.meta small{display:block;color:#555;font-size:9px;text-transform:uppercase}.meta .w2{grid-column:span 2}.meta .w4{grid-column:span 4}'
        . 'table{width:100%;border-collapse:collapse;font-size:11px}th,td{border:1px solid #888;padding:4px 5px;vertical-align:top}th{background:#eee;text-align:left}td.c{text-align:center}td.r{text-align:right}tfoot td{background:#f5f5f5}.obs{margin-top:8px}.peq{font-size:10px;color:#555;margin-top:10px}'
        . '.ass{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:auto;padding-top:46px}.ass .lin{border-top:1px solid #111;margin-bottom:4px}.ass b{display:block}.ass small{color:#555}footer{margin-top:16px;border-top:1px dashed #999;padding-top:6px;color:#555;font-size:10px;text-align:center}'
        . '@media print{body{background:#fff}.bar{display:none}.via{margin:0;box-shadow:none;width:auto;min-height:277mm;page-break-after:always;break-after:page}.via:last-child{page-break-after:auto}@page{size:A4;margin:10mm}}'
        . '@media(max-width:800px){.via{width:auto;margin:8px;padding:12px;min-height:0}.meta{grid-template-columns:1fr 1fr}table{font-size:10px}}'
        . '</style></head><body><div class="bar"><span>Espelho ' . $e($r['id']) . ' — 2 vias (Fiscal e Arquivo). Use "Imprimir" e escolha A4.</span><span style="display:flex;gap:8px"><a href="api.php?action=espelhos_pdf&id=' . rawurlencode($r['id']) . '">⇩ PDF</a><button onclick="print()">Imprimir</button></span></div>'
        . $via('1ª via — Fiscal') . $via('2ª via — Arquivo (Recebimento)') . '</body></html>';
}
function esp_pdf(array $r): string {
    $r = esp_view($r);
    $pdf = new PrtPdf(); $W = PrtPdf::W; $H = PrtPdf::H; $mx = 36; $cw = $W - 2 * $mx;
    $col = [['#', 20, 'C'], ['SKU', 62, 'L'], ['Descrição', 0, 'L'], ['Un.', 26, 'C'], ['Qtd.', 46, 'R'], ['Vl. unit.', 56, 'R'], ['Vl. total', 62, 'R'], ['Origem', 100, 'L']];
    $fixo = 0; foreach ($col as $c) $fixo += $c[1]; foreach ($col as &$c) if ($c[1] === 0) $c[1] = $cw - $fixo; unset($c);
    foreach (['1ª via — Fiscal', '2ª via — Arquivo (Recebimento)'] as $via) {
        $pdf->page(); $y = 40;
        $cab = function () use ($pdf, $mx, $cw, &$y, $r, $via) {
            $pdf->t($mx, $y + 14, 'DRILLING DO BRASIL', 16, true); $pdf->t($mx, $y + 27, 'Espelho para emissão de nota fiscal', 9);
            $pdf->t($mx, $y + 8, $via, 8.5, false, 'R', $cw); $pdf->t($mx, $y + 28, $r['id'], 16, true, 'R', $cw);
            $pdf->line($mx, $y + 35, $mx + $cw, $y + 35, 1.4); $y += 48;
        };
        $cab();
        $pdf->t($mx, $y + 8, 'ESPELHO DE NOTA FISCAL', 14, true, 'C', $cw); $y += 16;
        if (in_array($r['status'], ['RASCUNHO', 'CANCELADO'], true)) $pdf->t($mx, $y + 8, $r['status'] === 'RASCUNHO' ? 'RASCUNHO - ainda nao enviado ao Fiscal' : 'CANCELADO', 10, true, 'C', $cw);
        $y += 14;
        $caixa = function (float $bx, float $by, float $w, string $k, string $v, float $tam = 9) use ($pdf) { $pdf->rect($bx, $by, $w, 30); $pdf->t($bx + 5, $by + 10, $k, 6.5); $pdf->t($bx + 5, $by + 23, $pdf->fit($v !== '' ? $v : '—', $tam, true, $w - 10), $tam, true); };
        $q = ($cw - 18) / 4;
        $caixa($mx, $y, $q, 'DATA', rom_fmt_data($r['data'])); $caixa($mx + $q + 6, $y, 2 * $q + 6, 'NATUREZA DA OPERAÇÃO', $r['natureza']); $caixa($mx + 3 * ($q + 6), $y, $q, 'CFOP', $r['cfop']); $y += 35;
        $caixa($mx, $y, 2 * $q + 6, 'DESTINATÁRIO', $r['destinatario'], 10); $caixa($mx + 2 * ($q + 6), $y, $q, 'CNPJ / CPF', $r['destinatario_doc']); $caixa($mx + 3 * ($q + 6), $y, $q, 'OS', $r['os']); $y += 35;
        $caixa($mx, $y, $cw, 'ENDEREÇO DE ENTREGA', $r['destinatario_endereco']); $y += 35;
        $caixa($mx, $y, $q, 'SOLICITANTE', $r['solicitante']); $caixa($mx + $q + 6, $y, $q, 'TRANSPORTADORA', $r['transportadora']); $caixa($mx + 2 * ($q + 6), $y, $q, 'PLACA', $r['placa']); $caixa($mx + 3 * ($q + 6), $y, $q, 'FRETE (R$)', $r['frete'] > 0 ? esp_fmt_val($r['frete']) : ''); $y += 40;
        $cabTab = function () use ($pdf, $mx, &$y, $col, $cw) {
            $pdf->rect($mx, $y, $cw, 16, 0.6, 0.92); $x = $mx;
            foreach ($col as [$t, $w, $a]) { $pdf->t($x + 3, $y + 11.5, $t, 7.5, true, $a, $w - 6); $x += $w; }
            $y += 16;
        };
        $cabTab();
        foreach ($r['itens'] as $i => $it) {
            $cel = [(string)($i + 1), $it['sku'] ?: '—', $it['descricao'], $it['unidade'], rom_fmt_qtd($it['quantidade']), esp_fmt_val($it['valor_unit']), esp_fmt_val($it['valor_total']), trim(($it['pc'] ? 'PC ' . $it['pc'] : '') . ($it['nf_origem'] ? ' NF ' . $it['nf_origem'] : ''))];
            $lin = array_slice($pdf->wrap($cel[2], 8, true, $col[2][1] - 6) ?: [''], 0, 3); $h = max(17, 7 + 10.5 * count($lin));
            if ($y + $h > $H - 150) { $pdf->page(); $y = 40; $cab(); $cabTab(); }
            $x = $mx; $pdf->rect($mx, $y, $cw, $h, 0.4);
            foreach ($col as $k => [$t, $w, $a]) {
                if ($k > 0) $pdf->line($x, $y, $x, $y + $h, 0.4);
                if ($k === 2) foreach ($lin as $j => $ln) $pdf->t($x + 3, $y + 12 + 10.5 * $j, $ln, 8, true);
                else $pdf->t($x + 3, $y + 12, $pdf->fit($cel[$k], 8, in_array($k, [4, 6], true), $w - 6), 8, in_array($k, [4, 6], true), $a, $w - 6);
                $x += $w;
            }
            $y += $h;
        }
        $pdf->rect($mx, $y, $cw, 17, 0.6, 0.96); $pdf->t($mx, $y + 12, 'Total dos itens: R$ ' . esp_fmt_val($r['total_itens']) . ($r['frete'] > 0 ? '   ·   Frete: R$ ' . esp_fmt_val($r['frete']) . '   ·   TOTAL: R$ ' . esp_fmt_val($r['total_geral']) : ''), 9, true, 'R', $cw - 6); $y += 25;
        if ($r['obs'] !== '') { foreach ($pdf->wrap('Observações: ' . $r['obs'], 9, false, $cw) as $ln) { $pdf->t($mx, $y + 9, $ln, 9); $y += 12; } $y += 4; }
        $pdf->t($mx, $y + 8, 'Espelho para conferência e emissão. Não é nota fiscal e não tem valor fiscal.' . (!empty($r['nf_numero']) ? ' Nota emitida nº ' . $r['nf_numero'] . '.' : ''), 7.5); $y += 14;
        $sig = $H - 110; if ($y > $sig - 10) { $pdf->page(); $y = 40; $cab(); }
        $hw = ($cw - 30) / 2;
        foreach ([['Solicitante / Recebimento', 'Nome, assinatura e data'], ['Fiscal (emissão)', 'Nome, assinatura e data']] as $i => [$a, $b]) {
            $bx = $mx + $i * ($hw + 30); $pdf->line($bx, $sig, $bx + $hw, $sig, 0.8); $pdf->t($bx, $sig + 12, $a, 9.5, true); $pdf->t($bx, $sig + 23, $b, 8);
        }
        $pdf->line($mx, $H - 50, $mx + $cw, $H - 50, 0.4); $pdf->t($mx, $H - 38, $r['id'] . ' · ' . $via, 7.5, false, 'C', $cw);
    }
    return $pdf->out();
}
function esp_csv(array $r): string {
    $r = esp_view($r); $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"'; $n = fn($v) => str_replace('.', ',', (string)$v);
    $o = "\xEF\xBB\xBF" . 'Espelho;' . $q($r['id']) . ";Data;" . $q(rom_fmt_data($r['data'])) . ";Destinatario;" . $q($r['destinatario']) . ";CNPJ/CPF;" . $q($r['destinatario_doc']) . ";Natureza;" . $q($r['natureza']) . ";CFOP;" . $q($r['cfop']) . ";OS;" . $q($r['os']) . ";Status;" . $q($r['status_label']) . "\r\n";
    $o .= "Item;SKU;Descricao;Unidade;Quantidade;Valor unitario;Valor total;PC;NF origem;NCM;Fornecedor;Obs\r\n";
    foreach ($r['itens'] as $i => $it) $o .= ($i + 1) . ';' . $q($it['sku']) . ';' . $q($it['descricao']) . ';' . $q($it['unidade']) . ';' . $n($it['quantidade']) . ';' . $n(number_format($it['valor_unit'], 4, '.', '')) . ';' . $n(number_format($it['valor_total'], 2, '.', '')) . ';' . $q($it['pc']) . ';' . $q($it['nf_origem']) . ';' . $q($it['ncm']) . ';' . $q($it['fornecedor']) . ';' . $q($it['obs']) . "\r\n";
    $o .= ";;;;;Total itens;" . $n(number_format($r['total_itens'], 2, '.', '')) . "\r\n";
    return $o;
}
