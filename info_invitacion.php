<?php
/**
 * stockIAte - info_invitacion.php
 * ================================
 * Endpoint PÚBLICO (sin sesión, a propósito): le dice a la página de
 * invitación a qué negocio la invitaron y con qué rol, ANTES de que la
 * persona tenga cuenta. Sin esto, invitacion.html no podría mostrar nada
 * más que un formulario a ciegas.
 *
 * Expone EXACTAMENTE cuatro cosas y nada más:
 *     negocio_nombre, email, rol, valida
 *
 * En particular NO devuelve `negocio_id` ni quién invitó: el que tiene el
 * token todavía no es nadie dentro del sistema, y el token puede haber
 * circulado por un chat. La tabla `invitaciones` completa sólo la ve el
 * dueño del negocio (listar_invitaciones.php).
 *
 * GET ?token=<uuid>
 *   -> 200 {"ok":true,"valida":true,"negocio_nombre":"...","email":"...","rol":"cajero"}
 *   -> 404/410 {"ok":false,"valida":false,"codigo":"TOKEN_INVALIDO|TOKEN_EXPIRADO|TOKEN_USADA"}
 */

require_once 'sesion.php';

cabeceras_json('GET, OPTIONS');
exigir_metodo('GET');

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($token === '') {
    responder(['ok' => false, 'valida' => false, 'codigo' => 'TOKEN_INVALIDO',
               'mensaje' => 'Falta el token de invitación'], 400);
}

try {
    $stmt = $pdo->prepare(
        "SELECT i.email, i.rol, i.usada_at, i.expira_at,
                (i.expira_at <= NOW()) AS vencido,
                n.nombre AS negocio_nombre
           FROM invitaciones i
           JOIN negocios n ON n.id = i.negocio_id
          WHERE i.token = :token
          LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $inv = $stmt->fetch();

    if (!$inv) {
        responder(['ok' => false, 'valida' => false, 'codigo' => 'TOKEN_INVALIDO',
                   'mensaje' => 'Esta invitación no existe'], 404);
    }

    if ($inv['usada_at'] !== null) {
        responder(['ok' => false, 'valida' => false, 'codigo' => 'TOKEN_USADA',
                   'mensaje' => 'Esta invitación ya fue usada'], 410);
    }

    // El vencimiento lo calcula MySQL y no PHP, por la diferencia de relojes
    // que explica el comentario largo de aceptar_invitacion.php. Acá importa
    // además que las dos pantallas usen EXACTAMENTE el mismo criterio: si esta
    // previa dijera "válida" y el canje dijera "vencida", la persona completa
    // el formulario entero para que recién al confirmar le rebote el link.
    if ((int) $inv['vencido'] === 1) {
        responder(['ok' => false, 'valida' => false, 'codigo' => 'TOKEN_EXPIRADO',
                   'mensaje' => 'Esta invitación venció. Pedile al administrador que te mande una nueva.'], 410);
    }

    responder([
        'ok' => true,
        'valida' => true,
        'negocio_nombre' => $inv['negocio_nombre'],
        'email' => $inv['email'],
        'rol' => $inv['rol'],
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'valida' => false,
        'mensaje' => 'Error al leer la invitación',
        'error' => $e->getMessage(),
    ], 500);
}
