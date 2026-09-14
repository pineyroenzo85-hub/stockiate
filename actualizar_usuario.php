<?php
/**
 * stockIAte - actualizar_usuario.php
 * ================================
 * Actualiza nombre/apellido de un usuario existente ("Mi cuenta" en
 * ajustes.html). Email y rol no se editan acá: el email es la clave de
 * login (UNIQUE) y el rol define el acceso por módulo.
 *
 * Espera un body tipo:
 * { "usuario_id": 4, "nombre": "Ana", "apellido": "Gómez" }
 *
 * Devuelve el usuario actualizado (mismo shape que iniciar_sesion.php /
 * registrar_usuario.php) para que el frontend resincronice localStorage
 * vía guardarUsuarioSesion() -- el resto de la app no se entera sola de
 * un cambio de nombre.
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
    !isset($body['usuario_id'], $body['nombre'], $body['apellido']) ||
    trim($body['nombre']) === '' ||
    trim($body['apellido']) === ''
) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$usuario_id = (int) $body['usuario_id'];
$nombre = trim($body['nombre']);
$apellido = trim($body['apellido']);

try {
    $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, apellido = :apellido WHERE id = :usuario_id");
    $stmt->execute([':nombre' => $nombre, ':apellido' => $apellido, ':usuario_id' => $usuario_id]);

    $stmtUsuario = $pdo->prepare("SELECT id, nombre, apellido, email, rol FROM usuarios WHERE id = :usuario_id");
    $stmtUsuario->execute([':usuario_id' => $usuario_id]);
    $usuario = $stmtUsuario->fetch();

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "No se encontró el usuario"]);
        exit();
    }

    echo json_encode(["ok" => true, "mensaje" => "Perfil actualizado correctamente", "usuario" => $usuario]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error al actualizar el perfil", "error" => $e->getMessage()]);
}
