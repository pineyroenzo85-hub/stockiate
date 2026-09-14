<?php
/**
 * stockIAte - consultar_configuracion.php
 * ================================
 * Endpoint de solo lectura sobre `configuracion` (clave-valor). Lo usa
 * "Preferencias del negocio" en ajustes.html para precargar valores.
 * Sin restricción de rol (mismo criterio que consultar_stock.php: dato
 * no sensible); la escritura sí está restringida a "dueño" en
 * actualizar_configuracion.php.
 *
 * Espera body vacío o {}.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'conexion.php';

try {
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
    $filas = $stmt->fetchAll();

    $configuracion = [];
    foreach ($filas as $fila) {
        $configuracion[$fila['clave']] = $fila['valor'];
    }

    echo json_encode(["ok" => true, "configuracion" => $configuracion]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error al consultar la configuración", "error" => $e->getMessage()]);
}
