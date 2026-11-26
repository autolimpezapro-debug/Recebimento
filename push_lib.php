<?php
/** Web Push (RFC 8291/8292) sem Composer; requer openssl + saída HTTPS. */
declare(strict_types=1);
const PUSH_STORE = __DIR__ . '/data';
const PUSH_CONFIG = PUSH_STORE . '/push-vapid.json';
const PUSH_SUBS = PUSH_STORE . '/push-subscriptions.json';
const PUSH_QUEUE = PUSH_STORE . '/push-queue.json';
const PUSH_LOCK = PUSH_STORE . '/.push.lock';
const PUSH_REGRAS = PUSH_STORE . '/push-regras.json';
/** v3.48.0 — módulos que podem enviar push. O administrador escolhe quem recebe cada um. */
const PUSH_MODULOS = [
    'recebimento'  => 'Recebimento de pedidos (conferência finalizada / parcial)',
    'estoque'      => 'Estoque (movimentações e saídas)',
    'estoque_baixo'=> 'Estoque abaixo do mínimo',
    'locacoes'     => 'Locações (devoluções e prazos)',
    'agenda'       => 'Agenda de retiradas',
    'ferramentas'  => 'Ferramentas (empréstimos e devoluções)',
    'obras'        => 'Obras (envios de material)',
    'sistema'      => 'Avisos gerais do sistema',
];
/** Padrão: só o recebimento continua para todos (como era). Os demais ficam desligados até o administrador escolher. */
const PUSH_MODO_PADRAO = ['recebimento' => 'todos'];

function push_b64(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function push_unb64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }
function push_jsonread(string $path, array $default=[]): array {
    if (!is_file($path)) return $default;
    $data=json_decode((string)@file_get_contents($path),true);
    if (!is_array($data)) throw new RuntimeException('Arquivo de configuração de push danificado: '.basename($path));
    return $data;
}
function push_jsonwrite(string $path, array $data): void {
    if(!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
    $tmp=$path.'.tmp';
    if (file_put_contents($tmp,json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX)===false || !rename($tmp,$path)) throw new RuntimeException('Falha ao gravar arquivo de Web Push');
    @chmod($path,0600);
}
function push_locked(callable $callback): mixed {
    if(!is_dir(PUSH_STORE)) mkdir(PUSH_STORE, 0750, true);
    $fp=fopen(PUSH_LOCK,'c');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Falha ao bloquear notificações');
    try { return $callback(); } finally { flock($fp,LOCK_UN); fclose($fp); }
}
function push_pubraw(array $details): string {
    $ec=$details['ec'] ?? [];
    if (!isset($ec['x'],$ec['y']) || strlen($ec['x'])!==32 || strlen($ec['y'])!==32) throw new RuntimeException('Curva EC P-256 indisponível');
    return "\x04".$ec['x'].$ec['y'];
}
function push_key_config(): array {
    return push_locked(function(){
        if (is_file(PUSH_CONFIG)) return push_jsonread(PUSH_CONFIG);
        if (!function_exists('openssl_pkey_new')) throw new RuntimeException('PHP sem OpenSSL');
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if (!$key || !openssl_pkey_export($key,$pem)) throw new RuntimeException('Falha criando VAPID');
        $conf=['publicKey'=>push_b64(push_pubraw(openssl_pkey_get_details($key))), 'privatePem'=>$pem, 'createdAt'=>date('c')];
        push_jsonwrite(PUSH_CONFIG,$conf);
        return $conf;
    });
}
function push_endpoint_allowed(string $url): bool {
    if (strlen($url)<25 || strlen($url)>2048) return false;
    $p=parse_url($url);
    if (!is_array($p) || ($p['scheme']??'')!=='https' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || !isset($p['host'])) return false;
    $host=strtolower($p['host']);
    return in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com','push.services.mozilla.com'],true)
      || preg_match('/^([a-z0-9-]+\.)*notify\.windows\.com$/D',$host)===1
      || preg_match('/^([a-z0-9-]+\.)+push\.apple\.com$/D',$host)===1;
}
function push_validate_subscription(array $data): array {
    $endpoint=$data['endpoint']??''; $pub=$data['keys']['p256dh']??''; $auth=$data['keys']['auth']??'';
    if (!is_string($endpoint)||!push_endpoint_allowed($endpoint)||!is_string($pub)||!is_string($auth)) throw new InvalidArgumentException('Assinatura de navegador inválida');
    $pubRaw=push_unb64($pub); $authRaw=push_unb64($auth);
    if ($pubRaw===false||strlen($pubRaw)!==65||$pubRaw[0]!=="\x04" || $authRaw===false||strlen($authRaw)!==16) throw new InvalidArgumentException('Chaves de assinatura inválidas');
    return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$pub,'auth'=>$auth],'updatedAt'=>date('c')];
}
function push_regras_ler(): array {
    $raw=push_jsonread(PUSH_REGRAS);
    $out=[];
    foreach (PUSH_MODULOS as $m=>$_) {
        $r=is_array($raw[$m]??null)?$raw[$m]:[];
        $modo=in_array($r['modo']??'',['todos','ninguem','escolhidos'],true)?$r['modo']:(PUSH_MODO_PADRAO[$m]??'ninguem');
        $us=array_values(array_unique(array_filter(array_map('strval',(array)($r['usuarios']??[])))));
        $out[$m]=['modo'=>$modo,'usuarios'=>$us];
    }
    return $out;
}
function push_regras_salvar(array $in, string $por): array {
    $cur=push_regras_ler(); $new=$cur;
    foreach (PUSH_MODULOS as $m=>$_) {
        if (!isset($in[$m]) || !is_array($in[$m])) continue;
        $modo=(string)($in[$m]['modo']??'');
        if (!in_array($modo,['todos','ninguem','escolhidos'],true)) throw new InvalidArgumentException('Modo inválido em '.$m);
        $us=[];
        foreach ((array)($in[$m]['usuarios']??[]) as $u) {
            $u=trim((string)$u);
            if ($u==='') continue;
            if (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/',$u)) throw new InvalidArgumentException('Usuário inválido: '.$u);
            $us[strtolower($u)]=$u;
        }
        $new[$m]=['modo'=>$modo,'usuarios'=>array_values($us)];
    }
    $new['_atualizado_em']=date('c'); $new['_atualizado_por']=mb_substr($por,0,64);
    push_locked(function() use ($new){ push_jsonwrite(PUSH_REGRAS,$new); });
    return push_regras_ler();
}
/** Quais inscrições (ids) devem receber o push deste módulo. */
function push_destinos(string $modulo, array $subs): array {
    $regras=push_regras_ler();
    $r=$regras[isset(PUSH_MODULOS[$modulo])?$modulo:'sistema'];
    if ($r['modo']==='todos') return array_keys($subs);
    if ($r['modo']==='ninguem') return [];
    $ok=array_map('strtolower',$r['usuarios']); $ids=[];
    foreach ($subs as $id=>$sub) if (isset($sub['usuario']) && in_array(strtolower((string)$sub['usuario']),$ok,true)) $ids[]=$id;
    return $ids;
}
function push_subscribe(array $data, string $usuario=''): array {
    $sub=push_validate_subscription($data);
    return push_locked(function() use ($sub,$usuario){
        $list=push_jsonread(PUSH_SUBS);
        $id=hash('sha256',$sub['endpoint']);
        if(!isset($list[$id]) && count($list)>=500) throw new RuntimeException('Limite de dispositivos inscritos atingido');
        // v3.48.0: a inscrição passa a lembrar de quem é (para o push por módulo). Sem sessão, mantém o dono anterior.
        $sub['usuario']=$usuario!=='' ? mb_substr($usuario,0,64) : (string)($list[$id]['usuario']??'');
        $list[$id]=$sub;
        push_jsonwrite(PUSH_SUBS,$list);
        return ['ok'=>true, 'subscriptions'=>count($list)];
    });
}
function push_unsubscribe(string $endpoint): array {
    return push_locked(function() use ($endpoint){
        $list=push_jsonread(PUSH_SUBS); unset($list[hash('sha256',$endpoint)]);
        push_jsonwrite(PUSH_SUBS,$list);
        return ['ok'=>true];
    });
}
function push_queue_received(int $pc, string $arrival): void {
    push_locked(function() use ($pc,$arrival){
        $list=push_jsonread(PUSH_QUEUE);
        $subs=push_jsonread(PUSH_SUBS);
        if (!$subs) return; // Não retroenviar eventos anteriores ao consentimento.
        $alvos=push_destinos('recebimento',$subs); if (!$alvos) return;
        $list[]= ['id'=>bin2hex(random_bytes(12)), 'created'=>time(), 'pc'=>$pc, 'arrival'=>$arrival,'targets'=>$alvos,'sent'=>[],'failed'=>[]];
        $list=array_slice($list,-250);
        push_jsonwrite(PUSH_QUEUE,$list);
    });
}
function push_hkdf_expand(string $prk,string $info,int $len):string {
    return substr(hash_hmac('sha256',$info."\x01",$prk,true),0,$len);
}
/** Retorna corpo HTTP cifrado aes128gcm, incluindo o cabeçalho de conteúdo RFC 8188. */
function push_encrypt(array $sub,string $message): string {
    $ua=push_unb64($sub['keys']['p256dh']); $auth=push_unb64($sub['keys']['auth']);
    $ephemeral=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    if (!$ephemeral) throw new RuntimeException('Falha ao gerar chave ECDH');
    $as=push_pubraw(openssl_pkey_get_details($ephemeral));
    $uaPem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$ua),64,"\n")."-----END PUBLIC KEY-----\n";
    $pub=openssl_pkey_get_public($uaPem);
    if (!$pub) throw new RuntimeException('Chave pública de usuário inválida');
    $secret=openssl_pkey_derive($pub,$ephemeral,32);
    if (!$secret) throw new RuntimeException('Falha em ECDH');
    $prkKey=hash_hmac('sha256',$secret,$auth,true);
    $ikm=push_hkdf_expand($prkKey,"WebPush: info\x00".$ua.$as,32);
    $salt=random_bytes(16); $prk=hash_hmac('sha256',$ikm,$salt,true);
    $cek=push_hkdf_expand($prk,"Content-Encoding: aes128gcm\x00",16);
    $nonce=push_hkdf_expand($prk,"Content-Encoding: nonce\x00",12);
    $cipher=openssl_encrypt($message."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag);
    if ($cipher===false) throw new RuntimeException('Falha ao cifrar');
    return $salt.pack('N',4096).chr(65).$as.$cipher.$tag;
}
function push_der_to_raw(string $der): string {
    $offset=0;
    if (ord($der[$offset++])!==0x30) throw new RuntimeException('Assinatura inválida');
    $len=ord($der[$offset++]); if($len>0x80){$n=$len&0x7f;$offset+=$n;}
    $values=[];
    for($i=0;$i<2;$i++){
        if(ord($der[$offset++])!==0x02)throw new RuntimeException('Assinatura EC inválida');
        $n=ord($der[$offset++]); $v=substr($der,$offset,$n);$offset+=$n;
        $v=ltrim($v,"\x00"); if(strlen($v)>32)throw new RuntimeException('Assinatura EC extensa');
        $values[]=str_pad($v,32,"\x00",STR_PAD_LEFT);
    }
    return implode('',$values);
}
function push_jwt(string $endpoint,array $conf): string {
    $p=parse_url($endpoint);
    $aud=$p['scheme'].'://'.$p['host'];
    $header=push_b64('{"typ":"JWT","alg":"ES256"}');
    $body=push_b64(json_encode(['aud'=>$aud,'exp'=>time()+3600,'sub'=>'https://recebimento.autolimpezapro.com.br/'],JSON_UNESCAPED_SLASHES));
    $unsigned=$header.'.'.$body;
    $private=openssl_pkey_get_private($conf['privatePem']);
    if (!$private || !openssl_sign($unsigned,$der,$private,OPENSSL_ALGO_SHA256)) throw new RuntimeException('Falha na assinatura VAPID');
    return $unsigned.'.'.push_b64(push_der_to_raw($der));
}
/**
 * v3.47.0 — Enfileira Web Push para QUALQUER notificação do sistema (estoque, alertas, locações, agenda, avisos).
 * O envio vai para todos os dispositivos inscritos, estejam logados ou não: a inscrição é do aparelho.
 */
function push_queue_notificacao(array $n): void {
    push_locked(function() use ($n){
        $subs=push_jsonread(PUSH_SUBS);
        if (!$subs) return;
        $alvos=push_destinos((string)($n['modulo']??'sistema'),$subs); if (!$alvos) return;
        $url=(string)($n['url']??'');
        if ($url==='' || $url[0]!=='/' || (isset($url[1]) && $url[1]==='/')) $url='/';
        $list=push_jsonread(PUSH_QUEUE);
        $list[]=['id'=>bin2hex(random_bytes(12)),'created'=>time(),'pc'=>(int)($n['pc']??0),'arrival'=>'',
            'title'=>mb_substr((string)($n['titulo']??'Recebimento Drilling'),0,120),
            'body'=>mb_substr((string)($n['texto']??''),0,300),'url'=>$url,'tag'=>'notif-'.(int)($n['id']??0),
            'targets'=>$alvos,'sent'=>[],'failed'=>[]];
        push_jsonwrite(PUSH_QUEUE,array_slice($list,-400));
    });
}
function push_send(array $sub,array $conf,array $payload): int {
    $url=$sub['endpoint'];
    if(!push_endpoint_allowed($url)) return 410;
    $body=push_encrypt($sub,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $headers=['TTL: 86400','Content-Type: application/octet-stream','Content-Encoding: aes128gcm',
        'Authorization: vapid t='.push_jwt($url,$conf).', k='.$conf['publicKey']];
    if (function_exists('curl_init')) {
        $c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
        curl_exec($c); $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        return $status;
    }
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>5,'ignore_errors'=>true,'follow_location'=>0],
      'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    @file_get_contents($url,false,$ctx);
    $first=$http_response_header[0]??'';
    return preg_match('~^HTTP/\S+\s+(\d{3})~',$first,$m)?(int)$m[1]:0;
}
/** Processa lote, para ser chamado pela requisição e pelo cron. Não inventa sucesso sem resposta HTTP 201/202. */
function push_payload_do_job(array $job): array {
    if (isset($job['title'])) return ['title'=>$job['title'],'body'=>$job['body']??'','pc'=>($job['pc']??0)?:null,'url'=>$job['url']??'/','tag'=>$job['tag']??('notif-'.$job['id'])];
    return ['title'=>'Recebimento Drilling • PC '.$job['pc'],
        'body'=>(($job['arrival']??'')==='PARCIAL'?'Pedido chegou: conferência parcial finalizada. Confira os insumos pendentes.':'Pedido chegou e foi conferido integralmente. Toque para abrir o recebimento.'),
        'pc'=>$job['pc'],'url'=>'/?pc='.$job['pc'],'tag'=>'recebimento-'.$job['id']];
}
/**
 * Processa a fila em 3 etapas para NÃO segurar a trava durante a rede (v3.48.1):
 *  1) sob trava (rápido): escolhe o lote e marca "em envio";  2) sem trava: envia;  3) sob trava (rápido): grava os resultados.
 * Assim, salvar um recebimento nunca espera outro processo que está falando com o Chrome/Firefox.
 */
function push_process_queue(int $max=20,int $seconds=5): array {
    $lote=push_locked(function() use ($max){
        $jobs=push_jsonread(PUSH_QUEUE); $subs=push_jsonread(PUSH_SUBS);
        if (!$jobs) return ['vazio'=>true,'subs'=>count($subs),'pend'=>0];
        $conf=push_jsonread(PUSH_CONFIG); if(!isset($conf['privatePem'])) throw new RuntimeException('VAPID ainda não inicializado');
        $pares=[]; $agora=time();
        foreach ($jobs as &$job) {
            if ($agora-($job['created']??0)>86400) { $job['done']=true; continue; }
            foreach ($subs as $id=>$sub) {
                if (!in_array($id,$job['targets']??[],true)) continue;
                if (isset($job['sent'][$id]) || (int)($job['failed'][$id]??0)>=3) continue;
                if ((int)($job['inflight'][$id]??0) > $agora-90) continue;   // outro processo já está enviando este par
                if (count($pares)>=$max) break 2;
                $job['inflight'][$id]=$agora;
                $pares[]=['job'=>$job['id'],'sub'=>$id,'payload'=>push_payload_do_job($job),'dados'=>$sub];
            }
        }
        unset($job);
        // poda jobs vencidos/esgotados mesmo quando não há nada a enviar (a fila nunca fica crescendo)
        $manter=array_values(array_filter($jobs,function($job) use ($subs){
            if (!empty($job['done'])) return false;
            foreach ($subs as $id=>$sub) { if (in_array($id,$job['targets']??[],true) && empty($job['sent'][$id]) && ($job['failed'][$id]??0)<3) return true; }
            return false;
        }));
        if ($pares) push_jsonwrite(PUSH_QUEUE,$jobs);
        elseif (count($manter)!==count($jobs)) push_jsonwrite(PUSH_QUEUE,$manter);
        return ['pares'=>$pares,'conf'=>$conf,'subs'=>count($subs),'pend'=>count($manter)];
    });
    if (!empty($lote['vazio'])) return ['sent'=>0,'pending'=>0,'subscriptions'=>$lote['subs']];
    if (empty($lote['pares'])) return ['sent'=>0,'pending'=>$lote['pend'],'subscriptions'=>$lote['subs']];
    // 2) envio, sem trava
    $res=[]; $inicio=microtime(true);
    foreach ($lote['pares'] as $par) {
        if (microtime(true)-$inicio>=$seconds) { $res[]=[$par['job'],$par['sub'],null]; continue; }   // sem tempo: devolve para a fila
        try { $r=push_send($par['dados'],$lote['conf'],$par['payload']); }
        catch (Throwable $e) { error_log('Push falhou: '.$e->getMessage()); $r=0; }
        $res[]=[$par['job'],$par['sub'],$r];
    }
    // 3) resultados, sob trava
    return push_locked(function() use ($res){
        $jobs=push_jsonread(PUSH_QUEUE); $subs=push_jsonread(PUSH_SUBS); $total=0;
        $porId=[]; foreach ($jobs as $k=>$j) $porId[$j['id']]=$k;
        foreach ($res as [$jid,$sid,$r]) {
            if (!isset($porId[$jid])) continue;
            $job=&$jobs[$porId[$jid]];
            unset($job['inflight'][$sid]);
            if ($r===null) { unset($job); continue; }
            if ($r>=200 && $r<300) { $job['sent'][$sid]=true; $total++; }
            elseif ($r===404 || $r===410) { unset($subs[$sid]); $job['sent'][$sid]=true; }
            else { $job['failed'][$sid]=(int)($job['failed'][$sid]??0)+1; }
            unset($job);
        }
        $jobs=array_values(array_filter($jobs,function($job) use ($subs){
            if (!empty($job['done'])) return false;
            foreach ($subs as $id=>$sub) { if (in_array($id,$job['targets']??[],true) && empty($job['sent'][$id]) && ($job['failed'][$id]??0)<3) return true; }
            return false;
        }));
        push_jsonwrite(PUSH_QUEUE,$jobs); push_jsonwrite(PUSH_SUBS,$subs);
        return ['sent'=>$total,'pending'=>count($jobs),'subscriptions'=>count($subs)];
    });
}
