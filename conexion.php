<?php

$host = getenv('DB_HOST');
$usuario = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$baseDatos = getenv('DB_NAME');
$port = getenv('DB_PORT') ?: '3306';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $usuario, $password, $baseDatos, (int)$port);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die(json_encode([
        "success" => false,
        "message" => "Error de conexión con MySQL: " . $e->getMessage()
    ]));
}

?>