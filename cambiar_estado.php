<?php
// Conductor: pasa su ambulancia entre DISPONIBLE y FUERA_DE_SERVICIO.
// OCUPADA solo la pone/quita el sistema o el administrador (HU-C01, HU-A04).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);
$nuevo = strtoupper(trim((string)($in["estado"] ?? "")));

if (!in_array($nuevo, ["DISPONIBLE", "FUERA_DE_SERVICIO"], true)) {
    responder(["success" => false, "message" => "Estado no válido."], 400);
}

function fallar(mysqli $conn, string $msg, int $codigo = 409): void {
    $conn->rollback();
    responder(["success" => false, "message" => $msg], $codigo);
}

try {
    $conn->begin_transaction();
    $amb = ambulanciaDelConductor($conn, $idUsuario, true);
    if (!$amb) fallar($conn, "No tienes una ambulancia asignada.", 404);

    $actual = $amb["estado"];
    if ($actual === "OCUPADA") {
        fallar($conn, $nuevo === "DISPONIBLE"
            ? "No puedes cambiar a Disponible mientras estás ocupado. Se requiere autorización del administrador."
            : "No puedes marcar la ambulancia como fuera de servicio mientras atiendes un caso.");
    }
    if ($actual === "INCOMPLETA" || !(int)$amb["operativa"]) {
        fallar($conn, "La ambulancia no está operativa. Contacta al administrador.");
    }
    if ($nuevo === "DISPONIBLE" && !(int)$amb["licencia_vigente"]) {
        fallar($conn, "Tu licencia de conducción está vencida. No puedes ponerte disponible.");
    }
    if ($actual === $nuevo) {
        $conn->rollback();
        responder(["success" => true, "estado" => $actual, "message" => "La ambulancia ya estaba en ese estado."]);
    }

    $id = (int)$amb["id_ambulancia"];
    $st = $conn->prepare("UPDATE ambulancias SET estado = ? WHERE id_ambulancia = ?");
    $st->bind_param("si", $nuevo, $id);
    $st->execute();
    $st->close();

    $st = $conn->prepare("INSERT INTO historial_estados_ambulancia
        (id_ambulancia, estado_anterior, estado_nuevo, id_usuario, origen)
        VALUES (?, ?, ?, ?, 'CONDUCTOR')");
    $st->bind_param("issi", $id, $actual, $nuevo, $idUsuario);
    $st->execute();
    $st->close();

    $conn->commit();
    responder(["success" => true, "estado" => $nuevo,
        "message" => $nuevo === "DISPONIBLE" ? "Estado actualizado: Disponible." : "Estado actualizado: Fuera de servicio."]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "No fue posible actualizar el estado de la ambulancia. Intenta nuevamente."], 500);
}
