(function(){
  // helper: izračunaj bazu trenutnog puta (npr. /Dogovoreno/)
  function basePath(){
    // npr. /Dogovoreno/trazimuslugu.html -> /Dogovoreno/
    return location.pathname.replace(/[^\/]+$/, '');
  }
  function log(){ try{ console.log.apply(console, arguments); }catch(_){} }
  function warn(){ try{ console.warn.apply(console, arguments); }catch(_){} }

  function doFetch(url){
    // no-cache + bust param
    const bust = (url.indexOf('?')>-1 ? '&' : '?') + 'v=' + Date.now();
    return fetch(url + bust, { cache:'no-cache' });
  }

  function includeOne(el){
    const file = el.getAttribute('data-include');
    if(!file){ return; }
    // Ako je putanja relativna (nema http/https i ne počinje sa /), dodaj basePath
    const isAbs = /^(https?:)?\/\//i.test(file) || file.startsWith('/');
    const url = isAbs ? file : (basePath() + file);

    log('[include] →', file, '→', url);

    return doFetch(url)
      .then(res=>{
        if(!res.ok) throw new Error('HTTP '+res.status+' '+url);
        return res.text();
      })
      .then(html=>{
        el.innerHTML = html;
        // označi kao učitano
        el.setAttribute('data-included', '1');
        log('[include] OK:', url);
      })
      .catch(err=>{
        warn('[include] FAIL:', err.message || err, 'for', url);
        el.innerHTML = ''; // ili možeš staviti user-friendly fallback
      });
  }

  function run(){
    var nodes = document.querySelectorAll('[data-include]');
    if(!nodes.length){
      log('[include] Nema elemenata s data-include.');
      return;
    }
    var jobs = [];
    for(var i=0;i<nodes.length;i++){
      jobs.push(includeOne(nodes[i]));
    }
    Promise.allSettled(jobs).then(()=>log('[include] gotovo.'));
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', run);
  }else{
    run();
  }
})();
