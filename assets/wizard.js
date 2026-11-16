/* Tutorial automático apenas no primeiro acesso deste navegador/PWA; acesso manual sempre permitido. */
'use strict';
const tutorialSteps=[
 {icon:'👋',title:'Bem-vindo ao Recebimento Drilling',description:'O sistema organiza pedidos de compra, conferência, notas fiscais e evidências.',html:'<p>Este guia aparece automaticamente apenas na primeira visita e permanece disponível pelo botão <strong>Tutorial</strong> no menu lateral.</p><p>O dashboard mostra pendências, chegadas e o andamento das operações.</p>'},
 {icon:'📥',title:'Importe PCs pelo WhatsApp',description:'Alimente a lista automaticamente com o ZIP exportado do grupo.',html:'<p>Acesse <strong>Importar WhatsApp</strong>, selecione o ZIP exportado com mídia e clique em <strong>Analisar pedidos</strong>. Confira a prévia com insumos, unidades e quantidades. Os pedidos já conferidos ou com itens cadastrados são preservados; confirme somente os elegíveis.</p><p>Importar pedidos não confirma recebimento nem dispara notificações Push.</p>'},
 {icon:'🔎',title:'Localize o pedido de compra',description:'Abra a seção Pedidos e encontre o PC correto.',html:'<p>Use a busca por <strong>PC, fornecedor, material ou NF</strong>. Os filtros permitem ver recebidos, pendentes, parciais e pedidos com fotos.</p><p>Clique na linha do pedido para abrir a conferência no painel lateral.</p>'},
 {icon:'▶️',title:'Inicie o recebimento',description:'O horário de início fica no histórico, mas NÃO confirma o recebimento.',html:'<p>Dentro do pedido, clique em <strong>Iniciar recebimento</strong>. A hora de início é registrada para auditoria. A data de confirmação e os avisos só são gerados ao finalizar.</p><p>O pedido passa a mostrar <strong>Em conferência</strong>.</p>'},
 {icon:'🧾',title:'Registre a nota fiscal e confira os materiais',description:'Use a câmera, a chave da NF-e ou o arquivo XML.',html:'<p>Importe o <strong>XML</strong> para obter os dados fiscais detalhados quando disponíveis. O <strong>OCR da foto</strong> sugere informações que devem ser conferidas antes de salvar. A chave sozinha não recupera todos os itens.</p><p>Marque a <strong>origem da entrega</strong> (Coleta interna Drilling ou Entrega do fornecedor). Confira cada insumo: <strong>quantidade pedida, quantidade nesta entrega e saldo não entregue</strong>. O status será automático; não preencha uma quantidade geral duplicada. Se um insumo não foi entregue, registre 0. Após uma entrega parcial, use “Receber saldo pendente” para lançar a próxima.</p>'},
 {icon:'📷',title:'Anexe fotos e documentos',description:'Fotografe durante a conferência ou depois de finalizar.',html:'<p>Você pode guardar até <strong>três fotos de produto</strong> por PC e anexar imagem ou PDF da nota fiscal.</p><p>Adicionar ou trocar fotos depois não modifica os horários originais do recebimento.</p>'},
 {icon:'✅',title:'Finalize e compartilhe a atualização',description:'Somente esta ação confirma a data/hora e notifica os demais.',html:'<p>Depois de conferir as quantidades por insumo e a origem da entrega, clique em <strong>Finalizar conferência</strong>. Havendo saldo, a situação será <strong>Parcialmente entregue</strong> e o sistema permitirá registrar outra entrega posteriormente sem apagar o histórico. Se precisar <strong>corrigir uma entrega parcial já finalizada</strong>, use <strong>Retificar última entrega parcial</strong>, informe o responsável e motivo. A correção fica no histórico e não muda a confirmação original.</p><p>Somente agora a data de confirmação é preenchida e o sino registra o aviso. Os dispositivos inscritos receberão Web Push. Para relatórios, use Exportação com as colunas <strong>DATA, PC, Nº NF, FORNECEDOR, SOLICITANTE e OBSERVAÇÕES</strong>.</p>'},
 {icon:'🔔',title:'Ative notificações neste dispositivo',description:'Receba avisos quando outros pedidos forem finalizados, mesmo fora da página.',html:'<p>Ao ativar, o navegador pedirá sua autorização. Isso é <strong>opcional e individual para cada navegador e dispositivo</strong>. Sem autorização, você continua vendo os avisos no sino quando estiver no sistema.</p><p>Notificações com a página fechada dependem de internet, permissões do sistema operacional e funcionamento do serviço Push. <strong>O servidor também precisa permitir saída HTTPS.</strong></p><p id="tutorialPushStatus" class="tutorial-push-status"></p><button type="button" class="button primary tutorial-push-button" onclick="enablePushAlerts()">🔔 Ativar notificações neste dispositivo</button>'}
];
let tutorialCurrent=0,tutorialPreviousFocus=null;
const tutorialSeenKey='recebimento-drilling-tutorial-ja-visto';
function tutorialWasSeen(){
 try{return localStorage.getItem(tutorialSeenKey)==='1'}catch{return document.cookie.split('; ').includes('rd_tutorial_visto=1')}
}
function markTutorialSeen(){
 try{localStorage.setItem(tutorialSeenKey,'1')}catch{
  if(window.isSecureContext)document.cookie='rd_tutorial_visto=1; Max-Age=31536000; Path=/; SameSite=Lax; Secure';
  else document.cookie='rd_tutorial_visto=1; Max-Age=31536000; Path=/; SameSite=Lax';
 }
}
function renderTutorial(){
 const t=tutorialSteps[tutorialCurrent];
 document.getElementById('tutorialCounter').textContent=`ETAPA ${tutorialCurrent+1} DE ${tutorialSteps.length}`;
 document.getElementById('tutorialIcon').textContent=t.icon;
 document.getElementById('tutorialTitle').textContent=t.title;
 document.getElementById('tutorialDescription').textContent=t.description;
 document.getElementById('tutorialContent').innerHTML=t.html;
 document.getElementById('tutorialBack').disabled=tutorialCurrent===0;
 document.getElementById('tutorialNext').textContent=tutorialCurrent===tutorialSteps.length-1?'Concluir ✓':'Próximo →';
 document.getElementById('tutorialProgress').innerHTML=tutorialSteps.map((_,i)=>`<span class="${i<=tutorialCurrent?'done':''}" aria-hidden="true"></span>`).join('');
 if(typeof renderPushButton==='function')renderPushButton();
}
function openTutorial(){
 markTutorialSeen();
 tutorialCurrent=0;tutorialPreviousFocus=document.activeElement;
 const modal=document.getElementById('tutorialBackdrop');modal.hidden=false;
 renderTutorial();document.body.classList.add('tutorial-open');
 document.getElementById('tutorialClose').focus();
}
function closeTutorial(){
 const modal=document.getElementById('tutorialBackdrop');modal.hidden=true;
 document.body.classList.remove('tutorial-open');
 if(tutorialPreviousFocus&&document.contains(tutorialPreviousFocus))tutorialPreviousFocus.focus();
}
function tutorialNext(){if(tutorialCurrent===tutorialSteps.length-1)closeTutorial();else{tutorialCurrent++;renderTutorial()}}
function tutorialPrevious(){if(tutorialCurrent>0){tutorialCurrent--;renderTutorial()}}
document.addEventListener('keydown',e=>{
 const visible=document.getElementById('tutorialBackdrop')?.hidden===false;
 if(!visible)return;
 if(e.key==='Escape'){e.preventDefault();closeTutorial()}
 if(e.key==='Tab'){
  const modal=document.getElementById('tutorialBackdrop');
  const links=[...modal.querySelectorAll('button:not([disabled]):not([hidden])')];
  if(!links.length)return;
  if(e.shiftKey&&document.activeElement===links[0]){e.preventDefault();links[links.length-1].focus()}
  else if(!e.shiftKey&&document.activeElement===links[links.length-1]){e.preventDefault();links[0].focus()}
 }
});
window.addEventListener('DOMContentLoaded',()=>{
 // O link do Push tem prioridade: não cobrir o PC com tutorial.
 const deepLink=new URLSearchParams(location.search).get('pc');
 if(deepLink && /^\d{1,12}$/.test(deepLink)){markTutorialSeen();return}
 // O tutorial nunca pode ficar por cima da tela de login: espera a sessão resolver.
 const abrir=()=>{if(!tutorialWasSeen())openTutorial();};
 if(window.rdAuthReady&&typeof window.rdAuthReady.then==='function'){
  window.rdAuthReady.then(()=>{
   const tem=typeof RDA!=='undefined';
   const logado=tem?!!RDA.logado:true;
   const exige=tem?!!RDA.exigir:false;
   if(logado||!exige)abrir();
  }).catch(()=>abrir());
  return;
 }
 abrir();
});
