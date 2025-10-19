<?php
$db_host = 'localhost';
$db_name = 'TVOJA_BAZA';
$db_user = 'TVOJ_USER';
$db_pass = 'TVOJ_PASS';

$pdo = new PDO(
  "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
  $db_user, $db_pass,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
