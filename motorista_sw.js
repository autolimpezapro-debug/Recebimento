/* Rota do Motorista — service worker (v3.40.0): abre o app mesmo sem sinal. Dados da API nunca entram no cache. */
'use strict';
const CACHE = 'motorista-shell-v1';
const SHELL = ['/motorista.html', '/assets/icons/icon-192.png', '/assets/icons/icon-512.png', '/assets/icons/apple-touch-icon.png', '/favicon.ico'];
self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  if (u.pathname === '/motorista.html') {   // a página: rede primeiro (pega versão nova), cache se estiver sem sinal
    e.respondWith(fetch(e.request).then(r => { const cp = r.clone(); caches.open(CACHE).then(c => c.put('/motorista.html', cp)); return r; }).catch(() => caches.match('/motorista.html')));
  }
});
