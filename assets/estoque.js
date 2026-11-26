/* Recebimento Drilling — Módulo de Estoque v3.0.0
 * Fluxo: ENTRADA (pendente) → CONFERÊNCIA (linha a linha) → RECEBIMENTO (gera saldo) → SAÍDA
 * Somente leitura dos pedidos; nenhuma conferência de recebimento é alterada aqui.
 */
'use strict';

const EST = {
  page: 'painel',
  itens: [],
  entradas: [],
  movimentos: [],
  dashboard: null,
  entradaAtual: null,
  filtro: { q: '', status: '', categoria: '', sort: 'descricao' },
  movFiltro: { tipo: '', de: '', ate: '' },
  carregando: false,
};

const estEl = id => document.getElementById(id);
const estHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const estNum = n => Number(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
const estMoeda = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const estData = v => { if (!v) return '—'; const d = new Date(v); return isNaN(d) ? '—' : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); };
const estDia = v => { if (!v) return '—'; const d = new Date(v); return isNaN(d) ? '—' : d.toLocaleDateString('pt-BR'); };

async function estApi(action, opts = {}) {
  const { query, ...init } = opts;
  let extra = '';
  if (query instanceof URLSearchParams) extra = query.toString();
  else if (query && typeof query === 'object') {
    const qp = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) qp.append(k, v); });
    extra = qp.toString();
  }
  const url = `api.php?action=${encodeURIComponent(action)}${extra ? '&' + extra : ''}`;
  const res = await fetch(url, {
    cache: 'no-store',
    credentials: 'same-origin',
    ...init,
    headers: { 'X-Client-Id': typeof notificationClientId !== 'undefined' ? notificationClientId : 'estoque', ...(init.headers || {}) },
  });
  let data;
  try { data = await res.json(); } catch { throw new Error('Resposta inválida da API de estoque'); }
  if (!res.ok || !data.ok) throw new Error(data.error || `Erro ${res.status}`);
  return data;
}

function estPost(action, body) {
  return estApi(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
}

function estToast(msg, erro = false) {
  if (typeof toast === 'function') return toast(msg, erro);
  alert(msg);
}

/* ---------------- navegação interna ---------------- */

function estGo(tab) {
  EST.page = tab;
  document.querySelectorAll('[data-est-tab]').forEach(b => b.classList.toggle('active', b.dataset.estTab === tab));
  document.querySelectorAll('[data-est-page]').forEach(p => p.classList.toggle('active', p.dataset.estPage === tab));
  if (tab === 'painel') estLoadDashboard();
  if (tab === 'itens') estLoadItens();
  if (tab === 'entradas') estLoadEntradas();
  if (tab === 'saidas') estLoadRequisicoes();
  if (tab === 'movimentos') estLoadMovimentos();
  if (tab === 'inventario') estLoadInventarios();
  if (tab === 'sap') estSapHistorico();
  if (tab === 'funcionarios') funCarregar();
  if (tab === 'reservas') estLoadReservas();
  if (tab === 'reposicao') estLoadReposicao();
  if (tab === 'abc') estLoadAbc();
  if (tab === 'integridade') estLoadIntegridade();
  if (tab === 'etiquetas' && typeof etqInit === 'function') etqInit();
}

async function estoqueInit() {
  if (!estEl('page-estoque')) return;
  if (typeof rdaPermite === 'function' && !rdaPermite('estoque', 'ver')) return;   // v3.27.4
  await estLoadDashboard();
}

/* ---------------- painel ---------------- */

async function estLoadDashboard() {
  try {
    const r = await estApi('estoque_dashboard', { query: { dias: EST.dashDias || 90 } });
    EST.dashboard = r.dashboard;
    estRenderDashboard(r);
    estIaSilenciosa();
  } catch (e) {
    const host = estEl('estKpis');
    if (host) host.innerHTML = `<div class="est-erro">Não foi possível carregar o estoque: ${estHtml(e.message)}</div>`;
  }
}

const estK = n => { n = Number(n || 0); const a = Math.abs(n);
  return a >= 1e6 ? 'R$ ' + (n / 1e6).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' mi'
    : a >= 1e3 ? 'R$ ' + (n / 1e3).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : estMoeda(n); };
const estPct = (a, b) => b > 0 ? Math.round(a / b * 1000) / 10 : 0;
const ESTD_MES = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];

document.addEventListener('click', e => {
  const b = e.target.closest('.estd-seg button'); if (!b) return;
  EST.dashDias = Number(b.dataset.dias);
  document.querySelectorAll('.estd-seg button').forEach(x => x.classList.toggle('active', x === b));
  estLoadDashboard();
});

function estdBars(id, rows, label, valor, extra) {
  const el = estEl(id); if (!el) return;
  if (!rows.length) { el.innerHTML = '<div class="notice-empty">Sem dados no período.</div>'; return; }
  const max = Math.max(...rows.map(valor), 1);
  el.innerHTML = rows.map(r => `<div class="estd-bar" title="${estHtml(label(r))}">
    <div class="estd-bar-lbl"><span>${estHtml(label(r))}</span><strong>${extra(r)}</strong></div>
    <div class="estd-bar-track"><i style="width:${Math.max(1.5, valor(r) / max * 100)}%"></i></div></div>`).join('');
}

function estdMensal(rows) {
  const el = estEl('estdMensal'); if (!el) return;
  const W = 760, H = 230, P = { l: 58, r: 10, t: 12, b: 28 };
  const max = Math.max(1, ...rows.flatMap(r => [r.entradas, r.saidas]));
  const iw = (W - P.l - P.r) / rows.length, bw = Math.min(16, iw / 3.2);
  const y = v => P.t + (H - P.t - P.b) * (1 - v / max);
  let g = '';
  for (let k = 0; k <= 4; k++) { const v = max * k / 4, yy = y(v);
    g += `<line x1="${P.l}" x2="${W - P.r}" y1="${yy}" y2="${yy}" class="grid"/><text x="${P.l - 6}" y="${yy + 4}" text-anchor="end">${estK(v).replace('R$ ', '')}</text>`; }
  const maxInv = Math.max(1, ...rows.map(r => r.inventario));
  rows.forEach((r, i) => {
    const cx = P.l + iw * i + iw / 2, [ano, m] = r.mes.split('-');
    g += `<rect class="e" x="${cx - bw - 1}" y="${y(r.entradas)}" width="${bw}" height="${H - P.b - y(r.entradas)}" rx="3"><title>${ESTD_MES[m - 1]}/${ano} · Entradas ${estMoeda(r.entradas)}</title></rect>`;
    g += `<rect class="s" x="${cx + 1}" y="${y(r.saidas)}" width="${bw}" height="${H - P.b - y(r.saidas)}" rx="3"><title>${ESTD_MES[m - 1]}/${ano} · Saídas ${estMoeda(r.saidas)}</title></rect>`;
    if (r.inventario > 0) g += `<circle class="i" cx="${cx}" cy="${P.t + 4}" r="${3 + 5 * r.inventario / maxInv}"><title>Inventário SAP ${estMoeda(r.inventario)} (fora da escala)</title></circle>`;
    g += `<text x="${cx}" y="${H - 9}" text-anchor="middle">${ESTD_MES[m - 1]}${m === '01' ? '/' + ano.slice(2) : ''}</text>`;
  });
  el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Entradas e saídas por mês">${g}</svg>`;
}

function estdOrigem(o, total) {
  const parts = [['inventario', 'Inventário SAP', 'var(--rd-info, #5b8def)'], ['inventario+pedidos', 'Inventário + pedidos', 'var(--rd-success, #3fb68b)'], ['pedidos', 'Somente pedidos de compra', 'var(--rd-warning, #e0a43b)']];
  const tot = Math.max(1, parts.reduce((s, p) => s + (o[p[0]] || 0), 0));
  let ang = -Math.PI / 2, arcs = '';
  parts.forEach(([k, , cor]) => { const v = (o[k] || 0) / tot; if (v <= 0) return;
    const a2 = ang + v * 2 * Math.PI - (v >= 1 ? 0.0001 : 0), large = v > .5 ? 1 : 0;
    const p = (a, r) => [60 + r * Math.cos(a), 60 + r * Math.sin(a)];
    const [x1, y1] = p(ang, 52), [x2, y2] = p(a2, 52);
    arcs += `<path d="M${x1} ${y1} A52 52 0 ${large} 1 ${x2} ${y2}" stroke="${cor}" stroke-width="16" fill="none"/>`; ang = a2; });
  estEl('estdOrigem').innerHTML = `<div class="estd-donut"><svg viewBox="0 0 120 120">${arcs}<text x="60" y="58" text-anchor="middle" class="big">${estK(total)}</text><text x="60" y="73" text-anchor="middle">em estoque</text></svg>
    <ul>${parts.map(([k, n, cor]) => `<li><i style="background:${cor}"></i><span>${n}</span><strong>${estK(o[k])}</strong><small>${estPct(o[k] || 0, tot)}%</small></li>`).join('')}</ul></div>`;
}

/* Gemini em segundo plano: sem chat; as observações entram nos alertas quando ficam prontas. */
async function estIaSilenciosa() {
  const dias = EST.dashDias || 90;
  try {
    const r = await estApi('estoque_ia', { query: { dias } });
    if ((EST.dashDias || 90) !== dias || !(r.itens || []).length) return;
    const host = estEl('estdAlertas'); if (!host) return;
    host.querySelectorAll('.estd-ia').forEach(x => x.remove());
    host.insertAdjacentHTML('beforeend', r.itens.map(x =>
      `<button class="estd-alerta estd-ia ${estHtml(x.nivel)}" onclick="estGo('${estHtml(x.aba)}')">${estHtml(x.texto)} →</button>`).join(''));
  } catch (e) { /* silencioso */ }
}

function estRenderDashboard(r) {
  const d = r.dashboard || {}, a = r.analise || {}, k = a.kpis || {};
  const per = a.periodo_dias || 90;
  estEl('estdGerado').textContent = `Análise dos últimos ${per} dias · atualizado ${estData(a.gerado_em || new Date().toISOString())}`;
  const kpis = [
    ['Valor em estoque', estK(k.valor_total), '', 'R$', `${estNum(k.itens_com_saldo)} itens com saldo`],
    ['Itens cadastrados', estNum(k.itens_total), '', '▦', `${estNum(k.itens_zerados)} zerados`],
    ['Abaixo do mínimo', estNum(d.itens_criticos), d.itens_criticos ? 'warn' : 'ok', '⚠', `${estNum(k.itens_repor)} no ponto de pedido`],
    ['Entradas no período', estK(k.valor_entradas), '', '⇩', `${estNum(d.entradas_pendentes)} entradas pendentes`],
    ['Saídas no período', estK(k.valor_saidas), '', '⇧', `${estNum(k.movimentos)} movimentações`],
    ['Giro anualizado', (k.giro_anual || 0).toLocaleString('pt-BR') + '×', '', '⟳', k.cobertura_media_dias ? `cobertura ~${estNum(k.cobertura_media_dias)} dias` : 'sem consumo no período'],
    ['Importados críticos', estK(k.importados_valor), 'info', '✈', `${estNum(k.importados_itens)} itens`],
    ['Valor parado', estK(k.valor_parado), k.valor_parado ? 'warn' : 'ok', '⏸', `${estNum(k.itens_parados)} itens > 180 dias`],
  ];
  estEl('estKpis').innerHTML = kpis.map(([label, valor, cls, icon, sub]) =>
    `<article class="kpi ${cls}"><div class="kpi-top"><span class="kpi-icon">${icon}</span></div><strong>${valor}</strong><span>${label}</span><small class="estd-sub">${sub}</small></article>`).join('');

  const al = [];
  if (k.itens_negativos) al.push(['danger', `${estNum(k.itens_negativos)} item(ns) com saldo negativo`, 'integridade']);
  if (d.entradas_conferidas) al.push(['info', `${estNum(d.entradas_conferidas)} entrada(s) conferida(s) aguardando recebimento`, 'entradas']);
  if (k.requisicoes_abertas) al.push(['warn', `${estNum(k.requisicoes_abertas)} requisição(ões) em aberto`, 'saidas']);
  if (k.inventarios_abertos) al.push(['warn', `${estNum(k.inventarios_abertos)} inventário(s) em contagem`, 'inventario']);
  if (k.itens_sem_custo) al.push(['warn', `${estNum(k.itens_sem_custo)} item(ns) com saldo e sem custo — valor subestimado`, 'itens']);
  if (k.itens_sem_minimo) al.push(['muted', `${estNum(k.itens_sem_minimo)} item(ns) com saldo sem estoque mínimo definido`, 'itens']);
  estEl('estdAlertas').innerHTML = al.map(([c, t, go]) => `<button class="estd-alerta ${c}" onclick="estGo('${go}')">${estHtml(t)} →</button>`).join('');

  const int_ = r.integridade || {};
  estEl('estIntegridadeBanner').innerHTML = int_.alterado_desde_instalacao ? `
    <div class="est-integrity mudou"><strong>A base de pedidos mudou desde a instalação</strong>
      <span>${estNum(int_.pedidos_total)} PCs · ${estNum(int_.pedidos_com_conferencia)} com conferência · ${estNum(int_.linhas_de_insumo)} linhas de insumo.</span></div>` : '';

  estdMensal(a.mensal || []);
  estdOrigem(a.origem || {}, k.valor_total);
  estdBars('estdFamilias', (a.familias || []).filter(f => f.valor > 0), f => f.familia, f => f.valor, f => `${estK(f.valor)} <small>${estNum(f.itens)} itens</small>`);
  estdBars('estdObras', a.obras || [], o => o.obra, o => o.valor, o => `${estK(o.valor)} <small>${estNum(o.saidas)} saídas</small>`);
  estdBars('estdPessoas', a.pessoas || [], p => p.nome, p => p.saidas, p => `${estNum(p.saidas)} <small>${estK(p.valor)}</small>`);

  const abc = a.abc || {}, totAbc = ['A', 'B', 'C'].reduce((s, c) => s + (abc[c]?.valor || 0), 0), nAbc = ['A', 'B', 'C'].reduce((s, c) => s + (abc[c]?.itens || 0), 0);
  estEl('estdAbc').innerHTML = nAbc ? `<div class="estd-abc">${['A', 'B', 'C'].map(c => `<div class="estd-abc-c c${c}" style="flex:${Math.max(.12, (abc[c]?.valor || 0) / Math.max(1, totAbc))}"><strong>${c}</strong><span>${estNum(abc[c]?.itens)} itens</span><small>${estK(abc[c]?.valor)}</small></div>`).join('')}</div>
    <p class="estd-note">Classe A concentra ${estPct(abc.A?.valor || 0, totAbc)}% do consumo com ${estPct(abc.A?.itens || 0, nAbc)}% dos itens consumidos — foque inventário e reposição nela.</p>` : '<div class="notice-empty">Sem saídas no período.</div>';

  const row = (t, s, badge, cls = '') => `<div class="est-mini-row"><div><strong>${estHtml(t)}</strong><small>${s}</small></div><span class="est-badge ${cls}">${badge}</span></div>`;
  const vazio = m => `<div class="notice-empty">${m}</div>`;
  estEl('estdCobertura').innerHTML = (a.cobertura || []).map(c => row(c.descricao, `${estHtml(c.codigo)} · saldo ${estNum(c.saldo)} ${estHtml(c.unidade)} · consumo ${c.consumo_dia.toLocaleString('pt-BR')}/dia`,
    c.dias <= 0 ? 'zerado' : `${c.dias.toLocaleString('pt-BR')} d`, c.dias <= 7 ? 'danger' : c.dias <= 20 ? 'warn' : '')).join('') || vazio('Nenhum item consumido corre risco de faltar nos próximos 45 dias.');
  estEl('estdConsumo').innerHTML = (a.top_consumo || []).map(c => row(c.descricao, `${estHtml(c.codigo)} · ${estNum(c.quantidade)} ${estHtml(c.unidade)} em ${estNum(c.saidas)} saídas`, estK(c.valor), 'out')).join('') || vazio('Sem saídas no período.');
  estEl('estdTopValor').innerHTML = (a.top_valor || []).map(c => row(c.descricao, `${estHtml(c.codigo)} · ${estNum(c.saldo)} ${estHtml(c.unidade)}`, estK(c.valor), 'ok')).join('') || vazio('Sem itens valorizados.');
  estEl('estdParados').innerHTML = (a.parados || []).map(c => row(c.descricao, `${estHtml(c.codigo)} · ${estNum(c.saldo)} ${estHtml(c.unidade)} · último mov. ${c.ultimo ? estData(c.ultimo) : '—'}`, estK(c.valor), 'warn')).join('') || vazio('Nenhum item parado há mais de 180 dias.');

  const abaixo = d.abaixo_minimo || [];
  estEl('estAbaixo').innerHTML = abaixo.length ? abaixo.map(i => row(i.descricao, `${estHtml(i.codigo)} · mín. ${estNum(i.minimo)} ${estHtml(i.unidade)}`, estNum(i.saldo), i.status === 'SEM ESTOQUE' ? 'danger' : 'warn')).join('')
    : vazio('Nenhum item abaixo do mínimo. Cadastre o mínimo em cada item para receber alertas.');
  const movs = d.ultimos_movimentos || [];
  estEl('estUltimos').innerHTML = movs.length ? movs.map(m => { const ent = /^(ENTRADA|AJUSTE_POSITIVO|INVENTARIO)/.test(m.tipo);
    return row(m.descricao || m.codigo, `${estHtml(m.tipo)}${m.pc ? ' · PC ' + m.pc : ''}${m.setor ? ' · ' + estHtml(m.setor) : ''} · ${estData(m.em)}`, (ent ? '+' : '−') + estNum(m.quantidade), ent ? 'ok' : 'out'); }).join('')
    : vazio('Nenhuma movimentação registrada ainda.');
}

/* ---------------- itens ---------------- */

async function estLoadItens() {
  const p = new URLSearchParams({ ...EST.filtro });
  try {
    const r = await estApi('estoque_itens', { query: p });
    EST.itens = r.itens;
    estRenderItens(r);
  } catch (e) { estEl('estItensBody').innerHTML = `<tr><td colspan="8" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estRenderItens(r) {
  const cats = r.categorias || [];
  const sel = estEl('estCat');
  const atual = EST.filtro.categoria;
  sel.innerHTML = '<option value="">Todas as categorias</option>' + cats.map(c => `<option value="${estHtml(c)}" ${c === atual ? 'selected' : ''}>${estHtml(c)}</option>`).join('');
  estEl('estItensCount').textContent = `${estNum(r.total)} item(ns)`;
  const rows = EST.itens.map(i => `
    <tr>
      <td><code>${estHtml(i.codigo)}</code></td>
      <td><strong>${estHtml(i.descricao)}</strong><small class="est-sub">${estHtml(i.categoria || '')}${i.localizacao ? ' · ' + estHtml(i.localizacao) : ''}</small></td>
      <td>${estHtml(i.unidade)}</td>
      <td><strong>${estNum(i.saldo)}</strong></td>
      <td>${estNum(i.minimo)}</td>
      <td><span class="est-badge ${i.status === 'SEM ESTOQUE' ? 'danger' : i.status === 'CRÍTICO' ? 'warn' : i.status === 'ATENÇÃO' ? 'info' : 'ok'}">${i.status}</span></td>
      <td>${estMoeda(i.custo_medio)}</td>
      <td class="est-actions"><button class="button ghost small" onclick="estVerItem('${estHtml(i.codigo)}')">Ficha</button>
        <button class="button ghost small" onclick="estEditarItem('${estHtml(i.codigo)}')">Editar</button></td>
    </tr>`).join('');
  estEl('estItensBody').innerHTML = rows || '<tr><td colspan="8" class="empty">Nenhum item de estoque. As entradas recebidas criam os itens automaticamente.</td></tr>';
}

/* v3.51.0: busca por nome do insumo nas demais abas */
let EST_DEB = null;
function estBuscaDebounce(fn) { clearTimeout(EST_DEB); EST_DEB = setTimeout(fn, 300); }
function estNormTxt(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
function estFiltrarLinhas(inputId, tbodyId) {
  const palavras = estNormTxt(estEl(inputId).value).split(' ').filter(Boolean);
  estEl(tbodyId).querySelectorAll('tr').forEach(tr => {
    if (tr.querySelector('td.empty')) return;
    const t = estNormTxt(tr.textContent);
    tr.hidden = !palavras.every(w => t.includes(w));
  });
}
function estFiltroChanged() {
  EST.filtro.q = estEl('estQ').value;
  EST.filtro.status = estEl('estStatus').value;
  EST.filtro.categoria = estEl('estCat').value;
  EST.filtro.sort = estEl('estSort').value;
  estLoadItens();
}

async function estVerItem(codigo) {
  try {
    const r = await estApi('estoque_item', { query: { codigo } });
    const i = r.item;
    estEl('estFichaTitle').textContent = i.descricao;
    estEl('estFichaBody').innerHTML = `
      <div class="est-ficha-grid">
        <div><small>Código</small><strong>${estHtml(i.codigo)}</strong></div>
        <div><small>Unidade</small><strong>${estHtml(i.unidade)}</strong></div>
        <div><small>Saldo</small><strong>${estNum(i.saldo)}</strong></div>
        <div><small>Mínimo</small><strong>${estNum(i.minimo)}</strong></div>
        <div><small>Custo médio</small><strong>${estMoeda(i.custo_medio)}</strong></div>
        <div><small>Valor em estoque</small><strong>${estMoeda(i.valor_estoque)}</strong></div>
        <div><small>Localização</small><strong>${estHtml(i.localizacao || '—')}</strong></div>
        <div><small>Categoria</small><strong>${estHtml(i.categoria || '—')}</strong></div>
      </div>
      ${Array.isArray(i.composicao) && i.composicao.length ? `<h4>Origem do saldo</h4>
      <div class="est-table-scroll"><table class="orders-table"><tbody>
      ${i.composicao.map(c => `<tr><td>${estHtml(c.origem)}</td><td style="text-align:right;font-weight:800;color:${c.qtd < 0 ? 'var(--red)' : 'var(--teal)'}">${c.qtd > 0 ? '+' : ''}${estNum(c.qtd)} ${estHtml(i.unidade)}</td></tr>`).join('')}
      <tr><td><strong>Saldo atual</strong></td><td style="text-align:right"><strong>${estNum(i.saldo)} ${estHtml(i.unidade)}</strong></td></tr>
      </tbody></table></div>` : ''}
      <h4>Movimentações (${estNum(r.movimentos.length)})</h4>
      <div class="est-table-scroll"><table class="orders-table"><thead><tr><th>ID</th><th>Data</th><th>Tipo</th><th>Qtd</th><th>Saldo após</th><th>Origem</th></tr></thead><tbody>
      ${r.movimentos.map(m => `<tr><td><code>${estHtml(m.id)}</code></td><td>${estData(m.em)}</td><td>${estHtml(m.tipo)}</td>
        <td>${estNum(m.quantidade)}</td><td>${estNum(m.saldo_posterior)}</td>
        <td>${estHtml(m.tipo === 'INVENTARIO_SAP' ? 'Inventário SAP ' + (m.documento || '') : m.pc ? 'PC ' + m.pc : (m.setor || m.fornecedor || m.documento || '—'))}</td></tr>`).join('') || '<tr><td colspan="6" class="empty">Sem movimentações</td></tr>'}
      </tbody></table></div>`;
    estEl('estFicha').classList.add('on');
  } catch (e) { estToast('Falha ao abrir ficha: ' + e.message, true); }
}

function estFecharFicha() { estEl('estFicha').classList.remove('on'); }

function estEditarItem(codigo) {
  const i = EST.itens.find(x => x.codigo === codigo);
  estEl('estItemForm').reset();
  estEl('estItemCodigo').value = codigo;
  estEl('estItemCodigo').readOnly = true;
  estEl('estItemDesc').value = i ? i.descricao : '';
  estEl('estItemUn').value = i ? i.unidade : 'UN';
  estEl('estItemCat').value = i ? (i.categoria || '') : '';
  estEl('estItemLoc').value = i ? (i.localizacao || '') : '';
  estEl('estItemMin').value = i ? i.minimo : 0;
  estEl('estItemPonto').value = i ? (i.ponto_pedido || 0) : 0;
  estEl('estItemMax').value = i ? (i.maximo || 0) : 0;
  estEl('estItemForn').value = i ? (i.fornecedor_padrao || '') : '';
  estEl('estItemFormTitle').textContent = i ? 'Editar item ' + codigo : 'Novo item de estoque';
  estEl('estItemModal').classList.add('on');
}

function estNovoItem() { estEl('estItemForm').reset(); estEl('estItemCodigo').readOnly = false; estEl('estItemFormTitle').textContent = 'Novo item de estoque'; estEl('estItemModal').classList.add('on'); }
function estFecharItem() { estEl('estItemModal').classList.remove('on'); }

async function estSalvarItem(ev) {
  ev.preventDefault();
  try {
    await estPost('estoque_item_save', {
      codigo: estEl('estItemCodigo').value, descricao: estEl('estItemDesc').value,
      unidade: estEl('estItemUn').value, categoria: estEl('estItemCat').value,
      localizacao: estEl('estItemLoc').value, minimo: estEl('estItemMin').value,
      ponto_pedido: estEl('estItemPonto').value, maximo: estEl('estItemMax').value,
      fornecedor_padrao: estEl('estItemForn').value,
    });
    estFecharItem(); estToast('Item de estoque salvo'); estLoadItens();
  } catch (e) { estToast('Falha ao salvar item: ' + e.message, true); }
}

/* ---------------- entradas ---------------- */

async function estLoadEntradas() {
  try {
    const status = estEl('estEntStatus') ? estEl('estEntStatus').value : '';
    const q = estEl('estEntQ') ? estEl('estEntQ').value.trim() : '';
    const r = await estApi('estoque_entradas', { query: { ...(status ? { status } : {}), ...(q ? { q } : {}) } });
    EST.entradas = r.entradas;
    estEl('estEntradasBody').innerHTML = r.entradas.map(e => {
      const badge = e.status === 'recebido' ? 'ok' : e.status === 'conferido' ? 'info' : 'warn';
      const label = e.status === 'recebido' ? 'Recebido' : e.status === 'conferido' ? 'Conferido' : 'Pendente';
      return `<tr>
        <td><strong>${e.pc ? 'PC ' + e.pc : estHtml(e.entrada_id)}</strong><small class="est-sub">${estHtml(e.entrada_id)}</small></td>
        <td>${estHtml(e.fornecedor || '—')}<small class="est-sub">NF ${estHtml(e.numero_nf || '—')}</small></td>
        <td>${estNum(e.linhas)}<small class="est-sub">${estNum(e.conferidas)} conferidas${e.divergencias ? ' · ' + estNum(e.divergencias) + ' divergência(s)' : ''}</small></td>
        <td>${estNum(e.qtd_pedida)} / <strong>${estNum(e.qtd_recebida)}</strong></td>
        <td><span class="est-badge ${badge}">${label}</span></td>
        <td>${estData(e.criado_em)}</td>
        <td class="est-actions"><button class="button primary small" onclick="estAbrirEntrada(${e.pc})">${e.status === 'recebido' ? 'Consultar' : e.status === 'conferido' ? 'Receber' : 'Conferir'}</button></td>
      </tr>`;
    }).join('') || '<tr><td colspan="7" class="empty">Nenhuma entrada. Gere a partir de um pedido de compra ou lance uma entrada manual.</td></tr>';
  } catch (e) { estEl('estEntradasBody').innerHTML = `<tr><td colspan="7" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estAbrirNovaEntrada() {
  estEl('estNovaEntrada').classList.add('on');
  estEl('estNovaPc').value = '';
  estEl('estNovaPc').focus();
}

/** v3.51.4: PC já finalizado sem entrada no estoque → gera a entrada pendente e leva à conferência. */
async function estGerarEntradaDaSaida(pc) {
  try {
    await estPost('estoque_entrada_from_pedido', { pc });
    estToast('Entrada do PC ' + pc + ' gerada. Confira e confirme o recebimento para liberar a saída.');
    if (typeof estGo === 'function') estGo('entradas');
    await estLoadEntradas();
    estAbrirEntrada(pc);
  } catch (e) { estToast('Não foi possível gerar a entrada: ' + e.message, true); }
}

async function estGerarEntradaPedido() {
  const pc = Number(estEl('estNovaPc').value);
  if (!pc) return estToast('Informe o número do PC', true);
  try {
    const r = await estPost('estoque_entrada_from_pedido', { pc });
    estEl('estNovaEntrada').classList.remove('on');
    estToast('Entrada gerada a partir do PC ' + pc);
    await estLoadEntradas();
    estAbrirEntrada(pc);
  } catch (e) { estToast('Não foi possível gerar a entrada: ' + e.message, true); }
}

function estNovaEntradaManual() {
  estEl('estNovaEntrada').classList.remove('on');
  estEl('estManualForm').reset();
  estEl('estManualLinhas').innerHTML = '';
  estManualLinha();
  estEl('estManualModal').classList.add('on');
}

function estManualLinha() {
  const div = document.createElement('div');
  div.className = 'est-line-row';
  div.innerHTML = `<input placeholder="Descrição do material" class="est-l-desc" maxlength="600">
    <input placeholder="UN" class="est-l-un" value="UN" maxlength="12">
    <input placeholder="Qtd" class="est-l-qtd" type="number" min="0.001" step="any">
    <input placeholder="R$ unit." class="est-l-valor" type="number" min="0" step="any">
    <input placeholder="Lote" class="est-l-lote" maxlength="60">
    <button type="button" class="button ghost small" onclick="this.parentNode.remove()">✕</button>`;
  estEl('estManualLinhas').appendChild(div);
}

async function estSalvarManual(ev) {
  ev.preventDefault();
  const linhas = [...estEl('estManualLinhas').children].map((row, i) => ({
    id: 'l' + (i + 1),
    descricao: row.querySelector('.est-l-desc').value,
    unidade: row.querySelector('.est-l-un').value || 'UN',
    quantidade: row.querySelector('.est-l-qtd').value,
    valor_unitario: row.querySelector('.est-l-valor').value || null,
    lote: row.querySelector('.est-l-lote').value,
  })).filter(l => l.descricao.trim() && Number(l.quantidade) > 0);
  if (!linhas.length) return estToast('Inclua ao menos uma linha válida', true);
  try {
    await estPost('estoque_entrada_manual', {
      fornecedor: estEl('estManualForn').value, numero_nf: estEl('estManualNF').value,
      observacao: estEl('estManualObs').value, linhas,
    });
    estEl('estManualModal').classList.remove('on');
    estToast('Entrada manual registrada — saldo atualizado');
    estLoadEntradas(); estLoadDashboard();
  } catch (e) { estToast('Falha na entrada manual: ' + e.message, true); }
}

async function estAbrirEntrada(pc) {
  try {
    const r = await estApi('estoque_entrada', { query: { pc } });
    EST.entradaAtual = r.entrada;
    estRenderEntrada();
    estEl('estEntradaModal').classList.add('on');
  } catch (e) { estToast('Falha ao abrir entrada: ' + e.message, true); }
}

function estFecharEntrada() { estEl('estEntradaModal').classList.remove('on'); EST.entradaAtual = null; }

function estRenderEntrada() {
  const d = EST.entradaAtual;
  if (!d) return;
  const recebido = d.status === 'recebido';
  const conferido = d.linhas.filter(l => l.conferido).length;
  estEl('estEntradaTitle').textContent = `Conferência e recebimento — ${d.pc ? 'PC ' + d.pc : d.entrada_id}`;
  estEl('estEntradaSub').textContent = `${d.entrada_id} · ${d.fornecedor || 'fornecedor não informado'} · NF ${d.numero_nf || '—'} · ${conferido}/${d.linhas.length} linhas conferidas`;
  const etapa = recebido ? 'Recebido' : d.status === 'conferido' ? 'Conferido — pronto para receber' : 'Aguardando conferência';
  estEl('estEntradaStatus').innerHTML = `<span class="est-badge ${recebido ? 'ok' : d.status === 'conferido' ? 'info' : 'warn'}">${etapa}</span>`;

  estEl('estEntradaLinhas').innerHTML = d.linhas.map((l, n) => {
    const q = Number(l.quantidade);
    const conf = l.conferido ? Number(l.quantidade_conferida ?? q) : null;
    const div = l.conferido && Math.abs((conf ?? 0) - q) > 0.0001;
    return `<tr class="${l.conferido ? (div ? 'est-row-div' : 'est-row-ok') : ''}">
      <td>${estHtml(l.descricao)}<small class="est-sub">${l.codigo ? 'estoque: <code>' + estHtml(l.codigo) + '</code>' : (l.codigo_sugerido ? 'vincular a <code>' + estHtml(l.codigo_sugerido) + '</code>' : 'novo item será criado')}</small></td>
      <td>${estHtml(l.unidade)}</td>
      <td><strong>${estNum(q)}</strong></td>
      <td>${recebido ? estNum(l.quantidade_recebida ?? 0) : `<input type="number" min="0" max="${q}" step="any" class="est-conf-q" data-id="${estHtml(l.id)}" value="${l.conferido && conf !== null ? String(conf).replace('.', ',') : ''}" placeholder="0" ${l.conferido ? '' : ''}>`}</td>
      <td>${recebido ? estNum(l.quantidade_pendente ?? 0) : `<input type="text" class="est-conf-mot" data-id="${estHtml(l.id)}" value="${estHtml(l.divergencia_motivo || '')}" placeholder="motivo se faltar" maxlength="400">`}</td>
      <td>${recebido ? estData(l.recebido_em) : (l.conferido ? `<span class="est-badge ${div ? 'warn' : 'ok'}">${div ? 'Divergente' : 'Conferido'}</span>` : '<span class="est-badge">Pendente</span>')}</td>
    </tr>`;
  }).join('');

  estEl('estEntradaFoot').innerHTML = recebido
    ? `<button class="button ghost" onclick="estFecharEntrada()">Fechar</button>`
    : `<button class="button ghost" onclick="estFecharEntrada()">Cancelar</button>
       <button class="button ghost" onclick="estConferir('nao_chegou')">Marcar restantes como “não chegou”</button>
       <button class="button primary" onclick="estConferir('conferir')">Salvar conferência</button>
       <button class="button primary" id="estBtnReceber" onclick="estReceber(${Number(d.pc) || 0})" ${d.status === 'conferido' ? '' : 'disabled'}>✓ Confirmar recebimento</button>`;
}

function estLerConferencia() {
  const d = EST.entradaAtual;
  const linhas = d.linhas.map(l => {
    const qi = estEl('estEntradaLinhas').querySelector(`.est-conf-q[data-id="${CSS.escape(l.id)}"]`);
    const mi = estEl('estEntradaLinhas').querySelector(`.est-conf-mot[data-id="${CSS.escape(l.id)}"]`);
    return { id: l.id, quantidade_conferida: qi ? qi.value : 0, divergencia_motivo: mi ? mi.value : '' };
  });
  return linhas;
}

async function estConferir(modo) {
  const d = EST.entradaAtual;
  let linhas = estLerConferencia();
  if (modo === 'nao_chegou') {
    linhas = linhas.map((l, i) => {
      if (String(l.quantidade_conferida).trim() !== '') return l;
      return { ...l, quantidade_conferida: 0, divergencia_motivo: l.divergencia_motivo || 'Material não chegou' };
    });
  }
  try {
    const r = await estPost('estoque_conferir', { pc: d.pc, linhas });
    EST.entradaAtual = r.entrada;
    estRenderEntrada();
    estToast(r.entrada.status === 'conferido' ? 'Conferência completa — confirme o recebimento para dar entrada no saldo' : 'Conferência parcial salva');
    estLoadEntradas();
  } catch (e) { estToast('Conferência não salva: ' + e.message, true); }
}

async function estReceber(pc) {
  if (!confirm('Confirmar o recebimento? Esta ação dá entrada no saldo do estoque conforme as quantidades conferidas.')) return;
  try {
    await estPost('estoque_receber', { pc });
    estToast('Recebimento confirmado — saldo atualizado');
    estFecharEntrada();
    estLoadEntradas(); estLoadDashboard(); estLoadMovimentos();
  } catch (e) { estToast('Não foi possível receber: ' + e.message, true); }
}

/* ---------------- saídas ---------------- */

async function estLoadRequisicoes() {
  try {
    const qq = estEl('estReqQ') ? estEl('estReqQ').value.trim() : '';
    const r = await estApi('estoque_requisicoes', { query: qq ? { q: qq } : {} });
    estEl('estReqBody').innerHTML = r.requisicoes.map(q => `
      <tr><td><code>${estHtml(q.id)}</code></td><td>${estHtml(q.setor || '—')}<small class="est-sub">${estHtml(q.solicitante || '—')}${(() => { const p = [...new Set((q.linhas || []).map(l => l.pc).filter(Boolean))]; return p.length ? ' · PC ' + p.map(estHtml).join(', ') : ''; })()}${q.os ? ' · OS ' + estHtml(q.os) : ''}${q.equipamento ? ' · ' + estHtml(q.equipamento) : ''}</small></td>
      <td>${estNum((q.linhas || []).length)}</td>
      <td><span class="est-badge ${q.status === 'atendida' ? 'ok' : 'warn'}">${q.status === 'atendida' ? 'Atendida' : 'Rascunho'}</span></td>
      <td>${estData(q.atendida_em || q.atualizado_em)}${q.status === 'atendida' ? `<br><a href="#" onclick="rdAbrirComprovante('${estHtml(q.id)}');return false">${q.assinatura ? '✍ ' + estHtml(q.assinatura.nome) : 'comprovante'}</a>` : ''}${q.status === 'atendida' ? `<small class="est-sub">${q.os || q.equipamento ? 'OS ' + estHtml(q.os || '—') + ' · ' + estHtml(q.equipamento || '—') : '<b style="color:#b45309">⚠ sem OS/equipamento</b>'} · <a href="#" onclick="estCompletarSaida('${estHtml(q.id)}');return false">✎ ${q.os || q.equipamento ? 'alterar' : 'completar'}</a></small>` : ''}</td></tr>`).join('')
      || '<tr><td colspan="5" class="empty">Nenhuma requisição de saída registrada.</td></tr>';
  } catch (e) { estEl('estReqBody').innerHTML = `<tr><td colspan="5" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estNovaSaida() {
  estEl('estSaidaForm').reset();
  estEl('estSaidaLinhas').innerHTML = ''; EST_PCS_META = {}; estSaidaPcsRender();
  estEl('estSaidaBuscaRes').hidden = true; estEl('estSaidaBuscaRes').innerHTML = '';
  estSaidaLinha();
  estSaidaSetorMudou(); estSaidaCarregarObras(); estCarregarEquipamentos('estEquipLista'); estEl('estSaidaOSInfo').hidden = true;
  estEl('estSaidaModal').classList.add('on');
  estLoadItensSaida(); estSaidaFuncCarregar();
  setTimeout(() => estEl('estSaidaBusca').focus(), 60);
}

/** v3.55.2: base de funcionários na Saída — busca por nome ou matrícula; informa se a pessoa está cadastrada (não bloqueia: terceiro/visitante pode retirar). */
async function estSaidaFuncCarregar() {
  estEl('estSaidaFuncInfo').textContent = '';
  try {
    const l = (typeof rdFuncionarios === 'function') ? await rdFuncionarios(true) : [];
    estEl('estFuncLista').innerHTML = l.map(f => `<option value="${estHtml(f.nome)}">${estHtml([f.matricula ? 'Matrícula ' + f.matricula : '', f.setor, f.funcao].filter(Boolean).join(' · '))}</option>`).join('');
    estEl('estSaidaSolicitante').placeholder = l.length ? `Digite o nome ou a matrícula (${l.length} funcionários cadastrados)` : 'Nome de quem retira';
  } catch { /* digita livre */ }
}
function estSaidaFuncMudou() {
  const inp = estEl('estSaidaSolicitante'), info = estEl('estSaidaFuncInfo'), lista = (typeof RD_FUNC !== 'undefined' && RD_FUNC) || [];
  const v = inp.value.trim(); if (!v || !lista.length) { info.textContent = ''; return; }
  const n = rdNormNome(v);
  // matrícula digitada: troca pelo nome
  const porMat = lista.filter(f => f.matricula && String(f.matricula).trim().toLowerCase() === v.toLowerCase());
  if (porMat.length === 1) { inp.value = porMat[0].nome; return estSaidaFuncMudou(); }
  const exato = lista.filter(f => rdNormNome(f.nome) === n);
  if (exato.length === 1) { const f = exato[0]; info.textContent = ['✔ Funcionário cadastrado', f.matricula ? 'matrícula ' + f.matricula : '', f.setor, f.funcao].filter(Boolean).join(' · '); return; }
  const parc = lista.filter(f => rdNormNome(f.nome).includes(n)).length;
  info.textContent = parc ? `${parc} funcionário(s) combinam — escolha na lista.` : 'Não está na base de funcionários (será tratado como terceiro/visitante). Cadastre em Estoque > Funcionários se for do quadro.';
}

/** v3.27.0: sugestões do campo "Equipamento aplicado" (usados antes, ferramentas e locados). */
async function estCarregarEquipamentos(listaId) {
  const dl = document.getElementById(listaId); if (!dl) return;
  try { const r = await estApi('estoque_equipamentos'); dl.innerHTML = r.equipamentos.map(e => `<option value="${estHtml(e)}">`).join(''); } catch (e) { /* digita livre */ }
}

async function estLoadItensSaida() {
  try {
    const r = await estApi('estoque_itens', { query: { sort: 'descricao' } });
    EST.itens = r.itens;
    const dl = estEl('estSaidaDatalist');
    if (dl) dl.innerHTML = r.itens.map(i => `<option value="${estHtml(i.descricao)}">${estHtml(i.codigo)} · saldo ${estNum(i.saldo)} ${estHtml(i.unidade)}</option>`).join('');
  } catch { /* silencioso: datalist é conveniência */ }
}

/** preset (v3.30.0): { codigo, descricao, unidade, quantidade, pc, linha_pc, foto, pendente, saldo, rotulo }. Sem preset = linha livre (como antes). */
function estSaidaLinha(preset) {
  const p = preset || null, div = document.createElement('div');
  div.className = 'est-line-row' + (p ? ' est-line-preset' : '');
  if (p) {
    div.dataset.codigo = p.codigo || ''; div.dataset.pc = p.pc || ''; div.dataset.linhaPc = p.linha_pc || '';
    div.dataset.autoFoto = p.foto || ''; div.dataset.fotoEscolhida = ''; div.dataset.max = p.pendente != null ? p.pendente : '';
  }
  div.innerHTML = `<div class="est-s-descbox"><input list="estSaidaDatalist" placeholder="Material (digite para buscar)" class="est-s-desc" maxlength="600"${p ? ' readonly' : ''}>${p && p.rotulo ? `<small class="est-sub">${estHtml(p.rotulo)}</small>` : ''}${p ? '<button type="button" class="button ghost small est-s-trocar" onclick="estSaidaTrocar(this)" title="Material errado? Troque por outro">🔁 Trocar material</button>' : ''}</div>
    <input placeholder="Qtd" class="est-s-qtd" type="number" min="0.001" step="any"${p && p.pendente != null ? ` max="${Number(p.pendente)}"` : ''}>
    <input placeholder="Observação" class="est-s-obs" maxlength="400">
    <label class="est-foto-btn" title="Fotografar o material (opcional)"><input type="file" accept="image/*" capture="environment" hidden onchange="estSaidaFoto(this)"><span>📷 Foto</span><img alt="" hidden></label>
    <button type="button" class="button ghost small" onclick="estSaidaRemoverLinha(this)">✕</button>`;
  if (p) {
    div.querySelector('.est-s-desc').value = p.descricao || '';
    div.querySelector('.est-s-qtd').value = p.quantidade != null ? p.quantidade : '';
    if (p.foto) {
      const lab = div.querySelector('.est-foto-btn'), im = lab.querySelector('img');
      im.src = p.foto; im.hidden = false; lab.querySelector('span').hidden = true; lab.classList.add('on', 'auto');
      lab.title = 'Foto automática (' + (p.foto_origem === 'cadastro' ? 'cadastro' : 'recebimento') + ') — clique para trocar por outra';
      // v3.44.0: o PC pode ter até 3 fotos do recebimento — escolha qual vai com a saída
      if (p.fotos && p.fotos.length > 1) {
        const st = document.createElement('div'); st.className = 'est-fotos-opc';
        st.innerHTML = '<small>Fotos do recebimento:</small>' + p.fotos.map((f, k) => `<button type="button" class="est-foto-opc${k === 0 ? ' on' : ''}" data-f="${estHtml(f)}" title="Foto ${k + 1} do recebimento"><img src="${estHtml(f)}" alt="" loading="lazy"></button>`).join('');
        st.onclick = e => {
          const b = e.target.closest('.est-foto-opc'); if (!b) return;
          st.querySelectorAll('.est-foto-opc').forEach(x => x.classList.toggle('on', x === b));
          div.dataset.fotoEscolhida = b.dataset.f; div.dataset.autoFoto = b.dataset.f;
          if (!lab.dataset.foto) im.src = b.dataset.f;
        };
        div.appendChild(st);
      }
    }
  }
  estEl('estSaidaLinhas').appendChild(div);
  return div;
}

/* v3.30.0: busca única — nº do PC, SKU/código ou nome (com apelidos). */
let EST_BUSCA_T = null, EST_BUSCA_SEQ = 0, EST_BUSCA_ULT = null;
function estBuscaDigitou() {
  clearTimeout(EST_BUSCA_T);
  const q = estEl('estSaidaBusca').value.trim(), box = estEl('estSaidaBuscaRes');
  if (q.length < 2) { box.hidden = true; box.innerHTML = ''; EST_BUSCA_ULT = null; return; }
  EST_BUSCA_T = setTimeout(() => estBuscaExecutar(q), 280);
}
function estBuscaTecla(ev) {
  if (ev.key !== 'Enter') return;
  ev.preventDefault();               // Enter nunca envia o formulário daqui
  clearTimeout(EST_BUSCA_T);
  const q = estEl('estSaidaBusca').value.trim(); if (q.length < 2) return;
  const nums = q.match(/^\s*(?:pc|pedidos?)?\s*[#nº°.:-]*\s*(\d{3,7}(?:\s*(?:[,;e]|\s)\s*\d{3,7})+)\s*$/iu) ? q.match(/\d{3,7}/g) : null;
  if (nums) return estBuscaVarios([...new Set(nums)]);
  estBuscaExecutar(q, true);
}
async function estBuscaExecutar(q, enter) {
  const seq = ++EST_BUSCA_SEQ, box = estEl('estSaidaBuscaRes');
  try {
    const r = await estApi('estoque_saida_buscar', { query: { q } });
    if (seq !== EST_BUSCA_SEQ) return;       // resposta velha
    EST_BUSCA_ULT = r; estBuscaRender(r);
    if (enter) {
      const ped = r.pc;
      if (ped && ped.status === 'ok') estPcAdicionarTodas();
      else if (!ped && (r.itens || []).length === 1) estItemAdicionar(0);
    }
  } catch (e) { if (seq === EST_BUSCA_SEQ) { box.hidden = false; box.innerHTML = `<p class="est-busca-vazio">${estHtml(e.message)}</p>`; } }
}
/** v3.55.3: vários pedidos de uma vez (ex.: "2190 2200 2210"): adiciona os materiais com saldo de cada um e avisa os que não deu. */
async function estBuscaVarios(nums) {
  const falhas = []; let somados = 0;
  for (const num of nums) {
    try {
      const r = await estApi('estoque_saida_buscar', { query: { q: num } }); const ped = r.pc;
      if (!ped || ped.status !== 'ok') { falhas.push(`PC ${num}: ${ped && ped.mensagem ? ped.mensagem : 'não encontrado'}`); continue; }
      EST_BUSCA_ULT = r; let n = 0; ped.linhas.forEach((l, i) => { if (estPcAdicionar(i, true)) n++; });
      if (n) somados++; else falhas.push(`PC ${num}: nada novo para retirar`);
    } catch (e) { falhas.push(`PC ${num}: ${e.message}`); }
  }
  EST_BUSCA_ULT = null; const b = estEl('estSaidaBusca'), box = estEl('estSaidaBuscaRes');
  b.value = ''; box.hidden = !falhas.length; box.innerHTML = falhas.length ? `<div class="est-pc-card aviso">${falhas.map(estHtml).join('<br>')}</div>` : '';
  estToast(`${somados} de ${nums.length} pedido(s) adicionado(s). Confira as quantidades.${falhas.length ? ' Veja os avisos.' : ''}`, !somados);
}
const EST_PC_ST = { ok: '', saldo_parcial: 'saldo parcial', sem_saldo: 'sem saldo', retirada_total: 'já retirado' };
function estBuscaRender(r) {
  const box = estEl('estSaidaBuscaRes'); let h = '';
  const ped = r.pc;
  if (ped) {
    if (ped.status !== 'ok') h += `<div class="est-pc-card aviso"><strong>PC ${estHtml(ped.pc)}</strong> — ${estHtml(ped.mensagem || 'sem material disponível')}${ped.pode_gerar_entrada ? `<div style="margin-top:8px"><button type="button" class="button small" onclick="estGerarEntradaDaSaida(${Number(ped.pc)})">Gerar entrada deste PC agora</button></div>` : ''}</div>`;
    else {
      const dest = ped.destino || {};
      h += `<div class="est-pc-card"><div class="est-pc-head"><span class="est-pc-tit"><strong>PC ${estHtml(ped.pc)}</strong>${ped.fornecedor ? ` — ${estHtml(ped.fornecedor)}` : ''}${ped.urgente ? ' <span class="est-badge warn">urgente</span>' : ''}</span>
        <small class="est-sub">${ped.solicitante ? 'Solicitante: ' + estHtml(ped.solicitante) : ''}${ped.os ? ' · OS ' + estHtml(ped.os) : ''}${dest.setor_codigo ? ' · Destino previsto: ' + estHtml(dest.obra || dest.setor_codigo) : ''}</small></div>
        <table class="est-pc-tab"><tbody>${ped.linhas.map((l, i) => `<tr class="${l.status === 'ok' ? '' : 'est-pc-off'}">
          <td class="est-pc-foto">${l.foto ? `<img src="${estHtml(l.foto)}" alt="" loading="lazy">` : '<span>—</span>'}</td>
          <td><b>${estHtml(l.descricao)}</b><small class="est-sub">${l.sku ? 'SKU ' + estHtml(l.sku) + ' · ' : ''}recebido ${estNum(l.recebida)} · já retirado ${estNum(l.retirada)} · saldo ${estNum(l.disponivel)} ${estHtml(l.unidade)}${EST_PC_ST[l.status] ? ' · <b>' + EST_PC_ST[l.status] + '</b>' : ''}</small></td>
          <td class="est-pc-qtd">${estNum(l.pendente)}</td>
          <td><button type="button" class="button ghost small" ${l.qtd_sugerida > 0 ? '' : 'disabled'} onclick="estPcAdicionar(${i})">+ Adicionar</button></td></tr>`).join('')}</tbody></table>
        <div class="est-pc-acoes"><button type="button" class="button primary small" onclick="estPcAdicionarTodas()">Adicionar todos os materiais do PC</button></div></div>`;
    }
  }
  const itens = (ped && ped.status === 'ok') ? [] : (r.itens || []);   // PC encontrado: não mistura com itens que só têm o número no nome
  if (itens.length) h += `<div class="est-busca-itens">${itens.map((it, i) => `<button type="button" class="est-busca-item" onclick="estItemAdicionar(${i})">
      <span class="est-pc-foto">${it.foto ? `<img src="${estHtml(it.foto)}" alt="" loading="lazy">` : '<span>—</span>'}</span>
      <span><b>${estHtml(it.descricao)}</b><small class="est-sub">${it.sku ? 'SKU ' + estHtml(it.sku) + ' · ' : ''}saldo ${estNum(it.disponivel)} ${estHtml(it.unidade)}${it.localizacao ? ' · ' + estHtml(it.localizacao) : ''}${it.status && it.status !== 'ok' ? ' · <b>' + estHtml(String(it.status).replace(/_/g, ' ')) + '</b>' : ''}</small></span></button>`).join('')}</div>`;
  if (!h) h = '<p class="est-busca-vazio">Nada encontrado. Confira o número do PC, o SKU ou o nome do cadastro.</p>';
  box.innerHTML = h; box.hidden = false;
}
/** Remove a linha em branco inicial para não sobrar linha vazia depois de adicionar pela busca. */
function estSaidaLimparVazias() {
  [...estEl('estSaidaLinhas').children].forEach(row => {
    if (!row.dataset.codigo && !row.querySelector('.est-s-desc').value.trim() && !row.querySelector('.est-s-qtd').value) row.remove();
  });
}
let EST_PCS_META = {};   // v3.55.3: o que cada pedido da saída prevê (destino/OS/equipamento) para avisar quando divergem
/** v3.56.2: linha adicionada pelo PC/busca com o material errado — vira linha livre (mantém a quantidade) para escolher outro material. */
function estSaidaTrocar(btn) {
  const row = btn.closest('.est-line-row'), desc = row.querySelector('.est-s-desc'), qtd = row.querySelector('.est-s-qtd');
  row.classList.remove('est-line-preset');
  ['codigo', 'pc', 'linhaPc', 'autoFoto', 'fotoEscolhida', 'max'].forEach(k => delete row.dataset[k]);
  qtd.removeAttribute('max'); desc.readOnly = false; desc.value = '';
  row.querySelectorAll('.est-sub, .est-fotos-opc, .est-s-trocar').forEach(e => e.remove());
  const lab = row.querySelector('.est-foto-btn');
  if (lab && lab.classList.contains('auto')) { const im = lab.querySelector('img'); im.hidden = true; im.removeAttribute('src'); lab.querySelector('span').hidden = false; lab.classList.remove('on', 'auto'); lab.title = 'Fotografar o material (opcional)'; delete lab.dataset.foto; }
  desc.focus(); estSaidaPcsRender();
  estToast('Escolha o material correto no campo (digite para buscar). A quantidade foi mantida.');
}
function estSaidaRemoverLinha(btn) { btn.closest('.est-line-row').remove(); estSaidaPcsRender(); }
function estSaidaPcsRender() {
  const bar = estEl('estSaidaPcs'); if (!bar) return;
  const rows = [...estEl('estSaidaLinhas').children], porPc = {};
  rows.forEach(r => { if (r.dataset.pc) porPc[r.dataset.pc] = (porPc[r.dataset.pc] || 0) + 1; });
  Object.keys(EST_PCS_META).forEach(k => { if (!porPc[k]) delete EST_PCS_META[k]; });
  // agrupa as linhas por pedido (ordem em que cada PC entrou); materiais avulsos ficam no fim
  const ordem = Object.keys(porPc), box = estEl('estSaidaLinhas');
  if (ordem.length > 1) [...rows].sort((a, b) => { const ia = a.dataset.pc ? ordem.indexOf(a.dataset.pc) : 999, ib = b.dataset.pc ? ordem.indexOf(b.dataset.pc) : 999; return ia - ib; }).forEach(r => box.appendChild(r));
  const cores = ['#2f7df6', '#12b76a', '#f79009', '#a855f7', '#ec4899', '#14b8a6'];
  rows.forEach(r => { const k = ordem.indexOf(r.dataset.pc || ''); r.style.borderLeft = k >= 0 && ordem.length > 1 ? `4px solid ${cores[k % cores.length]}` : ''; r.style.paddingLeft = k >= 0 && ordem.length > 1 ? '6px' : ''; });
  if (!ordem.length) { bar.hidden = true; bar.innerHTML = ''; return; }
  const dests = new Set(), oss = new Set();
  ordem.forEach(k => { const m = EST_PCS_META[k] || {}; if (m.destino) dests.add(m.destino); if (m.os) oss.add(m.os); });
  let aviso = '';
  if (dests.size > 1 || oss.size > 1) aviso = `<div class="est-pcs-aviso">⚠ Os pedidos têm destino/OS diferentes: ${ordem.map(k => { const m = EST_PCS_META[k] || {}; return `<b>PC ${estHtml(k)}</b> → ${estHtml(m.destino || 'sem destino')}${m.os ? ' · OS ' + estHtml(m.os) : ''}`; }).join(' · ')}. Esta saída vai para <b>um</b> destino (o escolhido acima). Se for para lugares diferentes, faça saídas separadas.</div>`;
  bar.hidden = false;
  bar.innerHTML = `<div><b>${ordem.length} pedido(s) nesta saída:</b> ${ordem.map((k, i) => `<span class="est-pc-chip" style="border-color:${cores[i % cores.length]}">PC ${estHtml(k)} · ${porPc[k]} item(ns) <button type="button" title="Tirar este pedido da saída" onclick="estSaidaTirarPc('${estHtml(k)}')">✕</button></span>`).join(' ')}</div>${aviso}<small class="est-sub">Dica: para lançar vários de uma vez, digite os números separados por espaço ou vírgula (ex.: 2190 2200 2210) e tecle Enter.</small>`;
}
function estSaidaTirarPc(pc) { [...estEl('estSaidaLinhas').children].forEach(r => { if (r.dataset.pc === String(pc)) r.remove(); }); delete EST_PCS_META[pc]; estSaidaPcsRender(); }
function estSaidaDestinoSugerir(ped) {
  const d = (ped && ped.destino) || {};
  if (ped && ped.pc) EST_PCS_META[ped.pc] = { destino: d.obra || d.setor_codigo || '', os: ped.os || '' };
  if (d.setor_codigo && !estEl('estSaidaSetor').value) {
    const sel = estEl('estSaidaSetor');
    if ([...sel.options].some(o => o.value === d.setor_codigo)) {
      sel.value = d.setor_codigo; estSaidaSetorMudou();
      if (d.setor_codigo === 'OBRA' && d.obra_id) {
        const ob = estEl('estSaidaObra');
        if ([...ob.options].some(o => o.value === d.obra_id)) { ob.value = d.obra_id; estSaidaSetorMudou(); }
      }
    }
  }
  if (ped && ped.solicitante && !estEl('estSaidaSolicitante').value) estEl('estSaidaSolicitante').value = ped.solicitante;
  // v3.32.0: OS/equipamento lidos do pedido (só preenche campo vazio; o operador pode apagar ou trocar)
  const info = estEl('estSaidaOSInfo'), partes = [];
  if (ped && ped.os && !estEl('estSaidaOS').value) { estEl('estSaidaOS').value = ped.os; partes.push('OS ' + ped.os); }
  if (ped && ped.equipamento && !estEl('estSaidaEquip').value) { estEl('estSaidaEquip').value = ped.equipamento; partes.push(ped.equipamento); }
  if (partes.length) { info.textContent = `✔ Lido do PC ${ped.pc}${ped.os_origem === 'ia' ? ' (por IA — confira)' : ''}: ${partes.join(' · ')}. Pode alterar ou apagar.`; info.hidden = false; }
}
function estPcAdicionar(i, silencioso) {
  const ped = EST_BUSCA_ULT && EST_BUSCA_ULT.pc; if (!ped || !ped.linhas[i]) return false;
  const l = ped.linhas[i];
  if (l.qtd_sugerida <= 0) { if (!silencioso) estToast('Este material não tem saldo ou já foi todo retirado deste PC.', true); return false; }
  const dup = [...estEl('estSaidaLinhas').children].find(r => r.dataset.pc === String(ped.pc) && r.dataset.linhaPc === l.linha_id);
  if (dup) { if (!silencioso) estToast('Este material do PC já está na lista.', true); return false; }
  estSaidaLimparVazias();
  estSaidaLinha({ codigo: l.codigo, descricao: l.descricao, unidade: l.unidade, quantidade: l.qtd_sugerida, pc: ped.pc, linha_pc: l.linha_id,
    foto: l.foto, foto_origem: l.foto_origem, fotos: l.fotos || [], pendente: l.pendente, rotulo: `PC ${ped.pc}${l.sku ? ' · SKU ' + l.sku : ''} · pendente ${estNum(l.pendente)} ${l.unidade}` });
  estSaidaDestinoSugerir(ped); estSaidaPcsRender();
  if (!silencioso) estToast('Adicionado: ' + l.descricao);
  return true;
}
function estPcAdicionarTodas() {
  const ped = EST_BUSCA_ULT && EST_BUSCA_ULT.pc; if (!ped) return;
  let n = 0; ped.linhas.forEach((l, i) => { if (estPcAdicionar(i, true)) n++; });
  estToast(n ? `${n} material(is) do PC ${ped.pc} adicionado(s). Confira as quantidades.` : 'Nada novo para adicionar deste PC.', !n);
  if (n) { const b = estEl('estSaidaBusca'); b.value = ''; estEl('estSaidaBuscaRes').hidden = true; estEl('estSaidaBuscaRes').innerHTML = ''; EST_BUSCA_ULT = null; b.focus(); }   // pronto para o próximo pedido
}
function estItemAdicionar(i) {
  const it = EST_BUSCA_ULT && (EST_BUSCA_ULT.itens || [])[i]; if (!it) return;
  if (it.disponivel <= 0) return estToast('Sem saldo disponível deste material.', true);
  if ([...estEl('estSaidaLinhas').children].some(r => r.dataset.codigo === it.codigo && !r.dataset.pc)) return estToast('Este material já está na lista.', true);
  estSaidaLimparVazias();
  const row = estSaidaLinha({ codigo: it.codigo, descricao: it.descricao, unidade: it.unidade, quantidade: '', foto: it.foto, foto_origem: it.foto_origem,
    rotulo: `${it.sku ? 'SKU ' + it.sku + ' · ' : ''}saldo ${estNum(it.disponivel)} ${it.unidade}` });
  const q = row.querySelector('.est-s-qtd'); q.focus();
}

/* v3.19.0: foto do produto na saída — reduzida no aparelho (até 1280 px, JPEG) antes de enviar. */
async function estSaidaFoto(inp) {
  const f = inp.files && inp.files[0], lab = inp.closest('.est-foto-btn');
  if (!f) return;
  try {
    const img = await new Promise((ok, err) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => err(new Error('Imagem inválida')); i.src = URL.createObjectURL(f); });
    const k = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight));
    const c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(img.src);
    let q = 0.82, url = c.toDataURL('image/jpeg', q);
    while (url.length > 1.9e6 && q > 0.4) { q -= 0.12; url = c.toDataURL('image/jpeg', q); }
    lab.dataset.foto = url;
    const im = lab.querySelector('img'); im.src = url; im.hidden = false; lab.querySelector('span').hidden = true; lab.classList.add('on');
    lab.title = 'Foto anexada — clique para trocar';
  } catch (e) { estToast('Não foi possível usar a foto: ' + e.message, true); }
}

function estFecharSaida() { estEl('estSaidaModal').classList.remove('on'); }

let EST_OBRAS = null;
async function estSaidaCarregarObras() {
  if (EST_OBRAS) return;
  try { EST_OBRAS = (await estApi('estoque_obras')).obras || []; } catch { EST_OBRAS = []; }
  estEl('estSaidaObra').innerHTML = '<option value="">Selecione a obra…</option>' + EST_OBRAS.map(o => `<option value="${estHtml(o.id)}">${estHtml(o.name)}${o.city ? ' — ' + estHtml(o.city) : ''}</option>`).join('') + '<option value="__outra">Outra obra (digitar)…</option>';
}
function estSaidaSetorMudou() {
  const obra = estEl('estSaidaSetor').value === 'OBRA';
  estEl('estSaidaObraBox').hidden = !obra;
  estEl('estSaidaObra').required = obra;
  const outra = obra && estEl('estSaidaObra').value === '__outra';
  estEl('estSaidaObraOutraBox').hidden = !outra;
  estEl('estSaidaObraOutra').required = outra;
}
const EST_SETOR_NOME = { OBRA: 'Obra', DEPOSITO: 'Depósito', CALDEIRARIA: 'Caldeiraria', MANUTENCAO: 'Manutenção', EXPEDICAO: 'Expedição', LOGISTICA: 'Logística', ADM: 'ADM' };
function estSaidaDestino() {
  const sc = estEl('estSaidaSetor').value, ov = estEl('estSaidaObra').value;
  if (!sc) return null;
  if (sc !== 'OBRA') return { setor_codigo: sc, rotulo: estEl('estSaidaSetor').selectedOptions[0].text };
  const nome = ov === '__outra' ? estEl('estSaidaObraOutra').value.trim() : ((EST_OBRAS || []).find(o => o.id === ov) || {}).name;
  if (!nome) return null;
  return { setor_codigo: 'OBRA', obra_id: ov === '__outra' ? '' : ov, obra_nome: nome, rotulo: 'Obra · ' + nome };
}

async function estSalvarSaida(ev) {
  ev.preventDefault();
  const linhas = [...estEl('estSaidaLinhas').children].map((row, i) => ({
    id: 'l' + (i + 1),
    descricao: row.querySelector('.est-s-desc').value,
    unidade: 'UN',
    quantidade: row.querySelector('.est-s-qtd').value,
    observacao: row.querySelector('.est-s-obs').value,
    foto: (row.querySelector('.est-foto-btn') || {}).dataset?.foto || undefined,
    codigo: row.dataset.codigo || undefined,
    pc: row.dataset.pc ? Number(row.dataset.pc) : undefined,
    linha_pc: row.dataset.linhaPc || undefined,
    foto_auto: !((row.querySelector('.est-foto-btn') || {}).dataset?.foto) && !!row.dataset.autoFoto || undefined,
    foto_escolhida: row.dataset.fotoEscolhida || undefined,
  })).filter(l => l.descricao.trim() && Number(l.quantidade) > 0);
  if (!linhas.length) return estToast('Inclua ao menos um material com quantidade', true);
  const dest = estSaidaDestino();
  if (!dest) return estToast(estEl('estSaidaSetor').value ? 'Escolha qual obra recebe o material.' : 'Escolha o setor de destino da saída.', true);
  const body = {
    solicitante: estEl('estSaidaSolicitante').value,
    setor: dest.rotulo, setor_codigo: dest.setor_codigo, obra_id: dest.obra_id || '', obra_nome: dest.obra_nome || '',
    finalidade: estEl('estSaidaFinalidade').value,
    // v3.32.0: OS e equipamento são opcionais; vão se estiverem preenchidos (à mão ou lidos do pedido).
    os: estEl('estSaidaOS').value.trim(), equipamento: estEl('estSaidaEquip').value.trim(),
    linhas,
  };
  body.com_os = !!body.os;
  const assinatura = await rdAssinar({ nome: body.solicitante,
    resumo: linhas.map(l => `<b>${estNum(l.quantidade)}</b> × ${estHtml(l.descricao)}${l.foto || l.foto_auto ? ' 📷' : ''}${l.pc ? ` <small>(PC ${estHtml(l.pc)})</small>` : ''}`).join('<br>') + (body.setor ? `<br>Destino: <b>${estHtml(body.setor)}</b>` : '') + (body.os || body.equipamento ? `<br>${body.os ? 'OS <b>' + estHtml(body.os) + '</b>' : ''}${body.os && body.equipamento ? ' · ' : ''}${body.equipamento ? 'Equipamento: <b>' + estHtml(body.equipamento) + '</b>' : ''}` : '') });
  if (!assinatura) return;
  try {
    if (typeof impSolic === 'function') body.solicitante = impSolic(body.solicitante, assinatura);
    const r = await estPost('estoque_saida', { ...body, efetivar: true, assinatura });
    estFecharSaida();
    estToast('Saída registrada com assinatura — saldo atualizado');
    if (confirm('Abrir o comprovante de entrega para imprimir/salvar?')) rdAbrirComprovante(r.requisicao.id);
    estLoadRequisicoes(); estLoadDashboard(); estLoadMovimentos(); estLoadItens();
  } catch (e) { estToast('Saída não registrada: ' + e.message, true); }
}

/* ---------------- movimentos ---------------- */

async function estLoadMovimentos() {
  try {
    const q = new URLSearchParams({ tipo: EST.movFiltro.tipo, de: EST.movFiltro.de, ate: EST.movFiltro.ate, q: estEl('estMovQ') ? estEl('estMovQ').value.trim() : '' });
    const r = await estApi('estoque_movimentos', { query: q });
    EST.movimentos = r.movimentos;
    const tipos = r.tipos || {};
    estEl('estMovTipo').innerHTML = '<option value="">Todos os tipos</option>' + Object.entries(tipos).map(([k, v]) => `<option value="${k}" ${k === EST.movFiltro.tipo ? 'selected' : ''}>${v}</option>`).join('');
    estEl('estMovCount').textContent = `${estNum(r.total)} movimento(s)`;
    estEl('estMovBody').innerHTML = r.movimentos.map(m => `
      <tr><td><code>${estHtml(m.id)}</code></td><td>${estData(m.em)}</td>
      <td>${estHtml(tipos[m.tipo] || m.tipo)}</td>
      <td>${estHtml(m.descricao || m.codigo)}${m.foto ? ` <a href="api.php?action=estoque_foto_saida&arq=${encodeURIComponent(m.foto)}" target="_blank" rel="noopener" title="Foto do produto na saída">📷</a>` : ''}<small class="est-sub"><code>${estHtml(m.codigo)}</code></small></td>
      <td class="${String(m.tipo).startsWith('ENTRADA') || m.tipo === 'ESTORNO_SAIDA' ? 'est-pos' : 'est-neg'}">${String(m.tipo).startsWith('ENTRADA') || m.tipo === 'ESTORNO_SAIDA' ? '+' : '−'}${estNum(m.quantidade)} ${estHtml(m.unidade || '')}</td>
      <td>${estNum(m.saldo_anterior)} → <strong>${estNum(m.saldo_posterior)}</strong></td>
      <td>${estHtml(m.pc ? 'PC ' + m.pc : (m.setor || m.fornecedor || m.documento || '—'))}${m.os || m.equipamento ? `<small class="est-sub">${m.os ? 'OS ' + estHtml(m.os) : ''}${m.os && m.equipamento ? ' · ' : ''}${estHtml(m.equipamento || '')}</small>` : ''}${m.usuario ? `<small class="est-sub">${estHtml(m.usuario)}</small>` : ''}</td></tr>`).join('')
      || '<tr><td colspan="7" class="empty">Nenhum movimento no período.</td></tr>';
  } catch (e) { estEl('estMovBody').innerHTML = `<tr><td colspan="7" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estMovFiltroChanged() {
  EST.movFiltro = { tipo: estEl('estMovTipo').value, de: estEl('estMovDe').value, ate: estEl('estMovAte').value };
  estLoadMovimentos();
}

function estExportar(tipo) {
  const q = new URLSearchParams({ tipo });
  window.open('api.php?action=estoque_export_csv&' + q.toString(), '_blank', 'noopener');
}

/* ---------------- integridade ---------------- */

async function estLoadIntegridade() {
  try {
    const r = await estApi('estoque_dashboard');
    const i = r.integridade || {};
    estEl('estIntegridadeBody').innerHTML = `
      <div class="est-integrity ${i.alterado_desde_instalacao ? 'mudou' : 'ok'}">
        <strong>${i.alterado_desde_instalacao ? 'A base de pedidos foi alterada desde a instalação' : 'Nenhuma alteração detectada na base de pedidos'}</strong>
        <span>Comparação por hash SHA-1 do arquivo <code>data/items.json</code>.</span>
      </div>
      <table class="orders-table"><tbody>
        <tr><th>PCs na base</th><td>${estNum(i.pedidos_total)}</td></tr>
        <tr><th>PCs com conferência iniciada/finalizada</th><td>${estNum(i.pedidos_com_conferencia)}</td></tr>
        <tr><th>Linhas de insumo</th><td>${estNum(i.linhas_de_insumo)}</td></tr>
        <tr><th>Hash atual de items.json</th><td><code>${estHtml(i.hash_atual || '—')}</code></td></tr>
        <tr><th>Hash no momento da instalação</th><td><code>${estHtml(i.hash_instalacao || '—')}</code></td></tr>
        <tr><th>Cópia de segurança gerada</th><td><code>${estHtml(i.copia_seguranca || '—')}</code></td></tr>
      </tbody></table>
      ${(i.finalizados_sem_entrada || []).length ? `<div class="est-integrity mudou"><strong>${i.finalizados_sem_entrada.length} PC(s) finalizado(s) sem entrada no estoque</strong>
        <span>Recebimento concluído, mas o material não aparece no estoque (nem para saída). Gere a entrada de cada um, confira e confirme.</span></div>
        <table class="orders-table"><thead><tr><th>PC</th><th>Fornecedor</th><th>Finalizado em</th><th></th></tr></thead><tbody>${i.finalizados_sem_entrada.map(x => `<tr><td data-label="PC">${estHtml(x.pc)}</td><td data-label="Fornecedor">${estHtml(x.fornecedor)}</td><td data-label="Finalizado em">${estHtml(String(x.finalizado_em || '').slice(0, 10))}</td><td><button class="button ghost small" onclick="estGerarEntradaDaSaida(${Number(x.pc)})">Gerar entrada</button></td></tr>`).join('')}</tbody></table>` : ''}
      <p class="est-hint">${estHtml(i.protecao || '')}</p>`;
  } catch (e) { estEl('estIntegridadeBody').innerHTML = `<div class="est-erro">${estHtml(e.message)}</div>`; }
}

window.addEventListener('DOMContentLoaded', () => { if (estEl('page-estoque')) rdWhenLogged(estoqueInit); });


/* ================================================================== */
/* Inventário cíclico                                                 */
/* ================================================================== */

async function estLoadInventarios() {
  try {
    const r = await estApi('estoque_inventarios');
    estEl('estInvBody').innerHTML = r.inventarios.map(i => `
      <tr><td><code>${estHtml(i.id)}</code></td>
      <td>${estHtml(i.escopo)}${i.filtro ? ' · ' + estHtml(i.filtro) : ''}</td>
      <td>${estNum(i.contados)} / ${estNum(i.total_itens)}</td>
      <td>${i.divergencias ? `<span class="est-badge warn">${estNum(i.divergencias)}</span>` : '<span class="est-badge ok">0</span>'}</td>
      <td><span class="est-badge ${i.status === 'aplicado' ? 'ok' : 'warn'}">${i.status === 'aplicado' ? 'Aplicada' : 'Em contagem'}</span></td>
      <td>${estData(i.criado_em)}</td>
      <td class="est-actions"><button class="button ghost small" onclick="estAbrirInv('${estHtml(i.id)}')">${i.status === 'aplicado' ? 'Consultar' : 'Contar'}</button></td></tr>`).join('')
      || '<tr><td colspan="7" class="empty">Nenhuma contagem registrada.</td></tr>';
  } catch (e) { estEl('estInvBody').innerHTML = `<tr><td colspan="7" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estNovaContagem() { estEl('estInvNova').classList.add('on'); }

async function estCriarContagem() {
  try {
    const r = await estPost('estoque_inventario_criar', {
      escopo: estEl('estInvEscopo').value,
      filtro: estEl('estInvFiltro').value,
    });
    estEl('estInvNova').classList.remove('on');
    estToast('Contagem ' + r.inventario.id + ' criada com ' + r.inventario.itens.length + ' item(ns)');
    await estLoadInventarios();
    estAbrirInv(r.inventario.id);
  } catch (e) { estToast('Não foi possível criar a contagem: ' + e.message, true); }
}

let EST_INV = null;

async function estAbrirInv(id) {
  try {
    const r = await estApi('estoque_inventario', { query: { id } });
    EST_INV = r.inventario;
    const aplicado = EST_INV.status === 'aplicado';
    estEl('estInvTitle').textContent = 'Contagem ' + EST_INV.id;
    estEl('estInvSub').textContent = EST_INV.escopo + (EST_INV.filtro ? ' · ' + EST_INV.filtro : '') + ' · ' + estData(EST_INV.criado_em);
    estEl('estInvStatus').innerHTML = `<span class="est-badge ${aplicado ? 'ok' : 'warn'}">${aplicado ? 'Aplicada em ' + estData(EST_INV.aplicado_em) : 'Em contagem'}</span>`;
    estEl('estInvLinhas').innerHTML = Object.values(EST_INV.itens).map(l => {
      const dif = l.diferenca;
      const cls = dif === null ? '' : (Math.abs(dif) < 0.0001 ? 'est-row-ok' : 'est-row-div');
      return `<tr class="${cls}"><td>${estHtml(l.descricao)}<small class="est-sub"><code>${estHtml(l.codigo)}</code></small></td>
        <td>${estHtml(l.localizacao || '—')}</td><td>${estNum(l.saldo_sistema)}</td>
        <td>${aplicado ? estNum(l.contado ?? 0) : `<input type="number" min="0" step="any" class="est-inv-q" data-codigo="${estHtml(l.codigo)}" value="${l.contado ?? ''}" placeholder="—">`}</td>
        <td>${dif === null ? '—' : `<strong class="${dif < 0 ? 'est-neg' : dif > 0 ? 'est-pos' : ''}">${dif > 0 ? '+' : ''}${estNum(dif)}</strong>`}</td></tr>`;
    }).join('');
    estEl('estInvFoot').innerHTML = aplicado
      ? `<button class="button ghost" onclick="estFecharInv()">Fechar</button>`
      : `<button class="button ghost" onclick="estFecharInv()">Fechar</button>
         <button class="button ghost" onclick="estSalvarContagem()">Salvar contagem</button>
         <button class="button primary" onclick="estAplicarContagem()">✓ Aplicar ao estoque</button>`;
    estEl('estInvModal').classList.add('on');
  } catch (e) { estToast('Falha ao abrir a contagem: ' + e.message, true); }
}

function estFecharInv() { estEl('estInvModal').classList.remove('on'); EST_INV = null; }

async function estSalvarContagem(aplicar = false) {
  const itens = [...estEl('estInvLinhas').querySelectorAll('.est-inv-q')].map(i => ({ codigo: i.dataset.codigo, contado: i.value }));
  try {
    await estPost('estoque_inventario_contar', { id: EST_INV.id, itens });
    estToast('Contagem salva');
    if (aplicar) return estAplicarContagem();
    await estAbrirInv(EST_INV.id);
    estLoadInventarios();
  } catch (e) { estToast('Contagem não salva: ' + e.message, true); }
}

async function estAplicarContagem() {
  if (!confirm('Aplicar esta contagem? As diferenças gerarão ajustes auditados no estoque.')) return;
  await estSalvarContagem();
  try {
    const r = await estPost('estoque_inventario_aplicar', { id: EST_INV.id });
    estToast('Contagem aplicada: ' + r.ajustados + ' ajuste(s)');
    estFecharInv();
    estLoadInventarios(); estLoadDashboard(); estLoadItens(); estLoadMovimentos();
  } catch (e) { estToast('Não foi possível aplicar: ' + e.message, true); }
}

/* ================================================================== */
/* Reservas                                                           */
/* ================================================================== */

async function estLoadReservas() {
  try {
    const qq = estEl('estResQ') ? estEl('estResQ').value.trim() : '';
    const r = await estApi('estoque_reservas', { query: qq ? { q: qq } : {} });
    estEl('estResBody').innerHTML = r.reservas.map(x => `
      <tr><td><code>${estHtml(x.id)}</code></td><td>PC ${x.pc}</td><td>${estNum((x.itens || []).length)}</td>
      <td><span class="est-badge ${x.status === 'ativa' ? 'ok' : 'danger'}">${x.status}</span></td>
      <td>${estData(x.atualizado_em)}</td>
      <td class="est-actions">${x.status === 'ativa' ? `<button class="button ghost small" onclick="estCancelarReserva('${estHtml(x.id)}')">Cancelar</button>` : '—'}</td></tr>`).join('')
      || '<tr><td colspan="6" class="empty">Nenhuma reserva ativa.</td></tr>';
  } catch (e) { estEl('estResBody').innerHTML = `<tr><td colspan="6" class="empty">${estHtml(e.message)}</td></tr>`; }
}

function estNovaReserva() { estEl('estResForm').reset(); estEl('estResModal').classList.add('on'); estLoadItensSaida(); }

async function estSalvarReserva(ev) {
  ev.preventDefault();
  try {
    await estPost('estoque_reservar', {
      pc: Number(estEl('estResPc').value),
      linhas: [{ id: 'l1', descricao: estEl('estResDesc').value, unidade: 'UN', quantidade: estEl('estResQtd').value }],
    });
    estEl('estResModal').classList.remove('on');
    estToast('Material reservado');
    estLoadReservas(); estLoadItens();
  } catch (e) { estToast('Reserva não registrada: ' + e.message, true); }
}

async function estCancelarReserva(id) {
  if (!confirm('Cancelar esta reserva e liberar o material?')) return;
  try { await estPost('estoque_reserva_cancelar', { id }); estToast('Reserva cancelada'); estLoadReservas(); estLoadItens(); }
  catch (e) { estToast('Falha: ' + e.message, true); }
}

/* ================================================================== */
/* Reposição e Curva ABC                                              */
/* ================================================================== */

async function estLoadReposicao() {
  try {
    const r = await estApi('estoque_reposicao');
    estEl('estRepBody').innerHTML = r.itens.map(i => `
      <tr><td><strong>${estHtml(i.descricao)}</strong><small class="est-sub"><code>${estHtml(i.codigo)}</code></small></td>
      <td>${estNum(i.saldo)} ${estHtml(i.unidade)}</td><td>${estNum(i.minimo)}</td>
      <td>${estNum(i.ponto_pedido)}</td><td>${estNum(i.maximo)}</td>
      <td><strong>${estNum(i.sugerido)}</strong></td>
      <td>${estHtml(i.fornecedor || '—')}</td>
      <td><span class="est-badge ${i.urgencia === 'ZERADO' ? 'danger' : 'warn'}">${i.urgencia}</span></td></tr>`).join('')
      || '<tr><td colspan="8" class="empty">Nenhum item no ponto de pedido. Configure mínimo/ponto de pedido na ficha do item.</td></tr>';
    estFiltrarLinhas('estRepQ', 'estRepBody');
  } catch (e) { estEl('estRepBody').innerHTML = `<tr><td colspan="8" class="empty">${estHtml(e.message)}</td></tr>`; }
}

async function estLoadAbc() {
  try {
    const de = estEl('estAbcDe')?.value || '';
    const ate = estEl('estAbcAte')?.value || '';
    const r = await estApi('estoque_abc', { query: { de, ate } });
    estEl('estAbcBody').innerHTML = r.itens.map(i => `
      <tr><td><span class="est-badge ${i.classe === 'A' ? 'danger' : i.classe === 'B' ? 'warn' : 'ok'}">${i.classe}</span></td>
      <td>${estHtml(i.descricao)}<small class="est-sub"><code>${estHtml(i.codigo)}</code></small></td>
      <td>${estMoeda(i.valor)}</td><td>${i.percentual}%</td><td>${i.acumulado}%</td></tr>`).join('')
      || '<tr><td colspan="5" class="empty">Sem saídas no período para classificar.</td></tr>';
    estFiltrarLinhas('estAbcQ', 'estAbcBody');
  } catch (e) { estEl('estAbcBody').innerHTML = `<tr><td colspan="5" class="empty">${estHtml(e.message)}</td></tr>`; }
}


/* ---------------- Inventário SAP (v3.9) ---------------- */
const EST_SAP = { linhas: null, arquivo: '', deposito: '' };

async function estSapLer() {
  const f = estEl('estSapFile').files[0];
  if (!f) return;
  estEl('estSapStatus').textContent = 'Lendo a planilha…';
  try {
    const X = await import('./xlsx-lib.js');
    const wb = X.read(await f.arrayBuffer(), { type: 'array', raw: true });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const m = X.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
    const norm = v => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    let h = m.findIndex(r => r && r.some(c => norm(c).startsWith('no do item') || norm(c).startsWith('nº do item') || norm(c) === 'n do item' || norm(c).includes('do item') && norm(c).startsWith('n')));
    if (h < 0) h = 0;
    const cab = m[h].map(norm);
    const col = (...nomes) => cab.findIndex(c => nomes.some(n => c.startsWith(n)));
    const ix = { codigo: col('no do item', 'nº do item', 'n do item', 'codigo', 'item'), descricao: col('descricao'), unidade: col('unidade'),
      em_estoque: col('em estoque', 'qtd.acumulada', 'qtd acumulada', 'quantidade acumulada', 'qtd'), pedido: col('pedido'), preco: col('preco'), total: col('total') };
    if (ix.codigo < 0 || ix.em_estoque < 0) throw new Error('Colunas "Nº do item" e "Em estoque" (ou "Qtd.acumulada") não encontradas.');
    let deposito = '';
    const linhas = [];
    for (const r of m.slice(h + 1)) {
      if (!r) continue;
      const c = String(r[ix.codigo] ?? '').trim();
      if (!c) continue;
      if (/^dep[oó]sito/i.test(c)) { deposito = String(r[ix.codigo + 1] ?? '').trim(); continue; }
      const get = k => ix[k] >= 0 ? r[ix[k]] : null;
      linhas.push({ codigo: c, descricao: get('descricao'), unidade: get('unidade'), em_estoque: get('em_estoque'), pedido: get('pedido'), preco: get('preco'), total: get('total') });
    }
    EST_SAP.linhas = linhas; EST_SAP.arquivo = f.name; EST_SAP.deposito = deposito;
    await estSapPrevia();
  } catch (e) { estEl('estSapStatus').textContent = 'Não foi possível ler: ' + e.message; }
}

function estSapOpcoes() {
  return { arquivo: EST_SAP.arquivo, deposito: EST_SAP.deposito, data_base: estEl('estSapData').value, somar_pedidos: estEl('estSapSomar').checked };
}

async function estSapPrevia() {
  if (!EST_SAP.linhas) return;
  estEl('estSapStatus').textContent = 'Calculando a conciliação…';
  try {
    const r = await estPost('estoque_sap_previa', { linhas: EST_SAP.linhas, opcoes: estSapOpcoes() });
    const p = r.previa, t = p.totais;
    estEl('estSapStatus').innerHTML = `Arquivo <b>${estHtml(EST_SAP.arquivo)}</b> · depósito <b>${estHtml(EST_SAP.deposito || '—')}</b> · ${estNum(t.linhas)} itens lidos.`;
    const aviso = [];
    if (t.possivel_duplicidade) aviso.push(`<b>${estNum(t.possivel_duplicidade)} item(ns)</b> já aparecem no SAP com quantidade igual ou maior que a recebida pelos pedidos. O SAP provavelmente já lançou essas notas; se for o caso, desmarque "Somar os recebimentos" para não contar em dobro.`);
    if (t.sem_preco_com_saldo) aviso.push(`${estNum(t.sem_preco_com_saldo)} item(ns) com saldo e sem preço no SAP (valor ficará zerado).`);
    if (t.fora_da_planilha) aviso.push(`${estNum(t.fora_da_planilha)} item(ns) com saldo no sistema não estão na planilha e permanecem como estão.`);
    if (p.duplicados?.length) aviso.push(`Códigos repetidos na planilha (usada a 1ª linha): ${p.duplicados.map(estHtml).join(', ')}`);
    estEl('estSapPrevia').innerHTML = `
      <div class="kpi-grid est-kpis est-sap-kpis">
        <div class="kpi"><span>Valor no SAP</span><strong>${estMoeda(t.valor_sap)}</strong><small>${estNum(t.com_saldo)} itens com saldo</small></div>
        <div class="kpi"><span>+ Pedidos de compra</span><strong>${estMoeda(t.pedidos_valor)}</strong><small>${estNum(t.pedidos_qtd)} unid. não conciliadas</small></div>
        <div class="kpi ok"><span>Valor final do estoque</span><strong>${estMoeda(t.valor_final)}</strong><small>após a conciliação</small></div>
        <div class="kpi"><span>Itens novos / existentes</span><strong>${estNum(t.novos)} / ${estNum(t.existentes)}</strong><small>${estNum(t.unificados)} unificado(s) ao código SAP</small></div>
        <div class="kpi ${t.divergencias ? 'warn' : ''}"><span>Divergências</span><strong>${p.primeira_carga ? '—' : estNum(t.divergencias)}</strong><small>${p.primeira_carga ? 'carga inicial: sem comparação' : 'de ' + estNum(t.conferidos) + ' conferidos'}</small></div>
        <div class="kpi ${t.valor_divergencia_neg ? 'bad' : ''}"><span>Falta / sobra (R$)</span><strong>${p.primeira_carga ? '—' : estMoeda(t.valor_divergencia_neg) + ' / ' + estMoeda(t.valor_divergencia_pos)}</strong><small>SAP × sistema</small></div>
      </div>
      ${aviso.length ? `<div class="panel" style="border-color:#695638;background:#2b2618;margin-bottom:14px;font-size:12px;line-height:1.6">${aviso.map(a => '⚠ ' + a).join('<br>')}</div>` : ''}
      <div class="table-panel"><div class="table-scroll" style="max-height:460px"><table class="orders-table">
        <thead><tr><th>Código</th><th>Descrição</th><th>SAP</th><th>+ PCs</th><th>Saldo hoje</th><th>Saldo final</th><th>Preço</th><th>Valor final</th><th>Diferença</th><th>Situação</th></tr></thead>
        <tbody>${p.linhas.map(l => `<tr>
          <td><code>${estHtml(l.codigo)}</code>${l.codigo_anterior ? `<br><small class="est-hint">era ${estHtml(l.codigo_anterior)}</small>` : ''}</td>
          <td>${estHtml(l.descricao)}</td><td>${estNum(l.qtd_sap)}</td>
          <td>${l.pedidos ? `<b style="color:var(--teal)">+${estNum(l.pedidos)}</b>${l.possivel_duplicidade ? ' <span class="est-badge warn" title="O SAP já pode incluir esta entrada">dupla?</span>' : ''}` : '—'}</td>
          <td>${estNum(l.saldo_atual)}</td><td><b>${estNum(l.saldo_final)}</b> ${estHtml(l.unidade)}</td>
          <td>${estMoeda(l.preco)}</td><td>${estMoeda(l.valor_final)}</td>
          <td>${l.esperado === null ? '—' : (Math.abs(l.diferenca) > 0.0001 ? `<span class="est-badge ${l.diferenca < 0 ? 'bad' : 'warn'}">${l.diferenca > 0 ? '+' : ''}${estNum(l.diferenca)}</span>` : '<span class="est-badge ok">0</span>')}</td>
          <td>${l.situacao === 'novo' ? 'Novo' : l.situacao === 'unificado' ? 'Unificado' : 'Atualizado'}</td></tr>`).join('')}</tbody></table></div>
        <div class="pagination"><span>Mostrando ${estNum(p.linhas.length)} de ${estNum(p.linhas_total)} itens com saldo ou movimento (maior valor primeiro). Itens sem saldo entram só no cadastro, com o preço do SAP.</span>
        <button class="button primary" onclick="estSapImportar()">Confirmar e conciliar</button></div></div>`;
  } catch (e) { estEl('estSapStatus').textContent = 'Falha na prévia: ' + e.message; estEl('estSapPrevia').innerHTML = ''; }
}

async function estSapImportar() {
  if (!EST_SAP.linhas) return;
  const somar = estEl('estSapSomar').checked;
  if (!confirm(`Conciliar o inventário SAP agora?\n\n${somar ? 'Os recebimentos de pedidos ainda não conciliados serão SOMADOS ao saldo do SAP.' : 'O saldo de cada item passará a ser exatamente o do SAP (sem somar pedidos).'}\n\nUma cópia da planilha e o histórico de origem ficam guardados.`)) return;
  try {
    const r = await estPost('estoque_sap_importar', { linhas: EST_SAP.linhas, opcoes: estSapOpcoes(), confirmo: true });
    estToast(`Inventário ${r.importacao.id} conciliado · ${estMoeda(r.importacao.totais.valor_final)}`);
    EST_SAP.linhas = null; estEl('estSapFile').value = ''; estEl('estSapPrevia').innerHTML = '';
    estEl('estSapStatus').textContent = 'Conciliação gravada. Os saldos já estão atualizados em "Itens & saldos".';
    estSapHistorico();
  } catch (e) { estToast('Falha ao conciliar: ' + e.message, true); }
}

async function estSapHistorico() {
  if (!estEl('estSapData').value) estEl('estSapData').value = new Date().toISOString().slice(0, 10);
  try {
    const r = await estApi('estoque_sap_historico');
    estEl('estSapHist').innerHTML = r.historico.map(h => `<tr><td><code>${estHtml(h.id)}</code><br><small class="est-hint">${estHtml(h.arquivo || '')} · ${estHtml(h.deposito || '')}</small></td>
      <td>${estData(h.em)}</td><td>${estHtml(h.usuario || '')}</td><td>${estNum(h.totais.linhas)}</td><td>${estMoeda(h.totais.valor_sap)}</td>
      <td>${h.somar_pedidos ? estMoeda(h.totais.pedidos_valor) : 'não somado'}</td><td><b>${estMoeda(h.totais.valor_final)}</b></td>
      <td>${h.primeira_carga ? 'carga inicial' : estNum(h.totais.divergencias) + ' · ' + estMoeda(h.totais.valor_divergencia_neg)}</td></tr>`).join('')
      || '<tr><td colspan="8" class="empty">Nenhuma importação ainda.</td></tr>';
  } catch (e) { estEl('estSapHist').innerHTML = `<tr><td colspan="8" class="empty">${estHtml(e.message)}</td></tr>`; }
}


/* ---------------- Funcionários autorizados (v3.9.3) ---------------- */
const FUN = { lista: [], q: '', inativos: false, nomes: null };
async function funCarregar() {
  try {
    const r = await estApi('estoque_funcionarios');
    FUN.lista = r.funcionarios;
    const imp = r.importacao;
    estEl('funInfo').textContent = `${FUN.lista.filter(f => f.ativo).length} ativo(s)` + (imp ? ` · lista importada em ${estData(imp.em)}${imp.data_lista ? ' (posição de ' + imp.data_lista.split('-').reverse().join('/') + ')' : ''}` : '') + (r.retiradas_terceiros ? ` · ${r.retiradas_terceiros} retirada(s) por terceiros` : '');
    if (typeof RD_FUNC !== 'undefined') RD_FUNC = null;
    funRender();
  } catch (e) { estEl('funBody').innerHTML = `<tr><td colspan="7" class="empty">${estHtml(e.message)}</td></tr>`; }
}
function funRender() {
  const q = FUN.q.toLowerCase();
  const l = FUN.lista.filter(f => (FUN.inativos || f.ativo) && (!q || (f.nome + ' ' + (f.matricula || '')).toLowerCase().includes(q)));
  estEl('funBody').innerHTML = l.map(f => `<tr class="${f.ativo ? '' : 'fun-inativo'}"><td><b>${estHtml(f.nome)}</b>${f.email ? ' <span class="fun-mail-ic" title="Recebe aviso de chegada de pedidos">✉</span>' : ''}</td><td>${estHtml(f.matricula || '—')}</td>
    <td>${estHtml([f.setor, f.funcao].filter(Boolean).join(' · ') || '—')}</td>
    <td><span class="est-badge ${f.ativo ? 'ok' : 'warn'}">${f.ativo ? 'Ativo' : 'Inativo'}</span></td>
    <td>${f.retiradas ? `<a href="#" onclick="funRetiradas('${estHtml(f.id)}');return false">${estNum(f.retiradas)}</a>` : '0'}</td><td>${f.ultima_retirada ? estData(f.ultima_retirada) : '—'}</td>
    <td class="est-actions"><button class="button ghost small" onclick="funEditar('${estHtml(f.id)}')">Editar</button>
    <button class="button ghost small" onclick="funAtivo('${estHtml(f.id)}',${f.ativo ? 'false' : 'true'})">${f.ativo ? 'Inativar' : 'Reativar'}</button></td></tr>`).join('')
    || '<tr><td colspan="7" class="empty">Nenhum funcionário. Use “Importar planilha”.</td></tr>';
}
async function funSalvar(dados) { await estPost('estoque_funcionario_salvar', dados); await funCarregar(); }
function funEditar(id) {
  const f = FUN.lista.find(x => x.id === id) || { nome: '', matricula: '', setor: 'Depósito', funcao: '', ativo: true };
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<form class="rd-ass"><h2>${id ? 'Editar funcionário' : 'Novo funcionário'}</h2>
    <div class="rd-ass-campos"><label>Nome completo<input name="nome" required value="${estHtml(f.nome)}"></label><label>Matrícula<input name="matricula" value="${estHtml(f.matricula || '')}"></label>
    <label>Setor<input name="setor" value="${estHtml(f.setor || '')}"></label><label>Função<input name="funcao" value="${estHtml(f.funcao || '')}"></label></div>
    <details class="fun-mail" ${f.email ? 'open' : ''}><summary title="Aviso de chegada de pedidos">✉</summary>
      <label>E-mail — avisa quando um pedido que ele(a) solicitou chegar no almoxarifado<input name="email" type="email" value="${estHtml(f.email || '')}" placeholder="nome@empresa.com.br" autocomplete="off"></label>
      <label>Como o nome aparece nos pedidos (opcional, separe por vírgula)<input name="apelidos" value="${estHtml(f.apelidos || '')}" placeholder="MARCOS, MARCOS ENG"></label></details>
    <div class="rd-ass-acoes"><button type="button" class="button ghost">Cancelar</button><button class="button primary">Salvar</button></div></form>`;
  document.body.appendChild(bg);
  bg.querySelector('.button.ghost').onclick = () => bg.remove();
  bg.querySelector('form').onsubmit = async ev => { ev.preventDefault(); const t = ev.target;
    try { await funSalvar({ id: id || '', nome: t.nome.value, matricula: t.matricula.value, setor: t.setor.value, funcao: t.funcao.value, ativo: f.ativo, email: t.email.value.trim(), apelidos: t.apelidos.value }); bg.remove(); estToast('Funcionário salvo.'); }
    catch (e) { estToast(e.message, true); } };
  setTimeout(() => bg.querySelector('input').focus(), 50);
}
async function funAtivo(id, ativo) {
  const f = FUN.lista.find(x => x.id === id); if (!f) return;
  if (!ativo && !confirm(`Inativar ${f.nome}? Ele(a) deixa de poder retirar material.`)) return;
  try { await funSalvar({ ...f, ativo }); estToast(ativo ? 'Reativado.' : 'Inativado.'); } catch (e) { estToast(e.message, true); }
}
async function funRetiradas(id) {
  const f = FUN.lista.find(x => x.id === id);
  try {
    const r = await estApi('estoque_funcionario_retiradas', { query: { id } });
    estEl('estFichaTitle').textContent = 'Retiradas de ' + f.nome;
    estEl('estFichaBody').innerHTML = `<div class="est-table-scroll"><table class="orders-table"><thead><tr><th>Data</th><th>Material</th><th>Qtd</th><th>Destino</th><th>Comprovante</th></tr></thead><tbody>
      ${r.retiradas.map(x => `<tr><td>${estData(x.em)}</td><td><code>${estHtml(x.codigo)}</code> ${estHtml(x.descricao)}</td><td>${estNum(x.quantidade)} ${estHtml(x.unidade)}</td><td>${estHtml(x.destino || '—')}</td>
      <td><a href="#" onclick="rdAbrirComprovante('${estHtml(/^REQ-/.test(x.documento) ? x.documento : x.mov)}');return false">abrir</a></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem retiradas.</td></tr>'}</tbody></table></div>`;
    estEl('estFicha').classList.add('on');
  } catch (e) { estToast(e.message, true); }
}
async function funLerPlanilha(inp) {
  const file = inp.files[0]; if (!file) return;
  try {
    const X = await import('./xlsx-lib.js');
    const wb = X.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
    const ws = wb.Sheets[wb.SheetNames.find(n => /ativo/i.test(n)) || wb.SheetNames[0]];
    const m = X.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
    let col = -1, h = -1, data = '';
    m.forEach((r, i) => (r || []).forEach((c, j) => {
      if (c instanceof Date && !data) data = c.toISOString().slice(0, 10);
      if (h < 0 && /nome/i.test(String(c || ''))) { h = i; col = j; }
    }));
    if (h < 0) { col = (m.find(r => r && r.some(Boolean)) || []).findIndex(Boolean); h = -1; }
    FUN.nomes = { nomes: m.slice(h + 1).map(r => r && r[col]).filter(v => typeof v === 'string' && v.trim()), arquivo: file.name, data_lista: data };
    const r = await estPost('estoque_funcionarios_importar', FUN.nomes);
    const p = r.importacao;
    estEl('funPrevia').innerHTML = `<div class="panel" style="margin-bottom:14px"><h3 style="margin-bottom:6px">Prévia da lista ${data ? '(posição de ' + data.split('-').reverse().join('/') + ')' : ''}</h3>
      <p>${estNum(p.na_planilha)} nome(s) na planilha · <b>${estNum(p.novos.length)} novo(s)</b> · ${estNum(p.ja_cadastrados)} já cadastrado(s)${p.reativar.length ? ' · ' + p.reativar.length + ' será(ão) reativado(s)' : ''}</p>
      ${p.novos.length ? `<details><summary>Ver novos</summary><p class="muted">${p.novos.map(estHtml).join(' · ')}</p></details>` : ''}
      ${p.inativar.length ? `<label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:12px"><input type="checkbox" id="funInativar"> Inativar ${p.inativar.length} funcionário(s) que não estão mais na lista: ${p.inativar.map(estHtml).join(', ')}</label>` : ''}
      <button class="button primary" onclick="funConfirmar()">Confirmar importação</button> <button class="button ghost" onclick="estEl('funPrevia').innerHTML=''">Cancelar</button></div>`;
  } catch (e) { estToast('Não foi possível ler a planilha: ' + e.message, true); }
  inp.value = '';
}
async function funConfirmar() {
  try {
    await estPost('estoque_funcionarios_importar', { ...FUN.nomes, confirmo: true, inativar_ausentes: !!estEl('funInativar')?.checked });
    estEl('funPrevia').innerHTML = ''; estToast('Lista de funcionários importada.'); funCarregar();
  } catch (e) { estToast(e.message, true); }
}


/* v3.51.3 — busca de item pelo nome, sempre visível no topo do Estoque */
let EST_GB = null, EST_GB_SEQ = 0;
function estGBuscaDigitou() {
  clearTimeout(EST_GB);
  const q = estEl('estGBusca').value.trim(), box = estEl('estGRes');
  if (q.length < 2) { box.hidden = true; return; }
  EST_GB = setTimeout(async () => {
    const seq = ++EST_GB_SEQ;
    try {
      const r = await estApi('estoque_itens', { query: { q, limite: 8 } });
      if (seq !== EST_GB_SEQ) return;
      box.hidden = false;
      box.innerHTML = r.itens.length
        ? r.itens.map(i => `<button type="button" class="est-gitem" onclick="estGBuscaAbrir('${estHtml(i.codigo)}')"><span><strong>${estHtml(i.descricao)}</strong><small>${estHtml(i.codigo)}${i.localizacao ? ' · ' + estHtml(i.localizacao) : ''}</small></span>
            <span class="est-gsaldo"><b>${estNum(i.saldo)}</b> ${estHtml(i.unidade)}<small class="est-badge ${i.status === 'SEM ESTOQUE' ? 'danger' : i.status === 'CRÍTICO' ? 'warn' : i.status === 'ATENÇÃO' ? 'info' : 'ok'}">${estHtml(i.status)}</small></span></button>`).join('')
          + (r.total > r.itens.length ? `<button type="button" class="est-gmais" onclick="estGBuscaLista()">Ver os ${estNum(r.total)} itens encontrados →</button>` : '')
        : '<div class="est-gvazio">Nenhum item com esse nome. Tente menos palavras.</div>';
    } catch (e) { box.hidden = false; box.innerHTML = `<div class="est-gvazio">${estHtml(e.message)}</div>`; }
  }, 250);
}
function estGBuscaAbrir(codigo) { estEl('estGRes').hidden = true; estVerItem(codigo); }
function estGBuscaLista() {
  const q = estEl('estGBusca').value.trim(); estEl('estGRes').hidden = true;
  EST.filtro.q = q; estEl('estQ').value = q; estGo('itens');
}
function estGBuscaTecla(ev) {
  if (ev.key === 'Enter') { ev.preventDefault(); estGBuscaLista(); }
  if (ev.key === 'Escape') estEl('estGRes').hidden = true;
}
document.addEventListener('click', ev => { const b = document.getElementById('estGBuscaBox'); if (b && !b.contains(ev.target)) { const r = document.getElementById('estGRes'); if (r) r.hidden = true; } });


/* ---------------- v3.57.0 — Cadastros duplicados: sugestões + unificação auditada ---------------- */
const EST_DUP = { pares: [] };
async function estDupAbrir() {
  estEl('estDupModal').classList.add('on');
  const b = estEl('estDupBody'); b.innerHTML = '<p class="muted">Procurando cadastros parecidos…</p>';
  try { EST_DUP.pares = (await estApi('estoque_duplicados')).pares || []; estDupRender(); }
  catch (e) { b.innerHTML = `<p class="muted">${estHtml(e.message)}</p>`; }
}
function estDupCard(it, manter) {
  return `<div class="est-dup-it${manter ? ' manter' : ''}"><b>${estHtml(it.codigo)}</b>${it.provisorio ? ' <small>(criado sem código SAP)</small>' : ''}<br>${estHtml(it.descricao)}<br><small>Saldo ${estNum(it.saldo)} ${estHtml(it.unidade)}${it.reservado ? ' · reservado ' + estNum(it.reservado) : ''}</small></div>`;
}
function estDupRender() {
  const b = estEl('estDupBody'), L = EST_DUP.pares;
  if (!L.length) { b.innerHTML = '<p class="muted">Nenhum cadastro parecido encontrado. 👍</p>'; return; }
  b.innerHTML = '<p class="muted">Itens com nomes parecidos. Confira e unifique os que são o mesmo material — o saldo do cadastro antigo é transferido (com registro no histórico) e ele deixa de aparecer.</p>' +
    L.map((p, i) => `<div class="est-dup-par" id="estDupPar${i}"><div class="est-dup-cols">${estDupCard(p.a, p.manter === p.a.codigo)}${estDupCard(p.b, p.manter === p.b.codigo)}</div>
      <div class="est-dup-acao"><label>Manter <select id="estDupKeep${i}" onchange="estDupAviso(${i})"><option value="a"${p.manter === p.a.codigo ? ' selected' : ''}>${estHtml(p.a.codigo)}</option><option value="b"${p.manter === p.b.codigo ? ' selected' : ''}>${estHtml(p.b.codigo)}</option></select></label>
      <label>1 do cadastro antigo = <input id="estDupFator${i}" type="number" min="0" step="any" value="1" style="width:80px"> do mantido</label>
      <span class="est-dup-aviso" id="estDupAv${i}"></span>
      <button class="button primary" onclick="estDupUnificar(${i})">Unificar</button><button class="button ghost" onclick="estDupIgnorar(${i})">São itens diferentes</button></div></div>`).join('');
  L.forEach((_, i) => estDupAviso(i));
}
function estDupAviso(i) {
  const p = EST_DUP.pares[i], k = estEl('estDupKeep' + i).value, keep = k === 'a' ? p.a : p.b, de = k === 'a' ? p.b : p.a;
  estEl('estDupAv' + i).textContent = String(keep.unidade).toUpperCase() !== String(de.unidade).toUpperCase()
    ? `⚠ Unidades diferentes (${de.unidade} → ${keep.unidade}): informe quantas ${keep.unidade} vale 1 ${de.unidade}.` : '';
}
async function estDupUnificar(i) {
  const p = EST_DUP.pares[i], k = estEl('estDupKeep' + i).value, keep = k === 'a' ? p.a : p.b, de = k === 'a' ? p.b : p.a;
  const fator = Number(estEl('estDupFator' + i).value);
  if (!(fator > 0)) return estToast('Informe um fator maior que zero.', true);
  const novo = de.saldo * fator;
  if (!confirm(`Unificar ${de.codigo} em ${keep.codigo}?\n\nSaldo ${estNum(de.saldo)} ${de.unidade} do antigo vira +${estNum(novo)} ${keep.unidade} no mantido.\nO cadastro ${de.codigo} deixará de aparecer.`)) return;
  try {
    const r = await estPost('estoque_unificar', { de: de.codigo, para: keep.codigo, fator, motivo: 'Cadastro duplicado' });
    estToast(`Unificado. Saldo atual de ${keep.codigo}: ${estNum(r.saldo_final)}.` + (r.aviso ? ' ' + r.aviso : ''));
    EST_DUP.pares = EST_DUP.pares.filter(x => x.a.codigo !== de.codigo && x.b.codigo !== de.codigo); estDupRender();
    if (typeof estLoadItens === 'function') estLoadItens();
  } catch (e) { estToast(e.message, true); }
}
async function estDupIgnorar(i) {
  const p = EST_DUP.pares[i];
  try { await estPost('estoque_duplicado_ignorar', { a: p.a.codigo, b: p.b.codigo }); EST_DUP.pares.splice(i, 1); estDupRender(); }
  catch (e) { estToast(e.message, true); }
}


/* v3.61.0 — completar OS/equipamento de uma saída já assinada (não mexe em saldo nem assinatura; fica registrado quem alterou) */
let EST_COMPL_ID = '';
async function estCompletarSaida(id) {
  EST_COMPL_ID = id;
  estEl('estComplId').textContent = id;
  estEl('estComplOs').value = ''; estEl('estComplEq').value = ''; estEl('estComplMotivo').value = '';
  estEl('estComplModal').classList.add('on');
  estCarregarEquipamentos('estEquipLista2');
  try {
    const r = await estApi('estoque_requisicoes', { query: { q: id } });
    const q = (r.requisicoes || []).find(x => x.id === id);
    if (q) { estEl('estComplOs').value = q.os || ''; estEl('estComplEq').value = q.equipamento || ''; }
  } catch { /* digita livre */ }
  setTimeout(() => estEl('estComplOs').focus(), 60);
}
async function estSalvarComplemento() {
  const os = estEl('estComplOs').value.trim(), eq = estEl('estComplEq').value.trim();
  if (!os && !eq) { estToast('Informe a OS e/ou o equipamento aplicado.', true); return; }
  try {
    await estPost('estoque_saida_complementar', { id: EST_COMPL_ID, os, equipamento: eq, motivo: estEl('estComplMotivo').value.trim() });
    estEl('estComplModal').classList.remove('on');
    estToast('Saída ' + EST_COMPL_ID + ' atualizada.');
    estLoadRequisicoes(); if (typeof estLoadMovimentos === 'function') estLoadMovimentos();
  } catch (e) { estToast(e.message, true); }
}
