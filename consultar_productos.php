<?php
/**
 * stockIAte - consultar_productos.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_productos") para buscar en el catálogo.
 *
 * Espera un body tipo:
 * {
 *   "termino": "dior"   // opcional, busca en nombre/marca/sku/categoria
 * }
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion()['negocio_id'];

$body = cuerpo_json();

$termino = !empty($body['termino']) ? trim($body['termino']) : null;

// Primera condición y siempre presente. `activo = 1` la acompaña: un
// producto archivado no tiene que aparecer en los <select> de la Red de
// Seguridad ni cuando el chatbot busca en el catálogo.
$condiciones = ["p.negocio_id = :negocio_id", "p.activo = 1"];
$parametros = [':negocio_id' => $negocio_id];

if ($termino !== null) {
    // Placeholders con nombre distinto por cada ocurrencia: con
    // PDO::ATTR_EMULATE_PREPARES en false (ver conexion.php), MySQL no
    // permite reusar el mismo :param varias veces en la misma query.
    $condiciones[] = "(p.nombre LIKE :termino1 OR p.marca LIKE :termino2 OR p.sku LIKE :termino3 OR p.categoria LIKE :termino4)";
    $like = "%$termino%";
    $parametros[':termino1'] = $like;
    $parametros[':termino2'] = $like;
    $parametros[':termino3'] = $like;
    $parametros[':termino4'] = $like;
}

$where = "WHERE " . implode(" AND ", $condiciones);

try {
    // El LEFT JOIN también filtra por negocio: si un producto quedara
    // apuntando a un proveedor ajeno (imposible con las FK compuestas, pero
    // el filtro es gratis), el nombre del proveedor de otro comercio no se
    // filtraría en la respuesta -- aparecería como NULL.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.variante, p.categoria,
                p.precio_venta, p.precio_costo, p.stock_actual,
                p.proveedor_id, pr.nombre AS proveedor
         FROM productos p
         LEFT JOIN proveedores pr
                ON pr.id = p.proveedor_id AND pr.negocio_id = p.negocio_id
         $where
         ORDER BY p.nombre ASC
         LIMIT 30"
    );
    $stmt->execute($parametros);
    $productos = $stmt->fetchAll();

    echo json_encode([
        "ok" => true,
        "productos" => $productos,
        "total" => count($productos),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar productos",
        "error" => $e->getMessage(),
    ], 500);
}
