/* Recebimento Drilling — datas no padrão brasileiro (v3.19.1)
 * O campo de data do navegador mostra o formato do idioma do computador (ex.: 11/17/2026).
 * Aqui cada <input type="date"> ganha um campo dd/mm/aaaa ao lado (o original fica oculto e continua
 * guardando a data no formato do sistema, então nada muda para o resto do código). 📅 abre o calendário. */
'use strict';
(function () {
  const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  const iso2br = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? v.slice(8, 10) + '/' + v.slice(5, 7) + '/' + v.slice(0, 4) : '';
  const br2iso = v => {
    const m = String(v).match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if (!m) return null;
    const d = new Date(+m[3], +m[2] - 1, +m[1]);
    return d.getFullYear() === +m[3] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[1] ? `${m[3]}-${m[2]}-${m[1]}` : null;
  };
  function melhorar(el) {
    if (el.dataset.brdate || !el.parentNode) return;
    el.dataset.brdate = '1';
    const t = document.createElement('input');
    t.type = 'text'; t.inputMode = 'numeric'; t.placeholder = 'dd/mm/aaaa'; t.maxLength = 10; t.autocomplete = 'off';
    t.className = el.className; t.style.cssText = el.style.cssText; t.required = el.required; t.disabled = el.disabled;
    if (el.id) t.dataset.para = el.id;
    t.setAttribute('aria-label', el.getAttribute('aria-label') || el.title || el.name || 'data');
    el.required = false;
    const wrap = document.createElement('span'); wrap.className = 'rd-date';
    el.parentNode.insertBefore(wrap, el); wrap.appendChild(t); wrap.appendChild(el);
    const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'rd-date-btn'; btn.textContent = '📅'; btn.tabIndex = -1; btn.title = 'Abrir calendário';
    wrap.appendChild(btn);
    el.classList.add('rd-date-native'); el.tabIndex = -1;
    Object.defineProperty(el, 'value', { configurable: true,
      get() { return desc.get.call(el); },
      set(v) { desc.set.call(el, v); t.value = iso2br(desc.get.call(el)); t.setCustomValidity(''); } });
    t.value = iso2br(desc.get.call(el));
    t.addEventListener('input', () => {
      const d = t.value.replace(/\D/g, '').slice(0, 8);
      t.value = d.length > 4 ? d.slice(0, 2) + '/' + d.slice(2, 4) + '/' + d.slice(4) : d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
      const iso = br2iso(t.value);
      t.setCustomValidity(t.value && !iso ? 'Data inválida — use dd/mm/aaaa' : '');
      if (iso || t.value === '') { desc.set.call(el, iso || ''); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    el.addEventListener('change', () => { t.value = iso2br(desc.get.call(el)); t.setCustomValidity(''); });
    btn.onclick = () => { try { el.showPicker(); } catch (e) { t.focus(); } };
    new MutationObserver(() => { t.disabled = el.disabled; }).observe(el, { attributes: true, attributeFilter: ['disabled'] });
  }
  const varrer = raiz => (raiz.querySelectorAll ? raiz : document).querySelectorAll('input[type="date"]:not([data-brdate])').forEach(melhorar);
  const iniciar = () => {
    varrer(document);
    new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(n => {
      if (n.nodeType !== 1) return;
      if (n.matches && n.matches('input[type="date"]')) melhorar(n); else varrer(n);
    }))).observe(document.documentElement, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
  window.rdDataBR = iso2br;
})();
