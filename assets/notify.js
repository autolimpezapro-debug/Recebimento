/* Recebimento Drilling — Sistema de Notificações v3.1.0
 * Toda conferência finalizada chega a TODOS os operadores logados, em qualquer tela:
 * sino (contador), banner no topo, som, notificação do navegador e Web Push.
 * Cada navegador tem um device_id próprio; a entrega é individual e não se perde.
 */
'use strict';

const NT = {
  deviceId: '',
  cursor: null,
  itens: [],
  naoLidas: 0,
  online: 0,
  timer: null,
  hbTimer: null,
  vistos: new Set(),
  prefs: { som: true, navegador: true, categorias: ['recebimento', 'parcial', 'estoque', 'estoque_baixo', 'sistema'] },
  audioCtx: null,
  iniciado: false,
};

const ntEl = id => document.getElementById(id);
const ntEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ntHora = v => { if (!v) return ''; const d = new Date(v); return isNaN(d) ? '' : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); };

const NT_ICONE = { recebimento: '📦', parcial: '⚠️', estoque: '▦', estoque_baixo: '🔻', sistema: 'ⓘ' };
const NT_STORE = 'rd-notif-device-v1';

function ntDevice() {
  if (NT.deviceId) return NT.deviceId;
  let id = '';
  try { id = localStorage.getItem(NT_STORE) || ''; } catch {}
  if (!/^[A-Za-z0-9_-]{12,64}$/.test(id)) {
    const rnd = (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Math.random().toString(36).slice(2) + Date.now().toString(36));
    id = 'dev_' + rnd.slice(0, 28);
    try { localStorage.setItem(NT_STORE, id); } catch {}
  }
  NT.deviceId = id;
  return id;
}

function ntPrefsLoad() {
  try {
    const raw = localStorage.getItem(NT_STORE + '-prefs');
    if (raw) {
      const p = JSON.parse(raw);
      NT.prefs = {
        som: p.som !== false,
        navegador: p.navegador !== false,
        categorias: Array.isArray(p.categorias) && p.categorias.length ? p.categorias : NT.prefs.categorias,
      };
    }
  } catch {}
}

function ntPrefsSave() {
  try { localStorage.setItem(NT_STORE + '-prefs', JSON.stringify(NT.prefs)); } catch {}
}

async function ntApi(action, opts = {}) {
  const { query, ...init } = opts;
  let extra = '';
  if (query) {
    const qp = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== '' && v != null) qp.append(k, v); });
    extra = '&' + qp.toString();
  }
  const res = await fetch(`api.php?action=${encodeURIComponent(action)}${extra}`, {
    cache: 'no-store', credentials: 'same-origin', ...init,
    headers: { 'X-Client-Id': typeof notificationClientId !== 'undefined' ? notificationClientId : ntDevice(), ...(init.headers || {}) },
  });
  let data;
  try { data = await res.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!res.ok || !data.ok) throw new Error(data.error || `Erro ${res.status}`);
  return data;
}

function ntPost(action, body) {
  return ntApi(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
}

/* ---------------- presença ---------------- */

async function ntHeartbeat() {
  try {
    const r = await ntPost('notify_heartbeat', {
      device_id: ntDevice(),
      navegador: navigator.userAgent.slice(0, 150),
      pagina: typeof activePage !== 'undefined' ? activePage : '',
      preferencias: NT.prefs,
    });
    NT.online = r.online || 0;
    ntRenderOnline();
    return r;
  } catch { return null; }
}

/* ---------------- recebimento ---------------- */

async function ntPoll(forcar = false) {
  if (!NT.iniciado) return;
  if (document.hidden && !forcar) return;
  try {
    const query = { device_id: ntDevice() };
    if (NT.cursor !== null) query.since = String(NT.cursor);
    const r = await ntApi('notify_inbox', { query });
    NT.cursor = r.cursor;
    NT.naoLidas = r.nao_lidas || 0;
    NT.online = r.online || 0;
    if (Array.isArray(r.novas) && r.novas.length) ntChegaram(r.novas);
    ntRenderBadge();
    ntRenderOnline();
    if (forcar && (!r.novas || !r.novas.length)) ntStatus('Tudo atualizado.');
  } catch (e) {
    ntStatus('Sem conexão para atualizar avisos: ' + e.message);
  }
}

function ntChegaram(novas) {
  const relevantes = novas.filter(n => n.tipo === 'recebimento' || n.tipo === 'parcial');
  novas.forEach(n => NT.itens.unshift(n));
  NT.itens = NT.itens.slice(0, 60);
  ntRenderLista();

  // Último aviso de recebimento vira banner + som + notificação do sistema.
  const principal = relevantes[0] || novas[0];
  if (principal) {
    ntBanner(principal);
    if (NT.prefs.som) ntSom();
    ntSistema(principal);
  }
  // Atualiza a tela de pedidos sem atrapalhar quem está conferindo.
  const ocupado = typeof drawerPC !== 'undefined' && drawerPC !== null;
  const salvando = typeof pendingSaves !== 'undefined' && pendingSaves.size > 0;
  const modalAberto = document.querySelector('.modal-bg.on');
  if (!ocupado && !salvando && !modalAberto && typeof load === 'function') load();
  else if (typeof notificationNeedRefresh !== 'undefined') window.notificationNeedRefresh = true;
}

function ntBanner(n) {
  const host = ntEl('ntBanner');
  if (!host) return;
  const icone = NT_ICONE[n.tipo] || '🔔';
  host.innerHTML = `
    <button class="nt-banner-main" onclick="ntAbrir(${n.id})">
      <span class="nt-banner-icon">${icone}</span>
      <span class="nt-banner-text"><strong>${ntEsc(n.titulo)}</strong><small>${ntEsc(n.texto)}</small></span>
    </button>
    <div class="nt-banner-actions">
      ${n.pc ? `<button class="nt-banner-btn" onclick="ntAbrirPC(${n.pc},${n.id})">Abrir pedido</button>` : ''}
      <button class="nt-banner-close" aria-label="Fechar aviso" onclick="ntFecharBanner()">✕</button>
    </div>`;
  host.hidden = false;
  host.classList.add('on');
  clearTimeout(NT.bannerTimer);
  NT.bannerTimer = setTimeout(ntFecharBanner, 15000);
}

function ntFecharBanner() {
  const host = ntEl('ntBanner');
  if (!host) return;
  host.classList.remove('on');
  setTimeout(() => { host.hidden = true; }, 180);
}

function ntSom() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    NT.audioCtx = NT.audioCtx || new Ctx();
    const ctx = NT.audioCtx;
    if (ctx.state === 'suspended') ctx.resume();
    const agora = ctx.currentTime;
    // Dois toques curtos (dó e sol) — audível, sem arquivo externo.
    [[880, 0], [1174, 0.16]].forEach(([freq, at]) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, agora + at);
      gain.gain.exponentialRampToValueAtTime(0.18, agora + at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, agora + at + 0.16);
      osc.connect(gain).connect(ctx.destination);
      osc.start(agora + at);
      osc.stop(agora + at + 0.18);
    });
  } catch { /* som é opcional */ }
}

function ntSistema(n) {
  if (!NT.prefs.navegador) return;
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  if (window.recebimentoPushActive) return; // Web Push já cobre fora da aba
  try {
    const nota = new Notification(n.titulo, {
      body: n.texto, icon: '/assets/icons/icon-192.png', badge: '/assets/icons/notification.svg',
      tag: 'rd-notif-' + n.id, renotify: true,
    });
    nota.onclick = () => { window.focus(); ntAbrir(n.id); nota.close(); };
  } catch { /* navegador pode bloquear */ }
}

/* ---------------- interface ---------------- */

function ntRenderBadge() {
  const badge = ntEl('ntBadge');
  if (badge) {
    badge.textContent = NT.naoLidas > 99 ? '99+' : String(NT.naoLidas);
    badge.hidden = NT.naoLidas === 0;
  }
  const dot = ntEl('ntDot');
  if (dot) dot.hidden = NT.naoLidas === 0;
  document.title = (NT.naoLidas ? `(${NT.naoLidas}) ` : '') + (NT.baseTitle || (NT.baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '')));
}

function ntRenderOnline() {
  const el = ntEl('ntOnline');
  if (el) el.textContent = NT.online ? `${NT.online} operador(es) online` : 'Nenhum operador online agora';
}

function ntRenderLista() {
  const host = ntEl('ntLista');
  if (!host) return;
  if (!NT.itens.length) {
    host.innerHTML = '<div class="nt-empty">Nenhuma notificação ainda. Toda conferência finalizada aparecerá aqui.</div>';
    return;
  }
  host.innerHTML = NT.itens.map(n => `
    <button class="nt-item ${n.lido ? '' : 'unread'}" onclick="ntAbrir(${n.id})">
      <span class="nt-item-icon">${NT_ICONE[n.tipo] || '🔔'}</span>
      <span class="nt-item-body">
        <strong>${ntEsc(n.titulo)}</strong>
        <small>${ntEsc(n.texto)}</small>
        <em>${ntHora(n.em)}${n.autor ? ' · ' + ntEsc(n.autor) : ''}</em>
      </span>
    </button>`).join('');
}

async function ntCarregarHistorico() {
  try {
    const r = await ntApi('notify_history', { query: { device_id: ntDevice() } });
    NT.itens = r.notificacoes || [];
    NT.online = r.online || 0;
    NT.naoLidas = NT.itens.filter(n => !n.lido).length;
    ntRenderLista(); ntRenderBadge(); ntRenderOnline();
  } catch (e) { ntStatus('Falha ao carregar histórico: ' + e.message); }
}

function ntToggle(force) {
  const painel = ntEl('ntPanel');
  if (!painel) return;
  const abrir = typeof force === 'boolean' ? force : painel.hidden;
  painel.hidden = !abrir;
  const btn = ntEl('ntBtn');
  if (btn) btn.setAttribute('aria-expanded', String(abrir));
  if (abrir) { ntCarregarHistorico(); ntHeartbeat().then(ntPoll); }
}

async function ntAbrir(id) {
  const n = NT.itens.find(x => Number(x.id) === Number(id));
  if (n && n.pc) return ntAbrirPC(n.pc, id);
  await ntMarcarLida([id]);
}

async function ntAbrirPC(pc, id) {
  await ntMarcarLida(id ? [id] : []);
  ntToggle(false);
  ntFecharBanner();
  if (typeof openDrawer === 'function' && typeof items !== 'undefined' && items.some(i => i.pc === Number(pc))) {
    if (typeof goPage === 'function') goPage('pedidos');
    openDrawer(Number(pc));
  } else if (typeof goPage === 'function') {
    goPage('pedidos');
    const q = document.getElementById('q');
    if (q) { q.value = String(pc); if (typeof filtersChanged === 'function') filtersChanged(); }
  }
}

async function ntMarcarLida(ids) {
  try {
    await ntPost('notify_read', { device_id: ntDevice(), ids: ids || [], todas: !ids || !ids.length });
    NT.itens = NT.itens.map(n => (!ids || !ids.length || ids.includes(n.id)) ? { ...n, lido: true } : n);
    NT.naoLidas = NT.itens.filter(n => !n.lido).length;
    ntRenderLista(); ntRenderBadge();
  } catch (e) { ntStatus('Não foi possível marcar como lida: ' + e.message); }
}

async function ntMarcarTodas() { await ntMarcarLida([]); }

async function ntTestar() {
  try {
    await ntPost('notify_test', { device_id: ntDevice() });
    ntStatus('Teste enviado. Se nada aparecer em 6 segundos, verifique a conexão.');
    setTimeout(() => ntPoll(true), 1500);
  } catch (e) { ntStatus('Falha no teste: ' + e.message); }
}

function ntStatus(msg) {
  const el = ntEl('ntStatus');
  if (el) el.textContent = msg;
}

async function ntAtivarNavegador() {
  if (!('Notification' in window)) return ntStatus('Este navegador não suporta notificações do sistema.');
  try {
    const p = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (p !== 'granted') return ntStatus('Permissão não concedida: os avisos continuam no sino e no banner.');
    NT.prefs.navegador = true; ntPrefsSave(); ntHeartbeat();
    ntStatus('Notificações do navegador ativadas neste dispositivo.');
    if (typeof enablePushAlerts === 'function') {
      const ok = await enablePushAlerts();
      ntStatus(ok ? 'Notificações ativadas, inclusive com a aba fechada (Web Push).' : 'Ativadas com a aba aberta. Web Push depende de HTTPS e do servidor.');
    }
  } catch (e) { ntStatus('Falha ao ativar: ' + e.message); }
}


/* ---------------- página de configuração ---------------- */

const NT_CAT_LABEL = {
  recebimento: '📦 Conferência finalizada (integral)',
  parcial: '⚠️ Recebimento parcial',
  estoque: '▦ Movimentação de estoque (entrada/saída)',
  estoque_baixo: '🔻 Item abaixo do mínimo',
  sistema: 'ⓘ Avisos do sistema',
};

function ntRenderCats() {
  const host = ntEl('ntCats');
  if (!host) return;
  host.innerHTML = Object.entries(NT_CAT_LABEL).map(([k, label]) => `
    <label class="nt-cat"><input type="checkbox" data-cat="${k}" ${NT.prefs.categorias.includes(k) ? 'checked' : ''} onchange="ntSalvarPrefs()"> ${label}</label>`).join('');
  const som = ntEl('ntPrefSom'); if (som) som.checked = NT.prefs.som;
  const nav = ntEl('ntPrefNavegador'); if (nav) nav.checked = NT.prefs.navegador;
}

function ntLerPrefsDaTela() {
  const som = ntEl('ntPrefSom'); if (som) NT.prefs.som = som.checked;
  const nav = ntEl('ntPrefNavegador'); if (nav) NT.prefs.navegador = nav.checked;
  const cats = [...document.querySelectorAll('#ntCats input[data-cat]')].map(i => i.dataset.cat);
  if (cats.length) NT.prefs.categorias = cats;
}

function ntSalvarPrefs() {
  ntLerPrefsDaTela();
  ntPrefsSave();
  ntHeartbeat();
  ntConfigStatus('Preferências salvas neste dispositivo.');
}

function ntConfigStatus(msg) {
  const el = ntEl('ntConfigStatus');
  if (el) el.textContent = msg;
  else ntStatus(msg);
}

function ntTestarSom() {
  NT.prefs.som = true;
  ntPrefsSave();
  ntSom();
  ntConfigStatus('Som reproduzido. Se não ouviu, verifique o volume e o modo silencioso.');
}

async function ntRenderDevices() {
  const host = ntEl('ntDevices');
  if (!host) return;
  try {
    const r = await ntApi('notify_stats');
    const rows = (r.dispositivos || []).map(d => `
      <tr>
        <td><span class="nt-pill ${d.online ? 'on' : 'off'}">${d.online ? 'online' : 'offline'}</span></td>
        <td>${ntEsc(d.usuario || '—')}<br><small style="color:#92a3bb">${ntEsc(d.perfil || '')}${d.pagina ? ' · ' + ntEsc(d.pagina) : ''}</small></td>
        <td><small style="color:#92a3bb">${ntEsc(d.navegador || 'navegador não informado')}</small></td>
        <td>${ntHora(d.visto_em) || '—'}</td>
      </tr>`).join('');
    host.innerHTML = `<p style="font-size:12px;color:#92a3bb;margin-bottom:8px">${r.online} online agora · ${(r.dispositivos || []).length} dispositivo(s) conhecido(s) · ${r.total_notificacoes} notificação(ões) no histórico</p>
      <div class="nt-table-scroll"><table class="nt-table"><thead><tr><th>Situação</th><th>Operador</th><th>Navegador</th><th>Último sinal</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="4">Nenhum dispositivo registrado ainda.</td></tr>'}</tbody></table></div>`;
    const pushState = ntEl('ntPushState');
    if (pushState) {
      const ativo = window.recebimentoPushActive;
      pushState.textContent = ativo ? 'ativado' : (('Notification' in window && Notification.permission === 'denied') ? 'bloqueado' : 'não ativado');
      pushState.className = 'nt-pill ' + (ativo ? 'on' : 'off');
    }
  } catch (e) { host.textContent = 'Falha ao carregar dispositivos: ' + e.message; }
}

async function notificacoesInit() {
  if (!ntEl('page-notificacoes')) return;
  ntRenderCats();
  await ntHeartbeat();
  await ntPoll(true);
  await ntRenderDevices();
  clearInterval(NT.devTimer);
  NT.devTimer = setInterval(ntRenderDevices, 15000);
}

/* ---------------- ciclo ---------------- */

function ntIniciar() {
  if (NT.iniciado) return;
  NT.iniciado = true;
  ntPrefsLoad();
  ntDevice();
  ntRenderBadge();
  ntHeartbeat().then(() => ntPoll(true));
  clearInterval(NT.hbTimer); NT.hbTimer = setInterval(() => ntHeartbeat(), 20000);
  clearInterval(NT.timer); NT.timer = setInterval(() => ntPoll(false), 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { ntHeartbeat(); ntPoll(true); } });
  window.addEventListener('focus', () => ntPoll(true));
  window.addEventListener('online', () => { ntHeartbeat(); ntPoll(true); });
  if (typeof window !== 'undefined') {
    window.ntPageInit = () => { if (ntEl('page-notificacoes')) notificacoesInit(); };
  }
  document.addEventListener('click', () => {
    // Permite o som do navegador após a primeira interação do usuário.
    if (NT.audioCtx && NT.audioCtx.state === 'suspended') NT.audioCtx.resume();
  }, { once: true });
}

window.addEventListener('DOMContentLoaded', () => rdWhenLogged(ntIniciar));
