/* Recebimento Drilling v3.7.1 — Cadastro manual de pedido de compra
 * Para o PC que não veio no ZIP do WhatsApp nem em PDF avulso: anotação de papel,
 * telefone, e-mail ou pedido urgente. Mesmo validador da importação, mesma base.
 * Nunca sobrescreve um PC existente (para isso existe a edição normal do pedido).
 */
'use strict';
const NP = { linhas: [], fornecedores: [], materiais: [] };

const npEl = id => document.getElementById(id);
const npEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const npNum = v => { const n = parseFloat(String(v ?? '').replace(/\./g, '').replace(',', '.')); return Number.isFinite(n) && n > 0 ? n : 0; };

function npAbrir() {
  const modal = npEl('npModal'); if (!modal) return;
  // sugestões aproveitando o que já existe na base (fornecedores e solicitantes)
  NP.fornecedores = [...new Set((items || []).map(i => (i.fornecedor || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
  NP.materiais = [...new Set((items || []).map(i => (i.material || '').trim()).filter(Boolean))].slice(0, 400);
  NP.linhas = [];
 const dl = npEl('npFornList');
 if (dl) dl.innerHTML = NP.fornecedores.slice(0, 300).map(f => `<option value="${npEsc(f)}"></option>`).join('');
  ['npPc', 'npFornecedor', 'npSolicitante', 'npObs', 'npMaterial', 'npNumeroNF', 'npSerieNF', 'npChave', 'npCnpj', 'npEmissao', 'npValor', 'npItensNF'].forEach(id => { const e = npEl(id); if (e) e.value = ''; });
  ['npOrigem', 'npChegada'].forEach(id => { const e = npEl(id); if (e) e.value = ''; });
  const dataEl = npEl('npData'); if (dataEl) dataEl.value = new Date().toISOString().slice(0, 10);
  npEl('npErro').textContent = '';
  npRenderLinhas();
  modal.classList.add('on');
  const pc = npEl('npPc'); if (pc) { pc.focus(); pc.value = npSugerirPc(); }
}
function npFechar() { const m = npEl('npModal'); if (m) m.classList.remove('on'); }

/** Próximo PC livre acima do maior já cadastrado (só sugestão, o usuário pode trocar). */
function npSugerirPc() {
  const pcs = (items || []).map(i => Number(i.pc)).filter(n => Number.isSafeInteger(n) && n > 0);
  return pcs.length ? String(Math.max(...pcs) + 1) : '';
}

function npLinha(desc = '', qtd = '', un = 'UN', cod = '') {
  NP.linhas.push({ id: 'item_' + (NP.linhas.length + 1), codigo: cod, descricao: desc, unidade: un, quantidade_pedida: qtd });
  npRenderLinhas();
}
function npRemover(i) { NP.linhas.splice(i, 1); NP.linhas.forEach((l, n) => l.id = 'item_' + (n + 1)); npRenderLinhas(); }
function npEditar(i, campo, valor) { if (NP.linhas[i]) NP.linhas[i][campo] = valor; }

function npRenderLinhas() {
  const host = npEl('npLinhas'); if (!host) return;
  host.innerHTML = NP.linhas.length ? NP.linhas.map((l, i) => `
    <tr>
      <td><input value="${npEsc(l.codigo)}" maxlength="20" placeholder="código" aria-label="Código do insumo ${i + 1}" onchange="npEditar(${i},'codigo',this.value)"></td>
      <td><input value="${npEsc(l.descricao)}" maxlength="600" placeholder="Descrição do material" aria-label="Descrição do insumo ${i + 1}" onchange="npEditar(${i},'descricao',this.value)"></td>
      <td><input value="${npEsc(l.unidade)}" maxlength="12" style="width:80px" placeholder="UN" aria-label="Unidade ${i + 1}" onchange="npEditar(${i},'unidade',this.value.toUpperCase())"></td>
      <td><input value="${npEsc(l.quantidade_pedida)}" style="width:100px" inputmode="decimal" placeholder="0" aria-label="Quantidade pedida ${i + 1}" onchange="npEditar(${i},'quantidade_pedida',this.value)"></td>
      <td><button type="button" class="icon-btn" aria-label="Remover insumo ${i + 1}" onclick="npRemover(${i})">✕</button></td>
    </tr>`).join('')
    : '<tr><td colspan="5" class="empty">Sem insumos. Use “+ Insumo” ou descreva o material no campo acima.</td></tr>';
  npAtualizarResumo();
}
function npAtualizarResumo() {
  const el = npEl('npResumo'); if (!el) return;
  const total = NP.linhas.reduce((s, l) => s + (npNum(l.quantidade_pedida) > 0 ? 1 : 0), 0);
  el.textContent = NP.linhas.length ? `${NP.linhas.length} insumo(s) · ${total} com quantidade informada` : 'Insumos opcionais: sem eles o pedido entra só com o texto do material.';
}

/** Aceita "1) FILTRO X" por linha, ou "QTD;DESCRIÇÃO", ou descrição solta. */
function npLerMaterial() {
  const txt = npEl('npMaterial')?.value || '';
  const achadas = [];
  for (const bruto of txt.split(/\r?\n/)) {
    const l = bruto.trim(); if (!l) continue;
    let m = l.match(/^(\d+(?:[.,]\d+)?)\s*\)\s*(.+)$/);
    if (!m) m = l.match(/^(\d+(?:[.,]\d+)?)\s*[;,\t]\s*(.+)$/);
    if (m) achadas.push({ qtd: m[1], desc: m[2].trim() });
    else achadas.push({ qtd: '', desc: l });
  }
  if (!achadas.length) { toast('Descreva ao menos uma linha de material.', true); return; }
  NP.linhas = achadas.map((a, i) => ({ id: 'item_' + (i + 1), codigo: '', descricao: a.desc.slice(0, 600), unidade: 'UN', quantidade_pedida: a.qtd }));
  npRenderLinhas();
  toast(`${NP.linhas.length} linha(s) convertida(s) em insumos. Confira as quantidades.`);
}

async function npSalvar() {
  const erro = npEl('npErro'); erro.textContent = '';
  const pc = parseInt(npEl('npPc').value, 10);
  const fornecedor = npEl('npFornecedor').value.trim();
  if (!Number.isSafeInteger(pc) || pc <= 0) { erro.textContent = 'Informe o número do PC (pedido).'; return; }
  if (fornecedor.length < 2) { erro.textContent = 'Informe o fornecedor do pedido.'; return; }
  const linhas = NP.linhas
    .map(l => ({ id: l.id, descricao: String(l.descricao || '').trim(), unidade: String(l.unidade || 'UN').trim().toUpperCase() || 'UN',
      quantidade_pedida: npNum(l.quantidade_pedida), quantidade_recebida: 0, conferido_etapa: false }))
    .filter(l => l.descricao.length >= 2);
  for (const l of linhas) if (!(l.quantidade_pedida > 0)) { erro.textContent = `Quantidade inválida em "${l.descricao.slice(0, 40)}". Informe um número maior que zero.`; return; }
  if (!linhas.length && !npEl('npMaterial').value.trim()) { erro.textContent = 'Cadastre ao menos um insumo ou descreva o material.'; return; }

  const corpo = {
    pc, fornecedor,
    solicitante: npEl('npSolicitante').value.trim(),
    obs: npEl('npObs').value.trim(),
    origem_entrega: npEl('npOrigem').value,
    chegou: npEl('npChegada').value,
    numeroNF: npEl('npNumeroNF').value.trim(), serieNF: npEl('npSerieNF').value.trim(),
    chaveNF: npEl('npChave').value.replace(/\D/g, ''), cnpjEmitente: npEl('npCnpj').value.replace(/\D/g, ''),
    emissaoNF: npEl('npEmissao').value.trim(), valorNF: npEl('npValor').value.trim(), itensNF: npEl('npItensNF').value.trim(),
  };
  if (linhas.length) corpo.insumos = linhas; else corpo.material = npEl('npMaterial').value.trim();
  if (corpo.chaveNF && corpo.chaveNF.length !== 44) { erro.textContent = 'A chave da NF deve ter exatamente 44 dígitos.'; return; }
  if (corpo.cnpjEmitente.length && corpo.cnpjEmitente.length !== 14) { erro.textContent = 'O CNPJ deve ter 14 dígitos.'; return; }

  const btn = npEl('npSalvar'); btn.disabled = true; btn.textContent = 'Cadastrando…';
  try {
    const r = await api('pc_create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
    npFechar();
    await load();
    toast(`PC ${r.item.pc} cadastrado manualmente.`);
    if (typeof openDrawer === 'function') { goPage('pedidos'); openDrawer(r.item.pc); }
  } catch (e) {
    erro.textContent = e.message || 'Não foi possível cadastrar o pedido.';
  } finally { btn.disabled = false; btn.textContent = 'Cadastrar pedido'; }
}

window.addEventListener('DOMContentLoaded', () => {
  const f = npEl('npForm');
  if (f) f.onsubmit = ev => { ev.preventDefault(); npSalvar(); };
  const m = npEl('npModal');
  if (m) m.addEventListener('click', ev => { if (ev.target === m) npFechar(); });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && m?.classList.contains('on')) npFechar(); });
});
