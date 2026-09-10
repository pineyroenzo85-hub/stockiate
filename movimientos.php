<?php
/**
 * stockIAte - movimientos.php
 * ================================
 * Dueño de la tabla `movimientos_stock`, el libro mayor del inventario.
 *
 * Todo cambio de `productos.stock_actual` pasa por acá. Antes, las entradas
 * quedaban en `lotes_stock`, las salidas por venta en `ventas`, y los ajustes
 * de los botones +/- del panel no quedaban en ningún lado: si el sistema decía
 * 12 y en la góndola había 9, no había forma de saber por qué.
 *
 * LA REGLA
 * --------
 * `registrar_movimiento()` se llama SIEMPRE DENTRO de la misma transacción que
 * hace el UPDATE del stock, nunca después del commit.
 *
 * Es exactamente lo contrario de lo que hace `encolar_notificacion()`, y a
 * propósito. Un WhatsApp que no sale es un aviso perdido; un movimiento que no
 * se registra es un stock que cambió sin que nadie sepa por qué, o sea el
 * problema que esta tabla vino a resolver. El libro mayor tiene que ser
 * atómico con el hecho que registra: o pasan las dos cosas, o no pasa ninguna.
 *
 * QUÉ NO HACE
 * -----------
 * No valida permisos ni negocio: eso ya lo hizo el endpoint que llama, con
 * `exigir_sesion()` y su `SELECT ... FOR UPDATE` filtrado por `negocio_id`.
 * Acá se confía en el llamador, igual que en `configuracion.php`.
 */

/**
 * Anota un cambio de stock en el libro mayor.
 *
 * @param int    $delta           con signo: positivo entra, negativo sale
 * @param int    $stock_anterior  el stock ANTES del cambio
 * @param string $motivo          carga | venta | ajuste | conteo
 * @param string|null $ref_tipo   a qué tabla apunta (lote, venta, conteo)
 * @param int|null    $ref_id     el id en esa tabla
 */
function registrar_movimiento(
    PDO $pdo,
    int $negocio_id,
    int $producto_id,
    string $motivo,
    int $delta,
    int $stock_anterior,
    ?int $usuario_id = null,
    ?string $ref_tipo = null,
    ?int $ref_id = null
): void {
    // Un movimiento que no mueve nada no se registra: ensuciaría el libro sin
    // decir nada. Pasa, por ejemplo, cuando un conteo confirma que el stock ya
    // estaba bien.
    if ($delta === 0) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO movimientos_stock
            (negocio_id, producto_id, motivo, delta, stock_anterior, stock_nuevo,
             referencia_tipo, referencia_id, usuario_id)
         VALUES
            (:negocio_id, :producto_id, :motivo, :delta, :anterior, :nuevo,
             :ref_tipo, :ref_id, :usuario_id)"
    );

    $stmt->execute([
        ':negocio_id' => $negocio_id,
        ':producto_id' => $producto_id,
        ':motivo' => $motivo,
        ':delta' => $delta,
        ':anterior' => $stock_anterior,
        // Se calcula acá y no se recibe: que las dos columnas puedan
        // contradecirse al delta sería perder la propiedad que hace auditable
        // a la tabla (`stock_anterior + delta = stock_nuevo`, siempre).
        ':nuevo' => $stock_anterior + $delta,
        ':ref_tipo' => $ref_tipo,
        ':ref_id' => $ref_id,
        ':usuario_id' => $usuario_id,
    ]);
}

/**
 * Los últimos movimientos de un producto, para mostrar su historia.
 *
 * Ordena por `id` y no por `fecha`: dos movimientos del mismo segundo (una
 * venta de varios renglones, por ejemplo) tienen la misma `fecha` al segundo y
 * quedarían en un orden arbitrario, que es justo lo que no se quiere en un
 * libro mayor.
 */
function movimientos_de_producto(PDO $pdo, int $negocio_id, int $producto_id, int $limite = 50): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id, m.motivo, m.delta, m.stock_anterior, m.stock_nuevo,
                m.referencia_tipo, m.referencia_id, m.fecha,
                CONCAT(u.nombre, ' ', u.apellido) AS usuario
           FROM movimientos_stock m
           LEFT JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.negocio_id = :negocio_id
            AND m.producto_id = :producto_id
          ORDER BY m.id DESC
          LIMIT " . (int) $limite
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':producto_id' => $producto_id]);

    return $stmt->fetchAll();
}
