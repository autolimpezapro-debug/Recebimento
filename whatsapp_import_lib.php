<?php
/** Importador ZIP WhatsApp: leitura em memória de PDFs SAP Business One e mensagens.
 * Sem bibliotecas remotas, sem executar comandos do shell e sem descompactar arquivos em public_html.
 *
 * Camada de leitura (v3.5.1):
 *   1) parser nativo (texto do PDF), agora tolerante a variantes de acento/espaçamento
 *      e a dois formatos do cabeçalho "Número do documento";
 *   2) quando o nativo falha ou não extrai itens e o Gemini está habilitado, o PDF é
 *      enviado à IA (gemini_import.php) e entra na prévia marcado como "Lido por IA".
 */
function wa_le16($s,$p) {return unpack('v',substr($s,$p,2))[1];}
function wa_le32($s,$p) {return unpack('V',substr($s,$p,4))[1];}
function wa_zip_entries($path) {
    // v3.14.0: leitura direto do disco (ZipArchive) — ZIP de até 1 GB sem carregar tudo na memória.
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('O arquivo não é uma exportação ZIP válida.');
        $out = []; $total = 0; $n = $z->numFiles;
        if ($n > 50000) { $z->close(); throw new RuntimeException('ZIP contém arquivos demais.'); }
        for ($i = 0; $i < $n; $i++) {
            $st = $z->statIndex($i); if (!$st) continue;
            $filename = (string)$st['name']; $base = basename(str_replace('\\', '/', $filename));
            if (!preg_match('/\.(txt|pdf)$/i', $base) || str_contains($filename, "\0")) continue;
            if ($st['size'] > 30 * 1048576) continue;
            $total += $st['size']; if ($total > 600 * 1048576) { $z->close(); throw new RuntimeException('Os PDFs/conversa dentro do ZIP passam de 600 MB descompactados.'); }
            if (count($out) >= 2000) break;
            $c = $z->getFromIndex($i);
            if (!is_string($c) || strlen($c) !== (int)$st['size']) continue;
            $out[] = ['name' => $base, 'bytes' => $c];
        }
        $z->close();
        return $out;
    }
    $data=@file_get_contents($path);
    if($data===false || strlen($data)>45*1024*1024)throw new RuntimeException('ZIP inválido ou acima do limite de 45 MB.');
    $eocd=strrpos($data,"PK\x05\x06");
    if($eocd===false || strlen($data)<$eocd+22)throw new RuntimeException('O arquivo não é uma exportação ZIP válida.');
    $n=wa_le16($data,$eocd+10);$off=wa_le32($data,$eocd+16);
    if($n>900 || $off>=strlen($data))throw new RuntimeException('ZIP contém arquivos demais ou formato ZIP64 não suportado.');
    $out=[];$total=0;
    for($i=0;$i<$n;$i++) {
        if(substr($data,$off,4)!=="PK\x01\x02")throw new RuntimeException('Diretório ZIP inválido.');
        $method=wa_le16($data,$off+10);$compressed=wa_le32($data,$off+20);$size=wa_le32($data,$off+24);
        $nl=wa_le16($data,$off+28);$el=wa_le16($data,$off+30);$cl=wa_le16($data,$off+32);
        $relative=wa_le32($data,$off+42);$filename=substr($data,$off+46,$nl);
        $off+=46+$nl+$el+$cl;
        if($size>6*1024*1024 || $compressed>6*1024*1024)continue;
        $total+=$size;if($total>130*1024*1024)throw new RuntimeException('ZIP descompactado acima do limite de segurança.');
        // Não usar caminho de dentro do ZIP como destino de escrita; somente arquivos identificados.
        $base=basename(str_replace('\\','/',$filename));
        if(!preg_match('/\.(txt|pdf)$/i',$base) || str_contains($filename,"\0"))continue;
        if(substr($data,$relative,4)!=="PK\x03\x04")continue;
        $localname=wa_le16($data,$relative+26);$localextra=wa_le16($data,$relative+28);
        $pos=$relative+30+$localname+$localextra;
        if($pos+$compressed>strlen($data))continue;
        $bin=substr($data,$pos,$compressed);
        $contents=$method===0?$bin:($method===8?@gzinflate($bin):false);
        if(!is_string($contents) || strlen($contents)!==$size)continue;
        $out[]=['name'=>$base,'bytes'=>$contents,'hash'=>hash('sha256',$contents)];
    }
    return $out;
}
function wa_pdf_unescape($s){
    return preg_replace_callback('/\\\\([0-7]{1,3}|.)/s',static function($m){
        $a=$m[1];if(preg_match('/^[0-7]+$/',$a))return chr(octdec($a));
        return match($a){'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\x0c", "\n"=>'',default=>$a};
    },$s);
}
function wa_pdf_font_map($source){
    preg_match_all('~(\d+)\s+0\s+obj\b(.*?)endobj~s',$source,$om,PREG_SET_ORDER);
    $objs=[];
    foreach($om as $m)$objs[(int)$m[1]]=$m[2];
    // PDFs do Crystal Reports armazenam fontes dentro de /ObjStm (objetos comprimidos).
    foreach($objs as $container){
        if(!preg_match('~/Type\s*/ObjStm\b~',$container))continue;
        $raw=wa_pdf_stream($container);
        if(!$raw || !preg_match('~/N\s+(\d+)\b~',$container,$nm)||!preg_match('~/First\s+(\d+)\b~',$container,$fm))continue;
        $count=(int)$nm[1];$first=(int)$fm[1];
        if($count>500 || $first>strlen($raw))continue;
        preg_match_all('/(\d+)\s+(\d+)/',substr($raw,0,$first),$pairs,PREG_SET_ORDER);
        for($j=0;$j<min($count,count($pairs));$j++){
            $start=$first+(int)$pairs[$j][2];
            $end=isset($pairs[$j+1])?$first+(int)$pairs[$j+1][2]:strlen($raw);
            $objs[(int)$pairs[$j][1]]=substr($raw,$start,$end-$start);
        }
    }
    $maps=[];
    foreach($objs as $number=>$obj){
        if(!preg_match('~/ToUnicode\s+(\d+)\s+0\s+R~',$obj,$mr))continue;
        $cm=$objs[(int)$mr[1]]??'';
        $stream=wa_pdf_stream($cm);
        $map=[];
        if(preg_match_all('/<([A-Fa-f0-9]{2,4})>\s*<([A-Fa-f0-9]{2,4})>\s*<([A-Fa-f0-9]{4,12})>/',$stream,$tr,PREG_SET_ORDER)){
            foreach($tr as $line){
                $start=hexdec($line[1]);$end=hexdec($line[2]);$unicode=hexdec($line[3]);
                for($i=$start;$i<=$end&&$i-$start<256;$i++){
                    $u=$unicode+($i-$start);$utf=($u<65536?pack('n',$u):pack('N',$u));
                    $decoded=@iconv($u<65536?'UTF-16BE':'UTF-32BE','UTF-8',$utf);
                    $map[$i]=$decoded===false?'':$decoded;
                }
            }
        }
        $maps[$number]=$map;
    }
    // PDFium usa fontes /WinAnsiEncoding sem CMap explícito.
    foreach($objs as $id=>$obj){
        if(isset($maps[$id]) || !preg_match('~/Encoding\s*/WinAnsiEncoding~',$obj))continue;
        $table=[];for($n=0;$n<256;$n++){
            $v=@iconv('Windows-1252','UTF-8',chr($n));$table[$n]=$v===false?'':$v;
        }
        $maps[$id]=$table;
    }
    $fonts=[];
    foreach($objs as $obj){
        if(!preg_match('~/Font\s*<<(.+?)>>~s',$obj,$block))continue;
        if(preg_match_all('~/([A-Za-z0-9]+)\s+(\d+)\s+0\s+R~',$block[1],$refs,PREG_SET_ORDER)){
            foreach($refs as $r)if(isset($maps[(int)$r[2]]))$fonts[$r[1]]=$maps[(int)$r[2]];
        }
    }
    return [$objs,$fonts];
}
function wa_pdf_stream($obj){
    $p=strpos($obj,'stream');if($p===false)return '';
    $data=substr($obj,$p+6);
    if(str_starts_with($data,"\r\n"))$data=substr($data,2);
    elseif(str_starts_with($data,"\n")||str_starts_with($data,"\r"))$data=substr($data,1);
    if(preg_match('~/Length\s+(\d+)\b~',substr($obj,0,$p),$length))$data=substr($data,0,(int)$length[1]);
    else {$end=strpos($data,'endstream');if($end!==false)$data=substr($data,0,$end);$data=preg_replace('/\r?\n$/','',$data);}

    return str_contains(substr($obj,0,$p),'/FlateDecode')?(@gzuncompress($data)?:''):$data;
}
function wa_pdf_decode($str,$map){
    $str=wa_pdf_unescape($str);$out='';
    foreach(str_split($str) as $ch)$out.=$map[ord($ch)]??(ord($ch)>=32&&ord($ch)<127?$ch:'');
    return $out;
}
function wa_pdf_extract($source){
    if(substr($source,0,5)!=='%PDF-')return [];
    [$objs,$fonts]=wa_pdf_font_map($source);
    if(!$fonts)return [];
    $segments=[];$currentFont='';$fontSize=8;$pdfium=str_contains($source,'PDFium');
    foreach($objs as $obj){
        $content=wa_pdf_stream($obj);
        if(!str_contains($content,'BT')||!str_contains($content,'Tj'))continue;
        preg_match_all('/\bBT\b(.*?)\bET\b/s',$content,$blocks,PREG_SET_ORDER);
        foreach($blocks as $block){
            $x=0;$y=0;$txt='';
            $re='~\/([A-Za-z0-9]+)\s+[-.\d]+\s+Tf|[-.\d]+\s+[-.\d]+\s+[-.\d]+\s+[-.\d]+\s+([-.\d]+)\s+([-.\d]+)\s+Tm|([-.\d]+)\s+([-.\d]+)\s+Td|\(((?:\\\\.|[^\\\\)])*)\)\s*Tj|\[((?:\\\\.|[^\]])*)\]\s*TJ~s';
            preg_match_all($re,$block[1],$tokens,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
            foreach($tokens as $t){
                if($t[1]!==null){$currentFont=$t[1];if(preg_match('~/'.preg_quote($currentFont,'~').'\s+([\d.]+)\s+Tf~',$t[0],$fm))$fontSize=(float)$fm[1];}
                elseif($t[2]!==null){$x=(float)$t[2];$y=(float)$t[3];}
                elseif($t[4]!==null){$x+=(float)$t[4];$y+=(float)$t[5];}
                else{
                    if($t[6]!==null)$v=wa_pdf_decode($t[6],$fonts[$currentFont]??[]);
                    else{
                        $v='';preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s',$t[7]??'',$inner);
                        foreach($inner[1] as $a)$v.=wa_pdf_decode($a,$fonts[$currentFont]??[]);
                    }
                    if($v!=='' && (!$pdfium || trim($v)!=='' || $v===' '))$segments[]=['x'=>$x,'y'=>-$y,'text'=>$pdfium?$v:trim($v),'size'=>$fontSize];
                }
            }
        }
    }
    usort($segments,static fn($a,$b)=>($a['y']<=>$b['y']) ?:($a['x']<=>$b['x']));
    $lines=[];$current=null;
    foreach($segments as $s){
        $yr=round($s['y']*2)/2;
        if($current===null || abs($yr-$current)>2.3){$lines[]=['y'=>$yr,'parts'=>[]];$current=$yr;}
        $lines[count($lines)-1]['parts'][]=$s;
    }
    $out=[];
    foreach($lines as $line){
        usort($line['parts'],static fn($a,$b)=>$a['x']<=>$b['x']);
        if($pdfium){
            $text='';$endX=null;
            foreach($line['parts'] as $part){
                if($endX!==null && $part['x']-$endX>max(4,(float)$part['size']*0.8))$text.=' ';
                $text.=$part['text'];
                $endX=$part['x']+strlen($part['text'])*(float)$part['size']*.48;
            }
            $out[]=['y'=>$line['y'],'line'=>trim(preg_replace('/\s+/u',' ',$text))];
        }else $out[]=['y'=>$line['y'],'line'=>implode(' ',array_column($line['parts'],'text'))];
    }
    return $out;
}
/** Lê o número do documento nas variantes que o SAP imprime ("Nº do documento", "N0º do
c * documento" colado no horário da data de entrega, sem acento etc). */
function wa_docpc_from_text(string $text): int {
    $m = [];
    if (preg_match('/N\s*[0º°oO]{0,3}\s*do\s*documento\s*:\s*([0-9][0-9.,]{2,11})/iu', $text, $m)
        || preg_match('/documento\s*:\s*N?\s*[0º°oO]{0,3}\s*([0-9][0-9.,]{2,11})/iu', $text, $m)) {
        $digits = preg_replace('/[^0-9]/', '', (string)$m[1]);
        $pc = (int)$digits;
        if (strlen($digits) >= 3 && strlen($digits) <= 7) return $pc;
    }
    // Variante com texto "entrelaçado" (P E D I D O...): tenta sem espaços.
    $tight = preg_replace('/\s+/u', '', $text);
    if (preg_match('/N[0º°oO]{0,3}dodocumento:([0-9][0-9.,]{2,11})/iu', (string)$tight, $m)) {
        $digits = preg_replace('/[^0-9]/', '', (string)$m[1]);
        if (strlen($digits) >= 3 && strlen($digits) <= 7) return (int)$digits;
    }
    return 0;
}
/**
 * v3.32.0: OS e equipamento escritos no pedido de compra (observações, finalidade, texto do PDF).
 * Regras conservadoras: "OS"/"O.S." só em MAIÚSCULAS (para não confundir com o artigo "os"), ou "ordem de serviço/manutenção".
 * Retorna ['os'=>'', 'equipamento'=>'']. Nunca inventa: sem padrão claro, devolve vazio.
 */
function wa_os_extrair($texto){
    $t=preg_replace('/\s+/u',' ',(string)$texto);$os='';$eq='';
    if(preg_match('/\b(?:O\.?\s?S\.?|OS)\s*(?:N[º°Oo]\.?|NUM(?:ERO)?\.?)?\s*[:#\-]?\s*(\d{3,10}(?:[\/\-]\d{1,4})?)\b/u',$t,$m))$os=$m[1];
    elseif(preg_match('/\bordem\s+de\s+(?:servi[çc]o|manuten[çc][ãa]o)\s*(?:n[º°o]\.?|n[úu]mero)?\s*[:#\-]?\s*(\d{3,10}(?:[\/\-]\d{1,4})?)\b/iu',$t,$m))$os=$m[1];
    if(preg_match('/\bPAT\.?\s*[:#\-]?\s*(\d{4,8})\b/iu',$t,$m))$eq='PAT '.$m[1];
    elseif(preg_match('/\bequipamento\s*[:\-]\s*([^.;|]{3,80})/iu',$t,$m))$eq=trim($m[1]);
    return ['os'=>mb_strtoupper(trim($os)),'equipamento'=>$eq];
}
/**
 * v3.32.0: só quando a regra não achou OS e o texto sugere que há uma: pergunta ao Gemini, em texto curto (observações/finalidade).
 * A resposta só vale se a OS (e o equipamento) aparecem LITERALMENTE no texto enviado — nada de invenção.
 */
function wa_os_via_ia($texto){
    $vazio=['os'=>'','equipamento'=>''];
    $texto=trim(preg_replace('/\s+/u',' ',(string)$texto));
    if(mb_strlen($texto)<8 || !preg_match('/\b(OS|O\.S|ordem|equip|manuten|PAT|m[aá]quina|perfuratriz|compressor)/iu',$texto))return $vazio;
    require_once __DIR__.'/gemini_import.php';
    if(!function_exists('rd_gemini_ready') || !rd_gemini_ready())return $vazio;
    $texto=mb_substr($texto,0,1500);
    try{
        $raw=rd_gemini_call([['text'=>"Do texto abaixo (observações de um pedido de compra), extraia SOMENTE se estiver escrito de forma explícita: o número da ordem de serviço (OS) e o equipamento onde o material será aplicado. Se não houver, deixe vazio. Nunca invente nem deduza. Responda só JSON: {\"os\":\"\",\"equipamento\":\"\"}.\n\nTEXTO:\n".$texto]],300);
        $j=json_decode($raw,true);if(!is_array($j))return $vazio;
        $os=mb_strtoupper(trim((string)($j['os']??'')));$eq=trim((string)($j['equipamento']??''));
        $norm=fn($x)=>mb_strtolower(preg_replace('/\s+/u','',(string)$x));
        $tn=$norm($texto);
        if($os!=='' && (!preg_match('/^[A-Z0-9][A-Z0-9 .\/-]{1,39}$/u',$os) || !str_contains($tn,$norm($os))))$os='';
        if($eq!=='' && (mb_strlen($eq)>120 || !str_contains($tn,$norm($eq))))$eq='';
        return ['os'=>$os,'equipamento'=>$eq];
    }catch(Throwable $e){error_log('OS via IA: '.$e->getMessage());return $vazio;}
}
function wa_pc_from_pdf($pdf,$filename){
    $lines=wa_pdf_extract($pdf);
    $text=implode("\n",array_column($lines,'line'));
    $namepc=preg_match('/\bPC\s*([0-9]{3,7})\b/i',$filename,$m)?(int)$m[1]:0;
    $docpc=wa_docpc_from_text($text);
    if($docpc && $namepc && $docpc!==$namepc)return ['error'=>'Número do PC no nome difere do PDF.','name'=>$filename];
    $pc=$docpc?:$namepc;
    // Cabeçalho tolerante: acentos, espaços múltiplos, caixa e variantes entrelaçadas.
    $temCabecalho = preg_match('/PEDIDO\s*DE\s*COMPRA/iu',$text) === 1
        || str_contains((string)preg_replace('/\s+/u','',$text),'PEDIDOCOMPRA');
    if(!$pc || !$temCabecalho)return ['error'=>'Não é um pedido de compra SAP reconhecido.','name'=>$filename];
    $supplier='';$cnpj='';$buyer='';$requester='';$destination='';$items=[];
    if(preg_match('/\bF\d{10,15}\s+(.+?)\s+Data de entrega:/iu',$text,$m))$supplier=trim($m[1]);
    if(!$supplier && preg_match('/^PC\s*\d+\s+(.+?)(?:\s*\([^)]*\))?\.pdf$/iu',$filename,$m))$supplier=trim($m[1]);
    if(preg_match_all('/CNPJ:\s*([0-9.\/\-]+)/iu',$text,$m) && count($m[1])>1)$cnpj=end($m[1]);
    if(preg_match('/Vendedor\s*\/\s*Comprador:\s*(.+)/iu',$text,$m))$buyer=trim($m[1]);
    if(preg_match('/Finalidade:\s*(.+)/iu',$text,$m))$destination=trim($m[1]);
    if(preg_match('/SOLICITANTE\s*[-:]\s*(.+?)(?:Baseado em|$)/iu',$text,$m))$requester=trim($m[1]);
    // v3.20.0: observações do pedido (usadas para descobrir a obra/setor de destino)
    $observacoes='';
    if(preg_match('/Observa[çc][õo]es do pedido:\s*(.+?)(?:Condi[çc][õo]es de pagamento|Assinatura|Aprovad|$)/isu',$text,$m))$observacoes=trim(preg_replace('/\s{2,}[A-Z][a-zçãõé ]+:\s+R\$\s*[\d.,]+/u',' ',preg_replace('/\s+/u',' ',mb_substr($m[1],0,1200))));
    // Itens SAP: código (SKU), descrição, unidade, quantidade, preço.
    foreach($lines as $row){
        $l=trim($row['line']);
        if(!preg_match('/^([A-Z]{1,5}\d{4,10})\s+(.+?)\s+(?:(UN|PC|PÇ|KG|LT|L|M|MT|M2|M3|PA|CX|JG|RL|FD|SC|GL|BD|CJ|HR|SV|PAR|KIT|LITRO|BALDE|TON|MTS|SERV)\s+)?(\d{1,9}(?:\.\d{3})*(?:,\d{1,4})?)\s+([\d.,]+)\s+(?:[\d.,]+\s+){5}\d{2}\/\d{2}\/\d{4}/iu',$l,$m))continue;
        $qty=(float)str_replace(',','.',str_replace('.','',$m[4]));
        if($qty<=0||$qty>1000000)continue;
        $items[]=['id'=>'sku_'.$m[1],'codigo'=>$m[1],'descricao'=>trim($m[2]),'unidade'=>substr($m[3]?:'UN',0,20),'quantidade_pedida'=>$qty,'quantidade_recebida'=>0,'conferido_etapa'=>false];
    }
    // Quando o mesmo SKU aparece em várias linhas, não misturar silenciosamente.
    $used=[];foreach($items as &$i){$k=$i['id'];$n=$used[$k]??0;$used[$k]=$n+1;if($n)$i['id'].='_'.($n+1);}unset($i);
    $osx=wa_os_extrair($observacoes.' '.$destination);   // v3.32.0: só observações/finalidade (o resto do PDF tem preços, CNPJ etc.)
    return ['pc'=>$pc,'fornecedor'=>$supplier,'cnpj'=>$cnpj,'comprador'=>$buyer,'solicitante'=>$requester,'destino'=>$destination,'observacoes'=>mb_substr($observacoes,0,600),'os'=>$osx['os'],'equipamento'=>$osx['equipamento'],'insumos'=>$items,'pdf'=>$filename,'pdf_hash'=>hash('sha256',$pdf),'texto_extraido'=>count($lines)>10];
}
/* ------------------------------------------------------------------ */
/* Fallback Gemini: quando o parser nativo não conclui a leitura       */
/* ------------------------------------------------------------------ */

const WA_GEMINI_MAX_PDF_POR_LOTE = 25;

/** O Gemini está habilitado e disponível para uso agora? */
function wa_gemini_disponivel(): bool {
    static $check = null;
    if ($check !== null) return $check;
    if (!is_file(__DIR__ . '/gemini_import.php')) { $check = false; return $check; }
    require_once __DIR__ . '/gemini_import.php';
    if (!function_exists('rd_gemini_ready') || !rd_gemini_ready()) { $check = false; return $check; }
    $check = true; return $check;
}

/**
 * Lê um PDF de pedido: primeiro o parser nativo; se não identificar PC ou não
 * extrair os itens, tenta o Gemini automaticamente (quando habilitado).
 * Retorna sempre um registro com 'pdf' + ('pc','insumos') ou ('erro').
 */
function wa_pdf_best_effort(string $bytes, string $fileName, bool $allowIA = true): array {
    $row = wa_pc_from_pdf($bytes, $fileName);
    if (!empty($row['pc']) && !empty($row['insumos'])) {
        $row['leitura_origem'] = 'nativo';
        return $row;
    }
    $erroNativo = (string)($row['error'] ?? ((int)($row['pc'] ?? 0) ? 'sem itens' : 'pedido nao reconhecido'));
    if (!$allowIA || !wa_gemini_disponivel()) {
        return ['pdf' => $fileName, 'pc' => (int)($row['pc'] ?? 0), 'erro' => $erroNativo . ' — leitura por IA indisponivel ou limite do lote atingido'];
    }
    if (strlen($bytes) > 30 * 1024 * 1024 || !str_starts_with($bytes, '%PDF-')) {
        return ['pdf' => $fileName, 'pc' => (int)($row['pc'] ?? 0), 'erro' => $erroNativo . ' — PDF maior que 6 MB ou invalido para a IA'];
    }
    try {
        $g = rd_gemini_parse_pdf($bytes, $fileName);
    } catch (Throwable $e) {
        return ['pdf' => $fileName, 'pc' => (int)($row['pc'] ?? 0), 'erro' => 'Nativo: ' . $erroNativo . ' · IA: ' . $e->getMessage()];
    }
    if (!empty($g['pc']) && !empty($g['insumos'])) {
        $g['leitura_origem'] = 'gemini';
        $g['pc_nativo'] = (int)($row['pc'] ?? 0);
        return $g;
    }
    return ['pdf' => $fileName, 'pc' => (int)($row['pc'] ?? 0),
        'erro' => 'Nativo: ' . $erroNativo . ' · IA: ' . ($g['error'] ?? 'sem leitura')];
}

/**
 * v3.18.0: lê a conversa do WhatsApp e descobre quais pedidos foram pedidos com URGÊNCIA.
 * Uma mensagem com "urgente/urgência/emergência/emergencial/prioridade" marca:
 *  - os PCs citados nela (número de 4–5 dígitos que exista entre os pedidos lidos);
 *  - o PDF anexado na própria mensagem (legenda do anexo);
 *  - se não citar nada: os PDFs enviados pela mesma pessoa até 15 min antes/depois (máx. 3 mensagens de distância).
 * @return array pc => ['motivo' => trecho, 'em' => data, 'autor' => nome]
 */
function wa_chat_urgentes(array $chats, array $pdfPc, array $pcsValidos): array {
    $msgs = [];
    foreach ($chats as $txt) {
        $txt = str_replace(["\u{200E}", "\u{200F}", "\u{202A}", "\u{202C}", "\r"], '', $txt);
        foreach (preg_split('/\n/u', $txt) as $l) {
            if (preg_match('/^\[?(\d{1,2})\/(\d{1,2})\/(\d{2,4})[,\s]+(\d{1,2}):(\d{2})(?::\d{2})?\]?\s*(?:-\s*)?([^:]{1,60}):\s?(.*)$/u', $l, $m)) {
                $ano = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
                $msgs[] = ['t' => mktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[1], (int)$ano), 'autor' => trim($m[6]), 'txt' => $m[7]];
            } elseif ($msgs) $msgs[count($msgs) - 1]['txt'] .= "\n" . $l;
        }
    }
    $porNome = [];
    foreach ($pdfPc as $nome => $pc) $porNome[mb_strtolower($nome)] = $pc;
    $pdfsDa = function (string $t) use ($porNome) {
        $pcs = [];
        if (preg_match_all('/([^\s<>:"\/\\|?*][^<>:"\/\\|?*\n]*?\.pdf)/iu', $t, $mm)) foreach ($mm[1] as $f) {
            $f = mb_strtolower(trim(preg_replace('/^(?:<?anexado:\s*|‎)/iu', '', $f)));
            if (isset($porNome[$f])) $pcs[] = $porNome[$f];
            else foreach ($porNome as $n => $pc) if (str_ends_with($f, $n) || str_ends_with($n, $f)) { $pcs[] = $pc; break; }
        }
        return $pcs;
    };
    $urg = [];
    $re = '/(*UCP)\\b(urg[eê]n\\w*|emerg[eê]n\\w*|emergencial|prioridade|priorit[aá]ri\\w*|pra hoje|para hoje|imediat\\w*)\\b/iu';
    foreach ($msgs as $i => $m) {
        if (!preg_match($re, $m['txt'])) continue;
        $alvo = [];
        if (preg_match_all('/\b(\d{4,5})\b/', $m['txt'], $nn)) foreach ($nn[1] as $n) if (isset($pcsValidos[(int)$n])) $alvo[] = (int)$n;
        $alvo = array_merge($alvo, $pdfsDa($m['txt']));
        if (!$alvo) for ($j = max(0, $i - 3); $j <= min(count($msgs) - 1, $i + 3); $j++) {
            if ($j === $i || $msgs[$j]['autor'] !== $m['autor'] || abs($msgs[$j]['t'] - $m['t']) > 900) continue;
            $alvo = array_merge($alvo, $pdfsDa($msgs[$j]['txt']));
        }
        foreach (array_unique($alvo) as $pc) if (!isset($urg[$pc]))
            $urg[$pc] = ['motivo' => mb_substr(trim(preg_replace('/\s+/u', ' ', $m['txt'])), 0, 160), 'em' => date('c', $m['t']), 'autor' => $m['autor']];
    }
    return $urg;
}

function wa_zip_preview($zipPath){
    $entries=wa_zip_entries($zipPath);$chat=[];$results=[];$pdfCount=0;$iaUsadas=0;$textos=[];
    foreach($entries as $file){
        if(preg_match('/\.txt$/i',$file['name'])){
            $textos[]=$file['bytes'];
            if(str_contains($file['bytes'],'PEDIDO COMPRA COLETA'))$chat[]=['nome'=>$file['name'],'mensagens'=>substr_count($file['bytes'], ' - ')];
            continue;
        }
        if(!preg_match('/\.pdf$/i',$file['name']))continue;
        $pdfCount++;
        $row=wa_pdf_best_effort($file['bytes'],$file['name'],$iaUsadas<WA_GEMINI_MAX_PDF_POR_LOTE);
        if(($row['leitura_origem']??'')==='gemini')$iaUsadas++;
        $results[]=$row;
    }
    $unique=[];$warnings=[];
    foreach($results as $r){
        if(empty($r['pc'])||empty($r['insumos'])){$warnings[]=['pdf'=>$r['pdf'],'erro'=>$r['erro']??'PDF sem itens reconhecidos','pc'=>(int)($r['pc']??0)];continue;}
        $pc=(int)$r['pc'];
        if(isset($unique[$pc])){
            if(count($r['insumos'])>count($unique[$pc]['insumos']))$unique[$pc]=$r;
        }else $unique[$pc]=$r;
    }
    // v3.18.0: urgência lida das mensagens do chat
    $pdfPc=[];foreach($results as $r)if(!empty($r['pc']))$pdfPc[(string)$r['pdf']]=(int)$r['pc'];
    $urg=$textos?wa_chat_urgentes($textos,$pdfPc,array_fill_keys(array_keys($unique),true)):[];
    foreach($urg as $pc=>$u)if(isset($unique[$pc]))$unique[$pc]['urgente_chat']=$u;
    return ['pedidos'=>array_values($unique),'avisos'=>$warnings,'pdfs'=>$pdfCount,'mensagens'=>$chat,'arquivos'=>count($entries),'ia_aplicada'=>$iaUsadas,'urgentes_chat'=>count($urg)];
}

/** Prévia de um documento PDF individual, sem exportação ZIP do WhatsApp. */
function wa_single_pdf_preview(string $path,string $fileName): array {
    $bytes=@file_get_contents($path);
    if(!is_string($bytes)||strlen($bytes)>30*1024*1024||!str_starts_with($bytes,'%PDF-'))
        throw new RuntimeException('Selecione um arquivo PDF válido de até 30 MB.');
    // Mesmo fluxo do ZIP: nativo primeiro, Gemini automático se o nativo não concluir.
    $row=wa_pdf_best_effort($bytes,$fileName);
    if(empty($row['pc'])||empty($row['insumos']))
        throw new RuntimeException(($row['erro']??'PDF sem pedido reconhecido'). ' O documento precisa conter texto SAP legível ou ser lido pela IA (ative Gemini na tela abaixo).');
    return ['pedidos'=>[$row],'avisos'=>[],'pdfs'=>1,'mensagens'=>[],'arquivos'=>1,'ia_aplicada'=>($row['leitura_origem']??'')==='gemini'?1:0];
}
