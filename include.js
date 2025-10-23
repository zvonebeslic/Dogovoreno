<script>
(function(){
  const BUST = () => 'v=' + Date.now();

  function basePath(){ return location.pathname.replace(/[^\/]+$/, ''); }

  function toAbs(url, base){
    try { return new URL(url, base).href; } catch(_){ return url; }
  }

  function doFetch(url){
    const bust = (url.includes('?') ? '&' : '?') + BUST();
    return fetch(url + bust, { cache:'no-cache' });
  }

  // Izvrši sve <script> tagove iz container-a, po redu
  function executeScripts(container, fileUrl){
    const nodeList = container.querySelectorAll('script');
    // iteracija po statičnoj kopiji (jer ćemo uklanjati/umetati)
    const scripts = Array.from(nodeList);
    for (const old of scripts){
      const s = document.createElement('script');
      // kopiraj atribute
      for (const a of old.attributes) s.setAttribute(a.name, a.value);
      // riješi relativni src prema fileUrl
      if (old.src){
        s.src = toAbs(old.getAttribute('src'), fileUrl);
      }else{
        s.textContent = old.textContent || '';
      }
      // osiguraj da se izvrši (defer/async po potrebi)
      if (!s.hasAttribute('defer') && !s.hasAttribute('async')) {
        // ništa – append će izvršiti odmah u dokumentu
      }
      old.replaceWith(s); // zamjena trigerira izvršavanje
    }
  }

  function includeOne(el){
    const file = el.getAttribute('data-include');
    if(!file) return Promise.resolve();
    const isAbs = /^(https?:)?\/\//i.test(file) || file.startsWith('/');
    const url   = isAbs ? file : (basePath() + file);
    const base  = isAbs ? file : (location.origin + basePath() + file);

    return doFetch(url).then(res=>{
      if(!res.ok) throw new Error('HTTP '+res.status+' '+url);
      return res.text();
    }).then(html=>{
      // koristimo <template> kako ne bismo iznova parsirali dva puta
      const tpl = document.createElement('template');
      tpl.innerHTML = html.trim();
      el.innerHTML = ''; // očisti staro
      el.appendChild(tpl.content.cloneNode(true));
      // označi i emitiraj event
      el.setAttribute('data-included','1');

      // izvrši skripte iz includanog sadržaja
      executeScripts(el, base);

      // po elementu
      el.dispatchEvent(new CustomEvent('include:loaded', { detail:{ el, file:url }}));
    }).catch(err=>{
      console.error('[include] Greška:', err);
      el.innerHTML='';
    });
  }

  function run(){
    const nodes = document.querySelectorAll('[data-include]');
    const tasks = Array.from(nodes).map(includeOne);

    Promise.allSettled(tasks).then(()=>{
      // globalni event za sve gotovo
      window.dispatchEvent(new CustomEvent('include:all'));
      // nakon što je header došao, pokušaj podesiti “Moj profil”
      safeHeaderAuthWire();
    });
  }

  // Minimalni fallback za header auth gumb — ne smeta ako headerov vlastiti JS to odradi
  async function safeHeaderAuthWire(){
    const btn = document.getElementById('authBtn');
    if(!btn) return;
    try{
      const res = await fetch('/api/auth.php?route=profile', { headers:{'Accept':'application/json'} });
      if(res.ok){
        // logiran
        btn.textContent = 'Uredi profil';
        btn.setAttribute('href','/nudimuslugu.html');
      }else{
        // nelogiran – ostavi “Moj profil”, header modal JS (ako postoji) će preuzeti
        btn.textContent = 'Moj profil';
        btn.setAttribute('href','#');
      }
    }catch(_){
      // na grešku – ništa, ostavi kako jest
    }
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', run);
  }else{
    run();
  }
})();
</script>
