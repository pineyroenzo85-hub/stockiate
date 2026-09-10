<?php
/**
 * stockIAte - guardar_oferta.php
 * ================================
 * Pone o saca la oferta de un producto.
 *
 * POR QUÉ ESTÁ ACÁ Y NO EN UNA PANTALLA DE PROMOCIONES
 * ----------------------------------------------------
 * Porque un cartel de oferta que nunca puede decir "OFERTA" no sirve para
 * nada, y `carteles.html` es hoy el único lugar del sistema que las usa.
 * Antes que dejar media función construida esperando un módulo de
 * promociones que no existe, la oferta se carga desde la misma pantalla que
 * la imprime: se escribe el precio, se elige hasta cuándo, se imprime el
 * cartel y se pega en la góndola. Ese es el circuito completo.
 *
 * LO QUE ESTO **NO** HACE
 * -----------------------
 * No cambia lo que cobra la caja. `registrar_venta.php` sigue usando
 * `productos.precio_venta`. Es una limitación real y conocida —el cartel
 * puede decir un precio y la caja cobrar otro—, y engancharlas es una
 * decisión aparte: hay que resolver qué pasa con el snapshot de
 * `ventas.precio_unitario`, con los márgenes históricos y con lo que responde
 * el chatbot. Hacerlo de prepo desde acá rompería reportes que hoy cierran.
 * La pantalla lo dice en pantalla, no sólo en este comentario.
 *
 * Espera:
 * {
 *   "producto_id": 12,
 *   "precio": 3990.00,       // ausente o null => se saca la oferta
 *   "hasta": "2026-09-30"    // opcional, default: 14 días
 * }
 *
 * Sólo el rol 'dueño'. El `negocio_id` sale de la sesión, nunca del body.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];

$body = cuerpo_json();

$producto_id = isset($body['producto_id']) ? (int) $body['producto_id'] : 0;
if ($producto_id <= 0) {
    responder(['ok' => false, 'mensaje' => 'Falta el producto'], 400);
}

/** Cuánto dura una oferta si no se dice otra cosa. */
const DIAS_OFERTA_DEFAULT = 14;

try {
    // El producto tiene que ser de ESTE negocio, y ESTA validación es lo único
    // que lo garantiza: `migracion_ofertas.sql` crea la FK de producto simple
    // (`productos(id)`), no compuesta, porque la compuesta necesita un índice
    // que sólo existe si se corrió la PARTE B de migracion_multitenant.sql.
    // Ver la nota larga de esa migración. O sea: acá no hay red debajo.
    $stmt = $pdo->prepare(
        "SELECT precio_venta FROM productos
          WHERE id = :id AND negocio_id = :negocio_id AND activo = 1"
    );
    $stmt->execute([':id' => $producto_id, ':negocio_id' => $negocio_id]);
    $precio_lista = $stmt->fetchColumn();

    if ($precio_lista === false) {
        responder(['ok' => false, 'mensaje' => 'Ese producto no existe o está archivado'], 404);
    }

    // ----------------------------------------------------------
    // Sacar la oferta
    // ----------------------------------------------------------
    // Se BORRAN las vigentes en vez de acortarles la fecha: una oferta que
    // "terminó ayer" y una que nunca existió son lo mismo para el cartel, y
    // dejar filas con fechas retocadas ensucia el historial sin agregar nada.
    // Las vencidas quedan como estaban: son el registro de qué se promocionó.
    if (!isset($body['precio']) || $body['precio'] === null || $body['precio'] === '') {
        $stmt = $pdo->prepare(
            "DELETE FROM ofertas
              WHERE negocio_id = :negocio_id
                AND producto_id = :producto_id
                AND CURDATE() BETWEEN desde AND hasta"
        );
        $stmt->execute([':negocio_id' => $negocio_id, ':producto_id' => $producto_id]);

        responder([
            'ok' => true,
            'mensaje' => 'Oferta sacada',
            'oferta' => null,
        ]);
    }

    // ----------------------------------------------------------
    // Poner la oferta
    // ----------------------------------------------------------
    $precio = $body['precio'];

    if (!is_numeric($precio) || (float) $precio <= 0) {
        responder(['ok' => false, 'mensaje' => 'El precio de oferta tiene que ser un número mayor que cero'], 400);
    }

    $precio = round((float) $precio, 2);
    $precio_lista = (float) $precio_lista;

    // Un "precio de oferta" más caro que el de lista es casi siempre un
    // tipeo (un cero de más). Se rechaza en vez de imprimir un cartel que
    // anuncia un aumento como si fuera una promoción.
    if ($precio >= $precio_lista) {
        responder([
            'ok' => false,
            'mensaje' => 'El precio de oferta tiene que ser MENOR que el de lista ($'
                . number_format($precio_lista, 2, ',', '.') . ').',
        ], 400);
    }

    $hasta = isset($body['hasta']) && is_string($body['hasta']) ? trim($body['hasta']) : '';
    if ($hasta === '') {
        $hasta = date('Y-m-d', strtotime('+' . DIAS_OFERTA_DEFAULT . ' days'));
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        responder(['ok' => false, 'mensaje' => 'La fecha tiene que estar en formato AAAA-MM-DD'], 400);
    }

    if ($hasta < date('Y-m-d')) {
        responder(['ok' => false, 'mensaje' => 'La oferta no puede terminar antes de hoy'], 400);
    }

    $pdo->beginTransaction();

    // Reemplaza la vigente en vez de acumular: si no, `consultar_carteles.php`
    // tendría que desempatar entre varias y la pantalla mostraría el precio
    // viejo hasta que venciera.
    $stmt = $pdo->prepare(
        "DELETE FROM ofertas
          WHERE negocio_id = :negocio_id
            AND producto_id = :producto_id
            AND CURDATE() BETWEEN desde AND hasta"
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':producto_id' => $producto_id]);

    $stmt = $pdo->prepare(
        "INSERT INTO ofertas
            (negocio_id, producto_id, precio_oferta, precio_anterior, desde, hasta, creado_por)
         VALUES
            (:negocio_id, :producto_id, :precio, :anterior, CURDATE(), :hasta, :creado_por)"
    );
    $stmt->execute([
        ':negocio_id' => $negocio_id,
        ':producto_id' => $producto_id,
        ':precio' => $precio,
        // Snapshot del precio de lista de HOY: es lo que va tachado en el
        // cartel, y tiene que seguir siendo ese aunque mañana aumente la
        // lista. Mismo criterio que `ventas.costo_unitario`.
        ':anterior' => $precio_lista,
        ':hasta' => $hasta,
        ':creado_por' => $sesion['id'],
    ]);

    // ANTES del commit: después, `lastInsertId()` devuelve 0 (la conexión ya
    // no está en la transacción que hizo el INSERT) y el frontend se quedaba
    // con una oferta de id 0.
    $oferta_id = (int) $pdo->lastInsertId();

    $pdo->commit();

    responder([
        'ok' => true,
        'mensaje' => 'Oferta guardada',
        'oferta' => [
            'id' => $oferta_id,
            'precio' => $precio,
            'precio_anterior' => $precio_lista,
            'desde' => date('Y-m-d'),
            'hasta' => $hasta,
        ],
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $falta_tabla = str_contains($e->getMessage(), 'ofertas')
        && str_contains(strtolower($e->getMessage()), "doesn't exist");

    responder([
        'ok' => false,
        'mensaje' => $falta_tabla
            ? 'Falta la tabla de ofertas. Corré migracion_ofertas.sql sobre la base.'
            : 'Error al guardar la oferta',
        'error' => $e->getMessage(),
    ], 500);
}
