<?php
/**
 * stockIAte - consultar_rentabilidad.php
 * ================================
 * Endpoint de solo lectura usado por el chatbot IA (herramienta
 * "consultar_rentabilidad") para responder preguntas de negocio: costo,
 * margen, ganancia, capital inmovilizado y proveedores.
 *
 * Espera un body tipo:
 * {
 *   "desde": "2026-07-01",       // opcional, default: hace 30 días
 *   "hasta": "2026-08-10",       // opcional, default: hoy
 *   "termino": "dior",           // opcional, busca en nombre/marca/sku
 *   "proveedor": "Distribuidora", // opcional, filtra por nombre de proveedor
 *   "solo_sin_costo": false,     // opcional, sólo productos sin precio_costo
 *   "orden": "margen",           // opcional: margen | ganancia | unidades | capital
 *   "limite": 300                // opcional, 1..500 (default 50)
 * }
 *
 * El `limite` existe porque este endpoint tiene dos consumidores con
 * necesidades opuestas: el chatbot quiere un top corto que entre en el
 * contexto del modelo (default 50), y la tabla de costos del panel quiere el
 * catálogo entero para poder cargarlo de una sentada. Va como entero acotado,
 * nunca interpolado como texto libre.
 *
 * SÓLO EL DUEÑO
 * -------------
 * A diferencia de los otros endpoints del chatbot, éste expone la estructura
 * de costos del negocio (cuánto se gana por producto, a quién se le compra).
 * `exigir_sesion(['dueño'])` es el control real: aunque alguien le pegue
 * directo salteándose Python, un cajero o un repositor se lleva un 403.
 * chatbot_ia.py además no le ofrece esta herramienta al modelo salvo en el
 * contexto de administración, pero eso es una optimización del prompt.
 *
 * EL COSTO SALE DEL SNAPSHOT, NO DEL PRECIO DE HOY
 * ------------------------------------------------
 * La ganancia usa `COALESCE(v.costo_unitario, p.precio_costo)`: el costo que
 * tenía el producto cuando se vendió, y sólo si esa venta es anterior a
 * `migracion_costo_ventas.sql` (o el producto no tenía costo cargado) se cae
 * al costo actual. Por eso la respuesta trae también `unidades_sin_costo` y
 * `productos_sin_costo`: para que el asistente pueda avisar cuándo el número
 * es parcial en vez de darlo como exacto.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

$desde = !empty($body['desde']) ? $body['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta = !empty($body['hasta']) ? $body['hasta'] : date('Y-m-d');
$termino = !empty($body['termino']) ? trim($body['termino']) : null;
$proveedor = !empty($body['proveedor']) ? trim($body['proveedor']) : null;
$solo_sin_costo = !empty($body['solo_sin_costo']);

// Whitelist estricta: nunca se interpola texto libre del usuario en el SQL
// (mismo patrón que $agrupaciones en consultar_ventas.php). En DESC, MySQL
// manda los NULL al final solo, así que los productos sin costo no ensucian
// el arranque del ranking.
$ordenes = [
    'margen' => 'margen_pct DESC',
    'ganancia' => 'ganancia_estimada DESC',
    'unidades' => 'unidades_vendidas DESC',
    'capital' => 'capital_inmovilizado DESC',
];
$orden = in_array($body['orden'] ?? null, array_keys($ordenes), true)
    ? $body['orden']
    : 'margen';
$columnaOrden = $ordenes[$orden];

// Entero acotado: el cast a int ya descarta cualquier cosa rara, y el clamp
// evita que alguien pida el catálogo entero de golpe.
$limite = isset($body['limite']) ? (int) $body['limite'] : 50;
$limite = max(1, min(500, $limite));

// El filtro por negocio no es opcional ni condicional: es la primera
// condición y siempre está. `activo = 1` también: esto es una herramienta de
// decisión sobre el catálogo que tenés HOY (qué reponer, dónde está la plata
// inmovilizada), y un producto archivado no admite ninguna de esas dos
// decisiones.
$condiciones = ["p.negocio_id = :negocio_id", "p.activo = 1"];
$parametros = [
    ':negocio_id' => $negocio_id,
    // Placeholders con nombre distinto por cada ocurrencia: con
    // PDO::ATTR_EMULATE_PREPARES en false (ver conexion.php), MySQL no
    // permite reusar el mismo :param varias veces en la misma query, y acá
    // `negocio_id` aparece dos veces (el ON de ventas y el WHERE).
    ':negocio_id_ventas' => $negocio_id,
    ':desde' => $desde,
    ':hasta' => $hasta,
];

if ($termino !== null) {
    $condiciones[] = "(p.nombre LIKE :termino1 OR p.marca LIKE :termino2 OR p.sku LIKE :termino3)";
    $like = "%$termino%";
    $parametros[':termino1'] = $like;
    $parametros[':termino2'] = $like;
    $parametros[':termino3'] = $like;
}

if ($proveedor !== null) {
    $condiciones[] = "pr.nombre LIKE :proveedor";
    $parametros[':proveedor'] = "%$proveedor%";
}

if ($solo_sin_costo) {
    $condiciones[] = "p.precio_costo IS NULL";
}

$where = "WHERE " . implode(" AND ", $condiciones);

try {
    // El rango de fechas va en el ON del LEFT JOIN y NO en el WHERE: si
    // estuviera en el WHERE, un producto sin ventas en el período quedaría
    // afuera del resultado -- y ésos son justamente los del capital dormido,
    // la mitad interesante de la pregunta "¿en qué me conviene invertir?".
    $sqlProductos =
        "SELECT p.id, p.sku, p.nombre, p.marca, p.categoria,
                pr.nombre AS proveedor,
                p.precio_costo, p.precio_venta, p.stock_actual,
                p.precio_venta - p.precio_costo AS margen_unitario,
                (p.precio_venta - p.precio_costo) / NULLIF(p.precio_venta, 0) * 100 AS margen_pct,
                (p.precio_venta - p.precio_costo) / NULLIF(p.precio_costo, 0) * 100 AS markup_pct,
                p.stock_actual * p.precio_costo AS capital_inmovilizado,
                COALESCE(SUM(v.cantidad), 0) AS unidades_vendidas,
                COALESCE(SUM(v.cantidad * v.precio_unitario), 0) AS ingresos,
                -- 0 = no se vendió nada en el rango. NULL = SÍ se vendió pero
                -- no hay costo con el que calcular la ganancia (lo aclara
                -- unidades_sin_costo). Sin el CASE los dos casos daban NULL y
                -- el modelo no podía distinguirlos.
                CASE WHEN COUNT(v.id) = 0 THEN 0
                     ELSE SUM(v.cantidad * (v.precio_unitario - COALESCE(v.costo_unitario, p.precio_costo)))
                END AS ganancia_estimada,
                COALESCE(SUM(CASE WHEN COALESCE(v.costo_unitario, p.precio_costo) IS NULL
                                  THEN v.cantidad ELSE 0 END), 0) AS unidades_sin_costo
           FROM productos p
           LEFT JOIN proveedores pr
                  ON pr.id = p.proveedor_id AND pr.negocio_id = p.negocio_id
           LEFT JOIN ventas v
                  ON v.producto_id = p.id
                 AND v.negocio_id = :negocio_id_ventas
                 AND v.fecha >= :desde
                 AND v.fecha < DATE_ADD(:hasta, INTERVAL 1 DAY)
           $where
          GROUP BY p.id, p.sku, p.nombre, p.marca, p.categoria, pr.nombre,
                   p.precio_costo, p.precio_venta, p.stock_actual
          ORDER BY $columnaOrden
          LIMIT $limite";

    $stmt = $pdo->prepare($sqlProductos);
    $stmt->execute($parametros);
    $filas = $stmt->fetchAll();

    // Redondeamos acá y no en SQL: lo que sale de este endpoint lo lee un
    // modelo de lenguaje, y 23.456789012 no aporta nada sobre 23.46.
    $productos = [];
    foreach ($filas as $f) {
        $productos[] = [
            "id" => (int) $f['id'],
            "sku" => $f['sku'],
            "nombre" => $f['nombre'],
            "marca" => $f['marca'],
            "categoria" => $f['categoria'],
            "proveedor" => $f['proveedor'],
            "precio_costo" => $f['precio_costo'] !== null ? round((float) $f['precio_costo'], 2) : null,
            "precio_venta" => round((float) $f['precio_venta'], 2),
            "margen_unitario" => $f['margen_unitario'] !== null ? round((float) $f['margen_unitario'], 2) : null,
            "margen_pct" => $f['margen_pct'] !== null ? round((float) $f['margen_pct'], 1) : null,
            "markup_pct" => $f['markup_pct'] !== null ? round((float) $f['markup_pct'], 1) : null,
            "stock_actual" => (int) $f['stock_actual'],
            "capital_inmovilizado" => $f['capital_inmovilizado'] !== null ? round((float) $f['capital_inmovilizado'], 2) : null,
            "unidades_vendidas" => (int) $f['unidades_vendidas'],
            "ingresos" => round((float) $f['ingresos'], 2),
            "ganancia_estimada" => $f['ganancia_estimada'] !== null ? round((float) $f['ganancia_estimada'], 2) : null,
            "unidades_sin_costo" => (int) $f['unidades_sin_costo'],
        ];
    }

    // Segunda query, por proveedor y sobre TODO el catálogo del negocio (sin
    // los filtros de arriba ni el LIMIT): si el resumen se calculara sobre los
    // productos devueltos, un "tenés $X inmovilizados" sería mentira.
    //
    // La agregación por producto va en una subconsulta: agrupar por proveedor
    // directamente contra el JOIN de ventas multiplicaría stock_actual por la
    // cantidad de renglones de venta de cada producto (fan-out).
    //
    // Sin LIMIT a propósito: una perfumería tiene decenas de proveedores, no
    // miles, y `totales` se calcula sumando estas filas -- con un LIMIT, los
    // totales quedarían cortados.
    $sqlProveedores =
        "SELECT t.proveedor,
                COUNT(*) AS productos,
                SUM(t.stock_actual) AS unidades_en_stock,
                SUM(t.capital_inmovilizado) AS capital_inmovilizado,
                SUM(t.unidades_vendidas) AS unidades_vendidas,
                SUM(t.ganancia_estimada) AS ganancia_estimada,
                AVG(t.margen_pct) AS margen_pct_promedio,
                SUM(t.sin_costo) AS productos_sin_costo
           FROM (
                SELECT p.id,
                       COALESCE(pr.nombre, 'Sin proveedor') AS proveedor,
                       p.stock_actual,
                       p.stock_actual * p.precio_costo AS capital_inmovilizado,
                       (p.precio_venta - p.precio_costo) / NULLIF(p.precio_venta, 0) * 100 AS margen_pct,
                       CASE WHEN p.precio_costo IS NULL THEN 1 ELSE 0 END AS sin_costo,
                       COALESCE(SUM(v.cantidad), 0) AS unidades_vendidas,
                       CASE WHEN COUNT(v.id) = 0 THEN 0
                            ELSE SUM(v.cantidad * (v.precio_unitario - COALESCE(v.costo_unitario, p.precio_costo)))
                       END AS ganancia_estimada
                  FROM productos p
                  LEFT JOIN proveedores pr
                         ON pr.id = p.proveedor_id AND pr.negocio_id = p.negocio_id
                  LEFT JOIN ventas v
                         ON v.producto_id = p.id
                        AND v.negocio_id = :negocio_id_ventas_prov
                        AND v.fecha >= :desde_prov
                        AND v.fecha < DATE_ADD(:hasta_prov, INTERVAL 1 DAY)
                 WHERE p.negocio_id = :negocio_id_prov AND p.activo = 1
                 GROUP BY p.id, proveedor, p.stock_actual, p.precio_costo, p.precio_venta
           ) t
          GROUP BY t.proveedor
          ORDER BY capital_inmovilizado DESC";

    $stmtProv = $pdo->prepare($sqlProveedores);
    $stmtProv->execute([
        ':negocio_id_prov' => $negocio_id,
        ':negocio_id_ventas_prov' => $negocio_id,
        ':desde_prov' => $desde,
        ':hasta_prov' => $hasta,
    ]);
    $filasProv = $stmtProv->fetchAll();

    $por_proveedor = [];
    $capital_total = 0.0;
    $ganancia_total = 0.0;
    $unidades_total = 0;
    $productos_sin_costo = 0;
    $productos_total = 0;

    foreach ($filasProv as $f) {
        $capital = (float) $f['capital_inmovilizado'];
        $ganancia = (float) $f['ganancia_estimada'];

        $por_proveedor[] = [
            "proveedor" => $f['proveedor'],
            "productos" => (int) $f['productos'],
            "unidades_en_stock" => (int) $f['unidades_en_stock'],
            "capital_inmovilizado" => round($capital, 2),
            "unidades_vendidas" => (int) $f['unidades_vendidas'],
            "ganancia_estimada" => round($ganancia, 2),
            "margen_pct_promedio" => $f['margen_pct_promedio'] !== null ? round((float) $f['margen_pct_promedio'], 1) : null,
            "productos_sin_costo" => (int) $f['productos_sin_costo'],
        ];

        $capital_total += $capital;
        $ganancia_total += $ganancia;
        $unidades_total += (int) $f['unidades_vendidas'];
        $productos_sin_costo += (int) $f['productos_sin_costo'];
        $productos_total += (int) $f['productos'];
    }

    echo json_encode([
        "ok" => true,
        "desde" => $desde,
        "hasta" => $hasta,
        "orden" => $orden,
        "productos" => $productos,
        "total_productos_devueltos" => count($productos),
        "por_proveedor" => $por_proveedor,
        "totales" => [
            "productos_en_catalogo" => $productos_total,
            "capital_inmovilizado" => round($capital_total, 2),
            "ganancia_estimada" => round($ganancia_total, 2),
            "unidades_vendidas" => $unidades_total,
            // Si esto es > 0, cualquier número de ganancia o margen es
            // parcial: hay productos sin precio_costo cargado.
            "productos_sin_costo" => $productos_sin_costo,
        ],
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar la rentabilidad",
        "error" => $e->getMessage(),
    ], 500);
}
