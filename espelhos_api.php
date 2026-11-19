<?php
/** Recebimento Drilling — rotas espelhos_* (Espelho de NF, v3.53.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/espelho_lib.php';

$espUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$espMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($espMet === 'GET') {
    switch ($action) {
        case 'espelhos_painel': respond(['ok' => true] + esp_painel(esp_read()));
        case 'espelhos_sku': respond(['ok' => true] + esp_sku_info((string)($_GET['sku'] ?? '')));
        case 'espelhos_itens_pc': respond(['ok' => true, 'pedidos' => esp_itens_pc((string)($_GET['q'] ?? ''))]);
        case 'espelhos_pdf': case 'espelhos_csv': case 'espelhos_imprimir':
            $id = (string)($_GET['id'] ?? '');
            foreach (esp_read()['espelhos'] as $r) if ($r['id'] === $id) {
                header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
                if ($action === 'espelhos_imprimir') { header('Content-Type: text/html; charset=utf-8'); echo esp_html_impressao($r); exit; }
                $csv = $action === 'espelhos_csv'; $corpo = $csv ? esp_csv($r) : esp_pdf($r);
                header('Content-Type: ' . ($csv ? 'text/csv; charset=utf-8' : 'application/pdf'));
                header('Content-Disposition: attachment; filename="Espelho-NF-' . $r['id'] . ($csv ? '.csv' : '.pdf') . '"'); header('Content-Length: ' . strlen($corpo));
                echo $corpo; exit;
            }
            http_response_code(404); exit('Espelho não encontrado');
    }
    prt_fail('Ação de espelhos inválida', 400);
}
if ($espMet !== 'POST') prt_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) prt_fail('Dados inválidos', 400);

$res = esp_with_lock(function () use ($action, $body, $espUser) {
    $d = esp_read();
    switch ($action) {
        case 'espelhos_salvar': $r = esp_salvar($d, $body, $espUser); break;
        case 'espelhos_status': $r = esp_status($d, $body, $espUser); break;
        default: prt_fail('Ação de espelhos inválida', 400);
    }
    esp_write($d);
    return ['espelho' => esp_view($r)];
});
if (function_exists('append_event')) {
    $r = $res['espelho'];
    append_event(0, 'estoque', $r['id'] . ' · ' . $r['destinatario'] . ': Espelho de NF ' . ($action === 'espelhos_salvar' ? 'salvo' : $r['status_label']));
}
respond(['ok' => true] + $res);
