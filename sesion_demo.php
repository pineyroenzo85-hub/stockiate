<?php
/**
 * stockIAte - sesion_demo.php
 * ================================
 * ATAJO DE TESTEO. Devuelve un usuario "demo" listo para usar, para poder
 * entrar a la app sin pasar por registro.html ni login.html.
 *
 * Por qué esto y no una sesión inventada en el frontend: `lotes_stock`,
 * `ventas`, `correcciones_ia` y `chatbot_conversaciones` tienen FOREIGN KEY
 * a `usuarios(id)` (ver schema.sql). Si el frontend guardara un usuario con
 * un id que no existe en la base, cargar stock o registrar una venta fallaría
 * con un error de integridad referencial. Así que la cuenta demo es una fila
 * real de `usuarios`: se crea la primera vez que se usa y después se reutiliza.
 *
 * La contraseña de esas cuentas es aleatoria y no se guarda en ningún lado:
 * existen para que las FK tengan a quién apuntar, no para loguearse por el
 * formulario normal.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │  OJO: esto es un backdoor. Cualquiera que le pegue a este endpoint   │
 * │  se lleva una sesión de "dueño" sin contraseña, y el proyecto está   │
 * │  expuesto por ngrok con CORS abierto. Antes de mostrar el sistema    │
 * │  fuera de tu máquina: poner MODO_PRUEBA en false, o borrar este      │
 * │  archivo y el bloque "Modo prueba" de login.html.                    │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * GET  -> {"ok":true,"habilitado":true|false}   (login.html lo usa para
 *          decidir si muestra el bloque de skip)
 * POST -> {"rol":"repositor"|"cajero"|"dueño"}
 *          {"ok":true,"usuario":{id,nombre,apellido,email,rol}}
 */

// ⬇⬇⬇ EL INTERRUPTOR: poner en false para desactivar el skip. ⬇⬇⬇
const MODO_PRUEBA = true;

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'configuracion.php'; // sembrar_config_inicial()

cabeceras_json('GET, POST, OPTIONS');

// Sonda: el frontend pregunta si tiene que mostrar el botón o no.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(["ok" => true, "habilitado" => MODO_PRUEBA]);
}

exigir_metodo('POST');

if (!MODO_PRUEBA) {
    responder(["ok" => false, "mensaje" => "El modo prueba está desactivado"], 403);
}

$body = cuerpo_json();
$rol = isset($body['rol']) ? $body['rol'] : '';

/**
 * Negocio al que pertenecen las cuentas demo.
 *
 * Con multi-tenant las cuentas de prueba necesitan un negocio propio: si
 * cayeran en un negocio real, el backdoor daría acceso a los datos de un
 * comercio de verdad. Se crea la primera vez que se usa y después se reusa.
 * La migración `migracion_multitenant.sql` crea un negocio con este mismo
 * nombre y le adopta los datos preexistentes.
 */
const NEGOCIO_DEMO = 'Negocio Demo';

function negocio_demo_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM negocios WHERE nombre = :nombre LIMIT 1");
    $stmt->execute([':nombre' => NEGOCIO_DEMO]);
    $id = $stmt->fetchColumn();

    if ($id !== false) {
        return (int) $id;
    }

    $pdo->prepare("INSERT INTO negocios (nombre) VALUES (:nombre)")
        ->execute([':nombre' => NEGOCIO_DEMO]);
    $negocio_id = (int) $pdo->lastInsertId();

    // Configuración por defecto, igual que en crear_negocio.php. Ojo con
    // WhatsApp: el negocio demo queda sin teléfono y desactivado a propósito.
    // Esto es un backdoor abierto (MODO_PRUEBA), y cualquiera que le pegue
    // podría hacer que le lleguen mensajes al celular de otro.
    sembrar_config_inicial($pdo, $negocio_id);

    return $negocio_id;
}

// 'dueño' es el rol interno (ENUM de la base); en la UI se muestra como
// "Administrador" — mismo criterio que auth.js y chatbot_ia.py.
$CUENTAS_DEMO = [
    'repositor' => ['email' => 'demo.repositor@stockiate.test', 'nombre' => 'Demo', 'apellido' => 'Repositor'],
    'cajero'    => ['email' => 'demo.cajero@stockiate.test',    'nombre' => 'Demo', 'apellido' => 'Cajero'],
    'dueño'     => ['email' => 'demo.admin@stockiate.test',     'nombre' => 'Demo', 'apellido' => 'Administrador'],
];

if (!isset($CUENTAS_DEMO[$rol])) {
    responder(["ok" => false, "mensaje" => "El rol indicado no es válido"], 400);
}

$cuenta = $CUENTAS_DEMO[$rol];

/**
 * Busca la cuenta demo por email. Devuelve el array del usuario o null.
 */
function buscar_demo(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, negocio_id, nombre, apellido, email, rol FROM usuarios WHERE email = :email"
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return null;
    }

    $usuario['id'] = (int) $usuario['id'];
    $usuario['negocio_id'] = (int) $usuario['negocio_id'];
    return $usuario;
}

try {
    $usuario = buscar_demo($pdo, $cuenta['email']);

    if (!$usuario) {
        $negocio_id = negocio_demo_id($pdo);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (negocio_id, nombre, apellido, email, password_hash, rol)
                 VALUES (:negocio_id, :nombre, :apellido, :email, :password_hash, :rol)"
            );
            $stmt->execute([
                ':negocio_id'    => $negocio_id,
                ':nombre'        => $cuenta['nombre'],
                ':apellido'      => $cuenta['apellido'],
                ':email'         => $cuenta['email'],
                // Contraseña aleatoria que no se guarda en ningún lado.
                ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ':rol'           => $rol,
            ]);
        } catch (PDOException $e) {
            // 23000 = email duplicado. Pasa si dos pestañas piden la misma
            // cuenta demo a la vez: la otra ganó la carrera, la releemos.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $usuario = buscar_demo($pdo, $cuenta['email']);
    }

    if (!$usuario) {
        responder(["ok" => false, "mensaje" => "No se pudo preparar la cuenta de prueba"], 500);
    }

    $usuario['negocio_nombre'] = NEGOCIO_DEMO;

    // Igual que el login normal: abre una sesión de servidor de verdad.
    establecer_sesion($usuario);

    responder([
        "ok"      => true,
        "mensaje" => "Sesión de prueba iniciada",
        "usuario" => $usuario,
    ]);
} catch (PDOException $e) {
    responder([
        "ok"      => false,
        "mensaje" => "Error al preparar la cuenta de prueba",
        "error"   => $e->getMessage(),
    ], 500);
}
