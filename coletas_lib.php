<?php
/**
 * Recebimento Drilling — Módulo de Coletas (v3.5.0)
 * ==================================================
 * Módulo INDEPENDENTE de controle de coletas: agenda, mapa, rota e retorno.
 * Não conversa com pedidos, estoque, agenda ou notificações — só lê a base de
 * pedidos para importar fornecedor/endereço quando o operador pedir.
 *
 * Origem das regras: projeto Almoxarifado Drilling r16 (_rotas.zip), portado de
 * MySQL/React para o padrão JSON standalone deste sistema.
 *
 * Estrutura de data/coletas.json:
 * {
 *   "versao": 1,
 *   "sequencia": { "coleta": 0 },
 *   "coletas": {
 *      "COL-2026-0001": {
 *         id, pc, numero_pc, fornecedor, cnpj, contato,
 *         endereco, numero, complemento, bairro, cidade, uf, cep,
 *         lat, lng, geo_status, geo_mensagem, geo_precisao, geo_tentativas,
 *         endereco_hash, geo_confirmado, geo_origem,
 *         status (AGUARDANDO|ROTA|ESTOQUE|FABRICACAO|COLETADO|TRANSPORTE|CHEGOU),
 *         agendada_data, agendada_hora, prioridade,
 *         observacao, criado_em, atualizado_em, criado_por,
 *         historico[], retornos[]
 *      }
 *   },
 *   "geocache": { "<hash do endereço>": {lat,lng,precisao,origem,em} },
 *   "config": { "origem": {lat,lng,nome}, "tiles": "voyager", "osrm": true }
 * }
 *
 * Regras de ouro:
 *  - Cada coleta tem endereço próprio; nada é deduzido de outro módulo.
 *  - Geocodificação só roda quando o endereço muda (hash) ou por pedido explícito.
 *  - Falha de geocode NUNCA é tratada como "endereço inexistente": há classificação.
 *  - Rota é sugestão (vizinho mais próximo + OSRM); a ordem pode ser ajustada.
 *  - Todo status/retorno fica no histórico da coleta.
 */

if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const COL_FILE  = DATA_DIR . '/coletas.json';
const COL_LOCK  = DATA_DIR . '/.coletas.lock';

/** Depósito de origem (mesmo ponto usado no projeto antigo). */
const COL_ORIGEM_PADRAO = ['lat' => -19.6986, 'lng' => -43.9586, 'nome' => 'Drilling do Brasil — Depósito (São José da Lapa)'];

const COL_STATUS = [
    'AGUARDANDO' => 'Aguardando retirada',
    'ROTA'       => 'Rota de coleta',
    'ESTOQUE'    => 'Estoque',
    'FABRICACAO' => 'Fabricação',
    'COLETADO'   => 'Coletado',
    'TRANSPORTE' => 'Em transporte',
    'CHEGOU'     => 'Chegou ao almoxarifado',
];

/** v3.9: motivo do atraso/não realização (alimenta o gráfico "Motivos de atraso" dos Indicadores). */
const COL_MOTIVOS_ATRASO = [
    'FORNECEDOR'    => 'Fornecedor (material não liberado/pronto)',
    'TRANSPORTADORA'=> 'Transportadora / veículo',
    'PLANEJAMENTO'  => 'Planejamento / rota',
    'DOCUMENTACAO'  => 'Documentação fiscal',
    'OBRA'          => 'Obra / solicitante',
    'OUTRO'         => 'Outro',
];

const COL_PRIORIDADES = ['BAIXA' => 'Baixa', 'NORMAL' => 'Normal', 'ALTA' => 'Alta', 'URGENTE' => 'Urgente'];

const COL_RETORNO_MOTIVOS = [
    'AUSENTE'        => 'Fornecedor ausente',
    'SEM_MATERIAL'   => 'Material não estava pronto',
    'ENDERECO'       => 'Endereço não localizado',
    'FECHADO'        => 'Estabelecimento fechado',
    'RECUSADO'       => 'Coleta recusada pelo fornecedor',
    'OUTRO'          => 'Outro motivo',
];

const COL_MAX = 2000;

/* ------------------------------------------------------------------ */
/* Base                                                               */
/* ------------------------------------------------------------------ */

function col_empty() {
    return [
        'versao' => 1,
        'sequencia' => ['coleta' => 0],
        'coletas' => [],
        'geocache' => [],
        'config' => ['origem' => COL_ORIGEM_PADRAO, 'tiles' => 'osm', 'osrm' => true],
    ];
}

function col_read() {
    if (!is_file(COL_FILE)) return col_empty();
    $raw = @file_get_contents(COL_FILE);
    if ($raw === false || $raw === '') return col_empty();
    $d = json_decode($raw, true);
    if (!is_array($d)) return col_empty();
    return array_merge(col_empty(), $d);
}

function col_write(array $db) {
    $tmp = COL_FILE . '.tmp';
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        respond(['ok' => false, 'error' => 'Não foi possível gravar as coletas'], 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, COL_FILE)) respond(['ok' => false, 'error' => 'Falha ao persistir as coletas'], 500);
}

function col_with_lock(callable $fn) {
    $fp = @fopen(COL_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) respond(['ok' => false, 'error' => 'Trava de coletas indisponível'], 503);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function col_try_lock(callable $fn) {
    $fp = @fopen(COL_LOCK, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) { if ($fp) fclose($fp); throw new RuntimeException('Trava de coletas indisponível'); }
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function col_now() { return date('c'); }
function col_str($v, $max = 200) { return mb_substr(trim((string)$v), 0, $max); }

/* ------------------------------------------------------------------ */
/* Endereço: normalização e hash                                      */
/* ------------------------------------------------------------------ */

/** Normaliza o endereço para comparação e cache (remove acento, caixa e pontuação). */
function col_norm_addr(array $c) {
    $partes = [
        $c['endereco'] ?? '', $c['numero'] ?? '', $c['bairro'] ?? '',
        $c['cidade'] ?? '', $c['uf'] ?? '', $c['cep'] ?? '',
    ];
    $s = implode(' ', $partes);
    $s = mb_strtolower($s, 'UTF-8');
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($conv !== false) $s = $conv;
    }
    $s = preg_replace('/[^a-z0-9]+/', ' ', (string)$s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function col_addr_hash(array $c) {
    return sha1(col_norm_addr($c));
}

/** Monta a consulta enviada ao provedor de geocodificação. */
function col_addr_query(array $c) {
    $linha1 = trim(implode(', ', array_filter([
        trim(($c['endereco'] ?? '') . (($c['numero'] ?? '') !== '' ? ', ' . $c['numero'] : '')),
        $c['bairro'] ?? '',
    ])));
    $linha2 = trim(implode(' - ', array_filter([$c['cidade'] ?? '', $c['uf'] ?? ''])));
    $cep = preg_replace('/\D/', '', (string)($c['cep'] ?? ''));
    $partes = array_filter([$linha1, $linha2, $cep, 'Brasil']);
    return implode(', ', $partes);
}

/* ------------------------------------------------------------------ */
/* Geocodificação (Photon → Nominatim), com cache                     */
/* ------------------------------------------------------------------ */

/**
 * Bundle de CAs: em hospedagem normalmente já existe; em PHP de desktop pode faltar.
 * Procura em data/cacert.pem, no bundle do sistema e nas variáveis de ambiente.
 */
function col_ca_bundle() {
    static $bundle = null;
    if ($bundle !== null) return $bundle;
    $candidatos = [
        DATA_DIR . '/cacert.pem',
        (string)ini_get('curl.cainfo'),
        (string)ini_get('openssl.cafile'),
        '/etc/ssl/certs/ca-certificates.crt',
        '/usr/local/etc/openssl/cert.pem',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
    ];
    foreach ($candidatos as $c) {
        if ($c !== '' && is_file($c)) { $bundle = $c; return $bundle; }
    }
    $bundle = '';
    return $bundle;
}

function col_http_get($url, $timeout = 10) {
    $ua = 'RecebimentoDrilling-Coletas/1.0 (+https://recebimento.autolimpezapro.com.br)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
        ];
        $ca = col_ca_bundle();
        if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = (int)curl_errno($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'status' => $errno === 28 ? 'timeout' : 'temporario', 'mensagem' => 'Sem resposta do serviço de localização.', 'http' => $code];
        return ['ok' => true, 'corpo' => (string)$out, 'http' => $code];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $ua, 'header' => "Accept: application/json\r\n"]]);
    $out = @file_get_contents($url, false, $ctx);
    if (!is_string($out)) return ['ok' => false, 'status' => 'temporario', 'mensagem' => 'Sem resposta do serviço de localização.'];
    return ['ok' => true, 'corpo' => $out, 'http' => 200];
}

/** Classifica a resposta HTTP — distingue falha técnica de endereço inexistente. */
function col_classificar_http($code) {
    if ($code === 429) return ['status' => 'limite', 'mensagem' => 'Limite do provedor de localização. Aguarde e tente de novo.'];
    if ($code === 0 || $code >= 500) return ['status' => 'temporario', 'mensagem' => 'Serviço de localização indisponível no momento.'];
    if ($code === 400) return ['status' => 'consulta_rejeitada', 'mensagem' => 'O provedor recusou a consulta (HTTP 400). Revise o endereço e tente de novo.'];
    if ($code === 401 || $code === 403) return ['status' => 'temporario', 'mensagem' => 'O provedor recusou o acesso (HTTP ' . $code . ').'];
    if ($code >= 400) return ['status' => 'temporario', 'mensagem' => 'Falha técnica no provedor (HTTP ' . $code . ').'];
    return null;
}

/** Geocodifica um endereço. Retorna lat/lng ou erro classificado. */
function col_geocode(array $c, $provedor = 'photon') {
    $q = col_addr_query($c);
    if (mb_strlen($q) < 8) return ['ok' => false, 'status' => 'endereco_incompleto', 'mensagem' => 'Endereço insuficiente para localizar.'];
    $url = $provedor === 'nominatim'
        ? 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=br&q=' . urlencode($q)
        : 'https://photon.komoot.io/api/?limit=1&q=' . urlencode($q);
    $res = col_http_get($url, 10);
    if (!$res['ok']) return $res;
    $cls = col_classificar_http((int)($res['http'] ?? 200));
    if ($cls !== null) return ['ok' => false] + $cls;
    $j = json_decode((string)$res['corpo'], true);
    if (!is_array($j)) return ['ok' => false, 'status' => 'temporario', 'mensagem' => 'Resposta inválida do provedor.'];

    $lat = $lng = null; $precisao = 'aprox';
    if ($provedor === 'nominatim') {
        if (!empty($j[0]['lat']) && !empty($j[0]['lon'])) {
            $lat = (float)$j[0]['lat'];
            $lng = (float)$j[0]['lon'];
            $precisao = ($j[0]['type'] ?? '') === 'house' ? 'casa' : 'rua';
        }
    } else {
        $f = $j['features'][0] ?? null;
        if ($f && isset($f['geometry']['coordinates'])) {
            $lng = (float)$f['geometry']['coordinates'][0];
            $lat = (float)$f['geometry']['coordinates'][1];
            $tipo = (string)($f['properties']['type'] ?? '');
            $precisao = in_array($tipo, ['house', 'street'], true) ? ($tipo === 'house' ? 'casa' : 'rua') : 'aprox';
        }
    }
    if ($lat === null || $lng === null) {
        return ['ok' => false, 'status' => 'nao_encontrado', 'mensagem' => 'Endereço não localizado. Confira número, bairro e cidade.'];
    }
    if ($lat < -35 || $lat > 6 || $lng < -75 || $lng > -30) {
        return ['ok' => false, 'status' => 'fora_do_brasil', 'mensagem' => 'O resultado ficou fora do Brasil; revise o endereço.'];
    }
    return ['ok' => true, 'lat' => round($lat, 7), 'lng' => round($lng, 7), 'precisao' => $precisao, 'origem' => $provedor, 'consulta' => $q];
}

/**
 * Garante as coordenadas de uma coleta, usando cache por hash de endereço.
 * $forcar ignora o cache (útil quando o operador corrige o endereço).
 */
function col_geocodificar(array &$db, array &$c, $forcar = false, $provedor = 'photon') {
    $hash = col_addr_hash($c);
    $c['endereco_hash'] = $hash;
    if (!$forcar && isset($db['geocache'][$hash])) {
        $hit = $db['geocache'][$hash];
        $c['lat'] = $hit['lat']; $c['lng'] = $hit['lng'];
        $c['geo_precisao'] = $hit['precisao'] ?? 'aprox';
        $c['geo_origem'] = ($hit['origem'] ?? 'cache') . ' (cache)';
        $c['geo_status'] = 'ok';
        $c['geo_mensagem'] = '';
        return ['ok' => true, 'cache' => true];
    }
    if (!empty($c['geo_confirmado']) && !$forcar && $c['lat'] !== null) {
        return ['ok' => true, 'confirmado' => true];
    }
    $c['geo_tentativas'] = (int)($c['geo_tentativas'] ?? 0) + 1;
    $r = col_geocode($c, $provedor);
    if ($r['ok']) {
        $c['lat'] = $r['lat']; $c['lng'] = $r['lng'];
        $c['geo_status'] = 'ok';
        $c['geo_mensagem'] = '';
        $c['geo_precisao'] = $r['precisao'];
        $c['geo_origem'] = $r['origem'];
        $db['geocache'][$hash] = ['lat' => $r['lat'], 'lng' => $r['lng'], 'precisao' => $r['precisao'], 'origem' => $r['origem'], 'em' => col_now()];
        if (count($db['geocache']) > 3000) $db['geocache'] = array_slice($db['geocache'], -3000, null, true);
        return ['ok' => true, 'cache' => false];
    }
    $c['geo_status'] = $r['status'];
    $c['geo_mensagem'] = $r['mensagem'] ?? 'Falha ao localizar o endereço.';
    return ['ok' => false] + $r;
}

/* ------------------------------------------------------------------ */
/* Coletas: gravação                                                  */
/* ------------------------------------------------------------------ */

function col_sanitize_id($id) {
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id);
    return mb_substr((string)$id, 0, 40);
}

function col_salvar(array $input, $usuario) {
    $pc = (int)($input['pc'] ?? 0);
    $fornecedor = col_str($input['fornecedor'] ?? '', 200);
    if ($pc <= 0 && $fornecedor === '') respond(['ok' => false, 'error' => 'Informe o PC ou o fornecedor da coleta'], 422);

    return col_with_lock(function () use ($input, $usuario, $pc, $fornecedor) {
        $db = col_read();
        $id = col_sanitize_id($input['id'] ?? '');
        $novo = $id === '' || !isset($db['coletas'][$id]);
        $now = col_now();
        if ($novo) {
            $db['sequencia']['coleta'] = (int)($db['sequencia']['coleta'] ?? 0) + 1;
            $id = 'COL-' . date('Y') . '-' . str_pad((string)$db['sequencia']['coleta'], 4, '0', STR_PAD_LEFT);
            $c = [
                'id' => $id, 'criado_em' => $now, 'criado_por' => $usuario,
                'status' => 'AGUARDANDO', 'historico' => [], 'retornos' => [],
                'geo_tentativas' => 0, 'geo_confirmado' => false,
                'lat' => null, 'lng' => null, 'geo_status' => '', 'geo_mensagem' => '',
            ];
        } else {
            $c = $db['coletas'][$id];
        }
        $enderecoAntes = col_addr_hash($c);

        $c['pc'] = $pc ?: ($c['pc'] ?? 0);
        $c['numero_pc'] = col_str($input['numero_pc'] ?? ($c['numero_pc'] ?? ''), 40);
        $c['fornecedor'] = $fornecedor !== '' ? $fornecedor : ($c['fornecedor'] ?? '');
        $c['cnpj'] = preg_replace('/\D/', '', (string)($input['cnpj'] ?? ($c['cnpj'] ?? '')));
        $c['contato'] = col_str($input['contato'] ?? ($c['contato'] ?? ''), 120);
        foreach (['endereco' => 240, 'numero' => 20, 'complemento' => 80, 'bairro' => 80, 'cidade' => 80] as $campo => $max) {
            if (array_key_exists($campo, $input)) $c[$campo] = col_str($input[$campo], $max);
        }
        if (array_key_exists('uf', $input)) $c['uf'] = strtoupper(col_str($input['uf'], 2));
        if (array_key_exists('cep', $input)) $c['cep'] = preg_replace('/\D/', '', (string)$input['cep']);
        if (array_key_exists('agendada_data', $input)) {
            $d = (string)$input['agendada_data'];
            $c['agendada_data'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
        }
        if (array_key_exists('agendada_hora', $input)) {
            $h = (string)$input['agendada_hora'];
            $c['agendada_hora'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : '';
        }
        $prio = strtoupper((string)($input['prioridade'] ?? ($c['prioridade'] ?? 'NORMAL')));
        $c['prioridade'] = isset(COL_PRIORIDADES[$prio]) ? $prio : 'NORMAL';
        if (array_key_exists('observacao', $input)) $c['observacao'] = col_str($input['observacao'], 600);
        if (array_key_exists('motivo_atraso', $input)) {
            $m = strtoupper((string)$input['motivo_atraso']);
            $novo = isset(COL_MOTIVOS_ATRASO[$m]) ? $m : '';
            if ($novo !== ($c['motivo_atraso'] ?? '') && $novo !== '') {
                $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'motivo do atraso', 'detalhe' => COL_MOTIVOS_ATRASO[$novo]];
            }
            $c['motivo_atraso'] = $novo;
        }
        if (array_key_exists('ordem', $input)) $c['ordem'] = (int)$input['ordem'];
        if (!empty($input['geo_confirmado'])) $c['geo_confirmado'] = true;
        if (isset($input['lat'], $input['lng']) && $input['lat'] !== '' && $input['lng'] !== '') {
            $c['lat'] = (float)$input['lat'];
            $c['lng'] = (float)$input['lng'];
            $c['geo_status'] = 'ok';
            $c['geo_mensagem'] = '';
            $c['geo_origem'] = 'manual';
            $c['geo_confirmado'] = true;
        }
        $c['atualizado_em'] = $now;
        $c['atualizado_por'] = $usuario;

        if ($novo) {
            $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'criada', 'detalhe' => $c['fornecedor']];
        } else {
            $novoHash = col_addr_hash($c);
            if ($enderecoAntes !== '' && $enderecoAntes !== $novoHash) {
                $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'endereco alterado'];
                // Endereço mudou: coordenadas antigas deixam de valer.
                $c['lat'] = null; $c['lng'] = null;
                $c['geo_status'] = '';
                $c['geo_confirmado'] = false;
            }
        }
        $db['coletas'][$id] = $c;
        if (count($db['coletas']) > COL_MAX) {
            // Mantém as mais recentes por atualização.
            uasort($db['coletas'], fn($a, $b) => strcmp((string)($b['atualizado_em'] ?? ''), (string)($a['atualizado_em'] ?? '')));
            $db['coletas'] = array_slice($db['coletas'], 0, COL_MAX, true);
        }
        col_write($db);
        return ['coleta' => col_view($c), 'novo' => $novo];
    });
}

function col_mudar_status($id, $status, $usuario, $detalhe = '', $km = null) {
    $id = col_sanitize_id($id);
    $status = strtoupper((string)$status);
    if (!isset(COL_STATUS[$status])) respond(['ok' => false, 'error' => 'Status inválido'], 422);
    return col_with_lock(function () use ($id, $status, $usuario, $detalhe, $km) {
        $db = col_read();
        if (!isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Coleta não encontrada'], 404);
        $c = $db['coletas'][$id];
        $antes = $c['status'] ?? 'AGUARDANDO';
        // v3.17.0: km real rodado (informado pelo motorista); vale para o relatório de coletas por fornecedor
        if ($km !== null && $km !== '') {
            $kmv = round((float)str_replace(',', '.', (string)$km), 1);
            if ($kmv < 0 || $kmv > 5000) respond(['ok' => false, 'error' => 'Km rodado inválido.'], 422);
            if (($c['km_real'] ?? null) !== $kmv) { $c['km_real'] = $kmv; $c['historico'][] = ['em' => col_now(), 'por' => $usuario, 'acao' => 'km', 'detalhe' => 'Km rodado: ' . $kmv];
                if ($antes === $status) { $c['atualizado_em'] = col_now(); $db['coletas'][$id] = $c; col_write($db); return ['coleta' => col_view($c), 'alterado' => true]; } }
        }
        if ($antes === $status) return ['coleta' => col_view($c), 'alterado' => false];
        $now = col_now();
        $c['status'] = $status;
        $c['atualizado_em'] = $now;
        $c['atualizado_por'] = $usuario;
        $c['historico'][] = [
            'em' => $now, 'por' => $usuario, 'acao' => 'status',
            'de' => COL_STATUS[$antes] ?? $antes, 'para' => COL_STATUS[$status] ?? $status,
            'detalhe' => col_str($detalhe, 300),
        ];
        if ($status === 'ROTA' && empty($c['coleta_inicio'])) $c['coleta_inicio'] = $now;
        if ($status === 'CHEGOU') $c['chegou_em'] = $now;
        if (in_array($status, ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true) && empty($c['coletado_em'])) $c['coletado_em'] = $now;
        $db['coletas'][$id] = $c;
        col_write($db);
        return ['coleta' => col_view($c), 'alterado' => true];
    });
}

/** Registra um retorno de coleta (o motorista foi e não conseguiu coletar). */
function col_registrar_retorno($id, array $input, $usuario) {
    $id = col_sanitize_id($id);
    $motivo = strtoupper((string)($input['motivo'] ?? ''));
    if (!isset(COL_RETORNO_MOTIVOS[$motivo])) respond(['ok' => false, 'error' => 'Motivo do retorno inválido'], 422);
    $obs = col_str($input['observacao'] ?? '', 500);
    if ($motivo === 'OUTRO' && mb_strlen($obs) < 4) respond(['ok' => false, 'error' => 'Descreva o motivo do retorno'], 422);
    $reagendar = (string)($input['reagendar_data'] ?? '');
    if ($reagendar !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reagendar)) $reagendar = '';

    return col_with_lock(function () use ($id, $motivo, $obs, $reagendar, $usuario, $input) {
        $db = col_read();
        if (!isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Coleta não encontrada'], 404);
        $c = $db['coletas'][$id];
        $now = col_now();
        $registro = [
            'em' => $now, 'por' => $usuario, 'motivo' => $motivo,
            'motivo_label' => COL_RETORNO_MOTIVOS[$motivo], 'observacao' => $obs,
            'reagendada_para' => $reagendar,
            'status_anterior' => $c['status'] ?? 'AGUARDANDO',
        ];
        $c['retornos'][] = $registro;
        $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'retorno', 'detalhe' => COL_RETORNO_MOTIVOS[$motivo] . ($obs ? ' — ' . $obs : '')];
        // Volta para a fila; se reagendou, vai para a nova data.
        $c['status'] = 'AGUARDANDO';
        if ($reagendar !== '') {
            $c['agendada_data'] = $reagendar;
            if (!empty($input['reagendar_hora']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$input['reagendar_hora'])) {
                $c['agendada_hora'] = (string)$input['reagendar_hora'];
            }
            $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'reagendada', 'detalhe' => $reagendar];
        }
        $c['atualizado_em'] = $now;
        $c['atualizado_por'] = $usuario;
        $db['coletas'][$id] = $c;
        col_write($db);
        return ['coleta' => col_view($c), 'retorno' => $registro];
    });
}

function col_excluir($id, $usuario) {
    $id = col_sanitize_id($id);
    return col_with_lock(function () use ($id, $usuario) {
        $db = col_read();
        if (!isset($db['coletas'][$id])) respond(['ok' => false, 'error' => 'Coleta não encontrada'], 404);
        $c = $db['coletas'][$id];
        if (!empty($c['historico'])) {
            // Nunca apaga de verdade: marca como cancelada e preserva o histórico.
            $c['status'] = 'CANCELADA';
            $c['cancelada_em'] = col_now();
            $c['atualizado_em'] = col_now();
            $c['atualizado_por'] = $usuario;
            $c['historico'][] = ['em' => col_now(), 'por' => $usuario, 'acao' => 'cancelada'];
            $db['coletas'][$id] = $c;
        } else {
            unset($db['coletas'][$id]);
        }
        col_write($db);
        return ['id' => $id];
    });
}

/* ------------------------------------------------------------------ */
/* Consultas e rota                                                   */
/* ------------------------------------------------------------------ */

function col_distancia_km($a, $b) {
    if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) return null;
    $dLat = deg2rad((float)$b['lat'] - (float)$a['lat']);
    $dLng = deg2rad((float)$b['lng'] - (float)$a['lng']);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad((float)$a['lat'])) * cos(deg2rad((float)$b['lat'])) * sin($dLng / 2) ** 2;
    return round(12742 * asin(sqrt($h)), 2);
}

function col_view(array $c) {
    $c['status_label'] = COL_STATUS[$c['status'] ?? 'AGUARDANDO'] ?? ($c['status'] ?? '');
    $c['prioridade_label'] = COL_PRIORIDADES[$c['prioridade'] ?? 'NORMAL'] ?? 'Normal';
    $c['motivo_atraso_label'] = COL_MOTIVOS_ATRASO[$c['motivo_atraso'] ?? ''] ?? '';
    $c['tem_coordenadas'] = isset($c['lat'], $c['lng']) && $c['lat'] !== null && $c['lng'] !== null;
    $c['endereco_completo'] = trim(implode(', ', array_filter([
        trim(($c['endereco'] ?? '') . (($c['numero'] ?? '') !== '' ? ', ' . $c['numero'] : '')),
        $c['complemento'] ?? '', $c['bairro'] ?? '',
        trim(implode('/', array_filter([$c['cidade'] ?? '', $c['uf'] ?? '']))),
    ])));
    $hoje = date('Y-m-d');
    $c['atrasada'] = !empty($c['agendada_data']) && $c['agendada_data'] < $hoje
        && !in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU', 'CANCELADA'], true);
    $c['hoje'] = !empty($c['agendada_data']) && $c['agendada_data'] === $hoje;
    $c['retornos_count'] = count($c['retornos'] ?? []);
    $c['retornos'] = array_slice($c['retornos'] ?? [], -20);
    $c['historico'] = array_slice($c['historico'] ?? [], -40);
    return $c;
}

function col_listar(array $q) {
    $db = col_read();
    $status = strtoupper((string)($q['status'] ?? ''));
    $dia = (string)($q['dia'] ?? '');
    $busca = mb_strtolower(trim((string)($q['q'] ?? '')), 'UTF-8');
    $incluirCanceladas = !empty($q['incluir_canceladas']);
    $out = [];
    foreach ($db['coletas'] as $c) {
        if (($c['status'] ?? '') === 'CANCELADA' && !$incluirCanceladas) continue;
        if ($status !== '') {
            if ($status === 'ABERTAS' && in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU', 'CANCELADA'], true)) continue;
            if ($status === 'ATRASADAS') { $v = col_view($c); if (!$v['atrasada']) continue; }
            if ($status === 'SEM_LOCAL') { if (isset($c['lat']) && $c['lat'] !== null) continue; }
            if (!in_array($status, ['ABERTAS', 'ATRASADAS', 'SEM_LOCAL'], true) && ($c['status'] ?? '') !== $status) continue;
        }
        if ($dia !== '' && ($c['agendada_data'] ?? '') !== $dia) continue;
        if ($busca !== '') {
            $hay = mb_strtolower(implode(' ', [(string)($c['pc'] ?? ''), (string)($c['fornecedor'] ?? ''), (string)($c['endereco'] ?? ''), (string)($c['bairro'] ?? ''), (string)($c['cidade'] ?? ''), (string)($c['id'] ?? '')]), 'UTF-8');
            if (mb_strpos($hay, $busca) === false) continue;
        }
        $out[] = col_view($c);
    }
    usort($out, function ($a, $b) {
        $pa = $a['atrasada'] ? 0 : (($a['prioridade'] ?? 'NORMAL') === 'URGENTE' ? 1 : (($a['prioridade'] ?? '') === 'ALTA' ? 2 : 3));
        $pb = $b['atrasada'] ? 0 : (($b['prioridade'] ?? 'NORMAL') === 'URGENTE' ? 1 : (($b['prioridade'] ?? '') === 'ALTA' ? 2 : 3));
        if ($pa !== $pb) return $pa <=> $pb;
        $da = $a['agendada_data'] ?: '9999-12-31';
        $dbb = $b['agendada_data'] ?: '9999-12-31';
        if ($da !== $dbb) return strcmp($da, $dbb);
        return strcmp((string)($a['fornecedor'] ?? ''), (string)($b['fornecedor'] ?? ''));
    });
    return ['coletas' => $out, 'total' => count($out), 'hoje' => date('Y-m-d')];
}

function col_dashboard() {
    $db = col_read();
    $hoje = date('Y-m-d');
    $k = ['total' => 0, 'aguardando' => 0, 'rota' => 0, 'coletado' => 0, 'chegou' => 0, 'atrasadas' => 0, 'hoje' => 0, 'sem_local' => 0];
    foreach ($db['coletas'] as $c) {
        if (($c['status'] ?? '') === 'CANCELADA') continue;
        $k['total']++;
        $st = strtolower((string)($c['status'] ?? 'aguardando'));
        if (isset($k[$st])) $k[$st]++;
        $v = col_view($c);
        if ($v['atrasada']) $k['atrasadas']++;
        if ($v['hoje']) $k['hoje']++;
        if (!$v['tem_coordenadas']) $k['sem_local']++;
    }
    return ['kpis' => $k, 'hoje' => $hoje, 'origem' => $db['config']['origem'] ?? COL_ORIGEM_PADRAO];
}

/**
 * Monta a rota: vizinho mais próximo a partir do depósito (mesma lógica do
 * projeto antigo), respeitando prioridade URGENTE/ALTA na frente.
 */
function col_rota(array $q) {
    $db = col_read();
    $dia = (string)($q['dia'] ?? '');
    $origem = $db['config']['origem'] ?? COL_ORIGEM_PADRAO;
    $paradas = [];
    foreach ($db['coletas'] as $c) {
        if (in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU', 'CANCELADA'], true)) continue;
        if ($dia !== '' && ($c['agendada_data'] ?? '') !== $dia) continue;
        $paradas[] = $c;
    }
    $comCoord = array_values(array_filter($paradas, fn($c) => isset($c['lat'], $c['lng']) && $c['lat'] !== null));
    $semLocal = array_values(array_filter($paradas, fn($c) => !isset($c['lat']) || $c['lat'] === null));

    // Ordena por prioridade antes de aplicar o vizinho mais próximo.
    $peso = ['URGENTE' => 0, 'ALTA' => 1, 'NORMAL' => 2, 'BAIXA' => 3];
    usort($comCoord, fn($a, $b) => ($peso[$a['prioridade'] ?? 'NORMAL'] ?? 2) <=> ($peso[$b['prioridade'] ?? 'NORMAL'] ?? 2));

    $restantes = $comCoord;
    $ordenadas = [];
    $atual = $origem;
    $km = 0.0;
    while ($restantes) {
        $melhor = 0; $melhorDist = INF;
        foreach ($restantes as $i => $c) {
            $d = col_distancia_km($atual, $c);
            if ($d === null) continue;
            // Urgente ganha até 30 km de vantagem na escolha.
            $bias = ($c['prioridade'] ?? 'NORMAL') === 'URGENTE' ? -30 : (($c['prioridade'] ?? '') === 'ALTA' ? -10 : 0);
            if ($d + $bias < $melhorDist) { $melhorDist = $d + $bias; $melhor = $i; }
        }
        $escolhida = $restantes[$melhor];
        $dReal = col_distancia_km($atual, $escolhida) ?? 0;
        $km += $dReal;
        $escolhida['distancia_anterior_km'] = $dReal;
        $escolhida['ordem'] = count($ordenadas) + 1;
        $ordenadas[] = col_view($escolhida);
        $atual = $escolhida;
        array_splice($restantes, $melhor, 1);
    }
    $volta = $ordenadas ? col_distancia_km(end($ordenadas), $origem) : null;
    if ($volta !== null) $km += $volta;

    return [
        'paradas' => $ordenadas,
        'sem_local' => array_map('col_view', $semLocal),
        'total' => count($paradas),
        'origem' => $origem,
        'km_estimado' => round($km, 1),
        'min_estimado' => (int)round(($km / 45) * 60),
        'dia' => $dia,
        'osrm' => !empty($db['config']['osrm']),
    ];
}

/** Endereços para o mapa (todos com coordenadas, sem filtro de status). */
/**
 * Quando o provedor de tiles pede chave de API (HTTP 403 "API KEY REQUIRED"),
 * cai automaticamente para o OpenStreetMap padrão, que é livre.
 */
function col_tiles_efetivo(array $db) {
    $pref = $db['config']['tiles'] ?? 'voyager';
    if (!empty($db['config']['tiles_fallback'])) return 'osm';
    return $pref;
}

function col_mapa() {
    $db = col_read();
    $pontos = [];
    foreach ($db['coletas'] as $c) {
        if (($c['status'] ?? '') === 'CANCELADA') continue;
        $v = col_view($c);
        if (!$v['tem_coordenadas']) continue;
        $pontos[] = [
            'id' => $v['id'], 'pc' => $v['pc'] ?? 0, 'fornecedor' => $v['fornecedor'] ?? '',
            'lat' => $v['lat'], 'lng' => $v['lng'], 'status' => $v['status'],
            'status_label' => $v['status_label'], 'endereco' => $v['endereco_completo'],
            'agendada_data' => $v['agendada_data'] ?? '', 'prioridade' => $v['prioridade'] ?? 'NORMAL',
            'atrasada' => $v['atrasada'], 'precisao' => $v['geo_precisao'] ?? '',
        ];
    }
    return ['pontos' => $pontos, 'origem' => $db['config']['origem'] ?? COL_ORIGEM_PADRAO, 'total' => count($pontos)];
}

function col_agenda(array $q) {
    $db = col_read();
    $ini = (string)($q['de'] ?? date('Y-m-d'));
    $fim = (string)($q['ate'] ?? date('Y-m-d', strtotime('+30 days')));
    $dias = [];
    foreach ($db['coletas'] as $c) {
        if (($c['status'] ?? '') === 'CANCELADA') continue;
        $d = $c['agendada_data'] ?? '';
        if ($d === '' || $d < $ini || $d > $fim) continue;
        $dias[$d] = $dias[$d] ?? ['data' => $d, 'total' => 0, 'itens' => []];
        $dias[$d]['total']++;
        $v = col_view($c);
        $dias[$d]['itens'][] = ['id' => $v['id'], 'pc' => $v['pc'], 'fornecedor' => $v['fornecedor'], 'hora' => $v['agendada_hora'] ?? '', 'status' => $v['status'], 'atrasada' => $v['atrasada']];
    }
    ksort($dias);
    return ['dias' => array_values($dias), 'de' => $ini, 'ate' => $fim];
}

/** Importa fornecedor e endereço de um PC da base de pedidos (somente leitura). */
function col_importar_pedido($pc) {
    if (!function_exists('read_items')) respond(['ok' => false, 'error' => 'Base de pedidos indisponível'], 500);
    $pc = (int)$pc;
    $pedido = null;
    foreach (read_items() as $p) {
        if ((int)$p['pc'] === $pc) { $pedido = $p; break; }
    }
    if (!$pedido) respond(['ok' => false, 'error' => 'PC ' . $pc . ' não encontrado na base de pedidos'], 404);
    return [
        'pc' => $pc,
        'numero_pc' => (string)$pc,
        'fornecedor' => (string)($pedido['fornecedor'] ?? ''),
        'cnpj' => (string)($pedido['cnpjEmitente'] ?? ''),
        'observacao' => (string)($pedido['material'] ?? ''),
    ];
}

/* ------------------------------------------------------------------ */
/* v3.23.0 — Rota montada à mão, rota ativa e modo motorista          */
/* ------------------------------------------------------------------ */

/** Ordena as coletas escolhidas (vizinho mais próximo a partir do depósito) ou mantém a ordem dada. */
function col_ordenar_paradas(array $db, array $ids, bool $otimizar = true) {
    $origem = $db['config']['origem'] ?? COL_ORIGEM_PADRAO;
    $sel = [];
    foreach ($ids as $id) { $id = col_sanitize_id($id); if (isset($db['coletas'][$id]) && !isset($sel[$id])) $sel[$id] = $db['coletas'][$id]; }
    $com = array_values(array_filter($sel, fn($c) => isset($c['lat'], $c['lng']) && $c['lat'] !== null));
    $sem = array_values(array_filter($sel, fn($c) => !isset($c['lat']) || $c['lat'] === null));
    if ($otimizar) {
        $rest = $com; $ord = []; $at = $origem;
        while ($rest) {
            $m = 0; $md = INF;
            foreach ($rest as $i => $c) { $d = col_distancia_km($at, $c) ?? INF; $b = ($c['prioridade'] ?? '') === 'URGENTE' ? -30 : (($c['prioridade'] ?? '') === 'ALTA' ? -10 : 0); if ($d + $b < $md) { $md = $d + $b; $m = $i; } }
            $ord[] = $rest[$m]; $at = $rest[$m]; array_splice($rest, $m, 1);
        }
        $com = $ord;
    }
    $km = 0.0; $at = $origem; $out = [];
    foreach ($com as $c) { $d = col_distancia_km($at, $c) ?? 0; $km += $d; $v = col_view($c); $v['distancia_anterior_km'] = round($d * 1.3, 1); $v['ordem'] = count($out) + 1; $out[] = $v; $at = $c; }
    if ($com) $km += col_distancia_km($at, $origem) ?? 0;
    $km *= 1.3;   // fator de estrada (mesmo dos indicadores)
    return ['paradas' => $out, 'sem_local' => array_map('col_view', $sem), 'origem' => $origem,
        'km_estimado' => round($km, 1), 'min_estimado' => (int)round($km / 40 * 60 + count($out) * 15)];
}

/** Itens do pedido de compra (somente leitura em items.json) para o motorista saber o que retirar. */
function col_itens_pc($pc) {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        $raw = @file_get_contents(DATA_DIR . '/items.json');
        $j = $raw ? json_decode($raw, true) : null;
        foreach ((array)($j['items'] ?? $j ?? []) as $it) if (is_array($it) && !empty($it['pc'])) $mapa[(int)$it['pc']] = $it;
    }
    $it = $mapa[(int)$pc] ?? null;
    if (!$it) return [];
    return array_map(fn($x) => ['descricao' => (string)($x['descricao'] ?? ''), 'quantidade' => $x['quantidade_pedida'] ?? '', 'unidade' => (string)($x['unidade'] ?? '')], array_slice((array)($it['insumos'] ?? []), 0, 40));
}

function col_rota_ativa_view(array $db) {
    $r = $db['rota_ativa'] ?? null;
    $pos = $db['motorista_pos'] ?? null;
    if ($pos && strtotime($pos['em'] ?? '') < time() - 6 * 3600) $pos = null;
    if (!$r || empty($r['paradas'])) return ['rota' => null, 'posicao' => $pos];
    $o = col_ordenar_paradas($db, $r['paradas'], false);
    foreach ($o['paradas'] as &$p) {
        $p['itens'] = $p['pc'] ? col_itens_pc($p['pc']) : [];
        $p['concluida'] = in_array($p['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true);
        $ult = end($p['retornos']) ?: null;
        $p['nao_coletada'] = !$p['concluida'] && $ult && strcmp((string)$ult['em'], (string)$r['criada_em']) >= 0;
        if ($p['nao_coletada']) $p['nao_coletada_motivo'] = $ult['motivo_label'] ?? '';
    }
    unset($p);
    foreach ($o['sem_local'] as &$p) { $p['itens'] = $p['pc'] ? col_itens_pc($p['pc']) : []; $p['concluida'] = in_array($p['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true); $p['nao_coletada'] = false; }
    unset($p);
    $todas = array_merge($o['paradas'], $o['sem_local']);
    $feitas = count(array_filter($todas, fn($p) => $p['concluida']));
    $o['nao_coletadas'] = count(array_filter($todas, fn($p) => $p['nao_coletada']));
    return ['rota' => $o + ['id' => $r['id'], 'criada_em' => $r['criada_em'], 'criada_por' => $r['criada_por'], 'motorista' => $r['motorista'] ?? '',
        'feitas' => $feitas, 'total' => count($o['paradas']) + count($o['sem_local'])], 'posicao' => $pos];
}

/** Inicia (ou substitui) a rota do dia: guarda a ordem e passa as coletas abertas para "Rota de coleta". */
function col_rota_iniciar(array $ids, string $motorista, string $usuario, bool $otimizar, array $rua = []) {
    return col_with_lock(function () use ($ids, $motorista, $usuario, $otimizar, $rua) {
        $db = col_read();
        $o = col_ordenar_paradas($db, array_slice($ids, 0, 40), $otimizar);
        $ordem = array_merge(array_column($o['paradas'], 'id'), array_column($o['sem_local'], 'id'));
        if (!$ordem) respond(['ok' => false, 'error' => 'Escolha ao menos uma coleta para a rota.'], 422);
        $now = col_now();
        foreach ($ordem as $id) {
            $c = $db['coletas'][$id];
            if (in_array($c['status'] ?? '', ['AGUARDANDO', 'ESTOQUE', 'FABRICACAO'], true)) {
                $c['historico'][] = ['em' => $now, 'por' => $usuario, 'acao' => 'status', 'de' => COL_STATUS[$c['status']] ?? $c['status'], 'para' => COL_STATUS['ROTA'], 'detalhe' => 'Rota iniciada' . ($motorista ? ' — ' . $motorista : '')];
                $c['status'] = 'ROTA'; if (empty($c['coleta_inicio'])) $c['coleta_inicio'] = $now;
                $c['atualizado_em'] = $now; $c['atualizado_por'] = $usuario;
                $db['coletas'][$id] = $c;
            }
        }
        $db['rota_ativa'] = ['id' => 'ROTA-' . date('Ymd-His'), 'paradas' => $ordem, 'criada_em' => $now, 'criada_por' => $usuario, 'motorista' => col_str($motorista, 80)];
        $db['rotas_hist'] = array_slice(array_merge($db['rotas_hist'] ?? [], [['id' => $db['rota_ativa']['id'], 'em' => $now, 'por' => $usuario, 'paradas' => $ordem, 'km_estimado' => $o['km_estimado'], 'motorista' => col_str($motorista, 80)]
            + ((($rua['km'] ?? 0) > 0) ? ['km_rua' => round((float)$rua['km'], 1), 'min_rua' => (int)($rua['min'] ?? 0)] : [])]), -200);
        col_write($db);
        return col_rota_ativa_view($db);
    });
}

function col_rota_encerrar(string $usuario) {
    return col_with_lock(function () use ($usuario) {
        $db = col_read();
        if (!empty($db['rota_ativa'])) {
            foreach ($db['rotas_hist'] ?? [] as $i => $h) if (($h['id'] ?? '') === $db['rota_ativa']['id']) { $db['rotas_hist'][$i]['encerrada_em'] = col_now(); $db['rotas_hist'][$i]['encerrada_por'] = $usuario; }
        }
        $db['rota_ativa'] = null;
        col_write($db);
        return ['rota' => null];
    });
}

/** Posição do motorista (só a última fica guardada). */
function col_posicao(array $b, string $usuario) {
    $lat = (float)($b['lat'] ?? 0); $lng = (float)($b['lng'] ?? 0);
    if ($lat < -34 || $lat > 6 || $lng < -75 || $lng > -28) respond(['ok' => false, 'error' => 'Posição fora do Brasil.'], 422);
    return col_with_lock(function () use ($lat, $lng, $b, $usuario) {
        $db = col_read();
        $db['motorista_pos'] = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'precisao' => (int)($b['precisao'] ?? 0), 'em' => col_now(), 'por' => $usuario];
        col_write($db);
        return ['posicao' => $db['motorista_pos']];
    });
}

/** v3.24.0: rotas já feitas — quantas, km, tempo e coletas, por período. */
function col_rotas_resumo(array $q) {
    $db = col_read();
    $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['de'] ?? '')) ? $q['de'] : date('Y-m-01');
    $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['ate'] ?? '')) ? $q['ate'] : date('Y-m-d');
    $lista = []; $t = ['rotas' => 0, 'km' => 0.0, 'min_deslocamento' => 0, 'min_total' => 0, 'paradas' => 0, 'coletadas' => 0, 'nao_coletadas' => 0];
    foreach (array_reverse($db['rotas_hist'] ?? []) as $h) {
        $dia = substr((string)$h['em'], 0, 10);
        if ($dia < $de || $dia > $ate) continue;
        $col = 0; $nao = 0; $kmReal = 0.0; $temKmReal = false;
        foreach ($h['paradas'] as $id) {
            $c = $db['coletas'][$id] ?? null; if (!$c) continue;
            $cAt = (string)($c['coletado_em'] ?? '');
            if ($cAt !== '' && strcmp($cAt, (string)$h['em']) >= 0 && (empty($h['encerrada_em']) || strcmp($cAt, (string)$h['encerrada_em']) <= 0)) { $col++; if (isset($c['km_real'])) { $kmReal += (float)$c['km_real']; $temKmReal = true; } }
            foreach ($c['retornos'] ?? [] as $r) if (strcmp((string)$r['em'], (string)$h['em']) >= 0 && (empty($h['encerrada_em']) || strcmp((string)$r['em'], (string)$h['encerrada_em']) <= 0)) { $nao++; break; }
        }
        $km = (float)($h['km_rua'] ?? $h['km_estimado'] ?? 0);
        $minDesl = (int)($h['min_rua'] ?? round($km / 40 * 60));
        $minTot = !empty($h['encerrada_em']) ? (int)round((strtotime($h['encerrada_em']) - strtotime($h['em'])) / 60) : null;
        $lista[] = ['id' => $h['id'], 'em' => $h['em'], 'encerrada_em' => $h['encerrada_em'] ?? null, 'motorista' => $h['motorista'] ?? '', 'paradas' => count($h['paradas']),
            'coletadas' => $col, 'nao_coletadas' => $nao, 'km' => round($km, 1), 'km_fonte' => isset($h['km_rua']) ? 'ruas' : 'estimado', 'km_motorista' => $temKmReal ? round($kmReal, 1) : null,
            'min_deslocamento' => $minDesl, 'min_total' => $minTot, 'ativa' => ($db['rota_ativa']['id'] ?? '') === $h['id']];
        $t['rotas']++; $t['km'] += $km; $t['min_deslocamento'] += $minDesl; $t['min_total'] += (int)$minTot; $t['paradas'] += count($h['paradas']); $t['coletadas'] += $col; $t['nao_coletadas'] += $nao;
    }
    $t['km'] = round($t['km'], 1);
    return ['de' => $de, 'ate' => $ate, 'totais' => $t, 'rotas' => $lista];
}
