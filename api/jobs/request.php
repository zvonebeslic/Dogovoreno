<?php
// api/jobs/request.php
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../db.php'; // was dp.php → db.php radi konzistentnosti
if (file_exists(__DIR__ . '/../mailer.php')) require_once __DIR__ . '/../mailer.php';

function json_out($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(false,'Method not allowed',405);

$raw = file_get_contents('php://input');
$in  = json_decode($raw,true);
if (!$in) json_out(false,'Neispravan JSON',400);

// Ulaz
$skills = $in['skills']??[];
$desc   = trim((string)($in['description']??''));
$images = $in['images']??[];           // niz URL-ova (upload endpoint ih vraća)
$loc    = $in['location']??null;       // {label, lat, lng}
$uid    = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;

if (!$skills || !$desc || !$loc || !isset($loc['lat'],$loc['lng'])) {
  json_out(false,'Nedostaju obavezna polja',400);
}

// Čišćenje vještina
$skills = array_values(array_unique(array_filter(array_map(static function($s){
  return trim((string)$s);
}, is_array($skills)?$skills:[]))));

// Label ograničimo na 255 (kolona jobs.location_label)
$locLabel = (string)($loc['label'] ?? '');
if (mb_strlen($locLabel) > 255) $locLabel = mb_substr($locLabel, 0, 255);

try{
  $pdo->beginTransaction();

  // (opcija) osiguraj da skills postoje u katalogu (ne ruši se ako nema skills tablice)
  try{
    $chk = $pdo->query("SHOW TABLES LIKE 'skills'");
    if ($chk && $chk->fetchColumn()){
      $insS = $pdo->prepare("INSERT IGNORE INTO skills(name) VALUES (?)");
      foreach ($skills as $s) { if($s!==''){ $insS->execute([$s]); } }
    }
  }catch(Throwable $e){ /* tihi fallback */ }

  // JOB
  $stmt = $pdo->prepare("INSERT INTO jobs (user_id, description, skills_json, location_label, lat, lng, status)
                         VALUES (:uid, :d, :sj, :lbl, :lat, :lng, 'open')");
  $stmt->execute([
    ':uid'=>$uid,
    ':d'=>$desc,
    ':sj'=>json_encode($skills, JSON_UNESCAPED_UNICODE),
    ':lbl'=>$locLabel,
    ':lat'=>(float)$loc['lat'],
    ':lng'=>(float)$loc['lng']
  ]);
  $jobId = (int)$pdo->lastInsertId();

  // Slike (ako ih ima)
  if ($images) {
    $ins = $pdo->prepare("INSERT INTO job_images (job_id, url) VALUES (?, ?)");
    foreach ($images as $u) {
      $u = trim((string)$u);
      if ($u!=='') $ins->execute([$jobId, $u]);
    }
  }

  // Provjeri postoji li provider_skills; ako ne postoji, radimo geo-only fallback
  $hasPS = false;
  try{
    $chk = $pdo->query("SHOW TABLES LIKE 'provider_skills'");
    $hasPS = (bool)($chk && $chk->fetchColumn());
  }catch(Throwable $e){ $hasPS = false; }

  // Pronađi kandidate — prema skillu i udaljenosti (ako imamo provider_skills), inače samo geo
  $params = [ (float)$loc['lng'], (float)$loc['lat'] ];
  if ($hasPS && $skills) {
    // map skill imena → id-evi
    $skillIds = [];
    try {
      $qMarks = implode(',', array_fill(0, count($skills), '?'));
      $s = $pdo->prepare("SELECT id,name FROM skills WHERE name IN ($qMarks)");
      $s->execute($skills);
      while($r=$s->fetch(PDO::FETCH_ASSOC)) $skillIds[]=(int)$r['id'];
    }catch(Throwable $e){ $skillIds = []; }

    if ($skillIds) {
      $inQ = implode(',', array_fill(0, count($skillIds), '?'));
      $sql = "SELECT 
                p.id AS provider_id,
                ANY_VALUE(u.email) AS prov_email,
                ANY_VALUE(u.name)  AS prov_name,
                ANY_VALUE(u.phone) AS prov_phone,
                ROUND(ST_Distance_Sphere(p.geo, ST_SRID(POINT(?, ?), 4326))/1000,2) AS distance_km
              FROM providers p
              JOIN users u ON u.id=p.user_id
              JOIN provider_skills ps ON ps.provider_id=p.id
              WHERE ps.skill_id IN ($inQ)
                AND p.geo IS NOT NULL
                AND u.email IS NOT NULL
              GROUP BY p.id
              ORDER BY distance_km ASC
              LIMIT 60";
      $params = array_merge($params, $skillIds);
    } else {
      // nema mapiranih skill_id-a → fallback: geo-only
      $sql = "SELECT 
                p.id AS provider_id,
                ANY_VALUE(u.email) AS prov_email,
                ANY_VALUE(u.name)  AS prov_name,
                ANY_VALUE(u.phone) AS prov_phone,
                ROUND(ST_Distance_Sphere(p.geo, ST_SRID(POINT(?, ?), 4326))/1000,2) AS distance_km
              FROM providers p
              JOIN users u ON u.id=p.user_id
              WHERE p.geo IS NOT NULL
                AND u.email IS NOT NULL
              GROUP BY p.id
              ORDER BY distance_km ASC
              LIMIT 60";
    }
  } else {
    // fallback: bez skill filtera, samo po geo (i mailu)
    $sql = "SELECT 
              p.id AS provider_id,
              ANY_VALUE(u.email) AS prov_email,
              ANY_VALUE(u.name)  AS prov_name,
              ANY_VALUE(u.phone) AS prov_phone,
              ROUND(ST_Distance_Sphere(p.geo, ST_SRID(POINT(?, ?), 4326))/1000,2) AS distance_km
            FROM providers p
            JOIN users u ON u.id=p.user_id
            WHERE p.geo IS NOT NULL
              AND u.email IS NOT NULL
            GROUP BY p.id
            ORDER BY distance_km ASC
            LIMIT 60";
  }

  $st=$pdo->prepare($sql);
  $st->execute($params);
  $candidates=$st->fetchAll(PDO::FETCH_ASSOC);

  // Zapiši notifikacije + (opcija) pošalji mail kandidatima
  $insertNotif = $pdo->prepare("INSERT IGNORE INTO job_notifications (job_id, provider_id, distance_km, status) VALUES (?,?,?, 'sent')");
  $mailSent = 0;
  $mailCap  = 30; // zaštita: max 30 mailova po zahtjevu

  foreach($candidates as $idx=>$c){
    $pid = (int)$c['provider_id'];
    $dist= $c['distance_km']!==null?(float)$c['distance_km']:null;

    $insertNotif->execute([$jobId, $pid, $dist]);

    // HTML obavijest majstoru (ako mailer postoji i imamo e-mail)
    if (function_exists('send_new_job_to_provider') && !empty($c['prov_email']) && $idx < $mailCap){
      // Grad/oznaka lokacije samo kao label (bez točne adrese)
      $city = $locLabel;
      $cta  = (function_exists('mailer_base_url') ? mailer_base_url() : '/') . '/moj-profil';
      @send_new_job_to_provider($c['prov_email'], [
        'desc'        => $desc,
        'distance_km' => $dist,
        'city'        => $city,
        'cta_url'     => $cta
      ], function_exists('mailer_admin_email') ? mailer_admin_email() : null);
      $mailSent++;
    }
  }

  $pdo->commit();

  json_out(true, [
    'id' => $jobId,
    'candidates_found' => count($candidates),
    'emails_sent' => $mailSent,
    'email_cap' => $mailCap
  ]);
}
catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(false, $e->getMessage(), 500);
}
