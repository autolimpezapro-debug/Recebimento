/* Recebimento Drilling — Empréstimo de ferramentas (v3.10.0) */
'use strict';
const FER = { painel: null, aba: 'abertos', q: '', cat: '', carrinho: {}, hist: [], histStatus: '', histQ: '' };
const ferEl = id => document.getElementById(id);
const ferH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ferD = s => s ? new Date(s.length <= 10 ? s + 'T12:00:00' : s).toLocaleDateString('pt-BR') : '—';
const ferDH = s => s ? new Date(s).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const ferMoeda = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const FER_SIT = { 'DISPONÍVEL': 'ok', PARCIAL: 'warn', EMPRESTADA: 'bad', 'MANUTENÇÃO': 'warn', 'SEM SALDO': 'bad', BAIXADA: '' };

async function ferApi(action, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (window.rdAuthCsrf) headers['X-RD-CSRF'] = window.rdAuthCsrf;
  const q = opts.query ? '&' + new URLSearchParams(opts.query) : '';
  const r = await fetch(`api.php?action=${action}${q}`, { cache: 'no-store', credentials: 'same-origin', ...opts, headers });
  let d; try { d = await r.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!r.ok || !d.ok) throw new Error(d.error || `Erro ${r.status}`);
  return d;
}
const ferPost = (a, b) => ferApi(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
const ferToast = (m, e) => (typeof toast === 'function' ? toast(m, e) : alert(m));
function ferComprovante(id) { window.open('api.php?action=ferramentas_comprovante&id=' + encodeURIComponent(id), '_blank', 'noopener'); }

async function ferramentasInit() {
  try {
    FER.painel = await ferApi('ferramentas_painel');
    // v3.19.1: cadastro vazio → traz as ferramentas do estoque (SKU FE) automaticamente
    if (!(FER.painel.ferramentas || []).length && !FER.puxouAuto) { FER.puxouAuto = true; try { await ferPost('ferramentas_puxar_estoque', {}); FER.painel = await ferApi('ferramentas_painel'); } catch (e) { } }
    ferRender();
  }
  catch (e) { ferEl('ferConteudo').innerHTML = `<div class="empty">${ferH(e.message)}</div>`; }
}

function ferAba(a) { FER.aba = a; document.querySelectorAll('[data-fer-aba]').forEach(b => b.classList.toggle('active', b.dataset.ferAba === a)); ferRender(); }

function ferRender() {
  const p = FER.painel, k = p.kpis;
  ferEl('ferKpis').innerHTML = `
    <div class="imp-kpi"><span>Ferramentas</span><strong>${k.ferramentas}</strong><small>${k.unidades} unidade(s)</small></div>
    <div class="imp-kpi ok"><span>Disponíveis</span><strong>${k.disponiveis}</strong></div>
    <div class="imp-kpi"><span>Emprestadas</span><strong>${k.emprestadas}</strong><small>${k.emprestimos_abertos} empréstimo(s)</small></div>
    <div class="imp-kpi ${k.atrasados ? 'bad' : ''}"><span>Atrasados</span><strong>${k.atrasados}</strong></div>
    <div class="imp-kpi warn"><span>Em manutenção</span><strong>${k.manutencao}</strong></div>
    <div class="imp-kpi"><span title="Soma do valor cadastrado de cada ferramenta ativa (valor × quantidade). Ferramentas sem valor entram como R$ 0.">Patrimônio em ferramentas</span><strong style="font-size:18px">${ferMoeda(k.valor)}</strong></div>`;
  const ps = FER.painel.por_setor || [];
  ferEl('ferKpis').insertAdjacentHTML('beforeend', ps.length ? `<div class="fer-setores"><span>Emprestadas agora por destino:</span>${ps.map(s => `<b title="${s.emprestimos} empréstimo(s)">${ferH(s.destino)} · ${s.unidades}</b>`).join('')}</div>` : '');
  if (FER.aba === 'abertos') return ferRenderAbertos();
  if (FER.aba === 'ferramentas') return ferRenderFerramentas();
  return ferCarregarHistorico();
}

function ferLinhaEmp(e) {
  return `<tr class="${e.atrasado ? 'fer-atrasado' : ''}"><td><code>${ferH(e.id)}</code><br><small class="muted">${ferDH(e.criado_em)}</small></td>
    <td><b>${ferH(e.responsavel)}</b>${e.terceiro ? ' <span class="est-badge warn">terceiro</span>' : ''}<br><small class="muted">${ferH(e.obra)}</small></td>
    <td>${e.itens.map(i => `${i.qtd - i.devolvido > 0 ? '' : '<s>'}${i.qtd}× ${ferH(i.nome)}${i.qtd - i.devolvido > 0 ? '' : '</s>'}`).join('<br>')}</td>
    <td>${ferD(e.previsto)}${e.atrasado ? `<br><span class="est-badge bad">${e.dias_atraso} dia(s) de atraso</span>` : ''}</td>
    <td><span class="est-badge ${e.status === 'DEVOLVIDO' ? 'ok' : e.status === 'PARCIAL' ? 'warn' : ''}">${{ ABERTO: 'Em aberto', PARCIAL: 'Parcial', DEVOLVIDO: 'Devolvido' }[e.status]}</span></td>
    <td class="est-actions">${e.status !== 'DEVOLVIDO' ? `<button class="button primary small" onclick="ferDevolver('${ferH(e.id)}')">Devolver</button>` : ''}
    <button class="button ghost small" onclick="ferComprovante('${ferH(e.id)}')">Termo</button></td></tr>`;
}

function ferRenderAbertos() {
  const l = FER.painel.abertos;
  ferEl('ferConteudo').innerHTML = `<div class="table-panel"><div class="table-scroll"><table class="orders-table">
    <thead><tr><th>Empréstimo</th><th>Responsável / destino</th><th>Ferramentas</th><th>Devolver até</th><th>Status</th><th></th></tr></thead>
    <tbody>${l.map(ferLinhaEmp).join('') || '<tr><td colspan="6" class="empty">Nenhuma ferramenta emprestada no momento.</td></tr>'}</tbody></table></div></div>`;
}

function ferFiltradas() {
  const q = FER.q.toLowerCase();
  return FER.painel.ferramentas.filter(f => (!FER.cat || f.categoria === FER.cat)
    && (!q || [f.codigo, f.nome, f.marca, f.modelo, f.serie, f.patrimonio].join(' ').toLowerCase().includes(q)));
}

function ferRenderFerramentas() {
  const p = FER.painel;
  ferEl('ferConteudo').innerHTML = `<div class="fun-top"><input type="search" placeholder="Buscar código, nome, série, patrimônio" value="${ferH(FER.q)}" oninput="FER.q=this.value;ferRenderListaFer()">
    <select onchange="FER.cat=this.value;ferRenderListaFer()"><option value="">Todas as categorias</option>${p.categorias.map(c => `<option ${c === FER.cat ? 'selected' : ''}>${ferH(c)}</option>`).join('')}</select>
    <span style="flex:1"></span><button class="button ghost" onclick="ferPuxarEstoque()" title="Acrescenta as ferramentas do estoque (SKU FE) que ainda não estão no cadastro">⇩ Puxar do estoque</button><label class="button ghost">⇧ Importar planilha<input type="file" accept=".xlsx,.xls,.csv" hidden onchange="ferImportar(this)"></label>
    <button class="button primary" onclick="ferEditar()">＋ Nova ferramenta</button></div><div id="ferImpPrevia"></div><div class="fer-grid" id="ferLista"></div>`;
  ferRenderListaFer();
}
function ferRenderListaFer() {
  ferEl('ferLista').innerHTML = ferFiltradas().map(f => `<article class="fer-card ${f.ativo ? '' : 'fer-baixada'}" onclick="ferFicha('${ferH(f.id)}')">
    <div class="fer-foto">${(f.fotos && f.fotos[0]) || f.foto ? `<img src="${ferH((f.fotos && f.fotos[0]) || f.foto)}" alt="" loading="lazy">` : '<span>🔧</span>'}${f.fotos && f.fotos.length > 1 ? `<em class="fer-nfotos">${f.fotos.length} 📷</em>` : ''}</div>
    <div><small><code>${ferH(f.codigo)}</code>${f.categoria ? ' · ' + ferH(f.categoria) : ''}</small><h3>${ferH(f.nome)}</h3>
    <small class="muted">${ferH([f.marca, f.modelo, f.serie && 'Série ' + f.serie, f.patrimonio && 'Pat. ' + f.patrimonio].filter(Boolean).join(' · '))}</small>
    <div class="fer-qtd"><span><b>${f.disponivel}</b> disp.</span><span><b>${f.emprestado}</b> emprest.</span>${f.manutencao ? `<span><b>${f.manutencao}</b> manut.</span>` : ''}<span class="muted">de ${f.quantidade}</span></div>
    <span class="imp-badge ${FER_SIT[f.situacao] || ''}">${ferH(f.situacao)}</span></div></article>`).join('')
    || '<div class="empty">Nenhuma ferramenta cadastrada. Use “Nova ferramenta” ou “Importar planilha”.</div>';
}

async function ferCarregarHistorico() {
  ferEl('ferConteudo').innerHTML = `<div class="fun-top"><input type="search" placeholder="Buscar responsável, obra, ferramenta" value="${ferH(FER.histQ)}" onchange="FER.histQ=this.value;ferCarregarHistorico()">
    <select onchange="FER.histStatus=this.value;ferCarregarHistorico()">${[['', 'Todos'], ['ABERTOS', 'Em aberto'], ['ATRASADOS', 'Atrasados'], ['DEVOLVIDOS', 'Devolvidos']].map(([v, l]) => `<option value="${v}" ${v === FER.histStatus ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
    <div class="table-panel"><div class="table-scroll"><table class="orders-table"><thead><tr><th>Empréstimo</th><th>Responsável / destino</th><th>Ferramentas</th><th>Devolver até</th><th>Status</th><th></th></tr></thead><tbody id="ferHist"><tr><td colspan="6" class="empty">Carregando…</td></tr></tbody></table></div></div>`;
  try {
    const r = await ferApi('ferramentas_emprestimos', { query: { status: FER.histStatus, q: FER.histQ } });
    ferEl('ferHist').innerHTML = r.emprestimos.map(ferLinhaEmp).join('') || '<tr><td colspan="6" class="empty">Nada encontrado.</td></tr>';
  } catch (e) { ferEl('ferHist').innerHTML = `<tr><td colspan="6" class="empty">${ferH(e.message)}</td></tr>`; }
}

/* ---------- modal genérico ---------- */
function ferModal(titulo, html, largura) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="${largura ? 'width:min(' + largura + 'px,100%)' : ''}"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div>${html}</div>`;
  document.body.appendChild(bg);
  bg.querySelector('[data-x]').onclick = () => bg.remove();
  return bg;
}

/* ---------- novo empréstimo ---------- */
function ferNovoEmprestimo() {
  FER.carrinho = {};
  const prev = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10);
  const bg = ferModal('Novo empréstimo de ferramentas', `
    <div class="rd-ass-campos"><label>Setor*<select name="setor" required><option value="">Selecione…</option>${Object.entries(FER.painel.setores || {}).map(([k, n]) => `<option value="${k}">${ferH(n)}</option>`).join('')}</select></label>
    <label class="fer-obra" hidden>Qual obra?*<select name="obra_id"><option value="">Selecione a obra…</option>${(FER.painel.obras || []).map(o => `<option value="${ferH(o.id)}">${ferH(o.name)}${o.city ? ' — ' + ferH(o.city) : ''}</option>`).join('')}<option value="__outra">Outra obra (digitar)…</option></select></label>
    <label class="fer-obra-outra" hidden>Nome da obra*<input name="obra_nome" placeholder="Nome da obra"></label>
    <label>Devolver até<input name="previsto" type="date" required value="${prev}" min="${new Date().toISOString().slice(0, 10)}"></label></div>
    <label style="display:grid;gap:4px;font-size:11px;color:#b5c6dc">Finalidade (opcional)<input name="finalidade" placeholder="Ex.: manutenção da perfuratriz"></label>
    <input type="search" class="fer-busca" placeholder="Buscar ferramenta por nome, código ou série">
    <div class="fer-escolha"></div><div class="fer-carrinho"></div>
    <div class="fer-fotos-emp"><span>Fotos das ferramentas na saída (opcional, até 3)</span><div class="fer-slots">${[0, 1, 2].map(i => ferSlot(i, '')).join('')}</div></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Seguir para assinatura</button></div>`, 820);
  const fotosEmp = ['', '', ''];
  ferSlotsLigar(bg.querySelector('.fer-fotos-emp'), async (i, url) => { fotosEmp[i] = url; }, i => { fotosEmp[i] = ''; });
  const lista = bg.querySelector('.fer-escolha'), cart = bg.querySelector('.fer-carrinho');
  const selSetor = bg.querySelector('[name=setor]'), selObra = bg.querySelector('[name=obra_id]');
  const ajustaSetor = () => {
    const ehObra = selSetor.value === 'OBRA';
    bg.querySelector('.fer-obra').hidden = !ehObra;
    bg.querySelector('.fer-obra-outra').hidden = !(ehObra && selObra.value === '__outra');
  };
  selSetor.onchange = ajustaSetor; selObra.onchange = ajustaSetor;
  const disp = () => FER.painel.ferramentas.filter(f => f.ativo && f.disponivel > 0);
  const desenhar = () => {
    const q = bg.querySelector('.fer-busca').value.toLowerCase();
    lista.innerHTML = disp().filter(f => !q || [f.codigo, f.nome, f.serie, f.patrimonio].join(' ').toLowerCase().includes(q)).slice(0, 60).map(f => {
      const no = FER.carrinho[f.id] || 0;
      return `<div class="fer-op"><div><b>${ferH(f.nome)}</b><br><small class="muted"><code>${ferH(f.codigo)}</code> · ${f.disponivel - no} disponível(is)</small></div>
        <div class="fer-step"><button type="button" data-m="${ferH(f.id)}">−</button><b>${no}</b><button type="button" data-p="${ferH(f.id)}" ${no >= f.disponivel ? 'disabled' : ''}>＋</button></div></div>`;
    }).join('') || '<p class="muted">Nenhuma ferramenta disponível com esse filtro.</p>';
    const itens = Object.entries(FER.carrinho).filter(([, q]) => q > 0);
    cart.innerHTML = itens.length ? `<b>Selecionadas:</b> ${itens.map(([id, q]) => q + '× ' + ferH(FER.painel.ferramentas.find(f => f.id === id).nome)).join(' · ')}` : '<span class="muted">Nenhuma ferramenta selecionada.</span>';
  };
  lista.onclick = ev => {
    const p = ev.target.dataset.p, m = ev.target.dataset.m;
    if (p) FER.carrinho[p] = (FER.carrinho[p] || 0) + 1;
    if (m) FER.carrinho[m] = Math.max(0, (FER.carrinho[m] || 0) - 1);
    if (p || m) desenhar();
  };
  bg.querySelector('.fer-busca').oninput = desenhar;
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = async () => {
    const setor = selSetor.value, obraId = selObra.value === '__outra' ? '' : selObra.value, obraNome = bg.querySelector('[name=obra_nome]').value.trim();
    const obraTxt = setor === 'OBRA' ? (obraId ? (FER.painel.obras.find(o => o.id === obraId) || {}).name : obraNome) : (FER.painel.setores[setor] || '');
    const obra = setor ? (setor === 'OBRA' ? (obraTxt ? 'Obra · ' + obraTxt : '') : obraTxt) : '', previsto = bg.querySelector('[name=previsto]').value, finalidade = bg.querySelector('[name=finalidade]').value;
    const itens = Object.entries(FER.carrinho).filter(([, q]) => q > 0).map(([ferramenta_id, qtd]) => ({ ferramenta_id, qtd }));
    if (!itens.length) return ferToast('Escolha pelo menos uma ferramenta.', true);
    if (!setor) return ferToast('Escolha o setor de destino.', true);
    if (!obra) return ferToast('Escolha qual obra.', true);
    if (!previsto) return ferToast('Informe a data de devolução.', true);
    const nomes = itens.map(i => `<b>${i.qtd}×</b> ${ferH(FER.painel.ferramentas.find(f => f.id === i.ferramenta_id).nome)}`).join('<br>');
    const assinatura = await rdAssinar({ titulo: 'Assinatura de quem retira as ferramentas', resumo: `${nomes}<br>Destino: <b>${ferH(obra)}</b> · devolver até <b>${ferD(previsto)}</b><br><small>Ao assinar, a pessoa se responsabiliza pela guarda e devolução.</small>` });
    if (!assinatura) return;
    try {
      const r = await ferPost('ferramentas_emprestar', { itens, setor, obra_id: obraId, obra_nome: obraNome, previsto, finalidade, assinatura, fotos: fotosEmp.filter(Boolean) });
      bg.remove(); await ferramentasInit();
      if (confirm(`Empréstimo ${r.emprestimo.id} registrado. Abrir o termo assinado para imprimir/salvar?`)) ferComprovante(r.emprestimo.id);
    } catch (e) { ferToast(e.message, true); }
  };
  desenhar();
}

/* ---------- devolução ---------- */
async function ferDevolver(id) {
  let e = FER.painel.abertos.find(x => x.id === id);
  if (!e) { const r = await ferApi('ferramentas_emprestimos', { query: { q: id } }); e = r.emprestimos.find(x => x.id === id); }
  if (!e) return;
  const pend = e.itens.filter(i => i.qtd - i.devolvido > 0);
  const bg = ferModal('Devolução · ' + ferH(e.id), `<p class="muted">Responsável: <b>${ferH(e.responsavel)}</b> · ${ferH(e.obra)} · prevista ${ferD(e.previsto)}</p>
    ${pend.map(i => `<div class="fer-dev" data-id="${ferH(i.ferramenta_id)}"><div><b>${ferH(i.nome)}</b><br><small class="muted">pendente: ${i.qtd - i.devolvido}</small></div>
      <label>Qtd devolvida<input type="number" min="0" max="${i.qtd - i.devolvido}" value="${i.qtd - i.devolvido}"></label>
      <label>Estado<select><option value="OK">Em bom estado</option><option value="DANIFICADA">Danificada → manutenção</option><option value="INCOMPLETA">Incompleta / faltando acessório</option><option value="EXTRAVIADA">Extraviada / perdida</option></select></label>
      <label class="full">Observação<input placeholder="Obrigatória se não estiver OK"></label></div>`).join('')}
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-x2>Cancelar</button><button type="button" class="button primary" data-ok>Registrar devolução</button></div>`, 760);
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('[data-ok]').onclick = async () => {
    const itens = [...bg.querySelectorAll('.fer-dev')].map(r => ({ ferramenta_id: r.dataset.id, qtd: Number(r.querySelector('input[type=number]').value), estado: r.querySelector('select').value, observacao: r.querySelector('.full input').value }));
    try {
      const r = await ferPost('ferramentas_devolver', { emprestimo_id: id, itens });
      bg.remove(); ferToast(r.emprestimo.status === 'DEVOLVIDO' ? 'Devolução completa registrada.' : 'Devolução parcial registrada.'); ferramentasInit();
    } catch (er) { ferToast(er.message, true); }
  };
}

/* ---------- cadastro ---------- */
function ferEditar(id) {
  const f = FER.painel.ferramentas.find(x => x.id === id) || { quantidade: 1, unidade: 'UN' };
  const campo = (n, l, t = 'text', extra = '') => `<label>${l}<input name="${n}" type="${t}" value="${ferH(f[n] ?? '')}" ${extra}></label>`;
  const bg = ferModal(id ? 'Editar ferramenta' : 'Nova ferramenta', `<form class="fer-form">
    ${campo('nome', 'Nome / descrição', 'text', 'required')}${campo('codigo', 'Código / TAG (vazio = automático)')}
    ${campo('categoria', 'Categoria', 'text', 'list="ferCats"')}${campo('marca', 'Marca')}${campo('modelo', 'Modelo')}${campo('serie', 'Nº de série')}
    ${campo('patrimonio', 'Patrimônio')}${campo('quantidade', 'Quantidade', 'number', 'min="1" required')}${campo('unidade', 'Unidade')}
    ${campo('valor', 'Valor unitário (R$)', 'number', 'min="0" step="0.01"')}${campo('localizacao', 'Localização no almoxarifado')}
    <label class="full">Observação<input name="observacao" value="${ferH(f.observacao || '')}"></label>
    <div class="full fer-fotos-cad"><span>Fotos da ferramenta (até 3) — tire na hora pelo celular ou escolha da galeria</span><div class="fer-slots">${[0, 1, 2].map(i => ferSlot(i, (f.fotos || [])[i] || '')).join('')}</div></div>
    <datalist id="ferCats">${FER.painel.categorias.map(c => `<option value="${ferH(c)}">`).join('')}</datalist>
    <div class="rd-ass-acoes full"><button type="button" class="button ghost" data-x2>Cancelar</button><button class="button primary">Salvar</button></div></form>`, 760);
  // v3.27.5: fotos já no cadastro. Ficam pendentes até salvar e são enviadas logo depois.
  const fotos = [0, 1, 2].map(i => (f.fotos || [])[i] || ''), mudou = [false, false, false];
  ferSlotsLigar(bg.querySelector('.fer-fotos-cad'), async (i, url) => { fotos[i] = url; mudou[i] = true; }, async i => { fotos[i] = ''; mudou[i] = true; });
  bg.querySelector('[data-x2]').onclick = () => bg.remove();
  bg.querySelector('form').onsubmit = async ev => {
    ev.preventDefault();
    const dados = Object.fromEntries([...new FormData(ev.target)].filter(([k]) => !k.startsWith('fer_slot'))); dados.id = id || '';
    const btn = ev.target.querySelector('.button.primary'); btn.disabled = true; btn.textContent = 'Salvando…';
    try {
      const r = await ferPost('ferramentas_salvar', dados);
      const fid = (r.ferramenta && r.ferramenta.id) || id;
      if (mudou.some(Boolean) && fid) {
        // 1) troca no lugar / acrescenta no fim; 2) remove as apagadas, de trás para frente
        let n = (f.fotos || []).length;
        for (let i = 0; i < 3; i++) if (mudou[i] && fotos[i]) {
          const pos = i < n ? i : n;
          await ferPost('ferramentas_foto', { ferramenta_id: fid, imagem: fotos[i], posicao: pos });
          if (pos === n) n++;
        }
        for (let i = 2; i >= 0; i--) if (mudou[i] && !fotos[i] && i < (f.fotos || []).length) await ferPost('ferramentas_foto', { ferramenta_id: fid, remover: i });
      }
      bg.remove(); ferToast('Ferramenta salva.'); await ferramentasInit();
    } catch (e) { ferToast(e.message, true); btn.disabled = false; btn.textContent = 'Salvar'; }
  };
}

async function ferFicha(id) {
  try {
    const f = (await ferApi('ferramentas_item', { query: { id } })).ferramenta;
    const bg = ferModal(ferH(f.nome), `<div class="imp-ficha-top"><div class="imp-ficha-foto fer-galeria">${f.fotos.length ? `<img src="${ferH(f.fotos[0])}" alt="" data-grande>` : '<span style="font-size:48px">🔧</span>'}
      <div class="fer-slots">${[0, 1, 2].map(i => ferSlot(i, f.fotos[i] || '')).join('')}</div><small class="muted">Até 3 fotos · clique para trocar</small></div>
      <div><p><code>${ferH(f.codigo)}</code> · ${ferH(f.categoria || 'sem categoria')} · <span class="imp-badge ${FER_SIT[f.situacao] || ''}">${ferH(f.situacao)}</span></p>
      <p class="muted">${ferH([f.marca, f.modelo, f.serie && 'Série ' + f.serie, f.patrimonio && 'Patrimônio ' + f.patrimonio, f.localizacao && 'Local: ' + f.localizacao].filter(Boolean).join(' · '))}</p>
      <div class="imp-meta big"><div><small>Total</small><b>${f.quantidade}</b></div><div><small>Disponível</small><b>${f.disponivel}</b></div><div><small>Emprestado</small><b>${f.emprestado}</b></div><div><small>Manutenção</small><b>${f.manutencao || 0}</b></div></div>
      <div class="fer-acoes"><button class="button ghost small" data-ed>Editar</button>
        <button class="button ghost small" data-man="enviar" ${f.disponivel ? '' : 'disabled'}>Enviar p/ manutenção</button>
        <button class="button ghost small" data-man="retornar" ${f.manutencao ? '' : 'disabled'}>Voltou da manutenção</button>
        <button class="button ghost small" data-man="baixar">Baixar (descarte)</button></div></div></div>
      <h4>Empréstimos (${f.emprestimos.length})</h4><div class="est-table-scroll"><table class="orders-table"><thead><tr><th>Data</th><th>Responsável</th><th>Obra</th><th>Qtd</th><th>Dias</th><th>Status</th><th></th></tr></thead><tbody>
      ${f.emprestimos.map(e => `<tr><td>${ferD(e.em)}</td><td>${ferH(e.responsavel)}</td><td>${ferH(e.obra)}</td><td>${e.devolvido}/${e.qtd}</td><td>${e.dias}</td>
        <td>${e.status === 'DEVOLVIDO' ? 'Devolvido' : e.atrasado ? '<span class="est-badge bad">Atrasado</span>' : 'Em aberto'}${e.devolucoes.filter(d => d.estado !== 'OK').map(d => `<br><small>${ferH(d.estado)}: ${ferH(d.observacao)}</small>`).join('')}</td>
        <td><a href="#" onclick="ferComprovante('${ferH(e.id)}');return false">termo</a></td></tr>`).join('') || '<tr><td colspan="7" class="empty">Nunca emprestada.</td></tr>'}</tbody></table></div>
      <h4>Histórico</h4><div class="fer-hist">${f.historico.map(h => `<div><small>${ferDH(h.em)} · ${ferH(h.por)}</small> ${ferH(h.acao)}${h.detalhe ? ' — ' + ferH(h.detalhe) : ''}</div>`).join('')}</div>`, 980);
    bg.querySelector('[data-ed]').onclick = () => { bg.remove(); ferEditar(id); };
    bg.querySelectorAll('[data-man]').forEach(b => b.onclick = async () => {
      const acao = b.dataset.man;
      const qtd = Number(prompt('Quantidade:', '1')); if (!qtd) return;
      let observacao = prompt(acao === 'baixar' ? 'Motivo da baixa (obrigatório):' : 'Observação (opcional):', '') || '';
      const origem = acao === 'baixar' ? ((f.manutencao || 0) >= qtd ? 'manutencao' : 'disponivel') : undefined;
      try { await ferPost('ferramentas_manutencao', { ferramenta_id: id, acao, qtd, observacao, origem }); bg.remove(); await ferramentasInit(); ferFicha(id); }
      catch (e) { ferToast(e.message, true); }
    });
    ferSlotsLigar(bg.querySelector('.fer-galeria'),
      async (i, url) => { await ferPost('ferramentas_foto', { ferramenta_id: id, imagem: url, posicao: Math.min(i, f.fotos.length) }); bg.remove(); await ferramentasInit(); ferFicha(id); },
      async i => { if (!confirm('Remover esta foto?')) return false; await ferPost('ferramentas_foto', { ferramenta_id: id, remover: i }); bg.remove(); await ferramentasInit(); ferFicha(id); });
  } catch (e) { ferToast(e.message, true); }
}

/* ---------- importação ---------- */
async function ferImportar(inp) {
  const file = inp.files[0]; inp.value = ''; if (!file) return;
  try {
    const X = await import('./xlsx-lib.js');
    const wb = X.read(await file.arrayBuffer(), { type: 'array' });
    const m = X.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: true, defval: null });
    const norm = v => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const h = m.findIndex(r => r && r.some(c => /^(nome|descri|ferramenta|equipamento)/.test(norm(c))));
    if (h < 0) throw new Error('Não achei a coluna de nome/descrição.');
    const cab = m[h].map(norm);
    const col = (...p) => cab.findIndex(c => p.some(x => c.startsWith(x)));
    const ix = { nome: col('nome', 'descri', 'ferramenta', 'equipamento'), codigo: col('codigo', 'cod', 'tag'), categoria: col('categoria', 'tipo', 'grupo'),
      marca: col('marca', 'fabricante'), modelo: col('modelo'), serie: col('serie', 'n serie', 'numero de serie'), patrimonio: col('patrimonio'),
      quantidade: col('quant', 'qtd'), localizacao: col('local'), valor: col('valor', 'preco', 'custo') };
    const linhas = m.slice(h + 1).filter(r => r && r[ix.nome]).map(r => Object.fromEntries(Object.entries(ix).map(([k, i]) => [k, i >= 0 ? r[i] : null])));
    const r = await ferPost('ferramentas_importar', { linhas });
    const p = r.importacao;
    FER.importar = linhas;
    ferEl('ferImpPrevia').innerHTML = `<div class="panel" style="margin-bottom:14px"><b>${p.linhas}</b> ferramenta(s) na planilha · <b>${p.novas}</b> nova(s) · ${p.atualizadas} já cadastrada(s) (serão atualizadas)
      <div class="est-table-scroll" style="max-height:260px;margin:10px 0"><table class="orders-table"><thead><tr><th>Código</th><th>Nome</th><th>Categoria</th><th>Qtd</th><th>Série</th><th></th></tr></thead><tbody>
      ${p.amostra.map(l => `<tr><td>${ferH(l.codigo || 'auto')}</td><td>${ferH(l.nome)}</td><td>${ferH(l.categoria)}</td><td>${l.quantidade}</td><td>${ferH(l.serie)}</td><td>${l.existe ? 'atualizar' : 'nova'}</td></tr>`).join('')}</tbody></table></div>
      <button class="button primary" onclick="ferConfirmarImport()">Confirmar importação</button> <button class="button ghost" onclick="ferEl('ferImpPrevia').innerHTML=''">Cancelar</button></div>`;
  } catch (e) { ferToast('Não foi possível ler a planilha: ' + e.message, true); }
}
async function ferConfirmarImport() {
  try { await ferPost('ferramentas_importar', { linhas: FER.importar, confirmo: true }); ferToast('Ferramentas importadas.'); await ferramentasInit(); ferAba('ferramentas'); }
  catch (e) { ferToast(e.message, true); }
}

async function ferPuxarEstoque() {
  try { const r = await ferPost('ferramentas_puxar_estoque', {}); ferToast(r.novas ? `${r.novas} ferramenta(s) trazida(s) do estoque.` : 'Todas as ferramentas do estoque já estão no cadastro.'); ferramentasInit(); }
  catch (e) { ferToast(e.message, true); }
}

/* v3.21.0: até 3 fotos — por ferramenta e por empréstimo */
function ferSlot(i, url) {
  return `<label class="fer-slot ${url ? 'on' : ''}" data-slot="${i}" title="${url ? 'Trocar foto' : 'Adicionar foto'}">${url ? `<img src="${ferH(url)}" alt="">` : '<span>＋📷</span>'}
    <input type="file" accept="image/*" hidden>${url ? `<button type="button" class="fer-slot-x" title="Remover">✕</button>` : ''}</label>`;
}
async function ferComprimir(file) {
  const img = await createImageBitmap(file); const k = Math.min(1, 1280 / Math.max(img.width, img.height));
  const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
  c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
  let q = .84, url = c.toDataURL('image/jpeg', q); while (url.length > 1.9e6 && q > .4) { q -= .12; url = c.toDataURL('image/jpeg', q); }
  return url;
}
function ferSlotsLigar(box, aoEscolher, aoRemover) {
  box.querySelectorAll('.fer-slot').forEach(slot => {
    const i = +slot.dataset.slot, inp = slot.querySelector('input');
    inp.onchange = async () => {
      const f = inp.files[0]; if (!f) return;
      try { const url = await ferComprimir(f); await aoEscolher(i, url); if (document.body.contains(slot)) slot.outerHTML = ferSlot(i, url), ferSlotsLigar(box, aoEscolher, aoRemover); }
      catch (e) { ferToast('Foto: ' + e.message, true); }
    };
    const x = slot.querySelector('.fer-slot-x');
    if (x) x.onclick = async ev => { ev.preventDefault(); ev.stopPropagation();
      try { const r = await aoRemover(i); if (r === false) return; if (document.body.contains(slot)) { slot.outerHTML = ferSlot(i, ''); ferSlotsLigar(box, aoEscolher, aoRemover); } } catch (e) { ferToast(e.message, true); } };
  });
}
