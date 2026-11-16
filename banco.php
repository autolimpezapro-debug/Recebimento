<?php
/** Recebimento Drilling — painel do banco de dados (v3.8.0). Somente administrador logado. */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');
require_once __DIR__ . '/auth_users.php';
require_once __DIR__ . '/rd_db.php';

$id = rd_login_info();
$admin = $id && $id['role'] === 'gestor';
if ($admin && is_file(__DIR__ . '/perm_lib.php')) {
    require_once __DIR__ . '/perm_lib.php';
    if (function_exists('rd_e_admin_principal') && function_exists('rd_perm')) {
        $admin = rd_e_admin_principal($id['username']) || rd_perm('administracao', 'admin');
    }
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (!$admin) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Banco</title><body style="font-family:system-ui;padding:40px;background:#0c1526;color:#e6eefb">'
       . '<h2>Acesso restrito</h2><p>Entre no sistema como administrador e volte a esta página.</p><p><a style="color:#7fb0ff" href="./">Abrir o sistema</a></p>';
    exit;
}

$msg = ''; $erro = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tok = (string)($_POST['csrf'] ?? '');
    if (!rd_origin_valid() || $tok === '' || !hash_equals((string)($_SESSION['rd_token'] ?? ''), $tok)) {
        $erro = 'Sessão expirada ou origem inválida. Recarregue a página.';
    } else {
        try {
            switch ($_POST['acao'] ?? '') {
                case 'sincronizar':
                    $r = rd_db_sincronizar(true);
                    if (!$r['ok']) throw new RuntimeException($r['erro']);
                    $msg = 'Sincronização: ' . count($r['enviados']) . ' arquivo(s) de dados e ' . (int)($r['arquivos'] ?? 0) . ' foto(s)/PDF(s) enviados ao banco.';
                    break;
                case 'evidencias':
                    $n = rd_db_restaurar_binarios();
                    $msg = $n ? $n . ' foto(s)/PDF(s)/XML(s) recuperados do banco.' : 'Nenhuma evidência faltando — todas estão na pasta.';
                    break;
                case 'restaurar':
                    $chave = rd_db_restaurar_versao((int)($_POST['versao'] ?? 0));
                    $msg = 'Versão restaurada em data/' . $chave . '. O arquivo anterior foi guardado como .antes-restauracao-…';
                    break;
                default: $erro = 'Ação desconhecida.';
            }
        } catch (Throwable $e) { $erro = $e->getMessage(); }
    }
}

$cfg = rd_db_config();
$conexao = 'desativado'; $verif = []; $versoes = []; $qtdPedidos = null; $qtdBin = null; $qtdBinLocal = count(rd_db_lista_binarios());
if (rd_db_ativo()) {
    try {
        $pdo = rd_db();
        $conexao = 'conectado (' . h($cfg['driver']) . ($cfg['driver'] === 'mysql' ? ' · ' . h($cfg['banco']) . '@' . h($cfg['host'] ?? 'localhost') : '') . ')';
        $verif = rd_db_verificar();
        $qtdPedidos = (int)$pdo->query('SELECT COUNT(*) FROM rd_pedidos')->fetchColumn();
        $qtdBin = (int)$pdo->query('SELECT COUNT(*) FROM rd_arquivos')->fetchColumn();
        $arqV = (string)($_GET['versoes'] ?? '');
        if ($arqV !== '' && strpos($arqV, '..') === false) $versoes = rd_db_versoes($arqV);
    } catch (Throwable $e) { $conexao = 'FALHA: ' . h($e->getMessage()); }
}
$ultimoOk = is_file(__DIR__ . '/data/.db_ultimo_ok') ? (string)file_get_contents(__DIR__ . '/data/.db_ultimo_ok') : '—';
$ultimaFalha = is_file(__DIR__ . '/data/.db_ultima_falha') ? (string)file_get_contents(__DIR__ . '/data/.db_ultima_falha') : '';
$csrf = h($_SESSION['rd_token'] ?? '');
$cont = array_count_values(array_column($verif, 'situacao'));
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Banco de dados · Recebimento Drilling</title>
<style>
body{margin:0;font-family:Inter,system-ui,sans-serif;background:#0c1526;color:#e6eefb;font-size:14px}
main{max-width:1100px;margin:0 auto;padding:28px 20px 60px}
a{color:#7fb0ff}h1{font-size:24px;margin:6px 0 4px}.muted{color:#8ca0ba;font-size:12px}
.card{background:#132036;border:1px solid #2b3b55;border-radius:12px;padding:16px 18px;margin:14px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.kpi strong{display:block;font-size:22px;margin-top:4px}.kpi span{color:#8ca0ba;font-size:11px;text-transform:uppercase;letter-spacing:.06em}
.ok{color:#45d6b0}.bad{color:#ff8b98}.warn{color:#f1ac47}
button{background:#3878ed;color:#fff;border:0;border-radius:8px;padding:9px 14px;font-weight:700;cursor:pointer}
button.ghost{background:#21334d}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:7px 8px;border-top:1px solid #25334b;text-align:left}
th{color:#9ab0ce;font-size:10px;text-transform:uppercase}
.msg{padding:12px 14px;border-radius:9px;margin:12px 0}.msg.ok{background:#123f37;color:#bff5e5}.msg.err{background:#4a2130;color:#ffd0d6}
</style></head><body><main>
<a href="./">← Voltar ao sistema</a>
<h1>Banco de dados</h1>
<p class="muted">Os arquivos em <code>data/</code> continuam sendo a base principal. O banco guarda uma cópia sincronizada e o histórico de versões; se algum arquivo sumir ou corromper, o sistema o recupera daqui automaticamente.</p>
<?php if ($msg) echo '<div class="msg ok">' . h($msg) . '</div>'; if ($erro) echo '<div class="msg err">' . h($erro) . '</div>'; ?>
<div class="card grid">
  <div class="kpi"><span>Conexão</span><strong class="<?= str_starts_with($conexao, 'conectado') ? 'ok' : 'bad' ?>" style="font-size:14px"><?= $conexao ?></strong></div>
  <div class="kpi"><span>Última sincronização</span><strong style="font-size:14px"><?= h($ultimoOk) ?></strong></div>
  <div class="kpi"><span>Pedidos no banco</span><strong><?= $qtdPedidos ?? '—' ?></strong></div>
  <div class="kpi"><span>Fotos/PDFs/XMLs no banco</span><strong class="<?= $qtdBin !== null && $qtdBin >= $qtdBinLocal ? 'ok' : 'warn' ?>"><?= $qtdBin ?? '—' ?> <small style="font-size:12px;color:#8ca0ba">de <?= $qtdBinLocal ?></small></strong></div>
  <div class="kpi"><span>Arquivos iguais</span><strong class="ok"><?= (int)($cont['igual'] ?? 0) ?></strong></div>
  <div class="kpi"><span>Pendentes</span><strong class="<?= (($cont['diferente'] ?? 0) + ($cont['só no arquivo'] ?? 0)) ? 'warn' : 'ok' ?>"><?= (int)(($cont['diferente'] ?? 0) + ($cont['só no arquivo'] ?? 0)) ?></strong></div>
</div>
<?php if ($ultimaFalha): ?><div class="msg err">Última falha registrada: <?= h($ultimaFalha) ?></div><?php endif; ?>
<?php if (!rd_db_ativo()): ?>
<div class="card">O banco está desativado. Configure <code>data/db_config.php</code> (driver <code>mysql</code> ou <code>sqlite</code>).</div>
<?php else: ?>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="sincronizar">
  <button>Sincronizar tudo agora</button> <span class="muted">Envia dados e evidências para o banco (normalmente acontece sozinho a cada alteração, em lotes). Se ainda faltarem fotos/PDFs, clique de novo.</span>
</form>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="evidencias">
  <button class="ghost">Recuperar fotos/PDFs ausentes</button> <span class="muted">Recria a partir do banco só os arquivos que sumiram da pasta. Não substitui nada que existe.</span>
</form>
<div class="card"><h3 style="margin-top:0">Arquivos</h3>
<table><tr><th>Arquivo</th><th>Local</th><th>Banco</th><th>Atualizado no banco</th><th>Situação</th><th></th></tr>
<?php foreach ($verif as $l): $cls = $l['situacao'] === 'igual' ? 'ok' : ($l['situacao'] === 'só no banco' ? 'bad' : 'warn'); ?>
<tr><td><?= h($l['arquivo']) ?></td><td><?= $l['local_bytes'] === null ? '—' : number_format($l['local_bytes'] / 1024, 1, ',', '.') . ' KB' ?></td>
<td><?= $l['banco_bytes'] === null ? '—' : number_format($l['banco_bytes'] / 1024, 1, ',', '.') . ' KB' ?></td><td><?= h($l['banco_em'] ?? '—') ?></td>
<td class="<?= $cls ?>"><?= h($l['situacao']) ?></td><td><?php if (!in_array(basename($l['arquivo']), RD_DB_SEM_VERSAO, true)): ?><a href="?versoes=<?= urlencode($l['arquivo']) ?>#versoes">versões</a><?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<?php if (isset($_GET['versoes'])): ?>
<div class="card" id="versoes"><h3 style="margin-top:0">Versões de <?= h($_GET['versoes']) ?></h3>
<?php if (!$versoes): ?><p class="muted">Nenhuma versão guardada.</p><?php else: ?>
<p class="muted">Restaurar substitui o arquivo atual pela versão escolhida. O atual é guardado antes como <code>.antes-restauracao-…</code>.</p>
<table><tr><th>#</th><th>Data</th><th>Tamanho</th><th></th></tr>
<?php foreach ($versoes as $v): ?><tr><td><?= (int)$v['id'] ?></td><td><?= h($v['criado_em']) ?></td><td><?= number_format($v['bytes'] / 1024, 1, ',', '.') ?> KB</td>
<td><form method="post" onsubmit="return confirm('Restaurar esta versão? O arquivo atual será guardado antes.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="restaurar"><input type="hidden" name="versao" value="<?= (int)$v['id'] ?>"><button class="ghost">Restaurar</button></form></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>
<?php endif; endif; ?>
</main></body></html>
