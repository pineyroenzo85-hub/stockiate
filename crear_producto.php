<?php
/**
 * stockIAte - crear_producto.php
 * ================================
 * Da de alta un producto nuevo en el catálogo. Lo usa la pantalla de
 * validación (Red de Seguridad) de repositor.html/cajero.html cuando la
 * IA detectó algo (por OCR o clase genérica) que no matchea con ningún
 * producto existente -- el repositor/cajero completa nombre/marca/SKU/
 * precio ahí mismo y el producto queda disponible para seleccionar.
 *
 * Espera un body tipo:
 * {
 *   "sku": "REX-001",
 *   "nombre": "Rexona Hidra Protección Intensiva",
 *   "marca": "Rexona",       // opcional
 *   "precio_venta": 12000    // opcional, default 0
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

if (!isset($body['sku'], $body['nombre'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$sku = trim($body['sku']);
$nombre = trim($body['nombre']);
$marca = !empty($body['marca']) ? trim($body['marca']) : null;
$precio_venta = isset($body['precio_venta']) ? (float) $body['precio_venta'] : 0;

if ($sku === '' || $nombre === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El SKU y el nombre no pueden estar vacíos"]);
    exit();
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO productos (sku, nombre, marca, precio_venta)
         VALUES (:sku, :nombre, :marca, :precio_venta)"
    );
    $stmt->execute([
        ':sku' => $sku,
        ':nombre' => $nombre,
        ':marca' => $marca,
        ':precio_venta' => $precio_venta,
    ]);

    $id = (int) $pdo->lastInsertId();

    echo json_encode([
        "ok" => true,
        "producto" => [
            "id" => $id,
            "sku" => $sku,
            "nombre" => $nombre,
            "marca" => $marca,
            "precio_venta" => $precio_venta,
            "stock_actual" => 0,
        ],
    ]);
} catch (PDOException $e) {
    // Código 23000 = violación de constraint (típicamente el SKU único ya existe)
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ya existe un producto con ese SKU"]);
    } else {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Error al crear el producto",
            "error" => $e->getMessage(),
        ]);
    }
}
