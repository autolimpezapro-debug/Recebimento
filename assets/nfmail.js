/* Recebimento Drilling — Caixa de NFs por e-mail (v3.59.0) */
'use strict';
const NFM = { p: null, aba: 'notas', st: 'TODAS', q: '', forn: '', de: '', ate: '', timer: null };
const nfmEl = id => document.getElementById(id);
const nfmH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const nfmD = s => s ? new Date(String(s).slice(0, 10) + 'T12:00:00').toLocaleDateString('pt-BR') : '—';
const nfmDH = s => s ? new Date(s).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const nfmR = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const nfmCnpj = c => /^\d{14}$/.test(c || '') ? c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5') : (c || '');
const nfmPode = op => typeof rdaPermite !== 'function' || rdaPermite('pedidos', op);
const nfmToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));
const NFM_ST = { VINCULADA: ['Vinculada ao PC', 'ok'], A_VINCULAR: ['Vinculando…', 'warn'], PENDENTE: ['Falta o PC', 'warn'], IGNORADA: ['Arquivada sem PC', 'off'] };

async function nfmApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const q = opts.query ? '&' + new URLSearchParams(opts.query) : '';
  const r = await fetch(`api.php?action=${action}${q}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const nfmPost = (a, b) => nfmApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) });

async function nfmailInit() {
  try { NFM.p = await nfmApi('nfmail_painel'); nfmRender(); }
  catch (e) { nfmEl('nfmConteudo').innerHTML = `<div class="empty">${nfmH(e.message)}</div>`; return; }
  // tela aberta: verifica a caixa no intervalo configurado (o servidor decide se já está na hora)
  // só quem pode vincular notas dispara a leitura automática (perfil de consulta só vê a lista)
  if (NFM.p.config.ativo && nfmPode('anexar')) nfmVerificar(true);
  clearInterval(NFM.timer);
  NFM.timer = setInterval(() => { if (document.getElementById('page-nfmail')?.classList.contains('active') && NFM.p?.config.ativo && nfmPode('anexar')) nfmVerificar(true); }, 5 * 60 * 1000);
}
async function nfmVerificar(auto) {
  const b = nfmEl('nfmBtnVerificar'); if (b && !auto) { b.disabled = true; b.textContent = '⏳ Lendo a caixa…'; }
  try {
    const r = await nfmPost('nfmail_sincronizar', { auto: !!auto });
    const x = r.resultado || {};
    if (!auto) nfmToast(x.pulado === 'em andamento' ? 'Já existe uma leitura em andamento.' : `Caixa lida: ${x.lidas || 0} e-mail(s), ${x.novas || 0} nota(s) nova(s), ${r.vinculadas_agora || 0} vinculada(s) ao PC.` + (x.restam ? ` Restam ${x.restam} — clique de novo.` : ''));
    if (!auto || x.lidas || r.vinculadas_agora) { NFM.p = await nfmApi('nfmail_painel'); nfmRender(); }
  } catch (e) {
    if (!auto) nfmToast(e.message, true);
    else if (/acesso negado|permiss/i.test(e.message)) clearInterval(NFM.timer);   // sem permissão: para em silêncio
    else if (NFM.p) { NFM.p.ultimo_erro = e.message; nfmRenderTopo(); }
  }
  finally { if (b) { b.disabled = false; b.textContent = '↻ Verificar agora'; } }
}
function nfmAba(a) { NFM.aba = a; document.querySelectorAll('[data-nfm-aba]').forEach(b => b.classList.toggle('active', b.dataset.nfmAba === a)); nfmRender(); }

function nfmRenderTopo() {
  const p = NFM.p, k = p.kpis, c = p.config;
  nfmEl('nfmKpis').innerHTML = `
    <div class="imp-kpi"><span>Notas recebidas</span><strong>${k.total}</strong><small>${k.hoje} hoje · ${k.cte} CT-e</small></div>
    <div class="imp-kpi ok"><span>Vinculadas ao PC</span><strong>${k.vinculadas}</strong><small>XML/DANFE já no pedido</small></div>
    <div class="imp-kpi ${k.pendentes ? 'warn' : 'ok'}"><span>Falta o PC</span><strong>${k.pendentes}</strong><small>confirme o pedido</small></div>
    <div class="imp-kpi ${k.so_pdf ? 'warn' : ''}"><span>Só PDF (sem XML)</span><strong>${k.so_pdf}</strong><small>peça o XML ao fornecedor</small></div>
    <div class="imp-kpi ${k.canceladas ? 'bad' : ''}"><span>Canceladas</span><strong>${k.canceladas}</strong><small>evento de cancelamento recebido</small></div>
    <div class="imp-kpi"><span>Valor no mês</span><strong>${nfmR(k.valor_mes)}</strong><small>notas emitidas neste mês</small></div>`;
  const st = !c.ativo ? `<span class="prt-badge off">Leitura desligada</span> ${nfmPode('admin') ? 'Configure a caixa na aba <b>Configuração</b>.' : 'Peça ao administrador para configurar.'}`
    : p.ultimo_erro ? `<span class="prt-badge bad">Erro</span> ${nfmH(p.ultimo_erro)}`
    : `<span class="prt-badge ok">Ligada</span> Lendo <b>${nfmH(p.endereco)}</b> a cada ${c.intervalo_min} min · última leitura ${nfmDH(p.ultima_sync_ok)}`;
  nfmEl('nfmStatus').innerHTML = st;
}
function nfmRender() {
  if (!NFM.p) return;
  nfmRenderTopo();
  if (NFM.aba === 'notas') return nfmRenderNotas();
  if (NFM.aba === 'fornecedores') return nfmRenderForn();
  if (NFM.aba === 'emails') return nfmRenderEmails();
  return nfmRenderConfig();
}

/* ---------- notas ---------- */
function nfmFiltradas() {
  const q = NFM.q.trim().toLowerCase();
  return NFM.p.notas.filter(n => {
    if (n.tipo === 'CTE' && NFM.st !== 'CTE' && NFM.st !== 'TODAS') return false;
    if (NFM.st === 'PENDENTES' && !(n.status === 'PENDENTE' || n.status === 'A_VINCULAR')) return false;
    if (NFM.st === 'VINCULADA' && n.status !== 'VINCULADA') return false;
    if (NFM.st === 'IGNORADA' && n.status !== 'IGNORADA') return false;
    if (NFM.st === 'PDF' && n.tipo !== 'PDF') return false;
    if (NFM.st === 'CANCELADA' && !n.cancelada) return false;
    if (NFM.st === 'AVISOS' && !n.avisos.length) return false;
    if (NFM.st === 'CTE' && n.tipo !== 'CTE') return false;
    if (NFM.forn && (n.emitente_cnpj || n.emitente_nome) !== NFM.forn) return false;
    const dt = n.emissao || String(n.recebido_em).slice(0, 10);
    if (NFM.de && dt < NFM.de) return false;
    if (NFM.ate && dt > NFM.ate) return false;
    return !q || [n.numero, n.pc, n.emitente_nome, n.emitente_cnpj, n.chave, n.email?.assunto, n.email?.de].join(' ').toLowerCase().includes(q);
  });
}
function nfmRenderNotas() {
  const p = NFM.p, cont = s => nfmFiltroCont(s);
  const forns = p.fornecedores.map(f => [f.cnpj || f.nome, f.nome || nfmCnpj(f.cnpj)]).sort((a, b) => a[1].localeCompare(b[1]));
  nfmEl('nfmConteudo').innerHTML = `
    <div class="imp-filtros prt-st">${[['TODAS', 'Todas'], ['PENDENTES', 'Falta o PC'], ['VINCULADA', 'Vinculadas'], ['PDF', 'Só PDF'], ['AVISOS', 'Com aviso'], ['CANCELADA', 'Canceladas'], ['IGNORADA', 'Arquivadas'], ['CTE', 'Fretes']].map(([v, t]) =>
      `<button class="est-tab ${NFM.st === v ? 'active' : ''}" onclick="NFM.st='${v}';nfmRenderNotas()">${t} (${v === 'CTE' ? (NFM.p.fretes || []).length : cont(v)})</button>`).join('')}</div>
    <div class="loc-filtros prt-filtros"><input type="search" placeholder="Buscar NF, PC, fornecedor, CNPJ, chave…" value="${nfmH(NFM.q)}" oninput="NFM.q=this.value;nfmTabela()">
      <select onchange="NFM.forn=this.value;nfmTabela()"><option value="">Todos os fornecedores</option>${forns.map(([v, t]) => `<option ${v === NFM.forn ? 'selected' : ''} value="${nfmH(v)}">${nfmH(t)}</option>`).join('')}</select>
      <label class="prt-data">Emissão de <input type="date" value="${NFM.de}" onchange="NFM.de=this.value;nfmTabela()"></label>
      <label class="prt-data">até <input type="date" value="${NFM.ate}" onchange="NFM.ate=this.value;nfmTabela()"></label>
      <a class="button ghost" href="api.php?action=nfmail_csv" download>⬇ Excel (CSV)</a></div>
    <div id="nfmTabela"></div>`;
  nfmTabela();
}
function nfmFiltroCont(v) { const s = NFM.st; NFM.st = v; const q = NFM.q, f = NFM.forn, de = NFM.de, ate = NFM.ate; NFM.q = ''; NFM.forn = ''; NFM.de = ''; NFM.ate = ''; const n = nfmFiltradas().length; Object.assign(NFM, { st: s, q, forn: f, de, ate }); return n; }
const NFM_FR = { vinculado: ['Ligado ao PC', 'ok'], aguardando_nf: ['Aguardando a NF', 'warn'], sem_nf: ['CT-e não cita NF', 'off'] };
function nfmFretesHtml() {
  const q = NFM.q.trim().toLowerCase();
  const L = (NFM.p.fretes || []).filter(f => (!NFM.de || (f.emissao || '') >= NFM.de) && (!NFM.ate || (f.emissao || '') <= NFM.ate) && (!q || [f.numero, f.transportadora, f.cnpj, f.pcs.join(' '), f.nfs.map(n => n.numero + ' ' + n.fornecedor).join(' ')].join(' ').toLowerCase().includes(q)));
  if (!L.length) return '<div class="empty">Nenhum CT-e (frete) recebido ainda.</div>';
  const tot = L.reduce((s, f) => s + (f.cancelada ? 0 : f.valor), 0);
  const por = {}; L.forEach(f => { if (!f.cancelada) { const k = f.transportadora || '—'; por[k] = (por[k] || 0) + f.valor; } });
  const pode = nfmPode('anexar');
  return `<div class="nfm-frete-res">${Object.entries(por).sort((a, b) => b[1] - a[1]).slice(0, 6).map(([k, v]) => `<span><small>${nfmH(k)}</small><b>${nfmR(v)}</b></span>`).join('')}<span><small>Total filtrado</small><b>${nfmR(tot)}</b></span></div>
  <div class="prt-scroll"><table class="orders-table prt-tab nfm-tab"><thead><tr><th>CT-e</th><th>Transportadora</th><th>Emissão</th><th>Frete</th><th>NFs transportadas</th><th>PC</th><th>Situação</th><th></th></tr></thead><tbody>${L.map(f => { const [rot, cls] = NFM_FR[f.situacao]; return `<tr class="${f.cancelada ? 'nfm-canc' : ''}">
    <td data-l="CT-e"><b>${nfmH(f.numero || '—')}</b>${f.serie ? ` <small class="muted">s.${nfmH(f.serie)}</small>` : ''}</td>
    <td data-l="Transportadora">${nfmH(f.transportadora || '—')}<br><small class="muted">${nfmH(nfmCnpj(f.cnpj))}</small></td>
    <td data-l="Emissão">${nfmD(f.emissao)}</td><td data-l="Frete"><b>${f.valor ? nfmR(f.valor) : '—'}</b></td>
    <td data-l="NFs">${f.nfs.length ? f.nfs.map(n => `${nfmH(n.numero)}${n.fornecedor ? ' <small class="muted">' + nfmH(n.fornecedor) + '</small>' : ''}${n.na_caixa ? '' : ' <small class="prt-badge warn">NF não chegou</small>'}`).join('<br>') : '—'}</td>
    <td data-l="PC">${f.pcs.length ? f.pcs.map(pc => `<a href="#" onclick="event.preventDefault();goPage('pedidos');setTimeout(()=>openDrawer(${pc}),150)"><b>${pc}</b></a>`).join(', ') : '—'}${f.manual ? ' <small class="muted">(manual)</small>' : ''}</td>
    <td data-l="Situação"><span class="prt-badge ${cls}">${rot}</span></td>
    <td>${f.xml_url ? `<a class="button ghost" href="${f.xml_url}&baixar=1">XML</a>` : ''}${pode ? ` <button class="button ghost" onclick="nfmFreteLigar('${nfmH(f.id)}',${f.pcs.length && f.manual ? 0 : 1})">${f.manual ? 'Desligar PC' : 'Ligar a PC'}</button>` : ''}</td></tr>`; }).join('')}</tbody></table></div>`;
}
async function nfmFreteLigar(id, ligar) {
  let pc = 0;
  if (ligar) { pc = Number(prompt('Número do PC a que este frete pertence:')); if (!pc) return; }
  try { const r = await nfmPost('nfmail_frete_pc', { id, pc }); nfmToast(r.mensagem); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); } catch (e) { nfmToast(e.message, true); }
}
function nfmTabela() {
  if (NFM.st === 'CTE') { nfmEl('nfmTabela').innerHTML = nfmFretesHtml(); return; }
  const ordem = n => (n.status === 'PENDENTE' || n.status === 'A_VINCULAR') ? 0 : 1;   // o que falta resolver fica no topo
  const L = nfmFiltradas().slice().sort((a, b) => ordem(a) - ordem(b));
  const tot = L.reduce((s, n) => s + (n.cancelada ? 0 : Number(n.valor || 0)), 0);
  nfmEl('nfmTabela').innerHTML = !NFM.p.notas.length
    ? `<div class="empty">Nenhuma nota recebida ainda.${NFM.p.config.ativo ? ' Assim que chegar um e-mail com XML/PDF na caixa, ele aparece aqui.' : ' Ligue a leitura na aba Configuração.'}</div>`
    : `<div class="prt-scroll"><table class="orders-table prt-tab nfm-tab"><thead><tr><th>NF / série</th><th>Fornecedor</th><th>Emissão</th><th>Valor</th><th>PC</th><th>Arquivos</th><th>Situação</th></tr></thead>
      <tbody>${L.map(n => { const [rot, cls] = NFM_ST[n.status] || [n.status, '']; return `<tr onclick="nfmAbrir('${nfmH(n.id)}')" class="${n.cancelada ? 'nfm-canc' : ''}">
        <td data-l="NF"><b>${nfmH(n.numero || '—')}</b>${n.serie ? ` <small class="muted">s.${nfmH(n.serie)}</small>` : ''}${n.tipo === 'CTE' ? ' <small class="prt-badge">CT-e</small>' : n.tipo === 'BOL' ? ' <small class="prt-badge off">Boleto</small>' : ''}</td>
        <td data-l="Fornecedor">${nfmH(n.emitente_nome || '—')}<br><small class="muted">${nfmH(nfmCnpj(n.emitente_cnpj))}</small></td>
        <td data-l="Emissão">${nfmD(n.emissao)}<br><small class="muted">chegou ${nfmDH(n.recebido_em)}</small></td>
        <td data-l="Valor">${n.valor ? nfmR(n.valor) : '—'}</td>
        <td data-l="PC">${n.pc ? `<b>${n.pc}</b>` : (n.sugestoes?.[0] ? `<small class="muted">sugestão ${n.sugestoes.slice(0, 2).map(s => s.pc).join(', ')}</small>` : '—')}</td>
        <td data-l="Arquivos">${n.xml ? '<span class="prt-badge ok">XML</span> ' : '<span class="prt-badge warn">sem XML</span> '}${n.pdfs.length ? '<span class="prt-badge ok">PDF</span>' : '<span class="prt-badge off">sem PDF</span>'}</td>
        <td data-l="Situação">${n.cancelada ? '<span class="prt-badge bad">CANCELADA</span> ' : ''}<span class="prt-badge ${cls}">${rot}</span>${n.avisos.length ? ` <span title="${nfmH(n.avisos.join('\n'))}">⚠️</span>` : ''}</td></tr>`; }).join('') || '<tr><td colspan="7" class="empty">Nada com esse filtro.</td></tr>'}</tbody></table></div>
      <p class="muted nfm-rodape">${L.length} nota(s)${tot ? ' · ' + nfmR(tot) : ''}</p>`;
}

/* ---------- detalhe da nota ---------- */
function nfmModal(titulo, html, largura) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass nfm-modal" style="width:min(${largura || 760}px,100%)"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div>${html}</div>`;
  document.body.appendChild(bg);
  bg.querySelectorAll('[data-x],[data-x2]').forEach(b => b.onclick = () => bg.remove());
  bg.addEventListener('click', e => { if (e.target === bg) bg.remove(); });
  return bg;
}
async function nfmAbrir(id) {
  let n; try { n = (await nfmApi('nfmail_nota', { query: { id } })).nota; } catch (e) { return nfmToast(e.message, true); }
  const [rot, cls] = NFM_ST[n.status] || [n.status, ''];
  const pode = nfmPode('anexar') && n.tipo !== 'CTE' && n.tipo !== 'BOL';
  const sug = (n.sugestoes || []).filter(s => s.pc !== n.pc);
  const bg = nfmModal(`${n.tipo === 'CTE' ? 'CT-e' : n.tipo === 'BOL' ? 'Boleto' : n.tipo === 'PDF' ? 'DANFE (só PDF)' : 'NF-e'} ${nfmH(n.numero || '')} — ${nfmH(n.emitente_nome || '')}`, `
    <div class="nfm-linha">${n.cancelada ? '<span class="prt-badge bad">CANCELADA</span>' : ''}<span class="prt-badge ${cls}">${rot}${n.pc ? ' ' + n.pc : ''}</span>
      ${n.xml_url ? `<a class="button ghost" href="${n.xml_url}&baixar=1">⬇ XML</a>` : ''}${n.pdfs.map((p, i) => `<a class="button ghost" href="${p.url}" target="_blank" rel="noopener">📄 ${nfmH(p.nome || 'DANFE ' + (i + 1))}</a>`).join('')}
      ${pode && n.tipo === 'PDF' && n.pdfs.length && n.status !== 'VINCULADA' ? `<button class="button ghost" data-ia title="A IA lê o PDF; a chave de 44 dígitos valida">🤖 Ler PDF com IA</button>` : ''}
      ${n.pc ? `<button class="button ghost" onclick="this.closest('.rd-ass-bg').remove();goPage('pedidos');typeof openDrawer==='function'&&setTimeout(()=>openDrawer(${n.pc}),150)">Abrir PC ${n.pc}</button>` : ''}</div>
    ${n.avisos.map(a => `<div class="us-alert">${nfmH(a)}</div>`).join('')}
    ${(n.boletos || []).length ? `<div class="prt-docs"><b>Boleto(s) desta NF:</b> ${n.boletos.map(b => b.url ? `<a href="${b.url}" target="_blank" rel="noopener">📄 boleto (${nfmDH(b.recebido_em)})</a>` : 'boleto recebido').join(' · ')}</div>` : ''}
    ${(n.fretes || []).length ? `<div class="prt-docs"><b>Frete:</b> ${n.fretes.map(f => `CT-e ${nfmH(f.numero)} · ${nfmH(f.transportadora)} · ${nfmR(f.valor)}`).join(' · ')}</div>` : ''}
    <div class="nfm-grid">
      <div><span>Emitente</span><b>${nfmH(n.emitente_nome || '—')}</b><small>${nfmH(nfmCnpj(n.emitente_cnpj))}</small></div>
      <div><span>Destinatário</span><b>${nfmH(n.dest_nome || '—')}</b><small>${nfmH(nfmCnpj(n.dest_cnpj))}</small></div>
      <div><span>Número / série</span><b>${nfmH(n.numero || '—')} / ${nfmH(n.serie || '—')}</b><small>emissão ${nfmD(n.emissao)}</small></div>
      <div><span>Valor</span><b>${n.valor ? nfmR(n.valor) : '—'}</b><small>${nfmH(n.natureza || '')}</small></div>
      <div class="nfm-full"><span>Chave de acesso</span><b class="nfm-chave">${nfmH(n.chave || '—')}</b></div>
      <div class="nfm-full"><span>E-mail</span><b>${nfmH(n.email?.assunto || '—')}</b><small>${nfmH(n.email?.de || '')} · ${nfmDH(n.email?.data)}</small></div>
    </div>
    ${n.itens.length ? `<details class="prt-det" ${n.itens.length <= 6 ? 'open' : ''}><summary>${n.itens.length} item(ns) da nota</summary><div class="prt-scroll"><table class="orders-table"><thead><tr><th>Código</th><th>Descrição</th><th>Qtd</th><th>Un</th><th>Valor</th><th>Pedido</th></tr></thead><tbody>
      ${n.itens.map(i => `<tr><td>${nfmH(i.codigo)}</td><td>${nfmH(i.descricao)}</td><td>${Number(i.qtd).toLocaleString('pt-BR')}</td><td>${nfmH(i.un)}</td><td>${nfmR(i.valor)}</td><td>${nfmH(i.xped || '')}</td></tr>`).join('')}</tbody></table></div></details>` : ''}
    ${n.info ? `<details class="prt-det"><summary>Informações complementares da NF</summary><p class="muted" style="font-size:12.5px">${nfmH(n.info)}</p></details>` : ''}
    ${n.eventos.length ? `<div class="prt-docs"><b>Eventos:</b> ${n.eventos.map(e => `${nfmH(e.descricao)} (${nfmDH(e.data)})${e.texto ? ' — ' + nfmH(e.texto) : ''}`).join(' · ')}</div>` : ''}
    ${pode ? `<div class="nfm-vinc"><h3>${n.pc ? 'Trocar o PC' : 'Vincular ao pedido de compra'}</h3>
      ${n.motivo && !n.pc ? `<p class="muted" style="margin:0 0 6px">${nfmH(n.motivo)}</p>` : ''}
      ${sug.length ? `<div class="nfm-sug">${sug.map(s => `<button class="button ghost" data-pc="${s.pc}" title="${nfmH(s.motivo)}"><b>PC ${s.pc}</b> <small>${nfmH(s.fornecedor || '')} · ${nfmH(s.motivo)}${s.ja_tem_nf ? ' · já tem NF ' + nfmH(s.ja_tem_nf) : ''}</small></button>`).join('')}</div>` : ''}
      <div class="nfm-manual"><input type="number" inputmode="numeric" min="1" placeholder="Nº do PC" data-pcman><button class="button primary" data-vinc>Vincular</button></div>
      <p class="muted" style="font-size:12px;margin:6px 0 0">Ao vincular, o sistema só <b>preenche o que está vazio</b> no PC (XML, chave, número, série, emitente, valor, itens e DANFE). Se o PC já tem outra NF, nada é alterado nele.</p></div>` : ''}
    ${pode && n.tipo === 'NFE' && !n.cancelada ? `<details class="prt-det nfm-multi" ${(n.pcs_extra || []).length ? 'open' : ''}><summary>Esta NF cobre vários pedidos? (fornecedor que entrega e fatura depois)</summary>
      ${(n.pcs_extra || []).length ? `<p class="muted" style="font-size:12.5px">Também cobre: <b>${n.pcs_extra.join(', ')}</b></p>` : ''}
      ${(n.abertos_fornecedor || []).length ? `<div class="nfm-sug" style="max-height:170px;overflow:auto">${n.abertos_fornecedor.map(a => `<label class="button ghost" style="cursor:pointer"><input type="checkbox" data-mpc value="${a.pc}"> <b>PC ${a.pc}</b> <small>${a.recebido ? 'já recebido' : 'em aberto'}${a.solicitante ? ' · ' + nfmH(a.solicitante) : ''}</small></label>`).join('')}</div>` : '<p class="muted" style="font-size:12.5px">Nenhum outro PC sem NF deste fornecedor (CNPJ). Digite os números abaixo.</p>'}
      <div class="nfm-manual"><input placeholder="Outros PCs: 4210, 4215…" data-mpcs><button class="button primary" data-multi>Ligar a NF aos PCs marcados</button></div>
      <p class="muted" style="font-size:12px;margin:6px 0 0">O primeiro PC recebe todos os dados; nos demais o sistema só registra chave, número, série, XML e DANFE (o valor e os itens ficam só na NF).</p></details>` : ''}
    <details class="prt-det"><summary>Histórico</summary><ul class="prt-hist">${n.historico.map(h => `<li>${nfmDH(h.em)} · ${nfmH(h.por)} — ${nfmH(h.acao)}</li>`).join('')}</ul></details>
    <div class="rd-ass-acoes">
      ${nfmPode('editar') && n.status === 'VINCULADA' ? '<button class="button ghost" data-desv>Desvincular</button>' : ''}
      ${nfmPode('editar') && n.status !== 'IGNORADA' && n.status !== 'VINCULADA' ? '<button class="button ghost" data-ign>Arquivar sem PC</button>' : ''}
      ${nfmPode('editar') && n.status === 'IGNORADA' ? '<button class="button ghost" data-volta>Voltar para pendentes</button>' : ''}
      <button type="button" class="button ghost" data-x2>Fechar</button></div>`);
  const vinc = async pc => {
    if (!pc) return nfmToast('Informe o número do PC.', true);
    try { const r = await nfmPost('nfmail_vincular', { id: n.id, pc: Number(pc) }); nfmToast(r.mensagem); bg.remove(); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); }
    catch (e) { nfmToast(e.message, true); }
  };
  bg.querySelectorAll('[data-pc]').forEach(b => b.onclick = () => vinc(b.dataset.pc));
  const man = bg.querySelector('[data-pcman]'); if (man) { bg.querySelector('[data-vinc]').onclick = () => vinc(man.value); man.onkeydown = e => { if (e.key === 'Enter') vinc(man.value); }; }
  const acao = async (a, corpo) => { try { const r = await nfmPost(a, { id: n.id, ...corpo }); nfmToast(r.mensagem); bg.remove(); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); } catch (e) { nfmToast(e.message, true); } };
  bg.querySelector('[data-multi]')?.addEventListener('click', async () => {
    const pcs = [...bg.querySelectorAll('[data-mpc]:checked')].map(x => Number(x.value)).concat(String(bg.querySelector('[data-mpcs]').value).split(/[^\d]+/).filter(Boolean).map(Number));
    if (!pcs.length) return nfmToast('Marque ou digite ao menos um PC.', true);
    try { const r = await nfmPost('nfmail_vincular_varios', { id: n.id, pcs }); nfmToast(r.mensagem); bg.remove(); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); nfmAbrir(n.id); } catch (e) { nfmToast(e.message, true); }
  });
  bg.querySelector('[data-ia]')?.addEventListener('click', async ev => { const b = ev.currentTarget; b.disabled = true; b.textContent = 'Lendo…'; try { const r = await nfmPost('nfmail_ia_ler', { id: n.id }); nfmToast(r.mensagem); bg.remove(); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); nfmAbrir(n.id); } catch (e) { nfmToast(e.message, true); b.disabled = false; b.textContent = '🤖 Ler PDF com IA'; } });
  bg.querySelector('[data-ign]')?.addEventListener('click', () => { const m = prompt('Motivo para arquivar sem PC (ex.: nota de serviço, não é da empresa, duplicada):'); if (m) acao('nfmail_ignorar', { motivo: m }); });
  bg.querySelector('[data-volta]')?.addEventListener('click', () => acao('nfmail_ignorar', { desfazer: true }));
  bg.querySelector('[data-desv]')?.addEventListener('click', () => { const m = prompt('Motivo para desvincular do PC ' + n.pc + ':\n(os dados já copiados para o PC continuam lá — corrija no pedido se preciso)'); if (m !== null) acao('nfmail_desvincular', { motivo: m }); });
}

/* ---------- fornecedores ---------- */
function nfmRenderForn() {
  const F = NFM.p.fornecedores;
  nfmEl('nfmConteudo').innerHTML = !F.length ? '<div class="empty">Nenhuma nota recebida ainda.</div>' : `<div class="prt-scroll"><table class="orders-table prt-tab"><thead><tr><th>Fornecedor</th><th>Notas</th><th>Valor</th><th>Falta o PC</th><th>Só PDF</th><th>Canceladas</th><th>Última</th><th>PCs</th></tr></thead><tbody>
    ${F.map(f => `<tr onclick="NFM.forn='${nfmH(f.cnpj || f.nome).replace(/'/g, '&#39;')}';NFM.st='TODAS';nfmAba('notas')">
      <td data-l="Fornecedor"><b>${nfmH(f.nome || '—')}</b><br><small class="muted">${nfmH(nfmCnpj(f.cnpj))}</small></td><td data-l="Notas">${f.qtd}</td><td data-l="Valor">${nfmR(f.valor)}</td>
      <td data-l="Falta o PC">${f.pendentes ? `<span class="prt-badge warn">${f.pendentes}</span>` : '0'}</td><td data-l="Só PDF">${f.so_pdf || 0}</td><td data-l="Canceladas">${f.canceladas || 0}</td>
      <td data-l="Última">${nfmD(f.ultima)}</td><td data-l="PCs"><small>${f.pcs.slice(0, 8).join(', ')}${f.pcs.length > 8 ? '…' : ''}</small></td></tr>`).join('')}</tbody></table></div>
    <p class="muted nfm-rodape">Clique num fornecedor para ver as notas dele.</p>`;
}

/* ---------- e-mails sem NF ---------- */
function nfmRenderEmails() {
  const E = NFM.p.emails_sem_nf, L = NFM.p.log;
  nfmEl('nfmConteudo').innerHTML = `<div class="panel"><div class="panel-heading"><div><h2>E-mails sem nota fiscal</h2><p>Chegaram na caixa, mas sem XML nem PDF de NF. Confira se o fornecedor mandou só um link ou um arquivo em outro formato.</p></div></div>
    ${E.length ? `<div class="prt-scroll"><table class="orders-table"><thead><tr><th>Recebido</th><th>De</th><th>Assunto</th><th>Observação</th></tr></thead><tbody>${E.map(e => `<tr><td>${nfmDH(e.data)}</td><td>${nfmH(e.de)}</td><td>${nfmH(e.assunto)}</td><td><small>${nfmH(e.obs || (e.anexos ? e.anexos + ' anexo(s)' : 'sem anexo'))}</small></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Nenhum.</div>'}</div>
    <div class="panel"><div class="panel-heading"><div><h2>Últimas leituras da caixa</h2></div></div>
    ${L.length ? `<div class="prt-scroll"><table class="orders-table"><thead><tr><th>Quando</th><th>Origem</th><th>E-mails</th><th>Novas</th><th>Atualizadas</th><th>Resultado</th></tr></thead><tbody>${L.map(l => `<tr><td>${nfmDH(l.em)}</td><td>${nfmH(l.origem)}</td><td>${l.lidas}</td><td>${l.novas}</td><td>${l.atualizadas}</td><td>${l.ok ? '<span class="prt-badge ok">ok</span>' : `<span class="prt-badge bad">erro</span> <small>${nfmH((l.erros || []).join(' · '))}</small>`}${l.restam ? ` <small>restam ${l.restam}</small>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Nenhuma leitura ainda.</div>'}</div>`;
}

/* ---------- configuração ---------- */
function nfmRenderConfig() {
  if (!nfmPode('admin')) { nfmEl('nfmConteudo').innerHTML = '<div class="empty">Só o administrador configura a caixa de e-mail.</div>'; return; }
  const c = NFM.p.config;
  nfmEl('nfmConteudo').innerHTML = `<div class="panel"><div class="panel-heading"><div><h2>Caixa de e-mail das notas</h2><p>Use uma caixa <b>só para as notas</b> (ex.: drilling@recebimento.autolimpezapro.com.br). No e-mail corporativo, crie uma regra que encaminha para ela todo e-mail com XML/PDF de NF.</p></div></div>
    <form id="nfmCfg" class="form-grid" onsubmit="event.preventDefault();nfmSalvarCfg()">
      <label class="form-line full nfm-ck"><input type="checkbox" name="ativo" ${c.ativo ? 'checked' : ''}> <span><b>Ligar a leitura automática</b> — o sistema lê a caixa a cada ${c.intervalo_min} min e vincula as notas aos PCs</span></label>
      <label class="form-line"><span>E-mail (usuário)</span><input name="usuario" value="${nfmH(c.usuario || 'drilling@recebimento.autolimpezapro.com.br')}" autocomplete="off"></label>
      <label class="form-line"><span>Senha do e-mail ${c.senha_definida ? '<small class="muted">(já gravada — deixe em branco para manter)</small>' : ''}</span><input name="senha" type="password" autocomplete="new-password" placeholder="${c.senha_definida ? '••••••••' : 'senha da caixa'}"></label>
      <label class="form-line"><span>Servidor IMAP</span><input name="host" value="${nfmH(c.host || 'mail.recebimento.autolimpezapro.com.br')}"></label>
      <label class="form-line"><span>Porta</span><input name="porta" type="number" value="${c.porta}"></label>
      <label class="form-line"><span>Segurança</span><select name="seguranca">${[['ssl', 'SSL/TLS (porta 993)'], ['tls', 'STARTTLS (porta 143)'], ['none', 'Nenhuma (não recomendado)']].map(([v, t]) => `<option value="${v}" ${c.seguranca === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
      <label class="form-line"><span>Pasta</span><input name="pasta" value="${nfmH(c.pasta)}"></label>
      <label class="form-line"><span>Na 1ª leitura, buscar e-mails dos últimos (dias)</span><input name="dias_iniciais" type="number" min="1" max="365" value="${c.dias_iniciais}"></label>
      <label class="form-line"><span>Ler a caixa a cada (minutos)</span><input name="intervalo_min" type="number" min="5" max="240" value="${c.intervalo_min}"></label>
      <label class="form-line full"><span>CNPJs da empresa (destinatário) — separados por vírgula</span><input name="cnpjs_empresa" value="${nfmH(c.cnpjs_empresa)}" placeholder="04.105.851/0001-50, 43.946.023/0001-04"><small class="muted">Nota para outro CNPJ fica com aviso e não é vinculada sozinha. Em branco = não confere.</small></label>
      <label class="form-line nfm-ck"><input type="checkbox" name="vincular_auto" ${c.vincular_auto ? 'checked' : ''}> <span>Vincular sozinho quando o PC estiver claro</span></label>
      <label class="form-line nfm-ck"><input type="checkbox" name="marcar_lida" ${c.marcar_lida ? 'checked' : ''}> <span>Marcar o e-mail como lido depois de processar</span></label>
      <label class="form-line nfm-ck"><input type="checkbox" name="verificar_certificado" ${c.verificar_certificado ? 'checked' : ''}> <span>Verificar o certificado SSL do servidor</span></label>
      <div class="form-line full nfm-botoes"><button type="button" class="button ghost" onclick="nfmTestar()">🔌 Testar conexão</button><button class="button primary">💾 Salvar</button></div>
    </form></div>
    <div class="panel"><div class="panel-heading"><div><h2>Leitura mesmo com o sistema fechado (recomendado)</h2><p>No cPanel da HostGator → <b>Cron Jobs</b>, crie uma tarefa a cada 10 minutos (*/10 * * * *) com o comando:</p></div></div>
      <pre class="nfm-pre">php -q ${nfmH(NFM.p.caminho || '/CAMINHO/DO/SISTEMA')}/nfmail_worker.php</pre>
      <p class="muted" style="font-size:12.5px">Sem o cron, a leitura acontece quando alguém está com o sistema aberto.</p></div>`;
}
function nfmCfgDados() {
  const f = nfmEl('nfmCfg'), o = {};
  f.querySelectorAll('input,select').forEach(el => { o[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
  return o;
}
async function nfmTestar() {
  try { const r = await nfmPost('nfmail_testar', nfmCfgDados()); nfmToast(r.mensagem); } catch (e) { nfmToast(e.message, true); }
}
async function nfmSalvarCfg() {
  try { await nfmPost('nfmail_config_salvar', nfmCfgDados()); nfmToast('Configuração salva.'); NFM.p = await nfmApi('nfmail_painel'); nfmRender(); if (NFM.p.config.ativo) nfmVerificar(false); }
  catch (e) { nfmToast(e.message, true); }
}

/* v3.65.2 — aviso ao conferente: "NF deste PC já chegou por e-mail" (aparece no detalhe do pedido) */
async function nfEmailAviso(pc) {
  const box = document.getElementById('nfEmailBox'); if (!box) return;
  try {
    const a = (await nfmApi('nfmail_aviso', { query: { pc } })).aviso; if (!a || document.getElementById('nfEmailBox') !== box) return;
    const t = { vinculada: ['ok', '📬 NF já recebida por e-mail e vinculada a este pedido'], vinculando: ['ok', '📬 NF recebida por e-mail — vinculando a este pedido'], possivel: ['warn', '📬 Chegou uma NF por e-mail que provavelmente é deste pedido (falta confirmar)'] }[a.situacao];
    box.innerHTML = `<div class="nfm-aviso ${t[0]}"><b>${t[1]}</b><span>NF ${nfmH(a.numero || '—')} · ${nfmH(a.emitente || '')}${a.valor ? ' · ' + nfmR(a.valor) : ''} · recebida em ${nfmDH(a.recebido_em)}${a.tem_xml ? ' · XML' : ''}${a.tem_pdf ? ' · PDF' : ''}</span>`
      + `<small>${(a.frete || []).length ? 'Frete: ' + a.frete.map(f => nfmH(f.transportadora) + ' ' + nfmR(f.valor)).join(' · ') + '. ' : ''}Isto não é a chegada da mercadoria: confira os volumes e os itens do pedido normalmente.</small>${a.pdf_url ? `<a class="button ghost" href="${a.pdf_url}" target="_blank" rel="noopener">📄 Ver DANFE</a>` : ''}</div>`;
  } catch (e) { /* sem Caixa de NFs ou sem permissão: não mostra nada */ }
}
