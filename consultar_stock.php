<?php
/**
 * stockIAte - consultar_stock.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_stock") para responder preguntas sobre stock actual.
 *
 * Espera un body tipo:
 * {
 *   "termino": "sauvage",      // opcional, busca en nombre/marca/sku
 *   "categoria": "Perfume Hombre", // opcional
 *   "solo_bajo": true          // opcional, filtra stock_actual <= stock_minimo
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

$body = json_decode(file_get_contents("php://input"), true) ?? [];

$termino = !empty($body['termino']) ? trim($body['termino']) : null;
$categoria = !empty($body['categoria']) ? trim($body['categoria']) : null;
$solo_bajo = !empty($body['solo_bajo']);

$condiciones = [];
$parametros = [];

if ($termino !== null) {
    // Placeholders con nombre distinto por cada ocurrencia: con
    // PDO::ATTR_EMULATE_PREPARES en false (ver conexion.php), MySQL no
    // permite reusar el mismo :param varias veces en la misma query.
    $condiciones[] = "(nombre LIKE :termino1 OR marca LIKE :termino2 OR sku LIKE :termino3)";
    $like = "%$termino%";
    $parametros[':termino1'] = $like;
    $parametros[':termino2'] = $like;
    $parametros[':termino3'] = $like;
}

if ($categoria !== null) {
    $condiciones[] = "categoria = :categoria";
    $parametros[':categoria'] = $categoria;
}

if ($solo_bajo) {
    $condiciones[] = "stock_actual <= stock_minimo";
}

$where = count($condiciones) > 0 ? "WHERE " . implode(" AND ", $condiciones) : "";

try {
    $stmt = $pdo->prepare(
        "SELECT id, sku, nombre, marca, variante, categoria, precio_venta, stock_actual, stock_minimo
         FROM productos
         $where
         ORDER BY stock_actual ASC
         LIMIT 50"
    );
    $stmt->execute($parametros);
    $productos = $stmt->fetchAll();

    echo json_encode([
        "ok" => true,
        "productos" => $productos,
        "total" => count($productos),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al consultar el stock",
        "error" => $e->getMessage(),
    ]);
}
