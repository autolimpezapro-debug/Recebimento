/* Recebimento Drilling — Acesso, usuários e permissões (v3.7.0)
 * O navegador mostra/oculta e bloqueia ações, mas a decisão é sempre do servidor:
 * api.php aplica o portão módulo × operação em cada rota.
 */
'use strict';
const RDA = { pronto: false, logado: false, user: null, csrf: '', perm: {}, exigir: false,
  admin: false, perfil: '', perfilId: '', escopo: 'todos', nome: '', opcoes: null, resumo: null,
  lista: [], filtro: { q: '', perfil: '', status: '' }, detalhe: null, matriz: null, log: [], sessoes: [] };
let rdaResolve; window.rdAuthReady = new Promise(r => { rdaResolve = r; });
const rdaEl = id => document.getElementById(id);
const rdaH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const rdaData = v => v ? new Date(v).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—';

/* página do menu → módulo */
const RDA_PAGINAS = { dashboard: 'dashboard', inicio: null, pedidos: 'pedidos', importacao: 'importacao',
  estoque: 'estoque', importados: 'estoque', ferramentas: 'estoque', locacoes: 'estoque', obras: 'estoque', coletas: 'coletas', exportacao: 'exportacao', administracao: 'administracao', notificacoes: 'notificacoes' };

function rdaCan(modulo, op) { return !!(RDA.perm && RDA.perm[modulo] && RDA.perm[modulo][op]); }
/* sem login obrigatório = comportamento legado: nada é escondido */
function rdaAtivo() { return RDA.exigir && RDA.logado; }
function rdaPermite(modulo, op) { return !rdaAtivo() ? true : rdaCan(modulo, op); }

async function rdaFetch(action, opts = {}) {
  const { query, ...init } = opts;
  const qs = query ? '&' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v != null)).toString() : '';
  const headers = { 'X-Client-Id': typeof notificationClientId !== 'undefined' ? notificationClientId : 'acesso', ...(init.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const res = await fetch(`api.php?action=${encodeURIComponent(action)}${qs}`, { cache: 'no-store', credentials: 'same-origin', ...init, headers });
  let d; try { d = await res.json(); } catch { throw new Error('O servidor não respondeu corretamente.'); }
  if (!res.ok || !d.ok) { const e = new Error(d.error || `Erro ${res.status}`); e.status = res.status; e.code = d.code; throw e; }
  return d;
}
const rdaPost = (a, b) => rdaFetch(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });

/* ---------------- sessão ---------------- */
function rdaSetStatus(st) {
  RDA.logado = !!st.authenticated; RDA.user = st.user || null; RDA.csrf = st.csrf || '';
  window.rdAuthCsrf = st.csrf || '';
  RDA.perm = st.permissoes || {}; RDA.exigir = !!st.exigir_login; RDA.admin = !!st.eh_admin_principal;
  RDA.perfil = st.perfil || ''; RDA.perfilId = st.perfil_id || ''; RDA.escopo = st.escopo || 'todos';
  RDA.nome = st.nome_completo || (st.user && st.user.username) || '';
  const box = rdaEl('rdUserChip');
  if (box) {
    box.hidden = !RDA.logado;
    box.innerHTML = `<b>${rdaH(RDA.nome)}</b><span class="rd-chip ${RDA.admin ? 'adm' : ''}">${rdaH(RDA.perfil || (st.user ? st.user.role : ''))}</span>` +
      (RDA.exigir ? `<button type="button" class="button ghost small" onclick="rdaLogout()">Sair</button>` : '');
  }
  const lg = rdaEl('rdLogin'); if (lg) lg.hidden = RDA.logado || !RDA.exigir;
  if (!RDA.logado && RDA.exigir) { const u = rdaEl('rdLoginUser'); if (u) setTimeout(() => u.focus(), 60); }
  const av = rdaEl('rdAvisoLogin'); if (av) av.hidden = !(!RDA.exigir && RDA.logado);
}
async function rdaStatus() {
  try { const st = await rdaFetch('auth_status'); rdaSetStatus(st); }
  catch (e) { rdaSetStatus({ authenticated: false }); const e2 = rdaEl('rdLoginErro'); if (e2) e2.textContent = 'Não foi possível falar com o servidor: ' + e.message; }
  RDA.pronto = true; rdaApply(); rdaResolve();
}
async function rdaLogin(ev) {
  ev.preventDefault();
  const b = rdaEl('rdLoginSubmit'); if (b) b.disabled = true;
  const err = rdaEl('rdLoginErro'); if (err) err.textContent = '';
  try {
    const r = await rdaFetch('auth_login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: rdaEl('rdLoginUser').value.trim(), password: rdaEl('rdLoginPass').value }) });
    rdaEl('rdLoginPass').value = '';
    rdaSetStatus({ authenticated: true, user: r.user, csrf: r.csrf, ...(r.permissoes ? {} : {}) });
    await rdaStatus();
    if (!RDA.logado) { err.textContent = 'Usuário ativo, mas sem acesso liberado. Fale com o administrador.'; return; }
    // Os módulos (pedidos, estoque, notificações, coletas…) tentaram carregar antes do
    // login e receberam 403. Recarregar reinicia todos já com a sessão válida.
    location.reload();
  } catch (e) { if (err) err.textContent = e.message; }
  finally { if (b) b.disabled = false; }
}
async function rdaLogout() { try { await rdaPost('auth_logout', {}); } catch {} location.reload(); }

/* ---------------- aplicação no chrome ---------------- */
function rdaApply() {
  document.querySelectorAll('.nav-link[data-page]').forEach(btn => {
    const m = RDA_PAGINAS[btn.dataset.page];
    btn.hidden = !!(m && !rdaPermite(m, 'ver'));
  });
  // abas do estoque (etiquetas é módulo próprio)
  document.querySelectorAll('[data-est-tab]').forEach(t => {
    if (t.dataset.estTab === 'etiquetas') t.hidden = !rdaPermite('etiquetas', 'ver');
  });
  // seção de usuários dentro de Administração
  const us = rdaEl('usSection'); if (us) us.hidden = !rdaPermite('usuarios', 'ver');
  rdaGuard();
}

/* Bloqueio de ações no navegador (o servidor nega de novo, por segurança). */
const RDA_FUNCOES = {
  setStatus: ['pedidos', 'editar'], setField: ['pedidos', 'editar'], updateItem: ['pedidos', 'editar'], addItem: ['pedidos', 'editar'],
  removeItem: ['pedidos', 'excluir'], markNotArrived: ['pedidos', 'editar'], savePatch: ['pedidos', 'editar'],
  receiptAction: ['pedidos', 'iniciar'], startPhoto: ['pedidos', 'anexar'], startPhotoGal: ['pedidos', 'anexar'],
  onFile: ['pedidos', 'anexar'], removePhoto: ['pedidos', 'anexar'], pickXML: ['pedidos', 'anexar'],
  openCorrection: ['pedidos', 'aprovar'], submitCorrection: ['pedidos', 'aprovar'],
  importOrderText: ['pedidos', 'editar'], useNFItems: ['pedidos', 'editar'],
  npAbrir: ['pedidos', 'criar'], npSalvar: ['pedidos', 'criar'], npLerMaterial: ['pedidos', 'criar'],
  waImportPreview: ['importacao', 'criar'], waImportConfirm: ['importacao', 'criar'], waIaSalvar: ['importacao', 'admin'], waIaTestar: ['importacao', 'admin'],
  estNovaEntrada: ['estoque', 'criar'], estAbrirNovaEntrada: ['estoque', 'criar'], estSalvarEntrada: ['estoque', 'criar'],
  estSalvarManual: ['estoque', 'criar'], estNovaSaida: ['estoque', 'criar'], estSalvarSaida: ['estoque', 'criar'],
  estConferir: ['estoque', 'editar'], estReceber: ['estoque', 'finalizar'], estEstornar: ['estoque', 'aprovar'],
  estNovaContagem: ['estoque', 'criar'], estAplicarInventario: ['estoque', 'aprovar'], estNovaReserva: ['estoque', 'criar'],
  estSalvarItem: ['estoque', 'criar'], estExportar: ['estoque', 'exportar'],
  colNovaColeta: ['coletas', 'criar'], colSalvar: ['coletas', 'criar'], colMudarStatus: ['coletas', 'status'],
  colSalvarRetorno: ['coletas', 'status'], colGeocodar: ['coletas', 'editar'], colGeocodarTodas: ['coletas', 'editar'],
  downloadCSV: ['exportacao', 'exportar'], printReport: ['exportacao', 'imprimir'], exportSelected: ['exportacao', 'exportar'],
  bkGerarAgora: ['administracao', 'criar'], bkSalvarRetencao: ['administracao', 'admin'], bkVerificar: ['administracao', 'historico'],
  backupRestore: ['administracao', 'admin'], backupSetup: ['administracao', 'admin'], backupDownload: ['administracao', 'baixar'],
  ntPublicar: ['notificacoes', 'criar'],
  etqAdicionar: ['etiquetas', 'criar'], etqManual: ['etiquetas', 'criar'], etqSalvarFila: ['etiquetas', 'editar'],
  etqConfirmarImport: ['etiquetas', 'criar'], etqImprimir: ['etiquetas', 'imprimir'], etqCadastroDialogo: ['etiquetas', 'admin'],
};
let rdaWrapped = false;
function rdaGuard() {
  if (rdaWrapped || !rdaAtivo()) return;
  rdaWrapped = true;
  for (const [nome, [mod, op]] of Object.entries(RDA_FUNCOES)) {
    const f = window[nome];
    if (typeof f !== 'function' || f.__rda) continue;
    const wrap = function (...args) {
      if (rdaPermite(mod, op)) return f.apply(this, args);
      const rotulo = (RDA.opcoes && RDA.opcoes.modulos[mod]) || mod;
      const o = (RDA.opcoes && RDA.opcoes.operacoes[op]) || op;
      if (typeof toast === 'function') toast(`Acesso negado: ${rotulo} — ${o}`, true);
      throw new Error('Acesso negado: ' + mod + '.' + op);
    };
    wrap.__rda = true; window[nome] = wrap;
  }
}

/* ---------------- navegação bloqueada por URL ---------------- */
const rdaGoPageOriginal = typeof goPage === 'function' ? goPage : null;
function rdaNegado(mod) {
  const root = rdaEl('page-negado'); if (!root) return;
  const nome = (RDA.opcoes && RDA.opcoes.modulos[mod]) || mod;
  root.innerHTML = `<div class="us-alert"><b>Acesso negado.</b> Seu perfil não tem permissão para “${rdaH(nome)}”. Se precisa desse acesso, fale com o administrador do sistema.</div>`;
}
const rdaGoPageAntes = window.goPage;
window.goPage = function (page) {
  if (page === 'administracao' && rdaPermite('usuarios', 'ver') && !RDA.resumo) setTimeout(usInit, 60);
  const mod = RDA_PAGINAS[page];
  if (rdaAtivo() && mod && !rdaCan(mod, 'ver')) {
    document.querySelectorAll('.page').forEach(el => el.classList.toggle('active', el.id === 'page-acessonegado'));
    document.querySelectorAll('.nav-link').forEach(el => el.classList.toggle('active', el.dataset.page === page));
    const bc = rdaEl('breadcrumb'); if (bc) bc.innerHTML = 'Acesso <span>/</span> Negado';
    rdaNegado(mod);
    if (typeof toast === 'function') toast('Você não tem permissão para abrir essa tela.', true);
    return;
  }
  if (rdaGoPageOriginal) return rdaGoPageOriginal(page);
};

/* ---------------- seção Usuários e Permissões ---------------- */
async function usInit() {
  if (!rdaPermite('usuarios', 'ver')) return;
  const root = rdaEl('usRoot'); if (!root) return;
  root.innerHTML = '<div class="us-alert info">Carregando usuários…</div>';
  try {
    const [op, res, lista, sess, log] = await Promise.all([
      rdaFetch('usuarios_opcoes'), rdaFetch('usuarios_resumo'), rdaFetch('usuarios_listar'),
      rdaFetch('usuarios_sessoes').catch(() => ({ sessoes: [] })), rdaFetch('usuarios_log', { query: { limite: 80 } }).catch(() => ({ eventos: [] }))]);   // v3.27.4: gestor sem permissão de histórico ainda vê os usuários
    RDA.opcoes = op; RDA.resumo = res; RDA.lista = lista.usuarios; RDA.sessoes = sess.sessoes; RDA.log = log.eventos;
    usRender();
  } catch (e) { root.innerHTML = `<div class="us-alert">${rdaH(e.message)}</div>`; }
}
function usRender() {
  const r = RDA.resumo, o = RDA.opcoes, adm = o.pode_editar;
  const kpi = (v, t, cls = '') => `<div class="us-kpi ${cls}"><strong>${v}</strong><small>${t}</small></div>`;
  rdaEl('usRoot').innerHTML = `
   <div class="us-wrap">
    <div class="us-kpis">${kpi(r.total, 'usuários')}${kpi(r.ativos, 'ativos', 'ok')}${kpi(r.inativos, 'inativos')}
      ${kpi(r.bloqueados, 'bloqueados', 'bad')}${kpi(r.conectados, 'conectados agora')}${kpi(r.perfis, 'perfis')}</div>
    ${r.sem_perfil ? `<div class="us-alert"><b>${r.sem_perfil} usuário(s) sem perfil definido.</b> Sem perfil, o acesso é negado por padrão. Atribua um perfil em Configurar acessos.</div>` : ''}
    ${!o.exigir_login ? `<div class="us-alert info"><b>Login ainda não obrigatório.</b> O sistema funciona como hoje (qualquer pessoa com a URL entra). Ao ativar, todos precisam entrar com usuário e senha próprios.
      <div class="us-tools" style="margin-top:9px"><button class="button primary small" onclick="usExigirLogin(true)">Ativar login obrigatório</button></div>
      <p class="us-hint">Antes de ativar: crie seu usuário de administrador ou confirme a senha da Administração.</p></div>`
      : `<div class="us-alert ok"><b>Login obrigatório ativo.</b> Cada acesso depende de usuário, perfil e permissões.
        <div class="us-tools" style="margin-top:9px"><button class="button ghost small" onclick="usExigirLogin(false)">Desativar (modo legado)</button></div></div>`}
    <div class="us-tools">
      <input type="search" id="usQ" placeholder="Pesquisar nome, usuário, cargo, setor ou perfil…" value="${rdaH(RDA.filtro.q)}" oninput="usFiltrar()">
      <select id="usPerfil" onchange="usFiltrar()"><option value="">Todos os perfis</option>${Object.entries(o.perfis).map(([k, p]) => `<option value="${k}" ${RDA.filtro.perfil === k ? 'selected' : ''}>${rdaH(p.nome)}</option>`).join('')}</select>
      <select id="usStatus" onchange="usFiltrar()"><option value="">Todos os status</option>${['ativo', 'inativo', 'bloqueado'].map(s => `<option ${RDA.filtro.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select>
      <span style="flex:1"></span>
      ${adm ? '<button class="button primary small" onclick="usNovo()">+ Novo usuário</button>' : ''}
      <button class="button ghost small" onclick="usInit()">↻ Atualizar</button>
    </div>
    <div class="us-scroll"><table class="us-table"><thead><tr><th>Usuário</th><th>Cargo / Setor</th><th>Perfil</th><th>Status</th><th>Último acesso</th><th>Ações</th></tr></thead>
      <tbody>${usLinhas()}</tbody></table></div>
    <div id="usPainel"></div>
    <details class="us-mod" ${RDA.log.length ? '' : 'open'}><summary>Sessões ativas (${RDA.sessoes.length}) e trilha de segurança (${RDA.log.length})</summary>
      <div style="padding:11px 13px">
        <div class="us-scroll" style="max-height:180px"><table class="us-table"><thead><tr><th>Usuário</th><th>Perfil</th><th>Início</th><th>Última atividade</th><th></th></tr></thead><tbody>
        ${RDA.sessoes.map(s => `<tr><td>${rdaH(s.usuario)}</td><td>${rdaH(s.perfil || '—')}</td><td>${rdaData(new Date((s.inicio || 0) * 1000).toISOString())}</td><td>${rdaData(new Date((s.ultima_vista || 0) * 1000).toISOString())}</td><td>${adm && s.usuario !== o.usuario ? `<button class="button ghost small" onclick="usSessoes('${rdaH(s.usuario)}')">Encerrar</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="us-empty">Nenhuma sessão registrada ainda.</td></tr>'}</tbody></table></div>
        <div class="us-log" style="margin-top:10px">${RDA.log.map(l => `<div class="${l.resultado === 'bloqueado' ? 'neg' : ''}"><time>${rdaData(l.em)}</time><b>${rdaH(l.usuario || '—')}</b><span>${rdaH(l.tipo)} · ${rdaH(l.modulo)}.${rdaH(l.operacao)}</span><span style="margin-left:auto">${rdaH(l.resultado)}</span></div>`).join('') || '<div>Sem registros.</div>'}</div>
        <p class="us-hint">A trilha registra ações, mudanças de permissão e tentativas negadas. Senhas e conteúdo de documentos nunca são gravados aqui.</p>
      </div></details>
   </div>`;
}
function usLinhas() {
  const f = RDA.filtro, k = f.q.toLowerCase();
  const rows = RDA.lista.filter(u => (!f.perfil || u.perfil === f.perfil) && (!f.status || u.status === f.status) &&
    (!k || (u.username + ' ' + u.nome + ' ' + u.cargo + ' ' + u.setor + ' ' + u.perfil_nome).toLowerCase().includes(k)));
  const adm = RDA.opcoes.pode_editar;
  if (!rows.length) return '<tr><td colspan="6" class="us-empty">Nenhum usuário com esses filtros.</td></tr>';
  return rows.map(u => `<tr>
    <td><b>${rdaH(u.nome || u.username)}</b><br><small style="color:#8fa3bd">${rdaH(u.username)}${u.matricula ? ' · ' + rdaH(u.matricula) : ''}</small></td>
    <td>${rdaH(u.cargo || '—')}<br><small style="color:#8fa3bd">${rdaH(u.setor || '')}</small></td>
    <td>${rdaH(u.perfil_nome)}${u.ajustes_individuais ? ` <span class="rd-chip">${u.ajustes_individuais} ajustes</span>` : ''}</td>
    <td><span class="us-st ${u.status}">${u.status}</span></td>
    <td>${rdaData(u.ultimo_acesso)}</td>
    <td><div class="us-tools">${adm && !u.protegido ? `
      <button class="button ghost small" onclick="usAbrir('${rdaH(u.username)}')">Configurar acessos</button>
      <button class="button ghost small" onclick="usEditar('${rdaH(u.username)}')">Editar</button>
      <button class="button ghost small" onclick="usSenha('${rdaH(u.username)}')">Redefinir senha</button>
      ${u.status !== 'ativo' ? `<button class="button ghost small" onclick="usStatus('${rdaH(u.username)}','ativo')">Ativar</button>` : ''}
      ${u.status !== 'bloqueado' ? `<button class="button ghost small" onclick="usStatus('${rdaH(u.username)}','bloqueado')">Bloquear</button>` : ''}
      ${u.status === 'ativo' ? `<button class="button ghost small" onclick="usStatus('${rdaH(u.username)}','inativo')">Desativar</button>` : ''}
      <button class="button ghost small" onclick="usSessoes('${rdaH(u.username)}')">Encerrar sessões</button>` :
      (u.protegido ? '<small style="color:#8fa3bd">conta protegida</small>' : '—')}</div></td></tr>`).join('');
}
function usFiltrar() {
  RDA.filtro = { q: rdaEl('usQ').value, perfil: rdaEl('usPerfil').value, status: rdaEl('usStatus').value };
  usRender();
}

/* painel lateral: cadastro, dados e matriz */
async function usAbrir(username) {
  try {
    const d = await rdaFetch('usuarios_detalhe', { query: { username } });
    RDA.detalhe = d; RDA.matriz = JSON.parse(JSON.stringify(d.permissoes || {}));
    usRenderPainel();
  } catch (e) { if (typeof toast === 'function') toast(e.message, true); }
}
function usRenderPainel() {
  const d = RDA.detalhe; if (!d) return;
  const o = RDA.opcoes, u = d.usuario, M = d.permissoes || {}, P = d.perfil_dados && d.perfil_dados.perm ? d.perfil_dados.perm : {};
  const host = rdaEl('usPainel');
  const linhas = Object.entries(o.modulos).map(([mod, titulo]) => {
    const ops = Object.entries(o.operacoes).map(([op, rot]) => {
      const ind = M[mod] && Object.prototype.hasOwnProperty.call(M[mod], op);
      const val = ind ? !!M[mod][op] : !!(P[mod] && P[mod][op]);
      return `<label class="us-op"><input type="checkbox" data-m="${mod}" data-o="${op}" ${val ? 'checked' : ''}> ${rdaH(rot)}
        <span class="herd ${ind ? 'ind' : ''}">${ind ? 'individual' : 'do perfil'}</span></label>`;
    }).join('');
    const herdadas = Object.values(P[mod] || {}).filter(Boolean).length;
    return `<details class="us-mod" ${mod === 'dashboard' ? 'open' : ''}><summary>${rdaH(titulo)} <span class="rd-chip">${herdadas} do perfil</span></summary>
      <div class="us-ops">${ops}</div>
      <div class="us-mod-atalhos"><button class="button ghost small" onclick="usModAll('${mod}',true)">Tudo neste módulo</button>
        <button class="button ghost small" onclick="usModAll('${mod}',false)">Nada neste módulo</button>
        <button class="button ghost small" onclick="usModHerde('${mod}')">Voltar a seguir o perfil</button></div></details>`;
  }).join('');
  host.innerHTML = `<div class="us-mod" style="border:1px solid #253550;border-radius:12px;padding:16px;background:#0f1c30">
    <div class="us-tools"><h3 style="margin:0;font-size:15px">${rdaH(u.nome || u.username)} <small style="color:#8fa3bd;font-weight:400">${rdaH(u.username)}</small></h3>
      <span style="flex:1"></span><button class="button ghost small" onclick="usFecharPainel()">Fechar</button></div>
    <p class="us-hint">${rdaH(u.cargo || 'sem cargo')}${u.setor ? ' · ' + rdaH(u.setor) : ''} · ${rdaH(u.email || 'sem e-mail')} · criado por ${rdaH(u.criado_por || '—')} em ${rdaData(u.criado_em)}</p>
    <div class="us-form" style="margin-top:12px">
      <label>Perfil de acesso<select id="usPerfilSel">${Object.entries(o.perfis).filter(([k]) => k !== 'admin_principal').map(([k, p]) => `<option value="${k}" ${u.perfil === k ? 'selected' : ''}>${rdaH(p.nome)}</option>`).join('')}</select></label>
      <label>Alcance dos registros<select id="usEscopoSel">${Object.entries(o.escopos).map(([k, v]) => `<option value="${k}" ${(d.efetivas.escopo || 'todos') === k ? 'selected' : ''}>${rdaH(v)}</option>`).join('')}</select></label>
    </div>
    <p class="us-hint">Alcance “atribuídos” usa o responsável já gravado nos registros (coletas, entradas, movimentos). Setor e unidade ainda não existem na base de dados, então hoje equivalem a “todos”.</p>
    <div class="us-matriz" style="margin-top:14px">${linhas}</div>
    <div class="us-tools" style="margin-top:14px">
      <button class="button primary" onclick="usSalvarMatriz(false)">Ver resumo antes de salvar</button>
      <button class="button ghost" onclick="usCopiar('${rdaH(u.username)}')">Copiar permissões de outro usuário…</button>
      <span id="usPainelMsg" style="font-size:12.5px;color:#8fa3bd"></span></div>
    <div id="usResumo"></div></div>`;
}
function usModAll(mod, val) {
  document.querySelectorAll(`[data-m="${mod}"]`).forEach(c => { c.checked = val; c.dataset.marcar = 'sim'; });
}
function usModHerde(mod) {
  document.querySelectorAll(`[data-m="${mod}"]`).forEach(c => { c.checked = false; });
  if (!RDA.matriz[mod]) RDA.matriz[mod] = {};
  Object.keys(RDA.matriz[mod]).forEach(op => delete RDA.matriz[mod][op]);
  usCollect(); usRenderResumo();
}
function usCollect() {
  const m = {};
  document.querySelectorAll('#usPainel [data-m]').forEach(c => {
    (m[c.dataset.m] = m[c.dataset.m] || {})[c.dataset.o] = c.checked;
  });
  RDA.matriz = m; return m;
}
function usResumoCalc() {
  const o = RDA.opcoes, P = RDA.detalhe.perfil_dados && RDA.detalhe.perfil_dados.perm ? RDA.detalhe.perfil_dados.perm : {};
  const M = RDA.matriz, out = [];
  for (const [mod, titulo] of Object.entries(o.modulos)) {
    const ops = [];
    for (const op of Object.keys(o.operacoes)) {
      const v = M[mod] && Object.prototype.hasOwnProperty.call(M[mod], op) ? M[mod][op] : !!(P[mod] && P[mod][op]);
      if (v) ops.push(o.operacoes[op]);
    }
    if (ops.length) out.push(`<li><b>${rdaH(titulo)}</b>: ${rdaH(ops.join(', '))}</li>`);
  }
  return out.length ? out.join('') : '<li><b>Nenhum acesso liberado.</b> O usuário não verá nenhuma tela.</li>';
}
function usRenderResumo() {
  const host = rdaEl('usResumo'); if (!host) return;
  host.innerHTML = `<div class="us-resumo" style="margin-top:14px"><b>Resumo do que ficará liberado</b><ul>${usResumoCalc()}</ul></div>`;
}
async function usSalvarMatriz(confirmado) {
  usCollect();
  if (!confirmado) { usRenderResumo(); rdaEl('usPainelMsg').textContent = 'Confira o resumo acima e salve de novo para aplicar.'; return; }
  const perfil = rdaEl('usPerfilSel').value, escopo = rdaEl('usEscopoSel').value;
  if (!confirm('Aplicar estas permissões? O acesso atual do usuário muda imediatamente.')) return;
  try {
    const r = await rdaPost('usuarios_permissoes', { username: RDA.detalhe.usuario.username, perfil, escopo, perm: RDA.matriz, confirmado: true });
    rdaEl('usPainelMsg').textContent = 'Permissões aplicadas.';
    if (typeof toast === 'function') toast(`Acesso de ${r.usuario.nome || r.usuario.username} atualizado (${r.resumo.modulos_com_acesso} módulo(s)).`, false);
    await usInit();
  } catch (e) { rdaEl('usPainelMsg').textContent = e.message; }
}
function usCopiar(destino) {
  const outros = RDA.lista.filter(u => u.username !== destino && !u.protegido);
  const escolha = prompt('Copiar perfil e ajustes de qual usuário?\n\n' + outros.map(u => u.username + ' — ' + (u.nome || '')).join('\n'), outros[0] ? outros[0].username : '');
  if (!escolha) return;
  rdaPost('usuarios_copiar_permissoes', { origem: escolha.trim(), destino })
    .then(() => { if (typeof toast === 'function') toast('Permissões copiadas.'); usAbrir(destino); })
    .catch(e => { if (typeof toast === 'function') toast(e.message, true); });
}
function usFecharPainel() { RDA.detalhe = null; rdaEl('usPainel').innerHTML = ''; }

/* ações de cadastro */
function usNovo() {
  const o = RDA.opcoes;
  rdaEl('usPainel').innerHTML = `<div class="us-mod" style="border:1px solid #253550;border-radius:12px;padding:16px;background:#0f1c30">
    <h3 style="margin:0 0 10px;font-size:15px">Novo usuário</h3>
    <form id="usForm" class="us-form">
      <label>Nome completo<input name="nome_completo" maxlength="120" required></label>
      <label>Nome de acesso<input name="username" maxlength="60" required pattern="[a-zA-Z0-9_.@-]{3,60}"></label>
      <label>E-mail<input name="email" type="email" maxlength="160"></label>
      <label>Matrícula<input name="matricula" maxlength="40"></label>
      <label>Cargo / função<input name="cargo" maxlength="80"></label>
      <label>Setor<input name="setor" maxlength="80"></label>
      <label>Perfil<select name="perfil">${Object.entries(o.perfis).filter(([k]) => k !== 'admin_principal').map(([k, p]) => `<option value="${k}">${rdaH(p.nome)}</option>`).join('')}</select></label>
      <label>Alcance dos registros<select name="escopo">${Object.entries(o.escopos).map(([k, v]) => `<option value="${k}">${rdaH(v)}</option>`).join('')}</select></label>
      <label>Senha (mín. 12 caracteres)<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
      <label>Confirmar senha<input name="password_confirm" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
      <div class="full us-tools"><button class="button primary" type="submit">Cadastrar usuário</button>
        <button class="button ghost" type="button" onclick="usFecharPainel()">Cancelar</button>
        <span id="usFormMsg" style="font-size:12.5px;color:#ffb2bb"></span></div>
    </form>
    <p class="us-hint">A senha é gravada como hash no servidor e nunca aparece em tela, log ou backup. Informe-a diretamente à pessoa por canal seguro.</p></div>`;
  rdaEl('usForm').onsubmit = async ev => {
    ev.preventDefault();
    const f = new FormData(ev.target), b = Object.fromEntries(f.entries());
    try {
      const r = await rdaPost('usuarios_criar', b);
      if (typeof toast === 'function') toast(`Usuário ${b.username} criado com o perfil ${r.resumo.perfil}.`);
      usFecharPainel(); await usInit();
    } catch (e) { rdaEl('usFormMsg').textContent = e.message; }
  };
}
function usEditar(username) {
  const u = RDA.lista.find(x => x.username === username); if (!u) return;
  const o = RDA.opcoes;
  rdaEl('usPainel').innerHTML = `<div class="us-mod" style="border:1px solid #253550;border-radius:12px;padding:16px;background:#0f1c30">
    <h3 style="margin:0 0 10px;font-size:15px">Editar cadastro — ${rdaH(u.username)}</h3>
    <form id="usForm" class="us-form">
      <label>Nome completo<input name="nome_completo" value="${rdaH(u.nome)}" maxlength="120"></label>
      <label>E-mail<input name="email" type="email" value="${rdaH(u.email)}" maxlength="160"></label>
      <label>Matrícula<input name="matricula" value="${rdaH(u.matricula)}" maxlength="40"></label>
      <label>Cargo / função<input name="cargo" value="${rdaH(u.cargo)}" maxlength="80"></label>
      <label>Setor<input name="setor" value="${rdaH(u.setor)}" maxlength="80"></label>
      <label>Perfil<select name="perfil"><option value="">— sem perfil —</option>${Object.entries(o.perfis).filter(([k]) => k !== 'admin_principal').map(([k, p]) => `<option value="${k}" ${u.perfil === k ? 'selected' : ''}>${rdaH(p.nome)}</option>`).join('')}</select></label>
      <label>Alcance<select name="escopo">${Object.entries(o.escopos).map(([k, v]) => `<option value="${k}" ${u.escopo === k ? 'selected' : ''}>${rdaH(v)}</option>`).join('')}</select></label>
      <div class="full us-tools"><button class="button primary" type="submit">Salvar cadastro</button>
        <button class="button ghost" type="button" onclick="usFecharPainel()">Cancelar</button><span id="usFormMsg" style="font-size:12.5px;color:#ffb2bb"></span></div>
    </form></div>`;
  rdaEl('usForm').onsubmit = async ev => {
    ev.preventDefault();
    const b = Object.fromEntries(new FormData(ev.target).entries()); b.username = username;
    try { await rdaPost('usuarios_editar', b); if (typeof toast === 'function') toast('Cadastro atualizado.'); usFecharPainel(); await usInit(); }
    catch (e) { rdaEl('usFormMsg').textContent = e.message; }
  };
}
function usSenha(username) {
  const s = prompt('Nova senha para ' + username + ' (mínimo 12 caracteres):');
  if (!s) return;
  const c = prompt('Confirme a nova senha:');
  rdaPost('usuarios_senha', { username, password: s, password_confirm: c })
    .then(r => { if (typeof toast === 'function') toast(r.mensagem || 'Senha atualizada.'); })
    .catch(e => { if (typeof toast === 'function') toast(e.message, true); });
}
function usStatus(username, status) {
  if (!confirm(`${status === 'ativo' ? 'Reativar' : status === 'bloqueado' ? 'Bloquear' : 'Desativar'} ${username}?` + (status === 'ativo' ? '' : ' As sessões abertas serão encerradas.'))) return;
  rdaPost('usuarios_status', { username, status })
    .then(() => { if (typeof toast === 'function') toast(`${username}: ${status}.`); return usInit(); })
    .catch(e => { if (typeof toast === 'function') toast(e.message, true); });
}
function usSessoes(username) {
  rdaPost('usuarios_terminar_sessoes', { username })
    .then(r => { if (typeof toast === 'function') toast(`${r.encerradas} sessão(ões) encerrada(s).`); return usInit(); })
    .catch(e => { if (typeof toast === 'function') toast(e.message, true); });
}
function usExigirLogin(v) {
  if (!confirm(v ? 'Ativar login obrigatório?\n\nA partir de agora ninguém entra sem usuário e senha. Se não houver um administrador com senha própria, você ficará de fora — o sistema avisa antes de ativar.'
    : 'Desativar a exigência de login?\n\nO sistema volta a permitir uso sem autenticação (modo legado). Não é recomendado em produção.')) return;
  rdaPost('usuarios_acesso_config', { exigir_login: v })
    .then(() => { if (typeof toast === 'function') toast(v ? 'Login obrigatório ativado. Recarregue a página.' : 'Modo legado ativado.'); return usInit(); })
    .catch(e => { if (typeof toast === 'function') toast(e.message, true); });
}

/* ---------------- inicialização ---------------- */
window.addEventListener('DOMContentLoaded', async () => {
  const form = rdaEl('rdLoginForm'); if (form) form.onsubmit = rdaLogin;
  await rdaStatus();
  if (RDA.logado && rdaPermite('usuarios', 'ver')) {
    const orig = window.backupRefresh;
    window.backupRefresh = async function (...a) { if (orig) await orig.apply(this, a); if (rdaEl('usRoot') && !RDA.resumo) usInit(); };
  }
});
