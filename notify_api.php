<?php
/**
 * Recebimento Drilling — rotas do sistema de notificações (v3.1.0)
 * Chamadas por api.php?action=notify_*  (mesma origem, mesma sessão).
 *
 * GET
 *   notify_inbox&device_id=…[&since=N]   novas notificações do dispositivo
 *   notify_history&device_id=…           últimas notificações (painel do sino)
 *   notify_stats                          dispositivos online e totais
 *   notify_publica&pc=N                   publica teste/aviso manual (gestor)
 * POST
 *   notify_heartbeat   {device_id, navegador, pagina, preferencias}
 *   notify_read        {device_id, ids:[...]}  | {device_id, todas:true}
 *   notify_test        {device_id}
 *   notify_publicar    {tipo, titulo, texto, pc}   (gestor)
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/notify_lib.php';

function notif_json_body() {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 65536) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $b;
}

function notif_handle() {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        switch ($action) {
            case 'notify_inbox':
                $since = isset($_GET['since']) && $_GET['since'] !== '' ? (int)$_GET['since'] : null;
                respond(['ok' => true] + notif_inbox(notif_device_required($_GET['device_id'] ?? ''), $since));
            case 'notify_history':
                respond(['ok' => true] + notif_history($_GET['device_id'] ?? ''));
            case 'notify_stats':
                respond(['ok' => true] + notif_stats());
            case 'notify_publica':
                // Publicação por link (usada para teste rápido do gestor).
                if (notif_user_role() !== 'gestor') respond(['ok' => false, 'error' => 'Somente o gestor publica avisos'], 403);
                $pc = (int)($_GET['pc'] ?? 0);
                $n = notif_publish([
                    'tipo' => 'recebimento',
                    'titulo' => $pc ? 'Recebimento • PC ' . $pc : 'Teste de notificação',
                    'texto' => 'Aviso publicado manualmente para validar o recebimento em todos os dispositivos.',
                    'pc' => $pc ?: null,
                    'url' => $pc ? '/?pc=' . $pc : '',
                    'autor' => notif_user_name(),
                ]);
                respond(['ok' => true, 'notificacao' => $n]);
        }
        respond(['ok' => false, 'error' => 'Ação de notificação inválida'], 400);
    }

    if ($method !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
    if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) {
        respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
    }
    $body = notif_json_body();

    switch ($action) {
        case 'notify_heartbeat':
            respond(['ok' => true] + notif_heartbeat($body));

        case 'notify_read':
            $dev = notif_device_required($body['device_id'] ?? '');
            if (!empty($body['todas'])) respond(['ok' => true] + notif_mark_all_read($dev));
            $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
            respond(['ok' => true] + notif_mark_read($dev, $ids));

        case 'notify_test':
            $dev = notif_device_id($body['device_id'] ?? '');
            $n = notif_publish([
                'tipo' => 'sistema',
                'titulo' => 'Teste de notificação',
                'texto' => 'Se este aviso apareceu no sino, no banner e (se autorizado) como notificação do navegador, este dispositivo está configurado.',
                'autor' => notif_user_name(),
                'origem_device' => $dev,
                'dados' => ['teste' => true],
            ]);
            respond(['ok' => true, 'notificacao' => $n]);

        case 'notify_publicar':
            if (notif_user_role() !== 'gestor') respond(['ok' => false, 'error' => 'Somente o gestor publica avisos'], 403);
            $titulo = trim((string)($body['titulo'] ?? ''));
            if (mb_strlen($titulo) < 3) respond(['ok' => false, 'error' => 'Informe o título do aviso'], 422);
            $n = notif_publish([
                'tipo' => (string)($body['tipo'] ?? 'sistema'),
                'titulo' => $titulo,
                'texto' => (string)($body['texto'] ?? ''),
                'pc' => !empty($body['pc']) ? (int)$body['pc'] : null,
                'url' => !empty($body['pc']) ? '/?pc=' . (int)$body['pc'] : '',
                'autor' => notif_user_name(),
            ]);
            respond(['ok' => true, 'notificacao' => $n]);
    }
    respond(['ok' => false, 'error' => 'Ação de notificação inválida'], 400);
}

notif_handle();
