/** Conversation logic with injected, read-only data and model adapters. No wallet tools. */
type Row = Record<string, any>;
export type ChatInput = { message: string; action: string; ticker: string | null; state: Row; context?: {role:string;text:string}[] };
export type Dependencies = {
  pool: () => Promise<Row>;
  snapshot: (ticker: string) => Promise<Row>;
  technical: (ticker: string) => Promise<Row>;
  research?: (plan: Row) => Promise<Row>;
  ask: (purpose: string, data: Row, signal?: AbortSignal) => Promise<string>;
};
const SYMBOL = /^[A-Z0-9][A-Z0-9._-]{0,15}$/;
const CODES: Record<string, string> = {
  trend_alignment: "The recorded close is above SMA20 and SMA50, with the moving averages aligned and RSI in the checked trend range.",
  ma_pullback_recovery: "The close is near SMA20 and above SMA50, with RSI rising from its previous observed reading. This supports a possible pullback recovery.",
  support_recovery: "The close is rising near a repeated support zone, with RSI in the checked recovery range.",
  possible_range_breakout: "The recorded close exceeds the earlier range high under the checked breakout conditions. Confirmation is still needed.",
  possible_breakout_retest: "The checked sequence shows an earlier range breakout followed by a possible retest holding above that level.",
  additional_review: "Core indicators are available, but none of the configured opportunity conditions matched. This is an additional review candidate.",
};
const obj = (x: unknown): x is Row => !!x && typeof x === 'object' && !Array.isArray(x);
export function parseModelJson(text: string): Row {
  let s = text.trim();
  const end = s.lastIndexOf('</think>');
  if (end >= 0) s = s.slice(end + 8).trim();
  s = s.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
  const out: unknown = JSON.parse(s);
  if (!obj(out)) throw new Error('invalid_model_output');
  return out;
}
export function evidenceCodes(r: Row): string[] {
  return r.signals.length ? r.signals.map((s: Row) => s.code) : ['additional_review'];
}
export function validateRanking(value: Row, records: Row[], expectedCount: number = records.length): Row[] {
  if (!Array.isArray(value.ranking) || value.ranking.length !== expectedCount || expectedCount > records.length || expectedCount < 0) throw new Error('incomplete_ranking');
  const source = new Map(records.map(r => [r.ticker, r]));
  const seen = new Set<string>();
  return value.ranking.map((item: unknown) => {
    if (!obj(item) || typeof item.ticker !== 'string' || seen.has(item.ticker) || !source.has(item.ticker)) throw new Error('invalid_rank_ticker');
    const r = source.get(item.ticker)!;
    if (!Array.isArray(item.reasons) || !item.reasons.length || item.reasons.length > 3
      || new Set(item.reasons).size !== item.reasons.length
      || !item.reasons.every((c: unknown) => typeof c === 'string' && evidenceCodes(r).includes(c))) throw new Error('unsupported_rank_reason');
    seen.add(item.ticker);
    return { ticker: item.ticker, reasons: item.reasons };
  });
}
export function validState(state: unknown): state is Row {
  if (!obj(state) || Object.keys(state).length > 13) return false;
  if (state.analyzed_tickers !== undefined && (!Array.isArray(state.analyzed_tickers) || state.analyzed_tickers.length>1000 || !state.analyzed_tickers.every((t:unknown)=>typeof t==='string' && SYMBOL.test(t)))) return false;
  if (state.next_rank_at_utc !== undefined && (typeof state.next_rank_at_utc!=='string' || !Number.isFinite(Date.parse(state.next_rank_at_utc)))) return false;
  if (state.batch_size !== undefined && (!Number.isInteger(state.batch_size) || state.batch_size < 1 || state.batch_size > 10)) return false;
  if (!Object.keys(state).length) return true;
  if (!Array.isArray(state.pool) || state.pool.length > 1000 || !Array.isArray(state.ranking)
    || !Number.isInteger(state.offset) || state.offset < 0 || state.offset > state.ranking.length
    || typeof state.generated_at_utc !== 'string' || !Number.isFinite(Date.parse(state.generated_at_utc))) return false;
  for (const r of state.pool) {
    if (!obj(r) || !SYMBOL.test(r.ticker) || !obj(r.indicators) || !obj(r.availability) || !Array.isArray(r.signals)) return false;
  }
  try { validateRanking({ ranking: state.ranking }, state.pool, state.ranking.length); } catch { return false; }
  return state.selected === null || (typeof state.selected === 'string' && SYMBOL.test(state.selected));
}
export function validateInput(input: unknown): input is ChatInput {
  if (!obj(input) || Object.keys(input).some(k => !['message','action','ticker','state','context'].includes(k))
    || typeof input.message !== 'string' || input.message.length > 2000
    || !['message','discover','more','detail','compare','refresh','buy','sell','swap','dashboard','help'].includes(input.action)
    || !(input.ticker === null || (typeof input.ticker === 'string' && SYMBOL.test(input.ticker))) || !validState(input.state)) return false;
  if(input.context!==undefined && (!Array.isArray(input.context)||input.context.length>6||input.context.some((m:unknown)=>!obj(m)||Object.keys(m).some(k=>!['role','text'].includes(k))||!['user','assistant'].includes(m.role)||typeof m.text!=='string'||m.text.length>600)))return false;
  return input.action !== 'message' || input.message.trim().length > 0;
}
function frame(state: Row, text: string, extra: Row = {}): Row {
  return { ok: true, state, text, cards: [], tables: [], actions: [], model_status: 'not_called', ...extra };
}
/** A compact ledger gives model-written paragraphs explicit source references. */
export function analysisEvidence(r: Row): Row {
  const facts: Row = {
    close: { value:r.close, meaning:'Latest recorded completed daily token close' },
    source: { value:r.source_candle_end_utc, meaning:'Source candle completed UTC' },
    execution: { value:false, meaning:'Executable quotes, fees and slippage have not been checked' },
    normalization: { value:false, meaning:'Independent stock-price normalization is not verified' },
  };
  if(r.data_quality) facts.data_quality={value:r.data_quality,meaning:'Source candle age, API collection age and report age are separate; a newer report or fetch does not make an old price current'};
  for (const [name,value] of Object.entries(r.indicators)) {
    if(value !== null && value !== undefined && r.availability[name]?.status === 'available')
      facts[name] = {value,meaning:'Saved calculated indicator'};
  }
  for (const s of r.signals) facts['setup:'+s.code] = {value:s.evidence,meaning:'Observed rule inputs; no probability or software score'};
  if(facts.RSI14) facts.RSI14.meaning += '; conventional interpretation levels: midpoint 50, lower band 30, upper band 70. These are reference thresholds, not recorded readings or price targets.';
  if(Array.isArray(r.levels)) r.levels.forEach((l:Row,i:number)=>{facts['zone:'+i]={value:l,meaning:'Detected repeated price zone'};});
  const unavailable=Object.fromEntries(Object.entries(r.availability).filter(([,a]:[string,any])=>a.status!=='available'));
  if(Object.keys(unavailable).length) facts.limitations={value:unavailable,meaning:'Unavailable or undetected features and source reasons'};
  if(r.additional_review) facts.additional_review={value:true,meaning:'No configured opportunity setup matched'};
  return facts;
}
/** Response identity and shape only. Prose numbers are not validated or rewritten. */
export function validateAnalysis(value: Row, ticker:string, _facts?:Row): string {
  const conversational=Object.prototype.hasOwnProperty.call(value,'answer');
  const fields=conversational?['answer']:['thesis','risks','monitor'];
  if(value.ticker!==ticker || Object.keys(value).some(k=>!['ticker',...fields].includes(k))) throw new Error('invalid_analysis_identity');
  const paragraphs:string[]=[];
  for(const name of fields){
    const raw=value[name];
    const paragraph=typeof raw==='string'?raw:obj(raw)?raw.text:undefined;
    const fail=(code:string):never=>{throw Object.assign(new Error(code),{analysis_section:name});};
    if(typeof paragraph!=='string')fail('analysis_section_missing');
    const part={text:paragraph as string};
    if(part.text.trim().length<20)fail('analysis_section_too_short');
    if(part.text.length>1400)fail('analysis_section_too_long');
    if(/[<>]|\b(?:system prompt|chain.of.thought)\b/i.test(part.text))fail('analysis_unsafe_text');
    paragraphs.push(part.text.trim());
  }
  const text=paragraphs.join('\n\n');
  if(text.length<(conversational?20:100))throw new Error('analysis_too_short');
  if(text.length>3500)throw new Error('analysis_too_long');
  return text;
}
function technicalTable(r: Row): Row {
  return { title: `${r.ticker}: saved daily evidence`, columns: ['Metric','Value','Availability at saved check'],
    rows: [['Latest recorded daily close', r.close ?? 'Unknown', 'Recorded'],
      ...Object.entries(r.indicators).map(([k,v]) => [k, v ?? 'Unknown',
        r.availability[k]?.status === 'available' ? 'Available' : (r.availability[k]?.reasons ?? ['Unavailable']).join(' ')]),
      ['Source candle completed UTC', r.source_candle_end_utc ?? 'Unknown', 'Recorded']], numeric_columns: [1] };
}
/** Explicit asset identity overrides conversational carry-over, never numerical analysis. */
export function explicitTickers(message:string, state:Row):string[] {
  const ignored=new Set(['HUBBLE','RWA','API','RFQ','RSI','SMA','EMA','MA','UTC','USD','USDT','USDC','THE','WHAT','WHY','HOW','CAN','YOU','PLEASE','TOKEN','TOKENS','STOCK','STOCKS','ETF','ETFS','ASSET','ASSETS','ONDO','BSTOCKS','XSTOCKS','COMPARE','ANALYZE','BUY','SELL','SWAP','SHOW','MORE','OPEN','SCREEN','PAGE','FORM','FLOW','WALLET','DASHBOARD','HOME','TRADE','TRADING','PURCHASE','SALE','BUTTON','IF','IS','IT','AND','OR','FOR','TO','OF','IN','ON','OK','HI','HELLO','HELP','THIS','THAT','MY','YOUR','ITS','RISK','RISKS','SETUP','SUPPORT','RESISTANCE','PRICE','HOLDERS','CONFIRMATION','MOMENTUM','THANKS','YES','NO']);
  const known=new Set((state.pool??[]).map((r:Row)=>r.ticker));
  const found=new Set<string>();
  const exact=message.trim().replace(/^\$/,'').toUpperCase();
  if(SYMBOL.test(exact)&&(!ignored.has(exact)||known.has(exact))&&!/^(?:SMA|EMA|MA|RSI)\d+$/.test(exact))found.add(exact);
  for(const match of message.matchAll(/(?<![\p{L}\p{N}._-])\$?([A-Za-z][A-Za-z0-9]*(?:[.-][A-Za-z0-9]+)?)(?![\p{L}\p{N}._-])/gu)){
    const raw=match[1]!, upper=raw.toUpperCase();
    if(!SYMBOL.test(upper)||/^(?:SMA|EMA|MA|RSI)\d+$/.test(upper))continue;
    const before=message.slice(Math.max(0,match.index!-30),match.index);
    const after=message.slice(match.index!+match[0].length,match.index!+match[0].length+30);
    const addressed=match[0].startsWith('$')||/\b(?:analyze|analyse|compare|buy|sell|ticker)\s*$/i.test(before)
      || /^\s+(?:token(?:i|in|inin)?\b|analiz\w*\b|providers?\b)/i.test(after);
    if((raw===upper && raw.length>=2 && (!ignored.has(upper)||known.has(upper)))
      || (addressed && (raw===raw.toLowerCase()||match[0].startsWith('$')) && (!ignored.has(upper)||known.has(upper))))found.add(upper);
  }
  return [...found];
}
/** Route explicit informational research before the intent model can select opportunity discovery.
 * Model-selected read-only research plans and model-authored answers are unchanged.
 * Trading commands, opportunity discovery and legacy single-ticker flows remain distinct. */
export function isClearResearchRequest(message:string, tickers:string[]):boolean {
  const text=message.trim();
  // Multi-asset trading/navigation instructions must never become research or wallet actions.
  if (/\b(?:buy|sell|swap|purchase|checkout|approve|approval)\b/i.test(text)) return false;
  // Technical opportunity discovery retains its existing ranked cards and Show more sequence.
  if (/\b(?:opportunit(?:y|ies)|candidates?|setups?)\b|\bf[ıi]rsat(?:lar)?\b/i.test(text)) return false;
  if (tickers.length >= 2) return true;
  const marketMetric=/\b(?:market\s*cap(?:italization)?|volume|24\s*h(?:our)?|24h|gainers?|losers?|biggest|largest|smallest|highest|lowest|top|bottom|rank(?:ing|ed)?|rsi|perform(?:ance|ers?)|valuations?)\b/i.test(text);
  const relevantSubject=/\b(?:stocks?|etfs?|assets?|shares?|securities|tokenized|rwa|market|companies|underlyings?|providers?|ondo|bstocks?|xstocks?)\b/i.test(text);
  const filteredListing=/\b(?:show|list|find|which|available|display)\b.*\b(?:stocks?|etfs?|assets?|shares?|securities)\b/i.test(text);
  const dataHealth=/\b(?:fresh(?:ness)?|stale|outdated|data\s*health|last\s*(?:fetch|update)|recently\s*(?:fetched|updated)|data\s*age)\b/i.test(text)
    && /\b(?:data|fetch|update|market|rwa|assets?|prices?|hub|collector|sources?)\b/i.test(text);
  return dataHealth || (marketMetric && relevantSubject) || filteredListing;
}
export function requestedOpportunityCount(message:string):number|null {
  const normalized=message.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'').replace(/ı/g,'i');
  const words:Record<string,number>={one:1,two:2,three:3,four:4,five:5,six:6,seven:7,eight:8,nine:9,ten:10,bir:1,iki:2,uc:3,dort:4,bes:5,alti:6,yedi:7,sekiz:8,dokuz:9,on:10};
  const token='(?:10|[1-9]|one|two|three|four|five|six|seven|eight|nine|ten|bir|iki|uc|dort|bes|alti|yedi|sekiz|dokuz|on)';
  const patterns=[
    new RegExp(`\\b(?:top|find|show(?:\\s+me)?|give(?:\\s+me)?|list|want|need|bul|goster|listele|istiyorum)\\s+(${token})\\b`,'i'),
    new RegExp(`\\b(${token})\\s+(?:tane\\s+)?(?:rwa\\s+)?(?:opportunit(?:y|ies)|candidate(?:s)?|asset(?:s)?|token(?:s)?|coin(?:s)?|firsat(?:\\s+coin\\w*)?|aday\\w*)\\b`,'i'),
    new RegExp(`\\b(${token})\\s+more\\b`,'i'),
    new RegExp(`\\b(${token})\\s+(?:tane\\s+)?daha\\b`,'i'),
  ];
  for(const pattern of patterns){
    const match=pattern.exec(normalized);if(!match)continue;
    const raw=match[1]!.toLowerCase(),value=/^\d+$/.test(raw)?Number(raw):words[raw];
    if(Number.isInteger(value)&&value>=1&&value<=10)return value;
  }
  return null;
}

function directAppIntent(message:string):{action:string;ticker:string|null}|null {
  const text=message.trim(),normalized=text.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'').replace(/ı/g,'i');
  let m:RegExpExecArray|null;
  if(requestedOpportunityCount(text)!==null&&/\b(?:more|daha)\b/.test(normalized))return{action:'more',ticker:null};
  if(requestedOpportunityCount(text)!==null&&/\b(?:opportunit(?:y|ies)|candidate(?:s)?|firsat\w*|aday\w*)\b/.test(normalized))return{action:'discover',ticker:null};
  if(/^find(?:\s+rwa)?\s+opportunit(?:y|ies)$/i.test(text))return{action:'discover',ticker:null};
  if(/^(?:show|see)\s+more$/i.test(text))return{action:'more',ticker:null};
  if((m=/^(?:analyze|analyse)\s+\$?([A-Za-z][A-Za-z0-9._-]{0,15})$/i.exec(text)))return{action:'detail',ticker:m[1]!.toUpperCase()};
  if((m=/^compare\s+\$?([A-Za-z][A-Za-z0-9._-]{0,15})(?:\s+providers?)?$/i.exec(text)))return{action:'compare',ticker:m[1]!.toUpperCase()};
  if((m=/^(?:refresh|update)\s+(?:analysis\s+(?:for\s+)?|)(?:\$)?([A-Za-z][A-Za-z0-9._-]{0,15})$/i.exec(text)))return{action:'refresh',ticker:m[1]!.toUpperCase()};
  if(/^(?:buy|purchase)(?:\s+rwa)?\s+tokens?$/i.test(text)||/^(?:open|start)\s+(?:the\s+)?buy(?:\s+(?:screen|flow))?$/i.test(text))return{action:'buy_open',ticker:null};
  if((m=/^(?:buy|purchase)\s+\$?([A-Za-z][A-Za-z0-9._-]{0,15})$/i.exec(text)))return{action:'buy_open',ticker:m[1]!.toUpperCase()};
  if((m=/^(?:open|start)\s+(?:the\s+)?buy(?:\s+(?:screen|flow))?\s+(?:for\s+)?\$?([A-Za-z][A-Za-z0-9._-]{0,15})$/i.exec(text)))return{action:'buy_open',ticker:m[1]!.toUpperCase()};
  if(/^(?:sell)(?:\s+rwa)?\s+tokens?$/i.test(text)||/^(?:open|start)\s+(?:the\s+)?sell(?:\s+(?:screen|flow))?$/i.test(text))return{action:'sell',ticker:null};
  if((m=/^sell\s+(?:my\s+)?\$?([A-Za-z][A-Za-z0-9._-]{0,15})$/i.exec(text)))return{action:'sell',ticker:m[1]!.toUpperCase()};
  if(/^(?:swap|open\s+(?:the\s+)?swap(?:\s+(?:screen|flow))?)$/i.test(text))return{action:'swap',ticker:null};
  if(/^(?:dashboard|home|open\s+(?:the\s+)?(?:dashboard|home)|go\s+to\s+(?:the\s+)?(?:dashboard|home))$/i.test(text))return{action:'dashboard',ticker:null};
  if(/^(?:help|what\s+can\s+i\s+ask\??)$/i.test(text))return{action:'help',ticker:null};
  return null;
}
export async function handleChat(input: ChatInput, deps: Dependencies, signal?: AbortSignal): Promise<Row> {
  const started = performance.now();
  let state = input.state;
  let action = input.action;
  let ticker = input.action==='message'?null:input.ticker ?? state.selected ?? null;
  const requestedCount=requestedOpportunityCount(input.message);
  const context=(input.context??[]).slice(-6).map(m=>({role:m.role,text:m.text.slice(0,600)}));
  let calls = 0;
  const ask = async (purpose: string, data: Row) => { calls++; return parseModelJson(await deps.ask(purpose, data, signal)); };
  const done = (r: Row) => ({ ...r, model_requests: calls, binance_http_requests: 0, binance_request_weight: 0,
    answered_at_utc: new Date().toISOString(), total_time_ms: Math.round((performance.now()-started)*100)/100 });
  if (action === 'message') {
    const direct=directAppIntent(input.message);
    if(direct){action=direct.action;ticker=direct.ticker;}
    else {
      const explicit=explicitTickers(input.message,state);

      const exact = input.message.trim().toUpperCase();
      if (explicit.length===1 && exact.replace(/^\$/,'')===explicit[0]) { action = 'detail'; ticker = explicit[0]!; }
      else if (isClearResearchRequest(input.message, explicit)) { action = 'research'; ticker = null; }
      else {
        try {
          const intent = await ask('intent', { message: input.message, recent_messages:context, explicit_tickers:explicit });
          if (!['discover','more','detail','compare','refresh','buy','buy_open','sell','swap','dashboard','help','research'].includes(intent.action)
            || !(intent.ticker === null || (typeof intent.ticker === 'string' && SYMBOL.test(intent.ticker)))) throw new Error('invalid_intent');
          action = intent.action; ticker = explicit[0] ?? intent.ticker ?? null;
          // Multi-asset informational requests use DB-backed research even when the
          // intent model labels them as the legacy single-ticker compare/detail path.
          // UI trade commands and single-asset compare/detail retain their old routing.
          if (explicit.length > 1 && ['compare','detail','refresh','research','help'].includes(action)) {
            action = 'research'; ticker = null;
          } else if (explicit.length > 1) {
            return done(frame(state,`You mentioned ${explicit.join(', ')}. Which single RWA asset would you like to analyze or compare first?`,
              {actions:explicit.slice(0,5).map(t=>({action:'detail',ticker:t,label:`Analyze ${t}`}))}));
          }
        } catch { return done(frame(state, 'The agent is temporarily unavailable. Your conversation is saved. You can still use the action buttons.', { model_status:'unavailable', actions:[{action:'discover',label:'Find RWA opportunities'},{action:'help',label:'What can I ask?'}] })); }
      }
    }
  }
  if (action === 'research') {
    if (!deps.research) return done(frame(state,'The saved market research tool is not available right now. Existing analysis and app actions remain available.',{model_status:'unavailable',data_status:'unavailable'}));
    try {
      const requestedTickers=explicitTickers(input.message,state);
      const plan=await ask('research_plan',{user_question:input.message.trim(),recent_messages:context,
        explicit_tickers:requestedTickers.length>=2&&requestedTickers.length<=8?requestedTickers:[]});
      // Never silently answer a named multi-ticker comparison from an incomplete selection.
      if (requestedTickers.length>=2 && requestedTickers.length<=8 &&
          (!Array.isArray(plan.tickers) || !requestedTickers.every(t=>plan.tickers.includes(t)))) throw new Error('incomplete_research_tickers');
      const evidence=await deps.research(plan);
      if(evidence.ok!==true)return done(frame(state,'I could not read the saved research dataset for that question. No market figures have been inferred.',{model_status:'unavailable',data_status:'unavailable'}));
      const reply=await ask('research_answer',{user_question:input.message.trim(),recent_messages:context,evidence});
      if(!obj(reply) || Object.keys(reply).length!==1 || typeof reply.answer!=='string' || reply.answer.trim().length<15 || reply.answer.length>3500
        || /<\s*\/?\s*[a-z][^>]*>/i.test(reply.answer))throw new Error('invalid_research_reply');
      return done(frame(state,reply.answer.trim(),{model_status:'live',response_origin:'agent_research',data_status:'saved_database',research_as_of_utc:evidence.as_of_utc,db_time_ms:evidence.db_time_ms??null}));
    } catch {
      return done(frame(state,'I could not complete that database-backed research request. The previous conversation and other Hubble functions are unchanged.',{model_status:'unavailable',data_status:'unavailable'}));
    }
  }
  if (action === 'help') {
    const fallback='I can research saved market rankings, asset coverage and freshness, explore tokenized stocks, find technical RWA candidates, analyze a ticker, compare providers, and open the app’s Buy, Sell or Swap flows from a written request. Trading still requires the same review steps and explicit wallet confirmations; Hubble never sends a purchase, sale or approval automatically. I can also explain RWA concepts and technical indicators using the app’s saved evidence and stated limitations.';
    try {
      const reply=await ask('conversation',{user_message:input.message.trim()||'What can I ask you?',recent_messages:context,
        capabilities:{assistant_name:'Hubble',scope:'tokenized-stock RWA research and app navigation',discovery:'agent-ranked technical candidates from saved evidence',analysis:'recorded prices, SMA20/SMA50/SMA200 where available, RSI14, detected price zones and setup evidence',comparison:'saved bStocks, Ondo and xStocks representations',trading:'written Buy, Sell and Swap requests can open the existing app flows; current quotes are requested inside those flows and every approval/purchase/sale still requires explicit wallet confirmation',navigation:'written requests can open the Dashboard',education:'general RWA, technical-indicator and app-use explanations',market_research:'saved DB asset search, multi-asset comparison, market rankings, provider availability and data health, with model-written answers',data_access:'saved research snapshots in conversation; executable quotes are requested only after opening a trade flow',purchases_enabled:true,sales_enabled:true,crypto_discovery_enabled:false}});
      if(!obj(reply)||Object.keys(reply).length!==1||typeof reply.answer!=='string'||reply.answer.trim().length<5||reply.answer.length>1800||/[<>]|\b(?:system prompt|chain.of.thought)\b/i.test(reply.answer))throw new Error('invalid_conversation_reply');
      return done(frame(state,reply.answer.trim(),{model_status:'live',response_origin:'agent_written',actions:[{action:'discover',label:'Find RWA opportunities'}]}));
    } catch {
      return done(frame(state,fallback,{model_status:'unavailable',response_origin:'software_fallback',actions:[{action:'discover',label:'Find RWA opportunities'}]}));
    }
  }
  if (action === 'dashboard') return done(frame(state,'Opening the Dashboard.',{ui_action:{type:'navigate',target:'dashboard'},actions:[{action:'dashboard',label:'Open Dashboard'}]}));
  if (action === 'swap') return done(frame(state,'Opening Swap. Choose Buy RWA Tokens or Sell RWA Tokens; no transaction is sent until you review the trade and confirm it in your wallet.',{ui_action:{type:'swap'},actions:[{action:'swap',label:'Open Swap'}]}));
  if (action === 'sell') return done(frame(state,ticker?`Opening Sell RWA tokens for ${ticker}. The sale screen will read supported balances from your connected wallet. Review the fresh quote before any wallet confirmation.`:'Opening Sell RWA tokens. The sale screen will show supported RWA balances from your connected wallet. Review the fresh quote before any wallet confirmation.',{ticker:ticker??undefined,ui_action:{type:'trade',mode:'sale',ticker:ticker??null},actions:[{action:'sell',ticker:ticker??undefined,label:ticker?`Sell ${ticker}`:'Sell RWA Tokens'}]}));
  if (action === 'buy_open') {
    if(!ticker)return done(frame(state,'Opening Buy RWA tokens. Select an asset, review current provider quotes, and confirm any approval or purchase separately in your wallet.',{ui_action:{type:'trade',mode:'buy',ticker:null},actions:[{action:'buy',label:'Buy RWA Tokens'}]}));
    let snapshot:Row|undefined;try{snapshot=await deps.snapshot(ticker);}catch{snapshot=undefined;}
    if(!snapshot?.ok||!snapshot.found||!Array.isArray(snapshot.tokens)||!snapshot.tokens.length)return done(frame(state,`I could not confirm an active saved BSC representation for ${ticker}, so I did not open a purchase flow.`,{ticker,data_status:'unavailable',actions:[{action:'compare',ticker,label:`Compare ${ticker} providers`}]}));
    return done(frame(state,`Opening Buy ${ticker}. Review the fresh provider quotes and confirm any approval or purchase separately in your wallet.`,{ticker,ui_action:{type:'trade',mode:'buy',ticker},actions:[{action:'buy',ticker,label:`Buy ${ticker}`},{action:'compare',ticker,label:`Compare ${ticker} providers`}],db_time_ms:snapshot.db_time_ms}));
  }
  if(input.action==='message' && ['detail','compare','refresh','buy'].includes(action) && !ticker)return done(frame(state,'Which tokenized-stock RWA asset do you mean? Please name its ticker so I can use the correct data.',{actions:[]}));
  if (action === 'buy') {
    let snapshot:Row|undefined;
    try { snapshot=await deps.snapshot(ticker!); } catch { snapshot=undefined; }
    const providerName:Record<string,string>={bstock:'bStocks',ondo:'Ondo',xstocks:'xStocks'};
    if(snapshot?.ok&&snapshot.found&&Array.isArray(snapshot.tokens)&&snapshot.tokens.length){
      const rows=snapshot.tokens.map((r:Row)=>[providerName[r.provider]??r.provider,r.symbol??'Unknown']);
      const places=rows.map((r:any[])=>`${r[0]} (${r[1]})`).join(', ');
      const text=`${ticker} is available in the saved BSC catalogue through ${places}. Open Buy ${ticker} to connect your wallet and request current executable quotes. Catalogue availability does not guarantee that a fresh route will be available.`;
      return done(frame(state,text,{ticker,tables:[{title:`${ticker}: available representations`,columns:['Provider','Token'],rows,numeric_columns:[]}],actions:[{action:'buy',ticker,label:`Buy ${ticker}`},{action:'compare',ticker,label:`Compare ${ticker} providers`}],db_time_ms:snapshot.db_time_ms}));
    }
    return done(frame(state,`I could not confirm an active saved provider representation for ${ticker}. You can still compare the saved provider data before trying a purchase.`,{ticker,data_status:'unavailable',actions:[{action:'compare',ticker,label:`Compare ${ticker} providers`}]}));
  }
  if (action === 'discover' || (action === 'refresh' && !ticker)) {
    const pool = await deps.pool();
    if (!pool.ok || !Array.isArray(pool.records)) return done(frame(state, 'The saved technical evidence is currently unavailable or stale. Your previous messages remain available. Please try again after the data refresh.', {data_status:'unavailable'}));
    const records: Row[] = pool.records;
    if (!records.length) return done(frame(state, 'The saved scan was checked, but no assets currently qualify for discovery with recent, usable technical evidence. This is not a ranking failure. The data availability breakdown is shown below; you can still request a dated analysis of a specific ticker.', {coverage:pool,actions:[{action:'discover',label:'Check again'}]}));
    try {
      // Every eligible record is included. Alphabetical input, no software score or score rank.
      const batchSize=Math.min(10,Math.max(1,requestedCount??(action==='refresh'&&Number.isInteger(state.batch_size)?state.batch_size:10)));
      const count=Math.min(batchSize,records.length);
      const ranked = validateRanking(await ask('rank', { generated_at_utc:pool.generated_at_utc, records, rank_count:count }), records, count);
      state = { pool:records, ranking:ranked, offset:0, selected:null, generated_at_utc:pool.generated_at_utc, batch_size:batchSize,
        last_action:'discover', coverage:{eligible_count:pool.eligible_count,scanned_count:pool.scanned_count,pending_count:pool.pending_count,discovery_summary:pool.discovery_summary}, expanded:pool.expanded,
        next_rank_at_utc:new Date(Date.now()+30_000).toISOString() };
      action = 'more';
    } catch { return done(frame(state, 'The agent could not produce a valid evidence-backed selection. No software ranking has been presented as an agent ranking. Your previous conversation is preserved.', {model_status:'unavailable',actions:[{action:'discover',label:'Try ranking again'}]})); }
  }
  if (action === 'more') {
    if (!state.ranking?.length) return done(frame(state, 'Ask me to find RWA opportunities first.', {actions:[{action:'discover',label:'Find RWA opportunities'}]}));
    const batchSize=Math.min(10,Math.max(1,requestedCount??(Number.isInteger(state.batch_size)?state.batch_size:10)));
    if(state.batch_size!==batchSize)state={...state,batch_size:batchSize};
    if(state.offset===state.ranking.length && state.ranking.length<state.pool.length){
      const readyAt=typeof state.next_rank_at_utc==='string'?Date.parse(state.next_rank_at_utc):0;
      if(Number.isFinite(readyAt)&&readyAt>Date.now()){
        const retry=Math.max(1,Math.ceil((readyAt-Date.now())/1000));
        return done(frame(state,'The next candidate batch is waiting for the model request window. Previous selections and your position are preserved.',{model_status:'cooldown',retry_after_seconds:retry,retry_after_until_utc:state.next_rank_at_utc,actions:[{action:'more',label:'Show more'}]}));
      }
      const chosen=new Set(state.ranking.map((r:Row)=>r.ticker));
      const remaining=state.pool.filter((r:Row)=>!chosen.has(r.ticker));
      const count=Math.min(batchSize,remaining.length);
      try{
        const next=validateRanking(await ask('rank',{generated_at_utc:state.generated_at_utc,records:remaining,rank_count:count}),remaining,count);
        state={...state,ranking:[...state.ranking,...next],next_rank_at_utc:new Date(Date.now()+30_000).toISOString()};
      }catch(error){
        const raw=Number((error as any)?.retryAfterSeconds),retry=Number.isFinite(raw)&&raw>0?Math.min(300,Math.ceil(raw)):15;
        state={...state,next_rank_at_utc:new Date(Date.now()+retry*1000).toISOString()};
        const errorCode=error instanceof Error?error.message:'';
        const cooldownText=errorCode==='model_rate_limited'
          ? 'Model rate limit reached. Please retry when the countdown ends. Previous selections and your position are preserved.'
          : errorCode==='model_provider_unavailable'
          ? 'The model provider is temporarily unavailable. Please retry when the countdown ends. Previous selections and your position are preserved.'
          : 'The next candidate batch needs a short model cooldown. Previous selections and your position are preserved.';
        return done(frame(state,cooldownText,{model_status:'cooldown',retry_after_seconds:retry,retry_after_until_utc:state.next_rank_at_utc,actions:[{action:'more',label:'Try more candidates again'}]}));
      }
    }
    const items = state.ranking.slice(state.offset,state.offset + batchSize);
    if (!items.length) return done(frame(state, 'You have reached the end of this agent ranking.', {actions:[{action:'discover',label:'Refresh opportunity ranking'}]}));
    const cards = items.map((item: Row, i: number) => {
      const r = state.pool.find((r: Row) => r.ticker === item.ticker);
      return {ticker:item.ticker,rank:state.offset+i+1,provider:r.provider,source_candle_end_utc:r.source_candle_end_utc,
        reasons:item.reasons.map((c: string) => CODES[c]),additional_review:r.additional_review};
    });
    state = {...state, offset:state.offset+items.length,last_action:'more'};
    const actions = state.offset < state.pool.length ? [{action:'more',label:'Show more'}] : [];
    const readyAt=typeof state.next_rank_at_utc==='string'?Date.parse(state.next_rank_at_utc):0;
    const retry=actions.length&&Number.isFinite(readyAt)&&readyAt>Date.now()?Math.max(1,Math.ceil((readyAt-Date.now())/1000)):0;
    return done(frame(state, 'Which asset would you like to explore?',
      {cards, actions, retry_after_seconds:retry||undefined, retry_after_until_utc:retry?state.next_rank_at_utc:undefined, model_status:calls ? 'live':'saved_ranking', ranking_generated_at_utc:state.generated_at_utc, coverage:state.coverage}));
  }
  if (!ticker) return done(frame(state, 'Which RWA asset would you like to analyze or compare? Enter its ticker, for example TSLA.', {actions:[{action:'help',label:'What can I ask?'}]}));
  if (action === 'compare') {
    const snapshot = await deps.snapshot(ticker);
    if (!snapshot.ok || !snapshot.found) return done(frame(state, `${ticker} has no available provider snapshot in the current catalogue.`, {data_status:'unavailable'}));
    const tokens = snapshot.tokens;
    const shown = tokens.map((r: Row) => [r.provider,r.symbol,r.price ?? 'Unknown',r.reference_price ?? 'Unknown',r.holders ?? 'Unknown',r.liquidity ?? 'Unknown',
      `${r.market_price_age_seconds ?? 'Unknown'} seconds (${r.market_price_age_seconds_status ?? 'missing'})`,`${r.market_fetch_age_seconds ?? 'Unknown'} seconds (${r.market_fetch_age_seconds_status ?? 'missing'})`]);
    const missing = ['bstock','ondo','xstocks'].filter(p=>!tokens.some((r: Row)=>r.provider===p));
    const text = `Provider comparison for ${ticker}. Prices are database snapshots, not executable quotes. Price and liquidity units are not established here. Holder counts do not establish activity. Zero reported liquidity does not establish that RFQ is unavailable. Fees, slippage and executable quotes are still required.`
      + (missing.length ? ` No active ${missing.join(', ')} representation was found in the checked catalogue.`:'');
    state = Object.keys(state).length ? {...state,selected:ticker,last_action:'compare'} : {pool:[],ranking:[],offset:0,selected:ticker,generated_at_utc:snapshot.captured_at_utc,last_action:'compare'};
    return done(frame(state,text,{ticker,tables:[{title:`${ticker}: provider snapshot at ${snapshot.captured_at_utc}`,columns:['Provider','Token','Market price','Reference price','Holders','Reported liquidity','Price source age','API fetch age'],rows:shown,numeric_columns:[2,3,5]}],actions:[{action:'detail',ticker,label:`Analyze ${ticker}`},{action:'buy',ticker,label:`Buy ${ticker}`}],db_time_ms:snapshot.db_time_ms}));
  }
  let current:Row;
  try { current=await deps.technical(ticker); } catch {current={ok:false,error:'technical_snapshot_unavailable'};}
  const r=current.ok?current.record:undefined;
  const sourceAge=r?Date.now()-Date.parse(r.source_candle_end_utc??''):NaN;
  const usable=r && r.ticker===ticker && Number.isFinite(sourceAge) && sourceAge>=-60_000
    && (r.close!==null && r.close!==undefined
      || Object.entries(r.indicators??{}).some(([k,v])=>v!==null && v!==undefined && r.availability[k]?.status==='available'));
  if(!usable){
    const reasons:Record<string,string>={
      technical_scan_pending:'API candle collection for this token is still pending.',
      ticker_not_in_technical_scope:'This token is outside the current saved technical scan scope.',
      invalid_technical_snapshot:'The saved technical data failed structural validation.',
      technical_snapshot_clock_inconsistency:'The saved data timestamp is inconsistent with the server clock.',
      technical_snapshot_unavailable:'The saved technical data could not be read.',
      technical_record_missing:'No saved technical record was found for this token.',
    };
    const fieldReasons=r?Object.values(r.availability??{}).flatMap((a:any)=>a.reasons??[]):[];
    const reason=reasons[current.error]??(!Number.isFinite(sourceAge)||sourceAge < -60_000
      ?'The collected API candle has no valid completed source timestamp.'
      :'The collected API data has insufficient valid prices or indicator history for this analysis.');
    return done(frame(state,`${ticker}: ${reason} ${[...new Set(fieldReasons)].join(' ')} This does not establish that the token has lost its opportunity status. The older conversation evidence has not been reused.`,
      {ticker,data_status:'unavailable',opportunity_status:'unknown',actions:[{action:'refresh',ticker,label:'Check again'},{action:'compare',ticker,label:`Compare ${ticker} providers`}]}));
  }
  const q=r.data_quality??{};
  const sourceStale=sourceAge>86_400_000;
  const coreComplete=r.close!==null && r.close!==undefined && ['SMA20','SMA50','RSI14'].every(k=>r.availability[k]?.status==='available' && r.indicators[k]!==null);
  const reportAge=Date.now()-Date.parse(current.generated_at_utc??'');
  const reportDelayed=!Number.isFinite(reportAge)||reportAge>129_600_000;
  const collectionUncertain=['unknown','delayed','invalid_timestamp'].includes(q.api_fetch_status);
  const currentStatusKnown=r.status!=='technical_data_unavailable' && !sourceStale && !reportDelayed && !collectionUncertain && coreComplete;
  const opportunityStatus=currentStatusKnown?(r.status==='technical_candidate'?'candidate':'not_candidate'):'unknown';
  const previousCandidate=state.pool?.some((old:Row)=>old.ticker===ticker && old.signals.length>0);
  const notices:string[]=[];
  if(sourceStale)notices.push(`The latest completed candle in the collected API data closed at ${r.source_candle_end_utc} and is ${Math.floor(sourceAge/3_600_000)} hours old (over 24 hours). The analysis describes that recorded setup; it cannot confirm whether the opportunity remains valid now.`);
  if(q.api_fetch_status==='delayed')notices.push(`API data was last collected at ${q.api_fetch_at_utc}. Collection is over 36 hours old; a newer candle may exist at the source. A newly generated report does not refresh the API data.`);
  if(q.api_fetch_status==='unknown')notices.push('The API collection time is not recorded for this saved dataset; source freshness cannot be independently confirmed.');
  if(q.api_fetch_status==='invalid_timestamp')notices.push('The API collection timestamp is inconsistent; collection freshness cannot be confirmed.');
  if(reportDelayed)notices.push(`The saved technical report has not been updated within its 36-hour freshness window. Current opportunity status cannot be confirmed from this report.`);
  if(!coreComplete)notices.push('Some core indicators are unavailable because the collected API data is insufficient or failed field validation. The agent can discuss the available evidence, but cannot confirm a complete opportunity setup. The available evidence and field-specific data limitations are supplied to the agent.');
  if(opportunityStatus==='not_candidate')notices.push(`${ticker} ${previousCandidate?'no longer meets':'does not meet'} the opportunity conditions in the latest saved technical scan. The analysis below evaluates its current recorded situation.`);
  const statusNotice=notices.join('\n\n');
  const previouslyShown=state.analyzed_tickers?.includes(ticker) || (state.selected===ticker && state.last_action==='detail');
  const followUp=input.action==='message' && action==='detail' && previouslyShown;
  let analysis: string;
  let modelStatus = 'live';
  const facts=analysisEvidence(r);
  try {
    const value = await ask('analyze', { ticker:r.ticker, symbol:r.symbol, provider:r.provider,
      response_mode:followUp?'follow_up':'initial_analysis', recent_messages:context, evidence:facts, source_stale:sourceStale, data_quality:{...q,report_status:reportDelayed?'delayed':'recent'}, opportunity_status:opportunityStatus, technical_generated_at_utc:current.generated_at_utc, user_question:input.message.trim() || null });
    analysis=validateAnalysis(value,ticker,facts);
    console.info('[rwa-chat-analysis] '+JSON.stringify({ticker,status:'validated',origin:'agent_written',text_chars:analysis.length,request_utc:new Date().toISOString()}));
  } catch (error) {
    const code=error instanceof Error && /^[a-z_]{1,64}$/.test(error.message)?error.message:'generation_or_reply_validation';
    const diagnostic=obj(error)?error:{};
    console.info('[rwa-chat-analysis] '+JSON.stringify({ticker,status:'unavailable',error_code:code,
      ...(diagnostic.analysis_section?{section:diagnostic.analysis_section}:{}),
      request_utc:new Date().toISOString()}));
    modelStatus = 'unavailable';
    analysis = 'The agent could not produce a valid analysis for this request. The saved evidence tables remain available. You can retry or compare providers.';
  }
  const zones = Array.isArray(r.levels) && r.levels.length ? [{title:'Repeated price zones',columns:['Type','Lower bound','Upper bound','Center'],rows:r.levels.map((l: Row)=>[l.kind,l.low,l.high,l.center]),numeric_columns:[1,2,3]}] : [];
  if(statusNotice)analysis=statusNotice+'\n\n'+analysis;
  if(!Object.keys(state).length)state={pool:[],ranking:[],offset:0,selected:null,generated_at_utc:current.generated_at_utc};
  state = {...state, selected:ticker,last_action:'detail',analyzed_tickers:[...new Set([...(state.analyzed_tickers??[]),ticker])]};
  return done(frame(state,analysis,{ticker,asset_provider:r.provider,tables:followUp?[]:[technicalTable(r),...(r.signals.length ? [{title:'Observed setup evidence',columns:['Setup','Input','Recorded value'],rows:r.signals.flatMap((s: Row)=>Object.entries(s.evidence).map(([k,v])=>[s.code,k,v])),numeric_columns:[2]}] : []),...zones],model_status:modelStatus,analysis_origin:modelStatus==='live'?'agent_written':'unavailable',opportunity_status:opportunityStatus,data_status:sourceStale?'historical':coreComplete?'available':'partial',data_quality:q,technical_generated_at_utc:current.generated_at_utc,ranking_generated_at_utc:current.generated_at_utc,
    actions:[{action:'compare',ticker,label:`Compare ${ticker} providers`},{action:'refresh',ticker,label:'Refresh analysis'},{action:'buy',ticker,label:`Buy ${ticker}`}]}));
}
