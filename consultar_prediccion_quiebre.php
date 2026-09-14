<?php
/**
 * stockIAte - consultar_prediccion_quiebre.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_prediccion_quiebre") y por el panel de administrador para
 * responder "¿qué se me va a acabar pronto?" -- productos que TODAVÍA no
 * están en stock crítico (ese caso ya lo cubre la alerta de
 * registrar_venta.php) pero que al ritmo de venta actual se agotan dentro
 * de la ventana de aviso.
 *
 * La lógica vive en alertas.php (productos_en_riesgo_quiebre()), el mismo
 * archivo que ya comparten las pantallas y tareas_notificaciones.php -- ver
 * el docstring de ese archivo para el porqué de esa separación.
 *
 * Espera un body tipo:
 * {
 *   "dias_umbral": 7,   // opcional, 1..60 (default 7): sólo productos que
 *                       // se agotarían dentro de este plazo
 *   "dias_ventana": 14, // opcional, 3..90 (default 14): sobre cuántos días
 *                       // de ventas se calcula el ritmo
 *   "limite": 50        // opcional, 1..200 (default 50)
 * }
 *
 * SÓLO EL DUEÑO, mismo criterio que consultar_rentabilidad.php: es una
 * herramienta de decisión de reposición/compra, no algo que el repositor o
 * el cajero necesiten para su tarea del día a día.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once __DIR__ . '/alertas.php'; // productos_en_riesgo_quiebre()

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

$dias_umbral = isset($body['dias_umbral']) ? (int) $body['dias_umbral'] : 7;
$dias_umbral = max(1, min(60, $dias_umbral));

$dias_ventana = isset($body['dias_ventana']) ? (int) $body['dias_ventana'] : 14;
$dias_ventana = max(3, min(90, $dias_ventana));

$limite = isset($body['limite']) ? (int) $body['limite'] : 50;
$limite = max(1, min(200, $limite));

try {
    $productos = productos_en_riesgo_quiebre($pdo, $negocio_id, $dias_umbral, $dias_ventana, $limite);

    echo json_encode([
        "ok" => true,
        "dias_umbral" => $dias_umbral,
        "dias_ventana" => $dias_ventana,
        "productos" => $productos,
        "total" => count($productos),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al calcular la predicción de quiebre de stock",
        "error" => $e->getMessage(),
    ], 500);
}
