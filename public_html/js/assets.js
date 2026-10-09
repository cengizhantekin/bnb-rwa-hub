(() => {
  'use strict';

  const search = document.getElementById('asset-search');
  const provider = document.getElementById('asset-provider');
  const assetType = document.getElementById('asset-type');
  const technicalSignal = document.getElementById('asset-signal');
  const mobileSort = document.getElementById('asset-mobile-sort');
  const mobileDir = document.getElementById('asset-mobile-dir');
  const count = document.getElementById('assets-count');
  const refreshStatus = document.getElementById('assets-refresh');
  const error = document.getElementById('assets-error');
  const loading = document.getElementById('assets-loading');
  const empty = document.getElementById('assets-empty');
  const tableWrap = document.getElementById('assets-table-wrap');
  const table = document.querySelector('.assets-table');
  const tableBody = document.getElementById('assets-table-body');
  const mobileList = document.getElementById('assets-mobile-list');
  const pagination = document.getElementById('assets-pagination');
  const sortButtons = Array.from(document.querySelectorAll('[data-sort]'));
  const assetCard = document.getElementById('asset-card-dialog');
  const assetCardTitle = document.getElementById('asset-card-title');
  const assetCardContent = document.getElementById('asset-card-content');
  const assetCardClose = document.querySelector('[data-asset-card-close]');

  if (!search || !provider || !assetType || !technicalSignal || !mobileSort || !mobileDir || !count || !refreshStatus || !error || !loading || !empty || !tableWrap || !table || !tableBody || !mobileList || !pagination) return;

  const allowedSorts = new Set(['ticker', 'volume', 'market_cap', 'pnl']);
  const allowedProviders = new Set(['all', 'bstock', 'ondo', 'xstocks']);
  const allowedTypes = new Set(['all', 'stock', 'etf']);
  const allowedSignals = new Set(['all', 'trend_alignment', 'ma_pullback_recovery', 'support_recovery', 'possible_range_breakout', 'possible_breakout_retest']);
  const params = new URLSearchParams(location.search);

  const state = {
    page: Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1),
    sort: allowedSorts.has(params.get('sort')) ? params.get('sort') : 'market_cap',
    dir: params.get('dir') === 'asc' ? 'asc' : 'desc',
    q: (params.get('q') || '').slice(0, 80),
    provider: allowedProviders.has(params.get('provider')) ? params.get('provider') : 'all',
    asset_type: allowedTypes.has(params.get('asset_type')) ? params.get('asset_type') : 'all',
    signal: allowedSignals.has(params.get('signal')) ? params.get('signal') : 'all',
  };

  if (!params.has('dir')) state.dir = state.sort === 'ticker' ? 'asc' : 'desc';

  search.value = state.q;
  provider.value = state.provider;
  assetType.value = state.asset_type;
  technicalSignal.value = state.signal;
  mobileSort.value = state.sort;

  let requestId = 0;
  let searchTimer = null;
  let isLoading = false;
  let refreshDueAt = Date.now() + 120000;
  const REFRESH_INTERVAL_MS = 120000;
  const REFRESH_RETRY_MS = 30000;

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  function initials(ticker) {
    return String(ticker || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 4) || '?';
  }

  function compactMoney(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const n = Number(value);
    const abs = Math.abs(n);
    const units = [
      [1e12, 'T'],
      [1e9, 'B'],
      [1e6, 'M'],
      [1e3, 'K'],
    ];
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
    const [rawInteger, fraction = ''] = s.split('.');
    const integer = rawInteger.replace(/^0+(?=\d)/, '');
    if (integer === '0' && !/[1-9]/.test(fraction)) return '0';
    const places = integer !== '0' ? (integer.length >= 2 ? 2 : 3) : fraction.search(/[1-9]/) + 4;
    let digits = BigInt(integer + fraction.padEnd(places, '0').slice(0, places));
    if ((fraction[places] ?? '0') >= '5') digits++;
    const rounded = digits.toString().padStart(places + 1, '0');
    const whole = rounded.slice(0, -places);
    const part = rounded.slice(-places).replace(/0+$/, '');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (part ? '.' + part : '');
  }

  function price(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    return '$' + projectPrice(value);
  }

  function pnl(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return { text: '—', cls: 'pnl-flat' };
    const n = Number(value);
    return {
      text: `${n > 0 ? '+' : ''}${n.toFixed(2)}%`,
      cls: n > 0 ? 'pnl-up' : n < 0 ? 'pnl-down' : 'pnl-flat',
    };
  }

  function logoHtml(item) {
    if (item.logo_url) {
      return `<span class="asset-logo-shell"><img class="asset-logo" src="${esc(item.logo_url)}" alt="" loading="lazy" decoding="async"></span>`;
    }
    return `<span class="asset-logo-shell"><span class="asset-logo-fallback">${esc(initials(item.ticker))}</span></span>`;
  }

  function providerHtml(providers) {
    if (!Array.isArray(providers) || providers.length === 0) return '<span class="provider-chip">—</span>';
    return providers.map((p) => `<span class="provider-chip" title="${esc(p.symbol || '')}">${esc(p.label)}</span>`).join('');
  }

  function etfBadge(item) {
    return item.asset_type === 'etf' ? '<span class="asset-type-badge">ETF</span>' : '';
  }

  function renderTable(items) {
    tableBody.innerHTML = items.map((item) => {
      const move = pnl(item.pnl_24h);
      return `<tr>
        <td class="asset-rank">${item.display_rank ?? '—'}</td>
        <td class="sort-cell sort-ticker"><div class="ticker-cell">${logoHtml(item)}<span class="asset-ticker-labels"><a class="asset-ticker-link" href="asset.php?ticker=${encodeURIComponent(item.ticker)}">${esc(item.ticker)}</a>${etfBadge(item)}</span></div></td>
        <td><span class="company-name">${esc(item.name || '—')}</span></td>
        <td class="numeric"><span class="numeric-value">${price(item.price)}</span></td>
        <td class="numeric sort-cell sort-volume"><span class="numeric-value">${compactMoney(item.volume_24h)}</span></td>
        <td class="numeric sort-cell sort-market_cap"><span class="numeric-value">${compactMoney(item.market_cap)}</span></td>
        <td><div class="provider-list">${providerHtml(item.providers)}</div></td>
        <td class="numeric sort-cell sort-pnl"><span class="numeric-value ${move.cls}">${move.text}</span></td>
      </tr>`;
    }).join('');
  }

  function renderMobile(items) {
    mobileList.innerHTML = items.map((item) => {
      const move = pnl(item.pnl_24h);
      return `<article class="asset-mobile-card">
        <div class="asset-mobile-head">
          ${logoHtml(item)}
          <div class="asset-mobile-copy">
            <div class="asset-mobile-title"><a class="asset-ticker-link" href="asset.php?ticker=${encodeURIComponent(item.ticker)}">${esc(item.ticker)}</a>${etfBadge(item)}<span class="asset-mobile-rank">#${item.display_rank ?? '—'}</span></div>
            <div class="asset-mobile-company">${esc(item.name || '—')}</div>
          </div>
          <div class="asset-mobile-pnl ${move.cls}">${move.text}</div>
        </div>
        <div class="asset-mobile-metrics">
          <div class="asset-mobile-metric"><span>Price</span><strong>${price(item.price)}</strong></div>
          <div class="asset-mobile-metric"><span>24h underlying volume</span><strong>${compactMoney(item.volume_24h)}</strong></div>
          <div class="asset-mobile-metric"><span>Underlying market cap</span><strong>${compactMoney(item.market_cap)}</strong></div>
        </div>
        <div class="asset-mobile-providers"><div class="provider-list">${providerHtml(item.providers)}</div></div>
      </article>`;
    }).join('');
  }

  function age(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return '—';
    let seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
  }

  function rank(value, total) {
    if (!Number.isFinite(Number(value)) || Number(value) < 1) return '—';
    const n = Number(value).toLocaleString('en-US');
    const t = Number.isFinite(Number(total)) && Number(total) > 0 ? ` of ${Number(total).toLocaleString('en-US')}` : '';
    return `#${n}${t}`;
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

  function cardMetric(label, value, small = '') {
    return `<div class="asset-card-metric"><span>${esc(label)}</span><strong>${value}</strong>${small ? `<small>${esc(small)}</small>` : ''}</div>`;
  }

  function renderAssetCard(data) {
    const identity = data.identity || {};
    const market = data.market || {};
    const reps = Array.isArray(data.representations) ? data.representations : [];
    const tech = data.technical || null;
    const fresh = data.freshness || {};
    const move = pnl(market.pnl_24h);
    const providerLabels = Array.from(new Map(reps.map((x) => [x.provider, x])).values())
      .map((x) => `<span class="provider-chip">${esc(x.provider_label || x.provider)}</span>`).join('');
    const identityLogo = logoHtml({ ticker: identity.ticker, logo_url: identity.logo_url });

    let repHtml = '<div class="asset-card-empty">No active BSC token representations are available.</div>';
    if (reps.length) {
      repHtml = `<div class="asset-card-representations">${reps.map((rep) => {
        const source = market.price_provider === rep.provider && (!market.price_symbol || market.price_symbol === rep.symbol) ? '<span class="asset-card-price-source">Price source</span>' : '';
        const contract = rep.contract_address || '';
        return `<div class="asset-card-rep">
          <div><span class="provider-chip">${esc(rep.provider_label || rep.provider)}</span></div>
          <div class="asset-card-rep-symbol">${esc(rep.symbol || '—')}${source}</div>
          <div class="asset-card-contract" title="${esc(contract)}">${esc(shortContract(contract))}</div>
          <div class="asset-card-ratio">${rep.token_to_share_ratio ? `Ratio ${esc(ratio(rep.token_to_share_ratio))}` : 'Ratio —'}</div>
        </div>`;
      }).join('')}</div>`;
    }

    let techHtml = '<div class="asset-card-empty">No saved technical snapshot is available for this underlying.</div>';
    if (tech) {
      const status = technicalStatus(tech.status);
      const signals = Array.isArray(tech.signals) ? tech.signals : [];
      techHtml = `<div class="asset-card-tech-head"><span class="asset-card-status ${esc(status.cls)}">${esc(status.text)}</span><span class="asset-card-tech-source">${esc(tech.provider_label || tech.provider || '—')} · ${esc(tech.symbol || '—')}</span></div>
        ${signals.length ? `<div class="asset-card-signals">${signals.map((s) => `<span class="asset-card-signal">${esc(signalLabel(s))}</span>`).join('')}</div>` : ''}
        <div class="asset-card-tech-grid">
          <div class="asset-card-tech-value"><span>RSI14</span><strong>${technicalNumber(tech.rsi14, 1)}</strong></div>
          <div class="asset-card-tech-value"><span>Close</span><strong>${tech.close === null ? '—' : price(tech.close)}</strong></div>
          <div class="asset-card-tech-value"><span>SMA20</span><strong>${tech.sma20 === null ? '—' : price(tech.sma20)}</strong></div>
          <div class="asset-card-tech-value"><span>SMA50</span><strong>${tech.sma50 === null ? '—' : price(tech.sma50)}</strong></div>
          <div class="asset-card-tech-value"><span>SMA200</span><strong>${tech.sma200 === null ? '—' : price(tech.sma200)}</strong></div>
        </div>`;
    }

    const freshnessItems = [
      ['Displayed price source', fresh.price_source_utc],
      ['Displayed price collector', fresh.price_fetch_utc],
      ['24h PnL source', fresh.pnl_source_utc],
      ['Underlying metrics fetch', fresh.underlying_metrics_fetch_utc],
      ['Technical candle', fresh.technical_candle_utc],
      ['Technical API fetch', fresh.technical_fetch_utc],
    ];

    assetCardTitle.textContent = identity.ticker || 'Asset';
    assetCardContent.innerHTML = `
      <div class="asset-card-identity">
        ${identityLogo}
        <div class="asset-card-idcopy">
          <div class="asset-card-idline"><strong>${esc(identity.ticker || '—')}</strong><span>${esc(identity.name || '—')}</span></div>
          <div class="asset-card-provider-strip">${providerLabels || '<span class="provider-chip">No provider</span>'}</div>
        </div>
      </div>

      <section class="asset-card-section">
        <h3>Market snapshot</h3>
        <div class="asset-card-metric-grid">
          ${cardMetric('Price', price(market.price), market.price_provider_label ? `${market.price_provider_label}${market.price_source_kind ? ` · ${market.price_source_kind}` : ''}` : '')}
          ${cardMetric('24h PnL', `<span class="${move.cls}">${move.text}</span>`)}
          ${cardMetric('Underlying market cap', compactMoney(market.market_cap))}
          ${cardMetric('Market cap rank', rank(market.market_cap_rank, market.market_cap_rank_total))}
          ${cardMetric('Underlying 24h volume', compactMoney(market.volume_24h))}
          ${cardMetric('Volume rank', rank(market.volume_24h_rank, market.volume_24h_rank_total))}
        </div>
      </section>

      <section class="asset-card-section">
        <h3>RWA representations</h3>
        ${repHtml}
      </section>

      <section class="asset-card-section">
        <h3>Technical snapshot</h3>
        ${techHtml}
      </section>

      <section class="asset-card-section">
        <h3>Data freshness</h3>
        <div class="asset-card-freshness">
          ${freshnessItems.map(([label, value]) => `<div class="asset-card-freshness-item"><span>${esc(label)}</span><strong>${esc(age(value))}</strong></div>`).join('')}
        </div>
      </section>

      <p class="asset-card-note">Underlying market cap and volume use the saved preferred-provider snapshot. Provider values are not averaged. Technical signals are saved daily-candle evidence, not a trade recommendation.</p>`;
  }

  let assetCardRequestId = 0;
  async function openAssetCard(ticker) {
    if (!assetCard || !assetCardTitle || !assetCardContent) return;
    const cleanTicker = String(ticker || '').toUpperCase();
    if (!/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(cleanTicker)) return;

    const id = ++assetCardRequestId;
    assetCardTitle.textContent = cleanTicker;
    assetCardContent.innerHTML = '<div class="asset-card-loading">Loading saved asset data…</div>';
    if (!assetCard.open) assetCard.showModal();

    try {
      const response = await fetch(`asset-card-api.php?ticker=${encodeURIComponent(cleanTicker)}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (id !== assetCardRequestId) return;
      if (!response.ok || payload?.ok !== true || !payload.data) throw new Error('asset_card_unavailable');
      renderAssetCard(payload.data);
    } catch (e) {
      if (id !== assetCardRequestId) return;
      assetCardContent.innerHTML = '<div class="asset-card-error">Saved asset details could not be loaded.</div>';
    }
  }

  function renderPagination(meta) {
    const pages = Number(meta.total_pages || 1);
    const current = Number(meta.page || 1);
    if (pages <= 1) {
      pagination.hidden = true;
      pagination.innerHTML = '';
      return;
    }

    const parts = [];
    const addButton = (page, label, cls = '') => {
      parts.push(`<button type="button" data-page="${page}" class="${cls}" ${page === current ? 'aria-current="page"' : ''}>${label}</button>`);
    };

    parts.push(`<button type="button" data-page="${Math.max(1, current - 1)}" ${current === 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>`);

    const visible = new Set([1, pages, current - 2, current - 1, current, current + 1, current + 2]);
    const ordered = Array.from(visible).filter((p) => p >= 1 && p <= pages).sort((a, b) => a - b);
    let previous = 0;
    for (const p of ordered) {
      if (previous && p - previous > 1) parts.push('<span class="ellipsis">…</span>');
      addButton(p, String(p), p === current ? 'current' : '');
      previous = p;
    }

    parts.push(`<button type="button" data-page="${Math.min(pages, current + 1)}" ${current === pages ? 'disabled' : ''} aria-label="Next page">›</button>`);
    pagination.innerHTML = parts.join('');
    pagination.hidden = false;
  }

  function syncSortUi() {
    table.dataset.activeSort = state.sort;
    for (const button of sortButtons) {
      const mark = button.querySelector('.sort-mark');
      const active = button.dataset.sort === state.sort;
      const th = button.closest('th');
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      if (th) {
        th.classList.toggle('active-sort', active);
        if (active) {
          th.setAttribute('aria-sort', state.dir === 'asc' ? 'ascending' : 'descending');
        } else {
          th.removeAttribute('aria-sort');
        }
      }
      if (mark) mark.textContent = active ? (state.dir === 'asc' ? '↑' : '↓') : '↕';
    }
    mobileSort.value = state.sort;
    mobileDir.textContent = state.dir === 'asc' ? '↑' : '↓';
    mobileDir.setAttribute('aria-label', state.dir === 'asc' ? 'Sort descending' : 'Sort ascending');
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

  function syncUrl() {
    const next = new URLSearchParams();
    if (state.page > 1) next.set('page', String(state.page));
    if (state.sort !== 'market_cap') next.set('sort', state.sort);
    if (state.dir !== (state.sort === 'ticker' ? 'asc' : 'desc')) next.set('dir', state.dir);
    if (state.q) next.set('q', state.q);
    if (state.provider !== 'all') next.set('provider', state.provider);
    if (state.asset_type !== 'all') next.set('asset_type', state.asset_type);
    if (state.signal !== 'all') next.set('signal', state.signal);
    const qs = next.toString();
    history.replaceState(null, '', location.pathname + (qs ? `?${qs}` : ''));
  }

  async function loadAssets(options = {}) {
    const silent = options.silent === true;
    const id = ++requestId;
    isLoading = true;
    refreshLabel();
    error.hidden = true;
    if (!silent) {
      loading.hidden = false;
      empty.hidden = true;
      tableWrap.hidden = true;
      mobileList.hidden = true;
      pagination.hidden = true;
      count.textContent = 'Loading assets…';
    }
    syncSortUi();

    const qs = new URLSearchParams({
      page: String(state.page),
      sort: state.sort,
      dir: state.dir,
      provider: state.provider,
      asset_type: state.asset_type,
      signal: state.signal,
    });
    if (state.q) qs.set('q', state.q);

    try {
      const response = await fetch(`assets-api.php?${qs.toString()}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok || !payload.data) throw new Error('assets_unavailable');
      if (id !== requestId) return;

      const data = payload.data;
      state.page = Number(data.pagination.page || 1);
      state.sort = data.state.sort;
      state.dir = data.state.dir;
      state.q = data.state.q;
      state.provider = data.state.provider;
      state.asset_type = data.state.asset_type;
      state.signal = data.state.signal;
      assetType.value = state.asset_type;
      technicalSignal.value = state.signal;
      syncUrl();
      syncSortUi();

      const items = Array.isArray(data.items) ? data.items : [];
      const meta = data.pagination;
      count.textContent = meta.total_items === 0
        ? 'No matching assets'
        : `Showing ${meta.from}–${meta.to} of ${meta.total_items} assets`;

      isLoading = false;
      scheduleRefresh();
      loading.hidden = true;
      if (items.length === 0) {
        empty.hidden = false;
        mobileList.hidden = true;
        tableWrap.hidden = true;
        pagination.hidden = true;
        return;
      }

      renderTable(items);
      renderMobile(items);
      tableWrap.hidden = false;
      mobileList.hidden = false;
      renderPagination(meta);
    } catch (e) {
      if (id !== requestId) return;
      isLoading = false;
      scheduleRefresh(REFRESH_RETRY_MS);
      loading.hidden = true;
      if (silent) return;
      empty.hidden = true;
      tableWrap.hidden = true;
      mobileList.hidden = true;
      error.textContent = 'Asset data could not be loaded from the saved database snapshots.';
      error.hidden = false;
      count.textContent = 'Assets unavailable';
    }
  }

  sortButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const nextSort = button.dataset.sort;
      if (!allowedSorts.has(nextSort)) return;
      if (state.sort === nextSort) {
        state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      } else {
        state.sort = nextSort;
        state.dir = nextSort === 'ticker' ? 'asc' : 'desc';
      }
      state.page = 1;
      loadAssets();
    });
  });

  search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.q = search.value.trim().slice(0, 80);
      state.page = 1;
      loadAssets();
    }, 250);
  });

  provider.addEventListener('change', () => {
    state.provider = allowedProviders.has(provider.value) ? provider.value : 'all';
    state.page = 1;
    loadAssets();
  });

  assetType.addEventListener('change', () => {
    state.asset_type = allowedTypes.has(assetType.value) ? assetType.value : 'all';
    state.page = 1;
    loadAssets();
  });

  technicalSignal.addEventListener('change', () => {
    state.signal = allowedSignals.has(technicalSignal.value) ? technicalSignal.value : 'all';
    state.page = 1;
    loadAssets();
  });

  mobileSort.addEventListener('change', () => {
    if (!allowedSorts.has(mobileSort.value)) return;
    state.sort = mobileSort.value;
    state.dir = state.sort === 'ticker' ? 'asc' : 'desc';
    state.page = 1;
    loadAssets();
  });

  mobileDir.addEventListener('click', () => {
    state.dir = state.dir === 'asc' ? 'desc' : 'asc';
    state.page = 1;
    loadAssets();
  });

  pagination.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-page]');
    if (!button || button.disabled) return;
    const next = Number.parseInt(button.dataset.page || '', 10);
    if (!Number.isFinite(next) || next < 1 || next === state.page) return;
    state.page = next;
    loadAssets();
    window.scrollTo({ top: Math.max(0, document.querySelector('.assets-workspace').offsetTop - 120), behavior: 'smooth' });
  });

  document.addEventListener('click', (event) => {
    const tickerButton = event.target.closest('[data-asset-ticker]');
    if (!tickerButton) return;
    openAssetCard(tickerButton.dataset.assetTicker || tickerButton.textContent || '');
  });

  if (assetCardClose && assetCard) {
    assetCardClose.addEventListener('click', () => assetCard.close());
    assetCard.addEventListener('click', (event) => {
      if (event.target === assetCard) assetCard.close();
    });
  }

  window.setInterval(() => {
    if (document.hidden || isLoading) {
      refreshLabel();
      return;
    }
    if (Date.now() >= refreshDueAt) {
      loadAssets({ silent: true });
      return;
    }
    refreshLabel();
  }, 1000);

  document.addEventListener('visibilitychange', () => {
    if (document.hidden || isLoading) return;
    if (Date.now() >= refreshDueAt) {
      loadAssets({ silent: true });
    } else {
      refreshLabel();
    }
  });

  refreshLabel();
  loadAssets();
})();
