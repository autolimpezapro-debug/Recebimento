<?php
/**
 * Recebimento Drilling — API do Módulo de Estoque (v3.0.0)
 * -------------------------------------------------------
 * Chamada por api.php?action=estoque_* (mesma origem, mesma trava de escrita).
 *
 * Rotas (GET):
 *   estoque_dashboard, estoque_itens, estoque_item&codigo=, estoque_entradas,
 *   estoque_entrada&pc=, estoque_movimentos, estoque_requisicoes,
 *   estoque_export_csv&tipo=itens|movimentos|entradas
 * Rotas (POST, JSON):
 *   estoque_entrada_save, estoque_entrada_from_pedido, estoque_entrada_remover,
 *   estoque_conferir, estoque_receber, estoque_entrada_manual,
 *   estoque_item_save, estoque_saida, estoque_estorno
 *
 * Perfis (quando a autenticação operacional estiver ativa):
 *   leitura → gestor, conferente, consulta, importador
 *   escrita → gestor, conferente
 *   estorno → gestor
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

require_once __DIR__ . '/estoque_lib.php';

/* ---------------- identificação do operador ---------------- */

function est_user_name() {
    if (function_exists('rd_login_info')) {
        $id = rd_login_info();
        if (is_array($id) && !empty($id['username'])) return $id['username'];
    }
    return 'operador-local';
}

function est_user_role() {
    if (function_exists('rd_login_info')) {
        $id = rd_login_info();
        if (is_array($id) && !empty($id['role'])) return $id['role'];
    }
    return 'gestor';
}

function est_require_write($role) {
    if (!rd_perm_or_legado('estoque', 'criar', ['gestor', 'conferente'])) {
        respond(['ok' => false, 'error' => 'Seu perfil não permite movimentar o estoque'], 403);
    }
}

function est_json_body() {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 12 * 1024 * 1024) respond(['ok' => false, 'error' => 'Corpo da requisição muito grande'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $body;
}

/** Registra a movimentação no histórico compartilhado de atividades (events.json). */
function est_log_event($tipo, $mensagem) {
    if (function_exists('append_event')) append_event(0, $tipo, $mensagem);
}

/* ---------------- consultas ---------------- */

function est_list_itens(array $db, array $q) {
    $search = est_norm_key($q['q'] ?? '');
    $status = (string)($q['status'] ?? '');
    $cat = (string)($q['categoria'] ?? '');
    $rows = [];
    foreach ($db['itens'] as $code => $it) {
        if (!empty($it['unificado_para'])) continue;   // v3.57.0: cadastro unificado não aparece na lista
        $v = est_item_view($it);
        if ($cat !== '' && ($v['categoria'] ?? '') !== $cat) continue;
        if ($status !== '' && $v['status'] !== $status) continue;
        if ($search !== '') {
            $hay = est_norm_key($v['descricao'] . ' ' . $code . ' ' . implode(' ', $v['aliases'] ?? []) . ' ' . ($v['categoria'] ?? ''));
            // v3.51.3: todas as palavras digitadas precisam existir, em qualquer ordem (antes tinha de ser a frase exata)
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $w) if (mb_strpos($hay, $w) === false) continue 2;
        }
        // Lista enxuta (v3.10.1): só os campos da tabela/edição. A ficha completa vem de estoque_item.
        $rows[] = array_intersect_key($v, array_flip(['codigo', 'descricao', 'categoria', 'localizacao', 'unidade', 'saldo', 'reservado',
            'disponivel', 'minimo', 'maximo', 'ponto_pedido', 'status', 'custo_medio', 'valor_estoque', 'fornecedor_padrao', 'importado', 'repor']));
    }
    $sort = (string)($q['sort'] ?? 'descricao');
    usort($rows, function ($a, $b) use ($sort) {
        return match ($sort) {
            'saldo_asc' => $a['saldo'] <=> $b['saldo'],
            'saldo_desc' => $b['saldo'] <=> $a['saldo'],
            'codigo' => strcmp($a['codigo'], $b['codigo']),
            'atualizado' => strcmp((string)$b['atualizado_em'], (string)$a['atualizado_em']),
            default => strcmp(est_norm_key($a['descricao']), est_norm_key($b['descricao'])),
        };
    });
    $cats = [];
    foreach ($db['itens'] as $it) if (!empty($it['categoria'])) $cats[$it['categoria']] = true;
    $total = count($rows);
    if (($lim = (int)($q['limite'] ?? 0)) > 0) $rows = array_slice($rows, 0, min($lim, 50));
    return ['itens' => $rows, 'categorias' => array_keys($cats), 'total' => $total];
}

function est_item_detail(array $db, $codigo) {
    $code = est_sanitize_code($codigo);
    if (!isset($db['itens'][$code])) respond(['ok' => false, 'error' => 'Item não encontrado'], 404);
    $item = est_item_view($db['itens'][$code]);
    $movs = array_values(array_filter($db['movimentos'], fn($m) => ($m['codigo'] ?? '') === $code));
    $movs = array_slice(array_reverse($movs), 0, 100);
    return ['item' => $item, 'movimentos' => $movs];
}

/** v3.51.0: busca por nome do insumo (e código/PC/fornecedor/NF): todas as palavras digitadas precisam aparecer, sem acento e sem maiúsculas. */
function est_q_norm($t): string {
    $t = mb_strtolower((string)$t, 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
}
function est_q_match($registro, string $q): bool {
    $q = est_q_norm($q);
    if ($q === '') return true;
    $txt = est_q_norm(is_string($registro) ? $registro : json_encode($registro, JSON_UNESCAPED_UNICODE));
    foreach (explode(' ', $q) as $w) if ($w !== '' && !str_contains($txt, $w)) return false;
    return true;
}

function est_entradas_list(array $db, array $q) {
    $rows = [];
    foreach ($db['recebimentos'] as $key => $d) {
        if (!empty($q['status']) && ($d['status'] ?? '') !== $q['status']) continue;
        if (!empty($q['q']) && !est_q_match([$d['pc'] ?? '', $d['fornecedor'] ?? '', $d['numero_nf'] ?? '', array_map(fn($l) => [$l['descricao'] ?? '', $l['codigo'] ?? ''], $d['linhas'] ?? [])], (string)$q['q'])) continue;
        $linhas = $d['linhas'] ?? [];
        $conf = 0; $div = 0; $qtd = 0.0; $rec = 0.0;
        foreach ($linhas as $l) {
            if (!empty($l['conferido'])) $conf++;
            if (!empty($l['conferido']) && round((float)($l['quantidade_conferida'] ?? 0), 4) !== round((float)$l['quantidade'], 4)) $div++;
            $qtd += (float)$l['quantidade'];
            $rec += (float)($l['quantidade_recebida'] ?? 0);
        }
        $rows[] = [
            'pc' => $d['pc'], 'entrada_id' => $d['entrada_id'], 'fornecedor' => $d['fornecedor'] ?? '',
            'numero_nf' => $d['numero_nf'] ?? '', 'status' => $d['status'] ?? 'pendente',
            'criado_em' => $d['criado_em'] ?? null, 'recebido_em' => $d['recebido_em'] ?? null,
            'linhas' => count($linhas), 'conferidas' => $conf, 'divergencias' => $div,
            'qtd_pedida' => round($qtd, 4), 'qtd_recebida' => round($rec, 4),
            'origem' => $d['origem'] ?? 'MANUAL',
        ];
    }
    usort($rows, fn($a, $b) => strcmp((string)$b['criado_em'], (string)$a['criado_em']));
    return ['entradas' => $rows, 'total' => count($rows)];
}

function est_entrada_detail(array $db, $pc) {
    $key = 'PC-' . (int)$pc;
    if (!isset($db['recebimentos'][$key])) respond(['ok' => false, 'error' => 'Entrada não encontrada'], 404);
    $doc = $db['recebimentos'][$key];
    // Sugere o item de estoque já vinculado (ou o candidato por descrição).
    foreach ($doc['linhas'] as $i => $l) {
        $doc['linhas'][$i]['codigo_sugerido'] = $l['codigo'] ?: (string)(est_match_item($db, $l['descricao']) ?? '');
    }
    return ['entrada' => $doc];
}

function est_movimentos_list(array $db, array $q) {
    $tipo = (string)($q['tipo'] ?? '');
    $ini = (string)($q['de'] ?? '');
    $fim = (string)($q['ate'] ?? '');
    $code = est_sanitize_code($q['codigo'] ?? '');
    $rows = [];
    foreach (array_reverse($db['movimentos']) as $m) {
        if ($tipo !== '' && ($m['tipo'] ?? '') !== $tipo) continue;
        if ($code !== '' && ($m['codigo'] ?? '') !== $code) continue;
        if (!empty($q['q']) && !est_q_match([$m['codigo'] ?? '', $m['descricao_origem'] ?? '', $db['itens'][$m['codigo'] ?? '']['descricao'] ?? '', $m['pc'] ?? '', $m['setor'] ?? '', $m['fornecedor'] ?? ''], (string)$q['q'])) continue;
        $dia = substr((string)($m['em'] ?? ''), 0, 10);
        if ($ini !== '' && $dia < $ini) continue;
        if ($fim !== '' && $dia > $fim) continue;
        $it = $db['itens'][$m['codigo']] ?? null;
        $m['descricao'] = $it['descricao'] ?? ($m['descricao_origem'] ?? $m['codigo']);
        $m['unidade'] = $it['unidade'] ?? 'UN';
        $rows[] = $m;
    }
    return ['movimentos' => array_slice($rows, 0, 2000), 'total' => count($rows), 'tipos' => EST_MOV_TIPOS];
}

/* ---------------- escrita ---------------- */

function est_handle() {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $role = est_user_role();
    $user = est_user_name();

    /* -------- leitura -------- */
    if ($method === 'GET') {
        switch ($action) {
            case 'estoque_dashboard':
                $db = est_read();
                respond(['ok' => true, 'dashboard' => est_dashboard($db), 'analise' => est_dashboard_analise($db, (int)($_GET['dias'] ?? 90)), 'versao' => '3.0.0',
                    'usuario' => $user, 'perfil' => $role, 'integridade' => est_check_pedidos_integridade($db)]);
            case 'estoque_ia':
                require_once __DIR__ . '/gemini_assist.php';
                @set_time_limit(60);
                respond(['ok' => true] + rd_ia_insights_estoque(est_dashboard_analise(est_read(), (int)($_GET['dias'] ?? 90)), true));
            case 'estoque_obras':
                require_once __DIR__ . '/rd_obras.php';
                respond(['ok' => true, 'obras' => rd_obras_lista(), 'setores' => RD_SETORES]);
            case 'estoque_duplicados':   // v3.57.0
                require_once __DIR__ . '/duplicados_lib.php'; respond(['ok' => true, 'pares' => dup_sugestoes(est_read())]);
            case 'estoque_itens':
                respond(['ok' => true] + est_list_itens(est_read(), $_GET));
            case 'estoque_os_config':
                respond(['ok' => true, 'setores' => est_os_setores(est_read())]);
            case 'estoque_equipamentos':   // v3.27.0: sugestões para "Equipamento aplicado"
                respond(['ok' => true, 'equipamentos' => est_equipamentos_sugestoes(est_read())]);
            case 'estoque_saida_buscar':   // v3.30.0: nome, SKU ou nº do PC
                respond(['ok' => true] + est_buscar_saida(est_read(), (string)($_GET['q'] ?? '')));
            case 'estoque_saida_pc':       // v3.30.0: o que o PC trouxe e o que ainda pode sair
                respond(['ok' => true, 'pedido' => est_pc_saida_info(est_read(), (int)($_GET['pc'] ?? 0))]);
            case 'estoque_item':
                respond(['ok' => true] + est_item_detail(est_read(), $_GET['codigo'] ?? ''));
            case 'estoque_entradas':
                respond(['ok' => true] + est_entradas_list(est_read(), $_GET));
            case 'estoque_entrada':
                respond(['ok' => true] + est_entrada_detail(est_read(), $_GET['pc'] ?? 0));
            case 'estoque_movimentos':
                respond(['ok' => true] + est_movimentos_list(est_read(), $_GET));
            case 'estoque_sku_relatorio':
                respond(['ok' => true] + est_relatorio_sku(est_read(), function_exists('read_items') ? read_items() : []));
            case 'estoque_comprovante':
                $html = est_comprovante_html(est_read(), preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['doc'] ?? '')));
                if ($html === null) est_fail('Documento não encontrado', 404);
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Security-Policy: default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'');
                echo $html; exit;
            case 'estoque_foto_saida':
                $fp = est_foto_saida_path((string)($_GET['arq'] ?? ''));
                if (!$fp) est_fail('Foto não encontrada', 404);
                header('Content-Type: ' . (mime_content_type($fp) ?: 'image/jpeg'));
                header('Cache-Control: private, max-age=86400');
                header('X-Content-Type-Options: nosniff');
                readfile($fp); exit;
            case 'estoque_funcionarios':
                respond(['ok' => true] + est_funcionarios_lista(est_read()));
            case 'estoque_funcionario_retiradas':
                respond(['ok' => true, 'retiradas' => est_funcionario_retiradas(est_read(), (string)($_GET['id'] ?? ''))]);
            case 'estoque_importados':
                respond(['ok' => true] + est_importados_view(est_read()));
            case 'estoque_importados_link':   // v3.45.0
                require_once __DIR__ . '/importados_publico_lib.php';
                respond(['ok' => true, 'link' => impp_info()]);
            case 'estoque_importado_item':
                $v = est_importados_view(est_read(), (string)($_GET['codigo'] ?? ''));
                if (!$v) est_fail('Item importado não encontrado', 404);
                respond(['ok' => true, 'item' => $v]);
            case 'estoque_sap_historico':
                respond(['ok' => true, 'historico' => est_sap_historico(est_read())]);
            case 'estoque_inventarios':
                respond(['ok' => true] + est_inventarios_listar(est_read()));
            case 'estoque_inventario':
                $db = est_read();
                $id = est_sanitize_code($_GET['id'] ?? '');
                if (!isset($db['inventarios'][$id])) respond(['ok' => false, 'error' => 'Contagem não encontrada'], 404);
                respond(['ok' => true, 'inventario' => $db['inventarios'][$id]]);
            case 'estoque_reservas':
                $db = est_read();
                $rows = array_values($db['reservas'] ?? []);
                if (($q = trim((string)($_GET['q'] ?? ''))) !== '') $rows = array_values(array_filter($rows, fn($r) => est_q_match($r, $q)));
                usort($rows, fn($a, $b) => strcmp((string)($b['atualizado_em'] ?? ''), (string)($a['atualizado_em'] ?? '')));
                respond(['ok' => true, 'reservas' => $rows, 'total' => count($rows)]);
            case 'estoque_reposicao':
                respond(['ok' => true] + est_reposicao(est_read()));
            case 'estoque_abc':
                respond(['ok' => true] + est_curva_abc(est_read(), (string)($_GET['de'] ?? ''), (string)($_GET['ate'] ?? '')));
            case 'estoque_kardex':
                respond(['ok' => true] + est_kardex(est_read(), (string)($_GET['codigo'] ?? ''), (int)($_GET['limite'] ?? 200)));
            case 'estoque_requisicoes':
                $db = est_read();
                $rows = array_values($db['requisicoes']);
                if (($q = trim((string)($_GET['q'] ?? ''))) !== '') $rows = array_values(array_filter($rows, fn($r) => est_q_match($r, $q)));
                usort($rows, fn($a, $b) => strcmp((string)($b['atualizado_em'] ?? ''), (string)($a['atualizado_em'] ?? '')));
                respond(['ok' => true, 'requisicoes' => $rows, 'total' => count($rows)]);
            case 'estoque_export_csv':
                est_export_csv(est_read(), (string)($_GET['tipo'] ?? 'itens'));
        }
        respond(['ok' => false, 'error' => 'Ação de estoque inválida'], 400);
    }

    if ($method !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);

    /* -------- escrita -------- */
    if ($action === 'estoque_estorno') {
        if ($role !== 'gestor') respond(['ok' => false, 'error' => 'Somente o gestor pode estornar movimentos'], 403);
    } else {
        est_require_write($role);
    }
    // Trava de CSRF/origem quando a autenticação operacional está disponível.
    if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) {
        respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
    }

    if ($action === 'estoque_importados_link_gerar' || $action === 'estoque_importados_link_revogar') {   // v3.45.0 — não mexe no estoque
        require_once __DIR__ . '/importados_publico_lib.php';
        try { if ($action === 'estoque_importados_link_gerar') impp_gerar($user); else impp_revogar(); }
        catch (Throwable $e) { respond(['ok' => false, 'error' => $e->getMessage()], 500); }
        respond(['ok' => true, 'link' => impp_info()]);
    }

    $body = est_json_body();
    $result = est_with_lock(function () use ($action, $body, $user) {
        $db = est_read();
        switch ($action) {
            case 'estoque_entrada_save':
                $doc = est_save_entrada($db, $body, $user);
                est_write($db);
                est_log_event('estoque', 'Entrada ' . $doc['entrada_id'] . ' (PC ' . $doc['pc'] . ') salva com ' . count($doc['linhas']) . ' linha(s)');
                return ['entrada' => $doc];

            case 'estoque_entrada_from_pedido':
                $pc = (int)($body['pc'] ?? 0);
                if ($pc <= 0) est_fail('Informe o PC');
                if (!function_exists('read_items')) est_fail('Base de pedidos indisponível', 500);
                $pedidos = read_items();
                $pedido = null;
                foreach ($pedidos as $p) if ((int)$p['pc'] === $pc) { $pedido = $p; break; }
                if (!$pedido) est_fail('PC ' . $pc . ' não encontrado na base de pedidos', 404);
                $doc = est_entrada_from_pedido($db, $pedido, $user);
                est_write($db);
                est_log_event('estoque', 'Entrada gerada do pedido PC ' . $pc);
                return ['entrada' => $doc];

            case 'estoque_entrada_remover':
                $pc = (int)($body['pc'] ?? 0);
                $key = 'PC-' . $pc;
                if (!isset($db['recebimentos'][$key])) est_fail('Entrada não encontrada', 404);
                if (($db['recebimentos'][$key]['status'] ?? '') === 'recebido') est_fail('Entrada recebida não pode ser excluída; use estorno.', 409);
                unset($db['recebimentos'][$key]);
                est_write($db);
                return ['removido' => $pc];

            case 'estoque_conferir':
                $doc = est_confere($db, $body, $user);
                est_write($db);
                return ['entrada' => $doc];

            case 'estoque_receber':
                $pc = (int)($body['pc'] ?? 0);
                $doc = est_receber($db, $pc, $user, est_str($body['localizacao'] ?? '', 80));
                est_write($db);
                est_log_event('estoque', 'PC ' . $pc . ' recebido no estoque (' . count($doc['linhas']) . ' linha(s))');
                // Avisa todos os logados: entrada confirmada no estoque.
                est_notify([
                    'tipo' => 'estoque',
                    'titulo' => 'Entrada no estoque • PC ' . $pc,
                    'texto' => 'Materiais conferidos e lançados no estoque (' . count($doc['linhas']) . ' linha(s)). NF ' . (($doc['numero_nf'] ?? '') !== '' ? $doc['numero_nf'] : 'não informada') . '.',
                    'pc' => $pc,
                    'url' => '/?pc=' . $pc,
                    'autor' => $user,
                    'origem_device' => (string)($_SERVER['HTTP_X_CLIENT_ID'] ?? ''),
                ]);
                return ['entrada' => $doc, 'resumo' => est_dashboard($db)];

            case 'estoque_entrada_manual':
                $linhas = est_lines_input($body);
                $agora = est_now();
                $db['sequencia']['entrada'] = (int)($db['sequencia']['entrada'] ?? 0) + 1;
                $doc = [
                    'pc' => 0,
                    'entrada_id' => 'ENT-' . date('Y') . '-' . str_pad((string)$db['sequencia']['entrada'], 4, '0', STR_PAD_LEFT),
                    'fornecedor' => est_str($body['fornecedor'] ?? '', 200),
                    'numero_nf' => est_str($body['numero_nf'] ?? '', 60),
                    'origem' => 'MANUAL',
                    'observacao' => est_str($body['observacao'] ?? '', 1000),
                    'status' => 'pendente', 'linhas' => [], 'criado_em' => $agora,
                    'atualizado_em' => $agora, 'recebido_em' => null, 'usuario' => $user,
                ];
                $movs = [];
                foreach ($linhas as $line) {
                    $resolved = est_resolve_line_item($db, $line, true);
                    $unitario = isset($line['valor_unitario']) && is_numeric($line['valor_unitario']) ? (float)$line['valor_unitario'] : 0.0;
                    $valor = $unitario > 0 ? $unitario * (float)$line['quantidade'] : null;
                    $movs[] = est_apply_movement($db, [
                        'tipo' => 'ENTRADA_MANUAL', 'codigo' => $resolved['codigo'],
                        'quantidade' => (float)$line['quantidade'],
                        'valor_total' => $valor !== null && $valor > 0 ? round($valor, 4) : null,
                        'documento' => $doc['entrada_id'], 'numero_nf' => $doc['numero_nf'],
                        'fornecedor' => $doc['fornecedor'], 'descricao_origem' => $line['descricao'],
                        'observacao' => $line['observacao'], 'usuario' => $user,
                    ]);
                    $doc['linhas'][] = array_merge($line, [
                        'codigo' => $resolved['codigo'], 'quantidade_recebida' => (float)$line['quantidade'],
                        'quantidade_pendente' => 0, 'conferido' => true,
                        'quantidade_conferida' => (float)$line['quantidade'],
                        'recebido_em' => $agora, 'movimento_id' => end($movs)['id'],
                    ]);
                }
                $doc['status'] = 'recebido';
                $doc['recebido_em'] = $agora;
                $doc['recebido_por'] = $user;
                $db['recebimentos']['ENT-' . $doc['entrada_id']] = $doc;
                est_write($db);
                est_log_event('estoque', 'Entrada manual ' . $doc['entrada_id'] . ' com ' . count($movs) . ' item(ns)');
                return ['entrada' => $doc, 'movimentos' => $movs];

            case 'estoque_unificar':   // v3.57.0: unifica cadastro duplicado (saldo, apelidos, histórico preservado)
                require_once __DIR__ . '/duplicados_lib.php';
                $r = dup_unificar($db, (string)($body['de'] ?? ''), (string)($body['para'] ?? ''), (float)str_replace(',', '.', (string)($body['fator'] ?? 1)), (string)($body['motivo'] ?? ''), $user);
                est_write($db);
                return $r;

            case 'estoque_duplicado_ignorar':   // v3.57.0: "são itens diferentes"
                require_once __DIR__ . '/duplicados_lib.php';
                dup_ignorar($db, (string)($body['a'] ?? ''), (string)($body['b'] ?? ''), $user);
                est_write($db);
                return ['ok' => true];

            case 'estoque_item_save':
                // Preserva o código exato do item escolhido (a interface envia o código real).
                $code = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($body['codigo'] ?? ''));
                $code = mb_substr($code, 0, 70);
                $desc = est_str($body['descricao'] ?? '', 600);
                if (mb_strlen($desc) < 2) est_fail('Informe a descrição do item');
                if ($code === '') $code = est_slug_code($desc);
                if (isset($db['itens'][$code])) {
                    $it = &$db['itens'][$code];
                    $it['descricao'] = $desc;
                    if (!empty($body['unidade'])) $it['unidade'] = strtoupper(est_str($body['unidade'], 12));
                    if (array_key_exists('categoria', $body)) $it['categoria'] = est_str($body['categoria'], 80);
                    if (array_key_exists('localizacao', $body)) $it['localizacao'] = est_str($body['localizacao'], 80);
                    if (array_key_exists('minimo', $body)) $it['minimo'] = round(max(0, (float)$body['minimo']), 4);
                    if (array_key_exists('ponto_pedido', $body)) $it['ponto_pedido'] = round(max(0, (float)$body['ponto_pedido']), 4);
                    if (array_key_exists('maximo', $body)) $it['maximo'] = round(max(0, (float)$body['maximo']), 4);
                    if (array_key_exists('fornecedor_padrao', $body)) $it['fornecedor_padrao'] = est_str($body['fornecedor_padrao'], 200);
                    if (array_key_exists('aliases', $body) && is_array($body['aliases'])) {
                        $it['aliases'] = array_slice(array_values(array_filter(array_map(fn($a) => est_str($a, 300), $body['aliases']))), 0, 25);
                    }
                    $it['atualizado_em'] = est_now();
                    unset($it);
                    $criado = false;
                } else {
                    $db['itens'][$code] = est_new_item(array_merge($body, ['descricao' => $desc]), $code);
                    $criado = true;
                }
                est_write($db);
                return ['item' => est_item_view($db['itens'][$code]), 'criado' => $criado];

            case 'estoque_os_config_salvar':
                $set = est_os_setores_salvar($db, $body);
                est_write($db);
                return ['setores' => $set];
            case 'estoque_saida_complementar':   // v3.61.0
                $r = est_req_complementar($db, (string)($body['id'] ?? ''), $body, $user);
                est_write($db);
                est_log_event('estoque', 'Saída ' . ($body['id'] ?? '') . ' complementada: OS ' . ($r['requisicao']['os'] ?? '—') . ' · equipamento ' . ($r['requisicao']['equipamento'] ?? '—'));
                return $r;
            case 'estoque_saida':
                $efetivar = !empty($body['efetivar']);
                $req = est_save_requisicao($db, $body, $user, $efetivar);
                est_write($db);
                if ($efetivar) {
                    est_log_event('estoque', 'Saída ' . $req['id'] . ' com ' . count($req['linhas']) . ' item(ns) para ' . ($req['setor'] ?: $req['solicitante']));
                    est_notify([
                        'tipo' => 'estoque',
                        'titulo' => 'Saída de material • ' . $req['id'],
                        'texto' => count($req['linhas']) . ' item(ns) para ' . ($req['setor'] ?: 'setor não informado') . ' — ' . ($req['solicitante'] ?: 'solicitante não informado') . '.',
                        'autor' => $user,
                        'origem_device' => (string)($_SERVER['HTTP_X_CLIENT_ID'] ?? ''),
                    ]);
                }
                return ['requisicao' => $req];

            case 'estoque_funcionario_salvar':
                $f = est_funcionario_salvar($db, $body, $user);
                est_write($db);
                return ['funcionario' => $f];
            case 'estoque_funcionarios_importar':
                $r = est_funcionarios_importar($db, $body, $user, !empty($body['confirmo']));
                if (!empty($body['confirmo'])) { est_write($db); est_log_event('estoque', 'Lista de funcionários importada: ' . count($r['novos']) . ' novo(s)'); }
                return ['importacao' => $r];
            case 'estoque_importados_previa':
                return ['previa' => est_importados_carga($db, $body, $user, false)];
            case 'estoque_importados_carga':
                if (empty($body['confirmo'])) est_fail('Confirme a carga.');
                $r = est_importados_carga($db, $body, $user, true);
                est_write($db);
                est_log_event('estoque', 'Planilha de importados carregada: ' . $r['produtos'] . ' produtos, ' . $r['movimentos'] . ' movimentos');
                return ['carga' => $r];
            case 'estoque_importado_mov':
                $mv = est_importado_mov($db, $body, $user);
                est_write($db);
                est_log_event('estoque', 'Importado ' . $mv['codigo'] . ': ' . $mv['tipo'] . ' ' . est_num($mv['quantidade']) . (!empty($mv['obra']) ? ' · ' . $mv['obra'] : ''));
                return ['movimento' => $mv, 'item' => est_importados_view($db, $mv['codigo'])];
            case 'estoque_sku_unificar':
                $u = est_unificar_sku($db, $user);
                est_write($db);
                if ($u) est_log_event('estoque', 'SKU único: ' . count($u) . ' item(ns) ajustado(s)');
                return ['ajustados' => $u];
            case 'estoque_importado_mov_editar':
                $mv = est_importado_mov_editar($db, $body, $user);
                est_write($db);
                est_log_event('estoque', 'Importado ' . $mv['codigo'] . ': movimentação ' . $mv['id'] . (!empty($mv['cancelado']) ? ' excluída' : ' editada'));
                return ['movimento' => $mv, 'item' => est_importados_view($db, $mv['codigo'])];
            case 'estoque_importado_config':
                est_importado_config($db, $body);
                est_write($db);
                return ['item' => est_importados_view($db, (string)$body['codigo'])];
            case 'estoque_importado_foto':
                $f = est_importado_foto($db, $body);
                est_write($db);
                return ['foto' => $f];
            case 'estoque_sap_previa':
                return ['previa' => est_sap_conciliar($db, (array)($body['linhas'] ?? []), (array)($body['opcoes'] ?? []), $user, false)];

            case 'estoque_sap_importar':
                if (empty($body['confirmo'])) est_fail('Confirme a importação.');
                $r = est_sap_conciliar($db, (array)($body['linhas'] ?? []), (array)($body['opcoes'] ?? []), $user, true);
                est_write($db);
                est_log_event('estoque', 'Inventário SAP ' . $r['id'] . ' conciliado: ' . $r['totais']['linhas'] . ' itens, R$ ' . number_format($r['totais']['valor_final'], 2, ',', '.'));
                unset($r['linhas']);
                return ['importacao' => $r];

            case 'estoque_inventario_criar':
                $inv = est_inventario_criar($db, $body, $user);
                est_write($db);
                est_log_event('estoque', 'Contagem ' . $inv['id'] . ' iniciada (' . count($inv['itens']) . ' itens)');
                return ['inventario' => $inv];

            case 'estoque_inventario_contar':
                $inv = est_inventario_contar($db, $body, $user);
                est_write($db);
                return ['inventario' => $inv];

            case 'estoque_inventario_aplicar':
                $r = est_inventario_aplicar($db, (string)($body['id'] ?? ''), $user);
                est_write($db);
                est_log_event('estoque', 'Contagem ' . $r['inventario']['id'] . ' aplicada (' . $r['ajustados'] . ' ajuste(s))');
                return $r;

            case 'estoque_reservar':
                $res = est_reservar($db, $body, $user);
                est_write($db);
                return ['reserva' => $res];

            case 'estoque_reserva_cancelar':
                $res = est_reserva_cancelar($db, (string)($body['id'] ?? ''));
                est_write($db);
                return ['reserva' => $res];

            case 'estoque_transferir':
                $movs = est_transferir($db, $body, $user);
                est_write($db);
                est_log_event('estoque', count($movs) . ' item(ns) transferido(s) de endereço');
                return ['movimentos' => $movs];

            case 'estoque_baixa_perda':
                $movs = est_baixa_perda($db, $body, $user);
                est_write($db);
                est_log_event('estoque', 'Baixa por perda: ' . count($movs) . ' item(ns)');
                return ['movimentos' => $movs];

            case 'estoque_estorno':
                $mov = est_estorno($db, (string)($body['movimento_id'] ?? ''), (string)($body['motivo'] ?? ''), $user);
                est_write($db);
                est_log_event('estoque', 'Estorno ' . $mov['id'] . ' do movimento ' . $mov['movimento_origem']);
                return ['movimento' => $mov];

            default:
                est_fail('Ação de estoque inválida', 400);
        }
    });

    respond(['ok' => true] + $result);
}

/* ---------------- exportação CSV ---------------- */

function est_export_csv(array $db, $tipo) {
    $lines = [];
    if ($tipo === 'movimentos') {
        $lines[] = ['ID', 'DATA/HORA', 'TIPO', 'CÓDIGO', 'DESCRIÇÃO', 'UNIDADE', 'QUANTIDADE', 'SALDO ANTERIOR', 'SALDO POSTERIOR', 'PC', 'DOCUMENTO', 'NF', 'FORNECEDOR', 'SETOR', 'SOLICITANTE', 'OS', 'EQUIPAMENTO APLICADO', 'USUÁRIO', 'OBSERVAÇÃO'];
        foreach ($db['movimentos'] as $m) {
            $it = $db['itens'][$m['codigo']] ?? [];
            $lines[] = [$m['id'], $m['em'], EST_MOV_TIPOS[$m['tipo']] ?? $m['tipo'], $m['codigo'],
                $it['descricao'] ?? ($m['descricao_origem'] ?? ''), $it['unidade'] ?? 'UN',
                est_num($m['quantidade']), est_num($m['saldo_anterior'] ?? 0), est_num($m['saldo_posterior'] ?? 0),
                $m['pc'] ?? '', $m['documento'] ?? '', $m['numero_nf'] ?? '', $m['fornecedor'] ?? '',
                $m['setor'] ?? '', $m['solicitante'] ?? '', $m['os'] ?? '', $m['equipamento'] ?? '', $m['usuario'] ?? '', $m['observacao'] ?? ($m['motivo'] ?? '')];
        }
    } elseif ($tipo === 'entradas') {
        $lines[] = ['ENTRADA', 'PC', 'FORNECEDOR', 'NF', 'STATUS', 'CRIADO EM', 'RECEBIDO EM', 'LINHA', 'CÓDIGO', 'DESCRIÇÃO', 'UN', 'QTD PEDIDA', 'QTD CONFERIDA', 'QTD RECEBIDA', 'PENDENTE', 'DIVERGÊNCIA'];
        foreach ($db['recebimentos'] as $d) {
            foreach ($d['linhas'] as $l) {
                $lines[] = [$d['entrada_id'], $d['pc'], $d['fornecedor'] ?? '', $d['numero_nf'] ?? '', $d['status'] ?? '',
                    $d['criado_em'] ?? '', $d['recebido_em'] ?? '', $l['id'], $l['codigo'] ?? '', $l['descricao'], $l['unidade'],
                    est_num($l['quantidade']), est_num($l['quantidade_conferida'] ?? 0), est_num($l['quantidade_recebida'] ?? 0),
                    est_num($l['quantidade_pendente'] ?? 0), $l['divergencia_motivo'] ?? ''];
            }
        }
    } else {
        $lines[] = ['CÓDIGO', 'DESCRIÇÃO', 'UNIDADE', 'CATEGORIA', 'LOCALIZAÇÃO', 'SALDO', 'RESERVADO', 'DISPONÍVEL', 'MÍNIMO', 'STATUS', 'CUSTO MÉDIO', 'VALOR EM ESTOQUE', 'ÚLTIMA ENTRADA', 'ATUALIZADO EM'];
        foreach ($db['itens'] as $code => $it) {
            $v = est_item_view($it);
            $lines[] = [$code, $v['descricao'], $v['unidade'], $v['categoria'], $v['localizacao'],
                est_num($v['saldo']), est_num($v['reservado']), est_num($v['disponivel']), est_num($v['minimo']), $v['status'],
                number_format((float)$v['custo_medio'], 4, ',', '.'), number_format((float)$v['valor_estoque'], 2, ',', '.'),
                $v['ultima_entrada_em'] ?? '', $v['atualizado_em'] ?? ''];
        }
    }
    $csv = "\xEF\xBB\xBFsep=;\r\n";
    foreach ($lines as $row) {
        $csv .= implode(';', array_map(function ($c) {
            $s = (string)$c;
            if (preg_match('/^[=+\-@]/', $s)) $s = "'" . $s;
            return '"' . str_replace('"', '""', $s) . '"';
        }, $row)) . "\r\n";
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="estoque_' . $tipo . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

est_handle();
