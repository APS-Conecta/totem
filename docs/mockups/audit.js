window.__audit = async function (opts) {
  opts = opts || {};
  const out = [], sleep = ms => new Promise(r => setTimeout(r, ms)), M = window.__mock;
  const check = (id, tag) => {
    const scr = document.getElementById(id), R = scr.getBoundingClientRect(), bad = [];
    scr.querySelectorAll('*').forEach(el => {
      if (!([...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim()))) return;
      if (el.closest('.a-scroll,.nc-body,.b-main,.b-side,.a-main')) return; // scrollable regions
      const r = el.getBoundingClientRect(); if (r.width === 0) return; const e = 1.5;
      if (r.left < R.left - e || r.right > R.right + e || r.top < R.top - e || r.bottom > R.bottom + e) bad.push('outside:' + el.className + ':' + el.textContent.trim().slice(0, 16));
      if (el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflowX === 'visible' && el.clientWidth > 0) bad.push('wider:' + el.className + ':' + el.textContent.trim().slice(0, 16));
    });
    const nw = scr.querySelector('.d-now');
    if (nw) { const a = nw.getBoundingClientRect(); [...nw.children].forEach(c => { const r = c.getBoundingClientRect(); if (r.bottom > a.bottom + 1 || r.top < a.top - 1) bad.push('d-now-clipped:' + c.className); }); }
    const l = scr.querySelector('.d-list');
    if (l) { const a = l.getBoundingClientRect(); [...l.children].forEach(c => { const r = c.getBoundingClientRect(); if (r.bottom > a.bottom + 1) bad.push('list-overflow:' + c.className); }); }
    scr.querySelectorAll('.t-bar,.t-choice').forEach(b => { if (b.scrollHeight > b.clientHeight + 2) bad.push('clip:' + b.className); });
    if (bad.length) out.push(tag + ' -> ' + [...new Set(bad)].slice(0, 5).join(' | '));
  };
  const setSize = async (panel, v) => {
    const sel = document.querySelector('#' + panel + ' select'); sel.value = v; sel.dispatchEvent(new Event('change'));
    if (v === 'custom') { const ins = document.querySelectorAll('#' + panel + ' .cust input'); ins[0].value = 3840; ins[1].value = 2160; ins[0].dispatchEvent(new Event('input')); }
    await sleep(50);
  };
  const sizes = p => [...document.querySelector('#' + p + ' select').options].map(o => o.value);
  const tab = n => document.getElementById('tab-' + n).click();
  const kinds = opts.kinds || ['totem', 'display'];
  if (kinds.includes('totem')) { tab('totem');
    for (const v of sizes('p-totem')) { await setSize('p-totem', v);
      document.getElementById('btn-reset').click(); await sleep(20); check('totem', 'totem-home ' + v);
      document.querySelector('#totem [data-id=farmacia]').click(); await sleep(20); check('totem', 'totem-pref ' + v);
      document.querySelector('#totem [data-v="1"]').click(); await sleep(20); check('totem', 'totem-ticket ' + v);
      document.getElementById('btn-reset').click(); document.getElementById('btn-offline').click(); await sleep(20); check('totem', 'totem-offline ' + v); document.getElementById('btn-offline').click(); } }
  if (kinds.includes('display')) { tab('display');
    for (const v of sizes('p-display')) { await setSize('p-display', v); M.refresh(); await sleep(20); check('display', 'display ' + v); } }
  if (kinds.includes('box')) { tab('box');
    for (const v of sizes('p-box')) { await setSize('p-box', v); M.refresh(); await sleep(20); check('box', 'box ' + v); } }
  if (kinds.includes('admin')) { tab('admin');
    for (const v of sizes('p-admin')) { await setSize('p-admin', v); await sleep(20); check('admin', 'admin ' + v); } }
  return out.length ? out.join('\n') : 'OK ' + kinds.join('+');
};
