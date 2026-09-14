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
 *   "pregunta": "¿qué productos tienen stock bajo?",
 *   "respuesta": "Tenés 3 productos con stock bajo: ...",
 *   "herramientas_usadas": "consultar_stock"
 * }
 *
 * `rol` y `usuario_id` YA NO se leen del body: salen de la sesión, que
 * main.py resuelve reenviando la cookie del navegador. Antes se guardaba lo
 * que mandara el cliente, sin verificar que el usuario existiera ni que el
 * rol fuera el suyo -- el log de auditoría era falsificable.
 *
 * `chatbot_conversaciones` es la única tabla sin `negocio_id` propio: es un
 * log, y el negocio se deriva por usuario_id -> usuarios.negocio_id.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion();
$rol = $sesion['rol'];
$usuario_id = $sesion['id'];

$body = cuerpo_json();

if (!isset($body['pregunta'], $body['respuesta'])) {
    responder(["ok" => false, "mensaje" => "Faltan campos obligatorios"], 400);
}

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

    responder(["ok" => true]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al guardar el log del chatbot",
        "error" => $e->getMessage(),
    ], 500);
}
