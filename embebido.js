/**
 * stockIAte - embebido.js
 * ================================
 * Hace que una página completa (`carteles.html`, `equipo.html`) se pueda abrir
 * adentro de la ventana flotante del menú del negocio (`menu_negocio.js`), que
 * la carga en un <iframe>.
 *
 * POR QUÉ UN IFRAME Y NO REESCRIBIRLAS COMO COMPONENTE
 * ----------------------------------------------------
 * Carteles son ~1100 líneas con su propio CSS de impresión. Dentro de un iframe
 * siguen siendo exactamente la misma página: `window.print()` imprime sólo el
 * documento del iframe (la hoja A4, con sus reglas @media print), no el panel
 * de atrás. Y abiertas por URL directa siguen andando como antes.
 *
 * Se carga en el <head>, después de tema.js, para que la clase esté puesta
 * antes del primer paint y no se vea el header un instante.
 */
(function () {
  var embebido = false;
  try { embebido = window.self !== window.top; } catch (e) { embebido = true; }
  if (!embebido) return;

  document.documentElement.classList.add("stockiate-embebido");

  // La ventana flotante ya tiene título y botón de cerrar: el header de la
  // página (marca, "Volver al panel", tema, cerrar sesión) sobra adentro.
  var css =
    "html.stockiate-embebido header{display:none !important;}" +
    "html.stockiate-embebido body{background:transparent;min-height:0;}" +
    "html.stockiate-embebido .app{min-height:0;}" +
    "html.stockiate-embebido .landing{padding-top:20px;}";
  var estilo = document.createElement("style");
  estilo.textContent = css;
  document.head.appendChild(estilo);

  // Escape no burbujea del iframe a la página de atrás: se reenvía.
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      window.parent.postMessage({ tipo: "stockiate-cerrar-flotante" }, window.location.origin);
    }
  });
})();
