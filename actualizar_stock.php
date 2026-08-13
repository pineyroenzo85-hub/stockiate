<?php
/**
 * stockIAte - actualizar_stock.php
 * ================================
 * Ajusta el stock de un producto en +1/-1 (botones +/- de la tabla de
 * inventario, en Administrador y Repositor). Usa SELECT ... FOR UPDATE
 * dentro de una transacción, mismo patrón que registrar_venta.php, para
 * evitar condiciones de carrera con otros ajustes o ventas simultáneas.
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "delta": 1,        // o -1
 *   "usuario_id": 1     // opcional
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

if (!isset($body['producto_id'], $body['delta'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$producto_id = (int) $body['producto_id'];
$delta = (int) $body['delta'];

if ($delta !== 1 && $delta !== -1) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "delta debe ser 1 o -1"]);
    exit();
}

try {
    $pdo->beginTransaction();

    $stmtProducto = $pdo->prepare(
        "SELECT id, stock_actual, stock_minimo FROM productos WHERE id = :producto_id FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "El producto no existe"]);
        exit();
    }

    $nuevoStock = (int) $producto['stock_actual'] + $delta;

    if ($nuevoStock < 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "mensaje" => "El stock no puede quedar negativo (disponible: {$producto['stock_actual']})",
        ]);
        exit();
    }

    $stmtUpdate = $pdo->prepare(
        "UPDATE productos SET stock_actual = :nuevo_stock WHERE id = :producto_id"
    );
    $stmtUpdate->execute([
        ':nuevo_stock' => $nuevoStock,
        ':producto_id' => $producto_id,
    ]);

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "producto_id" => $producto_id,
        "stock_actual" => $nuevoStock,
        "stock_minimo" => (int) $producto['stock_minimo'],
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al actualizar el stock",
        "error" => $e->getMessage(),
    ]);
}
