/**
 * stockIAte - Widget de chat flotante (Asistente IA)
 * =====================================================
 * Componente compartido, autocontenido (inyecta su propio <style>, no
 * depende de styles.css ni choca con el Tailwind de administrador.html).
 * Se incluye igual en las tres pantallas de rol, inyectado dinámicamente por
 * insertarChatbotWidget() en auth.js:
 *
 *   <script src="chatbot_widget.js" data-rol="repositor" data-contexto="repositor"></script>
 *
 * data-rol: "repositor" | "cajero" | "dueño" -- rol real de la sesión. Acá
 * sólo elige el copy de respaldo; el backend lo resuelve por su cuenta desde
 * la cookie (ver chatbot_ia.py).
 *
 * data-contexto: "repositor" | "cajero" | "admin" -- la SECCIÓN desde la que
 * se abre el chat. Elige el copy y los chips, y viaja al backend para
 * recortar de qué puede hablar el asistente: en el depósito no se pregunta
 * por facturación aunque quien pregunte sea el dueño. Sólo recorta (se
 * intersecta con las herramientas del rol), así que falsearlo no habilita
 * nada.
 */
(function () {
  "use strict";

  var scriptTag = document.currentScript;
  var rol = (scriptTag && scriptTag.dataset.rol) || "dueño";
  var contexto = (scriptTag && scriptTag.dataset.contexto) || "";
  // data-usuario-id ya no se lee: el backend identifica al usuario por la
  // cookie de sesión, no por lo que diga el cliente.

  var API_URL = URL_IA_CHATBOT;
  // Evita la página de advertencia HTML de ngrok free tier en vez de la respuesta JSON real.
  var HEADERS_NGROK = STOCKIATE_HEADERS; // definido en config.js
  var MAX_HISTORIAL = 10; // últimos N turnos (5 intercambios) para no mandar contexto infinito

  // Copy y preguntas sugeridas POR SECCIÓN (antes eran por rol). Los chips
  // acompañan el recorte de herramientas del backend: si desde el depósito no
  // se pueden consultar ventas, tampoco se ofrecen como sugerencia.
  var CONTEXTO_INFO = {
    repositor: {
      titulo: "Depósito",
      bienvenida: "Hola, soy el asistente de stockIAte. Desde acá te ayudo con stock y vencimientos. ¿Qué necesitás saber?",
      chips: [
        "¿Qué productos tienen stock bajo?",
        "¿Qué productos vencen pronto?",
        "¿Tenemos stock de Dior?",
      ],
    },
    cajero: {
      titulo: "Caja",
      bienvenida: "Hola, soy el asistente de stockIAte. Desde acá te ayudo con ventas y stock disponible. ¿Qué necesitás saber?",
      chips: [
        "¿Cuánto vendimos hoy?",
        "¿Qué se vendió más esta semana?",
        "¿Tenemos stock de Eros?",
      ],
    },
    admin: {
      titulo: "Administración",
      bienvenida: "Hola, soy el asistente de stockIAte. Preguntame por stock, ventas, vencimientos, costos o márgenes.",
      chips: [
        "¿Qué productos me dejan más margen?",
        "¿Dónde tengo la plata inmovilizada?",
        "¿A qué proveedor le compro más?",
      ],
    },
  };

  // Respaldo para una página que no declare contexto (ver
  // CONTEXTO_CHATBOT_POR_PAGINA en auth.js): se elige por rol, como hacía
  // este widget antes.
  var CONTEXTO_POR_ROL = { repositor: "repositor", cajero: "cajero", "dueño": "admin" };

  var info = CONTEXTO_INFO[contexto] || CONTEXTO_INFO[CONTEXTO_POR_ROL[rol]] || CONTEXTO_INFO.admin;
  var historial = [];
  var enviando = false;

  function inyectarEstilos() {
    if (document.getElementById("siate-chat-estilos")) return;
    var style = document.createElement("style");
    style.id = "siate-chat-estilos";
    style.textContent = [
      ".siate-chat-widget{--sc-accent:var(--lila-dark, #B87FD9);--sc-accent-2:var(--lila, #D9BFEA);--sc-bg:var(--glass-bg, rgba(255,255,255,.55));--sc-bg-2:var(--superficie, #F3EFF7);--sc-bg-3:var(--superficie, #F3EFF7);--sc-text:var(--blanco-puro, #453B5C);--sc-text-dim:var(--gris-tenue, #8B7FA3);--sc-border:var(--glass-border, rgba(255,255,255,.7));position:fixed;right:18px;bottom:18px;z-index:999999;font-family:'Plus Jakarta Sans','Inter',system-ui,sans-serif;}",
      ".siate-chat-boton{width:58px;height:58px;border-radius:50%;border:none;cursor:pointer;background:linear-gradient(135deg,var(--sc-accent-2),var(--sc-accent));color:#fff;font-size:26px;box-shadow:0 8px 20px var(--lila-shadow, rgba(184,127,217,.4));display:flex;align-items:center;justify-content:center;transition:transform .15s ease;}",
      ".siate-chat-boton:hover{transform:scale(1.06);}",
      ".siate-chat-panel{position:absolute;right:0;bottom:72px;width:340px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 120px);background:var(--sc-bg);backdrop-filter:blur(20px) saturate(180%);-webkit-backdrop-filter:blur(20px) saturate(180%);border:1px solid var(--sc-border);border-radius:20px;box-shadow:var(--glass-shadow, 0 20px 60px rgba(0,0,0,.25));display:flex;flex-direction:column;overflow:hidden;}",
      ".siate-chat-panel[hidden]{display:none;}",
      ".siate-chat-head{padding:14px 16px;background:linear-gradient(135deg,var(--sc-accent-2),var(--sc-accent));color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between;}",
      ".siate-chat-cerrar{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.85;line-height:1;padding:8px;margin:-8px;border-radius:6px;}",
      ".siate-chat-cerrar:hover{opacity:1;background:rgba(255,255,255,.15);}",
      ".siate-chat-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;}",
      ".siate-chat-msg{max-width:86%;padding:9px 12px;border-radius:12px;font-size:13px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}",
      ".siate-chat-msg-bot{background:var(--sc-bg-3);color:var(--sc-text);align-self:flex-start;border-bottom-left-radius:4px;box-shadow:var(--neu-sombra-chica, none);}",
      ".siate-chat-msg-bot-error{background:var(--error-bg, rgba(242,191,196,.6));color:var(--error, #B4636F);border:1px solid var(--error-border, rgba(242,191,196,.65));align-self:flex-start;border-bottom-left-radius:4px;}",
      ".siate-chat-msg-user{background:linear-gradient(135deg,var(--sc-accent-2),var(--sc-accent));color:#fff;align-self:flex-end;border-bottom-right-radius:4px;}",
      ".siate-chat-typing span{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:var(--sc-text-dim);animation:siate-chat-blink 1.2s infinite;}",
      ".siate-chat-typing span:nth-child(2){animation-delay:.2s;}",
      ".siate-chat-typing span:nth-child(3){animation-delay:.4s;}",
      "@keyframes siate-chat-blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}",
      ".siate-chat-chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px;}",
      ".siate-chat-chip{background:var(--sc-bg-2);border:1px solid var(--sc-border);color:var(--sc-text-dim);border-radius:999px;padding:6px 10px;font-size:11.5px;cursor:pointer;}",
      ".siate-chat-chip:hover{color:var(--sc-text);border-color:var(--sc-accent);}",
      ".siate-chat-form{display:flex;gap:8px;padding:12px;border-top:1px solid var(--sc-border);background:var(--sc-bg-2);}",
      ".siate-chat-input{flex:1;background:var(--sc-bg-3);border:none;box-shadow:var(--neu-inset, none);border-radius:10px;color:var(--sc-text);padding:9px 12px;font-size:13px;outline:none;min-width:0;}",
      ".siate-chat-input:focus{box-shadow:var(--neu-inset, none), 0 0 0 2px var(--sc-accent);}",
      ".siate-chat-input:disabled{opacity:.6;}",
      ".siate-chat-enviar{background:linear-gradient(135deg,var(--sc-accent-2),var(--sc-accent));border:none;color:#fff;width:38px;flex-shrink:0;border-radius:10px;cursor:pointer;font-size:15px;}",
      ".siate-chat-enviar:disabled{opacity:.5;cursor:default;}",
      "@media (max-width:480px){.siate-chat-widget{right:12px;bottom:12px;}.siate-chat-panel{width:calc(100vw - 24px);bottom:70px;}}",
    ].join("\n");
    document.head.appendChild(style);
  }

  function crearWidget() {
    var raiz = document.createElement("div");
    raiz.className = "siate-chat-widget";

    var boton = document.createElement("button");
    boton.type = "button";
    boton.className = "siate-chat-boton";
    boton.setAttribute("aria-label", "Abrir asistente IA");
    boton.setAttribute("aria-expanded", "false");
    boton.textContent = "🤖";

    var panel = document.createElement("div");
    panel.className = "siate-chat-panel";
    panel.hidden = true;

    var head = document.createElement("div");
    head.className = "siate-chat-head";
    var headTitulo = document.createElement("span");
    headTitulo.textContent = "🤖 Asistente · " + info.titulo;
    var cerrar = document.createElement("button");
    cerrar.type = "button";
    cerrar.className = "siate-chat-cerrar";
    cerrar.setAttribute("aria-label", "Cerrar");
    cerrar.textContent = "✕";
    head.appendChild(headTitulo);
    head.appendChild(cerrar);

    var cuerpo = document.createElement("div");
    cuerpo.className = "siate-chat-body";

    var chipsEl = document.createElement("div");
    chipsEl.className = "siate-chat-chips";

    var form = document.createElement("form");
    form.className = "siate-chat-form";
    var input = document.createElement("input");
    input.type = "text";
    input.className = "siate-chat-input";
    input.placeholder = "Escribí tu pregunta...";
    input.autocomplete = "off";
    var botonEnviar = document.createElement("button");
    botonEnviar.type = "submit";
    botonEnviar.className = "siate-chat-enviar";
    botonEnviar.textContent = "➤";
    form.appendChild(input);
    form.appendChild(botonEnviar);

    panel.appendChild(head);
    panel.appendChild(cuerpo);
    panel.appendChild(chipsEl);
    panel.appendChild(form);

    raiz.appendChild(boton);
    raiz.appendChild(panel);
    document.body.appendChild(raiz);

    return { raiz: raiz, boton: boton, panel: panel, cerrar: cerrar, cuerpo: cuerpo, chipsEl: chipsEl, form: form, input: input, botonEnviar: botonEnviar };
  }

  function agregarMensaje(cuerpo, texto, tipo) {
    var div = document.createElement("div");
    div.className = "siate-chat-msg siate-chat-msg-" + tipo;
    div.textContent = texto;
    cuerpo.appendChild(div);
    cuerpo.scrollTop = cuerpo.scrollHeight;
    return div;
  }

  function agregarTyping(cuerpo) {
    var div = document.createElement("div");
    div.className = "siate-chat-msg siate-chat-msg-bot siate-chat-typing";
    div.appendChild(document.createElement("span"));
    div.appendChild(document.createElement("span"));
    div.appendChild(document.createElement("span"));
    cuerpo.appendChild(div);
    cuerpo.scrollTop = cuerpo.scrollHeight;
    return div;
  }

  function enviarPregunta(el, texto) {
    if (!texto || !texto.trim() || enviando) return;
    enviando = true;
    el.input.value = "";
    el.input.disabled = true;
    el.botonEnviar.disabled = true;

    agregarMensaje(el.cuerpo, texto, "user");
    var typing = agregarTyping(el.cuerpo);

    fetchApi(API_URL, {
      method: "POST",
      headers: { ...HEADERS_NGROK, "Content-Type": "application/json" },
      // `rol` y `usuario_id` ya NO se mandan: el backend los resuelve desde
      // la cookie de sesión (que fetchApi adjunta). El `rol` de este archivo
      // sólo elige el copy y las preguntas sugeridas del widget.
      //
      // `contexto` sí se manda: es la sección de la que se está hablando, no
      // una credencial. El backend lo intersecta con las herramientas del rol
      // real, así que sólo puede recortar.
      body: JSON.stringify({
        pregunta: texto,
        historial: historial,
        contexto: contexto,
      }),
    })
      .then(function (resp) {
        return resp.json().catch(function () { return null; }).then(function (data) {
          return { ok: resp.ok, data: data };
        });
      })
      .then(function (res) {
        typing.remove();

        if (!res.ok || !res.data || !res.data.ok) {
          var detalle = (res.data && (res.data.detail || res.data.mensaje)) || "No se pudo responder";
          agregarMensaje(el.cuerpo, "Uy, algo falló: " + detalle, "bot-error");
          return;
        }

        agregarMensaje(el.cuerpo, res.data.respuesta, "bot");

        historial.push({ role: "user", content: texto });
        historial.push({ role: "assistant", content: res.data.respuesta });
        if (historial.length > MAX_HISTORIAL) {
          historial = historial.slice(historial.length - MAX_HISTORIAL);
        }
      })
      .catch(function () {
        typing.remove();
        agregarMensaje(
          el.cuerpo,
          "No me pude conectar con el servicio de IA. ¿Está corriendo el servicio en el puerto 8000?",
          "bot-error"
        );
      })
      .finally(function () {
        enviando = false;
        el.input.disabled = false;
        el.botonEnviar.disabled = false;
        el.input.focus();
      });
  }

  function init() {
    inyectarEstilos();
    var el = crearWidget();
    var abierto = false;

    function alternarPanel() {
      abierto = !abierto;
      el.panel.hidden = !abierto;
      el.boton.setAttribute("aria-expanded", String(abierto));
      if (abierto) el.input.focus();
    }

    el.boton.addEventListener("click", alternarPanel);
    el.cerrar.addEventListener("click", alternarPanel);

    info.chips.forEach(function (pregunta) {
      var chip = document.createElement("button");
      chip.type = "button";
      chip.className = "siate-chat-chip";
      chip.textContent = pregunta;
      chip.addEventListener("click", function () { enviarPregunta(el, pregunta); });
      el.chipsEl.appendChild(chip);
    });

    el.form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      enviarPregunta(el, el.input.value);
    });

    agregarMensaje(el.cuerpo, info.bienvenida, "bot");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
