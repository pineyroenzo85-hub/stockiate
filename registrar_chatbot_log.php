<?php
/**
 * stockIAte - registrar_chatbot_log.php
 * ================================
 * Guarda cada intercambio pregunta/respuesta del chatbot IA en
 * chatbot_conversaciones, para auditoría y evaluación de calidad.
 * Lo llama main.py (endpoint /chatbot) en modo best-effort: si esto
 * falla, no debe romper la respuesta que ya se le mostró al usuario.
 *
 * Espera un body tipo:
 * {
 *   "rol": "repositor",
 *   "usuario_id": 1,
 *   "pregunta": "¿qué productos tienen stock bajo?",
 *   "respuesta": "Tenés 3 productos con stock bajo: ...",
 *   "herramientas_usadas": "consultar_stock"
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

if (!isset($body['rol'], $body['pregunta'], $body['respuesta'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan campos obligatorios"]);
    exit();
}

$rol = $body['rol'];
$usuario_id = isset($body['usuario_id']) ? (int) $body['usuario_id'] : null;
$pregunta = $body['pregunta'];
$respuesta = $body['respuesta'];
$herramientas_usadas = isset($body['herramientas_usadas']) ? $body['herramientas_usadas'] : null;

try {
    $stmt = $pdo->prepare(
        "INSERT INTO chatbot_conversaciones (rol, usuario_id, pregunta, respuesta, herramientas_usadas)
         VALUES (:rol, :usuario_id, :pregunta, :respuesta, :herramientas_usadas)"
    );
    $stmt->execute([
        ':rol' => $rol,
        ':usuario_id' => $usuario_id,
        ':pregunta' => $pregunta,
        ':respuesta' => $respuesta,
        ':herramientas_usadas' => $herramientas_usadas,
    ]);

    echo json_encode(["ok" => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error al guardar el log del chatbot",
        "error" => $e->getMessage(),
    ]);
}
