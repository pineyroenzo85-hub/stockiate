<?php
/**
 * stockIAte - crear_negocio.php
 * ================================
 * Camino de alta (a): crea un NEGOCIO NUEVO y a su primer usuario, que queda
 * como 'dueño' (el rol Administrador en la UI). Lo usa registro.html.
 *
 * Reemplaza a registrar_usuario.php, que dejaba elegir el rol en un <select>
 * — cualquiera con la URL podía darse de alta como dueño del comercio.
 * Acá el rol NO se lee del body: siempre es 'dueño', porque quien crea un
 * negocio es por definición su administrador. Para sumar empleados está el
 * otro camino: crear_invitacion.php -> aceptar_invitacion.php.
 *
 * Espera un body tipo:
 * {
 *   "negocio_nombre": "Perfumería Norte",
 *   "nombre": "Enzo",
 *   "apellido": "Piñeyro",
 *   "email": "enzo@ejemplo.com",
 *   "password": "algo-secreto"
 * }
 *
 * Todo pasa dentro de una transacción: no puede quedar un negocio sin dueño
 * ni un dueño sin negocio.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php'; // sembrar_config_inicial()

cabeceras_json();
exigir_metodo('POST');

$body = cuerpo_json();

$requeridos = ['negocio_nombre', 'nombre', 'apellido', 'email', 'password'];
foreach ($requeridos as $campo) {
    if (!isset($body[$campo]) || trim((string) $body[$campo]) === '') {
        responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
    }
}

$negocioNombre = trim($body['negocio_nombre']);
$nombre = trim($body['nombre']);
$apellido = trim($body['apellido']);
$email = trim($body['email']);
$password = (string) $body['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(['ok' => false, 'mensaje' => 'El email no es válido'], 400);
}

if (strlen($password) < 6) {
    responder(['ok' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres'], 400);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // 1) El negocio. `creado_por` queda NULL por ahora: el usuario todavía no
    //    existe (la dependencia entre las dos tablas es circular).
    $stmtNegocio = $pdo->prepare("INSERT INTO negocios (nombre) VALUES (:nombre)");
    $stmtNegocio->execute([':nombre' => $negocioNombre]);
    $negocio_id = (int) $pdo->lastInsertId();

    // 2) El dueño.
    $stmtUsuario = $pdo->prepare(
        "INSERT INTO usuarios (negocio_id, nombre, apellido, email, password_hash, rol)
         VALUES (:negocio_id, :nombre, :apellido, :email, :password_hash, 'dueño')"
    );
    $stmtUsuario->execute([
        ':negocio_id' => $negocio_id,
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':email' => $email,
        ':password_hash' => $password_hash,
    ]);
    $usuario_id = (int) $pdo->lastInsertId();

    // 3) Recién ahora se puede cerrar el círculo.
    $pdo->prepare("UPDATE negocios SET creado_por = :usuario_id WHERE id = :negocio_id")
        ->execute([':usuario_id' => $usuario_id, ':negocio_id' => $negocio_id]);

    // 4) Configuración por defecto del negocio. Antes esta fila era única y
    //    global (la sembraba schema.sql); ahora cada negocio tiene la suya.
    //    Las de WhatsApp arrancan DESACTIVADAS y sin teléfono: un negocio
    //    recién creado no manda un solo mensaje hasta que el dueño cargue su
    //    número desde el panel.
    sembrar_config_inicial($pdo, $negocio_id);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // 23000 = violación de restricción única (el email ya está registrado).
    if ($e->getCode() === '23000') {
        responder(['ok' => false, 'mensaje' => 'Ya existe una cuenta registrada con ese email'], 409);
    }

    responder([
        'ok' => false,
        'mensaje' => 'Error al crear el negocio',
        'error' => $e->getMessage(),
    ], 500);
}

$usuario = [
    'id' => $usuario_id,
    'negocio_id' => $negocio_id,
    'nombre' => $nombre,
    'apellido' => $apellido,
    'email' => $email,
    'rol' => 'dueño',
    'negocio_nombre' => $negocioNombre,
];

// Auto-login: quien crea el negocio entra directo, sin pasar por login.html.
establecer_sesion($usuario);

responder([
    'ok' => true,
    'mensaje' => 'Negocio creado correctamente',
    'usuario' => $usuario,
]);
