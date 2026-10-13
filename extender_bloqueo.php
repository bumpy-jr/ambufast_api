<?php
// Administrador: extiende el tiempo estimado de ocupación (HU-A04 esc. 7, 8, 10, 17).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idAdmin = (int)($in["id_usuario"] ?? 0);
$idAmb = (int)($in["id_ambulancia"] ?? 0);
$minutos = (int)($in["minutos"] ?? 0);

if ($minutos < 5 || $minutos > 240) {
    responder(["success" => false, "message" => "Ingresa un tiempo de extensión válido (entre 5 y 240 minutos)."], 400);
}

try {
    exigirAdministrador($conn, $idAdmin);
    $conn->begin_transaction();

    $bloq = bloqueoActivo($conn, $idAmb, true);
    if (!$bloq) fallarTx($conn, "La ambulancia no tiene un bloqueo activo.");

    $antes = $bloq["fecha_fin_estimada"];
    // Si el tiempo ya venció, se extiende desde ahora; si no, se suma al fin estimado.
    $st = $conn->prepare("UPDATE bloqueos_ambulancia
                          SET fecha_fin_estimada = DATE_ADD(GREATEST(fecha_fin_estimada, NOW()), INTERVAL ? MINUTE)
                          WHERE id_bloqueo = ?");
    $idB = (int)$bloq["id_bloqueo"];
    $st->bind_param("ii", $minutos, $idB);
    $st->execute();
    $st->close();

    $st = $conn->prepare("SELECT fecha_fin_estimada, TIMESTAMPDIFF(SECOND, NOW(), fecha_fin_estimada) AS seg
                          FROM bloqueos_ambulancia WHERE id_bloqueo = ?");
    $st->bind_param("i", $idB);
    $st->execute();
    $nuevo = $st->get_result()->fetch_assoc();
    $st->close();

    auditar($conn, $idAdmin, "EXTENSION_BLOQUEO", $idAmb,
            ["fecha_fin_estimada" => $antes],
            ["fecha_fin_estimada" => $nuevo["fecha_fin_estimada"], "minutos_agregados" => $minutos]);

    $conn->commit();
    responder(["success" => true, "minutos_restantes" => (int)ceil((int)$nuevo["seg"] / 60),
               "message" => "Tiempo de ocupación extendido correctamente."]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "No fue posible extender el tiempo de ocupación."], 500);
}
