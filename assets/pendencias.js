/* v3.51.0 — Painel "Pendências de hoje" no Dashboard */
(function () {
  const esc = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let carregando = false, ultimo = 0;
  function painel() {
    let p = document.getElementById('pendPanel');
    if (p) return p;
    const pg = document.getElementById('page-dashboard'); if (!pg) return null;
    p = document.createElement('section'); p.id = 'pendPanel'; p.className = 'pend-panel'; p.hidden = true;
    const alvo = pg.querySelector('.hero-grid'); pg.insertBefore(p, alvo || null);
    return p;
  }
  function ir(modulo, id, secao) {
    if (typeof goPage !== 'function') return;
    goPage(modulo);
    setTimeout(() => {
      if (modulo === 'coletas' && id && typeof colAbrirDetalhe === 'function') colAbrirDetalhe(id);
      if (modulo === 'estoque' && typeof estGo === 'function') estGo(secao === 'estoque_entradas' ? 'entradas' : 'reposicao');
    }, 700);
  }
  window.pendIr = ir;
  function render(r) {
    const p = painel(); if (!p) return;
    const secs = (r.secoes || []).filter((s) => s.total > 0);
    if (!(r.secoes || []).length) { p.hidden = true; return; }
    p.hidden = false;
    if (!secs.length) { p.innerHTML = '<div class="pend-head"><h2>Pendências de hoje</h2></div><p class="pend-ok">✔ Tudo em dia: nada atrasado, sem itens a repor e sem entradas paradas.</p>'; return; }
    p.innerHTML = `<div class="pend-head"><h2>Pendências de hoje</h2><button type="button" class="button ghost small" onclick="pendCarregar(true)">↻ Atualizar</button></div>
      <div class="pend-grid">${secs.map((s) => `<article class="pend-card pend-${esc(s.tom)}"><header><span>${s.icone}</span><strong>${esc(s.titulo)}</strong><b>${s.total}</b></header>
        ${s.resumo ? `<small class="pend-resumo">${esc(s.resumo)}</small>` : ''}
        <ul>${s.itens.map((i) => `<li><button type="button" class="pend-item" onclick="pendIr('${esc(s.modulo)}','${esc(i.id)}','${esc(s.id)}')"><strong>${esc(i.titulo)}</strong><small>${esc(i.sub)}</small></button>
          ${i.wa ? `<a class="button ghost small pend-wa" href="${esc(i.wa)}" target="_blank" rel="noopener" title="${i.wa_direto ? 'Abrir conversa com o fornecedor' : 'Sem telefone cadastrado: escolha o contato no WhatsApp'}">◉ Cobrar</a>` : ''}</li>`).join('')}</ul>
        ${s.total > s.itens.length ? `<small class="pend-mais">+ ${s.total - s.itens.length} outro(s) no módulo</small>` : ''}</article>`).join('')}</div>`;
  }
  async function carregar(forcar) {
    if (carregando || (!forcar && Date.now() - ultimo < 60000)) return;
    carregando = true;
    try {
      const res = await fetch('api.php?action=pendencias_hoje', { cache: 'no-store', credentials: 'same-origin' });
      const j = await res.json(); if (j && j.ok) { ultimo = Date.now(); render(j); }
      else if (painel()) painel().hidden = true;
    } catch (e) { /* silencioso: o painel é um complemento */ } finally { carregando = false; }
  }
  window.pendCarregar = carregar;
  const g = window.goPage;
  if (typeof g === 'function') window.goPage = function (page) { const r = g.apply(this, arguments); if (page === 'dashboard') carregar(); return r; };
  setTimeout(carregar, 1800);
  setInterval(() => { const pg = document.getElementById('page-dashboard'); if (pg && pg.classList.contains('active') && !document.hidden) carregar(true); }, 300000);
})();
