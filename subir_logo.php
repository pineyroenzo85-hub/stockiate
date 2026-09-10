<?php
/**
 * stockIAte - subir_logo.php
 * ================================
 * El logo del negocio, que se imprime en los carteles de góndola.
 *
 * ES LA PRIMERA Y ÚNICA SUBIDA DE ARCHIVOS DEL PROYECTO
 * -----------------------------------------------------
 * Hasta acá, ningún endpoint tocaba `$_FILES`: las fotos de la Red de
 * Seguridad se le mandan al servicio Python y no se guardan en ningún lado.
 * Por eso este archivo es más paranoico que sus vecinos: una carpeta donde un
 * usuario puede dejar archivos y el servidor después los sirve es el camino
 * más corto que existe entre "subí una imagen" y "me tomaron el servidor".
 *
 * LAS CUATRO BARRERAS, EN ORDEN
 * -----------------------------
 * 1. **El tipo se saca del contenido, no de la extensión ni del
 *    `Content-Type`.** Los dos últimos los elige quien sube el archivo, así
 *    que no prueban nada: `getimagesize()` lee los bytes de verdad.
 * 2. **El nombre lo inventa el servidor.** Nunca se usa el nombre original,
 *    ni siquiera "saneado": es la vía de los `logo.php.png`, los `..%2f` y los
 *    nombres con caracteres que el sistema de archivos interpreta distinto que
 *    PHP. Se guarda como `negocio<id>_<hash>.png` y listo.
 * 3. **La imagen se RE-ENCODEA con GD.** Un PNG válido puede llevar PHP
 *    escondido en un chunk de metadatos y seguir pasando `getimagesize()`.
 *    Al redibujarlo sobre un lienzo nuevo sobrevive únicamente lo que es
 *    píxeles. De paso se achica: un logo de 3 MB no aporta nada a un cartel.
 * 4. **`uploads/.htaccess`** apaga la ejecución de código en esa carpeta.
 *    Es la red por si alguna de las tres de arriba falla o alguien la toca.
 *
 * Sólo el rol 'dueño'. El `negocio_id` sale de la sesión, nunca del body: es
 * lo que impide subirle un logo al comercio de al lado.
 *
 * POST multipart con el campo `logo`  -> guarda y devuelve el nombre y la URL
 * POST JSON `{"borrar": true}`        -> saca el logo actual
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php';

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];

/** Dónde viven los archivos, y bajo qué URL los sirve Apache. */
const DIR_LOGOS = __DIR__ . '/uploads/logos';
const URL_LOGOS = 'uploads/logos';

/** Lado mayor del logo ya guardado. Más que esto no se ve en un cartel. */
const LADO_MAXIMO_LOGO = 600;

/** Tope de lo que se acepta recibir, antes de mirar siquiera qué es. */
const BYTES_MAXIMO_LOGO = 3 * 1024 * 1024;   // 3 MB

/**
 * Formatos aceptados. WebP queda afuera a propósito: lo soportan los
 * navegadores pero no todas las instalaciones de GD, y un logo que se ve en
 * pantalla y desaparece al imprimir sería peor que rechazarlo de entrada.
 */
const TIPOS_LOGO = [
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_JPEG => 'jpg',
];

/** Borra el logo anterior del disco, si había uno. */
function borrar_logo_anterior(PDO $pdo, int $negocio_id): void
{
    $actual = leer_configs($pdo, $negocio_id, ['cartel_logo'])['cartel_logo'] ?? '';

    if ($actual === '') {
        return;
    }

    // `basename()` y no la ruta guardada tal cual: aunque el nombre lo escriba
    // este mismo archivo, si alguien alguna vez mete un `../..` en esa fila de
    // `configuracion`, el unlink no puede salirse de la carpeta.
    $ruta = DIR_LOGOS . '/' . basename($actual);

    if (is_file($ruta)) {
        @unlink($ruta);
    }
}

// ------------------------------------------------------------------
// Sacar el logo
// ------------------------------------------------------------------
// Va antes que todo lo demás porque llega como JSON, no como multipart, y
// `cuerpo_json()` necesita leer el body crudo.
if (($_SERVER['CONTENT_TYPE'] ?? '') !== '' && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    cabeceras_json();
    exigir_metodo('POST');

    $body = cuerpo_json();

    if (!empty($body['borrar'])) {
        borrar_logo_anterior($pdo, $negocio_id);
        guardar_config($pdo, $negocio_id, 'cartel_logo', '');

        responder(['ok' => true, 'mensaje' => 'Logo eliminado', 'logo' => '', 'url' => null]);
    }

    responder(['ok' => false, 'mensaje' => 'No se mandó ninguna imagen'], 400);
}

// ------------------------------------------------------------------
// Subir el logo
// ------------------------------------------------------------------
cabeceras_json();
exigir_metodo('POST');

if (!isset($_FILES['logo'])) {
    responder(['ok' => false, 'mensaje' => 'No se mandó ninguna imagen'], 400);
}

$archivo = $_FILES['logo'];

// Los errores de PHP tienen mensajes propios que le dicen a la persona qué
// hacer. `UPLOAD_ERR_INI_SIZE` es el más común y el más confuso: el archivo
// no llegó nunca, así que sin este mensaje la pantalla diría "no se mandó
// ninguna imagen" sobre una imagen que sí se eligió.
if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $mensajes = [
        UPLOAD_ERR_INI_SIZE => 'La imagen es más grande que lo que acepta el servidor (revisá upload_max_filesize en php.ini).',
        UPLOAD_ERR_FORM_SIZE => 'La imagen es demasiado grande.',
        UPLOAD_ERR_PARTIAL => 'La imagen se subió incompleta. Probá de nuevo.',
        UPLOAD_ERR_NO_FILE => 'No se mandó ninguna imagen.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
    ];

    responder([
        'ok' => false,
        'mensaje' => $mensajes[$archivo['error']] ?? 'No se pudo subir la imagen.',
    ], 400);
}

// Sin esto, un POST armado a mano podría apuntar `tmp_name` a cualquier
// archivo del servidor (por ejemplo `.env`) y hacérnoslo copiar a una carpeta
// pública. Es la razón por la que esta función existe.
if (!is_uploaded_file($archivo['tmp_name'])) {
    responder(['ok' => false, 'mensaje' => 'Subida inválida'], 400);
}

if ($archivo['size'] > BYTES_MAXIMO_LOGO) {
    responder([
        'ok' => false,
        'mensaje' => 'La imagen no puede pesar más de ' . (BYTES_MAXIMO_LOGO / 1024 / 1024) . ' MB.',
    ], 400);
}

// BARRERA 1: qué es esto REALMENTE. No la extensión, no el Content-Type: los
// bytes. `getimagesize()` devuelve false para cualquier cosa que no sea una
// imagen que PHP pueda abrir.
$info = @getimagesize($archivo['tmp_name']);

if ($info === false || !isset(TIPOS_LOGO[$info[2]])) {
    responder([
        'ok' => false,
        'mensaje' => 'El archivo tiene que ser una imagen PNG o JPG.',
    ], 400);
}

[$ancho, $alto] = $info;
$tipo = $info[2];

if ($ancho < 1 || $alto < 1) {
    responder(['ok' => false, 'mensaje' => 'La imagen está vacía.'], 400);
}

if (!extension_loaded('gd')) {
    responder([
        'ok' => false,
        'mensaje' => 'El servidor no tiene la extensión GD de PHP habilitada, así que no puede procesar la imagen. Activá `extension=gd` en php.ini.',
    ], 500);
}

if (!is_dir(DIR_LOGOS) && !@mkdir(DIR_LOGOS, 0755, true)) {
    responder([
        'ok' => false,
        'mensaje' => 'No existe la carpeta uploads/logos y no se pudo crear.',
    ], 500);
}

if (!is_writable(DIR_LOGOS)) {
    responder([
        'ok' => false,
        'mensaje' => 'La carpeta uploads/logos no tiene permiso de escritura. En Linux: chown www-data uploads/logos.',
    ], 500);
}

// BARRERA 3: re-encodear. Se abre la imagen, se dibuja sobre un lienzo nuevo y
// se guarda ese lienzo. Lo único que sobrevive son los píxeles: cualquier cosa
// escondida en los metadatos queda afuera.
$origen = $tipo === IMAGETYPE_PNG
    ? @imagecreatefrompng($archivo['tmp_name'])
    : @imagecreatefromjpeg($archivo['tmp_name']);

if ($origen === false) {
    responder(['ok' => false, 'mensaje' => 'La imagen está dañada o no se pudo leer.'], 400);
}

$escala = min(1, LADO_MAXIMO_LOGO / max($ancho, $alto));
$ancho_final = max(1, (int) round($ancho * $escala));
$alto_final = max(1, (int) round($alto * $escala));

$destino = imagecreatetruecolor($ancho_final, $alto_final);

// La transparencia se preserva a mano: `imagecreatetruecolor()` nace con un
// fondo negro opaco, y un logo PNG con fondo transparente saldría con un
// rectángulo negro alrededor justo al imprimirlo sobre papel blanco.
imagealphablending($destino, false);
imagesavealpha($destino, true);
imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
imagealphablending($destino, true);

imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho_final, $alto_final, $ancho, $alto);
imagedestroy($origen);

// BARRERA 2: el nombre lo pone el servidor. El sufijo aleatorio no es
// seguridad —la carpeta no se puede listar— sino cache-busting: sin él, el
// navegador seguiría mostrando el logo viejo después de cambiarlo, porque la
// URL sería la misma.
$nombre = sprintf('negocio%d_%s.png', $negocio_id, bin2hex(random_bytes(6)));
$ruta_final = DIR_LOGOS . '/' . $nombre;

// Siempre PNG, venga lo que venga: un solo formato de salida es un caso menos
// en todo lo que viene después, y el PNG conserva la transparencia de los
// logos, que es lo que hace que se vean bien sobre el papel.
$guardado = imagepng($destino, $ruta_final, 6);
imagedestroy($destino);

if (!$guardado) {
    responder(['ok' => false, 'mensaje' => 'No se pudo guardar la imagen en el servidor.'], 500);
}

// El anterior se borra recién ahora: si algo falla más arriba, el negocio se
// queda con el logo que ya tenía en vez de quedarse sin ninguno.
borrar_logo_anterior($pdo, $negocio_id);
guardar_config($pdo, $negocio_id, 'cartel_logo', $nombre);

responder([
    'ok' => true,
    'mensaje' => 'Logo actualizado',
    'logo' => $nombre,
    'url' => URL_LOGOS . '/' . $nombre,
    'ancho' => $ancho_final,
    'alto' => $alto_final,
]);
