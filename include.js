// Robustni include za GitHub Pages (radi i na /Dogovoreno/ podputu)
document.addEventListener('DOMContentLoaded', async () => {
  const includes = document.querySelectorAll('[data-include]');
  if (!includes.length) return;

  // detektiraj base path repozitorija (npr. "/Dogovoreno/")
  const parts = location.pathname.split('/').filter(Boolean);
  const repoBase = parts.length ? `/${parts[0]}/` : '/';

  for (const el of includes) {
    let url = el.getAttribute('data-include') || '';
    // ako putanja ne počinje sa "/" ili "http", veži je na repoBase
    if (!/^https?:\/\//i.test(url) && !url.startsWith('/')) {
      url = repoBase + url.replace(/^\.\//,'');
    }

    try{
      const res = await fetch(url, {cache:'no-cache'});
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
      el.innerHTML = await res.text();
    }catch(err){
      console.error('Include failed:', url, err);
      el.innerHTML = `<!-- include failed: ${url} -->`;
    }
  }
});
