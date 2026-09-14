<?php
/**
 * stockIAte - iniciar_sesion.php
 * ================================
 * Login: valida email + contraseña contra `usuarios` y ABRE LA SESIÓN DE
 * SERVIDOR (cookie STOCKIATE_SID, ver sesion.php).
 *
 * Además devuelve los datos del usuario en el JSON, pero eso ya NO es la
 * sesión: es solo para que auth.js llene su caché de UX (nombre, rol, a qué
 * módulo redirigir) sin tener que hacer un round-trip extra. La sesión real,
 * la que deciden los endpoints, vive en $_SESSION.
 *
 * Espera un body tipo:
 * {
 *   "email": "enzo@ejemplo.com",
 *   "password": "algo-secreto"
 * }
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

$body = cuerpo_json();

if (!isset($body['email'], $body['password']) || trim($body['email']) === '' || $body['password'] === '') {
    responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
}

$email = trim($body['email']);
$password = (string) $body['password'];

try {
    $stmt = $pdo->prepare(
        "SELECT id, negocio_id, nombre, apellido, email, password_hash, rol
         FROM usuarios WHERE email = :email"
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    // Mensaje genérico en ambos casos (email inexistente o contraseña
    // incorrecta) para no revelar si un email está registrado o no.
    if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
        responder(['ok' => false, 'mensaje' => 'Email o contraseña incorrectos'], 401);
    }

    unset($usuario['password_hash']);

    // Nombre del negocio: lo usa la UI para mostrar "estás en X".
    $stmtNeg = $pdo->prepare("SELECT nombre FROM negocios WHERE id = :id");
    $stmtNeg->execute([':id' => $usuario['negocio_id']]);
    $usuario['negocio_nombre'] = (string) $stmtNeg->fetchColumn();

    establecer_sesion($usuario);

    responder([
        'ok' => true,
        'mensaje' => 'Inicio de sesión exitoso',
        'usuario' => $usuario,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al iniciar sesión',
        'error' => $e->getMessage(),
    ], 500);
}
