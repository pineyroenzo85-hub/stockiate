/**
 * stockIAte - foto_producto.js
 * ================================
 * La foto de cada producto: la que se sacó con la cámara en la Red de
 * Seguridad. Dos piezas, en el mismo archivo porque son las dos puntas de lo
 * mismo:
 *
 *   subirFotoProducto(productoId, archivo)
 *     La llaman repositor.html y cajero.html DESPUÉS de confirmar, para cada
 *     producto que salió de la foto. Es best-effort, como registrarCorreccion():
 *     si falla, la carga o la venta ya quedaron hechas y no se toca nada.
 *
 *   Vista previa al pasar el mouse
 *     Cualquier elemento con `data-foto-url` muestra la foto en un cartelito
 *     flotante que sigue al cursor. Lo usa inventario_tabla.js en el nombre del
 *     producto. Es un único listener delegado en `document`, así que sirve para
 *     filas que se dibujan después de cargar este script.
 *
 * Por qué la foto entera y no el recorte del producto: las detecciones que
 * devuelve el servicio de IA no traen la caja del objeto, sólo clase, confianza
 * y texto. La foto ya viene encuadrada por la persona (recorte_imagen.js).
 *
 * Clases con prefijo "fpr-".
 */

const URL_SUBIR_FOTO_PRODUCTO = "subir_foto_producto.php";

function subirFotoProducto(productoId, archivo) {
  if (!archivo || !productoId) return Promise.resolve(null);
  const datos = new FormData();
  datos.append("producto_id", String(productoId));
  datos.append("foto", archivo, "foto.jpg");
  // Sin Content-Type a mano: el navegador tiene que poner el boundary del
  // multipart.
  return fetchApi(URL_SUBIR_FOTO_PRODUCTO, {
    method: "POST",
    headers: { ...STOCKIATE_HEADERS },
    body: datos,
  })
    .then(r => r.json())
    .catch(err => {
      console.warn("No se pudo guardar la foto del producto:", err);
      return null;
    });
}

(function () {
  const ESTILOS_ID = "fpr-estilos";
  if (document.getElementById(ESTILOS_ID)) return;

  const estilo = document.createElement("style");
  estilo.id = ESTILOS_ID;
  estilo.textContent = `
    [data-foto-url]{ cursor:zoom-in; }
    .fpr-indicador{ font-size:11px; margin-left:5px; opacity:.55; vertical-align:1px; }
    .fpr-preview{
      position:fixed; z-index:999998; pointer-events:none;
      width:220px; padding:6px; border-radius:16px;
      background:var(--superficie, #fff);
      border:1px solid var(--glass-border, rgba(255,255,255,.75));
      box-shadow:0 18px 48px rgba(0,0,0,.35);
      opacity:0; transform:scale(.96); transition:opacity .12s ease, transform .12s ease;
    }
    .fpr-preview.fpr-visible{ opacity:1; transform:none; }
    .fpr-preview img{ display:block; width:100%; max-height:260px; object-fit:contain; border-radius:11px; background:#fff; }
    @media (hover: none){ .fpr-preview{ display:none; } }
  `;
  document.head.appendChild(estilo);

  let preview = null;
  let actual = null;

  function asegurarPreview() {
    if (preview) return preview;
    preview = document.createElement("div");
    preview.className = "fpr-preview";
    preview.setAttribute("aria-hidden", "true");
    preview.innerHTML = "<img alt=''>";
    document.body.appendChild(preview);
    return preview;
  }

  function ubicar(x, y) {
    const margen = 18;
    const ancho = preview.offsetWidth || 220;
    const alto = preview.offsetHeight || 240;
    let left = x + margen;
    let top = y + margen;
    // Si no entra a la derecha o abajo, se da vuelta hacia el otro lado.
    if (left + ancho > window.innerWidth - 8) left = x - ancho - margen;
    if (top + alto > window.innerHeight - 8) top = y - alto - margen;
    preview.style.left = Math.max(8, left) + "px";
    preview.style.top = Math.max(8, top) + "px";
  }

  document.addEventListener("mouseover", (e) => {
    const el = e.target.closest && e.target.closest("[data-foto-url]");
    if (!el || el === actual) return;
    actual = el;
    const p = asegurarPreview();
    const img = p.querySelector("img");
    img.src = el.dataset.fotoUrl;
    img.alt = el.textContent.trim();
    ubicar(e.clientX, e.clientY);
    p.classList.add("fpr-visible");
  });

  document.addEventListener("mousemove", (e) => {
    if (actual && preview) ubicar(e.clientX, e.clientY);
  });

  document.addEventListener("mouseout", (e) => {
    if (!actual) return;
    // Sigue adentro del mismo elemento (pasó a un hijo): no se esconde.
    if (e.relatedTarget && actual.contains(e.relatedTarget)) return;
    if (e.target.closest && e.target.closest("[data-foto-url]") !== actual) return;
    actual = null;
    if (preview) preview.classList.remove("fpr-visible");
  });

  // Al scrollear la fila se va de abajo del cursor sin disparar mouseout.
  window.addEventListener("scroll", () => {
    actual = null;
    if (preview) preview.classList.remove("fpr-visible");
  }, true);
})();
