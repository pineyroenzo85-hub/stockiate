<?php
/**
 * stockIAte - consultar_cartel_config.php
 * ================================
 * El diseño del cartel de góndola guardado por este negocio.
 *
 * POR QUÉ NO VA EN `consultar_preferencias.php`
 * ---------------------------------------------
 * Aquél existe porque las claves de WhatsApp y los umbrales los pinta UN SOLO
 * formulario, y partirlo obligaba al frontend a dos requests para llenarlo.
 * Acá es al revés: esto lo usa otra pantalla (`carteles.html`), que el panel
 * de administrador no abre nunca. Meterlo en el endpoint del panel haría que
 * cada carga del dashboard trajera nueve claves que no va a mirar.
 *
 * Comparten el almacenamiento (`configuracion`) y los helpers
 * (`configuracion.php`), que es lo que importa: los defaults siguen viviendo
 * en un solo lugar.
 *
 * Sólo el rol 'dueño', igual que toda la pantalla de carteles.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';
require_once 'cartel_config.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    responder([
        'ok' => true,
        'config' => leer_cartel_config($pdo, $negocio_id),
        // Los rangos los manda el servidor y no están escritos en el HTML: son
        // los mismos que valida `guardar_cartel_config.php`, así que los
        // min/max de los inputs no pueden quedar desalineados con lo que el
        // servidor acepta.
        'limites' => CARTEL_LIMITES,
        'disenos' => CARTEL_DISENOS,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al consultar el diseño del cartel',
        'error' => $e->getMessage(),
    ], 500);
}
