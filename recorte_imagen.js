/**
 * stockIAte - recorte_imagen.js
 * ================================
 * Recortador táctil que se abre después de sacar la foto y antes de mandarla
 * al detector. El usuario mueve y estira un recuadro con el dedo para dejar
 * sólo los productos adentro.
 *
 * POR QUÉ EXISTE
 * --------------
 * El detector trabaja sobre la foto entera: todo lo que entre en cuadro
 * (el piso, una mano, cosas del mostrador) compite con los productos. Dejar
 * que la persona encuadre antes de enviar es la forma más barata de subir la
 * tasa de acierto, porque nadie sabe mejor que ella qué está tratando de
 * cargar.
 *
 * Medido sobre `images/hawas.png`, el recorte además LIMPIA el OCR:
 *
 *     foto entera                -> 1 det, OCR "حسن HAWAS For Him"
 *     recorte 8%..92% (default)  -> 1 det, OCR "HAWAS For Him BLACK"  <- mejor
 *     recorte 2%..98%            -> 1 det, OCR "HAQAS For Him BLACK"
 *     recorte pegado a la etiqueta -> 0 detecciones
 *
 * ⚠️ EL ENVASE TIENE QUE QUEDAR ENTERO ADENTRO. Recortar por dentro del
 * producto (sólo la etiqueta) lo rompe: el detector busca un objeto completo,
 * y un pedazo de frasco sin bordes no lo es. Por eso el recuadro arranca en
 * 8%..92% (bien holgado) y el texto de ayuda lo dice explícitamente en vez de
 * dejar que la persona lo descubra a fuerza de fallar.
 *
 * ⚠️ LA CALIDAD DEL JPEG NO ES NEGOCIABLE
 * Recortar obliga a re-encodear, y este modelo es muy sensible a los
 * artefactos del JPEG. Medido sobre el mismo recorte de `hawas.png`:
 *
 *     q=0.80 ->  16 KB -> 0 detecciones
 *     q=0.90 ->  23 KB -> 0 detecciones
 *     q=0.95 ->  32 KB -> 1 deteccion
 *     q=1.00 ->  90 KB -> 1 deteccion
 *
 * El piso medido es 0.95; abajo de eso el recorte se ve bien a simple vista
 * pero el modelo deja de detectar. Se usa 0.97 para tener margen, porque el
 * corte puede caer un poco más arriba en otras fotos y el costo en bytes es
 * despreciable. **Si tocás RCI_CALIDAD_JPEG, re-medí la detección, no el
 * peso** -- es exactamente la trampa en la que ya caímos con
 * `image_utils.js`.
 *
 * El recorte sale ya escalado a RCI_LADO_MAXIMO, así que `redimensionarImagen()`
 * lo deja pasar tal cual y hay UN solo re-encode en todo el pipeline.
 *
 * Uso:
 *   const blob = await abrirRecortador(archivo);   // siempre devuelve algo
 *   // blob === archivo  si la persona eligió "Usar toda la foto"
 *
 * Autocontenido: inyecta su propio <style>, con prefijo "rci-" para no chocar
 * con styles.css (mismo criterio que inventario_tabla.js y costos_tabla.js).
 */

const RCI_CALIDAD_JPEG = 0.97;
// Mismo límite que image_utils.js: el recorte sale listo para subir.
const RCI_LADO_MAXIMO = 1280;
// Lado mínimo del recuadro, en fracción de la imagen. Evita que un toque
// torpe deje un recorte de 3 píxeles.
const RCI_MINIMO = 0.12;
// Lado mínimo del recuadro, pero en PÍXELES REALES de la foto original (no
// relativo). El 12% de arriba alcanza en una foto de celular normal, pero si
// la foto de origen ya es chica (poca resolución, comprimida, bajada de
// WhatsApp) el 12% puede dar un recorte de menos de 100px de lado -- ahí el
// texto de la etiqueta deja de ser legible y glm_ocr no lee nada.
// ⚠️ Este número (400px) es una estimación conservadora de legibilidad de
// texto para OCR/VLM, NO una medición hecha sobre este proyecto -- acá no
// hay un equivalente a la tabla de RCI_CALIDAD_JPEG (ver el comentario de
// arriba del archivo) porque variar el tamaño del recorte manual no se
// probó todavía contra glm_ocr. Si algún día se corre esa medición (mismo
// método: la misma foto, recortes de distinto tamaño, comparar si
// texto_limpio sale vacío), ajustar este valor con el resultado real.
const RCI_MINIMO_PIXELES = 400;

const RCI_ESTILOS_ID = "rci-estilos";
// El overlay siempre queda oscuro a propósito -- sin importar el tema de la
// app -- porque la máscara semitransparente necesita ese contraste para leer
// claramente qué queda afuera del recuadro. Colores fijos, no var(--...).
const RCI_CSS = `
/* z-index por encima del widget del chatbot (999999 en chatbot_widget.js):
   su botón flotante tapaba "Recortar y analizar" en pantalla de celular. */
.rci-overlay{ position:fixed; inset:0; z-index:1000001; background:#1A1625; display:flex; flex-direction:column; touch-action:none; font-family:'Plus Jakarta Sans','Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
.rci-head{ padding:16px 20px; color:#F2EDF9; flex-shrink:0; }
.rci-head h2{ margin:0; font-size:16px; font-weight:800; }
.rci-head p{ margin:4px 0 0; font-size:12.5px; color:#A99BC0; line-height:1.45; }
.rci-area{ flex:1; position:relative; display:flex; align-items:center; justify-content:center; overflow:hidden; padding:8px; min-height:0; }
.rci-lienzo{ position:relative; touch-action:none; user-select:none; -webkit-user-select:none; }
.rci-lienzo img{ display:block; width:100%; height:100%; pointer-events:none; }
.rci-mascara{ position:absolute; inset:0; pointer-events:none; }
.rci-mascara div{ position:absolute; background:rgba(26,22,37,.78); }
.rci-caja{ position:absolute; border:2px solid #E8C468; box-shadow:0 0 0 9999px rgba(0,0,0,0); cursor:move; }
.rci-caja::before{ content:""; position:absolute; inset:0; background:
  linear-gradient(#E8C468,#E8C468) 33.3% 0/1px 100% no-repeat,
  linear-gradient(#E8C468,#E8C468) 66.6% 0/1px 100% no-repeat,
  linear-gradient(#E8C468,#E8C468) 0 33.3%/100% 1px no-repeat,
  linear-gradient(#E8C468,#E8C468) 0 66.6%/100% 1px no-repeat;
  opacity:.4; pointer-events:none; }
.rci-tirador{ position:absolute; width:44px; height:44px; display:flex; align-items:center; justify-content:center; }
.rci-tirador::after{ content:""; width:20px; height:20px; border:3px solid #E8C468; background:rgba(26,22,37,.5); border-radius:3px; }
.rci-tirador[data-esq="nw"]{ left:-22px; top:-22px; cursor:nwse-resize; }
.rci-tirador[data-esq="nw"]::after{ border-right:none; border-bottom:none; }
.rci-tirador[data-esq="ne"]{ right:-22px; top:-22px; cursor:nesw-resize; }
.rci-tirador[data-esq="ne"]::after{ border-left:none; border-bottom:none; }
.rci-tirador[data-esq="sw"]{ left:-22px; bottom:-22px; cursor:nesw-resize; }
.rci-tirador[data-esq="sw"]::after{ border-right:none; border-top:none; }
.rci-tirador[data-esq="se"]{ right:-22px; bottom:-22px; cursor:nwse-resize; }
.rci-tirador[data-esq="se"]::after{ border-left:none; border-top:none; }
.rci-pie{ padding:14px 20px calc(14px + env(safe-area-inset-bottom)); display:flex; gap:10px; flex-shrink:0; }
.rci-btn{ flex:1; padding:14px; border-radius:16px; font-size:14px; font-weight:700; font-family:inherit; cursor:pointer; border:1px solid transparent; }
.rci-btn-primario{ background:linear-gradient(135deg,#F5E3AE 0%,#E8C468 100%); color:#3D2E08; box-shadow:0 6px 16px rgba(232,196,104,.35); }
.rci-btn-primario:active{ filter:brightness(.95); }
.rci-btn-secundario{ background:rgba(255,255,255,.06); color:#A99BC0; border-color:rgba(255,255,255,.09); }
.rci-btn-secundario:active{ color:#F2EDF9; }
`;

function rciAsegurarEstilos() {
  if (document.getElementById(RCI_ESTILOS_ID)) return;
  const style = document.createElement("style");
  style.id = RCI_ESTILOS_ID;
  style.textContent = RCI_CSS;
  document.head.appendChild(style);
}

/**
 * Abre el recortador sobre `archivo` y resuelve con lo que haya que subir.
 *
 * Siempre resuelve con un Blob usable: si la persona toca "Usar toda la
 * foto", o si algo falla (imagen ilegible, canvas bloqueado), devuelve el
 * archivo original sin tocar. Nunca rechaza -- el flujo de carga no se puede
 * frenar porque el recortador tenga un problema (mismo criterio offline-first
 * que el resto de la app).
 */
async function abrirRecortador(archivo) {
  rciAsegurarEstilos();

  let bitmap;
  try {
    bitmap = await createImageBitmap(archivo, { imageOrientation: "from-image" });
  } catch (err) {
    console.warn("No se pudo abrir el recortador, se sube la foto entera:", err);
    return archivo;
  }

  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.className = "rci-overlay";
    overlay.innerHTML = `
      <div class="rci-head">
        <h2>Encuadrá los productos</h2>
        <p>Movés el recuadro con el dedo y lo estirás de las esquinas. Dejá los envases <b>enteros</b> adentro y sacá el fondo de alrededor. Ojo: si cortás por dentro del producto, la IA deja de reconocerlo.</p>
      </div>
      <div class="rci-area">
        <div class="rci-lienzo" data-rci-lienzo>
          <img alt="Foto a recortar">
          <div class="rci-mascara" data-rci-mascara>
            <div data-lado="arriba"></div><div data-lado="abajo"></div>
            <div data-lado="izq"></div><div data-lado="der"></div>
          </div>
          <div class="rci-caja" data-rci-caja>
            <div class="rci-tirador" data-esq="nw"></div>
            <div class="rci-tirador" data-esq="ne"></div>
            <div class="rci-tirador" data-esq="sw"></div>
            <div class="rci-tirador" data-esq="se"></div>
          </div>
        </div>
      </div>
      <div class="rci-pie">
        <button type="button" class="rci-btn rci-btn-secundario" data-rci-todo>Usar toda la foto</button>
        <button type="button" class="rci-btn rci-btn-primario" data-rci-ok>Recortar y analizar</button>
      </div>
    `;
    document.body.appendChild(overlay);

    const area = overlay.querySelector(".rci-area");
    const lienzo = overlay.querySelector("[data-rci-lienzo]");
    const img = lienzo.querySelector("img");
    const caja = overlay.querySelector("[data-rci-caja]");
    const mascara = overlay.querySelector("[data-rci-mascara]");

    img.src = URL.createObjectURL(archivo);

    // Mínimo real del recuadro en cada eje, como fracción -- el mayor entre
    // el piso relativo (RCI_MINIMO) y el piso en píxeles (RCI_MINIMO_PIXELES)
    // convertido a fracción de ESTA imagen. En una foto de celular normal
    // (3000px+ de lado) manda el piso relativo; en una foto ya chica manda el
    // de píxeles. El tope en 0.9 es sólo para que una foto diminuta (<445px)
    // no deje un mínimo mayor al recuadro máximo posible.
    const minFraccX = Math.min(0.9, Math.max(RCI_MINIMO, RCI_MINIMO_PIXELES / bitmap.width));
    const minFraccY = Math.min(0.9, Math.max(RCI_MINIMO, RCI_MINIMO_PIXELES / bitmap.height));

    // El recuadro se guarda en FRACCIONES de la imagen (0..1), no en píxeles
    // de pantalla: así sobrevive a que el celular rote o cambie de tamaño.
    const anInicial = Math.max(0.84, minFraccX);
    const alInicial = Math.max(0.84, minFraccY);
    let rect = {
      // Si el mínimo agrandó el recuadro más allá del 8%..92% default, hay
      // que recentrarlo -- si no, arrancaría saliéndose del borde derecho o
      // inferior de la imagen.
      x: Math.min(0.08, 1 - anInicial),
      y: Math.min(0.08, 1 - alInicial),
      an: anInicial,
      al: alInicial,
    };

    // El lienzo se dimensiona al tamaño exacto que ocupa la imagen dentro del
    // área disponible. Con eso, las coordenadas de pantalla del recuadro son
    // 1:1 con el lienzo y no hay que compensar el letterboxing.
    function ajustarLienzo() {
      const dispAn = area.clientWidth - 16;
      const dispAl = area.clientHeight - 16;
      if (dispAn <= 0 || dispAl <= 0) return;
      const escala = Math.min(dispAn / bitmap.width, dispAl / bitmap.height);
      lienzo.style.width = Math.round(bitmap.width * escala) + "px";
      lienzo.style.height = Math.round(bitmap.height * escala) + "px";
      pintar();
    }

    function pintar() {
      const an = lienzo.clientWidth, al = lienzo.clientHeight;
      const izq = rect.x * an, arr = rect.y * al;
      const anc = rect.an * an, alt = rect.al * al;

      caja.style.left = izq + "px";
      caja.style.top = arr + "px";
      caja.style.width = anc + "px";
      caja.style.height = alt + "px";

      // Cuatro rectángulos oscuros alrededor del recuadro. Es más simple y
      // más compatible que un clip-path, y no depende de box-shadow gigante.
      const [arriba, abajo, i, d] = mascara.children;
      arriba.style.cssText = `left:0;top:0;width:100%;height:${arr}px`;
      abajo.style.cssText = `left:0;top:${arr + alt}px;width:100%;bottom:0`;
      i.style.cssText = `left:0;top:${arr}px;width:${izq}px;height:${alt}px`;
      d.style.cssText = `left:${izq + anc}px;top:${arr}px;right:0;height:${alt}px`;
    }

    // --- arrastre (mover el recuadro entero o estirar de una esquina) ---
    let gesto = null;

    function alBajar(ev) {
      const tirador = ev.target.closest(".rci-tirador");
      if (!tirador && !ev.target.closest("[data-rci-caja]")) return;
      ev.preventDefault();
      gesto = {
        esquina: tirador ? tirador.dataset.esq : null,
        x0: ev.clientX,
        y0: ev.clientY,
        inicial: { ...rect },
      };
      lienzo.setPointerCapture(ev.pointerId);
    }

    function alMover(ev) {
      if (!gesto) return;
      ev.preventDefault();
      const an = lienzo.clientWidth, al = lienzo.clientHeight;
      const dx = (ev.clientX - gesto.x0) / an;
      const dy = (ev.clientY - gesto.y0) / al;
      const ini = gesto.inicial;

      if (!gesto.esquina) {
        // Mover: se limita para que el recuadro no salga de la imagen.
        rect.x = Math.min(Math.max(ini.x + dx, 0), 1 - ini.an);
        rect.y = Math.min(Math.max(ini.y + dy, 0), 1 - ini.al);
      } else {
        const e = gesto.esquina;
        let x1 = ini.x, y1 = ini.y, x2 = ini.x + ini.an, y2 = ini.y + ini.al;
        if (e === "nw" || e === "sw") x1 = Math.min(Math.max(ini.x + dx, 0), x2 - minFraccX);
        if (e === "ne" || e === "se") x2 = Math.max(Math.min(ini.x + ini.an + dx, 1), x1 + minFraccX);
        if (e === "nw" || e === "ne") y1 = Math.min(Math.max(ini.y + dy, 0), y2 - minFraccY);
        if (e === "sw" || e === "se") y2 = Math.max(Math.min(ini.y + ini.al + dy, 1), y1 + minFraccY);
        rect = { x: x1, y: y1, an: x2 - x1, al: y2 - y1 };
      }
      pintar();
    }

    function alSoltar() { gesto = null; }

    lienzo.addEventListener("pointerdown", alBajar);
    lienzo.addEventListener("pointermove", alMover);
    lienzo.addEventListener("pointerup", alSoltar);
    lienzo.addEventListener("pointercancel", alSoltar);

    const alRedimensionar = () => ajustarLienzo();
    window.addEventListener("resize", alRedimensionar);
    window.addEventListener("orientationchange", alRedimensionar);

    function cerrar(resultado) {
      window.removeEventListener("resize", alRedimensionar);
      window.removeEventListener("orientationchange", alRedimensionar);
      URL.revokeObjectURL(img.src);
      overlay.remove();
      bitmap.close && bitmap.close();
      resolve(resultado);
    }

    overlay.querySelector("[data-rci-todo]").addEventListener("click", () => cerrar(archivo));

    overlay.querySelector("[data-rci-ok]").addEventListener("click", async () => {
      try {
        cerrar(await rciRecortar(bitmap, rect));
      } catch (err) {
        console.warn("Falló el recorte, se sube la foto entera:", err);
        cerrar(archivo);
      }
    });

    img.onload = ajustarLienzo;
    // Si la imagen ya estaba en caché, onload puede no dispararse.
    if (img.complete) ajustarLienzo();
  });
}

/**
 * Recorta y escala en UNA sola pasada de canvas: dos operaciones separadas
 * significarían dos re-encodes, y cada uno se come calidad que el detector
 * necesita (ver el bloque de arriba).
 */
async function rciRecortar(bitmap, rect) {
  const sx = Math.round(rect.x * bitmap.width);
  const sy = Math.round(rect.y * bitmap.height);
  const sAn = Math.round(rect.an * bitmap.width);
  const sAl = Math.round(rect.al * bitmap.height);

  const mayor = Math.max(sAn, sAl);
  const escala = mayor > RCI_LADO_MAXIMO ? RCI_LADO_MAXIMO / mayor : 1;

  const canvas = document.createElement("canvas");
  canvas.width = Math.round(sAn * escala);
  canvas.height = Math.round(sAl * escala);
  canvas.getContext("2d").drawImage(bitmap, sx, sy, sAn, sAl, 0, 0, canvas.width, canvas.height);

  const blob = await new Promise((r) => canvas.toBlob(r, "image/jpeg", RCI_CALIDAD_JPEG));
  if (!blob) throw new Error("canvas.toBlob devolvió null");

  // A diferencia de image_utils.js, acá NO se descarta el resultado si pesa
  // más que el original: el recorte es contenido distinto, es lo que la
  // persona acaba de encuadrar a propósito. Devolver la foto entera porque
  // pesaba menos sería ignorar lo que pidió.
  return blob;
}
