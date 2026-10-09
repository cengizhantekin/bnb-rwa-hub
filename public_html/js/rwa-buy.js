'use strict';
const el=id=>document.getElementById(id), wallets=[], ticker=new URLSearchParams(location.search).get('ticker')??'';
let provider=null,address='',chain='',csrf='',busy=false,version=0,connecting=false;
const title=el('title'),notice=el('quote-status'),results=el('quote-results'),select=el('wallet-select');
const validTicker=/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker),validAddress=a=>typeof a==='string'&&/^0x[0-9a-fA-F]{40}$/.test(a)&&!/^0x0{40}$/i.test(a);
function n(tag,text,cls){const x=document.createElement(tag);if(text!==undefined)x.textContent=String(text);if(cls)x.className=cls;return x;}
function invalidate(){version++;results.replaceChildren();el('trade-review').replaceChildren();el('trade-review').hidden=true;}
function controls(){el('quote').disabled=busy||!csrf||!validTicker||!validAddress(address)||chain.toLowerCase()!=='0x38';el('connect').disabled=busy||connecting||!wallets.length;select.disabled=busy||connecting;el('switch').hidden=!address||chain.toLowerCase()==='0x38';el('switch').disabled=busy||connecting;el('amount').disabled=busy;document.querySelectorAll('.approval-action').forEach(b=>b.disabled=busy||connecting||!address||chain.toLowerCase()!=='0x38');document.querySelectorAll('.review-route').forEach(b=>b.disabled=b.dataset.valid!=='yes'||busy||connecting||!csrf||!address||chain.toLowerCase()!=='0x38');el('wallet-state').textContent=address?`${address} · ${chain.toLowerCase()==='0x38'?'BNB Smart Chain':'Different network selected'}`:'No wallet connected.';}
function announce(info){if(!info?.provider||typeof info.provider.request!=='function'||wallets.some(w=>w.provider===info.provider))return;wallets.push({provider:info.provider,name:String(info.info?.name??'Browser wallet').slice(0,60)});select.append(n('option',wallets.at(-1).name));controls();}
window.addEventListener('eip6963:announceProvider',e=>announce(e.detail));
window.dispatchEvent(new Event('eip6963:requestProvider'));
function accountsChanged(accounts){invalidate();address=validAddress(accounts?.[0])?accounts[0]:'';notice.textContent='Wallet changed. Request fresh quotes.';controls();}
function chainChanged(value){invalidate();chain=String(value);notice.textContent='Network changed. Request fresh quotes on BNB Smart Chain.';controls();}
function disconnected(){invalidate();address='';chain='';notice.textContent='Wallet disconnected.';controls();}
function selectProvider(next){if(provider?.removeListener){provider.removeListener('accountsChanged',accountsChanged);provider.removeListener('chainChanged',chainChanged);provider.removeListener('disconnect',disconnected);}provider=next;if(provider?.on){provider.on('accountsChanged',accountsChanged);provider.on('chainChanged',chainChanged);provider.on('disconnect',disconnected);}address='';chain='';invalidate();controls();}
select.addEventListener('change',()=>selectProvider(wallets[select.selectedIndex]?.provider??null));
el('amount').addEventListener('input',()=>{invalidate();notice.textContent='Amount changed. Request fresh quotes.';});
el('connect').addEventListener('click',async()=>{
  if(busy||connecting)return;selectProvider(wallets[select.selectedIndex]?.provider??null);if(!provider)return;
  connecting=true;controls();notice.textContent='Confirm wallet connection in your wallet.';
  try{const accounts=await provider.request({method:'eth_requestAccounts'});address=validAddress(accounts?.[0])?accounts[0]:'';chain=String(await provider.request({method:'eth_chainId'}));notice.textContent=address?'Wallet connected. Enter an amount and request quotes.':'No account was returned.';}
  catch(e){address='';notice.textContent=e.code===4001?'Wallet connection was declined.':'Could not connect the wallet.';}
  finally{connecting=false;controls();renderPending();renderPendingSwap();}
});
el('switch').addEventListener('click',async()=>{connecting=true;controls();try{await provider.request({method:'wallet_switchEthereumChain',params:[{chainId:'0x38'}]});chain=String(await provider.request({method:'eth_chainId'}));invalidate();notice.textContent='Network selected. Request fresh quotes.';}catch(e){notice.textContent=e.code===4001?'Network switch was declined.':'Select BNB Smart Chain in your wallet, then reconnect.';}finally{connecting=false;controls();}});
const statusText={not_in_catalogue:'This provider has no active token for this asset in the collected catalogue.',quote_liquidity_unavailable:'The API could not provide liquidity for this pair and amount.',market_not_tradable:'The API reports this asset is not currently tradable.',pair_not_supported:'The API does not support this trading pair.',amount_outside_limits:'This amount is outside the API trading limits.',access_or_compliance_denied:'The API denied access to this quote.',authentication_or_permission:'API authentication or permission failed.',rate_limited:'The API rate limit was reached.',skipped_after_upstream_stop:'Not requested because an earlier API response stopped this batch.',no_routes:'The API returned no routes.',transport_error:'The API connection failed.',invalid_response:'The API response could not be read.'};
function formatPrice(value){
  const s=String(value);if(!/^\d+(\.\d+)?$/.test(s))return s;
  const [rawInteger,fraction='']=s.split('.'),integer=rawInteger.replace(/^0+(?=\d)/,'');
  if(integer==='0'&&!/[1-9]/.test(fraction))return '0';
  const places=integer!=='0'?(integer.length>=2?2:3):fraction.search(/[1-9]/)+4;
  let digits=BigInt(integer+fraction.padEnd(places,'0').slice(0,places));
  if((fraction[places]??'0')>='5')digits++;
  const rounded=digits.toString().padStart(places+1,'0'),whole=rounded.slice(0,-places),part=rounded.slice(-places).replace(/0+$/,'');
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(part?'.'+part:'');
}

function display(report){results.replaceChildren();for(const p of report.providers??[]){const card=n('article',undefined,'quote-provider');card.append(n('h2',`${({bstock:'bStocks',ondo:'Ondo',xstocks:'xStocks'})[p.provider]??p.provider} · ${p.symbol??ticker}`));if(p.status!=='quotes_received')card.append(n('p',statusText[p.status]??'A quote is unavailable for this provider.','quote-notice'));if(p.message && p.status!=='quotes_received')card.append(n('p',`API: ${p.message}${p.business_code?' (code '+p.business_code+')':''}`));for(const r of p.routes??[]){const box=n('div',undefined,'quote-route');box.append(n('h3',`${r.vendor??'Unknown route'} · ${r.execution_mode??'Unknown mode'}`));const dl=n('dl');for(const [key,value] of [['Estimated tokens received',r.output_amount??'Not supplied'],['Network fee estimate, USD',r.trade_fee_estimate_usd??'Not supplied'],['Reported price impact, %',r.price_impact_percent??'Not supplied']]){dl.append(n('dt',key),n('dd',formatPrice(value)));}box.append(dl);if(r.validation_warnings?.length)box.append(n('p','Incomplete quote metadata: '+r.validation_warnings.join(', '),'quote-notice'));box.append(n('p','Preview only. No trade has been submitted.','meta'));const review=n('button',`Review ${p.symbol??ticker}`,'review-route');review.type='button';review.dataset.valid=r.diagnostic_fields_verified?'yes':'no';review.disabled=!r.diagnostic_fields_verified;review.addEventListener('click',()=>reviewRoute(p.provider,r.vendor));box.append(review);card.append(box);}results.append(card);}results.append(n('p',`Quote snapshot UTC: ${report.checked_at_utc} · Binance requests: ${report.binance_http_requests} · Weight: ${report.observed_weight_sum??'Unknown'} · Time: ${report.total_time_ms} ms`,'quote-summary'));for(const r of report.requests??[])results.append(n('p',`${r.symbol}: ${r.request_utc} · HTTP ${r.http} · Code ${r.business_code??'Not supplied'} · ${r.time_ms} ms · Weight ${r.weight??'Unknown'} · Remaining ${r.remaining??'Unknown'}`,'quote-summary'));results.append(n('p','Quotes are estimates at the displayed time. Obtain a new quote before execution.','meta'));}
el('quote-form').addEventListener('submit',async e=>{
  e.preventDefault();if(busy||!provider||el('quote').disabled)return;
  const amount=el('amount').value.trim();if(!/^(0|[1-9][0-9]{0,3})(\.[0-9]{1,18})?$/.test(amount)||!/[1-9]/.test(amount)){notice.textContent='Enter a positive USDT amount below 10,000, with at most 18 decimal places.';return;}
  try{demoPurchaseAmount(amount);}catch{notice.textContent='Enter a purchase amount from 6 to 10 USDT.';return;}
  busy=true;invalidate();const snapshot=version;controls();notice.textContent='Requesting provider quotes…';
  try{
    const accounts=await provider.request({method:'eth_accounts'}),network=String(await provider.request({method:'eth_chainId'}));
    if(!validAddress(accounts?.[0])||accounts[0].toLowerCase()!==address.toLowerCase()||network.toLowerCase()!=='0x38'){accountsChanged(accounts);chainChanged(network);throw new Error('wallet_changed');}
    const response=await fetch('rwa-quote-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,ticker,amount,wallet:address}),signal:AbortSignal.timeout(70000)});
    const report=await response.json();if(snapshot!==version)return;if(!response.ok||!report.ok)throw new Error(report.error??'quotes_unavailable');display(report);notice.textContent='Provider quotes received. Review a route before preparing a purchase.';results.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
  }catch(e){if(snapshot===version)notice.textContent=({please_wait:'Please wait 10 seconds before requesting another quote.',quotes_busy:'Another quote request is running. Try again shortly.',sync_busy_try_again:'Market data refresh is running. Try again shortly.',csrf_failed:'Your session changed. Reload this page.',wallet_changed:'Wallet or network changed. Reconnect and request fresh quotes.'})[e.message]??'Quotes are temporarily unavailable. No trade was attempted.';}
  finally{busy=false;controls();}
});
(async()=>{if(!validTicker){notice.textContent='Choose an asset using a Buy button in the conversation.';controls();return;}title.textContent=`Buy ${ticker}`;if(window.ethereum&&!wallets.length)announce({provider:window.ethereum,info:{name:'Browser wallet'}});try{const r=await fetch('rwa-quote-api.php',{credentials:'same-origin',cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error();csrf=d.csrf;notice.textContent=wallets.length?'Connect a wallet to request quotes.':'No browser wallet detected. Open this page in a wallet browser or enable a wallet extension.';}catch{notice.textContent='The quote service is unavailable. Please reload after installation.';}controls();})();

function exactUnits(value,decimals=18){const b=BigInt(value);const p=b.toString().padStart(decimals+1,'0');const f=p.slice(-decimals).replace(/0+$/,'');return p.slice(0,-decimals)+(f?'.'+f:'');}
function uintHex(value){if(typeof value!=='string'||!/^0x[0-9a-fA-F]{64}$/.test(value))throw Error('invalid_contract_read');return BigInt(value);}
function quantityHex(value){if(typeof value!=='string'||!/^0x[0-9a-fA-F]{1,64}$/.test(value))throw Error('invalid_native_read');return BigInt(value);}
function word(a){if(!validAddress(a))throw Error('invalid_wallet_address');return a.slice(2).toLowerCase().padStart(64,'0');}
function walletRead(args){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('wallet_read_timeout')),12000);Promise.resolve().then(()=>provider.request(args)).then(resolve,reject).finally(()=>clearTimeout(timer));});}
async function stableWallet(){const accounts=await walletRead({method:'eth_accounts'}),network=String(await walletRead({method:'eth_chainId'}));if(!validAddress(accounts?.[0])||accounts[0].toLowerCase()!==address.toLowerCase()||network.toLowerCase()!=='0x38'){accountsChanged(accounts);chainChanged(network);throw Error('wallet_changed');}}
async function walletChecks(preview,amount){
  const owner=address,spender=preview.approve_target,usdt='0x55d398326f99059fF775485246999027B3197955';
  let reads=0;const read=args=>{reads++;return walletRead(args);};
  let block;try{block=await read({method:'eth_blockNumber'});if(typeof block!=='string'||!/^0x[0-9a-fA-F]{1,16}$/.test(block))throw Error();}catch{return{read_requests:reads,block:null,items:[{label:'Wallet checks',text:'The wallet RPC could not provide a block number. Balances and permission remain unknown.',ok:false}]};}
  const requests=[read({method:'eth_call',params:[{to:usdt,data:'0x313ce567'},block]}),read({method:'eth_call',params:[{to:usdt,data:'0x70a08231'+word(owner)},block]}),read({method:'eth_getBalance',params:[owner,block]})];
  if(validAddress(spender))requests.push(read({method:'eth_call',params:[{to:usdt,data:'0xdd62ed3e'+word(owner)+word(spender)},block]}));
  const values=await Promise.allSettled(requests),items=[];
  const [whole,fraction='']=amount.split('.');const required=BigInt(whole+fraction.padEnd(18,'0'));
  let decimals=false;try{decimals=values[0].status==='fulfilled'&&uintHex(values[0].value)===18n;}catch{}
  if(!decimals)items.push({label:'USDT precision',text:'The wallet RPC did not verify 18 decimals. USDT balance and permission checks remain unverified.',ok:false});
  let balance=null,allowance=null,bnb=null;
  try{if(decimals&&values[1].status==='fulfilled')balance=uintHex(values[1].value);}catch{}
  try{if(values[2].status==='fulfilled')bnb=quantityHex(values[2].value);}catch{}
  try{if(decimals&&values[3]?.status==='fulfilled')allowance=uintHex(values[3].value);}catch{}
  items.push({label:'USDT balance',text:balance===null?'Unknown; the wallet RPC read failed.':`${formatPrice(exactUnits(balance))} USDT · ${balance>=required?'Enough for the entered amount.':'Not enough for the entered amount.'}`,ok:balance!==null&&balance>=required});
  items.push({label:'USDT spending permission',text:allowance===null?'Unknown; the spender or wallet RPC response could not be verified.':allowance>=required?'Existing permission covers this amount.':allowance>0n?'Permission is below this amount. The later approval flow may require a reset before a new approval.':'No sufficient permission. A separate USDT approval will be required.',ok:allowance!==null&&allowance>=required});
  let fee=null;const tx=preview.transaction_summary??{};try{const gas=tx.gas,price=tx.maxFeePerGas??tx.gasPrice;if(typeof gas==='string'&&/^\d{1,30}$/.test(gas)&&typeof price==='string'&&/^\d{1,30}$/.test(price))fee=BigInt(gas)*BigInt(price);}catch{}
  items.push({label:'BNB balance',text:bnb===null?'Unknown; the wallet RPC read failed.':`${formatPrice(exactUnits(bnb))} BNB`,ok:bnb!==null&&bnb>0n});
  items.push({label:'Swap gas estimate',text:fee===null?'Unknown; transaction gas parameters were not supplied.':`${formatPrice(exactUnits(fee))} BNB · ${bnb===null?'Balance check unavailable.':bnb>=fee?'Balance covers this estimate.':'BNB balance is below this estimate.'} Approval gas, if needed, is additional. Final gas costs can change.`,ok:fee!==null&&bnb!==null&&bnb>=fee});
  return{read_requests:reads,block,items};
}
function showReview(report,checks){
  const box=el('trade-review');box.replaceChildren();box.className='trade-review';box.hidden=false;
  const p=report.preview,tx=p.transaction_summary??{};box.append(n('h2',`Review ${p.symbol}`));
  const dl=n('dl');
  for(const [label,value] of [['Provider',({bstock:'bStocks',ondo:'Ondo',xstocks:'xStocks'})[report.provider]],['Route',`${p.vendor} · ${p.build_execution_mode}`],['Spend',report.input_amount_usdt+' USDT'],['Estimated receive',formatPrice(p.build_output_amount??'Unknown')+' '+p.symbol],['Minimum receive',p.minimum_output_amount===null?'Not supplied':formatPrice(p.minimum_output_amount)+' '+p.symbol],['Slippage tolerance',report.slippage_percent+'%'],['Price impact protection',report.price_impact_protection_percent+'%'],['Wallet',report.wallet_address],['Token contract',p.contract_address],['Spending permission contract',p.approve_target??'Unknown'],['Transaction destination',tx.to??'Not supplied']])dl.append(n('dt',label),n('dd',value));
  box.append(dl);if(p.metadata_warnings?.length)box.append(n('p','API metadata could not be fully verified: '+p.metadata_warnings.join(', '),'check-warning'));
  const minimum=tx.minReceiveAmount,out=p.build_output_amount_raw;
  if(p.build_execution_mode==='SWAP'){
    let consistent=false;try{if(/^\d+$/.test(minimum??'')&&/^\d+$/.test(out??'')){const m=BigInt(minimum),o=BigInt(out);consistent=m>0n&&m<=o&&m>=o*995n/1000n;}}catch{}
    if(!consistent)box.append(n('p','The returned minimum receive could not be reconciled with the 0.5% tolerance. This review cannot establish an execution-ready order.','check-warning'));
  }
  box.append(n('h3','Wallet checks'));
  for(const item of checks.items)box.append(n('p',`${item.label}: ${item.text}`,item.ok?'check-ok':'check-warning'));
  box.append(n('p',`Wallet reads: ${checks.read_requests} · Block: ${checks.block??'Unknown'}`,'meta'));
  box.append(n('p',`Prepared UTC: ${report.checked_at_utc} · API requests: ${report.binance_http_requests} · Weight: ${report.observed_weight_sum??'Unknown'} · Time: ${report.total_time_ms} ms`,'quote-summary'));
  for(const r of report.requests??[])box.append(n('p',`${r.stage??'quote'}: ${r.request_utc} · ${r.time_ms} ms · Weight ${r.weight??'Unknown'} · Remaining ${r.remaining??'Unknown'}`,'meta'));
  box.append(n('p','Review only. No approval or purchase has been submitted. Balances and gas estimates do not establish that a trade will succeed. The final execution step will require a fresh quote and wallet confirmation.','meta'));
  if(!p.metadata_warnings?.length&&p.build_execution_mode==='SWAP'&&!pendingApproval()){const permission=checks.items.find(i=>i.label==='USDT spending permission');if(permission&&!permission.ok&&!permission.text.startsWith('Unknown')){const b=n('button',`Prepare approval for ${report.input_amount_usdt} USDT`,'approval-action');b.type='button';b.addEventListener('click',()=>prepareApproval(report.provider,p.vendor));box.append(b);}}
  if(!p.metadata_warnings?.length&&p.build_execution_mode==='SWAP'&&p.vendor==='LiquidMesh'&&p.approve_target?.toLowerCase()===swapRouter&&checks.items.find(i=>i.label==='USDT spending permission')?.ok){const b=n('button','Prepare purchase','approval-action');b.type='button';b.addEventListener('click',()=>prepareSwap(report.provider,p.vendor));box.append(b);}
  box.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
}
async function reviewRoute(selected,vendor,action='review'){
  if(busy||connecting||!provider||!csrf||!address||chain.toLowerCase()!=='0x38')return;
  const amount=el('amount').value.trim(),snapshot=++version;el('trade-review').hidden=true;el('trade-review').replaceChildren();busy=true;controls();notice.textContent='Preparing a fresh quote for the selected provider…';
  try{
    await stableWallet();
    const response=await fetch('rwa-quote-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,action,ticker,amount,wallet:address,provider:selected,vendor}),signal:AbortSignal.timeout(70000)});
    const report=await response.json();if(snapshot!==version)return;
    if(!response.ok||!report.ok)throw Error(report.error??'review_unavailable');
    if(report.wallet_address?.toLowerCase()!==address.toLowerCase()||report.ticker!==ticker||report.provider!==selected||report.preview?.vendor!==vendor)throw Error('review_identity_mismatch');
    notice.textContent='Checking wallet balances and USDT spending permission…';
    const checks=await walletChecks(report.preview,amount);await stableWallet();if(snapshot!==version)return;
    showReview(report,checks);if(action==='swap_check'){const sim=report.simulation;el('trade-review').append(n('p',sim?.transport_status==='received'?`Swap simulation: ${sim.simulation_status}. ${sim.fail_reason??''}`:'The swap simulation is unavailable.',sim?.simulation_status==='SUCCESS'?'check-ok':'check-warning'));}notice.textContent='Trade review prepared. No purchase was submitted.';
  }catch(e){if(snapshot===version)notice.textContent=({please_wait:'Another quote was recently requested. Wait a few seconds and review again.',quotes_busy:'Another quote request is running. Try again shortly.',sync_busy_try_again:'Market data refresh is running. Try again shortly.',selected_vendor_not_available:'The selected route is no longer available. Request new provider quotes.',provider_token_unavailable_or_ambiguous:'The selected provider token is no longer uniquely available in the catalogue.',quote_unavailable:'A fresh quote is unavailable for this route. Request new provider quotes.',build_unavailable:'The API could not prepare this route. Request new provider quotes.',wallet_changed:'Wallet or network changed. Reconnect and request new quotes.'})[e.message]??'The review could not be prepared. No trade was attempted.';}
  finally{busy=false;controls();}
}

const approvalUSDT='0x55d398326f99059fF775485246999027B3197955';
let approvalDraft=null;
function amountRaw(s){if(!/^(0|[1-9][0-9]{0,3})(\.[0-9]{1,18})?$/.test(s))throw Error('invalid_amount');const [w,f='']=s.split('.');const raw=BigInt(w+f.padEnd(18,'0'));if(raw<=0n)throw Error('invalid_amount');return raw;}
function approvalData(spender,raw){return '0x095ea7b3'+word(spender)+raw.toString(16).padStart(64,'0');}
function approvalKey(){return 'bnb-rwa-approval:56:'+address.toLowerCase();}
function pendingApproval(){if(!address)return null;try{const s=localStorage.getItem(approvalKey());if(!s)return null;const d=JSON.parse(s);if(d.owner?.toLowerCase()!==address.toLowerCase()||d.chain!=='0x38'||!validAddress(d.spender)||!/^\d{1,23}$/.test(d.amount_raw)||!(d.hash===null||/^0x[0-9a-fA-F]{64}$/.test(d.hash)))return{invalid:true};return d;}catch{return{invalid:true};}}
function savePending(d){localStorage.setItem(approvalKey(),JSON.stringify(d));}
function clearPending(){localStorage.removeItem(approvalKey());}
function approvalNote(message,hash){const panel=el('approval-status');panel.replaceChildren(n('h2','USDT approval'),n('p',message));panel.hidden=false;if(hash&&/^0x[0-9a-fA-F]{64}$/.test(hash)){const link=n('a','View transaction on BscScan');link.href='https://bscscan.com/tx/'+hash;link.target='_blank';link.rel='noopener noreferrer';panel.append(link);}return panel;}
function renderPending(){const d=pendingApproval();if(!d)return;const panel=approvalNote(d.invalid?'Saved approval status cannot be read. Check wallet activity before approving again.':d.hash?'An approval is awaiting verification. Do not submit it again.':'A wallet approval request may still be pending. Check wallet activity; no automatic retry will be sent.',d.hash);if(d.hash){const b=n('button','Check approval status','approval-action');b.type='button';b.addEventListener('click',()=>checkApproval());panel.append(b);}controls();}
function validateApproval(report,selected,vendor,amount){
 const a=report.approval_transaction,raw=amountRaw(amount);
 if(!report.ok||!report.approval_enabled||report.wallet_address?.toLowerCase()!==address.toLowerCase()||report.ticker!==ticker||report.provider!==selected||report.input_amount_usdt!==amount||report.preview?.vendor!==vendor||!a||a.chainId!=='0x38'||a.from?.toLowerCase()!==address.toLowerCase()||a.to?.toLowerCase()!==approvalUSDT.toLowerCase()||a.value!=='0'||a.amount_raw!==raw.toString()||!validAddress(a.spender)||a.spender.toLowerCase()!==report.preview.approve_target?.toLowerCase()||a.unlimited_approval!==false||a.simulation_verified!==true||a.data?.toLowerCase()!==approvalData(a.spender,raw))throw Error('approval_identity_mismatch');
 return a;
}
async function prepareApproval(selected,vendor){
 if(busy||connecting||!provider||!csrf||!address||chain.toLowerCase()!=='0x38')return;
 if(pendingSwap()){renderPendingSwap();return;}if(pendingApproval()){renderPending();return;}
 const amount=el('amount').value.trim(),snapshot=version;busy=true;controls();notice.textContent='Preparing and simulating an exact-amount USDT approval…';
 try{await stableWallet();const response=await fetch('rwa-quote-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,action:'approval',ticker,amount,wallet:address,provider:selected,vendor}),signal:AbortSignal.timeout(100000)});const report=await response.json();if(snapshot!==version)return;if(!response.ok||!report.ok)throw Error(report.error??'approval_unavailable');const a=validateApproval(report,selected,vendor,amount);await stableWallet();if(snapshot!==version)return;
 approvalDraft={a,report,version:snapshot,created:Date.now(),amount};const panel=approvalNote(`Approve ${amount} USDT for ${a.spender} on BNB Smart Chain. This permits the spender to use up to this amount; it does not buy ${ticker}. Approval consumes BNB gas.`);
 panel.append(n('p',`Simulation verified · API requests: ${report.binance_http_requests} · Weight: ${report.observed_weight_sum??'Unknown'} · Time: ${report.total_time_ms} ms`,'meta'));
 const confirm=n('button',`Approve ${amount} USDT in wallet`,'approval-action');confirm.type='button';confirm.addEventListener('click',confirmApproval);panel.append(confirm);panel.scrollIntoView({block:'start',behavior:'smooth'});notice.textContent='Review the spender and amount, then confirm in your wallet.';
 }catch(e){if(snapshot===version)notice.textContent=({nonzero_allowance_requires_separate_review:'Existing nonzero permission requires a separate reset review. No approval was requested.',please_wait:'Wait a few seconds, then prepare approval again.',sync_busy_try_again:'Market data refresh is running. Try again shortly.',approval_simulation_not_verified:'The API could not verify the approval simulation. No approval was requested.'})[e.message]??'Approval could not be prepared. No wallet transaction was requested.';}finally{busy=false;controls();}
}
async function sendApproval(){
 const draft=approvalDraft;if(busy||connecting||!draft)return;
 if(draft.version!==version||Date.now()-draft.created>90000||el('amount').value.trim()!==draft.amount){approvalDraft=null;approvalNote('This approval preparation expired or the wallet/amount changed. Prepare a new approval.');return;}
 if(pendingSwap()){renderPendingSwap();return;}if(pendingApproval()){renderPending();return;}
 busy=true;controls();let sending=false;
 try{
 await stableWallet();const a=validateApproval(draft.report,draft.report.provider,draft.report.preview.vendor,draft.amount),raw=BigInt(a.amount_raw);
 const call={from:address,to:approvalUSDT,value:'0x0',data:a.data};
 const [permission,precision,code,callResult,gas,bnb,price]=await Promise.all([
 walletRead({method:'eth_call',params:[{to:approvalUSDT,data:'0xdd62ed3e'+word(address)+word(a.spender)},'latest']}),
 walletRead({method:'eth_call',params:[{to:approvalUSDT,data:'0x313ce567'},'latest']}),
 walletRead({method:'eth_getCode',params:[a.spender,'latest']}),
 walletRead({method:'eth_call',params:[call,'latest']}),
 walletRead({method:'eth_estimateGas',params:[call]}),
 walletRead({method:'eth_getBalance',params:[address,'latest']}),walletRead({method:'eth_gasPrice'})]);
 const allowance=uintHex(permission);if(allowance>=raw){approvalDraft=null;approvalNote('Existing spending permission already covers this amount. No new approval was sent.');return;}if(allowance!==0n)throw Error('nonzero_allowance');
 if(uintHex(precision)!==18n||typeof code!=='string'||!/^0x(?:[0-9a-fA-F]{2})+$/.test(code)||uintHex(callResult)!==1n)throw Error('approval_read_unverified');
 const estimate=quantityHex(gas),limit=(estimate*12n+9n)/10n,gasPrice=quantityHex(price);if(estimate<=0n||limit>200000n||gasPrice<=0n||quantityHex(bnb)<limit*gasPrice)throw Error('gas_balance_unverified');
 await stableWallet();if(draft.version!==version||Date.now()-draft.created>90000)throw Error('approval_expired');
 const pending={owner:address,chain:'0x38',spender:a.spender,amount_raw:a.amount_raw,hash:null,ticker,provider:draft.report.provider,vendor:draft.report.preview.vendor};savePending(pending);
 sending=true;notice.textContent='Confirm the exact USDT approval in your wallet. No purchase will be sent.';
 // Deliberately no timeout or retry around a financial wallet request.
 const hash=await provider.request({method:'eth_sendTransaction',params:[{...call,chainId:'0x38',gas:'0x'+limit.toString(16)}]});
 if(typeof hash!=='string'||!/^0x[0-9a-fA-F]{64}$/.test(hash))throw Error('transaction_hash_unknown');
 // Persist by original owner even if the wallet changes while its prompt is open.
 localStorage.setItem('bnb-rwa-approval:56:'+pending.owner.toLowerCase(),JSON.stringify({...pending,hash}));approvalDraft=null;approvalNote('Approval submitted. Wait for chain confirmation; no purchase has been submitted.',hash);renderPending();
 }catch(e){if(sending&&e.code===4001){localStorage.removeItem('bnb-rwa-approval:56:'+draft.a.from.toLowerCase());approvalNote('Approval was declined in the wallet. No automatic retry was sent.');}
 else if(sending){approvalNote('The wallet request outcome is uncertain. Check wallet activity before doing anything else. The app will not submit another approval automatically.');}
 else approvalNote(({nonzero_allowance:'Permission changed to a nonzero value. A separate reset review is required.',approval_expired:'Approval preparation expired. Prepare it again.',gas_balance_unverified:'Gas estimate or BNB coverage could not be verified. No approval was requested.'})[e.message]??'Wallet checks did not complete. No approval was requested.');
 }finally{busy=false;controls();}
}
async function checkApproval(){
 if(busy||connecting||!provider)return;const d=pendingApproval();if(!d||d.invalid||!d.hash){renderPending();return;}const snapshot=version,owner=address;busy=true;controls();
 try{await stableWallet();const [tx,receipt,head]=await Promise.all([walletRead({method:'eth_getTransactionByHash',params:[d.hash]}),walletRead({method:'eth_getTransactionReceipt',params:[d.hash]}),walletRead({method:'eth_blockNumber'})]);await stableWallet();if(snapshot!==version||owner.toLowerCase()!==address.toLowerCase())throw Error('wallet_changed');
 if(!tx||!receipt){approvalNote('Approval is pending or not yet indexed. Check again shortly; do not submit it again.',d.hash);return;}
 if(tx.hash?.toLowerCase()!==d.hash.toLowerCase()||receipt.transactionHash?.toLowerCase()!==d.hash.toLowerCase()||tx.from?.toLowerCase()!==d.owner.toLowerCase()||tx.to?.toLowerCase()!==approvalUSDT.toLowerCase()||tx.input?.toLowerCase()!==approvalData(d.spender,BigInt(d.amount_raw))||quantityHex(tx.value)!==0n||(tx.chainId!==undefined&&quantityHex(tx.chainId)!==56n))throw Error('transaction_identity_unverified');
 if(receipt.status==='0x0'){clearPending();approvalNote('The approval transaction reverted. Gas may have been spent; no purchase was sent.',d.hash);return;}if(receipt.status!=='0x1')throw Error('receipt_status_unknown');
 if(!receipt.blockHash||tx.blockHash?.toLowerCase()!==receipt.blockHash.toLowerCase()||quantityHex(head)<quantityHex(receipt.blockNumber)+1n){approvalNote('Approval included; waiting for one additional block before verifying permission. Check again shortly.',d.hash);return;}
 const actual=uintHex(await walletRead({method:'eth_call',params:[{to:approvalUSDT,data:'0xdd62ed3e'+word(d.owner)+word(d.spender)},'latest']}));await stableWallet();if(snapshot!==version||owner.toLowerCase()!==address.toLowerCase())throw Error('wallet_changed');
 if(actual<BigInt(d.amount_raw)){approvalNote('Approval was mined, but current permission no longer covers the amount. No purchase was sent. Check current wallet activity.',d.hash);return;}
 clearPending();approvalDraft=null;approvalNote('Approval confirmed and current spending permission verified. Prepare a fresh swap review and simulation; no purchase has been sent.',d.hash);
 const panel=el('approval-status');if(d.ticker!==ticker){const link=n('a',`Return to ${d.ticker} purchase review`);link.href='rwa-buy.php?ticker='+encodeURIComponent(d.ticker);panel.append(link);}else{const b=n('button','Refresh review and simulate swap','approval-action');b.type='button';b.addEventListener('click',()=>{try{if(amountRaw(el('amount').value.trim()).toString()!==d.amount_raw)throw Error();reviewRoute(d.provider,d.vendor,'swap_check');}catch{notice.textContent='Amount changed. Get new quotes and review the selected route.';}});panel.append(b);}
 }catch{approvalNote('Approval status could not be verified. Check again after reconnecting to the original wallet on BNB Smart Chain. Do not submit the approval again.',d.hash);renderPending();}finally{busy=false;controls();}
}

async function confirmApproval(){
 if(busy||connecting||!address)return;
 if(!navigator.locks?.request){approvalNote('This browser cannot coordinate approval requests across tabs. Use a current secure browser with a wallet extension. No transaction was requested.');return;}
 try{await navigator.locks.request('bnb-rwa-approval:56:'+address.toLowerCase(),{mode:'exclusive',ifAvailable:true},async lock=>{if(!lock){approvalNote('An approval request is already active in another tab. Check that tab or wallet activity.');return;}await sendApproval();});}catch{approvalNote('The approval request could not be coordinated. Check wallet activity before retrying.');}
}

// Small purchase pilot: fresh validated simulation, separate wallet confirmation.
const swapRouter='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';
const transferTopic='0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
function demoPurchaseAmount(amount){const raw=amountRaw(amount);if(raw<6n*10n**18n||raw>10n*10n**18n)throw Error('purchase_amount_must_be_6_to_10_USDT');return raw;}
let swapDraft=null;
const quoteDeadlines=new WeakMap();
function establishQuoteDeadline(r,started){const now=performance.now(),a=r.swap_transaction;if(!a||!Number.isSafeInteger(r.server_now_ms)||!Number.isSafeInteger(a.valid_until_ms))throw Error("quote_time_unverified");const remaining=a.valid_until_ms-r.server_now_ms;if(remaining<=0||remaining>25000)throw Error("fresh_quote_required");const transport=Math.max(0,now-started);quoteDeadlines.set(a,now+remaining-transport);}
function quoteExpired(a){const deadline=quoteDeadlines.get(a);return !Number.isFinite(deadline)||performance.now()>=deadline;}
const same=(a,b)=>typeof a==='string'&&typeof b==='string'&&a.toLowerCase()===b.toLowerCase();
async function dataHash(data){const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(data));return Array.from(new Uint8Array(bytes),b=>b.toString(16).padStart(2,'0')).join('');}
function swapKey(owner=address){return 'bnb-rwa-swap:56:'+owner.toLowerCase();}
function pendingSwap(){if(!address)return null;try{const s=localStorage.getItem(swapKey());if(!s)return null;const d=JSON.parse(s);if(!same(d.owner,address)||d.chain!=='0x38'||d.to!==swapRouter||!validAddress(d.token)||!/^\d{1,23}$/.test(d.raw)||!/^\d{1,78}$/.test(d.minimum)||!/^\d{1,78}$/.test(d.quoted)||BigInt(d.raw)<=0n||BigInt(d.raw)>10n**19n||BigInt(d.minimum)<=0n||BigInt(d.minimum)>BigInt(d.quoted)||!/^[\da-f]{64}$/.test(d.data_hash)||!(d.hash===null||/^0x[\da-fA-F]{64}$/.test(d.hash)))return{invalid:true};return d;}catch{return{invalid:true};}}
function purchaseNote(message,hash){const panel=el('purchase-status');panel.replaceChildren(n('h2','Purchase'),n('p',message));panel.hidden=false;if(/^0x[\da-fA-F]{64}$/.test(hash??'')){const a=n('a','View transaction on BscScan');a.href='https://bscscan.com/tx/'+hash;a.target='_blank';a.rel='noopener noreferrer';panel.append(a);}return panel;}
function renderPendingSwap(){const d=pendingSwap();if(!d)return;const panel=purchaseNote(d.invalid?'Saved purchase status is unreadable. Check wallet activity before buying again.':d.hash?'A purchase is awaiting verification. Do not send it again.':'A purchase request may still be pending in the wallet. Check wallet activity before attempting another purchase.',d.hash);if(d.hash){const b=n('button','Check purchase status','approval-action');b.addEventListener('click',checkSwap);panel.append(b);}controls();}
async function validateSwap(r,selected,vendor,amount){
 const a=r.swap_transaction,p=r.preview,raw=amountRaw(amount);
 if(!r.ok||r.execution_enabled!==true||r.ticker!==ticker||r.provider!==selected||r.input_amount_usdt!==amount||!same(r.wallet_address,address)||p?.vendor!==vendor||vendor!=='LiquidMesh'||p.build_execution_mode!=='SWAP'||p.metadata_warnings?.length||!a||!same(a.from,address)||a.to?.toLowerCase()!==swapRouter||a.spender!==swapRouter||a.chainId!=='0x38'||a.value!=='0'||!same(a.input_token,approvalUSDT)||!same(a.output_token,p.contract_address)||!validAddress(a.output_token)||same(a.output_token,approvalUSDT)||a.output_decimals!==18||a.effects_verified!==true||a.input_amount_raw!==raw.toString()||(raw<6n*10n**18n||raw>10n**19n)||!/^0xad43f73d(?:[\da-fA-F]{2}){1,32768}$/.test(a.data??''))throw Error('swap_identity_mismatch');
 for(const k of ['minimum_output_raw','simulated_output_raw'])if(!/^[1-9]\d{0,77}$/.test(a[k]??''))throw Error('invalid_output');
 const out=BigInt(p.build_output_amount_raw),min=BigInt(a.minimum_output_raw);if(min!==BigInt(p.transaction_summary.minReceiveAmount)||min>out||min<out*995n/1000n||BigInt(a.simulated_output_raw)<min)throw Error('invalid_minimum');
 if(quoteExpired(a))throw Error('fresh_quote_required');
 const sim=r.simulation,rows=sim?.balanceChanges,changes=sim?.allowanceChanges;
 if(sim?.transport_status!=='received'||sim.simulation_status!=='SUCCESS'||sim.fail_reason||sim.balanceChanges_truncated||sim.allowanceChanges_truncated||rows?.length!==2||changes?.length!==1)throw Error('effects_unverified');
 const debit=rows.find(x=>same(x.contractAddress,approvalUSDT)),credit=rows.find(x=>same(x.contractAddress,a.output_token));
 if(!debit||!credit||!rows.every(x=>same(x.owner,address)&&x.tokenType==='Erc20')||debit.change!=='-'+a.input_amount_raw||credit.change!==a.simulated_output_raw)throw Error('effects_unverified');
 const c=changes[0];if(!same(c.owner,address)||!same(c.tokenAddress,approvalUSDT)||!same(c.spender,swapRouter)||!/^\d{1,78}$/.test(c.preAmount??'')||!/^\d{1,78}$/.test(c.postAmount??'')||BigInt(c.preAmount)-BigInt(c.postAmount)!==raw)throw Error('allowance_effect_unverified');
 if(await dataHash(a.data)!==a.calldata_sha256)throw Error('calldata_mismatch');return a;
}
async function prepareSwap(selected,vendor){
 if(busy||connecting||!provider||!csrf||!address||chain.toLowerCase()!=='0x38')return;
 if(pendingApproval()){renderPending();return;}if(pendingSwap()){renderPendingSwap();return;}
 const amount=el('amount').value.trim(),snapshot=version;busy=true;controls();swapDraft=null;notice.textContent='Preparing a fresh quote and verifying simulated purchase effects…';
 try{demoPurchaseAmount(amount);await stableWallet();const requestStarted=performance.now();const response=await fetch('rwa-quote-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,action:'swap_prepare',ticker,amount,wallet:address,provider:selected,vendor}),signal:AbortSignal.timeout(70000)});const r=await response.json();if(snapshot!==version)return;if(!response.ok||!r.ok)throw Error(r.error??'purchase_unavailable');establishQuoteDeadline(r,requestStarted);const a=await validateSwap(r,selected,vendor,amount);await stableWallet();if(snapshot!==version)return;if(quoteExpired(a))throw Error('fresh_quote_required');
 swapDraft={a,r,amount,version:snapshot};const panel=purchaseNote(`Spend ${amount} USDT for ${r.preview.symbol}. Estimated receive: ${formatPrice(r.preview.build_output_amount)}. Minimum receive: ${formatPrice(exactUnits(a.minimum_output_raw))}. Simulated receive: ${formatPrice(exactUnits(a.simulated_output_raw))}. Slippage tolerance: 0.5%. BNB gas is additional.`);
 panel.append(n('p',`Simulation verified. About ${Math.max(0,Math.floor((quoteDeadlines.get(a)-performance.now())/1000))} seconds remain to request wallet confirmation. If it expires, prepare a fresh purchase. · API requests: ${r.binance_http_requests} · Weight: ${r.observed_weight_sum}`,'meta'));
 const b=n('button',`Buy ${r.preview.symbol} for ${amount} USDT in wallet`,'approval-action');b.type='button';b.addEventListener('click',confirmSwap);panel.append(b);panel.scrollIntoView({block:'start',behavior:'smooth'});notice.textContent='Purchase prepared. Sending requires your separate wallet confirmation.';
 }catch(e){if(snapshot===version)purchaseNote(e.message==='purchase_amount_must_be_6_to_10_USDT'?'Enter a purchase amount from 6 to 10 USDT.':e.message==='fresh_quote_required'?'The quote expired. Prepare a fresh purchase.':'Purchase preparation could not be verified. No transaction was requested. Wait a few seconds, then prepare again.');}finally{busy=false;controls();notice.textContent=swapDraft?'Purchase prepared. Review the summary and confirm in your wallet.':'Purchase preparation finished. No wallet transaction was requested.';}
}
async function confirmSwap(){
 if(busy||connecting||!address)return;if(!navigator.locks?.request){purchaseNote('Use a secure browser that supports coordination across tabs. No transaction was requested.');return;}
 try{await navigator.locks.request('bnb-rwa-approval:56:'+address.toLowerCase(),{mode:'exclusive',ifAvailable:true},async lock=>{if(!lock){purchaseNote('Another wallet transaction request is active in another tab.');return;}await sendSwap();});}catch{purchaseNote('The purchase request could not be coordinated. Check wallet activity before retrying.');}
}
async function purchaseRead(stage,args){
 try{return await walletRead(args);}catch(original){const e=Error('wallet_rpc_failed');e.checkStage=stage;e.rpcCode=typeof original?.code==='number'?original.code:null;const message=String(original?.message??'').toLowerCase();e.rpcReason=message.includes('allowance')?'allowance':message.includes('insufficient funds')?'insufficient_funds':message.includes('expired')||message.includes('deadline')?'quote_expired':message.includes('revert')?'execution_reverted':message.includes('timeout')?'timeout':message.includes('rate')?'rate_limit':'rpc_error';throw e;}
}
function purchaseCheckMessage(e,stage){
 const known={fresh_quote_required:'The quote expired or wallet changed. Prepare a fresh purchase.',usdt_precision:'USDT decimals could not be verified.',output_precision:'The output token decimals could not be verified.',usdt_balance:'Current USDT balance is below the purchase amount.',usdt_allowance:'Current USDT permission does not cover the purchase amount.',router_code:'Router contract code could not be verified.',simulation_reply:'The wallet returned an invalid simulation response.',gas_limit:'The wallet gas estimate is outside the pilot limit.',gas_price:'The wallet returned an invalid gas price.',bnb_balance:'BNB balance does not cover the checked gas estimate.',wallet_changed:'Wallet or network changed. Reconnect before preparing a fresh purchase.'};
 const key=Object.hasOwn(known,e.message)?e.message:'check_failed',check=e.checkStage??stage,code=Number.isFinite(e.rpcCode)?e.rpcCode:null,reason=e.rpcReason??key;
 console.warn('[rwa-purchase-check]',{stage:check,reason,rpc_code:code,...(e.gasDetails??{})});
 return (known[key]??'The final wallet check could not be completed.')+' No purchase was requested. Check: '+check+'; reason: '+reason+(code!==null?'; RPC code: '+code:'')+'.'+(e.gasDetails?' Estimated gas: '+e.gasDetails.estimated_gas+'; with 20% buffer: '+e.gasDetails.buffered_gas+'; pilot limit: '+e.gasDetails.pilot_limit+'; gas price: '+e.gasDetails.gas_price_wei+' wei; buffered fee estimate: '+e.gasDetails.buffered_fee_bnb+' BNB.':'');
}
async function sendSwap(){
 const d=swapDraft;if(!d||busy)return;if(pendingApproval()){renderPending();return;}if(pendingSwap()){renderPendingSwap();return;}
 if(d.version!==version||d.amount!==el('amount').value.trim()||quoteExpired(d.a)){swapDraft=null;purchaseNote('The quote expired or wallet/amount changed. Prepare a fresh purchase.');return;}
 busy=true;controls();let sending=false,pending=null,checkStage='wallet_identity';const owner=address;
 try{await stableWallet();checkStage='prepared_transaction';const a=await validateSwap(d.r,d.r.provider,d.r.preview.vendor,d.amount),raw=BigInt(a.input_amount_raw);const call={from:owner,to:a.to,value:'0x0',data:a.data};
 const [precision,outPrecision,balance,allowance,code,simulation,gas,bnb,price]=await Promise.all([
 purchaseRead('USDT decimals',{method:'eth_call',params:[{to:approvalUSDT,data:'0x313ce567'},'latest']}),purchaseRead('Output token decimals',{method:'eth_call',params:[{to:a.output_token,data:'0x313ce567'},'latest']}),purchaseRead('USDT balance',{method:'eth_call',params:[{to:approvalUSDT,data:'0x70a08231'+word(owner)},'latest']}),purchaseRead('USDT allowance',{method:'eth_call',params:[{to:approvalUSDT,data:'0xdd62ed3e'+word(owner)+word(a.spender)},'latest']}),purchaseRead('Router code',{method:'eth_getCode',params:[a.to,'latest']}),purchaseRead('Swap eth_call',{method:'eth_call',params:[call,'latest']}),purchaseRead('Swap gas estimate',{method:'eth_estimateGas',params:[call]}),purchaseRead('BNB balance',{method:'eth_getBalance',params:[owner,'latest']}),purchaseRead('Gas price',{method:'eth_gasPrice'})]);
 checkStage='USDT decimals';if(uintHex(precision)!==18n)throw Error('usdt_precision');checkStage='Output token decimals';if(uintHex(outPrecision)!==18n)throw Error('output_precision');checkStage='USDT balance';if(uintHex(balance)<raw)throw Error('usdt_balance');checkStage='USDT allowance';if(uintHex(allowance)<raw)throw Error('usdt_allowance');checkStage='Router code';if(!/^0x(?:[\da-fA-F]{2})+$/.test(code))throw Error('router_code');checkStage='Swap eth_call';if(!/^0x(?:[\da-fA-F]{2})*$/.test(simulation))throw Error('simulation_reply');
 checkStage='Swap gas estimate';const estimate=quantityHex(gas),limit=(estimate*120n+99n)/100n;checkStage='Gas price';const feePrice=quantityHex(price);if(feePrice<=0n)throw Error('gas_price');checkStage='Swap gas estimate';if(estimate<=0n||limit>1000000n){const error=Error('gas_limit');error.gasDetails={estimated_gas:estimate.toString(),buffered_gas:limit.toString(),pilot_limit:'1000000',gas_price_wei:feePrice.toString(),buffered_fee_bnb:exactUnits(limit*feePrice)};throw error;}checkStage='BNB balance';if(quantityHex(bnb)<limit*feePrice)throw Error('bnb_balance');
 checkStage='Final wallet and quote freshness';await stableWallet();if(d.version!==version||!same(owner,address)||d.amount!==el('amount').value.trim()||quoteExpired(a))throw Error('fresh_quote_required');
 pending={owner,chain:'0x38',to:swapRouter,token:a.output_token,raw:a.input_amount_raw,minimum:a.minimum_output_raw,quoted:d.r.preview.build_output_amount_raw,data_hash:a.calldata_sha256,symbol:d.r.preview.symbol,ticker,hash:null};checkStage='Pending purchase storage';localStorage.setItem(swapKey(owner),JSON.stringify(pending));sending=true;purchaseNote('Confirm the purchase in your wallet. Do not send a second transaction while this request is pending.');
 const hash=await provider.request({method:'eth_sendTransaction',params:[{...call,chainId:'0x38',gas:'0x'+limit.toString(16)}]});if(!/^0x[\da-fA-F]{64}$/.test(hash))throw Error('unknown_send_outcome');pending.hash=hash;localStorage.setItem(swapKey(owner),JSON.stringify(pending));swapDraft=null;purchaseNote('Purchase submitted. Check its status after the transaction is mined.',hash);if(same(owner,address))renderPendingSwap();
 }catch(e){if(sending&&e.code===4001){localStorage.removeItem(swapKey(owner));purchaseNote('Purchase declined in the wallet. No automatic retry was sent.');}else if(sending){purchaseNote('The wallet request outcome is uncertain. Check wallet activity before buying again. No retry was sent.',pending?.hash);}else{purchaseNote(purchaseCheckMessage(e,checkStage));notice.textContent='Wallet checks stopped the purchase. See the diagnostic above.';}swapDraft=null;}finally{busy=false;controls();}
}
function receiptEffects(logs,d){
 if(!Array.isArray(logs))throw Error('receipt_logs_missing');let debit=0n,credit=0n;for(const l of logs){if(!same(l.topics?.[0],transferTopic))continue;if(l.removed||l.topics.length!==3||!l.topics.slice(1).every(t=>/^0x0{24}[\da-fA-F]{40}$/.test(t))||!/^0x[\da-fA-F]{64}$/.test(l.data??''))throw Error('unverified_transfer');const from='0x'+l.topics[1].slice(-40),to='0x'+l.topics[2].slice(-40);if(!same(from,d.owner)&&!same(to,d.owner))continue;const v=uintHex(l.data);if(same(l.address,approvalUSDT))debit+=(same(from,d.owner)?v:0n)-(same(to,d.owner)?v:0n);else if(same(l.address,d.token))credit+=(same(to,d.owner)?v:0n)-(same(from,d.owner)?v:0n);else if(same(from,d.owner)&&v>0n)throw Error('unexpected_asset_debit');}
 if(debit!==BigInt(d.raw)||credit<BigInt(d.minimum))throw Error('settlement_amounts_unverified');return credit;
}
async function checkSwap(){
 const d=pendingSwap();if(busy||connecting||!d?.hash||d.invalid)return;const snapshot=version;busy=true;controls();
 try{await stableWallet();const [tx,r,head]=await Promise.all([walletRead({method:'eth_getTransactionByHash',params:[d.hash]}),walletRead({method:'eth_getTransactionReceipt',params:[d.hash]}),walletRead({method:'eth_blockNumber'})]);await stableWallet();if(snapshot!==version||!same(address,d.owner))throw Error('wallet_changed');
 if(!r){purchaseNote('Purchase is not yet confirmed. Check again shortly; do not send it again.',d.hash);return;}
 if(!tx||!same(tx.hash,d.hash)||!same(r.transactionHash,d.hash)||!same(tx.from,d.owner)||!same(tx.to,d.to)||quantityHex(tx.value)!==0n||quantityHex(tx.chainId)!==56n||!same(tx.blockHash,r.blockHash)||!/^0x[\da-fA-F]{64}$/.test(r.blockHash)||await dataHash(tx.input)!==d.data_hash)throw Error('receipt_identity_unverified');
 const status=quantityHex(r.status);if(status!==0n&&status!==1n)throw Error('receipt_status_unknown');if(quantityHex(head)<quantityHex(r.blockNumber)+1n){purchaseNote('Transaction mined. Waiting for one additional block before confirming.',d.hash);return;}
 if(status===0n){localStorage.removeItem(swapKey(d.owner));purchaseNote('Purchase reverted on-chain. No successful purchase was confirmed; BNB gas may have been spent.',d.hash);return;}
 const received=receiptEffects(r.logs,d);localStorage.removeItem(swapKey(d.owner));purchaseNote(`Purchase confirmed. Spent ${exactUnits(d.raw)} USDT and received ${formatPrice(exactUnits(received))} ${d.symbol}. Receipt transfer amounts verified.`,d.hash);
 }catch{purchaseNote('Purchase status or transfer effects could not be verified. The transaction may already have executed. Check BscScan and wallet activity; do not buy again until resolved.',d.hash);}finally{busy=false;controls();}
}
