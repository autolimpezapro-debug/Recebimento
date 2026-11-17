<?php
/**
 * Recebimento Drilling — link público (somente leitura) do módulo Importados (v3.45.0)
 * Quem tem o link vê o módulo Importados completo, sem login e sem poder alterar nada.
 * Config: data/importados_publico.json (data/*.json fica bloqueado pelo .htaccess). O código do link é longo e aleatório;
 * gerar um novo ou revogar invalida o anterior na hora. Valores financeiros (custo) e nomes de usuários não saem.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const IMPP_ARQ = DATA_DIR . '/importados_publico.json';
const IMPP_TENT = DATA_DIR . '/.importados_publico_tentativas.json';
const IMPP_PADRAO = ['ativo' => false, 'token' => '', 'criado_em' => null, 'criado_por' => '', 'ultimo_acesso' => null, 'acessos' => 0, 'recentes' => []];

function impp_ler(): array {
    $j = is_file(IMPP_ARQ) ? json_decode((string)@file_get_contents(IMPP_ARQ), true) : null;
    return is_array($j) ? $j + IMPP_PADRAO : IMPP_PADRAO;
}
function impp_gravar(array $d): void {
    $tmp = IMPP_ARQ . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, IMPP_ARQ))
        throw new RuntimeException('Não foi possível gravar o link público.');
    @chmod(IMPP_ARQ, 0640);
}
function impp_gerar(string $usuario): array {
    $d = ['ativo' => true, 'token' => bin2hex(random_bytes(24)), 'criado_em' => date('c'), 'criado_por' => $usuario, 'ultimo_acesso' => null, 'acessos' => 0, 'recentes' => []];
    impp_gravar($d);
    return $d;
}
function impp_revogar(): array {
    $d = impp_ler(); $d['ativo'] = false; $d['token'] = '';
    impp_gravar($d);
    return $d;
}
/** Dados para a tela de administração (inclui o código, para poder copiar o link de novo). */
function impp_info(): array {
    $d = impp_ler();
    return ['ativo' => (bool)$d['ativo'] && $d['token'] !== '', 'token' => $d['ativo'] ? $d['token'] : '', 'criado_em' => $d['criado_em'], 'criado_por' => $d['criado_por'],
        'ultimo_acesso' => $d['ultimo_acesso'], 'acessos' => (int)$d['acessos'], 'recentes' => array_slice((array)$d['recentes'], 0, 10)];
}

function impp_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
function impp_ip_mascarado(string $ip): string {
    if (str_contains($ip, ':')) return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…';
    $p = explode('.', $ip); return count($p) === 4 ? "{$p[0]}.{$p[1]}.{$p[2]}.x" : $ip;
}
/** Limite de tentativas com código errado: 20 em 15 min por IP. */
function impp_bloqueado(): bool {
    $t = is_file(IMPP_TENT) ? (json_decode((string)@file_get_contents(IMPP_TENT), true) ?: []) : [];
    $k = hash('sha256', impp_ip()); $agora = time();
    $t[$k] = array_values(array_filter((array)($t[$k] ?? []), fn($x) => $x > $agora - 900));
    return count($t[$k]) >= 20;
}
function impp_falhou(): void {
    $t = is_file(IMPP_TENT) ? (json_decode((string)@file_get_contents(IMPP_TENT), true) ?: []) : [];
    $k = hash('sha256', impp_ip()); $agora = time();
    foreach ($t as $kk => $v) { $t[$kk] = array_values(array_filter((array)$v, fn($x) => $x > $agora - 900)); if (!$t[$kk]) unset($t[$kk]); }
    $t[$k][] = $agora;
    @file_put_contents(IMPP_TENT, json_encode($t), LOCK_EX);
}
function impp_token_ok(string $k): bool {
    $d = impp_ler();
    return $d['ativo'] && $d['token'] !== '' && $k !== '' && hash_equals((string)$d['token'], $k);
}
function impp_registrar_acesso(): void {
    $d = impp_ler(); $d['acessos'] = (int)$d['acessos'] + 1; $d['ultimo_acesso'] = date('c');
    $d['recentes'] = array_slice(array_merge([['em' => date('c'), 'ip' => impp_ip_mascarado(impp_ip()), 'agente' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80)]], (array)$d['recentes']), 0, 10);
    try { impp_gravar($d); } catch (Throwable $e) { /* visualização nunca falha por causa do registro */ }
}

const IMPP_CAMPOS = ['codigo', 'nome', 'saldo', 'unidade', 'disparo', 'recomendado', 'lead_time_dias', 'fabricavel', 'foto', 'localizacao', 'par', 'consumo', 'media_mensal',
    'cobertura_dias', 'status', 'acao', 'sugestao', 'ruptura_prevista', 'pedir_ate', 'obras', 'ultimo_movimento'];
function impp_item(array $v): array {
    $o = array_intersect_key($v, array_flip(IMPP_CAMPOS));
    if (isset($v['movimentos'])) {
        $o['movimentos'] = array_map(fn($m) => ['em' => $m['em'] ?? '', 'tipo' => $m['tipo'] ?? '', 'situacao' => $m['situacao'] ?? '', 'quantidade' => $m['quantidade'] ?? 0,
            'saldo_anterior' => $m['saldo_anterior'] ?? 0, 'saldo_posterior' => $m['saldo_posterior'] ?? 0, 'obra' => $m['obra'] ?? '', 'solicitante' => $m['solicitante'] ?? '',
            'os' => $m['os'] ?? '', 'equipamento' => $m['equipamento'] ?? '', 'observacao' => $m['observacao'] ?? '', 'cancelado' => !empty($m['cancelado']),
            'origem_planilha' => !empty($m['origem_planilha'])], (array)$v['movimentos']);
    }
    return $o;
}
