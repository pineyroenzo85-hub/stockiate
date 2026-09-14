/**
 * stockIAte - image_utils.js
 * ============================
 * Resize/compresión del lado del cliente antes de subir la foto: reduce el
 * tamaño del payload que cruza el túnel (ayuda con la latencia). Función
 * pura, sin estado ni conocimiento de rol -- compartida entre repositor.html
 * y cajero.html (mismo criterio que ya usa inventario_tabla.js para la tabla
 * de inventario).
 *
 * ⚠️ COMPRIMIR DE MÁS ROMPE LA DETECCIÓN, NO SÓLO EL OCR.
 * Antes esta función re-encodeaba SIEMPRE a JPEG calidad 0.8, incluso cuando
 * la foto ya entraba en el límite y no había nada que achicar. Medido contra
 * `images/hawas.png` (340x491, por debajo de los 1280px):
 *
 *   original PNG 180.787 b -> 1 detección  ("HAWAS For Him BLACK")
 *   JPEG q=1.00 119.823 b  -> 1 detección
 *   JPEG q=0.92  34.314 b  -> 0 detecciones
 *   JPEG q=0.80  22.097 b  -> 0 detecciones   <- lo que subía la app
 *
 * Ocho veces más chica, cero bytes de beneficio real (ya era chica), y el
 * modelo dejaba de ver el envase: el síntoma es "la IA no detecta nada" con
 * fotos que antes andaban. Como el corte está entre 0.92 y 1.00, cada foto
 * cae de un lado según su contenido -- por eso fallaba de forma intermitente.
 *
 * De ahí las dos reglas de abajo: no tocar lo que no hay que achicar, y no
 * quedarse con un re-encode que no achicó nada.
 */

const IMGU_LADO_MAXIMO = 1280;
// 0.9 y no 0.8: sólo se aplica a fotos que SÍ se están achicando (las de
// celular, de 3000px para arriba), donde el ahorro de payload es enorme igual
// y no hace falta apretar tanto.
const IMGU_CALIDAD_JPEG = 0.9;

// Devuelve la foto lista para subir. Si hay que achicarla (lado mayor >
// 1280px), devuelve un Blob JPEG redimensionado; si no, devuelve el archivo
// ORIGINAL tal cual. Si cualquier paso falla, también devuelve el original --
// nunca bloquea la carga (offline-first).
async function redimensionarImagen(archivo) {
  try {
    const bitmap = await imguObtenerBitmap(archivo);
    const { width, height } = imguCalcularDimensiones(bitmap.width, bitmap.height);

    // Regla 1: si ya entra en el límite, se sube tal cual. Re-encodear acá no
    // ahorra nada y es justo lo que rompía la detección (ver el comentario de
    // arriba).
    if (width === bitmap.width && height === bitmap.height) {
      return archivo;
    }

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    canvas.getContext("2d").drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
      canvas.toBlob(resolve, "image/jpeg", IMGU_CALIDAD_JPEG);
    });

    // Regla 2: si el re-encode no salió más chico que el original, no vale la
    // pena pagar la pérdida de calidad. Pasa con fotos apenas por encima del
    // límite que ya venían bien comprimidas.
    if (!blob || blob.size >= archivo.size) {
      return archivo;
    }

    return blob;
  } catch (err) {
    console.warn("No se pudo redimensionar la imagen, se sube el original:", err);
    return archivo;
  }
}

// createImageBitmap con imageOrientation:'from-image' respeta la rotación
// EXIF de fotos de celular. Si el navegador no lo soporta, cae a <img> +
// object URL, que también respeta EXIF al dibujarse en canvas.
async function imguObtenerBitmap(archivo) {
  if (window.createImageBitmap) {
    try {
      return await createImageBitmap(archivo, { imageOrientation: "from-image" });
    } catch (err) {
      // sigue al fallback de abajo
    }
  }
  return await new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(archivo);
    img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = (err) => { URL.revokeObjectURL(url); reject(err); };
    img.src = url;
  });
}

function imguCalcularDimensiones(anchoOriginal, altoOriginal) {
  const ladoMayor = Math.max(anchoOriginal, altoOriginal);
  if (ladoMayor <= IMGU_LADO_MAXIMO) {
    return { width: anchoOriginal, height: altoOriginal };
  }
  const escala = IMGU_LADO_MAXIMO / ladoMayor;
  return {
    width: Math.round(anchoOriginal * escala),
    height: Math.round(altoOriginal * escala),
  };
}
