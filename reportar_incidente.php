<?php
// Solicitante: reporta una emergencia (HU-S01/S02) y dispara la búsqueda automática de ambulancia (HU-SIST01).
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idUsuario = (int)($in["id_usuario"] ?? 0);

$direccion = trim((string)($in["direccion"] ?? ""));
$gravedad = strtoupper(strtr(trim((string)($in["gravedad"] ?? "")), ["í" => "i", "Í" => "I", "é" => "e", "É" => "E"]));
$descripcion = trim((string)($in["descripcion"] ?? ""));
$heridosRaw = $in["heridos"] ?? null;

function rechazar(string $msg, int $codigo = 400): void { responder(["success" => false, "message" => $msg], $codigo); }

if (!is_numeric($in["latitud"] ?? null) || !is_numeric($in["longitud"] ?? null)) {
    rechazar("No pudimos determinar con precisión tu ubicación. Confirma o ajusta la dirección en el mapa.");
}
$lat = (float)$in["latitud"];
$lng = (float)$in["longitud"];
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    rechazar("No pudimos determinar con precisión tu ubicación. Confirma o ajusta la dirección en el mapa.");
}
if (largoTexto($direccion) < 5) rechazar("Debes indicar una dirección válida para reportar la emergencia.");
if ($gravedad === "") rechazar("Selecciona el nivel de gravedad de la emergencia.");
if (!in_array($gravedad, GRAVEDADES, true)) rechazar("Selecciona un nivel de gravedad válido.");
if ($heridosRaw === null || $heridosRaw === "" || !is_numeric($heridosRaw) || (float)$heridosRaw != (int)$heridosRaw) {
    rechazar("Completa los datos obligatorios para enviar el reporte.");
}
$heridos = (int)$heridosRaw;
if ($heridos < 0) rechazar("El número de heridos no puede ser negativo.");
if ($heridos > 500) rechazar("Verifica el número de heridos antes de enviar el reporte.");
$direccion = recortarTexto($direccion, 150);
$descripcion = recortarTexto($descripcion, 1000);

try {
    exigirSolicitante($conn, $idUsuario);

    // Posible duplicado: otro reporte activo a menos de 200 m en los últimos 20 minutos (HU-S01 esc. 17).
    $duplicado = 0;
    $res = $conn->query("SELECT latitud, longitud FROM incidentes
                         WHERE estado IN ('PENDIENTE','ASIGNADO','EN_ATENCION','SIN_UNIDAD')
                           AND fecha_reporte >= DATE_SUB(NOW(), INTERVAL 20 MINUTE) LIMIT 100");
    while ($o = $res->fetch_assoc()) {
        if (distanciaKm($lat, $lng, (float)$o["latitud"], (float)$o["longitud"]) <= 0.2) { $duplicado = 1; break; }
    }

    $st = $conn->prepare("INSERT INTO incidentes (id_solicitante, direccion, latitud, longitud, gravedad, numero_heridos, descripcion, estado, posible_duplicado)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?)");
    $st->bind_param("isddsisi", $idUsuario, $direccion, $lat, $lng, $gravedad, $heridos, $descripcion, $duplicado);
    $st->execute();
    $idIncidente = $conn->insert_id;
    $st->close();
} catch (Throwable $e) {
    error_log("[ambufast] " . $e->getMessage());
    responder(["success" => false, "message" => "No fue posible registrar la emergencia. Intenta nuevamente."], 500);
}

// El reporte ya está guardado: un fallo en la búsqueda no debe perderlo (queda PENDIENTE para el administrador).
$busqueda = ["resultado" => "ERROR", "ampliaciones" => 0, "unidad" => null];
try {
    $busqueda = buscarYAsignar($conn, $idIncidente, $lat, $lng);
    if ($busqueda["resultado"] !== "ASIGNADA") {
        $st = $conn->prepare("UPDATE incidentes SET estado = 'SIN_UNIDAD' WHERE id_incidente = ?");
        $st->bind_param("i", $idIncidente);
        $st->execute();
        $st->close();
    }
} catch (Throwable $e) {
    $conn->rollback();
    error_log("[ambufast] búsqueda incidente $idIncidente: " . $e->getMessage());
}

$mensaje = "Reporte de emergencia enviado correctamente. Buscando una ambulancia disponible.";
if ($busqueda["resultado"] === "SIN_UNIDAD") {
    $mensaje = "No encontramos ambulancias disponibles en el área de cobertura. Puedes comunicarte con la central de emergencias.";
} elseif ($busqueda["resultado"] === "FUERA_COBERTURA") {
    $mensaje = "La ubicación se encuentra fuera del área de cobertura. Comunícate con la central de emergencias.";
}

responder([
    "success" => true,
    "message" => $mensaje,
    "id_incidente" => $idIncidente,
    "busqueda" => $busqueda["resultado"],
    "ampliaciones" => $busqueda["ampliaciones"],
    "posible_duplicado" => (bool)$duplicado,
    "aviso_duplicado" => $duplicado ? "Estamos verificando si este incidente ya fue reportado." : null,
]);
