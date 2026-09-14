<?php
/**
 * stockIAte - probar_whatsapp.php
 * ================================
 * Script manual de línea de comandos para verificar que la configuración de
 * WhatsApp anda, sin meter la base de datos ni la app en el medio.
 *
 *   C:\xampp\php\php.exe probar_whatsapp.php 1155554444
 *   C:\xampp\php\php.exe probar_whatsapp.php 1155554444 alerta_stock "Mi Negocio" "Se acabó el Shampoo X" 0 5
 *
 * Sin argumentos extra manda `hello_world`, la plantilla que Meta deja
 * preaprobada en toda cuenta nueva: si ESO no llega, el problema es la
 * configuración de Meta (token, número emisor, destinatario fuera de la lista
 * de prueba) y no hay nada que buscar en el código de la app.
 *
 * Es el equivalente de `smoke_test_roboflow.py` para la otra integración
 * externa: no es un test automatizado, no hay runner. Se corre a mano cuando
 * algo no llega.
 */

require_once __DIR__ . '/whatsapp.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$argumentos = array_slice($argv, 1);

if (count($argumentos) === 0) {
    echo "Uso: php probar_whatsapp.php <telefono> [plantilla] [param1] [param2] ...\n";
    echo "Ejemplo: php probar_whatsapp.php 1155554444\n";
    exit(1);
}

$telefonoCrudo = array_shift($argumentos);
$plantilla = count($argumentos) > 0 ? array_shift($argumentos) : 'hello_world';
$parametros = $argumentos;

// `hello_world` viene en inglés y sin variables; las nuestras son en español.
$idioma = $plantilla === 'hello_world' ? 'en_US' : 'es';

echo "--- Configuración ---\n";
echo "Token:            " . (leer_env('WHATSAPP_TOKEN') !== null ? 'cargado' : 'FALTA en el .env') . "\n";
echo "Phone number ID:  " . (leer_env('WHATSAPP_PHONE_NUMBER_ID') ?? 'FALTA en el .env') . "\n";
echo "Versión API:      " . leer_env('WHATSAPP_API_VERSION', WHATSAPP_API_VERSION_DEFAULT) . "\n";

$telefono = normalizar_telefono($telefonoCrudo);

if ($telefono === null) {
    echo "\nEl teléfono '$telefonoCrudo' no se pudo interpretar.\n";
    echo "Se espera un número argentino de 10 dígitos (área + abonado), con o\n";
    echo "sin 54 / 9 / 15 adelante. Ejemplo: 1155554444\n";
    exit(1);
}

echo "Destino:          $telefonoCrudo -> $telefono\n";
echo "Plantilla:        $plantilla ($idioma)\n";
if (count($parametros) > 0) {
    echo "Parámetros:       " . implode(' | ', $parametros) . "\n";
}

echo "\n--- Enviando ---\n";

$resultado = enviar_plantilla_whatsapp($telefono, $plantilla, $parametros, $idioma);

echo "HTTP:  " . $resultado['http'] . "\n";

if ($resultado['ok']) {
    echo "OK:    mensaje aceptado por Meta.\n";
    echo "wamid: " . $resultado['wamid'] . "\n";
    echo "\nSi no llega al celular, revisá que el número esté en la lista de\n";
    echo "destinatarios de prueba (WhatsApp -> Configuración de la API -> Para).\n";
    exit(0);
}

echo "ERROR: " . $resultado['error'] . "\n";
echo "\nLos errores más comunes:\n";
echo "  * 'Invalid OAuth access token' / código 190 -> el token venció (el\n";
echo "    temporal dura 24 h). Generá uno permanente con un usuario del sistema.\n";
echo "  * 'Template name does not exist' -> la plantilla no está aprobada\n";
echo "    todavía, o el idioma no coincide con el que le pusiste en Meta.\n";
echo "  * 'Recipient phone number not in allowed list' -> con el número de\n";
echo "    prueba sólo se le puede escribir a los 5 destinatarios verificados.\n";
exit(1);
