<?php
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
/** Ações AJAX, incluídas por api.php depois das funções de dados. */
require_once __DIR__.'/whatsapp_import_lib.php';
$importDir=DATA_DIR.'/imports';
if(!is_dir($importDir) && !@mkdir($importDir,0750,true))respond(['ok'=>false,'error'=>'Não foi possível preparar a importação.'],500);
if(!file_exists($importDir.'/.htaccess'))@file_put_contents($importDir.'/.htaccess',"Require all denied\nOptions -Indexes\n");
$origin=$_SERVER['HTTP_ORIGIN']??'';
$httpHost=$_SERVER['HTTP_HOST']??'';
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
if(!$origin || !hash_equals($https.'://'.$httpHost,$origin))respond(['ok'=>false,'error'=>'A importação deve ser iniciada no próprio sistema.'],403);
/* ---------------- Configuração da leitura por IA (Gemini) ----------------
   Rotas protegidas pela mesma autorização da importação (senha + origem + token).
   A chave NUNCA volta ao navegador: só existe/últimos caracteres. */
require_once __DIR__.'/gemini_import.php';
if($action==='gemini_status'){
    if($_SERVER['REQUEST_METHOD']!=='GET')respond(['ok'=>false,'error'=>'Método inválido.'],405);
    respond(['ok'=>true,'config'=>rd_gemini_resumo(),'extensao_curl'=>function_exists('curl_init')]);
}
if($action==='gemini_config'){
    if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'error'=>'Método inválido.'],405);
    $b=json_decode(file_get_contents('php://input'),true);
    if(!is_array($b))respond(['ok'=>false,'error'=>'Dados inválidos.'],422);
    try{ rd_gemini_config_save($b); respond(['ok'=>true,'config'=>rd_gemini_resumo()]); }
    catch(Throwable $e){respond(['ok'=>false,'error'=>$e->getMessage()],422);}
}
if($action==='gemini_teste'){
    if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'error'=>'Método inválido.'],405);
    $c=rd_gemini_config();
    if(empty($c['enabled']))respond(['ok'=>false,'error'=>'A leitura por IA está desligada.'],422);
    if(!function_exists('curl_init'))respond(['ok'=>false,'error'=>'PHP sem cURL. Habilite a extensão no cPanel (MultiPHP INI Editor).'],503);
    @set_time_limit(60);
    try{
        $t0=microtime(true);
        $txt=rd_gemini_call([['text'=>'Responda somente JSON: {"ok":true,"modelo":"seu-nome"}']],80);
        $j=json_decode(trim($txt),true);
        respond(['ok'=>true,'resposta'=>is_array($j)?$j:['texto'=>mb_substr((string)$txt,0,120)],
            'ms'=>(int)round((microtime(true)-$t0)*1000),'modelo'=>$c['model']]);
    }catch(Throwable $e){respond(['ok'=>false,'error'=>$e->getMessage()],502);}
}

if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'error'=>'Método não permitido.'],405);
function wa_import_class($p,$all){
    $pc=(int)$p['pc'];$idx=find_index($all,$pc);
    if(!$p['insumos'])return ['state'=>'sem_itens','message'=>'PDF identificado, mas sem linhas de insumo verificáveis.'];
    if($idx<0)return ['state'=>'novo','message'=>'Pedido novo.'];
    $row=$all[$idx];
    if(!empty($row['entregas'])||!empty($row['recebimento_estado'])||!empty($row['recebimento_finalizado_em']))return ['state'=>'protegido','message'=>'Recebimento iniciado ou concluído; não será alterado.'];
    if(!empty($row['insumos']))return ['state'=>'ja_detalhado','message'=>'Pedido já tem insumos cadastrados; será preservado.'];
    return ['state'=>'completar','message'=>'Completar quantidades do PC já cadastrado.'];
}
require_once __DIR__.'/alertas_lib.php';
/** v3.29.0: quem deve receber o e-mail "seu pedido chegou" — solicitante do PDF, depois observações do pedido. Só sugere. */
function wa_aviso_pc($p,$items){
    $idx=find_index($items,(int)$p['pc']);$row=$idx>=0?$items[$idx]:[];
    if(($row['avisar_modo']??'')!=='')return rcb_situacao($row);                 // já definido antes: respeita
    $sol=trim((string)($row['solicitante']??''))?:(string)($p['solicitante']??'');
    $txt=trim((string)($p['observacoes']??'').' '.(string)($p['destino']??'').' '.(string)($row['obs']??''));
    return rcb_resolver($sol,$txt);
}
function wa_prepare_list($preview,$items){
    $out=[];
    foreach($preview['pedidos'] as $p){$st=wa_import_class($p,$items);$out[]=[
       'pc'=>$p['pc'],'fornecedor'=>$p['fornecedor'],'solicitante'=>$p['solicitante'],'pdf'=>$p['pdf'],
       'itens'=>count($p['insumos']),'insumos'=>$p['insumos'],'estado'=>$st['state'],'detalhe'=>$st['message'],
       'leitura'=>$p['leitura_origem']??'nativo','avisos_ia'=>$p['avisos_ia']??[],
       'revisao_ia_obrigatoria'=>!empty($p['revisao_ia_obrigatoria']),'urgente_chat'=>$p['urgente_chat']??null,
       'aviso'=>wa_aviso_pc($p,$items)];}
    return $out;
}
function wa_import_cleanup($directory){
    foreach(glob($directory.'/*.json')?:[] as $f){if(@filemtime($f)<time()-3600){@unlink($f);@unlink(substr($f,0,-5).'.zip');@unlink(substr($f,0,-5).'.pdf');}}
}
if($action==='whatsapp_import_preview' && !empty($_POST['upload_id'])){
    // v3.14.0: arquivo enviado em partes (até 1 GB). Lido e apagado logo depois da prévia.
    require_once __DIR__.'/upload_lib.php';
    @set_time_limit(900); @ini_set('memory_limit','512M');
    $up=rd_up_arquivo((string)$_POST['upload_id']);
    if(!$up)respond(['ok'=>false,'error'=>'Envio não encontrado ou expirado. Selecione o arquivo novamente.'],410);
    $format=$up['ext'];$base=$up['nome'];$target=$up['path'];$t=(string)$_POST['upload_id'];
    try{
        $preview=$format==='pdf'?wa_single_pdf_preview($target,$base):wa_zip_preview($target);
        if(!$preview['pdfs'])throw new RuntimeException('Arquivo sem PDFs de pedidos reconhecíveis.');
        $preview['hash_arquivo']=hash_file('sha256',$target);
        $preview['hash_zip']=$preview['hash_arquivo'];
        $preview['formato']=$format;
        $preview['sem_arquivo']=true;
        if(file_put_contents($importDir.'/'.$t.'.json',json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false)throw new RuntimeException('Não foi possível preparar a prévia.');
    }catch(Throwable $e){rd_up_apagar($t);@unlink($importDir.'/'.$t.'.json');respond(['ok'=>false,'error'=>$e->getMessage()],422);}
    rd_up_apagar($t);   // anexo não fica no servidor: só os dados lidos
    wa_import_cleanup($importDir);
    $all=read_items();
    respond(['ok'=>true,'token'=>$t,'formato'=>$format,'totais'=>['pdfs'=>$preview['pdfs'],'pedidos'=>count($preview['pedidos']),'insumos'=>array_sum(array_map(fn($p)=>count($p['insumos']),$preview['pedidos'])),'avisos'=>count($preview['avisos'])],
      'gemini_ativo'=>wa_gemini_disponivel(),'contatos'=>rcb_lista_selecao(),'pedidos'=>wa_prepare_list($preview,$all),'avisos'=>$preview['avisos']]);
}
if($action==='whatsapp_import_preview'){
    if(!isset($_FILES['arquivo']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])){
        $err=$_FILES['arquivo']['error']??UPLOAD_ERR_NO_FILE;
        respond(['ok'=>false,'error'=>in_array($err,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?'Arquivo maior que o limite de upload do servidor; ajuste upload_max_filesize e post_max_size no cPanel.':'Selecione um ZIP do WhatsApp ou um PDF individual de pedido de compra.'],422);
    }
    if($_FILES['arquivo']['error']!==UPLOAD_ERR_OK)respond(['ok'=>false,'error'=>'Falha no envio do arquivo.'],422);
    $original=(string)($_FILES['arquivo']['name']??'pedido.pdf');
    $base=basename(str_replace('\\','/',$original));
    if(strlen($base)>200 || !preg_match('/\.(pdf|zip)$/i',$base,$m))respond(['ok'=>false,'error'=>'Formato inválido. Envie PDF ou ZIP.'],422);
    $format=strtolower($m[1]);$max=$format==='pdf'?30*1024*1024:1024*1024*1024;
    if((int)$_FILES['arquivo']['size']>$max)respond(['ok'=>false,'error'=>'Tamanho máximo: '.($format==='pdf'?'30 MB para PDF.':'1 GB para ZIP.')],413);
    $fh=@fopen($_FILES['arquivo']['tmp_name'],'rb');$magic=$fh?fread($fh,5):'';if($fh)fclose($fh);
    if(($format==='pdf' && $magic!=='%PDF-')||($format==='zip' && !in_array(substr($magic,0,4),["PK\x03\x04","PK\x05\x06"],true)))
        respond(['ok'=>false,'error'=>'O conteúdo do arquivo não corresponde à extensão PDF/ZIP.'],422);
    $t=bin2hex(random_bytes(20));$target=$importDir.'/'.$t.'.'.$format;
    if(!move_uploaded_file($_FILES['arquivo']['tmp_name'],$target))respond(['ok'=>false,'error'=>'Não foi possível salvar temporariamente o arquivo.'],500);
    try{
        $preview=$format==='pdf'?wa_single_pdf_preview($target,$base):wa_zip_preview($target);
        if(!$preview['pdfs'])throw new RuntimeException('Arquivo sem PDFs de pedidos reconhecíveis.');
        $preview['hash_arquivo']=hash_file('sha256',$target);
        $preview['hash_zip']=$preview['hash_arquivo']; // compatibilidade com prévias ZIP anteriores
        $preview['formato']=$format;
        $preview['sem_arquivo']=true;
        $path=$importDir.'/'.$t.'.json';
        if(file_put_contents($path,json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false)throw new RuntimeException('Não foi possível preparar a prévia.');
        @unlink($target);
        wa_import_cleanup($importDir);
        $all=read_items();
        respond(['ok'=>true,'token'=>$t,'formato'=>$format,'totais'=>['pdfs'=>$preview['pdfs'],'pedidos'=>count($preview['pedidos']),'insumos'=>array_sum(array_map(fn($p)=>count($p['insumos']),$preview['pedidos'])),'avisos'=>count($preview['avisos']),'ia'=>(int)($preview['ia_aplicada']??0)],
          'gemini_ativo'=>wa_gemini_disponivel(),
          'contatos'=>rcb_lista_selecao(),'pedidos'=>wa_prepare_list($preview,$all),'avisos'=>$preview['avisos']]);
    }catch(Throwable $e){@unlink($target);@unlink($importDir.'/'.$t.'.json');respond(['ok'=>false,'error'=>$e->getMessage()],422);}
}
if($action==='whatsapp_import_confirm'){
    $payload=json_decode(file_get_contents('php://input'),true);
    $t=(string)($payload['token']??'');
    if(!preg_match('/^[0-9a-f]{40}$/',$t))respond(['ok'=>false,'error'=>'Prévia expirada ou inválida.'],422);
    $path=$importDir.'/'.$t.'.json';
    if(!is_file($path) || filemtime($path)<time()-1800)respond(['ok'=>false,'error'=>'Prévia expirada (30 minutos). Envie o arquivo novamente.'],410);
    $preview=json_decode(file_get_contents($path),true);
    if(!is_array($preview)||!is_array($preview['pedidos']??null))respond(['ok'=>false,'error'=>'Prévia inválida.'],422);
    $format=(string)($preview['formato']??'zip');
    if(!in_array($format,['zip','pdf'],true))respond(['ok'=>false,'error'=>'Formato de prévia inválido.'],422);
    $source=$importDir.'/'.$t.'.'.$format;
    $expected=(string)($preview['hash_arquivo']??$preview['hash_zip']??'');
    // v3.14.0: a prévia (gravada no servidor) já tem os dados; o arquivo original não é mais necessário.
    if(empty($preview['sem_arquivo'])){
        if(!is_file($source))respond(['ok'=>false,'error'=>'Arquivo temporário expirado.'],410);
        if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,hash_file('sha256',$source)))respond(['ok'=>false,'error'=>'Arquivo não corresponde à prévia.'],409);
    }
    $wanted=$payload['pcs']??[];
    if(!is_array($wanted)||count($wanted)>400)respond(['ok'=>false,'error'=>'Seleção inválida.'],422);
    $selected=[];foreach($wanted as $pc){if(!is_int($pc)&&!ctype_digit((string)$pc))respond(['ok'=>false,'error'=>'PC inválido na seleção.'],422);$selected[(int)$pc]=true;}
    if(!$selected&&!array_filter($preview['pedidos'],fn($p)=>!empty($p['urgente_chat'])))respond(['ok'=>false,'error'=>'Selecione pelo menos um PC.'],422);
    // v3.29.0: destinatários do aviso de chegada escolhidos na prévia: {"PC":{"emails":[...],"origem":"..."}} ou {"PC":{"nenhum":true}}
    $avisarMap=[];
    if(is_array($payload['avisar']??null)){
        foreach($payload['avisar'] as $k=>$v){
            if(!ctype_digit((string)$k)||!is_array($v))continue;
            if(!empty($v['nenhum'])){$avisarMap[(int)$k]=['modo'=>'nenhum','lista'=>[]];continue;}
            $og=in_array($v['origem']??'',['solicitante','observacao'],true)?(string)$v['origem']:'manual';
            try{$l=rcb_validar_emails((array)($v['emails']??[]),$og);}catch(InvalidArgumentException $e){respond(['ok'=>false,'error'=>'PC '.(int)$k.': '.$e->getMessage()],422);}
            if($l)$avisarMap[(int)$k]=['modo'=>'definido','lista'=>$l];
        }
    }
    $lockFile=@fopen(DATA_DIR.'/.write.lock','c');
    if(!$lockFile||!flock($lockFile,LOCK_EX))respond(['ok'=>false,'error'=>'Sistema ocupado. Tente novamente.'],503);
    $items=read_items();$imported=[];$skipped=[];$stats=['novos'=>0,'completados'=>0,'ignorados'=>0];
    foreach($preview['pedidos'] as $p){
        $pc=(int)$p['pc'];if(!isset($selected[$pc]))continue;
        $s=wa_import_class($p,$items);
        if(!in_array($s['state'],['novo','completar'],true)){$stats['ignorados']++;$skipped[]=['pc'=>$pc,'motivo'=>$s['message']];continue;}
        if(!count($p['insumos'])||count($p['insumos'])>150){$stats['ignorados']++;continue;}
        $validated=validate_lines($p['insumos'],[]);
        $idx=find_index($items,$pc);
        if($idx>=0){
            $row=&$items[$idx];$row['insumos']=$validated;
            if(empty($row['solicitante']))$row['solicitante']=$p['solicitante'];
            if(empty($row['fornecedor']))$row['fornecedor']=$p['fornecedor'];
            $stats['completados']++;
        }else{
            $material=implode("\n",array_map(fn($i)=>$i['quantidade_pedida'].') '.$i['descricao'],$validated));
            $new=['pc'=>$pc,'fornecedor'=>$p['fornecedor'],'material'=>$material,'insumos'=>$validated,
                'solicitante'=>$p['solicitante'],'situacao'=>'PENDENTE','situacao_origem'=>'WHATSAPP',
                'chegou'=>'','recebimento_estado'=>'','entregas'=>[], 'origem_entrega'=>'',
                'novo_pedido_em'=>date('c'),
                // v3.9: a mensagem/PDF com "URGENTE"/"EMERGENCIAL" já entra marcada como emergencial.
                'urgente'=>preg_match('/\b(urgente|urgencia|urgência|emergencia|emergência|emergencial)\b/iu', json_encode($p, JSON_UNESCAPED_UNICODE)) ? '1' : ''];
            $items[]=normalize_item($new);$idx=count($items)-1;$row=&$items[$idx];$stats['novos']++;
        }
        $row['importacao_origem']=($format==='pdf'?'PDF individual':'WhatsApp ZIP') . (($p['leitura_origem']??'nativo')==='gemini'?' (IA)':'');$row['importacao_arquivo']=$p['pdf'];
        $row['importacao_hash']=$p['pdf_hash'];$row['importacao_em']=date('c');
        // v3.29.0: quem recebe o aviso de chegada (escolhido na prévia da importação)
        if(isset($avisarMap[$pc])){
            $row['avisar_modo']=$avisarMap[$pc]['modo'];$row['avisar_para']=$avisarMap[$pc]['lista'];$row['avisar_em']=date('c');
            $row['avisar_por']=(function_exists('rd_login_info')&&is_array(rd_login_info()))?(string)(rd_login_info()['username']??''):'importação';
        }
        // v3.20.0: obra/setor de destino lido do PDF (não sobrescreve o que foi definido à mão)
        if(($row['destino_origem']??'')!=='manual'){
            require_once __DIR__.'/rd_obras.php';
            $row['finalidade_pc']=(string)($p['destino']??($row['finalidade_pc']??''));$row['observacoes_pc']=(string)($p['observacoes']??($row['observacoes_pc']??''));
            $dd=rd_destino_detectar($row['observacoes_pc'].' '.$row['finalidade_pc'].' '.($row['obs']??''));
            if($dd){$row['destino_setor']=$dd['setor'];$row['obra_id']=$dd['obra_id']??'';$row['obra']=$dd['obra']??'';$row['destino_origem']='auto';}
        }
        // v3.32.0: OS/equipamento do pedido (regra de texto; se não achou, Gemini só confirma o que está escrito). Nunca sobrescreve o digitado à mão.
        if(($row['os_origem']??'')!=='manual'){
            $osx=['os'=>(string)($p['os']??''),'equipamento'=>(string)($p['equipamento']??'')];$osOrig='texto';
            if($osx['os']===''){
                $tx=trim((string)($p['observacoes']??'').' '.(string)($p['destino']??''));
                $rx=wa_os_extrair($tx);                                   // leitura via Gemini do PDF não passa pelo parser nativo
                if($rx['os']!==''||$rx['equipamento']!=='')$osx=['os'=>$rx['os'],'equipamento'=>$rx['equipamento']?:$osx['equipamento']];
                if($osx['os']===''){$ia=wa_os_via_ia($tx);if($ia['os']!==''||$ia['equipamento']!==''){$osx=['os'=>$ia['os'],'equipamento'=>$ia['equipamento']?:$osx['equipamento']];$osOrig='ia';}}
            }
            if($osx['os']!==''||$osx['equipamento']!==''){$row['os_pc']=$osx['os'];$row['equipamento_pc']=$osx['equipamento'];$row['os_origem']=$osOrig;}
        }
        // v3.18.0: urgência informada no chat do WhatsApp (só marca; nunca desmarca)
        if(!empty($p['urgente_chat'])&&($row['urgente']??'')!=='1'){$row['urgente']='1';$row['urgente_motivo']='WhatsApp ('.($p['urgente_chat']['autor']??'').'): '.($p['urgente_chat']['motivo']??'');$row['urgente_em']=$p['urgente_chat']['em']??date('c');$stats['urgentes']=($stats['urgentes']??0)+1;}
        mark_action($row,$format==='pdf'?'Itens do pedido importados de PDF individual':'Itens do pedido importados do WhatsApp');
        unset($row);
        $imported[]=$pc;
    }
    // v3.18.0: urgência do chat também vale para PCs que já existiam (só a marcação; nada mais muda)
    foreach($preview['pedidos'] as $p){
        if(empty($p['urgente_chat']))continue;
        $i=find_index($items,(int)$p['pc']);if($i<0||($items[$i]['urgente']??'')==='1')continue;
        $items[$i]['urgente']='1';$items[$i]['urgente_motivo']='WhatsApp ('.($p['urgente_chat']['autor']??'').'): '.($p['urgente_chat']['motivo']??'');$items[$i]['urgente_em']=$p['urgente_chat']['em']??date('c');
        mark_action($items[$i],'Marcado como URGENTE pela mensagem do WhatsApp');
        $stats['urgentes']=($stats['urgentes']??0)+1;
        if(!in_array((int)$p['pc'],$imported,true))$imported[]=(int)$p['pc'];
    }
    if($imported){
        // Guardar cópia íntegra do pedido PDF individual em diretório privado.
        // A importação nunca altera os arquivos fiscais nem o histórico anterior.
        if(false){ // v3.14.0: PDFs dos pedidos não são mais arquivados no servidor (só os dados são gravados)
            $private=DATA_DIR.'/order_pdfs';
            if(!is_dir($private) && !@mkdir($private,0750,true))respond(['ok'=>false,'error'=>'Pasta privada de pedidos indisponível.'],500);
            if(!is_file($private.'/.htaccess') && @file_put_contents($private.'/.htaccess',"Require all denied\nOptions -Indexes\n")===false)
                respond(['ok'=>false,'error'=>'Não foi possível proteger os PDFs dos pedidos.'],500);
            $pcArchived=(int)$imported[0];
            $archivedFile=$private.'/pc_'.$pcArchived.'_'.substr($expected,0,16).'.pdf';
            if(!is_file($archivedFile) && !@copy($source,$archivedFile))respond(['ok'=>false,'error'=>'Falha ao arquivar o PDF original; nada foi importado.'],500);
        }
        write_items($items);
        // Aviso para que todos os navegadores abertos atualizem a lista e o contador NOVO.
        // O evento não marca como nova uma PC existente apenas complementada pelo PDF.
        foreach($preview['pedidos'] as $p){
            $pc=(int)$p['pc'];
            if(!in_array($pc,$imported,true))continue;
            $rowIdx=find_index($items,$pc);
            if($rowIdx>=0 && !empty($items[$rowIdx]['novo_pedido_em']))
                append_event($pc,'novo_pedido',$format==='pdf'?'Novo pedido cadastrado por PDF individual':'Novo pedido cadastrado pelo WhatsApp');
        }
    }
    // Lote repetido pode ser consultado, mas nunca replica pedidos nem suas entregas.
    $historyFile=DATA_DIR.'/whatsapp_import_history.json';
    $history=is_file($historyFile)?json_decode(file_get_contents($historyFile),true):[];
    if(!is_array($history))$history=[];
    $history[]=['em'=>date('c'),'formato'=>$format,'hash_arquivo'=>$expected,'pcs'=>$imported,'totais'=>$stats];
    @file_put_contents($historyFile,json_encode(array_slice($history,-100),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
    @unlink($path);@unlink($source);
    respond(['ok'=>true,'totais'=>$stats,'pcs_importados'=>$imported,'ignorados'=>$skipped]);
}
respond(['ok'=>false,'error'=>'Ação inválida.'],404);
