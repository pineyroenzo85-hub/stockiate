<?php
/**
 * stockIAte - consultar_metricas_ia.php
 * ================================
 * Endpoint de solo lectura que mide qué tan bien anda el modelo de IA, leyendo
 * la tabla `correcciones_ia`.
 *
 * POR QUÉ EXISTE
 * --------------
 * `correcciones_ia` viene guardando, desde que arrancó el piloto, qué detectó
 * el modelo y qué corrigió la persona en la Red de Seguridad. Es el respaldo
 * numérico del argumento central del proyecto -- que la validación humana es
 * lo que hace usable un modelo imperfecto -- y hasta ahora no había ninguna
 * pantalla que la leyera: los datos estaban ahí sin que nadie los mirara.
 *
 * LAS CUATRO CATEGORÍAS, Y POR QUÉ SON CUATRO Y NO DOS
 * ----------------------------------------------------
 *   acierto        detectó el producto correcto Y contó bien
 *   error_producto identificó mal QUÉ era
 *   error_cantidad identificó bien pero contó mal
 *   no_reconocido  `producto_detectado_id` NULL: el modelo vio un envase pero
 *                  el OCR no leyó la etiqueta, así que no matcheó con el
 *                  catálogo y la persona lo eligió a mano
 *
 * Los dos errores del medio se separan porque son problemas distintos y se
 * arreglan distinto: uno es el OCR y el catálogo, el otro es el detector
 * contando cajas. Y `no_reconocido` es una categoría aparte, no un error:
 * el modelo no se equivocó de producto, no propuso ninguno. Meterlo en la
 * bolsa de los errores hunde la métrica de acierto por algo que el sistema
 * maneja bien (la tarjeta sale en rojo, sin prellenar nada, y el botón
 * Confirmar la rechaza hasta que una persona elija -- ver CLAUDE.md).
 *
 * Un mismo registro puede ser error de producto Y de cantidad, así que los
 * tres conteos de error NO suman el total: `acierto + error_alguno +
 * no_reconocido` sí.
 *
 * HONESTIDAD ESTADÍSTICA
 * ----------------------
 * Todo porcentaje viaja con su `n`. Un 100% sobre 3 casos no es un 100%, y la
 * única forma de que la pantalla no pueda mentir es que el numerito de
 * muestra viaje pegado al porcentaje en la misma respuesta. Si el período
 * tiene menos de MUESTRA_MINIMA registros, la respuesta trae
 * `muestra_insuficiente: true` y la pantalla muestra los conteos crudos en
 * vez de porcentajes grandes.
 *
 * Espera un body tipo:
 * {
 *   "desde": "2026-06-11",   // opcional, default: hace 90 días
 *   "hasta": "2026-09-09"    // opcional, default: hoy
 * }
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

// Sólo dueño: es una métrica de cómo anda el sistema, no una herramienta de
// trabajo del repositor ni del cajero. Mismo criterio que
// consultar_rentabilidad.php.
$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

$desde = !empty($body['desde']) ? $body['desde'] : date('Y-m-d', strtotime('-90 days'));
$hasta = !empty($body['hasta']) ? $body['hasta'] : date('Y-m-d');

/**
 * Debajo de esto no se muestran porcentajes. 30 no es un número mágico ni un
 * umbral estadístico formal: es el mínimo por debajo del cual un porcentaje
 * se mueve tanto por un caso que informa peor que el conteo crudo.
 */
const MUESTRA_MINIMA = 30;

// Las cuatro categorías, en SQL y en un solo lugar. `<=>` es la igualdad
// null-safe de MySQL: `NOT (a <=> b)` es verdadero cuando difieren, incluso
// si uno de los dos es NULL (una corrección donde la persona no eligió
// producto cuenta como error de producto, no se cae del conteo).
const SQL_NO_RECONOCIDO  = "producto_detectado_id IS NULL";
const SQL_RECONOCIDO     = "producto_detectado_id IS NOT NULL";
const SQL_ERR_PRODUCTO   = "producto_detectado_id IS NOT NULL AND NOT (producto_detectado_id <=> producto_corregido_id)";
const SQL_ERR_CANTIDAD   = "producto_detectado_id IS NOT NULL AND cantidad_detectada <> cantidad_corregida";
const SQL_ACIERTO        = "producto_detectado_id IS NOT NULL
                            AND producto_detectado_id <=> producto_corregido_id
                            AND cantidad_detectada = cantidad_corregida";

// Rango cerrado por la izquierda y abierto por la derecha del día siguiente:
// `fecha` es DATETIME, y un `<= :hasta` se comería todo el último día.
$where = "WHERE negocio_id = :negocio_id
            AND fecha >= :desde
            AND fecha < DATE_ADD(:hasta, INTERVAL 1 DAY)";
$parametros = [':negocio_id' => $negocio_id, ':desde' => $desde, ':hasta' => $hasta];

/** Porcentaje con un decimal, o null si no hay sobre qué calcularlo. */
function porcentaje(int $parte, int $total): ?float
{
    return $total > 0 ? round($parte * 100 / $total, 1) : null;
}

try {
    // ----------------------------------------------------------------
    // (a) (b) (c) (d) — conteos por categoría, en una sola pasada
    // ----------------------------------------------------------------
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(" . SQL_ACIERTO . ")       AS aciertos,
                SUM(" . SQL_ERR_PRODUCTO . ")  AS err_producto,
                SUM(" . SQL_ERR_CANTIDAD . ")  AS err_cantidad,
                SUM(" . SQL_NO_RECONOCIDO . ") AS no_reconocido,
                SUM(" . SQL_RECONOCIDO . ")    AS reconocidas
           FROM correcciones_ia
         $where"
    );
    $stmt->execute($parametros);
    $c = $stmt->fetch();

    $total         = (int) $c['total'];
    $aciertos      = (int) $c['aciertos'];
    $err_producto  = (int) $c['err_producto'];
    $err_cantidad  = (int) $c['err_cantidad'];
    $no_reconocido = (int) $c['no_reconocido'];
    $reconocidas   = (int) $c['reconocidas'];

    $resumen = [
        'total' => $total,
        'aciertos' => $aciertos,
        'error_producto' => $err_producto,
        'error_cantidad' => $err_cantidad,
        'no_reconocido' => $no_reconocido,
        'reconocidas' => $reconocidas,
        // Dos denominadores, los dos con su n, porque contestan preguntas
        // distintas y elegir uno solo sería elegir qué número queda mejor:
        //   - sobre el total: de cada 100 fotos, cuántas salieron perfectas
        //   - sobre las reconocidas: cuando el modelo SÍ propuso un producto,
        //     cuántas veces acertó
        'acierto_pct_total' => porcentaje($aciertos, $total),
        'acierto_pct_reconocidas' => porcentaje($aciertos, $reconocidas),
        'error_producto_pct' => porcentaje($err_producto, $reconocidas),
        'error_cantidad_pct' => porcentaje($err_cantidad, $reconocidas),
        'no_reconocido_pct' => porcentaje($no_reconocido, $total),
    ];

    // ----------------------------------------------------------------
    // (e) — ¿la confianza baja predice el error?
    // ----------------------------------------------------------------
    // Es la pregunta más interesante del trabajo: si la confianza separa
    // aciertos de errores, el umbral se puede justificar con datos propios en
    // lugar de un número elegido a ojo.
    //
    // Sólo entran las filas que tienen confianza guardada, y las no
    // reconocidas quedan afuera del promedio de "error": no son un error del
    // clasificador, y su confianza mide otra cosa (el detector vio algo, el
    // OCR no leyó).
    $stmt = $pdo->prepare(
        "SELECT SUM(" . SQL_ACIERTO . ") AS n_acierto,
                AVG(CASE WHEN " . SQL_ACIERTO . " THEN confianza_ia END) AS conf_acierto,
                SUM(" . SQL_RECONOCIDO . " AND NOT (" . SQL_ACIERTO . ")) AS n_error,
                AVG(CASE WHEN " . SQL_RECONOCIDO . " AND NOT (" . SQL_ACIERTO . ") THEN confianza_ia END) AS conf_error,
                SUM(" . SQL_NO_RECONOCIDO . ") AS n_no_reconocido,
                AVG(CASE WHEN " . SQL_NO_RECONOCIDO . " THEN confianza_ia END) AS conf_no_reconocido
           FROM correcciones_ia
         $where
           AND confianza_ia IS NOT NULL"
    );
    $stmt->execute($parametros);
    $f = $stmt->fetch();

    $confianza = [
        'acierto' => [
            'n' => (int) $f['n_acierto'],
            'promedio' => $f['conf_acierto'] !== null ? round((float) $f['conf_acierto'], 3) : null,
        ],
        'error' => [
            'n' => (int) $f['n_error'],
            'promedio' => $f['conf_error'] !== null ? round((float) $f['conf_error'], 3) : null,
        ],
        'no_reconocido' => [
            'n' => (int) $f['n_no_reconocido'],
            'promedio' => $f['conf_no_reconocido'] !== null ? round((float) $f['conf_no_reconocido'], 3) : null,
        ],
    ];

    // Distribución en tramos de 0.1, con el acierto de cada tramo. Es la
    // versión de la pregunta anterior que sirve para elegir un umbral: no
    // "los errores tienen menos confianza", sino "abajo de 0.7 el acierto es
    // X%". Se agrupa por FLOOR(conf * 10) para no interpolar nada en el SQL.
    $stmt = $pdo->prepare(
        "SELECT FLOOR(confianza_ia * 10) / 10 AS tramo,
                COUNT(*) AS n,
                SUM(" . SQL_ACIERTO . ") AS aciertos
           FROM correcciones_ia
         $where
           AND confianza_ia IS NOT NULL
          GROUP BY tramo
          ORDER BY tramo ASC"
    );
    $stmt->execute($parametros);

    $tramos = [];
    foreach ($stmt->fetchAll() as $fila) {
        $n = (int) $fila['n'];
        $ok = (int) $fila['aciertos'];
        $tramos[] = [
            'desde' => round((float) $fila['tramo'], 2),
            'hasta' => round((float) $fila['tramo'] + 0.1, 2),
            'n' => $n,
            'aciertos' => $ok,
            'acierto_pct' => porcentaje($ok, $n),
        ];
    }

    // ----------------------------------------------------------------
    // (f) — top 5 de confusiones
    // ----------------------------------------------------------------
    // Qué confundió con qué. En el caso real el error típico son dos
    // variantes del MISMO envase (el shampoo con el acondicionador de la
    // misma línea), y verlo en una tabla es lo que dice qué fotos hay que
    // agregar al dataset.
    $stmt = $pdo->prepare(
        "SELECT c.producto_detectado_id, c.producto_corregido_id,
                COUNT(*) AS veces,
                ROUND(AVG(c.confianza_ia), 3) AS confianza_promedio,
                pd.nombre AS detectado_nombre, pd.marca AS detectado_marca,
                pc.nombre AS corregido_nombre, pc.marca AS corregido_marca
           FROM correcciones_ia c
           LEFT JOIN productos pd ON pd.id = c.producto_detectado_id AND pd.negocio_id = c.negocio_id
           LEFT JOIN productos pc ON pc.id = c.producto_corregido_id AND pc.negocio_id = c.negocio_id
          WHERE c.negocio_id = :negocio_id
            AND c.fecha >= :desde
            AND c.fecha < DATE_ADD(:hasta, INTERVAL 1 DAY)
            -- La misma condición de SQL_ERR_PRODUCTO, escrita con el alias.
            -- Es la única query que joinea, así que es la única donde las
            -- columnas necesitan calificarse.
            AND c.producto_detectado_id IS NOT NULL
            AND NOT (c.producto_detectado_id <=> c.producto_corregido_id)
          GROUP BY c.producto_detectado_id, c.producto_corregido_id,
                   pd.nombre, pd.marca, pc.nombre, pc.marca
          ORDER BY veces DESC, c.producto_detectado_id ASC
          LIMIT 5"
    );
    $stmt->execute($parametros);

    $confusiones = [];
    foreach ($stmt->fetchAll() as $fila) {
        $confusiones[] = [
            'detectado' => [
                'id' => (int) $fila['producto_detectado_id'],
                'nombre' => $fila['detectado_nombre'],
                'marca' => $fila['detectado_marca'],
            ],
            'corregido' => [
                'id' => $fila['producto_corregido_id'] !== null ? (int) $fila['producto_corregido_id'] : null,
                'nombre' => $fila['corregido_nombre'],
                'marca' => $fila['corregido_marca'],
            ],
            'veces' => (int) $fila['veces'],
            'confianza_promedio' => $fila['confianza_promedio'] !== null ? (float) $fila['confianza_promedio'] : null,
        ];
    }

    // ----------------------------------------------------------------
    // (g) — evolución semanal
    // ----------------------------------------------------------------
    // YEARWEEK con modo 1 (semanas de lunes a domingo, la convención de acá).
    // Se devuelve la fecha del lunes en vez del número de semana porque
    // "2026-33" no se puede poner en un eje y leerlo.
    $stmt = $pdo->prepare(
        "SELECT DATE(DATE_SUB(fecha, INTERVAL WEEKDAY(fecha) DAY)) AS semana,
                COUNT(*) AS n,
                SUM(" . SQL_ACIERTO . ") AS aciertos
           FROM correcciones_ia
         $where
          GROUP BY semana
          ORDER BY semana ASC
          LIMIT 60"
    );
    $stmt->execute($parametros);

    $semanas = [];
    foreach ($stmt->fetchAll() as $fila) {
        $n = (int) $fila['n'];
        $ok = (int) $fila['aciertos'];
        $semanas[] = [
            'semana' => $fila['semana'],
            'n' => $n,
            'aciertos' => $ok,
            'acierto_pct' => porcentaje($ok, $n),
        ];
    }

    echo json_encode([
        'ok' => true,
        'desde' => $desde,
        'hasta' => $hasta,
        // La pantalla mira ESTO antes de dibujar un porcentaje grande.
        'muestra_insuficiente' => $total < MUESTRA_MINIMA,
        'muestra_minima' => MUESTRA_MINIMA,
        'resumen' => $resumen,
        'confianza' => $confianza,
        'tramos_confianza' => $tramos,
        'confusiones' => $confusiones,
        'semanas' => $semanas,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al calcular las métricas de la IA',
        'error' => $e->getMessage(),
    ], 500);
}
