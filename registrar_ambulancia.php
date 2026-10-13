<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "conexion.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "No se recibieron datos."
    ]);
    exit;
}

// =====================================================
// DATOS DE LA AMBULANCIA
// =====================================================

$codigoUnidadRaw = $data["codigoUnidad"] ?? "";
$codigoUnidad    = trim($codigoUnidadRaw);
$placaRaw        = $data["placa"] ?? "";
$placa           = strtoupper(trim($placaRaw));
$capacidadRaw    = $data["capacidad"] ?? null;
$equipamiento    = trim($data["equipamiento"] ?? "");

// El conductor es opcional: si viene, debe traer sus datos completos
$conductor = $data["conductor"] ?? null;


// =====================================================
// VALIDAR CÓDIGO DE UNIDAD
// =====================================================

if ($codigoUnidad === "") {
    echo json_encode([
        "success" => false,
        "message" => "El código de unidad es obligatorio."
    ]);
    exit;
}

$sqlCodigo = "SELECT id_ambulancia FROM ambulancias WHERE codigo_unidad = ? LIMIT 1";
$stmtCodigo = $conn->prepare($sqlCodigo);
$stmtCodigo->bind_param("s", $codigoUnidad);
$stmtCodigo->execute();
$stmtCodigo->store_result();

if ($stmtCodigo->num_rows > 0) {
    echo json_encode([
        "success" => false,
        "message" => "El código de unidad ya se encuentra registrado."
    ]);
    $stmtCodigo->close();
    exit;
}
$stmtCodigo->close();


// =====================================================
// VALIDAR PLACA
// =====================================================

if ($placaRaw === "" || $placaRaw === null) {
    echo json_encode([
        "success" => false,
        "message" => "La placa de la ambulancia es obligatoria."
    ]);
    exit;
}

if ($placa === "") {
    echo json_encode([
        "success" => false,
        "message" => "Ingrese una placa válida para continuar."
    ]);
    exit;
}

// Formato típico de placa colombiana: 3 letras + 3 números (ej. ABC123)
if (!preg_match('/^[A-Z]{3}[0-9]{3}$/', $placa)) {
    echo json_encode([
        "success" => false,
        "message" => "El formato de la placa no es válido. Verifique la información ingresada."
    ]);
    exit;
}

$sqlPlaca = "SELECT id_ambulancia FROM ambulancias WHERE placa = ? LIMIT 1";
$stmtPlaca = $conn->prepare($sqlPlaca);
$stmtPlaca->bind_param("s", $placa);
$stmtPlaca->execute();
$stmtPlaca->store_result();

if ($stmtPlaca->num_rows > 0) {
    echo json_encode([
        "success" => false,
        "message" => "La placa ingresada ya se encuentra registrada."
    ]);
    $stmtPlaca->close();
    exit;
}
$stmtPlaca->close();


// =====================================================
// VALIDAR CAPACIDAD
// =====================================================

if ($capacidadRaw === null || $capacidadRaw === "") {
    echo json_encode([
        "success" => false,
        "message" => "La capacidad de la ambulancia es obligatoria."
    ]);
    exit;
}

if (!is_numeric($capacidadRaw) || (int)$capacidadRaw <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Ingrese una capacidad válida."
    ]);
    exit;
}

$capacidad = (int)$capacidadRaw;


// =====================================================
// VALIDAR EQUIPAMIENTO
// =====================================================

if ($equipamiento === "") {
    echo json_encode([
        "success" => false,
        "message" => "Debe ingresar el equipamiento de la ambulancia."
    ]);
    exit;
}


// =====================================================
// SI NO SE ENVÍA CONDUCTOR: registrar ambulancia como INCOMPLETA
// =====================================================

if (!$conductor || !is_array($conductor)) {

    try {
        $estado = "INCOMPLETA";
        $operativa = 0;

        $sqlAmb = "INSERT INTO ambulancias
            (codigo_unidad, placa, capacidad, equipamiento, estado, gps_activo, operativa)
            VALUES (?, ?, ?, ?, ?, 1, ?)";
        $stmtAmb = $conn->prepare($sqlAmb);
        $stmtAmb->bind_param("ssissi", $codigoUnidad, $placa, $capacidad, $equipamiento, $estado, $operativa);

        if (!$stmtAmb->execute()) {
            throw new Exception($stmtAmb->error);
        }

        echo json_encode([
            "success" => true,
            "message" => "Ambulancia registrada, pero permanece no operativa hasta asignar un conductor.",
            "ambulancia_id" => $conn->insert_id
        ]);

        $stmtAmb->close();

    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "No fue posible registrar la ambulancia. Intente nuevamente."
        ]);
    }

    exit;
}


// =====================================================
// VALIDAR DATOS DEL CONDUCTOR
// =====================================================

$nombreCompleto  = trim($conductor["nombre"] ?? "");
$cedula          = trim($conductor["cedula"] ?? "");
$telefono        = trim($conductor["telefono"] ?? "");
$correo          = trim($conductor["correo"] ?? "");
$fechaNacimiento = trim($conductor["fechaNacimiento"] ?? "");
$numeroLicencia  = trim($conductor["licencia"] ?? "");
$licenciaVence   = trim($conductor["licenciaVencimiento"] ?? "");
$password        = $conductor["password"] ?? "";

if ($nombreCompleto === "") {
    echo json_encode(["success" => false, "message" => "El nombre del conductor es obligatorio."]);
    exit;
}

if ($numeroLicencia === "") {
    echo json_encode(["success" => false, "message" => "La licencia del conductor es obligatoria."]);
    exit;
}

if ($telefono === "") {
    echo json_encode(["success" => false, "message" => "El contacto del conductor es obligatorio."]);
    exit;
}

if ($licenciaVence === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $licenciaVence)) {
    echo json_encode(["success" => false, "message" => "La fecha de vencimiento de la licencia es obligatoria."]);
    exit;
}

if ($fechaNacimiento === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaNacimiento)) {
    echo json_encode(["success" => false, "message" => "La fecha de nacimiento del conductor es obligatoria."]);
    exit;
}

$hoyEdad = new DateTime();
$nacimiento = new DateTime($fechaNacimiento);
$edad = $hoyEdad->diff($nacimiento)->y;

if ($edad < 18) {
    echo json_encode(["success" => false, "message" => "El conductor debe ser mayor de edad para registrarse."]);
    exit;
}

if ($cedula === "" || $correo === "" || $password === "") {
    echo json_encode(["success" => false, "message" => "Complete los datos obligatorios del conductor."]);
    exit;
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "El correo electrónico no es válido."]);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(["success" => false, "message" => "La contraseña debe tener mínimo 6 caracteres."]);
    exit;
}


// =====================================================
// VALIDAR CONDUCTOR DUPLICADO (usuarios: cedula/email; conductores: licencia)
// =====================================================

$sqlDupUsuario = "SELECT id_usuario FROM usuarios WHERE cedula = ? OR email = ? OR telefono = ? LIMIT 1";
$stmtDupUsuario = $conn->prepare($sqlDupUsuario);
$stmtDupUsuario->bind_param("sss", $cedula, $correo, $telefono);
$stmtDupUsuario->execute();
$stmtDupUsuario->store_result();

if ($stmtDupUsuario->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "El conductor ya se encuentra registrado en el sistema."]);
    $stmtDupUsuario->close();
    exit;
}
$stmtDupUsuario->close();

$sqlDupLicencia = "SELECT id_conductor FROM conductores WHERE numero_licencia = ? LIMIT 1";
$stmtDupLicencia = $conn->prepare($sqlDupLicencia);
$stmtDupLicencia->bind_param("s", $numeroLicencia);
$stmtDupLicencia->execute();
$stmtDupLicencia->store_result();

if ($stmtDupLicencia->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Ese número de licencia ya está registrado a otro conductor."]);
    $stmtDupLicencia->close();
    exit;
}
$stmtDupLicencia->close();


// =====================================================
// VIGENCIA DE LA LICENCIA
// =====================================================

$zona = new DateTimeZone("America/Bogota");
$hoy = new DateTime("today", $zona);          // medianoche de hoy: la licencia vale todo su último día
$vencimiento = new DateTime($licenciaVence, $zona);
$licenciaVencida = $vencimiento < $hoy;

$confirmarVencida = $conductor["confirmarVencida"] ?? false;

if ($licenciaVencida && !$confirmarVencida) {
    echo json_encode([
        "success" => false,
        "necesitaConfirmacion" => true,
        "message" => "La licencia del conductor se encuentra vencida. Verifique la información antes de continuar."
    ]);
    exit;
}


// =====================================================
// OBTENER ROL CONDUCTOR
// =====================================================

$resultRol = $conn->query("SELECT id_rol FROM roles WHERE nombre = 'CONDUCTOR' LIMIT 1");

if (!$resultRol || $resultRol->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "No existe el rol CONDUCTOR en la base de datos."]);
    exit;
}

$idRol = $resultRol->fetch_assoc()["id_rol"];

$partes = preg_split('/\s+/', $nombreCompleto, 2);
$nombres = $partes[0] ?? "";
$apellidos = $partes[1] ?? "";
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$fechaNacimientoDB = $fechaNacimiento !== "" ? $fechaNacimiento : null;


// =====================================================
// TRANSACCIÓN: usuario -> conductores -> ambulancias -> asignación
// =====================================================

$conn->begin_transaction();

try {

    // 1) Crear el usuario (rol CONDUCTOR)
    $sqlUsuario = "INSERT INTO usuarios
        (nombres, apellidos, cedula, email, password_hash, telefono, fecha_nacimiento, id_rol)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtUsuario = $conn->prepare($sqlUsuario);
    if (!$stmtUsuario) throw new Exception($conn->error);

    $stmtUsuario->bind_param(
        "sssssssi",
        $nombres, $apellidos, $cedula, $correo, $passwordHash, $telefono, $fechaNacimientoDB, $idRol
    );
    if (!$stmtUsuario->execute()) throw new Exception($stmtUsuario->error);

    $idUsuario = $conn->insert_id;
    $stmtUsuario->close();

    // 2) Crear el registro en conductores
    $sqlConductor = "INSERT INTO conductores (id_usuario, numero_licencia, fecha_vencimiento_licencia, activo)
                      VALUES (?, ?, ?, 1)";
    $stmtConductor = $conn->prepare($sqlConductor);
    if (!$stmtConductor) throw new Exception($conn->error);

    $stmtConductor->bind_param("iss", $idUsuario, $numeroLicencia, $licenciaVence);
    if (!$stmtConductor->execute()) throw new Exception($stmtConductor->error);

    $idConductor = $conn->insert_id;
    $stmtConductor->close();

    // 3) Crear la ambulancia
    $estado = $licenciaVencida ? "INCOMPLETA" : "DISPONIBLE";
    $operativa = $licenciaVencida ? 0 : 1;

    $sqlAmb = "INSERT INTO ambulancias
        (codigo_unidad, placa, capacidad, equipamiento, estado, gps_activo, operativa)
        VALUES (?, ?, ?, ?, ?, 1, ?)";
    $stmtAmb = $conn->prepare($sqlAmb);
    if (!$stmtAmb) throw new Exception($conn->error);

    $stmtAmb->bind_param("ssissi", $codigoUnidad, $placa, $capacidad, $equipamiento, $estado, $operativa);
    if (!$stmtAmb->execute()) throw new Exception($stmtAmb->error);

    $idAmbulancia = $conn->insert_id;
    $stmtAmb->close();

    // 4) Asociar conductor <-> ambulancia
    $sqlAsig = "INSERT INTO asignaciones_conductor_ambulancia
        (id_conductor, id_ambulancia, fecha_inicio, activa)
        VALUES (?, ?, NOW(), 1)";
    $stmtAsig = $conn->prepare($sqlAsig);
    if (!$stmtAsig) throw new Exception($conn->error);

    $stmtAsig->bind_param("ii", $idConductor, $idAmbulancia);
    if (!$stmtAsig->execute()) throw new Exception($stmtAsig->error);

    $stmtAsig->close();

    $conn->commit();

    if ($licenciaVencida) {
        echo json_encode([
            "success" => true,
            "warning" => true,
            "message" => "Conductor y ambulancia registrados. Como la licencia está vencida, la ambulancia queda marcada como no operativa hasta actualizar la licencia.",
            "ambulancia_id" => $idAmbulancia,
            "conductor_id" => $idConductor
        ]);
    } else {
        echo json_encode([
            "success" => true,
            "message" => "Ambulancia registrada correctamente y disponible para asignación.",
            "ambulancia_id" => $idAmbulancia,
            "conductor_id" => $idConductor
        ]);
    }

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "success" => false,
        "message" => "No fue posible registrar la ambulancia. Intente nuevamente.",
        "error" => $e->getMessage()
    ]);
}