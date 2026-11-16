/* Recebimento Drilling — Controle de obras (v3.28.0)
 * Envios de material para cada obra: nota (PDF/foto) + fotos do carregamento, e o que o sistema já mandou. */
'use strict';
const OB = { painel: null, atual: null, dados: null, aba: 'envios' };
const obEl = id => document.getElementById(id);
const obH = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const obD = s => s ? String(s).slice(0, 10).split('-').reverse().join('/') : '—';
const obN = v => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
const obR = v => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const obPode = op => typeof rdaPermite !== 'function' || rdaPermite('estoque', op);
const obToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));
async function obFetch(action, opts = {}) {
  if (typeof rdaFetch === 'function') return rdaFetch(action, opts);
  const r = await fetch('api.php?action=' + action, { credentials: 'same-origin', ...opts }); const d = await r.json(); if (!r.ok || !d.ok) throw Error(d.error || r.status); return d;
}
const obGet = (a, q) => obFetch(a, { query: q });
const obPost = (a, b) => obFetch(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) });

async function obrasInit() {
  if (!obEl('page-obras')) return;
  document.querySelectorAll('#page-obras .est-head-actions .button').forEach(b => { b.hidden = !obPode('criar'); });
  try {
    OB.painel = await obGet('obras_painel');
    const t = OB.painel.totais;
    obEl('obKpis').innerHTML = [['Obras com envio', t.obras_com_envio], ['Envios', t.envios], ['Notas anexadas', t.notas], ['Fotos', t.fotos], ['Valor das notas', obR(t.valor)]]
      .map(([l, v]) => `<div class="imp-kpi"><span>${l}</span><strong>${typeof v === 'number' ? obN(v) : v}</strong></div>`).join('');
    obRenderLista();
    if (OB.atual) obAbrir(OB.atual, true);
  } catch (e) { obEl('obLista').innerHTML = `<div class="empty">${obH(e.message)}</div>`; }
}
function obRenderLista() {
  if (!OB.painel) return;
  const q = (obEl('obBusca').value || '').toLowerCase();
  const l = OB.painel.obras.filter(o => !q || (o.nome + ' ' + o.cidade).toLowerCase().includes(q));
  obEl('obLista').innerHTML = l.map(o => `<button type="button" class="ob-item ${OB.atual === o.id ? 'on' : ''}" onclick="obAbrir('${obH(o.id)}')">
      <strong>${obH(o.nome)}</strong><small>${obH(o.cidade || '')}</small>
      <span class="ob-item-n">${o.envios ? `${o.envios} envio(s) · ${o.notas} nota(s) · ${o.fotos} foto(s)<br>último ${obD(o.ultimo)}` : '<i>sem envios</i>'}</span></button>`).join('') || '<div class="empty">Nenhuma obra.</div>';
}

async function obAbrir(id, silencioso) {
  OB.atual = id; obRenderLista();
  if (!silencioso) obEl('obDetalhe').innerHTML = '<div class="empty">Carregando…</div>';
  try { OB.dados = await obGet('obras_obra', { id }); obRenderObra(); if (window.innerWidth < 900 && !silencioso) obEl('obDetalhe').scrollIntoView({ behavior: 'smooth' }); }
  catch (e) { obEl('obDetalhe').innerHTML = `<div class="empty">${obH(e.message)}</div>`; }
}
function obRenderObra() {
  const d = OB.dados, o = d.obra, s = d.sistema;
  const valor = d.envios.reduce((a, e) => a + (e.valor || 0), 0);
  obEl('obDetalhe').innerHTML = `<div class="ob-head"><div><h2>${obH(o.nome)}</h2><small>${obH(o.cidade || '')}</small></div>
      ${obPode('criar') ? `<button class="button primary small" onclick="obNovoEnvio('${obH(o.id)}')">＋ Novo envio para esta obra</button>` : ''}</div>
    <div class="ob-mini"><span><b>${d.envios.length}</b> envios</span><span><b>${d.envios.reduce((a, e) => a + e.notas.length, 0)}</b> notas</span><span><b>${d.envios.reduce((a, e) => a + e.fotos.length, 0)}</b> fotos</span>${valor ? `<span><b>${obR(valor)}</b> em notas</span>` : ''}</div>
    <div class="imp-filtros"><button class="est-tab ${OB.aba === 'envios' ? 'active' : ''}" onclick="OB.aba='envios';obRenderObra()">Envios (${d.envios.length})</button>
      <button class="est-tab ${OB.aba === 'sistema' ? 'active' : ''}" onclick="OB.aba='sistema';obRenderObra()">Registrado no sistema (${s.estoque.length + s.ferramentas.length + s.locacoes.length})</button></div>
    ${OB.aba === 'envios' ? obRenderEnvios(d) : obRenderSistema(s, d.chaves)}`;
}
function obThumb(a, envId, lista) {
  const pdf = a.mime === 'application/pdf';
  return `<a class="ob-thumb ${pdf ? 'pdf' : ''}" href="${obH(a.url)}" target="_blank" rel="noopener" title="${obH(a.nome)}">${pdf ? `<span>PDF</span><small>${obH(a.nome)}</small>` : `<img src="${obH(a.url)}" alt="" loading="lazy">`}
    ${obPode('editar') ? `<button type="button" class="ob-thumb-x" title="Remover" onclick="event.preventDefault();event.stopPropagation();obRemoverArquivo('${obH(envId)}','${lista}',${a.idx})">✕</button>` : ''}</a>`;
}
function obRenderEnvios(d) {
  if (!d.envios.length) return `<div class="ob-vazio"><span>📦</span><strong>Nenhum envio registrado para esta obra</strong><small>Clique em "Novo envio" e anexe a nota e as fotos.</small></div>`;
  return d.envios.map(e => `<article class="ob-envio">
    <div class="ob-envio-top"><div><span class="ob-data">${obD(e.data)}</span> <span class="est-badge">${obH(e.tipo_label)}${e.numero ? ' ' + obH(e.numero) : ''}</span>${e.pc ? ` <span class="est-badge">PC ${obH(e.pc)}</span>` : ''}${e.valor ? ` <b>${obR(e.valor)}</b>` : ''}
      <small class="est-sub">${obH(e.id)}${e.transporte ? ' · ' + obH(e.transporte) : ''}${e.responsavel ? ' · resp. ' + obH(e.responsavel) : ''} · lançado por ${obH(e.criado_por)}</small></div>
      <div class="ob-envio-acoes">${obPode('criar') ? `<button class="button ghost small" onclick="obNovoEnvio('${obH(e.obra_id)}','${obH(e.id)}')">Editar / anexar</button>` : ''}${obPode('aprovar') ? `<button class="button ghost small" onclick="obExcluir('${obH(e.id)}')">Excluir</button>` : ''}</div></div>
    ${e.itens ? `<pre class="ob-itens">${obH(e.itens)}</pre>` : ''}${e.observacao ? `<p class="ob-obs">${obH(e.observacao)}</p>` : ''}
    <div class="ob-arqs"><div><h5>Notas (${e.notas.length})</h5><div class="ob-thumbs">${e.notas.map(a => obThumb(a, e.id, 'notas')).join('') || '<small class="muted">sem nota anexada</small>'}</div></div>
      <div><h5>Fotos (${e.fotos.length})</h5><div class="ob-thumbs">${e.fotos.map(a => obThumb(a, e.id, 'fotos')).join('') || '<small class="muted">sem fotos</small>'}</div></div></div>
  </article>`).join('');
}
function obRenderSistema(s, ch) {
  const est = s.estoque.length ? `<div class="est-table-scroll"><table class="orders-table"><thead><tr><th>Data</th><th>Material</th><th>Qtd</th><th>Documento</th><th>Solicitante</th></tr></thead><tbody>
    ${s.estoque.map(m => `<tr><td>${obD(m.em)}</td><td>${obH(m.descricao)}<small class="est-sub">${obH(m.codigo)}${m.importado ? ' · Importados' : ''}${m.os ? ' · OS ' + obH(m.os) : ''}</small></td><td>${obN(m.quantidade)} ${obH(m.unidade)}</td><td>${obH(m.documento)}</td><td>${obH(m.solicitante)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Nenhuma saída de estoque para esta obra.</p>';
  return `<h4>Saídas de material (Estoque e Importados)</h4>${est}
    <h4>Ferramentas emprestadas</h4>${s.ferramentas.length ? '<ul class="ob-ul">' + s.ferramentas.map(f => `<li><b>${obD(f.em)}</b> · ${obH(f.itens.join(', '))} · ${obH(f.responsavel)} <span class="est-badge">${obH(f.status)}</span></li>`).join('') + '</ul>' : '<p class="muted">Nenhuma.</p>'}
    <h4>Equipamentos locados nesta obra</h4>${s.locacoes.length ? '<ul class="ob-ul">' + s.locacoes.map(l => `<li>${obH(l.equipamento)}${l.patrimonio ? ' · PAT ' + obH(l.patrimonio) : ''} · ${obH(l.fornecedor)} · desde ${obD(l.desde)}</li>`).join('') + '</ul>' : '<p class="muted">Nenhum.</p>'}
    <p class="ob-nota">Identificado pelo nome da obra nos lançamentos (${obH(ch.join(', '))}).</p>`;
}

/* ---------- novo envio / edição + anexos ---------- */
async function obComprimir(file) {
  if (!file.type.startsWith('image/') || file.size < 600 * 1024) return file;
  try {
    const img = await createImageBitmap(file); const k = Math.min(1, 2000 / Math.max(img.width, img.height));
    const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    const b = await new Promise(r => c.toBlob(r, 'image/jpeg', .85));
    return b && b.size < file.size ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
  } catch (e) { return file; }
}
async function obEnviarArquivo(envio, lista, file) {
  const fd = new FormData(); fd.append('envio', envio); fd.append('lista', lista); fd.append('arquivo', await obComprimir(file), file.name);
  return obFetch('obras_arquivo_enviar', { method: 'POST', body: fd });
}
function obNovoEnvio(obraId, envioId) {
  if (!OB.painel) return obToast('Carregando obras…', true);
  const e = envioId ? OB.dados.envios.find(x => x.id === envioId) : null;
  const hoje = new Date().toISOString().slice(0, 10);
  const bg = document.createElement('div'); bg.className = 'modal-bg on';
  bg.innerHTML = `<div class="modal ob-modal"><div class="modal-head"><h2>${e ? 'Editar envio ' + obH(e.id) : 'Novo envio de material'}</h2><button class="icon-btn" data-x>✕</button></div>
    <form class="modal-body ob-form">
      <label class="full">Obra *<select name="obra_id" required><option value="">Escolha a obra…</option>${OB.painel.obras.map(o => `<option value="${obH(o.id)}" ${(e ? e.obra_id : obraId) === o.id ? 'selected' : ''}>${obH(o.nome)}${o.cidade ? ' — ' + obH(o.cidade) : ''}</option>`).join('')}</select></label>
      <div class="full ob-pc"><label>Pedido de compra (PC) <small>— opcional: digite o número para trazer os dados</small>
          <span class="ob-pc-linha"><input data-pcnum inputmode="numeric" maxlength="8" autocomplete="off" placeholder="Ex.: 1000" value="${e && e.pc ? obH(e.pc) : ''}"><button type="button" class="button ghost small" data-pcbuscar>Buscar PC</button><button type="button" class="button ghost small" data-pclimpar hidden>Remover PC</button></span></label>
        <div class="ob-pc-box" data-pcbox hidden></div></div>
      <label>Data do envio *<input name="data" type="date" required value="${obH(e ? e.data : hoje)}"></label>
      <label>Documento<select name="tipo">${Object.entries(OB.painel.tipos).map(([k, v]) => `<option value="${k}" ${(e ? e.tipo : 'NF_REMESSA') === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
      <label>Número da nota / romaneio<input name="numero" value="${obH(e ? e.numero : '')}" placeholder="Ex.: 13685"></label>
      <label>Valor da nota (R$)<input name="valor" inputmode="decimal" value="${e && e.valor ? obH(String(e.valor).replace('.', ',')) : ''}" placeholder="opcional"></label>
      <label>Transporte<input name="transporte" value="${obH(e ? e.transporte : '')}" placeholder="Ex.: caminhão próprio, transportadora X, placa…"></label>
      <label>Responsável pelo envio<input name="responsavel" value="${obH(e ? e.responsavel : '')}"></label>
      <label class="full">Materiais enviados (um por linha)<textarea name="itens" rows="4" placeholder="Ex.:&#10;20 SC POLÍMERO GMUD&#10;2 UN CAÇAMBA Ø1300">${obH(e ? e.itens : '')}</textarea></label>
      <label class="full">Observação<input name="observacao" value="${obH(e ? e.observacao : '')}"></label>
      <div class="full ob-up"><div><h5>📄 Notas (PDF ou foto)</h5><label class="ob-drop"><input type="file" data-lista="notas" accept="application/pdf,image/*" multiple hidden><span>＋ Anexar nota</span><small>PDF, JPG ou PNG · até 15 MB cada</small></label><ul data-fila="notas"></ul>${e ? `<div class="ob-thumbs">${e.notas.map(a => obThumb(a, e.id, 'notas')).join('')}</div>` : ''}</div>
        <div><h5>📷 Fotos do material / carregamento</h5><label class="ob-drop"><input type="file" data-lista="fotos" accept="image/*" multiple hidden><span>＋ Adicionar fotos</span><small>Câmera ou galeria · várias de uma vez</small></label><ul data-fila="fotos"></ul>${e ? `<div class="ob-thumbs">${e.fotos.map(a => obThumb(a, e.id, 'fotos')).join('')}</div>` : ''}</div></div>
      <p class="ob-nota full" data-status></p>
      <div class="modal-actions full"><button type="button" class="button ghost" data-x>Cancelar</button><button class="button primary" type="submit">Salvar envio</button></div>
    </form></div>`;
  document.body.appendChild(bg);
  const fila = { notas: [], fotos: [] };
  const desenhar = lista => { bg.querySelector(`[data-fila="${lista}"]`).innerHTML = fila[lista].map((f, i) => `<li>${f.type === 'application/pdf' ? '📄' : '🖼'} ${obH(f.name)} <small>${(f.size / 1048576).toFixed(1)} MB</small> <button type="button" data-rm="${lista}:${i}">✕</button></li>`).join(''); };
  /* v3.33.0 — origem em pedido de compra. Só preenche o formulário; NÃO baixa estoque (a baixa é na Saída de material). */
  const pcNum = bg.querySelector('[data-pcnum]'), pcBox = bg.querySelector('[data-pcbox]'), pcLimpar = bg.querySelector('[data-pclimpar]');
  const campo = n => bg.querySelector(`[name="${n}"]`); let pcSel = null, itensManual = false;
  campo('itens').addEventListener('input', () => { itensManual = true; });
  const pcTexto = () => pcSel ? pcSel.info.linhas.filter(l => l.marcado && Number(l.qtd) > 0).map(l => `${obN(l.qtd)} ${l.unidade} ${l.descricao}`).join('\n') : '';
  const pcAtualizarTexto = () => { if (!itensManual) campo('itens').value = pcTexto(); };
  function pcDesenhar() {
    const i = pcSel.info, nova = !e || e.pc !== i.pc;
    const avisos = [];
    if (i.mensagem) avisos.push(i.mensagem);
    if (i.envios.length) avisos.push('Já houve envio deste PC: ' + i.envios.map(v => `${v.id} (${obD(v.data)}${v.obra_nome ? ' · ' + v.obra_nome : ''})`).join('; ') + '. As quantidades abaixo já descontam o que foi enviado.');
    const obraSel = campo('obra_id').value;
    if (i.destino && i.destino.obra_id && obraSel && i.destino.obra_id !== obraSel) avisos.push('Atenção: este PC tem destino previsto na obra "' + (i.destino.obra || i.destino.obra_id) + '", diferente da obra escolhida.');
    pcBox.innerHTML = `<div class="ob-pc-cab"><span><b>PC ${obH(i.pc)}</b> — ${obH(i.fornecedor)}${i.numero_nf ? ' · NF compra ' + obH(i.numero_nf) : ''}</span>${i.solicitante ? '<small class="est-sub">Solicitante: ' + obH(i.solicitante) + '</small>' : ''}</div>
      ${avisos.map(a => `<p class="ob-pc-aviso">⚠ ${obH(a)}</p>`).join('')}
      <div class="ob-pc-linhas">${i.linhas.map((l, k) => `<div class="ob-pc-linha-item ${l.disponivel <= 0 && !l.marcado ? 'off' : ''}"><label class="ob-pc-chk"><input type="checkbox" data-k="${k}" ${l.marcado ? 'checked' : ''}><span><b>${obH(l.descricao)}</b><small class="est-sub">${l.sku ? 'SKU ' + obH(l.sku) + ' · ' : ''}${i.recebido ? 'recebido' : 'pedido'} ${obN(l.base)} · já enviado ${obN(l.ja_enviada)} · a enviar até ${obN(l.disponivel)} ${obH(l.unidade)}</small></span></label>
        <input type="number" min="0" step="any" data-q="${k}" value="${l.marcado ? obH(l.qtd) : ''}" ${l.marcado ? '' : 'disabled'} aria-label="Quantidade"></div>`).join('')}</div>
      ${i.fotos.length ? `<p class="ob-pc-sub">Fotos do recebimento deste PC <small>(marque para anexar uma cópia ao envio)</small></p><div class="ob-pc-fotos">${i.fotos.map(f => `<label><input type="checkbox" data-foto="${obH(f)}" ${pcSel.fotos.has(f) ? 'checked' : ''}><img src="${obH(f)}" alt="Foto do recebimento" loading="lazy"></label>`).join('')}</div>` : ''}
      <p class="ob-nota">Este envio é só um registro (itens, nota e fotos). <b>Não dá baixa no estoque</b> — se o material precisa sair do estoque, use "Saída de material".</p>`;
    pcBox.hidden = false; pcLimpar.hidden = false;
    pcBox.querySelectorAll('[data-k]').forEach(c => c.onchange = () => { const l = i.linhas[c.dataset.k]; l.marcado = c.checked; if (c.checked && !(Number(l.qtd) > 0)) l.qtd = l.disponivel; pcDesenhar(); pcAtualizarTexto(); });
    pcBox.querySelectorAll('[data-q]').forEach(c => c.oninput = () => { i.linhas[c.dataset.q].qtd = c.value; pcAtualizarTexto(); });
    pcBox.querySelectorAll('[data-foto]').forEach(c => c.onchange = () => { c.checked ? pcSel.fotos.add(c.dataset.foto) : pcSel.fotos.delete(c.dataset.foto); });
    return nova;
  }
  async function pcCarregar() {
    const n = Number(String(pcNum.value).replace(/\D/g, ''));
    if (!(n > 0)) return obToast('Digite o número do PC.', true);
    pcBox.hidden = false; pcBox.innerHTML = '<p class="muted">Buscando o PC…</p>';
    try {
      const r = await obGet('obras_pc', { pc: n, ignorar: e ? e.id : '' }), i = r.pedido;
      if (i.status === 'nao_encontrado') { pcSel = null; pcLimpar.hidden = true; pcBox.innerHTML = `<p class="ob-pc-aviso">⚠ ${obH(i.mensagem)}</p>`; return; }
      const antes = e && e.pc === i.pc ? Object.fromEntries((e.linhas_pc || []).map(l => [l.linha_id, l.quantidade])) : null;
      i.linhas.forEach(l => { const q = antes ? antes[l.linha_id] : null; l.marcado = antes ? q > 0 : l.disponivel > 0; l.qtd = antes ? (q || '') : (l.disponivel > 0 ? l.disponivel : ''); });
      pcSel = { info: i, fotos: new Set() };
      pcDesenhar(); pcAtualizarTexto();
      // preenche só o que está vazio (nunca sobrescreve o que o operador digitou)
      const obra = campo('obra_id');
      if (!obra.value && i.destino && i.destino.obra_id && [...obra.options].some(o => o.value === i.destino.obra_id)) { obra.value = i.destino.obra_id; pcDesenhar(); }
      if (!campo('responsavel').value && i.solicitante) campo('responsavel').value = i.solicitante;
      if (!campo('observacao').value) campo('observacao').value = `PC ${i.pc} · ${i.fornecedor}`.slice(0, 180);
    } catch (err) { pcBox.innerHTML = `<p class="ob-pc-aviso">⚠ ${obH(err.message)}</p>`; }
  }
  bg.querySelector('[data-pcbuscar]').onclick = pcCarregar;
  pcNum.onkeydown = ev => { if (ev.key === 'Enter') { ev.preventDefault(); pcCarregar(); } };
  campo('obra_id').addEventListener('change', () => { if (pcSel) pcDesenhar(); });
  pcLimpar.onclick = () => { pcSel = null; pcNum.value = ''; pcBox.hidden = true; pcBox.innerHTML = ''; pcLimpar.hidden = true; if (!itensManual) campo('itens').value = ''; };
  if (e && e.pc) { itensManual = true; pcCarregar(); }
  bg.querySelectorAll('input[type=file]').forEach(inp => inp.onchange = () => {
    const l = inp.dataset.lista;
    for (const f of inp.files) { if (f.size > 15 * 1048576) { obToast(`${f.name}: maior que 15 MB.`, true); continue; } fila[l].push(f); }
    inp.value = ''; desenhar(l);
  });
  bg.addEventListener('click', ev => { const b = ev.target.closest('[data-rm]'); if (b) { const [l, i] = b.dataset.rm.split(':'); fila[l].splice(Number(i), 1); desenhar(l); } });
  bg.querySelectorAll('[data-x]').forEach(b => b.onclick = () => { if ((fila.notas.length || fila.fotos.length) && !confirm('Há arquivos ainda não enviados. Sair mesmo assim?')) return; bg.remove(); });
  bg.querySelector('form').onsubmit = async ev => {
    ev.preventDefault();
    const f = ev.target, btn = f.querySelector('[type=submit]'), st = f.querySelector('[data-status]');
    const dados = Object.fromEntries(new FormData(f)); dados.id = e ? e.id : '';
    if (pcNum.value.trim() && !pcSel) return obToast('Clique em "Buscar PC" para trazer os dados do pedido, ou apague o número.', true);
    if (pcSel) {
      const marc = pcSel.info.linhas.filter(l => l.marcado && Number(l.qtd) > 0);
      const acima = marc.filter(l => Number(l.qtd) > l.disponivel + 0.00001);
      if (acima.length && !confirm('Quantidade acima do que consta disponível neste PC:\n' + acima.map(l => `• ${l.descricao}: ${obN(l.qtd)} (disponível ${obN(l.disponivel)})`).join('\n') + '\n\nSalvar mesmo assim?')) return;
      dados.pc = pcSel.info.pc; dados.linhas_pc = marc.map(l => ({ linha_id: l.linha_id, quantidade: Number(l.qtd) })); dados.fotos_pc = [...pcSel.fotos];
    } else { dados.pc = 0; dados.linhas_pc = []; }
    btn.disabled = true; btn.textContent = 'Salvando…';
    try {
      const r = await obPost('obras_envio_salvar', dados);
      const id = r.envio.id, total = fila.notas.length + fila.fotos.length; let n = 0, falhas = [];
      for (const l of ['notas', 'fotos']) for (const arq of fila[l]) {
        st.textContent = `Enviando arquivo ${++n} de ${total}: ${arq.name}…`;
        try { await obEnviarArquivo(id, l, arq); } catch (err) { falhas.push(arq.name + ' (' + err.message + ')'); }
      }
      fila.notas = []; fila.fotos = [];
      bg.remove();
      obToast(falhas.length ? `Envio salvo, mas ${falhas.length} arquivo(s) falharam: ${falhas.join('; ')}` : `Envio ${id} salvo${total ? ` com ${total} arquivo(s)` : ''}.`, !!falhas.length);
      OB.atual = dados.obra_id; OB.aba = 'envios'; await obrasInit();
    } catch (err) { obToast(err.message, true); btn.disabled = false; btn.textContent = 'Salvar envio'; st.textContent = ''; }
  };
}
async function obRemoverArquivo(envio, lista, i) {
  if (!confirm('Remover este arquivo do envio? (ele continua guardado no servidor por segurança)')) return;
  try { await obPost('obras_arquivo_remover', { envio, lista, i }); document.querySelectorAll('.ob-modal').forEach(m => m.closest('.modal-bg').remove()); await obrasInit(); obToast('Arquivo removido.'); }
  catch (e) { obToast(e.message, true); }
}
async function obExcluir(id) {
  const motivo = prompt('Motivo da exclusão do envio ' + id + ' (obrigatório):'); if (!motivo) return;
  try { await obPost('obras_envio_excluir', { id, motivo }); obToast('Envio excluído.'); await obrasInit(); } catch (e) { obToast(e.message, true); }
}
async function obNovaObra() {
  const nome = prompt('Nome da obra:'); if (!nome) return;
  const cidade = prompt('Cidade/UF (opcional):') || '';
  try { const r = await obPost('obras_nova', { nome, cidade }); OB.atual = r.obra.id; await obrasInit(); obToast('Obra criada.'); } catch (e) { obToast(e.message, true); }
}
