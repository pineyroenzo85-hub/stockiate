-- ============================================================
-- stockIAte - Migración: foto del producto
-- ============================================================
-- Agrega `productos.foto`: el nombre del archivo (en uploads/productos/) de la
-- última foto que se sacó con la cámara en la Red de Seguridad. Es lo que se
-- muestra al pasar el mouse por un producto en la tabla de inventario.
--
-- Aditiva e idempotente: una columna NULLable, segura con el código viejo
-- andando. Correrla también sobre `stockiate_demo` si se usa el modo demo.
-- Además hace falta que `uploads/productos/` tenga permiso de escritura (se
-- crea sola si `uploads/` lo tiene) y `extension=gd` en php.ini, igual que el
-- logo de los carteles.

USE stockiate;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS foto VARCHAR(100) NULL AFTER archivado_en;
