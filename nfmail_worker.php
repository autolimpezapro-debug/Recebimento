<?php
/**
 * Recebimento Drilling — Caixa de NFs por CRON (v3.59.0)
 * cPanel → Cron Jobs (a cada 10 minutos):   php -q /CAMINHO/ABSOLUTO/nfmail_worker.php
 * Lê a caixa de e-mail dedicada e guarda as NFs. O vínculo com os PCs é concluído na próxima
 * vez que alguém abrir o sistema (a tela aplica os vínculos automáticos pendentes).
 * Sem cron: a tela "Caixa de NFs" aberta também verifica a cada 10 minutos.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('America/Sao_Paulo');
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');
require __DIR__ . '/nfmail_lib.php';
try { echo json_encode(nfm_sincronizar('cron', false), JSON_UNESCAPED_UNICODE) . PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR, 'Caixa de NFs: ' . $e->getMessage() . PHP_EOL); exit(1); }
