<?php
/**
 * Recebimento Drilling — rotas da Agenda Inteligente (v3.3.0)
 * Chamadas por api.php?action=agenda_*  (mesma origem e sessão).
 *
 * GET
 *   agenda_listar[&de=&ate=&status=&q=]   agendamentos
 *   agenda_dashboard                       KPIs + fila do dia
 *   agenda_calendario&mes=YYYY-MM           contagem por dia do mês
 *   agenda_sugestoes                       PCs ainda sem data, prontos para agendar
 *   agenda_detalhe&pc=N                    um agendamento
 * POST
 *   agenda_salvar     {pc, data, janela, hora, tipo, local, responsavel, ...}
 *   agenda_concluir   {pc}
 *   agenda_cancelar   {pc, motivo}
 *   agenda_verificar  {}                  dispara avisos de véspera/hoje/atraso
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/agenda_lib.php';

function ag_user() {
    if (function_exists('rd_login_info')) {
        $u = rd_login_info();
        if (is_array($u) && !empty($u['username'])) return (string)$u['username'];
    }
    return 'operador-local';
}

function ag_role() {
    if (function_exists('rd_login_info')) {
        $u = rd_login_info();
        if (is_array($u) && !empty($u['role'])) return (string)$u['role'];
    }
    return 'gestor';
}

function ag_require_write() {
    if (!rd_perm_or_legado('agenda', 'criar', ['gestor', 'conferente'])) {
        respond(['ok' => false, 'error' => 'Seu perfil não permite alterar a agenda'], 403);
    }
}

function ag_body() {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 262144) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $b;
}

$agAction = $_GET['action'] ?? '';
$agMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($agMethod === 'GET') {
    switch ($agAction) {
        case 'agenda_listar':
            respond(['ok' => true] + ag_listar($_GET));
        case 'agenda_dashboard':
            respond(['ok' => true] + ag_dashboard());
        case 'agenda_calendario':
            respond(['ok' => true] + ag_calendario((string)($_GET['mes'] ?? '')));
        case 'agenda_sugestoes':
            respond(['ok' => true] + ag_sugestoes((int)($_GET['limite'] ?? 12)));
        case 'agenda_detalhe':
            $db = ag_read();
            $key = ag_key((int)($_GET['pc'] ?? 0));
            if (!isset($db['agendamentos'][$key])) respond(['ok' => false, 'error' => 'Agendamento não encontrado'], 404);
            respond(['ok' => true, 'agendamento' => ag_view($db['agendamentos'][$key])]);
    }
    respond(['ok' => false, 'error' => 'Ação de agenda inválida'], 400);
}

if ($agMethod !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) {
    respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
}
ag_require_write();
$agBody = ag_body();
$agUser = ag_user();

switch ($agAction) {
    case 'agenda_salvar':
        $r = ag_salvar($agBody, $agUser);
        respond(['ok' => true] + $r);

    case 'agenda_concluir':
        $ag = ag_concluir((int)($agBody['pc'] ?? 0), $agUser, 'manual');
        if (!$ag) respond(['ok' => false, 'error' => 'Agendamento não encontrado'], 404);
        respond(['ok' => true, 'agendamento' => $ag]);

    case 'agenda_cancelar':
        $ag = ag_cancelar((int)($agBody['pc'] ?? 0), $agUser, (string)($agBody['motivo'] ?? ''));
        respond(['ok' => true, 'agendamento' => $ag]);

    case 'agenda_verificar':
        $r = ag_verificar_avisos(!empty($agBody['forcar']));
        respond(['ok' => true] + $r);
}
respond(['ok' => false, 'error' => 'Ação de agenda inválida'], 400);
