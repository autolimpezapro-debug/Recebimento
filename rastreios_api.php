<?php
/** Recebimento Drilling — rotas rastreios_* (v3.64.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/rastreios_lib.php';

$rtrUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$rtrMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($rtrMet === 'GET') {
    switch ($action) {
        case 'rastreios_painel': respond(['ok' => true] + rtr_painel(rtr_read()));
        case 'rastreios_csv':
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="rastreios.csv"'); header('Cache-Control: no-store');
            echo rtr_csv(rtr_read()); exit;
    }
    prt_fail('Ação de rastreios inválida', 400);
}
if ($rtrMet !== 'POST') prt_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 2 * 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) prt_fail('Dados inválidos', 400);

if ($action === 'rastreios_importar_previa') {   // só lê: não grava nada
    [$linhas, $erro] = rtr_importar_ler(rtr_read(), (string)($body['texto'] ?? ''));
    if ($erro !== '') prt_fail($erro);
    respond(['ok' => true, 'linhas' => $linhas, 'novos' => count(array_filter($linhas, fn($l) => $l['estado'] === 'novo')),
        'duplicados' => count(array_filter($linhas, fn($l) => $l['estado'] === 'duplicado')), 'erros' => count(array_filter($linhas, fn($l) => $l['estado'] === 'erro'))]);
}
$res = rtr_with_lock(function () use ($action, $body, $rtrUser) {
    $d = rtr_read();
    switch ($action) {
        case 'rastreios_salvar': $r = rtr_salvar($d, $body, $rtrUser); break;
        case 'rastreios_entregar': $r = rtr_entregar($d, $body, $rtrUser); break;
        case 'rastreios_reabrir': $r = rtr_reabrir($d, $body, $rtrUser); break;
        case 'rastreios_excluir': $r = rtr_excluir($d, $body, $rtrUser); break;
        case 'rastreios_config': $r = rtr_config($d, $body); break;
        case 'rastreios_importar': $r = rtr_importar($d, (string)($body['texto'] ?? ''), $rtrUser); break;
        default: prt_fail('Ação de rastreios inválida', 400);
    }
    rtr_write($d);
    return $r;
});
respond(['ok' => true] + $res);
