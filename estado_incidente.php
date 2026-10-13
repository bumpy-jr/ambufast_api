<?php
// Solicitante: seguimiento de su emergencia más reciente (pantalla "Ambulancia en camino").
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);

try {
    procesarExpiracionesSeguro($conn);
    exigirSolicitante($conn, $idUsuario);

    $st = $conn->prepare("SELECT id_incidente, direccion, latitud, longitud, gravedad, numero_heridos, estado
                          FROM incidentes WHERE id_solicitante = ? AND estado <> 'CANCELADO'
                            AND fecha_reporte >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                          ORDER BY id_incidente DESC LIMIT 1");
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $i = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$i) responder(["success" => true, "incidente" => null]);
    $idInc = (int)$i["id_incidente"];

    // Asignación relevante: la aceptada/finalizada, si no la última.
    $st = $conn->prepare("SELECT s.id_asignacion, s.id_ambulancia, s.estado, s.eta_minutos, s.distancia_km, s.fecha_llegada,
                                 s.fecha_finalizacion, am.codigo_unidad, am.placa
                          FROM asignaciones s JOIN ambulancias am ON am.id_ambulancia = s.id_ambulancia
                          WHERE s.id_incidente = ?
                          ORDER BY (s.estado IN ('ACEPTADA','COMPLETADA','FINALIZADA')) DESC, s.id_asignacion DESC LIMIT 1");
    $st->bind_param("i", $idInc);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();

    $fase = match ($i["estado"]) {
        "ASIGNADO" => "EN_CAMINO",
        "EN_ATENCION" => "EN_LUGAR",
        "SIN_UNIDAD" => "SIN_UNIDAD",
        "CERRADO" => "FINALIZADO",
        default => "BUSCANDO",
    };
    if ($fase === "FINALIZADO") { // el resumen final se muestra solo un rato
        $st = $conn->prepare("SELECT 1 FROM asignaciones WHERE id_incidente = ? AND fecha_finalizacion >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
        $st->bind_param("i", $idInc);
        $st->execute();
        $reciente = $st->get_result()->num_rows > 0;
        $st->close();
        if (!$reciente) responder(["success" => true, "incidente" => null]);
    }

    $unidad = null;
    if ($a && in_array($fase, ["EN_CAMINO", "EN_LUGAR", "FINALIZADO"], true)) {
        $idAmb = (int)$a["id_ambulancia"];
        $st = $conn->prepare("SELECT u.nombres, u.telefono FROM asignaciones_conductor_ambulancia x
                              JOIN conductores c ON c.id_conductor = x.id_conductor JOIN usuarios u ON u.id_usuario = c.id_usuario
                              WHERE x.id_ambulancia = ? ORDER BY x.activa DESC, x.fecha_inicio DESC LIMIT 1");
        $st->bind_param("i", $idAmb);
        $st->execute();
        $cond = $st->get_result()->fetch_assoc();
        $st->close();

        $st = $conn->prepare("SELECT latitud, longitud, TIMESTAMPDIFF(SECOND, fecha_hora, NOW()) AS seg
                              FROM ubicaciones_ambulancia WHERE id_ambulancia = ? ORDER BY id_ubicacion DESC LIMIT 1");
        $st->bind_param("i", $idAmb);
        $st->execute();
        $ub = $st->get_result()->fetch_assoc();
        $st->close();

        $conSenal = $ub && (int)$ub["seg"] <= SEGUNDOS_SIN_SENAL;
        $eta = (int)$a["eta_minutos"];
        $dist = (float)$a["distancia_km"];
        if ($conSenal && $fase === "EN_CAMINO") { // ETA actualizado con la posición real de la unidad
            $dist = round(distanciaKm((float)$ub["latitud"], (float)$ub["longitud"], (float)$i["latitud"], (float)$i["longitud"]), 2);
            $eta = etaMinutos($dist);
        }
        $unidad = [
            "codigo" => $a["codigo_unidad"],
            "placa" => $a["placa"],
            "conductor" => $cond["nombres"] ?? null,
            "telefono" => $cond["telefono"] ?? null,
            "eta_minutos" => $eta,
            "distancia_km" => $dist,
            "con_senal" => (bool)$conSenal,
            "latitud" => $ub ? (float)$ub["latitud"] : null,
            "longitud" => $ub ? (float)$ub["longitud"] : null,
        ];
    }

    responder(["success" => true, "fase" => $fase, "incidente" => [
        "id_incidente" => $idInc,
        "direccion" => $i["direccion"],
        "latitud" => (float)$i["latitud"],
        "longitud" => (float)$i["longitud"],
        "gravedad" => $i["gravedad"],
        "heridos" => (int)$i["numero_heridos"],
        "estado" => $i["estado"],
    ], "unidad" => $unidad]);
} catch (Throwable $e) { errorServidor($e); }
