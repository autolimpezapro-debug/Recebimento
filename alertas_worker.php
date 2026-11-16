<?php
/**
 * Recebimento Drilling — alertas de estoque baixo por CRON (v3.26.0)
 * cPanel → Cron Jobs (de hora em hora):   php -q /CAMINHO/ABSOLUTO/alertas_worker.php
 * Opcional: sem cron, as telas abertas já verificam a cada 15 min.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('America/Sao_Paulo');
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');
require __DIR__ . '/alertas_lib.php';
try { echo json_encode(alt_verificar('cron'), JSON_UNESCAPED_UNICODE) . PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR, 'Alertas: ' . $e->getMessage() . PHP_EOL); exit(1); }
