<?php
/**
 * stockIAte - guardar_preferencias.php
 * ================================
 * Escribe las preferencias del negocio en `configuracion`. Reemplaza al viejo
 * `guardar_config_whatsapp.php`, que hacía lo mismo pero sólo con las tres
 * claves de WhatsApp: al sumar los umbrales (vencimiento, ventana de avisos,
 * stock mínimo) iban a ser dos endpoints con la misma lógica y distinta lista.
 *
 * ACTUALIZACIÓN PARCIAL
 * ---------------------
 * Sólo se tocan los campos que vengan en el body, igual que
 * `actualizar_costo.php` y por el mismo motivo: la pantalla guarda campo por
 * campo a medida que se sale de cada input, y mandar todo siempre haría que
 * activar el switch pisara un teléfono que se está editando en otra pestaña.
 * Se usa `array_key_exists` para poder distinguir "no lo mandé" de "lo mandé
 * vacío" (= borrar el teléfono).
 *
 * Espera un body tipo (todos opcionales, se manda de a uno):
 * {
 *   "telefono": "11 5555-4444",           // "" borra el número
 *   "activo": true,
 *   "hora_resumen": "20:00",
 *   "umbral_dias_vencimiento": 30,        // 1..365
 *   "ventana_notificaciones_horas": 24,   // 1..168 (una semana)
 *   "stock_minimo_default": 5,            // 0..9999
 *   "reposicion_dias_entrega": 7,         // 1..120
 *   "reposicion_dias_objetivo": 30,       // 1..365
 *   "reposicion_factor_seguridad": 1.5    // 0..5, decimal
 * }
 *
 * POR QUÉ SE VALIDAN LOS RANGOS ACÁ
 * ---------------------------------
 * Los `<input type=number>` del panel ya tienen min/max, pero eso es una
 * sugerencia del navegador: quien garantiza que no entre un 0 en la ventana
 * de avisos (que dejaría pasar un WhatsApp por cada unidad vendida) es el
 * servidor. Mismo criterio que el precio de venta en `registrar_venta.php`.
 *
 * Sólo el rol 'dueño'. El `negocio_id` sale de la sesión, nunca del body: es
 * lo único que impide que alguien redirija las alertas de otro comercio a su
 * propio celular.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';
require_once 'notificaciones.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

/**
 * Valida un entero dentro de un rango y corta con 400 si no entra.
 *
 * No se hace `max(min(...))` como en los endpoints de sólo lectura
 * (`consultar_prediccion_quiebre.php` recorta y sigue) porque acá el valor se
 * GUARDA: recortar en silencio dejaría al dueño mirando un número distinto
 * del que escribió, sin ningún cartel que lo explique.
 */
function exigir_entero($valor, int $min, int $max, string $mensaje): int
{
    if (!is_numeric($valor) || (int) $valor != $valor) {
        responder(['ok' => false, 'mensaje' => $mensaje], 400);
    }

    $entero = (int) $valor;

    if ($entero < $min || $entero > $max) {
        responder(['ok' => false, 'mensaje' => $mensaje], 400);
    }

    return $entero;
}

$cambios = [];

if (array_key_exists('telefono', $body)) {
    $telefono = is_string($body['telefono']) ? trim($body['telefono']) : '';

    // Se guarda lo que escribió la persona, no la versión normalizada: si más
    // adelante cambia la regla de normalización (o resulta que estaba mal),
    // el número original sigue ahí para reinterpretarlo. La normalización se
    // aplica al encolar y al enviar.
    if ($telefono !== '' && normalizar_telefono($telefono) === null) {
        responder([
            'ok' => false,
            'mensaje' => 'Ese teléfono no se entiende. Poné el número con característica, '
                . 'sin el 0 y sin el 15. Ejemplo: 11 5555-4444',
        ], 400);
    }

    $cambios['whatsapp_telefono'] = $telefono;
}

if (array_key_exists('activo', $body)) {
    $cambios['whatsapp_activo'] = !empty($body['activo']) ? '1' : '0';
}

if (array_key_exists('hora_resumen', $body)) {
    $hora = is_string($body['hora_resumen']) ? trim($body['hora_resumen']) : '';

    // 'HH:MM' en 24 h y con cero adelante. El formato importa: el script
    // programado compara esta hora como TEXTO contra date('H:i'), y '9:00'
    // sin el cero compararía mal contra cualquier hora de dos dígitos.
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        responder([
            'ok' => false,
            'mensaje' => 'La hora del resumen tiene que estar en formato HH:MM (por ejemplo, 20:00)',
        ], 400);
    }

    $cambios['whatsapp_hora_resumen'] = $hora;
}

if (array_key_exists('umbral_dias_vencimiento', $body)) {
    $cambios['umbral_dias_vencimiento'] = (string) exigir_entero(
        $body['umbral_dias_vencimiento'],
        1,
        365,
        'Los días de anticipación para vencimientos tienen que ser un número entre 1 y 365.'
    );
}

if (array_key_exists('ventana_notificaciones_horas', $body)) {
    // El piso de 1 h no es decorativo: en 0 la deduplicación se apaga entera y
    // una venta de diez unidades de a una manda diez WhatsApp iguales, que es
    // justo el problema que la ventana vino a resolver.
    $cambios['ventana_notificaciones_horas'] = (string) exigir_entero(
        $body['ventana_notificaciones_horas'],
        1,
        168,
        'Las horas entre avisos repetidos tienen que ser un número entre 1 y 168 (una semana).'
    );
}

if (array_key_exists('stock_minimo_default', $body)) {
    // El 0 sí se permite: es "avisame recién cuando me quede sin nada", una
    // elección razonable para un negocio que repone todos los días.
    $cambios['stock_minimo_default'] = (string) exigir_entero(
        $body['stock_minimo_default'],
        0,
        9999,
        'El stock mínimo por defecto tiene que ser un número entre 0 y 9999.'
    );
}

if (array_key_exists('reposicion_dias_entrega', $body)) {
    $cambios['reposicion_dias_entrega'] = (string) exigir_entero(
        $body['reposicion_dias_entrega'],
        1,
        120,
        'Los días de entrega del proveedor tienen que ser un número entre 1 y 120.'
    );
}

if (array_key_exists('reposicion_dias_objetivo', $body)) {
    $cambios['reposicion_dias_objetivo'] = (string) exigir_entero(
        $body['reposicion_dias_objetivo'],
        1,
        365,
        'Los días de mercadería a pedir tienen que ser un número entre 1 y 365.'
    );
}

if (array_key_exists('reposicion_factor_seguridad', $body)) {
    // El único campo decimal del panel, así que no pasa por exigir_entero().
    // El 0 se permite: es "pedí justo el consumo del plazo de entrega, sin
    // colchón", que para un proveedor que nunca falla es razonable.
    $factor = $body['reposicion_factor_seguridad'];

    if (!is_numeric($factor) || (float) $factor < 0 || (float) $factor > 5) {
        responder([
            'ok' => false,
            'mensaje' => 'El colchón de seguridad tiene que ser un número entre 0 y 5.',
        ], 400);
    }

    // Se guarda con un decimal para que el panel repinte exactamente lo que
    // se guardó: sin el round, escribir 1.4999 dejaba el input mostrando un
    // número distinto del que se ve en la lista de reposición.
    $cambios['reposicion_factor_seguridad'] = (string) round((float) $factor, 1);
}

if (count($cambios) === 0) {
    responder(['ok' => false, 'mensaje' => 'No hay nada para actualizar'], 400);
}

try {
    foreach ($cambios as $clave => $valor) {
        guardar_config($pdo, $negocio_id, $clave, $valor);
    }

    // Se relee en vez de devolver lo que mandó el cliente, mismo criterio que
    // actualizar_costo.php: el panel repinta contra lo que quedó guardado.
    $whatsapp = config_whatsapp($pdo, $negocio_id);

    responder([
        'ok' => true,
        'preferencias' => [
            'telefono' => $whatsapp['telefono'],
            'activo' => $whatsapp['activo'],
            'hora_resumen' => $whatsapp['hora_resumen'],
            'telefono_normalizado' => normalizar_telefono($whatsapp['telefono']),
            'umbral_dias_vencimiento' => leer_config_int($pdo, $negocio_id, 'umbral_dias_vencimiento'),
            'ventana_notificaciones_horas' => leer_config_int($pdo, $negocio_id, 'ventana_notificaciones_horas'),
            'stock_minimo_default' => leer_config_int($pdo, $negocio_id, 'stock_minimo_default'),
            'reposicion_dias_entrega' => leer_config_int($pdo, $negocio_id, 'reposicion_dias_entrega'),
            'reposicion_dias_objetivo' => leer_config_int($pdo, $negocio_id, 'reposicion_dias_objetivo'),
            'reposicion_factor_seguridad' => leer_config_float($pdo, $negocio_id, 'reposicion_factor_seguridad'),
        ],
        'servidor_configurado' => whatsapp_configurado(),
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al guardar las preferencias',
        'error' => $e->getMessage(),
    ], 500);
}
