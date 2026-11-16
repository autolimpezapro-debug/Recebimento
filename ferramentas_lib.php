<?php
/**
 * Recebimento Drilling — Empréstimo de ferramentas (v3.10.0)
 *
 * data/ferramentas.json (gravação atômica + trava própria). Usa o cadastro de funcionários
 * e a assinatura do Estoque. Ferramentas podem ser individuais (patrimônio/série, qtd 1)
 * ou por quantidade (ex.: 10 marretas). Disponível = total − emprestado − manutenção.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';
require_once __DIR__ . '/rd_obras.php';

const FER_FILE = DATA_DIR . '/ferramentas.json';
const FER_LOCK = DATA_DIR . '/.ferramentas.lock';
const FER_ESTADOS_DEV = ['OK' => 'Em bom estado', 'DANIFICADA' => 'Danificada (vai para manutenção)', 'INCOMPLETA' => 'Incompleta / faltando acessório', 'EXTRAVIADA' => 'Extraviada / perdida'];

function fer_fail($m, $c = 422) { respond(['ok' => false, 'error' => $m], $c); }
function fer_str($v, $n = 200) { return mb_substr(trim(str_replace("\xC2\xA0", ' ', (string)$v)), 0, $n); }
function fer_now() { return date('c'); }

function fer_read() {
    if (!is_file(FER_FILE)) return ['versao' => 1, 'seq' => ['fer' => 0, 'emp' => 0], 'ferramentas' => [], 'emprestimos' => []];
    $d = json_decode((string)file_get_contents(FER_FILE), true);
    if (!is_array($d)) fer_fail('Falha ao ler data/ferramentas.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + ['ferramentas' => [], 'emprestimos' => [], 'seq' => ['fer' => 0, 'emp' => 0]];
}
function fer_write(array $d) {
    $tmp = FER_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) fer_fail('Não foi possível gravar as ferramentas.', 500);
    if (is_file(FER_FILE)) @copy(FER_FILE, FER_FILE . '.backup.json');
    if (!rename($tmp, FER_FILE)) fer_fail('Falha ao persistir as ferramentas.', 500);
}
function fer_with_lock(callable $fn) {
    $fp = @fopen(FER_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) fer_fail('Não foi possível bloquear as ferramentas para gravação.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/** Quantidade emprestada (aberta) por ferramenta. */
function fer_emprestado(array $d) {
    $q = [];
    foreach ($d['emprestimos'] as $e) {
        if (($e['status'] ?? '') === 'DEVOLVIDO') continue;
        foreach ($e['itens'] as $i) $q[$i['ferramenta_id']] = ($q[$i['ferramenta_id']] ?? 0) + max(0, $i['qtd'] - $i['devolvido']);
    }
    return $q;
}

function fer_view(array $f, array $emp) {
    $em = (int)($emp[$f['id']] ?? 0);
    $man = (int)($f['manutencao'] ?? 0);
    $f['fotos'] = fer_fotos($f);
    $f['emprestado'] = $em;
    $f['disponivel'] = max(0, (int)$f['quantidade'] - $em - $man);
    $f['situacao'] = !$f['ativo'] ? 'BAIXADA' : ($f['disponivel'] > 0 ? ($em || $man ? 'PARCIAL' : 'DISPONÍVEL') : ($em ? 'EMPRESTADA' : ($man ? 'MANUTENÇÃO' : 'SEM SALDO')));
    return $f;
}

function fer_emp_view(array $e) {
    $hoje = date('Y-m-d');
    $e['atrasado'] = $e['status'] !== 'DEVOLVIDO' && !empty($e['previsto']) && $e['previsto'] < $hoje;
    $e['dias_atraso'] = $e['atrasado'] ? (int)floor((strtotime($hoje) - strtotime($e['previsto'])) / 86400) : 0;
    $e['pendentes'] = array_sum(array_map(fn($i) => max(0, $i['qtd'] - $i['devolvido']), $e['itens']));
    return $e;
}

function fer_painel(array $d) {
    $emp = fer_emprestado($d);
    $fs = array_map(fn($f) => fer_view($f, $emp), array_values($d['ferramentas']));
    usort($fs, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    $abertos = [];
    foreach ($d['emprestimos'] as $e) if ($e['status'] !== 'DEVOLVIDO') $abertos[] = fer_emp_view($e);
    usort($abertos, fn($a, $b) => [!$a['atrasado'], $a['previsto'] ?: '9999'] <=> [!$b['atrasado'], $b['previsto'] ?: '9999']);
    $ativos = array_filter($fs, fn($f) => $f['ativo']);
    $k = ['ferramentas' => count($ativos), 'unidades' => array_sum(array_column($ativos, 'quantidade')),
          'disponiveis' => array_sum(array_column($ativos, 'disponivel')), 'emprestadas' => array_sum(array_column($ativos, 'emprestado')),
          'manutencao' => array_sum(array_map(fn($f) => (int)($f['manutencao'] ?? 0), $ativos)),
          'emprestimos_abertos' => count($abertos), 'atrasados' => count(array_filter($abertos, fn($e) => $e['atrasado'])),
          'valor' => round(array_sum(array_map(fn($f) => (float)$f['valor'] * (int)$f['quantidade'], $ativos)), 2)];
    $cats = array_values(array_unique(array_filter(array_column($fs, 'categoria'))));
    sort($cats);
    // emprestadas agora por setor / obra
    $porSetor = [];
    foreach ($abertos as $e) {
        $rot = $e['obra'] ?: 'Sem setor';
        $porSetor[$rot] = $porSetor[$rot] ?? ['destino' => $rot, 'setor' => $e['setor'] ?? '', 'emprestimos' => 0, 'unidades' => 0];
        $porSetor[$rot]['emprestimos']++;
        foreach ($e['itens'] as $i) $porSetor[$rot]['unidades'] += max(0, $i['qtd'] - $i['devolvido']);
    }
    usort($porSetor, fn($a, $b) => $b['unidades'] <=> $a['unidades']);
    return ['kpis' => $k, 'ferramentas' => $fs, 'abertos' => $abertos, 'categorias' => $cats,
            'setores' => RD_SETORES, 'obras' => rd_obras_lista(), 'por_setor' => array_values($porSetor)];
}

function fer_salvar(array &$d, array $in, $usuario) {
    $nome = fer_str($in['nome'] ?? '', 180);
    if (mb_strlen($nome) < 3) fer_fail('Informe o nome da ferramenta.');
    $id = (string)($in['id'] ?? '');
    $codigo = strtoupper(fer_str($in['codigo'] ?? '', 40));
    foreach ($d['ferramentas'] as $k => $f) if ($k !== $id && $codigo !== '' && strtoupper($f['codigo']) === $codigo) fer_fail('Já existe ferramenta com o código ' . $codigo . '.', 409);
    $qtd = (int)($in['quantidade'] ?? 1);
    if ($qtd < 1 || $qtd > 100000) fer_fail('Quantidade inválida.');
    $novo = $id === '' || !isset($d['ferramentas'][$id]);
    if ($novo) {
        $d['seq']['fer'] = (int)($d['seq']['fer'] ?? 0) + 1;
        $id = 'FER-' . str_pad((string)$d['seq']['fer'], 4, '0', STR_PAD_LEFT);
        $d['ferramentas'][$id] = ['id' => $id, 'criado_em' => fer_now(), 'criado_por' => $usuario, 'ativo' => true, 'manutencao' => 0, 'historico' => [], 'foto' => ''];
    } else {
        $emp = fer_emprestado($d);
        if ($qtd < (int)($emp[$id] ?? 0) + (int)($d['ferramentas'][$id]['manutencao'] ?? 0)) fer_fail('A quantidade não pode ser menor que o que está emprestado + em manutenção.');
    }
    $f = &$d['ferramentas'][$id];
    $f['codigo'] = $codigo !== '' ? $codigo : $id;
    $f['nome'] = $nome;
    foreach (['categoria' => 80, 'marca' => 80, 'modelo' => 80, 'serie' => 80, 'patrimonio' => 80, 'localizacao' => 120, 'observacao' => 400] as $c => $n) $f[$c] = fer_str($in[$c] ?? ($f[$c] ?? ''), $n);
    $f['unidade'] = strtoupper(fer_str($in['unidade'] ?? ($f['unidade'] ?? 'UN'), 8)) ?: 'UN';
    $f['quantidade'] = $qtd;
    $f['valor'] = round(max(0, (float)str_replace(',', '.', (string)($in['valor'] ?? ($f['valor'] ?? 0)))), 2);
    $f['atualizado_em'] = fer_now();
    $f['historico'][] = ['em' => fer_now(), 'por' => $usuario, 'acao' => $novo ? 'cadastrada' : 'editada'];
    $f['historico'] = array_slice($f['historico'], -100);
    $r = $f; unset($f);
    return $r;
}

/** Importação de planilha (prévia ou gravação). Casa pelo código; sem código, pelo nome+série. */
function fer_importar(array &$d, array $in, $usuario, $aplicar) {
    $linhas = [];
    foreach (array_slice((array)($in['linhas'] ?? []), 0, 5000) as $l) {
        $nome = fer_str($l['nome'] ?? '', 180);
        if (mb_strlen($nome) < 3) continue;
        $linhas[] = ['codigo' => strtoupper(fer_str($l['codigo'] ?? '', 40)), 'nome' => $nome, 'categoria' => fer_str($l['categoria'] ?? '', 80),
            'marca' => fer_str($l['marca'] ?? '', 80), 'modelo' => fer_str($l['modelo'] ?? '', 80), 'serie' => fer_str($l['serie'] ?? '', 80),
            'patrimonio' => fer_str($l['patrimonio'] ?? '', 80), 'quantidade' => max(1, (int)est_num_br($l['quantidade'] ?? 1)),
            'localizacao' => fer_str($l['localizacao'] ?? '', 120), 'valor' => est_num_br($l['valor'] ?? 0)];
    }
    if (!$linhas) fer_fail('Nenhuma ferramenta reconhecida. A planilha precisa de uma coluna com o nome/descrição.');
    $idx = [];
    foreach ($d['ferramentas'] as $id => $f) { $idx['c:' . strtoupper($f['codigo'])] = $id; $idx['n:' . est_norm_key($f['nome'] . ' ' . $f['serie'])] = $id; }
    $novos = 0; $atual = 0;
    foreach ($linhas as &$l) {
        $l['existe'] = $idx['c:' . $l['codigo']] ?? ($l['codigo'] === '' ? ($idx['n:' . est_norm_key($l['nome'] . ' ' . $l['serie'])] ?? null) : null);
        $l['existe'] ? $atual++ : $novos++;
    }
    unset($l);
    $res = ['linhas' => count($linhas), 'novas' => $novos, 'atualizadas' => $atual, 'amostra' => array_slice($linhas, 0, 300)];
    if (!$aplicar) return $res;
    foreach ($linhas as $l) {
        $base = $l['existe'] ? $d['ferramentas'][$l['existe']] : [];
        fer_salvar($d, array_merge($base, array_filter($l, fn($v) => $v !== '' && $v !== null), ['id' => $l['existe'] ?? '']), $usuario);
    }
    return $res;
}

/** Empréstimo: funcionário do cadastro (ou terceiro com documento) assina na tela. */
function fer_emprestar(array &$d, array $in, $usuario) {
    $itens = [];
    $emp = fer_emprestado($d);
    foreach ((array)($in['itens'] ?? []) as $i) {
        $fid = (string)($i['ferramenta_id'] ?? '');
        $q = (int)($i['qtd'] ?? 1);
        $f = $d['ferramentas'][$fid] ?? null;
        if (!$f || empty($f['ativo'])) fer_fail('Ferramenta não encontrada ou baixada.');
        if ($q < 1) fer_fail('Quantidade inválida em ' . $f['nome']);
        $disp = (int)$f['quantidade'] - (int)($emp[$fid] ?? 0) - (int)($f['manutencao'] ?? 0) - (int)($itens[$fid]['qtd'] ?? 0);
        if ($q > $disp) fer_fail('Indisponível: ' . $f['nome'] . ' (disponível ' . max(0, $disp) . ').', 409);
        $itens[$fid] = ['ferramenta_id' => $fid, 'codigo' => $f['codigo'], 'nome' => $f['nome'], 'qtd' => $q + (int)($itens[$fid]['qtd'] ?? 0), 'devolvido' => 0, 'devolucoes' => []];
    }
    if (!$itens) fer_fail('Escolha pelo menos uma ferramenta.');
    [$setor, $obraId, $obra] = rd_setor_destino($in['setor'] ?? '', $in['obra_id'] ?? '', $in['obra_nome'] ?? '');
    if ($setor === '') fer_fail('Informe o setor de destino (Obra, Caldeiraria, Manutenção, Expedição, Logística ou ADM).');
    if ($setor === 'OBRA' && $obra === '') fer_fail('Escolha qual obra.');
    $prev = (string)($in['previsto'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prev) || $prev < date('Y-m-d')) fer_fail('Informe a data prevista de devolução (hoje ou depois).');
    $d['seq']['emp'] = (int)($d['seq']['emp'] ?? 0) + 1;
    $id = 'EMP-' . date('Y') . '-' . str_pad((string)$d['seq']['emp'], 4, '0', STR_PAD_LEFT);
    // Assinatura gravada no Estoque (mesmo cadastro de funcionários e mesmo arquivo de assinaturas).
    $ass = est_with_lock(function () use ($in, $id, $usuario) {
        $edb = est_read();
        $a = est_assinatura_salvar($edb, (array)($in['assinatura'] ?? []), $id, $usuario);
        est_write($edb);
        return $a;
    });
    $e = ['id' => $id, 'criado_em' => fer_now(), 'usuario' => $usuario, 'status' => 'ABERTO', 'previsto' => $prev, 'obra' => $obra, 'setor' => $setor, 'obra_id' => $obraId,
          'finalidade' => fer_str($in['finalidade'] ?? '', 300), 'responsavel' => $ass['nome'], 'funcionario_id' => $ass['funcionario_id'],
          'terceiro' => $ass['terceiro'], 'documento_pessoa' => $ass['documento_pessoa'], 'assinatura_id' => $ass['id'],
          'assinatura_arquivo' => $ass['arquivo'], 'assinatura_sha' => $ass['sha256'], 'itens' => array_values($itens), 'devolvido_em' => null];
    // v3.21.0: até 3 fotos das ferramentas no momento do empréstimo (estado em que saíram)
    $fotos = [];
    foreach (array_slice((array)($in['fotos'] ?? []), 0, 3) as $n => $img) if (is_string($img) && $img !== '') $fotos[] = fer_img_salvar($img, 'feremp_' . preg_replace('/[^A-Za-z0-9-]/', '', $id) . '_' . ($n + 1));
    $e['fotos'] = $fotos;
    $d['emprestimos'][$id] = $e;
    foreach ($itens as $fid => $i) {
        $d['ferramentas'][$fid]['historico'][] = ['em' => fer_now(), 'por' => $usuario, 'acao' => 'emprestada', 'detalhe' => $i['qtd'] . ' → ' . $ass['nome'] . ' (' . $obra . ') · ' . $id];
        $d['ferramentas'][$fid]['historico'] = array_slice($d['ferramentas'][$fid]['historico'], -100);
    }
    return $e;
}

/** Devolução total ou parcial, com estado de cada ferramenta. */
function fer_devolver(array &$d, array $in, $usuario) {
    $id = (string)($in['emprestimo_id'] ?? '');
    if (!isset($d['emprestimos'][$id])) fer_fail('Empréstimo não encontrado.', 404);
    $e = &$d['emprestimos'][$id];
    if ($e['status'] === 'DEVOLVIDO') fer_fail('Este empréstimo já foi devolvido.', 409);
    $houve = false;
    foreach ((array)($in['itens'] ?? []) as $dv) {
        $q = (int)($dv['qtd'] ?? 0);
        if ($q <= 0) continue;
        $estado = strtoupper((string)($dv['estado'] ?? 'OK'));
        if (!isset(FER_ESTADOS_DEV[$estado])) fer_fail('Estado de devolução inválido.');
        foreach ($e['itens'] as &$it) {
            if ($it['ferramenta_id'] !== ($dv['ferramenta_id'] ?? '')) continue;
            $pend = $it['qtd'] - $it['devolvido'];
            if ($q > $pend) fer_fail('Devolução maior que o pendente em ' . $it['nome'] . ' (pendente ' . $pend . ').');
            $obs = fer_str($dv['observacao'] ?? '', 300);
            if ($estado !== 'OK' && $obs === '') fer_fail('Descreva o problema de ' . $it['nome'] . '.');
            $it['devolvido'] += $q;
            $it['devolucoes'][] = ['em' => fer_now(), 'qtd' => $q, 'estado' => $estado, 'observacao' => $obs, 'recebido_por' => $usuario];
            $f = &$d['ferramentas'][$it['ferramenta_id']];
            if ($estado === 'DANIFICADA') $f['manutencao'] = (int)($f['manutencao'] ?? 0) + $q;
            if ($estado === 'EXTRAVIADA') { $f['quantidade'] = max(0, (int)$f['quantidade'] - $q); $f['extraviadas'] = (int)($f['extraviadas'] ?? 0) + $q; if ($f['quantidade'] === 0) $f['ativo'] = false; }
            $f['historico'][] = ['em' => fer_now(), 'por' => $usuario, 'acao' => 'devolvida', 'detalhe' => $q . ' · ' . FER_ESTADOS_DEV[$estado] . ($obs ? ' — ' . $obs : '') . ' · ' . $id];
            $f['historico'] = array_slice($f['historico'], -100);
            unset($f);
            $houve = true;
        }
        unset($it);
    }
    if (!$houve) fer_fail('Informe a quantidade devolvida.');
    $pend = array_sum(array_map(fn($i) => $i['qtd'] - $i['devolvido'], $e['itens']));
    $e['status'] = $pend > 0 ? 'PARCIAL' : 'DEVOLVIDO';
    if ($pend === 0) $e['devolvido_em'] = fer_now();
    $r = $e; unset($e);
    return $r;
}

function fer_manutencao(array &$d, array $in, $usuario) {
    $fid = (string)($in['ferramenta_id'] ?? '');
    if (!isset($d['ferramentas'][$fid])) fer_fail('Ferramenta não encontrada.', 404);
    $f = &$d['ferramentas'][$fid];
    $q = (int)($in['qtd'] ?? 1);
    $acao = (string)($in['acao'] ?? '');
    $emp = fer_emprestado($d);
    $disp = (int)$f['quantidade'] - (int)($emp[$fid] ?? 0) - (int)($f['manutencao'] ?? 0);
    $obs = fer_str($in['observacao'] ?? '', 300);
    if ($q < 1) fer_fail('Quantidade inválida.');
    if ($acao === 'enviar') { if ($q > $disp) fer_fail('Só ' . $disp . ' disponível para enviar.'); $f['manutencao'] = (int)($f['manutencao'] ?? 0) + $q; $txt = 'enviada para manutenção'; }
    elseif ($acao === 'retornar') { if ($q > (int)($f['manutencao'] ?? 0)) fer_fail('Só ' . (int)$f['manutencao'] . ' em manutenção.'); $f['manutencao'] -= $q; $txt = 'voltou da manutenção'; }
    elseif ($acao === 'baixar') {
        $origem = ($in['origem'] ?? 'manutencao') === 'disponivel' ? 'disponivel' : 'manutencao';
        if ($origem === 'manutencao' && $q > (int)($f['manutencao'] ?? 0)) fer_fail('Só ' . (int)$f['manutencao'] . ' em manutenção.');
        if ($origem === 'disponivel' && $q > $disp) fer_fail('Só ' . $disp . ' disponível.');
        if ($obs === '') fer_fail('Informe o motivo da baixa.');
        if ($origem === 'manutencao') $f['manutencao'] -= $q;
        $f['quantidade'] -= $q; $f['baixadas'] = (int)($f['baixadas'] ?? 0) + $q;
        if ($f['quantidade'] <= 0) { $f['quantidade'] = 0; $f['ativo'] = false; }
        $txt = 'baixada (descarte)';
    } else fer_fail('Ação inválida.');
    $f['historico'][] = ['em' => fer_now(), 'por' => $usuario, 'acao' => $txt, 'detalhe' => $q . ($obs ? ' — ' . $obs : '')];
    $f['historico'] = array_slice($f['historico'], -100);
    $r = $f; unset($f);
    return $r;
}

/** v3.21.0: grava uma imagem (data URL) em data/photos e devolve o caminho. */
function fer_img_salvar(string $dataUrl, string $prefixo): string {
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) fer_fail('Imagem inválida');
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024 || !@getimagesizefromstring($bin)) fer_fail('Imagem inválida ou maior que 3 MB');
    if (!is_dir(DATA_DIR . '/photos')) @mkdir(DATA_DIR . '/photos', 0755, true);
    $nome = $prefixo . '_' . bin2hex(random_bytes(6)) . '.jpg';
    if (@file_put_contents(DATA_DIR . '/photos/' . $nome, $bin, LOCK_EX) === false) fer_fail('Falha ao gravar a foto', 500);
    return 'data/photos/' . $nome;
}
/** Fotos da ferramenta: até 3. 'posicao' 0–2 substitui; sem posição acrescenta; 'remover' (índice) apaga. */
function fer_foto(array &$d, array $in) {
    $fid = (string)($in['ferramenta_id'] ?? '');
    if (!isset($d['ferramentas'][$fid])) fer_fail('Ferramenta não encontrada.', 404);
    $f = &$d['ferramentas'][$fid];
    $fotos = fer_fotos($f);
    if (isset($in['remover'])) {
        $i = (int)$in['remover'];
        if (!isset($fotos[$i])) fer_fail('Foto não encontrada.', 404);
        array_splice($fotos, $i, 1);
    } else {
        $pos = isset($in['posicao']) && $in['posicao'] !== '' ? (int)$in['posicao'] : count($fotos);
        if ($pos < 0 || $pos > 2) fer_fail('Cada ferramenta pode ter no máximo 3 fotos.');
        if ($pos >= count($fotos) && count($fotos) >= 3) fer_fail('Cada ferramenta pode ter no máximo 3 fotos. Substitua ou remova uma.');
        $path = fer_img_salvar((string)($in['imagem'] ?? ''), 'fer_' . preg_replace('/[^A-Za-z0-9-]/', '', $fid));
        if ($pos < count($fotos)) $fotos[$pos] = $path; else $fotos[] = $path;
    }
    $f['fotos'] = array_values($fotos);
    $f['foto'] = $f['fotos'][0] ?? '';
    return $f['fotos'];
}
function fer_fotos(array $f): array {
    $l = array_values(array_filter((array)($f['fotos'] ?? []), 'is_string'));
    if (!$l && !empty($f['foto'])) $l = [(string)$f['foto']];
    return array_slice($l, 0, 3);
}

function fer_item(array $d, $fid) {
    if (!isset($d['ferramentas'][$fid])) fer_fail('Ferramenta não encontrada.', 404);
    $f = fer_view($d['ferramentas'][$fid], fer_emprestado($d));
    $hist = [];
    foreach ($d['emprestimos'] as $e) foreach ($e['itens'] as $i) if ($i['ferramenta_id'] === $fid) {
        $v = fer_emp_view($e);
        $hist[] = ['id' => $e['id'], 'em' => $e['criado_em'], 'responsavel' => $e['responsavel'], 'obra' => $e['obra'], 'qtd' => $i['qtd'],
                   'devolvido' => $i['devolvido'], 'previsto' => $e['previsto'], 'status' => $e['status'], 'atrasado' => $v['atrasado'],
                   'dias' => (int)ceil(((kpi_ts_local($e['devolvido_em']) ?? time()) - strtotime($e['criado_em'])) / 86400), 'devolucoes' => $i['devolucoes']];
    }
    usort($hist, fn($a, $b) => strcmp($b['em'], $a['em']));
    $f['emprestimos'] = $hist;
    $f['historico'] = array_reverse($f['historico'] ?? []);
    return $f;
}
function kpi_ts_local($s) { return $s ? strtotime($s) : null; }

function fer_emprestimos(array $d, array $q) {
    $st = strtoupper((string)($q['status'] ?? ''));
    $busca = mb_strtolower((string)($q['q'] ?? ''));
    $out = [];
    foreach (array_reverse($d['emprestimos']) as $e) {
        $v = fer_emp_view($e);
        if ($st === 'ABERTOS' && $e['status'] === 'DEVOLVIDO') continue;
        if ($st === 'ATRASADOS' && !$v['atrasado']) continue;
        if ($st === 'DEVOLVIDOS' && $e['status'] !== 'DEVOLVIDO') continue;
        if ($busca !== '' && !str_contains(mb_strtolower($e['id'] . ' ' . $e['responsavel'] . ' ' . $e['obra'] . ' ' . implode(' ', array_column($e['itens'], 'nome'))), $busca)) continue;
        $out[] = $v;
        if (count($out) >= 500) break;
    }
    return $out;
}

function fer_comprovante_html(array $d, $id) {
    $e = $d['emprestimos'][$id] ?? null;
    if (!$e) return null;
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $img = '';
    $arq = EST_ASS_DIR . '/' . basename((string)$e['assinatura_arquivo']);
    if (is_file($arq)) $img = 'data:image/png;base64,' . base64_encode((string)file_get_contents($arq));
    $linhas = '';
    foreach ($e['itens'] as $i) {
        $f = $d['ferramentas'][$i['ferramenta_id']] ?? [];
        $dev = '';
        foreach ($i['devolucoes'] as $dv) $dev .= $h(date('d/m/Y', strtotime($dv['em']))) . ': ' . $dv['qtd'] . ' ' . $h(FER_ESTADOS_DEV[$dv['estado']] ?? $dv['estado']) . ($dv['observacao'] ? ' — ' . $h($dv['observacao']) : '') . '<br>';
        $linhas .= '<tr><td>' . $h($i['codigo']) . '</td><td>' . $h($i['nome']) . (!empty($f['serie']) ? '<br><small>Série ' . $h($f['serie']) . '</small>' : '') . (!empty($f['patrimonio']) ? '<br><small>Patrimônio ' . $h($f['patrimonio']) . '</small>' : '') . '</td><td style="text-align:center">' . (int)$i['qtd'] . '</td><td style="text-align:center">' . (int)$i['devolvido'] . '</td><td>' . ($dev ?: '—') . '</td></tr>';
    }
    $status = ['ABERTO' => 'Em aberto', 'PARCIAL' => 'Devolvido parcialmente', 'DEVOLVIDO' => 'Devolvido'][$e['status']] ?? $e['status'];
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Empréstimo ' . $h($id) . '</title>
<style>body{font:13px/1.45 Arial,sans-serif;color:#111;max-width:780px;margin:24px auto;padding:0 16px}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse;margin:14px 0}td,th{border:1px solid #bbb;padding:6px 8px;text-align:left;vertical-align:top}th{background:#eef2f7;font-size:11px;text-transform:uppercase}.top{display:flex;justify-content:space-between;border-bottom:2px solid #1d4ed8;padding-bottom:10px;margin-bottom:12px}.g{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px}.ass{margin-top:18px;border:1px solid #bbb;border-radius:8px;padding:12px}.ass img{max-width:100%;height:140px;object-fit:contain;display:block;margin:0 auto;border-bottom:1px solid #333}.mut{color:#555;font-size:11px}button{padding:8px 14px;margin-top:14px}@media print{button{display:none}}</style></head><body>
<div class="top"><div><h1>Termo de empréstimo de ferramentas</h1><div class="mut">Drilling do Brasil — Almoxarifado · Recebimento Drilling</div></div><div style="text-align:right"><b>' . $h($id) . '</b><br>' . $h(date('d/m/Y H:i', strtotime($e['criado_em']))) . '<br>' . $h($status) . '</div></div>
<div class="g"><div><b>Responsável:</b> ' . $h($e['responsavel']) . ($e['terceiro'] ? ' (terceiro · ' . $h($e['documento_pessoa']) . ')' : '') . '</div><div><b>Setor / destino:</b> ' . $h($e['obra']) . '</div>
<div><b>Devolução prevista:</b> ' . $h(date('d/m/Y', strtotime($e['previsto']))) . '</div><div><b>Entregue por:</b> ' . $h($e['usuario']) . '</div>' . ($e['finalidade'] ? '<div style="grid-column:1/-1"><b>Finalidade:</b> ' . $h($e['finalidade']) . '</div>' : '') . '</div>
<table><thead><tr><th>Código</th><th>Ferramenta</th><th>Qtd</th><th>Devolvido</th><th>Devoluções</th></tr></thead><tbody>' . $linhas . '</tbody></table>' . fer_fotos_html((array)($e['fotos'] ?? [])) . '
<div class="ass"><p>Declaro que recebi as ferramentas acima em perfeito estado de funcionamento e me responsabilizo por sua guarda, uso adequado e devolução até a data prevista, comunicando imediatamente qualquer dano, perda ou extravio.</p>' . ($img ? '<img src="' . $img . '" alt="Assinatura">' : '') . '
<p style="text-align:center;margin:6px 0 0"><b>' . $h($e['responsavel']) . '</b></p><p class="mut" style="text-align:center">Assinado em ' . $h(date('d/m/Y H:i:s', strtotime($e['criado_em']))) . ' (horário do servidor) · ' . $h($e['assinatura_id']) . '<br>Código de verificação SHA-256: ' . $h(substr((string)$e['assinatura_sha'], 0, 32)) . '…</p></div>
<button onclick="print()">Imprimir / salvar em PDF</button></body></html>';
}


/** v3.19.1: traz para o cadastro de ferramentas os itens do estoque com SKU de ferramenta (FE……).
 *  Só ACRESCENTA o que falta (casa pelo código); nunca altera nem apaga ferramenta existente. */
function fer_puxar_estoque(array &$d, $usuario) {
    $est = est_read();
    $tem = [];
    foreach ($d['ferramentas'] as $f) $tem[strtoupper((string)$f['codigo'])] = true;
    $novas = 0;
    foreach ($est['itens'] as $cod => $it) {
        if (!preg_match('/^FE\d{6}$/', $cod) || isset($tem[$cod])) continue;
        $saldo = (float)($it['saldo'] ?? 0);
        fer_salvar($d, ['codigo' => $cod, 'nome' => $it['descricao'], 'categoria' => 'Ferramenta (estoque SAP)', 'unidade' => $it['unidade'] ?? 'UN',
            'quantidade' => max(1, (int)ceil($saldo)), 'valor' => (float)($it['custo_medio'] ?? 0), 'localizacao' => (string)($it['localizacao'] ?? ''),
            'observacao' => 'Vinda do estoque (SKU ' . $cod . ')' . ($saldo < 1 ? ' — saldo no SAP zerado: confirme a quantidade disponível.' : '')], $usuario);
        $novas++;
    }
    $d['estoque_sincronizado_em'] = fer_now();
    return ['novas' => $novas, 'total' => count($d['ferramentas'])];
}

/** Fotos do empréstimo embutidas no comprovante (data URI — o comprovante não depende de outros arquivos). */
function fer_fotos_html(array $fotos): string {
    $out = '';
    foreach ($fotos as $p) {
        $abs = dirname(__FILE__) . '/' . ltrim((string)$p, '/');
        if (!preg_match('#^data/photos/[A-Za-z0-9_.-]+\.jpg$#', (string)$p) || !is_file($abs)) continue;
        $out .= '<img src="data:image/jpeg;base64,' . base64_encode((string)file_get_contents($abs)) . '" alt="Foto da ferramenta" style="width:31%;max-height:180px;object-fit:cover;border:1px solid #ccc;border-radius:6px;margin:0 1% 8px 0">';
    }
    return $out ? '<p style="margin:10px 0 6px"><b>Fotos na saída</b></p><div>' . $out . '</div>' : '';
}
