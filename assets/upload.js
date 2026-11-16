/* Recebimento Drilling — envio de arquivos grandes em partes (v3.14.0). Até 1 GB por arquivo. */
'use strict';
const RD_UPLOAD_LIMITE = 1024 * 1024 * 1024;
let rdUploadParteMax = 0;
async function rdUploadGrande(file, { acao = 'upload_parte', headers = {}, progresso } = {}) {
  if (file.size > RD_UPLOAD_LIMITE) throw new Error('O limite de importação é 1 GB por arquivo.');
  if (!rdUploadParteMax) rdUploadParteMax = 1536 * 1024;   // 1ª parte pequena; o servidor informa o tamanho ideal
  const h = { ...headers }; if (window.rdAuthCsrf) h['X-RD-CSRF'] = window.rdAuthCsrf;
  let id = '', offset = 0, falhas = 0;
  while (true) {
    const fim = Math.min(file.size, offset + rdUploadParteMax);
    const fd = new FormData();
    fd.append('id', id); fd.append('offset', offset); fd.append('total', file.size); fd.append('nome', file.name);
    fd.append('parte', file.slice(offset, fim), 'parte.bin');
    let d;
    try {
      const r = await fetch('api.php?action=' + acao, { method: 'POST', body: fd, credentials: 'same-origin', headers: h });
      d = await r.json();
      if (!r.ok || !d.ok) { const e = new Error(d.error || 'Erro ' + r.status); e.fatal = r.status < 500; throw e; }
    } catch (e) {
      if (e.fatal || ++falhas > 5) throw e;              // falha de rede: tenta de novo a mesma parte
      await new Promise(ok => setTimeout(ok, 1500 * falhas)); continue;
    }
    falhas = 0; id = d.id; offset = d.recebido; if (d.parte_max) rdUploadParteMax = d.parte_max;
    if (progresso) progresso(Math.min(100, Math.round(offset / file.size * 100)), offset, file.size);
    if (d.completo) return id;
  }
}
const rdTamanho = b => b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB';
