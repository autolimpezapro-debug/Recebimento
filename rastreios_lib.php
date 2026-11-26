<?php
/**
 * Recebimento Drilling — Rastreios de envios (v3.64.0), dentro do módulo Terceiros.
 * Controle dos envios feitos por transportadoras/Correios: código de rastreio, link, datas, recebedor e situação,
 * com painel de gestão (em trânsito, atrasados, prazo por transportadora, conferência de dados).
 * NÃO consulta as transportadoras (isso exige contrato/chave): o link abre o site delas já com o código.
 * Não movimenta Estoque. Dados em data/rastreios.json (com backup .backup.json a cada gravação).
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/protocolos_lib.php';   // prt_fail, prt_str

const RTR_FILE = DATA_DIR . '/rastreios.json';
const RTR_LOCK = DATA_DIR . '/.rastreios.lock';
/** Modelos de link por transportadora. {codigo} é trocado pelo código. Editáveis em "Configurar". */
const RTR_MODELOS = [
    'Correios'         => 'https://rastreamento.correios.com.br/app/index.php?objetos={codigo}',
    'Azul Cargo'       => 'https://www.azullogistica.com.br/Rastreio/Rastrear',
    'Satilog'          => 'https://satilog.com.br/rastreamento/',
    'Rodonaves'        => 'https://rodonaves.com.br/rastreio-de-mercadoria/home.html',
    'Gontijo'          => 'https://www.gontijo.com.br/',
    'Andorinha Express' => 'https://ssw.inf.br/2/rastreamento',
    'Gollog'           => 'https://www.gollog.com.br/',
];

function rtr_read(): array {
    $vazio = ['versao' => 1, 'seq' => 0, 'config' => ['prazo_alerta_dias' => 7, 'modelos' => []], 'rastreios' => []];
    if (!is_file(RTR_FILE)) return $vazio;
    $d = json_decode((string)file_get_contents(RTR_FILE), true);
    if (!is_array($d)) prt_fail('Falha ao ler data/rastreios.json. Preserve o arquivo e restaure o backup.', 500);
    $d += $vazio; $d['config'] += $vazio['config'];
    return $d;
}
function rtr_write(array $d): void {
    $tmp = RTR_FILE . '.tmp';
    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($j === false || file_put_contents($tmp, $j, LOCK_EX) === false) prt_fail('Não foi possível gravar os rastreios.', 500);
    if (is_file(RTR_FILE)) @copy(RTR_FILE, RTR_FILE . '.backup.json');
    if (!rename($tmp, RTR_FILE)) prt_fail('Falha ao persistir os rastreios.', 500);
}
function rtr_with_lock(callable $fn) {
    $fp = @fopen(RTR_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) prt_fail('Não foi possível bloquear os rastreios para gravação.', 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

/** "Azul cargos", "AZUL CARGO" → "Azul Cargo"; desconhecida fica como digitada (primeiras letras maiúsculas). */
function rtr_transportadora(string $t): string {
    $t = prt_str($t, 60); if ($t === '') return '';
    $k = mb_strtolower($t);
    if (str_contains($k, 'correio')) return 'Correios';
    if (str_contains($k, 'azul')) return 'Azul Cargo';
    if (str_contains($k, 'satilog') || str_contains($k, 'satlog')) return 'Satilog';
    if (str_contains($k, 'rodonaves')) return 'Rodonaves';
    if (str_contains($k, 'gontijo')) return 'Gontijo';
    if (str_contains($k, 'andorinha') || str_contains($k, 'ssw')) return 'Andorinha Express';
    if (str_contains($k, 'gollog') || $k === 'gol') return 'Gollog';
    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}
function rtr_modelo(array $d, string $transp): string {
    return (string)(($d['config']['modelos'][$transp] ?? '') ?: (RTR_MODELOS[$transp] ?? ''));
}
function rtr_data($v): string {
    $v = trim((string)$v); if ($v === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $v, $m)) {
        $a = (int)$m[3]; if ($a < 100) $a += 2000;
        if (checkdate((int)$m[2], (int)$m[1], $a)) return sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]);
    }
    return '';
}
function rtr_bool($v): bool { return in_array(mb_strtolower(trim((string)$v)), ['sim', 's', '1', 'true', 'yes', 'x'], true); }
function rtr_dias(string $a, string $b): ?int {
    if ($a === '' || $b === '') return null;
    return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
}

/** Valida/normaliza os dados de um rastreio (cadastro manual ou linha importada). Devolve [registro, avisos]. */
function rtr_norm(array $in): array {
    $codigo = prt_str($in['codigo'] ?? '', 80); if ($codigo === '-' || $codigo === '—') $codigo = '';
    $r = [
        'fornecedor'     => prt_str($in['fornecedor'] ?? '', 120),
        'pedido'         => prt_str($in['pedido'] ?? '', 40),
        'produto'        => prt_str($in['produto'] ?? '', 200),
        'transportadora' => rtr_transportadora((string)($in['transportadora'] ?? '')),
        'codigo'         => $codigo,
        'link'           => '',
        'data_envio'     => rtr_data($in['data_envio'] ?? ''),
        'data_entrega'   => rtr_data($in['data_entrega'] ?? ''),
        'data_previsao'  => rtr_data($in['data_previsao'] ?? ''),
        'recebedor'      => prt_str($in['recebedor'] ?? '', 80),
        'romaneio'       => strtoupper(prt_str($in['romaneio'] ?? '', 20)),
        'obs'            => prt_str($in['obs'] ?? '', 300),
    ];
    if ($r['transportadora'] === '' && preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', strtoupper($codigo))) $r['transportadora'] = 'Correios';   // código dos Correios é inconfundível
    if ($r['transportadora'] === 'Correios') $r['codigo'] = strtoupper($r['codigo']);
    $link = trim((string)($in['link'] ?? '')); if (preg_match('#^https?://#i', $link) && mb_strlen($link) <= 400) $r['link'] = $link;
    $r['finalizado'] = array_key_exists('finalizado', $in) ? (is_bool($in['finalizado']) ? $in['finalizado'] : rtr_bool($in['finalizado'])) : ($r['data_entrega'] !== '');
    if ($r['data_entrega'] !== '') $r['finalizado'] = true;
    $av = [];
    if ($r['data_envio'] === '') $av[] = 'sem data de envio válida';
    if (trim((string)($in['data_envio'] ?? '')) !== '' && $r['data_envio'] === '') $av[] = 'data de envio não reconhecida';
    if (trim((string)($in['data_entrega'] ?? '')) !== '' && $r['data_entrega'] === '') $av[] = 'data de entrega não reconhecida';
    if (trim((string)($in['data_previsao'] ?? '')) !== '' && $r['data_previsao'] === '') $av[] = 'previsão de entrega não reconhecida';
    if ($r['data_envio'] !== '' && $r['data_previsao'] !== '' && $r['data_previsao'] < $r['data_envio']) $av[] = 'previsão anterior ao envio';
    if ($r['data_envio'] !== '' && $r['data_entrega'] !== '' && $r['data_entrega'] < $r['data_envio']) $av[] = 'entrega anterior ao envio';
    return [$r, $av];
}

/** Acrescenta os campos calculados (situação, dias, link final, alertas de conferência). */
function rtr_derivar(array $d, array $r, string $hoje): array {
    $ent = $r['finalizado'] || ($r['data_entrega'] ?? '') !== '';
    $r['situacao'] = $ent ? 'ENTREGUE' : 'EM_TRANSITO';
    $fim = $ent ? ($r['data_entrega'] ?: '') : $hoje;
    $r['dias'] = rtr_dias((string)($r['data_envio'] ?? ''), $fim);
    $lim = (int)($d['config']['prazo_alerta_dias'] ?? 7);
    $prev = (string)($r['data_previsao'] ?? '');
    if ($prev !== '') {
        $r['atrasado'] = !$ent && $hoje > $prev;
        $r['dias_atraso'] = $ent ? max(0, (int)rtr_dias($prev, (string)$r['data_entrega'])) : max(0, (int)rtr_dias($prev, $hoje));
        $r['no_prazo'] = $ent ? ($r['data_entrega'] !== '' && $r['data_entrega'] <= $prev) : null;
    } else {
        $r['atrasado'] = !$ent && $r['dias'] !== null && $r['dias'] > $lim;
        $r['dias_atraso'] = 0;
        $r['no_prazo'] = $ent && $r['dias'] !== null ? ($r['dias'] <= $lim) : null;
    }
    $url = (string)($r['link'] ?? '');
    $mod = rtr_modelo($d, (string)($r['transportadora'] ?? ''));
    $r['link_final'] = $url !== '' ? $url : ($mod !== '' ? str_replace('{codigo}', rawurlencode((string)$r['codigo']), $mod) : '');
    $c = [];
    if (($r['codigo'] ?? '') === '') $c[] = 'Sem código de rastreio';
    if (($r['transportadora'] ?? '') === '') $c[] = 'Sem transportadora';
    if ($r['codigo'] !== '' && preg_match('/objetos=([A-Z0-9]+)/i', $url, $m) && strcasecmp($m[1], $r['codigo']) !== 0) $c[] = "O link aponta para outro código ({$m[1]})";
    if (($r['data_envio'] ?? '') !== '' && ($r['data_entrega'] ?? '') !== '' && $r['data_entrega'] < $r['data_envio']) $c[] = 'Entrega anterior ao envio';
    if ($prev !== '' && ($r['data_envio'] ?? '') !== '' && $prev < $r['data_envio']) $c[] = 'Previsão anterior ao envio';
    if ($ent && $r['dias'] !== null && $r['dias'] > 30) $c[] = "Entregue em {$r['dias']} dias (confira as datas)";
    if ($ent && ($r['recebedor'] ?? '') === '') $c[] = 'Entregue sem recebedor';
    if ($ent && ($r['data_entrega'] ?? '') === '') $c[] = 'Finalizado sem data de entrega';
    if (!$ent && $r['dias'] !== null && $r['dias'] > 30) $c[] = "Em trânsito há {$r['dias']} dias";
    $r['conferir'] = $c;
    return $r;
}

function rtr_painel(array $d): array {
    $hoje = date('Y-m-d'); $lista = [];
    foreach ($d['rastreios'] as $r) if (empty($r['excluido'])) $lista[] = rtr_derivar($d, $r, $hoje);
    usort($lista, fn($a, $b) => [$b['situacao'] === 'EM_TRANSITO', (string)$b['data_envio']] <=> [$a['situacao'] === 'EM_TRANSITO', (string)$a['data_envio']]);
    $nomes = array_keys(RTR_MODELOS); foreach ($lista as $r) if ($r['transportadora'] !== '' && !in_array($r['transportadora'], $nomes, true)) $nomes[] = $r['transportadora'];
    $mod = []; foreach ($nomes as $n) $mod[$n] = rtr_modelo($d, $n);
    return ['hoje' => $hoje, 'config' => ['prazo_alerta_dias' => (int)$d['config']['prazo_alerta_dias']], 'transportadoras' => $nomes, 'modelos' => $mod, 'rastreios' => $lista];
}

function rtr_salvar(array &$d, array $in, string $user): array {
    [$r, $av] = rtr_norm($in);
    if ($r['data_envio'] === '') prt_fail('Informe a data de envio.');
    if ($r['codigo'] === '' && $r['produto'] === '' && $r['fornecedor'] === '') prt_fail('Informe o código de rastreio ou o que foi enviado.');
    $id = trim((string)($in['id'] ?? ''));
    if ($id !== '') {
        foreach ($d['rastreios'] as $k => $x) if ($x['id'] === $id) {
            $d['rastreios'][$k] = array_merge($x, $r, ['atualizado_em' => date('c'), 'atualizado_por' => $user]);
            return ['rastreio' => rtr_derivar($d, $d['rastreios'][$k], date('Y-m-d')), 'avisos' => $av];
        }
        prt_fail('Rastreio não encontrado.', 404);
    }
    $d['seq'] = (int)$d['seq'] + 1;
    $novo = ['id' => sprintf('RAS-%04d', $d['seq'])] + $r + ['criado_em' => date('c'), 'criado_por' => $user];
    $d['rastreios'][] = $novo;
    return ['rastreio' => rtr_derivar($d, $novo, date('Y-m-d')), 'avisos' => $av];
}
function rtr_entregar(array &$d, array $in, string $user): array {
    $id = (string)($in['id'] ?? ''); $data = rtr_data($in['data_entrega'] ?? '') ?: date('Y-m-d');
    foreach ($d['rastreios'] as $k => $x) if ($x['id'] === $id) {
        if ($x['data_envio'] !== '' && $data < $x['data_envio']) prt_fail('A entrega não pode ser anterior ao envio (' . $x['data_envio'] . ').');
        $d['rastreios'][$k]['data_entrega'] = $data; $d['rastreios'][$k]['finalizado'] = true;
        $d['rastreios'][$k]['recebedor'] = prt_str($in['recebedor'] ?? ($x['recebedor'] ?? ''), 80);
        $d['rastreios'][$k]['atualizado_em'] = date('c'); $d['rastreios'][$k]['atualizado_por'] = $user;
        return ['rastreio' => rtr_derivar($d, $d['rastreios'][$k], date('Y-m-d'))];
    }
    prt_fail('Rastreio não encontrado.', 404);
}
function rtr_reabrir(array &$d, array $in, string $user): array {
    $id = (string)($in['id'] ?? '');
    foreach ($d['rastreios'] as $k => $x) if ($x['id'] === $id) {
        $d['rastreios'][$k]['data_entrega'] = ''; $d['rastreios'][$k]['finalizado'] = false;
        $d['rastreios'][$k]['atualizado_em'] = date('c'); $d['rastreios'][$k]['atualizado_por'] = $user;
        return ['rastreio' => rtr_derivar($d, $d['rastreios'][$k], date('Y-m-d'))];
    }
    prt_fail('Rastreio não encontrado.', 404);
}
function rtr_excluir(array &$d, array $in, string $user): array {
    $id = (string)($in['id'] ?? '');
    foreach ($d['rastreios'] as $k => $x) if ($x['id'] === $id) {
        $d['rastreios'][$k]['excluido'] = true; $d['rastreios'][$k]['excluido_em'] = date('c'); $d['rastreios'][$k]['excluido_por'] = $user;   // soft delete: fica no arquivo
        return ['id' => $id];
    }
    prt_fail('Rastreio não encontrado.', 404);
}
function rtr_config(array &$d, array $in): array {
    $dias = (int)($in['prazo_alerta_dias'] ?? 7); if ($dias < 1 || $dias > 60) prt_fail('O prazo de alerta deve ficar entre 1 e 60 dias.');
    $d['config']['prazo_alerta_dias'] = $dias;
    foreach ((array)($in['modelos'] ?? []) as $n => $u) {
        $n = prt_str($n, 60); $u = trim((string)$u); if ($n === '') continue;
        if ($u !== '' && !preg_match('#^https?://#i', $u)) prt_fail("O link de $n deve começar com http:// ou https://.");
        $d['config']['modelos'][$n] = mb_substr($u, 0, 400);
    }
    return ['config' => $d['config']];
}

/* ---------- Importação colando linhas do Excel ---------- */
const RTR_CAMPOS = ['fornecedor', 'pedido', 'produto', 'transportadora', 'codigo', 'link', 'data_envio', 'data_entrega', 'finalizado', 'recebedor'];   // modelo antigo de 10 colunas
function rtr_cab(string $h): string {
    $h = mb_strtolower(trim($h)); $h = strtr($h, ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    if (str_contains($h, 'fornecedor')) return 'fornecedor';
    if (str_contains($h, 'pedido')) return 'pedido';
    if (str_contains($h, 'produto')) return 'produto';
    if (str_contains($h, 'transportadora')) return 'transportadora';
    if (str_contains($h, 'codigo') || str_contains($h, 'rastreio') && !str_contains($h, 'site')) return 'codigo';
    if (str_contains($h, 'site') || str_contains($h, 'link')) return 'link';
    if (str_contains($h, 'envio')) return 'data_envio';
    if (str_contains($h, 'previs')) return 'data_previsao';
    if (str_contains($h, 'entrega')) return 'data_entrega';
    if (str_contains($h, 'finaliz')) return 'finalizado';
    if (str_contains($h, 'recebedor')) return 'recebedor';
    return '';
}
function rtr_chave(array $r): string {
    $c = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string)$r['codigo']));
    return $c !== '' ? $c . '|' . $r['data_envio'] : mb_strtoupper($r['fornecedor'] . '|' . $r['produto']) . '|' . $r['data_envio'];
}
/** Lê o texto colado. Devolve [linhas[], erro]. Cada linha: ['n','reg','avisos','estado'=>novo|duplicado|erro, 'motivo']. */
function rtr_importar_ler(array $d, string $texto): array {
    $texto = str_replace(["\r\n", "\r"], "\n", $texto); $ls = array_values(array_filter(array_map('rtrim', explode("\n", $texto)), fn($l) => trim($l) !== ''));
    if (!$ls) return [[], 'Cole as linhas copiadas da planilha.'];
    if (count($ls) > 2000) return [[], 'No máximo 2000 linhas por importação.'];
    $sep = str_contains($ls[0], "\t") ? "\t" : (substr_count($ls[0], ';') >= substr_count($ls[0], ',') ? ';' : ',');
    $linhas = array_map(fn($l) => array_map('trim', str_getcsv($l, $sep, '"', '\\')), $ls);
    $mapa = []; $cab = array_map('rtr_cab', $linhas[0]);
    if (count(array_filter($cab)) >= 3) { $mapa = $cab; array_shift($linhas); }
    else {
        $n = count($linhas[0]);
        $mapa = $n >= 10 ? RTR_CAMPOS : ($n === 6 ? ['codigo', 'link', 'data_envio', 'data_entrega', 'finalizado', 'recebedor'] : []);
        if (!$mapa) return [[], 'Não reconheci as colunas. Copie também a linha de títulos da planilha (FORNECEDOR, PRODUTO, TRANSPORTADORA, CÓDIGO DE RASTREIO…).'];
    }
    $exist = []; foreach ($d['rastreios'] as $x) if (empty($x['excluido'])) $exist[rtr_chave($x)] = 1;
    $vistos = []; $out = [];
    foreach ($linhas as $i => $cols) {
        $in = []; foreach ($mapa as $k => $campo) if ($campo !== '' && isset($cols[$k])) $in[$campo] = $cols[$k];
        if (isset($in['link']) && !preg_match('#^https?://#i', (string)$in['link'])) unset($in['link']);   // "GOLLOG: Transporte…" e "Rastreamento pelo destinatário" não são links
        [$r, $av] = rtr_norm($in); $estado = 'novo'; $motivo = '';
        if ($r['data_envio'] === '') { $estado = 'erro'; $motivo = 'Sem data de envio válida'; }
        elseif ($r['codigo'] === '' && $r['produto'] === '' && $r['fornecedor'] === '') { $estado = 'erro'; $motivo = 'Linha vazia'; }
        else { $k = rtr_chave($r); if (isset($exist[$k]) || isset($vistos[$k])) { $estado = 'duplicado'; $motivo = 'Já existe (mesmo código e data de envio)'; } $vistos[$k] = 1; }
        $out[] = ['n' => $i + 1, 'reg' => $r, 'avisos' => $av, 'estado' => $estado, 'motivo' => $motivo];
    }
    return [$out, ''];
}
function rtr_importar(array &$d, string $texto, string $user): array {
    [$linhas, $erro] = rtr_importar_ler($d, $texto); if ($erro !== '') prt_fail($erro);
    $n = 0;
    foreach ($linhas as $l) if ($l['estado'] === 'novo') {
        $d['seq'] = (int)$d['seq'] + 1;
        $d['rastreios'][] = ['id' => sprintf('RAS-%04d', $d['seq'])] + $l['reg'] + ['criado_em' => date('c'), 'criado_por' => $user, 'origem' => 'importado'];
        $n++;
    }
    return ['importados' => $n, 'duplicados' => count(array_filter($linhas, fn($l) => $l['estado'] === 'duplicado')), 'erros' => count(array_filter($linhas, fn($l) => $l['estado'] === 'erro'))];
}

function rtr_csv(array $d): string {
    $p = rtr_painel($d); $o = fopen('php://temp', 'w+');
    fputcsv($o, ['ID', 'Fornecedor', 'Nº pedido', 'Produto', 'Transportadora', 'Código de rastreio', 'Link', 'Data envio', 'Previsão de entrega', 'Data entrega', 'Dias', 'Dias de atraso', 'Situação', 'Recebedor', 'Romaneio', 'Observação'], ';');
    foreach ($p['rastreios'] as $r) fputcsv($o, [$r['id'], $r['fornecedor'], $r['pedido'], $r['produto'], $r['transportadora'], $r['codigo'], $r['link_final'],
        $r['data_envio'] ? date('d/m/Y', strtotime($r['data_envio'])) : '', $r['data_previsao'] ? date('d/m/Y', strtotime($r['data_previsao'])) : '', $r['data_entrega'] ? date('d/m/Y', strtotime($r['data_entrega'])) : '', $r['dias'] ?? '', $r['dias_atraso'] ?: '',
        $r['situacao'] === 'ENTREGUE' ? 'Entregue' : ($r['atrasado'] ? 'Em trânsito (atrasado)' : 'Em trânsito'), $r['recebedor'], $r['romaneio'], $r['obs']], ';');
    rewind($o); return "\xEF\xBB\xBF" . stream_get_contents($o);
}
