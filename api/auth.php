<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

require __DIR__.'/db.php';
require __DIR__.'/mailer.php';

$route  = $_GET['route'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function json_out($x, int $code=200){
  http_response_code($code);
  echo json_encode($x, JSON_UNESCAPED_UNICODE);
  exit;
}
function token64(){ return bin2hex(random_bytes(32)); }
function base_url(){
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  return $scheme.'://'.$_SERVER['HTTP_HOST'];
}

/* ---------- REGISTER ---------- */
if ($route==='register' && $method==='POST'){
  global $pdo, $input;
  $email = strtolower(trim($input['email'] ?? ''));
  $pass  = $input['password'] ?? '';
  $name  = trim($input['name'] ?? '');
  $phone = trim($input['phone'] ?? '');

  if(!$email || !$pass || !$name || !$phone) json_out(['error'=>'Nedostaju polja'],422);
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error'=>'Neispravan e-mail'],422);
  if(strlen($pass) < 8) json_out(['error'=>'Lozinka mora imati min. 8 znakova'],422);

  $st=$pdo->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$email]);
  if($st->fetch()) json_out(['error'=>'E-mail već postoji'],409);

  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $tok  = token64();
  $pdo->prepare('INSERT INTO users(email,pass_hash,name,phone,verify_token) VALUES(?,?,?,?,?)')
      ->execute([$email,$hash,$name,$phone,$tok]);

  $link = base_url().'/api/auth.php?route=verify&token='.$tok;
  send_verification_email($email, $link);

  json_out(['ok'=>true,'message'=>'Provjerite e-mail za potvrdu.']);
}

/* ---------- VERIFY EMAIL (GET) ---------- */
if ($route==='verify' && $method==='GET'){
  global $pdo;
  $tok = $_GET['token'] ?? '';
  if(!$tok){ echo 'Nevažeći token.'; exit; }
  $st=$pdo->prepare('SELECT id FROM users WHERE verify_token=?'); $st->execute([$tok]);
  $u=$st->fetch(PDO::FETCH_ASSOC);
  if(!$u){ echo 'Nevažeći ili iskorišten token.'; exit; }
  $pdo->prepare('UPDATE users SET verified=1, verify_token=NULL WHERE id=?')->execute([$u['id']]);
  echo 'E-mail potvrđen. Sada se možete prijaviti.'; exit;
}

/* ---------- LOGIN (email ILI ime ILI telefon) ---------- */
if ($route==='login' && $method==='POST'){
  global $pdo, $input;
  $identifier = trim($input['identifier'] ?? '');
  $pass       = $input['password'] ?? '';
  if(!$identifier || !$pass) json_out(['error'=>'Nedostaju polja'],422);

  // redoslijed: email -> phone -> name
  $st=$pdo->prepare('SELECT id,pass_hash,verified,email,name,phone FROM users WHERE LOWER(email)=LOWER(?)');
  $st->execute([$identifier]);
  $u=$st->fetch(PDO::FETCH_ASSOC);

  if(!$u){
    $st=$pdo->prepare('SELECT id,pass_hash,verified,email,name,phone FROM users WHERE REPLACE(phone," ","")=REPLACE(?," ","")');
    $st->execute([$identifier]);
    $u=$st->fetch(PDO::FETCH_ASSOC);
  }
  if(!$u){
    $st=$pdo->prepare('SELECT id,pass_hash,verified,email,name,phone FROM users WHERE LOWER(name)=LOWER(?)');
    $st->execute([$identifier]);
    $u=$st->fetch(PDO::FETCH_ASSOC);
  }

  if(!$u || !password_verify($pass, $u['pass_hash'])) json_out(['error'=>'Pogrešan podatak ili lozinka'],401);
  if(!$u['verified']) json_out(['error'=>'E-mail nije potvrđen'],403);

  $_SESSION['uid']=$u['id'];
  json_out(['ok'=>true]);
}

/* ---------- LOGOUT ---------- */
if ($route==='logout'){
  session_destroy();
  json_out(['ok'=>true]);
}

/* ---------- PROFILE (GET/PUT) ---------- */
if ($route==='profile'){
  if(!isset($_SESSION['uid'])) json_out(['error'=>'Nije prijavljen'],401);
  $uid = (int)$_SESSION['uid'];

  if($method==='GET'){
    $st=$pdo->prepare('SELECT u.email,u.name,u.phone,p.id as provider_id,p.skills,p.location,p.bio,p.reviews_enabled,p.quiet_from,p.quiet_to,p.lat,p.lng
                       FROM users u LEFT JOIN providers p ON p.user_id=u.id WHERE u.id=?');
    $st->execute([$uid]);
    $row=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    if(isset($row['skills']) && $row['skills']) $row['skills']=json_decode($row['skills'],true);
    json_out(['data'=>$row]);
  }

  if($method==='PUT'){
    $name = trim($input['name'] ?? '');
    $phone= trim($input['phone'] ?? '');
    $skills = $input['skills'] ?? [];
    $location = trim($input['location'] ?? '');
    $bio = trim($input['bio'] ?? '');
    $reviews = !empty($input['reviews_enabled']) ? 1 : 0;
    $qf = $input['quiet_from'] ?? '18:00';
    $qt = $input['quiet_to'] ?? '07:00';
    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;

    if(!$name || !$phone) json_out(['error'=>'Ime i telefon su obavezni'],422);

    // 1) update users
    $pdo->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name,$phone,$uid]);

    // 2) providers: SELECT pa INSERT/UPDATE (bez ON DUPLICATE KEY)
    $st=$pdo->prepare('SELECT id FROM providers WHERE user_id=?'); $st->execute([$uid]);
    $provId = $st->fetchColumn();

    $skillsJson = json_encode(array_values(array_unique(is_array($skills)?$skills:[])), JSON_UNESCAPED_UNICODE);

    if ($provId){
      $pdo->prepare('UPDATE providers SET skills=?, location=?, bio=?, reviews_enabled=?, quiet_from=?, quiet_to=?, lat=?, lng=? WHERE id=?')
          ->execute([$skillsJson,$location,$bio,$reviews,$qf,$qt,$lat,$lng,$provId]);
    }else{
      $pdo->prepare('INSERT INTO providers(user_id,skills,location,bio,reviews_enabled,quiet_from,quiet_to,lat,lng)
                     VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$uid,$skillsJson,$location,$bio,$reviews,$qf,$qt,$lat,$lng]);
      $provId = (int)$pdo->lastInsertId();
    }

    // 3) (opcija) sinkronizacija u normalizirane tablice skills/provider_skills ako postoje
    try{
      if (is_array($skills) && count($skills)){
        // osiguraj da postoje skilovi
        $sel = $pdo->query("SHOW TABLES LIKE 'skills'"); $hasSkills = (bool)$sel->fetchColumn();
        $sel = $pdo->query("SHOW TABLES LIKE 'provider_skills'"); $hasPS = (bool)$sel->fetchColumn();
        if ($hasSkills && $hasPS){
          // upiši nove skillove u katalog (ako ne postoje)
          $insS = $pdo->prepare("INSERT IGNORE INTO skills(name) VALUES (?)");
          foreach ($skills as $s){ $s = trim((string)$s); if($s!==''){ $insS->execute([$s]); } }
          // zbriši stare mape pa upiši nove
          $pdo->prepare("DELETE FROM provider_skills WHERE provider_id=?")->execute([$provId]);
          $inQ = implode(',', array_fill(0, count($skills), '?'));
          $q = $pdo->prepare("SELECT id,name FROM skills WHERE name IN ($inQ)");
          $q->execute(array_values($skills));
          $rows = $q->fetchAll(PDO::FETCH_ASSOC);
          $insPS = $pdo->prepare("INSERT IGNORE INTO provider_skills(provider_id, skill_id) VALUES (?,?)");
          foreach ($rows as $r){ $insPS->execute([$provId, (int)$r['id']]); }
        }
      }
    }catch(Throwable $e){ /* tihi fallback */ }

    json_out(['ok'=>true]);
  }
}

/* ---------- PUBLIC: LIST PROVIDERS (GET) + HAVERSINE ---------- */
if ($route==='providers' && $method==='GET'){
  global $pdo;
  $skill = trim($_GET['skill'] ?? '');
  $loc   = trim($_GET['location'] ?? '');
  $qlat  = isset($_GET['qlat']) ? (float)$_GET['qlat'] : null;
  $qlng  = isset($_GET['qlng']) ? (float)$_GET['qlng'] : null;
  $radkm = isset($_GET['radius_km']) ? (float)$_GET['radius_km'] : null;

  // Bazna polja
  $fields = 'p.id, u.name, u.phone, p.skills, p.location, p.bio, p.reviews_enabled, p.updated_at';
  $where = [];
  $args  = [];

  // Filtar po tekstualnoj lokaciji (opcija)
  if($loc){ $where[] = 'LOWER(p.location) LIKE ?'; $args[] = '%'.strtolower($loc).'%'; }

  // Filtar po skillu (string u JSON-u -> radi i prije normalizacije)
  if($skill){ $where[] = 'LOWER(p.skills) LIKE ?'; $args[] = '%'.strtolower($skill).'%'; }

  $distanceJoin = '';
  $having = '';
  if ($qlat !== null && $qlng !== null){
    // izračun distance_km u SELECT-u (alias)
    $fields .= ', (6371 * acos( cos(radians(?)) * cos(radians(p.lat)) * cos(radians(p.lng) - radians(?)) + sin(radians(?)) * sin(radians(p.lat)) )) AS distance_km';
    array_push($args, $qlat, $qlng, $qlat);

    // HAVING koristi alias (izbjegavamo duplo vezanje istih placeholdera)
    if ($radkm){ $having = ' HAVING distance_km <= ?'; $args[] = $radkm; }
  }

  $sql = 'SELECT '.$fields.' FROM providers p JOIN users u ON u.id=p.user_id';
  if($where){ $sql .= ' WHERE '.implode(' AND ', $where); }
  if($having){ $sql .= $having; }
  $sql .= ($qlat!==null && $qlng!==null) ? ' ORDER BY distance_km ASC, p.updated_at DESC' : ' ORDER BY p.updated_at DESC';
  $sql .= ' LIMIT 200';

  $st=$pdo->prepare($sql); $st->execute($args);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as &$r){
    $r['skills'] = $r['skills'] ? json_decode($r['skills'],true) : [];
    if(isset($r['distance_km'])) $r['distance_km'] = round((float)$r['distance_km'], 1);
    unset($r['phone']); // telefon ne izlažemo javno u listi
  }
  json_out(['data'=>$rows]);
}

/* ---------- PUBLIC: PROVIDER DETAIL (GET) ---------- */
if ($route==='provider' && $method==='GET'){
  global $pdo;
  $id = (int)($_GET['id'] ?? 0);
  if(!$id) json_out(['error'=>'Nedostaje id'],422);

  $st=$pdo->prepare('SELECT p.id, u.name, u.phone, u.email, p.skills, p.location, p.bio, p.reviews_enabled, p.updated_at, p.lat, p.lng
                     FROM providers p JOIN users u ON u.id=p.user_id WHERE p.id=?');
  $st->execute([$id]);
  $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row) json_out(['error'=>'Nije pronađeno'],404);
  $row['skills'] = $row['skills'] ? json_decode($row['skills'],true) : [];
  unset($row['email']); // email ne vraćamo javno
  json_out(['data'=>$row]);
}

/* ---------- PUBLIC: CONTACT PROVIDER (POST) ---------- */
if ($route==='contact' && $method==='POST'){
  global $pdo, $input;
  $pid = (int)($input['provider_id'] ?? 0);
  $from_name  = trim($input['from_name'] ?? '');
  $from_phone = trim($input['from_phone'] ?? '');
  $from_email = trim($input['from_email'] ?? '');
  $message    = trim($input['message'] ?? '');

  if(!$pid || !$from_name || !$from_phone || !$message) json_out(['error'=>'Nedostaju polja'],422);

  $st=$pdo->prepare('SELECT u.email, u.name as prov_name FROM providers p JOIN users u ON u.id=p.user_id WHERE p.id=?');
  $st->execute([$pid]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row) json_out(['error'=>'Majstor nije pronađen'],404);

  $to = $row['email'];
  $subj = 'Novi upit — Dogovoreno';
  $body = "Imate novi upit od klijenta:\n\nIme: $from_name\nTelefon: $from_phone\nEmail: $from_email\n\nPoruka:\n$message\n\n— Dogovoreno.com";
  $ok = send_contact_email($to, $subj, $body);

  // kopija administratoru (opcija)
  @send_contact_email('info@'.$_SERVER['HTTP_HOST'], 'Kopija upita — '.$row['prov_name'], $body);

  if(!$ok) json_out(['error'=>'Slanje e-maila nije uspjelo'],500);
  json_out(['ok'=>true]);
}

json_out(['error'=>'Ruta ne postoji'],404);
