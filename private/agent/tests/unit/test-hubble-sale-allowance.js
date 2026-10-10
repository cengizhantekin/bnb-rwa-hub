'use strict';
// Run the actual Hubble Sell response validator against offline fixtures.
// Never contacts a wallet, server, exchange, or blockchain.
const fs=require('fs');
const path=require('path');
const vm=require('vm');
const crypto=require('crypto');
const assert=require('assert/strict');
const src=fs.readFileSync(path.join(__dirname,'../../../../public_html/js/hubble-sale.js'),'utf8');
const from=src.indexOf('async function validate(r){');
const to=src.indexOf("\nel('prepare').addEventListener",from);
assert(from!==-1&&to>from,'sale validator found in current source');
const owner='0x'+'1'.repeat(40),token='0x'+'2'.repeat(40);
const spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';
const usdt='0x55d398326f99059ff775485246999027b3197955';
const raw='20000000000000000',out='4613234056800000000',min='4590167886516000000';
const data='0xad43f73d0000',hash=crypto.createHash('sha256').update(data).digest('hex');
const same=(a,b)=>typeof a==='string'&&typeof b==='string'&&a.toLowerCase()===b.toLowerCase();
const context={ticker:'NVDA',assetProvider:'bstock',symbol:'NVDAB',amount:'0.02',owner,spender,token,usdt,raw:BigInt(raw),lastPermission:BigInt(raw),same,BigInt,
 dataHash:async d=>crypto.createHash('sha256').update(d).digest('hex'), Error};
vm.createContext(context);
const validate=vm.runInContext(src.slice(from,to)+'\nvalidate',context);
function fixture(){
 return {ok:true,execution_enabled:true,ticker:'NVDA',provider:'bstock',symbol:'NVDAB',input_amount:'0.02',vendor:'LiquidMesh',mode:'SWAP',wallet:owner,
  sale_transaction:{from:owner,to:spender,spender,value:'0',chainId:'0x38',input_token:token,output_token:usdt,input_amount_raw:raw,effects_verified:true,
   data,calldata_sha256:hash,minimum_output_raw:min,quoted_output_raw:out,simulated_output_raw:out,allowance_debit_verified:false,wallet_allowance_check_required:true},
  simulation:{transport_status:'received',simulation_status:'SUCCESS',fail_reason:'',balanceChanges_truncated:false,allowanceChanges_truncated:false,
   balanceChanges:[{contractAddress:token,tokenType:'Erc20',change:'-'+raw,owner},{contractAddress:usdt,tokenType:'Erc20',change:out,owner}],allowanceChanges:[]}};
}
(async()=>{
 let passed=0;
 const accept=async v=>{await validate(v);passed++;};
 const reject=async(v,expected)=>{await assert.rejects(validate(v),e=>e.message===expected,expected);passed++;};
 await accept(fixture());
 let v=fixture();v.sale_transaction.wallet_allowance_check_required=false;await reject(v,'allowance_effect_unverified');
 v=fixture();v.sale_transaction.allowance_debit_verified=true;await reject(v,'allowance_effect_unverified');
 context.lastPermission=BigInt(raw)-1n;await reject(fixture(),'allowance_effect_unverified');context.lastPermission=BigInt(raw);
 v=fixture();v.simulation.allowanceChanges=null;await reject(v,'effects_unverified');
 v=fixture();v.simulation.allowanceChanges=[{},{}];await reject(v,'effects_unverified');
 v=fixture();v.simulation.balanceChanges[0].change='-'+(BigInt(raw)-1n);await reject(v,'effects_unverified');
 v=fixture();v.simulation.balanceChanges[1].owner='0x'+'3'.repeat(40);await reject(v,'effects_unverified');
 v=fixture();v.simulation.allowanceChanges=[{tokenAddress:token,owner,spender,preAmount:raw,postAmount:'0'}];
 v.sale_transaction.allowance_debit_verified=true;v.sale_transaction.wallet_allowance_check_required=false;await accept(v);
 v.simulation.allowanceChanges[0].spender='0x'+'3'.repeat(40);await reject(v,'allowance_effect_unverified');
 console.log(`PASS: ${passed} offline Hubble Sell response checks; no API, database or wallet calls.`);
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
