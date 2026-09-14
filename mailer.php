<?php
/**
 * stockIAte - mailer.php
 * ================================
 * El único archivo del proyecto que manda mails. Envuelve a PHPMailer
 * (vendor/phpmailer/) y expone dos funciones: si está configurado, y mandar.
 *
 * POR QUÉ HAY UN MAILER AHORA
 * ---------------------------
 * Hasta acá el proyecto no mandaba mails a propósito: las invitaciones se
 * copian a mano justamente porque no había mailer (lo dice
 * crear_invitacion.php en su comentario). Eso funciona para invitar, porque
 * siempre hay un dueño del otro lado que puede pasar el link.
 *
 * Para recuperar una contraseña no funciona: el caso que hay que cubrir es
 * justamente el del dueño, que es el único que no tiene a nadie arriba que le
 * genere el link. Y el mail es lo único que prueba que quien pide el reseteo
 * es el titular de la cuenta.
 *
 * POR QUÉ PHPMailer Y NO mail()
 * -----------------------------
 * `mail()` de PHP en XAMPP/Windows no manda nada sin un servidor SMTP local
 * configurado en php.ini, y cuando "anda" entrega a spam porque arma los
 * headers a mano y sin autenticar. PHPMailer habla SMTP autenticado con TLS
 * contra un proveedor real (Gmail, Brevo, el que sea), que es lo que hace que
 * el mail llegue a la bandeja de entrada.
 *
 * POR QUÉ NO HAY COMPOSER
 * -----------------------
 * El proyecto no usa Composer y no vale la pena introducirlo por una
 * dependencia. PHPMailer son tres archivos sin dependencias propias, y se
 * cargan con `require_once` igual que jsPDF y Chart.js se sirven desde
 * `vendor/` en vez de un CDN: la misma decisión, que el día de la defensa
 * nada dependa de bajar algo.
 *
 * NINGUNA FUNCIÓN DE ACÁ LANZA. Devuelven un array con `ok` y `error`, mismo
 * criterio que whatsapp.php: quien llama tiene que poder guardar el error y
 * seguir, no cortarse.
 */

require_once __DIR__ . '/env.php';

/** Timeout de la conexión SMTP, en segundos. */
const MAILER_TIMEOUT = 15;

/** Puerto por defecto: 587 es SMTP con STARTTLS, el que usan casi todos. */
const MAILER_PUERTO_DEFAULT = 587;

/**
 * ¿Está configurado el envío? Sin esto no se puede mandar nada, y quien
 * pregunta (solicitar_reset.php, probar_mail.php) tiene que poder decirlo con
 * un mensaje claro en vez de fallar con un error de SMTP.
 */
function mail_configurado(): bool
{
    return leer_env('SMTP_HOST') !== null
        && leer_env('SMTP_USUARIO') !== null
        && leer_env('SMTP_PASSWORD') !== null;
}

/**
 * Dirección desde la que sale el mail. Cae al usuario SMTP, que es lo
 * correcto en Gmail: mandar con un `From` distinto de la cuenta autenticada
 * hace que el proveedor lo reescriba o lo rechace.
 */
function mail_remitente(): array
{
    return [
        leer_env('SMTP_DESDE') ?? (string) leer_env('SMTP_USUARIO', ''),
        leer_env('SMTP_DESDE_NOMBRE') ?? 'stockIAte',
    ];
}

/**
 * Manda un mail. Devuelve `['ok' => bool, 'error' => ?string]`.
 *
 * Siempre va en multipart: `$html` para los clientes que lo soportan y
 * `$texto` como alternativa. No es un detalle estético — un mail sólo-HTML
 * suma puntos de spam en casi todos los filtros, y el link de reseteo tiene
 * que llegar sí o sí a la bandeja de entrada.
 */
function enviar_mail(string $para, string $paraNombre, string $asunto, string $html, string $texto): array
{
    if (!mail_configurado()) {
        return ['ok' => false, 'error' => 'SMTP sin configurar (faltan SMTP_HOST / SMTP_USUARIO / SMTP_PASSWORD en el .env)'];
    }

    require_once __DIR__ . '/vendor/phpmailer/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

    [$desde, $desdeNombre] = mail_remitente();

    $mail = new PHPMailer\PHPMailer\PHPMailer(true); // true = lanza excepciones

    try {
        $mail->isSMTP();
        $mail->Host = (string) leer_env('SMTP_HOST');
        $mail->Port = (int) leer_env('SMTP_PUERTO', (string) MAILER_PUERTO_DEFAULT);
        $mail->SMTPAuth = true;
        $mail->Username = (string) leer_env('SMTP_USUARIO');
        $mail->Password = (string) leer_env('SMTP_PASSWORD');
        $mail->Timeout = MAILER_TIMEOUT;
        $mail->CharSet = 'UTF-8';

        // 465 es SMTPS (TLS desde el primer byte); 587 es STARTTLS (arranca en
        // claro y sube a TLS). Poner el modo que no corresponde al puerto es
        // el error de configuración más común y da un timeout mudo.
        $seguridad = strtolower((string) leer_env('SMTP_SEGURIDAD', 'tls'));
        $mail->SMTPSecure = $seguridad === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($desde, $desdeNombre);
        $mail->addAddress($para, $paraNombre);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $html;
        $mail->AltBody = $texto;

        $mail->send();

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        // `ErrorInfo` trae el diálogo SMTP real ("535 Username and Password
        // not accepted"), que es mucho más útil que el mensaje de la
        // excepción. Si está vacío se cae al mensaje.
        $detalle = trim((string) $mail->ErrorInfo);
        return ['ok' => false, 'error' => $detalle !== '' ? $detalle : $e->getMessage()];
    }
}
