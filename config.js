/**
 * stockIAte - config.js
 * ================================
 * Única fuente de las URLs del frontend. Antes cada archivo declaraba las
 * suyas: había 18 constantes con el host de ngrok hardcodeado repartidas
 * entre login.html, registro.html, cajero.html, repositor.html,
 * inventario_tabla.js y chatbot_widget.js, más el header anti-warning de
 * ngrok duplicado en 6 scopes distintos.
 *
 * POR QUÉ LOS PHP SE LLAMAN CON RUTAS RELATIVAS
 * ---------------------------------------------
 * La sesión de servidor (ver sesion.php) viaja en una cookie. Una cookie sólo
 * se manda al mismo origen que la puso, y con `Access-Control-Allow-Origin: *`
 * el navegador ni siquiera acepta mandarla. Como las páginas y los endpoints
 * PHP los sirve el mismo Apache, llamarlos por ruta relativa los vuelve
 * automáticamente mismo origen: la cookie viaja sola, no hay preflight y no
 * hay que mantener ninguna lista de dominios. Además la app deja de romperse
 * cada vez que ngrok cambia de subdominio.
 *
 * Lo único que sigue necesitando una URL absoluta es el servicio de IA
 * (FastAPI en Python), que corre en otro puerto.
 *
 * Se carga ANTES que auth.js en todas las páginas.
 */

/**
 * Base del servicio de IA (FastAPI, main.py).
 *
 * - Cadena vacía  -> mismo origen. Es lo correcto cuando se entra por el
 *                    túnel de ngrok, que multiplexa Apache y FastAPI bajo el
 *                    mismo host (/chatbot y /procesar-imagen van al Python,
 *                    /stockiate/... a Apache).
 * - "http://localhost:8000" -> desarrollo local puro, con uvicorn aparte.
 *
 * Si le pegás a la app por http://localhost/stockiate/tesis_enzo/... cambiá
 * esta constante a "http://localhost:8000".
 */
const STOCKIATE_IA_BASE = "";

/** URLs del servicio de IA, ya armadas. */
const URL_IA_PROCESAR_IMAGEN = `${STOCKIATE_IA_BASE}/procesar-imagen`;
const URL_IA_REGISTRAR_CORRECCION = `${STOCKIATE_IA_BASE}/registrar-correccion`;
const URL_IA_CHATBOT = `${STOCKIATE_IA_BASE}/chatbot`;

/**
 * Header que evita que ngrok devuelva su página de advertencia en HTML en
 * lugar de la respuesta JSON real. Inofensivo fuera de ngrok.
 */
const STOCKIATE_HEADERS = { "ngrok-skip-browser-warning": "true" };
