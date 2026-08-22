<?php
/**
 * stockIAte - registrar_usuario.php
 * ================================
 * Registro de un nuevo usuario (repositor, cajero o dueño/administrador).
 * Recibe el JSON del formulario de registro y lo persiste en MySQL local
 * (XAMPP), guardando la contraseña siempre hasheada (nunca en texto plano).
 *
 * Espera un body tipo:
 * {
 *   "nombre": "Enzo",
 *   "apellido": "Piñeyro",
 *   "email": "enzo@ejemplo.com",
 *   "password": "algo-secreto",
 *   "rol": "repositor"   // "repositor" | "cajero" | "dueño"
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json");

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'conexion.php'; // debe exponer $pdo (PDO conectado a MySQL/XAMPP)

$body = json_decode(file_get_contents("php://input"), true);

$roles_validos = ['repositor', 'cajero', 'dueño'];

if (
    !isset($body['nombre'], $body['apellido'], $body['email'], $body['password'], $body['rol']) ||
    trim($body['nombre']) === '' ||
    trim($body['apellido']) === '' ||
    trim($body['email']) === '' ||
    trim($body['password']) === ''
) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$nombre = trim($body['nombre']);
$apellido = trim($body['apellido']);
$email = trim($body['email']);
$password = (string) $body['password'];
$rol = $body['rol'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El email no es válido"]);
    exit();
}

if (!in_array($rol, $roles_validos, true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El rol indicado no es válido"]);
    exit();
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "La contraseña debe tener al menos 6 caracteres"]);
    exit();
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO usuarios (nombre, apellido, email, password_hash, rol)
         VALUES (:nombre, :apellido, :email, :password_hash, :rol)"
    );
    $stmt->execute([
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':email' => $email,
        ':password_hash' => $password_hash,
        ':rol' => $rol,
    ]);

    $usuario_id = (int) $pdo->lastInsertId();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Usuario registrado correctamente",
        "usuario" => [
            "id" => $usuario_id,
            "nombre" => $nombre,
            "apellido" => $apellido,
            "email" => $email,
            "rol" => $rol,
        ],
    ]);
} catch (PDOException $e) {
    // Código 23000 = violación de restricción única (email duplicado)
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ya existe una cuenta registrada con ese email"]);
        exit();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al registrar el usuario",
        "error" => $e->getMessage(),
    ]);
}
