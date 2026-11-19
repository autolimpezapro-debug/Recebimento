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

function rge_dia_extenso(string $dia): string {
    $sem = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $mes = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $t = strtotime($dia . ' 12:00:00'); return $sem[(int)date('w', $t)] . ', ' . (int)date('j', $t) . ' de ' . $mes[(int)date('n', $t)] . ' de ' . date('Y', $t);
}

/** Monta [assunto, html, texto, csv, nome_csv, resumo] das saídas do dia $dia (Y-m-d). Layout "premium": tabelas + estilos inline (compatível com Gmail/Outlook/celular). */
function rge_montar(string $dia, bool $soOs = true): array {
    $r = rg_relatorio($dia, $dia, $soOs); $res = $r['resumo']; $dd = substr($dia, 8, 2) . '/' . substr($dia, 5, 2) . '/' . substr($dia, 0, 4);
    $assunto = "Saídas de material · $dd · " . $res['saidas_mov'] . ' mov. · ' . rge_brl((float)$res['saidas_valor']);
    $F = 'font-family:Segoe UI,Helvetica,Arial,sans-serif;';
    // agrupa por OS (maior valor primeiro); dentro, por horário
    $g = [];
    foreach ($r['saidas'] as $s) {
        $k = $s['os'] !== '' ? $s['os'] : '(sem OS)';
        $g[$k] ??= ['os' => $k, 'valor' => 0.0, 'obras' => [], 'equip' => [], 'sol' => [], 'itens' => []];
        $g[$k]['valor'] += (float)$s['valor']; $g[$k]['itens'][] = $s;
        if ($s['obra'] !== '') $g[$k]['obras'][$s['obra']] = 1; if ($s['equipamento'] !== '') $g[$k]['equip'][$s['equipamento']] = 1; if ($s['solicitante'] !== '') $g[$k]['sol'][$s['solicitante']] = 1;
    }
    uasort($g, fn($a, $b) => $b['valor'] <=> $a['valor']);
    $kpi = fn($n, $t, $cor = '#0b1f3a') => '<td width="25%" valign="top" style="padding:0 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e3e8ef;border-radius:10px"><tr><td align="center" style="padding:14px 6px;' . $F . '"><div style="font-size:20px;font-weight:700;color:' . $cor . ';line-height:1.2">' . rge_h($n) . '</div><div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.6px;padding-top:4px">' . rge_h($t) . '</div></td></tr></table></td>';
    $blocos = ''; $txt = "SAÍDAS DE MATERIAL — " . rge_dia_extenso($dia) . "\n{$res['saidas_mov']} movimentos · {$res['os_distintas']} OS · " . rge_brl((float)$res['saidas_valor']) . "\n"; $n = 0; $max = 250; $cortou = false;
    foreach ($g as $x) {
        $semOs = $x['os'] === '(sem OS)';
        $meta = array_filter([$x['obras'] ? implode(', ', array_keys($x['obras'])) : '', $x['equip'] ? 'Equip. ' . implode(', ', array_keys($x['equip'])) : '', $x['sol'] ? 'Solic. ' . implode(', ', array_keys($x['sol'])) : '']);
        $blocos .= '<tr><td style="padding:0 0 14px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e3e8ef;border-radius:10px;overflow:hidden">'
            . '<tr><td bgcolor="' . ($semOs ? '#fff4e5' : '#f4f7fb') . '" style="padding:12px 16px;border-bottom:1px solid #e3e8ef;' . $F . '"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
            . '<td valign="middle"><span style="display:inline-block;background:' . ($semOs ? '#b54708' : '#0b1f3a') . ';color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.5px;padding:4px 10px;border-radius:12px">' . ($semOs ? 'SEM OS/OM' : 'OS ' . rge_h($x['os'])) . '</span>'
            . ($meta ? '<div style="font-size:12px;color:#667085;padding-top:6px">' . rge_h(implode('  ·  ', $meta)) . '</div>' : '') . '</td>'
            . '<td align="right" valign="middle" style="white-space:nowrap"><div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.6px">Custo</div><div style="font-size:17px;font-weight:700;color:#0b1f3a">' . rge_brl($x['valor']) . '</div></td></tr></table></td></tr>';
        $txt .= "\n" . ($semOs ? 'SEM OS' : 'OS ' . $x['os']) . ' — ' . rge_brl($x['valor']) . "\n";
        foreach ($x['itens'] as $i => $s) {
            if (++$n > $max) { $cortou = true; break; }
            $ap = rge_aplicacao($s); $q = alt_n(abs($s['quantidade'])) . ' ' . $s['unidade']; $est = $s['quantidade'] < 0;
            $blocos .= '<tr><td style="padding:10px 16px;' . ($i ? 'border-top:1px solid #eef1f5;' : '') . $F . '"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                . '<td valign="top" style="font-size:14px;color:#101828"><b>' . rge_h($s['descricao']) . '</b>' . ($est ? ' <span style="color:#b42318;font-size:11px">(estorno)</span>' : '')
                . '<div style="font-size:12px;color:#667085;padding-top:3px">' . rge_h(trim(($s['sku'] ?: '') . ($s['sku'] ? '  ·  ' : '') . substr($s['em'], 11, 5))) . ($ap !== '' ? '  ·  ' . rge_h($ap) : '') . '</div></td>'
                . '<td valign="top" align="right" style="white-space:nowrap;padding-left:12px;font-size:13px;color:#344054"><b style="font-size:14px;color:#101828">' . ($est ? '−' : '') . rge_h($q) . '</b><div style="font-size:12px;color:#667085;padding-top:3px">'
                . ($s['valor_fonte'] === 'sem custo' ? 'sem custo' : rge_brl(abs((float)$s['valor']))) . '</div></td></tr></table></td></tr>';
            $txt .= '  • ' . $s['descricao'] . ' — ' . ($est ? '-' : '') . $q . ' — ' . rge_brl((float)$s['valor']) . ($ap !== '' ? ' — ' . $ap : '') . "\n";
        }
        $blocos .= '</table></td></tr>';
        if ($cortou) break;
    }
    if (!$res['saidas_mov']) $blocos = '<tr><td align="center" style="padding:30px 16px;background:#ffffff;border:1px solid #e3e8ef;border-radius:10px;' . $F . 'color:#667085;font-size:14px">Nenhuma saída de material' . ($soOs ? ' com OS/OM' : '') . ' neste dia.</td></tr>';
    $base = isset($_SERVER['HTTP_HOST']) ? 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST']) : '';
    $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . rge_h($assunto) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef1f5"><div style="display:none;max-height:0;overflow:hidden;opacity:0">' . rge_h($res['saidas_mov'] . ' saídas · ' . $res['os_distintas'] . ' OS · ' . rge_brl((float)$res['saidas_valor']) . ' em custo') . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#eef1f5"><tr><td align="center" style="padding:22px 10px">'
        . '<table role="presentation" width="680" cellspacing="0" cellpadding="0" style="width:100%;max-width:680px">'
        . '<tr><td bgcolor="#0b1f3a" style="background:#0b1f3a;border-radius:12px 12px 0 0;padding:26px 28px 22px;' . $F . '"><div style="font-size:11px;letter-spacing:2px;color:#f5b942;font-weight:700">DRILLING DO BRASIL</div>'
        . '<div style="font-size:26px;font-weight:700;color:#ffffff;padding-top:6px;line-height:1.2">Saídas de material</div>'
        . '<div style="font-size:14px;color:#b9c8de;padding-top:6px">' . rge_h(ucfirst(rge_dia_extenso($dia))) . ($soOs ? '  ·  somente com OS/OM' : '') . '</div></td></tr>'
        . '<tr><td bgcolor="#f5b942" height="4" style="height:4px;line-height:4px;font-size:0">&nbsp;</td></tr>'
        . '<tr><td style="padding:20px 0 6px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>' . $kpi($res['saidas_mov'], 'Movimentos') . $kpi($res['os_distintas'], 'OS atendidas') . $kpi(rge_brl((float)$res['saidas_valor']), 'Custo total', '#067647') . $kpi($res['saidas_sem_os'], 'Sem OS', $res['saidas_sem_os'] ? '#b54708' : '#0b1f3a') . '</tr></table></td></tr>'
        . '<tr><td style="padding:12px 0 0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $blocos . '</table></td></tr>'
        . ($cortou ? '<tr><td style="padding:0 0 12px;' . $F . 'font-size:12px;color:#667085">Mostrando as primeiras ' . $max . ' linhas — o CSV em anexo tem todas.</td></tr>' : '')
        . ($res['saidas_sem_custo'] ? '<tr><td style="padding:0 0 12px;' . $F . 'font-size:12px;color:#b54708">⚠ ' . (int)$res['saidas_sem_custo'] . ' saída(s) sem custo cadastrado (contam R$ 0,00).</td></tr>' : '')
        . '<tr><td align="center" style="padding:6px 0 0;' . $F . '">' . ($base ? '<a href="' . $base . '/?page=relger" style="display:inline-block;background:#0b1f3a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;padding:11px 22px;border-radius:8px">Abrir relatório completo</a>' : '') . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 10px 4px;' . $F . 'font-size:11px;line-height:1.6;color:#8a94a6">📎 CSV completo em anexo (abre no Excel). Valor = custo médio do item.<br>Relatório automático · Sistema Recebimento Drilling · enviado ' . date('d/m/Y H:i') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
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
