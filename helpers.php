<?php
// Utilidades compartidas por los endpoints nuevos (S7-S9).
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/conexion.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Segundos sin recibir ubicación para considerar al conductor "sin señal".
const SEGUNDOS_SIN_SENAL = 90;

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function leerEntrada(): array {
    $d = json_decode(file_get_contents("php://input"), true);
    if (!is_array($d)) {
        responder(["success" => false, "message" => "No se recibieron datos."], 400);
    }
    return $d;
}

function errorServidor(Throwable $e): void {
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "Ocurrió un error en el servidor. Intenta nuevamente."], 500);
}

// Ambulancia con conductor activo asociado a este usuario (o null).
function ambulanciaDelConductor(mysqli $conn, int $idUsuario, bool $bloquear = false): ?array {
    $sql = "SELECT a.id_ambulancia, a.codigo_unidad, a.placa, a.capacidad, a.equipamiento,
                   a.estado, a.operativa, a.gps_activo,
                   c.id_conductor, c.fecha_vencimiento_licencia,
                   (c.fecha_vencimiento_licencia >= CURDATE()) AS licencia_vigente
            FROM conductores c
            JOIN asignaciones_conductor_ambulancia aca
                 ON aca.id_conductor = c.id_conductor AND aca.activa = 1
            JOIN ambulancias a ON a.id_ambulancia = aca.id_ambulancia
            WHERE c.id_usuario = ? AND c.activo = 1
            LIMIT 1" . ($bloquear ? " FOR UPDATE" : "");
    $st = $conn->prepare($sql);
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ?: null;
}

function exigirAdministrador(mysqli $conn, int $idUsuario): void {
    $st = $conn->prepare("SELECT 1 FROM usuarios u
        JOIN roles r ON r.id_rol = u.id_rol
        JOIN administradores ad ON ad.id_usuario = u.id_usuario
        WHERE u.id_usuario = ? AND u.activo = 1 AND r.nombre = 'ADMINISTRADOR' LIMIT 1");
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$ok) {
        responder(["success" => false, "message" => "No tienes permisos de administrador."], 403);
    }
}

function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}

// ---------------------------------------------------------------------------
// Utilidades de la gestión de bloqueo y flota (HU-A04, HU-A01)
// ---------------------------------------------------------------------------

// Registra una acción administrativa: quién, cuándo (fecha_hora), sobre qué ambulancia y desde qué IP.
function auditar(mysqli $conn, ?int $idUsuario, string $accion, int $idAmbulancia, ?array $antes, ?array $despues): void {
    $a = $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE);
    $d = $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;
    $tabla = "ambulancias";
    $st = $conn->prepare("INSERT INTO auditoria (id_usuario, accion, tabla_afectada, id_registro, datos_anteriores, datos_nuevos, direccion_ip)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param("ississs", $idUsuario, $accion, $tabla, $idAmbulancia, $a, $d, $ip);
    $st->execute();
    $st->close();
}

function registrarCambioEstado(mysqli $conn, int $idAmbulancia, string $anterior, string $nuevo, ?int $idUsuario, string $origen, ?string $obs = null): void {
    $st = $conn->prepare("INSERT INTO historial_estados_ambulancia
        (id_ambulancia, estado_anterior, estado_nuevo, id_usuario, origen, observacion) VALUES (?, ?, ?, ?, ?, ?)");
    $st->bind_param("ississ", $idAmbulancia, $anterior, $nuevo, $idUsuario, $origen, $obs);
    $st->execute();
    $st->close();
}

// Bloqueo vigente (sin liberar) de una ambulancia, con segundos restantes (negativo = tiempo vencido).
function bloqueoActivo(mysqli $conn, int $idAmbulancia, bool $bloquear = false): ?array {
    $st = $conn->prepare("SELECT id_bloqueo, id_asignacion, fecha_fin_estimada,
                                 TIMESTAMPDIFF(SECOND, NOW(), fecha_fin_estimada) AS segundos_restantes
                          FROM bloqueos_ambulancia
                          WHERE id_ambulancia = ? AND estado = 'ACTIVO' AND fecha_fin_real IS NULL
                          ORDER BY id_bloqueo DESC LIMIT 1" . ($bloquear ? " FOR UPDATE" : ""));
    $st->bind_param("i", $idAmbulancia);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $f ?: null;
}

// Para usar al aceptar un servicio (semana 10): deja la ambulancia bloqueada $minutos minutos.
function iniciarBloqueo(mysqli $conn, int $idAmbulancia, int $idAsignacion, int $minutos, string $motivo = "SERVICIO"): int {
    $st = $conn->prepare("INSERT INTO bloqueos_ambulancia (id_ambulancia, id_asignacion, fecha_fin_estimada, estado, motivo)
                          VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'ACTIVO', ?)");
    $st->bind_param("iiis", $idAmbulancia, $idAsignacion, $minutos, $motivo);
    $st->execute();
    $id = $conn->insert_id;
    $st->close();
    return $id;
}

function fallarTx(mysqli $conn, string $mensaje, int $codigo = 409, array $extra = []): void {
    $conn->rollback();
    responder(array_merge(["success" => false, "message" => $mensaje], $extra), $codigo);
}

// ---------------------------------------------------------------------------
// Búsqueda automática de ambulancia (HU-SIST01) y datos del solicitante (HU-S01, HU-S03)
// ---------------------------------------------------------------------------

// Radios de búsqueda progresiva (km). Se parte del primero y se amplía hasta el último (radio máximo).
const RADIOS_BUSQUEDA_KM = [3, 6, 12, 25];
// Área de cobertura del servicio: centro (Neiva) y radio en km. Fuera de ella no se despacha.
const COBERTURA_CENTRO = [2.9273, -75.2819];
const COBERTURA_RADIO_KM = 35;
// Sin servicio de rutas: el ETA se estima con distancia en línea recta x factor de ruta a velocidad promedio.
const FACTOR_RUTA = 1.3;
const VELOCIDAD_PROMEDIO_KMH = 30;
// Tiempo que tiene el conductor para responder a una asignación (temporizador del mockup).
const SEGUNDOS_RESPUESTA_CONDUCTOR = 30;
const GRAVEDADES = ["LEVE", "MODERADO", "GRAVE", "CRITICO"];
// Intentos automáticos por caso antes de pedir intervención manual (HU-SIST02 esc. 6 y 9).
const MAX_INTENTOS_ASIGNACION = 3;
// Tiempo de atención que se suma al ETA para calcular el bloqueo de la unidad al aceptar un servicio.
const MINUTOS_ATENCION = 30;
// Distancia máxima al lugar del incidente para poder confirmar la llegada (HU-C03 esc. 7).
const RADIO_LLEGADA_KM = 0.2;

function etaMinutos(float $km): int {
    return max(1, (int)ceil(($km * FACTOR_RUTA / VELOCIDAD_PROMEDIO_KMH) * 60));
}

function exigirSolicitante(mysqli $conn, int $idUsuario): void {
    $st = $conn->prepare("SELECT 1 FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
                          WHERE u.id_usuario = ? AND u.activo = 1 AND r.nombre = 'SOLICITANTE' LIMIT 1");
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$ok) responder(["success" => false, "message" => "Solo un solicitante puede realizar esta acción."], 403);
}

/**
 * Unidades elegibles para atender un punto, ordenadas por menor tiempo estimado y luego distancia.
 * Elegible = DISPONIBLE, operativa, GPS activo, conductor con licencia vigente, ubicación reciente
 * (nunca se usa información desactualizada) y sin una asignación pendiente de respuesta.
 */
function unidadesDisponibles(mysqli $conn, float $lat, float $lng, array $excluir = []): array {
    $seg = SEGUNDOS_SIN_SENAL;
    $sql = "SELECT a.id_ambulancia, a.codigo_unidad, ub.latitud, ub.longitud
            FROM ambulancias a
            JOIN asignaciones_conductor_ambulancia aca ON aca.id_ambulancia = a.id_ambulancia AND aca.activa = 1
            JOIN conductores c ON c.id_conductor = aca.id_conductor AND c.activo = 1
                 AND c.fecha_vencimiento_licencia >= CURDATE()
            JOIN ubicaciones_ambulancia ub ON ub.id_ubicacion =
                 (SELECT MAX(id_ubicacion) FROM ubicaciones_ambulancia WHERE id_ambulancia = a.id_ambulancia)
            WHERE a.estado = 'DISPONIBLE' AND a.operativa = 1 AND a.gps_activo = 1
              AND ub.fecha_hora >= DATE_SUB(NOW(), INTERVAL $seg SECOND)
              AND NOT EXISTS (SELECT 1 FROM asignaciones x
                              JOIN notificaciones_asignacion n ON n.id_asignacion = x.id_asignacion
                              WHERE x.id_ambulancia = a.id_ambulancia AND x.estado = 'PENDIENTE'
                                AND n.fecha_expiracion > NOW())";
    $lista = [];
    foreach ($conn->query($sql)->fetch_all(MYSQLI_ASSOC) as $f) {
        if (in_array((int)$f["id_ambulancia"], $excluir, true)) continue;
        $km = distanciaKm($lat, $lng, (float)$f["latitud"], (float)$f["longitud"]);
        $lista[] = [
            "id_ambulancia" => (int)$f["id_ambulancia"],
            "codigo_unidad" => $f["codigo_unidad"],
            "latitud" => (float)$f["latitud"],
            "longitud" => (float)$f["longitud"],
            "distancia_km" => round($km, 2),
            "eta_minutos" => etaMinutos($km),
        ];
    }
    usort($lista, fn($a, $b) => [$a["eta_minutos"], $a["distancia_km"]] <=> [$b["eta_minutos"], $b["distancia_km"]]);
    return $lista;
}

/**
 * Busca la unidad más cercana ampliando el radio de forma progresiva, registra cada ampliación y deja una
 * asignación PENDIENTE de respuesta del conductor (con su fecha de expiración).
 * Devuelve: resultado = ASIGNADA | SIN_UNIDAD | FUERA_COBERTURA.
 */
function buscarYAsignar(mysqli $conn, int $idIncidente, float $lat, float $lng, array $excluir = []): array {
    $estadoInc = $conn->query("SELECT estado FROM incidentes WHERE id_incidente = " . (int)$idIncidente)->fetch_assoc()["estado"] ?? null;
    if (in_array($estadoInc, ["CERRADO", "CANCELADO"], true)) {
        return ["resultado" => "NO_APLICA", "ampliaciones" => 0, "unidad" => null];
    }
    $radioMax = max(RADIOS_BUSQUEDA_KM);
    $st = $conn->prepare("INSERT INTO busquedas_ambulancia (id_incidente, radio_maximo_km) VALUES (?, ?)");
    $st->bind_param("id", $idIncidente, $radioMax);
    $st->execute();
    $idBusqueda = $conn->insert_id;
    $st->close();

    $cerrar = function (string $resultado) use ($conn, $idBusqueda): void {
        $st = $conn->prepare("UPDATE busquedas_ambulancia SET fecha_fin = NOW(), resultado = ? WHERE id_busqueda = ?");
        $st->bind_param("si", $resultado, $idBusqueda);
        $st->execute();
        $st->close();
    };

    if (distanciaKm($lat, $lng, COBERTURA_CENTRO[0], COBERTURA_CENTRO[1]) > COBERTURA_RADIO_KM) {
        $cerrar("FUERA_COBERTURA");
        return ["resultado" => "FUERA_COBERTURA", "ampliaciones" => 0, "unidad" => null];
    }

    $candidatas = unidadesDisponibles($conn, $lat, $lng, $excluir);
    $enRadio = [];
    $radioUsado = null;
    $ampliaciones = 0;
    foreach (RADIOS_BUSQUEDA_KM as $i => $radio) {
        $enRadio = array_values(array_filter($candidatas, fn($u) => $u["distancia_km"] <= $radio));
        $intento = $i + 1;
        $n = count($enRadio);
        $st = $conn->prepare("INSERT INTO busquedas_radio (id_busqueda, numero_intento, radio_km, ambulancias_encontradas) VALUES (?, ?, ?, ?)");
        $st->bind_param("iidi", $idBusqueda, $intento, $radio, $n);
        $st->execute();
        $st->close();
        if ($n > 0) { $radioUsado = $radio; break; }
        $ampliaciones = $i + 1 < count(RADIOS_BUSQUEDA_KM) ? $i + 1 : $i;
    }

    foreach ($enRadio as $u) {
        $conn->begin_transaction();
        // Orden de bloqueo: incidente -> unidad. Dos procesos simultáneos sobre el mismo caso se serializan
        // y solo uno puede dejar una asignación vigente (HU-SIST03 esc. 2, 4 y 5).
        $st = $conn->prepare("SELECT estado FROM incidentes WHERE id_incidente = ? FOR UPDATE");
        $st->bind_param("i", $idIncidente);
        $st->execute();
        $estadoCaso = $st->get_result()->fetch_assoc()["estado"] ?? null;
        $st->close();
        if (in_array($estadoCaso, ["CERRADO", "CANCELADO"], true)) {
            $conn->rollback();
            $cerrar("NO_APLICA");
            return ["resultado" => "NO_APLICA", "ampliaciones" => $ampliaciones, "unidad" => null];
        }
        $st = $conn->prepare("SELECT 1 FROM asignaciones x LEFT JOIN notificaciones_asignacion n ON n.id_asignacion = x.id_asignacion
                              WHERE x.id_incidente = ? AND (x.estado = 'ACEPTADA' OR (x.estado = 'PENDIENTE' AND n.fecha_expiracion > NOW())) LIMIT 1");
        $st->bind_param("i", $idIncidente);
        $st->execute();
        $yaTiene = $st->get_result()->num_rows > 0;
        $st->close();
        if ($yaTiene) {
            $conn->rollback();
            $cerrar("YA_ASIGNADO");
            return ["resultado" => "YA_ASIGNADO", "ampliaciones" => $ampliaciones, "unidad" => null];
        }

        $st = $conn->prepare("SELECT estado FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
        $st->bind_param("i", $u["id_ambulancia"]);
        $st->execute();
        $estado = $st->get_result()->fetch_assoc()["estado"] ?? null;
        $st->close();

        $st = $conn->prepare("SELECT 1 FROM asignaciones x JOIN notificaciones_asignacion n ON n.id_asignacion = x.id_asignacion
                              WHERE x.id_ambulancia = ? AND x.estado = 'PENDIENTE' AND n.fecha_expiracion > NOW() LIMIT 1");
        $st->bind_param("i", $u["id_ambulancia"]);
        $st->execute();
        $pendiente = $st->get_result()->num_rows > 0;
        $st->close();

        if ($estado !== "DISPONIBLE" || $pendiente) { // dejó de estar disponible durante la búsqueda: siguiente unidad
            $conn->rollback();
            continue;
        }

        $st = $conn->prepare("SELECT COUNT(*) + 1 AS n FROM asignaciones WHERE id_incidente = ?");
        $st->bind_param("i", $idIncidente);
        $st->execute();
        $numIntento = (int)$st->get_result()->fetch_assoc()["n"];
        $st->close();

        $st = $conn->prepare("INSERT INTO asignaciones (id_incidente, id_ambulancia, numero_intento, distancia_km, eta_minutos, estado)
                              VALUES (?, ?, ?, ?, ?, 'PENDIENTE')");
        $st->bind_param("iiidi", $idIncidente, $u["id_ambulancia"], $numIntento, $u["distancia_km"], $u["eta_minutos"]);
        $st->execute();
        $idAsignacion = $conn->insert_id;
        $st->close();

        $seg = SEGUNDOS_RESPUESTA_CONDUCTOR;
        $st = $conn->prepare("INSERT INTO notificaciones_asignacion (id_asignacion, fecha_expiracion)
                              VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
        $st->bind_param("ii", $idAsignacion, $seg);
        $st->execute();
        $st->close();

        $conn->commit();
        $cerrar("ASIGNADA");
        return ["resultado" => "ASIGNADA", "ampliaciones" => $ampliaciones, "radio_km" => $radioUsado,
                "id_asignacion" => $idAsignacion, "unidad" => $u];
    }

    $cerrar("SIN_UNIDAD");
    return ["resultado" => "SIN_UNIDAD", "ampliaciones" => $ampliaciones, "unidad" => null];
}

// Largo y recorte de texto UTF-8 sin depender de la extensión mbstring.
function largoTexto(string $s): int { return preg_match_all('/./us', $s) ?: 0; }
function recortarTexto(string $s, int $max): string { return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : $s; }


// ---------------------------------------------------------------------------
// Reasignación automática (HU-SIST02) y ciclo de la asignación (HU-C02, HU-SIST03)
// ---------------------------------------------------------------------------

// Unidades que ya recibieron una asignación para este caso (no se vuelven a ofrecer en la cadena automática).
function unidadesYaIntentadas(mysqli $conn, int $idIncidente): array {
    $st = $conn->prepare("SELECT DISTINCT id_ambulancia FROM asignaciones WHERE id_incidente = ?");
    $st->bind_param("i", $idIncidente);
    $st->execute();
    $ids = array_map(fn($f) => (int)$f["id_ambulancia"], $st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
    return $ids;
}

/**
 * Busca otra unidad para un caso. Respeta el límite de intentos automáticos (salvo reintento manual) y deja el
 * caso en SIN_UNIDAD si no hay a quién asignar. Resultado: ASIGNADA | SIN_UNIDAD | LIMITE_INTENTOS | FUERA_COBERTURA | NO_APLICA | YA_ASIGNADO.
 */
function reasignarIncidente(mysqli $conn, int $idIncidente, bool $manual = false, ?array $excluir = null): array {
    $st = $conn->prepare("SELECT estado, latitud, longitud FROM incidentes WHERE id_incidente = ?");
    $st->bind_param("i", $idIncidente);
    $st->execute();
    $inc = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inc || in_array($inc["estado"], ["CERRADO", "CANCELADO"], true)) {
        return ["resultado" => "NO_APLICA", "ampliaciones" => 0, "unidad" => null];
    }

    $st = $conn->prepare("SELECT COUNT(*) AS n FROM asignaciones WHERE id_incidente = ?");
    $st->bind_param("i", $idIncidente);
    $st->execute();
    $intentos = (int)$st->get_result()->fetch_assoc()["n"];
    $st->close();

    $nuevoEstado = null;
    if (!$manual && $intentos >= MAX_INTENTOS_ASIGNACION) {
        $r = ["resultado" => "LIMITE_INTENTOS", "ampliaciones" => 0, "unidad" => null];
        $nuevoEstado = "SIN_UNIDAD";
    } else {
        $descartar = $manual ? ($excluir ?? []) : unidadesYaIntentadas($conn, $idIncidente);
        $r = buscarYAsignar($conn, $idIncidente, (float)$inc["latitud"], (float)$inc["longitud"], $descartar);
        if ($r["resultado"] === "ASIGNADA") $nuevoEstado = "PENDIENTE";
        elseif (in_array($r["resultado"], ["SIN_UNIDAD", "FUERA_COBERTURA"], true)) $nuevoEstado = "SIN_UNIDAD";
    }
    if ($nuevoEstado !== null) {
        $st = $conn->prepare("UPDATE incidentes SET estado = ? WHERE id_incidente = ?");
        $st->bind_param("si", $nuevoEstado, $idIncidente);
        $st->execute();
        $st->close();
    }
    return $r;
}

/**
 * HU-SIST02: marca "No respondió" las asignaciones cuya notificación venció sin respuesta y busca otra unidad.
 * Se ejecuta cuando alguien consulta (conductor, solicitante o administrador); no necesita una tarea programada.
 * Es seguro llamarla en paralelo: cada asignación se bloquea y se vuelve a verificar antes de procesarla.
 */
function procesarExpiraciones(mysqli $conn): int {
    $vencidas = $conn->query("SELECT a.id_asignacion, a.id_incidente, a.id_ambulancia
                              FROM asignaciones a JOIN notificaciones_asignacion n ON n.id_asignacion = a.id_asignacion
                              WHERE a.estado = 'PENDIENTE' AND n.fecha_expiracion <= NOW() AND n.fecha_respuesta IS NULL
                              ORDER BY a.id_asignacion LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    $procesadas = 0;
    foreach ($vencidas as $v) {
        $idA = (int)$v["id_asignacion"];
        $conn->begin_transaction();
        $st = $conn->prepare("SELECT estado FROM asignaciones WHERE id_asignacion = ? FOR UPDATE");
        $st->bind_param("i", $idA);
        $st->execute();
        $estado = $st->get_result()->fetch_assoc()["estado"] ?? null;
        $st->close();
        if ($estado !== "PENDIENTE") { $conn->rollback(); continue; } // otro proceso ya la resolvió (aceptó, rechazó o expiró)

        $st = $conn->prepare("UPDATE asignaciones SET estado = 'SIN_RESPUESTA' WHERE id_asignacion = ?");
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();
        $st = $conn->prepare("UPDATE notificaciones_asignacion SET respuesta = 'NO_RESPONDIO' WHERE id_asignacion = ?");
        $st->bind_param("i", $idA);
        $st->execute();
        $st->close();
        auditar($conn, null, "NO_RESPONDIO", (int)$v["id_ambulancia"], null, ["id_asignacion" => $idA, "id_incidente" => (int)$v["id_incidente"]]);
        $conn->commit();

        reasignarIncidente($conn, (int)$v["id_incidente"]);
        $procesadas++;
    }
    return $procesadas;
}

// Versión para llamar al inicio de un endpoint: un fallo aquí nunca debe romper la respuesta principal.
function procesarExpiracionesSeguro(mysqli $conn): void {
    try {
        procesarExpiraciones($conn);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorar) {}
        error_log("[ambufast] expiraciones: " . $e->getMessage());
    }
}
