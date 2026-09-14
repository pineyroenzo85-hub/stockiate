/**
 * stockIAte - voz_busqueda.js
 * ================================
 * Búsqueda y navegación por voz (Speech-to-Text) sobre la Web Speech API
 * nativa del navegador (`SpeechRecognition` / `webkitSpeechRecognition`):
 * sin librerías ni build, igual que el resto del frontend.
 *
 * Componente compartido y autocontenido (inyecta su propio <style>, no
 * depende de styles.css, así funciona igual en administrador.html —que usa
 * Tailwind— y en repositor.html). Lo engancha `inventario_tabla.js` a la
 * barra de búsqueda del inventario.
 *
 * Uso:
 *   <script src="voz_busqueda.js?v=1"></script>
 *   ...
 *   initBusquedaPorVoz(inputDeBusqueda, {
 *     boton:  elementoBoton,      // opcional: si no viene, lo crea al lado del input
 *     estado: elementoTexto,      // opcional: línea de feedback; si no viene, la crea
 *     idioma: "es-AR",            // opcional (default es-AR, con fallback a es-ES)
 *     onNavegar: (destino) => bool,   // opcional: devolver true si la página ya lo resolvió
 *     onBuscar:  (termino) => {},     // opcional: extra, además de rellenar el input
 *     onEstado:  (estado) => {},      // opcional: para enganchar toasts propios
 *   });
 *
 * El parser de comandos es una función pura y se exporta aparte, así se
 * puede probar sin micrófono desde la consola del navegador:
 *   VozBusqueda.interpretarComando("buscá Dior")   // {tipo:"buscar", termino:"dior"}
 *   VozBusqueda.interpretarComando("ir a ventas")  // {tipo:"navegar", destino:"ventas"}
 *   VozBusqueda.interpretarComando("limpiar")      // {tipo:"limpiar"}
 *
 * Limitaciones conocidas (ver docs/ARCHITECTURE.md):
 * - Sólo navegadores con Web Speech API (Chrome, Edge, Safari; Firefox no).
 * - Requiere contexto seguro: https o localhost (abrir por Apache, nunca
 *   con file://) y permiso de micrófono del usuario.
 * - En Chrome el audio se transcribe en servidores de Google: necesita
 *   internet, no es reconocimiento offline.
 */
(function (global) {
  "use strict";

  var IDIOMA_DEFAULT = "es-AR";
  var IDIOMA_FALLBACK = "es-ES";
  var MS_SIN_VOZ = 12000; // watchdog: corta si el navegador se queda escuchando de gusto

  /* ============================================================
   * 1. Motor de reconocimiento (Web Speech API)
   * ============================================================ */

  function apiReconocimiento() {
    return global.SpeechRecognition || global.webkitSpeechRecognition || null;
  }

  /**
   * Devuelve null si se puede dictar, o el motivo por el que no se puede:
   * "navegador" (API ausente) o "inseguro" (ni https ni localhost).
   */
  function motivoNoDisponible() {
    if (!apiReconocimiento()) return "navegador";
    // isSecureContext ya considera localhost/127.0.0.1 como seguro.
    if (global.isSecureContext === false) return "inseguro";
    return null;
  }

  function soportado() {
    return motivoNoDisponible() === null;
  }

  var MENSAJES_NO_DISPONIBLE = {
    navegador: "Tu navegador no soporta dictado por voz. Probá con Chrome o Edge.",
    inseguro: "El dictado por voz necesita https o localhost (abrí la página por Apache, no con file://).",
  };

  var MENSAJES_ERROR = {
    "not-allowed": "Bloqueaste el micrófono. Habilitalo desde el candado de la barra de direcciones.",
    "service-not-allowed": "El navegador no dejó usar el micrófono. Revisá los permisos del sitio.",
    "no-speech": "No te escuché. Probá de nuevo y hablá cerca del micrófono.",
    "audio-capture": "No encontramos ningún micrófono conectado.",
    "network": "Sin conexión con el servicio de voz. Revisá internet.",
    "language-not-supported": "El navegador no tiene instalado el español para dictado.",
  };

  function mensajeDeError(codigo) {
    return MENSAJES_ERROR[codigo] || "No se pudo usar el micrófono (" + codigo + ").";
  }

  /**
   * Envuelve SpeechRecognition con la configuración del proyecto (español,
   * un resultado por vez) y normaliza sus eventos. Devuelve null si el
   * navegador no lo soporta — el llamador decide qué hacer con eso.
   *
   * callbacks: { onInicio, onParcial(texto), onFinal(texto), onError(codigo, mensaje), onFin }
   */
  function crearReconocimientoVoz(opciones) {
    opciones = opciones || {};
    var API = apiReconocimiento();
    if (!API) return null;

    var reconocimiento = new API();
    reconocimiento.lang = opciones.idioma || IDIOMA_DEFAULT;
    reconocimiento.continuous = false;      // una frase por vez: dictar y ejecutar
    reconocimiento.interimResults = true;   // para mostrar lo que va escuchando
    reconocimiento.maxAlternatives = 1;

    var escuchando = false;
    var huboError = false;
    var reintentoIdioma = false;
    var temporizador = null;

    function limpiarTemporizador() {
      if (temporizador) {
        clearTimeout(temporizador);
        temporizador = null;
      }
    }

    function reiniciarTemporizador() {
      limpiarTemporizador();
      temporizador = setTimeout(function () {
        if (escuchando) reconocimiento.abort();
      }, MS_SIN_VOZ);
    }

    reconocimiento.onstart = function () {
      escuchando = true;
      huboError = false;
      reiniciarTemporizador();
      if (opciones.onInicio) opciones.onInicio();
    };

    reconocimiento.onresult = function (evento) {
      reiniciarTemporizador();
      var resultado = evento.results[evento.results.length - 1];
      var texto = resultado[0] ? resultado[0].transcript : "";
      if (resultado.isFinal) {
        if (opciones.onFinal) opciones.onFinal(texto);
      } else if (opciones.onParcial) {
        opciones.onParcial(texto);
      }
    };

    reconocimiento.onerror = function (evento) {
      // "aborted" es el usuario cortando (o nuestro watchdog): no es un error que mostrar.
      if (evento.error === "aborted") return;

      // Fallback de idioma: si el navegador no tiene es-AR, reintentamos
      // una sola vez con es-ES antes de dar el error por perdido.
      if (evento.error === "language-not-supported" && !reintentoIdioma && reconocimiento.lang !== IDIOMA_FALLBACK) {
        reintentoIdioma = true;
        reconocimiento.lang = IDIOMA_FALLBACK;
        escuchando = false;
        try {
          reconocimiento.start();
          return;
        } catch (e) {
          // si tampoco arranca, sigue de largo y reporta el error original
        }
      }

      huboError = true;
      if (opciones.onError) opciones.onError(evento.error, mensajeDeError(evento.error));
    };

    reconocimiento.onend = function () {
      limpiarTemporizador();
      // En el reintento por idioma el onend del intento anterior no cierra el ciclo.
      if (!escuchando) return;
      escuchando = false;
      if (opciones.onFin) opciones.onFin(huboError);
    };

    return {
      get escuchando() {
        return escuchando;
      },
      get idioma() {
        return reconocimiento.lang;
      },
      escuchar: function () {
        if (escuchando) return false;
        try {
          reconocimiento.start();
          return true;
        } catch (e) {
          // InvalidStateError: ya estaba arrancando. No es fatal.
          return false;
        }
      },
      detener: function () {
        if (!escuchando) return;
        reconocimiento.abort();
        escuchando = false;
        limpiarTemporizador();
        if (opciones.onFin) opciones.onFin(false);
      },
    };
  }

  /* ============================================================
   * 2. Parser de comandos (función pura, sin DOM)
   * ============================================================ */

  // Destinos de navegación. El match es exacto contra la frase ya
  // normalizada y sin artículos, para no confundir "buscar stock de Dior"
  // (búsqueda) con "ver stock" (navegación).
  var DESTINOS = {
    inventario: ["inventario", "inventarios", "stock", "el stock", "deposito", "productos", "catalogo", "existencias", "mercaderia"],
    ventas: ["ventas", "venta", "caja", "cajero", "vender", "cobrar", "punto de venta", "modulo de ventas", "modulo caja"],
    ajustes: ["ajustes", "ajuste", "configuracion", "configuraciones", "preferencias", "opciones", "settings", "menu", "mi cuenta", "perfil"],
    repositor: ["repositor", "reposicion", "carga", "carga de stock", "cargar mercaderia", "modulo repositor"],
    administrador: ["administrador", "admin", "administracion", "panel", "panel de administrador", "dashboard", "estadisticas"],
    inicio: ["inicio", "home", "principal", "menu principal", "pantalla principal", "landing"],
  };

  var COMANDOS_LIMPIAR = [
    "limpiar", "limpia", "limpiar busqueda", "limpia la busqueda", "limpiar filtro", "limpiar filtros",
    "borrar", "borra", "borrar busqueda", "borra la busqueda", "borrar todo",
    "cancelar", "reiniciar", "resetear", "reset", "todo", "todos", "todos los productos", "ver todo", "mostrar todo",
  ];

  var RE_VERBO_BUSQUEDA = /^(?:buscar|buscarme|buscame|busca|busque|busquen|busqueda de|busqueda|filtrar|filtrame|filtra|encontrar|encontrame|encontra|encuentra)\b\s*(.*)$/;
  var RE_VERBO_NAVEGACION = /^(?:ir a|ir al|ir|anda a|andate a|anda|vamos a|vamos|llevame a|llevame|volver a|volver|volve a|volve|abrir|abrime|abri|abre|mostrar|mostrame|mostra|muestra|ver|entrar a|entrar|pasar a|quiero ver|necesito ver)\b\s*(.*)$/;
  // "stock de Dior", "cuánto stock hay de Eros" -> es una búsqueda, no navegación.
  var RE_STOCK_DE = /^(?:stock|inventario|productos|catalogo|existencias)\s+(?:hay|tenemos|tenes|queda|quedan)?\s*de\s+(.+)$/;
  var RE_ARTICULO = /^(?:a|al|el|la|los|las|un|una|unos|unas|de|del|mi|mis|por)\s+/;
  // Muletillas de pregunta ("¿tenemos stock de Dior?", "¿cuánto queda de Eros?"):
  // se sacan antes de decidir si lo dictado es búsqueda o navegación.
  var RE_PREGUNTA = /^(?:cuanto|cuanta|cuantos|cuantas|que|cual|cuales|hay|tenemos|tenes|tiene|tienen|queda|quedan|nos|me|todavia)\s+/;

  function quitarAcentos(texto) {
    return texto.normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  function limpiarPuntuacion(texto) {
    return String(texto || "")
      .toLowerCase()
      .replace(/[¿?¡!.,;:"'`()\[\]]/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  /** Minúsculas, sin acentos, sin puntuación y con espacios colapsados. */
  function normalizarTexto(texto) {
    return quitarAcentos(limpiarPuntuacion(texto));
  }

  function quitarArticulos(texto) {
    var t = texto;
    while (RE_ARTICULO.test(t)) t = t.replace(RE_ARTICULO, "");
    return t.trim();
  }

  function quitarPreguntas(texto) {
    var t = texto;
    while (RE_PREGUNTA.test(t)) t = t.replace(RE_PREGUNTA, "");
    return t.trim();
  }

  function esComandoLimpiar(texto) {
    return COMANDOS_LIMPIAR.indexOf(texto.trim()) !== -1;
  }

  function destinoDe(texto) {
    var t = quitarArticulos(texto);
    if (!t) return null;
    for (var destino in DESTINOS) {
      if (Object.prototype.hasOwnProperty.call(DESTINOS, destino) && DESTINOS[destino].indexOf(t) !== -1) {
        return destino;
      }
    }
    return null;
  }

  /**
   * El parsing corre sobre el texto sin acentos (para que "buscá" y "busca"
   * caigan en el mismo comando), pero el término que va al buscador tiene
   * que conservarlos: el filtro de la tabla hace un `includes` literal y
   * "unica" no matchea "Única". Como normalizar no cambia la cantidad de
   * palabras, alcanza con recortar las mismas N palabras iniciales sobre la
   * versión acentuada.
   */
  function conAcentosDe(textoConAcentos, textoNormalizado, resto) {
    if (!resto) return "";
    var palabras = textoConAcentos.split(" ");
    var consumidas = textoNormalizado.split(" ").length - resto.split(" ").length;
    if (consumidas < 0 || consumidas >= palabras.length) return resto;
    return palabras.slice(consumidas).join(" ");
  }

  /**
   * Interpreta lo que dictó el usuario y devuelve la acción a ejecutar:
   *   { tipo: "buscar",  termino: "dior", texto }
   *   { tipo: "navegar", destino: "ventas", texto }
   *   { tipo: "limpiar", texto }
   *   { tipo: "desconocido", texto }
   * Si no es ni un comando de navegación ni de limpieza, se asume que el
   * usuario dictó directamente el nombre de un producto (búsqueda).
   */
  function interpretarComando(textoCrudo) {
    var conAcentos = limpiarPuntuacion(textoCrudo);
    var t = quitarAcentos(conAcentos);

    if (!t) return { tipo: "desconocido", texto: "" };
    if (esComandoLimpiar(t)) return { tipo: "limpiar", texto: t };

    function buscar(resto) {
      var termino = conAcentosDe(conAcentos, t, resto);
      return termino ? { tipo: "buscar", termino: termino, texto: t } : { tipo: "desconocido", texto: t };
    }

    // 1) Búsqueda explícita: "buscar Dior", "filtrá Carolina Herrera".
    var mBusqueda = t.match(RE_VERBO_BUSQUEDA);
    if (mBusqueda) {
      var restoBusqueda = quitarArticulos(mBusqueda[1]);
      var mStockDe = restoBusqueda.match(RE_STOCK_DE);
      if (mStockDe) restoBusqueda = quitarArticulos(mStockDe[1]);
      return buscar(restoBusqueda);
    }

    // 2) Navegación o consulta: "ir a ventas", "ver stock",
    //    "¿tenemos stock de Dior?", "¿cuánto queda de Eros?".
    var mNavegacion = t.match(RE_VERBO_NAVEGACION);
    var resto = quitarArticulos(quitarPreguntas(mNavegacion ? mNavegacion[1] : t));

    if (esComandoLimpiar(resto)) return { tipo: "limpiar", texto: t };

    var mStock = resto.match(RE_STOCK_DE);
    if (mStock) return buscar(quitarArticulos(mStock[1]));

    var destino = destinoDe(resto);
    if (destino) return { tipo: "navegar", destino: destino, texto: t };

    // 3) Cualquier otra cosa se toma como el nombre del producto buscado.
    return buscar(resto);
  }

  /* ============================================================
   * 3. Navegación por defecto (respeta el rol de la sesión)
   * ============================================================ */

  // Mismas rutas y permisos que exigirSesion() en auth.js: si mandáramos a
  // un cajero a administrador.html, el guard lo rebotaría de vuelta sin
  // explicarle nada. Mejor avisarle acá.
  var RUTAS = {
    inicio: { url: "landing_page.html", roles: null, etiqueta: "Inicio" },
    inventario: { url: "administrador.html", roles: ["dueño"], etiqueta: "Inventario" },
    ventas: { url: "cajero.html", roles: ["cajero", "dueño"], etiqueta: "Ventas" },
    repositor: { url: "repositor.html", roles: ["repositor", "dueño"], etiqueta: "Repositor" },
    administrador: { url: "administrador.html", roles: ["dueño"], etiqueta: "Panel de Administrador" },
    // Sin restricción de rol: es la configuración de la cuenta propia,
    // accesible a cualquier usuario logueado (ver exigirSesion() en
    // ajustes.html).
    ajustes: { url: "ajustes.html", roles: null, etiqueta: "Ajustes" },
  };

  function rolDeSesion() {
    if (typeof global.obtenerUsuarioSesion !== "function") return null;
    var usuario = global.obtenerUsuarioSesion();
    return usuario ? usuario.rol : null;
  }

  function etiquetaRol(rol) {
    var etiquetas = global.STOCKIATE_ETIQUETA_ROL;
    return (etiquetas && etiquetas[rol]) || rol || "tu usuario";
  }

  /**
   * Resuelve un destino cuando la página no lo manejó por su cuenta.
   * Devuelve { ok, mensaje } — no navega si el rol no tiene acceso.
   */
  function navegarPorDefecto(destino) {
    var ruta = RUTAS[destino];
    if (!ruta) return { ok: false, mensaje: "No sé cómo ir a “" + destino + "”." };

    var rol = rolDeSesion();
    if (ruta.roles && rol && ruta.roles.indexOf(rol) === -1) {
      return { ok: false, mensaje: "Tu rol (" + etiquetaRol(rol) + ") no tiene acceso a " + ruta.etiqueta + "." };
    }

    global.location.href = ruta.url;
    return { ok: true, mensaje: "Yendo a " + ruta.etiqueta + "…" };
  }

  /* ============================================================
   * 4. UI: botón de micrófono + estados visuales
   * ============================================================ */

  var ESTILOS_ID = "voz-estilos";
  var CSS = [
    ".voz-mic{--voz-accent:#7c5cff;--voz-escuchando:#ff5c72;width:28px;height:28px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;border-radius:8px;border:1px solid #2f3650;background:#252b3d;color:#9aa1b5;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s,color .15s;}",
    ".voz-mic svg{width:15px;height:15px;opacity:1;}",
    ".voz-mic:hover:not(:disabled){background:var(--voz-accent);border-color:var(--voz-accent);color:#fff;}",
    ".voz-mic:focus-visible{outline:2px solid var(--voz-accent);outline-offset:2px;}",
    ".voz-mic:disabled{opacity:.4;cursor:not-allowed;}",
    ".voz-mic--escuchando,.voz-mic--escuchando:hover:not(:disabled){background:var(--voz-escuchando);border-color:var(--voz-escuchando);color:#fff;animation:voz-pulso 1.4s ease-out infinite;}",
    "@keyframes voz-pulso{0%{box-shadow:0 0 0 0 rgba(255,92,114,.55);}70%{box-shadow:0 0 0 9px rgba(255,92,114,0);}100%{box-shadow:0 0 0 0 rgba(255,92,114,0);}}",
    ".voz-estado{margin:0;font-size:11.5px;line-height:1.35;color:#9aa1b5;font-family:inherit;}",
    ".voz-estado[hidden]{display:none;}",
    ".voz-estado--error{color:#ff5c72;}",
    ".voz-estado--ok{color:#3ddc84;}",
    "@media (prefers-reduced-motion:reduce){.voz-mic--escuchando{animation:none;}}",
  ].join("\n");

  var ICONO_MICROFONO =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>';

  function asegurarEstilos() {
    if (document.getElementById(ESTILOS_ID)) return;
    var style = document.createElement("style");
    style.id = ESTILOS_ID;
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  function crearBotonMicrofono() {
    var boton = document.createElement("button");
    boton.type = "button";
    boton.className = "voz-mic";
    boton.innerHTML = ICONO_MICROFONO;
    return boton;
  }

  function crearLineaEstado() {
    var p = document.createElement("p");
    p.className = "voz-estado";
    p.hidden = true;
    p.setAttribute("aria-live", "polite");
    return p;
  }

  /**
   * Engancha el dictado por voz a un input de búsqueda ya existente.
   * Devuelve { escuchar, detener, soportado, boton } o null si no hay input.
   */
  function initBusquedaPorVoz(input, opciones) {
    if (!input) return null;
    opciones = opciones || {};
    asegurarEstilos();

    var boton = opciones.boton;
    if (!boton) {
      boton = crearBotonMicrofono();
      (input.parentNode || input).appendChild(boton);
    } else if (!boton.innerHTML.trim()) {
      boton.innerHTML = ICONO_MICROFONO;
    }
    boton.classList.add("voz-mic");

    var estado = opciones.estado;
    if (!estado) {
      estado = crearLineaEstado();
      var contenedor = input.parentNode;
      if (contenedor && contenedor.parentNode) {
        contenedor.parentNode.insertBefore(estado, contenedor.nextSibling);
      }
    }

    function mostrarEstado(mensaje, tipo) {
      if (opciones.onEstado) opciones.onEstado({ mensaje: mensaje, tipo: tipo || "info" });
      if (!estado) return;
      estado.classList.remove("voz-estado--error", "voz-estado--ok");
      if (tipo === "error") estado.classList.add("voz-estado--error");
      if (tipo === "ok") estado.classList.add("voz-estado--ok");
      estado.textContent = mensaje || "";
      estado.hidden = !mensaje;
    }

    // --- Estado "no soportado": botón deshabilitado + tooltip explicativo ---
    var motivo = motivoNoDisponible();
    if (motivo) {
      var aviso = MENSAJES_NO_DISPONIBLE[motivo];
      boton.disabled = true;
      boton.classList.add("voz-mic--no-soportado");
      boton.title = aviso;
      boton.setAttribute("aria-label", aviso);
      return { soportado: false, motivo: motivo, boton: boton, escuchar: function () {}, detener: function () {} };
    }

    boton.title = "Buscar por voz";
    boton.setAttribute("aria-label", "Buscar por voz");
    boton.setAttribute("aria-pressed", "false");

    function pintarEscuchando(activo) {
      boton.classList.toggle("voz-mic--escuchando", activo);
      boton.setAttribute("aria-pressed", activo ? "true" : "false");
      boton.title = activo ? "Tocá para dejar de escuchar" : "Buscar por voz";
    }

    function aplicarBusqueda(termino) {
      input.value = termino;
      // El input ya tiene su propio listener de filtrado: disparamos los
      // eventos nativos en vez de acoplarnos a la función de cada página.
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
      if (opciones.onBuscar) opciones.onBuscar(termino);
    }

    function ejecutarComando(textoDictado) {
      var comando = interpretarComando(textoDictado);

      if (comando.tipo === "buscar") {
        aplicarBusqueda(comando.termino);
        mostrarEstado("Buscando “" + comando.termino + "”", "ok");
        return;
      }

      if (comando.tipo === "limpiar") {
        aplicarBusqueda("");
        mostrarEstado("Búsqueda limpia.", "ok");
        return;
      }

      if (comando.tipo === "navegar") {
        // La página puede resolver el destino sin recargar (ej. repositor.html
        // cambia de pantalla en vez de navegar); si devuelve true, listo.
        if (opciones.onNavegar && opciones.onNavegar(comando.destino) === true) {
          mostrarEstado("Listo.", "ok");
          return;
        }
        var resultado = navegarPorDefecto(comando.destino);
        mostrarEstado(resultado.mensaje, resultado.ok ? "ok" : "error");
        return;
      }

      mostrarEstado("No te entendí. Probá con “buscar Dior”, “ver stock” o “limpiar”.", "error");
    }

    var reconocimiento = crearReconocimientoVoz({
      idioma: opciones.idioma || IDIOMA_DEFAULT,
      onInicio: function () {
        pintarEscuchando(true);
        mostrarEstado("Escuchando… decí el producto o un comando.");
      },
      onParcial: function (texto) {
        mostrarEstado("“" + texto.trim() + "”…");
      },
      onFinal: function (texto) {
        ejecutarComando(texto);
      },
      onError: function (codigo, mensaje) {
        mostrarEstado(mensaje, "error");
      },
      onFin: function () {
        pintarEscuchando(false);
      },
    });

    boton.addEventListener("click", function () {
      if (reconocimiento.escuchando) {
        reconocimiento.detener();
        mostrarEstado("");
        return;
      }
      mostrarEstado("");
      reconocimiento.escuchar();
    });

    return {
      soportado: true,
      boton: boton,
      escuchar: function () {
        reconocimiento.escuchar();
      },
      detener: function () {
        reconocimiento.detener();
      },
    };
  }

  /* ============================================================
   * 5. Export
   * ============================================================ */

  global.VozBusqueda = {
    soportado: soportado,
    motivoNoDisponible: motivoNoDisponible,
    crearReconocimientoVoz: crearReconocimientoVoz,
    interpretarComando: interpretarComando,
    normalizarTexto: normalizarTexto,
    navegarPorDefecto: navegarPorDefecto,
    initBusquedaPorVoz: initBusquedaPorVoz,
    DESTINOS: DESTINOS,
  };

  // Alias global, misma convención que initInventarioTabla().
  global.initBusquedaPorVoz = initBusquedaPorVoz;
})(window);
