/* Recebimento Drilling — Espelho de NF (v3.53.0). Reaproveita os helpers do Romaneio (terceiros.js). */
'use strict';
const ESP = { d: null, q: '', st: '' };
const espMoney = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const ESP_ST = { RASCUNHO: ['Rascunho', 'warn'], ENVIADO: ['Enviado ao Fiscal', 'warn'], NF_EMITIDA: ['Nota emitida', 'ok'], CANCELADO: ['Cancelado', 'off'] };

async function espelhoInit() {
  const c = terEl('espConteudo'); if (!c) return;
  try { ESP.d = await terApi('espelhos_painel'); } catch (e) { c.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  espRender();
}
function espRender() {
  const k = ESP.d.kpis, box = (n, t, cls = '') => `<div class="imp-kpi ${cls}"><b>${n}</b><span>${t}</span></div>`;
  terEl('espKpis').innerHTML = box(k.rascunhos, 'rascunhos', k.rascunhos ? 'warn' : '') + box(k.enviados, 'aguardando o Fiscal', k.enviados ? 'warn' : '') + box(k.emitidas, 'notas emitidas') + box(espMoney(k.valor_em_aberto), 'valor enviado/emitido');
  terEl('espConteudo').innerHTML = `<div class="filterrow ter-filtros"><label class="searchbox"><span>🔎</span><input id="espQ" type="search" placeholder="Buscar nº, destinatário, material, SKU, PC, OS…" value="${terH(ESP.q)}"></label>
    <select id="espSt"><option value="">Todos os status</option>${Object.entries(ESP_ST).map(([k, v]) => `<option value="${k}" ${ESP.st === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select></div><div id="espLista"></div>`;
  terEl('espQ').oninput = e => { ESP.q = e.target.value; espLista(); };
  terEl('espSt').onchange = e => { ESP.st = e.target.value; espLista(); };
  espLista();
}
function espLista() {
  const q = ESP.q.trim().toLowerCase();
  const l = (ESP.d.espelhos || []).filter(r => (!ESP.st || r.status === ESP.st) && (!q || [r.id, r.destinatario, r.os, r.nf_numero, ...(r.itens || []).flatMap(i => [i.descricao, i.sku, i.pc])].join(' ').toLowerCase().includes(q)));
  terEl('espLista').innerHTML = l.length ? l.map(espCard).join('') : `<div class="empty">${(ESP.d.espelhos || []).length ? 'Nenhum espelho com esse filtro.' : 'Nenhum espelho ainda. Clique em “＋ Novo espelho” e puxe os itens dos pedidos recebidos.'}</div>`;
}
function espCard(r) {
  const [rot, cls] = ESP_ST[r.status] || [r.status, ''], id = terH(r.id), pode = terPode('editar'), b = [];
  b.push(`<button class="button ghost" onclick="window.open('api.php?action=espelhos_imprimir&id=${encodeURIComponent(r.id)}','_blank')">🖨 Imprimir</button><a class="button ghost" href="api.php?action=espelhos_pdf&id=${encodeURIComponent(r.id)}" download>⬇ PDF</a><a class="button ghost" href="api.php?action=espelhos_csv&id=${encodeURIComponent(r.id)}" download>⬇ CSV</a>`);
  if (r.status === 'RASCUNHO' && terPode('criar')) b.push(`<button class="button ghost" onclick="espForm('${id}')">✏ Editar</button>`);
  if (r.status === 'RASCUNHO' && pode) b.push(`<button class="button primary" onclick="espEnviar('${id}',${r.itens_sem_valor})">Enviar ao Fiscal</button>`);
  if (r.status === 'ENVIADO' && pode) b.push(`<button class="button primary" onclick="espConcluir('${id}')">Registrar nota emitida</button><button class="button ghost" onclick="espMotivo('${id}','reabrir','Reabrir como rascunho')">Reabrir</button>`);
  if (terPode('criar')) b.push(`<button class="button ghost" onclick="espDuplicar('${id}')">⧉ Duplicar</button>`);
  if (['RASCUNHO', 'ENVIADO'].includes(r.status) && pode) b.push(`<button class="button ghost" onclick="espMotivo('${id}','cancelar','Cancelar espelho')">Cancelar</button>`);
  return `<article class="panel prt-card ${r.status === 'CANCELADO' ? 'prt-cancelado' : ''}"><div class="prt-top"><div><h3>${id} <span class="prt-badge ${cls}">${terH(rot)}</span>${r.nf_numero ? ` <span class="prt-badge ok">NF ${terH(r.nf_numero)}</span>` : ''}${r.itens_sem_valor ? ` <span class="prt-badge warn">${r.itens_sem_valor} item(ns) sem valor</span>` : ''}</h3>
    <p class="muted">${terD(r.data)} · para <b>${terH(r.destinatario)}</b>${r.os ? ' · OS <b>' + terH(r.os) + '</b>' : ''} · ${terH(r.natureza)} · ${r.qtd_itens} item(ns) · <b>${espMoney(r.total_geral)}</b></p></div><div class="prt-acoes">${b.join('')}</div></div>
    <details class="prt-det"><summary>Ver os ${r.qtd_itens} item(ns)</summary><div class="prt-scroll"><table class="orders-table"><thead><tr><th>SKU</th><th>Material</th><th>Un.</th><th>Qtd.</th><th>Vl. unit.</th><th>Vl. total</th><th>Origem</th></tr></thead><tbody>
    ${r.itens.map(i => `<tr><td>${terH(i.sku) || '—'}</td><td><b>${terH(i.descricao)}</b></td><td>${terH(i.unidade)}</td><td><b>${terQtd(i.quantidade)}</b></td><td>${espMoney(i.valor_unit)}</td><td>${espMoney(i.valor_total)}</td><td>${i.pc ? 'PC ' + i.pc : ''}${i.nf_origem ? ' · NF ' + terH(i.nf_origem) : ''}</td></tr>`).join('')}</tbody></table></div>
    ${r.obs ? `<p class="muted"><b>Obs.:</b> ${terH(r.obs)}</p>` : ''}${r.motivo_cancelamento ? `<p class="muted"><b>Cancelado:</b> ${terH(r.motivo_cancelamento)}</p>` : ''}
    <details class="prt-hist"><summary>Histórico</summary><ul>${(r.historico || []).map(h => `<li>${terD(h.em)} ${terH(String(h.em).slice(11, 16))} · ${terH(h.por)} — ${terH(h.acao)}</li>`).join('')}</ul></details></details></article>`;
}
function espLinha(i = {}) {
  return `<tr class="ter-it"><td><input data-f="sku" maxlength="40" placeholder="SKU" value="${terH(i.sku || '')}"></td><td><input data-f="descricao" maxlength="160" placeholder="Material*" value="${terH(i.descricao || '')}"></td>
    <td><input data-f="unidade" maxlength="8" placeholder="UN" value="${terH(i.unidade || '')}" style="width:54px"></td><td><input data-f="quantidade" inputmode="decimal" placeholder="Qtd*" value="${i.quantidade != null ? terH(String(i.quantidade).replace('.', ',')) : ''}" style="width:74px"></td>
    <td><input data-f="valor_unit" inputmode="decimal" placeholder="0,00" value="${i.valor_unit ? terH(String(i.valor_unit).replace('.', ',')) : ''}" style="width:84px" title="${terH(i.valor_fonte || '')}"></td>
    <td><input data-f="pc" inputmode="numeric" placeholder="PC" value="${terH(i.pc || '')}" style="width:64px"><input data-f="nf_origem" type="hidden" value="${terH(i.nf_origem || '')}"><input data-f="fornecedor" type="hidden" value="${terH(i.fornecedor || '')}"><input data-f="ncm" type="hidden" value="${terH(i.ncm || '')}"></td>
    <td><button type="button" class="icon-btn" data-del title="Remover">×</button></td></tr>`;
}
async function espForm(id) {
  const r = id ? (ESP.d.espelhos || []).find(x => x.id === id) : null;
  const obras = typeof rdCarregarObras === 'function' ? await rdCarregarObras() : [];
  const v = k => terH(r ? r[k] : '');
  const bg = terModal(r ? `Editar ${terH(r.id)}` : 'Novo espelho de NF', `<p class="muted">Documento para o Fiscal emitir a nota (ou para enviar material a uma obra). Não é nota fiscal e <b>não baixa o Estoque</b>.</p>
    <div class="rd-ass-campos ter-grid">
      <label>Data<input name="data" type="date" value="${r ? terH(r.data) : terHoje()}"></label>
      <label>Destinatário* (obra/cliente)<input name="destinatario" list="espObras" maxlength="140" value="${v('destinatario')}"><datalist id="espObras">${obras.map(o => `<option value="${terH(o.nome)}">`).join('')}</datalist></label>
      <label>CNPJ / CPF<input name="destinatario_doc" maxlength="24" value="${v('destinatario_doc')}"></label>
      <label>Natureza da operação<select name="natureza">${(ESP.d.naturezas || []).map(n => `<option ${r && r.natureza === n ? 'selected' : ''}>${terH(n)}</option>`).join('')}</select></label>
      <label>CFOP<input name="cfop" maxlength="6" value="${v('cfop')}"></label>
      <label>OS<input name="os" maxlength="30" value="${v('os')}"></label>
      <label>Endereço de entrega<input name="destinatario_endereco" maxlength="200" value="${v('destinatario_endereco')}"></label>
      <label>Solicitante<input name="solicitante" maxlength="80" value="${v('solicitante')}"></label>
      <label>Transportadora<input name="transportadora" maxlength="80" value="${v('transportadora')}"></label>
      <label>Placa<input name="placa" maxlength="12" value="${v('placa')}"></label>
      <label>Frete (R$)<input name="frete" inputmode="decimal" value="${r && r.frete ? terH(String(r.frete).replace('.', ',')) : ''}"></label></div>
    <h4 style="margin:12px 0 6px">Itens <button type="button" class="button ghost" data-pc>📥 Puxar de pedidos recebidos</button></h4>
    <div class="rd-ass-campos"><label>⚡ Preenchimento rápido — digite o SKU ou o nº do pedido e tecle Enter<input id="espRapido" placeholder="Ex.: UC000491  ou  5027" autocomplete="off"></label></div><small id="espRapidoMsg" class="muted"></small>
    <div class="prt-scroll"><table class="ter-itens"><thead><tr><th>SKU</th><th>Material*</th><th>Un.</th><th>Qtd*</th><th>Vl. unit.</th><th>PC</th><th></th></tr></thead><tbody id="espItens">${((r && r.itens.length) ? r.itens : [{}, {}]).map(espLinha).join('')}</tbody></table></div>
    <button type="button" class="button ghost" data-add>＋ Item manual</button>
    <div class="rd-ass-campos"><label>Observações<input name="obs" maxlength="600" value="${v('obs')}"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-salvar>Salvar rascunho</button></div>`, 960);
  const tb = bg.querySelector('#espItens');
  const ligar = () => tb.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { if (tb.querySelectorAll('.ter-it').length > 1) b.closest('tr').remove(); else b.closest('tr').querySelectorAll('input').forEach(i => i.value = ''); });
  ligar();
  bg.querySelector('[data-add]').onclick = () => { tb.insertAdjacentHTML('beforeend', espLinha()); ligar(); };
  const msg = t => { bg.querySelector('#espRapidoMsg').textContent = t; };
  const vazia = tr => ![...tr.querySelectorAll('[data-f="descricao"],[data-f="quantidade"],[data-f="sku"]')].some(i => i.value.trim());
  const addLinhas = itens => { tb.querySelectorAll('.ter-it').forEach(tr => { if (vazia(tr)) tr.remove(); }); itens.forEach(i => tb.insertAdjacentHTML('beforeend', espLinha(i))); ligar(); };
  const porPc = async pc => { const ps = (await terApi('espelhos_itens_pc&q=' + encodeURIComponent(pc))).pedidos.filter(p => String(p.pc) === String(pc)); return ps[0] || null; };
  const linhasDoPc = p => p.linhas.filter(l => l.disponivel > 0).map(l => ({ sku: l.sku, descricao: l.descricao, unidade: l.unidade, quantidade: l.disponivel, valor_unit: l.valor_unit, valor_fonte: l.valor_fonte, pc: p.pc, nf_origem: p.nf, fornecedor: p.fornecedor }));
  const rapido = async v => {
    v = v.trim(); if (!v) return;
    try {
      if (/^\d{3,6}$/.test(v)) { const p = await porPc(v); if (p) { const l = linhasDoPc(p); if (!l.length) return msg(`PC ${v}: tudo já foi para outros espelhos.`); addLinhas(l); return msg(`PC ${v} (${p.fornecedor}): ${l.length} item(ns) adicionados.`); } }
      const x = await terApi('espelhos_sku&sku=' + encodeURIComponent(v));
      if (!x.achou) return msg(`Não achei "${v}" como pedido nem como SKU. Digite o item manualmente.`);
      addLinhas([{ sku: x.sku, descricao: x.descricao, unidade: x.unidade, quantidade: '', valor_unit: x.valor_unit, valor_fonte: x.valor_fonte, pc: (x.pcs[0] || {}).pc || '', fornecedor: (x.pcs[0] || {}).fornecedor || '' }]);
      msg(`SKU ${x.sku}: ${x.descricao}${x.valor_unit ? ' · ' + espMoney(x.valor_unit) + ' (' + x.valor_fonte + ')' : ' · sem valor — preencha'}. Informe a quantidade.`);
      const q = tb.querySelector('.ter-it:last-child [data-f=quantidade]'); if (q) q.focus();
    } catch (e) { msg(e.message); }
  };
  const rap = bg.querySelector('#espRapido'); rap.onkeydown = async e => { if (e.key === 'Enter') { e.preventDefault(); const v = rap.value; rap.value = ''; await rapido(v); rap.focus(); } };
  // digitou o SKU direto na linha → completa descrição, unidade e valor; digitou o PC na linha → traz os itens do pedido
  tb.addEventListener('change', async e => {
    const f = e.target.dataset.f, tr = e.target.closest('.ter-it'); if (!tr) return; const g = k => tr.querySelector(`[data-f="${k}"]`);
    try {
      if (f === 'sku' && e.target.value.trim() && !g('descricao').value.trim()) {
        const x = await terApi('espelhos_sku&sku=' + encodeURIComponent(e.target.value.trim()));
        if (x.achou) { g('descricao').value = x.descricao; if (!g('unidade').value) g('unidade').value = x.unidade || ''; if (x.valor_unit && !g('valor_unit').value) { g('valor_unit').value = String(x.valor_unit).replace('.', ','); g('valor_unit').title = x.valor_fonte; } if (x.pcs[0] && !g('pc').value) g('pc').value = x.pcs[0].pc; g('quantidade').focus(); msg(`SKU ${x.sku} preenchido.`); }
        else msg(`SKU "${e.target.value}" não encontrado — digite a descrição.`);
      } else if (f === 'pc' && /^\d{3,6}$/.test(e.target.value.trim()) && !g('descricao').value.trim()) {
        const p = await porPc(e.target.value.trim()); if (!p) return msg(`PC ${e.target.value} não encontrado entre os recebidos.`);
        const l = linhasDoPc(p); tr.remove(); addLinhas(l); msg(`PC ${p.pc} (${p.fornecedor}): ${l.length} item(ns) adicionados.`);
      }
    } catch (er) { msg(er.message); }
  });
  bg.querySelector('[data-pc]').onclick = () => espPicker(itens => {
    tb.querySelectorAll('.ter-it').forEach(tr => { if (![...tr.querySelectorAll('[data-f="descricao"],[data-f="quantidade"]')].some(i => i.value.trim())) tr.remove(); });
    itens.forEach(i => tb.insertAdjacentHTML('beforeend', espLinha(i))); ligar();
  });
  bg.querySelector('[data-salvar]').onclick = async e => {
    const btn = e.target; btn.disabled = true;
    const o = { id: r ? r.id : '', itens: [...tb.querySelectorAll('.ter-it')].map(tr => Object.fromEntries([...tr.querySelectorAll('[data-f]')].map(i => [i.dataset.f, i.value]))) };
    ['data', 'destinatario', 'destinatario_doc', 'natureza', 'cfop', 'os', 'destinatario_endereco', 'solicitante', 'transportadora', 'placa', 'frete', 'obs'].forEach(k => o[k] = terVal(bg, k));
    const ob = obras.find(x => x.nome === o.destinatario); o.obra_id = ob ? ob.id : '';
    try { const x = (await terPost('espelhos_salvar', o)).espelho; bg.remove(); terToast(`Espelho ${x.id} salvo`); await espelhoInit(); }
    catch (er) { terToast(er.message, true); btn.disabled = false; }
  };
}
function espPicker(cb) {
  const bg = terModal('Puxar itens de pedidos recebidos', `<div class="rd-ass-campos"><label>Pedido (nº), fornecedor ou material<input name="q" placeholder="Ex.: 5007 5012  ou  confermeta  ou  parafuso" autofocus></label></div>
    <div id="espRes" class="prt-scroll" style="max-height:48vh"><div class="empty">Digite e pressione Enter. Sem filtro, mostra os pedidos recebidos mais recentes.</div></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Adicionar marcados</button></div>`, 820);
  const res = bg.querySelector('#espRes'); let dados = [];
  const buscar = async () => {
    res.innerHTML = '<div class="empty">Buscando…</div>';
    try { dados = (await terApi('espelhos_itens_pc&q=' + encodeURIComponent(terVal(bg, 'q')))).pedidos || []; } catch (e) { res.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
    res.innerHTML = dados.length ? dados.map((p, pi) => `<div class="panel" style="padding:8px 10px;margin:6px 0"><label><input type="checkbox" data-pall="${pi}"> <b>PC ${p.pc}</b> · ${terH(p.fornecedor)} · ${terD(p.recebido_em)}${p.nf ? ' · NF ' + terH(p.nf) : ''}</label>
      <table class="orders-table"><tbody>${p.linhas.map((l, li) => `<tr><td><input type="checkbox" data-p="${pi}" data-l="${li}" ${l.disponivel > 0 ? '' : 'disabled'}></td><td>${terH(l.sku) || '—'}</td><td>${terH(l.descricao)}</td><td>${terQtd(l.disponivel)} ${terH(l.unidade)}${l.ja_espelhado ? ` <small class="muted">(já em espelho: ${terQtd(l.ja_espelhado)})</small>` : ''}</td><td>${l.valor_unit ? espMoney(l.valor_unit) : '<b style="color:#c62828">sem valor</b>'}<br><small class="muted">${terH(l.valor_fonte)}</small></td></tr>`).join('')}</tbody></table></div>`).join('') : '<div class="empty">Nenhum pedido recebido encontrado.</div>';
    res.querySelectorAll('[data-pall]').forEach(c => c.onchange = () => res.querySelectorAll(`[data-p="${c.dataset.pall}"]:not(:disabled)`).forEach(x => x.checked = c.checked));
  };
  bg.querySelector('[name="q"]').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); buscar(); } };
  buscar();
  bg.querySelector('[data-ok]').onclick = () => {
    const sel = [...res.querySelectorAll('[data-l]:checked')].map(c => { const p = dados[+c.dataset.p], l = p.linhas[+c.dataset.l]; return { sku: l.sku, descricao: l.descricao, unidade: l.unidade, quantidade: l.disponivel, valor_unit: l.valor_unit, valor_fonte: l.valor_fonte, pc: p.pc, nf_origem: p.nf, fornecedor: p.fornecedor }; });
    if (!sel.length) return terToast('Marque ao menos um item.', true);
    bg.remove(); cb(sel);
  };
}
async function espAcao(body, msg) { try { await terPost('espelhos_status', body); terToast(msg); await espelhoInit(); return true; } catch (e) { if (e.message && e.message.includes('sem valor') && body.acao === 'enviar') return 'sv'; terToast(e.message, true); return false; } }
async function espEnviar(id, semValor) {
  if (semValor && !confirm(`${semValor} item(ns) estão sem valor unitário. Enviar ao Fiscal mesmo assim?`)) return;
  espAcao({ id, acao: 'enviar', confirmar_sem_valor: semValor ? 1 : 0 }, 'Enviado ao Fiscal');
}
function espConcluir(id) {
  const bg = terModal('Registrar nota emitida', `<p><b>${terH(id)}</b></p><div class="rd-ass-campos"><label>Nº da nota*<input name="nf_numero" maxlength="30"></label><label>Data de emissão<input name="nf_data" type="date" value="${terHoje()}"></label></div><div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar</button></div>`, 460);
  bg.querySelector('[data-ok]').onclick = async () => { if (await espAcao({ id, acao: 'concluir', nf_numero: terVal(bg, 'nf_numero'), nf_data: terVal(bg, 'nf_data') }, 'Nota registrada')) bg.remove(); };
}
function espMotivo(id, acao, titulo) {
  const bg = terModal(titulo, `<p><b>${terH(id)}</b></p><div class="rd-ass-campos"><label>Motivo*<input name="motivo" maxlength="200"></label></div><div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar</button></div>`, 460);
  bg.querySelector('[data-ok]').onclick = async () => { if (await espAcao({ id, acao, motivo: terVal(bg, 'motivo') }, 'Feito')) bg.remove(); };
}
async function espDuplicar(id) { try { const x = (await terPost('espelhos_status', { id, acao: 'duplicar' })).espelho; await espelhoInit(); espForm(x.id); } catch (e) { terToast(e.message, true); } }
