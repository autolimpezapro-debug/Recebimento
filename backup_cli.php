<?php
/**
 * Recebimento Drilling — Backup automático por CRON (v3.2.0)
 *
 * cPanel → Cron Jobs (uma vez por dia, de madrugada):
 *   php -q /CAMINHO/ABSOLUTO/backup_cli.php
 *
 * Opções:
 *   --manter=14      quantos backups diários guardar
 *   --semanal=8      quantos backups semanais (domingo) guardar
 *   --forcar         gera mesmo que já exista backup de hoje
 *   --listar         apenas lista os backups
 *   --verificar=ARQ  confere um backup pelo manifesto (hash SHA-256)
 *   --diagnostico    mostra o estado do backup automático
 *
 * Seguro para execução repetida: cria no máximo um snapshot por dia e nunca
 * apaga nada fora dos snapshots gerados por ele.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }

require __DIR__ . '/backup_lib.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/i', $a, $m)) $args[strtolower($m[1])] = $m[2] ?? true;
}

// Comandos de consulta terminam aqui (exit explícito para nunca cair no backup diário).
if (isset($args['listar'])) { bc_json_out(['ok' => true, 'backups' => bc_listar()]); exit(0); }
if (isset($args['diagnostico'])) { bc_json_out(['ok' => true] + bc_diagnostico()); exit(0); }
if (isset($args['verificar'])) {
    try { bc_json_out(['ok' => true] + bc_verificar((string)$args['verificar'], true)); exit(0); }
    catch (Throwable $e) { bc_json_out(['ok' => false, 'erro' => $e->getMessage()], 500); exit(1); }
}

$dias = isset($args['manter']) && is_numeric($args['manter']) ? max(1, (int)$args['manter']) : BC_RETENCAO_DIAS;
$sem = isset($args['semanal']) && is_numeric($args['semanal']) ? max(1, (int)$args['semanal']) : BC_RETENCAO_SEMANAS;
$r = bc_automatico($dias, $sem, isset($args['forcar']));
bc_json_out($r, ($r['ok'] ?? false) ? 200 : 500);
