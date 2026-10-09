'use strict';
(()=>{
 const dialog=document.getElementById('hubble-trade-dialog'),root=document.getElementById('hubble-buy-root'),heading=document.getElementById('hubble-trade-heading'),notice=document.getElementById('hubble-trade-notice');
 if(!dialog||!root||!heading||!notice||!window.HubbleBuy?.mount)return;
 let instance=null,currentTicker='',trigger=null,revealFrame=null;
 const tickerPattern=/^[A-Z0-9][A-Z0-9._-]{0,15}$/;
 const stateClasses=['trade-status-success','trade-status-error','trade-status-warning','trade-status-wait'];
 const stateSelector=['#hubble-trade-notice','[data-trade-id=quote-status]','.approval-panel > p:first-of-type','.quote-notice','.check-warning'].join(',');
 function stateTone(text){
  const value=String(text??'').trim();if(!value)return'';
  if(/^Purchase confirmed\b/i.test(value))return'success';
  if(/\b(?:failed|failure|could not|unavailable|reverted|unreadable|did not pass|declined)\b/i.test(value)||/^Enter (?:a positive USDT amount|a purchase amount from 6 to 10 USDT)\./i.test(value)||/\b(?:not supported for execution|not currently tradable|does not support this trading pair|outside the API trading limits|denied access|authentication or permission failed|no routes)\b/i.test(value))return'error';
  if(/^(?:Amount|Wallet|Network) changed\./i.test(value)||/^Network selected\. Request fresh quotes\./i.test(value)||/\b(?:Request fresh quotes|Get new quotes)\b/i.test(value))return'warning';
  if(/^(?:Preparing|Checking|Confirm\b|Please wait\b|Approval submitted\b|Purchase submitted\b|Approval is pending\b|Approval included\b|Transaction mined\b|Purchase is not yet confirmed\b|Purchase confirmation is taking longer\b|Purchase is still not confirmed\b|Automatic confirmation checks paused\b)/i.test(value)||/\b(?:awaiting verification|being checked automatically|waiting for one additional block|still pending|checks are continuing|refresh is running|request is running|request is active|transaction is unresolved|Preparing a fresh)\b/i.test(value))return'wait';
  return'';
 }
 function paintState(target){if(!target?.classList)return;target.classList.remove(...stateClasses);const tone=stateTone(target.textContent);if(tone)target.classList.add('trade-status-'+tone);}
 function paintStates(scope=dialog){if(scope.matches?.(stateSelector))paintState(scope);for(const target of scope.querySelectorAll?.(stateSelector)??[])paintState(target);}
 const observer=new MutationObserver(records=>{for(const record of records){const target=record.target.nodeType===Node.TEXT_NODE?record.target.parentElement:record.target;if(target?.matches?.(stateSelector))paintState(target);for(const added of record.addedNodes)if(added.nodeType===Node.ELEMENT_NODE)paintStates(added);}});
 observer.observe(dialog,{childList:true,subtree:true,characterData:true});paintStates();
 function reveal(target){
  if(!dialog.open||!target||!root.contains(target))return;
  if(revealFrame!==null)cancelAnimationFrame(revealFrame);
  revealFrame=requestAnimationFrame(()=>{
   revealFrame=null;if(!dialog.open||!root.contains(target)||target.hidden)return;
   const frame=dialog.getBoundingClientRect(),box=target.getBoundingClientRect(),header=dialog.querySelector('.trade-dialog-header')?.getBoundingClientRect();
   const headerHeight=header?.height??0,top=Math.max(0,dialog.scrollTop+box.top-frame.top-headerHeight-16);
   dialog.scrollTo({top,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
  });
 }
 function close(){
  if(instance?.isBusy?.()){notice.textContent='Please wait for the current request or wallet confirmation to finish.';return;}
  if(revealFrame!==null){cancelAnimationFrame(revealFrame);revealFrame=null;}
  instance?.reset?.(currentTicker);notice.textContent='';if(dialog.open)dialog.close();trigger?.focus?.({preventScroll:true});
 }
 async function open(mode,ticker){
  if(mode!=='buy'){
   location.href='rwa-agent.php?trade='+encodeURIComponent(mode==='sale'?'sale':'buy');
   return;
  }
  const next=typeof ticker==='string'&&tickerPattern.test(ticker)?ticker:'';
  if(instance?.isBusy?.()){notice.textContent='Finish the current request before changing assets.';return;}
  trigger=document.activeElement;notice.textContent='';currentTicker=next;heading.textContent=next?'Buy '+next:'Buy RWA tokens';
  if(!instance){root.dataset.ticker=next;instance=window.HubbleBuy.mount(root);}else if(!instance.setAsset(next)){notice.textContent='The current purchase request must finish first.';return;}
  if(!dialog.open)dialog.showModal();dialog.scrollTop=0;await instance.activate?.();
 }
 document.getElementById('hubble-trade-close')?.addEventListener('click',close);
 document.getElementById('hubble-trade-restart')?.addEventListener('click',()=>{if(!instance?.isBusy?.())instance?.reset?.(currentTicker);});
 dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 dialog.addEventListener('click',e=>{if(e.target===dialog)close();});
 window.HubbleTrading={open,reveal};
})();
