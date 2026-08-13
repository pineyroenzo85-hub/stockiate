<?php
/**
 * stockIAte - consultar_vencimientos.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_vencimientos") para responder preguntas sobre lotes
 * próximos a vencer.
 *
 * Espera un body tipo:
 * {
 *   "dias": 30   // opcional, default: configuracion.umbral_dias_vencimiento
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

$dias = isset($body['dias']) ? (int) $body['dias'] : null;

if ($dias === null) {
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'umbral_dias_vencimiento'");
    $stmtConfig->execute();
    $config = $stmtConfig->fetch();
    $dias = $config ? (int) $config['valor'] : 30;
}

try {
    $stmt = $pdo->prepare(
        "SELECT l.id AS lote_id, l.cantidad, l.fecha_vencimiento, l.fecha_carga,
                p.id AS producto_id, p.nombre, p.marca, p.sku
         FROM lotes_stock l
         JOIN productos p ON p.id = l.producto_id
         WHERE l.fecha_vencimiento IS NOT NULL
           AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL :dias DAY)
         ORDER BY l.fecha_vencimiento ASC
         LIMIT 50"
    );
    $stmt->execute([':dias' => $dias]);
    $lotes = $stmt->fetchAll();

    echo json_encode([
        "ok" => true,
        "dias_ventana" => $dias,
        "lotes" => $lotes,
        "total" => count($lotes),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al consultar vencimientos",
        "error" => $e->getMessage(),
    ]);
}
