<?php
/**
 * stockIAte - tareas_notificaciones.php
 * ================================
 * El "cron" del proyecto. Corre por línea de comandos, cada unos minutos,
 * desde el Programador de tareas de Windows:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\stockiate\tesis_enzo\tareas_notificaciones.php
 *
 * Hace tres cosas, en este orden y para cada negocio que tenga WhatsApp
 * activado:
 *
 *   1. DETECTA lo que no tiene un evento de usuario que lo dispare: lotes por
 *      vencer y el resumen del día. (El stock crítico no está acá: ese lo
 *      encola `registrar_venta.php` en el momento de la venta.)
 *   2. ENCOLA lo que encontró, deduplicado.
 *   3. VACÍA la cola: le pega a la API de Meta por cada notificación
 *      pendiente y marca el resultado.
 *
 * Es el ÚNICO lugar del sistema que le pega a Meta. Todo lo demás sólo
 * escribe en la tabla `notificaciones`.
 *
 * Corre sin sesión y a propósito recorre TODOS los negocios: es el único
 * código del proyecto que cruza el límite de tenant, porque tiene que
 * atenderlos a todos. Por eso no es accesible por HTTP (ver la guarda de acá
 * abajo) y por eso cada negocio se procesa con su propio `negocio_id`
 * explícito, nunca con una query sin filtrar.
 */

require_once __DIR__ . '/conexion.php';       // $pdo
require_once __DIR__ . '/alertas.php';        // lotes_por_vencer(), ventas_del_dia()...
require_once __DIR__ . '/notificaciones.php'; // la cola
require_once __DIR__ . '/whatsapp.php';       // el envío

// Sin esto, cualquiera con la URL podría hacer que se vacíe la cola cuando
// quiera. El `.htaccess` controla caché y bloquea `.env`/`.sql`, pero un
// `.php` en la raíz web se sirve igual: la guarda tiene que estar en el
// archivo.
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

/** Cuántas notificaciones se mandan por corrida. */
const LOTE_ENVIO = 20;

/** Después de tantos intentos fallidos se deja de reintentar. */
const MAX_INTENTOS = 3;

/**
 * Log a stdout. Cuando esto corre por el Programador de tareas no lo lee
 * nadie, pero cuando lo corrés a mano para averiguar por qué no llega un
 * mensaje es todo lo que tenés.
 */
function log_tarea(string $mensaje): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . "\n";
}

log_tarea('Arranca la corrida de notificaciones.');

// Sin credenciales igual se detecta y se encola: así no se pierde ningún
// aviso de las horas anteriores a que alguien complete el `.env`. Lo que no
// se hace es intentar el envío, más abajo.
if (!whatsapp_configurado()) {
    log_tarea('AVISO: falta WHATSAPP_TOKEN o WHATSAPP_PHONE_NUMBER_ID en el .env.');
    log_tarea('       Los avisos de WhatsApp se encolan igual y salen cuando cargues el token.');
}

if (!mail_configurado()) {
    log_tarea('AVISO: falta la configuración SMTP en el .env (ver probar_mail.php).');
    log_tarea('       Los avisos por mail se encolan igual y salen cuando la cargues.');
}

// ----------------------------------------------------------------------
// 1 y 2) Detectar y encolar, negocio por negocio
// ----------------------------------------------------------------------
try {
    $negocios = $pdo->query("SELECT id, nombre FROM negocios ORDER BY id")->fetchAll();
} catch (PDOException $e) {
    log_tarea('ERROR: no se pudo leer la lista de negocios: ' . $e->getMessage());
    exit(1);
}

$hoy = date('Y-m-d');

foreach ($negocios as $negocio) {
    $negocio_id = (int) $negocio['id'];
    $negocio_nombre = $negocio['nombre'] !== '' ? $negocio['nombre'] : 'tu negocio';

    $config = config_avisos($pdo, $negocio_id);

    if (!avisos_habilitados($config)) {
        continue; // ningún canal activo y bien configurado en este negocio
    }

    // --- Vencimientos --------------------------------------------------
    // Se avisa una vez por día como mucho: la clave de deduplicación lleva la
    // fecha. Si mañana sigue habiendo lotes por vencer, mañana vuelve a
    // avisar (que es lo que se quiere: es un recordatorio, no una novedad).
    try {
        $dias = dias_vencimiento_config($pdo, $negocio_id);
        $lotes = lotes_por_vencer($pdo, $negocio_id, $dias);

        if (count($lotes) > 0) {
            $primero = $lotes[0]; // vienen ordenados por fecha ascendente
            $detalle = $primero['nombre'] . ' (vence el '
                . date('d/m/Y', strtotime($primero['fecha_vencimiento'])) . ')';

            $encolado = encolar_notificacion(
                $pdo,
                $negocio_id,
                $config,
                'vencimientos',
                'vencimientos:' . $hoy,
                [$negocio_nombre, count($lotes), $dias, $detalle]
            );

            if ($encolado) {
                log_tarea("Negocio $negocio_id: encolado aviso de " . count($lotes) . ' lote(s) por vencer.');
            }
        }
    } catch (PDOException $e) {
        log_tarea("Negocio $negocio_id: error revisando vencimientos: " . $e->getMessage());
    }

    // --- Predicción de quiebre de stock ---------------------------------
    // Igual que vencimientos: un recordatorio diario mientras el riesgo siga
    // ahí, no un evento único. Sólo el más urgente -- si mandáramos uno por
    // cada producto en riesgo, un mal día de ventas podría encolar varios
    // WhatsApp de un saque.
    try {
        $enRiesgo = productos_en_riesgo_quiebre($pdo, $negocio_id);

        if (count($enRiesgo) > 0) {
            $primero = $enRiesgo[0]; // ya viene ordenado por urgencia (menos días primero)
            $frase = "{$primero['nombre']} se agotaría en ~{$primero['dias_restantes']} día(s) al ritmo de venta actual";

            $encolado = encolar_notificacion(
                $pdo,
                $negocio_id,
                $config,
                'prediccion_quiebre',
                'prediccion_quiebre:' . $primero['id'] . ':' . $hoy,
                [$negocio_nombre, $frase, $primero['stock_actual'], $primero['stock_minimo']]
            );

            if ($encolado) {
                log_tarea("Negocio $negocio_id: encolado aviso de riesgo de quiebre (" . count($enRiesgo) . ' producto(s) en riesgo).');
            }
        }
    } catch (PDOException $e) {
        log_tarea("Negocio $negocio_id: error calculando riesgo de quiebre: " . $e->getMessage());
    }

    // --- Resumen diario ------------------------------------------------
    // La comparación de 'HH:MM' como texto funciona porque las dos horas
    // están con cero adelante y en 24 h: '09:30' < '20:00' < '21:15'.
    try {
        if (date('H:i') >= $config['hora_resumen']) {
            $ventas = ventas_del_dia($pdo, $negocio_id);
            $criticos = count(productos_criticos($pdo, $negocio_id));

            $encolado = encolar_notificacion(
                $pdo,
                $negocio_id,
                $config,
                'resumen_diario',
                'resumen_diario:' . $hoy,
                [
                    $negocio_nombre,
                    date('d/m/Y'),
                    $ventas['unidades'],
                    number_format($ventas['monto'], 2, ',', '.'),
                    $criticos,
                ]
            );

            if ($encolado) {
                log_tarea("Negocio $negocio_id: encolado el resumen del día.");
            }
        }
    } catch (PDOException $e) {
        log_tarea("Negocio $negocio_id: error armando el resumen: " . $e->getMessage());
    }
}

// ----------------------------------------------------------------------
// 3) Vaciar la cola
// ----------------------------------------------------------------------
// Se procesan las pendientes de TODOS los negocios juntas, en orden de
// llegada. El `destino` ya viene resuelto en la fila (snapshot del teléfono
// al encolar), así que acá no hace falta volver a mirar la configuración.
// Sin credenciales no se intenta: cada intento fallido gasta uno de los 3
// que tiene cada notificación, y a la tercera queda 'fallida' para siempre.
// Los avisos encolados mientras faltaba el token tienen que poder salir
// cuando el token aparezca, no morir esperándolo.
// El filtro es POR CANAL y no un corte global. Antes, sin token de WhatsApp
// el script se iba sin vaciar nada; con dos canales eso significaría que un
// mail perfectamente enviable se queda en la cola porque falta una credencial
// de Meta que no tiene nada que ver.
$canales_enviables = [];
if (whatsapp_configurado()) {
    $canales_enviables[] = 'whatsapp';
}
if (mail_configurado()) {
    $canales_enviables[] = 'email';
}

if (count($canales_enviables) === 0) {
    log_tarea('No se vacía la cola: no hay credenciales de ningún canal. Fin.');
    exit(0);
}

log_tarea('Canales que se pueden enviar: ' . implode(', ', $canales_enviables) . '.');

try {
    // Los `?` del IN se arman según cuántos canales haya. No es interpolación
    // de datos del usuario: `$canales_enviables` sale de dos constantes de
    // este archivo, y los valores igual viajan como parámetros.
    $huecos = implode(', ', array_fill(0, count($canales_enviables), '?'));

    $stmtPendientes = $pdo->prepare(
        "SELECT id, negocio_id, tipo, canal, destino, plantilla, parametros, intentos
           FROM notificaciones
          WHERE estado = 'pendiente' AND intentos < ?
            AND canal IN ($huecos)
          ORDER BY creada_en ASC
          LIMIT " . LOTE_ENVIO
    );
    $stmtPendientes->execute(array_merge([MAX_INTENTOS], $canales_enviables));
    $pendientes = $stmtPendientes->fetchAll();
} catch (PDOException $e) {
    log_tarea('ERROR: no se pudo leer la cola: ' . $e->getMessage());
    log_tarea('       Si dice que la tabla no existe, falta correr');
    log_tarea('       migracion_notificaciones_whatsapp.sql.');
    exit(1);
}

if (count($pendientes) === 0) {
    log_tarea('No hay notificaciones pendientes. Fin.');
    exit(0);
}

log_tarea('Notificaciones pendientes: ' . count($pendientes));

$enviadas = 0;
$fallidas = 0;

foreach ($pendientes as $notificacion) {
    // El envío y el marcado de la fila viven en notificaciones.php, para que
    // el botón de prueba del panel recorra exactamente este mismo camino.
    $resultado = enviar_notificacion($pdo, $notificacion, MAX_INTENTOS);

    if ($resultado['ok']) {
        $enviadas++;
        log_tarea("  #{$notificacion['id']} ({$notificacion['tipo']}, {$notificacion['canal']}) -> enviada a {$notificacion['destino']}");
        continue;
    }

    $fallidas++;
    log_tarea("  #{$notificacion['id']} ({$notificacion['tipo']}, {$notificacion['canal']}) -> ERROR: " . $resultado['error']);
}

log_tarea("Fin. Enviadas: $enviadas. Con error: $fallidas.");
exit(0);
