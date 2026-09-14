/**
 * stockIAte - reposicion_tabla.js
 * ================================
 * Bloque "Reposición al proveedor" del panel de administrador: qué hay que
 * pedir, cuánto, y cuánto va a salir el pedido.
 *
 * Es el espejo del riesgo de quiebre (`prediccion_tabla.js`): aquél avisa que
 * un producto se está por acabar, éste dice cuántas unidades pedir.
 *
 * Lee de `consultar_reposicion.php` (solo lectura, sólo dueño). Los tres
 * parámetros de la cuenta (días de entrega, días a pedir, colchón de
 * seguridad) se editan en Preferencias del negocio, no acá: son configuración
 * del negocio, no de esta pantalla.
 *
 * Uso:
 *   initReposicionTabla(document.getElementById("contenedor"), { plegable: true });
 *
 * Clases con prefijo "rep-", igual que "cst-" en costos_tabla.js.
 *
 * EL BOTÓN DE WHATSAPP AGRUPA POR PROVEEDOR
 * -----------------------------------------
 * El texto que se copia sale partido por proveedor, con un encabezado por
 * cada uno, y no como una lista corrida. Un pedido se le manda a UN proveedor:
 * una lista mezclada de seis proveedores no se puede mandar a ninguno sin
 * editarla a mano primero, que es justo el trabajo que el botón vino a evitar.
 * El filtro de arriba de la tabla permite copiar uno solo.
 */

const URL_REP_REPOSICION = "consultar_reposicion.php";

// Prefijo REP_ porque este archivo convive en el scope global con
// inventario_tabla.js, costos_tabla.js, prediccion_tabla.js, preferencias.js y
// metricas_ia.js, que ya declaran los suyos.
const REP_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

const REP_CLAVE_PLEGADO = "stockiate_reposicion_plegado";

const REP_ESTILOS_ID = "rep-estilos";
const REP_CSS = `
.rep-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.rep-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.rep-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.rep-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.rep-head-acciones{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto; }
.rep-info{ cursor:help; color:var(--gris-tenue); font-weight:600; font-size:13px; margin-left:4px; }
.rep-panel-head p:empty{ display:none; }
.rep-btn{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--blanco-puro); border-radius:10px; padding:8px 13px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.rep-btn:hover:not(:disabled){ color:var(--lila-dark); }
.rep-btn:disabled{ opacity:.5; cursor:default; }
.rep-select{ background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:10px; padding:8px 11px; color:var(--blanco-puro); font-size:12.5px; font-family:inherit; outline:none; max-width:210px; }

.rep-tabla-wrap{ overflow-x:auto; }
.rep-tabla{ width:100%; border-collapse:collapse; font-size:12.5px; }
.rep-tabla th{ text-align:left; padding:11px 14px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; white-space:nowrap; }
.rep-tabla td{ padding:10px 14px; border-bottom:1px solid var(--divisor); vertical-align:middle; }
.rep-tabla tr:last-child td{ border-bottom:none; }
.rep-num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.rep-marca{ display:block; font-size:11px; color:var(--gris-tenue); }
.rep-sugerido{ font-weight:800; font-size:14px; }

/* La urgencia se dice con TEXTO además de con color: "0.8 días" ya es la
   información, el color sólo la refuerza. Un badge que sólo fuera color no se
   entendería en escala de grises ni para quien no distingue rojo de verde. */
.rep-badge{ display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.rep-badge-urgente{ background:var(--error-bg); color:var(--error); }
.rep-badge-pronto{ background:var(--alerta-bg); color:var(--alerta); }
.rep-badge-ok{ background:var(--ok-bg); color:var(--ok); }
.rep-flojo{ font-size:11px; color:var(--alerta); }

.rep-pie{ padding:14px 20px; border-top:1px solid var(--divisor); display:flex; gap:22px; flex-wrap:wrap; font-size:12.5px; color:var(--gris-tenue); }
.rep-pie b{ color:var(--blanco-puro); font-weight:800; }
.rep-aviso{ margin:14px 20px 0; border-radius:10px; padding:10px 13px; font-size:12.5px; line-height:1.5; background:var(--alerta-bg); border:1px solid rgba(232,196,104,0.4); color:var(--alerta); }
.rep-empty{ padding:26px 20px; color:var(--gris-tenue); font-size:12.5px; text-align:center; }
.rep-copiado{ font-size:12px; color:var(--ok); font-weight:700; }

/* El textarea de respaldo: si el navegador no deja escribir el portapapeles
   (pasa fuera de https), el texto igual tiene que poder salir de acá. */
.rep-texto{ width:calc(100% - 40px); margin:14px 20px 0; min-height:160px; background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:12px; padding:12px 14px; color:var(--blanco-puro); font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; line-height:1.55; resize:vertical; }

/* Plegado: queda el pie, que es el resumen (cuántos productos y cuánta plata).
   El botón de plegar va FUERA de .rep-head-acciones: cuando estaba adentro,
   esta regla lo escondía también y el panel plegado no se podía volver a abrir. */
.rep-panel.rep-plegado .rep-tabla-wrap,
.rep-panel.rep-plegado .rep-head-acciones,
.rep-panel.rep-plegado .rep-texto,
.rep-panel.rep-plegado .rep-aviso{ display:none; }
/* Sin nada para pedir no se dibuja la tabla vacía: el pie ya lo dice. */
.rep-panel.rep-vacio .rep-tabla-wrap{ display:none; }
/* Sin tabla en el medio, el borde del head y el del pie hacían doble línea. */
.rep-panel.rep-plegado .rep-panel-head{ border-bottom:none; }
`;

function asegurarEstilosReposicion() {
  if (document.getElementById(REP_ESTILOS_ID)) return;
  const estilo = document.createElement("style");
  estilo.id = REP_ESTILOS_ID;
  estilo.textContent = REP_CSS;
  document.head.appendChild(estilo);
}

function repEscapar(texto) {
  const div = document.createElement("div");
  div.textContent = texto === null || texto === undefined ? "" : String(texto);
  return div.innerHTML;
}

function repPesos(valor) {
  return `$${Math.round(valor).toLocaleString("es-AR")}`;
}

/** Sin proveedor cargado, el producto igual tiene que poder pedirse. */
const REP_SIN_PROVEEDOR = "Sin proveedor asignado";

function initReposicionTabla(contenedor, opciones = {}) {
  asegurarEstilosReposicion();

  const usaPlegable = opciones.plegable === true && typeof plegableConectar === "function";
  const arrancaPlegado = usaPlegable ? plegableLeer(REP_CLAVE_PLEGADO, true) : false;

  contenedor.innerHTML = `
    <div class="rep-panel ${arrancaPlegado ? "rep-plegado" : ""}">
      <div class="rep-panel-head">
        <div>
          <h2>Reposición al proveedor<span class="rep-info" id="repInfo" tabindex="0" role="note" hidden>ⓘ</span></h2>
          <p id="repSubtitulo">Calculando...</p>
        </div>
        <div class="rep-head-acciones">
          <select class="rep-select" id="repProveedor" title="Filtrar por proveedor">
            <option value="">Todos los proveedores</option>
          </select>
          <button type="button" class="rep-btn" id="repCopiar">Copiar para WhatsApp</button>
        </div>
        ${usaPlegable ? `<button type="button" class="rep-btn" data-rep-toggle-pleg></button>` : ""}
      </div>
      <div id="repAviso"></div>
      <div class="rep-tabla-wrap">
        <table class="rep-tabla">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Proveedor</th>
              <th class="rep-num">Stock</th>
              <th class="rep-num">Le queda</th>
              <th class="rep-num">Pedir</th>
              <th class="rep-num">Costo estimado</th>
            </tr>
          </thead>
          <tbody id="repTbody"></tbody>
        </table>
      </div>
      <textarea class="rep-texto" id="repTexto" hidden readonly
                aria-label="Texto del pedido para copiar"></textarea>
      <div class="rep-pie" id="repPie"></div>
    </div>
  `;

  const panel = contenedor.querySelector(".rep-panel");
  const subtitulo = contenedor.querySelector("#repSubtitulo");
  const info = contenedor.querySelector("#repInfo");
  const tbody = contenedor.querySelector("#repTbody");
  const pie = contenedor.querySelector("#repPie");
  const aviso = contenedor.querySelector("#repAviso");
  const selectProveedor = contenedor.querySelector("#repProveedor");
  const botonCopiar = contenedor.querySelector("#repCopiar");
  const textarea = contenedor.querySelector("#repTexto");
  const botonPlegar = contenedor.querySelector("[data-rep-toggle-pleg]");

  let datos = null;

  function visibles() {
    if (!datos) return [];
    const filtro = selectProveedor.value;
    if (!filtro) return datos.resultados;
    return datos.resultados.filter(r => (r.proveedor || REP_SIN_PROVEEDOR) === filtro);
  }

  /**
   * El badge de urgencia. Los cortes son en días de venta que quedan, no en
   * unidades: 3 unidades es mucho para un producto que vende uno por semana y
   * nada para uno que vende cuatro por día.
   */
  function badgeCobertura(dias) {
    if (dias <= 0) return `<span class="rep-badge rep-badge-urgente">sin stock</span>`;
    const texto = dias < 10 ? `${dias.toFixed(1)} días` : `${Math.round(dias)} días`;
    if (dias < 3) return `<span class="rep-badge rep-badge-urgente">${texto}</span>`;
    if (dias < 10) return `<span class="rep-badge rep-badge-pronto">${texto}</span>`;
    return `<span class="rep-badge rep-badge-ok">${texto}</span>`;
  }

  function renderTabla() {
    const filas = visibles();

    panel.classList.toggle("rep-vacio", !filas.length);
    if (!filas.length) {
      tbody.innerHTML = "";
      return;
    }

    tbody.innerHTML = filas.map(r => {
      // Un producto que apenas pasa el mínimo de historia proyecta peor que
      // uno con meses de ventas. Se avisa en vez de esconderlo: el dato sirve,
      // pero con menos confianza.
      const flojo = r.dias_historia < datos.parametros.dias_historia_venta / 2
        ? `<span class="rep-flojo">sólo ${r.dias_historia} días de historia</span>`
        : "";

      return `
        <tr>
          <td>
            ${repEscapar(r.nombre)}${flojo ? "<br>" + flojo : ""}
            <span class="rep-marca">${repEscapar([r.marca, r.variante].filter(Boolean).join(" · "))}</span>
          </td>
          <td>${repEscapar(r.proveedor || REP_SIN_PROVEEDOR)}</td>
          <td class="rep-num">${r.stock_actual}</td>
          <td class="rep-num">${badgeCobertura(r.dias_cobertura)}</td>
          <td class="rep-num rep-sugerido">${r.sugerido}</td>
          <td class="rep-num">${r.costo_estimado === null
            ? `<span class="rep-marca">sin costo cargado</span>`
            : repPesos(r.costo_estimado)}</td>
        </tr>
      `;
    }).join("");
  }

  function renderPie() {
    const filas = visibles();
    const conCosto = filas.filter(r => r.costo_estimado !== null);
    const total = conCosto.reduce((acc, r) => acc + r.costo_estimado, 0);
    const unidades = filas.reduce((acc, r) => acc + r.sugerido, 0);
    const sinCosto = filas.length - conCosto.length;

    if (!filas.length) {
      pie.innerHTML = `<span style="color:var(--ok); font-weight:700">✓ Nada para pedir</span>`;
      return;
    }

    pie.innerHTML = `
      <span><b>${filas.length}</b> ${filas.length === 1 ? "producto" : "productos"}</span>
      <span><b>${unidades}</b> unidades</span>
      <span>Costo: <b>${repPesos(total)}</b>${
        // Un total que parece completo pero se saltea productos sin costo
        // cargado es un número que engaña. Se dice en el mismo renglón.
        sinCosto > 0 ? ` <span style="color:var(--alerta)">(${sinCosto} sin costo)</span>` : ""
      }</span>
    `;
  }

  function renderAviso() {
    const partes = [];

    const p = datos.parametros;

    if (datos.parametros_contradictorios) {
      partes.push(
        `Hay sugerencias en 0: ${p.dias_objetivo} días a pedir no cubren la entrega con colchón. ` +
        `Ajustalo en Preferencias.`
      );
    }

    // Por qué falta un producto, en una sola línea: sin ventas no se repone
    // (se liquida) y con poca historia la venta diaria sería inventada.
    const d = datos.diagnostico;
    const afuera = [];
    if (d.sin_ventas > 0) afuera.push(`${d.sin_ventas} sin ventas en ${p.dias_historia_venta} días`);
    if (d.sin_historia > 0) afuera.push(`${d.sin_historia} con menos de ${p.antiguedad_minima_dias} días de historia`);
    if (afuera.length) partes.push(`No se incluyen: ${afuera.join(" · ")}.`);

    aviso.innerHTML = partes.length
      ? `<div class="rep-aviso">${partes.join("<br>")}</div>`
      : "";
  }

  function renderProveedores() {
    const nombres = [...new Set(datos.resultados.map(r => r.proveedor || REP_SIN_PROVEEDOR))].sort();
    const elegido = selectProveedor.value;

    selectProveedor.innerHTML =
      `<option value="">Todos los proveedores</option>` +
      nombres.map(n => `<option value="${repEscapar(n)}">${repEscapar(n)}</option>`).join("");

    if (nombres.includes(elegido)) selectProveedor.value = elegido;
  }

  /**
   * El texto del pedido, agrupado por proveedor.
   *
   * Sin encabezados de acento ni emojis: esto se pega en WhatsApp y se manda
   * tal cual, y lo que importa es que del otro lado se entienda qué producto
   * y cuántos.
   */
  function textoParaWhatsApp() {
    const filas = visibles();
    if (!filas.length) return "";

    const porProveedor = new Map();
    for (const r of filas) {
      const clave = r.proveedor || REP_SIN_PROVEEDOR;
      if (!porProveedor.has(clave)) porProveedor.set(clave, []);
      porProveedor.get(clave).push(r);
    }

    const bloques = [];
    for (const [proveedor, items] of [...porProveedor.entries()].sort()) {
      const lineas = items.map(r => {
        const marca = r.marca ? `${r.marca} ` : "";
        // La variante se omite si el nombre ya la dice ("Shampoo Hidratación
        // 400ml" + variante "Hidratación"). Repetirla no agrega nada y hace
        // el mensaje más largo de leer para el que lo recibe.
        const repetida = r.variante &&
          r.nombre.toLowerCase().includes(r.variante.toLowerCase());
        const variante = r.variante && !repetida ? ` (${r.variante})` : "";
        return `- ${marca}${r.nombre}${variante}: ${r.sugerido} u.`;
      });
      bloques.push(`*${proveedor}*\n${lineas.join("\n")}`);
    }

    return `Pedido ${new Date().toLocaleDateString("es-AR")}\n\n${bloques.join("\n\n")}`;
  }

  botonCopiar.addEventListener("click", async () => {
    const texto = textoParaWhatsApp();
    if (!texto) return;

    const original = botonCopiar.textContent;

    try {
      // `navigator.clipboard` no existe fuera de un contexto seguro (http en
      // una IP de la red local, por ejemplo). No es un caso raro acá: el
      // sistema se usa por LAN. Por eso hay respaldo.
      await navigator.clipboard.writeText(texto);
      textarea.hidden = true;
      botonCopiar.textContent = "Copiado";
      setTimeout(() => { botonCopiar.textContent = original; }, 1600);
    } catch (err) {
      textarea.value = texto;
      textarea.hidden = false;
      textarea.focus();
      textarea.select();
      botonCopiar.textContent = "Copialo de acá";
      setTimeout(() => { botonCopiar.textContent = original; }, 2600);
    }
  });

  selectProveedor.addEventListener("change", () => {
    renderTabla();
    renderPie();
    textarea.hidden = true;
  });

  async function cargar() {
    try {
      const resp = await fetchApi(URL_REP_REPOSICION, {
        method: "POST",
        headers: { ...REP_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        subtitulo.textContent = data.mensaje || "No se pudo calcular la reposición.";
        tbody.innerHTML = "";
        return;
      }

      datos = data;

      // Los parámetros de la cuenta van al tooltip del ⓘ: a la vista eran dos
      // oraciones que el resumen del pie ya no necesita.
      const p = data.parametros;
      const detalle =
        `Pedido para ${p.dias_objetivo} días, entrega en ${p.dias_entrega} días, colchón ${p.factor_seguridad}. ` +
        `Ritmo de los últimos ${p.dias_historia_venta} días. Se cambia en Preferencias.`;
      info.title = detalle;
      info.setAttribute("aria-label", detalle);
      info.hidden = false;
      subtitulo.textContent = "";

      renderProveedores();
      renderAviso();
      renderTabla();
      renderPie();
      botonCopiar.disabled = data.resultados.length === 0;
    } catch (err) {
      panel.classList.remove("rep-vacio");
      subtitulo.textContent = mensajeDeError(err);
      tbody.innerHTML = `<tr><td colspan="6" class="rep-empty">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
    }
  }

  if (botonPlegar) {
    plegableConectar({
      panel: panel,
      boton: botonPlegar,
      clave: REP_CLAVE_PLEGADO,
      clasePlegada: "rep-plegado",
      etiqueta: "reposición",
    });
  }

  // Se carga igual aunque el panel arranque plegado: el pie (cuántos productos
  // y cuánta plata) es lo único que queda a la vista, y sale de este fetch.
  cargar();

  return { recargar: cargar };
}
