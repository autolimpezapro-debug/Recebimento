<?php
/** Recebimento Drilling — rotas romaneios_* (v3.39.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/romaneios_lib.php';

$romUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$romMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($romMet === 'GET') {
    switch ($action) {
        case 'romaneios_buscar_pc':  respond(['ok' => true] + rom_buscar_pc((int)preg_replace('/\D/', '', (string)($_GET['pc'] ?? ''))));
        case 'romaneios_buscar_sku': respond(['ok' => true] + rom_buscar_sku((string)($_GET['q'] ?? '')));
        case 'romaneios_painel': respond(['ok' => true] + rom_painel(rom_read()));
        case 'romaneios_pdf': case 'romaneios_csv': case 'romaneios_imprimir':
            $id = (string)($_GET['id'] ?? '');
            foreach (rom_read()['romaneios'] as $r) if ($r['id'] === $id) {
                header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
                if ($action === 'romaneios_imprimir') { header('Content-Type: text/html; charset=utf-8'); echo rom_html_impressao($r); exit; }
                $csv = $action === 'romaneios_csv'; $corpo = $csv ? rom_csv($r) : rom_pdf($r);
                header('Content-Type: ' . ($csv ? 'text/csv; charset=utf-8' : 'application/pdf'));
                header('Content-Disposition: attachment; filename="Romaneio-' . $r['id'] . ($csv ? '.csv' : '.pdf') . '"'); header('Content-Length: ' . strlen($corpo));
                echo $corpo; exit;
            }
            http_response_code(404); exit('Romaneio não encontrado');
    }
    prt_fail('Ação de romaneios inválida', 400);
}
if ($romMet !== 'POST') prt_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) prt_fail('Dados inválidos', 400);

$res = rom_with_lock(function () use ($action, $body, $romUser) {
    $d = rom_read();
    switch ($action) {
        case 'romaneios_salvar': $r = rom_salvar($d, $body, $romUser); break;
        case 'romaneios_status': $r = rom_status($d, $body, $romUser); break;
        default: prt_fail('Ação de romaneios inválida', 400);
    }
    rom_write($d);
    return ['romaneio' => rom_view($r)];
});
if (function_exists('append_event')) {
    $r = $res['romaneio'];
    $txt = $action === 'romaneios_salvar' ? 'Romaneio salvo' : 'Romaneio: ' . $r['status_label'];
    append_event(0, 'estoque', $r['id'] . ' · ' . $r['obra_nome'] . ': ' . $txt);
}
respond(['ok' => true] + $res);
