<?php
/**
 * stockIAte - registrar_venta.php
 * ================================
 * Recibe el ticket confirmado desde la pantalla de venta (Red de Seguridad
 * del módulo Caja) y lo persiste en MySQL local (XAMPP): resta stock y
 * registra las ventas.
 *
 * Espera un body con el ticket completo:
 * {
 *   "items": [
 *     { "producto_id": 12, "cantidad": 2 },
 *     { "producto_id": 30, "cantidad": 1 }
 *   ]
 * }
 *
 * También acepta el formato viejo de un solo producto
 * ({"producto_id": 12, "cantidad": 2}), que se trata como un ticket de un
 * ítem.
 *
 * `usuario_id` ya no viene en el body: sale de la sesión.
 *
 * **El ticket es atómico**: se valida el stock de TODOS los ítems antes de
 * escribir nada, y si alguno falla no se registra ninguno (rollback). Antes
 * el frontend mandaba un request por producto, así que un solo renglón sin
 * stock dejaba la venta a medio registrar y el resto ya descontado.
 *
 * El precio se toma de productos.precio_venta en la base (no del cliente),
 * para no confiar en un precio que venga del navegador.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'movimientos.php'; // registrar_movimiento()
require_once 'alertas.php'; // es_critico()
require_once 'notificaciones.php'; // cola de avisos por WhatsApp

cabeceras_json();
exigir_metodo('POST');

// Vender es cosa de cajero (y del dueño, que puede entrar a cualquier módulo).
$sesion = exigir_sesion(['cajero', 'dueño']);
$negocio_id = $sesion['negocio_id'];
$usuario_id = $sesion['id'];

$body = cuerpo_json();

// Formato viejo (un solo producto) -> ticket de un ítem.
if (!isset($body['items']) && isset($body['producto_id'], $body['cantidad'])) {
    $body['items'] = [[
        'producto_id' => $body['producto_id'],
        'cantidad' => $body['cantidad'],
    ]];
}

if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
    responder(["ok" => false, "mensaje" => "El ticket no tiene productos"], 400);
}

// Normalizamos y agrupamos por producto: si el mismo producto vino en dos
// renglones (ej. dos detecciones que el cajero apuntó al mismo artículo),
// se suman las cantidades. Si no, cada renglón validaría el stock por su
// cuenta y entre los dos podrían llevarse más unidades de las que hay.
$items = [];
foreach ($body['items'] as $item) {
    if (!isset($item['producto_id'], $item['cantidad'])) {
        responder(["ok" => false, "mensaje" => "Hay un producto sin id o sin cantidad"], 400);
    }

    $producto_id = (int) $item['producto_id'];
    $cantidad = (int) $item['cantidad'];

    if ($producto_id <= 0) {
        responder(["ok" => false, "mensaje" => "Hay un producto sin seleccionar"], 400);
    }
    if ($cantidad <= 0) {
        responder(["ok" => false, "mensaje" => "La cantidad debe ser mayor a 0"], 400);
    }

    $items[$producto_id] = ($items[$producto_id] ?? 0) + $cantidad;
}

// Orden fijo por id: dos cajas cobrando a la vez lockean las filas en el
// mismo orden y no se traban entre sí (deadlock).
ksort($items);

try {
    $pdo->beginTransaction();

    // El `negocio_id` acá es lo que impide vender el producto de otro
    // comercio: un id ajeno no matchea, cae en el "El producto no existe" de
    // más abajo y el ticket entero se rechaza con 409, sin escribir nada.
    // `activo = 1` hace lo mismo con los archivados: si alguien tenía la
    // pantalla de caja abierta cuando el dueño archivó el producto, el
    // ticket se rechaza entero en vez de vender algo dado de baja.
    $stmtProducto = $pdo->prepare(
        "SELECT id, nombre, precio_venta, precio_costo, stock_actual, stock_minimo FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );

    // 1) Validar TODO el ticket antes de escribir nada. Juntamos todos los
    //    errores (no cortamos en el primero) para que el cajero vea de una
    //    qué renglones tiene que corregir.
    $validados = [];
    $errores = [];

    foreach ($items as $producto_id => $cantidad) {
        $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
        $producto = $stmtProducto->fetch();

        if (!$producto) {
            $errores[] = [
                "producto_id" => $producto_id,
                "nombre" => null,
                "pedido" => $cantidad,
                "disponible" => 0,
                "mensaje" => "El producto no existe",
            ];
            continue;
        }

        if ((int) $producto['stock_actual'] < $cantidad) {
            $errores[] = [
                "producto_id" => $producto_id,
                "nombre" => $producto['nombre'],
                "pedido" => $cantidad,
                "disponible" => (int) $producto['stock_actual'],
                "mensaje" => "{$producto['nombre']}: stock insuficiente (disponible: {$producto['stock_actual']}, pedido: {$cantidad})",
            ];
            continue;
        }

        $validados[] = [
            "producto_id" => $producto_id,
            "nombre" => $producto['nombre'],
            "cantidad" => $cantidad,
            "precio_unitario" => (float) $producto['precio_venta'],
            // Snapshot del costo de HOY (puede ser NULL si el producto no lo
            // tiene cargado). Se congela acá para que el margen histórico no
            // se recalcule cuando el proveedor aumente. Ver el comentario de
            // la columna en schema.sql.
            "costo_unitario" => $producto['precio_costo'] !== null
                ? (float) $producto['precio_costo']
                : null,
            "subtotal" => (float) $producto['precio_venta'] * $cantidad,
            // Con cuánto queda el producto después de esta venta. Sale de la
            // fila que ya está lockeada, así que no hace falta releerla
            // después del commit para saber si hay que avisar.
            "stock_resultante" => (int) $producto['stock_actual'] - $cantidad,
            "stock_minimo" => (int) $producto['stock_minimo'],
        ];
    }

    if (count($errores) > 0) {
        $pdo->rollBack();
        responder([
            "ok" => false,
            "mensaje" => count($errores) === 1
                ? $errores[0]['mensaje']
                : "Hay " . count($errores) . " productos que no se pueden vender. No se registró nada.",
            "errores" => $errores,
        ], 409);
    }

    // 2) Recién ahora escribimos: todos los ítems o ninguno.
    $stmtVenta = $pdo->prepare(
        "INSERT INTO ventas (negocio_id, producto_id, cantidad, precio_unitario, costo_unitario, usuario_id)
         VALUES (:negocio_id, :producto_id, :cantidad, :precio_unitario, :costo_unitario, :usuario_id)"
    );
    $stmtStock = $pdo->prepare(
        "UPDATE productos
         SET stock_actual = stock_actual - :cantidad
         WHERE id = :producto_id AND negocio_id = :negocio_id"
    );

    $total = 0;
    foreach ($validados as $item) {
        $stmtVenta->execute([
            ':negocio_id' => $negocio_id,
            ':producto_id' => $item['producto_id'],
            ':cantidad' => $item['cantidad'],
            ':precio_unitario' => $item['precio_unitario'],
            ':costo_unitario' => $item['costo_unitario'],
            ':usuario_id' => $usuario_id,
        ]);

        // Se captura acá y no después del UPDATE: un UPDATE no cambia el
        // lastInsertId, así que hoy daría lo mismo, pero leerlo pegado al
        // INSERT que lo generó es lo que hace que siga siendo correcto si
        // mañana se mete otra escritura en el medio.
        $venta_id = (int) $pdo->lastInsertId();

        $stmtStock->execute([
            ':cantidad' => $item['cantidad'],
            ':producto_id' => $item['producto_id'],
            ':negocio_id' => $negocio_id,
        ]);

        // El libro mayor va DENTRO de la transacción, al revés que el encolado
        // de WhatsApp de más abajo. No es una inconsistencia: un aviso que no
        // sale es un aviso perdido, pero un movimiento que no se registra es
        // stock que cambió sin que nadie sepa por qué. Ver movimientos.php.
        //
        // `stock_resultante` salió de la fila lockeada en la validación, así
        // que el stock de ANTES es ése más lo que se vende.
        registrar_movimiento(
            $pdo,
            $negocio_id,
            $item['producto_id'],
            'venta',
            -$item['cantidad'],
            $item['stock_resultante'] + $item['cantidad'],
            $usuario_id,
            'venta',
            $venta_id
        );

        $total += $item['subtotal'];
    }

    $pdo->commit();

    // ------------------------------------------------------------------
    // Avisos de reposición por WhatsApp
    // ------------------------------------------------------------------
    // Va DESPUÉS del commit, FUERA de la transacción y con su propio catch
    // que se traga todo. La venta ya está registrada y es irreversible: un
    // problema encolando un aviso no puede hacerle rollback ni devolverle un
    // error al cajero, que tiene al cliente enfrente y no sabe ni le importa
    // qué es WhatsApp.
    //
    // Acá sólo se hace un INSERT local (microsegundos). Quien le pega a la
    // API de Meta es tareas_notificaciones.php, después y por su cuenta.
    try {
        $config = config_whatsapp($pdo, $negocio_id);

        if (whatsapp_habilitado($config)) {
            $negocio_nombre = nombre_negocio($pdo, $negocio_id);
            // Cada cuántas horas el dueño acepta que se repita el aviso del
            // mismo producto. Se lee una sola vez para todo el ticket, no una
            // por renglón: es la misma pregunta para todos.
            $ventana_horas = ventana_notificaciones_config($pdo, $negocio_id);

            foreach ($validados as $item) {
                $restante = $item['stock_resultante'];

                if ($restante > 0 && !es_critico($restante, $item['stock_minimo'])) {
                    continue;
                }

                $tipo = $restante <= 0 ? 'sin_stock' : 'stock_critico';
                $frase = $restante <= 0
                    ? "Se quedó sin stock de {$item['nombre']}"
                    : "Queda poco stock de {$item['nombre']}";

                // La clave de deduplicación lleva el tipo además del id: si un
                // producto pasa de crítico a cero en el mismo día, ese segundo
                // aviso (que es el urgente) igual sale.
                encolar_notificacion(
                    $pdo,
                    $negocio_id,
                    $config,
                    $tipo,
                    $tipo . ':' . $item['producto_id'],
                    [$negocio_nombre, $frase, $restante, $item['stock_minimo']],
                    $ventana_horas
                );
            }
        }
    } catch (Throwable $e) {
        // Silencio deliberado: no hay a quién avisarle de que falló el aviso,
        // y la venta salió bien. Si no llega ningún WhatsApp, el diagnóstico
        // se hace desde el panel (tabla de notificaciones) o corriendo
        // `php probar_whatsapp.php`.
    }

    responder([
        "ok" => true,
        "mensaje" => "Venta registrada correctamente",
        "items" => $validados,
        "cantidad_productos" => count($validados),
        "unidades" => array_sum(array_column($validados, 'cantidad')),
        "total" => $total,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        "ok" => false,
        "mensaje" => "Error al registrar la venta",
        "error" => $e->getMessage(),
    ], 500);
}
