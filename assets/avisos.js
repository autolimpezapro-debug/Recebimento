/* Recebimento Drilling v3.29.0 — Aviso de chegada: quem recebe o e-mail "seu pedido chegou".
 *  1) avisoEditor(i)      seletor no detalhe do pedido
 *  2) avisoTrava(pc)      última conferência ao finalizar o recebimento (nunca bloqueia por falha técnica)
 *  3) avisoPendencias()   quadro de PCs sem destinatário + e-mails que não saíram
 * Depende de app.js: API, api(), html(), toast(), items, normalized(). */
'use strict';

const AVISO_ST = {
  ok: ['🟢', 'Identificado pelo solicitante'], definido: ['🟢', 'Destinatário definido'], sugerido: ['🟡', 'Sugestão — confirme'],
  ambiguo: ['🟠', 'Mais de uma pessoa possível — escolha'], nenhum: ['🔴', 'Sem destinatário'], sem_aviso: ['⚪', 'Ninguém será avisado']
};
const avisoPost = o => ({ method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(o) });
async function avisoGet(action, params = {}) {
  const q = new URLSearchParams({ action, ...params });
  const r = await fetch(`${API}?${q}`, { cache: 'no-store', headers: { 'X-Client-Id': typeof notificationClientId !== 'undefined' ? notificationClientId : '' } });
  let v; try { v = await r.json(); } catch { throw Error('Resposta inválida da API'); }
  if (!r.ok || !v.ok) throw Error(v.error || `Erro ${r.status}`);
  return v;
}
/** Lista compartilhada de sugestões (digitar o nome e escolher). */
function avisoDatalist(contatos) {
  let dl = document.getElementById('avisoDL');
  if (!dl) { dl = document.createElement('datalist'); dl.id = 'avisoDL'; document.body.appendChild(dl); }
  dl.innerHTML = (contatos || []).map(c => `<option value="${html(c.nome)} · ${html(c.email)}"></option>`).join('');
}
function avisoAcharContato(valor, contatos) {
  const v = String(valor || '').trim().toLowerCase(); if (!v) return null;
  const m = contatos.find(c => `${c.nome} · ${c.email}`.toLowerCase() === v) || contatos.find(c => c.email.toLowerCase() === v);
  if (m) return m;
  const p = contatos.filter(c => c.nome.toLowerCase().includes(v));
  return p.length === 1 ? p[0] : null;     // nome parcial só vale se identificar uma pessoa só
}
function avisoChips(sel, attr) {
  return sel.map((d, k) => `<span class="aviso-chip">${html(d.nome)} <small>${html(d.email)}</small><button type="button" ${attr}="${k}" title="Remover" aria-label="Remover ${html(d.nome)}">✕</button></span>`).join('') || '<small class="muted">Ninguém escolhido.</small>';
}

/* ---------------------------------------------------------------- 1) detalhe do pedido */
function avisoEditor(i) {
  setTimeout(() => avisoMontar(i.pc), 0);
  return `<div class="aviso-ed" id="avisoEd${Number(i.pc)}"><small class="muted">Carregando…</small></div>`;
}
async function avisoMontar(pc) {
  const box = document.getElementById('avisoEd' + pc); if (!box) return;
  let r; try { r = await avisoGet('pc_avisar_info', { pc }); }
  catch (e) { box.innerHTML = `<small class="muted">Aviso de chegada indisponível: ${html(e.message)}</small>`; return; }
  const s = r.situacao;
  box._r = r; box._mod = false;
  box._sel = (s.destinatarios || []).map(d => ({ nome: d.nome, email: d.email }));
  box._origem = s.status === 'sugerido' ? 'observacao' : s.status === 'ok' ? 'solicitante' : 'manual';
  avisoDesenhar(box);
}
function avisoDesenhar(box) {
  const r = box._r, s = r.situacao, pc = r.pc, [ic, tx] = AVISO_ST[s.status] || ['', ''];
  const cands = (s.candidatos || []).filter(c => !box._sel.some(d => d.email === c.email));
  box.innerHTML = `<div class="aviso-st">${ic} <b>${html(tx)}</b>${s.motivo ? `<small class="muted"> — ${html(s.motivo)}</small>` : ''}</div>
    <div class="aviso-chips">${avisoChips(box._sel, 'data-rem')}</div>
    ${cands.length ? `<div class="aviso-cands"><small>Possíveis:</small> ${cands.map((c, j) => `<button type="button" class="button ghost small" data-cand="${j}">+ ${html(c.nome)}</button>`).join(' ')}</div>` : ''}
    <div class="aviso-add"><input list="avisoDL" data-in placeholder="Digite o nome para adicionar…" aria-label="Adicionar pessoa"><button type="button" class="button ghost small" data-add>+ Adicionar</button></div>
    <div class="aviso-acoes"><button class="button primary small" type="button" data-salvar>Salvar</button><button class="button ghost small" type="button" data-nenhum>Não avisar ninguém</button><button class="button ghost small" type="button" data-auto>Voltar ao automático</button></div>
    <small class="muted">Solicitante no pedido: ${html(r.solicitante || '— não informado —')}. Quando o recebimento for finalizado, essas pessoas recebem o e-mail.</small>`;
  avisoDatalist(r.contatos);
  const add = c => { if (!c) return toast('Pessoa não encontrada na lista. Cadastre em Administração → Avisos por e-mail.', true); if (!box._sel.some(d => d.email === c.email)) box._sel.push({ nome: c.nome, email: c.email }); box._mod = true; avisoDesenhar(box); };
  box.querySelectorAll('[data-rem]').forEach(b => b.onclick = () => { box._sel.splice(Number(b.dataset.rem), 1); box._mod = true; avisoDesenhar(box); });
  box.querySelectorAll('[data-cand]').forEach(b => b.onclick = () => add(cands[Number(b.dataset.cand)]));
  const inp = box.querySelector('[data-in]');
  const doAdd = () => { if (inp.value.trim()) add(avisoAcharContato(inp.value, r.contatos)); };
  inp.onchange = doAdd; box.querySelector('[data-add]').onclick = doAdd;
  const gravar = async corpo => {
    try {
      const res = await api('pc_avisar', avisoPost({ pc, ...corpo }));
      const idx = items.findIndex(x => x.pc === pc); if (idx >= 0) items[idx] = normalized(res.item);
      toast('Aviso de chegada salvo.'); avisoMontar(pc); avisoAtualizarBadge();
    } catch (e) { toast(e.message, true); }
  };
  box.querySelector('[data-salvar]').onclick = () => {
    if (!box._sel.length) return toast('Escolha pelo menos uma pessoa — ou use "Não avisar ninguém".', true);
    gravar({ emails: box._sel.map(d => d.email), origem: box._mod ? 'manual' : box._origem });
  };
  box.querySelector('[data-nenhum]').onclick = () => gravar({ nenhum: true });
  box.querySelector('[data-auto]').onclick = () => gravar({ emails: [] });
}

/* ---------------------------------------------------------------- 2) trava ao finalizar */
/** Retorna true para seguir com a finalização. Qualquer falha técnica aqui NUNCA impede o recebimento. */
/** v3.34.0: depois de finalizar, mostra o que aconteceu com o e-mail (ele sai logo após a resposta; consulta em ~3 s). */
async function avisoResultado(pc, desde) {
  for (const espera of [3000, 5000]) {
    await new Promise(r => setTimeout(r, espera));
    try {
      const u = (await avisoGet('pc_avisar_info', { pc })).ultimo_envio;
      if (!u || (desde && String(u.em) <= desde)) continue;
      if (u.enviado) { toast('✉ Aviso de chegada enviado para ' + (u.para || []).map(p => String(p).replace(/\s*<.*$/, '')).join(', ') + (u.fotos ? ` (com ${u.fotos} foto${u.fotos > 1 ? 's' : ''})` : '') + '.'); }
      else { toast('⚠ Nenhum e-mail de chegada foi enviado: ' + (u.motivo || 'motivo não informado') + ' — defina quem avisar no detalhe do pedido.', true); }
      return;
    } catch (e) { return; }
  }
  toast('⚠ Não foi possível confirmar o envio do e-mail de chegada. Confira em "✉ Avisos de chegada".', true);
}
async function avisoTrava(pc) {
  let r; try { r = await avisoGet('pc_avisar_info', { pc }); } catch (e) { return true; }
  const s = r.situacao;
  if (['definido', 'ok', 'sem_aviso'].includes(s.status)) return true;
  const it = (typeof items !== 'undefined' ? items.find(x => x.pc === pc) : null) || {};
  return new Promise(resolve => {
    const bg = document.createElement('div'); bg.className = 'modal-bg on'; bg.id = 'avisoTrava';
    let sel = (s.destinatarios || []).map(d => ({ nome: d.nome, email: d.email })), mod = false;
    const origem = s.status === 'sugerido' ? 'observacao' : 'manual';
    const fechar = v => { bg.remove(); resolve(v); };
    const gravar = async corpo => { try { await api('pc_avisar', avisoPost({ pc, ...corpo })); } catch (e) { toast('Não foi possível salvar o destinatário: ' + e.message, true); } };
    const desenhar = () => {
      const cands = (s.candidatos || []).filter(c => !sel.some(d => d.email === c.email));
      bg.innerHTML = `<div class="modal aviso-modal"><div class="modal-head"><div><h2>Quem está esperando este material?</h2><small>PC ${Number(pc)} · ${html(it.fornecedor || '')}</small></div></div>
        <div class="modal-body"><p>${s.status === 'sugerido' ? 'Encontrei um nome nas observações do pedido. <b>Confirme</b> para avisar:' : 'Este pedido não tem um solicitante identificado com segurança. Escolha quem deve receber o e-mail de que o material chegou:'}</p>
        <p class="muted" style="font-size:12px">${html(s.motivo || '')}</p>
        <div class="aviso-chips">${avisoChips(sel, 'data-rem')}</div>
        ${cands.length ? `<div class="aviso-cands"><small>Possíveis:</small> ${cands.map((c, j) => `<button type="button" class="button ghost small" data-cand="${j}">+ ${html(c.nome)}</button>`).join(' ')}</div>` : ''}
        <div class="aviso-add"><input list="avisoDL" data-in placeholder="Digite o nome para adicionar…" aria-label="Adicionar pessoa"><button type="button" class="button ghost small" data-add>+ Adicionar</button></div>
        <div class="aviso-acoes"><button class="button primary" type="button" data-ok ${sel.length ? '' : 'disabled'}>✉ Avisar e finalizar</button><button class="button ghost" type="button" data-sem>Finalizar sem avisar ninguém</button><button class="button ghost" type="button" data-cancel>Cancelar</button></div></div></div>`;
      avisoDatalist(r.contatos);
      const add = c => { if (!c) return toast('Pessoa não encontrada na lista.', true); if (!sel.some(d => d.email === c.email)) sel.push({ nome: c.nome, email: c.email }); mod = true; desenhar(); };
      bg.querySelectorAll('[data-rem]').forEach(b => b.onclick = () => { sel.splice(Number(b.dataset.rem), 1); mod = true; desenhar(); });
      bg.querySelectorAll('[data-cand]').forEach(b => b.onclick = () => add(cands[Number(b.dataset.cand)]));
      const inp = bg.querySelector('[data-in]'); const doAdd = () => { if (inp.value.trim()) add(avisoAcharContato(inp.value, r.contatos)); };
      inp.onchange = doAdd; bg.querySelector('[data-add]').onclick = doAdd;
      bg.querySelector('[data-ok]').onclick = async () => { await gravar({ emails: sel.map(d => d.email), origem: mod ? 'manual' : origem }); fechar(true); };
      bg.querySelector('[data-sem]').onclick = async () => { await gravar({ nenhum: true }); fechar(true); };
      bg.querySelector('[data-cancel]').onclick = () => fechar(false);
    };
    desenhar(); document.body.appendChild(bg);
  });
}

/* ---------------------------------------------------------------- 3) quadro de pendências */
async function avisoAtualizarBadge() {
  const b = document.getElementById('avisoBadge'); if (!b) return;
  try { const r = await avisoGet('avisar_pendencias'); b.textContent = r.total ? String(r.total) : ''; b.hidden = !r.total; }
  catch (e) { b.textContent = ''; b.hidden = true; }
}
async function avisoPendencias() {
  let r; try { r = await avisoGet('avisar_pendencias'); } catch (e) { return toast(e.message, true); }
  const bg = document.createElement('div'); bg.className = 'modal-bg on'; bg.id = 'avisoPend';
  bg.onclick = e => { if (e.target === bg) bg.remove(); };
  const quando = s => { try { return new Date(s).toLocaleString('pt-BR'); } catch { return ''; } };
  const linha = p => {
    const [ic, tx] = AVISO_ST[p.status] || ['', ''];
    const sug = (p.sugestao || []).map(d => d.nome).join(', ');
    return `<tr data-pc="${Number(p.pc)}"><td><b>PC ${Number(p.pc)}</b>${p.urgente ? ' <span title="Urgente">⚠</span>' : ''}</td><td>${html(p.fornecedor)}</td><td>${html(p.solicitante || '—')}</td>
      <td>${ic} ${html(tx)}${sug ? `<br><small>${html(sug)}</small>` : ''}</td>
      <td class="aviso-pacoes">${sug ? '<button type="button" class="button primary small" data-conf>✓ Confirmar</button> ' : ''}${(p.candidatos || []).slice(0, 4).map((c, j) => `<button type="button" class="button ghost small" data-cand="${j}">${html(c.nome)}</button>`).join(' ')}
        <span class="aviso-add"><input list="avisoDL" data-in placeholder="Outro nome…" aria-label="Escolher pessoa"><button type="button" class="button ghost small" data-set>Definir</button></span>
        <button type="button" class="button ghost small" data-nenhum>Ninguém</button></td></tr>`;
  };
  const falhas = (r.falhas || []).map(f => `<li><b>PC ${Number(f.pc)}</b> · ${html(f.solicitante || '—')} — <span style="color:#f9cd72">${html(f.motivo || 'não enviado')}</span> <small>${html(quando(f.em))}</small></li>`).join('');
  bg.innerHTML = `<div class="modal aviso-modal wide"><div class="modal-head"><div><h2>Avisos de chegada</h2><small>Pedidos que ainda não chegaram e não têm quem avisar. Defina agora e o e-mail sai sozinho quando o conferente finalizar.</small></div><button class="icon-btn" type="button" data-fechar aria-label="Fechar">✕</button></div>
    <div class="modal-body">${r.pendentes.length ? `<p><b id="avisoTotal">${Number(r.total)}</b> pedido(s) sem destinatário definido${r.total > r.pendentes.length ? ` (mostrando ${r.pendentes.length})` : ''}.</p>
      <div class="table-scroll"><table class="orders-table"><thead><tr><th>PC</th><th>Fornecedor</th><th>Solicitante</th><th>Situação</th><th>Definir quem recebe</th></tr></thead><tbody id="avisoBody">${r.pendentes.map(linha).join('')}</tbody></table></div>` : '<p>✓ Todos os pedidos em aberto têm destinatário definido.</p>'}
      ${falhas ? `<h3 style="margin-top:18px">E-mails que não saíram</h3><ul class="ct-envios">${falhas}</ul>` : ''}</div></div>`;
  document.body.appendChild(bg);
  avisoDatalist(r.contatos);
  bg.querySelector('[data-fechar]').onclick = () => bg.remove();
  const porPc = new Map(r.pendentes.map(p => [p.pc, p]));
  bg.querySelectorAll('#avisoBody tr').forEach(tr => {
    const pc = Number(tr.dataset.pc), p = porPc.get(pc);
    const salvar = async corpo => {
      try {
        const res = await api('pc_avisar', avisoPost({ pc, ...corpo }));
        const idx = items.findIndex(x => x.pc === pc); if (idx >= 0) items[idx] = normalized(res.item);
        tr.remove(); const t = bg.querySelector('#avisoTotal'); if (t) t.textContent = String(Math.max(0, Number(t.textContent) - 1));
        toast(`PC ${pc}: aviso de chegada salvo.`); avisoAtualizarBadge();
      } catch (e) { toast(e.message, true); }
    };
    tr.querySelector('[data-conf]')?.addEventListener('click', () => salvar({ emails: p.sugestao.map(d => d.email), origem: p.status === 'sugerido' ? 'observacao' : 'manual' }));
    tr.querySelectorAll('[data-cand]').forEach(b => b.onclick = () => salvar({ emails: [p.candidatos[Number(b.dataset.cand)].email], origem: 'manual' }));
    tr.querySelector('[data-set]').onclick = () => { const c = avisoAcharContato(tr.querySelector('[data-in]').value, r.contatos); if (!c) return toast('Escolha uma pessoa da lista.', true); salvar({ emails: [c.email], origem: 'manual' }); };
    tr.querySelector('[data-nenhum]').onclick = () => salvar({ nenhum: true });
  });
}
window.addEventListener('load', () => {
  setTimeout(avisoAtualizarBadge, 3500);
  setInterval(() => { if (!document.hidden) avisoAtualizarBadge(); }, 10 * 60 * 1000);
});
