<?php
/**
 * stockIAte - registrar_correccion.php
 * ================================
 * Recibe la corrección que hace el repositor en la pantalla de validación
 * (Red de Seguridad) cuando ajusta lo que detectó la IA (producto y/o
 * cantidad) y la persiste en correcciones_ia, para poder medir después
 * qué tan seguido se equivoca el modelo.
 * Lo llama main.py (endpoint /registrar-correccion).
 *
 * Espera un body tipo:
 * {
 *   "producto_detectado_id": 12,
 *   "producto_corregido_id": 12,
 *   "cantidad_detectada": 5,
 *   "cantidad_corregida": 4,
 *   "confianza_ia": 0.83
 * }
 *
 * `usuario_id` ya no viene en el body: sale de la sesión, igual que el
 * negocio. main.py reenvía la cookie del navegador al llamar acá.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion();
$negocio_id = $sesion['negocio_id'];
$usuario_id = $sesion['id'];

$body = cuerpo_json();

if (!isset($body['cantidad_detectada'], $body['cantidad_corregida'])) {
    responder(["ok" => false, "mensaje" => "Faltan campos obligatorios"], 400);
}

$producto_detectado_id = isset($body['producto_detectado_id']) ? (int) $body['producto_detectado_id'] : null;
$producto_corregido_id = isset($body['producto_corregido_id']) ? (int) $body['producto_corregido_id'] : null;
$cantidad_detectada = (int) $body['cantidad_detectada'];
$cantidad_corregida = (int) $body['cantidad_corregida'];
$confianza_ia = isset($body['confianza_ia']) ? (float) $body['confianza_ia'] : null;

/**
 * Los dos producto_id son opcionales (la IA puede no haber reconocido nada),
 * pero si vienen tienen que ser de ESTE negocio. Sin este chequeo, un id
 * ajeno quedaría guardado en el historial de correcciones de otro comercio.
 */
function producto_del_negocio(PDO $pdo, ?int $producto_id, int $negocio_id): bool
{
    if ($producto_id === null) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM productos WHERE id = :id AND negocio_id = :negocio_id LIMIT 1"
    );
    $stmt->execute([':id' => $producto_id, ':negocio_id' => $negocio_id]);
    return $stmt->fetchColumn() !== false;
}

try {
    if (!producto_del_negocio($pdo, $producto_detectado_id, $negocio_id)
        || !producto_del_negocio($pdo, $producto_corregido_id, $negocio_id)) {
        responder(["ok" => false, "mensaje" => "El producto no existe"], 404);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO correcciones_ia
            (negocio_id, producto_detectado_id, producto_corregido_id, cantidad_detectada, cantidad_corregida, confianza_ia, usuario_id)
         VALUES
            (:negocio_id, :producto_detectado_id, :producto_corregido_id, :cantidad_detectada, :cantidad_corregida, :confianza_ia, :usuario_id)"
    );
    $stmt->execute([
        ':negocio_id' => $negocio_id,
        ':producto_detectado_id' => $producto_detectado_id,
        ':producto_corregido_id' => $producto_corregido_id,
        ':cantidad_detectada' => $cantidad_detectada,
        ':cantidad_corregida' => $cantidad_corregida,
        ':confianza_ia' => $confianza_ia,
        ':usuario_id' => $usuario_id,
    ]);

    responder([
        "ok" => true,
        "mensaje" => "Corrección registrada correctamente",
        "id" => $pdo->lastInsertId(),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al guardar la corrección",
        "error" => $e->getMessage(),
    ], 500);
}
