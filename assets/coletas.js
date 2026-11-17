/* Recebimento Drilling — Módulo de Coletas v3.5.0
 * Mapa (Leaflet), rota (vizinho mais próximo + OSRM), agenda, status e retorno.
 * Módulo independente: não conversa com pedidos, estoque ou agenda.
 */
'use strict';

const COL = {
  mapa: null,
  camada: null,
  camadaRota: null,
  tiles: 'osm',
  pontos: [],
  coletas: [],
  rota: null,
  status: {},
  motivos: {},
  coletaAtual: null,
  picker: null,
  pickerMapa: null,
  pickerLatLng: null,
  carregado: false,
};

const colEl = id => document.getElementById(id);
const colEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const colNum = n => Number(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const colData = v => { if (!v) return '—'; const [a, m, d] = String(v).split('-'); return d ? `${d}/${m}/${a}` : v; };
const colDataHora = v => { if (!v) return '—'; const d = new Date(v); return isNaN(d) ? '—' : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); };

const COL_TILES = {
  voyager: {
    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    attr: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
  },
  osm: {
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attr: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  },
  hot: {
    url: 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
    attr: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> — HOT',
  },
};

const COL_COR = {
  AGUARDANDO: '#f5b544', ROTA: '#5b8def', ESTOQUE: '#a78bfa', FABRICACAO: '#f472b6',
  COLETADO: '#34d399', TRANSPORTE: '#22d3ee', CHEGOU: '#4ade80', CANCELADA: '#74879f',
};

async function colApi(action, opts = {}) {
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

function colPost(action, body) {
  return colApi(action, { method: 'POST', body: JSON.stringify(body) });
}

function colToast(msg, erro = false) {
  if (typeof toast === 'function') return toast(msg, erro);
  alert(msg);
}

/* ---------------- inicialização ---------------- */

async function coletasInit() {
  if (!colEl('page-coletas')) return;
  try {
    const cfg = await colApi('coletas_config');
    COL.status = cfg.status || {};
    COL.motivos = cfg.motivos_retorno || {};
    COL.tiles = (cfg.config && cfg.config.tiles === 'voyager') ? 'osm' : ((cfg.config && cfg.config.tiles) || 'osm');
    if (colEl('colTiles')) colEl('colTiles').value = COL.tiles;
    colPreencherMotivos();
  } catch (e) { /* segue com padrões */ }
  colMontarMapa();
  await Promise.all([colCarregar(), colCarregarRota()]);
  colMontarMeses(); colCarregarHist();
  COL.carregado = true;
}

function colPreencherMotivos() {
  const sel = colEl('colRetMotivo');
  if (!sel) return;
  const motivos = Object.keys(COL.motivos).length ? COL.motivos : {
    AUSENTE: 'Fornecedor ausente', SEM_MATERIAL: 'Material não estava pronto', ENDERECO: 'Endereço não localizado',
    FECHADO: 'Estabelecimento fechado', RECUSADO: 'Coleta recusada pelo fornecedor', OUTRO: 'Outro motivo',
  };
  sel.innerHTML = Object.entries(motivos).map(([k, v]) => `<option value="${k}">${colEsc(v)}</option>`).join('');
}

/* ---------------- mapa ---------------- */

async function colMontarMapa() {
  const el = colEl('colMap');
  if (!el || typeof L === 'undefined' || COL.mapa) return;
  COL.mapa = L.map(el, { zoomControl: true, attributionControl: true }).setView([-19.6986, -43.9586], 8);
  // Testa a camada preferida antes de desenhar: se exigir chave, usa OSM.
  const pref = COL.tiles;
  const ok = await colTestarTiles(pref);
  if (!ok && pref !== 'osm') {
    COL.tiles = 'osm';
    if (colEl('colTiles')) colEl('colTiles').value = 'osm';
  }
  colAplicarTiles();
}

/** Faz um HEAD numa tile do provedor e diz se ele responde sem chave. */
function colTestarTiles(qual) {
  return new Promise(resolve => {
    const t = COL_TILES[qual];
    if (!t) return resolve(false);
    const z = 12, x = 1513, y = 2465; // tile de Belo Horizonte
    const url = t.url.replace('{s}', 'a').replace('{z}', z).replace('{x}', x).replace('{y}', y).replace('{r}', '');
    const img = new Image();
    const fim = ok => { img.onload = img.onerror = null; resolve(ok); };
    const timer = setTimeout(() => fim(false), 3500);
    img.onload = () => { clearTimeout(timer); fim(true); };
    img.onerror = () => { clearTimeout(timer); fim(false); };
    img.src = url;
  });
}

function colAplicarTiles() {
  if (!COL.mapa) return;
  if (COL.camada) COL.mapa.removeLayer(COL.camada);
  const t = COL_TILES[COL.tiles] || COL_TILES.voyager;
  COL.camada = L.tileLayer(t.url, { attribution: t.attr, maxZoom: 19, crossOrigin: true }).addTo(COL.mapa);
  // Se o provedor pedir chave (HTTP 403), troca para OpenStreetMap automaticamente.
  COL.camada.on('tileerror', () => {
    if (COL.tiles === 'osm') return;
    COL.tiles = 'osm';
    if (colEl('colTiles')) colEl('colTiles').value = 'osm';
    colAplicarTiles();
    colToast('A camada escolhida exige chave de API; usando OpenStreetMap.');
  });
}

function colTrocarTiles() {
  COL.tiles = colEl('colTiles').value;
  colAplicarTiles();
  colPost('coletas_config_save', { tiles: COL.tiles }).catch(() => {});
}

function colIcone(coleta, ordem) {
  const cor = COL_COR[coleta.status] || '#5b8def';
  const borda = coleta.atrasada ? '#f87171' : '#0b131f';
  const texto = ordem || (coleta.pc || '•');
  return L.divIcon({
    className: 'col-pin-wrap',
    html: `<span class="col-pin" style="background:${cor};border-color:${borda}">${colEsc(texto)}</span>`,
    iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -26],
  });
}

function colDesenharPontos() {
  if (!COL.mapa) return;
  if (COL.camadaRota) { COL.mapa.removeLayer(COL.camadaRota); COL.camadaRota = null; }
  if (COL.camadaPontos) { COL.mapa.removeLayer(COL.camadaPontos); COL.camadaPontos = null; }
  const grupo = L.layerGroup().addTo(COL.mapa);
  const orig = colEl('colKpis')?.dataset.origem;
  COL.pontos.forEach(p => {
    const m = L.marker([p.lat, p.lng], { icon: colIcone(p) }).addTo(grupo);
    m.bindPopup(`
      <div class="col-popup"><strong>${colEsc(p.fornecedor || 'Fornecedor')}</strong>
      <small>${p.pc ? 'PC ' + colEsc(p.pc) : colEsc(p.id)} · ${colEsc(p.status_label)}</small>
      <p>${colEsc(p.endereco)}</p>
      ${p.agendada_data ? `<p><b>Agendada:</b> ${colData(p.agendada_data)}</p>` : ''}
      <button type="button" class="col-popup-btn" onclick="colAbrirDetalhe('${colEsc(p.id)}')">Abrir coleta</button></div>`);
    m.on('click', () => {});
  });
  COL.camadaPontos = grupo;
  if (COL.rota && COL.rota.paradas && COL.rota.paradas.length) { colDesenharRota(); colDesenharMotorista(); return; }
  if (COL.pontos.length) {
    const bounds = L.latLngBounds(COL.pontos.map(p => [p.lat, p.lng]));
    COL.mapa.fitBounds(bounds, { maxZoom: 13, padding: [40, 40] });
  }
}

function colDesenharRota() {
  if (!COL.mapa || !COL.rota) return;
  if (COL.camadaRota) COL.mapa.removeLayer(COL.camadaRota);
  const grupo = L.layerGroup().addTo(COL.mapa);
  const pontos = COL.rota.paradas.filter(p => p.tem_coordenadas);
  const origem = COL.rota.origem;
  const sequencia = [[origem.lat, origem.lng], ...pontos.map(p => [p.lat, p.lng]), [origem.lat, origem.lng]];
  L.polyline(sequencia, { color: '#5b8def', weight: 4, opacity: .75, dashArray: '1 8', lineCap: 'round' }).addTo(grupo);
  L.marker([origem.lat, origem.lng], { icon: L.divIcon({ className: 'col-pin-wrap', html: '<span class="col-pin col-pin-origem">⌂</span>', iconSize: [30, 30], iconAnchor: [15, 30] }) })
    .addTo(grupo).bindPopup(`<strong>${colEsc(origem.nome)}</strong><small>Ponto de partida</small>`);
  pontos.forEach((p, i) => {
    L.marker([p.lat, p.lng], { icon: colIcone(p, i + 1) }).addTo(grupo)
      .bindPopup(`<div class="col-popup"><strong>${i + 1}. ${colEsc(p.fornecedor || 'Fornecedor')}</strong>
        <small>${p.distancia_anterior_km ? colNum(p.distancia_anterior_km) + ' km da parada anterior' : ''}</small>
        <p>${colEsc(p.endereco_completo || '')}</p>
        <button type="button" class="col-popup-btn" onclick="colAbrirDetalhe('${colEsc(p.id)}')">Abrir coleta</button></div>`);
  });
  COL.camadaRota = grupo;
  if (!COL.semZoom && pontos.length) COL.mapa.fitBounds(L.latLngBounds(sequencia), { maxZoom: 14, padding: [40, 40] });
  const sub = colEl('colBtnRota');
  if (sub) sub.textContent = '↗ Rota desenhada';
}

/** Desenha a linha real da estrada via OSRM (gratuito), com fallback para linha reta. */
async function colTracarRota() {
  if (!COL.mapa || !COL.rota) return;
  const paradas = COL.rota.paradas.filter(p => p.tem_coordenadas);
  if (paradas.length < 1) return colToast('Nenhuma parada com localização para traçar a rota.', true);
  const origem = COL.rota.origem;
  const coords = [[origem.lng, origem.lat], ...paradas.map(p => [p.lng, p.lat]), [origem.lng, origem.lat]]
    .map(c => c.join(',')).join(';');
  colToast('Calculando rota pelas ruas…');
  try {
    const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`;
    const res = await fetch(url, { cache: 'no-store' });
    const j = await res.json();
    if (!j.routes || !j.routes[0]) throw new Error('sem rota');
    const r = j.routes[0];
    if (COL.camadaRota) COL.mapa.removeLayer(COL.camadaRota);
    const grupo = L.layerGroup().addTo(COL.mapa);
    const latlngs = r.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
    L.polyline(latlngs, { color: '#5b8def', weight: 5, opacity: .85 }).addTo(grupo);
    L.marker([origem.lat, origem.lng], { icon: L.divIcon({ className: 'col-pin-wrap', html: '<span class="col-pin col-pin-origem">⌂</span>', iconSize: [30, 30], iconAnchor: [15, 30] }) }).addTo(grupo);
    paradas.forEach((p, i) => {
      L.marker([p.lat, p.lng], { icon: colIcone(p, i + 1) }).addTo(grupo)
        .bindPopup(`<div class="col-popup"><strong>${i + 1}. ${colEsc(p.fornecedor || '')}</strong><p>${colEsc(p.endereco_completo || '')}</p>
          <button type="button" class="col-popup-btn" onclick="colAbrirDetalhe('${colEsc(p.id)}')">Abrir coleta</button></div>`);
    });
    COL.camadaRota = grupo;
    COL.mapa.fitBounds(L.latLngBounds(latlngs), { padding: [40, 40] });
    const km = (r.distance / 1000).toFixed(1);
    const min = Math.round(r.duration / 60);
    const sub = colEl('colRouteSub');
    if (sub) sub.textContent = `${paradas.length} paradas · ${km} km pelas ruas · ${min} min`;
    colToast(`Rota pelas ruas: ${km} km · ${min} min`);
  } catch (e) {
    colDesenharRota();
    colToast('Serviço de rotas indisponível; mostrando ligação direta entre as paradas.');
  }
}

/* ---------------- dados ---------------- */

async function colCarregar() {
  try {
    const q = {
      q: colEl('colQ')?.value || '',
      status: colEl('colStatusFiltro')?.value || '',
      dia: colEl('colDiaFiltro')?.value || '',
    };
    const [lista, mapa, dash] = await Promise.all([
      colApi('coletas_listar', { query: q }),
      colApi('coletas_mapa'),
      colApi('coletas_dashboard'),
    ]);
    COL.coletas = lista.coletas;
    COL.pontos = mapa.pontos;
    colRenderKpis(dash, mapa);
    colRenderTabela();
    colDesenharPontos();
  } catch (e) {
    if (colEl('colBody')) colEl('colBody').innerHTML = `<tr><td colspan="6" class="empty">${colEsc(e.message)}</td></tr>`;
  }
}

/* ---------- v3.23.0: pipeline de status, rota montada à mão, rota ativa e motorista ---------- */
COL.sel = new Set(JSON.parse(localStorage.getItem('rd_col_sel') || '[]'));
COL.ordem = null;          // ordem manual (ids) depois de mexer nas setas
COL.ativa = null; COL.pos = null; COL.camadaPos = null;
const COL_PIPE = [['', 'Todos', '◈'], ['AGUARDANDO', 'Aguardando retirada', '◷'], ['ROTA', 'Rota de coleta', '↗'], ['ESTOQUE', 'Estoque', '▦'], ['FABRICACAO', 'Fabricação', '⚙'], ['COLETADO', 'Coletado', '✓']];
function colSalvarSel() { try { localStorage.setItem('rd_col_sel', JSON.stringify([...COL.sel])); } catch (e) {} }

function colRenderKpis(dash, mapa) {
  const k = dash.kpis || {}, atual = colEl('colStatusFiltro')?.value || '';
  const cont = { '': k.total, AGUARDANDO: k.aguardando, ROTA: k.rota, ESTOQUE: k.estoque, FABRICACAO: k.fabricacao, COLETADO: (k.coletado || 0) + (k.transporte || 0) + (k.chegou || 0) };
  const host = colEl('colKpis'); if (!host) return;
  host.dataset.origem = (dash.origem && dash.origem.nome) || '';
  host.innerHTML = COL_PIPE.map(([v, l, i]) => `<button type="button" role="tab" aria-selected="${atual === v}" class="col-pipe-item${atual === v ? ' on' : ''}" style="--cor:${COL_COR[v] || '#8fb3e8'}" onclick="colFiltrarStatus('${v}')">
      <span class="col-pipe-ic">${i}</span><strong>${colNum(cont[v] || 0)}</strong><span>${l}</span></button>`).join('')
    + `<div class="col-pipe-extra">${k.atrasadas ? `<button type="button" class="col-pill bad" onclick="colFiltrarStatus('ATRASADAS')">⚠ ${k.atrasadas} atrasada(s)</button>` : ''}${k.sem_local ? `<button type="button" class="col-pill warn" onclick="colFiltrarStatus('SEM_LOCAL')">⌖ ${k.sem_local} sem localização</button>` : ''}${k.hoje ? `<span class="col-pill">📅 ${k.hoje} para hoje</span>` : ''}</div>`;
  const leg = colEl('colLegend');
  if (leg) leg.innerHTML = Object.entries(COL_COR).filter(([x]) => x !== 'CANCELADA').map(([x, cor]) => `<span class="col-leg"><i style="background:${cor}"></i>${colEsc(COL.status[x] || x)}</span>`).join('')
    + '<span class="col-leg"><i style="background:#0b131f;border-color:#f87171"></i>Atrasada</span><span class="col-leg">🚚 Motorista</span>';
  const sub = colEl('colMapSub'); if (sub) sub.textContent = `${mapa.total} fornecedor(es) no mapa · saída: ${dash.origem ? dash.origem.nome : ''}`;
}
function colFiltrarStatus(v) { const s = colEl('colStatusFiltro'); if (s) s.value = v; colCarregar(); }

function colRenderTabela() {
  const ordem = colEl('colOrdem')?.value || 'prioridade';
  let lista = COL.coletas.slice();
  if (ordem === 'fornecedor') lista.sort((a, b) => String(a.fornecedor).localeCompare(String(b.fornecedor), 'pt-BR'));
  const fechada = c => ['COLETADO', 'TRANSPORTE', 'CHEGOU'].includes(c.status);
  colEl('colBody').innerHTML = lista.map(c => `
    <tr class="${COL.sel.has(c.id) ? 'col-row-sel' : ''}">
      <td class="col-ck">${fechada(c) ? '' : `<input type="checkbox" aria-label="Incluir ${colEsc(c.fornecedor)} na rota" ${COL.sel.has(c.id) ? 'checked' : ''} onchange="colMarcar('${colEsc(c.id)}',this.checked)">`}</td>
      <td><code>${colEsc(c.id)}</code><small class="est-sub">${c.pc ? 'PC ' + colEsc(c.pc) : ''}${c.prioridade === 'URGENTE' ? ' <span class="est-badge danger">urgente</span>' : ''}</small></td>
      <td><strong>${colEsc(c.fornecedor || '—')}</strong><small class="est-sub">${colEsc(c.endereco_completo || 'sem endereço')}</small></td>
      <td>${c.agendada_data ? colData(c.agendada_data) : '—'}${c.agendada_hora ? `<small class="est-sub">${colEsc(c.agendada_hora)}</small>` : ''}
        ${c.atrasada ? '<span class="est-badge danger">atrasada</span>' : ''}</td>
      <td><span class="col-status" style="--cor:${COL_COR[c.status] || '#5b8def'}">${colEsc(c.status_label)}</span>
        ${c.retornos_count ? `<small class="est-sub">${c.retornos_count} retorno(s)</small>` : ''}</td>
      <td>${c.tem_coordenadas ? '<span class="est-badge ok">no mapa</span>' : `<span class="est-badge warn">${colEsc(c.geo_status || 'sem local')}</span>`}</td>
      <td class="est-actions"><button class="button ghost small" onclick="colAbrirDetalhe('${colEsc(c.id)}')">Abrir</button></td>
    </tr>`).join('') || '<tr><td colspan="7" class="empty">Nenhuma coleta neste filtro. Clique em “Nova coleta” ou importe pedidos.</td></tr>';
  const todas = colEl('colSelTodas'); if (todas) { const ab = lista.filter(c => !fechada(c)); todas.checked = ab.length > 0 && ab.every(c => COL.sel.has(c.id)); }
}

function colMarcar(id, on) { on ? COL.sel.add(id) : COL.sel.delete(id); COL.ordem = null; colSalvarSel(); colRenderTabela(); colCarregarRota(); }
function colMarcarTodas(on) { COL.coletas.filter(c => !['COLETADO', 'TRANSPORTE', 'CHEGOU'].includes(c.status)).forEach(c => on ? COL.sel.add(c.id) : COL.sel.delete(c.id)); COL.ordem = null; colSalvarSel(); colRenderTabela(); colCarregarRota(); }
function colLimparSel() { COL.sel.clear(); COL.ordem = null; colSalvarSel(); colRenderTabela(); colCarregarRota(); }

function colFiltro() { colCarregar(); }

async function colCarregarRota() {
  try {
    const at = await colApi('coletas_rota_ativa');
    COL.ativa = at.rota; COL.pos = at.posicao; colDesenharMotorista();
    if (COL.ativa) return colRenderRotaAtiva();
    const ids = COL.ordem || [...COL.sel];
    const r = ids.length ? await colApi('coletas_rota_escolhida', { query: { ids: ids.join(','), otimizar: COL.ordem ? '0' : '1' } }) : null;
    COL.rota = r;
    colRenderMontagem(r);
  } catch (e) { colEl('colRouteList').innerHTML = `<li class="col-empty">${colEsc(e.message)}</li>`; }
}

function colLinkMaps(r) {
  const o = r.origem, ps = r.paradas.filter(p => p.tem_coordenadas && !p.concluida).slice(0, 9);
  if (!ps.length) return '';
  const way = ps.slice(0, -1).map(p => `${p.lat},${p.lng}`).join('|'), fim = ps[ps.length - 1];
  return `https://www.google.com/maps/dir/?api=1&origin=${o.lat},${o.lng}&destination=${fim.lat},${fim.lng}${way ? '&waypoints=' + encodeURIComponent(way) : ''}&travelmode=driving`;
}
function colTextoWA(r, titulo) {
  const linhas = r.paradas.map((p, i) => `${i + 1}. *${p.fornecedor}*${p.pc ? ' — PC ' + p.pc : ''}\n   ${p.endereco_completo || 'sem endereço'}${p.contato ? '\n   ☎ ' + p.contato : ''}`);
  r.sem_local.forEach(p => linhas.push(`• *${p.fornecedor}*${p.pc ? ' — PC ' + p.pc : ''} (sem localização)`));
  const link = colLinkMaps(r);
  return `🚚 *${titulo}*\n${r.paradas.length + r.sem_local.length} parada(s) · ~${colNum(r.km_estimado)} km\n\n${linhas.join('\n')}${link ? '\n\n🗺 Rota no Google Maps:\n' + link : ''}\n\n📱 Atualize as coletas em: ${location.origin}/motorista.html`;
}

function colRenderMontagem(r) {
  colEl('colPcsForm').hidden = false;
  const lista = colEl('colRouteList'), act = colEl('colRouteAct');
  colEl('colRouteTitulo').textContent = 'Rota de coleta'; colEl('colRouteBadge').hidden = true; colEl('colRouteMotorista').innerHTML = '';
  if (!r) {
    colEl('colRouteSub').textContent = '0 paradas';
    lista.innerHTML = `<li class="col-route-vazia"><span>☐→↗</span><strong>Digite os PCs acima ou marque pedidos na lista.</strong><small>A ordem é calculada a partir da Drilling (São José da Lapa), pela menor distância, com urgentes na frente.</small></li>`;
    act.innerHTML = COL.coletas.some(c => c.status === 'AGUARDANDO' && c.tem_coordenadas) ? '<button class="button ghost small" onclick="colSugerir()">✦ Sugerir com todas as aguardando retirada</button>' : '';
    if (COL.camadaRota) { COL.mapa.removeLayer(COL.camadaRota); COL.camadaRota = null; }
    return;
  }
  const n = r.paradas.length + r.sem_local.length;
  COL.rua = null;
  colEl('colRouteSub').innerHTML = `<b>${n} parada(s)</b> · <span id="colRuaInfo">~${colNum(r.km_estimado)} km (estimado) · ${colHm(Math.round(r.km_estimado / 40 * 60))} rodando</span> · ${colHm(r.min_estimado)} com as paradas`;
  lista.innerHTML = `<li class="col-stop col-stop-origem"><span class="col-stop-num">⌂</span><span class="col-stop-body"><strong>Saída</strong><small>${colEsc(r.origem.nome)}</small></span></li>`
    + r.paradas.map((p, i) => `<li class="col-stop">
        <span class="col-stop-num" style="background:${COL_COR[p.status] || '#5b8def'}">${i + 1}</span>
        <span class="col-stop-body" onclick="colAbrirDetalhe('${colEsc(p.id)}')"><strong>${colEsc(p.fornecedor || 'Fornecedor')}</strong>
          <small>${p.pc ? 'PC ' + colEsc(p.pc) : colEsc(p.id)} · ${colEsc(p.cidade || p.bairro || '')}${p.distancia_anterior_km ? ' · +' + colNum(p.distancia_anterior_km) + ' km' : ''}</small></span>
        <span class="col-stop-ctl"><button title="Subir" ${i ? '' : 'disabled'} onclick="colMover(${i},-1)">↑</button><button title="Descer" ${i < r.paradas.length - 1 ? '' : 'disabled'} onclick="colMover(${i},1)">↓</button><button title="Tirar da rota" onclick="colMarcar('${colEsc(p.id)}',false)">✕</button></span>
      </li>`).join('')
    + r.sem_local.map(p => `<li class="col-stop col-stop-warn"><span class="col-stop-num">!</span><span class="col-stop-body" onclick="colAbrirDetalhe('${colEsc(p.id)}')"><strong>${colEsc(p.fornecedor)}</strong><small>Sem localização — entra no fim da lista</small></span><span class="col-stop-ctl"><button onclick="colMarcar('${colEsc(p.id)}',false)">✕</button></span></li>`).join('')
    + `<li class="col-stop col-stop-origem"><span class="col-stop-num">⌂</span><span class="col-stop-body"><strong>Volta</strong><small>Drilling</small></span></li>`;
  const link = colLinkMaps(r);
  act.innerHTML = `<label class="col-motorista-campo"><span>Motorista</span><input id="colMotorista" list="colMotoristas" placeholder="Nome do motorista" value="${colEsc(localStorage.getItem('rd_col_motorista') || '')}"></label>
    <div class="col-route-btns">
      <button class="button primary" onclick="colIniciarRota()">▶ Iniciar rota</button>
      ${link ? `<a class="button ghost" href="${link}" target="_blank" rel="noopener">🗺 Google Maps</a>` : ''}
      <button class="button ghost" onclick="colEnviarWA()">◉ Enviar no WhatsApp</button>
      <button class="button ghost small" onclick="colTracarRota()">↗ Ver no mapa</button>
      ${COL.ordem ? '<button class="button ghost small" onclick="COL.ordem=null;colCarregarRota()">✦ Otimizar ordem</button>' : ''}
      <button class="button ghost small" onclick="colLimparSel()">Limpar</button></div>`;
  colDesenharRota();
  colCalcRua(r);
}

const colHm = m => { m = Math.max(0, Math.round(m || 0)); return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0'); };

/** km e tempo pelas ruas (OSRM, gratuito). Se o serviço não responder, fica a estimativa (linha reta × 1,3). */
async function colCalcRua(r) {
  const ps = r.paradas.filter(p => p.tem_coordenadas); if (!ps.length) return;
  const o = r.origem, chave = ps.map(p => p.id).join(',');
  const coords = [[o.lng, o.lat], ...ps.map(p => [p.lng, p.lat]), [o.lng, o.lat]].map(c => c.join(',')).join(';');
  try {
    const ctl = new AbortController(); setTimeout(() => ctl.abort(), 9000);
    const j = await (await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=false`, { signal: ctl.signal })).json();
    if (!j.routes || !j.routes[0] || !COL.rota || COL.rota.paradas.filter(p => p.tem_coordenadas).map(p => p.id).join(',') !== chave) return;
    COL.rua = { km: j.routes[0].distance / 1000, min: Math.round(j.routes[0].duration / 60) };
    const el = colEl('colRuaInfo'); if (el) el.innerHTML = `<b>${colNum(COL.rua.km)} km</b> pelas ruas · <b>${colHm(COL.rua.min)}</b> rodando`;
  } catch (e) { /* mantém a estimativa */ }
}

async function colRotaPorPcs(ev) {
  ev.preventDefault();
  const pcs = (colEl('colPcs').value.match(/\d{3,6}/g) || []).map(Number);
  if (!pcs.length) return colToast('Digite os números dos PCs.', true);
  const btn = ev.target.querySelector('button'); btn.disabled = true; btn.textContent = 'Montando…';
  try {
    const r = await colPost('coletas_rota_por_pcs', { pcs });
    COL.sel = new Set(r.ids); COL.ordem = null; colSalvarSel();
    colEl('colPcs').value = '';
    if (r.avisos.length) alert('Atenção:\n\n' + r.avisos.join('\n'));
    colToast(`${r.ids.length} parada(s) na rota${r.criadas.length ? ` · ${r.criadas.length} coleta(s) criada(s)` : ''}`);
    await colCarregar(); await colCarregarRota();
  } catch (e) { colToast(e.message, true); }
  finally { btn.disabled = false; btn.textContent = 'Montar rota'; }
}

function colMontarMeses() {
  const sel = colEl('colHistMes'); if (!sel) return;
  const d = new Date(); let h = '';
  for (let i = 0; i < 12; i++) { const x = new Date(d.getFullYear(), d.getMonth() - i, 1); const v = x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0');
    h += `<option value="${v}">${x.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}</option>`; }
  sel.innerHTML = h;
}
async function colCarregarHist() {
  const v = colEl('colHistMes')?.value; if (!v) return;
  const [a, m] = v.split('-').map(Number), ult = new Date(a, m, 0).getDate();
  try {
    const r = await colApi('coletas_rotas_resumo', { query: { de: v + '-01', ate: v + '-' + String(ult).padStart(2, '0') } });
    COL.hist = r; const t = r.totais;
    colEl('colHistKpis').innerHTML = [['Rotas', colNum(t.rotas)], ['Km rodados', colNum(t.km) + ' km'], ['Tempo de deslocamento', colHm(t.min_deslocamento)], ['Tempo total em rota', t.rotas ? colHm(t.min_total) : '—'],
      ['Paradas', colNum(t.paradas)], ['Coletadas', colNum(t.coletadas)], ['Não coletadas', colNum(t.nao_coletadas)], ['Km por rota', t.rotas ? colNum(t.km / t.rotas) + ' km' : '—']]
      .map(([l, v2]) => `<div><strong>${v2}</strong><span>${l}</span></div>`).join('');
    colEl('colHistBody').innerHTML = r.rotas.map(x => `<tr><td><strong>${colDataHora(x.em)}</strong><small class="est-sub">${colEsc(x.id)}${x.ativa ? ' · <b style="color:#5ee2b8">em andamento</b>' : ''}</small></td>
      <td>${colEsc(x.motorista || '—')}</td><td>${x.paradas}</td><td>${x.coletadas}${x.nao_coletadas ? ` <small class="est-sub">${x.nao_coletadas} não</small>` : ''}</td>
      <td>${colNum(x.km)} km<small class="est-sub">${x.km_fonte === 'ruas' ? 'pelas ruas' : 'estimado'}${x.km_motorista != null ? ' · motorista: ' + colNum(x.km_motorista) + ' km' : ''}</small></td>
      <td>${colHm(x.min_deslocamento)}</td><td>${x.min_total != null ? colHm(x.min_total) : (x.ativa ? 'em andamento' : '—')}</td></tr>`).join('')
      || '<tr><td colspan="7" class="empty">Nenhuma rota iniciada neste mês.</td></tr>';
  } catch (e) { colEl('colHistBody').innerHTML = `<tr><td colspan="7" class="empty">${colEsc(e.message)}</td></tr>`; }
}
async function colHistExcel() {
  const r = COL.hist; if (!r || !r.rotas.length) return colToast('Nenhuma rota no período.', true);
  const X = await import('./xlsx-lib.js');
  const head = ['Início', 'Encerrada', 'Rota', 'Motorista', 'Paradas', 'Coletadas', 'Não coletadas', 'Km', 'Origem do km', 'Km informado pelo motorista', 'Deslocamento (min)', 'Duração total (min)'];
  const rows = r.rotas.map(x => [colDataHora(x.em), x.encerrada_em ? colDataHora(x.encerrada_em) : '', x.id, x.motorista, x.paradas, x.coletadas, x.nao_coletadas, x.km, x.km_fonte === 'ruas' ? 'pelas ruas' : 'estimado', x.km_motorista ?? '', x.min_deslocamento, x.min_total ?? '']);
  const t = r.totais; rows.push([], ['TOTAL', '', '', '', t.paradas, t.coletadas, t.nao_coletadas, t.km, '', '', t.min_deslocamento, t.min_total]);
  const ws = X.utils.aoa_to_sheet([head, ...rows]); ws['!cols'] = head.map(h => ({ wch: Math.max(12, h.length + 2) }));
  const wb = X.utils.book_new(); X.utils.book_append_sheet(wb, ws, 'Rotas'); X.writeFile(wb, `rotas_coleta_${colEl('colHistMes').value}.xlsx`);
}

function colMover(i, d) {
  const ids = COL.rota.paradas.map(p => p.id), j = i + d;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  COL.ordem = [...ids, ...COL.rota.sem_local.map(p => p.id)];
  colCarregarRota();
}
function colSugerir() { COL.coletas.filter(c => c.status === 'AGUARDANDO' && c.tem_coordenadas).forEach(c => COL.sel.add(c.id)); COL.ordem = null; colSalvarSel(); colRenderTabela(); colCarregarRota(); }

function colEnviarWA() {
  if (!COL.rota) return;
  const nome = colEl('colMotorista')?.value || '';
  window.open('https://wa.me/?text=' + encodeURIComponent(colTextoWA(COL.rota, 'Rota de coleta ' + new Date().toLocaleDateString('pt-BR') + (nome ? ' — ' + nome : ''))), '_blank');
}

async function colIniciarRota() {
  const ids = COL.ordem || [...COL.sel], motorista = colEl('colMotorista')?.value || '';
  try { localStorage.setItem('rd_col_motorista', motorista); } catch (e) {}
  try {
    await colPost('coletas_rota_iniciar', { ids: COL.rota ? [...COL.rota.paradas.map(p => p.id), ...COL.rota.sem_local.map(p => p.id)] : ids, motorista, otimizar: false, rua: COL.rua || {} });
    colCarregarHist();
    COL.sel.clear(); COL.ordem = null; colSalvarSel();
    colToast('Rota iniciada. As coletas passaram para "Rota de coleta".');
    colCarregar(); colCarregarRota();
  } catch (e) { colToast(e.message, true); }
}
async function colEncerrarRota() {
  const pend = COL.ativa ? COL.ativa.total - COL.ativa.feitas : 0;
  if (!confirm(pend ? `Ainda há ${pend} parada(s) sem coleta. Encerrar a rota mesmo assim? (as coletas continuam no status atual)` : 'Encerrar a rota?')) return;
  try { await colPost('coletas_rota_encerrar', {}); colToast('Rota encerrada.'); colCarregarRota(); colCarregar(); colCarregarHist(); } catch (e) { colToast(e.message, true); }
}

function colRenderRotaAtiva() {
  colEl('colPcsForm').hidden = true;
  const r = COL.ativa; COL.rota = r;
  colEl('colRouteTitulo').textContent = 'Rota em andamento';
  const b = colEl('colRouteBadge'); b.hidden = false; b.textContent = `${r.feitas}/${r.total}`;
  colEl('colRouteSub').textContent = `${r.motorista ? r.motorista + ' · ' : ''}iniciada ${colDataHora(r.criada_em)} · ~${colNum(r.km_estimado)} km`;
  const p = COL.pos, idade = p ? Math.round((Date.now() - new Date(p.em)) / 60000) : null;
  colEl('colRouteMotorista').innerHTML = `<div class="col-progress"><i style="width:${r.total ? Math.round(r.feitas / r.total * 100) : 0}%"></i></div>
    <p class="col-pos ${p && idade <= 10 ? 'ok' : ''}">${p ? `🚚 Última posição do motorista há ${idade < 1 ? 'menos de 1' : idade} min <button class="linkbtn" onclick="colCentrarMotorista()">ver no mapa</button>` : '🚚 O motorista ainda não compartilhou a localização (Modo motorista → "Compartilhar localização").'}</p>`;
  const nColetadas = r.paradas.filter(x => x.concluida).length, nNao = r.paradas.filter(x => x.nao_coletada).length, nRota = r.paradas.length - nColetadas - nNao;
  colEl('colRouteMotorista').innerHTML += `<p class="col-pos"><b>${nRota}</b> em rota · <b>${nColetadas}</b> coletada(s) · <b>${nNao}</b> não coletada(s)</p>`;
  colEl('colRouteList').innerHTML = r.paradas.map((x, i) => `<li class="col-stop ${x.concluida ? 'col-stop-ok' : ''} ${x.nao_coletada ? 'col-stop-warn' : ''}" onclick="colAbrirDetalhe('${colEsc(x.id)}')">
      <span class="col-stop-num" style="background:${COL_COR[x.status] || '#5b8def'}">${x.concluida ? '✓' : x.nao_coletada ? '✕' : i + 1}</span>
      <span class="col-stop-body"><strong>${colEsc(x.fornecedor)}</strong><small>${x.pc ? 'PC ' + colEsc(x.pc) + ' · ' : ''}${x.nao_coletada ? 'Não coletado — ' + colEsc(x.nao_coletada_motivo) : colEsc(x.status_label)}${x.concluida && x.coletado_em ? ' · coletado às ' + new Date(x.coletado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ''}${x.concluida && x.coletado_geo ? ` · <a href="https://www.google.com/maps?q=${x.coletado_geo.lat},${x.coletado_geo.lng}" target="_blank" rel="noopener" onclick="event.stopPropagation()">📍 local</a>${x.coletado_geo.longe ? ` <b style="color:#f5b544" title="A localização do celular ficou longe do endereço cadastrado (só informativo; o GPS pode errar ou o endereço pode precisar de ajuste)">⚠ a ${String(x.coletado_geo.dist_fornecedor_km).replace('.', ',')} km do endereço</b>` : ''}` : ''}</small></span></li>`).join('')
    + r.sem_local.map(x => `<li class="col-stop col-stop-warn"><span class="col-stop-num">!</span><span class="col-stop-body"><strong>${colEsc(x.fornecedor)}</strong><small>${colEsc(x.status_label)} · sem localização</small></span></li>`).join('');
  const link = colLinkMaps(r);
  colEl('colRouteAct').innerHTML = `<div class="col-route-btns">${link ? `<a class="button ghost" href="${link}" target="_blank" rel="noopener">🗺 Google Maps</a>` : ''}
    <button class="button ghost" onclick="window.open('https://wa.me/?text='+encodeURIComponent(colTextoWA(COL.rota,'Rota de coleta em andamento')),'_blank')">◉ Reenviar no WhatsApp</button>
    <button class="button ghost" onclick="colMotorista()">📱 App do motorista</button>
    <button class="button ghost small" onclick="colTracarRota()">↗ Ver no mapa</button>
    <button class="button ghost small danger" onclick="colEncerrarRota()">■ Encerrar rota</button></div>`;
  colDesenharRota();
}

function colDesenharMotorista() {
  if (!COL.mapa) return;
  if (COL.camadaPos) { COL.mapa.removeLayer(COL.camadaPos); COL.camadaPos = null; }
  if (!COL.pos) return;
  COL.camadaPos = L.marker([COL.pos.lat, COL.pos.lng], { zIndexOffset: 1000, icon: L.divIcon({ className: 'col-pin-wrap', html: '<span class="col-truck">🚚</span>', iconSize: [36, 36], iconAnchor: [18, 18] }) })
    .addTo(COL.mapa).bindPopup(`<strong>Motorista</strong><small>${colEsc(COL.pos.por || '')} · ${colDataHora(COL.pos.em)}</small>`);
}
function colCentrarMotorista() { if (COL.pos && COL.mapa) { COL.mapa.setView([COL.pos.lat, COL.pos.lng], 14); COL.camadaPos?.openPopup(); } }
// Atualiza a posição do motorista e o andamento da rota a cada 60 s enquanto a tela de coletas estiver aberta.
setInterval(() => { const pg = document.getElementById('page-coletas'); if (pg && pg.classList.contains('active') && !document.hidden && COL.carregado && COL.ativa) { COL.semZoom = true; colCarregarRota().finally(() => { COL.semZoom = false; }); } }, 60000);

/* ---------------- formulário ---------------- */

function colLimparForm() {
  ['colPc', 'colFornecedor', 'colCnpj', 'colContato', 'colEndereco', 'colNumero', 'colComplemento', 'colBairro', 'colCidade', 'colUf', 'colCep', 'colData', 'colHora', 'colObs']
    .forEach(id => { const el = colEl(id); if (el) el.value = ''; });
  colEl('colPrioridade').value = 'NORMAL'; if (colEl('colMotivoAtraso')) colEl('colMotivoAtraso').value = '';
  colEl('colGeoStatus').textContent = 'Ainda não localizada';
  COL.pickerLatLng = null;
}

function colNovaColeta() {
  COL.coletaAtual = null;
  colLimparForm();
  colEl('colModalTitle').textContent = 'Nova coleta';
  colEl('colModalSub').textContent = 'Informe o PC para puxar o fornecedor automaticamente.';
  colEl('colModal').classList.add('on');
}

async function colPuxarPedido() {
  const pc = Number(colEl('colPc').value);
  if (!pc) return;
  try {
    const r = await colPost('coletas_importar', { pc });
    const p = r.pedido;
    if (!colEl('colFornecedor').value) colEl('colFornecedor').value = p.fornecedor || '';
    if (!colEl('colCnpj').value) colEl('colCnpj').value = p.cnpj || '';
    if (!colEl('colObs').value && p.observacao) colEl('colObs').value = String(p.observacao).slice(0, 200);
    colToast('Dados do PC ' + pc + ' carregados. Complete o endereço do fornecedor.');
  } catch (e) { colToast(e.message, true); }
}

async function colSalvar(ev) {
  ev.preventDefault();
  const body = {
    id: COL.coletaAtual?.id || '',
    pc: Number(colEl('colPc').value) || 0,
    fornecedor: colEl('colFornecedor').value,
    cnpj: colEl('colCnpj').value,
    contato: colEl('colContato').value,
    endereco: colEl('colEndereco').value,
    numero: colEl('colNumero').value,
    complemento: colEl('colComplemento').value,
    bairro: colEl('colBairro').value,
    cidade: colEl('colCidade').value,
    uf: colEl('colUf').value,
    cep: colEl('colCep').value,
    agendada_data: colEl('colData').value,
    agendada_hora: colEl('colHora').value,
    prioridade: colEl('colPrioridade').value,
    motivo_atraso: colEl('colMotivoAtraso').value,
    observacao: colEl('colObs').value,
  };
  if (COL.pickerLatLng) { body.lat = COL.pickerLatLng.lat; body.lng = COL.pickerLatLng.lng; }
  try {
    const r = await colPost('coletas_salvar', body);
    colEl('colModal').classList.remove('on');
    colToast(r.novo ? 'Coleta criada' : 'Coleta atualizada');
    await colCarregar(); await colCarregarRota();
    if (r.coleta && r.coleta.tem_coordenadas === false && (body.endereco || '').length > 4) {
      colGeocodar(r.coleta.id, true);
    }
  } catch (e) { colToast('Não foi possível salvar: ' + e.message, true); }
}

async function colGeocodarAtual() {
  const body = {
    id: COL.coletaAtual?.id || '',
    endereco: colEl('colEndereco').value, numero: colEl('colNumero').value,
    bairro: colEl('colBairro').value, cidade: colEl('colCidade').value,
    uf: colEl('colUf').value, cep: colEl('colCep').value,
  };
  if (!body.endereco) return colToast('Preencha o endereço antes de localizar.', true);
  colEl('colGeoStatus').textContent = 'Consultando o serviço de localização…';
  try {
    // Salva primeiro (para ter um id) e depois geocodifica.
    if (!COL.coletaAtual?.id) {
      const r = await colPost('coletas_salvar', {
        pc: Number(colEl('colPc').value) || 0, fornecedor: colEl('colFornecedor').value,
        cnpj: colEl('colCnpj').value, contato: colEl('colContato').value,
        endereco: body.endereco, numero: body.numero, complemento: colEl('colComplemento').value,
        bairro: body.bairro, cidade: body.cidade, uf: body.uf, cep: body.cep,
        agendada_data: colEl('colData').value, agendada_hora: colEl('colHora').value,
        prioridade: colEl('colPrioridade').value, motivo_atraso: colEl('colMotivoAtraso').value, observacao: colEl('colObs').value,
      });
      COL.coletaAtual = r.coleta;
    }
    const g = await colPost('coletas_geocodar', { id: COL.coletaAtual.id, forcar: true });
    if (g.resultado.ok) {
      colEl('colGeoStatus').textContent = `Localizada (${g.coleta.geo_precisao}) · ${g.coleta.lat.toFixed(5)}, ${g.coleta.lng.toFixed(5)}`;
      COL.pickerLatLng = { lat: g.coleta.lat, lng: g.coleta.lng };
      colToast('Endereço localizado no mapa.');
    } else {
      colEl('colGeoStatus').textContent = g.resultado.mensagem || 'Não localizado';
      colToast(g.resultado.mensagem || 'Não foi possível localizar', true);
    }
    await colCarregar(); await colCarregarRota();
  } catch (e) { colEl('colGeoStatus').textContent = e.message; colToast(e.message, true); }
}

async function colGeocodar(id, silencioso = false) {
  try {
    const g = await colPost('coletas_geocodar', { id });
    if (!silencioso) colToast(g.resultado.ok ? 'Endereço localizado' : (g.resultado.mensagem || 'Não localizado'), !g.resultado.ok);
    await colCarregar(); await colCarregarRota();
  } catch (e) { if (!silencioso) colToast(e.message, true); }
}

async function colGeocodarTodas() {
  const pendentes = COL.coletas.filter(c => !c.tem_coordenadas && (c.endereco || '').length > 4);
  if (!pendentes.length) return colToast('Todas as coletas já estão no mapa.');
  colToast(`Localizando ${pendentes.length} coleta(s)…`);
  for (const c of pendentes) {
    await colGeocodar(c.id, true);
  }
  colToast('Localização concluída.');
}

/* ---------------- detalhe ---------------- */

async function colAbrirDetalhe(id) {
  try {
    const r = await colApi('coletas_detalhe', { query: { id } });
    const c = r.coleta;
    COL.coletaAtual = c;
    colEl('colDetTitle').textContent = c.fornecedor || 'Coleta ' + c.id;
    colEl('colDetSub').textContent = `${c.id}${c.pc ? ' · PC ' + c.pc : ''} · ${c.status_label}`;
    const opts = Object.entries(COL.status).map(([k, v]) => `<option value="${k}" ${k === c.status ? 'selected' : ''}>${colEsc(v)}</option>`).join('');
    colEl('colDetBody').innerHTML = `
      <div class="col-det-grid">
        <div><small>Endereço</small><strong>${colEsc(c.endereco_completo || '—')}</strong></div>
        <div><small>Agendada</small><strong>${c.agendada_data ? colData(c.agendada_data) : '—'}${c.agendada_hora ? ' ' + colEsc(c.agendada_hora) : ''}</strong></div>
        <div><small>Prioridade</small><strong>${colEsc(c.prioridade_label)}</strong></div>
        ${c.motivo_atraso_label ? `<div><small>Motivo do atraso</small><strong>${colEsc(c.motivo_atraso_label)}</strong></div>` : ''}
        <div><small>Localização</small><strong>${c.tem_coordenadas ? 'No mapa (' + colEsc(c.geo_precisao || 'aprox') + ')' : colEsc(c.geo_status || 'não localizada')}</strong></div>
        <div><small>CNPJ</small><strong>${colEsc(c.cnpj || '—')}</strong></div>
        <div><small>Contato</small><strong>${colEsc(c.contato || '—')}</strong></div>
      </div>
      ${c.observacao ? `<p class="col-obs">${colEsc(c.observacao)}</p>` : ''}
      <div class="col-det-actions">
        <label class="form-line"><span>Alterar status</span><select id="colDetStatus">${opts}</select></label>
        <label class="form-line"><span>Km rodado (ida e volta)</span><input id="colDetKm" type="number" min="0" step="0.1" inputmode="decimal" placeholder="opcional" value="${c.km_real ?? ''}" style="width:120px"></label>
        <button class="button primary small" onclick="colMudarStatus()">Aplicar</button>
        <button class="button ghost small" onclick="colEditar()">Editar dados</button>
        <button class="button ghost small" onclick="colAbrirRetorno()">Registrar retorno</button>
        ${c.tem_coordenadas ? `<a class="button ghost small" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=${c.lat},${c.lng}">Abrir no Google Maps</a>` : ''}
      </div>
      <h4 class="col-h4">Histórico</h4>
      <div class="col-hist">${(c.historico || []).slice().reverse().map(h => `
        <div class="col-hist-row"><span>${colDataHora(h.em)}</span><strong>${colEsc(h.acao)}</strong>
        <small>${colEsc(h.detalhe || (h.de ? h.de + ' → ' + h.para : ''))}${h.por ? ' · ' + colEsc(h.por) : ''}</small></div>`).join('') || '<p class="col-empty">Sem movimentações.</p>'}</div>
      ${(c.retornos || []).length ? `<h4 class="col-h4">Retornos</h4><div class="col-hist">${c.retornos.map(r2 => `
        <div class="col-hist-row"><span>${colDataHora(r2.em)}</span><strong>${colEsc(r2.motivo_label)}</strong>
        <small>${colEsc(r2.observacao || '')}${r2.reagendada_para ? ' · reagendada para ' + colData(r2.reagendada_para) : ''}</small></div>`).join('')}</div>` : ''}`;
    colEl('colDetFoot').innerHTML = `<button class="button ghost" onclick="colFecharDetalhe()">Fechar</button>`;
    colEl('colDetalhe').classList.add('on');
  } catch (e) { colToast(e.message, true); }
}

function colFecharDetalhe() { colEl('colDetalhe').classList.remove('on'); }

async function colMudarStatus() {
  try {
    await colPost('coletas_status', { id: COL.coletaAtual.id, status: colEl('colDetStatus').value, km_real: colEl('colDetKm') ? colEl('colDetKm').value : null });
    colToast('Status atualizado');
    colFecharDetalhe();
    await colCarregar(); await colCarregarRota();
  } catch (e) { colToast(e.message, true); }
}

function colEditar() {
  const c = COL.coletaAtual;
  if (!c) return;
  colFecharDetalhe();
  colEl('colModalTitle').textContent = 'Editar coleta ' + c.id;
  colEl('colModalSub').textContent = 'Alterar o endereço apaga a localização e exige localizar de novo.';
  colEl('colPc').value = c.pc || '';
  colEl('colFornecedor').value = c.fornecedor || '';
  colEl('colCnpj').value = c.cnpj || '';
  colEl('colContato').value = c.contato || '';
  colEl('colEndereco').value = c.endereco || '';
  colEl('colNumero').value = c.numero || '';
  colEl('colComplemento').value = c.complemento || '';
  colEl('colBairro').value = c.bairro || '';
  colEl('colCidade').value = c.cidade || '';
  colEl('colUf').value = c.uf || '';
  colEl('colCep').value = c.cep || '';
  colEl('colData').value = c.agendada_data || '';
  colEl('colHora').value = c.agendada_hora || '';
  colEl('colPrioridade').value = c.prioridade || 'NORMAL';
  colEl('colMotivoAtraso').value = c.motivo_atraso || '';
  colEl('colObs').value = c.observacao || '';
  colEl('colGeoStatus').textContent = c.tem_coordenadas ? `Localizada (${c.geo_precisao || 'aprox'})` : 'Ainda não localizada';
  // Mantém as coordenadas ao editar (só mudam se o operador escolher outro ponto).
  COL.pickerLatLng = c.tem_coordenadas ? { lat: c.lat, lng: c.lng } : null;
  colEl('colModal').classList.add('on');
}

function colFechar() { colEl('colModal').classList.remove('on'); }

function colAbrirRetorno() {
  if (!COL.coletaAtual) return;
  colEl('colRetObs').value = '';
  colEl('colRetData').value = '';
  colEl('colRetHora').value = '';
  colEl('colRetornoModal').classList.add('on');
}

async function colSalvarRetorno(ev) {
  ev.preventDefault();
  try {
    await colPost('coletas_retorno', {
      id: COL.coletaAtual.id,
      motivo: colEl('colRetMotivo').value,
      observacao: colEl('colRetObs').value,
      reagendar_data: colEl('colRetData').value,
      reagendar_hora: colEl('colRetHora').value,
    });
    colEl('colRetornoModal').classList.remove('on');
    colFecharDetalhe();
    colToast('Retorno registrado; coleta voltou para a fila.');
    await colCarregar(); await colCarregarRota();
  } catch (e) { colToast(e.message, true); }
}

/* ---------------- escolher ponto no mapa ---------------- */

function colAbrirMapaPicker() {
  colEl('colPicker').hidden = false;
  const centro = COL.pickerLatLng ? [COL.pickerLatLng.lat, COL.pickerLatLng.lng] : [-19.6986, -43.9586];
  if (!COL.pickerMapa) {
    COL.pickerMapa = L.map('colPickerMap').setView(centro, 12);
    const t = COL_TILES[COL.tiles] || COL_TILES.voyager;
    L.tileLayer(t.url, { attribution: t.attr, maxZoom: 19 }).addTo(COL.pickerMapa);
    COL.pickerMapa.on('click', ev => {
      COL.pickerLatLng = { lat: ev.latlng.lat, lng: ev.latlng.lng };
      if (COL.pickerMarcador) COL.pickerMapa.removeLayer(COL.pickerMarcador);
      COL.pickerMarcador = L.marker(ev.latlng).addTo(COL.pickerMapa);
    });
  } else {
    COL.pickerMapa.setView(centro, 12);
  }
  setTimeout(() => COL.pickerMapa.invalidateSize(), 120);
}

function colUsarPicker() {
  if (!COL.pickerLatLng) return colToast('Clique no mapa para marcar o ponto.', true);
  colEl('colGeoStatus').textContent = `Ponto escolhido: ${COL.pickerLatLng.lat.toFixed(5)}, ${COL.pickerLatLng.lng.toFixed(5)}`;
  colEl('colPicker').hidden = true;
  colToast('Ponto definido. Clique em “Salvar coleta”.');
}

function colFecharPicker() { colEl('colPicker').hidden = true; }

// coletasInit() é chamado por goPage('coletas') em app.js.


/* ---------- v3.13.0: importar pedidos de coleta (só cria coletas) ---------- */
function colImportarPedidos() {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(980px,100%)">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>Importar pedidos de coleta</h2><button type="button" class="icon-btn" data-x>×</button></div>
    <p class="muted" style="margin:4px 0 12px">Envie o <b>PDF do pedido</b> (um ou vários) ou o <b>ZIP exportado do WhatsApp</b> — até 1 GB por arquivo. Aqui só são criadas <b>coletas</b> — pedidos, estoque e indicadores não são alterados.</p>
    <label class="coli-drop"><input type="file" multiple accept=".pdf,.zip,application/pdf,application/zip"><span>Clique para escolher os arquivos ou arraste aqui</span></label>
    <div class="coli-res"></div></div>`;
  document.body.appendChild(bg);
  const fechar = () => bg.remove();
  bg.querySelector('[data-x]').onclick = fechar;
  const res = bg.querySelector('.coli-res'), inp = bg.querySelector('input[type=file]');
  inp.onchange = async () => {
    if (!inp.files.length) return;
    const arqs = [...inp.files];
    try {
      const ids = [];
      for (const [i, f] of arqs.entries()) {
        ids.push(await rdUploadGrande(f, { acao: 'upload_parte_coletas', progresso: p => { res.innerHTML = `<p class="muted">Enviando ${i + 1}/${arqs.length}: ${colEsc(f.name)} (${rdTamanho(f.size)}) — ${p}%</p>`; } }));
      }
      res.innerHTML = '<p class="muted">Lendo os pedidos… (PDFs sem texto são lidos pela IA e podem levar alguns segundos). Os arquivos não ficam salvos no servidor.</p>';
      const d = await colPost('coletas_importar_previa_partes', { uploads: ids });
      colImpPrevia(res, d, fechar);
    } catch (e) { res.innerHTML = `<p class="coli-erro">${colEsc(e.message)}</p>`; }
  };
}

function colImpPrevia(res, d, fechar) {
  const ps = d.pedidos || [];
  const marcar = p => !p.coleta_existente && (!d.tem_chat || p.citado_como_coleta);
  res.innerHTML = `${ps.length ? `<div class="coli-topo"><b>${ps.length} pedido(s) lido(s)</b>${d.tem_chat ? ' · os citados como coleta no WhatsApp já vêm marcados' : ''}
      <label><input type="checkbox" data-todos> marcar todos</label></div>
    <div class="coli-lista">${ps.map((p, i) => `<label class="coli-item ${p.coleta_existente ? 'tem' : ''}"><input type="checkbox" data-i="${i}" ${marcar(p) ? 'checked' : ''}>
      <div><b>PC ${p.pc}</b> · ${colEsc(p.fornecedor || 'fornecedor não identificado')}
        <div class="coli-tags">${p.urgente_chat ? '<span class="warn">⚠ urgente no chat</span>' : ''}${p.citado_como_coleta ? '<span class="ok">citado como coleta</span>' : ''}${p.coleta_existente ? `<span class="warn">já tem coleta ${colEsc(p.coleta_existente)}</span>` : ''}${p.lido_por_ia ? '<span>lido por IA</span>' : ''}${p.pc_no_sistema ? '' : '<span>PC ainda não está em Pedidos</span>'}
        ${p.endereco ? `<span class="ok">endereço: ${colEsc([p.endereco.endereco, p.endereco.numero, p.endereco.cidade].filter(Boolean).join(', '))}</span>` : '<span>endereço será buscado pelo CNPJ</span>'}</div>
        <small class="muted">${p.itens.slice(0, 4).map(x => colEsc(x.quantidade + ' ' + x.unidade + ' ' + x.descricao)).join(' · ')}${p.itens.length > 4 ? ` · +${p.itens.length - 4} item(ns)` : ''}</small></div></label>`).join('')}</div>` : '<p class="muted">Nenhum pedido reconhecido nos arquivos.</p>'}
    ${(d.avisos || []).length ? `<details class="coli-avisos"><summary>${d.avisos.length} arquivo(s) não lido(s)</summary>${d.avisos.map(a => `<div><b>${colEsc(a.arquivo)}</b>: ${colEsc(a.erro)}</div>`).join('')}</details>` : ''}
    ${ps.length ? `<div class="rd-ass-acoes"><label class="muted" style="margin-right:auto"><input type="checkbox" data-atualizar> atualizar coletas que já existem</label><button type="button" class="button ghost" data-c>Cancelar</button><button type="button" class="button primary" data-ok>Criar coletas</button></div>` : ''}`;
  const todos = res.querySelector('[data-todos]');
  if (todos) todos.onchange = () => res.querySelectorAll('[data-i]').forEach(c => c.checked = todos.checked);
  const c = res.querySelector('[data-c]'); if (c) c.onclick = fechar;
  const ok = res.querySelector('[data-ok]');
  if (ok) ok.onclick = async () => {
    const sel = [...res.querySelectorAll('[data-i]:checked')].map(x => ps[+x.dataset.i]);
    if (!sel.length) return colToast('Marque ao menos um pedido.', true);
    ok.disabled = true; ok.textContent = 'Criando…';
    try {
      const r = await colPost('coletas_importar_confirmar', { pedidos: sel, atualizar_existentes: res.querySelector('[data-atualizar]').checked });
      const novas = r.criadas.filter(x => x.novo).length, sem = r.criadas.filter(x => x.endereco === 'sem endereço').length;
      colToast(`${novas} coleta(s) criada(s)${r.criadas.length - novas ? ', ' + (r.criadas.length - novas) + ' atualizada(s)' : ''}${r.ignoradas.length ? ', ' + r.ignoradas.length + ' já existia(m)' : ''}${sem ? ' · ' + sem + ' sem endereço (complete na coleta)' : ''}.`);
      fechar();
      if (typeof colCarregar === 'function') colCarregar();
    } catch (e) { colToast(e.message, true); ok.disabled = false; ok.textContent = 'Criar coletas'; }
  };
}


/* ---- App do motorista (v3.40.0): link pessoal, sem login ---- */
function colMotModal(titulo, html) {
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(560px,100%)"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h2>${titulo}</h2><button type="button" class="icon-btn" data-x>×</button></div><div data-corpo>${html}</div></div>`;
  document.body.appendChild(bg); bg.querySelector('[data-x]').onclick = () => bg.remove(); return bg;
}
async function colMotorista() {
  const bg = colMotModal('📱 App do motorista', '<p class="muted">Carregando…</p>');
  const dt = v => v ? colDataHora(v) : '—';
  const render = (i, link) => {
    const corpo = bg.querySelector('[data-corpo]');
    corpo.innerHTML = `<p class="muted">O motorista instala o app uma vez, pelo link abaixo, e depois ele abre direto na rota do dia — sem login. Ao tocar em "Coletei" o app grava horário e localização, e isso alimenta os Indicadores. Não mexe no estoque.</p>
      ${i.configurado ? `<div class="panel" style="margin:10px 0;padding:10px 12px"><b>${colEsc(i.nome)}</b> — ${i.ativo ? '<span style="color:#34d399">link ativo</span>' : i.revogado ? '<span style="color:#f87171">link revogado</span>' : '<span style="color:#f87171">link vencido</span>'}<br>
        <small class="muted">Gerado em ${dt(i.criado_em)} por ${colEsc(i.criado_por)} · último acesso: ${dt(i.ultimo_acesso)} · aparelhos: ${i.aparelhos} de ${i.max_aparelhos} · vence em ${dt(i.expira_em)} (renova a cada uso)</small></div>` : '<p><b>Ainda não há link do motorista.</b></p>'}
      ${link ? `<div class="panel" style="margin:10px 0;padding:10px 12px;border-color:#34d399"><b>Link do motorista</b> <small class="muted">(guarde: por segurança ele só aparece agora)</small>
        <input readonly id="colMotLink" style="width:100%;margin:8px 0" value="${colEsc(link)}" onclick="this.select()">
        <div class="col-route-btns"><button class="button primary" id="colMotCopiar">Copiar link</button><button class="button ghost" id="colMotWa">◉ Enviar por WhatsApp</button></div></div>` : ''}
      <label style="display:block;margin-top:10px">Nome do motorista<input id="colMotNome" maxlength="80" style="width:100%" value="${colEsc(i.nome || '')}" placeholder="Ex.: Leandro"></label>
      <div class="rd-ass-acoes" style="flex-wrap:wrap"><button class="button primary" id="colMotGerar">${i.configurado ? 'Gerar novo link (o anterior deixa de valer)' : 'Gerar link do motorista'}</button>
        ${i.configurado && i.aparelhos ? '<button class="button ghost" id="colMotLiberar">Liberar aparelhos (celular novo)</button>' : ''}${i.configurado && i.ativo ? '<button class="button ghost danger" id="colMotRevogar">Revogar link</button>' : ''}</div>
      <p class="muted" style="font-size:12px">Segurança: quem tem o link vê apenas a rota em andamento e pode registrar coleta. Se o celular for perdido ou o link vazar, revogue ou gere um novo.</p>`;
    const g = id => corpo.querySelector(id);
    if (link) {
      g('#colMotCopiar').onclick = async () => { try { await navigator.clipboard.writeText(link); colToast('Link copiado'); } catch { g('#colMotLink').select(); colToast('Selecione e copie o link', true); } };
      g('#colMotWa').onclick = () => window.open('https://wa.me/?text=' + encodeURIComponent('Rota Drilling — abra este link no seu celular e instale o app (Chrome: ⋮ > Instalar app; iPhone: Safari > Compartilhar > Adicionar à Tela de Início):\n' + link), '_blank');
    }
    g('#colMotGerar').onclick = async () => {
      const nome = g('#colMotNome').value.trim(); if (nome.length < 2) return colToast('Informe o nome do motorista', true);
      if (i.configurado && !confirm('Gerar um novo link? O link atual do motorista deixa de funcionar.')) return;
      try { const r = await colPost('coletas_motorista_gerar', { nome }); render(r, r.link); } catch (e) { colToast(e.message, true); }
    };
    const lib = g('#colMotLiberar'); if (lib) lib.onclick = async () => { try { render(await colPost('coletas_motorista_liberar', {}), null); colToast('Aparelhos liberados'); } catch (e) { colToast(e.message, true); } };
    const rev = g('#colMotRevogar'); if (rev) rev.onclick = async () => { if (!confirm('Revogar o link? O app do motorista para de funcionar até você gerar outro.')) return; try { render(await colPost('coletas_motorista_revogar', {}), null); colToast('Link revogado'); } catch (e) { colToast(e.message, true); } };
  };
  try { render(await colApi('coletas_motorista_info'), null); } catch (e) { bg.querySelector('[data-corpo]').innerHTML = `<p>${colEsc(e.message)}</p>`; }
}


/* v3.42.0 — Média de km por dia (saída do depósito, coletas do dia, volta ao depósito) */
async function colKmMedia(nao) {
  let r; try { r = await colApi('coletas_km_media', { query: nao ? { incluir_nao_coletadas: 1 } : {} }); } catch (e) { return colToast(e.message, true); }
  document.querySelectorAll('.col-kmmedia-bg').forEach(e => e.remove());
  const k = v => v == null ? '—' : colNum(v) + ' km', g = r.geral, f = r.faixa_6_8, x = r.excluidas;
  const bg = document.createElement('div'); bg.className = 'rd-ass-bg col-kmmedia-bg';
  bg.innerHTML = `<div class="rd-ass" style="width:min(820px,100%);max-height:90vh;overflow:auto"><div style="display:flex;justify-content:space-between;align-items:center"><h2>Média de km por dia</h2><button type="button" class="icon-btn" data-x>×</button></div>
    <p class="muted">Depósito → coletas do dia → depósito · só ${colEsc(r.estado)}${r.incluir_nao_coletadas ? ' · incluindo não coletadas' : ' · só coletadas'} · km = linha reta × ${r.fator_estrada} (estrada)</p>
    <div class="col-hist-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:10px 0">
      ${[['Média por dia', k(g.media_km)], ['Mediana', k(g.mediana_km)], ['Dias analisados', g.dias], ['Paradas/dia', g.media_paradas ?? '—'], ['Km por parada', k(g.km_por_parada)], ['Mín. / máx.', g.dias ? colNum(g.min_km) + ' / ' + colNum(g.max_km) : '—'],
         ['Dias com 6 a 8 coletas', f.dias], ['Média nesses dias', k(f.media_km)]].map(([l, v]) => `<div><strong>${v}</strong><span>${l}</span></div>`).join('')}</div>
    <p class="muted">Descartadas: ${x.fora_do_estado} fora do estado · ${x.nao_coletadas} não coletadas · ${x.sem_coordenada} sem coordenada · ${x.sem_data} sem data${r.sem_uf_incluidas ? ` · (${r.sem_uf_incluidas} sem UF foram mantidas)` : ''}</p>
    <div class="table-wrap"><table class="est-table"><thead><tr><th>Dia</th><th>Paradas</th><th>Km</th><th>Tempo est.</th></tr></thead><tbody>${r.dias.map(d => `<tr><td>${colEsc(d.data.split('-').reverse().join('/'))}</td><td>${d.paradas}</td><td>${colNum(d.km)} km</td><td>${colHm(d.min)}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">Sem coletas que atendam às regras.</td></tr>'}</tbody></table></div>
    <div class="rd-ass-acoes"><button type="button" class="button ghost" data-nao>${r.incluir_nao_coletadas ? 'Só coletadas' : 'Incluir não coletadas'}</button><button type="button" class="button primary" data-x2>Fechar</button></div></div>`;
  document.body.appendChild(bg);
  bg.querySelectorAll('[data-x],[data-x2]').forEach(b => b.onclick = () => bg.remove());
  bg.querySelector('[data-nao]').onclick = () => colKmMedia(!r.incluir_nao_coletadas);
}
