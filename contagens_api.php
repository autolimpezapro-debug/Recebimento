<?php
/** Recebimento Drilling — rotas contagens_* (v3.55.0): Inventário e Acuracidade. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/contagens_lib.php';
$cntUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$cntMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cntGestor = !function_exists('rd_perm') || rd_perm('estoque', 'aprovar');
if ($cntMet === 'GET') {
    $d = cnt_read();
    switch ($action) {
        case 'contagens_painel': respond(['ok' => true] + cnt_painel($d, est_read()) + ['gestor' => $cntGestor]);
        case 'contagens_sugestao': respond(['ok' => true] + cnt_sugestao($d, est_read(), (int)($_GET['n'] ?? $d['config']['qtd_dia'])));
        case 'contagens_detalhe':
            $id = (string)($_GET['id'] ?? ''); if (!isset($d['contagens'][$id])) prt_fail('Contagem não encontrada.', 404);
            // Contagem cega: só gestor pode ver saldo do sistema antes de encerrar, e apenas se pedir (revelar=1).
            $cego = !($cntGestor && !empty($_GET['revelar']));
            respond(['ok' => true, 'contagem' => cnt_view($d['contagens'][$id], $cego), 'gestor' => $cntGestor]);
        case 'contagens_csv': case 'contagens_folha':
            $id = (string)($_GET['id'] ?? ''); if (!isset($d['contagens'][$id])) prt_fail('Contagem não encontrada.', 404); $c = $d['contagens'][$id];
            header('Cache-Control: no-store');
            if ($action === 'contagens_folha') { $h = cnt_folha_html($c); header('Content-Type: text/html; charset=utf-8'); echo $h; exit; }
            if (!$cntGestor && $c['status'] === 'CONTANDO') prt_fail('Exportação com saldo do sistema só após o encerramento ou para o gestor.', 403);
            $s = cnt_csv($c); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $id . '.csv"'); header('Content-Length: ' . strlen($s)); echo $s; exit;
    }
    prt_fail('Ação de contagens inválida', 400);
}
if ($cntMet !== 'POST') prt_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 4 * 1024 * 1024) prt_fail('Corpo muito grande', 413);
$body = json_decode($raw, true); if (!is_array($body)) prt_fail('Dados inválidos', 400);
$res = est_with_lock(function () use ($action, $body, $cntUser) {
    return cnt_with_lock(function () use ($action, $body, $cntUser) {
        $d = cnt_read(); $db = est_read(); $out = []; $gravaEst = false; $log = '';
        switch ($action) {
            case 'contagens_criar': $c = cnt_criar($d, $db, $body, $cntUser); $out = ['id' => $c['id']]; $log = $c['id'] . ' criada (' . count($c['itens']) . ' itens)'; break;
            case 'contagens_contar':
                $out = cnt_contar($d, $db, (string)($body['id'] ?? ''), (array)($body['itens'] ?? []), $cntUser); $log = ''; break;
            case 'contagens_decidir':
                cnt_decidir($d, (string)($body['id'] ?? ''), (string)($body['codigo'] ?? ''), ($body['acao'] ?? '') === 'aprovar' ? 'aprovar' : 'rejeitar', (string)($body['motivo'] ?? ''), $cntUser);
                $log = ($body['id'] ?? '') . ': decisão sobre ' . ($body['codigo'] ?? ''); break;
            case 'contagens_aplicar':
                $out = cnt_aplicar($d, $db, (string)($body['id'] ?? ''), !empty($body['ignorar_pendentes']), $cntUser); $gravaEst = true; $log = ($body['id'] ?? '') . ' aplicada: ' . $out['ajustes'] . ' ajuste(s)'; break;
            case 'contagens_cancelar':
                $c = &cnt_get($d, (string)($body['id'] ?? '')); if ($c['status'] !== 'CONTANDO') prt_fail('Só contagem em andamento pode ser cancelada.', 409);
                $c['status'] = 'CANCELADA'; $c['historico'][] = ['em' => date('c'), 'por' => $cntUser, 'acao' => 'Cancelada: ' . prt_str($body['motivo'] ?? '', 120)]; $log = $c['id'] . ' cancelada'; unset($c); break;
            case 'contagens_config':
                $c = &$d['config']; $c['tolerancia_pct'] = max(0, min(50, (float)($body['tolerancia_pct'] ?? $c['tolerancia_pct']))); $c['tolerancia_abs'] = max(0, (float)($body['tolerancia_abs'] ?? $c['tolerancia_abs']));
                $c['meta_acuracidade'] = max(1, min(100, (float)($body['meta_acuracidade'] ?? $c['meta_acuracidade']))); $c['qtd_dia'] = max(1, min(200, (int)($body['qtd_dia'] ?? $c['qtd_dia'])));
                foreach (['A', 'B', 'C'] as $k) if (isset($body['freq_dias'][$k])) $c['freq_dias'][$k] = max(1, min(720, (int)$body['freq_dias'][$k]));
                if (isset($body['cega'])) $c['cega'] = (bool)$body['cega']; unset($c); $log = 'configuração alterada'; break;
            default: prt_fail('Ação de contagens inválida', 400);
        }
        if ($gravaEst) est_write($db);
        cnt_write($d); $out['_log'] = $log; return $out;
    });
});
$log = $res['_log'] ?? ''; unset($res['_log']);
if ($log !== '' && function_exists('append_event')) append_event(0, 'estoque', 'Inventário/Acuracidade: ' . $log);
respond(['ok' => true] + $res);
