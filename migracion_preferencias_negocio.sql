-- ============================================================
-- stockIAte - migracion_preferencias_negocio.sql
-- ============================================================
-- Suma a `configuracion` las dos claves que faltaban para que la sección
-- "Preferencias del negocio" del panel pueda configurar lo que hasta ahora
-- estaba escrito en el código:
--
--   * ventana_notificaciones_horas -- cada cuántas horas se puede repetir el
--     aviso de un mismo producto en stock crítico. Antes era el default de 24
--     que tenía `encolar_notificacion()` en su firma, y no había forma de
--     cambiarlo sin editar PHP.
--   * stock_minimo_default -- con cuánto stock mínimo nace un producto nuevo.
--     Antes era el DEFAULT 5 de la columna `productos.stock_minimo`, invisible
--     desde la aplicación.
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- No crea ni altera tablas: sólo INSERTA filas en `configuracion` con los
-- MISMOS valores que ya eran el comportamiento hardcodeado. Un negocio que no
-- toque nada después de migrar se comporta exactamente igual que antes, y el
-- código viejo (que ni lee estas claves) las ignora.
--
-- Es idempotente: correrla dos veces no pisa lo que el dueño haya configurado
-- (`ON DUPLICATE KEY UPDATE valor = valor` es un no-op deliberado, no un
-- descuido).
--
-- No hace falta si la base se crea desde cero: `crear_negocio.php` siembra
-- estas claves vía `CONFIG_INICIAL_NEGOCIO` (configuracion.php).
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_preferencias_negocio.sql
-- ============================================================

USE stockiate;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'ventana_notificaciones_horas', '24'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'stock_minimo_default', '5'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

-- ------------------------------------------------------------
-- Verificación: cada negocio tiene que quedar con las 6 claves
-- (umbral_dias_vencimiento, ventana_notificaciones_horas,
--  stock_minimo_default, whatsapp_telefono, whatsapp_activo,
--  whatsapp_hora_resumen).
-- ------------------------------------------------------------
-- SELECT n.id, n.nombre, COUNT(c.clave) AS claves
--   FROM negocios n
--   LEFT JOIN configuracion c ON c.negocio_id = n.id
--  GROUP BY n.id, n.nombre;
