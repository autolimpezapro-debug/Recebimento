<?php
/**
 * Recebimento Drilling — Romaneio de Terceiros (v3.39.0).
 * Saída de material da empresa que está em pátio (controlado por outra pessoa, fora do estoque) para uma obra.
 * Não é nota fiscal: é um romaneio de controle, numerado, que pode ser salvo (rascunho), emitido, impresso (2 vias),
 * baixado em PDF/CSV e confirmado na entrega. Não movimenta o Estoque. Dados em data/romaneios.json.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/protocolos_lib.php';   // reaproveita helpers (prt_str, prt_valor) e o gerador de PDF (PrtPdf)

const ROM_FILE = DATA_DIR . '/romaneios.json';
const ROM_LOCK = DATA_DIR . '/.romaneios.lock';
const ROM_STATUS = ['RASCUNHO' => 'Rascunho (salvo, ainda não emitido)', 'EMITIDO' => 'Emitido (material em trânsito)', 'ENTREGUE' => 'Entregue na obra', 'CANCELADO' => 'Cancelado'];

function rom_read(): array {
    $vazio = ['versao' => 1, 'seq' => 0, 'romaneios' => []];
    if (!is_file(ROM_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(ROM_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/romaneios.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + $vazio;
}
function rom_write(array $d): void {
    $tmp = ROM_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar o romaneio.', 500);
    if (is_file(ROM_FILE)) @copy(ROM_FILE, ROM_FILE . '.backup.json');
    if (!rename($tmp, ROM_FILE)) prt_fail('Falha ao persistir o romaneio.', 500);
}
function rom_with_lock(callable $fn) {
    $fp = @fopen(ROM_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) prt_fail('Não foi possível bloquear os romaneios para gravação.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function rom_fmt_qtd($q): string { $q = (float)$q; return rtrim(rtrim(number_format($q, 3, ',', '.'), '0'), ',') ?: '0'; }
function rom_fmt_data($v): string { $v = substr((string)$v, 0, 10); return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v; }

/** Valida e normaliza os dados digitados (cabeçalho + itens). */
function rom_norm(array $in): array {
    $data = substr(trim((string)($in['data'] ?? '')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');
    $obra = prt_str($in['obra_nome'] ?? '', 120);
    if (mb_strlen($obra) < 2) prt_fail('Informe a obra (ou local) de destino.');
    $itens = [];
    foreach (array_slice((array)($in['itens'] ?? []), 0, 300) as $i => $it) {
        if (!is_array($it)) continue;
        $desc = prt_str($it['descricao'] ?? '', 160); $sku = prt_str($it['sku'] ?? '', 40); $qRaw = trim((string)($it['quantidade'] ?? ''));
        if ($desc === '' && $sku === '' && $qRaw === '') continue;           // linha em branco
        $q = round(prt_valor($qRaw), 3);
        if ($desc === '' || $q <= 0) prt_fail('Item ' . ($i + 1) . ': informe a descrição e uma quantidade maior que zero.');
        $itens[] = ['sku' => $sku, 'descricao' => $desc, 'unidade' => mb_strtoupper(prt_str($it['unidade'] ?? '', 8)) ?: 'UN', 'quantidade' => $q, 'obs' => prt_str($it['obs'] ?? '', 120)];
    }
    if (!$itens) prt_fail('Inclua ao menos um item no romaneio.');
    return [
        'data' => $data, 'obra_id' => prt_str($in['obra_id'] ?? '', 60), 'obra_nome' => $obra,
        'origem' => prt_str($in['origem'] ?? '', 100) ?: 'Pátio (terceiros)', 'responsavel_patio' => prt_str($in['responsavel_patio'] ?? '', 80),
        'solicitante' => prt_str($in['solicitante'] ?? '', 80), 'transportadora' => prt_str($in['transportadora'] ?? '', 80),
        'motorista' => prt_str($in['motorista'] ?? '', 80), 'veiculo' => prt_str($in['veiculo'] ?? '', 40), 'placa' => mb_strtoupper(prt_str($in['placa'] ?? '', 12)),
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 600), 'itens' => $itens,
    ];
}
function &rom_get(array &$d, string $id) {
    foreach ($d['romaneios'] as $k => &$r) if ($r['id'] === $id) return $r;
    prt_fail('Romaneio não encontrado.', 404);
}
function rom_view(array $r): array {
    $r['status_label'] = ROM_STATUS[$r['status'] ?? ''] ?? ($r['status'] ?? '');
    $r['qtd_itens'] = count($r['itens'] ?? []);
    $r['total_unidades'] = array_sum(array_map(fn($i) => (float)$i['quantidade'], $r['itens'] ?? []));
    $un = array_unique(array_map(fn($i) => $i['unidade'], $r['itens'] ?? []));
    $r['total_unidades_ok'] = count($un) === 1;      // só faz sentido somar se todos têm a mesma unidade
    return $r;
}
function rom_painel(array $d): array {
    $lista = array_reverse($d['romaneios']); $k = ['RASCUNHO' => 0, 'EMITIDO' => 0, 'ENTREGUE' => 0, 'CANCELADO' => 0];
    foreach ($lista as $r) $k[$r['status']] = ($k[$r['status']] ?? 0) + 1;
    $obras = []; foreach ($lista as $r) if ($r['status'] !== 'CANCELADO') $obras[$r['obra_nome']] = 1;
    return ['kpis' => ['rascunhos' => $k['RASCUNHO'], 'em_transito' => $k['EMITIDO'], 'entregues' => $k['ENTREGUE'], 'cancelados' => $k['CANCELADO']],
        'obras' => array_keys($obras), 'romaneios' => array_map('rom_view', $lista)];
}
const ROM_CAMPOS = ['data' => 'Data', 'origem' => 'Origem', 'responsavel_patio' => 'Responsável pelo pátio', 'obra_nome' => 'Obra de destino', 'solicitante' => 'Solicitante',
    'transportadora' => 'Transportadora', 'motorista' => 'Motorista', 'veiculo' => 'Veículo', 'placa' => 'Placa', 'obs' => 'Observações'];
/** Diferenças entre o romaneio gravado e os dados novos: [{campo, de, para}] (cabeçalho e itens). */
function rom_diff(array $r, array $n): array {
    $d = [];
    foreach (ROM_CAMPOS as $k => $rot) {
        $a = (string)($r[$k] ?? ''); $b = (string)($n[$k] ?? '');
        if ($a !== $b) $d[] = ['campo' => $rot, 'de' => $k === 'data' ? rom_fmt_data($a) : $a, 'para' => $k === 'data' ? rom_fmt_data($b) : $b];
    }
    $chave = fn($it) => mb_strtoupper($it['sku'] . '|' . $it['descricao']);
    $ant = []; foreach ($r['itens'] as $it) $ant[$chave($it)] = $it;
    $nov = []; foreach ($n['itens'] as $it) $nov[$chave($it)] = $it;
    $fmt = fn($it) => rom_fmt_qtd($it['quantidade']) . ' ' . $it['unidade'];
    foreach ($nov as $k => $it) {
        if (!isset($ant[$k])) { $d[] = ['campo' => 'Item incluído', 'de' => '', 'para' => $it['descricao'] . ' — ' . $fmt($it)]; continue; }
        $o = $ant[$k];
        if ($fmt($o) !== $fmt($it)) $d[] = ['campo' => 'Quantidade — ' . $it['descricao'], 'de' => $fmt($o), 'para' => $fmt($it)];
        if ($o['obs'] !== $it['obs']) $d[] = ['campo' => 'Obs. do item — ' . $it['descricao'], 'de' => $o['obs'], 'para' => $it['obs']];
    }
    foreach ($ant as $k => $it) if (!isset($nov[$k])) $d[] = ['campo' => 'Item removido', 'de' => $it['descricao'] . ' — ' . $fmt($it), 'para' => ''];
    return $d;
}
/** Cria (sem id) ou atualiza um romaneio. Rascunho edita livremente; emitido/entregue também pode ser editado, mas exige motivo e registra as alterações. */
function rom_salvar(array &$d, array $in, string $user): array {
    $n = rom_norm($in); $id = prt_str($in['id'] ?? '', 20);
    if ($id !== '') {
        $r = &rom_get($d, $id);
        if ($r['status'] === 'CANCELADO') prt_fail('Romaneio cancelado não pode ser editado. Use "Duplicar" para criar um novo.');
        $agora = date('c');
        if ($r['status'] === 'RASCUNHO') {
            $r = array_merge($r, $n); $r['atualizado_em'] = $agora;
            $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Rascunho atualizado (' . count($n['itens']) . ' item(ns))'];
            return $r;
        }
        $motivo = prt_str($in['motivo_alteracao'] ?? '', 200);
        if (mb_strlen($motivo) < 3) prt_fail('Informe o motivo da alteração.');
        $dif = rom_diff($r, $n);
        if (!$dif) prt_fail('Nada foi alterado.');
        $r = array_merge($r, $n); $r['revisao'] = (int)($r['revisao'] ?? 0) + 1; $r['alterado_em'] = $agora; $r['alterado_por'] = $user; $r['atualizado_em'] = $agora;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Alterado após ' . ($r['status'] === 'ENTREGUE' ? 'a entrega' : 'a emissão') . ' (revisão ' . $r['revisao'] . '): ' . $motivo, 'motivo' => $motivo, 'alteracoes' => array_slice($dif, 0, 80)];
        return $r;
    }
    $d['seq'] = (int)$d['seq'] + 1;
    $r = ['id' => 'ROM-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT), 'tipo' => 'TERCEIROS', 'status' => 'RASCUNHO', 'criado_em' => date('c'), 'criado_por' => $user] + $n
        + ['historico' => [['em' => date('c'), 'por' => $user, 'acao' => 'Romaneio criado (' . count($n['itens']) . ' item(ns))']]];
    $d['romaneios'][] = $r;
    return $r;
}
/** acao: emitir | entregar (recebido_por, data) | cancelar (motivo) | duplicar */
function rom_status(array &$d, array $in, string $user): array {
    $id = prt_str($in['id'] ?? '', 20); $ac = (string)($in['acao'] ?? ''); $agora = date('c');
    if ($ac === 'duplicar') {
        $o = rom_get($d, $id); $copia = $o;
        foreach (['id', 'status', 'criado_em', 'criado_por', 'historico', 'emitido_em', 'emitido_por', 'entregue_em', 'recebido_por', 'cancelado_em', 'motivo_cancelamento', 'atualizado_em', 'revisao', 'alterado_em', 'alterado_por'] as $k) unset($copia[$k]);
        $copia['data'] = date('Y-m-d'); $copia['obs'] = $o['obs'];
        $n = rom_salvar($d, $copia, $user); $nova = &rom_get($d, $n['id']);
        $nova['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Duplicado de ' . $id];
        return $nova;
    }
    $r = &rom_get($d, $id); $st = $r['status'];
    if ($ac === 'emitir') {
        if ($st !== 'RASCUNHO') prt_fail('Só um rascunho pode ser emitido.');
        $r['status'] = 'EMITIDO'; $r['emitido_em'] = $agora; $r['emitido_por'] = $user;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Romaneio emitido'];
    } elseif ($ac === 'entregar') {
        if ($st !== 'EMITIDO') prt_fail('Só um romaneio emitido pode ser marcado como entregue.');
        $rec = prt_str($in['recebido_por'] ?? '', 80); if (mb_strlen($rec) < 2) prt_fail('Informe quem recebeu o material na obra.');
        $dt = substr(trim((string)($in['data'] ?? '')), 0, 10); $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) ? $dt : date('Y-m-d');
        $r['status'] = 'ENTREGUE'; $r['entregue_em'] = $dt; $r['recebido_por'] = $rec;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Entregue na obra (recebido por ' . $rec . ') em ' . $dt];
    } elseif ($ac === 'cancelar') {
        if (!in_array($st, ['RASCUNHO', 'EMITIDO'], true)) prt_fail('Este romaneio não pode mais ser cancelado.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo do cancelamento.');
        $r['status'] = 'CANCELADO'; $r['cancelado_em'] = $agora; $r['motivo_cancelamento'] = $m;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Cancelado: ' . $m];
    } else prt_fail('Ação inválida.');
    return $r;
}

/** Página de impressão (A4), 2 vias, com campos de assinatura. */
function rom_html_impressao(array $r): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linhas = '';
    foreach ($r['itens'] as $i => $it)
        $linhas .= '<tr><td class="c">' . ($i + 1) . '</td><td>' . $e($it['sku'] ?: '—') . '</td><td><b>' . $e($it['descricao']) . '</b></td><td class="c">' . $e($it['unidade']) . '</td><td class="r"><b>' . $e(rom_fmt_qtd($it['quantidade'])) . '</b></td><td>' . $e($it['obs']) . '</td></tr>';
    $rasc = ($r['status'] ?? '') === 'RASCUNHO' ? '<div class="rasc">RASCUNHO — não emitido</div>' : (($r['status'] ?? '') === 'CANCELADO' ? '<div class="rasc">CANCELADO</div>' : '');
    $meta = fn($k, $v) => '<div><small>' . $e($k) . '</small><b>' . ($v !== '' ? $e($v) : '—') . '</b></div>';
    $via = function (string $titulo) use ($e, $r, $linhas, $meta, $rasc): string {
        return '<section class="via">' . $rasc . '<header><div><div class="emp">DRILLING DO BRASIL</div><div class="sub">Controle de materiais</div></div><div class="num"><small>' . $e($titulo) . '</small><br><b>' . $e($r['id']) . '</b>' . (!empty($r['revisao']) ? '<br><small>Revisão ' . (int)$r['revisao'] . ' — alterado em ' . $e(date('d/m/Y H:i', strtotime((string)$r['alterado_em']))) . '</small>' : '') . '</div></header>'
            . '<h1>ROMANEIO DE SAÍDA DE MATERIAL — TERCEIROS</h1>'
            . '<div class="meta">' . $meta('Data', rom_fmt_data($r['data'])) . $meta('Origem', $r['origem']) . '<div class="w2"><small>Responsável pelo pátio</small><b>' . ($r['responsavel_patio'] !== '' ? $e($r['responsavel_patio']) : '—') . '</b></div>'
            . '<div class="w4 dest"><small>Destino (obra)</small><b>' . $e($r['obra_nome']) . '</b></div>'
            . $meta('Solicitante', $r['solicitante']) . $meta('Transportadora', $r['transportadora']) . $meta('Motorista', $r['motorista']) . $meta('Veículo / placa', trim($r['veiculo'] . ' ' . $r['placa'])) . '</div>'
            . '<table><thead><tr><th>#</th><th>Código / SKU</th><th>Descrição do material</th><th>Un.</th><th>Qtd.</th><th>Observação</th></tr></thead><tbody>' . $linhas . '</tbody>'
            . '<tfoot><tr><td colspan="6" class="r"><b>' . count($r['itens']) . ' item(ns) listado(s)</b></td></tr></tfoot></table>'
            . ($r['obs'] !== '' ? '<p class="obs"><b>Observações:</b> ' . nl2br($e($r['obs'])) . '</p>' : '')
            . '<p class="peq">Documento de controle interno de movimentação de material. Não substitui nota fiscal.</p>'
            . '<div class="ass"><div><div class="lin"></div><b>Liberado por (pátio)</b><small>Nome, assinatura e data</small></div><div><div class="lin"></div><b>Transportador</b><small>Nome, assinatura e data</small></div><div><div class="lin"></div><b>Recebido na obra</b><small>Nome, assinatura, data e hora</small></div></div>'
            . '<footer>Romaneio ' . $e($r['id']) . ' · ' . $e($titulo) . '</footer></section>';
    };
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($r['id']) . ' - Romaneio</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:12px/1.35 Arial,Helvetica,sans-serif;color:#111}'
        . '.bar{position:sticky;top:0;z-index:5;background:#1f2a37;color:#fff;padding:10px 14px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.bar button,.bar a{background:#2e7d32;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}.bar a{background:#3b6ea8}'
        . '.via{position:relative;width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:14mm 13mm;display:flex;flex-direction:column;box-shadow:0 1px 6px #0003}'
        . '.rasc{position:absolute;top:44%;left:0;right:0;text-align:center;font-size:54px;font-weight:800;color:#c6282833;transform:rotate(-24deg);pointer-events:none}'
        . 'header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px}.emp{font-size:18px;font-weight:800}.sub{color:#555}.num{text-align:right}.num b{font-size:18px}.num small{color:#555}'
        . 'h1{font-size:15px;text-align:center;margin:14px 0 10px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}.meta div{border:1px solid #bbb;border-radius:4px;padding:5px 7px}.meta small{display:block;color:#555;font-size:9px;text-transform:uppercase}.meta b{font-size:11.5px}.meta .w2{grid-column:span 2}.meta .w4{grid-column:span 4}.meta .dest b{font-size:14px}'
        . 'table{width:100%;border-collapse:collapse;font-size:11px}th,td{border:1px solid #888;padding:4px 5px;vertical-align:top}th{background:#eee;text-align:left}td.c{text-align:center}td.r{text-align:right}tfoot td{background:#f5f5f5}'
        . '.obs{margin-top:8px}.peq{font-size:10px;color:#555;margin-top:10px}.ass{display:grid;grid-template-columns:1fr 1fr 1fr;gap:22px;margin-top:auto;padding-top:46px}.ass .lin{border-top:1px solid #111;margin-bottom:4px}.ass b{display:block}.ass small{color:#555}'
        . 'footer{margin-top:16px;border-top:1px dashed #999;padding-top:6px;color:#555;font-size:10px;text-align:center}'
        . '@media print{body{background:#fff}.bar{display:none}.via{margin:0;box-shadow:none;width:auto;min-height:277mm;page-break-after:always;break-after:page}.via:last-child{page-break-after:auto;break-after:auto}@page{size:A4;margin:10mm}}'
        . '@media(max-width:800px){.via{width:auto;margin:8px;padding:12px;min-height:0}.meta{grid-template-columns:1fr 1fr}.meta .w4{grid-column:span 2}table{font-size:10px}.ass{grid-template-columns:1fr}}'
        . '</style></head><body><div class="bar"><span>Romaneio ' . $e($r['id']) . ' — 2 vias (Obra e Arquivo). Use "Imprimir" e escolha A4.</span><span style="display:flex;gap:8px"><a href="api.php?action=romaneios_pdf&id=' . rawurlencode($r['id']) . '">Baixar PDF</a><button onclick="window.print()">Imprimir</button></span></div>'
        . $via('1ª via — Obra') . $via('2ª via — Arquivo (pátio/Recebimento)') . '</body></html>';
}
function rom_pdf(array $r): string {
    $pdf = new PrtPdf(); $W = PrtPdf::W; $H = PrtPdf::H; $mx = 40; $cw = $W - 2 * $mx;
    $col = [['#', 24, 'C'], ['Código / SKU', 80, 'L'], ['Descrição do material', 0, 'L'], ['Un.', 34, 'C'], ['Qtd.', 58, 'R'], ['Observação', 120, 'L']];
    $fixo = 0; foreach ($col as $c) $fixo += $c[1]; foreach ($col as &$c) if ($c[1] === 0) $c[1] = $cw - $fixo; unset($c);
    $al = fn($a) => $a === 'R' ? 'R' : ($a === 'C' ? 'C' : 'L');
    foreach (['1ª via — Obra', '2ª via — Arquivo (pátio/Recebimento)'] as $via) {
        $pdf->page(); $y = 44;
        $cab = function () use ($pdf, $mx, $cw, &$y, $r, $via) {
            $pdf->t($mx, $y + 14, 'DRILLING DO BRASIL', 17, true); $pdf->t($mx, $y + 28, 'Controle de materiais', 9);
            $pdf->t($mx, $y + 8, $via . (!empty($r['revisao']) ? ' · Revisao ' . (int)$r['revisao'] . ' (alterado em ' . date('d/m/Y H:i', strtotime((string)$r['alterado_em'])) . ')' : ''), 8.5, false, 'R', $cw); $pdf->t($mx, $y + 28, $r['id'], 17, true, 'R', $cw);
            $pdf->line($mx, $y + 36, $mx + $cw, $y + 36, 1.4); $y += 50;
        };
        $cab();
        $pdf->t($mx, $y + 8, 'ROMANEIO DE SAÍDA DE MATERIAL — TERCEIROS', 14, true, 'C', $cw); $y += 18;
        if (in_array($r['status'], ['RASCUNHO', 'CANCELADO'], true)) { $pdf->t($mx, $y + 8, $r['status'] === 'RASCUNHO' ? 'RASCUNHO - ainda nao emitido' : 'CANCELADO', 10, true, 'C', $cw); }
        $y += 14;
        $bw = ($cw - 18) / 4;
        $caixa = function (float $bx, float $by, float $w, string $k, string $v, float $tam = 9.5) use ($pdf) { $pdf->rect($bx, $by, $w, 32); $pdf->t($bx + 5, $by + 10, $k, 6.5); $pdf->t($bx + 5, $by + 24, $pdf->fit($v !== '' ? $v : '—', $tam, true, $w - 10), $tam, true); };
        $caixa($mx, $y, $bw, 'DATA', rom_fmt_data($r['data'])); $caixa($mx + $bw + 6, $y, $bw, 'ORIGEM', $r['origem']); $caixa($mx + 2 * ($bw + 6), $y, 2 * $bw + 6, 'RESPONSÁVEL PELO PÁTIO', $r['responsavel_patio']);
        $y += 38; $caixa($mx, $y, $cw, 'DESTINO (OBRA)', $r['obra_nome'], 12); $y += 38;
        $caixa($mx, $y, $bw, 'SOLICITANTE', $r['solicitante']); $caixa($mx + $bw + 6, $y, $bw, 'TRANSPORTADORA', $r['transportadora']); $caixa($mx + 2 * ($bw + 6), $y, $bw, 'MOTORISTA', $r['motorista']); $caixa($mx + 3 * ($bw + 6), $y, $bw, 'VEÍCULO / PLACA', trim($r['veiculo'] . ' ' . $r['placa']));
        $y += 46;
        $cabTab = function () use ($pdf, $mx, &$y, $col, $cw, $al) {
            $pdf->rect($mx, $y, $cw, 17, 0.6, 0.92); $x = $mx;
            foreach ($col as [$t, $w, $a]) { $pdf->t($x + 3, $y + 12, $t, 8, true, $al($a), $w - 6); $x += $w; }
            $y += 17;
        };
        $cabTab();
        foreach ($r['itens'] as $i => $it) {
            $cel = [(string)($i + 1), $it['sku'] ?: '—', $it['descricao'], $it['unidade'], rom_fmt_qtd($it['quantidade']), $it['obs']];
            $quebra = [];                                                   // descrição e observação quebram em até 3 linhas
            foreach ([2, 5] as $k) $quebra[$k] = array_slice($pdf->wrap($cel[$k], 8.5, $k === 2, $col[$k][1] - 6) ?: [''], 0, 3);
            $n = max(count($quebra[2]), count($quebra[5])); $h = max(18, 7 + 11 * $n);
            if ($y + $h > $H - 60) { $pdf->page(); $y = 44; $cab(); $cabTab(); }
            $x = $mx; $pdf->rect($mx, $y, $cw, $h, 0.4);
            foreach ($col as $k => [$t, $w, $a]) {
                if ($k > 0) $pdf->line($x, $y, $x, $y + $h, 0.4);
                if (isset($quebra[$k])) foreach ($quebra[$k] as $j => $ln) $pdf->t($x + 3, $y + 12.5 + 11 * $j, $ln, 8.5, $k === 2);
                else $pdf->t($x + 3, $y + 12.5, $pdf->fit($cel[$k], 8.5, $k === 4, $w - 6), 8.5, $k === 4, $al($a), $w - 6);
                $x += $w;
            }
            $y += $h;
        }
        $pdf->rect($mx, $y, $cw, 19, 0.6, 0.96); $pdf->t($mx, $y + 13, count($r['itens']) . ' item(ns) listado(s)', 9, true, 'R', $cw - 6); $y += 27;
        if ($r['obs'] !== '') { foreach ($pdf->wrap('Observações: ' . $r['obs'], 9, false, $cw) as $ln) { $pdf->t($mx, $y + 9, $ln, 9); $y += 12; } $y += 4; }
        $pdf->t($mx, $y + 8, 'Documento de controle interno de movimentação de material. Não substitui nota fiscal.', 7.5); $y += 14;
        $sig = $H - 150; if ($y > $sig - 10) { $pdf->page(); $y = 44; $cab(); }
        $hw = ($cw - 40) / 3;
        foreach ([['Liberado por (pátio)', 'Nome, assinatura e data'], ['Transportador', 'Nome, assinatura e data'], ['Recebido na obra', 'Nome, assinatura, data e hora']] as $i => [$a, $b]) {
            $bx = $mx + $i * ($hw + 20); $pdf->line($bx, $sig, $bx + $hw, $sig, 0.8);
            $pdf->t($bx, $sig + 13, $pdf->fit($a, 9.5, true, $hw), 9.5, true); $pdf->t($bx, $sig + 24, $pdf->fit($b, 8, false, $hw), 8);
        }
        $pdf->line($mx, $H - 62, $mx + $cw, $H - 62, 0.4);
        $pdf->t($mx, $H - 50, 'Romaneio ' . $r['id'] . ' · ' . $via, 7.5, false, 'C', $cw);
    }
    return $pdf->out();
}
function rom_csv(array $r): string {
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
    $o = "\xEF\xBB\xBF" . 'Romaneio;' . $q($r['id']) . ";Data;" . $q(rom_fmt_data($r['data'])) . ";Origem;" . $q($r['origem']) . ";Destino;" . $q($r['obra_nome']) . ";Responsavel patio;" . $q($r['responsavel_patio']) . ";Status;" . $q(ROM_STATUS[$r['status']] ?? $r['status']) . "\r\n";
    $o .= "Item;Codigo/SKU;Descricao;Unidade;Quantidade;Observacao\r\n";
    foreach ($r['itens'] as $i => $it) $o .= ($i + 1) . ';' . $q($it['sku']) . ';' . $q($it['descricao']) . ';' . $q($it['unidade']) . ';' . str_replace('.', ',', (string)$it['quantidade']) . ';' . $q($it['obs']) . "\r\n";
    return $o;
}


/** v3.62.0 — AJUDA OPCIONAL no romaneio: traz os itens de um PC pelo número. Só leitura; o operador pode editar tudo ou digitar tudo à mão. */
function rom_buscar_pc(int $pc): array {
    $out = ['status' => 'nao_encontrado', 'mensagem' => 'PC ' . $pc . ' não encontrado.', 'linhas' => []];
    if ($pc <= 0 || !function_exists('read_items')) return $out;
    $it = null; foreach (read_items() as $p) if ((int)($p['pc'] ?? 0) === $pc) { $it = $p; break; }
    if (!$it) return $out;
    $ins = array_values(array_filter((array)($it['insumos'] ?? []), 'is_array')); $rec = false;
    foreach ($ins as $l) if ((float)($l['quantidade_recebida'] ?? 0) > 0) { $rec = true; break; }
    foreach ($ins as $l) {
        $q = $rec ? (float)($l['quantidade_recebida'] ?? 0) : (float)($l['quantidade_pedida'] ?? 0);
        if ($q <= 0) continue;
        $out['linhas'][] = ['sku' => (string)($l['sku'] ?? ''), 'descricao' => (string)($l['descricao'] ?? ''), 'unidade' => (string)(($l['unidade'] ?? '') ?: 'UN'), 'quantidade' => $q];
    }
    $out += ['pc' => $pc, 'fornecedor' => (string)($it['fornecedor'] ?? ''), 'obra' => (string)($it['obra'] ?? '')];
    $out['status'] = $out['linhas'] ? 'ok' : 'sem_linhas';
    $out['mensagem'] = !$out['linhas'] ? 'Este PC não tem itens com quantidade.' : ($rec ? '' : 'Este PC ainda não teve material recebido: trouxe as quantidades PEDIDAS. Confira.');
    return $out;
}
/** v3.62.0 — procura material pelo código (SKU) no estoque e nos pedidos; se não achar o código, sugere por parte do nome. */
function rom_buscar_sku(string $q): array {
    $q = trim($q); $out = ['status' => 'nao_encontrado', 'candidatos' => []];
    if (mb_strlen($q) < 2) return $out;
    $u = mb_strtoupper($q);
    $est = json_decode((string)@file_get_contents(DATA_DIR . '/estoque.json'), true) ?: [];
    foreach ((array)($est['itens'] ?? []) as $k => $i) {
        $cods = array_filter([(string)$k, (string)($i['codigo'] ?? ''), (string)($i['sku'] ?? ''), (string)($i['sku_origem'] ?? '')]);
        $desc = (string)($i['nome_planilha'] ?? $i['descricao'] ?? '');
        $exato = false; foreach ($cods as $c) if (mb_strtoupper($c) === $u) $exato = true;
        if ($exato || (mb_strlen($u) >= 4 && mb_stripos($desc, $q) !== false)) {
            $c = ['sku' => (string)($i['codigo'] ?? $k), 'descricao' => $desc, 'unidade' => (string)(($i['unidade'] ?? '') ?: 'UN'), 'saldo' => isset($i['saldo']) ? (float)$i['saldo'] : null, 'exato' => $exato];
            if ($exato) { array_unshift($out['candidatos'], $c); } elseif (count($out['candidatos']) < 8) $out['candidatos'][] = $c;
        }
    }
    if (!$out['candidatos'] && function_exists('read_items')) {
        foreach (read_items() as $p) foreach ((array)($p['insumos'] ?? []) as $l) {
            if (is_array($l) && mb_strtoupper((string)($l['sku'] ?? '')) === $u) { $out['candidatos'][] = ['sku' => (string)$l['sku'], 'descricao' => (string)($l['descricao'] ?? ''), 'unidade' => (string)(($l['unidade'] ?? '') ?: 'UN'), 'saldo' => null, 'exato' => true]; break 2; }
        }
    }
    if ($out['candidatos']) $out['status'] = 'ok';
    return $out;
}


/** v3.62.1 — sugestões para o formulário (motorista, placa, veículo, transportadora, colaborador e função), montadas SÓ do que o sistema já registrou:
 *  romaneios, DANFEs lidas no Painel, remessas do Painel, motoristas cadastrados, usuários, saídas/requisições do estoque e empréstimos de ferramentas.
 *  Só leitura; o formulário continua aceitando qualquer texto digitado. */
function rom_sugestoes(): array {
    $lerJ = fn(string $f) => json_decode((string)@file_get_contents(DATA_DIR . '/' . $f), true) ?: [];
    $kn = fn($t) => preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string)$t)));
    $kp = fn($t) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string)$t));
    $mot = []; $pla = []; $trn = []; $pes = [];
    $melhor = function (array $cont) { arsort($cont); return (string)array_key_first($cont); };
    $reg = function (string $nome, string $placa, string $veic, string $transp, string $dia) use (&$mot, &$pla, &$trn, $kn, $kp) {
        $nome = trim($nome); $placa = trim($placa); $veic = trim($veic); $transp = trim($transp);
        if ($nome !== '' && mb_strlen($nome) >= 2) {
            $k = $kn($nome); $m = &$mot[$k]; $m['nome'][$nome] = ($m['nome'][$nome] ?? 0) + 1; $m['usos'] = ($m['usos'] ?? 0) + 1; if ($dia > ($m['ultimo'] ?? '')) $m['ultimo'] = $dia;
            foreach (['placa' => $placa, 'veiculo' => $veic, 'transportadora' => $transp] as $c => $v) if ($v !== '') $m[$c][$v] = ($m[$c][$v] ?? 0) + 1; unset($m);
        }
        if ($placa !== '' && $kp($placa) !== '') {
            $k = $kp($placa); $p = &$pla[$k]; $p['placa'][$placa] = ($p['placa'][$placa] ?? 0) + 1; $p['usos'] = ($p['usos'] ?? 0) + 1;
            foreach (['motorista' => $nome, 'veiculo' => $veic, 'transportadora' => $transp] as $c => $v) if ($v !== '') $p[$c][$v] = ($p[$c][$v] ?? 0) + 1; unset($p);
        }
        if ($transp !== '') { $k = $kn($transp); $trn[$k][$transp] = ($trn[$k][$transp] ?? 0) + 1; }
    };
    foreach ((array)($lerJ('romaneios.json')['romaneios'] ?? []) as $r) {
        if (($r['status'] ?? '') === 'CANCELADO') continue;
        $reg((string)($r['motorista'] ?? ''), (string)($r['placa'] ?? ''), (string)($r['veiculo'] ?? ''), (string)($r['transportadora'] ?? ''), (string)($r['data'] ?? ''));
    }
    foreach ((array)$lerJ('indicadores_danfe.json') as $n) if (is_array($n)) $reg((string)($n['motorista'] ?? ''), (string)($n['placa'] ?? ''), '', (string)($n['carrier'] ?? ''), (string)($n['emissao'] ?? ''));
    foreach ((array)($lerJ('indicadores.json')['movimentos'] ?? []) as $m) $reg((string)($m['driver'] ?? ''), (string)($m['plate'] ?? ''), '', (string)($m['carrier'] ?? ''), (string)($m['month'] ?? '') . '-01');
    $ma = $lerJ('motorista_acesso.json'); if (!empty($ma['nome']) && empty($ma['revogado'])) $reg((string)$ma['nome'], '', '', '', '');
    // colaboradores + função
    $pe = function (string $nome, string $funcao = '') use (&$pes, $kn) {
        $nome = trim($nome); if (mb_strlen($nome) < 2) return; $k = $kn($nome);
        $x = &$pes[$k]; $x['nome'][$nome] = ($x['nome'][$nome] ?? 0) + 1; if (trim($funcao) !== '') $x['funcao'][trim($funcao)] = ($x['funcao'][trim($funcao)] ?? 0) + 1; unset($x);
    };
    foreach ((array)($lerJ('permissoes.json')['usuarios'] ?? []) as $u) if (($u['status'] ?? 'ativo') === 'ativo') $pe((string)($u['nome'] ?? ''), (string)(($u['cargo'] ?? '') ?: ($u['setor'] ?? '')));
    $est = $lerJ('estoque.json');
    foreach ((array)($est['requisicoes'] ?? []) as $q) { $pe((string)($q['solicitante'] ?? ''), (string)($q['setor'] ?? '')); if (!empty($q['assinatura']['nome'])) $pe((string)$q['assinatura']['nome'], (string)($q['setor'] ?? '')); }
    foreach ((array)($est['movimentos'] ?? []) as $m) if (!empty($m['solicitante'])) $pe((string)$m['solicitante']);
    foreach ((array)($lerJ('ferramentas.json')['emprestimos'] ?? []) as $e) if (!empty($e['responsavel'])) $pe((string)$e['responsavel']);
    foreach ((array)($lerJ('romaneios.json')['romaneios'] ?? []) as $r) { $pe((string)($r['responsavel_patio'] ?? '')); $pe((string)($r['solicitante'] ?? '')); }
    // saída
    $outM = []; foreach ($mot as $m) $outM[] = ['nome' => $melhor($m['nome']), 'placa' => isset($m['placa']) ? $melhor($m['placa']) : '', 'veiculo' => isset($m['veiculo']) ? $melhor($m['veiculo']) : '', 'transportadora' => isset($m['transportadora']) ? $melhor($m['transportadora']) : '', 'usos' => $m['usos'] ?? 0, 'ultimo' => $m['ultimo'] ?? ''];
    $outP = []; foreach ($pla as $p) $outP[] = ['placa' => $melhor($p['placa']), 'motorista' => isset($p['motorista']) ? $melhor($p['motorista']) : '', 'veiculo' => isset($p['veiculo']) ? $melhor($p['veiculo']) : '', 'transportadora' => isset($p['transportadora']) ? $melhor($p['transportadora']) : '', 'usos' => $p['usos'] ?? 0];
    $outT = []; foreach ($trn as $t) $outT[] = $melhor($t);
    $outV = []; foreach ($mot as $m) foreach (array_keys($m['veiculo'] ?? []) as $v) $outV[$kn($v)] = $v;
    $outC = []; foreach ($pes as $x) $outC[] = ['nome' => $melhor($x['nome']), 'funcao' => isset($x['funcao']) ? $melhor($x['funcao']) : ''];
    $ord = fn($a, $b) => strcmp(mb_strtoupper((string)($a['nome'] ?? $a['placa'] ?? $a)), mb_strtoupper((string)($b['nome'] ?? $b['placa'] ?? $b)));
    usort($outM, $ord); usort($outP, $ord); usort($outC, $ord); sort($outT); $outV = array_values($outV); sort($outV);
    return ['motoristas' => $outM, 'placas' => $outP, 'transportadoras' => $outT, 'veiculos' => $outV, 'colaboradores' => $outC];
}
