-- ============================================================
-- stockIAte - Migración: multi-tenant (negocios e invitaciones)
-- ============================================================
-- Ejecutar sobre una base `stockiate` creada con una versión anterior de
-- schema.sql (mono-negocio: sin tabla `negocios`, sin `negocio_id` en ningún
-- lado, sin `invitaciones`). Si la base se crea desde cero con el schema.sql
-- actual, esta migración NO es necesaria.
--
-- ┌──────────────────────────────────────────────────────────────────────┐
-- │  ESTE ARCHIVO SE CORRE EN DOS TANDAS, NO DE UNA SOLA VEZ.            │
-- │                                                                      │
-- │  PARTE A  -> se puede correr con el código VIEJO todavía andando.    │
-- │              Agrega todo como NULL y solo AFLOJA restricciones.      │
-- │  PARTE B  -> recién DESPUÉS de desplegar el código nuevo y verificar.│
-- │              Pone NOT NULL y cierra las FK compuestas.               │
-- │                                                                      │
-- │  Por qué separadas: si ponés NOT NULL antes de desplegar el código   │
-- │  nuevo, el primer INSERT del código viejo (que no manda negocio_id)  │
-- │  falla con error 1364 y el cajero no puede vender.                   │
-- └──────────────────────────────────────────────────────────────────────┘
--
-- ANTES DE EMPEZAR: hacé un backup.
--   mysqldump -u root stockiate > backup_pre_multitenant.sql
--
-- ⚠️ CORRELO EN UTF-8: la tabla `invitaciones` declara un ENUM con 'dueño'.
--    Desde la consola de Windows, `mysql -u root < archivo.sql` usa el
--    codepage de la consola y guarda 'due├▒o'; después las invitaciones de
--    administrador guardan '' en silencio. Usá siempre:
--        mysql -u root --default-character-set=utf8mb4 < migracion_multitenant.sql

USE stockiate;

-- ============================================================
-- PARTE A  (segura con el código viejo desplegado)
-- ============================================================

-- ------------------------------------------------------------
-- A1. Tabla `negocios`. Sin la FK a usuarios todavía: la dependencia entre
--     negocios.creado_por y usuarios.negocio_id es circular.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS negocios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    creado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- A2. El negocio que adopta TODOS los datos que ya tenías (productos,
--     ventas, lotes, usuarios reales). Queda con id = 1.
--
--     👉 CAMBIÁ EL NOMBRE DE ABAJO por el de tu comercio antes de correr
--        esto. Es el que van a ver tus usuarios en el panel.
--
--     ⚠️ NO lo llames 'Negocio Demo'. Ese nombre está reservado: es el que
--        busca sesion_demo.php para resolver el negocio del backdoor de
--        testeo (ver A4b). Si el negocio de tus datos reales se llamara así,
--        cualquiera que le pegue al backdoor entraría directo a ver tu
--        inventario y tus ventas.
-- ------------------------------------------------------------
INSERT INTO negocios (nombre) VALUES ('Mi Perfumería');

-- ------------------------------------------------------------
-- A3. Columnas `negocio_id`, TODAS NULL en este paso.
-- ------------------------------------------------------------
ALTER TABLE usuarios        ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE proveedores     ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE productos       ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE lotes_stock     ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE ventas          ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE correcciones_ia ADD COLUMN negocio_id INT NULL AFTER id;
ALTER TABLE configuracion   ADD COLUMN negocio_id INT NULL FIRST;

-- ------------------------------------------------------------
-- A4. Backfill: todo lo que existe pasa a ser del negocio 1.
-- ------------------------------------------------------------
UPDATE usuarios        SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE proveedores     SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE productos       SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE lotes_stock     SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE ventas          SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE correcciones_ia SET negocio_id = 1 WHERE negocio_id IS NULL;
UPDATE configuracion   SET negocio_id = 1 WHERE negocio_id IS NULL;

-- ------------------------------------------------------------
-- A4b. Sacar las cuentas del backdoor de testeo a SU PROPIO negocio.
--
--      `sesion_demo.php` entrega una sesión sin pedir contraseña. Sus 3
--      cuentas (demo.repositor@ / demo.cajero@ / demo.admin@stockiate.test)
--      pueden existir ya en tu base, de haber usado el "Modo prueba" antes
--      de la migración. Si quedaran en el negocio 1, el backdoor daría
--      acceso directo a tus datos reales.
--
--      El nombre 'Negocio Demo' tiene que ser EXACTAMENTE ése: es el que
--      busca negocio_demo_id() en sesion_demo.php. Si no encuentra el
--      negocio, lo crea; al crearlo acá nos aseguramos de que las cuentas
--      demo preexistentes también terminen adentro.
--
--      Si nunca usaste el Modo prueba, el UPDATE no matchea ninguna fila y
--      el negocio queda vacío esperando. Es lo correcto.
-- ------------------------------------------------------------
INSERT INTO negocios (nombre) VALUES ('Negocio Demo');

UPDATE usuarios
   SET negocio_id = (SELECT id FROM negocios WHERE nombre = 'Negocio Demo')
 WHERE email LIKE '%@stockiate.test';

-- (La fila de `configuracion` del negocio demo se inserta en A5b, DESPUÉS de
--  que la PK de esa tabla pase a ser compuesta. Acá todavía la PK es `clave`
--  a secas, así que una segunda fila con la misma clave rebotaría.)

-- ------------------------------------------------------------
-- A5. UNIQUE globales -> UNIQUE por negocio.
--     Estos cambios AFLOJAN la restricción, así que el código viejo los
--     tolera sin enterarse.
--
--     ⚠️ OJO CON LOS NOMBRES DE ÍNDICE. MySQL nombra los UNIQUE declarados
--     inline (`sku VARCHAR(50) NOT NULL UNIQUE`) con el nombre de la columna,
--     pero eso depende de la versión y de cómo se creó la base. VERIFICÁ
--     ANTES de correr estas cuatro líneas:
--         SHOW INDEX FROM productos;
--         SHOW INDEX FROM proveedores;
--     y ajustá el nombre del DROP INDEX si no coincide.
-- ------------------------------------------------------------
ALTER TABLE proveedores
    DROP INDEX nombre,
    ADD UNIQUE KEY uq_proveedores_negocio_nombre (negocio_id, nombre);

ALTER TABLE productos
    DROP INDEX sku,
    ADD UNIQUE KEY uq_productos_negocio_sku (negocio_id, sku);

-- `configuracion` pasa de PK (clave) a PK (negocio_id, clave).
-- Va DESPUÉS del backfill de A4: una PRIMARY KEY no admite NULL, así que
-- este ALTER falla si quedó alguna fila sin negocio_id.
ALTER TABLE configuracion
    MODIFY negocio_id INT NOT NULL,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (negocio_id, clave);

-- ------------------------------------------------------------
-- A5b. Recién ahora que la PK de `configuracion` es (negocio_id, clave) se
--      puede darle su propia fila de configuración al negocio demo, sin
--      chocar con la del negocio real.
-- ------------------------------------------------------------
INSERT INTO configuracion (negocio_id, clave, valor)
SELECT id, 'umbral_dias_vencimiento', '30'
  FROM negocios WHERE nombre = 'Negocio Demo';

-- ------------------------------------------------------------
-- A6. FK simples a `negocios` + la FK circular que faltaba.
--     (Las FK COMPUESTAS producto<->negocio van en la PARTE B.)
-- ------------------------------------------------------------
ALTER TABLE usuarios
    ADD CONSTRAINT fk_usuarios_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE proveedores
    ADD CONSTRAINT fk_proveedores_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE productos
    ADD CONSTRAINT fk_productos_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE lotes_stock
    ADD CONSTRAINT fk_lotes_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE ventas
    ADD CONSTRAINT fk_ventas_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE correcciones_ia
    ADD CONSTRAINT fk_correcciones_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);
ALTER TABLE configuracion
    ADD CONSTRAINT fk_configuracion_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id);

ALTER TABLE negocios
    ADD CONSTRAINT fk_negocios_creador FOREIGN KEY (creado_por) REFERENCES usuarios(id);

-- Dejamos el negocio 1 apuntando a algún dueño existente, si lo hay.
UPDATE negocios
   SET creado_por = (SELECT id FROM usuarios WHERE negocio_id = 1 AND rol = 'dueño' ORDER BY id LIMIT 1)
 WHERE id = 1 AND creado_por IS NULL;

-- ------------------------------------------------------------
-- A7. Índices: los del dashboard pasan a arrancar por negocio_id, porque
--     TODA query filtra primero por negocio.
-- ------------------------------------------------------------
DROP INDEX idx_lotes_vencimiento ON lotes_stock;
CREATE INDEX idx_lotes_vencimiento ON lotes_stock (negocio_id, fecha_vencimiento);

DROP INDEX idx_ventas_fecha ON ventas;
CREATE INDEX idx_ventas_fecha ON ventas (negocio_id, fecha);

CREATE INDEX idx_usuarios_negocio ON usuarios (negocio_id);

-- ------------------------------------------------------------
-- A8. Invitaciones (reemplazan el registro abierto con rol a elección).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    rol ENUM('repositor', 'cajero', 'dueño') NOT NULL,
    token CHAR(36) NOT NULL UNIQUE,
    expira_at DATETIME NOT NULL,
    usada_at DATETIME NULL,
    creado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    INDEX idx_invitaciones_negocio (negocio_id, usada_at)
);


-- ============================================================
-- PARTE B  --  NO CORRER TODAVÍA
-- ============================================================
-- Correr SOLO después de:
--   1. desplegar el código nuevo (sesion.php + endpoints filtrados), y
--   2. verificar que no quedaron NULLs con el chequeo de B0.
--
-- Con el código viejo todavía activo, estos ALTER rompen todos los INSERT.
-- ============================================================

-- ------------------------------------------------------------
-- B0. Chequeo previo. Las 6 filas tienen que dar 0.
-- ------------------------------------------------------------
-- SELECT 'usuarios' tabla, COUNT(*) nulos FROM usuarios WHERE negocio_id IS NULL
-- UNION ALL SELECT 'proveedores',     COUNT(*) FROM proveedores     WHERE negocio_id IS NULL
-- UNION ALL SELECT 'productos',       COUNT(*) FROM productos       WHERE negocio_id IS NULL
-- UNION ALL SELECT 'lotes_stock',     COUNT(*) FROM lotes_stock     WHERE negocio_id IS NULL
-- UNION ALL SELECT 'ventas',          COUNT(*) FROM ventas          WHERE negocio_id IS NULL
-- UNION ALL SELECT 'correcciones_ia', COUNT(*) FROM correcciones_ia WHERE negocio_id IS NULL;

-- ------------------------------------------------------------
-- B1. NOT NULL. (`configuracion` ya quedó NOT NULL en A5, por la PK.)
-- ------------------------------------------------------------
-- ALTER TABLE usuarios        MODIFY negocio_id INT NOT NULL;
-- ALTER TABLE proveedores     MODIFY negocio_id INT NOT NULL;
-- ALTER TABLE productos       MODIFY negocio_id INT NOT NULL;
-- ALTER TABLE lotes_stock     MODIFY negocio_id INT NOT NULL;
-- ALTER TABLE ventas          MODIFY negocio_id INT NOT NULL;
-- ALTER TABLE correcciones_ia MODIFY negocio_id INT NOT NULL;

-- ------------------------------------------------------------
-- B2. FK compuestas: la garantía a nivel de motor de que una venta/lote/
--     corrección no puede apuntar a un producto de OTRO negocio, ni un
--     producto a un proveedor de otro negocio. Es la única defensa real
--     contra un producto_id ajeno inyectado en el body de un request.
--
--     ⚠️ Hay que DROPEAR primero las FK simples a productos(id). Sus nombres
--     los generó MySQL automáticamente. Los de abajo se verificaron contra
--     una copia de la base real de este proyecto y coinciden, pero si tu base
--     se creó de otra forma, confirmalos antes:
--         SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME
--           FROM information_schema.KEY_COLUMN_USAGE
--          WHERE TABLE_SCHEMA='stockiate'
--            AND REFERENCED_TABLE_NAME IN ('productos','proveedores');
--
--     ⚠️ OJO CON `fk_productos_proveedor`: al dropear una FK, MariaDB deja
--     el índice que la acompañaba, con el mismo nombre. Si la nueva
--     constraint se llamara igual, el ALTER falla con "Duplicate key name"
--     (error 1061) aunque la FK vieja se haya dropeado en la misma
--     sentencia. Por eso acá se dropea también el índice y la constraint
--     nueva se llama distinto. Las otras tres no tienen el problema porque
--     ya cambian de nombre (ibfk_N -> fk_*).
-- ------------------------------------------------------------
-- -- Claves candidatas que las FK compuestas necesitan del lado padre:
-- ALTER TABLE productos   ADD UNIQUE KEY uq_productos_id_negocio (id, negocio_id);
-- ALTER TABLE proveedores ADD UNIQUE KEY uq_proveedores_id_negocio (id, negocio_id);
--
-- ALTER TABLE productos
--     DROP FOREIGN KEY fk_productos_proveedor,
--     DROP INDEX fk_productos_proveedor,
--     ADD CONSTRAINT fk_productos_proveedor_negocio
--         FOREIGN KEY (proveedor_id, negocio_id) REFERENCES proveedores(id, negocio_id);
--
-- ALTER TABLE lotes_stock
--     DROP FOREIGN KEY lotes_stock_ibfk_1,
--     ADD CONSTRAINT fk_lotes_producto
--         FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id);
--
-- ALTER TABLE ventas
--     DROP FOREIGN KEY ventas_ibfk_1,
--     ADD CONSTRAINT fk_ventas_producto
--         FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id);
--
-- ALTER TABLE correcciones_ia
--     DROP FOREIGN KEY correcciones_ia_ibfk_1,
--     DROP FOREIGN KEY correcciones_ia_ibfk_2,
--     ADD CONSTRAINT fk_correcciones_detectado
--         FOREIGN KEY (producto_detectado_id, negocio_id) REFERENCES productos(id, negocio_id),
--     ADD CONSTRAINT fk_correcciones_corregido
--         FOREIGN KEY (producto_corregido_id, negocio_id) REFERENCES productos(id, negocio_id);

-- ------------------------------------------------------------
-- B3. Verificación final del aislamiento. Tiene que devolver CERO filas:
--     ninguna fila hija puede tener un negocio_id distinto al de su padre.
-- ------------------------------------------------------------
-- SELECT 'ventas' tabla, v.id FROM ventas v
--     JOIN productos p ON p.id = v.producto_id WHERE v.negocio_id <> p.negocio_id
-- UNION ALL SELECT 'lotes', l.id FROM lotes_stock l
--     JOIN productos p ON p.id = l.producto_id WHERE l.negocio_id <> p.negocio_id
-- UNION ALL SELECT 'ventas_usuario', v.id FROM ventas v
--     JOIN usuarios u ON u.id = v.usuario_id WHERE v.negocio_id <> u.negocio_id
-- UNION ALL SELECT 'lotes_usuario', l.id FROM lotes_stock l
--     JOIN usuarios u ON u.id = l.usuario_id WHERE l.negocio_id <> u.negocio_id
-- UNION ALL SELECT 'producto_proveedor', p.id FROM productos p
--     JOIN proveedores pr ON pr.id = p.proveedor_id WHERE p.negocio_id <> pr.negocio_id
-- UNION ALL SELECT 'correccion', c.id FROM correcciones_ia c
--     JOIN productos p ON p.id = c.producto_corregido_id WHERE c.negocio_id <> p.negocio_id;
