/* Asset Detail only: read-only Binance Wallet Skill evidence, separate from trading and saved price data. */
(() => {
  'use strict';
  const root = document.getElementById('asset-wallet-skills');
  const ticker = String(document.body?.dataset.assetTicker || '').trim().toUpperCase();
  if (!root || !/^[A-Z0-9][A-Z0-9._-]{0,15}$/.test(ticker)) return;

  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number = (v, digits = 2) => (typeof v === 'number' && Number.isFinite(v))
    ? v.toLocaleString('en-US', {maximumFractionDigits: digits}) : '—';
  const usd = v => (typeof v === 'number' && Number.isFinite(v))
    ? '$' + number(v) : '—';
  const utc = v => {
    if (typeof v !== 'string') return 'Unavailable';
    const d = new Date(v);
    return Number.isFinite(d.getTime()) ? d.toLocaleString('en-GB', {timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'short'}) + ' UTC' : 'Unavailable';
  };
  const cell = (label, value) => `<div class="asset-skill-metric"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
  const sourceLink = (name, href) => `<a href="${href}" target="_blank" rel="noopener noreferrer">${esc(name)} ↗</a>`;
  const statusLabel = status => {
    if (!status || typeof status.open !== 'boolean') return 'Unavailable';
    return status.open ? 'Open (Ondo)' : 'Not open (Ondo)';
  };

  const reportLink = (value, label) => {
    try {
      const u = new URL(value);
      if (u.protocol === 'https:' && u.hostname === 'bin.bnbstatic.com' &&
          /^\/images\/web3-data\/public\/token\/ondo\/pdf\/[A-Za-z0-9/_-]+\.pdf$/.test(u.pathname) && !u.search && !u.hash) {
        return `<a href="${esc(value)}" target="_blank" rel="noopener noreferrer">${label} ↗</a>`;
      }
    } catch (_) { /* No verified URL. */ }
    return `<span>${label}: unavailable</span>`;
  };

  function show(payload) {
    const ondo = payload.ondo;
    const audits = Array.isArray(payload.audits) ? payload.audits : [];
    const sections = [];
    if (ondo) {
      const s = ondo.status;
      const f = ondo.fundamentals;
      const supply = ondo.supply;
      const company = ondo.company;
      const reports = ondo.reports;
      const hasData = !!(s || f || supply || company || reports);
      const eventCode = s?.reason_code || null;
      const event = eventCode === 'ASSET_PAUSED' || eventCode === 'ASSET_LIMITED';
      sections.push(`<section class="asset-page-section asset-skill-panel" aria-label="Ondo securities information">
        <div class="asset-skill-heading"><h2>Ondo Securities Intelligence</h2><span class="asset-skill-provider">Binance Wallet Skill</span></div>
        <p class="asset-skill-sub">Applies only to ${esc(ondo.symbol || 'Ondo')} on BNB Smart Chain, not to other token providers or DEX trading availability.</p>
        ${hasData ? `
          <div class="asset-skill-grid">
            ${cell('Ondo asset status', statusLabel(s))}
            ${cell('Market session', s?.session || '—')}
            ${cell('Next Ondo market open', s?.next_open_utc ? utc(s.next_open_utc) : '—')}
            ${cell('P/E (underlying stock)', number(f?.pe_ttm))}
            ${cell('Dividend yield (underlying)', f?.dividend_yield_pct == null ? '—' : number(f.dividend_yield_pct) + '%')}
            ${cell('52-week high / low (stock)', `${usd(f?.high_52w_usd)} / ${usd(f?.low_52w_usd)}`)}
            ${cell('BSC token market cap', usd(supply?.market_cap_bsc_usd))}
            ${cell('Token holders (cross-chain)', number(supply?.holders_cross_chain, 0))}
          </div>
          ${s?.reason_code && s.reason_code !== 'TRADING' ? `<p class="asset-skill-alert">Ondo status: <strong>${esc(s.reason_code)}</strong>${s.reason ? ` · ${esc(s.reason)}` : ''}${event ? ' · Possible corporate action or trading restriction.' : ''}</p>` : ''}
          ${company?.industry || company?.ceo ? `<p class="asset-skill-sub">${company?.industry ? `Industry: ${esc(company.industry)}` : ''}${company?.ceo ? ` · CEO: ${esc(company.ceo)}` : ''}</p>` : ''}
          <div class="asset-skill-reports">${reportLink(reports?.daily, 'Daily attestation')} ${reportLink(reports?.monthly, 'Monthly attestation')}</div>`
        : '<p class="asset-skill-empty">Ondo Skill data is currently unavailable for this verified BSC contract.</p>'}
        <p class="asset-skill-source">${sourceLink('binance-tokenized-securities-info', 'https://github.com/binance/binance-skills-hub/tree/main/skills/binance-web3/binance-tokenized-securities-info')}</p>
      </section>`);
    }

    // Security status is rendered in the single provider/contract table above;
    // never duplicate the provider audit cards in a lower section.
    window.dispatchEvent(new CustomEvent('assetWalletAuditData', {detail: {audits}}));

    const date = payload.fetched_at_utc ? utc(payload.fetched_at_utc) : 'Unavailable';
    const freshness = payload.ttl_seconds ? ` · Cache TTL: ${Math.round(payload.ttl_seconds / 60)} min` : '';
    const callCount = Number.isInteger(payload.http_requests) ? payload.http_requests : 0;
    const elapsed = typeof payload.duration_ms === 'number' ? number(payload.duration_ms, 1) + ' ms' : 'unavailable';
    root.innerHTML = sections.join('') + `<p class="asset-skill-footnote">Wallet Skills fetched: ${esc(date)}${freshness} · HTTP requests this lookup: ${callCount} · Query time: ${esc(elapsed)}${payload.cache_hit ? ' (cache hit)' : ''}. Public endpoint request weight: not documented. Results are point-in-time information, not executable quotes.</p>`;
    root.hidden = false;
  }

  async function load() {
    try {
      const response = await fetch(`asset-skills-api.php?ticker=${encodeURIComponent(ticker)}`, {
        credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
      });
      const body = await response.json();
      if (!response.ok || body?.ok !== true) throw new Error('skills_unavailable');
      show(body);
    } catch (_) {
      window.dispatchEvent(new CustomEvent('assetWalletAuditData', {detail: {audits: []}}));
      root.innerHTML = `<section class="asset-page-section asset-skill-panel"><h2>Binance Wallet Skills</h2><p class="asset-skill-empty">Additional security and Ondo information is temporarily unavailable. Existing asset data remains unaffected.</p></section>`;
      root.hidden = false;
    }
  }
  load();
})();
