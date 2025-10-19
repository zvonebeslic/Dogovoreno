(function(){
  function basePath(){ return location.pathname.replace(/[^\/]+$/, ''); }
  function doFetch(url){
    const bust = (url.indexOf('?')>-1 ? '&' : '?') + 'v=' + Date.now();
    return fetch(url + bust, { cache:'no-cache' });
  }
  function includeOne(el){
    const file = el.getAttribute('data-include');
    if(!file) return;
    const isAbs = /^(https?:)?\/\//i.test(file) || file.startsWith('/');
    const url = isAbs ? file : (basePath() + file);
    return doFetch(url).then(res=>{
      if(!res.ok) throw new Error('HTTP '+res.status+' '+url);
      return res.text();
    }).then(html=>{
      el.innerHTML = html;
      el.setAttribute('data-included','1');
    }).catch(_=>{ el.innerHTML=''; });
  }
  function run(){
    const nodes = document.querySelectorAll('[data-include]');
    Promise.allSettled(Array.from(nodes).map(includeOne));
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
