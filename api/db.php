<?php
declare(strict_types=1);

/**
 * db.php — centralni PDO bootstrap
 * - čita kredencijale iz ENV-a (ako postoje), inače koristi fallback vrijednosti ispod
 * - podržava port ili unix socket
 * - postavlja siguran sql_mode i time_zone po sesiji
 * - ima kratki retry (npr. kod cold starta MySQL-a)
 */

$DB_HOST   = getenv('DB_HOST')   ?: 'localhost';
$DB_NAME   = getenv('DB_NAME')   ?: 'TVOJA_BAZA';
$DB_USER   = getenv('DB_USER')   ?: 'TVOJ_USER';
$DB_PASS   = getenv('DB_PASS')   ?: 'TVOJ_PASS';
$DB_PORT   = getenv('DB_PORT')   ?: null;              // npr. '3306'
$DB_SOCKET = getenv('DB_SOCKET') ?: null;              // npr. '/var/run/mysqld/mysqld.sock'

// Sastavi DSN (prioritet: SOCKET > HOST[:PORT])
if ($DB_SOCKET) {
  $dsn = "mysql:unix_socket={$DB_SOCKET};dbname={$DB_NAME};charset=utf8mb4";
} else {
  $host = $DB_HOST . ($DB_PORT ? ";port={$DB_PORT}" : '');
  $dsn  = "mysql:host={$host};dbname={$DB_NAME};charset=utf8mb4";
}

$pdoOptions = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
  PDO::ATTR_PERSISTENT         => true,   // možeš prebaciti na false ako koristiš puno kratkih PHP-FPM worker-a
  PDO::ATTR_TIMEOUT            => 5
];

/** Mali helper za izlaz kao JSON kad se poziva preko /api */
function db_json_fail(string $msg, int $code=500): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($code);
  echo json_encode(['error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$retries = 2; // ukupno 1 pokušaj + 2 retry-a
do {
  try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $pdoOptions);

    // Po sesiji postavi siguran sql_mode i time_zone (nije globalno)
    // - ONLY_FULL_GROUP_BY i STRICT modovi smanjuju tihe bugove
    // - time_zone '+00:00' olakšava backend logiku (front neka brine o lokalizaciji)
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("
      SET SESSION sql_mode =
        'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'
    ");

    // Ako smo ovdje, sve je prošlo
    break;
  } catch (Throwable $e) {
    if ($retries-- > 0) {
      // kratko pričekaj pa probaj opet (npr. kad se DB tek diže)
      usleep(250_000); // 250 ms
      continue;
    }

    // Ako puca na API ruti, vrati JSON; inače plain tekst
    $isApi = (stripos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
    if ($isApi) {
      db_json_fail('Greška spajanja na bazu');
    } else {
      // za ne-API stranice pokaži kratku poruku (po želji sakrij $e->getMessage() u produkciji)
      if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
      http_response_code(500);
      echo 'Greška spajanja na bazu.';
      // echo "\n\nDetalji: ".$e->getMessage(); // OTKOMENTIRAJ samo u DEV-u
      exit;
    }
  }
} while (true);
