<?php
/** Recebimento Drilling — rotas protocolos_* (v3.36.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/protocolos_lib.php';

$prtUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$prtMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($prtMet === 'GET') {
    switch ($action) {
        case 'protocolos_painel': respond(['ok' => true] + prt_painel(prt_read(), read_items()));
        case 'protocolos_arquivo': prt_doc_servir(prt_read());
        case 'protocolos_imprimir':
            $id = (string)($_GET['id'] ?? ''); $d = prt_read();
            foreach ($d['protocolos'] as $p) if ($p['id'] === $id) {
                header('Content-Type: text/html; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
                echo prt_html_impressao($p); exit;
            }
            http_response_code(404); exit('Protocolo não encontrado');
    }
    prt_fail('Ação de protocolos inválida', 400);
}
if ($prtMet !== 'POST') prt_fail('Método não permitido', 405);
if ($action === 'protocolos_doc_enviar') {   // multipart: id, arquivo
    @set_time_limit(120);
    $r = prt_with_lock(function () use ($prtUser) { $d = prt_read(); $p = prt_doc_add($d, $prtUser); prt_write($d); return ['protocolo' => prt_view($p)]; });
    respond(['ok' => true] + $r);
}
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) prt_fail('Dados inválidos', 400);

$res = prt_with_lock(function () use ($action, $body, $prtUser) {
    $d = prt_read();
    switch ($action) {
        case 'protocolos_criar': $r = ['protocolo' => prt_view(prt_criar($d, $body, read_items(), $prtUser))]; break;
        case 'protocolos_status': $r = ['protocolo' => prt_view(prt_status($d, $body, $prtUser))]; break;
        default: prt_fail('Ação de protocolos inválida', 400);
    }
    prt_write($d);
    return $r;
});
if (function_exists('append_event') && isset($res['protocolo'])) {
    $p = $res['protocolo'];
    $txt = ['protocolos_criar' => 'Protocolo de NFs gerado (' . $p['qtd_nfs'] . ' NF)', 'protocolos_status' => 'Protocolo: ' . $p['status_label']][$action] ?? $action;
    append_event(0, 'estoque', $p['id'] . ' · ' . $p['destinatario'] . ': ' . $txt);
}
respond(['ok' => true] + $res);
