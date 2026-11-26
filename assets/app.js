/* Recebimento Drilling v3.0.0 — conferência por insumo + módulo de estoque */
'use strict';
const API='api.php';
const APP_VERSION='3.61.3';
const notificationClientId='receb_'+Math.random().toString(36).slice(2)+Date.now().toString(36);
const STATUS=['SIM','PARCIAL','NÃO','EM TRÂNSITO','N/A'];
const STATUS_NAMES={'SIM':'Recebido','PARCIAL':'Parcial','NÃO':'Não chegou','EM TRÂNSITO':'Em trânsito','N/A':'N/A','':'Sem status'};
const STATUS_COLORS={'SIM':'#22d3a7','PARCIAL':'#f8bf60','NÃO':'#f17e89','EM TRÂNSITO':'#62c5eb','N/A':'#a797fc','':'#526782'};
const MANUAL_HEADERS=['DATA','PC','Nº NF','FORNECEDOR','SOLICITANTE','OBSERVAÇÕES'];
const COMPLETE_HEADERS=[...MANUAL_HEADERS,'SITUAÇÃO AUTOMÁTICA','STATUS CHEGADA','MATERIAL','CHAVE NF (44 DÍGITOS)','NF ANEXADA','FOTOS PRODUTO','ÚLTIMA ATUALIZAÇÃO','ETAPA DO RECEBIMENTO','INÍCIO DO RECEBIMENTO','FINALIZAÇÃO DO RECEBIMENTO','SÉRIE NF','CNPJ EMITENTE','EMISSÃO NF','VALOR NF','ITENS DA NF','ORIGEM DOS DADOS','XML ARQUIVADO','ÚLTIMA AÇÃO','DATA/HORA DA ÚLTIMA AÇÃO','ORIGEM DA ENTREGA','INSUMOS PEDIDOS','INSUMOS RECEBIDOS','INSUMOS NÃO ENTREGUES','HISTÓRICO DE ENTREGAS','RETIFICAÇÕES'];
let items=[],selected=new Set(),currentPage=1,activePage='dashboard',drawerPC=null,uploadCtx=null,currentWAScope='resumo';
let lastFiltered=[];
const pendingSaves=new Map(),receiptBusy=new Set();
const $=id=>document.getElementById(id);
const html=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const number=n=>Number(n||0).toLocaleString('pt-BR');
const pct=(a,b)=>b?Math.round(a*100/b):0;
const arrived=i=>['SIM','PARCIAL'].includes(i.chegou);
const hasNF=i=>Boolean(i.numeroNF||i.chaveNF||i.fotoNF);
// NOVO = PC criada após a implantação desta versão e ainda sem primeira conferência finalizada.
// Não desaparece só porque alguém abriu o pedido; funciona igual em todos os dispositivos.
const isNewOrder=i=>Boolean(i.novo_pedido_em)&&!i.primeira_confirmacao_em&&!i.entregas.length&&!i.recebimento_finalizado_em;
const newOrders=()=>items.filter(isNewOrder);
function showNewOrders(){goPage('pedidos');$('statusFilter').value='novo';$('orderSort').value='novos';filtersChanged();}
const receiptPhase=i=>i.recebimento_estado==='finalizado'?'Finalizado':i.recebimento_estado==='em_andamento'?'Em andamento':'Não iniciado';
const receiptClass=i=>i.recebimento_estado==='finalizado'?'done':i.recebimento_estado==='em_andamento'?'active':'waiting';
const localISO=()=>{const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-')};
function parseDate(v){const d=/^\d{4}-\d{2}-\d{2}$/.test(v||'')?new Date(v+'T12:00:00'):new Date(v||'');return isNaN(d.getTime())?null:d}
function fmtDate(v){const d=parseDate(v);return d?d.toLocaleDateString('pt-BR'):'—'}
function fmtDatetime(v){const d=parseDate(v);return d?d.toLocaleString('pt-BR',{dateStyle:'short',timeStyle:'short'}):'—'}
function short(s,n=70){s=String(s||'').replace(/\s+/g,' ').trim();return s.length>n?s.slice(0,n-1)+'…':s}
function normalized(it){return {...it,pc:Number(it.pc),material:String(it.material||''),fornecedor:String(it.fornecedor||''),situacao:String(it.situacao||'PENDENTE'),chegou:String(it.chegou||''),data:String(it.data||''),qtd:String(it.qtd||''),obs:String(it.obs||''),chaveNF:String(it.chaveNF||''),numeroNF:String(it.numeroNF||''),solicitante:String(it.solicitante||''),serieNF:String(it.serieNF||''),cnpjEmitente:String(it.cnpjEmitente||''),emissaoNF:String(it.emissaoNF||''),valorNF:String(it.valorNF||''),itensNF:String(it.itensNF||''),origemNF:String(it.origemNF||''),xmlNF:String(it.xmlNF||''),fotoNF:String(it.fotoNF||''),nfResumo:String(it.nfResumo||''),origem_entrega:String(it.origem_entrega||''),insumos:Array.isArray(it.insumos)?it.insumos:[],entregas:Array.isArray(it.entregas)?it.entregas:[],historico_retificacoes:Array.isArray(it.historico_retificacoes)?it.historico_retificacoes:[],primeira_confirmacao_em:it.primeira_confirmacao_em||null,fotosProdutos:Array.isArray(it.fotosProdutos)?it.fotosProdutos.filter(Boolean).slice(0,3):[],recebimento_estado:String(it.recebimento_estado||''),recebimento_iniciado_em:it.recebimento_iniciado_em||null,recebimento_finalizado_em:it.recebimento_finalizado_em||null,novo_pedido_em:it.novo_pedido_em||null,urgente:it.urgente==='1'?'1':'',devolucoes:Array.isArray(it.devolucoes)?it.devolucoes:[]}}
async function api(action,opts={}){const r=await fetch(`${API}?action=${encodeURIComponent(action)}`,{cache:'no-store',...opts,headers:{'X-Client-Id':notificationClientId,...(opts.headers||{})}});let v;try{v=await r.json()}catch{throw Error('Resposta inválida da API')};if(!r.ok||!v.ok)throw Error(v.error||`Erro ${r.status}`);return v}
function toast(message,error=false){const t=$('toast');t.textContent=message;t.className='toast on'+(error?' err':'');clearTimeout(t._timer);t._timer=setTimeout(()=>t.className='toast',3600)}
async function load(){try{const response=await api('list');items=response.items.map(normalized);if($('loadError'))$('loadError').hidden=true; if(response.version&&response.version!==APP_VERSION)toast('Nova atualização disponível. Recarregue a página.',true);$('connectionLabel').innerHTML='<i></i> Dados atualizados';$('connectionLabel').className='connection good';$('menuOrders').textContent=number(items.length);$('menuNewOrders').textContent=number(newOrders().length)+' novos';$('menuNewOrders').hidden=newOrders().length===0;populateSupplier();populateObra();renderAll();const deepLinkPC=new URLSearchParams(location.search).get('pc');if(deepLinkPC && /^\d{1,12}$/.test(deepLinkPC) && items.some(i=>i.pc===Number(deepLinkPC))){history.replaceState(null,'',location.pathname);goPage('pedidos');openDrawer(Number(deepLinkPC))}}catch(e){if($('loadError'))$('loadError').hidden=false;$('connectionLabel').innerHTML='<i></i> Falha de conexão';$('connectionLabel').className='connection bad';$('ordersBody').innerHTML=`<tr><td colspan="10" class="empty">Não foi possível carregar os pedidos. Verifique sua conexão e tente novamente.</td></tr>`;toast('Não foi possível carregar os pedidos. Tente novamente.',true)}}
async function refreshData(){await load();if($('connectionLabel').classList.contains('good'))toast('Dados atualizados')}
function renderAll(){renderDashboard();renderOrders();renderExportPreview();if(drawerPC!==null&&items.some(i=>i.pc===drawerPC))renderDrawer()}
function goPage(page){activePage=page;document.querySelectorAll('.page').forEach(el=>el.classList.toggle('active',el.id===`page-${page}`));document.querySelectorAll('.nav-link').forEach(el=>el.classList.toggle('active',el.dataset.page===page));$('breadcrumb').innerHTML=`Operações <span>/</span> ${{dashboard:'Dashboard',inicio:'Início',pedidos:'Pedidos',estoque:'Estoque',importados:'Importados',ferramentas:'Ferramentas',locacoes:'Locações',protocolos:'Protocolo de NFs',terceiros:'Terceiros (romaneio)',espelho:'Espelho de NF',relger:'Relatório gerencial',chegadas:'Chegadas / NF pendente',contagens:'Inventário e Acuracidade',obras:'Obras',coletas:'Coletas',notificacoes:'Notificações',importacao:'Importar pedidos',exportacao:'Exportação',administracao:'Administração'}[page]}`;if(page==='exportacao')renderExportPreview();if(page==='importacao'&&typeof waImportRefreshAccess==='function')waImportRefreshAccess();if(page==='administracao'){if(typeof backupRefresh==='function')backupRefresh();if(typeof backupInit==='function')backupInit();}if(page==='estoque'&&typeof estoqueInit==='function')estoqueInit();if(page==='coletas'&&typeof coletasInit==='function')coletasInit();if(page==='importados'&&typeof importadosInit==='function')importadosInit();if(page==='ferramentas'&&typeof ferramentasInit==='function')ferramentasInit();if(page==='locacoes'&&typeof locacoesInit==='function')locacoesInit();if(page==='protocolos'&&typeof protocolosInit==='function')protocolosInit();if(page==='terceiros'&&typeof terceirosInit==='function')terceirosInit();if(page==='espelho'&&typeof espelhoInit==='function')espelhoInit();if(page==='relger'&&typeof relgerInit==='function')relgerInit();if(page==='chegadas'&&typeof chegadasInit==='function')chegadasInit();if(page==='contagens'&&typeof contagensInit==='function')contagensInit();if(page==='obras'&&typeof obrasInit==='function')obrasInit();if(page==='notificacoes'&&typeof ntPageInit==='function')ntPageInit();window.scrollTo({top:0,behavior:'instant'})}
function kpi(icon,value,title,hint,tone=''){return `<div class="kpi ${tone}"><div class="kpi-top"><span>${html(title)}</span><div class="kpi-icon">${icon}</div></div><strong>${html(value)}</strong><small>${html(hint)}</small></div>`}
function dashboardPeriodItems(){const days=$('dashPeriod').value;if(days==='all')return items.filter(i=>arrived(i));const since=new Date();since.setHours(0,0,0,0);since.setDate(since.getDate()-Number(days)+1);return items.filter(i=>arrived(i)&&parseDate(i.data)&&parseDate(i.data)>=since)}
function gotoStatus(f){goPage('pedidos');$('statusFilter').value=f;filtersChanged()}
function renderDashboard(){const pendingNew=newOrders().length;$('menuNewOrders').textContent=number(pendingNew)+' novos';$('menuNewOrders').hidden=pendingNew===0;const total=items.length,sim=items.filter(i=>i.chegou==='SIM').length,par=items.filter(i=>i.chegou==='PARCIAL').length,nao=items.filter(i=>i.chegou==='NÃO').length,trans=items.filter(i=>i.chegou==='EM TRÂNSITO').length,unknown=items.filter(i=>!i.chegou).length,recebidos=items.filter(arrived),rcv=recebidos.length,withNF=recebidos.filter(i=>i.numeroNF).length,withPhotos=recebidos.filter(i=>i.fotosProdutos.length).length,withAttachment=recebidos.filter(i=>i.fotoNF).length,period=dashboardPeriodItems(),missingNum=recebidos.filter(i=>!i.numeroNF).length,missingPhotos=recebidos.filter(i=>!i.fotosProdutos.length).length,distinct=new Set(items.map(i=>i.fornecedor.trim().toLocaleUpperCase('pt-BR')).filter(Boolean)).size;
const newCount=newOrders().length;const coverage=pct(rcv,total);$('heroPercent').textContent=coverage+'%';$('ringPct').textContent=coverage+'%';$('heroText').textContent=`${number(rcv)} de ${number(total)} PCs com chegada total ou parcial`;$('heroRing').style.background=`conic-gradient(var(--teal) 0 ${coverage}%,#354c6a ${coverage}% 100%)`;$('heroProgress').style.width=coverage+'%';$('heroHint').textContent=`${number(sim)} recebidos integralmente · ${number(par)} parciais · ${number(unknown)} ainda sem classificação`;
$('queueGrid').innerHTML=`<button class="queue-card new-queue" onclick="showNewOrders()"><strong>${newCount}</strong><span>Novos pedidos sem conferência</span></button><button class="queue-card danger" onclick="gotoStatus('semNF')"><strong>${missingNum}</strong><span>Recebidos sem nº NF</span></button><button class="queue-card warn" onclick="gotoStatus('PARCIAL')"><strong>${par}</strong><span>Recebimentos parciais</span></button><button class="queue-card info" onclick="gotoStatus('EM TRÂNSITO')"><strong>${trans}</strong><span>Em trânsito</span></button><button class="queue-card" onclick="gotoStatus('pendente')"><strong>${unknown}</strong><span>Sem status informado</span></button>`;
$('kpiGrid').innerHTML=kpi('✦',number(newCount),'Novos pedidos','Aguardando primeira conferência','warn')+kpi('◉',number(items.filter(i=>i.recebimento_estado==='em_andamento').length),'Em andamento','Conferência iniciada','warn')+kpi('✓',number(items.filter(i=>i.recebimento_estado==='finalizado').length),'Finalizados','Horário final registrado','ok')+kpi('▤',number(total),'PCs cadastrados','Base completa')+kpi('✓',number(sim),'Recebidos','Chegada total','ok')+kpi('◐',number(par),'Parciais','Conferência incompleta','warn')+kpi('↗',number(period.length),'Chegadas no período','Com data de chegada','ok')+kpi('⌁',number(distinct),'Fornecedores','Distintos na base')+kpi('▣',pct(withNF,rcv)+'%','Com número de NF',`${withNF}/${rcv} com chegada`,'ok')+kpi('🚚',number(items.filter(i=>i.origem_entrega==='COLETA_DRILLING').length),'Coleta interna','Origem registrada')+kpi('🏭',number(items.filter(i=>i.origem_entrega==='ENTREGA_FORNECEDOR').length),'Entrega fornecedor','Origem registrada')+kpi('⚠',number(items.filter(i=>missingItems(i).length).length),'PCs com saldo','Insumos ainda não entregues','warn');
const statusGroups=[['SIM',sim],['PARCIAL',par],['NÃO',nao],['EM TRÂNSITO',trans],['N/A',items.filter(i=>i.chegou==='N/A').length],['',unknown]];let cumulative=0;const slices=statusGroups.filter(([_,v])=>v).map(([s,v])=>{const from=cumulative;cumulative+=total?100*v/total:0;return `${STATUS_COLORS[s]} ${from.toFixed(3)}% ${cumulative.toFixed(3)}%`});$('statusChart').innerHTML=`<div class="donut" style="background:conic-gradient(${slices.length?slices.join(','):'#33425a 0% 100%'})"><div class="donut-center"><strong>${number(total)}</strong><small>pedidos</small></div></div><div class="legend">${statusGroups.map(([s,v])=>`<div class="legend-row"><span class="label"><i class="swatch" style="background:${STATUS_COLORS[s]}"></i>${STATUS_NAMES[s]}</span><strong>${number(v)}</strong></div>`).join('')}</div>`;
const daily=[];for(let offset=13;offset>=0;offset--){const d=new Date();d.setHours(12,0,0,0);d.setDate(d.getDate()-offset);const key=[d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');daily.push({day:d.getDate(),key,value:items.filter(i=>arrived(i)&&i.data===key).length})}const max=Math.max(1,...daily.map(x=>x.value));$('trendChart').innerHTML=`<div class="trend-bars">${daily.map(x=>`<div class="trend-col" title="${x.key}: ${x.value} chegada(s)"><span class="count">${x.value||''}</span><div class="bar-vert" style="height:${Math.max(2,Math.round(x.value/max*145))}px"></div><small>${x.day}</small></div>`).join('')}</div>`;
const suppliers=new Map();items.forEach(i=>{const name=i.fornecedor.trim()||'Não informado';suppliers.set(name,(suppliers.get(name)||0)+1)});const top=[...suppliers].sort((a,b)=>b[1]-a[1]).slice(0,7);const peak=top.length?top[0][1]:1;$('suppliersChart').innerHTML=top.map(([name,value])=>`<div class="sup-row" title="${html(name)}"><span>${html(name)}</span><div class="sup-line"><i style="width:${pct(value,peak)}%"></i></div><strong>${value}</strong></div>`).join('')||'<p class="empty">Nenhum fornecedor</p>';
const quality=[['Número da NF',withNF,''],['NF anexada',withAttachment,'blue'],['Fotos do produto',withPhotos,'amber']];$('qualityChart').innerHTML=quality.map(([label,count,cls])=>`<div><div class="quality-head"><span>${label}</span><strong>${pct(count,rcv)}% <small>(${count}/${rcv})</small></strong></div><div class="quality-line ${cls}"><i style="width:${pct(count,rcv)}%"></i></div></div>`).join('')+`<div class="quality-foot">${missingNum} recebidos sem número da NF · ${missingPhotos} sem fotos. Indicadores calculados apenas entre PCs com chegada total ou parcial.</div>`;
const recent=items.filter(i=>i.updated_at).sort((a,b)=>(Date.parse(b.updated_at)||0)-(Date.parse(a.updated_at)||0)).slice(0,6);$('recentRows').innerHTML=recent.length?recent.map(i=>`<div class="recent-row"><button onclick="openDrawer(${i.pc})">PC ${i.pc}</button>${isNewOrder(i)?'<span class="new-tag" title="Novo pedido ainda não conferido">NOVO</span>':''}<span class="muted">${html(i.fornecedor)}</span><span class="tag ${statusClass(i.chegou)}">${STATUS_NAMES[i.chegou]||html(i.chegou)}</span><span class="muted recent-date">${fmtDatetime(i.updated_at)}</span></div>`).join(''):'<p style="color:var(--muted);font-size:12px">Nenhuma atualização registrada até o momento.</p>'}
function statusClass(s){return ({'SIM':'sim','PARCIAL':'parcial','NÃO':'nao','EM TRÂNSITO':'transito'})[s]||'blank'}
function populateSupplier(){const sel=$('supplierFilter'),old=sel.value;const suppliers=[...new Set(items.map(i=>i.fornecedor.trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'pt-BR'));sel.innerHTML='<option value="">Todos os fornecedores</option>'+suppliers.map(n=>`<option value="${html(n)}">${html(n)}</option>`).join('');if(suppliers.includes(old))sel.value=old}
const RD_SETOR_NOMES={OBRA:'Obra',DEPOSITO:'Depósito',CALDEIRARIA:'Caldeiraria',MANUTENCAO:'Manutenção',EXPEDICAO:'Expedição',LOGISTICA:'Logística',ADM:'ADM'};
function destinoChave(i){const s=i.destino_setor||'';return s==='OBRA'?'OBRA:'+(i.obra||''):s;}
function destinoRotulo(i){const s=i.destino_setor||'';if(!s)return '';return s==='OBRA'?(i.obra||'Obra (não identificada)'):(RD_SETOR_NOMES[s]||s);}
function populateObra(){const sel=$('obraFilter');if(!sel)return;const old=sel.value,cont={};items.forEach(i=>{const k=destinoChave(i)||'__sem';cont[k]=(cont[k]||0)+1;});
 const obras=Object.keys(cont).filter(k=>k.startsWith('OBRA:')).sort((a,b)=>a.localeCompare(b,'pt-BR')),setores=Object.keys(RD_SETOR_NOMES).filter(k=>k!=='OBRA'&&cont[k]);
 const totObra=obras.reduce((t,k)=>t+cont[k],0);
 sel.innerHTML='<option value="">Todas as obras / setores</option>'+(totObra?`<option value="OBRA:*">Todas as obras (${totObra})</option>`:'')+(obras.length?'<optgroup label="Obras">'+obras.map(k=>`<option value="${html(k)}">${html(k.slice(5)||'Obra (não identificada)')} (${cont[k]})</option>`).join('')+'</optgroup>':'')+(setores.length?'<optgroup label="Setores">'+setores.map(k=>`<option value="${k}">${RD_SETOR_NOMES[k]} (${cont[k]})</option>`).join('')+'</optgroup>':'')+(cont.__sem?`<option value="__sem">Sem destino identificado (${cont.__sem})</option>`:'');
 sel.value=[...sel.options].some(o=>o.value===old)?old:'';}
/* v3.51.3 — filtro por insumo / SKU: o PC entra se ALGUMA linha dele tiver todas as palavras digitadas (descrição ou SKU, sem acento). */
const insumoHits=new Map();
const nSemAcento=t=>String(t==null?'':t).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
function insumoTermos(){const el=$('insumoQ');return el?nSemAcento(el.value).split(/\s+/).filter(Boolean):[]}
function insumoLinhas(i,termos){const out=[];const casa=(txt)=>termos.every(t=>txt.includes(t));
 for(const l of (i.insumos||[])){const sku=String(l.sku||'');if(casa(nSemAcento(l.descricao)+' '+nSemAcento(sku)+' '+nSemAcento(sku).replace(/[^a-z0-9]/g,'')))out.push({desc:l.descricao||'',sku,ped:Number(l.quantidade_pedida||0),rec:Number(l.quantidade_recebida||0),un:l.unidade||'UN'})}
 if(!out.length&&!(i.insumos||[]).length)for(const ln of String(i.material||'').split(/\n+/)){if(ln.trim()&&casa(nSemAcento(ln)))out.push({desc:ln.trim(),sku:'',ped:0,rec:0,un:''})}
 return out}
function insumoMarca(txt,termos){const o=String(txt||'');const n=[...o].map(c=>{const b=c.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();return b.length===1?b:'\u0000'}).join('');if(n.length!==o.length)return html(o);
 const mark=new Array(o.length).fill(false);for(const t of termos){let k=0;while((k=n.indexOf(t,k))>=0){for(let j=k;j<k+t.length;j++)mark[j]=true;k+=t.length}}
 let r='',on=false;for(let j=0;j<o.length;j++){if(mark[j]&&!on){r+='<mark>';on=true}if(!mark[j]&&on){r+='</mark>';on=false}r+=html(o[j])}return r+(on?'</mark>':'')}
function insumoHit(i){const h=insumoHits.get(i.pc);if(!h||!h.length)return'';const termos=insumoTermos();
 return`<div class="insumo-hit">${h.slice(0,3).map(l=>`<div>🔩 ${l.sku?`<b>${insumoMarca(l.sku,termos)}</b> · `:''}${insumoMarca(short(l.desc,110),termos)}${l.ped?` <span class="muted">— pedido ${number(l.ped)} ${html(l.un)}${l.rec?` · recebido ${number(l.rec)}`:''}</span>`:''}</div>`).join('')}${h.length>3?`<div class="muted">+ ${h.length-3} linha(s) com o mesmo critério</div>`:''}</div>`}
function insumoResumo(){const el=$('insumoResumo');if(!el)return;const termos=insumoTermos();if(!termos.length){el.hidden=true;el.textContent='';return}
 let ped=0,rec=0,linhas=0;const uns=new Set();for(const i of lastFiltered){for(const l of (insumoHits.get(i.pc)||[])){linhas++;ped+=l.ped;rec+=l.rec;if(l.un)uns.add(l.un)}}
 el.hidden=false;el.innerHTML=`🔩 <b>${number(lastFiltered.length)}</b> pedido(s) com o item${uns.size===1&&ped?` · <b>${number(ped)}</b> ${html([...uns][0])} pedidas, <b>${number(rec)}</b> recebidas, <b>${number(Math.max(0,ped-rec))}</b> pendentes`:''}`}
function filteredItems(){const q=$('q').value.toLocaleLowerCase('pt-BR').trim(),status=$('statusFilter').value,supplier=$('supplierFilter').value,sort=$('orderSort').value,obraF=$('obraFilter')?$('obraFilter').value:'';insumoHits.clear();const insT=insumoTermos();const matches=items.filter(i=>{if(status==='novo'&&!isNewOrder(i))return false;if(status==='pendente'&&i.chegou)return false;if(status==='fotos'&&!i.fotosProdutos.length)return false;if(status==='semNF'&&(!arrived(i)||i.numeroNF))return false;if(status!=='all'&&!['novo','pendente','fotos','semNF'].includes(status)&&i.chegou!==status)return false;if(supplier&&i.fornecedor!==supplier)return false;if(obraF){const k=destinoChave(i);if(obraF==='__sem'?k!=='':obraF==='OBRA:*'?!k.startsWith('OBRA'):k!==obraF)return false;}if(insT.length){const h=insumoLinhas(i,insT);if(!h.length)return false;insumoHits.set(i.pc,h)}if(q&&!String(i.pc).includes(q)&&![i.fornecedor,i.material,i.situacao,i.numeroNF,i.chaveNF,i.solicitante,destinoRotulo(i),(i.insumos||[]).map(l=>l.sku||'').join(' ')].some(s=>String(s||'').toLocaleLowerCase('pt-BR').includes(q)))return false;return true});matches.sort((a,b)=>sort==='novos'?(Number(isNewOrder(b))-Number(isNewOrder(a)))||(isNewOrder(a)&&isNewOrder(b)?((Date.parse(a.novo_pedido_em)||0)-(Date.parse(b.novo_pedido_em)||0))||a.pc-b.pc:((Date.parse(b.novo_pedido_em)||0)-(Date.parse(a.novo_pedido_em)||0))||b.pc-a.pc):sort==='pc_asc'?a.pc-b.pc:sort==='fornecedor'?a.fornecedor.localeCompare(b.fornecedor,'pt-BR')||b.pc-a.pc:sort==='recent'?(Date.parse(b.updated_at)||0)-(Date.parse(a.updated_at)||0)||b.pc-a.pc:b.pc-a.pc);return matches}
function filtersChanged(){currentPage=1;renderOrders();renderExportPreview()}
function clearFilters(){$('q').value='';if($('insumoQ'))$('insumoQ').value='';$('statusFilter').value='all';$('supplierFilter').value='';if($('obraFilter'))$('obraFilter').value='';$('orderSort').value='novos';filtersChanged()}
function statusOptions(value){return `<option value="" ${!value?'selected':''}>— Sem status —</option>`+STATUS.map(s=>`<option value="${html(s)}" ${value===s?'selected':''}>${STATUS_NAMES[s]}</option>`).join('')}
function renderOrders(){lastFiltered=filteredItems();const pageSize=Number($('pageSize').value),totalPages=Math.max(1,Math.ceil(lastFiltered.length/pageSize));currentPage=Math.max(1,Math.min(currentPage,totalPages));const slice=lastFiltered.slice((currentPage-1)*pageSize,currentPage*pageSize);$('resultCount').textContent=`${number(lastFiltered.length)} de ${number(items.length)} pedidos encontrados`;insumoResumo();$('pageInfo').textContent=`Página ${currentPage} de ${totalPages}`;$('prevPage').disabled=currentPage===1;$('nextPage').disabled=currentPage===totalPages;$('selectPage').checked=slice.length>0&&slice.every(i=>selected.has(i.pc));$('selectPage').indeterminate=slice.some(i=>selected.has(i.pc))&&!$('selectPage').checked;let rows='',cards='';for(const i of slice){const checked=selected.has(i.pc)?'checked':'',ev=(i.fotosProdutos.length?'📷 '+i.fotosProdutos.length+' ':'')+(hasNF(i)?'🧾':'');rows+=`<tr><td><input type="checkbox" aria-label="Selecionar PC ${i.pc}" ${checked} onchange="toggleSel(${i.pc},this.checked)"></td><td><span class="num">${i.pc}</span>${isNewOrder(i)?'<span class="new-tag" title="Novo pedido pendente de conferência">NOVO</span>':''}</td><td class="material-cell"><strong>${html(short(i.fornecedor,44))}</strong>${destinoRotulo(i)?`<span class="destino-tag ${i.destino_setor==='OBRA'?'obra':''}" title="Destino do pedido${i.destino_origem==='auto'?' (identificado automaticamente)':''}">${html(destinoRotulo(i))}</span>`:''}<small title="${html(i.material)}">${html(short(i.material,115))}</small>${insumoHit(i)}</td><td><span class="tag situacao" title="${html(i.situacao)}">${html(short(i.situacao,20))}</span></td><td>${i.insumos.length||i.recebimento_estado==='finalizado'?`<span class="tag ${statusClass(i.chegou)}" title="Status definido automaticamente na finalização">${html(STATUS_NAMES[i.chegou]||'Aguardando conferência')}</span>`:`<select aria-label="Chegada PC ${i.pc}" onchange="setStatus(${i.pc},this.value)">${statusOptions(i.chegou)}</select>`}</td><td>${fmtDate(i.data)}</td><td>${html(i.numeroNF||'—')}</td><td class="evidence ${ev?'present':''}">${ev||'—'}</td><td><span class="phase-tag ${receiptClass(i)}">${receiptPhase(i)}</span></td><td><button class="tiny-btn" onclick="openDrawer(${i.pc})">Detalhes →</button></td></tr>`;cards+=`<div class="mob-card"><div class="mob-head"><label><input type="checkbox" ${checked} onchange="toggleSel(${i.pc},this.checked)"> <strong>PC ${i.pc}</strong>${isNewOrder(i)?'<span class="new-tag">NOVO</span>':''}</label><span class="tag ${statusClass(i.chegou)}">${STATUS_NAMES[i.chegou]||html(i.chegou)}</span></div><div class="mob-meta">${html(short(i.fornecedor,70))} · ${html(short(i.situacao,25))}${destinoRotulo(i)?` · <b>${html(destinoRotulo(i))}</b>`:''}</div><div class="mob-phase"><span class="phase-tag ${receiptClass(i)}">${receiptPhase(i)}</span>${i.recebimento_finalizado_em?`<small>Finalizado: ${fmtDatetime(i.recebimento_finalizado_em)}</small>`:i.recebimento_iniciado_em?`<small>Iniciado: ${fmtDatetime(i.recebimento_iniciado_em)}</small>`:''}</div><div class="mob-material">${html(short(i.material,95))}</div>${insumoHit(i)}<div class="mob-actions">${i.insumos.length||i.recebimento_estado==='finalizado'?`<span class="tag ${statusClass(i.chegou)}" title="Status definido automaticamente na finalização">${html(STATUS_NAMES[i.chegou]||'Aguardando conferência')}</span>`:`<select aria-label="Chegada PC ${i.pc}" onchange="setStatus(${i.pc},this.value)">${statusOptions(i.chegou)}</select>`}<span>${ev||'Sem anexos'}</span><button class="tiny-btn" onclick="openDrawer(${i.pc})">Abrir →</button></div></div>`} $('ordersBody').innerHTML=rows||'<tr><td colspan="10" class="empty">Nenhum pedido encontrado para estes filtros.</td></tr>';$('mobileCards').innerHTML=cards||'<p class="empty">Nenhum pedido encontrado.</p>';renderSelection()}
function renderSelection(){$('selCount').textContent=selected.size;$('selectionToolbar').hidden=!selected.size}
function changePage(n){currentPage+=n;renderOrders();$('page-pedidos').scrollIntoView({behavior:'smooth',block:'start'})}
function toggleSel(pc,on){if(on)selected.add(pc);else selected.delete(pc);renderOrders();renderExportPreview()}
function togglePageSelection(on){const n=Number($('pageSize').value);lastFiltered.slice((currentPage-1)*n,currentPage*n).forEach(i=>on?selected.add(i.pc):selected.delete(i.pc));renderOrders();renderExportPreview()}
function clearSelection(){selected.clear();renderOrders();renderExportPreview()}
function selectReceived(){selected.clear();items.filter(arrived).forEach(i=>selected.add(i.pc));goPage('pedidos');renderOrders();renderExportPreview();toast(`${selected.size} recebidos selecionados`)}
function exportSelected(){$('exportScope').value='selecionados';goPage('exportacao');renderExportPreview()}
async function savePatch(pc,patch){
 const it=items.find(x=>x.pc===pc);if(!it)return;
 const old={};for(const k in patch){old[k]=it[k];it[k]=patch[k]}
 const prior=pendingSaves.get(pc)||Promise.resolve();
 const request=prior.catch(()=>{}).then(()=>{
  const current=items.find(x=>x.pc===pc);
  return api('save',{method:'POST',headers:{'Content-Type':'application/json','X-Client-Id':notificationClientId},body:JSON.stringify({pc,...patch,...(Object.prototype.hasOwnProperty.call(patch,'insumos')?{expected_updated_at:current?.updated_at??''}:{})})});
 });
 pendingSaves.set(pc,request);
 try{
  const res=await request;const idx=items.findIndex(x=>x.pc===pc);
  if(idx>=0)items[idx]=normalized(res.item);
  renderDashboard();renderOrders();renderExportPreview();
  if(drawerPC===pc){$('drawerSaveHint').textContent='✓ Salvo no servidor';if(Object.prototype.hasOwnProperty.call(patch,'chegou')||Object.prototype.hasOwnProperty.call(patch,'insumos')||Object.prototype.hasOwnProperty.call(patch,'origem_entrega'))renderDrawer();}
 }catch(e){const current=items.find(x=>x.pc===pc);if(current){for(const k in old)current[k]=old[k]};renderOrders();if(drawerPC===pc)renderDrawer();toast('Não foi possível salvar: '+e.message,true);if(/alterado em outro acesso/i.test(e.message))await load();}
 finally{if(pendingSaves.get(pc)===request)pendingSaves.delete(pc);if(drawerPC===pc)updateReceiptFooter();if(pendingSaves.size===0&&drawerPC===null&&typeof refreshIfDeferred==='function')refreshIfDeferred()}
}

function setStatus(pc,v){const i=items.find(x=>x.pc===pc);if(!i)return;if(i.recebimento_estado==='finalizado'){toast('Recebimento finalizado: status de chegada bloqueado.',true);renderAll();return}savePatch(pc,{chegou:v})}
function setField(pc,key,value){if(key==='chaveNF'){value=value.replace(/\D/g,'').slice(0,44);if(value.length>0&&value.length!==44){toast('A chave da NF deve ter 44 dígitos; o valor parcial será salvo.',true)}}savePatch(pc,{[key]:value})}
function openDrawer(pc){const it=items.find(i=>i.pc===pc);if(!it)return;drawerPC=pc;renderDrawer();$('drawer').classList.add('on');$('drawerMask').classList.add('on');$('drawer').setAttribute('aria-hidden','false');document.body.style.overflow='hidden';$('drawerContent').scrollTop=0}
function closeDrawer(){$('drawer').classList.remove('on');$('drawerMask').classList.remove('on');$('drawer').setAttribute('aria-hidden','true');document.body.style.overflow='';drawerPC=null;if(typeof refreshIfDeferred==='function')refreshIfDeferred()}
function canReceiveBalance(i){return i.recebimento_estado==='finalizado'&&i.chegou==='PARCIAL'&&missingItems(i).length>0;}
function updateReceiptFooter(){
 const i=items.find(x=>x.pc===drawerPC),b=$('receiptActionBtn'),n=$('drawerSaveHint');
 if(!i||!b)return;
 const busy=receiptBusy.has(i.pc)||pendingSaves.has(i.pc);
 const hasFiscal=Boolean(i.chaveNF||i.fotoNF||i.xmlNF);
 if(i.recebimento_estado==='finalizado'){
  b.textContent=canReceiveBalance(i)?'Aguardando nova entrega':'✓ Recebimento finalizado';b.disabled=true;b.className='button completed';
  n.textContent=canReceiveBalance(i)?'Quando os materiais restantes chegarem, lance a primeira quantidade abaixo: a nova etapa inicia automaticamente. A entrega anterior fica no histórico.':'Entrega confirmada. Fotos e NF podem ser anexadas depois, sem alterar os horários.';
 }else if(i.recebimento_estado==='em_andamento'){
  b.textContent=busy?'Salvando…':'✓ Finalizar conferência';b.disabled=busy||!i.insumos.length||!i.origem_entrega||!hasFiscal||!hasNewQty(i)||!allChecked(i);b.className='button primary';
  n.textContent=!i.insumos.length?'Cadastre os insumos do PC.':!i.origem_entrega?'Selecione a origem da entrega.':!hasFiscal?'Informe a chave da NF ou anexe foto/PDF/XML da nota.':!hasNewQty(i)?'Informe pelo menos uma quantidade positiva recebida.':!allChecked(i)?'Confirme todos os itens: quantidade recebida ou “Não chegou”.':'Tudo pronto. Data, hora e notificações serão registradas somente na finalização.';
 }else{
  b.textContent='Aguardando primeiro lançamento';b.disabled=true;b.className='button ghost';
  n.textContent='Início automático: registre a primeira quantidade recebida ou marque “Não chegou” em um item. Finalize somente após conferir todos os insumos e a NF.';
 }
}
async function receiptAction(){
 const pc=drawerPC,i=items.find(x=>x.pc===pc);if(!i||receiptBusy.has(pc))return;
 receiptBusy.add(pc);updateReceiptFooter();
 try{
  if(pendingSaves.has(pc))await pendingSaves.get(pc);
  const current=items.find(x=>x.pc===pc);
  if(!current||current.recebimento_estado!=='em_andamento')throw Error('Registre primeiro a quantidade recebida de um insumo para iniciar a conferência.');
  if(!current.origem_entrega||!(current.chaveNF||current.fotoNF||current.xmlNF)||!allChecked(current)||!hasNewQty(current))throw Error('Complete origem, NF e quantidades de todos os itens antes de finalizar.');
  // v3.29.0: última conferência de quem será avisado (falha técnica nunca bloqueia o recebimento)
  if(typeof avisoTrava==='function'){const seguir=await avisoTrava(pc);if(!seguir)return;}
  let avisoDesde='';try{if(typeof avisoGet==='function'){const a=await avisoGet('pc_avisar_info',{pc});avisoDesde=(a.ultimo_envio&&a.ultimo_envio.em)||''}}catch(_){}
  const res=await api('finish_receipt',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc})});
  if(typeof avisoResultado==='function')avisoResultado(pc,avisoDesde);
  const idx=items.findIndex(x=>x.pc===pc);if(idx>=0)items[idx]=normalized(res.item);
  renderAll();toast('Conferência finalizada: data e hora registradas; Push enfileirado.');
 }catch(e){toast('Não foi possível finalizar: '+e.message,true);await load();}
 finally{receiptBusy.delete(pc);if(drawerPC===pc)updateReceiptFooter();}
}
function securePhotoPath(p){return /^data\/photos\/[A-Za-z0-9_.-]+$/.test(p||'')?p:''}
function origemEntregaLabel(v){return v==='COLETA_DRILLING'?'Coleta interna Drilling':v==='ENTREGA_FORNECEDOR'?'Entrega do fornecedor':'Não informada'}
function missingItems(i){return (i.insumos||[]).filter(x=>Number(x.quantidade_pendente)>0.00001)}
function deliveryTotals(i){return (i.insumos||[]).reduce((a,x)=>{a.pedido+=Number(x.quantidade_pedida)||0;a.recebido+=Number(x.quantidade_recebida)||0;a.pendente+=Number(x.quantidade_pendente)||0;return a},{pedido:0,recebido:0,pendente:0})}
function allChecked(i){return i.insumos.length>0&&i.insumos.every(x=>x.conferido_etapa===true)}
function hasNewQty(i){const prior=i.entregas?.[i.entregas.length-1];const amounts=new Map((prior?.insumos||[]).map(x=>[x.id,Number(x.quantidade_recebida)||0]));return i.insumos.some(x=>Number(x.quantidade_recebida)>Number(amounts.get(x.id)||0)+.00001)}
function previousReceived(i, line){
 const prev=i.entregas?.[i.entregas.length-1];
 return Number((prev?.insumos||[]).find(x=>x.id===line.id)?.quantidade_recebida)||0;
}
function currentStepQty(i,line){return Math.max(0,Number(line.quantidade_recebida)-previousReceived(i,line));}
function itemGrid(i){
 const locked=i.recebimento_estado==='finalizado'&&!canReceiveBalance(i);
 const prior=i.entregas?.length||0;
 const missing=missingItems(i);
 const reviewed=i.insumos.filter(x=>x.conferido_etapa===true).length;
 const stageHint=locked?'Conferência finalizada. Quantidades e confirmação preservadas no histórico.': 'DUPLO CHECK: compare a quantidade do pedido com a chegada física. Informe a quantidade recebida de CADA item; para os não entregues, toque “Não chegou”.';
 const rows=i.insumos.map((x,n)=>{
  const receivedBefore=previousReceived(i,x),now=currentStepQty(i,x),editable=!locked;
  const remaining=Math.max(0,Number(x.quantidade_pedida)-receivedBefore);
  const checked=i.recebimento_estado!=='finalizado'&&x.conferido_etapa===true;
  const pedida=editable&&i.recebimento_estado!=='finalizado'&&Number(x.quantidade_recebida)<=0?`<input aria-label="Quantidade pedida de ${html(x.descricao)}" type="number" inputmode="decimal" min="0.001" step="any" value="${Number(x.quantidade_pedida)}" onchange="updateItem(${i.pc},${n},'quantidade_pedida',this.value)">`:`<strong>${number(x.quantidade_pedida)}</strong>`;
  const entrega=editable?`<div class="receipt-input-row"><input class="received-qty" aria-label="Quantidade recebida de ${html(x.descricao)}" inputmode="decimal" type="number" min="0" max="${remaining}" step="any" placeholder="Digite" value="${checked?now:''}" onchange="updateItem(${i.pc},${n},'quantidade_nesta_entrega',this.value)"><button type="button" class="zero-button" onclick="markNotArrived(${i.pc},${n})" aria-label="Confirmar que ${html(x.descricao)} não chegou">Não chegou</button></div>`:`<strong>${number(prior?Number((i.entregas[prior-1]?.insumos||[]).find(v=>v.id===x.id)?.quantidade_nesta_entrega)||0:Number(x.quantidade_recebida))}</strong>`;
  const review=locked?'<span class="check-reviewed">✓ Confirmado</span>':checked?'<span class="check-reviewed">✓ Conferido</span>':'<span class="check-pending">○ A conferir</span>';
  return `<div class="check-item ${checked?'checked':''}" role="group" aria-label="Insumo ${n+1}: ${html(x.descricao)}"><div class="check-item-top"><div class="check-item-name"><small>ITEM ${n+1} · ${html(x.unidade||'UN')}${x.sku?' · SKU '+html(x.sku):''}</small><strong>${html(x.descricao)}</strong></div>${review}</div><div class="check-item-fields"><label><span>Qtd. comprada</span>${pedida}</label><label><span>Recebido anteriormente</span><strong>${number(receivedBefore)}</strong></label><label class="current-qty"><span>Recebido nesta entrega</span>${entrega}</label><div class="qty-balance"><span>Falta entregar</span><strong>${number(x.quantidade_pendente)}</strong></div></div>${editable?`<div class="item-secondary-action"><button type="button" class="link-danger" onclick="removeItem(${i.pc},${n})">Excluir item do pedido</button></div>`:''}</div>`;
 }).join('');
 const history=(i.entregas||[]).map((e,n)=>`<div class="delivery-record"><strong>Entrega ${n+1} · ${fmtDatetime(e.confirmado_em)}</strong><span>${html(origemEntregaLabel(e.origem_entrega))} · ${e.status==='PARCIAL'?'Parcialmente entregue':'Completo'}</span><details><summary>Itens desta entrega</summary>${(e.insumos||[]).map(x=>`<div>${html(x.descricao)}: +${number(x.quantidade_nesta_entrega)} ${html(x.unidade||'UN')} · faltam ${number(x.quantidade_pendente)}</div>`).join('')}</details></div>`).join('');
 return `<div class="drawer-group item-control"><div class="item-head"><div><h3>Conferência por insumo · Duplo check</h3><p class="inline-hint">PC ${i.pc} · ${i.insumos.length} insumo(s). ${html(stageHint)}</p></div><span class="phase-tag ${missing.length?'active':'done'}">${missing.length} com saldo</span></div>
 ${i.insumos.length?`<div class="check-progress">${locked?'Conferência finalizada':`Itens conferidos nesta etapa: <strong>${reviewed} de ${i.insumos.length}</strong>`}</div><div class="check-items">${rows}</div>`:`<div class="missing-alert"><strong>Quantidade do PC não disponível.</strong><p>A base importada não contém a relação dos materiais comprados nem suas quantidades. Não podemos inventar o pedido. Copie abaixo os itens do PC original ou cadastre-os individualmente.</p></div>`}
 ${missing.length?`<details class="missing-summary"><summary><strong>Saldo pendente (${missing.length} itens)</strong></summary>${missing.map(x=>`<div>• ${html(x.descricao)} — falta(m) <strong>${number(x.quantidade_pendente)} ${html(x.unidade)}</strong></div>`).join('')}</details>`:i.insumos.length?'<p class="inline-hint">Sem saldo pendente nos itens registrados.</p>':''}
 ${!locked&&!i.insumos.length&&i.origemNF==='XML'&&i.itensNF?`<button class="button ghost small" onclick="useNFItems(${i.pc})">▤ Sugerir insumos do XML da NF</button><p class="inline-hint">A NF contém quantidade faturada, que pode ser diferente da quantidade comprada. Verifique contra o PC.</p>`:''}
 ${!locked&&!i.insumos.length?`<div class="import-order-box"><label for="orderLinesText">Cole os itens do pedido (uma linha por item)</label><textarea id="orderLinesText" rows="4" placeholder="14) METALON 40 X 40 X 2\n5) CANTONEIRA 1 POLEGADA\n11) CHAPA EXPANDIDA"></textarea><button type="button" class="button ghost small" onclick="importOrderText(${i.pc})">✓ Identificar itens e quantidades</button><small>Use o formato “14) DESCRIÇÃO”. Os dados serão mostrados para revisão antes de salvar.</small></div>`:''}
 ${!locked&&i.recebimento_estado!=='finalizado'?`<div class="item-add"><input id="newItemDesc" maxlength="600" placeholder="Descrição do insumo" aria-label="Descrição do novo insumo"><input id="newItemQty" type="number" inputmode="decimal" min="0.001" step="any" placeholder="Qtd pedida" aria-label="Quantidade pedida"><button class="button ghost small" onclick="addItem(${i.pc})">+ Incluir insumo</button></div>`:''}
 ${history?`<details class="history-receipts"><summary>Histórico de entregas (${i.entregas.length})</summary>${history}</details>`:''}
 ${i.historico_retificacoes?.length?`<details class="history-receipts"><summary>Histórico de retificações (${i.historico_retificacoes.length})</summary>${i.historico_retificacoes.map(c=>`<div class="delivery-record"><strong>${c.tipo==='admin'?'Correção do administrador':'Retificação'} ${html(c.numero)} · ${fmtDatetime(c.feito_em)}</strong><div>${c.tipo==='admin'?'Administrador':'Conferente'}: ${html(c.responsavel_declarado)}</div>${c.tipo==='admin'?`<div>Entrega ${Number(c.entrega_indice)+1}: ${(c.depois?.entrega?.insumos||[]).filter(x=>Number(c.quantidades_antes?.[x.id]??0)!==Number(c.quantidades_depois?.[x.id]??0)).map(x=>`${html(x.descricao)}: ${number(c.quantidades_antes?.[x.id]??0)} → <strong>${number(c.quantidades_depois?.[x.id]??0)}</strong> ${html(x.unidade)}`).join(' · ')||'sem mudança de quantidade'}</div>`:''}<div>Motivo: ${html(c.motivo)}</div><div>${html(c.antes?.chegou==='SIM'?'Recebido':'Parcial')} → ${html(c.depois?.chegou==='SIM'?'Recebido':'Parcial')}</div></div>`).join('')}</details>`:''}
 ${locked&&i.entregas?.length?`<div class="correction-entry admin-fix"><button type="button" class="button ghost small" onclick="openAdminFix(${i.pc})">🛠 Corrigir quantidade recebida (administrador)</button><p class="inline-hint">Para quando o conferente lançou a quantidade errada. Exige perfil de administrador e motivo; o valor anterior fica no histórico.</p><div id="adminFixSection"></div></div>`:''}
 ${locked&&(i.chegou==='PARCIAL'||i.historico_retificacoes?.some(c=>c.entrega_indice===i.entregas.length-1))?`<div class="correction-entry"><button type="button" class="button ghost small" onclick="openCorrection(${i.pc})">✎ Retificar última entrega parcial</button><p class="inline-hint">A retificação exige justificativa e responsável. Para registrar materiais que chegaram depois, use “Receber saldo pendente”.</p><div id="correctionSection"></div></div>`:''}
 </div>`;
}
function markNotArrived(pc,n){updateItem(pc,n,'quantidade_nesta_entrega','0')}
function importOrderText(pc){
 const i=items.find(x=>x.pc===pc),el=$('orderLinesText');if(!i||!el||i.insumos.length)return;
 const lines=el.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
 const found=[],invalid=[];
 for (const raw of lines){
  const match=raw.match(/^(\d+(?:[.,]\d+)?)\s*[)xX×;:-]\s*(.{3,})$/u);
  if(!match){invalid.push(raw);continue}
  const qty=Number(match[1].replace(',','.')),desc=match[2].trim();
  if(!Number.isFinite(qty)||qty<=0||qty>1e8||desc.length>600||found.length>=150){invalid.push(raw);continue}
  found.push({id:'item_'+(found.length+1),descricao:desc,unidade:'UN',quantidade_pedida:qty,quantidade_recebida:0,quantidade_pendente:qty,conferido_etapa:false});
 }
 if(!found.length){toast('Não identifiquei linhas no formato “14) Descrição”. Revise o texto do pedido.',true);return}
 if(invalid.length){toast(`${invalid.length} linha(s) não reconhecida(s). Corrija antes de importar para não perder materiais.`,true);return}
 if(!confirm(`Importar ${found.length} insumo(s) para o PC ${pc}? Confirme que representam todos os itens e quantidades do pedido original.`))return;
 savePatch(pc,{insumos:found});
}

function useNFItems(pc){const i=items.find(x=>x.pc===pc);if(!i||i.insumos.length||i.origemNF!=='XML')return;
 const parsed=i.itensNF.split(/\r?\n/).map((line,n)=>{const parts=line.split(' | ');const q=Number((parts[2]||'').replace(',','.'));return parts.length>=4 && Number.isFinite(q) && q>0?{id:'nf_'+(n+1),descricao:(parts[1]||'').slice(0,600),unidade:(parts[3]||'UN').slice(0,20),quantidade_pedida:q,quantidade_recebida:0,quantidade_pendente:q,conferido_etapa:false}:null}).filter(Boolean);
 if(!parsed.length){toast('XML não trouxe quantidades identificáveis; cadastre o pedido por insumo.',true);return}
 if(!confirm('Usar '+parsed.length+' linha(s) da NF como RASCUNHO do pedido? Atenção: quantidades faturadas podem ser menores que as compradas. Confira a quantidade pedida antes de finalizar.'))return;
 savePatch(pc,{insumos:parsed});}
function openCorrection(pc){
 const i=items.find(x=>x.pc===pc),el=$('correctionSection');if(!i||!el||!i.entregas?.length)return;
 const original=i.entregas[i.entregas.length-1];
 const prior=i.entregas[i.entregas.length-2];
 const before=new Map((prior?.insumos||[]).map(x=>[x.id,Number(x.quantidade_recebida)||0]));
 const reported=new Map((original.insumos||[]).map(x=>[x.id,Number(x.quantidade_nesta_entrega)||0]));
 const tr=i.insumos.map(x=>`<div class="correction-line"><label>${html(x.descricao)} <small>Pedido: ${number(x.quantidade_pedida)} ${html(x.unidade)} · Entregas anteriores: ${number(before.get(x.id)||0)} · Limite desta: ${number(Number(x.quantidade_pedida)-(before.get(x.id)||0))}</small></label><input data-correct-id="${html(x.id)}" type="number" inputmode="decimal" min="0" step="any" max="${Number(x.quantidade_pedida)-(before.get(x.id)||0)}" value="${reported.get(x.id)??Math.max(0,Number(x.quantidade_recebida)-(before.get(x.id)||0))}" aria-label="Quantidade retificada de ${html(x.descricao)}"></div>`).join('');
 el.innerHTML=`<form id="correctionForm" class="correction-form" onsubmit="submitCorrection(event,${pc})"><h4>Retificação auditada · PC ${pc}</h4><p class="inline-hint">Altere apenas o que foi recebido na última entrega. As quantidades de entregas anteriores ficam protegidas. A data da confirmação não será alterada.</p><div class="correction-lines">${tr}</div><div class="form-grid"><div class="form-line"><label>Status corrigido</label><select id="correctionStatus"><option value="PARCIAL" ${i.chegou==='PARCIAL'?'selected':''}>Parcialmente entregue</option><option value="SIM" ${i.chegou==='SIM'?'selected':''}>Recebido integralmente</option></select></div><div class="form-line"><label>Conferente responsável</label><input id="correctionActor" maxlength="120" minlength="3" required placeholder="Nome do conferente" autocomplete="name"></div><div class="form-line full"><label>Motivo da correção (obrigatório)</label><textarea id="correctionReason" required minlength="8" maxlength="1000" rows="3" placeholder="Descreva o que foi corrigido e por quê"></textarea></div></div><p class="inline-hint">Se o status escolhido não corresponder às quantidades, o servidor recusará a alteração. A retificação não dispara um novo aviso de recebimento.</p><div class="photo-actions"><button class="button primary small" type="submit">Salvar retificação no histórico</button><button class="button ghost small" type="button" onclick="closeCorrection()">Cancelar</button></div></form>`;
 el.scrollIntoView({block:'nearest',behavior:'smooth'});
}
function closeCorrection(){const e=$('correctionSection');if(e)e.innerHTML=''}
async function submitCorrection(ev,pc){
 ev.preventDefault();const f=$('correctionForm');if(!f)return;
 const lines=[...f.querySelectorAll('[data-correct-id]')].map(x=>({id:x.dataset.correctId,quantidade_nesta_entrega:x.value}));
 const responsavel=$('correctionActor').value.trim(),motivo=$('correctionReason').value.trim(),status=$('correctionStatus').value;
 if(!confirm(`Salvar retificação no PC ${pc}? O histórico original ficará registrado e a confirmação manterá a data anterior.`))return;
 const i=items.find(x=>x.pc===pc);if(!i)return;
 const btn=f.querySelector('button[type=submit]');btn.disabled=true;
 try{
  const res=await api('correct_partial',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,responsavel,motivo,status,insumos:lines,expected_revision:(i.historico_retificacoes||[]).length,expected_receipt_count:i.entregas.length,expected_confirmation:i.entregas[i.entregas.length-1]?.confirmado_em})});
  const idx=items.findIndex(x=>x.pc===pc);if(idx>=0)items[idx]=normalized(res.item);
  renderAll();toast('Retificação registrada com histórico e confirmação preservada.');
 }catch(e){toast('Não foi possível retificar: '+e.message,true);btn.disabled=false;}
}

function openAdminFix(pc){
 const i=items.find(x=>x.pc===pc),el=$('adminFixSection');if(!i||!el||!i.entregas?.length)return;
 const opts=i.entregas.map((e,n)=>`<option value="${n}" ${n===i.entregas.length-1?'selected':''}>Entrega ${n+1} · ${fmtDatetime(e.confirmado_em)}</option>`).join('');
 el.innerHTML=`<form id="adminFixForm" class="correction-form" onsubmit="submitAdminFix(event,${pc})"><h4>Correção do administrador · PC ${pc}</h4><div class="form-line"><label>Entrega a corrigir</label><select id="adminFixEntrega" onchange="adminFixCarregar(${pc})">${opts}</select></div><div class="correction-lines" id="adminFixLinhas"></div><div class="form-line full"><label>Motivo da correção (obrigatório)</label><textarea id="adminFixMotivo" required minlength="8" maxlength="1000" rows="3" placeholder="Ex.: conferente digitou 100, o correto é 10"></textarea></div><p class="inline-hint">O status (parcial/integral) e o saldo pendente são recalculados automaticamente. Entregas seguintes são ajustadas. A quantidade não pode ultrapassar o pedido.</p><div class="photo-actions"><button class="button primary small" type="submit">Salvar correção</button><button class="button ghost small" type="button" onclick="$('adminFixSection').innerHTML=''">Cancelar</button></div></form>`;
 adminFixCarregar(pc);el.scrollIntoView({block:'nearest',behavior:'smooth'});
}
function adminFixCarregar(pc){
 const i=items.find(x=>x.pc===pc),box=$('adminFixLinhas');if(!i||!box)return;
 const n=Number($('adminFixEntrega').value),atual=i.entregas[n],ant=i.entregas[n-1];
 const antes=new Map((ant?.insumos||[]).map(x=>[x.id,Number(x.quantidade_recebida)||0]));
 const qtd=new Map((atual?.insumos||[]).map(x=>[x.id,Number(x.quantidade_nesta_entrega)||0]));
 box.innerHTML=i.insumos.map(x=>`<div class="correction-line"><label>${html(x.descricao)} <small>Pedido: ${number(x.quantidade_pedida)} ${html(x.unidade)} · Entregas anteriores: ${number(antes.get(x.id)||0)}</small></label><input data-admin-id="${html(x.id)}" type="number" inputmode="decimal" min="0" step="any" value="${qtd.get(x.id)??0}" aria-label="Quantidade correta de ${html(x.descricao)}"></div>`).join('');
}
async function submitAdminFix(ev,pc){
 ev.preventDefault();const f=$('adminFixForm');if(!f)return;
 const i=items.find(x=>x.pc===pc);if(!i)return;
 const lines=[...f.querySelectorAll('[data-admin-id]')].map(x=>({id:x.dataset.adminId,quantidade_nesta_entrega:x.value}));
 const n=Number($('adminFixEntrega').value),motivo=$('adminFixMotivo').value.trim();
 if(!confirm(`Salvar a correção da entrega ${n+1} do PC ${pc}? Os valores anteriores ficarão no histórico.`))return;
 const btn=f.querySelector('button[type=submit]');btn.disabled=true;
 try{
  const res=await api('admin_corrigir_recebimento',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,entrega_indice:n,motivo,insumos:lines,expected_revision:(i.historico_retificacoes||[]).length,expected_receipt_count:i.entregas.length})});
  const idx=items.findIndex(x=>x.pc===pc);if(idx>=0)items[idx]=normalized(res.item);
  renderAll();toast('Correção registrada. Status e saldo recalculados.');
 }catch(e){toast('Não foi possível corrigir: '+e.message,true);btn.disabled=false;}
}
function updateItem(pc,n,key,value){
 const i=items.find(x=>x.pc===pc);if(!i||(i.recebimento_estado==='finalizado'&&!canReceiveBalance(i)))return;
 const lines=i.insumos.map(x=>({...x,...(canReceiveBalance(i)?{conferido_etapa:false}:{})}));const line=lines[n];if(!line)return;
 const num=Number(value),prior=previousReceived(i,line);
 if(String(value).trim()===''||!Number.isFinite(num)||num<0||key==='quantidade_pedida'&&num<=0){toast('Informe uma quantidade válida.',true);renderDrawer();return}
 if(key==='quantidade_nesta_entrega'){
  if(num>Number(line.quantidade_pedida)-prior+.00001){toast('Quantidade desta entrega excede o saldo do pedido.',true);renderDrawer();return}
  line.quantidade_recebida=prior+num;
  line.conferido_etapa=true;
 }else if(key==='quantidade_pedida'){
  if(prior>0||num<Number(line.quantidade_recebida)){toast('Não é permitido modificar a quantidade pedida já recebida.',true);renderDrawer();return}
  line.quantidade_pedida=num;
 }else return;
 line.quantidade_pendente=Math.max(0,Number(line.quantidade_pedida)-Number(line.quantidade_recebida));
 savePatch(pc,{insumos:lines});
}
function addItem(pc){const i=items.find(x=>x.pc===pc);if(!i||i.recebimento_estado==='finalizado')return;const desc=$('newItemDesc').value.trim(),qty=Number($('newItemQty').value);if(desc.length<2||!Number.isFinite(qty)||qty<=0){toast('Informe descrição e quantidade pedida válida.',true);return}const id='manual_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,7);savePatch(pc,{insumos:[...i.insumos,{id,descricao:desc,unidade:'UN',quantidade_pedida:qty,quantidade_recebida:0,quantidade_pendente:qty,conferido_etapa:false}]});}
function removeItem(pc,n){const i=items.find(x=>x.pc===pc);if(!i||i.recebimento_estado==='finalizado')return;if(Number(i.insumos[n]?.quantidade_recebida)>0){toast('Não é possível excluir item já recebido.',true);return}if(!confirm('Remover este insumo ainda não recebido?'))return;savePatch(pc,{insumos:i.insumos.filter((x,j)=>j!==n)});}
/* Pré-visualização de anexos: apenas leitura, inclusive após a finalização. */
let attachmentViewerState=null;
let attachmentViewerPreviousFocus=null;
function attachmentList(pc){
 const order=items.find(x=>x.pc===Number(pc));
 if(!order)return [];
 const found=[];
 const nf=securePhotoPath(order.fotoNF);
 if(nf)found.push({src:nf,title:'Nota fiscal · PC '+order.pc,kind:nf.toLowerCase().endsWith('.pdf')?'pdf':'image',tipo:'nf',slot:0});
 order.fotosProdutos.forEach((source,slot)=>{
  const safe=securePhotoPath(source);
  if(safe)found.push({src:safe,title:'Foto '+(slot+1)+' do material · PC '+order.pc,kind:'image',tipo:'produto',slot});
 });
 return found;
}
function attachmentGallery(order){
 const list=attachmentList(order.pc);
 const imgs=list.map((a,index)=>{
  const thumb=a.kind==='pdf'?'<span class="attachment-pdf-icon" aria-hidden="true">▤ PDF</span>':`<img loading="lazy" alt="" src="${html(a.src)}">`;
  return `<button class="attachment-tile" type="button" onclick="openAttachmentViewer(${order.pc},${index})" aria-label="Abrir ${html(a.title)}"><span class="attachment-tile-visual">${thumb}</span><span class="attachment-tile-label">${a.tipo==='nf'?'Nota fiscal':`Foto ${a.slot+1}`}</span><span class="attachment-tile-action">Toque para visualizar</span></button>`;
 }).join('');
 const state=order.recebimento_estado==='finalizado'?'Conferência finalizada · anexos disponíveis para consulta.':'Anexos disponíveis para consulta antes e depois da finalização.';
 return `<div class="drawer-group attachment-section"><div class="attachment-section-head"><h3>📎 Anexos do pedido <span class="attachment-count">${list.length}</span></h3><small>${html(state)}</small></div>${list.length?`<div class="attachment-grid">${imgs}</div>`:'<p class="attachment-empty">Nenhuma nota fiscal ou foto anexada a este pedido.</p>'}<p class="inline-hint">Clique em uma foto ou na NF para abrir em tela ampliada. Visualizar não modifica a conferência nem os horários registrados.</p></div>`;
}
function openAttachmentViewer(pc,index=0){
 const docs=attachmentList(pc);
 if(!docs.length)return toast('Nenhum anexo disponível para visualizar.',true);
 const selected=Number(index);
 if(!Number.isInteger(selected)||selected<0||selected>=docs.length)return;
 attachmentViewerPreviousFocus=document.activeElement;
 attachmentViewerState={pc:Number(pc),index:selected};
 $('attachmentViewer').classList.add('on');
 $('attachmentViewer').setAttribute('aria-hidden','false');
 document.body.classList.add('attachment-viewer-open');
 renderAttachmentViewer();
 $('attachmentViewerClose').focus();
}
function renderAttachmentViewer(){
 if(!attachmentViewerState)return;
 const {pc,index}=attachmentViewerState;
 const docs=attachmentList(pc);
 if(!docs[index]){closeAttachmentViewer();return;}
 const current=docs[index],src=current.src;
 $('attachmentViewerTitle').textContent=current.title;
 $('attachmentViewerCounter').textContent=`Anexo ${index+1} de ${docs.length}`;
 $('attachmentViewerContent').replaceChildren();
 if(current.kind==='pdf'){
  const frame=document.createElement('iframe');
  frame.src=src;
  frame.title='Visualização da '+current.title;
  frame.className='attachment-pdf-frame';
  $('attachmentViewerContent').append(frame);
 }else{
  const img=document.createElement('img');
  img.src=src;
  img.alt=current.title;
  img.className='attachment-full-image';
  img.onerror=()=>{if(attachmentViewerState?.pc===pc){$('attachmentViewerMessage').textContent='Não foi possível carregar esta imagem. Tente Abrir em outra aba.';}};
  $('attachmentViewerContent').append(img);
 }
 $('attachmentViewerMessage').textContent=current.kind==='pdf'?'Se o PDF não aparecer neste navegador, use “Abrir em outra aba”.':'';
 $('attachmentViewerOpen').href=src;
 $('attachmentViewerOpen').setAttribute('aria-label','Abrir '+current.title+' em outra aba');
 $('attachmentViewerPrev').disabled=index===0;
 $('attachmentViewerNext').disabled=index===docs.length-1;
}
function moveAttachmentViewer(direction){
 if(!attachmentViewerState)return;
 const next=attachmentViewerState.index+direction;
 if(next<0||next>=attachmentList(attachmentViewerState.pc).length)return;
 attachmentViewerState.index=next;
 renderAttachmentViewer();
}
function closeAttachmentViewer(){
 if(!attachmentViewerState)return;
 attachmentViewerState=null;
 $('attachmentViewer').classList.remove('on');
 $('attachmentViewer').setAttribute('aria-hidden','true');
 $('attachmentViewerContent').replaceChildren();
 $('attachmentViewerOpen').removeAttribute('href');
 document.body.classList.remove('attachment-viewer-open');
 const focus=attachmentViewerPreviousFocus;
 attachmentViewerPreviousFocus=null;
 if(focus?.isConnected)focus.focus();
}
window.addEventListener('keydown',event=>{
 if(!attachmentViewerState)return;
 if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();closeAttachmentViewer();}
 if(event.key==='ArrowRight')moveAttachmentViewer(1);
 if(event.key==='ArrowLeft')moveAttachmentViewer(-1);
},true);
function renderDrawer(){const i=items.find(x=>x.pc===drawerPC);if(!i)return;$('drawerTitle').innerHTML='PC '+i.pc+(isNewOrder(i)?' <span class="new-tag" title="Pedido recém-cadastrado, aguardando primeira conferência">NOVO</span>':'');$('drawerSubtitle').textContent=i.fornecedor;$('drawerSaveHint').textContent='Alterações salvas ao sair dos campos';let slots='';for(let s=0;s<3;s++){const p=securePhotoPath(i.fotosProdutos[s]);slots+=p?`<div class="photo-slot"><button type="button" class="photo-preview-button" onclick="openAttachmentViewer(${i.pc},${(securePhotoPath(i.fotoNF)?1:0)+s})" aria-label="Visualizar foto ${s+1} do PC ${i.pc}"><img src="${html(p)}" alt="Foto ${s+1} do PC ${i.pc}"></button><button class="rm" aria-label="Remover foto ${s+1}" onclick="removePhoto(${i.pc},'produto',${s})">✕</button></div>`:`<button class="photo-slot" onclick="startPhoto(${i.pc},'produto',${s})">+ Foto ${s+1}</button>`}
const fileNF=securePhotoPath(i.fotoNF);$('drawerContent').innerHTML=`<div class="drawer-group workflow-panel"><div class="workflow-head"><div><h3>Controle de recebimento</h3><span class="phase-tag ${receiptClass(i)}">${receiptPhase(i)}</span></div><small>Carimbos de hora automáticos · Brasília</small></div><div class="timeline-grid"><div><small>INÍCIO</small><strong>${fmtDatetime(i.recebimento_iniciado_em)}</strong></div><div><small>CONFIRMAÇÃO / FINALIZAÇÃO</small><strong>${fmtDatetime(i.recebimento_finalizado_em)}</strong></div></div><p class="inline-hint">O início é registrado automaticamente no primeiro lançamento de quantidade. A confirmação e os avisos acontecem somente em “Finalizar conferência”.</p></div><div class="drawer-group destino-box"><h3>Destino do pedido</h3>${destinoEditor(i)}</div><div class="drawer-group aviso-box"><h3>Avisar quando chegar</h3>${typeof avisoEditor==='function'?avisoEditor(i):''}</div><div class="drawer-group"><h3>Pedido e material</h3><label style="display:flex;gap:8px;align-items:center;margin-bottom:10px;font-size:12px;font-weight:700;color:${i.urgente==='1'?'#ff9ca4':'#b5c6dc'}"><input type="checkbox" ${i.urgente==='1'?'checked':''} onchange="setField(${i.pc},'urgente',this.checked?'1':'')"> 🚨 Pedido de compra emergencial / urgente</label><div class="detail-text">${html(i.material)}</div><div class="inline-hint" style="margin-top:10px">Situação atual: <strong>${html(i.situacao)}</strong> · ${html(i.ultima_acao||'Aguardando primeira ação')}${i.ultima_acao_em?' · '+fmtDatetime(i.ultima_acao_em):''}</div></div>${itemGrid(i)}${attachmentGallery(i)}<div class="drawer-group"><h3>Recebimento</h3><div class="form-grid"><div class="form-line"><label>Origem desta entrega</label>${i.recebimento_estado==='finalizado'?`<div class="auto-field"><strong>${html(origemEntregaLabel(i.origem_entrega))}</strong><small>Origem da etapa finalizada; na próxima entrega, lance uma quantidade para iniciar automaticamente e escolha a nova origem.</small></div>`:`<select onchange="setField(${i.pc},'origem_entrega',this.value)"><option value="" ${!i.origem_entrega?'selected':''}>— Selecionar —</option><option value="COLETA_DRILLING" ${i.origem_entrega==='COLETA_DRILLING'?'selected':''}>Coleta interna Drilling</option><option value="ENTREGA_FORNECEDOR" ${i.origem_entrega==='ENTREGA_FORNECEDOR'?'selected':''}>Entrega do fornecedor</option></select>`}</div><div class="form-line"><label>Status da conferência · automático</label><div class="auto-field"><strong>${html(i.recebimento_estado==='finalizado'?i.situacao:i.recebimento_estado==='em_andamento'?'Em conferência':'Pendente')}</strong><small>Calculado com as quantidades de cada insumo quando a conferência é finalizada.</small></div></div><div class="form-line"><label>Data e hora da confirmação · automático</label><div class="auto-field"><strong>${i.recebimento_finalizado_em?fmtDatetime(i.recebimento_finalizado_em):'Aguardando finalização'}</strong><small>Somente o botão Finalizar conferência confirma a entrega e envia notificações.</small></div></div><div class="form-line"><label>Solicitante</label><input value="${html(i.solicitante)}" placeholder="Nome do solicitante" onchange="setField(${i.pc},'solicitante',this.value)"></div><div class="form-line"><label>Nº da nota fiscal</label><input value="${html(i.numeroNF)}" placeholder="Ex.: 000123" onchange="setField(${i.pc},'numeroNF',this.value)"></div><div class="form-line"><label>Fornecedor</label><input value="${html(i.fornecedor)}" placeholder="Nome do fornecedor" onchange="setField(${i.pc},'fornecedor',this.value)"></div><div class="form-line full"><label>Chave de acesso da NF (44 dígitos)</label><input id="nfAccessKey_${i.pc}" inputmode="numeric" maxlength="54" value="${html(i.chaveNF)}" placeholder="Cole a chave de 44 dígitos"><div class="photo-actions" style="margin-top:8px"><button type="button" class="button ghost small" onclick="handleNFKey(${i.pc},document.getElementById('nfAccessKey_${i.pc}').value)">✓ Ler dados da chave</button></div><div class="inline-hint">Lê número, série e CNPJ. A chave não consulta os itens nem o valor da NF.</div></div><div class="form-line full"><label>Observações do recebimento</label><textarea placeholder="Divergências, avarias, volumes, informação de entrega…" onchange="setField(${i.pc},'obs',this.value)">${html(i.obs)}</textarea></div></div></div><div class="drawer-group nf-tools"><div class="nf-head"><div><h3>Nota fiscal inteligente</h3><small>Para extrair todos os dados, prefira o arquivo XML. Confira os campos antes de confirmar.</small></div><span class="phase-tag active">NF DIGITAL</span></div><div class="photo-actions"><button class="button primary small" onclick="startPhoto(${i.pc},'nf',0)">📷 Fotografar NF</button><button class="button ghost small" onclick="beginScan(${i.pc})">▦ QR / Barras</button><button class="button ghost small" onclick="pickXML(${i.pc})">⇧ Importar XML</button></div><div class="photo-actions"><button class="button ghost small" onclick="startPhotoGal(${i.pc},'nf',0)">Enviar foto/PDF da NF</button><button class="button ghost small" onclick="openManualNF(${i.pc})">✎ Preencher manualmente</button></div><p class="inline-hint">Importar XML preenche os campos fiscais e mostra itens para revisão. Fotografar NF usa OCR e pode falhar. QR / Barras lê somente a chave. Anexar PDF da NF NÃO extrai os dados automaticamente. Sempre confirme e salve.</p><div class="nf-details"><div><small>Série</small><strong>${html(i.serieNF||'—')}</strong></div><div><small>Emitente CNPJ</small><strong>${html(i.cnpjEmitente||'—')}</strong></div><div><small>Emissão</small><strong>${i.emissaoNF?fmtDate(i.emissaoNF):'—'}</strong></div><div><small>Valor NF</small><strong>${html(i.valorNF||'—')}</strong></div><div><small>Fonte</small><strong>${html(i.origemNF||'Manual / ausente')}</strong></div><div><small>XML</small><strong>${i.xmlNF?'Arquivado':'Não anexado'}</strong></div></div>${i.itensNF?`<details class="nf-items"><summary>Itens da NF (${html(i.itensNF.slice(0,40))}…)</summary><pre>${html(i.itensNF)}</pre></details>`:''}</div>${devolucoesGroup(i)}<div class="drawer-group"><h3>Fotos do produto · até 3</h3><div class="photo-grid">${slots}</div><div class="photo-actions"><button class="button ghost small" onclick="startPhoto(${i.pc},'produto',${i.fotosProdutos.length})">📷 Câmera</button><button class="button ghost small" onclick="startPhotoGal(${i.pc},'produto',${i.fotosProdutos.length})">▧ Galeria</button></div><div class="inline-hint">É permitido anexar as fotos agora ou depois da finalização. Os horários originais não serão modificados.</div></div><div class="drawer-group"><h3>Documento fiscal (foto ou PDF)</h3><div class="photo-actions"><button class="button ghost small" onclick="startPhoto(${i.pc},'nf',0)">📷 Fotografe a NF</button><button class="button ghost small" onclick="startPhotoGal(${i.pc},'nf',0)">⇧ Anexar NF</button>${fileNF?`<button type="button" class="button ghost small" onclick="openAttachmentViewer(${i.pc},0)">👁 Visualizar NF</button><button class="button ghost small" onclick="removePhoto(${i.pc},'nf',0)">Remover</button>`:''}</div></div>`;updateReceiptFooter()}
function startPhoto(pc,tipo,slot){const i=items.find(i=>i.pc===pc);if(!i)return;if(tipo==='produto'&&i.fotosProdutos.length>=3&&slot>=3){toast('Limite de três fotos atingido',true);return}uploadCtx={pc,tipo,slot};$('fileCam').click()}
function startPhotoGal(pc,tipo,slot){const i=items.find(i=>i.pc===pc);if(!i)return;if(tipo==='produto'&&i.fotosProdutos.length>=3&&slot>=3){toast('Limite de três fotos atingido',true);return}uploadCtx={pc,tipo,slot};$('fileGal').click()}
function compressImage(file,maxSide=1280,quality=.72){return new Promise((resolve,reject)=>{const img=new Image(),url=URL.createObjectURL(file);img.onload=()=>{let w=img.width,h=img.height;if(w>maxSide||h>maxSide){const ratio=Math.min(maxSide/w,maxSide/h);w=Math.round(w*ratio);h=Math.round(h*ratio)}const c=document.createElement('canvas');c.width=w;c.height=h;c.getContext('2d').drawImage(img,0,0,w,h);URL.revokeObjectURL(url);c.toBlob(b=>b?resolve(b):reject(Error('Erro na compressão')),'image/jpeg',quality)};img.onerror=()=>{URL.revokeObjectURL(url);reject(Error('Não foi possível abrir a imagem'))};img.src=url})}
async function onFile(e){const file=e.target.files?.[0];e.target.value='';if(!file||!uploadCtx)return;const {pc,tipo,slot}=uploadCtx;uploadCtx=null;try{let blob=file;if(file.type.startsWith('image/'))blob=await compressImage(file);else if(file.type!=='application/pdf'||tipo!=='nf')throw Error('Use JPG, PNG, WEBP ou PDF para NF');if(blob.size>3.5*1024*1024)throw Error('Arquivo maior que 3,5 MB');const fd=new FormData();fd.append('photo',blob,file.type==='application/pdf'?'nf.pdf':'foto.jpg');fd.append('pc',pc);fd.append('tipo',tipo);fd.append('slot',slot);const res=await api('upload_photo',{method:'POST',body:fd});const fresh=await api('list');items=fresh.items.map(normalized);renderAll();toast(tipo==='nf'?'NF anexada — iniciando leitura automática':'Foto do produto salva');if(tipo==='nf'&&file.type.startsWith('image/'))await analyzeNFPhoto(pc,file);}catch(e){toast('Erro no anexo: '+e.message,true)}}
async function removePhoto(pc,tipo,slot){if(!confirm('Remover este anexo?'))return;try{const res=await api('remove_photo',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,tipo,slot})});const idx=items.findIndex(x=>x.pc===pc);if(idx>=0)items[idx]=normalized(res.item);renderAll();toast('Anexo removido')}catch(e){toast('Falha: '+e.message,true)}}
function exportItems(){const scope=$('exportScope').value;return scope==='filtrados'?filteredItems():scope==='recebidos'?items.filter(arrived):scope==='selecionados'?items.filter(i=>selected.has(i.pc)):items.slice().sort((a,b)=>b.pc-a.pc)}
function headers(){const mode=$('exportMode').value;return mode==='insumos'?[...MANUAL_HEADERS,'ORIGEM DA ENTREGA','INSUMO','UNIDADE','QTD PEDIDA','QTD RECEBIDA','QTD NÃO ENTREGUE','STATUS']:mode==='completo'?COMPLETE_HEADERS:MANUAL_HEADERS}
function reportRows(){const mode=$('exportMode').value;const rows=exportItems();if(mode==='insumos')return rows.flatMap(i=>i.insumos.length?i.insumos.map(x=>[...formatExportRow(i,false),origemEntregaLabel(i.origem_entrega),x.descricao,x.unidade,x.quantidade_pedida,x.quantidade_recebida,x.quantidade_pendente,i.situacao]):[[...formatExportRow(i,false),origemEntregaLabel(i.origem_entrega),'Não cadastrado','','','','',i.situacao]]);return rows.map(i=>formatExportRow(i,mode==='completo'))} 
function formatExportRow(i,complete=false){const base=[i.data?fmtDate(i.data):'',String(i.pc),i.numeroNF,i.fornecedor,i.solicitante,i.obs];return complete?[...base,i.situacao,STATUS_NAMES[i.chegou]||i.chegou,i.material,i.chaveNF,i.fotoNF?'Sim':'Não',String(i.fotosProdutos.length),i.updated_at?fmtDatetime(i.updated_at):'',receiptPhase(i),i.recebimento_iniciado_em?fmtDatetime(i.recebimento_iniciado_em):'',i.recebimento_finalizado_em?fmtDatetime(i.recebimento_finalizado_em):'',i.serieNF,i.cnpjEmitente,i.emissaoNF?fmtDate(i.emissaoNF):'',i.valorNF,i.itensNF,i.origemNF,i.xmlNF?'Sim':'Não',i.ultima_acao||'',i.ultima_acao_em?fmtDatetime(i.ultima_acao_em):'',origemEntregaLabel(i.origem_entrega),i.insumos.map(x=>`${x.descricao}: ${x.quantidade_pedida} ${x.unidade}`).join(' | '),i.insumos.map(x=>`${x.descricao}: ${x.quantidade_recebida}`).join(' | '),missingItems(i).map(x=>`${x.descricao}: ${x.quantidade_pendente}`).join(' | '),String(i.entregas.length),String(i.historico_retificacoes?.length||0)]:base}
function renderExportPreview(){const rows=reportRows(),head=headers();$('exportTotals').textContent=`${number(exportItems().length)} pedido(s) · ${rows.length} linha(s) · ${head.length} colunas`;$('previewNote').textContent=`Amostra de ${Math.min(rows.length,12)} de ${rows.length} linhas`;$('previewHead').innerHTML='<tr>'+head.map(v=>`<th>${html(v)}</th>`).join('')+'</tr>';$('previewRows').innerHTML=rows.slice(0,12).map(row=>'<tr>'+row.map(v=>`<td>${html(v)}</td>`).join('')+'</tr>').join('')||`<tr><td colspan="${head.length}">Nenhum pedido no escopo selecionado.</td></tr>`}
function csvCell(v){let s=String(v??'');if(/^[\s]*[=+@-]/.test(s))s="'"+s;return '"'+s.replace(/"/g,'""')+'"'}
function downloadCSV(){const rows=exportItems();if(!rows.length){toast('Não há pedidos neste escopo',true);return}const lines=[headers(),...reportRows()];const csv='\ufeffsep=;\r\n'+lines.map(r=>r.map(csvCell).join(';')).join('\r\n');const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`recebimento_drilling_v3.0.0_${$('exportMode').value}_${localISO()}.csv`;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),5000);toast('CSV gerado para Excel')}
function printReport(){const rows=exportItems();if(!rows.length){toast('Não há pedidos neste escopo',true);return}const cols=headers(),complete=$('exportMode').value!=='manual',dataRows=reportRows();const win=window.open('','_blank');if(!win){toast('Permita abrir janelas para imprimir',true);return}const date=new Date().toLocaleString('pt-BR');const style=`<style>@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#18212d;margin:0;font-size:9pt}h1{font-size:15pt;margin:0 0 4px}p{font-size:8pt;margin:0 0 11px}table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}th,td{border:1px solid #6b7280;padding:5px 5px;text-align:left;vertical-align:top;overflow-wrap:anywhere;white-space:pre-wrap}th{font-size:8pt;background:#e6edf6;text-transform:uppercase}th:nth-child(1){width:10%}th:nth-child(2){width:7%}th:nth-child(3){width:10%}th:nth-child(4){width:22%}th:nth-child(5){width:18%}th:nth-child(6){width:33%}body.completo table{table-layout:auto}body.completo th,body.completo td{font-size:6.5pt;padding:4px}body.completo th{width:auto!important}.noprint{margin-bottom:12px;background:#edf3fb;border:1px solid #d4dce8;padding:10px;border-radius:6px}@media print{.noprint{display:none}}</style>`;win.document.open();win.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório de Recebimento</title>${style}</head><body class="${complete?'completo':''}"><div class="noprint">Relatório pronto. Use Imprimir → Salvar como PDF. <button onclick="window.print()">Imprimir novamente</button></div><h1>Recebimento Drilling · v3.0.0</h1><p>Data de emissão: ${html(date)} &nbsp; · &nbsp; ${rows.length} registro(s) &nbsp; · &nbsp; Modelo: ${complete?'Completo':'Padrão manual'}</p><table><thead><tr>${cols.map(s=>`<th>${html(s)}</th>`).join('')}</tr></thead><tbody>${dataRows.map(row=>`<tr>${row.map(v=>`<td>${html(v)}</td>`).join('')}</tr>`).join('')}</tbody></table><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));<\/script></body></html>`);win.document.close()}
function waMessage(){const scope=$('waScope').value;const rows=scope==='selecionados'?items.filter(i=>selected.has(i.pc)):scope==='recebidos'?items.filter(arrived):items;let msg=`📦 *RECEBIMENTO DE MATERIAIS*\n📅 ${new Date().toLocaleString('pt-BR')}\n━━━━━━━━━━━━━━━━\n`;msg+=`• Pedidos: ${rows.length}\n• Recebidos: ${rows.filter(i=>i.chegou==='SIM').length}\n• Parciais: ${rows.filter(i=>i.chegou==='PARCIAL').length}\n• Sem status: ${rows.filter(i=>!i.chegou).length}\n`;if(scope!=='resumo')rows.forEach(i=>{msg+=`\n*PC ${i.pc}* — ${STATUS_NAMES[i.chegou]||i.chegou}\n🏢 ${i.fornecedor}\n`;if(i.numeroNF)msg+=`🧾 NF ${i.numeroNF}\n`;if(i.solicitante)msg+=`👤 ${i.solicitante}\n`;if(i.data)msg+=`📅 ${fmtDate(i.data)}\n`;if(i.recebimento_iniciado_em)msg+=`▶ Início: ${fmtDatetime(i.recebimento_iniciado_em)}\n`;if(i.recebimento_finalizado_em)msg+=`✓ Finalização: ${fmtDatetime(i.recebimento_finalizado_em)}\n`;if(i.origem_entrega)msg+=`🚚 ${origemEntregaLabel(i.origem_entrega)}\n`;if(missingItems(i).length)msg+=`⚠️ Faltam: ${missingItems(i).map(x=>x.descricao+' ('+x.quantidade_pendente+' '+x.unidade+')').join('; ')}\n`;if(i.obs)msg+=`💬 ${short(i.obs,150)}\n`});return msg+'\n_Relatório Recebimento Drilling_'}
function openWA(scope='resumo'){$('waScope').value=scope;refreshWA();$('waModal').classList.add('on')}
function closeWA(){$('waModal').classList.remove('on');if(typeof refreshIfDeferred==='function')refreshIfDeferred()}
function refreshWA(){$('waPre').textContent=waMessage()}
async function copyWA(){try{await navigator.clipboard.writeText(waMessage());toast('Relatório copiado')}catch{toast('Falha ao copiar',true)}}
function sendWA(){const message=waMessage();if(message.length>15000){toast('Relatório longo; use o botão Copiar e envie em partes',true);return}window.open('https://wa.me/?text='+encodeURIComponent(message),'_blank','noopener')}
window.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDrawer();closeWA()}});
window.addEventListener('DOMContentLoaded',()=>{$('todayText').textContent=new Date().toLocaleDateString('pt-BR',{weekday:'long',day:'2-digit',month:'short'});$('fileCam').addEventListener('change',onFile);$('fileGal').addEventListener('change',onFile);if(window.matchMedia('(max-width:790px)').matches)$('pageSize').value='10';rdWhenLogged(load)});
// Espera a verificação de sessão: com login obrigatório e ninguém logado, não chama a API
// (evita 403 e o aviso falso de "falha de conexão" atrás da tela de login).
async function rdWhenLogged(fn){try{if(window.rdAuthReady)await window.rdAuthReady}catch{}if(typeof RDA!=='undefined'&&RDA.exigir&&!RDA.logado)return;return fn()}

// Caminho alternativo para navegadores que não permitem navegar na aba via Service Worker.
if('serviceWorker' in navigator){
 navigator.serviceWorker.addEventListener('message',async event=>{
  if(event.data?.type!=='OPEN_RECEIPT_PC')return;
  const pc=Number(event.data.pc);
  if(!Number.isSafeInteger(pc)||pc<=0)return;
  if(!items.length)await load();
  const item=items.find(x=>x.pc===pc);
  if(item){goPage('pedidos');openDrawer(pc)}
  else{goPage('pedidos');const q=document.getElementById('q');if(q){q.value=String(pc);filtersChanged()}}
 });
}

/* v3.9 — devolução de material comprado ao fornecedor (alimenta os Indicadores) */
function devolucoesGroup(i){const lista=(i.devolucoes||[]).map(d=>`<div class="rd-user-line" style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #25334b;font-size:12px"><span><b>${fmtDate(d.data)}</b> · <b style="color:#f5cb87">${Number(d.valor||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}</b> · ${html(d.motivo)}${d.itens?` · <span class="muted">${html(d.itens)}</span>`:''}<br><small class="muted">registrado por ${html(d.usuario||'')}</small></span><button type="button" class="button ghost small" onclick="removerDevolucao(${i.pc},'${html(d.id)}')">Remover</button></div>`).join('');
return `<div class="drawer-group"><h3>Devolução ao fornecedor</h3><p class="inline-hint" style="margin-bottom:10px">Registre material comprado que voltou ao fornecedor (defeito, erro de pedido, divergência). Não altera o recebimento.</p><div class="form-grid"><div class="form-line"><label>Data</label><input type="date" id="devData_${i.pc}" value="${new Date().toISOString().slice(0,10)}"></div><div class="form-line"><label>Valor devolvido (R$)</label><input id="devValor_${i.pc}" inputmode="decimal" placeholder="0,00"></div><div class="form-line full"><label>Motivo</label><select id="devMotivo_${i.pc}"><option>Defeito / avaria</option><option>Erro de pedido</option><option>Divergência de especificação</option><option>Quantidade a mais</option><option>Outro</option></select></div><div class="form-line full"><label>Itens devolvidos (opcional)</label><input id="devItens_${i.pc}" placeholder="Ex.: 2 filtros hidráulicos"></div></div><button type="button" class="button primary small" onclick="registrarDevolucao(${i.pc})">Registrar devolução</button>${lista?`<div style="margin-top:12px">${lista}</div>`:''}</div>`}
async function registrarDevolucao(pc){const v=String($('devValor_'+pc).value||'').trim();const valor=Number(v.includes(',')?v.replace(/\./g,'').replace(',','.'):v);if(!Number.isFinite(valor)||valor<0){toast('Informe um valor válido.',true);return}
try{const r=await api('pc_devolucao',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,data:$('devData_'+pc).value,valor,motivo:$('devMotivo_'+pc).value,itens:$('devItens_'+pc).value})});const k=items.findIndex(x=>x.pc===pc);if(k>=0)items[k]=normalized(r.item);openDrawer(pc);toast('Devolução registrada.')}catch(e){toast(e.message,true)}}
async function removerDevolucao(pc,id){if(!confirm('Remover esta devolução?'))return;try{const r=await api('pc_devolucao',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,remover:id})});const k=items.findIndex(x=>x.pc===pc);if(k>=0)items[k]=normalized(r.item);openDrawer(pc);toast('Devolução removida.')}catch(e){toast(e.message,true)}}

/* v3.28.0: destino do pedido (obra ou setor) — identificado do PDF ou definido à mão */
let RD_OBRAS=null;
async function rdCarregarObras(){if(RD_OBRAS)return RD_OBRAS;try{RD_OBRAS=(await api('obras_lista')).obras||[];}catch(e){RD_OBRAS=[];}return RD_OBRAS;}
function destinoEditor(i){
  setTimeout(()=>destinoMontar(i.pc),0);
  const info=i.destino_origem==='auto'?'<small class="muted">Identificado automaticamente pelo texto do pedido — corrija se precisar.</small>':i.destino_origem==='manual'?'<small class="muted">Definido manualmente.</small>':'<small class="muted">Não identificado no pedido. Escolha abaixo.</small>';
  const obs=i.observacoes_pc||i.finalidade_pc?`<details style="margin:6px 0"><summary style="cursor:pointer;font-size:12px">Ver texto do pedido usado</summary><small>${html(i.finalidade_pc?('Finalidade: '+i.finalidade_pc+' · '):'')}${html(i.observacoes_pc||'')}</small></details>`:'';
  return `<div class="destino-ed" id="destinoEd${i.pc}"><div style="display:grid;grid-template-columns:1fr 1.4fr auto;gap:8px;align-items:end">
   <label class="form-line"><span>Setor</span><select data-d="setor"><option value="">— sem destino —</option>${Object.entries(RD_SETOR_NOMES).map(([k,n])=>`<option value="${k}" ${i.destino_setor===k?'selected':''}>${n}</option>`).join('')}</select></label>
   <label class="form-line" data-d="obraBox" ${i.destino_setor==='OBRA'?'':'hidden'}><span>Qual obra?</span><select data-d="obra"><option value="">Carregando…</option></select></label>
   <button class="button primary small" type="button" data-d="salvar">Salvar</button></div>${info}${obs}</div>`;
}
async function destinoMontar(pc){
  const box=document.getElementById('destinoEd'+pc);if(!box)return;const it=items.find(x=>x.pc===pc)||{};
  const obras=await rdCarregarObras();const so=box.querySelector('[data-d=obra]'),ss=box.querySelector('[data-d=setor]');
  so.innerHTML='<option value="">Selecione a obra…</option>'+obras.filter(o=>o.id!=='deposito').map(o=>`<option value="${html(o.id)}" ${it.obra_id===o.id?'selected':''}>${html(o.name)}</option>`).join('');
  ss.onchange=()=>{box.querySelector('[data-d=obraBox]').hidden=ss.value!=='OBRA';};
  box.querySelector('[data-d=salvar]').onclick=async()=>{
    if(ss.value==='OBRA'&&!so.value)return toast('Escolha qual obra.',true);
    try{const r=await api('pc_destino',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pc,setor:ss.value,obra_id:so.value})});
      const idx=items.findIndex(x=>x.pc===pc);if(idx>=0)items[idx]=normalized(r.item);populateObra();renderOrders();toast('Destino do pedido salvo.');openDrawer(pc);}
    catch(e){toast(e.message,true);}
  };
}
