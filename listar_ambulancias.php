<?php
// Administrador: flota completa con conductor, estado y última ubicación.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();

try {
    exigirAdministrador($conn, (int)($in["id_usuario"] ?? 0));

    $sql = "SELECT a.id_ambulancia, a.codigo_unidad, a.placa, a.capacidad, a.estado, a.operativa, a.gps_activo,
                   u.nombres, u.apellidos, u.telefono,
                   c.fecha_vencimiento_licencia,
                   (c.fecha_vencimiento_licencia >= CURDATE()) AS licencia_vigente,
                   bl.id_bloqueo, TIMESTAMPDIFF(SECOND, NOW(), bl.fecha_fin_estimada) AS seg_bloqueo,
                   ub.latitud, ub.longitud,
                   TIMESTAMPDIFF(SECOND, ub.fecha_hora, NOW()) AS segundos
            FROM ambulancias a
            LEFT JOIN asignaciones_conductor_ambulancia aca
                   ON aca.id_ambulancia = a.id_ambulancia AND aca.activa = 1
            LEFT JOIN conductores c ON c.id_conductor = aca.id_conductor
            LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
            LEFT JOIN bloqueos_ambulancia bl ON bl.id_bloqueo =
                   (SELECT MAX(id_bloqueo) FROM bloqueos_ambulancia
                    WHERE id_ambulancia = a.id_ambulancia AND estado = 'ACTIVO' AND fecha_fin_real IS NULL)
            LEFT JOIN ubicaciones_ambulancia ub ON ub.id_ubicacion =
                   (SELECT MAX(id_ubicacion) FROM ubicaciones_ambulancia WHERE id_ambulancia = a.id_ambulancia)
            ORDER BY a.codigo_unidad";
    $res = $conn->query($sql);

    $lista = [];
    $resumen = ["DISPONIBLE" => 0, "OCUPADA" => 0, "FUERA_DE_SERVICIO" => 0, "INCOMPLETA" => 0];
    while ($f = $res->fetch_assoc()) {
        $seg = $f["segundos"] === null ? null : (int)$f["segundos"];
        $estado = $f["estado"];
        if (isset($resumen[$estado])) $resumen[$estado]++;
        $lista[] = [
            "id_ambulancia" => (int)$f["id_ambulancia"],
            "codigo_unidad" => $f["codigo_unidad"],
            "placa" => $f["placa"],
            "capacidad" => (int)$f["capacidad"],
            "estado" => $estado,
            "operativa" => (bool)$f["operativa"],
            "conductor" => $f["nombres"] === null ? null : trim($f["nombres"] . " " . $f["apellidos"]),
            "telefono" => $f["telefono"],
            "licencia_vigente" => $f["licencia_vigente"] === null ? null : (bool)$f["licencia_vigente"],
            "licencia_vence" => $f["fecha_vencimiento_licencia"],
            "motivo_incompleta" => $estado !== "INCOMPLETA" ? null
                : ($f["nombres"] === null ? "SIN_CONDUCTOR" : ($f["licencia_vigente"] ? null : "LICENCIA_VENCIDA")),
            "bloqueo" => $f["id_bloqueo"] === null ? null : [
                "minutos_restantes" => (int)ceil((int)$f["seg_bloqueo"] / 60),
                "vencido" => (int)$f["seg_bloqueo"] <= 0,
            ],
            "ubicacion" => $f["latitud"] === null ? null : [
                "latitud" => (float)$f["latitud"],
                "longitud" => (float)$f["longitud"],
                "segundos" => $seg,
            ],
            "con_senal" => $seg !== null && $seg <= SEGUNDOS_SIN_SENAL,
        ];
    }
    responder(["success" => true, "resumen" => $resumen, "ambulancias" => $lista]);
} catch (Throwable $e) { errorServidor($e); }
