(() => {
  'use strict';

  const root = document.getElementById('asset-page-content');
  const loading = document.getElementById('asset-page-loading');
  const error = document.getElementById('asset-page-error');
  const refreshStatus = document.getElementById('asset-page-refresh');
  const ticker = String(document.body?.dataset.assetTicker || '').toUpperCase();

  if (!root || !loading || !error || !refreshStatus) return;

  const REFRESH_INTERVAL_MS = 120000;
  const REFRESH_RETRY_MS = 30000;
  let refreshDueAt = Date.now() + REFRESH_INTERVAL_MS;
  let isLoading = false;
  let requestId = 0;

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  function initials(value) {
    return String(value || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 4) || '?';
  }

  function logoHtml(identity) {
    if (identity.logo_url) {
      return `<span class="asset-page-logo-shell"><img class="asset-page-logo" src="${esc(identity.logo_url)}" alt="" decoding="async"></span>`;
    }
    return `<span class="asset-page-logo-shell"><span class="asset-page-logo-fallback">${esc(initials(identity.ticker))}</span></span>`;
  }

  function compactMoney(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const n = Number(value);
    const abs = Math.abs(n);
    const units = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']];
    for (const [size, suffix] of units) {
      if (abs >= size) {
        const scaled = n / size;
        const scaledAbs = Math.abs(scaled);
        const digits = scaledAbs >= 100 ? 0 : scaledAbs >= 10 ? 1 : 2;
        return '$' + scaled.toFixed(digits) + '\u00a0' + suffix;
      }
    }
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function projectPrice(value) {
    const s = String(value);
    if (!/^\d+(\.\d+)?$/.test(s)) return s;
    const [whole, fraction = ''] = s.split('.');
    if (whole !== '0') {
      const integerDigits = whole.replace(/^0+/, '').length || 1;
      const keep = integerDigits >= 2 ? 2 : 3;
      if (!fraction) return whole;
      const trimmed = fraction.slice(0, keep).replace(/0+$/, '');
      return trimmed ? `${whole}.${trimmed}` : whole;
    }
    const firstNonZero = fraction.search(/[1-9]/);
    if (firstNonZero < 0) return '0';
    const keep = Math.min(fraction.length, firstNonZero + 4);
    const trimmed = fraction.slice(0, keep).replace(/0+$/, '');
    return trimmed ? `0.${trimmed}` : '0';
  }

  function price(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    return '$' + projectPrice(value);
  }

  function pnl(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return { text: '—', cls: 'flat' };
    const n = Number(value);
    return { text: `${n > 0 ? '+' : ''}${n.toFixed(2)}%`, cls: n > 0 ? 'up' : n < 0 ? 'down' : 'flat' };
  }

  function rank(value, total) {
    if (!Number.isFinite(Number(value)) || Number(value) < 1) return '—';
    const n = Number(value).toLocaleString('en-US');
    const t = Number.isFinite(Number(total)) && Number(total) > 0 ? ` of ${Number(total).toLocaleString('en-US')}` : '';
    return `#${n}${t}`;
  }

  function age(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return '—';
    const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
  }

  function technicalNumber(value, digits = 2) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    return Number(value).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: digits });
  }

  function ratio(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    return Number(value).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 8 });
  }

  function shortContract(value) {
    const s = String(value || '');
    if (!/^0x[0-9a-fA-F]{40}$/.test(s)) return s || '—';
    return `${s.slice(0, 8)}…${s.slice(-6)}`;
  }

  function contractHtml(value) {
    const contract = String(value || '');
    const label = esc(shortContract(contract));
    if (!/^0x[0-9a-fA-F]{40}$/.test(contract)) return `<strong>${label}</strong>`;
    const href = `https://bscscan.com/token/${encodeURIComponent(contract)}`;
    return `<a class="asset-page-contract-link" href="${href}" target="_blank" rel="noopener noreferrer"><strong>${label}</strong><span aria-hidden="true">↗</span></a>`;
  }

  function refreshLabel() {
    if (isLoading) {
      refreshStatus.textContent = 'Refreshing…';
      return;
    }
    const seconds = Math.max(0, Math.ceil((refreshDueAt - Date.now()) / 1000));
    if (seconds >= 60) {
      const minutes = Math.floor(seconds / 60);
      const remainder = seconds % 60;
      refreshStatus.textContent = remainder ? `Refreshing in ${minutes}m ${remainder}s` : `Refreshing in ${minutes}m`;
      return;
    }
    refreshStatus.textContent = `Refreshing in ${seconds}s`;
  }

  function scheduleRefresh(delay = REFRESH_INTERVAL_MS) {
    refreshDueAt = Date.now() + delay;
    refreshLabel();
  }

  function signalLabel(code) {
    return ({
      trend_alignment: 'Trend alignment',
      ma_pullback_recovery: 'MA pullback recovery',
      support_recovery: 'Support recovery',
      possible_range_breakout: 'Range breakout',
      possible_breakout_retest: 'Breakout retest',
    })[code] || String(code || '').replaceAll('_', ' ');
  }

  function technicalStatus(status) {
    if (status === 'technical_candidate') return { text: 'Technical candidate', cls: 'candidate' };
    if (status === 'technical_data_unavailable') return { text: 'Technical data unavailable', cls: 'unavailable' };
    if (status === 'not_selected') return { text: 'No current technical signal', cls: '' };
    return { text: 'Technical snapshot', cls: '' };
  }

  function providerChip(label) {
    return `<span class="asset-page-provider-chip">${esc(label)}</span>`;
  }

  function assetTypeBadge(representations) {
    if (!representations.length) return '';
    const codes = representations.map((rep) => rep.asset_type_code);
    const label = codes.every((code) => code === 1)
      ? 'Stock'
      : codes.every((code) => code === 3) ? 'ETF' : null;
    return label ? `<span class="asset-page-provider-chip asset-page-type-chip">${label}</span>` : '';
  }

  function render(data) {
    const identity = data.identity || {};
    const market = data.market || {};
    const reps = Array.isArray(data.representations) ? data.representations : [];
    const tech = data.technical || null;
    const fresh = data.freshness || {};
    const move = pnl(market.pnl_24h);

    document.title = `${identity.ticker || ticker || 'Asset'} · BNB RWA Hub`;

    const providers = Array.from(new Map(reps.map((rep) => [rep.provider, rep])).values())
      .map((rep) => providerChip(rep.provider_label || rep.provider)).join('');

    let representations = '<div class="asset-page-empty">No active BSC token representations are available.</div>';
    if (reps.length) {
      representations = `<div class="asset-page-reps">${reps.map((rep) => {
        const isPriceSource = market.price_provider === rep.provider && (!market.price_symbol || market.price_symbol === rep.symbol);
        const contract = rep.contract_address || '';
        return `<div class="asset-page-rep">
          <div class="asset-page-rep-provider">${providerChip(rep.provider_label || rep.provider)}</div>
          <div class="asset-page-rep-token"><strong>${esc(rep.symbol || '—')}</strong>${isPriceSource ? '<span class="asset-page-source-badge">Price source</span>' : ''}</div>
          <div class="asset-page-rep-price"><span>Token price</span><strong>${price(rep.token_price)}</strong>${rep.token_price_source_kind ? `<small>${esc(rep.token_price_source_kind)}</small>` : ''}</div>
          <div class="asset-page-rep-contract" title="${esc(contract)}"><span>Contract</span>${contractHtml(contract)}</div>
          <div class="asset-page-rep-ratio"><span>Token / share ratio</span><strong>${esc(rep.token_to_share_ratio ? ratio(rep.token_to_share_ratio) : '—')}</strong></div>
        </div>`;
      }).join('')}</div>`;
    }

    let technical = '<div class="asset-page-empty">No saved technical snapshot is available for this underlying.</div>';
    if (tech) {
      const status = technicalStatus(tech.status);
      const signals = Array.isArray(tech.signals) ? tech.signals : [];
      technical = `<div class="asset-page-tech-head">
          <div class="asset-page-tech-state"><span class="asset-page-status ${esc(status.cls)}">${esc(status.text)}</span>${signals.map((signal) => `<span class="asset-page-signal">${esc(signalLabel(signal))}</span>`).join('')}</div>
          <span class="asset-page-tech-source">${esc(tech.provider_label || tech.provider || '—')} · ${esc(tech.symbol || '—')}</span>
        </div>
        <div class="asset-page-tech-grid">
          <div class="asset-page-tech-value"><span>RSI14</span><strong>${technicalNumber(tech.rsi14, 1)}</strong></div>
          <div class="asset-page-tech-value"><span>Close</span><strong>${tech.close === null ? '—' : price(tech.close)}</strong></div>
          <div class="asset-page-tech-value"><span>SMA20</span><strong>${tech.sma20 === null ? '—' : price(tech.sma20)}</strong></div>
          <div class="asset-page-tech-value"><span>SMA50</span><strong>${tech.sma50 === null ? '—' : price(tech.sma50)}</strong></div>
          <div class="asset-page-tech-value"><span>SMA200</span><strong>${tech.sma200 === null ? '—' : price(tech.sma200)}</strong></div>
        </div>`;
    }

    const freshness = [
      ['Displayed price source', fresh.price_source_utc],
      ['Displayed price collector', fresh.price_fetch_utc],
      ['24h PnL source', fresh.pnl_source_utc],
      ['Underlying metrics fetch', fresh.underlying_metrics_fetch_utc],
      ['Technical candle', fresh.technical_candle_utc],
      ['Technical API fetch', fresh.technical_fetch_utc],
    ];

    root.innerHTML = `
      <section class="asset-page-hero">
        <div class="asset-page-identity">
          ${logoHtml(identity)}
          <div class="asset-page-idcopy">
            <div class="asset-page-idline"><h1>${esc(identity.ticker || ticker || '—')}</h1><span>${esc(identity.name || '—')}</span></div>
            <div class="asset-page-provider-strip">${assetTypeBadge(reps)}${providers || providerChip('No provider')}</div>
          </div>
        </div>
        <div class="asset-page-quote">
          <div class="asset-page-price">
            <span>Price</span>
            <strong>${price(market.price)}</strong>
            <small>${market.price_provider_label ? `${esc(market.price_provider_label)}${market.price_source_kind ? ` · ${esc(market.price_source_kind)}` : ''}` : '—'}</small>
          </div>
          <div class="asset-page-pnl ${move.cls}">
            <span>24h PnL</span>
            <strong>${move.text}</strong>
          </div>
        </div>
      </section>

      <div class="asset-page-actions">
        <button class="asset-page-action primary" type="button" data-asset-buy>Buy ${esc(identity.ticker || ticker)}</button>
        <a class="asset-page-action hubble" href="rwa-agent.php?action=detail&amp;ticker=${encodeURIComponent(identity.ticker || ticker)}&amp;source=asset"><img src="assets/hubble/hubble-48.webp" width="20" height="20" alt="" decoding="async">Ask Hubble for detailed analysis</a>
      </div>

      <section class="asset-page-section">
        <h2>Underlying market</h2>
        <div class="asset-page-market-grid">
          <div class="asset-page-market-metric"><span>Market cap</span><strong>${compactMoney(market.market_cap)}</strong></div>
          <div class="asset-page-market-metric"><span>Market cap rank</span><strong>${rank(market.market_cap_rank, market.market_cap_rank_total)}</strong></div>
          <div class="asset-page-market-metric"><span>24h volume</span><strong>${compactMoney(market.volume_24h)}</strong></div>
          <div class="asset-page-market-metric"><span>Volume rank</span><strong>${rank(market.volume_24h_rank, market.volume_24h_rank_total)}</strong></div>
        </div>
      </section>

      <section class="asset-page-section">
        <h2>RWA representations</h2>
        ${representations}
        <p class="asset-page-rep-note">Token / share ratio is provider metadata, not a provider-vs-underlying price ratio. Saved token prices are shown separately.</p>
      </section>

      <section class="asset-page-section">
        <h2>Technical snapshot</h2>
        ${technical}
      </section>

      <section class="asset-page-section">
        <h2>Data freshness</h2>
        <div class="asset-page-freshness">
          ${freshness.map(([label, value]) => `<div class="asset-page-freshness-item"><span>${esc(label)}</span><strong>${esc(age(value))}</strong></div>`).join('')}
        </div>
      </section>

      <p class="asset-page-note">Underlying market cap and volume use the saved preferred-provider snapshot. Provider values are not averaged. Technical signals are saved daily-candle evidence, not a trade recommendation.</p>`;

    loading.hidden = true;
    error.hidden = true;
    root.hidden = false;
  }

  async function load(options = {}) {
    const silent = options.silent === true;
    if (!/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker)) {
      loading.hidden = true;
      error.textContent = 'Invalid asset ticker.';
      error.hidden = false;
      refreshStatus.textContent = '';
      return;
    }
    if (isLoading) return;

    const id = ++requestId;
    isLoading = true;
    refreshLabel();
    if (!silent) {
      loading.hidden = false;
      error.hidden = true;
    }

    try {
      const response = await fetch(`asset-card-api.php?ticker=${encodeURIComponent(ticker)}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok || payload?.ok !== true || !payload.data) throw new Error('asset_unavailable');
      if (id !== requestId) return;
      render(payload.data);
      scheduleRefresh();
    } catch (e) {
      if (id !== requestId) return;
      scheduleRefresh(REFRESH_RETRY_MS);
      if (!silent) {
        loading.hidden = true;
        error.hidden = false;
      }
    } finally {
      if (id === requestId) {
        isLoading = false;
        refreshLabel();
      }
    }
  }

  root.addEventListener('click', (event) => {
    const buy = event.target.closest?.('[data-asset-buy]');
    if (!buy || !root.contains(buy)) return;
    window.HubbleTrading?.open?.('buy', ticker);
  });

  window.setInterval(() => {
    if (document.hidden || isLoading) {
      refreshLabel();
      return;
    }
    if (Date.now() >= refreshDueAt) {
      load({ silent: true });
      return;
    }
    refreshLabel();
  }, 1000);

  document.addEventListener('visibilitychange', () => {
    if (document.hidden || isLoading) return;
    if (Date.now() >= refreshDueAt) load({ silent: true });
    else refreshLabel();
  });

  refreshLabel();
  load();
})();
