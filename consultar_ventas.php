<?php
/**
 * stockIAte - consultar_ventas.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_ventas") para responder preguntas sobre ventas.
 *
 * Espera un body tipo:
 * {
 *   "desde": "2026-07-01",      // opcional, default: hace 30 días
 *   "hasta": "2026-08-10",      // opcional, default: hoy
 *   "producto_id": 12,          // opcional
 *   "agrupar_por": "producto"   // opcional: producto | marca | dia (default producto)
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

$desde = !empty($body['desde']) ? $body['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta = !empty($body['hasta']) ? $body['hasta'] : date('Y-m-d');
$producto_id = !empty($body['producto_id']) ? (int) $body['producto_id'] : null;

// Whitelist estricta: nunca se interpola texto libre del usuario en el SQL.
$agrupaciones = [
    'producto' => 'p.nombre',
    'marca' => 'p.marca',
    'dia' => 'DATE(v.fecha)',
];
$agrupar_por = in_array($body['agrupar_por'] ?? null, array_keys($agrupaciones), true)
    ? $body['agrupar_por']
    : 'producto';
$columnaAgrupacion = $agrupaciones[$agrupar_por];

$condiciones = ["v.fecha >= :desde", "v.fecha < DATE_ADD(:hasta, INTERVAL 1 DAY)"];
$parametros = [':desde' => $desde, ':hasta' => $hasta];

if ($producto_id !== null) {
    $condiciones[] = "v.producto_id = :producto_id";
    $parametros[':producto_id'] = $producto_id;
}

$where = "WHERE " . implode(" AND ", $condiciones);

try {
    $stmt = $pdo->prepare(
        "SELECT $columnaAgrupacion AS etiqueta,
                SUM(v.cantidad) AS unidades_vendidas,
                SUM(v.cantidad * v.precio_unitario) AS total_vendido
         FROM ventas v
         JOIN productos p ON p.id = v.producto_id
         $where
         GROUP BY $columnaAgrupacion
         ORDER BY total_vendido DESC
         LIMIT 20"
    );
    $stmt->execute($parametros);
    $resultados = $stmt->fetchAll();

    $stmtTotal = $pdo->prepare(
        "SELECT SUM(v.cantidad) AS unidades_totales, SUM(v.cantidad * v.precio_unitario) AS monto_total
         FROM ventas v
         $where"
    );
    $stmtTotal->execute($parametros);
    $totales = $stmtTotal->fetch();

    echo json_encode([
        "ok" => true,
        "desde" => $desde,
        "hasta" => $hasta,
        "agrupado_por" => $agrupar_por,
        "resultados" => $resultados,
        "unidades_totales" => (int) ($totales['unidades_totales'] ?? 0),
        "monto_total" => (float) ($totales['monto_total'] ?? 0),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al consultar las ventas",
        "error" => $e->getMessage(),
    ]);
}
