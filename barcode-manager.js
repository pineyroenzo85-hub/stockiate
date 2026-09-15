/**
 * barcode-manager.js
 * ============================================================
 * El lector de código de barras es una HERRAMIENTA OPCIONAL: no vive
 * en la pantalla principal de Cámara/IA. Solo se activa si el usuario
 * lo prendió en "Ajustes > Periféricos y Herramientas".
 *
 * Este módulo se importa una sola vez en el bootstrap de la app (desde
 * ajustes.html, donde vive el UI de configuración) y decide, según lo
 * que haya en localStorage, si debe escuchar el teclado o no.
 */

// ---------------------------------------------------------------
// Persistencia de ajustes
// ---------------------------------------------------------------
const CLAVES_AJUSTES = {
  activo: 'stockiate_periferico_lector_activo',
  autoConfirmar: 'stockiate_periferico_auto_confirmar',
  sonido: 'stockiate_periferico_sonido',
  modoEntrada: 'stockiate_periferico_modo_entrada', // 'hid' | 'serial'
};

class BarcodeSettings {
  static leer() {
    return {
      activo: localStorage.getItem(CLAVES_AJUSTES.activo) === 'true',
      autoConfirmar: localStorage.getItem(CLAVES_AJUSTES.autoConfirmar) !== 'false',
      sonido: localStorage.getItem(CLAVES_AJUSTES.sonido) !== 'false',
      modoEntrada: localStorage.getItem(CLAVES_AJUSTES.modoEntrada) || 'hid',
    };
  }

  static guardar(parciales) {
    Object.entries(parciales).forEach(([clave, valor]) => {
      if (clave in CLAVES_AJUSTES) localStorage.setItem(CLAVES_AJUSTES[clave], String(valor));
    });
  }
}

// ---------------------------------------------------------------
// Gestor del lector físico (modo HID = teclado, modo Serial = puerto serie)
// ---------------------------------------------------------------
class BarcodeManager {
  /**
   * @param {Object} opts
   * @param {(codigo: string) => void} opts.onScan   Callback al detectar un código válido
   * @param {number} opts.minLength      Largo mínimo del código
   * @param {number} opts.maxIntervalMs  Intervalo máx (ms) entre teclas propio de una pistola
   * @param {number} opts.timeoutMs      Si no llega Enter en este tiempo, se descarta el buffer
   * @param {string} opts.statusElementId  Id del badge de estado en la UI de ajustes (opcional)
   */
  constructor({ onScan, minLength = 4, maxIntervalMs = 40, timeoutMs = 300, statusElementId = 'perif-estado-lector' } = {}) {
    this.onScan = onScan;
    this.minLength = minLength;
    this.maxIntervalMs = maxIntervalMs;
    this.timeoutMs = timeoutMs;
    this.statusElementId = statusElementId;

    this.buffer = '';
    this.lastKeyTime = 0;
    this.timeoutId = null;
    this.hidActivo = false;
    this.serialPort = null;

    this._handleKeydown = this._handleKeydown.bind(this);
  }

  /** Llamar una vez al cargar la página de Ajustes (o al cambiar preferencias). */
  iniciarSegunAjustes() {
    const { activo, modoEntrada } = BarcodeSettings.leer();
    if (!activo) {
      this._setEstado('inactivo');
      return;
    }
    if (modoEntrada === 'serial') {
      // La Web Serial API exige un gesto explícito del usuario para conectar
      // el puerto — no se puede auto-conectar al cargar la página. Queda
      // "armado" hasta que el usuario aprieta "Conectar puerto" en Ajustes.
      this._setEstado('esperando-serial');
    } else {
      this._iniciarHID();
    }
  }

  /** Apaga cualquier listener activo (se llama al desactivar el toggle en Ajustes). */
  detener() {
    document.removeEventListener('keydown', this._handleKeydown, true);
    this.hidActivo = false;
    this._clearBuffer();
    this._setEstado('inactivo');
    if (this.serialPort) {
      this.serialPort.close().catch(() => {});
      this.serialPort = null;
    }
  }

  // ---- Modo HID: la pistola se ve como un teclado ----
  _iniciarHID() {
    if (this.hidActivo) return;
    document.addEventListener('keydown', this._handleKeydown, true);
    this.hidActivo = true;
    this._setEstado('listo');
  }

  _handleKeydown(e) {
    const now = Date.now();
    const delta = now - this.lastKeyTime;
    this.lastKeyTime = now;

    // Ráfaga de pistola = teclas muy seguidas. Si el intervalo es grande,
    // era tipeo humano suelto: reiniciamos el buffer.
    if (delta > this.maxIntervalMs && this.buffer.length > 0) this._clearBuffer();

    if (e.key === 'Enter') {
      if (this.buffer.length >= this.minLength) {
        e.preventDefault();
        e.stopPropagation();
        this._emitScan(this.buffer);
      }
      this._clearBuffer();
      return;
    }
    if (e.key.length === 1) {
      this.buffer += e.key;
      this._resetTimeout();
    }
  }

  _resetTimeout() {
    clearTimeout(this.timeoutId);
    this.timeoutId = setTimeout(() => this._clearBuffer(), this.timeoutMs);
  }
  _clearBuffer() { this.buffer = ''; clearTimeout(this.timeoutId); }

  // ---- Modo Serial: escáner conectado por puerto serie ----
  /** Debe dispararse desde un click real (botón "Conectar puerto" en Ajustes). */
  async conectarPuertoSerial() {
    if (!('serial' in navigator)) {
      alert('Este navegador no soporta la Web Serial API (usá Chrome/Edge de escritorio).');
      return;
    }
    try {
      this.serialPort = await navigator.serial.requestPort();
      await this.serialPort.open({ baudRate: 9600 });
      this._setEstado('listo');
      this._leerSerial();
    } catch (err) {
      console.error('No se pudo conectar el puerto serial:', err);
      this._setEstado('inactivo');
    }
  }

  async _leerSerial() {
    const decoder = new TextDecoderStream();
    this.serialPort.readable.pipeTo(decoder.writable);
    const lector = decoder.readable.getReader();
    let bufferSerial = '';
    try {
      while (true) {
        const { value, done } = await lector.read();
        if (done) break;
        bufferSerial += value;
        if (bufferSerial.includes('\n') || bufferSerial.includes('\r')) {
          const codigo = bufferSerial.trim();
          if (codigo.length >= this.minLength) this._emitScan(codigo);
          bufferSerial = '';
        }
      }
    } catch (err) {
      console.error('Error leyendo el puerto serial:', err);
    }
  }

  // ---- Común a ambos modos ----
  _emitScan(codigo) {
    const { sonido } = BarcodeSettings.leer();
    if (sonido) this._reproducirBip();
    this._setEstado('leyendo');
    if (typeof this.onScan === 'function') this.onScan(codigo);
    setTimeout(() => this._setEstado('listo'), 400);
  }

  _reproducirBip() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 880;
      gain.gain.value = 0.08;
      osc.connect(gain).connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.09);
    } catch (_) { /* si el navegador bloquea audio autónomo, no es crítico */ }
  }

  _setEstado(estado) {
    const el = document.getElementById(this.statusElementId);
    if (!el) return;
    const estados = {
      listo: { texto: '🟢 Conectado / Escuchando', clase: 'perif-badge-on' },
      leyendo: { texto: '🔵 Leyendo código...', clase: 'perif-badge-on' },
      'esperando-serial': { texto: '🟡 Activado — conectá el puerto', clase: 'perif-badge-pendiente' },
      inactivo: { texto: '⚪ Desactivado', clase: 'perif-badge-off' },
    };
    const e = estados[estado] || estados.inactivo;
    el.textContent = e.texto;
    el.className = 'perif-badge ' + e.clase;
  }
}

// ---------------------------------------------------------------
// Procesamiento de un código leído, según el rol de la sesión
// ---------------------------------------------------------------
// `rol` y `negocio_id` NO se mandan en el body: buscar_producto.php los saca
// de la cookie de sesión (ver sesion.php -> exigir_sesion()), igual que
// registrar_venta.php y guardar_stock.php. Acá sólo se usa `usuarioSesion.rol`
// para decidir qué texto mostrar en la UI, no para autorizar nada — eso ya lo
// hace el servidor.
async function procesarCodigoEscaneado(codigo) {
  const usuarioSesion = typeof obtenerUsuarioSesion === 'function' ? obtenerUsuarioSesion() : null;
  if (!usuarioSesion) {
    console.error('No hay sesión activa');
    return;
  }

  const rol = usuarioSesion.rol;
  const { autoConfirmar } = BarcodeSettings.leer();

  try {
    const resp = await fetchApi('buscar_producto.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ codigo_barras: codigo, aplicar: autoConfirmar }),
    });
    const data = await resp.json();

    if (!data.ok) {
      mostrarToastError(data.mensaje || 'No se pudo procesar el código.');
      return;
    }

    if (!data.encontrado) {
      mostrarToastCodigoNoRegistrado(codigo);
      return;
    }

    if (data.advertencia) {
      mostrarToastError(data.advertencia + ': ' + data.producto.nombre);
      return;
    }

    // Modo de confirmación automática apagado: mostramos el producto y
    // esperamos que el usuario confirme antes de tocar el stock.
    if (!autoConfirmar) {
      mostrarModalConfirmacionManual(data.producto, rol, codigo);
      return;
    }

    // Confirmación automática: el movimiento ya se aplicó del lado del servidor.
    mostrarToastCodigoEscaneado(data.producto, rol);
  } catch (err) {
    console.error('Error al procesar el código escaneado:', err);
    mostrarToastError('Error al procesar el código.');
  }
}

/** Se llama cuando el usuario confirma manualmente (modo de confirmación NO automática). */
async function confirmarMovimientoManual(codigo, rol) {
  try {
    const resp = await fetchApi('buscar_producto.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ codigo_barras: codigo, aplicar: true }),
    });
    const data = await resp.json();
    if (data.ok && data.encontrado) {
      mostrarToastCodigoEscaneado(data.producto, rol);
    } else if (data.ok && data.advertencia) {
      mostrarToastError(data.advertencia + ': ' + data.producto.nombre);
    }
  } catch (err) {
    console.error('Error al confirmar:', err);
    mostrarToastError('Error al confirmar el movimiento.');
  }
}

// Funciones de UI (stubs para que se puedan llamar desde ajustes.html)
function mostrarToastCodigoNoRegistrado(codigo) {
  if (typeof mostrarToast === 'function') {
    mostrarToast('Código ' + codigo + ' no registrado en el catálogo.', 'error');
  } else {
    console.warn('Código no encontrado:', codigo);
  }
}

function mostrarToastCodigoEscaneado(producto, rol) {
  if (typeof mostrarToast === 'function') {
    const accion = rol === 'cajero' ? 'vendido' : 'reabastecido';
    mostrarToast(producto.nombre + ' — ' + accion, 'exito');
  } else {
    console.log('Código escaneado:', producto);
  }
}

function mostrarToastError(msg) {
  if (typeof mostrarToast === 'function') {
    mostrarToast(msg, 'error');
  } else {
    console.error(msg);
  }
}

function mostrarModalConfirmacionManual(producto, rol, codigo) {
  // El proyecto no tiene un modal genérico reutilizable (cada pantalla arma
  // su propia "Red de Seguridad" por su cuenta, ver CLAUDE.md). Un confirm()
  // nativo es la confirmación explícita más simple que no bloquea el flujo;
  // si hace falta un modal con la estética del resto de la app, se puede
  // reemplazar esta función sin tocar nada más de este archivo.
  if (confirm('¿Confirmar ' + (rol === 'cajero' ? 'venta' : 'reabastecimiento') + ' de ' + producto.nombre + '?')) {
    confirmarMovimientoManual(codigo, rol);
  }
}

// ---------------------------------------------------------------
// Bootstrap: se ejecuta una sola vez por página, al cargar este script.
// Se inyecta dinámicamente desde auth.js -> inicializarLectorCodigoBarras(),
// igual que chatbot_widget.js, para que corra en todas las pantallas
// autenticadas sin que cada una lo agregue a mano.
// ---------------------------------------------------------------
const barcodeManager = new BarcodeManager({ onScan: procesarCodigoEscaneado });
barcodeManager.iniciarSegunAjustes();
