<?php
/** Recebimento Drilling — autenticação operacional, isolada da API de backup. */
declare(strict_types=1);
const RD_USER_FILE=__DIR__.'/data/operational_users.json';
const RD_ADMIN_FILE=__DIR__.'/data/backup_admin_auth.json';
const RD_AUTH_IDLE=3600;
function rd_auth_send(array $body,int $http=200): never {http_response_code($http);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function rd_auth_session():void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 ini_set('session.use_only_cookies','1'); ini_set('session.use_strict_mode','1');
 session_name('rd_operation');$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
 session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
 if(!session_start())rd_auth_send(['ok'=>false,'error'=>'Não foi possível iniciar sessão'],503);
}
function rd_user_data():array { $raw=@file_get_contents(RD_USER_FILE);$data=$raw?json_decode($raw,true):null;return is_array($data)?$data:[]; }
function rd_admin_data():array {$raw=@file_get_contents(RD_ADMIN_FILE);$data=$raw?json_decode($raw,true):null;return is_array($data)?$data:[];}
function rd_login_info():?array {
 rd_auth_session();$s=$_SESSION['rd_login']??null;
 if(!is_array($s)||time()-(int)($s['last']??0)>RD_AUTH_IDLE){unset($_SESSION['rd_login'],$_SESSION['rd_token']);return null;}
 $name=(string)($s['username']??'');$role=(string)($s['role']??'');
 if($role==='gestor'&&$name===(string)(rd_admin_data()['username']??'adm')){
  if(!is_file(RD_ADMIN_FILE)||!empty(rd_admin_data()['must_change_password']))return null;
 }else{$users=rd_user_data();$row=$users[$name]??null;
  if(!is_array($row)||empty($row['enabled'])||!empty($row['bloqueado'])||($row['role']??null)!==$role)return null;}
 if($role==='gestor'&&$name===(string)(rd_admin_data()['username']??'adm')&&!hash_equals((string)($s['admin_hash']??''),(string)(rd_admin_data()['hash']??'')))return null;
 $_SESSION['rd_login']['last']=time();return ['username'=>$name,'role'=>$role];
}
function rd_auth_role():string {return rd_login_info()['role']??'';}
function rd_origin_valid():bool {
 $host=(string)($_SERVER['HTTP_HOST']??'');$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
 $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
 return $host!=='' && $origin!=='' && hash_equals($scheme.'://'.$host,$origin);
}
function rd_auth_write_guard():void {
 if(!rd_origin_valid())rd_auth_send(['ok'=>false,'error'=>'Origem não autorizada'],403);
 $csrf=(string)($_SERVER['HTTP_X_RD_CSRF']??'');
 if($csrf===''||!hash_equals((string)($_SESSION['rd_token']??''),$csrf))rd_auth_send(['ok'=>false,'error'=>'CSRF inválido; recarregue e entre novamente'],403);
}
function rd_auth_gate(string $group,bool $write=false):void {
 $identity=rd_login_info();
 if(!$identity)rd_auth_send(['ok'=>false,'error'=>'Autenticação necessária. Entre no Recebimento Drilling.'],401);
 $allowed=match($group){'admin'=>['gestor'],'import'=>['gestor','importador'],'write'=>['gestor','conferente'],default=>['gestor','conferente','consulta','importador']};
 if(!in_array($identity['role'],$allowed,true))rd_auth_send(['ok'=>false,'error'=>'Seu perfil não permite esta operação'],403);
 if($write)rd_auth_write_guard();
}
function rd_auth_route(string $action):never {
 rd_auth_session();
 if($action==='auth_status'){
  $identity=rd_login_info();
  $cfgFile=__DIR__.'/data/acesso_config.json';
  $exigir=is_file($cfgFile) && !empty(json_decode((string)@file_get_contents($cfgFile),true)['exigir_login']);
  if($identity===null){rd_auth_send(['ok'=>true,'authenticated'=>false,'user'=>null,'csrf'=>null,'admin_setup_required'=>!is_file(RD_ADMIN_FILE),'exigir_login'=>$exigir]);}
  $out=['ok'=>true,'authenticated'=>true,'user'=>$identity,'csrf'=>$_SESSION['rd_token']??null,'admin_setup_required'=>false];
  if(is_file(__DIR__.'/perm_lib.php')){
   require_once __DIR__.'/perm_lib.php';
   $db=rd_perm_read();$perfil=rd_user_profile($db,$identity['username']);
   $u=$db['usuarios'][$identity['username']]??null;
   $mat=[];
   foreach(RD_MODULOS as $m=>$_){$mat[$m]=[];
    foreach(RD_OPERACOES as $op=> $__){$v=$u['perm'][$m][$op] ?? $perfil['perm'][$m][$op] ?? false;$mat[$m][$op]=(bool)$v;}}
   $out['exigir_login']=$exigir;
   $out['permissoes']=$mat;
   $out['perfil']=$perfil['nome']??'— sem perfil —';
   $out['perfil_id']=$u['perfil']??'';
   $out['escopo']=rd_escopo();
   $out['eh_admin_principal']=rd_e_admin_principal($identity['username']);
   $out['nome_completo']=$u['nome']??$identity['username'];
  }
  rd_auth_send($out);
 }
 if($action==='auth_users'){
  if(($_SERVER['REQUEST_METHOD']??'')!=='GET')rd_auth_send(['ok'=>false,'error'=>'Método não permitido'],405);
  rd_auth_gate('admin');$all=rd_user_data();$rows=[];foreach($all as $name=>$row)$rows[]=['username'=>$name,'role'=>$row['role'],'enabled'=>!empty($row['enabled'])];rd_auth_send(['ok'=>true,'users'=>$rows]);
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')rd_auth_send(['ok'=>false,'error'=>'Método não permitido'],405);
 if(!rd_origin_valid())rd_auth_send(['ok'=>false,'error'=>'Origem não autorizada'],403);
 if($action==='auth_login'){
  $body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))rd_auth_send(['ok'=>false,'error'=>'Solicitação inválida'],422);
  $name=trim((string)($body['username']??''));$pw=(string)($body['password']??'');
  if(strlen($name)>60||strlen($pw)>128)rd_auth_send(['ok'=>false,'error'=>'Credenciais inválidas'],401);
  // Controle de tentativas com arquivo local protegido.
  $attempts=__DIR__.'/data/operation_login_attempts.json';$fp=@fopen($attempts,'c+');if(!$fp||!flock($fp,LOCK_EX))rd_auth_send(['ok'=>false,'error'=>'Proteção de acesso indisponível'],503);
  $log=json_decode(stream_get_contents($fp)?:'{}',true);if(!is_array($log))$log=[];
  $key=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));$now=time();$log[$key]=array_values(array_filter((array)($log[$key]??[]),fn($t)=>is_numeric($t)&&(int)$t>$now-900));
  $blocked=count($log[$key])>=6;
  $admin=rd_admin_data();$users=rd_user_data();$role='';
  if(!$blocked){if($name!==''&&$name===(string)($admin['username']??'adm')&&empty($admin['must_change_password'])&&is_string($admin['hash']??null)&&password_verify($pw,$admin['hash']))$role='gestor';
   elseif(!empty($users[$name]['enabled'])&&is_string($users[$name]['hash']??null)&&password_verify($pw,$users[$name]['hash']))$role=(string)$users[$name]['role'];}
  if(!$role){$log[$key][]=$now;}else{unset($log[$key]);
  if($role!=='gestor'||$name!==(string)($admin['username']??'adm')){
   $fp2=@fopen(RD_USER_FILE,'c+');
   if($fp2&&flock($fp2,LOCK_EX)){$l2=json_decode((string)(stream_get_contents($fp2)?:'{}'),true);
    if(is_array($l2)&&isset($l2[$name])){$l2[$name]['ultimo_acesso']=date('c');
     rewind($fp2);ftruncate($fp2,0);fwrite($fp2,json_encode($l2,JSON_PRETTY_PRINT));fflush($fp2);}flock($fp2,LOCK_UN);fclose($fp2);}
  }}
  rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($log));fflush($fp);flock($fp,LOCK_UN);fclose($fp);
  if(!$role)rd_auth_send(['ok'=>false,'error'=>$blocked?'Muitas tentativas; tente após 15 minutos':'Usuário ou senha incorretos; para primeiro acesso ative Administração e troque a senha temporária'], $blocked?429:401);
  session_regenerate_id(true);$_SESSION['rd_login']=['username'=>$name,'role'=>$role,'last'=>time(),'admin_hash'=>$role==='gestor'?(string)($admin['hash']??''):null];$_SESSION['rd_token']=bin2hex(random_bytes(32));
  rd_auth_send(['ok'=>true,'user'=>['username'=>$name,'role'=>$role],'csrf'=>$_SESSION['rd_token']]);
 }
 rd_auth_gate('read',true);
 if($action==='auth_logout'){unset($_SESSION['rd_login'],$_SESSION['rd_token']);session_regenerate_id(true);rd_auth_send(['ok'=>true]);}
 if($action==='auth_user_save'){
  rd_auth_gate('admin');$body=json_decode((string)file_get_contents('php://input'),true);$name=trim((string)($body['username']??''));$role=(string)($body['role']??'');$pw=(string)($body['password']??'');
  if(!preg_match('/^[a-zA-Z0-9_.@-]{3,60}$/D',$name)||$name===(string)(rd_admin_data()['username']??'adm')||!in_array($role,['conferente','consulta','importador','gestor'],true)||strlen($pw)<12||strlen($pw)>128)rd_auth_send(['ok'=>false,'error'=>'Usuário, perfil ou senha inválidos (mínimo 12 caracteres)'],422);
  $fp=@fopen(RD_USER_FILE,'c+');if(!$fp||!flock($fp,LOCK_EX))rd_auth_send(['ok'=>false,'error'=>'Não foi possível gravar usuário'],503);$list=json_decode(stream_get_contents($fp)?:'{}',true);if(!is_array($list))$list=[];
  $list[$name]=['role'=>$role,'hash'=>password_hash($pw,PASSWORD_DEFAULT),'enabled'=>true,'updated_at'=>date('c')];rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($list,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));fflush($fp);flock($fp,LOCK_UN);fclose($fp);@chmod(RD_USER_FILE,0600);rd_auth_send(['ok'=>true]);
 }
 if($action==='auth_user_disable'){
  rd_auth_gate('admin');$body=json_decode((string)file_get_contents('php://input'),true);$name=trim((string)($body['username']??''));
  $fp=@fopen(RD_USER_FILE,'c+');if(!$fp||!flock($fp,LOCK_EX))rd_auth_send(['ok'=>false,'error'=>'Falha ao alterar acesso'],503);
  $all=json_decode(stream_get_contents($fp)?:'{}',true);if(!is_array($all)||!isset($all[$name])){flock($fp,LOCK_UN);fclose($fp);rd_auth_send(['ok'=>false,'error'=>'Usuário inexistente'],404);}
  $all[$name]['enabled']=false;rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($all,JSON_PRETTY_PRINT));fflush($fp);flock($fp,LOCK_UN);fclose($fp);rd_auth_send(['ok'=>true]);
 }
 rd_auth_send(['ok'=>false,'error'=>'Ação desconhecida'],404);
}
