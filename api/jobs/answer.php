<?php
// api/jobs/answer.php
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../dp.php';
if (file_exists(__DIR__ . '/../mailer.php')) require_once __DIR__ . '/../mailer.php';

function json_out($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(false,'Method not allowed',405);

$in = json_decode(file_get_contents('php://input'), true);
$notifId = (int)($in['notification_id'] ?? 0);
$status  = $in['status'] ?? ''; // 'interested' | 'not_interested'

if (!$notifId || !in_array($status,['interested','not_interested'],true)) {
  json_out(false,'Pogrešan payload',400);
}

try{
  $pdo->beginTransaction();

  // Lockaj notifikaciju i povuci kontekst (posao + provider + user)
  $q = $pdo->prepare("
    SELECT n.id, n.job_id, n.provider_id, n.distance_km,
           u.name AS prov_name, u.phone AS prov_phone, u.email AS prov_email,
           j.user_id AS client_user_id, j.description AS job_desc, j.location_label
    FROM job_notifications n
    JOIN providers p ON p.id = n.provider_id
    JOIN users u ON u.id = p.user_id
    JOIN jobs j ON j.id = n.job_id
    WHERE n.id = ?
    FOR UPDATE
  ");
  $q->execute([$notifId]);
  $n = $q->fetch(PDO::FETCH_ASSOC);
  if(!$n){
    $pdo->rollBack();
    json_out(false,'Notifikacija ne postoji',404);
  }

  // 1) Ažuriraj status notifikacije
  $u = $pdo->prepare("UPDATE job_notifications SET status=:s, responded_at=NOW() WHERE id=:id");
  $u->execute([':s'=>$status, ':id'=>$notifId]);

  // 2) Upsert u job_matches (živi tok)
  //    - unique key: (job_id, provider_id)
  $sel = $pdo->prepare("SELECT id FROM job_matches WHERE job_id=? AND provider_id=?");
  $sel->execute([(int)$n['job_id'], (int)$n['provider_id']]);
  $jmId = $sel->fetchColumn();

  if ($jmId) {
    $upd = $pdo->prepare("UPDATE job_matches
                          SET status=?, updated_at=NOW()
                          WHERE id=?");
    $upd->execute([$status, (int)$jmId]);
  } else {
    $ins = $pdo->prepare("INSERT INTO job_matches
      (job_id, provider_id, notification_id, status, created_at, distance_km, message_excerpt)
      VALUES (?,?,?,?,NOW(),?,?)");
    $ins->execute([
      (int)$n['job_id'],
      (int)$n['provider_id'],
      (int)$n['id'],
      $status,
      $n['distance_km'] !== null ? (float)$n['distance_km'] : null,
      mb_substr((string)$n['job_desc'], 0, 200)
    ]);
    $jmId = (int)$pdo->lastInsertId();
  }

  // 3) Ako je interested → javi klijentu (ako je registriran i ima e-mail)
  $mailSent = false;
  if ($status === 'interested' && !empty($n['client_user_id']) && function_exists('send_good_news_to_client')) {
    $qe = $pdo->prepare("SELECT email, name FROM users WHERE id=?");
    $qe->execute([(int)$n['client_user_id']]);
    if ($client = $qe->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($client['email'])) {
        // CTA može voditi na “moji zahtjevi”
        $cta = (function_exists('mailer_base_url') ? mailer_base_url() : '/') . '/moji-zahtjevi';
        $mailSent = @send_good_news_to_client($client['email'], [
          'provider_name'  => (string)$n['prov_name'],
          'provider_phone' => (string)$n['prov_phone'],
          'job_desc'       => (string)$n['job_desc'],
          'cta_url'        => $cta
        ], function_exists('mailer_admin_email') ? mailer_admin_email() : null);
      }
    }
  }

  $pdo->commit();
  json_out(true, [
    'ok' => true,
    'notification_id' => (int)$n['id'],
    'job_id' => (int)$n['job_id'],
    'provider_id' => (int)$n['provider_id'],
    'match_id' => (int)$jmId,
    'status' => $status,
    'mail_sent' => (bool)$mailSent
  ]);
}
catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(false, $e->getMessage(), 500);
}
