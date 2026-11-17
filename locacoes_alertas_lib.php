<?php
/**
 * Recebimento Drilling — alertas de devolução de locação (v3.41.0)
 *
 * Avisa por várias vias quando a previsão de devolução de um equipamento alugado se aproxima ou vence:
 *   sino + push (notif_publish), e-mail resumido, faixa na tela, selos coloridos, link de WhatsApp,
 *   arquivo de calendário (.ics) e painel "Controle de devoluções".
 * Marcos (dias antes do vencimento) configuráveis; depois de vencida, repete a cada N horas.
 * Config: data/locacoes_alertas.json · Estado (o que já foi avisado): data/locacoes_alertas_estado.json
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const LAL_CONFIG = DATA_DIR . '/locacoes_alertas.json';
const LAL_ESTADO = DATA_DIR . '/locacoes_alertas_estado.json';
const LAL_LOCK   = DATA_DIR . '/.locacoes_alertas.lock';
const LAL_PADRAO = ['ativo' => true, 'marcos' => [7, 3, 1, 0], 'repetir_vencida_horas' => 24, 'sino' => true, 'email' => true, 'emails' => [], 'whatsapp' => ''];

function lal_json_read(string $f, array $padrao): array {
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j + $padrao : $padrao;
}
function lal_json_write(string $f, array $d): void {
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, $f))
        throw new RuntimeException('Não foi possível gravar ' . basename($f));
    @chmod($f, 0640);
}
function lal_config(): array { return lal_json_read(LAL_CONFIG, LAL_PADRAO); }

function lal_config_salvar(array $in): array {
    $marcos = [];
    foreach ((array)($in['marcos'] ?? []) as $m) { if (is_numeric($m) && (int)$m >= 0 && (int)$m <= 90) $marcos[(int)$m] = (int)$m; }
    $marcos = array_values($marcos); rsort($marcos);
    $emails = [];
    foreach (preg_split('/[\s,;]+/', is_array($in['emails'] ?? null) ? implode(',', $in['emails']) : (string)($in['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
        $e = mb_strtolower(trim($e));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido: ' . $e);
        $emails[$e] = $e;
    }
    $c = [
        'ativo' => !empty($in['ativo']), 'marcos' => $marcos ?: [7, 3, 1, 0],
        'repetir_vencida_horas' => max(1, min(168, (int)($in['repetir_vencida_horas'] ?? 24))),
        'sino' => !empty($in['sino']), 'email' => !empty($in['email']), 'emails' => array_values($emails),
        'whatsapp' => preg_replace('/\D/', '', (string)($in['whatsapp'] ?? '')),
    ];
    lal_json_write(LAL_CONFIG, $c);
    return $c;
}

/** Locações ativas com previsão, com dias até o vencimento (negativo = vencida). */
function lal_itens(array $d): array {
    $hoje = loc_hoje(); $r = [];
    foreach ((array)($d['locacoes'] ?? []) as $l) {
        if (($l['status'] ?? '') !== 'ATIVA' || empty($l['previsao_devolucao'])) continue;
        $r[] = ['id' => $l['id'], 'equipamento' => $l['equipamento'], 'fornecedor' => $l['fornecedor'], 'local' => $l['local_atual'] ?? '',
            'responsavel' => $l['responsavel'] ?? '', 'previsao' => $l['previsao_devolucao'], 'dias' => loc_dias($hoje, $l['previsao_devolucao']),
            'quantidade' => (int)($l['quantidade'] ?? 1), 'contrato' => $l['contrato'] ?? ''];
    }
    usort($r, fn($a, $b) => strcmp($a['previsao'], $b['previsao']));
    return $r;
}

function lal_rotulo(int $dias): string {
    if ($dias < 0) return 'vencida há ' . (-$dias) . ' dia(s)';
    if ($dias === 0) return 'vence HOJE';
    if ($dias === 1) return 'vence amanhã';
    return 'vence em ' . $dias . ' dias';
}

/** Verifica e avisa. $forcar: reenvia o resumo de tudo que está vencido/próximo, sem consultar o que já foi avisado. */
function lal_verificar(string $motivo = 'batimento', bool $forcar = false): array {
    $fp = @fopen(LAL_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) return ['ok' => true, 'ocupado' => true, 'avisados' => 0];
    try {
        $cfg = lal_config();
        $est = lal_json_read(LAL_ESTADO, ['enviados' => [], 'vencidas' => [], 'historico' => [], 'ultima_verificacao' => null, 'ultimo_email' => null]);
        $est['ultima_verificacao'] = date('c');
        if (!$cfg['ativo'] && !$forcar) { lal_json_write(LAL_ESTADO, $est); return ['ok' => true, 'ativo' => false, 'avisados' => 0]; }
        $itens = lal_itens(loc_read());
        $marcos = $cfg['marcos']; sort($marcos);
        $ids = array_column($itens, 'id');
        // limpa estado de locações que já saíram da lista
        $est['vencidas'] = array_intersect_key((array)$est['vencidas'], array_flip($ids));
        $maior = $marcos ? max($marcos) : 7;
        $lista = []; $agora = time();
        foreach ($itens as $i) {
            $chave = $i['id'] . '|' . $i['previsao'];
            if ($i['dias'] < 0) {
                $ult = (int)($est['vencidas'][$i['id']]['ts'] ?? 0);
                $mudouData = ($est['vencidas'][$i['id']]['previsao'] ?? '') !== $i['previsao'];
                if ($forcar || $mudouData || $agora - $ult >= $cfg['repetir_vencida_horas'] * 3600) {
                    $lista[] = $i; $est['vencidas'][$i['id']] = ['ts' => $agora, 'previsao' => $i['previsao']];
                }
                continue;
            }
            if ($i['dias'] > $maior) continue;
            $m = null; foreach ($marcos as $x) { if ($i['dias'] <= $x) { $m = $x; break; } }   // menor marco já atingido
            if ($m === null) continue;
            $k = $chave . '|' . $m;
            if ($forcar || empty($est['enviados'][$k])) {
                $lista[] = $i;
                foreach ($marcos as $x) if ($x >= $m) $est['enviados'][$chave . '|' . $x] = $agora;   // marcos maiores já ficam como avisados
            }
        }
        // poda marcos de datas antigas
        foreach (array_keys($est['enviados']) as $k) if (!in_array(explode('|', $k)[0], $ids, true)) unset($est['enviados'][$k]);
        $res = ['ok' => true, 'avisados' => count($lista), 'sino' => false, 'email' => false, 'erro_email' => null, 'motivo' => $motivo];
        if ($lista) {
            $venc = count(array_filter($lista, fn($i) => $i['dias'] < 0));
            $titulo = $venc ? '⚠ Locação: ' . $venc . ' devolução(ões) vencida(s)' : '⏰ Locação: ' . count($lista) . ' devolução(ões) se aproximando';
            $linhas = array_map(fn($i) => $i['equipamento'] . ' (' . $i['fornecedor'] . ', ' . $i['local'] . ') — ' . lal_rotulo($i['dias']), $lista);
            if ($cfg['sino']) {
                try {
                    require_once __DIR__ . '/notify_lib.php';
                    notif_publish(['tipo' => 'sistema', 'modulo' => 'locacoes', 'titulo' => $titulo, 'texto' => implode(' · ', array_slice($linhas, 0, 4)) . (count($linhas) > 4 ? ' …' : ''), 'url' => '?pagina=locacoes']);
                    $res['sino'] = true;
                } catch (Throwable $e) { error_log('Locações/sino: ' . $e->getMessage()); }
            }
            if ($cfg['email']) {
                try {
                    require_once __DIR__ . '/alertas_lib.php';
                    $ac = alt_config();
                    $para = $cfg['emails'] ?: $ac['emails'];
                    if ($para) {
                        [$assunto, $html, $texto] = lal_email_montar($lista, $titulo);
                        alt_email_enviar($ac, $assunto, $html, $texto, $para);
                        $res['email'] = true; $est['ultimo_email'] = date('c');
                    } else $res['erro_email'] = 'Nenhum e-mail configurado.';
                } catch (Throwable $e) { $res['erro_email'] = $e->getMessage(); error_log('Locações/e-mail: ' . $e->getMessage()); }
            }
            $est['historico'][] = ['em' => date('c'), 'motivo' => $motivo, 'qtd' => count($lista), 'vencidas' => $venc, 'sino' => $res['sino'], 'email' => $res['email'], 'itens' => array_column($lista, 'id')];
            $est['historico'] = array_slice($est['historico'], -60);
        }
        lal_json_write(LAL_ESTADO, $est);
        return $res;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/** Batimento das telas abertas: no máximo a cada 30 min. */
function lal_tick(): void {
    $est = lal_json_read(LAL_ESTADO, []);
    if (!empty($est['ultima_verificacao']) && time() - strtotime($est['ultima_verificacao']) < 1800) return;
    try { lal_verificar('batimento'); } catch (Throwable $e) { error_log('Locações/alertas: ' . $e->getMessage()); }
}

function lal_email_montar(array $lista, string $assunto): array {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $base = isset($_SERVER['HTTP_HOST']) ? 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST']) : '';
    $linhas = ''; $txt = [];
    foreach ($lista as $i) {
        $cor = $i['dias'] < 0 ? '#b42318' : ($i['dias'] <= 1 ? '#c4320a' : '#b54708');
        $linhas .= '<tr><td style="padding:8px;border-bottom:1px solid #eee"><b>' . $h(($i['quantidade'] > 1 ? $i['quantidade'] . '× ' : '') . $i['equipamento']) . '</b><br><span style="color:#667085;font-size:12px">' . $h($i['id'] . ' · ' . $i['fornecedor'] . ($i['contrato'] ? ' · contrato ' . $i['contrato'] : '')) . '</span></td>'
            . '<td style="padding:8px;border-bottom:1px solid #eee">' . $h($i['local']) . ($i['responsavel'] ? '<br><span style="color:#667085;font-size:12px">' . $h($i['responsavel']) . '</span>' : '') . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #eee">' . date('d/m/Y', strtotime($i['previsao'])) . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #eee"><b style="color:' . $cor . '">' . $h(lal_rotulo($i['dias'])) . '</b></td></tr>';
        $txt[] = '- ' . $i['equipamento'] . ' (' . $i['fornecedor'] . ', ' . $i['local'] . '): devolver até ' . date('d/m/Y', strtotime($i['previsao'])) . ' — ' . lal_rotulo($i['dias']);
    }
    $html = '<div style="font-family:Arial,sans-serif;color:#101828;max-width:760px"><div style="background:#0b1f3a;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0"><b style="font-size:17px">Devolução de locação · Drilling</b><br><span style="font-size:12px;color:#b9c8de">' . date('d/m/Y H:i') . '</span></div>'
        . '<div style="border:1px solid #e4e7ec;border-top:0;padding:8px 20px 20px;border-radius:0 0 10px 10px"><table style="width:100%;border-collapse:collapse;font-size:13px"><tr style="background:#f2f4f7;text-align:left"><th style="padding:8px">Equipamento</th><th style="padding:8px">Onde está</th><th style="padding:8px">Devolver até</th><th style="padding:8px">Situação</th></tr>' . $linhas . '</table>'
        . '<p style="font-size:12px;color:#667085;margin-top:18px">Equipamento vencido continua gerando custo de aluguel. Devolva ou prorrogue a data no sistema.' . ($base ? ' <a href="' . $base . '/?pagina=locacoes">Abrir Locações</a>.' : '') . '</p></div></div>';
    return [$assunto, $html, $assunto . "\n\n" . implode("\n", $txt)];
}

/** Calendário (.ics): um evento de dia inteiro por locação ativa, com lembretes 7, 3 e 1 dia antes. */
function lal_ics(array $d): string {
    $esc = fn($s) => str_replace(["\\", ';', ',', "\r", "\n"], ["\\\\", '\;', '\,', '', '\n'], (string)$s);
    $o = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Drilling do Brasil//Locacoes//PT", "CALSCALE:GREGORIAN", "X-WR-CALNAME:Devolução de locações"];
    foreach (lal_itens($d) as $i) {
        $dt = date('Ymd', strtotime($i['previsao'] . ' 12:00')); $fim = date('Ymd', strtotime($i['previsao'] . ' 12:00 +1 day'));
        $o[] = 'BEGIN:VEVENT';
        $o[] = 'UID:' . $i['id'] . '-' . $dt . '@drilling-locacoes';
        $o[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $o[] = 'DTSTART;VALUE=DATE:' . $dt; $o[] = 'DTEND;VALUE=DATE:' . $fim;
        $o[] = 'SUMMARY:' . $esc('Devolver: ' . $i['equipamento'] . ' (' . $i['fornecedor'] . ')');
        $o[] = 'DESCRIPTION:' . $esc($i['id'] . ' · ' . $i['local'] . ($i['responsavel'] ? ' · resp. ' . $i['responsavel'] : '') . ($i['contrato'] ? ' · contrato ' . $i['contrato'] : ''));
        foreach (['-P7D', '-P3D', '-P1D'] as $t) { $o[] = 'BEGIN:VALARM'; $o[] = 'ACTION:DISPLAY'; $o[] = 'DESCRIPTION:Devolução de locação'; $o[] = 'TRIGGER:' . $t; $o[] = 'END:VALARM'; }
        $o[] = 'END:VEVENT';
    }
    $o[] = 'END:VCALENDAR';
    return implode("\r\n", $o) . "\r\n";
}

/** Dados para a tela: configuração, histórico de avisos e contadores do controle. */
function lal_painel(array $d): array {
    $cfg = lal_config();
    $est = lal_json_read(LAL_ESTADO, ['historico' => [], 'ultima_verificacao' => null, 'ultimo_email' => null]);
    require_once __DIR__ . '/alertas_lib.php';
    $ac = alt_config();
    $it = lal_itens($d);
    return ['config' => $cfg, 'email_geral' => $ac['emails'], 'ultima_verificacao' => $est['ultima_verificacao'], 'ultimo_email' => $est['ultimo_email'],
        'historico' => array_slice(array_reverse((array)$est['historico']), 0, 15),
        'contagem' => ['vencidas' => count(array_filter($it, fn($i) => $i['dias'] < 0)), 'hoje' => count(array_filter($it, fn($i) => $i['dias'] === 0)),
            'd7' => count(array_filter($it, fn($i) => $i['dias'] >= 0 && $i['dias'] <= 7)), 'd30' => count(array_filter($it, fn($i) => $i['dias'] >= 0 && $i['dias'] <= 30))]];
}
