<?php
// Utilidades compartidas por los endpoints nuevos (S7-S9).
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/conexion.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Segundos sin recibir ubicación para considerar al conductor "sin señal".
const SEGUNDOS_SIN_SENAL = 90;

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function leerEntrada(): array {
    $d = json_decode(file_get_contents("php://input"), true);
    if (!is_array($d)) {
        responder(["success" => false, "message" => "No se recibieron datos."], 400);
    }
    return $d;
}

function errorServidor(Throwable $e): void {
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "Ocurrió un error en el servidor. Intenta nuevamente."], 500);
}

// Ambulancia con conductor activo asociado a este usuario (o null).
function ambulanciaDelConductor(mysqli $conn, int $idUsuario, bool $bloquear = false): ?array {
    $sql = "SELECT a.id_ambulancia, a.codigo_unidad, a.placa, a.capacidad, a.equipamiento,
                   a.estado, a.operativa, a.gps_activo,
                   c.id_conductor, c.fecha_vencimiento_licencia,
                   (c.fecha_vencimiento_licencia >= CURDATE()) AS licencia_vigente
            FROM conductores c
            JOIN asignaciones_conductor_ambulancia aca
                 ON aca.id_conductor = c.id_conductor AND aca.activa = 1
            JOIN ambulancias a ON a.id_ambulancia = aca.id_ambulancia
            WHERE c.id_usuario = ? AND c.activo = 1
            LIMIT 1" . ($bloquear ? " FOR UPDATE" : "");
    $st = $conn->prepare($sql);
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ?: null;
}

function exigirAdministrador(mysqli $conn, int $idUsuario): void {
    $st = $conn->prepare("SELECT 1 FROM usuarios u
        JOIN roles r ON r.id_rol = u.id_rol
        JOIN administradores ad ON ad.id_usuario = u.id_usuario
        WHERE u.id_usuario = ? AND u.activo = 1 AND r.nombre = 'ADMINISTRADOR' LIMIT 1");
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$ok) {
        responder(["success" => false, "message" => "No tienes permisos de administrador."], 403);
    }
}

function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}
