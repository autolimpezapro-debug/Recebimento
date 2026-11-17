<?php
/**
 * Recebimento Drilling — Sistema de Notificações em tempo real (v3.1.0)
 * ---------------------------------------------------------------------
 * Objetivo: quando uma CONFERÊNCIA É FINALIZADA, todos os operadores logados
 * recebem o aviso — em qualquer tela, em qualquer dispositivo.
 *
 * Como funciona
 *  1. Cada navegador logado envia um "sinal de presença" (heartbeat) a cada 20s.
 *  2. A finalização grava a notificação em data/notifications.json e, para cada
 *     dispositivo online, cria uma "entrega" (entrega = caixa de entrada do dispositivo).
 *  3. O dispositivo consulta a cada 5s (e imediatamente após o heartbeat) e recebe
 *     tudo o que ainda não leu: sino, banner, som e notificação do sistema.
 *
 * Garantias
 *  - Nada é disparado retroativamente: o cursor começa no último id existente.
 *  - A entrega é POR DISPOSITIVO, então quem estava offline ou em outra aba
 *    recebe ao voltar; o aviso nunca se perde no meio de um polling.
 *  - Autodeclaração: o autor da finalização também recebe (todas as sessões logadas).
 *  - Arquivos novos (data/notifications.json, data/devices.json). Nenhum dado de
 *    pedido, conferência, foto ou NF é alterado por este módulo.
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const NOTIF_FILE   = DATA_DIR . '/notifications.json';
const NOTIF_DEV    = DATA_DIR . '/devices.json';
const NOTIF_LOCK   = DATA_DIR . '/.notify.lock';
const NOTIF_MAX    = 400;    // notificações mantidas
const NOTIF_MAX_DEV = 300;   // dispositivos rastreados
const NOTIF_ONLINE_S = 90;   // considerado online se visto nos últimos 90s
const NOTIF_TTL_DIAS = 45;   // entrega não lida expira depois disso

const NOTIF_TIPOS = [
    'recebimento'   => 'Conferência finalizada',
    'parcial'       => 'Recebimento parcial',
    'estoque'       => 'Movimentação de estoque',
    'estoque_baixo' => 'Item abaixo do mínimo',
    'sistema'       => 'Aviso do sistema',
];

/* ------------------------------------------------------------------ */
/* Base                                                                */
/* ------------------------------------------------------------------ */

function notif_empty_db() {
    return ['versao' => 1, 'last_id' => 0, 'notificacoes' => [], 'dispositivos' => []];
}

function notif_read() {
    if (!is_file(NOTIF_FILE)) return notif_empty_db();
    $raw = @file_get_contents(NOTIF_FILE);
    if ($raw === false || $raw === '') return notif_empty_db();
    $data = json_decode($raw, true);
    if (!is_array($data)) return notif_empty_db();
    return array_merge(notif_empty_db(), $data);
}

function notif_write(array $db) {
    $tmp = NOTIF_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        respond(['ok' => false, 'error' => 'Não foi possível gravar as notificações'], 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, NOTIF_FILE)) respond(['ok' => false, 'error' => 'Falha ao persistir notificações'], 500);
}

function notif_with_lock(callable $fn) {
    $fp = @fopen(NOTIF_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) respond(['ok' => false, 'error' => 'Trava de notificações indisponível'], 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/**
 * Mesma trava, mas sem responder ao cliente: usada dentro de outra gravação
 * (ex.: finalização do recebimento) para que a notificação seja apenas o melhor
 * esforço — uma falha aqui não interrompe o recebimento já gravado.
 */
function notif_try_lock(callable $fn) {
    $fp = @fopen(NOTIF_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('Trava de notificações indisponível');
    }
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/* ------------------------------------------------------------------ */
/* Dispositivos (sessões logadas)                                      */
/* ------------------------------------------------------------------ */

function notif_device_id($raw) {
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$raw);
    return mb_substr((string)$id, 0, 64);
}

/** Exige um device_id com o mesmo formato gerado no navegador (dev_ + 28 chars). */
function notif_device_required($raw) {
    $id = notif_device_id($raw);
    if (!preg_match('/^[A-Za-z0-9_-]{12,64}$/', $id)) {
        respond(['ok' => false, 'error' => 'Identificador do dispositivo inválido'], 422);
    }
    return $id;
}

function notif_user_name() {
    if (function_exists('rd_login_info')) {
        $id = rd_login_info();
        if (is_array($id) && !empty($id['username'])) return (string)$id['username'];
    }
    return 'operador-local';
}

function notif_user_role() {
    if (function_exists('rd_login_info')) {
        $id = rd_login_info();
        if (is_array($id) && !empty($id['role'])) return (string)$id['role'];
    }
    return 'gestor';
}

/** Registra presença. Nunca sobrescreve as preferências do dispositivo. */
function notif_heartbeat(array $input) {
    $devId = notif_device_required($input['device_id'] ?? '');
    $user = notif_user_name();
    $role = notif_user_role();
    return notif_with_lock(function () use ($devId, $user, $role, $input) {
        $db = notif_read();
        $dev = $db['dispositivos'][$devId] ?? [
            'id' => $devId, 'primeiro_acesso' => date('c'),
            'cursor' => (int)$db['last_id'],
        ];
        $dev['usuario'] = $user;
        $dev['perfil'] = $role;
        $dev['visto_em'] = date('c');
        $dev['visto_ts'] = time();
        if (!empty($input['navegador'])) $dev['navegador'] = mb_substr((string)$input['navegador'], 0, 160);
        if (!empty($input['pagina'])) $dev['pagina'] = mb_substr((string)$input['pagina'], 0, 60);
        if (isset($input['preferencias']) && is_array($input['preferencias'])) {
            $p = $input['preferencias'];
            $dev['preferencias'] = [
                'som' => !empty($p['som']),
                'navegador' => !empty($p['navegador']),
                'categorias' => array_values(array_intersect(
                    array_keys(NOTIF_TIPOS),
                    array_map('strval', (array)($p['categorias'] ?? []))
                )),
            ];
        }
        if (!isset($dev['preferencias'])) {
            $dev['preferencias'] = ['som' => true, 'navegador' => true, 'categorias' => array_keys(NOTIF_TIPOS)];
        }
        // Mantém a lista enxuta: remove dispositivos inativos há mais de 30 dias.
        foreach ($db['dispositivos'] as $id => $d) {
            if (time() - (int)($d['visto_ts'] ?? 0) > 30 * 86400) unset($db['dispositivos'][$id]);
        }
        if (!isset($db['dispositivos'][$devId]) && count($db['dispositivos']) >= NOTIF_MAX_DEV) {
            // Descarta o mais antigo para abrir espaço.
            uasort($db['dispositivos'], fn($a, $b) => (int)($a['visto_ts'] ?? 0) <=> (int)($b['visto_ts'] ?? 0));
            $oldest = array_key_first($db['dispositivos']);
            if ($oldest !== null) unset($db['dispositivos'][$oldest]);
        }
        $db['dispositivos'][$devId] = $dev;
        notif_write($db);
        return ['dispositivo' => $dev, 'online' => notif_online_count($db)];
    });
}

function notif_online_count(array $db) {
    $n = 0;
    foreach ($db['dispositivos'] as $d) {
        if (time() - (int)($d['visto_ts'] ?? 0) <= NOTIF_ONLINE_S) $n++;
    }
    return $n;
}

function notif_device_online(array $dev) {
    return time() - (int)($dev['visto_ts'] ?? 0) <= NOTIF_ONLINE_S;
}

/** Uma notificação está visível para o dispositivo? (preferências de categoria) */
function notif_device_wants(array $dev, array $notif) {
    $cats = $dev['preferencias']['categorias'] ?? array_keys(NOTIF_TIPOS);
    return in_array($notif['tipo'], $cats, true);
}

/* ------------------------------------------------------------------ */
/* Publicação                                                          */
/* ------------------------------------------------------------------ */

/**
 * Publica uma notificação para TODOS os dispositivos logados.
 * $alvo: 'todos' (padrão) | 'outros' (ignora o autor)
 * Retorna a notificação criada (com id).
 */
function notif_publish(array $data) {
    $tipo = (string)($data['tipo'] ?? 'sistema');
    if (!isset(NOTIF_TIPOS[$tipo])) $tipo = 'sistema';
    $titulo = mb_substr(trim((string)($data['titulo'] ?? NOTIF_TIPOS[$tipo])), 0, 160);
    $texto  = mb_substr(trim((string)($data['texto'] ?? '')), 0, 600);
    if ($titulo === '') $titulo = NOTIF_TIPOS[$tipo];
    $notifCriada = notif_try_lock(function () use ($tipo, $titulo, $texto, $data) {
        $db = notif_read();
        $id = (int)$db['last_id'] + 1;
        $now = date('c');
        $notif = [
            'id' => $id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'texto' => $texto,
            'pc' => isset($data['pc']) ? (int)$data['pc'] : null,
            'url' => mb_substr((string)($data['url'] ?? ''), 0, 300),
            'autor' => mb_substr((string)($data['autor'] ?? 'sistema'), 0, 120),
            'origem_device' => notif_device_id($data['origem_device'] ?? ''),
            'dados' => is_array($data['dados'] ?? null) ? $data['dados'] : [],
            'em' => $now,
            'ts' => time(),
        ];
        // Entregas: uma cópia por dispositivo, respeitando a preferência de categoria.
        $notif['entregas'] = [];
        $alvo = (string)($data['alvo'] ?? 'todos');
        foreach ($db['dispositivos'] as $devId => $dev) {
            if ($alvo === 'outros' && $devId === $notif['origem_device']) continue;
            if (!notif_device_wants($dev, $notif)) continue;
            $notif['entregas'][$devId] = ['visto_em' => null, 'lido_em' => null, 'online' => notif_device_online($dev)];
        }
        $db['last_id'] = $id;
        $db['notificacoes'][] = $notif;
        // Retenção: mantém as mais recentes.
        if (count($db['notificacoes']) > NOTIF_MAX) {
            $db['notificacoes'] = array_slice($db['notificacoes'], -NOTIF_MAX);
        }
        notif_write($db);
        return $notif;
    });
    // v3.47.0: além do sino (inalterado), toda notificação sai também por Web Push para os aparelhos inscritos,
    // mesmo sem sessão aberta. Recebimento/parcial já têm o seu push na finalização (push_queue_received).
    if (is_array($notifCriada) && ($data['push'] ?? true) !== false && !in_array($tipo, ['recebimento', 'parcial'], true)) {
        notif_push_enviar($notifCriada);
    }
    return $notifCriada;
}

/** Enfileira e dispara o Web Push de uma notificação. Falha aqui nunca derruba quem publicou. */
function notif_push_enviar(array $notif) {
    try {
        require_once __DIR__ . '/push_lib.php';
        push_queue_notificacao($notif);
        if (empty($GLOBALS['RD_SKIP_PUSH_INLINE'])) {
            register_shutdown_function(function () {
                if (function_exists('rd_liberar_cliente')) { try { rd_liberar_cliente(); } catch (Throwable $e) {} }
                try { push_process_queue(20, 8); } catch (Throwable $e) { error_log('Web Push: ' . $e->getMessage()); }
            });
        }
        return true;
    } catch (Throwable $e) {
        error_log('Notificação: falha ao enfileirar Web Push: ' . $e->getMessage());
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Consulta por dispositivo (caixa de entrada)                         */
/* ------------------------------------------------------------------ */

function notif_inbox($devId, $since = null) {
    $devId = notif_device_id($devId);
    if ($devId === '') respond(['ok' => false, 'error' => 'device_id obrigatório'], 422);
    $db = notif_read();
    $dev = $db['dispositivos'][$devId] ?? null;
    $limite = $since === null ? (int)($dev['cursor'] ?? $db['last_id']) : max(0, (int)$since);
    $novas = [];
    $naoLidas = 0;
    foreach ($db['notificacoes'] as $n) {
        if (!isset($n['entregas'][$devId])) continue;
        if (empty($n['entregas'][$devId]['lido_em'])) $naoLidas++;
        if ((int)$n['id'] > $limite) {
            $item = $n;
            $item['lido'] = !empty($n['entregas'][$devId]['lido_em']);
            $item['entregas'] = count($n['entregas'] ?? []);
            $item['lidas_por'] = count(array_filter($n['entregas'] ?? [], fn($e) => !empty($e['lido_em'])));
            $novas[] = $item;
        }
    }
    // Cursor do dispositivo: só avança até a maior notificação realmente entregue a ele,
    // para que um dispositivo offline receba o que perdeu ao voltar.
    $cursor = $limite;
    foreach ($db['notificacoes'] as $n) {
        if (isset($n['entregas'][$devId]) && (int)$n['id'] > $cursor) $cursor = (int)$n['id'];
    }
    if ($cursor < $limite) $cursor = $limite;
    // Marca como "vistas" (chegaram na tela) — lidas é ação do usuário.
    if ($novas) {
        $mudou = false;
        foreach ($db['notificacoes'] as &$n) {
            if (isset($n['entregas'][$devId]) && (int)$n['id'] > $limite && empty($n['entregas'][$devId]['visto_em'])) {
                $n['entregas'][$devId]['visto_em'] = date('c');
                $mudou = true;
            }
        }
        unset($n);
        if ($mudou) notif_write($db);
    }
    return [
        'novas' => $novas,
        'cursor' => $cursor,
        'nao_lidas' => $naoLidas,
        'online' => notif_online_count($db),
        'dispositivo' => $dev,
        'tipos' => NOTIF_TIPOS,
        'agora' => date('c'),
    ];
}

/** Histórico recente para o painel do sino (independente do cursor). */
function notif_history($devId, $limite = 40) {
    $devId = notif_device_id($devId);
    $db = notif_read();
    $out = [];
    foreach (array_reverse($db['notificacoes']) as $n) {
        if ($devId !== '' && !isset($n['entregas'][$devId])) continue;
        $item = $n;
        $item['lido'] = $devId !== '' ? !empty($n['entregas'][$devId]['lido_em']) : false;
        $item['entregas'] = count($n['entregas'] ?? []);
        $item['lidas_por'] = count(array_filter($n['entregas'] ?? [], fn($e) => !empty($e['lido_em'])));
        unset($item['entregas']);
        $out[] = $item;
        if (count($out) >= $limite) break;
    }
    return ['notificacoes' => $out, 'online' => notif_online_count($db), 'tipos' => NOTIF_TIPOS];
}

function notif_mark_read($devId, array $ids = []) {
    $devId = notif_device_id($devId);
    return notif_with_lock(function () use ($devId, $ids) {
        $db = notif_read();
        $now = date('c');
        $marcadas = 0;
        foreach ($db['notificacoes'] as &$n) {
            if (!isset($n['entregas'][$devId])) continue;
            if (!empty($n['entregas'][$devId]['lido_em'])) continue;
            if ($ids && !in_array((int)$n['id'], array_map('intval', $ids), true)) continue;
            $n['entregas'][$devId]['lido_em'] = $now;
            $marcadas++;
        }
        unset($n);
        notif_write($db);
        return ['marcadas' => $marcadas];
    });
}

function notif_mark_all_read($devId) {
    return notif_mark_read($devId, []);
}

/** Estatística simples para a tela de configuração. */
function notif_stats() {
    $db = notif_read();
    $porTipo = [];
    foreach (NOTIF_TIPOS as $k => $v) $porTipo[$k] = 0;
    foreach ($db['notificacoes'] as $n) {
        $porTipo[$n['tipo']] = ($porTipo[$n['tipo']] ?? 0) + 1;
    }
    $dispositivos = [];
    foreach ($db['dispositivos'] as $d) {
        $dispositivos[] = [
            'id' => substr($d['id'], 0, 8) . '…',
            'usuario' => $d['usuario'] ?? '',
            'perfil' => $d['perfil'] ?? '',
            'navegador' => $d['navegador'] ?? '',
            'pagina' => $d['pagina'] ?? '',
            'visto_em' => $d['visto_em'] ?? null,
            'online' => notif_device_online($d),
            'preferencias' => $d['preferencias'] ?? null,
        ];
    }
    usort($dispositivos, fn($a, $b) => strcmp((string)$b['visto_em'], (string)$a['visto_em']));
    return [
        'total_notificacoes' => count($db['notificacoes']),
        'por_tipo' => $porTipo,
        'dispositivos' => $dispositivos,
        'online' => notif_online_count($db),
        'tipos' => NOTIF_TIPOS,
    ];
}

/** Dispara Web Push (fora do navegador) para os inscritos, sem bloquear a resposta. */
function notif_push_broadcast(array $notif) {
    if (!function_exists('push_queue_received')) return false;
    try {
        push_queue_received((int)($notif['pc'] ?? 0), $notif['tipo'] === 'parcial' ? 'PARCIAL' : 'SIM');
        return true;
    } catch (Throwable $e) {
        error_log('Notificação: falha ao enfileirar Web Push: ' . $e->getMessage());
        return false;
    }
}
