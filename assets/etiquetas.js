/* Recebimento Drilling — Etiquetas e Identificação de Materiais v3.6.0
 * Tudo que é oficial (catálogo, fila, histórico) vive no SERVIDOR. O navegador só guarda
 * preferências de layout (margens, zoom). Etiqueta nunca movimenta estoque.
 */
'use strict';
const ETQ = { cfg: null, fila: [], rev: 0, sel: new Set(), res: [], resSel: new Set(), tab: 'gerar', modo: 'todas',
  imp: null, jobs: null, carregado: false, salvando: null, layout: { dx: 0, dy: 0, zoom: 0.55, barras: true, zoom_auto: true } };
const etqHoje = () => new Date().toLocaleDateString('pt-BR');
/* DATA = dia da impressão, sempre. Só vale a data digitada se a pessoa editou o campo à mão. */
const etqData = e => (e.data_manual && e.data) ? e.data : etqHoje();
const etqEl = id => document.getElementById(id);
const etqH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ETQ_LOGO = 'assets/icons/logo-drilling.png';
try { Object.assign(ETQ.layout, JSON.parse(localStorage.getItem('etq-layout') || '{}')); } catch {}

async function etqApi(action, opts = {}) {
  const { query, ...init } = opts;
  const qs = query ? '&' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v != null)).toString() : '';
  const headers = { 'X-Client-Id': typeof notificationClientId !== 'undefined' ? notificationClientId : 'etiquetas', ...(init.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const res = await fetch(`api.php?action=${encodeURIComponent(action)}${qs}`, { cache: 'no-store', credentials: 'same-origin', ...init, headers });
  let d; try { d = await res.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!res.ok || !d.ok) { const e = new Error(d.error || `Erro ${res.status}`); e.status = res.status; throw e; }
  return d;
}
const etqPost = (a, b) => etqApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
function etqMsg(t, tipo = '') { const m = etqEl('etqMsg'); if (!m) return; m.textContent = t; m.className = 'etq-msg ' + tipo; m.hidden = !t; }
const can = p => !!(ETQ.cfg && ETQ.cfg.permissoes && ETQ.cfg.permissoes[p]);

/* ---------------- Code 128 (Code B) com o SKU oficial ---------------- */
const C128 = '212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112'.split(' ');
function etqBarsSvg(text) {
  const s = String(text || '').replace(/[^\x20-\x7e]/g, '');
  if (!s) return '';
  const codes = [104]; let sum = 104;
  [...s].forEach((c, i) => { const v = c.charCodeAt(0) - 32; codes.push(v); sum += v * (i + 1); });
  codes.push(sum % 103, 106);
  let x = 10, rects = '';   // 10 módulos de zona silenciosa
  codes.forEach(v => { const p = C128[v]; for (let i = 0; i < p.length; i++) { const w = +p[i]; if (i % 2 === 0) rects += `<rect x="${x}" y="0" width="${w}" height="40"/>`; x += w; } });
  x += 10;
  return `<svg class="etq-bars" viewBox="0 0 ${x} 40" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-label="Código de barras ${etqH(s)}"><g fill="#000">${rects}</g></svg>`;
}

/* ---------------- Etiqueta / folha ---------------- */
function etqClasseItem(t) { return t.length > 75 ? ' texto-longo' : t.length > 42 ? ' texto-medio' : ''; }
function etqCampo(valor, rot, campo, id, editavel, multi) {
  if (!editavel) return etqH(valor);
  const at = `data-id="${id}" data-campo="${campo}" aria-label="${rot}" spellcheck="false"`;
  return (multi ? `<textarea class="etiqueta-input no-print" rows="1" ${at}>${etqH(valor)}</textarea>` : `<input class="etiqueta-input no-print" ${at} value="${etqH(valor)}">`) + `<span class="so-impressao">${etqH(valor)}</span>`;
}
function etqEtiquetaHtml(e, n, editavel) {
  if (!e) return '<div class="etiqueta"><img class="etiqueta-logo" src="' + ETQ_LOGO + '" alt=""></div>';
  const sel = editavel && ETQ.sel.has(e.id);
  return `<div class="etiqueta${sel ? ' etiqueta-selecionada' : ''}">
    <img class="etiqueta-logo" src="${ETQ_LOGO}" alt="Drilling do Brasil">
    <span class="etiqueta-label lbl-almox">ALMOXARIFADO:</span><div class="etiqueta-box box-almox">${etqCampo(e.almoxarifado, 'Almoxarifado', 'almoxarifado', e.id, editavel)}</div>
    <span class="etiqueta-label lbl-item">ITEM:</span><div class="etiqueta-box box-item${etqClasseItem(e.item)}">${etqCampo(e.item, 'Item', 'item', e.id, editavel, true)}</div>
    <span class="etiqueta-label lbl-insumo">INSUMO:</span><div class="etiqueta-box box-insumo">${etqCampo(e.insumo, 'Insumo (SKU)', 'insumo', e.id, editavel)}</div>
    ${ETQ.layout.barras && e.insumo ? etqBarsSvg(e.insumo) : ''}
    <span class="etiqueta-label lbl-data">DATA:</span><div class="etiqueta-box box-data">${etqCampo(etqData(e), 'Data', 'data', e.id, editavel)}</div>
    ${editavel && !e.oficial && e.insumo ? '<span class="etiqueta-label etq-flag no-print">SKU fora do catálogo</span>' : ''}
    ${editavel ? `<div class="etiqueta-controles no-print"><label><input type="checkbox" data-sel="${e.id}" ${sel ? 'checked' : ''}><span>Selecionar</span></label><span class="etiqueta-numero">nº ${n}</span><button type="button" data-rem="${e.id}">Remover</button></div>` : ''}</div>`;
}
function etqFolhaHtml(lista, pagina, total, inicio, editavel) {
  const seis = Array.from({ length: 6 }, (_, i) => lista[i] ?? null);
  return `<div class="folha"><div class="folha-in" style="position:absolute;inset:0;transform:translate(${ETQ.layout.dx}mm,${ETQ.layout.dy}mm)">
    <div class="cabecalho"><div class="cabecalho-grid"><div class="cabecalho-logo"><img src="${ETQ_LOGO}" alt="Drilling do Brasil"></div><div class="cabecalho-titulo">Formulários</div>
    <div class="cabecalho-meta"><div><span>CODIGO:</span><strong>FOR-COM-001</strong></div><div><span>REVISÃO Nº:</span><strong>1</strong></div><div><span>DATA REVISÃO:</span><strong>09/06/2015</strong></div><div><span>PÁGINA:</span><strong>${pagina} / ${total}</strong></div></div></div>
    <div class="cabecalho-subtitulo">SINALIZAÇÃO DE MATERIAIS – ALMOXARIFADO INTERNO</div></div>
    <div class="grade-etiquetas">${seis.map((e, i) => etqEtiquetaHtml(e, inicio + i + 1, editavel)).join('')}</div></div></div>`;
}

/* ---------------- Escopo de impressão (mesmos modos do original + ids) ---------------- */
function etqEscopo() {
  const f = ETQ.fila, M = ETQ.modo, v = id => Math.max(1, parseInt(etqEl(id)?.value) || 1), tot = Math.max(1, Math.ceil(f.length / 6));
  let lista = f;
  if (M === 'folhas') { const a = Math.min(tot, v('etqFolhaDe')), b = Math.min(tot, Math.max(a, v('etqFolhaAte'))); lista = f.slice((a - 1) * 6, b * 6); }
  else if (M === 'ultimas') { const n = Math.min(tot, v('etqUltN')); lista = f.slice((tot - n) * 6); }
  else if (M === 'etiquetas') { const a = Math.min(f.length || 1, v('etqEtDe')), b = Math.min(f.length || 1, Math.max(a, v('etqEtAte'))); lista = f.slice(a - 1, b); }
  else if (M === 'data') { const d = etqEl('etqData')?.value || ''; lista = f.filter(e => etqData(e).trim() === d); }
  else if (M === 'selecionadas') lista = f.filter(e => ETQ.sel.has(e.id));
  else if (M === 'quantidades') { const sku = (etqEl('etqQSku')?.value || '').trim().toUpperCase(), q = v('etqQtd'); lista = f.filter(e => e.insumo === sku).slice(0, q); }
  return lista;
}
function etqFolhas(lista, primeira = 1, totalPag = null) {
  const out = []; for (let i = 0; i < lista.length; i += 6) out.push(lista.slice(i, i + 6));
  return out.map((l, k) => etqFolhaHtml(l, primeira + k, totalPag ?? out.length, k * 6, false));
}

/* ---------------- Persistência da fila no servidor ---------------- */
let etqTimer = null;
function etqSalvarFila(imediato) {
  clearTimeout(etqTimer);
  const go = async () => {
    try {
      const r = await etqPost('etiquetas_fila_salvar', { itens: ETQ.fila, rev: ETQ.rev });
      ETQ.rev = r.rev; ETQ.fila = r.itens; etqEl('etqSync') && (etqEl('etqSync').textContent = 'Fila salva no servidor ✓');
    } catch (e) {
      etqEl('etqSync') && (etqEl('etqSync').textContent = 'Não salvou: ' + e.message);
      if (e.status === 409) { etqMsg(e.message, 'err'); await etqCarregarFila(); etqRender(); }
    }
  };
  if (imediato) return go();
  etqEl('etqSync') && (etqEl('etqSync').textContent = 'Salvando…');
  etqTimer = setTimeout(go, 600);
}
async function etqCarregarFila() { const r = await etqApi('etiquetas_fila'); ETQ.fila = r.itens; ETQ.rev = r.rev; const ids = new Set(ETQ.fila.map(e => e.id)); ETQ.sel = new Set([...ETQ.sel].filter(i => ids.has(i))); }

/* ---------------- Inicialização e layout ---------------- */
async function etqInit() {
  const root = etqEl('etqRoot'); if (!root) return;
  if (!ETQ.carregado) root.innerHTML = '<div class="etq-msg">Carregando módulo de etiquetas…</div>';
  try {
    ETQ.cfg = (await etqApi('etiquetas_config'));
    await etqCarregarFila();
    ETQ.carregado = true; etqRender(); etqBuscar('');
  } catch (e) { root.innerHTML = `<div class="etq-msg err">Não foi possível abrir as etiquetas: ${etqH(e.message)}</div>`; }
}
function etqAba(t) { ETQ.tab = t; etqRender(); if (t === 'historico') etqCarregarHistorico(); }

function etqRender() {
  const root = etqEl('etqRoot'); if (!root || !ETQ.cfg) return;
  const c = ETQ.cfg, tabs = [['gerar', 'Gerar etiquetas'], ['fila', `Fila (${ETQ.fila.length})`], ['imprimir', 'Imprimir / PDF'], ['importar', 'Importar planilha'], ['historico', 'Histórico']];
  const keep = { q: etqEl('etqQ')?.value ?? '', a: etqEl('etqAlmox')?.value, d: etqEl('etqDataPad')?.value, k: etqEl('etqCop')?.value };
  root.innerHTML = `<div class="etq-wrap">
   <div class="etq-row"><div class="etq-tabs">${tabs.map(([k, n]) => `<button type="button" class="button ghost small ${ETQ.tab === k ? 'on' : ''}" onclick="etqAba('${k}')">${n}</button>`).join('')}</div>
    <span style="flex:1"></span><small id="etqSync" style="color:#8fa3bd">${etqH(c.usuario)} · ${etqH(c.perfil)}</small></div>
   <div id="etqMsg" class="etq-msg" hidden role="status"></div>
   ${ETQ.tab === 'gerar' ? etqTabGerar(keep) : ''}${ETQ.tab === 'fila' ? etqTabFila() : ''}${ETQ.tab === 'imprimir' ? etqTabImprimir() : ''}${ETQ.tab === 'importar' ? etqTabImportar() : ''}${ETQ.tab === 'historico' ? '<div id="etqHist" class="etq-card">Carregando…</div>' : ''}
  </div>`;
  etqBind();
  if (ETQ.tab === 'gerar') etqRenderResultados();
  if (ETQ.tab === 'imprimir') etqRenderPreview();
  if (ETQ.tab === 'historico') etqRenderHistorico();
}

function etqPadroes(keep) {
  const alm = (ETQ.cfg.almoxarifados || []).map(a => `<option value="${etqH(a)}">`).join('');
  return `<div class="etq-grid3"><label class="f">Almoxarifado (padrão)<input type="text" id="etqAlmox" list="etqAlmList" value="${etqH(keep.a ?? 'DRILLING')}" maxlength="40" title="Padrão DRILLING. Pode ser alterado manualmente."><datalist id="etqAlmList">${alm}</datalist></label>
   <label class="f">Data da etiqueta<input type="text" id="etqDataPad" value="Dia da impressão (${etqHoje()})" disabled></label>
   <label class="f">Cópias por item<input type="number" id="etqCop" min="1" max="60" value="${etqH(keep.k ?? 1)}"></label></div>`;
}
const etqPad = () => ({ almoxarifado: ((etqEl('etqAlmox')?.value || '').trim() || 'DRILLING').toUpperCase(), data: etqHoje(), copias: Math.max(1, Math.min(60, parseInt(etqEl('etqCop')?.value) || 1)) });

/* ---------------- Aba: gerar ---------------- */
function etqTabGerar(keep) {
  return `<div class="etq-card"><h3>Pesquisar no catálogo oficial de materiais</h3>${etqPadroes(keep)}
   <form id="etqForm" class="etq-row" style="margin-top:12px"><input type="search" id="etqQ" value="${etqH(keep.q)}" placeholder="SKU, código, descrição, apelido ou categoria (ex.: MA002530 ou adaptador)" autocomplete="off" style="flex:1;min-width:220px"><button class="button primary" type="submit" ${can('gerar') ? '' : 'disabled'}>Adicionar</button><button type="button" class="button ghost" onclick="etqManual()" ${can('gerar') ? '' : 'disabled'}>Etiqueta manual</button></form>
   <div class="etq-row" style="margin:10px 0"><label class="etq-row" style="font-size:12px"><input type="checkbox" id="etqAll"> <span id="etqResInfo">—</span></label><span style="flex:1"></span><button class="button primary small" id="etqAddSel" ${can('gerar') ? '' : 'disabled'}>Adicionar selecionados (0)</button></div>
   <div class="etq-results" id="etqRes"></div>
   <p style="font-size:11.5px;color:#8fa3bd;margin:10px 0 0">Ao digitar um SKU existente, a descrição oficial é preenchida automaticamente. <b>Gerar etiquetas não altera saldo nem cria movimentação.</b></p></div>`;
}
let etqBuscaT = null;
async function etqBuscar(q) {
  try { const r = await etqApi('etiquetas_buscar', { query: { q, limite: 80 } }); ETQ.res = r.itens; ETQ.resSel = new Set([...ETQ.resSel].filter(s => ETQ.res.some(x => x.sku === s))); etqRenderResultados(); }
  catch (e) { etqMsg(e.message, 'err'); }
}
function etqRenderResultados() {
  const host = etqEl('etqRes'); if (!host) return;
  host.innerHTML = ETQ.res.length ? ETQ.res.map(r => `<div class="etq-res" data-sku="${etqH(r.sku)}"><input type="checkbox" ${ETQ.resSel.has(r.sku) ? 'checked' : ''} data-rs="${etqH(r.sku)}"><b>${etqH(r.sku)}</b><span class="d">${etqH(r.descricao)}${r.unidade ? ' · ' + etqH(r.unidade) : ''}${r.localizacao ? ' · ' + etqH(r.localizacao) : ''}</span>${r.oficial ? '<span class="etq-tag of">CATÁLOGO</span>' : `<span class="etq-tag ped" title="Veio de um pedido de compra e ainda não foi cadastrado">PEDIDO${r.pc ? ' ' + r.pc : ''}</span>`}</div>`).join('') : '<div style="padding:14px;font-size:13px;color:#8fa3bd">Nenhum material encontrado. Use “Etiqueta manual” ou importe uma planilha.</div>';
  etqEl('etqResInfo').textContent = `${ETQ.res.length} resultado(s) · catálogo oficial com ${ETQ.cfg.catalogo_total} item(ns)`;
  const b = etqEl('etqAddSel'); if (b) b.textContent = `Adicionar selecionados (${ETQ.resSel.size})`;
  const all = etqEl('etqAll'); if (all) all.checked = ETQ.res.length > 0 && ETQ.res.every(r => ETQ.resSel.has(r.sku));
}
async function etqAdicionar(skus) {
  if (!skus.length) return;
  const p = etqPad();
  try {
    const r = await etqPost('etiquetas_adicionar', { skus: skus.map(sku => ({ sku, copias: p.copias })), almoxarifado: p.almoxarifado, data: p.data });
    ETQ.fila = r.itens; ETQ.rev = r.rev; ETQ.resSel.clear();
    etqMsg(`${r.adicionadas} etiqueta(s) adicionada(s) à fila.` + (r.sem_cadastro.length ? ` Sem cadastro: ${r.sem_cadastro.join(', ')}.` : ''), 'ok'); etqRender(); etqBuscar(etqEl('etqQ')?.value || '');
  } catch (e) { etqMsg(e.message, 'err'); }
}
async function etqSubmitBusca() {
  const q = (etqEl('etqQ').value || '').trim(); if (!q) return;
  if (ETQ.resSel.size) return etqAdicionar([...ETQ.resSel]);
  const exato = ETQ.res.find(r => r.sku === q.toUpperCase().replace(/\s+/g, ''));
  if (exato) return etqAdicionar([exato.sku]);
  if (ETQ.res.length === 1) return etqAdicionar([ETQ.res[0].sku]);
  // SKU inexistente: oferece cadastro (se permitido) ou etiqueta manual
  const sku = q.toUpperCase().replace(/\s+/g, '');
  if (/^[A-Z0-9][A-Z0-9._\-\/]{1,39}$/.test(sku)) {
    const r = await etqApi('etiquetas_sku', { query: { sku } });
    if (!r.encontrado) return etqCadastroDialogo(sku, r.candidato_pedido, r.pode_cadastrar);
  }
  etqMsg('Vários resultados: marque os materiais desejados e use “Adicionar selecionados”.', '');
}
function etqCadastroDialogo(sku, cand, pode) {
  if (!pode) { etqMsg(`SKU ${sku} não existe no catálogo oficial. Solicite o cadastro a um gestor ou use “Etiqueta manual” (a etiqueta sai marcada como fora do catálogo).`, 'err'); return; }
  const desc = prompt(`SKU ${sku} não está no catálogo oficial.\n${cand ? 'Encontrado em pedido de compra ' + (cand.pc || '') + '.\n' : ''}Digite a descrição para CADASTRAR este material (cancelar = não cadastrar):`, cand?.descricao || '');
  if (!desc) return;
  etqPost('etiquetas_material_salvar', { sku, descricao: desc, unidade: cand?.unidade || 'UN', origem: cand ? 'PEDIDO_COMPRA' : 'CADASTRO_MANUAL' })
    .then(() => { etqMsg(`Material ${sku} cadastrado no catálogo oficial.`, 'ok'); ETQ.cfg.catalogo_total++; return etqAdicionar([sku]); }).catch(e => etqMsg(e.message, 'err'));
}
function etqManual() {
  const p = etqPad(), sku = (etqEl('etqQ')?.value || '').trim().toUpperCase().replace(/\s+/g, '');
  const ref = ETQ.res.find(r => r.sku === sku);
  ETQ.fila.push({ id: Math.random().toString(36).slice(2, 14), almoxarifado: p.almoxarifado, item: ref?.descricao || '', insumo: sku, data: '', oficial: !!ref?.oficial });
  etqSalvarFila(true); etqMsg('Etiqueta manual adicionada — edite os campos na aba Fila. Edições não alteram o cadastro oficial.', 'ok'); ETQ.tab = 'fila'; etqRender();
}

/* ---------------- Aba: fila ---------------- */
function etqTabFila() {
  const n = ETQ.fila.length, paginas = Math.max(1, Math.ceil(n / 6)), ed = can('editar');
  const folhas = []; for (let i = 0; i < n || i === 0; i += 6) folhas.push(etqFolhaHtml(ETQ.fila.slice(i, i + 6), i / 6 + 1, paginas, i, ed));
  const out = `<div class="etq-card"><div class="etq-row"><h3 style="margin:0">Fila de impressão (${n}) · ${ETQ.sel.size} selecionada(s)</h3><span style="flex:1"></span>
    <button class="button ghost small" id="etqSelAll" ${n ? '' : 'disabled'}>Selecionar todas</button><button class="button ghost small" id="etqSelNone" ${ETQ.sel.size ? '' : 'disabled'}>Desmarcar todas</button></div>
   <p style="font-size:11px;font-weight:700;color:#8fa3bd;margin:12px 0 6px">EDIÇÃO EM LOTE — marque os campos que deseja aplicar (os demais ficam inalterados)</p>
   <div class="etq-grid4">${[['almoxarifado', 'Almoxarifado'], ['item', 'Item'], ['insumo', 'Insumo (SKU)'], ['data', 'Data']].map(([k, r]) => `<label class="f"><span><input type="checkbox" data-bk="${k}"> ${r}</span><input type="text" id="etqB_${k}" placeholder="${r}"></label>`).join('')}
    <label class="f"><span><input type="checkbox" data-bk="copias"> Cópias (por etiqueta selecionada)</span><input type="number" id="etqB_copias" min="1" max="60" value="1"></label></div>
   <div class="etq-row" style="margin-top:12px"><button class="button primary small" id="etqApply" ${ed && n ? '' : 'disabled'}>Aplicar nas ${ETQ.sel.size ? 'selecionadas' : 'todas'}</button><button class="button ghost small" id="etqDup" ${ETQ.sel.size && can('fila') ? '' : 'disabled'}>Duplicar selecionadas</button><button class="button ghost small" id="etqRemSel" ${ETQ.sel.size && can('fila') ? '' : 'disabled'}>Remover selecionadas</button><button class="button ghost small" id="etqClear" ${n && can('fila') ? '' : 'disabled'}>Limpar fila</button></div>
   <p style="font-size:11.5px;color:#8fa3bd;margin:10px 0 0">Clique nos campos da etiqueta para editar. Digitar um SKU do catálogo no campo INSUMO completa o ITEM sozinho. A fila fica salva no servidor, na sua conta.</p></div>
   <div class="etq-preview"><div class="etq-scroll"><div class="etq-zoom" id="etqPrevWrap" style="zoom:${ETQ.layout.zoom}">${folhas.join('')}</div></div></div>`;
  setTimeout(etqZoomAuto, 0); return out;
}

/* ---------------- Aba: imprimir ---------------- */
function etqTabImprimir() {
  const n = ETQ.fila.length, tot = Math.max(1, Math.ceil(n / 6)), datas = [...new Set(ETQ.fila.map(e => etqData(e).trim()).filter(Boolean))];
  const M = ETQ.modo, R = (v, t, extra = '') => `<label class="etq-row" style="font-size:13px"><input type="radio" name="etqModo" value="${v}" ${M === v ? 'checked' : ''}> ${t} ${extra}</label>`;
  const num = (id, v, max) => `<input type="number" id="${id}" min="1" max="${max}" value="${v}" style="width:80px">`;
  const L = ETQ.layout;
  return `<div class="etq-card"><h3>O que imprimir · fila com ${n} etiqueta(s) em ${tot} folha(s)</h3><div style="display:grid;gap:10px">
   ${R('todas', 'Tudo')}${R('folhas', 'Folhas de', num('etqFolhaDe', 1, tot) + ' até ' + num('etqFolhaAte', tot, tot))}
   ${R('ultimas', 'Só as últimas', num('etqUltN', 1, tot) + ' folha(s)')}${R('etiquetas', 'Da etiqueta nº', num('etqEtDe', 1, Math.max(1, n)) + ' até ' + num('etqEtAte', Math.max(1, n), Math.max(1, n)))}
   ${R('data', 'Só da data', `<select id="etqData" style="width:auto">${datas.map(d => `<option>${etqH(d)}</option>`).join('')}</select>`)}
   ${R('quantidades', 'Quantidade específica do SKU', `<input type="text" id="etqQSku" placeholder="SKU" style="width:140px"> ${num('etqQtd', 1, 999)} etiqueta(s)`)}
   ${R('selecionadas', `Só as selecionadas (${ETQ.sel.size})`)}</div>
   <div class="etq-row" style="margin-top:14px"><button class="button primary" id="etqPrint" ${can('imprimir') && n ? '' : 'disabled'}>🖨 Imprimir</button><button class="button ghost" id="etqPdf" ${can('exportar_pdf') && n ? '' : 'disabled'}>⇩ Salvar como PDF</button><span id="etqCount" style="font-size:12px;color:#8fa3bd"></span></div>
   <p style="font-size:11.5px;color:#8fa3bd;margin:8px 0 0">Impressão A4 retrato, 6 etiquetas por folha, 95×65 mm cada. Na janela de impressão use <b>Escala 100%</b>, <b>Margens: nenhuma</b> e ative “Gráficos de segundo plano”. Em “Salvar como PDF” escolha o destino PDF.</p></div>
   <div class="etq-card"><h3>Ajuste de impressora</h3><div class="etq-grid4">
    <label class="f">Margem horizontal (mm)<input type="number" step="0.5" min="-10" max="10" id="etqDx" value="${L.dx}"></label>
    <label class="f">Margem vertical (mm)<input type="number" step="0.5" min="-10" max="10" id="etqDy" value="${L.dy}"></label>
    <label class="f">Zoom da pré-visualização<input type="number" step="0.05" min="0.3" max="1.2" id="etqZoom" value="${L.zoom}"></label>
    <label class="f">Código de barras do SKU<select id="etqBars"><option value="1" ${L.barras ? 'selected' : ''}>Imprimir (Code 128)</option><option value="0" ${L.barras ? '' : 'selected'}>Não imprimir</option></select></label></div>
    <p style="font-size:11.5px;color:#8fa3bd;margin:10px 0 0">Use a margem para compensar o recuo da sua impressora; o tamanho das etiquetas permanece fixo no gabarito do formulário FOR-COM-001. O ajuste fica só neste navegador.</p></div>
   <div class="etq-preview"><div class="etq-scroll"><div class="etq-zoom" id="etqPrev" style="zoom:${L.zoom}"></div></div></div>`;
}
/* zoom automático: cabe a folha inteira na tela quando o usuário não fixou um valor */
function etqZoomAuto() {
  const host = etqEl('etqPrevWrap') || document.querySelector('.etq-preview'); if (!host) return;
  const disponivel = host.clientWidth - 24; if (disponivel < 200) return;
  const ideal = Math.min(1.2, Math.max(0.28, disponivel / 794));   // 210 mm ≈ 794 px
  if (ETQ.layout.zoom_auto === undefined || ETQ.layout.zoom_auto) {
    ETQ.layout.zoom = Math.round(ideal * 100) / 100;
    const z = etqEl('etqZoom'); if (z) z.value = ETQ.layout.zoom;
  }
}
function etqRenderPreview() {
  etqZoomAuto();
  const lista = etqEscopo(), host = etqEl('etqPrev'); if (!host) return;
  const f = etqFolhas(lista); host.innerHTML = f.join('') || '<div class="etq-msg">Nenhuma etiqueta corresponde à escolha.</div>';
  etqEl('etqCount').textContent = `${lista.length} etiqueta(s) em ${f.length} folha(s)`;
}
async function etqImprimir(tipo) {
  const lista = etqEscopo(); if (!lista.length) return etqMsg('Nenhuma etiqueta corresponde à escolha de impressão.', 'err');
  if (lista.length > 600 && !confirm(`Imprimir ${lista.length} etiquetas (${Math.ceil(lista.length / 6)} folhas)?`)) return;
  const escopo = { modo: ETQ.modo, total: lista.length };
  try { await etqPost('etiquetas_registrar_impressao', { tipo, escopo, ids: lista.map(e => e.id) }); }
  catch (e) { return etqMsg('Impressão não registrada no histórico: ' + e.message, 'err'); }
  let area = etqEl('etqPrintArea'); if (!area) { area = document.createElement('div'); area.id = 'etqPrintArea'; area.className = 'etq-print-area'; document.body.appendChild(area); }
  area.innerHTML = etqFolhas(lista).join(''); document.body.classList.add('etq-printing');
  const fim = () => { document.body.classList.remove('etq-printing'); area.innerHTML = ''; window.removeEventListener('afterprint', fim); };
  window.addEventListener('afterprint', fim);
  const imgs = [...area.querySelectorAll('img')]; await Promise.all(imgs.map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })));
  setTimeout(() => window.print(), 150);
}

/* ---------------- Aba: importar planilha ---------------- */
function etqTabImportar() {
  const pode = can('importar'), im = ETQ.imp;
  return `<div class="etq-card"><h3>Importar planilha (XLSX, XLS ou CSV)</h3>
   <p style="font-size:12.5px;color:#b5c7dd">Reconhecemos automaticamente as colunas <b>SKU / Código</b>, <b>Descrição</b>, <b>Unidade</b> e <b>Categoria</b>. Nada é gravado antes da pré-visualização e da sua confirmação.</p>
   <input type="file" id="etqFile" accept=".xlsx,.xls,.csv,text/csv" ${pode ? '' : 'disabled'}>
   <details style="margin-top:10px"><summary style="cursor:pointer;font-size:12.5px">Ou cole a lista (SKU;DESCRIÇÃO por linha)</summary><textarea id="etqPaste" rows="4" placeholder="MA002530;ADAPTADOR FEMEA 1/4&quot; NPT" style="margin-top:8px;font-family:ui-monospace,monospace"></textarea><button class="button ghost small" id="etqPasteGo" style="margin-top:8px" ${pode ? '' : 'disabled'}>Analisar texto colado</button></details>
   ${pode ? '' : '<p class="etq-msg err" style="margin-top:10px">Seu perfil não permite importar planilhas.</p>'}</div>
   ${im ? etqImportResultado(im) : ''}`;
}
function etqImportResultado(im) {
  const t = im.totais, tbl = (rows, cols) => `<div class="etq-scroll" style="max-height:230px;overflow:auto"><table class="etq-table"><thead><tr>${cols.map(c => `<th>${c[0]}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${c[1](r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  const sec = (tit, rows, cols, open) => rows.length ? `<details ${open ? 'open' : ''} style="margin-top:10px"><summary style="cursor:pointer;font-weight:700;font-size:13px">${tit} (${rows.length})</summary>${tbl(rows, cols)}</details>` : '';
  const base = [['Linha', r => r.linha], ['SKU', r => `<b>${etqH(r.sku)}</b>`], ['Descrição', r => etqH(r.descricao)]];
  return `<div class="etq-card"><h3>Pré-visualização — ${etqH(im.arquivo)}</h3>
   <div>${[['Linhas', t.linhas], ['Novos', t.novos], ['Já existem', t.existentes], ['Divergentes', t.divergentes], ['Duplicados', t.duplicados], ['Inválidos', t.invalidos], ['Vazias', t.vazias]].map(([a, b]) => `<span class="etq-stat"><b>${b}</b>${a}</span>`).join('')}</div>
   ${sec('SKUs novos', im.novos, base.concat([['Un.', r => etqH(r.unidade)]]), true)}
   ${sec('Descrições divergentes do cadastro oficial', im.divergentes, [['Linha', r => r.linha], ['SKU', r => `<b>${etqH(r.sku)}</b>`], ['Oficial', r => etqH(r.descricao_oficial) + (r.unidade_oficial ? ' · ' + etqH(r.unidade_oficial) : '')], ['Planilha', r => etqH(r.descricao) + (r.unidade ? ' · ' + etqH(r.unidade) : '')], ['Atualizar', r => im.pode_atualizar ? r.diferencas.map(d => `<label style="display:block"><input type="checkbox" data-up="${etqH(r.sku)}|${d}"> ${d}</label>`).join('') : '—']], true)}
   ${sec('SKUs repetidos na planilha (só a 1ª ocorrência vale)', im.duplicados, base.concat([['1ª linha', r => r.primeira_linha], ['Igual?', r => r.mesma_descricao ? 'sim' : '<b>descrição diferente</b>']]))}
   ${sec('Registros inválidos', im.invalidos, base.concat([['Motivo', r => etqH(r.motivo)]]), true)}
   ${sec('Já existem sem diferença', im.existentes, base)}
   <div class="etq-grid3" style="margin-top:14px">${etqPadroes({})}</div>
   <div style="display:grid;gap:8px;margin-top:12px;font-size:13px">
    <label><input type="checkbox" id="etqImpCad" ${im.pode_cadastrar && t.novos ? 'checked' : 'disabled'}> Cadastrar os ${t.novos} SKU(s) novos no catálogo oficial ${im.pode_cadastrar ? '' : '(requer gestor)'}</label>
    <label><input type="checkbox" id="etqImpFila" checked> Enviar as etiquetas dos materiais que estão no catálogo para a fila</label></div>
   <div class="etq-row" style="margin-top:12px"><button class="button primary" id="etqImpGo">Confirmar importação</button><button class="button ghost" id="etqImpCancel">Cancelar</button></div>
   <p style="font-size:11.5px;color:#8fa3bd">Divergências só alteram o cadastro oficial nos campos que você marcar acima. A importação nunca altera saldo, movimentos nem pedidos.</p></div>`;
}
const ETQ_COLS = { sku: ['sku', 'codigo', 'cod', 'cod.', 'codigo do insumo', 'cod. insumo', 'cod insumo', 'codigo insumo', 'codigo do produto', 'cod. produto', 'insumo', 'produto', 'item', 'material', 'codigo sku'],
  descricao: ['descricao', 'descricao do insumo', 'descricao insumo', 'descricao do produto', 'descricao do material', 'nome', 'texto breve', 'denominacao'], unidade: ['unidade', 'un', 'und', 'um', 'unid', 'unid.', 'unidade de medida'], categoria: ['categoria', 'grupo', 'familia', 'classe'] };
const etqNorm = s => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase().replace(/\s+/g, ' ');
function etqLinhasDeMatriz(m) {
  let h = -1, idx = {};
  for (let i = 0; i < Math.min(m.length, 25) && h < 0; i++) {
    const cab = (m[i] || []).map(etqNorm), f = k => cab.findIndex(c => ETQ_COLS[k].includes(c) || (k === 'descricao' && c.startsWith('descricao')));
    const a = f('sku'), b = f('descricao'); if (a >= 0 && b >= 0 && a !== b) { h = i; idx = { sku: a, descricao: b, unidade: f('unidade'), categoria: f('categoria') }; }
  }
  if (h < 0) return null;
  return m.slice(h + 1).map((r, i) => ({ linha: h + i + 2, sku: String(r?.[idx.sku] ?? '').trim(), descricao: String(r?.[idx.descricao] ?? '').trim(), unidade: idx.unidade >= 0 ? String(r?.[idx.unidade] ?? '').trim() : '', categoria: idx.categoria >= 0 ? String(r?.[idx.categoria] ?? '').trim() : '' }));
}
function etqCsvParse(txt) {
  const primeira = (txt.replace(/\r\n?/g, '\n').split('\n')[0] || '');
  const conta = s => (primeira.split(s).length - 1);
  const sep = conta(';') >= conta(',') && txt.includes(';') ? ';' : (conta('\t') > conta(',') && txt.includes('\t')) ? '\t' : ',';
  const rows = []; let r = [], c = '', q = false;
  for (let i = 0; i < txt.length; i++) { const ch = txt[i];
    if (q) { if (ch === '"') { if (txt[i + 1] === '"') { c += '"'; i++; } else q = false; } else c += ch; }
    else if (ch === '"' && c === '') q = true; else if (ch === sep) { r.push(c); c = ''; } else if (ch === '\n' || ch === '\r') { if (ch === '\r' && txt[i + 1] === '\n') i++; r.push(c); rows.push(r); r = []; c = ''; } else c += ch; }
  if (c !== '' || r.length) { r.push(c); rows.push(r); }
  return rows.map(l => (l.length === 1 && l[0]) ? l[0].split(/[;\t,]/).map(x => x.trim()) : l);
}
async function etqLerArquivo(file) {
  if (file.size > 8 * 1024 * 1024) throw new Error('Arquivo acima de 8 MB.');
  if (/\.csv$/i.test(file.name)) { const buf = await file.arrayBuffer(); let t = new TextDecoder('utf-8', { fatal: false }).decode(buf); if (t.includes('\ufffd')) t = new TextDecoder('windows-1252').decode(buf); return etqLinhasDeMatriz(etqCsvParse(t.replace(/^\ufeff/, ''))); }
  const X = await import('./xlsx-lib.js'); const wb = X.read(await file.arrayBuffer(), { type: 'array', raw: true }); const out = [];
  for (const nome of wb.SheetNames) { const m = X.utils.sheet_to_json(wb.Sheets[nome], { header: 1, defval: '', raw: false }); const l = etqLinhasDeMatriz(m); if (l) out.push(...l); }
  return out.length ? out : null;
}
async function etqAnalisar(linhas, nome) {
  if (!linhas || !linhas.length) return etqMsg(`Não encontrei colunas de SKU e DESCRIÇÃO em "${nome}". A primeira linha precisa conter esses títulos.`, 'err');
  try { etqMsg('Analisando…'); ETQ.imp = await etqApi('etiquetas_importar_previa', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ arquivo: nome, linhas }) }); etqMsg(''); etqRender(); }
  catch (e) { etqMsg(e.message, 'err'); }
}
async function etqConfirmarImport() {
  const im = ETQ.imp; if (!im) return; const p = etqPad(), atualizar = {};
  document.querySelectorAll('[data-up]:checked').forEach(b => { const [s, c] = b.dataset.up.split('|'); (atualizar[s] = atualizar[s] || []).push(c); });
  if (Object.keys(atualizar).length && !confirm('Atualizar o cadastro OFICIAL nos campos marcados? O valor anterior fica registrado no histórico do item.')) return;
  try {
    const r = await etqPost('etiquetas_importar_confirmar', { token: im.token, cadastrar_novos: !!etqEl('etqImpCad')?.checked, atualizar, enviar_fila: !!etqEl('etqImpFila')?.checked, copias: p.copias, almoxarifado: p.almoxarifado, data: p.data });
    const x = r.resultado; ETQ.imp = null; await etqCarregarFila(); const cfg = await etqApi('etiquetas_config'); ETQ.cfg = cfg;
    etqMsg(`Importação ${x.id}: ${x.cadastrados} cadastrado(s), ${x.atualizados} atualizado(s), ${x.etiquetas_na_fila} etiqueta(s) enviada(s) à fila.`, 'ok'); ETQ.tab = x.etiquetas_na_fila ? 'fila' : 'importar'; etqRender();
  } catch (e) { etqMsg(e.message, 'err'); }
}

/* ---------------- Aba: histórico ---------------- */
async function etqCarregarHistorico() { try { const [j, h] = await Promise.all([etqApi('etiquetas_jobs'), etqApi('etiquetas_historico')]); ETQ.jobs = { j: j.jobs, imp: j.importacoes, h: h.historico }; } catch (e) { ETQ.jobs = { erro: e.message }; } etqRenderHistorico(); }
function etqRenderHistorico() {
  const host = etqEl('etqHist'); if (!host) return; const d = ETQ.jobs; if (!d) return;
  if (d.erro) { host.innerHTML = `<div class="etq-msg err">${etqH(d.erro)}</div>`; return; }
  const dt = v => v ? new Date(v).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  host.innerHTML = `<h3>Impressões e PDFs</h3>${d.j.length ? `<div class="etq-scroll"><table class="etq-table"><thead><tr><th>Trabalho</th><th>Quando</th><th>Usuário</th><th>Tipo</th><th>Etiquetas</th><th>Folhas</th><th>SKUs</th><th></th></tr></thead><tbody>${d.j.map(j => `<tr><td><b>${etqH(j.id)}</b></td><td>${dt(j.em)}</td><td>${etqH(j.usuario)}</td><td>${etqH(j.tipo)}</td><td>${j.total}</td><td>${j.folhas}</td><td>${Object.keys(j.skus || {}).length}</td><td><button class="button ghost small" data-reimp="${etqH(j.id)}" ${can('imprimir') ? '' : 'disabled'}>Reimprimir</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="etq-msg">Nenhuma impressão registrada ainda.</p>'}
   <h3 style="margin-top:18px">Importações de planilha</h3>${d.imp.length ? `<div class="etq-scroll"><table class="etq-table"><thead><tr><th>ID</th><th>Quando</th><th>Usuário</th><th>Arquivo</th><th>Cadastrados</th><th>Atualizados</th><th>Dup.</th><th>Inválidos</th></tr></thead><tbody>${d.imp.map(i => `<tr><td>${etqH(i.id)}</td><td>${dt(i.em)}</td><td>${etqH(i.usuario)}</td><td>${etqH(i.arquivo)}</td><td>${i.cadastrados}</td><td>${i.atualizados}</td><td>${i.duplicados}</td><td>${i.invalidos}</td></tr>`).join('')}</tbody></table></div>` : '<p class="etq-msg">Nenhuma importação.</p>'}
   <h3 style="margin-top:18px">Trilha de auditoria</h3><div class="etq-scroll" style="max-height:260px;overflow:auto"><table class="etq-table"><tbody>${d.h.map(h => `<tr><td style="white-space:nowrap">${dt(h.em)}</td><td>${etqH(h.usuario)}</td><td>${etqH(h.tipo)}</td><td>${etqH(h.detalhe)}</td></tr>`).join('') || '<tr><td>Sem registros.</td></tr>'}</tbody></table></div>`;
}

/* ---------------- Eventos ---------------- */
window.addEventListener('resize', () => { if (ETQ.tab === 'imprimir' || ETQ.tab === 'fila') { try { ETQ.layout.zoom_auto = true; } catch {} etqRender(); } });
function etqBind() {
  const root = etqEl('etqRoot');
  root.querySelectorAll('input[name=etqModo]').forEach(r => r.onchange = () => { ETQ.modo = r.value; etqRenderPreview(); });
  root.querySelectorAll('#etqRoot .etq-card input[type=number], #etqRoot .etq-card select').forEach(i => { if (ETQ.tab === 'imprimir') i.addEventListener('input', () => { ETQ.layout = { ...ETQ.layout, dx: parseFloat(etqEl('etqDx').value) || 0, dy: parseFloat(etqEl('etqDy').value) || 0, zoom: Math.min(1.2, Math.max(0.28, parseFloat(etqEl('etqZoom').value) || 0.55)), barras: etqEl('etqBars').value === '1', zoom_auto: false }; try { localStorage.setItem('etq-layout', JSON.stringify(ETQ.layout)); } catch {} etqEl('etqPrev').style.zoom = ETQ.layout.zoom; etqRenderPreview(); }); });
  const on = (id, fn) => { const e = etqEl(id); if (e) e.onclick = fn; };
  const form = etqEl('etqForm'); if (form) { form.onsubmit = ev => { ev.preventDefault(); etqSubmitBusca(); }; etqEl('etqQ').oninput = ev => { clearTimeout(etqBuscaT); etqBuscaT = setTimeout(() => etqBuscar(ev.target.value), 250); }; }
  on('etqAddSel', () => etqAdicionar([...ETQ.resSel]));
  const all = etqEl('etqAll'); if (all) all.onchange = () => { ETQ.res.forEach(r => all.checked ? ETQ.resSel.add(r.sku) : ETQ.resSel.delete(r.sku)); etqRenderResultados(); };
  const res = etqEl('etqRes'); if (res) res.onclick = ev => { const row = ev.target.closest('.etq-res'); if (!row) return; const s = row.dataset.sku; if (ev.target.tagName !== 'INPUT') { ETQ.resSel.has(s) ? ETQ.resSel.delete(s) : ETQ.resSel.add(s); } else ev.target.checked ? ETQ.resSel.add(s) : ETQ.resSel.delete(s); etqRenderResultados(); };
  const prev = root.querySelector('.etq-preview');
  if (ETQ.tab === 'fila' && prev) {
    prev.addEventListener('input', ev => { const t = ev.target; if (!t.dataset.campo) return; const e = ETQ.fila.find(x => x.id === t.dataset.id); if (!e) return; e[t.dataset.campo] = t.dataset.campo === 'data' ? t.value : t.value.toUpperCase(); e.editada = true; if (t.dataset.campo === 'data') e.data_manual = true;
      if (t.dataset.campo === 'insumo') { const hit = ETQ.res.find(r => r.sku === e.insumo) || null; if (hit) { e.item = hit.descricao; e.oficial = hit.oficial; } else e.oficial = false; clearTimeout(etqBuscaT); etqBuscaT = setTimeout(async () => { try { const r = await etqApi('etiquetas_sku', { query: { sku: e.insumo } }); if (r.encontrado && ETQ.fila.includes(e)) { e.item = r.item.descricao; e.oficial = true; etqSalvarFila(true); etqRender(); } } catch {} }, 500); }
      etqSalvarFila(); });
    prev.addEventListener('change', ev => { const t = ev.target; if (t.dataset.sel) { t.checked ? ETQ.sel.add(t.dataset.sel) : ETQ.sel.delete(t.dataset.sel); etqRender(); } });
    prev.addEventListener('click', ev => { const id = ev.target.dataset?.rem; if (id && can('fila')) { ETQ.fila = ETQ.fila.filter(e => e.id !== id); ETQ.sel.delete(id); etqSalvarFila(true); etqRender(); } });
  }
  on('etqSelAll', () => { ETQ.sel = new Set(ETQ.fila.map(e => e.id)); etqRender(); }); on('etqSelNone', () => { ETQ.sel.clear(); etqRender(); });
  on('etqApply', () => { const alvo = ETQ.sel.size ? ETQ.fila.filter(e => ETQ.sel.has(e.id)) : ETQ.fila; const campos = [...document.querySelectorAll('[data-bk]:checked')].map(c => c.dataset.bk);
    if (!campos.length) return etqMsg('Marque ao menos um campo para aplicar em lote.', 'err');
    const vals = Object.fromEntries(campos.map(k => [k, (etqEl('etqB_' + k).value || '').trim()]));
    const lista = campos.map(k => `${k}: "${vals[k]}"`).join('\n'); if (!confirm(`Aplicar em ${alvo.length} etiqueta(s)?\n\n${lista}\n\nCampos não marcados permanecem inalterados.`)) return;
    const cop = campos.includes('copias') ? Math.max(1, Math.min(60, parseInt(vals.copias) || 1)) : 0, extra = [];
    alvo.forEach(e => { campos.filter(k => k !== 'copias').forEach(k => { e[k] = k === 'data' ? vals[k] : vals[k].toUpperCase(); if (k === 'data') e.data_manual = true; }); e.editada = true; if (cop > 1) for (let i = 1; i < cop; i++) extra.push({ ...e, id: Math.random().toString(36).slice(2, 14) }); });
    ETQ.fila.push(...extra); etqSalvarFila(true); etqMsg(`Alteração aplicada em ${alvo.length} etiqueta(s)${extra.length ? ` (+${extra.length} cópia(s))` : ''}.`, 'ok'); etqRender(); });
  on('etqDup', () => { ETQ.fila.push(...ETQ.fila.filter(e => ETQ.sel.has(e.id)).map(e => ({ ...e, id: Math.random().toString(36).slice(2, 14) }))); etqSalvarFila(true); etqRender(); });
  on('etqRemSel', () => { if (!confirm(`Remover ${ETQ.sel.size} etiqueta(s) da fila?`)) return; ETQ.fila = ETQ.fila.filter(e => !ETQ.sel.has(e.id)); ETQ.sel.clear(); etqSalvarFila(true); etqRender(); });
  on('etqClear', () => { if (!confirm(`Apagar as ${ETQ.fila.length} etiqueta(s) da fila? (As impressões já feitas continuam no histórico.)`)) return; ETQ.fila = []; ETQ.sel.clear(); etqSalvarFila(true); etqRender(); });
  on('etqPrint', () => etqImprimir('impressao')); on('etqPdf', () => { etqMsg('Na janela de impressão, escolha “Salvar como PDF” como destino.', 'ok'); etqImprimir('pdf'); });
  const file = etqEl('etqFile'); if (file) file.onchange = async () => { const f = file.files[0]; if (!f) return; try { etqAnalisar(await etqLerArquivo(f), f.name); } catch (e) { etqMsg('Não foi possível ler o arquivo: ' + e.message, 'err'); } file.value = ''; };
  on('etqPasteGo', () => etqAnalisar(etqLinhasDeMatriz(etqCsvParse('SKU;DESCRIÇÃO\n' + (etqEl('etqPaste').value || ''))), 'texto colado'));
  on('etqImpGo', etqConfirmarImport); on('etqImpCancel', () => { ETQ.imp = null; etqRender(); });
  root.querySelectorAll('[data-reimp]').forEach(b => b.onclick = async () => { try { const r = await etqPost('etiquetas_reimprimir', { job: b.dataset.reimp }); ETQ.fila = r.itens; ETQ.rev = r.rev; etqMsg(`${r.recolocadas} etiqueta(s) recolocada(s) na fila${r.parcial ? ' (trabalho grande: guardadas as primeiras 600)' : ''}.`, 'ok'); ETQ.tab = 'imprimir'; etqRender(); } catch (e) { etqMsg(e.message, 'err'); } });
}
