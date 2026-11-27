<?php
/**
 * Recebimento Drilling - API PHP (HostGator)
 * JSON + até 3 fotos de produto otimizadas por PC
 */
header('Content-Type: application/json; charset=utf-8');
// Os carimbos de início e finalização são gerados pelo servidor em horário de Brasília.
date_default_timezone_set('America/Sao_Paulo');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Instalação automática (standalone): cria pastas, proteções e a base do seed
// na primeira requisição. Não é mais necessário rodar instalar.php.
// bootstrap.php é opcional por robustez: se ele não chegar ao servidor (upload
// interrompido, antivírus do painel, extração parcial), o próprio api.php refaz o
// essencial. Assim nenhuma instalação fica quebrada por um arquivo só.
// Banco de dados espelho (v3.8.0). Se a pasta data/ foi perdida/reinstalada, recupera tudo
// do banco ANTES do bootstrap — que, sem items.json, criaria uma base nova a partir do seed.
if (is_file(__DIR__ . '/rd_db.php')) {
    require_once __DIR__ . '/rd_db.php';
    if (!is_file(__DIR__ . '/data/items.json')) rd_db_restaurar_ausentes();
    register_shutdown_function('rd_db_ao_final');
}
if (is_file(__DIR__ . '/bootstrap.php')) {
    require_once __DIR__ . '/bootstrap.php';
    rd_bootstrap_or_die(__DIR__);
} elseif (!is_file(__DIR__ . '/data/items.json') || !is_file(__DIR__ . '/data/.htaccess')) {
    foreach (['data', 'data/photos', 'data/xml', 'data/order_pdfs', 'data/backups', 'data/imports', 'data/estoque'] as $d) {
        $p = __DIR__ . '/' . $d;
        if (!is_dir($p)) @mkdir($p, $d === 'data/backups' ? 0700 : 0755, true);
    }
    if (!is_file(__DIR__ . '/data/.htaccess')) {
        @file_put_contents(__DIR__ . '/data/.htaccess', "Options -Indexes\n<FilesMatch \"(?i)\\.(json|php|xml|zip|key|lock|tmp|bak|log|pem|ini)$\">\nRequire all denied\n</FilesMatch>\n<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
    }
    foreach (['data/backups', 'data/imports', 'data/order_pdfs'] as $d) {
        $f = __DIR__ . '/' . $d . '/.htaccess';
        if (!is_file($f)) @file_put_contents($f, "Require all denied\nOptions -Indexes\n", LOCK_EX);
    }
}

define('DATA_DIR', __DIR__ . '/data');
define('PHOTOS_DIR', DATA_DIR . '/photos');
define('ITEMS_FILE', DATA_DIR . '/items.json');
define('SEED_FILE', DATA_DIR . '/seed.php');
define('MAX_PRODUCT_PHOTOS', 3);
define('MAX_UPLOAD_BYTES', 4 * 1024 * 1024);
define('EVENTS_FILE', DATA_DIR . '/events.json');
define('XML_DIR', DATA_DIR . '/xml');
define('APP_VERSION', '3.65.3');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(PHOTOS_DIR)) mkdir(PHOTOS_DIR, 0755, true);
if (!is_dir(XML_DIR)) mkdir(XML_DIR, 0750, true);
if (is_file(DATA_DIR . '/.maintenance')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Sistema em manutenção para restauração de backup. Tente novamente após a conclusão.']);
    exit;
}

// v3.51.3: a versão 3.37.0–3.45.0 trazia um data/.htaccess que negava tudo (inclusive as fotos). Repara sozinho.
if (is_file(DATA_DIR . '/.htaccess') && ($h0 = (string)@file_get_contents(DATA_DIR . '/.htaccess')) && stripos($h0, 'FilesMatch') === false && stripos($h0, 'Require all denied') !== false) {
    @file_put_contents(DATA_DIR . '/.htaccess', "# Arquivos privados; fotos públicas por link\n<FilesMatch \"(?i)\\.(json|php|xml|zip|key|lock|tmp|bak|log|pem)$\">\nRequire all denied\n</FilesMatch>\n<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n", LOCK_EX);
}
if (!file_exists(DATA_DIR . '/.htaccess')) {
    file_put_contents(DATA_DIR . '/.htaccess', "# Arquivos JSON privados; fotos públicas por link\n<FilesMatch \"\\.(json|php)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n");
}

function respond($data, $code = 200) {
    // Integrações secundárias (estoque, notificações, agenda, push) rodam depois que o
    // pedido já foi gravado. Nesse modo um erro delas vira exceção capturável, em vez de
    // encerrar a requisição e mostrar falha ao usuário sobre um recebimento já salvo.
    if (!empty($GLOBALS['RD_SIDE_EFFECT'])) {
        throw new RuntimeException((string)($data['error'] ?? 'Falha em integração secundária'));
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** v3.28.0: entrega a resposta ao navegador antes de tarefas lentas (e-mail) feitas no encerramento. */
function rd_liberar_cliente(): void {
    ignore_user_abort(true); @set_time_limit(90);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); return; }
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}

/** Status operacional calculado pelas ações, independente do campo legado da importação. */
function operational_status($row) {
    $phase = $row['recebimento_estado'] ?? '';
    $arrival = $row['chegou'] ?? '';
    if ($phase === 'finalizado') return $arrival === 'PARCIAL' ? 'PARCIALMENTE ENTREGUE' : 'FINALIZADO';
    if ($phase === 'em_andamento') return 'EM CONFERÊNCIA';
    if ($arrival === 'SIM') return 'RECEBIDO';
    if ($arrival === 'PARCIAL') return 'PARCIAL';
    if ($arrival === 'NÃO') return 'NÃO CHEGOU';
    if ($arrival === 'EM TRÂNSITO') return 'EM TRÂNSITO';
    if ($arrival === 'N/A') return 'NÃO SE APLICA';
    if (!empty($row['fotoNF']) || !empty($row['fotosProdutos']) || !empty($row['xmlNF']) ||
        !empty($row['numeroNF']) || !empty($row['chaveNF'])) return 'DOCUMENTADO';
    if (!empty($row['ultima_acao']) && $row['ultima_acao'] !== 'Pedido cadastrado') return 'EM PREPARAÇÃO';
    return 'PENDENTE';
}
/** Linhas do pedido: quantidade explicitamente informada, nunca estimada. */
function parse_order_lines($material) {
    $result=[];
    foreach (preg_split('/\r\n|\n|\r/', (string)$material) as $line) {
        if (!preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*\)\s*(.{3,})$/u', trim($line), $match)) continue;
        $q=(float)str_replace(',', '.', $match[1]);
        $description=trim($match[2]);
        if ($q<=0 || $q>100000000 || strlen($description)>600) continue;
        $result[]=['id'=>'item_'.(count($result)+1),'descricao'=>$description,'unidade'=>'UN',
          'quantidade_pedida'=>$q,'quantidade_recebida'=>0,'quantidade_pendente'=>$q,'conferido_etapa'=>false];
        if (count($result)>=150) break;
    }
    return $result;
}
function normalize_lines($lines) {
    if (!is_array($lines)) return [];
    $out=[]; $used=[];
    foreach (array_slice($lines,0,150) as $n=>$line) {
        if (!is_array($line)) continue;
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($line['id']??('item_'.($n+1))));
        if (!$id || isset($used[$id])) $id='item_'.($n+1);
        $used[$id]=true;
        $requested=max(0,(float)($line['quantidade_pedida']??0));
        $received=max(0,(float)($line['quantidade_recebida']??0));
        $out[]=['id'=>$id,'sku'=>rd_sku_da_linha($line,$id),'descricao'=>mb_safe_trim((string)($line['descricao']??''),600),
         'unidade'=>mb_safe_trim((string)($line['unidade']??'UN'),20),
         'quantidade_pedida'=>$requested,'quantidade_recebida'=>$received,
         'quantidade_pendente'=>max(0,round($requested-$received,4)), 'conferido_etapa'=>(bool)($line['conferido_etapa']??false)];
    }
    return $out;
}
/** v3.28.0: SKU único da linha do PC — maiúsculo, sem o sufixo _2/_3 que separa linhas repetidas. */
function rd_sku_da_linha($line, $id = '') {
    $s = strtoupper(trim((string)($line['sku'] ?? '')));
    if ($s === '' && preg_match('/^sku[_-](.+)$/i', (string)$id, $m)) $s = strtoupper($m[1]);
    $s = preg_replace('/_\d{1,2}$/', '', preg_replace('/[^A-Z0-9 ._\/-]/', '', $s));
    return mb_substr($s, 0, 40);
}
function mb_safe_trim($str,$n) {
    $str = trim((string)$str);
    // substr() corta no meio de um caractere UTF-8 e quebra o json_encode.
    return function_exists('mb_substr') ? mb_substr($str,0,$n,'UTF-8') : substr($str,0,$n);
}
function validate_lines($lines,$oldLines=[], $finalized=false) {
    if (!is_array($lines) || count($lines)>150) respond(['ok'=>false,'error'=>'Limite de 150 insumos por pedido'],422);
    $oldById=[];foreach ($oldLines as $old) $oldById[$old['id']]=$old;
    $out=[];$seen=[];
    foreach ($lines as $index=>$line) {
        if (!is_array($line)) respond(['ok'=>false,'error'=>'Formato inválido do insumo'],422);
        $id=(string)($line['id']??'');if(!preg_match('/^[A-Za-z0-9_-]{1,70}$/',$id)||isset($seen[$id])) respond(['ok'=>false,'error'=>'ID inválido ou duplicado de insumo'],422);
        $seen[$id]=true;
        $desc=trim((string)($line['descricao']??''));$unit=trim((string)($line['unidade']??'UN'));
        if(strlen($desc)<2 || strlen($desc)>600 || strlen($unit)>20) respond(['ok'=>false,'error'=>'Descrição ou unidade inválida no insumo '.($index+1)],422);
        foreach(['quantidade_pedida','quantidade_recebida'] as $k){if(!isset($line[$k])||!is_numeric($line[$k])||!is_finite((float)$line[$k])) respond(['ok'=>false,'error'=>'Quantidade inválida: '.$k],422);}
        $ordered=(float)$line['quantidade_pedida'];$received=(float)$line['quantidade_recebida'];
        if($ordered<=0||$ordered>100000000||$received<0||$received>$ordered+0.00001) respond(['ok'=>false,'error'=>'Quantidades devem ser positivas e recebido não pode ultrapassar o pedido'],422);
        if(isset($oldById[$id]) && $received+0.00001 < (float)$oldById[$id]['quantidade_recebida']) respond(['ok'=>false,'error'=>'Não é permitido reduzir quantidade já registrada. Corrija em procedimento de estorno auditado.'],409);
        if(isset($oldById[$id]) && (float)$oldById[$id]['quantidade_recebida']>0){
          if(abs($ordered-(float)$oldById[$id]['quantidade_pedida'])>.00001) respond(['ok'=>false,'error'=>'Quantidade pedida com recebimento registrado não pode mudar'],409);
          if($desc!==$oldById[$id]['descricao']||$unit!==$oldById[$id]['unidade']) respond(['ok'=>false,'error'=>'Descrição/unidade já recebida não pode ser alterada'],409);
        }
        $out[]=['id'=>$id,'sku'=>rd_sku_da_linha($line,$id),'descricao'=>$desc,'unidade'=>$unit,'quantidade_pedida'=>$ordered,'quantidade_recebida'=>$received,'quantidade_pendente'=>max(0,round($ordered-$received,4)), 'conferido_etapa'=>(bool)($line['conferido_etapa']??false)];
    }
    foreach($oldById as $id=>$old)if(!isset($seen[$id]) && (float)$old['quantidade_recebida']>0)respond(['ok'=>false,'error'=>'Não é possível excluir insumo já recebido'],409);
    return $out;
}
function line_completion($lines) {
    if (!$lines) return '';
    $remaining=false; $some=false;
    foreach($lines as $line){
        if((float)$line['quantidade_pedida']<=0) return '';
        if((float)$line['quantidade_recebida']>0)$some=true;
        if((float)$line['quantidade_pendente']>0.00001)$remaining=true;
    }
    return !$some?'':($remaining?'PARCIAL':'SIM');
}
function mark_action(&$item, $label) {
    $item['ultima_acao'] = $label;
    $item['ultima_acao_em'] = date('c');
    // Revisão de alta precisão: evita colisão de duas gravações no mesmo segundo.
    $item['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:s.uP');
    $item['situacao'] = operational_status($item);
}

function normalize_item($row) {
    $fotos = isset($row['fotosProdutos']) && is_array($row['fotosProdutos']) ? $row['fotosProdutos'] : [];
    $fotos = array_values(array_filter($fotos));
    $fotos = array_slice($fotos, 0, MAX_PRODUCT_PHOTOS);
    $lines=normalize_lines($row['insumos']??parse_order_lines($row['material']??''));
    return [
        'pc' => (int)($row['pc'] ?? 0),
        'material' => $row['material'] ?? '',
        'fornecedor' => $row['fornecedor'] ?? '',
        'situacao' => operational_status($row),
        'situacao_origem' => $row['situacao_origem'] ?? ($row['situacao'] ?? 'PENDENTE'),
        'ultima_acao' => $row['ultima_acao'] ?? '',
        'ultima_acao_em' => $row['ultima_acao_em'] ?? null,
        'chegou' => $row['chegou'] ?? '',
        // Data de confirmação: só existe após finalização. Nunca usar chegada/início.
        'data' => !empty($row['recebimento_finalizado_em']) && ($row['recebimento_estado'] ?? '') === 'finalizado'
            ? date('Y-m-d', strtotime($row['recebimento_finalizado_em'])) : '',
        'qtd' => $row['qtd'] ?? '',
        'insumos' => $lines,
        'origem_entrega' => $row['origem_entrega'] ?? '',
        'entregas' => is_array($row['entregas']??null) ? $row['entregas'] : [],
        'historico_retificacoes' => is_array($row['historico_retificacoes']??null) ? $row['historico_retificacoes'] : [],
        'primeira_confirmacao_em' => $row['primeira_confirmacao_em'] ?? ($row['recebimento_finalizado_em']??null),
        'obs' => $row['obs'] ?? '',
        'chaveNF' => $row['chaveNF'] ?? '',
        'fotoNF' => $row['fotoNF'] ?? '',
        'fotosProdutos' => $fotos,
        'nfResumo' => $row['nfResumo'] ?? '',
        'numeroNF' => $row['numeroNF'] ?? '',
        'serieNF' => $row['serieNF'] ?? '',
        'cnpjEmitente' => $row['cnpjEmitente'] ?? '',
        'emissaoNF' => $row['emissaoNF'] ?? '',
        'valorNF' => $row['valorNF'] ?? '',
        'itensNF' => $row['itensNF'] ?? '',
        'origemNF' => $row['origemNF'] ?? '',
        'xmlNF' => $row['xmlNF'] ?? '',
        'solicitante' => $row['solicitante'] ?? '',
        'recebimento_estado' => $row['recebimento_estado'] ?? '',
        'recebimento_iniciado_em' => $row['recebimento_iniciado_em'] ?? null,
        'recebimento_finalizado_em' => $row['recebimento_finalizado_em'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'importacao_origem'=>$row['importacao_origem']??'',
        'importacao_arquivo'=>$row['importacao_arquivo']??'',
        'importacao_hash'=>$row['importacao_hash']??'',
        'importacao_em'=>$row['importacao_em']??null,
        // Marcador controlado pelo servidor: apenas PCs realmente inéditas, sem retroagir à base existente.
        'novo_pedido_em'=>!empty($row['novo_pedido_em']) ? (string)$row['novo_pedido_em'] : null,
        // v3.9: PC emergencial e devoluções ao fornecedor (alimentam os Indicadores).
        'urgente'=>(($row['urgente'] ?? '') === '1' || ($row['urgente'] ?? null) === true) ? '1' : '',
        'devolucoes'=>array_values(array_filter((array)($row['devolucoes'] ?? []), 'is_array')),
        // v3.28.0: de onde veio a marcação de urgência (ex.: mensagem do WhatsApp)
        'urgente_motivo'=>mb_substr((string)($row['urgente_motivo'] ?? ''), 0, 260),
        // v3.28.0: destino do pedido — obra ou setor (Depósito, Caldeiraria, Manutenção…)
        'destino_setor'=>preg_match('/^[A-Z]{2,15}$/', (string)($row['destino_setor'] ?? '')) ? (string)$row['destino_setor'] : '',
        'obra_id'=>mb_substr((string)($row['obra_id'] ?? ''), 0, 60),
        'obra'=>mb_substr((string)($row['obra'] ?? ''), 0, 120),
        'destino_origem'=>in_array($row['destino_origem'] ?? '', ['auto', 'manual'], true) ? $row['destino_origem'] : '',
        'finalidade_pc'=>mb_substr((string)($row['finalidade_pc'] ?? ''), 0, 200),
        'observacoes_pc'=>mb_substr((string)($row['observacoes_pc'] ?? ''), 0, 600),
        // v3.32.0: OS e equipamento lidos do pedido de compra (origem: texto | ia | manual). Opcionais — pedidos antigos ficam vazios.
        'os_pc'=>mb_substr(trim((string)($row['os_pc'] ?? '')), 0, 40),
        'equipamento_pc'=>mb_substr(trim((string)($row['equipamento_pc'] ?? '')), 0, 120),
        'os_origem'=>in_array(($row['os_origem'] ?? ''), ['texto','ia','manual'], true) ? $row['os_origem'] : '',
        'urgente_em'=>$row['urgente_em'] ?? null,
        // v3.29.0: quem recebe o e-mail "seu pedido chegou". modo: '' (automático pelo solicitante), 'definido' (lista abaixo) ou 'nenhum'.
        'avisar_modo'=>in_array($row['avisar_modo'] ?? '', ['definido', 'nenhum'], true) ? $row['avisar_modo'] : '',
        'avisar_para'=>rd_avisar_limpar($row['avisar_para'] ?? []),
        'avisar_em'=>$row['avisar_em'] ?? null,
        'avisar_por'=>mb_substr((string)($row['avisar_por'] ?? ''), 0, 80),
    ];
}
/** v3.29.0: sanitiza a lista de destinatários do aviso de chegada (nome, e-mail e de onde veio a escolha). */
function rd_avisar_limpar($lista) {
    if (!is_array($lista)) return [];
    $out = []; $vistos = [];
    foreach (array_slice($lista, 0, 10) as $d) {
        if (!is_array($d)) continue;
        $e = strtolower(trim((string)($d['email'] ?? '')));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || isset($vistos[$e])) continue;
        $vistos[$e] = 1;
        $o = (string)($d['origem'] ?? 'manual');
        $out[] = ['nome' => mb_substr(trim((string)($d['nome'] ?? '')), 0, 120), 'email' => mb_substr($e, 0, 160),
                  'origem' => in_array($o, ['solicitante', 'observacao', 'manual'], true) ? $o : 'manual'];
    }
    return $out;
}

function read_items() {
    if (file_exists(ITEMS_FILE)) {
        $data = json_decode(file_get_contents(ITEMS_FILE), true);
        if (is_array($data)) return array_map('normalize_item', $data);
        // Corrompido: tenta a última cópia válida do banco (o arquivo ruim é guardado ao lado).
        if (function_exists('rd_db_recuperar') && rd_db_recuperar('items.json')) {
            $data = json_decode(file_get_contents(ITEMS_FILE), true);
            if (is_array($data)) return array_map('normalize_item', $data);
        }
        // NUNCA recriar o banco a partir do seed se o JSON de produção estiver corrompido.
        respond(['ok'=>false,'error'=>'Falha ao ler data/items.json. Preserve o arquivo e restaure o backup; não houve reinicialização automática.'],500);
    }
    if (function_exists('rd_db_recuperar') && rd_db_recuperar('items.json')) return read_items();
    if (is_file(ITEMS_FILE . '.backup.json')) respond(['ok'=>false,'error'=>'Arquivo principal de pedidos ausente. Backup preservado; restauração manual necessária.'],500);
    if (file_exists(SEED_FILE)) {
        $seed = include SEED_FILE;
        $items = [];
        foreach ($seed as $pc => $row) {
            $items[] = normalize_item(array_merge($row, [
                'chegou' => '', 'data' => '', 'qtd' => '', 'obs' => '',
                'chaveNF' => '', 'fotoNF' => '', 'fotosProdutos' => [], 'nfResumo' => '',
                'numeroNF' => '', 'solicitante' => '',
                'serieNF'=>'', 'cnpjEmitente'=>'', 'emissaoNF'=>'', 'valorNF'=>'', 'itensNF'=>'', 'origemNF'=>'', 'xmlNF'=>'',
                'recebimento_estado' => '', 'recebimento_iniciado_em' => null, 'recebimento_finalizado_em' => null,
            ]));
        }
        usort($items, function($a,$b){ return $a['pc'] <=> $b['pc']; });
        write_items($items);
        return $items;
    }
    return [];
}

function write_items($items) {
    $items = array_map('normalize_item', $items);
    usort($items, function($a,$b){ return $a['pc'] <=> $b['pc']; });
    $tmp = ITEMS_FILE . '.tmp';
    $encoded=json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded===false || file_put_contents($tmp,$encoded,LOCK_EX)===false) {
        respond(['ok'=>false,'error'=>'Não foi possível gravar o arquivo temporário de pedidos'],500);
    }
    if (is_file(ITEMS_FILE) && !@copy(ITEMS_FILE, ITEMS_FILE . '.backup.json')) error_log('Recebimento Drilling: não foi possível copiar backup da base');
    if (!rename($tmp,ITEMS_FILE)) {
        respond(['ok'=>false,'error'=>'Falha ao persistir os pedidos. Verifique permissões de gravação.'],500);
    }
}

function find_index($items, $pc) {
    foreach ($items as $i => $it) {
        if ((int)$it['pc'] === (int)$pc) return $i;
    }
    return -1;
}

/**
 * Histórico sincronizado entre navegadores, até 300 atividades recentes.
 * Chamado dentro do lock de alteração de pedidos. Não armazena dados de NF,
 * conteúdo de observações nem fotos para reduzir exposição.
 */
function append_event($pc, $tipo, $mensagem) {
    // Trava própria: os módulos (estoque, locações, protocolos...) chamam isto sob as SUAS travas, não a dos pedidos;
    // sem ela, duas gravações simultâneas repetiam o mesmo id (o cursor da sincronização) e perdiam eventos.
    $fpEv = @fopen(DATA_DIR . '/.events.lock', 'c');
    if ($fpEv) flock($fpEv, LOCK_EX);
    try { append_event_sem_trava($pc, $tipo, $mensagem); }
    finally { if ($fpEv) { flock($fpEv, LOCK_UN); fclose($fpEv); } }
}
function append_event_sem_trava($pc, $tipo, $mensagem) {
    $log = ['last_id' => 0, 'events' => []];
    if (is_file(EVENTS_FILE)) {
        $decoded = json_decode(@file_get_contents(EVENTS_FILE), true);
        if (is_array($decoded) && isset($decoded['events']) && is_array($decoded['events'])) $log = $decoded;
    }
    $id = (int)($log['last_id'] ?? 0) + 1;
    $origin = $_SERVER['HTTP_X_CLIENT_ID'] ?? '';
    $origin = preg_match('/^[A-Za-z0-9_-]{1,80}$/', $origin) ? $origin : '';
    $ev = [
        'id' => $id,
        'pc' => (int)$pc,
        'type' => $tipo,
        'message' => $mensagem,
        'at' => date('c'),
        'origin' => $origin,
    ];
    $log['last_id'] = $id;
    $log['events'][] = $ev;
    $log['events'] = array_slice($log['events'], -300);
    $temp = EVENTS_FILE . '.tmp';
    if (file_put_contents($temp, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        rename($temp, EVENTS_FILE);
    } else { error_log('Recebimento Drilling: falha ao salvar evento PC '.$pc); }
}

function delete_photo_file($relative) {
    if (!$relative || !preg_match('~^data/photos/pc_[0-9]+_(?:nf|p[0-2])_[A-Za-z0-9]+\.(?:jpg|pdf)$~', $relative)) return;
    $path = PHOTOS_DIR . '/' . basename($relative);
    if (is_file($path)) @unlink($path);
}

function optimize_image($tmpPath, $destPath, $mime) {
    if (!function_exists('imagecreatetruecolor')) {
        return move_uploaded_file($tmpPath, $destPath) || copy($tmpPath, $destPath);
    }
    $src = false;
    if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($tmpPath);
    elseif ($mime === 'image/png') $src = @imagecreatefrompng($tmpPath);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($tmpPath);
    if (!$src) return false;

    $w = imagesx($src); $h = imagesy($src); $max = 1280;
    if ($w > $max || $h > $max) {
        if ($w >= $h) { $nw = $max; $nh = (int)round($h * $max / $w); }
        else { $nh = $max; $nw = (int)round($w * $max / $h); }
    } else { $nw = $w; $nh = $h; }

    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $ok = imagejpeg($dst, $destPath, 72);
    imagedestroy($dst);
    return $ok;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// v3.10.2: carga inicial entregue no pacote (roda uma vez; nunca substitui dados existentes).
if (is_file(__DIR__ . '/carga_inicial.php') && is_dir(DATA_DIR . '/carga_inicial') && !is_file(DATA_DIR . '/carga_inicial/.aplicada')) {
    require_once __DIR__ . '/carga_inicial.php';
    rd_carga_inicial();
}
// v3.28.0: cargas de locação (NF de locadora) — cada arquivo uma única vez.
if (is_file(__DIR__ . '/carga_inicial.php') && glob(DATA_DIR . '/carga_inicial/locacoes_*.json')) {
    require_once __DIR__ . '/carga_inicial.php';
    rd_carga_locacoes();
}
// v3.28.0: uma única vez — descobre a obra/setor de destino dos pedidos já existentes (PDF + textos)
// v3.28.0: recuperação pontual de pedidos (arquivos data/carga_inicial/recuperar_pedidos_*.json)
if (glob(DATA_DIR . '/carga_inicial/recuperar_pedidos_*.json')) { require_once __DIR__ . '/carga_inicial.php'; rd_recuperar_pedidos(); }
if (!is_file(DATA_DIR . '/.destino-pc-3200') && is_file(ITEMS_FILE)) {
    $fpL = @fopen(DATA_DIR . '/.write.lock', 'c');
    if ($fpL && flock($fpL, LOCK_EX | LOCK_NB)) {
        try {
            @set_time_limit(180);
            require_once __DIR__ . '/rd_obras.php';
            require_once __DIR__ . '/whatsapp_import_lib.php';
            $its = read_items(); $n = 0;
            foreach ($its as &$it) {
                if (($it['destino_origem'] ?? '') === 'manual' || ($it['destino_setor'] ?? '') !== '') continue;
                $txt = '';
                foreach (glob(DATA_DIR . '/order_pdfs/pc_' . (int)$it['pc'] . '_*.pdf') ?: [] as $pdf) {
                    try { $r = wa_pc_from_pdf((string)file_get_contents($pdf), basename($pdf));
                        if (empty($r['error'])) { $it['finalidade_pc'] = (string)($r['destino'] ?? ''); $it['observacoes_pc'] = (string)($r['observacoes'] ?? ''); $txt .= ' ' . $it['observacoes_pc'] . ' ' . $it['finalidade_pc']; }
                    } catch (Throwable $e) {}
                    break;
                }
                $d = rd_destino_detectar($txt . ' ' . ($it['obs'] ?? '') . ' ' . ($it['material'] ?? ''));
                if ($d) { $it['destino_setor'] = $d['setor']; $it['obra_id'] = $d['obra_id'] ?? ''; $it['obra'] = $d['obra'] ?? ''; $it['destino_origem'] = 'auto'; $n++; }
            }
            unset($it);
            write_items($its);
            @file_put_contents(DATA_DIR . '/.destino-pc-3200', json_encode(['em' => date('c'), 'identificados' => $n]));
        } catch (Throwable $e) { error_log('destino PC: ' . $e->getMessage()); }
        flock($fpL, LOCK_UN);
    }
}

// Autenticação operacional (quando publicada): dá suporte a perfil e CSRF nos módulos.
// Carregada antes do roteamento; a validação de origem fica a cargo de cada módulo,
// que tolera ausência do cabeçalho Origin (clientes nativos e testes).
if (is_file(__DIR__.'/auth_users.php')) { @require_once __DIR__.'/auth_users.php'; }
require_once __DIR__ . '/perm_lib.php';

// Rotas de sessão (login, status, logout, usuários do fluxo antigo).
if (strncmp((string)$action, 'auth_', 5) === 0 && function_exists('rd_auth_route')) rd_auth_route((string)$action);

// PORTÃO CENTRAL (v3.7.0): decide módulo × operação no servidor, antes de rotear.
// Negação por padrão quando a exigência de login está ativa; sem login, comportamento legado.
if (function_exists('rd_gate_action')) {
    $ident = rd_identity();
    if ($ident !== null) {
        $st = rd_user_status($ident['username']);
        if ($st !== 'ativo') {
            rd_log('negado', $ident['username'], 'sessao', 'ver', 'bloqueado', 'status do cadastro: ' . $st);
            respond(['ok' => false, 'error' => 'Acesso encerrado: usuário ' . ($st === 'bloqueado' ? 'bloqueado' : 'inativo') . '.'], 403);
        }
        if (rd_sessao_invalidada()) {
            rd_log('negado', $ident['username'], 'sessao', 'ver', 'bloqueado', 'sessão encerrada pelo administrador');
            respond(['ok' => false, 'error' => 'Sua sessão foi encerrada pelo administrador. Entre novamente.'], 403);
        }
        $dbp = rd_perm_read();
        rd_sessao_tocar($ident['username'], (string)($dbp['usuarios'][$ident['username']]['perfil'] ?? $ident['role']));
    }
    rd_gate_action((string)$action);
}

// Depois de qualquer gravação no estoque, confere os alertas (após a resposta ao usuário).
if (strncmp((string)$action, 'estoque_', 8) === 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    register_shutdown_function(function () {
        rd_liberar_cliente();
        try { require_once __DIR__ . '/alertas_lib.php'; alt_verificar('movimento'); } catch (Throwable $e) { error_log('Alertas: ' . $e->getMessage()); }
    });
}
// v3.51.3: checagem de Origem (anti-CSRF) para TODA gravação, inclusive as rotas dos módulos
// (estoque, locações, protocolos, obras...), que são atendidas antes do bloco de POST abaixo.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
    $rdH0 = (string)($_SERVER['HTTP_HOST'] ?? ''); $rdO0 = (string)$_SERVER['HTTP_ORIGIN'];
    if ($rdH0 === '' || ($rdO0 !== 'https://' . $rdH0 && $rdO0 !== 'http://' . $rdH0)) {
        respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
    }
}

// Módulo de Estoque (v3.0.0): rotas estoque_* vivem em estoque_api.php e
// compartilham a mesma sessão, a mesma origem e a mesma trava de escrita.
// Somente leitura de data/estoque.json — nunca toca em data/items.json.
if (strncmp((string)$action, 'estoque_', 8) === 0) {
    require __DIR__.'/estoque_api.php';
}

// v3.51.3: painel de pendências do dia (Dashboard).
if ($action === 'pendencias_hoje' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    require_once __DIR__ . '/pendencias_lib.php';
    respond(['ok' => true] + pend_hoje());
}

// v3.28.0: alertas de estoque baixo (sino + e-mail, repetindo até a reposição).
if (strncmp((string)$action, 'alertas_', 8) === 0) {
    require_once __DIR__ . '/alertas_lib.php';
    $altB = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    try {
        switch ($action) {
            case 'alertas_status': respond(['ok' => true] + alt_status());
            case 'alertas_config': respond(['ok' => true, 'config' => alt_config_publica()]);
            case 'alertas_config_salvar': respond(['ok' => true, 'config' => alt_config_salvar($altB)]);
            case 'alertas_teste': respond(['ok' => true] + alt_email_teste());
            case 'alertas_verificar': respond(['ok' => true] + alt_verificar('manual', !empty($altB['reenviar'])));
            case 'alertas_contatos': respond(['ok' => true, 'contatos' => rcb_contatos(), 'envios' => rcb_envios()]);
            case 'alertas_contatos_salvar': respond(['ok' => true, 'contatos' => rcb_contatos_salvar((array)($altB['contatos'] ?? []), (function_exists('rd_login_info') && is_array(rd_login_info())) ? (string)rd_login_info()['username'] : 'sistema')]);
            case 'alertas_contatos_testar': respond(['ok' => true, 'destinatarios' => array_map(fn($d) => $d['nome'] . ' <' . $d['email'] . '>', rcb_destinatarios((string)($_GET['nome'] ?? '')))]);
        }
    } catch (InvalidArgumentException $e) { respond(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (Throwable $e) { respond(['ok' => false, 'error' => $e->getMessage()], 500); }
    respond(['ok' => false, 'error' => 'Ação inválida'], 400);
}
// Sem cron: as telas abertas (batimento a cada 20 s) disparam a verificação no máximo a cada 15 min.
if ($action === 'notify_heartbeat') {
    register_shutdown_function(function () {
        rd_liberar_cliente();
        try { require_once __DIR__ . '/alertas_lib.php'; alt_tick(); } catch (Throwable $e) {}
        try { require_once __DIR__ . '/locacoes_lib.php'; require_once __DIR__ . '/locacoes_alertas_lib.php'; lal_tick(); } catch (Throwable $e) {}
        try { require_once __DIR__ . '/relger_envio_lib.php'; rge_tick(); } catch (Throwable $e) {}   // v3.58.1: envio diário das saídas
        try { if (is_file(DATA_DIR . '/nfmail_config.json')) { require_once __DIR__ . '/nfmail_lib.php'; nfm_tick(); } } catch (Throwable $e) { error_log('Caixa de NFs: ' . $e->getMessage()); }   // v3.59.0
        // v3.51.3: escoa pushes pendentes (tentativas que falharam) mesmo sem cron.
        try { if (is_file(DATA_DIR . '/push-queue.json') && filesize(DATA_DIR . '/push-queue.json') > 4) { require_once __DIR__ . '/push_lib.php'; push_process_queue(10, 3); } } catch (Throwable $e) {}
    });
}
// Sistema de notificações (v3.1.0): presença dos logados + caixa de entrada por dispositivo.
if (strncmp((string)$action, 'notify_', 7) === 0) {
    require __DIR__.'/notify_api.php';
}

// Backup completo automatizado (v3.2.0): snapshot diário, verificação e retenção.
if (strncmp((string)$action, 'backup_', 7) === 0) {
    require __DIR__.'/backup_api.php';
}

// Agenda inteligente (v3.3.0): data programada de retirada/entrega por PC.
if (strncmp((string)$action, 'agenda_', 7) === 0) {
    require __DIR__.'/agenda_api.php';
}

// Administração de usuários, perfis e permissões (v3.7.0).
if (strncmp((string)$action, 'usuarios_', 9) === 0) {
    require __DIR__.'/usuarios_api.php';
}

// Módulo de Etiquetas e Identificação de Materiais (v3.6.0): usa o catálogo oficial do estoque.
if (strncmp((string)$action, 'etiquetas_', 10) === 0) {
    require __DIR__.'/etiquetas_api.php';
}

// Locação de equipamentos de fornecedores (v3.28.0).
// v3.28.0: Controle de obras — envios de material com notas e fotos.
if (strncmp((string)$action, 'obras_', 6) === 0 && $action !== 'obras_lista') {
    require __DIR__.'/obras_api.php';
}
if (strncmp((string)$action, 'locacoes_', 9) === 0) {
    require __DIR__.'/locacoes_api.php';
}
// Protocolo de NFs ao Financeiro (v3.36.0).
if (strncmp((string)$action, 'protocolos_', 11) === 0) {
    require __DIR__.'/protocolos_api.php';
}
// Romaneio de Terceiros (v3.51.3).
if (strncmp((string)$action, 'rastreios_', 10) === 0) {
    require __DIR__.'/rastreios_api.php';
}
if (strncmp((string)$action, 'romaneios_', 10) === 0) {
    require __DIR__.'/romaneios_api.php';
}
// Caixa de NFs por e-mail (v3.59.0).
if (strncmp((string)$action, 'nfmail_', 7) === 0) {
    require __DIR__.'/nfmail_api.php';
}
// Relatório gerencial (v3.58.1).
if (strncmp((string)$action, 'contagens_', 10) === 0) {
    require __DIR__.'/contagens_api.php';
}
if (strncmp((string)$action, 'chegadas_', 9) === 0) {
    require __DIR__.'/chegadas_api.php';
}
if (strncmp((string)$action, 'relger_', 7) === 0) {
    require __DIR__.'/relger_api.php';
}
// Espelho de NF (v3.65.3).
if (strncmp((string)$action, 'espelhos_', 9) === 0) {
    require __DIR__.'/espelhos_api.php';
}
// Empréstimo de ferramentas (v3.10.0).
if (strncmp((string)$action, 'ferramentas_', 12) === 0) {
    require __DIR__.'/ferramentas_api.php';
}

// Módulo de Coletas (v3.5.0): módulo independente de controle, mapa e rota.
if (strncmp((string)$action, 'coletas_', 8) === 0) {
    require __DIR__.'/coletas_api.php';
}

require_once __DIR__.'/import_auth.php';
if (in_array($action,['import_auth_status','import_auth_login','import_auth_logout'],true)) imp_auth_route($action);
// v3.28.0: upload em partes (até 1 GB) — usado pela importação de pedidos e de coletas.
if (in_array($action, ['upload_info', 'upload_parte', 'upload_parte_coletas'], true)) {
    require_once __DIR__ . '/upload_lib.php';
    if ($action === 'upload_info') respond(['ok' => true, 'parte_max' => rd_up_parte_max(), 'limite' => RD_UP_MAX]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
    if (function_exists('rd_origin_valid') && !rd_origin_valid()) respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
    if ($action === 'upload_parte') {               // mesma autorização da importação de pedidos
        require_once __DIR__ . '/import_auth.php';
        imp_authorize_action();
    } else {                                         // coletas: perfil com permissão de criar coletas
        if (function_exists('rd_perm_or_legado') && !rd_perm_or_legado('coletas', 'criar', ['gestor', 'conferente']))
            respond(['ok' => false, 'error' => 'Seu perfil não permite alterar as coletas'], 403);
    }
    @set_time_limit(300);
    respond(['ok' => true] + rd_up_parte());
}
if ($action === 'nf_ler_danfe') {   // v3.28.0: leitura da DANFE pela IA (não grava; o conferente revisa e salva)
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['ok' => false, 'error' => 'Método não permitido'], 405);
    require_once __DIR__ . '/gemini_import.php';
    @set_time_limit(120);
    $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $pcL = (int)($b['pc'] ?? 0); $itL = null;
    foreach (read_items() as $x) if ((int)$x['pc'] === $pcL) { $itL = $x; break; }
    if (!$itL) respond(['ok' => false, 'error' => 'PC não encontrado'], 404);
    if (empty($itL['chaveNF']) && preg_match('/^\d{44}$/', (string)($b['chave'] ?? ''))) $itL['chaveNF'] = $b['chave'];
    try { respond(['ok' => true] + rd_nf_ler_danfe($itL)); }
    catch (Throwable $e) { respond(['ok' => false, 'error' => $e->getMessage()], 422); }
}
if (in_array($action,['whatsapp_import_preview','whatsapp_import_confirm','gemini_status','gemini_config','gemini_teste'],true)) {
    imp_authorize_action();
    require __DIR__.'/whatsapp_import_api.php';
}

// v3.51.3 — regras de push por módulo (somente administrador).
if ($action === 'push_regras' || $action === 'push_regras_salvar') {
    require_once __DIR__.'/push_lib.php';
    try {
        $subs=push_jsonread(PUSH_SUBS); $porUser=[]; $semDono=0;
        foreach ($subs as $sb) { $u=strtolower((string)($sb['usuario']??'')); if ($u==='') $semDono++; else $porUser[$u]=($porUser[$u]??0)+1; }
        if ($action === 'push_regras_salvar') {
            if ($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'error'=>'Método não permitido'],405);
            $in=json_decode((string)file_get_contents('php://input'),true);
            if(!is_array($in)||!is_array($in['regras']??null)) respond(['ok'=>false,'error'=>'Dados inválidos'],422);
            $quem=(function_exists('rd_identity') && ($__i=rd_identity())) ? (string)$__i['username'] : 'administrador';
            push_regras_salvar($in['regras'],$quem);
        }
        $regras=push_regras_ler(); $mods=[];
        foreach (PUSH_MODULOS as $m=>$nome) $mods[]=['modulo'=>$m,'nome'=>$nome]+$regras[$m];
        $users=[]; $nomes=[];
        $pdb=function_exists('rd_perm_read')?rd_perm_read():['usuarios'=>[]];
        $lista=function_exists('rd_user_data')?rd_user_data():[];
        foreach ($lista as $nm=>$row) { if (!empty($row['bloqueado'])||empty($row['enabled'])) continue; $users[$nm]=(string)($pdb['usuarios'][$nm]['nome']??$nm); }
        if (function_exists('rd_admin_data') && !empty(rd_admin_data()['username'])) { $an=(string)rd_admin_data()['username']; $users[$an]=(string)(rd_admin_data()['nome']??'Administrador principal'); }
        $us=[]; foreach ($users as $nm=>$nome) $us[]=['username'=>$nm,'nome'=>$nome,'push_ativo'=>($porUser[strtolower($nm)]??0)];
        respond(['ok'=>true,'modulos'=>$mods,'usuarios'=>$us,'inscritos'=>count($subs),'sem_dono'=>$semDono]);
    } catch (InvalidArgumentException $e) { respond(['ok'=>false,'error'=>$e->getMessage()],422); }
    catch (Throwable $e) { error_log('Regras de push: '.$e->getMessage()); respond(['ok'=>false,'error'=>'Não foi possível processar as regras de push'],500); }
}

// Web Push: inscrição explícita por navegador. A permissão é solicitada somente
// após um clique do próprio usuário. A API legada NÃO possui contas individuais.
if (in_array($action, ['push_public_key','push_subscribe','push_unsubscribe'], true)) {
    require_once __DIR__.'/push_lib.php';
    try {
        if ($action === 'push_public_key' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $conf=push_key_config();
            respond(['ok'=>true, 'publicKey'=>$conf['publicKey']]);
        }
        if ($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'error'=>'Método não permitido'],405);
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        $expected='https://'.($_SERVER['HTTP_HOST']??'');
        $localHttp=preg_match('/^(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$/',$_SERVER['HTTP_HOST']??'')
            ? 'http://'.($_SERVER['HTTP_HOST']??'') : '';
        if (!$origin || ($origin!==$expected && $origin!==$localHttp)) respond(['ok'=>false,'error'=>'Origem não autorizada'],403);
        $body=file_get_contents('php://input');
        if (strlen($body)>8192) respond(['ok'=>false,'error'=>'Corpo muito grande'],413);
        $input=json_decode($body,true);
        if(!is_array($input)) respond(['ok'=>false,'error'=>'Dados inválidos'],422);
        if($action==='push_subscribe') respond(push_subscribe($input, (function_exists('rd_identity') && ($__id=rd_identity())) ? (string)$__id['username'] : ''));
        if($action==='push_unsubscribe') respond(push_unsubscribe((string)($input['endpoint']??'')));
    }catch(InvalidArgumentException $e){respond(['ok'=>false,'error'=>$e->getMessage()],422);}
     catch(Throwable $e){error_log('Web Push: '.$e->getMessage());respond(['ok'=>false,'error'=>'Não foi possível configurar o Web Push. Verifique os requisitos do servidor.'],503);}
}


if ($action === 'events' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $log = ['last_id' => 0, 'events' => []];
    if (is_file(EVENTS_FILE)) {
        $decoded = json_decode(@file_get_contents(EVENTS_FILE), true);
        if (is_array($decoded) && isset($decoded['events']) && is_array($decoded['events'])) $log = $decoded;
    }
    $lastId = (int)($log['last_id'] ?? 0);
    $sinceRaw = $_GET['since'] ?? 'latest';
    $since = $sinceRaw === 'latest' ? $lastId : max(0, (int)$sinceRaw);
    $results = [];
    if ($sinceRaw === 'latest') {
        respond(['ok' => true, 'version' => APP_VERSION, 'initial' => true,
          'events' => array_slice($log['events'], -30), 'cursor' => $lastId, 'last_id' => $lastId]);
    }
    foreach ($log['events'] as $event) {
        if ((int)$event['id'] > $since) {
            $results[] = $event;
            if (count($results) >= 80) break;
        }
    }
    $cursor = $results ? (int)end($results)['id'] : max($since, $lastId);
    respond(['ok' => true, 'version' => APP_VERSION,
      'events' => $results, 'cursor' => $cursor, 'last_id' => $lastId]);
}

// v3.7.4: as gravações do núcleo (save, fotos, recebimento) não exigiam CSRF. Bloqueia POST
// vindo de outro site: navegadores sempre enviam Origin em POST via fetch/form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
    $rdHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $rdOrig = (string)$_SERVER['HTTP_ORIGIN'];
    if ($rdHost === '' || ($rdOrig !== 'https://' . $rdHost && $rdOrig !== 'http://' . $rdHost)) {
        respond(['ok' => false, 'error' => 'Origem não autorizada'], 403);
    }
}

// O arquivo JSON é compartilhado entre operadores. Serializar gravações evita
// perda de atualização quando duas requisições PHP chegam ao mesmo tempo.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($action, ['save', 'upload_photo', 'upload_xml', 'remove_photo', 'start_receipt', 'finish_receipt', 'correct_partial', 'admin_corrigir_recebimento', 'pc_renumerar', 'pc_create', 'pc_devolucao', 'pc_destino', 'pc_avisar'], true)) {
    $lockFile = @fopen(DATA_DIR . '/.write.lock', 'c');
    if (!$lockFile || !flock($lockFile, LOCK_EX)) {
        respond(['ok' => false, 'error' => 'Não foi possível bloquear os dados para gravação'], 503);
    }
}

// Timestamp definitivo gerado pela API. Não é enviado nem alterável pelo navegador.
if (in_array($action, ['start_receipt', 'finish_receipt'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pc = (int)($input['pc'] ?? 0);
    if (!$pc) respond(['ok' => false, 'error' => 'PC obrigatório'], 400);
    $items = read_items();
    $idx = find_index($items, $pc);
    if ($idx < 0) respond(['ok' => false, 'error' => 'PC não encontrado'], 404);
    $item = &$items[$idx];
    $estado = $item['recebimento_estado'] ?? '';
    $now = date('c');
    if ($action === 'start_receipt') {
        if ($estado === 'finalizado') {
            if (($item['chegou']??'') !== 'PARCIAL' || line_completion($item['insumos']) !== 'PARCIAL')
                respond(['ok' => false, 'error' => 'Pedido finalizado integralmente. Só é possível iniciar nova etapa para saldo pendente.'], 409);
            // Nova etapa de entrega parcial; confirmações anteriores continuam em entregas[].
            $item['recebimento_estado']='em_andamento';
            $item['origem_entrega']=''; // origem pode mudar na entrega seguinte
            foreach ($item['insumos'] as &$line) $line['conferido_etapa']=false;
            unset($line);
            $item['recebimento_iniciado_em']=$now;
            $item['recebimento_finalizado_em']=null;
            $item['data']='';
            mark_action($item, 'Nova conferência do saldo pendente');
            write_items($items);
            append_event($pc,'inicio','Nova etapa de recebimento iniciada');
            respond(['ok'=>true,'item'=>normalize_item($item)]);
        }
        if (!$item['recebimento_iniciado_em']) {
            $item['recebimento_iniciado_em'] = $now;
            $item['recebimento_estado'] = 'em_andamento';
            mark_action($item, 'Recebimento iniciado');
            write_items($items);
            append_event($pc, 'inicio', 'Recebimento iniciado');
        }
    } else {
        if (!$item['recebimento_iniciado_em']) {
            respond(['ok' => false, 'error' => 'Inicie o recebimento antes de finalizar'], 409);
        }
        if (!in_array(($item['origem_entrega']??''),['COLETA_DRILLING','ENTREGA_FORNECEDOR'],true))
            respond(['ok'=>false,'error'=>'Informe se foi coleta interna Drilling ou entrega do fornecedor'],422);
        if (empty($item['chaveNF']) && empty($item['fotoNF']) && empty($item['xmlNF']))
            respond(['ok'=>false,'error'=>'Anexe a NF (foto/PDF ou XML) ou registre a chave de acesso antes de finalizar.'],422);
        if (!$item['insumos']) respond(['ok'=>false,'error'=>'Não há itens de compra detalhados. Cadastre as quantidades do PC antes de finalizar.'],422);
        foreach($item['insumos'] as $line) {
          if(empty($line['conferido_etapa'])) respond(['ok'=>false,'error'=>'Confirme a quantidade recebida ou marque Não chegou para TODOS os insumos.'],422);
        }
        $lineStatus=line_completion($item['insumos']);
        if ($lineStatus==='') respond(['ok'=>false,'error'=>'Cadastre os insumos e informe as quantidades realmente entregues antes de finalizar'],422);
        // Cada nova entrega deve registrar incremento positivo em algum insumo.
        $prev=end($item['entregas']);
        if(is_array($prev) && !$item['recebimento_finalizado_em']){
          $old=[]; foreach(($prev['insumos']??[]) as $x) $old[$x['id']]=(float)$x['quantidade_recebida'];
          $more=false;foreach($item['insumos'] as $x)if((float)$x['quantidade_recebida']>($old[$x['id']]??0)+0.00001)$more=true;
          if(!$more)respond(['ok'=>false,'error'=>'Nenhum insumo foi acrescentado nesta nova entrega. Informe a quantidade recebida nesta etapa.'],422);
        }
        // A situação é calculada pelo saldo de cada insumo. Não aceitar status contraditório.
        $item['chegou']=$lineStatus;
        if (!$item['recebimento_finalizado_em']) {
            $item['recebimento_finalizado_em'] = $now;
            $item['recebimento_estado'] = 'finalizado';
            $item['data'] = date('Y-m-d'); // carimbo confirmado exclusivamente na finalização
            if (empty($item['primeira_confirmacao_em'])) $item['primeira_confirmacao_em']=$now;
            // A primeira conferência finalizada (total ou parcial) encerra a condição NOVO.
            $item['novo_pedido_em']=null;
            $lastDelivery=end($item['entregas']);
            $previous=[]; if(is_array($lastDelivery)) foreach(($lastDelivery['insumos']??[]) as $x)$previous[$x['id']]=(float)$x['quantidade_recebida'];
            $detail=[];foreach($item['insumos'] as $x)$detail[]=['id'=>$x['id'],'descricao'=>$x['descricao'],'unidade'=>$x['unidade'],
              'quantidade_pedida'=>$x['quantidade_pedida'],'quantidade_recebida'=>$x['quantidade_recebida'],
              'quantidade_nesta_entrega'=>max(0,$x['quantidade_recebida']-($previous[$x['id']]??0)),
              'quantidade_pendente'=>$x['quantidade_pendente']];
            $item['entregas'][]=['confirmado_em'=>$now,'origem_entrega'=>$item['origem_entrega'],'status'=>$lineStatus,'insumos'=>$detail];
            mark_action($item, 'Recebimento finalizado');
            write_items($items);
            append_event($pc, 'finalizacao', $lineStatus==='PARCIAL' ? 'Pedido chegou e foi conferido parcialmente' : 'Pedido chegou e foi conferido integralmente');
            $GLOBALS['RD_SIDE_EFFECT'] = true;
            // Integração com o Estoque: cria/atualiza a ENTRADA PENDENTE do PC.
            // Não altera saldo, não altera a conferência e não reescreve o pedido.
            try {
                require_once __DIR__.'/estoque_lib.php';
                est_with_lock(function () use ($pc, $item) {
                    $db = est_read();
                    $key = 'PC-' . (int)$pc;
                    // v3.59.1: cada entrega (inclusive a 2ª, 3ª... de um PC parcial) cria ou complementa a entrada
                    // pendente só com o que chegou e ainda não foi lançado no estoque (nunca duplica).
                    if (!empty($item['insumos']) && est_entrada_from_pedido($db, $item, 'sistema:finalizacao_recebimento', true) !== null) {
                        est_write($db);
                    }
                });
            } catch (Throwable $e) {
                // Estoque indisponível NUNCA desfaz um recebimento já gravado.
                error_log('Recebimento salvo; pendencia de entrada no estoque: '.$e->getMessage());
            }
            // NOTIFICAÇÃO PARA TODOS OS LOGADOS (v3.1.0): grava na caixa de
            // entrada de cada dispositivo online e agenda o Web Push. Roda uma
            // única vez por finalização e nunca desfaz o recebimento em caso de erro.
            try {
                require_once __DIR__.'/notify_lib.php';
                $total = 0; $parciais = 0;
                foreach ($item['insumos'] as $ln) {
                    $total++;
                    if ((float)($ln['quantidade_pendente'] ?? 0) > 0.00001) $parciais++;
                }
                notif_publish([
                    'tipo' => $lineStatus === 'PARCIAL' ? 'parcial' : 'recebimento',
                    'titulo' => $lineStatus === 'PARCIAL'
                        ? 'Recebimento parcial • PC '.(int)$pc
                        : 'Conferência finalizada • PC '.(int)$pc,
                    'texto' => $lineStatus === 'PARCIAL'
                        ? 'Chegou parcialmente. '.$parciais.' de '.$total.' insumo(s) com saldo pendente. Toque para abrir o pedido.'
                        : 'Recebimento conferido integralmente ('.$total.' insumo(s)). Toque para abrir o pedido.',
                    'pc' => (int)$pc,
                    'url' => '/?pc='.(int)$pc,
                    'autor' => (function_exists('rd_login_info') && is_array(rd_login_info()) ? rd_login_info()['username'] : 'sistema'),
                    'origem_device' => (string)($_SERVER['HTTP_X_CLIENT_ID'] ?? ''),
                    'dados' => ['status' => $lineStatus, 'insumos' => $total, 'pendentes' => $parciais],
                ]);
            } catch (Throwable $e) {
                // Notificação indisponível não pode desfazer um recebimento já gravado.
                error_log('Recebimento salvo; falha ao publicar notificação: '.$e->getMessage());
            }
            // v3.28.0: e-mail ao solicitante (depois da resposta, para não atrasar o conferente).
            $rcbItem = $item; $rcbStatus = $lineStatus; $rcbPc = (int)$pc;
            $rcbConf = (function_exists('rd_login_info') && is_array(rd_login_info())) ? (string)(rd_login_info()['nome_completo'] ?? rd_login_info()['username'] ?? '') : '';
            register_shutdown_function(function () use ($rcbItem, $rcbStatus, $rcbPc, $rcbConf) {
                rd_liberar_cliente();
                try {
                    require_once __DIR__ . '/alertas_lib.php';
                    $r = rcb_email_recebimento($rcbItem, $rcbStatus, $rcbConf);
                    $log = DATA_DIR . '/emails_recebimento.json';
                    $l = json_decode((string)@file_get_contents($log), true) ?: [];
                    $l[] = ['em' => date('c'), 'pc' => $rcbPc, 'status' => $rcbStatus, 'solicitante' => (string)($rcbItem['solicitante'] ?? '')] + $r;
                    @file_put_contents($log, json_encode(array_slice($l, -500), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                } catch (Throwable $e) { error_log('E-mail de recebimento: ' . $e->getMessage()); }
            });
            // Agenda: se o PC estava programado, a retirada é concluída automaticamente.
            try {
                require_once __DIR__.'/agenda_lib.php';
                ag_concluir_por_recebimento((int)$pc, 'sistema:recebimento_finalizado');
            } catch (Throwable $e) {
                error_log('Recebimento salvo; agenda não concluída: '.$e->getMessage());
            }
            // O aviso Push é emitido só uma vez na primeira finalização, para
            // evitar notificações duplicadas em cliques repetidos.
            try {
                require_once __DIR__.'/push_lib.php';
                push_queue_received($pc, $item['chegou']);
                // Libera o lock de itens antes das requisições HTTPS externas.
                if(isset($lockFile) && is_resource($lockFile)) flock($lockFile, LOCK_UN);
                // O envio síncrono pode levar segundos: fica a cargo do cron (push_worker.php)
                // para não segurar a finalização do recebimento.
                if (empty($GLOBALS['RD_SKIP_PUSH_INLINE'])) { @push_process_queue(2, 1); }
            } catch (Throwable $e) {
                // Falha de Push não pode desfazer um recebimento já gravado.
                error_log('Recebimento salvo; pendência de envio Web Push: '.$e->getMessage());
            }
            $GLOBALS['RD_SIDE_EFFECT'] = false;
        }
    }
    respond(['ok' => true, 'item' => normalize_item($item)]);
}


// Retificação auditada: somente a ÚLTIMA entrega fechada originalmente como parcial.
// Não reabre a confirmação nem dispara Push; preserva o histórico original no próprio pedido.
if ($action === 'correct_partial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)) respond(['ok'=>false,'error'=>'Corpo JSON inválido'],400);
    $pc=(int)($input['pc']??0);
    $responsavel=trim((string)($input['responsavel']??''));
    $motivo=trim((string)($input['motivo']??''));
    $requestedStatus=(string)($input['status']??'');
    if($pc<=0)respond(['ok'=>false,'error'=>'PC inválido'],422);
    if(strlen($responsavel)<3||strlen($responsavel)>120)respond(['ok'=>false,'error'=>'Informe o nome do conferente responsável (3 a 120 caracteres)'],422);
    if(strlen($motivo)<8||strlen($motivo)>1000)respond(['ok'=>false,'error'=>'Informe motivo detalhado da correção (8 a 1000 caracteres)'],422);
    if(!in_array($requestedStatus,['PARCIAL','SIM'],true))respond(['ok'=>false,'error'=>'Selecione Parcialmente entregue ou Recebido integralmente'],422);
    if(!isset($input['insumos'])||!is_array($input['insumos']))respond(['ok'=>false,'error'=>'Quantidades por insumo obrigatórias'],422);
    $items=read_items();$idx=find_index($items,$pc);
    if($idx<0)respond(['ok'=>false,'error'=>'PC não localizado'],404);
    $item=&$items[$idx];$deliveries=$item['entregas'];$count=count($deliveries);
    if(($item['recebimento_estado']??'')!=='finalizado'||!$count)
       respond(['ok'=>false,'error'=>'Apenas entregas já finalizadas podem ser retificadas'],409);
    $last=$deliveries[$count-1];
    if(!isset($input['expected_confirmation']) || !is_string($input['expected_confirmation']) ||
       $input['expected_confirmation']!==($last['confirmado_em']??''))
       respond(['ok'=>false,'error'=>'A última entrega mudou. Atualize a página antes de retificar.'],409);
    if(!isset($input['expected_receipt_count'])||(int)$input['expected_receipt_count']!==$count)
       respond(['ok'=>false,'error'=>'O histórico de entregas mudou. Atualize antes de retificar.'],409);
    // Continua disponível após a primeira correção da mesma parcial, mesmo que tenha sido convertida em SIM.
    $historico=$item['historico_retificacoes']??[];
    $wasPartial=($last['status']??'')==='PARCIAL';
    foreach($historico as $cor) if(($cor['entrega_indice']??-1)===$count-1)$wasPartial=true;
    if(!$wasPartial)respond(['ok'=>false,'error'=>'Somente a última entrega parcial é elegível para retificação'],409);
    $revision=count($historico);
    if(!isset($input['expected_revision'])||!is_numeric($input['expected_revision'])||(int)$input['expected_revision']!==$revision)
       respond(['ok'=>false,'error'=>'Este pedido foi alterado em outro navegador. Atualize antes de corrigir.'],409);
    $previous=[];
    if($count>1)foreach(($deliveries[$count-2]['insumos']??[]) as $ln)$previous[(string)$ln['id']]=(float)($ln['quantidade_recebida']??0);
    $given=[];
    foreach($input['insumos'] as $line){
        if(!is_array($line))respond(['ok'=>false,'error'=>'Insumo inválido'],422);
        $id=(string)($line['id']??'');$raw=$line['quantidade_nesta_entrega']??null;
        if(!preg_match('/^[A-Za-z0-9_-]{1,70}$/',$id)||isset($given[$id])||!is_numeric($raw)||!is_finite((float)$raw))
          respond(['ok'=>false,'error'=>'Quantidades inválidas ou itens duplicados'],422);
        $given[$id]=(float)$raw;
    }
    if(count($given)!==count($item['insumos']))respond(['ok'=>false,'error'=>'Envie exatamente os insumos existentes no pedido'],422);
    $after=[];$newDetails=[];$newLines=[];$positive=false;
    foreach($item['insumos'] as $line){
        $id=$line['id'];if(!array_key_exists($id,$given))respond(['ok'=>false,'error'=>'Falta quantidade do insumo '.$id],422);
        $prior=$previous[$id]??0.0;$thisQty=$given[$id];$ordered=(float)$line['quantidade_pedida'];
        if($thisQty<0 || $prior+$thisQty>$ordered+.00001)
            respond(['ok'=>false,'error'=>'Quantidade corrigida excede o saldo válido: '.$line['descricao']],422);
        $total=round($prior+$thisQty,4);$remain=max(0,round($ordered-$total,4));
        if($thisQty>0)$positive=true;
        $newLine=$line;$newLine['quantidade_recebida']=$total;$newLine['quantidade_pendente']=$remain;$newLines[]=$newLine;
        $newDetails[]=['id'=>$id,'descricao'=>$line['descricao'],'unidade'=>$line['unidade'],
            'quantidade_pedida'=>$ordered,'quantidade_recebida'=>$total,
            'quantidade_nesta_entrega'=>round($thisQty,4),'quantidade_pendente'=>$remain];
        $after[$id]=['descricao'=>$line['descricao'],'anterior'=>$prior,'nesta_entrega'=>$thisQty,'acumulado'=>$total,'pendente'=>$remain];
    }
    if(!$positive)respond(['ok'=>false,'error'=>'Uma entrega finalizada deve conter pelo menos um insumo recebido'],422);
    $calculated=line_completion($newLines);
    if($requestedStatus!==$calculated)respond(['ok'=>false,'error'=>'O status escolhido não corresponde às quantidades: o resultado é '.($calculated==='SIM'?'Recebido integralmente':'Parcialmente entregue')],422);
    $oldSnapshot=['chegou'=>$item['chegou'],'situacao'=>$item['situacao'],
        'insumos'=>$item['insumos'],'entrega'=>$last,'recebimento_finalizado_em'=>$item['recebimento_finalizado_em']];
    $last['insumos']=$newDetails;$last['status']=$calculated;
    $last['retificado_em']=date('c');$last['quantidade_retificacoes']=(int)($last['quantidade_retificacoes']??0)+1;
    $item['entregas'][$count-1]=$last;
    $item['insumos']=$newLines;$item['chegou']=$calculated;
    $newSnapshot=['chegou'=>$calculated,'insumos'=>$newLines,'entrega'=>$last];
    $item['historico_retificacoes'][]=[
        'numero'=>$revision+1,'entrega_indice'=>$count-1,'pc'=>$pc,'feito_em'=>date('c'),
        'responsavel_declarado'=>$responsavel,'motivo'=>$motivo,'antes'=>$oldSnapshot,'depois'=>$newSnapshot
    ];
    // A data original da confirmação NÃO muda. Este é o horário da retificação.
    mark_action($item,'Retificação auditada da entrega parcial');
    write_items($items);
    append_event($pc,'retificacao','Conferência parcial retificada por '.mb_safe_trim($responsavel,65).
      ' · '.($calculated==='SIM'?'Recebido integralmente':'Parcialmente entregue'));
    respond(['ok'=>true,'item'=>normalize_item($item),'revision'=>$revision+1]);
}

// v3.51.3 — Correção de quantidade pelo ADMINISTRADOR em qualquer entrega (parcial ou integral).
// Recalcula os acumulados das entregas seguintes, o status e os saldos; guarda o antes/depois no histórico.
// v3.59.1: corrigir o número do PC (administrador). Já roda dentro do lock de gravação acima.
if ($action === 'pc_renumerar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/pc_renumerar_lib.php';
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['ok' => false, 'error' => 'Dados inválidos'], 400);
    $quem = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
    respond(pcr_executar((int)($input['pc_atual'] ?? 0), (int)($input['pc_novo'] ?? 0), (string)($input['motivo'] ?? ''), $quem, !empty($input['simular'])));
}

if ($action === 'admin_corrigir_recebimento' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)) respond(['ok'=>false,'error'=>'Corpo JSON inválido'],400);
    $pc=(int)($input['pc']??0);
    $motivo=trim((string)($input['motivo']??''));
    $eIdx=isset($input['entrega_indice'])&&is_numeric($input['entrega_indice'])?(int)$input['entrega_indice']:-1;
    if($pc<=0)respond(['ok'=>false,'error'=>'PC inválido'],422);
    if(mb_strlen($motivo)<8||mb_strlen($motivo)>1000)respond(['ok'=>false,'error'=>'Informe o motivo da correção (8 a 1000 caracteres)'],422);
    if(!isset($input['insumos'])||!is_array($input['insumos']))respond(['ok'=>false,'error'=>'Quantidades por insumo obrigatórias'],422);
    $items=read_items();$idx=find_index($items,$pc);
    if($idx<0)respond(['ok'=>false,'error'=>'PC não localizado'],404);
    $item=&$items[$idx];
    $deliveries=array_values($item['entregas']??[]);$count=count($deliveries);
    if(!$count||!$item['insumos'])respond(['ok'=>false,'error'=>'Este pedido ainda não tem entrega finalizada para corrigir'],409);
    if($eIdx<0||$eIdx>=$count)respond(['ok'=>false,'error'=>'Entrega não localizada'],422);
    $historico=$item['historico_retificacoes']??[];
    if(!isset($input['expected_revision'])||!is_numeric($input['expected_revision'])||(int)$input['expected_revision']!==count($historico))
       respond(['ok'=>false,'error'=>'Este pedido foi alterado em outro navegador. Atualize antes de corrigir.'],409);
    if(!isset($input['expected_receipt_count'])||(int)$input['expected_receipt_count']!==$count)
       respond(['ok'=>false,'error'=>'O histórico de entregas mudou. Atualize antes de corrigir.'],409);
    $given=[];
    foreach($input['insumos'] as $line){
        if(!is_array($line))respond(['ok'=>false,'error'=>'Insumo inválido'],422);
        $id=(string)($line['id']??'');$raw=$line['quantidade_nesta_entrega']??null;
        if(!preg_match('/^[A-Za-z0-9_-]{1,70}$/',$id)||isset($given[$id])||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0)
          respond(['ok'=>false,'error'=>'Quantidades inválidas ou itens duplicados'],422);
        $given[$id]=round((float)$raw,4);
    }
    if(count($given)!==count($item['insumos']))respond(['ok'=>false,'error'=>'Envie exatamente os insumos existentes no pedido'],422);
    $orderedBy=[];foreach($item['insumos'] as $ln)$orderedBy[(string)$ln['id']]=(float)$ln['quantidade_pedida'];
    foreach($given as $id=>$q)if(!isset($orderedBy[$id]))respond(['ok'=>false,'error'=>'Insumo não pertence ao pedido: '.$id],422);
    // Quantidade de cada insumo em cada entrega (a partir dos acumulados gravados).
    $perDelivery=[];$prevCum=[];
    foreach($deliveries as $n=>$d){
        $cur=[];foreach(($d['insumos']??[]) as $x)$cur[(string)$x['id']]=(float)($x['quantidade_recebida']??0);
        foreach($orderedBy as $id=>$_){$perDelivery[$n][$id]=round(max(0,($cur[$id]??($prevCum[$id]??0))-($prevCum[$id]??0)),4);}
        foreach($orderedBy as $id=>$_)$prevCum[$id]=$cur[$id]??($prevCum[$id]??0);
    }
    $antesQtd=$perDelivery[$eIdx];
    foreach($given as $id=>$q)$perDelivery[$eIdx][$id]=$q;
    // Recalcula acumulados e valida o limite de cada insumo em todas as entregas.
    $cum=array_fill_keys(array_keys($orderedBy),0.0);$newDeliveries=[];
    foreach($deliveries as $n=>$d){
        $details=[];$positive=false;
        foreach($item['insumos'] as $ln){
            $id=(string)$ln['id'];$q=$perDelivery[$n][$id];$cum[$id]=round($cum[$id]+$q,4);
            if($cum[$id]>$orderedBy[$id]+.00001)
                respond(['ok'=>false,'error'=>'Quantidade acima do pedido em "'.$ln['descricao'].'": acumulado '.rtrim(rtrim(number_format($cum[$id],4,',',''),'0'),',').' de '.rtrim(rtrim(number_format($orderedBy[$id],4,',',''),'0'),',').'.'],422);
            if($q>0)$positive=true;
            $details[]=['id'=>$id,'descricao'=>$ln['descricao'],'unidade'=>$ln['unidade'],'quantidade_pedida'=>$orderedBy[$id],
                'quantidade_recebida'=>$cum[$id],'quantidade_nesta_entrega'=>$q,'quantidade_pendente'=>max(0,round($orderedBy[$id]-$cum[$id],4))];
        }
        if(!$positive)respond(['ok'=>false,'error'=>'A entrega '.($n+1).' ficaria sem nenhum insumo recebido. Mantenha ao menos um item com quantidade.'],422);
        $nd=$d;$nd['insumos']=$details;
        $lines=[];foreach($details as $x)$lines[]=['quantidade_pedida'=>$x['quantidade_pedida'],'quantidade_recebida'=>$x['quantidade_recebida'],'quantidade_pendente'=>$x['quantidade_pendente']];
        $nd['status']=line_completion($lines)?:($d['status']??'PARCIAL');
        $newDeliveries[]=$nd;
    }
    $newLines=[];
    foreach($item['insumos'] as $ln){$id=(string)$ln['id'];$nl=$ln;$nl['quantidade_recebida']=$cum[$id];$nl['quantidade_pendente']=max(0,round($orderedBy[$id]-$cum[$id],4));$newLines[]=$nl;}
    $calculated=line_completion($newLines);
    if($calculated==='')respond(['ok'=>false,'error'=>'Não foi possível calcular o status com estas quantidades'],422);
    $ident=function_exists('rd_identity')?rd_identity():null;
    $quem=$ident['username']??'administrador';
    $oldSnapshot=['chegou'=>$item['chegou'],'situacao'=>$item['situacao']??'','insumos'=>$item['insumos'],'entrega'=>$deliveries[$eIdx]];
    $newDeliveries[$eIdx]['retificado_em']=date('c');
    $newDeliveries[$eIdx]['quantidade_retificacoes']=(int)($deliveries[$eIdx]['quantidade_retificacoes']??0)+1;
    $item['entregas']=$newDeliveries;$item['insumos']=$newLines;$item['chegou']=$calculated;
    $item['historico_retificacoes'][]=[
        'numero'=>count($historico)+1,'tipo'=>'admin','entrega_indice'=>$eIdx,'pc'=>$pc,'feito_em'=>date('c'),
        'responsavel_declarado'=>$quem,'motivo'=>$motivo,'antes'=>$oldSnapshot,
        'depois'=>['chegou'=>$calculated,'insumos'=>$newLines,'entrega'=>$newDeliveries[$eIdx]],
        'quantidades_antes'=>$antesQtd,'quantidades_depois'=>$perDelivery[$eIdx]
    ];
    mark_action($item,'Correção de quantidade pelo administrador');
    write_items($items);
    append_event($pc,'retificacao','Quantidade da entrega '.($eIdx+1).' corrigida pelo administrador '.mb_safe_trim($quem,65).
      ' · '.($calculated==='SIM'?'Recebido integralmente':'Parcialmente entregue'));
    respond(['ok'=>true,'item'=>normalize_item($item),'revision'=>count($historico)+1]);
}

if ($action === 'obras_lista') {   // v3.28.0: obras + setores para o destino do pedido
    require_once __DIR__ . '/rd_obras.php';
    respond(['ok' => true, 'obras' => rd_obras_lista(), 'setores' => RD_SETORES]);
}
if ($action === 'list') {
    $items = read_items();
    respond(['ok' => true, 'version'=>APP_VERSION, 'items' => $items, 'total' => count($items)]);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['pc'])) respond(['ok' => false, 'error' => 'PC obrigatório'], 400);
    $pc = (int)$input['pc'];
    if (array_key_exists('data', $input) || array_key_exists('recebimento_finalizado_em', $input) || array_key_exists('entregas', $input) || array_key_exists('primeira_confirmacao_em',$input))
        respond(['ok'=>false,'error'=>'Confirmação é automática e só ocorre ao finalizar a conferência'],422);
    if ($pc <= 0) respond(['ok' => false, 'error' => 'PC inválido'], 400);
    $items = read_items();
    $idx = find_index($items, $pc);
    // v3.7.4: salvar num PC inexistente criava um registro em branco (sem fornecedor) e
    // contornava a permissão "criar". Cadastro novo só pelo fluxo pc_create.
    if ($idx < 0) respond(['ok' => false, 'error' => 'PC '.$pc.' não encontrado. Use "Cadastrar PC manualmente" para incluir um pedido novo.'], 404);
    $fields = ['urgente','chegou','origem_entrega','qtd','obs','chaveNF','nfResumo','numeroNF','solicitante','material','fornecedor',
      'serieNF','cnpjEmitente','emissaoNF','valorNF','itensNF','origemNF'];
    $changed = [];
    $autoStarted = false;
    $autoReopened = false;
    if (array_key_exists('insumos',$input)) {
      // Evita que duas conferências simultâneas sobrescrevam dados uma da outra.
      if ($idx>=0 && isset($input['expected_updated_at']) &&
          (string)$input['expected_updated_at'] !== (string)($items[$idx]['updated_at']??'')) {
          respond(['ok'=>false,'error'=>'Este pedido foi alterado em outro acesso. Recarregue os dados antes de conferir novamente.'],409);
      }
      $oldLines=$idx>=0?$items[$idx]['insumos']:[];
      $insumos=validate_lines($input['insumos'], $oldLines);
      if ($idx>=0) {
        $item = &$items[$idx];
        $wasFinal = ($item['recebimento_estado']??'')==='finalizado';
        $oldById=[];
        foreach ($oldLines as $oldLine) $oldById[$oldLine['id']]=$oldLine;
        $hasNewCheck=false;
        foreach ($insumos as $line) {
          $oldLine=$oldById[$line['id']]??null;
          $increment=$oldLine && (float)$line['quantidade_recebida']>(float)$oldLine['quantidade_recebida']+0.00001;
          $newCheck=!empty($line['conferido_etapa']) && (!$oldLine || empty($oldLine['conferido_etapa']));
          if ($increment || $newCheck || ($wasFinal && $oldLine &&
              (float)($oldLine['quantidade_pendente']??0)>0.00001 && !empty($line['conferido_etapa']))) $hasNewCheck=true;
        }
        if ($wasFinal) {
          // Um PC totalmente entregue permanece imutável. Uma parcial pode ter nova etapa.
          if (($item['chegou']??'')!=='PARCIAL' || line_completion($oldLines)!=='PARCIAL')
              respond(['ok'=>false,'error'=>'Pedido finalizado integralmente. Não é possível alterar a conferência.'],409);
          if (!$hasNewCheck) respond(['ok'=>false,'error'=>'Registre a quantidade de um insumo pendente para iniciar a nova entrega.'],409);
          $autoReopened=true;
          $autoStarted=true;
          $item['recebimento_estado']='em_andamento';
          $item['recebimento_iniciado_em']=date('c');
          $item['recebimento_finalizado_em']=null;
          $item['data']='';
          $item['origem_entrega']=''; // a origem da entrega anterior fica em entregas[]
          // Os checks da etapa encerrada não podem confirmar automaticamente uma nova etapa.
          foreach ($insumos as &$line) {
              $oldLine=$oldById[$line['id']]??null;
              if ($oldLine && (float)($oldLine['quantidade_pendente']??0)<=0.00001) $line['conferido_etapa']=false;
          }
          unset($line);
        } elseif (($item['recebimento_estado']??'')!=='em_andamento' && empty($item['recebimento_iniciado_em']) && $hasNewCheck) {
          // Começa no MESMO salvamento do primeiro insumo conferido: um único commit.
          $autoStarted=true;
          $item['recebimento_estado']='em_andamento';
          $item['recebimento_iniciado_em']=date('c');
        }
        $item['insumos']=$insumos;
        $changed[]='insumos';
        unset($item);
      }
    }
    if ($idx >= 0) {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                // Campo simples: nunca sobrescrever carimbos de início/fim enviados no body.
                if (!is_scalar($input[$field]) && $input[$field] !== null) respond(['ok'=>false,'error'=>'Campo inválido: '.$field],422);
                $value = trim((string)$input[$field]);
                $max = in_array($field, ['itensNF','obs','material','nfResumo'], true) ? 12000 : 280;
                if (strlen($value) > $max) respond(['ok'=>false,'error'=>'Campo muito extenso: '.$field],422);
                if ($field === 'chaveNF' && $value !== '' && !preg_match('/^[0-9]{44}$/', $value)) respond(['ok'=>false,'error'=>'Chave NF deve conter 44 dígitos'],422);
                if ($field === 'cnpjEmitente' && $value !== '' && !preg_match('/^[0-9]{14}$/', $value)) respond(['ok'=>false,'error'=>'CNPJ deve conter 14 dígitos'],422);
                if ($field === 'chegou' && !in_array($value,['','SIM','PARCIAL','NÃO','EM TRÂNSITO','N/A'],true)) respond(['ok'=>false,'error'=>'Status inválido'],422);
                if ($field === 'chegou' && ($items[$idx]['recebimento_estado'] ?? '') === 'finalizado' && $value !== (string)($items[$idx]['chegou'] ?? '')) respond(['ok'=>false,'error'=>'Recebimento finalizado: a chegada está bloqueada para preservar o histórico.'],409);
                if ($field === 'origem_entrega' && !in_array($value,['','COLETA_DRILLING','ENTREGA_FORNECEDOR'],true)) respond(['ok'=>false,'error'=>'Origem da entrega inválida'],422);
                if ($field === 'origem_entrega' && ($items[$idx]['recebimento_estado']??'')==='finalizado' && $value!==($items[$idx]['origem_entrega']??'')) respond(['ok'=>false,'error'=>'Origem de recebimento finalizado não pode ser alterada'],409);
                if ($field === 'origemNF' && !in_array($value,['','XML','OCR','CHAVE','QRCODE','MANUAL'],true)) respond(['ok'=>false,'error'=>'Origem inválida'],422);
                if ($field === 'urgente') $value = in_array(strtolower($value), ['1','true','sim'], true) ? '1' : '';
                if ($field==='chegou' && !empty($items[$idx]['insumos']) && in_array($value,['SIM','PARCIAL'],true)) respond(['ok'=>false,'error'=>'Status de recebimento de pedido com insumos é automático pelas quantidades'],422);
                if ((string)($items[$idx][$field] ?? '') !== $value) {
                    $items[$idx][$field] = $value;
                    $changed[] = $field;
                }
            }
        }
        if ($changed) {
            mark_action($items[$idx], 'Dados do pedido atualizados');
        }
    } else {
        // Incluir PC novo é mantido por compatibilidade com integrações existentes.
        $new = ['pc'=>$pc,'situacao_origem'=>'PENDENTE', 'novo_pedido_em'=>date('c')];
        foreach ($fields as $field) {
            if (!array_key_exists($field,$input)) continue;
            if (!is_scalar($input[$field]) && $input[$field] !== null) respond(['ok'=>false,'error'=>'Campo inválido: '.$field],422);
            $value = trim((string)$input[$field]);
            $max = in_array($field, ['itensNF','obs','material','nfResumo'],true) ? 12000 : 280;
            if (strlen($value)>$max) respond(['ok'=>false,'error'=>'Campo muito extenso: '.$field],422);
            if ($field==='chegou' && !in_array($value,['','SIM','PARCIAL','NÃO','EM TRÂNSITO','N/A'],true)) respond(['ok'=>false,'error'=>'Status inválido'],422);
            if ($field==='chaveNF' && $value!=='' && !preg_match('/^[0-9]{44}$/',$value)) respond(['ok'=>false,'error'=>'Chave NF deve conter 44 dígitos'],422);
            if ($field==='cnpjEmitente' && $value!=='' && !preg_match('/^[0-9]{14}$/',$value)) respond(['ok'=>false,'error'=>'CNPJ deve conter 14 dígitos'],422);
            if ($field==='origem_entrega' && !in_array($value,['','COLETA_DRILLING','ENTREGA_FORNECEDOR'],true)) respond(['ok'=>false,'error'=>'Origem da entrega inválida'],422);
            if ($field==='origemNF' && !in_array($value,['','XML','OCR','CHAVE','QRCODE','MANUAL'],true)) respond(['ok'=>false,'error'=>'Origem inválida'],422);
            $new[$field]=$value;
        }
        if (isset($insumos)) $new['insumos']=$insumos;
        $items[] = normalize_item($new);
        $changed = ['novo'];
    }
    if ($changed) {
        if ($idx < 0) mark_action($items[count($items)-1], 'Pedido cadastrado');
        elseif ($autoStarted) mark_action($items[$idx], $autoReopened ? 'Nova conferência do saldo iniciada automaticamente' : 'Conferência iniciada automaticamente');
        write_items($items);
        if ($autoStarted) append_event($pc,'inicio',$autoReopened ? 'Nova etapa de recebimento do saldo iniciada automaticamente' : 'Conferência iniciada no primeiro lançamento do insumo');
        $labels = ['urgente'=>'Urgência','insumos'=>'Insumos e quantidades','origem_entrega'=>'Origem da entrega','chegou'=>'Status da chegada','qtd'=>'Quantidade',
            'obs'=>'Observações','chaveNF'=>'Chave da NF','nfResumo'=>'Resumo NF',
            'numeroNF'=>'Número da NF','solicitante'=>'Solicitante',
            'material'=>'Material','fornecedor'=>'Fornecedor',
            'serieNF'=>'Série NF','cnpjEmitente'=>'CNPJ do emitente',
            'emissaoNF'=>'Emissão NF','valorNF'=>'Valor da NF',
            'itensNF'=>'Itens fiscais','origemNF'=>'Origem NF',
            'novo'=>'Cadastro'];
        $safe = array_map(function($f) use ($labels) {return $labels[$f] ?? 'Campo';}, $changed);
        append_event($pc, 'edicao', 'Atualização: '.implode(', ', $safe));
        // v3.51.4: fornecedor/NF corrigidos no pedido valem também para a entrada do estoque (cópia).
        if (in_array('fornecedor', $changed, true) || in_array('numeroNF', $changed, true)) {
            try {
                require_once __DIR__.'/estoque_lib.php';
                $novoForn = (string)($items[$idx]['fornecedor'] ?? ''); $novoNf = (string)($items[$idx]['numeroNF'] ?? '');
                est_with_lock(function () use ($pc, $novoForn, $novoNf) {
                    $db = est_read(); $k = 'PC-'.(int)$pc;
                    if (isset($db['recebimentos'][$k])) {
                        if ($novoForn !== '') $db['recebimentos'][$k]['fornecedor'] = $novoForn;
                        if ($novoNf !== '') $db['recebimentos'][$k]['numero_nf'] = $novoNf;
                        est_write($db);
                    }
                });
            } catch (Throwable $e) { error_log('Fornecedor atualizado no pedido; estoque nao sincronizado: '.$e->getMessage()); }
        }
    }
    $idx = find_index($items, $pc);
    respond(['ok' => true, 'item' => normalize_item($items[$idx])]);
}

// O XML fica em data/xml, protegido pelo Apache; jamais servido diretamente.
if ($action === 'upload_xml' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pc = (int)($_POST['pc'] ?? 0);
    if (!$pc || empty($_FILES['xml'])) respond(['ok'=>false,'error'=>'Informe PC e XML'],400);
    $file = $_FILES['xml'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] < 100 || $file['size'] > 2048*1024) respond(['ok'=>false,'error'=>'XML deve ter até 2 MB'],422);
    $xml = @file_get_contents($file['tmp_name']);
    // Evita comandos externos/DOCTYPE e executáveis; a validação detalhada ocorre no browser.
    if (!is_string($xml) || stripos($xml,'<!DOCTYPE') !== false || stripos($xml,'<!ENTITY') !== false ||
        !preg_match('/<(?:[a-zA-Z0-9_]+:)?(?:nfeProc|NFe)\b/', $xml)) {
        respond(['ok'=>false,'error'=>'Arquivo não reconhecido como NF-e XML'],422);
    }
    $items = read_items(); $idx = find_index($items,$pc);
    if ($idx < 0) respond(['ok'=>false,'error'=>'Pedido inexistente'],404);
    $name = 'pc_'.$pc.'_'.bin2hex(random_bytes(8)).'.xml';
    if (file_put_contents(XML_DIR.'/'.$name,$xml,LOCK_EX) === false) respond(['ok'=>false,'error'=>'Falha ao guardar XML'],500);
    @chmod(XML_DIR.'/'.$name,0640);
    // Apenas um XML atual por PC; remover anterior depois da gravação bem-sucedida.
    $old = basename((string)($items[$idx]['xmlNF']??''));
    $items[$idx]['xmlNF'] = $name;
    mark_action($items[$idx], 'XML fiscal anexado');
    write_items($items);
    if ($old && $old !== $name && is_file(XML_DIR.'/'.$old)) @unlink(XML_DIR.'/'.$old);
    append_event($pc,'anexo','XML da nota fiscal anexado');
    respond(['ok'=>true,'xmlNF'=>$name]);
}

if ($action === 'upload_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pc = (int)($_POST['pc'] ?? 0);
    $tipo = $_POST['tipo'] ?? 'produto';
    $slot = (int)($_POST['slot'] ?? 0);
    if ($slot < 0 || $slot >= MAX_PRODUCT_PHOTOS) respond(['ok'=>false,'error'=>'Posição de foto inválida'],422);
    if (!$pc) respond(['ok' => false, 'error' => 'PC obrigatório'], 400);
    if (empty($_FILES['photo'])) respond(['ok' => false, 'error' => 'Arquivo não enviado'], 400);
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) respond(['ok' => false, 'error' => 'Erro upload'], 400);
    if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_BYTES) respond(['ok' => false, 'error' => 'Arquivo vazio ou maior que 4 MB'], 422);
    if (!is_uploaded_file($file['tmp_name'])) respond(['ok'=>false,'error'=>'Origem do arquivo inválida'],422);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $items = read_items();
    $idx = find_index($items, $pc);
    if ($idx < 0) respond(['ok' => false, 'error' => 'PC não encontrado'], 404);

    if ($mime === 'application/pdf') {
        if ($tipo !== 'nf') respond(['ok' => false, 'error' => 'PDF só para NF'], 400);
        $filename = 'pc_' . $pc . '_nf_' . bin2hex(random_bytes(8)) . '.pdf';
        $dest = PHOTOS_DIR . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['ok' => false, 'error' => 'Falha ao salvar'], 500);
        $previous = $items[$idx]['fotoNF'] ?? '';
        $items[$idx]['fotoNF'] = 'data/photos/' . $filename;
        mark_action($items[$idx], 'Nota fiscal anexada');
        write_items($items);
        if ($previous) delete_photo_file($previous);
        append_event($pc, 'anexo', 'Documento fiscal anexado ou substituído');
        respond(['ok' => true, 'tipo' => 'nf', 'fotoNF' => $items[$idx]['fotoNF']]);
    }

    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        respond(['ok' => false, 'error' => 'Use JPG, PNG ou WEBP'], 400);
    }
    $imageInfo=@getimagesize($file['tmp_name']);
    if (!$imageInfo || $imageInfo[0]<1 || $imageInfo[1]<1 || (float)$imageInfo[0]*(float)$imageInfo[1]>25000000)
        respond(['ok'=>false,'error'=>'Imagem inválida ou dimensão muito grande'],422);

    if ($tipo === 'nf') {
        $filename = 'pc_' . $pc . '_nf_' . bin2hex(random_bytes(8)) . '.jpg';
        $dest = PHOTOS_DIR . '/' . $filename;
        if (!optimize_image($file['tmp_name'], $dest, $mime)) respond(['ok' => false, 'error' => 'Falha otimizar'], 500);
        $previous = $items[$idx]['fotoNF'] ?? '';
        $items[$idx]['fotoNF'] = 'data/photos/' . $filename;
        mark_action($items[$idx], 'Nota fiscal anexada');
        write_items($items);
        if ($previous) delete_photo_file($previous);
        append_event($pc, 'anexo', 'Documento fiscal anexado ou substituído');
        respond(['ok' => true, 'tipo' => 'nf', 'fotoNF' => $items[$idx]['fotoNF']]);
    }

    // produto
    $fotos = $items[$idx]['fotosProdutos'];
    if (count($fotos) >= MAX_PRODUCT_PHOTOS && $slot >= count($fotos)) {
        respond(['ok' => false, 'error' => 'Máximo 3 fotos por pedido'], 400);
    }
    // Se slot aponta para substituir
    if ($slot < count($fotos) && !empty($fotos[$slot])) {
        $previousProductPhoto = $fotos[$slot];
    } elseif (count($fotos) >= MAX_PRODUCT_PHOTOS) {
        respond(['ok' => false, 'error' => 'Máximo 3 fotos por pedido'], 400);
    }

    $filename = 'pc_' . $pc . '_p' . $slot . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $dest = PHOTOS_DIR . '/' . $filename;
    if (!optimize_image($file['tmp_name'], $dest, $mime)) respond(['ok' => false, 'error' => 'Falha otimizar'], 500);

    $path = 'data/photos/' . $filename;
    if ($slot < count($fotos)) {
        $fotos[$slot] = $path;
    } else {
        $fotos[] = $path;
    }
    $items[$idx]['fotosProdutos'] = array_slice(array_values($fotos), 0, MAX_PRODUCT_PHOTOS);
    mark_action($items[$idx], 'Foto do produto anexada');
    write_items($items);
    if (!empty($previousProductPhoto)) delete_photo_file($previousProductPhoto);
    append_event($pc, 'foto', 'Foto de produto anexada ou substituída');
    respond(['ok' => true, 'tipo' => 'produto', 'fotosProdutos' => $items[$idx]['fotosProdutos']]);
}

if ($action === 'remove_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pc = (int)($input['pc'] ?? 0);
    $tipo = $input['tipo'] ?? 'produto';
    $slot = (int)($input['slot'] ?? 0);
    if ($slot < 0 || $slot >= MAX_PRODUCT_PHOTOS) respond(['ok'=>false,'error'=>'Posição inválida'],422);
    if (!$pc) respond(['ok' => false, 'error' => 'PC obrigatório'], 400);
    $items = read_items();
    $idx = find_index($items, $pc);
    if ($idx < 0) respond(['ok' => false, 'error' => 'PC não encontrado'], 404);
    if ($tipo === 'nf') {
        $removedPhoto = $items[$idx]['fotoNF'] ?? '';
        $items[$idx]['fotoNF'] = '';
    } else {
        $fotos = $items[$idx]['fotosProdutos'];
        if (isset($fotos[$slot])) {
            $removedPhoto = $fotos[$slot];
            array_splice($fotos, $slot, 1);
            $items[$idx]['fotosProdutos'] = array_values($fotos);
        }
    }
    mark_action($items[$idx], $tipo === 'nf' ? 'Documento fiscal removido' : 'Foto removida');
    write_items($items);
    if (!empty($removedPhoto)) delete_photo_file($removedPhoto);
    append_event($pc, 'remocao', $tipo === 'nf' ? 'Documento fiscal removido' : 'Foto de produto removida');
    respond(['ok' => true, 'item' => normalize_item($items[$idx])]);
}

/* ---------------------------------------------------------------
 * Pedido de compra cadastrado manualmente (v3.7.1)
 * Usado quando o PC não veio no ZIP do WhatsApp nem em PDF avulso:
 * nota de papel, telefone, e-mail, pedido urgente fora do fluxo.
 * Validações iguais às da importação: PC único, insumos pelo mesmo
 * validador, campos com os mesmos limites. Nunca sobrescreve um PC
 * existente — para isso existe a edição normal do pedido.
 * --------------------------------------------------------------- */
if ($action === 'pc_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['ok'=>false,'error'=>'Dados inválidos'],400);
    $pc = (int)($input['pc'] ?? 0);
    if ($pc <= 0 || $pc > 99999999) respond(['ok'=>false,'error'=>'Informe o número do PC (pedido)'],422);
    $items = read_items();
    if (find_index($items, $pc) >= 0)
        respond(['ok'=>false,'error'=>'PC '.$pc.' já está cadastrado. Abra o pedido para complementar os dados.'],409);
    $fornecedor = mb_safe_trim($input['fornecedor'] ?? '', 280);
    if (mb_strlen($fornecedor) < 2) respond(['ok'=>false,'error'=>'Informe o fornecedor do pedido'],422);
    $novo = ['pc'=>$pc,'fornecedor'=>$fornecedor,'situacao_origem'=>'MANUAL','novo_pedido_em'=>date('c')];
    // campos que a API já valida no save; aqui só normalizamos
    foreach (['material','solicitante','obs','qtd','numeroNF','serieNF','cnpjEmitente','emissaoNF','valorNF','itensNF','nfResumo','chaveNF'] as $f) {
        if (!array_key_exists($f, $input)) continue;
        if (!is_scalar($input[$f]) && $input[$f] !== null) respond(['ok'=>false,'error'=>'Campo inválido: '.$f],422);
        $v = trim((string)$input[$f]);
        $max = in_array($f, ['itensNF','obs','material','nfResumo'], true) ? 12000 : 280;
        if (strlen($v) > $max) respond(['ok'=>false,'error'=>'Campo muito extenso: '.$f],422);
        if ($f === 'chaveNF' && $v !== '' && !preg_match('/^[0-9]{44}$/', $v)) respond(['ok'=>false,'error'=>'Chave NF deve conter 44 dígitos'],422);
        if ($f === 'cnpjEmitente' && $v !== '' && !preg_match('/^[0-9]{14}$/', $v)) respond(['ok'=>false,'error'=>'CNPJ deve conter 14 dígitos'],422);
        $novo[$f] = $v;
    }
    if (array_key_exists('origem_entrega', $input)) {
        $oe = trim((string)$input['origem_entrega']);
        if ($oe !== '' && !in_array($oe, ['COLETA_DRILLING','ENTREGA_FORNECEDOR'], true))
            respond(['ok'=>false,'error'=>'Origem da entrega inválida'],422);
        $novo['origem_entrega'] = $oe;
    }
    $chegou = trim((string)($input['chegou'] ?? ''));
    if ($chegou !== '' && !in_array($chegou, ['','SIM','PARCIAL','NÃO','EM TRÂNSITO','N/A'], true))
        respond(['ok'=>false,'error'=>'Status de chegada inválido'],422);
    $novo['chegou'] = $chegou;
    // insumos: mesmo validador da importação (id, descrição, unidade, quantidades)
    $linhas = $input['insumos'] ?? null;
    if (is_array($linhas) && $linhas) {
        $novo['insumos'] = validate_lines($linhas, []);
    } else {
        $novo['insumos'] = parse_order_lines($novo['material'] ?? '');
    }
    // material é derivado dos insumos quando não foi digitado
    if (($novo['material'] ?? '') === '' && $novo['insumos'])
        $novo['material'] = implode("\n", array_map(fn($i) => rtrim(rtrim(number_format($i['quantidade_pedida'], 4, ',', ''), '0'), ',') . ') ' . $i['descricao'], $novo['insumos']));
    if (($novo['material'] ?? '') === '' && !$novo['insumos'])
        respond(['ok'=>false,'error'=>'Descreva o material ou cadastre ao menos um insumo'],422);
    $novo['importacao_origem'] = 'Cadastro manual';
    $items[] = normalize_item($novo);
    $idx = count($items) - 1;
    mark_action($items[$idx], 'Pedido cadastrado manualmente');
    write_items($items);
    append_event($pc, 'novo_pedido', 'PC cadastrado manualmente (' . mb_safe_trim($fornecedor, 60) . ')');
    respond(['ok'=>true,'item'=>normalize_item($items[$idx]),'mensagem'=>'PC '.$pc.' cadastrado.']);
}

// v3.9: devolução de material comprado ao fornecedor (registro auditado, nunca altera o recebimento).
// v3.28.0: definir manualmente a obra/setor de destino do pedido
if ($action === 'pc_destino' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/rd_obras.php';
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['ok'=>false,'error'=>'Dados inválidos'],400);
    $items = read_items();
    $idx = find_index($items, (int)($input['pc'] ?? 0));
    if ($idx < 0) respond(['ok'=>false,'error'=>'PC não encontrado'],404);
    $setor = strtoupper(trim((string)($input['setor'] ?? '')));
    if ($setor === '') { $items[$idx]['destino_setor'] = ''; $items[$idx]['obra_id'] = ''; $items[$idx]['obra'] = ''; }
    else {
        [$sc, $oid, $rot] = rd_setor_destino($setor, $input['obra_id'] ?? '', $input['obra_nome'] ?? '');
        if ($sc === '') respond(['ok'=>false,'error'=>'Setor inválido'],422);
        $items[$idx]['destino_setor'] = $sc; $items[$idx]['obra_id'] = $oid;
        $items[$idx]['obra'] = $sc === 'OBRA' ? trim(preg_replace('/^OBRA · /u', '', $rot)) : '';
        if ($sc === 'OBRA' && $items[$idx]['obra'] !== '') foreach (rd_obras_lista() as $o) if ($o['id'] === $oid) $items[$idx]['obra'] = $o['name'];
    }
    $items[$idx]['destino_origem'] = 'manual';
    mark_action($items[$idx], 'Destino do pedido: ' . (rd_destino_rotulo($items[$idx]) ?: 'sem destino'));
    write_items($items);
    respond(['ok'=>true,'item'=>normalize_item($items[$idx])]);
}
// ---------------------------------------------------------------------------------------------
// v3.29.0 — QUEM RECEBE O E-MAIL "SEU PEDIDO CHEGOU"
//   pc_avisar_info   (GET)  situação do aviso de um PC + lista de pessoas para escolher
//   pc_avisar        (POST) define os destinatários (ou "ninguém", ou volta ao automático)
//   avisar_pendencias(GET)  PCs que ainda não chegaram e não têm destinatário definido + e-mails que falharam
// Só aceita e-mails que já estão na lista de contatos/funcionários. Nunca envia para sugestão não confirmada.
// ---------------------------------------------------------------------------------------------
if ($action === 'pc_avisar_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/alertas_lib.php';
    $items = read_items();
    $idx = find_index($items, (int)($_GET['pc'] ?? 0));
    if ($idx < 0) respond(['ok'=>false,'error'=>'PC não encontrado'],404);
    $it = $items[$idx];
    respond(['ok'=>true,'pc'=>(int)$it['pc'],'solicitante'=>$it['solicitante'],'observacoes'=>trim($it['observacoes_pc'] . ' ' . $it['obs']),
        'modo'=>$it['avisar_modo'],'avisar_para'=>$it['avisar_para'],'situacao'=>rcb_situacao($it),'contatos'=>rcb_lista_selecao(),
        'ultimo_envio'=>rcb_ultimo_envio((int)$it['pc'])]);   // v3.34.0: o que aconteceu com o e-mail da última finalização
}
if ($action === 'pc_avisar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/alertas_lib.php';
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['ok'=>false,'error'=>'Dados inválidos'],400);
    $items = read_items();
    $idx = find_index($items, (int)($input['pc'] ?? 0));
    if ($idx < 0) respond(['ok'=>false,'error'=>'PC não encontrado'],404);
    if (!empty($input['nenhum'])) {
        $items[$idx]['avisar_modo'] = 'nenhum'; $items[$idx]['avisar_para'] = [];
        $rot = 'ninguém será avisado';
    } else {
        $origem = in_array($input['origem'] ?? '', ['solicitante', 'observacao'], true) ? (string)$input['origem'] : 'manual';
        try { $lista = rcb_validar_emails((array)($input['emails'] ?? []), $origem); }
        catch (InvalidArgumentException $e) { respond(['ok'=>false,'error'=>$e->getMessage()],422); }
        if ($lista) { $items[$idx]['avisar_modo'] = 'definido'; $items[$idx]['avisar_para'] = $lista; $rot = implode(', ', array_column($lista, 'nome')); }
        else { $items[$idx]['avisar_modo'] = ''; $items[$idx]['avisar_para'] = []; $rot = 'automático (pelo solicitante)'; }
    }
    $items[$idx]['avisar_em'] = date('c');
    $items[$idx]['avisar_por'] = (function_exists('rd_login_info') && is_array(rd_login_info())) ? (string)(rd_login_info()['username'] ?? '') : 'sistema';
    mark_action($items[$idx], 'Aviso de chegada: ' . $rot);
    write_items($items);
    $novo = normalize_item($items[$idx]);
    respond(['ok'=>true,'item'=>$novo,'situacao'=>rcb_situacao($novo)]);
}
if ($action === 'avisar_pendencias' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/alertas_lib.php';
    $pend = [];
    foreach (read_items() as $it) {
        if (in_array($it['situacao'], ['FINALIZADO', 'RECEBIDO', 'NÃO SE APLICA'], true)) continue;   // já chegou / não se aplica
        $sit = rcb_situacao($it);
        if (in_array($sit['status'], ['definido', 'ok', 'sem_aviso'], true)) continue;                  // resolvido
        $pend[] = ['pc'=>(int)$it['pc'],'fornecedor'=>$it['fornecedor'],'solicitante'=>$it['solicitante'],'urgente'=>$it['urgente'] === '1',
            'situacao'=>$it['situacao'],'status'=>$sit['status'],'sugestao'=>$sit['destinatarios'],'candidatos'=>$sit['candidatos'],'motivo'=>$sit['motivo']];
    }
    usort($pend, fn($a, $b) => [$b['urgente'], $b['pc']] <=> [$a['urgente'], $a['pc']]);
    $total = count($pend);
    $log = json_decode((string)@file_get_contents(DATA_DIR . '/emails_recebimento.json'), true) ?: [];
    $falhas = array_values(array_filter(array_reverse($log), fn($l) => is_array($l) && empty($l['enviado'])));
    respond(['ok'=>true,'total'=>$total,'pendentes'=>array_slice($pend, 0, 150),'falhas'=>array_slice($falhas, 0, 20),'contatos'=>rcb_lista_selecao()]);
}
if ($action === 'pc_devolucao' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['ok'=>false,'error'=>'Dados inválidos'],400);
    $pc = (int)($input['pc'] ?? 0);
    $items = read_items();
    $idx = find_index($items, $pc);
    if ($idx < 0) respond(['ok'=>false,'error'=>'PC não encontrado'],404);
    $autor = function_exists('rd_login_info') && is_array(rd_login_info()) ? rd_login_info()['username'] : 'operador';
    if (!empty($input['remover'])) {
        $rid = (string)$input['remover'];
        $antes = count($items[$idx]['devolucoes']);
        $items[$idx]['devolucoes'] = array_values(array_filter($items[$idx]['devolucoes'], fn($d) => ($d['id'] ?? '') !== $rid));
        if (count($items[$idx]['devolucoes']) === $antes) respond(['ok'=>false,'error'=>'Devolução não encontrada'],404);
        mark_action($items[$idx], 'Devolução removida');
        write_items($items);
        append_event($pc, 'edicao', 'Devolução removida por ' . $autor);
        respond(['ok'=>true,'item'=>normalize_item($items[$idx])]);
    }
    $valor = round((float)str_replace(',', '.', str_replace('.', '', (string)($input['valor'] ?? '0'))), 2);
    if (is_numeric($input['valor'] ?? null)) $valor = round((float)$input['valor'], 2);
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($input['data'] ?? '')) ? (string)$input['data'] : date('Y-m-d');
    $motivo = mb_safe_trim((string)($input['motivo'] ?? ''), 300);
    $itens = mb_safe_trim((string)($input['itens'] ?? ''), 600);
    if ($valor < 0 || $valor > 100000000) respond(['ok'=>false,'error'=>'Valor inválido'],422);
    if ($motivo === '') respond(['ok'=>false,'error'=>'Informe o motivo da devolução'],422);
    if (count($items[$idx]['devolucoes']) >= 50) respond(['ok'=>false,'error'=>'Limite de devoluções do PC atingido'],422);
    $items[$idx]['devolucoes'][] = ['id'=>'DEV-'.bin2hex(random_bytes(4)), 'data'=>$data, 'valor'=>$valor, 'motivo'=>$motivo,
        'itens'=>$itens, 'registrado_em'=>date('c'), 'usuario'=>$autor];
    mark_action($items[$idx], 'Devolução ao fornecedor registrada');
    write_items($items);
    append_event($pc, 'edicao', 'Devolução ao fornecedor: R$ ' . number_format($valor, 2, ',', '.'));
    respond(['ok'=>true,'item'=>normalize_item($items[$idx])]);
}

if ($action === 'reset') {
    // Uma limpeza remota não autenticada apagaria os registros e as evidências.
    // A função não é utilizada pela interface: bloqueada por segurança.
    respond(['ok' => false, 'error' => 'Operação administrativa desabilitada'], 403);
}

if ($action === 'stats') {
    $items = read_items();
    $s = ['total'=>count($items),'sim'=>0,'nao'=>0,'parcial'=>0,'transito'=>0,'blank'=>0,'com_nf'=>0,'com_fotos'=>0];
    foreach ($items as $i) {
        if ($i['chegou']==='SIM') $s['sim']++;
        elseif ($i['chegou']==='NÃO') $s['nao']++;
        elseif ($i['chegou']==='PARCIAL') $s['parcial']++;
        elseif ($i['chegou']==='EM TRÂNSITO') $s['transito']++;
        else $s['blank']++;
        if ($i['chaveNF']||$i['fotoNF']||$i['nfResumo']) $s['com_nf']++;
        if (!empty($i['fotosProdutos'])) $s['com_fotos']++;
    }
    respond(['ok'=>true,'stats'=>$s]);
}

respond(['ok'=>false,'error'=>'Ação inválida'], 400);
