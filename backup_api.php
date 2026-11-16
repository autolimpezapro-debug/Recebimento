<?php
/**
 * Recebimento Drilling — API do Backup Completo (v3.2.0)
 * Chamadas por api.php?action=backup_*  — exige perfil gestor.
 *
 * GET  backup_diagnostico            estado, últimos backups, alertas, resumo dos dados
 * GET  backup_listar                 lista os ZIPs guardados no servidor
 * GET  backup_verificar&arquivo=…    confere um ZIP pelo manifesto (hash SHA-256)
 * GET  backup_baixar&arquivo=…       baixa um ZIP guardado no servidor
 * POST backup_agora                  {tipo:"manual"} cria um backup completo agora
 * POST backup_config                 {retencao_dias, retencao_semanas}
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/backup_lib.php';

function bk_user() {
    if (function_exists('rd_login_info')) {
        $u = rd_login_info();
        if (is_array($u)) return $u;
    }
    return ['username' => 'administrador', 'role' => 'gestor'];
}

function bk_require_gestor() {
    $u = bk_user();
    if (!rd_perm_or_legado('administracao', 'admin', ['gestor'])) {
        respond(['ok' => false, 'error' => 'Somente o gestor acessa o backup completo'], 403);
    }
    return $u;
}

function bk_body() {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 65536) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $b;
}

function bk_arquivo_valido($nome) {
    $n = (string)$nome;
    if (!preg_match('/^(?:backup|pre-restauracao)-[A-Za-z0-9.\-]+\.zip$/D', $n)) {
        respond(['ok' => false, 'error' => 'Nome de arquivo inválido'], 422);
    }
    $path = BC_BACKUPS . '/' . $n;
    if (!is_file($path)) respond(['ok' => false, 'error' => 'Backup não encontrado'], 404);
    return $path;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = bk_require_gestor();

if ($method === 'GET') {
    switch ($action) {
        case 'backup_diagnostico':
            respond(['ok' => true, 'usuario' => $user['username']] + bc_diagnostico());

        case 'backup_listar':
            respond(['ok' => true, 'backups' => bc_listar()]);

        case 'backup_verificar':
            try {
                respond(['ok' => true] + bc_verificar((string)($_GET['arquivo'] ?? ''), !empty($_GET['profundo'])));
            } catch (Throwable $e) {
                respond(['ok' => false, 'error' => $e->getMessage()], 422);
            }

        case 'backup_baixar':
            $path = bk_arquivo_valido($_GET['arquivo'] ?? '');
            $nome = basename($path);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $nome . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            readfile($path);
            exit;
    }
    respond(['ok' => false, 'error' => 'Ação de backup inválida'], 400);
}

if ($method !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);

$body = bk_body();
switch ($action) {
    case 'backup_agora':
        @set_time_limit(300);
        try {
            $fp = bc_lock();
            if (!$fp) respond(['ok' => false, 'error' => 'Outro backup está em execução. Tente em alguns instantes.'], 409);
            try {
                $info = bc_create('manual');
            } finally {
                bc_unlock($fp);
            }
            $state = bc_state();
            $state['ultimo_backup'] = $info['criado_em'];
            $state['ultimo_backup_bytes'] = $info['bytes'];
            $state['ultimo_backup_arquivos'] = $info['arquivos'];
            $state['ultimo_backup_resumo'] = $info['resumo'];
            $state['ultimo_erro'] = null;
            $state['total_backups'] = (int)($state['total_backups'] ?? 0) + 1;
            bc_state_save($state);
            respond(['ok' => true, 'backup' => $info, 'mensagem' => 'Backup completo criado e guardado no servidor.']);
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }

    case 'backup_config':
        $state = bc_state();
        if (isset($body['retencao_dias'])) $state['retencao_dias'] = max(1, min(90, (int)$body['retencao_dias']));
        if (isset($body['retencao_semanas'])) $state['retencao_semanas'] = max(1, min(52, (int)$body['retencao_semanas']));
        bc_state_save($state);
        $removidos = bc_reter((int)$state['retencao_dias'], (int)$state['retencao_semanas']);
        respond(['ok' => true, 'estado' => $state, 'removidos' => $removidos]);

    case 'backup_limpar_antigos':
        $removidos = bc_reter(
            (int)($body['retencao_dias'] ?? BC_RETENCAO_DIAS),
            (int)($body['retencao_semanas'] ?? BC_RETENCAO_SEMANAS)
        );
        respond(['ok' => true, 'removidos' => $removidos]);
}

respond(['ok' => false, 'error' => 'Ação de backup inválida'], 400);
