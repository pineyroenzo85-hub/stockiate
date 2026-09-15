/**
 * stockIAte - auth.js
 * ================================
 * Sesión de usuario del lado del frontend.
 *
 * OJO CON QUÉ ES CADA COSA ACÁ
 * ----------------------------
 * Hasta multi-tenant, localStorage ERA la sesión: no había nada del lado del
 * servidor y cada endpoint le creía al `usuario_id` que le mandaran. Ahora la
 * sesión de verdad es una cookie de servidor (ver sesion.php) y este archivo
 * quedó dividido en dos capas con responsabilidades muy distintas:
 *
 *   1. CACHÉ DE UX (localStorage, sincrónico). Sirve para no parpadear:
 *      saber a qué módulo redirigir y pintar el nombre y el rol sin esperar
 *      un round-trip. `exigirSesion()` vive acá. **No es seguridad**: quien
 *      edite su localStorage a mano puede ver el HTML de otro módulo.
 *
 *   2. VERDAD (el servidor). `confirmarSesion()` contrasta el caché contra
 *      sesion_actual.php al cargar la página, y `fetchApi()` expulsa al
 *      usuario ante cualquier 401/403. Lo que decide qué datos se ven son
 *      las policies de cada endpoint PHP, no nada de este archivo.
 *
 * El peor caso es que alguien se falsee el caché y vea un cascarón de HTML
 * vacío durante ~200 ms antes de ser pateado, sin haber leído un solo dato:
 * todos los fetch pasan por fetchApi() y el servidor los rechaza.
 *
 * Requiere config.js cargado antes.
 */

const STOCKIATE_SESSION_KEY = "stockiate_usuario";

// "dueño" es el rol interno (coincide con el ENUM de la base y con
// chatbot_ia.py); en la UI se muestra como "Administrador". En el modelo
// multi-negocio significa "administrador DE SU negocio": es el único que
// puede invitar gente y borrar productos.
const STOCKIATE_RUTA_POR_ROL = {
  repositor: "repositor.html",
  cajero: "cajero.html",
  "dueño": "administrador.html",
};

const STOCKIATE_ETIQUETA_ROL = {
  repositor: "Repositor",
  cajero: "Cajero",
  "dueño": "Administrador",
};

function obtenerUsuarioSesion() {
  try {
    const raw = localStorage.getItem(STOCKIATE_SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) {
    return null;
  }
}

function guardarUsuarioSesion(usuario) {
  localStorage.setItem(STOCKIATE_SESSION_KEY, JSON.stringify(usuario));
}

function olvidarUsuarioSesion() {
  localStorage.removeItem(STOCKIATE_SESSION_KEY);
}

/**
 * Cierra sesión de verdad: primero en el servidor (que es lo que importa),
 * después limpia el caché local. El `catch` es a propósito: si el server no
 * responde igual queremos sacar al usuario de la pantalla.
 */
function cerrarSesion() {
  fetch("cerrar_sesion.php", { method: "POST", credentials: "include", headers: STOCKIATE_HEADERS })
    .catch(() => {})
    .finally(() => {
      olvidarUsuarioSesion();
      window.location.href = "login.html";
    });
}

function rutaParaRol(rol) {
  return STOCKIATE_RUTA_POR_ROL[rol] || "landing_page.html";
}

/**
 * Guardia OPTIMISTA, sincrónico. Corre en el <head> antes del render para
 * evitar que se vea el contenido de un módulo que no te corresponde.
 *
 * Lee del caché de localStorage, así que es falsificable: es una decisión de
 * UX, no de seguridad. La contraparte real es `confirmarSesion()`, que corre
 * apenas carga la página, y sobre todo cada endpoint PHP, que valida la
 * cookie por su cuenta.
 *
 * Devuelve el usuario, o null si redirigió (en ese caso el resto del script
 * no debería ejecutarse).
 */
function exigirSesion(rolesPermitidos) {
  const usuario = obtenerUsuarioSesion();

  if (!usuario) {
    window.location.href = "login.html";
    return null;
  }

  if (rolesPermitidos && !rolesPermitidos.includes(usuario.rol)) {
    window.location.href = rutaParaRol(usuario.rol);
    return null;
  }

  return usuario;
}

/**
 * Guardia AUTORITATIVO, asíncrono. Le pregunta al servidor quién sos de
 * verdad y corrige el caché:
 *
 *   - sin sesión de servidor -> a login, sin importar qué diga localStorage;
 *   - rol o negocio distintos a los cacheados -> actualiza y redirige al
 *     módulo correcto (cubre el caso de alguien que se editó el rol a mano,
 *     y también el de un rol cambiado desde otra sesión);
 *   - sesión válida pero rol no permitido en ESTA página -> al módulo que sí.
 *
 * Se llama al final de cada página de rol. Si el servidor no responde (backend
 * caído) no expulsa a nadie: no tiene sentido desloguear por un timeout.
 */
async function confirmarSesion(rolesPermitidos) {
  let data;
  try {
    const resp = await fetch("sesion_actual.php", {
      credentials: "include",
      headers: STOCKIATE_HEADERS,
    });

    if (resp.status === 401) {
      olvidarUsuarioSesion();
      window.location.href = "login.html";
      return null;
    }

    data = await resp.json();
    if (!data || !data.ok) return null;
  } catch (e) {
    // Sin conexión con el backend: dejamos la pantalla como está.
    return null;
  }

  // El modo (real o demo) lo decide el servidor, no el cliente: sale del
  // .env vía conexion.php. Se pinta la banda antes de cualquier otra cosa
  // para que no exista un instante en que la demo se vea como el sistema real.
  window.STOCKIATE_MODO_DEMO = data.modo_demo === true;
  if (window.STOCKIATE_MODO_DEMO) mostrarBandaDemo();

  const usuario = data.usuario;
  const cacheado = obtenerUsuarioSesion();

  // El caché quedó desactualizado (o alguien lo tocó): lo alineamos con la
  // única fuente que vale.
  if (!cacheado || cacheado.rol !== usuario.rol || cacheado.id !== usuario.id) {
    guardarUsuarioSesion(usuario);
  }

  if (rolesPermitidos && !rolesPermitidos.includes(usuario.rol)) {
    window.location.href = rutaParaRol(usuario.rol);
    return null;
  }

  return usuario;
}

/**
 * Wrapper de fetch para TODOS los endpoints PHP. Dos cosas:
 *
 *   - manda la cookie de sesión (`credentials: include`) — sin esto el
 *     servidor no sabe quién sos y todo devuelve 401;
 *   - ante 401 (sesión vencida o inexistente) limpia el caché y manda a
 *     login. Es la red de contención que hace que falsear el localStorage no
 *     sirva de nada: la primera llamada a datos te expulsa.
 *
 * El 403 (rol insuficiente) NO expulsa: significa que estás logueado pero esa
 * acción no es para vos. Se deja pasar para que cada pantalla muestre su
 * propio mensaje.
 */
async function fetchApi(url, opciones = {}) {
  const config = {
    ...opciones,
    credentials: "include",
    headers: { ...STOCKIATE_HEADERS, ...(opciones.headers || {}) },
  };

  const resp = await fetch(url, config);

  if (resp.status === 401) {
    olvidarUsuarioSesion();
    window.location.href = "login.html";
    throw new Error("Sesión expirada");
  }

  return resp;
}

/**
 * fetch para las páginas PRE-SESIÓN (login.html, registro.html,
 * invitacion.html). Manda la cookie —los endpoints de alta la necesitan para
 * dejarla puesta— pero NO expulsa ante un 401.
 *
 * La diferencia importa: en el login, un 401 significa "contraseña
 * incorrecta" y hay que mostrar el mensaje. Si usara fetchApi(), el 401
 * redirigiría a login.html y el usuario vería la página recargarse sin
 * entender por qué.
 */
async function fetchPublico(url, opciones = {}) {
  return fetch(url, {
    ...opciones,
    credentials: "include",
    headers: { ...STOCKIATE_HEADERS, ...(opciones.headers || {}) },
  });
}

/**
 * Traduce una excepción del bloque try de un submit a un mensaje honesto.
 *
 * Por qué existe: los `catch` de las pantallas de auth mostraban siempre
 * "No se pudo conectar con el servidor". Pero ahí adentro cae CUALQUIER
 * excepción, no sólo las de red — y la más común no es de red: si el
 * navegador sirve una copia cacheada vieja de este archivo, una función que
 * todavía no existía lanza un ReferenceError y el usuario termina leyendo
 * que el servidor está caído cuando el servidor está perfecto.
 *
 * `fetch` sólo tira TypeError cuando de verdad no pudo llegar al servidor.
 * Todo lo demás es un error de la página, y conviene decirlo así.
 */
function mensajeDeError(err) {
  console.error("[stockIAte]", err);

  if (err instanceof TypeError) {
    return "No se pudo conectar con el servidor.";
  }
  return "Error inesperado en la página. Probá recargar con Ctrl+Shift+R; "
       + "si sigue, mirá la consola del navegador (F12).";
}

/**
 * Qué sección del sistema es cada página. El chatbot lo usa para recortar sus
 * herramientas: parado en el depósito no contesta de facturación, aunque
 * quien pregunte sea el dueño.
 *
 * Es una pista del cliente, así que SÓLO PUEDE RECORTAR: el backend la
 * intersecta con las herramientas del rol de la sesión (ver CONTEXT_TOOLS en
 * chatbot_ia.py), y el control de acceso duro sigue estando en cada endpoint
 * PHP. Una página que no esté en este mapa manda contexto vacío y el
 * asistente se comporta como antes: recortado sólo por rol.
 */
const CONTEXTO_CHATBOT_POR_PAGINA = {
  "repositor.html": "repositor",
  "cajero.html": "cajero",
  "administrador.html": "admin",
};

function contextoChatbotDeLaPagina() {
  const archivo = window.location.pathname.split("/").pop();
  return CONTEXTO_CHATBOT_POR_PAGINA[archivo] || "";
}

/**
 * Inyecta el widget del chatbot con el rol de la sesión real y el contexto de
 * la página, en vez de los data-rol/data-usuario-id fijos que tenía cada una.
 * chatbot_widget.js lee sus atributos de document.currentScript al cargar,
 * así que el <script> tiene que crearse dinámicamente con esos valores ya
 * puestos (no alcanza con editar los atributos de un <script> estático).
 *
 * El rol que va acá es sólo para elegir el copy y las preguntas sugeridas del
 * widget: el backend ya no le cree (ver chatbot_ia.py, que resuelve el rol
 * real reenviando la cookie a sesion_actual.php).
 */
function insertarChatbotWidget(usuario) {
  const script = document.createElement("script");
  script.src = "chatbot_widget.js?v=4";
  script.dataset.rol = usuario.rol;
  script.dataset.contexto = contextoChatbotDeLaPagina();
  document.body.appendChild(script);
}

/**
 * Inyecta barcode-manager.js una sola vez por página, igual que
 * insertarChatbotWidget(): así el lector de código de barras (herramienta
 * opcional, ver "Ajustes > Periféricos y Herramientas") corre en TODAS las
 * pantallas autenticadas sin que cada una lo agregue a mano, y no vive
 * atado a ninguna pantalla en particular (ni siquiera a la de Cámara/IA).
 *
 * El propio barcode-manager.js decide, leyendo BarcodeSettings de
 * localStorage, si arma el listener de teclado o no — acá sólo se carga el
 * script.
 */
function inicializarLectorCodigoBarras(alCargar) {
  const script = document.createElement("script");
  script.src = "barcode-manager.js?v=1";
  // El script se inyecta dinámicamente -> carga async. La pantalla de
  // Ajustes necesita el callback para recién ahí conectar sus controles a
  // `barcodeManager` (que barcode-manager.js crea como variable global al
  // final de su propio bootstrap); el resto de las pantallas no lo necesita
  // y puede omitirlo.
  if (typeof alCargar === "function") {
    script.addEventListener("load", alCargar);
  }
  document.body.appendChild(script);
}

/**
 * Banda de "modo demostración" arriba de todo.
 *
 * En modo demo el sistema anda contra `stockiate_demo`, una base separada con
 * datos inventados (ver conexion.php y seed_demo.php). Todo se ve igual que
 * con datos reales, y ése es justamente el problema: una captura de pantalla
 * de la demo metida en el informe como si fuera el piloto sería un dato
 * falso. La banda existe para que eso no pueda pasar ni por accidente, así
 * que es fija, va arriba de todo y no se puede cerrar.
 *
 * No usa las variables del tema a propósito: tiene que verse igual en claro,
 * en oscuro y en escala de grises si alguien imprime la captura.
 */
function mostrarBandaDemo() {
  if (document.getElementById("stockiate-banda-demo")) return;

  const estilo = document.createElement("style");
  estilo.textContent = `
    #stockiate-banda-demo{
      position:fixed; top:0; left:0; right:0; z-index:1000001;
      background:#161b29; color:#ffb547;
      font:700 12px/1 'Plus Jakarta Sans','Inter',system-ui,sans-serif;
      letter-spacing:.06em; text-transform:uppercase; text-align:center;
      padding:7px 12px; border-bottom:2px solid #ffb547;
    }
    #stockiate-banda-demo span{ font-weight:500; text-transform:none; letter-spacing:0; opacity:.85; }
    body{ padding-top:32px !important; }

    /* Al imprimir, la banda deja de ser fija. Fija se repetiría arriba de
       CADA hoja y correría 32px hacia abajo todo el contenido de todas, que
       en carteles.html rompe la grilla A4 entera. Estática sale una sola vez,
       arriba del documento, que es lo que hace falta: que una impresión de la
       demo no se pueda confundir con una del piloto. */
    @media print{
      #stockiate-banda-demo{ position:static; border-bottom:1px solid #000; }
      body{ padding-top:0 !important; }
    }
  `;
  document.head.appendChild(estilo);

  const banda = document.createElement("div");
  banda.id = "stockiate-banda-demo";
  banda.setAttribute("role", "status");
  banda.innerHTML = 'Modo demostración &nbsp;<span>— datos de ejemplo, no son del comercio</span>';
  document.body.prepend(banda);
}
