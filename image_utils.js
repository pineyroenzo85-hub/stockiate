/**
 * stockIAte - image_utils.js
 * ============================
 * Resize/compresión del lado del cliente antes de subir la foto: reduce el
 * tamaño del payload que cruza el túnel (ayuda con la latencia) y normaliza
 * el input que llega al modelo. Función pura, sin estado ni conocimiento de
 * rol -- compartida entre repositor.html y cajero.html (mismo criterio que
 * ya usa inventario_tabla.js para la tabla de inventario).
 */

const IMGU_LADO_MAXIMO = 1280;
const IMGU_CALIDAD_JPEG = 0.8;

// Devuelve un Blob JPEG redimensionado (lado mayor <= 1280px, sin agrandar
// imágenes más chicas). Si cualquier paso falla, devuelve el archivo
// original sin tocar -- nunca bloquea la carga (offline-first).
async function redimensionarImagen(archivo) {
  try {
    const bitmap = await imguObtenerBitmap(archivo);
    const { width, height } = imguCalcularDimensiones(bitmap.width, bitmap.height);

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    canvas.getContext("2d").drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
      canvas.toBlob(resolve, "image/jpeg", IMGU_CALIDAD_JPEG);
    });
    return blob || archivo;
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
