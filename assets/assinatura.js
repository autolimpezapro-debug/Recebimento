/* Recebimento Drilling — assinatura de quem retira material (v3.9.1).
 * rdAssinar({titulo, resumo}) → Promise<{nome, documento, imagem, aceite} | null>
 * Funciona com dedo (celular/tablet), caneta e mouse. */
'use strict';
let RD_FUNC = null;
async function rdFuncionarios(forcar) {
  if (RD_FUNC && !forcar) return RD_FUNC;
  try {
    const r = await fetch('api.php?action=estoque_funcionarios', { cache: 'no-store', credentials: 'same-origin' }).then(x => x.json());
    RD_FUNC = r.ok ? r.funcionarios.filter(f => f.ativo) : [];
  } catch { RD_FUNC = []; }
  return RD_FUNC;
}
const rdNormNome = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/\s+/g, ' ').trim();
async function rdAssinar(opts = {}) {
  const lista = await rdFuncionarios();
  return new Promise(resolve => {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const bg = document.createElement('div');
    bg.className = 'rd-ass-bg';
    bg.innerHTML = `<form class="rd-ass" autocomplete="off">
      <h2>${esc(opts.titulo || 'Assinatura de quem retira o material')}</h2>
      ${opts.resumo ? `<div class="rd-ass-resumo">${opts.resumo}</div>` : ''}
      <div class="rd-ass-campos">
        <label>Quem está retirando${lista.length ? ' (funcionário cadastrado)' : ''}<input name="nome" required minlength="3" list="rdFuncList" placeholder="${lista.length ? 'Digite para buscar na lista' : 'Nome e sobrenome'}" autocomplete="off"></label>
        <label class="rd-ass-doc">Documento (CPF, RG ou empresa)<input name="documento" maxlength="40" placeholder="${lista.length ? 'Obrigatório para terceiros' : 'Opcional'}"></label>
      </div>
      <datalist id="rdFuncList">${lista.map(f => `<option value="${esc(f.nome)}">${esc(f.matricula ? 'Matrícula ' + f.matricula : f.setor || '')}</option>`).join('')}</datalist>
      ${lista.length ? '<label class="rd-ass-aceite"><input type="checkbox" name="terceiro"> Não está na lista (terceiro, motorista ou visitante)</label><div class="rd-ass-status" aria-live="polite"></div>' : ''}
      <div class="rd-ass-quadro"><canvas></canvas><span>Assine aqui com o dedo, caneta ou mouse</span><button type="button" class="rd-ass-limpar">Limpar</button></div>
      <label class="rd-ass-aceite"><input type="checkbox" name="aceite" required> Declaro que recebi os materiais acima, nas quantidades indicadas.</label>
      <div class="rd-ass-acoes"><button type="button" class="button ghost rd-ass-cancelar">Cancelar</button><button class="button primary">Confirmar entrega</button></div>
    </form>`;
    document.body.appendChild(bg);
    const form = bg.querySelector('form'), cv = bg.querySelector('canvas'), dica = bg.querySelector('.rd-ass-quadro span');
    const ctx = cv.getContext('2d');
    let tracos = 0, desenhando = false, ult = null;
    function ajustar() {
      const r = cv.getBoundingClientRect(), dpr = Math.max(1, window.devicePixelRatio || 1);
      cv.width = Math.round(r.width * dpr); cv.height = Math.round(r.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1b3a';
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, r.width, r.height); tracos = 0; dica.hidden = false;
    }
    const pos = e => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    cv.addEventListener('pointerdown', e => { e.preventDefault(); cv.setPointerCapture(e.pointerId); desenhando = true; ult = pos(e); dica.hidden = true; });
    cv.addEventListener('pointermove', e => {
      if (!desenhando) return; e.preventDefault();
      const p = pos(e); ctx.beginPath(); ctx.moveTo(ult[0], ult[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); ult = p; tracos++;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => cv.addEventListener(t, () => { desenhando = false; }));
    bg.querySelector('.rd-ass-limpar').onclick = ajustar;
    const fechar = v => { bg.remove(); window.removeEventListener('resize', ajustar); resolve(v); };
    bg.querySelector('.rd-ass-cancelar').onclick = () => fechar(null);
    const status = bg.querySelector('.rd-ass-status');
    const achar = () => { const n = rdNormNome(form.nome.value); return n ? lista.find(f => rdNormNome(f.nome) === n) : null; };
    const atualizar = () => {
      if (!status) return;
      const f = achar(), terc = form.terceiro.checked;
      form.documento.required = terc;
      bg.querySelector('.rd-ass-doc').classList.toggle('on', terc);
      status.className = 'rd-ass-status ' + (f ? 'ok' : terc ? 'warn' : form.nome.value.trim() ? 'bad' : '');
      status.textContent = f ? '✓ ' + f.nome + (f.matricula ? ' · matrícula ' + f.matricula : '') + (f.setor ? ' · ' + f.setor : '')
        : terc ? 'Terceiro: informe o documento.' : form.nome.value.trim() ? 'Nome não encontrado na lista de funcionários ativos.' : '';
    };
    if (lista.length) {
      form.nome.addEventListener('input', atualizar); form.terceiro.addEventListener('change', atualizar);
      const pre = rdNormNome(opts.nome);
      const m = pre && (lista.find(f => rdNormNome(f.nome) === pre) || (lista.filter(f => rdNormNome(f.nome).startsWith(pre)).length === 1 ? lista.find(f => rdNormNome(f.nome).startsWith(pre)) : null));
      form.nome.value = m ? m.nome : ''; atualizar();
    } else form.nome.value = opts.nome || '';
    form.onsubmit = ev => {
      ev.preventDefault();
      const func = lista.length ? achar() : null;
      if (lista.length && !func && !form.terceiro.checked) { alert('Escolha o funcionário na lista ou marque "Não está na lista".'); form.nome.focus(); return; }
      if (form.terceiro?.checked && form.documento.value.trim().length < 4) { alert('Para terceiros, informe o documento.'); form.documento.focus(); return; }
      if (tracos < 8) { alert('Assine no quadro antes de confirmar.'); return; }
      // recorta para o tamanho real e exporta PNG
      const out = document.createElement('canvas'); out.width = Math.min(cv.width, 1200); out.height = Math.round(cv.height * out.width / cv.width);
      out.getContext('2d').drawImage(cv, 0, 0, out.width, out.height);
      fechar({ nome: func ? func.nome : form.nome.value.trim(), funcionario_id: func ? func.id : '', documento: form.documento.value.trim(), aceite: form.aceite.checked, imagem: out.toDataURL('image/png') });
    };
    requestAnimationFrame(ajustar); window.addEventListener('resize', ajustar);
    setTimeout(() => { if (!form.nome.value) form.nome.focus(); }, 50);
  });
}
function rdAbrirComprovante(doc) { window.open('api.php?action=estoque_comprovante&doc=' + encodeURIComponent(doc), '_blank', 'noopener'); }
