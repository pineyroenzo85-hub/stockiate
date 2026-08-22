-- ============================================================
-- stockIAte - Migración: agrega columna `apellido` a `usuarios`
-- ============================================================
-- Ejecutar una sola vez sobre una base `stockiate` creada con una versión
-- anterior de schema.sql (que no tenía columna `apellido`). Si la base se
-- crea desde cero con el schema.sql actual, esta migración no es necesaria.

USE stockiate;

ALTER TABLE usuarios
    ADD COLUMN apellido VARCHAR(100) NOT NULL DEFAULT '' AFTER nombre;
