<?php
/** Recebimento Drilling — rotas chegadas_* (v3.54.0). */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/chegadas_lib.php';
$chgUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$chgMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($chgMet === 'GET') {
    switch ($action) {
        case 'chegadas_painel': respond(['ok' => true] + chg_painel(chg_read()));
        case 'chegadas_pcs': respond(['ok' => true, 'pedidos' => chg_buscar_pcs((string)($_GET['q'] ?? ''))]);
        case 'chegadas_csv':
            $c = chg_csv(chg_read()); header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="Chegadas-almoxarifado-' . date('Y-m-d') . '.csv"'); header('Content-Length: ' . strlen($c)); echo $c; exit;
        case 'chegadas_foto':
            $p = chg_foto_path((string)($_GET['arq'] ?? '')); if (!$p) { http_response_code(404); exit('Foto não encontrada'); }
            header('Content-Type: ' . (mime_content_type($p) ?: 'image/jpeg')); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff'); readfile($p); exit;
    }
    prt_fail('Ação de chegadas inválida', 400);
}
if ($chgMet !== 'POST') prt_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 18 * 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true); if (!is_array($body)) prt_fail('Dados inválidos', 400);
$res = chg_with_lock(function () use ($action, $body, $chgUser) {
    $d = chg_read();
    switch ($action) {
        case 'chegadas_salvar': $r = chg_salvar($d, $body, $chgUser); break;
        case 'chegadas_acao': $r = chg_acao($d, $body, $chgUser); break;
        default: prt_fail('Ação de chegadas inválida', 400);
    }
    chg_write($d); return ['registro' => chg_view($r)];
});
if (function_exists('append_event')) append_event(0, 'estoque', $res['registro']['id'] . ' · ' . ($res['registro']['fornecedor'] ?: '—') . ': Chegada ' . ($action === 'chegadas_salvar' ? 'salva' : 'atualizada (' . $res['registro']['status'] . ')'));
respond(['ok' => true] + $res);
