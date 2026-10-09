from pathlib import Path
import os

ROOT=Path(os.environ.get('BHUB_ROOT','/home/bhub.tm.biz.tr'))
buy=(ROOT/'public_html/hubble-buy.js').read_text()
agent=(ROOT/'public_html/rwa-agent.php').read_text()
api=(ROOT/'public_html/rwa-quote-api.php').read_text()
build=(ROOT/'private/agent/rwa-build-client.php').read_text()
swap=(ROOT/'private/agent/rwa-swap-client.php').read_text()
run_all=(ROOT/'private/agent/run-all-sync.py').read_text()
run_catalog=(ROOT/'private/agent/run-catalog-sync.py').read_text()
market=(ROOT/'public_html/sync-market.php').read_text()
price=(ROOT/'public_html/sync-rwa-price.php').read_text()
tokens=(ROOT/'public_html/sync-tokens.php').read_text()
candles=(ROOT/'private/agent/test-rwa-candles.php').read_text()

old_intro='Connect your wallet and compare current provider quotes on BNB Smart Chain.'
assert old_intro not in agent and old_intro not in buy
assert 'purchase is awaiting verification' not in buy
assert 'Preparation diagnostics' in buy and 'Error code: ' in buy and 'Simulation failure: ' in buy
assert 'quoteWalletThresholdMs=8000' in buy
assert "'Refresh purchase quote'" in buy
assert 'Quote ready for wallet confirmation' in buy
assert 'quoteRemainingMs(swapDraft.a)<quoteWalletThresholdMs' in buy
assert "'swap_simulation_failed'].includes(code)" in buy
assert "report?.error_stage" in buy
assert "'error_stage'=>$error===null?null:$failureStage" in build
assert "$report['error_stage']=$problemStage" in swap
assert "'error_stage'=>'request_pacing'" in api
assert "'error_stage'=>'request_coordination'" in api
assert "fwrite($budget,json_encode(['last_request'=>$last]))" in api
assert "microtime(true)+3.0" in build
assert "microtime(true)+3.0" in (ROOT/'private/agent/rwa-quote-client.php').read_text()
assert "+25)*1000" in swap, 'upstream quote lifetime was changed'
assert '3000000n' in buy, 'purchase gas pilot cap changed unexpectedly'
assert "LOCK = '/run/lock/bnb-rwa-all-sync-run.lock'" in run_all
assert "LOCK = '/run/lock/bnb-rwa-catalog-sync-run.lock'" in run_catalog
assert "LOCK = '/run/lock/bnb-rwa-all-sync.lock'" not in run_all
assert "LOCK = '/run/lock/bnb-rwa-all-sync.lock'" not in run_catalog
for src in (market,price,tokens):
    assert "function acquireWeb3Coordinator" in src
    assert "releaseWeb3Coordinator($apiLock)" in src
    assert src.index('acquireWeb3Coordinator();') < src.index('timestampUtc();')
assert 'function acquireCandleApiLock' in candles
assert "getCandles(array $query, array $cfg, array &$metrics, string $symbol, float &$lockWaitMs)" in candles
assert candles.count("fopen('/run/lock/bnb-rwa-all-sync.lock','r')") == 1
print('PASS: buy copy, diagnostics, quote-refresh UX, quote lifetime, gas cap, budget rollback, and per-request background API coordination guards are intact.')
