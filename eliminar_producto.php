<?php
/**
 * stockIAte - eliminar_producto.php
 * ================================
 * Borra un producto del catálogo (botón 🗑 de la tabla de inventario).
 * Solo permite borrar productos con stock_actual = 0 -- se revalida acá
 * server-side, no se confía en que el frontend ya lo haya chequeado.
 *
 * Si el producto tiene ventas, lotes de stock o correcciones de IA
 * asociadas (FOREIGN KEY sin ON DELETE CASCADE en el schema), el DELETE
 * falla por integridad referencial y se devuelve un mensaje claro en vez
 * de un error 500 crudo -- ese historial no se borra en cascada porque
 * rompería registros de ventas reales.
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12
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

if (!isset($body['producto_id'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta producto_id"]);
    exit();
}

$producto_id = (int) $body['producto_id'];

try {
    $pdo->beginTransaction();

    $stmtProducto = $pdo->prepare(
        "SELECT id, nombre, stock_actual FROM productos WHERE id = :producto_id FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "El producto no existe"]);
        exit();
    }

    if ((int) $producto['stock_actual'] !== 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "mensaje" => "No se puede eliminar un producto con stock disponible (actual: {$producto['stock_actual']})",
        ]);
        exit();
    }

    $stmtDelete = $pdo->prepare("DELETE FROM productos WHERE id = :producto_id");
    $stmtDelete->execute([':producto_id' => $producto_id]);

    $pdo->commit();

    echo json_encode(["ok" => true, "producto_id" => $producto_id]);
} catch (PDOException $e) {
    $pdo->rollBack();

    // Código 23000 = violación de integridad referencial (FK): el producto
    // tiene ventas, lotes de stock o correcciones de IA asociadas.
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "mensaje" => "No se puede eliminar: el producto tiene ventas, lotes de stock o correcciones registradas en el historial.",
        ]);
        exit();
    }

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al eliminar el producto",
        "error" => $e->getMessage(),
    ]);
}
