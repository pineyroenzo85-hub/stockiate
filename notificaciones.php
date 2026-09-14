<?php
/**
 * stockIAte - notificaciones.php
 * ================================
 * La cola de avisos: encolar, deduplicar y leer la configuración de WhatsApp
 * de cada negocio.
 *
 * Nadie manda un WhatsApp en el momento en que pasa la cosa. Los disparadores
 * (`registrar_venta.php` cuando un producto queda bajo el mínimo,
 * `tareas_notificaciones.php` cuando detecta vencimientos) sólo insertan una
 * fila en `notificaciones`, y el script programado la vacía después. El
 * porqué completo está en `migracion_notificaciones_whatsapp.sql`; el resumen
 * es que una venta no puede depender de que la API de Meta conteste.
 *
 * La deduplicación es lo que hace esto usable en la práctica: sin ella,
 * vender diez unidades de a una manda diez veces el mismo aviso de stock
 * crítico y el dueño silencia el chat a la media hora.
 */

require_once __DIR__ . '/whatsapp.php'; // normalizar_telefono()
require_once __DIR__ . '/mailer.php'; // enviar_mail()
require_once __DIR__ . '/configuracion.php'; // leer_configs()

/**
 * Qué plantilla de Meta corresponde a cada tipo de aviso.
 *
 * `stock_critico`, `sin_stock` y `prediccion_quiebre` comparten plantilla a
 * propósito: cambia el texto de la variable {{2}}, no la estructura del
 * mensaje. Cada plantilla nueva es una aprobación más que esperar de Meta,
 * y los tres avisos tienen la misma forma (negocio, frase, stock, mínimo)
 * con distinta urgencia/motivo.
 *
 * Los nombres tienen que coincidir EXACTAMENTE con los del Administrador de
 * plantillas de Meta, o el envío falla con "Template name does not exist".
 */
const PLANTILLA_POR_TIPO = [
    'stock_critico' => 'alerta_stock',
    'sin_stock' => 'alerta_stock',
    // Producto que TODAVÍA no está en stock_critico pero que al ritmo de
    // venta actual se agota pronto -- ver productos_en_riesgo_quiebre() en
    // alertas.php. Reusa la misma plantilla aprobada que stock_critico.
    'prediccion_quiebre' => 'alerta_stock',
    'vencimientos' => 'alerta_vencimientos',
    'resumen_diario' => 'resumen_diario',
    'prueba' => 'alerta_vencimientos',
    'prueba_simple' => 'prueba_simple',
];

/** Valores por defecto si el negocio no tiene las filas de configuración. */
const CONFIG_WHATSAPP_DEFAULT = [
    'telefono' => '',
    'activo' => false,
    'hora_resumen' => '20:00',
    'email' => '',
    'email_activo' => false,
];

/**
 * Los canales por los que puede salir un aviso.
 *
 * SON INDEPENDIENTES, no alternativas. Un negocio puede tener los dos, uno o
 * ninguno, y cada envío deja su propia fila con su estado, sus intentos y su
 * error. Que el mail salga no dice nada sobre el WhatsApp, y al revés.
 *
 * El mail se agregó porque WhatsApp, estando entero, no manda nada: las tres
 * plantillas nunca se aprobaron en Meta y todo vuelve con
 * `(#132001) Template name does not exist`. Un mail no necesita que nadie lo
 * apruebe. Pero WhatsApp no se sacó: el día que las plantillas estén, empieza
 * a andar sin tocar código.
 */
const CANALES_AVISO = ['whatsapp', 'email'];

/**
 * Lee la configuración de avisos de un negocio (LOS DOS CANALES) desde
 * `configuracion`.
 *
 * Cae a los defaults si faltan filas, con el mismo criterio que ya usaba
 * `consultar_vencimientos.php` para `umbral_dias_vencimiento`: una base
 * migrada desde una versión anterior no tiene por qué reventar.
 *
 * @return array{telefono: string, activo: bool, hora_resumen: string,
 *               email: string, email_activo: bool}
 */
function config_avisos(PDO $pdo, int $negocio_id): array
{
    $filas = leer_configs($pdo, $negocio_id, [
        'whatsapp_telefono',
        'whatsapp_activo',
        'whatsapp_hora_resumen',
        'email_destino',
        'email_activo',
    ]);

    return [
        'telefono' => $filas['whatsapp_telefono'] ?? CONFIG_WHATSAPP_DEFAULT['telefono'],
        'activo' => ($filas['whatsapp_activo'] ?? '0') === '1',
        // El segundo canal. Va en el MISMO array y no en uno aparte para que
        // `encolar_notificacion()` reciba una sola cosa: quien encola no
        // decide por dónde sale el aviso, sólo dice qué pasó.
        'email' => $filas['email_destino'] ?? CONFIG_WHATSAPP_DEFAULT['email'],
        'email_activo' => ($filas['email_activo'] ?? '0') === '1',
        // Una hora vacía en la base cae al default en vez de romper el
        // parseo: `''` compararía como menor que cualquier hora y el resumen
        // saldría a cualquier hora del día.
        'hora_resumen' => ($filas['whatsapp_hora_resumen'] ?? '') !== ''
            ? $filas['whatsapp_hora_resumen']
            : CONFIG_WHATSAPP_DEFAULT['hora_resumen'],
    ];
}

/**
 * Cada cuántas horas este negocio acepta que se repita el aviso de un MISMO
 * producto en stock crítico. Configurable desde el panel; el default vive en
 * `CONFIG_INICIAL_NEGOCIO`.
 *
 * SÓLO APLICA A LOS AVISOS DISPARADOS POR UNA VENTA
 * ------------------------------------------------
 * Los avisos diarios (`vencimientos`, `prediccion_quiebre`, `resumen_diario`)
 * llevan la fecha en su `clave_dedup` ('resumen_diario:2026-09-05'), así que
 * ya son "uno por día" por construcción y la ventana es sólo la baranda que
 * evita que las corridas del cron de ese mismo día lo repitan. Pasarles esta
 * configuración sería una trampa: con la ventana en 6 h, el resumen diario
 * saldría tres veces por noche. Por eso `tareas_notificaciones.php` sigue
 * usando el default de 24 h y el único que lee esta función es
 * `registrar_venta.php`, donde la clave NO lleva fecha ('stock_critico:42')
 * y diez ventas del mismo producto son diez avisos idénticos.
 */
function ventana_notificaciones_config(PDO $pdo, int $negocio_id): int
{
    return leer_config_int($pdo, $negocio_id, 'ventana_notificaciones_horas');
}

/**
 * ¿Este negocio quiere recibir avisos? Activado y con un teléfono que se
 * pueda interpretar.
 *
 * A propósito NO mira `whatsapp_configurado()` (las credenciales del `.env`).
 * Son dos preguntas distintas y se arreglan en lugares distintos: que el
 * servidor no tenga token todavía es un problema de instalación, y si por eso
 * dejáramos de encolar, los avisos de ese rato se perderían para siempre. Se
 * encolan igual y quedan esperando; el que decide no gastar intentos contra
 * una API sin credenciales es `tareas_notificaciones.php`, que directamente
 * no vacía la cola hasta que el token exista.
 */
function whatsapp_habilitado(array $config): bool
{
    return $config['activo'] && normalizar_telefono($config['telefono']) !== null;
}

/**
 * Lo mismo para el mail: activado y con una dirección que sea una dirección.
 *
 * Igual que su par de WhatsApp, NO mira `mail_configurado()` (el SMTP del
 * `.env`). Son dos preguntas que se arreglan en lugares distintos y por
 * personas distintas: la casilla del negocio la carga el dueño desde el
 * panel, el SMTP lo carga quien instala el sistema. Si por falta de SMTP no
 * encoláramos, los avisos de ese rato se perderían para siempre.
 */
function email_habilitado(array $config): bool
{
    return !empty($config['email_activo'])
        && filter_var((string) ($config['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * ¿Este negocio quiere recibir avisos por ALGÚN canal?
 *
 * Es la guarda que usan `registrar_venta.php` y `tareas_notificaciones.php`
 * antes de gastar queries detectando cosas que no le va a contar a nadie.
 * Antes esa guarda era `whatsapp_habilitado()` a secas, y dejarla así hubiera
 * significado que un negocio con SÓLO el mail activado no recibe nada — el
 * bug más fácil de cometer al sumar un canal, y el más difícil de ver.
 */
function avisos_habilitados(array $config): bool
{
    return whatsapp_habilitado($config) || email_habilitado($config);
}

/**
 * A qué canales hay que mandarle este aviso, con el destino ya resuelto.
 * Devuelve `['whatsapp' => '549115...', 'email' => 'x@y.com']` con sólo los
 * canales activos y bien configurados.
 */
function destinos_activos(array $config): array
{
    $destinos = [];

    if (whatsapp_habilitado($config)) {
        $destinos['whatsapp'] = normalizar_telefono($config['telefono']);
    }

    if (email_habilitado($config)) {
        $destinos['email'] = trim((string) $config['email']);
    }

    return $destinos;
}

/**
 * Nombre del negocio, para encabezar los mensajes.
 *
 * No sale de `$_SESSION` (que guarda el `negocio_id` pero no el nombre)
 * porque el script programado corre sin sesión ninguna. Devuelve 'tu negocio'
 * si el id no existe, para que un mensaje nunca salga con un hueco.
 */
function nombre_negocio(PDO $pdo, int $negocio_id): string
{
    $stmt = $pdo->prepare("SELECT nombre FROM negocios WHERE id = :id");
    $stmt->execute([':id' => $negocio_id]);
    $nombre = $stmt->fetchColumn();

    return $nombre !== false && $nombre !== '' ? (string) $nombre : 'tu negocio';
}

/**
 * Mete un aviso en la cola, si no se avisó lo mismo hace poco.
 *
 * `$clave_dedup` identifica el HECHO, no la fila: 'stock_critico:42' es "el
 * producto 42 está en crítico". Mientras haya una fila con esa clave dentro
 * de `$ventana_horas`, no se encola otra.
 *
 * @param array    $config      lo que devuelve config_avisos() (se pasa en
 *                              vez de releerlo para no hacer una query por
 *                              cada ítem de un ticket)
 * @param string[] $parametros  variables {{1}}..{{n}} de la plantilla
 *
 * UNA FILA POR CANAL ACTIVO
 * -------------------------
 * Quien llama no elige el canal: dice qué pasó, y acá se decide por dónde
 * sale. Si el negocio tiene los dos activos se encolan dos filas, cada una
 * con su estado, sus intentos y su error propios.
 *
 * Y LA DEDUPLICACIÓN ES POR CANAL. Es lo que parece un detalle y no lo es: si
 * la clave no llevara el canal, la fila del WhatsApp deduplicaría a la del
 * mail y activar el segundo canal no mandaría nada. El síntoma sería "activé
 * el mail y no llega", sin ningún error en ningún lado.
 *
 * @return int[] los ids creados (vacío si estaba desactivado, mal configurado
 *               o deduplicado). Devuelve array y no un id porque ahora pueden
 *               ser dos; un array vacío sigue siendo falsy, así que los
 *               `if ($encolado)` de quien llama siguen valiendo. Nunca lanza.
 */
function encolar_notificacion(
    PDO $pdo,
    int $negocio_id,
    array $config,
    string $tipo,
    string $clave_dedup,
    array $parametros,
    int $ventana_horas = 24
): array {
    if (!isset(PLANTILLA_POR_TIPO[$tipo])) {
        return [];
    }

    $destinos = destinos_activos($config);

    if (count($destinos) === 0) {
        return [];
    }

    $ids = [];

    try {
        // Deduplicación. No hace falta lockear: dos ventas simultáneas del
        // mismo producto que empaten acá dejarían dos filas, y el costo de
        // eso es un aviso repetido. Bloquear la tabla en el camino caliente
        // de la venta costaría bastante más.
        $stmtDedup = $pdo->prepare(
            "SELECT id FROM notificaciones
              WHERE negocio_id = :negocio_id
                AND clave_dedup = :clave_dedup
                AND canal = :canal
                AND creada_en >= DATE_SUB(NOW(), INTERVAL :horas HOUR)
              LIMIT 1"
        );

        $stmt = $pdo->prepare(
            "INSERT INTO notificaciones
                (negocio_id, tipo, canal, clave_dedup, destino, plantilla, parametros)
             VALUES
                (:negocio_id, :tipo, :canal, :clave_dedup, :destino, :plantilla, :parametros)"
        );

        foreach ($destinos as $canal => $destino) {
            $stmtDedup->execute([
                ':negocio_id' => $negocio_id,
                ':clave_dedup' => $clave_dedup,
                ':canal' => $canal,
                ':horas' => $ventana_horas,
            ]);

            if ($stmtDedup->fetchColumn() !== false) {
                continue;
            }

            $stmt->execute([
                ':negocio_id' => $negocio_id,
                ':tipo' => $tipo,
                ':canal' => $canal,
                ':clave_dedup' => $clave_dedup,
                ':destino' => $destino,
                ':plantilla' => PLANTILLA_POR_TIPO[$tipo],
                ':parametros' => json_encode(array_values($parametros), JSON_UNESCAPED_UNICODE),
            ]);

            $ids[] = (int) $pdo->lastInsertId();
        }

        return $ids;
    } catch (PDOException $e) {
        // Encolar un aviso NUNCA puede voltear la operación que lo disparó
        // (una venta, por ejemplo). Si la tabla no existe todavía porque no
        // se corrió la migración, esto se traga el error y el sistema sigue
        // funcionando exactamente como antes de que existieran los avisos.
        //
        // Se devuelven los ids que SÍ se alcanzaron a escribir: si el negocio
        // tiene los dos canales y el segundo INSERT falla, el primero ya está
        // encolado y tiene que poder salir.
        return $ids;
    }
}

/**
 * Las últimas notificaciones del negocio, para el panel de diagnóstico.
 */
function notificaciones_recientes(PDO $pdo, int $negocio_id, int $limite = 10): array
{
    $stmt = $pdo->prepare(
        "SELECT id, tipo, destino, plantilla, parametros, estado, intentos,
                error, creada_en, enviada_en
           FROM notificaciones
          WHERE negocio_id = :negocio_id
          ORDER BY id DESC
          LIMIT " . (int) $limite
    );
    $stmt->execute([':negocio_id' => $negocio_id]);

    return $stmt->fetchAll();
}

/**
 * Manda UNA notificación de la cola y anota el resultado en su fila.
 *
 * Lo usan los dos que necesitan enviar: el script programado (que vacía la
 * cola de a lotes) y el botón "Mandar mensaje de prueba" del panel (que
 * encola una y la manda en el acto). Que compartan esta función es lo que
 * hace que la prueba valga: recorre el mismo camino que un aviso real, así
 * que si la prueba llega, las alertas también.
 *
 * El estado que queda cuando falla depende de si sobran intentos:
 * 'pendiente' se reintenta en la corrida siguiente (un token vencido o
 * quedarse sin internet son cosas que se arreglan solas o a mano), y
 * 'fallida' es definitivo.
 *
 * @param array $fila con id, canal, tipo, destino, plantilla, parametros
 * @return array{ok: bool, wamid: ?string, error: ?string, http: int}
 */
function enviar_notificacion(PDO $pdo, array $fila, int $max_intentos = 3): array
{
    $parametros = json_decode($fila['parametros'], true);
    if (!is_array($parametros)) {
        $parametros = [];
    }

    // ── Acá se bifurca el canal ──────────────────────────────────────────
    // Lo de abajo (contar intentos, marcar 'enviada'/'fallida', guardar el
    // error) es idéntico para los dos y por eso no se duplica: lo único que
    // cambia es a quién se le pega. Un canal nuevo se suma acá y hereda toda
    // la política de reintentos sin tocarla.
    //
    // `canal` puede faltar si la fila es anterior a
    // `migracion_notificaciones_email.sql`: esas son todas de WhatsApp.
    $canal = $fila['canal'] ?? 'whatsapp';

    if ($canal === 'email') {
        [$asunto, $html, $texto] = armar_mail_notificacion(
            (string) ($fila['tipo'] ?? ''),
            $parametros
        );

        $envio = enviar_mail($fila['destino'], '', $asunto, $html, $texto);

        // Se normaliza a la misma forma que devuelve WhatsApp para que quien
        // llama no tenga que preguntar por el canal. `wamid` es un id de Meta
        // y en mail no existe: va null, no una cadena inventada.
        $resultado = [
            'ok' => $envio['ok'],
            'wamid' => null,
            'error' => $envio['error'],
            'http' => $envio['ok'] ? 200 : 0,
        ];
    } else {
        $resultado = enviar_plantilla_whatsapp($fila['destino'], $fila['plantilla'], $parametros, 'es_AR');
    }

    if ($resultado['ok']) {
        $pdo->prepare(
            "UPDATE notificaciones
                SET estado = 'enviada', wamid = :wamid, intentos = intentos + 1,
                    enviada_en = NOW(), error = NULL
              WHERE id = :id"
        )->execute([':wamid' => $resultado['wamid'], ':id' => $fila['id']]);

        return $resultado;
    }

    $pdo->prepare(
        "UPDATE notificaciones
            SET estado = CASE WHEN intentos + 1 >= :max_intentos THEN 'fallida' ELSE 'pendiente' END,
                intentos = intentos + 1,
                error = :error
          WHERE id = :id"
    )->execute([
        ':max_intentos' => $max_intentos,
        ':error' => $resultado['error'],
        ':id' => $fila['id'],
    ]);

    return $resultado;
}

// ======================================================================
// EL AVISO, EN VERSIÓN MAIL
// ======================================================================
// Vive acá y no en un archivo aparte por el mismo motivo por el que acá vive
// `PLANTILLA_POR_TIPO`: las dos cosas traducen el MISMO array de parámetros
// posicionales, y tienen que moverse juntas. Si alguien agrega un parámetro a
// un aviso y sólo toca una de las dos, el otro canal manda un mensaje con un
// hueco — y nadie se entera hasta que un dueño lo lee.
//
// ESE ACOPLE ES LA DEUDA DE ESTE DISEÑO Y CONVIENE TENERLA A LA VISTA: los
// parámetros son posicionales porque la Cloud API de Meta los quiere así
// ({{1}}, {{2}}, ...). Un mail no los necesita posicionales, pero cambiarlos a
// un array asociativo obligaba a tocar `registrar_venta.php`,
// `tareas_notificaciones.php` y `probar_notificacion.php`, y a migrar el JSON
// de las filas que ya están encoladas. Se dejó como está, documentado.

/**
 * Qué significa cada posición de `parametros`, por tipo de aviso.
 *
 * Es la misma información que ya estaba implícita en las plantillas de Meta,
 * escrita donde se puede leer. La lista describe el orden en que cada
 * disparador arma el array: ver `registrar_venta.php` (stock) y
 * `tareas_notificaciones.php` (el resto).
 */
const CAMPOS_POR_TIPO = [
    'stock_critico'      => ['negocio', 'frase', 'stock', 'minimo'],
    'sin_stock'          => ['negocio', 'frase', 'stock', 'minimo'],
    'prediccion_quiebre' => ['negocio', 'frase', 'stock', 'minimo'],
    'vencimientos'       => ['negocio', 'cantidad', 'dias', 'detalle'],
    'prueba'             => ['negocio', 'cantidad', 'dias', 'detalle'],
    'resumen_diario'     => ['negocio', 'fecha', 'unidades', 'monto', 'criticos'],
];

/** Asunto del mail por tipo. Sin el nombre del negocio: se lo agrega abajo. */
const ASUNTO_POR_TIPO = [
    'stock_critico'      => 'Queda poco stock',
    'sin_stock'          => 'Un producto se quedó sin stock',
    'prediccion_quiebre' => 'Un producto se va a agotar pronto',
    'vencimientos'       => 'Productos próximos a vencer',
    'prueba'             => 'Aviso de prueba',
    'resumen_diario'     => 'Resumen del día',
];

/**
 * Convierte `parametros` (posicional) en un array con nombres, según el tipo.
 * Las posiciones que falten quedan en cadena vacía en vez de romper: un aviso
 * al que le falta un dato tiene que salir igual, con el hueco a la vista, y no
 * quedarse trabado en la cola.
 */
function campos_de_aviso(string $tipo, array $parametros): array
{
    $nombres = CAMPOS_POR_TIPO[$tipo] ?? [];
    $campos = [];

    foreach ($nombres as $i => $nombre) {
        $campos[$nombre] = (string) ($parametros[$i] ?? '');
    }

    return $campos;
}

/**
 * Arma el mail de un aviso: `[asunto, html, texto]`, tal como los quiere
 * `enviar_mail()`.
 *
 * Un tipo desconocido NO devuelve vacío: cae a listar los parámetros crudos.
 * Es fiero, pero el aviso llega y se entiende qué pasó — que es exactamente lo
 * que hace falta el día que alguien agrega un tipo y se olvida de este
 * archivo. Quedarse callado sería la peor opción.
 */
function armar_mail_notificacion(string $tipo, array $parametros): array
{
    $c = campos_de_aviso($tipo, $parametros);
    $negocio = $c['negocio'] ?? (string) ($parametros[0] ?? 'tu negocio');

    $asunto = (ASUNTO_POR_TIPO[$tipo] ?? 'Aviso de stockIAte') . ' - ' . $negocio;

    switch ($tipo) {
        case 'stock_critico':
        case 'sin_stock':
        case 'prediccion_quiebre':
            $titulo = $tipo === 'sin_stock' ? 'Sin stock' : 'Stock bajo';
            $lineas = [
                $c['frase'] . '.',
                'Quedan <b>' . $c['stock'] . '</b> unidades (el mínimo configurado es '
                    . $c['minimo'] . ').',
            ];
            break;

        case 'vencimientos':
        case 'prueba':
            $titulo = 'Productos próximos a vencer';
            $lineas = [
                'Hay <b>' . $c['cantidad'] . '</b> lote(s) que vencen en los próximos '
                    . $c['dias'] . ' días.',
                'El más próximo: ' . $c['detalle'] . '.',
            ];
            break;

        case 'resumen_diario':
            $titulo = 'Resumen del ' . $c['fecha'];
            $lineas = [
                'Vendiste <b>' . $c['unidades'] . '</b> unidades por <b>$'
                    . $c['monto'] . '</b>.',
                'Productos con stock crítico: <b>' . $c['criticos'] . '</b>.',
            ];
            break;

        default:
            $titulo = 'Aviso';
            $lineas = array_map(
                fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
                $parametros
            );
    }

    return [
        $asunto,
        cuerpo_html_aviso($negocio, $titulo, $lineas),
        cuerpo_texto_aviso($negocio, $titulo, $lineas),
    ];
}

/**
 * El HTML del mail. Estilos inline: los clientes de mail descartan el `<head>`
 * entero (mismo criterio que `armar_mail_reset()` en reset_password.php).
 *
 * Las `$lineas` pueden traer `<b>`, así que NO se escapan acá: ya vienen
 * armadas por `armar_mail_notificacion()`, que es el único que las construye y
 * que escapa lo que no controla en el caso `default`. Los datos de los casos
 * conocidos son números y frases que arma el propio sistema.
 */
function cuerpo_html_aviso(string $negocio, string $titulo, array $lineas): string
{
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;'
        . 'color:#1f2937;line-height:1.6;max-width:520px;">'
        . '<p style="font-size:22px;font-weight:700;margin:0 0 4px;">stockIAte</p>'
        . '<p style="margin:0 0 24px;color:#6b7280;font-size:13px;">'
        . htmlspecialchars($negocio, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:17px;font-weight:600;margin:0 0 12px;">'
        . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</p>';

    foreach ($lineas as $linea) {
        $html .= '<p style="margin:0 0 8px;">' . $linea . '</p>';
    }

    return $html
        . '<p style="font-size:13px;color:#6b7280;margin-top:24px;">'
        . 'Este aviso lo manda stockIAte automáticamente. Podés desactivarlo o '
        . 'cambiar la casilla desde Preferencias del negocio, en el panel.</p>'
        . '</div>';
}

/** La misma cosa en texto plano. Un mail sólo-HTML suma puntos de spam. */
function cuerpo_texto_aviso(string $negocio, string $titulo, array $lineas): string
{
    $texto = "stockIAte - $negocio\n\n$titulo\n\n";

    foreach ($lineas as $linea) {
        $texto .= '- ' . trim(strip_tags($linea)) . "\n";
    }

    return $texto
        . "\nEste aviso lo manda stockIAte automáticamente. Podés desactivarlo "
        . "o cambiar la casilla desde Preferencias del negocio, en el panel.\n";
}
