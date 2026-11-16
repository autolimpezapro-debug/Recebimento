/* Recebimento Drilling v2.9.8 — Importação assistida de ZIP WhatsApp e PDF SAP individual. */
'use strict';
let waImportState=null;
let waImportAccessToken=null;
let waAuthRequest=0;
let waImportInputMode='zip';
function waImportSetAccess(authorized,token=null){
 waImportAccessToken=authorized?token:null;
 document.getElementById('waImportLock').hidden=authorized;
 document.getElementById('waImportProtected').hidden=!authorized;
 if(!authorized){
   waImportState=null;
   document.getElementById('waImportResults').hidden=true;
   document.getElementById('waImportSummary').hidden=true;
   const f=document.getElementById('waImportFile'); f.value='';
   const pdf=document.getElementById('waPdfFile');if(pdf)pdf.value='';
   const label=document.getElementById('waPdfFileLabel');if(label)label.textContent='Nenhum PDF selecionado';
   document.getElementById('waImportFileLabel').textContent='Nenhum arquivo selecionado';
   document.getElementById('waImportPreviewBtn').disabled=true;
 }
}
async function waImportRefreshAccess(){
 const current=++waAuthRequest;
 waImportSetAccess(false);
 try{
   const r=await api('import_auth_status');
   if(current!==waAuthRequest)return;
   waImportSetAccess(!!r.authorized,r.csrf||null);
   if(r.authorized)waIaCarregar();
 }catch(error){if(current===waAuthRequest)document.getElementById('waAuthError').textContent='Não foi possível validar o acesso. Verifique a conexão.';}
}
async function waImportLogin(event){
 event.preventDefault();
 const password=document.getElementById('waImportPassword');
 const submit=document.getElementById('waAuthSubmit');
 const error=document.getElementById('waAuthError');
 error.textContent='';submit.disabled=true;submit.textContent='Verificando…';
 try{
   const result=await api('import_auth_login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:password.value})});
   ++waAuthRequest;
   waImportSetAccess(true,result.csrf);waIaCarregar();
 }catch(ex){error.textContent=ex.message||'Não foi possível autorizar a importação.';}
 finally{password.value='';submit.disabled=false;submit.textContent='Liberar importação';}
}
async function waImportLogout(){
 try{
   await api('import_auth_logout',{method:'POST',headers:{'Content-Type':'application/json','X-Import-Token':waImportAccessToken||''},body:'{}'});
 }catch(ex){}
 ++waAuthRequest;waImportSetAccess(false);
}
function waImportProtectedHeaders(){
 if(!waImportAccessToken)throw Error('Digite a senha para liberar a importação.');
 return {'X-Import-Token':waImportAccessToken};
}
function waImportHandleError(error){
 if(/Autorização de importação expirada|Autorização inválida|IMPORT_LOCKED/i.test(error.message||'')){
   ++waAuthRequest;waImportSetAccess(false);
   document.getElementById('waAuthError').textContent='Sessão expirada. Informe a senha novamente.';
 }
}

const waImportStatus={novo:'Novo PC',completar:'Completar insumos',ja_detalhado:'Já detalhado',protegido:'Recebimento protegido',sem_itens:'Sem itens reconhecidos'};
/* PC que nem o SAP nem a IA concluíram: lançar manualmente sem sair da importação */
function waManualDe(pc){npAbrir();if(pc){const el=document.getElementById('npPc');if(el){el.value=String(pc);el.focus();}}}
function waImportText(text,error=false){const el=document.getElementById('waImportStatus');if(!el)return;el.textContent=text;el.className='wa-import-message'+(error?' error':'');}
function waImportChoose(){waImportInputMode='zip';document.getElementById('waImportFile').click()}
function waPdfChoose(){waImportInputMode='pdf';document.getElementById('waPdfFile').click()}
function waPdfFileChanged(){
 const f=document.getElementById('waPdfFile').files[0];
 if(f){waImportInputMode='pdf';document.getElementById('waImportFile').value='';document.getElementById('waImportFileLabel').textContent='Nenhum ZIP selecionado';}
 document.getElementById('waPdfFileLabel').textContent=f?f.name:'Nenhum PDF selecionado';
 document.getElementById('waImportPreviewBtn').disabled=!f||f.size>30*1024*1024;
 waImportState=null;document.getElementById('waImportResults').hidden=true;
 waImportText(!f?'Selecione um PDF.':f.size>30*1024*1024?'PDF maior que 30 MB.': 'PDF selecionado. Clique em Analisar pedidos.',!!f&&f.size>30*1024*1024);
}
function waImportFileChanged(){
 waImportInputMode='zip';document.getElementById('waPdfFile').value='';document.getElementById('waPdfFileLabel').textContent='Nenhum PDF selecionado';
 const file=document.getElementById('waImportFile').files[0];
 document.getElementById('waImportFileLabel').textContent=file?file.name:'Nenhum arquivo selecionado';
 document.getElementById('waImportPreviewBtn').disabled=!file;
 waImportState=null;document.getElementById('waImportResults').hidden=true;
 if(file&&file.size>1024*1024*1024)waImportText('Arquivo acima de 1 GB. Selecione um ZIP menor.',true);
 else waImportText(file?'Arquivo selecionado. Clique em Analisar pedidos.':'Envie um ZIP contendo a conversa e os PDFs dos pedidos.');
}
async function waImportPreview(){
 const file=document.getElementById(waImportInputMode==='pdf'?'waPdfFile':'waImportFile').files[0],btn=document.getElementById('waImportPreviewBtn');
 if(!file)return;
 btn.disabled=true;btn.textContent='Analisando PDFs…';waImportText('Extraindo pedidos e quantidades. Não feche esta página.');
 try{
  // v3.14.0: envio em partes (até 1 GB); o servidor só lê o arquivo e o apaga depois da prévia.
  const upId=await rdUploadGrande(file,{headers:waImportProtectedHeaders(),progresso:(p,a,t)=>{btn.textContent=`Enviando… ${p}%`;waImportText(`Enviando ${rdTamanho(a)} de ${rdTamanho(t)}. Não feche esta página.`);}});
  btn.textContent='Analisando PDFs…';waImportText('Arquivo recebido. Lendo os pedidos — o arquivo não fica salvo no servidor.');
  const fd=new FormData();fd.append('upload_id',upId);
  const result=await api('whatsapp_import_preview',{method:'POST',headers:waImportProtectedHeaders(),body:fd});
  waImportState={token:result.token,formato:result.formato||waImportInputMode,rows:result.pedidos,selected:new Set(result.pedidos.filter(p=>['novo','completar'].includes(p.estado)).map(p=>p.pc)),totals:result.totais,avisos:result.avisos};
  waAvisoIniciar(result);
  const {pdfs,pedidos,insumos,avisos}=result.totais;
  document.getElementById('waImportMetrics').innerHTML=[['PDFs examinados',pdfs],['PCs identificados',pedidos],['Insumos reconhecidos',insumos],['Não processados',avisos]].map(([a,b])=>`<div class="wa-metric"><strong>${number(b)}</strong><span>${html(a)}</span></div>`).join('');
  document.getElementById('waImportResults').hidden=false;
  document.getElementById('waImportWarnings').innerHTML=avisos?`<details open><summary>${avisos} PDF(s) n\u00e3o import\u00e1veis \u2014 ver detalhes</summary><div class="wa-import-warnings">${result.avisos.map(w=>`<p><strong>${html(w.pdf)}</strong> \u2014 ${html(w.erro)} <button type="button" class="button ghost small" onclick="waManualDe(${Number(w.pc)||0})">+ Lan\u00e7ar PC manualmente</button></p>`).join('')}</div><p style="font-size:12px;color:#b5c7dd">Nenhum desses PDFs foi gravado. Lance o pedido manualmente e a confer\u00eancia segue normal.</p></details>`:'';
  waImportRender();
  waImportText(`Prévia concluída. ${pedidos} PCs identificados. Confirme somente os pedidos selecionados.`);
 }catch(error){waImportHandleError(error);waImportText(error.message||'Falha ao analisar o ZIP.',true);}
 finally{btn.disabled=false;btn.textContent='Analisar pedidos';}
}
function waImportRender(){
 if(!waImportState)return;
 const term=document.getElementById('waImportFilter').value.trim().toLocaleLowerCase('pt-BR');
 const mode=document.getElementById('waImportType').value;
 const subset=waImportState.rows.filter(r=>(mode==='todos'||mode===r.estado)&&(!term||`${r.pc} ${r.fornecedor} ${r.pdf}`.toLocaleLowerCase('pt-BR').includes(term)));
 const list=document.getElementById('waImportRows');
 list.innerHTML=subset.map(r=>{
  const enabled=['novo','completar'].includes(r.estado);
  const selected=waImportState.selected.has(r.pc);
  return `<article class="wa-import-row ${enabled?'':'wa-import-row-disabled'}"><label class="wa-import-row-head">
    <input type="checkbox" ${enabled?'':'disabled'} ${selected?'checked':''} onchange="waImportToggle(${Number(r.pc)},this.checked)" aria-label="Selecionar PC ${Number(r.pc)}">
    <span class="wa-import-pc">PC ${Number(r.pc)}</span><span class="wa-import-tag ${enabled?'ok':'muted'}">${html(waImportStatus[r.estado]||r.estado)}</span>
    </label><div class="wa-import-row-content"><strong>${html(r.fornecedor||'Fornecedor não identificado')}</strong>${r.urgente_chat?`<em class="wa-urg" title="${html((r.urgente_chat.autor||'')+': '+(r.urgente_chat.motivo||''))}">⚠ URGENTE no chat</em>`:''}<small>${html(r.pdf)} · ${Number(r.itens)} insumo(s)</small><span>${html(r.detalhe)}</span>
    ${r.itens?`<details class="wa-import-details"><summary>Ver ${Number(r.itens)} insumo(s) e quantidades</summary><div>${r.insumos.map(x=>`<p><b>${html(x.quantidade_pedida)} ${html(x.unidade)}</b> · ${html(x.descricao)}</p>`).join('')}</div></details>`:''}${enabled?waAvisoHtml(r):''}</div></article>`;
 }).join('')||'<p class="wa-import-empty">Nenhum PC encontrado para esses filtros.</p>';
 const available=waImportState.rows.filter(r=>['novo','completar'].includes(r.estado));
 const n=waImportState.selected.size;
 const avs=[...waImportState.selected].map(pc=>waImportState.aviso&&waImportState.aviso[pc]).filter(Boolean);
 const semDest=avs.filter(a=>!a.nenhum&&!(a.emails.length&&a.tocado)).length;
 document.getElementById('waImportSelected').textContent=`${n} selecionado(s) de ${available.length} elegíveis`+(semDest?` · ✉ ${semDest} sem destinatário confirmado`:'');
 const sugN=avs.filter(a=>a.emails.length&&!a.tocado).length;const bulk=document.getElementById('waAvisoBulk');
 if(bulk){bulk.hidden=!sugN;bulk.textContent=`✓ Aceitar ${sugN} sugestão(ões) de e-mail`;}
 const urgN=waImportState.rows.filter(r=>r.urgente_chat).length;
 const button=document.getElementById('waImportConfirm');button.disabled=n===0&&!urgN;button.textContent=n?`✓ Importar ${n} PC(s) selecionado(s)${urgN?` · marcar ${urgN} urgente(s)`:''}`:`⚠ Marcar ${urgN} PC(s) como URGENTE (chat)`;
 const selAll=document.getElementById('waImportSelectAll');selAll.checked=available.length>0&&n===available.length;selAll.indeterminate=n>0&&n<available.length;
}
/* v3.29.0 — quem recebe o e-mail "seu pedido chegou": escolhido já na prévia. Sugestão (amarelo) só vale depois de confirmada. */
function waAvisoIniciar(result){
  const m={};
  for(const r of result.pedidos||[]){
    const v=r.aviso||{},st=v.status||'nenhum',dest=(v.destinatarios||[]).map(d=>d.email);
    m[r.pc]={status:st,emails:['ok','definido','sugerido'].includes(st)?dest:[],nenhum:st==='sem_aviso',
      tocado:['ok','definido','sem_aviso'].includes(st),origem:st==='sugerido'?'observacao':(st==='ok'?'solicitante':'manual'),
      cands:v.candidatos||[],motivo:v.motivo||''};
  }
  waImportState.aviso=m;waImportState.contatos=result.contatos||[];
  if(typeof avisoDatalist==='function')avisoDatalist(waImportState.contatos);
}
function waAvisoNome(email){const c=(waImportState.contatos||[]).find(x=>x.email===email);return c?c.nome:email}
function waAvisoHtml(r){
  const a=waImportState.aviso&&waImportState.aviso[r.pc];if(!a)return '';
  const pc=Number(r.pc),tem=a.emails.length>0;
  const cls=a.nenhum?'cinza':tem&&a.tocado?'ok':tem?'sug':'sem';
  const tit=a.nenhum?'⚪ Ninguém será avisado':tem&&a.tocado?'🟢 Avisar quando chegar':tem?'🟡 Sugestão — confirme':a.cands.length?'🟠 Escolha quem avisar':'🔴 Sem destinatário — escolha';
  const chips=a.emails.map((e,k)=>`<span class="aviso-chip">${html(waAvisoNome(e))} <small>${html(e)}</small><button type="button" onclick="waAvisoRem(${pc},${k})" title="Remover" aria-label="Remover">✕</button></span>`).join('');
  const cands=a.cands.filter(c=>!a.emails.includes(c.email)).map((c,j)=>`<button type="button" class="button ghost small" onclick="waAvisoCand(${pc},${j})">+ ${html(c.nome)}</button>`).join(' ');
  return `<div class="wa-aviso ${cls}"><div><b>${tit}</b>${a.motivo&&!tem?`<small class="muted"> — ${html(a.motivo)}</small>`:''}</div>
    ${chips?`<div class="aviso-chips">${chips}</div>`:''}${cands?`<div class="aviso-cands"><small>Possíveis:</small> ${cands}</div>`:''}
    <div class="aviso-add"><input list="avisoDL" placeholder="Digite o nome…" aria-label="Adicionar pessoa ao PC ${pc}" onchange="waAvisoInput(${pc},this)">
    ${tem&&!a.tocado?`<button type="button" class="button primary small" onclick="waAvisoConfirmar(${pc})">✓ Confirmar</button>`:''}
    <button type="button" class="button ghost small" onclick="waAvisoNenhum(${pc})">Não avisar ninguém</button></div></div>`;
}
function waAvisoAdd(pc,email){const a=waImportState.aviso[pc];if(!a)return;if(!a.emails.includes(email))a.emails.push(email);a.nenhum=false;a.tocado=true;a.origem='manual';waImportRender()}
function waAvisoRem(pc,k){const a=waImportState.aviso[pc];if(!a)return;a.emails.splice(k,1);a.tocado=true;a.origem='manual';waImportRender()}
function waAvisoCand(pc,j){const a=waImportState.aviso[pc];const c=a&&a.cands.filter(x=>!a.emails.includes(x.email))[j];if(c)waAvisoAdd(pc,c.email)}
function waAvisoInput(pc,el){
  const v=String(el.value||'').trim().toLowerCase();if(!v)return;const L=waImportState.contatos||[];
  let c=L.find(x=>`${x.nome} · ${x.email}`.toLowerCase()===v)||L.find(x=>x.email.toLowerCase()===v);
  if(!c){const p=L.filter(x=>x.nome.toLowerCase().includes(v));if(p.length===1)c=p[0];}
  if(!c){el.value='';return toast('Pessoa não encontrada (ou nome ambíguo). Escolha da lista ou cadastre em Administração → Avisos por e-mail.',true);}
  waAvisoAdd(pc,c.email);
}
function waAvisoConfirmar(pc){const a=waImportState.aviso[pc];if(a){a.tocado=true;waImportRender()}}
function waAvisoNenhum(pc){const a=waImportState.aviso[pc];if(a){a.emails=[];a.nenhum=true;a.tocado=true;waImportRender()}}
function waAvisoAceitarTodas(){for(const pc of waImportState.selected){const a=waImportState.aviso[pc];if(a&&a.emails.length&&!a.tocado)a.tocado=true}waImportRender()}
/** Só vai para o servidor o que alguém confirmou (ou o que o solicitante identificou com certeza). */
function waAvisoPayload(){
  const out={};if(!waImportState||!waImportState.aviso)return out;
  for(const pc of waImportState.selected){
    const a=waImportState.aviso[pc];if(!a||!a.tocado)continue;
    if(a.nenhum)out[pc]={nenhum:true};else if(a.emails.length)out[pc]={emails:a.emails,origem:a.origem};
  }
  return out;
}
function waImportToggle(pc,on){if(!waImportState)return;if(on)waImportState.selected.add(pc);else waImportState.selected.delete(pc);waImportRender()}
function waImportToggleAll(on){if(!waImportState)return;for(const r of waImportState.rows)if(['novo','completar'].includes(r.estado)){if(on)waImportState.selected.add(r.pc);else waImportState.selected.delete(r.pc)}waImportRender()}
async function waImportConfirm(){
 if(!waImportState||(!waImportState.selected.size&&!waImportState.rows.some(r=>r.urgente_chat)))return;
 const button=document.getElementById('waImportConfirm');button.disabled=true;button.textContent='Gravando pedidos…';
 waImportText('Salvando os pedidos selecionados. Recebimentos existentes serão preservados.');
 try{
  const result=await api('whatsapp_import_confirm',{method:'POST',headers:{'Content-Type':'application/json',...waImportProtectedHeaders()},body:JSON.stringify({token:waImportState.token,pcs:[...waImportState.selected],avisar:waAvisoPayload()})});
  const s=result.totais;
  document.getElementById('waImportSummary').hidden=false;
  document.getElementById('waImportSummary').innerHTML=`<h3>Importação concluída</h3><p><strong>${number(s.novos)}</strong> novos PCs · <strong>${number(s.completados)}</strong> PCs completados com insumos · <strong>${number(s.ignorados)}</strong> preservados/ignorados${s.urgentes?` · <strong style="color:#ff9b8f">${number(s.urgentes)}</strong> marcados como URGENTE pelo chat`:''}</p><p>Fotos, notas fiscais, entregas e confirmações anteriores foram preservadas. Esta importação não envia notificações de recebimento.</p><button class="button primary" onclick="goPage('pedidos')">Ver pedidos importados →</button>`;
  waImportState=null;document.getElementById('waImportResults').hidden=true;document.getElementById('waImportFile').value='';document.getElementById('waImportFileLabel').textContent='Nenhum arquivo selecionado';document.getElementById('waImportPreviewBtn').disabled=true;
  waImportText('Importação realizada com sucesso.');
  await load();
 }catch(error){waImportHandleError(error);waImportText('Não foi possível importar: '+(error.message||'Erro'),true);if(waImportState){button.disabled=false;waImportRender();}}
}
/* ---------------- Leitura assistida por IA (Gemini) ---------------- */
let iaConfig=null;
function iaMsg(t,erro=false){const el=document.getElementById('iaMsg');if(el){el.textContent=t;el.className='wa-import-message'+(erro?' error':'');}}
function iaRender(c){
 iaConfig=c;
 const sel=document.getElementById('iaModel');
 if(sel&&Array.isArray(c.modelos)){sel.innerHTML=c.modelos.map(m=>`<option value="${m}" ${m===c.model?'selected':''}>${m}</option>`).join('');}
 const en=document.getElementById('iaEnabled');if(en)en.value=c.enabled?'1':'0';
 const url=document.getElementById('iaUrl');if(url&&c.base_url)url.value=c.base_url;
 const k=document.getElementById('iaKey');if(k)k.value='';
 const hint=document.getElementById('iaKeyHint');if(hint)hint.textContent=c.chave_configurada?('Chave atual configurada ('+c.chave_final+'). Deixe vazio para manter.'):'Nenhuma chave configurada ainda.';
 const m=document.getElementById('iaStatus');
 if(m)m.innerHTML=[['Situação',c.enabled?'ATIVADA':'desligada'],['Modelo',c.model||'—'],['Chave',c.chave_configurada?'configurada':'falta'],['cURL',c.extensao_curl===false?'ausente':'ok']].map(([a,b])=>`<div class="wa-metric"><strong>${b}</strong><span>${a}</span></div>`).join('');
}
async function waIaCarregar(){
 try{const r=await api('gemini_status',{headers:waImportProtectedHeaders()});iaRender(r.config);}
 catch(e){const m=document.getElementById('iaStatus');if(m)m.innerHTML='<div class="wa-metric"><strong>—</strong><span>'+e.message+'</span></div>';}
}
async function waIaSalvar(){
 const btn=document.getElementById('iaSaveBtn');if(btn)btn.disabled=true;
 const body={enabled:document.getElementById('iaEnabled').value==='1',model:document.getElementById('iaModel').value,base_url:document.getElementById('iaUrl').value};
 const k=document.getElementById('iaKey').value.trim();if(k)body.api_key=k;
 try{const r=await api('gemini_config',{method:'POST',headers:{'Content-Type':'application/json',...waImportProtectedHeaders()},body:JSON.stringify(body)});
  iaRender(r.config);document.getElementById('iaKey').value='';iaMsg('Configuração de IA salva.');}
 catch(e){iaHandleError(e);iaMsg(e.message,true);}
 finally{if(btn)btn.disabled=false;}
}
async function waIaTestar(){
 const btn=document.getElementById('iaTestBtn');if(btn){btn.disabled=true;btn.textContent='Testando…';}
 try{const r=await api('gemini_teste',{method:'POST',headers:{'Content-Type':'application/json',...waImportProtectedHeaders()},body:'{}'});
  iaMsg('Conexão OK com '+r.modelo+' em '+r.ms+' ms. Resposta: '+JSON.stringify(r.resposta).slice(0,90));}
 catch(e){iaHandleError(e);iaMsg('Falha no teste: '+e.message,true);}
 finally{if(btn){btn.disabled=false;btn.textContent='Testar conexão';}}
}
function waImportDropped(evt){evt.preventDefault();document.getElementById('waImportDrop').classList.remove('drag');const f=evt.dataTransfer?.files?.[0];if(!f)return;const field=document.getElementById('waImportFile');try{const d=new DataTransfer();d.items.add(f);field.files=d.files;waImportFileChanged()}catch{waImportText('Use Selecionar arquivo ZIP para continuar.',true)}}
function waImportDragged(evt){evt.preventDefault();document.getElementById('waImportDrop').classList.add('drag')}
function waImportDragLeave(){document.getElementById('waImportDrop').classList.remove('drag')}
