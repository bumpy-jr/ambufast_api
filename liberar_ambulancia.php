<?php
// Administrador: libera una ambulancia ocupada (HU-A04 esc. 5, 6, 10, 12, 13, 14, 18).
// Si el bloqueo aún está vigente exige confirmación; al confirmar queda como "Liberación forzada" en auditoría.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idAdmin = (int)($in["id_usuario"] ?? 0);
$idAmb = (int)($in["id_ambulancia"] ?? 0);
$confirmada = ($in["forzada"] ?? false) === true;

try {
    exigirAdministrador($conn, $idAdmin);
    $conn->begin_transaction();

    $st = $conn->prepare("SELECT estado, operativa FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $amb = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$amb) fallarTx($conn, "Ambulancia no encontrada.", 404);
    if ($amb["estado"] !== "OCUPADA") fallarTx($conn, "La ambulancia no está ocupada, no es necesario liberarla.");

    $bloq = bloqueoActivo($conn, $idAmb, true);
    $vigente = $bloq && (int)$bloq["segundos_restantes"] > 0;

    if ($vigente && !$confirmada) {
        $min = (int)ceil((int)$bloq["segundos_restantes"] / 60);
        fallarTx($conn,
            "Esta ambulancia está bloqueada durante $min minutos. Se requiere autorización para realizar una liberación o asignación excepcional.",
            409, ["requiere_confirmacion" => true, "minutos_restantes" => $min]);
    }

    $forzada = $vigente;
    if ($bloq) {
        $motivo = $forzada ? "LIBERACION_FORZADA" : "LIBERACION_ADMIN";
        $st = $conn->prepare("UPDATE bloqueos_ambulancia SET estado = 'LIBERADO', fecha_fin_real = NOW(), motivo = ? WHERE id_bloqueo = ?");
        $idB = (int)$bloq["id_bloqueo"];
        $st->bind_param("si", $motivo, $idB);
        $st->execute();
        $st->close();
        // El servicio concluye: la asignación queda finalizada y el caso cerrado. Para seguir atendiendo el caso
        // con otra unidad se usa reasignar_incidente.php.
        $st = $conn->prepare("UPDATE asignaciones SET estado = 'FINALIZADA', fecha_finalizacion = COALESCE(fecha_finalizacion, NOW()) WHERE id_asignacion = ?");
        $idA = (int)$bloq["id_asignacion"];
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();
        $st = $conn->prepare("UPDATE incidentes i JOIN asignaciones a ON a.id_incidente = i.id_incidente
                              SET i.estado = 'CERRADO' WHERE a.id_asignacion = ? AND i.estado IN ('ASIGNADO','EN_ATENCION')");
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();
    }

    // Una unidad no operativa (p. ej. licencia vencida mientras atendía) no vuelve a Disponible.
    $nuevo = (int)$amb["operativa"] ? "DISPONIBLE" : "INCOMPLETA";
    $st = $conn->prepare("UPDATE ambulancias SET estado = ? WHERE id_ambulancia = ?");
    $st->bind_param("si", $nuevo, $idAmb);
    $st->execute();
    $st->close();

    registrarCambioEstado($conn, $idAmb, "OCUPADA", $nuevo, $idAdmin, "ADMIN", $forzada ? "Liberación forzada" : "Liberación autorizada");
    auditar($conn, $idAdmin, $forzada ? "LIBERACION_FORZADA" : "LIBERACION", $idAmb,
            ["estado" => "OCUPADA", "segundos_restantes" => $bloq ? (int)$bloq["segundos_restantes"] : null],
            ["estado" => $nuevo]);

    $conn->commit();
    responder(["success" => true, "estado" => $nuevo, "forzada" => $forzada,
               "message" => "Ambulancia liberada y disponible para nuevas asignaciones."]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "No fue posible liberar la ambulancia. Verifica el estado e inténtalo nuevamente."], 500);
}
