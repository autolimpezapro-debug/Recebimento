/* Recebimento Drilling — Importados, visão pública SOMENTE LEITURA (v3.45.1). Sem login: o código do link vai em ?k=. */
'use strict';
const IMP = { itens: [], meses: [], filtro: 'todos', busca: '', atual: null };
const K = new URLSearchParams(location.search).get('k') || '';
const impEl = id => document.getElementById(id);
const impH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const impN = n => Number(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
const impD = s => s ? new Date(s.length <= 10 ? s + 'T12:00:00' : s).toLocaleDateString('pt-BR') : '—';
const IMP_COR = { 'SEM ESTOQUE': 'bad', COMPRAR: 'bad', FABRICAR: 'warn', 'ATENÇÃO': 'warn', OK: 'ok' };
const IMP_MES = m => new Date(m + '-15').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '');
const IMP_TIPO = { SAIDA: 'Saída', ENTRADA_MANUAL: 'Entrada', AJUSTE_POSITIVO: 'Ajuste +', AJUSTE_NEGATIVO: 'Ajuste −', AJUSTE_INVENTARIO: 'Ajuste de saldo', BAIXA_PERDA: 'Perda', INVENTARIO_SAP: 'Inventário SAP', ENTRADA_RECEBIMENTO: 'Recebimento PC' };

async function impApi(a, extra = '') {
  const r = await fetch(`importados_publico_api.php?a=${a}&k=${encodeURIComponent(K)}${extra}`, { cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer' });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
async function iniciar() {
  try {
    const r = await impApi('lista');
    IMP.itens = r.itens; IMP.meses = r.meses;
    impEl('pubInfo').textContent = 'Somente leitura · atualizado em ' + new Date(r.gerado_em).toLocaleString('pt-BR');
    impRender();
  } catch (e) {
    impEl('pubApp').innerHTML = `<div class="pub-erro"><h1>Não foi possível abrir</h1><p class="muted">${impH(e.message)}</p><p class="muted">Peça um link atualizado a quem enviou este endereço.</p></div>`;
  }
}
function impRender() {
  const t = IMP.itens, cont = s => t.filter(i => s.includes(i.status)).length;
  impEl('impKpis').innerHTML = `
    <div class="imp-kpi"><span>Itens controlados</span><strong>${t.length}</strong></div>
    <div class="imp-kpi bad"><span>Sem estoque</span><strong>${cont(['SEM ESTOQUE'])}</strong></div>
    <div class="imp-kpi bad"><span>Comprar (China)</span><strong>${cont(['COMPRAR'])}</strong></div>
    <div class="imp-kpi warn"><span>Fabricar (bases)</span><strong>${cont(['FABRICAR'])}</strong></div>
    <div class="imp-kpi warn"><span>Atenção (prazo)</span><strong>${cont(['ATENÇÃO'])}</strong></div>
    <div class="imp-kpi ok"><span>OK</span><strong>${cont(['OK'])}</strong></div>`;
  const q = IMP.busca.toLowerCase();
  const lista = t.filter(i => (IMP.filtro === 'todos' || (IMP.filtro === 'alerta' ? i.status !== 'OK' : i.status === IMP.filtro)) && (!q || (i.nome + ' ' + i.codigo).toLowerCase().includes(q)));
  impEl('impGrid').innerHTML = lista.map(i => {
    const max = Math.max(i.recomendado, i.saldo, i.disparo, 1), pct = Math.min(100, i.saldo / max * 100), pd = Math.min(100, i.disparo / max * 100);
    const cons = IMP.meses.map(m => i.consumo[m] || 0), cmax = Math.max(...cons, 1);
    return `<article class="imp-card ${IMP_COR[i.status]}" onclick="impAbrir('${impH(i.codigo)}')" tabindex="0" onkeydown="if(event.key==='Enter')impAbrir('${impH(i.codigo)}')">
      <div class="imp-foto">${i.foto ? `<img src="${impH(i.foto)}" alt="${impH(i.nome)}" loading="lazy">` : '<span>📷<br>sem foto</span>'}<span class="imp-badge ${IMP_COR[i.status]}">${impH(i.status)}</span></div>
      <div class="imp-body"><small><code>${impH(i.codigo)}</code>${i.fabricavel ? ' · fabricação interna' : ' · importado China'}</small><h3>${impH(i.nome)}</h3>
        <div class="imp-saldo"><strong>${impN(i.saldo)}</strong><span>${impH(i.unidade)} em estoque</span></div>
        <div class="imp-barra"><i style="width:${pct}%"></i><b style="left:${pd}%"></b></div>
        <div class="imp-legenda"><span>disparo ${impN(i.disparo)}</span><span>recomendado ${impN(i.recomendado)}</span></div>
        <div class="imp-meta"><div><small>Consumo/mês</small><b>${impN(i.media_mensal)}</b></div>
          <div><small>Cobertura</small><b>${i.cobertura_dias === null ? 'sem consumo' : i.cobertura_dias > 999 ? '+999 d' : i.cobertura_dias + ' d'}</b></div>
          <div><small>${i.status === 'OK' ? 'Pedir até' : 'Sugestão'}</small><b>${i.status === 'OK' ? impD(i.pedir_ate) : '+' + impN(i.sugestao)}</b></div></div>
        <div class="imp-spark">${cons.map((v, k) => `<i style="height:${Math.max(3, v / cmax * 34)}px" title="${IMP_MES(IMP.meses[k])}: ${impN(v)}"></i>`).join('')}</div></div></article>`;
  }).join('') || '<div class="empty">Nenhum item neste filtro.</div>';
}
function impFiltro(f) { IMP.filtro = f; document.querySelectorAll('[data-imp-f]').forEach(b => b.classList.toggle('active', b.dataset.impF === f)); impRender(); }
async function impAbrir(cod) {
  try { IMP.atual = (await impApi('item', '&codigo=' + encodeURIComponent(cod))).item; impFicha(); impEl('impModal').classList.add('on'); }
  catch (e) { alert(e.message); }
}
function impFechar() { impEl('impModal').classList.remove('on'); IMP.atual = null; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') impFechar(); });
function impFicha() {
  const i = IMP.atual, cons = IMP.meses.map(m => i.consumo[m] || 0), cmax = Math.max(...cons, 1);
  const par = i.par ? IMP.itens.find(x => x.codigo === i.par) : null;
  const obras = Object.entries(i.obras || {}), omax = Math.max(1, ...obras.map(o => o[1]));
  impEl('impFichaTitulo').textContent = i.nome;
  impEl('impFichaBody').innerHTML = `
  <div class="imp-ficha-top">
    <div class="imp-ficha-foto">${i.foto ? `<img src="${impH(i.foto)}" alt="">` : '<span>📷 sem foto</span>'}</div>
    <div>
      <div class="imp-ficha-saldo"><span class="imp-badge ${IMP_COR[i.status]}">${impH(i.status)}</span><strong>${impN(i.saldo)}</strong> ${impH(i.unidade)}
        <small><code>${impH(i.codigo)}</code>${par ? ` · par: <a href="#" onclick="impAbrir('${impH(par.codigo)}');return false">${impH(par.nome)} (${impN(par.saldo)})</a>` : ''}</small></div>
      <div class="imp-meta big">
        <div><small>Consumo médio (3 meses)</small><b>${impN(i.media_mensal)}/mês</b></div>
        <div><small>Cobertura</small><b>${i.cobertura_dias === null ? 'sem consumo' : i.cobertura_dias + ' dias'}</b></div>
        <div><small>Ruptura prevista</small><b>${impD(i.ruptura_prevista)}</b></div>
        <div><small>${i.fabricavel ? 'Fabricar até' : 'Pedir à China até'}</small><b>${impD(i.pedir_ate)}</b></div>
      </div>
      <div class="imp-meta big" style="margin-top:10px">
        <div><small>Ponto de disparo</small><b>${impN(i.disparo)}</b></div><div><small>Estoque recomendado</small><b>${impN(i.recomendado)}</b></div>
        <div><small>Prazo de reposição</small><b>${i.lead_time_dias} dias</b></div><div><small>Localização</small><b>${impH(i.localizacao || '—')}</b></div>
      </div>
    </div>
  </div>
  <div class="imp-graficos">
    <div><h4>Consumo mensal (saídas)</h4><div class="imp-colunas">${cons.map((v, k) => `<div><i style="height:${Math.max(2, v / cmax * 110)}px"></i><b>${impN(v)}</b><small>${IMP_MES(IMP.meses[k])}</small></div>`).join('')}</div></div>
    <div><h4>Para onde foi (6 meses)</h4>${obras.length ? obras.map(([o, q]) => `<div class="imp-obra"><span>${impH(o)}</span><i style="width:${q / omax * 100}%"></i><b>${impN(q)}</b></div>`).join('') : '<p class="muted">Sem saídas.</p>'}</div>
  </div>
  <h4>Histórico de movimentações (${i.movimentos.length})</h4>
  <div class="est-table-scroll"><table class="orders-table imp-hist"><thead><tr><th>Data</th><th>Tipo</th><th>Qtd</th><th>Saldo</th><th>Obra</th><th>Solicitante</th><th>Observação</th></tr></thead><tbody>
  ${i.movimentos.map(m => { const ent = ['ENTRADA_MANUAL', 'AJUSTE_POSITIVO', 'ENTRADA_RECEBIMENTO'].includes(m.tipo), sai = ['SAIDA', 'AJUSTE_NEGATIVO', 'BAIXA_PERDA'].includes(m.tipo);
    return `<tr class="${m.cancelado ? 'imp-cancelado' : ''}"><td>${impD(m.em)}</td><td>${impH(m.situacao || IMP_TIPO[m.tipo] || m.tipo)}${m.cancelado ? ' <small>(cancelado)</small>' : ''}</td>
    <td class="${ent ? 'pos' : sai ? 'neg' : ''}">${ent ? '+' : sai ? '−' : ''}${impN(m.tipo === 'AJUSTE_INVENTARIO' ? m.saldo_posterior - m.saldo_anterior : m.quantidade)}</td>
    <td>${impN(m.saldo_posterior)}</td><td>${impH(m.obra)}</td><td>${impH(m.solicitante)}</td><td>${m.os ? `<b>OS ${impH(m.os)}</b>${m.equipamento ? ' · ' + impH(m.equipamento) : ''}<br>` : ''}${impH(m.observacao)}</td></tr>`; }).join('')}
  </tbody></table></div>`;
}
iniciar();
