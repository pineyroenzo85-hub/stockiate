/**
 * stockIAte - plegable.js
 * ================================
 * Los paneles plegables del panel de administrador. El dashboard tiene tres
 * bloques grandes de tabla (inventario, costos, riesgo de quiebre) y no entra
 * ninguno completo en una pantalla: poder cerrarlos es lo que hace que el panel
 * se pueda leer de un vistazo.
 *
 * POR QUÉ ESTÁ ACÁ Y NO ADENTRO DE CADA TABLA
 * -------------------------------------------
 * Arrancó adentro de `inventario_tabla.js`. Al hacer plegable el segundo panel
 * (`costos_tabla.js`) la alternativa era copiar el bloque, y con él dos cosas
 * que no conviene tener por duplicado: el try/catch de `localStorage` (que
 * existe porque en incógnito con el almacenamiento bloqueado el acceso lanza en
 * vez de devolver null) y la convención de las etiquetas del botón. Cada panel
 * sigue siendo dueño de SU CSS -- qué hijos se esconden al plegar cambia de
 * panel a panel -- y de su clave de guardado; lo compartido es sólo el
 * mecanismo.
 *
 * Uso, desde un componente que ya renderizó su panel y su botón:
 *   const estado = plegableConectar({
 *     panel: contenedor.querySelector(".cst-panel"),
 *     boton: contenedor.querySelector("[data-cst-toggle-pleg]"),
 *     clave: "stockiate_costos_plegado",
 *     clasePlegada: "cst-plegado",
 *     etiqueta: "costos",
 *   });
 *
 * El estado inicial se lee ANTES de escribir el innerHTML (con
 * `plegableLeer()`) y el markup se emite ya plegado: pintar el panel abierto
 * para cerrarlo después daría un parpadeo.
 */

/**
 * ¿Este panel arranca plegado? `plegadoPorDefecto` es lo que vale mientras
 * nadie haya tocado el botón todavía.
 */
function plegableLeer(clave, plegadoPorDefecto) {
  try {
    const guardado = localStorage.getItem(clave);
    if (guardado === null) return plegadoPorDefecto;
    return guardado === "1";
  } catch (e) {
    return plegadoPorDefecto;
  }
}

function plegableGuardar(clave, plegado) {
  try {
    localStorage.setItem(clave, plegado ? "1" : "0");
  } catch (e) {
    // Sin persistencia se pierde la preferencia entre cargas, pero el botón
    // tiene que seguir andando dentro de esta sesión.
  }
}

/**
 * Texto del botón. El triángulo apunta a lo que va a pasar si se lo toca, no a
 * lo que está pasando ahora.
 */
function plegableEtiqueta(plegado, etiqueta) {
  return (plegado ? "▾ Mostrar " : "▴ Ocultar ") + etiqueta;
}

/**
 * Cablea un panel ya renderizado. El estado inicial se toma de la clase que el
 * markup ya trae, así que quien llama no puede desincronizar el HTML del
 * comportamiento.
 *
 * @param {{panel: HTMLElement, boton: HTMLElement, clave: string,
 *          clasePlegada: string, etiqueta: string}} opciones
 * @returns {{estaPlegado: () => boolean}}
 */
function plegableConectar(opciones) {
  const { panel, boton, clave, clasePlegada, etiqueta } = opciones;
  let plegado = panel.classList.contains(clasePlegada);

  function pintar() {
    panel.classList.toggle(clasePlegada, plegado);
    boton.textContent = plegableEtiqueta(plegado, etiqueta);
    boton.setAttribute("aria-expanded", String(!plegado));
  }

  boton.addEventListener("click", () => {
    plegado = !plegado;
    pintar();
    plegableGuardar(clave, plegado);
  });

  pintar();

  return { estaPlegado: () => plegado };
}
