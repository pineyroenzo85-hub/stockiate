<?php
/**
 * stockIAte - probar_mail.php
 * ================================
 * Script manual de línea de comandos para verificar que el SMTP anda, sin la
 * base de datos ni la app en el medio.
 *
 *   C:\xampp\php\php.exe probar_mail.php enzo@gmail.com
 *
 * Es el equivalente de `probar_whatsapp.php` para la otra integración que
 * sale a internet, y existe por el mismo motivo: si este mail no llega, el
 * problema es la configuración del `.env` y no hay nada que buscar en
 * solicitar_reset.php.
 *
 * Hace falta porque el endpoint real NO PUEDE decirte qué pasó: responde lo
 * mismo exista o no la cuenta, y falle o no el envío, para no ser un
 * enumerador de cuentas (ver el comentario largo de solicitar_reset.php). Ese
 * silencio es correcto de cara a internet y malísimo para diagnosticar, así
 * que el diagnóstico vive acá.
 *
 * Sin argumentos sólo muestra la configuración y no manda nada.
 */

require_once __DIR__ . '/mailer.php';

// Igual que tareas_notificaciones.php: por HTTP no existe. No expone secretos
// (imprime "cargada", no la contraseña), pero es un botón de mandar mails.
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

echo "--- Configuración SMTP ---\n";
echo "Host:       " . (leer_env('SMTP_HOST') ?? 'FALTA en el .env') . "\n";
echo "Puerto:     " . leer_env('SMTP_PUERTO', (string) MAILER_PUERTO_DEFAULT) . "\n";
echo "Seguridad:  " . leer_env('SMTP_SEGURIDAD', 'tls') . "\n";
echo "Usuario:    " . (leer_env('SMTP_USUARIO') ?? 'FALTA en el .env') . "\n";
echo "Password:   " . (leer_env('SMTP_PASSWORD') !== null ? 'cargada' : 'FALTA en el .env') . "\n";

[$desde, $desdeNombre] = mail_remitente();
echo "Desde:      $desdeNombre <$desde>\n";
echo "URL base:   " . url_base_app_cli() . "\n";

if (!mail_configurado()) {
    echo "\nFalta configurar el SMTP. Copiá el bloque de .env.example al .env.\n";
    exit(1);
}

$destino = $argv[1] ?? null;

if ($destino === null) {
    echo "\nPara mandar un mail de prueba:\n";
    echo "  php probar_mail.php tu-direccion@ejemplo.com\n";
    exit(0);
}

echo "\n--- Enviando a $destino ---\n";

$momento = date('d/m/Y H:i:s');
$resultado = enviar_mail(
    $destino,
    'Prueba',
    'Prueba de stockIAte',
    '<p>Si estás leyendo esto, el SMTP de stockIAte funciona.</p>'
    . "<p style=\"color:#6b7280;font-size:13px;\">Enviado el $momento.</p>",
    "Si estás leyendo esto, el SMTP de stockIAte funciona.\nEnviado el $momento.\n"
);

if ($resultado['ok']) {
    echo "OK: el servidor SMTP aceptó el mensaje.\n";
    echo "\nSi no aparece en la bandeja de entrada, mirá el spam: un dominio\n";
    echo "sin SPF/DKIM propio (o sea, cualquier prueba local) suele caer ahí.\n";
    exit(0);
}

echo "ERROR: " . $resultado['error'] . "\n";
echo "\nLos errores más comunes:\n";
echo "  * '535 Username and Password not accepted' -> con Gmail hay que usar\n";
echo "    una CONTRASEÑA DE APLICACIÓN, no la de la cuenta. Se genera en\n";
echo "    https://myaccount.google.com/apppasswords y necesita la\n";
echo "    verificación en dos pasos activada.\n";
echo "  * Timeout sin más -> el puerto y el modo no coinciden: 587 va con\n";
echo "    SMTP_SEGURIDAD=tls y 465 con ssl.\n";
echo "  * 'Could not connect to SMTP host' -> extensión openssl apagada en\n";
echo "    php.ini, o el firewall bloqueando la salida al puerto.\n";
exit(1);

/**
 * `url_base_app()` (reset_password.php) deriva del request cuando no hay
 * APP_BASE_URL, y en CLI no hay request. Se muestra sólo a título informativo,
 * así que basta con decir de dónde saldría el link.
 */
function url_base_app_cli(): string
{
    $config = leer_env('APP_BASE_URL');
    return $config !== null
        ? rtrim($config, '/')
        : '(sin APP_BASE_URL: se deriva del request; en CLI no aplica)';
}
