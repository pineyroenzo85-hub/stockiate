/**
 * stockIAte - prediccion_tabla.js
 * ================================
 * Bloque "Riesgo de quiebre de stock" del panel de administrador: productos
 * que TODAVÍA no están en stock crítico pero que, al ritmo de venta de los
 * últimos días, se van a agotar pronto.
 *
 * POR QUÉ EXISTE ESTA PANTALLA
 * ----------------------------
 * El KPI de "stock crítico" (alertas.php / es_critico()) sólo mira un
 * umbral fijo por producto (stock_actual <= stock_minimo). Un producto con
 * stock_minimo=5 y 40 unidades en el estante no aparece ahí -- pero si se
 * están vendiendo 15 por día, se termina en menos de tres. Esta pantalla es
 * la que agarra ESE caso, cruzando stock actual con ritmo de venta real en
 * vez de un número fijo cargado una vez.
 *
 * Lee de `consultar_prediccion_quiebre.php` (la misma función,
 * productos_en_riesgo_quiebre() en alertas.php, que usa
 * tareas_notificaciones.php para el aviso por WhatsApp -- así la pantalla y
 * el WhatsApp nunca se contradicen). Sólo lectura: acá no se edita nada, es
 * de solo lectura, sólo-dueño.
 *
 * ⚠️ Es una heurística (ritmo constante de los últimos días), no un
 * pronóstico real -- lo aclara el tooltip del ⓘ junto al título. Antes era un
 * párrafo fijo arriba de la tabla, y se sacó para que el panel se lea de un
 * vistazo.
 *
 * Uso:
 *   initPrediccionQuiebre(document.getElementById("contenedor"));
 *
 * Clases con prefijo "prd-" por la misma razón que "cst-"/"invt-"/"ntf-": no
 * chocar con styles.css ni con el Tailwind de administrador.html.
 */

const URL_PRD_PREDICCION = "consultar_prediccion_quiebre.php";

// Prefijo PRD_ porque este archivo convive en el scope global con
// inventario_tabla.js, costos_tabla.js y preferencias.js, que ya
// declaran los suyos.
const PRD_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

const PRD_UMBRALES = [7, 14, 30];
const PRD_UMBRAL_DEFAULT = 7;

const PRD_ESTILOS_ID = "prd-estilos";
const PRD_CSS = `
.prd-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.prd-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.prd-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.prd-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.prd-umbral{ display:flex; align-items:center; gap:6px; background:var(--superficie); box-shadow:var(--neu-inset); border-radius:10px; padding:4px; }
.prd-umbral button{ background:none; border:none; color:var(--gris-tenue); font-size:12px; font-weight:700; padding:6px 10px; border-radius:7px; cursor:pointer; font-family:inherit; }
.prd-umbral button.activo{ background:var(--lila-dark); color:#fff; }
/* La aclaración de que es una estimación vive en el tooltip del ⓘ: a la vista
   era un párrafo entero arriba de cada tabla. */
.prd-info{ cursor:help; color:var(--gris-tenue); font-weight:600; font-size:13px; margin-left:4px; }
/* Sin nada en riesgo no se dibuja la tabla: el subtítulo ya lo dice. */
.prd-panel.prd-vacio .prd-table-wrap{ display:none; }
.prd-panel.prd-vacio .prd-panel-head{ border-bottom:none; }
.prd-panel.prd-vacio [data-prd-subtitle]{ color:var(--ok); font-weight:700; }
.prd-table-wrap{ overflow-x:auto; }
.prd-table{ width:100%; border-collapse:collapse; font-size:13.5px; }
.prd-table th{ text-align:left; padding:10px 20px; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; }
.prd-table td{ padding:12px 20px; border-bottom:1px solid var(--divisor); vertical-align:middle; }
.prd-table tr:last-child td{ border-bottom:none; }
.prd-prod-name{ font-weight:700; }
.prd-prod-sub{ font-size:12px; color:var(--gris-tenue); }
.prd-dias{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:700; white-space:nowrap; }
.prd-dias.urgente{ background:var(--error-bg); color:var(--error); }
.prd-dias.pronto{ background:var(--alerta-bg); color:var(--alerta); }
.prd-ritmo{ color:var(--gris-tenue); font-size:12.5px; white-space:nowrap; }
.prd-empty-row td{ text-align:center; padding:34px 20px; color:var(--gris-tenue); }
.prd-empty-row .prd-empty-ok{ color:var(--ok); font-weight:700; }

@media (max-width: 640px){
  .prd-table-wrap{ overflow-x:visible; }
  .prd-table thead{ display:none; }
  .prd-table, .prd-table tbody, .prd-table tr, .prd-table td{ display:block; width:100%; }
  .prd-table{ padding:0 16px 16px; }
  .prd-table tbody tr:not(.prd-empty-row){
    background:var(--superficie); box-shadow:var(--neu-sombra-chica); border-radius:16px;
    padding:14px 16px; margin:12px 0 0;
  }
  .prd-table tbody tr:not(.prd-empty-row):first-child{ margin-top:14px; }
  .prd-table tbody tr:not(.prd-empty-row) td{ padding:6px 0; border-bottom:none; }
  .prd-table tbody tr:not(.prd-empty-row) td:first-child{
    padding:0 0 10px; margin-bottom:8px; border-bottom:1px solid var(--divisor);
  }
  .prd-table tbody tr:not(.prd-empty-row) td:not(:first-child){
    display:flex; align-items:center; justify-content:space-between; gap:10px;
  }
  .prd-table tbody tr:not(.prd-empty-row) td:not(:first-child)::before{
    content:attr(data-label); font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.04em; color:var(--gris-tenue); flex-shrink:0;
  }
  .prd-empty-row td{ padding:30px 16px; }
}
`;

function asegurarEstilosPrediccion() {
  if (document.getElementById(PRD_ESTILOS_ID)) return;
  const style = document.createElement("style");
  style.id = PRD_ESTILOS_ID;
  style.textContent = PRD_CSS;
  document.head.appendChild(style);
}

function prdClaseDias(dias, umbral) {
  return dias <= umbral / 2 ? "urgente" : "pronto";
}

function initPrediccionQuiebre(contenedor, opciones = {}) {
  asegurarEstilosPrediccion();

  let umbral = PRD_UMBRAL_DEFAULT;

  contenedor.innerHTML = `
    <div class="prd-panel">
      <div class="prd-panel-head">
        <div>
          <h2>Riesgo de quiebre de stock<span class="prd-info" tabindex="0" role="note"
              title="Estimado con el ritmo de venta de los últimos 14 días, no es un pronóstico exacto. Los que ya están en stock crítico no aparecen acá."
              aria-label="Estimado con el ritmo de venta de los últimos 14 días, no es un pronóstico exacto. Los que ya están en stock crítico no aparecen acá.">ⓘ</span></h2>
          <p data-prd-subtitle>Calculando...</p>
        </div>
        <div class="prd-umbral" data-prd-umbral>
          ${PRD_UMBRALES.map(d => `<button type="button" data-dias="${d}" class="${d === PRD_UMBRAL_DEFAULT ? "activo" : ""}">${d} días</button>`).join("")}
        </div>
      </div>
      <div class="prd-table-wrap">
        <table class="prd-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Stock</th>
              <th>Vende</th>
              <th>Se agota en</th>
            </tr>
          </thead>
          <tbody data-prd-tbody></tbody>
        </table>
      </div>
    </div>
  `;

  const panel = contenedor.querySelector(".prd-panel");
  const subtitulo = contenedor.querySelector("[data-prd-subtitle]");
  const botonesUmbral = contenedor.querySelectorAll("[data-prd-umbral] button");
  const tbody = contenedor.querySelector("[data-prd-tbody]");

  function renderTabla(productos) {
    panel.classList.toggle("prd-vacio", productos.length === 0);
    if (productos.length === 0) {
      tbody.innerHTML = "";
      return;
    }

    tbody.innerHTML = productos.map(p => `
      <tr>
        <td>
          <div class="prd-prod-name">${p.nombre}</div>
          <div class="prd-prod-sub">${p.sku}${p.marca ? " · " + p.marca : ""}</div>
        </td>
        <td data-label="Stock">${p.stock_actual} u.</td>
        <td data-label="Vende"><span class="prd-ritmo">~${p.tasa_diaria} u./día</span></td>
        <td data-label="Se agota en"><span class="prd-dias ${prdClaseDias(p.dias_restantes, umbral)}">${p.dias_restantes} día(s)</span></td>
      </tr>
    `).join("");
  }

  async function cargar() {
    try {
      const resp = await fetchApi(URL_PRD_PREDICCION, {
        method: "POST",
        headers: { ...PRD_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ dias_umbral: umbral }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        panel.classList.remove("prd-vacio");
        subtitulo.textContent = "No se pudo calcular el riesgo de quiebre.";
        tbody.innerHTML = `<tr class="prd-empty-row"><td colspan="4">${data.mensaje || "Error al consultar la predicción."}</td></tr>`;
        return;
      }

      subtitulo.textContent = data.total === 0
        ? `✓ Nada se agota en ${umbral} días`
        : `${data.total} ${data.total === 1 ? "producto se agota" : "productos se agotan"} en ${umbral} días o menos`;

      renderTabla(data.productos || []);

      if (typeof opciones.onDatos === "function") opciones.onDatos(data);
    } catch (err) {
      panel.classList.remove("prd-vacio");
      subtitulo.textContent = mensajeDeError(err);
      tbody.innerHTML = `<tr class="prd-empty-row"><td colspan="4">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
    }
  }

  botonesUmbral.forEach(btn => {
    btn.addEventListener("click", () => {
      umbral = parseInt(btn.dataset.dias, 10);
      botonesUmbral.forEach(b => b.classList.toggle("activo", b === btn));
      cargar();
    });
  });

  cargar();

  return { recargar: cargar };
}
