<script>
document.addEventListener('DOMContentLoaded', async ()=>{
  const includes = document.querySelectorAll('[data-include]');
  for(const el of includes){
    const file = el.getAttribute('data-include');
    try{
      const res = await fetch(file, {cache:'no-cache'});
      el.innerHTML = await res.text();
    }catch(_){ el.innerHTML = ''; }
  }
});
</script>
