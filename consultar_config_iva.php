<?php
/**
 * stockIAte - consultar_config_iva.php
 * ================================
 * Devuelve SÓLO la alícuota de IVA del negocio. Existe porque
 * consultar_preferencias.php es sólo-dueño (expone teléfono y demás
 * decisiones del negocio), pero cajero.html necesita la alícuota para pintar
 * "IVA contenido" debajo del total del ticket (Ley 27.743, Transparencia
 * Fiscal al Consumidor) y un cajero nunca tiene rol dueño.
 *
 * Cualquier sesión válida puede leerlo (no hay `exigir_sesion([...])` con
 * roles): un número de IVA no es un dato sensible, a diferencia del resto de
 * la configuración del negocio.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion()['negocio_id'];

responder([
    'ok' => true,
    'alicuota_iva' => leer_config_float($pdo, $negocio_id, 'alicuota_iva'),
]);
