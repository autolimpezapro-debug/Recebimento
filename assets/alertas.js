/* Recebimento Drilling — Alertas de estoque baixo (v3.26.0)
 * Faixa fixa no topo enquanto houver item no ponto de reposição, lista detalhada
 * e configuração dos e-mails (somente quem administra o estoque). */
'use strict';
const ALT = { dados: null, cfg: null, timer: null };
const altEl = id => document.getElementById(id);
const altH = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const altN = v => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
const ALT_ROT = { ZERADO: 'Sem estoque', MINIMO: 'Abaixo do mínimo', REPOSICAO: 'No ponto de reposição' };
const altPode = op => typeof rdaPermite !== 'function' || rdaPermite('estoque', op);
const altGet = a => rdaFetch(a);
const altPost = (a, b) => rdaFetch(a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) });

async function altAtualizar() {
  if (!altPode('ver')) return;
  try { ALT.dados = await altGet('alertas_status'); altFaixa(); if (altEl('altModal')?.classList.contains('on')) altRenderLista(); } catch (e) { /* sem acesso: silencioso */ }
}
function altFaixa() {
  let f = altEl('altFaixa');
  const d = ALT.dados, n = d ? d.itens.length : 0;
  if (!f) {
    f = document.createElement('button'); f.id = 'altFaixa'; f.type = 'button'; f.className = 'alt-faixa'; f.onclick = altAbrir;
    const main = document.querySelector('main.content'); if (!main) return; main.prepend(f);
  }
  f.hidden = !n;
  if (!n) return;
  const z = d.zerados, m = d.abaixo_minimo;
  f.className = 'alt-faixa' + (z ? ' grave' : m ? ' medio' : '');
  f.innerHTML = `<span class="alt-ic">🔻</span><span><strong>${n} item(ns) precisam de reposição</strong><small>${z ? z + ' sem estoque · ' : ''}${m ? m + ' abaixo do mínimo · ' : ''}${altH(d.itens.slice(0, 4).map(i => i.descricao).join(', '))}${n > 4 ? '…' : ''}</small></span><span class="alt-ver">Ver lista →</span>`;
}

function altAbrir() {
  let m = altEl('altModal');
  if (!m) {
    m = document.createElement('div'); m.id = 'altModal'; m.className = 'modal-bg'; m.onclick = e => { if (e.target === m) m.classList.remove('on'); };
    m.innerHTML = `<div class="modal alt-modal"><div class="modal-head"><div><h2>Alertas de estoque</h2><small id="altSub"></small></div><button class="icon-btn" aria-label="Fechar" onclick="altEl('altModal').classList.remove('on')">✕</button></div>
      <div class="alt-tabs"><button class="on" data-t="lista" onclick="altAba('lista')">Itens baixos</button>${altPode('admin') ? '<button data-t="cfg" onclick="altAba(\'cfg\')">⚙ E-mails e frequência</button>' : ''}</div>
      <div class="modal-body" id="altCorpo"></div></div>`;
    document.body.appendChild(m);
  }
  m.classList.add('on'); altAba('lista');
}
function altAba(t) {
  document.querySelectorAll('#altModal .alt-tabs button').forEach(b => b.classList.toggle('on', b.dataset.t === t));
  t === 'cfg' ? altRenderCfg() : altRenderLista();
}
function altRenderLista() {
  const d = ALT.dados; if (!d) return;
  altEl('altSub').textContent = `${d.itens.length} de ${d.monitorados} itens monitorados · ${d.email_ativo ? 'e-mail ligado' : 'e-mail desligado'}${d.ultima_verificacao ? ' · verificado ' + new Date(d.ultima_verificacao).toLocaleString('pt-BR') : ''}`;
  const desde = s => { if (!s) return '—'; const dias = Math.floor((Date.now() - new Date(s)) / 86400000); return dias < 1 ? 'hoje' : dias + ' dia(s)'; };
  altEl('altCorpo').innerHTML = (d.itens.length ? `<div class="table-scroll"><table class="orders-table alt-tab"><thead><tr><th>Item</th><th>Saldo</th><th>Ponto de reposição</th><th>Mínimo</th><th>Repor</th><th>Situação</th><th>Há</th></tr></thead><tbody>
      ${d.itens.map(i => `<tr><td><strong>${altH(i.descricao)}</strong><small class="est-sub">${altH(i.codigo)}${i.categoria ? ' · ' + altH(i.categoria) : ''}</small></td>
        <td class="alt-num"><b>${altN(i.saldo)}</b> ${altH(i.unidade)}</td><td class="alt-num">${altN(i.ponto)}</td><td class="alt-num">${i.minimo ? altN(i.minimo) : '—'}</td><td class="alt-num"><b>${altN(i.comprar)}</b></td>
        <td><span class="alt-tag ${i.nivel}">${ALT_ROT[i.nivel]}</span></td><td>${desde(i.desde)}${i.avisos ? `<small class="est-sub">${i.avisos} aviso(s)</small>` : ''}</td></tr>`).join('')}</tbody></table></div>`
    : '<p class="alt-vazio">✓ Nenhum item abaixo do ponto de reposição.</p>')
    + (d.repostos?.length ? `<h3 class="alt-h3">Repostos recentemente</h3><ul class="alt-repostos">${d.repostos.slice(0, 8).map(r => `<li>✓ ${altH(r.descricao)} <small>reposto em ${new Date(r.reposto_em).toLocaleDateString('pt-BR')}</small></li>`).join('')}</ul>` : '')
    + `<div class="alt-acoes"><button class="button ghost" onclick="altEl('altModal').classList.remove('on');goPage('estoque')">Abrir o estoque</button>${altPode('admin') ? '<button class="button ghost" onclick="altVerificar(true)">✉ Reenviar e-mail agora</button>' : ''}</div>
      <p class="alt-nota">O alerta usa o <b>ponto de reposição</b> de cada item (ou o mínimo, se o ponto não estiver definido). Ele se repete até o saldo voltar acima do ponto. Itens sem esses parâmetros não são monitorados.</p>`;
}
async function altRenderCfg() {
  const c = altEl('altCorpo'); c.innerHTML = '<p class="alt-vazio">Carregando…</p>';
  try { ALT.cfg = (await altGet('alertas_config')).config; } catch (e) { c.innerHTML = `<p class="alt-vazio">${altH(e.message)}</p>`; return; }
  const g = ALT.cfg, ue = ALT.dados?.ultimo_email;
  c.innerHTML = `<form class="alt-form" onsubmit="altSalvar(event)">
    <label class="alt-sw"><input type="checkbox" id="altAtivo" ${g.ativo ? 'checked' : ''}> <span><strong>Enviar alertas por e-mail</strong><small>O aviso no sino do sistema funciona sempre, com ou sem e-mail.</small></span></label>
    <label class="alt-campo full"><span>E-mails que recebem (um por linha ou separados por vírgula)</span><textarea id="altEmails" rows="3" placeholder="compras@empresa.com.br&#10;almoxarifado@empresa.com.br">${altH(g.emails.join('\n'))}</textarea></label>
    <label class="alt-campo"><span>Repetir enquanto não repor</span><select id="altRepetir">${[6, 12, 24, 48, 72].map(h => `<option value="${h}" ${g.repetir_horas === h ? 'selected' : ''}>a cada ${h} horas</option>`).join('')}</select></label>
    <label class="alt-campo"><span>Quais itens</span><select id="altEscopo"><option value="com_parametro" ${g.escopo === 'com_parametro' ? 'selected' : ''}>Todos com ponto de reposição ou mínimo</option><option value="importados" ${g.escopo === 'importados' ? 'selected' : ''}>Somente Importados</option></select></label>
    <label class="alt-sw full"><input type="checkbox" id="altReposto" ${g.avisar_reposto ? 'checked' : ''}> <span><strong>Avisar também quando o item for reposto</strong></span></label>
    <details class="alt-smtp full" ${g.metodo === 'smtp' ? 'open' : ''}><summary>Como enviar o e-mail</summary>
      <label class="alt-campo"><span>Método</span><select id="altMetodo"><option value="mail" ${g.metodo !== 'smtp' ? 'selected' : ''}>Servidor do site (mail do PHP)</option><option value="smtp" ${g.metodo === 'smtp' ? 'selected' : ''}>Conta de e-mail (SMTP) — recomendado</option></select></label>
      <label class="alt-campo"><span>Nome do remetente</span><input id="altNome" value="${altH(g.remetente_nome)}"></label>
      <label class="alt-campo"><span>E-mail remetente</span><input id="altDe" type="email" value="${altH(g.remetente)}" placeholder="estoque@seudominio.com.br"></label>
      <label class="alt-campo"><span>Servidor SMTP</span><input id="altHost" value="${altH(g.smtp_host)}" placeholder="mail.seudominio.com.br"></label>
      <label class="alt-campo"><span>Porta / segurança</span><span class="alt-linha"><input id="altPorta" type="number" value="${g.smtp_porta}" style="width:90px"><select id="altSeg"><option value="ssl" ${g.smtp_seguranca === 'ssl' ? 'selected' : ''}>SSL (465)</option><option value="tls" ${g.smtp_seguranca === 'tls' ? 'selected' : ''}>STARTTLS (587)</option><option value="nenhuma" ${g.smtp_seguranca === 'nenhuma' ? 'selected' : ''}>Nenhuma</option></select></span></label>
      <label class="alt-campo"><span>Usuário SMTP</span><input id="altUser" value="${altH(g.smtp_usuario)}" autocomplete="off"></label>
      <label class="alt-campo"><span>Senha SMTP</span><input id="altSenha" type="password" autocomplete="new-password" placeholder="${g.smtp_senha_definida ? '•••••• (guardada — deixe em branco para manter)' : ''}"></label>
      <p class="alt-nota">No cPanel: crie uma conta em <b>E-mail Accounts</b> (ex.: estoque@seudominio) e use os dados de "Connect Devices". Pelo SMTP o e-mail cai menos no spam.</p>
    </details>
    <div class="alt-acoes full"><button class="button primary" type="submit">Salvar</button><button class="button ghost" type="button" onclick="altTeste()">✉ Enviar e-mail de teste</button></div>
    <p class="alt-nota full" id="altUltimo">${ue ? (ue.ok ? '✓ Último e-mail: ' + altH(ue.assunto) + ' — ' + new Date(ue.em).toLocaleString('pt-BR') : '⚠ Último envio falhou (' + new Date(ue.em).toLocaleString('pt-BR') + '): ' + altH(ue.erro)) : 'Nenhum e-mail enviado ainda.'}</p>
  </form>`;
}
function altColetar() {
  return { ativo: altEl('altAtivo').checked, emails: altEl('altEmails').value, repetir_horas: Number(altEl('altRepetir').value), escopo: altEl('altEscopo').value,
    avisar_reposto: altEl('altReposto').checked, metodo: altEl('altMetodo').value, remetente_nome: altEl('altNome').value, remetente: altEl('altDe').value,
    smtp_host: altEl('altHost').value, smtp_porta: Number(altEl('altPorta').value), smtp_seguranca: altEl('altSeg').value, smtp_usuario: altEl('altUser').value, smtp_senha: altEl('altSenha').value };
}
async function altSalvar(ev) {
  ev?.preventDefault();
  try { await altPost('alertas_config_salvar', altColetar()); toast('Alertas salvos'); await altAtualizar(); altRenderCfg(); return true; }
  catch (e) { toast(e.message, true); return false; }
}
async function altTeste() {
  if (!(await altSalvar())) return;
  try { const r = await altPost('alertas_teste', {}); toast(`E-mail de teste enviado para ${r.enviado_para.join(', ')}`); }
  catch (e) { toast('Falha no envio: ' + e.message, true); }
}
async function altVerificar(reenviar) {
  try { const r = await altPost('alertas_verificar', { reenviar }); toast(r.email ? 'E-mail ' + r.email : 'Verificação feita (e-mail desligado)', String(r.email || '').startsWith('FALHOU')); altAtualizar(); }
  catch (e) { toast(e.message, true); }
}

window.addEventListener('load', () => {
  setTimeout(() => { const a = altEl('altAdmin'); if (a) a.hidden = !altPode('admin'); }, 2000);
  setTimeout(altAtualizar, 2500);
  ALT.timer = setInterval(() => { if (!document.hidden) altAtualizar(); }, 5 * 60 * 1000);
  const pg = new URLSearchParams(location.search).get('pagina');
  if (pg && /^[a-z]+$/.test(pg)) setTimeout(() => { try { history.replaceState(null, '', location.pathname); goPage(pg); } catch (e) {} }, 1800);
});
// Depois de lançar uma saída/entrada no estoque, a faixa se atualiza sozinha.
document.addEventListener('click', e => { if (e.target.closest?.('#page-estoque, #page-importados, .modal')) { clearTimeout(ALT.depois); ALT.depois = setTimeout(altAtualizar, 4000); } });

/* ---------- v3.27.3: Nome + e-mail para o aviso "seu pedido chegou" ---------- */
async function altContatos() {
  let r; try { r = await altGet('alertas_contatos'); } catch (e) { toast(e.message, true); return; }
  const bg = document.createElement('div'); bg.className = 'modal-bg on'; bg.id = 'altCtModal';
  bg.onclick = e => { if (e.target === bg) bg.remove(); };
  const linha = (c = {}) => `<tr><td><input class="ct-nome" value="${altH(c.nome || '')}" placeholder="Ex.: MARCOS SILVA"></td><td><input class="ct-email" type="email" value="${altH(c.email || '')}" placeholder="marcos@empresa.com.br"></td>
    <td><input class="ct-apel" value="${altH(c.apelidos || '')}" placeholder="opcional: MARCOS, MARCOS ENG"></td><td><button type="button" class="icon-btn" title="Remover" onclick="this.closest('tr').remove()">✕</button></td></tr>`;
  const envios = (r.envios || []).map(e => `<li><b>PC ${altH(e.pc)}</b> · ${altH(e.solicitante || '—')} → ${e.enviado ? '✓ ' + altH((e.para || []).join(', ')) : '<span style="color:#f9cd72">não enviado: ' + altH(e.motivo || '') + '</span>'} <small>${new Date(e.em).toLocaleString('pt-BR')}</small></li>`).join('');
  bg.innerHTML = `<div class="modal alt-modal"><div class="modal-head"><div><h2>Aviso de chegada do pedido</h2><small>Quando o recebimento for finalizado, quem está no campo <b>Solicitante</b> do pedido recebe um e-mail.</small></div><button class="icon-btn" onclick="this.closest('.modal-bg').remove()">✕</button></div>
    <div class="modal-body">
      <div class="table-scroll"><table class="orders-table ct-tab"><thead><tr><th>Nome (como no pedido)</th><th>E-mail</th><th>Outros nomes usados nos pedidos</th><th></th></tr></thead><tbody id="ctBody">${(r.contatos.length ? r.contatos : [{}]).map(linha).join('')}</tbody></table></div>
      <div class="alt-acoes"><button type="button" class="button ghost" id="ctMais">+ Adicionar pessoa</button><button type="button" class="button primary" id="ctSalvar">Salvar</button></div>
      <div class="ct-teste"><label class="alt-campo"><span>Testar: digite o solicitante como está no pedido</span><span class="alt-linha"><input id="ctNomeTeste" placeholder="Ex.: Marcos"><button type="button" class="button ghost" id="ctTestar">Quem recebe?</button></span></label><p id="ctTesteRes" class="alt-nota"></p></div>
      <p class="alt-nota">O sistema compara o nome do solicitante com o nome cadastrado (nome completo, parte do nome ou só o primeiro nome). Se houver duas pessoas com o mesmo primeiro nome, preencha "Outros nomes" para não haver dúvida — na dúvida ele não envia. Funcionários com e-mail no cadastro de Estoque também são avisados.</p>
      ${envios ? `<h3 class="alt-h3">Últimos avisos</h3><ul class="ct-envios">${envios}</ul>` : ''}
    </div></div>`;
  document.body.appendChild(bg);
  bg.querySelector('#ctMais').onclick = () => { bg.querySelector('#ctBody').insertAdjacentHTML('beforeend', linha()); bg.querySelector('#ctBody tr:last-child .ct-nome').focus(); };
  bg.querySelector('#ctSalvar').onclick = async () => {
    const contatos = [...bg.querySelectorAll('#ctBody tr')].map(tr => ({ nome: tr.querySelector('.ct-nome').value, email: tr.querySelector('.ct-email').value, apelidos: tr.querySelector('.ct-apel').value }));
    try { const s = await altPost('alertas_contatos_salvar', { contatos }); toast(`${s.contatos.length} pessoa(s) salvas.`); bg.querySelector('#ctBody').innerHTML = (s.contatos.length ? s.contatos : [{}]).map(linha).join(''); }
    catch (e) { toast(e.message, true); }
  };
  bg.querySelector('#ctTestar').onclick = async () => {
    const n = bg.querySelector('#ctNomeTeste').value.trim(); if (!n) return;
    try { const t = await rdaFetch('alertas_contatos_testar', { query: { nome: n } }); bg.querySelector('#ctTesteRes').innerHTML = t.destinatarios.length ? '✓ Recebe: <b>' + altH(t.destinatarios.join(', ')) + '</b> (salve antes de testar nomes novos)' : '⚠ Ninguém recebe para "' + altH(n) + '". Cadastre a pessoa ou preencha "Outros nomes".'; }
    catch (e) { toast(e.message, true); }
  };
}
