<?php
/**
 * Recebimento Drilling — API do módulo Etiquetas e Identificação (v3.6.0)
 * Chamada por api.php?action=etiquetas_*  (mesma origem, sessão e CSRF dos demais módulos).
 *
 * GET   etiquetas_config            permissões do usuário, almoxarifados, padrões
 *       etiquetas_buscar&q=         pesquisa no catálogo OFICIAL (+ materiais de pedidos ainda não cadastrados)
 *       etiquetas_sku&sku=          resolve um SKU (descrição oficial)
 *       etiquetas_fila              fila do usuário (servidor)
 *       etiquetas_jobs              histórico de impressões  | etiquetas_job&id= (detalhe)
 *       etiquetas_historico         trilha de auditoria
 * POST  etiquetas_fila_salvar       {itens[], rev}
 *       etiquetas_adicionar         {skus:[{sku,copias}], almoxarifado, data}
 *       etiquetas_importar_previa   {arquivo, linhas[]}  → classifica, NÃO grava
 *       etiquetas_importar_confirmar{token, cadastrar_novos, atualizar:{sku:[campos]}, enviar_fila, copias,...}
 *       etiquetas_material_salvar   {sku, descricao, unidade, categoria}  (cadastrar)
 *       etiquetas_registrar_impressao {tipo, escopo, ids[]|itens[]}
 *       etiquetas_reimprimir        {job}  → recoloca os itens do trabalho na fila
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';
require_once __DIR__ . '/etiquetas_lib.php';

function etq_user() {
    if (function_exists('rd_login_info')) { $u = rd_login_info(); if (is_array($u) && !empty($u['username'])) return (string)$u['username']; }
    return 'operador-local';
}
function etq_role() {
    if (function_exists('rd_login_info')) { $u = rd_login_info(); if (is_array($u) && !empty($u['role'])) return (string)$u['role']; }
    return 'gestor';
}
function etq_can($perm) {
    // v3.7.0: a matriz de permissões manda quando o login está ativo; senão vale o papel.
    $mapa = ['visualizar' => ['ver', ['gestor', 'conferente', 'consulta', 'importador']],
        'pesquisar' => ['listar', ['gestor', 'conferente', 'consulta', 'importador']],
        'historico' => ['historico', ['gestor', 'conferente', 'consulta']],
        'gerar' => ['criar', ['gestor', 'conferente']], 'editar' => ['editar', ['gestor', 'conferente']],
        'fila' => ['editar', ['gestor', 'conferente']], 'imprimir' => ['imprimir', ['gestor', 'conferente']],
        'exportar_pdf' => ['exportar', ['gestor', 'conferente']], 'importar' => ['criar', ['gestor', 'importador']],
        'cadastrar' => ['admin', ['gestor']], 'atualizar_cadastro' => ['admin', ['gestor']]];
    [$op, $legado] = $mapa[$perm] ?? ['ver', ['gestor']];
    return rd_perm_or_legado('etiquetas', $op, $legado);
}
function etq_need($perm) {
    if (!etq_can($perm)) etq_fail('Seu perfil não permite esta operação (' . $perm . ').', 403);
}
function etq_body($max = 4194304) {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > $max) etq_fail('Corpo da requisição muito grande.', 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) etq_fail('Corpo JSON inválido.', 400);
    return $b;
}
function etq_almoxarifados(array $db, array $etq) {
    $s = ['DRILLING' => true];
    foreach (($db['itens'] ?? []) as $it) { $l = etq_up($it['localizacao'] ?? '', 40); if ($l !== '') $s[$l] = true; }
    foreach (($etq['filas'] ?? []) as $f) foreach ((array)($f['itens'] ?? []) as $e) { $a = $e['almoxarifado'] ?? ''; if ($a !== '') $s[$a] = true; }
    ksort($s);
    return array_slice(array_keys($s), 0, 60);
}
function etq_marcar_oficial(array $db, array $etiquetas) {
    [$idx] = etq_catalog_index($db);
    foreach ($etiquetas as &$e) $e['oficial'] = isset($idx[etq_sku($e['insumo'] ?? '')]);
    return $etiquetas;
}

function etq_handle() {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = etq_user();

    /* ------------------------- leitura ------------------------- */
    if ($method === 'GET') {
        etq_need('visualizar');
        switch ($action) {
            case 'etiquetas_config': {
                $perms = []; foreach (array_keys(ETQ_PERMS) as $p) $perms[$p] = etq_can($p);
                $db = est_read(); $etq = etq_read();
                [$idx, $dup] = etq_catalog_index($db);
                respond(['ok' => true, 'versao' => '3.6.0', 'usuario' => $user, 'perfil' => etq_role(), 'permissoes' => $perms,
                    'almoxarifados' => etq_almoxarifados($db, $etq), 'catalogo_total' => count($db['itens'] ?? []),
                    'skus_ambiguos' => count($dup), 'hoje' => date('d/m/Y'), 'max_fila' => ETQ_MAX_FILA]);
            }
            case 'etiquetas_buscar': {
                etq_need('pesquisar');
                respond(['ok' => true, 'itens' => etq_buscar(est_read(), (string)($_GET['q'] ?? ''), min(200, max(1, (int)($_GET['limite'] ?? 60))))]);
            }
            case 'etiquetas_sku': {
                etq_need('pesquisar');
                $db = est_read(); $sku = etq_sku($_GET['sku'] ?? '');
                $code = etq_find_official($db, $sku);
                if ($code !== null) respond(['ok' => true, 'encontrado' => true, 'item' => etq_official_view($code, $db['itens'][$code], $sku)]);
                $cand = etq_pedido_candidates($db);
                respond(['ok' => true, 'encontrado' => false, 'candidato_pedido' => $cand[$sku] ?? null,
                    'pode_cadastrar' => etq_can('cadastrar'), 'sku' => $sku]);
            }
            case 'etiquetas_fila': {
                $f = etq_fila_get(etq_read(), $user);
                respond(['ok' => true, 'rev' => $f['rev'], 'itens' => etq_marcar_oficial(est_read(), $f['itens']), 'atualizado_em' => $f['atualizado_em']]);
            }
            case 'etiquetas_jobs': {
                etq_need('historico');
                $etq = etq_read(); $jobs = array_reverse($etq['jobs']);
                if (etq_role() !== 'gestor') $jobs = array_values(array_filter($jobs, fn($j) => ($j['usuario'] ?? '') === $user));
                respond(['ok' => true, 'jobs' => array_map('etq_job_resumo', array_slice($jobs, 0, 100)), 'importacoes' => array_slice(array_reverse($etq['importacoes']), 0, 30)]);
            }
            case 'etiquetas_job': {
                etq_need('historico');
                $id = preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['id'] ?? ''));
                foreach (etq_read()['jobs'] as $j) if ($j['id'] === $id) {
                    if (etq_role() !== 'gestor' && ($j['usuario'] ?? '') !== $user) etq_fail('Trabalho de outro usuário.', 403);
                    respond(['ok' => true, 'job' => $j]);
                }
                etq_fail('Trabalho não encontrado.', 404);
            }
            case 'etiquetas_historico': {
                etq_need('historico');
                respond(['ok' => true, 'historico' => array_slice(array_reverse(etq_read()['historico']), 0, 300)]);
            }
        }
        etq_fail('Ação de etiquetas inválida.', 400);
    }

    if ($method !== 'POST') etq_fail('Método não permitido.', 405);
    if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid()) etq_fail('Origem não autorizada.', 403);
    $body = etq_body();

    /* ------------------------- escrita ------------------------- */
    switch ($action) {

        case 'etiquetas_fila_salvar': {
            etq_need('fila');
            $itens = $body['itens'] ?? null;
            if (!is_array($itens)) etq_fail('Lista de etiquetas inválida.');
            $r = etq_with_lock(function () use ($itens, $body, $user) {
                $etq = etq_read();
                $f = etq_fila_set($etq, $user, $itens, $body['rev'] ?? null);
                etq_write($etq);
                return $f;
            });
            respond(['ok' => true, 'rev' => $r['rev'], 'itens' => $r['itens']]);
        }

        case 'etiquetas_adicionar': {
            etq_need('gerar');
            $lista = $body['skus'] ?? null;
            if (!is_array($lista) || !$lista) etq_fail('Selecione ao menos um material.');
            $almox = etq_up($body['almoxarifado'] ?? '', 40) ?: 'DRILLING';
            $data = '';   // a data é carimbada no dia da impressão
            $db = est_read(); [$idx] = etq_catalog_index($db);
            $cands = etq_pedido_candidates($db);
            $novas = []; $faltam = [];
            foreach ($lista as $x) {
                $sku = etq_sku(is_array($x) ? ($x['sku'] ?? '') : $x);
                $copias = max(1, min(60, (int)(is_array($x) ? ($x['copias'] ?? 1) : 1)));
                if (isset($idx[$sku])) { $desc = $db['itens'][$idx[$sku]]['descricao']; $of = true; }
                elseif (isset($cands[$sku])) { $desc = $cands[$sku]['descricao']; $of = false; }
                else { $faltam[] = $sku; continue; }
                for ($i = 0; $i < $copias; $i++) $novas[] = ['id' => etq_id(), 'almoxarifado' => $almox, 'item' => $desc, 'insumo' => $sku, 'data' => $data, 'oficial' => $of];
            }
            if (!$novas) etq_fail('Nenhum SKU encontrado no catálogo oficial ou nos pedidos: ' . implode(', ', $faltam));
            $r = etq_with_lock(function () use ($novas, $user) {
                $etq = etq_read(); $f = etq_fila_get($etq, $user);
                $f = etq_fila_set($etq, $user, array_merge($f['itens'], $novas), null);
                etq_hist($etq, $user, 'fila_adicionar', count($novas) . ' etiqueta(s) adicionada(s) à fila');
                etq_write($etq);
                return $f;
            });
            respond(['ok' => true, 'adicionadas' => count($novas), 'sem_cadastro' => $faltam, 'rev' => $r['rev'], 'itens' => etq_marcar_oficial($db, $r['itens'])]);
        }

        case 'etiquetas_material_salvar': {
            etq_need('cadastrar');
            $resp = etq_with_lock(function () use ($body, $user) {
                return est_with_lock(function () use ($body, $user) {
                    $db = est_read();
                    $sku = etq_sku($body['sku'] ?? '');
                    $code = etq_criar_item($db, $sku, $body['descricao'] ?? '', strtoupper(etq_str($body['unidade'] ?? 'UN', 12)), etq_str($body['categoria'] ?? '', 80),
                        in_array(($body['origem'] ?? ''), ['PEDIDO_COMPRA', 'CADASTRO_MANUAL'], true) ? $body['origem'] : 'CADASTRO_MANUAL', $user);
                    est_write($db);
                    $etq = etq_read();
                    etq_hist($etq, $user, 'material_cadastrado', 'Material ' . $sku . ' cadastrado no catálogo oficial (' . $db['itens'][$code]['origem_cadastro'] . ')');
                    etq_write($etq);
                    return etq_official_view($code, $db['itens'][$code], $sku);
                });
            });
            respond(['ok' => true, 'item' => $resp]);
        }

        case 'etiquetas_importar_previa': {
            etq_need('importar');
            $linhas = $body['linhas'] ?? null;
            if (!is_array($linhas)) etq_fail('Nenhuma linha recebida.');
            if (count($linhas) > ETQ_IMPORT_MAX_LINHAS) etq_fail('Planilha acima de ' . ETQ_IMPORT_MAX_LINHAS . ' linhas. Divida o arquivo.');
            $arquivo = etq_str($body['arquivo'] ?? 'planilha', 160);
            $res = etq_classificar_import(est_read(), $linhas);
            // Guarda a prévia no servidor: a confirmação usa ESTE resultado, não dados reenviados.
            $token = bin2hex(random_bytes(16));
            $dir = DATA_DIR . '/imports';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            foreach (glob($dir . '/etq_*.json') ?: [] as $f) if (@filemtime($f) < time() - 3600) @unlink($f);
            file_put_contents($dir . '/etq_' . $token . '.json', json_encode(['usuario' => $user, 'arquivo' => $arquivo, 'em' => time(), 'res' => $res, 'hash' => hash('sha256', json_encode($linhas))], JSON_UNESCAPED_UNICODE), LOCK_EX);
            respond(['ok' => true, 'token' => $token, 'arquivo' => $arquivo, 'totais' => [
                'linhas' => count($linhas), 'novos' => count($res['novos']), 'existentes' => count($res['existentes']),
                'divergentes' => count($res['divergentes']), 'duplicados' => count($res['duplicados']),
                'invalidos' => count($res['invalidos']), 'vazias' => $res['vazias']],
                'novos' => array_slice($res['novos'], 0, 500), 'existentes' => array_slice($res['existentes'], 0, 300),
                'divergentes' => array_slice($res['divergentes'], 0, 500), 'duplicados' => array_slice($res['duplicados'], 0, 300),
                'invalidos' => array_slice($res['invalidos'], 0, 300),
                'pode_cadastrar' => etq_can('cadastrar'), 'pode_atualizar' => etq_can('atualizar_cadastro')]);
        }

        case 'etiquetas_importar_confirmar': {
            etq_need('importar');
            $token = (string)($body['token'] ?? '');
            if (!preg_match('/^[0-9a-f]{32}$/', $token)) etq_fail('Prévia inválida ou expirada.', 422);
            $path = DATA_DIR . '/imports/etq_' . $token . '.json';
            if (!is_file($path) || filemtime($path) < time() - 3600) etq_fail('Prévia expirada. Envie a planilha novamente.', 410);
            $prev = json_decode((string)file_get_contents($path), true);
            if (!is_array($prev) || ($prev['usuario'] ?? '') !== $user) etq_fail('Prévia não pertence a este usuário.', 403);
            $res = $prev['res'];
            $cadastrar = !empty($body['cadastrar_novos']);
            $atualizar = is_array($body['atualizar'] ?? null) ? $body['atualizar'] : [];   // sku => [campos]
            $skusNovos = is_array($body['skus_novos'] ?? null) ? array_map('etq_sku', $body['skus_novos']) : null;
            if ($cadastrar) etq_need('cadastrar');
            if ($atualizar) etq_need('atualizar_cadastro');
            $enviarFila = !empty($body['enviar_fila']);
            if ($enviarFila) etq_need('gerar');
            $copias = max(1, min(60, (int)($body['copias'] ?? 1)));
            $almox = etq_up($body['almoxarifado'] ?? '', 40) ?: 'DRILLING'; $data = '';

            $out = etq_with_lock(function () use ($res, $cadastrar, $atualizar, $skusNovos, $enviarFila, $copias, $almox, $data, $user, $prev, $path) {
                return est_with_lock(function () use ($res, $cadastrar, $atualizar, $skusNovos, $enviarFila, $copias, $almox, $data, $user, $prev, $path) {
                    $db = est_read();
                    $cad = 0; $atu = 0; $ign = 0; $etiq = [];
                    $backup = $db;   // transação: qualquer erro descarta tudo (nada é gravado antes de est_write)
                    foreach ($res['novos'] as $r) {
                        if (!$cadastrar || ($skusNovos !== null && !in_array($r['sku'], $skusNovos, true))) { $ign++; continue; }
                        [$idx] = etq_catalog_index($db);
                        if (isset($idx[$r['sku']])) { $ign++; continue; }     // idempotência: já criado em outra execução
                        etq_criar_item($db, $r['sku'], $r['descricao'], $r['unidade'] ?: 'UN', $r['categoria'], 'IMPORTACAO_PLANILHA', $user);
                        $cad++;
                    }
                    foreach ($res['divergentes'] as $r) {
                        $campos = $atualizar[$r['sku']] ?? [];
                        if (!is_array($campos) || !$campos) continue;
                        $apply = [];
                        foreach ($campos as $c) if (in_array($c, $r['diferencas'], true)) $apply[$c] = $r[$c];
                        if ($apply && etq_atualizar_item($db, $r['codigo'], $apply, $user)) $atu++;
                    }
                    if ($cad || $atu) est_write($db);
                    [$idx] = etq_catalog_index($db);
                    if ($enviarFila) {
                        foreach (array_merge($res['novos'], $res['existentes'], $res['divergentes']) as $r) {
                            if (!isset($idx[$r['sku']])) continue;                       // só materiais que existem no catálogo oficial
                            $d = $db['itens'][$idx[$r['sku']]]['descricao'];             // etiqueta usa a descrição OFICIAL
                            for ($i = 0; $i < $copias; $i++) $etiq[] = ['id' => etq_id(), 'almoxarifado' => $almox, 'item' => $d, 'insumo' => $r['sku'], 'data' => $data, 'oficial' => true];
                        }
                    }
                    $etq = etq_read();
                    if ($etiq) { $f = etq_fila_get($etq, $user); etq_fila_set($etq, $user, array_merge($f['itens'], $etiq), null); }
                    $etq['sequencia']['import'] = (int)($etq['sequencia']['import'] ?? 0) + 1;
                    $reg = ['id' => 'IMP-' . date('Y') . '-' . str_pad((string)$etq['sequencia']['import'], 4, '0', STR_PAD_LEFT), 'em' => etq_now(), 'usuario' => $user,
                        'arquivo' => $prev['arquivo'], 'hash' => $prev['hash'], 'cadastrados' => $cad, 'atualizados' => $atu, 'ignorados' => $ign,
                        'duplicados' => count($res['duplicados']), 'invalidos' => count($res['invalidos']), 'etiquetas_na_fila' => count($etiq)];
                    $etq['importacoes'][] = $reg;
                    $etq['importacoes'] = array_slice($etq['importacoes'], -200);
                    etq_hist($etq, $user, 'importacao', 'Planilha "' . $prev['arquivo'] . '": ' . $cad . ' cadastrado(s), ' . $atu . ' atualizado(s), ' . count($etiq) . ' etiqueta(s) na fila');
                    etq_write($etq);
                    @unlink($path);
                    return $reg;
                });
            });
            respond(['ok' => true, 'resultado' => $out]);
        }

        case 'etiquetas_registrar_impressao': {
            etq_need('imprimir');
            $tipo = (string)($body['tipo'] ?? 'impressao');
            if ($tipo === 'pdf') etq_need('exportar_pdf');
            $ids = is_array($body['ids'] ?? null) ? array_flip(array_map('strval', $body['ids'])) : null;
            $r = etq_with_lock(function () use ($body, $tipo, $ids, $user) {
                $etq = etq_read(); $f = etq_fila_get($etq, $user);
                $sel = $ids === null ? $f['itens'] : array_values(array_filter($f['itens'], fn($e) => isset($ids[$e['id']])));
                if (!$sel) etq_fail('Nenhuma etiqueta para registrar.');
                $job = etq_registrar_job($etq, $user, $tipo, $sel, is_array($body['escopo'] ?? null) ? $body['escopo'] : [], (string)($body['origem_job'] ?? ''));
                etq_hist($etq, $user, $tipo, $job['id'] . ': ' . $job['total'] . ' etiqueta(s) em ' . $job['folhas'] . ' folha(s)');
                etq_write($etq);
                return $job;
            });
            respond(['ok' => true, 'job' => etq_job_resumo($r)]);
        }

        case 'etiquetas_reimprimir': {
            etq_need('imprimir'); etq_need('fila');
            $id = preg_replace('/[^A-Za-z0-9-]/', '', (string)($body['job'] ?? ''));
            $r = etq_with_lock(function () use ($id, $user) {
                $etq = etq_read(); $job = null;
                foreach ($etq['jobs'] as $j) if ($j['id'] === $id) $job = $j;
                if (!$job) etq_fail('Trabalho não encontrado.', 404);
                if (etq_role() !== 'gestor' && $job['usuario'] !== $user) etq_fail('Trabalho de outro usuário.', 403);
                $itens = array_map(fn($e) => ['id' => etq_id()] + $e, $job['itens']);
                $f = etq_fila_get($etq, $user);
                $f = etq_fila_set($etq, $user, array_merge($f['itens'], $itens), null);
                etq_hist($etq, $user, 'reimpressao', 'Trabalho ' . $id . ' recolocado na fila (' . count($itens) . ' etiqueta(s))');
                etq_write($etq);
                return [$f, count($itens), !$job['snapshot_completo']];
            });
            respond(['ok' => true, 'rev' => $r[0]['rev'], 'itens' => $r[0]['itens'], 'recolocadas' => $r[1], 'parcial' => $r[2]]);
        }
    }
    etq_fail('Ação de etiquetas inválida.', 400);
}

etq_handle();
