<?php
/**
 * Recebimento Drilling — Permissões por módulo e operação (v3.7.0)
 * ----------------------------------------------------------------
 * Fonte única de verdade do controle de acesso. Princípio: NEGAR POR PADRÃO.
 *
 *  - Não esconde apenas botões: toda rota passa por rd_gate(), que decide no servidor.
 *  - A resolução é: concessão individual do usuário > perfil > negado.
 *  - O navegador nunca envia permissões; elas vêm de data/permissoes.json.
 *  - admin_principal é protegido: ninguém altera suas permissões, ele não remove o
 *    próprio acesso e o último administrador ativo não pode ser desativado.
 *
 * Arquivos (todos privados, sob data/.htaccess):
 *  permissoes.json    perfis + concessões individuais + escopo de dados
 *  acessos_log.json   trilha de segurança (ações, mudanças de permissão, negações)
 *  sessoes.json       sessões ativas por usuário (para invalidar/encerrar)
 *  acesso_config.json flag exigir_login (ativa em etapa separada, após criar o admin)
 *
 * Compatibilidade: usuários antigos de data/operational_users.json (role + hash) são
 * lidos sem migração destrutiva; a primeira escrita normaliza o registro.
 */

declare(strict_types=1);
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const PERM_FILE  = DATA_DIR . '/permissoes.json';
const PERM_LOCK  = DATA_DIR . '/.permissoes.lock';
const SEC_LOG    = DATA_DIR . '/acessos_log.json';
const SEC_LOG_MAX = 4000;
const SESS_FILE  = DATA_DIR . '/sessoes.json';
const ACESSO_CFG = DATA_DIR . '/acesso_config.json';

/** Módulos do sistema (chave estável usada nas rotas e na matriz). */
const RD_MODULOS = [
    'dashboard'     => 'Dashboard',
    'pedidos'       => 'Pedidos de compra',
    'importacao'    => 'Importação de pedidos',
    'estoque'       => 'Estoque',
    'etiquetas'     => 'Etiquetas e identificação',
    'coletas'       => 'Coletas',
    'agenda'        => 'Agenda',
    'exportacao'    => 'Exportação',
    'notificacoes'  => 'Notificações',
    'administracao' => 'Administração',
    'usuarios'      => 'Usuários e permissões',
];

/** Operações disponíveis por módulo. */
const RD_OPERACOES = [
    'ver'       => 'Visualizar módulo',
    'listar'    => 'Visualizar listagem',
    'detalhe'   => 'Visualizar detalhes',
    'criar'     => 'Criar registros',
    'editar'    => 'Editar registros',
    'excluir'   => 'Excluir registros',
    'status'    => 'Alterar status',
    'iniciar'   => 'Iniciar operação',
    'finalizar' => 'Finalizar operação',
    'aprovar'   => 'Aprovar operação',
    'reabrir'   => 'Reabrir operação',
    'anexar'    => 'Anexar arquivos',
    'baixar'    => 'Baixar arquivos',
    'exportar'  => 'Exportar dados',
    'imprimir'  => 'Imprimir documentos',
    'historico' => 'Consultar histórico',
    'admin'     => 'Administrar configurações',
];

/** Escopo de dados (alcance dos registros visíveis/alteráveis). */
const RD_ESCOPOS = [
    'todos'      => 'Todos os registros',
    'atribuidos' => 'Somente registros atribuídos a mim',
    'setor'      => 'Somente registros do meu setor',
    'unidade'    => 'Somente registros da minha unidade/obra',
];

/** Perfis iniciais. Totalmente personalizáveis pelo admin_principal. */
function rd_perfis_padrao(): array {
    $todas = array_keys(RD_OPERACOES);
    $leitura = ['ver', 'listar', 'detalhe', 'historico'];
    $mk = function (array $map) use ($todas): array {
        $out = [];
        foreach (array_keys(RD_MODULOS) as $m) {
            $lib = $map[$m] ?? [];
            $out[$m] = array_fill_keys($todas, false);
            if ($lib === '*') $out[$m] = array_fill_keys($todas, true);
            else foreach ($lib as $op) if (isset($out[$m][$op])) $out[$m][$op] = true;
        }
        return $out;
    };
    return [
        'admin_principal' => [
            'nome' => 'Administrador principal',
            'descricao' => 'Acesso total, inclusive a usuários, perfis e configurações de segurança.',
            'escopo' => 'todos', 'protegido' => true,
            'perm' => $mk(array_fill_keys(array_keys(RD_MODULOS), '*')),
        ],
        'gestor' => [
            'nome' => 'Gestor',
            'descricao' => 'Operação completa e relatórios. Não administra o Administrador principal nem as próprias permissões.',
            'escopo' => 'todos',
            'perm' => $mk([
                'dashboard' => ['ver', 'listar', 'detalhe', 'historico', 'exportar'],
                'pedidos' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'iniciar', 'finalizar', 'aprovar', 'reabrir', 'anexar', 'baixar', 'exportar', 'historico'],
                'importacao' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'historico'],
                'estoque' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'finalizar', 'aprovar', 'anexar', 'baixar', 'exportar', 'imprimir', 'historico', 'admin'],
                'etiquetas' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'imprimir', 'exportar', 'historico', 'baixar', 'admin'],
                'coletas' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'anexar', 'exportar', 'historico'],
                'agenda' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'finalizar', 'historico'],
                'exportacao' => ['ver', 'listar', 'exportar', 'imprimir', 'baixar'],
                'notificacoes' => ['ver', 'listar', 'criar', 'historico'],
                'administracao' => ['ver', 'listar', 'criar', 'editar', 'baixar', 'historico', 'admin'],
                'usuarios' => ['ver', 'listar', 'detalhe'],
            ]),
        ],
        'conferente' => [
            'nome' => 'Conferente',
            'descricao' => 'Conferência de recebimento: quantidades, anexos, NF e finalização.',
            'escopo' => 'todos',
            'perm' => $mk([
                'dashboard' => ['ver', 'listar', 'detalhe'],
                'pedidos' => ['ver', 'listar', 'detalhe', 'editar', 'status', 'iniciar', 'finalizar', 'anexar', 'baixar', 'historico'],
                'estoque' => ['ver', 'listar', 'detalhe', 'editar', 'finalizar', 'historico'],
                'etiquetas' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'imprimir', 'exportar', 'historico'],
                'coletas' => ['ver', 'listar', 'detalhe'],
                'agenda' => ['ver', 'listar', 'detalhe', 'editar', 'status'],
                'notificacoes' => ['ver', 'listar', 'historico'],
                'exportacao' => ['ver', 'listar', 'exportar'],
            ]),
        ],
        'almoxarife' => [
            'nome' => 'Almoxarife',
            'descricao' => 'Estoque: entradas, saídas, inventário, reservas, reposição e etiquetas.',
            'escopo' => 'todos',
            'perm' => $mk([
                'dashboard' => ['ver', 'listar', 'detalhe'],
                'pedidos' => ['ver', 'listar', 'detalhe'],
                'estoque' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'finalizar', 'anexar', 'exportar', 'imprimir', 'historico'],
                'etiquetas' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'imprimir', 'exportar', 'historico'],
                'notificacoes' => ['ver', 'listar', 'historico'],
                'exportacao' => ['ver', 'listar', 'exportar'],
            ]),
        ],
        'compras' => [
            'nome' => 'Compras',
            'descricao' => 'Pedidos, importação, fornecedores e pendências de entrega.',
            'escopo' => 'todos',
            'perm' => $mk([
                'dashboard' => ['ver', 'listar', 'detalhe', 'exportar'],
                'pedidos' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'exportar', 'historico', 'baixar'],
                'importacao' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'historico'],
                'estoque' => ['ver', 'listar', 'detalhe', 'exportar', 'historico'],
                'coletas' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status', 'historico'],
                'agenda' => ['ver', 'listar', 'detalhe', 'criar', 'editar', 'status'],
                'exportacao' => ['ver', 'listar', 'exportar', 'imprimir', 'baixar'],
                'notificacoes' => ['ver', 'listar', 'historico'],
            ]),
        ],
        'motorista' => [
            'nome' => 'Motorista / Coletor',
            'descricao' => 'Somente as coletas atribuídas: rota, chegada, coleta e ocorrências.',
            'escopo' => 'atribuidos',
            'perm' => $mk([
                'coletas' => ['ver', 'listar', 'detalhe', 'editar', 'status', 'iniciar', 'finalizar', 'anexar', 'baixar'],
                'agenda' => ['ver', 'listar', 'detalhe', 'status'],
                'notificacoes' => ['ver', 'listar', 'historico'],
                'pedidos' => ['ver', 'listar', 'detalhe'],
            ]),
        ],
        'visualizador' => [
            'nome' => 'Visualizador (somente leitura)',
            'descricao' => 'Vê todos os módulos operacionais — pedidos, estoque, importados, ferramentas, locações, coletas, agenda, indicadores — sem criar, editar, excluir, finalizar nem exportar. Não acessa Administração nem Usuários.',
            'escopo' => 'todos',
            'perm' => $mk(array_fill_keys(['dashboard', 'pedidos', 'importacao', 'estoque', 'etiquetas', 'coletas', 'agenda', 'exportacao', 'notificacoes'], ['ver', 'listar', 'detalhe', 'historico'])),
        ],
        'consulta' => [
            'nome' => 'Consulta',
            'descricao' => 'Somente leitura nos módulos autorizados. Não cria, edita, exclui nem finaliza.',
            'escopo' => 'todos',
            'perm' => $mk([
                'dashboard' => ['ver', 'listar', 'detalhe'],
                'pedidos' => ['ver', 'listar', 'detalhe', 'historico'],
                'notificacoes' => ['ver', 'listar'],
            ]),
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Armazenamento                                                       */
/* ------------------------------------------------------------------ */

function rd_empty_perm(): array {
    return ['versao' => 1, 'perfis' => rd_perfis_padrao(), 'usuarios' => []];
}

function rd_perm_read(): array {
    if (!is_file(PERM_FILE)) return rd_empty_perm();
    $raw = @file_get_contents(PERM_FILE);
    if ($raw === false || trim($raw) === '') return rd_empty_perm();
    $d = json_decode($raw, true);
    if (!is_array($d)) return rd_empty_perm();   // nunca recria por cima: leitura só em memória
    $base = rd_empty_perm();
    $out = array_merge($base, $d);
    // perfis padrão sempre presentes (atualização de código não apaga personalizações)
    foreach ($base['perfis'] as $id => $p) {
        if (!isset($out['perfis'][$id])) $out['perfis'][$id] = $p;
        else $out['perfis'][$id] = array_merge($p, $out['perfis'][$id]);
    }
    return $out;
}

function rd_perm_write(array $db): void {
    $tmp = PERM_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false)
        throw new RuntimeException('Não foi possível gravar as permissões.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, PERM_FILE)) throw new RuntimeException('Falha ao persistir as permissões.');
}

function rd_perm_lock(callable $fn) {
    $fp = @fopen(PERM_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Trava de permissões indisponível.');
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/** Flag que liga a exigência de login. Desligada = comportamento legado (operador local). */
function rd_exigir_login(): bool {
    if (!is_file(ACESSO_CFG)) return false;
    $d = json_decode((string)@file_get_contents(ACESSO_CFG), true);
    return is_array($d) && !empty($d['exigir_login']);
}

function rd_exigir_login_set(bool $v, string $usuario): array {
    $cfg = ['exigir_login' => $v, 'alterado_em' => date('c'), 'alterado_por' => $usuario];
    $tmp = ACESSO_CFG . '.tmp';
    if (@file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !@rename($tmp, ACESSO_CFG))
        throw new RuntimeException('Não foi possível salvar a configuração de acesso.');
    @chmod(ACESSO_CFG, 0600);
    return $cfg;
}

/* ------------------------------------------------------------------ */
/* Trilha de segurança                                                 */
/* ------------------------------------------------------------------ */

function rd_sec_log(?array $entrada = null): array {
    $log = is_file(SEC_LOG) ? json_decode((string)@file_get_contents(SEC_LOG), true) : null;
    if (!is_array($log) || !isset($log['eventos'])) $log = ['versao' => 1, 'last_id' => 0, 'eventos' => []];
    if ($entrada === null) return $log;
    $log['last_id'] = (int)($log['last_id'] ?? 0) + 1;
    $entrada['id'] = $log['last_id'];
    $entrada['em'] = $entrada['em'] ?? date('c');
    $log['eventos'][] = $entrada;
    if (count($log['eventos']) > SEC_LOG_MAX) $log['eventos'] = array_slice($log['eventos'], -SEC_LOG_MAX);
    $tmp = SEC_LOG . '.tmp';
    if (@file_put_contents($tmp, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($tmp, 0600); @rename($tmp, SEC_LOG);
    }
    return $log;
}

/** Nunca registra senhas nem conteúdo de requisição. */
function rd_log(string $tipo, string $usuario, string $modulo, string $operacao, string $resultado, string $detalhe = '', $registro = null): void {
    try {
        rd_sec_log(['tipo' => $tipo, 'usuario' => $usuario, 'modulo' => $modulo, 'operacao' => $operacao,
            'resultado' => $resultado, 'registro' => $registro, 'detalhe' => mb_substr($detalhe, 0, 300),
            'ip' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''))]);
    } catch (Throwable $e) { error_log('Trilha de segurança: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ */
/* Identidade e resolução de permissão                                 */
/* ------------------------------------------------------------------ */

/** Identidade da sessão, com status do cadastro. Retorna null se não logado. */
function rd_identity(): ?array {
    if (!function_exists('rd_login_info')) return null;
    $s = rd_login_info();
    if (!is_array($s) || empty($s['username'])) return null;
    return ['username' => (string)$s['username'], 'role' => (string)($s['role'] ?? '')];
}

/** true se o usuário existe, está ativo e não bloqueado (fonte: operational_users + admin). */
function rd_user_status(string $username): string {
    if ($username === '') return 'inexistente';
    $admin = function_exists('rd_admin_data') ? rd_admin_data() : [];
    if ($admin && $username === (string)($admin['username'] ?? 'adm') && empty($admin['must_change_password'])) return 'ativo';
    $users = function_exists('rd_user_data') ? rd_user_data() : [];
    $u = $users[$username] ?? null;
    if (!is_array($u)) return 'inexistente';
    if (!empty($u['bloqueado'])) return 'bloqueado';
    return !empty($u['enabled']) ? 'ativo' : 'inativo';
}

function rd_e_admin_principal(string $username): bool {
    $admin = function_exists('rd_admin_data') ? rd_admin_data() : [];
    return $admin && $username === (string)($admin['username'] ?? 'adm');
}

/** Perfil efetivo do usuário (null = sem perfil; então tudo é negado). */
function rd_user_profile(array $db, string $username): ?array {
    $u = $db['usuarios'][$username] ?? null;
    if (rd_e_admin_principal($username)) return $db['perfis']['admin_principal'] ?? null;
    if (!is_array($u)) {
        // compatibilidade: usuários antigos só têm 'role' (conferente/consulta/importador)
        $legacy = function_exists('rd_user_data') ? (rd_user_data()[$username]['role'] ?? '') : '';
        $map = ['conferente' => 'conferente', 'consulta' => 'consulta', 'importador' => 'compras'];
        $id = $map[$legacy] ?? null;
        return $id ? ($db['perfis'][$id] ?? null) : null;
    }
    return $db['perfis'][$u['perfil'] ?? ''] ?? null;
}

/**
 * DECISÃO: pode?  negar por padrão.
 * ordem: override individual > perfil > false
 */
function rd_perm(string $modulo, string $operacao): bool {
    $id = rd_identity();
    if ($id === null) return !rd_exigir_login();          // legado: sem login, operador local
    $db = rd_perm_read();
    $u = $db['usuarios'][$id['username']] ?? null;
    $ov = $u['perm'][$modulo][$operacao] ?? null;
    if ($ov !== null) return (bool)$ov;
    $perfil = rd_user_profile($db, $id['username']);
    if (!is_array($perfil)) return false;
    return !empty($perfil['perm'][$modulo][$operacao]);
}

/** Escopo efetivo (override individual > perfil > 'todos' quando legado). */
function rd_escopo(string $modulo = ''): string {
    $id = rd_identity();
    if ($id === null) return 'todos';
    $db = rd_perm_read();
    $u = $db['usuarios'][$id['username']] ?? null;
    $e = $u['escopo'][$modulo] ?? ($u['escopo']['_'] ?? null);
    if ($e === null) {
        $perfil = rd_user_profile($db, $id['username']);
        $e = $perfil['escopo'] ?? 'todos';
    }
    return isset(RD_ESCOPOS[$e]) ? $e : 'todos';
}

/** Erro padronizado de negação (frontend mostra "acesso negado"). */
function rd_negado(string $modulo, string $operacao): never {
    $id = rd_identity();
    rd_log('negado', $id['username'] ?? '', $modulo, $operacao, 'bloqueado', 'rota ' . (string)($_GET['action'] ?? ''));
    $nome = RD_MODULOS[$modulo] ?? $modulo;
    $op = RD_OPERACOES[$operacao] ?? $operacao;
    if (function_exists('respond')) respond(['ok' => false, 'error' => 'Acesso negado: ' . $nome . ' — ' . $op . '.', 'code' => 'FORBIDDEN'], 403);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
    exit;
}

function rd_gate(string $modulo, string $operacao): void {
    if (!rd_perm($modulo, $operacao)) rd_negado($modulo, $operacao);
}

/* ------------------------------------------------------------------ */
/* Mapa rota → módulo + operação                                       */
/* ------------------------------------------------------------------ */

/** Devolve [modulo, operacao] ou null quando a rota é pública/self-service. */
function rd_rota(string $action): ?array {
    static $exatas = null;
    if ($exatas === null) {
        $exatas = [
            // núcleo de pedidos
            'list' => ['pedidos', 'listar'], 'obras_lista' => ['pedidos', 'listar'], 'stats' => ['dashboard', 'ver'], 'events' => ['notificacoes', 'listar'],
            'save' => ['pedidos', 'editar'], 'pc_devolucao' => ['pedidos', 'editar'], 'pc_destino' => ['pedidos', 'editar'], 'pc_avisar' => ['pedidos', 'editar'], 'pc_avisar_info' => ['pedidos', 'detalhe'], 'avisar_pendencias' => ['pedidos', 'listar'], 'pc_create' => ['pedidos', 'criar'], 'start_receipt' => ['pedidos', 'iniciar'], 'finish_receipt' => ['pedidos', 'finalizar'],
            'correct_partial' => ['pedidos', 'aprovar'], 'reset' => ['administracao', 'admin'],
            'upload_photo' => ['pedidos', 'anexar'], 'upload_xml' => ['pedidos', 'anexar'], 'nf_ler_danfe' => ['pedidos', 'anexar'], 'remove_photo' => ['pedidos', 'anexar'],
            // importação
            'whatsapp_import_preview' => ['importacao', 'criar'], 'upload_parte' => ['importacao', 'criar'], 'upload_info' => ['importacao', 'ver'], 'upload_parte_coletas' => ['coletas', 'criar'], 'whatsapp_import_confirm' => ['importacao', 'criar'],
            'gemini_status' => ['importacao', 'ver'], 'gemini_config' => ['importacao', 'admin'], 'gemini_teste' => ['importacao', 'admin'],
            // estoque
            'estoque_dashboard' => ['estoque', 'ver'], 'estoque_itens' => ['estoque', 'listar'], 'estoque_equipamentos' => ['estoque', 'listar'], 'estoque_os_config' => ['estoque', 'listar'], 'estoque_saida_buscar' => ['estoque', 'listar'], 'estoque_saida_pc' => ['estoque', 'listar'], 'estoque_os_config_salvar' => ['estoque', 'admin'], 'estoque_item' => ['estoque', 'detalhe'],
            'estoque_entradas' => ['estoque', 'listar'], 'estoque_entrada' => ['estoque', 'detalhe'],
            'estoque_movimentos' => ['estoque', 'listar'], 'estoque_requisicoes' => ['estoque', 'listar'],
            'estoque_inventarios' => ['estoque', 'listar'], 'estoque_inventario' => ['estoque', 'detalhe'],
            'estoque_reservas' => ['estoque', 'listar'], 'estoque_reposicao' => ['estoque', 'listar'],
            'alertas_status' => ['estoque', 'ver'], 'alertas_config' => ['estoque', 'admin'], 'alertas_config_salvar' => ['estoque', 'admin'], 'alertas_teste' => ['estoque', 'admin'], 'alertas_verificar' => ['estoque', 'admin'], 'alertas_contatos' => ['estoque', 'admin'], 'alertas_contatos_salvar' => ['estoque', 'admin'], 'alertas_contatos_testar' => ['estoque', 'admin'],
            'estoque_abc' => ['estoque', 'listar'], 'estoque_kardex' => ['estoque', 'detalhe'],
            'estoque_export_csv' => ['estoque', 'exportar'],
            'estoque_entrada_save' => ['estoque', 'criar'], 'estoque_entrada_from_pedido' => ['estoque', 'criar'],
            'estoque_entrada_manual' => ['estoque', 'criar'], 'estoque_item_save' => ['estoque', 'criar'],
            'estoque_entrada_remover' => ['estoque', 'excluir'], 'estoque_conferir' => ['estoque', 'editar'],
            'estoque_receber' => ['estoque', 'finalizar'], 'estoque_saida' => ['estoque', 'criar'],
            'estoque_inventario_criar' => ['estoque', 'criar'], 'estoque_inventario_contar' => ['estoque', 'editar'],
            'estoque_inventario_aplicar' => ['estoque', 'aprovar'], 'estoque_reservar' => ['estoque', 'criar'],
            'estoque_reserva_cancelar' => ['estoque', 'editar'], 'estoque_transferir' => ['estoque', 'editar'],
            'estoque_baixa_perda' => ['estoque', 'editar'], 'estoque_estorno' => ['estoque', 'aprovar'],
            'estoque_sap_historico' => ['estoque', 'listar'], 'estoque_sap_previa' => ['estoque', 'criar'],
            'estoque_sap_importar' => ['estoque', 'aprovar'],
            'protocolos_painel' => ['pedidos', 'listar'], 'protocolos_arquivo' => ['pedidos', 'baixar'], 'protocolos_imprimir' => ['pedidos', 'baixar'], 'protocolos_pdf' => ['pedidos', 'baixar'], 'protocolos_csv' => ['pedidos', 'baixar'],
            'protocolos_criar' => ['pedidos', 'editar'], 'protocolos_status' => ['pedidos', 'editar'], 'protocolos_doc_enviar' => ['pedidos', 'anexar'],
            'romaneios_painel' => ['estoque', 'listar'], 'romaneios_pdf' => ['estoque', 'listar'], 'romaneios_csv' => ['estoque', 'listar'], 'romaneios_imprimir' => ['estoque', 'listar'],
            'romaneios_salvar' => ['estoque', 'criar'], 'romaneios_status' => ['estoque', 'editar'],
            'locacoes_painel' => ['estoque', 'listar'], 'locacoes_csv' => ['estoque', 'listar'],
            'locacoes_salvar' => ['estoque', 'editar'], 'locacoes_transferir' => ['estoque', 'criar'], 'locacoes_desfazer_transferencia' => ['estoque', 'editar'],
            'locacoes_extra' => ['estoque', 'criar'], 'locacoes_devolver' => ['estoque', 'criar'], 'locacoes_reabrir' => ['estoque', 'editar'],
            'locacoes_excluir' => ['estoque', 'aprovar'], 'locacoes_arquivo' => ['estoque', 'listar'], 'locacoes_alertas_config' => ['estoque', 'listar'], 'locacoes_ics' => ['estoque', 'listar'], 'locacoes_alertas_salvar' => ['estoque', 'editar'], 'locacoes_alertas_verificar' => ['estoque', 'editar'], 'locacoes_prorrogar' => ['estoque', 'editar'], 'locacoes_doc_enviar' => ['estoque', 'anexar'], 'locacoes_doc_remover' => ['estoque', 'editar'],
            'obras_painel' => ['estoque', 'listar'], 'obras_pc' => ['estoque', 'listar'], 'obras_obra' => ['estoque', 'detalhe'], 'obras_arquivo' => ['estoque', 'detalhe'],
            'obras_nova' => ['estoque', 'criar'], 'obras_envio_salvar' => ['estoque', 'criar'], 'obras_arquivo_enviar' => ['estoque', 'anexar'],
            'obras_arquivo_remover' => ['estoque', 'editar'], 'obras_envio_excluir' => ['estoque', 'aprovar'],
            'ferramentas_painel' => ['estoque', 'listar'], 'ferramentas_item' => ['estoque', 'detalhe'],
            'ferramentas_emprestimos' => ['estoque', 'listar'], 'ferramentas_comprovante' => ['estoque', 'detalhe'],
            'ferramentas_salvar' => ['estoque', 'editar'], 'ferramentas_puxar_estoque' => ['estoque', 'editar'], 'ferramentas_importar' => ['estoque', 'editar'],
            'ferramentas_emprestar' => ['estoque', 'criar'], 'ferramentas_devolver' => ['estoque', 'criar'],
            'ferramentas_manutencao' => ['estoque', 'editar'], 'ferramentas_foto' => ['estoque', 'editar'],
            'estoque_comprovante' => ['estoque', 'detalhe'], 'estoque_foto_saida' => ['estoque', 'detalhe'], 'estoque_funcionarios' => ['estoque', 'listar'],
            'estoque_sku_relatorio' => ['estoque', 'listar'], 'estoque_ia' => ['estoque', 'listar'], 'estoque_obras' => ['estoque', 'listar'], 'estoque_sku_unificar' => ['estoque', 'aprovar'],
            'estoque_funcionario_retiradas' => ['estoque', 'detalhe'], 'estoque_funcionario_salvar' => ['estoque', 'editar'],
            'estoque_funcionarios_importar' => ['estoque', 'editar'], 'estoque_importados' => ['estoque', 'listar'], 'estoque_importado_item' => ['estoque', 'detalhe'],
            'estoque_importados_previa' => ['estoque', 'criar'], 'estoque_importados_carga' => ['estoque', 'aprovar'],
            'estoque_importado_mov' => ['estoque', 'criar'], 'estoque_importado_config' => ['estoque', 'editar'],
            'estoque_importado_foto' => ['estoque', 'editar'], 'estoque_importados_link' => ['estoque', 'editar'], 'estoque_importados_link_gerar' => ['estoque', 'editar'], 'estoque_importados_link_revogar' => ['estoque', 'editar'], 'estoque_importado_mov_editar' => ['estoque', 'editar'],
            // etiquetas
            'etiquetas_config' => ['etiquetas', 'ver'], 'etiquetas_buscar' => ['etiquetas', 'listar'],
            'etiquetas_sku' => ['etiquetas', 'listar'], 'etiquetas_fila' => ['etiquetas', 'ver'],
            'etiquetas_jobs' => ['etiquetas', 'historico'], 'etiquetas_job' => ['etiquetas', 'historico'],
            'etiquetas_historico' => ['etiquetas', 'historico'], 'etiquetas_fila_salvar' => ['etiquetas', 'editar'],
            'etiquetas_adicionar' => ['etiquetas', 'criar'], 'etiquetas_material_salvar' => ['etiquetas', 'admin'],
            'etiquetas_importar_previa' => ['etiquetas', 'criar'], 'etiquetas_importar_confirmar' => ['etiquetas', 'criar'],
            'etiquetas_registrar_impressao' => ['etiquetas', 'imprimir'], 'etiquetas_reimprimir' => ['etiquetas', 'imprimir'],
            // coletas
            'coletas_listar' => ['coletas', 'listar'], 'coletas_dashboard' => ['coletas', 'ver'],
            'coletas_rota' => ['coletas', 'listar'], 'coletas_mapa' => ['coletas', 'listar'],
            'coletas_agenda' => ['coletas', 'listar'], 'coletas_detalhe' => ['coletas', 'detalhe'],
            'coletas_config' => ['coletas', 'ver'], 'coletas_salvar' => ['coletas', 'criar'],
            'coletas_status' => ['coletas', 'status'], 'coletas_geocodar' => ['coletas', 'editar'],
            'coletas_retorno' => ['coletas', 'status'], 'coletas_excluir' => ['coletas', 'excluir'],
            'coletas_importar' => ['coletas', 'criar'], 'coletas_importar_previa' => ['coletas', 'criar'], 'coletas_importar_previa_partes' => ['coletas', 'criar'], 'coletas_localizar_fornecedores' => ['coletas', 'editar'], 'coletas_importar_confirmar' => ['coletas', 'criar'], 'coletas_config_save' => ['coletas', 'admin'],
            'coletas_rota_escolhida' => ['coletas', 'listar'], 'coletas_km_media' => ['coletas', 'listar'], 'coletas_rota_ativa' => ['coletas', 'listar'], 'coletas_rota_iniciar' => ['coletas', 'editar'], 'coletas_rota_encerrar' => ['coletas', 'editar'], 'coletas_posicao' => ['coletas', 'status'], 'coletas_motorista_info' => ['coletas', 'listar'], 'coletas_motorista_gerar' => ['coletas', 'editar'], 'coletas_motorista_revogar' => ['coletas', 'editar'], 'coletas_motorista_liberar' => ['coletas', 'editar'], 'coletas_rota_por_pcs' => ['coletas', 'criar'], 'coletas_rotas_resumo' => ['coletas', 'listar'],
            // agenda
            'agenda_listar' => ['agenda', 'listar'], 'agenda_dashboard' => ['agenda', 'ver'],
            'agenda_calendario' => ['agenda', 'listar'], 'agenda_sugestoes' => ['agenda', 'listar'],
            'agenda_detalhe' => ['agenda', 'detalhe'], 'agenda_salvar' => ['agenda', 'criar'],
            'agenda_concluir' => ['agenda', 'finalizar'], 'agenda_cancelar' => ['agenda', 'status'],
            'agenda_verificar' => ['agenda', 'admin'],
            // notificações
            'notify_inbox' => ['notificacoes', 'listar'], 'notify_history' => ['notificacoes', 'historico'],
            'notify_stats' => ['notificacoes', 'ver'], 'notify_heartbeat' => ['notificacoes', 'ver'],
            'notify_read' => ['notificacoes', 'listar'], 'notify_test' => ['notificacoes', 'ver'],
            'notify_publica' => ['notificacoes', 'criar'], 'notify_publicar' => ['notificacoes', 'criar'],
            // backup/administração
            'backup_diagnostico' => ['administracao', 'ver'], 'backup_listar' => ['administracao', 'listar'],
            'backup_verificar' => ['administracao', 'historico'], 'backup_baixar' => ['administracao', 'baixar'],
            'backup_agora' => ['administracao', 'criar'], 'backup_config' => ['administracao', 'admin'],
            'backup_limpar_antigos' => ['administracao', 'excluir'],
            // web push (self-service do próprio dispositivo)
            'push_public_key' => ['notificacoes', 'ver'], 'push_subscribe' => ['notificacoes', 'ver'],
            'push_unsubscribe' => ['notificacoes', 'ver'],
        ];
    }
    if (isset($exatas[$action])) return $exatas[$action];
    // prefixos de segurança: qualquer rota nova desses módulos exige, no mínimo, 'ver'
    foreach ([['estoque_', 'estoque'], ['etiquetas_', 'etiquetas'], ['coletas_', 'coletas'],
              ['agenda_', 'agenda'], ['notify_', 'notificacoes'], ['backup_', 'administracao'],
              ['usuarios_', 'usuarios'], ['permissoes_', 'usuarios']] as [$p, $m]) {
        if (strncmp($action, $p, strlen($p)) === 0) return [$m, 'ver'];
    }
    return null;
}

/**
 * PORTÃO. Chamado por api.php antes de rotear qualquer ação.
 * Rotas de sessão (auth_*) e públicas passam direto.
 */
function rd_gate_action(string $action): void {
    if (strncmp($action, 'auth_', 5) === 0) return;   // login/logout/status/usuários da sessão
    $rota = rd_rota($action);
    if ($rota === null) return;                        // ação desconhecida: o api.php responde 400 depois
    [$modulo, $operacao] = $rota;
    rd_gate($modulo, $operacao);
}

/* ------------------------------------------------------------------ */
/* Filtro de escopo sobre registros                                    */
/* ------------------------------------------------------------------ */

/**
 * Aplica o escopo do usuário a uma lista de registros.
 * Só usa campos que já existem nos dados: criado_por / usuario / responsavel / coletor.
 * Setor e unidade ainda não existem no banco → tratados como "todos" (documentado).
 */
function rd_filtrar_escopo(array $rows, string $modulo, string $campoUsuario = ''): array {
    $esc = rd_escopo($modulo);
    if ($esc === 'todos' || $esc === 'setor' || $esc === 'unidade') return $rows;
    $id = rd_identity();
    if ($id === null) return $rows;
    $campos = $campoUsuario !== '' ? [$campoUsuario] : ['criado_por', 'usuario', 'responsavel', 'coletor', 'cadastrado_por'];
    return array_values(array_filter($rows, function ($r) use ($campos, $id) {
        if (!is_array($r)) return true;
        foreach ($campos as $c) {
            if (isset($r[$c]) && (string)$r[$c] === $id['username']) return true;
        }
        // registro sem dono identificado fica visível (não se perde operação por metadado ausente)
        foreach ($campos as $c) if (array_key_exists($c, $r)) return false;
        return true;
    }));
}

/* ------------------------------------------------------------------ */
/* Perfil: leitura e comparação (sem carregar o arquivo inteiro)        */
/* ------------------------------------------------------------------ */

function rd_perfil_permite(string $perfilId, string $modulo, string $operacao): bool {
    if ($perfilId === '') return false;
    $db = rd_perm_read();
    return !empty($db['perfis'][$perfilId]['perm'][$modulo][$operacao]);
}

/** Quantos usuários ativos usam um perfil que pode administrar usuários. */
function rd_contar_admins_ativos(): int {
    $n = 0;
    $admin = function_exists('rd_admin_data') ? rd_admin_data() : [];
    if (!empty($admin['username']) && empty($admin['must_change_password'])) return 1; // admin principal conta
    $users = function_exists('rd_user_data') ? rd_user_data() : [];
    $db = rd_perm_read();
    foreach ($users as $name => $u) {
        if (empty($u['enabled']) || !empty($u['bloqueado'])) continue;
        $p = $db['usuarios'][$name]['perfil'] ?? '';
        if ($p === 'admin_principal' || rd_perfil_permite((string)$p, 'usuarios', 'admin')) $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ */
/* Sessões ativas (registro para invalidar/encerrar)                   */
/* ------------------------------------------------------------------ */

function rd_sec_sessoes(): array {
    $d = is_file(SESS_FILE) ? json_decode((string)@file_get_contents(SESS_FILE), true) : null;
    if (!is_array($d)) return [];
    $agora = time();
    return array_values(array_filter($d, fn($s) => (int)($s['ultima_vista'] ?? 0) > $agora - 3600));
}

function rd_sessao_sid(): string {
    $sid = function_exists('session_id') ? (string)session_id() : '';
    return $sid === '' ? '' : substr(hash('sha256', $sid), 0, 32);
}

/** Registra/atualiza a sessão do usuário logado. Chamado a cada requisição autenticada. */
function rd_sessao_tocar(string $username, string $perfil): void {
    $sid = rd_sessao_sid();
    if ($sid === '') return;
    $d = rd_sec_sessoes();
    $achou = false; $mudou = false;
    foreach ($d as &$s) {
        if (($s['sid'] ?? '') === $sid) {
            $achou = true;
            if (time() - (int)($s['ultima_vista'] ?? 0) >= 60 || ($s['usuario'] ?? '') !== $username) {
                $s['ultima_vista'] = time(); $s['usuario'] = $username; $mudou = true;
            }
        }
    }
    unset($s);
    if ($achou && !$mudou) return;   // não reescreve o arquivo a cada requisição
    if (!$achou) $d[] = ['sid' => $sid, 'usuario' => $username, 'perfil' => $perfil,
        'inicio' => time(), 'ultima_vista' => time(), 'invalidada' => false,
        'ip' => substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16)];
    if (count($d) > 500) $d = array_slice($d, -500);
    $tmp = SESS_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) { @chmod($tmp, 0600); @rename($tmp, SESS_FILE); }
}

/** A sessão atual foi encerrada pelo administrador? */
function rd_sessao_invalidada(): bool {
    $sid = rd_sessao_sid();
    if ($sid === '') return false;
    foreach (rd_sec_sessoes() as $s) if (($s['sid'] ?? '') === $sid && !empty($s['invalidada'])) return true;
    return false;
}

function rd_sec_terminar_sessoes(string $username, string $por): int {
    $d = rd_sec_sessoes(); $n = 0;
    foreach ($d as &$s) if (($s['usuario'] ?? '') === $username) { $s['invalidada'] = true; $s['invalidada_por'] = $por; $n++; }
    unset($s);
    $tmp = SESS_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) { @chmod($tmp, 0600); @rename($tmp, SESS_FILE); }
    return $n;
}

/**
 * Guarda de escrita dos módulos: usa a matriz quando a exigência de login está ativa e
 * cai no papel legado enquanto o sistema roda sem login. Assim a v3.7.0 não quebra
 * instalação nenhuma — o controle só aperta quando você liga o login.
 */
function rd_perm_or_legado(string $modulo, string $operacao, array $legadoRoles): bool {
    if (rd_exigir_login()) return rd_perm($modulo, $operacao);
    $id = rd_identity();
    if ($id === null) return true;                       // operador local (sem login)
    return in_array($id['role'], $legadoRoles, true);
}
