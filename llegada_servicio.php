<?php
// Conductor: "He llegado al lugar" (HU-C03 esc. 3, 6, 7, 8 y 9).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);
$idAsig = (int)($in["id_asignacion"] ?? 0);

try {
    $amb = ambulanciaDelConductor($conn, $idUsuario);
    if (!$amb) responder(["success" => false, "message" => "No tienes una ambulancia asignada."], 404);
    $idAmb = (int)$amb["id_ambulancia"];

    $conn->begin_transaction();
    $st = $conn->prepare("SELECT a.estado, a.fecha_llegada, a.id_incidente, a.id_ambulancia, i.latitud, i.longitud
                          FROM asignaciones a JOIN incidentes i ON i.id_incidente = a.id_incidente
                          WHERE a.id_asignacion = ? FOR UPDATE");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$a || (int)$a["id_ambulancia"] !== $idAmb) fallarTx($conn, "Esta solicitud no corresponde a tu ambulancia.", 403);
    if ($a["estado"] === "PENDIENTE") fallarTx($conn, "No puedes confirmar la llegada de un servicio que todavía no has aceptado.");
    if ($a["estado"] !== "ACEPTADA") fallarTx($conn, "Este servicio ya no está en curso.");
    if ($a["fecha_llegada"] !== null) { // HU-C03 esc. 9: no se duplican eventos
        $conn->rollback();
        responder(["success" => true, "ya_registrada" => true, "message" => "La llegada ya estaba registrada."]);
    }

    // La posición sale del servidor (última ubicación reciente de la unidad), no de lo que diga el teléfono.
    $st = $conn->prepare("SELECT latitud, longitud, TIMESTAMPDIFF(SECOND, fecha_hora, NOW()) AS seg
                          FROM ubicaciones_ambulancia WHERE id_ambulancia = ? ORDER BY id_ubicacion DESC LIMIT 1");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$u || (int)$u["seg"] > SEGUNDOS_SIN_SENAL) {
        fallarTx($conn, "La posición actual no está disponible. Activa el GPS para confirmar la llegada.");
    }
    $km = distanciaKm((float)$u["latitud"], (float)$u["longitud"], (float)$a["latitud"], (float)$a["longitud"]);
    if ($km > RADIO_LLEGADA_KM) {
        fallarTx($conn, "Aún no estás lo suficientemente cerca del lugar del incidente. Acércate para confirmar la llegada.",
                 409, ["distancia_m" => (int)round($km * 1000)]);
    }

    $st = $conn->prepare("UPDATE asignaciones SET fecha_llegada = NOW() WHERE id_asignacion = ? AND fecha_llegada IS NULL");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $st->close();
    $idInc = (int)$a["id_incidente"];
    $st = $conn->prepare("UPDATE incidentes SET estado = 'EN_ATENCION' WHERE id_incidente = ? AND estado = 'ASIGNADO'");
    $st->bind_param("i", $idInc);
    $st->execute();
    $st->close();
    auditar($conn, $idUsuario, "LLEGADA_REGISTRADA", $idAmb, null, ["id_asignacion" => $idAsig, "distancia_m" => (int)round($km * 1000)]);
    $conn->commit();

    responder(["success" => true, "message" => "Llegada registrada correctamente."]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $x) {}
    errorServidor($e);
}
