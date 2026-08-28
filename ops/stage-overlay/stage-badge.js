(function () {
  fetch('/stage-meta.json', { cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (meta) {
      if (!meta) return;
      var el = document.createElement('div');
      el.textContent = 'STAGE ' + (meta.ref || '?') + ' @ ' + (meta.commit || '?');
      el.style.cssText =
        'position:fixed;left:0;bottom:0;z-index:999999;' +
        'background:#c0392b;color:#fff;font:12px/1.4 monospace;' +
        'padding:4px 8px;border-top-right-radius:4px;opacity:.85;pointer-events:none;';
      document.body.appendChild(el);
    })
    .catch(function () {});
})();
