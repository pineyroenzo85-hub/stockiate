const URL_BACKEND_IA = "http://localhost:8000/procesar-imagen";
const URL_GUARDAR_STOCK = "http://localhost/stockiate/guardar_stock.php";

// Catálogo de prueba para el selector desplegable
const CATALOGO_DEMO = [
  { id: 12, nombre: "Fragancia A · 100ml" },
  { id: 13, nombre: "Fragancia B · 100ml" },
  { id: 14, nombre: "Crema hidratante · 200g" },
  { id: 15, nombre: "Labial mate · Rojo" },
];

const pantallas = {
  captura: document.getElementById("pantalla-captura"),
  procesando: document.getElementById("pantalla-procesando"),
  validacion: document.getElementById("pantalla-validacion"),
  confirmacion: document.getElementById("pantalla-confirmacion"),
};

function mostrarPantalla(nombre) {
  Object.values(pantallas).forEach(p => p.classList.remove("activa"));
  pantallas[nombre].classList.add("activa");
}

const inputImagen = document.getElementById("input-imagen");
const zonaVacia = document.getElementById("zona-captura-vacia");
const previewImagen = document.getElementById("preview-imagen");
const btnProcesar = document.getElementById("btn-procesar");
const bannerOffline = document.getElementById("banner-offline");

let archivoSeleccionado = null;

// Escucha cuando el usuario sube o saca una foto
inputImagen.addEventListener("change", (e) => {
  const archivo = e.target.files[0];
  if (!archivo) return;
  archivoSeleccionado = archivo;

  const lector = new FileReader();
  lector.onload = (ev) => {
    previewImagen.src = ev.target.result;
    previewImagen.style.display = "block";
    zonaVacia.style.display = "none";
  };
  lector.readAsDataURL(archivo);

  btnProcesar.disabled = false;
});

// Carga Manual Directa
document.getElementById("btn-manual").addEventListener("click", () => {
  irAValidacionManual();
});

// Petición al Servidor de Python (FastAPI + Roboflow)
btnProcesar.addEventListener("click", async () => {
  if (!archivoSeleccionado) return;
  mostrarPantalla("procesando");
  bannerOffline.classList.remove("activo");

  const formData = new FormData();
  formData.append("imagen", archivoSeleccionado);

  try {
    const resp = await fetch(URL_BACKEND_IA, { method: "POST", body: formData });
    if (!resp.ok) throw new Error("Error en el servidor de IA");
    
    const data = await resp.json();

    // Si Roboflow falló o no está entrenado, el backend avisa para ir a modo offline
    if (data.modo_offline) {
      bannerOffline.classList.add("activo");
      mostrarPantalla("captura");
      return;
    }

    // Si todo salió bien, dibuja las tarjetas azules en la pantalla de validación
    renderizarValidacion(data.detecciones || []);
    mostrarPantalla("validacion");
  } catch (err) {
    console.error(err);
    bannerOffline.classList.add("activo");
    mostrarPantalla("captura");
  }
});

const listaItems = document.getElementById("lista-items");
const resumenCantidad = document.getElementById("resumen-cantidad");
let contadorItems = 0;

function claseConfianza(conf) {
  if (conf >= 0.85) return { clase: "conf-alta", texto: "Alta confianza" };
  if (conf >= 0.6) return { clase: "conf-media", texto: "Revisar" };
  return { clase: "conf-baja", texto: "Baja confianza" };
}

function opcionesCatalogo(seleccionadoTexto) {
  return CATALOGO_DEMO.map(p =>
    `<option value="${p.id}" ${p.nombre === seleccionadoTexto ? "selected" : ""}>${p.nombre}</option>`
  ).join("");
}

// Crea dinámicamente las tarjetas de la Red de Seguridad
function crearItemCard({ clase = "", confianza = null, cantidad = 1 }) {
  contadorItems++;
  const id = `item-${contadorItems}`;
  const conf = confianza !== null ? claseConfianza(confianza) : null;

  const card = document.createElement("div");
  card.className = "item-card";
  card.dataset.id = id;
  card.innerHTML = `
    <div class="item-card-top">
      <label style="margin:0;">Producto detectado</label>
      ${conf ? `<span class="item-confianza ${conf.clase}">${conf.texto}</span>` : `<span class="item-confianza conf-media">Manual</span>`}
    </div>
    <select class="select-producto">
      ${opcionesCatalogo(clase)}
    </select>
    <div class="fila-doble">
      <div>
        <label>Cantidad</label>
        <input type="number" class="input-cantidad" value="${cantidad}" min="1">
      </div>
      <div>
        <label>Vencimiento <span class="campo-opcional-tag">opcional</span></label>
        <input type="date" class="input-vencimiento">
      </div>
    </div>
  `;
  listaItems.appendChild(card);

  card.querySelector(".input-cantidad").addEventListener("input", actualizarResumen);
}

function renderizarValidacion(detecciones) {
  listaItems.innerHTML = "";
  contadorItems = 0;

  if (detecciones.length === 0) {
    crearItemCard({});
  } else {
    const agrupado = {};
    detecciones.forEach(d => {
      agrupado[d.clase] = agrupado[d.clase] || { cantidad: 0, confianza: d.confianza };
      agrupado[d.clase].cantidad++;
      agrupado[d.clase].confianza = Math.min(agrupado[d.clase].confianza, d.confianza);
    });
    Object.entries(agrupado).forEach(([clase, info]) => {
      crearItemCard({ clase, confianza: info.confianza, cantidad: info.cantidad });
    });
  }
  actualizarResumen();
}

function irAValidacionManual() {
  renderizarValidacion([]);
  mostrarPantalla("validacion");
}

document.getElementById("btn-agregar-item").addEventListener("click", () => {
  crearItemCard({});
  actualizarResumen();
});

function actualizarResumen() {
  let total = 0;
  document.querySelectorAll(".input-cantidad").forEach(inp => {
    total += parseInt(inp.value || "0", 10);
  });
  resumenCantidad.textContent = total;
}

// Envío final de los datos validados a PHP / MySQL
document.getElementById("btn-confirmar").addEventListener("click", async () => {
  const cards = document.querySelectorAll(".item-card");
  const usuarioId = 1;

  const pedidos = Array.from(cards).map(card => {
    const productoId = parseInt(card.querySelector(".select-producto").value, 10);
    const cantidad = parseInt(card.querySelector(".input-cantidad").value || "0", 10);
    const vencimiento = card.querySelector(".input-vencimiento").value || null;
    return { producto_id: productoId, cantidad, fecha_vencimiento: vencimiento, usuario_id: usuarioId };
  });

  try {
    for (const pedido of pedidos) {
      await fetch(URL_GUARDAR_STOCK, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(pedido),
      });
    }
    document.getElementById("texto-confirmacion").textContent =
      `Se cargaron ${resumenCantidad.textContent} unidades en ${pedidos.length} producto(s).`;
    mostrarPantalla("confirmacion");
  } catch (err) {
    alert("No se pudo guardar el stock. Verificá la conexión con el servidor local (XAMPP).");
  }
});

document.getElementById("btn-nueva-carga").addEventListener("click", () => {
  archivoSeleccionado = null;
  previewImagen.style.display = "none";
  zonaVacia.style.display = "block";
  btnProcesar.disabled = true;
  inputImagen.value = "";
  mostrarPantalla("captura");
});