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
 const reg=await navigator.serviceWorker.register('sw.js',{scope:'/',updateViaCache:'none'});
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
