/* Recebimento Drilling — PWA completo (v3.61.4): shell offline, página offline, atualização controlada, Background Sync (aviso à página) e Web Push.
   Dados operacionais (API, fotos, PDFs) NUNCA entram no cache: só a interface. Funciona na raiz do domínio ou em subpasta (usa o escopo do SW). */
'use strict';
const CACHE='recebimento-drilling-shell-v3.61.4.20261125r2';
const SCOPE=new URL(self.registration.scope);
const BASE=SCOPE.pathname;                       // "/" ou "/subpasta/"
const abs=p=>new URL(p,SCOPE).href;
const SHELL=['./','index.html','offline.html','manifest.webmanifest','assets/acesso.css','assets/acesso.js','assets/admin-backup.js','assets/alertas.js','assets/app.css','assets/app.js','assets/assinatura.js','assets/avisos.css','assets/avisos.js','assets/backup.css','assets/backup.js','assets/chegadas.js','assets/coletas.css','assets/coletas.js','assets/contagens.js','assets/rascunho.js','assets/datas.js','assets/espelho.js','assets/estoque.css','assets/estoque.js','assets/etiquetas.css','assets/etiquetas.js','assets/exportacao.css','assets/exportacao.js','assets/ferramentas.js','assets/icons/apple-touch-icon.png','assets/icons/icon-192.png','assets/icons/icon-512.png','assets/icons/icon-maskable-192.png','assets/icons/icon-maskable-512.png','assets/icons/logo-drilling.png','assets/icons/notification.svg','assets/importados.css','assets/importados.js','assets/locacoes.css','assets/locacoes.js','assets/mobile-nav.css','assets/mobile-nav.js','assets/nf.js','assets/notify.css','assets/notify.js','assets/novo-pedido.js','assets/obras.css','assets/obras.js','assets/pendencias.css','assets/pendencias.js','assets/premium.css','assets/protocolos.css','assets/protocolos.js','assets/push.js','assets/pwa.js','assets/relger.js','assets/terceiros.css','assets/terceiros.js','assets/upload.js','assets/vendor/leaflet/leaflet.css','assets/vendor/leaflet/leaflet.js','assets/whatsapp-import.js','assets/wizard.js','assets/xlsx-lib.js'];
const STATIC_RE=/\.(?:js|css|png|svg|webp|jpg|ico|woff2?)$/i;
const rel=url=>url.pathname.startsWith(BASE)?url.pathname.slice(BASE.length):null;
function isShellAsset(url){const r=rel(url);if(r===null)return false;
 if(r===''||r==='index.html'||r==='offline.html'||r==='manifest.webmanifest')return true;
 return r.startsWith('assets/')&&!r.startsWith('assets/uploads/')&&STATIC_RE.test(r);}
const keyOf=url=>new Request(url.origin+url.pathname);   // chave sem ?v=..., offline casa qualquer versão
self.addEventListener('install',event=>event.waitUntil((async()=>{
  const cache=await caches.open(CACHE);
  const core=await Promise.allSettled(SHELL.map(async p=>{const u=new URL(p,SCOPE);const r=await fetch(u,{cache:'no-store'});if(!r.ok)throw new Error(u.pathname+' '+r.status);
    await cache.put(p==='./'?new Request(abs('./')):keyOf(u),r);}));
  // index e offline são obrigatórios; um asset opcional ausente não derruba a instalação.
  if(core[1].status==='rejected'||core[2].status==='rejected')throw new Error('Shell essencial indisponível');
  if(!self.registration.active)await self.skipWaiting();   // 1ª instalação ativa já; atualização espera o aceite do usuário (mensagem SKIP_WAITING)
})()));
self.addEventListener('activate',event=>event.waitUntil((async()=>{
  const names=await caches.keys();
  await Promise.all(names.filter(n=>n.startsWith('recebimento-drilling-shell-')&&n!==CACHE).map(n=>caches.delete(n)));
  if(self.registration.navigationPreload)try{await self.registration.navigationPreload.disable()}catch{}
  await self.clients.claim();
})()));
function comTimeout(req,ms){return Promise.race([fetch(req,{cache:'no-store'}),new Promise((_,rej)=>setTimeout(()=>rej(new Error('timeout')),ms))]);}
self.addEventListener('fetch',event=>{
 const req=event.request,url=new URL(req.url);
 if(req.method!=='GET'||url.origin!==self.location.origin)return;
 if(req.mode==='navigate'){
  const r=rel(url);
  if(r===null)return;
  const ehApp=r===''||r==='index.html';
  event.respondWith((async()=>{
   try{
    const resp=await (ehApp?comTimeout(req,6000):fetch(req));
    if(ehApp&&resp.ok){const c=await caches.open(CACHE);await c.put(new Request(abs('index.html')),resp.clone());}
    return resp;
   }catch{
    if(ehApp){const c=await caches.match(abs('index.html'))||await caches.match(abs('./'));if(c)return c;}
    return (await caches.match(abs('offline.html')))||Response.error();
   }
  })());return;
 }
 if(!isShellAsset(url))return;              // API, documentos, fotos: direto na rede, sem cache
 event.respondWith((async()=>{
  const key=keyOf(url);
  try{
   const resp=await comTimeout(req,6000);
   if(resp.ok){const c=await caches.open(CACHE);await c.put(key,resp.clone());}
   return resp;
  }catch{
   return (await caches.match(key))||Response.error();
  }
 })());
});
/* Atualização controlada: a página pede para ativar a nova versão quando o usuário aceita. */
self.addEventListener('message',event=>{if(event.data&&event.data.tipo==='SKIP_WAITING')self.skipWaiting();});
/* Background Sync: avisa uma aba aberta para enviar contagens offline guardadas (o envio usa a sessão da página). */
self.addEventListener('sync',event=>{
 if(event.tag==='rd-flush-contagens')event.waitUntil((async()=>{
  const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  if(!tabs.length)throw new Error('sem aba aberta');   // faz o navegador tentar de novo mais tarde
  tabs.forEach(t=>t.postMessage({tipo:'flush-contagens'}));
 })());
});
self.addEventListener('push',event=>{
 event.waitUntil((async()=>{
  let data={};try{data=event.data?event.data.json():{}}catch{}
  const pc=Number(data.pc);
  const validPC=Number.isSafeInteger(pc)&&pc>0;
  const sentUrl=(typeof data.url==='string'&&data.url[0]==='/'&&data.url[1]!=='/')?data.url:'';
  const target=validPC?abs('./?pc='+encodeURIComponent(pc)):(sentUrl?new URL(sentUrl,self.location.origin).href:abs('./'));
  await self.registration.showNotification(data.title||'Recebimento Drilling',{
   body:data.body||(validPC?'Recebimento conferido. Toque para visualizar o pedido.':'Toque para abrir o sistema.'),
   icon:abs('assets/icons/icon-192.png'),badge:abs('assets/icons/notification.svg'),
   tag:data.tag||('recebimento-'+(validPC?pc:'geral')),
   renotify:true,data:{url:target},requireInteraction:false
  });
 })());
});
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil((async()=>{
  const target=new URL(event.notification.data?.url||abs('./'),self.location.origin);
  if(target.origin!==self.location.origin)return;
  const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
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
