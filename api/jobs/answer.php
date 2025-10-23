<?php
// api/jobs/answer.php
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../dp.php';
if (file_exists(__DIR__ . '/../mailer.php')) require_once __DIR__ . '/../mailer.php';

function json_out($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(false,'Method not allowed',405);

$in = json_decode(file_get_contents('php://input'), true);
$notifId = (int)($in['notification_id'] ?? 0);
$status  = $in['status'] ?? '';
if (!$notifId || !in_array($status,['interested','not_interested'],true))
  json_out(false,'Pogrešan payload',400);

try{
  $pdo->beginTransaction();

  $q = $pdo->prepare("
    SELECT n.id, n.job_id, n.provider_id, u.name prov_name, u.phone prov_phone,
           j.user_id, j.description
    FROM job_notifications n
    JOIN providers p ON p.id=n.provider_id
    JOIN users u ON u.id=p.user_id
    JOIN jobs j ON j.id=n.job_id
    WHERE n.id=? FOR UPDATE
  ");
  $q->execute([$notifId]);
  $n=$q->fetch(PDO::FETCH_ASSOC);
  if(!$n){ $pdo->rollBack(); json_out(false,'Notifikacija ne postoji',404); }

  $u=$pdo->prepare("UPDATE job_notifications SET status=:s, responded_at=NOW() WHERE id=:id");
  $u->execute([':s'=>$status, ':id'=>$notifId]);

  // Ako interested → email klijentu (ako registriran)
  if ($status==='interested' && $n['user_id']) {
    $qe=$pdo->prepare("SELECT email,name FROM users WHERE id=?");
    $qe->execute([(int)$n['user_id']]);
    if ($client=$qe->fetch(PDO::FETCH_ASSOC) and function_exists('mailer_send')) {
      $sub='Imamo dobre vijesti — majstor je zainteresiran (Dogovoreno.com)';
      $html='<p>Pronašli smo Vam majstora koji je spreman odraditi uslugu.</p>'
           .'<p><strong>'.htmlspecialchars($n['prov_name']).'</strong><br>'
           .'Telefon: <strong>'.htmlspecialchars($n['prov_phone']).'</strong></p>'
           .'<p>Opis posla: '.htmlspecialchars(mb_substr((string)$n['description'],0,200)).'</p>';
      @mailer_send($client['email'],$sub,$html);
    }
  }

  $pdo->commit();
  json_out(true, ['ok'=>true]);
}catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(false, $e->getMessage(), 500);
}
