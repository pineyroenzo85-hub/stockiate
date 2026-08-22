<?php
/**
 * stockIAte - iniciar_sesion.php
 * ================================
 * Login: valida email + contraseña contra `usuarios` y devuelve los datos
 * del usuario (sin el hash) para que el frontend guarde la sesión en
 * localStorage y redirija al módulo que corresponde a su rol.
 *
 * No hay manejo de sesión de servidor (cookies/tokens) en este proyecto:
 * el frontend es el que sostiene la sesión, igual que el resto del repo
 * (ver CLAUDE.md, sección "Cosas para tener en cuenta").
 *
 * Espera un body tipo:
 * {
 *   "email": "enzo@ejemplo.com",
 *   "password": "algo-secreto"
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

if (!isset($body['email'], $body['password']) || trim($body['email']) === '' || $body['password'] === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$email = trim($body['email']);
$password = (string) $body['password'];

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, apellido, email, password_hash, rol
         FROM usuarios WHERE email = :email"
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    // Mensaje genérico en ambos casos (email inexistente o contraseña
    // incorrecta) para no revelar si un email está registrado o no.
    if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
        http_response_code(401);
        echo json_encode(["ok" => false, "mensaje" => "Email o contraseña incorrectos"]);
        exit();
    }

    unset($usuario['password_hash']);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Inicio de sesión exitoso",
        "usuario" => $usuario,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al iniciar sesión",
        "error" => $e->getMessage(),
    ]);
}
