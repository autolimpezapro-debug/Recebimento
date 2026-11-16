/* Recebimento Drilling v2.9.5 — Administração, backup e restauração. */
'use strict';
let backupCsrf='',backupAuthorized=false,backupConfigured=false;
const backupEl=id=>document.getElementById(id);
const backupText=(message,bad=false)=>{const el=backupEl('backupMessage');if(el){el.textContent=message;el.className='backup-message'+(bad?' backup-error':'')}};
async function backupCall(action,options={}){
  const response=await fetch('admin_backup.php?action='+encodeURIComponent(action),{
    credentials:'same-origin',cache:'no-store',...options,
    headers:{...(options.body && !(options.body instanceof FormData)?{'Content-Type':'application/json'}:{}),
      ...(backupCsrf?{'X-Backup-CSRF':backupCsrf}:{}),...(options.headers||{})}
  });
  if(options.download){
    if(!response.ok){let msg='Falha no backup';try{const e=await response.json();msg=e.error||msg}catch{};throw new Error(msg)}
    const blob=await response.blob();
    const disposition=response.headers.get('Content-Disposition')||'';
    const name=/filename="?([^";]+)"?/i.exec(disposition)?.[1]||'recebimento-drilling-backup.zip';
    const url=URL.createObjectURL(blob);
    try{const a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove()}finally{setTimeout(()=>URL.revokeObjectURL(url),4000)}
    return {ok:true};
  }
  const payload=await response.json().catch(()=>({ok:false,error:'Resposta inválida do servidor'}));
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Não foi possível concluir a operação');
  return payload;
}
function backupMode(configured,authorized){
  backupConfigured=!!configured;backupAuthorized=!!authorized;
  backupEl('backupSetup').hidden=configured;
  backupEl('backupLogin').hidden=!configured||authorized;
  backupEl('backupControls').hidden=!authorized;
  backupEl('backupBadge').textContent=authorized?'Administração liberada':configured?'Acesso protegido':'Ativação inicial';
}
async function backupRefresh(){
  try{
    const r=await backupCall('status');
    backupCsrf=r.csrf||'';backupMode(r.configured,r.authorized);
    backupEl('backupZipWarning').hidden=!!r.zip_available;
    if(r.authorized)await backupLoadSnapshots();
  }catch(e){backupText('Falha ao consultar o módulo administrativo: '+e.message,true)}
}
async function backupSetup(event){
  event.preventDefault();
  const code=backupEl('backupActivation').value.trim();
  const password=backupEl('backupNewPassword').value;
  if(password.length<12){backupText('Use uma senha de pelo menos 12 caracteres.',true);return}
  try{await backupCall('setup',{method:'POST',body:JSON.stringify({code,password})});
    backupEl('backupActivation').value='';backupEl('backupNewPassword').value='';
    backupText('Senha criada. Entre com sua senha para acessar os backups.');await backupRefresh();
  }catch(e){backupText(e.message,true)}
}
async function backupLogin(event){
  event.preventDefault();
  try{const r=await backupCall('login',{method:'POST',body:JSON.stringify({password:backupEl('backupLoginPassword').value})});
    backupEl('backupLoginPassword').value='';backupCsrf=r.csrf;
    backupMode(true,true);backupText('Administração liberada por 15 minutos de inatividade.');await backupLoadSnapshots();
  }catch(e){backupText(e.message,true)}
}
async function backupLogout(){
  try{await backupCall('logout',{method:'POST',body:JSON.stringify({})})}catch{}
  backupCsrf='';backupMode(true,false);backupText('Área administrativa bloqueada.');
}
async function backupCreate(){
  const btn=backupEl('backupDownloadButton');btn.disabled=true;backupText('Gerando cópia completa dos registros e arquivos no servidor…');
  try{await backupCall('create',{method:'POST',body:JSON.stringify({}),download:true});backupText('Backup baixado. Guarde o arquivo ZIP em um lugar seguro.');}
  catch(e){backupText(e.message,true);if(/expirada|Token/i.test(e.message))await backupRefresh()}
  finally{btn.disabled=false}
}
async function backupRestore(event){
  event.preventDefault();
  const file=backupEl('backupRestoreFile').files[0];
  if(!file){backupText('Selecione um ZIP gerado pelo módulo Backup.',true);return}
  if(!backupEl('backupRestoreAccept').checked){backupText('Confirme que leu o aviso de substituição de dados.',true);return}
  const pwd=backupEl('backupRestorePassword').value;
  if(!pwd){backupText('Confirme sua senha administrativa.',true);return}
  if(backupEl('backupRestoreConfirm').value.trim()!=='RESTAURAR'){
    backupText('Digite RESTAURAR no campo de confirmação.',true);return;
  }
  if(!confirm('ATENÇÃO: isto substituirá os dados e arquivos do Recebimento Drilling pelo conteúdo do backup. Uma cópia do estado atual será feita antes. Deseja prosseguir?'))return;
  const btn=backupEl('backupRestoreButton');btn.disabled=true;backupText('Verificando o ZIP e preparando restauração… Não feche a página.');
  try{
    const body=new FormData();body.append('archive',file);body.append('password',pwd);body.append('confirm','RESTAURAR');
    const result=await backupCall('restore',{method:'POST',body});
    backupEl('backupRestorePassword').value='';backupEl('backupRestoreConfirm').value='';backupEl('backupRestoreFile').value='';
    backupEl('backupRestoreAccept').checked=false;
    backupText('Restauração concluída. Cópia anterior preservada: '+(result.details?.snapshot||'no servidor')+'. Atualize o aplicativo e confira os dados.');
    await backupLoadSnapshots();
  }catch(e){backupText('Restauração não concluída: '+e.message,true)}
  finally{btn.disabled=false}
}
async function backupLoadSnapshots(){
  const target=backupEl('backupSnapshots');if(!target||!backupAuthorized)return;
  try{
    const r=await backupCall('snapshots',{method:'POST',body:JSON.stringify({})});
    target.replaceChildren();
    if(!r.snapshots?.length){target.textContent='Ainda não há cópias automáticas pré-restauração neste servidor.';return}
    for(const snap of r.snapshots){
      const line=document.createElement('div');line.className='backup-snapshot';
      const title=document.createElement('span');title.textContent=snap.name+' · '+(Number(snap.bytes)/1048576).toFixed(2)+' MB';
      const btn=document.createElement('button');btn.className='button ghost small';btn.textContent='Baixar';
      btn.onclick=async()=>{btn.disabled=true;try{await backupCall('download_snapshot',{method:'POST',body:JSON.stringify({name:snap.name}),download:true})}catch(e){backupText(e.message,true)}finally{btn.disabled=false}};
      line.append(title,btn);target.appendChild(line);
    }
  }catch(e){target.textContent='Não foi possível listar cópias: '+e.message}
}
