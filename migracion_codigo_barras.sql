-- Agregar columna codigo_barras a productos
-- Opcional, único por producto (puede ser NULL)
ALTER TABLE productos
ADD COLUMN codigo_barras VARCHAR(50) NULL UNIQUE,
ADD INDEX idx_productos_codigo_barras (codigo_barras);
