/* Recebimento Drilling — "Continuar de onde parei" (v3.56.0).
 * Guarda automaticamente, neste aparelho, o que foi preenchido em processos que ainda não foram finalizados
 * e mostra uma faixa no topo com o botão para retomar. Some sozinho quando o processo é concluído no servidor.
 * Processos: Saída de material, Entrada manual, Chegada, Espelho de NF, Romaneio de terceiros, Empréstimo de ferramentas, Contagem de inventário. */
'use strict';
const RASC = { chave: 'rd_rascunhos_v1', ttl: 7 * 864e5, timers: {}, restaurando: false, modais: {} };
const rascH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const rascEsperar = async (cond, ms = 4000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { try { const v = cond(); if (v) return v; } catch { } await new Promise(r => setTimeout(r, 80)); } return null; };
function rascUser() { try { return (typeof RDA !== 'undefined' && ((RDA.user && RDA.user.username) || RDA.nome)) || 'local'; } catch { return 'local'; } }
function rascTudo() { try { return JSON.parse(localStorage.getItem(RASC.chave) || '{}') || {}; } catch { return {}; } }
function rascGravarTudo(o) { try { localStorage.setItem(RASC.chave, JSON.stringify(o)); return true; } catch { return false; } }
function rascLista() {
  const todos = rascTudo(), meus = todos[rascUser()] || {}, agora = Date.now(); let mudou = false;
  Object.keys(meus).forEach(k => { if (agora - (meus[k].em || 0) > RASC.ttl) { delete meus[k]; mudou = true; } });
  if (mudou) { todos[rascUser()] = meus; rascGravarTudo(todos); }
  return meus;
}
function rascSalvar(proc, dados, rotulo) {
  const todos = rascTudo(), u = rascUser(); (todos[u] = todos[u] || {})[proc] = { dados, rotulo, em: Date.now() };
  if (!rascGravarTudo(todos)) { // sem espaço: tenta sem imagens
    const leve = JSON.parse(JSON.stringify(dados).replace(/"data:image[^"]*"/g, '""')); todos[u][proc].dados = leve; rascGravarTudo(todos);
  }
  rascRender();
}
function rascLimpar(proc) {
  clearTimeout(RASC.timers[proc]); const todos = rascTudo(), u = rascUser();
  if (todos[u] && todos[u][proc]) { delete todos[u][proc]; rascGravarTudo(todos); }
  rascRender();
}
function rascAgendar(proc) {
  if (RASC.restaurando) return; clearTimeout(RASC.timers[proc]);
  RASC.timers[proc] = setTimeout(() => { const a = RASC.ad[proc]; let c = null; try { c = a.capturar(); } catch { } if (c) rascSalvar(proc, c.dados, c.rotulo); }, 700);
}

/* ---- captura/restauração genérica dos formulários em janela (terModal / ferModal): campos [name] e linhas .ter-it [data-f] ---- */
function rascCampos(bg) { const o = {}; bg.querySelectorAll('input[name],select[name],textarea[name]').forEach(e => { if (e.type === 'file' || e.type === 'password') return; o[e.name] = e.type === 'checkbox' ? e.checked : e.value; }); return o; }
function rascLinhas(bg) { return [...bg.querySelectorAll('.ter-it')].map(tr => Object.fromEntries([...tr.querySelectorAll('[data-f]')].map(i => [i.dataset.f, i.value]))); }
function rascAplicarCampos(bg, c) {
  Object.entries(c || {}).forEach(([n, v]) => { const e = bg.querySelector(`[name="${n}"]`); if (!e) return; if (e.type === 'checkbox') e.checked = !!v; else e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); });
}
function rascAplicarLinhas(bg, linhas) {
  const tb = bg.querySelector('.ter-itens tbody'); if (!tb || !linhas || !linhas.length) return;
  const add = bg.querySelector('[data-add]'); let g = 0;
  while (tb.querySelectorAll('.ter-it').length < linhas.length && add && g++ < 200) add.click();
  [...tb.querySelectorAll('.ter-it')].forEach((tr, i) => { const l = linhas[i] || {}; tr.querySelectorAll('[data-f]').forEach(inp => { if (l[inp.dataset.f] != null) inp.value = l[inp.dataset.f]; }); });
}
const rascTemTexto = o => Object.values(o || {}).some(v => typeof v === 'string' && v.trim() !== '');
function rascLigarJanela(proc, bg) {
  RASC.modais[proc] = bg; const ag = () => rascAgendar(proc);
  bg.addEventListener('input', ag); bg.addEventListener('change', ag);
  new MutationObserver(ag).observe(bg, { childList: true, subtree: true });
}

/* ---- adaptadores ---- */
const rq = id => document.getElementById(id);
RASC.ad = {
  saida: {
    titulo: 'Saída de material', pagina: 'estoque',
    capturar() {
      if (!rq('estSaidaModal') || !rq('estSaidaModal').classList.contains('on')) return null;
      const linhas = [...rq('estSaidaLinhas').children].map(r => ({ codigo: r.dataset.codigo || '', pc: r.dataset.pc || '', linha_pc: r.dataset.linhaPc || '', auto_foto: r.dataset.autoFoto || '', max: r.dataset.max || '', rotulo: (r.querySelector('.est-sub') || {}).textContent || '', desc: r.querySelector('.est-s-desc').value, qtd: r.querySelector('.est-s-qtd').value, obs: r.querySelector('.est-s-obs').value, foto: (r.querySelector('.est-foto-btn') || {}).dataset?.foto || '' })).filter(l => l.desc.trim() || l.qtd);
      const campos = Object.fromEntries(['estSaidaSetor', 'estSaidaObra', 'estSaidaObraOutra', 'estSaidaSolicitante', 'estSaidaFinalidade', 'estSaidaOS', 'estSaidaEquip'].map(i => [i, rq(i) ? rq(i).value : '']));
      if (!linhas.length && !rascTemTexto(campos)) return null;
      const pcs = [...new Set(linhas.map(l => l.pc).filter(Boolean))];
      return { dados: { campos, linhas, meta: typeof EST_PCS_META !== 'undefined' ? EST_PCS_META : {} }, rotulo: `Saída de material — ${linhas.length} item(ns)${pcs.length ? ' · PC ' + pcs.join(', ') : ''}${campos.estSaidaSolicitante ? ' · ' + campos.estSaidaSolicitante : ''}` };
    },
    async abrir() { goPage('estoque'); await rascEsperar(() => typeof estNovaSaida === 'function'); estNovaSaida(); return true; },
    async restaurar(d) {
      const c = d.campos || {}, set = (i, v) => { if (rq(i) && v != null) rq(i).value = v; };
      ['estSaidaSolicitante', 'estSaidaFinalidade', 'estSaidaOS', 'estSaidaEquip'].forEach(i => set(i, c[i]));
      set('estSaidaSetor', c.estSaidaSetor); estSaidaSetorMudou();
      if (c.estSaidaObra) { await rascEsperar(() => [...rq('estSaidaObra').options].some(o => o.value === c.estSaidaObra)); set('estSaidaObra', c.estSaidaObra); estSaidaSetorMudou(); set('estSaidaObraOutra', c.estSaidaObraOutra); }
      rq('estSaidaLinhas').innerHTML = '';
      (d.linhas || []).forEach(l => {
        let row;
        if (l.codigo) row = estSaidaLinha({ codigo: l.codigo, descricao: l.desc, quantidade: l.qtd, pc: l.pc, linha_pc: l.linha_pc, foto: l.auto_foto, pendente: l.max !== '' ? Number(l.max) : null, rotulo: l.rotulo });
        else { row = estSaidaLinha(); row.querySelector('.est-s-desc').value = l.desc; row.querySelector('.est-s-qtd').value = l.qtd; }
        row.querySelector('.est-s-obs').value = l.obs || '';
        if (l.foto) { const lab = row.querySelector('.est-foto-btn'), im = lab.querySelector('img'); lab.dataset.foto = l.foto; im.src = l.foto; im.hidden = false; lab.querySelector('span').hidden = true; lab.classList.add('on'); }
      });
      if (!(d.linhas || []).length) estSaidaLinha();
      EST_PCS_META = d.meta || {}; estSaidaPcsRender();
    },
    observar() { const m = rq('estSaidaModal'); if (!m) return; const ag = () => rascAgendar('saida'); m.addEventListener('input', ag); m.addEventListener('change', ag); new MutationObserver(ag).observe(rq('estSaidaLinhas'), { childList: true }); }
  },
  entrada_manual: {
    titulo: 'Entrada manual de material', pagina: 'estoque',
    capturar() {
      const m = rq('estManualModal'); if (!m || !m.classList.contains('on')) return null;
      const linhas = [...rq('estManualLinhas').children].map(r => ({ desc: r.querySelector('.est-l-desc').value, un: r.querySelector('.est-l-un').value, qtd: r.querySelector('.est-l-qtd').value, valor: r.querySelector('.est-l-valor').value, lote: r.querySelector('.est-l-lote').value })).filter(l => l.desc.trim() || l.qtd);
      const campos = { forn: rq('estManualForn').value, nf: rq('estManualNF').value, obs: rq('estManualObs').value };
      if (!linhas.length && !rascTemTexto(campos)) return null;
      return { dados: { campos, linhas }, rotulo: `Entrada manual — ${linhas.length} item(ns)${campos.nf ? ' · NF ' + campos.nf : ''}` };
    },
    async abrir() { goPage('estoque'); await rascEsperar(() => typeof estNovaEntradaManual === 'function'); estNovaEntradaManual(); return true; },
    async restaurar(d) {
      rq('estManualForn').value = d.campos.forn || ''; rq('estManualNF').value = d.campos.nf || ''; rq('estManualObs').value = d.campos.obs || '';
      rq('estManualLinhas').innerHTML = '';
      (d.linhas || []).forEach(l => { estManualLinha(); const r = rq('estManualLinhas').lastElementChild; r.querySelector('.est-l-desc').value = l.desc; r.querySelector('.est-l-un').value = l.un; r.querySelector('.est-l-qtd').value = l.qtd; r.querySelector('.est-l-valor').value = l.valor; r.querySelector('.est-l-lote').value = l.lote; });
      if (!(d.linhas || []).length) estManualLinha();
    },
    observar() { const m = rq('estManualModal'); if (!m) return; const ag = () => rascAgendar('entrada_manual'); m.addEventListener('input', ag); new MutationObserver(ag).observe(rq('estManualLinhas'), { childList: true }); }
  },
  chegada: {
    titulo: 'Chegada / NF pendente', pagina: 'chegadas', janela: /^Registrar chegada$/,
    capturar() { const bg = RASC.modais.chegada; if (!bg || !bg.isConnected) return null; const c = rascCampos(bg), l = rascLinhas(bg).filter(x => rascTemTexto(x)); if (!l.length && !['fornecedor', 'pc', 'nf_numero', 'valor', 'origem', 'recebido_por', 'transportadora', 'contato', 'obs'].some(k => (c[k] || '').trim())) return null; return { dados: { c, l }, rotulo: `Chegada — ${c.fornecedor || 'sem fornecedor'}${c.pc ? ' · PC ' + c.pc : ''}${c.nf_numero ? ' · NF ' + c.nf_numero : ''}` }; },
    async abrir() { goPage('chegadas'); await rascEsperar(() => typeof CHG !== 'undefined' && CHG.d); chgForm(); return true; },
    restaurar(d, bg) { rascAplicarCampos(bg, d.c); rascAplicarLinhas(bg, d.l); }
  },
  espelho: {
    titulo: 'Espelho de NF', pagina: 'espelho', janela: /^Novo espelho de NF$/,
    capturar() { const bg = RASC.modais.espelho; if (!bg || !bg.isConnected) return null; const c = rascCampos(bg), l = rascLinhas(bg).filter(x => rascTemTexto(x)); if (!l.length && !rascTemTexto(c)) return null; return { dados: { c, l }, rotulo: `Espelho de NF — ${l.length} item(ns)${c.destinatario ? ' · ' + c.destinatario : ''}` }; },
    async abrir() { goPage('espelho'); await rascEsperar(() => typeof ESP !== 'undefined' && ESP.d); espForm(); return true; },
    restaurar(d, bg) { rascAplicarCampos(bg, d.c); rascAplicarLinhas(bg, d.l); }
  },
  romaneio: {
    titulo: 'Romaneio de terceiros', pagina: 'terceiros', janela: /^Novo romaneio/,
    capturar() { const bg = RASC.modais.romaneio; if (!bg || !bg.isConnected) return null; const c = rascCampos(bg), l = rascLinhas(bg).filter(x => rascTemTexto(x)); if (!l.length && !c.obra_nome && !c.solicitante) return null; return { dados: { c, l }, rotulo: `Romaneio de terceiros — ${l.length} item(ns)${c.obra_nome ? ' · ' + c.obra_nome : ''}` }; },
    async abrir() { goPage('terceiros'); await rascEsperar(() => typeof TER !== 'undefined' && TER.d); terForm(); return true; },
    restaurar(d, bg) { rascAplicarCampos(bg, d.c); rascAplicarLinhas(bg, d.l); }
  },
  emprestimo: {
    titulo: 'Empréstimo de ferramentas', pagina: 'ferramentas', janela: /^Novo empréstimo de ferramentas$/, modalFer: true,
    capturar() { const bg = RASC.modais.emprestimo; if (!bg || !bg.isConnected) return null; const c = rascCampos(bg), car = Object.fromEntries(Object.entries(FER.carrinho || {}).filter(([, q]) => q > 0)); if (!Object.keys(car).length && !c.finalidade) return null; const n = Object.values(car).reduce((a, b) => a + b, 0); return { dados: { c, car }, rotulo: `Empréstimo de ferramentas — ${n} ferramenta(s)${c.finalidade ? ' · ' + c.finalidade : ''}` }; },
    async abrir() { goPage('ferramentas'); await rascEsperar(() => typeof FER !== 'undefined' && FER.painel); ferNovoEmprestimo(); return true; },
    async restaurar(d, bg) {
      rascAplicarCampos(bg, d.c);
      const busca = bg.querySelector('.fer-busca');
      for (const [id, q] of Object.entries(d.car || {})) {
        const f = (FER.painel.ferramentas || []).find(x => x.id === id); if (!f) continue;
        busca.value = f.codigo; busca.dispatchEvent(new Event('input'));
        for (let i = 0; i < q; i++) { const b = bg.querySelector(`[data-p="${CSS.escape(id)}"]`); if (b && !b.disabled) b.click(); }
      }
      busca.value = ''; busca.dispatchEvent(new Event('input'));
    }
  },
  contagem: {
    titulo: 'Contagem de inventário', pagina: 'contagens',
    capturar() { return null; },   // gravada por contagens.js (a contagem em si já fica no servidor)
    async abrir(d) { goPage('contagens'); await rascEsperar(() => typeof cntAbrir === 'function' && CNT.d); await cntAbrir(d.id); return false; },
    restaurar() { }
  }
};

/* ---- ligação com as janelas criadas por terModal / ferModal ---- */
function rascEnvolverJanela(nome, ehFer) {
  const orig = window[nome]; if (typeof orig !== 'function') return;
  window[nome] = function (titulo) {
    const bg = orig.apply(this, arguments);
    const plain = String(titulo).replace(/<[^>]*>/g, '');
    for (const [proc, a] of Object.entries(RASC.ad)) if (a.janela && !!a.modalFer === !!ehFer && a.janela.test(plain)) {
      rascLigarJanela(proc, bg);
      if (RASC.pendente && RASC.pendente.proc === proc) { const p = RASC.pendente; RASC.pendente = null; setTimeout(async () => { RASC.restaurando = true; try { await a.restaurar(p.dados, bg); } finally { setTimeout(() => RASC.restaurando = false, 900); } }, 0); }
    }
    return bg;
  };
}

/* ---- conclusão do processo (resposta ok do servidor) apaga o rascunho ---- */
const RASC_CONCLUI = { estoque_saida: 'saida', estoque_entrada_manual: 'entrada_manual', chegadas_salvar: 'chegada', espelhos_salvar: 'espelho', romaneios_salvar: 'romaneio', ferramentas_emprestar: 'emprestimo', contagens_aplicar: 'contagem', contagens_cancelar: 'contagem' };
const RASC_SO_NOVO = new Set(['chegadas_salvar', 'espelhos_salvar', 'romaneios_salvar']);   // edição de um registro existente não encerra o rascunho de um novo
(function () {
  const f0 = window.fetch; if (!f0) return;
  window.fetch = async function (url, init) {
    const r = await f0.apply(this, arguments);
    try {
      const m = /[?&]action=([a-z_]+)/.exec(String(url && url.url || url)), proc = m && RASC_CONCLUI[m[1]];
      if (proc && r.ok && init && init.method === 'POST') {
        let corpo = {}; try { corpo = JSON.parse(init.body); } catch { }
        if (RASC_SO_NOVO.has(m[1]) && corpo.id) return r;
        r.clone().json().then(j => { if (j && j.ok) rascLimpar(proc); }).catch(() => { });
      }
    } catch { }
    return r;
  };
})();

/* ---- faixa "Continuar de onde parei" ---- */
function rascQuando(em) { const m = Math.round((Date.now() - em) / 60000); return m < 1 ? 'agora há pouco' : m < 60 ? `há ${m} min` : m < 1440 ? `há ${Math.round(m / 60)} h` : `há ${Math.round(m / 1440)} dia(s)`; }
function rascRender() {
  let bar = rq('rascBanner');
  if (!bar) { const pg = document.querySelector('.page'); if (!pg) return; bar = document.createElement('div'); bar.id = 'rascBanner'; bar.setAttribute('aria-live', 'polite'); pg.parentNode.insertBefore(bar, pg); }
  const l = rascLista(), ks = Object.keys(l).filter(k => RASC.ad[k]).sort((a, b) => l[b].em - l[a].em);
  bar.hidden = !ks.length;
  bar.innerHTML = ks.map(k => `<div class="rasc-item"><span class="rasc-ico">📝</span><div class="rasc-txt"><b>Você começou e não finalizou:</b> ${rascH(l[k].rotulo || RASC.ad[k].titulo)} <small>· salvo ${rascQuando(l[k].em)}</small></div>
    <button type="button" class="button primary small" data-rasc-cont="${k}">Continuar de onde parei</button><button type="button" class="button ghost small" data-rasc-desc="${k}" title="Descartar este rascunho">Descartar</button></div>`).join('');
}
async function rascContinuar(proc) {
  const a = RASC.ad[proc], reg = rascLista()[proc]; if (!a || !reg) return;
  try {
    RASC.pendente = a.janela ? { proc, dados: reg.dados } : null;
    RASC.restaurando = true;
    const abriu = await a.abrir(reg.dados);
    if (!a.janela && abriu) await a.restaurar(reg.dados);
    if (a.janela) await rascEsperar(() => !RASC.pendente, 4000);
  } catch (e) { if (typeof toast === 'function') toast('Não foi possível retomar: ' + e.message, true); }
  finally { RASC.pendente = null; setTimeout(() => { RASC.restaurando = false; }, 1000); }
}
document.addEventListener('click', e => {
  const c = e.target.closest('[data-rasc-cont]'), d = e.target.closest('[data-rasc-desc]');
  if (c) rascContinuar(c.dataset.rascCont);
  if (d && confirm('Descartar o que foi preenchido e não finalizado?')) rascLimpar(d.dataset.rascDesc);
});
window.addEventListener('storage', e => { if (e.key === RASC.chave) rascRender(); });
window.addEventListener('beforeunload', () => Object.keys(RASC.timers).forEach(p => { clearTimeout(RASC.timers[p]); const a = RASC.ad[p]; try { const c = a.capturar(); if (c) rascSalvar(p, c.dados, c.rotulo); } catch { } }));
window.addEventListener('DOMContentLoaded', () => {
  rascEnvolverJanela('terModal', false); rascEnvolverJanela('ferModal', true);
  Object.values(RASC.ad).forEach(a => a.observar && a.observar());
  const iniciar = () => rascRender();
  (window.rdAuthReady ? Promise.resolve(window.rdAuthReady).catch(() => { }) : Promise.resolve()).then(iniciar);
  setInterval(rascRender, 60000);
});
