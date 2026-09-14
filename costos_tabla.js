/**
 * stockIAte - costos_tabla.js
 * ================================
 * Bloque "Costos y márgenes" del panel de administrador: una fila por
 * producto con costo, precio de venta y proveedor editables, y el margen
 * recalculado en vivo mientras se tipea.
 *
 * POR QUÉ EXISTE ESTA PANTALLA
 * ----------------------------
 * `crear_producto.php` acepta el costo, pero es opcional y en la Red de
 * Seguridad (donde se dan de alta los productos, con el cliente esperando)
 * casi nunca se carga. Los productos que ya estaban en el catálogo tampoco lo
 * tenían. Resultado: 25 de 36 productos sin `precio_costo`, y todo lo que
 * responde el chatbot sobre margen y rentabilidad saliendo parcial. Esta
 * pantalla es el lugar para sentarse a cargarlos de una sentada, fuera del
 * mostrador.
 *
 * Lee de `consultar_rentabilidad.php` (el mismo endpoint que usa el chatbot,
 * así que la tabla y el asistente muestran exactamente los mismos números) y
 * escribe con `actualizar_costo.php`. Los dos son sólo-dueño.
 *
 * Uso:
 *   initCostosTabla(document.getElementById("contenedor"));
 *
 * Clases con prefijo "cst-" por la misma razón que "invt-" en
 * inventario_tabla.js: no chocar con styles.css ni con el Tailwind de
 * administrador.html.
 *
 * Opciones:
 *   plegable: true  // agrega el botón de plegar y recuerda el estado. Requiere
 *                    // que la página cargue plegable.js.
 */

const URL_COSTOS_RENTABILIDAD = "consultar_rentabilidad.php";
const URL_COSTOS_ACTUALIZAR = "actualizar_costo.php";
const URL_COSTOS_PROVEEDORES = "consultar_proveedores.php";

// Prefijo CST_ porque este archivo convive en el scope global con
// inventario_tabla.js, que ya declara su propio HEADERS_NGROK.
const CST_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

// Se pide el catálogo entero, no el top 50 que le alcanza al chatbot: acá la
// tarea es justamente no dejarse ninguno sin cargar.
const CST_LIMITE = 500;

// El mecanismo de plegado vive en plegable.js, compartido con
// inventario_tabla.js. Acá queda sólo la clave y qué se esconde (el CSS).
const CST_CLAVE_PLEGADO = "stockiate_costos_plegado";

const CST_ESTILOS_ID = "cst-estilos";
const CST_CSS = `
.cst-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; box-shadow:var(--glass-shadow); }
.cst-panel-head p.cst-faltan{ color:var(--alerta); font-weight:700; }
.cst-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.cst-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.cst-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.cst-head-acciones{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.cst-toggle{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
.cst-toggle:hover{ color:var(--blanco-puro); }
.cst-toggle.activo{ background:var(--lila-tenue); color:var(--lila-dark); box-shadow:var(--neu-inset); }
/* Plegado: se esconde la tabla y los controles que sólo sirven teniéndola a la
   vista. El .cst-resumen SE QUEDA -- capital inmovilizado, margen promedio y
   cuántos productos tienen costo cargado son justo el número que uno quiere ver
   sin abrir nada. */
.cst-panel.cst-plegado .cst-table-wrap,
.cst-panel.cst-plegado .cst-head-acciones{ display:none; }
/* margin-left:auto: con el botón de plegar el head tiene tres hijos y el
   space-between dejaría las acciones en el medio (igual que en invt-). */
.cst-head-acciones{ margin-left:auto; }
/* Sin tabla en el medio, el borde del head y el del resumen se pegaban en una
   doble línea. */
.cst-panel.cst-plegado .cst-panel-head{ border-bottom:none; }
.cst-toggle-pleg{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--gris-tenue); border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap; }
.cst-toggle-pleg:hover{ color:var(--blanco-puro); }
.cst-search-box{ display:flex; align-items:center; gap:8px; background:var(--superficie); box-shadow:var(--neu-inset); border-radius:10px; padding:8px 12px; min-width:200px; }
.cst-search-box input{ background:transparent; border:none; outline:none; color:var(--blanco-puro); font-size:13px; width:100%; font-family:inherit; }
.cst-search-box input::placeholder{ color:var(--gris-tenue); }
.cst-table-wrap{ overflow-x:auto; }
.cst-table{ width:100%; border-collapse:collapse; font-size:13.5px; }
.cst-table th{ text-align:left; padding:11px 20px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; white-space:nowrap; }
.cst-table td{ padding:10px 20px; border-bottom:1px solid var(--divisor); vertical-align:middle; }
.cst-table tr:last-child td{ border-bottom:none; }
.cst-prod-name{ font-weight:700; }
.cst-prod-sub{ font-size:11.5px; color:var(--gris-tenue); margin-top:2px; }
.cst-input{ background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:8px; color:var(--blanco-puro); padding:7px 9px; font-size:13px; font-family:inherit; outline:none; width:100px; transition:box-shadow .15s, background .3s; }
.cst-input:focus{ box-shadow:var(--neu-inset), 0 0 0 2px var(--lila); }
.cst-input.cst-proveedor{ width:150px; }
.cst-input.cst-guardando{ opacity:.6; }
.cst-input.cst-ok{ background:var(--ok-bg); box-shadow:var(--neu-inset), 0 0 0 2px var(--ok-border); }
.cst-input.cst-error{ background:var(--error-bg); box-shadow:var(--neu-inset), 0 0 0 2px var(--error-border); }
.cst-margen{ font-weight:700; white-space:nowrap; }
.cst-margen.bueno{ color:var(--ok); }
.cst-margen.medio{ color:var(--alerta); }
.cst-margen.malo{ color:var(--error); }
.cst-margen.vacio{ color:var(--gris-tenue); font-weight:500; }
.cst-capital{ color:var(--gris-tenue); white-space:nowrap; }
.cst-fila-sin-costo{ background:var(--alerta-bg); }
.cst-empty-row td{ text-align:center; padding:30px; color:var(--gris-tenue); }
.cst-resumen{ padding:14px 20px; border-top:1px solid var(--divisor); display:flex; gap:26px; flex-wrap:wrap; font-size:12.5px; color:var(--gris-tenue); }
.cst-resumen b{ color:var(--blanco-puro); }
`;

function asegurarEstilosCostos() {
  if (document.getElementById(CST_ESTILOS_ID)) return;
  const style = document.createElement("style");
  style.id = CST_ESTILOS_ID;
  style.textContent = CST_CSS;
  document.head.appendChild(style);
}

function cstMoneda(n) {
  if (n === null || n === undefined) return "—";
  return `$${Number(n).toLocaleString("es-AR", { maximumFractionDigits: 0 })}`;
}

/**
 * El margen se colorea sobre la venta (no el markup sobre el costo): es el
 * número con el que se razona un negocio minorista ("de cada $100 que entran,
 * $40 son míos"). Los cortes son orientativos, no una regla contable.
 */
function cstClaseMargen(pct) {
  if (pct === null || pct === undefined) return "vacio";
  if (pct >= 35) return "bueno";
  if (pct >= 15) return "medio";
  return "malo";
}

function initCostosTabla(contenedor, opciones = {}) {
  asegurarEstilosCostos();

  // Arranca PLEGADO. Se resuelve ANTES de escribir el innerHTML y el markup se
  // emite ya plegado: pintarlo abierto para cerrarlo después daría un parpadeo.
  const plegable = opciones.plegable === true;
  const plegado = plegable && plegableLeer(CST_CLAVE_PLEGADO, true);

  let productos = [];
  let proveedores = [];
  let soloFaltantes = false;

  contenedor.innerHTML = `
    <div class="cst-panel${plegado ? " cst-plegado" : ""}">
      <div class="cst-panel-head">
        <div>
          <h2>Costos y márgenes</h2>
          <p data-cst-subtitle>Cargando costos...</p>
        </div>
        <div class="cst-head-acciones">
          <button type="button" class="cst-toggle" data-cst-toggle>Solo los que faltan</button>
          <div class="cst-search-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" data-cst-search placeholder="Buscar producto...">
          </div>
        </div>
        ${plegable ? `<button type="button" class="cst-toggle-pleg" data-cst-toggle-pleg aria-expanded="${plegado ? "false" : "true"}" aria-controls="cstTablaWrap"></button>` : ""}
      </div>
      <div class="cst-table-wrap" id="cstTablaWrap">
        <table class="cst-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Proveedor</th>
              <th>Costo</th>
              <th>Venta</th>
              <th>Margen</th>
              <th>Inmovilizado</th>
            </tr>
          </thead>
          <tbody data-cst-tbody></tbody>
        </table>
      </div>
      <div class="cst-resumen" data-cst-resumen></div>
      <datalist id="cst-lista-proveedores"></datalist>
    </div>
  `;

  const panel = contenedor.querySelector(".cst-panel");
  const botonPlegar = contenedor.querySelector("[data-cst-toggle-pleg]");
  const subtitulo = contenedor.querySelector("[data-cst-subtitle]");
  const buscador = contenedor.querySelector("[data-cst-search]");
  const botonToggle = contenedor.querySelector("[data-cst-toggle]");
  const tbody = contenedor.querySelector("[data-cst-tbody]");
  const resumenEl = contenedor.querySelector("[data-cst-resumen]");
  const datalist = contenedor.querySelector("#cst-lista-proveedores");

  function renderSubtitulo() {
    const sinCosto = productos.filter(p => p.precio_costo === null).length;
    subtitulo.classList.toggle("cst-faltan", sinCosto > 0);
    subtitulo.textContent = sinCosto === 0
      ? `${productos.length} productos`
      : `Faltan ${sinCosto} de ${productos.length} costos`;
  }

  function renderResumen() {
    const conCosto = productos.filter(p => p.precio_costo !== null);
    const capital = conCosto.reduce((acc, p) => acc + p.precio_costo * p.stock_actual, 0);
    const margenes = conCosto
      .map(p => (p.precio_venta > 0 ? (p.precio_venta - p.precio_costo) / p.precio_venta * 100 : null))
      .filter(m => m !== null);
    const promedio = margenes.length
      ? margenes.reduce((a, b) => a + b, 0) / margenes.length
      : null;

    resumenEl.innerHTML = `
      <span>Capital inmovilizado: <b>${cstMoneda(capital)}</b></span>
      <span>Margen promedio: <b>${promedio === null ? "—" : promedio.toFixed(1) + "%"}</b></span>
    `;
  }

  function renderDatalist() {
    datalist.innerHTML = proveedores.map(p => `<option value="${(p.nombre || "").replace(/"/g, "&quot;")}">`).join("");
  }

  function renderTabla() {
    const f = buscador.value.trim().toLowerCase();
    let filtrados = productos;

    if (soloFaltantes) filtrados = filtrados.filter(p => p.precio_costo === null);
    if (f) {
      filtrados = filtrados.filter(p =>
        (p.nombre || "").toLowerCase().includes(f) ||
        (p.marca || "").toLowerCase().includes(f) ||
        (p.proveedor || "").toLowerCase().includes(f)
      );
    }

    if (filtrados.length === 0) {
      const vacio = soloFaltantes && !f
        ? "No falta ningún costo. 🎉"
        : `Sin resultados para "${buscador.value}"`;
      tbody.innerHTML = `<tr class="cst-empty-row"><td colspan="6">${vacio}</td></tr>`;
      return;
    }

    tbody.innerHTML = filtrados.map(p => {
      const margen = margenDe(p);
      return `
      <tr data-cst-row data-id="${p.id}" class="${p.precio_costo === null ? "cst-fila-sin-costo" : ""}">
        <td>
          <div class="cst-prod-name">${p.nombre}</div>
          <div class="cst-prod-sub">${p.sku}${p.marca ? " · " + p.marca : ""} · ${p.stock_actual} u.</div>
        </td>
        <td>
          <input type="text" class="cst-input cst-proveedor" data-campo="proveedor"
                 list="cst-lista-proveedores" placeholder="—"
                 value="${(p.proveedor || "").replace(/"/g, "&quot;")}">
        </td>
        <td>
          <input type="number" class="cst-input" data-campo="precio_costo" min="0" step="0.01"
                 placeholder="—" value="${p.precio_costo === null ? "" : p.precio_costo}">
        </td>
        <td>
          <input type="number" class="cst-input" data-campo="precio_venta" min="0" step="0.01"
                 placeholder="0" value="${p.precio_venta}">
        </td>
        <td data-cst-margen>${pintarMargen(margen)}</td>
        <td class="cst-capital" data-cst-capital>${p.precio_costo === null ? "—" : cstMoneda(p.precio_costo * p.stock_actual)}</td>
      </tr>`;
    }).join("");
  }

  function margenDe(p) {
    if (p.precio_costo === null || !(p.precio_venta > 0)) return null;
    return (p.precio_venta - p.precio_costo) / p.precio_venta * 100;
  }

  function pintarMargen(pct) {
    if (pct === null) return `<span class="cst-margen vacio">sin costo</span>`;
    return `<span class="cst-margen ${cstClaseMargen(pct)}">${pct.toFixed(1)}%</span>`;
  }

  /**
   * Recalcula margen y capital de una fila mientras se tipea, sin esperar al
   * guardado. Es el feedback que hace que cargar 25 costos seguidos no sea a
   * ciegas: se ve si el precio que pusiste deja el margen donde querés.
   */
  function refrescarCalculosDeFila(fila) {
    const costoTxt = fila.querySelector('[data-campo="precio_costo"]').value.trim();
    const ventaTxt = fila.querySelector('[data-campo="precio_venta"]').value.trim();
    const costo = costoTxt === "" ? null : parseFloat(costoTxt);
    const venta = ventaTxt === "" ? 0 : parseFloat(ventaTxt);

    const valido = costo !== null && !isNaN(costo) && !isNaN(venta) && venta > 0;
    const pct = valido ? (venta - costo) / venta * 100 : null;

    fila.querySelector("[data-cst-margen]").innerHTML = pintarMargen(pct);

    const prod = productos.find(p => p.id === parseInt(fila.dataset.id, 10));
    const stock = prod ? prod.stock_actual : 0;
    fila.querySelector("[data-cst-capital]").textContent =
      costo === null || isNaN(costo) ? "—" : cstMoneda(costo * stock);
  }

  function marcar(input, clase) {
    input.classList.remove("cst-ok", "cst-error", "cst-guardando");
    if (!clase) return;
    input.classList.add(clase);
    if (clase !== "cst-guardando") {
      setTimeout(() => input.classList.remove(clase), 1200);
    }
  }

  /**
   * Guarda UN campo, no la fila entera: mandar los tres siempre haría que
   * editar el costo pisara un precio de venta cambiado desde otra pestaña.
   * Ver la nota de actualización parcial en actualizar_costo.php.
   */
  async function guardarCampo(fila, input) {
    const id = parseInt(fila.dataset.id, 10);
    const campo = input.dataset.campo;
    const prod = productos.find(p => p.id === id);
    if (!prod) return;

    let valor = input.value.trim();
    // Sin cambios respecto de lo que ya está guardado: no gastamos un request.
    const actual = campo === "proveedor" ? (prod.proveedor || "") : String(prod[campo] === null ? "" : prod[campo]);
    if (valor === actual) return;

    marcar(input, "cst-guardando");
    input.disabled = true;

    try {
      const resp = await fetchApi(URL_COSTOS_ACTUALIZAR, {
        method: "POST",
        headers: { ...CST_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ producto_id: id, [campo]: valor === "" ? null : valor }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        marcar(input, "cst-error");
        alert(data.mensaje || "No se pudo guardar el cambio.");
        return;
      }

      // Se pisa la fila local con lo que devolvió el servidor, no con lo que
      // tipeó el usuario: así el margen que queda en pantalla es el que salió
      // de la base.
      Object.assign(prod, data.producto);
      input.value = campo === "proveedor"
        ? (prod.proveedor || "")
        : (prod[campo] === null ? "" : prod[campo]);

      fila.classList.toggle("cst-fila-sin-costo", prod.precio_costo === null);
      refrescarCalculosDeFila(fila);
      renderSubtitulo();
      renderResumen();
      marcar(input, "cst-ok");

      // Un proveedor recién creado tiene que quedar disponible en el datalist
      // del resto de las filas sin recargar la página.
      if (prod.proveedor && !proveedores.some(pr => pr.nombre === prod.proveedor)) {
        proveedores.push({ id: prod.proveedor_id, nombre: prod.proveedor });
        renderDatalist();
      }
    } catch (err) {
      marcar(input, "cst-error");
      alert(mensajeDeError(err));
    } finally {
      input.disabled = false;
    }
  }

  tbody.addEventListener("input", (e) => {
    const input = e.target.closest(".cst-input");
    if (!input || input.dataset.campo === "proveedor") return;
    refrescarCalculosDeFila(input.closest("[data-cst-row]"));
  });

  // "change" y no "blur": en un <input type=number> dispara igual al salir del
  // campo, pero no cuando se sale sin haber tocado nada.
  tbody.addEventListener("change", (e) => {
    const input = e.target.closest(".cst-input");
    if (!input) return;
    guardarCampo(input.closest("[data-cst-row]"), input);
  });

  // Enter guarda y salta al mismo campo de la fila siguiente: cargar costos es
  // una tarea de teclado, no de mouse.
  tbody.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    const input = e.target.closest(".cst-input");
    if (!input) return;
    e.preventDefault();
    const fila = input.closest("[data-cst-row]");
    const siguiente = fila.nextElementSibling;
    input.blur();
    if (siguiente) {
      const destino = siguiente.querySelector(`[data-campo="${input.dataset.campo}"]`);
      if (destino) destino.focus();
    }
  });

  buscador.addEventListener("input", renderTabla);

  botonToggle.addEventListener("click", () => {
    soloFaltantes = !soloFaltantes;
    botonToggle.classList.toggle("activo", soloFaltantes);
    botonToggle.textContent = soloFaltantes ? "Ver todos" : "Solo los que faltan";
    renderTabla();
  });

  async function cargar() {
    try {
      const [respRent, respProv] = await Promise.all([
        fetchApi(URL_COSTOS_RENTABILIDAD, {
          method: "POST",
          headers: { ...CST_HEADERS_NGROK, "Content-Type": "application/json" },
          body: JSON.stringify({ orden: "capital", limite: CST_LIMITE }),
        }),
        fetchApi(URL_COSTOS_PROVEEDORES, { method: "GET", headers: { ...CST_HEADERS_NGROK } }),
      ]);

      const data = await respRent.json();
      if (!respRent.ok || !data.ok) {
        subtitulo.textContent = "No se pudieron cargar los costos.";
        tbody.innerHTML = `<tr class="cst-empty-row"><td colspan="6">${data.mensaje || "Error al consultar la rentabilidad."}</td></tr>`;
        return;
      }

      // Los que no tienen costo van arriba: son la tarea pendiente. Dentro de
      // cada grupo, primero los de más stock (los que más plata representan).
      productos = (data.productos || []).sort((a, b) => {
        const faltaA = a.precio_costo === null ? 0 : 1;
        const faltaB = b.precio_costo === null ? 0 : 1;
        if (faltaA !== faltaB) return faltaA - faltaB;
        return b.stock_actual - a.stock_actual;
      });

      const dataProv = await respProv.json().catch(() => null);
      proveedores = (dataProv && dataProv.ok && dataProv.proveedores) || [];

      renderDatalist();
      renderSubtitulo();
      renderResumen();
      renderTabla();

      if (typeof opciones.onDatos === "function") opciones.onDatos(data);
    } catch (err) {
      subtitulo.textContent = mensajeDeError(err);
      tbody.innerHTML = `<tr class="cst-empty-row"><td colspan="6">Verificá que Apache y MySQL estén corriendo.</td></tr>`;
    }
  }

  if (botonPlegar) {
    plegableConectar({
      panel: panel,
      boton: botonPlegar,
      clave: CST_CLAVE_PLEGADO,
      clasePlegada: "cst-plegado",
      etiqueta: "costos",
    });
  }

  // Se carga SIEMPRE, aunque el panel arranque plegado: el resumen del pie
  // (capital inmovilizado, margen promedio, cuántos tienen costo cargado) es lo
  // único que queda a la vista plegado, y sale de este mismo fetch.
  cargar();

  return { recargar: cargar };
}
