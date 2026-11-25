<?php
/**
 * Recebimento Drilling — Controle de obras (v3.28.0)
 *
 * Cada obra guarda os ENVIOS de material: nota de remessa / romaneio (PDF ou foto) + fotos do carregamento.
 * Mostra também, só leitura, o que o sistema já registrou para a obra (saídas do estoque, Importados,
 * ferramentas emprestadas e equipamentos locados).
 *
 * Dados: data/obras_envios.json · Arquivos: data/obras/ (bloqueado para acesso direto; servido pela API com login).
 * Nada é apagado de verdade: excluir um envio só o marca como excluído.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/rd_obras.php';
require_once __DIR__ . '/espelho_lib.php';   // v3.60.0: busca de remessa (Espelho de NF / Romaneio) → materiais do envio

const OB_FILE = DATA_DIR . '/obras_envios.json';
const OB_LOCK = DATA_DIR . '/.obras.lock';
const OB_DIR  = DATA_DIR . '/obras';
const OB_MAX_ARQ = 15 * 1024 * 1024;
const OB_TIPOS = ['NF_REMESSA' => 'NF de remessa', 'ROMANEIO' => 'Romaneio', 'NF_VENDA' => 'NF de venda', 'DECLARACAO' => 'Declaração de conteúdo', 'OUTRO' => 'Outro'];

function ob_fail(string $m, int $c = 422) { respond(['ok' => false, 'error' => $m], $c); }
function ob_now(): string { return date('c'); }
function ob_str($v, int $max = 200): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); }

function ob_read(): array {
    $j = is_file(OB_FILE) ? json_decode((string)@file_get_contents(OB_FILE), true) : null;
    if (is_file(OB_FILE) && !is_array($j)) ob_fail('Falha ao ler data/obras_envios.json — o arquivo foi preservado. Restaure pelo backup.', 500);
    return ($j ?: []) + ['envios' => [], 'obras_extras' => [], 'seq' => 0];
}
function ob_write(array $d): void {
    $tmp = OB_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, OB_FILE))
        ob_fail('Não foi possível gravar o controle de obras.', 500);
    @chmod(OB_FILE, 0640);
}
function ob_with_lock(callable $fn) {
    $fp = @fopen(OB_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) ob_fail('Controle de obras ocupado, tente de novo.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function ob_dir(): void {
    if (!is_dir(OB_DIR)) @mkdir(OB_DIR, 0750, true);
    if (!is_file(OB_DIR . '/.htaccess')) @file_put_contents(OB_DIR . '/.htaccess', "Require all denied\nOptions -Indexes\n");
}

/** Todas as obras: cadastro + NFs + obras criadas aqui + obras que só aparecem em envios. */
function ob_obras(array $d): array {
    $l = [];
    foreach (rd_obras_lista() as $o) if ($o['id'] !== 'deposito') $l[$o['id']] = ['id' => $o['id'], 'nome' => $o['name'], 'cidade' => $o['city'] ?? ''];
    foreach ($d['obras_extras'] as $o) if (!isset($l[$o['id']])) $l[$o['id']] = $o;
    foreach ($d['envios'] as $e) if (empty($e['excluido']) && !isset($l[$e['obra_id']])) $l[$e['obra_id']] = ['id' => $e['obra_id'], 'nome' => $e['obra_nome'], 'cidade' => ''];
    return $l;
}
function ob_envio_view(array $e): array {
    $e['tipo_label'] = OB_TIPOS[$e['tipo'] ?? ''] ?? 'Outro';
    foreach (['notas', 'fotos'] as $k) $e[$k] = array_values(array_map(fn($a, $i) => $a + ['idx' => $i, 'url' => 'api.php?action=obras_arquivo&envio=' . rawurlencode($e['id']) . '&lista=' . $k . '&i=' . $i], $e[$k] ?? [], array_keys($e[$k] ?? [])));
    return $e;
}

function ob_painel(array $d): array {
    $obras = ob_obras($d); $cont = [];
    foreach ($d['envios'] as $e) {
        if (!empty($e['excluido'])) continue;
        $c = &$cont[$e['obra_id']];
        $c['envios'] = ($c['envios'] ?? 0) + 1;
        $c['notas'] = ($c['notas'] ?? 0) + count($e['notas'] ?? []);
        $c['fotos'] = ($c['fotos'] ?? 0) + count($e['fotos'] ?? []);
        $c['valor'] = ($c['valor'] ?? 0) + (float)($e['valor'] ?? 0);
        if (($e['data'] ?? '') > ($c['ultimo'] ?? '')) $c['ultimo'] = $e['data'];
        unset($c);
    }
    $lista = [];
    foreach ($obras as $id => $o) $lista[] = $o + ($cont[$id] ?? []) + ['envios' => 0, 'notas' => 0, 'fotos' => 0, 'valor' => 0, 'ultimo' => null];
    usort($lista, fn($a, $b) => [($b['ultimo'] ?? ''), $a['nome']] <=> [($a['ultimo'] ?? ''), $b['nome']]);
    $t = ['obras_com_envio' => count($cont), 'envios' => array_sum(array_column($cont, 'envios')), 'notas' => array_sum(array_column($cont, 'notas')),
          'fotos' => array_sum(array_column($cont, 'fotos')), 'valor' => round(array_sum(array_column($cont, 'valor')), 2)];
    return ['obras' => $lista, 'totais' => $t, 'tipos' => OB_TIPOS];
}

/** Palavras que identificam a obra nos lançamentos de outros módulos. */
function ob_chaves(array $o): array {
    $gen = ['PONTE', 'OBRA', 'ENGENHARIA', 'CONSTRUTORA', 'TERMINAL', 'MALL', 'CORP', 'SHOPPING', 'EDIFICIO', 'JARDINS', 'GRANDE'];
    $k = [];
    foreach (array_merge([$o['nome']], rd_obras_apelidos()[$o['id']] ?? [], explode('-', $o['id'])) as $txt)
        foreach (preg_split('/\s+/', trim(rd_txt_chave((string)$txt))) as $w) if (strlen($w) >= 4 && !in_array($w, $gen, true) && !ctype_digit($w)) $k[$w] = 1;
    foreach (rd_obras_apelidos()[$o['id']] ?? [] as $ap) { $ap = trim(rd_txt_chave($ap)); if (strlen($ap) >= 3) $k[$ap] = 1; }
    return array_keys($k);
}
function ob_bate(string $txt, array $chaves): bool {
    $t = rd_txt_chave($txt); if (trim($t) === '') return false;
    foreach ($chaves as $k) if (str_contains($t, ' ' . $k . ' ')) return true;
    return false;
}

function ob_obra(array $d, string $id): array {
    $obras = ob_obras($d);
    if (!isset($obras[$id])) ob_fail('Obra não encontrada.', 404);
    $o = $obras[$id];
    $envios = array_values(array_map('ob_envio_view', array_filter($d['envios'], fn($e) => $e['obra_id'] === $id && empty($e['excluido']))));
    usort($envios, fn($a, $b) => strcmp($b['data'] . $b['criado_em'], $a['data'] . $a['criado_em']));
    // O que o sistema já registrou para a obra (somente leitura)
    $ch = ob_chaves($o); $sis = ['estoque' => [], 'ferramentas' => [], 'locacoes' => []];
    $est = json_decode((string)@file_get_contents(DATA_DIR . '/estoque.json'), true) ?: [];
    foreach (array_reverse($est['movimentos'] ?? []) as $m) {
        if (($m['tipo'] ?? '') !== 'SAIDA' || !empty($m['cancelado'])) continue;
        if (!ob_bate(($m['obra'] ?? '') . ' ' . ($m['setor'] ?? ''), $ch)) continue;
        $it = $est['itens'][$m['codigo']] ?? [];
        $sis['estoque'][] = ['em' => $m['em'], 'codigo' => $m['codigo'], 'descricao' => $it['nome_planilha'] ?? $it['descricao'] ?? $m['descricao_origem'] ?? '', 'quantidade' => (float)$m['quantidade'],
            'unidade' => $it['unidade'] ?? '', 'documento' => $m['documento'] ?? '', 'solicitante' => $m['solicitante'] ?? '', 'os' => $m['os'] ?? '', 'importado' => !empty($it['importado'])];
        if (count($sis['estoque']) >= 300) break;
    }
    $fer = json_decode((string)@file_get_contents(DATA_DIR . '/ferramentas.json'), true) ?: [];
    foreach ((array)($fer['emprestimos'] ?? []) as $e) {
        if (!ob_bate((string)($e['obra'] ?? ''), $ch)) continue;
        $sis['ferramentas'][] = ['id' => $e['id'], 'em' => $e['em'] ?? '', 'status' => $e['status'] ?? '', 'responsavel' => $e['responsavel'] ?? ($e['assinatura']['nome'] ?? ''),
            'itens' => array_map(fn($i) => ($i['qtd'] ?? 1) . '× ' . ($i['nome'] ?? $i['ferramenta_id'] ?? ''), (array)($e['itens'] ?? []))];
    }
    $loc = json_decode((string)@file_get_contents(DATA_DIR . '/locacoes.json'), true) ?: [];
    foreach ((array)($loc['locacoes'] ?? []) as $l) {
        if (($l['status'] ?? '') === 'DEVOLVIDO' || !ob_bate((string)($l['local_atual'] ?? ''), $ch)) continue;
        $sis['locacoes'][] = ['equipamento' => $l['equipamento'] ?? '', 'patrimonio' => $l['patrimonio'] ?? '', 'fornecedor' => $l['fornecedor'] ?? '', 'desde' => $l['inicio'] ?? ''];
    }
    return ['obra' => $o, 'envios' => $envios, 'sistema' => $sis, 'chaves' => $ch, 'tipos' => OB_TIPOS];
}

/** v3.60.0 — acha a remessa pelo número e devolve TODOS os materiais dela, para o envio à obra. Só leitura: não mexe no estoque.
 *  Aceita: nº da nota emitida no Espelho de NF (ex.: 13685), código do espelho (ESP-2026-0003) ou do romaneio de terceiros (ROM-0005). */
function ob_remessa_info(array $d, string $numero, string $ignorar = ''): array {
    $q = mb_strtoupper(trim($numero)); $out = ['numero' => $numero, 'status' => 'nao_encontrado', 'mensagem' => 'Remessa "' . $numero . '" não encontrada.', 'remessas' => []];
    if ($q === '') { $out['mensagem'] = 'Digite o número da remessa.'; return $out; }
    $zero = fn($x) => ltrim(preg_replace('/\D/', '', (string)$x), '0');
    $usada = function (string $ref) use ($d, $ignorar) { $l = []; foreach ($d['envios'] as $e) if (empty($e['excluido']) && ($e['remessa_ref'] ?? '') === $ref && ($e['id'] ?? '') !== $ignorar) $l[] = ['id' => $e['id'], 'data' => $e['data'] ?? '', 'obra_nome' => $e['obra_nome'] ?? '']; return $l; };
    $nome = fn(array $i) => ['sku' => (string)($i['sku'] ?? ''), 'descricao' => (string)$i['descricao'], 'unidade' => (string)($i['unidade'] ?? 'UN'), 'quantidade' => (float)$i['quantidade'], 'valor_total' => (float)($i['valor_total'] ?? 0)];
    foreach (esp_read()['espelhos'] as $r) {
        if (($r['status'] ?? '') === 'CANCELADO') continue;
        $porNf = ($r['nf_numero'] ?? '') !== '' && $zero($r['nf_numero']) !== '' && $zero($r['nf_numero']) === $zero($q) && !preg_match('/^(ESP|ROM)/', $q);
        if (!$porNf && mb_strtoupper((string)$r['id']) !== $q) continue;
        $it = array_map($nome, (array)$r['itens']);
        $out['remessas'][] = ['ref' => $r['id'], 'origem' => 'Espelho de NF', 'tipo' => 'NF_REMESSA', 'numero' => ($r['nf_numero'] ?? '') ?: $r['id'], 'status' => $r['status'], 'status_label' => ESP_STATUS[$r['status']] ?? $r['status'],
            'data' => ($r['nf_data'] ?? '') ?: ($r['data'] ?? ''), 'destino' => (string)($r['destinatario'] ?? ''), 'obra_id' => (string)($r['obra_id'] ?? ''), 'obs' => (string)($r['obs'] ?? ''),
            'itens' => $it, 'total' => round(array_sum(array_column($it, 'valor_total')), 2), 'ja_aplicada' => $usada((string)$r['id'])];
    }
    if (preg_match('/^ROM-?\d+$/', $q)) {
        $id = 'ROM-' . str_pad($zero($q) ?: '0', 4, '0', STR_PAD_LEFT);
        foreach (rom_read()['romaneios'] as $r) if ($r['id'] === $id && ($r['status'] ?? '') !== 'CANCELADO') {
            $out['remessas'][] = ['ref' => $r['id'], 'origem' => 'Romaneio', 'tipo' => 'ROMANEIO', 'numero' => $r['id'], 'status' => $r['status'], 'status_label' => ROM_STATUS[$r['status']] ?? $r['status'],
                'data' => (string)($r['data'] ?? ''), 'destino' => (string)($r['obra_nome'] ?? ''), 'obra_id' => (string)($r['obra_id'] ?? ''), 'obs' => (string)($r['obs'] ?? ''),
                'itens' => array_map($nome, (array)$r['itens']), 'total' => 0.0, 'ja_aplicada' => $usada((string)$r['id'])];
        }
    }
    if ($out['remessas']) { $out['status'] = 'ok'; $out['mensagem'] = ''; }
    elseif (preg_match('/^\d+$/', $q)) $out['mensagem'] = 'Nenhuma nota de remessa com o número ' . $numero . ' no Espelho de NF. Confira se o espelho foi marcado como "Nota emitida". Para romaneio, digite ROM-0001.';
    return $out;
}

function ob_obra_nova(array &$d, array $in): array {
    $nome = ob_str($in['nome'] ?? '', 120);
    if (mb_strlen($nome) < 3) ob_fail('Informe o nome da obra.');
    foreach (ob_obras($d) as $o) if (trim(rd_txt_chave($o['nome'])) === trim(rd_txt_chave($nome))) ob_fail('Já existe a obra "' . $o['nome'] . '".', 409);
    $id = 'ob-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: 'obra')), '-') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    $o = ['id' => $id, 'nome' => $nome, 'cidade' => ob_str($in['cidade'] ?? '', 80)];
    $d['obras_extras'][] = $o;
    return $o;
}

/**
 * v3.33.0: dados de um PC para preencher o envio à obra. SOMENTE LEITURA — o envio à obra nunca baixa estoque
 * (a baixa é feita na Saída de material; aqui só se registra nota/fotos/itens, para não duplicar).
 * "ja_enviada" = soma do que já foi lançado em envios (não excluídos) com este PC; $ignorar = envio em edição.
 */
function ob_pc_info(array $d, int $pc, string $ignorar = ''): array {
    $out = ['pc' => $pc, 'status' => 'nao_encontrado', 'mensagem' => 'PC ' . $pc . ' não encontrado.', 'linhas' => [], 'fotos' => [], 'envios' => []];
    if ($pc <= 0 || !function_exists('read_items')) return $out;
    $it = null; foreach (read_items() as $p) if ((int)($p['pc'] ?? 0) === $pc) { $it = $p; break; }
    if (!$it) return $out;
    $env = []; $detalhe = [];
    foreach ($d['envios'] as $e) {
        if ((int)($e['pc'] ?? 0) !== $pc || !empty($e['excluido']) || ($e['id'] ?? '') === $ignorar) continue;
        $detalhe[] = ['id' => $e['id'], 'data' => $e['data'] ?? '', 'obra_nome' => $e['obra_nome'] ?? ''];
        foreach ((array)($e['linhas_pc'] ?? []) as $l) $env[(string)$l['linha_id']] = ($env[(string)$l['linha_id']] ?? 0) + (float)$l['quantidade'];
    }
    $insumos = array_values(array_filter((array)($it['insumos'] ?? []), 'is_array'));
    $algumRecebido = false; foreach ($insumos as $l) if ((float)($l['quantidade_recebida'] ?? 0) > 0) { $algumRecebido = true; break; }
    foreach ($insumos as $l) {
        $lid = (string)($l['id'] ?? ''); $ped = (float)($l['quantidade_pedida'] ?? 0); $rec = (float)($l['quantidade_recebida'] ?? 0);
        $base = $algumRecebido ? $rec : $ped;          // PC ainda sem recebimento: usa o pedido (com aviso na tela)
        $ja = round((float)($env[$lid] ?? 0), 4);
        $out['linhas'][] = ['linha_id' => $lid, 'sku' => (string)($l['sku'] ?? ''), 'descricao' => (string)($l['descricao'] ?? ''), 'unidade' => (string)($l['unidade'] ?? 'UN'),
            'pedida' => $ped, 'recebida' => $rec, 'base' => $base, 'ja_enviada' => $ja, 'disponivel' => round(max(0, $base - $ja), 4)];
    }
    foreach ((array)($it['fotosProdutos'] ?? []) as $f) {
        $f = (string)$f;
        if (preg_match('~^data/photos/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$~i', $f) && is_file(dirname(DATA_DIR) . '/' . $f)) $out['fotos'][] = $f;
    }
    $out += ['fornecedor' => (string)($it['fornecedor'] ?? ''), 'solicitante' => (string)($it['solicitante'] ?? ''), 'numero_nf' => (string)($it['numeroNF'] ?? ''),
        'destino' => ['setor_codigo' => (string)($it['destino_setor'] ?? ''), 'obra_id' => (string)($it['obra_id'] ?? ''), 'obra' => (string)($it['obra'] ?? '')],
        'os' => (string)($it['os_pc'] ?? ''), 'equipamento' => (string)($it['equipamento_pc'] ?? ''), 'recebido' => $algumRecebido];
    $out['envios'] = $detalhe;
    $out['status'] = $insumos ? ($algumRecebido ? 'ok' : 'sem_recebimento') : 'sem_linhas';
    $out['mensagem'] = $out['status'] === 'ok' ? '' : ($out['status'] === 'sem_recebimento' ? 'Este PC ainda não teve material recebido: mostrando as quantidades PEDIDAS.' : 'Este PC não tem itens cadastrados.');
    return $out;
}

function ob_envio_salvar(array &$d, array $in, string $usuario): array {
    $obras = ob_obras($d);
    $oid = (string)($in['obra_id'] ?? '');
    if (!isset($obras[$oid])) ob_fail('Escolha a obra.');
    $data = (string)($in['data'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) ob_fail('Informe a data do envio.');
    $id = (string)($in['id'] ?? '');
    if ($id !== '' && !isset($d['envios'][$id])) ob_fail('Envio não encontrado.', 404);
    // v3.33.0: origem em pedido de compra (opcional). Só registra — NÃO movimenta estoque.
    $pc = max(0, (int)($in['pc'] ?? 0)); $linhasPc = []; $infoPc = null;
    if ($pc > 0) {
        $infoPc = ob_pc_info($d, $pc, $id);
        if ($infoPc['status'] === 'nao_encontrado') ob_fail('PC ' . $pc . ' não encontrado.');
        $mapa = []; foreach ($infoPc['linhas'] as $l) $mapa[$l['linha_id']] = $l;
        foreach (array_slice((array)($in['linhas_pc'] ?? []), 0, 150) as $l) {
            if (!is_array($l)) continue;
            $lid = (string)($l['linha_id'] ?? ''); $q = round((float)str_replace(',', '.', (string)($l['quantidade'] ?? 0)), 4);
            if (!isset($mapa[$lid]) || $q <= 0 || $q > 1000000) continue;
            $linhasPc[] = ['linha_id' => $lid, 'sku' => $mapa[$lid]['sku'], 'descricao' => $mapa[$lid]['descricao'], 'unidade' => $mapa[$lid]['unidade'], 'quantidade' => $q];
        }
    }
    if ($id === '') {
        $d['seq'] = (int)$d['seq'] + 1;
        $id = 'ENV-' . date('Y') . '-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT);
        $d['envios'][$id] = ['id' => $id, 'criado_em' => ob_now(), 'criado_por' => $usuario, 'notas' => [], 'fotos' => [], 'historico' => []];
    }
    $e = &$d['envios'][$id];
    $valor = str_replace(',', '.', preg_replace('/\.(?=\d{3}(\D|$))/', '', (string)($in['valor'] ?? '')));
    $e = array_merge($e, [
        'obra_id' => $oid, 'obra_nome' => $obras[$oid]['nome'], 'data' => $data,
        'tipo' => isset(OB_TIPOS[$in['tipo'] ?? '']) ? $in['tipo'] : 'NF_REMESSA',
        'numero' => ob_str($in['numero'] ?? '', 40), 'valor' => is_numeric($valor) ? round((float)$valor, 2) : 0,
        'transporte' => ob_str($in['transporte'] ?? '', 120), 'responsavel' => ob_str($in['responsavel'] ?? '', 120),
        'itens' => mb_substr(trim((string)($in['itens'] ?? '')), 0, 4000), 'observacao' => ob_str($in['observacao'] ?? '', 600),
        'atualizado_em' => ob_now(), 'atualizado_por' => $usuario,
        'pc' => $pc, 'linhas_pc' => $linhasPc, 'pc_fornecedor' => $infoPc ? $infoPc['fornecedor'] : '',
    ]);
    // v3.60.0: remessa escolhida (os materiais vêm do próprio sistema, nunca do navegador)
    $ref = ob_str($in['remessa_ref'] ?? '', 30);
    if ($ref !== '') {
        $rm = ob_remessa_info($d, $ref, $id)['remessas'][0] ?? null;
        if (!$rm) ob_fail('Remessa ' . $ref . ' não encontrada.');
        $e['remessa_ref'] = $rm['ref']; $e['remessa_itens'] = $rm['itens'];
    } else { unset($e['remessa_ref'], $e['remessa_itens']); }
    // v3.33.0: fotos do recebimento do PC escolhidas pelo operador viram fotos do envio (cópia; o original continua no PC).
    if ($infoPc) {
        foreach (array_slice((array)($in['fotos_pc'] ?? []), 0, 10) as $rel) {
            $rel = (string)$rel;
            if (!in_array($rel, $infoPc['fotos'], true) || in_array($rel, (array)($e['fotos_pc_copiadas'] ?? []), true) || count($e['fotos']) >= 30) continue;
            ob_dir();
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION)); $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            $nome = preg_replace('/[^A-Za-z0-9-]/', '', $id) . '-f-' . bin2hex(random_bytes(5)) . '.' . $ext;
            if (!@copy(dirname(DATA_DIR) . '/' . $rel, OB_DIR . '/' . $nome)) continue;
            @chmod(OB_DIR . '/' . $nome, 0640);
            $e['fotos'][] = ['arquivo' => $nome, 'nome' => 'Recebimento PC ' . $pc . ' - ' . basename($rel), 'mime' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg',
                'bytes' => (int)@filesize(OB_DIR . '/' . $nome), 'em' => ob_now(), 'por' => $usuario, 'origem' => 'recebimento PC ' . $pc];
            $e['fotos_pc_copiadas'][] = $rel;
        }
    }
    $e['historico'][] = ['em' => ob_now(), 'por' => $usuario, 'acao' => count($e['historico']) ? 'editado' : 'criado'];
    $r = ob_envio_view($e); unset($e);
    return $r;
}

/** Recebe um arquivo (multipart, campo "arquivo") para a lista "notas" ou "fotos". */
function ob_arquivo_add(array &$d, string $envio, string $lista, string $usuario): array {
    if (!isset($d['envios'][$envio]) || !empty($d['envios'][$envio]['excluido'])) ob_fail('Envio não encontrado.', 404);
    if (!in_array($lista, ['notas', 'fotos'], true)) ob_fail('Lista inválida.');
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) ob_fail(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Arquivo maior que o limite do servidor.' : 'Arquivo não recebido.');
    if ($f['size'] > OB_MAX_ARQ) ob_fail('Arquivo maior que 15 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) ob_fail('Formato não aceito (' . $mime . '). Use PDF, JPG, PNG ou WEBP.');
    if ($lista === 'fotos' && $ext === 'pdf') ob_fail('Em fotos, envie imagens. O PDF vai em "Notas".');
    if (count($d['envios'][$envio][$lista] ?? []) >= 30) ob_fail('Limite de 30 arquivos por lista neste envio.');
    ob_dir();
    $nome = preg_replace('/[^A-Za-z0-9-]/', '', $envio) . '-' . substr($lista, 0, 1) . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], OB_DIR . '/' . $nome)) ob_fail('Não foi possível gravar o arquivo.', 500);
    @chmod(OB_DIR . '/' . $nome, 0640);
    $d['envios'][$envio][$lista][] = ['arquivo' => $nome, 'nome' => ob_str($f['name'] ?? $nome, 120), 'mime' => $mime, 'bytes' => (int)$f['size'], 'em' => ob_now(), 'por' => $usuario];
    $d['envios'][$envio]['atualizado_em'] = ob_now();
    return ob_envio_view($d['envios'][$envio]);
}
function ob_arquivo_remover(array &$d, array $in, string $usuario): array {
    $id = (string)($in['envio'] ?? ''); $lista = (string)($in['lista'] ?? ''); $i = (int)($in['i'] ?? -1);
    if (!isset($d['envios'][$id])) ob_fail('Envio não encontrado.', 404);
    if (!in_array($lista, ['notas', 'fotos'], true) || !isset($d['envios'][$id][$lista][$i])) ob_fail('Arquivo não encontrado.', 404);
    $a = $d['envios'][$id][$lista][$i];
    array_splice($d['envios'][$id][$lista], $i, 1);
    $d['envios'][$id]['removidos'][] = $a + ['lista' => $lista, 'removido_em' => ob_now(), 'removido_por' => $usuario];   // arquivo fica no servidor
    $d['envios'][$id]['historico'][] = ['em' => ob_now(), 'por' => $usuario, 'acao' => 'arquivo removido: ' . $a['nome']];
    return ob_envio_view($d['envios'][$id]);
}
function ob_envio_excluir(array &$d, array $in, string $usuario): array {
    $id = (string)($in['id'] ?? '');
    if (!isset($d['envios'][$id])) ob_fail('Envio não encontrado.', 404);
    $motivo = ob_str($in['motivo'] ?? '', 200);
    if (mb_strlen($motivo) < 3) ob_fail('Informe o motivo da exclusão.');
    $d['envios'][$id]['excluido'] = ['em' => ob_now(), 'por' => $usuario, 'motivo' => $motivo];
    return ['excluido' => $id];
}

/** Entrega o arquivo (só para quem está logado e pode ver o módulo). */
function ob_servir(array $d): void {
    $e = $d['envios'][(string)($_GET['envio'] ?? '')] ?? null;
    $lista = (string)($_GET['lista'] ?? ''); $i = (int)($_GET['i'] ?? -1);
    $a = ($e && in_array($lista, ['notas', 'fotos'], true)) ? ($e[$lista][$i] ?? null) : null;
    if (!$a) { http_response_code(404); exit('Arquivo não encontrado'); }
    $p = OB_DIR . '/' . basename($a['arquivo']);
    if (!is_file($p)) { http_response_code(404); exit('Arquivo não encontrado no servidor'); }
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . (!empty($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $a['nome']) . '"');
    readfile($p); exit;
}
