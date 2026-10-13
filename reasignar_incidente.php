<?php
// Administrador: libera la asignación activa de un caso y busca otra unidad, o reinicia la búsqueda de un caso
// que quedó sin unidad (HU-SIST03 esc. 6, 7, 8, 9 y 10; HU-SIST02 esc. 4).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idAdmin = (int)($in["id_usuario"] ?? 0);
$idInc = (int)($in["id_incidente"] ?? 0);

try {
    exigirAdministrador($conn, $idAdmin);
    procesarExpiracionesSeguro($conn);

    $conn->begin_transaction();
    $st = $conn->prepare("SELECT estado FROM incidentes WHERE id_incidente = ? FOR UPDATE");
    $st->bind_param("i", $idInc);
    $st->execute();
    $caso = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$caso) fallarTx($conn, "Incidente no encontrado.", 404);
    if (in_array($caso["estado"], ["CERRADO", "CANCELADO"], true)) {
        fallarTx($conn, "El caso está cerrado o cancelado y no admite una nueva asignación.");
    }

    // ¿Hay una oferta pendiente de respuesta? Debe resolverse antes de asignar otra unidad (esc. 8).
    $st = $conn->prepare("SELECT 1 FROM asignaciones a JOIN notificaciones_asignacion n ON n.id_asignacion = a.id_asignacion
                          WHERE a.id_incidente = ? AND a.estado = 'PENDIENTE' AND n.fecha_expiracion > NOW() LIMIT 1");
    $st->bind_param("i", $idInc);
    $st->execute();
    $pendiente = $st->get_result()->num_rows > 0;
    $st->close();
    if ($pendiente) fallarTx($conn, "Debe liberarse la asignación actual antes de realizar una nueva asignación.");

    // ¿Hay una asignación aceptada? Se libera: la unidad vuelve a Disponible y el caso reabre la búsqueda.
    $st = $conn->prepare("SELECT id_asignacion, id_ambulancia FROM asignaciones
                          WHERE id_incidente = ? AND estado = 'ACEPTADA' AND fecha_finalizacion IS NULL
                          ORDER BY id_asignacion DESC LIMIT 1 FOR UPDATE");
    $st->bind_param("i", $idInc);
    $st->execute();
    $activa = $st->get_result()->fetch_assoc();
    $st->close();

    $excluir = [];
    $liberada = false;
    if ($activa) {
        $idA = (int)$activa["id_asignacion"];
        $idAmb = (int)$activa["id_ambulancia"];
        $st = $conn->prepare("UPDATE asignaciones SET estado = 'LIBERADA', fecha_finalizacion = NOW() WHERE id_asignacion = ?");
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();
        $st = $conn->prepare("UPDATE bloqueos_ambulancia SET estado = 'LIBERADO', fecha_fin_real = NOW(), motivo = 'ASIGNACION_LIBERADA'
                              WHERE id_asignacion = ? AND estado = 'ACTIVO'");
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();

        $st = $conn->prepare("SELECT estado, operativa FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
        $st->bind_param("i", $idAmb);
        $st->execute();
        $amb = $st->get_result()->fetch_assoc();
        $st->close();
        if ($amb && $amb["estado"] === "OCUPADA") {
            $nuevo = (int)$amb["operativa"] ? "DISPONIBLE" : "INCOMPLETA";
            $st = $conn->prepare("UPDATE ambulancias SET estado = ? WHERE id_ambulancia = ?");
            $st->bind_param("si", $nuevo, $idAmb);
            $st->execute();
            $st->close();
            registrarCambioEstado($conn, $idAmb, "OCUPADA", $nuevo, $idAdmin, "ADMIN", "Asignación liberada para reasignar");
        }
        $st = $conn->prepare("UPDATE incidentes SET estado = 'PENDIENTE' WHERE id_incidente = ?");
        $st->bind_param("i", $idInc);
        $st->execute();
        $st->close();
        auditar($conn, $idAdmin, "LIBERAR_ASIGNACION", $idAmb, ["id_asignacion" => $idA], ["id_incidente" => $idInc]);
        $excluir = [$idAmb];
        $liberada = true;
    }
    $conn->commit();

    $r = reasignarIncidente($conn, $idInc, true, $excluir);
    if ($r["resultado"] === "YA_ASIGNADO") { // otra petición simultánea ya dejó una asignación para este caso
        responder(["success" => false, "message" => "Este caso ya tiene una ambulancia asignada."]);
    }
    $ok = $r["resultado"] === "ASIGNADA";
    $msg = $ok
        ? ($liberada ? "Asignación liberada. Se notificó a otra unidad." : "Búsqueda reiniciada. Se notificó a una unidad.")
        : "No hay otra ambulancia disponible. El caso requiere atención del supervisor.";
    responder(["success" => true, "asignada" => $ok, "resultado" => $r["resultado"], "message" => $msg]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $x) {}
    errorServidor($e);
}
