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
 *   "fecha_vencimiento": "2026-12-01",   // opcional, puede venir null
 *   "proveedor": "Distribuidora Sur",    // opcional: proveedor de ESTE lote
 *   "precio_costo": 2000                 // opcional: costo de ESTE lote
 * }
 *
 * `usuario_id` ya no viene en el body: sale de la sesión.
 *
 * `proveedor`/`precio_costo` quedan grabados en el lote (para siempre, sin
 * que una carga posterior los pise) Y ADEMÁS actualizan el "último usado"
 * en productos.proveedor_id/precio_costo -- pero sólo si vinieron con
 * valor: dejar el campo en blanco en el formulario nunca borra el default
 * que el producto ya tenía de una carga anterior.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'movimientos.php'; // registrar_movimiento()
require_once 'proveedores.php'; // resolverProveedorId(), compartido con crear_producto.php y actualizar_costo.php

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

// Proveedor y precio de costo de ESTE lote puntual: los dos son opcionales
// (el formulario puede quedar en blanco) y NO pisan el default del producto
// si vienen vacíos -- ver el UPDATE condicional más abajo.
$proveedorNombreLote = !empty($body['proveedor']) ? trim((string) $body['proveedor']) : null;
$precio_costo_lote = (isset($body['precio_costo']) && $body['precio_costo'] !== '' && $body['precio_costo'] !== null)
    ? (float) $body['precio_costo']
    : null;

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

    // El proveedor se resuelve DENTRO de la transacción, igual que en
    // actualizar_costo.php: si el INSERT del lote falla, no queda un
    // proveedor huérfano recién creado.
    $proveedor_id_lote = resolverProveedorId($pdo, $proveedorNombreLote, $negocio_id);

    // 1) Insertar el lote (permite trazabilidad y control de vencimiento por
    //    lote, y ahora también de proveedor/costo -- ver schema.sql).
    $stmtLote = $pdo->prepare(
        "INSERT INTO lotes_stock (negocio_id, producto_id, cantidad, proveedor_id, precio_costo, fecha_vencimiento, usuario_id)
         VALUES (:negocio_id, :producto_id, :cantidad, :proveedor_id, :precio_costo, :fecha_vencimiento, :usuario_id)"
    );
    $stmtLote->execute([
        ':negocio_id' => $negocio_id,
        ':producto_id' => $producto_id,
        ':cantidad' => $cantidad,
        ':proveedor_id' => $proveedor_id_lote,
        ':precio_costo' => $precio_costo_lote,
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

    // 2.1) Actualiza el "default" del producto SÓLO con lo que efectivamente
    //      vino en este lote: dejar el campo en blanco en el formulario NO
    //      tiene que borrar el proveedor/costo que ya tenía el producto de
    //      una carga anterior. Mismo criterio de "no pisar lo que no se
    //      mandó" que actualizar_costo.php, pero acá la condición es sobre
    //      el VALOR resuelto (no sobre si la clave vino en el body), porque
    //      este endpoint nunca borra el default -- sólo lo actualiza cuando
    //      hay algo nuevo para reemplazarlo.
    $camposProducto = [];
    $parametrosProducto = [':producto_id' => $producto_id, ':negocio_id' => $negocio_id];

    if ($proveedor_id_lote !== null) {
        $camposProducto[] = "proveedor_id = :proveedor_id";
        $parametrosProducto[':proveedor_id'] = $proveedor_id_lote;
    }
    if ($precio_costo_lote !== null) {
        $camposProducto[] = "precio_costo = :precio_costo";
        $parametrosProducto[':precio_costo'] = $precio_costo_lote;
    }

    if (count($camposProducto) > 0) {
        $stmtDefaultProducto = $pdo->prepare(
            "UPDATE productos SET " . implode(", ", $camposProducto) . "
              WHERE id = :producto_id AND negocio_id = :negocio_id"
        );
        $stmtDefaultProducto->execute($parametrosProducto);
    }

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
        "proveedor_id" => $proveedor_id_lote,
        "precio_costo" => $precio_costo_lote,
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
