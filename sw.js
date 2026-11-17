/* Recebimento Drilling v2.9.8 — PWA offline-shell + Web Push, cache corrigido. */
'use strict';
const CACHE='recebimento-drilling-shell-v3.43.0.20261116r1';
const SHELL=['/','/index.html','/manifest.webmanifest',
 '/assets/app.css?v=3.43.0.20261116r1','/assets/app.js?v=3.43.0.20261116r1',
 '/assets/nf.js?v=3.43.0.20261116r1',
 '/assets/push.js?v=3.43.0.20261116r1','/assets/wizard.js?v=3.43.0.20261116r1','/assets/pwa.js?v=3.43.0.20261116r1','/assets/whatsapp-import.js?v=3.43.0.20261116r1','/assets/datas.js?v=3.43.0.20261116r1','/assets/upload.js?v=3.43.0.20261116r1','/assets/admin-backup.js?v=3.43.0.20261116r1',
 '/assets/estoque.css?v=3.43.0.20261116r1','/assets/estoque.js?v=3.43.0.20261116r1','/assets/importados.css?v=3.43.0.20261116r1','/assets/importados.js?v=3.43.0.20261116r1','/assets/assinatura.js?v=3.43.0.20261116r1','/assets/ferramentas.js?v=3.43.0.20261116r1','/assets/locacoes.js?v=3.43.0.20261116r1','/assets/protocolos.js?v=3.43.0.20261116r1','/assets/terceiros.js?v=3.43.0.20261116r1','/assets/terceiros.css?v=3.43.0.20261116r1','/assets/locacoes.css?v=3.43.0.20261116r1','/assets/protocolos.css?v=3.43.0.20261116r1',
 '/assets/notify.css?v=3.43.0.20261116r1','/assets/notify.js?v=3.43.0.20261116r1',
 '/assets/backup.css?v=3.43.0.20261116r1','/assets/backup.js?v=3.43.0.20261116r1',
 '/assets/premium.css?v=3.43.0.20261116r1','/assets/coletas.css?v=3.43.0.20261116r1','/assets/coletas.js?v=3.43.0.20261116r1','/assets/etiquetas.css?v=3.43.0.20261116r1','/assets/etiquetas.js?v=3.43.0.20261116r1','/assets/acesso.css?v=3.43.0.20261116r1','/assets/acesso.js?v=3.43.0.20261116r1','/assets/novo-pedido.js?v=3.43.0.20261116r1','/assets/exportacao.js?v=3.43.0.20261116r1','/assets/alertas.js?v=3.43.0.20261116r1','/assets/obras.js?v=3.43.0.20261116r1','/assets/obras.css?v=3.43.0.20261116r1','/assets/avisos.js?v=3.43.0.20261116r1','/assets/avisos.css?v=3.43.0.20261116r1','/assets/exportacao.css?v=3.43.0.20261116r1','/assets/xlsx-lib.js','/assets/icons/logo-drilling.png',
 '/assets/vendor/leaflet/leaflet.js?v=1.9.4','/assets/vendor/leaflet/leaflet.css?v=1.9.4',
 '/assets/icons/icon-192.png','/assets/icons/icon-512.png','/assets/icons/icon-maskable-512.png','/assets/icons/notification.svg'];
self.addEventListener('install',event=>event.waitUntil((async()=>{
  const cache=await caches.open(CACHE);
  await cache.addAll(SHELL);
  await self.skipWaiting();
})()));
self.addEventListener('activate',event=>event.waitUntil((async()=>{
  const names=await caches.keys();
  await Promise.all(names.filter(n=>n.startsWith('recebimento-drilling-shell-')&&n!==CACHE).map(n=>caches.delete(n)));
  await self.clients.claim();
})()));
function isShellAsset(url){return url.pathname==='/'||url.pathname==='/index.html'||
 /^\/assets\/(app\.css|app\.js|nf\.js|notifications\.js|push\.js|wizard\.js|pwa\.js|whatsapp-import\.js|admin-backup\.js|estoque\.css|estoque\.js|notify\.css|notify\.js|backup\.css|backup\.js|premium\.css|coletas\.css|coletas\.js|etiquetas\.css|etiquetas\.js|acesso\.css|acesso\.js|novo-pedido\.js|exportacao\.js|alertas\.js|avisos\.js|avisos\.css|obras\.js|obras\.css|exportacao\.css|xlsx-lib\.js|vendor\/leaflet\/leaflet\.js|vendor\/leaflet\/leaflet\.css)$/.test(url.pathname)||
 url.pathname==='/manifest.webmanifest'||url.pathname.startsWith('/assets/icons/');}
self.addEventListener('fetch',event=>{
 const req=event.request, url=new URL(req.url);
 if(req.method!=='GET'||url.origin!==self.location.origin)return;
 // API, documentos, fotos e qualquer dado operacional NÃO entram no cache.
 if(!isShellAsset(url))return;
 if(req.mode==='navigate'){
  event.respondWith((async()=>{
   try {
    // Evita reutilizar o HTML antigo do PWA e do cache HTTP.
    const response=await fetch(req,{cache:'no-store'});
    if(response.ok){const cache=await caches.open(CACHE);await cache.put('/index.html',response.clone());}
    return response;
   }catch{
    return (await caches.match('/index.html'))||Response.error();
   }
  })());return;
 }
 event.respondWith((async()=>{
  // Recursos de interface: buscar no servidor PRIMEIRO; offline usa cache local.
  try {
   const response=await fetch(req,{cache:'no-store'});
   if(response.ok){const cache=await caches.open(CACHE);await cache.put(req,response.clone());}
   return response;
  }catch{
   return (await caches.match(req))||Response.error();
  }
 })());
});
self.addEventListener('push', event => {
 event.waitUntil((async()=>{
  let data={};try{data=event.data?event.data.json():{}}catch{}
  const pc=Number(data.pc);
  const validPC=Number.isSafeInteger(pc)&&pc>0;
  const target=validPC?'/?pc='+encodeURIComponent(pc):'/';
  await self.registration.showNotification(data.title||'Recebimento Drilling',{
   body:data.body||'Recebimento conferido. Toque para visualizar o pedido.',
   icon:'/assets/icons/icon-192.png',badge:'/assets/icons/notification.svg',
   tag:data.tag||('recebimento-'+(validPC?pc:'geral')),
   renotify:true,data:{url:target},requireInteraction:false
  });
 })());
});
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil((async()=>{
  const target=new URL(event.notification.data?.url||'/',self.location.origin);
  if(target.origin!==self.location.origin)return;
  const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  // Reaproveita a aplicação já aberta, evitando abas duplicadas.
  for(const tab of tabs){
   if(new URL(tab.url).origin!==target.origin)continue;
   try{
    if(typeof tab.navigate==='function'){
     const moved=await tab.navigate(target.href);
     if(moved){await moved.focus();return}
    }
    await tab.focus();
    tab.postMessage({type:'OPEN_RECEIPT_PC',pc:target.searchParams.get('pc')});
    return;
   }catch{ /* tenta outra aba ou abre uma nova abaixo */ }
  }
  await self.clients.openWindow(target.href);
 })());
});
