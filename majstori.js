<script>
window.Majstori = {
  async fetchProviders(filters={}){
    const params = new URLSearchParams();
    if(filters.skill) params.set('skill', filters.skill);
    if(filters.location) params.set('location', filters.location);
    if(Number.isFinite(filters.qlat)) params.set('qlat', filters.qlat);
    if(Number.isFinite(filters.qlng)) params.set('qlng', filters.qlng);
    if(Number.isFinite(filters.radius_km)) params.set('radius_km', filters.radius_km);

    const r = await fetch('api/auth.php?route=providers&'+params.toString());
    const j = await r.json();
    if(!r.ok) throw new Error(j.error||'Greška');
    return j.data || [];
  }
};
</script>
