/**
 * stockIAte - auth.js
 * ================================
 * Sesión de usuario en el frontend (localStorage), sin backend de sesión
 * (cookies/tokens) — ver CLAUDE.md, sección "Cosas para tener en cuenta".
 * Lo usan login.html, registro.html y las páginas de cada rol
 * (repositor.html, cajero.html, administrador.html) para separar el acceso
 * por rol y reemplazar el `usuarioId = 1` hardcodeado que había antes.
 */

const STOCKIATE_SESSION_KEY = "stockiate_usuario";

// "dueño" es el rol interno (coincide con el ENUM de la base y con
// chatbot_ia.py); en la UI se muestra como "Administrador".
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

function cerrarSesion() {
  localStorage.removeItem(STOCKIATE_SESSION_KEY);
  window.location.href = "login.html";
}

function rutaParaRol(rol) {
  return STOCKIATE_RUTA_POR_ROL[rol] || "landing_page.html";
}

/**
 * Exige sesión iniciada. Si `rolesPermitidos` viene definido, además exige
 * que el usuario tenga uno de esos roles; si no lo tiene, lo redirige al
 * módulo que sí le corresponde (no lo deja pasar). Devuelve el usuario, o
 * null si redirigió (en ese caso el resto del script no debería ejecutarse).
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
 * Inyecta el widget del chatbot con el rol y usuario_id de la sesión real,
 * en vez de los data-rol/data-usuario-id fijos que tenía cada página.
 * chatbot_widget.js lee sus atributos de document.currentScript al cargar,
 * así que el <script> tiene que crearse dinámicamente con esos valores ya
 * puestos (no alcanza con editar los atributos de un <script> estático).
 */
function insertarChatbotWidget(usuario) {
  const script = document.createElement("script");
  script.src = "chatbot_widget.js?v=2";
  script.dataset.rol = usuario.rol;
  script.dataset.usuarioId = usuario.id;
  document.body.appendChild(script);
}
