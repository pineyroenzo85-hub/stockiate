<?php
/**
 * stockIAte - info_reset.php
 * ================================
 * Paso 2a: `recuperar.html` pregunta si el token del link todavía sirve,
 * ANTES de mostrar el formulario.
 *
 * Sin esto, la persona completa dos campos de contraseña y recién al enviar
 * se entera de que el link venció — el mismo motivo por el que existe
 * `info_invitacion.php`.
 *
 * Es PÚBLICO, como aquél, y por eso expone lo mínimo: el email ENMASCARADO
 * (`e***o@gmail.com`) y nada más. Ni el usuario_id, ni el negocio, ni el rol.
 * Quien tiene el token ya sabe a qué casilla llegó; el enmascarado sólo sirve
 * para que confirme que está por cambiar la contraseña de la cuenta correcta.
 *
 * GET info_reset.php?token=<hex>
 */

require_once 'sesion.php';           // trae conexion.php ($pdo)
require_once 'reset_password.php';

cabeceras_json('GET, OPTIONS');
exigir_metodo('GET');

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    responder(['ok' => false, 'codigo' => 'TOKEN_INVALIDO',
               'mensaje' => 'El link no trae ningún código de recuperación.'], 400);
}

try {
    $reset = buscar_reset_vigente($pdo, $token);
    $motivo = motivo_reset_invalido($reset);

    if ($motivo !== null) {
        [$codigo, $mensaje] = $motivo;
        // 404 sólo cuando no existe; 410 (Gone) cuando existió y ya no sirve.
        responder(['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje],
                  $codigo === 'TOKEN_INVALIDO' ? 404 : 410);
    }

    responder([
        'ok' => true,
        'nombre' => $reset['nombre'],
        'email_enmascarado' => enmascarar_email((string) $reset['email']),
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al verificar el link de recuperación',
        'error' => $e->getMessage(),
    ], 500);
}
