<?php
// api/upload.php
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

$MAX_MB = 5;
$ALLOWED = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$uploadDir = dirname(__DIR__) . '/uploads';
$baseUrl   = rtrim(dirname($_SERVER['SCRIPT_NAME'],2),'/') . '/uploads';

if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

function json_out($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(false, 'Method not allowed', 405);
if (empty($_FILES['images'])) json_out(false, 'Nema datoteka (images[])', 400);

$urls = [];
foreach ($_FILES['images']['tmp_name'] as $i=>$tmp) {
  if (!is_uploaded_file($tmp)) continue;
  $type = mime_content_type($tmp) ?: '';
  if (!isset($ALLOWED[$type])) json_out(false, 'Nepodržan format: '.$type, 400);
  $size = (int)($_FILES['images']['size'][$i] ?? 0);
  if ($size > $MAX_MB*1024*1024) json_out(false, 'Prevelika slika (> '.$MAX_MB.'MB)', 400);

  $ext  = $ALLOWED[$type];
  $hash = bin2hex(random_bytes(6));
  $name = date('Ymd_His') . "_$hash.$ext";
  $dest = $uploadDir . '/' . $name;

  if (!move_uploaded_file($tmp, $dest)) json_out(false, 'Upload nije uspio', 500);
  $urls[] = $baseUrl . '/' . $name;
}
json_out(true, ['urls'=>$urls]);
