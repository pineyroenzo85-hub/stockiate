/**
 * stockIAte - Cajita (la mascota) -- personaje y guía visual del asistente
 * =====================================================================
 * Cajita y el chatbot son UN solo asistente. Este archivo es el personaje y
 * todo lo que es "guía visual" (tour de bienvenida con spotlight, modo 🎯 de
 * señalar un panel, burbuja de avisos, reacciones a la captura por IA). La
 * conversación vive en chatbot_widget.js, que es dueño del botón flotante y
 * del panel de chat: Cajita ES ese botón.
 *
 * Contrato (window.Cajita), lo usa chatbot_widget.js:
 *
 *   Cajita.montar({ raiz, boton, rol, pagina, panelAbierto, cerrarPanel, alCambiarMontaje })
 *       Se engancha al botón del chat y lo reemplaza por el personaje. Si la
 *       mascota está oculta en Ajustes no hace nada y el botón queda como 🤖:
 *       el chat nunca queda inalcanzable.
 *   Cajita.onPanelAbierto()   -- el panel se abrió: se apaga el 🎯 y la burbuja.
 *   Cajita.tourActivo()       -- mientras dura el tour el botón no abre el chat.
 *   Cajita.setEstado(e)       -- idle|pensando|exito|error|explicando|buscando.
 *   Cajita.alternarSenalar()  -- prende/apaga el 🎯; devuelve si quedó prendido.
 *   Cajita.alSenalar(cb)      -- cb({id, titulo, texto}) al tocar un panel.
 *   Cajita.respuestaLocal(texto, seccion)
 *       Promesa con {respuesta, estado} o null. Es el RESPALDO cuando /chatbot
 *       no responde (sin cupo en Groq, servicio caído): el FAQ por palabras
 *       clave de ayuda.json. Con la IA andando no se usa.
 *   Cajita.silenciada() / Cajita.alternarSilencio()
 *
 * Los textos del 🎯 y del FAQ salen de ayuda.json, que TAMBIÉN lee
 * chatbot_ia.py para armar la guía de uso del modelo: una sola fuente. El
 * guion del tour (GUION_TOUR) se queda acá porque es UI pura (selectores,
 * estados, orden de pasos).
 *
 * Se carga desde insertarChatbotWidget() en auth.js (siempre ANTES que
 * chatbot_widget.js). ajustes.html lo carga a mano, antes, para conectar su
 * panel; auth.js detecta que ya está y no lo duplica.
 */
(function () {
  "use strict";

  var TAMANOS = { chico: 60, mediano: 84, grande: 112 };
  var VELOCIDAD_MS = 32; // ms por letra al "tipear" la burbuja
  var MUTE_MINUTOS = 20;
  // Una burbuja que no es del tour se retira sola: ahora vive pegada al botón
  // del chat, en la zona de los botones de acción, y no puede quedar tapando.
  var AUTOCIERRE_MS = 9000;

  // ---------------- Guion del tour de bienvenida (por página) ----------------
  // selector:null => paso "flotante" sin spotlight (presentación).
  // enLanzador:true => el foco es el propio botón de Cajita, en su esquina.

  var GUION_TOUR = {
    repositor: [
      { estado: "idle", titulo: "¡Bienvenido!", selector: null,
        texto: "¡Hola! Soy Cajita, tu asistente de stockIAte. Veo que entraste en el Modo Repositor. ¡Acá es donde ocurre la magia en la góndola! ¿Listo para empezar?" },
      { estado: "explicando", titulo: "Sacá la foto", selector: "#zona-captura",
        texto: "Acomodá los productos, abrí la cámara desde acá y sacale una foto a la góndola. Nuestra IA (Roboflow) va a contar todos los productos en simultáneo en solo 2 segundos." },
      { estado: "exito", titulo: "Red de Seguridad", selector: "#pantalla-validacion",
        texto: "¡Excelente! Ahora viene mi parte favorita: la Red de Seguridad. Revisá rápido esta lista para confirmar el conteo. Si hay algún error, lo ajustás con un toque antes de guardar. ¡Y listo, stock actualizado!" },
      { estado: "explicando", titulo: "Preguntame lo que quieras", selector: null, enLanzador: true,
        texto: "Y si en algún momento tenés una duda —cómo se usa algo, cuánto stock queda, lo que sea— tocame acá abajo. Te contesto con los datos de tu negocio." },
    ],
    admin: [
      { estado: "idle", titulo: "Centro de mando", selector: null,
        texto: "¡Bienvenido al centro de mando! Soy Cajita. Esta es tu vista de Administrador, diseñada para que tengas el control total de tu negocio en un solo vistazo." },
      { estado: "explicando", titulo: "Tus KPIs", selector: "#kpiGrid",
        texto: "Acá arriba tenés tus KPIs principales: podés ver tu stock total, la eficiencia del escáner IA, y prestá atención a las alertas en rojo que te avisan sobre los quiebres de stock." },
      { estado: "buscando", titulo: "Buscar un producto", selector: null,
        texto: "¿Buscás un producto puntual? Tanto el Inventario como Costos y márgenes tienen su propio buscador arriba de la tabla — no hace falta scrollear toda la lista. Y si querés que te explique un panel, abrime y tocá 🎯." },
      { estado: "explicando", titulo: "Preguntame lo que quieras", selector: null, enLanzador: true,
        texto: "Para preguntas más elaboradas —márgenes, rotación, qué reponer— o para saber cómo se usa una sección, tocame acá abajo. Tengo acceso a los datos reales de tu negocio y respondo con eso, no con inventos." },
    ],
    cajero: [
      { estado: "idle", titulo: "Modo Caja", selector: null,
        texto: "¡Modo Caja activado! Soy Cajita. Acá la prioridad es que atiendas rápido a tus clientes." },
      { estado: "explicando", titulo: "Registrá la venta", selector: "#zona-captura",
        texto: "Usá nuestro buscador rápido (o el escáner), o sacale una foto a lo que se está vendiendo y la IA lo identifica." },
      { estado: "exito", titulo: "Revisá antes de cobrar", selector: "#pantalla-validacion",
        texto: "Antes de cobrar confirmá el ticket: producto, cantidad y precio. Al confirmar se descuenta solo de la base de datos para que el inventario siempre esté al día." },
      { estado: "explicando", titulo: "Preguntame lo que quieras", selector: null, enLanzador: true,
        texto: "Cualquier duda rápida, tocame acá abajo — te contesto con IA sobre stock y precios reales, o te explico cómo se usa esta pantalla." },
    ],
  };

  // Mensaje corto para cuando ya se vio el tour completo de este rol: se
  // habla igual (no queda muda para siempre), pero sin repetir el monólogo.
  var REVISITA = {
    repositor: "¡Hola de nuevo! Sacá la foto y yo me encargo del resto. Tocame si necesitás algo.",
    admin: "Panel de Administrador: tus KPIs y alertas de siempre, acá arriba. Tocame y preguntame por tu negocio o por cómo usar una sección.",
    cajero: "Modo Caja: buscá, escaneá o sacá una foto para descontar del stock al vender. Tocame si tenés una duda.",
  };

  // ---------------- Configuración (localStorage, MascotaSettings) ----------------
  // Mínima a propósito: se puede ocultar y se puede cambiar el tamaño. El
  // resto de lo que antes era configurable (posición, velocidad, colores de la
  // burbuja) se fue junto con la burbuja-conversación.

  var CLAVE_CONFIG = "stockiate_mascota_config";
  var CONFIG_DEFAULT = { visible: true, tamano: "mediano" };

  function leerConfig() {
    var copia = {};
    for (var k in CONFIG_DEFAULT) copia[k] = CONFIG_DEFAULT[k];
    try {
      var raw = localStorage.getItem(CLAVE_CONFIG);
      if (!raw) return copia;
      var guardado = JSON.parse(raw);
      // Solo las claves que todavía existen: una config vieja con "posicion",
      // "colorAcento", etc. se ignora en vez de arrastrar basura.
      for (var k2 in CONFIG_DEFAULT) {
        if (guardado && guardado[k2] != null) copia[k2] = guardado[k2];
      }
      return copia;
    } catch (e) {
      return copia;
    }
  }

  function guardarConfig(parcial) {
    var actual = leerConfig();
    for (var k in parcial) actual[k] = parcial[k];
    try {
      localStorage.setItem(CLAVE_CONFIG, JSON.stringify(actual));
    } catch (e) {
      // Sin persistencia se pierde la preferencia entre cargas, pero el
      // widget sigue andando dentro de esta sesión.
    }
    aplicarConfigAlWidget(actual);
    return actual;
  }

  window.MascotaSettings = {
    leer: leerConfig,
    guardar: guardarConfig,
  };

  // ---------------- Tour visto (por rol) ----------------

  function claveTourVisto(r) {
    return "stockiate_tour_" + r + "_visto";
  }
  function tourYaVisto(r) {
    try {
      return localStorage.getItem(claveTourVisto(r)) === "1";
    } catch (e) {
      return false;
    }
  }
  function marcarTourVisto(r) {
    try {
      localStorage.setItem(claveTourVisto(r), "1");
    } catch (e) {}
  }

  // ---------------- Silenciar un rato ----------------

  var CLAVE_MUTE = "stockiate_mascota_mute_hasta";
  function leerMuteHasta() {
    try {
      return Number(localStorage.getItem(CLAVE_MUTE) || 0);
    } catch (e) {
      return 0;
    }
  }
  function guardarMuteHasta(ts) {
    try {
      localStorage.setItem(CLAVE_MUTE, String(ts));
    } catch (e) {}
  }
  function estaMuteada() {
    return Date.now() < leerMuteHasta();
  }

  // ---------------- Contenido de ayuda (ayuda.json) ----------------

  var ayuda = null;
  var ayudaPromesa = null;

  function cargarAyuda() {
    if (!ayudaPromesa) {
      ayudaPromesa = fetch("ayuda.json", { cache: "no-cache" })
        .then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          return r.json();
        })
        .then(function (d) {
          ayuda = d;
          return d;
        })
        .catch(function (e) {
          // Sin el JSON no hay 🎯 ni respaldo, pero el chat anda igual. Se
          // suelta la promesa para reintentar la próxima vez que se necesite.
          console.warn("[Cajita] no se pudo cargar ayuda.json:", e);
          ayudaPromesa = null;
          return { senalables: {}, faq: [] };
        });
    }
    return ayudaPromesa;
  }

  // ---------------- Estilos ----------------

  function inyectarEstilos() {
    if (document.getElementById("mtour-estilos")) return;
    var style = document.createElement("style");
    style.id = "mtour-estilos";
    style.textContent = [
      /* -------- tokens: reusa el tema real del proyecto -------- */
      /* En :root y no en .mtour-raiz: el modo señalar pinta elementos de la
         página (fuera de la raíz del chat) y necesitan --mtour-accent. */
      ":root{--mtour-cardboard:#C89B6B;--mtour-cardboard-dark:#A6784A;--mtour-cardboard-line:#8C6238;--mtour-ink:#211C17;--mtour-shoe:#E8702A;--mtour-label:#F6EEDF;--mtour-accent:var(--azul-acento,#7C3AED);--mtour-bg:var(--glass-bg, rgba(255,255,255,.85));--mtour-text:var(--crema-clean,#2E2A55);--mtour-text-dim:var(--gris-tenue,#5F5C80);--mtour-border:var(--glass-border, rgba(124,58,237,.2));}",

      /* -------- la raíz del chat, ahora con Cajita adentro -------- */
      /* Columna: la burbuja va en el flujo, arriba del botón. El panel del
         chat es absolute (bottom:100%) y no entra en la cuenta. */
      ".siate-chat-widget.mtour-raiz{display:flex;flex-direction:column;align-items:flex-end;}",
      ".siate-chat-widget.mtour-en-tour{z-index:1000001;}",
      ".siate-chat-widget.mtour-flotante{right:auto!important;bottom:auto!important;left:var(--mtour-fx,16px);top:var(--mtour-fy,16px);z-index:1000001;align-items:flex-start;transition:top .45s cubic-bezier(.4,0,.2,1),left .45s cubic-bezier(.4,0,.2,1);}",

      /* -------- el botón del chat, convertido en Cajita -------- */
      ".siate-chat-boton.siate-chat-boton--cajita{width:auto;height:auto;border-radius:14px;background:none;box-shadow:none;padding:0;font-size:inherit;}",
      ".siate-chat-boton--cajita:focus-visible{outline:2px solid var(--mtour-accent);outline-offset:3px;}",

      /* -------- burbuja -------- */
      /* display:none cuando no se ve: en el flujo, una burbuja invisible
         empujaría el botón hacia arriba. */
      ".mtour-burbuja{display:none;max-width:min(78vw,300px);background:var(--mtour-bg);color:var(--mtour-text);border:1px solid var(--mtour-border);border-radius:16px;padding:12px 14px;font-size:15px;line-height:1.4;box-shadow:var(--glass-shadow, 0 8px 24px rgba(30,20,10,.18));margin-bottom:10px;backdrop-filter:blur(16px) saturate(160%);-webkit-backdrop-filter:blur(16px) saturate(160%);}",
      ".mtour-burbuja.mtour-visible{display:block;animation:mtour-burbuja-entra .18s ease both;}",
      "@keyframes mtour-burbuja-entra{from{opacity:0;transform:translateY(6px) scale(.97);}to{opacity:1;transform:none;}}",
      ".mtour-burbuja .mtour-cursor{opacity:.5;}",
      ".mtour-burbuja-controles{display:none;align-items:center;justify-content:space-between;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--mtour-border);}",
      ".mtour-burbuja.mtour-tour .mtour-burbuja-controles{display:flex;}",
      ".mtour-burbuja-puntos{display:flex;gap:4px;}",
      ".mtour-burbuja-puntos span{width:6px;height:6px;border-radius:50%;background:var(--mtour-border);}",
      ".mtour-burbuja-puntos span.mtour-activo{background:var(--mtour-accent);}",
      ".mtour-burbuja-botones{display:flex;gap:6px;}",
      ".mtour-burbuja-botones button{border:none;background:var(--mtour-accent);color:#fff;border-radius:999px;padding:5px 12px;font-size:.78rem;cursor:pointer;}",
      ".mtour-burbuja-botones button.mtour-saltar{background:transparent;color:var(--mtour-text-dim);padding:5px 6px;}",

      /* -------- mascota SVG -------- */
      ".mtour-caja-mascota{cursor:pointer;width:var(--mtour-tamano,84px);filter:drop-shadow(0 6px 10px rgba(30,20,10,.28));}",
      ".mtour-caja-mascota svg{display:block;width:100%;height:auto;}",
      ".mtour-grp-cuerpo{transform-origin:150px 230px;animation:mtour-respirar 3.4s ease-in-out infinite;}",
      "@keyframes mtour-respirar{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-3px) scale(1.015);}}",
      ".mtour-parpado{transform-origin:center;animation:mtour-parpadeo 4.2s ease-in-out infinite;}",
      "@keyframes mtour-parpadeo{0%,92%,100%{transform:scaleY(.02);}94%,98%{transform:scaleY(1);}}",
      ".mtour-brazo-izq,.mtour-brazo-der{transform-origin:150px 190px;transition:transform .35s cubic-bezier(.34,1.56,.64,1);}",
      ".mtour-engranaje{transform-origin:222px 150px;opacity:0;transition:opacity .2s ease;}",
      ".mtour-mascota--pensando .mtour-engranaje{opacity:1;animation:mtour-girar 1.6s linear infinite;}",
      "@keyframes mtour-girar{to{transform:rotate(360deg);}}",
      ".mtour-mascota--pensando .mtour-brazo-izq{transform:rotate(8deg);}",
      ".mtour-mascota--pensando .mtour-brazo-der{transform:rotate(-6deg);}",
      ".mtour-mascota--exito .mtour-grp-cuerpo{animation:mtour-salto .55s cubic-bezier(.34,1.56,.64,1) 2;}",
      "@keyframes mtour-salto{0%{transform:translateY(0);}40%{transform:translateY(-16px);}100%{transform:translateY(0);}}",
      ".mtour-mascota--exito .mtour-brazo-der{transform:rotate(-135deg);}",
      ".mtour-mascota--exito .mtour-brazo-izq{transform:rotate(18deg);}",
      ".mtour-mascota--error .mtour-grp-cuerpo{animation:mtour-negar .5s ease-in-out 2;}",
      "@keyframes mtour-negar{0%,100%{transform:rotate(0);}25%{transform:rotate(-4deg);}75%{transform:rotate(4deg);}}",
      ".mtour-mascota--error .mtour-brazo-izq{transform:rotate(35deg) translateY(6px);}",
      ".mtour-mascota--error .mtour-brazo-der{transform:rotate(-35deg) translateY(6px);}",
      ".mtour-mascota--explicando .mtour-brazo-der,.mtour-mascota--buscando .mtour-brazo-der{transform:rotate(-70deg) translateX(6px);}",
      ".mtour-mascota--explicando .mtour-grp-cuerpo,.mtour-mascota--buscando .mtour-grp-cuerpo{animation:mtour-inclinar 1.8s ease-in-out infinite;}",
      "@keyframes mtour-inclinar{0%,100%{transform:rotate(0);}50%{transform:rotate(-2deg);}}",

      /* -------- accesibilidad: menos movimiento -------- */
      "@media (prefers-reduced-motion: reduce){.mtour-grp-cuerpo,.mtour-parpado,.mtour-engranaje,.mtour-burbuja{animation:none!important;}.siate-chat-widget.mtour-flotante,#mtour-hueco,.mtour-brazo-izq,.mtour-brazo-der{transition:none!important;}}",
      ".mtour-senalable:focus-visible{outline:2px solid var(--mtour-accent);outline-offset:3px;}",

      /* -------- modo señalar -------- */
      "body.mtour-modo-senalar .mtour-senalable{cursor:crosshair;}",
      "body.mtour-modo-senalar .mtour-senalable:hover,body.mtour-modo-senalar .mtour-senalable:focus-visible{box-shadow:0 0 0 2px var(--mtour-accent);border-radius:10px;}",

      /* -------- spotlight del tour -------- */
      "#mtour-hueco{position:fixed;z-index:1000000;pointer-events:none;transition:all .4s cubic-bezier(.4,0,.2,1);box-shadow:0 0 0 2000px rgba(18,14,9,.62);border-radius:14px;opacity:0;}",
      "#mtour-hueco.mtour-visible{opacity:1;}",
      "#mtour-backdrop{position:fixed;inset:0;z-index:999996;display:none;}",
      "body.mtour-tour-activo #mtour-backdrop{display:block;}",
    ].join("\n");
    document.head.appendChild(style);
  }

  // ---------------- SVG de la mascota ----------------

  var SVG_NS = "http://www.w3.org/2000/svg";

  // Sin role/tabindex a propósito: va adentro del <button> del chat, que es el
  // control accesible. Un role=button anidado en otro button es inválido.
  function crearMascotaSVG() {
    var cont = document.createElement("div");
    cont.className = "mtour-caja-mascota mtour-mascota--idle";
    cont.innerHTML =
      '<svg viewBox="0 0 300 320" xmlns="' + SVG_NS + '" aria-hidden="true">' +
      '<g class="mtour-grp-cuerpo">' +
      '<line class="mtour-pierna-izq" x1="138" y1="230" x2="128" y2="270" stroke="var(--mtour-ink)" stroke-width="6" stroke-linecap="round"/>' +
      '<line class="mtour-pierna-der" x1="162" y1="230" x2="172" y2="270" stroke="var(--mtour-ink)" stroke-width="6" stroke-linecap="round"/>' +
      '<ellipse cx="122" cy="276" rx="16" ry="9" fill="var(--mtour-shoe)"/>' +
      '<ellipse cx="178" cy="276" rx="16" ry="9" fill="var(--mtour-shoe)"/>' +
      '<line class="mtour-brazo-izq" x1="105" y1="190" x2="78" y2="220" stroke="var(--mtour-ink)" stroke-width="6" stroke-linecap="round"/>' +
      '<line class="mtour-brazo-der" x1="195" y1="190" x2="222" y2="220" stroke="var(--mtour-ink)" stroke-width="6" stroke-linecap="round"/>' +
      '<rect x="90" y="110" width="120" height="120" rx="14" fill="var(--mtour-cardboard)" stroke="var(--mtour-cardboard-line)" stroke-width="2"/>' +
      '<path d="M90 128 L150 148 L210 128" fill="none" stroke="var(--mtour-cardboard-dark)" stroke-width="2" opacity=".6"/>' +
      '<path d="M90 116 L150 100 L210 116" fill="none" stroke="var(--mtour-cardboard-dark)" stroke-width="2" opacity=".5"/>' +
      '<line x1="150" y1="110" x2="150" y2="230" stroke="var(--mtour-cardboard-dark)" stroke-width="1.5" opacity=".35"/>' +
      '<rect x="112" y="150" width="76" height="34" rx="4" fill="var(--mtour-label)" stroke="var(--mtour-cardboard-line)" stroke-width="1.5"/>' +
      '<text x="150" y="172" text-anchor="middle" font-size="12" font-weight="700" fill="var(--mtour-ink)" font-family="inherit">stockIAte</text>' +
      '<g><circle cx="122" cy="132" r="7" fill="var(--mtour-ink)"/><rect class="mtour-parpado" x="114" y="123" width="16" height="18" fill="var(--mtour-cardboard)"/></g>' +
      '<g><circle cx="178" cy="132" r="7" fill="var(--mtour-ink)"/><rect class="mtour-parpado" x="170" y="123" width="16" height="18" fill="var(--mtour-cardboard)"/></g>' +
      '<path d="M 136 143 Q 150 152 164 143" fill="none" stroke="var(--mtour-ink)" stroke-width="4" stroke-linecap="round"/>' +
      '</g>' +
      '<g class="mtour-engranaje">' +
      '<circle cx="222" cy="150" r="12" fill="none" stroke="var(--mtour-text-dim)" stroke-width="4"/>' +
      '<circle cx="222" cy="150" r="4" fill="var(--mtour-text-dim)"/>' +
      '<line x1="222" y1="134" x2="222" y2="140" stroke="var(--mtour-text-dim)" stroke-width="4" stroke-linecap="round"/>' +
      '<line x1="222" y1="160" x2="222" y2="166" stroke="var(--mtour-text-dim)" stroke-width="4" stroke-linecap="round"/>' +
      '<line x1="206" y1="150" x2="212" y2="150" stroke="var(--mtour-text-dim)" stroke-width="4" stroke-linecap="round"/>' +
      '<line x1="232" y1="150" x2="238" y2="150" stroke="var(--mtour-text-dim)" stroke-width="4" stroke-linecap="round"/>' +
      '</g>' +
      '</svg>';
    return cont;
  }

  var ESTADOS = ["idle", "pensando", "exito", "error", "explicando", "buscando"];
  function ponerEstado(el, estado) {
    if (!el) return;
    for (var i = 0; i < ESTADOS.length; i++) el.classList.remove("mtour-mascota--" + ESTADOS[i]);
    el.classList.add("mtour-mascota--" + (estado || "idle"));
  }

  // ---------------- Montaje sobre el botón del chat ----------------

  var opciones = null; // lo que pasó chatbot_widget.js en montar()
  var raiz, boton, contenidoOriginal;
  var rol = "";
  var pagina = "";
  var montado = false;

  var mascotaEl, burbuja, burbujaTexto, btnSiguienteTour;
  var tokenEscritura = 0;
  var volverAIdleTimer = null;
  var autocierreTimer = null;

  function panelAbierto() {
    return !!(opciones && opciones.panelAbierto && opciones.panelAbierto());
  }

  function montar(opts) {
    opciones = opts;
    raiz = opts.raiz;
    boton = opts.boton;
    rol = opts.rol || "";
    pagina = opts.pagina || "";
    contenidoOriginal = boton.textContent;

    inyectarEstilos();
    cargarAyuda(); // en segundo plano: el 🎯 y el respaldo lo van a necesitar
    aplicarTamano(leerConfig());

    if (leerConfig().visible) {
      acoplar();
      programarArranque();
    }
    return montado;
  }

  function acoplar() {
    if (montado || !boton) return;
    montado = true;

    mascotaEl = crearMascotaSVG();
    boton.textContent = "";
    boton.appendChild(mascotaEl);
    boton.classList.add("siate-chat-boton--cajita");
    boton.setAttribute("aria-label", "Abrir el asistente Cajita");
    raiz.classList.add("mtour-raiz");

    burbuja = crearBurbuja();
    raiz.insertBefore(burbuja, boton);
    montarOverlayTour();

    if (opciones.alCambiarMontaje) opciones.alCambiarMontaje(true);
  }

  // Inverso de acoplar(): se usa al apagar "Mostrar a Cajita" en Ajustes. El
  // botón vuelve a ser el 🤖 de siempre.
  function desacoplar() {
    if (!montado) return;
    if (tourActivo) cerrarTour();
    activarModoSenalar(false);
    ocultarBurbuja();
    if (burbuja && burbuja.parentNode) burbuja.parentNode.removeChild(burbuja);
    boton.textContent = contenidoOriginal;
    boton.classList.remove("siate-chat-boton--cajita");
    boton.setAttribute("aria-label", "Abrir asistente IA");
    raiz.classList.remove("mtour-raiz", "mtour-flotante", "mtour-en-tour");
    mascotaEl = null;
    burbuja = null;
    montado = false;

    if (opciones.alCambiarMontaje) opciones.alCambiarMontaje(false);
  }

  function aplicarTamano(cfg) {
    document.documentElement.style.setProperty("--mtour-tamano", (TAMANOS[cfg.tamano] || TAMANOS.mediano) + "px");
  }

  function aplicarConfigAlWidget(cfg) {
    aplicarTamano(cfg);
    if (!opciones) return; // todavía no se montó nada (p. ej. en ajustes.html antes del chat)
    if (cfg.visible) acoplar();
    else desacoplar();
  }

  // ---------------- Burbuja ----------------

  function crearBurbuja() {
    var b = document.createElement("div");
    b.className = "mtour-burbuja";
    b.id = "mtour-burbuja";
    b.setAttribute("aria-live", "polite");
    b.setAttribute("aria-atomic", "true");

    burbujaTexto = document.createElement("span");
    var cursor = document.createElement("span");
    cursor.className = "mtour-cursor";
    cursor.textContent = "▌";
    b.appendChild(burbujaTexto);
    b.appendChild(cursor);

    var controles = document.createElement("div");
    controles.className = "mtour-burbuja-controles";
    var puntos = document.createElement("div");
    puntos.className = "mtour-burbuja-puntos";
    puntos.id = "mtour-puntos";
    var botones = document.createElement("div");
    botones.className = "mtour-burbuja-botones";
    var btnSaltarTour = document.createElement("button");
    btnSaltarTour.type = "button";
    btnSaltarTour.className = "mtour-saltar";
    btnSaltarTour.textContent = "Saltar";
    btnSiguienteTour = document.createElement("button");
    btnSiguienteTour.type = "button";
    btnSiguienteTour.textContent = "Siguiente";
    botones.appendChild(btnSaltarTour);
    botones.appendChild(btnSiguienteTour);
    controles.appendChild(puntos);
    controles.appendChild(botones);
    b.appendChild(controles);

    btnSiguienteTour.addEventListener("click", function () {
      if (pasoTour >= pasosActuales.length - 1) {
        cerrarTour("¡Listo! Ya conocés lo básico de esta pantalla. Si te queda alguna duda, tocame y preguntame.");
      } else {
        mostrarPasoTour(pasoTour + 1);
      }
    });
    btnSaltarTour.addEventListener("click", function () {
      cerrarTour();
    });
    // Un toque en una burbuja suelta la descarta (en el tour no: tiene sus botones).
    b.addEventListener("click", function (ev) {
      if (tourActivo || ev.target.closest("button")) return;
      ocultarBurbuja();
    });
    return b;
  }

  function decir(texto, estado, opts) {
    opts = opts || {};
    if (!montado) return;
    // Con el chat abierto la conversación está en el panel: la burbuja sería
    // una segunda voz tapándolo. Sí se mueve la mascota.
    if (!opts.tour && panelAbierto()) {
      if (estado) ponerEstado(mascotaEl, estado);
      return;
    }
    if (estado) ponerEstado(mascotaEl, estado);
    burbuja.classList.add("mtour-visible");
    burbuja.classList.toggle("mtour-tour", !!opts.tour);
    clearTimeout(volverAIdleTimer);
    clearTimeout(autocierreTimer);
    var miToken = ++tokenEscritura;
    burbujaTexto.textContent = "";
    var i = 0;
    var paso = function () {
      if (miToken !== tokenEscritura) return;
      if (i <= texto.length) {
        burbujaTexto.textContent = texto.slice(0, i);
        i++;
        setTimeout(paso, VELOCIDAD_MS);
        return;
      }
      if (opts.tour) return; // el tour avanza con sus botones
      if (estado && estado !== "idle") {
        volverAIdleTimer = setTimeout(function () {
          if (miToken === tokenEscritura) ponerEstado(mascotaEl, "idle");
        }, 1400);
      }
      if (!opts.persistente) {
        autocierreTimer = setTimeout(function () {
          if (miToken === tokenEscritura) ocultarBurbuja();
        }, AUTOCIERRE_MS);
      }
    };
    paso();
  }

  function ocultarBurbuja() {
    if (!burbuja) return;
    tokenEscritura++; // corta cualquier tipeo en curso
    clearTimeout(volverAIdleTimer);
    clearTimeout(autocierreTimer);
    burbuja.classList.remove("mtour-visible", "mtour-tour");
    // Sin texto no tiene sentido dejar la pantalla oscurecida: si estaba
    // señalando o en medio del tour, el spotlight se apaga con la burbuja.
    if (hueco) hueco.classList.remove("mtour-visible");
    if (raiz) raiz.classList.remove("mtour-flotante", "mtour-en-tour");
    document.body.classList.remove("mtour-tour-activo");
  }

  // Estado de la mascota sin burbuja: lo usa el chat para "pensando" mientras
  // espera a Groq. Éxito/error vuelven solos a idle.
  function setEstado(estado) {
    if (!montado) return;
    clearTimeout(volverAIdleTimer);
    ponerEstado(mascotaEl, estado);
    if (estado === "exito" || estado === "error" || estado === "explicando") {
      volverAIdleTimer = setTimeout(function () {
        ponerEstado(mascotaEl, "idle");
      }, 1600);
    }
  }

  // ---------------- Tour de bienvenida (spotlight) ----------------

  var hueco, backdrop;
  var pasosActuales = [];
  var pasoTour = 0;
  var tourActivo = false;

  function montarOverlayTour() {
    if (hueco) return;
    hueco = document.createElement("div");
    hueco.id = "mtour-hueco";
    backdrop = document.createElement("div");
    backdrop.id = "mtour-backdrop";
    document.body.appendChild(hueco);
    document.body.appendChild(backdrop);

    backdrop.addEventListener("click", function (e) {
      if (!tourActivo) return;
      var el = elementoDelPaso(pasosActuales[pasoTour]);
      if (!el) {
        cerrarTour();
        return;
      }
      var r = el.getBoundingClientRect();
      var dentro = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
      if (!dentro) cerrarTour();
    });
  }

  function elementoDelPaso(paso) {
    if (!paso) return null;
    if (paso.enLanzador) return boton;
    return paso.selector ? document.querySelector(paso.selector) : null;
  }

  // Solo el recuadro de luz. Para el botón de Cajita no se mueve nada: ya está
  // en su esquina.
  function ubicarHueco(el) {
    var r = el.getBoundingClientRect();
    var pad = 8;
    hueco.style.left = r.left - pad + "px";
    hueco.style.top = r.top - pad + "px";
    hueco.style.width = r.width + pad * 2 + "px";
    hueco.style.height = r.height + pad * 2 + "px";
    hueco.classList.add("mtour-visible");
    return r;
  }

  // Recuadro + Cajita se acerca al elemento con su burbuja.
  function ubicarFocoYWidget(el) {
    var r = ubicarHueco(el);
    var anchoWidget = 300;
    var fx = r.left;
    var fy = r.bottom + 14;
    if (fy + 190 > window.innerHeight) fy = Math.max(10, r.top - 190);
    if (fx + anchoWidget > window.innerWidth - 10) fx = window.innerWidth - anchoWidget - 16;
    if (fx < 10) fx = 10;
    raiz.style.setProperty("--mtour-fx", fx + "px");
    raiz.style.setProperty("--mtour-fy", fy + "px");
  }

  function reubicarPasoActual() {
    var paso = pasosActuales[pasoTour];
    if (!tourActivo || !paso) return;
    var el = elementoDelPaso(paso);
    if (!el) return;
    if (paso.enLanzador) ubicarHueco(el);
    else ubicarFocoYWidget(el);
  }

  function construirPuntos() {
    var cont = document.getElementById("mtour-puntos");
    if (!cont) return;
    cont.innerHTML = "";
    pasosActuales.forEach(function (_, i) {
      var s = document.createElement("span");
      if (i === pasoTour) s.classList.add("mtour-activo");
      cont.appendChild(s);
    });
  }

  function mostrarPasoTour(indice) {
    var paso = pasosActuales[indice];
    if (paso.selector && !document.querySelector(paso.selector)) {
      console.warn("[Cajita] selector no encontrado, salto el paso:", paso.selector);
      if (indice + 1 < pasosActuales.length) mostrarPasoTour(indice + 1);
      else cerrarTour();
      return;
    }
    pasoTour = indice;
    // En el paso del lanzador Cajita vuelve a su esquina (sin flotar); en el
    // resto va junto al elemento que se explica.
    raiz.classList.toggle("mtour-flotante", !paso.enLanzador);
    var el = elementoDelPaso(paso);
    // El elemento existe pero está en otra pantalla (display:none, p.ej. la
    // Red de Seguridad mientras se ve la captura): no hay nada real para
    // iluminar, así que el paso se muestra igual pero sin spotlight en vez
    // de marcar un recuadro de 0x0 en la esquina.
    if (el && el.offsetParent === null && !paso.enLanzador) el = null;
    var mostrarAhora = function () {
      if (el && paso.enLanzador) ubicarHueco(el);
      else if (el) ubicarFocoYWidget(el);
      else hueco.classList.remove("mtour-visible");
      construirPuntos();
      var titulo = paso.titulo ? paso.titulo + ". " : "";
      decir(titulo + paso.texto, paso.estado, { tour: true });
      if (btnSiguienteTour) btnSiguienteTour.textContent = pasoTour === pasosActuales.length - 1 ? "Terminar" : "Siguiente";
    };
    if (el && !paso.enLanzador) {
      el.scrollIntoView({ behavior: "smooth", block: "center" });
      setTimeout(mostrarAhora, 380);
    } else {
      mostrarAhora();
    }
  }

  function iniciarTour(pasos) {
    if (!pasos || !pasos.length) return;
    // El tour no convive con el chat abierto ni con el 🎯 prendido.
    if (opciones && opciones.cerrarPanel) opciones.cerrarPanel();
    activarModoSenalar(false);
    montarOverlayTour();
    pasosActuales = pasos;
    pasoTour = 0;
    tourActivo = true;
    document.body.classList.add("mtour-tour-activo");
    raiz.classList.add("mtour-en-tour");
    mostrarPasoTour(0);
  }

  function cerrarTour(mensajeFinal) {
    if (!tourActivo) return;
    tourActivo = false;
    document.body.classList.remove("mtour-tour-activo");
    if (hueco) hueco.classList.remove("mtour-visible");
    raiz.classList.remove("mtour-flotante", "mtour-en-tour");
    if (rol) marcarTourVisto(rol);
    if (mensajeFinal) {
      burbuja.classList.remove("mtour-tour");
      decir(mensajeFinal, "exito");
    } else {
      // Sin esto, "Saltar" dejaba el último texto del tour pegado en pantalla.
      ocultarBurbuja();
      ponerEstado(mascotaEl, "idle");
    }
  }

  // ---------------- Modo "tocar para preguntar" (🎯) ----------------

  var modoSenalar = false;
  var alSenalarCb = null;

  // Filtra a los objetivos que están realmente visibles ahora: repositor y
  // cajero muestran una sola pantalla a la vez (captura/validación/
  // inventario) y el resto queda en display:none -- señalar algo oculto no
  // tendría nada que iluminar.
  function obtenerSenalablesVisibles() {
    var lista = (ayuda && ayuda.senalables && ayuda.senalables[pagina]) || [];
    var visibles = [];
    for (var i = 0; i < lista.length; i++) {
      var el = document.querySelector(lista[i].selector);
      if (el && el.offsetParent !== null) visibles.push({ cfg: lista[i], el: el });
    }
    return visibles;
  }

  function limpiarSenalables() {
    var marcados = document.querySelectorAll(".mtour-senalable");
    for (var i = 0; i < marcados.length; i++) {
      marcados[i].classList.remove("mtour-senalable");
      marcados[i].removeEventListener("click", onClickSenalable);
      marcados[i].removeEventListener("keydown", onKeydownSenalable);
    }
  }

  function onClickSenalable(ev) {
    if (!modoSenalar || tourActivo) return;
    var cfg = ev.currentTarget.__mtourCfg;
    if (!cfg) return;
    // Algunos objetivos son <label> de un <input type="file"> (la zona de
    // captura): en modo señalar el toque es sólo para preguntar, no para
    // disparar la acción real (abrir la cámara/el selector de archivos).
    ev.preventDefault();
    ev.stopPropagation();
    activarModoSenalar(false);
    ocultarBurbuja();
    // La explicación y la conversación siguen en el chat: acá solo se avisa
    // qué panel se tocó.
    if (alSenalarCb) alSenalarCb({ id: cfg.id, titulo: cfg.titulo, texto: cfg.texto });
  }
  function onKeydownSenalable(ev) {
    if (ev.key === "Enter" || ev.key === " ") {
      ev.preventDefault();
      ev.currentTarget.click();
    }
  }

  function activarModoSenalar(activo) {
    modoSenalar = activo;
    document.body.classList.toggle("mtour-modo-senalar", activo);
    limpiarSenalables();
    if (!activo || !montado) return;
    cargarAyuda().then(function () {
      if (!modoSenalar) return; // se canceló mientras cargaba la ayuda
      var visibles = obtenerSenalablesVisibles();
      visibles.forEach(function (v) {
        v.el.classList.add("mtour-senalable");
        v.el.__mtourCfg = v.cfg;
        if (!v.el.hasAttribute("tabindex")) v.el.setAttribute("tabindex", "0");
        v.el.addEventListener("click", onClickSenalable);
        v.el.addEventListener("keydown", onKeydownSenalable);
      });
      if (visibles.length) {
        decir("Tocá cualquier parte resaltada de la pantalla y te cuento de qué se trata.", "idle", { persistente: true });
      } else {
        activarModoSenalar(false);
        decir("No encontré nada para señalar en esta pantalla todavía. Abrime y escribí tu pregunta.", "idle");
      }
    });
  }

  function alternarSenalar() {
    activarModoSenalar(!modoSenalar);
    return modoSenalar;
  }

  // ---------------- Respaldo local (FAQ por palabras clave) ----------------

  // Solo para cuando /chatbot no responde. Con la IA andando el modelo
  // contesta con la misma ayuda.json pero entendiendo la pregunta; esto es
  // un match por palabras sueltas y puede errarle, por eso el chat lo marca
  // como "respuesta guardada".
  function respuestaLocal(texto, seccion) {
    return cargarAyuda().then(function (datos) {
      var t = String(texto || "").toLowerCase();
      var faq = (datos && datos.faq) || [];
      for (var i = 0; i < faq.length; i++) {
        var f = faq[i];
        if ((f.contextos || []).indexOf(seccion) === -1) continue;
        for (var j = 0; j < f.palabras.length; j++) {
          if (t.indexOf(f.palabras[j]) !== -1) return { respuesta: f.respuesta, estado: f.estado || "idle" };
        }
      }
      return null;
    });
  }

  // ---------------- Mute y arranque ----------------

  function alternarSilencio() {
    var estabaMuteada = estaMuteada();
    guardarMuteHasta(estabaMuteada ? 0 : Date.now() + MUTE_MINUTOS * 60000);
    if (!estabaMuteada) ocultarBurbuja();
    return !estabaMuteada; // true = quedó silenciada
  }

  function programarArranque() {
    if (estaMuteada()) return; // seguía silenciada de antes: no molestamos al cargar
    setTimeout(function () {
      if (!montado || panelAbierto()) return;
      if (GUION_TOUR[pagina] && rol && !tourYaVisto(rol)) {
        iniciarTour(GUION_TOUR[pagina]);
      } else if (REVISITA[pagina]) {
        decir(REVISITA[pagina], "idle");
      }
    }, 700);
  }

  // ---------------- Listeners globales ----------------

  window.addEventListener("resize", reubicarPasoActual);
  window.addEventListener("scroll", reubicarPasoActual, { passive: true });

  document.addEventListener("keydown", function (ev) {
    if (ev.key !== "Escape") return;
    if (tourActivo) cerrarTour();
    else if (modoSenalar) {
      activarModoSenalar(false);
      ocultarBurbuja();
    }
  });

  // repositor.html y cajero.html disparan este evento en los mismos puntos
  // donde ya resuelven pensando/éxito/error/offline del backend real
  // (Roboflow vía FastAPI) -- no hay ningún setTimeout simulado acá.
  window.addEventListener("stockiate:captura-ia", function (ev) {
    if (!montado || estaMuteada()) return;
    var detalle = (ev && ev.detail) || {};
    if (detalle.estado === "pensando") {
      // persistente: tiene que seguir ahí hasta que llegue el éxito o el error.
      decir("Analizando la foto con la IA…", "pensando", { persistente: true });
    } else if (detalle.estado === "exito") {
      decir("¡Listo! Revisá el conteo en la Red de Seguridad antes de guardar.", "exito");
    } else if (detalle.estado === "error") {
      decir("No pude conectar con la IA. Podés seguir cargando manualmente mientras tanto.", "error");
    }
  });

  window.Cajita = {
    montar: montar,
    onPanelAbierto: function () {
      activarModoSenalar(false);
      ocultarBurbuja();
    },
    tourActivo: function () {
      return tourActivo;
    },
    setEstado: setEstado,
    alternarSenalar: alternarSenalar,
    alSenalar: function (cb) {
      alSenalarCb = cb;
    },
    respuestaLocal: respuestaLocal,
    silenciada: estaMuteada,
    alternarSilencio: alternarSilencio,
  };

  // ---------------- Panel de configuración (ajustes.html) ----------------

  function wirePanelConfiguracion() {
    var panel = document.getElementById("mtour-panel-configuracion");
    if (!panel) return;

    // El tour se marca "visto" por ROL, no por pantalla ni por cuenta (ver
    // claveTourVisto). Ajustes no sabe en qué pantalla se va a re-lanzar, así
    // que limpia los tres roles -- reproducir el que no corresponde no hace
    // nada, porque cada pantalla sólo lee el suyo.
    var btnRepetirTour = document.getElementById("mtour-btn-repetir-tour");
    if (btnRepetirTour) {
      btnRepetirTour.addEventListener("click", function () {
        ["repositor", "cajero", "dueño"].forEach(function (r) {
          try {
            localStorage.removeItem(claveTourVisto(r));
          } catch (e) {}
        });
        if (typeof window.mostrarToast === "function") {
          window.mostrarToast("Listo. La próxima vez que entres a Repositor, Caja o Administrador vas a ver el recorrido de nuevo.");
        }
      });
    }

    var cfg = leerConfig();
    var toggleVisible = document.getElementById("mtour-toggle-visible");
    if (toggleVisible) {
      toggleVisible.checked = !!cfg.visible;
      toggleVisible.addEventListener("change", function () {
        guardarConfig({ visible: toggleVisible.checked });
      });
    }
    var selectTamano = document.getElementById("mtour-select-tamano");
    if (selectTamano) {
      selectTamano.value = cfg.tamano;
      selectTamano.addEventListener("change", function () {
        guardarConfig({ tamano: selectTamano.value });
      });
    }
  }

  // ---------------- Bootstrap ----------------

  function init() {
    // Restos de la versión anterior: la lista de "preguntas sin responder"
    // vivía en el navegador de cada persona y nadie la podía leer.
    try {
      localStorage.removeItem("stockiate_mascota_preguntas_sin_responder");
    } catch (e) {}
    wirePanelConfiguracion();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
