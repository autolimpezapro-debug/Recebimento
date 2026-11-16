<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
$files = [
    'index' => __DIR__ . '/index.html',
    'app' => __DIR__ . '/assets/app.js',
    'sw' => __DIR__ . '/sw.js',
];
$content = [];
foreach ($files as $name => $path) {
    $content[$name] = is_readable($path) ? file_get_contents($path) : '';
}
$index = null;
$app = null;
$sw = null;
if (preg_match('/Recebimento Drilling\s*·\s*v(\d+\.\d+\.\d+)/u', $content['index'], $match)) {
    $index = $match[1];
}
if (preg_match('/APP_VERSION\s*=\s*[\x27\x22](\d+\.\d+\.\d+)/', $content['app'], $match)) {
    $app = $match[1];
}
if (preg_match('/recebimento-drilling-shell-v(\d+\.\d+\.\d+)/', $content['sw'], $match)) {
    $sw = $match[1];
}
$ok = $index === '3.35.1' && $app === '3.35.1' && $sw === '3.35.1';
echo json_encode([
    'sistema' => 'Recebimento Drilling',
    'versao_do_pacote' => '3.35.1',
    'index_html' => $index,
    'app_js' => $app,
    'service_worker' => $sw,
    'index_php_concorrente' => is_file(__DIR__ . '/index.php'),
    'versao_consistente' => $ok,
    'orientacao' => $ok
        ? 'Arquivos atualizados nesta pasta. Se a tela exibir versao antiga, limpe o cache do navegador/PWA.'
        : 'Arquivos inconsistentes nesta pasta. Confira o destino da extracao no cPanel.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
