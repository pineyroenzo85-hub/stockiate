/**
 * stockIAte - metricas_ia.js
 * ================================
 * Bloque "Rendimiento del modelo de IA" del panel de administrador: qué tan
 * seguido acierta el modelo, en qué se equivoca, y si la confianza que devuelve
 * sirve para predecir el error.
 *
 * POR QUÉ EXISTE ESTA PANTALLA
 * ----------------------------
 * `correcciones_ia` viene guardando cada detección y su corrección humana desde
 * que arrancó el piloto, y no había ninguna pantalla que la leyera. Es el
 * respaldo numérico del argumento central del proyecto -- que la Red de
 * Seguridad es lo que hace usable un modelo imperfecto -- y estaba sin usar.
 *
 * Lee de `consultar_metricas_ia.php` (solo lectura, sólo dueño).
 *
 * Uso:
 *   initMetricasIA(document.getElementById("contenedor"), { plegable: true });
 *
 * Clases con prefijo "mia-", igual que "cst-" en costos_tabla.js e "invt-" en
 * inventario_tabla.js: no chocar con styles.css ni con el Tailwind de
 * administrador.html.
 *
 * SIN LIBRERÍAS DE GRÁFICOS
 * -------------------------
 * Los dos gráficos de acá son SVG y divs a mano, no Chart.js. No es purismo:
 * un SVG inline hereda el CSS, así que se adapta solo al tema claro/oscuro. El
 * `<canvas>` de graficos.js necesita leer las variables con `getComputedStyle`
 * y un MutationObserver para repintar (ver la nota en graficos.js), y eso no
 * vale la pena para una línea de puntos y unas barras horizontales.
 */

const URL_MIA_METRICAS = "consultar_metricas_ia.php";

// Prefijo MIA_ porque este archivo convive en el scope global con
// inventario_tabla.js, costos_tabla.js, prediccion_tabla.js y preferencias.js,
// que ya declaran los suyos.
const MIA_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

const MIA_CLAVE_PLEGADO = "stockiate_metricas_ia_plegado";

/**
 * Los colores de las cuatro categorías. NO son los colores de estado del
 * proyecto (--ok / --alerta / --error) a propósito: acá "error de producto" no
 * es una alarma que haya que apagar, es una categoría de un gráfico. Pintarla
 * de rojo haría leer el panel como si algo estuviera roto.
 *
 * Están validados para daltonismo sobre fondo oscuro, así que el orden importa:
 * si se agrega una quinta categoría, va al final, no en el medio.
 *
 * Se usan SÓLO en el cuadradito de la leyenda y en el trazo del gráfico. Los
 * números y las etiquetas van en el color de texto normal: un número pintado
 * del color de su serie se vuelve ilegible en cuanto el color es claro, y en
 * escala de grises se pierde del todo.
 */
const MIA_COLORES = {
  acierto: "#8673d9",
  error_producto: "#009faa",
  error_cantidad: "#cf6139",
  no_reconocido: "#b761b1",
};

const MIA_ETIQUETAS = {
  acierto: "Acierto exacto",
  error_producto: "Error de producto",
  error_cantidad: "Error de cantidad",
  no_reconocido: "No reconocido",
};

const MIA_ESTILOS_ID = "mia-estilos";
const MIA_CSS = `
.mia-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.mia-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.mia-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.mia-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.mia-toggle{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.mia-toggle:hover{ color:var(--blanco-puro); }

.mia-body{ padding:18px 20px; display:flex; flex-direction:column; gap:22px; }

/* Titular: el acierto exacto, que es LA métrica */
.mia-titular{ display:flex; align-items:flex-end; gap:26px; flex-wrap:wrap; }
.mia-grande{ font-size:44px; font-weight:800; line-height:1; letter-spacing:-0.03em; }
.mia-grande small{ font-size:15px; font-weight:700; color:var(--gris-tenue); margin-left:4px; }
.mia-n{ font-size:12px; color:var(--gris-tenue); margin-top:6px; }
.mia-leyenda{ display:flex; flex-direction:column; gap:5px; font-size:12.5px; }
.mia-leyenda-fila{ display:flex; align-items:center; gap:8px; }
.mia-cuadro{ width:11px; height:11px; border-radius:3px; flex:none; }
.mia-leyenda-fila b{ font-weight:700; min-width:34px; text-align:right; }
.mia-leyenda-fila span{ color:var(--gris-tenue); }

/* Barra apilada de las cuatro categorías */
.mia-barra{ display:flex; height:10px; border-radius:999px; overflow:hidden; background:var(--gris-tenue-bg); flex-basis:100%; }
.mia-barra div{ height:100%; }

.mia-grupo-titulo{ font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:var(--gris-tenue); font-weight:800; margin:0 0 10px; }
.mia-nota{ font-size:12px; color:var(--gris-tenue); line-height:1.5; margin:8px 0 0; }
.mia-separador{ height:1px; background:var(--divisor); margin:0 -20px; }

/* Confianza: acierto vs. error, lado a lado */
.mia-confianza{ display:flex; gap:14px; flex-wrap:wrap; }
.mia-tarjeta{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border-radius:14px; padding:13px 16px; min-width:150px; }
.mia-tarjeta .mia-cuadro{ display:inline-block; vertical-align:middle; margin-right:6px; }
.mia-tarjeta-label{ font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--gris-tenue); font-weight:700; }
.mia-tarjeta-valor{ font-size:24px; font-weight:800; margin-top:7px; letter-spacing:-0.02em; }
.mia-tarjeta-n{ font-size:11.5px; color:var(--gris-tenue); margin-top:2px; }

/* Tramos de confianza: barras horizontales */
.mia-tramos{ display:flex; flex-direction:column; gap:7px; }
.mia-tramo{ display:grid; grid-template-columns:74px 1fr 92px; align-items:center; gap:10px; font-size:12.5px; }
.mia-tramo-rango{ color:var(--gris-tenue); font-variant-numeric:tabular-nums; }
.mia-tramo-pista{ background:var(--gris-tenue-bg); border-radius:999px; height:14px; overflow:hidden; }
.mia-tramo-relleno{ height:100%; border-radius:999px; }
.mia-tramo-dato{ text-align:right; font-variant-numeric:tabular-nums; color:var(--gris-tenue); }
.mia-tramo-dato b{ color:var(--blanco-puro); }

/* Tabla de confusiones */
.mia-tabla-wrap{ overflow-x:auto; }
.mia-tabla{ width:100%; border-collapse:collapse; font-size:12.5px; }
.mia-tabla th{ text-align:left; padding:9px 10px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; white-space:nowrap; }
.mia-tabla td{ padding:9px 10px; border-bottom:1px solid var(--divisor); vertical-align:top; }
.mia-tabla tr:last-child td{ border-bottom:none; }
.mia-tabla th:first-child, .mia-tabla td:first-child{ padding-left:0; }
.mia-marca{ display:block; font-size:11px; color:var(--gris-tenue); }
.mia-flecha{ color:var(--gris-tenue); }
.mia-num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }

/* Evolución semanal */
.mia-svg{ width:100%; height:auto; display:block; overflow:visible; }
.mia-eje{ stroke:var(--divisor); stroke-width:1; }
.mia-guia{ stroke:var(--divisor); stroke-width:1; stroke-dasharray:3 4; }
.mia-serie{ fill:none; stroke-width:2.5; stroke-linejoin:round; stroke-linecap:round; }
.mia-punto-borde{ stroke:var(--glass-bg); stroke-width:2; }
.mia-svg text{ fill:var(--gris-tenue); font-size:10.5px; font-family:'Plus Jakarta Sans','Inter',system-ui,sans-serif; }

.mia-aviso{ border-radius:10px; padding:10px 13px; font-size:12.5px; line-height:1.5; background:var(--alerta-bg); border:1px solid rgba(232,196,104,0.4); color:var(--alerta); }
.mia-empty{ color:var(--gris-tenue); font-size:12.5px; }

/* Plegado: queda a la vista el titular (el acierto exacto y su n), que es
   justamente el valor de tener el panel cerrado. Se esconde todo lo demás. */
.mia-panel.mia-plegado .mia-body > *:not(.mia-titular){ display:none; }
.mia-panel.mia-plegado .mia-body{ gap:0; }

@media (max-width: 620px){
  .mia-tramo{ grid-template-columns:64px 1fr 78px; gap:7px; font-size:11.5px; }
  .mia-grande{ font-size:36px; }
}
`;

function asegurarEstilosMetricasIA() {
  if (document.getElementById(MIA_ESTILOS_ID)) return;
  const estilo = document.createElement("style");
  estilo.id = MIA_ESTILOS_ID;
  estilo.textContent = MIA_CSS;
  document.head.appendChild(estilo);
}

/** Escapa texto que viene de la base antes de meterlo en innerHTML. */
function miaEscapar(texto) {
  const div = document.createElement("div");
  div.textContent = texto === null || texto === undefined ? "" : String(texto);
  return div.innerHTML;
}

/** "2026-08-31" -> "31/08" */
function miaFechaCorta(valor) {
  if (!valor) return "";
  const p = String(valor).split("-");
  return p.length >= 3 ? `${p[2]}/${p[1]}` : valor;
}

/** Un porcentaje que puede venir null (no había sobre qué calcularlo). */
function miaPct(valor) {
  return valor === null || valor === undefined ? "—" : `${valor}%`;
}

function initMetricasIA(contenedor, opciones = {}) {
  asegurarEstilosMetricasIA();

  const usaPlegable = opciones.plegable === true && typeof plegableConectar === "function";
  // Se lee ANTES de escribir el innerHTML y el markup se emite ya plegado:
  // pintar el panel abierto para cerrarlo después daría un parpadeo.
  const arrancaPlegado = usaPlegable ? plegableLeer(MIA_CLAVE_PLEGADO, true) : false;

  contenedor.innerHTML = `
    <div class="mia-panel ${arrancaPlegado ? "mia-plegado" : ""}">
      <div class="mia-panel-head">
        <div>
          <h2>Rendimiento del modelo de IA</h2>
          <p id="miaSubtitulo">Cargando métricas...</p>
        </div>
        ${usaPlegable ? `<button type="button" class="mia-toggle" data-mia-toggle-pleg></button>` : ""}
      </div>
      <div class="mia-body" id="miaBody">
        <div class="mia-titular" id="miaTitular"></div>
      </div>
    </div>
  `;

  const panel = contenedor.querySelector(".mia-panel");
  const subtitulo = contenedor.querySelector("#miaSubtitulo");
  const body = contenedor.querySelector("#miaBody");
  const titular = contenedor.querySelector("#miaTitular");
  const botonPlegar = contenedor.querySelector("[data-mia-toggle-pleg]");

  /** El cuadradito de color de una categoría. */
  function cuadro(categoria) {
    return `<span class="mia-cuadro" style="background:${MIA_COLORES[categoria]}"></span>`;
  }

  function renderTitular(data) {
    const r = data.resumen;

    // Con muestra chica no se muestra el porcentaje grande: se muestra el
    // conteo. Un 100% sobre 3 casos no es un 100%, y el número grande es
    // justo lo que alguien recorta para una presentación.
    const grande = data.muestra_insuficiente
      ? `${r.aciertos}<small> de ${r.total}</small>`
      : `${miaPct(r.acierto_pct_total)}`;

    const categorias = ["acierto", "error_producto", "error_cantidad", "no_reconocido"];
    const conteos = {
      acierto: r.aciertos,
      error_producto: r.error_producto,
      error_cantidad: r.error_cantidad,
      no_reconocido: r.no_reconocido,
    };

    // La barra apilada usa el total como denominador. Los dos errores pueden
    // solaparse en un mismo registro (producto mal Y cantidad mal), así que se
    // dibuja sobre el total y lo que sobra queda como fondo: inflar la barra
    // al 100% sumando categorías que se pisan sería dibujar un dato falso.
    const ancho = (n) => (r.total > 0 ? (n * 100) / r.total : 0);

    titular.innerHTML = `
      <div>
        <div class="mia-grande">${grande}</div>
        <div class="mia-n">
          Acierto exacto · <b>n = ${r.total}</b>
        </div>
      </div>
      <div class="mia-leyenda">
        ${categorias.map(c => `
          <div class="mia-leyenda-fila">
            ${cuadro(c)}<b>${conteos[c]}</b><span>${MIA_ETIQUETAS[c]}</span>
          </div>
        `).join("")}
      </div>
    `;

    const barra = document.createElement("div");
    barra.className = "mia-barra";
    barra.innerHTML = categorias.map(c =>
      `<div style="width:${ancho(conteos[c])}%; background:${MIA_COLORES[c]}"></div>`
    ).join("");
    titular.appendChild(barra);
  }

  /**
   * El hallazgo: ¿la confianza baja predice el error? Va con el n de cada
   * grupo pegado al promedio, porque una diferencia de 0.3 entre dos grupos de
   * 40 y de 2 casos no dice lo mismo.
   */
  function bloqueConfianza(data) {
    const c = data.confianza;
    const hayDiferencia =
      c.acierto.promedio !== null && c.error.promedio !== null;
    const diferencia = hayDiferencia
      ? (c.acierto.promedio - c.error.promedio).toFixed(3)
      : null;

    const tarjeta = (categoria, grupo) => `
      <div class="mia-tarjeta">
        <div class="mia-tarjeta-label">${cuadro(categoria)}${MIA_ETIQUETAS[categoria]}</div>
        <div class="mia-tarjeta-valor">${grupo.promedio === null ? "—" : grupo.promedio.toFixed(3)}</div>
        <div class="mia-tarjeta-n">n = ${grupo.n}</div>
      </div>
    `;

    return `
      <div>
        <p class="mia-grupo-titulo">Confianza promedio: ¿predice el error?</p>
        <div class="mia-confianza">
          ${tarjeta("acierto", c.acierto)}
          <div class="mia-tarjeta">
            <div class="mia-tarjeta-label">Cuando se equivocó</div>
            <div class="mia-tarjeta-valor">${c.error.promedio === null ? "—" : c.error.promedio.toFixed(3)}</div>
            <div class="mia-tarjeta-n">n = ${c.error.n}</div>
          </div>
          ${tarjeta("no_reconocido", c.no_reconocido)}
        </div>
        ${diferencia !== null
          ? `<p class="mia-nota">Cuando acierta, <b>${diferencia}</b> más de confianza.</p>`
          : ""}
      </div>
    `;
  }

  /**
   * El acierto por tramo de confianza. Es la versión de la pregunta anterior
   * que sirve para elegir un umbral concreto: no "los errores tienen menos
   * confianza", sino "abajo de 0.7 el acierto es X%".
   */
  function bloqueTramos(data) {
    if (!data.tramos_confianza.length) {
      return `<div><p class="mia-grupo-titulo">Acierto por tramo de confianza</p>
              <p class="mia-empty">Ninguna detección del período tiene confianza guardada.</p></div>`;
    }

    // Los tramos sin ningún caso se rellenan con n = 0 en vez de omitirse. El
    // SQL sólo devuelve los tramos que existen, y salteando uno la lista
    // mostraba "0.6–0.7" pegado a "0.8–0.9": se lee como si fueran contiguos y
    // el hueco (que es un dato) desaparece.
    const tramos = [];
    const presentes = new Map(data.tramos_confianza.map(t => [t.desde.toFixed(1), t]));
    const primero = data.tramos_confianza[0].desde;
    const ultimo = data.tramos_confianza[data.tramos_confianza.length - 1].desde;

    for (let d = Math.round(primero * 10); d <= Math.round(ultimo * 10); d++) {
      const clave = (d / 10).toFixed(1);
      tramos.push(presentes.get(clave) || {
        desde: d / 10, hasta: d / 10 + 0.1, n: 0, aciertos: 0, acierto_pct: null,
      });
    }

    // El ancho de la barra es el % de acierto del tramo, no su cantidad de
    // casos: la pregunta del gráfico es "qué tan confiable es este tramo".
    const filas = tramos.map(t => `
      <div class="mia-tramo">
        <span class="mia-tramo-rango">${t.desde.toFixed(1)} – ${t.hasta.toFixed(1)}</span>
        <div class="mia-tramo-pista">
          <div class="mia-tramo-relleno" style="width:${t.acierto_pct === null ? 0 : t.acierto_pct}%; background:${MIA_COLORES.acierto}"></div>
        </div>
        <span class="mia-tramo-dato">${t.n === 0 ? "sin casos" : `<b>${miaPct(t.acierto_pct)}</b> · n = ${t.n}`}</span>
      </div>
    `).join("");

    return `
      <div>
        <p class="mia-grupo-titulo">Acierto por tramo de confianza</p>
        <div class="mia-tramos">${filas}</div>
      </div>
    `;
  }

  function bloqueConfusiones(data) {
    if (!data.confusiones.length) {
      return `<div><p class="mia-grupo-titulo">Confusiones más frecuentes</p>
              <p class="mia-empty">No hubo ningún error de producto en el período.</p></div>`;
    }

    const filas = data.confusiones.map(c => `
      <tr>
        <td>
          ${miaEscapar(c.detectado.nombre || "(producto borrado)")}
          <span class="mia-marca">${miaEscapar(c.detectado.marca || "")}</span>
        </td>
        <td class="mia-flecha">→</td>
        <td>
          ${miaEscapar(c.corregido.nombre || "(sin elegir)")}
          <span class="mia-marca">${miaEscapar(c.corregido.marca || "")}</span>
        </td>
        <td class="mia-num">${c.veces}</td>
        <td class="mia-num">${c.confianza_promedio === null ? "—" : c.confianza_promedio.toFixed(2)}</td>
      </tr>
    `).join("");

    return `
      <div>
        <p class="mia-grupo-titulo">Confusiones más frecuentes</p>
        <div class="mia-tabla-wrap">
          <table class="mia-tabla">
            <thead>
              <tr>
                <th>La IA dijo</th><th></th><th>Era</th>
                <th class="mia-num">Veces</th><th class="mia-num">Confianza</th>
              </tr>
            </thead>
            <tbody>${filas}</tbody>
          </table>
        </div>
      </div>
    `;
  }

  /**
   * Evolución semanal, en SVG a mano.
   *
   * El eje Y va fijo de 0 a 100 y no escalado al mínimo y máximo de la serie:
   * con un eje ajustado, pasar de 92% a 94% se ve como un salto enorme. Acá lo
   * que importa es la altura absoluta.
   */
  function bloqueSemanas(data) {
    const semanas = data.semanas.filter(s => s.acierto_pct !== null);

    if (semanas.length < 2) {
      return `<div><p class="mia-grupo-titulo">Evolución semanal del acierto</p>
              <p class="mia-empty">Falta más de una semana de datos.</p></div>`;
    }

    const W = 720, H = 190;
    const M = { top: 12, right: 14, bottom: 26, left: 34 };
    const anchoUtil = W - M.left - M.right;
    const altoUtil = H - M.top - M.bottom;

    const x = (i) => M.left + (semanas.length === 1 ? anchoUtil / 2 : (anchoUtil * i) / (semanas.length - 1));
    const y = (pct) => M.top + altoUtil * (1 - pct / 100);

    const guias = [0, 25, 50, 75, 100].map(v => `
      <line class="mia-guia" x1="${M.left}" y1="${y(v)}" x2="${W - M.right}" y2="${y(v)}"></line>
      <text x="${M.left - 7}" y="${y(v) + 3.5}" text-anchor="end">${v}</text>
    `).join("");

    const linea = semanas.map((s, i) => `${x(i).toFixed(1)},${y(s.acierto_pct).toFixed(1)}`).join(" ");

    // Los puntos llevan el radio proporcionado al n de la semana: una semana
    // de 2 detecciones no puede pesar visualmente lo mismo que una de 30.
    const nMax = Math.max(...semanas.map(s => s.n));
    const puntos = semanas.map((s, i) => {
      const r = 2.5 + 2.5 * (nMax > 0 ? s.n / nMax : 0);
      return `<circle class="mia-punto-borde" cx="${x(i).toFixed(1)}" cy="${y(s.acierto_pct).toFixed(1)}" r="${r.toFixed(1)}"
                      fill="${MIA_COLORES.acierto}"></circle>`;
    }).join("");

    // Una etiqueta cada N semanas: con 13 semanas pegadas no se lee ninguna.
    const paso = Math.ceil(semanas.length / 8);
    const etiquetas = semanas.map((s, i) =>
      i % paso === 0 || i === semanas.length - 1
        ? `<text x="${x(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${miaFechaCorta(s.semana)}</text>`
        : ""
    ).join("");

    return `
      <div>
        <p class="mia-grupo-titulo">Evolución semanal del acierto</p>
        <svg class="mia-svg" viewBox="0 0 ${W} ${H}" role="img"
             aria-label="Porcentaje de acierto exacto por semana, de ${miaFechaCorta(semanas[0].semana)} a ${miaFechaCorta(semanas[semanas.length - 1].semana)}">
          ${guias}
          <line class="mia-eje" x1="${M.left}" y1="${M.top}" x2="${M.left}" y2="${M.top + altoUtil}"></line>
          <polyline class="mia-serie" stroke="${MIA_COLORES.acierto}" points="${linea}"></polyline>
          ${puntos}
          ${etiquetas}
        </svg>
        <p class="mia-nota">Punto más grande = más detecciones esa semana.</p>
      </div>
    `;
  }

  function render(data) {
    const r = data.resumen;

    subtitulo.textContent = r.total === 0
      ? "Sin detecciones todavía."
      : `${r.total} detecciones · ${data.desde} a ${data.hasta}`;

    // El titular se repinta siempre porque es lo único que queda a la vista
    // con el panel plegado.
    renderTitular(data);

    // Lo demás se reconstruye entero: son bloques de solo lectura, no hay
    // estado de formulario que preservar.
    body.querySelectorAll(".mia-bloque").forEach(n => n.remove());

    if (r.total === 0) return;

    const piezas = [];

    if (data.muestra_insuficiente) {
      piezas.push(`
        <div class="mia-aviso">
          <b>Muestra chica (n = ${r.total} de ${data.muestra_minima}).</b> Los porcentajes todavía no son confiables.
        </div>
      `);
    }

    piezas.push(bloqueConfianza(data));
    piezas.push(`<div class="mia-separador"></div>`);
    piezas.push(bloqueTramos(data));
    piezas.push(`<div class="mia-separador"></div>`);
    piezas.push(bloqueConfusiones(data));
    piezas.push(`<div class="mia-separador"></div>`);
    piezas.push(bloqueSemanas(data));

    // Cada pieza va en su propio hijo directo de .mia-body: la regla de
    // plegado esconde `> *:not(.mia-titular)`, así que lo que no sea hijo
    // directo no se plegaría.
    piezas.forEach(html => {
      const envoltorio = document.createElement("div");
      envoltorio.className = "mia-bloque";
      envoltorio.innerHTML = html;
      body.appendChild(envoltorio);
    });
  }

  async function cargar() {
    try {
      const resp = await fetchApi(URL_MIA_METRICAS, {
        method: "POST",
        headers: { ...MIA_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        subtitulo.textContent = data.mensaje || "No se pudieron cargar las métricas del modelo.";
        return;
      }

      render(data);
    } catch (err) {
      subtitulo.textContent = mensajeDeError(err);
    }
  }

  if (botonPlegar) {
    plegableConectar({
      panel: panel,
      boton: botonPlegar,
      clave: MIA_CLAVE_PLEGADO,
      clasePlegada: "mia-plegado",
      etiqueta: "métricas",
    });
  }

  // Se carga igual aunque el panel arranque plegado: el titular (el acierto
  // exacto y su n) es lo único que queda a la vista, y sale de este fetch.
  cargar();

  return { recargar: cargar };
}
