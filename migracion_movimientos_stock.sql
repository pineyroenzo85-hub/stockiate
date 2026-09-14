-- ============================================================
-- stockIAte - migracion_movimientos_stock.sql
-- ============================================================
-- Crea `movimientos_stock`: el libro mayor del inventario. Una fila por cada
-- vez que el stock de un producto cambia, sin importar quién ni por qué.
--
-- POR QUÉ HACE FALTA
-- ------------------
-- Hasta ahora el stock se podía reconstruir a medias y con esfuerzo: las
-- entradas están en `lotes_stock`, las salidas por venta en `ventas`, y los
-- ajustes de los botones +/- del panel NO ESTABAN EN NINGÚN LADO. Se aplicaban
-- directo sobre `productos.stock_actual` y no dejaban rastro: si al final del
-- mes el sistema decía 12 y en la góndola había 9, no había forma de saber si
-- faltaba mercadería, si alguien había tocado un botón de más, o si una venta
-- se había registrado mal.
--
-- Con esta tabla, `productos.stock_actual` deja de ser la única fuente y pasa
-- a ser un acumulador que se puede AUDITAR: la suma de los `delta` de un
-- producto tiene que dar su stock actual.
--
-- POR QUÉ GUARDA `stock_anterior` Y `stock_nuevo`, QUE PARECEN REDUNDANTES
-- ------------------------------------------------------------------------
-- Porque sin ellos, para saber en cuánto estaba el stock el 12 de agosto hay
-- que sumar todos los movimientos desde el principio de los tiempos, y si UNO
-- se perdió (un endpoint viejo que escribió sin registrar, una migración a
-- medias) el error se arrastra hacia adelante sin que nada lo delate.
-- Con las dos columnas, cada fila se valida sola —`stock_anterior + delta =
-- stock_nuevo`— y cualquier salto entre el `stock_nuevo` de un movimiento y el
-- `stock_anterior` del siguiente señala exactamente dónde se escribió por
-- afuera. Es el mismo criterio que `ventas.costo_unitario`: guardar el número
-- que regía en el momento, en vez de recalcularlo después.
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- Crea una tabla nueva y no toca ninguna existente. El código viejo la ignora;
-- el nuevo la llena. Lo único que hay que tener claro es que **la tabla
-- arranca vacía**: los movimientos anteriores a esta migración no existen y no
-- se pueden reconstruir (justamente porque no se guardaban). El primer
-- movimiento de cada producto va a tener un `stock_anterior` que no cuadra con
-- nada previo, y está bien.
--
-- ⚠️ LA FK DE PRODUCTO ES SIMPLE, NO COMPUESTA, igual que en
-- `migracion_ofertas.sql` y por el mismo motivo: la compuesta necesita
-- `uq_productos_id_negocio (id, negocio_id)`, que crea la PARTE B de
-- `migracion_multitenant.sql`, y esa parte todavía no se corrió sobre la base
-- real. Ver la nota larga de ese archivo. El ALTER para subirla está comentado
-- al final.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_movimientos_stock.sql
-- ============================================================

USE stockiate;

CREATE TABLE IF NOT EXISTS movimientos_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,

    -- Por qué cambió el stock. Es la columna que hace útil a la tabla: sin
    -- ella habría una lista de números sin explicación.
    --   carga   -- entró mercadería (guardar_stock.php)
    --   venta   -- salió por caja (registrar_venta.php)
    --   ajuste  -- alguien tocó los +/- del panel (actualizar_stock.php)
    --   conteo  -- se corrigió contra un conteo físico
    motivo ENUM('carga', 'venta', 'ajuste', 'conteo') NOT NULL,

    -- Cuánto cambió, con signo. Positivo entra, negativo sale. Nunca 0: un
    -- movimiento que no mueve nada no se registra.
    delta INT NOT NULL,

    -- El antes y el después, para poder auditar sin sumar la historia entera.
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,

    -- A qué fila de otra tabla corresponde este movimiento, cuando
    -- corresponde a alguna: el lote que entró, la venta que salió, el conteo
    -- que lo ajustó. Se guarda como (tipo, id) y NO como una FK por cada
    -- destino posible: son tres tablas distintas y tres columnas nullables
    -- serían tres FK que casi siempre están vacías.
    referencia_tipo VARCHAR(20) NULL,
    referencia_id INT NULL,

    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    -- El índice que sostiene la pregunta que se hace siempre: "¿qué le pasó a
    -- este producto, del más nuevo al más viejo?".
    KEY idx_mov_producto_fecha (negocio_id, producto_id, fecha),
    -- Y el de "¿qué pasó en el negocio en tal período?".
    KEY idx_mov_negocio_fecha (negocio_id, fecha),
    KEY idx_mov_referencia (referencia_tipo, referencia_id)
);

-- ------------------------------------------------------------
-- Verificación
-- ------------------------------------------------------------
-- Que cada fila cierre sola (no tiene que devolver ninguna):
-- SELECT * FROM movimientos_stock WHERE stock_anterior + delta <> stock_nuevo;
--
-- Últimos movimientos de un producto:
-- SELECT m.fecha, m.motivo, m.delta, m.stock_anterior, m.stock_nuevo,
--        CONCAT(u.nombre, ' ', u.apellido) AS quien
--   FROM movimientos_stock m
--   LEFT JOIN usuarios u ON u.id = m.usuario_id
--  WHERE m.producto_id = 12
--  ORDER BY m.id DESC LIMIT 20;
--
-- Divergencia entre el libro mayor y el acumulador. Ojo: va a dar distinto de
-- cero para todo producto que haya tenido movimiento ANTES de esta migración,
-- porque esa historia no existe. Sirve de acá en adelante.
-- SELECT p.id, p.nombre, p.stock_actual,
--        COALESCE(SUM(m.delta), 0) AS suma_movimientos
--   FROM productos p
--   LEFT JOIN movimientos_stock m ON m.producto_id = p.id
--  GROUP BY p.id, p.nombre, p.stock_actual;

-- ------------------------------------------------------------
-- DESPUÉS de correr la PARTE B de migracion_multitenant.sql
-- ------------------------------------------------------------
-- Recién cuando exista `uq_productos_id_negocio (id, negocio_id)`:
--
-- ALTER TABLE movimientos_stock DROP FOREIGN KEY movimientos_stock_ibfk_2;
-- ALTER TABLE movimientos_stock
--     ADD CONSTRAINT fk_movimientos_producto
--     FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id);
--
-- (Confirmá el nombre con SHOW CREATE TABLE movimientos_stock antes del DROP.)
