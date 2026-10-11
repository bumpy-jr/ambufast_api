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
// LEER DATOS DE ENTRADA
// =====================================================

$telefono = trim($data["telefono"] ?? "");
$password = $data["password"] ?? "";


// =====================================================
// VALIDACIONES BÁSICAS
// =====================================================

if ($telefono === "" || $password === "") {
    echo json_encode([
        "success" => false,
        "message" => "Teléfono y contraseña son obligatorios."
    ]);
    exit;
}


// =====================================================
// BUSCAR USUARIO POR TELÉFONO (JUNTO CON SU ROL)
// =====================================================

$sqlUsuario = "SELECT
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.email,
        u.telefono,
        u.password_hash,
        u.activo,
        r.nombre AS rol
    FROM usuarios u
    INNER JOIN roles r ON u.id_rol = r.id_rol
    WHERE u.telefono = ?
    LIMIT 1";

$stmtUsuario = $conn->prepare($sqlUsuario);

if (!$stmtUsuario) {
    echo json_encode([
        "success" => false,
        "message" => "Error al preparar consulta: " . $conn->error
    ]);
    exit;
}

$stmtUsuario->bind_param("s", $telefono);
$stmtUsuario->execute();

$resultado = $stmtUsuario->get_result();

if ($resultado->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "No existe una cuenta con ese número de teléfono."
    ]);
    $stmtUsuario->close();
    exit;
}

$usuario = $resultado->fetch_assoc();

$stmtUsuario->close();


// =====================================================
// VERIFICAR CONTRASEÑA
// =====================================================

if (!password_verify($password, $usuario["password_hash"])) {
    echo json_encode([
        "success" => false,
        "message" => "Contraseña incorrecta."
    ]);
    exit;
}


if (!(int)$usuario["activo"]) {
    echo json_encode([
        "success" => false,
        "message" => "Tu cuenta está desactivada. Contacta al administrador."
    ]);
    exit;
}

// =====================================================
// RESPUESTA EXITOSA
// =====================================================

echo json_encode([
    "success" => true,
    "message" => "Inicio de sesión exitoso.",
    "usuario" => [
        "id_usuario" => $usuario["id_usuario"],
        "nombres"    => $usuario["nombres"],
        "apellidos"  => $usuario["apellidos"],
        "email"      => $usuario["email"],
        "telefono"   => $usuario["telefono"],
        "rol"        => strtolower($usuario["rol"])
    ]
]);
