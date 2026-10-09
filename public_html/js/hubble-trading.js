'use strict';
(()=>{
 const dialog=document.getElementById('hubble-trade-dialog'),heading=document.getElementById('hubble-trade-heading'),notice=document.getElementById('hubble-trade-notice');
 const roots={buy:document.getElementById('hubble-buy-root'),sale:document.getElementById('hubble-sale-root'),approval:document.getElementById('hubble-approval-root')},instances={};let active=null,trigger=null,currentTicker=null;
 let revealFrame=null;
 const stateClasses=['trade-status-success','trade-status-error','trade-status-warning','trade-status-wait'];
 const stateSelector=['#hubble-trade-notice','[data-trade-id=quote-status]','[data-trade-id=status]','[data-trade-id=token-balance]','.approval-panel > p:first-of-type','.quote-notice','.check-warning'].join(',');
 function stateTone(text){
  const value=String(text??'').trim();if(!value)return'';
  if(/^(?:Sale|Purchase) confirmed\b/i.test(value)||/^\d+ supported RWA tokens? found in this wallet\.$/i.test(value))return'success';
  if(/^No supported RWA token balance was found in this wallet\.$/i.test(value))return'error';
  if(/\b(?:failed|failure|could not|unavailable|reverted|unreadable|did not pass|declined)\b/i.test(value)||/^(?:Wallet checks stopped|Approval preparation stopped)\b/i.test(value)||/Entered amount exceeds the selected token balance\./i.test(value)||/^Enter (?:a positive USDT amount|a purchase amount from 6 to 10 USDT)\./i.test(value)||/\b(?:not supported for execution|not currently tradable|does not support this trading pair|outside the API trading limits|denied access|authentication or permission failed|no routes)\b/i.test(value))return'error';
  if(/^(?:Amount|Wallet|Network) changed\./i.test(value)||/^Network selected\. Request fresh quotes\./i.test(value)||/\b(?:Request fresh quotes|Get new quotes)\b/i.test(value))return'warning';
  if(/^(?:Preparing|Checking|Confirm\b|Please wait\b|Approval submitted\b|Sale submitted\b|Purchase submitted\b|Approval is pending\b|Approval included\b|Transaction mined\b|Purchase is not yet confirmed\b|Purchase confirmation is taking longer\b|Purchase is still not confirmed\b|Automatic confirmation checks paused\b)/i.test(value)||/\b(?:awaiting verification|being checked automatically|waiting for one additional block|still pending|checks are continuing|refresh is running|request is running|request is active|transaction is unresolved|Preparing a fresh)\b/i.test(value))return'wait';
  return'';
 }
 function paintState(target){if(!target?.classList)return;target.classList.remove(...stateClasses);const tone=stateTone(target.textContent);if(tone)target.classList.add('trade-status-'+tone);}
 function paintStates(scope=dialog){
  if(scope.matches?.(stateSelector))paintState(scope);for(const target of scope.querySelectorAll?.(stateSelector)??[])paintState(target);
 }
 const stateObserver=new MutationObserver(records=>{for(const record of records){const target=record.target.nodeType===Node.TEXT_NODE?record.target.parentElement:record.target;if(target?.matches?.(stateSelector))paintState(target);for(const added of record.addedNodes)if(added.nodeType===Node.ELEMENT_NODE)paintStates(added);}});
 stateObserver.observe(dialog,{childList:true,subtree:true,characterData:true});paintStates();
 function reveal(target){
  if(!dialog.open||!target||!roots[active]?.contains(target))return;
  if(revealFrame!==null)cancelAnimationFrame(revealFrame);
  revealFrame=requestAnimationFrame(()=>{
   revealFrame=null;if(!dialog.open||!roots[active]?.contains(target)||target.hidden)return;
   const frame=dialog.getBoundingClientRect(),box=target.getBoundingClientRect(),header=dialog.querySelector('.trade-dialog-header').getBoundingClientRect();
   // Scroll this dialog only; never scroll the conversation or focus a wallet button.
   const top=Math.max(0,dialog.scrollTop+box.top-frame.top-header.height-16);
   dialog.scrollTo({top,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
  });
 }
 function close(){if(active&&instances[active]?.isBusy()){notice.textContent='Please wait for the current request or wallet confirmation to finish.';return;}if(revealFrame!==null){cancelAnimationFrame(revealFrame);revealFrame=null;}for(const instance of Object.values(instances))instance.reset?.();notice.textContent='';dialog.close();active=null;trigger?.focus({preventScroll:true});}
 document.getElementById('hubble-trade-close').addEventListener('click',close);
 dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 function diagnostics(root){const observer=new MutationObserver(()=>{const rows=[...root.querySelectorAll('.quote-summary')].filter(n=>!n.closest('.trade-diagnostics'));if(!rows.length)return;const container=rows[0].parentElement;let details=container.querySelector(':scope > .trade-diagnostics');if(!details){details=document.createElement('details');details.className='trade-diagnostics';const summary=document.createElement('summary');summary.textContent='Request details: UTC, duration and API weight';details.append(summary);container.append(details);}for(const row of rows)details.append(row);});observer.observe(root,{childList:true,subtree:true});}
 async function open(mode,ticker,options={}){if(!['buy','sale','approval'].includes(mode)||!roots[mode]||mode==='buy'&&ticker!==undefined&&ticker!==null&&!/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker))return;
  if(active&&instances[active]?.isBusy()){notice.textContent='Finish the current request before changing assets.';return;}
  const fresh=mode==='buy'||(!dialog.open&&!options.resume)||options.restart;if(fresh){for(const instance of Object.values(instances))instance.reset?.();}trigger=document.activeElement;notice.textContent='';heading.textContent=mode==='buy'?(ticker?'Buy '+ticker:'Buy RWA tokens'):mode==='sale'?'Sell RWA tokens':'Token spending permission';
  for(const [key,root]of Object.entries(roots))root.hidden=key!==mode;
  active=mode;const root=roots[mode];if(!instances[mode]){root.dataset.ticker=ticker??'';instances[mode]=(mode==='buy'?window.HubbleBuy:mode==='sale'?window.HubbleSale:window.HubbleSaleApproval).mount(root);diagnostics(root);}else if(mode==='buy'&&!instances.buy.setAsset(ticker??'')){notice.textContent='The current purchase request must finish first.';return;}
  if(mode==='buy')currentTicker=ticker??null;if(!dialog.open)dialog.showModal();dialog.scrollTop=0;await instances[mode].activate?.();
  if(mode==='approval'){if(options.recovery||instances.approval.hasPending?.()){notice.textContent='Check the saved approval status below. This does not send a new transaction.';}else if(instances.sale?.selection){try{const selected=instances.sale.selection();if(!instances.approval.setSelection(selected)){notice.textContent='A saved approval must be checked before preparing another one.';}else heading.textContent=selected.symbol+' spending permission';}catch{notice.textContent='Return to sale and select a token and a positive amount first.';}}}
 }
 document.getElementById('hubble-open-approval').addEventListener('click',()=>open('approval'));document.getElementById('hubble-back-sale').addEventListener('click',()=>{instances.approval?.reset?.();instances.sale?.clearStep?.();open('sale',undefined,{resume:true});});
 document.getElementById('hubble-trade-restart')?.addEventListener('click',()=>open(active==='approval'?'sale':active,currentTicker,{restart:true}));
 window.HubbleTrading={open,reveal};
})();
