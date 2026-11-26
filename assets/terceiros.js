/* Recebimento Drilling — Terceiros: romaneio de saída de material do pátio para a obra (v3.39.0) */
'use strict';
const TER = { d: null, q: '', st: '', obra: '' };
const terEl = id => document.getElementById(id);
const terH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const terD = s => s ? new Date(String(s).slice(0, 10) + 'T12:00:00').toLocaleDateString('pt-BR') : '—';
const terHoje = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const terPode = op => typeof rdaPermite !== 'function' || rdaPermite('estoque', op);
const terQtd = n => Number(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
const TER_ST = { RASCUNHO: ['Rascunho', 'warn'], EMITIDO: ['Emitido — em trânsito', 'warn'], ENTREGUE: ['Entregue na obra', 'ok'], CANCELADO: ['Cancelado', 'off'] };
async function terApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const r = await fetch(`api.php?action=${action}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const terPost = (a, b) => terApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
const terToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));
function terModal(titulo, html, largura) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(${largura || 560}px,100%)"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div>${html}</div>`;
  document.body.appendChild(bg);
  bg.querySelectorAll('[data-x],[data-x2]').forEach(b => b.onclick = () => bg.remove());
  return bg;
}
const terVal = (bg, n) => { const el = bg.querySelector(`[name="${n}"]`); return el ? el.value : ''; };

async function terceirosInit() {
  const c = terEl('terConteudo'); if (!c) return;
  try { TER.d = await terApi('romaneios_painel'); } catch (e) { c.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  terRender();
}
function terKpis() {
  const k = TER.d.kpis, box = (n, t, cls = '') => `<div class="imp-kpi ${cls}"><b>${n}</b><span>${t}</span></div>`;
  terEl('terKpis').innerHTML = box(k.rascunhos, 'rascunhos salvos', k.rascunhos ? 'warn' : '') + box(k.em_transito, 'emitidos (em trânsito)', k.em_transito ? 'warn' : '') + box(k.entregues, 'entregues na obra') + box(k.cancelados, 'cancelados');
}
function terFiltrados() {
  const q = TER.q.trim().toLowerCase();
  return (TER.d.romaneios || []).filter(r => (!TER.st || r.status === TER.st) && (!TER.obra || r.obra_nome === TER.obra)
    && (!q || [r.id, r.obra_nome, r.responsavel_patio, r.solicitante, r.motorista, r.placa, ...(r.itens || []).flatMap(i => [i.descricao, i.sku])].join(' ').toLowerCase().includes(q)));
}
function terRender() {
  terKpis();
  const obras = TER.d.obras || [];
  terEl('terConteudo').innerHTML = `<div class="filterrow ter-filtros"><label class="searchbox"><span>🔎</span><input id="terQ" type="search" placeholder="Buscar nº, obra, material, SKU, motorista, placa…" value="${terH(TER.q)}"></label>
    <select id="terSt"><option value="">Todos os status</option>${Object.entries(TER_ST).map(([k, v]) => `<option value="${k}" ${TER.st === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select>
    <select id="terObra"><option value="">Todas as obras</option>${obras.map(o => `<option ${TER.obra === o ? 'selected' : ''}>${terH(o)}</option>`).join('')}</select></div><div id="terLista"></div>`;
  terEl('terQ').oninput = e => { TER.q = e.target.value; terLista(); };
  terEl('terSt').onchange = e => { TER.st = e.target.value; terLista(); };
  terEl('terObra').onchange = e => { TER.obra = e.target.value; terLista(); };
  terLista();
}
function terLista() {
  const l = terFiltrados();
  terEl('terLista').innerHTML = l.length ? l.map(terCard).join('') : `<div class="empty">${(TER.d.romaneios || []).length ? 'Nenhum romaneio com esse filtro.' : 'Nenhum romaneio ainda. Clique em “＋ Novo romaneio” para começar.'}</div>`;
}
function terCard(r) {
  const [rot, cls] = TER_ST[r.status] || [r.status, ''];
  const id = terH(r.id), pode = terPode('editar'), b = [];
  b.push(`<button class="button ghost" onclick="terImprimir('${id}')">🖨 Imprimir</button><a class="button ghost" href="api.php?action=romaneios_pdf&id=${encodeURIComponent(r.id)}" download>⬇ Baixar PDF</a><a class="button ghost" href="api.php?action=romaneios_csv&id=${encodeURIComponent(r.id)}" download>⬇ CSV</a>`);
  if (r.status === 'RASCUNHO' && terPode('criar')) b.push(`<button class="button ghost" onclick="terForm('${id}')">✏ Editar</button>`);
  if (['EMITIDO', 'ENTREGUE'].includes(r.status) && pode) b.push(`<button class="button ghost" onclick="terForm('${id}')">✏ Alterar (fica no histórico)</button>`);
  if (r.status === 'RASCUNHO' && pode) b.push(`<button class="button primary" onclick="terEmitir('${id}')">Emitir romaneio</button>`);
  if (r.status === 'EMITIDO' && pode) b.push(`<button class="button primary" onclick="terEntregar('${id}')">Marcar entregue</button>`);
  if (terPode('criar')) b.push(`<button class="button ghost" onclick="terDuplicar('${id}')">⧉ Duplicar</button>`);
  if (['RASCUNHO', 'EMITIDO'].includes(r.status) && pode) b.push(`<button class="button ghost" onclick="terCancelar('${id}')">Cancelar</button>`);
  return `<article class="panel prt-card ${r.status === 'CANCELADO' ? 'prt-cancelado' : ''}"><div class="prt-top"><div><h3>${id} <span class="prt-badge ${cls}">${terH(rot)}</span>${r.revisao ? ` <span class="prt-badge warn" title="Alterado em ${terD(r.alterado_em)} por ${terH(r.alterado_por)}">Revisão ${r.revisao}</span>` : ''}</h3>
    <p class="muted">${terD(r.data)} · de <b>${terH(r.origem)}</b>${r.responsavel_patio ? ' (' + terH(r.responsavel_patio) + ')' : ''} → <b>${terH(r.obra_nome)}</b> · ${r.qtd_itens} item(ns)${r.motorista || r.placa ? ' · ' + terH([r.motorista, r.placa].filter(Boolean).join(' / ')) : ''}${r.recebido_por ? ' · recebido por <b>' + terH(r.recebido_por) + '</b> em ' + terD(r.entregue_em) : ''}</p></div>
    <div class="prt-acoes">${b.join('')}</div></div>
    <details class="prt-det"><summary>Ver os ${r.qtd_itens} item(ns)</summary><div class="prt-scroll"><table class="orders-table"><thead><tr><th>SKU</th><th>Material</th><th>Un.</th><th>Qtd.</th><th>Obs.</th></tr></thead><tbody>
      ${r.itens.map(i => `<tr><td>${terH(i.sku) || '—'}</td><td><b>${terH(i.descricao)}</b></td><td>${terH(i.unidade)}</td><td><b>${terQtd(i.quantidade)}</b></td><td>${terH(i.obs)}</td></tr>`).join('')}</tbody></table></div>
      ${r.obs ? `<p class="muted"><b>Obs.:</b> ${terH(r.obs)}</p>` : ''}${r.motivo_cancelamento ? `<p class="muted"><b>Cancelado:</b> ${terH(r.motivo_cancelamento)}</p>` : ''}
      <details class="prt-hist"><summary>Histórico</summary><ul>${(r.historico || []).map(h => `<li>${terD(h.em)} ${terH(String(h.em).slice(11, 16))} · ${terH(h.por)} — ${terH(h.acao)}${(h.alteracoes || []).length ? `<ul class="ter-alt">${h.alteracoes.map(a => `<li><b>${terH(a.campo)}</b>: ${a.de ? `<s>${terH(a.de)}</s> → ` : ''}${a.para ? terH(a.para) : '<i>(removido)</i>'}</li>`).join('')}</ul>` : ''}</li>`).join('')}</ul></details></details></article>`;
}
function terImprimir(id) { window.open(`api.php?action=romaneios_imprimir&id=${encodeURIComponent(id)}`, '_blank'); }

/* ---- formulário (novo / editar rascunho) ---- */
function terLinha(i = {}) {
  return `<tr class="ter-it"><td><input data-f="sku" maxlength="40" placeholder="Código" value="${terH(i.sku || '')}"></td><td><input data-f="descricao" maxlength="160" placeholder="Descrição do material*" value="${terH(i.descricao || '')}"></td>
    <td><input data-f="unidade" maxlength="8" placeholder="UN" value="${terH(i.unidade || '')}"></td><td><input data-f="quantidade" inputmode="decimal" placeholder="Qtd*" value="${i.quantidade != null ? terH(String(i.quantidade).replace('.', ',')) : ''}"></td>
    <td><input data-f="obs" maxlength="120" placeholder="Obs." value="${terH(i.obs || '')}"></td><td><button type="button" class="icon-btn" data-del title="Remover linha">×</button></td></tr>`;
}
async function terForm(id) {
  const r = id ? (TER.d.romaneios || []).find(x => x.id === id) : null;
  const obras = typeof rdCarregarObras === 'function' ? await rdCarregarObras() : [];
  const nomes = obras.map(o => o.nome).filter(Boolean);
  const v = k => terH(r ? r[k] : '');
  const posEmissao = !!r && r.status !== 'RASCUNHO';
  const bg = terModal(r ? `Editar ${terH(r.id)}` : 'Novo romaneio — Terceiros', `<p class="muted">Material da empresa que está no pátio (fora do estoque) e vai para a obra. Não é nota fiscal e não movimenta o Estoque.</p>
    ${posEmissao ? '<div class="panel" style="padding:8px 12px;margin:8px 0;border-color:#f5b544">Este romaneio já foi emitido. A alteração gera uma <b>revisão</b>, e o que mudou (antes → depois), quem mudou e o motivo ficam no histórico. Imprima de novo e substitua as vias antigas.</div>' : ''}
    <div class="rd-ass-campos ter-grid">
      <label>Data<input name="data" type="date" value="${r ? terH(r.data) : terHoje()}"></label>
      <label>Origem<input name="origem" maxlength="100" value="${r ? v('origem') : 'Pátio (terceiros)'}"></label>
      <label>Responsável pelo pátio<input name="responsavel_patio" maxlength="80" placeholder="Quem controla o material" value="${v('responsavel_patio')}"></label>
      <label>Obra de destino*<input name="obra_nome" list="terObras" maxlength="120" placeholder="Escolha ou digite" value="${v('obra_nome')}"><datalist id="terObras">${nomes.map(n => `<option value="${terH(n)}">`).join('')}</datalist></label>
      <label>Solicitante<input name="solicitante" maxlength="80" value="${v('solicitante')}"></label>
      <label>Transportadora<input name="transportadora" maxlength="80" value="${v('transportadora')}"></label>
      <label>Motorista<input name="motorista" maxlength="80" value="${v('motorista')}"></label>
      <label>Veículo<input name="veiculo" maxlength="40" placeholder="Ex.: Caminhão baú" value="${v('veiculo')}"></label>
      <label>Placa<input name="placa" maxlength="12" value="${v('placa')}"></label></div>
    <h4 style="margin:12px 0 6px">Itens do romaneio</h4>
    ${posEmissao ? '' : `<div class="panel" style="padding:8px 12px;margin:0 0 8px"><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><label style="margin:0">Pedido de compra (PC) — opcional<input id="terPcNum" inputmode="numeric" maxlength="10" placeholder="Ex.: 4391" style="max-width:150px"></label><button type="button" class="button ghost" data-pcbuscar>🔎 Trazer itens do PC</button></div>
      <small class="muted" id="terPcMsg">Opcional: o PC preenche a lista (você pode editar). Também dá para digitar o SKU ou o nome na linha, ou preencher tudo à mão.</small></div>`}
    <div class="prt-scroll"><table class="ter-itens"><thead><tr><th>SKU</th><th>Material*</th><th>Un.</th><th>Qtd*</th><th>Obs.</th><th></th></tr></thead><tbody id="terItens">${((r && r.itens.length) ? r.itens : [{}, {}, {}]).map(terLinha).join('')}</tbody></table></div>
    <button type="button" class="button ghost" data-add>＋ Adicionar item</button>
    <div class="rd-ass-campos"><label>Observações<input name="obs" maxlength="600" value="${v('obs')}"></label>
      ${posEmissao ? '<label>Motivo da alteração*<input name="motivo_alteracao" maxlength="200" placeholder="Ex.: quantidade corrigida; item incluído a pedido da obra"></label>' : ''}</div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button>${posEmissao ? '<button type="button" class="button primary" data-alterar>Salvar alteração</button>' : '<button type="button" class="button ghost" data-salvar>Salvar rascunho</button><button type="button" class="button primary" data-emitir>Salvar e emitir</button>'}</div>`, 900);
  const tb = bg.querySelector('#terItens');
  const ligar = () => tb.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { if (tb.querySelectorAll('.ter-it').length > 1) b.closest('tr').remove(); });
  ligar();
  // v3.62.1 — motorista, placa, veículo, transportadora, solicitante e responsável sugeridos do histórico (opcional; pode digitar qualquer coisa)
  (async () => {
    let S; try { S = await terApi('romaneios_sugestoes'); } catch { return; }
    const campo = k => bg.querySelector(`[name="${k}"]`), norm = t => String(t || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    const dl = (id, opts) => { let d = bg.querySelector('#' + id); if (!d) { d = document.createElement('datalist'); d.id = id; bg.appendChild(d); } d.innerHTML = opts.map(o => `<option value="${terH(o[0])}">${terH(o[1] || '')}</option>`).join(''); };
    dl('terSMot', S.motoristas.map(m => [m.nome, [m.placa, m.transportadora].filter(Boolean).join(' · ')]));
    dl('terSPla', S.placas.map(p => [p.placa, [p.motorista, p.transportadora].filter(Boolean).join(' · ')]));
    dl('terSVei', S.veiculos.map(v => [v]));
    dl('terSTra', S.transportadoras.map(t => [t]));
    dl('terSCol', S.colaboradores.map(c => [c.nome, c.funcao]));
    [['motorista', 'terSMot'], ['placa', 'terSPla'], ['veiculo', 'terSVei'], ['transportadora', 'terSTra'], ['solicitante', 'terSCol'], ['responsavel_patio', 'terSCol']].forEach(([k, l]) => { const i = campo(k); if (i) i.setAttribute('list', l); });
    const vazio = k => campo(k) && !campo(k).value.trim(), pôr = (k, v) => { if (v && vazio(k)) campo(k).value = v; };
    campo('motorista').addEventListener('change', () => { const m = S.motoristas.find(x => norm(x.nome) === norm(campo('motorista').value)); if (m) { pôr('placa', m.placa); pôr('veiculo', m.veiculo); pôr('transportadora', m.transportadora); } });
    campo('placa').addEventListener('change', () => { const p = S.placas.find(x => norm(x.placa) === norm(campo('placa').value)); if (p) { pôr('motorista', p.motorista); pôr('veiculo', p.veiculo); pôr('transportadora', p.transportadora); } });
    const sol = campo('solicitante'); if (sol) sol.title = 'Escolha na lista ou digite. A função aparece ao lado do nome.';
  })();
  // v3.62.0 — ajuda opcional: itens do PC e preenchimento por SKU/nome. Tudo continua editável; nada disso é obrigatório.
  const terGetQ = (a, q) => terApi(a + '&' + new URLSearchParams(q).toString());
  const linhaVazia = tr => ![...tr.querySelectorAll('[data-f]')].some(i => i.value.trim());
  const preencher = (tr, c) => { const set = (k, v) => { const i = tr.querySelector(`[data-f="${k}"]`); if (i && v !== undefined && v !== null) i.value = v; }; set('sku', c.sku); set('descricao', c.descricao); set('unidade', c.unidade); if (c.quantidade != null) set('quantidade', String(c.quantidade).replace('.', ',')); };
  const pcBtn = bg.querySelector('[data-pcbuscar]'), pcMsg = bg.querySelector('#terPcMsg'), pcNum = bg.querySelector('#terPcNum');
  if (pcBtn) {
    const buscarPc = async () => {
      const n = (pcNum.value || '').replace(/\D/g, ''); if (!n) { pcMsg.textContent = 'Digite o número do PC.'; return; }
      pcMsg.textContent = 'Buscando…';
      try {
        const x = await terGetQ('romaneios_buscar_pc', { pc: n });
        if (x.status === 'nao_encontrado' || !x.linhas.length) { pcMsg.textContent = '⚠ ' + (x.mensagem || 'Nada encontrado.') + ' Preencha à mão.'; return; }
        tb.querySelectorAll('.ter-it').forEach(tr => { if (linhaVazia(tr)) tr.remove(); });
        x.linhas.forEach(l => { tb.insertAdjacentHTML('beforeend', terLinha(l)); });
        ligar(); ligarSku();
        const ob = bg.querySelector('[name="obra_nome"]'); if (ob && !ob.value.trim() && x.obra) ob.value = x.obra;
        pcMsg.textContent = `✔ ${x.linhas.length} item(ns) do PC ${n}${x.fornecedor ? ' — ' + x.fornecedor : ''}. Confira as quantidades e remova o que não vai.` + (x.mensagem ? ' ⚠ ' + x.mensagem : '');
      } catch (e) { pcMsg.textContent = '⚠ ' + e.message; }
    };
    pcBtn.onclick = buscarPc; pcNum.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); buscarPc(); } };
  }
  const sug = document.createElement('datalist'); sug.id = 'terSug'; bg.appendChild(sug); let sugMap = {}, sugT = null;
  function ligarSku() {
    tb.querySelectorAll('.ter-it').forEach(tr => {
      if (tr.dataset.lig) return; tr.dataset.lig = '1';
      const sku = tr.querySelector('[data-f="sku"]'), desc = tr.querySelector('[data-f="descricao"]'); desc.setAttribute('list', 'terSug');
      sku.addEventListener('change', async () => {
        if (!sku.value.trim() || desc.value.trim()) return;
        try { const x = await terGetQ('romaneios_buscar_sku', { q: sku.value.trim() }); const c = (x.candidatos || []).find(k => k.exato); if (c) preencher(tr, { sku: c.sku, descricao: c.descricao, unidade: c.unidade }); } catch { /* digitação livre */ }
      });
      desc.addEventListener('input', () => {
        clearTimeout(sugT); const q = desc.value.trim(); if (q.length < 4) return;
        sugT = setTimeout(async () => { try { const x = await terGetQ('romaneios_buscar_sku', { q }); sugMap = {}; (x.candidatos || []).forEach(c => { sugMap[c.descricao] = c; }); sug.innerHTML = Object.keys(sugMap).map(d => `<option value="${terH(d)}">${terH(sugMap[d].sku)}${sugMap[d].saldo != null ? ' · saldo ' + terQtd(sugMap[d].saldo) : ''}</option>`).join(''); } catch { /* ok */ } }, 300);
      });
      desc.addEventListener('change', () => { const c = sugMap[desc.value]; if (c) { if (!sku.value.trim()) sku.value = c.sku; const un = tr.querySelector('[data-f="unidade"]'); if (!un.value.trim()) un.value = c.unidade; } });
    });
  }
  ligarSku();
  bg.querySelector('[data-add]').onclick = () => { tb.insertAdjacentHTML('beforeend', terLinha()); ligar(); ligarSku(); tb.lastElementChild.querySelector('input').focus(); };
  tb.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('[data-f="obs"]') && e.target.closest('tr') === tb.lastElementChild) { e.preventDefault(); bg.querySelector('[data-add]').click(); } });
  const coletar = () => {
    const o = { id: r ? r.id : '', itens: [...tb.querySelectorAll('.ter-it')].map(tr => Object.fromEntries([...tr.querySelectorAll('[data-f]')].map(i => [i.dataset.f, i.value]))) };
    ['data', 'origem', 'responsavel_patio', 'obra_nome', 'solicitante', 'transportadora', 'motorista', 'veiculo', 'placa', 'obs', 'motivo_alteracao'].forEach(k => o[k] = terVal(bg, k));
    const ob = obras.find(x => x.nome === o.obra_nome); o.obra_id = ob ? ob.id : '';
    return o;
  };
  const gravar = async emitir => {
    const bs = bg.querySelectorAll('[data-salvar],[data-emitir],[data-alterar]'); bs.forEach(b => b.disabled = true);
    try {
      let x = (await terPost('romaneios_salvar', coletar())).romaneio;
      if (emitir) x = (await terPost('romaneios_status', { id: x.id, acao: 'emitir' })).romaneio;
      const alt = posEmissao; bg.remove(); terToast(alt ? `Romaneio ${x.id} alterado (revisão ${x.revisao})` : emitir ? `Romaneio ${x.id} emitido` : `Rascunho ${x.id} salvo`); await terceirosInit();
      if (emitir || alt) terPronto(x);
    } catch (e) { terToast(e.message, true); bs.forEach(b => b.disabled = false); }
  };
  const bsv = bg.querySelector('[data-salvar]'), bem = bg.querySelector('[data-emitir]'), bal = bg.querySelector('[data-alterar]');
  if (bsv) bsv.onclick = () => gravar(false); if (bem) bem.onclick = () => gravar(true); if (bal) bal.onclick = () => gravar(false);
}
function terPronto(r) {
  const bg = terModal(`${r.revisao ? 'Romaneio ' + terH(r.id) + ' alterado' : 'Romaneio ' + terH(r.id) + ' emitido'}`, `<p>Imprima 2 vias (Obra e Arquivo) ou baixe o arquivo.${r.revisao ? ' Esta é a <b>revisão ' + r.revisao + '</b>: substitua as vias antigas.' : ''}</p><div class="prt-pronto">
    <button type="button" class="button primary" data-imp>🖨 Imprimir</button><a class="button ghost" href="api.php?action=romaneios_pdf&id=${encodeURIComponent(r.id)}" download>⬇ Baixar PDF</a><a class="button ghost" href="api.php?action=romaneios_csv&id=${encodeURIComponent(r.id)}" download>⬇ CSV</a></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Fechar</button></div>`, 480);
  bg.querySelector('[data-imp]').onclick = () => terImprimir(r.id);
}
async function terStatus(bg, body, msg) {
  const btn = bg && bg.querySelector('[data-ok]'); if (btn) btn.disabled = true;
  try { const x = (await terPost('romaneios_status', body)).romaneio; if (bg) bg.remove(); terToast(msg); await terceirosInit(); return x; }
  catch (e) { terToast(e.message, true); if (btn) btn.disabled = false; }
}
async function terEmitir(id) {
  const x = await terStatus(null, { id, acao: 'emitir' }, `Romaneio ${id} emitido`); if (x) terPronto(x);
}
function terEntregar(id) {
  const bg = terModal('Marcar como entregue', `<p>Quem recebeu o material de <b>${terH(id)}</b> na obra?</p><div class="rd-ass-campos"><label>Recebido por*<input name="recebido_por" maxlength="80"></label><label>Data da entrega<input name="data" type="date" value="${terHoje()}"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar entrega</button></div>`, 480);
  bg.querySelector('[data-ok]').onclick = () => terStatus(bg, { id, acao: 'entregar', recebido_por: terVal(bg, 'recebido_por'), data: terVal(bg, 'data') }, 'Entrega registrada');
}
function terCancelar(id) {
  const bg = terModal('Cancelar romaneio', `<p><b>${terH(id)}</b></p><div class="rd-ass-campos"><label>Motivo*<input name="motivo" maxlength="200"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar cancelamento</button></div>`, 460);
  bg.querySelector('[data-ok]').onclick = () => terStatus(bg, { id, acao: 'cancelar', motivo: terVal(bg, 'motivo') }, 'Romaneio cancelado');
}
async function terDuplicar(id) {
  const x = await terStatus(null, { id, acao: 'duplicar' }, 'Duplicado como rascunho'); if (x) terForm(x.id);
}
