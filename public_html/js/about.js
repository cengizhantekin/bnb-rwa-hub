/* About v2: accessible local tabs; no API calls, model requests, or data storage. */
(() => {
  'use strict';
  const tablist = document.querySelector('.about-tabbar');
  if (!tablist) return;
  const tabs = Array.from(tablist.querySelectorAll('[role="tab"][data-tab]'));
  const panels = tabs.map(t => document.getElementById(t.getAttribute('aria-controls')));
  const next = document.getElementById('about-next');
  const counter = document.getElementById('about-topic-count');
  if (tabs.length !== 6 || panels.some(p => !p)) return;
  const ids = tabs.map(t => t.dataset.tab);
  const fromHash = () => {
    const id = decodeURIComponent(location.hash.slice(1));
    return ids.indexOf(id);
  };
  let current = Math.max(0, fromHash());
  function activate(index, {focus = false, url = false, scroll = false} = {}) {
    current = (index + tabs.length) % tabs.length;
    tabs.forEach((tab, i) => {
      const selected = i === current;
      tab.classList.toggle('is-selected', selected);
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      panels[i].classList.toggle('is-active', selected);
      panels[i].hidden = !selected;
    });
    if (counter) counter.textContent = String(current + 1).padStart(2, '0') + ' / 06';
    if (next) next.innerHTML = current === tabs.length - 1 ? 'Back to overview <span aria-hidden="true">↺</span>' : 'Next topic <span aria-hidden="true">→</span>';
    if (url) {
      const target = '#' + ids[current];
      history.replaceState(null, '', target);
    }
    if (focus) tabs[current].focus({preventScroll: true});
    if (focus || scroll) tabs[current].scrollIntoView({block:'nearest',inline:'nearest'});
    if (scroll) {
      // Position the tabs below the sticky/fixed global header, keeping the new panel heading visible.
      const bar = document.querySelector('.about-tabbar-wrap') || tablist;
      const header = document.querySelector('.site-header');
      const position = header ? getComputedStyle(header).position : '';
      const headerHeight = header && (position === 'sticky' || position === 'fixed')
        ? header.getBoundingClientRect().height : 0;
      const destination = window.scrollY + bar.getBoundingClientRect().top - headerHeight - 12;
      window.scrollTo({top: Math.max(0, destination), behavior: 'instant'});
    }
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(index, {url:true}));
  });
  tablist.addEventListener('keydown', event => {
    const idx = tabs.indexOf(document.activeElement);
    if (idx < 0) return;
    let index = null;
    if (event.key === 'ArrowRight') index = idx + 1;
    else if (event.key === 'ArrowLeft') index = idx - 1;
    else if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = tabs.length - 1;
    if (index === null) return;
    event.preventDefault();
    activate(index, {focus:true,url:true});
  });
  if (next) next.addEventListener('click', () => activate(current + 1, {url:true,scroll:true}));
  window.addEventListener('hashchange', () => { const idx = fromHash(); if (idx >= 0) activate(idx, {scroll:true}); });
  activate(current, {scroll: fromHash() >= 0});
})();
