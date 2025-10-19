document.addEventListener('DOMContentLoaded', async ()=>{
  const includes = document.querySelectorAll('[data-include]');
  for (const el of includes) {
    const file = el.getAttribute('data-include');
    try {
      const res = await fetch(file, { cache: 'no-cache' });
      if (!res.ok) throw new Error(`${file} ${res.status}`);
      const html = await res.text();
      el.innerHTML = html;
    } catch (err) {
      console.error('Include error:', err);
      el.innerHTML = '';
    }
  }
});
