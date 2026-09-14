<?php
/**
 * stockIAte - consultar_notificaciones.php
 * ================================
 * El historial: las últimas notificaciones del negocio con su estado y el
 * error crudo que devolvió Meta.
 *
 * La lista no es decorativa: es LA herramienta de diagnóstico. "No me llega
 * nada" tiene muchas causas distintas (el negocio está desactivado, el
 * teléfono está mal escrito, la plantilla no está aprobada, el token venció,
 * el destinatario no está en la lista de prueba) y todas se distinguen
 * mirando la columna `error` de esta tabla.
 *
 * QUÉ NO DEVUELVE
 * ---------------
 * La configuración del negocio, que antes salía por acá, se mudó a
 * `consultar_preferencias.php` cuando el panel pasó a tener una sección de
 * preferencias única (teléfono + umbrales juntos). Este endpoint quedó con lo
 * que de verdad es suyo: qué se avisó y cómo salió.
 *
 * Sólo el rol 'dueño': expone a qué teléfono se avisó, igual criterio que
 * `consultar_rentabilidad.php`.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'notificaciones.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    $recientes = [];
    foreach (notificaciones_recientes($pdo, $negocio_id) as $fila) {
        $parametros = json_decode($fila['parametros'], true);

        $recientes[] = [
            'id' => (int) $fila['id'],
            'tipo' => $fila['tipo'],
            'destino' => $fila['destino'],
            'estado' => $fila['estado'],
            'intentos' => (int) $fila['intentos'],
            'error' => $fila['error'],
            'creada_en' => $fila['creada_en'],
            'enviada_en' => $fila['enviada_en'],
            // Se manda el texto ya armado en vez del array de variables: al
            // panel le sirve ver QUÉ se avisó, no cómo quedó dividido en
            // {{1}}, {{2}}...
            'resumen' => is_array($parametros) ? implode(' · ', $parametros) : '',
        ];
    }

    responder([
        'ok' => true,
        'notificaciones' => $recientes,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al consultar las notificaciones',
        'error' => $e->getMessage(),
    ], 500);
}
