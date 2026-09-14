<?php
/**
 * stockIAte - consultar_carteles.php
 * ================================
 * El catálogo con su oferta vigente, para armar los carteles de góndola
 * (`carteles.html`).
 *
 * POR QUÉ NO REUSA `consultar_inventario.php`
 * -------------------------------------------
 * Porque necesita otra cosa. El inventario trae stock, estado y alertas, que
 * acá no se imprimen; y no trae la oferta vigente, que es la mitad de un
 * cartel. Sumarle el JOIN de ofertas a un endpoint que se pide en cada carga
 * del panel sería pagarlo siempre para usarlo en una pantalla que se abre una
 * vez por semana.
 *
 * QUÉ ES UNA OFERTA "VIGENTE"
 * ---------------------------
 * `CURDATE() BETWEEN desde AND hasta`, inclusive de las dos puntas. Si hay
 * más de una para el mismo producto (se cargó una nueva sin borrar la vieja),
 * gana la más reciente por `id`: la última que alguien cargó es la que esa
 * persona quiso.
 *
 * Devuelve TAMBIÉN los productos sin oferta: la pantalla imprime carteles de
 * precio común, no sólo de promoción.
 *
 * Sólo el rol 'dueño': decide precios de cara al público.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    // La subconsulta correlacionada elige UNA oferta por producto (la de id
    // más alto entre las vigentes) antes de joinear. Un JOIN directo contra
    // `ofertas` duplicaría el producto por cada oferta vigente que tuviera, y
    // saldrían dos carteles del mismo artículo con precios distintos.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.variante, p.categoria,
                p.precio_venta, p.stock_actual,
                o.id AS oferta_id, o.precio_oferta, o.precio_anterior,
                o.desde AS oferta_desde, o.hasta AS oferta_hasta
           FROM productos p
           LEFT JOIN ofertas o
                  ON o.id = (SELECT o2.id
                               FROM ofertas o2
                              WHERE o2.producto_id = p.id
                                AND o2.negocio_id = p.negocio_id
                                AND CURDATE() BETWEEN o2.desde AND o2.hasta
                              ORDER BY o2.id DESC
                              LIMIT 1)
          WHERE p.negocio_id = :negocio_id
            AND p.activo = 1
          ORDER BY p.categoria ASC, p.marca ASC, p.nombre ASC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);

    $productos = [];
    $con_oferta = 0;

    foreach ($stmt->fetchAll() as $fila) {
        $tiene_oferta = $fila['oferta_id'] !== null;
        if ($tiene_oferta) {
            $con_oferta++;
        }

        $productos[] = [
            'id' => (int) $fila['id'],
            'sku' => $fila['sku'],
            'nombre' => $fila['nombre'],
            'marca' => $fila['marca'],
            'variante' => $fila['variante'],
            'categoria' => $fila['categoria'],
            'precio_venta' => (float) $fila['precio_venta'],
            'stock_actual' => (int) $fila['stock_actual'],
            'oferta' => $tiene_oferta ? [
                'id' => (int) $fila['oferta_id'],
                'precio' => (float) $fila['precio_oferta'],
                // El precio de lista de CUANDO se armó la oferta, no el de
                // hoy: es lo que va tachado en el cartel.
                'precio_anterior' => (float) $fila['precio_anterior'],
                'desde' => $fila['oferta_desde'],
                'hasta' => $fila['oferta_hasta'],
            ] : null,
        ];
    }

    responder([
        'ok' => true,
        'productos' => $productos,
        'total' => count($productos),
        'con_oferta' => $con_oferta,
        // Para que la pantalla pueda mostrar "vence hoy" sin depender del
        // reloj de la máquina del cliente, que puede estar corrido.
        'hoy' => date('Y-m-d'),
    ]);
} catch (PDOException $e) {
    // La tabla `ofertas` es nueva: una base que no corrió
    // migracion_ofertas.sql todavía existe y tiene que dar un mensaje que
    // diga qué hacer, no un error de SQL crudo.
    $falta_tabla = str_contains($e->getMessage(), 'ofertas')
        && str_contains(strtolower($e->getMessage()), "doesn't exist");

    responder([
        'ok' => false,
        'mensaje' => $falta_tabla
            ? 'Falta la tabla de ofertas. Corré migracion_ofertas.sql sobre la base.'
            : 'Error al consultar el catálogo para carteles',
        'error' => $e->getMessage(),
    ], 500);
}
