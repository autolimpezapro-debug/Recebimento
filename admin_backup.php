<?php
/**
 * Recebimento Drilling · Administração / Backup — v2.9.5
 * Sem dependência do SGA ou do MySQL; guarda arquivos JSON/fotos/XML e código.
 */
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

const RB_MAX_TOTAL = 400000000; // 400 MB descompactados
const RB_MAX_FILES = 10000;
const RB_MAX_UPLOAD = 220000000; // ZIP transferido via upload
const RB_IDLE = 900; // 15 minutos
const RB_SENHA_MIN = 8; // mínimo aceito; 12+ é o recomendado
const RB_AUTH = __DIR__.'/data/backup_admin_auth.json';
const RB_SETUP = __DIR__.'/data/backup_setup.key';
const RB_DATA = __DIR__.'/data';
const RB_BACKUPS = __DIR__.'/data/backups';
const RB_LOCK = __DIR__.'/data/.write.lock';
const RB_MAINT = __DIR__.'/data/.maintenance';
function rb_ini_bytes(string $value): int {
    $number=(float)$value;$suffix=strtolower(substr(trim($value),-1));
    if($suffix==='g')return (int)($number*1073741824);
    if($suffix==='m')return (int)($number*1048576);
    if($suffix==='k')return (int)($number*1024);
    return (int)$number;
}

function rb_json(array $v, int $status=200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function rb_fail(string $message, int $status=400): void {rb_json(['ok'=>false,'error'=>$message],$status);}
function rb_private_setup(): void {
    if (!is_dir(RB_DATA) && !@mkdir(RB_DATA,0750,true)) rb_fail('Pasta data/ indisponível.',503);
    // Proteção no Apache/LiteSpeed; arquivos de backup não podem ser públicos.
    $ht=RB_DATA.'/.htaccess';
    if (!file_exists($ht)) {
        $contents="Options -Indexes\n<FilesMatch \"(?i)\\.(json|php|xml|zip|key|lock|tmp|bak|log|pem)$\">\nRequire all denied\n</FilesMatch>\n<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n";
        if (@file_put_contents($ht,$contents,LOCK_EX)===false) rb_fail('Não foi possível proteger data/.',503);
    }
    if (!is_dir(RB_BACKUPS) && !@mkdir(RB_BACKUPS,0700,true)) rb_fail('Não foi possível criar a pasta privada de backups.',503);
    $bht=RB_BACKUPS.'/.htaccess';
    if(!is_file($bht)) @file_put_contents($bht,"Require all denied\nOptions -Indexes\n",LOCK_EX);
    if (!is_file(RB_AUTH) && !is_file(RB_SETUP)) {
        // Código só é visível no Gerenciador de Arquivos do cPanel.
        $code=bin2hex(random_bytes(16));
        if(@file_put_contents(RB_SETUP,$code,LOCK_EX)===false) rb_fail('Não foi possível gerar o código de ativação.',503);
        @chmod(RB_SETUP,0600);
    }
}
function rb_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE)return;
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_strict_mode','1');
    session_name('rd_backup_admin');
    $secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    if(!session_start())rb_fail('Sessão administrativa indisponível.',503);
}
function rb_origin(): void {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    if(!$origin || !$host || !hash_equals($scheme.'://'.$host,$origin))rb_fail('Origem da solicitação não autorizada.',403);
}
function rb_authenticated(): bool {
    rb_session();
    $t=(int)($_SESSION['rb_last']??0);
    if(empty($_SESSION['rb_auth']) || $t<time()-RB_IDLE){
        unset($_SESSION['rb_auth'],$_SESSION['rb_last'],$_SESSION['rb_csrf']);
        return false;
    }
    $_SESSION['rb_last']=time();
    return true;
}
function rb_guard(): void {
    if(!rb_authenticated())rb_fail('Sessão expirada. Entre com a senha administrativa novamente.',401);
    $token=(string)($_SERVER['HTTP_X_BACKUP_CSRF']??'');
    if(!$token || !hash_equals((string)($_SESSION['rb_csrf']??''),$token))rb_fail('Token de segurança inválido.',403);
}
function rb_payload(): array {
    $raw=file_get_contents('php://input');
    if(strlen($raw)>16384)rb_fail('Requisição muito grande.',413);
    $payload=json_decode($raw,true);
    if(!is_array($payload))rb_fail('Dados inválidos.',422);
    return $payload;
}
function rb_password_valid(string $password): bool {
    $settings=json_decode((string)@file_get_contents(RB_AUTH),true);
    return is_array($settings) && isset($settings['hash']) && password_verify($password,(string)$settings['hash']);
}
function rb_attempt(bool $failed=false, bool $clear=false): bool {
    $file=RB_DATA.'/backup_auth_attempts.json';
    $fp=@fopen($file,'c+');
    if(!$fp||!flock($fp,LOCK_EX))rb_fail('Controle de tentativas indisponível.',503);
    $record=json_decode(stream_get_contents($fp)?:'{}',true);
    if(!is_array($record))$record=[];
    $now=time();
    foreach($record as $k=>$v){$record[$k]=array_values(array_filter(is_array($v)?$v:[],fn($n)=>is_numeric($n)&&(int)$n>$now-900));if(!$record[$k])unset($record[$k]);}
    $key=hash('sha256', (string)($_SERVER['REMOTE_ADDR']??'unknown'));
    $allowed=count($record[$key]??[])<6;
    if($failed && $allowed)$record[$key][]=$now;
    if($clear && $allowed)unset($record[$key]);
    rewind($fp);
    if(!ftruncate($fp,0)||fwrite($fp,json_encode($record))===false||!fflush($fp)){
        flock($fp,LOCK_UN);fclose($fp);rb_fail('Controle de tentativas indisponível.',503);
    }
    flock($fp,LOCK_UN);fclose($fp);
    return $allowed;
}
function rb_lock(int $mode) {
    $fp=@fopen(RB_LOCK,'c+');
    if(!$fp||!flock($fp,$mode))rb_fail('Não foi possível bloquear a base de dados.',503);
    return $fp;
}
/** Apenas arquivos do Recebimento; nunca arquivos de outros sistemas da mesma hospedagem. */
function rb_allowed(string $rel): bool {
    if($rel===''||str_contains($rel,'\\')||str_contains($rel,"\0")||str_starts_with($rel,'/')||preg_match('~(^|/)\.\.?(/|$)~',$rel))return false;
    if(preg_match('~^(?:index\.html|sw\.js|manifest\.webmanifest|\.htaccess|(?:[a-zA-Z0-9_-]+\.(?:php|txt)))$~D',$rel))return true;
    if(preg_match('~^assets/[a-zA-Z0-9_.-]+\.(?:js|css)$~D',$rel))return true;
    if(preg_match('~^icons/[a-zA-Z0-9_.-]+\.(?:svg|png|webp|ico)$~D',$rel))return true;
    if(!str_starts_with($rel,'data/'))return rb_allowed_extra($rel);
    // Módulo de notificações (v3.1) e backup completo (v3.2) também são dados do sistema.
    if($rel==='data/notifications.json'||$rel==='data/devices.json'||$rel==='data/coletas.json')return true;
    if(preg_match('~^data/(?:backups/|imports/|backup_admin_auth\.json$|backup_setup\.key$|backup_auth_attempts\.json$|gemini_config\.json$|\.write\.lock$|\.maintenance$|\.restore)~',$rel))return false;
    if($rel==='data/.htaccess'||$rel==='data/seed.php'||$rel==='data/order_pdfs/.htaccess')return true;
    if(preg_match('~^data/order_pdfs/pc_[0-9]+_[a-f0-9]{16}\.pdf$~D',$rel))return true;
    if(preg_match('~^data/(?:[a-zA-Z0-9_.-]+\.json|(?:photos|xml)/[a-zA-Z0-9_./-]+\.(?:jpe?g|png|webp|gif|pdf|xml))$~D',$rel))return true;
    // Módulo de Estoque (v3.0.0): banco do estoque e cópia de segurança da base de pedidos.
    if(preg_match('~^data/estoque/[a-zA-Z0-9_.-]+\.json$~D',$rel))return true;
    return rb_allowed_extra($rel);
}
/**
 * v3.59.1 (auditoria): o backup automático (backup_lib.php) inclui pastas que este restaurador recusava
 * (assinaturas, fotos de saída do estoque, obras, documentos de locação/protocolo, Caixa de NFs, README.md...),
 * então restaurar um backup diário falhava inteiro com "Arquivo ou checksum não permitido".
 * Estes caminhos são aceitos na restauração, mas NÃO entram na lista de arquivos atuais que a restauração apaga
 * (um backup antigo, sem essas pastas, não remove assinaturas/documentos que já existem).
 */
function rb_allowed_extra(string $rel): bool {
    if($rel===''||str_contains($rel,'\\')||str_contains($rel,"\0")||str_starts_with($rel,'/')||preg_match('~(^|/)\.\.?(/|$)~',$rel))return false;
    if(preg_match('~^[a-zA-Z0-9_-]+\.(?:md|json)$~D',$rel))return true;
    if(preg_match('~^testes/[a-zA-Z0-9_.-]+\.(?:py|js)$~D',$rel))return true;
    if(preg_match('~^data/(?:backups/|imports/|backup_admin_auth\.json$|backup_setup\.key$|backup_auth_attempts\.json$|gemini_config\.json$)~',$rel))return false;
    if(preg_match('~^data/(?:estoque|obras|assinaturas)/[a-zA-Z0-9_.\-/]+\.(?:jpe?g|png|webp|gif|pdf|xml|json)$~D',$rel))return true;
    if(preg_match('~^data/(?:locacoes_docs|protocolos_docs|chegadas_fotos|nfmail)/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp|pdf|xml)$~D',$rel))return true;
    if(preg_match('~^data/(?:locacoes_docs|protocolos_docs|chegadas_fotos|nfmail|obras|assinaturas|estoque/fotos_saida)/\.htaccess$~D',$rel))return true;
    return false;
}
function rb_inventory(): array {
    $result=[];$root=realpath(__DIR__);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if($file->isLink()||!$file->isFile())continue;
        $name=substr($file->getPathname(),strlen($root)+1);
        $name=str_replace(DIRECTORY_SEPARATOR,'/',$name);
        if(!rb_allowed($name))continue;
        $result[$name]=$file->getPathname();
        if(count($result)>RB_MAX_FILES)throw new RuntimeException('Quantidade máxima de arquivos ultrapassada.');
    }
    ksort($result,SORT_STRING);
    return $result;
}
function rb_create_archive(string $target): array {
    if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão PHP zip (ZipArchive) no cPanel.');
    $zip=new ZipArchive();
    if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o ZIP; verifique o espaço em disco.');
    $files=[];$size=0;
    try {
        foreach(rb_inventory() as $rel=>$absolute){
            $bytes=filesize($absolute);
            if($bytes===false||$bytes<0)throw new RuntimeException('Erro de leitura de '.$rel);
            $size+=$bytes;
            if($size>RB_MAX_TOTAL)throw new RuntimeException('Backup excede 400 MB; contate o administrador para ampliar o limite.');
            if(!$zip->addFile($absolute,$rel))throw new RuntimeException('Não foi possível anexar '.$rel);
            $files[]=['path'=>$rel,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$absolute)];
        }
        $manifest=['type'=>'recebimento-drilling-complete-backup','schema'=>1,'created_at'=>date('c'),
            'app_version'=>'3.5.0','files'=>$files,'file_count'=>count($files),'total_bytes'=>$size,
            'exclusions'=>['data/backups','data/backup_admin_auth.json','data/backup_setup.key','data/backup_auth_attempts.json','data/imports','data/.write.lock','data/.maintenance']];
        if(!$zip->addFromString('RECEBIMENTO_BACKUP_MANIFEST.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)))throw new RuntimeException('Erro ao incluir manifesto.');
        if(!$zip->close())throw new RuntimeException('Não foi possível concluir o ZIP.');
    }catch(Throwable $e){$zip->close();@unlink($target);throw $e;}
    return ['files'=>count($files),'bytes'=>$size];
}
function rb_check_archive(string $path): array {
    if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão PHP zip (ZipArchive) no cPanel.');
    $zip=new ZipArchive();
    if($zip->open($path,ZipArchive::RDONLY)!==true)throw new RuntimeException('ZIP inválido ou corrompido.');
    $raw=$zip->getFromName('RECEBIMENTO_BACKUP_MANIFEST.json');
    $manifest=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($manifest)||($manifest['type']??'')!=='recebimento-drilling-complete-backup'||!in_array($manifest['schema']??null,[1,2],true)||!is_array($manifest['files']??null)){
        $zip->close();throw new RuntimeException('O arquivo não é um backup completo do Recebimento Drilling.');
    }
    if(count($manifest['files'])>RB_MAX_FILES || count($manifest['files'])!==($manifest['file_count']??-1)){
        $zip->close();throw new RuntimeException('Manifesto de backup inválido.');
    }
    $paths=[];$total=0;
    foreach($manifest['files'] as $row){
        $name=$row['path']??null; $bytes=$row['bytes']??null; $hash=$row['sha256']??null;
        if(!is_string($name)||!rb_allowed($name)||isset($paths[$name])||!is_int($bytes)||$bytes<0||!is_string($hash)||!preg_match('/^[0-9a-f]{64}$/D',$hash)){
            $zip->close();throw new RuntimeException('Arquivo ou checksum não permitido no backup.');
        }
        $stat=$zip->statName($name);
        if(!$stat || (int)$stat['size']!==$bytes){$zip->close();throw new RuntimeException('Arquivo faltando ou com tamanho alterado: '.$name);}
        $paths[$name]=$row; $total+=$bytes;
        if($total>RB_MAX_TOTAL){$zip->close();throw new RuntimeException('Backup descompactado ultrapassa limite.');}
    }
    if($zip->numFiles!==count($paths)+1){$zip->close();throw new RuntimeException('Há arquivos extras ou duplicados no ZIP.');}
    for($i=0;$i<$zip->numFiles;$i++){
        $stat=$zip->statIndex($i);$name=$stat['name']??'';
        if($name!=='RECEBIMENTO_BACKUP_MANIFEST.json'&&!isset($paths[$name])){$zip->close();throw new RuntimeException('Conteúdo não permitido.');}
        // Nunca aceitar links simbólicos empacotados em ZIP.
        if($zip->getExternalAttributesIndex($i,$opsys,$attr) && $opsys===ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000)===0120000){
            $zip->close();throw new RuntimeException('Backup contém link simbólico não permitido.');
        }
    }
    return [$zip,$manifest];
}
function rb_stage_zip(ZipArchive $zip,array $manifest,string $stage): void {
    foreach($manifest['files'] as $row){
        $name=$row['path'];$dest=$stage.'/'.$name;
        $dir=dirname($dest);
        if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Falha ao preparar restauração.');
        $read=$zip->getStream($name);
        $write=@fopen($dest,'wb');
        if(!$read||!$write)throw new RuntimeException('Não foi possível extrair '.$name);
        $context=hash_init('sha256');$count=0;
        while(!feof($read)){
            $buf=fread($read,65536);
            if($buf===false)throw new RuntimeException('Falha de leitura no backup.');
            if($buf==='')break;
            $count+=strlen($buf);
            if($count>$row['bytes'])throw new RuntimeException('Arquivo maior que o declarado.');
            hash_update($context,$buf);
            if(fwrite($write,$buf)!==strlen($buf))throw new RuntimeException('Disco cheio durante a restauração.');
        }
        fclose($read);fclose($write);
        if($count!==$row['bytes']||!hash_equals($row['sha256'],hash_final($context)))throw new RuntimeException('Integridade SHA-256 falhou para '.$name);
        if(str_starts_with($name,'data/') && str_ends_with($name,'.json')){
            $json=json_decode((string)file_get_contents($dest),true);
            if(json_last_error()!==JSON_ERROR_NONE || !is_array($json))throw new RuntimeException('JSON inválido: '.$name);
            if($name==='data/items.json'){
                $pcs=[];
                foreach($json as $item){
                    $pc=is_array($item)?($item['pc']??null):null;
                    if(!is_numeric($pc)||(int)$pc<=0||isset($pcs[(int)$pc]))throw new RuntimeException('Pedidos inconsistentes ou repetidos no backup.');
                    $pcs[(int)$pc]=true;
                }
            }
        }
    }
}
function rb_remove_tree(string $path): void {
    if(!is_dir($path))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){ if($file->isDir() && !$file->isLink())@rmdir($file->getPathname());else @unlink($file->getPathname()); }
    @rmdir($path);
}
function rb_restore(string $archive): array {
    $lock=rb_lock(LOCK_EX);
    $job=RB_BACKUPS.'/.restore-'.bin2hex(random_bytes(8));
    $stage=$job.'/stage';$undo=$job.'/undo';
    $applied=[];$deleted=[];
    try {
        [$zip,$manifest]=rb_check_archive($archive);
        if(!is_dir($stage)&&!mkdir($stage,0700,true))throw new RuntimeException('Não foi possível criar área temporária.');
        rb_stage_zip($zip,$manifest,$stage);
        $zip->close();
        $containsItems=false;
        foreach($manifest['files'] as $row){if($row['path']==='data/items.json'){$containsItems=true;break;}}
        if(!$containsItems && is_file(RB_DATA.'/items.json'))throw new RuntimeException('Este backup não contém a base items.json e não pode sobrescrever uma instalação com pedidos cadastrados.');
        // Antes da primeira modificação, salvar cópia integral no servidor.
        $snapshot=RB_BACKUPS.'/pre-restauracao-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
        rb_create_archive($snapshot);
        if(@file_put_contents(RB_MAINT,date('c'),LOCK_EX)===false)throw new RuntimeException('Não foi possível entrar em modo seguro.');
        $want=[];foreach($manifest['files'] as $row)$want[$row['path']]=true;
        $current=rb_inventory();
        $delete=array_diff_key($current,$want);
        foreach(array_keys($delete) as $rel) if(rb_allowed_extra($rel)) unset($delete[$rel]);   // ver rb_allowed_extra()
        // Não substituir as regras de acesso do servidor a documentos privados.
        unset($want['data/.htaccess']);
        foreach($delete as $rel=>$src){
            if($rel==='data/.htaccess')continue;
            $to=$undo.'/'.$rel;
            if(!is_dir(dirname($to))&&!mkdir(dirname($to),0700,true))throw new RuntimeException('Falha ao preparar reversão.');
            if(!rename($src,$to))throw new RuntimeException('Falha ao preservar arquivo antigo.');
            $deleted[]=$rel;
        }
        foreach(array_keys($want) as $rel){
            $dest=__DIR__.'/'.$rel;
            $incoming=$stage.'/'.$rel;
            if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0750,true))throw new RuntimeException('Falha ao preparar diretório.');
            $had=is_file($dest);
            if($had){
                $to=$undo.'/'.$rel;
                if(!is_dir(dirname($to))&&!mkdir(dirname($to),0700,true))throw new RuntimeException('Falha ao preparar reversão.');
                if(!rename($dest,$to))throw new RuntimeException('Não foi possível preservar arquivo anterior.');
            }
            $applied[$rel]=$had;
            if(!rename($incoming,$dest))throw new RuntimeException('Falha ao instalar '.$rel);
        }
        @unlink(RB_MAINT);
        rb_remove_tree($job);
        flock($lock,LOCK_UN);fclose($lock);
        return ['restored'=>count($want),'removed'=>count($delete),'snapshot'=>basename($snapshot)];
    }catch(Throwable $e){
        // Reverter todos os arquivos alterados, inclusive exclusões.
        foreach(array_reverse(array_keys($applied)) as $rel){
            $dst=__DIR__.'/'.$rel;
            if(is_file($dst))@unlink($dst);
            if($applied[$rel] && is_file($undo.'/'.$rel))@rename($undo.'/'.$rel,$dst);
        }
        foreach(array_reverse($deleted) as $rel){
            $dst=__DIR__.'/'.$rel;
            if(is_file($undo.'/'.$rel)){
                if(!is_dir(dirname($dst)))@mkdir(dirname($dst),0750,true);
                @rename($undo.'/'.$rel,$dst);
            }
        }
        @unlink(RB_MAINT);
        rb_remove_tree($job);
        flock($lock,LOCK_UN);fclose($lock);
        throw $e;
    }
}

try {
    rb_private_setup();rb_session();
    $action=(string)($_GET['action']??'status');
    if($action==='status'){
        if(($_SERVER['REQUEST_METHOD']??'')!=='GET')rb_fail('Método não permitido.',405);
        $yes=rb_authenticated();
        rb_json(['ok'=>true,'configured'=>is_file(RB_AUTH),'authorized'=>$yes,'csrf'=>$yes?$_SESSION['rb_csrf']:null,
            'zip_available'=>class_exists('ZipArchive'),'max_upload_bytes'=>min(RB_MAX_UPLOAD,rb_ini_bytes((string)ini_get('upload_max_filesize'))),
            'idle_minutes'=>RB_IDLE/60]);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')rb_fail('Método não permitido.',405);
    rb_origin();
    if($action==='setup'){
        if(is_file(RB_AUTH))rb_fail('Senha administrativa já configurada.',409);
        if(!rb_attempt())rb_fail('Limite de tentativas. Aguarde 15 minutos.',429);
        $body=rb_payload();$code=(string)($body['code']??'');$pw=(string)($body['password']??'');
        $expected=trim((string)@file_get_contents(RB_SETUP));
        if(!$expected||!hash_equals($expected,$code)){rb_attempt(true);rb_fail('Código de ativação inválido.',401);}
        if(strlen($pw)<RB_SENHA_MIN||strlen($pw)>128)rb_fail('A senha administrativa deve ter de '.RB_SENHA_MIN.' a 128 caracteres (recomendado: 12 ou mais).',422);
        $cfg=['hash'=>password_hash($pw,PASSWORD_DEFAULT),'created_at'=>date('c')];
        $fh=@fopen(RB_AUTH,'x');
        if(!$fh)rb_fail('Configuração já criada por outra sessão.',409);
        $ok=fwrite($fh,json_encode($cfg,JSON_PRETTY_PRINT))!==false;fclose($fh);
        if(!$ok){@unlink(RB_AUTH);rb_fail('Não foi possível gravar a senha administrativa.',503);}
        @chmod(RB_AUTH,0600);@unlink(RB_SETUP);rb_attempt(false,true);
        rb_json(['ok'=>true,'message'=>'Senha configurada. Entre para acessar os backups.']);
    }
    if($action==='login'){
        if(!is_file(RB_AUTH))rb_fail('Ative o administrador primeiro.',409);
        if(!rb_attempt())rb_fail('Muitas tentativas. Aguarde 15 minutos.',429);
        $body=rb_payload();$pw=$body['password']??null;
        if(!is_string($pw)||strlen($pw)>128||!rb_password_valid($pw)){
            rb_attempt(true);rb_fail('Senha administrativa incorreta.',401);
        }
        rb_attempt(false,true);session_regenerate_id(true);
        $_SESSION['rb_auth']=true;$_SESSION['rb_last']=time();$_SESSION['rb_csrf']=bin2hex(random_bytes(32));
        rb_json(['ok'=>true,'csrf'=>$_SESSION['rb_csrf']]);
    }
    rb_guard();
    if($action==='logout'){
        unset($_SESSION['rb_auth'],$_SESSION['rb_last'],$_SESSION['rb_csrf']);session_regenerate_id(true);
        rb_json(['ok'=>true]);
    }
    if($action==='create'){
        set_time_limit(240);
        $lock=rb_lock(LOCK_EX);
        $temp=RB_BACKUPS.'/.download-'.bin2hex(random_bytes(12)).'.zip';
        try{rb_create_archive($temp);}finally{flock($lock,LOCK_UN);fclose($lock);}
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="recebimento-drilling-backup-'.date('Ymd-His').'.zip"');
        header('Content-Length: '.filesize($temp));
        header('Cache-Control: private, no-store');
        readfile($temp);@unlink($temp);exit;
    }
    if($action==='restore'){
        set_time_limit(240);
        if(!rb_attempt())rb_fail('Acesso temporariamente bloqueado após muitas tentativas.',429);
        $pw=$_POST['password']??null;
        if(!is_string($pw)||strlen($pw)>128||!rb_password_valid($pw)){
            rb_attempt(true);rb_fail('Confirmação da senha administrativa incorreta.',401);
        }
        if(($_POST['confirm']??'')!=='RESTAURAR')rb_fail('Confirme a operação digitando RESTAURAR.',422);
        $file=$_FILES['archive']??null;
        if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))rb_fail('Não foi possível receber o ZIP. Confira os limites de upload do PHP.',422);
        if((int)$file['size']>RB_MAX_UPLOAD)rb_fail('O arquivo ultrapassa o limite de 220 MB.',413);
        $result=rb_restore($file['tmp_name']);
        rb_attempt(false,true);
        rb_json(['ok'=>true,'message'=>'Backup restaurado. Atualize a página para carregar o sistema restaurado.','details'=>$result]);
    }
    if($action==='snapshots'){
        $list=[];
        foreach(glob(RB_BACKUPS.'/pre-restauracao-*.zip')?:[] as $f){
            if(!is_file($f))continue;
            $list[]=['name'=>basename($f),'bytes'=>filesize($f),'date'=>date('c',filemtime($f))];
        }
        usort($list,fn($a,$b)=>strcmp($b['name'],$a['name']));
        rb_json(['ok'=>true,'snapshots'=>array_slice($list,0,40)]);
    }
    if($action==='download_snapshot'){
        $body=rb_payload();$name=(string)($body['name']??'');
        if(!preg_match('/^pre-restauracao-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.zip$/D',$name))rb_fail('Cópia de segurança inválida.',422);
        $file=RB_BACKUPS.'/'.$name;
        if(!is_file($file))rb_fail('Cópia não encontrada.',404);
        header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($file));readfile($file);exit;
    }
    rb_fail('Ação administrativa desconhecida.',404);
}catch(Throwable $e){
    error_log('Backup Recebimento Drilling: '.$e->getMessage());
    rb_fail('Operação não concluída: '.$e->getMessage(),500);
}
