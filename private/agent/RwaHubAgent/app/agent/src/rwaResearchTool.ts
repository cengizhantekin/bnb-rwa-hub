/** Read-only, fixed-path DB research tool. No arbitrary SQL, network calls or wallet capability. */
import { execFile } from 'node:child_process';

const READER = '/home/bhub.tm.biz.tr/private/agent/rwa-research.php';
const TICKER = /^[A-Z0-9][A-Z0-9._-]{0,15}$/;
const MODES = ['assets','health'] as const;
const SORTS = ['market_cap_desc','market_cap_asc','volume_desc','volume_asc','pnl_desc','pnl_asc','ticker_asc','rsi_asc'] as const;
const PROVIDERS = ['all','bstock','ondo','xstocks'] as const;
const TYPES = ['all','stock','etf'] as const;
type ResearchPlan = {
  mode: typeof MODES[number]; sort: typeof SORTS[number]; provider: typeof PROVIDERS[number]; asset_type: typeof TYPES[number];
  tickers: string[]; search: string; limit: number; technical: boolean; technical_status: 'all'|'candidate';
};
export function validateResearchPlan(raw: unknown): ResearchPlan | null {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
  const r=raw as Record<string,unknown>;
  if(Object.keys(r).some(k=>!['mode','sort','provider','asset_type','tickers','search','limit','technical','technical_status'].includes(k)))return null;
  const mode=r.mode,sort=r.sort??'market_cap_desc',provider=r.provider??'all',asset_type=r.asset_type??'all';
  const tickers=r.tickers??[],search=r.search??'',limit=r.limit??8,technical=r.technical??false,technical_status=r.technical_status??'all';
  if(!MODES.includes(mode as ResearchPlan['mode']) || !SORTS.includes(sort as ResearchPlan['sort'])
    || !PROVIDERS.includes(provider as ResearchPlan['provider']) || !TYPES.includes(asset_type as ResearchPlan['asset_type'])
    || !Array.isArray(tickers)||tickers.length>8||!tickers.every(t=>typeof t==='string'&&TICKER.test(t))
    || new Set(tickers).size!==tickers.length || typeof search!=='string'||search.length>60
    || typeof limit!=='number'||!Number.isInteger(limit)||limit<1||limit>15||typeof technical!=='boolean'
    || !['all','candidate'].includes(technical_status as string) || (technical_status==='candidate'&&!technical))return null;
  if(sort==='rsi_asc' && !technical)return null;
  return {mode:mode as ResearchPlan['mode'],sort:sort as ResearchPlan['sort'],provider:provider as ResearchPlan['provider'],asset_type:asset_type as ResearchPlan['asset_type'],tickers,search,limit,technical,technical_status:technical_status as ResearchPlan['technical_status']};
}
function validateResearchResult(raw:unknown,plan:ResearchPlan):Record<string,unknown>|null {
  if(!raw||typeof raw!=='object'||Array.isArray(raw))return null;
  const r=raw as Record<string,unknown>;
  if(r.ok!==true || r.mode!==plan.mode || r.source!=='saved_database' || typeof r.as_of_utc!=='string'
    || !Number.isFinite(Date.parse(r.as_of_utc)) || r.binance_http_requests!==0 || r.binance_request_weight!==0)return null;
  if(plan.mode==='health')return r.data && typeof r.data==='object'&&!Array.isArray(r.data)?r:null;
  if(!Array.isArray(r.items)||r.items.length>plan.limit||!Number.isInteger(r.total_matching)||Number(r.total_matching)<r.items.length)return null;
  for(const item of r.items){
    if(!item||typeof item!=='object'||Array.isArray(item))return null;
    const row=item as Record<string,unknown>;
    if(typeof row.ticker!=='string'||!TICKER.test(row.ticker)||!Array.isArray(row.providers)
       ||row.providers.length>3||!row.providers.every(p=>typeof p==='string'&&PROVIDERS.includes(p as ResearchPlan['provider'])&&p!=='all'))return null;
  }
  return r;
}
export async function readRwaResearch(raw:unknown,abortSignal?:AbortSignal):Promise<Record<string,unknown>> {
  const plan=validateResearchPlan(raw);
  if(!plan)return {ok:false,error:'invalid_research_plan'};
  const started=performance.now();
  const result=await new Promise<Record<string,unknown>>(resolve=>{
    execFile('php',[READER,JSON.stringify(plan)],{encoding:'utf8',timeout:7_000,maxBuffer:85_000,
      killSignal:'SIGKILL',windowsHide:true,signal:abortSignal},(error,stdout,stderr)=>{
      if(error||stderr.trim())return resolve({ok:false,error:'research_data_unavailable'});
      try{resolve(validateResearchResult(JSON.parse(stdout),plan)??{ok:false,error:'invalid_research_result'});}
      catch{resolve({ok:false,error:'invalid_research_result'});}
    });
  });
  console.info('[rwa-research] '+JSON.stringify({mode:plan.mode,ok:result.ok===true,
    returned:Array.isArray(result.items)?result.items.length:null,
    time_ms:Math.round((performance.now()-started)*100)/100,binance_request_weight:0,request_utc:new Date().toISOString()}));
  return result;
}
