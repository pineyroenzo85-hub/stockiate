<?php
/**
 * stockIAte - actualizar_configuracion.php
 * ================================
 * Actualiza una clave de `configuracion` ("Preferencias del negocio" en
 * ajustes.html, sólo rol "dueño"). Sin sesión de servidor: el rol viene
 * del body igual que en /chatbot -- no es seguridad real, es el mismo gap
 * ya documentado en el resto del proyecto.
 *
 * Whitelist de claves editables (agregar acá cuando haya más):
 *   - umbral_dias_vencimiento: entero >= 0 (lo usa consultar_vencimientos.php)
 *
 * Espera un body tipo:
 * { "rol": "dueño", "clave": "umbral_dias_vencimiento", "valor": "45" }
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

$body = json_decode(file_get_contents("php://input"), true);
$claves_editables = ['umbral_dias_vencimiento'];

if (!isset($body['rol'], $body['clave'], $body['valor']) || trim($body['clave']) === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

if ($body['rol'] !== 'dueño') {
    http_response_code(403);
    echo json_encode(["ok" => false, "mensaje" => "No tenés permiso para editar la configuración"]);
    exit();
}

$clave = trim($body['clave']);
$valor = (string) $body['valor'];

if (!in_array($clave, $claves_editables, true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Esa clave de configuración no se puede editar"]);
    exit();
}

if ($clave === 'umbral_dias_vencimiento' && (!ctype_digit($valor) || (int) $valor < 0)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El umbral de vencimiento debe ser un número de días mayor o igual a 0"]);
    exit();
}

try {
    // Placeholder distinto por ocurrencia: con EMULATE_PREPARES en false
    // (conexion.php) no se puede reusar :valor dos veces en la misma query
    // (mismo comentario que consultar_stock.php).
    $stmt = $pdo->prepare(
        "INSERT INTO configuracion (clave, valor) VALUES (:clave, :valor)
         ON DUPLICATE KEY UPDATE valor = :valor2"
    );
    $stmt->execute([':clave' => $clave, ':valor' => $valor, ':valor2' => $valor]);

    echo json_encode(["ok" => true, "mensaje" => "Configuración actualizada correctamente", "clave" => $clave, "valor" => $valor]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error al actualizar la configuración", "error" => $e->getMessage()]);
}
