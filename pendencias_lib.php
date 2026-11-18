<?php
/**
 * v3.51.0 — Painel "Pendências de hoje" (Dashboard): o que precisa de atenção agora, por módulo.
 * Só leitura. Cada seção só aparece se o usuário tem permissão de ver aquele módulo.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

/** Telefone do campo "contato" (só dígitos; 10–11 dígitos viram +55…). null se não houver. */
function pend_telefone(string $contato): ?string {
    if (!preg_match_all('/\(?\d[\d\s().-]{7,}\d/', $contato, $m)) return null;
    foreach ($m[0] as $t) {
        $d = preg_replace('/\D/', '', $t);
        if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
        if (strlen($d) >= 12 && strlen($d) <= 13 && str_starts_with($d, '55')) return $d;
    }
    return null;
}

/** Mensagem de cobrança de uma retirada atrasada. */
function pend_msg_cobranca(array $c): string {
    $forn = trim((string)($c['fornecedor'] ?? '')) ?: 'fornecedor';
    $quando = !empty($c['agendada_data']) ? date('d/m/Y', strtotime($c['agendada_data'])) . (!empty($c['agendada_hora']) ? ' às ' . $c['agendada_hora'] : '') : 'a data combinada';
    $pc = !empty($c['pc']) ? ' do PC ' . $c['pc'] : '';
    $m = "Olá, {$forn}! Aqui é da Drilling do Brasil.\n\nSobre a retirada{$pc}: estava agendada para {$quando} e ainda não foi realizada.";
    $motivo = trim((string)($c['motivo_atraso_label'] ?? ''));
    if ($motivo !== '') $m .= " (motivo registrado: {$motivo})";
    $m .= "\n\nPode nos confirmar quando o material estará liberado para retirada? Assim reorganizamos a rota do nosso motorista.\n\nObrigado!";
    return $m;
}

function pend_hoje(): array {
    $hoje = date('Y-m-d');
    $secoes = [];
    $pode = fn(string $mod) => function_exists('rd_perm') ? rd_perm($mod, 'listar') || rd_perm($mod, 'ver') : true;

    if ($pode('coletas')) {
        require_once __DIR__ . '/coletas_lib.php';
        $db = col_read();
        $atr = []; $hojeL = []; $semLoc = [];
        foreach ($db['coletas'] as $c) {
            if (in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU', 'CANCELADA'], true)) continue;
            $v = col_view($c);
            $tel = pend_telefone((string)($c['contato'] ?? ''));
            $base = ['id' => $c['id'], 'titulo' => ($c['fornecedor'] ?? '') ?: $c['id'], 'pc' => $c['pc'] ?? null];
            if (!empty($v['atrasada'])) {
                $dias = max(1, (int)floor((strtotime($hoje) - strtotime($c['agendada_data'])) / 86400));
                $atr[] = $base + ['sub' => ($c['pc'] ? 'PC ' . $c['pc'] . ' · ' : '') . $dias . ' dia(s) de atraso' . (($v['motivo_atraso_label'] ?? '') !== '' ? ' · ' . $v['motivo_atraso_label'] : ''), 'dias' => $dias,
                    'wa' => 'https://wa.me/' . ($tel ?? '') . '?text=' . rawurlencode(pend_msg_cobranca($v)), 'wa_direto' => $tel !== null];
            } elseif (!empty($v['hoje'])) {
                $hojeL[] = $base + ['sub' => ($c['pc'] ? 'PC ' . $c['pc'] . ' · ' : '') . (($c['agendada_hora'] ?? '') ?: 'hoje') . ' · ' . ($c['cidade'] ?? '')];
            }
            if (!$v['tem_coordenadas']) $semLoc[] = $base + ['sub' => ($c['pc'] ? 'PC ' . $c['pc'] . ' · ' : '') . (($c['geo_status'] ?? '') === 'divergente' ? 'endereço diverge do mapa' : 'sem localização no mapa')];
        }
        usort($atr, fn($a, $b) => $b['dias'] <=> $a['dias']);
        $secoes[] = ['id' => 'coletas_atrasadas', 'modulo' => 'coletas', 'icone' => '⏰', 'titulo' => 'Coletas atrasadas', 'tom' => 'danger', 'total' => count($atr), 'itens' => array_slice($atr, 0, 8)];
        $secoes[] = ['id' => 'coletas_hoje', 'modulo' => 'coletas', 'icone' => '🚚', 'titulo' => 'Coletas de hoje', 'tom' => 'info', 'total' => count($hojeL), 'itens' => array_slice($hojeL, 0, 8)];
        $secoes[] = ['id' => 'coletas_semloc', 'modulo' => 'coletas', 'icone' => '📍', 'titulo' => 'Coletas sem localização', 'tom' => 'warn', 'total' => count($semLoc), 'itens' => array_slice($semLoc, 0, 8)];
    }

    if ($pode('estoque') && is_file(__DIR__ . '/estoque_lib.php')) {
        require_once __DIR__ . '/estoque_lib.php';
        $edb = est_read();
        $rep = est_reposicao($edb);
        $zer = array_values(array_filter($rep['itens'], fn($i) => $i['urgencia'] === 'ZERADO'));
        $secoes[] = ['id' => 'estoque_repor', 'modulo' => 'estoque', 'icone' => '📦', 'titulo' => 'Itens a repor', 'tom' => $zer ? 'danger' : 'warn', 'total' => $rep['total'],
            'resumo' => count($zer) . ' sem estoque', 'itens' => array_map(fn($i) => ['id' => $i['codigo'], 'titulo' => $i['descricao'], 'sub' => 'saldo ' . $i['saldo'] . ' ' . $i['unidade'] . ' · ' . strtolower($i['urgencia'])], array_slice($rep['itens'], 0, 8))];
        $pend = [];
        foreach ($edb['recebimentos'] ?? [] as $r) if (($r['status'] ?? 'pendente') !== 'recebido') $pend[] = $r;
        usort($pend, fn($a, $b) => strcmp((string)($a['criado_em'] ?? ''), (string)($b['criado_em'] ?? '')));
        $secoes[] = ['id' => 'estoque_entradas', 'modulo' => 'estoque', 'icone' => '📥', 'titulo' => 'Entradas a conferir/receber', 'tom' => 'warn', 'total' => count($pend),
            'itens' => array_map(fn($r) => ['id' => (string)($r['pc'] ?? ''), 'titulo' => ($r['pc'] ? 'PC ' . $r['pc'] : $r['entrada_id']) . ' · ' . ($r['fornecedor'] ?? ''), 'sub' => ($r['status'] ?? 'pendente') === 'conferido' ? 'conferida — falta receber' : 'falta conferir'], array_slice($pend, 0, 8))];
    }
    return ['hoje' => $hoje, 'secoes' => $secoes, 'total' => array_sum(array_map(fn($s) => (int)$s['total'], array_filter($secoes, fn($s) => $s['id'] !== 'coletas_hoje')))];
}
