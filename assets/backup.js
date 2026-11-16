/* Recebimento Drilling — Backup completo v3.2.0
 * Painel de diagnóstico, backup sob demanda, verificação por hash e retenção.
 */
'use strict';

const BK = { dados: null, carregando: false };

const bkEl = id => document.getElementById(id);
const bkEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const bkBytes = n => {
  n = Number(n || 0);
  if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
  if (n >= 1048576) return (n / 1048576).toFixed(2) + ' MB';
  if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
  return n + ' B';
};
const bkData = v => { if (!v) return '—'; const d = new Date(v); return isNaN(d) ? '—' : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); };

async function bkApi(action, opts = {}) {
  const { query, ...init } = opts;
  let extra = '';
  if (query) {
    const qp = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== '' && v != null) qp.append(k, v); });
    extra = '&' + qp.toString();
  }
  const res = await fetch(`api.php?action=${encodeURIComponent(action)}${extra}`, {
    cache: 'no-store', credentials: 'same-origin', ...init,
    headers: { 'Content-Type': 'application/json', ...(init.headers || {}) },
  });
  let data;
  try { data = await res.json(); } catch { throw new Error('Resposta inválida do servidor'); }
  if (!res.ok || !data.ok) throw new Error(data.error || `Erro ${res.status}`);
  return data;
}

function bkStatus(msg, erro = false) {
  const el = bkEl('bkStatus');
  if (el) { el.textContent = msg; el.style.color = erro ? '#ffb2bb' : '#7df0d5'; }
}

async function backupInit() {
  if (!bkEl('page-administracao')) return;
  if (typeof rdaPermite === 'function' && !rdaPermite('administracao', 'ver')) return;   // v3.27.4: sem acesso, não consulta
  if (BK.carregando) return;
  BK.carregando = true;
  try {
    await bkCarregar();
  } finally { BK.carregando = false; }
}

async function bkCarregar() {
  try {
    const r = await bkApi('backup_diagnostico');
    BK.dados = r;
    bkRender(r);
  } catch (e) {
    bkStatus('Não foi possível ler o diagnóstico do backup: ' + e.message, true);
  }
}

function bkRender(r) {
  const ultimo = r.ultimo_backup;
  const dados = r.dados_atuais || {};
  const estado = r.estado || {};
  const alertas = r.alertas || [];

  bkEl('bkCards').innerHTML = [
    ['Último backup', ultimo ? bkData(ultimo.criado_em) : 'nenhum', ultimo ? (ultimo.tipo || '') : 'warn'],
    ['Idade', ultimo ? (ultimo.idade_horas < 24 ? ultimo.idade_horas.toFixed(1) + ' h' : (ultimo.idade_horas / 24).toFixed(1) + ' dias') : '—', ultimo && ultimo.idade_horas > 48 ? 'warn' : 'ok'],
    ['Backups guardados', String(r.total_backups || 0), ''],
    ['Espaço livre', r.espaco_livre_bytes != null ? bkBytes(r.espaco_livre_bytes) : '—', (r.espaco_livre_bytes != null && r.espaco_livre_bytes < 209715200) ? 'warn' : ''],
  ].map(([label, valor, cls]) => `<article class="kpi ${cls}"><div class="kpi-top"><span class="kpi-icon">▣</span></div><strong style="font-size:20px">${bkEsc(valor)}</strong><span>${bkEsc(label)}</span></article>`).join('');

  bkEl('bkAlertas').innerHTML = alertas.length
    ? alertas.map(a => `<div class="bk-alert">⚠ ${bkEsc(a)}</div>`).join('')
    : '<div class="bk-ok">✓ Backup automático em dia. Nenhuma pendência detectada.</div>';

  bkEl('bkDados').innerHTML = `
    <div class="bk-resumo">
      <div><small>PCs</small><strong>${dados.pcs ?? 0}</strong></div>
      <div><small>Conferências</small><strong>${dados.conferencias ?? 0}</strong></div>
      <div><small>Linhas de insumo</small><strong>${dados.linhas_insumo ?? 0}</strong></div>
      <div><small>Fotos</small><strong>${dados.fotos ?? 0}</strong></div>
      <div><small>PDFs de pedido</small><strong>${dados.pdfs ?? 0}</strong></div>
      <div><small>XMLs</small><strong>${dados.xmls ?? 0}</strong></div>
      <div><small>Itens em estoque</small><strong>${dados.estoque_itens ?? 0}</strong></div>
      <div><small>Notificações</small><strong>${dados.notificacoes ?? 0}</strong></div>
    </div>`;

  const lista = r.backups || [];
  bkEl('bkLista').innerHTML = lista.length ? `<div class="bk-table-scroll"><table class="orders-table">
    <thead><tr><th>Arquivo</th><th>Tipo</th><th>Tamanho</th><th>Criado</th><th>Ações</th></tr></thead><tbody>
    ${lista.map(b => `<tr>
      <td><code>${bkEsc(b.nome)}</code></td>
      <td><span class="nt-pill ${b.tipo === 'diário' || b.tipo === 'semanal' ? 'on' : ''}">${bkEsc(b.tipo)}</span></td>
      <td>${bkBytes(b.bytes)}</td>
      <td>${bkData(b.criado_em)}</td>
      <td class="est-actions">
        <button class="button ghost small" onclick="bkVerificar('${bkEsc(b.nome)}')">Verificar</button>
        <a class="button ghost small" href="api.php?action=backup_baixar&arquivo=${encodeURIComponent(b.nome)}">Baixar</a>
      </td></tr>`).join('')}
    </tbody></table></div>` : '<p class="est-hint">Nenhum backup guardado ainda. Clique em “Gerar backup completo agora”.</p>';

  const ret = r.retencao || { diarios: 14, semanais: 8 };
  if (bkEl('bkDias')) bkEl('bkDias').value = ret.diarios;
  if (bkEl('bkSemanas')) bkEl('bkSemanas').value = ret.semanais;
  if (bkEl('bkCron')) bkEl('bkCron').textContent = r.cron || '';
  if (bkEl('bkEstadoErro')) {
    bkEl('bkEstadoErro').textContent = estado.ultimo_erro
      ? 'Último erro: ' + estado.ultimo_erro.mensagem + ' (' + bkData(estado.ultimo_erro.em) + ')'
      : '';
  }
}

async function bkGerarAgora() {
  const btn = bkEl('bkGerar');
  if (btn) { btn.disabled = true; btn.textContent = 'Gerando backup…'; }
  bkStatus('Gerando backup completo no servidor. Não feche esta janela.');
  try {
    const r = await bkApi('backup_agora', { method: 'POST', body: JSON.stringify({ tipo: 'manual' }) });
    const b = r.backup || {};
    bkStatus(`Backup criado: ${b.arquivo} · ${b.arquivos} arquivo(s) · ${bkBytes(b.bytes)}.`);
    await bkCarregar();
  } catch (e) {
    bkStatus('Falha ao gerar backup: ' + e.message, true);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '⛁ Gerar backup completo agora'; }
  }
}

async function bkVerificar(nome) {
  bkStatus('Verificando ' + nome + ' pelo manifesto (hash SHA-256)…');
  try {
    const r = await bkApi('backup_verificar', { query: { arquivo: nome, profundo: 1 } });
    bkStatus(r.integro
      ? `✓ ${nome} íntegro: ${r.arquivos_encontrados} de ${r.arquivos_no_manifesto} arquivo(s) conferidos.`
      : `⚠ ${nome} com ${r.problemas.length} problema(s): ${r.problemas.slice(0, 3).join(' | ')}`, !r.integro);
  } catch (e) {
    bkStatus('Não foi possível verificar: ' + e.message, true);
  }
}

async function bkSalvarRetencao() {
  try {
    const r = await bkApi('backup_config', {
      method: 'POST',
      body: JSON.stringify({ retencao_dias: Number(bkEl('bkDias').value), retencao_semanas: Number(bkEl('bkSemanas').value) }),
    });
    bkStatus('Retenção salva. ' + ((r.removidos || []).length ? r.removidos.length + ' backup(s) antigo(s) removido(s).' : 'Nenhum backup removido.'));
    await bkCarregar();
  } catch (e) { bkStatus('Falha ao salvar retenção: ' + e.message, true); }
}

async function bkCopiarCron() {
  const txt = bkEl('bkCron')?.textContent || '';
  try { await navigator.clipboard.writeText(txt); bkStatus('Comando do cron copiado.'); }
  catch { bkStatus('Copie manualmente: ' + txt); }
}

window.addEventListener('DOMContentLoaded', () => {
  if (bkEl('page-administracao')) rdWhenLogged(() => setTimeout(backupInit, 1200));
});
