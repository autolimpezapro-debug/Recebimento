/* Rastreios de envios (Terceiros) — v3.64.0. Usa terApi/terPost/terModal/terH de terceiros.js. */
const RTR = { d: null, q: '', sit: 'ABERTOS', tr: '', aba: 'romaneios' };
const rtrPode = op => typeof rdaPermite !== 'function' || rdaPermite('estoque', op);
const rtrData = s => (s ? s.split('-').reverse().join('/') : '—');
const rtrEl = id => document.getElementById(id);

function terAba(aba) {
  RTR.aba = aba;
  document.querySelectorAll('#terTabs [data-aba]').forEach(b => b.classList.toggle('active', b.dataset.aba === aba));
  const rom = aba === 'romaneios';
  ['terKpis', 'terConteudo', 'terBtnNovo'].forEach(i => { const e = rtrEl(i); if (e) e.hidden = !rom; });
  const r = rtrEl('terRastreios'); if (r) r.hidden = rom;
  if (!rom) rtrCarregar();
}
async function rtrCarregar() {
  const c = rtrEl('terRastreios');
  try { RTR.d = await terApi('rastreios_painel'); } catch (e) { c.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  rtrRender();
}
function rtrFiltrados() {
  const q = RTR.q.trim().toLowerCase();
  return RTR.d.rastreios.filter(r =>
    (RTR.sit === 'TODOS' || (RTR.sit === 'ABERTOS' && r.situacao === 'EM_TRANSITO') || (RTR.sit === 'ATRASADOS' && r.atrasado) || (RTR.sit === 'ENTREGUES' && r.situacao === 'ENTREGUE') || (RTR.sit === 'CONFERIR' && r.conferir.length)) &&
    (!RTR.tr || r.transportadora === RTR.tr) &&
    (!q || [r.id, r.fornecedor, r.pedido, r.produto, r.codigo, r.transportadora, r.recebedor, r.romaneio].join(' ').toLowerCase().includes(q)));
}
const RTR_COR = { 'Correios': '#f8bf60', 'Azul Cargo': '#4c8dff', 'Satilog': '#22d3a7', 'Rodonaves': '#f17e89', 'Gontijo': '#a797fc', 'Andorinha Express': '#5ad0e6', 'Gollog': '#ff9f6b' };
const rtrCor = t => RTR_COR[t] || '#92a3bb';
const rtrMes = m => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][+m.slice(5, 7) - 1] + '/' + m.slice(2, 4);
function rtrRender() {
  const L = RTR.d.rastreios, ab = L.filter(r => r.situacao === 'EM_TRANSITO'), at = L.filter(r => r.atrasado), conf = L.filter(r => r.conferir.length);
  const ent = L.filter(r => r.situacao === 'ENTREGUE'), mes = RTR.d.hoje.slice(0, 7), entm = ent.filter(r => (r.data_entrega || '').startsWith(mes));
  const val = ent.filter(r => r.dias != null && r.dias >= 0 && r.dias <= 30), med = val.length ? (val.reduce((a, r) => a + r.dias, 0) / val.length) : null;
  const pont = ent.length ? Math.round(100 * val.filter(r => r.dias <= RTR.d.config.prazo_alerta_dias).length / ent.length) : 0;
  const kpi = (ic, n, t, sub, cls = '') => `<div class="rtr-kpi ${cls}"><i>${ic}</i><b>${n}</b><span>${t}</span><small>${sub}</small></div>`;
  // volume por mês (últimos 6) e por transportadora
  const meses = {}; L.forEach(r => { const m = (r.data_envio || '').slice(0, 7); if (m) meses[m] = (meses[m] || 0) + 1; });
  const ms = Object.keys(meses).sort().slice(-6), mx = Math.max(1, ...ms.map(m => meses[m]));
  const por = {}; L.forEach(r => { const t = r.transportadora || '(sem transportadora)'; const o = por[t] = por[t] || { n: 0, ab: 0, soma: 0, k: 0 }; o.n++; if (r.situacao === 'EM_TRANSITO') o.ab++; else if (r.dias != null && r.dias >= 0 && r.dias <= 30) { o.soma += r.dias; o.k++; } });
  const pt = Object.entries(por).sort((a, b) => b[1].n - a[1].n), mxt = Math.max(1, ...pt.map(x => x[1].n));
  const sit = (v, t, n) => `<button class="${RTR.sit === v ? 'on' : ''}" data-sit="${v}">${t}<em>${n}</em></button>`;
  const trs = RTR.d.transportadoras.map(t => `<option ${RTR.tr === t ? 'selected' : ''}>${terH(t)}</option>`).join('');
  rtrEl('terRastreios').innerHTML = `
  <div class="rtr-hero"><div><div class="rtr-eyebrow">CENTRAL DE RASTREIOS</div><h2>${ab.length ? `${ab.length} envio${ab.length > 1 ? 's' : ''} a caminho` : 'Nenhum envio em trânsito'}</h2><p>${at.length ? `<b class="rtr-bad">${at.length} com atraso</b> · ` : ''}${L.length} envios controlados${med != null ? ` · prazo médio de entrega ${med.toFixed(1).replace('.', ',')} dias` : ''}</p></div>
    <div class="rtr-ring" style="--p:${pont}"><div><b>${pont}%</b><small>entregues em até ${RTR.d.config.prazo_alerta_dias} dias</small></div></div></div>
  <div class="rtr-kpis">${kpi('🚚', ab.length, 'Em trânsito', ab.length ? 'aguardando entrega' : 'tudo entregue', ab.length ? 'warn' : '')}${kpi('⏱', at.length, 'Atrasados', `mais de ${RTR.d.config.prazo_alerta_dias} dias`, at.length ? 'bad' : '')}${kpi('✔', entm.length, 'Entregues no mês', `${ent.length} no total`, 'ok')}${kpi('⌛', med != null ? med.toFixed(1).replace('.', ',') : '—', 'Prazo médio (dias)', 'envio → entrega')}${kpi('⚠', conf.length, 'A conferir', 'dados para revisar', conf.length ? 'warn' : '')}</div>
  <div class="rtr-panels"><section class="rtr-panel"><h3>Envios por mês</h3><div class="rtr-bars">${ms.map(m => `<div><b>${meses[m]}</b><i style="height:${Math.max(6, 100 * meses[m] / mx)}%"></i><small>${rtrMes(m)}</small></div>`).join('')}</div></section>
    <section class="rtr-panel"><h3>Por transportadora <small>envios · prazo médio</small></h3><div class="rtr-carr">${pt.map(([t, o]) => `<div><span><i style="background:${rtrCor(t)}"></i>${terH(t)}</span><div class="rtr-track"><i style="width:${100 * o.n / mxt}%;background:${rtrCor(t)}"></i></div><b>${o.n}</b><small>${o.k ? (o.soma / o.k).toFixed(1).replace('.', ',') + ' d' : '—'}${o.ab ? ` · ${o.ab} aberto${o.ab > 1 ? 's' : ''}` : ''}</small></div>`).join('')}</div><small class="rtr-note">Prazo médio considera entregas de até 30 dias.</small></section></div>
  <div class="rtr-bar"><div class="rtr-seg">${sit('ABERTOS', 'Em trânsito', ab.length)}${sit('ATRASADOS', 'Atrasados', at.length)}${sit('ENTREGUES', 'Entregues', ent.length)}${sit('CONFERIR', 'A conferir', conf.length)}${sit('TODOS', 'Todos', L.length)}</div>
    <div class="rtr-tools"><input id="rtrBusca" type="search" placeholder="🔎  Buscar código, fornecedor, PC, produto…" value="${terH(RTR.q)}"><select id="rtrTr"><option value="">Todas as transportadoras</option>${trs}</select>
    <button class="button ghost" onclick="rtrCsv()">⬇ CSV</button>${rtrPode('criar') ? `<button class="button ghost" onclick="rtrImportar()">📋 Importar</button><button class="button primary" onclick="rtrForm()">＋ Novo rastreio</button>` : ''}${rtrPode('admin') ? `<button class="button ghost" onclick="rtrConfig()" title="Configurar">⚙</button>` : ''}</div></div>
  <div id="rtrLista"></div>`;
  rtrEl('rtrBusca').oninput = e => { RTR.q = e.target.value; rtrLista(); };
  rtrEl('rtrTr').onchange = e => { RTR.tr = e.target.value; rtrLista(); };
  document.querySelectorAll('#terRastreios [data-sit]').forEach(b => b.onclick = () => { RTR.sit = b.dataset.sit; document.querySelectorAll('#terRastreios [data-sit]').forEach(x => x.classList.toggle('on', x === b)); rtrLista(); });
  rtrLista();
}
function rtrLista() {
  const L = rtrFiltrados(), pode = rtrPode('editar'), box = rtrEl('rtrLista');
  if (!L.length) { box.innerHTML = '<div class="rtr-vazio"><b>🚚</b>Nenhum envio neste filtro.</div>'; return; }
  const lim = RTR.d.config.prazo_alerta_dias;
  box.innerHTML = L.map(r => {
    const id = terH(r.id), ent = r.situacao === 'ENTREGUE', cor = rtrCor(r.transportadora);
    const pct = ent ? 100 : Math.min(92, Math.max(8, 100 * (r.dias || 0) / lim * .8));
    const st = ent ? '<span class="rtr-tag ok">Entregue</span>' : (r.atrasado ? '<span class="rtr-tag bad">Atrasado</span>' : '<span class="rtr-tag warn">Em trânsito</span>');
    const lk = r.link_final ? `<a class="button ghost" href="${terH(r.link_final)}" target="_blank" rel="noopener noreferrer">🔎 Rastrear</a>` : '';
    const ac = pode ? (ent ? `<button class="button ghost" onclick="rtrReabrir('${id}')" title="Reabrir">↩</button>` : `<button class="button primary" onclick="rtrEntregar('${id}')">✔ Entregue</button>`) + `<button class="button ghost" onclick="rtrForm('${id}')" title="Editar">✏</button>` : '';
    return `<article class="rtr-card ${r.atrasado ? 'late' : ''} ${ent ? 'done' : ''}" style="--c:${cor}">
      <div class="rtr-main"><div class="rtr-top">${st}<span class="rtr-carrier" style="--c:${cor}">${terH(r.transportadora) || 'sem transportadora'}</span>${r.codigo ? `<code class="rtr-code" title="Copiar" onclick="navigator.clipboard&&navigator.clipboard.writeText('${terH(r.codigo)}');terToast('Código copiado')">${terH(r.codigo)}</code>` : '<span class="rtr-nocode">sem código</span>'}</div>
        <h4>${terH(r.produto) || '—'}</h4><p>${terH(r.fornecedor) || '—'}${r.pedido ? ` · PC ${terH(r.pedido)}` : ''}${r.romaneio ? ` · ${terH(r.romaneio)}` : ''} <small>${id}</small></p></div>
      <div class="rtr-line"><div class="rtr-dates"><span>${rtrData(r.data_envio)}</span><b>${r.dias != null ? r.dias + (r.dias === 1 ? ' dia' : ' dias') : ''}</b><span>${ent ? rtrData(r.data_entrega) : 'a caminho'}</span></div><div class="rtr-prog"><i style="width:${pct}%"></i></div>${ent && r.recebedor ? `<small>Recebido por ${terH(r.recebedor)}</small>` : ''}</div>
      <div class="rtr-ac">${lk}${ac}</div>
      ${r.conferir.length ? `<div class="rtr-warn">⚠ ${r.conferir.map(terH).join(' · ')}</div>` : ''}</article>`;
  }).join('');
}
function rtrCsv() { location.href = 'api.php?action=rastreios_csv'; }
async function rtrAcao(a, b, msg) { try { await terPost(a, b); if (msg) terToast(msg); await rtrCarregar(); } catch (e) { terToast(e.message, true); } }
function rtrEntregar(id) {
  const r = RTR.d.rastreios.find(x => x.id === id); if (!r) return;
  const bg = terModal('Confirmar entrega', `<p>${terH(r.produto || r.codigo || r.id)} — enviado em ${rtrData(r.data_envio)}.</p><label>Data da entrega<input type="date" name="d" value="${RTR.d.hoje}" min="${terH(r.data_envio)}"></label><label>Recebedor<input name="rc" maxlength="80" value="${terH(r.recebedor)}"></label><div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button><button class="button primary" data-ok>Confirmar</button></div>`, 420);
  bg.querySelector('[data-ok]').onclick = async () => { await rtrAcao('rastreios_entregar', { id, data_entrega: terVal(bg, 'd'), recebedor: terVal(bg, 'rc') }, 'Entrega registrada'); bg.remove(); };
}
function rtrReabrir(id) { if (confirm('Reabrir este envio (volta para “em trânsito”)?')) rtrAcao('rastreios_reabrir', { id }, 'Reaberto'); }
function rtrForm(id) {
  const r = id ? RTR.d.rastreios.find(x => x.id === id) : {}; r.finalizado = !!r.finalizado;
  const f = (n, t, v, extra = '') => `<label>${t}<input name="${n}" value="${terH(v || '')}" ${extra}></label>`;
  const dl = `<datalist id="rtrTrs">${RTR.d.transportadoras.map(t => `<option value="${terH(t)}">`).join('')}</datalist>`;
  const bg = terModal(id ? 'Editar rastreio' : 'Novo rastreio', `${dl}<div class="rtr-grid">${f('fornecedor', 'Fornecedor', r.fornecedor)}${f('pedido', 'Nº do pedido (PC)', r.pedido)}${f('produto', 'Produto', r.produto)}${f('transportadora', 'Transportadora', r.transportadora, 'list="rtrTrs"')}${f('codigo', 'Código de rastreio', r.codigo)}${f('link', 'Link de rastreio (opcional)', r.link)}${f('data_envio', 'Data de envio', r.data_envio, 'type="date"')}${f('data_entrega', 'Data de entrega', r.data_entrega, 'type="date"')}${f('recebedor', 'Recebedor', r.recebedor)}${f('romaneio', 'Romaneio (opcional, ex.: ROM-0001)', r.romaneio)}</div>${f('obs', 'Observação', r.obs)}<small>Sem link, o sistema abre o site da transportadora. Com data de entrega o envio fica finalizado.</small><div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button><button class="button primary" data-ok>Salvar</button></div>`, 640);
  bg.querySelector('[data-ok]').onclick = async () => {
    const b = { id: id || '' }; ['fornecedor', 'pedido', 'produto', 'transportadora', 'codigo', 'link', 'data_envio', 'data_entrega', 'recebedor', 'romaneio', 'obs'].forEach(n => b[n] = terVal(bg, n));
    try { const d = await terPost('rastreios_salvar', b); terToast(d.avisos && d.avisos.length ? 'Salvo. Atenção: ' + d.avisos.join('; ') : 'Rastreio salvo'); bg.remove(); await rtrCarregar(); } catch (e) { terToast(e.message, true); }
  };
}
function rtrImportar() {
  const bg = terModal('Importar da planilha', `<p>No Excel, selecione as linhas (com a linha de títulos: FORNECEDOR, Nº DE PEDIDO, PRODUTO, TRANSPORTADORA, CÓDIGO DE RASTREIO, SITE DE RASTREIO, DATA DE ENVIO, DATA DE ENTREGA, FINALIZADO, RECEBEDOR), copie e cole aqui. Nada é gravado antes de você conferir a prévia.</p><textarea name="t" rows="8" style="width:100%" placeholder="Cole aqui…"></textarea><div id="rtrPrev"></div><div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button><button class="button ghost" data-pv>Pré-visualizar</button><button class="button primary" data-ok disabled>Importar</button></div>`, 900);
  const ok = bg.querySelector('[data-ok]'), pv = bg.querySelector('#rtrPrev');
  bg.querySelector('[data-pv]').onclick = async () => {
    ok.disabled = true;
    try {
      const d = await terPost('rastreios_importar_previa', { texto: terVal(bg, 't') });
      pv.innerHTML = `<p><b>${d.novos}</b> novos · ${d.duplicados} já existem (serão ignorados) · ${d.erros} com erro</p><div class="tablewrap" style="max-height:260px;overflow:auto"><table class="table"><thead><tr><th>#</th><th>Envio</th><th>Fornecedor</th><th>Transportadora</th><th>Código</th><th>Entrega</th><th>Situação</th></tr></thead><tbody>${d.linhas.map(l => `<tr><td>${l.n}</td><td>${rtrData(l.reg.data_envio)}</td><td>${terH(l.reg.fornecedor)}</td><td>${terH(l.reg.transportadora)}</td><td>${terH(l.reg.codigo) || '—'}</td><td>${rtrData(l.reg.data_entrega)}</td><td>${l.estado === 'novo' ? 'Novo' : terH(l.motivo)}${l.avisos.length ? ' ⚠ ' + l.avisos.map(terH).join('; ') : ''}</td></tr>`).join('')}</tbody></table></div>`;
      ok.disabled = !d.novos; ok.textContent = `Importar ${d.novos}`;
    } catch (e) { pv.innerHTML = `<p class="rtr-conf">${terH(e.message)}</p>`; }
  };
  ok.onclick = async () => { try { const d = await terPost('rastreios_importar', { texto: terVal(bg, 't') }); terToast(`${d.importados} rastreio(s) importado(s)`); bg.remove(); RTR.sit = 'TODOS'; await rtrCarregar(); } catch (e) { terToast(e.message, true); } };
}
function rtrConfig() {
  const M = RTR.d.modelos;
  const bg = terModal('Configurar rastreios', `<label>Alertar atraso após (dias em trânsito)<input name="dias" type="number" min="1" max="60" value="${RTR.d.config.prazo_alerta_dias}"></label><p><small>Link usado quando o envio não tem link próprio. Use {codigo} onde vai o código (Correios já funciona assim; as demais abrem a página de consulta da transportadora).</small></p>${Object.keys(M).map((t, i) => `<label>${terH(t)}<input data-m="${terH(t)}" value="${terH(M[t])}"></label>`).join('')}<div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button><button class="button primary" data-ok>Salvar</button></div>`, 640);
  bg.querySelector('[data-ok]').onclick = async () => {
    const modelos = {}; bg.querySelectorAll('[data-m]').forEach(i => modelos[i.dataset.m] = i.value);
    try { await terPost('rastreios_config', { prazo_alerta_dias: +terVal(bg, 'dias'), modelos }); terToast('Configuração salva'); bg.remove(); await rtrCarregar(); } catch (e) { terToast(e.message, true); }
  };
}
