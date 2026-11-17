<?php
/** Recebimento Drilling — API pública SOMENTE LEITURA do módulo Importados (v3.45.0). Acesso só pelo código do link (?k=). */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
define('DATA_DIR', __DIR__ . '/data');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
function respond($data, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
define('ITEMS_FILE', DATA_DIR . '/items.json');   // usada pelo estoque_lib (definida no api.php)
require_once __DIR__ . '/estoque_lib.php';
require_once __DIR__ . '/importados_publico_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') respond(['ok' => false, 'error' => 'Somente leitura'], 405);
if (impp_bloqueado()) respond(['ok' => false, 'error' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $k) || !impp_token_ok($k)) { impp_falhou(); respond(['ok' => false, 'error' => 'Link inválido ou desativado.'], 403); }

switch ((string)($_GET['a'] ?? 'lista')) {
    case 'lista':
        $v = est_importados_view(est_read());
        impp_registrar_acesso();
        respond(['ok' => true, 'itens' => array_map('impp_item', $v['itens']), 'meses' => $v['meses'], 'gerado_em' => date('c')]);
    case 'item':
        $v = est_importados_view(est_read(), (string)($_GET['codigo'] ?? ''));
        if (!$v) respond(['ok' => false, 'error' => 'Item não encontrado'], 404);
        respond(['ok' => true, 'item' => impp_item($v)]);
    default:
        respond(['ok' => false, 'error' => 'Ação inválida'], 400);
}
