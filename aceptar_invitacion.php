<?php
/**
 * stockIAte - aceptar_invitacion.php
 * ================================
 * Camino de alta (b): la persona invitada crea su cuenta y queda dentro del
 * negocio que la invitó, con el rol que dice la invitación.
 *
 * Dos cosas NO se leen del body, por diseño:
 *   - el ROL, que sale de la invitación (si viniera del body, cualquiera se
 *     autoproclamaría dueño con un token de cajero);
 *   - el EMAIL, que también sale de la invitación (si viniera del body,
 *     invitás a una persona y se registra otra con el mismo link).
 *
 * El body sólo aporta los datos personales y la contraseña:
 * {
 *   "token": "<uuid>",
 *   "nombre": "Ana",
 *   "apellido": "Gómez",
 *   "password": "algo-secreto"
 * }
 *
 * Todo dentro de una transacción con SELECT ... FOR UPDATE sobre la
 * invitación: dos personas que abran el mismo link a la vez no pueden
 * crear dos cuentas, la segunda encuentra `usada_at` ya marcado.
 */

require_once 'sesion.php';

cabeceras_json();
exigir_metodo('POST');

$body = cuerpo_json();

$requeridos = ['token', 'nombre', 'apellido', 'password'];
foreach ($requeridos as $campo) {
    if (!isset($body[$campo]) || trim((string) $body[$campo]) === '') {
        responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
    }
}

$token = trim($body['token']);
$nombre = trim($body['nombre']);
$apellido = trim($body['apellido']);
$password = (string) $body['password'];

if (strlen($password) < 6) {
    responder(['ok' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // FOR UPDATE: lockea la invitación hasta el commit, así el chequeo de
    // "todavía no se usó" y el marcado de usada son atómicos.
    $stmt = $pdo->prepare(
        "SELECT id, negocio_id, email, rol, usada_at, expira_at,
                (expira_at <= NOW()) AS vencido
           FROM invitaciones WHERE token = :token FOR UPDATE"
    );
    $stmt->execute([':token' => $token]);
    $inv = $stmt->fetch();

    if (!$inv) {
        $pdo->rollBack();
        responder(['ok' => false, 'codigo' => 'TOKEN_INVALIDO',
                   'mensaje' => 'Esta invitación no existe'], 404);
    }

    if ($inv['usada_at'] !== null) {
        $pdo->rollBack();
        responder(['ok' => false, 'codigo' => 'TOKEN_USADA',
                   'mensaje' => 'Esta invitación ya fue usada'], 410);
    }

    // ── EL VENCIMIENTO LO DECIDE MySQL, NO PHP ──────────────────────────
    // Acá había un `strtotime($inv['expira_at']) < time()`, y estaba mal:
    // `expira_at` lo escribe MySQL con su NOW() (el DATE_ADD de
    // crear_invitacion.php) y `time()` es el reloj de PHP con la timezone de
    // php.ini. En esta misma máquina PHP está en Europe/Berlin y MySQL en hora
    // local: cinco horas de diferencia, o sea que una invitación de 7 días
    // vencía a los 6 días y 19 horas.
    //
    // Con 7 días de vigencia el desfasaje casi no se nota, y por eso el bug
    // estuvo acá sin que nadie lo viera. Se descubrió construyendo el reseteo
    // de contraseña, donde el token dura una hora y con cinco de diferencia
    // nacía vencido SIEMPRE (ver el comentario largo de
    // motivo_reset_invalido(), en reset_password.php).
    //
    // Comparar dentro de la query saca a PHP de la ecuación: los dos lados
    // salen del mismo reloj y da igual cómo esté configurado el otro. MySQL
    // devuelve el booleano como 1/0. Es lo que crear_invitacion.php ya hacía
    // del otro lado, con su `expira_at > NOW()`.
    if ((int) $inv['vencido'] === 1) {
        $pdo->rollBack();
        responder(['ok' => false, 'codigo' => 'TOKEN_EXPIRADO',
                   'mensaje' => 'Esta invitación venció. Pedile al administrador que te mande una nueva.'], 410);
    }

    // El rol y el email salen de la invitación, no del request.
    $stmtUsuario = $pdo->prepare(
        "INSERT INTO usuarios (negocio_id, nombre, apellido, email, password_hash, rol)
         VALUES (:negocio_id, :nombre, :apellido, :email, :password_hash, :rol)"
    );
    $stmtUsuario->execute([
        ':negocio_id' => $inv['negocio_id'],
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':email' => $inv['email'],
        ':password_hash' => $password_hash,
        ':rol' => $inv['rol'],
    ]);
    $usuario_id = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE invitaciones SET usada_at = NOW() WHERE id = :id")
        ->execute([':id' => $inv['id']]);

    $stmtNeg = $pdo->prepare("SELECT nombre FROM negocios WHERE id = :id");
    $stmtNeg->execute([':id' => $inv['negocio_id']]);
    $negocioNombre = (string) $stmtNeg->fetchColumn();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getCode() === '23000') {
        responder(['ok' => false, 'mensaje' => 'Ya existe una cuenta registrada con ese email'], 409);
    }

    responder([
        'ok' => false,
        'mensaje' => 'Error al aceptar la invitación',
        'error' => $e->getMessage(),
    ], 500);
}

$usuario = [
    'id' => $usuario_id,
    'negocio_id' => (int) $inv['negocio_id'],
    'nombre' => $nombre,
    'apellido' => $apellido,
    'email' => $inv['email'],
    'rol' => $inv['rol'],
    'negocio_nombre' => $negocioNombre,
];

// Auto-login, igual que al crear un negocio.
establecer_sesion($usuario);

responder([
    'ok' => true,
    'mensaje' => 'Cuenta creada correctamente',
    'usuario' => $usuario,
]);
