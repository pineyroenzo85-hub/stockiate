/**
 * stockIAte - graficos.js
 * ================================
 * El bloque "Gráficos" del panel de administrador: cuatro visualizaciones de lo
 * que el negocio ya tiene cargado, un selector para mirar las ventas de UN
 * producto, y un botón que exporta todo a PDF.
 *
 *   1. Estado del stock (torta)      -- cuántos productos están críticos, bajos,
 *                                       disponibles o sin stock.
 *   2. Capital por proveedor (torta) -- en cuál de tus proveedores está parada
 *                                       la plata.
 *   3. Ventas por día (línea)        -- facturación de los últimos 30 días, de
 *                                       todo el negocio o de un solo producto.
 *   4. Top productos (barras)        -- los 10 que más facturaron en el mes.
 *
 * DE DÓNDE SALEN LOS DATOS (y por qué esto casi no pide nada nuevo)
 * ----------------------------------------------------------------
 * Los dos primeros NO hacen ningún request: se alimentan de los datos que el
 * panel ya estaba pidiendo para sus tablas, pasados por los callbacks `onDatos`
 * de `inventario_tabla.js` y `costos_tabla.js`. Pedirlos de nuevo habría
 * duplicado dos consultas pesadas para pintar exactamente los mismos números
 * -- y peor, habría abierto la puerta a que el gráfico y la tabla de al lado
 * mostraran cosas distintas si una se recarga y la otra no.
 * Sólo los de ventas pegan a la red (`consultar_ventas.php`).
 *
 * El estado del stock se clasifica con `estadoDeProducto()`, la MISMA función
 * que pinta los badges de la tabla de inventario. Es a propósito: si la torta
 * tuviera su propio criterio, diría "5 críticos" al lado de una tabla que
 * muestra 3, y no habría forma de saber cuál miente.
 *
 * LOS COLORES SON UN "TEMA", NO CONSTANTES
 * ---------------------------------------
 * Cada gráfico se arma con `configXxx(datos, tema)`, donde `tema` trae los
 * colores. En pantalla se le pasa `temaPantalla()` (que lee las variables CSS
 * del tema activo); para el PDF se le pasa `GRF_TEMA_PDF`, que es fijo y
 * oscuro-sobre-blanco. Sin esa separación, exportar estando en modo oscuro
 * daba un PDF con los ejes en gris claro sobre papel blanco: ilegible, y sólo
 * te enterabas al imprimirlo.
 *
 * Chart.js dibuja en un <canvas>, así que no hereda nada del CSS: cuando cambia
 * el tema hay que volver a dibujar. De eso se encarga el MutationObserver sobre
 * el atributo `data-tema` del <html>. Al repintar se llama `destroy()` sobre la
 * instancia anterior: Chart.js deja listeners por gráfico y reconstruir sobre
 * el mismo canvas sin destruir los apila.
 *
 * Uso:
 *   const graficos = initGraficos(document.getElementById("contenedor"));
 *   graficos.actualizarInventario(datos);    // desde onDatos de la tabla
 *   graficos.actualizarRentabilidad(datos);  // desde onDatos de costos
 *
 * Depende de `vendor/chart.umd.min.js` (Chart.js 4) y `vendor/jspdf.umd.min.js`
 * (jsPDF 2), los dos servidos desde el repo y no desde un CDN: así el panel y
 * la exportación andan sin internet. También de `plegable.js`.
 *
 * Clases con prefijo "grf-", como "invt-"/"cst-"/"prd-"/"prf-"/"mng-".
 */

const URL_GRAFICOS_VENTAS = "consultar_ventas.php";

const GRF_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

const GRF_CLAVE_PLEGADO = "stockiate_graficos_plegado";

/** Colores del PDF: fijos y pensados para papel blanco, mire lo que mire la pantalla. */
const GRF_TEMA_PDF = {
  texto: "#3F3A4D",
  grilla: "#DED7E8",
  borde: "#FFFFFF",
  acento: "#8E5BB5",
  barra: "#6FBF95",
  paleta: ["#B98FD6", "#7FC9A4", "#E8C468", "#E695A0", "#8E5BB5", "#5FA8A0", "#C58A3D", "#9B7BC4"],
};

const GRF_ESTILOS_ID = "grf-estilos";
const GRF_CSS = `
.grf-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.grf-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.grf-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.grf-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.grf-head-acciones{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto; }
.grf-select{ background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:10px; padding:8px 12px; color:var(--blanco-puro); font-size:12.5px; font-family:inherit; outline:none; max-width:230px; cursor:pointer; }
.grf-select:focus{ box-shadow:var(--neu-inset), 0 0 0 2px var(--lila); }
.grf-btn{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap; }
.grf-btn:hover:not(:disabled){ color:var(--blanco-puro); }
.grf-btn:disabled{ opacity:.5; cursor:default; }
.grf-panel.grf-plegado .grf-grid,
.grf-panel.grf-plegado .grf-head-acciones{ display:none; }
.grf-panel.grf-plegado .grf-panel-head{ border-bottom:none; }

.grf-grid{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px; padding:18px 20px; }
.grf-caja{ min-width:0; display:flex; flex-direction:column; gap:8px; }
.grf-caja h3{ font-size:12px; font-weight:800; margin:0; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
/* Altura fija + un canvas con responsive:true y maintainAspectRatio:false.
   Sin la altura del contenedor, Chart.js crece en cada resize del canvas y el
   gráfico se estira hasta el infinito (el bug clásico de canvas + flex). */
.grf-lienzo{ position:relative; height:220px; }
.grf-vacio{ display:flex; align-items:center; justify-content:center; height:220px; color:var(--gris-tenue); font-size:12.5px; text-align:center; padding:0 10px; }

@media (max-width: 860px){
  .grf-grid{ grid-template-columns:1fr; }
}
`;

function asegurarEstilosGraficos() {
  if (document.getElementById(GRF_ESTILOS_ID)) return;
  const estilo = document.createElement("style");
  estilo.id = GRF_ESTILOS_ID;
  estilo.textContent = GRF_CSS;
  document.head.appendChild(estilo);
}

/** Lee una variable del tema, con fallback por si la página no la define. */
function grfVar(nombre, fallback) {
  const valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
  return valor || fallback;
}

/** Los colores del tema que está activo en pantalla ahora mismo. */
function grfTemaPantalla() {
  return {
    texto: grfVar("--gris-tenue", "#8B7FA8"),
    grilla: grfVar("--divisor", "rgba(0,0,0,0.08)"),
    borde: grfVar("--superficie", "#F3EFF7"),
    acento: grfVar("--lila-dark", "#B87FD9"),
    barra: grfVar("--menta", "#B8E8CE"),
    paleta: [
      grfVar("--lila", "#D9BFEA"),
      grfVar("--menta", "#B8E8CE"),
      grfVar("--dorado", "#F5E3AE"),
      grfVar("--coral", "#F2BFC4"),
      grfVar("--lila-dark", "#B87FD9"),
      "#9FD3C7",
      "#E8C468",
      "#C9A0DC",
    ],
  };
}

function grfMoneda(n) {
  return "$" + Number(n || 0).toLocaleString("es-AR", { maximumFractionDigits: 0 });
}

/** "2026-09-05" -> "05/09" */
function grfFechaCorta(valor) {
  const partes = String(valor || "").split("-");
  return partes.length === 3 ? partes[2] + "/" + partes[1] : String(valor);
}

function grfAcortar(texto, largo) {
  const t = String(texto || "");
  return t.length > largo ? t.slice(0, largo - 1) + "…" : t;
}

// ----------------------------------------------------------------------
// Configuraciones de Chart.js. Toman el tema por parámetro para poder
// reusarlas tal cual en pantalla y en el PDF.
// ----------------------------------------------------------------------

function grfConfigTorta(etiquetas, valores, formatear, tema) {
  return {
    type: "doughnut",
    data: {
      labels: etiquetas,
      datasets: [{
        data: valores,
        backgroundColor: tema.paleta,
        borderColor: tema.borde,
        borderWidth: 2,
      }],
    },
    options: {
      plugins: {
        legend: { position: "right", labels: { color: tema.texto, boxWidth: 12, font: { size: 11.5 } } },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${formatear(ctx.parsed)}` } },
      },
    },
  };
}

function grfConfigLinea(resultados, tema) {
  return {
    type: "line",
    data: {
      labels: resultados.map(r => grfFechaCorta(r.etiqueta)),
      datasets: [{
        label: "Facturado",
        data: resultados.map(r => Number(r.total_vendido)),
        borderColor: tema.acento,
        backgroundColor: tema.acento,
        fill: false,
        tension: 0.3,
        pointRadius: 3,
      }],
    },
    options: {
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => " " + grfMoneda(ctx.parsed.y) } },
      },
      scales: {
        x: { ticks: { color: tema.texto, font: { size: 10.5 } }, grid: { color: tema.grilla } },
        y: {
          beginAtZero: true,
          ticks: { color: tema.texto, font: { size: 10.5 }, callback: (v) => grfMoneda(v) },
          grid: { color: tema.grilla },
        },
      },
    },
  };
}

function grfConfigBarras(top, tema) {
  return {
    type: "bar",
    data: {
      labels: top.map(r => r.etiqueta),
      datasets: [{
        label: "Facturado",
        data: top.map(r => Number(r.total_vendido)),
        backgroundColor: tema.barra,
        borderRadius: 6,
      }],
    },
    options: {
      indexAxis: "y",
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => " " + grfMoneda(ctx.parsed.x) } },
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: { color: tema.texto, font: { size: 10.5 }, callback: (v) => grfMoneda(v) },
          grid: { color: tema.grilla },
        },
        y: {
          // Nombres de perfumería largos ("Libra Máscara Hidratante Acido
          // 100g") empujarían el área del gráfico hasta dejarla en nada.
          ticks: {
            color: tema.texto,
            font: { size: 10.5 },
            callback: function (valor) { return grfAcortar(this.getLabelForValue(valor), 22); },
          },
          grid: { display: false },
        },
      },
    },
  };
}

/**
 * Dibuja una configuración en un canvas suelto (fuera del DOM) y devuelve la
 * imagen que se mete en el PDF.
 *
 * `animation: false` no es opcional: con animación, el canvas se captura a
 * mitad del dibujado y las tortas salen como una porción incompleta.
 *
 * POR QUÉ JPEG Y NO PNG
 * --------------------
 * Un gráfico es color plano, así que en PNG pesa menos (48 KB contra 71 KB por
 * las cuatro imágenes). Pero jsPDF comprime el PNG en JavaScript, y eso es lo
 * caro: medido sobre este mismo reporte, PNG tarda 2060 ms y JPEG 212 ms -- diez
 * veces más, con la pantalla congelada mientras tanto. El JPEG ya viene
 * comprimido y se embebe tal cual. A `devicePixelRatio: 2` sobre 180 mm de
 * ancho la imagen queda en ~310 DPI, así que los artefactos de un q=0.92 no se
 * ven ni impresos.
 */
function grfImagenPara(config, ancho, alto) {
  const canvas = document.createElement("canvas");
  canvas.width = ancho;
  canvas.height = alto;

  const chart = new Chart(canvas, {
    type: config.type,
    data: config.data,
    options: Object.assign({}, config.options, {
      responsive: false,
      maintainAspectRatio: false,
      animation: false,
      devicePixelRatio: 2,
    }),
  });

  // EL FONDO VA DESPUÉS, Y POR DEBAJO. Dos motivos, y los dos hacen falta:
  // al construirse, Chart.js redimensiona el canvas (a ancho*devicePixelRatio),
  // y redimensionar un canvas lo BORRA -- un fondo pintado antes se perdía. Y
  // como el canvas queda transparente, el JPEG (que no tiene alpha) lo componía
  // sobre NEGRO: los cuatro gráficos salían con fondo negro y los ejes en gris
  // oscuro arriba, ilegibles. `destination-over` pinta el blanco por detrás de
  // lo ya dibujado, y se usan las medidas post-resize del canvas, no las que se
  // pidieron.
  const ctx = canvas.getContext("2d");
  ctx.globalCompositeOperation = "destination-over";
  ctx.fillStyle = "#FFFFFF";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.globalCompositeOperation = "source-over";

  const imagen = canvas.toDataURL("image/jpeg", 0.92);
  chart.destroy();
  return imagen;
}

function initGraficos(contenedor) {
  asegurarEstilosGraficos();

  // Arranca DESPLEGADO (a diferencia de las tablas): es el bloque que se mira
  // de un vistazo, y es justamente lo que queda a la vista cuando el inventario
  // y los costos están cerrados.
  const plegado = plegableLeer(GRF_CLAVE_PLEGADO, false);

  contenedor.innerHTML = `
    <div class="grf-panel${plegado ? " grf-plegado" : ""}">
      <div class="grf-panel-head">
        <div>
          <h2>Gráficos</h2>
        </div>
        <div class="grf-head-acciones">
          <select class="grf-select" data-grf-producto aria-label="Ver ventas de un producto">
            <option value="">Ventas de todo el negocio</option>
          </select>
          <button type="button" class="grf-btn" data-grf-pdf>📄 Exportar PDF</button>
        </div>
        <button type="button" class="grf-btn" data-grf-toggle-pleg aria-expanded="${plegado ? "false" : "true"}" aria-controls="grfGrid"></button>
      </div>
      <div class="grf-grid" id="grfGrid">
        <div class="grf-caja">
          <h3>Estado del stock</h3>
          <div class="grf-lienzo" data-grf-caja="stock"><canvas></canvas></div>
        </div>
        <div class="grf-caja">
          <h3>Capital por proveedor</h3>
          <div class="grf-lienzo" data-grf-caja="proveedor"><canvas></canvas></div>
        </div>
        <div class="grf-caja">
          <h3 data-grf-titulo-ventas>Ventas por día</h3>
          <div class="grf-lienzo" data-grf-caja="ventas"><canvas></canvas></div>
        </div>
        <div class="grf-caja">
          <h3>Top productos del mes</h3>
          <div class="grf-lienzo" data-grf-caja="top"><canvas></canvas></div>
        </div>
      </div>
    </div>
  `;

  const panel = contenedor.querySelector(".grf-panel");
  const selectorProducto = contenedor.querySelector("[data-grf-producto]");
  const botonPdf = contenedor.querySelector("[data-grf-pdf]");
  const tituloVentas = contenedor.querySelector("[data-grf-titulo-ventas]");

  plegableConectar({
    panel: panel,
    boton: contenedor.querySelector("[data-grf-toggle-pleg]"),
    clave: GRF_CLAVE_PLEGADO,
    clasePlegada: "grf-plegado",
    etiqueta: "gráficos",
  });

  // Instancias de Chart.js vivas, por nombre, para poder destruirlas antes de
  // volver a dibujar sobre el mismo canvas.
  const charts = {};
  // Últimos datos crudos de cada gráfico: permiten repintar con otros colores
  // (cambio de tema) y armar el PDF sin volver a pedir nada.
  const datos = {};
  // Producto elegido en el selector, o null = todo el negocio.
  let productoSel = null;

  function mostrarVacio(nombre, mensaje) {
    if (charts[nombre]) {
      charts[nombre].destroy();
      delete charts[nombre];
    }
    contenedor.querySelector(`[data-grf-caja="${nombre}"]`).innerHTML =
      `<div class="grf-vacio">${mensaje}</div>`;
  }

  /**
   * Devuelve un canvas limpio. Si la caja quedó con el cartel de "sin datos"
   * hay que reponer el <canvas>, que se perdió con ese innerHTML.
   */
  function lienzo(nombre) {
    const caja = contenedor.querySelector(`[data-grf-caja="${nombre}"]`);
    let canvas = caja.querySelector("canvas");
    if (!canvas) {
      caja.innerHTML = "<canvas></canvas>";
      canvas = caja.querySelector("canvas");
    }
    if (charts[nombre]) {
      charts[nombre].destroy();
      delete charts[nombre];
    }
    return canvas;
  }

  /** Dibuja en pantalla: misma config que el PDF, pero con el tema activo. */
  function pintar(nombre, config) {
    charts[nombre] = new Chart(lienzo(nombre), {
      type: config.type,
      data: config.data,
      options: Object.assign({}, config.options, {
        responsive: true,
        maintainAspectRatio: false,
      }),
    });
  }

  // ------------------------------------------------------------------
  // Cada gráfico
  // ------------------------------------------------------------------

  function pintarStock() {
    const respuesta = datos.inventario;
    if (!respuesta) return;

    // Se clasifica con estadoDeProducto() de inventario_tabla.js -- la misma
    // función que pinta los badges de la tabla, para que no puedan diferir.
    const orden = ["critical", "unavailable", "low", "ok"];
    const etiquetas = { critical: "Crítico", unavailable: "Sin stock", low: "Stock bajo", ok: "Disponible" };

    const conteo = {};
    respuesta.productos.forEach(p => {
      const key = estadoDeProducto(p).key;
      conteo[key] = (conteo[key] || 0) + 1;
    });

    const presentes = orden.filter(k => conteo[k] > 0);
    if (!presentes.length) {
      mostrarVacio("stock", "Sin productos cargados.");
      return;
    }

    pintar("stock", grfConfigTorta(
      presentes.map(k => etiquetas[k]),
      presentes.map(k => conteo[k]),
      (v) => v + (v === 1 ? " producto" : " productos"),
      grfTemaPantalla()
    ));
  }

  function proveedoresConCapital() {
    if (!datos.rentabilidad) return [];
    // Un proveedor sin plata parada no aporta nada a una torta de capital.
    return datos.rentabilidad.por_proveedor
      .filter(p => Number(p.capital_inmovilizado) > 0)
      .sort((a, b) => Number(b.capital_inmovilizado) - Number(a.capital_inmovilizado));
  }

  function pintarProveedores() {
    const lista = proveedoresConCapital();
    if (!lista.length) {
      mostrarVacio("proveedor", "Sin costos cargados.");
      return;
    }
    pintar("proveedor", grfConfigTorta(
      lista.map(p => p.proveedor),
      lista.map(p => Number(p.capital_inmovilizado)),
      grfMoneda,
      grfTemaPantalla()
    ));
  }

  function pintarVentas() {
    const serie = datos.ventas || [];
    if (!serie.length) {
      mostrarVacio("ventas", "Sin ventas en 30 días.");
      return;
    }
    pintar("ventas", grfConfigLinea(serie, grfTemaPantalla()));
  }

  function pintarTop() {
    const top = (datos.top || []).slice(0, 10);
    if (!top.length) {
      mostrarVacio("top", "Sin ventas este mes.");
      return;
    }
    pintar("top", grfConfigBarras(top, grfTemaPantalla()));
  }

  // ------------------------------------------------------------------
  // Entradas de datos
  // ------------------------------------------------------------------

  /** Lo llama administrador.html desde el onDatos de la tabla de inventario. */
  function actualizarInventario(respuesta) {
    if (!respuesta || !Array.isArray(respuesta.productos)) return;
    datos.inventario = respuesta;
    renderSelectorProductos();
    pintarStock();
  }

  /** Lo llama administrador.html desde el onDatos de la tabla de costos. */
  function actualizarRentabilidad(respuesta) {
    if (!respuesta || !Array.isArray(respuesta.por_proveedor)) return;
    datos.rentabilidad = respuesta;
    pintarProveedores();
  }

  /**
   * El selector sale del catálogo del inventario (que ya está en memoria) y no
   * de las ventas: así se puede elegir un producto que NO vendió nada, que es
   * justamente una respuesta útil ("este no lo compra nadie").
   */
  function renderSelectorProductos() {
    const productos = (datos.inventario.productos || [])
      .slice()
      .sort((a, b) => String(a.nombre).localeCompare(String(b.nombre), "es"));

    const elegido = selectorProducto.value;
    selectorProducto.innerHTML =
      `<option value="">Ventas de todo el negocio</option>` +
      productos.map(p => {
        const etiqueta = grfAcortar([p.nombre, p.marca].filter(Boolean).join(" · "), 42);
        return `<option value="${p.id}">${etiqueta.replace(/</g, "&lt;")}</option>`;
      }).join("");

    // Si el producto elegido sigue existiendo tras una recarga del catálogo, se
    // mantiene la selección.
    if (elegido && selectorProducto.querySelector(`option[value="${elegido}"]`)) {
      selectorProducto.value = elegido;
    }
  }

  async function pedirVentas(agruparPor, productoId) {
    const cuerpo = { agrupar_por: agruparPor };
    if (productoId) cuerpo.producto_id = productoId;

    const resp = await fetchApi(URL_GRAFICOS_VENTAS, {
      method: "POST",
      headers: { ...GRF_HEADERS_NGROK, "Content-Type": "application/json" },
      body: JSON.stringify(cuerpo),
    });
    const data = await resp.json();
    if (!resp.ok || !data.ok) throw new Error(data.mensaje || "No se pudieron leer las ventas.");
    return data;
  }

  /** La serie de la línea: de todo el negocio, o del producto elegido. */
  async function cargarSerieVentas() {
    tituloVentas.textContent = productoSel
      ? `Ventas · ${grfAcortar(productoSel.nombre, 28)}`
      : "Ventas por día";

    try {
      // Agrupado por día el endpoint devuelve la serie en orden cronológico
      // (ver la nota de consultar_ventas.php): se grafica tal cual viene.
      const data = await pedirVentas("dia", productoSel ? productoSel.id : null);
      datos.ventas = data.resultados || [];
      datos.ventasTotales = { unidades: data.unidades_totales, monto: data.monto_total };
      pintarVentas();
    } catch (err) {
      mostrarVacio("ventas", mensajeDeError(err));
    }
  }

  async function cargarTop() {
    try {
      const data = await pedirVentas("producto", null);
      datos.top = data.resultados || [];
      pintarTop();
    } catch (err) {
      mostrarVacio("top", mensajeDeError(err));
    }
  }

  selectorProducto.addEventListener("change", () => {
    const id = selectorProducto.value;
    if (!id) {
      productoSel = null;
    } else {
      const p = (datos.inventario.productos || []).find(x => String(x.id) === String(id));
      productoSel = p ? { id: p.id, nombre: p.nombre } : null;
    }
    cargarSerieVentas();
  });

  // ------------------------------------------------------------------
  // Exportar a PDF
  // ------------------------------------------------------------------

  function exportarPDF() {
    const { jsPDF } = window.jspdf;
    // compress:true NO es opcional: sin él, jsPDF embebe las imágenes crudas y
    // este mismo reporte de 2 páginas pesaba 33 MB (medido).
    const doc = new jsPDF({ unit: "mm", format: "a4", compress: true });

    const M = 15;                 // margen
    const ANCHO = 210 - M * 2;    // ancho útil de una A4 vertical
    let y = M;

    const usuario = (typeof obtenerUsuarioSesion === "function" && obtenerUsuarioSesion()) || {};
    const negocio = (usuario.negocio_nombre || "").trim() || "Mi negocio";
    const ahora = new Date();
    const fecha = ahora.toLocaleDateString("es-AR") + " " + ahora.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit" });

    function texto(txt, tam, negrita, gris) {
      doc.setFont("helvetica", negrita ? "bold" : "normal");
      doc.setFontSize(tam);
      doc.setTextColor(gris ? 120 : 45);
      doc.text(txt, M, y);
      y += tam * 0.45 + 2;
    }

    /** Salta de página si lo que viene no entra en lo que queda. */
    function espacio(alto) {
      if (y + alto > 297 - M) {
        doc.addPage();
        y = M;
      }
    }

    // --- Encabezado ---
    texto("Reporte de estadísticas", 18, true);
    texto(negocio, 12, false);
    texto("Generado el " + fecha + " · stockIAte", 9, false, true);
    y += 3;

    // --- Resumen en números ---
    const inv = datos.inventario;
    const rent = datos.rentabilidad;
    const lineas = [];

    if (inv) {
      const criticos = (inv.productos || []).filter(p => {
        const k = estadoDeProducto(p).key;
        return k === "critical" || k === "unavailable";
      }).length;
      lineas.push(`Productos en catálogo: ${(inv.productos || []).length}`);
      lineas.push(`Requieren atención (crítico o sin stock): ${criticos}`);
      lineas.push(`Ventas de hoy: ${grfMoneda(inv.ventas_hoy_monto)} (${inv.ventas_hoy_unidades} unidades)`);
    }
    if (rent && rent.totales) {
      lineas.push(`Capital inmovilizado: ${grfMoneda(rent.totales.capital_inmovilizado)}`);
      lineas.push(`Ganancia estimada del período: ${grfMoneda(rent.totales.ganancia_estimada)}`);
      if (rent.totales.productos_sin_costo > 0) {
        // Sin esto, los números de arriba parecen completos y no lo son.
        lineas.push(`Atención: ${rent.totales.productos_sin_costo} producto(s) sin costo cargado, así que capital y ganancia son parciales.`);
      }
    }
    if (datos.ventasTotales) {
      const alcance = productoSel ? productoSel.nombre : "todo el negocio";
      lineas.push(`Ventas de los últimos 30 días (${alcance}): ${grfMoneda(datos.ventasTotales.monto)} (${datos.ventasTotales.unidades} unidades)`);
    }

    if (lineas.length) {
      texto("Resumen", 13, true);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(10);
      doc.setTextColor(45);
      lineas.forEach(l => {
        // Los renglones largos (el aviso de costos faltantes) se parten solos.
        doc.splitTextToSize(l, ANCHO).forEach(parte => {
          espacio(6);
          doc.text(parte, M, y);
          y += 5;
        });
      });
      y += 4;
    }

    // --- Gráficos ---
    // Se redibujan con GRF_TEMA_PDF (oscuro sobre blanco) y NO se copia lo que
    // está en pantalla: si el usuario está en modo oscuro, esos ejes en gris
    // claro serían ilegibles sobre el papel.
    const bloques = [];

    if (charts.stock) {
      const presentes = charts.stock.data;
      bloques.push({
        titulo: "Estado del stock",
        img: grfImagenPara(grfConfigTorta(presentes.labels, presentes.datasets[0].data, (v) => v, GRF_TEMA_PDF), 900, 460),
        alto: 62,
      });
    }
    if (charts.proveedor) {
      const lista = proveedoresConCapital();
      bloques.push({
        titulo: "Capital inmovilizado por proveedor",
        img: grfImagenPara(grfConfigTorta(lista.map(p => p.proveedor), lista.map(p => Number(p.capital_inmovilizado)), grfMoneda, GRF_TEMA_PDF), 900, 460),
        alto: 62,
      });
    }
    if (datos.ventas && datos.ventas.length) {
      bloques.push({
        titulo: productoSel ? `Ventas de ${productoSel.nombre} (últimos 30 días)` : "Ventas por día (últimos 30 días)",
        img: grfImagenPara(grfConfigLinea(datos.ventas, GRF_TEMA_PDF), 1100, 520),
        alto: 70,
      });
    }
    if (datos.top && datos.top.length) {
      bloques.push({
        titulo: "Top productos del mes",
        img: grfImagenPara(grfConfigBarras(datos.top.slice(0, 10), GRF_TEMA_PDF), 1100, 620),
        alto: 82,
      });
    }

    bloques.forEach(b => {
      espacio(b.alto + 10);
      texto(b.titulo, 12, true);
      doc.addImage(b.img, "JPEG", M, y, ANCHO, b.alto);
      y += b.alto + 8;
    });

    // --- Detalle del producto elegido ---
    if (productoSel && datos.ventas && datos.ventas.length) {
      espacio(30);
      texto(`Detalle de ${productoSel.nombre}`, 12, true);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(10);
      datos.ventas.forEach(r => {
        espacio(6);
        doc.text(`${grfFechaCorta(r.etiqueta)}  ·  ${r.unidades_vendidas} u.  ·  ${grfMoneda(r.total_vendido)}`, M, y);
        y += 5;
      });
    }

    // --- Pie con numeración, al final para saber cuántas páginas hubo ---
    const paginas = doc.getNumberOfPages();
    for (let i = 1; i <= paginas; i++) {
      doc.setPage(i);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8);
      doc.setTextColor(150);
      doc.text(`stockIAte · ${negocio}`, M, 290);
      doc.text(`${i} / ${paginas}`, 210 - M, 290, { align: "right" });
    }

    const slug = negocio.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "negocio";
    doc.save(`estadisticas-${slug}-${ahora.toISOString().slice(0, 10)}.pdf`);
  }

  botonPdf.addEventListener("click", () => {
    botonPdf.disabled = true;
    const textoOriginal = botonPdf.textContent;
    botonPdf.textContent = "Generando...";

    // El siguiente frame, para que el "Generando..." llegue a pintarse antes
    // del trabajo sincrónico de dibujar los gráficos y armar el PDF.
    requestAnimationFrame(() => {
      try {
        exportarPDF();
      } catch (err) {
        console.error("stockIAte: falló la exportación a PDF", err);
        alert("No se pudo generar el PDF. Revisá la consola para el detalle.");
      } finally {
        botonPdf.disabled = false;
        botonPdf.textContent = textoOriginal;
      }
    });
  });

  // ------------------------------------------------------------------
  // Tema
  // ------------------------------------------------------------------

  function repintarTodo() {
    if (datos.inventario) pintarStock();
    if (datos.rentabilidad) pintarProveedores();
    if (datos.ventas) pintarVentas();
    if (datos.top) pintarTop();
  }

  // El <canvas> no hereda del CSS: al cambiar el tema hay que releer las
  // variables y volver a dibujar, o los ejes quedan con los colores del tema
  // anterior. tema.js cambia el atributo data-tema del <html>.
  new MutationObserver(repintarTodo).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-tema"],
  });

  cargarSerieVentas();
  cargarTop();

  return {
    actualizarInventario,
    actualizarRentabilidad,
    recargar: () => { cargarSerieVentas(); cargarTop(); },
    exportarPDF,
  };
}
