<?php
/**
 * Recebimento Drilling — E-mail automático do Protocolo de NFs + assinatura eletrônica por link (v3.59.0).
 * Ao gerar o protocolo, o sistema envia ao(s) e-mail(s) cadastrado(s) o resumo + PDF + botão "Assinar eletronicamente".
 * O link é único, de uso único, expira (padrão 7 dias) e só abre ESTE protocolo. Só o hash do código fica gravado.
 * Reaproveita: prt_pdf/prt_status (assinar_digital) e alt_email_enviar (SMTP/mail() dos Alertas de estoque).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/protocolos_lib.php';
require_once __DIR__ . '/alertas_lib.php';

const PEM_CONFIG = DATA_DIR . '/protocolos_email.json';
const PEM_PADRAO = ['ativo' => false, 'emails' => [], 'validade_dias' => 7, 'anexar_pdf' => true, 'confirmar' => true];

function pem_emails($v): array {
    $l = preg_split('/[\s,;]+/', is_array($v) ? implode(',', $v) : (string)$v);
    $l = array_values(array_unique(array_filter(array_map('trim', $l), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if (count($l) > 10) prt_fail('No máximo 10 e-mails.');
    return $l;
}
function pem_config(): array { return alt_json_read(PEM_CONFIG, PEM_PADRAO); }
function pem_config_salvar(array $in): array {
    $c = pem_config();
    $c['ativo'] = !empty($in['ativo']); $c['emails'] = pem_emails($in['emails'] ?? []);
    $c['validade_dias'] = max(1, min(60, (int)($in['validade_dias'] ?? 7)));
    $c['anexar_pdf'] = !empty($in['anexar_pdf']); $c['confirmar'] = !empty($in['confirmar']);
    if ($c['ativo'] && !$c['emails']) prt_fail('Informe ao menos um e-mail para receber os protocolos.');
    alt_json_write(PEM_CONFIG, $c);
    return $c;
}
function pem_base(): string {
    $h = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($h === '') return '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !preg_match('/^(localhost|127\.)/', $h);
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https://' : 'http://') . $h . $dir;
}
/** Cria um link novo (revoga os pendentes). Devolve o código em claro — só aparece aqui, no e-mail. */
function pem_token_novo(array &$p, int $dias, array $emails, string $user): string {
    foreach ($p['links'] ?? [] as $i => $l) if (empty($l['usado_em']) && empty($l['revogado'])) $p['links'][$i]['revogado'] = date('c');
    $tok = bin2hex(random_bytes(24));
    $p['links'][] = ['h' => hash('sha256', $tok), 'criado_em' => date('c'), 'criado_por' => $user, 'expira_em' => date('c', time() + $dias * 86400), 'emails' => $emails, 'usado_em' => null, 'revogado' => null];
    return $tok;
}
/** Posição ['pi' => protocolo, 'li' => link] do código (comparado pelo hash) ou null. */
function pem_achar(array $d, string $tok): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $h = hash('sha256', $tok);
    foreach ($d['protocolos'] as $pi => $p) foreach ($p['links'] ?? [] as $li => $l) if (hash_equals((string)$l['h'], $h)) return ['pi' => $pi, 'li' => $li];
    return null;
}
function pem_f(string $dia): string { $dia = substr((string)$dia, 0, 10); return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dia, $m) ? "$m[3]/$m[2]/$m[1]" : $dia; }
function pem_brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

function pem_email_montar(array $p, string $url, string $expira, bool $assinado = false, ?array $ass = null): array {
    $v = prt_view($p); $F = 'font-family:Segoe UI,Helvetica,Arial,sans-serif;'; $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $assunto = $assinado ? "Protocolo {$p['id']} assinado — {$v['qtd_nfs']} NF(s)" : "Protocolo de NFs {$p['id']} — assine eletronicamente";
    $lin = ''; $txt = '';
    foreach (array_slice($p['linhas'], 0, 60) as $l) {
        $val = prt_valor($l['valorNF'] ?? '');
        $lin .= '<tr><td style="padding:9px 12px;border-top:1px solid #eef1f5;' . $F . 'font-size:13px"><b>' . $h($l['numeroNF']) . '</b><div style="font-size:12px;color:#667085">' . $h($l['fornecedor']) . ' · PC ' . $h($l['pc']) . '</div></td>'
            . '<td align="right" style="padding:9px 12px;border-top:1px solid #eef1f5;' . $F . 'font-size:13px;white-space:nowrap">' . ($val > 0 ? $h(pem_brl($val)) : '—') . '</td></tr>';
        $txt .= '• NF ' . $l['numeroNF'] . ' — ' . $l['fornecedor'] . ' — PC ' . $l['pc'] . ($val > 0 ? ' — ' . pem_brl($val) : '') . "\n";
    }
    $mais = count($p['linhas']) > 60 ? '<tr><td colspan="2" style="padding:8px 12px;font-size:12px;color:#667085;' . $F . '">…e mais ' . (count($p['linhas']) - 60) . ' nota(s) — veja todas no PDF.</td></tr>' : '';
    $kpi = fn($n, $t) => '<td width="50%" style="padding:0 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #e3e8ef;border-radius:10px"><tr><td align="center" style="padding:12px 6px;' . $F . '"><div style="font-size:20px;font-weight:700;color:#0b1f3a">' . $h($n) . '</div><div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.6px;padding-top:3px">' . $h($t) . '</div></td></tr></table></td>';
    if ($assinado) {
        $bloco = '<tr><td style="padding:16px 0 4px;' . $F . '"><div style="background:#ecfdf3;border:1px solid #abefc6;border-radius:10px;padding:14px 16px;font-size:14px;color:#067647"><b>✔ Protocolo assinado eletronicamente</b><br><span style="color:#344054;font-size:13px">Por <b>' . $h($ass['nome'] ?? '') . '</b>' . (!empty($ass['cargo']) ? ' (' . $h($ass['cargo']) . ')' : '') . ' em ' . $h(date('d/m/Y H:i', strtotime((string)($ass['em'] ?? 'now')))) . '. Código de verificação: <b>' . $h($ass['codigo'] ?? '') . '</b>.</span></div></td></tr>';
        $txt = "Protocolo {$p['id']} assinado por " . ($ass['nome'] ?? '') . ' em ' . date('d/m/Y H:i', strtotime((string)($ass['em'] ?? 'now'))) . ' — código ' . ($ass['codigo'] ?? '') . "\n\n" . $txt;
    } else {
        $bloco = '<tr><td align="center" style="padding:20px 0 6px;' . $F . '"><a href="' . $h($url) . '" style="display:inline-block;background:#0b1f3a;color:#fff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 30px;border-radius:9px">✍ Assinar eletronicamente</a>'
            . '<div style="font-size:12px;color:#667085;padding-top:10px">Abre no celular ou no computador, sem senha. Link pessoal, de uso único, válido até ' . $h($expira) . '.</div></td></tr>';
        $txt = "Protocolo {$p['id']} — {$v['qtd_nfs']} NF(s)" . ($v['total_valor'] ? ' · ' . pem_brl($v['total_valor']) : '') . "\nAssine eletronicamente: $url\n(válido até $expira; link de uso único)\n\n" . $txt;
    }
    $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#eef1f5">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#eef1f5"><tr><td align="center" style="padding:22px 10px"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px">'
        . '<tr><td bgcolor="#0b1f3a" style="background:#0b1f3a;border-radius:12px 12px 0 0;padding:24px 28px 20px;' . $F . '"><div style="font-size:11px;letter-spacing:2px;color:#f5b942;font-weight:700">DRILLING DO BRASIL</div>'
        . '<div style="font-size:24px;font-weight:700;color:#fff;padding-top:6px">Protocolo de NFs ' . $h($p['id']) . '</div><div style="font-size:14px;color:#b9c8de;padding-top:6px">Para: ' . $h($p['destinatario']) . ' · gerado em ' . $h(pem_f($p['criado_em'])) . '</div></td></tr>'
        . '<tr><td bgcolor="#f5b942" height="4" style="height:4px;line-height:4px;font-size:0">&nbsp;</td></tr>'
        . '<tr><td style="padding:18px 0 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>' . $kpi($v['qtd_nfs'], 'Notas fiscais') . $kpi($v['total_valor'] ? pem_brl($v['total_valor']) : '—', 'Valor total') . '</tr></table></td></tr>'
        . $bloco
        . '<tr><td style="padding:14px 0 0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #e3e8ef;border-radius:10px">' . $lin . $mais . '</table></td></tr>'
        . ($p['obs'] !== '' ? '<tr><td style="padding:12px 4px 0;' . $F . 'font-size:13px;color:#475467"><b>Obs.:</b> ' . $h($p['obs']) . '</td></tr>' : '')
        . '<tr><td align="center" style="padding:18px 10px 4px;' . $F . 'font-size:11px;line-height:1.6;color:#8a94a6">' . ($assinado ? 'PDF do protocolo em anexo, com a assinatura.' : 'O PDF do protocolo segue em anexo. Se preferir, imprima e assine a via em papel.') . '<br>Mensagem automática · Sistema Recebimento Drilling</td></tr>'
        . '</table></td></tr></table></body></html>';
    return [$assunto, $html, $txt];
}
/** Envia (ou reenvia) o protocolo com link novo. Chamar DENTRO de prt_with_lock; o chamador grava $d depois. */
function pem_enviar(array &$d, string $id, ?array $emails, string $user): array {
    $cfg = pem_config(); $emails = $emails ?? $cfg['emails'];
    if (!$emails) return ['enviado' => false, 'motivo' => 'Nenhum e-mail cadastrado para receber o protocolo.'];
    $p = &prt_get($d, $id);
    if (!in_array($p['status'], ['GERADO', 'ENTREGUE'], true)) return ['enviado' => false, 'motivo' => 'Este protocolo não está aguardando assinatura.'];
    $base = pem_base();
    if ($base === '') return ['enviado' => false, 'motivo' => 'Não foi possível montar o endereço do sistema para o link.'];
    $tok = pem_token_novo($p, (int)$cfg['validade_dias'], $emails, $user);
    $url = $base . '/assinar_protocolo.html?t=' . $tok;
    [$a, $h, $t] = pem_email_montar($p, $url, pem_f(date('Y-m-d', time() + (int)$cfg['validade_dias'] * 86400)));
    try {
        $anexos = $cfg['anexar_pdf'] ? [['nome' => 'Protocolo-' . $p['id'] . '.pdf', 'mime' => 'application/pdf', 'dados' => prt_pdf($p)]] : [];
        alt_email_enviar(alt_config(), $a, $h, $t, $emails, [], $anexos);
        $p['historico'][] = ['em' => date('c'), 'por' => $user, 'acao' => 'E-mail com link de assinatura enviado para ' . implode(', ', $emails)];
        $p['email_enviado_em'] = date('c');
        return ['enviado' => true, 'para' => $emails];
    } catch (Throwable $e) {
        $ult = count($p['links']) - 1; $p['links'][$ult]['revogado'] = date('c');
        $p['historico'][] = ['em' => date('c'), 'por' => $user, 'acao' => 'Falha ao enviar o e-mail: ' . mb_substr($e->getMessage(), 0, 160)];
        return ['enviado' => false, 'motivo' => $e->getMessage()];
    }
}
/** Confirmação com o PDF assinado, para os e-mails cadastrados (se ativado). Não lança erro. */
function pem_confirmar(array $p): void {
    try {
        $cfg = pem_config(); if (!$cfg['confirmar'] || !$cfg['emails'] || empty($p['assinatura_digital'])) return;
        [$a, $h, $t] = pem_email_montar($p, '', '', true, $p['assinatura_digital']);
        alt_email_enviar(alt_config(), $a, $h, $t, $cfg['emails'], [], $cfg['anexar_pdf'] ? [['nome' => 'Protocolo-' . $p['id'] . '-assinado.pdf', 'mime' => 'application/pdf', 'dados' => prt_pdf($p)]] : []);
    } catch (Throwable $e) { error_log('Protocolo: confirmação por e-mail falhou: ' . $e->getMessage()); }
}
