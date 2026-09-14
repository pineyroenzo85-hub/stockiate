<?php
/**
 * stockIAte - consultar_reposicion.php
 * ================================
 * Qué hay que pedirle al proveedor, y cuánto.
 *
 * Es el espejo del riesgo de quiebre (`consultar_prediccion_quiebre.php`):
 * aquél avisa que un producto se está por acabar, éste dice cuántas unidades
 * pedir y cuánto va a costar el pedido.
 *
 * LA CUENTA
 * ---------
 *   venta_diaria   = unidades vendidas en los últimos 90 días / 90
 *   dias_cobertura = stock_actual / venta_diaria
 *   punto_pedido   = venta_diaria * dias_entrega * (1 + factor_seguridad)
 *   sugerido       = venta_diaria * dias_objetivo - stock_actual
 *
 * `dias_entrega` (7), `dias_objetivo` (30) y `factor_seguridad` (1.5) salen de
 * la tabla `configuracion` y se editan desde el panel, igual que
 * `umbral_dias_vencimiento`. No son constantes universales: un proveedor que
 * entrega al otro día y uno que tarda tres semanas dan listas completamente
 * distintas para el mismo inventario, y quien sabe cuál es cuál es el dueño,
 * no el código.
 *
 * DOS REGLAS DE HONESTIDAD, QUE SON LA MITAD DEL VALOR DE ESTA PANTALLA
 * ---------------------------------------------------------------------
 * 1. **Un producto sin ventas en 90 días NO va en la lista.** No se repone lo
 *    que no se vende: eso es exactamente lo que hay que liquidar, no
 *    recomprar. Sin esta regla, la lista de reposición y una eventual lista de
 *    ofertas se contradirían sobre el mismo producto.
 * 2. **Un producto con menos de `ANTIGUEDAD_MINIMA_DIAS` desde su primera
 *    carga queda afuera.** Con dos semanas de historia, "vendió 3 en 4 días"
 *    se proyecta a 67 unidades por mes y la sugerencia es un disparate. No hay
 *    forma de calcular una venta diaria confiable sin historia, y una
 *    sugerencia inventada es peor que ninguna: alguien la va a pedir.
 *
 * Los excluidos se cuentan y se informan en `diagnostico`, para poder
 * contestar "¿por qué no aparece tal producto?" sin entrar a la base.
 *
 * Sólo el rol 'dueño': la lista trae el costo estimado del pedido, o sea la
 * estructura de costos del negocio. Mismo criterio que
 * `consultar_rentabilidad.php`.
 *
 * Espera un body vacío o con overrides puntuales (para simular):
 * {
 *   "dias_entrega": 14,      // opcional, pisa la config sólo para esta consulta
 *   "dias_objetivo": 45,     // opcional
 *   "factor_seguridad": 1.2  // opcional
 * }
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

/** Ventana de historia sobre la que se calcula el ritmo de venta. */
const DIAS_HISTORIA_VENTA = 90;

/**
 * Historia mínima para que un producto entre en la lista.
 *
 * 21 días son tres semanas completas: alcanza para que el producto haya
 * pasado por tres fines de semana, que es donde este rubro concentra la
 * venta. Con menos, el promedio diario depende de si la semana que se midió
 * tenía un sábado bueno.
 */
const ANTIGUEDAD_MINIMA_DIAS = 21;

// Los overrides del body sirven para simular ("¿y si el proveedor tardara dos
// semanas?") sin guardar nada. Si no vienen, mandan los de la config.
$dias_entrega = isset($body['dias_entrega']) && is_numeric($body['dias_entrega'])
    ? max(1, min(120, (int) $body['dias_entrega']))
    : leer_config_int($pdo, $negocio_id, 'reposicion_dias_entrega');

$dias_objetivo = isset($body['dias_objetivo']) && is_numeric($body['dias_objetivo'])
    ? max(1, min(365, (int) $body['dias_objetivo']))
    : leer_config_int($pdo, $negocio_id, 'reposicion_dias_objetivo');

$factor_seguridad = isset($body['factor_seguridad']) && is_numeric($body['factor_seguridad'])
    ? max(0, min(5, (float) $body['factor_seguridad']))
    : leer_config_float($pdo, $negocio_id, 'reposicion_factor_seguridad');

$desde = date('Y-m-d H:i:s', strtotime('-' . DIAS_HISTORIA_VENTA . ' days'));

try {
    // Las dos agregaciones van como subconsultas y no como JOIN directo: un
    // producto tiene muchas ventas y muchos lotes, y joinear las dos tablas a
    // la vez multiplicaría las filas entre sí (el clásico fan-out, que acá
    // inflaría las unidades vendidas por la cantidad de lotes).
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.variante,
                p.stock_actual, p.stock_minimo, p.precio_costo, p.precio_venta,
                pr.nombre AS proveedor,
                COALESCE(v.unidades, 0) AS unidades_periodo,
                COALESCE(l.primera_carga, p.creado_en) AS primera_carga
           FROM productos p
           LEFT JOIN proveedores pr
                  ON pr.id = p.proveedor_id AND pr.negocio_id = p.negocio_id
           LEFT JOIN (SELECT producto_id, SUM(cantidad) AS unidades
                        FROM ventas
                       WHERE negocio_id = :negocio_id_v
                         AND fecha >= :desde
                       GROUP BY producto_id) v ON v.producto_id = p.id
           LEFT JOIN (SELECT producto_id, MIN(fecha_carga) AS primera_carga
                        FROM lotes_stock
                       WHERE negocio_id = :negocio_id_l
                       GROUP BY producto_id) l ON l.producto_id = p.id
          WHERE p.negocio_id = :negocio_id
            AND p.activo = 1"
    );
    $stmt->execute([
        ':negocio_id' => $negocio_id,
        ':negocio_id_v' => $negocio_id,
        ':negocio_id_l' => $negocio_id,
        ':desde' => $desde,
    ]);

    $hoy = new DateTimeImmutable('today');

    $lista = [];
    $excluidos = ['sin_ventas' => 0, 'sin_historia' => 0, 'con_stock_suficiente' => 0];
    $costo_total = 0.0;
    $sin_costo = 0;
    $parametros_contradictorios = false;

    foreach ($stmt->fetchAll() as $fila) {
        $unidades = (int) $fila['unidades_periodo'];

        // Regla 1: no se repone lo que no se vende.
        if ($unidades <= 0) {
            $excluidos['sin_ventas']++;
            continue;
        }

        // Regla 2: sin historia no hay ritmo de venta que valga.
        try {
            $primera = new DateTimeImmutable($fila['primera_carga']);
        } catch (Exception $e) {
            $primera = $hoy;
        }
        $antiguedad = (int) $primera->diff($hoy)->days;

        if ($antiguedad < ANTIGUEDAD_MINIMA_DIAS) {
            $excluidos['sin_historia']++;
            continue;
        }

        $venta_diaria = $unidades / DIAS_HISTORIA_VENTA;
        $stock = (int) $fila['stock_actual'];

        $punto_pedido = $venta_diaria * $dias_entrega * (1 + $factor_seguridad);

        if ($stock > $punto_pedido) {
            $excluidos['con_stock_suficiente']++;
            continue;
        }

        // Cuánto pedir para llegar al objetivo. `ceil` porque no se compran
        // fracciones de envase, y el redondeo va para arriba: quedarse corto
        // en la reposición es el error que esta pantalla vino a evitar.
        $sugerido = (int) ceil($venta_diaria * $dias_objetivo - $stock);

        if ($sugerido <= 0) {
            // Pasa sólo con parámetros contradictorios: un objetivo más corto
            // que el propio punto de pedido (por ejemplo, pedir para 10 días
            // cuando el proveedor tarda 7 y el colchón es 1.5). Se informa en
            // vez de esconder el producto, porque el problema es la config.
            $sugerido = 0;
            $parametros_contradictorios = true;
        }

        $precio_costo = $fila['precio_costo'] !== null ? (float) $fila['precio_costo'] : null;
        $costo_estimado = $precio_costo !== null ? round($sugerido * $precio_costo, 2) : null;

        if ($costo_estimado !== null) {
            $costo_total += $costo_estimado;
        } else {
            $sin_costo++;
        }

        $lista[] = [
            'id' => (int) $fila['id'],
            'sku' => $fila['sku'],
            'nombre' => $fila['nombre'],
            'marca' => $fila['marca'],
            'variante' => $fila['variante'],
            'proveedor' => $fila['proveedor'],
            'stock_actual' => $stock,
            'stock_minimo' => (int) $fila['stock_minimo'],
            'unidades_periodo' => $unidades,
            'venta_diaria' => round($venta_diaria, 2),
            'dias_cobertura' => round($stock / $venta_diaria, 1),
            'punto_pedido' => (int) ceil($punto_pedido),
            'sugerido' => $sugerido,
            'precio_costo' => $precio_costo,
            'costo_estimado' => $costo_estimado,
            // Cuántos días de historia tiene: un producto de 25 días pasa el
            // filtro pero su proyección es bastante más floja que la de uno
            // con 200, y la pantalla lo marca.
            'dias_historia' => $antiguedad,
        ];
    }

    // Por urgencia: primero el que menos días de venta tiene encima.
    usort($lista, function ($a, $b) {
        return $a['dias_cobertura'] <=> $b['dias_cobertura'];
    });

    responder([
        'ok' => true,
        'parametros' => [
            'dias_entrega' => $dias_entrega,
            'dias_objetivo' => $dias_objetivo,
            'factor_seguridad' => $factor_seguridad,
            'dias_historia_venta' => DIAS_HISTORIA_VENTA,
            'antiguedad_minima_dias' => ANTIGUEDAD_MINIMA_DIAS,
        ],
        'resultados' => $lista,
        'total_productos' => count($lista),
        'costo_estimado_total' => round($costo_total, 2),
        // El total de arriba está INCOMPLETO si esto es mayor que cero, y la
        // pantalla lo dice. Un total de pedido que parece completo pero se
        // saltea productos sin costo cargado es un número que engaña.
        'productos_sin_costo' => $sin_costo,
        'diagnostico' => $excluidos,
        'parametros_contradictorios' => $parametros_contradictorios,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al calcular la lista de reposición',
        'error' => $e->getMessage(),
    ], 500);
}
