/* Recebimento Drilling — Chegadas no almoxarifado (v3.54.0): material sem NF, NF sem material, compra online sem NF. */
'use strict';
const CHG = { d: null, q: '', st: 'ABERTO', tipo: '' };
const chgMoney = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const CHG_COR = { MATERIAL_SEM_NF: 'warn', NF_SEM_MATERIAL: 'warn', ONLINE_SEM_NF: 'warn', OUTRO: '' };

async function chegadasInit() {
  const c = terEl('chgConteudo'); if (!c) return;
  try { CHG.d = await terApi('chegadas_painel'); } catch (e) { c.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  chgRender();
}
function chgRender() {
  const k = CHG.d.kpis, box = (n, t, cls = '', f = '') => `<div class="imp-kpi ${cls}" ${f ? `style="cursor:pointer" onclick="${f}"` : ''}><b>${n}</b><span>${t}</span></div>`;
  terEl('chgKpis').innerHTML = box(k.abertos, 'pendências abertas', k.abertos ? 'warn' : '', "CHG.st='ABERTO';CHG.tipo='';chgRender()") + box(k.vencidos, 'vencidas (passou do prazo)', k.vencidos ? 'bad' : '') + box(k.sem_nf, 'material sem NF', '', "CHG.st='ABERTO';CHG.tipo='MATERIAL_SEM_NF';chgRender()")
    + box(k.nf_sem_material, 'NF sem material', '', "CHG.st='ABERTO';CHG.tipo='NF_SEM_MATERIAL';chgRender()") + box(k.online, 'compra online sem NF', '', "CHG.st='ABERTO';CHG.tipo='ONLINE_SEM_NF';chgRender()") + box(k.resolvidos, 'resolvidas');
  const forn = CHG.d.fornecedores_pendentes.slice(0, 6).map(f => `<span class="prt-badge warn">${terH(f.fornecedor)}: ${f.abertos}</span>`).join(' ');
  terEl('chgConteudo').innerHTML = `${forn ? `<p class="muted">Fornecedores com pendência: ${forn}</p>` : ''}<div class="filterrow ter-filtros"><label class="searchbox"><span>🔎</span><input id="chgQ" type="search" placeholder="Buscar fornecedor, PC, NF, material, código…" value="${terH(CHG.q)}"></label>
    <select id="chgSt"><option value="">Todos os status</option>${[['ABERTO', 'Abertas'], ['RESOLVIDO', 'Resolvidas'], ['CANCELADO', 'Canceladas']].map(([v, t]) => `<option value="${v}" ${CHG.st === v ? 'selected' : ''}>${t}</option>`).join('')}</select>
    <select id="chgTp"><option value="">Todos os tipos</option>${Object.entries(CHG.d.tipos).map(([v, t]) => `<option value="${v}" ${CHG.tipo === v ? 'selected' : ''}>${terH(t)}</option>`).join('')}</select></div><div id="chgLista"></div>`;
  terEl('chgQ').oninput = e => { CHG.q = e.target.value; chgLista(); };
  terEl('chgSt').onchange = e => { CHG.st = e.target.value; chgLista(); };
  terEl('chgTp').onchange = e => { CHG.tipo = e.target.value; chgLista(); };
  chgLista();
}
function chgLista() {
  const q = CHG.q.trim().toLowerCase();
  const l = CHG.d.registros.filter(r => (!CHG.st || r.status === CHG.st) && (!CHG.tipo || r.tipo === CHG.tipo) && (!q || [r.id, r.fornecedor, r.pc, r.nf_numero, r.origem, r.obs, ...r.itens.flatMap(i => [i.descricao, i.sku])].join(' ').toLowerCase().includes(q)));
  l.sort((a, b) => (b.vencido - a.vencido) || (b.dias - a.dias));
  terEl('chgLista').innerHTML = l.length ? l.map(chgCard).join('') : `<div class="empty">${CHG.d.registros.length ? 'Nada com esse filtro.' : 'Nenhuma chegada registrada. Clique em “＋ Registrar chegada” quando vier material sem nota, nota sem material ou compra online sem NF.'}</div>`;
}
function chgCard(r) {
  const id = terH(r.id), b = [], aberto = r.status === 'ABERTO', pode = terPode('editar');
  if (aberto && pode) b.push(`<button class="button ghost" onclick="chgCobrar('${id}')">📣 Cobrar fornecedor</button><button class="button primary" onclick="chgResolver('${id}')">✔ Resolver</button>`);
  if (aberto && terPode('criar')) b.push(`<button class="button ghost" onclick="chgForm('${id}')">✏ Editar</button>`);
  if (aberto && pode) b.push(`<button class="button ghost" onclick="chgMotivo('${id}','cancelar','Cancelar (lançado por engano)')">Cancelar</button>`);
  if (!aberto && pode) b.push(`<button class="button ghost" onclick="chgMotivo('${id}','reabrir','Reabrir pendência',true)">Reabrir</button>`);
  const stb = aberto ? (r.vencido ? '<span class="prt-badge bad">VENCIDA há ' + (r.dias - r.prazo_dias) + ' dia(s)</span>' : '<span class="prt-badge warn">aberta · ' + r.dias + ' dia(s)</span>') : r.status === 'RESOLVIDO' ? '<span class="prt-badge ok">resolvida</span>' : '<span class="prt-badge off">cancelada</span>';
  const itens = r.itens.map(i => `${i.quantidade != null ? terQtd(i.quantidade) + ' ' + terH(i.unidade) + ' ' : ''}${terH(i.descricao)}`).join(' · ');
  return `<article class="panel prt-card ${r.status === 'CANCELADO' ? 'prt-cancelado' : ''}"><div class="prt-top"><div><h3>${id} <span class="prt-badge ${CHG_COR[r.tipo]}">${terH(r.tipo_label)}</span> ${stb}</h3>
    <p class="muted">${terD(r.data_chegada)} · <b>${terH(r.fornecedor || 'fornecedor não informado')}</b>${r.pc ? ' · PC ' + r.pc : ''}${r.nf_numero ? ' · NF ' + terH(r.nf_numero) : ''}${r.valor ? ' · ' + chgMoney(r.valor) : ''}${r.origem ? ' · ' + terH(r.origem) : ''}</p>
    ${itens ? `<p>${itens}</p>` : ''}${r.obs ? `<p class="muted"><b>Obs.:</b> ${terH(r.obs)}</p>` : ''}
    <p class="muted">${r.recebido_por ? 'Recebido por ' + terH(r.recebido_por) + ' · ' : ''}prazo ${r.prazo_dias} dia(s)${r.qtd_cobrancas ? ` · ${r.qtd_cobrancas} cobrança(s), última em ${terD(r.ultima_cobranca)}` : ' · ainda não cobrado'}${r.resolucao_label ? ` · <b>${terH(r.resolucao_label)}</b>${r.resolucao_obs ? ' — ' + terH(r.resolucao_obs) : ''}` : ''}${r.motivo_cancelamento ? ' · cancelada: ' + terH(r.motivo_cancelamento) : ''}</p>
    ${(r.fotos || []).length ? `<p>${r.fotos.map(f => `<a href="api.php?action=chegadas_foto&arq=${encodeURIComponent(f)}" target="_blank" rel="noopener"><img src="api.php?action=chegadas_foto&arq=${encodeURIComponent(f)}" alt="Foto" style="height:54px;border-radius:6px;margin-right:4px"></a>`).join('')}</p>` : ''}</div><div class="prt-acoes">${b.join('')}</div></div>
    <details class="prt-hist"><summary>Histórico</summary><ul>${(r.historico || []).map(h => `<li>${terD(h.em)} ${terH(String(h.em).slice(11, 16))} · ${terH(h.por)} — ${terH(h.acao)}</li>`).join('')}</ul></details></article>`;
}
function chgLinha(i = {}) {
  return `<tr class="ter-it"><td><input data-f="descricao" maxlength="160" placeholder="Material (como veio escrito)" value="${terH(i.descricao || '')}"></td><td><input data-f="quantidade" inputmode="decimal" placeholder="Qtd" style="width:70px" value="${i.quantidade != null ? terH(String(i.quantidade).replace('.', ',')) : ''}"></td><td><input data-f="unidade" maxlength="8" placeholder="UN" style="width:54px" value="${terH(i.unidade || '')}"></td><td><button type="button" class="icon-btn" data-del>×</button></td></tr>`;
}
async function chgReduzir(f) {
  const img = await new Promise((ok, err) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => err(new Error('Imagem inválida')); i.src = URL.createObjectURL(f); });
  const k = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
  c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(img.src);
  let q = .82, u = c.toDataURL('image/jpeg', q); while (u.length > 1.9e6 && q > .4) { q -= .12; u = c.toDataURL('image/jpeg', q); } return u;
}
function chgForm(id) {
  const r = id ? CHG.d.registros.find(x => x.id === id) : null, v = k => terH(r ? r[k] ?? '' : ''), novas = [], remover = [];
  const bg = terModal(r ? `Editar ${terH(r.id)}` : 'Registrar chegada', `<p class="muted">Só o tipo é necessário — preencha o que souber. Pode completar depois.</p><div class="rd-ass-campos ter-grid">
    <label>O que aconteceu?<select name="tipo">${Object.entries(CHG.d.tipos).map(([k, t]) => `<option value="${k}" ${r && r.tipo === k ? 'selected' : ''}>${terH(t)}</option>`).join('')}</select></label>
    <label>Data da chegada<input name="data_chegada" type="date" value="${r ? terH(r.data_chegada) : terHoje()}"></label>
    <label>Fornecedor / loja<input name="fornecedor" maxlength="120" value="${v('fornecedor')}"></label>
    <label>Pedido de compra (PC)<input name="pc" inputmode="numeric" value="${v('pc')}"> <button type="button" class="button ghost small" data-bpc>🔎 buscar PC</button></label>
    <label>Nº da NF<input name="nf_numero" maxlength="30" value="${v('nf_numero')}"></label>
    <label>Valor (R$)<input name="valor" inputmode="decimal" value="${r && r.valor ? terH(String(r.valor).replace('.', ',')) : ''}"></label>
    <label>Origem (site, marketplace, loja)<input name="origem" maxlength="100" value="${v('origem')}" placeholder="Ex.: Mercado Livre, Amazon, Kabum"></label>
    <label>Quem recebeu<input name="recebido_por" maxlength="80" value="${v('recebido_por')}"></label>
    <label>Transportadora / entregador<input name="transportadora" maxlength="80" value="${v('transportadora')}"></label>
    <label>Contato do fornecedor<input name="contato" maxlength="120" value="${v('contato')}" placeholder="Telefone, e-mail ou nome"></label>
    <label>Prazo para resolver (dias)<input name="prazo_dias" type="number" min="1" max="90" value="${r ? r.prazo_dias : 5}"></label></div>
    <div id="chgPcRes"></div>
    <h4 style="margin:10px 0 6px">Materiais que chegaram <small class="muted">(opcional)</small></h4><div class="prt-scroll"><table class="ter-itens"><tbody id="chgItens">${((r && r.itens.length) ? r.itens : [{}, {}]).map(chgLinha).join('')}</tbody></table></div><button type="button" class="button ghost" data-add>＋ Material</button>
    <h4 style="margin:10px 0 6px">Fotos <small class="muted">(material, etiqueta, volume)</small></h4><div id="chgFotos">${(r ? r.fotos : []).map(f => `<span class="chg-f" data-f="${terH(f)}"><img src="api.php?action=chegadas_foto&arq=${encodeURIComponent(f)}" style="height:54px;border-radius:6px"><a href="#" data-rm>remover</a> </span>`).join('')}</div>
    <label class="button ghost small"><input type="file" accept="image/*" capture="environment" multiple hidden id="chgFile">📷 Fotografar / anexar</label><span id="chgNovas" class="muted"></span>
    <div class="rd-ass-campos"><label>Observações<input name="obs" maxlength="600" value="${v('obs')}"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-salvar>Salvar</button></div>`, 900);
  const tb = bg.querySelector('#chgItens'), liga = () => tb.querySelectorAll('[data-del]').forEach(b => b.onclick = () => b.closest('tr').remove());
  liga(); bg.querySelector('[data-add]').onclick = () => { tb.insertAdjacentHTML('beforeend', chgLinha()); liga(); };
  bg.querySelectorAll('[data-rm]').forEach(a => a.onclick = e => { e.preventDefault(); const s = a.closest('.chg-f'); remover.push(s.dataset.f); s.remove(); });
  bg.querySelector('#chgFile').onchange = async e => { for (const f of e.target.files) { try { novas.push(await chgReduzir(f)); } catch (er) { terToast(er.message, true); } } bg.querySelector('#chgNovas').textContent = novas.length ? `${novas.length} foto(s) nova(s)` : ''; e.target.value = ''; };
  bg.querySelector('[data-bpc]').onclick = async () => {
    const q = [terVal(bg, 'pc'), terVal(bg, 'nf_numero'), terVal(bg, 'fornecedor')].filter(Boolean).join(' '), box = bg.querySelector('#chgPcRes');
    if (!q) return terToast('Digite o PC, a NF ou o fornecedor para buscar.', true);
    try {
      const ps = (await terApi('chegadas_pcs&q=' + encodeURIComponent(q))).pedidos;
      box.innerHTML = ps.length ? `<div class="panel" style="padding:8px"><b>Pedidos encontrados</b> — clique para vincular:${ps.map(p => `<div><a href="#" data-pc="${p.pc}" data-f="${terH(p.fornecedor)}"><b>PC ${p.pc}</b> · ${terH(p.fornecedor)} · ${terH(p.material)}</a> <small class="muted">${p.recebido ? '✔ já consta recebido' : 'ainda não recebido'}${p.tem_nf ? ' · com NF ' + terH(p.nf) : ' · sem NF no sistema'}</small></div>`).join('')}</div>` : '<small class="muted">Nenhum pedido encontrado.</small>';
      box.querySelectorAll('[data-pc]').forEach(a => a.onclick = e => { e.preventDefault(); bg.querySelector('[name=pc]').value = a.dataset.pc; if (!terVal(bg, 'fornecedor')) bg.querySelector('[name=fornecedor]').value = a.dataset.f; box.innerHTML = '<small class="muted">PC ' + a.dataset.pc + ' vinculado.</small>'; });
    } catch (er) { terToast(er.message, true); }
  };
  bg.querySelector('[data-salvar]').onclick = async e => {
    const btn = e.target; btn.disabled = true;
    const o = { id: r ? r.id : '', fotos_novas: novas, fotos_remover: remover, itens: [...tb.querySelectorAll('.ter-it')].map(tr => Object.fromEntries([...tr.querySelectorAll('[data-f]')].map(i => [i.dataset.f, i.value]))) };
    ['tipo', 'data_chegada', 'fornecedor', 'pc', 'nf_numero', 'valor', 'origem', 'recebido_por', 'transportadora', 'contato', 'prazo_dias', 'obs'].forEach(k => o[k] = terVal(bg, k));
    try { const x = (await terPost('chegadas_salvar', o)).registro; bg.remove(); terToast(`${x.id} salva`); await chegadasInit(); } catch (er) { terToast(er.message, true); btn.disabled = false; }
  };
}
function chgCobrar(id) {
  const r = CHG.d.registros.find(x => x.id === id);
  const txt = `Olá! Aqui é do almoxarifado da Drilling do Brasil. ` + ({
    MATERIAL_SEM_NF: `Recebemos em ${terD(r.data_chegada)} o material${r.itens.length ? ' (' + r.itens.map(i => (i.quantidade != null ? i.quantidade + ' ' : '') + i.descricao).join('; ') + ')' : ''}${r.pc ? ' do pedido de compra ' + r.pc : ''}, porém sem a nota fiscal. Por favor, envie a NF o quanto antes.`,
    NF_SEM_MATERIAL: `Recebemos a NF${r.nf_numero ? ' ' + r.nf_numero : ''}${r.pc ? ' (pedido ' + r.pc + ')' : ''}, mas o material não consta como entregue no nosso almoxarifado. Poderia confirmar data, transportadora e comprovante de entrega?`,
    ONLINE_SEM_NF: `Recebemos a compra${r.origem ? ' feita em ' + r.origem : ''} em ${terD(r.data_chegada)}, porém sem a nota fiscal. Por favor, envie a NF (com o CNPJ da Drilling do Brasil).`,
    OUTRO: 'Precisamos regularizar uma divergência na entrega recebida.' }[r.tipo]) + ` Referência interna: ${r.id}. Obrigado!`;
  const bg = terModal('Cobrar fornecedor', `<p><b>${terH(r.id)}</b> · ${terH(r.fornecedor || '')} ${r.contato ? '· ' + terH(r.contato) : ''}</p><label>Mensagem (copie e envie por WhatsApp ou e-mail)<textarea id="chgTxt" rows="6" style="width:100%">${terH(txt)}</textarea></label>
    <div class="rd-ass-campos"><label>Como cobrou?<select name="canal">${['WhatsApp', 'E-mail', 'Telefone', 'Pessoalmente', 'Portal / chat da loja'].map(c => `<option>${c}</option>`).join('')}</select></label><label>Observação<input name="obs" maxlength="200" placeholder="Ex.: prometeu enviar até sexta"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-copy>Copiar mensagem</button><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Registrar cobrança</button></div>`, 640);
  bg.querySelector('[data-copy]').onclick = () => { navigator.clipboard?.writeText(terEl('chgTxt').value); terToast('Mensagem copiada'); };
  bg.querySelector('[data-ok]').onclick = async () => { try { await terPost('chegadas_acao', { id, acao: 'cobrar', canal: terVal(bg, 'canal'), obs: terVal(bg, 'obs') }); bg.remove(); terToast('Cobrança registrada'); chegadasInit(); } catch (e) { terToast(e.message, true); } };
}
function chgResolver(id) {
  const r = CHG.d.registros.find(x => x.id === id), pad = r.tipo === 'NF_SEM_MATERIAL' ? 'MATERIAL_RECEBIDO' : 'NF_RECEBIDA';
  const bg = terModal('Resolver pendência', `<p><b>${terH(id)}</b> · ${terH(r.tipo_label)}</p><div class="rd-ass-campos"><label>Como foi resolvida?<select name="resolucao">${Object.entries(CHG.d.resolucoes).map(([k, t]) => `<option value="${k}" ${k === pad ? 'selected' : ''}>${terH(t)}</option>`).join('')}</select></label><label>Nº da NF (se chegou)<input name="nf_numero" maxlength="30" value="${terH(r.nf_numero || '')}"></label><label>Observação<input name="obs" maxlength="300"></label></div>
    <p class="muted">Se a NF chegou, lance/atualize o pedido normalmente em Pedidos; aqui você só encerra a pendência.</p><div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Encerrar pendência</button></div>`, 560);
  bg.querySelector('[data-ok]').onclick = async () => { try { await terPost('chegadas_acao', { id, acao: 'resolver', resolucao: terVal(bg, 'resolucao'), nf_numero: terVal(bg, 'nf_numero'), obs: terVal(bg, 'obs') }); bg.remove(); terToast('Pendência resolvida'); chegadasInit(); } catch (e) { terToast(e.message, true); } };
}
function chgMotivo(id, acao, titulo, opcional) {
  const bg = terModal(titulo, `<p><b>${terH(id)}</b></p><div class="rd-ass-campos"><label>Motivo${opcional ? ' (opcional)' : ''}<input name="motivo" maxlength="200"></label></div><div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar</button></div>`, 460);
  bg.querySelector('[data-ok]').onclick = async () => { try { await terPost('chegadas_acao', { id, acao, motivo: terVal(bg, 'motivo') }); bg.remove(); terToast('Feito'); chegadasInit(); } catch (e) { terToast(e.message, true); } };
}
