<?php
// Administrador: registra un conductor nuevo y lo asocia a una ambulancia ya creada, reemplazando al
// anterior si lo había (HU-A01 esc. 9 y 10). Con licencia vigente la ambulancia queda operativa.
require_once __DIR__ . "/helpers.php";
$in = leerEntrada();
$idAdmin = (int)($in["id_usuario"] ?? 0);
$idAmb = (int)($in["id_ambulancia"] ?? 0);
$c = is_array($in["conductor"] ?? null) ? $in["conductor"] : [];

$txt = fn(string $k): string => trim((string)($c[$k] ?? ""));
$nombres = $txt("nombres");
$apellidos = $txt("apellidos");
$cedula = $txt("cedula");
$telefono = $txt("telefono");
$correo = $txt("correo");
$nacimiento = $txt("fechaNacimiento");
$licencia = $txt("licencia");
$vence = $txt("licenciaVencimiento");
$password = (string)($c["password"] ?? "");
$confirmarVencida = ($c["confirmarVencida"] ?? false) === true;

function rechazar(string $msg, array $extra = []): void {
    responder(array_merge(["success" => false, "message" => $msg], $extra), 400);
}
function fechaValida(string $f): bool {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

if ($nombres === "") rechazar("El nombre del conductor es obligatorio.");
if ($licencia === "") rechazar("La licencia del conductor es obligatoria.");
if ($telefono === "") rechazar("El contacto del conductor es obligatorio.");
if (!preg_match('/^\d{10}$/', $telefono)) rechazar("El teléfono debe tener 10 dígitos.");
if (!fechaValida($vence)) rechazar("La fecha de vencimiento de la licencia es obligatoria.");
if (!fechaValida($nacimiento)) rechazar("La fecha de nacimiento del conductor es obligatoria.");
if ((new DateTime("today"))->diff(new DateTime($nacimiento))->y < 18) rechazar("El conductor debe ser mayor de edad para registrarse.");
if ($cedula === "" || $correo === "" || $password === "") rechazar("Complete los datos obligatorios del conductor.");
if (!preg_match('/^\d{6,10}$/', $cedula)) rechazar("La cédula debe tener entre 6 y 10 dígitos.");
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) rechazar("El correo electrónico no es válido.");
if (strlen($password) < 6) rechazar("La contraseña debe tener mínimo 6 caracteres.");

try {
    exigirAdministrador($conn, $idAdmin);
    $conn->begin_transaction();

    $st = $conn->prepare("SELECT estado, operativa FROM ambulancias WHERE id_ambulancia = ? FOR UPDATE");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $amb = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$amb) fallarTx($conn, "Ambulancia no encontrada.", 404);
    if ($amb["estado"] === "OCUPADA") fallarTx($conn, "No se puede cambiar el conductor mientras la ambulancia atiende un caso.");

    $st = $conn->prepare("SELECT 1 FROM usuarios WHERE cedula = ? OR email = ? OR telefono = ? LIMIT 1");
    $st->bind_param("sss", $cedula, $correo, $telefono);
    $st->execute();
    $dup = $st->get_result()->num_rows > 0;
    $st->close();
    if ($dup) fallarTx($conn, "El conductor ya se encuentra registrado en el sistema.");

    $st = $conn->prepare("SELECT 1 FROM conductores WHERE numero_licencia = ? LIMIT 1");
    $st->bind_param("s", $licencia);
    $st->execute();
    $dup = $st->get_result()->num_rows > 0;
    $st->close();
    if ($dup) fallarTx($conn, "Ese número de licencia ya está registrado a otro conductor.");

    // La licencia vale hasta el día de vencimiento inclusive.
    $st = $conn->prepare("SELECT (? < CURDATE()) AS vencida");
    $st->bind_param("s", $vence);
    $st->execute();
    $vencida = (int)$st->get_result()->fetch_assoc()["vencida"] === 1;
    $st->close();
    if ($vencida && !$confirmarVencida) {
        fallarTx($conn, "La licencia del conductor se encuentra vencida. Verifique la información antes de continuar.",
                 409, ["necesitaConfirmacion" => true]);
    }

    $idRol = (int)$conn->query("SELECT id_rol FROM roles WHERE nombre = 'CONDUCTOR' LIMIT 1")->fetch_assoc()["id_rol"];
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $st = $conn->prepare("INSERT INTO usuarios (nombres, apellidos, cedula, email, password_hash, telefono, fecha_nacimiento, id_rol)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param("sssssssi", $nombres, $apellidos, $cedula, $correo, $hash, $telefono, $nacimiento, $idRol);
    $st->execute();
    $idUsuario = $conn->insert_id;
    $st->close();

    $st = $conn->prepare("INSERT INTO conductores (id_usuario, numero_licencia, fecha_vencimiento_licencia, activo) VALUES (?, ?, ?, 1)");
    $st->bind_param("iss", $idUsuario, $licencia, $vence);
    $st->execute();
    $idConductor = $conn->insert_id;
    $st->close();

    // Cierra la asociación anterior (si la había) y crea la nueva.
    $st = $conn->prepare("SELECT id_conductor FROM asignaciones_conductor_ambulancia WHERE id_ambulancia = ? AND activa = 1 LIMIT 1");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $previo = $st->get_result()->fetch_assoc();
    $st->close();
    $st = $conn->prepare("UPDATE asignaciones_conductor_ambulancia SET activa = 0, fecha_fin = NOW() WHERE id_ambulancia = ? AND activa = 1");
    $st->bind_param("i", $idAmb);
    $st->execute();
    $st->close();
    $st = $conn->prepare("INSERT INTO asignaciones_conductor_ambulancia (id_conductor, id_ambulancia, fecha_inicio, activa) VALUES (?, ?, NOW(), 1)");
    $st->bind_param("ii", $idConductor, $idAmb);
    $st->execute();
    $st->close();

    // Estado resultante de la ambulancia.
    $estadoAntes = $amb["estado"];
    $nuevoEstado = $vencida ? "INCOMPLETA" : ($estadoAntes === "INCOMPLETA" ? "DISPONIBLE" : $estadoAntes);
    $operativa = $vencida ? 0 : 1;
    $st = $conn->prepare("UPDATE ambulancias SET estado = ?, operativa = ? WHERE id_ambulancia = ?");
    $st->bind_param("sii", $nuevoEstado, $operativa, $idAmb);
    $st->execute();
    $st->close();
    if ($nuevoEstado !== $estadoAntes) {
        registrarCambioEstado($conn, $idAmb, $estadoAntes, $nuevoEstado, $idAdmin, "ADMIN", "Conductor asignado");
    }
    auditar($conn, $idAdmin, "ASIGNAR_CONDUCTOR", $idAmb,
            ["id_conductor" => $previo ? (int)$previo["id_conductor"] : null, "estado" => $estadoAntes],
            ["id_conductor" => $idConductor, "estado" => $nuevoEstado]);

    $conn->commit();
    responder(["success" => true, "warning" => $vencida, "estado" => $nuevoEstado, "conductor_id" => $idConductor,
        "message" => $vencida
            ? "Conductor registrado y asociado. Como la licencia está vencida, la ambulancia sigue no operativa hasta actualizarla."
            : "Conductor registrado y asociado correctamente a la ambulancia."]);
} catch (Throwable $e) {
    $conn->rollback();
    errorServidor($e);
}
