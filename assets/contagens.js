/* Recebimento Drilling — Inventário e Acuracidade (v3.55.0): contagem geral/cíclica, cega, com recontagem, aprovação e contagem OFFLINE (IndexedDB). */
'use strict';
const CNT = { d: null, aba: 'painel', cur: null, revelar: false, filtro: '', sug: null };
const cntNum = n => n == null ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
const cntMoney = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const cntPct = n => n == null ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
const CNT_ST = { PENDENTE: ['a contar', ''], OK: ['ok', 'ok'], RECONTAR: ['recontar', 'bad'], OK_RECONTAGEM: ['ok (recontagem)', 'ok'], APROVACAO: ['aguarda gestor', 'warn'], APROVADO: ['ajuste aprovado', 'ok'], REJEITADO: ['ajuste rejeitado', 'off'], AJUSTADO: ['ajustado', 'ok'], NAO_CONTADO: ['não contado', 'off'] };
const CNT_CS = { CONTANDO: ['em contagem', 'warn'], APLICADA: ['aplicada ao estoque', 'ok'], CANCELADA: ['cancelada', 'off'] };

/* ---------- fila offline (IndexedDB) ---------- */
const cntIdb = (() => {
  let p; const open = () => p || (p = new Promise((res, rej) => {
    if (!window.indexedDB) return rej(new Error('sem IndexedDB'));
    const r = indexedDB.open('rd-contagens', 1);
    r.onupgradeneeded = () => { const d = r.result; d.createObjectStore('fila', { keyPath: 'k', autoIncrement: true }); d.createObjectStore('cache', { keyPath: 'id' }); };
    r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error);
  }));
  const tx = async (st, mode, fn) => { const d = await open(); return new Promise((res, rej) => { const t = d.transaction(st, mode), s = t.objectStore(st), q = fn(s); t.oncomplete = () => res(q && q.result); t.onerror = () => rej(t.error); }); };
  return { add: (st, v) => tx(st, 'readwrite', s => s.put(v)), all: st => tx(st, 'readonly', s => s.getAll()), get: (st, k) => tx(st, 'readonly', s => s.get(k)), del: (st, k) => tx(st, 'readwrite', s => s.delete(k)) };
})();
async function cntFilaTotal() { try { return (await cntIdb.all('fila')).length; } catch { return 0; } }
async function cntBadgeFila() { const n = await cntFilaTotal(), el = terEl('cntFila'); if (el) el.innerHTML = n ? `<span class="prt-badge warn">⏳ ${n} contagem(ns) aguardando envio</span> <button class="button ghost" onclick="cntSync(true)">Enviar agora</button>` : ''; }
let cntSyncando = false;
function cntRegistrarSync() { try { navigator.serviceWorker.ready.then(r => r.sync && r.sync.register('rd-flush-contagens')).catch(() => { }); } catch { } }
async function cntSync(avisar) {
  if (cntSyncando) return; cntSyncando = true;
  try {
    const fila = await cntIdb.all('fila'); if (!fila.length) { if (avisar) terToast('Nada pendente de envio.'); return; }
    const por = {}; fila.forEach(f => (por[f.cid] = por[f.cid] || []).push(f));
    for (const [cid, itens] of Object.entries(por)) {
      try {
        await terPost('contagens_contar', { id: cid, itens: itens.map(i => ({ codigo: i.codigo, qtd: i.qtd, em: i.em })) });
        for (const i of itens) await cntIdb.del('fila', i.k);
      } catch (e) {
        if (e instanceof TypeError) { cntRegistrarSync(); if (avisar) terToast('Sem conexão. Fica guardado neste aparelho e envia sozinho quando voltar.', true); break; }
        if (/encerrada|não encontrada/i.test(e.message)) { for (const i of itens) await cntIdb.del('fila', i.k); terToast(`${cid}: ${e.message} — contagens locais descartadas.`, true); }
        else { terToast(`${cid}: ${e.message}`, true); }
      }
    }
  } finally { cntSyncando = false; cntBadgeFila(); if (CNT.cur && terEl('cntTela')) cntRecarregarAtual(); }
}
window.addEventListener('online', () => cntSync(false));
document.addEventListener('visibilitychange', () => { if (!document.hidden) cntSync(false); });
setInterval(() => { if (navigator.onLine) cntSync(false); }, 60000);
navigator.serviceWorker && navigator.serviceWorker.addEventListener('message', e => { if (e.data && e.data.tipo === 'flush-contagens') cntSync(false); });

/* ---------- página ---------- */
async function contagensInit() {
  const c = terEl('cntConteudo'); if (!c) return;
  CNT.cur = null;
  try { CNT.d = await terApi('contagens_painel'); } catch (e) {
    c.innerHTML = `<div class="empty">${terH(e.message)}${navigator.onLine ? '' : '<br>Sem conexão. Contagens já baixadas neste aparelho aparecem abaixo.'}</div><div id="cntOffline"></div>`; cntListaOffline(); return;
  }
  cntRender();
}
async function cntListaOffline() {
  const el = terEl('cntOffline'); if (!el) return; let l = []; try { l = await cntIdb.all('cache'); } catch { }
  el.innerHTML = l.length ? l.map(c => `<article class="panel prt-card"><div class="prt-top"><div><h3>${terH(c.id)} · ${terH(c.nome)}</h3><p class="muted">${c.itens.length} itens baixados neste aparelho</p></div><button class="button primary" onclick="cntAbrir('${terH(c.id)}')">Contar offline</button></div></article>`).join('') : '';
}
function cntRender() {
  const d = CNT.d, k = d.kpis, ab = CNT.aba;
  const tabs = [['painel', 'Acuracidade'], ['contagens', 'Contagens'], ['ciclica', 'Plano cíclico'], ['config', 'Configuração']];
  terEl('cntKpis').innerHTML = '';
  terEl('cntConteudo').innerHTML = `<div id="cntFila" style="margin-bottom:8px"></div><div class="imp-filtros">${tabs.map(([v, t]) => `<button class="est-tab ${ab === v ? 'active' : ''}" onclick="CNT.aba='${v}';cntRender()">${t}</button>`).join('')}</div><div id="cntAba"></div>`;
  ({ painel: cntPainel, contagens: cntContagens, ciclica: cntCiclica, config: cntConfig }[ab])();
  cntBadgeFila();
}
function cntTabela(cab, linhas) { return linhas.length ? `<div class="table-wrap"><table class="orders-table"><thead><tr>${cab.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${linhas.map(l => `<tr>${l.map(c => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : '<div class="empty">Sem dados ainda.</div>'; }
function cntBarras(meses, meta) {
  if (!meses.length) return '<div class="empty">Aplique a primeira contagem para ver a evolução mensal.</div>';
  return `<div style="display:flex;gap:10px;align-items:flex-end;height:150px;border-bottom:1px solid #8884;padding:0 4px;overflow-x:auto">${meses.slice(-12).map(m => { const v = m.ira || 0; return `<div style="flex:0 0 52px;text-align:center"><small>${cntPct(m.ira)}</small><div title="${m.contados} itens contados" style="height:${Math.max(3, v * 1.1)}px;background:${v >= meta ? '#2e9e5b' : '#d99a1c'};border-radius:4px 4px 0 0"></div><small>${m.mes.slice(5)}/${m.mes.slice(2, 4)}</small></div>`; }).join('')}</div><p class="muted">Verde = atingiu a meta de ${cntPct(meta)}.</p>`;
}
function cntPainel() {
  const d = CNT.d, k = d.kpis, box = (n, t, cls = '', f = '') => `<div class="imp-kpi ${cls}" ${f ? `style="cursor:pointer" onclick="${f}"` : ''}><b>${n}</b><span>${t}</span></div>`;
  const ok = k.ira_90d != null && k.ira_90d >= k.meta;
  const pr = (rows, tit) => `<h3 style="margin-top:18px">${tit}</h3>` + cntTabela(['', 'Contados', 'Acertos', 'Acuracidade'], rows.map(r => [terH(r.chave), r.contados, r.acertos, `<b>${cntPct(r.ira)}</b>`]));
  terEl('cntAba').innerHTML = `<div class="imp-kpis prt-kpis" style="margin-top:10px">${box(k.ira_90d == null ? 'sem dado' : cntPct(k.ira_90d), `acuracidade (IRA) 90 dias · meta ${cntPct(k.meta)}`, k.ira_90d == null ? '' : (ok ? '' : 'bad'))}${box(k.contados_90d, 'itens contados em 90 dias')}${box(cntMoney(k.dif_valor_abs_90d), 'divergência em valor (90 dias)', k.dif_valor_abs_90d ? 'warn' : '')}${box(k.abertas, 'contagens em andamento', k.abertas ? 'warn' : '', "CNT.aba='contagens';cntRender()")}${box(k.total_vencidos, `itens a contar no plano (A ${k.vencidos.A} · B ${k.vencidos.B} · C ${k.vencidos.C})`, k.total_vencidos ? 'warn' : '', "CNT.aba='ciclica';cntRender()")}</div>
    <h3 style="margin-top:14px">Acuracidade por mês</h3>${cntBarras(d.meses, k.meta)}
    <p class="muted">Acuracidade = itens cuja <b>1ª contagem</b> bateu com o sistema (dentro da tolerância de ${d.config.tolerancia_pct}%) ÷ itens contados. Quem conta não vê o saldo do sistema.</p>
    ${pr(d.por_abc, 'Por curva ABC')}${pr(d.por_local, 'Por endereço')}${pr(d.por_contador, 'Por contador')}
    <h3 style="margin-top:18px">Itens que mais divergem</h3>${cntTabela(['Código', 'Material', 'Divergências', 'Valor divergente', 'Última'], d.itens_divergentes.map(i => [terH(i.codigo), terH(i.descricao), i.divergencias, cntMoney(i.valor_abs), terD(i.ultima)]))}`;
}
function cntContagens() {
  const l = CNT.d.contagens, pode = terPode('criar');
  terEl('cntAba').innerHTML = `<div style="margin:10px 0">${pode ? '<button class="button primary" onclick="cntNova(\'GERAL\')">＋ Novo inventário geral</button> <button class="button ghost" onclick="cntNova(\'CICLICA\')">＋ Nova contagem cíclica</button>' : ''}</div>`
    + (l.length ? l.map(c => { const [t, cls] = CNT_CS[c.status] || [c.status, '']; const r = c.resumo, e = c.estatisticas;
      return `<article class="panel prt-card"><div class="prt-top"><div><h3>${terH(c.id)} · ${terH(c.nome)} <span class="prt-badge ${cls}">${t}</span> <span class="prt-badge">${c.tipo === 'CICLICA' ? 'cíclica' : 'geral'}</span></h3>
        <p class="muted">${terD(c.criado_em)} · ${terH(c.criado_por)} · ${r.total} itens · contados ${r.contados}/${r.total}${r.recontar ? ` · <b>${r.recontar} a recontar</b>` : ''}${r.aprovacao ? ` · <b>${r.aprovacao} aguardam gestor</b>` : ''}${e ? ` · acuracidade ${cntPct(e.ira_itens)} · divergência ${cntMoney(e.dif_valor_abs)}` : ''}</p></div>
        <div class="est-head-actions"><button class="button primary" onclick="cntAbrir('${c.id}')">${c.status === 'CONTANDO' ? 'Contar / conferir' : 'Ver'}</button><button class="button ghost" onclick="window.open('api.php?action=contagens_folha&id=${c.id}','_blank')">🖨 Folha</button>${c.status === 'CONTANDO' ? `<button class="button ghost" onclick="cntBaixar('${c.id}')">📲 Baixar p/ offline</button>` : ''}<button class="button ghost" onclick="location.href='api.php?action=contagens_csv&id=${c.id}'">⇩ CSV</button></div></div></article>`; }).join('')
      : '<div class="empty">Nenhuma contagem ainda. Crie um inventário geral (balanço) ou uma contagem cíclica.</div>');
}
async function cntCiclica() {
  const box = terEl('cntAba'); box.innerHTML = '<div class="empty">Carregando plano…</div>';
  try { CNT.sug = await terApi(`contagens_sugestao&n=${CNT.d.config.qtd_dia}`); } catch (e) { box.innerHTML = `<div class="empty">${terH(e.message)}</div>`; return; }
  const s = CNT.sug, f = CNT.d.config.freq_dias;
  box.innerHTML = `<p class="muted" style="margin-top:10px">Curva ABC calculada pelo giro (saídas em 12 meses) e valor em estoque. Frequência: <b>A a cada ${f.A} dias · B a cada ${f.B} · C a cada ${f.C}</b>. Itens nunca contados entram como vencidos. Vencidos agora: A ${s.vencidos.A} · B ${s.vencidos.B} · C ${s.vencidos.C}.</p>
    ${terPode('criar') && s.sugeridos.length ? `<button class="button primary" onclick="cntCriarCiclica()">＋ Criar contagem do dia com estes ${s.sugeridos.length} itens</button>` : ''}
    ${cntTabela(['ABC', 'Endereço', 'Código', 'Material', 'Última contagem', 'Saldo'].slice(0, CNT.d.gestor ? 6 : 5), s.sugeridos.map(i => [`<span class="prt-badge ${i.abc === 'A' ? 'bad' : i.abc === 'B' ? 'warn' : ''}">${i.abc}</span>`, terH(i.localizacao || '—'), terH(i.codigo), terH(i.descricao) + (i.ultima_divergente ? ' <span class="prt-badge bad">divergiu</span>' : ''), i.dias == null ? 'nunca' : `${i.dias} dia(s)`].concat(CNT.d.gestor ? [cntNum(i.saldo)] : [])))}`;
}
async function cntCriarCiclica() {
  try { const r = await terPost('contagens_criar', { tipo: 'CICLICA', escopo: 'LISTA', codigos: CNT.sug.sugeridos.map(i => i.codigo) }); terToast('Contagem criada.'); CNT.d = await terApi('contagens_painel'); await cntAbrir(r.id); } catch (e) { terToast(e.message, true); }
}
function cntConfig() {
  const c = CNT.d.config, g = terPode('aprovar');
  terEl('cntAba').innerHTML = `<div class="panel" style="padding:14px;margin-top:10px;max-width:640px"><div class="rd-ass-campos">
    <label>Tolerância (%)<input type="number" step="0.1" min="0" name="tp" value="${c.tolerancia_pct}" ${g ? '' : 'disabled'}></label><label>Tolerância (unidades)<input type="number" step="0.01" min="0" name="ta" value="${c.tolerancia_abs}" ${g ? '' : 'disabled'}></label>
    <label>Meta de acuracidade (%)<input type="number" step="0.1" name="mt" value="${c.meta_acuracidade}" ${g ? '' : 'disabled'}></label><label>Itens por contagem cíclica do dia<input type="number" min="1" max="200" name="qd" value="${c.qtd_dia}" ${g ? '' : 'disabled'}></label>
    ${['A', 'B', 'C'].map(k => `<label>Contar curva ${k} a cada (dias)<input type="number" min="1" name="f${k}" value="${c.freq_dias[k]}" ${g ? '' : 'disabled'}></label>`).join('')}
    <label><input type="checkbox" name="cg" ${c.cega ? 'checked' : ''} ${g ? '' : 'disabled'}> Contagem cega (contador não vê o saldo do sistema)</label></div>
    <p class="muted">Divergência até a tolerância é ajustada direto. Acima dela: recontagem e, se persistir, aprovação do gestor com motivo. Vale para novas contagens.</p>
    ${g ? '<button class="button primary" onclick="cntSalvarConfig()">Salvar configuração</button>' : '<p class="muted">Somente quem aprova estoque altera a configuração.</p>'}</div>`;
}
async function cntSalvarConfig() {
  const q = n => document.querySelector(`#cntAba [name="${n}"]`);
  try { await terPost('contagens_config', { tolerancia_pct: q('tp').value, tolerancia_abs: q('ta').value, meta_acuracidade: q('mt').value, qtd_dia: q('qd').value, freq_dias: { A: q('fA').value, B: q('fB').value, C: q('fC').value }, cega: q('cg').checked }); terToast('Configuração salva.'); CNT.d = await terApi('contagens_painel'); cntRender(); } catch (e) { terToast(e.message, true); }
}
function cntNova(tipo) {
  const bg = terModal(tipo === 'CICLICA' ? 'Nova contagem cíclica' : 'Novo inventário geral', `<div class="rd-ass-campos"><label>Nome (opcional)<input name="nome" placeholder="${tipo === 'CICLICA' ? 'Ex.: Curva A — corredor 3' : 'Ex.: Balanço de dezembro'}"></label>
    <label>O que contar<select name="esc"><option value="TODOS">Todos os itens</option><option value="ABC">Uma curva ABC</option><option value="CATEGORIA">Uma categoria</option><option value="LOCALIZACAO">Um endereço/localização</option><option value="ITENS">Itens específicos (códigos)</option></select></label>
    <label>Filtro (curva A/B/C, categoria, endereço ou códigos separados por vírgula)<input name="filtro"></label><label><input type="checkbox" name="sc" ${tipo === 'GERAL' ? '' : 'checked'}> Só itens com saldo maior que zero</label></div>
    <p class="muted">O saldo do sistema é congelado agora. Movimentações feitas durante a contagem são descontadas automaticamente.</p><div class="est-head-actions"><button class="button primary" data-ok>Criar contagem</button></div>`, 560);
  bg.querySelector('[data-ok]').onclick = async () => {
    try { const r = await terPost('contagens_criar', { tipo, nome: terVal(bg, 'nome'), escopo: terVal(bg, 'esc'), filtro: terVal(bg, 'filtro'), so_com_saldo: bg.querySelector('[name="sc"]').checked }); bg.remove(); terToast('Contagem criada.'); CNT.d = await terApi('contagens_painel'); await cntAbrir(r.id); } catch (e) { terToast(e.message, true); }
  };
}

/* ---------- tela de contagem ---------- */
async function cntCarregar(id) {
  try { const r = await terApi(`contagens_detalhe&id=${id}${CNT.revelar ? '&revelar=1' : ''}`); if (!CNT.revelar && r.contagem.status === 'CONTANDO') cntIdb.add('cache', r.contagem).catch(() => { }); return r; }
  catch (e) { if (!(e instanceof TypeError)) throw e; const c = await cntIdb.get('cache', id); if (!c) throw new Error('Sem conexão e esta contagem não foi baixada neste aparelho.'); return { contagem: c, gestor: false, offline: true }; }
}
async function cntBaixar(id) { try { const r = await terApi(`contagens_detalhe&id=${id}`); await cntIdb.add('cache', r.contagem); if (typeof pwaPersistir === 'function') pwaPersistir(); terToast('Contagem baixada. Pode contar sem internet; envia sozinho ao voltar.'); } catch (e) { terToast(e.message, true); } }
async function cntRecarregarAtual() { if (CNT.cur) await cntAbrir(CNT.cur.contagem.id, true); }
async function cntAbrir(id, manter) {
  let r; try { r = await cntCarregar(id); } catch (e) { terToast(e.message, true); return; }
  // sobrepõe com o que ainda está na fila local (contado offline, não enviado)
  const fila = (await cntIdb.all('fila').catch(() => [])).filter(f => f.cid === id); r.pend = {}; fila.forEach(f => r.pend[f.codigo] = f.qtd);
  const y = window.scrollY; CNT.cur = r; cntTela(); if (manter) window.scrollTo(0, y);
}
function cntTela() {
  const r = CNT.cur, c = r.contagem, rs = c.resumo, aberto = c.status === 'CONTANDO', gest = r.gestor && terPode('aprovar');
  const q = CNT.filtro.trim().toLowerCase(), so = CNT.soPend;
  const itens = c.itens.filter(l => (!q || [l.codigo, l.descricao, l.localizacao].join(' ').toLowerCase().includes(q)) && (!so || ['PENDENTE', 'RECONTAR', 'APROVACAO'].includes(l.status) && r.pend[l.codigo] == null));
  const linhas = itens.map(l => {
    const [t, cls] = CNT_ST[l.status] || [l.status, '']; const loc = r.pend[l.codigo]; const ult = l.rodadas && l.rodadas.length ? l.rodadas[l.rodadas.length - 1] : null; const rec = l.status === 'RECONTAR';
    const mostra = !c.cega || r.revelado || !aberto || (CNT.revelar && gest); const dif = l.diferenca;
    let acao = '';
    if (aberto && l.status === 'APROVACAO' && gest) acao = `<button class="button primary" data-dec="aprovar" data-cod="${terH(l.codigo)}">Aprovar ajuste</button> <button class="button ghost" data-dec="rejeitar" data-cod="${terH(l.codigo)}">Manter sistema</button>`;
    else if (aberto && l.status !== 'APROVACAO') acao = `<input class="cnt-in" inputmode="decimal" data-cod="${terH(l.codigo)}" placeholder="${rec ? 'recontagem' : 'qtd'}" value="${loc != null ? loc : (ult && !rec ? ult.qtd : '')}" style="width:96px;${rec ? 'border:2px solid #d33' : ''}">`;
    else if (ult) acao = cntNum(ult.qtd);
    return `<tr><td>${terH(l.localizacao || '—')}</td><td>${terH(l.codigo)}</td><td>${terH(l.descricao)}</td><td>${terH(l.unidade)}</td>${CNT.revelar && gest || !aberto ? `<td>${cntNum(l.rodadas && l.rodadas.length ? l.rodadas[l.rodadas.length - 1].ref : l.saldo_congelado)}</td>` : ''}<td>${acao}${loc != null ? ' <small title="guardado neste aparelho">⏳</small>' : ''}</td>${(CNT.revelar && gest) || !aberto ? `<td>${dif == null ? '—' : (dif > 0 ? '+' : '') + cntNum(dif)}</td>` : ''}<td><span class="prt-badge ${cls}">${t}</span></td></tr>`;
  }).join('');
  const ver = (CNT.revelar && gest) || !aberto;
  terEl('cntConteudo').innerHTML = `<div id="cntTela"><div id="cntFila" style="margin-bottom:8px"></div><button class="button ghost" onclick="CNT.cur=null;CNT.revelar=false;contagensInit()">← Voltar</button>
    <h2 style="margin:10px 0 2px">${terH(c.id)} · ${terH(c.nome)} ${r.offline ? '<span class="prt-badge warn">offline</span>' : ''}</h2>
    <p class="muted">${rs.contados}/${rs.total} contados · ${rs.recontar} a recontar · ${rs.aprovacao} aguardam gestor${aberto ? '' : ' · ' + (CNT_CS[c.status] || [c.status])[0]}</p>
    <div class="filterrow"><label class="searchbox"><span>🔎</span><input id="cntBusca" type="search" placeholder="Buscar código, material ou endereço…" value="${terH(CNT.filtro)}"></label><label><input type="checkbox" id="cntSo" ${so ? 'checked' : ''}> só o que falta</label>
      ${gest && aberto ? `<button class="button ghost" onclick="CNT.revelar=!CNT.revelar;cntRecarregarAtual()">${CNT.revelar ? '🙈 Ocultar saldo' : '👁 Ver saldo do sistema (gestor)'}</button>` : ''}
      ${gest && aberto ? '<button class="button primary" onclick="cntAplicar()">✔ Encerrar e atualizar o sistema</button><button class="button ghost" onclick="cntCancelar()">Cancelar contagem</button>' : ''}
      <button class="button ghost" onclick="window.open('api.php?action=contagens_folha&id=${c.id}','_blank')">🖨 Folha</button></div>
    ${aberto && !gest && terPode('aprovar') === false ? '<p class="muted">Você conta; o ajuste no estoque é feito pelo gestor depois da conferência.</p>' : ''}
    <div class="table-wrap"><table class="orders-table"><thead><tr><th>Endereço</th><th>Código</th><th>Material</th><th>Un.</th>${ver ? '<th>Sistema</th>' : ''}<th>Contado</th>${ver ? '<th>Diferença</th>' : ''}<th>Situação</th></tr></thead><tbody>${linhas || '<tr><td colspan="8"><div class="empty">Nada para mostrar.</div></td></tr>'}</tbody></table></div>
    ${c.historico && !aberto ? `<h3 style="margin-top:16px">Histórico</h3><ul>${c.historico.map(h => `<li>${terD(h.em)} · ${terH(h.por)} — ${terH(h.acao)}</li>`).join('')}</ul>` : ''}</div>`;
  terEl('cntBusca').oninput = e => { CNT.filtro = e.target.value; const p = e.target.selectionStart; cntTela(); const b = terEl('cntBusca'); b.focus(); b.setSelectionRange(p, p); };
  terEl('cntSo').onchange = e => { CNT.soPend = e.target.checked; cntTela(); };
  document.querySelectorAll('#cntTela .cnt-in').forEach(i => { i.onchange = () => cntSalvarItem(i.dataset.cod, i.value); i.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); const all = [...document.querySelectorAll('#cntTela .cnt-in')]; const n = all[all.indexOf(i) + 1]; i.blur(); n && n.focus(); } }; });
  document.querySelectorAll('#cntTela [data-dec]').forEach(b => b.onclick = () => cntDecidir(b.dataset.cod, b.dataset.dec));
  cntBadgeFila();
}
async function cntSalvarItem(cod, v) {
  v = String(v).trim().replace(',', '.'); if (v === '') return;
  if (isNaN(Number(v)) || Number(v) < 0) { terToast('Quantidade inválida.', true); return; }
  const cid = CNT.cur.contagem.id; CNT.cur.pend[cod] = v;
  await cntIdb.add('fila', { cid, codigo: cod, qtd: v, em: new Date().toISOString() }).catch(() => terToast('Não foi possível guardar no aparelho; contagem não salva.', true));
  await cntSync(false); await cntRecarregarAtual();
}
async function cntDecidir(cod, acao) {
  const bg = terModal(acao === 'aprovar' ? 'Aprovar ajuste de estoque' : 'Manter saldo do sistema', `<p class="muted">${terH(cod)} continuou divergente após a recontagem.</p><div class="rd-ass-campos"><label>Motivo (obrigatório para auditoria)<input name="mot" placeholder="Ex.: avaria, saída sem baixa, erro de lançamento"></label></div><div class="est-head-actions"><button class="button primary" data-ok>Confirmar</button></div>`, 480);
  bg.querySelector('[data-ok]').onclick = async () => { try { await terPost('contagens_decidir', { id: CNT.cur.contagem.id, codigo: cod, acao, motivo: terVal(bg, 'mot') }); bg.remove(); await cntRecarregarAtual(); } catch (e) { terToast(e.message, true); } };
}
async function cntAplicar() {
  const rs = CNT.cur.contagem.resumo, id = CNT.cur.contagem.id;
  const bg = terModal('Encerrar e atualizar o sistema', `<p>${rs.contados} de ${rs.total} itens contados. As diferenças viram <b>ajustes de inventário</b> no Estoque (com histórico; nada é apagado).${rs.pendente ? `<br><b>${rs.pendente} item(ns) não contado(s)</b> ficam como estão.` : ''}</p><div class="est-head-actions"><button class="button primary" data-ok>Aplicar ajustes</button></div>`, 520);
  bg.querySelector('[data-ok]').onclick = async () => { try { const r = await terPost('contagens_aplicar', { id, ignorar_pendentes: rs.pendente > 0 }); bg.remove(); terToast(`Estoque atualizado: ${r.ajustes} ajuste(s). Acuracidade ${cntPct(r.estatisticas.ira_itens)}.`); CNT.revelar = false; await cntAbrir(id); } catch (e) { bg.remove(); terToast(e.message, true); } };
}
async function cntCancelar() {
  const bg = terModal('Cancelar contagem', '<div class="rd-ass-campos"><label>Motivo<input name="mot"></label></div><p class="muted">Nada é alterado no estoque.</p><div class="est-head-actions"><button class="button primary" data-ok>Cancelar contagem</button></div>', 440);
  bg.querySelector('[data-ok]').onclick = async () => { try { await terPost('contagens_cancelar', { id: CNT.cur.contagem.id, motivo: terVal(bg, 'mot') }); bg.remove(); CNT.cur = null; contagensInit(); } catch (e) { terToast(e.message, true); } };
}
