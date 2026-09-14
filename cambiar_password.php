<?php
/**
 * stockIAte - cambiar_password.php
 * ================================
 * Cambia la contraseña de un usuario ("Seguridad" en ajustes.html),
 * verificando la contraseña actual antes de aceptar la nueva.
 *
 * Espera un body tipo:
 * { "usuario_id": 4, "password_actual": "algo-secreto", "password_nueva": "algo-nuevo" }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'conexion.php';

$body = json_decode(file_get_contents("php://input"), true);

if (
    !isset($body['usuario_id'], $body['password_actual'], $body['password_nueva']) ||
    $body['password_actual'] === '' ||
    strlen((string) $body['password_nueva']) < 6
) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios o la contraseña nueva debe tener al menos 6 caracteres"]);
    exit();
}

$usuario_id = (int) $body['usuario_id'];
$password_actual = (string) $body['password_actual'];
$password_nueva = (string) $body['password_nueva'];

try {
    $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = :usuario_id");
    $stmt->execute([':usuario_id' => $usuario_id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "No se encontró el usuario"]);
        exit();
    }

    if (!password_verify($password_actual, $usuario['password_hash'])) {
        http_response_code(401);
        echo json_encode(["ok" => false, "mensaje" => "La contraseña actual no es correcta"]);
        exit();
    }

    $nuevo_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
    $stmtUpdate = $pdo->prepare("UPDATE usuarios SET password_hash = :hash WHERE id = :usuario_id");
    $stmtUpdate->execute([':hash' => $nuevo_hash, ':usuario_id' => $usuario_id]);

    echo json_encode(["ok" => true, "mensaje" => "Contraseña actualizada correctamente"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error al cambiar la contraseña", "error" => $e->getMessage()]);
}
