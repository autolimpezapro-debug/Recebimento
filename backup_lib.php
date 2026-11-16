<?php
/**
 * Recebimento Drilling — Backup Completo Automatizado (v3.2.0)
 * -----------------------------------------------------------
 * Complementa o módulo Administração/Backup (admin_backup.php) que já existe.
 * Aqui o foco é o que faltava para "backup completo" de verdade:
 *
 *  1. BACKUP AUTOMÁTICO: snapshot diário criado no servidor por cron (ou ao abrir
 *     a administração), com retenção configurável (padrão: 14 diários + 8 semanais).
 *  2. BACKUP COMPLETO: dados + código + manifesto com SHA-256 de cada arquivo.
 *  3. VERIFICAÇÃO: compara o ZIP com o manifesto e com o banco atual (integridade).
 *  4. DIAGNÓSTICO: mostra o que está dentro, tamanho, idade do último backup e
 *     avisa quando há pendências (ex.: sem backup há mais de 2 dias).
 *  5. CÓPIA EXTERNA OPCIONAL: envia o ZIP diário por e-mail (Gmail/SMTP) para que
 *     não fique só na hospedagem.
 *
 * Uso por linha de comando (cPanel → Cron Jobs):
 *   php -q /caminho/absoluto/backup_cli.php
 *   php -q /caminho/absoluto/backup_cli.php --manter=30 --semanal=8
 *
 * Regras de segurança:
 *  - Grava apenas em data/backups/ (privado, com .htaccess de bloqueio).
 *  - Nunca apaga nada fora dos próprios snapshots gerados por ele.
 *  - Nunca altera data/items.json, fotos, XMLs ou qualquer dado de pedido.
 */

declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

if (!defined('BC_ROOT')) define('BC_ROOT', __DIR__);
if (!defined('BC_DATA')) define('BC_DATA', BC_ROOT . '/data');
if (!defined('BC_BACKUPS')) define('BC_BACKUPS', BC_DATA . '/backups');
if (!defined('BC_STATE')) define('BC_STATE', BC_BACKUPS . '/backup_state.json');
if (!defined('BC_LOG')) define('BC_LOG', BC_BACKUPS . '/backup.log');
if (!defined('BC_LOCK')) define('BC_LOCK', BC_DATA . '/.backup.lock');
if (!defined('BC_MAX_TOTAL')) define('BC_MAX_TOTAL', 900000000); // 900 MB descompactado
if (!defined('BC_MAX_FILES')) define('BC_MAX_FILES', 20000);
if (!defined('BC_RETENCAO_DIAS')) define('BC_RETENCAO_DIAS', 14);
if (!defined('BC_RETENCAO_SEMANAS')) define('BC_RETENCAO_SEMANAS', 8);

/* ------------------------------------------------------------------ */
/* Utilidades                                                          */
/* ------------------------------------------------------------------ */

function bc_json_out(array $data, int $code = 200): void {
    if (PHP_SAPI === 'cli') { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL; return; }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bc_log(string $msg): void {
    $linha = '[' . date('c') . '] ' . $msg . PHP_EOL;
    if (!is_dir(BC_BACKUPS)) @mkdir(BC_BACKUPS, 0700, true);
    @file_put_contents(BC_LOG, $linha, FILE_APPEND | LOCK_EX);
}

function bc_state(): array {
    $raw = is_file(BC_STATE) ? @file_get_contents(BC_STATE) : '';
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [
        'versao' => 1,
        'ultimo_backup' => null,
        'ultimo_backup_bytes' => 0,
        'ultimo_backup_arquivos' => 0,
        'ultimo_erro' => null,
        'total_backups' => 0,
        'retencao_dias' => BC_RETENCAO_DIAS,
        'retencao_semanas' => BC_RETENCAO_SEMANAS,
        'email' => '',
    ];
}

function bc_state_save(array $state): void {
    if (!is_dir(BC_BACKUPS)) @mkdir(BC_BACKUPS, 0700, true);
    @file_put_contents(BC_STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function bc_guard(): void {
    if (!is_dir(BC_DATA)) @mkdir(BC_DATA, 0750, true);
    if (!is_dir(BC_BACKUPS)) @mkdir(BC_BACKUPS, 0700, true);
    $ht = BC_BACKUPS . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nOptions -Indexes\n", LOCK_EX);
}

function bc_lock() {
    $fp = @fopen(BC_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) return null;
    return $fp;
}

function bc_unlock($fp): void {
    if (is_resource($fp)) { flock($fp, LOCK_UN); fclose($fp); }
}

/** Somente arquivos do Recebimento — nunca outros sites da hospedagem. */
function bc_allowed(string $rel): bool {
    if ($rel === '' || str_contains($rel, '\\') || str_contains($rel, "\0") || str_starts_with($rel, '/')) return false;
    if (preg_match('~(^|/)\.\.?(/|$)~', $rel)) return false;
    if (preg_match('~^(?:index\.html|sw\.js|manifest\.webmanifest|\.htaccess|(?:[a-zA-Z0-9_-]+\.(?:php|txt|md|json)))$~D', $rel)) return true;
    if (preg_match('~^assets/[a-zA-Z0-9_.-]+\.(?:js|css)$~D', $rel)) return true;
    if (preg_match('~^icons/[a-zA-Z0-9_.-]+\.(?:svg|png|webp|ico)$~D', $rel)) return true;
    if (preg_match('~^testes/[a-zA-Z0-9_.-]+\.(?:py|js)$~D', $rel)) return true;
    if (!str_starts_with($rel, 'data/')) return false;
    // Backups antigos, controle de tentativas e travas ficam fora do ZIP.
    if ($rel === 'data/notifications.json' || $rel === 'data/devices.json' || $rel === 'data/coletas.json') return true;
    if (preg_match('~^data/(?:backups/|imports/|backup_admin_auth\.json$|backup_setup\.key$|backup_auth_attempts\.json$|gemini_config\.json$|\.write\.lock$|\.push\.lock$|\.notify\.lock$|\.estoque\.lock$|\.backup\.lock$|\.maintenance$|\.restore)~', $rel)) return false;
    if (preg_match('~^data/(?:[a-zA-Z0-9_.-]+\.json|(?:photos|xml|order_pdfs|estoque|obras|assinaturas)/[a-zA-Z0-9_.\-/]+\.(?:jpe?g|png|webp|gif|pdf|xml|json))$~D', $rel)) return true;
    if ($rel === 'data/.htaccess' || $rel === 'data/seed.php' || $rel === 'data/order_pdfs/.htaccess') return true;
    return false;
}

function bc_inventory(): array {
    $out = [];
    $root = realpath(BC_ROOT);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isLink() || !$file->isFile()) continue;
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if (!bc_allowed($rel)) continue;
        $out[$rel] = $file->getPathname();
        if (count($out) > BC_MAX_FILES) throw new RuntimeException('Quantidade de arquivos acima do limite do backup.');
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** Resumo dos dados que interessam ao operador (prova de que o backup tem o que importa). */
function bc_data_summary(): array {
    $items = BC_DATA . '/items.json';
    $resumo = ['pcs' => 0, 'conferencias' => 0, 'linhas_insumo' => 0, 'fotos' => 0, 'pdfs' => 0, 'xmls' => 0, 'estoque_itens' => 0, 'notificacoes' => 0];
    if (is_file($items)) {
        $d = json_decode((string)@file_get_contents($items), true);
        if (is_array($d)) {
            $resumo['pcs'] = count($d);
            foreach ($d as $p) {
                if (in_array($p['recebimento_estado'] ?? '', ['em_andamento', 'finalizado'], true)) $resumo['conferencias']++;
                $resumo['linhas_insumo'] += count($p['insumos'] ?? []);
            }
        }
    }
    $conta = fn(string $dir, string $ext) => count(glob(BC_DATA . '/' . $dir . '/*.' . $ext) ?: []);
    $resumo['fotos'] = count(glob(BC_DATA . '/photos/*') ?: []);
    $resumo['pdfs'] = $conta('order_pdfs', 'pdf');
    $resumo['xmls'] = $conta('xml', 'xml');
    if (is_file(BC_DATA . '/estoque.json')) {
        $e = json_decode((string)@file_get_contents(BC_DATA . '/estoque.json'), true);
        if (is_array($e)) $resumo['estoque_itens'] = count($e['itens'] ?? []);
    }
    if (is_file(BC_DATA . '/notifications.json')) {
        $n = json_decode((string)@file_get_contents(BC_DATA . '/notifications.json'), true);
        if (is_array($n)) $resumo['notificacoes'] = count($n['notificacoes'] ?? []);
    }
    return $resumo;
}

/* ------------------------------------------------------------------ */
/* Criação do ZIP                                                      */
/* ------------------------------------------------------------------ */

function bc_create(string $tipo = 'manual', array $opts = []): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ative a extensão PHP zip (ZipArchive) no cPanel.');
    bc_guard();
    $stamp = date('Ymd-His');
    $nome = sprintf('backup-%s-%s-%s.zip', $tipo, date('Ymd'), date('His'));
    $destino = BC_BACKUPS . '/' . $nome;
    $zip = new ZipArchive();
    if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível criar o ZIP. Verifique o espaço em disco.');
    }
    $arquivos = [];
    $bytes = 0;
    try {
        foreach (bc_inventory() as $rel => $abs) {
            $tamanho = filesize($abs);
            if ($tamanho === false || $tamanho < 0) throw new RuntimeException('Erro ao ler ' . $rel);
            $bytes += $tamanho;
            if ($bytes > BC_MAX_TOTAL) throw new RuntimeException('Backup acima de 900 MB. Reduza fotos/PDFs antigos ou amplie o limite.');
            if (!$zip->addFile($abs, $rel)) throw new RuntimeException('Não foi possível anexar ' . $rel);
            $arquivos[] = ['path' => $rel, 'bytes' => $tamanho, 'sha256' => hash_file('sha256', $abs)];
        }
        $manifesto = [
            'type' => 'recebimento-drilling-complete-backup',
            'schema' => 2,
            'created_at' => date('c'),
            'app_version' => '3.2.0',
            'tipo' => $tipo,
            'files' => $arquivos,
            'file_count' => count($arquivos),
            'total_bytes' => $bytes,
            'data_summary' => bc_data_summary(),
            'exclusions' => ['data/backups', 'data/imports', 'senhas administrativas', 'controle de tentativas', 'travas'],
        ];
        if (!$zip->addFromString('RECEBIMENTO_BACKUP_MANIFEST.json', json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))) {
            throw new RuntimeException('Erro ao gravar o manifesto.');
        }
        $fechado = true;   // close() invalida o objeto, com ou sem sucesso: não chamar de novo no catch
        if (!$zip->close()) throw new RuntimeException('Não foi possível concluir o ZIP: ' . ($zip->getStatusString() ?: 'erro desconhecido') . '. Verifique permissões de leitura dos arquivos e o espaço em disco.');
    } catch (Throwable $e) {
        if (empty($fechado)) { try { $zip->close(); } catch (Throwable $e2) {} }
        @unlink($destino);
        throw $e;
    }
    $tamanhoZip = (int)@filesize($destino);
    bc_log(sprintf('Backup %s criado: %s (%d arquivos, %.2f MB)', $tipo, $nome, count($arquivos), $tamanhoZip / 1048576));
    return [
        'arquivo' => $nome,
        'caminho' => $destino,
        'bytes' => $tamanhoZip,
        'arquivos' => count($arquivos),
        'bytes_descompactado' => $bytes,
        'resumo' => $manifesto['data_summary'],
        'tipo' => $tipo,
        'criado_em' => date('c'),
    ];
}

/** Rotina do cron: cria o snapshot do dia, aplica retenção e registra o estado. */
function bc_automatico(int $dias = BC_RETENCAO_DIAS, int $semanas = BC_RETENCAO_SEMANAS, bool $forcar = false): array {
    bc_guard();
    $state = bc_state();
    $hoje = date('Y-m-d');
    $ultimo = substr((string)($state['ultimo_backup'] ?? ''), 0, 10);
    if (!$forcar && $ultimo === $hoje) {
        return ['ok' => true, 'ignorado' => true, 'motivo' => 'Já existe backup de hoje', 'ultimo_backup' => $state['ultimo_backup']];
    }
    $fp = bc_lock();
    if (!$fp) return ['ok' => false, 'erro' => 'Outro backup está em execução'];
    try {
        // Guarda o semanal (domingo) e o diário.
        $tipo = ((int)date('N') === 7) ? 'semanal' : 'diario';
        $info = bc_create($tipo);
        $state['ultimo_backup'] = $info['criado_em'];
        $state['ultimo_backup_bytes'] = $info['bytes'];
        $state['ultimo_backup_arquivos'] = $info['arquivos'];
        $state['ultimo_backup_resumo'] = $info['resumo'];
        $state['ultimo_erro'] = null;
        $state['total_backups'] = (int)($state['total_backups'] ?? 0) + 1;
        $state['retencao_dias'] = $dias;
        $state['retencao_semanas'] = $semanas;
        bc_state_save($state);
        $removidos = bc_reter($dias, $semanas);
        return ['ok' => true, 'backup' => $info, 'removidos' => $removidos, 'retencao' => ['diarios' => $dias, 'semanais' => $semanas]];
    } catch (Throwable $e) {
        $state['ultimo_erro'] = ['em' => date('c'), 'mensagem' => $e->getMessage()];
        bc_state_save($state);
        bc_log('ERRO: ' . $e->getMessage());
        return ['ok' => false, 'erro' => $e->getMessage()];
    } finally {
        bc_unlock($fp);
    }
}

/** Retenção: mantém N diários recentes + N semanais. Nunca toca em snapshots pré-restauração. */
function bc_reter(int $dias, int $semanas): array {
    $arquivos = [];
    foreach (glob(BC_BACKUPS . '/backup-*.zip') ?: [] as $f) {
        $arquivos[] = ['path' => $f, 'ts' => (int)filemtime($f), 'nome' => basename($f)];
    }
    usort($arquivos, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $diarios = 0;
    $semanais = 0;
    $removidos = [];
    foreach ($arquivos as $a) {
        $semanal = str_contains($a['nome'], '-semanal-');
        if ($semanal) {
            $semanais++;
            if ($semanais > $semanas) { @unlink($a['path']); $removidos[] = $a['nome']; }
        } else {
            $diarios++;
            if ($diarios > $dias) { @unlink($a['path']); $removidos[] = $a['nome']; }
        }
    }
    if ($removidos) bc_log('Retenção removeu: ' . implode(', ', $removidos));
    return $removidos;
}

/* ------------------------------------------------------------------ */
/* Verificação e listagem                                              */
/* ------------------------------------------------------------------ */

function bc_listar(): array {
    $lista = [];
    foreach (glob(BC_BACKUPS . '/*.zip') ?: [] as $f) {
        $nome = basename($f);
        $tipo = str_starts_with($nome, 'pre-restauracao-') ? 'pré-restauração'
            : (str_contains($nome, '-semanal-') ? 'semanal' : (str_contains($nome, '-diario-') ? 'diário' : 'manual'));
        $lista[] = [
            'nome' => $nome,
            'tipo' => $tipo,
            'bytes' => (int)@filesize($f),
            'criado_em' => date('c', (int)@filemtime($f)),
            'idade_horas' => round((time() - (int)@filemtime($f)) / 3600, 1),
        ];
    }
    usort($lista, fn($a, $b) => strcmp($b['criado_em'], $a['criado_em']));
    return $lista;
}

/** Confere o ZIP contra o próprio manifesto (tamanho e hash de cada arquivo). */
function bc_verificar(string $arquivo, bool $profundo = false): array {
    $path = BC_BACKUPS . '/' . basename($arquivo);
    if (!is_file($path)) throw new RuntimeException('Backup não encontrado.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão ZipArchive indisponível.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('ZIP inválido ou corrompido.');
    $raw = $zip->getFromName('RECEBIMENTO_BACKUP_MANIFEST.json');
    $man = is_string($raw) ? json_decode($raw, true) : null;
    $problemas = [];
    if (!is_array($man)) { $zip->close(); throw new RuntimeException('Backup sem manifesto — não é um backup deste sistema.'); }
    $achados = 0;
    foreach (($man['files'] ?? []) as $f) {
        $i = $zip->locateName($f['path']);
        if ($i === false) { $problemas[] = 'Ausente: ' . $f['path']; continue; }
        if ($profundo) {
            $conteudo = $zip->getFromIndex($i);
            if ($conteudo === false || hash('sha256', $conteudo) !== $f['sha256']) $problemas[] = 'Hash divergente: ' . $f['path'];
        }
        $achados++;
    }
    $zip->close();
    return [
        'arquivo' => basename($arquivo),
        'arquivos_no_manifesto' => (int)($man['file_count'] ?? 0),
        'arquivos_encontrados' => $achados,
        'verificacao_profunda' => $profundo,
        'integro' => count($problemas) === 0,
        'problemas' => array_slice($problemas, 0, 25),
        'criado_em' => $man['created_at'] ?? null,
        'resumo_dados' => $man['data_summary'] ?? [],
        'total_bytes' => $man['total_bytes'] ?? 0,
    ];
}

/** Diagnóstico para o painel: estado do backup automático + comparação com os dados atuais. */
function bc_diagnostico(): array {
    $state = bc_state();
    $lista = bc_listar();
    $agora = bc_data_summary();
    $ultimo = $lista[0] ?? null;
    $horas = $ultimo ? $ultimo['idade_horas'] : null;
    $alertas = [];
    if (!$ultimo) $alertas[] = 'Nenhum backup encontrado. Gere o primeiro agora.';
    elseif ($horas !== null && $horas > 48) $alertas[] = sprintf('Último backup há %.1f horas. Configure o cron diário.', $horas);
    if (!class_exists('ZipArchive')) $alertas[] = 'Extensão ZipArchive ausente: backups não podem ser gerados.';
    if (!is_writable(BC_BACKUPS)) $alertas[] = 'A pasta data/backups não tem permissão de escrita.';
    $espaco = @disk_free_space(BC_DATA);
    if ($espaco !== false && $espaco < 200 * 1048576) $alertas[] = 'Menos de 200 MB livres em disco.';
    return [
        'estado' => $state,
        'ultimo_backup' => $ultimo,
        'total_backups' => count($lista),
        'backups' => array_slice($lista, 0, 20),
        'dados_atuais' => $agora,
        'alertas' => $alertas,
        'espaco_livre_bytes' => $espaco === false ? null : (int)$espaco,
        'zip_disponivel' => class_exists('ZipArchive'),
        'pasta_gravavel' => is_writable(BC_BACKUPS),
        'retencao' => ['diarios' => (int)($state['retencao_dias'] ?? BC_RETENCAO_DIAS), 'semanais' => (int)($state['retencao_semanas'] ?? BC_RETENCAO_SEMANAS)],
        'cron' => '*/30 2 * * * php -q ' . BC_ROOT . '/backup_cli.php',
    ];
}
