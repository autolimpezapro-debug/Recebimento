<?php
/**
 * Recebimento Drilling — Usuários, Perfis, Acessos e Permissões (v3.7.0)
 * Rotas: api.php?action=usuarios_*   (módulo 'usuarios'; escrita só do Administrador principal)
 *
 * GET  usuarios_opcoes                     módulos, operações, escopos e perfis
 * GET  usuarios_resumo                     indicadores (total/ativos/inativos/bloqueados/conectados)
 * GET  usuarios_listar[&q=&perfil=&status=]
 * GET  usuarios_detalhe&username=          cadastro + matriz + resumo efetivo
 * GET  usuarios_sessoes                    sessões ativas
 * GET  usuarios_log[&tipo=&usuario=&limite=]  trilha de segurança
 * POST usuarios_criar | usuarios_editar | usuarios_senha | usuarios_status
 *      usuarios_permissoes | usuarios_copiar_permissoes | usuarios_terminar_sessoes
 *      usuarios_acesso_config | usuarios_perfil_salvar
 *
 * Proteções: o Administrador principal não é editado nem bloqueado por ninguém; ninguém
 * altera as próprias permissões; o último acesso administrativo não pode ser removido;
 * senha apenas em hash; nenhuma senha ou conteúdo vai para o log.
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/perm_lib.php';

function us_nome(): string { $i = rd_identity(); return $i['username'] ?? ''; }
function us_body(int $max = 262144): array {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > $max) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) respond(['ok' => false, 'error' => 'Corpo JSON inválido'], 400);
    return $b;
}
function us_need(string $op): void { rd_gate('usuarios', $op); }
/**
 * Só o Administrador principal administra usuários — EXCETO enquanto o login não é
 * obrigatório: nesse intervalo não existe sessão, e sem essa exceção ninguém conseguiria
 * cadastrar os primeiros usuários (porta fechada por dentro). Assim que "exigir_login"
 * for ativado, o modo aberto some e a regra volta a ser rígida.
 */
function us_only_admin(): void {
    if (rd_e_admin_principal(us_nome())) return;
    if (!rd_exigir_login() && rd_identity() === null) {
        rd_log('modo_legado', 'operador-local', 'usuarios', 'admin', 'ok',
            'gestão de usuários sem sessão porque o login ainda não é obrigatório');
        return;
    }
    rd_log('negado', us_nome(), 'usuarios', 'admin', 'bloqueado', 'ação exclusiva do Administrador principal');
    respond(['ok' => false, 'error' => 'Esta ação é exclusiva do Administrador principal. Ative e entre com a conta de administrador.'], 403);
}
function us_str($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); }
function us_valida_username(string $n): string {
    $n = trim($n);
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,60}$/D', $n))
        respond(['ok' => false, 'error' => 'Nome de acesso inválido (3 a 60: letras, números, _ . @ -)'], 422);
    return $n;
}
function us_valida_senha(string $senha): void {
    if (strlen($senha) < 12 || strlen($senha) > 128)
        respond(['ok' => false, 'error' => 'A senha precisa de 12 a 128 caracteres.'], 422);
}

/** Perfil → role legado, para continuar compatível com o código que usa 'role'. */
function us_role_do_perfil(string $perfil): string {
    return ['admin_principal' => 'gestor', 'gestor' => 'gestor', 'conferente' => 'conferente',
        'almoxarife' => 'conferente', 'compras' => 'importador', 'motorista' => 'conferente',
        'consulta' => 'consulta'][$perfil] ?? 'consulta';
}

/** Combina operational_users.json + administrador + concessões em uma lista única. */
function us_all(): array {
    $users = function_exists('rd_user_data') ? rd_user_data() : [];
    $db = rd_perm_read();
    $admin = function_exists('rd_admin_data') ? rd_admin_data() : [];
    $rows = [];
    foreach ($users as $name => $u) {
        $p = $db['usuarios'][$name] ?? [];
        $perfil = (string)($p['perfil'] ?? '');
        $rows[$name] = [
            'username' => $name,
            'nome' => (string)($p['nome'] ?? $name),
            'email' => (string)($p['email'] ?? ''),
            'matricula' => (string)($p['matricula'] ?? ''),
            'cargo' => (string)($p['cargo'] ?? ''),
            'setor' => (string)($p['setor'] ?? ''),
            'perfil' => $perfil,
            'perfil_nome' => $db['perfis'][$perfil]['nome'] ?? '— sem perfil —',
            'escopo' => (string)($p['escopo'] ?? 'todos'),
            'status' => !empty($u['bloqueado']) ? 'bloqueado' : (!empty($u['enabled']) ? 'ativo' : 'inativo'),
            'criado_em' => $p['criado_em'] ?? ($u['updated_at'] ?? null),
            'ultimo_acesso' => $u['ultimo_acesso'] ?? null,
            'criado_por' => (string)($p['criado_por'] ?? ''),
            'permissoes_em' => $p['permissoes_em'] ?? null,
            'permissoes_por' => (string)($p['permissoes_por'] ?? ''),
            'tem_senha' => !empty($u['hash']),
            'ajustes_individuais' => isset($p['perm']) ? array_sum(array_map('count', array_filter($p['perm'], 'is_array'))) : 0,
        ];
    }
    if (!empty($admin['username'])) {
        $name = (string)$admin['username'];
        $rows[$name] = [
            'username' => $name, 'nome' => (string)($admin['nome'] ?? 'Administrador principal'),
            'email' => '', 'matricula' => '', 'cargo' => 'Administrador principal', 'setor' => 'TI',
            'perfil' => 'admin_principal', 'perfil_nome' => 'Administrador principal', 'escopo' => 'todos',
            'status' => empty($admin['must_change_password']) ? 'ativo' : 'inativo',
            'criado_em' => $admin['created_at'] ?? null, 'ultimo_acesso' => $admin['ultimo_acesso'] ?? null,
            'criado_por' => 'instalacao', 'permissoes_em' => null, 'permissoes_por' => '',
            'tem_senha' => !empty($admin['hash']), 'ajustes_individuais' => 0, 'protegido' => true,
        ];
    }
    return $rows;
}

/** Quantos acessos o usuário realmente tem (perfil + ajustes individuais). */
function us_contar(array $db, string $name): array {
    $perfil = rd_user_profile($db, $name);
    $u = $db['usuarios'][$name] ?? [];
    $liberadas = [];
    foreach (RD_MODULOS as $m => $titulo) {
        $q = 0;
        foreach (RD_OPERACOES as $op => $rotulo) {
            $v = $u['perm'][$m][$op] ?? $perfil['perm'][$m][$op] ?? false;
            if ($v) $q++;
        }
        if ($q) $liberadas[$m] = $q;
    }
    return ['modulos_com_acesso' => count($liberadas), 'detalhe' => $liberadas,
        'perfil' => $perfil['nome'] ?? '— sem perfil —',
        'perfil_id' => (string)($u['perfil'] ?? ''), 'escopo' => (string)($u['escopo'] ?? ($perfil['escopo'] ?? 'todos'))];
}

/** Matriz enviada → matriz validada. Módulo/operação inexistente é descartada. */
function us_matriz($perm): array {
    $out = [];
    if (!is_array($perm)) return $out;
    foreach ($perm as $modulo => $ops) {
        if (!isset(RD_MODULOS[$modulo]) || !is_array($ops)) continue;
        foreach ($ops as $op => $v) {
            if (!isset(RD_OPERACOES[$op])) continue;
            $out[$modulo][$op] = (bool)$v;
        }
    }
    return $out;
}

/** Grava em operational_users.json preservando os campos que já existiam. */
function us_grava_user(string $name, array $campos): void {
    // v3.59.1 (auditoria): gravação atômica e recusa se o arquivo estiver ilegível (antes virava lista vazia e apagava todos).
    $ok = rd_user_update(function ($list) use ($name, $campos) {
        $list[$name] = array_merge(is_array($list[$name] ?? null) ? $list[$name] : [], $campos, ['updated_at' => date('c')]);
        return $list;
    });
    if (!$ok) respond(['ok' => false, 'error' => 'Não foi possível gravar o usuário (data/operational_users.json ocupado ou ilegível — restaure o backup).'], 503);
}

/** O usuário é o último com acesso administrativo (pode administrar usuários)? */
function us_e_ultimo_admin(string $name): bool {
    $rows = us_all();
    $admins = array_filter($rows, function ($r) {
        return $r['status'] === 'ativo' && ($r['perfil'] === 'admin_principal'
            || rd_perfil_permite($r['perfil'], 'usuarios', 'admin'));
    });
    if (!isset($admins[$name])) return false;
    return count($admins) <= 1;
}

/** Existe Administrador principal com senha própria definida? */
function us_existe_admin_pronto(): bool {
    $admin = function_exists('rd_admin_data') ? rd_admin_data() : [];
    return !empty($admin['hash']) && empty($admin['must_change_password']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ------------------------------- GET ------------------------------- */
if ($method === 'GET') {
    switch ($action) {
        case 'usuarios_opcoes':
            us_need('ver');
            $db = rd_perm_read();
            $perfis = [];
            foreach ($db['perfis'] as $id => $p) {
                $perfis[$id] = ['nome' => $p['nome'], 'descricao' => $p['descricao'] ?? '',
                    'escopo' => $p['escopo'] ?? 'todos', 'protegido' => !empty($p['protegido']),
                    'perm' => $p['perm'] ?? []];
            }
            respond(['ok' => true, 'modulos' => RD_MODULOS, 'operacoes' => RD_OPERACOES, 'escopos' => RD_ESCOPOS,
                'perfis' => $perfis, 'pode_editar' => rd_e_admin_principal(us_nome()),
                'exigir_login' => rd_exigir_login(), 'eh_admin' => rd_e_admin_principal(us_nome()),
                'admin_pronto' => us_existe_admin_pronto(), 'usuario' => us_nome()]);

        case 'usuarios_resumo':
            us_need('ver');
            $rows = us_all(); $db = rd_perm_read();
            $st = array_column($rows, 'status');
            respond(['ok' => true, 'total' => count($rows),
                'ativos' => count(array_filter($st, fn($s) => $s === 'ativo')),
                'inativos' => count(array_filter($st, fn($s) => $s === 'inativo')),
                'bloqueados' => count(array_filter($st, fn($s) => $s === 'bloqueado')),
                'conectados' => count(rd_sec_sessoes()), 'perfis' => count($db['perfis']),
                'sem_perfil' => count(array_filter($rows, fn($r) => !isset($db['perfis'][$r['perfil']]))),
                'exigir_login' => rd_exigir_login()]);

        case 'usuarios_listar':
            us_need('listar');
            $k = mb_strtolower(us_str($_GET['q'] ?? '', 80), 'UTF-8');
            $perfil = (string)($_GET['perfil'] ?? ''); $status = (string)($_GET['status'] ?? '');
            $rows = [];
            foreach (us_all() as $r) {
                if ($perfil !== '' && $r['perfil'] !== $perfil) continue;
                if ($status !== '' && $r['status'] !== $status) continue;
                if ($k !== '' && mb_strpos(mb_strtolower($r['username'] . ' ' . $r['nome'] . ' ' . $r['cargo'] . ' ' . $r['setor'] . ' ' . $r['perfil_nome'], 'UTF-8'), $k) === false) continue;
                $rows[] = $r;
            }
            usort($rows, fn($a, $b) => strcmp($a['username'], $b['username']));
            respond(['ok' => true, 'usuarios' => $rows, 'total' => count($rows)]);

        case 'usuarios_detalhe':
            us_need('detalhe');
            $name = us_valida_username((string)($_GET['username'] ?? ''));
            $rows = us_all();
            if (!isset($rows[$name])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
            $db = rd_perm_read();
            respond(['ok' => true, 'usuario' => $rows[$name],
                'permissoes' => $db['usuarios'][$name]['perm'] ?? null,
                'efetivas' => us_contar($db, $name),
                'perfil_dados' => rd_user_profile($db, $name),
                'log' => array_slice(array_reverse(rd_sec_log()['eventos']), 0, 40)]);

        case 'usuarios_sessoes':
            us_need('ver');
            respond(['ok' => true, 'sessoes' => rd_sec_sessoes(), 'exigir_login' => rd_exigir_login()]);

        case 'usuarios_log':
            us_need('historico');
            $log = rd_sec_log();
            $tipo = (string)($_GET['tipo'] ?? ''); $usuario = (string)($_GET['usuario'] ?? '');
            $lim = max(1, min(1000, (int)($_GET['limite'] ?? 200)));
            $out = [];
            foreach (array_reverse($log['eventos']) as $e) {
                if ($tipo !== '' && ($e['tipo'] ?? '') !== $tipo) continue;
                if ($usuario !== '' && ($e['usuario'] ?? '') !== $usuario) continue;
                $out[] = $e;
                if (count($out) >= $lim) break;
            }
            respond(['ok' => true, 'eventos' => $out, 'total' => count($log['eventos'])]);
    }
    respond(['ok' => false, 'error' => 'Ação de usuários inválida'], 400);
}

/* ------------------------------- POST ------------------------------ */
if ($method !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
if (function_exists('rd_origin_valid') && !empty($_SERVER['HTTP_ORIGIN']) && !rd_origin_valid())
    respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);

$body = us_body();
$eu = us_nome();

switch ($action) {

    case 'usuarios_criar': {
        us_need('criar'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        if (rd_e_admin_principal($name)) respond(['ok' => false, 'error' => 'Este nome pertence ao Administrador principal.'], 409);
        if (isset(us_all()[$name])) respond(['ok' => false, 'error' => 'Já existe um usuário com esse nome de acesso.'], 409);
        $email = us_str($body['email'] ?? '', 160);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'error' => 'E-mail inválido'], 422);
        $senha = (string)($body['password'] ?? ''); us_valida_senha($senha);
        if ($senha !== (string)($body['password_confirm'] ?? '')) respond(['ok' => false, 'error' => 'A confirmação de senha não confere'], 422);
        $perfil = (string)($body['perfil'] ?? '');
        $db = rd_perm_read();
        if (!isset($db['perfis'][$perfil]) || $perfil === 'admin_principal')
            respond(['ok' => false, 'error' => 'Perfil inválido. O Administrador principal não é atribuível aqui.'], 422);
        $escopo = isset(RD_ESCOPOS[(string)($body['escopo'] ?? '')]) ? (string)$body['escopo'] : 'todos';

        us_grava_user($name, ['role' => us_role_do_perfil($perfil), 'hash' => password_hash($senha, PASSWORD_DEFAULT),
            'enabled' => true, 'bloqueado' => false, 'ultimo_acesso' => null]);
        $erro = null;
        rd_perm_lock(function () use ($db, $name, $body, $perfil, $escopo, $email, $eu, &$erro) {
            $db['usuarios'][$name] = [
                'nome' => us_str($body['nome_completo'] ?? $name, 120), 'email' => $email,
                'matricula' => us_str($body['matricula'] ?? '', 40), 'cargo' => us_str($body['cargo'] ?? '', 80),
                'setor' => us_str($body['setor'] ?? '', 80), 'perfil' => $perfil, 'escopo' => $escopo,
                'status' => 'ativo', 'criado_em' => date('c'), 'criado_por' => $eu,
                'perm' => us_matriz($body['perm'] ?? null),
            ];
            try { rd_perm_write($db); } catch (Throwable $e) { $erro = $e->getMessage(); }
        });
        if ($erro) respond(['ok' => false, 'error' => $erro], 500);
        rd_log('usuario_criado', $eu, 'usuarios', 'criar', 'ok', 'novo usuário ' . $name . ' (perfil ' . $perfil . ')', $name);
        respond(['ok' => true, 'usuario' => us_all()[$name], 'resumo' => us_contar(rd_perm_read(), $name)]);
    }

    case 'usuarios_editar': {
        us_need('editar'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        if (rd_e_admin_principal($name)) respond(['ok' => false, 'error' => 'O cadastro do Administrador principal não é editável por aqui.'], 403);
        $rows = us_all();
        if (!isset($rows[$name])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
        $email = us_str($body['email'] ?? '', 160);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'error' => 'E-mail inválido'], 422);
        $antesPerfil = $rows[$name]['perfil'];
        $db = rd_perm_read();
        $u = $db['usuarios'][$name] ?? [];
        $mapa = ['nome' => 'nome_completo', 'email' => 'email', 'matricula' => 'matricula', 'cargo' => 'cargo', 'setor' => 'setor'];
        foreach ($mapa as $campo => $chave) if (array_key_exists($chave, $body)) $u[$campo] = us_str($body[$chave], $campo === 'nome' ? 120 : 160);
        $mudouPerfil = false;
        if (array_key_exists('perfil', $body)) {
            $perfil = (string)$body['perfil'];
            if ($perfil !== '' && (!isset($db['perfis'][$perfil]) || $perfil === 'admin_principal'))
                respond(['ok' => false, 'error' => 'Perfil inválido.'], 422);
            $u['perfil'] = $perfil; $mudouPerfil = $perfil !== $antesPerfil;
        }
        if (array_key_exists('escopo', $body) && isset(RD_ESCOPOS[(string)$body['escopo']])) $u['escopo'] = (string)$body['escopo'];
        $u['editado_em'] = date('c'); $u['editado_por'] = $eu;
        $db['usuarios'][$name] = $u;
        $erro = null;
        rd_perm_lock(function () use ($db, &$erro) { try { rd_perm_write($db); } catch (Throwable $e) { $erro = $e->getMessage(); } });
        if ($erro) respond(['ok' => false, 'error' => $erro], 500);
        if ($mudouPerfil) us_grava_user($name, ['role' => us_role_do_perfil((string)$u['perfil'])]);
        rd_log('usuario_editado', $eu, 'usuarios', 'editar', 'ok',
            $name . ': perfil ' . $antesPerfil . ' → ' . ($u['perfil'] ?? '') . '; dados cadastrais atualizados', $name);
        respond(['ok' => true, 'usuario' => us_all()[$name]]);
    }

    case 'usuarios_senha': {
        us_need('editar'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        if (rd_e_admin_principal($name)) respond(['ok' => false, 'error' => 'A senha do Administrador principal é alterada em Administração → Backup e Restauração.'], 403);
        if (!isset(us_all()[$name])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
        $senha = (string)($body['password'] ?? ''); us_valida_senha($senha);
        if ($senha !== (string)($body['password_confirm'] ?? '')) respond(['ok' => false, 'error' => 'A confirmação de senha não confere'], 422);
        us_grava_user($name, ['hash' => password_hash($senha, PASSWORD_DEFAULT)]);
        rd_log('senha_redefinida', $eu, 'usuarios', 'editar', 'ok', 'senha de ' . $name . ' redefinida', $name);
        respond(['ok' => true, 'mensagem' => 'Senha atualizada. Informe-a diretamente à pessoa, por canal seguro.']);
    }

    case 'usuarios_status': {
        us_need('editar'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        $status = (string)($body['status'] ?? '');
        if (!in_array($status, ['ativo', 'inativo', 'bloqueado'], true)) respond(['ok' => false, 'error' => 'Status inválido'], 422);
        if (rd_e_admin_principal($name)) respond(['ok' => false, 'error' => 'O Administrador principal não pode ser bloqueado ou desativado.'], 403);
        if (!isset(us_all()[$name])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
        if ($status !== 'ativo' && us_e_ultimo_admin($name))
            respond(['ok' => false, 'error' => 'Este é o último acesso administrativo ativo. Crie outro administrador antes.'], 409);
        us_grava_user($name, ['enabled' => $status === 'ativo', 'bloqueado' => $status === 'bloqueado']);
        $encerradas = $status !== 'ativo' ? rd_sec_terminar_sessoes($name, $eu) : 0;
        rd_log('usuario_status', $eu, 'usuarios', 'editar', 'ok', $name . ' → ' . $status, $name);
        respond(['ok' => true, 'usuario' => us_all()[$name], 'sessoes_encerradas' => $encerradas]);
    }

    case 'usuarios_permissoes': {
        us_need('admin'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        if (rd_e_admin_principal($name)) respond(['ok' => false, 'error' => 'As permissões do Administrador principal não podem ser alteradas.'], 403);
        if ($name === $eu) respond(['ok' => false, 'error' => 'Você não pode alterar as próprias permissões.'], 403);
        $rows = us_all();
        if (!isset($rows[$name])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
        $db = rd_perm_read();
        if (array_key_exists('perfil', $body)) {
            $perfil = (string)$body['perfil'];
            if ($perfil !== '' && (!isset($db['perfis'][$perfil]) || $perfil === 'admin_principal'))
                respond(['ok' => false, 'error' => 'Perfil inválido.'], 422);
        }
        if (empty($body['confirmado'])) respond(['ok' => false, 'error' => 'Confira o resumo de acessos antes de salvar.'], 422);
        $antes = us_contar($db, $name);
        $u = $db['usuarios'][$name] ?? [];
        $u['perm'] = us_matriz($body['perm'] ?? null);
        if (array_key_exists('perfil', $body)) $u['perfil'] = (string)$body['perfil'];
        if (array_key_exists('escopo', $body) && isset(RD_ESCOPOS[(string)$body['escopo']])) $u['escopo'] = (string)$body['escopo'];
        $u['permissoes_em'] = date('c'); $u['permissoes_por'] = $eu;
        $db['usuarios'][$name] = $u;
        $erro = null;
        rd_perm_lock(function () use ($db, &$erro) { try { rd_perm_write($db); } catch (Throwable $e) { $erro = $e->getMessage(); } });
        if ($erro) respond(['ok' => false, 'error' => $erro], 500);
        if (array_key_exists('perfil', $body)) us_grava_user($name, ['role' => us_role_do_perfil((string)$u['perfil'])]);
        $depois = us_contar(rd_perm_read(), $name);
        rd_log('permissoes_alteradas', $eu, 'usuarios', 'admin', 'ok',
            $name . ': perfil ' . $antes['perfil'] . ' → ' . $depois['perfil'] . '; módulos com acesso ' .
            $antes['modulos_com_acesso'] . ' → ' . $depois['modulos_com_acesso'], $name);
        respond(['ok' => true, 'resumo' => $depois, 'usuario' => us_all()[$name]]);
    }

    case 'usuarios_copiar_permissoes': {
        us_need('admin'); us_only_admin();
        $origem = us_valida_username((string)($body['origem'] ?? ''));
        $destino = us_valida_username((string)($body['destino'] ?? ''));
        if ($origem === $destino) respond(['ok' => false, 'error' => 'Origem e destino são o mesmo usuário.'], 422);
        if (rd_e_admin_principal($destino)) respond(['ok' => false, 'error' => 'O Administrador principal não recebe permissões copiadas.'], 403);
        if ($destino === $eu) respond(['ok' => false, 'error' => 'Você não pode alterar as próprias permissões.'], 403);
        $rows = us_all();
        if (!isset($rows[$origem]) || !isset($rows[$destino])) respond(['ok' => false, 'error' => 'Usuário não encontrado'], 404);
        $db = rd_perm_read();
        $o = $db['usuarios'][$origem] ?? [];
        $d = $db['usuarios'][$destino] ?? [];
        $d['perfil'] = (string)($o['perfil'] ?? $rows[$origem]['perfil']);
        $d['escopo'] = (string)($o['escopo'] ?? 'todos');
        $d['perm'] = $o['perm'] ?? [];
        $d['copiado_de'] = $origem; $d['permissoes_em'] = date('c'); $d['permissoes_por'] = $eu;
        $db['usuarios'][$destino] = $d;
        $erro = null;
        rd_perm_lock(function () use ($db, &$erro) { try { rd_perm_write($db); } catch (Throwable $e) { $erro = $e->getMessage(); } });
        if ($erro) respond(['ok' => false, 'error' => $erro], 500);
        us_grava_user($destino, ['role' => us_role_do_perfil($d['perfil'])]);
        rd_log('permissoes_copiadas', $eu, 'usuarios', 'admin', 'ok', 'perfil e ajustes de ' . $origem . ' aplicados em ' . $destino, $destino);
        respond(['ok' => true, 'resumo' => us_contar(rd_perm_read(), $destino), 'usuario' => us_all()[$destino]]);
    }

    case 'usuarios_terminar_sessoes': {
        us_need('editar'); us_only_admin();
        $name = us_valida_username((string)($body['username'] ?? ''));
        if ($name === $eu) respond(['ok' => false, 'error' => 'Use Sair para encerrar a própria sessão.'], 422);
        $n = rd_sec_terminar_sessoes($name, $eu);
        rd_log('sessoes_encerradas', $eu, 'usuarios', 'editar', 'ok', $name . ': ' . $n . ' sessão(ões) invalidada(s)', $name);
        respond(['ok' => true, 'encerradas' => $n]);
    }

    case 'usuarios_acesso_config': {
        us_need('admin'); us_only_admin();
        $v = !empty($body['exigir_login']);
        if ($v && !us_existe_admin_pronto())
            respond(['ok' => false, 'error' => 'Ative a Administração (senha própria) antes de exigir login: sem administrador você ficaria de fora.'], 422);
        $antes = rd_exigir_login();
        rd_exigir_login_set($v, $eu);
        rd_log('acesso_config', $eu, 'usuarios', 'admin', 'ok', 'exigir_login ' . ($antes ? 'sim' : 'não') . ' → ' . ($v ? 'sim' : 'não'));
        respond(['ok' => true, 'exigir_login' => $v]);
    }

    case 'usuarios_perfil_salvar': {
        us_need('admin'); us_only_admin();
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($body['perfil'] ?? '')));
        if ($id === '' || strlen($id) > 40) respond(['ok' => false, 'error' => 'Identificador do perfil inválido'], 422);
        if ($id === 'admin_principal') respond(['ok' => false, 'error' => 'O perfil Administrador principal não é editável.'], 403);
        $perm = us_matriz($body['perm'] ?? null);
        $db = rd_perm_read();
        $novo = ['nome' => us_str($body['nome'] ?? $id, 80), 'descricao' => us_str($body['descricao'] ?? '', 200),
            'escopo' => isset(RD_ESCOPOS[(string)($body['escopo'] ?? '')]) ? (string)$body['escopo'] : 'todos', 'perm' => $perm];
        $db['perfis'][$id] = array_merge(['protegido' => false], $db['perfis'][$id] ?? [], $novo);
        $erro = null;
        rd_perm_lock(function () use ($db, &$erro) { try { rd_perm_write($db); } catch (Throwable $e) { $erro = $e->getMessage(); } });
        if ($erro) respond(['ok' => false, 'error' => $erro], 500);
        rd_log('perfil_salvo', $eu, 'usuarios', 'admin', 'ok', 'perfil ' . $id . ' salvo (' . count($perm) . ' módulo(s) configurados)');
        respond(['ok' => true, 'perfil' => $id]);
    }
}
respond(['ok' => false, 'error' => 'Ação de usuários inválida'], 400);
