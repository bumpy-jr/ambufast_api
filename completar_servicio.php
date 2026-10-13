<?php
// Conductor: "Completar servicio". Cierra el caso, libera el bloqueo y deja la unidad Disponible.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);
$idAsig = (int)($in["id_asignacion"] ?? 0);

try {
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

    // Orden de bloqueo: incidente -> asignación -> unidad.
    $conn->begin_transaction();
    $st = $conn->prepare("SELECT estado FROM incidentes WHERE id_incidente = ? FOR UPDATE");
    $st->bind_param("i", $idInc);
    $st->execute();
    $st->close();
    $st = $conn->prepare("SELECT estado, fecha_llegada FROM asignaciones WHERE id_asignacion = ? FOR UPDATE");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();

    if (in_array($a["estado"], ["COMPLETADA", "FINALIZADA"], true)) {
        $conn->rollback();
        responder(["success" => true, "ya_completado" => true, "message" => "El servicio ya fue completado."]);
    }
    if ($a["estado"] !== "ACEPTADA") fallarTx($conn, "Este servicio ya no está en curso.");
    if ($a["fecha_llegada"] === null) fallarTx($conn, "Confirma tu llegada al lugar antes de completar el servicio.");

    $st = $conn->prepare("UPDATE asignaciones SET estado = 'COMPLETADA', fecha_finalizacion = NOW() WHERE id_asignacion = ?");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE bloqueos_ambulancia SET estado = 'LIBERADO', fecha_fin_real = NOW(), motivo = 'SERVICIO_COMPLETADO'
                          WHERE id_asignacion = ? AND estado = 'ACTIVO'");
    $st->bind_param("i", $idAsig);
    $st->execute();
    $st->close();
    $st = $conn->prepare("UPDATE incidentes SET estado = 'CERRADO' WHERE id_incidente = ?");
    $st->bind_param("i", $idInc);
    $st->execute();
    $st->close();

    $st = $conn->prepare("SELECT estado, operativa FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    $nuevo = $u["estado"];
    if ($u["estado"] === "OCUPADA") {
        $nuevo = (int)$u["operativa"] ? "DISPONIBLE" : "INCOMPLETA";
        $st = $conn->prepare("UPDATE ambulancias SET estado = ? WHERE id_ambulancia = ?");
        $st->bind_param("si", $nuevo, $idAmb);
        $st->execute();
        $st->close();
        registrarCambioEstado($conn, $idAmb, "OCUPADA", $nuevo, $idUsuario, "CONDUCTOR", "Servicio completado");
    }
    auditar($conn, $idUsuario, "COMPLETAR_SERVICIO", $idAmb, ["estado" => "OCUPADA"], ["estado" => $nuevo, "id_asignacion" => $idAsig]);
    $conn->commit();

    responder(["success" => true, "estado" => $nuevo,
        "message" => $nuevo === "DISPONIBLE"
            ? "Servicio completado. Ya estás disponible para nuevas emergencias."
            : "Servicio completado. Tu ambulancia no está operativa; contacta al administrador."]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $x) {}
    errorServidor($e);
}
