<?php
/**
 * stockIAte - guardar_stock.php
 * ================================
 * Recibe el JSON confirmado desde la pantalla de validación (Red de
 * Seguridad) y lo persiste en MySQL local (XAMPP).
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "cantidad": 24,
 *   "fecha_vencimiento": "2026-12-01",   // opcional, puede venir null
 *   "usuario_id": 3
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

if (!isset($body['producto_id'], $body['cantidad'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$producto_id = (int) $body['producto_id'];
$cantidad = (int) $body['cantidad'];
$usuario_id = isset($body['usuario_id']) ? (int) $body['usuario_id'] : null;

// Fecha de vencimiento: opcional. Si no viene o es null, se guarda como NULL
// y ese lote simplemente no participa del control de vencimientos.
$fecha_vencimiento = null;
if (!empty($body['fecha_vencimiento'])) {
    $fecha_vencimiento = $body['fecha_vencimiento'];
}

try {
    $pdo->beginTransaction();

    // 1) Insertar el lote (permite trazabilidad y control de vencimiento por lote)
    $stmtLote = $pdo->prepare(
        "INSERT INTO lotes_stock (producto_id, cantidad, fecha_vencimiento, usuario_id)
         VALUES (:producto_id, :cantidad, :fecha_vencimiento, :usuario_id)"
    );
    $stmtLote->execute([
        ':producto_id' => $producto_id,
        ':cantidad' => $cantidad,
        ':fecha_vencimiento' => $fecha_vencimiento,
        ':usuario_id' => $usuario_id,
    ]);

    // 2) Actualizar el stock total del producto
    //    ON DUPLICATE KEY UPDATE no aplica directo acá porque productos.id
    //    ya existe, así que actualizamos con un UPDATE simple sumando cantidad.
    $stmtStock = $pdo->prepare(
        "UPDATE productos
         SET stock_actual = stock_actual + :cantidad
         WHERE id = :producto_id"
    );
    $stmtStock->execute([
        ':cantidad' => $cantidad,
        ':producto_id' => $producto_id,
    ]);

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Stock actualizado correctamente",
        "producto_id" => $producto_id,
        "cantidad_agregada" => $cantidad,
        "fecha_vencimiento" => $fecha_vencimiento,
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al guardar el stock",
        "error" => $e->getMessage(),
    ]);
}
