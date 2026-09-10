<?php
/**
 * stockIAte - consultar_preferencias.php
 * ================================
 * Todo lo que el negocio puede configurar sin que nadie toque código ni base,
 * en un solo lugar: a qué número avisar, si los avisos están activados, a qué
 * hora sale el resumen, con cuántos días de anticipación avisar un
 * vencimiento, cada cuánto repetir el aviso de un mismo producto, y con qué
 * stock mínimo nacen los productos nuevos.
 *
 * POR QUÉ UNO SOLO Y NO UNO POR TEMA
 * ----------------------------------
 * Todas estas claves viven en la misma tabla (`configuracion`, PK
 * `(negocio_id, clave)`) y las pinta la misma sección del panel. Partirlo en
 * "config de WhatsApp" por un lado y "umbrales" por otro obligaba al frontend
 * a dos requests para llenar un solo formulario, y a mantener dos endpoints
 * que hacen exactamente lo mismo con distinta lista de claves.
 *
 * Lo que NO está acá es el historial de notificaciones enviadas: eso es
 * diagnóstico, no configuración, y sigue en `consultar_notificaciones.php`.
 *
 * Sólo el rol 'dueño': expone un teléfono personal y son decisiones del
 * negocio, mismo criterio que `consultar_rentabilidad.php`. El `negocio_id`
 * sale de la sesión, nunca del body.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';
require_once 'notificaciones.php'; // config_whatsapp(), whatsapp_configurado()

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    $whatsapp = config_whatsapp($pdo, $negocio_id);

    responder([
        'ok' => true,
        'preferencias' => [
            'telefono' => $whatsapp['telefono'],
            'activo' => $whatsapp['activo'],
            'hora_resumen' => $whatsapp['hora_resumen'],
            // Cómo va a quedar el número que se manda a Meta. El panel lo
            // muestra al lado del input: es la forma más rápida de darse
            // cuenta de que faltó un dígito o sobró el 15.
            'telefono_normalizado' => normalizar_telefono($whatsapp['telefono']),
            'umbral_dias_vencimiento' => leer_config_int($pdo, $negocio_id, 'umbral_dias_vencimiento'),
            'ventana_notificaciones_horas' => leer_config_int($pdo, $negocio_id, 'ventana_notificaciones_horas'),
            'stock_minimo_default' => leer_config_int($pdo, $negocio_id, 'stock_minimo_default'),
            'reposicion_dias_entrega' => leer_config_int($pdo, $negocio_id, 'reposicion_dias_entrega'),
            'reposicion_dias_objetivo' => leer_config_int($pdo, $negocio_id, 'reposicion_dias_objetivo'),
            // El único decimal de la tabla: ver leer_config_float().
            'reposicion_factor_seguridad' => leer_config_float($pdo, $negocio_id, 'reposicion_factor_seguridad'),
        ],
        // Esto NO es configuración del negocio sino del servidor (el `.env`),
        // y es igual para todos los negocios. Se informa para que el panel
        // pueda distinguir "no configuraste tu número" de "el sistema todavía
        // no tiene credenciales de Meta", que se arreglan en lugares
        // distintos y por personas distintas.
        'servidor_configurado' => whatsapp_configurado(),
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al consultar las preferencias',
        'error' => $e->getMessage(),
    ], 500);
}
