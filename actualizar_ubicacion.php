<?php
// Conductor: guarda su posición actual. Con gps_activo=false solo marca el GPS como apagado.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);

try {
    $amb = ambulanciaDelConductor($conn, $idUsuario);
    if (!$amb) responder(["success" => false, "message" => "No tienes una ambulancia asignada."], 404);
    $id = (int)$amb["id_ambulancia"];

    if (array_key_exists("gps_activo", $in) && $in["gps_activo"] === false) {
        $st = $conn->prepare("UPDATE ambulancias SET gps_activo = 0 WHERE id_ambulancia = ?");
        $st->bind_param("i", $id);
        $st->execute();
        responder(["success" => true, "message" => "GPS marcado como inactivo."]);
    }

    if (!is_numeric($in["latitud"] ?? null) || !is_numeric($in["longitud"] ?? null)) {
        responder(["success" => false, "message" => "Coordenadas no válidas."], 400);
    }
    $lat = (float)$in["latitud"];
    $lng = (float)$in["longitud"];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        responder(["success" => false, "message" => "Coordenadas fuera de rango."], 400);
    }
    $precision = is_numeric($in["precision"] ?? null) ? (float)$in["precision"] : null;

    $conn->begin_transaction();
    $st = $conn->prepare("INSERT INTO ubicaciones_ambulancia (id_ambulancia, latitud, longitud, precision_metros, conectado)
                          VALUES (?, ?, ?, ?, 1)");
    $st->bind_param("iddd", $id, $lat, $lng, $precision);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE ambulancias SET gps_activo = 1 WHERE id_ambulancia = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();
    $conn->commit();

    responder(["success" => true]);
} catch (Throwable $e) { $conn->rollback(); errorServidor($e); }
