<?php
/**
 * Recebimento Drilling — Instalador de 1 clique (v3.2.0) — HostGator / cPanel
 * ---------------------------------------------------------------------------
 * Envie este pacote por cima da pasta do sistema (Document Root) e abra:
 *   https://SEU-DOMINIO/instalar.php
 *
 * O instalador NUNCA apaga nem sobrescreve dados existentes:
 *   - atualização: mantém data/items.json, fotos, PDFs, XMLs, usuários e senhas;
 *   - instalação nova: cria a base a partir de data/seed.php;
 *   - sempre gera um backup dos dados antes de concluir;
 *   - o próprio instalador se bloqueia ao final (data/.installed).
 *
 * Para reinstalar/verificar de novo, remova data/.installed no Gerenciador de
 * Arquivos do cPanel (o instalador explica isso na tela final).
 */

declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const INST_VERSAO = '3.60.0';
const INST_ROOT = __DIR__;
const INST_DATA = __DIR__ . '/data';

// v3.10.1: com o sistema já instalado, o diagnóstico só abre para administrador logado
// (antes mostrava o resumo dos dados para qualquer pessoa que soubesse a URL).
if (is_file(INST_DATA . '/items.json')) {
    if (!defined('DATA_DIR')) define('DATA_DIR', INST_DATA);
    require_once __DIR__ . '/auth_users.php';
    $instId = rd_login_info();
    $instAdm = $instId && $instId['role'] === 'gestor';
    if ($instAdm && is_file(__DIR__ . '/perm_lib.php')) { require_once __DIR__ . '/perm_lib.php'; $instAdm = rd_e_admin_principal($instId['username']) || rd_perm('administracao', 'admin'); }
    if (!$instAdm) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><title>Instalador</title><body style="font-family:system-ui;padding:40px;background:#0c1526;color:#e6eefb"><h2>Sistema já instalado</h2><p>O diagnóstico do instalador só abre para o administrador logado.</p><p><a style="color:#7fb0ff" href="./">Abrir o sistema</a></p>');
    }
}
const INST_TRAVA = __DIR__ . '/data/.installed';

const INST_ARQUIVOS = [
    'index.html', 'manifest.webmanifest', 'sw.js',
    'api.php', 'auth_users.php', 'admin_backup.php', 'import_auth.php',
    'estoque_lib.php', 'estoque_api.php',
    'notify_lib.php', 'notify_api.php',
    'backup_lib.php', 'backup_api.php', 'backup_cli.php',
    'agenda_lib.php', 'agenda_api.php', 'agenda_worker.php',
    'coletas_lib.php', 'coletas_api.php', 'etiquetas_lib.php', 'etiquetas_api.php', 'perm_lib.php', 'usuarios_api.php',
    'push_lib.php', 'push_worker.php', 'versao_recebimento.php',
    'whatsapp_import_lib.php', 'whatsapp_import_api.php',
    'gemini_import.php', 'diagnostico_nf_cli.php', 'mesclar_pedidos_sap_20261029.php',
    'assets/app.css', 'assets/app.js', 'assets/auth.js', 'assets/nf.js',
    'assets/push.js', 'assets/pwa.js', 'assets/wizard.js',
    'assets/whatsapp-import.js', 'assets/admin-backup.js',
    'assets/estoque.css', 'assets/estoque.js',
    'assets/notify.css', 'assets/notify.js',
    'assets/backup.css', 'assets/backup.js', 'assets/premium.css',
    'assets/coletas.css', 'assets/coletas.js', 'assets/etiquetas.css', 'assets/etiquetas.js', 'assets/novo-pedido.js', 'assets/acesso.css', 'assets/acesso.js', 'assets/xlsx-lib.js', 'icons/logo-drilling.png',
    'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/leaflet.css',
    'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable-192.png',
    'icons/icon-maskable-512.png', 'icons/notification.svg',
    'LEIA_PRIMEIRO_INSTALADOR.txt',
];

/* ------------------------------------------------------------------ */
/* Diagnóstico do servidor                                              */
/* ------------------------------------------------------------------ */

function inst_checagens(): array {
    $dirs = ['data', 'data/photos', 'data/xml', 'data/order_pdfs', 'data/backups', 'data/imports', 'data/estoque'];
    $r = [];
    $r[] = ['PHP 8.0 ou superior', PHP_VERSION_ID >= 80000, 'Detectado: ' . PHP_VERSION];
    $r[] = ['Extensão OpenSSL (Web Push e senhas)', extension_loaded('openssl'), 'Necessária para push e hash'];
    $r[] = ['Extensão JSON', extension_loaded('json'), 'Leitura dos pedidos'];
    $r[] = ['Extensão ZIP (backup)', class_exists('ZipArchive'), 'Sem ela o backup automático não gera ZIP'];
    $r[] = ['Extensão GD (fotos)', function_exists('imagecreatetruecolor'), 'Opcional: reduz fotos enviadas'];
    $r[] = ['Extensão cURL (Web Push externo)', function_exists('curl_init'), 'Opcional: envio de push'];
    $r[] = ['Pasta data/ gravável', is_dir(INST_DATA) ? is_writable(INST_DATA) : is_writable(INST_ROOT), 'O sistema grava JSON aqui'];
    foreach ($dirs as $d) {
        $full = INST_ROOT . '/' . $d;
        $r[] = ['Pasta ' . $d . '/ gravável', (is_dir($full) && is_writable($full)) || is_writable(INST_ROOT), is_dir($full) ? '' : 'Será criada'];
    }
    $livre = @disk_free_space(INST_ROOT);
    $r[] = ['Espaço em disco (mín. 200 MB)', $livre === false || $livre > 200 * 1048576, $livre === false ? 'Não informado' : round($livre / 1048576) . ' MB livres'];
    $r[] = ['HTTPS ativo', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'), 'Recomendado para PWA, câmera e push'];
    return $r;
}

function inst_faltando(): array {
    return array_values(array_filter(INST_ARQUIVOS, fn($f) => !is_file(INST_ROOT . '/' . $f)));
}

function inst_resumo_dados(): array {
    $resumo = ['base' => 'vazia', 'pcs' => 0, 'conferencias' => 0, 'fotos' => 0, 'pdfs' => 0];
    $items = INST_DATA . '/items.json';
    if (is_file($items)) {
        $d = json_decode((string)@file_get_contents($items), true);
        if (is_array($d)) {
            $resumo['base'] = 'existente';
            $resumo['pcs'] = count($d);
            foreach ($d as $p) if (in_array($p['recebimento_estado'] ?? '', ['em_andamento', 'finalizado'], true)) $resumo['conferencias']++;
        }
    } elseif (is_file(INST_DATA . '/seed.php')) {
        $resumo['base'] = 'será criada pelo seed';
    }
    $resumo['fotos'] = count(glob(INST_DATA . '/photos/*') ?: []);
    $resumo['pdfs'] = count(glob(INST_DATA . '/order_pdfs/*.pdf') ?: []);
    return $resumo;
}

/* ------------------------------------------------------------------ */
/* Execução do instalador                                              */
/* ------------------------------------------------------------------ */

function inst_rodar(): array {
    $log = [];
    $ok = true;
    $passo = function (string $msg, bool $sucesso = true) use (&$log, &$ok) {
        $log[] = ['msg' => $msg, 'ok' => $sucesso];
        if (!$sucesso) $ok = false;
    };

    // 1. Pastas e proteções
    foreach (['data', 'data/photos', 'data/xml', 'data/order_pdfs', 'data/backups', 'data/imports', 'data/estoque'] as $d) {
        $full = INST_ROOT . '/' . $d;
        if (!is_dir($full)) @mkdir($full, $d === 'data/backups' ? 0700 : 0750, true);
        $passo('Pasta ' . $d . '/ ' . (is_dir($full) ? 'pronta' : 'FALHOU'), is_dir($full));
    }
    $ht = INST_DATA . '/.htaccess';
    // v3.51.3: repara o .htaccess da v3.37.0–3.45.0, que negava TUDO em data/ e quebrava as fotos dos produtos
    $htAtual = is_file($ht) ? (string)@file_get_contents($ht) : '';
    if (!is_file($ht) || (stripos($htAtual, 'FilesMatch') === false && stripos($htAtual, 'Require all denied') !== false)) {
        @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"(?i)\\.(json|php|xml|zip|key|lock|tmp|bak|log|pem)$\">\nRequire all denied\n</FilesMatch>\n<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
    }
    $passo('Proteção de data/.htaccess ' . (is_file($ht) ? 'ativa' : 'FALHOU'), is_file($ht));
    foreach (['data/backups', 'data/imports', 'data/order_pdfs'] as $d) {
        $f = INST_ROOT . '/' . $d . '/.htaccess';
        if (!is_file($f)) @file_put_contents($f, "Require all denied\nOptions -Indexes\n", LOCK_EX);
    }
    $raiz = INST_ROOT . '/.htaccess';
    if (!is_file($raiz)) {
        @file_put_contents($raiz, "# Recebimento Drilling — configuração mínima (HostGator/LiteSpeed)\nOptions -Indexes\nDirectoryIndex index.html index.php\n<FilesMatch \"(?i)^(seed\\.php|push_worker\\.php|diagnostico_nf_cli\\.php|mesclar_pedidos_sap_20261029\\.php|backup_cli\\.php|agenda_worker\\.php)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
        $passo('.htaccess da raiz criado (protege scripts de linha de comando)', true);
    } else {
        $passo('.htaccess da raiz já existia — preservado', true);
    }

    // 2. Base de pedidos (nunca sobrescreve)
    $items = INST_DATA . '/items.json';
    if (!is_file($items)) {
        $seed = INST_DATA . '/seed.php';
        if (is_file($seed)) {
            @set_time_limit(120);
            $salvo = $_GET['action'] ?? null;
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['action'] = 'list';
            // A API gera data/items.json a partir do seed na primeira leitura.
            // Executada em um processo PHP separado quando possível: a saída JSON
            // nunca pode cair no meio da página do instalador.
            $gerou = false;
            if (function_exists('exec')) {
                $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
                $script = INST_ROOT . '/api.php';
                $cmd = escapeshellarg($php) . ' -q -r ' . escapeshellarg('$_GET["action"]="list"; $_SERVER["REQUEST_METHOD"]="GET"; chdir(' . var_export(INST_ROOT, true) . '); include ' . var_export($script, true) . ';') . ' > /dev/null 2>&1';
                @exec($cmd);
                $gerou = is_file($items);
            }
            if (!$gerou) {
                ob_start();
                try {
                    include INST_ROOT . '/api.php';
                } catch (Throwable $e) {
                    // api.php encerra com exit; qualquer saída já está no buffer.
                }
                ob_end_clean();
            }
            if ($salvo === null) unset($_GET['action']); else $_GET['action'] = $salvo;
            $passo('Base de pedidos criada a partir de data/seed.php ' . (is_file($items) ? 'OK' : 'FALHOU'), is_file($items));
        } else {
            $passo('data/seed.php ausente: a base será criada no primeiro acesso', true);
        }
    } else {
        $passo('Base de pedidos existente preservada (' . inst_resumo_dados()['pcs'] . ' PCs) — nada foi sobrescrito', true);
    }

    // 3. Estoque: instala a estrutura sem alterar nada
    if (!defined('DATA_DIR')) define('DATA_DIR', INST_DATA);
    if (!defined('ITEMS_FILE')) define('ITEMS_FILE', INST_DATA . '/items.json');
    if (!defined('SEED_FILE')) define('SEED_FILE', INST_DATA . '/seed.php');
    if (!defined('EVENTS_FILE')) define('EVENTS_FILE', INST_DATA . '/events.json');
    try {
        require_once INST_ROOT . '/estoque_lib.php';
        if (function_exists('est_backup_once')) {
            est_backup_once();
            $passo('Estoque inicializado (cópia de segurança dos pedidos gerada em data/estoque/)', true);
        }
    } catch (Throwable $e) {
        $passo('Estoque: ' . $e->getMessage(), false);
    }

    // 4. Notificações
    if (!defined('DATA_DIR')) define('DATA_DIR', INST_DATA);
    try {
        require_once INST_ROOT . '/notify_lib.php';
        if (function_exists('notif_read')) {
            $n = notif_read();
            notif_write($n);
            $passo('Sistema de notificações pronto (data/notifications.json)', is_file(INST_DATA . '/notifications.json'));
        }
    } catch (Throwable $e) {
        $passo('Notificações: ' . $e->getMessage(), false);
    }

    // 5. Backup automático: estado + primeiro snapshot
    try {
        require_once INST_ROOT . '/backup_lib.php';
        if (function_exists('bc_automatico')) {
            $r = bc_automatico();
            $passo(
                !empty($r['ok'])
                    ? 'Backup automático configurado' . (!empty($r['backup']['arquivo']) ? ' — primeiro snapshot: ' . $r['backup']['arquivo'] : '')
                    : 'Backup automático: ' . ($r['erro'] ?? 'não gerado'),
                !empty($r['ok'])
            );
        }
    } catch (Throwable $e) {
        $passo('Backup: ' . $e->getMessage(), false);
    }

    // 6. Versão do pacote
    $v = INST_ROOT . '/versao_recebimento.php';
    $passo('Arquivos do sistema presentes (' . (count(INST_ARQUIVOS) - count(inst_faltando())) . '/' . count(INST_ARQUIVOS) . ')', count(inst_faltando()) === 0);

    // 7. Grava a conclusão
    $info = [
        'instalado_em' => date('c'),
        'versao' => INST_VERSAO,
        'dominio' => $_SERVER['HTTP_HOST'] ?? '',
        'resumo_dados' => inst_resumo_dados(),
        'php' => PHP_VERSION,
    ];
    @file_put_contents(INST_DATA . '/.installed', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return ['ok' => $ok, 'log' => $log, 'info' => $info];
}

/* ------------------------------------------------------------------ */
/* Interface                                                           */
/* ------------------------------------------------------------------ */

$acao = $_GET['acao'] ?? '';
$jaInstalado = is_file(INST_TRAVA);

// As bibliotecas do sistema só podem ser carregadas DEPOIS que o instalador
// souber o resultado. Carregá-las aqui (antes de qualquer saída) faria um
// "exit" da biblioteca encerrar a página em branco — já aconteceu e foi corrigido.
$libsOk = ['estoque' => false, 'notificacoes' => false, 'backup' => false];
$infoInstalado = $jaInstalado ? json_decode((string)@file_get_contents(INST_TRAVA), true) : null;
$resultado = null;

// Instalador já concluído: a página informa o estado e não executa mais nada.
if ($acao === 'executar' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($jaInstalado) {
        $resultado = ['ok' => false, 'log' => [['msg' => 'Instalador já concluído. Remova data/.installed para executar novamente.', 'ok' => false]]];
    } else {
        $resultado = inst_rodar();
    }
}

$checagens = inst_checagens();
$faltando = inst_faltando();
$dados = inst_resumo_dados();
$tudoOk = count(array_filter($checagens, fn($c) => !$c[1])) === 0;

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalador · Recebimento Drilling v<?= INST_VERSAO ?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:radial-gradient(ellipse at top,#122a4a,#080f1d 60%);color:#ecf3ff;line-height:1.5;padding:28px 16px 60px}
.wrap{max-width:980px;margin:0 auto}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.logo{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#336efc,#16d8ba);display:grid;place-items:center;font-weight:900;font-size:22px}
h1{font-size:26px;margin:0;letter-spacing:-.03em}
h2{font-size:16px;margin:0 0 10px}
p{margin:0 0 10px;font-size:13px;color:#b5c7dd}
.card{background:#111e32;border:1px solid #253550;border-radius:15px;padding:20px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid #22314a;font-size:13px}
.row:first-child{border-top:0}
.row small{color:#8fa3bd;font-size:11.5px}
.pill{font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:999px;white-space:nowrap}
.ok{background:#0e544e;color:#7df0d5}
.bad{background:#513147;color:#ffb2bb}
.warn{background:#554325;color:#ffd89a}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:11px;padding:14px 22px;font-size:14px;font-weight:800;cursor:pointer;background:linear-gradient(110deg,#3878ed,#467eef);color:#fff;box-shadow:0 6px 20px #155aca44}
.btn:hover{filter:brightness(1.1)}
.btn.ghost{background:#16243a;border:1px solid #2b3b55;box-shadow:none}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.stat{background:#14263e;border:1px solid #2b405f;border-radius:11px;padding:11px 13px}
.stat small{display:block;font-size:10px;color:#92a3bb;text-transform:uppercase;letter-spacing:.05em}
.stat strong{font-size:19px}
.log{display:grid;gap:6px;font-size:12.5px}
.log div{display:flex;gap:8px;align-items:flex-start}
code{background:#0b1729;border:1px solid #2b3b55;border-radius:7px;padding:2px 6px;font-size:12px;word-break:break-all}
.next{display:grid;gap:9px;padding-left:20px;font-size:13px;color:#c3d3e6}
.next strong{color:#fff}
.banner{border-radius:12px;padding:13px 15px;font-size:13px;margin-bottom:16px}
.banner.good{background:#0e3b34;border:1px solid #1f7f6b;color:#7df0d5}
.banner.bad{background:#3a2430;border:1px solid #7b3140;color:#ffb2bb}
@media (max-width:760px){.grid,.stats{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo">R</div><div><h1>Instalador — Recebimento Drilling</h1><p style="margin:0">Versão <?= INST_VERSAO ?> · cPanel HostGator · instalação em 1 clique</p></div></div>

<?php if ($jaInstalado): ?>
  <div class="banner good">✓ Sistema já instalado em <?= e($infoInstalado['instalado_em'] ?? '') ?> (versão <?= e($infoInstalado['versao'] ?? '') ?>).</div>
  <div class="card">
    <h2>O que fazer agora</h2>
    <ol class="next">
      <li><strong>Abra o sistema:</strong> <a href="index.html" style="color:#7db6ff">index.html</a> e confirme a versão na barra lateral.</li>
      <li><strong>Configure o backup automático:</strong> cPanel → Cron Jobs, uma vez por dia:<br><code>php -q <?= e(INST_ROOT) ?>/backup_cli.php</code></li>
      <li><strong>Proteja o acesso:</strong> cPanel → Directory Privacy (ou contas individuais) para o site não ficar público.</li>
      <li><strong>Para rodar o instalador de novo:</strong> apague <code>data/.installed</code> no Gerenciador de Arquivos.</li>
    </ol>
    <p style="margin-top:14px"><a class="btn" href="index.html">Abrir o Recebimento Drilling →</a></p>
  </div>
<?php elseif ($resultado !== null): ?>
  <?php if (!empty($resultado['ok'])): ?>
    <div class="banner good">✓ Instalação concluída com sucesso.</div>
  <?php else: ?>
    <div class="banner bad">⚠ Instalação concluída com pendências. Veja o log abaixo.</div>
  <?php endif; ?>
  <div class="card"><h2>Log da instalação</h2><div class="log">
    <?php foreach ($resultado['log'] as $l): ?>
      <div><span class="pill <?= $l['ok'] ? 'ok' : 'bad' ?>"><?= $l['ok'] ? 'OK' : 'ATENÇÃO' ?></span><span><?= e($l['msg']) ?></span></div>
    <?php endforeach; ?>
  </div></div>
  <div class="card"><h2>Próximos passos</h2>
    <ol class="next">
      <li><strong>Abra o sistema:</strong> <a href="index.html" style="color:#7db6ff">index.html</a> (force a recarga com Ctrl+F5).</li>
      <li><strong>Backup automático:</strong> cPanel → Cron Jobs, uma vez por dia:<br><code>php -q <?= e(INST_ROOT) ?>/backup_cli.php</code></li>
      <li><strong>Proteja o acesso:</strong> cPanel → Directory Privacy.</li>
      <li><strong>Teste um PC piloto</strong> no desktop e um no tablet antes de liberar para a equipe.</li>
    </ol>
    <p style="margin-top:14px"><a class="btn" href="index.html">Abrir o Recebimento Drilling →</a></p>
  </div>
<?php else: ?>
  <div class="grid">
    <div class="card">
      <h2>Diagnóstico do servidor</h2>
      <?php foreach ($checagens as $c): ?>
        <div class="row"><div><div><?= e($c[0]) ?></div><small><?= e($c[2]) ?></small></div>
          <span class="pill <?= $c[1] ? 'ok' : 'bad' ?>"><?= $c[1] ? 'OK' : 'AJUSTAR' ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h2>Seus dados</h2>
      <div class="stats">
        <div class="stat"><small>Base</small><strong style="font-size:14px"><?= e($dados['base']) ?></strong></div>
        <div class="stat"><small>PCs</small><strong><?= (int)$dados['pcs'] ?></strong></div>
        <div class="stat"><small>Conferências</small><strong><?= (int)$dados['conferencias'] ?></strong></div>
        <div class="stat"><small>Fotos</small><strong><?= (int)$dados['fotos'] ?></strong></div>
      </div>
      <p style="margin-top:12px"><?php if ($dados['base'] === 'existente'): ?>
        <strong>Atualização:</strong> os pedidos, conferências, fotos e PDFs existentes serão <strong>preservados</strong>. Nada é sobrescrito.
      <?php else: ?>
        <strong>Instalação nova:</strong> a base de pedidos será criada a partir de <code>data/seed.php</code>.
      <?php endif; ?></p>
      <?php if ($faltando): ?>
        <p class="pill bad" style="display:inline-block">Arquivos ausentes: <?= e(implode(', ', array_slice($faltando, 0, 6))) ?><?= count($faltando) > 6 ? '…' : '' ?></p>
      <?php else: ?>
        <p class="pill ok" style="display:inline-block">Todos os <?= count(INST_ARQUIVOS) ?> arquivos do pacote estão no lugar</p>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <h2>Instalar / atualizar</h2>
    <p>O instalador vai: criar as pastas privadas, aplicar as proteções do Apache, inicializar estoque e notificações, gerar o primeiro backup e registrar a versão. Ele nunca apaga dados.</p>
    <form method="post" action="instalar.php?acao=executar">
      <button class="btn" type="submit">⚡ Instalar agora</button>
      <?php if (!$tudoOk): ?><span class="pill warn" style="margin-left:10px">Há itens a ajustar — você pode instalar e corrigir depois</span><?php endif; ?>
    </form>
  </div>
<?php endif; ?>
</div>
</body>
</html>
