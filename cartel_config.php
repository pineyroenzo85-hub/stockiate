<?php
/**
 * stockIAte - cartel_config.php
 * ================================
 * Los límites del diseño del cartel, y el lector compartido.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO EN VEZ DE PONER LOS NÚMEROS EN CADA ENDPOINT
 * ------------------------------------------------------------------------
 * Los rangos los usan tres lugares: `guardar_cartel_config.php` los valida,
 * `consultar_cartel_config.php` se los manda al frontend para que los inputs
 * tengan los mismos min/max, y `carteles.html` los aplica al dibujar. Con los
 * números copiados en cada uno, alcanzaba con tocar uno para que la pantalla
 * ofreciera un tamaño que el servidor rechaza. Mismo criterio que
 * `CONFIG_INICIAL_NEGOCIO` en `configuracion.php`.
 *
 * EL PISO NO ES UNA PREFERENCIA, ES EL PUNTO DE LA PANTALLA
 * ---------------------------------------------------------
 * Un cartel de góndola tiene un solo trabajo: leerse desde dos metros. El
 * precio no puede bajar de 40pt ni el resto de 12pt porque debajo de eso el
 * cartel deja de hacer ese trabajo, y quien lo descubre es el dueño cuando ya
 * lo imprimió y lo pegó. Por eso el editor deja mover los tamaños DENTRO de
 * ese rango y no fuera: es libertad acotada a propósito, no una limitación
 * que falte levantar.
 */

/**
 * Rango de cada valor numérico, y el default.
 *
 * `precio_pt` llega hasta 72 y no más porque con 8 carteles por hoja cada uno
 * mide 70.2mm (unos 199pt de alto) y hay que meter ahí el nombre, el precio y
 * el pie. El techo real de la hoja de 8 lo aplica el frontend escalando, sin
 * bajar nunca del piso; ver la nota de `crtPuntos()` en carteles.html.
 */
const CARTEL_LIMITES = [
    'precio_pt' => ['min' => 40, 'max' => 72, 'default' => 56],
    'nombre_pt' => ['min' => 12, 'max' => 22, 'default' => 15],
];

/** Cuánto texto libre entra al pie. `configuracion.valor` es VARCHAR(255). */
const CARTEL_PIE_MAXIMO = 60;

/**
 * Los diseños. Son tres a propósito: cada uno resuelve una situación distinta
 * de góndola, y agregar un cuarto "por si acaso" sólo hace más difícil elegir.
 */
const CARTEL_DISENOS = [
    'clasico' => 'Clásico — marca arriba, precio al medio',
    'oferta' => 'Oferta — banda ancha y precio dominante',
    'compacto' => 'Compacto — casi todo precio, para estantes chicos',
];

const CARTEL_POSICIONES_LOGO = [
    'arriba' => 'Arriba de todo',
    'pie' => 'Abajo, junto al texto',
    'oculto' => 'No mostrarlo',
];

/**
 * Lee el diseño del cartel de un negocio, ya tipado y con los defaults
 * aplicados.
 *
 * Cae a `CONFIG_INICIAL_NEGOCIO` clave por clave, igual que
 * `leer_config_int()`: un negocio creado antes de que existieran estas claves
 * no tiene las filas, y tiene que imprimir el cartel de siempre en vez de uno
 * en blanco.
 */
function leer_cartel_config(PDO $pdo, int $negocio_id): array
{
    $claves = [
        'cartel_diseno', 'cartel_precio_pt', 'cartel_nombre_pt',
        'cartel_mostrar_marca', 'cartel_mostrar_sku', 'cartel_mostrar_vencimiento',
        'cartel_texto_pie', 'cartel_logo', 'cartel_logo_posicion',
    ];

    $filas = leer_configs($pdo, $negocio_id, $claves);

    $valor = function (string $clave) use ($filas): string {
        $v = $filas[$clave] ?? '';
        // Un valor vacío cuenta como ausente EXCEPTO donde el vacío es una
        // elección válida (el texto del pie, el logo). Esos dos se resuelven
        // abajo sin pasar por acá.
        return $v !== '' ? $v : (string) (CONFIG_INICIAL_NEGOCIO[$clave] ?? '');
    };

    $diseno = $valor('cartel_diseno');
    if (!isset(CARTEL_DISENOS[$diseno])) {
        $diseno = 'clasico';
    }

    $posicion = $valor('cartel_logo_posicion');
    if (!isset(CARTEL_POSICIONES_LOGO[$posicion])) {
        $posicion = 'arriba';
    }

    $logo = $filas['cartel_logo'] ?? '';
    // `basename()` al leer, no sólo al escribir: si esa fila alguna vez queda
    // con una ruta (una edición a mano en la base, una migración), lo que sale
    // hacia el HTML no puede apuntar fuera de la carpeta de logos.
    $logo = $logo !== '' ? basename($logo) : '';

    // Un logo cuya fila quedó apuntando a un archivo que ya no está: se
    // devuelve vacío en vez de una URL rota, así el cartel sale sin logo en
    // lugar de con el ícono de imagen partida.
    if ($logo !== '' && !is_file(__DIR__ . '/uploads/logos/' . $logo)) {
        $logo = '';
    }

    return [
        'diseno' => $diseno,
        'precio_pt' => cartel_acotar((int) $valor('cartel_precio_pt'), 'precio_pt'),
        'nombre_pt' => cartel_acotar((int) $valor('cartel_nombre_pt'), 'nombre_pt'),
        'mostrar_marca' => $valor('cartel_mostrar_marca') === '1',
        'mostrar_sku' => $valor('cartel_mostrar_sku') === '1',
        'mostrar_vencimiento' => $valor('cartel_mostrar_vencimiento') === '1',
        'texto_pie' => mb_substr($filas['cartel_texto_pie'] ?? '', 0, CARTEL_PIE_MAXIMO),
        'logo' => $logo,
        'logo_url' => $logo !== '' ? 'uploads/logos/' . $logo : null,
        'logo_posicion' => $posicion,
    ];
}

/**
 * Recorta un valor a su rango.
 *
 * Al LEER se recorta en silencio (una fila fuera de rango no puede dejar la
 * pantalla en blanco), pero al ESCRIBIR `guardar_cartel_config.php` rechaza
 * con 400 en vez de recortar: recortar lo que alguien acaba de escribir lo
 * dejaría mirando un número distinto del que puso, sin ningún cartel que lo
 * explique. Es la misma asimetría que ya tiene `guardar_preferencias.php`.
 */
function cartel_acotar(int $valor, string $clave): int
{
    $limite = CARTEL_LIMITES[$clave];

    return max($limite['min'], min($limite['max'], $valor));
}
