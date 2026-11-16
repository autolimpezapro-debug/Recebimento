<?php
/** Controle de acesso exclusivo à importação de pedidos WhatsApp. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
const IMPORT_PASSWORD_HASH = '$2y$10$HklKi2CAxTocCzuZEK5LN.ggrFhXxJI.3Ulw.K4ANLT75dcC2WC4.';
const IMPORT_SESSION_IDLE = 3600; // uma hora sem atividade
const IMPORT_ATTEMPT_WINDOW = 900;
const IMPORT_MAX_ATTEMPTS = 7;

function imp_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_strict_mode','1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('rd_import_sid');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) respond(['ok'=>false,'error'=>'Sessão indisponível.'],503);
}
function imp_same_origin(): void {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    if (!$origin || !$host || !hash_equals($scheme.'://'.$host,$origin))
        respond(['ok'=>false,'error'=>'Ação permitida somente a partir do sistema.'],403);
}
function imp_is_authorized(): bool {
    imp_start_session();
    if (empty($_SESSION['import_auth']) || (int)($_SESSION['import_last_seen']??0)<time()-IMPORT_SESSION_IDLE) {
        unset($_SESSION['import_auth'],$_SESSION['import_last_seen'],$_SESSION['import_csrf']);
        return false;
    }
    $_SESSION['import_last_seen']=time();
    return true;
}
function imp_authorize_action(): void {
    if (!imp_is_authorized()) respond(['ok'=>false,'error'=>'Autorização de importação expirada. Digite a senha novamente.','code'=>'IMPORT_LOCKED'],401);
    imp_same_origin();
    $token=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');
    if (!$token || !hash_equals((string)($_SESSION['import_csrf']??''),$token))
        respond(['ok'=>false,'error'=>'Autorização inválida. Reabra a importação.','code'=>'IMPORT_LOCKED'],403);
}
/** Contador por IP; arquivo fora do acesso HTTP e com travamento de escrita. */
function imp_check_attempts(string $mode): bool {
    $path=DATA_DIR.'/import_auth_attempts.json';
    $f=@fopen($path,'c+');
    if (!$f || !flock($f,LOCK_EX)) respond(['ok'=>false,'error'=>'Proteção de acesso indisponível.'],503);
    $raw=stream_get_contents($f);
    $entries=json_decode($raw?:'{}',true);if(!is_array($entries))$entries=[];
    $now=time();$key=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));
    foreach($entries as $ip=>$attempts){
        if(!is_array($attempts)){$entries[$ip]=[];continue;}
        $entries[$ip]=array_values(array_filter($attempts,fn($at)=>is_numeric($at) && (int)$at > $now-IMPORT_ATTEMPT_WINDOW));
        if(!$entries[$ip])unset($entries[$ip]);
    }
    $blocked=count($entries[$key]??[])>=IMPORT_MAX_ATTEMPTS;
    if ($mode==='fail' && !$blocked) $entries[$key][]=$now;
    if ($mode==='clear' && !$blocked) unset($entries[$key]);
    rewind($f);$ok=ftruncate($f,0)!==false && fwrite($f,json_encode($entries))!==false && fflush($f);
    flock($f,LOCK_UN);fclose($f);
    if (!$ok) respond(['ok'=>false,'error'=>'Proteção de acesso indisponível.'],503);
    return !$blocked;
}
function imp_auth_route(string $action): void {
    if($action==='import_auth_status'){
        if(($_SERVER['REQUEST_METHOD']??'')!=='GET')respond(['ok'=>false,'error'=>'Método inválido.'],405);
        $enabled=imp_is_authorized();
        respond(['ok'=>true,'authorized'=>$enabled,'csrf'=>$enabled?$_SESSION['import_csrf']:null,'idle_minutes'=>IMPORT_SESSION_IDLE/60]);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')respond(['ok'=>false,'error'=>'Método inválido.'],405);
    imp_same_origin();imp_start_session();
    $payload=json_decode(file_get_contents('php://input'),true);
    if (!is_array($payload))respond(['ok'=>false,'error'=>'Solicitação inválida.'],400);
    if ($action==='import_auth_logout'){
        imp_authorize_action();
        unset($_SESSION['import_auth'],$_SESSION['import_last_seen'],$_SESSION['import_csrf']);
        session_regenerate_id(true);
        respond(['ok'=>true]);
    }
    if($action==='import_auth_login'){
        // NUNCA enviar a senha de volta ao navegador, escrever em log ou colocar em URL.
        if(!imp_check_attempts('check'))respond(['ok'=>false,'error'=>'Muitas tentativas. Tente novamente em 15 minutos.'],429);
        $pw=$payload['password']??null;
        if(!is_string($pw)||strlen($pw)>128||!password_verify($pw,IMPORT_PASSWORD_HASH)){
            imp_check_attempts('fail');
            respond(['ok'=>false,'error'=>'Senha incorreta.'],401);
        }
        imp_check_attempts('clear');
        session_regenerate_id(true);
        $_SESSION['import_auth']=true;
        $_SESSION['import_last_seen']=time();
        $_SESSION['import_csrf']=bin2hex(random_bytes(32));
        respond(['ok'=>true,'authorized'=>true,'csrf'=>$_SESSION['import_csrf']]);
    }
    respond(['ok'=>false,'error'=>'Ação inválida.'],404);
}
