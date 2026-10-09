'use strict';
(() => {
  const tabs = Array.from(document.querySelectorAll('[data-guide-tab]'));
  const panels = Array.from(document.querySelectorAll('[data-guide-panel]'));
  if (tabs.length !== 4 || panels.length !== 4) return;
  const valid = new Set(tabs.map(tab => tab.dataset.guideTab));
  function activate(name, focus = false) {
    if (!valid.has(name)) return;
    for (const tab of tabs) {
      const selected = tab.dataset.guideTab === name;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focus) tab.focus();
    }
    for (const panel of panels) panel.hidden = panel.dataset.guidePanel !== name;
  }
  for (const tab of tabs) {
    tab.addEventListener('click', () => activate(tab.dataset.guideTab));
    tab.addEventListener('keydown', event => {
      const index = tabs.indexOf(tab);
      let next = -1;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next < 0) return;
      event.preventDefault();
      activate(tabs[next].dataset.guideTab, true);
    });
  }
  const name = location.hash.replace(/^#/, '').toLowerCase();
  if (valid.has(name)) activate(name);
})();
