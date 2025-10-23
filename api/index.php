<?php
// api/index.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// CORS (po potrebi olabavi/zakini)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/dp.php';       // $pdo = new PDO(...)
if (file_exists(__DIR__ . '/mailer.php')) require_once __DIR__ . '/mailer.php';
// auth.php zadržiš za login/registraciju; ovdje samo router

function json_out($ok, $data=[], $code=null){
  if ($code) http_response_code($code);
  echo json_encode($ok ? $data : ['error'=>$data], JSON_UNESCAPED_UNICODE); exit;
}

// Route parsing
$uri = strtok($_SERVER['REQUEST_URI'],'?');              // npr. /api/jobs/request
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');    // /api
$path = ltrim(substr($uri, strlen($base)), '/');        // jobs/request

// Health
if ($path==='' || $path==='ping') { echo json_encode(['ok'=>true,'ts'=>time()]); exit; }

// Dispatch map
switch ($path) {
  case 'upload':
    require __DIR__ . '/upload.php';
    exit;

  case 'jobs/request':
    require __DIR__ . '/jobs/request.php';
    exit;

  case 'jobs/answer':
    require __DIR__ . '/jobs/answer.php';
    exit;

  // (opcija) inbox za providera
  case 'jobs/inbox':
    require __DIR__ . '/jobs/inbox.php';
    exit;

  // auth rute proslijedi na postojeći auth.php (ako ih zoveš kao /api/auth?route=...)
  case 'auth':
    require __DIR__ . '/auth.php';
    exit;

  default:
    json_out(false, 'Ruta ne postoji: '.$path, 404);
}
