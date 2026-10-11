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
// ESTE ENDPOINT ES EXCLUSIVO PARA SOLICITANTES
// =====================================================

$nombreCompleto = trim($data["nombre"] ?? "");
$cedula = trim($data["cedula"] ?? "");
$telefono = trim($data["telefono"] ?? "");
$correo = trim($data["correo"] ?? "");
$fechaNacimiento = trim($data["fechaNacimiento"] ?? "");
$password = $data["password"] ?? "";


// =====================================================
// BLOQUEAR REGISTRO DE CONDUCTORES
// =====================================================

if (
    isset($data["tipoUsuario"]) &&
    $data["tipoUsuario"] === "conductor"
) {
    echo json_encode([
        "success" => false,
        "message" => "El registro de conductores solo puede hacerlo un administrador."
    ]);
    exit;
}


// =====================================================
// VALIDACIONES COMUNES
// =====================================================

if (
    $nombreCompleto === "" ||
    $cedula === "" ||
    $telefono === "" ||
    $correo === "" ||
    $password === ""
) {
    echo json_encode([
        "success" => false,
        "message" => "Todos los campos son obligatorios."
    ]);
    exit;
}


// =====================================================
// VALIDAR CORREO
// =====================================================

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "success" => false,
        "message" => "El correo electrónico no es válido."
    ]);
    exit;
}


// =====================================================
// VALIDAR FECHA DE NACIMIENTO
// =====================================================

if ($fechaNacimiento === "") {
    echo json_encode([
        "success" => false,
        "message" => "La fecha de nacimiento es obligatoria."
    ]);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaNacimiento)) {
    echo json_encode([
        "success" => false,
        "message" => "La fecha de nacimiento debe tener el formato AAAA-MM-DD."
    ]);
    exit;
}


// =====================================================
// VALIDAR LONGITUD DE CONTRASEÑA
// =====================================================

if (strlen($password) < 6) {
    echo json_encode([
        "success" => false,
        "message" => "La contraseña debe tener mínimo 6 caracteres."
    ]);
    exit;
}


// =====================================================
// SEPARAR NOMBRES Y APELLIDOS
// =====================================================

$partes = preg_split('/\s+/', $nombreCompleto, 2);

$nombres = $partes[0] ?? "";
$apellidos = $partes[1] ?? "";


// =====================================================
// OBTENER ID DEL ROL "SOLICITANTE"
// =====================================================

$sqlRol = "SELECT id_rol 
           FROM roles 
           WHERE nombre = 'SOLICITANTE' 
           LIMIT 1";

$resultRol = $conn->query($sqlRol);

if (!$resultRol || $resultRol->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "No existe el rol SOLICITANTE en la base de datos."
    ]);
    exit;
}

$idRol = $resultRol->fetch_assoc()["id_rol"];


// =====================================================
// VALIDAR DUPLICADOS: CÉDULA / CORREO
// =====================================================

$sqlDuplicado = "SELECT id_usuario 
                 FROM usuarios
                 WHERE cedula = ? OR email = ?
                 LIMIT 1";

$stmtDuplicado = $conn->prepare($sqlDuplicado);

if (!$stmtDuplicado) {
    echo json_encode([
        "success" => false,
        "message" => "Error al validar datos existentes: " . $conn->error
    ]);
    exit;
}

$stmtDuplicado->bind_param(
    "ss",
    $cedula,
    $correo
);

$stmtDuplicado->execute();
$stmtDuplicado->store_result();

if ($stmtDuplicado->num_rows > 0) {

    echo json_encode([
        "success" => false,
        "message" => "Ya existe una cuenta registrada con esa cédula o ese correo."
    ]);

    $stmtDuplicado->close();
    exit;
}

$stmtDuplicado->close();


// =====================================================
// ENCRIPTAR CONTRASEÑA
// =====================================================

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);


// =====================================================
// INSERTAR USUARIO SOLICITANTE
// =====================================================

try {

    $sqlUsuario = "INSERT INTO usuarios
        (
            nombres,
            apellidos,
            cedula,
            email,
            password_hash,
            telefono,
            fecha_nacimiento,
            id_rol
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtUsuario = $conn->prepare($sqlUsuario);

    if (!$stmtUsuario) {
        throw new Exception(
            "Error al preparar usuario: " . $conn->error
        );
    }

    $stmtUsuario->bind_param(
        "sssssssi",
        $nombres,
        $apellidos,
        $cedula,
        $correo,
        $passwordHash,
        $telefono,
        $fechaNacimiento,
        $idRol
    );

    if (!$stmtUsuario->execute()) {
        throw new Exception(
            "Error al insertar usuario: " . $stmtUsuario->error
        );
    }

    $usuarioId = $conn->insert_id;

    echo json_encode([
        "success" => true,
        "message" => "Solicitante registrado correctamente.",
        "usuario_id" => $usuarioId
    ]);

    $stmtUsuario->close();

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "No se pudo registrar el solicitante.",
        "error" => $e->getMessage()
    ]);
}

?>
