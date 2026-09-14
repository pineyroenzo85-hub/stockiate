-- ============================================================
-- stockIAte - Esquema de base de datos MySQL (XAMPP)
-- ============================================================
-- MULTI-TENANT: cada comercio que se registra es un `negocio` y todos sus
-- datos (usuarios, productos, stock, ventas, proveedores, configuración)
-- viven aislados del resto. La pertenencia se materializa como una columna
-- `negocio_id` en cada tabla de datos -- NO se deriva por JOIN.
--
-- Por qué denormalizada y no por JOIN: hay ~16 endpoints PHP y varios hacen
-- agregados sobre `ventas` sin joinear `productos`. Con la columna, el filtro
-- correcto es siempre el mismo (`WHERE negocio_id = :negocio_id`), tanto en
-- la query que joinea como en la que no, y la regla queda auditable con un
-- grep. MySQL no tiene RLS: el aislamiento lo garantiza el código PHP
-- (`sesion.php` -> `exigir_sesion()`), apoyado en las FK compuestas de abajo.

-- ⚠️ IMPORTALO EN UTF-8 O SE ROMPE EL ROL 'dueño'.
--    Desde la consola de Windows, `mysql -u root < schema.sql` usa el
--    codepage de la consola y guarda el ENUM como enum(...,'due├▒o'). Después
--    todo INSERT con 'dueño' no matchea ningún valor del ENUM y MariaDB
--    (modo no estricto) guarda '' EN SILENCIO: te quedan usuarios sin rol y
--    nada falla de forma visible. Usá siempre:
--        mysql -u root --default-character-set=utf8mb4 < schema.sql
--    Desde phpMyAdmin no pasa (ya importa en UTF-8).
--    Para verificar: SHOW CREATE TABLE usuarios\G debe decir 'dueño'.

CREATE DATABASE IF NOT EXISTS stockiate CHARACTER SET utf8mb4;
USE stockiate;

-- ------------------------------------------------------------
-- NEGOCIOS (el tenant)
-- ------------------------------------------------------------
-- `creado_por` apunta al usuario dueño que dio de alta el negocio, pero
-- `usuarios.negocio_id` apunta de vuelta acá: la dependencia es circular, así
-- que la FK se agrega con un ALTER al final del archivo.
CREATE TABLE negocios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    creado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- USUARIOS Y ROLES (login diferenciado)
-- ------------------------------------------------------------
-- `rol` sigue siendo el mismo ENUM de siempre, pero ahora se lee en clave de
-- negocio: 'dueño' = administrador DE SU negocio (el único que invita gente y
-- borra productos), 'repositor'/'cajero' = empleados de ese negocio.
--
-- `email` sigue siendo UNIQUE GLOBAL, no por negocio. Decisión consciente:
-- iniciar_sesion.php busca solo por email, así que un email por negocio
-- obligaría a una pantalla de "elegí tu negocio" antes de loguear. La
-- consecuencia es que una persona = una cuenta = un negocio; si alguien
-- trabaja en dos comercios necesita dos emails. La solución completa sería
-- una tabla `membresias` (usuario N:M negocio, con el rol en la membresía),
-- que es un rediseño mayor del login y queda fuera de alcance.
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- Cuándo cambió la contraseña por última vez. NULL = nunca la cambió.
    -- No es un dato de auditoría: es lo que echa a las sesiones abiertas
    -- cuando alguien resetea su contraseña. Ver sesion_actual() en sesion.php
    -- y password_resets más abajo.
    password_cambiado_en DATETIME NULL DEFAULT NULL,
    rol ENUM('repositor', 'cajero', 'dueño') NOT NULL,  -- 'dueño' = rol Administrador en la UI
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    INDEX idx_usuarios_negocio (negocio_id)
);

-- ------------------------------------------------------------
-- INVITACIONES (reemplazan el registro abierto)
-- ------------------------------------------------------------
-- Antes cualquiera con la URL de registro.html elegía su propio rol en un
-- <select>, incluido 'dueño'. Ahora hay dos caminos de alta y ninguno deja
-- elegir el rol libremente:
--   a) crear_negocio.php      -> negocio nuevo, el que lo crea queda 'dueño'
--   b) aceptar_invitacion.php -> se une a un negocio existente con el rol que
--      dice la invitación (el rol NUNCA sale del body del request)
--
-- `token` es UNIQUE global y no por negocio: es un secreto que se busca sin
-- ningún contexto previo (la persona invitada todavía no tiene sesión).
CREATE TABLE invitaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    rol ENUM('repositor', 'cajero', 'dueño') NOT NULL,
    token CHAR(36) NOT NULL UNIQUE,
    expira_at DATETIME NOT NULL,
    usada_at DATETIME NULL,          -- NULL = todavía disponible
    creado_por INT NULL,             -- qué dueño la generó
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    INDEX idx_invitaciones_negocio (negocio_id, usada_at)
);

-- ------------------------------------------------------------
-- RESETEO DE CONTRASEÑA (recuperación por email)
-- ------------------------------------------------------------
-- Tokens de un solo uso que viajan en el link del mail de "olvidé mi
-- contraseña". Los escribe solicitar_reset.php y los canjea
-- restablecer_password.php.
--
-- SE GUARDA EL HASH DEL TOKEN, NO EL TOKEN. `invitaciones` guarda el suyo en
-- claro y está bien (sólo sirve para crear una cuenta que todavía no existe);
-- éste abre una cuenta EXISTENTE con las ventas del comercio adentro, así que
-- si alguien lee esta tabla no se puede llevar nada. El token en claro vive
-- sólo en el mail.
--
-- ES LA EXCEPCIÓN A "toda tabla lleva negocio_id": se consulta antes de que
-- exista una sesión, o sea sin ningún negocio_id con el cual filtrar. La
-- búsqueda es por token, que es un secreto global (mismo criterio que el
-- `token` de invitaciones). El negocio se deriva del usuario.
CREATE TABLE password_resets (
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
    INDEX idx_password_resets_usuario (usuario_id, creado_en)
);

-- ------------------------------------------------------------
-- PROVEEDORES (opcional: de quién se compra cada producto)
-- Se dan de alta solos desde la pantalla de alta de producto:
-- si el repositor escribe un proveedor que no existe, crear_producto.php
-- lo inserta acá y lo asocia.
-- ------------------------------------------------------------
-- El UNIQUE es (negocio_id, nombre), no `nombre` a secas: dos comercios
-- distintos pueden comprarle a "Distribuidora Sur" sin compartir la fila.
CREATE TABLE proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proveedores_negocio_nombre (negocio_id, nombre),
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    -- Clave candidata redundante: habilita la FK compuesta de `productos`,
    -- que es lo que impide asociar un proveedor de otro negocio.
    UNIQUE KEY uq_proveedores_id_negocio (id, negocio_id)
);

-- ------------------------------------------------------------
-- PRODUCTOS (catálogo base: perfumes, cosméticos, variantes)
-- ------------------------------------------------------------
-- El SKU es único POR NEGOCIO: dos comercios pueden tener cada uno su
-- "PER-001" sin pisarse (ver generarSku() en crear_producto.php, que scanea
-- los SKU existentes filtrando por negocio).
CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    sku VARCHAR(50) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    marca VARCHAR(100),
    variante VARCHAR(100),          -- ej: "Fragancia A 100ml" vs "Fragancia B 100ml" (mismo envase)
    categoria VARCHAR(100),
    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0,
    precio_costo DECIMAL(10,2) NULL,       -- opcional: lo que cuesta comprarlo
    proveedor_id INT NULL,                 -- opcional: a quién se le compra
    stock_actual INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 5,   -- umbral de stock bajo (configurable por producto)
    -- BAJA LÓGICA. El botón "eliminar" del panel archiva: pone activo = 0.
    -- No se borra la fila porque `ventas`, `lotes_stock` y `correcciones_ia`
    -- le apuntan con FK sin ON DELETE CASCADE (a propósito: borrar en
    -- cascada destruiría registros de ventas reales), así que un producto
    -- con historial era literalmente imposible de borrar.
    -- Todas las pantallas y herramientas filtran `activo = 1`, EXCEPTO
    -- consultar_ventas.php: ése es historial, y filtrarlo haría desaparecer
    -- facturación real al archivar un producto.
    -- El SKU de un archivado sigue ocupado (el UNIQUE de abajo aplica igual).
    activo TINYINT(1) NOT NULL DEFAULT 1,
    archivado_en DATETIME NULL,
    -- Última foto sacada con la cámara en la Red de Seguridad (nombre del
    -- archivo en uploads/productos/). La guarda subir_foto_producto.php.
    foto VARCHAR(100) NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_productos_negocio_sku (negocio_id, sku),
    -- Clave candidata redundante: habilita las FK compuestas de lotes_stock,
    -- ventas y correcciones_ia.
    UNIQUE KEY uq_productos_id_negocio (id, negocio_id),
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    -- FK compuesta: el proveedor tiene que ser del MISMO negocio que el
    -- producto. Sin esto, un proveedor_id ajeno pasaría silenciosamente.
    FOREIGN KEY (proveedor_id, negocio_id) REFERENCES proveedores(id, negocio_id),
    -- (negocio_id, activo) juntos: es el filtro que corre en cada carga del
    -- panel y de los <select> de repositor/cajero.
    KEY idx_productos_negocio_activo (negocio_id, activo)
);

-- ------------------------------------------------------------
-- LOTES DE STOCK (permite fecha de vencimiento opcional por lote)
-- Un producto puede tener varios lotes con distintas fechas de carga/vencimiento
-- ------------------------------------------------------------
CREATE TABLE lotes_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    fecha_carga DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento DATE NULL,   -- opcional: si es NULL, no se controla vencimiento
    usuario_id INT NULL,           -- quién cargó el lote (repositor)
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    -- Compuesta: el lote no puede apuntar a un producto de otro negocio.
    FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- VENTAS (módulo cajero)
-- ------------------------------------------------------------
CREATE TABLE ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    -- SNAPSHOT del costo al momento de vender, copiado de
    -- productos.precio_costo por registrar_venta.php. No es redundante con
    -- esa columna: el costo de un producto cambia cuando el proveedor
    -- aumenta, y sin este snapshot toda la historia de márgenes se
    -- recalcularía sola con el costo de hoy (una venta de marzo mostraría la
    -- ganancia que habría dejado a precios de agosto).
    -- NULL cuando el producto todavía no tiene precio_costo cargado; el
    -- chatbot avisa que ese tramo del cálculo es parcial.
    costo_unitario DECIMAL(10,2) NULL,
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- MOVIMIENTOS DE STOCK (el libro mayor del inventario)
-- ------------------------------------------------------------
-- Una fila por cada vez que cambia `productos.stock_actual`, sin importar
-- quién ni por qué. Antes, las entradas quedaban en `lotes_stock`, las
-- salidas por venta en `ventas`, y los ajustes de los botones +/- del panel
-- NO QUEDABAN EN NINGÚN LADO: si el sistema decía 12 y en la góndola había 9,
-- no había forma de saber si faltaba mercadería, si alguien había tocado un
-- botón de más, o si una venta se había registrado mal.
--
-- `stock_anterior` y `stock_nuevo` parecen redundantes con `delta` y no lo
-- son: con ellos cada fila se valida sola (`anterior + delta = nuevo`) y
-- cualquier salto entre el `stock_nuevo` de un movimiento y el
-- `stock_anterior` del siguiente señala exactamente dónde alguien escribió por
-- afuera del libro. Sin ellos habría que sumar la historia entera desde el
-- principio, y un solo movimiento perdido arrastraría el error hacia adelante
-- sin que nada lo delate.
--
-- La escritura vive en `movimientos.php` (`registrar_movimiento()`), y va
-- SIEMPRE dentro de la misma transacción que el UPDATE del stock -- al revés
-- que el encolado de notificaciones, que va después del commit a propósito.
CREATE TABLE movimientos_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    -- carga: entró mercadería · venta: salió por caja · ajuste: los +/- del
    -- panel · conteo: se corrigió contra un conteo físico
    motivo ENUM('carga', 'venta', 'ajuste', 'conteo') NOT NULL,
    delta INT NOT NULL,              -- con signo; nunca 0
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,
    -- A qué fila de otra tabla corresponde, cuando corresponde a alguna. Va
    -- como (tipo, id) y no como una FK por destino posible: serían tres
    -- columnas nullables que casi siempre están vacías.
    referencia_tipo VARCHAR(20) NULL,
    referencia_id INT NULL,
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    KEY idx_mov_producto_fecha (negocio_id, producto_id, fecha),
    KEY idx_mov_negocio_fecha (negocio_id, fecha),
    KEY idx_mov_referencia (referencia_tipo, referencia_id)
);

-- ------------------------------------------------------------
-- CORRECCIONES DE IA (feedback loop - Red de Seguridad)
-- Registra cada vez que el repositor corrige lo que detectó YOLO
-- ------------------------------------------------------------
CREATE TABLE correcciones_ia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_detectado_id INT NULL,   -- lo que la IA pensó que era
    producto_corregido_id INT NULL,   -- lo que el repositor confirmó
    cantidad_detectada INT NOT NULL,
    cantidad_corregida INT NOT NULL,
    confianza_ia DECIMAL(5,2) NULL,   -- score de confianza que devuelve Roboflow
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_detectado_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (producto_corregido_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- OFERTAS (precio promocional con vigencia)
-- ------------------------------------------------------------
-- La usa `carteles.html` para imprimir los carteles de góndola: un cartel de
-- oferta dice el precio anterior tachado, el nuevo, y hasta cuándo vale.
--
-- Es deliberadamente mínima: no hay motor de promociones, ni 2x1, ni
-- descuentos por categoría. Sólo "este producto, a este precio, desde acá
-- hasta acá".
--
-- OJO: las ventas NO leen esta tabla. `registrar_venta.php` sigue cobrando
-- `productos.precio_venta`. Ver la nota de `migracion_ofertas.sql`.
--
-- `precio_anterior` es un SNAPSHOT, por el mismo motivo que
-- `ventas.costo_unitario`: el "antes" que se imprime tiene que ser el precio
-- que regía cuando se armó la oferta, no el de hoy. Si saliera por JOIN de
-- `productos`, un aumento de lista cambiaría solo el número tachado del
-- cartel que ya está pegado en la góndola.
CREATE TABLE ofertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NOT NULL,
    precio_oferta DECIMAL(10,2) NOT NULL,
    precio_anterior DECIMAL(10,2) NOT NULL,
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    creado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    FOREIGN KEY (producto_id, negocio_id) REFERENCES productos(id, negocio_id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    KEY idx_ofertas_vigentes (negocio_id, hasta, desde),
    KEY idx_ofertas_producto (producto_id, hasta)
);

-- ------------------------------------------------------------
-- CONFIGURACIÓN GENERAL (umbral de vencimiento, etc.)
-- Tabla clave-valor simple para ajustes editables desde el dashboard
-- ------------------------------------------------------------
-- La PK es (negocio_id, clave): cada negocio elige su propia ventana de
-- vencimiento. No hay fila global sembrada acá -- las filas por defecto las
-- inserta crear_negocio.php al dar de alta el negocio, y de todos modos cada
-- lector cae a un default si no encuentra ninguna.
--
-- Claves en uso:
--   umbral_dias_vencimiento  -- días de anticipación del aviso de vencimiento
--   whatsapp_telefono        -- destinatario de las alertas, E.164 sin '+'
--   whatsapp_activo          -- '1' | '0'
--   whatsapp_hora_resumen    -- 'HH:MM', hora del resumen diario
CREATE TABLE configuracion (
    negocio_id INT NOT NULL,
    clave VARCHAR(50) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    PRIMARY KEY (negocio_id, clave),
    FOREIGN KEY (negocio_id) REFERENCES negocios(id)
);

-- ------------------------------------------------------------
-- NOTIFICACIONES (cola de avisos por WhatsApp)
-- ------------------------------------------------------------
-- Es a la vez COLA y LOG. Nada manda un WhatsApp en el momento: los
-- disparadores (registrar_venta.php, tareas_notificaciones.php) sólo insertan
-- una fila acá, y el script programado la vacía después. Tres motivos:
--
--   1. Una venta no puede depender de que la API de Meta responda. El envío
--      directo le sumaría ~1s a cada cobro y un 500 de Meta le explotaría en
--      la cara al cajero por algo que no tiene nada que ver con la venta.
--   2. Reintentos. Si el token venció o no hay internet, la fila queda
--      'pendiente' y se reintenta sola en la corrida siguiente.
--   3. Deduplicación. Vender 10 unidades de a una dispararía 10 veces la
--      misma alerta; `clave_dedup` + una ventana de tiempo lo cortan.
--
-- Y de yapa queda la auditoría: qué se avisó, cuándo y si llegó.
CREATE TABLE notificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    tipo ENUM('stock_critico', 'sin_stock', 'vencimientos', 'resumen_diario', 'prueba') NOT NULL,
    -- 'prueba' es el botón "Mandar mensaje de prueba" del panel. Se guarda
    -- como una notificación más para que el envío de prueba recorra
    -- exactamente el mismo camino que uno real: si la prueba llega, las
    -- alertas de verdad también.
    -- Identifica el HECHO que se está avisando, no la fila. Ej:
    -- 'stock_critico:42' (producto 42), 'resumen_diario:2026-08-30'.
    clave_dedup VARCHAR(100) NOT NULL,
    -- Snapshot del teléfono al momento de encolar, en E.164 sin '+'. Se copia
    -- en vez de leerlo al enviar para que cambiar el número en el panel no
    -- redirija avisos que ya estaban en la cola.
    destino VARCHAR(20) NOT NULL,
    plantilla VARCHAR(60) NOT NULL,         -- nombre de la plantilla aprobada en Meta
    parametros TEXT NOT NULL,               -- JSON: las variables {{1}}..{{n}} en orden
    estado ENUM('pendiente', 'enviada', 'fallida') NOT NULL DEFAULT 'pendiente',
    intentos TINYINT NOT NULL DEFAULT 0,    -- a los 3 se da por fallida y no se reintenta
    error TEXT NULL,                        -- el mensaje de error de Meta, tal cual vino
    wamid VARCHAR(80) NULL,                 -- id del mensaje que devuelve Meta si salió
    creada_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    enviada_en DATETIME NULL,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id),
    INDEX idx_notif_pendientes (estado, creada_en),
    INDEX idx_notif_dedup (negocio_id, clave_dedup, creada_en)
);

-- ------------------------------------------------------------
-- CHATBOT IA (historial de preguntas/respuestas del asistente)
-- Log de cada intercambio con el chatbot, para auditoría y
-- evaluación de calidad de respuestas.
-- ------------------------------------------------------------
-- Única tabla SIN `negocio_id` propio: es un log de auditoría, no una tabla
-- de datos del negocio, y el tenant se deriva por usuario_id -> usuarios.
-- Consecuencia asumida: las filas con usuario_id NULL quedan sin negocio.
CREATE TABLE chatbot_conversaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rol VARCHAR(20) NOT NULL,               -- 'repositor' | 'cajero' | 'dueño'
    usuario_id INT NULL,
    pregunta TEXT NOT NULL,
    respuesta TEXT NOT NULL,
    herramientas_usadas VARCHAR(255) NULL,  -- ej: "consultar_stock,consultar_ventas"
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- FK circular: negocios.creado_por -> usuarios.id
-- Va acá y no inline porque `usuarios` no existía cuando se creó `negocios`.
-- ------------------------------------------------------------
ALTER TABLE negocios
    ADD CONSTRAINT fk_negocios_creador FOREIGN KEY (creado_por) REFERENCES usuarios(id);

-- ------------------------------------------------------------
-- Índices útiles para las queries del dashboard
-- Todos arrancan por negocio_id porque TODA query filtra primero por negocio.
-- ------------------------------------------------------------
CREATE INDEX idx_lotes_vencimiento ON lotes_stock (negocio_id, fecha_vencimiento);
CREATE INDEX idx_ventas_fecha ON ventas (negocio_id, fecha);
CREATE INDEX idx_ventas_producto ON ventas (producto_id);
CREATE INDEX idx_chatbot_fecha ON chatbot_conversaciones (fecha);
