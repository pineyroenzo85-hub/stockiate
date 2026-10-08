<?php
/**
 * stockIAte - actualizar_precios.php
 * ================================
 * Aplica precios nuevos a varios productos de una sola vez: es el botón
 * "Aplicar precio sugerido" del módulo "Precios e inflación"
 * (precios_inflacion.html), después de que el dueño confirmó el cambio en
 * pantalla. Misma filosofía que la Red de Seguridad: nada se escribe sin que
 * una persona lo haya confirmado antes; acá la confirmación ya pasó en el
 * frontend (`confirm()` con la cantidad de precios a cambiar) y este
 * endpoint sólo ejecuta lo que se aceptó.
 *
 * Espera un body tipo:
 * {
 *   "items": [
 *     { "producto_id": 12, "precio_nuevo": 4900 },
 *     { "producto_id": 30, "precio_nuevo": 6200 }
 *   ],
 *   "motivo": "ajuste_inflacion"   // opcional, default 'manual'
 * }
 *
 * Mismo esqueleto transaccional que registrar_venta.php: se ordena por
 * producto_id (dos aplicaciones simultáneas lockean en el mismo orden y no
 * se traban entre sí), se lockea cada fila con SELECT ... FOR UPDATE, se
 * valida el LOTE ENTERO antes de escribir nada, y recién si no hay ningún
 * error se actualiza precio_venta y se deja el rastro en historial_precios
 * -- todo dentro de la misma transacción, porque un precio que cambió sin
 * quedar registrado es exactamente el problema que esa tabla existe para
 * resolver (mismo criterio que movimientos_stock).
 *
 * `usuario_id` sale de la sesión, nunca del body. Sólo el rol 'dueño'.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];
$usuario_id = $sesion['id'];

$body = cuerpo_json();

if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
    responder(['ok' => false, 'mensaje' => 'No hay precios para actualizar'], 400);
}

$motivosValidos = ['ajuste_inflacion', 'manual'];
$motivo = isset($body['motivo']) && in_array($body['motivo'], $motivosValidos, true)
    ? $body['motivo']
    : 'manual';

// Normalizamos a producto_id => precio_nuevo. Si el mismo producto vino dos
// veces en el lote, gana el último (no hay cantidades que sumar como en el
// ticket de venta, es un precio).
$items = [];
foreach ($body['items'] as $item) {
    if (!isset($item['producto_id'], $item['precio_nuevo'])) {
        responder(['ok' => false, 'mensaje' => 'Hay un precio sin producto_id o sin precio_nuevo'], 400);
    }

    $producto_id = (int) $item['producto_id'];
    if ($producto_id <= 0) {
        responder(['ok' => false, 'mensaje' => 'Hay un producto sin seleccionar'], 400);
    }
    if (!is_numeric($item['precio_nuevo']) || (float) $item['precio_nuevo'] <= 0) {
        responder(['ok' => false, 'mensaje' => 'El precio nuevo tiene que ser mayor a 0'], 400);
    }

    $items[$producto_id] = round((float) $item['precio_nuevo'], 2);
}

ksort($items);

try {
    $pdo->beginTransaction();

    $stmtProducto = $pdo->prepare(
        "SELECT id, nombre, precio_venta FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );

    // 1) Validar TODO el lote antes de escribir nada.
    $validados = [];
    $errores = [];

    foreach ($items as $producto_id => $precio_nuevo) {
        $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
        $producto = $stmtProducto->fetch();

        if (!$producto) {
            $errores[] = [
                'producto_id' => $producto_id,
                'nombre' => null,
                'mensaje' => 'El producto no existe',
            ];
            continue;
        }

        $validados[] = [
            'producto_id' => $producto_id,
            'nombre' => $producto['nombre'],
            'precio_anterior' => (float) $producto['precio_venta'],
            'precio_nuevo' => $precio_nuevo,
        ];
    }

    if (count($errores) > 0) {
        $pdo->rollBack();
        responder([
            'ok' => false,
            'mensaje' => count($errores) === 1
                ? $errores[0]['mensaje']
                : 'Hay ' . count($errores) . ' productos que no se pudieron actualizar. No se guardó ningún cambio.',
            'errores' => $errores,
        ], 409);
    }

    // 2) Recién ahora escribimos: todos los ítems o ninguno.
    $stmtUpdate = $pdo->prepare(
        "UPDATE productos SET precio_venta = :precio_nuevo
          WHERE id = :producto_id AND negocio_id = :negocio_id"
    );
    $stmtHistorial = $pdo->prepare(
        "INSERT INTO historial_precios (negocio_id, producto_id, precio_anterior, precio_nuevo, motivo, usuario_id)
         VALUES (:negocio_id, :producto_id, :precio_anterior, :precio_nuevo, :motivo, :usuario_id)"
    );

    foreach ($validados as $item) {
        $stmtUpdate->execute([
            ':precio_nuevo' => $item['precio_nuevo'],
            ':producto_id' => $item['producto_id'],
            ':negocio_id' => $negocio_id,
        ]);

        $stmtHistorial->execute([
            ':negocio_id' => $negocio_id,
            ':producto_id' => $item['producto_id'],
            ':precio_anterior' => $item['precio_anterior'],
            ':precio_nuevo' => $item['precio_nuevo'],
            ':motivo' => $motivo,
            ':usuario_id' => $usuario_id,
        ]);
    }

    $pdo->commit();

    responder([
        'ok' => true,
        'mensaje' => count($validados) === 1
            ? 'Precio actualizado correctamente'
            : count($validados) . ' precios actualizados correctamente',
        'items' => $validados,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        'ok' => false,
        'mensaje' => 'Error al actualizar los precios',
        'error' => $e->getMessage(),
    ], 500);
}
