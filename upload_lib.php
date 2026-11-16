<?php
/**
 * Recebimento Drilling — upload em partes (v3.14.0)
 * Arquivos de até 1 GB chegam em pedaços (padrão 8 MB) e são montados em data/imports/<id>.part.
 * Assim o limite de upload do PHP/HostGator (upload_max_filesize/post_max_size) não importa.
 * O arquivo é só LIDO: depois da prévia ele é apagado (nada de anexo guardado no servidor).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const RD_UP_MAX = 1073741824;          // 1 GB
const RD_UP_TTL = 3 * 3600;            // partes abandonadas somem em 3 h

function rd_up_dir(): string {
    $d = DATA_DIR . '/imports';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    return $d;
}
function rd_ini_bytes(string $k): int {
    $v = trim((string)ini_get($k)); if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
    $n = (float)$v; $u = strtolower(substr($v, -1));
    return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
}
/** Tamanho seguro de cada parte, respeitando o limite real do servidor. */
function rd_up_parte_max(): int {
    $lim = min(rd_ini_bytes('upload_max_filesize'), rd_ini_bytes('post_max_size') - 65536);
    return (int)max(512 * 1024, min(8 * 1048576, $lim - 65536));
}
function rd_up_limpar(): void {
    foreach (glob(rd_up_dir() . '/*.{part,zip,pdf,meta}', GLOB_BRACE) ?: [] as $f) if (@filemtime($f) < time() - RD_UP_TTL) @unlink($f);
}
function rd_up_fail(string $m, int $c = 422) { respond(['ok' => false, 'error' => $m], $c); }

/** Recebe uma parte. Primeira parte (offset 0, sem id) cria o envio. */
function rd_up_parte(): array {
    $total = (int)($_POST['total'] ?? 0);
    $offset = (int)($_POST['offset'] ?? -1);
    $nome = basename(str_replace('\\', '/', (string)($_POST['nome'] ?? 'arquivo')));
    if (!preg_match('/\.(zip|pdf)$/i', $nome, $m)) rd_up_fail('Envie um arquivo ZIP ou PDF.');
    $ext = strtolower($m[1]);
    if ($total <= 0 || $total > RD_UP_MAX) rd_up_fail('O limite de importação é 1 GB.', 413);
    $f = $_FILES['parte'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) rd_up_fail('Falha ao enviar uma parte do arquivo. Tente novamente.', 400);
    $dir = rd_up_dir();
    $id = (string)($_POST['id'] ?? '');
    if ($offset === 0 && $id === '') { $id = bin2hex(random_bytes(20)); rd_up_limpar(); }
    if (!preg_match('/^[0-9a-f]{40}$/', $id)) rd_up_fail('Envio inválido.');
    $part = "$dir/$id.part";
    $atual = is_file($part) ? filesize($part) : 0;
    if ($offset !== $atual) return ['id' => $id, 'recebido' => $atual, 'completo' => false, 'reenviar_de' => $atual, 'parte_max' => rd_up_parte_max()];
    $in = fopen($f['tmp_name'], 'rb'); $out = fopen($part, 'ab');
    if (!$in || !$out) rd_up_fail('Não foi possível gravar o arquivo temporário.', 500);
    flock($out, LOCK_EX); stream_copy_to_stream($in, $out); fflush($out); flock($out, LOCK_UN); fclose($out); fclose($in);
    clearstatcache(true, $part);
    $rec = filesize($part);
    if ($rec > $total) { @unlink($part); rd_up_fail('Arquivo recebido maior que o informado. Envie novamente.'); }
    if ($rec < $total) return ['id' => $id, 'recebido' => $rec, 'completo' => false, 'parte_max' => rd_up_parte_max()];
    $fh = fopen($part, 'rb'); $magic = fread($fh, 5); fclose($fh);
    if (($ext === 'pdf' && $magic !== '%PDF-') || ($ext === 'zip' && !in_array(substr($magic, 0, 4), ["PK\x03\x04", "PK\x05\x06"], true))) {
        @unlink($part); rd_up_fail('O conteúdo do arquivo não corresponde à extensão PDF/ZIP.');
    }
    rename($part, "$dir/$id.$ext");
    file_put_contents("$dir/$id.meta", json_encode(['nome' => $nome, 'ext' => $ext, 'tamanho' => $rec, 'em' => date('c')]));
    return ['id' => $id, 'recebido' => $rec, 'completo' => true];
}

/** Arquivo montado e pronto para leitura: ['path','nome','ext'] ou null. */
function rd_up_arquivo(string $id): ?array {
    if (!preg_match('/^[0-9a-f]{40}$/', $id)) return null;
    $dir = rd_up_dir();
    $meta = json_decode((string)@file_get_contents("$dir/$id.meta"), true);
    if (!is_array($meta) || !in_array($meta['ext'] ?? '', ['zip', 'pdf'], true)) return null;
    $p = "$dir/$id.{$meta['ext']}";
    return is_file($p) ? ['path' => $p, 'nome' => $meta['nome'], 'ext' => $meta['ext'], 'tamanho' => filesize($p)] : null;
}
function rd_up_apagar(string $id): void {
    if (!preg_match('/^[0-9a-f]{40}$/', $id)) return;
    foreach (['part', 'zip', 'pdf', 'meta'] as $e) @unlink(rd_up_dir() . "/$id.$e");
}
