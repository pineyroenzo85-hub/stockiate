<?php
/**
 * stockIAte - consultar_inventario.php
 * ================================
 * Endpoint de solo lectura usado por el dashboard de Administrador y por
 * la vista de inventario de Repositor. Devuelve el catálogo completo con
 * stock real y los agregados (KPIs) que antes se simulaban en el frontend
 * con localStorage.
 *
 * Acepta un único parámetro opcional:
 * {
 *   "archivados": true   // devuelve los dados de baja en vez del catálogo
 * }
 * El filtrado por texto se sigue haciendo client-side sobre el array ya
 * traído (mismo criterio que ya usa repositor.html con CATALOGO).
 *
 * Por defecto devuelve SÓLO productos activos (`activo = 1`). La vista de
 * archivados es exclusiva del rol 'dueño': es la contraparte del botón de
 * archivar, que también es sólo suyo. Un repositor mirando el inventario no
 * tiene nada que hacer ahí.
 *
 * El alcance sale de la sesión: cada negocio ve su propio inventario y sus
 * propios KPIs. No hay ningún parámetro de entrada que permita pedir los de
 * otro.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'alertas.php'; // es_critico() / contar_criticos() / ventas_del_dia()

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion();
$negocio_id = $sesion['negocio_id'];

$body = cuerpo_json();
$archivados = !empty($body['archivados']);

if ($archivados && $sesion['rol'] !== 'dueño') {
    responder([
        "ok" => false,
        "codigo" => "ROL_INSUFICIENTE",
        "mensaje" => "Tu rol no tiene permiso para ver los productos archivados",
    ], 403);
}

try {
    // Los archivados se ordenan por fecha de baja descendente: lo último que
    // archivaste es lo que más probablemente quieras restaurar (te
    // equivocaste recién).
    $orden = $archivados ? "archivado_en DESC, nombre ASC" : "nombre ASC";
    $stmtProductos = $pdo->prepare(
        "SELECT id, sku, nombre, marca, variante, categoria, precio_venta, stock_actual,
                stock_minimo, activo, archivado_en, foto
         FROM productos
         WHERE negocio_id = :negocio_id AND activo = :activo
         ORDER BY $orden"
    );
    $stmtProductos->execute([
        ':negocio_id' => $negocio_id,
        ':activo' => $archivados ? 0 : 1,
    ]);
    $productos = $stmtProductos->fetchAll();

    // La regla vive en alertas.php: el mismo umbral que decide si se manda
    // un WhatsApp tiene que ser el que pinta este KPI.
    $criticos = contar_criticos($productos);

    $ventasHoy = ventas_del_dia($pdo, $negocio_id);

    $stmtTopMes = $pdo->prepare(
        "SELECT p.id, p.nombre, p.marca, SUM(v.cantidad) AS unidades_vendidas
         FROM ventas v
         JOIN productos p ON p.id = v.producto_id
         WHERE v.negocio_id = :negocio_id
           -- Este KPI es accionable (\"reponé esto\"), así que un producto
           -- archivado no tiene nada que hacer acá. Es lo contrario de
           -- consultar_ventas.php, que sí los incluye porque reporta
           -- historial.
           AND p.activo = 1
           AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
         GROUP BY p.id, p.nombre, p.marca
         ORDER BY unidades_vendidas DESC
         LIMIT 1"
    );
    $stmtTopMes->execute([':negocio_id' => $negocio_id]);
    $topMes = $stmtTopMes->fetch();

    echo json_encode([
        "ok" => true,
        "archivados" => $archivados,
        "productos" => $productos,
        "total_productos" => count($productos),
        "criticos" => $criticos,
        "ventas_hoy_unidades" => $ventasHoy['unidades'],
        "ventas_hoy_monto" => $ventasHoy['monto'],
        "producto_mas_vendido" => $topMes ?: null,
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar el inventario",
        "error" => $e->getMessage(),
    ], 500);
}
