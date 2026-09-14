<?php
/**
 * stockIAte - whatsapp.php
 * ================================
 * Lo único del proyecto que habla con la API de Meta. Manda una plantilla
 * aprobada a un número, y nada más.
 *
 * POR QUÉ SIEMPRE PLANTILLAS Y NUNCA TEXTO LIBRE
 * ----------------------------------------------
 * WhatsApp sólo deja mandar texto libre dentro de las 24 h posteriores a que
 * el usuario te escriba a vos. Fuera de esa ventana --que es siempre nuestro
 * caso, porque acá el que inicia la conversación es el sistema-- hay que usar
 * una plantilla previamente aprobada por Meta, con las variables cargadas
 * aparte. De ahí la forma de la función: nombre de plantilla + array de
 * parámetros posicionales ({{1}}, {{2}}, ...).
 *
 * OJO: esto NO es la app "WhatsApp Business" del celular, que no tiene API.
 * Es la WhatsApp Business Platform (Cloud API). Ver .env.example y CLAUDE.md.
 *
 * PRIMERA LLAMADA HTTP SALIENTE DESDE PHP
 * ---------------------------------------
 * Hasta ahora el tráfico entre servicios iba en un solo sentido (Python ->
 * PHP, con `requests`). Éste es el primer PHP que sale a internet, así que
 * copia el criterio de chatbot_ia.py: timeout corto y explícito, y el error
 * se devuelve como dato, no como excepción.
 *
 * Ninguna función de acá lanza: devuelven un array con `ok`. Quien llama
 * (`tareas_notificaciones.php`) tiene que poder guardar el error en la cola y
 * seguir con la notificación siguiente, no cortarse.
 */

require_once __DIR__ . '/env.php';

/**
 * Versión de la Graph API a la que se le pega. Se puede pisar desde el `.env`
 * porque Meta deprecia versiones cada tanto y no hace falta tocar código.
 */
const WHATSAPP_API_VERSION_DEFAULT = 'v23.0';

/** Timeout de la llamada a Meta, en segundos. */
const WHATSAPP_TIMEOUT = 10;

/**
 * ¿Está configurado el envío? Se usa para no encolar notificaciones que no
 * tienen forma de salir, y para que el panel muestre el motivo.
 */
function whatsapp_configurado(): bool
{
    return leer_env('WHATSAPP_TOKEN') !== null
        && leer_env('WHATSAPP_PHONE_NUMBER_ID') !== null;
}

/**
 * Normaliza un teléfono al formato que quiere Meta: E.164 **sin** el '+',
 * sólo dígitos. Devuelve null si no se puede interpretar.
 *
 * Para Argentina hay dos trampas clásicas, y las dos hacen que el mensaje se
 * acepte con un 200 de Meta y NUNCA llegue (que es el peor modo de falla
 * posible, porque no hay error que mirar):
 *
 *   * Falta el 9. WhatsApp exige `54 9 <área> <número>` para los móviles.
 *     Sin el 9 el número existe pero no es el de WhatsApp.
 *   * Sobra el 15. Es un prefijo de discado NACIONAL: cuando ya pusiste el
 *     54 y el 9, el 15 no va. `011 15-5555-4444` es `5491155554444`.
 *
 * Un número que ya venga con código de país distinto de 54 se pasa tal cual:
 * si alguien carga un celular de otro país sabe lo que está haciendo.
 */
function normalizar_telefono(string $telefono): ?string
{
    $digitos = preg_replace('/\D+/', '', $telefono);

    if ($digitos === '') {
        return null;
    }

    // Prefijo internacional discado (00 54 ...) -> se cae, el '+' es implícito.
    if (str_starts_with($digitos, '00')) {
        $digitos = substr($digitos, 2);
    }

    // Código de país de otro lado: se confía en lo que cargó el usuario.
    if (strlen($digitos) > 10 && !str_starts_with($digitos, '54') && !str_starts_with($digitos, '0')) {
        return $digitos;
    }

    // A partir de acá reducimos a "número nacional argentino de 10 dígitos"
    // (área + abonado) y después le anteponemos el 54 9 nosotros.
    if (str_starts_with($digitos, '54')) {
        $digitos = substr($digitos, 2);
    }
    if (str_starts_with($digitos, '9')) {
        $digitos = substr($digitos, 1);
    }
    // Prefijo de larga distancia nacional (0 11 ...).
    if (str_starts_with($digitos, '0')) {
        $digitos = substr($digitos, 1);
    }

    // El 15 va pegado después del código de área, y el área tiene 2, 3 o 4
    // dígitos (11 es el único de 2). Con el 15 el número queda en 12 dígitos;
    // sin él, en 10.
    if (strlen($digitos) === 12) {
        foreach ([2, 3, 4] as $largoArea) {
            if (substr($digitos, $largoArea, 2) === '15') {
                $digitos = substr($digitos, 0, $largoArea) . substr($digitos, $largoArea + 2);
                break;
            }
        }
    }

    // Todo número nacional argentino tiene 10 dígitos. Si no llegamos a eso,
    // preferimos devolver null y que el panel avise, antes que mandar a un
    // número inventado.
    if (strlen($digitos) !== 10) {
        return null;
    }

    return '549' . $digitos;
}

/**
 * Manda una plantilla aprobada.
 *
 * @param string   $telefono   destino, ya normalizado (E.164 sin '+')
 * @param string   $plantilla  nombre exacto de la plantilla en Meta
 * @param string[] $parametros variables {{1}}..{{n}}, en orden
 * @param string   $idioma     código de idioma de la plantilla ('es', 'es_AR'...)
 *
 * @return array{ok: bool, wamid: ?string, error: ?string, http: int}
 */
function enviar_plantilla_whatsapp(
    string $telefono,
    string $plantilla,
    array $parametros = [],
    string $idioma = 'es'
): array {
    $token = leer_env('WHATSAPP_TOKEN');
    $phoneNumberId = leer_env('WHATSAPP_PHONE_NUMBER_ID');
    $version = leer_env('WHATSAPP_API_VERSION', WHATSAPP_API_VERSION_DEFAULT);

    if ($token === null || $phoneNumberId === null) {
        return [
            'ok' => false,
            'wamid' => null,
            'error' => 'Falta WHATSAPP_TOKEN o WHATSAPP_PHONE_NUMBER_ID en el .env',
            'http' => 0,
        ];
    }

    // Los parámetros van como texto, siempre. Meta rechaza los que tengan
    // saltos de línea o tabs, así que se aplastan acá en vez de confiar en
    // que el que llama se acuerde.
    $componentes = [];
    if (count($parametros) > 0) {
        $limpios = [];
        foreach (array_values($parametros) as $valor) {
            $limpios[] = [
                'type' => 'text',
                'text' => preg_replace('/\s+/u', ' ', trim((string) $valor)),
            ];
        }
        $componentes[] = ['type' => 'body', 'parameters' => $limpios];
    }

    $cuerpo = [
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'template',
        'template' => [
            'name' => $plantilla,
            'language' => ['code' => $idioma],
        ],
    ];
    if (count($componentes) > 0) {
        $cuerpo['template']['components'] = $componentes;
    }

    $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => WHATSAPP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
    ]);

    $respuesta = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorCurl = curl_error($ch);
    curl_close($ch);

    // No llegamos a Meta (sin internet, DNS, timeout). Es reintentable.
    if ($respuesta === false) {
        return [
            'ok' => false,
            'wamid' => null,
            'error' => 'No se pudo conectar con la API de WhatsApp: ' . $errorCurl,
            'http' => 0,
        ];
    }

    $datos = json_decode($respuesta, true);

    if ($http >= 200 && $http < 300 && isset($datos['messages'][0]['id'])) {
        return [
            'ok' => true,
            'wamid' => $datos['messages'][0]['id'],
            'error' => null,
            'http' => $http,
        ];
    }

    // Meta contesta los errores con {"error": {"message": ..., "code": ...}}.
    // Se guarda el mensaje tal cual: es lo que después se muestra en el panel,
    // y suele decir exactamente qué falta (plantilla no aprobada, número fuera
    // de la lista de prueba, token vencido).
    $mensaje = $datos['error']['message'] ?? ('Respuesta inesperada de WhatsApp: ' . substr($respuesta, 0, 300));
    if (isset($datos['error']['code'])) {
        $mensaje .= ' (código ' . $datos['error']['code'] . ')';
    }

    return ['ok' => false, 'wamid' => null, 'error' => $mensaje, 'http' => $http];
}
