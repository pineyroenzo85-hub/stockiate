<?php
/**
 * stockIAte - consultar_stock.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_stock") para responder preguntas sobre stock actual.
 *
 * Espera un body tipo:
 * {
 *   "termino": "sauvage",      // opcional, busca en nombre/marca/sku
 *   "categoria": "Perfume Hombre", // opcional
 *   "solo_bajo": true          // opcional, filtra stock_actual <= stock_minimo
 * }
 *
 * Aunque a este endpoint le pega el servicio Python (no el navegador), la
 * sesión igual se exige: chatbot_ia.py reenvía la cookie del usuario, así
 * que el negocio se resuelve acá y no hay que confiar en nada que mande el
 * cliente. Ver la sección "Chatbot" en CLAUDE.md.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

// Los tres roles pueden consultar stock (coincide con ROLE_TOOLS en
// chatbot_ia.py), pero la restricción real vive acá, no en Python.
$negocio_id = exigir_sesion()['negocio_id'];

$body = cuerpo_json();

$termino = !empty($body['termino']) ? trim($body['termino']) : null;
$categoria = !empty($body['categoria']) ? trim($body['categoria']) : null;
$solo_bajo = !empty($body['solo_bajo']);

// El filtro por negocio no es opcional ni condicional: es la primera
// condición y siempre está. `activo = 1` también: el stock de un producto
// archivado no se repone ni se reporta.
$condiciones = ["negocio_id = :negocio_id", "activo = 1"];
$parametros = [':negocio_id' => $negocio_id];

if ($termino !== null) {
    // Placeholders con nombre distinto por cada ocurrencia: con
    // PDO::ATTR_EMULATE_PREPARES en false (ver conexion.php), MySQL no
    // permite reusar el mismo :param varias veces en la misma query.
    $condiciones[] = "(nombre LIKE :termino1 OR marca LIKE :termino2 OR sku LIKE :termino3)";
    $like = "%$termino%";
    $parametros[':termino1'] = $like;
    $parametros[':termino2'] = $like;
    $parametros[':termino3'] = $like;
}

if ($categoria !== null) {
    $condiciones[] = "categoria = :categoria";
    $parametros[':categoria'] = $categoria;
}

if ($solo_bajo) {
    $condiciones[] = "stock_actual <= stock_minimo";
}

$where = "WHERE " . implode(" AND ", $condiciones);

try {
    $stmt = $pdo->prepare(
        "SELECT id, sku, nombre, marca, variante, categoria, precio_venta, stock_actual, stock_minimo
         FROM productos
         $where
         ORDER BY stock_actual ASC
         LIMIT 50"
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
        "mensaje" => "Error al consultar el stock",
        "error" => $e->getMessage(),
    ], 500);
}
