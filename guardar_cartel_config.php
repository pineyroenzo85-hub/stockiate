<?php
/**
 * stockIAte - guardar_cartel_config.php
 * ================================
 * Guarda el diseño del cartel de góndola.
 *
 * ACTUALIZACIÓN PARCIAL, igual que `guardar_preferencias.php` y por el mismo
 * motivo: la pantalla guarda campo por campo al salir de cada control, y
 * mandar todo siempre haría que mover un tamaño pisara un texto que se está
 * editando en otra pestaña. `array_key_exists` para distinguir "no lo mandé"
 * de "lo mandé vacío" (= borrar el texto del pie).
 *
 * LOS RANGOS SE VALIDAN ACÁ, NO EN EL HTML
 * ----------------------------------------
 * Los `min`/`max` de los inputs son una sugerencia del navegador. Quien
 * garantiza que el precio no baje de 40pt —que es lo único que hace que un
 * cartel se lea desde la góndola— es el servidor. Los números salen de
 * `CARTEL_LIMITES` (`cartel_config.php`), el mismo lugar del que
 * `consultar_cartel_config.php` los toma para armar esos min/max.
 *
 * Y se RECHAZA con 400 en vez de recortar en silencio: recortar dejaría al
 * dueño mirando un número distinto del que escribió.
 *
 * Espera un body con cualquier subconjunto de:
 * {
 *   "diseno": "clasico" | "oferta" | "compacto",
 *   "precio_pt": 56,               // 40..72
 *   "nombre_pt": 15,               // 12..22
 *   "mostrar_marca": true,
 *   "mostrar_sku": true,
 *   "mostrar_vencimiento": true,
 *   "texto_pie": "Perfumería Aroma",
 *   "logo_posicion": "arriba" | "pie" | "oculto"
 * }
 *
 * El LOGO no se toca desde acá: se sube por `subir_logo.php`, que es
 * multipart y tiene su propia validación.
 *
 * Sólo el rol 'dueño'. El `negocio_id` sale de la sesión, nunca del body.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';
require_once 'cartel_config.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

/** Valida un tamaño en puntos contra su rango y corta con 400 si no entra. */
function exigir_puntos($valor, string $clave): int
{
    $limite = CARTEL_LIMITES[$clave];

    $nombre = $clave === 'precio_pt' ? 'del precio' : 'del nombre';

    if (!is_numeric($valor) || (int) $valor != $valor) {
        responder([
            'ok' => false,
            'mensaje' => "El tamaño $nombre tiene que ser un número entero de puntos.",
        ], 400);
    }

    $entero = (int) $valor;

    if ($entero < $limite['min'] || $entero > $limite['max']) {
        responder([
            'ok' => false,
            'mensaje' => "El tamaño $nombre tiene que estar entre {$limite['min']} y {$limite['max']} puntos. "
                . ($clave === 'precio_pt'
                    ? 'Por debajo de 40 el precio deja de leerse desde la góndola.'
                    : 'Por debajo de 12 el texto deja de leerse impreso.'),
        ], 400);
    }

    return $entero;
}

$cambios = [];

if (array_key_exists('diseno', $body)) {
    $diseno = is_string($body['diseno']) ? $body['diseno'] : '';

    // Whitelist estricta: nunca se guarda texto libre que después va a
    // terminar como un atributo del HTML del cartel.
    if (!isset(CARTEL_DISENOS[$diseno])) {
        responder(['ok' => false, 'mensaje' => 'Ese diseño de cartel no existe.'], 400);
    }

    $cambios['cartel_diseno'] = $diseno;
}

if (array_key_exists('precio_pt', $body)) {
    $cambios['cartel_precio_pt'] = (string) exigir_puntos($body['precio_pt'], 'precio_pt');
}

if (array_key_exists('nombre_pt', $body)) {
    $cambios['cartel_nombre_pt'] = (string) exigir_puntos($body['nombre_pt'], 'nombre_pt');
}

foreach (['mostrar_marca', 'mostrar_sku', 'mostrar_vencimiento'] as $campo) {
    if (array_key_exists($campo, $body)) {
        $cambios['cartel_' . $campo] = !empty($body[$campo]) ? '1' : '0';
    }
}

if (array_key_exists('texto_pie', $body)) {
    $texto = is_string($body['texto_pie']) ? trim($body['texto_pie']) : '';

    if (mb_strlen($texto) > CARTEL_PIE_MAXIMO) {
        responder([
            'ok' => false,
            'mensaje' => 'El texto del pie no puede tener más de ' . CARTEL_PIE_MAXIMO . ' caracteres: '
                . 'más largo que eso no entra en el cartel sin achicar la letra por debajo de lo legible.',
        ], 400);
    }

    // Los saltos de línea se aplastan a espacios: el pie es un renglón, y un
    // texto multilínea empujaría el precio fuera del cartel.
    $cambios['cartel_texto_pie'] = preg_replace('/\s+/u', ' ', $texto);
}

if (array_key_exists('logo_posicion', $body)) {
    $posicion = is_string($body['logo_posicion']) ? $body['logo_posicion'] : '';

    if (!isset(CARTEL_POSICIONES_LOGO[$posicion])) {
        responder(['ok' => false, 'mensaje' => 'Esa posición de logo no existe.'], 400);
    }

    $cambios['cartel_logo_posicion'] = $posicion;
}

if (count($cambios) === 0) {
    responder(['ok' => false, 'mensaje' => 'No hay nada para actualizar'], 400);
}

try {
    foreach ($cambios as $clave => $valor) {
        guardar_config($pdo, $negocio_id, $clave, $valor);
    }

    // Se relee en vez de devolver lo que mandó el cliente, mismo criterio que
    // `actualizar_costo.php`: la pantalla repinta contra lo que quedó guardado.
    responder([
        'ok' => true,
        'config' => leer_cartel_config($pdo, $negocio_id),
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al guardar el diseño del cartel',
        'error' => $e->getMessage(),
    ], 500);
}
