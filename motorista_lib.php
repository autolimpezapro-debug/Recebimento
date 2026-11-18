<?php
/**
 * Recebimento Drilling — App do Motorista sem login (v3.40.0).
 * O escritório gera UM link pessoal para o motorista; o celular dele abre o app direto na rota ativa.
 * O link só dá acesso à rota em andamento (paradas, espelho dos pedidos de compra, "coletei", "não coletei", posição).
 * Não toca em estoque, pedidos, valores de NF nem qualquer outra tela. Dados em data/motorista_acesso.json.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const MOT_FILE = DATA_DIR . '/motorista_acesso.json';
const MOT_LOCK = DATA_DIR . '/.motorista.lock';
const MOT_VALIDADE_DIAS = 90;       // renova a cada uso
const MOT_MAX_APARELHOS = 3;        // ex.: navegador + app instalado + reserva

function mot_read(): array {
    $d = is_file(MOT_FILE) ? json_decode((string)file_get_contents(MOT_FILE), true) : null;
    return is_array($d) ? $d : [];
}
function mot_write(array $d): void {
    $tmp = MOT_FILE . '.tmp';
    if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($tmp, MOT_FILE)) respond(['ok' => false, 'error' => 'Não foi possível gravar o acesso do motorista.'], 500);
}
function mot_with_lock(callable $fn) {
    $fp = @fopen(MOT_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) respond(['ok' => false, 'error' => 'Não foi possível bloquear o acesso do motorista.'], 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
const MOT_MAX_MOTORISTAS = 10;

/** v3.51.0: vários motoristas, cada um com seu link. Migra o formato antigo (um só, na raiz do arquivo). */
function mot_todos(): array {
    $d = mot_read();
    if (!empty($d['token_hash']) && empty($d['motoristas'])) {
        $id = mot_slug((string)($d['nome'] ?? 'motorista'));
        $d = ['motoristas' => [$id => $d + ['id' => $id]]];
    }
    return (array)($d['motoristas'] ?? []);
}
function mot_salvar_todos(array $m): void { mot_write(['motoristas' => $m]); }
function mot_slug(string $nome): string {
    $t = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
    $t = trim((string)$t, '-');
    return $t !== '' ? substr($t, 0, 40) : 'motorista';
}
function mot_item_info(array $d): array {
    $exp = (string)($d['expira_em'] ?? '');
    return ['id' => (string)($d['id'] ?? ''), 'nome' => (string)($d['nome'] ?? ''), 'criado_em' => $d['criado_em'] ?? null, 'criado_por' => $d['criado_por'] ?? '', 'expira_em' => $exp ?: null,
        'ultimo_acesso' => $d['ultimo_acesso'] ?? null, 'aparelhos' => count((array)($d['aparelhos'] ?? [])), 'max_aparelhos' => MOT_MAX_APARELHOS,
        'revogado' => !empty($d['revogado']), 'expirado' => $exp !== '' && strtotime($exp) < time(),
        'ativo' => empty($d['revogado']) && ($exp === '' || strtotime($exp) >= time())];
}
/** Situação dos acessos, para o administrativo (nunca devolve o token). */
function mot_info(): array {
    $lista = array_values(array_map('mot_item_info', array_map(fn($id, $d) => $d + ['id' => $id], array_keys(mot_todos()), mot_todos())));
    return ['configurado' => (bool)$lista, 'motoristas' => $lista, 'max_motoristas' => MOT_MAX_MOTORISTAS];
}
/** Gera (ou renova) o link de UM motorista. Gerar de novo para o mesmo nome invalida o link anterior dele. */
function mot_gerar(string $nome, string $usuario): string {
    $nome = mb_substr(trim(preg_replace('/\s+/u', ' ', $nome)), 0, 80);
    if (mb_strlen($nome) < 2) respond(['ok' => false, 'error' => 'Informe o nome do motorista.'], 422);
    $token = bin2hex(random_bytes(24)); $id = mot_slug($nome);
    mot_with_lock(function () use ($nome, $usuario, $token, $id) {
        $m = mot_todos();
        if (!isset($m[$id]) && count($m) >= MOT_MAX_MOTORISTAS) respond(['ok' => false, 'error' => 'Limite de ' . MOT_MAX_MOTORISTAS . ' motoristas. Revogue e remova um para cadastrar outro.'], 409);
        $ant = (array)($m[$id] ?? []);
        $m[$id] = ['id' => $id, 'nome' => $nome, 'token_hash' => hash('sha256', $token), 'criado_em' => date('c'), 'criado_por' => $usuario, 'expira_em' => date('c', time() + MOT_VALIDADE_DIAS * 86400),
            'aparelhos' => [], 'revogado' => false, 'ops' => [], 'ultimo_acesso' => null,
            'historico' => array_slice(array_merge((array)($ant['historico'] ?? []), [['em' => date('c'), 'por' => $usuario, 'acao' => 'Novo link gerado' . (!empty($ant['token_hash']) ? ' (o anterior deixou de valer)' : '')]]), -30)];
        mot_salvar_todos($m);
    });
    return $token;
}
function mot_achar(string $id, array $m): string {
    if ($id !== '' && isset($m[$id])) return $id;
    if ($id === '' && count($m) === 1) return (string)array_key_first($m);
    respond(['ok' => false, 'error' => $m ? 'Informe qual motorista.' : 'Não há link do motorista.'], $m ? 422 : 404);
}
function mot_revogar(string $usuario, string $id = ''): void {
    mot_with_lock(function () use ($usuario, $id) {
        $m = mot_todos(); $k = mot_achar($id, $m);
        $m[$k]['revogado'] = true; $m[$k]['historico'][] = ['em' => date('c'), 'por' => $usuario, 'acao' => 'Link revogado']; $m[$k]['historico'] = array_slice($m[$k]['historico'], -30); mot_salvar_todos($m);
    });
}
function mot_remover(string $usuario, string $id): void {
    mot_with_lock(function () use ($id) { $m = mot_todos(); $k = mot_achar($id, $m); unset($m[$k]); mot_salvar_todos($m); });
}
function mot_liberar_aparelhos(string $usuario, string $id = ''): void {
    mot_with_lock(function () use ($usuario, $id) {
        $m = mot_todos(); $k = mot_achar($id, $m);
        $m[$k]['aparelhos'] = []; $m[$k]['historico'][] = ['em' => date('c'), 'por' => $usuario, 'acao' => 'Aparelhos liberados (o link pode ser aberto em um celular novo)']; $m[$k]['historico'] = array_slice($m[$k]['historico'], -30); mot_salvar_todos($m);
    });
}
/** Id do motorista dono do token, ou null. */
function mot_token_dono(string $token): ?string {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $h = hash('sha256', $token);
    foreach (mot_todos() as $id => $d) {
        if (!empty($d['token_hash']) && hash_equals((string)$d['token_hash'], $h) && empty($d['revogado']) && (empty($d['expira_em']) || strtotime((string)$d['expira_em']) >= time())) return (string)$id;
    }
    return null;
}
function mot_token_valido(string $token): bool { return mot_token_dono($token) !== null; }
/** Autentica a requisição do app (token do link + id do aparelho). Devolve o nome do motorista ou encerra com erro. */
function mot_autenticar(): string {
    $token = (string)($_SERVER['HTTP_X_MOT_TOKEN'] ?? $_GET['t'] ?? '');
    $dev = (string)($_SERVER['HTTP_X_MOT_DEV'] ?? $_GET['d'] ?? '');
    $dono = mot_token_dono($token);
    if ($dono === null) { usleep(400000); respond(['ok' => false, 'error' => 'Link inválido, vencido ou revogado. Peça um novo link ao escritório.', 'codigo' => 'link'], 401); }
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $dev)) respond(['ok' => false, 'error' => 'Aparelho não identificado.', 'codigo' => 'aparelho'], 400);
    $GLOBALS['MOT_ID'] = $dono;
    return mot_with_lock(function () use ($dev, $dono) {
        $m = mot_todos(); $d = $m[$dono]; $h = hash('sha256', $dev); $ap = (array)($d['aparelhos'] ?? []); $achou = false;
        foreach ($ap as &$a) if (($a['id'] ?? '') === $h) { $a['ultimo'] = date('c'); $achou = true; }
        unset($a);
        if (!$achou) {
            if (count($ap) >= MOT_MAX_APARELHOS) respond(['ok' => false, 'error' => 'Este link já está em uso em ' . MOT_MAX_APARELHOS . ' aparelhos. Peça ao escritório para liberar ou gerar um novo link.', 'codigo' => 'aparelhos'], 403);
            $ap[] = ['id' => $h, 'desde' => date('c'), 'ultimo' => date('c'), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)];
        }
        $d['aparelhos'] = $ap; $d['ultimo_acesso'] = date('c'); $d['expira_em'] = date('c', time() + MOT_VALIDADE_DIAS * 86400);
        $m[$dono] = $d; mot_salvar_todos($m);
        return (string)($d['nome'] ?? 'Motorista');
    });
}
/** true se o id da operação já foi processado (o app reenvia a fila se a resposta se perdeu). Só consulta. */
function mot_op_repetida(string $op): bool {
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $op) === 1 && in_array($op, (array)(mot_todos()[$GLOBALS['MOT_ID'] ?? '']['ops'] ?? []), true);
}
/** Marca a operação como processada (chamar só depois de gravar com sucesso). */
function mot_op_marcar(string $op): void {
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $op)) return;
    mot_with_lock(function () use ($op) { $m = mot_todos(); $k = $GLOBALS['MOT_ID'] ?? ''; if (!isset($m[$k])) return; $ops = (array)($m[$k]['ops'] ?? []); $ops[] = $op; $m[$k]['ops'] = array_slice($ops, -300); mot_salvar_todos($m); });
}

/** Espelho do pedido de compra (todos os dados que o sistema tem do PC). */
function mot_pedido(int $pc): ?array {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = []; $raw = @file_get_contents(DATA_DIR . '/items.json'); $j = $raw ? json_decode($raw, true) : null;
        foreach ((array)($j['items'] ?? $j ?? []) as $it) if (is_array($it) && !empty($it['pc'])) $mapa[(int)$it['pc']] = $it;
    }
    $it = $mapa[$pc] ?? null; if (!$it) return null;
    $destino = trim((string)($it['obra'] ?? '')) ?: trim((string)($it['destino_setor'] ?? ''));
    return ['pc' => $pc, 'fornecedor' => (string)($it['fornecedor'] ?? ''), 'situacao' => (string)($it['situacao'] ?? ''), 'solicitante' => (string)($it['solicitante'] ?? ''),
        'destino' => $destino, 'finalidade' => (string)($it['finalidade_pc'] ?? ''), 'os' => (string)($it['os_pc'] ?? ''), 'equipamento' => (string)($it['equipamento_pc'] ?? ''),
        'urgente' => (string)($it['urgente'] ?? '') === '1', 'urgente_motivo' => (string)($it['urgente_motivo'] ?? ''), 'material' => (string)($it['material'] ?? ''),
        'observacoes' => trim((string)($it['observacoes_pc'] ?? '')), 'obs' => trim((string)($it['obs'] ?? '')), 'importado_em' => $it['importacao_em'] ?? ($it['novo_pedido_em'] ?? null),
        'itens' => array_map(fn($x) => ['sku' => (string)($x['sku'] ?? ''), 'descricao' => (string)($x['descricao'] ?? ''), 'unidade' => (string)($x['unidade'] ?? ''), 'quantidade' => $x['quantidade_pedida'] ?? ''], array_slice((array)($it['insumos'] ?? []), 0, 300)),
        'tem_pdf' => (bool)mot_pdf_arquivo($pc)];
}
function mot_pdf_arquivo(int $pc): ?string {
    $l = glob(DATA_DIR . '/order_pdfs/pc_' . $pc . '_*.pdf') ?: [];
    return $l ? $l[0] : null;
}
/** Rota do motorista no formato do app (só o necessário). v3.51.0: a rota que leva o nome dele (a mais antiga ainda aberta). */
function mot_rota(string $nome = ''): array {
    $db = col_read();
    $minhas = col_rotas_do_motorista($db, $nome !== '' ? $nome : (string)(mot_todos()[$GLOBALS['MOT_ID'] ?? '']['nome'] ?? ''));
    if (!$minhas) return ['rota' => null];
    $x = $minhas[0];
    foreach ($minhas as $cand) if ($cand['feitas'] < $cand['total']) { $x = $cand; break; }
    $par = array_map(function ($p) {
        $pc = (int)($p['pc'] ?? 0);
        $g = $p['coletado_geo'] ?? null;
        return ['id' => $p['id'], 'pc' => $pc ?: null, 'fornecedor' => $p['fornecedor'] ?? '', 'cnpj' => $p['cnpj'] ?? '', 'contato' => $p['contato'] ?? '', 'endereco_completo' => $p['endereco_completo'] ?? '',
            'lat' => $p['lat'] ?? null, 'lng' => $p['lng'] ?? null, 'tem_coordenadas' => !empty($p['tem_coordenadas']), 'prioridade' => $p['prioridade'] ?? 'NORMAL', 'observacao' => $p['observacao'] ?? '',
            'agendada_data' => $p['agendada_data'] ?? '', 'agendada_hora' => $p['agendada_hora'] ?? '', 'status' => $p['status'] ?? '', 'status_label' => $p['status_label'] ?? '',
            'concluida' => !empty($p['concluida']), 'nao_coletada' => !empty($p['nao_coletada']), 'nao_coletada_motivo' => $p['nao_coletada_motivo'] ?? '', 'coletado_em' => $p['coletado_em'] ?? null,
            'coletado_geo' => $g ? ['lat' => $g['lat'], 'lng' => $g['lng']] : null, 'pedido' => $pc ? mot_pedido($pc) : null];
    }, array_merge($x['paradas'], $x['sem_local']));
    return ['rota' => ['id' => $x['id'], 'criada_em' => $x['criada_em'], 'motorista' => $x['motorista'] ?? '', 'feitas' => $x['feitas'], 'total' => $x['total'], 'nao_coletadas' => $x['nao_coletadas'] ?? 0,
        'km_estimado' => $x['km_estimado'] ?? 0, 'origem' => $x['origem'] ?? null, 'paradas' => $par]];
}
function mot_coleta_na_rota(string $id): array {
    $db = col_read(); $id = col_sanitize_id($id);
    $ok = false;
    foreach (col_rotas_do_motorista($db, (string)(mot_todos()[$GLOBALS['MOT_ID'] ?? '']['nome'] ?? '')) as $r) if (in_array($id, array_merge(array_column($r['paradas'], 'id'), array_column($r['sem_local'], 'id')), true)) $ok = true;
    if (!$ok || !isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Esta coleta não está na sua rota atual.'], 403);
    return $db['coletas'][$id];
}
