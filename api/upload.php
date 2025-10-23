<?php
// api/upload.php (hardened)
declare(strict_types=1);
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

const MAX_MB     = 5;                       // max 5 MB po slici
const MAX_FILES  = 6;                       // uskladi s frontendom
const ALLOWED    = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

$uploadDir = dirname(__DIR__) . '/uploads';

// Robustno izračunaj URL path prema app rootu (radi i ako je /app/public/api/upload.php)
$scriptDir  = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api')),'/'); // npr. /api
$basePath   = rtrim(substr($scriptDir, 0, strrpos($scriptDir, '/')), '/') ?: '';           // parent od /api -> ''
$uploadsUrl = $basePath . '/uploads';                                                      // npr. /uploads

if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

function j($ok, $data=[], $code=null){
  if($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') j(false, 'Method not allowed', 405);
if (empty($_FILES['images'])) j(false, 'Nema datoteka (images[])', 400);

// Normaliziraj $_FILES u listu
$files = [];
if (is_array($_FILES['images']['name'])) {
  $count = count($_FILES['images']['name']);
  for ($i=0; $i<$count; $i++) {
    $files[] = [
      'name' => $_FILES['images']['name'][$i] ?? '',
      'type' => $_FILES['images']['type'][$i] ?? '',
      'tmp'  => $_FILES['images']['tmp_name'][$i] ?? '',
      'err'  => (int)($_FILES['images']['error'][$i] ?? 0),
      'size' => (int)($_FILES['images']['size'][$i] ?? 0),
    ];
  }
} else {
  $files[] = [
    'name' => $_FILES['images']['name'] ?? '',
    'type' => $_FILES['images']['type'] ?? '',
    'tmp'  => $_FILES['images']['tmp_name'] ?? '',
    'err'  => (int)($_FILES['images']['error'] ?? 0),
    'size' => (int)($_FILES['images']['size'] ?? 0),
  ];
}

if (count($files) > MAX_FILES) {
  j(false, 'Maksimalno '.MAX_FILES.' slika po zahtjevu', 400);
}

$urls = [];
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

foreach ($files as $f) {
  if ($f['err'] !== UPLOAD_ERR_OK)        j(false, 'Greška u uploadu (kod '.$f['err'].')', 400);
  if (!is_uploaded_file($f['tmp']))       j(false, 'Neispravna datoteka', 400);
  if ($f['size'] > MAX_MB*1024*1024)      j(false, 'Prevelika slika (> '.MAX_MB.'MB)', 400);

  // MIME provjera (finfo > mime_content_type)
  $type = $finfo ? finfo_file($finfo, $f['tmp']) : (mime_content_type($f['tmp']) ?: '');
  if (!isset(ALLOWED[$type]))             j(false, 'Nepodržan format: '.$type, 400);

  // Dodatna provjera da je slika
  $imgInfo = @getimagesize($f['tmp']);
  if ($imgInfo === false)                 j(false, 'Datoteka nije valjana slika', 400);

  // Naziv fajla
  $ext  = ALLOWED[$type];
  $hash = bin2hex(random_bytes(6));
  $name = date('Ymd_His') . "_$hash.$ext";
  $dest = $uploadDir . '/' . $name;

  if (!@move_uploaded_file($f['tmp'], $dest)) {
    j(false, 'Upload nije uspio', 500);
  }

  // (opcija) postavi permisije
  @chmod($dest, 0644);

  $urls[] = $uploadsUrl . '/' . $name;
}

if ($finfo) finfo_close($finfo);

j(true, ['urls'=>$urls]);
