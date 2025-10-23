(() => {
  const bust = () => 'v=' + Date.now();

  // folder trenutne stranice, npr. /Dogovoreno/
  const basePath = () => location.pathname.replace(/[^/]+$/, '');

  // Pretvori relativni URL u apsolutni prema trenutnom folderu (NE rootu domene!)
  const toAbs = (url) => new URL(url, location.origin + basePath()).href;

  async function fetchText(url) {
    const u = toAbs(url);
    const sep = u.includes('?') ? '&' : '?';
    const res = await fetch(u + sep + bust(), { cache: 'no-store' });
    if (!res.ok) throw new Error('Include failed ' + u + ' ' + res.status);
    return res.text();
  }

  function executeScripts(container, fileUrlAbs) {
    const scripts = Array.from(container.querySelectorAll('script'));
    for (const old of scripts) {
      const s = document.createElement('script');
      // kopiraj atribute
      for (const a of old.attributes) s.setAttribute(a.name, a.value);
      // riješi relativni src u odnosu na includani file
      if (old.src) {
        const resolved = new URL(old.getAttribute('src'), fileUrlAbs).href;
        s.src = resolved;
      } else {
        s.textContent = old.textContent || '';
      }
      old.replaceWith(s);
    }
  }

  async function doInclude(el) {
    const rel = el.getAttribute('data-include');
    const abs = toAbs(rel);
    const html = await fetchText(rel);
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    executeScripts(tmp, abs);
    el.replaceWith(...tmp.childNodes);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-include]').forEach(async (el) => {
      try { await doInclude(el); } catch (e) { console.error(e); }
    });
  });
})();
