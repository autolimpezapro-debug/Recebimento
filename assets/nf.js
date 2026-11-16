/* Recebimento Drilling v2.9.4 - NF: XML, chave explícita, QR com fallback e OCR assistido. Sem consulta à SEFAZ. */
'use strict';
let nfCurrent=null, nfXMLFile=null, nfSource='', nfScanStream=null, nfScanTimer=null, nfScanPc=null;
const digits=x=>String(x||'').replace(/\D/g,'');
function nfCheckDigit(key){const p=digits(key);if(p.length!==44)return false;let total=0,w=2;for(let k=42;k>=0;k--){total+=Number(p[k])*w;w=w===9?2:w+1}const v=11-total%11;return Number(p[43])===(v>=10?0:v)}
function decodeNFeKey(input){const key=digits(input);if(key.length!==44)throw Error('A chave precisa conter exatamente 44 dígitos.');if(!nfCheckDigit(key))throw Error('Dígito verificador da chave inválido. Confira a leitura.');return {chaveNF:key,cnpjEmitente:key.slice(6,20),serieNF:String(Number(key.slice(22,25))),numeroNF:String(Number(key.slice(25,34))),model:key.slice(20,22),uf:key.slice(0,2),aamm:key.slice(2,6)};}
function keyFromText(raw){const txt=String(raw||'');const combined=txt.match(/(?:\d[\s.\-]*){44,65}/g)||[];for(const g of combined){const str=digits(g);for(let i=0;i<=str.length-44;i++){const k=str.slice(i,i+44);if(nfCheckDigit(k))return k}}const p=txt.match(/[?&]p=([0-9]{44})/i);if(p&&nfCheckDigit(p[1]))return p[1];return null}
function $nf(id){return document.getElementById(id)}
function setNfNotice(msg){$nf('nfMessages').textContent=msg||''}
function showNFPreview(pc,info,source='MANUAL',xmlFile=null,message=''){
 nfCurrent=pc;nfSource=source;nfXMLFile=xmlFile;
 const existing=items.find(x=>x.pc===pc)||{};
 const data={...info};
 const fields={nfNum:'numeroNF',nfSerie:'serieNF',nfKey:'chaveNF',nfCnpj:'cnpjEmitente',nfSupplier:'fornecedor',nfEmission:'emissaoNF',nfTotal:'valorNF',nfProducts:'itensNF'};
 // A leitura parcial da chave ou do OCR não pode apagar valores já conferidos.
 for(const [id,key] of Object.entries(fields)){
  const incoming=data[key];
  const fallback=existing[key];
  $nf(id).value=String(incoming!==undefined&&incoming!==null&&String(incoming).trim()!==''?incoming:(fallback??''));
 }
 // Troque automaticamente o fornecedor apenas se não houver fornecedor no PC.
 $nf('nfReplaceSupplier').checked=Boolean(data.fornecedor&&!String(existing.fornecedor||'').trim());
 $nf('nfMethodLabel').textContent=({XML:'XML da NF-e',OCR:'Foto / OCR (revisão obrigatória)',CHAVE:'Chave de acesso validada',QRCODE:'Código escaneado',MANUAL:'Preenchimento manual',IA:'DANFE lida por IA (Gemini)'})[source]||source;
 setNfNotice(message||'Revise os campos antes de gravar. Dados incompletos podem ser preenchidos manualmente.');
 $nf('nfConfidence').textContent=source==='XML'?'Valores extraídos do XML recebido (sem verificar assinatura na SEFAZ).':source==='OCR'?'Reconhecimento óptico sujeito a erros. Confira todos os números.':source==='IA'?'Leitura da DANFE por IA, conferida com a chave de acesso. Revise produtos e valores antes de gravar.':source==='CHAVE'||source==='QRCODE'?'Somente número, série e CNPJ do emissor estão codificados na chave.':'Dados inseridos manualmente.';
 nfRenderIaBotao(source);
 $nf('nfModal').classList.add('on');
}
function closeNFPreview(){if($nf('nfSaveBtn').disabled)return;$nf('nfModal').classList.remove('on');nfCurrent=null;nfXMLFile=null;if(typeof refreshIfDeferred==='function')refreshIfDeferred()}
async function confirmNFPreview(){if(!nfCurrent)return;const pc=nfCurrent,existing=items.find(x=>x.pc===pc);if(!existing)return;
 const button=$nf('nfSaveBtn');button.disabled=true;button.textContent='Gravando…';
 try{
  const key=digits($nf('nfKey').value);
  if(key && !nfCheckDigit(key))throw Error('Chave de acesso inválida. Corrija ou apague antes de salvar.');
  const cnpj=digits($nf('nfCnpj').value);
  if(cnpj&&cnpj.length!==14)throw Error('O CNPJ deve ter 14 dígitos.');
  const patch={numeroNF:$nf('nfNum').value.trim(),serieNF:$nf('nfSerie').value.trim(),chaveNF:key,cnpjEmitente:cnpj,
     emissaoNF:$nf('nfEmission').value,valorNF:$nf('nfTotal').value.trim(),itensNF:$nf('nfProducts').value.trim(),origemNF:nfSource};
  if($nf('nfReplaceSupplier').checked)patch.fornecedor=$nf('nfSupplier').value.trim();
  // Important: upload XML only after operator confirms preview; original securely archived.
  if(nfXMLFile){const fd=new FormData();fd.append('pc',pc);fd.append('xml',nfXMLFile,nfXMLFile.name||'nota.xml');await api('upload_xml',{method:'POST',body:fd});}
  const response=await api('save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,...patch})});
  const ix=items.findIndex(i=>i.pc===pc);if(ix>=0)items[ix]=normalized(response.item);
  // Read canonical record after XML association rather than overwriting it with older save state.
  if(nfXMLFile){const fresh=await api('list');items=fresh.items.map(normalized)}
  $nf('nfModal').classList.remove('on');nfCurrent=null;nfXMLFile=null;renderAll();if(typeof refreshIfDeferred==='function')refreshIfDeferred();toast('Dados fiscais preenchidos e salvos. Confira os materiais.');
 }catch(e){setNfNotice('Não foi possível salvar: '+e.message);toast(e.message,true)}
 finally{button.disabled=false;button.textContent='✓ Confirmar e preencher'}
}
function handleNFKey(pc,raw){const v=digits(raw);if(!v){toast('Informe a chave de 44 dígitos para ler os dados codificados.',true);return}try{
 const data=decodeNFeKey(v);showNFPreview(pc,data,'CHAVE',null,'Chave validada matematicamente. Não foi realizada consulta à SEFAZ. Confira o fornecedor e os itens pela nota física/XML.');
 }catch(e){toast(e.message,true);const input=document.activeElement;if(input&&input.tagName==='INPUT')input.value=(items.find(x=>x.pc===pc)||{}).chaveNF||''}
}
function openManualNF(pc){const it=items.find(x=>x.pc===pc);if(!it)return;showNFPreview(pc,{...it},'MANUAL',null,'Sem NF eletrônica disponível? Preencha os campos necessários, deixando a chave em branco se não existir.');}
function pickXML(pc){nfCurrent=pc;$nf('fileXml').click()}
const tagOne=(base,name)=>base?.getElementsByTagNameNS('*',name)?.[0]||base?.getElementsByTagName(name)?.[0]||null;
const txt=(base,tag)=>tagOne(base,tag)?.textContent?.trim()||'';
function parseNFeXML(data){const doc=new DOMParser().parseFromString(data,'application/xml');if(doc.getElementsByTagName('parsererror').length)throw Error('O XML contém erros de formatação.');const inf=tagOne(doc,'infNFe');if(!inf)throw Error('Não foi encontrado XML de NF-e (tag infNFe).');
 const ide=tagOne(inf,'ide'),emit=tagOne(inf,'emit'),total=tagOne(inf,'ICMSTot');if(!ide||!emit)throw Error('XML não contém os campos fiscais esperados.');
 const det=[...inf.getElementsByTagNameNS('*','det')];
 const products=det.map(d=>{const p=tagOne(d,'prod');return [txt(p,'cProd'),txt(p,'xProd'),txt(p,'qCom'),txt(p,'uCom'),txt(p,'vProd')].filter(Boolean).join(' | ')});
 const id=inf.getAttribute('Id')||'';const k=(id.match(/NFe(\d{44})/)||[])[1]||'';
 if(k&&!nfCheckDigit(k))throw Error('Chave fiscal inválida dentro do XML.');
 const stamp=txt(ide,'dhEmi')||txt(ide,'dEmi');const info={numeroNF:txt(ide,'nNF'),serieNF:txt(ide,'serie'),chaveNF:k,cnpjEmitente:digits(txt(emit,'CNPJ')),fornecedor:txt(emit,'xNome'),emissaoNF:stamp.slice(0,10),valorNF:txt(total,'vNF'),itensNF:products.join('\n')};
 const prot=tagOne(doc,'infProt');const cstat=txt(prot,'cStat');return {info,notice:`${products.length} item(ns) fiscal(is) extraído(s). ${cstat==='100'?'Protocolo XML informa autorização 100. A autenticidade NÃO foi consultada online.':'Autorização não confirmada por este sistema.'} A relação de itens é referência para a conferência física.`};
}
async function xmlSelected(e){const file=e.target.files?.[0],pc=nfCurrent;e.target.value='';if(!file||!pc)return;try{if(file.size>2*1024*1024)throw Error('O XML deve ter até 2 MB');const {info,notice}=parseNFeXML(await file.text());showNFPreview(pc,info,'XML',file,notice)}catch(err){toast('Não foi possível ler XML: '+err.message,true)}}
/** OCR heurístico: só sugere padrões textuais; jamais certifica leitura integral. */
function findOCRFields(text){
 const clean=String(text||'').normalize('NFKC').replace(/\r/g,'').replace(/[ \t]+/g,' ');
 const fields={};const key=keyFromText(clean);
 if(key)Object.assign(fields,decodeNFeKey(key));
 const match=re=>clean.match(re)?.[1]?.trim()||'';
 if(!fields.numeroNF){
  const nf=match(/(?:N[º°o.]?\s*(?:DA\s*)?(?:NOTA|NF[-e]?)|N[úu]mero\s*(?:da\s*)?NF|Nota\s+Fiscal\s*N[ºo]?)[\s:.-]*([0-9.]{3,12})/i);
  if(nf&&digits(nf))fields.numeroNF=String(Number(digits(nf)));
 }
 if(!fields.serieNF){const serie=match(/(?:s[eé]rie|serie)\s*[:\-]?\s*(\d{1,3})/i);if(serie)fields.serieNF=String(Number(serie))}
 if(!fields.cnpjEmitente){const cnpj=match(/CNPJ(?:\/CPF)?[\s:\-]*([\d.\/\-]{14,18})/i);if(digits(cnpj).length===14)fields.cnpjEmitente=digits(cnpj)}
 const date=match(/(?:data\s*(?:de\s*)?emiss[aã]o|emiss[aã]o)[^\d]{0,25}(\d{2}[\/.]\d{2}[\/.]\d{4})/i);
 if(date){const [d,m,y]=date.split(/[\/.]/);if(Number(d)>=1&&Number(d)<=31&&Number(m)>=1&&Number(m)<=12)fields.emissaoNF=`${y}-${m}-${d}`}
 const val=match(/(?:valor\s+total\s+(?:da\s+)?(?:nota|nf)|valor\s+da\s+nota|v\.?\s*total\s+nf[-e]?)[^\d]{0,25}(?:R\$\s*)?([\d.,]+)/i);
 if(val)fields.valorNF=val;
 // Empresa extraída somente com rótulo explícito; cabe ao operador validar razão social.
 const supplier=match(/(?:raz[aã]o\s+social|nome\s+(?:do\s+)?emitente|emitente\s*[:\-])\s*([^\n]{5,110})/i);
 if(supplier)fields.fornecedor=supplier.replace(/^[:\-\s]+/,'').replace(/(?:CNPJ|IE|ENDEREÇO).*$/i,'').trim();
 // Quantidades e materiais em fotografias são dependentes do modelo da DANFE;
 // não copiar linhas da tabela sem garantir alinhamento de descrição e quantidade.
 return fields;
}
function summarizeOCR(fields){
 const entries=[['numeroNF','número'],['serieNF','série'],['chaveNF','chave'],['cnpjEmitente','CNPJ'],['fornecedor','fornecedor'],['emissaoNF','emissão'],['valorNF','valor']];
 const found=entries.filter(([key])=>Boolean(fields[key])).map(([,label])=>label);
 const missing=entries.filter(([key])=>!fields[key]).map(([,label])=>label);
 return `OCR sugeriu ${found.length}/7 campos principais${found.length?' ('+found.join(', ')+')':''}. `+
   `Não identificados: ${missing.length?missing.join(', '):'nenhum'}. `+
   'Itens, quantidades e valores por produto: use o XML para preenchimento confiável. Revise tudo antes de confirmar.';
}

function insertLibrary(url){return new Promise((resolve,reject)=>{const already=url.includes('/jsqr@')?typeof window.jsQR==='function':Boolean(window.Tesseract);if(already){resolve();return}const script=document.createElement('script');script.src=url;script.async=true;script.onload=resolve;script.onerror=()=>reject(Error('Não foi possível carregar o OCR. Verifique conexão ou importe o XML.'));document.head.appendChild(script)})}
async function analyzeNFPhoto(pc,file){if(await nfLerIA(pc,true))return;try{
 toast('Analisando foto da NF. O reconhecimento pode levar alguns segundos…');
 // CDN fornece mecanismo + idiomas, OCR é executado no dispositivo; a foto é enviada apenas à API de anexos do próprio site.
 await insertLibrary('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js');
 const worker=await Tesseract.createWorker('por');let text='';try{const res=await worker.recognize(file);text=res.data.text||''}finally{await worker.terminate()}
 const fields=findOCRFields(text);
 showNFPreview(pc,fields,'OCR',null,summarizeOCR(fields));
 }catch(e){toast('Foto anexada, mas o OCR não conseguiu ler: '+e.message,true);const current=items.find(x=>x.pc===pc)||{};showNFPreview(pc,current,'MANUAL',null,'A foto foi anexada, porém o reconhecimento automático falhou. Confira sua internet ou utilize o XML. Os campos anteriores foram preservados.')}
}
async function ensureQrFallback(){
 if(typeof window.jsQR==='function')return true;
 try{await insertLibrary('https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js');return typeof window.jsQR==='function'}catch{return false}
}
function decodeDetected(pc,raw){
 const key=keyFromText(raw);
 if(!key)return false;
 closeScan();
 showNFPreview(pc,decodeNFeKey(key),'QRCODE',null,'Código reconhecido ✓ Agora fotografe a DANFE para preencher produtos e valores automaticamente.');
 return true;
}
function qrOnCanvas(canvas){
 if(typeof window.jsQR!=='function')return '';
 const ctx=canvas.getContext('2d',{willReadFrequently:true});
 if(!ctx)return '';
 const img=ctx.getImageData(0,0,canvas.width,canvas.height);
 return window.jsQR(img.data,img.width,img.height,{inversionAttempts:'dontInvert'})?.data||'';
}
async function beginScan(pc){
 nfScanPc=pc;$nf('scanModal').classList.add('on');
 setScanStatus('Solicitando acesso à câmera. Aponte para o QR Code ou código de barras da NF.');
 let detector=null,backup=false;
 try{
  if('BarcodeDetector' in window){
   const formats=await BarcodeDetector.getSupportedFormats();
   const desired=['qr_code','code_128','pdf417'].filter(x=>formats.includes(x));
   if(desired.length)detector=new BarcodeDetector({formats:desired});
  }
  if(!detector)backup=await ensureQrFallback();
  if(!detector&&!backup){setScanStatus('Leitura automática indisponível. Use Importar XML ou digite a chave de 44 dígitos.');return}
  if(!navigator.mediaDevices?.getUserMedia)throw Error('Câmera não disponível neste navegador ou sem HTTPS');
  nfScanStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
  const video=$nf('scanVideo');video.srcObject=nfScanStream;await video.play();
  const canvas=document.createElement('canvas');
  const scan=async()=>{
   if(!nfScanStream||nfScanPc!==pc)return;
   try{
    let raw='';
    if(detector){const found=await detector.detect(video);raw=(found||[]).map(x=>x.rawValue).find(x=>keyFromText(x))||''}
    else if(video.videoWidth&&video.videoHeight){
     const width=Math.min(800,video.videoWidth),height=Math.round(width*video.videoHeight/video.videoWidth);
     canvas.width=width;canvas.height=height;canvas.getContext('2d').drawImage(video,0,0,width,height);
     raw=qrOnCanvas(canvas);
    }
    if(raw&&decodeDetected(pc,raw))return;
   }catch(e){setScanStatus('Leitura não concluída: '+e.message)}
   nfScanTimer=setTimeout(scan,650);
  };
  setScanStatus('Câmera ativa. Se não reconhecer, toque em “Ler imagem da galeria” ou importe o XML.');
  scan();
 }catch(e){setScanStatus('Câmera indisponível: '+e.message+'. Use imagem da galeria, XML ou chave manual.')}
}
function setScanStatus(s){$nf('scanStatus').textContent=s}
function closeScan(){if(nfScanTimer)clearTimeout(nfScanTimer);nfScanTimer=null;if(nfScanStream)for(const tr of nfScanStream.getTracks())tr.stop();nfScanStream=null;$nf('scanVideo').srcObject=null;$nf('scanModal').classList.remove('on');nfScanPc=null;if(typeof refreshIfDeferred==='function')refreshIfDeferred()}
function chooseScanImage(){$nf('fileScan').click()}
async function loadImageToCanvas(file){
 const bmp=typeof createImageBitmap==='function'?await createImageBitmap(file):null;
 const canvas=document.createElement('canvas');
 if(bmp){const scale=Math.min(1,1800/Math.max(bmp.width,bmp.height));canvas.width=Math.round(bmp.width*scale);canvas.height=Math.round(bmp.height*scale);canvas.getContext('2d').drawImage(bmp,0,0,canvas.width,canvas.height);bmp.close();return canvas}
 const url=URL.createObjectURL(file);
 try{const img=new Image();await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=()=>reject(Error('Imagem inválida'));img.src=url});const scale=Math.min(1,1800/Math.max(img.naturalWidth,img.naturalHeight));canvas.width=Math.round(img.naturalWidth*scale);canvas.height=Math.round(img.naturalHeight*scale);canvas.getContext('2d').drawImage(img,0,0,canvas.width,canvas.height);return canvas}finally{URL.revokeObjectURL(url)}
}
async function scanImageSelected(e){
 const file=e.target.files?.[0],pc=nfScanPc;e.target.value='';if(!file||!pc)return;
 try{
  setScanStatus('Analisando imagem da galeria…');
  const canvas=await loadImageToCanvas(file);let raw='';
  if('BarcodeDetector' in window){
   const formats=await BarcodeDetector.getSupportedFormats();
   const desired=['qr_code','code_128','pdf417'].filter(x=>formats.includes(x));
   if(desired.length){const found=await new BarcodeDetector({formats:desired}).detect(canvas);raw=(found||[]).map(x=>x.rawValue).find(x=>keyFromText(x))||''}
  }
  if(!raw&&await ensureQrFallback())raw=qrOnCanvas(canvas);
  if(raw&&decodeDetected(pc,raw))return;
  throw Error('A imagem não contém uma chave reconhecível. Experimente aproximar o QR ou digite 44 dígitos.');
 }catch(e){setScanStatus(e.message)}
}
window.addEventListener('DOMContentLoaded',()=>{$nf('fileXml').addEventListener('change',xmlSelected);$nf('fileScan').addEventListener('change',scanImageSelected)});


/* ---------- v3.25.0: DANFE lida por IA + comparação com o pedido ---------- */
let nfIaUltima=null;
function nfRenderIaBotao(source){
 const box=$nf('nfIa');if(!box)return;
 if(source==='IA'&&nfIaUltima){nfRenderComparacao(nfCurrent,nfIaUltima);return}
 nfIaUltima=null;
 box.innerHTML=`<button type="button" class="nf-ia-btn" onclick="nfFotoIA()"><b>📷</b><span><strong>Fotografar a DANFE e preencher tudo</strong><small>A IA lê fornecedor, valores e todos os produtos e confere com a chave.${items.find(x=>x.pc===nfCurrent)?.fotoNF?' <a href="#" onclick="event.preventDefault();event.stopPropagation();nfLerIA(nfCurrent)">Usar a NF já anexada</a>':''}</small></span></button>`;
}
function nfFotoIA(){if(!nfCurrent)return;const i=$nf('fileDanfeIA');i.value='';i.click()}
async function nfFotoIASelecionada(e){
 const file=e.target.files?.[0],pc=nfCurrent;e.target.value='';if(!file||!pc)return;
 const box=$nf('nfIa');box.innerHTML='<div class="nf-ia-load"><span class="spinner"></span> Enviando a foto da DANFE…</div>';
 try{
  let blob=file;if(file.type.startsWith('image/'))blob=await compressImage(file,2200,.86);else if(file.type!=='application/pdf')throw Error('Use foto (JPG/PNG) ou PDF da DANFE');
  if(blob.size>3.5*1024*1024)throw Error('Arquivo maior que 3,5 MB');
  const fd=new FormData();fd.append('photo',blob,file.type==='application/pdf'?'nf.pdf':'nf.jpg');fd.append('pc',pc);fd.append('tipo','nf');fd.append('slot',0);
  await api('upload_photo',{method:'POST',body:fd});
  const fresh=await api('list');items=fresh.items.map(normalized);
  await nfLerIA(pc);
 }catch(err){toast(err.message,true);nfRenderIaBotao('')}
}
async function nfLerIA(pc,silencioso){
 const box=$nf('nfIa');const chaveTela=digits($nf('nfKey')?.value||'');
 if(box)box.innerHTML='<div class="nf-ia-load"><span class="spinner"></span> Lendo a DANFE com IA… (até 30 segundos)</div>';
 if(!silencioso&&!$nf('nfModal').classList.contains('on'))$nf('nfModal').classList.add('on');
 try{
  const r=await api('nf_ler_danfe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,chave:chaveTela})});
  nfIaUltima=r;
  const msg=(r.chave_confirmada?'✓ Chave de acesso confere com a nota fotografada. ':'')+(r.avisos.length?'Atenção: '+r.avisos.join(' '):'Leitura completa — confira os produtos abaixo.');
  showNFPreview(pc,r.nf,'IA',null,msg);
  return true;
 }catch(err){
  if(silencioso)return false;
  toast('Não foi possível ler a DANFE: '+err.message,true);nfRenderIaBotao('');return false;
 }
}
function nfNorm(s){return String(s||'').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^A-Z0-9 ]+/g,' ').split(/\s+/).filter(w=>w.length>2)}
function nfParecido(a,b){const A=new Set(nfNorm(a)),B=nfNorm(b);if(!A.size||!B.length)return 0;let n=0;B.forEach(w=>{if(A.has(w))n++});return n/Math.min(A.size,B.length)}
function nfRenderComparacao(pc,r){
 const box=$nf('nfIa');if(!box)return;
 const it=items.find(x=>x.pc===pc)||{},ped=(it.insumos||[]).map((x,i)=>({...x,_i:i})),usados=new Set();
 const fmt=v=>Number(v||0).toLocaleString('pt-BR',{maximumFractionDigits:3});
 const linhas=r.itens.map(n=>{
  let best=null,score=0;
  ped.forEach(p=>{if(usados.has(p._i))return;let s=(n.codigo&&p.codigo&&n.codigo.toUpperCase()===String(p.codigo).toUpperCase())?1:nfParecido(p.descricao,n.descricao);if(s>score){score=s;best=p}});
  if(best&&score>=.5)usados.add(best._i);else best=null;
  const dif=best&&Math.abs(Number(best.quantidade_pedida||0)-n.quantidade)>0.0001;
  return `<tr class="${!best?'nf-x':dif?'nf-d':'nf-ok'}"><td>${html(n.descricao)}${n.codigo?`<small>${html(n.codigo)}</small>`:''}</td><td class="num">${fmt(n.quantidade)} ${html(n.unidade)}</td><td class="num">${n.valor_total?'R$ '+Number(n.valor_total).toLocaleString('pt-BR',{minimumFractionDigits:2}):'—'}</td>
   <td>${best?`${html(best.descricao)}<small>pedido: ${fmt(best.quantidade_pedida)} ${html(best.unidade||'')}</small>`:'<small>—</small>'}</td><td>${!best?'<b>Não está no pedido</b>':dif?'<b>Quantidade diferente</b>':'✓ Confere'}</td></tr>`;
 });
 const faltam=ped.filter(p=>!usados.has(p._i));
 const nOk=r.itens.length-faltam.length;
 box.innerHTML=`<div class="nf-ia-res"><div class="nf-ia-head"><strong>${r.itens.length} produto(s) na NF</strong><span>${ped.length?`${ped.length} item(ns) no pedido · ${faltam.length?faltam.length+' do pedido não aparecem na NF':'todos os itens do pedido estão na NF'}`:'Pedido sem itens cadastrados para comparar'}</span>
   <button type="button" class="button ghost small" onclick="nfFotoIA()">📷 Ler outra foto</button></div>
  <div class="nf-ia-scroll"><table><thead><tr><th>Produto na NF</th><th>Qtd NF</th><th>Valor</th><th>Item do pedido</th><th>Situação</th></tr></thead><tbody>${linhas.join('')}
  ${faltam.map(p=>`<tr class="nf-x"><td><small>—</small></td><td></td><td></td><td>${html(p.descricao)}<small>pedido: ${fmt(p.quantidade_pedida)} ${html(p.unidade||'')}</small></td><td><b>Não veio na NF</b></td></tr>`).join('')}</tbody></table></div>
  <p class="nf-ia-nota">A NF diz o que o fornecedor faturou. O conferente ainda confere o que chegou de fato na etapa de conferência.</p></div>`;
}
