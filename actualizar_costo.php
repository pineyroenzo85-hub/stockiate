<?php
/**
 * stockIAte - actualizar_costo.php
 * ================================
 * Guarda el precio de costo, el precio de venta y el proveedor de un producto
 * ya existente. Es la contraparte de escritura del bloque "Costos y márgenes"
 * del panel de administrador (`costos_tabla.js`), que lee de
 * `consultar_rentabilidad.php`.
 *
 * Existe porque `crear_producto.php` sólo cubre el alta: los productos que ya
 * estaban en el catálogo (o los que se dieron de alta rápido desde la Red de
 * Seguridad, donde el costo es opcional) no tenían ninguna pantalla para
 * cargarles el costo después. Sin costo, todo lo que responde el chatbot
 * sobre margen y rentabilidad sale parcial.
 *
 * ACTUALIZACIÓN PARCIAL
 * ---------------------
 * Sólo se tocan los campos que vengan en el body: la tabla guarda campo por
 * campo a medida que se sale de cada input, y mandar los tres siempre haría
 * que editar el costo pisara un precio de venta que otra pestaña acaba de
 * cambiar. Se usa `array_key_exists` y no `isset` justamente para poder
 * distinguir "no lo mandé" de "mandé null" (= borrar el costo, que es
 * distinto de 0: 0 significaría "me lo regalaron").
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "precio_costo": 2500,        // opcional; null o "" borra el costo
 *   "precio_venta": 4900,        // opcional
 *   "proveedor": "Distribuidora" // opcional; null o "" desasocia el proveedor
 * }
 *
 * Sólo el rol 'dueño': los precios de costo son la estructura de costos del
 * negocio, igual que en `consultar_rentabilidad.php`.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'proveedores.php'; // resolverProveedorId(), compartido con crear_producto.php

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

if (!isset($body['producto_id'])) {
    responder(["ok" => false, "mensaje" => "Falta producto_id"], 400);
}

$producto_id = (int) $body['producto_id'];

/**
 * Normaliza un precio que llega del formulario. Devuelve un float, null (el
 * campo se vacía) o false si lo que mandaron no es un número válido.
 */
function precioDelBody($valor)
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (!is_numeric($valor)) {
        return false;
    }
    $numero = (float) $valor;
    return $numero < 0 ? false : $numero;
}

$campos = [];
$parametros = [':producto_id' => $producto_id, ':negocio_id' => $negocio_id];

if (array_key_exists('precio_costo', $body)) {
    $precio_costo = precioDelBody($body['precio_costo']);
    if ($precio_costo === false) {
        responder(["ok" => false, "mensaje" => "El precio de costo tiene que ser un número mayor o igual a 0"], 400);
    }
    $campos[] = "precio_costo = :precio_costo";
    $parametros[':precio_costo'] = $precio_costo;
}

if (array_key_exists('precio_venta', $body)) {
    $precio_venta = precioDelBody($body['precio_venta']);
    if ($precio_venta === false) {
        responder(["ok" => false, "mensaje" => "El precio de venta tiene que ser un número mayor o igual a 0"], 400);
    }
    // `precio_venta` es NOT NULL DEFAULT 0 en el schema: vaciar el campo no
    // lo borra, lo pone en 0 (el producto pasa a "sin precio cargado", que es
    // como estaba antes de que existiera esta pantalla).
    $campos[] = "precio_venta = :precio_venta";
    $parametros[':precio_venta'] = $precio_venta ?? 0;
}

$proveedorNombre = null;
$tocaProveedor = array_key_exists('proveedor', $body);
if ($tocaProveedor) {
    $proveedorNombre = is_string($body['proveedor']) ? trim($body['proveedor']) : null;
    if ($proveedorNombre === '') {
        $proveedorNombre = null;
    }
}

if (count($campos) === 0 && !$tocaProveedor) {
    responder(["ok" => false, "mensaje" => "No hay nada para actualizar"], 400);
}

try {
    $pdo->beginTransaction();

    // El producto tiene que ser de ESTE negocio y estar activo: a un
    // archivado no se le cargan costos, no está en la tabla de costos.
    $stmtProducto = $pdo->prepare(
        "SELECT id FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id AND activo = 1 FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);

    if ($stmtProducto->fetchColumn() === false) {
        $pdo->rollBack();
        responder(["ok" => false, "mensaje" => "El producto no existe"], 404);
    }

    // El proveedor se resuelve DENTRO de la transacción: si el UPDATE falla,
    // no queda un proveedor huérfano recién creado.
    if ($tocaProveedor) {
        $campos[] = "proveedor_id = :proveedor_id";
        $parametros[':proveedor_id'] = resolverProveedorId($pdo, $proveedorNombre, $negocio_id);
    }

    $stmt = $pdo->prepare(
        "UPDATE productos SET " . implode(", ", $campos) . "
          WHERE id = :producto_id AND negocio_id = :negocio_id"
    );
    $stmt->execute($parametros);

    // Se relee la fila en vez de devolver lo que mandó el cliente: así el
    // frontend recalcula el margen contra lo que quedó realmente guardado.
    $stmtFinal = $pdo->prepare(
        "SELECT p.id, p.sku, p.nombre, p.marca, p.precio_costo, p.precio_venta,
                p.stock_actual, p.proveedor_id, pr.nombre AS proveedor
           FROM productos p
           LEFT JOIN proveedores pr
                  ON pr.id = p.proveedor_id AND pr.negocio_id = p.negocio_id
          WHERE p.id = :producto_id AND p.negocio_id = :negocio_id"
    );
    $stmtFinal->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
    $producto = $stmtFinal->fetch();

    $pdo->commit();

    $costo = $producto['precio_costo'] !== null ? (float) $producto['precio_costo'] : null;
    $venta = (float) $producto['precio_venta'];

    responder([
        "ok" => true,
        "producto" => [
            "id" => (int) $producto['id'],
            "sku" => $producto['sku'],
            "nombre" => $producto['nombre'],
            "marca" => $producto['marca'],
            "precio_costo" => $costo,
            "precio_venta" => $venta,
            "stock_actual" => (int) $producto['stock_actual'],
            "proveedor_id" => $producto['proveedor_id'] !== null ? (int) $producto['proveedor_id'] : null,
            "proveedor" => $producto['proveedor'],
            "margen_unitario" => $costo !== null ? round($venta - $costo, 2) : null,
            "margen_pct" => ($costo !== null && $venta > 0) ? round(($venta - $costo) / $venta * 100, 1) : null,
            "capital_inmovilizado" => $costo !== null ? round($costo * (int) $producto['stock_actual'], 2) : null,
        ],
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responder([
        "ok" => false,
        "mensaje" => "Error al guardar el costo",
        "error" => $e->getMessage(),
    ], 500);
}
