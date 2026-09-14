<?php
/**
 * stockIAte - restablecer_password.php
 * ================================
 * Paso 2b: canjea el token del mail por una contraseña nueva.
 *
 * Es PÚBLICO: quien llega acá no tiene sesión, y lo que lo autoriza es haber
 * recibido el token en su casilla. Eso es todo lo que prueba (y todo lo que
 * hace falta): control del email de la cuenta.
 *
 * Espera un body tipo:
 * {
 *   "token": "<64 hex>",
 *   "password": "algo-secreto"
 * }
 *
 * NO HACE AUTO-LOGIN, a diferencia de aceptar_invitacion.php.
 * Es deliberado y es la contracara del punto siguiente: acá el objetivo es
 * que después de cambiar la contraseña NO quede ninguna sesión viva, ni la
 * del atacante ni la nuestra. Abrir una sesión nueva en el mismo request
 * contradiría eso. La persona vuelve a login.html y entra con su contraseña
 * nueva, que además es la forma de confirmar que quedó bien guardada.
 *
 * ────────────────────────────────────────────────────────────────────────
 * CAMBIAR LA CONTRASEÑA ECHA A LAS SESIONES ABIERTAS
 * ────────────────────────────────────────────────────────────────────────
 * Es lo que separa un reseteo de verdad de uno decorativo. El caso que hay
 * que cubrir es "me entraron a la cuenta": si el atacante ya tiene su cookie
 * de sesión, cambiar la contraseña sin echarlo lo deja adentro exactamente
 * igual que antes.
 *
 * Las sesiones de PHP son archivos en disco y no se pueden barrer por
 * usuario, así que el mecanismo es indirecto: se estampa
 * `usuarios.password_cambiado_en`, y `sesion_actual()` (sesion.php) compara
 * esa marca contra la que la sesión guardó al abrirse. La que no coincide
 * deja de valer en su próximo request.
 */

require_once 'sesion.php';           // trae conexion.php ($pdo)
require_once 'reset_password.php';

cabeceras_json();
exigir_metodo('POST');

$body = cuerpo_json();

foreach (['token', 'password'] as $campo) {
    if (!isset($body[$campo]) || trim((string) $body[$campo]) === '') {
        responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
    }
}

$token = trim((string) $body['token']);
$password = (string) $body['password'];

// El mismo mínimo que pide aceptar_invitacion.php. Si algún día sube, tiene
// que subir en los dos lados a la vez.
if (strlen($password) < 6) {
    responder(['ok' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // FOR UPDATE, igual que aceptar_invitacion.php: lockea la fila hasta el
    // commit para que el "¿todavía no se usó?" y el marcado de usada sean
    // atómicos. Dos pestañas con el mismo link no pueden canjearlo dos veces.
    $stmt = $pdo->prepare(
        "SELECT id, usuario_id, usada_at, expira_at,
                (expira_at <= NOW()) AS vencido
           FROM password_resets WHERE token_hash = :hash FOR UPDATE"
    );
    $stmt->execute([':hash' => hash_token_reset($token)]);
    $reset = $stmt->fetch();

    $motivo = motivo_reset_invalido($reset === false ? null : $reset);
    if ($motivo !== null) {
        $pdo->rollBack();
        [$codigo, $mensaje] = $motivo;
        responder(['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje],
                  $codigo === 'TOKEN_INVALIDO' ? 404 : 410);
    }

    // La contraseña nueva y la marca que echa a las sesiones, en el mismo
    // UPDATE: no puede pasar que una quede escrita y la otra no.
    $pdo->prepare(
        "UPDATE usuarios SET password_hash = :hash, password_cambiado_en = NOW()
          WHERE id = :id"
    )->execute([
        ':hash' => $password_hash,
        ':id' => $reset['usuario_id'],
    ]);

    $pdo->prepare("UPDATE password_resets SET usada_at = NOW() WHERE id = :id")
        ->execute([':id' => $reset['id']]);

    // Los demás links que la persona haya pedido dejan de servir. Si pidió
    // tres mails porque el primero no le llegaba, los otros dos no pueden
    // quedar vivos en la casilla después de que ya cambió la contraseña.
    $pdo->prepare(
        "UPDATE password_resets SET expira_at = NOW()
          WHERE usuario_id = :usuario_id AND usada_at IS NULL AND expira_at > NOW()"
    )->execute([':usuario_id' => $reset['usuario_id']]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder([
        'ok' => false,
        'mensaje' => 'Error al cambiar la contraseña',
        'error' => $e->getMessage(),
    ], 500);
}

// Si quien canjeó el token era el propio usuario con la sesión abierta en
// esta misma pestaña, su sesión también murió (es la que estampamos recién).
// Cerrarla acá evita que se quede con una cookie que ya no vale y con la
// caché de localStorage desactualizada.
cerrar_sesion_stockiate();

responder([
    'ok' => true,
    'mensaje' => 'Listo, tu contraseña quedó cambiada. Ya podés iniciar sesión.',
]);
