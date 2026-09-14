<?php
/**
 * stockIAte - consultar_proveedores.php
 * ================================
 * Endpoint de solo lectura: devuelve la lista de proveedores cargados, para
 * poblar el <datalist> del formulario de alta de producto en
 * repositor.html / cajero.html (el usuario elige uno existente o escribe uno
 * nuevo, que crear_producto.php da de alta solo).
 *
 * No espera body. Se llama por GET desde el frontend.
 *
 * Los proveedores son POR NEGOCIO: dos comercios pueden comprarle los dos a
 * "Distribuidora Sur" y cada uno tiene su propia fila. Sin este filtro, el
 * <datalist> de un negocio sugeriría los proveedores del otro.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json('GET, OPTIONS');
exigir_metodo('GET');

$negocio_id = exigir_sesion()['negocio_id'];

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre FROM proveedores WHERE negocio_id = :negocio_id ORDER BY nombre ASC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);
    $proveedores = $stmt->fetchAll();

    echo json_encode([
        "ok" => true,
        "proveedores" => $proveedores,
        "total" => count($proveedores),
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al consultar proveedores",
        "error" => $e->getMessage(),
    ], 500);
}
