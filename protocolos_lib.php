<?php
/**
 * Recebimento Drilling — Protocolo de NFs (v3.36.0).
 * O Recebimento escolhe pedidos já recebidos que têm número de NF, gera um protocolo numerado, imprime (2 vias) e o
 * Financeiro assina. O sistema acompanha: GERADO → ENTREGUE → ASSINADO (via assinada anexada), para a NF não sumir.
 * Dados em data/protocolos_nf.json; vias assinadas em data/protocolos_docs/ (bloqueado para acesso direto).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const PRT_FILE = DATA_DIR . '/protocolos_nf.json';
const PRT_LOCK = DATA_DIR . '/.protocolos_nf.lock';
const PRT_DIR = DATA_DIR . '/protocolos_docs';
const PRT_MAX_DOC = 15 * 1024 * 1024;
const PRT_STATUS = ['GERADO' => 'Gerado (aguardando entrega)', 'ENTREGUE' => 'Entregue ao Financeiro (aguardando via assinada)', 'ASSINADO' => 'Assinado e arquivado', 'CANCELADO' => 'Cancelado'];

function prt_fail($m, $c = 422) { respond(['ok' => false, 'error' => $m], $c); }
function prt_str($v, $n = 200) { return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)$v))), 0, $n); }
function prt_read(): array {
    $vazio = ['versao' => 1, 'seq' => 0, 'protocolos' => []];
    if (!is_file(PRT_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(PRT_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/protocolos_nf.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + $vazio;
}
function prt_write(array $d): void {
    $tmp = PRT_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar o protocolo.', 500);
    if (is_file(PRT_FILE)) @copy(PRT_FILE, PRT_FILE . '.backup.json');
    if (!rename($tmp, PRT_FILE)) prt_fail('Falha ao persistir o protocolo.', 500);
}
function prt_with_lock(callable $fn) {
    $fp = @fopen(PRT_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) prt_fail('Não foi possível bloquear os protocolos para gravação.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function prt_doc_dir(): void {
    if (!is_dir(PRT_DIR)) @mkdir(PRT_DIR, 0750, true);
    if (!is_file(PRT_DIR . '/.htaccess')) @file_put_contents(PRT_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n");
}
function prt_chave(int $pc, string $nf, string $serie): string { return $pc . '|' . mb_strtoupper(trim($nf)) . '|' . mb_strtoupper(trim($serie)); }
/** "1.234,56" / "1234.56" / "R$ 1.234,56" → float (0 se não der para ler). */
function prt_valor($v): float {
    $s = preg_replace('/[^0-9,.\-]/', '', (string)$v);
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (preg_match('/\.\d{3}$/', $s) && substr_count($s, '.') >= 1 && strlen($s) > 4) $s = str_replace('.', '', $s);
    return (float)$s;
}

/** Chaves (PC|NF|série) já ligadas a um protocolo que não foi cancelado. */
function prt_em_uso(array $d): array {
    $uso = [];
    foreach ($d['protocolos'] as $p) {
        if (($p['status'] ?? '') === 'CANCELADO') continue;
        foreach ($p['linhas'] as $l) $uso[prt_chave((int)$l['pc'], (string)$l['numeroNF'], (string)($l['serieNF'] ?? ''))] = $p['id'];
    }
    return $uso;
}
/** Pedidos já recebidos (finalizados). Com NF e ainda sem protocolo = candidatos; sem NF = pendência para o Recebimento corrigir. */
function prt_candidatos(array $items, array $d): array {
    $uso = prt_em_uso($d); $cand = []; $semNf = [];
    foreach ($items as $it) {
        if (($it['recebimento_estado'] ?? '') !== 'finalizado' || empty($it['recebimento_finalizado_em'])) continue;
        $nf = trim((string)($it['numeroNF'] ?? ''));
        if ($nf === '') { $semNf[] = ['pc' => (int)$it['pc'], 'fornecedor' => (string)$it['fornecedor'], 'data' => (string)$it['data']]; continue; }
        if (isset($uso[prt_chave((int)$it['pc'], $nf, (string)($it['serieNF'] ?? ''))])) continue;
        $cand[] = prt_linha($it);
    }
    usort($cand, fn($a, $b) => strcmp($b['data'], $a['data']) ?: ($b['pc'] <=> $a['pc']));
    return ['candidatos' => $cand, 'sem_nf' => $semNf];
}
function prt_linha(array $it): array {
    return [
        'pc' => (int)$it['pc'], 'fornecedor' => prt_str($it['fornecedor'] ?? '', 120),
        'numeroNF' => prt_str($it['numeroNF'] ?? '', 40), 'serieNF' => prt_str($it['serieNF'] ?? '', 10),
        'emissaoNF' => prt_str($it['emissaoNF'] ?? '', 20), 'valorNF' => prt_str($it['valorNF'] ?? '', 30),
        'chaveNF' => preg_replace('/\D/', '', (string)($it['chaveNF'] ?? '')),
        'data' => (string)($it['data'] ?? ''), 'solicitante' => prt_str($it['solicitante'] ?? '', 80),
    ];
}

function prt_view(array $p): array {
    $total = 0.0; $semValor = 0;
    foreach ($p['linhas'] as $l) { $v = prt_valor($l['valorNF'] ?? ''); if ($v > 0) $total += $v; else $semValor++; }
    $p['total_valor'] = round($total, 2); $p['linhas_sem_valor'] = $semValor; $p['qtd_nfs'] = count($p['linhas']);
    $p['status_label'] = PRT_STATUS[$p['status'] ?? ''] ?? ($p['status'] ?? '');
    $p['documentos'] = array_values(array_map(fn($a, $i) => $a + ['idx' => $i, 'url' => 'api.php?action=protocolos_arquivo&id=' . rawurlencode($p['id']) . '&i=' . $i],
        $p['documentos'] ?? [], array_keys($p['documentos'] ?? [])));
    return $p;
}
function prt_painel(array $d, array $items): array {
    $lista = array_map('prt_view', array_reverse($d['protocolos']));
    $c = prt_candidatos($items, $d);
    $abertos = array_values(array_filter($lista, fn($p) => in_array($p['status'], ['GERADO', 'ENTREGUE'], true)));
    $hoje = new DateTime('today');
    foreach ($lista as &$p) {
        $p['dias_aberto'] = null;
        if (in_array($p['status'], ['GERADO', 'ENTREGUE'], true)) $p['dias_aberto'] = (int)$hoje->diff(new DateTime(substr((string)$p['criado_em'], 0, 10)))->days;
    }
    unset($p);
    return [
        'kpis' => [
            'candidatos' => count($c['candidatos']), 'sem_nf' => count($c['sem_nf']),
            'aguardando_entrega' => count(array_filter($lista, fn($p) => $p['status'] === 'GERADO')),
            'aguardando_assinatura' => count(array_filter($lista, fn($p) => $p['status'] === 'ENTREGUE')),
            'assinados' => count(array_filter($lista, fn($p) => $p['status'] === 'ASSINADO')),
        ],
        'candidatos' => $c['candidatos'], 'sem_nf' => array_slice($c['sem_nf'], 0, 200), 'protocolos' => $lista,
        'fornecedores' => array_values(array_unique(array_map(fn($x) => $x['fornecedor'], $c['candidatos']))),
    ];
}

/** Cria o protocolo com as NFs escolhidas. body: linhas[{pc, numeroNF}], destinatario, obs. */
function prt_criar(array &$d, array $in, array $items, string $user): array {
    $sel = array_slice((array)($in['linhas'] ?? []), 0, 300);
    if (!$sel) prt_fail('Selecione ao menos um pedido com NF.');
    $uso = prt_em_uso($d); $linhas = []; $vistos = [];
    $porPc = []; foreach ($items as $it) $porPc[(int)$it['pc']] = $it;
    foreach ($sel as $s) {
        $pc = (int)($s['pc'] ?? 0); $it = $porPc[$pc] ?? null;
        if (!$it) prt_fail('Pedido ' . $pc . ' não encontrado.');
        if (($it['recebimento_estado'] ?? '') !== 'finalizado') prt_fail('O pedido ' . $pc . ' ainda não foi recebido (finalizado).');
        $nf = trim((string)($it['numeroNF'] ?? ''));
        if ($nf === '') prt_fail('O pedido ' . $pc . ' não tem número de NF informado. Cadastre a NF no pedido antes.');
        $k = prt_chave($pc, $nf, (string)($it['serieNF'] ?? ''));
        if (isset($vistos[$k])) continue; $vistos[$k] = 1;
        if (isset($uso[$k])) prt_fail('A NF ' . $nf . ' do pedido ' . $pc . ' já está no protocolo ' . $uso[$k] . '.');
        $linhas[] = prt_linha($it);
    }
    usort($linhas, fn($a, $b) => strcmp($a['fornecedor'], $b['fornecedor']) ?: strcmp($a['numeroNF'], $b['numeroNF']));
    $d['seq'] = (int)$d['seq'] + 1;
    $p = [
        'id' => 'PNF-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT),
        'criado_em' => date('c'), 'criado_por' => $user,
        'destinatario' => prt_str($in['destinatario'] ?? '', 80) ?: 'Financeiro',
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 600),
        'status' => 'GERADO', 'linhas' => $linhas, 'documentos' => [], 'historico' => [['em' => date('c'), 'por' => $user, 'acao' => 'Protocolo gerado com ' . count($linhas) . ' NF(s)']],
    ];
    $d['protocolos'][] = $p;
    return $p;
}
function &prt_get(array &$d, string $id) {
    foreach ($d['protocolos'] as $k => &$p) if ($p['id'] === $id) return $p;
    prt_fail('Protocolo não encontrado.', 404);
}
/** acao: entregar (recebido_por, data) | assinar (recebido_por opcional, data opcional) | cancelar (motivo) | reabrir (volta ao GERADO). */
function prt_status(array &$d, array $in, string $user): array {
    $p = &prt_get($d, prt_str($in['id'] ?? '', 20)); $ac = (string)($in['acao'] ?? ''); $agora = date('c');
    $data = substr(trim((string)($in['data'] ?? '')), 0, 10); $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : date('Y-m-d');
    $rec = prt_str($in['recebido_por'] ?? '', 80);
    $st = $p['status'];
    if ($ac === 'entregar') {
        if ($st !== 'GERADO') prt_fail('Só um protocolo "Gerado" pode ser marcado como entregue.');
        if (mb_strlen($rec) < 2) prt_fail('Informe quem do Financeiro recebeu o protocolo.');
        $p['status'] = 'ENTREGUE'; $p['entregue_em'] = $data; $p['recebido_por'] = $rec;
        $p['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Entregue ao ' . $p['destinatario'] . ' (recebido por ' . $rec . ') em ' . $data];
    } elseif ($ac === 'assinar') {
        if (!in_array($st, ['GERADO', 'ENTREGUE'], true)) prt_fail('Este protocolo não está aguardando assinatura.');
        if (empty($p['documentos'])) prt_fail('Anexe a via assinada (foto ou PDF) antes de arquivar como assinado.');
        if ($rec !== '') $p['recebido_por'] = $rec;
        if (empty($p['entregue_em'])) $p['entregue_em'] = $data;
        $p['status'] = 'ASSINADO'; $p['assinado_em'] = $data;
        $p['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Via assinada arquivada (assinado em ' . $data . ')'];
    } elseif ($ac === 'cancelar') {
        if ($st === 'ASSINADO') prt_fail('Protocolo assinado não pode ser cancelado. Reabra-o primeiro, se for mesmo necessário.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo do cancelamento.');
        $p['status'] = 'CANCELADO'; $p['cancelado_em'] = $agora; $p['motivo_cancelamento'] = $m;
        $p['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Cancelado: ' . $m . ' (as NFs voltam a ficar disponíveis)'];
    } elseif ($ac === 'reabrir') {
        if (!in_array($st, ['ASSINADO'], true)) prt_fail('Só um protocolo assinado pode ser reaberto.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo da reabertura.');
        $p['status'] = 'ENTREGUE'; unset($p['assinado_em']);
        $p['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Reaberto: ' . $m];
    } else prt_fail('Ação inválida.');
    return $p;
}
/** Anexa a via assinada (multipart: id, arquivo). PDF/JPG/PNG/WEBP até 15 MB. */
function prt_doc_add(array &$d, string $user): array {
    $p = &prt_get($d, prt_str($_POST['id'] ?? '', 20));
    if (($p['status'] ?? '') === 'CANCELADO') prt_fail('Protocolo cancelado não recebe arquivos.');
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) prt_fail('Selecione o arquivo da via assinada (PDF ou foto).');
    if ($f['size'] > PRT_MAX_DOC) prt_fail('Arquivo maior que 15 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) prt_fail('Formato não aceito. Envie PDF, JPG, PNG ou WEBP.');
    prt_doc_dir();
    $nome = preg_replace('/[^A-Za-z0-9-]/', '', $p['id']) . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], PRT_DIR . '/' . $nome)) prt_fail('Não foi possível salvar o arquivo.', 500);
    @chmod(PRT_DIR . '/' . $nome, 0640);
    $orig = prt_str(preg_replace('/[^\p{L}\p{N} ._()-]/u', '', (string)$f['name']), 120) ?: ('via-assinada.' . $ext);
    $p['documentos'][] = ['nome' => $orig, 'arquivo' => $nome, 'mime' => $mime, 'tamanho' => (int)$f['size'], 'enviado_em' => date('c'), 'enviado_por' => $user];
    $p['historico'][] = ['em' => date('c'), 'por' => $user, 'acao' => 'Via assinada anexada: ' . $orig];
    return $p;
}
function prt_doc_servir(array $d): void {
    $id = (string)($_GET['id'] ?? ''); $i = (int)($_GET['i'] ?? -1); $a = null;
    foreach ($d['protocolos'] as $p) if ($p['id'] === $id) { $a = $p['documentos'][$i] ?? null; break; }
    if (!$a) { http_response_code(404); exit('Arquivo não encontrado'); }
    $f = PRT_DIR . '/' . basename($a['arquivo']);
    if (!is_file($f)) { http_response_code(404); exit('Arquivo não encontrado no servidor'); }
    header('Content-Type: ' . $a['mime']); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $a['nome']) . '"');
    header('Content-Length: ' . filesize($f)); readfile($f); exit;
}

/** Página de impressão (A4), 2 vias, com campos de assinatura. */
function prt_html_impressao(array $p): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $fmtD = function ($v) { $v = substr((string)$v, 0, 10); return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v; };
    $money = fn($f) => 'R$ ' . number_format($f, 2, ',', '.');
    $v = prt_view($p);
    $linhas = '';
    foreach ($p['linhas'] as $i => $l) {
        $val = prt_valor($l['valorNF']);
        $nf = $e($l['numeroNF']) . ($l['serieNF'] !== '' ? ' <small>/ s.' . $e($l['serieNF']) . '</small>' : '');
        $linhas .= '<tr><td class="c">' . ($i + 1) . '</td><td><b>' . $nf . '</b></td><td class="c">' . $e($l['pc']) . '</td><td>' . $e($l['fornecedor']) . '</td><td class="c">'
            . $e($fmtD($l['emissaoNF'])) . '</td><td class="c">' . $e($fmtD($l['data'])) . '</td><td class="r">' . ($val > 0 ? $e($money($val)) : '—') . '</td><td class="ck"></td></tr>';
    }
    $via = function (string $titulo) use ($e, $p, $v, $linhas, $money, $fmtD): string {
        return '<section class="via"><header><div><div class="emp">DRILLING DO BRASIL</div><div class="sub">Recebimento de materiais</div></div>'
            . '<div class="num"><small>' . $e($titulo) . '</small><br><b>' . $e($p['id']) . '</b></div></header>'
            . '<h1>PROTOCOLO DE ENTREGA DE NOTAS FISCAIS</h1>'
            . '<div class="meta"><div><small>De</small><b>Recebimento</b></div><div><small>Para</small><b>' . $e($p['destinatario']) . '</b></div>'
            . '<div><small>Data de emissão</small><b>' . $e(date('d/m/Y', strtotime((string)$p['criado_em']))) . '</b></div>'
            . '<div><small>Emitido por</small><b>' . $e($p['criado_por']) . '</b></div></div>'
            . '<p class="txt">Encaminhamos ao setor <b>' . $e($p['destinatario']) . '</b> as notas fiscais abaixo, referentes a materiais já conferidos e recebidos. '
            . 'A assinatura deste protocolo confirma o recebimento dos <b>documentos</b> listados.</p>'
            . '<table><thead><tr><th>#</th><th>NF / série</th><th>Pedido (PC)</th><th>Fornecedor</th><th>Emissão</th><th>Recebido em</th><th>Valor da NF</th><th>Conf.</th></tr></thead><tbody>'
            . $linhas . '</tbody><tfoot><tr><td colspan="6" class="r"><b>' . (int)$v['qtd_nfs'] . ' nota(s) fiscal(is)</b></td><td class="r"><b>' . $e($money($v['total_valor'])) . '</b></td><td></td></tr></tfoot></table>'
            . ($v['linhas_sem_valor'] ? '<p class="peq">* ' . (int)$v['linhas_sem_valor'] . ' nota(s) sem valor informado no sistema; o total considera só as que têm valor.</p>' : '')
            . ($p['obs'] !== '' ? '<p class="obs"><b>Observações:</b> ' . nl2br($e($p['obs'])) . '</p>' : '')
            . '<div class="ass"><div><div class="lin"></div><b>Entregue por (Recebimento)</b><small>Nome, assinatura e data</small></div>'
            . '<div><div class="lin"></div><b>Recebido por (' . $e($p['destinatario']) . ')</b><small>Nome, assinatura, data e hora</small></div></div>'
            . '<footer>Protocolo ' . $e($p['id']) . ' · ' . $e($titulo) . ' · guarde a via assinada junto ao Recebimento.</footer></section>';
    };
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($p['id']) . ' - Protocolo de NFs</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:12px/1.35 Arial,Helvetica,sans-serif;color:#111}'
        . '.bar{position:sticky;top:0;background:#1f2a37;color:#fff;padding:10px 14px;display:flex;gap:10px;align-items:center;justify-content:space-between}.bar button{background:#2e7d32;color:#fff;border:0;border-radius:6px;padding:8px 16px;font-size:14px;cursor:pointer}.bar span{font-size:13px}'
        . '.via{width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:14mm 13mm;display:flex;flex-direction:column;box-shadow:0 1px 6px #0003}'
        . 'header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px}.emp{font-size:18px;font-weight:800;letter-spacing:.5px}.sub{color:#444}.num{text-align:right}.num b{font-size:20px}.num small{color:#555}'
        . 'h1{font-size:16px;text-align:center;margin:14px 0 10px;letter-spacing:.4px}'
        . '.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:8px}.meta div{border:1px solid #bbb;border-radius:4px;padding:5px 7px}.meta small{display:block;color:#555;font-size:10px;text-transform:uppercase}'
        . '.txt{margin:8px 0}table{width:100%;border-collapse:collapse;font-size:11px}th,td{border:1px solid #888;padding:4px 5px;vertical-align:top}th{background:#eee;text-align:left}td.c{text-align:center}td.r{text-align:right}td.ck{width:34px}tfoot td{background:#f4f4f4}'
        . '.peq{font-size:10px;color:#555}.obs{margin-top:8px}.ass{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:auto;padding-top:46px}.ass .lin{border-top:1px solid #111;margin-bottom:4px}.ass b{display:block}.ass small{color:#555}'
        . 'footer{margin-top:16px;border-top:1px dashed #999;padding-top:6px;color:#555;font-size:10px;text-align:center}'
        . '@media print{body{background:#fff}.bar{display:none}.via{margin:0;box-shadow:none;width:auto;min-height:277mm;page-break-after:always;break-after:page}.via:last-child{page-break-after:auto;break-after:auto}@page{size:A4;margin:10mm}}'
        . '@media(max-width:800px){.via{width:auto;margin:8px;padding:12px;min-height:0}.meta{grid-template-columns:1fr 1fr}table{font-size:10px}}'
        . '</style></head><body><div class="bar"><span>Protocolo ' . $e($p['id']) . ' — 2 vias (Financeiro e Recebimento). Use "Imprimir" e escolha A4.</span><span style="display:flex;gap:8px"><a href="api.php?action=protocolos_pdf&id=' . $e(rawurlencode($p['id'])) . '" download style="background:#37474f;color:#fff;border-radius:6px;padding:8px 16px;font-size:14px;text-decoration:none">⬇ Baixar PDF</a><button onclick="window.print()">🖨 Imprimir</button></span></div>'
        . $via('1ª via — Financeiro') . $via('2ª via — Recebimento (arquivo)') . '</body></html>';
}

/* ===================== PDF para download (v3.36.1) — gerador mínimo, sem biblioteca externa ===================== */
class PrtPdf {
    const W = 595.28, H = 841.89;
    private array $pages = []; private string $cur = ''; private bool $aberta = false;
    // larguras Helvetica / Helvetica-Bold (milésimos de em) para os caracteres 32..126
    private const W1 = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const W2 = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    public function page(): void { if ($this->aberta) $this->pages[] = $this->cur; $this->cur = ''; $this->aberta = true; }
    private static function cp(string $s): string { $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s); return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r; }
    public function w(string $s, float $size, bool $b = false): float {
        $t = $b ? self::W2 : self::W1; $n = 0;
        foreach (str_split(self::cp($s)) as $c) { $o = ord($c); $n += ($o >= 32 && $o <= 126) ? $t[$o - 32] : 556; }
        return $n * $size / 1000;
    }
    public function fit(string $s, float $size, bool $b, float $max): string {
        if ($this->w($s, $size, $b) <= $max) return $s;
        while ($s !== '' && $this->w($s . '...', $size, $b) > $max) $s = mb_substr($s, 0, -1);
        return rtrim($s) . '...';
    }
    /** Quebra em linhas que cabem em $max pontos. */
    public function wrap(string $s, float $size, bool $b, float $max): array {
        $out = []; $l = '';
        foreach (preg_split('/\s+/u', trim($s)) as $w) {
            $t = $l === '' ? $w : $l . ' ' . $w;
            if ($l !== '' && $this->w($t, $size, $b) > $max) { $out[] = $l; $l = $w; } else $l = $t;
        }
        if ($l !== '') $out[] = $l; return $out ?: [''];
    }
    public function t(float $x, float $yTop, string $s, float $size = 10, bool $b = false, string $al = 'L', float $box = 0): void {
        if ($s === '') return;
        $w = $this->w($s, $size, $b);
        if ($al === 'R') $x += $box - $w; elseif ($al === 'C') $x += ($box - $w) / 2;
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::cp($s));
        $this->cur .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $b ? 'F2' : 'F1', $size, $x, self::H - $yTop, $e);
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.6): void {
        $this->cur .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $lw, $x1, self::H - $y1, $x2, self::H - $y2);
    }
    public function rect(float $x, float $y, float $w, float $h, float $lw = 0.6, ?float $gray = null): void {
        if ($gray !== null) $this->cur .= sprintf("%.2f g %.2f %.2f %.2f %.2f re f 0 g\n", $gray, $x, self::H - $y - $h, $w, $h);
        $this->cur .= sprintf("%.2f w %.2f %.2f %.2f %.2f re S\n", $lw, $x, self::H - $y - $h, $w, $h);
    }
    public function out(): string {
        if ($this->aberta) { $this->pages[] = $this->cur; $this->aberta = false; $this->cur = ''; }
        $n = count($this->pages); $o = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = []; for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $o[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $o[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $c) {
            $pg = 5 + $i * 2; $ct = $pg + 1; $z = gzcompress($c, 6);
            $o[$pg] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $ct);
            $o[$ct] = ['<< /Length ' . strlen($z) . ' /Filter /FlateDecode >>', $z];
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        ksort($o);
        foreach ($o as $id => $v) {
            $off[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . (is_array($v) ? $v[0] . "\nstream\n" . $v[1] . "\nendstream" : $v) . "\nendobj\n";
        }
        $x = strlen($pdf); $max = max(array_keys($o));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}
function prt_pdf(array $p): string {
    $v = prt_view($p); $pdf = new PrtPdf(); $W = PrtPdf::W; $H = PrtPdf::H; $mx = 40; $cw = $W - 2 * $mx;
    $fmtD = function ($s) { $s = substr((string)$s, 0, 10); return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) ? "$m[3]/$m[2]/$m[1]" : $s; };
    $money = fn($f) => 'R$ ' . number_format($f, 2, ',', '.');
    $col = [['#', 22, 'C'], ['NF / série', 78, 'L'], ['Pedido', 46, 'C'], ['Fornecedor', 0, 'L'], ['Emissão', 56, 'C'], ['Recebido', 56, 'C'], ['Valor da NF', 72, 'R'], ['Conf.', 30, 'C']];
    $fixo = 0; foreach ($col as $c) $fixo += $c[1]; foreach ($col as &$c) if ($c[1] === 0) $c[1] = $cw - $fixo; unset($c);
    $vias = ['1ª via — Financeiro', '2ª via — Recebimento (arquivo)'];
    foreach ($vias as $via) {
        $pdf->page(); $y = 44;
        $cab = function () use ($pdf, $mx, $cw, &$y, $p, $via) {
            $pdf->t($mx, $y + 14, 'DRILLING DO BRASIL', 17, true); $pdf->t($mx, $y + 28, 'Recebimento de materiais', 9);
            $pdf->t($mx, $y + 8, $via, 8.5, false, 'R', $cw); $pdf->t($mx, $y + 28, $p['id'], 17, true, 'R', $cw);
            $pdf->line($mx, $y + 36, $mx + $cw, $y + 36, 1.4); $y += 50;
        };
        $cab();
        $pdf->t($mx, $y + 8, 'PROTOCOLO DE ENTREGA DE NOTAS FISCAIS', 14, true, 'C', $cw); $y += 26;
        $bw = ($cw - 18) / 4; $met = [['DE', 'Recebimento'], ['PARA', $p['destinatario']], ['DATA DE EMISSÃO', date('d/m/Y', strtotime((string)$p['criado_em']))], ['EMITIDO POR', $p['criado_por']]];
        foreach ($met as $i => [$k, $val]) { $bx = $mx + $i * ($bw + 6); $pdf->rect($bx, $y, $bw, 32); $pdf->t($bx + 5, $y + 10, $k, 6.8); $pdf->t($bx + 5, $y + 24, $pdf->fit($val, 10, true, $bw - 10), 10, true); }
        $y += 44;
        foreach ($pdf->wrap('Encaminhamos ao setor ' . $p['destinatario'] . ' as notas fiscais abaixo, referentes a materiais já conferidos e recebidos. A assinatura deste protocolo confirma o recebimento dos documentos listados.', 9.5, false, $cw) as $ln) { $pdf->t($mx, $y + 9, $ln, 9.5); $y += 13; }
        $y += 6;
        $cabTab = function () use ($pdf, $mx, &$y, $col, $cw) {
            $pdf->rect($mx, $y, $cw, 17, 0.6, 0.92); $x = $mx;
            foreach ($col as [$t, $w, $al]) { $pdf->t($x + 3, $y + 12, $t, 8, true, $al === 'R' ? 'R' : ($al === 'C' ? 'C' : 'L'), $w - 6); $x += $w; }
            $y += 17;
        };
        $cabTab();
        foreach ($p['linhas'] as $i => $l) {
            if ($y + 18 > $H - 50) { $pdf->page(); $y = 44; $cab(); $cabTab(); }
            $val = prt_valor($l['valorNF'] ?? '');
            $cel = [(string)($i + 1), $l['numeroNF'] . ($l['serieNF'] !== '' ? ' / s.' . $l['serieNF'] : ''), (string)$l['pc'], $l['fornecedor'], $fmtD($l['emissaoNF']), $fmtD($l['data']), $val > 0 ? $money($val) : '—', ''];
            $x = $mx; $pdf->rect($mx, $y, $cw, 18, 0.4);
            foreach ($col as $k => [$t, $w, $al]) {
                if ($k > 0) $pdf->line($x, $y, $x, $y + 18, 0.4);
                $s = $pdf->fit($cel[$k], 8.5, $k === 1, $w - 6);
                $pdf->t($x + 3, $y + 12.5, $s, 8.5, $k === 1, $al === 'R' ? 'R' : ($al === 'C' ? 'C' : 'L'), $w - 6); $x += $w;
            }
            $y += 18;
        }
        $pdf->rect($mx, $y, $cw, 19, 0.6, 0.96);
        $pdf->t($mx, $y + 13, $v['qtd_nfs'] . ' nota(s) fiscal(is)', 9, true, 'R', $cw - 72 - 30 - 4);
        $pdf->t($mx + $cw - 72 - 30, $y + 13, $money($v['total_valor']), 9, true, 'R', 72 - 6); $y += 27;
        if ($v['linhas_sem_valor']) { $pdf->t($mx, $y + 8, '* ' . $v['linhas_sem_valor'] . ' nota(s) sem valor informado no sistema; o total considera só as que têm valor.', 7.5); $y += 14; }
        if ($p['obs'] !== '') { foreach ($pdf->wrap('Observações: ' . $p['obs'], 9, false, $cw) as $ln) { $pdf->t($mx, $y + 9, $ln, 9); $y += 12; } $y += 4; }
        $sig = $H - 150; if ($y > $sig - 10) { $pdf->page(); $y = 44; $cab(); }
        $hw = ($cw - 30) / 2;
        foreach ([['Entregue por (Recebimento)', 'Nome, assinatura e data'], ['Recebido por (' . $p['destinatario'] . ')', 'Nome, assinatura, data e hora']] as $i => [$a, $b]) {
            $bx = $mx + $i * ($hw + 30); $pdf->line($bx, $sig, $bx + $hw, $sig, 0.8);
            $pdf->t($bx, $sig + 13, $pdf->fit($a, 9.5, true, $hw), 9.5, true); $pdf->t($bx, $sig + 24, $b, 8);
        }
        $pdf->line($mx, $H - 62, $mx + $cw, $H - 62, 0.4);
        $pdf->t($mx, $H - 50, 'Protocolo ' . $p['id'] . ' · ' . $via . ' · guarde a via assinada junto ao Recebimento.', 7.5, false, 'C', $cw);
    }
    return $pdf->out();
}
/** CSV do protocolo (Excel-BR: separador ";" e BOM UTF-8). */
function prt_csv(array $p): string {
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
    $o = "\xEF\xBB\xBF" . implode(';', array_map($q, ['Protocolo', 'Item', 'NF', 'Série', 'Pedido (PC)', 'Fornecedor', 'Emissão', 'Recebido em', 'Valor da NF', 'Chave de acesso'])) . "\r\n";
    foreach ($p['linhas'] as $i => $l) $o .= implode(';', array_map($q, [$p['id'], $i + 1, $l['numeroNF'], $l['serieNF'], $l['pc'], $l['fornecedor'], $l['emissaoNF'], $l['data'], $l['valorNF'], $l['chaveNF']])) . "\r\n";
    return $o;
}
