<?php
/**
 * stockIAte - archivar_producto.php
 * ================================
 * Da de baja (o vuelve a dar de alta) un producto del catálogo. Es el botón
 * 📦 de la tabla de inventario del panel de administrador.
 *
 * REEMPLAZA A eliminar_producto.php, QUE BORRABA DE VERDAD
 * -------------------------------------------------------
 * Aquel endpoint casi nunca podía hacer su trabajo: `ventas`, `lotes_stock` y
 * `correcciones_ia` tienen FK a `productos` sin ON DELETE CASCADE (a
 * propósito: borrar en cascada destruiría registros de ventas reales), así
 * que cualquier producto con historial hacía fallar el DELETE por integridad
 * referencial. Sumado a que el frontend además exigía `stock_actual = 0`,
 * sobre una base con 36 productos había exactamente 1 borrable.
 *
 * Ahora es una baja lógica: `productos.activo = 0`. El producto desaparece
 * del panel, de los <select> de repositor/cajero y de las herramientas del
 * chatbot, pero sus ventas viejas siguen sumando en los reportes
 * (`consultar_ventas.php` es el único que NO filtra por `activo`, justamente
 * para no borrarle facturación al historial).
 *
 * SE PUEDE ARCHIVAR CON STOCK > 0
 * -------------------------------
 * Exigir stock en cero dejaba 26 de 36 productos intocables, obligando a
 * bajarlos a mano de a una unidad. El aviso de que quedan N unidades sin
 * contar lo da el `confirm()` del frontend; acá se devuelve
 * `stock_al_archivar` para que ese aviso diga la verdad. El stock NO se
 * pone en cero: si se restaura el producto, vuelve con lo que tenía.
 *
 * Espera un body tipo:
 * {
 *   "producto_id": 12,
 *   "restaurar": false   // opcional; true vuelve a activarlo
 * }
 *
 * Sólo el rol 'dueño' (Administrador en la UI): es el admin de su negocio.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

$body = cuerpo_json();

if (!isset($body['producto_id'])) {
    responder(["ok" => false, "mensaje" => "Falta producto_id"], 400);
}

$producto_id = (int) $body['producto_id'];
$restaurar = !empty($body['restaurar']);

try {
    $pdo->beginTransaction();

    // El `negocio_id` acá es lo que impide archivar el producto de otro
    // comercio: un id ajeno no matchea y cae en el 404 de abajo.
    $stmtProducto = $pdo->prepare(
        "SELECT id, nombre, stock_actual, activo FROM productos
          WHERE id = :producto_id AND negocio_id = :negocio_id FOR UPDATE"
    );
    $stmtProducto->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        $pdo->rollBack();
        responder(["ok" => false, "mensaje" => "El producto no existe"], 404);
    }

    $yaEstaba = ((int) $producto['activo'] === 1) === $restaurar;

    if ($restaurar) {
        $stmt = $pdo->prepare(
            "UPDATE productos SET activo = 1, archivado_en = NULL
              WHERE id = :producto_id AND negocio_id = :negocio_id"
        );
    } else {
        $stmt = $pdo->prepare(
            "UPDATE productos SET activo = 0, archivado_en = NOW()
              WHERE id = :producto_id AND negocio_id = :negocio_id"
        );
    }
    $stmt->execute([':producto_id' => $producto_id, ':negocio_id' => $negocio_id]);

    $pdo->commit();

    responder([
        "ok" => true,
        "producto_id" => $producto_id,
        "nombre" => $producto['nombre'],
        "activo" => $restaurar,
        // Cuántas unidades dejan de contarse en el inventario. El frontend ya
        // lo avisó en el confirm; esto es para el toast de después y para que
        // quede en la respuesta si alguien le pega al endpoint directo.
        "stock_al_archivar" => (int) $producto['stock_actual'],
        "ya_estaba" => $yaEstaba,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responder([
        "ok" => false,
        "mensaje" => $restaurar ? "Error al restaurar el producto" : "Error al archivar el producto",
        "error" => $e->getMessage(),
    ], 500);
}
