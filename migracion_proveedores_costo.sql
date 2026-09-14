-- ============================================================
-- stockIAte - Migración: proveedores y precio de costo
-- ============================================================
-- Ejecutar una sola vez sobre una base `stockiate` creada con una versión
-- anterior de schema.sql (sin tabla `proveedores` ni las columnas
-- `precio_costo` / `proveedor_id` en `productos`). Si la base se crea desde
-- cero con el schema.sql actual, esta migración no es necesaria.

USE stockiate;

CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE productos
    ADD COLUMN precio_costo DECIMAL(10,2) NULL AFTER precio_venta,
    ADD COLUMN proveedor_id INT NULL AFTER precio_costo,
    ADD CONSTRAINT fk_productos_proveedor
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id);
