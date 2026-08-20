<?php
/**
 * stockIAte - consultar_inventario.php
 * ================================
 * Endpoint de solo lectura usado por el dashboard de Administrador y por
 * la vista de inventario de Repositor. Devuelve el catálogo completo con
 * stock real y los agregados (KPIs) que antes se simulaban en el frontend
 * con localStorage.
 *
 * No recibe parámetros: el filtrado por texto se hace client-side sobre el
 * array ya traído (mismo criterio que ya usa repositor.html con CATALOGO).
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

try {
    $stmtProductos = $pdo->query(
        "SELECT id, sku, nombre, marca, variante, categoria, precio_venta, stock_actual, stock_minimo
         FROM productos
         ORDER BY nombre ASC"
    );
    $productos = $stmtProductos->fetchAll();

    $criticos = 0;
    foreach ($productos as $p) {
        if ((int) $p['stock_actual'] <= (int) $p['stock_minimo']) {
            $criticos++;
        }
    }

    $stmtVentasHoy = $pdo->query(
        "SELECT COALESCE(SUM(cantidad), 0) AS unidades, COALESCE(SUM(cantidad * precio_unitario), 0) AS monto
         FROM ventas
         WHERE fecha >= CURDATE()"
    );
    $ventasHoy = $stmtVentasHoy->fetch();

    $stmtTopMes = $pdo->query(
        "SELECT p.id, p.nombre, p.marca, SUM(v.cantidad) AS unidades_vendidas
         FROM ventas v
         JOIN productos p ON p.id = v.producto_id
         WHERE v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
         GROUP BY p.id, p.nombre, p.marca
         ORDER BY unidades_vendidas DESC
         LIMIT 1"
    );
    $topMes = $stmtTopMes->fetch();

    echo json_encode([
        "ok" => true,
        "productos" => $productos,
        "total_productos" => count($productos),
        "criticos" => $criticos,
        "ventas_hoy_unidades" => (int) $ventasHoy['unidades'],
        "ventas_hoy_monto" => (float) $ventasHoy['monto'],
        "producto_mas_vendido" => $topMes ?: null,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al consultar el inventario",
        "error" => $e->getMessage(),
    ]);
}
