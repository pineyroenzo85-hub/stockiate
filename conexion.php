<?php
/**
 * stockIAte - conexion.php
 * Conexión PDO reutilizable a MySQL local (XAMPP).
 * Todos los endpoints (guardar_stock.php, registrar_correccion.php, etc.)
 * hacen require_once de este archivo.
 *
 * MODO DEMO
 * ---------
 * El nombre de la base NO está escrito en el DSN: sale de una constante, y
 * hay dos bases posibles.
 *
 *   normal -> `stockiate`        (los datos reales del comercio)
 *   demo   -> `stockiate_demo`   (los datos sembrados por seed_demo.php)
 *
 * Se elige con `STOCKIATE_MODO=demo` en el `.env` (ver .env.example). Un
 * `.env` sin esa clave, o con cualquier otro valor, es modo normal: no se
 * puede caer en la demo por olvido.
 *
 * POR QUÉ DOS BASES Y NO UNA COLUMNA `es_demo`
 * --------------------------------------------
 * Un flag en las filas depende de que las ~20 queries del proyecto se acuerden
 * de filtrarlo. Alcanza con que UNA se olvide para que los datos inventados se
 * cuelen en un promedio del piloto, y el síntoma sería un número apenas raro
 * en una tabla, meses después, sin nada que lo delate. Dos bases separadas no
 * se mezclan aunque el código tenga bugs: el aislamiento lo garantiza el
 * motor, no nuestra disciplina. Es el mismo criterio con el que el proyecto
 * eligió `negocio_id` como columna real en vez de derivarla por JOIN.
 */

require_once __DIR__ . '/env.php';

if (!defined('STOCKIATE_BASE_NORMAL')) {
    define('STOCKIATE_BASE_NORMAL', 'stockiate');
    define('STOCKIATE_BASE_DEMO', 'stockiate_demo');

    // Credenciales de XAMPP: root sin password. Siguen hardcodeadas a
    // propósito (son iguales en todas las máquinas de desarrollo y no son un
    // secreto), pero pasan a ser constantes para que seed_demo.php pueda
    // abrir su propia conexión sin duplicarlas.
    define('STOCKIATE_DB_HOST', 'localhost');
    define('STOCKIATE_DB_USER', 'root');
    define('STOCKIATE_DB_PASS', '');
    define('STOCKIATE_DB_CHARSET', 'utf8mb4');

    define('STOCKIATE_MODO_DEMO', strtolower(trim((string) leer_env('STOCKIATE_MODO', 'normal'))) === 'demo');
    define('STOCKIATE_BASE', STOCKIATE_MODO_DEMO ? STOCKIATE_BASE_DEMO : STOCKIATE_BASE_NORMAL);
}

/** Las opciones de PDO que usa todo el proyecto. */
function opciones_pdo_stockiate(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
}

/**
 * Abre una conexión nueva a una base concreta, o al servidor sin base si
 * `$base` es null (que es lo que necesita seed_demo.php para poder hacer
 * DROP/CREATE DATABASE).
 */
function conectar_mysql(?string $base): PDO
{
    $dsn = "mysql:host=" . STOCKIATE_DB_HOST;
    if ($base !== null) {
        $dsn .= ";dbname=$base";
    }
    $dsn .= ";charset=" . STOCKIATE_DB_CHARSET;

    return new PDO($dsn, STOCKIATE_DB_USER, STOCKIATE_DB_PASS, opciones_pdo_stockiate());
}

// seed_demo.php define esta constante ANTES de incluir este archivo: tiene que
// poder cargar las credenciales y `conectar_mysql()` cuando la base de demo
// todavía no existe (es justo lo que va a crear). Cualquier otro consumidor no
// la define y se lleva su `$pdo` como siempre.
if (!defined('STOCKIATE_SIN_CONEXION_AUTOMATICA')) {
    try {
        $pdo = conectar_mysql(STOCKIATE_BASE);
    } catch (PDOException $e) {
        // En modo demo el error más probable es que la base todavía no exista
        // (nadie corrió el seed). Decirlo evita mandar a alguien a revisar XAMPP
        // por algo que se arregla con un comando.
        $mensaje = STOCKIATE_MODO_DEMO
            ? "No se pudo conectar a la base de demo (" . STOCKIATE_BASE_DEMO . "). ¿Corriste `php seed_demo.php`?"
            : "Error de conexión a la base de datos";

        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, $mensaje . "\n");
        } else {
            http_response_code(500);
            echo json_encode(["ok" => false, "mensaje" => $mensaje]);
        }
        exit(1);
}
}
