<?php
/**
 * stockIAte - consultar_ipc_indec.php
 * ================================
 * Botón "Usar último IPC del INDEC" del módulo "Precios e inflación"
 * (precios_inflacion.html). Trae los últimos dos valores publicados del
 * índice y calcula la variación mensual en el servidor -- no se depende de
 * ningún sufijo de transformación de la API (":percent_change" o similar)
 * que no se pudo verificar con certeza; con el índice crudo alcanza.
 *
 * Serie: 148.3_INIVELNAL_DICI_M_26 -- "IPC. Nivel General Nacional. Base dic
 * 2016. Mensual.", la serie nacional (no la de GBA) publicada en
 * datos.gob.ar. Verificado a mano el 30/09/2026: con
 * `?ids=148.3_INIVELNAL_DICI_M_26&limit=2&sort=desc&format=json` la API
 * devuelve `data: [["2026-08-01", 12276.766], ["2026-07-01", ...]]`, el más
 * reciente primero.
 *
 * MISMO CRITERIO OFFLINE-FIRST QUE ROBOFLOW Y whatsapp.php
 * ----------------------------------------------------------
 * Es la primera vez que este proyecto sale a internet a algo que NO sea
 * WhatsApp o el chatbot, y es un dato opcional: si datos.gob.ar no responde
 * (sin internet, timeout, la API caída, o le cambian la forma a la
 * respuesta), esta pantalla NO se puede romper -- el dueño se queda con la
 * inflación cargada a mano. Por eso, igual que enviar_plantilla_whatsapp(),
 * esta función no lanza: cualquier fallo vuelve como `{"ok": false,
 * "mensaje": "..."}` con 200, nunca como excepción ni 5xx.
 *
 * No escribe nada en la base. El dueño confirma el valor al dejarlo en el
 * input de inflación mensual, que dispara el guardado normal de
 * preferencias (guardar_preferencias.php) -- acá sólo se consulta.
 *
 * Sólo rol 'dueño', igual que el resto del módulo.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)

cabeceras_json();
exigir_metodo('POST');

exigir_sesion(['dueño']);

const INDEC_SERIE_IPC_NACIONAL = '148.3_INIVELNAL_DICI_M_26';
const INDEC_TIMEOUT = 8;

$url = 'https://apis.datos.gob.ar/series/api/series/?ids=' . INDEC_SERIE_IPC_NACIONAL
    . '&limit=2&sort=desc&format=json';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => INDEC_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);

$respuesta = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($ch);
curl_close($ch);

if ($respuesta === false) {
    responder([
        'ok' => false,
        'mensaje' => 'No se pudo conectar con la API de datos.gob.ar: ' . $errorCurl,
    ]);
}

if ($http < 200 || $http >= 300) {
    responder([
        'ok' => false,
        'mensaje' => 'La API de datos.gob.ar respondió ' . $http . ', no se pudo traer el IPC',
    ]);
}

$datos = json_decode($respuesta, true);
$serie = $datos['data'] ?? null;

// Se necesitan al menos dos puntos (el último mes y el anterior) para poder
// calcular una variación. Si la serie viene incompleta, corta.
if (!is_array($serie) || count($serie) < 2 || !isset($serie[0][1], $serie[1][1])) {
    responder([
        'ok' => false,
        'mensaje' => 'La respuesta de datos.gob.ar no tiene el formato esperado',
    ]);
}

$valorUltimo = (float) $serie[0][1];
$valorAnterior = (float) $serie[1][1];

if ($valorAnterior <= 0) {
    responder([
        'ok' => false,
        'mensaje' => 'El valor anterior del índice no es válido para calcular una variación',
    ]);
}

$variacion = round(($valorUltimo / $valorAnterior - 1) * 100, 2);
// "2026-08-01" -> "2026-08": alcanza para mostrar de qué mes es el dato.
$periodo = is_string($serie[0][0] ?? null) ? substr($serie[0][0], 0, 7) : null;

responder([
    'ok' => true,
    'variacion' => $variacion,
    'periodo' => $periodo,
]);
