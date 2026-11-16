<?php
/**
 * Recebimento Drilling — carga inicial (v3.10.2). Roda UMA vez, no primeiro acesso após a atualização.
 * Aplica o que foi entregue em data/carga_inicial/: inventário SAP, importados (planilha CONTROLE
 * IMPORTADOS), lista de funcionários e notas de remessa (indicadores). Cada parte só roda se ainda
 * não existir no sistema — nunca substitui o que já foi lançado. Antes, guarda cópia do estoque.
 * Resultado em data/carga_inicial/resultado.json.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

function rd_carga_inicial(): void {
    $dir = DATA_DIR . '/carga_inicial';
    $flag = $dir . '/.aplicada';
    if (!is_dir($dir) || is_file($flag)) return;
    // v3.20.3: proteção para RESTAURAÇÃO — se o estoque (vindo do backup/MySQL) já tem a carga, não aplica de novo.
    $estF = DATA_DIR . '/estoque.json';
    if (is_file($estF) && (strpos((string)file_get_contents($estF), '"carga-inicial"') !== false || is_file($dir . '/resultado.json'))) {
        @file_put_contents($flag, date('c') . " (carga já presente nos dados restaurados)\n");
        return;
    }
    $lk = @fopen($dir . '/.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;     // outra requisição já está aplicando
    $res = ['inicio' => date('c'), 'partes' => []];
    $ler = fn($n) => is_file("$dir/$n") ? json_decode((string)file_get_contents("$dir/$n"), true) : null;
    $GLOBALS['RD_SIDE_EFFECT'] = true;                       // erros viram exceção, não encerram a requisição
    try {
        require_once __DIR__ . '/estoque_lib.php';
        if (!is_dir(ESTOQUE_DIR)) @mkdir(ESTOQUE_DIR, 0750, true);
        if (is_file(ESTOQUE_FILE)) @copy(ESTOQUE_FILE, ESTOQUE_DIR . '/antes_carga_inicial_' . date('Ymd_His') . '.json');
        est_with_lock(function () use ($ler, &$res) {
            $db = est_read();
            // 0) SKU único: itens antigos com código provisório passam a usar o SKU
            try { $u = est_unificar_sku($db, 'carga-inicial'); $res['partes']['sku'] = ['ok' => true, 'ajustados' => $u]; }
            catch (Throwable $e) { $res['partes']['sku'] = ['ok' => false, 'erro' => $e->getMessage()]; }
            // 1) Inventário SAP (soma os recebimentos de PC ainda não conciliados, como combinado)
            $sap = $ler('sap.json');
            if ($sap && empty($db['inventarios_sap'])) {
                try { $r = est_sap_conciliar($db, $sap['linhas'], ['arquivo' => $sap['arquivo'], 'deposito' => $sap['deposito'], 'data_base' => $sap['data_base'], 'somar_pedidos' => true], 'carga-inicial', true);
                      $res['partes']['sap'] = ['ok' => true, 'itens' => $r['totais']['linhas'], 'valor' => $r['totais']['valor_final'], 'possivel_duplicidade' => $r['totais']['possivel_duplicidade']]; }
                catch (Throwable $e) { $res['partes']['sap'] = ['ok' => false, 'erro' => $e->getMessage()]; }
            } else $res['partes']['sap'] = ['ok' => true, 'ignorado' => 'já existe inventário SAP no sistema'];
            // 2) Importados (saldo = aba ESTOQUE da planilha)
            $imp = $ler('importados.json');
            if ($imp && empty($db['importados_carga'])) {
                try { $r = est_importados_carga($db, $imp + ['confirmo' => true], 'carga-inicial', true); $res['partes']['importados'] = ['ok' => true, 'produtos' => $r['produtos'], 'movimentos' => $r['movimentos']]; }
                catch (Throwable $e) { $res['partes']['importados'] = ['ok' => false, 'erro' => $e->getMessage()]; }
            } else $res['partes']['importados'] = ['ok' => true, 'ignorado' => 'importados já carregados'];
            // 3) Funcionários
            $fun = $ler('funcionarios.json');
            if ($fun && empty($db['funcionarios'])) {
                try { $r = est_funcionarios_importar($db, $fun, 'carga-inicial', true); $res['partes']['funcionarios'] = ['ok' => true, 'novos' => count($r['novos'])]; }
                catch (Throwable $e) { $res['partes']['funcionarios'] = ['ok' => false, 'erro' => $e->getMessage()]; }
            } else $res['partes']['funcionarios'] = ['ok' => true, 'ignorado' => 'já existem funcionários cadastrados'];
            est_write($db);
        });
        // 4) Notas de remessa → Indicadores (movimentação por obra)
        $rem = $ler('remessas.json');
        $kf = DATA_DIR . '/indicadores.json';
        $kb = is_file($kf) ? (json_decode((string)file_get_contents($kf), true) ?: []) : [];
        if ($rem && empty($kb['movimentos'])) {
            $kb['movimentos'] = $rem['movimentos'];
            $kb['historico'][] = ['em' => date('c'), 'op' => 'carga_inicial', 'antes' => 0, 'depois' => count($rem['movimentos']), 'usuario' => 'carga-inicial'];
            $kb['atualizado_em'] = date('c');
            @file_put_contents($kf . '.tmp', json_encode($kb, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            @rename($kf . '.tmp', $kf);
            $cf = DATA_DIR . '/indicadores_danfe.json';
            $cache = is_file($cf) ? (json_decode((string)file_get_contents($cf), true) ?: []) : [];
            @file_put_contents($cf, json_encode($cache + (array)($rem['danfe'] ?? []), JSON_UNESCAPED_UNICODE), LOCK_EX);
            $res['partes']['remessas'] = ['ok' => true, 'notas' => count($rem['movimentos'])];
        } else $res['partes']['remessas'] = ['ok' => true, 'ignorado' => $rem ? 'já existem remessas nos indicadores' : 'sem arquivo'];
    } catch (Throwable $e) {
        $res['erro_geral'] = $e->getMessage();
    } finally {
        $GLOBALS['RD_SIDE_EFFECT'] = false;
    }
    $res['fim'] = date('c');
    @file_put_contents($dir . '/resultado.json', json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (empty($res['erro_geral'])) @file_put_contents($flag, date('c'));
    if (function_exists('append_event')) @append_event(0, 'sistema', 'Carga inicial aplicada (SAP, importados, funcionários, remessas)');
    flock($lk, LOCK_UN); fclose($lk);
}

/**
 * v3.12.2 — cargas de locação: cada arquivo data/carga_inicial/locacoes_*.json roda UMA vez (flag própria)
 * e só se ainda não houver locação com o mesmo documento. Nunca altera locações existentes.
 */
function rd_carga_locacoes(): void {
    $dir = DATA_DIR . '/carga_inicial';
    $arqs = glob($dir . '/locacoes_*.json') ?: [];
    if (!$arqs) return;
    foreach ($arqs as $arq) {
        $flag = $dir . '/.' . basename($arq, '.json') . '.aplicada';
        if (is_file($flag)) continue;
        $c = json_decode((string)file_get_contents($arq), true);
        if (!is_array($c) || empty($c['locacoes'])) continue;
        $GLOBALS['RD_SIDE_EFFECT'] = true;
        try {
            require_once __DIR__ . '/locacoes_lib.php';
            $res = loc_with_lock(function () use ($c) {
                $d = loc_read();
                foreach ($d['locacoes'] as $l) if (($l['contrato'] ?? '') === $c['documento'] || (($l['carga_documento'] ?? '') === $c['documento'])) return ['ignorado' => 'documento já lançado'];
                $ids = [];
                // substitui um lançamento anterior incompleto (só o que a própria carga criou)
                if (!empty($c['substituir_documento'])) {
                    foreach ($d['locacoes'] as $l) if (($l['contrato'] ?? '') === $c['substituir_documento'] && ($l['criado_por'] ?? '') === 'carga-nf')
                        loc_excluir($d, ['id' => $l['id']], 'carga-nf (substituído por ' . $c['documento'] . ')');
                }
                foreach ($c['locacoes'] as $x) {
                    // v3.35.0: 'casar_contrato' — se a locação JÁ existe no sistema (mesmo contrato e fornecedor, ainda ativa), encerra e anexa nela em vez de criar outra.
                    $l = null;
                    if (!empty($x['casar_contrato'])) foreach ($d['locacoes'] as $ex) {
                        if (($ex['contrato'] ?? '') === $x['casar_contrato'] && ($ex['status'] ?? '') === 'ATIVA' && stripos((string)($ex['fornecedor'] ?? ''), (string)($x['casar_fornecedor'] ?? '')) !== false) { $l = $ex; break; }
                    }
                    if (!$l) $l = loc_salvar($d, $x, 'carga-nf');
                    if (!empty($x['fim']) || !empty($x['devolvido'])) $l = loc_devolver($d, ['id' => $l['id'], 'data' => $x['fim'] ?? '', 'nf_devolucao' => $x['nf_devolucao'] ?? '', 'obs' => 'Devolvido (conforme ' . $c['documento'] . ')'], 'carga-nf');
                    // v3.35.0: documentos (PDF/foto) que vêm junto com a carga, em data/carga_inicial/docs/
                    foreach ((array)($x['documentos'] ?? []) as $doc) {
                        $orig = DATA_DIR . '/carga_inicial/docs/' . basename((string)($doc['arquivo'] ?? ''));
                        foreach ($d['locacoes'] as &$ll) if ($ll['id'] === $l['id']) { loc_doc_importar($ll, $orig, (string)($doc['nome'] ?? basename($orig)), (string)($doc['tipo'] ?? 'OUTRO'), 'carga-nf'); break; } unset($ll);
                    }
                    foreach ($d['locacoes'] as &$ll) if ($ll['id'] === $l['id']) $ll['carga_documento'] = $c['documento']; unset($ll);
                    $ids[] = $l['id'];
                }
                loc_write($d);
                return ['lancadas' => count($ids), 'ids' => $ids];
            });
            @file_put_contents($flag, json_encode(['em' => date('c')] + $res, JSON_UNESCAPED_UNICODE));
            if (function_exists('append_event')) @append_event(0, 'estoque', 'Locações carregadas da ' . $c['documento'] . ' (' . ($res['lancadas'] ?? 0) . ')');
        } catch (Throwable $e) {
            @file_put_contents($dir . '/' . basename($arq, '.json') . '_erro.txt', date('c') . ' ' . $e->getMessage());
        } finally {
            $GLOBALS['RD_SIDE_EFFECT'] = false;
        }
    }
}

/**
 * v3.20.4: recuperação de pedidos perdidos em 12/11/2026 (entre 12:37 e 13:53 a base de pedidos voltou a uma
 * versão antiga). Para cada PC do arquivo: se não existe no servidor → inclui; se o do servidor é MAIS ANTIGO
 * (updated_at menor) → substitui. Pedido alterado depois no servidor é mantido. Roda uma única vez.
 */
function rd_recuperar_pedidos(): void {
    $dir = DATA_DIR . '/carga_inicial';
    foreach (glob($dir . '/recuperar_pedidos_*.json') ?: [] as $arq) {
        $flag = $dir . '/.' . basename($arq, '.json') . '.aplicada';
        if (is_file($flag) || !is_file(ITEMS_FILE)) continue;
        $fp = @fopen(DATA_DIR . '/.write.lock', 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) return;
        try {
            $rec = json_decode((string)file_get_contents($arq), true);
            $items = read_items(); $feitos = [];
            foreach ((array)($rec['pedidos'] ?? []) as $p) {
                $idx = find_index($items, (int)$p['pc']);
                if ($idx < 0) { $items[] = $p; $feitos[] = $p['pc'] . ' incluído'; }
                elseif (empty($rec['somente_novos']) && strcmp((string)($items[$idx]['updated_at'] ?? ''), (string)($p['updated_at'] ?? '')) < 0) { $items[$idx] = $p; $feitos[] = $p['pc'] . ' atualizado'; }
            }
            if ($feitos) {
                write_items($items);
                if (function_exists('append_event')) @append_event(0, 'edicao', 'Recuperação de pedidos (' . basename($arq) . '): ' . implode(', ', $feitos));
            }
            @file_put_contents($flag, json_encode(['em' => date('c'), 'feitos' => $feitos], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) { error_log('recuperar pedidos: ' . $e->getMessage()); }
        flock($fp, LOCK_UN);
    }
}
