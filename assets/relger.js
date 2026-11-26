/* Recebimento Drilling — Relatório gerencial (v3.53.0): o que saiu do estoque, com a OS. Reaproveita helpers de terceiros.js. */
'use strict';
const RG = { d: null, aba: 'saidas', q: '', so_sem_os: false };
const rgMoney = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const rgMes = (off = 0) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + off); const a = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'); return [`${a}-${m}-01`, `${a}-${m}-${String(new Date(a, d.getMonth() + 1, 0).getDate()).padStart(2, '0')}`]; };

async function relgerInit() {
  const c = terEl('rgConteudo'); if (!c) return;
  if (!terEl('rgIni').value) { const [i, f] = rgMes(0); terEl('rgIni').value = i; terEl('rgFim').value = f; }
  relgerEnvioCarregar();
  await relgerCarregar();
}
function relgerPeriodo(tipo) {
  const [i, f] = tipo === 'mes' ? rgMes(0) : tipo === 'ant' ? rgMes(-1) : [`${new Date().getFullYear()}-01-01`, terHoje()];
  terEl('rgIni').value = i; terEl('rgFim').value = f; relgerCarregar();
}
async function relgerCarregar() {
  const c = terEl('rgConteudo'); c.innerHTML = '<div class="empty">Carregando…</div>';
  try { RG.d = await terApi(`relger_dados&ini=${terEl('rgIni').value}&fim=${terEl('rgFim').value}${relgerSoOs()}`); } catch (e) { c.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  relgerRender();
}
const relgerSoOs = () => (terEl('rgSoOs') && terEl('rgSoOs').checked ? '&so_os=1' : '');
function relgerCsv() { location.href = `api.php?action=relger_csv&ini=${terEl('rgIni').value}&fim=${terEl('rgFim').value}${relgerSoOs()}`; }
function rgQtd(n) { return terQtd(n); }
function relgerRender() {
  const r = RG.d.resumo, box = (n, t, cls = '') => `<div class="imp-kpi ${cls}"><b>${n}</b><span>${t}</span></div>`;
  terEl('rgKpis').innerHTML = box(r.saidas_mov, 'saídas de material') + box(r.os_distintas, 'OS atendidas') + box(rgMoney(r.saidas_valor), 'valor saído (custo)') + box(r.saidas_sem_os, 'saídas sem OS', r.saidas_sem_os ? 'warn' : '') + box(r.pcs_recebidos, 'PCs recebidos') + box(r.saidas_sem_custo, 'saídas sem custo', r.saidas_sem_custo ? 'warn' : '');
  const abas = [['saidas', 'Materiais que saíram'], ['os', 'Por OS'], ['obra', 'Por obra'], ['setor', 'Por setor'], ['mat', 'Top materiais'], ['entradas', 'Entradas (recebidos)']];
  terEl('rgConteudo').innerHTML = `<div class="imp-filtros">${abas.map(([k, t]) => `<button class="est-tab ${RG.aba === k ? 'active' : ''}" onclick="RG.aba='${k}';relgerRender()">${t}</button>`).join('')}</div>
    <p class="muted" style="margin:6px 0">${terH(RG.d.nota)}</p><div id="rgAba"></div>`;
  const f = { saidas: rgSaidas, os: () => rgGrupo(RG.d.por_os, 'OS', true), obra: () => rgGrupo(RG.d.por_obra, 'Obra'), setor: () => rgGrupo(RG.d.por_setor, 'Setor'), mat: rgMat, entradas: rgEntradas }[RG.aba];
  terEl('rgAba').innerHTML = f();
  const q = terEl('rgBusca'); if (q) q.oninput = e => { RG.q = e.target.value; terEl('rgRows').innerHTML = rgLinhas(); };
  const so = terEl('rgSemOs'); if (so) so.onchange = e => { RG.so_sem_os = e.target.checked; terEl('rgRows').innerHTML = rgLinhas(); };
}
function rgLinhas() {
  const q = RG.q.trim().toLowerCase();
  const l = RG.d.saidas.filter(s => (!RG.so_sem_os || !s.os) && (!q || [s.descricao, s.sku, s.sku_pc, s.os, s.obra, s.setor, s.equipamento, s.aplicacao, s.solicitante, s.recebedor, s.documento, s.pc].join(' ').toLowerCase().includes(q)));
  return `<p class="muted">${l.length} de ${RG.d.saidas.length} saídas</p><div class="prt-scroll"><table class="orders-table"><thead><tr><th>Data</th><th>PC</th><th>SKU do PC</th><th>Material</th><th>Qtd.</th><th>Aplicação</th><th>Setor</th><th>OS</th><th>Valor unit.</th><th>Valor total</th><th>Recebedor</th></tr></thead><tbody>
    ${l.map(s => `<tr><td>${terD(s.dia)} <small class="muted">${terH(String(s.em || '').slice(11, 16))}</small></td><td>${s.pc || '—'}</td><td>${terH(s.sku_pc || s.sku) || '—'}</td><td>${terH(s.descricao)}${s.tipo === 'ESTORNO_SAIDA' ? ' <small>(estorno)</small>' : ''}</td><td><b>${rgQtd(s.quantidade)}</b> ${terH(s.unidade)}</td><td>${terH(s.aplicacao || s.equipamento) || '—'}</td><td>${terH(s.setor || s.obra) || '—'}</td><td>${s.os ? '<b>' + terH(s.os) + '</b>' : '<span class="prt-badge warn" style="white-space:nowrap">sem OS</span>'}</td><td title="${terH(s.valor_fonte)}">${s.custo_unit ? rgMoney(s.custo_unit) : '<span class="muted">sem custo</span>'}</td><td title="${terH(s.valor_fonte)}">${s.valor ? rgMoney(s.valor) : '<span class="muted">—</span>'}</td><td>${terH(s.recebedor || s.solicitante)}</td></tr>`).join('') || '<tr><td colspan="11" class="empty">Nenhuma saída no período.</td></tr>'}</tbody></table></div>`;
}
function rgSaidas() {
  return `<div class="filterrow"><label class="searchbox"><span>🔎</span><input id="rgBusca" type="search" placeholder="Buscar material, SKU, OS, obra, solicitante…" value="${terH(RG.q)}"></label><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="rgSemOs" ${RG.so_sem_os ? 'checked' : ''}> só sem OS</label></div><div id="rgRows">${rgLinhas()}</div>`;
}
function rgGrupo(g, rot, destacarOs) {
  return `<div class="prt-scroll"><table class="orders-table"><thead><tr><th>${rot}</th><th>Movim.</th><th>Valor</th><th>Última</th><th>Materiais que saíram</th></tr></thead><tbody>${g.map(x => `<tr><td><b>${terH(x.chave)}</b>${destacarOs && x.chave === '(sem OS)' ? ' <span class="prt-badge warn">atenção</span>' : ''}${x.equipamentos.length ? '<br><small class="muted">' + terH(x.equipamentos.join(', ')) + '</small>' : ''}${x.obras.length ? '<br><small class="muted">' + terH(x.obras.join(', ')) + '</small>' : ''}</td><td>${x.movimentos}</td><td>${rgMoney(x.valor)}</td><td>${terD(x.ultima)}</td>
    <td><details><summary>${x.materiais.length} material(is)</summary><ul style="margin:4px 0;padding-left:16px">${x.materiais.map(m => `<li>${terH(m.sku ? m.sku + ' · ' : '')}${terH(m.descricao)} — <b>${rgQtd(m.quantidade)} ${terH(m.unidade)}</b></li>`).join('')}</ul></details></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem dados no período.</td></tr>'}</tbody></table></div>`;
}
function rgMat() {
  return `<div class="prt-scroll"><table class="orders-table"><thead><tr><th>SKU</th><th>Material</th><th>Un.</th><th>Qtd. saída</th><th>Movim.</th><th>Valor</th></tr></thead><tbody>${RG.d.top_materiais.map(x => { const m = x.materiais[0] || {}; return `<tr><td>${terH(m.sku) || '—'}</td><td><b>${terH(m.descricao)}</b></td><td>${terH(m.unidade)}</td><td>${rgQtd(m.quantidade)}</td><td>${x.movimentos}</td><td>${rgMoney(x.valor)}</td></tr>`; }).join('') || '<tr><td colspan="6" class="empty">Sem dados.</td></tr>'}</tbody></table></div>`;
}
function rgEntradas() {
  return `<p class="muted">${RG.d.resumo.pcs_recebidos} pedido(s) de ${RG.d.resumo.fornecedores_recebidos} fornecedor(es) no período.</p><div class="prt-scroll"><table class="orders-table"><thead><tr><th>Data</th><th>PC</th><th>Fornecedor</th><th>NF</th><th>SKU</th><th>Material</th><th>Qtd.</th></tr></thead><tbody>${RG.d.entradas.map(e => `<tr><td>${terD(e.dia)}</td><td>${e.pc || ''}</td><td>${terH(e.fornecedor)}</td><td>${terH(e.nf)}</td><td>${terH(e.sku) || '—'}</td><td>${terH(e.descricao)}</td><td>${rgQtd(e.quantidade)} ${terH(e.unidade)}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Sem entradas.</td></tr>'}</tbody></table></div>`;
}
function relgerImprimir() {
  const d = RG.d, h = terH, w = window.open('', '_blank'); if (!w) return terToast('Libere pop-ups para imprimir.', true);
  const tab = (t, cab, linhas) => `<h2>${t}</h2><table><thead><tr>${cab.map(c => `<th>${c}</th>`).join('')}</tr></thead><tbody>${linhas.join('')}</tbody></table>`;
  w.document.write(`<!doctype html><meta charset="utf-8"><title>Relatório gerencial</title><style>body{font:11px Arial;margin:14px;color:#111}h1{font-size:16px}h2{font-size:13px;margin:16px 0 4px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}.k{display:flex;gap:14px;flex-wrap:wrap;margin:8px 0}.k div{border:1px solid #999;padding:4px 8px}@media print{h2{page-break-after:avoid}tr{page-break-inside:avoid}}</style>
  <h1>Drilling do Brasil — Relatório gerencial</h1><p>Período: ${terD(d.periodo.ini)} a ${terD(d.periodo.fim)} · gerado em ${new Date().toLocaleString('pt-BR')}</p>
  <div class="k"><div><b>${d.resumo.saidas_mov}</b> saídas</div><div><b>${d.resumo.os_distintas}</b> OS</div><div><b>${rgMoney(d.resumo.saidas_valor)}</b> valor saído</div><div><b>${d.resumo.saidas_sem_os}</b> sem OS</div><div><b>${d.resumo.pcs_recebidos}</b> PCs recebidos</div></div>
  ${tab('Materiais que saíram (com OS)', ['Data', 'OS', 'SKU', 'Material', 'Un.', 'Qtd.', 'Obra / setor', 'Solicitante', 'PC', 'Valor'], d.saidas.map(s => `<tr><td>${terD(s.dia)}</td><td>${h(s.os || '(sem OS)')}</td><td>${h(s.sku)}</td><td>${h(s.descricao)}</td><td>${h(s.unidade)}</td><td>${rgQtd(s.quantidade)}</td><td>${h([s.obra, s.setor].filter(Boolean).join(' / '))}</td><td>${h(s.recebedor || s.solicitante)}</td><td>${s.pc || ''}</td><td>${s.valor ? rgMoney(s.valor) : ''}</td></tr>`))}
  ${tab('Resumo por OS', ['OS', 'Movim.', 'Valor', 'Materiais'], d.por_os.map(x => `<tr><td>${h(x.chave)}</td><td>${x.movimentos}</td><td>${rgMoney(x.valor)}</td><td>${h(x.materiais.map(m => m.descricao + ' (' + rgQtd(m.quantidade) + ' ' + m.unidade + ')').join('; '))}</td></tr>`))}
  <script>setTimeout(()=>print(),300)<\/script>`);
  w.document.close();
}

/* v3.58.0 — envio diário das saídas por e-mail (só perfis com permissão de administrar Estoque veem a seção) */
async function relgerEnvioCarregar() {
  const d = terEl('rgEnvio'); if (!d) return;
  try {
    const r = await terApi('relger_envio_config'), c = r.config;
    terEl('rgEnvAtivo').checked = !!c.ativo; terEl('rgEnvHora').value = c.hora; terEl('rgEnvPeriodo').value = c.periodo; terEl('rgEnvVazio').checked = !!c.enviar_vazio; terEl('rgEnvSoOs').checked = !!c.so_com_os; terEl('rgEnvEmails').value = (c.emails || []).join(', ');
    const u = r.ultimo; terEl('rgEnvioRes').textContent = c.ativo ? '· ligado às ' + c.hora + (u ? (u.ok ? ' · último: ' + terD(u.dia) + (u.obs ? ' (sem saídas)' : '') : ' · ⚠ falha no último envio') : '') : '· desligado';
    terEl('rgEnvInfo').dataset.erro = u && !u.ok ? u.erro : '';
    if (u && !u.ok) terEl('rgEnvInfo').textContent = '⚠ Último envio falhou: ' + u.erro + ' — confira o SMTP em Alertas de estoque.';
    d.hidden = false;
  } catch (e) { d.hidden = true; }
}
async function relgerEnvioSalvar() {
  try {
    await terPost('relger_envio_salvar', { ativo: terEl('rgEnvAtivo').checked, hora: terEl('rgEnvHora').value, periodo: terEl('rgEnvPeriodo').value, enviar_vazio: terEl('rgEnvVazio').checked, so_com_os: terEl('rgEnvSoOs').checked, emails: terEl('rgEnvEmails').value });
    terToast('Envio diário salvo.'); relgerEnvioCarregar();
  } catch (e) { terToast(e.message, true); }
}
async function relgerEnvioTestar() {
  try { await relgerEnvioSalvar(); const r = await terPost('relger_envio_testar', {}); terToast(`Teste enviado para ${r.enviado_para.length} e-mail(s) (${r.saidas} saída(s) de hoje).`); }
  catch (e) { terToast(e.message, true); }
}
