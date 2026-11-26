<?php
/** Execute via cron cPanel CLI, a cada minuto. Nunca exponha por URL. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('America/Sao_Paulo');
require __DIR__.'/push_lib.php';
try { echo json_encode(push_process_queue(200,50),JSON_UNESCAPED_UNICODE).PHP_EOL; }
catch(Throwable $e){fwrite(STDERR,'Web Push: '.$e->getMessage().PHP_EOL);exit(1);}
