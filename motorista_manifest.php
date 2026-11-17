<?php
/** Manifesto do app do motorista: start_url já leva o token do link (necessário no iPhone, que não guarda o token do Safari). */
declare(strict_types=1);
define('DATA_DIR', __DIR__ . '/data');
function respond($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
require_once __DIR__ . '/motorista_lib.php';
$t = (string)($_GET['t'] ?? '');
header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: no-store');
$start = '/motorista.html' . (mot_token_valido($t) ? '?t=' . $t : '');
echo json_encode(['name' => 'Rota do Motorista — Drilling', 'short_name' => 'Rota Drilling', 'description' => 'Rotas de coleta do motorista', 'id' => '/motorista.html', 'start_url' => $start, 'scope' => '/motorista.html',
    'display' => 'standalone', 'orientation' => 'portrait', 'background_color' => '#0b1424', 'theme_color' => '#0b1424', 'lang' => 'pt-BR',
    'icons' => [['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'], ['src' => '/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']]], JSON_UNESCAPED_SLASHES);
