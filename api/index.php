<?php
// api/index.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// CORS (po potrebi prilagodi na svoju domenu umjesto * )
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// === PDO konekcija i mailer (ako postoji) ===
require_once __DIR__ . '/db.php';                 // FIX: db.php (nije dp.php)
if (file_exists(__DIR__ . '/mailer.php')) {
  require_once __DIR__ . '/mailer.php';
}

// standardni JSON izlaz
function json_out(bool $ok, $data = [], ?int $code = null){
  if ($code !== null) http_response_code($code);
  echo json_encode($ok ? $data : ['error' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}

// --- Normalizacija rute ---
// Primjer: /api/jobs/request  -> "jobs/request"
$uri  = strtok($_SERVER['REQUEST_URI'], '?');
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');   // obično "/api"
$path = ltrim(substr($uri, strlen($base)), '/');        // npr. "jobs/request" ili "auth"
$path = rtrim($path, '/');                              // skini trailing slash

// Health/ping
if ($path === '' || $path === 'ping') {
  json_out(true, ['ok'=>true, 'ts'=>time()]);
}

// Dozvoli i varijante sa .php (npr. "jobs/request.php")
if (str_ends_with($path, '.php')) {
  $path = substr($path, 0, -4);
}

// --- DISPATCH ---
try {
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

    // (opcija) inbox za providera — kreirat ćeš kasnije
    case 'jobs/inbox':
      require __DIR__ . '/jobs/inbox.php';
      exit;

    // Auth rute: i dalje koristiš postojeći auth.php koji sam ti ranije dotjerao.
    // Ovo omogućuje pozive kao /api/auth?route=login itd.
    case 'auth':
      require __DIR__ . '/auth.php';
      exit;

    default:
      json_out(false, 'Ruta ne postoji: '.$path, 404);
  }
}
catch (Throwable $e) {
  // Globalni fallback da API ne pukne s HTML-om
  json_out(false, 'Interna greška: '.$e->getMessage(), 500);
}
