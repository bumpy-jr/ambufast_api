<?php
// Conductor: consulta periódica de la app. Devuelve la emergencia que debe aceptar o rechazar (HU-C02 esc. 1)
// y el servicio que ya aceptó. Antes procesa las respuestas vencidas del sistema (HU-SIST02).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);

try {
    procesarExpiracionesSeguro($conn);
    $amb = ambulanciaDelConductor($conn, $idUsuario);
    if (!$amb) responder(["success" => true, "solicitud" => null, "servicio" => null]);
    $idAmb = (int)$amb["id_ambulancia"];

    $st = $conn->prepare("SELECT s.id_asignacion, s.distancia_km, s.eta_minutos,
                                 TIMESTAMPDIFF(SECOND, NOW(), n.fecha_expiracion) AS segundos,
                                 i.direccion, i.gravedad, i.numero_heridos, i.descripcion
                          FROM asignaciones s
                          JOIN notificaciones_asignacion n ON n.id_asignacion = s.id_asignacion
                          JOIN incidentes i ON i.id_incidente = s.id_incidente
                          WHERE s.id_ambulancia = ? AND s.estado = 'PENDIENTE' AND n.fecha_expiracion > NOW()
                            AND n.fecha_respuesta IS NULL
                          ORDER BY s.id_asignacion DESC LIMIT 1");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();

    $solicitud = null;
    $aviso = null;
    if ($p) {
        // HU-C02 esc. 10: no se envía información incorrecta; se avisa que hay un problema con los datos.
        if (trim((string)$p["direccion"]) === "" || trim((string)$p["gravedad"]) === "") {
            $aviso = "Hay un problema con los datos de la emergencia asignada. Contacta al administrador.";
        } else {
            $solicitud = [
                "id_asignacion" => (int)$p["id_asignacion"],
                "unidad" => $amb["codigo_unidad"],
                "distancia_km" => (float)$p["distancia_km"],
                "eta_minutos" => (int)$p["eta_minutos"],
                "segundos_restantes" => max(0, (int)$p["segundos"]),
                "total_segundos" => SEGUNDOS_RESPUESTA_CONDUCTOR,
                "direccion" => $p["direccion"],
                "gravedad" => $p["gravedad"],
                "heridos" => (int)$p["numero_heridos"],
                "descripcion" => $p["descripcion"],
            ];
        }
    }

    $st = $conn->prepare("SELECT s.id_asignacion, s.distancia_km, s.eta_minutos, i.direccion, i.gravedad, i.numero_heridos,
                                 i.descripcion, i.latitud, i.longitud, s.fecha_llegada
                          FROM asignaciones s JOIN incidentes i ON i.id_incidente = s.id_incidente
                          WHERE s.id_ambulancia = ? AND s.estado = 'ACEPTADA' AND s.fecha_finalizacion IS NULL
                          ORDER BY s.id_asignacion DESC LIMIT 1");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();

    responder(["success" => true, "solicitud" => $solicitud, "aviso" => $aviso, "servicio" => $a ? [
        "id_asignacion" => (int)$a["id_asignacion"],
        "distancia_km" => (float)$a["distancia_km"],
        "eta_minutos" => (int)$a["eta_minutos"],
        "direccion" => $a["direccion"],
        "gravedad" => $a["gravedad"],
        "heridos" => (int)$a["numero_heridos"],
        "descripcion" => $a["descripcion"],
        "latitud" => (float)$a["latitud"],
        "longitud" => (float)$a["longitud"],
        "llegada" => $a["fecha_llegada"] !== null,
    ] : null]);
} catch (Throwable $e) { errorServidor($e); }
