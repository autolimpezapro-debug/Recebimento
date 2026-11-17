<?php
/** Recebimento Drilling — rotas ferramentas_* (v3.10.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/ferramentas_lib.php';

$ferUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$ferMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($ferMet === 'GET') {
    switch ($action) {
        case 'ferramentas_painel': respond(['ok' => true] + fer_painel(fer_read()));
        case 'ferramentas_item': respond(['ok' => true, 'ferramenta' => fer_item(fer_read(), (string)($_GET['id'] ?? ''))]);
        case 'ferramentas_emprestimos': respond(['ok' => true, 'emprestimos' => fer_emprestimos(fer_read(), $_GET)]);
        case 'ferramentas_comprovante':
            $html = fer_comprovante_html(fer_read(), preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['id'] ?? '')));
            if ($html === null) fer_fail('Empréstimo não encontrado.', 404);
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
            echo $html; exit;
    }
    fer_fail('Ação de ferramentas inválida', 400);
}
if ($ferMet !== 'POST') fer_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 8 * 1024 * 1024) fer_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) fer_fail('Dados inválidos', 400);

$res = fer_with_lock(function () use ($action, $body, $ferUser) {
    $d = fer_read();
    switch ($action) {
        case 'ferramentas_salvar': $r = ['ferramenta' => fer_salvar($d, $body, $ferUser)]; break;
        case 'ferramentas_puxar_estoque': $r = fer_puxar_estoque($d, $ferUser); break;
        case 'ferramentas_importar':
            $r = ['importacao' => fer_importar($d, $body, $ferUser, !empty($body['confirmo']))];
            if (empty($body['confirmo'])) return $r;
            break;
        case 'ferramentas_emprestar': $r = ['emprestimo' => fer_emprestar($d, $body, $ferUser)]; break;
        case 'ferramentas_devolver': $r = ['emprestimo' => fer_devolver($d, $body, $ferUser)]; break;
        case 'ferramentas_manutencao': $r = ['ferramenta' => fer_manutencao($d, $body, $ferUser)]; break;
        case 'ferramentas_foto': $r = ['foto' => fer_foto($d, $body)]; break;
        default: fer_fail('Ação de ferramentas inválida', 400);
    }
    fer_write($d);
    return $r;
});
if ($action === 'ferramentas_emprestar' && function_exists('append_event')) append_event(0, 'estoque', 'Empréstimo ' . $res['emprestimo']['id'] . ' → ' . $res['emprestimo']['responsavel']);
if ($action === 'ferramentas_devolver' && function_exists('append_event')) append_event(0, 'estoque', 'Devolução ' . $res['emprestimo']['id'] . ' (' . $res['emprestimo']['status'] . ')');
// v3.48.0: aviso (sino + push conforme as regras do administrador) para empréstimos e devoluções.
if (in_array($action, ['ferramentas_emprestar', 'ferramentas_devolver'], true) && isset($res['emprestimo']) && is_file(__DIR__ . '/notify_lib.php')) {
    require_once __DIR__ . '/notify_lib.php';
    $e = $res['emprestimo'];
    notif_evento('ferramentas', $action === 'ferramentas_emprestar' ? 'Ferramenta emprestada • ' . ($e['responsavel'] ?? '') : 'Ferramenta devolvida • ' . ($e['responsavel'] ?? ''),
        'Registro ' . ($e['id'] ?? '') . (isset($e['status']) ? ' · ' . $e['status'] : '') . ' · por ' . $ferUser, '/', $ferUser);
}
respond(['ok' => true] + $res);
