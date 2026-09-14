-- ============================================================
-- stockIAte - migracion_reset_password.sql
-- ============================================================
-- Recuperación de contraseña por email (autoservicio).
--
-- Agrega dos cosas:
--
--   * la tabla `password_resets`, que son los tokens de un solo uso que
--     viajan en el link del mail;
--   * la columna `usuarios.password_cambiado_en`, que es lo que echa a las
--     sesiones abiertas cuando alguien cambia su contraseña (ver sesion.php,
--     `sesion_actual()`).
--
-- ADITIVA Y SEGURA CON EL CÓDIGO VIEJO CORRIENDO
-- ----------------------------------------------
-- Crea una tabla nueva y suma una columna NULLable con default NULL. Nada de
-- lo que ya existe cambia de forma, así que se puede correr con el sistema
-- andando y antes de desplegar el código nuevo.
--
-- No hace falta si la base se crea desde cero: schema.sql ya trae las dos
-- cosas.
--
-- Uso:
--   mysql -u root --default-character-set=utf8mb4 stockiate < migracion_reset_password.sql
-- ============================================================

USE stockiate;

-- ------------------------------------------------------------
-- 1. Tokens de reseteo
-- ------------------------------------------------------------
-- POR QUÉ SE GUARDA EL HASH DEL TOKEN Y NO EL TOKEN
-- -------------------------------------------------
-- `invitaciones` guarda su token en claro y está bien: con ese token lo único
-- que se puede hacer es crear una cuenta nueva que todavía no existe. Un
-- token de reseteo es otra cosa: abre una cuenta EXISTENTE, con las ventas
-- reales del comercio adentro. Si alguien consigue leer esta tabla (un dump,
-- un backup mal guardado, una inyección en cualquier otro endpoint), con los
-- tokens en claro se lleva todas las cuentas con un reseteo vivo.
--
-- Guardando `SHA2(token, 256)` la tabla deja de ser un llavero: el token en
-- claro existe sólo en el mail y en la barra de direcciones de quien lo
-- recibió. El costo es que el token no se puede volver a mostrar (si se
-- pierde el mail, se pide otro), que es exactamente lo que se quiere.
--
-- POR QUÉ NO TIENE `negocio_id`
-- -----------------------------
-- Es la excepción deliberada a la regla de "toda tabla de datos lleva
-- negocio_id". Esta tabla se consulta ANTES de que exista una sesión, o sea
-- sin ningún `negocio_id` con el cual filtrar: la búsqueda es por token, que
-- es un secreto global y no tiene contexto de negocio (mismo criterio que el
-- `token` UNIQUE global de `invitaciones`). El negocio se deriva del usuario,
-- que sí lo tiene.
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,   -- SHA-256 en hex del token real
    expira_at DATETIME NOT NULL,           -- 1 hora, no 7 días como la invitación
    usada_at DATETIME NULL,                -- NULL = todavía se puede canjear
    enviado_at DATETIME NULL,              -- cuándo salió el mail (NULL = no salió)
    error TEXT NULL,                       -- el error crudo del SMTP, si falló
    ip_solicitud VARCHAR(45) NULL,         -- 45 = IPv6 con formato largo
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    -- El índice sirve a las dos queries calientes: los tokens vivos de un
    -- usuario (para anularlos al pedir uno nuevo) y el conteo del límite por
    -- hora.
    INDEX idx_password_resets_usuario (usuario_id, creado_en)
);

-- ------------------------------------------------------------
-- 2. Cuándo cambió su contraseña cada usuario
-- ------------------------------------------------------------
-- Sin esto, resetear la contraseña no echa a nadie: si a alguien le robaron
-- la cuenta, el atacante sigue adentro con su cookie de sesión intacta y el
-- reseteo no sirvió para nada. Las sesiones de PHP son archivos en disco y no
-- se pueden barrer por usuario, así que la marca va acá y `sesion_actual()`
-- la compara contra la que guardó la sesión al abrirse.
--
-- Arranca en NULL para todos, y `sesion_actual()` trata NULL y NULL como
-- iguales: nadie tiene que volver a loguearse por correr esta migración.
--
-- MySQL 8 / MariaDB 10.4 no tienen ADD COLUMN IF NOT EXISTS en todas las
-- variantes, así que se pregunta primero por information_schema. Correrla dos
-- veces no falla.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'usuarios'
       AND COLUMN_NAME = 'password_cambiado_en'
);

SET @sql := IF(@existe = 0,
    'ALTER TABLE usuarios ADD COLUMN password_cambiado_en DATETIME NULL DEFAULT NULL AFTER password_hash',
    'SELECT "La columna password_cambiado_en ya existe: no se hace nada" AS aviso'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
