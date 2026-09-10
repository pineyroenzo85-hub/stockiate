-- ============================================================
-- stockIAte - migracion_reposicion.sql
-- ============================================================
-- Suma a `configuracion` las tres claves de la lista de reposición al
-- proveedor (`consultar_reposicion.php`):
--
--   * reposicion_dias_entrega    -- cuántos días tarda el proveedor en
--     entregar. Es el corazón del punto de pedido: si tarda una semana, hay
--     que pedir cuando todavía queda una semana de venta en la góndola.
--   * reposicion_dias_objetivo   -- para cuántos días de venta se pide.
--     30 = un mes de mercadería.
--   * reposicion_factor_seguridad -- el colchón sobre el consumo del plazo de
--     entrega, como múltiplo de ese consumo. Es el ÚNICO valor decimal de la
--     tabla, y por eso existe `leer_config_float()` en configuracion.php.
--
-- Los tres son valores nuevos, no números que estuvieran escondidos en el
-- código: la lista de reposición no existía antes de esta tanda. Los defaults
-- (7 / 30 / 1.5) son los que trae `CONFIG_INICIAL_NEGOCIO`, así que el panel
-- y el backend no pueden mostrar números distintos.
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- No crea ni altera tablas: sólo INSERTA filas en `configuracion`. El código
-- viejo ni lee estas claves.
--
-- Es idempotente: correrla dos veces no pisa lo que el dueño haya configurado
-- (`ON DUPLICATE KEY UPDATE valor = valor` es un no-op deliberado, no un
-- descuido).
--
-- No hace falta si la base se crea desde cero: `crear_negocio.php` siembra
-- estas claves vía `CONFIG_INICIAL_NEGOCIO` (configuracion.php).
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_reposicion.sql
-- ============================================================

USE stockiate;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'reposicion_dias_entrega', '7'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'reposicion_dias_objetivo', '30'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'reposicion_factor_seguridad', '1.5'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

-- ------------------------------------------------------------
-- Verificación: cada negocio tiene que quedar con las 9 claves
-- (umbral_dias_vencimiento, ventana_notificaciones_horas,
--  stock_minimo_default, reposicion_dias_entrega,
--  reposicion_dias_objetivo, reposicion_factor_seguridad,
--  whatsapp_telefono, whatsapp_activo, whatsapp_hora_resumen).
-- ------------------------------------------------------------
-- SELECT n.id, n.nombre, COUNT(c.clave) AS claves
--   FROM negocios n
--   LEFT JOIN configuracion c ON c.negocio_id = n.id
--  GROUP BY n.id, n.nombre;
