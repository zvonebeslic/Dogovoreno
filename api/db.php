<?php
declare(strict_types=1);

$db_host = 'localhost';
$db_name = 'TVOJA_BAZA';
$db_user = 'TVOJ_USER';
$db_pass = 'TVOJ_PASS';

try{
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES   => false,
          PDO::ATTR_PERSISTENT         => true,
          PDO::ATTR_TIMEOUT            => 5
        ]
    );
}
catch(Throwable $e){
    // Ako puca na API poziv (JSON)
    if(stripos($_SERVER['REQUEST_URI'], '/api') !== false){
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'Greška spajanja na bazu'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Inače pokaži osnovnu poruku (za debug)
    die('Greška spajanja na bazu: '.$e->getMessage());
}
