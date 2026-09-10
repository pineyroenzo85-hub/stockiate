<?php
/**
 * stockIAte - guardar_stock.php
 * ================================
 * Recibe el JSON confirmado desde la pantalla de validación (Red de
 * Seguridad) y lo persiste en MySQL local (XAMPP).
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "cantidad": 24,
 *   "fecha_vencimiento": "2026-12-01"    // opcional, puede venir null
 * }
 *
 * `usuario_id` ya no viene en el body: sale de la sesión.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'movimientos.php'; // registrar_movimiento()

cabeceras_json();
exigir_metodo('POST');

// Cargar stock es cosa del repositor (y del dueño, que entra a todos lados).
$sesion = exigir_sesion(['repositor', 'dueño']);
$negocio_id = $sesion['negocio_id'];
$usuario_id = $sesion['id'];

$body = cuerpo_json();

if (!isset($body['producto_id'], $body['cantidad'])) {
    responder(["ok" => false, "mensaje" => "Faltan campos obligatorios"], 400);
}

$producto_id = (int) $body['producto_id'];
$cantidad = (int) $body['cantidad'];

// Fecha de vencimiento: opcional. Si no viene o es null, se guarda como NULL
// y ese lote simplemente no participa del control de vencimientos.
$fecha_vencimiento = null;
if (!empty($body['fecha_vencimiento'])) {
    $fecha_vencimiento = $body['fecha_vencimiento'];
}

try {
    $pdo->beginTransaction();

    // 0) El producto tiene que ser de ESTE negocio y estar activo (no
    //    archivado). Se lockea con FOR UPDATE porque el UPDATE de más abajo
    //    suma sobre su stock.
    $stmtProducto = $pdo->prepare(
        // Se trae `stock_actual` además del id: el UPDATE de abajo suma sin
        // leer, y el libro mayor necesita el número de ANTES. La fila ya está
        // lockeada, así que traer la columna no cuesta nada extra.
        "SELECT id, stock_actual FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        responder(["ok" => false, "mensaje" => "El producto no existe"], 404);
    }

    // 1) Insertar el lote (permite trazabilidad y control de vencimiento por lote)
    $stmtLote = $pdo->prepare(
        "INSERT INTO lotes_stock (negocio_id, producto_id, cantidad, fecha_vencimiento, usuario_id)
         VALUES (:negocio_id, :producto_id, :cantidad, :fecha_vencimiento, :usuario_id)"
    );
    $stmtLote->execute([
        ':negocio_id' => $negocio_id,
        ':producto_id' => $producto_id,
        ':cantidad' => $cantidad,
        ':fecha_vencimiento' => $fecha_vencimiento,
        ':usuario_id' => $usuario_id,
    ]);

    $lote_id = (int) $pdo->lastInsertId();

    // 2) Actualizar el stock total del producto
    //    ON DUPLICATE KEY UPDATE no aplica directo acá porque productos.id
    //    ya existe, así que actualizamos con un UPDATE simple sumando cantidad.
    $stmtStock = $pdo->prepare(
        "UPDATE productos
         SET stock_actual = stock_actual + :cantidad
         WHERE id = :producto_id AND negocio_id = :negocio_id"
    );
    $stmtStock->execute([
        ':cantidad' => $cantidad,
        ':producto_id' => $producto_id,
        ':negocio_id' => $negocio_id,
    ]);

    // 3) Anotarlo en el libro mayor, DENTRO de la misma transacción: el lote,
    //    el stock y el movimiento son un solo hecho. Ver movimientos.php.
    registrar_movimiento(
        $pdo,
        $negocio_id,
        $producto_id,
        'carga',
        $cantidad,
        (int) $producto['stock_actual'],
        $usuario_id,
        'lote',
        $lote_id
    );

    $pdo->commit();

    responder([
        "ok" => true,
        "mensaje" => "Stock actualizado correctamente",
        "producto_id" => $producto_id,
        "cantidad_agregada" => $cantidad,
        "fecha_vencimiento" => $fecha_vencimiento,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        "ok" => false,
        "mensaje" => "Error al guardar el stock",
        "error" => $e->getMessage(),
    ], 500);
}
