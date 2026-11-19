/* Permissão opcional, por dispositivo, sem exigir conta Google ou FCM direto. */
'use strict';
window.recebimentoPushActive=false;
const pushStorageKey='recebimento-drilling-push-device';
const legacyPushStorageKey='recebimento-pro-push-device';
try { if(localStorage.getItem(legacyPushStorageKey) && !localStorage.getItem(pushStorageKey)) localStorage.setItem(pushStorageKey,localStorage.getItem(legacyPushStorageKey)); localStorage.removeItem(legacyPushStorageKey); } catch {}
function pushBase64ToArray(text){const pad='='.repeat((4-text.length%4)%4);const bin=atob((text+pad).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(bin,c=>c.charCodeAt(0))}
function pushCompatible(){return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext}
function pushMessage(text,problem=false){const el=document.getElementById('pushStatus');if(el)el.textContent=text;if(typeof toast==='function')toast(text,problem)}
function renderPushButton(){
 const button=document.getElementById('enableBrowserAlerts'),disable=document.getElementById('disableBrowserAlerts');
 if(button)button.textContent=window.recebimentoPushActive?'✓ Alertas ativados':'Ativar Web Push no navegador';
 if(disable)disable.hidden=!window.recebimentoPushActive;
 const step=document.getElementById('tutorialPushStatus');
 if(step)step.textContent=window.recebimentoPushActive?'✓ Este dispositivo está inscrito para notificações.':
  !pushCompatible()?'Este navegador não oferece Web Push seguro. Use Chrome/Edge/Firefox atualizado com HTTPS.':
  Notification.permission==='denied'?'Notificações bloqueadas no navegador. Ajuste a permissão nas configurações do site.':'Opcional: autorize as notificações neste dispositivo.';
}
async function pushRegistration(){
 const reg=await navigator.serviceWorker.register('sw.js',{scope:'./',updateViaCache:'none'});
 await navigator.serviceWorker.ready;
 return reg;
}
async function enablePushAlerts(){
 if(!pushCompatible()){pushMessage('Web Push exige HTTPS e navegador compatível.',true);renderPushButton();return false}
 try{
   const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
   if(permission!=='granted'){pushMessage('Permissão não concedida: alertas apenas dentro do sistema.',true);renderPushButton();return false}
   const confResponse=await fetch('api.php?action=push_public_key',{cache:'no-store',credentials:'same-origin'});
   const conf=await confResponse.json();if(!confResponse.ok||!conf.ok||!conf.publicKey)throw Error(conf.error||'Servidor Web Push indisponível');
   const registration=await pushRegistration();
   let sub=await registration.pushManager.getSubscription();
   if(!sub)sub=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:pushBase64ToArray(conf.publicKey)});
   const saved=await fetch('api.php?action=push_subscribe',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(sub)});
   const answer=await saved.json();if(!saved.ok||!answer.ok)throw Error(answer.error||'Não foi possível inscrever o navegador');
   window.recebimentoPushActive=true;
   localStorage.setItem(pushStorageKey,'1');renderPushButton();pushMessage('Notificações de recebimentos ativadas neste dispositivo.');return true;
 }catch(e){window.recebimentoPushActive=false;renderPushButton();pushMessage('Erro ao ativar Push: '+e.message,true);return false}
}
async function disablePushAlerts(){
 if(!('serviceWorker' in navigator))return;
 try{
   const reg=await navigator.serviceWorker.getRegistration('sw.js');
   const sub=reg?await reg.pushManager.getSubscription():null;
   if(sub){await fetch('api.php?action=push_unsubscribe',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({endpoint:sub.endpoint})});await sub.unsubscribe()}
   localStorage.removeItem(pushStorageKey);window.recebimentoPushActive=false;renderPushButton();pushMessage('Alertas desativados neste dispositivo.');
 }catch(e){pushMessage('Falha ao desativar: '+e.message,true)}
}
async function restorePushSubscription(){
 if(!pushCompatible()||Notification.permission!=='granted'){renderPushButton();return}
 try{
  const reg=await navigator.serviceWorker.getRegistration('sw.js');
  const sub=reg?await reg.pushManager.getSubscription():null;
  if(sub){
   const res=await fetch('api.php?action=push_subscribe',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(sub)});
   window.recebimentoPushActive=res.ok;
  }
 }catch(e){console.warn('Web Push indisponível:',e)}
 renderPushButton();
}
window.addEventListener('DOMContentLoaded',()=>rdWhenLogged(restorePushSubscription));

/* v3.48.0 — administrador escolhe quem recebe push em cada módulo. O sino (notificação interna) não muda. */
let pushRegrasDados=null;
function pushEsc(t){return String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
async function pushRegrasAbrir(){
 const m=document.getElementById('pushRegrasModal'),b=document.getElementById('pushRegrasBody');if(!m||!b)return;
 m.classList.add('on');b.textContent='Carregando…';
 try{
  const r=await fetch('api.php?action=push_regras',{cache:'no-store',credentials:'same-origin'});const d=await r.json();
  if(!r.ok||!d.ok)throw Error(d.error||'Somente o administrador pode alterar estas regras');
  pushRegrasDados=d;pushRegrasDesenhar();
 }catch(e){b.innerHTML='<p class="inline-hint">'+pushEsc(e.message)+'</p>'}
}
function pushRegrasFechar(){const m=document.getElementById('pushRegrasModal');if(m)m.classList.remove('on')}
function pushRegrasDesenhar(){
 const d=pushRegrasDados,b=document.getElementById('pushRegrasBody');if(!d||!b)return;
 const ativos=d.usuarios.filter(u=>u.push_ativo>0).length;
 const cards=d.modulos.map(m=>`<div class="drawer-group" data-push-mod="${pushEsc(m.modulo)}"><h3 style="margin-bottom:6px">${pushEsc(m.nome)}</h3>
  <div class="photo-actions" style="margin-bottom:8px">${[['todos','Todos os inscritos'],['escolhidos','Só os escolhidos'],['ninguem','Ninguém (só o sino)']].map(([v,l])=>`<label style="display:flex;gap:6px;align-items:center;font-size:12px"><input type="radio" name="pushModo_${pushEsc(m.modulo)}" value="${v}" ${m.modo===v?'checked':''} onchange="pushRegrasModo('${pushEsc(m.modulo)}',this.value)"> ${l}</label>`).join('')}</div>
  <div class="push-users" ${m.modo==='escolhidos'?'':'hidden'} style="display:${m.modo==='escolhidos'?'grid':'none'};grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px">${d.usuarios.map(u=>`<label style="display:flex;gap:6px;align-items:center;font-size:12px"><input type="checkbox" data-user="${pushEsc(u.username)}" ${m.usuarios.some(x=>x.toLowerCase()===u.username.toLowerCase())?'checked':''}> ${pushEsc(u.nome)} ${u.push_ativo?'<small title="Tem push ativo em '+u.push_ativo+' aparelho(s)">🔔</small>':'<small title="Ainda não ativou o push" style="opacity:.55">🔕</small>'}</label>`).join('')}</div></div>`).join('');
 b.innerHTML=`<p class="inline-hint">O <strong>sino</strong> continua igual para todos. Aqui você escolhe só quem recebe o <strong>aviso no celular/PC (push)</strong> de cada módulo. 🔔 = o usuário já ativou o push; 🔕 = ainda não ativou (precisa abrir o sistema e tocar em “Ativar Web Push”). ${ativos} de ${d.usuarios.length} usuário(s) com push ativo${d.sem_dono?` · ${d.sem_dono} aparelho(s) antigo(s) sem dono (passam a ter dono quando o usuário abre o sistema)`:''}.</p>${cards}`;
}
function pushRegrasModo(mod,modo){
 const box=document.querySelector(`[data-push-mod="${mod}"] .push-users`);if(box){box.hidden=modo!=='escolhidos';box.style.display=modo==='escolhidos'?'grid':'none'}
}
async function pushRegrasSalvar(){
 const btn=document.getElementById('pushRegrasSalvar'),regras={};
 document.querySelectorAll('[data-push-mod]').forEach(card=>{
  const mod=card.dataset.pushMod;
  regras[mod]={modo:card.querySelector('input[type=radio]:checked')?.value||'ninguem',usuarios:[...card.querySelectorAll('input[data-user]:checked')].map(x=>x.dataset.user)};
 });
 btn.disabled=true;
 try{
  const r=await fetch('api.php?action=push_regras_salvar',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({regras})});
  const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Não foi possível salvar');
  pushRegrasDados=d;pushRegrasDesenhar();if(typeof toast==='function')toast('Regras de push salvas.');
 }catch(e){if(typeof toast==='function')toast('Erro: '+e.message,true)}
 btn.disabled=false;
}
