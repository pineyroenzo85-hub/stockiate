# Arquitectura de stockIAte

Complemento de [CLAUDE.md](../CLAUDE.md) con el detalle completo: qué hace
cada archivo, los flujos paso a paso con sus payloads reales, el esquema de
base de datos y las convenciones del proyecto.

## Capas y responsabilidades

| Archivo | Capa | Rol |
|---|---|---|
| `conexion.php` | PHP/persistencia | Conexión PDO compartida a MySQL (`stockiate`), credenciales hardcodeadas (`root`/sin password, default de XAMPP). Todos los endpoints PHP hacen `require_once` de este archivo. |
| `guardar_stock.php` | PHP/persistencia | Endpoint de carga de stock (rol repositor). Inserta un lote en `lotes_stock` e incrementa `productos.stock_actual`, en una transacción. |
| `registrar_venta.php` | PHP/persistencia | Endpoint de venta (rol cajero). Lockea la fila del producto (`SELECT ... FOR UPDATE`), valida stock, inserta en `ventas` con el precio del servidor, y decrementa `productos.stock_actual`, en una transacción. |
| `registrar_correccion.php` | PHP/persistencia | **No existe.** Referenciado por `main.py` pero nunca creado — ver "Gaps conocidos". |
| `consultar_stock.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Devuelve stock de productos, con filtros opcionales por término/categoría/stock bajo. |
| `consultar_ventas.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Devuelve ventas agregadas (por producto/marca/día) en un rango de fechas. |
| `consultar_vencimientos.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Devuelve lotes de `lotes_stock` próximos a vencer o ya vencidos. |
| `consultar_productos.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Busca en el catálogo por nombre/marca/sku/categoría. |
| `registrar_chatbot_log.php` | PHP/persistencia | Inserta cada intercambio pregunta/respuesta del chatbot en `chatbot_conversaciones`. Lo llama `main.py` en modo best-effort. |
| `schema.sql` | PHP/persistencia | DDL completo: crea la base `stockiate` y sus tablas (incluye `chatbot_conversaciones`). |
| `main.py` | Python/IA | App FastAPI ("Motor de IA"). Expone `/`, `/procesar-imagen`, `/registrar-correccion` y `/chatbot`. No accede a MySQL. |
| `roboflow_workflow.py` | Python/IA | Cliente del Workflow de Roboflow (`inference-sdk`, `InferenceHTTPClient.run_workflow`). Encapsula la llamada, reintentos con backoff, y el parseo de la respuesta (predicciones YOLO + texto OCR de marca). |
| `chatbot_ia.py` | Python/IA | Cliente de la API de Groq (inferencia gratuita con límites de uso, vía el SDK `groq`, formato de tool-calling compatible con OpenAI) con tool-use. Define las 4 herramientas (`consultar_stock`, `consultar_ventas`, `consultar_vencimientos`, `consultar_productos`), restringe cuáles puede usar cada rol (`ROLE_TOOLS`), corre el loop de tool-use, y llama a `registrar_chatbot_log.php` al final. |
| `chatbot_widget.js` | Frontend | Widget de chat flotante compartido, autocontenido (inyecta su propio CSS). Se incluye igual en `repositor.html`, `cajero.html` y `administrador.html` con un `data-rol` distinto por página. |
| `detector.py` | Python/IA (script suelto) | Ejemplo standalone de uso del SDK de Roboflow. No lo importa `main.py`. Tiene una API key hardcodeada — no usar como referencia de configuración. |
| `debug_roboflow_raw.py` | Python/IA (script suelto) | Vuelca la respuesta cruda del workflow para debugging manual. |
| `smoke_test_roboflow.py` | Python/IA (script suelto) | Test de humo manual (no pytest) para verificar conectividad con Roboflow. |
| `landing_page.html` | Frontend | Selector de rol — solo navega a la página correspondiente, no autentica. |
| `repositor.html` | Frontend | Flujo de carga de stock (versión vigente). |
| `index.html` + `script.js` | Frontend | Prototipo viejo del flujo de carga de stock. Superado por `repositor.html` — ver "Gaps conocidos". |
| `cajero.html` | Frontend | Flujo de venta. |
| `administrador.html` | Frontend | Dashboard de admin: KPIs y tabla de inventario siguen mockeados (`localStorage`, ver "Gaps conocidos"), pero el chatbot ya es real (`chatbot_widget.js` con `data-rol="dueño"`, sin restricción de herramientas). |
| `stockiate-panel-admin.html` | Frontend | Archivo vacío, sin usar. |
| `styles.css` | Frontend | Hoja de estilos compartida por `repositor.html`, `cajero.html`, `index.html`, `landing_page.html` (fuentes Fraunces/Inter de Google Fonts). `landing_page.html` y `administrador.html` usan además Tailwind vía CDN. |

## Flujos paso a paso

### Flujo repositor — carga de stock

1. En `repositor.html`, el usuario toma/selecciona una foto del lote de
   productos (`<input type="file" capture="environment">`).
2. El frontend hace `POST` (multipart, campo `imagen`) a
   `http://localhost:8000/procesar-imagen`.
3. `main.py` lee el archivo y llama a
   `roboflow_workflow.ejecutar_workflow_stock()`, que corre el workflow
   `text-recognition` (workspace `cooppers-workspace`): detección YOLO de
   envases genéricos, `dynamic_crop` de cada detección, y `glm_ocr` para leer
   la marca sobre cada recorte. Los resultados vienen alineados por índice
   entre `predictions` y `recognized_text`.
   - Si Roboflow no responde (`RoboflowWorkflowConnectionError` tras 2
     reintentos con backoff exponencial): `main.py` devuelve
     `{"ok": false, "modo_offline": true, ...}` — el frontend cae a carga
     manual.
   - Si la API key es inválida (`RoboflowWorkflowAuthError`): responde
     `502` explícito, sin caer a offline (para no esconder un problema de
     configuración).
4. Con `ok: true`, la respuesta trae una lista de detecciones
   (`clase`, `confianza`, `marca`). El frontend renderiza la "Red de
   Seguridad": una tarjeta editable por detección con badge de confianza,
   donde el usuario confirma o corrige cantidad, producto y fecha de
   vencimiento opcional.
5. Al confirmar, el frontend hace un `POST` por cada ítem confirmado a
   `http://localhost/stockiate/tesis_enzo/guardar_stock.php` con body:
   ```json
   {
     "producto_id": 12,
     "cantidad": 24,
     "fecha_vencimiento": "2026-12-01",
     "usuario_id": 3
   }
   ```
6. `guardar_stock.php`, en una transacción PDO: inserta el lote en
   `lotes_stock` y hace `UPDATE productos SET stock_actual = stock_actual +
   :cantidad`. Devuelve `{"ok": true, "mensaje": "...", ...}`.
7. **(Roto/sin implementar)** Cuando el repositor corrige una detección de
   la IA, el frontend debería avisarle a `main.py` vía
   `POST /registrar-correccion`, que reenvía el dato a
   `registrar_correccion.php` para guardarlo en `correcciones_ia` — ese
   archivo PHP no existe todavía (ver "Gaps conocidos").

### Flujo cajero — venta

Mismo patrón de foto → `POST /procesar-imagen` → "Red de Seguridad" que el
flujo repositor, implementado de forma independiente dentro del `<script>`
inline de `cajero.html` (no comparte código con `repositor.html`). Al
confirmar, hace `POST` a
`http://localhost/stockiate/tesis_enzo/registrar_venta.php` con body:
```json
{
  "producto_id": 12,
  "cantidad": 2,
  "usuario_id": 1
}
```

`registrar_venta.php`, en una transacción PDO:
1. `SELECT id, precio_venta, stock_actual FROM productos WHERE id = :producto_id FOR UPDATE`
   — lockea la fila para evitar que dos ventas simultáneas dejen el stock
   negativo.
2. Si `stock_actual < cantidad`, hace `rollBack()` y responde `409`.
3. Inserta en `ventas` usando `producto.precio_venta` (el precio que venga
   del cliente, si viene, se ignora — nunca se confía en un precio del
   navegador).
4. `UPDATE productos SET stock_actual = stock_actual - :cantidad`.
5. Devuelve `{"ok": true, "subtotal": precio_unitario * cantidad, ...}`.

### Flujo administrador (dashboard mockeado, chatbot real)

El dashboard de `administrador.html` (KPIs, tabla de inventario) sigue sin
llamar a ningún endpoint PHP ni a FastAPI: usa un objeto `MockDB` que
persiste un array hardcodeado (`CATALOGO_BASE`) en `localStorage` (clave
`stockiate_inventory_v1`). El widget de chat que tenía esta página (pattern-
matching en JS que fabricaba un string de SQL para *mostrar*, sin ejecutar
nada) fue reemplazado por el chatbot IA real — ver "Flujo chatbot IA"
abajo.

### Flujo chatbot IA

1. `chatbot_widget.js` es un componente compartido (autocontenido, inyecta
   su propio CSS) incluido igual en las tres pantallas de rol:
   ```html
   <script src="chatbot_widget.js" data-rol="repositor" data-usuario-id="1"></script>
   ```
   `data-rol` vale `repositor` en `repositor.html`, `cajero` en
   `cajero.html`, y `dueño` en `administrador.html` — no hay login, el rol
   se infiere por la página igual que el resto del frontend (mismo patrón
   que el `usuarioId = 1` hardcodeado).
2. El widget mantiene un historial corto en memoria (no persiste entre
   recargas) y hace `POST` a `http://localhost:8000/chatbot` con:
   ```json
   {
     "pregunta": "¿qué productos tienen stock bajo?",
     "rol": "repositor",
     "usuario_id": 1,
     "historial": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]
   }
   ```
3. `main.py` (endpoint `/chatbot`) valida `rol` y llama a
   `chatbot_ia.responder_pregunta()`, que arma un mensaje a Groq
   (inferencia gratuita con límites de uso, API compatible con el formato
   de tool-calling de OpenAI) con **tool-use restringido**: `ROLE_TOOLS` en
   `chatbot_ia.py` define qué herramientas puede usar cada rol —
   `repositor` → `consultar_stock`, `consultar_vencimientos`,
   `consultar_productos`; `cajero` → `consultar_ventas`, `consultar_stock`,
   `consultar_productos`; `dueño` → las cuatro sin restricción. No hay
   texto-a-SQL libre: cada herramienta ejecuta una query fija y
   parametrizada del lado PHP.
4. Cuando Groq decide usar una herramienta, `chatbot_ia.py` le pega
   (vía `requests`) al endpoint PHP correspondiente
   (`consultar_stock.php`, `consultar_ventas.php`,
   `consultar_vencimientos.php` o `consultar_productos.php`), le devuelve
   el resultado JSON a Groq como mensaje `role: "tool"`, y repite el loop
   (máx. 5 iteraciones) hasta que el modelo devuelve una respuesta final en
   texto. El servicio Python sigue sin tocar MySQL directamente.
5. `main.py` llama a `chatbot_ia.registrar_log()`, que hace `POST` a
   `registrar_chatbot_log.php` para guardar el intercambio en
   `chatbot_conversaciones` (best-effort: si falla, no rompe la respuesta
   ya devuelta al usuario).
6. Manejo de errores en `/chatbot`: si falta `GROQ_API_KEY` →
   **503** (validación *lazy*, a diferencia de `ROBOFLOW_API_KEY` que
   aborta el arranque de todo el proceso — así `/procesar-imagen` sigue
   funcionando aunque el chatbot no esté configurado); error de conexión
   con Groq → **502**.

## Esquema de base de datos

Definido en `schema.sql`, base `stockiate` (utf8mb4).

- **`usuarios`** — `id`, `nombre`, `email` (único), `password_hash`,
  `rol ENUM('repositor', 'cajero', 'dueño')`, `creado_en`. Pensada para
  login diferenciado por rol; no hay código que la use todavía (ver "Gaps
  conocidos").
- **`productos`** — catálogo base. `sku` (único), `nombre`, `marca`,
  `variante` (para distinguir variantes con el mismo envase, ej. dos
  fragancias de 100ml), `categoria`, `precio_venta`, `stock_actual`,
  `stock_minimo` (umbral configurable por producto para alertas de stock
  bajo).
- **`lotes_stock`** — un producto puede tener varios lotes con distinta
  fecha de carga/vencimiento. `producto_id` (FK), `cantidad`,
  `fecha_carga` (default `CURRENT_TIMESTAMP`), `fecha_vencimiento`
  (nullable — si es `NULL` ese lote no participa del control de
  vencimientos), `usuario_id` (FK, quién cargó el lote). Índice
  `idx_lotes_vencimiento` sobre `fecha_vencimiento`.
- **`ventas`** — `producto_id` (FK), `cantidad`, `precio_unitario`
  (copiado de `productos.precio_venta` al momento de la venta),
  `usuario_id` (FK), `fecha`. Índices `idx_ventas_fecha` e
  `idx_ventas_producto`.
- **`correcciones_ia`** — feedback loop de la "Red de Seguridad":
  `producto_detectado_id` (lo que la IA pensó que era, FK nullable),
  `producto_corregido_id` (lo que el repositor confirmó, FK nullable),
  `cantidad_detectada`, `cantidad_corregida`, `confianza_ia` (score que
  devuelve Roboflow), `usuario_id`, `fecha`. Se llena a través de
  `registrar_correccion.php`, que todavía no existe.
- **`configuracion`** — tabla clave-valor simple para ajustes editables
  desde un futuro dashboard. Único valor precargado:
  `umbral_dias_vencimiento = 30`.
- **`chatbot_conversaciones`** — log de cada intercambio con el chatbot IA:
  `rol` (quién preguntó), `usuario_id` (FK nullable), `pregunta`,
  `respuesta`, `herramientas_usadas` (string separado por comas, ej.
  `"consultar_stock,consultar_ventas"`), `fecha`. Se llena a través de
  `registrar_chatbot_log.php`, llamado desde `chatbot_ia.py` en modo
  best-effort. Índice `idx_chatbot_fecha` sobre `fecha`.

## Convenciones de nombres y lenguaje

- Todo el vocabulario de dominio está en **español**: nombres de tablas y
  columnas, variables, comentarios y docstrings (estilo Argentina: "vos",
  "acá").
- Endpoints PHP: patrón `accion_dominio.php` (verbo + sustantivo), planos en
  la raíz del proyecto — no hay carpeta `api/` ni subcarpetas por módulo.
- Páginas HTML: nombradas por rol/módulo (`repositor.html`, `cajero.html`,
  `administrador.html`), cada una autocontenida con su propio `<script>`
  inline en vez de JS compartido en archivos aparte.
- Funciones JS: nombradas según la pantalla que afectan (`mostrarPantalla()`,
  `renderizarValidacion()`, `actualizarResumen()`, `crearItemCard()`,
  `irAValidacionManual()`).
- Patrón recurrente **"Red de Seguridad"**: la pantalla de validación humana
  que se muestra siempre entre la detección de IA y cualquier escritura en
  la base — implementada de forma independiente en `repositor.html` y
  `cajero.html` en vez de como componente compartido.
- No hay Composer ni npm: PHP usa solo la extensión nativa `PDO`, el
  frontend no tiene dependencias JS instaladas (Tailwind y Lucide se cargan
  por CDN donde se usan).

## Gaps conocidos

- **`registrar_correccion.php` no existe.** `main.py` (`POST
  /registrar-correccion`) intenta reenviarle el JSON de corrección a
  `http://localhost/stockiate/registrar_correccion.php` — la URL además le
  falta el segmento `/tesis_enzo`, así que aunque se creara el archivo con
  ese nombre en la raíz de `stockiate/`, seguiría sin coincidir con dónde
  vive realmente el proyecto (`stockiate/tesis_enzo/`). Resultado: cualquier
  corrección que haga un repositor en la pantalla de validación nunca llega
  a guardarse en `correcciones_ia`.
- **`index.html` + `script.js` es un prototipo obsoleto.** Implementa el
  mismo flujo que `repositor.html` pero es una versión anterior; su
  `URL_GUARDAR_STOCK` apunta a `http://localhost/stockiate/guardar_stock.php`
  (sin `/tesis_enzo`), por lo que da 404 si se lo usa tal cual. No es la
  versión que hay que editar.
- **El dashboard de `administrador.html` sigue siendo un mock.** KPIs y
  tabla de inventario no leen ni escriben en MySQL; todo ese estado vive en
  `localStorage` del navegador. El chatbot de esa misma página, en cambio,
  ya es real (ver "Flujo chatbot IA").
- **La restricción de herramientas por rol del chatbot no es seguridad
  real.** `/chatbot` confía en el campo `rol` que manda el frontend — como
  no hay autenticación en ningún lado del proyecto, cualquiera que le pegue
  directo al endpoint puede mandar `rol: "dueño"` y usar todas las
  herramientas. Es una limitación heredada del resto del proyecto, no algo
  que este feature resuelva.
- **`stockiate-panel-admin.html`** es un archivo vacío (0 bytes), sin
  contenido ni uso actual.
- **No hay autenticación ni sesión implementada.** La tabla `usuarios`
  soporta login por rol (`password_hash`, `rol`), pero no existe ningún
  script de login, ningún `session_start()`, ni hashing de contraseñas en
  el código. El frontend hardcodea `usuarioId = 1` (o similar) al enviar
  datos a los endpoints PHP, y el ítem de menú "Cerrar sesión" no tiene
  handler asociado.
- **Credenciales de MySQL hardcodeadas** en `conexion.php` (`root`, sin
  password) — es el default de XAMPP en local, pero no está pensado para
  otro entorno.
- **`detector.py` tiene una API key de Roboflow hardcodeada en el código**,
  duplicando (de forma menos segura) el uso correcto vía `.env` que hace
  `roboflow_workflow.py`. Es un script suelto que no forma parte del flujo
  de la app.
- **CORS abierto (`*`)** tanto en los endpoints PHP (headers manuales) como
  en FastAPI (`CORSMiddleware`) — aceptable en desarrollo local, no
  pensado para exponer el servicio más allá de `localhost`.
