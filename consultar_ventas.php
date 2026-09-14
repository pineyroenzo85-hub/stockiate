<?php
/**
 * stockIAte - consultar_ventas.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_ventas") para responder preguntas sobre ventas.
 *
 * Espera un body tipo:
 * {
 *   "desde": "2026-07-01",      // opcional, default: hace 30 días
 *   "hasta": "2026-08-10",      // opcional, default: hoy
 *   "producto_id": 12,          // opcional
 *   "agrupar_por": "producto"   // opcional: producto | marca | dia (default producto)
 * }
 *
 * El orden de `resultados` depende de la agrupación: por producto o marca vienen
 * los 20 que más facturaron (de mayor a menor), y por día viene la serie
 * completa en orden cronológico -- ver la nota más abajo.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

// Sólo cajero y dueño ven ventas (coincide con ROLE_TOOLS en chatbot_ia.py,
// pero la restricción real es ésta: aunque alguien le pegue directo al
// endpoint saltándose Python, el rol se valida contra la sesión).
$negocio_id = exigir_sesion(['cajero', 'dueño'])['negocio_id'];

$body = cuerpo_json();

$desde = !empty($body['desde']) ? $body['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta = !empty($body['hasta']) ? $body['hasta'] : date('Y-m-d');
$producto_id = !empty($body['producto_id']) ? (int) $body['producto_id'] : null;

// Whitelist estricta: nunca se interpola texto libre del usuario en el SQL.
$agrupaciones = [
    'producto' => 'p.nombre',
    'marca' => 'p.marca',
    'dia' => 'DATE(v.fecha)',
];
$agrupar_por = in_array($body['agrupar_por'] ?? null, array_keys($agrupaciones), true)
    ? $body['agrupar_por']
    : 'producto';
$columnaAgrupacion = $agrupaciones[$agrupar_por];

// Agrupado por DÍA esto es una serie temporal, y una serie temporal ordenada
// por facturación no se puede graficar ni leer: hay que devolverla en orden
// cronológico. El LIMIT también cambia -- con el de 20, preguntar por el
// último mes devolvía "los 20 días que más facturaron" y se perdían días del
// medio sin que nada lo dijera. Para producto/marca sigue todo igual: los 20
// que más facturaron es exactamente lo que se quiere.
$esSerieDiaria = $agrupar_por === 'dia';
$ordenSql = $esSerieDiaria ? 'etiqueta ASC' : 'total_vendido DESC';
$limiteSql = $esSerieDiaria ? 366 : 20;

// `v.negocio_id` y no `p.negocio_id`: este mismo $where lo reusa la query de
// totales de más abajo, que NO joinea `productos`. Colgarse de la columna
// propia de `ventas` es lo que hace que un solo filtro cubra las dos queries.
//
// ESTE ES EL ÚNICO ENDPOINT QUE NO FILTRA POR `p.activo = 1`, y es a
// propósito: acá se reporta historial, no catálogo. Si filtrara, archivar un
// producto le borraría facturación a los reportes de meses ya cerrados y los
// totales dejarían de cuadrar contra la caja. Ver la nota de la columna
// `activo` en schema.sql.
$condiciones = [
    "v.negocio_id = :negocio_id",
    "v.fecha >= :desde",
    "v.fecha < DATE_ADD(:hasta, INTERVAL 1 DAY)",
];
$parametros = [':negocio_id' => $negocio_id, ':desde' => $desde, ':hasta' => $hasta];

if ($producto_id !== null) {
    $condiciones[] = "v.producto_id = :producto_id";
    $parametros[':producto_id'] = $producto_id;
}

$where = "WHERE " . implode(" AND ", $condiciones);

try {
    $stmt = $pdo->prepare(
        "SELECT $columnaAgrupacion AS etiqueta,
                SUM(v.cantidad) AS unidades_vendidas,
                SUM(v.cantidad * v.precio_unitario) AS total_vendido
         FROM ventas v
         JOIN productos p ON p.id = v.producto_id
         $where
         GROUP BY $columnaAgrupacion
         ORDER BY $ordenSql
         LIMIT $limiteSql"
    );
    $stmt->execute($parametros);
    $resultados = $stmt->fetchAll();

    $stmtTotal = $pdo->prepare(
        "SELECT SUM(v.cantidad) AS unidades_totales, SUM(v.cantidad * v.precio_unitario) AS monto_total
         FROM ventas v
         $where"
    );
    $stmtTotal->execute($parametros);
    $totales = $stmtTotal->fetch();

    echo json_encode([
        "ok" => true,
        "desde" => $desde,
        "hasta" => $hasta,
        "agrupado_por" => $agrupar_por,
        "resultados" => $resultados,
        "unidades_totales" => (int) ($totales['unidades_totales'] ?? 0),
        "monto_total" => (float) ($totales['monto_total'] ?? 0),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar las ventas",
        "error" => $e->getMessage(),
    ], 500);
}
