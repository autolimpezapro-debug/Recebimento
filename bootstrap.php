<?php
/**
 * Recebimento Drilling — Inicialização automática (standalone) v3.7.2
 * -------------------------------------------------------------------
 * O pacote passa a funcionar com "subir, extrair e abrir". Este arquivo é
 * chamado por api.php na primeira requisição e faz sozinho o que antes exigia
 * rodar instalar.php:
 *
 *   - cria as pastas de dados (data/, photos, xml, order_pdfs, backups, imports, estoque);
 *   - cria os .htaccess de proteção (nada de JSON/PHP/XML fica público);
 *   - cria o .htaccess da raiz (negando os scripts de linha de comando);
 *   - monta data/items.json a partir de data/seed.php, se ainda não existir;
 *   - NUNCA apaga nem sobrescreve dados existentes;
 *   - devolve mensagens legíveis em vez de tela em branco quando falta PHP 8
 *     ou quando a pasta data/ não tem permissão de escrita.
 *
 * instalar.php continua disponível como diagnóstico opcional, mas não é mais
 * necessário para o sistema funcionar.
 */

if (PHP_VERSION_ID < 80000) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Recebimento Drilling precisa de PHP 8.0 ou superior.\n";
    echo "PHP atual: " . PHP_VERSION . "\n\n";
    echo "No cPanel abra 'Select PHP Version' (ou MultiPHP INI Editor), escolha PHP 8.1 ou 8.2\n";
    echo "e confirme as extensoes: openssl, json, zip, gd, curl, fileinfo.\n";
    exit;
}

/**
 * Prepara a instalação. Retorna ['ok'=>bool,'feitos'=>string[],'erro'=>string].
 */
function rd_bootstrap(string $root): array {
    $feitos = [];
    $subpastas = ['data', 'data/photos', 'data/xml', 'data/order_pdfs', 'data/backups', 'data/imports', 'data/estoque'];
    foreach ($subpastas as $d) {
        $p = $root . '/' . $d;
        if (!is_dir($p)) {
            if (!@mkdir($p, $d === 'data/backups' ? 0700 : 0755, true) && !is_dir($p)) {
                return ['ok' => false, 'feitos' => $feitos, 'erro' => 'Não foi possível criar a pasta ' . $d . '/. Verifique as permissões no Gerenciador de Arquivos do cPanel.'];
            }
            $feitos[] = 'pasta ' . $d . '/ criada';
        }
    }
    $data = $root . '/data';
    if (!is_writable($data)) {
        return ['ok' => false, 'feitos' => $feitos, 'erro' => 'A pasta data/ não tem permissão de escrita. No cPanel, selecione data/ → Permissões → 0755 (ou 0775 se o servidor exigir).'];
    }

    // data/.htaccess protege todo o conteúdo (herdado pelas subpastas) e mantém as fotos legíveis.
    $ht = $data . '/.htaccess';
    if (!is_file($ht)) {
        $conteudo = "Options -Indexes\n"
            . "<FilesMatch \"(?i)\\.(json|php|xml|zip|key|lock|tmp|bak|log|pem|ini)$\">\nRequire all denied\n</FilesMatch>\n"
            . "<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n";
        if (@file_put_contents($ht, $conteudo, LOCK_EX) === false) return ['ok' => false, 'feitos' => $feitos, 'erro' => 'Não foi possível criar data/.htaccess.'];
        $feitos[] = 'data/.htaccess criado';
    }
    foreach (['data/backups', 'data/imports', 'data/order_pdfs'] as $d) {
        $f = $root . '/' . $d . '/.htaccess';
        if (!is_file($f)) {
            @file_put_contents($f, "Require all denied\nOptions -Indexes\n", LOCK_EX);
            $feitos[] = $d . '/.htaccess criado';
        }
    }
    $raiz = $root . '/.htaccess';
    if (!is_file($raiz)) {
        @file_put_contents($raiz, "# Recebimento Drilling — configuração mínima (Apache/LiteSpeed)\nOptions -Indexes\nDirectoryIndex index.html index.php\n"
            . "<FilesMatch \"(?i)^(seed\\.php|bootstrap\\.php|push_worker\\.php|diagnostico_nf_cli\\.php|mesclar_pedidos_sap_20261029\\.php|backup_cli\\.php|agenda_worker\\.php)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
        $feitos[] = '.htaccess da raiz criado';
    }

    // Base de pedidos: só cria quando não existe, a partir do seed do pacote.
    $items = $data . '/items.json';
    if (!is_file($items) && is_file($data . '/seed.php')) {
        $seed = @include $data . '/seed.php';
        if (is_array($seed) && $seed) {
            $agora = date('c');
            $rows = [];
            foreach ($seed as $pc => $r) {
                if (!is_array($r)) continue;
                $rows[] = [
                    'pc' => (int)$pc,
                    'material' => (string)($r['material'] ?? ''),
                    'fornecedor' => (string)($r['fornecedor'] ?? ''),
                    'situacao_origem' => (string)($r['situacao'] ?? 'PENDENTE'),
                    'chegou' => '', 'data' => '', 'qtd' => '', 'obs' => '', 'solicitante' => '',
                    'chaveNF' => '', 'fotoNF' => '', 'fotosProdutos' => [], 'nfResumo' => '',
                    'numeroNF' => '', 'serieNF' => '', 'cnpjEmitente' => '', 'emissaoNF' => '',
                    'valorNF' => '', 'itensNF' => '', 'origemNF' => '', 'xmlNF' => '',
                    'origem_entrega' => '', 'entregas' => [], 'historico_retificacoes' => [],
                    'insumos' => [], 'recebimento_estado' => '',
                    'recebimento_iniciado_em' => null, 'recebimento_finalizado_em' => null,
                    'ultima_acao' => 'Pedido cadastrado', 'ultima_acao_em' => $agora, 'updated_at' => $agora,
                    'importacao_origem' => '', 'importacao_arquivo' => '', 'importacao_hash' => '', 'importacao_em' => null,
                    'novo_pedido_em' => null,
                ];
            }
            usort($rows, fn($a, $b) => $a['pc'] <=> $b['pc']);
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json !== false && @file_put_contents($items . '.tmp', $json, LOCK_EX) !== false && @rename($items . '.tmp', $items)) {
                $feitos[] = 'base de pedidos criada a partir de data/seed.php (' . count($rows) . ' PCs)';
            } else {
                @unlink($items . '.tmp');
                return ['ok' => false, 'feitos' => $feitos, 'erro' => 'Não foi possível criar data/items.json. Confira a permissão de escrita em data/.'];
            }
        }
    }
    return ['ok' => true, 'feitos' => $feitos, 'erro' => ''];
}

/** Chamada única no topo de api.php. Em falha, responde JSON legível e encerra. */
function rd_bootstrap_or_die(string $root): void {
    rd_bootstrap_hardening($root);
    // Caminho rápido: quando a estrutura já está montada, duas checagens resolvem.
    if (is_file($root . '/data/items.json') && is_file($root . '/data/.htaccess')) return;
    $r = rd_bootstrap($root);
    if ($r['ok']) return;
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'error' => 'Instalação automática: ' . $r['erro'], 'feitos' => $r['feitos']], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * v3.7.4 — proteções idempotentes, executadas uma vez (marcador em data/.hardening-374).
 *  - Acrescenta ao .htaccess da RAIZ um bloco que nega error_log, *.zip, *.txt, scripts CLI
 *    e a pasta antiga data01/. NUNCA reescreve o arquivo: o cPanel grava ali a versão do PHP.
 *  - Remove permissão de escrita "para todos" (0666) de arquivos de senha/config em data/.
 * Nada aqui lê, move ou apaga dados.
 */
function rd_bootstrap_hardening(string $root): void {
    // v3.14.0: partes de upload maiores (menos requisições num arquivo de 1 GB). Só ACRESCENTA ao
    // .user.ini quando a diretiva ainda não existe — nunca altera o que foi configurado no cPanel.
    $f3140 = $root . '/data/.hardening-3140';
    if (!is_file($f3140) && is_dir($root . '/data')) {
        $ui = $root . '/.user.ini';
        $uc = is_file($ui) ? (string)@file_get_contents($ui) : '';
        $add = '';
        foreach (['upload_max_filesize' => '64M', 'post_max_size' => '72M', 'max_input_time' => '900'] as $k => $v)
            if (!preg_match('/^\s*' . $k . '\s*=/mi', $uc)) $add .= "$k = $v\n";
        if ($add !== '') @file_put_contents($ui, ($uc !== '' && substr($uc, -1) !== "\n" ? "\n" : '') . "; RD-3140 importacao ate 1 GB (enviada em partes)\n" . $add, FILE_APPEND | LOCK_EX);
        @file_put_contents($f3140, date('c'));
    }
    // v3.10.1: compressão das respostas (JSON/HTML/CSS/JS) — bem menos dados no celular.
    $f3101 = $root . '/data/.hardening-3101';
    if (!is_file($f3101) && is_dir($root . '/data')) {
        $rh = $root . '/.htaccess';
        $rc = is_file($rh) ? (string)@file_get_contents($rh) : '';
        if (strpos($rc, 'RD-3101') === false) {
            @file_put_contents($rh, "\n# RD-3101 compressao\n<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE application/json text/html text/css application/javascript text/javascript image/svg+xml\n</IfModule>\n", FILE_APPEND | LOCK_EX);
        }
        @file_put_contents($f3101, date('c'));
    }
    // v3.8.0: nega acesso web ao banco SQLite e a cópias .corrompido/.antes-restauracao.
    $f380 = $root . '/data/.hardening-380';
    if (!is_file($f380) && is_dir($root . '/data')) {
        $dh = $root . '/data/.htaccess';
        $cur = is_file($dh) ? (string)@file_get_contents($dh) : '';
        if (strpos($cur, 'RD-380') === false) {
            @file_put_contents($dh, "\n# RD-380 banco e copias de seguranca\n<FilesMatch \"(?i)(\\.(sqlite|sqlite3|db|db-wal|db-shm|sqlite-wal|sqlite-shm)$|\\.corrompido-|\\.antes-restauracao-)\">\nRequire all denied\n</FilesMatch>\n", FILE_APPEND | LOCK_EX);
        }
        $rh = $root . '/.htaccess';
        $rc = is_file($rh) ? (string)@file_get_contents($rh) : '';
        if (strpos($rc, 'RD-380') === false) {
            @file_put_contents($rh, "\n# RD-380 configuracao do banco\n<Files \"db_config.php\">\nRequire all denied\n</Files>\n", FILE_APPEND | LOCK_EX);
        }
        @file_put_contents($f380, date('c'));
    }
    $flag = $root . '/data/.hardening-374';
    if (is_file($flag) || !is_dir($root . '/data')) return;
    $ht = $root . '/.htaccess';
    $mark = '# BEGIN RecebimentoDrilling-protecao';
    $cur = is_file($ht) ? (string)@file_get_contents($ht) : '';
    if (strpos($cur, $mark) === false) {
        $bloco = "\n" . $mark . "\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"(?i)(^error_log$|\\.(zip|txt|log|bak|tmp|key)$|_cli\\.php$)\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n"
            . "RedirectMatch 403 ^/data01(/|$)\n"
            . "# END RecebimentoDrilling-protecao\n";
        if (@file_put_contents($ht, $bloco, FILE_APPEND | LOCK_EX) === false) return; // tenta de novo na próxima
    }
    foreach (['backup_admin_auth.json', 'backup_setup.key', 'operational_users.json', 'permissoes.json',
              'gemini_config.json', 'push-vapid.json', 'sessoes.json', 'acesso_config.json', 'seed.php'] as $f) {
        $p = $root . '/data/' . $f;
        if (is_file($p) && (@fileperms($p) & 0002)) @chmod($p, 0640);
    }
    // Bug até 3.7.3: a tela lia "semanas" em vez de "semanais" e, ao salvar a configuração,
    // gravava retenção semanal = 1. Restaura o padrão (8) uma única vez.
    $st = $root . '/data/backups/backup_state.json';
    if (is_file($st)) {
        $d = json_decode((string)@file_get_contents($st), true);
        if (is_array($d) && (int)($d['retencao_semanas'] ?? 0) === 1) {
            $d['retencao_semanas'] = 8;
            $tmp = $st . '.tmp';
            if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) @rename($tmp, $st);
        }
    }
    @file_put_contents($flag, date('c'));
}

