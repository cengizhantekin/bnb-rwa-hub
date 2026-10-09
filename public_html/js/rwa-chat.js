'use strict';
const conversation=document.getElementById('conversation'),statusBox=document.getElementById('status'),composer=document.getElementById('composer'),message=document.getElementById('message'),clearConversation=document.getElementById('clear-conversation');
let csrf='',busy=false,lastMessages=[];
const node=(tag,text,className)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);if(className)n.className=className;return n;};
const validTicker=value=>typeof value==='string'&&/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(value);
function assetLink(ticker,text=ticker,className='asset-ticker-link'){if(!validTicker(ticker))return node('span',text,className);const a=node('a',text,className);a.href=`asset.php?ticker=${encodeURIComponent(ticker)}`;a.title=`Open ${ticker} asset page`;return a;}
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
function controls(items,delaySeconds=0,deadlineUtc=null,answeredAtUtc=null,serverNowUtc=null){
  const div=node('div',undefined,'actions');
  // The deadline and current UTC both come from the VPS; only elapsed time is measured locally.
  const serverNow=Date.parse(String(serverNowUtc??'')),observedAt=performance.now();
  const currentServerTime=()=>serverNow+(performance.now()-observedAt);
  const explicit=Date.parse(String(deadlineUtc??'')),rawSeconds=Number(delaySeconds);
  const seconds=Number.isFinite(rawSeconds)&&rawSeconds>0?Math.min(300,Math.max(1,Math.ceil(rawSeconds))):0;
  const answeredAt=Date.parse(String(answeredAtUtc??''));
  // Do not restart an expired saved cooldown from its stale retry_after_seconds field.
  const end=Number.isFinite(explicit)?explicit:Number.isFinite(answeredAt)&&seconds?answeredAt+seconds*1000:NaN;
  for(const item of items??[]){
    const b=node('button',item.label);b.type='button';b.addEventListener('click',()=>send(item.action,item.ticker??null,item.label));div.append(b);
    if(item.action==='more'&&Number.isFinite(serverNow)&&Number.isFinite(end)&&end>currentServerTime()){
      const countdown=node('span',undefined,'action-countdown');
      b.dataset.cooldownLocked='1';b.disabled=true;div.append(countdown);
      const tick=()=>{const left=Math.max(0,Math.ceil((end-currentServerTime())/1000));if(left){countdown.textContent=`Next batch available in ${left}s`;setTimeout(()=>{if(b.isConnected)tick();},250);}else{countdown.remove();delete b.dataset.cooldownLocked;if(!busy)b.disabled=false;}};tick();
    }
  }
  return div;
}
function assetHeader(reply){
  if(typeof reply.ticker!=='string'||! /^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(reply.ticker))return null;
  const asset=reply.asset?.ticker===reply.ticker?reply.asset:{};
  const header=node('header',undefined,'asset-header'),logo=node('div',undefined,'asset-logo');
  const initials=node('span',reply.ticker.slice(0,2),'asset-initials');logo.append(initials);
  let url;
  try{url=new URL(asset.logo_url);if(url.protocol!=='https:'||!['onchainos.bnbstatic.com','bin.bnbstatic.com','public.bnbstatic.com'].includes(url.hostname)||url.username||url.password||url.port||url.search||url.hash||!/^\/images\/web3-data\/public\/token\/logos\/[A-Za-z0-9._-]+\.(png|jpg|jpeg|webp|svg)$/i.test(url.pathname))url=null;}catch{url=null;}
  if(url){const img=node('img');img.alt=`${reply.ticker} token logo`;img.width=48;img.height=48;img.loading='lazy';img.referrerPolicy='no-referrer';img.decoding='async';
    img.addEventListener('load',()=>{initials.hidden=true;});img.addEventListener('error',()=>{img.remove();initials.hidden=false;});img.src=url.href;logo.append(img);}
  const copy=node('div',undefined,'asset-heading-copy'),title=node('h3',undefined,'asset-title');
  title.append(assetLink(reply.ticker,reply.ticker,'asset-ticker asset-ticker-link'));
  if(typeof asset.name==='string'&&asset.name.trim())title.append(node('span',` (${asset.name})`,'asset-name'));
  copy.append(title);
  const provider={bstock:'bStocks',ondo:'Ondo',xstocks:'xStocks'}[asset.provider];
  const label=reply.asset_provider&&provider?`${asset.symbol??reply.ticker} · ${provider}`:'RWA asset';
  copy.append(node('div',label,'asset-caption'));
  if(!asset.name||!url)copy.append(node('div',[!asset.name?'Name unavailable':null,!url?'Logo unavailable':null].filter(Boolean).join(' · '),'asset-caption'));
  header.append(logo,copy);return header;
}
function table(t){const wrap=node('details');wrap.open=true;wrap.append(node('summary',t.title));const scroll=node('div',undefined,'scroll'),table=node('table'),head=node('thead'),tr=node('tr');for(const c of t.columns)tr.append(node('th',c));head.append(tr);table.append(head);const tickerColumns=new Set((t.columns??[]).map((c,i)=>/^(ticker|underlying ticker)$/i.test(String(c).trim())?i:-1).filter(i=>i>=0)),body=node('tbody');for(const row of t.rows){const line=node('tr');row.forEach((v,i)=>{const cell=node('td');if(tickerColumns.has(i)&&validTicker(String(v)))cell.append(assetLink(String(v)));else cell.textContent=t.numeric_columns?.includes(i)?formatPrice(v):String(v??'');line.append(cell);});body.append(line);}table.append(body);scroll.append(table);wrap.append(scroll);return wrap;}
// Bounded, text-node-only Markdown for model-authored research. Never accept model HTML
// or arbitrary URLs. The older fixed fact tables, cards and other chat replies stay unchanged.
function researchSafeHref(value){
  const href=String(value||'').trim();
  if(/^(?:assets\.php|index\.php|rwa-agent\.php)$/.test(href))return href;
  const asset=/^asset\.php\?ticker=([A-Z0-9][A-Z0-9._-]{0,15})$/.exec(href);
  return asset ? `asset.php?ticker=${encodeURIComponent(asset[1])}` : null;
}
function researchInline(target,text){
  const source=String(text??'');
  const tokens=/\[([^\]\n]{1,100})\]\(([^\s)]+)\)|\*\*([^*\n]+)\*\*|`([^`\n]+)`|(?<![\w/])(?:asset\.php\?ticker=[A-Z0-9][A-Z0-9._-]{0,15}|assets\.php|index\.php|rwa-agent\.php)/g;
  let last=0, match;
  while((match=tokens.exec(source))!==null){
    if(match.index>last)target.append(document.createTextNode(source.slice(last,match.index)));
    if(match[1]!==undefined){
      const href=researchSafeHref(match[2]);
      if(href){const link=node('a',match[1]);link.href=href;target.append(link);}
      else target.append(document.createTextNode(match[0]));
    }else if(match[3]!==undefined)target.append(node('strong',match[3]));
    else if(match[4]!==undefined)target.append(node('code',match[4]));
    else{
      const href=researchSafeHref(match[0]);
      if(href){const link=node('a',match[0]);link.href=href;target.append(link);}
      else target.append(document.createTextNode(match[0]));
    }
    last=tokens.lastIndex;
  }
  if(last<source.length)target.append(document.createTextNode(source.slice(last)));
}
function researchTableCells(line){
  if(!line.includes('|'))return null;
  const trimmed=line.trim().replace(/^\|/,'').replace(/\|$/,'');
  const cells=trimmed.split('|').map(part=>part.trim());
  return cells.length>=2&&cells.length<=10?cells:null;
}
function researchSeparator(line,count){
  const cells=researchTableCells(line);
  return !!cells&&cells.length===count&&cells.every(c=>/^:?-{3,}:?$/.test(c));
}
function researchMarkdown(source){
  const root=node('div',undefined,'research-markdown');
  const lines=String(source??'').replace(/\r\n?/g,'\n').split('\n');
  let i=0;
  const tableStarts=at=>at+1<lines.length&&!!researchTableCells(lines[at])&&
    researchSeparator(lines[at+1],researchTableCells(lines[at]).length);
  const listKind=line=>/^\s*(?:[-*]\s+|\d+[.)]\s+)/.exec(line)?.[0]??null;
  while(i<lines.length){
    if(!lines[i].trim()){i++;continue;}
    if(tableStarts(i)){
      const header=researchTableCells(lines[i]),width=header.length;
      const scroll=node('div',undefined,'scroll research-table-wrap'),table=node('table'),thead=node('thead'),theadRow=node('tr');
      for(const cell of header){const th=node('th');researchInline(th,cell);theadRow.append(th);}
      thead.append(theadRow);table.append(thead);
      const tbody=node('tbody');i+=2;let rows=0;
      while(i<lines.length&&rows<30){
        const cells=researchTableCells(lines[i]);
        if(!cells||cells.length!==width||researchSeparator(lines[i],width))break;
        const tr=node('tr');for(const cell of cells){const td=node('td');researchInline(td,cell);tr.append(td);}tbody.append(tr);i++;rows++;
      }
      table.append(tbody);scroll.append(table);root.append(scroll);continue;
    }
    const heading=/^\s*(#{1,4})\s+(.+)$/.exec(lines[i]);
    if(heading){const h=node('h4');researchInline(h,heading[2]);root.append(h);i++;continue;}
    const kind=listKind(lines[i]);
    if(kind){
      const ordered=/\d/.test(kind[0]),list=node(ordered?'ol':'ul');
      while(i<lines.length&&listKind(lines[i])&&/\d/.test(listKind(lines[i])[0])===ordered){
        const li=node('li');researchInline(li,lines[i].replace(/^\s*(?:[-*]\s+|\d+[.)]\s+)/,''));list.append(li);i++;
      }
      root.append(list);continue;
    }
    const paragraph=[];
    while(i<lines.length&&lines[i].trim()&&!tableStarts(i)&&!/^\s*#{1,4}\s+/.test(lines[i])&&!listKind(lines[i])){
      paragraph.push(lines[i].trim());i++;
    }
    if(paragraph.length){const p=node('p');researchInline(p,paragraph.join(' '));root.append(p);}
  }
  return root;
}
function assistantHeading(){
  const header=node('header',undefined,'assistant-heading'),avatar=node('img');
  avatar.src='assets/hubble/hubble-48.webp';avatar.srcset='assets/hubble/hubble-96.webp 2x';avatar.alt='';avatar.width=40;avatar.height=40;avatar.decoding='async';
  avatar.addEventListener('error',()=>{avatar.hidden=true;});
  const copy=node('div');copy.append(node('h2','Hubble'),node('div','Your RWA research assistant','assistant-role'));header.append(avatar,copy);return header;
}
function render(messages,{focusLatest=false,serverNowUtc=null}={}){
  let latestAssistant=null;
  const nearBottom=window.innerHeight+window.scrollY>=document.documentElement.scrollHeight-160;
  const initial=!lastMessages.length;lastMessages=messages;if(clearConversation)clearConversation.hidden=!messages.length;conversation.replaceChildren();
  for(const entry of messages){const user=node('article',undefined,'message user');user.append(node('h2','You'),node('div',entry.user_text,'text'),node('div',entry.created_at_utc,'meta'));conversation.append(user);
    const assistant=node('article',undefined,'message assistant');latestAssistant=assistant;assistant.tabIndex=-1;assistant.append(assistantHeading());
    const reply=entry.reply;
    if(!reply){assistant.append(node('div','This request did not finish. It will not be repeated automatically. You may send a new request.','text'));conversation.append(assistant);continue;}
    const identity=assetHeader(reply);if(identity)assistant.append(identity);
    if(reply.coverage){
      const c=reply.coverage,banner=node('section',undefined,'scan-coverage');
      const complete=c.scanned_count===c.eligible_count && !(c.pending_count>0);
      const title=complete ? `${c.scanned_count} assets checked` : `${c.scanned_count} / ${c.eligible_count} assets checked${c.pending_count>0?' · '+c.pending_count+' pending':''}`;
      banner.append(node('div',title,'scan-coverage-title'));
      const d=c.discovery_summary;
      if(d){
        banner.append(node('div',`${d.matched_candidate_count} technical candidates · ${d.additional_review_count} additional review assets`,'scan-coverage-detail'));
        if(d.pool_count<10){
          const labels={source_older_than_24h:'API source candles over 24 hours old',source_timestamp_unavailable:'missing or invalid source timestamps',collection_or_report_delayed:'delayed collection or scan',core_indicators_unavailable:'insufficient or invalid core indicator data',no_matched_conditions:'no matched technical conditions',other:'other eligibility limitations'};
          const reasons=Object.entries(d.exclusion_counts??{}).filter(([,n])=>n>0).map(([key,n])=>`${n}: ${labels[key]??key}`);
          if(reasons.length)banner.append(node('div','Not included in current discovery: '+reasons.join('; ')+'.','scan-coverage-detail'));
        }
      }
      banner.append(node('div','Technical evidence only; fundamentals and execution conditions have not been assessed.','scan-coverage-detail'));
      assistant.append(banner);
    }
    // Facts appear before prose for detail/comparison; all values are text nodes, never model HTML.
    for(const t of reply.tables??[])assistant.append(table(t));assistant.append(reply.response_origin==='agent_research'?researchMarkdown(reply.text):node('div',reply.text,'text'));
    if(reply.cards?.length){const cards=node('div',undefined,'cards');for(const item of reply.cards){const card=node('div',undefined,'card'),heading=node('h3');heading.append(document.createTextNode(`${item.rank}. `),assetLink(item.ticker));if(typeof item.name==='string'&&item.name.trim())heading.append(document.createTextNode(` — ${item.name.trim()}`));card.append(heading,node('div',`Source provider: ${item.provider}`,'meta'));card.append(node('span',item.additional_review?'Additional review':'Technical candidate',item.additional_review?'candidate-badge review':'candidate-badge'));for(const reason of item.reasons)card.append(node('p',reason));card.append(controls([{action:'detail',ticker:item.ticker,label:`Analyze ${item.ticker}`}]),node('div',`Source candle completed UTC: ${item.source_candle_end_utc??'Unknown'}`,'meta'));cards.append(card);}assistant.append(cards);}
    assistant.append(controls(reply.actions,reply.retry_after_seconds??0,reply.retry_after_until_utc??null,reply.answered_at_utc??entry.created_at_utc,serverNowUtc));
    const count=reply.model_requests===null?'unknown':reply.model_requests??0;
    assistant.append(node('div',`Answered UTC: ${reply.answered_at_utc??entry.created_at_utc} · Model: ${reply.model_status??'not_called'} · Model requests: ${count} · Binance requests: ${reply.binance_http_requests??0} · Binance weight: ${reply.binance_request_weight??0}${reply.total_time_ms!==undefined?' · Time: '+reply.total_time_ms+' ms':''}${reply.db_time_ms!==undefined?' · DB: '+reply.db_time_ms+' ms':''}${reply.ranking_generated_at_utc?' · Evidence snapshot UTC: '+reply.ranking_generated_at_utc:''}`,'meta'));conversation.append(assistant);
  }
  if(!focusLatest&&(initial||nearBottom))window.scrollTo({top:document.documentElement.scrollHeight,behavior:'instant'});
  return latestAssistant;
}
function performUiAction(action){
  if(!action||typeof action!=='object')return false;
  if(action.type==='trade'&&['buy','sale'].includes(action.mode)){const ticker=typeof action.ticker==='string'&&/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(action.ticker)?action.ticker:undefined;window.HubbleTrading?.open(action.mode,ticker);return true;}
  if(action.type==='swap'){document.getElementById('nav-swap')?.click();return true;}
  if(action.type==='navigate'&&action.target==='dashboard'){location.href='./';return true;}
  return false;
}
function latestUiAction(messages){const reply=messages?.[messages.length-1]?.reply;return reply&&typeof reply==='object'?reply.ui_action:null;}
function setBusy(value){busy=value;document.querySelectorAll('.toolbar button,#composer button,#conversation button').forEach(b=>{if(value)b.disabled=true;else if(b.dataset.cooldownLocked!=='1')b.disabled=false;});message.disabled=value;statusBox.textContent=value?'Hubble is working…':'';}
async function load(){const r=await fetch('rwa-chat-api.php',{cache:'no-store',credentials:'same-origin'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error??'chat_unavailable');csrf=d.csrf;render(d.messages,{serverNowUtc:d.server_now_utc});statusBox.textContent='';}
function id(){return Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');}
async function send(action,ticker=null,text=''){
  if(busy||!csrf)return;if(action==='buy'){window.HubbleTrading?.open('buy',/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker??'')?ticker:undefined);return;}if(action==='sell'){window.HubbleTrading?.open('sale');return;}if(action==='swap'){document.getElementById('nav-swap')?.click();return;}if(action==='dashboard'){location.href='./';return;}if(action==='delete'&&!window.confirm('This will permanently delete the entire conversation history and start fresh. Continue?'))return;
  setBusy(true);
  let replyTarget=null,pendingUiAction=null;
  try{const r=await fetch('rwa-chat-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,request_id:id(),action,ticker,message:text})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error??'chat_unavailable');
    if(d.reset){message.value='';lastMessages=[];await load();}
    else{csrf=d.csrf;replyTarget=render(d.messages,{focusLatest:true,serverNowUtc:d.server_now_utc});pendingUiAction=latestUiAction(d.messages);message.value='';}
  }catch(e){statusBox.textContent=({'please_wait':'Please wait a moment before sending another message.','conversation_busy':'A request is already running in another tab. Reload in a moment.','conversation_full':'This conversation has reached its message limit. Clear the conversation to start fresh.','csrf_failed':'The conversation changed in another tab. Reload this page.'})[e.message]??'The connection was interrupted. Reload to check whether the reply was saved before sending again.';}
  finally{const notice=statusBox.textContent;setBusy(false);statusBox.textContent=notice.startsWith('Hubble is working')?'':notice;
    if(pendingUiAction&&performUiAction(pendingUiAction))return;
    if(replyTarget){replyTarget.focus({preventScroll:true});replyTarget.scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});}
    else message.focus({preventScroll:true});
  }
}
function consumeAssetHandoff(){
  const params=new URLSearchParams(location.search),action=params.get('action'),raw=(params.get('ticker')??'').toUpperCase();
  if(!['buy','detail'].includes(action)||! /^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(raw))return false;
  params.delete('action');params.delete('ticker');params.delete('source');
  const query=params.toString();history.replaceState(null,'',location.pathname+(query?'?'+query:'')+location.hash);
  if(action==='buy'){window.HubbleTrading?.open('buy',raw);return true;}
  send('detail',raw,`Analyze ${raw} in detail`);
  return true;
}
composer.addEventListener('submit',e=>{e.preventDefault();const text=message.value.trim();if(text)send('message',null,text);});
document.getElementById('discover').addEventListener('click',()=>send('discover',null,'Find RWA opportunities'));
clearConversation?.addEventListener('click',()=>send('delete',null,''));
load().then(()=>{if(consumeAssetHandoff())return;if(new URLSearchParams(location.search).get('mode')==='compare'){message.placeholder='Compare TSLA providers';statusBox.textContent='Enter an RWA ticker to compare its available providers.';message.focus();}}).catch(()=>{statusBox.textContent='The conversation is temporarily unavailable. Please try reloading.';});
