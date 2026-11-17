/* Recebimento Drilling — Locação de equipamentos de fornecedores (v3.12.0) */
'use strict';
const LOC = { p: null, aba: 'ativas', q: '', local: '', forn: '', mes: '' };
const locEl = id => document.getElementById(id);
const locH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const locD = s => s ? new Date(s.slice(0, 10) + 'T12:00:00').toLocaleDateString('pt-BR') : '—';
const locR = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const locN = n => Number(n || 0).toLocaleString('pt-BR');
const locHoje = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const LOC_DOC_TIPOS = { NF_REMESSA: 'NF de remessa', NF_DEVOLUCAO: 'NF de devolução', COMPROVANTE: 'Comprovante de devolução', CHECKLIST: 'Checklist de entrega / retorno', CONTRATO: 'Contrato', FATURA: 'Fatura / boleto', OUTRO: 'Outro' };
const locPode = op => typeof rdaPermite !== 'function' || rdaPermite('estoque', op);
const LOC_TAR = { DIARIA: 'Diária', SEMANAL: 'Semanal', QUINZENAL: 'Quinzenal', MENSAL: 'Mensal' };
const LOC_MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const locMesNome = m => { const [a, b] = m.split('-'); return LOC_MES[b - 1] + '/' + a; };

async function locApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const q = opts.query ? '&' + new URLSearchParams(opts.query) : '';
  const r = await fetch(`api.php?action=${action}${q}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const locPost = (a, b) => locApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
const locToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));
function locCsv() { window.open('api.php?action=locacoes_csv', '_blank', 'noopener'); }

async function locacoesInit() {
  try {
    LOC.p = await locApi('locacoes_painel', { query: LOC.mes ? { mes: LOC.mes } : {} });
    const s = LOC.p.sugestoes || {};
    const dl = (id, arr) => { const el = locEl(id); if (el) el.innerHTML = (arr || []).map(x => `<option value="${locH(x)}">`).join(''); };
    dl('locLocais', s.locais); dl('locFornecedores', s.fornecedores); dl('locEquipamentos', s.equipamentos); dl('locCategorias', ['Máquina de solda', 'Furadeira', 'Lixadeira', 'Esmerilhadeira', 'Gerador', 'Compressor', 'Andaime', 'Betoneira', 'Martelete', 'Container', ...(s.categorias || [])].filter((x, i, a) => a.indexOf(x) === i));
    locRender();
  } catch (e) { locEl('locConteudo').innerHTML = `<div class="empty">${locH(e.message)}</div>`; }
}

function locAba(a) { LOC.aba = a; document.querySelectorAll('[data-loc-aba]').forEach(b => b.classList.toggle('active', b.dataset.locAba === a)); locRender(); }

function locRender() {
  const k = LOC.p.kpis;
  locEl('locKpis').innerHTML = `
    <div class="imp-kpi"><span>Locados agora</span><strong>${locN(k.equipamentos_ativos)}</strong><small>${locN(k.ativas)} contrato(s) · ${locN(k.em_obras)} em obra · ${locN(k.na_matriz)} na matriz</small></div>
    <div class="imp-kpi"><span>Custo por dia</span><strong>${locR(k.custo_dia_atual)}</strong><small>≈ ${locR(k.custo_dia_atual * 30)} / mês</small></div>
    <div class="imp-kpi"><span>Custo em ${locMesNome(k.mes)}</span><strong>${locR(k.custo_mes)}</strong><small>${k.mes === locHoje().slice(0, 7) ? 'projeção do mês ' + locR(k.projecao_mes) : 'fechado'}</small></div>
    <div class="imp-kpi ${k.vencidas ? 'bad' : 'ok'}"><span>Devolução vencida</span><strong>${locN(k.vencidas)}</strong><small>${locN(k.vencem_7)} vencem em 7 dias</small></div>
    <div class="imp-kpi"><span>Acumulado (ativas)</span><strong>${locR(k.custo_ativas)}</strong><small>desde a retirada</small></div>
    <div class="imp-kpi"><span>Total histórico</span><strong>${locR(k.custo_total)}</strong><small>todas as locações</small></div>`;
  const venc = LOC.p.locacoes.filter(l => l.vencida);
  const prox = LOC.p.locacoes.filter(l => l.status === 'ATIVA' && !l.vencida && l.vence_em !== null && l.vence_em <= 3);
  locEl('locAlertas').innerHTML = (venc.length ? `<div class="loc-alerta">⚠ ${venc.length} equipamento(s) passaram da previsão de devolução e continuam gerando custo: ${venc.slice(0, 5).map(l => `<b>${locH(l.equipamento)}</b> (${locH(l.local_atual)}, ${l.dias_vencida} dia(s))`).join(', ')}${venc.length > 5 ? '…' : ''} <button class="button ghost small" onclick="locAba('devolucoes')">Abrir controle</button></div>` : '')
    + (prox.length ? `<div class="loc-alerta" style="background:#fffaeb;border-color:#fedf89;color:#93370d">⏰ Devolução nos próximos 3 dias: ${prox.slice(0, 5).map(l => `<b>${locH(l.equipamento)}</b> (${l.vence_em === 0 ? 'HOJE' : l.vence_em === 1 ? 'amanhã' : 'em ' + l.vence_em + ' dias'})`).join(', ')}${prox.length > 5 ? '…' : ''} <button class="button small" style="background:#fff;color:#93370d;border:1px solid #93370d" onclick="locAba('devolucoes')">Abrir controle</button></div>` : '');
  if (LOC.aba === 'ativas') return locRenderLista(true);
  if (LOC.aba === 'historico') return locRenderLista(false);
  if (LOC.aba === 'devolucoes') return locRenderDevolucoes();
  if (LOC.aba === 'custos') return locRenderCustos();
  return locRenderFornecedores();
}

function locFiltros(ativas) {
  const s = LOC.p.sugestoes;
  return `<div class="loc-filtros"><input type="search" placeholder="Buscar equipamento, patrimônio, contrato…" value="${locH(LOC.q)}" oninput="LOC.q=this.value;locRenderTabela(${ativas})">
    <select onchange="LOC.local=this.value;locRenderTabela(${ativas})"><option value="">Todos os locais</option>${(s.locais || []).map(x => `<option ${x === LOC.local ? 'selected' : ''}>${locH(x)}</option>`).join('')}</select>
    <select onchange="LOC.forn=this.value;locRenderTabela(${ativas})"><option value="">Todos os fornecedores</option>${(s.fornecedores || []).map(x => `<option ${x === LOC.forn ? 'selected' : ''}>${locH(x)}</option>`).join('')}</select></div>`;
}
function locRenderLista(ativas) {
  locEl('locConteudo').innerHTML = locFiltros(ativas) + '<div id="locTabela"></div>';
  locRenderTabela(ativas);
}
function locRenderTabela(ativas) {
  const q = LOC.q.toLowerCase();
  const l = LOC.p.locacoes.filter(x => (ativas ? x.status === 'ATIVA' : true)
    && (!LOC.local || x.local_atual === LOC.local || Object.keys(x.custo.por_local).includes(LOC.local))
    && (!LOC.forn || x.fornecedor === LOC.forn)
    && (!q || [x.id, x.equipamento, x.patrimonio, x.contrato, x.pc, x.categoria, x.fornecedor, x.local_atual].join(' ').toLowerCase().includes(q)));
  const tot = l.reduce((s, x) => s + x.custo.total, 0), dia = l.filter(x => x.status === 'ATIVA').reduce((s, x) => s + x.custo.valor_dia, 0);
  locEl('locTabela').innerHTML = `<div class="table-panel"><div class="table-scroll"><table class="orders-table loc-tab">
    <thead><tr><th>Equipamento</th><th>Fornecedor / contrato</th><th>Onde está</th><th>Tarifa</th><th>Período</th><th>Custo</th><th></th></tr></thead>
    <tbody>${l.map(locLinha).join('') || `<tr><td colspan="7" class="empty">${ativas ? 'Nenhum equipamento locado no momento. Clique em “＋ Nova locação”.' : 'Nenhuma locação encontrada.'}</td></tr>`}</tbody>
    ${l.length ? `<tfoot><tr><td colspan="5"><b>${l.length} locação(ões)</b>${dia ? ` · custo atual ${locR(dia)}/dia` : ''}</td><td colspan="2"><b>${locR(tot)}</b></td></tr></tfoot>` : ''}</table></div></div>`;
}
function locLinha(x) {
  const c = x.custo;
  const prazo = x.status !== 'ATIVA' ? `<span class="est-badge ok">Devolvida${x.fim ? ' ' + locD(x.fim) : ''}</span>`
    : x.vencida ? `<span class="est-badge bad">vencida há ${x.dias_vencida} d</span>` : x.previsao_devolucao ? `<small class="muted">devolver até ${locD(x.previsao_devolucao)}${x.vence_em <= 7 ? ` <span class="est-badge warn">${x.vence_em} d</span>` : ''}</small>` : '<small class="muted">sem previsão</small>';
  return `<tr class="${x.vencida ? 'fer-atrasado' : ''}">
    <td><b>${x.quantidade > 1 ? x.quantidade + '× ' : ''}${locH(x.equipamento)}</b><br><small class="muted"><code>${locH(x.id)}</code>${(x.documentos || []).length ? ' · 📎 ' + x.documentos.length : ''}${x.patrimonio ? ' · pat. ' + locH(x.patrimonio) : ''}${x.categoria ? ' · ' + locH(x.categoria) : ''}</small></td>
    <td>${locH(x.fornecedor)}<br><small class="muted">${x.contrato ? 'contrato ' + locH(x.contrato) : ''}${x.pc ? ' · PC ' + locH(x.pc) : ''}</small></td>
    <td><span class="loc-local ${x.local_atual === 'MATRIZ' ? 'matriz' : ''}">${locH(x.local_atual)}</span>${c.segmentos.length > 1 ? `<br><small class="muted">${c.segmentos.length} locais no período</small>` : ''}</td>
    <td>${x.tarifa_valor > 0 ? `${locR(x.tarifa_valor)} <small class="muted">${LOC_TAR[x.tarifa_tipo]}</small><br><small class="muted">${locR(c.valor_dia)}/dia</small>` : '<small class="muted">não informada</small>'}</td>
    <td>${x.inicio ? `${locD(x.inicio)} → ${x.fim ? locD(x.fim) : (x.status === 'ATIVA' ? 'hoje' : '—')}<br><small class="muted">${c.dias} dia(s)</small>` : '<small class="muted">sem datas</small><br>'} ${prazo}${x.nf_devolucao ? `<br><small class="muted">NF devolução ${locH(x.nf_devolucao)}</small>` : ''}</td>
    <td>${c.total || x.tarifa_valor > 0 ? `<b>${locR(c.total)}</b><br><small class="muted">aluguel ${locR(c.aluguel)}${c.extras ? ' + extras ' + locR(c.extras) : ''}</small>` : '<small class="muted">sem custo lançado</small>'}</td>
    <td class="est-actions"><button class="button ghost small" onclick="locFicha('${locH(x.id)}')">Detalhes</button>
    ${x.status === 'ATIVA' ? `<button class="button ghost small" onclick="locTransferir('${locH(x.id)}')">Mover</button><button class="button primary small" onclick="locDevolver('${locH(x.id)}')">Devolver</button>` : ''}</td></tr>`;
}

function locBarras(rows, label, val, extra) {
  const max = Math.max(1, ...rows.map(val));
  return rows.map(r => `<div class="estd-bar"><div class="estd-bar-lbl"><span>${locH(label(r))}</span><strong>${extra(r)}</strong></div><div class="estd-bar-track"><i style="width:${Math.max(1.5, val(r) / max * 100)}%"></i></div></div>`).join('') || '<div class="notice-empty">Sem dados.</div>';
}
function locMeses() {
  const atual = LOC.p.kpis.mes, opts = [];
  for (let i = 0; i < 18; i++) { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - i); const m = d.toISOString().slice(0, 7); opts.push(`<option value="${m}" ${m === atual ? 'selected' : ''}>${locMesNome(m)}</option>`); }
  return `<select onchange="LOC.mes=this.value;locacoesInit()">${opts.join('')}</select>`;
}
function locRenderCustos() {
  const p = LOC.p, k = p.kpis, max = Math.max(1, ...p.mensal.map(m => m.valor));
  locEl('locConteudo').innerHTML = `<div class="loc-grid">
    <article class="panel loc-wide"><div class="panel-heading"><div><h2>Custo por obra / local</h2><p>Aluguel rateado pelos dias que o equipamento ficou em cada local + custos extras</p></div><label class="loc-mes">Mês ${locMeses()}</label></div>
      <div class="table-scroll"><table class="orders-table"><thead><tr><th>Local</th><th>Equipamentos agora</th><th>Custo/dia agora</th><th>Custo em ${locMesNome(k.mes)}</th><th>Total acumulado</th></tr></thead>
      <tbody>${p.por_local.map(r => `<tr><td><span class="loc-local ${r.local === 'MATRIZ' ? 'matriz' : ''}">${locH(r.local)}</span></td><td>${locN(r.ativos)}</td><td>${locR(r.custo_dia)}</td><td><b>${locR(r.mes)}</b></td><td>${locR(r.total)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem locações.</td></tr>'}</tbody>
      <tfoot><tr><td><b>Total</b></td><td>${locN(k.equipamentos_ativos)}</td><td>${locR(k.custo_dia_atual)}</td><td><b>${locR(k.custo_mes)}</b></td><td>${locR(k.custo_total)}</td></tr></tfoot></table></div></article>
    <article class="panel"><div class="panel-heading"><div><h2>Custo mensal</h2><p>Últimos 12 meses</p></div></div>
      <div class="loc-colunas">${p.mensal.map(m => `<div title="${locMesNome(m.mes)}: ${locR(m.valor)}"><i style="height:${Math.max(2, m.valor / max * 100)}%"></i><small>${LOC_MES[m.mes.slice(5) - 1]}</small></div>`).join('')}</div></article>
    <article class="panel"><div class="panel-heading"><div><h2>Por tipo de equipamento</h2><p>Custo total por categoria</p></div></div>
      <div class="estd-bars">${locBarras(p.por_categoria, r => r.categoria, r => r.total, r => `${locR(r.total)} <small>${r.ativas} ativa(s)</small>`)}</div></article>
  </div>`;
}
function locRenderFornecedores() {
  const p = LOC.p;
  locEl('locConteudo').innerHTML = `<div class="loc-grid"><article class="panel loc-wide"><div class="panel-heading"><div><h2>Locadoras</h2><p>Quanto foi gasto com cada fornecedor</p></div><label class="loc-mes">Mês ${locMeses()}</label></div>
    <div class="table-scroll"><table class="orders-table"><thead><tr><th>Fornecedor</th><th>Ativas</th><th>Locações</th><th>Custo em ${locMesNome(p.kpis.mes)}</th><th>Total</th></tr></thead>
    <tbody>${p.por_fornecedor.map(r => `<tr><td><b>${locH(r.fornecedor)}</b></td><td>${r.ativas}</td><td>${r.locacoes}</td><td>${locR(r.mes)}</td><td><b>${locR(r.total)}</b></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem locações.</td></tr>'}</tbody></table></div></article>
    <article class="panel"><div class="panel-heading"><div><h2>Participação</h2><p>Custo total</p></div></div><div class="estd-bars">${locBarras(p.por_fornecedor, r => r.fornecedor, r => r.total, r => locR(r.total))}</div></article></div>`;
}

/* ---------------- formulários ---------------- */
function locModal(titulo, html, largura) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(${largura || 640}px,100%)"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div>${html}</div>`;
  document.body.appendChild(bg);
  bg.querySelectorAll('[data-x],[data-x2]').forEach(b => b.onclick = () => bg.remove());
  return bg;
}
const locVal = (bg, n) => { const el = bg.querySelector(`[name="${n}"]`); return el ? el.value : ''; };
async function locEnviar(bg, action, body, msg) {
  const btn = bg.querySelector('[data-ok]'); if (btn) btn.disabled = true;
  try { await locPost(action, body); bg.remove(); locToast(msg); await locacoesInit(); }
  catch (e) { locToast(e.message, true); if (btn) btn.disabled = false; }
}

function locNova(id) {
  const x = id ? LOC.p.locacoes.find(l => l.id === id) : null;
  const v = (c, d = '') => locH(x ? (x[c] ?? d) : d);
  const bg = locModal(x ? 'Editar locação ' + locH(x.id) : 'Nova locação de equipamento', `
    <div class="rd-ass-campos loc-form">
      <label>Fornecedor (locadora)*<input name="fornecedor" list="locFornecedores" required value="${v('fornecedor')}" placeholder="Ex.: LOCAFAZ"></label>
      <label>Equipamento*<input name="equipamento" list="locEquipamentos" required value="${v('equipamento')}" placeholder="Ex.: Máquina de solda inversora 250A"></label>
      <label>Tipo<input name="categoria" list="locCategorias" value="${v('categoria')}" placeholder="Ex.: Máquina de solda"></label>
      <label>Quantidade<input name="quantidade" type="number" min="1" value="${v('quantidade', 1)}"></label>
      <label>Patrimônio / nº série da locadora<input name="patrimonio" value="${v('patrimonio')}"></label>
      <label>Nº contrato / NF de remessa<input name="contrato" value="${v('contrato')}"></label>
      <label>PC (se houver)<input name="pc" inputmode="numeric" value="${v('pc')}"></label>
      <label>Responsável<input name="responsavel" value="${v('responsavel')}" placeholder="Quem solicitou / responde"></label>
      <label>Cobrança<select name="tarifa_tipo">${Object.entries(LOC_TAR).map(([k, n]) => `<option value="${k}" ${(x ? x.tarifa_tipo : 'MENSAL') === k ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
      <label>Valor da tarifa (R$, por unidade) — opcional<input name="tarifa_valor" type="number" step="0.01" min="0" value="${v('tarifa_valor')}"></label>
      <label>Período mínimo cobrado (dias)<input name="minimo_dias" type="number" min="0" value="${v('minimo_dias', 0)}"></label>
      <label>Retirada / início — opcional<input name="inicio" type="date" value="${x ? v('inicio') : locHoje()}"></label>
      ${x ? (x.status === 'DEVOLVIDA' ? `<label>Devolução<input name="fim" type="date" value="${v('fim')}"></label><label>NF de devolução<input name="nf_devolucao" value="${v('nf_devolucao')}"></label>` : '') : `<label>Vai para*<input name="local" list="locLocais" required value="MATRIZ" placeholder="MATRIZ ou nome da obra"></label>
      <label>Frete de entrega (R$)<input name="frete_entrega" type="number" step="0.01" min="0" value=""></label>`}
      <label>Previsão de devolução<input name="previsao_devolucao" type="date" value="${v('previsao_devolucao')}"></label>
    </div>
    <label class="loc-obs">Observação<textarea name="observacao" rows="2">${v('observacao')}</textarea></label>
    <p class="muted loc-dica">Obrigatórios: fornecedor, equipamento e (de preferência) patrimônio. Tarifa e datas são opcionais — preencha só se quiser que o sistema calcule o custo. O custo é calculado pela tarifa dividida em dias (semanal ÷ 7, quinzenal ÷ 15, mensal ÷ 30), contando o dia da retirada e o da devolução, e rateado entre os locais por onde o equipamento passou.</p>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>${x ? 'Salvar alterações' : 'Registrar locação'}</button></div>`, 760);
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = () => {
    const b = {}; bg.querySelectorAll('[name]').forEach(el => b[el.name] = el.value);
    if (x) b.id = x.id;
    locEnviar(bg, 'locacoes_salvar', b, x ? 'Locação atualizada.' : 'Locação registrada.');
  };
}

function locTransferir(id) {
  const x = LOC.p.locacoes.find(l => l.id === id);
  const bg = locModal('Mover equipamento', `<p><b>${locH(x.equipamento)}</b> está em <b>${locH(x.local_atual)}</b>.</p>
    <div class="rd-ass-campos"><label>Novo local*<input name="local" list="locLocais" required placeholder="MATRIZ ou obra"></label>
    <label>Data*<input name="data" type="date" value="${locHoje()}" min="${locH(x.inicio)}"></label>
    <label>Frete da transferência (R$)<input name="frete" type="number" step="0.01" min="0"></label>
    <label>Observação<input name="obs"></label></div>
    <p class="muted loc-dica">A partir dessa data o custo diário passa a ser cobrado do novo local.</p>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Confirmar transferência</button></div>`);
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = () => locEnviar(bg, 'locacoes_transferir', { id, local: locVal(bg, 'local'), data: locVal(bg, 'data'), frete: locVal(bg, 'frete'), obs: locVal(bg, 'obs') }, 'Equipamento transferido.');
}

function locDevolver(id) {
  const x = LOC.p.locacoes.find(l => l.id === id);
  const bg = locModal('Devolver à locadora', `<p><b>${locH(x.equipamento)}</b> · ${locH(x.fornecedor)} · em ${locH(x.local_atual)}</p>
    <div class="rd-ass-campos"><label>NF de devolução<input name="nf_devolucao" placeholder="Ex.: 13685"></label>
    <label>Data da devolução${x.inicio ? '*' : ' (opcional)'}<input name="data" type="date" value="${x.inicio ? locHoje() : ''}" ${x.inicio ? 'min="' + locH(x.inicio) + '"' : ''}></label>
    <label>Frete de devolução (R$)<input name="frete" type="number" step="0.01" min="0"></label>
    <label>Avaria cobrada (R$)<input name="avaria" type="number" step="0.01" min="0"></label>
    <label>Descrição da avaria<input name="avaria_desc"></label>
    <label>Observação<input name="obs" placeholder="Ex.: devolvida com nota de retorno nº…"></label></div>
    <div class="loc-prev" data-prev></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Confirmar devolução</button></div>`);
  const prev = () => {
    const dt = locVal(bg, 'data'); if (!dt || !x.inicio || !x.tarifa_valor) { bg.querySelector('[data-prev]').innerHTML = ''; return; }
    const dias = Math.max(1, Math.round((new Date(dt + 'T12:00') - new Date(x.inicio + 'T12:00')) / 864e5) + 1), cob = Math.max(dias, x.minimo_dias || 0);
    const t = cob * x.custo.valor_dia + x.custo.extras + Number(locVal(bg, 'frete') || 0) + Number(locVal(bg, 'avaria') || 0);
    bg.querySelector('[data-prev]').innerHTML = `Custo final estimado: <b>${locR(t)}</b> <small class="muted">(${cob} dia(s) × ${locR(x.custo.valor_dia)} + extras)</small>`;
  };
  bg.addEventListener('input', prev); prev();
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = () => locEnviar(bg, 'locacoes_devolver', { id, nf_devolucao: locVal(bg, 'nf_devolucao'), data: locVal(bg, 'data'), frete: locVal(bg, 'frete'), avaria: locVal(bg, 'avaria'), avaria_desc: locVal(bg, 'avaria_desc'), obs: locVal(bg, 'obs') }, 'Devolução registrada.');
}

function locExtra(id) {
  const x = LOC.p.locacoes.find(l => l.id === id);
  const locais = [...new Set(x.custo.segmentos.map(s => s.local))];
  const bg = locModal('Lançar custo extra', `<p><b>${locH(x.equipamento)}</b> · ${locH(x.fornecedor)}</p>
    <div class="rd-ass-campos"><label>Tipo<select name="tipo">${Object.entries(LOC.p.extras).map(([k, n]) => `<option value="${k}">${locH(n)}</option>`).join('')}</select></label>
    <label>Valor (R$)*<input name="valor" type="number" step="0.01"></label>
    <label>Data*<input name="data" type="date" value="${locHoje()}"></label>
    <label>Cobrar de<select name="local">${locais.map(l => `<option ${l === x.local_atual ? 'selected' : ''}>${locH(l)}</option>`).join('')}</select></label>
    <label>Documento (NF/fatura)<input name="documento"></label><label>Descrição<input name="descricao"></label></div>
    <p class="muted loc-dica">Use “Ajuste de fatura” (pode ser negativo) para igualar o custo calculado ao valor cobrado pela locadora.</p>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Lançar</button></div>`);
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = () => { const b = { id }; bg.querySelectorAll('[name]').forEach(el => b[el.name] = el.value); locEnviar(bg, 'locacoes_extra', b, 'Custo lançado.'); };
}

async function locAcao(action, id, pergunta, msg, extra = {}) {
  if (pergunta && !confirm(pergunta)) return;
  try { await locPost(action, { id, ...extra }); document.querySelector('.rd-ass-bg')?.remove(); locToast(msg); await locacoesInit(); }
  catch (e) { locToast(e.message, true); }
}

function locFicha(id) {
  const x = LOC.p.locacoes.find(l => l.id === id), c = x.custo;
  const tipoMov = { INICIO: 'Retirada', TRANSFERENCIA: 'Transferência', DEVOLUCAO: 'Devolução' };
  const bg = locModal(`${locH(x.equipamento)} <small class="muted">${locH(x.id)}</small>`, `
    <div class="loc-ficha-top">
      <div><small>Fornecedor</small><b>${locH(x.fornecedor)}</b></div><div><small>Patrimônio</small><b>${locH(x.patrimonio || '—')}</b></div><div><small>Situação</small><b>${x.status === 'ATIVA' ? 'Locado · ' + locH(x.local_atual) : 'Devolvido' + (x.nf_devolucao ? ' · NF ' + locH(x.nf_devolucao) : '')}</b></div>
      <div><small>Tarifa</small><b>${x.tarifa_valor > 0 ? locR(x.tarifa_valor) + ' ' + LOC_TAR[x.tarifa_tipo] + (x.quantidade > 1 ? ' × ' + x.quantidade : '') : '—'}</b></div>
      <div><small>Valor por dia</small><b>${locR(c.valor_dia)}</b></div><div><small>Dias cobrados</small><b>${c.dias}</b></div>
      <div><small>Aluguel</small><b>${locR(c.aluguel)}</b></div><div><small>Extras</small><b>${locR(c.extras)}</b></div><div class="tot"><small>Custo total</small><b>${locR(c.total)}</b></div>
    </div>
    <p class="muted">${[x.patrimonio && 'Patrimônio ' + locH(x.patrimonio), x.contrato && 'Contrato ' + locH(x.contrato), x.pc && 'PC ' + locH(x.pc), x.responsavel && 'Responsável ' + locH(x.responsavel), x.minimo_dias && 'mínimo ' + x.minimo_dias + ' dias'].filter(Boolean).join(' · ')}${x.observacao ? '<br>' + locH(x.observacao) : ''}</p>
    <h3>Custo por local</h3>
    <table class="orders-table"><thead><tr><th>Local</th><th>Período</th><th>Dias</th><th>Custo</th></tr></thead><tbody>
      ${c.segmentos.map(s => `<tr><td>${locH(s.local)}</td><td>${locD(s.de)} → ${locD(s.ate)}</td><td>${s.dias}</td><td>${locR(s.dias * c.valor_dia)}</td></tr>`).join('')}</tbody>
      <tfoot>${Object.entries(c.por_local).map(([l, v]) => `<tr><td colspan="3">Total ${locH(l)} (com extras)</td><td><b>${locR(v)}</b></td></tr>`).join('')}</tfoot></table>
    <h3>Custos extras</h3>
    ${(x.extras || []).length ? `<table class="orders-table"><tbody>${x.extras.map(e => `<tr><td>${locD(e.data)}</td><td>${locH(LOC.p.extras[e.tipo] || e.tipo)}<br><small class="muted">${locH(e.descricao)}${e.documento ? ' · ' + locH(e.documento) : ''}</small></td><td>${locH(e.local)}</td><td>${locR(e.valor)}</td>
      <td><button class="button ghost small" onclick="locAcao('locacoes_extra','${locH(x.id)}','Remover este custo?','Custo removido.',{remover:'${locH(e.id)}'})">Remover</button></td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nenhum custo extra.</p>'}
    <h3>Documentos <small class="muted">(notas, comprovantes, contrato)</small></h3>
    <div class="loc-docs">${(x.documentos || []).length ? x.documentos.map(a => `<div class="loc-doc"><a class="loc-doc-link" href="${locH(a.url)}" target="_blank" rel="noopener">${a.mime === 'application/pdf' ? '📄' : '🖼'} <b>${locH(a.nome)}</b></a>
        <small class="muted">${locH(a.tipo_label)} · ${(a.bytes / 1048576).toFixed(2)} MB · ${locD(a.em)}</small>
        <span class="loc-doc-acoes"><a class="button ghost small" href="${locH(a.url)}&baixar=1">Baixar</a>${locPode('editar') ? `<button type="button" class="button ghost small" onclick="locDocRemover('${locH(x.id)}',${a.idx})">Remover</button>` : ''}</span></div>`).join('') : '<p class="muted">Nenhum documento anexado.</p>'}</div>
    ${locPode('anexar') ? `<div class="loc-doc-add"><select data-doc-tipo>${Object.entries(LOC_DOC_TIPOS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
      <label class="button ghost small loc-doc-btn"><input type="file" accept="application/pdf,image/*" multiple hidden onchange="locDocEnviar('${locH(x.id)}',this)">＋ Anexar documento</label><small class="muted" data-doc-st></small></div>` : ''}
    <h3>Movimentações</h3>
    <ul class="loc-mov">${x.movimentos.map(m => `<li><b>${m.data ? locD(m.data) : 'sem data'}</b> · ${tipoMov[m.tipo] || m.tipo} · ${locH(m.local)}${m.obs ? ' — ' + locH(m.obs) : ''} <small class="muted">(${locH(m.usuario)})</small></li>`).join('')}</ul>
    <div class="rd-ass-acoes loc-acoes">
      <button class="button ghost small" onclick="this.closest('.rd-ass-bg').remove();locNova('${locH(x.id)}')">Editar</button>
      <button class="button ghost small" onclick="this.closest('.rd-ass-bg').remove();locExtra('${locH(x.id)}')">＋ Custo extra</button>
      ${x.status === 'ATIVA' ? `${x.movimentos.some(m => m.tipo === 'TRANSFERENCIA') ? `<button class="button ghost small" onclick="locAcao('locacoes_desfazer_transferencia','${locH(x.id)}','Desfazer a última transferência?','Transferência desfeita.')">Desfazer transferência</button>` : ''}`
        : `<button class="button ghost small" onclick="locAcao('locacoes_reabrir','${locH(x.id)}','Reabrir esta locação? A devolução será desfeita.','Locação reaberta.')">Reabrir</button>`}
      <button class="button ghost small loc-del" onclick="locAcao('locacoes_excluir','${locH(x.id)}','Excluir esta locação? Ela fica guardada no arquivo de excluídas.','Locação excluída.')">Excluir</button>
    </div>`, 820);
}

/* v3.35.0 — documentos da locação */
async function locDocEnviar(id, inp) {
  const box = inp.closest('.loc-doc-add'), tipo = box.querySelector('[data-doc-tipo]').value, st = box.querySelector('[data-doc-st]');
  const arqs = [...inp.files]; inp.value = ''; if (!arqs.length) return;
  let ok = 0;
  for (const f of arqs) {
    if (f.size > 15 * 1048576) { locToast(`${f.name}: maior que 15 MB.`, true); continue; }
    st.textContent = `Enviando ${f.name}…`;
    try { const fd = new FormData(); fd.append('id', id); fd.append('tipo', tipo); fd.append('arquivo', f, f.name); await locApi('locacoes_doc_enviar', { method: 'POST', body: fd }); ok++; }
    catch (e) { locToast(`${f.name}: ${e.message}`, true); }
  }
  st.textContent = '';
  if (ok) { locToast(ok + ' documento(s) anexado(s).'); document.querySelector('.rd-ass-bg')?.remove(); await locacoesInit(); locFicha(id); }
}
async function locDocRemover(id, i) {
  if (!confirm('Remover este documento da locação? (o arquivo continua guardado no servidor por segurança)')) return;
  try { await locPost('locacoes_doc_remover', { id, i }); document.querySelector('.rd-ass-bg')?.remove(); await locacoesInit(); locFicha(id); locToast('Documento removido.'); }
  catch (e) { locToast(e.message, true); }
}


/* ===== v3.41.0 — Controle de devoluções (previsão de devolução) ===== */
LOC.devFiltro = 'todas';
function locDevSituacao(x) {
  if (x.vencida) return `<span class="est-badge bad">vencida há ${x.dias_vencida} d</span>`;
  if (x.vence_em === 0) return '<span class="est-badge bad">vence HOJE</span>';
  if (x.vence_em === 1) return '<span class="est-badge warn">vence amanhã</span>';
  if (x.vence_em !== null && x.vence_em <= 7) return `<span class="est-badge warn">em ${x.vence_em} dias</span>`;
  if (x.vence_em !== null) return `<span class="est-badge ok">em ${x.vence_em} dias</span>`;
  return '<span class="est-badge">sem previsão</span>';
}
function locDevLista() {
  const f = LOC.devFiltro;
  const a = LOC.p.locacoes.filter(x => x.status === 'ATIVA');
  const dias = x => x.vencida ? -x.dias_vencida : (x.vence_em === null ? 99999 : x.vence_em);
  const ok = { todas: () => true, vencidas: x => x.vencida, hoje: x => x.vence_em === 0, d7: x => x.vence_em !== null && x.vence_em <= 7, d30: x => x.vence_em !== null && x.vence_em <= 30, sem: x => !x.previsao_devolucao }[f] || (() => true);
  return a.filter(ok).sort((p, q) => dias(p) - dias(q));
}
function locDevFiltro(f) { LOC.devFiltro = f; locRenderDevolucoes(); }
function locRenderDevolucoes() {
  const a = LOC.p.locacoes.filter(x => x.status === 'ATIVA');
  const n = { todas: a.length, vencidas: a.filter(x => x.vencida).length, hoje: a.filter(x => x.vence_em === 0).length, d7: a.filter(x => x.vence_em !== null && x.vence_em <= 7).length,
    d30: a.filter(x => x.vence_em !== null && x.vence_em <= 30).length, sem: a.filter(x => !x.previsao_devolucao).length };
  const chip = (k, t) => `<button class="est-tab ${LOC.devFiltro === k ? 'active' : ''}" onclick="locDevFiltro('${k}')">${t} (${n[k]})</button>`;
  const l = locDevLista();
  locEl('locConteudo').innerHTML = `
    <div class="imp-filtros" style="margin-bottom:10px">${chip('todas', 'Todas ativas')}${chip('vencidas', '🔴 Vencidas')}${chip('hoje', 'Vencem hoje')}${chip('d7', 'Próx. 7 dias')}${chip('d30', 'Próx. 30 dias')}${chip('sem', 'Sem previsão')}
      <span style="flex:1"></span>
      <button class="button ghost small" onclick="locAvisoZap()">💬 Resumo p/ WhatsApp</button>
      <button class="button ghost small" onclick="window.open('api.php?action=locacoes_ics','_blank','noopener')">📅 Calendário (.ics)</button>
      <button class="button ghost small" onclick="locAlertasConfig()">🔔 Configurar avisos</button></div>
    ${l.length ? `<div class="table-wrap"><table class="est-table"><thead><tr><th>Equipamento</th><th>Onde está / responsável</th><th>Devolver até</th><th>Situação</th><th>Custo acumulado</th><th></th></tr></thead><tbody>${l.map(x => `
      <tr class="${x.vencida ? 'fer-atrasado' : ''}"><td><b>${x.quantidade > 1 ? x.quantidade + '× ' : ''}${locH(x.equipamento)}</b><br><small class="muted"><code>${locH(x.id)}</code> · ${locH(x.fornecedor)}${x.contrato ? ' · contrato ' + locH(x.contrato) : ''}</small></td>
      <td>${locH(x.local_atual)}${x.responsavel ? `<br><small class="muted">${locH(x.responsavel)}</small>` : ''}</td>
      <td>${x.previsao_devolucao ? locD(x.previsao_devolucao) : '—'}${(x.prorrogacoes || []).length ? `<br><small class="muted">prorrogada ${(x.prorrogacoes || []).length}×</small>` : ''}</td>
      <td>${locDevSituacao(x)}</td><td>${locR(x.custo.total)}</td>
      <td class="est-actions"><button class="button ghost small" onclick="locProrrogar('${locH(x.id)}')">Prorrogar</button><button class="button ghost small" title="Lembrar pelo WhatsApp" onclick="locAvisoZap('${locH(x.id)}')">💬</button><button class="button primary small" onclick="locDevolver('${locH(x.id)}')">Devolver</button></td></tr>`).join('')}</tbody></table></div>`
      : '<div class="notice-empty">Nenhuma locação neste filtro.</div>'}
    <p class="muted" style="margin-top:10px">Avisos automáticos: sino/push, e-mail e esta tela. Equipamento vencido continua gerando custo até a devolução.</p>`;
}
function locProrrogar(id) {
  const x = LOC.p.locacoes.find(l => l.id === id);
  const hist = (x.prorrogacoes || []).map(p => `<li>${locD(p.de)} → <b>${locD(p.para)}</b> · ${locH(p.motivo)} <small class="muted">(${locH(p.usuario)}, ${locD(p.em)})</small></li>`).join('');
  const bg = locModal('Prorrogar devolução', `<p><b>${locH(x.equipamento)}</b> · ${locH(x.fornecedor)} · em ${locH(x.local_atual)}<br><small class="muted">Previsão atual: ${x.previsao_devolucao ? locD(x.previsao_devolucao) : 'não informada'}</small></p>
    <div class="rd-ass-campos"><label>Nova data de devolução*<input name="nova_data" type="date" value="${x.previsao_devolucao ? '' : locHoje()}" ${x.inicio ? 'min="' + locH(x.inicio) + '"' : ''}></label>
    <label>Motivo*<input name="motivo" maxlength="300" placeholder="Ex.: obra atrasou, locadora autorizou mais 15 dias"></label></div>
    ${hist ? `<p class="muted" style="margin:10px 0 2px">Prorrogações anteriores:</p><ul style="margin:0 0 6px 18px">${hist}</ul>` : ''}
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Salvar nova data</button></div>`);
  bg.querySelector('[data-ok]').onclick = () => locEnviar(bg, 'locacoes_prorrogar', { id, nova_data: locVal(bg, 'nova_data'), motivo: locVal(bg, 'motivo') }, 'Previsão de devolução atualizada.');
}
function locAvisoZap(id) {
  const lista = id ? LOC.p.locacoes.filter(l => l.id === id) : locDevLista().filter(x => x.previsao_devolucao && x.vence_em !== null ? x.vence_em <= 7 : !!x.vencida);
  if (!lista.length) { locToast('Nada vencido ou próximo do vencimento para avisar.', true); return; }
  const lin = x => `• ${x.equipamento} (${x.fornecedor}, ${x.local_atual}) — devolver até ${locD(x.previsao_devolucao)}: ${x.vencida ? 'VENCIDA há ' + x.dias_vencida + ' dia(s)' : x.vence_em === 0 ? 'vence HOJE' : 'em ' + x.vence_em + ' dia(s)'}`;
  const txt = `*Devolução de locação*\n${lista.map(lin).join('\n')}\n\nDevolver ou prorrogar no sistema.`;
  const num = (LOC.alertas && LOC.alertas.config && LOC.alertas.config.whatsapp) || '';
  window.open(`https://wa.me/${num}?text=${encodeURIComponent(txt)}`, '_blank', 'noopener');
}
async function locAlertasConfig() {
  let p; try { p = await locApi('locacoes_alertas_config'); } catch (e) { return locToast(e.message, true); }
  LOC.alertas = p; const c = p.config;
  const hist = (p.historico || []).map(h => `<li>${new Date(h.em).toLocaleString('pt-BR')} · ${h.qtd} item(ns)${h.vencidas ? ` (${h.vencidas} vencida/s)` : ''} · ${h.sino ? '🔔' : ''}${h.email ? '✉' : ''} <small class="muted">${locH(h.motivo)}</small></li>`).join('');
  const bg = locModal('Avisos de devolução', `
    <div class="rd-ass-campos">
      <label style="grid-column:1/-1"><span><input type="checkbox" name="ativo" ${c.ativo ? 'checked' : ''}> Avisar automaticamente</span></label>
      <label>Avisar quando faltarem (dias)<input name="marcos" value="${locH((c.marcos || []).join(', '))}" placeholder="7, 3, 1, 0"></label>
      <label>Repetir se vencida, a cada (horas)<input name="repetir" type="number" min="1" max="168" value="${c.repetir_vencida_horas}"></label>
      <label style="grid-column:1/-1"><span><input type="checkbox" name="sino" ${c.sino ? 'checked' : ''}> Sino e notificação push</span></label>
      <label style="grid-column:1/-1"><span><input type="checkbox" name="email" ${c.email ? 'checked' : ''}> Enviar e-mail</span></label>
      <label style="grid-column:1/-1">E-mails (separe por vírgula)<input name="emails" value="${locH((c.emails || []).join(', '))}" placeholder="${(p.email_geral || []).length ? 'vazio = usa os e-mails dos alertas de estoque: ' + locH(p.email_geral.join(', ')) : 'nome@empresa.com.br'}"></label>
      <label style="grid-column:1/-1">WhatsApp de destino (opcional, com DDI: 5511999999999)<input name="whatsapp" value="${locH(c.whatsapp || '')}"></label>
    </div>
    <p class="muted">Última verificação: ${p.ultima_verificacao ? new Date(p.ultima_verificacao).toLocaleString('pt-BR') : 'ainda não'} · último e-mail: ${p.ultimo_email ? new Date(p.ultimo_email).toLocaleString('pt-BR') : '—'}.
      O e-mail usa o SMTP configurado em Estoque → Alertas.</p>
    ${hist ? `<details><summary>Últimos avisos</summary><ul style="margin:6px 0 0 18px">${hist}</ul></details>` : ''}
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Fechar</button><button type="button" class="button ghost" data-agora>Enviar aviso agora</button><button type="button" class="button primary" data-ok>Salvar</button></div>`, 680);
  const corpo = () => ({ ativo: bg.querySelector('[name=ativo]').checked, marcos: locVal(bg, 'marcos').split(/[\s,;]+/).filter(Boolean), repetir_vencida_horas: locVal(bg, 'repetir'),
    sino: bg.querySelector('[name=sino]').checked, email: bg.querySelector('[name=email]').checked, emails: locVal(bg, 'emails'), whatsapp: locVal(bg, 'whatsapp') });
  bg.querySelector('[data-ok]').onclick = async () => { try { LOC.alertas = await locPost('locacoes_alertas_salvar', corpo()); bg.remove(); locToast('Avisos salvos.'); } catch (e) { locToast(e.message, true); } };
  bg.querySelector('[data-agora]').onclick = async () => {
    try { await locPost('locacoes_alertas_salvar', corpo()); const r = await locPost('locacoes_alertas_verificar', {}); const z = r.resultado || {};
      locToast(z.avisados ? `Aviso enviado (${z.avisados} item/ns)${z.sino ? ' · sino' : ''}${z.email ? ' · e-mail' : ''}${z.erro_email ? ' · e-mail falhou: ' + z.erro_email : ''}` : 'Nenhuma locação vencida ou próxima do vencimento.', !!z.erro_email); }
    catch (e) { locToast(e.message, true); } };
}
