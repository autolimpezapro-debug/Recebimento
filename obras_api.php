<?php
/** Recebimento Drilling — rotas obras_* (v3.28.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/obras_lib.php';

$obUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$obMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($obMet === 'GET') {
    switch ($action) {
        case 'obras_painel': respond(['ok' => true] + ob_painel(ob_read()));
        case 'obras_obra': respond(['ok' => true] + ob_obra(ob_read(), (string)($_GET['id'] ?? '')));
        case 'obras_arquivo': ob_servir(ob_read());
        case 'obras_relatorio':   // v3.61.3
            $rel = ob_relatorio(ob_read(), (string)($_GET['obra'] ?? ''), (string)($_GET['ini'] ?? ''), (string)($_GET['fim'] ?? ''));
            if (!empty($_GET['csv'])) {
                header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="enviado_para_obras.csv"'); echo "\xEF\xBB\xBF";
                $o = fopen('php://output', 'w'); fputcsv($o, ['Data', 'Obra', 'Origem', 'Documento', 'OS', 'Material', 'Qtd', 'Un', 'Responsável/solicitante'], ';');
                foreach ($rel['linhas'] as $l) fputcsv($o, [implode('/', array_reverse(explode('-', $l['data']))), $l['obra'], $l['origem'], $l['documento'], $l['os'], $l['material'], $l['quantidade'] === null ? '' : str_replace('.', ',', (string)$l['quantidade']), $l['unidade'], $l['responsavel']], ';');
                exit;
            }
            respond(['ok' => true] + $rel);
        case 'obras_remessa': respond(['ok' => true] + ob_remessa_info(ob_read(), (string)($_GET['numero'] ?? ''), (string)($_GET['ignorar'] ?? '')));   // v3.60.0
        case 'obras_pc': respond(['ok' => true, 'pedido' => ob_pc_info(ob_read(), (int)($_GET['pc'] ?? 0), (string)($_GET['ignorar'] ?? ''))]);   // v3.33.0: só leitura
    }
    ob_fail('Ação de obras inválida', 400);
}
if ($obMet !== 'POST') ob_fail('Método não permitido', 405);

if ($action === 'obras_arquivo_enviar') {   // multipart: envio, lista, arquivo
    @set_time_limit(180);
    $r = ob_with_lock(function () use ($obUser) {
        $d = ob_read();
        $env = ob_arquivo_add($d, (string)($_POST['envio'] ?? ''), (string)($_POST['lista'] ?? ''), $obUser);
        ob_write($d);
        return ['envio' => $env];
    });
    respond(['ok' => true] + $r);
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 512 * 1024) ob_fail('Corpo muito grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) ob_fail('Dados inválidos', 400);

$res = ob_with_lock(function () use ($action, $body, $obUser) {
    $d = ob_read();
    switch ($action) {
        case 'obras_nova': $r = ['obra' => ob_obra_nova($d, $body)]; break;
        case 'obras_envio_salvar': $r = ['envio' => ob_envio_salvar($d, $body, $obUser)]; break;
        case 'obras_arquivo_remover': $r = ['envio' => ob_arquivo_remover($d, $body, $obUser)]; break;
        case 'obras_envio_excluir': $r = ob_envio_excluir($d, $body, $obUser); break;
        default: ob_fail('Ação de obras inválida', 400);
    }
    ob_write($d);
    return $r;
});
if (function_exists('append_event') && isset($res['envio']) && $action === 'obras_envio_salvar') {
    $e = $res['envio'];
    append_event(0, 'estoque', $e['id'] . ' · envio para ' . $e['obra_nome'] . ($e['numero'] ? ' (' . $e['tipo_label'] . ' ' . $e['numero'] . ')' : ''));
}
if (isset($res['envio']) && $action === 'obras_envio_salvar' && is_file(__DIR__ . '/notify_lib.php')) {
    require_once __DIR__ . '/notify_lib.php';
    $e = $res['envio'];
    notif_evento('obras', 'Envio para obra • ' . ($e['obra_nome'] ?? ''), ($e['id'] ?? '') . (!empty($e['numero']) ? ' · ' . ($e['tipo_label'] ?? '') . ' ' . $e['numero'] : '') . ' · por ' . $obUser, '/', $obUser);
}
respond(['ok' => true] + $res);
