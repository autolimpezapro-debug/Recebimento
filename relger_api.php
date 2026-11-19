<?php
/** Recebimento Drilling — rotas relger_* (Relatório gerencial, v3.53.0). Somente leitura. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/relger_lib.php';
if (strncmp((string)$action, 'relger_envio_', 13) === 0) {
    require_once __DIR__ . '/relger_envio_lib.php';
    try {
        if ($action === 'relger_envio_config') respond(['ok' => true] + rge_status());
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
        $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
        if ($action === 'relger_envio_salvar') { rge_config_salvar($b); respond(['ok' => true] + rge_status()); }
        if ($action === 'relger_envio_testar') respond(['ok' => true] + rge_teste());
    } catch (InvalidArgumentException $e) { respond(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (Throwable $e) { respond(['ok' => false, 'error' => $e->getMessage()], 500); }
    respond(['ok' => false, 'error' => 'Ação inválida'], 400);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
$rgR = rg_relatorio((string)($_GET['ini'] ?? ''), (string)($_GET['fim'] ?? ''), !empty($_GET['so_os']));
if ($action === 'relger_csv') {
    $c = rg_csv($rgR);
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="Saidas-por-OS-' . $rgR['periodo']['ini'] . '_a_' . $rgR['periodo']['fim'] . '.csv"'); header('Content-Length: ' . strlen($c));
    echo $c; exit;
}
if ($action === 'relger_dados') respond(['ok' => true] + $rgR);
respond(['ok' => false, 'error' => 'Ação inválida'], 400);
