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
 *   });
 *
 * Clases CSS con prefijo "invt-" para no chocar con estilos ya definidos en
 * styles.css (.panel, .kpi-card, etc. se reutilizan en otras pantallas).
 */

const URL_CONSULTAR_INVENTARIO = "https://atrium-overtime-deluxe.ngrok-free.dev/stockiate/tesis_enzo/consultar_inventario.php";
const URL_ACTUALIZAR_STOCK = "https://atrium-overtime-deluxe.ngrok-free.dev/stockiate/tesis_enzo/actualizar_stock.php";
const URL_ELIMINAR_PRODUCTO = "https://atrium-overtime-deluxe.ngrok-free.dev/stockiate/tesis_enzo/eliminar_producto.php";

// Evita la página de advertencia HTML de ngrok free tier en vez de la respuesta JSON real.
// Prefijo INVT_ porque este archivo se carga junto a otro <script> que ya
// declara su propio HEADERS_NGROK en el scope global de la página.
const INVT_HEADERS_NGROK = { "ngrok-skip-browser-warning": "true" };

const INVT_ESTILOS_ID = "invt-estilos";
const INVT_CSS = `
.invt-panel{ background:#161b29; border:1px solid #252b3d; border-radius:18px; overflow:hidden; color:#e8eaf2; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
.invt-panel-head{ padding:18px 20px; border-bottom:1px solid #252b3d; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.invt-panel-head h2{ font-size:15px; font-weight:700; margin:0; }
.invt-panel-head p{ font-size:12px; color:#9aa1b5; margin:2px 0 0; }
.invt-search-box{ display:flex; align-items:center; gap:8px; background:#1e2436; border:1px solid #252b3d; border-radius:10px; padding:8px 12px; min-width:230px; }
.invt-search-box input{ background:transparent; border:none; outline:none; color:#e8eaf2; font-size:13px; width:100%; font-family:inherit; }
.invt-search-box input::placeholder{ color:#5c6480; }
.invt-search-box svg{ flex-shrink:0; opacity:.6; }
.invt-table-wrap{ overflow-x:auto; }
.invt-table{ width:100%; border-collapse:collapse; font-size:13.5px; }
.invt-table thead th{ text-align:left; padding:10px 20px; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#5c6480; border-bottom:1px solid #252b3d; font-weight:700; background:#161b29; }
.invt-table tbody td{ padding:12px 20px; border-bottom:1px solid #252b3d; vertical-align:middle; }
.invt-table tbody tr:last-child td{ border-bottom:none; }
.invt-table tbody tr:hover{ background:rgba(124,92,255,0.05); }
.invt-prod-name{ font-weight:600; }
.invt-prod-sub{ font-size:12px; color:#9aa1b5; }
.invt-badge{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:700; }
.invt-badge.ok{ background:rgba(61,220,132,0.12); color:#3ddc84; }
.invt-badge.low{ background:rgba(255,181,71,0.12); color:#ffb547; }
.invt-badge.critical{ background:rgba(255,92,114,0.14); color:#ff5c72; }
.invt-badge.unavailable{ background:rgba(154,161,181,0.16); color:#9aa1b5; }
.invt-stock-controls{ display:flex; align-items:center; gap:8px; }
.invt-stock-controls button{ width:26px; height:26px; border-radius:7px; border:1px solid #252b3d; background:#1e2436; color:#e8eaf2; cursor:pointer; font-size:15px; font-weight:700; line-height:1; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-stock-controls button:hover:not(:disabled){ background:#7c5cff; border-color:#7c5cff; }
.invt-stock-controls button.invt-minus:hover:not(:disabled){ background:#ff5c72; border-color:#ff5c72; }
.invt-stock-controls button:disabled{ opacity:.35; cursor:not-allowed; }
.invt-stock-controls .invt-qty{ min-width:26px; text-align:center; font-weight:700; }
.invt-acciones{ display:flex; align-items:center; gap:14px; }
.invt-eliminar{ width:26px; height:26px; border-radius:7px; border:1px solid #252b3d; background:#1e2436; color:#e8eaf2; cursor:pointer; font-size:13px; line-height:1; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.invt-eliminar:hover:not(:disabled){ background:#ff5c72; border-color:#ff5c72; }
.invt-eliminar:disabled{ opacity:.25; cursor:not-allowed; }
.invt-empty-row td{ text-align:center; padding:30px; color:#5c6480; }
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

  contenedor.innerHTML = `
    <div class="invt-panel">
      <div class="invt-panel-head">
        <div>
          <h2>Inventario en Tiempo Real</h2>
          <p data-invt-subtitle>Cargando catálogo...</p>
        </div>
        <div class="invt-search-box">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="text" data-invt-search placeholder="Buscar por nombre o marca...">
        </div>
      </div>
      <div class="invt-table-wrap">
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

  const subtitulo = contenedor.querySelector("[data-invt-subtitle]");
  const buscador = contenedor.querySelector("[data-invt-search]");
  const tbody = contenedor.querySelector("[data-invt-tbody]");

  let productos = [];
  let datosCompletos = null;

  function renderSubtitulo() {
    const requierenAtencion = productos.filter(p => {
      const key = estadoDeProducto(p).key;
      return key === "critical" || key === "unavailable";
    }).length;
    subtitulo.textContent = `${productos.length} productos · ${requierenAtencion} requieren atención (crítico o sin stock)`;
  }

  function renderTabla(filtro = "") {
    const f = filtro.trim().toLowerCase();
    const filtrados = f
      ? productos.filter(p =>
          (p.nombre || "").toLowerCase().includes(f) || (p.marca || "").toLowerCase().includes(f)
        )
      : productos;

    if (filtrados.length === 0) {
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">No se encontraron productos para "${filtro}"</td></tr>`;
      return;
    }

    tbody.innerHTML = filtrados.map(p => {
      const estado = estadoDeProducto(p);
      return `
      <tr data-invt-row data-id="${p.id}">
        <td>
          <div class="invt-prod-name">${p.nombre}</div>
          <div class="invt-prod-sub">${p.marca || ""}${p.categoria ? " · " + p.categoria : ""}</div>
        </td>
        <td>${p.marca || "-"}</td>
        <td data-invt-stock>${p.stock_actual} u.</td>
        <td data-invt-badge><span class="invt-badge ${estado.key}">${estado.label}</span></td>
        <td>
          <div class="invt-acciones">
            <div class="invt-stock-controls">
              <button class="invt-minus" data-action="dec" data-id="${p.id}" ${p.stock_actual <= 0 ? "disabled" : ""}>−</button>
              <span class="invt-qty" data-invt-qty>${p.stock_actual}</span>
              <button class="invt-plus" data-action="inc" data-id="${p.id}">+</button>
            </div>
            <button class="invt-eliminar" data-action="eliminar" data-id="${p.id}" data-nombre="${p.nombre.replace(/"/g, "&quot;")}" ${p.stock_actual > 0 ? 'disabled title="Solo se puede eliminar con stock en 0"' : 'title="Eliminar producto"'}>🗑</button>
          </div>
        </td>
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
    fila.querySelector(".invt-minus").disabled = stockNuevo <= 0;
    fila.querySelector(".invt-eliminar").disabled = stockNuevo > 0;

    renderSubtitulo();
  }

  async function ajustarStock(id, delta, boton) {
    boton.disabled = true;
    try {
      const resp = await fetch(URL_ACTUALIZAR_STOCK, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ producto_id: id, delta, usuario_id: 1 }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        alert(data.mensaje || "No se pudo actualizar el stock.");
        return;
      }

      refrescarFilaProducto(id, data.stock_actual, data.stock_minimo);
    } catch (err) {
      alert("No se pudo conectar con el servidor local (XAMPP).");
    } finally {
      boton.disabled = false;
      const prod = productos.find(p => p.id === id);
      if (prod) {
        const fila = tbody.querySelector(`tr[data-id="${id}"]`);
        if (fila) {
          fila.querySelector(".invt-minus").disabled = prod.stock_actual <= 0;
          fila.querySelector(".invt-eliminar").disabled = prod.stock_actual > 0;
        }
      }
    }
  }

  async function eliminarProducto(id, nombre, boton) {
    const confirmado = confirm(`¿Estás seguro de que querés eliminar "${nombre}"? Esta acción no se puede deshacer.`);
    if (!confirmado) return;

    boton.disabled = true;
    try {
      const resp = await fetch(URL_ELIMINAR_PRODUCTO, {
        method: "POST",
        headers: { ...INVT_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ producto_id: id }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        alert(data.mensaje || "No se pudo eliminar el producto.");
        boton.disabled = false;
        return;
      }

      productos = productos.filter(p => p.id !== id);
      renderSubtitulo();
      renderTabla(buscador.value);
    } catch (err) {
      alert("No se pudo conectar con el servidor local (XAMPP).");
      boton.disabled = false;
    }
  }

  tbody.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-action]");
    if (!btn || btn.disabled) return;
    const id = parseInt(btn.dataset.id, 10);

    if (btn.dataset.action === "eliminar") {
      eliminarProducto(id, btn.dataset.nombre || "este producto", btn);
      return;
    }

    const delta = btn.dataset.action === "inc" ? 1 : -1;
    ajustarStock(id, delta, btn);
  });

  buscador.addEventListener("input", (e) => {
    renderTabla(e.target.value);
  });

  async function cargar() {
    try {
      const resp = await fetch(URL_CONSULTAR_INVENTARIO, { method: "POST", headers: { ...INVT_HEADERS_NGROK } });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        subtitulo.textContent = "No se pudo cargar el inventario.";
        tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">${data.mensaje || "Error al cargar el catálogo."}</td></tr>`;
        return;
      }

      productos = data.productos || [];
      datosCompletos = data;
      renderSubtitulo();
      renderTabla(buscador.value);

      if (typeof opciones.onDatos === "function") opciones.onDatos(data);
    } catch (err) {
      subtitulo.textContent = "No se pudo conectar con el servidor local (XAMPP).";
      tbody.innerHTML = `<tr class="invt-empty-row"><td colspan="5">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
    }
  }

  cargar();

  return {
    recargar: cargar,
    obtenerDatos: () => datosCompletos,
  };
}
