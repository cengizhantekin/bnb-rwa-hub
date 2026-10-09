'use strict';
(()=>{
 const swapButton=document.getElementById('nav-swap'),dialog=document.getElementById('swap-chooser');
 if(!swapButton||!dialog)return;
 const close=()=>{if(dialog.open)dialog.close();};
 swapButton.addEventListener('click',()=>{if(!dialog.open)dialog.showModal();});
 dialog.querySelector('[data-swap-close]')?.addEventListener('click',close);
 dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 dialog.addEventListener('click',e=>{if(e.target===dialog)close();});
 for(const button of dialog.querySelectorAll('[data-swap-mode]'))button.addEventListener('click',()=>{
  const mode=button.dataset.swapMode;if(!['buy','sale'].includes(mode))return;
  close();
  if(window.HubbleTrading?.open)window.HubbleTrading.open(mode);
  else location.href='rwa-agent.php?trade='+encodeURIComponent(mode);
 });
 const requested=new URLSearchParams(location.search).get('trade');
 if(['buy','sale'].includes(requested)&&window.HubbleTrading?.open){history.replaceState(null,'',location.pathname+location.hash);window.HubbleTrading.open(requested);}
})();
