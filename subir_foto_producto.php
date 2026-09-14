<?php
/**
 * stockIAte - subir_foto_producto.php
 * ================================
 * Guarda la foto que se sacó con la cámara en la Red de Seguridad como la foto
 * del producto confirmado. Es la que aparece al pasar el mouse por el producto
 * en la tabla de inventario.
 *
 * Es la segunda subida de archivos del proyecto y copia las cuatro barreras de
 * `subir_logo.php` (ver su cabecera): el tipo sale de `getimagesize()`, el
 * nombre lo inventa el servidor, la imagen se RE-ENCODEA con GD y
 * `uploads/.htaccess` apaga la ejecución de código en la carpeta.
 *
 * A diferencia del logo, la pueden subir los tres roles: quien saca la foto es
 * el repositor o el cajero. El `negocio_id` sale de la sesión y el producto se
 * busca con `negocio_id = :negocio_id`, así que no se le puede cambiar la foto
 * a un producto de otro comercio.
 *
 * Se guarda SIEMPRE la última: la foto más reciente es la que mejor muestra
 * cómo es hoy el envase en la góndola.
 *
 * POST multipart: `producto_id` + `foto` (imagen PNG o JPG)
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

$sesion = exigir_sesion();
$negocio_id = $sesion['negocio_id'];

const DIR_FOTOS = __DIR__ . '/uploads/productos';
const URL_FOTOS = 'uploads/productos';

/** Lado mayor de la foto guardada: alcanza y sobra para una vista previa. */
const LADO_MAXIMO_FOTO = 600;
const BYTES_MAXIMO_FOTO = 8 * 1024 * 1024; // 8 MB

cabeceras_json();
exigir_metodo('POST');

$producto_id = (int) ($_POST['producto_id'] ?? 0);
if ($producto_id <= 0) {
    responder(['ok' => false, 'mensaje' => 'Falta el producto'], 400);
}

$archivo = $_FILES['foto'] ?? null;
if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    responder(['ok' => false, 'mensaje' => 'No llegó la foto'], 400);
}

// Sin esto, un POST armado a mano podría apuntar tmp_name a cualquier archivo
// del servidor (por ejemplo .env) y hacérnoslo copiar a una carpeta pública.
if (!is_uploaded_file($archivo['tmp_name'])) {
    responder(['ok' => false, 'mensaje' => 'Subida inválida'], 400);
}

if ($archivo['size'] > BYTES_MAXIMO_FOTO) {
    responder(['ok' => false, 'mensaje' => 'La foto es demasiado grande'], 400);
}

// BARRERA 1: qué es esto realmente, por los bytes.
$info = @getimagesize($archivo['tmp_name']);
if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
    responder(['ok' => false, 'mensaje' => 'La foto tiene que ser JPG o PNG'], 400);
}
[$ancho, $alto] = $info;
if ($ancho < 1 || $alto < 1) {
    responder(['ok' => false, 'mensaje' => 'La foto está vacía'], 400);
}

if (!extension_loaded('gd')) {
    responder([
        'ok' => false,
        'mensaje' => 'Falta la extensión GD de PHP. Activá `extension=gd` en php.ini.',
    ], 500);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, foto FROM productos WHERE id = :id AND negocio_id = :negocio_id"
    );
    $stmt->execute([':id' => $producto_id, ':negocio_id' => $negocio_id]);
    $producto = $stmt->fetch();
} catch (PDOException $e) {
    // Lo más probable: falta correr migracion_productos_foto.sql.
    responder(['ok' => false, 'mensaje' => 'No se pudo leer el producto'], 500);
}

if (!$producto) {
    responder(['ok' => false, 'mensaje' => 'Producto inexistente'], 404);
}

if (!is_dir(DIR_FOTOS) && !@mkdir(DIR_FOTOS, 0755, true)) {
    responder(['ok' => false, 'mensaje' => 'No se pudo crear uploads/productos'], 500);
}
if (!is_writable(DIR_FOTOS)) {
    responder(['ok' => false, 'mensaje' => 'uploads/productos no tiene permiso de escritura'], 500);
}

// BARRERA 3: re-encodear. Sólo sobreviven los píxeles.
$origen = $info[2] === IMAGETYPE_PNG
    ? @imagecreatefrompng($archivo['tmp_name'])
    : @imagecreatefromjpeg($archivo['tmp_name']);
if ($origen === false) {
    responder(['ok' => false, 'mensaje' => 'La foto está dañada'], 400);
}

$escala = min(1, LADO_MAXIMO_FOTO / max($ancho, $alto));
$ancho_final = max(1, (int) round($ancho * $escala));
$alto_final = max(1, (int) round($alto * $escala));

$destino = imagecreatetruecolor($ancho_final, $alto_final);
// Fondo blanco: un PNG con transparencia pasado a JPEG saldría negro.
imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho_final, $alto_final, $ancho, $alto);
imagedestroy($origen);

// BARRERA 2: el nombre lo pone el servidor. El sufijo aleatorio además rompe
// la caché del navegador cuando la foto cambia.
$nombre = sprintf('n%d_p%d_%s.jpg', $negocio_id, $producto_id, bin2hex(random_bytes(6)));
$guardado = imagejpeg($destino, DIR_FOTOS . '/' . $nombre, 82);
imagedestroy($destino);

if (!$guardado) {
    responder(['ok' => false, 'mensaje' => 'No se pudo guardar la foto'], 500);
}

try {
    $pdo->prepare(
        "UPDATE productos SET foto = :foto WHERE id = :id AND negocio_id = :negocio_id"
    )->execute([':foto' => $nombre, ':id' => $producto_id, ':negocio_id' => $negocio_id]);
} catch (PDOException $e) {
    @unlink(DIR_FOTOS . '/' . $nombre);
    responder(['ok' => false, 'mensaje' => 'No se pudo guardar la foto'], 500);
}

// La anterior se borra recién ahora que la nueva quedó. basename() para que
// una fila manipulada no pueda sacar el unlink de la carpeta.
if (!empty($producto['foto'])) {
    $anterior = DIR_FOTOS . '/' . basename($producto['foto']);
    if (is_file($anterior)) {
        @unlink($anterior);
    }
}

responder([
    'ok' => true,
    'foto' => $nombre,
    'url' => URL_FOTOS . '/' . $nombre,
]);
