-- ============================================================
-- stockIAte - migracion_lotes_proveedor_costo.sql
-- ============================================================
-- Suma proveedor_id y precio_costo a lotes_stock: hasta ahora esas dos
-- columnas sólo existían en productos, como un valor único por producto que
-- se pisaba cada vez que se cargaba una reposición. El mismo producto a
-- veces se compra a proveedores distintos y a precios distintos según el
-- momento, y con un solo valor por producto el lote #1 (Distribuidora Sur,
-- $2000) y el lote #2 (Mayorista Norte, $2300) quedaban indistinguibles.
--
-- QUÉ CAMBIA Y QUÉ NO: productos.proveedor_id/precio_costo SIGUEN
-- existiendo y siguen siendo el "último usado" que ya leen
-- consultar_rentabilidad.php y consultar_reposicion.php. Lo nuevo es el
-- espejo permanente por lote en lotes_stock, que no se pisa con cargas
-- posteriores -- si hay que reclamarle a un proveedor por un lote
-- específico, esa fila dice exactamente a quién y a cuánto se le compró.
--
-- Ambas columnas son NULLABLE: cargar un lote sin tocar proveedor/costo
-- sigue siendo válido. Aditiva y segura con el código viejo corriendo:
-- buscar_producto.php y seed_demo.php insertan en lotes_stock con columnas
-- explícitas sin éstas -- esas filas quedan en NULL, que es lo correcto (esos
-- flujos nunca tuvieron este dato).
--
-- FK simple a proveedores(id): schema.sql documenta una FK COMPUESTA
-- (proveedor_id, negocio_id) para productos.proveedor_id, apoyada en una
-- uq_proveedores_id_negocio (id, negocio_id) -- pero esta base real no tiene
-- esa unique key ni esa FK compuesta en absoluto (se comprobó con SHOW
-- CREATE TABLE: tanto productos.proveedor_id como lotes_stock.producto_id
-- hoy usan FKs simples de una sola columna). schema.sql documenta un estado
-- multi-tenant más endurecido que nunca se aplicó de verdad sobre esta base
-- -- no es algo que corresponda arreglar acá de paso, así que esta columna
-- sigue la convención REAL ya vigente (FK simple), no la aspiracional.
--
-- Uso: mysql -u root --default-character-set=utf8mb4 stockiate < migracion_lotes_proveedor_costo.sql
-- ============================================================

USE stockiate;

ALTER TABLE lotes_stock
    ADD COLUMN proveedor_id INT NULL AFTER cantidad,
    ADD COLUMN precio_costo DECIMAL(10,2) NULL AFTER proveedor_id,
    ADD CONSTRAINT fk_lotes_proveedor
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id);

-- Verificación:
-- DESCRIBE lotes_stock;
-- SELECT l.id, l.fecha_carga, l.cantidad, l.precio_costo, pr.nombre AS proveedor
--   FROM lotes_stock l
--   LEFT JOIN proveedores pr ON pr.id = l.proveedor_id AND pr.negocio_id = l.negocio_id
--  WHERE l.producto_id = 12 ORDER BY l.id DESC;
