-- ============================================================
-- stockIAte - Migración: baja lógica de productos (archivar)
-- ============================================================
-- Ejecutar una sola vez sobre una base `stockiate` creada con una versión
-- anterior de schema.sql (sin las columnas `activo` / `archivado_en` en
-- `productos`). Si la base se crea desde cero con el schema.sql actual, esta
-- migración no es necesaria.
--
-- POR QUÉ ARCHIVAR Y NO BORRAR
-- ----------------------------
-- El botón 🗑 del panel existía pero en la práctica no servía. Dos candados
-- lo bloqueaban:
--   1. El frontend lo deshabilitaba si `stock_actual > 0`.
--   2. Aunque el stock estuviera en 0, el DELETE fallaba por integridad
--      referencial: `ventas`, `lotes_stock` y `correcciones_ia` tienen FK a
--      `productos` SIN ON DELETE CASCADE (a propósito: borrar en cascada
--      destruiría registros de ventas reales).
-- Sobre una base con 36 productos, eso dejaba exactamente 1 borrable.
--
-- La baja lógica resuelve las dos cosas sin tocar el historial: el producto
-- desaparece del catálogo, de los <select> de repositor/cajero, del panel y
-- del chatbot, pero sus ventas viejas siguen sumando en los reportes. Por eso
-- `consultar_ventas.php` es el único endpoint que NO filtra por `activo`: si
-- filtrara, archivar un producto le borraría facturación al historial y los
-- totales dejarían de cuadrar.
--
-- El SKU de un producto archivado SIGUE OCUPADO: el UNIQUE (negocio_id, sku)
-- aplica igual, y `crear_producto.php` scanea todos los productos (activos y
-- archivados) al generar uno nuevo. Si no, restaurar un producto podría
-- chocar con otro creado mientras tanto.
--
-- Es aditiva y con DEFAULT, así que es segura con el código viejo corriendo:
-- todos los productos existentes quedan activos y nada cambia hasta que se
-- despliegue el código nuevo.
--
-- No es idempotente: si se corre dos veces, la segunda falla con
-- "Duplicate column name 'activo'". Para verificar antes de correrla:
--   SHOW COLUMNS FROM productos LIKE 'activo';

USE stockiate;

ALTER TABLE productos
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_minimo,
    ADD COLUMN archivado_en DATETIME NULL AFTER activo;

-- Índice sobre (negocio_id, activo): todas las pantallas filtran por esas dos
-- columnas juntas, y es el filtro que corre en cada carga del panel.
CREATE INDEX idx_productos_negocio_activo ON productos (negocio_id, activo);

-- Verificación: todo lo que ya existía tiene que quedar activo.
--
--   SELECT activo, COUNT(*) FROM productos GROUP BY activo;
--
-- Y después de archivar uno desde el panel, sus ventas tienen que seguir
-- apareciendo en los reportes:
--
--   SELECT p.nombre, p.activo, SUM(v.cantidad) AS unidades
--     FROM ventas v JOIN productos p ON p.id = v.producto_id
--    WHERE p.activo = 0
--    GROUP BY p.id, p.nombre, p.activo;
