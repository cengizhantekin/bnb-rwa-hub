'use strict';
(()=>{
 const tabs=[...document.querySelectorAll('[data-dashboard-tab]')];
 const panels=new Map([...document.querySelectorAll('[data-dashboard-panel]')].map(p=>[p.dataset.dashboardPanel,p]));
 const status=document.getElementById('dashboard-refresh-status');
 const intervals={overview:60000,pulse:60000,opportunities:300000,providers:1800000,health:60000};
 const state=Object.fromEntries(Object.keys(intervals).map(k=>[k,{lastFetch:0,nextAt:0,revision:null,busy:false,error:false}]));
 let active='overview';
 const e=(tag,className,text)=>{const n=document.createElement(tag);if(className)n.className=className;if(text!==undefined)n.textContent=String(text);return n;};
 const validTicker=value=>typeof value==='string'&&/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(value);
 function assetLink(ticker,className='asset-ticker-link',text=ticker){if(!validTicker(ticker))return e('span',className,text||ticker||'—');const a=e('a',className,text||ticker);a.href=`asset.php?ticker=${encodeURIComponent(ticker)}`;a.title=`Open ${ticker} asset page`;return a;}
 const fmtInt=v=>Number.isFinite(Number(v))?new Intl.NumberFormat('en-US').format(Number(v)):'—';
 const fmtPct=v=>Number.isFinite(Number(v))?`${Number(v)>=0?'+':''}${Number(v).toFixed(2)}%`:'—';
 const fmtMoney=v=>{const n=Number(v);if(!Number.isFinite(n)||n<0)return'—';const abs=Math.abs(n);const units=[[1e12,'T'],[1e9,'B'],[1e6,'M'],[1e3,'K']];for(const [scale,suffix]of units)if(abs>=scale)return `$${(n/scale).toFixed(abs>=scale*100?0:abs>=scale*10?1:2)}${suffix}`;return `$${new Intl.NumberFormat('en-US',{maximumFractionDigits:2}).format(n)}`;};
 const provider=v=>v||'Unknown';
 const parseTime=v=>typeof v==='string'&&Number.isFinite(Date.parse(v))?Date.parse(v):null;
 const age=v=>{const t=parseTime(v);if(t===null)return'Unavailable';const s=Math.max(0,Math.floor((Date.now()-t)/1000));if(s<60)return`${s}s ago`;if(s<3600)return`${Math.floor(s/60)}m ago`;if(s<86400)return`${Math.floor(s/3600)}h ago`;return`${Math.floor(s/86400)}d ago`;};
 const clear=n=>n.replaceChildren();
 const safeLogo=value=>{if(typeof value!=='string'||value.length>1024)return null;try{const u=new URL(value);if(u.protocol!=='https:'||!['onchainos.bnbstatic.com','bin.bnbstatic.com','public.bnbstatic.com'].includes(u.hostname)||u.username||u.password||u.port||u.search||u.hash||!/^\/images\/web3-data\/public\/token\/logos\/[A-Za-z0-9._-]+\.(png|jpg|jpeg|webp|svg)$/i.test(u.pathname))return null;return u.href;}catch{return null;}};
 function head(title,copy){const wrap=e('div','dashboard-section-head'),left=e('div');left.append(e('h2','',title),e('p','',copy));wrap.append(left);return wrap;}
 function metric(label,value,small='',tone=''){const n=e('div',`metric-card${tone?` metric-${tone}`:''}`);n.append(e('span','',label),e('strong','',value));if(small)n.append(e('small','',small));return n;}
 function metricGrid(items){const g=e('div','metric-grid');for(const item of items)g.append(metric(...item));return g;}
 function card(title){const n=e('section','dashboard-card');n.append(e('h3','',title));return n;}
 function chip(text,cls='provider-chip'){return e('span',cls,text);}
 function row(title,sub,value,valueClass=''){const r=e('div','dash-row'),main=e('div','dash-main');main.append(e('div','dash-title',title));if(sub)main.append(e('div','dash-sub',sub));r.append(main,e('div',`dash-value ${valueClass}`.trim(),value));return r;}
 function timeSub(source,fetch){const parts=[];if(source)parts.push(`source ${age(source)}`);if(fetch)parts.push(`collector ${age(fetch)}`);return parts.join(' · ');}
 function signalLabel(code){return({trend_alignment:'Trend alignment',ma_pullback_recovery:'MA pullback recovery',support_recovery:'Support recovery',possible_range_breakout:'Range breakout',possible_breakout_retest:'Breakout retest'})[code]||code.replaceAll('_',' ');}
 function fallbackLogo(ticker){const f=e('span','asset-logo-fallback',String(ticker||'?').slice(0,2));f.setAttribute('aria-hidden','true');return f;}
 function assetLogo(asset){const wrap=e('span','asset-logo-shell'),url=safeLogo(asset?.logo_url);if(!url){wrap.append(fallbackLogo(asset?.ticker));return wrap;}const img=document.createElement('img');img.className='asset-logo';img.src=url;img.alt='';img.loading='lazy';img.decoding='async';img.addEventListener('error',()=>wrap.replaceChildren(fallbackLogo(asset?.ticker)),{once:true});wrap.append(img);return wrap;}
 function assetMain(asset,sub,underlying=''){const main=e('div','dash-main asset-main'),copy=e('div','asset-copy'),title=e('div','dash-title');title.append(assetLink(asset.ticker));if(asset.name)title.append(document.createTextNode(` · ${asset.name}`));copy.append(title);if(sub)copy.append(e('div','dash-sub',sub));if(underlying)copy.append(e('div','dash-sub underlying-metrics',underlying));main.append(assetLogo(asset),copy);return main;}
 function assetRow(asset,sub,value,valueClass='',underlying=''){const r=e('div','dash-row asset-row');r.append(assetMain(asset,sub,underlying),e('div',`dash-value ${valueClass}`.trim(),value));return r;}
 function formatRemaining(ms){const sec=Math.max(0,Math.ceil(ms/1000));if(sec>=60){const m=Math.floor(sec/60),s=sec%60;return `${m}m ${s}s`;}return `${sec}s`;}
 function refreshStatus(){const s=state[active];if(!status)return;if(s.busy){status.textContent='Refreshing…';return;}if(!s.nextAt){status.textContent='Loading dashboard…';return;}status.textContent=`Refreshing in ${formatRemaining(s.nextAt-Date.now())}`;}
 function errorPanel(panel){clear(panel);panel.append(e('div','dashboard-error','Saved dashboard data is temporarily unavailable.'));}
 function renderOverview(d,panel){
  clear(panel);panel.append(head('Overview','Coverage and the latest saved-system timestamps.'));
  const complete=d.technical_scanned!==null&&d.technical_eligible!==null&&Number(d.technical_scanned)===Number(d.technical_eligible);
  const scanValue=d.technical_scanned===null?'—':complete?fmtInt(d.technical_scanned):d.technical_eligible===null?fmtInt(d.technical_scanned):`${fmtInt(d.technical_scanned)} / ${fmtInt(d.technical_eligible)}`;
  const scanSmall=d.technical_scanned===null?'Watchlist unavailable':complete?'Assets checked':'Assets checked / eligible';
  panel.append(metricGrid([
   ['Active BSC token records',fmtInt(d.token_records),'bStocks · Ondo · xStocks'],
   ['Underlying assets',fmtInt(d.underlying_assets),'Distinct underlying tickers'],
   ['Providers',fmtInt(d.providers),'Active provider families'],
   ['Technical candidates',d.technical_candidates===null?'—':fmtInt(d.technical_candidates),'Saved daily-candle watchlist'],
   ['Technical scan',scanValue,scanSmall]
  ]));
  const grid=e('div','dashboard-grid two'),fetch=card('Latest collector fetches'),slow=card('Slower-moving datasets');
  fetch.append(row('General market snapshot','Collector fetch time',age(d.market_fetch_utc)),row('RWA price snapshot','Collector fetch time',age(d.rwa_fetch_utc)));
  slow.append(row('Token catalogue','Last active-record refresh',age(d.catalogue_seen_utc)),row('Technical watchlist','Report generation time',age(d.technical_generated_utc)));
  grid.append(fetch,slow);panel.append(grid);panel.append(e('p','dashboard-note','Collector fetch time and source price time are different concepts. Open Data Health to inspect source-age distributions.'));
 }
 function moverList(title,items){const c=card(title),list=e('div','dash-list');if(!items?.length)list.append(e('div','empty-state','No qualifying saved observations are available.'));else for(const x of items){const tone=x.change_24h>0?'up':x.change_24h<0?'down':'neutral';list.append(assetRow(x,`${provider(x.provider_label)} · ${x.symbol||''} · ${timeSub(x.source_utc,x.fetch_utc)}`,fmtPct(x.change_24h),tone));}c.append(list);return c;}
 function underlyingRankList(title,items,key){const c=card(title),list=e('div','dash-list');if(!items?.length)list.append(e('div','empty-state','No saved underlying market values are available.'));else for(const x of items)list.append(assetRow(x,`Source: ${provider(x.provider_label)} · ${x.symbol||''}`,fmtMoney(x[key])));c.append(list);return c;}
 /** Contract-specific Binance Most Held snapshot, served by local DB only. */
 async function loadBinanceStockRank(container){
  const target=e('div','stock-rank-content','Loading saved holder rankings…');
  container.append(target);
  try{
   const response=await fetch('dashboard-stock-rank-api.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
   const payload=await response.json();
   if(!response.ok||payload?.ok!==true||payload.chain_id!=='56'||payload.rank_type!==40||payload.sort_by!==30||!Array.isArray(payload.items))throw Error('rank_unavailable');
   if(!container.isConnected)return;
   clear(target);
   if(!payload.available){target.append(e('div','empty-state','Saved Binance holder rankings are unavailable. The next collector run will retry.'));}
   else{
    const list=e('div','stock-rank-list');
    for(const item of payload.items.slice(0,10)){
     if(!Number.isInteger(item.position)||item.position<1||typeof item.symbol!=='string'||item.symbol.length>40)continue;
     const r=e('div','stock-rank-row'),identity=e('div','stock-rank-identity');
     identity.append(e('span','stock-rank-position',`#${item.position}`));
     const rankLogo=assetLogo({ticker:validTicker(item.matched_ticker)?item.matched_ticker:item.symbol,logo_url:item.logo_url});
     rankLogo.classList.add('stock-rank-logo');
     identity.append(rankLogo);
     const label=e('div','stock-rank-name'),matched=validTicker(item.matched_ticker)?item.matched_ticker:null;
     if(matched)label.append(assetLink(matched,'asset-ticker-link stock-rank-ticker',item.symbol));
     else label.append(e('strong','stock-rank-ticker',item.symbol));
     label.append(e('div','dash-sub',matched?`${item.provider||'Tracked provider'} · ${matched}`:'Token not matched in local catalogue'));
     identity.append(label);
     const holders=(Number.isSafeInteger(item.holders)&&item.holders>=0)?fmtInt(item.holders):'—';
     const holderLabel=e('span','stock-rank-holder',holders==='—'?'—':`${holders} holders`);
     holderLabel.title='Binance-reported holders for this individual BSC token contract';
     holderLabel.setAttribute('aria-label',`Token holders: ${holders}`);
     r.append(identity,holderLabel);list.append(r);
    }
    if(list.children.length)target.append(list);
    else target.append(e('div','empty-state','No valid saved token identities were returned.'));
   }
   const metadata=e('div','stock-rank-meta');
   metadata.append(e('span','',`Binance Wallet Skill data · ${payload.total??'—'} ranked tokens · collected ${age(payload.fetched_at_utc)}${payload.stale?' · STALE SNAPSHOT':''}`));
   metadata.append(e('span','',`Saved DB snapshot · Last collector: ${payload.last_collector_http_requests??'—'} HTTP requests · ${payload.last_collector_duration_ms??'—'} ms · Weight ${payload.last_collector_request_weight??'unknown'}`));
   target.append(metadata);
  }catch{
   if(container.isConnected)target.replaceChildren(e('div','empty-state','Saved Binance stock holder data could not be loaded. Existing Market Pulse rankings are unaffected.'));
  }
 }
 function renderPulse(d,panel){
  clear(panel);panel.append(head('Market Pulse','Representative-provider 24h movement from saved observations.'));
  const medianTone=d.median_24h>0?'up':d.median_24h<0?'down':'neutral';
  panel.append(metricGrid([
   ['Usable underlying assets',fmtInt(d.usable_assets),'Source timestamp ≤ 24h'],
   ['Advancing',fmtInt(d.advancing),'24h change > 0','up'],
   ['Declining',fmtInt(d.declining),'24h change < 0','down'],
   ['Median 24h move',d.median_24h===null?'—':fmtPct(d.median_24h),'Across usable assets',medianTone]
  ]));
  const grid=e('div','dashboard-grid two');grid.append(moverList('Top 5 gainers',d.gainers),moverList('Top 5 losers',d.losers));panel.append(grid);
  const underlyingGrid=e('div','dashboard-grid two market-rank-grid');underlyingGrid.append(underlyingRankList('Top 5 underlying 24h volume',d.top_underlying_volume_24h,'underlying_volume_24h'),underlyingRankList('Top 5 underlying market cap',d.top_underlying_market_cap,'underlying_market_cap'));panel.append(underlyingGrid);
  const external=e('section','dashboard-card stock-rank-card');
  const rankHeading=e('div','stock-rank-heading');
  rankHeading.append(e('h3','','Most Held Stock Tokens'),e('span','stock-rank-provider','Binance Wallet Skill'));
  external.append(rankHeading);
  external.append(e('p','stock-rank-caption','Binance-reported token-contract holders, ranked highest first. Provider tokens are kept separate; holder counts are not unique people or an underlying-level aggregate. Collected in a shared read-only background snapshot.'));
  panel.append(external);
  void loadBinanceStockRank(external);
  panel.append(e('p','dashboard-note',d.freshness_rule||'Only sufficiently recent saved source observations are ranked.'));
 }
 function opportunityCard(x){
  const r=e('article','opportunity-row'),top=e('div','opportunity-top'),main=e('div','opportunity-asset'),copy=e('div','opportunity-copy');
  const title=e('div','opportunity-title');title.append(assetLink(x.ticker,'opportunity-symbol asset-ticker-link'));if(x.name){title.append(document.createTextNode(' · '),e('span','opportunity-company',x.name));}copy.append(title,chip(`${provider(x.provider_label)} · ${x.symbol||x.ticker}`));main.append(assetLogo(x),copy);top.append(main);r.append(top);
  const sig=e('div','opportunity-signals');for(const s of x.signals||[])sig.append(chip(signalLabel(s),'signal-chip'));if((x.signals||[]).length)r.append(sig);
  const meta=[];if(x.rsi14!==null)meta.push(`RSI14 ${Number(x.rsi14).toFixed(1)}`);if(x.close!==null)meta.push(`close ${x.close}`);if(x.source_candle_open_utc)meta.push(`candle ${age(x.source_candle_open_utc)}`);if(x.api_fetch_utc)meta.push(`API fetch ${age(x.api_fetch_utc)}`);r.append(e('div','opportunity-meta',meta.join(' · ')));return r;
 }
 function renderOpportunities(d,panel){
  clear(panel);panel.append(head('Opportunity Radar','Deterministic technical candidates from the saved daily-candle watchlist.'));
  if(!d.available){panel.append(e('div','dashboard-error',d.message||'Technical watchlist unavailable.'));return;}
  panel.append(metricGrid([
   ['Technical candidates',fmtInt(d.candidate_count),'Rule-based technical candidates'],
   ['Assets scanned',fmtInt(d.scanned_count),d.scope_complete?'Scope complete':'Scope still filling'],
   ['Data unavailable',fmtInt(d.data_unavailable_count),'Insufficient or invalid technical evidence']
  ]));
  const c=card('Current technical candidates');
  if(!d.candidates?.length)c.append(e('div','empty-state','No technical candidates are in the saved watchlist.'));
  else{const list=e('div','opportunity-list-grid');for(const x of d.candidates)list.append(opportunityCard(x));c.append(list);}
  const ask=e('a','dashboard-cta','Ask Hubble about all opportunities');ask.href='rwa-agent.php';c.append(ask);panel.append(c);
  panel.append(e('p','dashboard-note',`Technical report generated ${age(d.generated_at_utc)}. These are saved rule-based signals; Hubble analysis and an executable quote are separate checks. Weekend note: candidate counts can be lower when underlying equity markets are closed and fewer fresh daily candles are available.`));
 }
 function coverageBar(label,count,total){const n=e('div','coverage-item'),track=e('div','coverage-track'),fill=e('div','coverage-fill');fill.style.width=`${total>0?Math.max(0,Math.min(100,count/total*100)):0}%`;track.append(fill);n.append(e('span','coverage-label',label),track,e('span','coverage-count',fmtInt(count)));return n;}
 function renderProviders(d,panel){
  clear(panel);panel.append(head('Provider Coverage','Active BNB Smart Chain catalogue coverage by token provider.'));
  const cards=e('div','dashboard-grid two'),providerCard=card('Provider footprint'),distCard=card('Underlying provider choice'),list=e('div','dash-list');
  for(const x of d.providers||[])list.append(row(x.label,`Last catalogue observation ${age(x.last_seen_utc)}`,`${fmtInt(x.token_records)} tokens`));providerCard.append(list);
  const bars=e('div','coverage-bar');bars.append(coverageBar('1 provider',Number(d.underlying_distribution?.one_provider||0),Number(d.total_underlyings||0)),coverageBar('2 providers',Number(d.underlying_distribution?.two_providers||0),Number(d.total_underlyings||0)),coverageBar('3 providers',Number(d.underlying_distribution?.three_providers||0),Number(d.total_underlyings||0)));distCard.append(bars,e('p','',`${fmtInt(d.total_underlyings)} distinct underlying assets across the active catalogue.`));cards.append(providerCard,distCard);panel.append(cards);
 }
 function buckets(title,data){const c=card(title),g=e('div','health-buckets');const items=[['<15m','under_15m'],['15m–2h','15m_to_2h'],['2–24h','2h_to_24h'],['>24h','over_24h'],['Missing','missing']];for(const [label,key]of items){const b=e('div','health-bucket');b.append(e('strong','',fmtInt(data?.[key]||0)),e('span','',label));g.append(b);}c.append(g);return c;}
 function renderHealth(d,panel){
  clear(panel);panel.append(head('Data Health','Collector activity and source timestamps are shown separately.'));
  panel.append(metricGrid([
   ['Active token records',fmtInt(d.total_records),'Coverage denominator'],
   ['General market fresh <10m',fmtInt(d.general_fetch_10m),`${fmtInt(d.general_fetch_missing)} missing fetch times`],
   ['RWA price fresh <10m',fmtInt(d.rwa_fetch_10m),`${fmtInt(d.rwa_fetch_missing)} missing fetch times`]
  ]));
  const timeGrid=e('div','dashboard-grid two'),collector=card('Collector timestamps'),technical=card('Technical dataset');
  collector.append(
   row('General market collector','Latest DB fetch',age(d.market_fetch_latest)),
   row('RWA price collector','Latest DB fetch',age(d.rwa_fetch_latest)),
   row('Catalogue','Last active-record observation',age(d.catalogue_seen_utc)),
   row('Oldest active market fetch','Across active tokens',age(d.market_fetch_oldest))
  );
  if(d.technical)technical.append(
   row('Watchlist generated','Saved report',age(d.technical.generated_at_utc)),
   row('Coverage',d.technical.scope_complete?'Complete':'Still filling',`${fmtInt(d.technical.scanned_count)}/${fmtInt(d.technical.eligible_count)}`),
   row('Technical candidates','Saved technical state',fmtInt(d.technical.candidate_count)),
   row('Technical data unavailable','Saved technical state',fmtInt(d.technical.data_unavailable_count))
  );else technical.append(e('div','empty-state','Technical watchlist unavailable.'));
  timeGrid.append(collector,technical);panel.append(timeGrid);
  const source=e('div','health-grid');source.append(buckets('General market source price age',d.market_source_age),buckets('RWA token/reference source age',d.rwa_source_age));panel.append(source);panel.append(e('p','dashboard-note','An API fetch can be recent while the source price is older, for example outside an underlying market session. Source age is therefore shown as an observation, not automatically as an error.'));
 }
 const renderers={overview:renderOverview,pulse:renderPulse,opportunities:renderOpportunities,providers:renderProviders,health:renderHealth};
 async function load(section,force=false){
  const s=state[section],now=Date.now();if(s.busy)return;if(!force&&s.nextAt&&now<s.nextAt){if(section===active)refreshStatus();return;}
  s.busy=true;s.error=false;if(section===active)refreshStatus();
  try{
   const response=await fetch(`dashboard-api.php?section=${encodeURIComponent(section)}`,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),payload=await response.json();
   if(!response.ok||payload?.ok!==true||payload.section!==section||!payload.data)throw Error('dashboard_unavailable');
   s.lastFetch=Date.now();s.nextAt=s.lastFetch+intervals[section];s.revision=payload.revision;renderers[section](payload.data,panels.get(section));panels.get(section).dataset.rendered='1';
  }catch{
   s.error=true;s.nextAt=Date.now()+Math.min(intervals[section],60000);if(!panels.get(section).dataset.rendered)errorPanel(panels.get(section));
  }finally{s.busy=false;if(section===active)refreshStatus();}
 }
 function activate(section,pushHash=true){
  if(!panels.has(section))return;active=section;
  for(const tab of tabs){const on=tab.dataset.dashboardTab===section;tab.classList.toggle('active',on);tab.setAttribute('aria-selected',on?'true':'false');tab.tabIndex=on?0:-1;}
  for(const [key,panel]of panels)panel.hidden=key!==section;
  if(pushHash&&history.replaceState)history.replaceState(null,'',`#${section}`);
  load(section,false);refreshStatus();
 }
 for(const tab of tabs){
  tab.addEventListener('click',()=>activate(tab.dataset.dashboardTab));
  tab.addEventListener('keydown',ev=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(ev.key))return;ev.preventDefault();const i=tabs.indexOf(tab);const n=ev.key==='Home'?0:ev.key==='End'?tabs.length-1:ev.key==='ArrowRight'?(i+1)%tabs.length:(i-1+tabs.length)%tabs.length;tabs[n].focus();activate(tabs[n].dataset.dashboardTab);});
 }
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)load(active,false);});
 setInterval(()=>{if(document.hidden)return;const s=state[active];if(!s.busy&&(!s.nextAt||Date.now()>=s.nextAt))load(active,false);else refreshStatus();},1000);
 const requested=location.hash.slice(1);activate(panels.has(requested)?requested:'overview',false);
})();
