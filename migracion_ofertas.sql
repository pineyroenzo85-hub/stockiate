-- ============================================================
-- stockIAte - migracion_ofertas.sql
-- ============================================================
-- Crea la tabla `ofertas`: un precio promocional con fecha de vigencia.
--
-- POR QUÉ APARECE ACÁ Y NO ANTES
-- ------------------------------
-- La pide `carteles.html`, la pantalla que imprime los carteles de góndola:
-- un cartel de oferta tiene que decir el precio anterior tachado, el nuevo, y
-- hasta cuándo vale. Sin una tabla que guarde esas tres cosas, la mitad de esa
-- pantalla no se puede construir.
--
-- Es DELIBERADAMENTE MÍNIMA. No hay motor de promociones, ni 2x1, ni
-- descuentos por categoría, ni un recomendador que sugiera qué poner en
-- oferta. Sólo "este producto, a este precio, desde acá hasta acá". Todo lo
-- demás se agrega el día que haga falta.
--
-- OJO: las ventas NO leen esta tabla. `registrar_venta.php` sigue cobrando
-- `productos.precio_venta`. Que el cartel diga un precio y la caja cobre otro
-- es un problema real, y está anotado como tal en CLAUDE.md: engancharlo a la
-- venta es una decisión aparte (hay que decidir qué pasa con el snapshot de
-- `ventas.precio_unitario`, con los márgenes históricos y con el chatbot), y
-- meterlo de prepo acá rompería reportes que hoy cierran.
--
-- POR QUÉ `precio_anterior` ES UNA COLUMNA Y NO UN JOIN
-- -----------------------------------------------------
-- Mismo motivo que `ventas.costo_unitario`: es un SNAPSHOT. El "antes" que se
-- imprime en el cartel tiene que ser el precio que regía cuando se armó la
-- oferta. Si saliera de `productos.precio_venta`, un aumento de lista al día
-- siguiente cambiaría solo el número tachado del cartel que ya está pegado en
-- la góndola.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_ofertas.sql
-- ============================================================

USE stockiate;

-- ⚠️ LA FK DE PRODUCTO ES SIMPLE, NO COMPUESTA. LEER ESTO.
--
-- `schema.sql` declara `FOREIGN KEY (producto_id, negocio_id) REFERENCES
-- productos(id, negocio_id)`, que es lo correcto y lo que tiene una base
-- creada de cero. Acá va la versión simple, a propósito: la compuesta necesita
-- el índice `uq_productos_id_negocio (id, negocio_id)` en `productos`, y ese
-- índice lo crea la PARTE B de `migracion_multitenant.sql`, que va comentada y
-- no siempre se corrió.
--
-- Verificado el 09/09/2026 sobre la base real: PARTE B NO se corrió. No existe
-- `uq_productos_id_negocio`, `negocio_id` sigue siendo NULLable en 7 tablas, y
-- NINGUNA tabla tiene FK compuesta -- `ventas` y `lotes_stock` apuntan a
-- `productos(id)` a secas, igual que esta. O sea que esta migración no baja el
-- nivel de protección de nada: queda igual que sus vecinas.
--
-- Con la FK simple, la base no impide que una oferta apunte a un producto de
-- otro negocio. Quien lo impide es el código PHP: `guardar_oferta.php` valida
-- que el producto sea del negocio de la sesión ANTES de insertar, y
-- `consultar_carteles.php` filtra por `negocio_id` en el JOIN.
--
-- Cuando se corra la PARTE B, subir esta FK a compuesta es el bloque comentado
-- del final de este archivo.
CREATE TABLE IF NOT EXISTS ofertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    precio_oferta DECIMAL(10,2) NOT NULL,
    -- El precio de lista al momento de crear la oferta. Ver la nota de arriba.
    precio_anterior DECIMAL(10,2) NOT NULL,
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    creado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    -- El índice arranca por negocio_id porque toda query filtra primero por
    -- negocio, y sigue por `hasta` porque la pregunta que se hace siempre es
    -- "¿cuáles están vigentes hoy?".
    KEY idx_ofertas_vigentes (negocio_id, hasta, desde),
    KEY idx_ofertas_producto (producto_id, hasta)
);

-- ------------------------------------------------------------
-- Verificación
-- ------------------------------------------------------------
-- SHOW CREATE TABLE ofertas\G
--
-- Ofertas vigentes hoy:
-- SELECT p.nombre, o.precio_anterior, o.precio_oferta, o.desde, o.hasta
--   FROM ofertas o JOIN productos p ON p.id = o.producto_id
--  WHERE CURDATE() BETWEEN o.desde AND o.hasta;

-- ------------------------------------------------------------
-- DESPUÉS de correr la PARTE B de migracion_multitenant.sql
-- ------------------------------------------------------------
-- Recién cuando exista `uq_productos_id_negocio (id, negocio_id)` en
-- `productos`, descomentar esto para que el motor —y no sólo el PHP— impida
-- que una oferta apunte a un producto de otro negocio:
--
-- ALTER TABLE ofertas DROP FOREIGN KEY ofertas_ibfk_2;
-- ALTER TABLE ofertas
--     ADD CONSTRAINT fk_ofertas_producto
--     FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id);
--
-- (El nombre `ofertas_ibfk_2` es el que le pone MySQL solo; confirmalo con
--  SHOW CREATE TABLE ofertas antes de correr el DROP.)
