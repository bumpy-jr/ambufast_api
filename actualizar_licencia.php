<?php
// Administrador: renueva la licencia del conductor de una ambulancia. Si estaba Incompleta por
// licencia vencida, queda operativa y disponible (HU-A01 esc. 15 y 16).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idAdmin = (int)($in["id_usuario"] ?? 0);
$idAmb = (int)($in["id_ambulancia"] ?? 0);
$fecha = trim((string)($in["fecha"] ?? ""));

if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    responder(["success" => false, "message" => "La fecha de vencimiento de la licencia es obligatoria."], 400);
}

try {
    exigirAdministrador($conn, $idAdmin);
    $conn->begin_transaction();

    $st = $conn->prepare("SELECT a.estado, a.operativa, c.id_conductor, c.fecha_vencimiento_licencia
                          FROM ambulancias a
                          LEFT JOIN asignaciones_conductor_ambulancia aca ON aca.id_ambulancia = a.id_ambulancia AND aca.activa = 1
                          LEFT JOIN conductores c ON c.id_conductor = aca.id_conductor
                          WHERE a.id_ambulancia = ? FOR UPDATE");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$f) fallarTx($conn, "Ambulancia no encontrada.", 404);
    if ($f["id_conductor"] === null) fallarTx($conn, "La ambulancia no tiene conductor asignado.");

    $st = $conn->prepare("SELECT (? >= CURDATE()) AS vigente");
    $st->bind_param("s", $fecha);
    $st->execute();
    $vigente = (int)$st->get_result()->fetch_assoc()["vigente"] === 1;
    $st->close();
    if (!$vigente) {
        fallarTx($conn, "La licencia del conductor se encuentra vencida. Verifique la información antes de continuar.", 400);
    }

    $idC = (int)$f["id_conductor"];
    $st = $conn->prepare("UPDATE conductores SET fecha_vencimiento_licencia = ? WHERE id_conductor = ?");
    $st->bind_param("si", $fecha, $idC);
    $st->execute();
    $st->close();

    $reactivada = $f["estado"] === "INCOMPLETA";
    if ($reactivada) {
        $st = $conn->prepare("UPDATE ambulancias SET operativa = 1, estado = 'DISPONIBLE' WHERE id_ambulancia = ?");
        $st->bind_param("i", $idAmb);
        $st->execute();
        $st->close();
        registrarCambioEstado($conn, $idAmb, "INCOMPLETA", "DISPONIBLE", $idAdmin, "ADMIN", "Licencia renovada");
    }
    auditar($conn, $idAdmin, "RENOVAR_LICENCIA", $idAmb,
            ["fecha_vencimiento_licencia" => $f["fecha_vencimiento_licencia"], "estado" => $f["estado"]],
            ["fecha_vencimiento_licencia" => $fecha, "estado" => $reactivada ? "DISPONIBLE" : $f["estado"]]);

    $conn->commit();
    responder(["success" => true, "reactivada" => $reactivada,
        "message" => $reactivada
            ? "Licencia actualizada. La ambulancia quedó operativa y disponible para asignación."
            : "Licencia actualizada correctamente."]);
} catch (Throwable $e) {
    $conn->rollback();
    errorServidor($e);
}
