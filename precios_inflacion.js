/**
 * stockIAte - precios_inflacion.js
 * ================================
 * Lógica de precios_inflacion.html (el módulo "Precios e inflación" del
 * panel, sólo dueño): proyección de precios por inflación, desglose fiscal
 * (neto/IVA/IIBB) y alerta de precio atrasado.
 *
 * Lee de consultar_precios.php una sola vez; las proyecciones, el % de
 * atraso y el redondeo a $10 son puramente aritméticos sobre lo que ya
 * viajó, así que se recalculan en el cliente cada vez que se toca un control
 * (mismo criterio que costos_tabla.js con el margen).
 *
 * TRES CONTROLES SE GUARDAN, UNO NO
 * ----------------------------------
 * Inflación mensual, IVA e IIBB son supuestos reales del negocio (el IVA
 * incluso lo usa cajero.html para el ticket) y persisten en `configuracion`
 * vía guardar_preferencias.php, igual que el resto del panel. El horizonte
 * (1/3/6/12 meses) es sólo una ventana de la vista -- no tiene clave en
 * configuracion.php -- y cambiarlo nunca dispara un guardado.
 */

const URL_PIN_PRECIOS = "consultar_precios.php";
const URL_PIN_ACTUALIZAR = "actualizar_precios.php";
const URL_PIN_GUARDAR_PREF = "guardar_preferencias.php";
const URL_PIN_IPC = "consultar_ipc_indec.php";

const $ = (id) => document.getElementById(id);

let productos = []; // lo que devuelve el servidor + campos calculados (ver calcularFila)
let config = { inflacion_mensual: 2, alicuota_iva: 21, alicuota_iibb: 3.5, umbral_atraso_precio: 5 };
let horizonteMeses = 3; // vista únicamente, no se guarda
let productoSeleccionadoId = null;
let filtro = "";
const seleccionados = new Set();

/* ------------------------------------------------------------------
   Utilidades
   ------------------------------------------------------------------ */
function pinMoneda(n) {
  if (n === null || n === undefined || !isFinite(n)) return "—";
  return `$${Number(n).toLocaleString("es-AR", { maximumFractionDigits: 0 })}`;
}

function redondearA10(n) {
  return Math.round(n / 10) * 10;
}

function pinFecha(iso) {
  if (!iso) return "—";
  return new Date(iso.replace(" ", "T")).toLocaleDateString("es-AR");
}

// Aproximación deliberada: un mes calendario no tiene un largo fijo, y para
// esta pantalla (¿cuánta inflación pasó desde el último aumento?) alcanza
// con 30 días parejos -- la precisión de calendario no cambia si un producto
// aparece "atrasado" o no.
function mesesDesde(iso) {
  const ms = Date.now() - new Date(iso.replace(" ", "T")).getTime();
  return ms / (1000 * 60 * 60 * 24 * 30);
}

function proyeccion(precio, inflPct, meses) {
  return precio * Math.pow(1 + inflPct / 100, meses);
}

/* ------------------------------------------------------------------
   Cálculo por producto
   ------------------------------------------------------------------ */
function calcularFila(p) {
  const infl = config.inflacion_mensual;
  const precioEnHorizonte = redondearA10(proyeccion(p.precio_venta, infl, horizonteMeses));

  let atrasoPct = null;
  let sugerido = null;
  let estado = "sin_datos"; // sin historial_precios: no se puede saber desde cuándo rige el precio

  if (p.ultimo_aumento) {
    const meses = Math.max(mesesDesde(p.ultimo_aumento), 0);
    atrasoPct = (Math.pow(1 + infl / 100, meses) - 1) * 100;
    sugerido = redondearA10(p.precio_venta * (1 + atrasoPct / 100));

    const umbralAmbar = config.umbral_atraso_precio;
    // El rojo (>10% en el pedido original) no tiene clave propia en
    // configuracion.php: se deriva del único umbral configurable para no
    // sumar un segundo número sin forma de ajustarlo desde el panel.
    const umbralRojo = umbralAmbar * 2;
    estado = atrasoPct > umbralRojo ? "rojo" : atrasoPct > umbralAmbar ? "ambar" : "ok";
  }

  return { ...p, precio_en_horizonte: precioEnHorizonte, atraso_pct: atrasoPct, sugerido, estado };
}

function preseleccionarAtrasados() {
  seleccionados.clear();
  productos.forEach((p) => {
    if (p.estado === "ambar" || p.estado === "rojo") seleccionados.add(p.id);
  });
}

/* ------------------------------------------------------------------
   KPIs
   ------------------------------------------------------------------ */
function renderKpis() {
  const infl = config.inflacion_mensual;
  const acumulada = (Math.pow(1 + infl / 100, horizonteMeses) - 1) * 100;

  const valorHoy = productos.reduce((acc, p) => acc + p.precio_venta * p.stock_actual, 0);
  const valorProyectado = productos.reduce((acc, p) => acc + p.precio_en_horizonte * p.stock_actual, 0);

  const conDatos = productos.filter((p) => p.ultimo_aumento);
  const atrasados = conDatos.filter((p) => p.estado === "ambar" || p.estado === "rojo").length;
  const muyAtrasados = conDatos.filter((p) => p.estado === "rojo").length;

  const ivaStock = productos.reduce((acc, p) => {
    const neto = p.precio_venta / (1 + config.alicuota_iva / 100);
    return acc + (p.precio_venta - neto) * p.stock_actual;
  }, 0);

  const horiz = `${horizonteMeses} mes${horizonteMeses === 1 ? "" : "es"}`;
  const cards = [
    { label: `Inflación acumulada (${horiz})`, value: `${acumulada.toFixed(1)}%`, sub: `${infl}% mensual`, cls: "" },
    { label: "Valor del stock proyectado", value: pinMoneda(valorProyectado), sub: `Hoy: ${pinMoneda(valorHoy)}`, cls: "" },
    {
      label: "Precios atrasados",
      value: `${atrasados} de ${conDatos.length}`,
      sub: atrasados > 0 ? "Revisar precios" : "Todo al día",
      cls: atrasados > 0 ? "warn" : "ok",
      caja: muyAtrasados > 0 ? "pin-kpi-err" : atrasados > 0 ? "pin-kpi-warn" : "",
    },
    { label: "IVA contenido en el stock", value: pinMoneda(ivaStock), sub: `Alícuota ${config.alicuota_iva}%`, cls: "" },
  ];

  $("pinKpis").innerHTML = cards
    .map(
      (c) => `
    <div class="pin-kpi ${c.caja || ""}">
      <div class="pin-kpi-label">${c.label}</div>
      <div class="pin-kpi-value">${c.value}</div>
      <div class="pin-kpi-sub ${c.cls}">${c.sub}</div>
    </div>`
    )
    .join("");
}

/* ------------------------------------------------------------------
   Gráfico de proyección -- SVG a mano, mismo método que metricas_ia.js:
   viewBox fijo, márgenes en un objeto, escalas como closures, un
   <polyline> por serie armado como string e insertado con innerHTML. Los
   colores salen de clases CSS con var(--...), así que repinta solo al
   cambiar de tema sin hacer falta ningún MutationObserver (eso sólo lo
   necesita el <canvas> de graficos.js).
   ------------------------------------------------------------------ */
function renderGrafico() {
  const wrap = $("pinGraficoWrap");
  const sub = $("pinGraficoSubtitulo");
  const p = productos.find((x) => x.id === productoSeleccionadoId);

  if (!p) {
    wrap.innerHTML = `<div class="pin-vacio">Elegí un producto de la tabla para ver su proyección.</div>`;
    sub.textContent = "Elegí un producto en la tabla de abajo";
    return;
  }

  sub.textContent = `${p.nombre}${p.marca ? " · " + p.marca : ""}`;

  const infl = config.inflacion_mensual;
  const precio = p.precio_venta;
  const puntos = [];
  for (let m = 0; m <= 12; m++) puntos.push(proyeccion(precio, infl, m));

  const W = 680;
  const H = 220;
  const M = { top: 16, right: 16, bottom: 26, left: 58 };
  const anchoUtil = W - M.left - M.right;
  const altoUtil = H - M.top - M.bottom;

  const minV = Math.min(precio, ...puntos);
  const maxV = Math.max(precio, ...puntos);
  const rango = maxV - minV || 1;

  const x = (m) => M.left + (anchoUtil * m) / 12;
  const y = (v) => M.top + altoUtil * (1 - (v - minV) / rango);

  const lineaProy = puntos.map((v, m) => `${x(m).toFixed(1)},${y(v).toFixed(1)}`).join(" ");
  const lineaPlana = `${x(0).toFixed(1)},${y(precio).toFixed(1)} ${x(12).toFixed(1)},${y(precio).toFixed(1)}`;

  const etiquetasY = [minV, (minV + maxV) / 2, maxV]
    .map((v) => `<text x="${M.left - 8}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end">${pinMoneda(v)}</text>`)
    .join("");
  const etiquetasX = [0, 3, 6, 9, 12]
    .map((m) => `<text x="${x(m).toFixed(1)}" y="${H - 6}" text-anchor="middle">${m}m</text>`)
    .join("");

  const puntoHorizonte = `<circle class="pin-punto" cx="${x(horizonteMeses).toFixed(1)}" cy="${y(
    puntos[horizonteMeses]
  ).toFixed(1)}" r="4"></circle>`;

  const svg = `
    <svg class="pin-svg" viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg">
      <line class="pin-eje" x1="${M.left}" y1="${M.top}" x2="${M.left}" y2="${H - M.bottom}"></line>
      <line class="pin-eje" x1="${M.left}" y1="${H - M.bottom}" x2="${W - M.right}" y2="${H - M.bottom}"></line>
      ${etiquetasY}
      ${etiquetasX}
      <polyline class="pin-linea-plana" points="${lineaPlana}"></polyline>
      <polyline class="pin-linea-proy" points="${lineaProy}"></polyline>
      ${puntoHorizonte}
    </svg>`;

  const proyeccionHorizonte = redondearA10(puntos[horizonteMeses]);
  // El equivalente de hoy de mantener el precio nominal fijo: si no se toca
  // el precio, en N meses se sigue cobrando la misma cifra, pero esa cifra
  // vale menos en términos reales -- ésta es la cuenta que lo muestra.
  const equivalenteHoy = precio / Math.pow(1 + infl / 100, horizonteMeses);
  const horiz = `${horizonteMeses} mes${horizonteMeses === 1 ? "" : "es"}`;

  wrap.innerHTML = `
    ${svg}
    <div class="pin-grafico-texto">
      Para no perder contra la inflación, en ${horiz} <b>${p.nombre}</b> debería costar
      <b>${pinMoneda(proyeccionHorizonte)}</b>. Si lo dejás en <b>${pinMoneda(precio)}</b>, vas a estar cobrando el
      equivalente a <b>${pinMoneda(equivalenteHoy)}</b> de hoy.
    </div>`;
}

/* ------------------------------------------------------------------
   "¿Qué hay dentro del precio?" -- barra apilada + tabla hoy/futuro +
   mini-preview del ticket.
   ------------------------------------------------------------------ */
function renderFiscal() {
  const wrap = $("pinFiscalWrap");
  const p = productos.find((x) => x.id === productoSeleccionadoId);

  if (!p) {
    wrap.innerHTML = `<div class="pin-vacio">Elegí un producto de la tabla para ver su desglose.</div>`;
    return;
  }

  const iva = config.alicuota_iva;
  const iibb = config.alicuota_iibb;

  function desglosar(precio) {
    const neto = precio / (1 + iva / 100);
    const ivaContenido = precio - neto;
    const iibbMonto = neto * (iibb / 100);
    const leQueda = neto - iibbMonto;
    return { precio, neto, ivaContenido, iibbMonto, leQueda };
  }

  const hoy = desglosar(p.precio_venta);
  const futuro = desglosar(p.precio_en_horizonte);
  const horiz = `${horizonteMeses} mes${horizonteMeses === 1 ? "" : "es"}`;

  // La barra apila los TRES destinos del precio que suman el 100%: lo que
  // se queda el comercio, IIBB e IVA. "Neto" no tiene segmento propio porque
  // ya es "le queda + IIBB" -- mostrarlo aparte duplicaría esa porción.
  const pctLeQueda = (hoy.leQueda / hoy.precio) * 100;
  const pctIibb = (hoy.iibbMonto / hoy.precio) * 100;
  const pctIva = (hoy.ivaContenido / hoy.precio) * 100;

  wrap.innerHTML = `
    <div>
      <div class="pin-barra-apilada">
        <div class="pin-barra-seg" style="width:${pctLeQueda}%; background:var(--menta-dark);">${
    pctLeQueda >= 12 ? pctLeQueda.toFixed(0) + "%" : ""
  }</div>
        <div class="pin-barra-seg" style="width:${pctIibb}%; background:var(--dorado-dark);">${
    pctIibb >= 12 ? pctIibb.toFixed(0) + "%" : ""
  }</div>
        <div class="pin-barra-seg" style="width:${pctIva}%; background:var(--coral-dark);">${
    pctIva >= 12 ? pctIva.toFixed(0) + "%" : ""
  }</div>
      </div>
      <div class="pin-barra-leyenda">
        <span><i style="background:var(--menta-dark)"></i> Le queda al comercio (${pctLeQueda.toFixed(1)}%)</span>
        <span><i style="background:var(--dorado-dark)"></i> Ingresos Brutos (${pctIibb.toFixed(1)}%)</span>
        <span><i style="background:var(--coral-dark)"></i> IVA (${pctIva.toFixed(1)}%)</span>
      </div>
    </div>
    <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
      <table class="pin-fiscal-tabla" style="flex:2 1 320px;">
        <thead><tr><th></th><th>Hoy</th><th>En ${horiz}</th></tr></thead>
        <tbody>
          <tr><td>Precio de venta</td><td>${pinMoneda(hoy.precio)}</td><td>${pinMoneda(futuro.precio)}</td></tr>
          <tr><td>Neto</td><td>${pinMoneda(hoy.neto)}</td><td>${pinMoneda(futuro.neto)}</td></tr>
          <tr><td>IVA contenido</td><td>${pinMoneda(hoy.ivaContenido)}</td><td>${pinMoneda(futuro.ivaContenido)}</td></tr>
          <tr><td>Ingresos Brutos</td><td>${pinMoneda(hoy.iibbMonto)}</td><td>${pinMoneda(futuro.iibbMonto)}</td></tr>
          <tr><td>Le queda al comercio</td><td>${pinMoneda(hoy.leQueda)}</td><td>${pinMoneda(futuro.leQueda)}</td></tr>
        </tbody>
      </table>
      <div class="pin-ticket-preview" style="flex:1 1 220px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); font-weight:700;">Vista previa del ticket</div>
        <div style="margin-top:8px; color:var(--gris-tenue); font-size:12.5px;">${p.nombre}</div>
        <div class="pin-ticket-total"><span>Total</span><span>${pinMoneda(hoy.precio)}</span></div>
        <div class="pin-ticket-iva"><span>IVA contenido</span><span>${pinMoneda(hoy.ivaContenido)}</span></div>
      </div>
    </div>`;
}

/* ------------------------------------------------------------------
   Tabla de productos
   ------------------------------------------------------------------ */
function productosFiltrados() {
  const f = filtro.trim().toLowerCase();
  if (!f) return productos;
  return productos.filter(
    (p) =>
      (p.nombre || "").toLowerCase().includes(f) ||
      (p.marca || "").toLowerCase().includes(f) ||
      (p.sku || "").toLowerCase().includes(f)
  );
}

function estadoBadge(p) {
  if (!p.ultimo_aumento) return `<span class="pin-badge gris">Sin datos</span>`;
  if (p.estado === "rojo") return `<span class="pin-badge rojo">Atrasado ${p.atraso_pct.toFixed(0)}%</span>`;
  if (p.estado === "ambar") return `<span class="pin-badge ambar">Atrasado ${p.atraso_pct.toFixed(0)}%</span>`;
  return `<span class="pin-badge ok">Al día</span>`;
}

function renderTabla() {
  $("pinColEnN").textContent = `En ${horizonteMeses} mes${horizonteMeses === 1 ? "" : "es"}`;
  $("pinTablaSubtitulo").textContent = `${productos.length} producto${productos.length === 1 ? "" : "s"}`;

  const filtrados = productosFiltrados();
  const tbody = $("pinTbody");

  if (filtrados.length === 0) {
    const vacio = filtro ? `Sin resultados para "${filtro}"` : "No hay productos activos";
    tbody.innerHTML = `<tr><td colspan="9" class="pin-empty">${vacio}</td></tr>`;
    actualizarBotonAplicar();
    return;
  }

  tbody.innerHTML = filtrados
    .map(
      (p) => `
    <tr data-pin-row data-id="${p.id}" class="pin-e-${p.estado} ${p.id === productoSeleccionadoId ? "pin-fila-activa" : ""}">
      <td><input type="checkbox" class="pin-check" data-pin-check data-id="${p.id}" ${
        seleccionados.has(p.id) ? "checked" : ""
      } ${!p.ultimo_aumento ? "disabled" : ""}></td>
      <td>
        <div class="pin-prod-name">${p.nombre}</div>
        <div class="pin-prod-sub">${p.sku}${p.marca ? " · " + p.marca : ""}</div>
      </td>
      <td class="pin-num">${p.stock_actual}</td>
      <td>${pinFecha(p.ultimo_aumento)}</td>
      <td class="pin-num">${pinMoneda(p.precio_venta)}</td>
      <td class="pin-num">${p.atraso_pct === null ? "—" : p.atraso_pct.toFixed(1) + "%"}</td>
      <td class="pin-num pin-sug">${p.sugerido === null ? "—" : pinMoneda(p.sugerido)}</td>
      <td class="pin-num">${pinMoneda(p.precio_en_horizonte)}</td>
      <td>${estadoBadge(p)}</td>
    </tr>`
    )
    .join("");

  actualizarBotonAplicar();
}

function actualizarBotonAplicar() {
  const boton = $("pinAplicar");
  boton.disabled = seleccionados.size === 0;
  boton.textContent = seleccionados.size > 0 ? `Aplicar precio sugerido (${seleccionados.size})` : "Aplicar precio sugerido";
}

$("pinTbody").addEventListener("click", (e) => {
  if (e.target.closest("[data-pin-check]")) return;
  const fila = e.target.closest("[data-pin-row]");
  if (!fila) return;
  productoSeleccionadoId = parseInt(fila.dataset.id, 10);
  renderTabla();
  renderGrafico();
  renderFiscal();
});

$("pinTbody").addEventListener("change", (e) => {
  const check = e.target.closest("[data-pin-check]");
  if (!check) return;
  const id = parseInt(check.dataset.id, 10);
  if (check.checked) seleccionados.add(id);
  else seleccionados.delete(id);
  actualizarBotonAplicar();
});

$("pinCheckTodos").addEventListener("change", (e) => {
  const marcar = e.target.checked;
  productosFiltrados().forEach((p) => {
    if (!p.ultimo_aumento) return; // sin fecha de aumento no hay sugerido que aplicar
    if (marcar) seleccionados.add(p.id);
    else seleccionados.delete(p.id);
  });
  renderTabla();
});

$("pinBuscar").addEventListener("input", (e) => {
  filtro = e.target.value;
  renderTabla();
});

$("pinAplicar").addEventListener("click", async () => {
  const items = [...seleccionados]
    .map((id) => productos.find((p) => p.id === id))
    .filter((p) => p && p.sugerido !== null)
    .map((p) => ({ producto_id: p.id, precio_nuevo: p.sugerido }));

  if (items.length === 0) return;

  const detalle =
    items
      .slice(0, 8)
      .map((it) => {
        const p = productos.find((x) => x.id === it.producto_id);
        return `${p.nombre}: ${pinMoneda(p.precio_venta)} → ${pinMoneda(it.precio_nuevo)}`;
      })
      .join("\n") + (items.length > 8 ? `\n... y ${items.length - 8} más` : "");

  const confirmado = window.confirm(
    `Vas a actualizar ${items.length} precio${items.length === 1 ? "" : "s"}. ` +
      `Los cajeros van a cobrar el precio nuevo desde la próxima venta.\n\n${detalle}`
  );
  if (!confirmado) return;

  const boton = $("pinAplicar");
  boton.disabled = true;
  try {
    const resp = await fetchApi(URL_PIN_ACTUALIZAR, {
      method: "POST",
      headers: { ...STOCKIATE_HEADERS, "Content-Type": "application/json" },
      body: JSON.stringify({ items, motivo: "ajuste_inflacion" }),
    });
    const data = await resp.json();

    if (!resp.ok || !data.ok) {
      alert(data.mensaje || "No se pudieron aplicar los precios.");
      return;
    }

    await cargar();
  } catch (err) {
    alert(mensajeDeError(err));
  } finally {
    actualizarBotonAplicar();
  }
});

/* ------------------------------------------------------------------
   Controles: stepper (inflación/IIBB, reusa el patrón de .prf-stepper de
   preferencias.js) y pills (horizonte/IVA).
   ------------------------------------------------------------------ */
async function guardarPreferenciaFiscal(clave, valor) {
  try {
    const resp = await fetchApi(URL_PIN_GUARDAR_PREF, {
      method: "POST",
      headers: { ...STOCKIATE_HEADERS, "Content-Type": "application/json" },
      body: JSON.stringify({ [clave]: valor }),
    });
    const data = await resp.json();
    if (!resp.ok || !data.ok) {
      alert(data.mensaje || "No se pudo guardar el cambio.");
      return false;
    }
    return true;
  } catch (err) {
    alert(mensajeDeError(err));
    return false;
  }
}

function recalcularYRepintar() {
  productos = productos.map(calcularFila);
  renderKpis();
  renderTabla();
  renderGrafico();
  renderFiscal();
}

function pinWireStepper(input, clave) {
  const contenedor = input.closest(".pin-stepper");
  const paso = parseFloat(input.step) || 1;
  const decimales = (String(paso).split(".")[1] || "").length;
  const min = input.min !== "" ? parseFloat(input.min) : -Infinity;
  const max = input.max !== "" ? parseFloat(input.max) : Infinity;

  function mover(delta) {
    const actual = parseFloat(input.value);
    const base = Number.isFinite(actual) ? actual : 0;
    const nuevo = Math.min(max, Math.max(min, base + delta));
    input.value = decimales ? nuevo.toFixed(decimales) : String(nuevo);
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  contenedor.querySelector("[data-pin-stepper-menos]").addEventListener("click", () => mover(-paso));
  contenedor.querySelector("[data-pin-stepper-mas]").addEventListener("click", () => mover(paso));

  input.addEventListener("change", async () => {
    const valor = parseFloat(input.value);
    if (!Number.isFinite(valor) || valor === config[clave]) {
      input.value = config[clave];
      return;
    }
    input.disabled = true;
    const ok = await guardarPreferenciaFiscal(clave, valor);
    input.disabled = false;
    if (ok) {
      config[clave] = valor;
      recalcularYRepintar();
    } else {
      input.value = config[clave];
    }
  });
}

function pintarPillsActivas() {
  $("pinHorizonte")
    .querySelectorAll(".pin-pill")
    .forEach((btn) => {
      btn.classList.toggle("pin-activo", parseInt(btn.dataset.horizonte, 10) === horizonteMeses);
    });
  $("pinIva")
    .querySelectorAll(".pin-pill")
    .forEach((btn) => {
      btn.classList.toggle("pin-activo", parseFloat(btn.dataset.iva) === config.alicuota_iva);
    });
}

function wireHorizontePills() {
  $("pinHorizonte")
    .querySelectorAll(".pin-pill")
    .forEach((btn) => {
      btn.addEventListener("click", () => {
        horizonteMeses = parseInt(btn.dataset.horizonte, 10);
        pintarPillsActivas();
        recalcularYRepintar();
      });
    });
}

function wireIvaPills() {
  $("pinIva")
    .querySelectorAll(".pin-pill")
    .forEach((btn) => {
      btn.addEventListener("click", async () => {
        const valor = parseFloat(btn.dataset.iva);
        if (valor === config.alicuota_iva) return;
        $("pinIva").querySelectorAll(".pin-pill").forEach((b) => (b.disabled = true));
        const ok = await guardarPreferenciaFiscal("alicuota_iva", valor);
        $("pinIva").querySelectorAll(".pin-pill").forEach((b) => (b.disabled = false));
        if (ok) {
          config.alicuota_iva = valor;
          pintarPillsActivas();
          recalcularYRepintar();
        }
      });
    });
}

$("pinBtnIpc").addEventListener("click", async () => {
  const nota = $("pinIpcNota");
  const boton = $("pinBtnIpc");

  boton.disabled = true;
  nota.classList.remove("pin-error");
  nota.textContent = "Consultando datos.gob.ar...";

  try {
    const resp = await fetchApi(URL_PIN_IPC, {
      method: "POST",
      headers: { ...STOCKIATE_HEADERS, "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const data = await resp.json();

    if (!resp.ok || !data.ok) {
      // Offline-first: si el INDEC no responde, la pantalla se queda con la
      // inflación cargada a mano y sólo avisa -- nunca rompe nada.
      nota.classList.add("pin-error");
      nota.textContent = data.mensaje || "No se pudo traer el IPC. Se mantiene el valor cargado a mano.";
      return;
    }

    const guardado = await guardarPreferenciaFiscal("inflacion_mensual", data.variacion);
    if (guardado) {
      $("pinInflacion").value = data.variacion;
      config.inflacion_mensual = data.variacion;
      recalcularYRepintar();
      nota.textContent = `IPC${data.periodo ? " " + data.periodo : ""}: ${data.variacion}% mensual.`;
    } else {
      nota.classList.add("pin-error");
      nota.textContent = "Se trajo el dato pero no se pudo guardar.";
    }
  } catch (err) {
    nota.classList.add("pin-error");
    nota.textContent = mensajeDeError(err);
  } finally {
    boton.disabled = false;
  }
});

pinWireStepper($("pinInflacion"), "inflacion_mensual");
pinWireStepper($("pinIibb"), "alicuota_iibb");
wireHorizontePills();
wireIvaPills();

$("btnPanel").addEventListener("click", () => {
  window.location.href = "administrador.html";
});
$("btn-volver").addEventListener("click", () => {
  window.location.href = "administrador.html";
});

/* ------------------------------------------------------------------
   Carga
   ------------------------------------------------------------------ */
function pintarControlesDesdeConfig() {
  $("pinInflacion").value = config.inflacion_mensual;
  $("pinIibb").value = config.alicuota_iibb;
  pintarPillsActivas();
}

async function cargar() {
  $("pinSubtitulo").textContent = "Cargando...";
  try {
    const resp = await fetchApi(URL_PIN_PRECIOS, {
      method: "POST",
      headers: { ...STOCKIATE_HEADERS, "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const data = await resp.json();

    if (!resp.ok || !data.ok) {
      $("pinSubtitulo").textContent = data.mensaje || "No se pudieron cargar los precios.";
      $("pinTbody").innerHTML = `<tr><td colspan="9" class="pin-empty">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
      return;
    }

    config = data.config;
    productos = data.productos.map(calcularFila);

    if (productoSeleccionadoId === null || !productos.some((p) => p.id === productoSeleccionadoId)) {
      productoSeleccionadoId = productos.length > 0 ? productos[0].id : null;
    }

    preseleccionarAtrasados();
    pintarControlesDesdeConfig();

    $("pinSubtitulo").textContent = `Inflación mensual: ${config.inflacion_mensual}% · IVA ${config.alicuota_iva}% · IIBB ${config.alicuota_iibb}%`;

    renderKpis();
    renderTabla();
    renderGrafico();
    renderFiscal();
  } catch (err) {
    $("pinSubtitulo").textContent = mensajeDeError(err);
    $("pinTbody").innerHTML = `<tr><td colspan="9" class="pin-empty">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
  }
}

cargar();
