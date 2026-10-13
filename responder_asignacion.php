<?php
// Conductor: acepta o rechaza la emergencia que se le asignó (HU-C02 esc. 2, 3, 8 y 9).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);
$idAsig = (int)($in["id_asignacion"] ?? 0);
$accion = strtolower(trim((string)($in["accion"] ?? "")));

if (!in_array($accion, ["aceptar", "rechazar"], true)) {
    responder(["success" => false, "message" => "Acción no válida."], 400);
}

try {
    procesarExpiracionesSeguro($conn);
    $amb = ambulanciaDelConductor($conn, $idUsuario);
    if (!$amb) responder(["success" => false, "message" => "No tienes una ambulancia asignada."], 404);
    $idAmb = (int)$amb["id_ambulancia"];

    $st = $conn->prepare("SELECT id_incidente, id_ambulancia FROM asignaciones WHERE id_asignacion = ?");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $base = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$base || (int)$base["id_ambulancia"] !== $idAmb) {
        responder(["success" => false, "message" => "Esta solicitud no corresponde a tu ambulancia."], 403);
    }
    $idInc = (int)$base["id_incidente"];

    // Orden de bloqueo: incidente -> asignación -> unidad (igual que la búsqueda y la reasignación).
    $conn->begin_transaction();
    $st = $conn->prepare("SELECT estado FROM incidentes WHERE id_incidente = ? FOR UPDATE");
    $st->bind_param("i", $idInc);
    $st->execute();
    $estadoCaso = $st->get_result()->fetch_assoc()["estado"] ?? null;
    $st->close();

    $st = $conn->prepare("SELECT a.estado, TIMESTAMPDIFF(SECOND, NOW(), n.fecha_expiracion) AS segundos, a.eta_minutos
                          FROM asignaciones a JOIN notificaciones_asignacion n ON n.id_asignacion = a.id_asignacion
                          WHERE a.id_asignacion = ? FOR UPDATE");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();
    $vigente = $a && $a["estado"] === "PENDIENTE" && (int)$a["segundos"] > 0;

    if ($a && $a["estado"] === "ACEPTADA" && $accion === "aceptar") { // doble toque: el servicio ya es suyo
        $conn->rollback();
        responder(["success" => true, "message" => "Ya habías aceptado este servicio. Dirígete al lugar del incidente."]);
    }

    if (!$vigente) {
        $conn->rollback();
        if ($accion === "aceptar") {
            responder(["success" => false, "expirada" => true,
                "message" => "El tiempo para aceptar este servicio ha finalizado. El caso puede haber sido reasignado."], 409);
        }
        // HU-C02 esc. 9: un rechazo tardío no modifica la asignación vigente de otra unidad.
        responder(["success" => true, "expirada" => true,
            "message" => "Tu respuesta llegó fuera de tiempo. La asignación no fue modificada."]);
    }

    if ($accion === "rechazar") {
        $st = $conn->prepare("UPDATE asignaciones SET estado = 'RECHAZADA', fecha_rechazo = NOW() WHERE id_asignacion = ?");
        $st->bind_param("i", $idAsig);
        $st->execute();
        $st->close();
        $st = $conn->prepare("UPDATE notificaciones_asignacion SET respuesta = 'RECHAZADA', fecha_respuesta = NOW() WHERE id_asignacion = ?");
        $st->bind_param("i", $idAsig);
        $st->execute();
        $st->close();
        auditar($conn, $idUsuario, "RECHAZAR_SERVICIO", $idAmb, null, ["id_asignacion" => $idAsig, "id_incidente" => $idInc]);
        $conn->commit();

        try { reasignarIncidente($conn, $idInc); }
        catch (Throwable $e) { try { $conn->rollback(); } catch (Throwable $x) {} error_log("[ambufast] reasignar tras rechazo: " . $e->getMessage()); }
        responder(["success" => true, "message" => "Servicio rechazado. Buscando otra ambulancia disponible."]);
    }

    // ---- aceptar ----
    if ($estadoCaso !== "PENDIENTE") {
        $conn->rollback();
        responder(["success" => false, "message" => "Este caso ya no admite una nueva asignación."], 409);
    }
    $st = $conn->prepare("SELECT 1 FROM asignaciones WHERE id_incidente = ? AND estado = 'ACEPTADA' LIMIT 1");
    $st->bind_param("i", $idInc);
    $st->execute();
    $yaTiene = $st->get_result()->num_rows > 0;
    $st->close();
    if ($yaTiene) {
        $conn->rollback();
        responder(["success" => false, "message" => "Este caso ya tiene una ambulancia asignada."], 409);
    }

    $st = $conn->prepare("SELECT estado FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $estadoAmb = $st->get_result()->fetch_assoc()["estado"] ?? null;
    $st->close();
    if ($estadoAmb !== "DISPONIBLE") {
        $conn->rollback();
        responder(["success" => false, "message" => "Tu ambulancia no está disponible para atender este servicio."], 409);
    }

    $st = $conn->prepare("UPDATE asignaciones SET estado = 'ACEPTADA', fecha_aceptacion = NOW() WHERE id_asignacion = ?");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE notificaciones_asignacion SET respuesta = 'ACEPTADA', fecha_respuesta = NOW() WHERE id_asignacion = ?");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE incidentes SET estado = 'ASIGNADO' WHERE id_incidente = ?");
    $st->bind_param("i", $idInc);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE ambulancias SET estado = 'OCUPADA' WHERE id_ambulancia = ?");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $st->close();
    registrarCambioEstado($conn, $idAmb, "DISPONIBLE", "OCUPADA", $idUsuario, "SISTEMA", "Servicio aceptado");
    iniciarBloqueo($conn, $idAmb, $idAsig, (int)$a["eta_minutos"] + MINUTOS_ATENCION);
    auditar($conn, $idUsuario, "ACEPTAR_SERVICIO", $idAmb, ["estado" => "DISPONIBLE"], ["estado" => "OCUPADA", "id_asignacion" => $idAsig, "id_incidente" => $idInc]);
    $conn->commit();

    responder(["success" => true, "message" => "Emergencia aceptada. Dirígete al lugar del incidente."]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $x) {}
    errorServidor($e);
}
