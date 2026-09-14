<?php
/**
 * stockIAte - configuracion.php
 * ================================
 * Dueño de la tabla `configuracion` (PK compuesta `(negocio_id, clave)`): los
 * ajustes que cada negocio puede cambiar desde el panel sin que nadie toque
 * código ni base.
 *
 * Existe porque la configuración inicial se sembraba EN DOS LUGARES con el
 * texto copiado (`crear_negocio.php` y `sesion_demo.php`). Mientras fue una
 * sola fila (`umbral_dias_vencimiento`) se podía convivir; al sumar las tres
 * de WhatsApp, la copia iba a divergir sola y el síntoma habría sido "en el
 * negocio demo no anda" sin ninguna pista de por qué.
 *
 * Acá NO se decide quién puede escribir: eso lo hace cada endpoint con
 * `exigir_sesion()`, como siempre.
 */

/**
 * Con qué configuración arranca un negocio nuevo.
 *
 * Es además LA tabla de defaults del proyecto: `leer_config_int()` cae acá
 * cuando el negocio no tiene la fila (bases migradas desde antes de que
 * existiera la clave). Por eso los valores repiten a propósito lo que ya era
 * el comportamiento hardcodeado -- 30 días de vencimiento, ventana de 24 h,
 * stock mínimo 5 (el DEFAULT de la columna `productos.stock_minimo`): sembrar
 * la config no cambia cómo se comporta un negocio que ya venía andando.
 *
 * WhatsApp arranca desactivado y sin teléfono a propósito: dar de alta un
 * negocio no puede tener como efecto que le empiecen a llegar mensajes a
 * alguien. Se activa a mano desde el panel, y ahí el dueño carga su número.
 */
const CONFIG_INICIAL_NEGOCIO = [
    'umbral_dias_vencimiento' => '30',
    // Cada cuántas horas se puede repetir el aviso de un MISMO producto en
    // stock crítico. Ver la nota de `ventana_notificaciones_config()` en
    // notificaciones.php: no toca los avisos diarios.
    'ventana_notificaciones_horas' => '24',
    // Con cuánto stock nace un producto nuevo considerado "crítico".
    'stock_minimo_default' => '5',
    // --- Avisos por mail (segundo canal, al lado de WhatsApp) ---
    // Arrancan apagados y sin casilla, igual que arrancó WhatsApp: un negocio
    // recién creado no le manda un mail a nadie hasta que su dueño lo pida.
    'email_activo' => '0',
    'email_destino' => '',
    // --- Reposición al proveedor (consultar_reposicion.php) ---
    // Cuántos días tarda el proveedor en entregar. Es el corazón del punto de
    // pedido: si tarda una semana, hay que pedir cuando todavía queda una
    // semana de venta.
    'reposicion_dias_entrega' => '7',
    // Para cuántos días de venta se pide. 30 = un mes de mercadería.
    'reposicion_dias_objetivo' => '30',
    // Colchón sobre el consumo del plazo de entrega, como múltiplo de ese
    // consumo. 1.5 = "pedí cuando te quede el tiempo de entrega más un 50%".
    // Es el único valor decimal de la tabla: se lee con leer_config_float().
    'reposicion_factor_seguridad' => '1.5',
    // --- Diseño del cartel de góndola (carteles.html) ---
    // Los tamaños van en PUNTOS, no en píxeles: la unidad de destino es una
    // hoja A4. Los rangos los valida `guardar_cartel_config.php`, que es quien
    // garantiza que el precio nunca baje de 40pt ni el resto de 12pt --
    // debajo de eso el cartel deja de leerse desde la góndola, que es lo único
    // que tiene que hacer.
    'cartel_diseno' => 'clasico',
    'cartel_precio_pt' => '56',
    'cartel_nombre_pt' => '15',
    'cartel_mostrar_marca' => '1',
    'cartel_mostrar_sku' => '1',
    'cartel_mostrar_vencimiento' => '1',
    // Texto libre corto al pie de cada cartel: el nombre del local, una
    // dirección, "precios con IVA".
    'cartel_texto_pie' => '',
    // Nombre del archivo dentro de uploads/logos/, no una ruta: la carpeta la
    // decide el servidor. Ver `subir_logo.php`.
    'cartel_logo' => '',
    'cartel_logo_posicion' => 'arriba',
    'whatsapp_telefono' => '',
    'whatsapp_activo' => '0',
    'whatsapp_hora_resumen' => '20:00',
];

/**
 * Lee varias claves de un negocio de una sola vez.
 *
 * @param string[] $claves
 * @return array<string, string> sólo las claves que existan en la base
 */
function leer_configs(PDO $pdo, int $negocio_id, array $claves): array
{
    if (count($claves) === 0) {
        return [];
    }

    // Un placeholder por clave: con EMULATE_PREPARES en false no se puede
    // interpolar una lista en un IN (...), hay que armarla. Mismo motivo por
    // el que consultar_stock.php usa :termino1/:termino2/:termino3.
    $placeholders = [];
    $parametros = [':negocio_id' => $negocio_id];

    foreach (array_values($claves) as $i => $clave) {
        $placeholders[] = ":clave$i";
        $parametros[":clave$i"] = $clave;
    }

    $stmt = $pdo->prepare(
        "SELECT clave, valor FROM configuracion
          WHERE negocio_id = :negocio_id
            AND clave IN (" . implode(', ', $placeholders) . ")"
    );
    $stmt->execute($parametros);

    $valores = [];
    foreach ($stmt->fetchAll() as $fila) {
        $valores[$fila['clave']] = (string) $fila['valor'];
    }

    return $valores;
}

/**
 * Lee UNA clave numérica, cayendo al default de `CONFIG_INICIAL_NEGOCIO` si el
 * negocio no la tiene.
 *
 * El fallback sale de esa constante y no de un número escrito en cada llamada
 * a propósito: los negocios creados antes de que la clave existiera no tienen
 * la fila, y con el default repetido en cada consumidor bastaba con que uno
 * quedara desactualizado para que el panel mostrara un valor y el backend
 * usara otro -- exactamente el problema que este archivo vino a resolver con
 * la semilla de configuración.
 *
 * Un valor vacío o no numérico también cae al default, porque `(int) ''` es 0
 * y eso convertiría una fila corrupta en "cero días de anticipación" sin que
 * nadie se entere. Un 0 explícito y bien guardado sí pasa: para
 * `stock_minimo_default` es una elección válida. Los rangos se validan al
 * escribir (`guardar_preferencias.php`), no acá.
 */
function leer_config_int(PDO $pdo, int $negocio_id, string $clave): int
{
    $filas = leer_configs($pdo, $negocio_id, [$clave]);
    $valor = $filas[$clave] ?? '';

    if ($valor === '' || !is_numeric($valor)) {
        $valor = CONFIG_INICIAL_NEGOCIO[$clave] ?? '0';
    }

    return (int) $valor;
}

/**
 * Igual que `leer_config_int()` pero para las claves decimales.
 *
 * Hoy la única es `reposicion_factor_seguridad`. Existe porque un colchón de
 * seguridad de "1 o 2" es demasiado grueso: la diferencia entre 1.2 y 1.5
 * sobre un producto que vende 4 por día son 8 unidades de stock parado.
 *
 * `(float)` y no `(int)`: el resto del contrato es idéntico al de la versión
 * entera, incluido caer al default de `CONFIG_INICIAL_NEGOCIO` cuando el
 * valor está vacío o no es numérico.
 */
function leer_config_float(PDO $pdo, int $negocio_id, string $clave): float
{
    $filas = leer_configs($pdo, $negocio_id, [$clave]);
    $valor = $filas[$clave] ?? '';

    if ($valor === '' || !is_numeric($valor)) {
        $valor = CONFIG_INICIAL_NEGOCIO[$clave] ?? '0';
    }

    return (float) $valor;
}

/**
 * Guarda una clave. Sirve tanto para el alta como para la edición gracias al
 * `ON DUPLICATE KEY UPDATE`: los negocios migrados desde una versión anterior
 * no tienen las filas nuevas, y sin esto habría que averiguar primero si
 * existe para decidir entre INSERT y UPDATE.
 */
function guardar_config(PDO $pdo, int $negocio_id, string $clave, string $valor): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO configuracion (negocio_id, clave, valor)
         VALUES (:negocio_id, :clave, :valor)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    $stmt->execute([
        ':negocio_id' => $negocio_id,
        ':clave' => $clave,
        ':valor' => $valor,
    ]);
}

/**
 * Siembra la configuración inicial de un negocio recién creado.
 */
function sembrar_config_inicial(PDO $pdo, int $negocio_id): void
{
    foreach (CONFIG_INICIAL_NEGOCIO as $clave => $valor) {
        guardar_config($pdo, $negocio_id, $clave, $valor);
    }
}
