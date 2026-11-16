<?php
/**
 * Recebimento Drilling — camada de banco de dados (v3.8.0)
 *
 * ESTRATÉGIA "ESPELHO": os arquivos JSON em data/ continuam sendo a base principal
 * (é o que já funciona e foi testado). Cada alteração é copiada para um banco SQL,
 * que guarda também o HISTÓRICO de versões. Se um arquivo sumir ou corromper
 * (extração errada, reinstalação, disco), o sistema o recupera do banco sozinho.
 *
 * Drivers: mysql (HostGator/cPanel — recomendado), sqlite (arquivo local, sem servidor),
 *          off (somente JSON, comportamento antigo).
 * Configuração: data/db_config.php (protegido pelo .htaccess de data/).
 *
 * Regras de segurança:
 *  - Falha do banco NUNCA impede o sistema de funcionar nem desfaz gravação no JSON.
 *  - O banco nunca sobrescreve um JSON válido sozinho; só recupera ausente/corrompido,
 *    e guarda o arquivo corrompido ao lado antes (…corrompido-AAAAMMDD-HHMMSS).
 */
if (!defined('RD_DB_ROOT')) define('RD_DB_ROOT', __DIR__);
const RD_DB_CONFIG = RD_DB_ROOT . '/db_config.php';          // na raiz: sobrevive à perda de data/
const RD_DB_CONFIG_ALT = RD_DB_ROOT . '/data/db_config.php';
const RD_DB_CACHE  = RD_DB_ROOT . '/data/.db_sync.json';
const RD_DB_LOG    = RD_DB_ROOT . '/data/db_sync.log';
const RD_DB_VERSOES_POR_ARQUIVO = 60;   // ~15 dias de histórico com uso contínuo

/** Arquivos que não ganham histórico de versões (logs e filas que mudam o tempo todo). */
const RD_DB_SEM_VERSAO = ['acessos_log.json', 'alertas_estado.json', 'emails_recebimento.json', 'notifications.json', 'sessoes.json', 'events.json', 'push-queue.json',
    'import_auth_attempts.json', 'operation_login_attempts.json', 'backup_auth_attempts.json', 'items.json.backup.json'];

function rd_db_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = ['driver' => 'off'];
    $arq = is_file(RD_DB_CONFIG) ? RD_DB_CONFIG : (is_file(RD_DB_CONFIG_ALT) ? RD_DB_CONFIG_ALT : '');
    if ($arq !== '') {
        $c = @include $arq;
        if (is_array($c)) $cfg = array_merge($cfg, $c);
    }
    return $cfg;
}
function rd_db_ativo(): bool { return in_array(rd_db_config()['driver'] ?? 'off', ['mysql', 'sqlite'], true); }

function rd_db_log(string $msg): void {
    @file_put_contents(RD_DB_LOG, '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    if (is_file(RD_DB_LOG) && filesize(RD_DB_LOG) > 512 * 1024) {
        $l = @file(RD_DB_LOG); if ($l) @file_put_contents(RD_DB_LOG, implode('', array_slice($l, -1500)), LOCK_EX);
    }
}

/** Conexão PDO. Lança exceção em falha (quem chama decide se é fatal). */
function rd_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = rd_db_config();
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 4];
    if ($c['driver'] === 'mysql') {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('Extensão pdo_mysql ausente no PHP.');
        $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . (int)($c['port'] ?? 3306) . ';dbname=' . $c['banco'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)$c['usuario'], (string)$c['senha'], $opt);
    } elseif ($c['driver'] === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Extensão pdo_sqlite ausente no PHP.');
        $arq = $c['arquivo'] ?? (RD_DB_ROOT . '/data/recebimento.sqlite');
        $pdo = new PDO('sqlite:' . $arq, null, null, $opt);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    } else {
        throw new RuntimeException('Banco desativado (driver off).');
    }
    rd_db_schema($pdo);
    return $pdo;
}

function rd_db_schema(PDO $pdo): void {
    $my = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $txt = $my ? 'LONGTEXT' : 'TEXT';
    $eng = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $auto = $my ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rd_documentos (
        chave VARCHAR(190) NOT NULL PRIMARY KEY, conteudo $txt NOT NULL, sha1 CHAR(40) NOT NULL,
        bytes INT NOT NULL, atualizado_em VARCHAR(32) NOT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rd_versoes (
        id $auto, chave VARCHAR(190) NOT NULL, conteudo $txt NOT NULL, sha1 CHAR(40) NOT NULL,
        bytes INT NOT NULL, criado_em VARCHAR(32) NOT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rd_pedidos (
        pc INT NOT NULL PRIMARY KEY, fornecedor VARCHAR(255), material $txt, situacao VARCHAR(80),
        chegou VARCHAR(30), numero_nf VARCHAR(60), chave_nf VARCHAR(60), recebimento_estado VARCHAR(30),
        recebimento_finalizado_em VARCHAR(32), atualizado_em VARCHAR(32), dados $txt NOT NULL)$eng");
    $blob = $my ? 'LONGBLOB' : 'BLOB';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rd_arquivos (
        caminho VARCHAR(190) NOT NULL PRIMARY KEY, conteudo $blob NOT NULL, sha1 CHAR(40) NOT NULL,
        bytes INT NOT NULL, enviado_em VARCHAR(32) NOT NULL)$eng");
    try { $pdo->exec('CREATE INDEX ix_rd_versoes_chave ON rd_versoes (chave, id)'); } catch (Throwable $e) { /* já existe */ }
}

/** Lista os arquivos JSON espelhados (relativos a data/). */
function rd_db_arquivos(): array {
    $base = RD_DB_ROOT . '/data';
    $out = [];
    foreach (glob($base . '/*.json') ?: [] as $f) $out[] = basename($f);
    foreach (['estoque', 'backups'] as $sub) {
        foreach (glob($base . '/' . $sub . '/*.json') ?: [] as $f) $out[] = $sub . '/' . basename($f);
    }
    sort($out);
    return $out;
}

/** Grava um documento no banco (com versão, se mudou). Retorna true se mudou. */
function rd_db_gravar_doc(PDO $pdo, string $chave, string $conteudo): bool {
    $sha = sha1($conteudo);
    $st = $pdo->prepare('SELECT sha1 FROM rd_documentos WHERE chave = ?');
    $st->execute([$chave]);
    $atual = $st->fetchColumn();
    if ($atual === $sha) return false;
    $agora = date('c');
    $pdo->beginTransaction();
    try {
        if ($atual === false) {
            $pdo->prepare('INSERT INTO rd_documentos (chave, conteudo, sha1, bytes, atualizado_em) VALUES (?,?,?,?,?)')
                ->execute([$chave, $conteudo, $sha, strlen($conteudo), $agora]);
        } else {
            $pdo->prepare('UPDATE rd_documentos SET conteudo=?, sha1=?, bytes=?, atualizado_em=? WHERE chave=?')
                ->execute([$conteudo, $sha, strlen($conteudo), $agora, $chave]);
        }
        if (!in_array(basename($chave), RD_DB_SEM_VERSAO, true)) {
            // Agrupa alterações seguidas: no máximo uma versão a cada 15 minutos por arquivo
            // (o estoque muda a cada movimento; sem isso o histórico ocuparia centenas de MB).
            $st = $pdo->prepare('SELECT id, criado_em FROM rd_versoes WHERE chave = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$chave]);
            $ult = $st->fetch();
            if ($ult && strtotime((string)$ult['criado_em']) > time() - 900) {
                $pdo->prepare('UPDATE rd_versoes SET conteudo=?, sha1=?, bytes=? WHERE id=?')->execute([$conteudo, $sha, strlen($conteudo), $ult['id']]);
            } else {
                $pdo->prepare('INSERT INTO rd_versoes (chave, conteudo, sha1, bytes, criado_em) VALUES (?,?,?,?,?)')
                    ->execute([$chave, $conteudo, $sha, strlen($conteudo), $agora]);
            }
            // Retenção: mantém as N versões mais recentes de cada arquivo.
            $st = $pdo->prepare('SELECT id FROM rd_versoes WHERE chave = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (RD_DB_VERSOES_POR_ARQUIVO - 1));
            $st->execute([$chave]);
            $corte = $st->fetchColumn();
            if ($corte) $pdo->prepare('DELETE FROM rd_versoes WHERE chave = ? AND id < ?')->execute([$chave, $corte]);
        }
        if ($chave === 'items.json') rd_db_pedidos($pdo, $conteudo);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return true;
}

/** Tabela consultável de pedidos (para relatórios/SQL). A fonte continua sendo o JSON. */
function rd_db_pedidos(PDO $pdo, string $json): void {
    $items = json_decode($json, true);
    if (!is_array($items)) return;
    $pdo->exec('DELETE FROM rd_pedidos');
    $ins = $pdo->prepare('INSERT INTO rd_pedidos (pc, fornecedor, material, situacao, chegou, numero_nf, chave_nf, recebimento_estado, recebimento_finalizado_em, atualizado_em, dados) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $vistos = [];
    foreach ($items as $i) {
        $pc = (int)($i['pc'] ?? 0);
        if ($pc <= 0 || isset($vistos[$pc])) continue;
        $vistos[$pc] = true;
        $ins->execute([$pc, mb_substr((string)($i['fornecedor'] ?? ''), 0, 255), (string)($i['material'] ?? ''),
            mb_substr((string)($i['situacao'] ?? ''), 0, 80), (string)($i['chegou'] ?? ''), mb_substr((string)($i['numeroNF'] ?? ''), 0, 60),
            mb_substr((string)($i['chaveNF'] ?? ''), 0, 60), (string)($i['recebimento_estado'] ?? ''),
            $i['recebimento_finalizado_em'] ?? null, $i['updated_at'] ?? null, json_encode($i, JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * Sincroniza para o banco todo arquivo que mudou desde a última vez.
 * Detecção barata por tamanho+mtime (cache local); só lê/compara o que mudou.
 */
function rd_db_sincronizar(bool $forcar = false): array {
    $res = ['ok' => true, 'enviados' => [], 'erro' => ''];
    if (!rd_db_ativo()) return ['ok' => false, 'enviados' => [], 'erro' => 'Banco desativado'];
    $lock = @fopen(RD_DB_ROOT . '/data/.db_sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => true, 'enviados' => [], 'erro' => 'sincronização já em andamento'];
    try {
        $bloqueou = false;
        $cache = $forcar ? [] : (json_decode((string)@file_get_contents(RD_DB_CACHE), true) ?: []);
        $pdo = rd_db();
        foreach (rd_db_arquivos() as $rel) {
            $abs = RD_DB_ROOT . '/data/' . $rel;
            clearstatcache(true, $abs);
            $assin = @filesize($abs) . ':' . @filemtime($abs);
            if (!$forcar && ($cache[$rel] ?? '') === $assin) continue;
            $conteudo = @file_get_contents($abs);
            if (!is_string($conteudo)) continue;
            if (json_decode($conteudo) === null && trim($conteudo) !== 'null') {
                rd_db_log('IGNORADO (JSON inválido, banco preservado): ' . $rel);
                continue; // nunca espelha um arquivo corrompido por cima da cópia boa
            }
            if ($rel === 'items.json' && !rd_db_items_plausivel($pdo, $conteudo)) {
                $bloqueou = true;
                rd_db_log('BLOQUEADO: items.json local tem bem menos pedidos que o banco. Banco preservado; verifique em banco.php.');
                @file_put_contents(RD_DB_ROOT . '/data/.db_ultima_falha', date('c') . ' items.json local com bem menos pedidos que o banco — sincronização bloqueada para proteger os dados');
                continue;
            }
            if (rd_db_gravar_doc($pdo, $rel, $conteudo)) $res['enviados'][] = $rel;
            $cache[$rel] = $assin;
        }
        $res['arquivos'] = rd_db_sincronizar_binarios($pdo, $cache, $forcar ? 400 : 25);
        @file_put_contents(RD_DB_CACHE, json_encode($cache), LOCK_EX);
        @file_put_contents(RD_DB_ROOT . '/data/.db_ultimo_ok', date('c'));
        if (!$bloqueou) @unlink(RD_DB_ROOT . '/data/.db_ultima_falha');
        if ($res['enviados']) rd_db_log('Sincronizado: ' . implode(', ', $res['enviados']));
    } catch (Throwable $e) {
        $res = ['ok' => false, 'enviados' => [], 'erro' => $e->getMessage()];
        rd_db_log('FALHA: ' . $e->getMessage());
        @file_put_contents(RD_DB_ROOT . '/data/.db_ultima_falha', date('c') . ' ' . $e->getMessage());
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    return $res;
}

/**
 * Chamada no fim de cada requisição (shutdown). Envia a resposta ao navegador primeiro
 * e só então sincroniza, para não atrasar a tela. GET sincroniza no máximo 1x/min.
 */
function rd_db_ao_final(): void {
    if (!rd_db_ativo()) return;
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $marca = RD_DB_ROOT . '/data/.db_ultimo_ok';
    if (!$post && is_file($marca) && time() - (int)@filemtime($marca) < 60) return;
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    try { rd_db_sincronizar(); } catch (Throwable $e) { /* nunca afeta o usuário */ }
}

/** Pastas de evidências espelhadas (fotos, NFs em PDF/imagem, XMLs). */
const RD_DB_PASTAS_BIN = ['photos', 'order_pdfs', 'xml', 'estoque', 'assinaturas', 'estoque/fotos_saida', 'obras'];
const RD_DB_MAX_BIN = 15 * 1024 * 1024;

function rd_db_lista_binarios(): array {
    $out = [];
    foreach (RD_DB_PASTAS_BIN as $pasta) {
        foreach (glob(RD_DB_ROOT . '/data/' . $pasta . '/*') ?: [] as $f) {
            $n = basename($f);
            if (!is_file($f) || $n[0] === '.' || preg_match('/\\.(json|tmp|lock)$/i', $n)) continue;
            $out[] = $pasta . '/' . $n;
        }
    }
    return $out;
}

/**
 * Envia evidências novas em lotes pequenos (para não estourar o tempo da requisição).
 * Fotos/PDFs não mudam depois de gravados: basta enviar uma vez por nome+tamanho.
 */
function rd_db_sincronizar_binarios(PDO $pdo, array &$cache, int $limite): int {
    $enviados = 0;
    $chk = $pdo->prepare('SELECT sha1 FROM rd_arquivos WHERE caminho = ?');
    $ins = $pdo->prepare('REPLACE INTO rd_arquivos (caminho, conteudo, sha1, bytes, enviado_em) VALUES (?,?,?,?,?)');
    foreach (rd_db_lista_binarios() as $rel) {
        if ($enviados >= $limite) break;
        $abs = RD_DB_ROOT . '/data/' . $rel;
        $assin = 'b:' . @filesize($abs) . ':' . @filemtime($abs);
        if (($cache['bin:' . $rel] ?? '') === $assin) continue;
        $tam = (int)@filesize($abs);
        if ($tam <= 0 || $tam > RD_DB_MAX_BIN) { $cache['bin:' . $rel] = $assin; continue; }
        $sha = sha1_file($abs);
        $chk->execute([$rel]);
        if ($chk->fetchColumn() !== $sha) {
            $ins->bindValue(1, $rel);
            $ins->bindValue(2, file_get_contents($abs), PDO::PARAM_LOB);
            $ins->bindValue(3, $sha); $ins->bindValue(4, $tam); $ins->bindValue(5, date('c'));
            $ins->execute();
            $enviados++;
        }
        $cache['bin:' . $rel] = $assin;
    }
    return $enviados;
}

/** Recria fotos/PDFs/XMLs ausentes a partir do banco. Nunca sobrescreve arquivo existente. */
function rd_db_restaurar_binarios(): int {
    $n = 0;
    $pdo = rd_db();
    $lista = $pdo->query('SELECT caminho FROM rd_arquivos')->fetchAll(PDO::FETCH_COLUMN);
    $get = $pdo->prepare('SELECT conteudo FROM rd_arquivos WHERE caminho = ?');
    foreach ($lista as $rel) {
        if (strpos($rel, '..') !== false) continue;
        $abs = RD_DB_ROOT . '/data/' . $rel;
        if (is_file($abs)) continue;
        $get->execute([$rel]);
        $bin = $get->fetchColumn();
        if (is_resource($bin)) $bin = stream_get_contents($bin);
        if (!is_string($bin)) continue;
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
        if (@file_put_contents($abs, $bin, LOCK_EX) !== false) $n++;
    }
    if ($n) rd_db_log('Evidências recuperadas do banco: ' . $n);
    return $n;
}

/** Impede que uma base nova/vazia (ex.: criada do seed numa reinstalação) apague a do banco. */
function rd_db_items_plausivel(PDO $pdo, string $json): bool {
    $local = json_decode($json, true);
    $nLocal = is_array($local) ? count($local) : 0;
    $nBanco = (int)$pdo->query('SELECT COUNT(*) FROM rd_pedidos')->fetchColumn();
    if ($nBanco < 20) return true;
    if (is_file(RD_DB_ROOT . '/data/.db_permitir_reducao')) { @unlink(RD_DB_ROOT . '/data/.db_permitir_reducao'); return true; }
    return $nLocal >= (int)floor($nBanco * 0.8);
}

/** Lê do banco a cópia de um arquivo (ou null). */
function rd_db_ler_doc(string $chave): ?string {
    if (!rd_db_ativo()) return null;
    try {
        $st = rd_db()->prepare('SELECT conteudo FROM rd_documentos WHERE chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return is_string($v) ? $v : null;
    } catch (Throwable $e) { rd_db_log('Leitura falhou (' . $chave . '): ' . $e->getMessage()); return null; }
}

/**
 * Recupera do banco um arquivo ausente ou corrompido. Nunca substitui um JSON válido.
 * O arquivo corrompido é guardado ao lado antes. Retorna true se recuperou.
 */
function rd_db_recuperar(string $rel): bool {
    $abs = RD_DB_ROOT . '/data/' . $rel;
    if (is_file($abs)) {
        $atual = @file_get_contents($abs);
        if (is_string($atual) && json_decode($atual) !== null) return false; // válido: não mexe
    }
    $copia = rd_db_ler_doc($rel);
    if ($copia === null || json_decode($copia) === null) return false;
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
    if (is_file($abs)) @rename($abs, $abs . '.corrompido-' . date('Ymd-His'));
    $tmp = $abs . '.tmp';
    if (@file_put_contents($tmp, $copia, LOCK_EX) === false || !@rename($tmp, $abs)) return false;
    rd_db_log('RECUPERADO do banco: ' . $rel);
    return true;
}

/**
 * Reinstalação/perda de pasta: se data/items.json não existe mas o banco tem cópia,
 * restaura TODOS os arquivos ausentes antes que o sistema crie uma base nova do seed.
 */
function rd_db_restaurar_ausentes(): array {
    $feitos = [];
    if (!rd_db_ativo()) return $feitos;
    try {
        $st = rd_db()->query('SELECT chave FROM rd_documentos');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chave) {
            if (strpos($chave, '..') !== false) continue;
            if (!is_file(RD_DB_ROOT . '/data/' . $chave) && rd_db_recuperar($chave)) $feitos[] = $chave;
        }
        if ($feitos) rd_db_restaurar_binarios();
    } catch (Throwable $e) { rd_db_log('Restauração automática falhou: ' . $e->getMessage()); }
    return $feitos;
}

/** Compara cada arquivo com o banco. */
function rd_db_verificar(): array {
    $linhas = [];
    $pdo = rd_db();
    $doBanco = [];
    foreach ($pdo->query('SELECT chave, sha1, bytes, atualizado_em FROM rd_documentos') as $r) $doBanco[$r['chave']] = $r;
    foreach (array_unique(array_merge(rd_db_arquivos(), array_keys($doBanco))) as $rel) {
        $abs = RD_DB_ROOT . '/data/' . $rel;
        $sha = is_file($abs) ? sha1_file($abs) : null;
        $b = $doBanco[$rel] ?? null;
        $linhas[] = ['arquivo' => $rel, 'local_bytes' => is_file($abs) ? filesize($abs) : null,
            'banco_bytes' => $b ? (int)$b['bytes'] : null, 'banco_em' => $b['atualizado_em'] ?? null,
            'situacao' => !$b ? 'só no arquivo' : ($sha === null ? 'só no banco' : ($sha === $b['sha1'] ? 'igual' : 'diferente'))];
    }
    usort($linhas, fn($a, $b) => strcmp($a['arquivo'], $b['arquivo']));
    return $linhas;
}

function rd_db_versoes(string $chave): array {
    $st = rd_db()->prepare('SELECT id, bytes, criado_em FROM rd_versoes WHERE chave = ? ORDER BY id DESC LIMIT 60');
    $st->execute([$chave]);
    return $st->fetchAll();
}

/** Restaura uma versão antiga, guardando o arquivo atual como .antes-restauracao-… */
function rd_db_restaurar_versao(int $id): string {
    $st = rd_db()->prepare('SELECT chave, conteudo FROM rd_versoes WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('Versão não encontrada.');
    $chave = (string)$r['chave'];
    if (strpos($chave, '..') !== false || json_decode((string)$r['conteudo']) === null) throw new RuntimeException('Versão inválida.');
    $abs = RD_DB_ROOT . '/data/' . $chave;
    $lockF = $chave === 'items.json' ? RD_DB_ROOT . '/data/.write.lock' : null;
    $lk = $lockF ? @fopen($lockF, 'c') : null;
    if ($lk) flock($lk, LOCK_EX);
    try {
        if (is_file($abs)) @copy($abs, $abs . '.antes-restauracao-' . date('Ymd-His'));
        $tmp = $abs . '.tmp';
        if (@file_put_contents($tmp, (string)$r['conteudo'], LOCK_EX) === false || !@rename($tmp, $abs))
            throw new RuntimeException('Não foi possível gravar ' . $chave);
    } finally { if ($lk) { flock($lk, LOCK_UN); fclose($lk); } }
    rd_db_log('Versão #' . $id . ' restaurada em ' . $chave);
    return $chave;
}
