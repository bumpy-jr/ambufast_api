<?php
// Administrador: incidentes recientes con la unidad asignada y el detalle de la búsqueda (radios usados).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();

try {
    exigirAdministrador($conn, (int)($in["id_usuario"] ?? 0));
    procesarExpiracionesSeguro($conn);
    $sql = "SELECT i.id_incidente, i.direccion, i.gravedad, i.numero_heridos, i.estado, i.posible_duplicado,
                   TIMESTAMPDIFF(SECOND, i.fecha_reporte, NOW()) AS segundos, i.fecha_reporte,
                   CONCAT(u.nombres, ' ', u.apellidos) AS solicitante, u.telefono,
                   am.codigo_unidad, asg.distancia_km, asg.eta_minutos, asg.estado AS estado_asignacion, asg.numero_intento,
                   b.id_busqueda, b.resultado AS resultado_busqueda
            FROM incidentes i
            LEFT JOIN usuarios u ON u.id_usuario = i.id_solicitante
            LEFT JOIN asignaciones asg ON asg.id_asignacion = (SELECT MAX(id_asignacion) FROM asignaciones WHERE id_incidente = i.id_incidente)
            LEFT JOIN ambulancias am ON am.id_ambulancia = asg.id_ambulancia
            LEFT JOIN busquedas_ambulancia b ON b.id_busqueda = (SELECT MAX(id_busqueda) FROM busquedas_ambulancia WHERE id_incidente = i.id_incidente)
            ORDER BY i.id_incidente DESC LIMIT 50";
    $filas = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    $lista = [];
    foreach ($filas as $f) {
        $radios = [];
        if ($f["id_busqueda"] !== null) {
            $st = $conn->prepare("SELECT radio_km, ambulancias_encontradas FROM busquedas_radio WHERE id_busqueda = ? ORDER BY numero_intento");
            $idB = (int)$f["id_busqueda"];
            $st->bind_param("i", $idB);
            $st->execute();
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $radios[] = ["radio_km" => (float)$r["radio_km"], "encontradas" => (int)$r["ambulancias_encontradas"]];
            }
            $st->close();
        }
        $st = $conn->prepare("SELECT am.codigo_unidad, a.estado, a.distancia_km FROM asignaciones a
                              JOIN ambulancias am ON am.id_ambulancia = a.id_ambulancia
                              WHERE a.id_incidente = ? ORDER BY a.id_asignacion");
        $idI = (int)$f["id_incidente"];
        $st->bind_param("i", $idI);
        $st->execute();
        $intentos = array_map(fn($x) => ["unidad" => $x["codigo_unidad"], "estado" => $x["estado"], "distancia_km" => (float)$x["distancia_km"]],
                              $st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();
        $lista[] = [
            "intentos" => $intentos,
            "id_incidente" => (int)$f["id_incidente"],
            "direccion" => $f["direccion"],
            "gravedad" => $f["gravedad"],
            "heridos" => (int)$f["numero_heridos"],
            "estado" => $f["estado"],
            "posible_duplicado" => (bool)$f["posible_duplicado"],
            "segundos" => (int)$f["segundos"],
            "fecha_reporte" => $f["fecha_reporte"],
            "solicitante" => $f["solicitante"],
            "telefono" => $f["telefono"],
            "asignacion" => $f["codigo_unidad"] === null ? null : [
                "unidad" => $f["codigo_unidad"],
                "distancia_km" => (float)$f["distancia_km"],
                "eta_minutos" => (int)$f["eta_minutos"],
                "estado" => $f["estado_asignacion"],
                "intento" => (int)$f["numero_intento"],
            ],
            "busqueda" => $f["resultado_busqueda"],
            "radios" => $radios,
        ];
    }
    responder(["success" => true, "incidentes" => $lista]);
} catch (Throwable $e) { errorServidor($e); }
