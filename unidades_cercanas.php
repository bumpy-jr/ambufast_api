<?php
// Solicitante: ambulancias disponibles cerca de su ubicación (HU-S03). Solo referencia:
// no se expone nombre ni teléfono del conductor ni se permite elegir una unidad.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();

try {
    procesarExpiracionesSeguro($conn);
    exigirSolicitante($conn, (int)($in["id_usuario"] ?? 0));
    if (!is_numeric($in["latitud"] ?? null) || !is_numeric($in["longitud"] ?? null)) {
        responder(["success" => false, "message" => "Se necesita permiso de ubicación para mostrar las unidades cercanas."], 400);
    }
    $lat = (float)$in["latitud"];
    $lng = (float)$in["longitud"];
    $radio = RADIOS_BUSQUEDA_KM[0];

    $cerca = array_values(array_filter(unidadesDisponibles($conn, $lat, $lng), fn($u) => $u["distancia_km"] <= $radio));
    $unidades = array_map(fn($u) => [
        "codigo_unidad" => $u["codigo_unidad"],
        "latitud" => $u["latitud"],
        "longitud" => $u["longitud"],
        "distancia_km" => $u["distancia_km"],
        "eta_minutos" => $u["eta_minutos"],
    ], array_slice($cerca, 0, 5));

    responder(["success" => true, "radio_km" => $radio, "total" => count($cerca), "unidades" => $unidades]);
} catch (Throwable $e) { errorServidor($e); }
