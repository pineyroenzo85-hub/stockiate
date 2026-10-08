<?php
/**
 * stockIAte - consultar_precios.php
 * ================================
 * Endpoint de solo lectura del módulo "Precios e inflación"
 * (precios_inflacion.html), sólo rol 'dueño'.
 *
 * Devuelve el catálogo activo con, por producto, la fecha de su último
 * aumento (MAX(fecha) de historial_precios), más el bloque de configuración
 * fiscal/inflación del negocio. Las proyecciones a futuro, el % de atraso y
 * el redondeo a $10 son puramente aritméticos sobre estos números, así que
 * se calculan en el frontend (precios_inflacion.js) igual que costos_tabla.js
 * recalcula el margen en vivo: no hace falta ida y vuelta al servidor por
 * cada cambio de la inflación mensual o del horizonte elegido.
 *
 * No espera body. El alcance sale de la sesión, como el resto del proyecto.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php'; // leer_config_float()

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    // Subconsulta correlacionada por (producto_id, negocio_id), mismo patrón
    // que "ultima_carga" en consultar_inventario.php: un producto son a lo
    // sumo un puñado de cambios de precio, no miles, así que no hace falta
    // un JOIN + GROUP BY que complique el resto del SELECT.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.categoria, p.stock_actual, p.precio_venta,
                (SELECT MAX(h.fecha)
                   FROM historial_precios h
                  WHERE h.producto_id = p.id AND h.negocio_id = p.negocio_id) AS ultimo_aumento
           FROM productos p
          WHERE p.negocio_id = :negocio_id AND p.activo = 1
          ORDER BY p.nombre ASC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);
    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['stock_actual'] = (int) $p['stock_actual'];
        $p['precio_venta'] = (float) $p['precio_venta'];
    }
    unset($p);

    responder([
        'ok' => true,
        'productos' => $productos,
        'total_productos' => count($productos),
        'config' => [
            'inflacion_mensual' => leer_config_float($pdo, $negocio_id, 'inflacion_mensual'),
            'alicuota_iva' => leer_config_float($pdo, $negocio_id, 'alicuota_iva'),
            'alicuota_iibb' => leer_config_float($pdo, $negocio_id, 'alicuota_iibb'),
            'umbral_atraso_precio' => leer_config_float($pdo, $negocio_id, 'umbral_atraso_precio'),
        ],
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al consultar los precios',
        'error' => $e->getMessage(),
    ], 500);
}
