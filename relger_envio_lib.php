<?php
/**
 * Recebimento Drilling — Envio diário das saídas de material por e-mail (v3.58.0).
 * Reaproveita: rg_relatorio()/rg_csv() (Relatório gerencial) e alt_email_enviar() + SMTP/mail() configurados nos alertas de estoque.
 * Disparo: batimento das telas abertas (notify_heartbeat → rge_tick) e/ou cron (alertas_worker.php → rge_verificar). No máximo 1 envio por dia.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/relger_lib.php';
require_once __DIR__ . '/alertas_lib.php';

const RGE_CONFIG = DATA_DIR . '/relger_envio.json';
const RGE_ESTADO = DATA_DIR . '/relger_envio_estado.json';
const RGE_LOCK   = DATA_DIR . '/.relger_envio.lock';
const RGE_PADRAO = ['ativo' => false, 'hora' => '18:00', 'emails' => [], 'periodo' => 'hoje', 'enviar_vazio' => false, 'so_com_os' => true];

function rge_config(): array { return alt_json_read(RGE_CONFIG, RGE_PADRAO); }
function rge_estado(): array { return alt_json_read(RGE_ESTADO, []); }

/** Dia (Y-m-d) do último horário de envio já vencido: hoje se já passou da hora, senão ontem. */
function rge_slot(array $cfg, ?int $agora = null): string {
    date_default_timezone_set('America/Sao_Paulo'); $agora ??= time();
    $hoje = date('Y-m-d', $agora);
    return $agora >= strtotime($hoje . ' ' . $cfg['hora'] . ':00') ? $hoje : date('Y-m-d', strtotime($hoje . ' -1 day'));
}

function rge_config_salvar(array $in): array {
    $c = rge_config();
    $c['ativo'] = !empty($in['ativo']);
    $hora = trim((string)($in['hora'] ?? '18:00'));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw new InvalidArgumentException('Horário inválido. Use HH:MM (ex.: 18:00).');
    $c['hora'] = $hora;
    $lista = preg_split('/[\s,;]+/', is_array($in['emails'] ?? null) ? implode(',', $in['emails']) : (string)($in['emails'] ?? ''));
    $c['emails'] = array_values(array_unique(array_filter(array_map('trim', $lista), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if (count($c['emails']) > 20) throw new InvalidArgumentException('No máximo 20 e-mails.');
    $c['periodo'] = ($in['periodo'] ?? '') === 'ontem' ? 'ontem' : 'hoje';
    $c['enviar_vazio'] = !empty($in['enviar_vazio']);
    $c['so_com_os'] = !empty($in['so_com_os']);
    if ($c['ativo'] && !$c['emails']) throw new InvalidArgumentException('Informe ao menos um e-mail para receber o relatório.');
    alt_json_write(RGE_CONFIG, $c);
    // Ligou agora: começa a valer no PRÓXIMO horário (não dispara retroativo do horário que já passou hoje).
    if ($c['ativo']) { $e = rge_estado(); if (empty($e['ultimo_slot']) || $e['ultimo_slot'] < rge_slot($c)) { $e['ultimo_slot'] = rge_slot($c); alt_json_write(RGE_ESTADO, $e); } }
    return $c;
}

function rge_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rge_brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function rge_aplicacao(array $s): string { return trim(implode(' — ', array_filter([trim((string)$s['equipamento']), trim((string)$s['finalidade'])], fn($x) => $x !== ''))); }

/** Monta [assunto, html, texto, csv, nome_csv] das saídas do dia $dia (Y-m-d). */
function rge_montar(string $dia, bool $soOs = true): array {
    $r = rg_relatorio($dia, $dia, $soOs); $res = $r['resumo']; $dd = substr($dia, 8, 2) . '/' . substr($dia, 5, 2) . '/' . substr($dia, 0, 4);
    $assunto = "Saídas de material $dd — {$res['saidas_mov']} movimento(s), " . rge_brl((float)$res['saidas_valor']);
    $max = 300; $lin = ''; $txt = "Saídas de material — $dd\n{$res['saidas_mov']} movimentos · {$res['os_distintas']} OS · " . rge_brl((float)$res['saidas_valor']) . "\n\n";
    foreach (array_slice($r['saidas'], 0, $max) as $s) {
        $os = $s['os'] !== '' ? $s['os'] : '(sem OS)'; $ap = rge_aplicacao($s); $q = alt_n($s['quantidade']);
        $lin .= '<tr><td>' . rge_h(substr($s['em'], 11, 5)) . '</td><td><b>' . rge_h($os) . '</b></td><td>' . rge_h(($s['sku'] ? $s['sku'] . ' · ' : '') . $s['descricao']) . '</td><td align="right">' . rge_h($q . ' ' . $s['unidade'])
            . '</td><td align="right">' . ($s['valor_fonte'] === 'sem custo' ? '<i>sem custo</i>' : rge_brl((float)$s['valor'])) . '</td><td>' . rge_h($ap !== '' ? $ap : '—') . '</td></tr>';
        $txt .= "$os | {$s['descricao']} | $q {$s['unidade']} | " . rge_brl((float)$s['valor']) . ($ap !== '' ? " | $ap" : '') . "\n";
    }
    $mais = count($r['saidas']) > $max ? '<p><i>Mostrando as ' . $max . ' primeiras; veja todas no CSV em anexo.</i></p>' : '';
    $kp = fn($n, $t) => '<td style="padding:8px 14px;background:#f3f6f9;border-radius:6px"><b style="font-size:18px">' . rge_h($n) . '</b><br><span style="color:#555;font-size:12px">' . rge_h($t) . '</span></td><td width="8"></td>';
    $html = '<div style="font-family:Arial,sans-serif;font-size:13px;color:#111"><h2 style="margin:0 0 4px">Drilling do Brasil — Saídas de material</h2><p style="margin:0 0 12px;color:#555">' . $dd . '</p>'
        . '<table cellspacing="0" cellpadding="0"><tr>' . $kp($res['saidas_mov'], 'movimentos') . $kp($res['os_distintas'], 'OS atendidas') . $kp(rge_brl((float)$res['saidas_valor']), 'valor (custo)') . $kp($res['saidas_sem_os'], 'sem OS') . '</tr></table>'
        . ($res['saidas_mov'] ? '<table cellspacing="0" cellpadding="5" border="1" style="border-collapse:collapse;border-color:#d9dee5;margin-top:14px;width:100%"><thead style="background:#eef2f6"><tr><th>Hora</th><th>OS</th><th>Material</th><th>Qtd.</th><th>Valor</th><th>Aplicação</th></tr></thead><tbody>' . $lin . '</tbody></table>' . $mais
            : '<p>Nenhuma saída de material' . ($soOs ? ' com OS/OM' : '') . ' neste dia.</p>')
        . ($res['saidas_sem_custo'] ? '<p style="color:#b45309">' . (int)$res['saidas_sem_custo'] . ' saída(s) sem custo cadastrado (valor R$ 0,00).</p>' : '')
        . '<p style="color:#777;font-size:11px">' . ($soOs ? 'Somente saídas com OS/OM. ' : '') . 'Valor = custo médio do item. Relatório automático do sistema Recebimento Drilling.</p></div>';
    return [$assunto, $html, $txt, rg_csv($r), 'Saidas-' . $dia . '.csv', $res];
}

/** Envia o relatório do dia $dia para $para (ou a lista configurada). */
function rge_enviar(string $dia, ?array $para = null, bool $teste = false): array {
    $cfg = rge_config(); $para = $para ?? $cfg['emails'];
    if (!$para) throw new InvalidArgumentException('Nenhum e-mail configurado.');
    [$a, $h, $t, $csv, $nome, $res] = rge_montar($dia, !empty($cfg['so_com_os']));
    alt_email_enviar(alt_config(), ($teste ? '[TESTE] ' : '') . $a, $h, $t, $para, [], [['nome' => $nome, 'mime' => 'text/csv; charset=utf-8', 'dados' => $csv]]);
    return ['enviado_para' => $para, 'dia' => $dia, 'saidas' => (int)$res['saidas_mov'], 'valor' => (float)$res['saidas_valor']];
}

function rge_teste(): array {   // relatório de hoje (parcial) — só para conferir o formato; não conta como envio do dia
    date_default_timezone_set('America/Sao_Paulo');
    return rge_enviar(date('Y-m-d'), null, true);
}

/** Verifica se há envio vencido e o faz. Seguro para chamar a cada batimento: sai logo se não for a hora. */
function rge_verificar(string $motivo = 'batimento'): array {
    $cfg = rge_config(); if (!$cfg['ativo'] || !$cfg['emails']) return ['ignorado' => 'desligado'];
    $slot = rge_slot($cfg); $est = rge_estado();
    if (($est['ultimo_slot'] ?? '') >= $slot) return ['ignorado' => 'já enviado'];
    if (!empty($est['proxima_tentativa']) && ($est['slot_tentativa'] ?? '') === $slot && time() < (int)$est['proxima_tentativa']) return ['ignorado' => 'aguardando nova tentativa'];
    $r = alt_lock(function () use ($cfg, $slot, $motivo) {
        $est = rge_estado(); if (($est['ultimo_slot'] ?? '') >= $slot) return ['ignorado' => 'já enviado'];
        $dia = $cfg['periodo'] === 'ontem' ? date('Y-m-d', strtotime($slot . ' -1 day')) : $slot;
        $vazio = rg_relatorio($dia, $dia, !empty($cfg['so_com_os']))['resumo']['saidas_mov'] === 0;
        try {
            if ($vazio && !$cfg['enviar_vazio']) { $est['ultimo_resultado'] = ['em' => date('c'), 'dia' => $dia, 'ok' => true, 'obs' => 'sem saídas — não enviado', 'motivo' => $motivo]; }
            else { $o = rge_enviar($dia); $est['ultimo_resultado'] = ['em' => date('c'), 'dia' => $dia, 'ok' => true, 'saidas' => $o['saidas'], 'valor' => $o['valor'], 'para' => $o['enviado_para'], 'motivo' => $motivo]; }
            $est['ultimo_slot'] = $slot; unset($est['proxima_tentativa'], $est['slot_tentativa'], $est['tentativas']);
            alt_json_write(RGE_ESTADO, $est); return $est['ultimo_resultado'];
        } catch (Throwable $e) {
            $n = (($est['slot_tentativa'] ?? '') === $slot ? (int)($est['tentativas'] ?? 0) : 0) + 1;
            $est['ultimo_resultado'] = ['em' => date('c'), 'dia' => $dia, 'ok' => false, 'erro' => $e->getMessage(), 'tentativa' => $n];
            $est['slot_tentativa'] = $slot; $est['tentativas'] = $n; $est['proxima_tentativa'] = time() + 1800;
            if ($n >= 3) { $est['ultimo_slot'] = $slot; unset($est['proxima_tentativa']); }   // desiste deste dia após 3 tentativas (o erro fica visível na tela)
            alt_json_write(RGE_ESTADO, $est); error_log('Envio diário das saídas: ' . $e->getMessage()); return $est['ultimo_resultado'];
        }
    }, false);
    return $r ?? ['ignorado' => 'verificação em andamento'];
}
function rge_tick(): void { try { rge_verificar('batimento'); } catch (Throwable $e) { error_log('Envio diário das saídas: ' . $e->getMessage()); } }

function rge_status(): array { $e = rge_estado(); return ['config' => rge_config(), 'ultimo' => $e['ultimo_resultado'] ?? null, 'ultimo_dia_enviado' => $e['ultimo_slot'] ?? null, 'email_configurado' => (bool)(alt_config()['metodo'] === 'smtp' ? alt_config()['smtp_host'] : true)]; }
