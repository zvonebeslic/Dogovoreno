<?php
// api/jobs/request.php
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../dp.php';
if (file_exists(__DIR__ . '/../mailer.php')) require_once __DIR__ . '/../mailer.php';

function json_out($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(false,'Method not allowed',405);

$raw = file_get_contents('php://input');
$in  = json_decode($raw,true);
if (!$in) json_out(false,'Neispravan JSON',400);

$skills = $in['skills']??[];
$desc   = trim((string)($in['description']??''));
$images = $in['images']??[];
$loc    = $in['location']??null;
if (!$skills || !$desc || !$loc || !isset($loc['lat'],$loc['lng'])) json_out(false,'Nedostaju obavezna polja',400);

try{
  $pdo->beginTransaction();

  // JOB
  $stmt = $pdo->prepare("INSERT INTO jobs (user_id, description, skills_json, location_label, lat, lng, status)
                         VALUES (NULL, :d, :sj, :lbl, :lat, :lng, 'open')");
  $stmt->execute([
    ':d'=>$desc,
    ':sj'=>json_encode(array_values($skills), JSON_UNESCAPED_UNICODE),
    ':lbl'=>(string)($loc['label']??''),
    ':lat'=>(float)$loc['lat'],
    ':lng'=>(float)$loc['lng']
  ]);
  $jobId = (int)$pdo->lastInsertId();

  // Slike
  if ($images) {
    $ins = $pdo->prepare("INSERT INTO job_images (job_id, url) VALUES (?, ?)");
    foreach ($images as $u) $ins->execute([$jobId, (string)$u]);
  }

  // Mapiranje skill imena u id-eve
  $skillIds = [];
  if ($skills) {
    $inQ = implode(',', array_fill(0, count($skills), '?'));
    $s = $pdo->prepare("SELECT id,name FROM skills WHERE name IN ($inQ)");
    $s->execute(array_values($skills));
    while($r=$s->fetch(PDO::FETCH_ASSOC)) $skillIds[]=(int)$r['id'];
  }

  // Kandidati prema skillu i udaljenosti
  if ($skillIds) {
    $inQ = implode(',', array_fill(0, count($skillIds), '?'));
    $sql = "SELECT p.id provider_id,
                   ROUND(ST_Distance_Sphere(p.geo, ST_SRID(POINT(?, ?), 4326))/1000,2) AS distance_km
            FROM providers p
            JOIN provider_skills ps ON ps.provider_id=p.id
            WHERE ps.skill_id IN ($inQ) AND p.geo IS NOT NULL
            GROUP BY p.id
            ORDER BY distance_km ASC
            LIMIT 60";
    $params = [ (float)$loc['lng'], (float)$loc['lat'], ...$skillIds ];
  } else {
    $sql = "SELECT p.id provider_id,
                   ROUND(ST_Distance_Sphere(p.geo, ST_SRID(POINT(?, ?), 4326))/1000,2) AS distance_km
            FROM providers p
            WHERE p.geo IS NOT NULL
            ORDER BY distance_km ASC
            LIMIT 60";
    $params = [ (float)$loc['lng'], (float)$loc['lat'] ];
  }
  $st=$pdo->prepare($sql); $st->execute($params);
  $candidates=$st->fetchAll(PDO::FETCH_ASSOC);

  if ($candidates) {
    $jn = $pdo->prepare("INSERT IGNORE INTO job_notifications (job_id, provider_id, distance_km, status) VALUES (?,?,?, 'sent')");
    foreach($candidates as $c){
      $jn->execute([$jobId, (int)$c['provider_id'], $c['distance_km']!==null?(float)$c['distance_km']:null]);
      // (opcija) mail providera — vidi prethodnu poruku
    }
  }

  $pdo->commit();
  echo json_encode(['id'=>$jobId], JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
