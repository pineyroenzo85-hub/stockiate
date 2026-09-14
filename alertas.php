<?php
/**
 * stockIAte - alertas.php
 * ================================
 * Las consultas que definen "acá hay un problema": stock por debajo del
 * mínimo, lotes por vencer, y el corte de ventas del día.
 *
 * POR QUÉ ESTÁ SEPARADO
 * ---------------------
 * Estas preguntas ahora tienen DOS consumidores con formas muy distintas: las
 * pantallas y el chatbot (que las piden por HTTP contra un endpoint, con
 * sesión) y `tareas_notificaciones.php` (que corre por línea de comandos, sin
 * sesión ni request, recorriendo todos los negocios).
 *
 * Sin este archivo, la alternativa era que el script CLI se copiara y pegara
 * las queries de `consultar_vencimientos.php` y `consultar_inventario.php`.
 * El umbral de stock ya está duplicado en cuatro lugares del proyecto con
 * tres criterios distintos (ver el comentario de `es_critico()`); sumar un
 * quinto, en el archivo que decide a quién se le manda un WhatsApp, era
 * garantizar que el aviso y la pantalla se contradijeran.
 *
 * Nada de acá conoce `$_SESSION`: reciben `$negocio_id` como parámetro. Quien
 * llama es el responsable de haberlo sacado de la sesión (endpoints) o de
 * estar iterando negocios a propósito (el script programado).
 */

require_once __DIR__ . '/configuracion.php'; // leer_configs()

/**
 * LA definición de stock crítico del backend: el stock llegó o bajó del
 * mínimo configurado por producto (`productos.stock_minimo`, DEFAULT 5).
 *
 * Ojo: `inventario_tabla.js` pinta los badges de la tabla con OTRO criterio
 * (`< 5` fijo para "Crítico", `<= minimo * 2` para "Stock Bajo"), así que el
 * conteo del KPI y los badges de la misma pantalla pueden no coincidir. Es
 * una diferencia preexistente; lo que importa acá es que backend y
 * notificaciones usen todos esta función y no cada uno la suya.
 */
function es_critico(int $stock_actual, int $stock_minimo): bool
{
    return $stock_actual <= $stock_minimo;
}

/**
 * Cuenta los críticos sobre filas ya traídas de la base (evita una segunda
 * query cuando el catálogo ya está en memoria).
 */
function contar_criticos(array $productos): int
{
    $criticos = 0;

    foreach ($productos as $p) {
        if (es_critico((int) $p['stock_actual'], (int) $p['stock_minimo'])) {
            $criticos++;
        }
    }

    return $criticos;
}

/**
 * Los productos activos del negocio que están en o por debajo del mínimo,
 * los más urgentes primero. Para el resumen diario y para el panel.
 */
function productos_criticos(PDO $pdo, int $negocio_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, sku, nombre, marca, stock_actual, stock_minimo
           FROM productos
          WHERE negocio_id = :negocio_id
            AND activo = 1
            AND stock_actual <= stock_minimo
          ORDER BY stock_actual ASC, nombre ASC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);

    return $stmt->fetchAll();
}

/**
 * Productos activos con stock por ENCIMA del mínimo (los que ya están en o
 * bajo el mínimo tienen su propia alerta, encolada por registrar_venta.php
 * en el momento de la venta -- ver es_critico()) cuyo ritmo de venta
 * reciente dice que igual se agotan pronto.
 *
 * dias_restantes = stock_actual / (unidades_vendidas_en_ventana / dias_ventana).
 * Sin ventas en la ventana no hay ritmo con qué calcular, así que esos
 * productos no entran -- no es que estén "seguros", es que no hay dato.
 *
 * ⚠️ Es una heurística ingenua (ritmo constante, sin estacionalidad ni
 * tendencia), no un modelo predictivo real: sirve para priorizar qué reponer
 * primero, no como pronóstico exacto. A diferencia del resto de las
 * decisiones de este archivo (umbral de stock, umbral de vencimiento), esta
 * NO tiene una medición propia del proyecto detrás -- los defaults
 * (ventana de 14 días, umbral de aviso a 7 días) son un punto de partida
 * razonable, no un número medido contra ventas reales.
 *
 * Devuelve ordenado por urgencia (menos días primero).
 */
function productos_en_riesgo_quiebre(
    PDO $pdo,
    int $negocio_id,
    int $dias_umbral = 7,
    int $dias_ventana = 14,
    ?int $limite = null
): array {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.stock_actual, p.stock_minimo,
                COALESCE(SUM(v.cantidad), 0) AS unidades_vendidas
           FROM productos p
           LEFT JOIN ventas v
                  ON v.producto_id = p.id
                 AND v.negocio_id = p.negocio_id
                 AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL :dias_ventana DAY)
          WHERE p.negocio_id = :negocio_id
            AND p.activo = 1
            AND p.stock_actual > p.stock_minimo
          GROUP BY p.id, p.sku, p.nombre, p.marca, p.stock_actual, p.stock_minimo
         HAVING unidades_vendidas > 0"
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':dias_ventana' => $dias_ventana]);

    $resultado = [];
    foreach ($stmt->fetchAll() as $f) {
        $tasaDiaria = (int) $f['unidades_vendidas'] / $dias_ventana;
        $diasRestantes = (int) $f['stock_actual'] / $tasaDiaria;

        if ($diasRestantes > $dias_umbral) {
            continue;
        }

        $resultado[] = [
            'id' => (int) $f['id'],
            'sku' => $f['sku'],
            'nombre' => $f['nombre'],
            'marca' => $f['marca'],
            'stock_actual' => (int) $f['stock_actual'],
            'stock_minimo' => (int) $f['stock_minimo'],
            'unidades_vendidas_ventana' => (int) $f['unidades_vendidas'],
            'dias_ventana' => $dias_ventana,
            'tasa_diaria' => round($tasaDiaria, 2),
            'dias_restantes' => round($diasRestantes, 1),
        ];
    }

    usort($resultado, fn($a, $b) => $a['dias_restantes'] <=> $b['dias_restantes']);

    return $limite !== null ? array_slice($resultado, 0, $limite) : $resultado;
}

/**
 * Días de anticipación con los que este negocio quiere que se le avise de un
 * vencimiento. Sale de `configuracion`; el default (y el fallback para bases
 * migradas desde antes de que la configuración fuera por negocio) vive en
 * `CONFIG_INICIAL_NEGOCIO`, no acá.
 */
function dias_vencimiento_config(PDO $pdo, int $negocio_id): int
{
    return leer_config_int($pdo, $negocio_id, 'umbral_dias_vencimiento');
}

/**
 * Lotes que vencen dentro de la ventana de días.
 *
 * No tiene piso inferior a propósito: los lotes YA vencidos también entran
 * (son los más urgentes de todos, y si desaparecieran de la lista nadie se
 * enteraría de que quedó mercadería vencida en la góndola).
 */
function lotes_por_vencer(PDO $pdo, int $negocio_id, int $dias, int $limite = 50): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id AS lote_id, l.cantidad, l.fecha_vencimiento, l.fecha_carga,
                p.id AS producto_id, p.nombre, p.marca, p.sku
           FROM lotes_stock l
           JOIN productos p ON p.id = l.producto_id
          WHERE l.negocio_id = :negocio_id
            -- Los lotes de un producto archivado no generan alerta: ya no se
            -- vende ni se repone, avisar que vence sería ruido.
            AND p.activo = 1
            AND l.fecha_vencimiento IS NOT NULL
            AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL :dias DAY)
          ORDER BY l.fecha_vencimiento ASC
          LIMIT " . (int) $limite
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':dias' => $dias]);

    return $stmt->fetchAll();
}

/**
 * Unidades y facturación del día para el negocio.
 *
 * Agrega sobre `ventas` SIN joinear `productos`, así que no hay ningún
 * `p.negocio_id` del que colgarse: es exactamente el caso por el que
 * `negocio_id` es una columna propia de `ventas` y no algo que se deriva por
 * JOIN. Antes de multi-tenant este SUM sumaba las ventas de todos los
 * comercios en el dashboard de cada uno.
 *
 * @return array{unidades: int, monto: float}
 */
function ventas_del_dia(PDO $pdo, int $negocio_id): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(cantidad), 0) AS unidades,
                COALESCE(SUM(cantidad * precio_unitario), 0) AS monto
           FROM ventas
          WHERE negocio_id = :negocio_id AND fecha >= CURDATE()"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);
    $fila = $stmt->fetch();

    return [
        'unidades' => (int) $fila['unidades'],
        'monto' => (float) $fila['monto'],
    ];
}
