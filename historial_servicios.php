<?php
// Administrador: historial de servicios de solo lectura con filtros combinables (HU-A03).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();

function fechaOk(?string $f): bool {
    return $f !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

try {
    exigirAdministrador($conn, (int)($in["id_usuario"] ?? 0));

    $donde = [];
    $tipos = "";
    $vals = [];
    $desde = $in["desde"] ?? null;
    $hasta = $in["hasta"] ?? null;
    if ($desde !== null && $desde !== "") {
        if (!fechaOk($desde)) responder(["success" => false, "message" => "La fecha inicial no es válida."], 400);
        $donde[] = "i.fecha_reporte >= ?"; $tipos .= "s"; $vals[] = "$desde 00:00:00";
    }
    if ($hasta !== null && $hasta !== "") {
        if (!fechaOk($hasta)) responder(["success" => false, "message" => "La fecha final no es válida."], 400);
        $donde[] = "i.fecha_reporte <= ?"; $tipos .= "s"; $vals[] = "$hasta 23:59:59";
    }
    if (!empty($in["id_ambulancia"])) {
        $donde[] = "s.id_ambulancia = ?"; $tipos .= "i"; $vals[] = (int)$in["id_ambulancia"];
    }
    if (!empty($in["gravedad"])) {
        $g = strtoupper((string)$in["gravedad"]);
        if (!in_array($g, GRAVEDADES, true)) responder(["success" => false, "message" => "La gravedad indicada no es válida."], 400);
        $donde[] = "i.gravedad = ?"; $tipos .= "s"; $vals[] = $g;
    }

    $sql = "SELECT i.id_incidente, i.direccion, i.gravedad, i.numero_heridos, i.estado, i.fecha_reporte,
                   s.estado AS estado_asignacion, s.fecha_aceptacion, s.fecha_llegada, s.fecha_finalizacion, s.id_ambulancia,
                   am.codigo_unidad,
                   (SELECT CONCAT(u2.nombres, ' ', u2.apellidos)
                      FROM asignaciones_conductor_ambulancia x
                      JOIN conductores c2 ON c2.id_conductor = x.id_conductor JOIN usuarios u2 ON u2.id_usuario = c2.id_usuario
                     WHERE x.id_ambulancia = s.id_ambulancia
                       AND x.fecha_inicio <= COALESCE(s.fecha_aceptacion, s.fecha_asignacion, NOW())
                       AND (x.fecha_fin IS NULL OR x.fecha_fin >= COALESCE(s.fecha_aceptacion, s.fecha_asignacion, NOW()))
                     ORDER BY x.fecha_inicio DESC LIMIT 1) AS conductor,
                   TIMESTAMPDIFF(SECOND, i.fecha_reporte, s.fecha_aceptacion) AS seg_aceptacion,
                   TIMESTAMPDIFF(SECOND, i.fecha_reporte, s.fecha_llegada) AS seg_llegada,
                   TIMESTAMPDIFF(SECOND, s.fecha_aceptacion, s.fecha_finalizacion) AS seg_servicio
            FROM incidentes i
            LEFT JOIN asignaciones s ON s.id_asignacion =
                   (SELECT id_asignacion FROM asignaciones WHERE id_incidente = i.id_incidente
                    ORDER BY (fecha_aceptacion IS NULL), id_asignacion DESC LIMIT 1)
            LEFT JOIN ambulancias am ON am.id_ambulancia = s.id_ambulancia"
         . ($donde ? " WHERE " . implode(" AND ", $donde) : "")
         . " ORDER BY i.id_incidente DESC LIMIT 200";

    $st = $conn->prepare($sql);
    if ($vals) $st->bind_param($tipos, ...$vals);
    $st->execute();
    $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $lista = array_map(function ($f) {
        $final = in_array($f["estado_asignacion"], ["COMPLETADA", "FINALIZADA"], true);
        $abierto = !in_array($f["estado"], ["CERRADO", "CANCELADO"], true);
        return [
            "id_incidente" => (int)$f["id_incidente"],
            "fecha" => $f["fecha_reporte"],
            "direccion" => $f["direccion"],
            "gravedad" => $f["gravedad"],
            "heridos" => (int)$f["numero_heridos"],
            "ambulancia" => $f["codigo_unidad"],
            "conductor" => $f["conductor"],
            "estado" => $f["estado"],
            "estado_final" => $final ? "COMPLETADO" : ($abierto ? "PENDIENTE" : $f["estado"]),
            "pendiente" => $abierto,
            "min_aceptacion" => $f["seg_aceptacion"] === null ? null : (int)round((int)$f["seg_aceptacion"] / 60),
            "min_llegada" => $f["seg_llegada"] === null ? null : (int)round((int)$f["seg_llegada"] / 60),
            "min_servicio" => $f["seg_servicio"] === null ? null : (int)round((int)$f["seg_servicio"] / 60),
        ];
    }, $filas);

    responder(["success" => true, "total" => count($lista), "servicios" => $lista]);
} catch (Throwable $e) {
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "No fue posible cargar el historial. Intenta nuevamente."], 500);
}
