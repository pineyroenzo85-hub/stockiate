-- ============================================================
-- stockIAte - migracion_precios_inflacion.sql
-- ============================================================
-- Soporta el módulo "Precios e inflación" del panel (sólo dueño):
-- proyección de precios, desglose fiscal (IVA/IIBB) y alerta de precio
-- atrasado respecto de la inflación acumulada.
--
-- Dos cosas nuevas:
--
--   1. La tabla `historial_precios` -- una fila por cada cambio de
--      `productos.precio_venta`. Sin ella no hay forma de saber cuándo fue
--      el último aumento de un producto, que es el dato que hace falta para
--      calcular si está atrasado.
--   2. Cuatro claves nuevas en `configuracion`: `inflacion_mensual`,
--      `alicuota_iva`, `alicuota_iibb`, `umbral_atraso_precio`.
--
-- ⚠️ LA FK DE PRODUCTO ES SIMPLE, NO COMPUESTA. LEER ESTO.
--
-- `schema.sql` declara `FOREIGN KEY (producto_id, negocio_id) REFERENCES
-- productos(id, negocio_id)`, que es lo correcto y lo que tiene una base
-- creada de cero. Acá va la versión simple, a propósito: la compuesta
-- necesita el índice `uq_productos_id_negocio (id, negocio_id)` en
-- `productos`, y ese índice lo crea la PARTE B de `migracion_multitenant.sql`,
-- que va comentada y -- según CLAUDE.md, verificado el 09/09/2026 sobre la
-- base real -- todavía no se corrió. Mismo criterio que
-- `migracion_ofertas.sql`: con la FK simple esta migración no baja el nivel
-- de protección de nada, queda igual que sus vecinas (`ventas`, `lotes_stock`,
-- `ofertas` también apuntan a `productos(id)` a secas hoy). Quien impide que
-- una fila apunte a un producto de otro negocio es el código PHP
-- (`actualizar_precios.php` valida `negocio_id` antes de escribir).
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- -----------------------------------------------
-- `historial_precios` es una tabla nueva: no toca ninguna existente. Las
-- cuatro claves de `configuracion` se siembran con `ON DUPLICATE KEY UPDATE
-- valor = valor` (no-op deliberado, no pisa lo que el dueño ya haya
-- configurado) con los mismos valores que `CONFIG_INICIAL_NEGOCIO`
-- (configuracion.php), así que un negocio que no toque nada se comporta
-- igual que antes de migrar.
--
-- No hace falta si la base se crea desde cero: `schema.sql` ya trae la tabla,
-- y `crear_negocio.php` siembra las claves vía `CONFIG_INICIAL_NEGOCIO`.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_precios_inflacion.sql
-- ============================================================

USE stockiate;

CREATE TABLE IF NOT EXISTS historial_precios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    precio_anterior DECIMAL(10,2) NOT NULL,
    precio_nuevo DECIMAL(10,2) NOT NULL,
    motivo VARCHAR(30) NOT NULL,
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    KEY idx_historial_precios_producto (negocio_id, producto_id, fecha)
);

-- ------------------------------------------------------------
-- Semilla: una fila inicial por producto que ya existía, para que TODOS
-- tengan "último aumento" (si no, el panel no podría calcular el atraso de
-- ningún producto cargado antes de esta migración).
--
-- No hay forma de reconstruir el precio real anterior a este momento, así
-- que `precio_anterior` = `precio_nuevo` = el precio_venta actual, con
-- motivo 'carga_inicial' y fecha = `creado_en` del producto (la mejor
-- aproximación disponible a "desde cuándo rige este precio").
--
-- Sólo inserta si el producto todavía no tiene ninguna fila en
-- historial_precios, para que correr esta migración dos veces no duplique
-- la semilla.
-- ------------------------------------------------------------
INSERT INTO historial_precios (negocio_id, producto_id, precio_anterior, precio_nuevo, motivo, fecha)
SELECT p.negocio_id, p.id, p.precio_venta, p.precio_venta, 'carga_inicial', p.creado_en
  FROM productos p
 WHERE NOT EXISTS (
     SELECT 1 FROM historial_precios h WHERE h.producto_id = p.id
 );

-- ------------------------------------------------------------
-- Configuración fiscal / inflación, por negocio existente.
-- ------------------------------------------------------------
INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'inflacion_mensual', '2.0'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'alicuota_iva', '21'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'alicuota_iibb', '3.5'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'umbral_atraso_precio', '5'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

-- ------------------------------------------------------------
-- Verificación
-- ------------------------------------------------------------
-- SHOW CREATE TABLE historial_precios\G
--
-- Todo producto activo tiene que tener al menos una fila:
-- SELECT p.id, p.nombre
--   FROM productos p
--   LEFT JOIN historial_precios h ON h.producto_id = p.id
--  WHERE p.activo = 1 AND h.id IS NULL;
--
-- Cada negocio tiene que tener las 4 claves nuevas:
-- SELECT negocio_id, COUNT(*) FROM configuracion
--  WHERE clave IN ('inflacion_mensual','alicuota_iva','alicuota_iibb','umbral_atraso_precio')
--  GROUP BY negocio_id;

-- ------------------------------------------------------------
-- DESPUÉS de correr la PARTE B de migracion_multitenant.sql
-- ------------------------------------------------------------
-- Recién cuando exista `uq_productos_id_negocio (id, negocio_id)` en
-- `productos`, descomentar esto para que el motor -- y no sólo el PHP --
-- impida que una fila de historial apunte a un producto de otro negocio:
--
-- ALTER TABLE historial_precios DROP FOREIGN KEY historial_precios_ibfk_2;
-- ALTER TABLE historial_precios
--     ADD CONSTRAINT fk_historial_precios_producto
--     FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id);
--
-- (El nombre `historial_precios_ibfk_2` es el que le pone MySQL solo;
--  confirmalo con SHOW CREATE TABLE historial_precios antes de correr el DROP.)
