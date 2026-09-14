-- ============================================================
-- stockIAte - migracion_notificaciones_email.sql
-- ============================================================
-- Suma el EMAIL como segundo canal de notificaciones, al lado de WhatsApp.
--
-- POR QUÉ
-- -------
-- WhatsApp está construido y no manda nada: las tres plantillas nunca se
-- aprobaron en Meta, así que cada intento vuelve con
-- `(#132001) Template name does not exist in the translation`. Sobre esta
-- base había 50 avisos encolados desde el 03/09/2026, todos con ese error.
--
-- El mail no necesita la aprobación de nadie. Es el mismo aviso, por un canal
-- que ya está probado y funcionando (ver mailer.php, que se agregó para la
-- recuperación de contraseña).
--
-- NO REEMPLAZA A WHATSAPP: son dos canales independientes, cada uno con su
-- interruptor. Un negocio puede tener los dos, uno, o ninguno. El día que las
-- plantillas se aprueben, WhatsApp empieza a andar sin tocar una línea.
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- La columna `canal` nace con DEFAULT 'whatsapp', así que las 50 filas que ya
-- están en la cola siguen siendo exactamente lo que eran y el código viejo
-- (que ni sabe que la columna existe) se comporta igual.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_notificaciones_email.sql
-- ============================================================

USE stockiate;

-- ------------------------------------------------------------
-- 1. Por qué canal sale cada aviso
-- ------------------------------------------------------------
-- Una fila por canal, no una fila con dos destinos: cada envío tiene su
-- propio estado, sus propios intentos y su propio error. Si el mail sale y el
-- WhatsApp falla, eso tiene que verse en la tabla — con una sola fila habría
-- que elegir cuál de los dos resultados guardar.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                   AND COLUMN_NAME = 'canal');
SET @sql := IF(@existe = 0,
  'ALTER TABLE notificaciones ADD COLUMN canal ENUM(''whatsapp'', ''email'') NOT NULL DEFAULT ''whatsapp'' AFTER tipo',
  'SELECT "canal ya existe" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. `destino` tiene que poder guardar un email
-- ------------------------------------------------------------
-- Estaba en VARCHAR(20), que alcanza justo para un teléfono E.164 y NO para
-- una dirección de correo. Sin este ALTER, MariaDB en modo no estricto
-- guardaría el mail TRUNCADO y en silencio: `coopperstockiate@gmai` — y el
-- envío fallaría con un error de dirección inválida que no se parece en nada
-- a la causa real. Es la misma trampa que ya documenta CLAUDE.md para el ENUM
-- de `dueño` importado con el codepage equivocado.
ALTER TABLE notificaciones
  MODIFY COLUMN destino VARCHAR(150) NOT NULL;

-- ------------------------------------------------------------
-- 3. Los dos tipos que faltaban en el ENUM
-- ------------------------------------------------------------
-- `PLANTILLA_POR_TIPO` (notificaciones.php) ya tenía `prediccion_quiebre` y
-- `prueba_simple`, pero el ENUM de la tabla no. En modo no estricto eso NO da
-- error: guarda cadena vacía. Sobre esta base hay 2 filas así, con su tipo
-- perdido para siempre.
--
-- No se pueden reconstruir (el dato no está en ningún otro lado), así que
-- quedan como están; lo que arregla este ALTER es que no se sumen más.
ALTER TABLE notificaciones
  MODIFY COLUMN tipo ENUM(
      'stock_critico',
      'sin_stock',
      'prediccion_quiebre',
      'vencimientos',
      'resumen_diario',
      'prueba',
      'prueba_simple'
  ) NOT NULL;

-- ------------------------------------------------------------
-- 4. Índice de deduplicación, ahora por canal
-- ------------------------------------------------------------
-- La deduplicación pasa a ser POR CANAL: el mismo hecho ('stock_critico:42')
-- tiene que poder encolarse una vez por WhatsApp y una vez por mail. Sin el
-- canal en la clave, activar el segundo canal no manda nada — la fila del
-- primero deduplica a la del segundo, y el síntoma sería "activé el mail y no
-- llega nada", que es exactamente el modo de falla más difícil de diagnosticar.
-- EL ORDEN IMPORTA: primero se crea el nuevo, después se borra el viejo.
-- Al revés no se puede, y el error es poco obvio:
--   ERROR 1553: Cannot drop index 'idx_notif_dedup': needed in a foreign key
--               constraint
-- El índice viejo empieza con `negocio_id`, que es la columna de la FK a
-- `negocios`, así que MariaDB lo está usando para sostenerla y no lo suelta
-- mientras sea el único. El nuevo también empieza con `negocio_id`: en cuanto
-- existe, la FK se apoya en él y el viejo queda libre.
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                   AND INDEX_NAME = 'idx_notif_dedup_canal');
SET @sql := IF(@existe = 0,
  'CREATE INDEX idx_notif_dedup_canal ON notificaciones (negocio_id, clave_dedup, canal, creada_en)',
  'SELECT "idx_notif_dedup_canal ya existe" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                   AND INDEX_NAME = 'idx_notif_dedup');
SET @sql := IF(@existe > 0,
  'DROP INDEX idx_notif_dedup ON notificaciones',
  'SELECT "idx_notif_dedup ya no esta" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. Las dos claves de configuración por negocio
-- ------------------------------------------------------------
-- Arrancan DESACTIVADAS y sin dirección, igual que arrancó WhatsApp: después
-- de correr esto no sale ni un mail hasta que un dueño cargue su casilla en
-- el panel de Preferencias. Idempotente: `valor = valor` es un no-op
-- deliberado, para no pisar lo que ya haya configurado.
INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'email_activo', '0'
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (negocio_id, clave, valor)
SELECT n.id, 'email_destino', ''
  FROM negocios n
ON DUPLICATE KEY UPDATE valor = valor;

-- ------------------------------------------------------------
-- VERIFICACIÓN
-- ------------------------------------------------------------
--   SHOW CREATE TABLE notificaciones\G
--     -> `canal` presente, `destino` VARCHAR(150), ENUM de 7 tipos.
--
--   SELECT canal, COUNT(*) FROM notificaciones GROUP BY canal;
--     -> todo lo viejo sigue en 'whatsapp'.
--
--   SELECT n.id, n.nombre,
--          MAX(CASE WHEN c.clave = 'email_activo'  THEN c.valor END) AS activo,
--          MAX(CASE WHEN c.clave = 'email_destino' THEN c.valor END) AS destino
--     FROM negocios n LEFT JOIN configuracion c ON c.negocio_id = n.id
--    GROUP BY n.id, n.nombre;
--     -> cada negocio con sus dos claves, en 0 y vacío.
