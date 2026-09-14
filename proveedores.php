<?php
/**
 * stockIAte - proveedores.php (helper compartido)
 * ================================
 * Resolución de proveedor por nombre, compartida entre los endpoints que
 * pueden dar uno de alta al pasar: `crear_producto.php` (alta de producto
 * desde la Red de Seguridad) y `actualizar_costo.php` (carga de costos desde
 * el panel).
 *
 * Vive en su propio archivo y no duplicado en los dos porque la regla que
 * implementa —"el proveedor es por negocio, se reusa si existe y se crea si
 * no"— es exactamente la clase de invariante que no puede desincronizarse
 * entre dos copias: si una de las dos se olvidara del `negocio_id`, un
 * comercio empezaría a ver los proveedores del otro en su <datalist>.
 *
 * No hace `require` de nada: quien lo incluye ya trajo `conexion.php` (vía
 * `sesion.php`) y le pasa el `$pdo`.
 */

/**
 * Resuelve el proveedor por nombre DENTRO DEL NEGOCIO: si ya existe lo reusa,
 * si no lo crea. Devuelve el id, o null si no se mandó proveedor (el campo es
 * opcional).
 *
 * El filtro por negocio es obligatorio: sin él, escribir "Distribuidora Sur"
 * reusaría la fila del otro comercio (y ese nombre volvería en el <datalist>
 * de ambos). El UNIQUE de la tabla es (negocio_id, nombre) justamente para
 * que cada negocio tenga la suya.
 */
function resolverProveedorId(PDO $pdo, ?string $nombreProveedor, int $negocio_id): ?int
{
    if ($nombreProveedor === null || $nombreProveedor === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM proveedores WHERE negocio_id = :negocio_id AND nombre = :nombre LIMIT 1"
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':nombre' => $nombreProveedor]);
    $existente = $stmt->fetchColumn();
    if ($existente !== false) {
        return (int) $existente;
    }

    $insert = $pdo->prepare(
        "INSERT INTO proveedores (negocio_id, nombre) VALUES (:negocio_id, :nombre)"
    );
    $insert->execute([':negocio_id' => $negocio_id, ':nombre' => $nombreProveedor]);
    return (int) $pdo->lastInsertId();
}
