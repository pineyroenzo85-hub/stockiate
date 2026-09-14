<?php
/**
 * stockIAte - reset_password.php
 * ================================
 * Lo compartido entre las tres piezas del reseteo de contraseña:
 * solicitar_reset.php (pide el link), info_reset.php (valida el token antes
 * de mostrar el formulario) y restablecer_password.php (lo canjea).
 *
 * Acá no hay endpoints: son constantes y funciones. Se puso aparte de
 * sesion.php a propósito — sesion.php lo requiere TODO el proyecto, y esto lo
 * necesitan tres archivos.
 */

require_once __DIR__ . '/env.php';

/**
 * Cuánto vive un token de reseteo, en minutos.
 *
 * Una hora, no los 7 días de una invitación, y la diferencia es deliberada:
 * una invitación es para una cuenta que todavía no existe y se manda por
 * WhatsApp o en persona, así que tiene que aguantar el fin de semana. Un
 * token de reseteo abre una cuenta con datos reales adentro y vive en una
 * casilla de mail, que es exactamente el lugar donde queda para siempre.
 */
const RESET_VIGENCIA_MINUTOS = 60;

/**
 * Cuántos reseteos se pueden pedir por cuenta por hora.
 *
 * Sin esto, el formulario de "olvidé mi contraseña" es un botón para
 * bombardear la casilla de cualquier usuario del sistema: se escribe su email
 * y se aprieta enviar cien veces. El límite es por CUENTA y no por IP porque
 * lo que se protege es la casilla de la víctima, y quien la bombardea puede
 * cambiar de IP pero no de destinatario.
 */
const RESET_MAX_POR_HORA = 3;

/**
 * Genera el token que viaja en el link. 32 bytes de `random_bytes` en hex.
 *
 * Es más largo que el UUID de `generar_token_invitacion()` (16 bytes) porque
 * acá el token es lo único que separa a cualquiera de una cuenta existente, y
 * porque no tiene que parecerse a un UUID: nadie lo lee, se hace clic.
 */
function generar_token_reset(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Lo que se guarda en la base. Ver el comentario largo de la tabla
 * `password_resets` en schema.sql: la tabla guarda el hash para no ser un
 * llavero de cuentas si alguien la lee.
 *
 * SHA-256 pelado y no `password_hash()`: el token ya tiene 256 bits de
 * entropía criptográfica, así que no hay nada que un hash lento proteja (no
 * se le puede hacer fuerza bruta a 2^256), y en cambio necesitamos buscarlo
 * por índice — con `password_hash()` habría que traer todas las filas y
 * probar una por una.
 */
function hash_token_reset(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Base de la aplicación, para armar el link que va en el mail.
 *
 * SALE DEL `.env` Y NO DEL HEADER `Host`, y eso importa: el `Host` lo elige
 * quien manda el request. Derivar el link de ahí es el agujero clásico de los
 * reseteos por mail — se pide el reseteo de la cuenta de otro con un `Host`
 * propio, y a la víctima le llega un mail legítimo del sistema cuyo link
 * apunta al servidor del atacante, que se queda con el token.
 *
 * El fallback derivado del request existe sólo para el desarrollo local (una
 * instalación de XAMPP donde el host es siempre localhost). En cualquier
 * despliegue de verdad, y con el túnel de ngrok levantado, APP_BASE_URL va en
 * el `.env`.
 */
function url_base_app(): string
{
    $config = leer_env('APP_BASE_URL');
    if ($config !== null) {
        $base = rtrim($config, '/');

        // Es la BASE (la carpeta), no una página. La confusión es natural:
        // "poné la URL de la app" y lo que uno tiene a mano es lo que dice la
        // barra del navegador, que termina en `/olvide_password.html`. Pasó:
        // el link salía como `.../olvide_password.html/recuperar.html?token=`
        // y daba un `Not Found` de Apache, sin ninguna pista de qué estaba mal
        // y con el mail ya mandado.
        //
        // Recortar el archivo final no puede romper un valor correcto: una
        // carpeta base nunca termina en `.html`.
        if (preg_match('/\.html?$/i', $base)) {
            $base = rtrim(dirname($base), '/');
        }

        return $base;
    }

    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return "$esquema://$host$dir";
}

/**
 * Busca un token vivo y devuelve la fila con los datos del usuario, o null.
 * NO lockea: la usa info_reset.php, que sólo mira. El canje
 * (restablecer_password.php) hace su propio SELECT ... FOR UPDATE.
 */
function buscar_reset_vigente(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        "SELECT r.id, r.usuario_id, r.usada_at, r.expira_at,
                (r.expira_at <= NOW()) AS vencido,
                u.nombre, u.apellido, u.email
           FROM password_resets r
           JOIN usuarios u ON u.id = r.usuario_id
          WHERE r.token_hash = :hash
          LIMIT 1"
    );
    $stmt->execute([':hash' => hash_token_reset($token)]);
    $fila = $stmt->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Traduce el estado de un token a `[codigo, mensaje]`, o null si sirve.
 * Compartido para que info_reset.php y restablecer_password.php no puedan
 * decir cosas distintas sobre el mismo token.
 */
function motivo_reset_invalido(?array $reset): ?array
{
    if ($reset === null) {
        return ['TOKEN_INVALIDO', 'Este link de recuperación no existe o ya fue reemplazado por uno más nuevo.'];
    }
    if ($reset['usada_at'] !== null) {
        return ['TOKEN_USADO', 'Este link ya se usó para cambiar la contraseña. Pedí uno nuevo si hace falta.'];
    }
    // ── EL VENCIMIENTO LO DECIDE MySQL, NO PHP ──────────────────────────
    // La forma obvia era `strtotime($reset['expira_at']) < time()`, que es lo
    // que hace aceptar_invitacion.php. Está mal, y acá se ve: `expira_at` lo
    // escribe MySQL con su NOW(), y `time()` es el reloj de PHP con la
    // timezone de php.ini. En esta misma máquina PHP está en Europe/Berlin y
    // MySQL en hora local: cinco horas de diferencia. Con eso, un token de una
    // hora nacía vencido — no "a veces", SIEMPRE, y con un mensaje que manda a
    // buscar el problema en el lugar equivocado.
    //
    // Comparar dentro de la query saca a PHP de la ecuación: los dos lados de
    // la comparación salen del mismo reloj, y da igual cómo esté configurado
    // el otro. MySQL devuelve el booleano como 1/0.
    //
    // (Una invitación tiene el mismo problema, pero dura 7 días y un desfasaje
    // de horas no se nota. Es la razón por la que el bug estaba ahí sin que
    // nadie lo viera.)
    if ((int) $reset['vencido'] === 1) {
        return ['TOKEN_EXPIRADO', 'Este link venció. Los links de recuperación duran una hora; pedí uno nuevo.'];
    }
    return null;
}

/**
 * Enmascara un email para mostrarlo en la pantalla de reseteo:
 * `enzo@gmail.com` -> `e***o@gmail.com`.
 *
 * Quien abre el link ya sabe a qué casilla llegó, así que mostrarlo entero no
 * revelaría nada nuevo — pero el link también puede terminar reenviado o en
 * una captura de pantalla, y con el enmascarado alcanza para lo único que
 * tiene que hacer la pantalla: que la persona confirme que está cambiando la
 * contraseña de la cuenta que cree.
 */
function enmascarar_email(string $email): string
{
    $arroba = strpos($email, '@');
    if ($arroba === false || $arroba < 1) {
        return '***';
    }

    $usuario = substr($email, 0, $arroba);
    $dominio = substr($email, $arroba);

    if (strlen($usuario) <= 2) {
        return substr($usuario, 0, 1) . '***' . $dominio;
    }

    return substr($usuario, 0, 1) . '***' . substr($usuario, -1) . $dominio;
}

/**
 * Cuerpo del mail, en las dos versiones que exige enviar_mail().
 *
 * El link va también como texto plano y no sólo detrás de un botón: hay
 * clientes de mail que rompen el HTML, y sin el link visible la persona se
 * queda sin forma de continuar.
 *
 * Los estilos van inline y no en un `<style>`: los clientes de mail
 * (Gmail el primero) descartan el `<head>` entero.
 */
function armar_mail_reset(string $nombre, string $url, string $negocio): array
{
    $nombreEsc = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $negocioEsc = htmlspecialchars($negocio, ENT_QUOTES, 'UTF-8');
    $minutos = RESET_VIGENCIA_MINUTOS;

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1f2937;line-height:1.6;max-width:520px;">'
        . '<p style="font-size:22px;font-weight:700;margin:0 0 4px;">stockIAte</p>'
        . '<p style="margin:0 0 24px;color:#6b7280;font-size:13px;">' . $negocioEsc . '</p>'
        . '<p>Hola ' . $nombreEsc . ',</p>'
        . '<p>Pediste cambiar la contraseña de tu cuenta. Entrá acá para elegir una nueva:</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . $urlEsc . '" style="background:#7c3aed;color:#ffffff;text-decoration:none;'
        . 'padding:13px 22px;border-radius:8px;font-weight:600;display:inline-block;">Cambiar mi contraseña</a>'
        . '</p>'
        . '<p style="font-size:13px;color:#6b7280;">Si el botón no funciona, copiá y pegá esta dirección '
        . 'en el navegador:<br><span style="word-break:break-all;">' . $urlEsc . '</span></p>'
        . '<p style="font-size:13px;color:#6b7280;">El link sirve una sola vez y vence en '
        . $minutos . ' minutos.</p>'
        . '<p style="font-size:13px;color:#6b7280;">Si no pediste esto, podés ignorar este mail: '
        . 'tu contraseña sigue igual.</p>'
        . '</div>';

    $texto = "Hola $nombre,\n\n"
        . "Pediste cambiar la contraseña de tu cuenta de stockIAte ($negocio).\n"
        . "Entrá acá para elegir una nueva:\n\n"
        . "$url\n\n"
        . "El link sirve una sola vez y vence en $minutos minutos.\n\n"
        . "Si no pediste esto, podés ignorar este mail: tu contraseña sigue igual.\n";

    return [$html, $texto];
}
