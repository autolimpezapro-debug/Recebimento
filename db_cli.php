<?php
/** Sincronização do banco por cron (opcional): php -q /home/SEU_USUARIO/CAMINHO/db_cli.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso negado'); }
date_default_timezone_set('America/Sao_Paulo');
require __DIR__ . '/rd_db.php';
$forcar = in_array('--tudo', $argv, true);
if (!is_file(__DIR__ . '/data/items.json')) { $r = rd_db_restaurar_ausentes(); echo 'Restaurados do banco: ' . count($r) . "\n"; }
$r = rd_db_sincronizar($forcar);
echo ($r['ok'] ? 'OK' : 'FALHA: ' . $r['erro']) . ' — enviados: ' . count($r['enviados']) . "\n";
exit($r['ok'] ? 0 : 1);
