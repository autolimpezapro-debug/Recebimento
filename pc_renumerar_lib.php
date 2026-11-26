<?php
/**
 * Recebimento Drilling — Corrigir número do PC (v3.59.1, só administrador).
 *
 * Usado quando um pedido foi lançado no número errado (ex.: balaclavas da NF 5593 lançadas no PC 5027,
 * que é da CDM). Leva o pedido INTEIRO para o número certo — recebimento, NF, fotos, XML, insumos — e
 * troca o número também em tudo que aponta para ele: entradas/movimentos do estoque, coletas, avisos de
 * chegada, notificações, protocolos de NF, Caixa de NFs, locações, obras, histórico de eventos.
 *
 * Regras: o número novo precisa estar LIVRE; "simular" mostra tudo o que mudaria sem gravar; antes de gravar
 * cada arquivo é copiado para data/retificacoes/ (dá para desfazer). Textos livres (ex.: "PC 5027" dentro de
 * uma mensagem) não são reescritos — ficam como registro do que aconteceu.
 * Chamado por api.php já com o lock de gravação dos pedidos (.write.lock).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const PCR_CHAVES = ['pc', 'numero_pc', 'pc_alvo', 'pc_nativo', 'pc_origem', 'pc_vinculado'];
const PCR_LISTAS = ['pcs', 'pedidos_pc', 'pcs_vinculados'];
/** Arquivos que NÃO são tocados (logs, sessões, segredos, cópias, retratos históricos). */
const PCR_FORA = '/(backup|antes|retific|sessoes|acessos_log|attempts|push-|gemini|lote_|permissoes|acesso_config|operational_users|motorista_acesso|_auth|whatsapp_import_history|_estado|db_sync)/i';

/** Troca o número nas chaves conhecidas. Devolve quantas trocas fez. */
function pcr_trocar(&$x, int $de, int $para, string $caminho, array &$onde): int {
    if (!is_array($x)) return 0;
    $n = 0; $renomear = [];
    foreach ($x as $k => &$v) {
        $ks = (string)$k;
        if (in_array($ks, PCR_CHAVES, true)) {
            if (is_int($v) && $v === $de) { $v = $para; $n++; $onde[] = "$caminho/$ks"; continue; }
            if (is_string($v) && trim($v) === (string)$de) { $v = (string)$para; $n++; $onde[] = "$caminho/$ks"; continue; }
            if (is_float($v) && (int)$v === $de && floor($v) == $v) { $v = $para; $n++; $onde[] = "$caminho/$ks"; continue; }
        }
        if (in_array($ks, PCR_LISTAS, true) && is_array($v) && array_keys($v) === range(0, count($v) - 1)) {
            foreach ($v as &$e) if ((is_int($e) && $e === $de) || (is_string($e) && trim($e) === (string)$de)) { $e = is_int($e) ? $para : (string)$para; $n++; $onde[] = "{$caminho}/{$ks}[]"; }
            unset($e);
        }
        if ($ks === 'url' && is_string($v) && preg_match('/([?&]pc=)' . $de . '(?!\d)/', $v)) { $v = preg_replace('/([?&]pc=)' . $de . '(?!\d)/', '${1}' . $para, $v); $n++; $onde[] = "$caminho/url"; }
        if ($ks === 'PC-' . $de) $renomear[] = $ks;
        $n += pcr_trocar($v, $de, $para, "$caminho/$ks", $onde);
    }
    unset($v);
    foreach ($renomear as $ks) {   // chave "PC-5027" → "PC-5047", mantendo a ordem
        $novo = [];
        foreach ($x as $k => $v) $novo[(string)$k === $ks ? 'PC-' . $para : $k] = $v;
        $x = $novo; $n++; $onde[] = "$caminho/[chave $ks]";
    }
    return $n;
}

/**
 * Trava os arquivos dos outros módulos (data/.estoque.lock, .coletas.lock, .nfmail.lock...) durante a correção,
 * para que uma gravação concorrente não se perca (nem desfaça a troca). Sem espera infinita: outro processo pode
 * estar com a trava do módulo e aguardando a dos pedidos (que já é nossa) — então tenta por ~8 s e desiste sem alterar nada.
 */
function pcr_travar_modulos(): void {
    $GLOBALS['PCR_TRAVAS'] = [];
    foreach (glob(DATA_DIR . '/.*.lock') ?: [] as $lf) {
        if (preg_match('/^\.(write|push|backup|permissoes|events|db_sync)\.lock$/', basename($lf))) continue;
        $fp = @fopen($lf, 'c'); if (!$fp) continue;
        $ok = false;
        for ($t = 0; $t < 80; $t++) { if (flock($fp, LOCK_EX | LOCK_NB)) { $ok = true; break; } usleep(100000); }
        if (!$ok) { fclose($fp); respond(['ok' => false, 'error' => 'Outro usuário está gravando (' . basename($lf) . '). Nada foi alterado — tente de novo em instantes.'], 503); }
        $GLOBALS['PCR_TRAVAS'][] = $fp;   // liberadas em pcr_soltar_modulos() ou no fim da requisição
    }
}
function pcr_soltar_modulos(): void {
    foreach ($GLOBALS['PCR_TRAVAS'] ?? [] as $fp) { @flock($fp, LOCK_UN); @fclose($fp); }
    $GLOBALS['PCR_TRAVAS'] = [];
}

function pcr_arquivos(): array {
    $out = [];
    foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
        $b = basename($f);
        if ($b === 'items.json' || preg_match(PCR_FORA, $b)) continue;
        if (filesize($f) > 40 * 1024 * 1024) continue;
        $out[] = $f;
    }
    return $out;
}

/**
 * @return array ['ok', 'simulacao', 'de', 'para', 'pedido' => resumo, 'arquivos' => [arquivo => [qtd, exemplos]], 'total']
 */
function pcr_executar(int $de, int $para, string $motivo, string $usuario, bool $simular): array {
    if ($de <= 0 || $para <= 0 || $para > 99999999) respond(['ok' => false, 'error' => 'Informe o número atual e o número correto do PC.'], 422);
    if ($de === $para) respond(['ok' => false, 'error' => 'Os dois números são iguais.'], 422);
    if (mb_strlen(trim($motivo)) < 8) respond(['ok' => false, 'error' => 'Explique o motivo da correção (mínimo 8 caracteres).'], 422);
    $items = read_items();
    $i = find_index($items, $de);
    if ($i < 0) respond(['ok' => false, 'error' => 'O PC ' . $de . ' não existe no sistema.'], 404);
    if (find_index($items, $para) >= 0) respond(['ok' => false, 'error' => 'O PC ' . $para . ' já existe no sistema. Escolha um número livre (ou corrija/exclua o ' . $para . ' antes).'], 409);

    $p = $items[$i];
    $resumo = ['fornecedor' => $p['fornecedor'] ?? '', 'material' => mb_substr((string)($p['material'] ?? ''), 0, 160), 'nf' => $p['numeroNF'] ?? '',
        'chegou' => $p['chegou'] ?? '', 'situacao' => $p['situacao'] ?? ''];
    $rel = ['items.json' => ['qtd' => 1, 'onde' => ['pedido ' . $de . ' → ' . $para]]];
    $grava = [];
    if (!$simular) pcr_travar_modulos();   // ninguém grava estoque/coletas/notas... entre a leitura e a gravação abaixo
    foreach (pcr_arquivos() as $f) {
        $raw = (string)@file_get_contents($f);
        if (strpos($raw, (string)$de) === false) continue;
        $d = json_decode($raw, true);
        if (!is_array($d)) continue;
        $onde = [];
        if (basename($f) === 'nfmail.json' && isset($d['notas']) && is_array($d['notas'])) {
            // Caixa de NFs: só acompanha o pedido a nota que é DELE (mesma chave). Nota de outro fornecedor que citou o
            // número (ex.: a NF verdadeira do PC antigo) fica onde está.
            $n = 0; $chaveItem = (string)($p['chaveNF'] ?? '');
            foreach ($d['notas'] as $nid => &$nota) {
                if ((int)($nota['pc'] ?? 0) === $de && ($nota['chave'] ?? '') !== '' && $nota['chave'] !== $chaveItem) continue;
                $n += pcr_trocar($nota, $de, $para, '/notas/' . $nid, $onde);
            }
            unset($nota);
        } else $n = pcr_trocar($d, $de, $para, '', $onde);
        if ($n) { $rel[basename($f)] = ['qtd' => $n, 'onde' => array_slice($onde, 0, 8)]; $grava[$f] = $d; }
    }
    $total = array_sum(array_column($rel, 'qtd'));
    if ($simular) return ['ok' => true, 'simulacao' => true, 'de' => $de, 'para' => $para, 'pedido' => $resumo, 'arquivos' => $rel, 'total' => $total];

    // cópia de segurança de tudo que vai mudar
    $dirBk = DATA_DIR . '/retificacoes/pc-' . $de . '-para-' . $para . '-' . date('Ymd-His');
    if (!@mkdir($dirBk, 0750, true) && !is_dir($dirBk)) respond(['ok' => false, 'error' => 'Não foi possível criar a cópia de segurança.'], 500);
    @file_put_contents(DATA_DIR . '/retificacoes/.htaccess', "Require all denied\nOptions -Indexes\n");
    if (!@copy(ITEMS_FILE, $dirBk . '/items.json')) respond(['ok' => false, 'error' => 'Não foi possível copiar items.json para a cópia de segurança.'], 500);
    foreach (array_keys($grava) as $f) if (!@copy($f, $dirBk . '/' . basename($f))) respond(['ok' => false, 'error' => 'Não foi possível copiar ' . basename($f) . ' — nada foi alterado.'], 500);

    // 1) os outros arquivos (cada um gravado de forma atômica)
    foreach ($grava as $f => $d) {
        $pretty = strpos(substr((string)@file_get_contents($f, false, null, 0, 64), 0, 64), "\n") !== false ? JSON_PRETTY_PRINT : 0;
        $tmp = $f . '.pcr.tmp';
        if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $pretty), LOCK_EX) === false || !@rename($tmp, $f)) {
            @unlink($tmp);
            respond(['ok' => false, 'error' => 'Falha ao gravar ' . basename($f) . '. Cópia dos arquivos originais em data/retificacoes/' . basename($dirBk) . '.'], 500);
        }
    }
    // 2) o pedido
    $items[$i]['pc'] = $para;
    $items[$i]['historico_retificacoes'] = array_values((array)($items[$i]['historico_retificacoes'] ?? []));
    // 'numero', 'feito_em', 'responsavel_declarado', 'antes' e 'depois' no mesmo formato das retificações de quantidade:
    // a tela do pedido lista este histórico (sem eles aparecia "Retificação undefined · data inválida").
    $chegouAtual = (string)($items[$i]['chegou'] ?? '');
    $items[$i]['historico_retificacoes'][] = ['em' => date('c'), 'por' => $usuario, 'tipo' => 'numero_pc', 'de' => $de, 'para' => $para, 'motivo' => 'Número do PC corrigido de ' . $de . ' para ' . $para . ' — ' . mb_substr(trim($motivo), 0, 500),
        'copia' => 'data/retificacoes/' . basename($dirBk),
        'numero' => count($items[$i]['historico_retificacoes']) + 1, 'feito_em' => date('c'), 'responsavel_declarado' => $usuario,
        'antes' => ['chegou' => $chegouAtual, 'pc' => $de], 'depois' => ['chegou' => $chegouAtual, 'pc' => $para]];
    mark_action($items[$i], 'Número do PC corrigido: ' . $de . ' → ' . $para);
    write_items($items);
    pcr_soltar_modulos();
    append_event($para, 'edicao', 'Número do PC corrigido de ' . $de . ' para ' . $para . ' por ' . $usuario . ' — ' . mb_substr(trim($motivo), 0, 200));
    @file_put_contents($dirBk . '/LEIA.txt', "Correção do número do PC $de para $para em " . date('d/m/Y H:i') . " por $usuario.\nMotivo: $motivo\n"
        . "Para desfazer: copie estes arquivos de volta para a pasta data/ (substituindo).\n\nAlterações:\n" . json_encode($rel, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return ['ok' => true, 'simulacao' => false, 'de' => $de, 'para' => $para, 'pedido' => $resumo, 'arquivos' => $rel, 'total' => $total, 'copia' => 'data/retificacoes/' . basename($dirBk),
        'mensagem' => 'PC ' . $de . ' corrigido para ' . $para . ' (' . $total . ' referência(s) atualizadas). O número ' . $de . ' ficou livre.'];
}
