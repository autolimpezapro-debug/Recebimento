<?php
/** Recebimento Drilling — API do app do motorista (v3.40.0). Acesso só por token do link (sem login); escopo = rota ativa. */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
define('DATA_DIR', __DIR__ . '/data');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
function respond($data, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
require_once __DIR__ . '/coletas_lib.php';
require_once __DIR__ . '/motorista_lib.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
    $h = (string)($_SERVER['HTTP_HOST'] ?? ''); $o = (string)$_SERVER['HTTP_ORIGIN'];
    if ($h === '' || ($o !== 'https://' . $h && $o !== 'http://' . $h)) respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
}
$acao = (string)($_GET['a'] ?? '');
$nome = mot_autenticar();
$quem = $nome . ' (app do motorista)';
$b = [];
if ($metodo === 'POST') {
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 200000) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true); if (!is_array($b)) respond(['ok' => false, 'error' => 'Dados inválidos'], 400);
}
switch ($acao) {
    case 'rota':
        $db = col_read(); $pos = $db['motorista_pos'] ?? null;
        respond(['ok' => true, 'motorista' => $nome, 'servidor_em' => date('c'), 'motivos' => COL_RETORNO_MOTIVOS, 'posicao_enviada_em' => $pos['em'] ?? null] + mot_rota());
    case 'coletou':
        $c = mot_coleta_na_rota((string)($b['id'] ?? ''));
        if (mot_op_repetida((string)($b['op'] ?? ''))) respond(['ok' => true, 'repetida' => true]);
        $r = col_mudar_status($c['id'], 'COLETADO', $quem, 'Coletado pelo motorista', $b['km_real'] ?? null, $b, col_tempo_cliente($b['em_cliente'] ?? null));
        mot_op_marcar((string)($b['op'] ?? ''));
        respond(['ok' => true, 'coletado_geo' => $r['coleta']['coletado_geo'] ?? null]);
    case 'nao_coletou':
        $c = mot_coleta_na_rota((string)($b['id'] ?? ''));
        if (mot_op_repetida((string)($b['op'] ?? ''))) respond(['ok' => true, 'repetida' => true]);
        if (in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true)) respond(['ok' => true, 'ignorada' => 'já coletada']);
        $b['reagendar_data'] = ''; $b['reagendar_hora'] = '';   // quem reagenda é o escritório
        col_registrar_retorno($c['id'], $b, $quem);
        mot_op_marcar((string)($b['op'] ?? ''));
        respond(['ok' => true]);
    case 'posicao':
        $r = col_posicao($b, $quem);
        respond(['ok' => true]);
    case 'reotimizar':
        if ($metodo !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
        $r = col_rota_reotimizar((float)($b['lat'] ?? 0), (float)($b['lng'] ?? 0), $quem);
        respond(['ok' => true, 'reordenada' => $r['reordenada'], 'motivo' => $r['motivo']]);
    case 'pdf':
        $pc = (int)($_GET['pc'] ?? 0); $achou = false;
        foreach (mot_rota()['rota']['paradas'] ?? [] as $p) if ((int)$p['pc'] === $pc && $pc > 0) $achou = true;
        $f = $achou ? mot_pdf_arquivo($pc) : null;
        if (!$f) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('PDF do pedido não disponível.'); }
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="PC-' . $pc . '.pdf"'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
respond(['ok' => false, 'error' => 'Ação inválida'], 400);
