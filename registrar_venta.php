<?php
/**
 * stockIAte - registrar_venta.php
 * ================================
 * Recibe el JSON confirmado desde la pantalla de venta (Red de Seguridad
 * del módulo Caja) y lo persiste en MySQL local (XAMPP): resta stock y
 * registra la venta.
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "cantidad": 2,
 *   "usuario_id": 1
 * }
 *
 * El precio se toma de productos.precio_venta en la base (no del cliente),
 * para no confiar en un precio que venga del navegador.
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

if ($cantidad <= 0) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "La cantidad debe ser mayor a 0"]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1) Traer el producto CON LOCK (FOR UPDATE) para evitar que dos ventas
    //    simultáneas dejen el stock en negativo (condición de carrera).
    $stmtProducto = $pdo->prepare(
        "SELECT id, precio_venta, stock_actual FROM productos WHERE id = :producto_id FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "El producto no existe"]);
        exit();
    }

    if ($producto['stock_actual'] < $cantidad) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Stock insuficiente (disponible: {$producto['stock_actual']}, pedido: {$cantidad})",
        ]);
        exit();
    }

    $precio_unitario = $producto['precio_venta'];

    // 2) Insertar la venta
    $stmtVenta = $pdo->prepare(
        "INSERT INTO ventas (producto_id, cantidad, precio_unitario, usuario_id)
         VALUES (:producto_id, :cantidad, :precio_unitario, :usuario_id)"
    );
    $stmtVenta->execute([
        ':producto_id' => $producto_id,
        ':cantidad' => $cantidad,
        ':precio_unitario' => $precio_unitario,
        ':usuario_id' => $usuario_id,
    ]);

    // 3) Restar el stock del producto
    $stmtStock = $pdo->prepare(
        "UPDATE productos
         SET stock_actual = stock_actual - :cantidad
         WHERE id = :producto_id"
    );
    $stmtStock->execute([
        ':cantidad' => $cantidad,
        ':producto_id' => $producto_id,
    ]);

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "mensaje" => "Venta registrada correctamente",
        "producto_id" => $producto_id,
        "cantidad_vendida" => $cantidad,
        "precio_unitario" => $precio_unitario,
        "subtotal" => $precio_unitario * $cantidad,
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al registrar la venta",
        "error" => $e->getMessage(),
    ]);
}
