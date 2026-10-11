<?php
// Conductor: datos de su ambulancia, última ubicación y servicios del día.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);

try {
    $amb = ambulanciaDelConductor($conn, $idUsuario);
    if (!$amb) {
        responder(["success" => false, "message" => "No tienes una ambulancia asignada. Contacta al administrador."], 404);
    }
    $id = (int)$amb["id_ambulancia"];

    $st = $conn->prepare("SELECT latitud, longitud, TIMESTAMPDIFF(SECOND, fecha_hora, NOW()) AS segundos
                          FROM ubicaciones_ambulancia WHERE id_ambulancia = ?
                          ORDER BY id_ubicacion DESC LIMIT 1");
    $st->bind_param("i", $id);
    $st->execute();
    $ub = $st->get_result()->fetch_assoc();
    $st->close();

    $st = $conn->prepare("SELECT COUNT(*) AS n FROM asignaciones
                          WHERE id_ambulancia = ? AND fecha_finalizacion IS NOT NULL AND DATE(fecha_finalizacion) = CURDATE()");
    $st->bind_param("i", $id);
    $st->execute();
    $servicios = (int)$st->get_result()->fetch_assoc()["n"];
    $st->close();

    // Km recorridos hoy (suma Haversine entre posiciones; ignora saltos < 5 m por ruido del GPS).
    $st = $conn->prepare("SELECT latitud, longitud FROM ubicaciones_ambulancia
                          WHERE id_ambulancia = ? AND fecha_hora >= CURDATE() ORDER BY id_ubicacion");
    $st->bind_param("i", $id);
    $st->execute();
    $pts = $st->get_result();
    $km = 0.0; $prev = null;
    while ($p = $pts->fetch_assoc()) {
        if ($prev) {
            $d = distanciaKm((float)$prev["latitud"], (float)$prev["longitud"], (float)$p["latitud"], (float)$p["longitud"]);
            if ($d >= 0.005) { $km += $d; }
        }
        $prev = $p;
    }
    $st->close();

    // Tiempo activo hoy: minutos en estado DISPONIBLE u OCUPADA, según historial_estados_ambulancia.
    $hoy = $conn->query("SELECT TIMESTAMPDIFF(SECOND, CURDATE(), NOW()) AS ahora")->fetch_assoc();
    $ahora = (int)$hoy["ahora"];
    $st = $conn->prepare("SELECT estado_anterior, estado_nuevo, TIMESTAMPDIFF(SECOND, CURDATE(), fecha_hora) AS t
                          FROM historial_estados_ambulancia
                          WHERE id_ambulancia = ? AND fecha_hora >= CURDATE() ORDER BY fecha_hora, id_historial");
    $st->bind_param("i", $id);
    $st->execute();
    $cambios = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $estadoInicio = $cambios ? $cambios[0]["estado_anterior"] : $amb["estado"];
    // El conteo arranca en la primera actividad registrada hoy (primera ubicación o primer cambio),
    // no a medianoche; sin actividad registrada hoy el tiempo activo es 0.
    $st = $conn->prepare("SELECT MIN(TIMESTAMPDIFF(SECOND, CURDATE(), fecha_hora)) AS t
                          FROM ubicaciones_ambulancia WHERE id_ambulancia = ? AND fecha_hora >= CURDATE()");
    $st->bind_param("i", $id);
    $st->execute();
    $primeraLoc = $st->get_result()->fetch_assoc()["t"];
    $st->close();
    $candidatos = array_filter([$primeraLoc === null ? null : (int)$primeraLoc, $cambios ? (int)$cambios[0]["t"] : null],
                               fn($x) => $x !== null);
    $segActivo = 0; $estadoAct = $estadoInicio; $desde = $candidatos ? min($candidatos) : $ahora;
    foreach ($cambios as $c) {
        if (in_array($estadoAct, ["DISPONIBLE", "OCUPADA"], true)) { $segActivo += max(0, (int)$c["t"] - $desde); }
        $estadoAct = $c["estado_nuevo"]; $desde = (int)$c["t"];
    }
    if (in_array($estadoAct, ["DISPONIBLE", "OCUPADA"], true)) { $segActivo += max(0, $ahora - $desde); }

    // Últimos 5 servicios finalizados.
    $st = $conn->prepare("SELECT i.direccion, i.gravedad, a.fecha_finalizacion
                          FROM asignaciones a JOIN incidentes i ON i.id_incidente = a.id_incidente
                          WHERE a.id_ambulancia = ? AND a.fecha_finalizacion IS NOT NULL
                          ORDER BY a.fecha_finalizacion DESC LIMIT 5");
    $st->bind_param("i", $id);
    $st->execute();
    $historial = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    responder([
        "success" => true,
        "historial" => $historial,
        "ambulancia" => [
            "id_ambulancia" => $id,
            "codigo_unidad" => $amb["codigo_unidad"],
            "placa" => $amb["placa"],
            "capacidad" => (int)$amb["capacidad"],
            "equipamiento" => $amb["equipamiento"],
            "estado" => $amb["estado"],
            "operativa" => (bool)$amb["operativa"],
            "licencia_vigente" => (bool)$amb["licencia_vigente"],
            "licencia_vence" => $amb["fecha_vencimiento_licencia"],
            "ultima_ubicacion" => $ub ? [
                "latitud" => (float)$ub["latitud"],
                "longitud" => (float)$ub["longitud"],
                "segundos" => (int)$ub["segundos"],
            ] : null,
            "servicios_hoy" => $servicios,
            "km_hoy" => round($km, 1),
            "minutos_activo" => intdiv($segActivo, 60),
        ],
    ]);
} catch (Throwable $e) { errorServidor($e); }
