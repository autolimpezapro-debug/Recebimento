/* Controle de acesso operacional v2.9.11. Sessão e CSRF mantidos no PHP. */
'use strict';
/** @param {string} id @returns {HTMLInputElement} */
function rdById(id){return /** @type {HTMLInputElement} */(document.getElementById(id));}

window.rdAuthCsrf='';window.rdAuthUser=null;
let rdAuthResolve;
window.rdAuthReady=new Promise(resolve=>{rdAuthResolve=resolve});
async function rdAuthReq(action,body){
 /** @type {RequestInit} */ const opts={credentials:'same-origin',cache:'no-store'};
 if(body!==undefined){opts.method='POST';opts.headers={'Content-Type':'application/json','X-RD-CSRF':window.rdAuthCsrf||''};opts.body=JSON.stringify(body)}
 const response=await fetch('api.php?action='+encodeURIComponent(action),opts);
 const data=await response.json().catch(()=>({ok:false,error:'API não respondeu corretamente'}));
 if(!response.ok||!data.ok)throw new Error(data.error||'Erro de autenticação');return data;
}
function rdAuthInfo(status){window.rdAuthCsrf=status.csrf||'';window.rdAuthUser=status.user||null;rdById('rdAuthName').textContent=window.rdAuthUser?window.rdAuthUser.username+' · '+window.rdAuthUser.role:'';
 /** @type {HTMLElement} */(document.querySelector('[data-page="importacao"]')).hidden=status.user?.role==='consulta'||status.user?.role==='conferente';
 /** @type {HTMLElement} */(document.querySelector('[data-page="administracao"]')).hidden=status.user?.role!=='gestor';
 rdById('rdUserManager').hidden=status.user?.role!=='gestor';
}
function rdAuthError(message){rdById('rdAuthError').textContent=message}
async function rdAuthLogin(event){event.preventDefault();rdAuthError('');const b=rdById('rdAuthSubmit');b.disabled=true;
 try{const result=await rdAuthReq('auth_login',{username:rdById('rdAuthUsername').value.trim(),password:rdById('rdAuthPassword').value});rdById('rdAuthPassword').value='';rdAuthInfo(result);rdById('rdAuthPanel').hidden=true;rdAuthResolve();}
 catch(e){rdAuthError(e.message)}finally{b.disabled=false}}
async function rdAuthLogout(){try{await rdAuthReq('auth_logout',{})}catch{}window.location.reload()}
function rdAuthConfigure(){rdById('rdAuthPanel').hidden=true;rdAuthResolve();goPage('administracao');backupRefresh()}
async function rdAuthUserList(){const host=rdById('rdUserList');if(!host||window.rdAuthUser?.role!=='gestor')return;
 try{const r=await rdAuthReq('auth_users');host.replaceChildren();for(const u of r.users){const line=document.createElement('div');line.className='rd-user-line';const summary=document.createElement('span');summary.textContent=u.username+' — '+u.role+(u.enabled?'':' (desativado)');line.appendChild(summary);if(u.enabled){const b=document.createElement('button');b.type='button';b.className='button ghost small';b.textContent='Desativar';b.onclick=async()=>{if(!confirm('Desativar o usuário '+u.username+'?'))return;try{await rdAuthReq('auth_user_disable',{username:u.username});await rdAuthUserList()}catch(e){alert(e.message)}};line.appendChild(b)}host.appendChild(line)}}catch(e){host.textContent=e.message}}
async function rdAuthCreateUser(event){event.preventDefault();const form=event.target;const message=rdById('rdUserMessage');message.textContent='';
 try{await rdAuthReq('auth_user_save',{username:form.elements.username.value.trim(),role:form.elements.role.value,password:form.elements.password.value});form.reset();message.textContent='Usuário criado; informe a senha diretamente à pessoa autorizada.';await rdAuthUserList()}
 catch(e){message.textContent=e.message}}
window.addEventListener('DOMContentLoaded',async()=>{
 try{const status=await rdAuthReq('auth_status');if(status.authenticated){rdAuthInfo(status);rdAuthResolve();rdAuthUserList();return}rdById('rdAuthPanel').hidden=false;if(status.admin_setup_required){rdById('rdAuthHelp').textContent='Primeiro acesso: ative a senha em Administração → Backup e Restauração antes de entrar.'}}
 catch(e){rdById('rdAuthPanel').hidden=false;rdAuthError('Não foi possível consultar o servidor: '+e.message)}
});
