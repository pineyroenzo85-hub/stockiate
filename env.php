<?php
/**
 * stockIAte - env.php
 * ================================
 * Lector mínimo del archivo `.env` para el lado PHP.
 *
 * POR QUÉ EXISTE
 * --------------
 * La configuración del proyecto está partida entre capas: Python lee `.env`
 * con python-dotenv, y PHP hasta ahora no leía nada (las credenciales de
 * MySQL están hardcodeadas en `conexion.php`, que es aceptable porque son las
 * de XAMPP en local: root sin password, iguales en todas las máquinas).
 *
 * El token de WhatsApp es otra cosa: es un secreto de verdad, el repo tiene
 * remoto público, y `whatsapp.php` corre en PHP. Las opciones eran inventar
 * un segundo archivo de secretos sólo para PHP, o hacer que PHP lea el MISMO
 * `.env` que ya existe, ya está gitignoreado y ya es donde el proyecto guarda
 * sus keys. Esto último: un archivo de secretos, no dos.
 *
 * NO es un parser completo de dotenv. Soporta lo que este `.env` usa:
 * `CLAVE=valor`, líneas en blanco, comentarios con `#`, y comillas opcionales
 * alrededor del valor. No hace interpolación de variables (`${OTRA}`) ni
 * exporta a `getenv()`, a propósito: cuanto menos haga, menos hay que
 * mantener.
 *
 * Se lee una sola vez por request y queda cacheado en una `static`.
 */

/**
 * Devuelve el valor de una clave del `.env`, o `$default` si no está o está
 * vacía. Un valor vacío se trata como ausente: en la plantilla `.env.example`
 * las claves figuran como `WHATSAPP_TOKEN=`, y eso significa "sin configurar",
 * no "cadena vacía".
 */
function leer_env(string $clave, ?string $default = null): ?string
{
    static $valores = null;

    if ($valores === null) {
        $valores = cargar_env(__DIR__ . '/.env');
    }

    $valor = $valores[$clave] ?? '';

    return $valor === '' ? $default : $valor;
}

/**
 * Parsea el archivo y devuelve el mapa clave => valor. Si el archivo no
 * existe devuelve un array vacío en vez de fallar: la ausencia de `.env` no
 * puede voltear a los endpoints que no lo necesitan (mismo criterio lazy que
 * usa chatbot_ia.py con GROQ_API_KEY).
 */
function cargar_env(string $ruta): array
{
    if (!is_readable($ruta)) {
        return [];
    }

    $valores = [];

    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);

        if ($linea === '' || $linea[0] === '#') {
            continue;
        }

        $partes = explode('=', $linea, 2); // limit 2: el valor puede tener '='
        if (count($partes) !== 2) {
            continue;
        }

        $clave = trim($partes[0]);
        $valor = trim($partes[1]);

        // Comillas opcionales alrededor del valor.
        $largo = strlen($valor);
        if ($largo >= 2) {
            $primera = $valor[0];
            if (($primera === '"' || $primera === "'") && $valor[$largo - 1] === $primera) {
                $valor = substr($valor, 1, -1);
            }
        }

        $valores[$clave] = $valor;
    }

    return $valores;
}
