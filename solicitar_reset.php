<?php
/**
 * stockIAte - solicitar_reset.php
 * ================================
 * Paso 1 de la recuperación de contraseña: la persona escribe su email en
 * `olvide_password.html` y acá se le manda el link.
 *
 * Es PÚBLICO (no hay sesión: justamente el problema es que no puede entrar).
 *
 * Espera un body tipo:
 * {
 *   "email": "enzo@ejemplo.com"
 * }
 *
 * ────────────────────────────────────────────────────────────────────────
 * SIEMPRE RESPONDE LO MISMO, EXISTA O NO LA CUENTA
 * ────────────────────────────────────────────────────────────────────────
 * Si contestara "no hay ninguna cuenta con ese email" para uno y "listo, te
 * mandamos el mail" para otro, este endpoint sería un enumerador de cuentas
 * gratis: se le pasa una lista de emails y devuelve cuáles están registrados.
 * Es el mismo cuidado que ya tiene `iniciar_sesion.php`, que responde "Email o
 * contraseña incorrectos" sin decir cuál de los dos estuvo mal.
 *
 * Por eso el `ok: true` de acá no significa "se mandó el mail". Significa
 * "terminamos de procesar tu pedido". Los tres casos que responden igual son:
 * email inexistente, límite por hora alcanzado, y mail que falló al salir.
 *
 * LO QUE ESTA DEFENSA NO TAPA: el tiempo. Mandar un mail por SMTP tarda ~1 s
 * y un email inexistente responde al instante, así que quien mida los tiempos
 * puede distinguir los casos igual. Taparlo del todo pide encolar el envío
 * (como hace `notificaciones.php` con WhatsApp) y que un cron lo despache,
 * pero acá eso significaría que el mail de "olvidé mi contraseña" llega hasta
 * 5 minutos tarde, que es peor para la persona que el ataque que evita. Queda
 * como una limitación conocida, no como un descuido.
 */

require_once 'sesion.php';           // trae conexion.php ($pdo)
require_once 'reset_password.php';
require_once 'mailer.php';

cabeceras_json();
exigir_metodo('POST');

$body = cuerpo_json();

if (!isset($body['email']) || trim((string) $body['email']) === '') {
    responder(['ok' => false, 'mensaje' => 'Escribí tu email'], 400);
}

$email = trim((string) $body['email']);

// Un email mal escrito no es una cuenta que exista o no exista: decirlo no
// filtra nada y evita que alguien se quede esperando un mail que nunca pidió
// bien.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(['ok' => false, 'mensaje' => 'Ese email no parece válido'], 400);
}

// Sin SMTP configurado no hay nada que hacer, y decirlo no filtra ninguna
// cuenta: es igual para todo el mundo. Es un problema del servidor, no del
// usuario, así que va un 503 con un mensaje que se entienda.
if (!mail_configurado()) {
    responder([
        'ok' => false,
        'codigo' => 'MAIL_SIN_CONFIGURAR',
        'mensaje' => 'La recuperación por email todavía no está configurada en este servidor. '
                   . 'Pedile a quien administra el negocio que te cambie la contraseña.',
    ], 503);
}

/** La respuesta única. Ver el comentario de arriba. */
$RESPUESTA_GENERICA = [
    'ok' => true,
    'mensaje' => 'Si hay una cuenta con ese email, te mandamos un link para cambiar la contraseña. '
               . 'Revisá tu bandeja de entrada y también el spam.',
];

try {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.nombre, u.apellido, u.email, n.nombre AS negocio_nombre
           FROM usuarios u
           JOIN negocios n ON n.id = u.negocio_id
          WHERE u.email = :email
          LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        responder($RESPUESTA_GENERICA);
    }

    // Límite por cuenta y por hora: sin esto el formulario es un botón para
    // llenarle la casilla a cualquiera. Se cuentan los pedidos, no los envíos
    // exitosos, para que un SMTP roto no habilite reintentos infinitos.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM password_resets
          WHERE usuario_id = :id AND creado_en > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([':id' => $usuario['id']]);

    if ((int) $stmt->fetchColumn() >= RESET_MAX_POR_HORA) {
        responder($RESPUESTA_GENERICA);
    }

    $token = generar_token_reset();

    $pdo->beginTransaction();

    // Pedir un link nuevo mata los anteriores. Si no, cada pedido deja otra
    // llave viva dando vueltas por la casilla, y basta con que se filtre
    // cualquiera de ellas. Se vencen en vez de borrarse para que el conteo
    // del límite por hora siga viendo los pedidos.
    $pdo->prepare(
        "UPDATE password_resets SET expira_at = NOW()
          WHERE usuario_id = :id AND usada_at IS NULL AND expira_at > NOW()"
    )->execute([':id' => $usuario['id']]);

    // La vigencia va interpolada y no como placeholder: con
    // ATTR_EMULATE_PREPARES en false (ver conexion.php) el `?` viaja al motor
    // como parámetro real, y MySQL no acepta uno como cantidad de un
    // INTERVAL. Es seguro porque es una constante entera de PHP, no un dato
    // del request.
    $pdo->prepare(
        "INSERT INTO password_resets (usuario_id, token_hash, expira_at, ip_solicitud)
         VALUES (:usuario_id, :hash, DATE_ADD(NOW(), INTERVAL " . RESET_VIGENCIA_MINUTOS . " MINUTE), :ip)"
    )->execute([
        ':usuario_id' => $usuario['id'],
        ':hash' => hash_token_reset($token),
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);

    $reset_id = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        'ok' => false,
        'mensaje' => 'Error al generar el link de recuperación',
        'error' => $e->getMessage(),
    ], 500);
}

// ── El envío va DESPUÉS del commit y fuera de la transacción ──────────────
// Mismo criterio que `registrar_venta.php` con las notificaciones: una
// llamada de red a un servidor de terceros no puede tener abierta una
// transacción de MySQL. Si el mail falla, el token ya está escrito y el error
// queda guardado en su fila.
$url = url_base_app() . '/recuperar.html?token=' . $token;
[$html, $texto] = armar_mail_reset(
    (string) $usuario['nombre'],
    $url,
    (string) $usuario['negocio_nombre']
);

$envio = enviar_mail(
    (string) $usuario['email'],
    trim($usuario['nombre'] . ' ' . $usuario['apellido']),
    'Cambiá tu contraseña de stockIAte',
    $html,
    $texto
);

// El error crudo del SMTP se guarda en la fila, que es la única forma de
// diagnosticar "no me llegó nada" sin adivinar. Es la misma idea que la
// columna `error` de `notificaciones`. Se guarda con un catch mudo: que no se
// pueda anotar el resultado no cambia lo que se le responde a la persona.
//
// LA MARCA DE TIEMPO LA PONE MySQL CON `NOW()`, no PHP con `date()`.
// Es el mismo motivo que el del vencimiento (ver motivo_reset_invalido() en
// reset_password.php): los relojes de PHP y de MySQL no son el mismo. Con
// `date()` acá, la fila quedaba con `creado_en 19:59:22` y
// `enviado_at 00:59:26` — el mail parecía haber salido CINCO HORAS después de
// que se pidió el link. No rompe nada porque nadie compara esta columna, pero
// esta fila existe para diagnosticar, y una fila que miente al que la lee no
// sirve para diagnosticar nada.
//
// Son dos statements y no un `IF()`: leerlos dice exactamente qué queda
// escrito en cada caso, y el éxito tiene que BORRAR el error de un intento
// anterior, no dejarlo conviviendo con un `enviado_at`.
try {
    if ($envio['ok']) {
        $pdo->prepare(
            "UPDATE password_resets SET enviado_at = NOW(), error = NULL WHERE id = :id"
        )->execute([':id' => $reset_id]);
    } else {
        $pdo->prepare(
            "UPDATE password_resets SET enviado_at = NULL, error = :error WHERE id = :id"
        )->execute([
            ':error' => substr((string) $envio['error'], 0, 2000),
            ':id' => $reset_id,
        ]);
    }
} catch (PDOException $e) {
    // Sin ruido a propósito.
}

responder($RESPUESTA_GENERICA);
