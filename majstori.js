// majstori.js — frontend utili za pretragu / detalje / kontakt

(function(){
  // Mali helper za fetch JSON
  async function getJSON(url){
    const r = await fetch(url, {cache:'no-cache'});
    const j = await r.json().catch(()=> ({}));
    if(!r.ok) throw new Error(j.error || ('HTTP '+r.status));
    return j;
  }

  // Kreiraj URL za /providers prema filterima
  function providersUrl(filters={}){
    const params = new URLSearchParams();
    if (filters.skill)     params.set('skill', filters.skill);             // single skill
    if (filters.location)  params.set('location', filters.location);       // javna oznaka (država / grad)
    if (Number.isFinite(filters.qlat))      params.set('qlat', filters.qlat);
    if (Number.isFinite(filters.qlng))      params.set('qlng', filters.qlng);
    if (Number.isFinite(filters.radius_km)) params.set('radius_km', filters.radius_km);
    return 'api/auth.php?route=providers&' + params.toString();
  }

  // Deduplikacija po id
  function uniqById(list){
    const seen = new Set();
    const out = [];
    for(const x of list){
      if(x && x.id!=null && !seen.has(x.id)){
        seen.add(x.id); out.push(x);
      }
    }
    return out;
  }

  // Sortiraj (ako API vrati distance_km, po njoj; inače po updated_at opadajuće ako postoji)
  function sortResults(list){
    const withDist = list.some(x => typeof x.distance_km === 'number');
    return list.slice().sort((a,b)=>{
      if (withDist){
        const da = (typeof a.distance_km==='number') ? a.distance_km : Infinity;
        const db = (typeof b.distance_km==='number') ? b.distance_km : Infinity;
        if (da !== db) return da - db;
      }
      const ta = a.updated_at ? new Date(a.updated_at).getTime() : 0;
      const tb = b.updated_at ? new Date(b.updated_at).getTime() : 0;
      return tb - ta;
    });
  }

  // Geolokacija korisnika (promisified)
  function geolocate(options={enableHighAccuracy:true, timeout:10000}){
    return new Promise((resolve,reject)=>{
      if(!navigator.geolocation) return reject(new Error('Preglednik ne podržava geolokaciju.'));
      navigator.geolocation.getCurrentPosition(
        pos => resolve({lat:+pos.coords.latitude.toFixed(6), lng:+pos.coords.longitude.toFixed(6)}),
        err => reject(new Error('GPS greška: '+err.message)),
        options
      );
    });
  }

  // Glavna: dohvat providera
  // Podržava:
  //   - filters.skill   (string)      -> jedan upit
  //   - filters.skills  (array)       -> više upita (spajamo rezultate)
  //   - filters.match_all (bool)      -> ako true i filters.skills postoji: zadrži samo one koji sadrže SVE tražene vještine
  //   - filters.location (string)     -> "BiH / Sarajevo" (opcija)
  //   - filters.qlat, filters.qlng, filters.radius_km
  //
  // Napomena: backend trenutačno prima samo JEDAN `skill` parametar; za više vještina radimo paralelne upite i spajamo client-side.
  async function fetchProviders(filters={}){
    const skills = Array.isArray(filters.skills) ? filters.skills.filter(Boolean) : null;

    // Jednostavan slučaj — jedan skill ili bez skill filtera
    if (!skills || skills.length <= 1){
      const url = providersUrl({
        skill:    skills ? skills[0] : (filters.skill || ''),
        location: filters.location || '',
        qlat:     filters.qlat,
        qlng:     filters.qlng,
        radius_km:filters.radius_km
      });
      const j = await getJSON(url);
      const data = Array.isArray(j.data) ? j.data : [];
      return sortResults(uniqById(data));
    }

    // Više vještina → paralelni upiti i spajanje
    const qs = skills.map(s => providersUrl({
      skill: s,
      location: filters.location || '',
      qlat: filters.qlat,
      qlng: filters.qlng,
      radius_km: filters.radius_km
    }));

    const pages = await Promise.allSettled(qs.map(getJSON));
    let merged = [];
    for (const p of pages){
      if (p.status === 'fulfilled' && Array.isArray(p.value.data)){
        merged = merged.concat(p.value.data);
      }
    }
    merged = uniqById(merged);

    // Ako se traži STROGI match na SVE vještine → filtriraj
    if (filters.match_all){
      const wanted = new Set(skills.map(x => x.toLowerCase()));
      merged = merged.filter(p=>{
        const arr = Array.isArray(p.skills) ? p.skills : [];
        const have = new Set(arr.map(x => String(x||'').toLowerCase()));
        for(const w of wanted){ if(!have.has(w)) return false; }
        return true;
      });
    }

    return sortResults(merged);
  }

  // Detalj providera
  async function fetchProvider(id){
    const j = await getJSON('api/auth.php?route=provider&id='+encodeURIComponent(id));
    return j.data || null;
  }

  // Slanje upita majstoru (ista ruta koju već koristiš)
  async function contactProvider({provider_id, from_name, from_phone, from_email='', message}){
    const r = await fetch('api/auth.php?route=contact', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({provider_id, from_name, from_phone, from_email, message})
    });
    const j = await r.json().catch(()=> ({}));
    if(!r.ok) throw new Error(j.error || 'Greška slanja');
    return true;
  }

  // Public API
  window.Majstori = {
    geolocate,
    fetchProviders,
    fetchProvider,
    contactProvider
  };
})();
</script>
