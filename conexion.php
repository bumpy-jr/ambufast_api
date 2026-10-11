<?php

$host = "localhost";
$usuario = "root";
$password = "";
$baseDatos = "ambufast";

$conn = new mysqli(
    $host,
    $usuario,
    $password,
    $baseDatos
);

if ($conn->connect_error) {
    die(json_encode([
        "success" => false,
        "message" => "Error de conexión con MySQL: " . $conn->connect_error
    ]));
}

$conn->set_charset("utf8mb4");

?>