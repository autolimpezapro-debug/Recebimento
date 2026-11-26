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
function rtrRender() {
  const L = RTR.d.rastreios, ab = L.filter(r => r.situacao === 'EM_TRANSITO'), at = L.filter(r => r.atrasado), conf = L.filter(r => r.conferir.length);
  const mes = RTR.d.hoje.slice(0, 7), entm = L.filter(r => r.situacao === 'ENTREGUE' && (r.data_entrega || '').startsWith(mes));
  const box = (n, t, cls = '') => `<div class="imp-kpi ${cls}"><b>${n}</b><span>${t}</span></div>`;
  // por transportadora (somente entregues com dias válidos e <=30 para não distorcer)
  const por = {}; L.forEach(r => { const t = r.transportadora || '(sem transportadora)'; const o = por[t] = por[t] || { n: 0, ab: 0, soma: 0, k: 0 }; o.n++; if (r.situacao === 'EM_TRANSITO') o.ab++; else if (r.dias != null && r.dias >= 0 && r.dias <= 30) { o.soma += r.dias; o.k++; } });
  const linhasT = Object.entries(por).sort((a, b) => b[1].n - a[1].n).map(([t, o]) => `<tr><td>${terH(t)}</td><td>${o.n}</td><td>${o.ab}</td><td>${o.k ? (o.soma / o.k).toFixed(1).replace('.', ',') + ' dias' : '—'}</td></tr>`).join('');
  const pode = rtrPode('editar'), opt = (v, t) => `<option value="${v}" ${RTR.sit === v ? 'selected' : ''}>${t}</option>`;
  const trs = RTR.d.transportadoras.map(t => `<option ${RTR.tr === t ? 'selected' : ''}>${terH(t)}</option>`).join('');
  const el = rtrEl('terRastreios');
  el.innerHTML = `<div class="imp-kpis prt-kpis">${box(ab.length, 'em trânsito', ab.length ? 'warn' : '')}${box(at.length, `atrasados (+${RTR.d.config.prazo_alerta_dias} dias)`, at.length ? 'bad' : '')}${box(entm.length, 'entregues neste mês')}${box(conf.length, 'a conferir', conf.length ? 'warn' : '')}${box(L.length, 'total controlado')}</div>
  <div class="filterrow"><div class="searchbox"><input id="rtrBusca" type="search" placeholder="Buscar código, fornecedor, pedido, produto, recebedor…" value="${terH(RTR.q)}"></div>
    <select id="rtrSit">${opt('ABERTOS', 'Em trânsito')}${opt('ATRASADOS', 'Atrasados')}${opt('ENTREGUES', 'Entregues')}${opt('CONFERIR', 'A conferir')}${opt('TODOS', 'Todos')}</select>
    <select id="rtrTr"><option value="">Todas as transportadoras</option>${trs}</select>
    <button class="button ghost" onclick="rtrCsv()">⬇ CSV</button>${rtrPode('criar') ? `<button class="button ghost" onclick="rtrImportar()">📋 Importar da planilha</button><button class="button primary" onclick="rtrForm()">＋ Novo rastreio</button>` : ''}${rtrPode('admin') ? `<button class="button ghost" onclick="rtrConfig()">⚙ Configurar</button>` : ''}</div>
  <div id="rtrLista"></div>
  <details class="rtr-det"><summary><b>Por transportadora</b> — volume, abertos e prazo médio das entregas</summary><div class="tablewrap"><table class="table"><thead><tr><th>Transportadora</th><th>Envios</th><th>Em trânsito</th><th>Prazo médio</th></tr></thead><tbody>${linhasT || '<tr><td colspan="4">—</td></tr>'}</tbody></table></div><small>Prazo médio considera entregas de até 30 dias (acima disso a data provavelmente está errada — veja “A conferir”).</small></details>`;
  rtrEl('rtrBusca').oninput = e => { RTR.q = e.target.value; rtrLista(); };
  rtrEl('rtrSit').onchange = e => { RTR.sit = e.target.value; rtrLista(); };
  rtrEl('rtrTr').onchange = e => { RTR.tr = e.target.value; rtrLista(); };
  rtrLista();
}
function rtrLista() {
  const L = rtrFiltrados(), pode = rtrPode('editar'), box = rtrEl('rtrLista');
  if (!L.length) { box.innerHTML = '<div class="empty">Nenhum rastreio neste filtro.</div>'; return; }
  box.innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>Envio</th><th>Fornecedor / Pedido</th><th>Produto</th><th>Transportadora / Código</th><th>Dias</th><th>Situação</th><th></th></tr></thead><tbody>${L.map(r => {
    const id = terH(r.id), sit = r.situacao === 'ENTREGUE' ? `<span class="badge ok">Entregue ${rtrData(r.data_entrega)}</span>${r.recebedor ? `<br><small>${terH(r.recebedor)}</small>` : ''}` : (r.atrasado ? '<span class="badge bad">Atrasado</span>' : '<span class="badge warn">Em trânsito</span>');
    const cod = r.codigo ? `<code>${terH(r.codigo)}</code>` : '<em>sem código</em>';
    const lk = r.link_final ? `<a class="button ghost" href="${terH(r.link_final)}" target="_blank" rel="noopener noreferrer" title="Abrir rastreio no site">🔎 Rastrear</a>` : '';
    const cp = r.codigo ? `<button class="button ghost" onclick="navigator.clipboard&&navigator.clipboard.writeText('${terH(r.codigo)}');terToast('Código copiado')" title="Copiar código">⧉</button>` : '';
    const ac = pode ? (r.situacao === 'ENTREGUE' ? `<button class="button ghost" onclick="rtrReabrir('${id}')">↩ Reabrir</button>` : `<button class="button primary" onclick="rtrEntregar('${id}')">✔ Entregue</button>`) + `<button class="button ghost" onclick="rtrForm('${id}')">✏</button>` : '';
    return `<tr><td>${rtrData(r.data_envio)}<br><small>${terH(r.id)}</small></td><td>${terH(r.fornecedor) || '—'}<br><small>${r.pedido ? 'PC ' + terH(r.pedido) : ''}${r.romaneio ? ' · ' + terH(r.romaneio) : ''}</small></td><td>${terH(r.produto) || '—'}</td><td>${terH(r.transportadora) || '—'}<br>${cod}</td><td>${r.dias ?? '—'}</td><td>${sit}${r.conferir.length ? `<br><small class="rtr-conf">⚠ ${r.conferir.map(terH).join('; ')}</small>` : ''}</td><td class="rtr-ac">${lk}${cp}${ac}</td></tr>`;
  }).join('')}</tbody></table></div>`;
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
