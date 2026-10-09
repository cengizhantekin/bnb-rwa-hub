'use strict';
const fs=require('fs');
const path=require('path');
const vm=require('vm');
const {webcrypto}=require('crypto');

class FakeElement {
  constructor(tag='div') {
    this.tagName=tag.toUpperCase(); this.children=[]; this.dataset={}; this.hidden=false;
    this.disabled=false; this.value=''; this.textContent=''; this.className=''; this.selectedIndex=0;
    this.listeners={};
  }
  append(...items){this.children.push(...items);}
  replaceChildren(...items){this.children=[...items];}
  addEventListener(type,fn){this.listeners[type]=fn;}
  querySelector(){return null;}
  querySelectorAll(selector){
    const cls=selector.startsWith('.')?selector.slice(1):null, out=[];
    const walk=x=>{if(!x||typeof x!=='object')return;if(cls&&String(x.className||'').split(/\s+/).includes(cls))out.push(x);for(const c of x.children||[])walk(c);};
    walk(this); return out;
  }
}
class FakeRoot extends FakeElement {
  constructor(){super('section');this.dataset={ticker:'AMZN'};this.map={};for(const id of ['amount','approval-status','connect','disconnect','purchase-status','quote','quote-form','quote-results','quote-status','switch','title','trade-review','wallet-select','wallet-state','setup'])this.map[id]=new FakeElement(id==='quote-form'?'form':'div');this.map.amount.value='6';}
  querySelector(selector){const m=selector.match(/^\[data-trade-id="([^"]+)"\]$/);return m?this.map[m[1]]:null;}
  querySelectorAll(selector){const out=[];for(const e of Object.values(this.map))out.push(...e.querySelectorAll(selector));return out;}
}
const root=new FakeRoot();
const owner='0x1111111111111111111111111111111111111111';
const router='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';
const usdt='0x55d398326f99059ff775485246999027b3197955';
const token='0x4553cfe1c09f37f38b12dc509f676964e392f8fc';
const hash='0x'+'ab'.repeat(32), blockHash='0x'+'cd'.repeat(32), input='0xad43f73d00';
const transfer='0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const topic=a=>'0x'+'0'.repeat(24)+a.slice(2).toLowerCase();
const uint=v=>'0x'+BigInt(v).toString(16).padStart(64,'0');
const storage=new Map();
const localStorage={getItem:k=>storage.has(k)?storage.get(k):null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k)};
const provider={
  on(){}, removeListener(){},
  async request({method}){
    if(method==='eth_accounts')return [owner];
    if(method==='eth_chainId')return '0x38';
    if(method==='eth_getTransactionByHash')return {hash,from:owner,to:router,value:'0x0',chainId:'0x38',blockHash,input};
    if(method==='eth_getTransactionReceipt')return {transactionHash:hash,blockHash,status:'0x1',blockNumber:'0x64',logs:[
      {address:usdt,topics:[transfer,topic(owner),topic(router)],data:uint(6000000000000000000n),removed:false},
      {address:token,topics:[transfer,topic(router),topic(owner)],data:uint(23210000000000000n),removed:false},
    ]};
    if(method==='eth_blockNumber')return '0x65';
    throw new Error('unexpected method '+method);
  }
};
function allText(el){return [el.textContent,...(el.children||[]).flatMap(allText)].filter(Boolean).join(' ');}
(async()=>{
  const digest=await webcrypto.subtle.digest('SHA-256',new TextEncoder().encode(input));
  const dataHash=Buffer.from(digest).toString('hex');
  storage.set('bnb-rwa-swap:56:'+owner.toLowerCase(),JSON.stringify({owner,chain:'0x38',to:router,token,raw:'6000000000000000000',minimum:'23000000000000000',quoted:'23210000000000000',data_hash:dataHash,symbol:'AMZNon',ticker:'AMZN',hash,submitted_at_ms:Date.now()}));

  let timerId=0; const active=new Set();
  const fastTimeout=(fn,delay)=>{const id=++timerId;active.add(id);if(delay<10000)setImmediate(()=>{if(active.delete(id))fn();});return id;};
  const clearFast=id=>active.delete(id);
  const window={HubbleWallet:{provider,name:'Mock wallet'},HubbleTrading:{reveal(){}},addEventListener(){},dispatchEvent(){},ethereum:null};
  const document={createElement:tag=>new FakeElement(tag)};
  const context={window,document,Event:class Event{constructor(type){this.type=type;}},navigator:{locks:{request:async()=>null}},localStorage,fetch:async()=>({ok:true,json:async()=>({ok:true,csrf:'csrf'})}),AbortSignal,crypto:webcrypto,TextEncoder,performance,setTimeout:fastTimeout,clearTimeout:clearFast,console,BigInt,Promise,Date,String,Number,RegExp,Array,Object,Math,Error};
  vm.createContext(context);
  const projectRoot=process.env.BHUB_ROOT||path.resolve(__dirname,'../../../..');
  const buyPath=path.join(projectRoot,'public_html','js','hubble-buy.js');
  vm.runInContext(fs.readFileSync(buyPath,'utf8'),context,{filename:buyPath});
  const api=context.window.HubbleBuy.mount(root);
  await new Promise(r=>setTimeout(r,80));
  await api.activate();
  await new Promise(r=>setTimeout(r,80));

  const key='bnb-rwa-swap:56:'+owner.toLowerCase();
  if(storage.has(key))throw new Error('confirmed pending record was not cleared');
  const text=allText(root.map['purchase-status']);
  if(!text.includes('Purchase confirmed. Spent 6 USDT and received 0.02321 AMZNon.'))throw new Error('confirmed amounts not rendered: '+text);
  if(text.includes('awaiting verification'))throw new Error('stale awaiting-verification copy survived');
  if(root.map['purchase-status'].querySelectorAll('.approval-action').some(x=>x.textContent==='Check purchase status'))throw new Error('manual status button remained after success');
  console.log('PASS: automatic purchase confirmation clears pending storage and renders verified settlement amounts.');
})().catch(e=>{console.error('FAIL:',e.stack||e);process.exit(1);});
