<?php
/**
 * stockIAte - sesion.php
 * ================================
 * Sesión de servidor + guardias de autorización. Lo hace `require_once`
 * TODOS los endpoints PHP (ya trae `conexion.php`, así que no hace falta
 * requerirlo aparte).
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * ---------------------------
 * Antes de multi-tenant, la sesión vivía solo en localStorage y cada endpoint
 * recibía `usuario_id` como un campo más del body, creyéndole. Con un solo
 * comercio eso era una deuda conocida; con varios comercios es un agujero:
 * MySQL no tiene RLS (a diferencia del Postgres de Supabase), así que si el
 * `negocio_id` viniera del cliente, cualquiera con las DevTools abiertas
 * leería y escribiría el inventario de otro negocio.
 *
 * La regla que sostiene todo el aislamiento es una sola:
 *
 *     EL `negocio_id` NUNCA VIAJA EN EL BODY. SALE DE $_SESSION, SIEMPRE.
 *
 * Y su corolario: toda query de un endpoint lleva `negocio_id = :negocio_id`
 * en su WHERE (o inserta la columna). Es verificable con un grep.
 *
 * USO TÍPICO EN UN ENDPOINT
 * -------------------------
 *     require_once 'sesion.php';
 *     cabeceras_json();
 *     exigir_metodo('POST');
 *     $sesion = exigir_sesion();            // o exigir_sesion(['dueño'])
 *     $negocio_id = $sesion['negocio_id'];
 *     $body = cuerpo_json();
 */

require_once 'conexion.php'; // expone $pdo (PDO conectado a MySQL/XAMPP)

/**
 * Orígenes que pueden mandar requests con credenciales.
 *
 * NO se puede usar `Access-Control-Allow-Origin: *` junto con cookies: el
 * browser rechaza esa combinación. Como el frontend ahora le pega a los PHP
 * con URLs relativas (mismo origen), en la práctica esta lista casi no se
 * usa; queda para el caso del servicio FastAPI en :8000 durante desarrollo
 * local y para el túnel de ngrok.
 *
 * Si cambiás el dominio de ngrok, actualizalo acá.
 */
const ORIGENES_PERMITIDOS = [
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1',
    'http://127.0.0.1:8000',
    'https://atrium-overtime-deluxe.ngrok-free.dev',
];

/** Roles válidos. 'dueño' se muestra como "Administrador" en la UI. */
const ROLES_VALIDOS = ['repositor', 'cajero', 'dueño'];

/**
 * Abre (o retoma) la sesión de servidor. Idempotente: se puede llamar de
 * varios lugares sin romper nada.
 *
 * Detalles que importan:
 * - `path=/`: los endpoints PHP viven bajo /stockiate/tesis_enzo/ pero el
 *   servicio de IA escucha en /chatbot. Sin path=/ la cookie no llegaría al
 *   chatbot y las herramientas del bot no podrían resolver el negocio.
 * - `samesite=Lax` y no Strict: en desarrollo local el frontend está en
 *   localhost:80 y FastAPI en localhost:8000. Son cross-origin pero
 *   same-site, y Lax deja pasar la cookie; Strict la bloquearía.
 * - `httponly`: el JS no tiene por qué leer el identificador de sesión.
 */
function iniciar_sesion_stockiate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('STOCKIATE_SID');
    session_set_cookie_params([
        'lifetime' => 0,          // dura lo que dure el navegador abierto
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/**
 * Cabeceras JSON + CORS acotado. Corta los preflight OPTIONS con un 200
 * ANTES de tocar la sesión (así un preflight no crea sesiones vacías).
 *
 * Reemplaza al bloque `Access-Control-Allow-Origin: *` que estaba copiado en
 * los 16 endpoints.
 */
function cabeceras_json(string $metodos = 'POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');

    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '' && in_array($origen, ORIGENES_PERMITIDOS, true)) {
        header("Access-Control-Allow-Origin: $origen");
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header("Access-Control-Allow-Methods: $metodos");
    header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

/** Responde JSON y termina. */
function responder(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos);
    exit();
}

/**
 * Exige un método HTTP concreto. Antes ningún endpoint validaba esto, así
 * que varios `consultar_*.php` devolvían el listado completo a un GET plano
 * escrito en la barra de direcciones.
 */
function exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($metodo)) {
        responder(['ok' => false, 'mensaje' => 'Método no permitido'], 405);
    }
}

/** Body JSON del request como array (vacío si no vino nada). */
function cuerpo_json(): array
{
    $crudo = file_get_contents('php://input');
    if ($crudo === false || trim($crudo) === '') {
        return [];
    }
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : [];
}

/**
 * Guarda al usuario en la sesión. La llaman iniciar_sesion.php,
 * crear_negocio.php, aceptar_invitacion.php y sesion_demo.php.
 *
 * `session_regenerate_id(true)` evita fijación de sesión: si alguien logró
 * plantar un id de sesión antes del login, ese id deja de servir.
 */
function establecer_sesion(array $usuario): void
{
    iniciar_sesion_stockiate();
    session_regenerate_id(true);

    $_SESSION['usuario'] = [
        'id' => (int) $usuario['id'],
        'negocio_id' => (int) $usuario['negocio_id'],
        'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'],
        'email' => $usuario['email'],
        'rol' => $usuario['rol'],
        // Contra qué base se autenticó. Los IDs de `stockiate` y de
        // `stockiate_demo` son dos numeraciones independientes: sin esto, una
        // sesión abierta en demo sigue siendo válida al volver a modo normal y
        // te deja adentro del usuario que tenga ESE id en la base real, con
        // sus datos y su rol. Ver sesion_actual().
        'base' => STOCKIATE_BASE,
    ];
}

/** Cierra la sesión y borra la cookie. */
function cerrar_sesion_stockiate(): void
{
    iniciar_sesion_stockiate();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Devuelve el usuario de la sesión, o null si no hay sesión iniciada.
 * Claves: id, negocio_id, nombre, apellido, email, rol.
 */
function sesion_actual(): ?array
{
    iniciar_sesion_stockiate();
    $usuario = $_SESSION['usuario'] ?? null;

    if ($usuario === null) {
        return null;
    }

    // La sesión se abrió contra otra base (se cambió STOCKIATE_MODO con la
    // sesión abierta). No vale: el id apunta a otra persona.
    //
    // Una sesión SIN la clave `base` tampoco vale, aunque sea anterior a que
    // esta clave existiera: no hay forma de saber contra qué base se abrió, y
    // asumir la normal es exactamente el caso peligroso (una sesión de demo
    // pasando a leer los datos reales). El costo es que todo el mundo tiene
    // que volver a loguearse una vez.
    if (($usuario['base'] ?? '') !== STOCKIATE_BASE) {
        $_SESSION['usuario'] = null;
        unset($_SESSION['usuario']);
        return null;
    }

    // No forma parte del usuario para el resto del sistema: es metadato de la
    // sesión, y sesion_actual.php serializa este array tal cual al frontend.
    unset($usuario['base']);

    return $usuario;
}

/**
 * Guardia principal. Sin sesión -> 401. Con sesión pero rol equivocado -> 403.
 * Devuelve el usuario (garantizado, porque si no corta con exit).
 *
 * El frontend distingue por el campo `codigo`: ante SIN_SESION borra su caché
 * de localStorage y manda a login.html (ver fetchApi() en auth.js).
 *
 * @param string[]|null $roles Roles aceptados, o null para "cualquier sesión".
 */
function exigir_sesion(?array $roles = null): array
{
    $usuario = sesion_actual();

    if ($usuario === null) {
        responder([
            'ok' => false,
            'codigo' => 'SIN_SESION',
            'mensaje' => 'Necesitás iniciar sesión',
        ], 401);
    }

    if ($roles !== null && !in_array($usuario['rol'], $roles, true)) {
        responder([
            'ok' => false,
            'codigo' => 'ROL_INSUFICIENTE',
            'mensaje' => 'Tu rol no tiene permiso para esta acción',
        ], 403);
    }

    return $usuario;
}

/**
 * Genera un token de invitación aleatorio con forma de UUID v4.
 * `random_bytes` es criptográficamente seguro: el token es el único secreto
 * que protege una invitación, así que no puede ser adivinable ni enumerable.
 */
function generar_token_invitacion(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // versión 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variante RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
