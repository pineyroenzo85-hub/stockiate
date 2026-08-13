<?php
/**
 * stockIAte - registrar_correccion.php
 * ================================
 * Recibe la corrección que hace el repositor en la pantalla de validación
 * (Red de Seguridad) cuando ajusta lo que detectó la IA (producto y/o
 * cantidad) y la persiste en correcciones_ia, para poder medir después
 * qué tan seguido se equivoca el modelo.
 * Lo llama main.py (endpoint /registrar-correccion).
 *
 * Espera un body tipo:
 * {
 *   "producto_detectado_id": 12,
 *   "producto_corregido_id": 12,
 *   "cantidad_detectada": 5,
 *   "cantidad_corregida": 4,
 *   "confianza_ia": 0.83,
 *   "usuario_id": 1
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'conexion.php'; // debe exponer $pdo (PDO conectado a MySQL/XAMPP)

$body = json_decode(file_get_contents("php://input"), true);

if (!isset($body['cantidad_detectada'], $body['cantidad_corregida'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$producto_detectado_id = isset($body['producto_detectado_id']) ? (int) $body['producto_detectado_id'] : null;
$producto_corregido_id = isset($body['producto_corregido_id']) ? (int) $body['producto_corregido_id'] : null;
$cantidad_detectada = (int) $body['cantidad_detectada'];
$cantidad_corregida = (int) $body['cantidad_corregida'];
$confianza_ia = isset($body['confianza_ia']) ? (float) $body['confianza_ia'] : null;
$usuario_id = isset($body['usuario_id']) ? (int) $body['usuario_id'] : null;

try {
    $stmt = $pdo->prepare(
        "INSERT INTO correcciones_ia
            (producto_detectado_id, producto_corregido_id, cantidad_detectada, cantidad_corregida, confianza_ia, usuario_id)
         VALUES
            (:producto_detectado_id, :producto_corregido_id, :cantidad_detectada, :cantidad_corregida, :confianza_ia, :usuario_id)"
    );
    $stmt->execute([
        ':producto_detectado_id' => $producto_detectado_id,
        ':producto_corregido_id' => $producto_corregido_id,
        ':cantidad_detectada' => $cantidad_detectada,
        ':cantidad_corregida' => $cantidad_corregida,
        ':confianza_ia' => $confianza_ia,
        ':usuario_id' => $usuario_id,
    ]);

    echo json_encode([
        "ok" => true,
        "mensaje" => "Corrección registrada correctamente",
        "id" => $pdo->lastInsertId(),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al guardar la corrección",
        "error" => $e->getMessage(),
    ]);
}
