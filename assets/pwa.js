/* Recebimento Drilling — PWA (v3.55.0): instalação, status de rede, atalhos (?page=), atualização controlada, armazenamento persistente. */
'use strict';
let installPromptEvent=null, swReg=null;
function updateNetworkState(){
 const offline=!navigator.onLine;
 const banner=document.getElementById('offlineBanner');
 if(banner)banner.hidden=!offline;
 const indicator=document.getElementById('networkStatus');
 if(indicator){indicator.textContent=offline?'● Sem conexão':'● Online';indicator.className=offline?'network-state offline':'network-state';}
}
const pwaInstalado=()=>window.matchMedia('(display-mode: standalone)').matches||window.matchMedia('(display-mode: minimal-ui)').matches||navigator.standalone===true;
function installHelp(){
 const ios=/iP(hone|ad|od)/.test(navigator.userAgent);
 if(pwaInstalado()){if(typeof toast==='function')toast('O Recebimento Drilling já está instalado.');return;}
 const msg=ios?'No iPhone, abra no Safari, toque em Compartilhar e depois em Adicionar à Tela de Início.':
 'Abra o menu do Chrome ou Edge e selecione Instalar Recebimento Drilling ou Instalar aplicativo. Use HTTPS.';
 if(typeof toast==='function')toast(msg); else alert(msg);
}
async function installDrilling(){
 if(installPromptEvent){const prompt=installPromptEvent;installPromptEvent=null;prompt.prompt();await prompt.userChoice;
 }else{installHelp()}
}
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installPromptEvent=e;const b=document.getElementById('installPwa');if(b)b.hidden=false;});
window.addEventListener('appinstalled',()=>{installPromptEvent=null;const b=document.getElementById('installPwa');if(b)b.hidden=true;if(typeof toast==='function')toast('Recebimento Drilling instalado neste dispositivo.');pwaPersistir();});

/* Aviso de nova versão: o usuário decide quando recarregar (evita perder uma contagem em andamento). */
function pwaAvisoAtualizacao(worker){
 if(document.getElementById('pwaUpdate'))return;
 const d=document.createElement('div');d.id='pwaUpdate';d.setAttribute('role','alert');
 d.style.cssText='position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:99999;background:#12274a;color:#fff;border:1px solid #2f7df6;border-radius:12px;padding:10px 14px;display:flex;gap:12px;align-items:center;box-shadow:0 8px 28px #0006;font:14px system-ui,Arial,sans-serif;max-width:calc(100vw - 24px)';
 d.innerHTML='<span>Nova versão do sistema disponível.</span><button type="button" style="background:#2f7df6;color:#fff;border:0;border-radius:8px;padding:7px 12px;cursor:pointer">Atualizar</button><button type="button" aria-label="Depois" style="background:none;color:#cfd8ea;border:0;cursor:pointer;font-size:18px">×</button>';
 const [,ok,x]=[...d.children];
 ok.onclick=()=>{try{worker.postMessage({tipo:'SKIP_WAITING'})}catch{location.reload()}};
 x.onclick=()=>d.remove();
 document.body.appendChild(d);
}
async function pwaPersistir(){try{if(navigator.storage&&navigator.storage.persist&&!(await navigator.storage.persisted()))await navigator.storage.persist();}catch{}}
function pwaRegistrar(){
 if(!('serviceWorker' in navigator)||!window.isSecureContext)return;
 const tinhaControle=!!navigator.serviceWorker.controller;let recarregando=false;
 navigator.serviceWorker.addEventListener('controllerchange',()=>{if(!tinhaControle||recarregando)return;recarregando=true;location.reload();});
 navigator.serviceWorker.register('sw.js',{scope:'./',updateViaCache:'none'}).then(reg=>{
  swReg=reg;
  const olhar=w=>w&&w.addEventListener('statechange',()=>{if(w.state==='installed'&&navigator.serviceWorker.controller)pwaAvisoAtualizacao(w);});
  if(reg.waiting&&navigator.serviceWorker.controller)pwaAvisoAtualizacao(reg.waiting);
  reg.addEventListener('updatefound',()=>olhar(reg.installing));
  setInterval(()=>reg.update().catch(()=>{}),30*60*1000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)reg.update().catch(()=>{})});
 }).catch(e=>console.warn('PWA Service Worker:',e));
}
/* Atalhos da tela inicial (manifest "shortcuts") e links diretos: ?page=contagens */
function pwaAbrirPaginaDoLink(){
 const p=new URLSearchParams(location.search).get('page');if(!p||!/^[a-z]+$/.test(p))return;
 let t=0;const tenta=()=>{const el=document.getElementById('page-'+p);
  if(el&&typeof goPage==='function'){(async()=>{try{if(window.rdAuthReady)await window.rdAuthReady}catch{}
    if(typeof RDA!=='undefined'&&RDA.exigir&&!RDA.logado){try{sessionStorage.setItem('rd_page_pendente',p)}catch{}return;}   // vai para a página depois do login
    try{goPage(p)}catch{}history.replaceState(null,'',location.pathname);})();}
  else if(++t<40)setTimeout(tenta,250);};
 setTimeout(tenta,300);
}
window.addEventListener('DOMContentLoaded',()=>{
 updateNetworkState();
 const b=document.getElementById('installPwa');if(b)b.addEventListener('click',installDrilling);
 if(pwaInstalado()){document.documentElement.classList.add('pwa-instalado');pwaPersistir();}
 pwaRegistrar();pwaAbrirPaginaDoLink();
 try{const pend=sessionStorage.getItem('rd_page_pendente');if(pend&&!new URLSearchParams(location.search).get('page')){sessionStorage.removeItem('rd_page_pendente');history.replaceState(null,'','?page='+encodeURIComponent(pend));pwaAbrirPaginaDoLink();}}catch{}
});
window.addEventListener('online',()=>{updateNetworkState();if(typeof rdWhenLogged==='function'&&typeof load==='function')rdWhenLogged(load);else if(typeof load==='function')load();});
window.addEventListener('offline',updateNetworkState);
