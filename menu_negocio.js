/**
 * stockIAte - menu_negocio.js
 * ================================
 * El chip de la topbar de `administrador.html` dejó de ser un cartelito con el
 * nombre del usuario: ahora muestra el NEGOCIO y es el botón que abre sus
 * preferencias. Este archivo es dueño de las dos cosas -- el chip y el panel
 * superpuesto -- para que las iniciales y el nombre se calculen en un solo
 * lugar en vez de repetirse en el HTML.
 *
 * POR QUÉ EXISTE
 * --------------
 * Las preferencias eran un card más del `.main-grid`, al final de un panel que
 * ya tenía cuatro KPIs, el inventario, el riesgo de quiebre y los costos. Todo
 * lo que se configura una vez cada tanto empujaba hacia abajo lo que se mira
 * todos los días. Acá adentro también viven "Equipo" y "Cerrar sesión", que
 * antes eran dos botones sueltos en la barra de arriba.
 *
 * Es un componente autocontenido (inyecta su propio CSS) como el resto de los
 * bloques del panel, y como los otros dos flotantes del proyecto
 * (`chatbot_widget.js`, `recorte_imagen.js`). Los colores van como
 * `var(--x, fallback)` porque `administrador.html` NO carga `styles.css` -- ver
 * el comentario de su <style> -- y porque así el componente no depende de en
 * qué página se monte.
 *
 * Uso:
 *   const menu = initMenuNegocio(document.getElementById("btn-negocio"));
 *   menu.pintarSesion(usuarioSesion);
 *
 * Clases con prefijo "mng-", como "invt-"/"cst-"/"prd-"/"prf-".
 *
 * LIMITACIÓN CONOCIDA: no hay focus-trap. Con el modal abierto se puede tabular
 * hacia el fondo. Un trap correcto (primer/último tabbable, Tab/Shift+Tab,
 * inert en el resto) es bastante código y este es el primer role="dialog" del
 * proyecto; se hizo lo que sí importa (foco al abrir, foco de vuelta al chip al
 * cerrar, y cierre con Escape).
 */

const MNG_ESTILOS_ID = "mng-estilos";
const MNG_CSS = `
/* z-index 1000000: el botón flotante del chatbot es fixed con z-index 999999
   (chatbot_widget.js) y si no queda flotando ENCIMA del modal. Se deja por
   debajo del recortador de imagen (1000001) para no romper esa escala. */
.mng-overlay{
  position:fixed; inset:0; z-index:1000000;
  display:flex; align-items:center; justify-content:center; padding:24px;
  background:rgba(26,22,37,0.55);
  backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
  font-family:"Plus Jakarta Sans","Inter",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
}
/* Obligatorio: el display:flex de arriba le gana al [hidden]{display:none} que
   trae el navegador, así que sin esta línea el modal nace abierto y no cierra
   nunca. Misma trampa que .siate-chat-panel[hidden] en chatbot_widget.js. */
.mng-overlay[hidden]{ display:none; }

.mng-modal{
  width:min(780px, 100%); max-height:min(86vh, 900px);
  display:flex; flex-direction:column;
  background:var(--glass-bg, rgba(255,255,255,0.55));
  backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%);
  border:1px solid var(--glass-border, rgba(255,255,255,0.75));
  border-radius:22px; box-shadow:var(--glass-shadow, 0 20px 60px rgba(0,0,0,0.35));
  color:var(--blanco-puro, #453B5C); overflow:hidden; outline:none;
}

.mng-head{ display:flex; align-items:center; gap:12px; padding:18px 20px; border-bottom:1px solid var(--divisor, rgba(0,0,0,0.08)); }
.mng-avatar{ width:38px; height:38px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center; font-size:13.5px; font-weight:800; color:var(--blanco-puro, #FFF); background:linear-gradient(135deg, var(--lila, #D9BFEA) 0%, var(--menta, #B8E8CE) 50%, var(--dorado, #F5E3AE) 100%); }
.mng-head-txt{ flex:1; min-width:0; }
.mng-head-txt h2{ font-size:15px; font-weight:800; margin:0; }
.mng-head-txt p{ font-size:12px; color:var(--gris-tenue, #8B7FA8); margin:2px 0 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mng-cerrar{ background:none; border:none; color:var(--gris-tenue, #8B7FA8); font-size:17px; line-height:1; cursor:pointer; padding:6px 8px; border-radius:9px; font-family:inherit; transition:all .15s; }
.mng-cerrar:hover{ color:var(--blanco-puro, #453B5C); }

/* Scrollea el cuerpo, no el modal entero: la cabecera y el pie quedan fijos. */
.mng-body{ overflow-y:auto; flex:1; }

/* .prf-panel es un card glass suelto, pensado para el .main-grid. Adentro del
   modal daría un card dentro de otro card, más un hueco de 20px arriba. Se lo
   desviste desde acá para no tener que tocar preferencias.js, que sigue
   sirviendo tal cual si algún día se monta en otro lado.
   El selector .mng-body .prf-panel (0,2,0) le gana a .prf-panel (0,1,0), así
   que no importa en qué orden se inyecten los dos <style>.
   OJO: no tocar el padding de .prf-body -- .prf-tabla-wrap usa un margin
   negativo (0 -20px -18px) para sangrar el historial hasta los bordes. */
.mng-body .prf-panel{
  margin-top:0; background:none; backdrop-filter:none; -webkit-backdrop-filter:none;
  border:none; border-radius:0; box-shadow:none;
}

.mng-pie{ display:flex; align-items:center; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--divisor, rgba(0,0,0,0.08)); }
.mng-accion{ background:var(--superficie, #F3EFF7); box-shadow:var(--neu-sombra-chica, 0 2px 6px rgba(0,0,0,0.10)); border:none; color:var(--gris-tenue, #8B7FA8); border-radius:10px; padding:9px 14px; font-size:12.5px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.mng-accion:hover{ color:var(--blanco-puro, #453B5C); }
/* margin-left:auto en vez de justify-content:space-between: con tres botones,
   el space-between deja el del medio flotando en el centro. Así los dos de
   navegación quedan juntos a la izquierda y salir queda solo a la derecha.
   (Sin acentos graves acá: este CSS vive dentro de un template literal.) */
.mng-accion-salir{ margin-left:auto; }
.mng-accion-salir:hover{ color:var(--error, #E88F97); }
.mng-overlay button:focus-visible{ outline:2px solid var(--lila-dark, #B87FD9); outline-offset:2px; }

/* Ventana flotante de Carteles / Equipo. Es la página completa en un iframe
   (ver embebido.js), sobre un fondo más difuminado que el del menú. Mismo
   z-index que el menú: al abrirse, el menú se cierra. */
.mng-flot-overlay{
  position:fixed; inset:0; z-index:1000000;
  display:flex; align-items:center; justify-content:center; padding:24px;
  background:rgba(26,22,37,0.45);
  backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
  font-family:"Plus Jakarta Sans","Inter",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  animation:mng-aparecer .18s ease-out;
}
.mng-flot-overlay[hidden]{ display:none; }
.mng-flot{
  width:min(1240px, 100%); height:min(90vh, 1000px);
  display:flex; flex-direction:column;
  background:var(--superficie, #F3EFF7);
  border:1px solid var(--glass-border, rgba(255,255,255,0.75));
  border-radius:22px; box-shadow:0 30px 80px rgba(0,0,0,0.35);
  color:var(--blanco-puro, #453B5C); overflow:hidden; outline:none;
  animation:mng-subir .22s ease-out;
}
.mng-flot-head{ display:flex; align-items:center; gap:12px; padding:14px 18px 14px 22px; border-bottom:1px solid var(--divisor, rgba(0,0,0,0.08)); }
.mng-flot-head h2{ flex:1; font-size:15px; font-weight:800; margin:0; }
.mng-flot-marco{ position:relative; flex:1; min-height:0; }
.mng-flot-marco iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; background:transparent; }
.mng-flot-cargando{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:13px; color:var(--gris-tenue, #8B7FA8); }
@keyframes mng-aparecer{ from{ opacity:0; } to{ opacity:1; } }
@keyframes mng-subir{ from{ opacity:0; transform:translateY(12px) scale(.985); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion: reduce){
  .mng-flot-overlay, .mng-flot{ animation:none; }
}
@media (max-width: 560px){
  .mng-flot-overlay{ padding:8px; }
  .mng-flot{ height:96vh; border-radius:18px; }
}

/* El bloqueo del scroll del fondo va como clase en <html> y no como estilo
   inline, para no pisar (ni tener que restaurar) lo que hubiera antes. */
.mng-sin-scroll{ overflow:hidden; }

@media (max-width: 560px){
  .mng-overlay{ padding:12px; }
  .mng-modal{ max-height:94vh; }
}
`;

function asegurarEstilosMenuNegocio() {
  if (document.getElementById(MNG_ESTILOS_ID)) return;
  const estilo = document.createElement("style");
  estilo.id = MNG_ESTILOS_ID;
  estilo.textContent = MNG_CSS;
  document.head.appendChild(estilo);
}

/**
 * Iniciales del negocio para el avatar: "Mi Perfumería" -> "MP",
 * "Perfumería" -> "PE", "" -> "".
 *
 * Se recorre con Array.from y no con [0] porque un nombre que arranque con un
 * emoji es un par subrogado, y `nombre[0]` devolvería media letra (el rombo
 * con el signo de pregunta).
 */
function mngIniciales(nombre) {
  const palabras = String(nombre || "").trim().split(/\s+/).filter(Boolean);
  if (palabras.length === 0) return "";
  if (palabras.length === 1) {
    return Array.from(palabras[0]).slice(0, 2).join("").toUpperCase();
  }
  return (Array.from(palabras[0])[0] + Array.from(palabras[1])[0]).toUpperCase();
}

/**
 * @param {HTMLElement} chip  el <button class="user-chip"> de la topbar
 * @returns {{abrir: Function, cerrar: Function, pintarSesion: Function}}
 */
function initMenuNegocio(chip) {
  asegurarEstilosMenuNegocio();

  // El overlay se crea de entrada (no cuesta red, es markup vacío); lo que se
  // difiere es initPreferencias(), que sí pega dos fetch.
  const overlay = document.createElement("div");
  overlay.className = "mng-overlay";
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="mng-modal" role="dialog" aria-modal="true" aria-labelledby="mngTitulo" id="mngDialogo" tabindex="-1">
      <div class="mng-head">
        <div class="mng-avatar" data-mng-avatar></div>
        <div class="mng-head-txt">
          <h2 id="mngTitulo" data-mng-negocio>Mi negocio</h2>
          <p data-mng-usuario></p>
        </div>
        <button type="button" class="mng-cerrar" data-mng-cerrar aria-label="Cerrar">&#10005;</button>
      </div>
      <div class="mng-body"><div data-mng-preferencias></div></div>
      <div class="mng-pie">
        <button type="button" class="mng-accion" data-mng-carteles>🏷 Carteles</button>
        <button type="button" class="mng-accion" data-mng-equipo>👥 Equipo</button>
        <button type="button" class="mng-accion mng-accion-salir" data-mng-salir>Cerrar sesión</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  const modal = overlay.querySelector(".mng-modal");
  const cajaPreferencias = overlay.querySelector("[data-mng-preferencias]");
  // Los mismos data-* existen en el chip y en el modal, así que cada búsqueda
  // va scopeada a su raíz.
  const chipAvatar = chip.querySelector("[data-mng-avatar]");
  const chipNegocio = chip.querySelector("[data-mng-negocio]");
  const chipUsuario = chip.querySelector("[data-mng-usuario]");
  const modalAvatar = overlay.querySelector("[data-mng-avatar]");
  const modalNegocio = overlay.querySelector("[data-mng-negocio]");
  const modalUsuario = overlay.querySelector("[data-mng-usuario]");

  let abierto = false;
  let preferencias = null; // instancia de initPreferencias, montada al abrir

  /**
   * Llena el chip y la cabecera del modal. Se llama dos veces: una sincrónica
   * con el caché de localStorage (para que el chip nazca con el nombre real) y
   * otra cuando contesta `sesion_actual.php`, por si el negocio se renombró
   * desde otra sesión.
   */
  function pintarSesion(usuario) {
    // exigirSesion() devuelve null y redirige, pero el script sigue corriendo
    // hasta que el navegador cambia de documento: sin este guard, TypeError.
    if (!usuario) return;

    const negocio = (usuario.negocio_nombre || "").trim();
    const persona = [usuario.nombre, usuario.apellido].filter(Boolean).join(" ");
    const etiquetaRol = (typeof STOCKIATE_ETIQUETA_ROL !== "undefined" && STOCKIATE_ETIQUETA_ROL[usuario.rol])
      ? STOCKIATE_ETIQUETA_ROL[usuario.rol]
      : usuario.rol;

    // sesion_actual.php devuelve negocio_nombre:'' cuando no encuentra el
    // negocio, así que el vacío es un estado real y no una hipótesis. Se cae al
    // nombre de la persona para que el avatar nunca quede como un círculo vacío.
    const rotulo = negocio || "Mi negocio";
    const iniciales = mngIniciales(negocio) || (persona.charAt(0) || "?").toUpperCase();

    chipNegocio.textContent = rotulo;
    chipUsuario.textContent = persona;
    chipAvatar.textContent = iniciales;
    chip.title = rotulo + " · Preferencias del negocio";

    modalNegocio.textContent = rotulo;
    modalUsuario.textContent = [persona, etiquetaRol, usuario.email].filter(Boolean).join(" · ");
    modalAvatar.textContent = iniciales;
  }

  function abrir() {
    if (abierto) return;
    abierto = true;
    overlay.hidden = false;
    chip.setAttribute("aria-expanded", "true");
    document.documentElement.classList.add("mng-sin-scroll");

    // MONTAJE PEREZOSO. initPreferencias() dispara dos fetch
    // (consultar_preferencias.php + consultar_notificaciones.php). Montarlo al
    // cargar la página costaba esos dos requests SIEMPRE, para un panel que se
    // abre de vez en cuando. La primera vez se monta; las siguientes se
    // recarga, porque el historial de avisos pudo haber crecido.
    // Mismo patrón que repositor.html con la tabla de inventario.
    if (!preferencias) {
      preferencias = initPreferencias(cajaPreferencias);
    } else {
      preferencias.recargar();
    }

    modal.focus();
  }

  function cerrar() {
    if (!abierto) return;
    abierto = false;
    overlay.hidden = true;
    chip.setAttribute("aria-expanded", "false");
    document.documentElement.classList.remove("mng-sin-scroll");
    chip.focus(); // el foco vuelve de donde salió
  }

  chip.addEventListener("click", () => (abierto ? cerrar() : abrir()));
  overlay.querySelector("[data-mng-cerrar]").addEventListener("click", cerrar);

  overlay.addEventListener("click", (e) => {
    // Sólo el fondo. Sin este chequeo, arrastrar desde adentro del modal y
    // soltar sobre el fondo también cerraría.
    if (e.target === overlay) cerrar();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && abierto) cerrar();
  });

  // ------------------------------------------------------------------
  // Ventana flotante: Carteles y Equipo sin salir del panel
  // ------------------------------------------------------------------
  const flotOverlay = document.createElement("div");
  flotOverlay.className = "mng-flot-overlay";
  flotOverlay.hidden = true;
  flotOverlay.innerHTML = `
    <div class="mng-flot" role="dialog" aria-modal="true" aria-labelledby="mngFlotTitulo" tabindex="-1">
      <div class="mng-flot-head">
        <h2 id="mngFlotTitulo"></h2>
        <button type="button" class="mng-cerrar" data-mng-flot-cerrar aria-label="Cerrar">&#10005;</button>
      </div>
      <div class="mng-flot-marco"><div class="mng-flot-cargando">Cargando...</div></div>
    </div>
  `;
  document.body.appendChild(flotOverlay);

  const flot = flotOverlay.querySelector(".mng-flot");
  const flotTitulo = flotOverlay.querySelector("#mngFlotTitulo");
  const flotMarco = flotOverlay.querySelector(".mng-flot-marco");
  let flotAbierta = false;

  function abrirFlotante(pagina, titulo) {
    if (abierto) {
      // Se cierra el menú sin devolverle el foco al chip: lo toma la ventana.
      abierto = false;
      overlay.hidden = true;
      chip.setAttribute("aria-expanded", "false");
    }
    flotAbierta = true;
    flotTitulo.textContent = titulo;

    // Iframe nuevo en cada apertura: Equipo y el catálogo de carteles se leen
    // frescos, y al cerrar no queda una página viva escuchando atrás.
    const iframe = document.createElement("iframe");
    iframe.title = titulo;
    iframe.src = pagina;
    iframe.addEventListener("load", () => {
      // Si la sesión venció, exigirSesion() redirige a login.html ADENTRO del
      // iframe: el login tiene que ocupar la pestaña, no la ventanita.
      try {
        const ruta = iframe.contentWindow.location.pathname;
        if (!ruta.endsWith("/" + pagina)) {
          window.location.href = iframe.contentWindow.location.href;
        }
      } catch (e) { /* mismo origen siempre; por las dudas */ }
    });
    flotMarco.querySelectorAll("iframe").forEach(n => n.remove());
    flotMarco.appendChild(iframe);

    flotOverlay.hidden = false;
    document.documentElement.classList.add("mng-sin-scroll");
    flot.focus();
  }

  // Al cerrar Carteles o Equipo se vuelve al menú del negocio (preferencias,
  // avisos), que es de donde se abrieron, y no directo al panel.
  function cerrarFlotante() {
    if (!flotAbierta) return;
    flotAbierta = false;
    flotOverlay.hidden = true;
    flotMarco.querySelectorAll("iframe").forEach(n => n.remove());
    abrir(); // mantiene el bloqueo de scroll y le da el foco al menú
  }

  flotOverlay.querySelector("[data-mng-flot-cerrar]").addEventListener("click", cerrarFlotante);
  flotOverlay.addEventListener("click", (e) => {
    if (e.target === flotOverlay) cerrarFlotante();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && flotAbierta) cerrarFlotante();
  });
  // Escape apretado con el foco adentro del iframe (lo reenvía embebido.js).
  window.addEventListener("message", (e) => {
    if (e.origin !== window.location.origin) return;
    if (e.data && e.data.tipo === "stockiate-cerrar-flotante") cerrarFlotante();
  });

  overlay.querySelector("[data-mng-carteles]").addEventListener("click", () => {
    abrirFlotante("carteles.html", "🏷 Carteles de góndola");
  });

  overlay.querySelector("[data-mng-equipo]").addEventListener("click", () => {
    abrirFlotante("equipo.html", "👥 Equipo");
  });

  // cerrarSesion() (auth.js) navega a login.html por su cuenta: no hace falta
  // cerrar el modal antes.
  overlay.querySelector("[data-mng-salir]").addEventListener("click", cerrarSesion);

  return { abrir, cerrar, pintarSesion };
}
