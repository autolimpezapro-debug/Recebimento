<?php
/**
 * Recebimento Drilling — Agenda Inteligente (v3.3.0)
 * ---------------------------------------------------
 * Nem toda entrega/retirada acontece no dia do pedido. Este módulo programa
 * a data (e janela de horário) em que o material deve ser retirado ou entregue,
 * monta a fila do dia e avisa a equipe.
 *
 * Estrutura de data/agenda.json:
 * {
 *   "versao": 1,
 *   "agendamentos": {
 *      "PC-4850": {
 *         pc, data (Y-m-d), janela (MANHA|TARDE|DIA|NOITE), hora (HH:MM opcional),
 *         tipo (RETIRADA|ENTREGA|DEVOLUCAO|MANUTENCAO),
 *         local, responsavel, contato, prioridade (BAIXA|NORMAL|ALTA|URGENTE),
 *         status (PENDENTE|CONFIRMADO|CONCLUIDO|CANCELADO),
 *         observacao, criado_em, atualizado_em, criado_por, historico[],
 *         avisos { d1: bool, hoje: bool, atraso: bool }
 *      }
 *   },
 *   "sequencia": { "evento": 0 },
 *   "ultima_verificacao": "Y-m-d H:i"
 * }
 *
 * Regras:
 *  - Um PC tem no máximo UM agendamento ativo (reagendar substitui, com histórico).
 *  - A agenda NUNCA altera pedidos, conferências, fotos, NF ou estoque.
 *  - Ao finalizar o recebimento, o agendamento pendente do PC é concluído sozinho.
 *  - Os avisos (véspera, dia e atraso) são disparados pelo verificador, que pode
 *    rodar por cron (agenda_worker.php) ou ao abrir o sistema.
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const AG_FILE  = DATA_DIR . '/agenda.json';
const AG_LOCK  = DATA_DIR . '/.agenda.lock';

const AG_JANELAS = ['MANHA' => 'Manhã (08:00–12:00)', 'TARDE' => 'Tarde (13:00–18:00)', 'DIA' => 'Dia inteiro', 'NOITE' => 'Noite (18:00–22:00)'];
const AG_TIPOS = ['RETIRADA' => 'Retirada de material', 'ENTREGA' => 'Entrega do fornecedor', 'DEVOLUCAO' => 'Devolução', 'MANUTENCAO' => 'Manutenção / serviço'];
const AG_STATUS = ['PENDENTE' => 'Agendado', 'CONFIRMADO' => 'Confirmado', 'CONCLUIDO' => 'Concluído', 'CANCELADO' => 'Cancelado'];
const AG_PRIORIDADES = ['BAIXA' => 'Baixa', 'NORMAL' => 'Normal', 'ALTA' => 'Alta', 'URGENTE' => 'Urgente'];

/* ------------------------------------------------------------------ */
/* Base                                                               */
/* ------------------------------------------------------------------ */

function ag_empty() {
    return ['versao' => 1, 'agendamentos' => [], 'sequencia' => ['evento' => 0], 'ultima_verificacao' => null];
}

function ag_read() {
    if (!is_file(AG_FILE)) return ag_empty();
    $raw = @file_get_contents(AG_FILE);
    if ($raw === false || $raw === '') return ag_empty();
    $d = json_decode($raw, true);
    if (!is_array($d)) return ag_empty();
    return array_merge(ag_empty(), $d);
}

function ag_write(array $db) {
    $tmp = AG_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        respond(['ok' => false, 'error' => 'Não foi possível gravar a agenda'], 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, AG_FILE)) respond(['ok' => false, 'error' => 'Falha ao persistir a agenda'], 500);
}

function ag_with_lock(callable $fn) {
    $fp = @fopen(AG_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) respond(['ok' => false, 'error' => 'Trava da agenda indisponível'], 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function ag_try_lock(callable $fn) {
    $fp = @fopen(AG_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) { if ($fp) fclose($fp); throw new RuntimeException('Trava da agenda indisponível'); }
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/* ------------------------------------------------------------------ */
/* Utilidades de data                                                 */
/* ------------------------------------------------------------------ */

function ag_hoje() { return date('Y-m-d'); }
function ag_agora() { return date('c'); }

function ag_valida_data($d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) return false;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/** Dias entre hoje e a data (negativo = atrasado). */
function ag_dias($data) {
    if (!ag_valida_data($data)) return null;
    $hoje = new DateTimeImmutable(ag_hoje());
    $alvo = new DateTimeImmutable($data);
    return (int)$hoje->diff($alvo)->format('%r%a');
}

function ag_str($v, $max = 200) { return mb_substr(trim((string)$v), 0, $max); }

function ag_key($pc) { return 'PC-' . (int)$pc; }

/** Status efetivo calculado (nunca confiar só no campo salvo). */
function ag_status_efetivo(array $a) {
    if (in_array($a['status'] ?? '', ['CONCLUIDO', 'CANCELADO'], true)) return $a['status'];
    $d = ag_dias($a['data'] ?? '');
    if ($d === null) return $a['status'] ?? 'PENDENTE';
    if ($d < 0) return 'ATRASADO';
    if ($d === 0) return 'HOJE';
    if ($d === 1) return 'AMANHA';
    return $a['status'] ?? 'PENDENTE';
}

/* ------------------------------------------------------------------ */
/* Gravação                                                           */
/* ------------------------------------------------------------------ */

function ag_salvar(array $input, $usuario) {
    $pc = (int)($input['pc'] ?? 0);
    if ($pc <= 0) respond(['ok' => false, 'error' => 'Informe o número do PC'], 422);
    $data = (string)($input['data'] ?? '');
    if (!ag_valida_data($data)) respond(['ok' => false, 'error' => 'Data inválida. Use o formato AAAA-MM-DD.'], 422);

    $janela = strtoupper((string)($input['janela'] ?? 'DIA'));
    if (!isset(AG_JANELAS[$janela])) $janela = 'DIA';
    $tipo = strtoupper((string)($input['tipo'] ?? 'RETIRADA'));
    if (!isset(AG_TIPOS[$tipo])) $tipo = 'RETIRADA';
    $prioridade = strtoupper((string)($input['prioridade'] ?? 'NORMAL'));
    if (!isset(AG_PRIORIDADES[$prioridade])) $prioridade = 'NORMAL';
    $status = strtoupper((string)($input['status'] ?? 'PENDENTE'));
    if (!isset(AG_STATUS[$status])) $status = 'PENDENTE';
    $hora = (string)($input['hora'] ?? '');
    if ($hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = '';

    return ag_with_lock(function () use ($pc, $data, $janela, $tipo, $prioridade, $status, $hora, $input, $usuario) {
        $db = ag_read();
        $key = ag_key($pc);
        $now = ag_agora();
        $novo = !isset($db['agendamentos'][$key]);
        $antigo = $novo ? null : $db['agendamentos'][$key];

        $ag = $antigo ?? [
            'pc' => $pc,
            'criado_em' => $now,
            'criado_por' => $usuario,
            'historico' => [],
            'avisos' => ['d1' => false, 'hoje' => false, 'atraso' => false],
        ];
        // Histórico: guarda o estado anterior ao reagendamento.
        if ($antigo && (($antigo['data'] ?? '') !== $data || ($antigo['janela'] ?? '') !== $janela || ($antigo['hora'] ?? '') !== $hora)) {
            $ag['historico'][] = [
                'em' => $now, 'por' => $usuario, 'acao' => 'reagendado',
                'de' => ['data' => $antigo['data'] ?? '', 'janela' => $antigo['janela'] ?? '', 'hora' => $antigo['hora'] ?? ''],
                'para' => ['data' => $data, 'janela' => $janela, 'hora' => $hora],
            ];
            // Reagendou: os avisos precisam ser reemitidos.
            $ag['avisos'] = ['d1' => false, 'hoje' => false, 'atraso' => false];
        }
        $ag['data'] = $data;
        $ag['janela'] = $janela;
        $ag['hora'] = $hora;
        $ag['tipo'] = $tipo;
        $ag['local'] = ag_str($input['local'] ?? ($antigo['local'] ?? ''), 160);
        $ag['responsavel'] = ag_str($input['responsavel'] ?? ($antigo['responsavel'] ?? ''), 120);
        $ag['contato'] = ag_str($input['contato'] ?? ($antigo['contato'] ?? ''), 60);
        $ag['prioridade'] = $prioridade;
        $ag['status'] = $status;
        $ag['observacao'] = ag_str($input['observacao'] ?? ($antigo['observacao'] ?? ''), 600);
        $ag['atualizado_em'] = $now;
        $ag['atualizado_por'] = $usuario;
        if ($status === 'CONCLUIDO' && empty($ag['concluido_em'])) $ag['concluido_em'] = $now;
        if ($status !== 'CONCLUIDO') unset($ag['concluido_em']);

        $db['agendamentos'][$key] = $ag;
        ag_write($db);
        return ['agendamento' => ag_view($ag), 'novo' => $novo];
    });
}

function ag_concluir($pc, $usuario, $origem = 'manual') {
    return ag_with_lock(function () use ($pc, $usuario, $origem) {
        $db = ag_read();
        $key = ag_key($pc);
        if (!isset($db['agendamentos'][$key])) return null;
        $ag = $db['agendamentos'][$key];
        if (($ag['status'] ?? '') === 'CONCLUIDO') return ag_view($ag);
        $now = ag_agora();
        $ag['status'] = 'CONCLUIDO';
        $ag['concluido_em'] = $now;
        $ag['atualizado_em'] = $now;
        $ag['atualizado_por'] = $usuario;
        $ag['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'concluido', 'origem' => $origem];
        $db['agendamentos'][$key] = $ag;
        ag_write($db);
        return ag_view($ag);
    });
}

function ag_cancelar($pc, $usuario, $motivo = '') {
    return ag_with_lock(function () use ($pc, $usuario, $motivo) {
        $db = ag_read();
        $key = ag_key($pc);
        if (!isset($db['agendamentos'][$key])) respond(['ok' => false, 'error' => 'Agendamento não encontrado'], 404);
        $ag = $db['agendamentos'][$key];
        $now = ag_agora();
        $ag['status'] = 'CANCELADO';
        $ag['atualizado_em'] = $now;
        $ag['atualizado_por'] = $usuario;
        $ag['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'cancelado', 'motivo' => ag_str($motivo, 400)];
        $db['agendamentos'][$key] = $ag;
        ag_write($db);
        return ag_view($ag);
    });
}

/** Dados do PC (somente leitura) para enriquecer a agenda. */
function ag_pedidos() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (function_exists('read_items')) {
        try {
            foreach (read_items() as $p) $cache[(int)$p['pc']] = $p;
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function ag_view(array $ag) {
    $pedidos = ag_pedidos();
    $pc = (int)($ag['pc'] ?? 0);
    $p = $pedidos[$pc] ?? null;
    $ag['status_efetivo'] = ag_status_efetivo($ag);
    $ag['dias'] = ag_dias($ag['data'] ?? '');
    $ag['janela_label'] = AG_JANELAS[$ag['janela'] ?? 'DIA'] ?? 'Dia inteiro';
    $ag['tipo_label'] = AG_TIPOS[$ag['tipo'] ?? 'RETIRADA'] ?? 'Retirada';
    $ag['status_label'] = $ag['status_efetivo'] === 'ATRASADO' ? 'Atrasado'
        : ($ag['status_efetivo'] === 'HOJE' ? 'Hoje'
        : ($ag['status_efetivo'] === 'AMANHA' ? 'Amanhã'
        : (AG_STATUS[$ag['status'] ?? 'PENDENTE'] ?? 'Agendado')));
    $ag['fornecedor'] = $p['fornecedor'] ?? '';
    $ag['solicitante'] = $p['solicitante'] ?? '';
    $ag['material'] = $p['material'] ?? '';
    $ag['numeroNF'] = $p['numeroNF'] ?? '';
    $ag['recebimento_estado'] = $p['recebimento_estado'] ?? '';
    $ag['chegou'] = $p['chegou'] ?? '';
    $ag['insumos'] = count($p['insumos'] ?? []);
    $ag['pendentes'] = 0;
    foreach (($p['insumos'] ?? []) as $l) if ((float)($l['quantidade_pendente'] ?? 0) > 0.00001) $ag['pendentes']++;
    $ag['primeira_linha'] = '';
    foreach (($p['insumos'] ?? []) as $l) { $ag['primeira_linha'] = (string)($l['descricao'] ?? ''); break; }
    return $ag;
}

/* ------------------------------------------------------------------ */
/* Consultas                                                          */
/* ------------------------------------------------------------------ */

function ag_listar(array $q) {
    $db = ag_read();
    $ini = (string)($q['de'] ?? '');
    $fim = (string)($q['ate'] ?? '');
    $statusFiltro = (string)($q['status'] ?? '');
    $busca = mb_strtolower(trim((string)($q['q'] ?? '')), 'UTF-8');
    $out = [];
    foreach ($db['agendamentos'] as $ag) {
        $v = ag_view($ag);
        if ($ini !== '' && ag_valida_data($ini) && ($v['data'] ?? '') < $ini) continue;
        if ($fim !== '' && ag_valida_data($fim) && ($v['data'] ?? '') > $fim) continue;
        if ($statusFiltro !== '') {
            $ef = $v['status_efetivo'];
            $derivados = ['PENDENTES', 'ATRASADOS', 'HOJE', 'ATRASADO', 'AMANHA'];
            if ($statusFiltro === 'PENDENTES' && in_array($ef, ['CONCLUIDO', 'CANCELADO'], true)) continue;
            if (in_array($statusFiltro, ['ATRASADOS', 'ATRASADO'], true) && $ef !== 'ATRASADO') continue;
            if ($statusFiltro === 'HOJE' && $ef !== 'HOJE') continue;
            if ($statusFiltro === 'AMANHA' && $ef !== 'AMANHA') continue;
            if (!in_array($statusFiltro, $derivados, true) && ($v['status'] ?? '') !== $statusFiltro) continue;
        }
        if ($busca !== '') {
            $hay = mb_strtolower(implode(' ', [(string)$v['pc'], (string)$v['fornecedor'], (string)$v['material'], (string)$v['primeira_linha'], (string)$v['responsavel'], (string)$v['local']]), 'UTF-8');
            if (mb_strpos($hay, $busca) === false) continue;
        }
        $out[] = $v;
    }
    usort($out, function ($a, $b) {
        $peso = ['ATRASADO' => 0, 'HOJE' => 1, 'AMANHA' => 2];
        $pa = $peso[$a['status_efetivo']] ?? 3;
        $pb = $peso[$b['status_efetivo']] ?? 3;
        if ($pa !== $pb) return $pa <=> $pb;
        $cmp = strcmp((string)$a['data'], (string)$b['data']);
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['hora'] ?? ''), (string)($b['hora'] ?? ''));
    });
    return ['agendamentos' => $out, 'total' => count($out), 'hoje' => ag_hoje()];
}

function ag_dashboard() {
    $db = ag_read();
    $hoje = ag_hoje();
    $amanha = (new DateTimeImmutable($hoje))->modify('+1 day')->format('Y-m-d');
    $semana = (new DateTimeImmutable($hoje))->modify('+7 day')->format('Y-m-d');
    $k = ['hoje' => 0, 'amanha' => 0, 'semana' => 0, 'atrasados' => 0, 'pendentes' => 0, 'concluidos_mes' => 0];
    $fila = [];
    $mes = date('Y-m');
    foreach ($db['agendamentos'] as $ag) {
        $v = ag_view($ag);
        $st = $v['status_efetivo'];
        if (in_array($st, ['CONCLUIDO', 'CANCELADO'], true)) {
            if ($st === 'CONCLUIDO' && substr((string)($v['concluido_em'] ?? ''), 0, 7) === $mes) $k['concluidos_mes']++;
            continue;
        }
        $k['pendentes']++;
        if ($st === 'ATRASADO') $k['atrasados']++;
        if ($v['data'] === $hoje) $k['hoje']++;
        if ($v['data'] === $amanha) $k['amanha']++;
        if ($v['data'] >= $hoje && $v['data'] <= $semana) $k['semana']++;
        if ($st === 'ATRASADO' || $v['data'] === $hoje) $fila[] = $v;
    }
    usort($fila, fn($a, $b) => strcmp((string)$a['data'], (string)$b['data']) ?: strcmp((string)($a['hora'] ?? ''), (string)($b['hora'] ?? '')));
    return ['kpis' => $k, 'fila_do_dia' => array_slice($fila, 0, 12), 'hoje' => $hoje, 'amanha' => $amanha];
}

/** Contagem por dia para o calendário do mês. */
function ag_calendario($mes) {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$mes)) $mes = date('Y-m');
    $db = ag_read();
    $mapa = [];
    foreach ($db['agendamentos'] as $ag) {
        $v = ag_view($ag);
        if (strpos((string)$v['data'], $mes) !== 0) continue;
        if (in_array($v['status_efetivo'], ['CANCELADO'], true)) continue;
        $dia = substr($v['data'], 8, 2);
        $mapa[$dia] = $mapa[$dia] ?? ['total' => 0, 'atrasados' => 0, 'concluidos' => 0, 'pcs' => []];
        $mapa[$dia]['total']++;
        if ($v['status_efetivo'] === 'ATRASADO') $mapa[$dia]['atrasados']++;
        if ($v['status_efetivo'] === 'CONCLUIDO') $mapa[$dia]['concluidos']++;
        if (count($mapa[$dia]['pcs']) < 12) $mapa[$dia]['pcs'][] = (int)$v['pc'];
    }
    return ['mes' => $mes, 'dias' => $mapa];
}

/**
 * Sugestões inteligentes para agendar: PCs ainda sem data, priorizando os que
 * já têm material pendente. A data sugerida é um ponto de partida — o operador
 * confirma ou ajusta.
 */
function ag_sugestoes($limite = 12) {
    $db = ag_read();
    $pedidos = ag_pedidos();
    $out = [];
    foreach ($pedidos as $pc => $p) {
        if (isset($db['agendamentos'][ag_key($pc)])) {
            $st = ag_status_efetivo($db['agendamentos'][ag_key($pc)]);
            if (!in_array($st, ['CONCLUIDO', 'CANCELADO'], true)) continue;
        }
        $linhas = $p['insumos'] ?? [];
        if (!$linhas) continue;
        $pend = 0;
        foreach ($linhas as $l) if ((float)($l['quantidade_pendente'] ?? 0) > 0.00001) $pend++;
        if ($pend === 0) continue;
        $out[] = [
            'pc' => (int)$pc,
            'fornecedor' => (string)($p['fornecedor'] ?? ''),
            'primeira_linha' => (string)($linhas[0]['descricao'] ?? ''),
            'insumos' => count($linhas),
            'pendentes' => $pend,
            'data_sugerida' => (new DateTimeImmutable(ag_hoje()))->modify('+2 day')->format('Y-m-d'),
            'motivo' => $pend === count($linhas) ? 'Nenhum insumo recebido ainda' : $pend . ' de ' . count($linhas) . ' insumos pendentes',
        ];
        if (count($out) >= $limite) break;
    }
    return ['sugestoes' => $out];
}

/* ------------------------------------------------------------------ */
/* Avisos automáticos (véspera, dia e atraso)                          */
/* ------------------------------------------------------------------ */

/**
 * Verifica os agendamentos e publica avisos para todos os logados.
 * Idempotente: cada aviso sai uma única vez por agendamento/dia.
 * $forcar reprocessa mesmo que já tenha rodado hoje (usado em teste).
 */
function ag_verificar_avisos(bool $forcar = false) {
    if (!is_file(__DIR__ . '/notify_lib.php')) return ['ok' => false, 'motivo' => 'notificações indisponíveis'];
    require_once __DIR__ . '/notify_lib.php';
    if (!function_exists('notif_publish')) return ['ok' => false, 'motivo' => 'notificações indisponíveis'];

    return ag_try_lock(function () use ($forcar) {
        $db = ag_read();
        $hoje = ag_hoje();
        $marca = substr((string)($db['ultima_verificacao'] ?? ''), 0, 10);
        if (!$forcar && $marca === $hoje) return ['ok' => true, 'ignorado' => true];
        $enviados = [];
        foreach ($db['agendamentos'] as $key => &$ag) {
            $st = ag_status_efetivo($ag);
            if (in_array($st, ['CONCLUIDO', 'CANCELADO'], true)) continue;
            $avisos = $ag['avisos'] ?? ['d1' => false, 'hoje' => false, 'atraso' => false];
            $d = ag_dias($ag['data'] ?? '');
            if ($d === null) continue;
            $v = ag_view($ag);
            $pc = (int)$ag['pc'];

            // Véspera
            if ($d === 1 && empty($avisos['d1'])) {
                notif_publish([
                    'tipo' => 'sistema',
                    'titulo' => 'Amanhã • ' . $v['tipo_label'] . ' do PC ' . $pc,
                    'texto' => ($v['janela_label']) . ' · ' . ($v['fornecedor'] ?: 'fornecedor não informado') . '. Prepare a conferência.',
                    'pc' => $pc, 'url' => '/?pc=' . $pc, 'autor' => 'agenda',
                ]);
                $avisos['d1'] = true;
                $enviados[] = 'd1:' . $pc;
            }
            // No dia
            if ($d === 0 && empty($avisos['hoje'])) {
                notif_publish([
                    'tipo' => 'sistema',
                    'titulo' => 'Hoje • ' . $v['tipo_label'] . ' do PC ' . $pc,
                    'texto' => $v['janela_label'] . ($v['hora'] ? ' às ' . $v['hora'] : '') . ' · ' . ($v['local'] ?: 'local não informado') . '.',
                    'pc' => $pc, 'url' => '/?pc=' . $pc, 'autor' => 'agenda',
                ]);
                $avisos['hoje'] = true;
                $enviados[] = 'hoje:' . $pc;
            }
            // Atraso (uma vez por dia de atraso)
            if ($d < 0 && (int)($avisos['atraso'] ?? 0) !== $d) {
                notif_publish([
                    'tipo' => 'estoque_baixo',
                    'titulo' => 'Atrasado ' . abs($d) . ' dia(s) • PC ' . $pc,
                    'texto' => 'A ' . mb_strtolower($v['tipo_label']) . ' estava marcada para ' . date('d/m/Y', strtotime($ag['data'])) . ' e não foi concluída.',
                    'pc' => $pc, 'url' => '/?pc=' . $pc, 'autor' => 'agenda',
                ]);
                $avisos['atraso'] = $d;
                $enviados[] = 'atraso:' . $pc;
            }
            $ag['avisos'] = $avisos;
        }
        unset($ag);
        $db['ultima_verificacao'] = date('Y-m-d H:i');
        ag_write($db);
        return ['ok' => true, 'avisos' => $enviados, 'total' => count($enviados)];
    });
}

/** Chamado quando um recebimento é finalizado: conclui o agendamento do PC. */
function ag_concluir_por_recebimento($pc, $usuario = 'sistema') {
    try {
        return ag_concluir((int)$pc, $usuario, 'recebimento_finalizado');
    } catch (Throwable $e) {
        error_log('Agenda: falha ao concluir PC ' . $pc . ': ' . $e->getMessage());
        return null;
    }
}
