/* Recebimento Drilling PWA: install, offline awareness, update handling */
'use strict';
let installPromptEvent=null;
function updateNetworkState(){
 const offline=!navigator.onLine;
 const banner=document.getElementById('offlineBanner');
 if(banner)banner.hidden=!offline;
 const indicator=document.getElementById('networkStatus');
 if(indicator){indicator.textContent=offline?'● Sem conexão':'● Online';indicator.className=offline?'network-state offline':'network-state';}
}
function installHelp(){
 const ios=/iP(hone|ad|od)/.test(navigator.userAgent);
 const installed=window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
 if(installed){if(typeof toast==='function')toast('O Recebimento Drilling já está instalado.');return;}
 const msg=ios?'No iPhone, abra no Safari, toque em Compartilhar e depois em Adicionar à Tela de Início.':
 'Abra o menu do Chrome ou Edge e selecione Instalar Recebimento Drilling ou Instalar aplicativo. Use HTTPS.';
 if(typeof toast==='function')toast(msg); else alert(msg);
}
async function installDrilling(){
 if(installPromptEvent){const prompt=installPromptEvent;installPromptEvent=null;prompt.prompt();await prompt.userChoice;
 }else{installHelp()}
}
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installPromptEvent=e;const b=document.getElementById('installPwa');if(b)b.hidden=false;});
window.addEventListener('appinstalled',()=>{installPromptEvent=null;const b=document.getElementById('installPwa');if(b)b.hidden=true;if(typeof toast==='function')toast('Recebimento Drilling instalado neste dispositivo.');});
window.addEventListener('DOMContentLoaded',()=>{
 updateNetworkState();
 const b=document.getElementById('installPwa');if(b)b.addEventListener('click',installDrilling);
 if('serviceWorker' in navigator && window.isSecureContext){
   navigator.serviceWorker.register('/sw.js?v=2.9.8.20261028r1',{scope:'/',updateViaCache:'none'}).catch(e=>console.warn('PWA Service Worker:',e));
 }
});
window.addEventListener('online',()=>{updateNetworkState();if(typeof load==='function')load();});
window.addEventListener('offline',updateNetworkState);
