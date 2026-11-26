/* Rastreios de envios (Terceiros) — v3.64.0. Usa terApi/terPost/terModal/terH de terceiros.js. */
const RTR = { d: null, q: '', sit: 'ABERTOS', tr: '', ord: 'recentes', aba: 'romaneios' };
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
  const o = { recentes: (a, b) => (b.data_envio || '').localeCompare(a.data_envio || ''), antigos: (a, b) => (a.data_envio || '').localeCompare(b.data_envio || ''), prazo: (a, b) => (b.dias ?? -1) - (a.dias ?? -1) }[RTR.ord];
  return RTR.d.rastreios.slice().sort(o).filter(r =>
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
  const pont = ent.length ? Math.round(100 * ent.filter(r => r.no_prazo).length / ent.length) : 0;
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
    <div class="rtr-ring" style="--p:${pont}"><div><b>${pont}%</b><small>entregues no prazo</small></div></div></div>
  ${rtrPode('criar') ? `<div class="rtr-quick"><span>⚡</span><input id="rtrQuick" placeholder="Novo envio: cole ou digite o código de rastreio e tecle Enter…" autocomplete="off"><button class="button primary" id="rtrQuickBtn">Adicionar</button><kbd title="Atalho de teclado">N</kbd></div>` : ''}
  ${rtrAtencao(L)}
  <div class="rtr-kpis">${kpi('🚚', ab.length, 'Em trânsito', ab.length ? 'aguardando entrega' : 'tudo entregue', ab.length ? 'warn' : '')}${kpi('⏱', at.length, 'Atrasados', `mais de ${RTR.d.config.prazo_alerta_dias} dias`, at.length ? 'bad' : '')}${kpi('✔', entm.length, 'Entregues no mês', `${ent.length} no total`, 'ok')}${kpi('⌛', med != null ? med.toFixed(1).replace('.', ',') : '—', 'Prazo médio (dias)', 'envio → entrega')}${kpi('⚠', conf.length, 'A conferir', 'dados para revisar', conf.length ? 'warn' : '')}</div>
  <div class="rtr-panels"><section class="rtr-panel"><h3>Envios por mês</h3><div class="rtr-bars">${ms.map(m => `<div><b>${meses[m]}</b><i style="height:${Math.max(6, 100 * meses[m] / mx)}%"></i><small>${rtrMes(m)}</small></div>`).join('')}</div></section>
    <section class="rtr-panel"><h3>Por transportadora <small>envios · prazo médio</small></h3><div class="rtr-carr">${pt.map(([t, o]) => `<div><span><i style="background:${rtrCor(t)}"></i>${terH(t)}</span><div class="rtr-track"><i style="width:${100 * o.n / mxt}%;background:${rtrCor(t)}"></i></div><b>${o.n}</b><small>${o.k ? (o.soma / o.k).toFixed(1).replace('.', ',') + ' d' : '—'}${o.ab ? ` · ${o.ab} aberto${o.ab > 1 ? 's' : ''}` : ''}</small></div>`).join('')}</div><small class="rtr-note">Prazo médio considera entregas de até 30 dias.</small></section></div>
  <div class="rtr-bar"><div class="rtr-seg">${sit('ABERTOS', 'Em trânsito', ab.length)}${sit('ATRASADOS', 'Atrasados', at.length)}${sit('ENTREGUES', 'Entregues', ent.length)}${sit('CONFERIR', 'A conferir', conf.length)}${sit('TODOS', 'Todos', L.length)}</div>
    <div class="rtr-tools"><input id="rtrBusca" type="search" placeholder="🔎  Buscar código, fornecedor, PC, produto…" value="${terH(RTR.q)}"><select id="rtrTr"><option value="">Todas as transportadoras</option>${trs}</select><select id="rtrOrd" title="Ordenar"><option value="recentes" ${RTR.ord === 'recentes' ? 'selected' : ''}>Mais recentes</option><option value="antigos" ${RTR.ord === 'antigos' ? 'selected' : ''}>Mais antigos</option><option value="prazo" ${RTR.ord === 'prazo' ? 'selected' : ''}>Maior prazo</option></select>
    <button class="button ghost" onclick="rtrRelatorio()">📄 Relatório</button><button class="button ghost" onclick="rtrCsv()">⬇ CSV</button>${rtrPode('criar') ? `<button class="button ghost" onclick="rtrImportar()">📋 Importar</button><button class="button primary" onclick="rtrForm()">＋ Novo rastreio</button>` : ''}${rtrPode('admin') ? `<button class="button ghost" onclick="rtrConfig()" title="Configurar">⚙</button>` : ''}</div></div>
  <div id="rtrLista"></div>`;
  rtrEl('rtrBusca').oninput = e => { RTR.q = e.target.value; rtrLista(); };
  rtrEl('rtrTr').onchange = e => { RTR.tr = e.target.value; rtrLista(); };
  rtrEl('rtrOrd').onchange = e => { RTR.ord = e.target.value; rtrLista(); };
  const qk = rtrEl('rtrQuick'); if (qk) { const go = () => { const v = qk.value.trim(); if (v) { qk.value = ''; rtrForm(null, { codigo: v }); } else rtrForm(); }; qk.onkeydown = e => { if (e.key === 'Enter') go(); }; rtrEl('rtrQuickBtn').onclick = go; }
  document.querySelectorAll('#terRastreios [data-foco]').forEach(b => b.onclick = () => { RTR.sit = b.dataset.foco; rtrRender(); });
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
    const ac = pode ? (ent ? `<button class="button ghost" onclick="rtrReabrir('${id}')" title="Reabrir">↩</button>` : `<button class="button primary" onclick="rtrEntregar('${id}')">✔ Entregue</button>`) + `<button class="button ghost" onclick="rtrForm('${id}')" title="Editar">✏</button>` + (rtrPode('criar') ? `<button class="button ghost" onclick="rtrDup('${id}')" title="Duplicar: novo envio com os mesmos dados">⧉ Duplicar</button>` : '') : '';
    return `<article class="rtr-card ${r.atrasado ? 'late' : ''} ${ent ? 'done' : ''}" style="--c:${cor}">
      <div class="rtr-main"><div class="rtr-top">${st}<span class="rtr-carrier" style="--c:${cor}">${terH(r.transportadora) || 'sem transportadora'}</span>${r.codigo ? `<code class="rtr-code" title="Copiar" onclick="navigator.clipboard&&navigator.clipboard.writeText('${terH(r.codigo)}');terToast('Código copiado')">${terH(r.codigo)}</code>` : '<span class="rtr-nocode">sem código</span>'}</div>
        <h4 class="rtr-tit" onclick="rtrDetalhe('${id}')" title="Ver detalhes">${terH(r.produto) || '—'}</h4><p>${terH(r.fornecedor) || '—'}${r.pedido ? ` · PC ${terH(r.pedido)}` : ''}${r.romaneio ? ` · ${terH(r.romaneio)}` : ''} <small>${id}</small></p></div>
      <div class="rtr-line"><div class="rtr-dates"><span>${rtrData(r.data_envio)}</span><b>${r.dias != null ? r.dias + (r.dias === 1 ? ' dia' : ' dias') : ''}</b><span>${ent ? rtrData(r.data_entrega) : 'a caminho'}</span></div>${r.data_previsao ? `<div class="rtr-prev ${r.atrasado || (ent && !r.no_prazo) ? 'late' : ''}">📅 Previsão ${rtrData(r.data_previsao)}${r.atrasado ? ` · ${r.dias_atraso} dia(s) de atraso` : ent ? (r.no_prazo ? ' · entregue no prazo' : ` · entregue com ${r.dias_atraso} dia(s) de atraso`) : ''}</div>` : ''}<div class="rtr-prog"><i style="width:${pct}%"></i></div>${ent && r.recebedor ? `<small>Recebido por ${terH(r.recebedor)}</small>` : ''}</div>
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
const rtrUnicos = k => [...new Set(RTR.d.rastreios.map(r => (r[k] || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
const rtrDetecta = c => { c = (c || '').trim().toUpperCase(); return /^[A-Z]{2}\d{9}[A-Z]{2}$/.test(c) ? 'Correios' : ''; };
function rtrAtencao(L) {
  const lim = RTR.d.config.prazo_alerta_dias, it = [];
  const at = L.filter(r => r.atrasado).length, quase = L.filter(r => r.situacao === 'EM_TRANSITO' && !r.atrasado && r.dias != null && r.dias >= lim - 2).length;
  const sem = L.filter(r => r.situacao === 'EM_TRANSITO' && !r.codigo).length, conf = L.filter(r => r.conferir.length).length;
  if (at) it.push(`<button data-foco="ATRASADOS" class="bad">⏱ ${at} atrasado${at > 1 ? 's' : ''} — cobrar a transportadora</button>`);
  if (quase) it.push(`<button data-foco="ABERTOS" class="warn">⏳ ${quase} perto do prazo</button>`);
  if (sem) it.push(`<button data-foco="ABERTOS" class="warn">🏷 ${sem} em trânsito sem código</button>`);
  if (conf) it.push(`<button data-foco="CONFERIR" class="info">⚠ ${conf} com dados a conferir</button>`);
  return it.length ? `<div class="rtr-atencao"><b>Precisa de atenção</b>${it.join('')}</div>` : `<div class="rtr-atencao ok"><b>✔ Tudo em dia</b><span>Nenhum envio atrasado ou com pendência.</span></div>`;
}
function rtrDup(id) { const r = RTR.d.rastreios.find(x => x.id === id); if (r) rtrForm(null, { fornecedor: r.fornecedor, pedido: r.pedido, produto: r.produto, transportadora: r.transportadora, romaneio: r.romaneio }); }
function rtrForm(id, pre) {
  const r = id ? Object.assign({}, RTR.d.rastreios.find(x => x.id === id)) : Object.assign({ data_envio: RTR.d.hoje }, pre || {});
  const dl = (n, k) => `<datalist id="${n}">${rtrUnicos(k).map(t => `<option value="${terH(t)}">`).join('')}</datalist>`;
  const f = (n, t, v, extra = '', cls = '') => `<label class="${cls}">${t}<input name="${n}" value="${terH(v || '')}" ${extra}></label>`;
  const chips = RTR.d.transportadoras.map(t => `<button type="button" class="rtr-chip" data-tr="${terH(t)}" style="--c:${rtrCor(t)}">${terH(t)}</button>`).join('');
  const bg = terModal(id ? `Editar ${terH(id)}` : 'Novo rastreio', `${dl('rtrLF', 'fornecedor')}${dl('rtrLP', 'produto')}${dl('rtrLR', 'recebedor')}${dl('rtrTrs', 'transportadora')}
  <div class="rtr-f">
    <section><h5>1 · Envio</h5><div class="rtr-grid">${f('fornecedor', 'Fornecedor / origem', r.fornecedor, 'list="rtrLF" autocomplete="off"')}${f('pedido', 'Nº do pedido (PC)', r.pedido)}${f('produto', 'O que foi enviado', r.produto, 'list="rtrLP" autocomplete="off"', 'span2')}</div></section>
    <section><h5>2 · Rastreio</h5><label>Transportadora<div class="rtr-chips">${chips}</div><input name="transportadora" value="${terH(r.transportadora || '')}" list="rtrTrs" placeholder="ou digite outra"></label>
      <div class="rtr-grid">${f('codigo', 'Código de rastreio', r.codigo, 'autocomplete="off" autofocus')}${f('link', 'Link próprio (opcional)', r.link)}</div><div class="rtr-lk" id="rtrLk"></div></section>
    <section><h5>3 · Datas e entrega</h5><div class="rtr-grid">${f('data_envio', 'Data de envio', r.data_envio, 'type="date"')}${f('data_previsao', 'Previsão de entrega', r.data_previsao, 'type="date"')}${f('data_entrega', 'Data de entrega', r.data_entrega, 'type="date"')}${f('recebedor', 'Recebedor', r.recebedor, 'list="rtrLR" autocomplete="off"')}${f('romaneio', 'Romaneio (opcional)', r.romaneio, 'placeholder="ROM-0001"')}</div>${f('obs', 'Observação', r.obs, '', 'full')}<small>Preencher a data de entrega finaliza o envio.</small></section>
  </div><div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button>${id ? '' : '<button class="button ghost" data-ok2>Salvar e adicionar outro</button>'}<button class="button primary" data-ok>Salvar</button></div>`, 720);
  const v = n => terVal(bg, n), set = (n, x) => { bg.querySelector(`[name="${n}"]`).value = x; };
  const pinta = () => { bg.querySelectorAll('[data-tr]').forEach(b => b.classList.toggle('on', b.dataset.tr === v('transportadora'))); const t = v('transportadora'), c = v('codigo').trim(), lk = v('link').trim(); const mod = RTR.d.modelos[t] || ''; const url = lk || (mod ? mod.replace('{codigo}', encodeURIComponent(c)) : ''); bg.querySelector('#rtrLk').innerHTML = url ? `🔗 O botão Rastrear abrirá: <a href="${terH(url)}" target="_blank" rel="noopener noreferrer">${terH(url.length > 70 ? url.slice(0, 70) + '…' : url)}</a>` : (t ? 'Sem modelo de link para esta transportadora — cole um link próprio ou configure em ⚙.' : ''); };
  bg.querySelectorAll('[data-tr]').forEach(b => b.onclick = () => { set('transportadora', b.dataset.tr); pinta(); });
  bg.querySelector('[name="codigo"]').oninput = () => { const d = rtrDetecta(v('codigo')); if (d && !v('transportadora')) set('transportadora', d); pinta(); };
  ['transportadora', 'link'].forEach(n => bg.querySelector(`[name="${n}"]`).oninput = pinta);
  // lembra do último fornecedor→produto/transportadora usados
  bg.querySelector('[name="fornecedor"]').onchange = () => { if (id || v('transportadora')) return; const h = RTR.d.rastreios.find(x => x.fornecedor === v('fornecedor') && x.transportadora); if (h) { set('transportadora', h.transportadora); pinta(); } };
  pinta(); if (pre && pre.codigo) { set('codigo', pre.codigo); bg.querySelector('[name="codigo"]').oninput(); } setTimeout(() => bg.querySelector(!id && !(pre && pre.codigo) ? '[name="fornecedor"]' : '[name="codigo"]').focus(), 50);
  const salvar = async (outro) => {
    const b = { id: id || '' }; ['fornecedor', 'pedido', 'produto', 'transportadora', 'codigo', 'link', 'data_envio', 'data_previsao', 'data_entrega', 'recebedor', 'romaneio', 'obs'].forEach(n => b[n] = v(n));
    try { const d = await terPost('rastreios_salvar', b); terToast(d.avisos && d.avisos.length ? 'Salvo. Atenção: ' + d.avisos.join('; ') : '✔ Rastreio salvo'); bg.remove(); RTR.sit = 'ABERTOS'; await rtrCarregar(); if (outro) rtrForm(null, { fornecedor: b.fornecedor, transportadora: b.transportadora, pedido: '' }); } catch (e) { terToast(e.message, true); }
  };
  bg.querySelector('[data-ok]').onclick = () => salvar(false);
  const o2 = bg.querySelector('[data-ok2]'); if (o2) o2.onclick = () => salvar(true);
}
function rtrDetalhe(id) {
  const r = RTR.d.rastreios.find(x => x.id === id); if (!r) return; const ent = r.situacao === 'ENTREGUE';
  const pas = (ok, t, d, sub) => `<li class="${ok ? 'ok' : ''}"><i></i><b>${t}</b><span>${d}</span>${sub ? `<small>${sub}</small>` : ''}</li>`;
  const bg = terModal(terH(r.produto || r.id), `<p class="rtr-dp">${terH(r.fornecedor)}${r.pedido ? ' · PC ' + terH(r.pedido) : ''} · <small>${terH(r.id)}</small></p>
   <ul class="rtr-tl">${pas(true, 'Enviado', rtrData(r.data_envio), terH(r.transportadora) + (r.codigo ? ' · ' + terH(r.codigo) : ''))}${r.data_previsao ? pas(false, 'Previsão de entrega', rtrData(r.data_previsao), r.atrasado ? `⚠ ${r.dias_atraso} dia(s) de atraso` : ent ? (r.no_prazo ? 'entregue no prazo' : `entregue com ${r.dias_atraso} dia(s) de atraso`) : '') : ''}${pas(!ent, 'Em trânsito', ent ? 'concluído' : (r.dias != null ? r.dias + ' dia(s)' : ''), r.atrasado ? '⚠ acima do prazo' : '')}${pas(ent, 'Entregue', ent ? rtrData(r.data_entrega) : 'aguardando', ent && r.recebedor ? 'Recebido por ' + terH(r.recebedor) : '')}</ul>
   ${r.obs ? `<p class="rtr-dp">📝 ${terH(r.obs)}</p>` : ''}${r.conferir.length ? `<div class="rtr-warn">⚠ ${r.conferir.map(terH).join(' · ')}</div>` : ''}
   <div class="rd-ass-act">${r.link_final ? `<a class="button ghost" href="${terH(r.link_final)}" target="_blank" rel="noopener noreferrer">🔎 Rastrear</a>` : ''}${rtrPode('editar') ? `<button class="button ghost" data-ed>✏ Editar</button>` : ''}<button class="button primary" data-x2>Fechar</button></div>`, 520);
  const e = bg.querySelector('[data-ed]'); if (e) e.onclick = () => { bg.remove(); rtrForm(id); };
}
document.addEventListener('keydown', e => { if (RTR.aba === 'rastreios' && e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.altKey && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '') && !document.querySelector('.rd-ass-bg') && rtrPode('criar')) { e.preventDefault(); const q = rtrEl('rtrQuick'); if (q) q.focus(); } });
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

/* ---------- Relatório (impressão / PDF) ---------- */
function rtrRelatorio() {
  const ini = RTR.d.hoje.slice(0, 8) + '01', trs = RTR.d.transportadoras.map(t => `<option>${terH(t)}</option>`).join('');
  const bg = terModal('Relatório de rastreios', `<p><small>Gera uma página pronta para imprimir ou salvar em PDF (no diálogo de impressão, escolha “Salvar como PDF”).</small></p>
  <div class="rtr-grid"><label>Enviados de<input type="date" name="i" value="${ini}"></label><label>até<input type="date" name="f" value="${RTR.d.hoje}"></label>
  <label>Transportadora<select name="t"><option value="">Todas</option>${trs}</select></label>
  <label>Situação<select name="s"><option value="">Todas</option><option value="EM_TRANSITO">Em trânsito</option><option value="ENTREGUE">Entregues</option><option value="ATRASADOS">Atrasados / fora do prazo</option></select></label></div>
  <div class="rd-ass-act"><button class="button ghost" data-x2>Cancelar</button><button class="button ghost" data-todo>Período todo</button><button class="button primary" data-ok>📄 Gerar relatório</button></div>`, 560);
  bg.querySelector('[data-todo]').onclick = () => { bg.querySelector('[name=i]').value = ''; bg.querySelector('[name=f]').value = ''; };
  bg.querySelector('[data-ok]').onclick = () => { rtrRelGerar({ i: terVal(bg, 'i'), f: terVal(bg, 'f'), t: terVal(bg, 't'), s: terVal(bg, 's') }); bg.remove(); };
}
function rtrRelGerar(F) {
  const L = RTR.d.rastreios.filter(r => (!F.i || r.data_envio >= F.i) && (!F.f || r.data_envio <= F.f) && (!F.t || r.transportadora === F.t) &&
    (!F.s || (F.s === 'ATRASADOS' ? (r.atrasado || (r.situacao === 'ENTREGUE' && r.no_prazo === false)) : r.situacao === F.s))).sort((a, b) => (a.data_envio || '').localeCompare(b.data_envio || ''));
  if (!L.length) { terToast('Nenhum envio neste filtro.', true); return; }
  const E = L.filter(r => r.situacao === 'ENTREGUE'), A = L.filter(r => r.situacao !== 'ENTREGUE'), V = E.filter(r => r.dias != null && r.dias >= 0 && r.dias <= 30);
  const med = V.length ? (V.reduce((a, r) => a + r.dias, 0) / V.length).toFixed(1).replace('.', ',') : '—', pz = E.filter(r => r.no_prazo != null), np = pz.length ? Math.round(100 * pz.filter(r => r.no_prazo).length / pz.length) : null;
  const por = {}; L.forEach(r => { const t = r.transportadora || '(sem transportadora)', o = por[t] = por[t] || { n: 0, e: 0, a: 0, s: 0, k: 0, p: 0, np: 0 }; o.n++; if (r.situacao === 'ENTREGUE') { o.e++; if (r.dias != null && r.dias >= 0 && r.dias <= 30) { o.s += r.dias; o.k++; } if (r.no_prazo != null) { o.p++; if (r.no_prazo) o.np++; } } else o.a++; });
  const per = `${F.i ? rtrData(F.i) : 'início'} a ${F.f ? rtrData(F.f) : 'hoje'}`, h = terH;
  const linhas = L.map(r => `<tr><td>${rtrData(r.data_envio)}</td><td>${h(r.fornecedor)}${r.pedido ? '<br><small>PC ' + h(r.pedido) + '</small>' : ''}</td><td>${h(r.produto)}</td><td>${h(r.transportadora)}<br><small>${h(r.codigo) || 'sem código'}</small></td><td>${rtrData(r.data_previsao)}</td><td>${rtrData(r.data_entrega)}</td><td>${r.dias ?? '—'}</td><td class="${r.atrasado || r.no_prazo === false ? 'r' : ''}">${r.situacao === 'ENTREGUE' ? (r.no_prazo === false ? `Entregue (+${r.dias_atraso}d)` : 'Entregue') : (r.atrasado ? 'Atrasado' : 'Em trânsito')}${r.recebedor ? '<br><small>' + h(r.recebedor) + '</small>' : ''}</td></tr>`).join('');
  const tab = Object.entries(por).sort((a, b) => b[1].n - a[1].n).map(([t, o]) => `<tr><td>${h(t)}</td><td>${o.n}</td><td>${o.e}</td><td>${o.a}</td><td>${o.k ? (o.s / o.k).toFixed(1).replace('.', ',') : '—'}</td><td>${o.p ? Math.round(100 * o.np / o.p) + '%' : '—'}</td></tr>`).join('');
  const html = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório de rastreios</title><style>
  *{box-sizing:border-box}body{font:12px/1.45 Inter,Arial,sans-serif;color:#14213a;margin:0;padding:28px 34px}h1{margin:0;font-size:22px;letter-spacing:-.02em}.sub{color:#5a6b86;margin:3px 0 18px}
  .k{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}.k div{border:1px solid #d6deea;border-radius:10px;padding:10px 12px;background:#f6f9fd}.k b{display:block;font-size:22px;letter-spacing:-.03em}.k span{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#5a6b86}
  h2{font-size:13px;margin:18px 0 6px;text-transform:uppercase;letter-spacing:.08em;color:#2a5fb8}table{width:100%;border-collapse:collapse}th{background:#e8eef8;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:7px 8px}td{padding:6px 8px;border-top:1px solid #dfe6f1;vertical-align:top}small{color:#6b7b95}.r{color:#b42318;font-weight:700}
  .bar{display:flex;gap:10px;margin-bottom:14px}.bar button{padding:8px 14px;border:0;border-radius:8px;background:#2a5fb8;color:#fff;font-weight:700;cursor:pointer}.foot{margin-top:18px;color:#6b7b95;font-size:10px}
  @media print{.bar{display:none}body{padding:0}tr{break-inside:avoid}}</style></head><body>
  <div class="bar"><button onclick="print()">🖨 Imprimir / Salvar PDF</button></div>
  <h1>Relatório de rastreios — Drilling do Brasil</h1><div class="sub">Envios de ${per}${F.t ? ' · ' + h(F.t) : ''}${F.s ? ' · ' + ({ EM_TRANSITO: 'em trânsito', ENTREGUE: 'entregues', ATRASADOS: 'atrasados / fora do prazo' })[F.s] : ''} · emitido em ${rtrData(RTR.d.hoje)}</div>
  <div class="k"><div><b>${L.length}</b><span>Envios</span></div><div><b>${E.length}</b><span>Entregues</span></div><div><b>${A.length}</b><span>Em trânsito</span></div><div><b>${med}</b><span>Prazo médio (dias)</span></div><div><b>${np == null ? '—' : np + '%'}</b><span>Entregues no prazo</span></div></div>
  <h2>Por transportadora</h2><table><thead><tr><th>Transportadora</th><th>Envios</th><th>Entregues</th><th>Em trânsito</th><th>Prazo médio (d)</th><th>No prazo</th></tr></thead><tbody>${tab}</tbody></table>
  <h2>Detalhamento</h2><table><thead><tr><th>Envio</th><th>Fornecedor</th><th>Produto</th><th>Transportadora / código</th><th>Previsão</th><th>Entrega</th><th>Dias</th><th>Situação</th></tr></thead><tbody>${linhas}</tbody></table>
  <div class="foot">Prazo médio considera entregas de até 30 dias. “No prazo” usa a previsão de entrega quando informada; sem previsão, considera o limite de ${RTR.d.config.prazo_alerta_dias} dias.</div></body></html>`;
  const w = window.open('', '_blank'); if (!w) { terToast('Libere pop-ups para abrir o relatório.', true); return; }
  w.document.open(); w.document.write(html); w.document.close();
}
