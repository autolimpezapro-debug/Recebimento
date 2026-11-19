<?php
/**
 * Recebimento Drilling — Chegadas no almoxarifado (v3.54.0).
 * Controla o que chega "incompleto": MATERIAL SEM NF, NF SEM MATERIAL e COMPRA ONLINE SEM NF.
 * Cada ocorrência fica ABERTA (com prazo e cobranças ao fornecedor) até ser RESOLVIDA. Nada é obrigatório além do tipo.
 * Não mexe no estoque nem nos pedidos: é um controle de pendências. Dados em data/chegadas.json; fotos em data/chegadas_fotos/.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/romaneios_lib.php';   // prt_str/prt_valor/prt_fail

const CHG_FILE = DATA_DIR . '/chegadas.json';
const CHG_LOCK = DATA_DIR . '/.chegadas.lock';
const CHG_FOTOS = DATA_DIR . '/chegadas_fotos';
const CHG_TIPOS = [
    'MATERIAL_SEM_NF' => 'Material chegou sem NF',
    'NF_SEM_MATERIAL' => 'NF chegou sem o material',
    'ONLINE_SEM_NF'   => 'Compra online sem NF',
    'OUTRO'           => 'Outra divergência',
];
const CHG_RESOLUCOES = [
    'NF_RECEBIDA'       => 'NF recebida do fornecedor',
    'MATERIAL_RECEBIDO' => 'Material localizado / recebido',
    'MATERIAL_ENTREGUE' => 'Fornecedor confirmou entrega anterior (conferido)',
    'DEVOLVIDO'         => 'Material devolvido ao fornecedor',
    'SEM_NF_AUTORIZADO' => 'Liberado sem NF (autorizado)',
    'OUTRO'             => 'Outro',
];

function chg_read(): array {
    $vazio = ['versao' => 1, 'seq' => 0, 'registros' => []];
    if (!is_file(CHG_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(CHG_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/chegadas.json. Preserve o arquivo e restaure o backup.', 500);
    return $d + $vazio;
}
function chg_write(array $d): void {
    $tmp = CHG_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar a chegada.', 500);
    if (is_file(CHG_FILE)) @copy(CHG_FILE, CHG_FILE . '.backup.json');
    if (!rename($tmp, CHG_FILE)) prt_fail('Falha ao persistir a chegada.', 500);
}
function chg_with_lock(callable $fn) {
    $fp = @fopen(CHG_LOCK, 'c');
    if (!$fp) prt_fail('Não foi possível travar o arquivo de chegadas.', 500);
    flock($fp, LOCK_EX);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function chg_foto_salvar(string $dataUrl, string $id, int $n): string {
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) prt_fail('Foto inválida.', 422);
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024 || !@getimagesizefromstring($bin)) prt_fail('Foto inválida ou grande demais.', 422);
    if (!is_dir(CHG_FOTOS)) { @mkdir(CHG_FOTOS, 0750, true); @file_put_contents(CHG_FOTOS . '/.htaccess', "Require all denied\nOptions -Indexes\n"); }
    $arq = $id . '-' . $n . '-' . bin2hex(random_bytes(4)) . ($m[1] === 'png' ? '.png' : ($m[1] === 'webp' ? '.webp' : '.jpg'));
    if (@file_put_contents(CHG_FOTOS . '/' . $arq, $bin, LOCK_EX) === false) prt_fail('Não foi possível gravar a foto.', 500);
    return $arq;
}
function chg_foto_path(string $arq): ?string {
    if (!preg_match('/^CHG-\d{4}-\d{4}-\d+-[a-f0-9]{8}\.(jpg|png|webp)$/', $arq)) return null;
    $p = CHG_FOTOS . '/' . $arq; return is_file($p) ? $p : null;
}

/* ---------------------------------------------------------------- */
function chg_norm(array $in): array {
    $tipo = (string)($in['tipo'] ?? ''); if (!isset(CHG_TIPOS[$tipo])) prt_fail('Escolha o tipo da chegada.');
    $data = substr(trim((string)($in['data_chegada'] ?? '')), 0, 10); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');
    $itens = [];
    foreach (array_slice((array)($in['itens'] ?? []), 0, 80) as $it) {
        if (!is_array($it)) continue;
        $d = prt_str($it['descricao'] ?? '', 160); $q = trim((string)($it['quantidade'] ?? ''));
        if ($d === '' && $q === '') continue;
        $itens[] = ['descricao' => $d, 'quantidade' => $q === '' ? null : round(prt_valor($q), 3), 'unidade' => mb_strtoupper(prt_str($it['unidade'] ?? '', 8)), 'sku' => prt_str($it['sku'] ?? '', 40)];
    }
    $pz = (int)($in['prazo_dias'] ?? 5); if ($pz < 1 || $pz > 90) $pz = 5;
    return ['tipo' => $tipo, 'data_chegada' => $data, 'fornecedor' => prt_str($in['fornecedor'] ?? '', 120), 'pc' => ((int)($in['pc'] ?? 0)) ?: null,
        'nf_numero' => prt_str($in['nf_numero'] ?? '', 30), 'nf_chave' => preg_replace('/\D/', '', (string)($in['nf_chave'] ?? '')), 'valor' => round(max(0, prt_valor((string)($in['valor'] ?? '0'))), 2),
        'origem' => prt_str($in['origem'] ?? '', 100), 'transportadora' => prt_str($in['transportadora'] ?? '', 80), 'entregador' => prt_str($in['entregador'] ?? '', 80),
        'recebido_por' => prt_str($in['recebido_por'] ?? '', 80), 'prazo_dias' => $pz, 'contato' => prt_str($in['contato'] ?? '', 120),
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 600), 'itens' => $itens];
}
function &chg_get(array &$d, string $id) {
    foreach ($d['registros'] as $k => &$r) if ($r['id'] === $id) return $r;
    prt_fail('Registro não encontrado.', 404);
}
function chg_view(array $r): array {
    $r['tipo_label'] = CHG_TIPOS[$r['tipo']] ?? $r['tipo'];
    $ini = strtotime($r['data_chegada'] . ' 00:00'); $fim = $r['status'] === 'ABERTO' ? time() : strtotime(substr((string)($r['resolvido_em'] ?? $r['cancelado_em'] ?? date('c')), 0, 10) . ' 23:59');
    $r['dias'] = max(0, (int)floor(($fim - $ini) / 86400));
    $r['vencido'] = $r['status'] === 'ABERTO' && $r['dias'] > (int)$r['prazo_dias'];
    $r['qtd_cobrancas'] = count($r['cobrancas'] ?? []);
    $r['ultima_cobranca'] = $r['cobrancas'] ? substr(end($r['cobrancas'])['em'], 0, 10) : null;
    $r['resolucao_label'] = CHG_RESOLUCOES[$r['resolucao'] ?? ''] ?? '';
    return $r;
}
function chg_painel(array $d): array {
    $lista = array_map('chg_view', array_reverse($d['registros']));
    $k = ['abertos' => 0, 'vencidos' => 0, 'resolvidos' => 0, 'sem_nf' => 0, 'nf_sem_material' => 0, 'online' => 0, 'valor_aberto' => 0.0];
    $forn = [];
    foreach ($lista as $r) {
        if ($r['status'] === 'RESOLVIDO') $k['resolvidos']++;
        if ($r['status'] !== 'ABERTO') continue;
        $k['abertos']++; if ($r['vencido']) $k['vencidos']++; $k['valor_aberto'] += $r['valor'];
        if ($r['tipo'] === 'MATERIAL_SEM_NF') $k['sem_nf']++; elseif ($r['tipo'] === 'NF_SEM_MATERIAL') $k['nf_sem_material']++; elseif ($r['tipo'] === 'ONLINE_SEM_NF') $k['online']++;
        $f = $r['fornecedor'] ?: '(sem fornecedor)'; $forn[$f] = ($forn[$f] ?? 0) + 1;
    }
    arsort($forn); $k['valor_aberto'] = round($k['valor_aberto'], 2);
    return ['kpis' => $k, 'tipos' => CHG_TIPOS, 'resolucoes' => CHG_RESOLUCOES, 'fornecedores_pendentes' => array_map(fn($n, $q) => ['fornecedor' => $n, 'abertos' => $q], array_keys($forn), array_values($forn)), 'registros' => $lista];
}
function chg_salvar(array &$d, array $in, string $user): array {
    $n = chg_norm($in); $id = prt_str($in['id'] ?? '', 20); $agora = date('c');
    $novas = array_slice((array)($in['fotos_novas'] ?? []), 0, 4);
    if ($id !== '') {
        $r = &chg_get($d, $id);
        if ($r['status'] !== 'ABERTO') prt_fail('Só uma chegada aberta pode ser editada. Reabra primeiro.');
        $r = array_merge($r, $n); $r['atualizado_em'] = $agora;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Dados atualizados'];
    } else {
        $d['seq'] = (int)$d['seq'] + 1;
        $id = 'CHG-' . date('Y') . '-' . str_pad((string)$d['seq'], 4, '0', STR_PAD_LEFT);
        $d['registros'][] = ['id' => $id, 'status' => 'ABERTO', 'criado_em' => $agora, 'criado_por' => $user, 'fotos' => [], 'cobrancas' => [], 'historico' => [['em' => $agora, 'por' => $user, 'acao' => 'Chegada registrada: ' . CHG_TIPOS[$n['tipo']]]]] + $n;
        $r = &$d['registros'][array_key_last($d['registros'])];
    }
    foreach ((array)($in['fotos_remover'] ?? []) as $rm) { $i = array_search($rm, $r['fotos'], true); if ($i !== false) array_splice($r['fotos'], $i, 1); }
    foreach ($novas as $u) { if (count($r['fotos']) >= 6) break; if (is_string($u) && $u !== '') $r['fotos'][] = chg_foto_salvar($u, $r['id'], count($r['fotos']) + 1); }
    return $r;
}
/** acao: cobrar (canal, obs) | resolver (resolucao, nf_numero, nf_data, obs) | reabrir (motivo) | cancelar (motivo) */
function chg_acao(array &$d, array $in, string $user): array {
    $id = prt_str($in['id'] ?? '', 20); $ac = (string)($in['acao'] ?? ''); $agora = date('c');
    $r = &chg_get($d, $id);
    if ($ac === 'cobrar') {
        if ($r['status'] !== 'ABERTO') prt_fail('Só chegada aberta pode ser cobrada.');
        $canal = prt_str($in['canal'] ?? '', 30) ?: 'Outro'; $o = prt_str($in['obs'] ?? '', 200);
        $r['cobrancas'][] = ['em' => $agora, 'por' => $user, 'canal' => $canal, 'obs' => $o];
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Cobrança ao fornecedor (' . $canal . ')' . ($o ? ': ' . $o : '')];
    } elseif ($ac === 'resolver') {
        if ($r['status'] !== 'ABERTO') prt_fail('Esta chegada já foi encerrada.');
        $res = (string)($in['resolucao'] ?? ''); if (!isset(CHG_RESOLUCOES[$res])) prt_fail('Escolha como foi resolvida.');
        $r['status'] = 'RESOLVIDO'; $r['resolucao'] = $res; $r['resolvido_em'] = $agora; $r['resolvido_por'] = $user;
        $nf = prt_str($in['nf_numero'] ?? '', 30); if ($nf !== '') $r['nf_numero'] = $nf;
        $r['resolucao_obs'] = prt_str($in['obs'] ?? '', 300);
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Resolvida: ' . CHG_RESOLUCOES[$res] . ($nf !== '' ? ' (NF ' . $nf . ')' : '') . ($r['resolucao_obs'] ? ' — ' . $r['resolucao_obs'] : '')];
    } elseif ($ac === 'reabrir') {
        if ($r['status'] === 'ABERTO') prt_fail('Já está aberta.');
        $m = prt_str($in['motivo'] ?? '', 200);
        $r['status'] = 'ABERTO'; foreach (['resolucao', 'resolvido_em', 'resolvido_por', 'resolucao_obs', 'cancelado_em', 'motivo_cancelamento'] as $k) unset($r[$k]);
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Reaberta' . ($m ? ': ' . $m : '')];
    } elseif ($ac === 'cancelar') {
        if ($r['status'] !== 'ABERTO') prt_fail('Só chegada aberta pode ser cancelada.');
        $m = prt_str($in['motivo'] ?? '', 200); if (mb_strlen($m) < 3) prt_fail('Informe o motivo do cancelamento (ex.: lançado por engano).');
        $r['status'] = 'CANCELADO'; $r['cancelado_em'] = $agora; $r['motivo_cancelamento'] = $m;
        $r['historico'][] = ['em' => $agora, 'por' => $user, 'acao' => 'Cancelada: ' . $m];
    } else prt_fail('Ação inválida.');
    return $r;
}
/** Pedidos de compra para vincular (por nº, NF ou fornecedor/material) + informa se o pedido já consta como recebido. */
function chg_buscar_pcs(string $q, int $limite = 12): array {
    $ped = function_exists('read_items') ? read_items() : [];
    $nk = fn($s) => preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper(trim((string)$s)));
    $q = trim($q); if ($q === '') return [];
    $ws = array_filter(array_map($nk, preg_split('/\s+/', $q)));
    $out = [];
    foreach (array_reverse($ped) as $p) {
        $hay = $nk(($p['pc'] ?? '') . ' ' . ($p['fornecedor'] ?? '') . ' ' . ($p['numeroNF'] ?? '') . ' ' . ($p['material'] ?? ''));
        $ok = true; foreach ($ws as $w) if ($w !== '' && !str_contains($hay, $w)) { $ok = false; break; }
        if (!$ok) continue;
        $out[] = ['pc' => (int)$p['pc'], 'fornecedor' => (string)($p['fornecedor'] ?? ''), 'material' => mb_substr((string)($p['material'] ?? ''), 0, 90), 'nf' => (string)($p['numeroNF'] ?? ''),
            'situacao' => (string)($p['situacao'] ?? ''), 'recebido' => in_array($p['recebimento_estado'] ?? '', ['finalizado', 'em_andamento'], true) || !empty($p['entregas']), 'tem_nf' => !empty($p['numeroNF']) || !empty($p['chaveNF'])];
        if (count($out) >= $limite) break;
    }
    return $out;
}
function chg_csv(array $d): string {
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
    $o = "\xEF\xBB\xBFCodigo;Status;Tipo;Chegada;Dias;Prazo (dias);Vencido;Fornecedor;PC;NF;Valor;Origem;Recebido por;Materiais;Cobrancas;Ultima cobranca;Resolucao;Obs\r\n";
    foreach (chg_painel($d)['registros'] as $r)
        $o .= implode(';', [$q($r['id']), $q($r['status']), $q($r['tipo_label']), $q(implode('/', array_reverse(explode('-', $r['data_chegada'])))), $r['dias'], $r['prazo_dias'], $r['vencido'] ? 'SIM' : '', $q($r['fornecedor']), $q($r['pc']), $q($r['nf_numero']), str_replace('.', ',', (string)$r['valor']), $q($r['origem']),
            $q($r['recebido_por']), $q(implode(' | ', array_map(fn($i) => trim(($i['quantidade'] ?? '') . ' ' . $i['unidade'] . ' ' . $i['descricao']), $r['itens']))), $r['qtd_cobrancas'], $q($r['ultima_cobranca']), $q($r['resolucao_label']), $q($r['obs'])]) . "\r\n";
    return $o;
}
