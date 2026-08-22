-- ============================================================
-- stockIAte - Esquema de base de datos MySQL (XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS stockiate CHARACTER SET utf8mb4;
USE stockiate;

-- ------------------------------------------------------------
-- USUARIOS Y ROLES (login diferenciado)
-- ------------------------------------------------------------
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('repositor', 'cajero', 'dueño') NOT NULL,  -- 'dueño' = rol Administrador en la UI
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- PRODUCTOS (catálogo base: perfumes, cosméticos, variantes)
-- ------------------------------------------------------------
CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    marca VARCHAR(100),
    variante VARCHAR(100),          -- ej: "Fragancia A 100ml" vs "Fragancia B 100ml" (mismo envase)
    categoria VARCHAR(100),
    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_actual INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 5,   -- umbral de stock bajo (configurable por producto)
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- LOTES DE STOCK (permite fecha de vencimiento opcional por lote)
-- Un producto puede tener varios lotes con distintas fechas de carga/vencimiento
-- ------------------------------------------------------------
CREATE TABLE lotes_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    fecha_carga DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento DATE NULL,   -- opcional: si es NULL, no se controla vencimiento
    usuario_id INT NULL,           -- quién cargó el lote (repositor)
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- VENTAS (módulo cajero)
-- ------------------------------------------------------------
CREATE TABLE ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- CORRECCIONES DE IA (feedback loop - Red de Seguridad)
-- Registra cada vez que el repositor corrige lo que detectó YOLO
-- ------------------------------------------------------------
CREATE TABLE correcciones_ia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_detectado_id INT NULL,   -- lo que la IA pensó que era
    producto_corregido_id INT NULL,   -- lo que el repositor confirmó
    cantidad_detectada INT NOT NULL,
    cantidad_corregida INT NOT NULL,
    confianza_ia DECIMAL(5,2) NULL,   -- score de confianza que devuelve Roboflow
    usuario_id INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_detectado_id) REFERENCES productos(id),
    FOREIGN KEY (producto_corregido_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ------------------------------------------------------------
-- CONFIGURACIÓN GENERAL (umbral de vencimiento, etc.)
-- Tabla clave-valor simple para ajustes editables desde el dashboard
-- ------------------------------------------------------------
CREATE TABLE configuracion (
    clave VARCHAR(50) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
);

-- Valor por defecto: alertar cuando falten 30 días o menos para vencer
INSERT INTO configuracion (clave, valor) VALUES ('umbral_dias_vencimiento', '30');

-- ------------------------------------------------------------
-- CHATBOT IA (historial de preguntas/respuestas del asistente)
-- Log de cada intercambio con el chatbot, para auditoría y
-- evaluación de calidad de respuestas.
-- ------------------------------------------------------------
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
-- Índices útiles para las queries del dashboard
-- ------------------------------------------------------------
CREATE INDEX idx_lotes_vencimiento ON lotes_stock (fecha_vencimiento);
CREATE INDEX idx_ventas_fecha ON ventas (fecha);
CREATE INDEX idx_ventas_producto ON ventas (producto_id);
CREATE INDEX idx_chatbot_fecha ON chatbot_conversaciones (fecha);
