<?php
/**
 * Recebimento Drilling — avisos da Agenda por CRON (v3.3.0)
 *
 * cPanel → Cron Jobs (uma vez por dia, de manhã):
 *   php -q /CAMINHO/ABSOLUTO/agenda_worker.php
 *
 * Publica os avisos de véspera, do dia e de atraso para todos os operadores
 * logados. Seguro para rodar várias vezes: cada aviso sai uma vez por dia.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Constantes antes do require: agenda_lib exige DATA_DIR definido.
date_default_timezone_set('America/Sao_Paulo');
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');
if (!defined('ITEMS_FILE')) define('ITEMS_FILE', DATA_DIR . '/items.json');
require __DIR__ . '/agenda_lib.php';

try {
    $r = ag_verificar_avisos(true);
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Agenda: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
