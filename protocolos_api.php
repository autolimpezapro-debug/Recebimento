<?php
/** Recebimento Drilling — rotas protocolos_* (v3.36.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/protocolos_lib.php';
require_once __DIR__ . '/protocolos_email_lib.php';

$prtUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$prtMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($prtMet === 'GET') {
    switch ($action) {
        case 'protocolos_email_config': respond(['ok' => true, 'config' => pem_config(), 'smtp_ok' => (alt_config()['metodo'] === 'smtp' ? alt_config()['smtp_host'] !== '' : true)]);
        case 'protocolos_painel': respond(['ok' => true] + prt_painel(prt_read(), read_items()));
        case 'protocolos_arquivo': prt_doc_servir(prt_read());
        case 'protocolos_pdf': case 'protocolos_csv':
            $id = (string)($_GET['id'] ?? ''); $d = prt_read();
            foreach ($d['protocolos'] as $p) if ($p['id'] === $id) {
                $csv = $action === 'protocolos_csv'; $corpo = $csv ? prt_csv($p) : prt_pdf($p);
                header('Content-Type: ' . ($csv ? 'text/csv; charset=utf-8' : 'application/pdf')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
                header('Content-Disposition: attachment; filename="Protocolo-' . $p['id'] . ($csv ? '.csv' : '.pdf') . '"'); header('Content-Length: ' . strlen($corpo));
                echo $corpo; exit;
            }
            http_response_code(404); exit('Protocolo não encontrado');
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
        case 'protocolos_criar':
            $novo = prt_criar($d, $body, read_items(), $prtUser); $cfgE = pem_config();
            $em = ($cfgE['ativo'] && $cfgE['emails']) ? pem_enviar($d, $novo['id'], null, $prtUser) : ['enviado' => false, 'motivo' => 'sem_config'];
            $r = ['protocolo' => prt_view(prt_get($d, $novo['id'])), 'email' => $em]; break;
        case 'protocolos_email_enviar':   // (re)envia com um link novo; o anterior deixa de valer
            $em = pem_enviar($d, prt_str($body['id'] ?? '', 20), isset($body['emails']) ? pem_emails($body['emails']) : null, $prtUser);
            $r = ['protocolo' => prt_view(prt_get($d, prt_str($body['id'] ?? '', 20))), 'email' => $em]; break;
        case 'protocolos_email_config_salvar': $r = ['config' => pem_config_salvar($body)]; prt_write($d); return $r;
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
