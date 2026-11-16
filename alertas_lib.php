<?php
/**
 * Recebimento Drilling — Alertas de estoque baixo (v3.26.0)
 *
 * Quando o saldo de um item chega no PONTO DE REPOSIÇÃO (ou no mínimo, se o item não tiver
 * ponto definido), o sistema:
 *   • publica um aviso no sino de notificações de todos os operadores;
 *   • envia um e-mail para a lista configurada;
 *   • repete o aviso e o e-mail no intervalo escolhido (padrão 24 h) ENQUANTO o item continuar baixo;
 *   • avisa "reposto" quando o saldo volta acima do ponto, e para de alertar.
 *
 * Disparos: logo depois de cada movimentação de estoque, pelo cron (alertas_worker.php)
 * e, se não houver cron, a cada 15 min pelo próprio uso do sistema (batimento das telas abertas).
 * Só leitura em data/estoque.json — os alertas nunca alteram saldo.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const ALT_CONFIG = DATA_DIR . '/alertas_config.json';
const ALT_ESTADO = DATA_DIR . '/alertas_estado.json';
const ALT_LOCK   = DATA_DIR . '/.alertas.lock';
const ALT_PADRAO = [
    'ativo' => false, 'emails' => [], 'repetir_horas' => 24, 'escopo' => 'com_parametro', 'categorias' => [],
    'avisar_reposto' => true, 'metodo' => 'mail', 'remetente' => '', 'remetente_nome' => 'Estoque Drilling',
    'smtp_host' => '', 'smtp_porta' => 465, 'smtp_seguranca' => 'ssl', 'smtp_usuario' => '', 'smtp_senha' => '',
];

function alt_json_read(string $f, array $padrao): array {
    $raw = @file_get_contents($f);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j + $padrao : $padrao;
}
function alt_json_write(string $f, array $d): void {
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, $f))
        throw new RuntimeException('Não foi possível gravar ' . basename($f));
    @chmod($f, 0640);
    if (function_exists('rd_db_espelhar')) { try { rd_db_espelhar($f); } catch (Throwable $e) {} }
}
function alt_config(): array { return alt_json_read(ALT_CONFIG, ALT_PADRAO); }
/** Configuração para a tela (sem a senha). */
function alt_config_publica(): array {
    $c = alt_config(); $c['smtp_senha_definida'] = $c['smtp_senha'] !== ''; unset($c['smtp_senha']); return $c;
}
function alt_config_salvar(array $in): array {
    $c = alt_config(); $antes = $c;
    $c['ativo'] = !empty($in['ativo']);
    $emails = preg_split('/[\s,;]+/', is_array($in['emails'] ?? null) ? implode(',', $in['emails']) : (string)($in['emails'] ?? ''));
    $c['emails'] = array_values(array_unique(array_filter(array_map('trim', $emails), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if (count($c['emails']) > 20) throw new InvalidArgumentException('No máximo 20 e-mails.');
    $c['repetir_horas'] = in_array((int)($in['repetir_horas'] ?? 24), [6, 12, 24, 48, 72], true) ? (int)$in['repetir_horas'] : 24;
    $c['escopo'] = in_array($in['escopo'] ?? '', ['com_parametro', 'importados', 'categorias'], true) ? $in['escopo'] : 'com_parametro';
    $c['categorias'] = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), (array)($in['categorias'] ?? []))));
    $c['avisar_reposto'] = !empty($in['avisar_reposto']);
    $c['metodo'] = ($in['metodo'] ?? '') === 'smtp' ? 'smtp' : 'mail';
    $c['remetente'] = filter_var(trim((string)($in['remetente'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
    $c['remetente_nome'] = mb_substr(trim((string)($in['remetente_nome'] ?? 'Estoque Drilling')), 0, 60) ?: 'Estoque Drilling';
    $c['smtp_host'] = preg_replace('/[^a-z0-9.\-]/i', '', (string)($in['smtp_host'] ?? ''));
    $c['smtp_porta'] = max(1, min(65535, (int)($in['smtp_porta'] ?? 465)));
    $c['smtp_seguranca'] = in_array($in['smtp_seguranca'] ?? '', ['ssl', 'tls', 'nenhuma'], true) ? $in['smtp_seguranca'] : 'ssl';
    $c['smtp_usuario'] = mb_substr(trim((string)($in['smtp_usuario'] ?? '')), 0, 120);
    if (isset($in['smtp_senha']) && $in['smtp_senha'] !== '') $c['smtp_senha'] = (string)$in['smtp_senha'];
    if ($c['ativo'] && !$c['emails']) throw new InvalidArgumentException('Informe ao menos um e-mail para receber os alertas.');
    if ($c['metodo'] === 'smtp' && $c['ativo'] && ($c['smtp_host'] === '' || $c['smtp_usuario'] === '' || $c['smtp_senha'] === ''))
        throw new InvalidArgumentException('Para SMTP preencha servidor, usuário e senha.');
    alt_json_write(ALT_CONFIG, $c);
    // Ligou o e-mail agora (ou mudou a lista): manda já a lista do que está baixo, sem esperar a próxima repetição.
    if ($c['ativo'] && (!$antes['ativo'] || $antes['emails'] != $c['emails'])) { try { alt_verificar('config', true); } catch (Throwable $e) {} }
    return alt_config_publica();
}

function alt_lock(callable $fn, bool $esperar = true) {
    $fp = @fopen(ALT_LOCK, 'c'); if (!$fp) return null;
    if (!flock($fp, $esperar ? LOCK_EX : (LOCK_EX | LOCK_NB))) { fclose($fp); return null; }
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/** Itens monitorados e se estão abaixo do ponto. */
function alt_itens_baixos(array $cfg): array {
    $raw = @file_get_contents(DATA_DIR . '/estoque.json');
    $db = $raw ? json_decode($raw, true) : null;
    $out = [];
    foreach ((array)($db['itens'] ?? []) as $cod => $it) {
        if (!is_array($it) || !empty($it['inativo'])) continue;
        if ($cfg['escopo'] === 'importados' && empty($it['importado'])) continue;
        if ($cfg['escopo'] === 'categorias' && !in_array((string)($it['categoria'] ?? ''), $cfg['categorias'], true)) continue;
        $saldo = (float)($it['saldo'] ?? 0); $min = (float)($it['minimo'] ?? 0); $ponto = (float)($it['ponto_pedido'] ?? 0);
        $gatilho = $ponto > 0 ? $ponto : $min;
        if ($gatilho <= 0) continue;                          // sem parâmetro definido: não monitora
        $nivel = $saldo <= 0 ? 'ZERADO' : ($min > 0 && $saldo <= $min ? 'MINIMO' : ($saldo <= $gatilho ? 'REPOSICAO' : null));
        $out[$cod] = ['codigo' => (string)$cod, 'descricao' => (string)($it['nome_planilha'] ?? $it['descricao'] ?? $cod), 'unidade' => (string)($it['unidade'] ?? ''),
            'categoria' => (string)($it['categoria'] ?? ''), 'saldo' => $saldo, 'minimo' => $min, 'ponto' => $gatilho, 'maximo' => (float)($it['maximo'] ?? 0),
            'nivel' => $nivel, 'comprar' => max(0, ((float)($it['maximo'] ?? 0) ?: $gatilho * 2) - $saldo), 'importado' => !empty($it['importado'])];
    }
    return $out;
}
const ALT_PESO = ['REPOSICAO' => 1, 'MINIMO' => 2, 'ZERADO' => 3];
const ALT_ROTULO = ['REPOSICAO' => 'No ponto de reposição', 'MINIMO' => 'Abaixo do mínimo', 'ZERADO' => 'Sem estoque'];

/**
 * Verifica e dispara. $motivo: movimento | cron | batimento | manual.
 * Retorna o resumo do que foi enviado.
 */
function alt_verificar(string $motivo = 'manual', bool $forcarEmail = false): array {
    $r = alt_lock(function () use ($motivo, $forcarEmail) {
        $cfg = alt_config();
        $est = alt_json_read(ALT_ESTADO, ['abertos' => [], 'historico' => [], 'ultima_verificacao' => null, 'ultimo_email' => null]);
        $agora = time(); $iso = date('c');
        $itens = alt_itens_baixos($cfg);
        $novos = []; $piorou = []; $repetir = []; $repostos = [];
        foreach ($itens as $cod => $i) {
            $ab = $est['abertos'][$cod] ?? null;
            if ($i['nivel'] === null) {
                if ($ab) { $repostos[] = $i + ['desde' => $ab['desde']]; $est['historico'][] = ['codigo' => $cod, 'descricao' => $i['descricao'], 'desde' => $ab['desde'], 'reposto_em' => $iso, 'avisos' => $ab['avisos'] ?? 0]; unset($est['abertos'][$cod]); }
                continue;
            }
            if (!$ab) { $novos[] = $i; $est['abertos'][$cod] = ['desde' => $iso, 'nivel' => $i['nivel'], 'ultimo_aviso' => 0, 'avisos' => 0]; continue; }
            if ((ALT_PESO[$i['nivel']] ?? 0) > (ALT_PESO[$ab['nivel']] ?? 0)) $piorou[] = $i;
            elseif ($agora - (int)$ab['ultimo_aviso'] >= $cfg['repetir_horas'] * 3600 - 300) $repetir[] = $i;
            $est['abertos'][$cod]['nivel'] = $i['nivel'];
        }
        foreach (array_keys($est['abertos']) as $cod) if (!isset($itens[$cod])) unset($est['abertos'][$cod]);   // item excluído / fora do escopo
        $est['historico'] = array_slice($est['historico'], -300);

        $avisar = array_merge($novos, $piorou, $repetir);
        if ($forcarEmail) $avisar = array_values(array_filter($itens, fn($i) => $i['nivel'] !== null));
        $pendentes = array_values(array_filter($itens, fn($i) => $i['nivel'] !== null));
        usort($pendentes, fn($a, $b) => (ALT_PESO[$b['nivel']] <=> ALT_PESO[$a['nivel']]) ?: strcmp($a['descricao'], $b['descricao']));

        $res = ['novos' => count($novos), 'piorou' => count($piorou), 'repeticao' => count($repetir), 'repostos' => count($repostos), 'pendentes' => count($pendentes), 'email' => null, 'motivo' => $motivo];
        if ($avisar || $repostos) {
            // 1) Sino de notificações (um aviso por item novo/agravado, um resumo para as repetições)
            if (is_file(__DIR__ . '/notify_lib.php')) {
                require_once __DIR__ . '/notify_lib.php';
                foreach (array_merge($novos, $piorou) as $i) @notif_publish(['tipo' => 'estoque_baixo', 'autor' => 'alertas',
                    'titulo' => '🔻 ' . ALT_ROTULO[$i['nivel']] . ' • ' . $i['descricao'],
                    'texto' => 'Saldo ' . alt_n($i['saldo']) . ' ' . $i['unidade'] . ' · ponto de reposição ' . alt_n($i['ponto']) . ($i['minimo'] ? ' · mínimo ' . alt_n($i['minimo']) : '') . '. Repor ' . alt_n($i['comprar']) . ' ' . $i['unidade'] . '.',
                    'url' => '/?pagina=' . ($i['importado'] ? 'importados' : 'estoque'), 'dados' => ['codigo' => $i['codigo']]]);
                if ($repetir && !$forcarEmail) @notif_publish(['tipo' => 'estoque_baixo', 'autor' => 'alertas',
                    'titulo' => '🔻 Ainda sem reposição: ' . count($repetir) . ' item(ns)',
                    'texto' => implode(' · ', array_map(fn($i) => $i['descricao'] . ' (' . alt_n($i['saldo']) . ')', array_slice($repetir, 0, 8))) . (count($repetir) > 8 ? ' …' : ''), 'url' => '/?pagina=estoque']);
                if ($repostos && $cfg['avisar_reposto']) foreach ($repostos as $i) @notif_publish(['tipo' => 'estoque', 'autor' => 'alertas',
                    'titulo' => '✓ Reposto • ' . $i['descricao'], 'texto' => 'Saldo voltou para ' . alt_n($i['saldo']) . ' ' . $i['unidade'] . ' (ponto ' . alt_n($i['ponto']) . '). Alerta encerrado.']);
            }
            // 2) E-mail (um resumo por verificação)
            if ($cfg['ativo'] && $cfg['emails'] && ($avisar || ($repostos && $cfg['avisar_reposto']))) {
                try {
                    [$assunto, $htmlMail, $texto] = alt_email_montar($novos, $piorou, $forcarEmail ? $avisar : $repetir, $cfg['avisar_reposto'] ? $repostos : [], $pendentes, $cfg);
                    alt_email_enviar($cfg, $assunto, $htmlMail, $texto);
                    $res['email'] = 'enviado para ' . count($cfg['emails']) . ' destinatário(s)';
                    $est['ultimo_email'] = ['em' => $iso, 'assunto' => $assunto, 'ok' => true];
                } catch (Throwable $e) {
                    $res['email'] = 'FALHOU: ' . $e->getMessage();
                    $est['ultimo_email'] = ['em' => $iso, 'erro' => $e->getMessage(), 'ok' => false];
                }
            }
            // O e-mail sempre lista todos os pendentes: zera o relógio de repetição de todos, para não chegar outro lembrete logo depois.
            if (str_starts_with((string)$res['email'], 'enviado') || $repetir) foreach ($est['abertos'] as &$abx) $abx['ultimo_aviso'] = $agora;
            unset($abx);
            foreach ($avisar as $i) if (isset($est['abertos'][$i['codigo']])) { $est['abertos'][$i['codigo']]['ultimo_aviso'] = $agora; $est['abertos'][$i['codigo']]['avisos'] = (int)($est['abertos'][$i['codigo']]['avisos'] ?? 0) + 1; }
        }
        $est['ultima_verificacao'] = $iso;
        alt_json_write(ALT_ESTADO, $est);
        return $res;
    }, $motivo !== 'batimento');
    return $r ?? ['ignorado' => 'verificação em andamento'];
}

/** Chamado pelo batimento das telas abertas: no máximo a cada 15 min (substitui o cron). */
function alt_tick(): void {
    $est = alt_json_read(ALT_ESTADO, []);
    if (!empty($est['ultima_verificacao']) && time() - strtotime($est['ultima_verificacao']) < 900) return;
    try { alt_verificar('batimento'); } catch (Throwable $e) { error_log('Alertas: ' . $e->getMessage()); }
}

/** Status para a faixa de alerta e a tela de configuração. */
function alt_status(): array {
    $cfg = alt_config();
    $est = alt_json_read(ALT_ESTADO, ['abertos' => [], 'historico' => []]);
    $itens = alt_itens_baixos($cfg);
    $lista = [];
    foreach ($itens as $cod => $i) if ($i['nivel'] !== null) $lista[] = $i + ['desde' => $est['abertos'][$cod]['desde'] ?? null, 'avisos' => (int)($est['abertos'][$cod]['avisos'] ?? 0)];
    usort($lista, fn($a, $b) => (ALT_PESO[$b['nivel']] <=> ALT_PESO[$a['nivel']]) ?: strcmp($a['descricao'], $b['descricao']));
    return ['itens' => $lista, 'monitorados' => count($itens), 'zerados' => count(array_filter($lista, fn($i) => $i['nivel'] === 'ZERADO')),
        'abaixo_minimo' => count(array_filter($lista, fn($i) => $i['nivel'] === 'MINIMO')), 'ultima_verificacao' => $est['ultima_verificacao'] ?? null,
        'ultimo_email' => $est['ultimo_email'] ?? null, 'repostos' => array_slice(array_reverse($est['historico'] ?? []), 0, 15), 'email_ativo' => $cfg['ativo'] && $cfg['emails']];
}

function alt_n($v): string { $v = (float)$v; return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ','); }
function alt_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function alt_email_montar(array $novos, array $piorou, array $repetir, array $repostos, array $pendentes, array $cfg): array {
    $nNovos = count($novos) + count($piorou);
    $assunto = $nNovos ? '⚠ Estoque baixo: ' . $nNovos . ' novo(s) alerta(s)' . (count($pendentes) > $nNovos ? ' · ' . count($pendentes) . ' itens sem reposição' : '')
        : ($repetir ? '⚠ Lembrete: ' . count($pendentes) . ' item(ns) ainda abaixo do ponto de reposição' : '✓ Estoque reposto: ' . count($repostos) . ' item(ns)');
    $cor = ['ZERADO' => '#b42318', 'MINIMO' => '#c4320a', 'REPOSICAO' => '#b54708'];
    $linhas = fn(array $l) => implode('', array_map(fn($i) => '<tr><td style="padding:8px;border-bottom:1px solid #eee"><b>' . alt_h($i['descricao']) . '</b><br><span style="color:#667085;font-size:12px">' . alt_h($i['codigo']) . ($i['categoria'] ? ' · ' . alt_h($i['categoria']) : '') . '</span></td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right"><b style="color:' . ($cor[$i['nivel']] ?? '#111') . '">' . alt_n($i['saldo']) . '</b> ' . alt_h($i['unidade']) . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right">' . alt_n($i['ponto']) . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right">' . ($i['minimo'] ? alt_n($i['minimo']) : '—') . '</td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right"><b>' . alt_n($i['comprar']) . '</b></td>'
        . '<td style="padding:8px;border-bottom:1px solid #eee"><span style="background:' . ($cor[$i['nivel']] ?? '#555') . ';color:#fff;border-radius:10px;padding:2px 8px;font-size:11px">' . (ALT_ROTULO[$i['nivel']] ?? '') . '</span></td></tr>', $l));
    $tabela = fn(string $titulo, array $l) => $l ? '<h3 style="margin:22px 0 6px;font-size:15px">' . $titulo . '</h3><table style="width:100%;border-collapse:collapse;font-size:13px"><tr style="background:#f2f4f7;text-align:left;font-size:11px;color:#475467"><th style="padding:8px">Item</th><th style="padding:8px;text-align:right">Saldo</th><th style="padding:8px;text-align:right">Ponto de reposição</th><th style="padding:8px;text-align:right">Mínimo</th><th style="padding:8px;text-align:right">Repor (até o máximo)</th><th style="padding:8px">Situação</th></tr>' . $linhas($l) . '</table>' : '';
    $base = (isset($_SERVER['HTTP_HOST']) ? 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST']) : '');
    $novosCods = array_column(array_merge($novos, $piorou), 'codigo');
    $html = '<div style="font-family:Arial,sans-serif;color:#101828;max-width:760px">'
        . '<div style="background:#0b1f3a;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0"><b style="font-size:17px">Alerta de estoque · Drilling</b><br><span style="font-size:12px;color:#b9c8de">' . date('d/m/Y H:i') . '</span></div>'
        . '<div style="border:1px solid #e4e7ec;border-top:0;padding:4px 20px 20px;border-radius:0 0 10px 10px">'
        . $tabela('Novos alertas', array_merge($novos, $piorou))
        . $tabela($novosCods ? 'Continuam sem reposição' : 'Ainda abaixo do ponto de reposição', array_values(array_filter($pendentes, fn($i) => !in_array($i['codigo'], $novosCods, true))))
        . ($repostos ? '<h3 style="margin:22px 0 6px;font-size:15px;color:#067647">✓ Repostos</h3><ul style="font-size:13px">' . implode('', array_map(fn($i) => '<li>' . alt_h($i['descricao']) . ': saldo ' . alt_n($i['saldo']) . ' ' . alt_h($i['unidade']) . '</li>', $repostos)) . '</ul>' : '')
        . '<p style="font-size:12px;color:#667085;margin-top:22px">Este alerta se repete a cada ' . (int)$cfg['repetir_horas'] . ' h enquanto o item estiver abaixo do ponto de reposição e para sozinho quando o saldo for reposto.'
        . ($base ? ' <a href="' . $base . '/?pagina=estoque">Abrir o estoque</a>.' : '') . '</p></div></div>';
    $texto = $assunto . "\n\n" . implode("\n", array_map(fn($i) => '- ' . $i['descricao'] . ': saldo ' . alt_n($i['saldo']) . ' ' . $i['unidade'] . ' (ponto ' . alt_n($i['ponto']) . ', repor ' . alt_n($i['comprar']) . ')', $pendentes ?: $repostos));
    return [$assunto, $html, $texto];
}

/** $imagens (v3.31.0): [['cid'=>'foto1','mime'=>'image/jpeg','dados'=>binário], …] — vão embutidas no corpo (<img src="cid:foto1">). */
function alt_email_enviar(array $cfg, string $assunto, string $html, string $texto, ?array $para = null, array $imagens = []): void {
    $para = $para ?? $cfg['emails'];
    if (!$para) throw new RuntimeException('Nenhum destinatário configurado.');
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $de = $cfg['remetente'] ?: ($cfg['metodo'] === 'smtp' && filter_var($cfg['smtp_usuario'], FILTER_VALIDATE_EMAIL) ? $cfg['smtp_usuario'] : 'estoque@' . $host);
    $limite = '=_rd' . bin2hex(random_bytes(8));
    $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $parteHtml = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html));
    if ($imagens) {   // v3.31.0: html + fotos embutidas (multipart/related) dentro do multipart/alternative
        $rel = '=_rr' . bin2hex(random_bytes(8));
        $parteHtml = "Content-Type: multipart/related; boundary=\"$rel\"\r\n\r\n--$rel\r\n" . $parteHtml;
        foreach ($imagens as $im) {
            $cid = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$im['cid']);
            $parteHtml .= "--$rel\r\nContent-Type: {$im['mime']}\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$cid.jpg\"\r\n\r\n" . chunk_split(base64_encode($im['dados']));
        }
        $parteHtml .= "--$rel--\r\n";
    }
    $corpo = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
        . "--$limite\r\n" . $parteHtml . "--$limite--\r\n";
    $cab = ['From: ' . $enc($cfg['remetente_nome']) . " <$de>", 'Reply-To: ' . $de, 'MIME-Version: 1.0', "Content-Type: multipart/alternative; boundary=\"$limite\"", 'X-Mailer: RecebimentoDrilling'];
    if ($cfg['metodo'] !== 'smtp') {
        if (!function_exists('mail')) throw new RuntimeException('A função mail() está desativada neste servidor. Use SMTP.');
        if (!@mail(implode(', ', $para), $enc($assunto), $corpo, implode("\r\n", $cab), '-f' . $de)) throw new RuntimeException('O servidor recusou o envio pelo mail(). Configure SMTP.');
        return;
    }
    alt_smtp($cfg, $de, $para, "To: " . implode(', ', $para) . "\r\nSubject: " . $enc($assunto) . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . '@' . $host . ">\r\n" . implode("\r\n", $cab) . "\r\n\r\n" . $corpo);
}

/** Cliente SMTP mínimo: SSL (465) ou STARTTLS (587), AUTH LOGIN. */
function alt_smtp(array $c, string $de, array $para, string $msg): void {
    $alvo = ($c['smtp_seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . (int)$c['smtp_porta'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("Não conectou ao SMTP {$c['smtp_host']}:{$c['smtp_porta']} ($errstr)");
    stream_set_timeout($s, 20);
    $ler = function () use ($s) { $r = ''; while (($l = fgets($s, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, array $ok) use ($s, $ler) { if ($c !== '') fwrite($s, $c . "\r\n"); $r = $ler(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim(preg_replace('/\s+/', ' ', $r ?: 'sem resposta'))); return $r; };
    try {
        $cmd('', [220]);
        $eu = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $cmd("EHLO $eu", [250]);
        if ($c['smtp_seguranca'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('Falha no STARTTLS');
            $cmd("EHLO $eu", [250]);
        }
        $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['smtp_usuario']), [334]); $cmd(base64_encode($c['smtp_senha']), [235]);
        $cmd("MAIL FROM:<$de>", [250]);
        foreach ($para as $p) $cmd("RCPT TO:<$p>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd(preg_replace('/^\./m', '..', $msg) . "\r\n.", [250]);
        $cmd('QUIT', [221]);
    } finally { fclose($s); }
}

/** E-mail de teste com a lista atual. */
function alt_email_teste(): array {
    $cfg = alt_config();
    if (!$cfg['emails']) throw new InvalidArgumentException('Salve ao menos um e-mail antes do teste.');
    $pend = array_values(array_filter(alt_itens_baixos($cfg), fn($i) => $i['nivel'] !== null));
    [$a, $h, $t] = alt_email_montar([], [], $pend, [], $pend, $cfg);
    alt_email_enviar($cfg, '[TESTE] ' . $a, $h, $t);
    return ['enviado_para' => $cfg['emails'], 'itens' => count($pend)];
}


/* ------------------------------------------------------------------ */
/* v3.26.0 — E-mail ao solicitante quando o pedido chega no almoxarifado */
/* ------------------------------------------------------------------ */
function rcb_norm(string $s): array {
    $s = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return array_values(array_filter(preg_split('/[^A-Z]+/', $s), fn($w) => strlen($w) >= 2 && !in_array($w, ['DA', 'DE', 'DO', 'DAS', 'DOS', 'E', 'SR', 'SRA', 'ENG'], true)));
}
/** Pessoas que podem receber o aviso: lista própria (Administração) + funcionários ativos com e-mail, sem repetir e-mail. Lida uma vez por requisição. */
function rcb_base(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(DATA_DIR . '/estoque.json'); $db = $raw ? json_decode($raw, true) : [];
    $cand = [];
    // v3.27.3: lista própria "Nome + e-mail" (Administração → Avisos por e-mail) vem primeiro.
    foreach (rcb_contatos() as $i => $c) $cand[] = ['id' => 'CT-' . $i, 'nome' => $c['nome'], 'email' => $c['email'], 'apelidos' => $c['apelidos'] ?? ''];
    $emailsLista = array_map('strtolower', array_column($cand, 'email'));
    foreach ((array)($db['funcionarios'] ?? []) as $f) {
        if (empty($f['email']) || isset($f['ativo']) && !$f['ativo']) continue;
        if (in_array(strtolower($f['email']), $emailsLista, true)) continue;   // mesma pessoa nas duas listas
        $cand[] = $f;
    }
    return $cache = $cand;
}
function rcb_id(array $f): string { return (string)($f['id'] ?? strtolower((string)($f['email'] ?? ''))); }
function rcb_fmt(array $lista, string $origem = ''): array {
    return array_values(array_map(fn($f) => ['nome' => (string)$f['nome'], 'email' => strtolower((string)$f['email'])] + ($origem !== '' ? ['origem' => $origem] : []), $lista));
}
/** Casa o texto do campo Solicitante com as pessoas. Cada parte ("MARCOS / JOÃO") é tratada separado. */
function rcb_casar(string $sol, array $cand): array {
    $achados = []; $ambiguos = []; $sem = [];
    foreach (preg_split('/\s*(?:\/|,|;|\+|\be\b)\s*/i', $sol) as $parte) {
        $p = rcb_norm($parte); if (!$p) continue;
        $exatos = []; $porPrimeiro = [];
        foreach ($cand as $f) {
            $nomes = array_merge([$f['nome']], array_filter(array_map('trim', explode(',', (string)($f['apelidos'] ?? '')))));
            foreach ($nomes as $n) {
                $t = rcb_norm($n);
                if ($t && !array_diff($p, $t)) { $exatos[rcb_id($f)] = $f; break; }          // todas as palavras do pedido estão no nome
                if ($t && count($p) === 1 && $t[0] === $p[0]) $porPrimeiro[rcb_id($f)] = $f;   // só o primeiro nome
            }
        }
        $ok = $exatos ?: $porPrimeiro;
        if (count($ok) === 1) $achados += $ok;
        elseif (count($ok) > 1) $ambiguos += $ok;          // ambíguo (dois MARCOS): não envia sozinho
        else $sem[] = trim($parte);
    }
    return ['achados' => $achados, 'ambiguos' => $ambiguos, 'sem' => $sem];
}
/** Acha o(s) funcionário(s) com e-mail cujo nome (ou "como aparece nos pedidos") bate com o solicitante. */
function rcb_destinatarios(string $solicitante): array {
    $sol = trim($solicitante); if ($sol === '') return [];
    return array_values(rcb_casar($sol, rcb_base())['achados']);
}
/**
 * v3.29.0 — procura, num texto livre (observações do pedido), pessoas da lista. Não "adivinha" nomes novos:
 * só reconhece quem já está cadastrado. fortes = apelido ou nome completo; fracos = só o primeiro nome (único na lista);
 * duvidosos = primeiro nome que existe em mais de uma pessoa.
 */
function rcb_achar_no_texto(string $texto, array $cand): array {
    $out = ['fortes' => [], 'fracos' => [], 'duvidosos' => []];
    $palavras = array_flip(rcb_norm($texto));
    if (!$palavras) return $out;
    // v3.34.0: aceita abreviação de 5+ letras ("MANUT." = MANUTENÇÃO), só para palavras longas do apelido
    $tem = function (array $tokens) use ($palavras): bool {
        if (!$tokens) return false;
        foreach ($tokens as $w) {
            if (isset($palavras[$w])) continue;
            $ok = false;
            if (strlen($w) >= 8) foreach ($palavras as $p => $_) if (strlen($p) >= 5 && strlen($p) < strlen($w) && str_starts_with($w, (string)$p)) { $ok = true; break; }
            if (!$ok) return false;
        }
        return true;
    };
    $porPrimeiro = [];
    foreach ($cand as $f) { $t = rcb_norm((string)$f['nome']); if ($t) $porPrimeiro[$t[0]][] = rcb_id($f); }
    foreach ($cand as $f) {
        $id = rcb_id($f);
        foreach (array_filter(array_map('trim', explode(',', (string)($f['apelidos'] ?? '')))) as $a) {
            $t = rcb_norm($a);
            if ($t && $tem($t)) { $out['fortes'][$id] = $f; continue 2; }
        }
        $tn = rcb_norm((string)$f['nome']);
        if (count($tn) >= 2 && $tem($tn)) { $out['fortes'][$id] = $f; continue; }
        if ($tn && strlen($tn[0]) >= 3 && isset($palavras[$tn[0]])) {
            if (count($porPrimeiro[$tn[0]] ?? []) === 1) $out['fracos'][$id] = $f; else $out['duvidosos'][$id] = $f;
        }
    }
    return $out;
}
/**
 * v3.29.0 — quem deve receber o aviso. status:
 *   ok         solicitante identifica uma pessoa só (pode enviar)
 *   sugerido   achado nas observações; precisa de confirmação (NÃO envia sozinho)
 *   ambiguo    mais de uma pessoa possível (NÃO envia sozinho)
 *   nenhum     nada encontrado
 */
function rcb_resolver(string $solicitante, string $textoExtra = ''): array {
    $cand = rcb_base();
    $res = fn($st, $orig, $dest, $cands, $motivo) => ['status' => $st, 'origem' => $orig, 'destinatarios' => rcb_fmt($dest, $orig), 'candidatos' => rcb_fmt($cands), 'motivo' => $motivo];
    $sol = trim($solicitante); $motivoSol = 'Pedido sem solicitante.';
    if ($sol !== '') {
        $r = rcb_casar($sol, $cand);
        if ($r['achados']) return $res('ok', 'solicitante', $r['achados'], $r['ambiguos'], '');
        if ($r['ambiguos']) return $res('ambiguo', 'solicitante', [], $r['ambiguos'], 'O solicitante "' . mb_substr($sol, 0, 60) . '" bate com mais de uma pessoa.');
        $motivoSol = 'O solicitante "' . mb_substr($sol, 0, 60) . '" não está na lista de e-mails.';
    }
    $t = rcb_achar_no_texto($textoExtra, $cand);
    if (count($t['fortes']) === 1) return $res('sugerido', 'observacao', $t['fortes'], [], $motivoSol . ' Nome encontrado nas observações do pedido.');
    if (count($t['fortes']) > 1) return $res('ambiguo', 'observacao', [], array_slice($t['fortes'], 0, 8), $motivoSol . ' Mais de um nome nas observações.');
    if (count($t['fracos']) === 1 && !$t['duvidosos']) return $res('sugerido', 'observacao', $t['fracos'], [], $motivoSol . ' Primeiro nome encontrado nas observações do pedido.');
    $todos = $t['fracos'] + $t['duvidosos'];
    if ($todos) return $res('ambiguo', 'observacao', [], array_slice($todos, 0, 8), $motivoSol . ' Há nomes parecidos nas observações.');
    $repos = preg_match('/\b(PARA\s+ESTOQUE|REPOSI[CÇ][AÃ]O|ESTOQUE\s*[-–])/iu', $textoExtra) ? ' Parece reposição de estoque (ninguém aguardando).' : '';
    return $res('nenhum', '', [], [], $motivoSol . ' Nada nas observações.' . $repos);
}
/** v3.34.0: último registro do log de e-mails de recebimento deste PC (ou null). */
function rcb_ultimo_envio(int $pc): ?array {
    $l = json_decode((string)@file_get_contents(DATA_DIR . '/emails_recebimento.json'), true);
    if (!is_array($l)) return null;
    for ($i = count($l) - 1; $i >= 0; $i--) if ((int)($l[$i]['pc'] ?? 0) === $pc) return $l[$i];
    return null;
}
/** Situação do aviso de um PC: o que o conferente/compras definiram vale mais que a descoberta automática. */
function rcb_situacao(array $item): array {
    $modo = (string)($item['avisar_modo'] ?? '');
    $lista = array_values(array_filter((array)($item['avisar_para'] ?? []), fn($d) => is_array($d) && !empty($d['email'])));
    if ($modo === 'nenhum') return ['status' => 'sem_aviso', 'origem' => 'manual', 'destinatarios' => [], 'candidatos' => [], 'motivo' => 'Definido: não avisar ninguém.'];
    if ($modo === 'definido' && $lista) return ['status' => 'definido', 'origem' => 'manual', 'destinatarios' => $lista, 'candidatos' => [], 'motivo' => ''];
    $texto = trim(($item['observacoes_pc'] ?? '') . ' ' . ($item['finalidade_pc'] ?? '') . ' ' . ($item['obs'] ?? ''));
    return rcb_resolver((string)($item['solicitante'] ?? ''), $texto);
}
/** Lista para os seletores da tela (nome + e-mail). */
function rcb_lista_selecao(): array {
    $l = rcb_fmt(rcb_base());
    usort($l, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    return $l;
}
/** Só aceita e-mails que estão na lista de contatos/funcionários (ninguém digita um endereço qualquer). */
function rcb_validar_emails(array $emails, string $origem = 'manual'): array {
    $mapa = []; foreach (rcb_base() as $f) $mapa[strtolower((string)$f['email'])] = $f;
    $out = []; $vistos = [];
    foreach (array_slice($emails, 0, 10) as $e) {
        $e = strtolower(trim((string)$e)); if ($e === '' || isset($vistos[$e])) continue; $vistos[$e] = 1;
        if (!isset($mapa[$e])) throw new InvalidArgumentException('E-mail fora da lista de contatos: ' . $e);
        $out[] = ['nome' => (string)$mapa[$e]['nome'], 'email' => $e, 'origem' => $origem];
    }
    return $out;
}
/**
 * v3.31.0: fotos do recebimento para ir EMBUTIDAS no e-mail. Até 3 fotos do produto (nunca a da NF: tem valores),
 * reduzidas a 800 px / JPEG 74, no máximo ~700 KB no total. Qualquer falha de uma foto só a omite — o aviso sai igual.
 */
function rcb_fotos_email(array $item): array {
    $out = []; $total = 0;
    if (!function_exists('imagecreatefromstring')) return $out;
    foreach (array_slice(array_values(array_filter((array)($item['fotosProdutos'] ?? []))), 0, 3) as $rel) {
        try {
            $rel = (string)$rel;
            if (!preg_match('~^data/photos/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$~i', $rel)) continue;
            $arq = dirname(DATA_DIR) . '/' . $rel;
            if (!is_file($arq) || filesize($arq) > 12 * 1024 * 1024) continue;
            $im = @imagecreatefromstring((string)file_get_contents($arq)); if (!$im) continue;
            $w = imagesx($im); $h = imagesy($im); $k = min(1, 800 / max($w, $h));
            if ($k < 1) { $n = imagescale($im, max(1, (int)round($w * $k)), max(1, (int)round($h * $k))); if ($n) { imagedestroy($im); $im = $n; } }
            ob_start(); imagejpeg($im, null, 74); $bin = (string)ob_get_clean(); imagedestroy($im);
            if ($bin === '' || $total + strlen($bin) > 700 * 1024) continue;
            $total += strlen($bin); $out[] = ['cid' => 'foto' . (count($out) + 1), 'mime' => 'image/jpeg', 'dados' => $bin];
        } catch (Throwable $e) { /* foto ruim não impede o aviso */ }
    }
    return $out;
}
function rcb_email_recebimento(array $item, string $status, string $conferente = ''): array {
    // v3.29.0: só envia para quem está DEFINIDO no pedido ou identificado com certeza pelo solicitante.
    $sit = rcb_situacao($item);
    if ($sit['status'] === 'sem_aviso') return ['enviado' => false, 'motivo' => 'definido: não avisar ninguém'];
    $dest = in_array($sit['status'], ['definido', 'ok'], true) ? $sit['destinatarios'] : [];
    if (!$dest) return ['enviado' => false, 'motivo' => 'sem destinatário definido (' . $sit['status'] . ') — ' . $sit['motivo']];
    $cfg = alt_config();
    $pc = (int)$item['pc']; $parcial = $status === 'PARCIAL';
    $linhas = ''; $txt = '';
    foreach ((array)($item['insumos'] ?? []) as $l) {
        $rec = (float)($l['quantidade_recebida'] ?? 0); $ped = (float)($l['quantidade_pedida'] ?? 0); $pend = max(0, $ped - $rec);
        $linhas .= '<tr><td style="padding:7px;border-bottom:1px solid #eee">' . alt_h($l['descricao'] ?? '') . '</td><td style="padding:7px;border-bottom:1px solid #eee;text-align:right">' . alt_n($ped) . ' ' . alt_h($l['unidade'] ?? '') . '</td>'
            . '<td style="padding:7px;border-bottom:1px solid #eee;text-align:right"><b>' . alt_n($rec) . '</b></td><td style="padding:7px;border-bottom:1px solid #eee;text-align:right;color:' . ($pend > 0 ? '#b54708' : '#067647') . '">' . ($pend > 0 ? alt_n($pend) . ' pendente' : '✓') . '</td></tr>';
        $txt .= '- ' . ($l['descricao'] ?? '') . ': recebido ' . alt_n($rec) . ' de ' . alt_n($ped) . "\n";
    }
    $fin = strtotime((string)($item['recebimento_finalizado_em'] ?? '')); $quando = (new DateTime('@' . ($fin ?: time())))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y \à\s H:i');
    $fotos = rcb_fotos_email($item);
    $obs = trim((string)($item['obs'] ?? '')); if (mb_strlen($obs) > 400) $obs = mb_substr($obs, 0, 400) . '…';
    $htmlFotos = '';
    if ($fotos) {
        $htmlFotos = '<p style="margin:16px 0 6px;font-size:12px;color:#667085"><b>Fotos do recebimento</b></p><div>';
        foreach ($fotos as $f) $htmlFotos .= '<img src="cid:' . $f['cid'] . '" alt="Foto do recebimento" style="width:31%;min-width:150px;max-width:220px;height:auto;border-radius:8px;border:1px solid #e4e7ec;margin:0 6px 6px 0">';
        $htmlFotos .= '</div>';
    }
    $primeiro = fn($n) => ucfirst(mb_strtolower(explode(' ', trim($n))[0] ?? ''));
    $assunto = ($parcial ? '📦 Chegou parte do seu pedido' : '📦 Seu pedido chegou') . ' — PC ' . $pc . ' · ' . mb_substr((string)($item['fornecedor'] ?? ''), 0, 50);
    $base = isset($_SERVER['HTTP_HOST']) ? 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST']) : '';
    $html = '<div style="font-family:Arial,sans-serif;color:#101828;max-width:700px"><div style="background:#0b1f3a;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0"><b style="font-size:17px">' . ($parcial ? 'Parte do seu pedido chegou' : 'Seu pedido chegou') . ' no almoxarifado</b><br><span style="font-size:12px;color:#b9c8de">PC ' . $pc . ' · ' . $quando . '</span></div>'
        . '<div style="border:1px solid #e4e7ec;border-top:0;padding:16px 20px;border-radius:0 0 10px 10px">'
        . '<p>Olá, ' . alt_h(implode(' / ', array_map(fn($d) => $primeiro($d['nome']), $dest))) . '.</p><p>O pedido <b>PC ' . $pc . '</b> que você solicitou foi <b>recebido e conferido no almoxarifado</b>' . ($parcial ? ', <b>mas ainda faltam itens</b> (veja abaixo)' : '') . '.</p>'
        . '<table style="font-size:13px;margin:6px 0 14px"><tr><td style="color:#667085;padding-right:14px">Fornecedor</td><td><b>' . alt_h($item['fornecedor'] ?? '') . '</b></td></tr>'
        . (!empty($item['numeroNF']) ? '<tr><td style="color:#667085">Nota fiscal</td><td>' . alt_h($item['numeroNF']) . '</td></tr>' : '')
        . (!empty($item['obra']) ? '<tr><td style="color:#667085">Destino</td><td>' . alt_h($item['obra']) . '</td></tr>' : '')
        . '<tr><td style="color:#667085">Recebido em</td><td>' . $quando . '</td></tr>'
        . '<tr><td style="color:#667085">Situação</td><td><b style="color:' . ($parcial ? '#b54708' : '#067647') . '">' . ($parcial ? 'Recebimento parcial' : 'Recebimento completo') . '</b></td></tr>'
        . ($conferente ? '<tr><td style="color:#667085">Conferido por</td><td>' . alt_h($conferente) . '</td></tr>' : '')
        . ($obs !== '' ? '<tr><td style="color:#667085;vertical-align:top">Observação</td><td>' . nl2br(alt_h($obs)) . '</td></tr>' : '') . '</table>'
        . ($linhas ? '<table style="width:100%;border-collapse:collapse;font-size:13px"><tr style="background:#f2f4f7;font-size:11px;color:#475467;text-align:left"><th style="padding:7px">Item</th><th style="padding:7px;text-align:right">Pedido</th><th style="padding:7px;text-align:right">Recebido</th><th style="padding:7px;text-align:right">Situação</th></tr>' . $linhas . '</table>' : '')
        . $htmlFotos
        . '<p style="margin-top:16px">Procure o almoxarifado para retirar o material.</p>'
        . ($base ? '<p style="font-size:12px"><a href="' . $base . '/?pc=' . $pc . '">Ver o pedido no sistema</a></p>' : '')
        . '<p style="font-size:11px;color:#98a2b3">Aviso automático do Recebimento Drilling.</p></div></div>';
    $texto = ($parcial ? 'Parte do seu pedido chegou' : 'Seu pedido chegou') . " no almoxarifado.\nPC $pc - " . ($item['fornecedor'] ?? '') . (!empty($item['numeroNF']) ? ' - NF ' . $item['numeroNF'] : '') . "\nRecebido em $quando" . ($conferente ? " por $conferente" : '') . ($obs !== '' ? "\nObservação: $obs" : '') . "\n\n" . $txt . "\nProcure o almoxarifado para retirar o material.";
    try {
        try { alt_email_enviar($cfg, $assunto, $html, $texto, array_column($dest, 'email'), $fotos); }
        catch (Throwable $e1) {   // se o servidor recusou com fotos (tamanho etc.), tenta de novo sem elas — o aviso é o que importa
            if (!$fotos) throw $e1;
            $html = preg_replace('~<p style="margin:16px 0 6px[^>]*><b>Fotos do recebimento</b></p><div>.*?</div>~s', '', $html);
            $fotos = []; alt_email_enviar($cfg, $assunto, $html, $texto, array_column($dest, 'email'));
        }
        return ['enviado' => true, 'fotos' => count($fotos), 'para' => array_map(fn($d) => $d['nome'] . ' <' . $d['email'] . '>', $dest)];
    } catch (Throwable $e) { return ['enviado' => false, 'motivo' => $e->getMessage()]; }
}


/* v3.27.3 — Lista simples de contatos para o aviso "seu pedido chegou" */
const RCB_CONTATOS = DATA_DIR . '/contatos_aviso.json';
/** v3.29.0: contatos principais da Drilling, carregados UMA vez (marcador em data/.contatos-aviso-3290). Só acrescenta e-mails que faltam. */
const RCB_SEMENTE = [
    ['MARCOS VICTOR LOPES', 'marcos.lopes@drilling.com.br', ''],
    ['FREDERICO', 'frederico@drilling.com.br', ''],
    ['WILLY VIEIRA DO ROSARIO', 'pcp@drilling.com.br', 'PCP'],
    ['PCM', 'pcm@drilling.com.br', ''],
    ['ANA CLARA NASCIMENTO', 'assistentepcm@drilling.com.br', ''],
    ['COMPRAS DRILLING DO BRASIL', 'compras@drilling.com.br', 'COMPRAS'],
    ['ALMOXARIFADO', 'almoxarifado@drilling.com.br', ''],
    ['EXPEDIÇÃO', 'expedicao@drilling.com.br', 'EXPEDICAO'],
    ['WILLIAM RAMOS', 'william.ramos@drilling.com.br', ''],
    ['KENNETON FERREIRA', 'manutencao@drilling.com.br', 'MANUTENÇÃO, MECÂNICA'],
    ['ALINE', 'seguranca.drilling@drilling.com.br', 'SEGURANÇA, SEGURANCA'],
    ['DAIANA', 'seguranca2@drilling.com.br', 'SEGURANÇA, SEGURANCA'],
];
/** v3.35.1: Aline e Daiana (Segurança). Corrige o NOME dos dois e-mails ja semeados com nome generico, UMA vez; nome editado a mao nao e tocado. */
function rcb_semear_nomes_seguranca(): void {
    $marca = DATA_DIR . '/.contatos-seguranca-3351';
    if (!is_dir(DATA_DIR) || is_file($marca) || !is_file(RCB_CONTATOS)) return;
    $mapa = [
        'seguranca.drilling@drilling.com.br' => ['SEGURANÇA DRILLING', 'ALINE'],
        'seguranca2@drilling.com.br' => ['SEGURANÇA 2', 'DAIANA'],
    ];
    $j = json_decode((string)@file_get_contents(RCB_CONTATOS), true);
    $lista = array_values(array_filter((array)($j['contatos'] ?? []), 'is_array'));
    $mudou = false;
    foreach ($lista as &$c) {
        $em = strtolower((string)($c['email'] ?? ''));
        if (!isset($mapa[$em])) continue;
        if (mb_strtoupper(trim((string)($c['nome'] ?? ''))) === $mapa[$em][0]) {
            $c['nome'] = $mapa[$em][1];
            if (trim((string)($c['apelidos'] ?? '')) === '') $c['apelidos'] = 'SEGURANÇA, SEGURANCA';
            $mudou = true;
        }
    }
    unset($c);
    try {
        if ($mudou) {
            usort($lista, fn($a, $b) => strcmp((string)$a['nome'], (string)$b['nome']));
            alt_json_write(RCB_CONTATOS, ['contatos' => $lista, 'atualizado_em' => date('c'), 'atualizado_por' => 'carga v3.35.1']);
        }
        @file_put_contents($marca, date('c'));
    } catch (Throwable $e) { error_log('Contatos Seguranca: ' . $e->getMessage()); }
}
function rcb_semear(): void {
    rcb_semear_nomes_seguranca();
    // O marcador guarda QUAIS e-mails já foram semeados: só entram os que nunca foram (um e-mail apagado depois não volta).
    // Marcador antigo (só uma data, da primeira versão) = os 10 primeiros já foram semeados.
    $marca = DATA_DIR . '/.contatos-aviso-3290';
    if (!is_dir(DATA_DIR)) return;
    $ja = [];
    if (is_file($marca)) {
        $m = json_decode((string)@file_get_contents($marca), true);
        $ja = is_array($m) ? array_map('strtolower', $m) : array_slice(array_map(fn($c) => $c[1], RCB_SEMENTE), 0, 10);
    }
    $novos = array_values(array_filter(RCB_SEMENTE, fn($c) => !in_array($c[1], $ja, true)));
    if (!$novos) return;
    $j = is_file(RCB_CONTATOS) ? json_decode((string)@file_get_contents(RCB_CONTATOS), true) : [];
    $lista = array_values(array_filter((array)($j['contatos'] ?? []), 'is_array'));
    $tem = array_map(fn($c) => strtolower((string)($c['email'] ?? '')), $lista);
    foreach ($novos as [$nome, $email, $apel]) if (!in_array($email, $tem, true)) $lista[] = ['nome' => $nome, 'email' => $email, 'apelidos' => $apel];
    usort($lista, fn($a, $b) => strcmp((string)$a['nome'], (string)$b['nome']));
    try {
        alt_json_write(RCB_CONTATOS, ['contatos' => $lista, 'atualizado_em' => date('c'), 'atualizado_por' => 'carga inicial v3.29.0']);
        @file_put_contents($marca, json_encode(array_map(fn($c) => $c[1], RCB_SEMENTE)));
    } catch (Throwable $e) { error_log('Contatos iniciais: ' . $e->getMessage()); }
}
function rcb_contatos(): array {
    rcb_semear();
    $j = json_decode((string)@file_get_contents(RCB_CONTATOS), true);
    return array_values(array_filter((array)($j['contatos'] ?? []), fn($c) => is_array($c) && !empty($c['email'])));
}
function rcb_contatos_salvar(array $lista, string $usuario): array {
    $out = []; $vistos = [];
    foreach (array_slice($lista, 0, 300) as $c) {
        $nome = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string)($c['nome'] ?? ''))));
        $email = strtolower(trim((string)($c['email'] ?? '')));
        if ($nome === '' && $email === '') continue;
        if (mb_strlen($nome) < 2) throw new InvalidArgumentException('Informe o nome de quem recebe o e-mail ' . $email . '.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido para ' . $nome . ': ' . $email);
        $k = $nome . '|' . $email; if (isset($vistos[$k])) continue; $vistos[$k] = 1;
        $out[] = ['nome' => mb_substr($nome, 0, 120), 'email' => mb_substr($email, 0, 160), 'apelidos' => mb_substr(trim((string)($c['apelidos'] ?? '')), 0, 160)];
    }
    usort($out, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    alt_json_write(RCB_CONTATOS, ['contatos' => $out, 'atualizado_em' => date('c'), 'atualizado_por' => $usuario]);
    return $out;
}
function rcb_envios(int $n = 30): array {
    $l = json_decode((string)@file_get_contents(DATA_DIR . '/emails_recebimento.json'), true) ?: [];
    return array_slice(array_reverse($l), 0, $n);
}
