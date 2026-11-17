<?php
/** Recebimento Drilling — rotas locacoes_* (v3.12.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/locacoes_lib.php';

$locUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$locMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($locMet === 'GET') {
    switch ($action) {
        case 'locacoes_painel': respond(['ok' => true] + loc_painel(loc_read(), $_GET));
        case 'locacoes_arquivo': loc_doc_servir(loc_read());   // v3.35.0
        case 'locacoes_alertas_config': require_once __DIR__ . '/locacoes_alertas_lib.php'; respond(['ok' => true] + lal_painel(loc_read()));
        case 'locacoes_ics':
            require_once __DIR__ . '/locacoes_alertas_lib.php';
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="devolucoes_locacoes.ics"');
            echo lal_ics(loc_read()); exit;
        case 'locacoes_csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="locacoes_' . date('Ymd_His') . '.csv"');
            echo loc_csv(loc_read()); exit;
    }
    loc_fail('Ação de locações inválida', 400);
}
if ($locMet !== 'POST') loc_fail('Método não permitido', 405);
if ($action === 'locacoes_doc_enviar') {   // v3.35.0 — multipart: id, tipo, arquivo
    @set_time_limit(120);
    $r = loc_with_lock(function () use ($locUser) { $d = loc_read(); $l = loc_doc_add($d, $locUser); loc_write($d); return ['locacao' => $l]; });
    respond(['ok' => true] + $r);
}
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1024 * 1024) loc_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) loc_fail('Dados inválidos', 400);

if ($action === 'locacoes_alertas_salvar' || $action === 'locacoes_alertas_verificar') {   // v3.41.0 — fora da trava (envia e-mail)
    require_once __DIR__ . '/locacoes_alertas_lib.php';
    try {
        if ($action === 'locacoes_alertas_salvar') { lal_config_salvar($body); respond(['ok' => true] + lal_painel(loc_read())); }
        $r = lal_verificar('manual', true);
        respond(['ok' => true, 'resultado' => $r] + lal_painel(loc_read()));
    } catch (InvalidArgumentException $e) { loc_fail($e->getMessage()); }
}

$res = loc_with_lock(function () use ($action, $body, $locUser) {
    $d = loc_read();
    switch ($action) {
        case 'locacoes_salvar': $r = ['locacao' => loc_salvar($d, $body, $locUser)]; break;
        case 'locacoes_transferir': $r = ['locacao' => loc_transferir($d, $body, $locUser)]; break;
        case 'locacoes_desfazer_transferencia': $r = ['locacao' => loc_desfazer_transferencia($d, $body, $locUser)]; break;
        case 'locacoes_extra': $r = ['locacao' => loc_extra($d, $body, $locUser)]; break;
        case 'locacoes_prorrogar': $r = ['locacao' => loc_prorrogar($d, $body, $locUser)]; break;
        case 'locacoes_devolver': $r = ['locacao' => loc_devolver($d, $body, $locUser)]; break;
        case 'locacoes_reabrir': $r = ['locacao' => loc_reabrir($d, $body, $locUser)]; break;
        case 'locacoes_excluir': $r = loc_excluir($d, $body, $locUser); break;
        case 'locacoes_doc_remover': $r = ['locacao' => loc_doc_remover($d, $body, $locUser)]; break;
        default: loc_fail('Ação de locações inválida', 400);
    }
    loc_write($d);
    return $r;
});
if (function_exists('append_event') && isset($res['locacao'])) {
    $l = $res['locacao'];
    $txt = ['locacoes_salvar' => 'Locação salva', 'locacoes_transferir' => 'Transferida para ' . $l['local_atual'], 'locacoes_devolver' => 'Devolvida à locadora',
            'locacoes_extra' => 'Custo lançado', 'locacoes_reabrir' => 'Reaberta', 'locacoes_prorrogar' => 'Devolução prorrogada para ' . date('d/m/Y', strtotime($l['previsao_devolucao'])), 'locacoes_desfazer_transferencia' => 'Transferência desfeita'][$action] ?? $action;
    append_event(0, 'estoque', $l['id'] . ' · ' . $l['equipamento'] . ' (' . $l['fornecedor'] . '): ' . $txt);
}
respond(['ok' => true] + $res);
