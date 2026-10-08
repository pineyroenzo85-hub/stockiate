/**
 * stockIAte - inventario_tabla.js
 * ================================
 * Componente de tabla de inventario compartido entre administrador.html y
 * repositor.html (cada página tiene su propio JS inline por convención del
 * proyecto -- ver CLAUDE.md -- pero la tabla en sí debe verse y comportarse
 * igual en ambas, así que vive acá en vez de duplicarse).
 *
 * Uso:
 *   initInventarioTabla(document.getElementById("contenedor"), {
 *     onDatos: (datos) => { ... } // opcional, recibe la respuesta completa
 *                                  // de consultar_inventario.php (para que
 *                                  // cada página arme sus propios KPI cards)
 *     plegable: true              // opcional, default false: agrega el botón
 *                                  // de plegar y recuerda el estado. Sólo lo
 *                                  // usa administrador.html; en repositor.html
 *                                  // esta tabla ES la pantalla entera y
 *                                  // plegarla dejaría una pantalla vacía.
 *   });
 *
 * Clases CSS con prefijo "invt-" para no chocar con estilos ya definidos en
 * styles.css (.panel, .kpi-card, etc. se reutilizan en otras pantallas).
 *
 * El selector de orden (INVT_ORDENES) es del componente y aparece en las dos
 * páginas, sin flag: el repositor es justamente quien acaba de cargar algo y
 * quiere verlo arriba en vez de buscarlo por el paginador.
 */

const URL_CONSULTAR_INVENTARIO = "consultar_inventario.php";
const URL_ACTUALIZAR_STOCK = "actualizar_stock.php";
// Archivar, no eliminar: un producto con ventas o lotes no se puede borrar
// sin destruir historial. Ver archivar_producto.php.
const URL_ARCHIVAR_PRODUCTO = "archivar_producto.php";

// Evita la página de advertencia HTML de ngrok free tier en vez de la respuesta JSON real.
// Prefijo INVT_ porque este archivo se carga junto a otro <script> que ya
// declara su propio HEADERS_NGROK en el scope global de la página.
const INVT_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

// El mecanismo de plegado vive en plegable.js, compartido con costos_tabla.js.
// Acá queda sólo lo propio: la clave de guardado y qué se esconde (el CSS).
const INVT_CLAVE_PLEGADO = "stockiate_inventario_plegado";

// --------------------------------------------------------------------------
// Orden de la tabla
// --------------------------------------------------------------------------
// El endpoint sigue devolviendo nombre A-Z (y archivado_en DESC en
// archivados); reordenar es cosa del cliente, igual que filtrar por el
// buscador, y por el mismo motivo: el catálogo entero ya está en memoria.
const INVT_CLAVE_ORDEN = "stockiate_inventario_orden";

// Fecha de "cargado más reciente": la última entrada de mercadería
// (consultar_inventario.php la trae como ultima_carga). Un producto creado a
// mano y movido sólo con los +/- del panel no tiene ningún lote, así que ahí
// cae al alta del producto. Las dos llegan como "YYYY-MM-DD HH:MM:SS" de
// MySQL, un formato que ordena bien comparado como string: no hace falta
// parsear nada (y se evita de paso que Safari no entienda el espacio).
function invtFechaCarga(p) {
  return p.ultima_carga || p.creado_en || "";
}

// `comparar: null` significa "no reordenar": el servidor ya manda el catálogo
// activo por nombre A-Z, y los archivados por fecha de baja descendente, que
// es un default deliberado (lo último archivado es lo que más probablemente
// se quiera restaurar). Ordenar por nombre acá lo rompería, y por eso la
// etiqueta de esta opción cambia en la vista de archivados.
const INVT_ORDENES = [
  { clave: "nombre", etiqueta: "Nombre (A-Z)", etiquetaArchivados: "Archivados recientes", comparar: null },
  // Casi nadie cae en el caso "sin fecha", porque invtFechaCarga() ya cubre
  // al que no tiene lotes con su fecha de alta. Queda para el producto que no
  // trae ninguna de las dos: va al final, porque no se sabe cuándo se cargó y
  // arriba de todo estaría ocupando el lugar del que sí se sabe.
  { clave: "recientes", etiqueta: "Cargados recientemente", comparar: (a, b) => {
      const fa = invtFechaCarga(a);
      const fb = invtFechaCarga(b);
      if (!fa && !fb) return 0;
      if (!fa) return 1;
      if (!fb) return -1;
      return fb.localeCompare(fa);
    } },
  { clave: "stock_asc", etiqueta: "Menos stock primero", comparar: (a, b) => Number(a.stock_actual) - Number(b.stock_actual) },
  { clave: "stock_desc", etiqueta: "Más stock primero", comparar: (a, b) => Number(b.stock_actual) - Number(a.stock_actual) },
];

// No se reutiliza plegableLeer(): es booleano-específico (devuelve
// `guardado === "1"`). Lo que sí se copia es el try/catch, que existe porque
// en incógnito con el almacenamiento bloqueado el acceso lanza en vez de
// devolver null.
function invtLeerOrden() {
  try {
    const guardado = localStorage.getItem(INVT_CLAVE_ORDEN);
    if (INVT_ORDENES.some(o => o.clave === guardado)) return guardado;
  } catch (e) {
    // Sin persistencia se arranca con el orden por defecto, nada más.
  }
  return INVT_ORDENES[0].clave;
}

function invtGuardarOrden(clave) {
  try {
    localStorage.setItem(INVT_CLAVE_ORDEN, clave);
  } catch (e) {
    // La preferencia se pierde entre cargas, pero el selector tiene que
    // seguir andando dentro de esta sesión.
  }
}

const INVT_ESTILOS_ID = "invt-estilos";
const INVT_CSS = `
.invt-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.invt-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.invt-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.invt-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.invt-search-box{ display:flex; align-items:center; gap:8px; background:var(--superficie); box-shadow:var(--neu-inset); border-radius:10px; padding:8px 12px; min-width:230px; }
.invt-search-box input{ background:transparent; border:none; outline:none; color:var(--blanco-puro); font-size:13px; width:100%; font-family:inherit; }
.invt-search-box input::placeholder{ color:var(--gris-tenue); }
.invt-search-box svg{ flex-shrink:0; opacity:.6; }
.invt-table-wrap{ overflow-x:auto; }
.invt-table{ width:100%; border-collapse:collapse; font-size:13.5px; }
.invt-table thead th{ text-align:left; padding:10px 20px; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; background:transparent; }
.invt-table tbody td{ padding:12px 20px; border-bottom:1px solid var(--divisor); vertical-align:middle; }
.invt-table tbody tr:last-child td{ border-bottom:none; }
.invt-table tbody tr:hover{ background:var(--lila-tenue); }
.invt-prod-name{ font-weight:700; }
.invt-prod-sub{ font-size:12px; color:var(--gris-tenue); }
.invt-badge{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:700; }
.invt-badge.ok{ background:var(--ok-bg); color:var(--ok); }
.invt-badge.low{ background:var(--alerta-bg); color:var(--alerta); }
.invt-badge.critical{ background:var(--error-bg); color:var(--error); }
.invt-badge.unavailable{ background:var(--gris-tenue-bg); color:var(--gris-tenue); }
.invt-stock-controls{ display:flex; align-items:center; gap:8px; }
.invt-stock-controls button{ width:26px; height:26px; border-radius:7px; border:none; background:var(--superficie); box-shadow:var(--neu-sombra-chica); color:var(--blanco-puro); cursor:pointer; font-size:15px; font-weight:700; line-height:1; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-stock-controls button:hover:not(:disabled){ background:var(--lila); color:var(--texto-sobre-acento); }
.invt-stock-controls button.invt-minus:hover:not(:disabled){ background:var(--coral); color:#1B1740; }
.invt-stock-controls button:disabled{ opacity:.35; cursor:not-allowed; }
.invt-stock-controls .invt-qty{ min-width:26px; text-align:center; font-weight:700; }
.invt-acciones{ display:flex; align-items:center; gap:14px; }
.invt-eliminar{ width:26px; height:26px; border-radius:7px; border:none; background:var(--superficie); box-shadow:var(--neu-sombra-chica); color:var(--blanco-puro); cursor:pointer; font-size:13px; line-height:1; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-eliminar:hover:not(:disabled){ background:var(--coral); color:#1B1740; }
.invt-eliminar:disabled{ opacity:.25; cursor:not-allowed; }
.invt-restaurar{ width:auto; padding:0 10px; gap:6px; font-size:12px; font-weight:700; }
.invt-restaurar:hover:not(:disabled){ background:var(--menta); color:#1B1740; }
/* Selector de orden. Es un <select> NATIVO y no un botón con menú propio por
   dos motivos concretos: .invt-panel tiene overflow:hidden (por el
   border-radius que recorta la tabla), así que un menú posicionado en
   absoluto dentro del head quedaría cortado apenas pase el borde; y en el
   celular el select abre la rueda del sistema, que es el control correcto
   justo donde el thead está en display:none. Mismo look que .grf-select
   (graficos.js), que ya es el selector estándar del panel. */
/* max-width y no un ancho fijo: el criterio activo se lee en el propio
   control, así que la opción más larga ("Cargados recientemente") tiene que
   entrar entera -- cortada a "Cargados recientement…" el control deja de
   decir en qué orden está la tabla, que es la mitad de para qué está. */
.invt-select{ background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:10px; padding:8px 12px; color:var(--blanco-puro); font-size:12.5px; font-family:inherit; font-weight:700; outline:none; cursor:pointer; max-width:230px; }
.invt-select:focus{ box-shadow:var(--neu-inset), 0 0 0 2px var(--lila); }
.invt-toggle-arch{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.invt-toggle-arch:hover{ color:var(--blanco-puro); }
.invt-toggle-arch.activo{ background:var(--lila-tenue); color:var(--azul-acento); box-shadow:var(--neu-inset); }
/* Contenedor de las acciones del encabezado. Estaba como style inline, y tuvo
   que salir de ahí: un style inline le gana a cualquier selector sin
   !important, así que la regla de abajo que lo esconde al plegar no tendría
   efecto y el buscador quedaría flotando sobre una tabla invisible.
   El margin-left:auto es porque con el botón de plegar el head pasa a tener
   tres hijos y el space-between dejaría las acciones en el medio. En
   repositor.html (dos hijos) es no-op. */
.invt-head-acciones{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto; }
.invt-toggle-pleg{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap; }
.invt-toggle-pleg:hover{ color:var(--blanco-puro); }
/* Plegada: se esconde la tabla y con ella todo lo que sólo sirve teniéndola a
   la vista (buscar en algo invisible, o cambiar el contenido de algo
   invisible). Lo que SÍ queda es el subtítulo con el resumen -- "N productos ·
   N requieren atención" --, que es justamente el valor de un panel plegado. */
.invt-panel.invt-plegado .invt-table-wrap,
.invt-panel.invt-plegado .invt-head-acciones{ display:none; }
/* Sin tabla debajo, el borde del head queda como una línea bajo la nada. */
.invt-panel.invt-plegado .invt-panel-head{ border-bottom:none; }
.invt-arch-sub{ font-size:11.5px; color:var(--gris-tenue); }
.invt-empty-row td{ text-align:center; padding:30px; color:var(--gris-tenue); }

/* Sólo en vistaDetalle (administrador.html): fila clickeable que abre el
   flotante, paginador debajo de la tabla, y el flotante en sí. */
.invt-row-clickable{ cursor:pointer; }
.invt-paginador{ display:flex; align-items:center; justify-content:center; gap:14px; padding:14px 20px; border-top:1px solid var(--divisor); font-size:12.5px; color:var(--gris-tenue); }
.invt-paginador button{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--blanco-puro); border-radius:8px; width:30px; height:30px; cursor:pointer; font-size:14px; font-weight:700; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-paginador button:hover:not(:disabled){ background:var(--lila); color:var(--texto-sobre-acento); }
.invt-paginador button:disabled{ opacity:.3; cursor:not-allowed; }

/* z-index 1000000: mismo nivel que .mng-overlay de menu_negocio.js, para
   quedar por encima del botón flotante del chatbot (999999). No se espera
   que el panel del negocio y este flotante estén abiertos a la vez. */
.invt-modal-overlay{ position:fixed; inset:0; z-index:1000000; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(20,14,34,.45); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.invt-modal-overlay[hidden]{ display:none; }
.invt-modal{ width:100%; max-width:360px; background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; box-shadow:var(--glass-shadow); color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; position:relative; max-height:92vh; overflow-y:auto; }
.invt-modal-cerrar{ position:absolute; top:12px; right:12px; background:none; border:none; color:var(--gris-tenue); font-size:16px; line-height:1; cursor:pointer; padding:6px 8px; border-radius:9px; transition:all .15s; }
.invt-modal-cerrar:hover{ color:var(--blanco-puro); }
.invt-modal-head{ display:flex; gap:14px; align-items:flex-start; padding:22px 44px 16px 20px; }
.invt-modal-img{ width:83px; height:83px; flex-shrink:0; border-radius:14px; overflow:hidden; background:var(--superficie); box-shadow:var(--neu-inset); display:flex; align-items:center; justify-content:center; font-size:29px; }
.invt-modal-img img{ width:100%; height:100%; object-fit:cover; display:block; }
.invt-modal-info{ flex:1; min-width:0; padding-top:2px; }
.invt-modal-nombre{ font-weight:800; font-size:15px; }
.invt-modal-marca{ font-size:12.5px; color:var(--gris-tenue); margin-top:2px; }
.invt-modal-acciones{ display:flex; gap:8px; margin-top:10px; }
.invt-modal-accion{ width:28px; height:28px; border-radius:8px; border:none; background:var(--superficie); box-shadow:var(--neu-sombra-chica); color:var(--blanco-puro); cursor:pointer; font-size:13px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-modal-accion:hover:not(:disabled){ background:var(--lila); color:var(--texto-sobre-acento); }
.invt-modal-accion:disabled{ opacity:.35; cursor:not-allowed; }
.invt-modal-datos{ padding:0 20px 16px; display:flex; flex-direction:column; gap:10px; }
.invt-modal-dato{ display:flex; justify-content:space-between; gap:12px; font-size:13px; }
.invt-modal-dato-label{ color:var(--gris-tenue); font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.invt-modal-cantidad{ padding:16px 20px; border-top:1px solid var(--divisor); display:flex; align-items:center; justify-content:space-between; gap:12px; }
.invt-modal-qty-input{ width:56px; text-align:center; background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:8px; color:var(--blanco-puro); font-size:14px; font-weight:700; font-family:inherit; padding:6px 4px; }
.invt-modal-qty-input::-webkit-outer-spin-button, .invt-modal-qty-input::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }

/* Debajo de este ancho la tabla no entra sin scroll horizontal (5 columnas,
   la última con botones): se convierte en una tarjeta por producto en vez de
   forzar un swipe lateral para ver el estado o las acciones. */
@media (max-width: 640px){
  .invt-table-wrap{ overflow-x:visible; }
  .invt-table thead{ display:none; }
  .invt-table, .invt-table tbody, .invt-table tr, .invt-table td{ display:block; width:100%; }
  .invt-table{ padding:0 16px 16px; }
  .invt-table tbody tr:not(.invt-empty-row){
    background:var(--superficie); box-shadow:var(--neu-sombra-chica); border-radius:16px;
    padding:14px 16px; margin:12px 0 0;
  }
  .invt-table tbody tr:not(.invt-empty-row):first-child{ margin-top:14px; }
  .invt-table tbody tr:not(.invt-empty-row) td{ padding:6px 0; border-bottom:none; }
  .invt-table tbody tr:not(.invt-empty-row) td:first-child{
    padding:0 0 10px; margin-bottom:8px; border-bottom:1px solid var(--divisor);
  }
  .invt-table tbody tr:not(.invt-empty-row) td:not(:first-child){
    display:flex; align-items:center; justify-content:space-between; gap:10px;
  }
  .invt-table tbody tr:not(.invt-empty-row) td:not(:first-child)::before{
    content:attr(data-label); font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.04em; color:var(--gris-tenue); flex-shrink:0;
  }
  .invt-table tbody tr:not(.invt-empty-row) td:last-child{ padding-top:10px; margin-top:4px; border-top:1px solid var(--divisor); }
  .invt-empty-row td{ padding:30px 16px; }
}
`;

function asegurarEstilosInventario() {
  if (document.getElementById(INVT_ESTILOS_ID)) return;
  const style = document.createElement("style");
  style.id = INVT_ESTILOS_ID;
  style.textContent = INVT_CSS;
  document.head.appendChild(style);
}

// Stock en 0 es "No disponible" (no queda nada para vender), "Crítico" es
// por debajo de 5 unidades (umbral fijo, de momento), y "Stock Bajo" es un
// aviso temprano a 2x el stock_minimo configurado por producto.
function estadoDeProducto(p) {
  const stock = Number(p.stock_actual);
  const minimo = Number(p.stock_minimo);
  if (stock === 0) return { key: "unavailable", label: "No disponible" };
  if (stock < 5) return { key: "critical", label: "Crítico" };
  if (stock <= minimo * 2) return { key: "low", label: "Stock Bajo" };
  return { key: "ok", label: "Disponible" };
}

function initInventarioTabla(contenedor, opciones = {}) {
  asegurarEstilosInventario();

  // Archivar/restaurar es cosa del dueño (archivar_producto.php valida el rol
  // por su cuenta; esto es sólo para no mostrar un botón que siempre daría
  // 403 al repositor, que también usa esta tabla desde repositor.html).
  const esDueno = (obtenerUsuarioSesion() || {}).rol === "dueño";
  let verArchivados = false;

  // Sólo administrador.html pasa este flag. Achica la tabla a 3 columnas
  // (Producto/Stock/Estado), mueve Marca/Acciones al flotante de detalle que
  // abre cada fila, y agrega paginado. repositor.html no lo pasa, así que
  // sigue exactamente como estaba.
  const vistaDetalle = opciones.vistaDetalle === true;
  const PRODUCTOS_POR_PAGINA = 10;
  let paginaActual = 1;
  const COLSPAN = vistaDetalle ? 3 : 5;
  // El flotante queda abierto sobre ESTE producto: refrescarFilaProducto() lo
  // usa para mantener sincronizado el input de cantidad con la fila de atrás.
  let modalProductoId = null;

  // Se resuelve ANTES de escribir el innerHTML y el markup se emite ya plegado:
  // pintar la tabla abierta para cerrarla después daría un parpadeo.
  // Arranca PLEGADA (segundo argumento): el panel entra de una en la pantalla
  // y se despliega a pedido.
  const plegable = opciones.plegable === true;
  const plegado = plegable && plegableLeer(INVT_CLAVE_PLEGADO, true);

  // Mismo criterio que el plegado: se resuelve antes de escribir el markup,
  // así el <option> nace con el `selected` puesto y no hay que corregirlo
  // después.
  let ordenActual = invtLeerOrden();

  contenedor.innerHTML = `
    <div class="invt-panel${plegado ? " invt-plegado" : ""}">
      <div class="invt-panel-head">
        <div>
          <h2 data-invt-titulo>Inventario en Tiempo Real</h2>
          <p data-invt-subtitle>Cargando catálogo...</p>
        </div>
        <div class="invt-head-acciones">
          ${esDueno ? `<button type="button" class="invt-toggle-arch" data-invt-toggle-arch>📦 Ver archivados</button>` : ""}
          <select class="invt-select" data-invt-orden aria-label="Ordenar el inventario">
            ${INVT_ORDENES.map(o => `<option value="${o.clave}"${o.clave === ordenActual ? " selected" : ""}>⇅ ${o.etiqueta}</option>`).join("")}
          </select>
          <div class="invt-search-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" data-invt-search placeholder="Buscar...">
          </div>
        </div>
        ${plegable ? `<button type="button" class="invt-toggle-pleg" data-invt-toggle-pleg aria-expanded="${plegado ? "false" : "true"}" aria-controls="invtTablaWrap"></button>` : ""}
      </div>
      <div class="invt-table-wrap" id="invtTablaWrap">
        <table class="invt-table">
          <thead>
            <tr>
              <th>Producto</th>
              ${vistaDetalle ? "" : "<th>Marca</th>"}
              <th>Stock</th>
              <th>Estado</th>
              ${vistaDetalle ? "" : "<th>Acciones</th>"}
            </tr>
          </thead>
          <tbody data-invt-tbody></tbody>
        </table>
        ${vistaDetalle ? `<div class="invt-paginador" data-invt-paginador></div>` : ""}
      </div>
    </div>
  `;

  const panel = contenedor.querySelector(".invt-panel");
  const botonPlegar = contenedor.querySelector("[data-invt-toggle-pleg]");
  const titulo = contenedor.querySelector("[data-invt-titulo]");
  const botonToggleArch = contenedor.querySelector("[data-invt-toggle-arch]");
  const subtitulo = contenedor.querySelector("[data-invt-subtitle]");
  const buscador = contenedor.querySelector("[data-invt-search]");
  const selectorOrden = contenedor.querySelector("[data-invt-orden]");
  const tbody = contenedor.querySelector("[data-invt-tbody]");
  const paginador = contenedor.querySelector("[data-invt-paginador]");

  let productos = [];
  let datosCompletos = null;

  function renderSubtitulo() {
    if (verArchivados) {
      subtitulo.textContent = productos.length === 0
        ? "No hay productos archivados."
        : `${productos.length} ${productos.length === 1 ? "archivado" : "archivados"}`;
      return;
    }
    const requierenAtencion = productos.filter(p => {
      const key = estadoDeProducto(p).key;
      return key === "critical" || key === "unavailable";
    }).length;
    subtitulo.textContent = `${productos.length} productos · ${requierenAtencion} en alerta`;
  }

  function renderPaginador(totalFiltrados) {
    if (!paginador) return;
    const totalPaginas = Math.max(1, Math.ceil(totalFiltrados / PRODUCTOS_POR_PAGINA));
    if (paginaActual > totalPaginas) paginaActual = totalPaginas;
    if (paginaActual < 1) paginaActual = 1;

    if (totalPaginas <= 1) {
      paginador.innerHTML = "";
      return totalPaginas;
    }

    paginador.innerHTML = `
      <button type="button" data-invt-pag-prev ${paginaActual <= 1 ? "disabled" : ""} aria-label="Página anterior">‹</button>
      <span>Página ${paginaActual} de ${totalPaginas}</span>
      <button type="button" data-invt-pag-next ${paginaActual >= totalPaginas ? "disabled" : ""} aria-label="Página siguiente">›</button>
    `;
    return totalPaginas;
  }

  function renderTabla(filtro = "") {
    const f = filtro.trim().toLowerCase();
    const filtrados = f
      ? productos.filter(p =>
          (p.nombre || "").toLowerCase().includes(f) || (p.marca || "").toLowerCase().includes(f)
        )
      : productos;

    if (filtrados.length === 0) {
      const vacio = verArchivados
        ? (filtro ? `No hay productos archivados que coincidan con "${filtro}"` : "No hay productos archivados.")
        : `No se encontraron productos para "${filtro}"`;
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="${COLSPAN}">${vacio}</td></tr>`;
      if (vistaDetalle) renderPaginador(0);
      return;
    }

    // El orden va ANTES del recorte del paginador: al revés ordenaría sólo
    // las 10 filas de la página actual. Sobre una copia, porque `productos`
    // es el array que recorren los productos.find() de los handlers y el que
    // guarda el orden original del servidor.
    const orden = INVT_ORDENES.find(o => o.clave === ordenActual);
    const ordenados = orden && orden.comparar
      ? [...filtrados].sort(orden.comparar)
      : filtrados;

    // El paginado sólo existe en vistaDetalle: repositor.html sigue mostrando
    // la lista completa, sin cortar.
    let paraPintar = ordenados;
    if (vistaDetalle) {
      renderPaginador(ordenados.length);
      const inicio = (paginaActual - 1) * PRODUCTOS_POR_PAGINA;
      paraPintar = ordenados.slice(inicio, inicio + PRODUCTOS_POR_PAGINA);
    }

    tbody.innerHTML = paraPintar.map(p => {
      const estado = estadoDeProducto(p);
      const nombreEscapado = p.nombre.replace(/"/g, "&quot;");

      // vistaDetalle: la fila sólo muestra Producto/Stock/Estado. Marca,
      // foto y las acciones (archivar, +/-, cantidad) se ven al hacer click,
      // en el flotante de detalle.
      if (vistaDetalle) {
        return `
        <tr data-invt-row data-id="${p.id}" class="invt-row-clickable" tabindex="0">
          <td>
            <div class="invt-prod-name">${p.nombre}${p.foto ? `<span class="fpr-indicador" aria-hidden="true">📷</span>` : ""}</div>
            <div class="invt-prod-sub">${p.marca || ""}${p.categoria ? " · " + p.categoria : ""}${verArchivados && p.archivado_en ? `<span class="invt-arch-sub"> · archivado ${p.archivado_en.slice(0, 10)}</span>` : ""}</div>
          </td>
          <td data-label="Stock" data-invt-stock>${p.stock_actual} u.</td>
          <td data-label="Estado" data-invt-badge><span class="invt-badge ${estado.key}">${estado.label}</span></td>
        </tr>`;
      }

      // En la vista de archivados no se ajusta stock ni se archiva de nuevo:
      // la única acción posible es traerlo de vuelta.
      const acciones = verArchivados
        ? `<button class="invt-eliminar invt-restaurar" data-action="restaurar" data-id="${p.id}" data-nombre="${nombreEscapado}" title="Volver a activar este producto">↩ Restaurar</button>`
        : `
            <div class="invt-stock-controls">
              <button class="invt-minus" data-action="dec" data-id="${p.id}" ${p.stock_actual <= 0 ? "disabled" : ""}>−</button>
              <span class="invt-qty" data-invt-qty>${p.stock_actual}</span>
              <button class="invt-plus" data-action="inc" data-id="${p.id}">+</button>
            </div>
            ${typeof subirFotoProducto === "function" ? `<button class="invt-eliminar" data-action="foto" data-id="${p.id}" title="${p.foto ? "Cambiar la foto" : "Agregar foto"}">📷</button>` : ""}
            ${esDueno ? `<button class="invt-eliminar" data-action="archivar" data-id="${p.id}" data-nombre="${nombreEscapado}" data-stock="${p.stock_actual}" title="Archivar producto (se puede restaurar)">📦</button>` : ""}
          `;

      return `
      <tr data-invt-row data-id="${p.id}">
        <td>
          <div class="invt-prod-name"${p.foto ? ` data-foto-url="uploads/productos/${encodeURIComponent(p.foto)}"` : ""}>${p.nombre}${p.foto ? `<span class="fpr-indicador" aria-hidden="true">📷</span>` : ""}</div>
          <div class="invt-prod-sub">${p.marca || ""}${p.categoria ? " · " + p.categoria : ""}${verArchivados && p.archivado_en ? `<span class="invt-arch-sub"> · archivado ${p.archivado_en.slice(0, 10)}</span>` : ""}</div>
        </td>
        <td data-label="Marca">${p.marca || "-"}</td>
        <td data-label="Stock" data-invt-stock>${p.stock_actual} u.</td>
        <td data-label="Estado" data-invt-badge><span class="invt-badge ${estado.key}">${estado.label}</span></td>
        <td data-label="Acciones"><div class="invt-acciones">${acciones}</div></td>
      </tr>`;
    }).join("");
  }

  function refrescarFilaProducto(id, stockNuevo, stockMinimo) {
    const prod = productos.find(p => p.id === id);
    if (!prod) return;
    prod.stock_actual = stockNuevo;
    if (stockMinimo !== undefined) prod.stock_minimo = stockMinimo;

    const estado = estadoDeProducto(prod);
    const fila = tbody.querySelector(`tr[data-id="${id}"]`);
    if (fila) {
      const stockCell = fila.querySelector("[data-invt-stock]");
      if (stockCell) stockCell.textContent = `${stockNuevo} u.`;
      const qtyEl = fila.querySelector("[data-invt-qty]");
      if (qtyEl) qtyEl.textContent = stockNuevo;
      const badgeCell = fila.querySelector("[data-invt-badge]");
      if (badgeCell) badgeCell.innerHTML = `<span class="invt-badge ${estado.key}">${estado.label}</span>`;
      // El botón de archivar ya no se deshabilita por stock: archivar con
      // unidades en el estante está permitido, el confirm lo avisa.
      const menos = fila.querySelector(".invt-minus");
      if (menos) menos.disabled = stockNuevo <= 0;
    }

    // El flotante de detalle vive fuera de la tabla: si está abierto sobre
    // ESTE producto, el input de cantidad tiene que quedar sincronizado con
    // lo que acaba de confirmar el servidor (venga del +/- de la fila, del
    // +/- del flotante, o de haber tipeado un número a mano).
    if (vistaDetalle && modalProductoId === id && modalQtyInput) {
      modalQtyInput.value = stockNuevo;
      if (modalDec) modalDec.disabled = stockNuevo <= 0;
    }

    renderSubtitulo();
  }

  async function ajustarStock(id, delta, boton) {
    boton.disabled = true;
    try {
      const resp = await fetchApi(URL_ACTUALIZAR_STOCK, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ producto_id: id, delta }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        alert(data.mensaje || "No se pudo actualizar el stock.");
        return;
      }

      refrescarFilaProducto(id, data.stock_actual, data.stock_minimo);
    } catch (err) {
      alert(mensajeDeError(err));
    } finally {
      boton.disabled = false;
      const prod = productos.find(p => p.id === id);
      if (prod) {
        const fila = tbody.querySelector(`tr[data-id="${id}"]`);
        const menos = fila && fila.querySelector(".invt-minus");
        if (menos) menos.disabled = prod.stock_actual <= 0;
      }
    }
  }

  // Archivar (baja lógica) y restaurar son el mismo request con un flag: el
  // producto nunca se borra, así que sus ventas y lotes quedan intactos y se
  // puede deshacer desde "Ver archivados".
  async function cambiarArchivado(id, nombre, boton, { restaurar, stock }) {
    const aviso = restaurar
      ? `¿Volver a activar "${nombre}"? Va a reaparecer en el inventario y en las pantallas de carga y venta.`
      : `¿Archivar "${nombre}"?\n\nDeja de aparecer en el inventario, en la carga de stock y en la caja, pero sus ventas siguen contando en los reportes. Lo podés restaurar cuando quieras desde "Ver archivados".` +
        (stock > 0 ? `\n\nOjo: quedan ${stock} unidad(es) que dejan de contarse en el inventario.` : "");

    if (!confirm(aviso)) return;

    boton.disabled = true;
    try {
      const resp = await fetchApi(URL_ARCHIVAR_PRODUCTO, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ producto_id: id, restaurar: !!restaurar }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        alert(data.mensaje || (restaurar ? "No se pudo restaurar el producto." : "No se pudo archivar el producto."));
        boton.disabled = false;
        return;
      }

      // Sale de la lista actual: archivado desaparece del catálogo,
      // restaurado desaparece de la vista de archivados.
      productos = productos.filter(p => p.id !== id);
      renderSubtitulo();
      renderTabla(buscador.value);

      // Los KPIs (críticos, más vendido) cambian al archivar, así que la
      // página que nos hospeda tiene que poder repintarlos.
      if (typeof opciones.onCambioCatalogo === "function") opciones.onCambioCatalogo();
    } catch (err) {
      alert(mensajeDeError(err));
      boton.disabled = false;
    }
  }

  // Foto desde la tabla: para los productos que nunca pasaron por la cámara de
  // la Red de Seguridad (todos los cargados antes de que existiera la foto).
  // Un solo <input type=file> reutilizado; en el celular `capture` abre la
  // cámara trasera directo.
  const inputFoto = document.createElement("input");
  inputFoto.type = "file";
  inputFoto.accept = "image/*";
  inputFoto.setAttribute("capture", "environment");
  inputFoto.hidden = true;
  contenedor.appendChild(inputFoto);
  let fotoPendiente = null; // { id, boton }

  inputFoto.addEventListener("change", async () => {
    const archivo = inputFoto.files && inputFoto.files[0];
    const pendiente = fotoPendiente;
    inputFoto.value = "";
    fotoPendiente = null;
    if (!archivo || !pendiente) return;

    pendiente.boton.disabled = true;
    try {
      // Mismo achique que la Red de Seguridad, si la página lo carga: una foto
      // de celular de 3 MB no hace falta para una vista previa.
      const liviana = typeof redimensionarImagen === "function"
        ? await redimensionarImagen(archivo)
        : archivo;
      const r = await subirFotoProducto(pendiente.id, liviana);
      if (!r || !r.ok) {
        alert((r && r.mensaje) || "No se pudo guardar la foto.");
        return;
      }
      const prod = productos.find(p => p.id === pendiente.id);
      if (prod) {
        prod.foto = r.foto;
        // Si el flotante está abierto sobre este mismo producto, la foto
        // recién subida se ve ahí también, no sólo en la fila.
        if (vistaDetalle && modalProductoId === prod.id) {
          pintarImagenModal(prod);
          renderModalAcciones(prod);
        }
      }
      renderTabla(buscador.value);
    } catch (err) {
      alert(mensajeDeError(err));
    } finally {
      pendiente.boton.disabled = false;
    }
  });

  // ------------------------------------------------------------------
  // Flotante de detalle (sólo vistaDetalle): imagen, nombre/marca, proveedor,
  // fecha de alta y el control de cantidad, más los íconos de foto/archivar
  // que en la vista normal viven en la columna Acciones.
  // ------------------------------------------------------------------
  let modalOverlay = null;
  let modalImg = null;
  let modalNombre = null;
  let modalMarca = null;
  let modalAccionesEl = null;
  let modalProveedor = null;
  let modalFecha = null;
  let modalCantidadWrap = null;
  let modalQtyInput = null;
  let modalDec = null;
  let modalInc = null;

  function pintarImagenModal(p) {
    if (!modalImg) return;
    modalImg.innerHTML = p.foto
      ? `<img src="uploads/productos/${encodeURIComponent(p.foto)}" alt="${p.nombre.replace(/"/g, "&quot;")}">`
      : "📦";
  }

  function renderModalAcciones(p) {
    if (!modalAccionesEl) return;
    const nombreEscapado = p.nombre.replace(/"/g, "&quot;");
    if (verArchivados) {
      modalAccionesEl.innerHTML = `<button type="button" class="invt-modal-accion" data-action="restaurar" data-id="${p.id}" data-nombre="${nombreEscapado}" title="Volver a activar este producto">↩</button>`;
      return;
    }
    const botones = [];
    if (typeof subirFotoProducto === "function") {
      botones.push(`<button type="button" class="invt-modal-accion" data-action="foto" data-id="${p.id}" title="${p.foto ? "Cambiar la foto" : "Agregar foto"}">📷</button>`);
    }
    if (esDueno) {
      botones.push(`<button type="button" class="invt-modal-accion" data-action="archivar" data-id="${p.id}" data-nombre="${nombreEscapado}" data-stock="${p.stock_actual}" title="Archivar producto (se puede restaurar)">📦</button>`);
    }
    modalAccionesEl.innerHTML = botones.join("");
  }

  function abrirModalDetalle(p) {
    if (!modalOverlay) return;
    modalProductoId = p.id;

    pintarImagenModal(p);
    modalNombre.textContent = p.nombre;
    modalMarca.textContent = p.marca || "Sin marca";
    modalProveedor.textContent = p.proveedor_nombre || "Sin proveedor asignado";
    // creado_en llega como "YYYY-MM-DD HH:MM:SS" (MySQL): Safari no parsea
    // ese formato con new Date() salvo que se cambie el espacio por una T.
    modalFecha.textContent = p.creado_en
      ? new Date(String(p.creado_en).replace(" ", "T")).toLocaleDateString("es-AR")
      : "—";

    renderModalAcciones(p);

    // En archivados no se ajusta stock: el producto está dado de baja.
    modalCantidadWrap.hidden = verArchivados;
    if (!verArchivados) {
      modalQtyInput.value = p.stock_actual;
      modalDec.disabled = p.stock_actual <= 0;
    }

    modalOverlay.hidden = false;
  }

  function cerrarModalDetalle() {
    if (!modalOverlay) return;
    modalOverlay.hidden = true;
    modalProductoId = null;
  }

  if (vistaDetalle) {
    modalOverlay = document.createElement("div");
    modalOverlay.className = "invt-modal-overlay";
    modalOverlay.hidden = true;
    modalOverlay.innerHTML = `
      <div class="invt-modal" role="dialog" aria-modal="true">
        <button type="button" class="invt-modal-cerrar" data-invt-modal-cerrar aria-label="Cerrar">✕</button>
        <div class="invt-modal-head">
          <div class="invt-modal-img" data-invt-modal-img>📦</div>
          <div class="invt-modal-info">
            <div class="invt-modal-nombre" data-invt-modal-nombre></div>
            <div class="invt-modal-marca" data-invt-modal-marca></div>
            <div class="invt-modal-acciones" data-invt-modal-acciones></div>
          </div>
        </div>
        <div class="invt-modal-datos">
          <div class="invt-modal-dato"><span class="invt-modal-dato-label">Proveedor</span><span data-invt-modal-proveedor></span></div>
          <div class="invt-modal-dato"><span class="invt-modal-dato-label">Agregado</span><span data-invt-modal-fecha></span></div>
        </div>
        <div class="invt-modal-cantidad" data-invt-modal-cantidad>
          <span class="invt-modal-dato-label">Cantidad</span>
          <div class="invt-stock-controls">
            <button type="button" class="invt-minus" data-invt-modal-dec>−</button>
            <input type="number" class="invt-modal-qty-input" data-invt-modal-qty-input min="0" step="1">
            <button type="button" class="invt-plus" data-invt-modal-inc>+</button>
          </div>
        </div>
      </div>
    `;
    contenedor.appendChild(modalOverlay);

    modalImg = modalOverlay.querySelector("[data-invt-modal-img]");
    modalNombre = modalOverlay.querySelector("[data-invt-modal-nombre]");
    modalMarca = modalOverlay.querySelector("[data-invt-modal-marca]");
    modalAccionesEl = modalOverlay.querySelector("[data-invt-modal-acciones]");
    modalProveedor = modalOverlay.querySelector("[data-invt-modal-proveedor]");
    modalFecha = modalOverlay.querySelector("[data-invt-modal-fecha]");
    modalCantidadWrap = modalOverlay.querySelector("[data-invt-modal-cantidad]");
    modalQtyInput = modalOverlay.querySelector("[data-invt-modal-qty-input]");
    modalDec = modalOverlay.querySelector("[data-invt-modal-dec]");
    modalInc = modalOverlay.querySelector("[data-invt-modal-inc]");

    modalOverlay.querySelector("[data-invt-modal-cerrar]").addEventListener("click", cerrarModalDetalle);
    modalOverlay.addEventListener("click", (e) => {
      if (e.target === modalOverlay) cerrarModalDetalle();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !modalOverlay.hidden) cerrarModalDetalle();
    });

    modalDec.addEventListener("click", () => {
      if (modalProductoId != null) ajustarStock(modalProductoId, -1, modalDec);
    });
    modalInc.addEventListener("click", () => {
      if (modalProductoId != null) ajustarStock(modalProductoId, 1, modalInc);
    });

    modalQtyInput.addEventListener("change", async () => {
      if (modalProductoId == null) return;
      const prod = productos.find(pp => pp.id === modalProductoId);
      if (!prod) return;
      const nuevo = parseInt(modalQtyInput.value, 10);
      if (Number.isNaN(nuevo) || nuevo < 0) {
        modalQtyInput.value = prod.stock_actual;
        return;
      }
      const delta = nuevo - prod.stock_actual;
      if (delta === 0) return;
      await ajustarStock(modalProductoId, delta, modalQtyInput);
      // Tanto si el ajuste salió bien como si no, prod.stock_actual ya
      // refleja lo que el servidor confirmó (refrescarFilaProducto lo
      // actualiza; si falló, quedó igual que antes).
      modalQtyInput.value = prod.stock_actual;
    });

    modalAccionesEl.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-action]");
      if (!btn || btn.disabled) return;
      const id = parseInt(btn.dataset.id, 10);

      if (btn.dataset.action === "foto") {
        fotoPendiente = { id, boton: btn };
        inputFoto.click();
        return;
      }

      if (btn.dataset.action === "archivar" || btn.dataset.action === "restaurar") {
        cambiarArchivado(id, btn.dataset.nombre || "este producto", btn, {
          restaurar: btn.dataset.action === "restaurar",
          stock: parseInt(btn.dataset.stock || "0", 10),
        }).then(() => {
          // Si salió bien, el producto ya no está en `productos` (archivar lo
          // saca del catálogo, restaurar lo saca de la vista de archivados):
          // no tiene sentido dejar el flotante abierto sobre algo que ya no
          // está en la lista actual.
          if (!productos.find(pp => pp.id === id)) cerrarModalDetalle();
        });
      }
    });
  }

  tbody.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-action]");
    if (btn && !btn.disabled) {
      const id = parseInt(btn.dataset.id, 10);

      if (btn.dataset.action === "foto") {
        fotoPendiente = { id, boton: btn };
        inputFoto.click();
        return;
      }

      if (btn.dataset.action === "archivar" || btn.dataset.action === "restaurar") {
        cambiarArchivado(id, btn.dataset.nombre || "este producto", btn, {
          restaurar: btn.dataset.action === "restaurar",
          stock: parseInt(btn.dataset.stock || "0", 10),
        });
        return;
      }

      const delta = btn.dataset.action === "inc" ? 1 : -1;
      ajustarStock(id, delta, btn);
      return;
    }

    // vistaDetalle: sin botones en la fila, el click en cualquier parte de
    // la fila abre el flotante de detalle.
    if (vistaDetalle) {
      const fila = e.target.closest("tr[data-invt-row]");
      if (!fila) return;
      const id = parseInt(fila.dataset.id, 10);
      const prod = productos.find(pp => pp.id === id);
      if (prod) abrirModalDetalle(prod);
    }
  });

  if (vistaDetalle) {
    tbody.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" && e.key !== " ") return;
      const fila = e.target.closest("tr[data-invt-row]");
      if (!fila) return;
      e.preventDefault();
      const id = parseInt(fila.dataset.id, 10);
      const prod = productos.find(pp => pp.id === id);
      if (prod) abrirModalDetalle(prod);
    });
  }

  if (paginador) {
    paginador.addEventListener("click", (e) => {
      const btn = e.target.closest("button");
      if (!btn || btn.disabled) return;
      if (btn.hasAttribute("data-invt-pag-prev")) paginaActual--;
      else if (btn.hasAttribute("data-invt-pag-next")) paginaActual++;
      else return;
      renderTabla(buscador.value);
    });
  }

  buscador.addEventListener("input", (e) => {
    paginaActual = 1;
    renderTabla(e.target.value);
  });

  selectorOrden.addEventListener("change", () => {
    ordenActual = selectorOrden.value;
    invtGuardarOrden(ordenActual);
    // Volver a la primera página, igual que el buscador: quedarse en la
    // página 4 después de reordenar muestra un tramo del medio de una lista
    // que ya no es la misma.
    paginaActual = 1;
    renderTabla(buscador.value);
  });

  // La opción por defecto no reordena nada: deja el orden que mandó el
  // servidor, que en el catálogo es nombre A-Z y en archivados es fecha de
  // baja descendente. Son dos cosas distintas, así que la etiqueta tiene que
  // decir cuál de las dos está haciendo.
  function renderEtiquetaOrdenDefault() {
    const opcion = selectorOrden.options[0];
    const def = INVT_ORDENES[0];
    opcion.textContent = `⇅ ${verArchivados ? def.etiquetaArchivados : def.etiqueta}`;
  }

  if (botonToggleArch) {
    botonToggleArch.addEventListener("click", () => {
      verArchivados = !verArchivados;
      botonToggleArch.classList.toggle("activo", verArchivados);
      botonToggleArch.textContent = verArchivados ? "↩ Volver al inventario" : "📦 Ver archivados";
      titulo.textContent = verArchivados ? "Productos archivados" : "Inventario en Tiempo Real";
      renderEtiquetaOrdenDefault();
      subtitulo.textContent = "Cargando...";
      // El flotante mostraría datos de un producto que puede haber
      // desaparecido de esta vista (o al revés); más simple cerrarlo.
      if (vistaDetalle) cerrarModalDetalle();
      cargar();
    });
  }

  async function cargar() {
    // Cambió el catálogo entero: no tiene sentido quedarse en la página 7 si
    // la búsqueda o el modo (archivados/activos) ahora tienen menos páginas.
    paginaActual = 1;
    try {
      const resp = await fetchApi(URL_CONSULTAR_INVENTARIO, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ archivados: verArchivados }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        subtitulo.textContent = "No se pudo cargar el inventario.";
        tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="${COLSPAN}">${data.mensaje || "Error al cargar el catálogo."}</td></tr>`;
        return;
      }

      productos = data.productos || [];
      renderSubtitulo();
      renderTabla(buscador.value);

      // Los KPIs de la página se pintan sólo con la vista normal: en modo
      // archivados, `productos` son los dados de baja y repintar con eso
      // mostraría "0 productos críticos" en el dashboard.
      if (!verArchivados) {
        datosCompletos = data;
        if (typeof opciones.onDatos === "function") opciones.onDatos(data);
      }
    } catch (err) {
      subtitulo.textContent = mensajeDeError(err);
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="${COLSPAN}">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
    }
  }

  if (botonPlegar) {
    plegableConectar({
      panel: panel,
      boton: botonPlegar,
      clave: INVT_CLAVE_PLEGADO,
      clasePlegada: "invt-plegado",
      etiqueta: "tabla",
    });
  }

  // Se carga SIEMPRE, aunque la tabla arranque plegada. Este fetch alimenta,
  // vía onDatos, los KPI cards de administrador.html ("Alertas de Stock
  // Crítico", "Producto Más Vendido", "Ventas Estimadas Hoy"): diferirlo hasta
  // la primera apertura dejaría el dashboard entero vacío. Además el subtítulo,
  // que es lo único visible con la tabla plegada, sale de acá.
  cargar();

  return {
    recargar: cargar,
    obtenerDatos: () => datosCompletos,
  };
}
