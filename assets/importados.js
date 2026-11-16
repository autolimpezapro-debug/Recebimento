/* Recebimento Drilling — Materiais críticos IMPORTADOS (v3.9.1)
 * Vitrine com foto, saldo, cobertura e histórico. Usa o motor do Estoque (mesmos movimentos,
 * kardex, permissões e indicadores). */
'use strict';
const IMP = { itens: [], meses: [], carga: null, filtro: 'todos', busca: '', atual: null, planilha: null };
const impEl = id => document.getElementById(id);
const impH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const impN = n => Number(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
const impD = s => s ? new Date(s.length <= 10 ? s + 'T12:00:00' : s).toLocaleDateString('pt-BR') : '—';
const IMP_COR = { 'SEM ESTOQUE': 'bad', COMPRAR: 'bad', FABRICAR: 'warn', 'ATENÇÃO': 'warn', OK: 'ok' };
const IMP_MES = m => new Date(m + '-15').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '');

async function impApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const q = opts.query ? '&' + new URLSearchParams(opts.query) : '';
  const r = await fetch(`api.php?action=${action}${q}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const impPost = (a, b) => impApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
function impToast(m, e) { if (typeof toast === 'function') toast(m, e); else alert(m); }

async function importadosInit() {
  try {
    const r = await impApi('estoque_importados');
    IMP.itens = r.itens; IMP.meses = r.meses; IMP.carga = r.carga;
    impRender();
  } catch (e) { impEl('impGrid').innerHTML = `<div class="empty">${impH(e.message)}</div>`; }
}

function impFoto(i) { return i.foto ? impH(i.foto) : ''; }

function impRender() {
  const t = IMP.itens;
  const cont = s => t.filter(i => s.includes(i.status)).length;
  impEl('impKpis').innerHTML = `
    <div class="imp-kpi"><span>Itens controlados</span><strong>${t.length}</strong></div>
    <div class="imp-kpi bad"><span>Sem estoque</span><strong>${cont(['SEM ESTOQUE'])}</strong></div>
    <div class="imp-kpi bad"><span>Comprar (China)</span><strong>${cont(['COMPRAR'])}</strong></div>
    <div class="imp-kpi warn"><span>Fabricar (bases)</span><strong>${cont(['FABRICAR'])}</strong></div>
    <div class="imp-kpi warn"><span>Atenção (prazo)</span><strong>${cont(['ATENÇÃO'])}</strong></div>
    <div class="imp-kpi ok"><span>OK</span><strong>${cont(['OK'])}</strong></div>`;
  impEl('impCargaBox').hidden = !!IMP.carga;
  impEl('impCargaInfo').textContent = IMP.carga ? `Histórico da planilha carregado em ${impD(IMP.carga.em)} · ${IMP.carga.movimentos} movimentos` : '';
  const q = IMP.busca.toLowerCase();
  const lista = t.filter(i => (IMP.filtro === 'todos' || (IMP.filtro === 'alerta' ? i.status !== 'OK' : i.status === IMP.filtro))
    && (!q || (i.nome + ' ' + i.codigo).toLowerCase().includes(q)));
  impEl('impGrid').innerHTML = lista.map(i => {
    const max = Math.max(i.recomendado, i.saldo, i.disparo, 1);
    const pct = Math.min(100, i.saldo / max * 100), pd = Math.min(100, i.disparo / max * 100);
    const cons = IMP.meses.map(m => i.consumo[m] || 0), cmax = Math.max(...cons, 1);
    return `<article class="imp-card ${IMP_COR[i.status]}" onclick="impAbrir('${impH(i.codigo)}')" tabindex="0">
      <div class="imp-foto">${i.foto ? `<img src="${impFoto(i)}" alt="${impH(i.nome)}" loading="lazy">` : '<span>📷<br>sem foto</span>'}
        <span class="imp-badge ${IMP_COR[i.status]}">${impH(i.status)}</span></div>
      <div class="imp-body">
        <small><code>${impH(i.codigo)}</code>${i.fabricavel ? ' · fabricação interna' : ' · importado China'}</small>
        <h3>${impH(i.nome)}</h3>
        <div class="imp-saldo"><strong>${impN(i.saldo)}</strong><span>${impH(i.unidade)} em estoque</span></div>
        <div class="imp-barra" title="Saldo × ponto de disparo × recomendado"><i style="width:${pct}%"></i><b style="left:${pd}%"></b></div>
        <div class="imp-legenda"><span>disparo ${impN(i.disparo)}</span><span>recomendado ${impN(i.recomendado)}</span></div>
        <div class="imp-meta">
          <div><small>Consumo/mês</small><b>${impN(i.media_mensal)}</b></div>
          <div><small>Cobertura</small><b>${i.cobertura_dias === null ? 'sem consumo' : i.cobertura_dias > 999 ? '+999 d' : i.cobertura_dias + ' d'}</b></div>
          <div><small>${i.status === 'OK' ? 'Pedir até' : 'Sugestão'}</small><b>${i.status === 'OK' ? impD(i.pedir_ate) : '+' + impN(i.sugestao)}</b></div>
        </div>
        <div class="imp-spark">${cons.map((v, k) => `<i style="height:${Math.max(3, v / cmax * 34)}px" title="${IMP_MES(IMP.meses[k])}: ${impN(v)}"></i>`).join('')}</div>
      </div></article>`;
  }).join('') || '<div class="empty">Nenhum item neste filtro.</div>';
}

function impFiltro(f) { IMP.filtro = f; document.querySelectorAll('[data-imp-f]').forEach(b => b.classList.toggle('active', b.dataset.impF === f)); impRender(); }

async function impAbrir(cod) {
  try {
    const r = await impApi('estoque_importado_item', { query: { codigo: cod } });
    IMP.atual = r.item; impFicha();
    impEl('impModal').classList.add('on');
  } catch (e) { impToast(e.message, true); }
}
function impFechar() { impEl('impModal').classList.remove('on'); IMP.atual = null; }

const IMP_TIPO = { SAIDA: 'Saída', ENTRADA_MANUAL: 'Entrada', AJUSTE_POSITIVO: 'Ajuste +', AJUSTE_NEGATIVO: 'Ajuste −', AJUSTE_INVENTARIO: 'Ajuste de saldo', BAIXA_PERDA: 'Perda', INVENTARIO_SAP: 'Inventário SAP', ENTRADA_RECEBIMENTO: 'Recebimento PC' };

function impFicha() {
  const i = IMP.atual, cons = IMP.meses.map(m => i.consumo[m] || 0), cmax = Math.max(...cons, 1);
  const par = i.par ? IMP.itens.find(x => x.codigo === i.par) : null;
  const obras = Object.entries(i.obras || {});
  const omax = Math.max(1, ...obras.map(o => o[1]));
  impEl('impFichaTitulo').textContent = i.nome;
  impEl('impFichaBody').innerHTML = `
  <div class="imp-ficha-top">
    <div class="imp-ficha-foto">${i.foto ? `<img src="${impFoto(i)}" alt="">` : '<span>📷 sem foto</span>'}
      <label class="button ghost small">📷 Trocar foto<input type="file" accept="image/*" hidden onchange="impEnviarFoto(this)"></label>
      ${i.foto_trocada && i.foto_original ? `<button type="button" class="button ghost small" onclick="impRestaurarFoto()">↺ Voltar à foto original da planilha</button>` : ''}</div>
    <div>
      <div class="imp-ficha-saldo"><span class="imp-badge ${IMP_COR[i.status]}">${impH(i.status)}</span><strong>${impN(i.saldo)}</strong> ${impH(i.unidade)}
        <small><code>${impH(i.codigo)}</code>${par ? ` · par: <a href="#" onclick="impAbrir('${impH(par.codigo)}');return false">${impH(par.nome)} (${impN(par.saldo)})</a>` : ''}</small></div>
      <div class="imp-meta big">
        <div><small>Consumo médio (3 meses)</small><b>${impN(i.media_mensal)}/mês</b></div>
        <div><small>Cobertura</small><b>${i.cobertura_dias === null ? 'sem consumo' : i.cobertura_dias + ' dias'}</b></div>
        <div><small>Ruptura prevista</small><b>${impD(i.ruptura_prevista)}</b></div>
        <div><small>${i.fabricavel ? 'Fabricar até' : 'Pedir à China até'}</small><b>${impD(i.pedir_ate)}</b></div>
      </div>
      <form class="imp-params" onsubmit="impSalvarParams(event)">
        <label class="imp-span2">Nome do item<input name="nome" value="${impH(i.nome)}" required></label>
        <label>Unidade<input name="unidade" value="${impH(i.unidade)}" maxlength="8"></label>
        <label>Localização<input name="localizacao" value="${impH(i.localizacao || '')}" placeholder="Ex.: gaiola 3"></label>
        <label>Ponto de disparo<input name="disparo" type="number" min="0" step="any" value="${i.disparo}"></label>
        <label>Estoque recomendado<input name="recomendado" type="number" min="0" step="any" value="${i.recomendado}"></label>
        <label>Prazo de reposição (dias)<input name="lead_time_dias" type="number" min="1" value="${i.lead_time_dias}"></label>
        <label class="chk"><input name="fabricavel" type="checkbox" ${i.fabricavel ? 'checked' : ''}> Fabricação interna</label>
        <button class="button ghost small">Salvar item</button>
      </form>
    </div>
  </div>
  <div class="imp-mov-form">
    <h4>Lançar movimentação</h4>
    <form onsubmit="impMovimentar(event)">
      <label>Situação<select name="situacao" onchange="impSituacao(this)">
        <option>ENVIO PARA OBRA</option><option>RESERVA PARA OBRA</option><option>COMPRA</option><option>FABRICAÇÃO</option><option>DEVOLUÇÃO</option>
        <option>AJUSTE +</option><option>AJUSTE -</option><option>PERDA</option><option value="CONTAGEM">CONTAGEM (corrigir saldo)</option></select></label>
      <label><span class="imp-qlabel">Quantidade</span><input name="quantidade" type="number" min="0" step="any" required inputmode="decimal"></label>
      <label>Obra<input name="obra" list="impObras" required placeholder="Ex.: SINOP-MT"></label>
      <label>Solicitante / responsável<input name="solicitante" placeholder="Nome"></label>
      <label class="imp-saida-only imp-os-sw full"><input type="checkbox" name="com_os" onchange="impOSMudou(this.form)"> Vincular a uma ordem de serviço (OS)</label>
      <label class="imp-saida-only imp-os-campo" hidden>Ordem de serviço (OS) *<input name="os" maxlength="40" placeholder="Ex.: 1234" autocomplete="off"></label>
      <label class="imp-saida-only imp-os-campo" hidden>Equipamento aplicado *<input name="equipamento" maxlength="120" list="impEquipLista" placeholder="Ex.: Core roller bits Ø1300 · PAT CO008" autocomplete="off"></label><datalist id="impEquipLista"></datalist>
      <label class="full">Observação<input name="observacao" placeholder="Ex.: caçamba Ø1300, NF, motivo…"></label>
      <button class="button primary">Registrar</button>
    </form>
  </div>
  <div class="imp-graficos">
    <div><h4>Consumo mensal (saídas)</h4><div class="imp-colunas">${cons.map((v, k) => `<div><i style="height:${Math.max(2, v / cmax * 110)}px"></i><b>${impN(v)}</b><small>${IMP_MES(IMP.meses[k])}</small></div>`).join('')}</div></div>
    <div><h4>Para onde foi (6 meses)</h4>${obras.length ? obras.map(([o, q]) => `<div class="imp-obra"><span>${impH(o)}</span><i style="width:${q / omax * 100}%"></i><b>${impN(q)}</b></div>`).join('') : '<p class="muted">Sem saídas.</p>'}</div>
  </div>
  <h4>Histórico de movimentações (${i.movimentos.length}) <small class="muted" style="font-weight:400">— use ✎ para corrigir data, quantidade, obra ou solicitante; 🗑 para excluir. Tudo fica registrado.</small></h4>
  <div class="est-table-scroll"><table class="orders-table imp-hist"><thead><tr><th>Data</th><th>Tipo</th><th>Qtd</th><th>Saldo</th><th>Obra</th><th>Solicitante</th><th>Observação</th><th></th></tr></thead><tbody>
  ${i.movimentos.map(m => { const ent = ['ENTRADA_MANUAL', 'AJUSTE_POSITIVO', 'ENTRADA_RECEBIMENTO'].includes(m.tipo); const sai = ['SAIDA', 'AJUSTE_NEGATIVO', 'BAIXA_PERDA'].includes(m.tipo);
    const ed = (m.edicoes || []).filter(e => e.acao === 'editar');
    const acoes = m.cancelado ? '' : `<button class="imp-mini" title="Editar" onclick="impEditarMov('${impH(m.id)}')">✎</button><button class="imp-mini" title="Excluir" onclick="impExcluirMov('${impH(m.id)}')">🗑</button>`;
    return `<tr class="${m.cancelado ? 'imp-cancelado' : ''}"><td>${impD(m.em)}</td><td>${impH(m.situacao || IMP_TIPO[m.tipo] || m.tipo)}${m.origem_planilha ? ' <small class="muted">planilha</small>' : ''}${ed.length ? ` <small class="imp-ed" title="${impH(ed.map(e => impD(e.em) + ' ' + e.por + ': ' + e.motivo).join(' | '))}">editado</small>` : ''}${m.cancelado ? `<br><small>excluído por ${impH(m.cancelado_por)}: ${impH(m.cancelado_motivo)}</small>` : ''}</td>
    <td class="${ent ? 'pos' : sai ? 'neg' : ''}">${ent ? '+' : sai ? '−' : ''}${impN(m.tipo === 'AJUSTE_INVENTARIO' ? m.saldo_posterior - m.saldo_anterior : m.quantidade)}</td>
    <td>${impN(m.saldo_posterior)}</td><td>${impH(m.obra || '')}</td><td>${impH(m.solicitante || '')}</td><td>${m.os ? `<b>OS ${impH(m.os)}</b>${m.equipamento ? ' · ' + impH(m.equipamento) : ''}<br>` : ''}${impH(m.observacao || '')}${m.assinatura_id ? ` <a href="#" onclick="rdAbrirComprovante('${impH(m.id)}');return false" title="Assinado por ${impH(m.recebedor || '')}">✍ comprovante</a>` : ''}</td><td class="imp-acoes">${acoes}</td></tr>`; }).join('')}
  </tbody></table></div>`;
  const obrasAll = new Set(); IMP.itens.forEach(x => Object.keys(x.obras || {}).forEach(o => obrasAll.add(o)));
  impEl('impObras').innerHTML = [...obrasAll].map(o => `<option value="${impH(o)}">`).join('');
  if (typeof estCarregarEquipamentos === 'function') estCarregarEquipamentos('impEquipLista');
}

async function impSalvarParams(ev) {
  ev.preventDefault(); const f = ev.target;
  try {
    const r = await impPost('estoque_importado_config', { codigo: IMP.atual.codigo, nome: f.nome.value, unidade: f.unidade.value, localizacao: f.localizacao.value,
      disparo: f.disparo.value, recomendado: f.recomendado.value, lead_time_dias: f.lead_time_dias.value, fabricavel: f.fabricavel.checked });
    IMP.atual = { ...r.item, movimentos: IMP.atual.movimentos }; impFicha(); impToast('Parâmetros salvos.'); importadosInit();
  } catch (e) { impToast(e.message, true); }
}

async function impMovimentar(ev) {
  ev.preventDefault(); const f = ev.target;
  const saida = ['ENVIO PARA OBRA', 'RESERVA PARA OBRA'].includes(f.situacao.value);
  let assinatura;
  const comOS = saida && f.com_os.checked;
  if (comOS && !f.os.value.trim()) return impToast('Informe a ordem de serviço (OS) da saída.', true);
  if (comOS && f.equipamento.value.trim().length < 2) return impToast('Informe o equipamento em que o material será aplicado.', true);
  if (saida) {
    assinatura = await rdAssinar({ nome: f.solicitante.value, resumo: `<b>${impN(f.quantidade.value)} ${impH(IMP.atual.unidade)}</b> · ${impH(IMP.atual.nome)}<br>Destino: <b>${impH(f.obra.value)}</b>${f.observacao.value ? ' · ' + impH(f.observacao.value) : ''}` });
    if (!assinatura) return;
  }
  try {
    const r = await impPost('estoque_importado_mov', { codigo: IMP.atual.codigo, situacao: f.situacao.value, quantidade: f.quantidade.value,
      obra: f.obra.value, solicitante: impSolic(f.solicitante.value, assinatura), observacao: f.observacao.value, assinatura,
      com_os: comOS, os: comOS ? f.os.value.trim() : '', equipamento: comOS ? f.equipamento.value.trim() : '' });
    IMP.atual = r.item; impFicha(); importadosInit();
    if (saida && confirm('Saída registrada com assinatura. Abrir o comprovante para imprimir/salvar?')) rdAbrirComprovante(r.movimento.id);
    else impToast('Movimentação registrada.');
  } catch (e) { impToast(e.message, true); }
}

async function impEnviarFoto(inp) {
  const f = inp.files[0]; if (!f) return;
  try {
    let imagem;
    if (/^image\/(jpeg|png|webp)$/.test(f.type) && f.size <= 2.8 * 1024 * 1024) {
      // Até 2,8 MB a foto vai exatamente como foi tirada (sem redimensionar nem recomprimir).
      imagem = await new Promise((ok, erro) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = erro; fr.readAsDataURL(f); });
    } else {
      const img = await createImageBitmap(f);
      const k = Math.min(1, 2400 / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      imagem = c.toDataURL('image/jpeg', 0.92);
    }
    const r = await impPost('estoque_importado_foto', { codigo: IMP.atual.codigo, imagem });
    IMP.atual.foto = r.foto; impFicha(); importadosInit(); impToast('Foto atualizada.');
  } catch (e) { impToast('Falha ao enviar a foto: ' + e.message, true); }
}

/* ---------- carga única da planilha CONTROLE IMPORTADOS ---------- */
async function impLerPlanilha(inp) {
  const f = inp.files[0]; if (!f) return;
  impEl('impCargaPrevia').innerHTML = '<p class="muted">Lendo a planilha…</p>';
  try {
    const X = await import('./xlsx-lib.js');
    const wb = X.read(await f.arrayBuffer(), { type: 'array', cellDates: true });
    const aba = n => { const k = wb.SheetNames.find(s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim() === n); return k ? X.utils.sheet_to_json(wb.Sheets[k], { header: 1, raw: true, defval: null }) : []; };
    const mov = aba('MOVIMENTACAO'), est = aba('ESTOQUE'), cons = aba('CONSUMO MENSAL');
    const iso = v => v instanceof Date ? v.toISOString().slice(0, 10) : (typeof v === 'number' ? new Date(Math.round((v - 25569) * 864e5)).toISOString().slice(0, 10) : String(v || ''));
    const hM = mov.findIndex(r => r && r.some(c => String(c || '').trim().toUpperCase() === 'PRODUTO'));
    const cM = mov[hM].map(c => String(c || '').trim().toUpperCase());
    const ix = n => cM.findIndex(c => c.startsWith(n));
    const movimentos = mov.slice(hM + 1).filter(r => r && r[ix('DATA')]).map(r => ({ data: iso(r[ix('DATA')]), tipo: r[ix('TIPO')], situacao: r[ix('SITUA')],
      produto: r[ix('PRODUTO')], quantidade: r[ix('QUANTIDADE')], obra: r[ix('OBRA')], solicitante: r[ix('SOLICITANTE')], observacao: r[ix('OBSERVA')] }))
      .filter(m => /^\d{4}-\d{2}-\d{2}$/.test(m.data));
    // Colunas sempre pelo nome do cabeçalho (a planilha tem colunas vazias à esquerda que a leitura descarta).
    const sem = v => String(v ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toUpperCase();
    const cabec = (m, nome) => { const h = m.findIndex(r => r && r.some(c => sem(c) === nome)); return h < 0 ? null : { h, col: n => m[h].findIndex(c => sem(c).startsWith(n)) }; };
    const nomeOk = v => typeof v === 'string' && /[A-Za-zÀ-ú]{3}/.test(v);
    const par = {};
    const cC = cabec(cons, 'ITEM');
    if (cC) { const ci = cC.col('ITEM'), cd = cC.col('PONTO DE DISPARO'), cr = cC.col('ESTOQUE RECOMENDADO');
      cons.slice(cC.h + 1).forEach(r => { if (r && nomeOk(r[ci])) par[sem(r[ci])] = { disparo: r[cd], recomendado: r[cr] }; }); }
    const cE = cabec(est, 'PRODUTO');
    if (!cE) throw new Error('Aba ESTOQUE sem a coluna PRODUTO.');
    const ep = cE.col('PRODUTO'), es = cE.col('SALDO FINAL'), ea = cE.col('ALERTA');
    const produtos = est.slice(cE.h + 1).filter(r => r && nomeOk(r[ep])).map(r => { const n = sem(r[ep]); return { nome: n, saldo: es >= 0 ? r[es] : null, alerta: ea >= 0 ? r[ea] : '', ...(par[n] || {}) }; });
    if (!produtos.length) throw new Error('Nenhum produto encontrado na aba ESTOQUE.');
    IMP.planilha = { produtos, movimentos, arquivo: f.name };
    const r = await impPost('estoque_importados_previa', IMP.planilha);
    const p = r.previa;
    impEl('impCargaPrevia').innerHTML = `<p><b>${p.produtos}</b> produtos · <b>${p.movimentos}</b> movimentações de ${impD(p.periodo?.[0])} a ${impD(p.periodo?.[1])}.
      O saldo final de cada item fica igual à aba ESTOQUE; diferenças viram um ajuste registrado no histórico.</p>
      <div class="est-table-scroll"><table class="orders-table"><thead><tr><th></th><th>Código</th><th>Produto</th><th>Soma dos movimentos</th><th>Saldo da planilha</th><th>Ajuste</th><th>Saldo SAP hoje</th></tr></thead><tbody>
      ${p.linhas.map(l => `<tr><td>${l.foto ? `<img src="assets/importados/${impH(l.foto)}" style="width:42px;height:42px;object-fit:cover;border-radius:6px">` : ''}</td><td><code>${impH(l.codigo)}</code>${l.sap ? '' : ' <small class="muted">novo</small>'}</td><td>${impH(l.nome)}</td>
        <td>${impN(l.soma_movimentos)}</td><td><b>${impN(l.saldo_planilha)}</b></td><td>${l.ajuste ? `<span class="est-badge warn">${l.ajuste > 0 ? '+' : ''}${impN(l.ajuste)}</span>` : '—'}</td><td>${impN(l.saldo_sistema_hoje)}</td></tr>`).join('')}
      </tbody></table></div>
      <p class="muted">Itens com código SAP (CO…) passam a ser controlados por este módulo; a importação do inventário SAP não altera mais o saldo deles.</p>
      <button class="button primary" onclick="impConfirmarCarga()">Confirmar e carregar histórico</button>`;
  } catch (e) { impEl('impCargaPrevia').innerHTML = `<p class="muted">Não foi possível ler: ${impH(e.message)}</p>`; }
}

async function impConfirmarCarga() {
  if (!IMP.planilha || !confirm('Carregar a planilha de importados agora?')) return;
  try {
    await impPost('estoque_importados_carga', { ...IMP.planilha, confirmo: true });
    impEl('impCargaPrevia').innerHTML = ''; impToast('Histórico de importados carregado.'); importadosInit();
  } catch (e) { impToast(e.message, true); }
}

/* Se quem assinou é o mesmo que foi digitado como solicitante, usa o nome oficial do cadastro. */
function impSolic(digitado, ass) {
  if (!ass) return digitado;
  const d = rdNormNome(digitado), n = rdNormNome(ass.nome);
  return !d || n.startsWith(d) || d.split(' ').every(p => n.includes(p)) ? ass.nome : digitado;
}

function impOSMudou(f) {
  const on = f.com_os && f.com_os.checked && f.obra.required;
  f.querySelectorAll('.imp-os-campo').forEach(l => { l.hidden = !on; });
}
function impSituacao(sel) {
  const f = sel.form, cont = sel.value === 'CONTAGEM';
  f.obra.required = ['ENVIO PARA OBRA', 'RESERVA PARA OBRA'].includes(sel.value);
  f.querySelectorAll('.imp-saida-only').forEach(l => { l.hidden = !f.obra.required; });
  impOSMudou(f);
  f.querySelector('.imp-qlabel').textContent = cont ? `Saldo contado (hoje: ${impN(IMP.atual.saldo)})` : 'Quantidade';
  f.observacao.required = cont;
  f.observacao.placeholder = cont ? 'Motivo obrigatório: ex. contagem física 12/11' : 'Ex.: caçamba Ø1300, NF, motivo…';
}

async function impRestaurarFoto() {
  if (!confirm('Voltar a usar a foto original do catálogo (planilha)?')) return;
  try { const r = await impPost('estoque_importado_config', { codigo: IMP.atual.codigo, restaurar_foto: true }); IMP.atual = { ...r.item, movimentos: IMP.atual.movimentos }; impFicha(); importadosInit(); }
  catch (e) { impToast(e.message, true); }
}

/* ---------- edição / exclusão de movimentações (auditadas) ---------- */
function impEditarMov(id) {
  const m = IMP.atual.movimentos.find(x => x.id === id); if (!m) return;
  const ajuste = ['AJUSTE_INVENTARIO', 'INVENTARIO_SAP'].includes(m.tipo);
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<form class="rd-ass"><h2>Editar movimentação</h2>
    <p class="muted" style="font-size:12px">${impH(m.situacao || IMP_TIPO[m.tipo] || m.tipo)} · ${impD(m.em)} · ${impH(m.id)}${m.assinatura_id ? '<br><b>Saída assinada: não pode ser alterada. Exclua e lance novamente com nova assinatura.</b>' : ''}${ajuste ? '<br>Ajuste de saldo: só a observação pode ser alterada. Para mudar o valor, exclua e faça uma nova contagem.' : ''}</p>
    <div class="rd-ass-campos">
      <label>Data<input name="data" type="date" value="${String(m.em).slice(0, 10)}" max="${new Date().toISOString().slice(0, 10)}" ${ajuste || m.assinatura_id ? 'disabled' : ''}></label>
      <label>Quantidade<input name="quantidade" type="number" min="0.01" step="any" value="${m.quantidade}" ${ajuste || m.assinatura_id ? 'disabled' : ''}></label>
      <label>Obra<input name="obra" list="impObras" value="${impH(m.obra || '')}" ${ajuste || m.assinatura_id ? 'disabled' : ''}></label>
      <label>Solicitante<input name="solicitante" value="${impH(m.solicitante || '')}" ${ajuste || m.assinatura_id ? 'disabled' : ''}></label>
    </div>
    <label style="display:grid;gap:4px;font-size:11px;color:#b5c6dc">Observação<input name="observacao" value="${impH(m.observacao || '')}" ${m.assinatura_id ? 'disabled' : ''}></label>
    <label style="display:grid;gap:4px;font-size:11px;color:#b5c6dc">Motivo da alteração (obrigatório)<input name="motivo" required minlength="3" placeholder="Ex.: quantidade digitada errada na planilha"></label>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x>Cancelar</button><button class="button primary" ${m.assinatura_id ? 'disabled' : ''}>Salvar alteração</button></div></form>`;
  document.body.appendChild(bg);
  bg.querySelector('[data-x]').onclick = () => bg.remove();
  bg.querySelector('form').onsubmit = async ev => {
    ev.preventDefault(); const f = ev.target; const dados = { id, acao: 'editar', motivo: f.motivo.value, observacao: f.observacao.value };
    if (!ajuste) Object.assign(dados, { data: f.data.value, quantidade: f.quantidade.value, obra: f.obra.value, solicitante: f.solicitante.value });
    try { const r = await impPost('estoque_importado_mov_editar', dados); bg.remove(); IMP.atual = r.item; impFicha(); importadosInit(); impToast('Movimentação alterada. Saldo recalculado: ' + impN(r.item.saldo)); }
    catch (e) { impToast(e.message, true); }
  };
}
async function impExcluirMov(id) {
  const m = IMP.atual.movimentos.find(x => x.id === id); if (!m) return;
  const motivo = prompt(`Excluir a movimentação de ${impD(m.em)} (${impH(m.situacao || m.tipo)} ${impN(m.quantidade)})?\nO saldo será recalculado. Informe o motivo:`);
  if (!motivo || motivo.trim().length < 3) return;
  try { const r = await impPost('estoque_importado_mov_editar', { id, acao: 'excluir', motivo }); IMP.atual = r.item; impFicha(); importadosInit(); impToast('Movimentação excluída. Saldo recalculado: ' + impN(r.item.saldo)); }
  catch (e) { impToast(e.message, true); }
}
