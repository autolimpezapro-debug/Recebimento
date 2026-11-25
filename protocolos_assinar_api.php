<?php
/**
 * Recebimento Drilling — API PÚBLICA da assinatura eletrônica do Protocolo de NFs (v3.59.0).
 * Acesso só pelo código do link (?t=), que vale para UM protocolo, expira e é de uso único. Não dá acesso a mais nada.
 *   GET  ?t=…                → resumo do protocolo para conferir antes de assinar
 *   POST {t, nome, cargo, tracos} → grava a assinatura (mesma rotina da assinatura no sistema) e invalida o link
 */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
define('DATA_DIR', __DIR__ . '/data');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
function respond($data, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
require_once __DIR__ . '/protocolos_email_lib.php';

const PAS_TENT = DATA_DIR . '/.protocolos_assinar_tentativas.json';
function pas_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function pas_tent(bool $registrar): bool {   // 20 falhas / 15 min por IP
    $t = is_file(PAS_TENT) ? (json_decode((string)@file_get_contents(PAS_TENT), true) ?: []) : []; $k = hash('sha256', pas_ip()); $agora = time();
    foreach ($t as $kk => $v) { $t[$kk] = array_values(array_filter((array)$v, fn($x) => $x > $agora - 900)); if (!$t[$kk]) unset($t[$kk]); }
    if ($registrar) { $t[$k][] = $agora; @file_put_contents(PAS_TENT, json_encode($t), LOCK_EX); }
    return count($t[$k] ?? []) >= 20;
}
function pas_resumo(array $p, array $l): array {
    $v = prt_view($p);
    return ['id' => $p['id'], 'destinatario' => $p['destinatario'], 'criado_em' => $p['criado_em'], 'obs' => $p['obs'] ?? '', 'status' => $p['status'], 'qtd_nfs' => $v['qtd_nfs'], 'total_valor' => $v['total_valor'],
        'expira_em' => $l['expira_em'],
        'linhas' => array_map(fn($x) => ['pc' => $x['pc'], 'fornecedor' => $x['fornecedor'], 'numeroNF' => $x['numeroNF'], 'serieNF' => $x['serieNF'] ?? '', 'emissaoNF' => $x['emissaoNF'] ?? '', 'valorNF' => $x['valorNF'] ?? ''], $p['linhas'])];
}
/** Estado do link: ok | usado | expirado | revogado | cancelado */
function pas_estado(array $p, array $l): string {
    if (!empty($l['usado_em']) || $p['status'] === 'ASSINADO') return 'usado';
    if ($p['status'] === 'CANCELADO') return 'cancelado';
    if (!empty($l['revogado'])) return 'revogado';
    if (strtotime((string)$l['expira_em']) < time()) return 'expirado';
    return 'ok';
}
const PAS_MSG = ['usado' => 'Este protocolo já foi assinado.', 'cancelado' => 'Este protocolo foi cancelado.', 'revogado' => 'Este link foi substituído por um mais novo. Use o e-mail mais recente.', 'expirado' => 'Este link expirou. Peça um novo ao Recebimento.'];

if (pas_tent(false)) respond(['ok' => false, 'error' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'GET') {
    $t = (string)($_GET['t'] ?? ''); $d = prt_read(); $pos = pem_achar($d, $t);
    if (!$pos) { pas_tent(true); respond(['ok' => false, 'error' => 'Link inválido.'], 403); }
    $p = $d['protocolos'][$pos['pi']]; $l = $p['links'][$pos['li']]; $st = pas_estado($p, $l);
    if ($st !== 'ok') respond(['ok' => false, 'estado' => $st, 'error' => PAS_MSG[$st], 'protocolo_id' => $p['id']], 410);
    respond(['ok' => true, 'protocolo' => pas_resumo($p, $l)]);
}
if ($metodo !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 400000) respond(['ok' => false, 'error' => 'Corpo muito grande'], 413);
$in = json_decode($raw, true); if (!is_array($in)) respond(['ok' => false, 'error' => 'Dados inválidos'], 400);
$t = (string)($in['t'] ?? '');
$res = prt_with_lock(function () use ($t, $in) {
    $d = prt_read(); $pos = pem_achar($d, $t);
    if (!$pos) { pas_tent(true); respond(['ok' => false, 'error' => 'Link inválido.'], 403); }
    $pi = $pos['pi']; $li = $pos['li']; $p = $d['protocolos'][$pi]; $st = pas_estado($p, $p['links'][$li]);
    if ($st !== 'ok') respond(['ok' => false, 'estado' => $st, 'error' => PAS_MSG[$st]], 410);
    prt_status($d, ['id' => $p['id'], 'acao' => 'assinar_digital', 'recebido_por' => $in['nome'] ?? '', 'cargo' => $in['cargo'] ?? '', 'tracos' => $in['tracos'] ?? null], 'assinatura-por-link');
    $q = &$d['protocolos'][$pi];
    $q['assinatura_digital']['canal'] = 'link por e-mail'; $q['assinatura_digital']['agente'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160);
    $q['links'][$li]['usado_em'] = date('c'); $q['links'][$li]['usado_ip'] = pas_ip();
    $q['historico'][] = ['em' => date('c'), 'por' => 'link-por-email', 'acao' => 'Assinatura recebida pelo link enviado por e-mail (IP ' . pas_ip() . ')'];
    prt_write($d);
    return $q;
});
pem_confirmar($res);
respond(['ok' => true, 'protocolo_id' => $res['id'], 'codigo' => $res['assinatura_digital']['codigo'], 'em' => $res['assinatura_digital']['em']]);
