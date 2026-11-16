/* Recebimento Drilling — Protocolo de NFs ao Financeiro (v3.36.0) */
'use strict';
const PRT = { p: null, aba: 'novo', q: '', forn: '', de: '', ate: '', sel: new Set(), st: '' };
const prtEl = id => document.getElementById(id);
const prtH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const prtD = s => s ? new Date(String(s).slice(0, 10) + 'T12:00:00').toLocaleDateString('pt-BR') : '—';
const prtR = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const prtHoje = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const prtPode = op => typeof rdaPermite !== 'function' || rdaPermite('pedidos', op);
const PRT_ST = { GERADO: ['Aguardando entrega', 'warn'], ENTREGUE: ['Entregue — falta a via assinada', 'warn'], ASSINADO: ['Assinado e arquivado', 'ok'], CANCELADO: ['Cancelado', 'off'] };
/** Valor "1.234,56" / "1234.56" → número (espelha o PHP). */
function prtNum(v) {
  let s = String(v ?? '').replace(/[^0-9,.\-]/g, ''); if (!s || s === '-') return 0;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  else if (/\.\d{3}$/.test(s) && s.length > 4) s = s.replace(/\./g, '');
  return Number(s) || 0;
}
const prtKey = c => c.pc + '|' + c.numeroNF + '|' + (c.serieNF || '');

async function prtApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const q = opts.query ? '&' + new URLSearchParams(opts.query) : '';
  const r = await fetch(`api.php?action=${action}${q}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const prtPost = (a, b) => prtApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
const prtToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));

async function protocolosInit() {
  try {
    PRT.p = await prtApi('protocolos_painel');
    const ativos = new Set(PRT.p.candidatos.map(prtKey));
    PRT.sel = new Set([...PRT.sel].filter(k => ativos.has(k)));
    prtRender();
  } catch (e) { prtEl('prtConteudo').innerHTML = `<div class="empty">${prtH(e.message)}</div>`; }
}
function prtAba(a) { PRT.aba = a; document.querySelectorAll('[data-prt-aba]').forEach(b => b.classList.toggle('active', b.dataset.prtAba === a)); prtRender(); }

function prtRender() {
  const k = PRT.p.kpis;
  prtEl('prtKpis').innerHTML = `
    <div class="imp-kpi ${k.candidatos ? 'warn' : 'ok'}"><span>NFs sem protocolo</span><strong>${k.candidatos}</strong><small>recebidas e ainda não entregues ao Financeiro</small></div>
    <div class="imp-kpi ${k.aguardando_entrega ? 'warn' : ''}"><span>Aguardando entrega</span><strong>${k.aguardando_entrega}</strong><small>protocolo gerado, não entregue</small></div>
    <div class="imp-kpi ${k.aguardando_assinatura ? 'bad' : 'ok'}"><span>Aguardando assinatura</span><strong>${k.aguardando_assinatura}</strong><small>entregue, falta a via assinada</small></div>
    <div class="imp-kpi ok"><span>Assinados</span><strong>${k.assinados}</strong><small>arquivados</small></div>
    <div class="imp-kpi ${k.sem_nf ? 'warn' : ''}"><span>Recebidos sem NF</span><strong>${k.sem_nf}</strong><small>pedido recebido sem número de NF</small></div>`;
  if (PRT.aba === 'novo') return prtRenderNovo();
  if (PRT.aba === 'protocolos') return prtRenderLista();
  return prtRenderSemNf();
}

/* ---------- novo protocolo: escolher as NFs ---------- */
function prtFiltrados() {
  const q = PRT.q.trim().toLowerCase();
  return PRT.p.candidatos.filter(c => (!PRT.forn || c.fornecedor === PRT.forn) && (!PRT.de || c.data >= PRT.de) && (!PRT.ate || c.data <= PRT.ate)
    && (!q || [c.pc, c.numeroNF, c.fornecedor, c.solicitante].join(' ').toLowerCase().includes(q)));
}
function prtRenderNovo() {
  const p = PRT.p;
  prtEl('prtConteudo').innerHTML = `
    <div class="loc-filtros prt-filtros"><input type="search" id="prtQ" placeholder="Buscar PC, número da NF, fornecedor…" value="${prtH(PRT.q)}" oninput="PRT.q=this.value;prtTabela()">
      <select onchange="PRT.forn=this.value;prtTabela()"><option value="">Todos os fornecedores</option>${p.fornecedores.sort().map(f => `<option ${f === PRT.forn ? 'selected' : ''} value="${prtH(f)}">${prtH(f)}</option>`).join('')}</select>
      <label class="prt-data">Recebido de <input type="date" value="${PRT.de}" onchange="PRT.de=this.value;prtTabela()"></label>
      <label class="prt-data">até <input type="date" value="${PRT.ate}" onchange="PRT.ate=this.value;prtTabela()"></label></div>
    <div id="prtTabela"></div>
    <div class="prt-barra" id="prtBarra"></div>`;
  prtTabela();
}
function prtTabela() {
  const L = prtFiltrados();
  const todos = L.length && L.every(c => PRT.sel.has(prtKey(c)));
  prtEl('prtTabela').innerHTML = !PRT.p.candidatos.length
    ? '<div class="empty">Nenhuma NF pendente de protocolo. Todas as notas dos pedidos recebidos já estão em algum protocolo.</div>'
    : `<div class="prt-scroll"><table class="orders-table prt-tab"><thead><tr><th class="prt-ck"><input type="checkbox" ${todos ? 'checked' : ''} onchange="prtMarcarTodos(this.checked)" aria-label="Selecionar todos"></th><th>NF / série</th><th>Pedido (PC)</th><th>Fornecedor</th><th>Emissão</th><th>Recebido em</th><th>Valor</th></tr></thead>
      <tbody>${L.map(c => { const k = prtKey(c); return `<tr class="${PRT.sel.has(k) ? 'prt-on' : ''}" onclick="prtToggle('${prtH(k).replace(/'/g, '&#39;')}')"><td class="prt-ck"><input type="checkbox" ${PRT.sel.has(k) ? 'checked' : ''} tabindex="-1"></td>
        <td data-l="NF"><b>${prtH(c.numeroNF)}</b>${c.serieNF ? ` <small class="muted">s.${prtH(c.serieNF)}</small>` : ''}</td><td data-l="Pedido">${prtH(c.pc)}</td><td data-l="Fornecedor">${prtH(c.fornecedor)}</td>
        <td data-l="Emissão">${prtD(c.emissaoNF)}</td><td data-l="Recebido em">${prtD(c.data)}</td><td data-l="Valor">${prtNum(c.valorNF) ? prtR(prtNum(c.valorNF)) : '—'}</td></tr>`; }).join('') || '<tr><td colspan="7" class="empty">Nada com esse filtro.</td></tr>'}</tbody></table></div>`;
  prtBarra();
}
function prtToggle(k) { PRT.sel.has(k) ? PRT.sel.delete(k) : PRT.sel.add(k); prtTabela(); }
function prtMarcarTodos(on) { prtFiltrados().forEach(c => on ? PRT.sel.add(prtKey(c)) : PRT.sel.delete(prtKey(c))); prtTabela(); }
function prtBarra() {
  const sel = PRT.p.candidatos.filter(c => PRT.sel.has(prtKey(c)));
  const tot = sel.reduce((s, c) => s + prtNum(c.valorNF), 0);
  prtEl('prtBarra').innerHTML = `<div><b>${sel.length}</b> NF(s) selecionada(s)${tot ? ` · <b>${prtR(tot)}</b>` : ''}</div>
    <div class="prt-barra-b"><button class="button ghost" ${sel.length ? '' : 'disabled'} onclick="PRT.sel.clear();prtTabela()">Limpar</button>
    ${prtPode('editar') ? `<button class="button primary" ${sel.length ? '' : 'disabled'} onclick="prtGerar()">📄 Gerar protocolo</button>` : ''}</div>`;
}

function prtModal(titulo, html, largura) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(${largura || 560}px,100%)"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div>${html}</div>`;
  document.body.appendChild(bg);
  bg.querySelectorAll('[data-x],[data-x2]').forEach(b => b.onclick = () => bg.remove());
  return bg;
}
const prtVal = (bg, n) => { const el = bg.querySelector(`[name="${n}"]`); return el ? el.value : ''; };

function prtGerar() {
  const sel = PRT.p.candidatos.filter(c => PRT.sel.has(prtKey(c)));
  if (!sel.length) return;
  const bg = prtModal('Gerar protocolo de NFs', `<p><b>${sel.length}</b> nota(s) fiscal(is) de <b>${new Set(sel.map(c => c.fornecedor)).size}</b> fornecedor(es).</p>
    <div class="rd-ass-campos"><label>Entregar para<input name="destinatario" value="Financeiro" maxlength="80"></label>
    <label>Observações (opcional)<input name="obs" maxlength="600" placeholder="Ex.: NFs de material de manutenção da semana"></label></div>
    <p class="muted" style="font-size:12px">Depois de gerar, as NFs saem da lista de pendentes e o protocolo abre para impressão em 2 vias (Financeiro e Recebimento).</p>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Gerar e imprimir</button></div>`);
  bg.querySelector('[data-ok]').onclick = async function () {
    this.disabled = true;
    try {
      const r = await prtPost('protocolos_criar', { destinatario: prtVal(bg, 'destinatario'), obs: prtVal(bg, 'obs'), linhas: sel.map(c => ({ pc: c.pc })) });
      bg.remove(); PRT.sel.clear(); prtToast(`Protocolo ${r.protocolo.id} gerado`);
      PRT.aba = 'protocolos'; document.querySelectorAll('[data-prt-aba]').forEach(b => b.classList.toggle('active', b.dataset.prtAba === 'protocolos'));
      await protocolosInit(); prtPronto(r.protocolo);
    } catch (e) { prtToast(e.message, true); this.disabled = false; }
  };
}
function prtPronto(p) {
  const id = encodeURIComponent(p.id);
  const bg = prtModal(`Protocolo ${prtH(p.id)} gerado`, `<p><b>${p.qtd_nfs}</b> nota(s)${p.total_valor ? ' · ' + prtR(p.total_valor) : ''}. Imprima em 2 vias para o ${prtH(p.destinatario)} assinar, ou baixe o arquivo.</p>
    <div class="prt-pronto"><button type="button" class="button primary" data-imp>🖨 Imprimir (2 vias)</button>
    <a class="button ghost" href="api.php?action=protocolos_pdf&id=${id}" download>⬇ Baixar PDF</a>
    <a class="button ghost" href="api.php?action=protocolos_csv&id=${id}" download>⬇ Excel (CSV)</a></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Fechar</button></div>`, 480);
  bg.querySelector('[data-imp]').onclick = () => prtImprimir(p.id);
}
function prtImprimir(id) { window.open(`api.php?action=protocolos_imprimir&id=${encodeURIComponent(id)}`, '_blank'); }

/* ---------- protocolos gerados ---------- */
function prtRenderLista() {
  const L = PRT.p.protocolos.filter(p => !PRT.st || p.status === PRT.st);
  const cont = {}; PRT.p.protocolos.forEach(p => cont[p.status] = (cont[p.status] || 0) + 1);
  prtEl('prtConteudo').innerHTML = `<div class="imp-filtros prt-st">${[['', 'Todos'], ['GERADO', 'Aguardando entrega'], ['ENTREGUE', 'Aguardando assinatura'], ['ASSINADO', 'Assinados'], ['CANCELADO', 'Cancelados']].map(([v, t]) =>
      `<button class="est-tab ${PRT.st === v ? 'active' : ''}" onclick="PRT.st='${v}';prtRenderLista()">${t}${v ? ` (${cont[v] || 0})` : ` (${PRT.p.protocolos.length})`}</button>`).join('')}</div>
    ${L.map(prtCard).join('') || '<div class="empty">Nenhum protocolo nesta visão.</div>'}`;
}
function prtCard(p) {
  const [rot, cls] = PRT_ST[p.status] || [p.status, ''];
  const atraso = p.dias_aberto != null && p.dias_aberto >= 3 ? `<span class="prt-atraso">há ${p.dias_aberto} dias sem fechar</span>` : '';
  return `<article class="panel prt-card ${p.status === 'CANCELADO' ? 'prt-cancelado' : ''}"><div class="prt-top"><div><h3>${prtH(p.id)} <span class="prt-badge ${cls}">${prtH(rot)}</span> ${atraso}</h3>
    <p class="muted">${prtD(p.criado_em)} · ${prtH(p.criado_por)} → <b>${prtH(p.destinatario)}</b> · ${p.qtd_nfs} NF(s)${p.total_valor ? ' · ' + prtR(p.total_valor) : ''}${p.recebido_por ? ' · recebido por <b>' + prtH(p.recebido_por) + '</b>' : ''}${p.entregue_em ? ' em ' + prtD(p.entregue_em) : ''}</p></div>
    <div class="prt-acoes">${p.status !== 'CANCELADO' ? `<button class="button ghost" onclick="prtImprimir('${p.id}')">🖨 Imprimir</button><a class="button ghost" href="api.php?action=protocolos_pdf&id=${encodeURIComponent(p.id)}" download>⬇ Baixar PDF</a><a class="button ghost" href="api.php?action=protocolos_csv&id=${encodeURIComponent(p.id)}" download>⬇ Excel (CSV)</a>` : ''}${prtAcoes(p)}</div></div>
    <details class="prt-det"><summary>Ver as ${p.qtd_nfs} nota(s)</summary><div class="prt-scroll"><table class="orders-table"><thead><tr><th>NF</th><th>PC</th><th>Fornecedor</th><th>Emissão</th><th>Valor</th></tr></thead><tbody>
      ${p.linhas.map(l => `<tr><td><b>${prtH(l.numeroNF)}</b>${l.serieNF ? ' <small class="muted">s.' + prtH(l.serieNF) + '</small>' : ''}</td><td>${prtH(l.pc)}</td><td>${prtH(l.fornecedor)}</td><td>${prtD(l.emissaoNF)}</td><td>${prtNum(l.valorNF) ? prtR(prtNum(l.valorNF)) : '—'}</td></tr>`).join('')}</tbody></table></div>
      ${p.obs ? `<p class="muted"><b>Obs.:</b> ${prtH(p.obs)}</p>` : ''}${p.motivo_cancelamento ? `<p class="muted"><b>Cancelado:</b> ${prtH(p.motivo_cancelamento)}</p>` : ''}
      <div class="prt-docs">${(p.documentos || []).map(a => `<a href="${prtH(a.url)}" target="_blank" rel="noopener">📎 ${prtH(a.nome)}</a> <a href="${prtH(a.url)}&baixar=1" class="muted">baixar</a>`).join(' · ') || '<span class="muted">Nenhuma via assinada anexada.</span>'}</div>
      <details class="prt-hist"><summary>Histórico</summary><ul>${(p.historico || []).map(h => `<li>${prtD(h.em)} ${prtH(String(h.em).slice(11, 16))} · ${prtH(h.por)} — ${prtH(h.acao)}</li>`).join('')}</ul></details></details></article>`;
}
function prtAcoes(p) {
  if (!prtPode('editar') && !prtPode('anexar')) return '';
  const b = [];
  if (p.status === 'GERADO' && prtPode('editar')) b.push(`<button class="button primary" onclick="prtEntregar('${p.id}')">Marcar entregue</button>`);
  if (['GERADO', 'ENTREGUE'].includes(p.status) && prtPode('anexar')) b.push(`<label class="button ghost prt-up">📎 Anexar via assinada<input type="file" accept="application/pdf,image/*" hidden onchange="prtUpload('${p.id}',this)"></label>`);
  if (['GERADO', 'ENTREGUE'].includes(p.status) && prtPode('editar') && (p.documentos || []).length) b.push(`<button class="button primary" onclick="prtAssinar('${p.id}')">Arquivar como assinado</button>`);
  if (p.status === 'ASSINADO' && prtPode('editar')) b.push(`<button class="button ghost" onclick="prtMotivo('${p.id}','reabrir','Reabrir protocolo')">Reabrir</button>`);
  if (['GERADO', 'ENTREGUE'].includes(p.status) && prtPode('editar')) b.push(`<button class="button ghost" onclick="prtMotivo('${p.id}','cancelar','Cancelar protocolo')">Cancelar</button>`);
  return b.join('');
}
async function prtStatus(bg, body, msg) {
  const btn = bg && bg.querySelector('[data-ok]'); if (btn) btn.disabled = true;
  try { await prtPost('protocolos_status', body); if (bg) bg.remove(); prtToast(msg); await protocolosInit(); }
  catch (e) { prtToast(e.message, true); if (btn) btn.disabled = false; }
}
function prtEntregar(id) {
  const bg = prtModal('Marcar como entregue', `<p>Quem do <b>Financeiro</b> recebeu o protocolo <b>${prtH(id)}</b>?</p><div class="rd-ass-campos"><label>Recebido por*<input name="recebido_por" maxlength="80" placeholder="Nome de quem recebeu"></label>
    <label>Data da entrega<input name="data" type="date" value="${prtHoje()}"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar entrega</button></div>`);
  bg.querySelector('[data-ok]').onclick = () => prtStatus(bg, { id, acao: 'entregar', recebido_por: prtVal(bg, 'recebido_por'), data: prtVal(bg, 'data') }, 'Entrega registrada');
}
function prtAssinar(id) {
  const bg = prtModal('Arquivar como assinado', `<p>A via assinada de <b>${prtH(id)}</b> está anexada. Confirme a data da assinatura.</p><div class="rd-ass-campos"><label>Data da assinatura<input name="data" type="date" value="${prtHoje()}"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Arquivar</button></div>`, 460);
  bg.querySelector('[data-ok]').onclick = () => prtStatus(bg, { id, acao: 'assinar', data: prtVal(bg, 'data') }, 'Protocolo arquivado como assinado');
}
function prtMotivo(id, acao, titulo) {
  const bg = prtModal(titulo, `<p><b>${prtH(id)}</b>${acao === 'cancelar' ? ' — as NFs voltam à lista de pendentes.' : ''}</p><div class="rd-ass-campos"><label>Motivo*<input name="motivo" maxlength="200"></label></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Voltar</button><button type="button" class="button primary" data-ok>Confirmar</button></div>`, 480);
  bg.querySelector('[data-ok]').onclick = () => prtStatus(bg, { id, acao, motivo: prtVal(bg, 'motivo') }, acao === 'cancelar' ? 'Protocolo cancelado' : 'Protocolo reaberto');
}
async function prtUpload(id, inp) {
  const f = inp.files && inp.files[0]; if (!f) return;
  if (f.size > 15 * 1024 * 1024) { prtToast('Arquivo maior que 15 MB.', true); inp.value = ''; return; }
  const fd = new FormData(); fd.append('id', id); fd.append('arquivo', f);
  try { await prtApi('protocolos_doc_enviar', { method: 'POST', body: fd }); prtToast('Via assinada anexada'); await protocolosInit(); }
  catch (e) { prtToast(e.message, true); } finally { inp.value = ''; }
}

/* ---------- recebidos sem NF ---------- */
function prtRenderSemNf() {
  const L = PRT.p.sem_nf;
  prtEl('prtConteudo').innerHTML = L.length ? `<div class="panel"><p class="muted">Pedidos já recebidos que <b>não têm número de NF</b> cadastrado. Abra o pedido em <b>Pedidos</b>, informe a NF e eles aparecem aqui na tela de novo protocolo.${PRT.p.kpis.sem_nf > L.length ? ` (mostrando ${L.length} de ${PRT.p.kpis.sem_nf})` : ''}</p>
    <div class="prt-scroll"><table class="orders-table"><thead><tr><th>Pedido (PC)</th><th>Fornecedor</th><th>Recebido em</th></tr></thead><tbody>${L.map(x => `<tr><td><b>${prtH(x.pc)}</b></td><td>${prtH(x.fornecedor)}</td><td>${prtD(x.data)}</td></tr>`).join('')}</tbody></table></div></div>`
    : '<div class="empty">Todos os pedidos recebidos têm número de NF.</div>';
}
