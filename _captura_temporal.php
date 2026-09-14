<?php
/**
 * ARCHIVO TEMPORAL — sólo para generar las capturas de pantalla del documento
 * de tesis. Recibe un PNG en base64 y lo guarda en docs/capturas/.
 * BORRALO cuando el documento esté listo.
 */
header('Content-Type: application/json; charset=utf-8');

$nombre = isset($_POST['nombre']) ? $_POST['nombre'] : 'captura';
$nombre = preg_replace('/[^A-Za-z0-9_\-]/', '', $nombre);
if ($nombre === '') { $nombre = 'captura'; }

$data = isset($_POST['data']) ? $_POST['data'] : '';
$data = preg_replace('#^data:image/\w+;base64,#', '', $data);
$bin = base64_decode($data, true);

if ($bin === false || strlen($bin) < 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'imagen invalida']);
    exit;
}

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'capturas';
if (!is_dir($dir)) { mkdir($dir, 0777, true); }

$ruta = $dir . DIRECTORY_SEPARATOR . $nombre . '.png';
$bytes = file_put_contents($ruta, $bin);

echo json_encode(['ok' => $bytes !== false, 'bytes' => $bytes, 'ruta' => $ruta]);
