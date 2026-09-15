<?php
/**
 * stockIAte - buscar_producto.php
 * ================================
 * Busca un producto por código de barras para el flujo de lector físico
 * (pistola USB/Bluetooth o serial, ver barcode-manager.js). El parámetro
 * `aplicar` decide si además de buscar, se ejecuta el movimiento de stock:
 *
 * - aplicar = false -> solo busca y devuelve el producto, SIN tocar stock
 *   (se usa cuando "Modo de confirmación automática" está apagado: el
 *   frontend muestra el producto, y recién aplica en una segunda llamada
 *   cuando el usuario confirma manualmente).
 * - aplicar = true  -> busca Y aplica 1 unidad (resta si cajero -> venta,
 *   suma si repositor -> lote de carga sin vencimiento).
 *
 * `rol` y `negocio_id` NUNCA vienen del body: salen de la sesión de
 * servidor, igual que en registrar_venta.php y guardar_stock.php. Confiar en
 * esos campos del cliente es justo el agujero que sesion.php vino a cerrar
 * (ver su cabecera).
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'movimientos.php'; // registrar_movimiento()

cabeceras_json();
exigir_metodo('POST');

// Escanear es cosa de repositor o cajero (y del dueño, que entra a todos lados).
$sesion = exigir_sesion(['repositor', 'cajero', 'dueño']);
$negocio_id = $sesion['negocio_id'];
$usuario_id = $sesion['id'];
$rol = $sesion['rol'];

// El dueño no tiene un flujo de escaneo propio (no carga ni vende): si entra
// a Ajustes y prueba el lector, lo tratamos como repositor para no romper el
// flujo (mismo criterio que "dueño puede entrar a cualquier módulo").
$rol_movimiento = $rol === 'cajero' ? 'cajero' : 'repositor';

$body = cuerpo_json();

$codigoBarras = trim($body['codigo_barras'] ?? '');
$aplicar = filter_var($body['aplicar'] ?? true, FILTER_VALIDATE_BOOLEAN);

if ($codigoBarras === '') {
    responder(["ok" => false, "mensaje" => "Falta el código de barras"], 400);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, precio_venta, stock_actual, stock_minimo
           FROM productos
          WHERE codigo_barras = :codigo AND negocio_id = :negocio_id AND activo = 1
          LIMIT 1"
    );
    $stmt->execute([':codigo' => $codigoBarras, ':negocio_id' => $negocio_id]);
    $producto = $stmt->fetch();

    // Código no registrado: el frontend ofrece vincularlo o crearlo.
    if (!$producto) {
        responder(["ok" => true, "encontrado" => false, "codigo_barras" => $codigoBarras]);
    }

    // Solo lectura: no tocar stock (previsualización antes de confirmar).
    if (!$aplicar) {
        responder(["ok" => true, "encontrado" => true, "producto" => $producto, "accion" => "previsualizacion"]);
    }

    $pdo->beginTransaction();

    // Re-lockeamos la fila recién al momento de escribir: el SELECT de arriba
    // fue de solo lectura (para la previsualización), así que acá se repite
    // con FOR UPDATE, igual que registrar_venta.php y guardar_stock.php.
    $stmtLock = $pdo->prepare(
        "SELECT id, nombre, precio_venta, stock_actual, stock_minimo FROM productos
          WHERE id = :id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );
    $stmtLock->execute([':id' => $producto['id'], ':negocio_id' => $negocio_id]);
    $producto = $stmtLock->fetch();

    if (!$producto) {
        $pdo->rollBack();
        responder(["ok" => false, "mensaje" => "El producto ya no existe"], 404);
    }

    if ($rol_movimiento === 'cajero') {
        // Venta de 1 unidad: nunca deja stock negativo.
        if ((int) $producto['stock_actual'] <= 0) {
            $pdo->rollBack();
            responder([
                "ok" => true,
                "encontrado" => true,
                "producto" => $producto,
                "advertencia" => "Sin stock disponible",
            ]);
        }

        $stmtVenta = $pdo->prepare(
            "INSERT INTO ventas (negocio_id, producto_id, cantidad, precio_unitario, costo_unitario, usuario_id)
             VALUES (:negocio_id, :producto_id, 1, :precio_unitario, NULL, :usuario_id)"
        );
        $stmtVenta->execute([
            ':negocio_id' => $negocio_id,
            ':producto_id' => $producto['id'],
            ':precio_unitario' => $producto['precio_venta'],
            ':usuario_id' => $usuario_id,
        ]);
        $venta_id = (int) $pdo->lastInsertId();

        $stmtStock = $pdo->prepare(
            "UPDATE productos SET stock_actual = stock_actual - 1
              WHERE id = :id AND negocio_id = :negocio_id"
        );
        $stmtStock->execute([':id' => $producto['id'], ':negocio_id' => $negocio_id]);

        registrar_movimiento(
            $pdo, $negocio_id, $producto['id'], 'venta', -1,
            (int) $producto['stock_actual'], $usuario_id, 'venta', $venta_id
        );

        $producto['stock_actual'] = (int) $producto['stock_actual'] - 1;
        $accion = 'venta';
    } else {
        // Reposición de 1 unidad: entra como lote sin fecha de vencimiento
        // (escaneo rápido, sin la pantalla de Red de Seguridad de por medio).
        $stmtLote = $pdo->prepare(
            "INSERT INTO lotes_stock (negocio_id, producto_id, cantidad, fecha_vencimiento, usuario_id)
             VALUES (:negocio_id, :producto_id, 1, NULL, :usuario_id)"
        );
        $stmtLote->execute([
            ':negocio_id' => $negocio_id,
            ':producto_id' => $producto['id'],
            ':usuario_id' => $usuario_id,
        ]);
        $lote_id = (int) $pdo->lastInsertId();

        $stmtStock = $pdo->prepare(
            "UPDATE productos SET stock_actual = stock_actual + 1
              WHERE id = :id AND negocio_id = :negocio_id"
        );
        $stmtStock->execute([':id' => $producto['id'], ':negocio_id' => $negocio_id]);

        registrar_movimiento(
            $pdo, $negocio_id, $producto['id'], 'carga', 1,
            (int) $producto['stock_actual'], $usuario_id, 'lote', $lote_id
        );

        $producto['stock_actual'] = (int) $producto['stock_actual'] + 1;
        $accion = 'reposicion';
    }

    $pdo->commit();

    responder(["ok" => true, "encontrado" => true, "producto" => $producto, "accion" => $accion]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(["ok" => false, "mensaje" => "Error de base de datos", "error" => $e->getMessage()], 500);
}
