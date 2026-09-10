<?php
/**
 * stockIAte - actualizar_stock.php
 * ================================
 * Ajusta el stock de un producto en +1/-1 (botones +/- de la tabla de
 * inventario, en Administrador y Repositor). Usa SELECT ... FOR UPDATE
 * dentro de una transacción, mismo patrón que registrar_venta.php, para
 * evitar condiciones de carrera con otros ajustes o ventas simultáneas.
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "delta": 1         // o -1
 * }
 *
 * `usuario_id` ya no viene en el body (el frontend lo mandaba hardcodeado en
 * 1): quién hace el ajuste sale de la sesión.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'movimientos.php'; // registrar_movimiento()

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion();
$negocio_id = $sesion['negocio_id'];

$body = cuerpo_json();

if (!isset($body['producto_id'], $body['delta'])) {
    responder(["ok" => false, "mensaje" => "Faltan campos obligatorios"], 400);
}

$producto_id = (int) $body['producto_id'];
$delta = (int) $body['delta'];

if ($delta !== 1 && $delta !== -1) {
    responder(["ok" => false, "mensaje" => "delta debe ser 1 o -1"], 400);
}

try {
    $pdo->beginTransaction();

    // El filtro por negocio va en el SELECT: un producto de otro comercio
    // simplemente "no existe" desde acá (404), sin revelar que existe. Un
    // producto archivado tampoco: no se le ajusta el stock desde el panel.
    $stmtProducto = $pdo->prepare(
        "SELECT id, stock_actual, stock_minimo FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        responder(["ok" => false, "mensaje" => "El producto no existe"], 404);
    }

    $nuevoStock = (int) $producto['stock_actual'] + $delta;

    if ($nuevoStock < 0) {
        $pdo->rollBack();
        responder([
            "ok" => false,
            "mensaje" => "El stock no puede quedar negativo (disponible: {$producto['stock_actual']})",
        ], 409);
    }

    // Y también en el UPDATE, no sólo en el SELECT: si mañana alguien saca el
    // filtro de arriba, la escritura sigue acotada al negocio.
    $stmtUpdate = $pdo->prepare(
        "UPDATE productos SET stock_actual = :nuevo_stock
          WHERE id = :producto_id AND negocio_id = :negocio_id"
    );
    $stmtUpdate->execute([
        ':nuevo_stock' => $nuevoStock,
        ':producto_id' => $producto_id,
        ':negocio_id' => $negocio_id,
    ]);

    // DENTRO de la transacción, no después del commit: un ajuste que cambia el
    // stock sin dejar rastro es exactamente el problema que el libro mayor
    // vino a resolver. Ver la nota de movimientos.php.
    registrar_movimiento(
        $pdo,
        $negocio_id,
        $producto_id,
        'ajuste',
        $delta,
        (int) $producto['stock_actual'],
        $sesion['id']
    );

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "producto_id" => $producto_id,
        "stock_actual" => $nuevoStock,
        "stock_minimo" => (int) $producto['stock_minimo'],
    ]);
} catch (PDOException $e) {
    // Guarda con inTransaction(), igual que registrar_venta.php: si la
    // transacción ya se cerró, rollBack() tiraría su propia excepción.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        "ok" => false,
        "mensaje" => "Error al actualizar el stock",
        "error" => $e->getMessage(),
    ], 500);
}
