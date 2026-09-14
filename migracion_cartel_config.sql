-- ============================================================
-- stockIAte - migracion_cartel_config.sql
-- ============================================================
-- Suma a `configuracion` las nueve claves del diseño del cartel de góndola
-- (`carteles.html`): qué diseño usar, qué tamaño tiene el precio, qué campos
-- se muestran, el texto del pie y el logo del negocio.
--
-- Los valores que siembra son EXACTAMENTE los que el cartel tenía escritos en
-- el CSS antes de que esto se pudiera configurar (diseño clásico, precio en
-- 56pt, nombre en 15pt, marca / SKU / vencimiento visibles). Un negocio que
-- migre y no toque nada imprime carteles idénticos a los de antes.
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- No crea ni altera tablas: sólo INSERTA filas en `configuracion`. El código
-- viejo ni lee estas claves, y el nuevo cae al default de
-- `CONFIG_INICIAL_NEGOCIO` (configuracion.php) si la fila no está, así que
-- correrla tarde tampoco rompe nada.
--
-- Es idempotente: `ON DUPLICATE KEY UPDATE valor = valor` es un no-op
-- deliberado, para no pisar lo que el dueño haya configurado.
--
-- ⚠️ ADEMÁS DE ESTO HACE FALTA LA CARPETA DE SUBIDAS
-- ---------------------------------------------------
-- El logo se guarda como archivo en `uploads/logos/`, no en la base. Esa
-- carpeta va en el repo con su propio `.htaccess` (que impide que Apache
-- ejecute nada ahí adentro), pero **tiene que tener permiso de escritura para
-- el usuario de Apache**. En XAMPP sobre Windows funciona sin tocar nada; en
-- un Linux hay que hacer, una vez:
--
--   chown www-data:www-data uploads/logos && chmod 755 uploads/logos
--
-- Si falta el permiso, `subir_logo.php` lo dice con un mensaje claro en vez de
-- fallar en silencio.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_cartel_config.sql
-- ============================================================

USE stockiate;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_diseno', 'clasico' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_precio_pt', '56' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_nombre_pt', '15' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_mostrar_marca', '1' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_mostrar_sku', '1' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_mostrar_vencimiento', '1' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_texto_pie', '' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_logo', '' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'cartel_logo_posicion', 'arriba' FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

-- ------------------------------------------------------------
-- Verificación
-- ------------------------------------------------------------
-- SELECT n.nombre, c.clave, c.valor
--   FROM negocios n
--   JOIN configuracion c ON c.negocio_id = n.id
--  WHERE c.clave LIKE 'cartel_%'
--  ORDER BY n.id, c.clave;
