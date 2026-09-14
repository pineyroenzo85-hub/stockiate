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
 *
 * El umbral por defecto ahora es POR NEGOCIO: `configuracion` pasó de tener
 * PK `clave` (una fila global para todo el sistema) a PK (negocio_id, clave).
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'alertas.php'; // queries compartidas con tareas_notificaciones.php

cabeceras_json();
exigir_metodo('POST');

// Repositor y dueño son los que miran vencimientos (coincide con ROLE_TOOLS
// en chatbot_ia.py; la restricción real es ésta).
$negocio_id = exigir_sesion(['repositor', 'dueño'])['negocio_id'];

$body = cuerpo_json();

$dias = isset($body['dias']) ? (int) $body['dias'] : null;

try {
    // Esta lectura estaba FUERA del try: si fallaba, PHP devolvía un fatal en
    // HTML en vez de un JSON de error, y el chatbot reventaba al parsearlo.
    if ($dias === null) {
        $dias = dias_vencimiento_config($pdo, $negocio_id);
    }

    $lotes = lotes_por_vencer($pdo, $negocio_id, $dias);

    echo json_encode([
        "ok" => true,
        "dias_ventana" => $dias,
        "lotes" => $lotes,
        "total" => count($lotes),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar vencimientos",
        "error" => $e->getMessage(),
    ], 500);
}
