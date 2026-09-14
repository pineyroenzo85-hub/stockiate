-- ============================================================
-- stockIAte - Migración: notificaciones por WhatsApp
-- ============================================================
-- Ejecutar una sola vez sobre una base `stockiate` creada con una versión
-- anterior de schema.sql (sin la tabla `notificaciones`). Si la base se crea
-- desde cero con el schema.sql actual, esta migración no es necesaria.
--
-- POR QUÉ UNA COLA Y NO UN ENVÍO DIRECTO
-- --------------------------------------
-- La forma obvia sería que `registrar_venta.php` le pegue a la API de Meta
-- justo después de descontar el stock. Es la peor de las opciones:
--
--   * Le suma la latencia de una llamada a internet a CADA venta. El cajero
--     tiene el cliente enfrente esperando el ticket.
--   * Acopla el cobro a un servicio de terceros. Si Meta tarda, se cae, o el
--     token venció, el cajero ve un error en una operación que en realidad
--     salió perfecta. Y si el envío quedara DENTRO de la transacción, un
--     WhatsApp fallido haría rollback de una venta real.
--   * No hay reintentos: si no salió, se perdió.
--   * No hay deduplicación: vender 10 unidades de a una manda 10 veces el
--     mismo aviso de stock crítico.
--
-- Con la cola, el disparador sólo hace un INSERT local (microsegundos, y
-- fuera de la transacción de la venta) y `tareas_notificaciones.php` —
-- programado con el Programador de tareas de Windows — la vacía cada unos
-- minutos, reintentando lo que falló. De paso queda el registro de qué se
-- avisó y si llegó, que es exactamente lo que hace falta para diagnosticar
-- "no me llegó nada".
--
-- `clave_dedup` identifica el HECHO, no la fila: 'stock_critico:42' es "el
-- producto 42 está en crítico". Si ya hay una fila con esa clave dentro de la
-- ventana de deduplicación, no se encola otra.
--
-- CONFIGURACIÓN POR NEGOCIO
-- -------------------------
-- El teléfono destino y el resto de los ajustes NO son columnas nuevas en
-- `negocios`: van como filas de `configuracion`, que ya existe con PK
-- (negocio_id, clave) y es exactamente para esto (hoy guarda
-- `umbral_dias_vencimiento`). Se siembran en `whatsapp_activo = '0'`, así
-- que después de la migración no se manda absolutamente nada hasta que el
-- dueño cargue su número y lo active desde el panel.
--
-- Es aditiva: crea una tabla nueva y filas de configuración desactivadas. Es
-- segura con el código viejo corriendo (que sencillamente las ignora).
--
-- No es idempotente: si se corre dos veces, la segunda falla con
-- "Table 'notificaciones' already exists". Para verificar antes de correrla:
--   SHOW TABLES LIKE 'notificaciones';

USE stockiate;

-- ------------------------------------------------------------
-- 1) La cola de notificaciones
-- ------------------------------------------------------------
CREATE TABLE notificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    tipo ENUM('stock_critico', 'sin_stock', 'vencimientos', 'resumen_diario', 'prueba') NOT NULL,
    -- 'prueba' es el botón "Mandar mensaje de prueba" del panel. Se guarda
    -- como una notificación más para que el envío de prueba recorra
    -- exactamente el mismo camino que uno real: si la prueba llega, las
    -- alertas de verdad también.
    clave_dedup VARCHAR(100) NOT NULL,
    -- Snapshot del teléfono al encolar, E.164 sin '+'. Se copia en vez de
    -- releerlo al enviar para que cambiar el número en el panel no redirija
    -- avisos que ya estaban en la cola.
    destino VARCHAR(20) NOT NULL,
    plantilla VARCHAR(60) NOT NULL,
    parametros TEXT NOT NULL,
    estado ENUM('pendiente', 'enviada', 'fallida') NOT NULL DEFAULT 'pendiente',
    intentos TINYINT NOT NULL DEFAULT 0,
    error TEXT NULL,
    wamid VARCHAR(80) NULL,
    creada_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    enviada_en DATETIME NULL,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    INDEX idx_notif_pendientes (estado, creada_en),
    INDEX idx_notif_dedup (negocio_id, clave_dedup, creada_en)
);

-- ------------------------------------------------------------
-- 2) Configuración por negocio (desactivada por defecto)
-- ------------------------------------------------------------
-- INSERT ... SELECT para cubrir los negocios que ya existen. El
-- `ON DUPLICATE KEY UPDATE negocio_id = negocio_id` es un no-op deliberado:
-- hace que re-correr SÓLO esta parte no pise una configuración ya cargada.
INSERT INTO configuracion (negocio_id, clave, valor)
    SELECT id, 'whatsapp_activo', '0' FROM negocios
    ON DUPLICATE KEY UPDATE negocio_id = negocio_id;

INSERT INTO configuracion (negocio_id, clave, valor)
    SELECT id, 'whatsapp_telefono', '' FROM negocios
    ON DUPLICATE KEY UPDATE negocio_id = negocio_id;

INSERT INTO configuracion (negocio_id, clave, valor)
    SELECT id, 'whatsapp_hora_resumen', '20:00' FROM negocios
    ON DUPLICATE KEY UPDATE negocio_id = negocio_id;

-- ------------------------------------------------------------
-- Verificación (correr a mano después de la migración)
-- ------------------------------------------------------------
-- La tabla quedó creada y vacía:
--   SHOW CREATE TABLE notificaciones\G
--   SELECT COUNT(*) FROM notificaciones;   -- 0
--
-- Cada negocio tiene sus 3 claves nuevas, todas desactivadas:
--   SELECT n.id, n.nombre,
--          MAX(CASE WHEN c.clave = 'whatsapp_activo' THEN c.valor END) AS activo,
--          MAX(CASE WHEN c.clave = 'whatsapp_telefono' THEN c.valor END) AS telefono
--     FROM negocios n
--     LEFT JOIN configuracion c ON c.negocio_id = n.id
--    GROUP BY n.id, n.nombre;
--
-- Después de configurar y vender algo que deje un producto bajo el mínimo,
-- tiene que aparecer una fila pendiente:
--   SELECT id, tipo, clave_dedup, estado, intentos, error FROM notificaciones
--    ORDER BY id DESC LIMIT 10;
