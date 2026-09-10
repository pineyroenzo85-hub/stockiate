/**
 * stockIAte - preferencias.js
 * ================================
 * Sección "Preferencias del negocio" del panel de administrador: todo lo que
 * el dueño puede ajustar sin tocar código ni base. A qué número avisar, si los
 * avisos están activados, a qué hora sale el resumen, con cuántos días de
 * anticipación avisar un vencimiento, cada cuánto repetir el aviso de un mismo
 * producto, y con qué stock mínimo nacen los productos nuevos. Abajo, el
 * historial de lo último que se mandó.
 *
 * REEMPLAZA A notificaciones_config.js
 * ------------------------------------
 * Ese bloque configuraba sólo WhatsApp. Los umbrales (vencimiento, ventana de
 * avisos, stock mínimo) estaban escritos en el código y no había dónde
 * cambiarlos, así que al exponerlos la opción era sumar un segundo panel al
 * lado del primero -- dos cards, dos endpoints y dos formularios para lo que
 * el dueño vive como una sola cosa: "los ajustes de mi negocio".
 *
 * POR QUÉ EL HISTORIAL ESTÁ ACÁ
 * -----------------------------
 * "No me llega nada" tiene como cinco causas distintas y todas se ven igual
 * desde afuera: el negocio está desactivado, el teléfono está mal escrito, la
 * plantilla no está aprobada en Meta, el token venció, o el destinatario no
 * está en la lista de números de prueba. La única forma de distinguirlas es
 * mirar el error que devolvió Meta, y para eso hay que mostrarlo. Sin esta
 * tabla, el diagnóstico obliga a entrar a la base a mano.
 *
 * Lee de `consultar_preferencias.php` (los ajustes) y
 * `consultar_notificaciones.php` (el historial), y escribe con
 * `guardar_preferencias.php` y `probar_notificacion.php`. Los cuatro son
 * sólo-dueño.
 *
 * Uso:
 *   initPreferencias(document.getElementById("contenedor"));
 *
 * Clases con prefijo "prf-" por la misma razón que "cst-" en costos_tabla.js
 * y "invt-" en inventario_tabla.js: no chocar con styles.css ni con el
 * Tailwind de administrador.html.
 */

const URL_PRF_CONSULTAR = "consultar_preferencias.php";
const URL_PRF_GUARDAR = "guardar_preferencias.php";
const URL_PRF_HISTORIAL = "consultar_notificaciones.php";
const URL_PRF_PROBAR = "probar_notificacion.php";
// Sólo se usa en modo demo. Por HTTP, seed_demo.php exige sesión de dueño Y
// modo demo antes de tocar nada.
const URL_PRF_SEED = "seed_demo.php";

// Prefijo PRF_ porque este archivo convive en el scope global con
// inventario_tabla.js, costos_tabla.js y prediccion_tabla.js, que ya declaran
// los suyos.
const PRF_HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js

const PRF_ESTILOS_ID = "prf-estilos";
const PRF_CSS = `
.prf-panel{ background:var(--glass-bg); backdrop-filter:blur(20px) saturate(180%); -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid var(--glass-border); border-radius:22px; overflow:hidden; color:var(--blanco-puro); font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; margin-top:20px; box-shadow:var(--glass-shadow); }
.prf-panel-head{ padding:18px 20px; border-bottom:1px solid var(--divisor); }
.prf-panel-head h2{ font-size:15px; font-weight:800; margin:0; }
.prf-panel-head p{ font-size:12px; color:var(--gris-tenue); margin:2px 0 0; }
.prf-body{ padding:18px 20px; display:flex; flex-direction:column; gap:18px; }
.prf-grupo{ display:flex; flex-direction:column; gap:12px; }
.prf-grupo-titulo{ font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:var(--gris-tenue); font-weight:800; margin:0; }
.prf-campos{ display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start; }
.prf-campo{ display:flex; flex-direction:column; gap:6px; max-width:230px; }
.prf-campo label{ font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); font-weight:700; }
.prf-campo input[type=text], .prf-campo input[type=time], .prf-campo input[type=number]{ background:var(--superficie); box-shadow:var(--neu-inset); border:none; border-radius:10px; padding:9px 12px; color:var(--blanco-puro); font-size:13.5px; font-family:inherit; outline:none; transition:box-shadow .15s; width:100%; box-sizing:border-box; }
.prf-campo input[type=number]{ max-width:130px; }
.prf-campo input:focus{ box-shadow:var(--neu-inset), 0 0 0 2px var(--lila); }
.prf-campo input.prf-ok{ box-shadow:var(--neu-inset), 0 0 0 2px var(--ok-border); }
.prf-campo input.prf-error{ box-shadow:var(--neu-inset), 0 0 0 2px var(--error-border); }
.prf-campo input:disabled{ opacity:.6; }
.prf-hint{ font-size:11.5px; color:var(--gris-tenue); line-height:1.45; }
.prf-hint.prf-hint-ok{ color:var(--ok); }
.prf-hint.prf-hint-error{ color:var(--error); }
.prf-switch{ display:flex; align-items:center; gap:9px; cursor:pointer; user-select:none; font-size:13.5px; padding-top:20px; }
.prf-switch input{ appearance:none; width:38px; height:21px; border-radius:999px; background:var(--superficie); box-shadow:var(--neu-inset); position:relative; cursor:pointer; transition:background .15s; margin:0; flex:none; }
.prf-switch input::after{ content:""; position:absolute; top:3px; left:3px; width:15px; height:15px; border-radius:50%; background:var(--gris-tenue); transition:all .15s; }
.prf-switch input:checked{ background:var(--lila-tenue); }
.prf-switch input:checked::after{ left:20px; background:var(--lila-dark); }
.prf-switch input:disabled{ opacity:.6; cursor:default; }
.prf-btn{ background:var(--superficie); box-shadow:var(--neu-sombra-chica); border:none; color:var(--blanco-puro); border-radius:10px; padding:9px 14px; font-size:12.5px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; margin-top:18px; }
.prf-btn:hover:not(:disabled){ color:var(--lila-dark); }
.prf-btn:disabled{ opacity:.5; cursor:default; }
.prf-aviso{ border-radius:10px; padding:10px 13px; font-size:12.5px; line-height:1.5; }
.prf-aviso-warn{ background:var(--alerta-bg); border:1px solid rgba(232,196,104,0.4); color:var(--alerta); }
.prf-aviso-ok{ background:var(--ok-bg); border:1px solid var(--ok-border); color:var(--ok); }
.prf-aviso-error{ background:var(--error-bg); border:1px solid var(--error-border); color:var(--error); }
.prf-separador{ height:1px; background:var(--divisor); margin:0 -20px; }
.prf-tabla-wrap{ overflow-x:auto; border-top:1px solid var(--divisor); margin:0 -20px -18px; }
.prf-tabla{ width:100%; border-collapse:collapse; font-size:12.5px; }
.prf-tabla th{ text-align:left; padding:11px 20px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gris-tenue); border-bottom:1px solid var(--divisor); font-weight:700; white-space:nowrap; }
.prf-tabla td{ padding:9px 20px; border-bottom:1px solid var(--divisor); vertical-align:top; }
.prf-tabla tr:last-child td{ border-bottom:none; }
.prf-badge{ display:inline-block; padding:2px 9px; border-radius:999px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
.prf-badge-enviada{ background:var(--ok-bg); color:var(--ok); }
.prf-badge-pendiente{ background:var(--alerta-bg); color:var(--alerta); }
.prf-badge-fallida{ background:var(--error-bg); color:var(--error); }
.prf-error-celda{ color:var(--error); font-size:11.5px; max-width:320px; }
.prf-empty{ padding:18px 20px; color:var(--gris-tenue); font-size:12.5px; text-align:center; }
`;

function asegurarEstilosPreferencias() {
  if (document.getElementById(PRF_ESTILOS_ID)) return;
  const estilo = document.createElement("style");
  estilo.id = PRF_ESTILOS_ID;
  estilo.textContent = PRF_CSS;
  document.head.appendChild(estilo);
}

/** Escapa texto que viene de la base antes de meterlo en innerHTML. */
function prfEscapar(texto) {
  const div = document.createElement("div");
  div.textContent = texto === null || texto === undefined ? "" : String(texto);
  return div.innerHTML;
}

/** "2026-08-30 14:03:11" -> "30/08 14:03" */
function prfFecha(valor) {
  if (!valor) return "—";
  const partes = String(valor).split(/[- :]/);
  if (partes.length < 5) return valor;
  return `${partes[2]}/${partes[1]} ${partes[3]}:${partes[4]}`;
}

const PRF_ETIQUETA_TIPO = {
  stock_critico: "Stock crítico",
  sin_stock: "Sin stock",
  prediccion_quiebre: "Riesgo de quiebre",
  vencimientos: "Vencimientos",
  resumen_diario: "Resumen diario",
  prueba: "Prueba",
};

function initPreferencias(contenedor) {
  asegurarEstilosPreferencias();

  contenedor.innerHTML = `
    <div class="prf-panel">
      <div class="prf-panel-head">
        <h2>Preferencias del negocio</h2>
        <p>A quién avisar, cada cuánto, y con qué umbrales trabaja el sistema.</p>
      </div>
      <div class="prf-body">
        <div id="prfAvisoServidor"></div>

        <div class="prf-grupo">
          <p class="prf-grupo-titulo">Avisos por WhatsApp</p>
          <div class="prf-campos">
            <div class="prf-campo">
              <label for="prfTelefono">Teléfono</label>
              <input type="text" id="prfTelefono" placeholder="11 5555-4444" autocomplete="off">
              <span class="prf-hint" id="prfTelefonoHint">Con característica, sin el 0 y sin el 15.</span>
            </div>
            <div class="prf-campo">
              <label for="prfHora">Hora del resumen</label>
              <input type="time" id="prfHora">
              <span class="prf-hint">Una vez por día, al cierre.</span>
            </div>
            <label class="prf-switch">
              <input type="checkbox" id="prfActivo">
              <span>Activadas</span>
            </label>
            <button type="button" class="prf-btn" id="prfProbar">Mandar mensaje de prueba</button>
          </div>
          <div id="prfResultadoPrueba"></div>
        </div>

        <div class="prf-separador"></div>

        <div class="prf-grupo">
          <p class="prf-grupo-titulo">Umbrales</p>
          <div class="prf-campos">
            <div class="prf-campo">
              <label for="prfVencimiento">Vencimientos: días de aviso</label>
              <input type="number" id="prfVencimiento" min="1" max="365" step="1">
              <span class="prf-hint">Con cuánta anticipación avisar que un lote vence.</span>
            </div>
            <div class="prf-campo">
              <label for="prfVentana">Horas entre avisos repetidos</label>
              <input type="number" id="prfVentana" min="1" max="168" step="1">
              <span class="prf-hint">Si un producto queda en stock crítico, no se vuelve a avisar por él hasta que pasen estas horas.</span>
            </div>
            <div class="prf-campo">
              <label for="prfStockMinimo">Stock mínimo por defecto</label>
              <input type="number" id="prfStockMinimo" min="0" max="9999" step="1">
              <span class="prf-hint">Con qué mínimo nacen los productos nuevos. No cambia los que ya existen.</span>
            </div>
          </div>
        </div>

        <div class="prf-separador"></div>

        <div class="prf-grupo">
          <p class="prf-grupo-titulo">Reposición al proveedor</p>
          <div class="prf-campos">
            <div class="prf-campo">
              <label for="prfDiasEntrega">Días de entrega</label>
              <input type="number" id="prfDiasEntrega" min="1" max="120" step="1">
              <span class="prf-hint">Cuánto tarda el proveedor desde que le pedís hasta que llega.</span>
            </div>
            <div class="prf-campo">
              <label for="prfDiasObjetivo">Días de mercadería a pedir</label>
              <input type="number" id="prfDiasObjetivo" min="1" max="365" step="1">
              <span class="prf-hint">Para cuántos días de venta se pide. 30 = un mes.</span>
            </div>
            <div class="prf-campo">
              <label for="prfFactorSeguridad">Colchón de seguridad</label>
              <input type="number" id="prfFactorSeguridad" min="0" max="5" step="0.1">
              <span class="prf-hint">Extra sobre lo que se vende durante la entrega. 1.5 = pedí con un 50% de margen.</span>
            </div>
          </div>
        </div>

        <div id="prfBloqueDemo" hidden>
          <div class="prf-separador"></div>
          <div class="prf-grupo" style="margin-top:18px;">
            <p class="prf-grupo-titulo">Modo demostración</p>
            <div class="prf-aviso prf-aviso-warn">
              Estás trabajando contra <b>stockiate_demo</b>, una base aparte con datos
              inventados. Los datos reales del comercio no se tocan.
            </div>
            <div class="prf-campos">
              <button type="button" class="prf-btn" id="prfRegenerar">Regenerar datos de demo</button>
            </div>
            <span class="prf-hint">
              Borra y vuelve a sembrar la base de demo desde cero. Siempre genera lo mismo
              (es determinístico), así que sirve para dejarla como estaba después de una
              demostración. Te va a pedir que vuelvas a entrar: los usuarios se recrean.
            </span>
            <div id="prfResultadoDemo"></div>
          </div>
        </div>

        <div class="prf-tabla-wrap">
          <table class="prf-tabla">
            <thead>
              <tr><th>Cuándo</th><th>Aviso</th><th>Estado</th><th>Detalle</th></tr>
            </thead>
            <tbody id="prfTbody"></tbody>
          </table>
        </div>
      </div>
    </div>
  `;

  const inputTelefono = contenedor.querySelector("#prfTelefono");
  const inputHora = contenedor.querySelector("#prfHora");
  const inputActivo = contenedor.querySelector("#prfActivo");
  const inputVencimiento = contenedor.querySelector("#prfVencimiento");
  const inputVentana = contenedor.querySelector("#prfVentana");
  const inputStockMinimo = contenedor.querySelector("#prfStockMinimo");
  const inputDiasEntrega = contenedor.querySelector("#prfDiasEntrega");
  const inputDiasObjetivo = contenedor.querySelector("#prfDiasObjetivo");
  const inputFactorSeguridad = contenedor.querySelector("#prfFactorSeguridad");
  const botonProbar = contenedor.querySelector("#prfProbar");
  const hintTelefono = contenedor.querySelector("#prfTelefonoHint");
  const avisoServidor = contenedor.querySelector("#prfAvisoServidor");
  const resultadoPrueba = contenedor.querySelector("#prfResultadoPrueba");
  const tbody = contenedor.querySelector("#prfTbody");
  const bloqueDemo = contenedor.querySelector("#prfBloqueDemo");
  const botonRegenerar = contenedor.querySelector("#prfRegenerar");
  const resultadoDemo = contenedor.querySelector("#prfResultadoDemo");

  // `window.STOCKIATE_MODO_DEMO` lo pone auth.js con lo que contestó
  // sesion_actual.php. El botón es sólo comodidad: seed_demo.php rechaza por
  // su cuenta cualquier corrida que no apunte a la base de demo, así que
  // esconderlo no es lo que protege los datos reales.
  if (window.STOCKIATE_MODO_DEMO) {
    bloqueDemo.hidden = false;
  }

  // Últimas preferencias conocidas del servidor. Sirven para no gastar un
  // request cuando se sale de un campo sin haberlo cambiado, y para poder
  // repintar el valor bueno cuando el servidor rechaza uno.
  let prefs = {
    telefono: "",
    activo: false,
    hora_resumen: "20:00",
    umbral_dias_vencimiento: 30,
    ventana_notificaciones_horas: 24,
    stock_minimo_default: 5,
    reposicion_dias_entrega: 7,
    reposicion_dias_objetivo: 30,
    reposicion_factor_seguridad: 1.5,
  };

  function marcar(input, clase) {
    input.classList.remove("prf-ok", "prf-error");
    if (!clase) return;
    input.classList.add(clase);
    setTimeout(() => input.classList.remove(clase), 1200);
  }

  function pintarPreferencias(nuevas, servidorConfigurado) {
    prefs = nuevas;
    inputTelefono.value = nuevas.telefono || "";
    inputHora.value = nuevas.hora_resumen || "20:00";
    inputActivo.checked = !!nuevas.activo;
    inputVencimiento.value = nuevas.umbral_dias_vencimiento;
    inputVentana.value = nuevas.ventana_notificaciones_horas;
    inputStockMinimo.value = nuevas.stock_minimo_default;
    inputDiasEntrega.value = nuevas.reposicion_dias_entrega;
    inputDiasObjetivo.value = nuevas.reposicion_dias_objetivo;
    inputFactorSeguridad.value = nuevas.reposicion_factor_seguridad;

    if (!nuevas.telefono) {
      hintTelefono.className = "prf-hint";
      hintTelefono.textContent = "Con característica, sin el 0 y sin el 15.";
    } else if (nuevas.telefono_normalizado) {
      // Mostrar el número tal como se le va a mandar a Meta es la forma más
      // rápida de darse cuenta de que faltó un dígito o sobró el 15.
      hintTelefono.className = "prf-hint prf-hint-ok";
      hintTelefono.textContent = `Se envía a +${nuevas.telefono_normalizado}`;
    } else {
      hintTelefono.className = "prf-hint prf-hint-error";
      hintTelefono.textContent = "Ese número no se entiende.";
    }

    // Falta el `.env` del servidor: es un problema de instalación, no de
    // configuración del negocio, y se arregla en otro lado. Se avisa aparte
    // para no mandar al dueño a revisar su número cuando el número está bien.
    if (servidorConfigurado === false) {
      avisoServidor.className = "prf-aviso prf-aviso-warn";
      avisoServidor.textContent =
        "El servidor todavía no tiene las credenciales de WhatsApp cargadas en el archivo .env, " +
        "así que no va a salir ningún mensaje. Los avisos se siguen registrando igual.";
    } else {
      avisoServidor.className = "";
      avisoServidor.textContent = "";
    }
  }

  function pintarNotificaciones(lista) {
    if (!lista || lista.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="prf-empty">Todavía no se generó ningún aviso.</td></tr>`;
      return;
    }

    tbody.innerHTML = lista.map(n => {
      const cuando = n.enviada_en || n.creada_en;
      const detalle = n.error
        ? `<span class="prf-error-celda">${prfEscapar(n.error)}</span>`
        : prfEscapar(n.resumen);
      const intentos = n.intentos > 1 ? ` (${n.intentos} intentos)` : "";

      return `
        <tr>
          <td>${prfFecha(cuando)}</td>
          <td>${prfEscapar(PRF_ETIQUETA_TIPO[n.tipo] || n.tipo)}</td>
          <td><span class="prf-badge prf-badge-${prfEscapar(n.estado)}">${prfEscapar(n.estado)}</span>${intentos}</td>
          <td>${detalle}</td>
        </tr>
      `;
    }).join("");
  }

  /**
   * Guarda UN campo, no todos: mandar todo siempre haría que tocar el switch
   * pisara un teléfono que se está editando en otra pestaña. Mismo criterio
   * que costos_tabla.js; ver la nota de actualización parcial en
   * guardar_preferencias.php.
   */
  async function guardarCampo(input, campo, valor) {
    input.disabled = true;

    try {
      const resp = await fetchApi(URL_PRF_GUARDAR, {
        method: "POST",
        headers: { ...PRF_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({ [campo]: valor }),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        marcar(input, "prf-error");
        alert(data.mensaje || "No se pudo guardar el cambio.");
        // Se vuelve a lo que hay en el servidor: si el valor se rechazó,
        // dejarlo en pantalla haría creer que quedó guardado.
        pintarPreferencias(prefs, undefined);
        return;
      }

      pintarPreferencias(data.preferencias, data.servidor_configurado);
      marcar(input, "prf-ok");
    } catch (err) {
      marcar(input, "prf-error");
      alert(mensajeDeError(err));
      pintarPreferencias(prefs, undefined);
    } finally {
      input.disabled = false;
    }
  }

  /**
   * Los tres umbrales se guardan igual: si el campo quedó vacío o con algo que
   * no es número, se repinta el valor del servidor en vez de mandar basura
   * (el servidor la rechazaría igual, pero con un alert al pedo).
   */
  function guardarNumero(input, campo) {
    const valor = parseInt(input.value, 10);

    if (!Number.isFinite(valor)) {
      pintarPreferencias(prefs, undefined);
      return;
    }

    if (valor === prefs[campo]) return;

    guardarCampo(input, campo, valor);
  }

  // "change" y no "blur": dispara al salir del campo, pero no cuando se sale
  // sin haber tocado nada.
  inputTelefono.addEventListener("change", () => {
    const valor = inputTelefono.value.trim();
    if (valor === (prefs.telefono || "")) return;
    guardarCampo(inputTelefono, "telefono", valor);
  });

  inputHora.addEventListener("change", () => {
    const valor = inputHora.value;
    if (!valor || valor === prefs.hora_resumen) return;
    guardarCampo(inputHora, "hora_resumen", valor);
  });

  inputActivo.addEventListener("change", () => {
    guardarCampo(inputActivo, "activo", inputActivo.checked);
  });

  inputVencimiento.addEventListener("change", () => {
    guardarNumero(inputVencimiento, "umbral_dias_vencimiento");
  });

  inputVentana.addEventListener("change", () => {
    guardarNumero(inputVentana, "ventana_notificaciones_horas");
  });

  inputStockMinimo.addEventListener("change", () => {
    guardarNumero(inputStockMinimo, "stock_minimo_default");
  });

  inputDiasEntrega.addEventListener("change", () => {
    guardarNumero(inputDiasEntrega, "reposicion_dias_entrega");
  });

  inputDiasObjetivo.addEventListener("change", () => {
    guardarNumero(inputDiasObjetivo, "reposicion_dias_objetivo");
  });

  // Va por guardarCampo y no por guardarNumero: es el único decimal del
  // panel, y guardarNumero parsea con parseInt (ver su cuerpo), así que un
  // 1.5 se guardaría como 1 sin que nada avise.
  inputFactorSeguridad.addEventListener("change", () => {
    const valor = parseFloat(inputFactorSeguridad.value);
    if (Number.isNaN(valor) || valor === prefs.reposicion_factor_seguridad) return;
    guardarCampo(inputFactorSeguridad, "reposicion_factor_seguridad", valor);
  });

  botonProbar.addEventListener("click", async () => {
    botonProbar.disabled = true;
    const textoOriginal = botonProbar.textContent;
    botonProbar.textContent = "Enviando...";
    resultadoPrueba.className = "";
    resultadoPrueba.textContent = "";

    try {
      const resp = await fetchApi(URL_PRF_PROBAR, {
        method: "POST",
        headers: { ...PRF_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      resultadoPrueba.className = data.ok ? "prf-aviso prf-aviso-ok" : "prf-aviso prf-aviso-error";
      resultadoPrueba.textContent = data.mensaje || (data.ok ? "Mensaje enviado." : "No se pudo enviar.");

      // La prueba deja una fila en el historial (salga o falle), así que
      // recargamos para que se vea ahí abajo con su resultado.
      cargarHistorial();
    } catch (err) {
      resultadoPrueba.className = "prf-aviso prf-aviso-error";
      resultadoPrueba.textContent = mensajeDeError(err);
    } finally {
      botonProbar.disabled = false;
      botonProbar.textContent = textoOriginal;
    }
  });

  botonRegenerar.addEventListener("click", async () => {
    const seguro = window.confirm(
      "Se borra la base de demo entera y se genera de nuevo.\n\n" +
      "Los datos reales del comercio NO se tocan (están en otra base), pero vas a " +
      "tener que volver a iniciar sesión.\n\n¿Seguimos?"
    );
    if (!seguro) return;

    botonRegenerar.disabled = true;
    const textoOriginal = botonRegenerar.textContent;
    botonRegenerar.textContent = "Regenerando...";
    resultadoDemo.className = "";
    resultadoDemo.textContent = "";

    try {
      const resp = await fetchApi(URL_PRF_SEED, {
        method: "POST",
        headers: { ...PRF_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        resultadoDemo.className = "prf-aviso prf-aviso-error";
        resultadoDemo.textContent = data.mensaje || "No se pudieron regenerar los datos.";
        return;
      }

      // El usuario logueado ya no existe (se recreó la tabla `usuarios`), así
      // que no tiene sentido dejar la pantalla mostrando datos viejos: los
      // fetch siguientes van a devolver 401 igual.
      resultadoDemo.className = "prf-aviso prf-aviso-ok";
      resultadoDemo.textContent = "Datos regenerados. Volviendo al login...";
      setTimeout(() => { olvidarUsuarioSesion(); window.location.href = "login.html"; }, 1500);
    } catch (err) {
      resultadoDemo.className = "prf-aviso prf-aviso-error";
      resultadoDemo.textContent = mensajeDeError(err);
    } finally {
      botonRegenerar.disabled = false;
      botonRegenerar.textContent = textoOriginal;
    }
  });

  /**
   * Los ajustes y el historial se piden por separado a propósito: son dos
   * endpoints distintos y uno puede fallar sin el otro. Si se cayera el
   * historial, el formulario tiene que seguir siendo usable igual.
   */
  async function cargarPreferencias() {
    try {
      const resp = await fetchApi(URL_PRF_CONSULTAR, {
        method: "POST",
        headers: { ...PRF_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        avisoServidor.className = "prf-aviso prf-aviso-error";
        avisoServidor.textContent = data.mensaje || "No se pudieron cargar las preferencias.";
        return;
      }

      pintarPreferencias(data.preferencias, data.servidor_configurado);
    } catch (err) {
      avisoServidor.className = "prf-aviso prf-aviso-error";
      avisoServidor.textContent = mensajeDeError(err);
    }
  }

  async function cargarHistorial() {
    try {
      const resp = await fetchApi(URL_PRF_HISTORIAL, {
        method: "POST",
        headers: { ...PRF_HEADERS_NGROK, "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await resp.json();

      if (!resp.ok || !data.ok) {
        tbody.innerHTML = `<tr><td colspan="4" class="prf-empty">${prfEscapar(data.mensaje || "No se pudo cargar el historial de avisos.")}</td></tr>`;
        return;
      }

      pintarNotificaciones(data.notificaciones);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="4" class="prf-empty">${prfEscapar(mensajeDeError(err))}</td></tr>`;
    }
  }

  function cargar() {
    cargarPreferencias();
    cargarHistorial();
  }

  cargar();

  return { recargar: cargar };
}
