/**
 * stockIAte - Widget de chat flotante (Asistente IA)
 * =====================================================
 * Componente compartido, autocontenido (inyecta su propio <style>, no
 * depende de styles.css ni choca con el Tailwind de administrador.html).
 * Se incluye igual en las tres pantallas de rol:
 *
 *   <script src="chatbot_widget.js" data-rol="repositor" data-usuario-id="1"></script>
 *
 * data-rol: "repositor" | "cajero" | "dueño" -- determina qué preguntas
 * puede resolver el backend (no hay login en el proyecto, el rol se infiere
 * por la página, igual que el resto del frontend).
 */
(function () {
  "use strict";

  var scriptTag = document.currentScript;
  var rol = (scriptTag && scriptTag.dataset.rol) || "dueño";
  var usuarioId = scriptTag && scriptTag.dataset.usuarioId ? Number(scriptTag.dataset.usuarioId) : null;

  var API_URL = "http://localhost:8000/chatbot";
  var MAX_HISTORIAL = 10; // últimos N turnos (5 intercambios) para no mandar contexto infinito

  var ROLE_INFO = {
    repositor: {
      titulo: "Repositor",
      bienvenida: "Hola, soy el asistente de stockIAte. Te puedo ayudar con stock y vencimientos. ¿Qué necesitás saber?",
      chips: [
        "¿Qué productos tienen stock bajo?",
        "¿Qué productos vencen pronto?",
        "¿Tenemos stock de Dior?",
      ],
    },
    cajero: {
      titulo: "Cajero",
      bienvenida: "Hola, soy el asistente de stockIAte. Te puedo ayudar con ventas y stock disponible. ¿Qué necesitás saber?",
      chips: [
        "¿Cuánto vendimos hoy?",
        "¿Qué se vendió más esta semana?",
        "¿Tenemos stock de Eros?",
      ],
    },
    "dueño": {
      titulo: "Dueño/a",
      bienvenida: "Hola, soy el asistente de stockIAte. Preguntame lo que necesites sobre stock, ventas o vencimientos.",
      chips: [
        "¿Cuál es la marca más vendida?",
        "¿Qué productos tienen stock crítico?",
        "Resumen de ventas del mes",
      ],
    },
  };

  var info = ROLE_INFO[rol] || ROLE_INFO["dueño"];
  var historial = [];
  var enviando = false;

  function inyectarEstilos() {
    if (document.getElementById("siate-chat-estilos")) return;
    var style = document.createElement("style");
    style.id = "siate-chat-estilos";
    style.textContent = [
      ".siate-chat-widget{--sc-accent:#7c3aed;--sc-bg:#111827;--sc-bg-2:#1f2937;--sc-bg-3:#374151;--sc-text:#f3f4f6;--sc-text-dim:#9ca3af;position:fixed;right:18px;bottom:18px;z-index:999999;font-family:'Inter',system-ui,sans-serif;}",
      ".siate-chat-boton{width:58px;height:58px;border-radius:50%;border:none;cursor:pointer;background:linear-gradient(135deg,var(--sc-accent),#4338ca);color:#fff;font-size:26px;box-shadow:0 8px 24px rgba(124,58,237,.45);display:flex;align-items:center;justify-content:center;transition:transform .15s ease;}",
      ".siate-chat-boton:hover{transform:scale(1.06);}",
      ".siate-chat-panel{position:absolute;right:0;bottom:72px;width:340px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 120px);background:var(--sc-bg);border:1px solid rgba(255,255,255,.08);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.45);display:flex;flex-direction:column;overflow:hidden;}",
      ".siate-chat-panel[hidden]{display:none;}",
      ".siate-chat-head{padding:14px 16px;background:linear-gradient(135deg,var(--sc-accent),#4338ca);color:#fff;font-weight:600;font-size:14px;display:flex;align-items:center;justify-content:space-between;}",
      ".siate-chat-cerrar{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.85;line-height:1;padding:8px;margin:-8px;border-radius:6px;}",
      ".siate-chat-cerrar:hover{opacity:1;background:rgba(255,255,255,.12);}",
      ".siate-chat-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;}",
      ".siate-chat-msg{max-width:86%;padding:9px 12px;border-radius:12px;font-size:13px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}",
      ".siate-chat-msg-bot{background:var(--sc-bg-3);color:var(--sc-text);align-self:flex-start;border-bottom-left-radius:4px;}",
      ".siate-chat-msg-bot-error{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.35);align-self:flex-start;border-bottom-left-radius:4px;}",
      ".siate-chat-msg-user{background:linear-gradient(135deg,var(--sc-accent),#4338ca);color:#fff;align-self:flex-end;border-bottom-right-radius:4px;}",
      ".siate-chat-typing span{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:var(--sc-text-dim);animation:siate-chat-blink 1.2s infinite;}",
      ".siate-chat-typing span:nth-child(2){animation-delay:.2s;}",
      ".siate-chat-typing span:nth-child(3){animation-delay:.4s;}",
      "@keyframes siate-chat-blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}",
      ".siate-chat-chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px;}",
      ".siate-chat-chip{background:var(--sc-bg-2);border:1px solid rgba(255,255,255,.08);color:var(--sc-text-dim);border-radius:999px;padding:6px 10px;font-size:11.5px;cursor:pointer;}",
      ".siate-chat-chip:hover{color:var(--sc-text);border-color:var(--sc-accent);}",
      ".siate-chat-form{display:flex;gap:8px;padding:12px;border-top:1px solid rgba(255,255,255,.08);background:var(--sc-bg-2);}",
      ".siate-chat-input{flex:1;background:var(--sc-bg-3);border:1px solid rgba(255,255,255,.08);border-radius:10px;color:var(--sc-text);padding:9px 12px;font-size:13px;outline:none;min-width:0;}",
      ".siate-chat-input:focus{border-color:var(--sc-accent);}",
      ".siate-chat-input:disabled{opacity:.6;}",
      ".siate-chat-enviar{background:linear-gradient(135deg,var(--sc-accent),#4338ca);border:none;color:#fff;width:38px;flex-shrink:0;border-radius:10px;cursor:pointer;font-size:15px;}",
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

    fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        pregunta: texto,
        rol: rol,
        usuario_id: usuarioId,
        historial: historial,
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
