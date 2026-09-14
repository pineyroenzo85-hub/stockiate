<?php
/**
 * stockIAte - crear_invitacion.php
 * ================================
 * Un dueño invita a alguien a SU negocio con un rol determinado. Sólo el rol
 * 'dueño' puede hacerlo, y sólo sobre su propio negocio: el `negocio_id` sale
 * de la sesión, nunca del body.
 *
 * NO manda mail: no hay mailer en este stack. Devuelve el token para que
 * equipo.html arme el link (`invitacion.html?token=...`) y el dueño lo pase
 * por donde quiera (WhatsApp, en persona, lo que sea).
 *
 * Espera un body tipo:
 * {
 *   "email": "cajero@ejemplo.com",
 *   "rol": "cajero"     // "repositor" | "cajero" | "dueño"
 * }
 *
 * La invitación vence a los 7 días y sirve UNA sola vez (ver `usada_at`).
 */

require_once 'sesion.php';

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];

$body = cuerpo_json();

if (!isset($body['email'], $body['rol']) || trim((string) $body['email']) === '') {
    responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
}

$email = trim($body['email']);
$rol = (string) $body['rol'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(['ok' => false, 'mensaje' => 'El email no es válido'], 400);
}

if (!in_array($rol, ROLES_VALIDOS, true)) {
    responder(['ok' => false, 'mensaje' => 'El rol indicado no es válido'], 400);
}

try {
    // `usuarios.email` es UNIQUE global (una persona = una cuenta = un
    // negocio, ver la nota en schema.sql). Si ya existe, la invitación nunca
    // podría completarse: mejor decirlo ahora que después del formulario.
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetchColumn() !== false) {
        responder(['ok' => false, 'mensaje' => 'Ya existe una cuenta registrada con ese email'], 409);
    }

    // Evita acumular invitaciones vivas duplicadas para la misma persona.
    $stmt = $pdo->prepare(
        "SELECT token FROM invitaciones
          WHERE negocio_id = :negocio_id AND email = :email
            AND usada_at IS NULL AND expira_at > NOW()
          LIMIT 1"
    );
    $stmt->execute([':negocio_id' => $negocio_id, ':email' => $email]);
    $vigente = $stmt->fetchColumn();
    if ($vigente !== false) {
        responder([
            'ok' => false,
            'mensaje' => 'Ya hay una invitación pendiente para ese email',
            'token' => $vigente,
        ], 409);
    }

    $token = generar_token_invitacion();

    $pdo->prepare(
        "INSERT INTO invitaciones (negocio_id, email, rol, token, expira_at, creado_por)
         VALUES (:negocio_id, :email, :rol, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), :creado_por)"
    )->execute([
        ':negocio_id' => $negocio_id,
        ':email' => $email,
        ':rol' => $rol,
        ':token' => $token,
        ':creado_por' => $sesion['id'],
    ]);

    responder([
        'ok' => true,
        'mensaje' => 'Invitación creada',
        'token' => $token,
        'ruta' => 'invitacion.html?token=' . $token,
        'email' => $email,
        'rol' => $rol,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al crear la invitación',
        'error' => $e->getMessage(),
    ], 500);
}
