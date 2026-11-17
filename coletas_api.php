<?php
/**
 * Recebimento Drilling — rotas do módulo de Coletas (v3.5.0)
 * Chamadas por api.php?action=coletas_*  (mesma origem e sessão).
 *
 * GET
 *   coletas_listar[&status=&dia=&q=]     coletas
 *   coletas_dashboard                     KPIs
 *   coletas_rota[&dia=YYYY-MM-DD]         paradas ordenadas + km/min
 *   coletas_mapa                          pontos com coordenadas
 *   coletas_agenda[&de=&ate=]             coletas por dia
 *   coletas_detalhe&id=COL-...            uma coleta
 *   coletas_config                        origem, tiles e OSRM
 * POST
 *   coletas_salvar    {pc, fornecedor, endereco, ..., agendada_data}
 *   coletas_status    {id, status}
 *   coletas_geocodar  {id, provedor?, forcar?}
 *   coletas_retorno   {id, motivo, observacao, reagendar_data?}
 *   coletas_excluir   {id}
 *   coletas_importar  {pc}                (pré-preenche a partir do pedido)
 *   coletas_config_save {origem:{lat,lng,nome}, tiles, osrm}
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/coletas_lib.php';

function col_user() {
    if (function_exists('rd_login_info')) {
        $u = rd_login_info();
        if (is_array($u) && !empty($u['username'])) return (string)$u['username'];
    }
    return 'operador-local';
}

function col_role() {
    if (function_exists('rd_login_info')) {
        $u = rd_login_info();
        if (is_array($u) && !empty($u['role'])) return (string)$u['role'];
    }
    return 'gestor';
}

function col_require_write() {
    if (!rd_perm_or_legado('coletas', 'criar', ['gestor', 'conferente'])) {
        respond(['ok' => false, 'error' => 'Seu perfil não permite alterar as coletas'], 403);
    }
}

function col_body() {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 262144) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $b;
}

$colAction = $_GET['action'] ?? '';
$colMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($colMethod === 'GET') {
    switch ($colAction) {
        case 'coletas_listar':
            respond(['ok' => true] + col_listar($_GET));
        case 'coletas_motorista_info':   // v3.40.0: situação do link do app do motorista
            require_once __DIR__ . '/motorista_lib.php';
            respond(['ok' => true] + mot_info());
        case 'coletas_dashboard':
            respond(['ok' => true] + col_dashboard());
        case 'coletas_rota':
            respond(['ok' => true] + col_rota($_GET));
        case 'coletas_mapa':
            respond(['ok' => true] + col_mapa());
        case 'coletas_agenda':
            respond(['ok' => true] + col_agenda($_GET));
        case 'coletas_detalhe':
            $db = col_read();
            $id = col_sanitize_id($_GET['id'] ?? '');
            if (!isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Coleta não encontrada'], 404);
            respond(['ok' => true, 'coleta' => col_view($db['coletas'][$id])]);
        case 'coletas_rota_escolhida':   // v3.23.0: rota só com as coletas marcadas
            $db = col_read();
            respond(['ok' => true] + col_ordenar_paradas($db, array_filter(explode(',', (string)($_GET['ids'] ?? ''))), ($_GET['otimizar'] ?? '1') !== '0'));
        case 'coletas_rotas_resumo':
            respond(['ok' => true] + col_rotas_resumo($_GET));
        case 'coletas_rota_ativa':
            respond(['ok' => true] + col_rota_ativa_view(col_read()));
        case 'coletas_config':
            $db = col_read();
            respond(['ok' => true, 'config' => $db['config'], 'status' => COL_STATUS, 'prioridades' => COL_PRIORIDADES, 'motivos_retorno' => COL_RETORNO_MOTIVOS]);
    }
    respond(['ok' => false, 'error' => 'Ação de coletas inválida'], 400);
}

if ($colMethod !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) {
    respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
}
// v3.23.0: o motorista (perfil com permissão de status) atualiza a coleta e a posição sem precisar de "criar".
if (in_array($colAction, ['coletas_status', 'coletas_retorno', 'coletas_posicao'], true)) {
    if (!rd_perm_or_legado('coletas', 'status', ['gestor', 'conferente', 'motorista'])) respond(['ok' => false, 'error' => 'Seu perfil não permite atualizar coletas'], 403);
} else col_require_write();
// v3.13.0: importar pedidos de coleta (upload multipart → prévia; JSON → confirmar). Só grava em coletas.json.
if ($colAction === 'coletas_importar_previa') {
    require_once __DIR__ . '/coletas_import.php';
    @set_time_limit(240);
    $fs = [];
    $up = $_FILES['arquivos'] ?? null;
    if (is_array($up) && is_array($up['name'] ?? null)) foreach ($up['name'] as $i => $n) $fs[] = ['name' => $n, 'tmp_name' => $up['tmp_name'][$i], 'error' => $up['error'][$i]];
    elseif (is_array($up)) $fs[] = $up;
    if (!$fs) respond(['ok' => false, 'error' => 'Envie ao menos um arquivo (PDF do pedido ou ZIP do WhatsApp).'], 422);
    respond(['ok' => true] + coli_previa($fs));
}
$colBody = col_body();
if ($colAction === 'coletas_importar_previa_partes') {   // v3.14.0: arquivos enviados em partes (até 1 GB cada)
    require_once __DIR__ . '/coletas_import.php';
    require_once __DIR__ . '/upload_lib.php';
    @set_time_limit(900); @ini_set('memory_limit', '512M');
    $fs = []; $ids = array_slice((array)($colBody['uploads'] ?? []), 0, COLI_MAX_ARQUIVOS);
    foreach ($ids as $id) { $u = rd_up_arquivo((string)$id); if ($u) $fs[] = ['name' => $u['nome'], 'path' => $u['path']]; }
    if (!$fs) respond(['ok' => false, 'error' => 'Envio não encontrado ou expirado. Selecione os arquivos novamente.'], 410);
    try { $r = coli_previa($fs); } finally { foreach ($ids as $id) rd_up_apagar((string)$id); }   // anexos não ficam no servidor
    respond(['ok' => true] + $r);
}
if ($colAction === 'coletas_localizar_fornecedores') {   // v3.18.1: endereços/coordenadas pelo CNPJ (lotes de 12)
    require_once __DIR__ . '/coletas_import.php';
    @set_time_limit(120);
    respond(['ok' => true] + coli_localizar_fornecedores(12));
}
if ($colAction === 'coletas_rota_por_pcs') {   // v3.24.0: digitar os PCs e montar a rota
    require_once __DIR__ . '/coletas_import.php';
    @set_time_limit(120);
    respond(['ok' => true] + coli_rota_por_pcs((array)($colBody['pcs'] ?? []), col_user()));
}
if ($colAction === 'coletas_importar_confirmar') {
    require_once __DIR__ . '/coletas_import.php';
    @set_time_limit(240);
    respond(['ok' => true] + coli_confirmar($colBody, $colUser = col_user()));
}
$colUser = col_user();

switch ($colAction) {
    case 'coletas_salvar':
        respond(['ok' => true] + col_salvar($colBody, $colUser));

    case 'coletas_status':
        respond(['ok' => true] + col_mudar_status($colBody['id'] ?? '', $colBody['status'] ?? '', $colUser, (string)($colBody['detalhe'] ?? ''), $colBody['km_real'] ?? null));

    case 'coletas_geocodar':
        @set_time_limit(60);
        $id = col_sanitize_id($colBody['id'] ?? '');
        $provedor = (string)($colBody['provedor'] ?? 'photon');
        $forcar = !empty($colBody['forcar']);
        $r = col_with_lock(function () use ($id, $forcar, $provedor) {
            $db = col_read();
            if (!isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Coleta não encontrada'], 404);
            $c = $db['coletas'][$id];
            $res = col_geocodificar($db, $c, $forcar, $provedor);
            $c['atualizado_em'] = col_now();
            $db['coletas'][$id] = $c;
            col_write($db);
            return ['resultado' => $res, 'coleta' => col_view($c)];
        });
        respond(['ok' => true] + $r);

    case 'coletas_retorno':
        respond(['ok' => true] + col_registrar_retorno($colBody['id'] ?? '', $colBody, $colUser));

    case 'coletas_rota_iniciar':
        respond(['ok' => true] + col_rota_iniciar((array)($colBody['ids'] ?? []), (string)($colBody['motorista'] ?? ''), $colUser, !empty($colBody['otimizar']), (array)($colBody['rua'] ?? [])));

    case 'coletas_rota_encerrar':
        respond(['ok' => true] + col_rota_encerrar($colUser));

    case 'coletas_motorista_gerar':   // v3.40.0: gera o link pessoal (o anterior deixa de valer)
        require_once __DIR__ . '/motorista_lib.php';
        $tk = mot_gerar((string)($colBody['nome'] ?? ''), $colUser);
        $esq = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        respond(['ok' => true, 'link' => $esq . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/motorista.html?t=' . $tk] + mot_info());
    case 'coletas_motorista_revogar':
        require_once __DIR__ . '/motorista_lib.php'; mot_revogar($colUser); respond(['ok' => true] + mot_info());
    case 'coletas_motorista_liberar':
        require_once __DIR__ . '/motorista_lib.php'; mot_liberar_aparelhos($colUser); respond(['ok' => true] + mot_info());

    case 'coletas_posicao':
        respond(['ok' => true] + col_posicao($colBody, $colUser));

    case 'coletas_excluir':
        respond(['ok' => true] + col_excluir($colBody['id'] ?? '', $colUser));

    case 'coletas_importar':
        respond(['ok' => true, 'pedido' => col_importar_pedido((int)($colBody['pc'] ?? 0))]);

    case 'coletas_config_save':
        $r = col_with_lock(function () use ($colBody) {
            $db = col_read();
            if (isset($colBody['origem']) && is_array($colBody['origem'])) {
                $db['config']['origem'] = [
                    'lat' => (float)($colBody['origem']['lat'] ?? COL_ORIGEM_PADRAO['lat']),
                    'lng' => (float)($colBody['origem']['lng'] ?? COL_ORIGEM_PADRAO['lng']),
                    'nome' => col_str($colBody['origem']['nome'] ?? COL_ORIGEM_PADRAO['nome'], 160),
                ];
            }
            if (isset($colBody['tiles'])) {
                $t = (string)$colBody['tiles'];
                $db['config']['tiles'] = in_array($t, ['voyager', 'osm', 'hot'], true) ? $t : 'osm';
            }
            if (array_key_exists('osrm', $colBody)) $db['config']['osrm'] = (bool)$colBody['osrm'];
            col_write($db);
            return ['config' => $db['config']];
        });
        respond(['ok' => true] + $r);
}
respond(['ok' => false, 'error' => 'Ação de coletas inválida'], 400);
