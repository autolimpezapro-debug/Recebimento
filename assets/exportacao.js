/* Recebimento Drilling — Central de exportação (v3.22.0)
 * Substitui a interface antiga: cartões de modelo, conjuntos com contagem, refinamentos,
 * Excel (.xlsx) de verdade e pré-visualização expansível. Os IDs exportMode/exportScope
 * continuam existindo (ocultos) para não quebrar atalhos de outras telas. */
(function () {
  const MODELOS = [
    { v: 'manual', ic: '▤', t: 'Padrão manual', d: 'As 6 colunas do formulário: data, PC, NF, fornecedor, solicitante e observações.' },
    { v: 'completo', ic: '▦', t: 'Completo', d: 'Tudo do pedido: NF, chave, valores, etapas e horários do recebimento.' },
    { v: 'insumos', ic: '☰', t: 'Por insumo', d: 'Uma linha por material, com pedido, recebido e o que falta entregar.' }
  ];
  const ESCOPOS = [
    { v: 'todos', t: 'Todos', d: 'todos os pedidos' },
    { v: 'recebidos', t: 'Recebidos', d: 'total e parcial' },
    { v: 'filtrados', t: 'Filtro da lista', d: 'o que está filtrado em Pedidos' },
    { v: 'selecionados', t: 'Selecionados', d: 'marcados em Pedidos' }
  ];
  let linhasPrev = 15;
  const el = id => document.getElementById(id);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const n = v => Number(v || 0).toLocaleString('pt-BR');

  function base(scope) {
    return scope === 'filtrados' ? filteredItems() : scope === 'recebidos' ? items.filter(arrived)
      : scope === 'selecionados' ? items.filter(i => selected.has(i.pc)) : items.slice().sort((a, b) => b.pc - a.pc);
  }
  function refinado() {
    const q = (el('xpBusca')?.value || '').toLocaleLowerCase('pt-BR').trim(), dest = el('xpDestino')?.value || '',
      sit = el('xpSituacao')?.value || '', de = el('xpDe')?.value || '', ate = el('xpAte')?.value || '';
    return { q, dest, sit, de, ate, ativo: !!(q || dest || sit || de || ate) };
  }
  function aplicar(lista) {
    const r = refinado();
    if (!r.ativo) return lista;
    return lista.filter(i => {
      if (r.dest) { const k = destinoChave(i); if (r.dest === '__sem' ? k !== '' : k !== r.dest) return false; }
      if (r.sit === 'recebido' && i.chegou !== 'SIM') return false;
      if (r.sit === 'parcial' && i.chegou !== 'PARCIAL') return false;
      if (r.sit === 'pendente' && arrived(i)) return false;
      if (r.sit === 'urgente' && i.urgente !== '1') return false;
      if ((r.de || r.ate)) { const d = String(i.data || '').slice(0, 10); if (!d || (r.de && d < r.de) || (r.ate && d > r.ate)) return false; }
      if (r.q && !String(i.pc).includes(r.q) && ![i.fornecedor, i.numeroNF, i.solicitante, i.material, i.obs, destinoRotulo(i)]
        .some(s => String(s || '').toLocaleLowerCase('pt-BR').includes(r.q))) return false;
      return true;
    });
  }
  window.exportItems = function () { return aplicar(base(el('exportScope').value)); };

  function popularDestinos() {
    const sel = el('xpDestino'); if (!sel) return;
    const atual = sel.value, cont = {};
    items.forEach(i => { const k = destinoChave(i) || '__sem'; cont[k] = (cont[k] || 0) + 1; });
    const obras = Object.keys(cont).filter(k => k.startsWith('OBRA:')).sort((a, b) => a.localeCompare(b, 'pt-BR'));
    const setores = Object.keys(cont).filter(k => !k.startsWith('OBRA') && k !== '__sem');
    sel.innerHTML = '<option value="">Todos</option>'
      + (obras.length ? '<optgroup label="Obras">' + obras.map(k => `<option value="${esc(k)}">${esc(k.slice(5) || 'Obra não identificada')} (${cont[k]})</option>`).join('') + '</optgroup>' : '')
      + (setores.length ? '<optgroup label="Setores">' + setores.map(k => `<option value="${esc(k)}">${esc((typeof RD_SETOR_NOMES !== 'undefined' ? RD_SETOR_NOMES : {})[k] || k)} (${cont[k]})</option>`).join('') + '</optgroup>' : '')
      + (cont.__sem ? `<option value="__sem">Sem destino definido (${cont.__sem})</option>` : '');
    sel.value = [...sel.options].some(o => o.value === atual) ? atual : '';
  }

  function cartoes() {
    const modo = el('exportMode').value, esc0 = el('exportScope').value;
    el('xpModels').innerHTML = MODELOS.map(m => `<button type="button" role="radio" aria-checked="${m.v === modo}" class="xp-model${m.v === modo ? ' on' : ''}" onclick="xpModelo('${m.v}')">
      <span class="xp-model-ic">${m.ic}</span><span><strong>${m.t}</strong><small>${m.d}</small></span><i class="xp-model-cols">${(m.v === 'insumos' ? headersPara('insumos') : headersPara(m.v)).length} col.</i></button>`).join('');
    el('xpScopes').innerHTML = ESCOPOS.map(s => {
      const c = base(s.v).length, dis = c === 0 && s.v !== 'todos';
      return `<button type="button" role="radio" aria-checked="${s.v === esc0}" class="xp-scope${s.v === esc0 ? ' on' : ''}" ${dis ? 'disabled title="Nenhum pedido neste conjunto"' : ''} onclick="xpEscopo('${s.v}')">
        <strong>${n(c)}</strong><span>${s.t}</span><small>${s.d}</small></button>`;
    }).join('');
  }
  function headersPara(modo) {
    const old = el('exportMode').value; el('exportMode').value = modo; const h = headers(); el('exportMode').value = old; return h;
  }

  window.renderExportPreview = function () {
    if (!el('xpModels')) return;
    popularDestinos(); cartoes();
    const lista = exportItems(), rows = reportRows(), head = headers(), r = refinado();
    const rec = lista.filter(arrived).length, semNF = lista.filter(i => arrived(i) && !i.numeroNF).length;
    el('xpStats').innerHTML = `<div><strong>${n(lista.length)}</strong><span>pedidos</span></div><div><strong>${n(rows.length)}</strong><span>linhas</span></div>
      <div><strong>${lista.length ? Math.round(rec / lista.length * 100) : 0}%</strong><span>recebidos</span></div><div class="${semNF ? 'warn' : ''}"><strong>${n(semNF)}</strong><span>recebidos sem NF</span></div>`;
    el('exportTotals').textContent = lista.length ? `${n(lista.length)} pedido(s) · ${n(rows.length)} linha(s) · ${head.length} colunas` : 'Nenhum pedido no conjunto escolhido';
    el('xpLimpar').hidden = !r.ativo;
    el('xpActions').classList.toggle('vazio', !lista.length);
    el('xpActions').querySelectorAll('button').forEach(b => b.disabled = !lista.length && !b.classList.contains('wa'));
    el('xpCols').textContent = `${head.length} colunas · A4 paisagem`;
    const mostra = Math.min(rows.length, linhasPrev);
    el('previewNote').textContent = rows.length ? `Mostrando ${n(mostra)} de ${n(rows.length)} linha(s)` : 'Nada para mostrar';
    el('xpMais').hidden = rows.length <= linhasPrev;
    el('previewHead').innerHTML = '<tr>' + head.map(v => `<th>${esc(v)}</th>`).join('') + '</tr>';
    el('previewRows').innerHTML = rows.slice(0, linhasPrev).map(row => '<tr>' + row.map(v => `<td title="${esc(v)}">${esc(v)}</td>`).join('') + '</tr>').join('')
      || `<tr><td colspan="${head.length}" class="xp-empty"><strong>Nenhum pedido aqui</strong><span>${r.ativo ? 'Os refinamentos não encontraram pedidos. Tente limpar a busca ou o período.' : 'Escolha outro conjunto de pedidos.'}</span></td></tr>`;
  };

  window.xpModelo = v => { el('exportMode').value = v; linhasPrev = 15; renderExportPreview(); };
  window.xpEscopo = v => { el('exportScope').value = v; linhasPrev = 15; renderExportPreview(); };
  window.xpMaisLinhas = () => { linhasPrev += 50; renderExportPreview(); };
  window.xpLimpar = () => { ['xpBusca', 'xpDestino', 'xpSituacao', 'xpDe', 'xpAte'].forEach(id => { const e = el(id); if (e) e.value = ''; }); renderExportPreview(); };

  window.exportSelected = () => { ['xpBusca', 'xpDestino', 'xpSituacao', 'xpDe', 'xpAte'].forEach(id => { const e = el(id); if (e) e.value = ''; }); el('exportScope').value = 'selecionados'; goPage('exportacao'); };

  const nomeArq = ext => `recebimento_drilling_${el('exportMode').value}_${new Date().toLocaleDateString('pt-BR').split('/').reverse().join('-')}.${ext}`;
  const resumoFiltro = () => {
    const r = refinado(), p = [ESCOPOS.find(s => s.v === el('exportScope').value)?.t || ''];
    if (r.dest) p.push('Destino: ' + (el('xpDestino').selectedOptions[0]?.text || r.dest).replace(/\s\(\d+\)$/, ''));
    if (r.sit) p.push('Situação: ' + el('xpSituacao').selectedOptions[0].text);
    if (r.de || r.ate) p.push('Recebido ' + (r.de ? 'de ' + r.de.split('-').reverse().join('/') : '') + (r.ate ? ' até ' + r.ate.split('-').reverse().join('/') : ''));
    if (r.q) p.push('Busca: "' + r.q + '"');
    return p.join(' · ');
  };

  window.xpExcel = async function () {
    const lista = exportItems(); if (!lista.length) { toast('Não há pedidos neste conjunto', true); return; }
    try {
      const X = await import('./xlsx-lib.js');
      const head = headers(), rows = reportRows();
      const ws = X.utils.aoa_to_sheet([head, ...rows]);
      ws['!cols'] = head.map((h, c) => ({ wch: Math.min(60, Math.max(h.length + 2, ...rows.slice(0, 300).map(r => String(r[c] ?? '').length + 1))) }));
      ws['!autofilter'] = { ref: X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: rows.length, c: head.length - 1 } }) };
      const info = X.utils.aoa_to_sheet([['Recebimento Drilling · v' + APP_VERSION], ['Gerado em', new Date().toLocaleString('pt-BR')],
        ['Modelo', MODELOS.find(m => m.v === el('exportMode').value).t], ['Pedidos', resumoFiltro()], ['Total de pedidos', lista.length], ['Linhas', rows.length]]);
      info['!cols'] = [{ wch: 18 }, { wch: 70 }];
      const wb = X.utils.book_new(); X.utils.book_append_sheet(wb, ws, 'Relatório'); X.utils.book_append_sheet(wb, info, 'Sobre');
      X.writeFile(wb, nomeArq('xlsx'));
      toast('Excel gerado');
    } catch (e) { toast('Não foi possível gerar o Excel: ' + e.message, true); }
  };

  window.downloadCSV = function () {
    const lista = exportItems(); if (!lista.length) { toast('Não há pedidos neste conjunto', true); return; }
    const csv = '﻿sep=;\r\n' + [headers(), ...reportRows()].map(r => r.map(csvCell).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })), a = document.createElement('a');
    a.href = url; a.download = nomeArq('csv'); document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 5000);
    toast('CSV gerado');
  };

  window.printReport = function () {
    const lista = exportItems(); if (!lista.length) { toast('Não há pedidos neste conjunto', true); return; }
    const cols = headers(), modo = el('exportMode').value, dados = reportRows(), win = window.open('', '_blank');
    if (!win) { toast('Permita abrir janelas para imprimir', true); return; }
    const larg = modo === 'manual' ? '' : 'body.largo table{table-layout:auto}body.largo th,body.largo td{font-size:6.5pt;padding:3px 4px}';
    const css = `<style>@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#18212d;margin:0;font-size:9pt}
      .top{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #1f4fa8;padding-bottom:6px;margin-bottom:8px}
      h1{font-size:14pt;margin:0}.sub{font-size:8pt;color:#475569;margin-top:2px}.meta{font-size:8pt;text-align:right;color:#334155}
      table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tr{break-inside:avoid}
      th,td{border:1px solid #94a3b8;padding:5px;text-align:left;vertical-align:top;overflow-wrap:anywhere;white-space:pre-wrap}
      th{font-size:7.5pt;background:#e6edf6;text-transform:uppercase}tbody tr:nth-child(even) td{background:#f6f8fb}
      th:nth-child(1){width:10%}th:nth-child(2){width:7%}th:nth-child(3){width:10%}th:nth-child(4){width:22%}th:nth-child(5){width:18%}th:nth-child(6){width:33%}
      ${larg}body.largo th{width:auto!important}.ass{display:flex;gap:40px;margin-top:28px;font-size:8pt}.ass div{flex:1;border-top:1px solid #334155;padding-top:4px;text-align:center}
      .noprint{margin-bottom:10px;background:#edf3fb;border:1px solid #d4dce8;padding:9px;border-radius:6px;font-size:9pt}@media print{.noprint{display:none}}</style>`;
    win.document.open();
    win.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório de Recebimento</title>${css}</head><body class="${modo === 'manual' ? '' : 'largo'}">
      <div class="noprint">Relatório pronto. Use Imprimir → Salvar como PDF. <button onclick="window.print()">Imprimir novamente</button></div>
      <div class="top"><div><h1>Relatório de Recebimento · Drilling</h1><div class="sub">${esc(MODELOS.find(m => m.v === modo).t)} · ${esc(resumoFiltro())}</div></div>
      <div class="meta">Emitido em ${esc(new Date().toLocaleString('pt-BR'))}<br>${n(lista.length)} pedido(s) · ${n(dados.length)} linha(s) · v${esc(APP_VERSION)}</div></div>
      <table><thead><tr>${cols.map(s => `<th>${esc(s)}</th>`).join('')}</tr></thead><tbody>${dados.map(r => `<tr>${r.map(v => `<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>
      <div class="ass"><div>Conferente</div><div>Responsável pelo almoxarifado</div></div>
      <script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));<\/script></body></html>`);
    win.document.close();
  };
})();
