-- ============================================================
-- stockIAte - Migración: snapshot del costo en las ventas
-- ============================================================
-- Ejecutar una sola vez sobre una base `stockiate` creada con una versión
-- anterior de schema.sql (sin la columna `costo_unitario` en `ventas`). Si la
-- base se crea desde cero con el schema.sql actual, esta migración no es
-- necesaria.
--
-- POR QUÉ NO ALCANZA CON productos.precio_costo
-- ---------------------------------------------
-- `productos.precio_costo` es el costo de HOY. El proveedor aumenta y esa
-- columna se pisa, así que calcular el margen de una venta vieja con ella
-- reescribe la historia: una venta de marzo pasaría a mostrar la ganancia que
-- habría dejado a precios de agosto. Con el snapshot, cada renglón de venta
-- se queda con el costo que tenía el producto en ese momento.
--
-- La columna es NULL cuando el producto todavía no tiene costo cargado, y
-- todas las ventas anteriores a esta migración quedan en NULL. Para esas,
-- consultar_rentabilidad.php cae al `precio_costo` actual
-- (COALESCE(v.costo_unitario, p.precio_costo)) y reporta cuántos productos no
-- tienen costo, para que el chatbot pueda avisar que el número es parcial.
--
-- ES SEGURA CON EL CÓDIGO VIEJO ANDANDO: la columna es aditiva y nullable, no
-- toca ninguna FK ni restricción existente. No hace falta partirla en dos
-- tandas como `migracion_multitenant.sql`. Correrla y después desplegar el
-- `registrar_venta.php` nuevo funciona; en el medio, las ventas que se
-- registren quedan con `costo_unitario` en NULL, que es exactamente lo mismo
-- que pasa con las ventas históricas.
--
-- No es idempotente: si se corre dos veces, la segunda falla con
-- "Duplicate column name 'costo_unitario'". Es inofensivo, pero para
-- verificar antes de correrla:
--   SHOW COLUMNS FROM ventas LIKE 'costo_unitario';

USE stockiate;

ALTER TABLE ventas
    ADD COLUMN costo_unitario DECIMAL(10,2) NULL AFTER precio_unitario;

-- Verificación: las ventas nuevas tienen que empezar a traer costo.
-- Registrá una venta de un producto que tenga `precio_costo` cargado y corré:
--
--   SELECT v.id, p.nombre, v.precio_unitario, v.costo_unitario, v.fecha
--     FROM ventas v
--     JOIN productos p ON p.id = v.producto_id
--    ORDER BY v.id DESC
--    LIMIT 5;
--
-- Cuántas ventas históricas quedaron sin costo (van a usar el costo actual):
--
--   SELECT COUNT(*) AS ventas_sin_costo FROM ventas WHERE costo_unitario IS NULL;
