<script>
document.addEventListener('DOMContentLoaded', async () => {
  const includes = document.querySelectorAll('[data-include]');
  for (const el of includes) {
    const url = el.getAttribute('data-include');
    try{
      const res = await fetch(url, {cache:'no-cache'});
      el.innerHTML = await res.text();
    }catch(_){
      el.innerHTML = '<!-- include failed: ' + url + ' -->';
    }
  }
});
</script>
