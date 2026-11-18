/* v3.51.0 — gaveta de menu para celular e tablet */
(function () {
  const nav = document.querySelector('.sidebar .nav');
  const side = document.querySelector('.sidebar');
  if (!nav || !side || document.getElementById('navToggle')) return;
  const btn = document.createElement('button');
  btn.type = 'button'; btn.id = 'navToggle'; btn.className = 'nav-toggle';
  btn.setAttribute('aria-controls', 'mainNav'); btn.setAttribute('aria-expanded', 'false');
  btn.innerHTML = '<span class="nt-ico" aria-hidden="true">☰</span><span>Menu</span>';
  nav.id = 'mainNav';
  const head = document.createElement('div');
  head.className = 'nav-head';
  head.innerHTML = '<span>Menu principal</span><button type="button" aria-label="Fechar menu">✕</button>';
  nav.insertBefore(head, nav.firstChild);
  const back = document.createElement('div'); back.className = 'nav-backdrop';
  document.body.appendChild(back);
  side.appendChild(btn);
  const set = (on) => {
    nav.classList.toggle('open', on); back.classList.toggle('on', on);
    document.body.classList.toggle('nav-aberto', on); btn.setAttribute('aria-expanded', on ? 'true' : 'false');
  };
  btn.addEventListener('click', () => set(!nav.classList.contains('open')));
  back.addEventListener('click', () => set(false));
  head.querySelector('button').addEventListener('click', () => set(false));
  nav.addEventListener('click', (e) => { if (e.target.closest('.nav-link')) set(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
  window.addEventListener('resize', () => { if (window.innerWidth > 1030) set(false); });
})();

/* v3.51.0 — rótulos para as tabelas virarem cartões no celular (cada célula recebe o nome da coluna). */
(function () {
  const rotular = (box) => {
    const t = box.querySelector('table'); if (!t) return;
    const heads = [];
    t.querySelectorAll('thead th').forEach(th => { const n = parseInt(th.getAttribute('colspan') || '1', 10) || 1; const txt = (th.textContent || '').trim(); for (let i = 0; i < n; i++) heads.push(i === 0 ? txt : ''); });
    t.querySelectorAll('tbody tr').forEach(tr => {
      let col = 0;
      tr.querySelectorAll(':scope > td').forEach(td => { if (!td.hasAttribute('data-label')) td.setAttribute('data-label', heads[col] || ''); col += parseInt(td.getAttribute('colspan') || '1', 10) || 1; });
    });
  };
  const varrer = () => {
    document.querySelectorAll('.table-scroll').forEach(box => {
      if (box.nextElementSibling && box.nextElementSibling.classList.contains('mobile-cards')) return;   // já tem cartões próprios
      box.classList.add('cards-mobile'); rotular(box);
    });
  };
  let agendado = false;
  const agendar = () => { if (agendado) return; agendado = true; requestAnimationFrame(() => { agendado = false; varrer(); }); };
  new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
  varrer();
})();
