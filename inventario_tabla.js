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
.invt-stock-controls button.invt-minus:hover:not(:disabled){ background:var(--coral); color:var(--texto-sobre-acento); }
.invt-stock-controls button:disabled{ opacity:.35; cursor:not-allowed; }
.invt-stock-controls .invt-qty{ min-width:26px; text-align:center; font-weight:700; }
.invt-acciones{ display:flex; align-items:center; gap:14px; }
.invt-eliminar{ width:26px; height:26px; border-radius:7px; border:none; background:var(--superficie); box-shadow:var(--neu-sombra-chica); color:var(--blanco-puro); cursor:pointer; font-size:13px; line-height:1; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-eliminar:hover:not(:disabled){ background:var(--coral); color:var(--texto-sobre-acento); }
.invt-eliminar:disabled{ opacity:.25; cursor:not-allowed; }
.invt-restaurar{ width:auto; padding:0 10px; gap:6px; font-size:12px; font-weight:700; }
.invt-restaurar:hover:not(:disabled){ background:var(--menta); color:var(--texto-sobre-acento); }
.invt-toggle-arch{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.invt-toggle-arch:hover{ color:var(--blanco-puro); }
.invt-toggle-arch.activo{ background:var(--lila-tenue); color:var(--lila-dark); box-shadow:var(--neu-inset); }
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

  // Se resuelve ANTES de escribir el innerHTML y el markup se emite ya plegado:
  // pintar la tabla abierta para cerrarla después daría un parpadeo.
  // Arranca PLEGADA (segundo argumento): el panel entra de una en la pantalla
  // y se despliega a pedido.
  const plegable = opciones.plegable === true;
  const plegado = plegable && plegableLeer(INVT_CLAVE_PLEGADO, true);

  contenedor.innerHTML = `
    <div class="invt-panel${plegado ? " invt-plegado" : ""}">
      <div class="invt-panel-head">
        <div>
          <h2 data-invt-titulo>Inventario en Tiempo Real</h2>
          <p data-invt-subtitle>Cargando catálogo...</p>
        </div>
        <div class="invt-head-acciones">
          ${esDueno ? `<button type="button" class="invt-toggle-arch" data-invt-toggle-arch>📦 Ver archivados</button>` : ""}
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
              <th>Marca</th>
              <th>Stock</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody data-invt-tbody></tbody>
        </table>
      </div>
    </div>
  `;

  const panel = contenedor.querySelector(".invt-panel");
  const botonPlegar = contenedor.querySelector("[data-invt-toggle-pleg]");
  const titulo = contenedor.querySelector("[data-invt-titulo]");
  const botonToggleArch = contenedor.querySelector("[data-invt-toggle-arch]");
  const subtitulo = contenedor.querySelector("[data-invt-subtitle]");
  const buscador = contenedor.querySelector("[data-invt-search]");
  const tbody = contenedor.querySelector("[data-invt-tbody]");

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
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">${vacio}</td></tr>`;
      return;
    }

    tbody.innerHTML = filtrados.map(p => {
      const estado = estadoDeProducto(p);
      const nombreEscapado = p.nombre.replace(/"/g, "&quot;");

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

    const fila = tbody.querySelector(`tr[data-id="${id}"]`);
    if (!fila) return;

    const estado = estadoDeProducto(prod);
    fila.querySelector("[data-invt-stock]").textContent = `${stockNuevo} u.`;
    fila.querySelector("[data-invt-qty]").textContent = stockNuevo;
    fila.querySelector("[data-invt-badge]").innerHTML = `<span class="invt-badge ${estado.key}">${estado.label}</span>`;
    // El botón de archivar ya no se deshabilita por stock: archivar con
    // unidades en el estante está permitido, el confirm lo avisa.
    const menos = fila.querySelector(".invt-minus");
    if (menos) menos.disabled = stockNuevo <= 0;

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
      if (prod) prod.foto = r.foto;
      renderTabla(buscador.value);
    } catch (err) {
      alert(mensajeDeError(err));
    } finally {
      pendiente.boton.disabled = false;
    }
  });

  tbody.addEventListener("click", (e) => {
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
      });
      return;
    }

    const delta = btn.dataset.action === "inc" ? 1 : -1;
    ajustarStock(id, delta, btn);
  });

  buscador.addEventListener("input", (e) => {
    renderTabla(e.target.value);
  });

  if (botonToggleArch) {
    botonToggleArch.addEventListener("click", () => {
      verArchivados = !verArchivados;
      botonToggleArch.classList.toggle("activo", verArchivados);
      botonToggleArch.textContent = verArchivados ? "↩ Volver al inventario" : "📦 Ver archivados";
      titulo.textContent = verArchivados ? "Productos archivados" : "Inventario en Tiempo Real";
      subtitulo.textContent = "Cargando...";
      cargar();
    });
  }

  async function cargar() {
    try {
      const resp = await fetchApi(URL_CONSULTAR_INVENTARIO, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ archivados: verArchivados }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        subtitulo.textContent = "No se pudo cargar el inventario.";
        tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">${data.mensaje || "Error al cargar el catálogo."}</td></tr>`;
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
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
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
