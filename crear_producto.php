<?php
/**
 * stockIAte - crear_producto.php
 * ================================
 * Da de alta un producto nuevo en el catálogo. Lo usa la pantalla de
 * validación (Red de Seguridad) de repositor.html/cajero.html cuando la
 * IA detectó algo (por OCR o clase genérica) que no matchea con ningún
 * producto existente -- el repositor/cajero confirma nombre/marca/proveedor/
 * precios ahí mismo y el producto queda disponible para seleccionar.
 *
 * Espera un body tipo:
 * {
 *   "nombre": "Rexona Hidra Protección Intensiva",
 *   "marca": "Rexona",                 // opcional
 *   "sku": "REX-001",                  // opcional: si falta, se genera solo
 *   "sku_auto": true,                  // el SKU lo sugirió el frontend, no el usuario
 *   "precio_venta": 12000,             // opcional, default 0
 *   "precio_costo": 8000,              // opcional, puede venir null
 *   "proveedor": "Distribuidora Sur"   // opcional: se crea si no existe
 * }
 *
 * SKU: nunca se pisan dos productos. Si el SKU vino vacío o marcado como
 * automático (`sku_auto`), el servidor genera uno único con formato
 * PREFIJO-NNN (prefijo derivado de la marca, o del nombre si no hay marca)
 * y reintenta con el siguiente número si otro proceso ganó la carrera. Si el
 * usuario escribió el SKU a mano y ya existe, se responde 409 en vez de
 * cambiárselo por atrás.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'proveedores.php'; // resolverProveedorId(), compartido con actualizar_costo.php
require_once 'configuracion.php'; // leer_config_int() -> stock_minimo_default

cabeceras_json();
exigir_metodo('POST');

// Repositor y cajero dan de alta productos desde la Red de Seguridad, y el
// dueño entra a cualquier módulo: los tres roles pueden crear.
$sesion = exigir_sesion();
$negocio_id = $sesion['negocio_id'];

/**
 * Prefijo del SKU: hasta 3 caracteres alfanuméricos en mayúscula, sin
 * acentos. Si no queda nada usable (ej. el OCR leyó solo símbolos), cae a
 * "PRD".
 */
function prefijoSku(string $base): string
{
    $sinAcentos = strtr($base, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
    $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sinAcentos));
    $prefijo = substr($limpio, 0, 3);
    return strlen($prefijo) >= 2 ? $prefijo : 'PRD';
}

/**
 * Genera el próximo SKU libre para ese prefijo: mira los SKU existentes con
 * formato PREFIJO-NNN y devuelve el máximo + 1, con 3 dígitos.
 *
 * El scan se acota al negocio, igual que el UNIQUE (negocio_id, sku): cada
 * comercio numera su catálogo desde cero y dos negocios pueden tener cada uno
 * su PER-001. Si el scan fuera global, un negocio "reservaría" números del
 * otro y los huecos en la numeración delatarían cuántos productos de esa
 * marca tiene el vecino.
 */
function generarSku(PDO $pdo, string $base, int $negocio_id): string
{
    $prefijo = prefijoSku($base);

    // Sin filtro de `activo` a propósito: el SKU de un producto archivado
    // SIGUE OCUPADO (el UNIQUE (negocio_id, sku) aplica igual). Si acá se
    // ignoraran los archivados, restaurar uno podría chocar contra otro
    // creado mientras tanto y el UPDATE fallaría con un error feo.
    $stmt = $pdo->prepare(
        "SELECT sku FROM productos WHERE negocio_id = :negocio_id AND sku LIKE :patron"
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':patron' => $prefijo . '-%']);

    $maximo = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $skuExistente) {
        // Case-insensitive como la constraint UNIQUE de MySQL (collation ci):
        // si ya existe "rex-11", el próximo tiene que ser REX-012, no REX-001.
        if (preg_match('/^' . preg_quote($prefijo, '/') . '-(\d+)$/i', $skuExistente, $m)) {
            $maximo = max($maximo, (int) $m[1]);
        }
    }

    return sprintf('%s-%03d', $prefijo, $maximo + 1);
}

$body = cuerpo_json();

if (!isset($body['nombre'])) {
    responder(["ok" => false, "mensaje" => "Faltan campos obligatorios"], 400);
}

$nombre = trim($body['nombre']);
$marca = !empty($body['marca']) ? trim($body['marca']) : null;
$precio_venta = isset($body['precio_venta']) ? (float) $body['precio_venta'] : 0;
// Precio de costo: opcional de verdad. Sin valor se guarda NULL (distinto de
// 0, que significaría "me lo regalaron").
$precio_costo = (isset($body['precio_costo']) && $body['precio_costo'] !== '' && $body['precio_costo'] !== null)
    ? (float) $body['precio_costo']
    : null;
$proveedorNombre = !empty($body['proveedor']) ? trim($body['proveedor']) : null;

if ($nombre === '') {
    responder(["ok" => false, "mensaje" => "El nombre no puede estar vacío"], 400);
}

$skuPedido = isset($body['sku']) ? trim((string) $body['sku']) : '';
// El SKU lo genera el servidor salvo que el usuario lo haya escrito a mano.
$skuAutomatico = $skuPedido === '' || !empty($body['sku_auto']);
$sku = $skuAutomatico ? generarSku($pdo, $marca ?: $nombre, $negocio_id) : $skuPedido;

try {
    $proveedor_id = resolverProveedorId($pdo, $proveedorNombre, $negocio_id);

    // Antes se dejaba caer en el DEFAULT 5 de la columna, que es invisible
    // desde la app: el dueño no tenía forma de decir "para mi negocio, poco
    // stock son 3". Ahora sale de `configuracion` (y su default sigue siendo
    // 5, así que un negocio que no lo tocó se comporta igual que antes).
    $stock_minimo = leer_config_int($pdo, $negocio_id, 'stock_minimo_default');

    $stmt = $pdo->prepare(
        "INSERT INTO productos (negocio_id, sku, nombre, marca, precio_venta, precio_costo, proveedor_id, stock_minimo)
         VALUES (:negocio_id, :sku, :nombre, :marca, :precio_venta, :precio_costo, :proveedor_id, :stock_minimo)"
    );

    // Reintentos solo para el SKU automático: si entre el SELECT y el INSERT
    // otra carga se quedó con ese número, generamos el siguiente.
    $intentos = 0;
    while (true) {
        try {
            $stmt->execute([
                ':negocio_id' => $negocio_id,
                ':sku' => $sku,
                ':nombre' => $nombre,
                ':marca' => $marca,
                ':precio_venta' => $precio_venta,
                ':precio_costo' => $precio_costo,
                ':proveedor_id' => $proveedor_id,
                ':stock_minimo' => $stock_minimo,
            ]);
            break;
        } catch (PDOException $e) {
            // El SQLSTATE 23000 abarca varias cosas distintas. Antes se
            // asumía que siempre era "el SKU ya existe", pero con las FK
            // compuestas del modelo multi-tenant también lo tira una FK que
            // no cierra (1452). Reintentar el SKU en ese caso serían 5
            // vueltas al pedo antes de un 500 confuso, así que se discrimina
            // por el código de error de MySQL:
            //   1062 = duplicate entry  -> tiene sentido regenerar el SKU
            //   1452 = FK constraint    -> no, es otro problema
            $codigoMysql = $e->errorInfo[1] ?? null;
            if ($codigoMysql !== 1062) {
                throw $e;
            }
            if (!$skuAutomatico) {
                responder(["ok" => false, "mensaje" => "Ya existe un producto con ese SKU"], 409);
            }
            if (++$intentos >= 5) {
                throw $e;
            }
            $sku = generarSku($pdo, $marca ?: $nombre, $negocio_id);
        }
    }

    $id = (int) $pdo->lastInsertId();

    responder([
        "ok" => true,
        "producto" => [
            "id" => $id,
            "sku" => $sku,
            "nombre" => $nombre,
            "marca" => $marca,
            "precio_venta" => $precio_venta,
            "precio_costo" => $precio_costo,
            "proveedor_id" => $proveedor_id,
            "proveedor" => $proveedorNombre,
            "stock_actual" => 0,
            "stock_minimo" => $stock_minimo,
        ],
    ]);
} catch (PDOException $e) {
    responder([
        "ok" => false,
        "mensaje" => "Error al crear el producto",
        "error" => $e->getMessage(),
    ], 500);
}
