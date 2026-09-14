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
| `registrar_usuario.php` | PHP/persistencia | Endpoint de registro. Valida y hashea la contraseña (`password_hash`, `PASSWORD_DEFAULT`) e inserta en `usuarios`. Devuelve el usuario creado (sin el hash). |
| `iniciar_sesion.php` | PHP/persistencia | Endpoint de login. Busca por `email`, valida con `password_verify`, y devuelve el usuario (sin el hash) si coincide. Mensaje de error genérico en ambos casos de fallo (email inexistente o contraseña incorrecta) para no filtrar qué emails están registrados. |
| `actualizar_usuario.php` | PHP/persistencia | Endpoint de "Mi cuenta" en `ajustes.html`. Actualiza nombre/apellido (email y rol no se editan) y devuelve el usuario actualizado, mismo shape que `iniciar_sesion.php`. |
| `cambiar_password.php` | PHP/persistencia | Endpoint de "Seguridad" en `ajustes.html`. Verifica la contraseña actual con `password_verify` antes de aceptar la nueva. |
| `consultar_configuracion.php` | PHP/persistencia | Endpoint de solo lectura sobre la tabla `configuracion` (clave-valor). Lo usa "Preferencias del negocio" en `ajustes.html` para precargar valores. |
| `actualizar_configuracion.php` | PHP/persistencia | Escribe una clave de `configuracion`, restringido a rol `dueño` (vía el `rol` que manda el body, mismo criterio de confianza que `/chatbot`). Whitelist de claves editables. |
| `schema.sql` | PHP/persistencia | DDL completo: crea la base `stockiate` y sus tablas (incluye `chatbot_conversaciones`). |
| `migracion_usuarios_apellido.sql` | PHP/persistencia | Migración puntual para bases creadas con un `schema.sql` anterior sin columna `apellido` en `usuarios`. No hace falta si la base se crea desde cero. |
| `main.py` | Python/IA | App FastAPI ("Motor de IA"). Expone `/`, `/procesar-imagen`, `/registrar-correccion` y `/chatbot`. No accede a MySQL. |
| `roboflow_workflow.py` | Python/IA | Cliente del Workflow de Roboflow (`inference-sdk`, `InferenceHTTPClient.run_workflow`). Encapsula la llamada, reintentos con backoff, y el parseo de la respuesta (predicciones YOLO + texto OCR de marca). |
| `chatbot_ia.py` | Python/IA | Cliente de la API de Groq (inferencia gratuita con límites de uso, vía el SDK `groq`, formato de tool-calling compatible con OpenAI) con tool-use. Define las 4 herramientas (`consultar_stock`, `consultar_ventas`, `consultar_vencimientos`, `consultar_productos`), restringe cuáles puede usar cada rol (`ROLE_TOOLS`), corre el loop de tool-use, y llama a `registrar_chatbot_log.php` al final. |
| `chatbot_widget.js` | Frontend | Widget de chat flotante compartido, autocontenido (inyecta su propio CSS). Se incluye igual en `repositor.html`, `cajero.html` y `administrador.html`, inyectado dinámicamente por `auth.js` (`insertarChatbotWidget()`) con el `rol`/`usuario_id` de la sesión real en vez de un `data-rol` fijo por página. |
| `voz_busqueda.js` | Frontend | Búsqueda y navegación por voz (Speech-to-Text) con la Web Speech API nativa. Componente compartido y autocontenido (inyecta su propio CSS, igual que `chatbot_widget.js`): expone `initBusquedaPorVoz(input, opciones)` —que engancha el botón de micrófono a un input de búsqueda existente— y `VozBusqueda.interpretarComando(texto)`, el parser de comandos, que es una función pura y se puede probar sin micrófono. Lo usa la barra de búsqueda de `inventario_tabla.js`. |
| `detector.py` | Python/IA (script suelto) | Ejemplo standalone de uso del SDK de Roboflow. No lo importa `main.py`. Tiene una API key hardcodeada — no usar como referencia de configuración. |
| `debug_roboflow_raw.py` | Python/IA (script suelto) | Vuelca la respuesta cruda del workflow para debugging manual. |
| `smoke_test_roboflow.py` | Python/IA (script suelto) | Test de humo manual (no pytest) para verificar conectividad con Roboflow. |
| `login.html` | Frontend | Formulario de login (email + contraseña). Pega a `iniciar_sesion.php`, guarda el usuario devuelto en `localStorage` (`auth.js`) y redirige al módulo de su rol. |
| `registro.html` | Frontend | Formulario de registro (nombre, apellido, email, contraseña, rol). Pega a `registrar_usuario.php`; si sale bien, inicia sesión automáticamente igual que `login.html`. |
| `ajustes.html` | Frontend | Página de configuración de cuenta y de negocio (perfil, contraseña, y — sólo rol `dueño` — `umbral_dias_vencimiento`). Accesible a cualquier rol logueado, `exigirSesion(["repositor","cajero","dueño"])`. Ver "Flujo ajustes". |
| `auth.js` | Frontend | Sesión de usuario en `localStorage` (sin backend de sesión). Expone `exigirSesion(rolesPermitidos)` — usado como guard sincrónico en `<head>` de cada página protegida —, `cerrarSesion()`, `rutaParaRol()` e `insertarChatbotWidget()`. Lo cargan `landing_page.html`, `login.html`, `registro.html`, `repositor.html`, `cajero.html` y `administrador.html`. |
| `landing_page.html` | Frontend | Selector de módulo, ahora detrás de `exigirSesion()`: exige sesión iniciada y sólo muestra los paneles que le corresponden al rol logueado (`dueño`/Administrador ve los tres; `repositor`/`cajero` sólo el suyo). |
| `repositor.html` | Frontend | Flujo de carga de stock (versión vigente). Protegido con `exigirSesion(["repositor", "dueño"])`. |
| `index.html` + `script.js` | Frontend | Prototipo viejo del flujo de carga de stock. Superado por `repositor.html` — ver "Gaps conocidos". No tiene guard de sesión. |
| `cajero.html` | Frontend | Flujo de venta. Protegido con `exigirSesion(["cajero", "dueño"])`. |
| `administrador.html` | Frontend | Dashboard de admin: KPIs y tabla de inventario siguen mockeados (`localStorage`, ver "Gaps conocidos"), pero el chatbot ya es real. Protegido con `exigirSesion(["dueño"])` — sólo el rol Administrador entra. |
| `stockiate-panel-admin.html` | Frontend | Archivo vacío, sin usar. |
| `styles.css` | Frontend | Hoja de estilos compartida por `repositor.html`, `cajero.html`, `index.html`, `landing_page.html`, `login.html`, `registro.html` (fuentes Fraunces/Inter de Google Fonts). `landing_page.html` y `administrador.html` usan además Tailwind vía CDN. |

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

### Flujo login / registro

1. `registro.html` pide nombre, apellido, email, contraseña (mín. 6
   caracteres, con confirmación) y rol (`repositor`, `cajero` o `dueño` —
   este último se etiqueta "Administrador" en la UI, ver más abajo). Hace
   `POST` a `registrar_usuario.php`, que valida los campos, chequea que el
   rol esté en la whitelist, hashea la contraseña con
   `password_hash(..., PASSWORD_DEFAULT)` e inserta en `usuarios`. Un email
   duplicado responde `409` (constraint `UNIQUE` de MySQL, código `23000`).
2. `login.html` pide email + contraseña y hace `POST` a
   `iniciar_sesion.php`, que busca por email y valida con
   `password_verify()`. Si falla (email inexistente o contraseña
   incorrecta) devuelve siempre el mismo mensaje genérico, para no revelar
   qué emails están registrados.
3. Ambos endpoints devuelven el usuario (sin `password_hash`) en éxito. El
   frontend (`auth.js`, función `guardarUsuarioSesion()`) lo guarda tal
   cual en `localStorage` bajo la clave `stockiate_usuario`, y redirige al
   módulo que le corresponde según `rutaParaRol(usuario.rol)`
   (`repositor` → `repositor.html`, `cajero` → `cajero.html`, `dueño` →
   `administrador.html`).
4. **No hay sesión de servidor** (cookies, tokens, `session_start()`): la
   sesión vive enteramente en `localStorage` del navegador, igual que el
   resto del estado de este proyecto (ver "Gaps conocidos" — esto es una
   limitación conocida, no un descuido de este feature).
5. Cada página protegida (`landing_page.html`, `repositor.html`,
   `cajero.html`, `administrador.html`) llama a `exigirSesion(rolesPermitidos)`
   de forma **sincrónica en el `<head>`**, antes de que se pinte el body:
   si no hay sesión redirige a `login.html`; si hay sesión pero el rol no
   está permitido para esa página, redirige a `rutaParaRol()` del usuario
   (por ejemplo, un `repositor` que entra a `administrador.html` rebota a
   `repositor.html`) — el `dueño`/Administrador tiene acceso a las tres.
   El resultado (el objeto `usuario`) queda en la variable global
   `usuarioSesion`, que cada página usa para reemplazar el
   `usuarioId = 1` hardcodeado que tenían antes `repositor.html` y
   `cajero.html`, y para inyectar el widget del chatbot con el `rol` y
   `usuario_id` reales (`insertarChatbotWidget()`).

### Flujo ajustes

`ajustes.html` es una página propia (no un modal/panel), accesible a
**cualquier rol logueado** (`exigirSesion(["repositor","cajero","dueño"])`)
porque es la configuración de la cuenta del usuario, no un módulo
restringido. Se llega desde el ítem "Ajustes" del menú desplegable en
`repositor.html`/`cajero.html`, o desde un botón nuevo junto al
`.user-chip` en `administrador.html` (que no tiene menú desplegable).
Extiende `styles.css` (no es standalone con tema propio como
`administrador.html`), reutilizando `.item-card`, `.auth-mensaje`,
`.fila-doble`, `.btn`/`.btn-primario`/`.btn-secundario` y
`.menu-desplegable` tal cual — sólo se agregaron dos clases chicas
(`.ajustes-titulo`, `.campo-solo-lectura`).

Cuatro secciones, cada una con su propio formulario y su propio mensaje de
resultado (mismo patrón `mostrarMensaje()`/`.auth-mensaje` que
`login.html`/`registro.html`):

1. **Mi cuenta** — editar nombre/apellido. Email y rol quedan de sólo
   lectura (el email es la clave única de login, el rol define accesos).
   `POST actualizar_usuario.php` con `{usuario_id, nombre, apellido}`,
   devuelve el usuario actualizado con el mismo shape que
   `iniciar_sesion.php`/`registrar_usuario.php`
   (`{id,nombre,apellido,email,rol}`). El frontend llama
   `guardarUsuarioSesion()` con la respuesta para resincronizar
   `localStorage` — el resto de la app (header, chatbot) sigue leyendo la
   sesión guardada y no se entera sola de un cambio de nombre.
2. **Seguridad** — cambiar contraseña. `POST cambiar_password.php` con
   `{usuario_id, password_actual, password_nueva}`; verifica la actual con
   `password_verify()` antes de aceptar la nueva (`401` si no coincide),
   mínimo 6 caracteres igual que en el registro. Validación de
   confirmación en el cliente antes de pegarle al backend.
3. **Preferencias del negocio** (sólo visible si `rol === "dueño"`, gating
   cliente + servidor) — editar `umbral_dias_vencimiento`. `POST
   consultar_configuracion.php` (`{}`, sin restricción de rol, dato no
   sensible) precarga el valor actual; `POST actualizar_configuracion.php`
   con `{rol, clave, valor}` lo guarda, con una whitelist de claves
   editables (hoy sólo esa una) y `403` si `rol !== "dueño"` — mismo
   patrón de "confiar en el rol que manda el body" que ya usa `/chatbot`
   (ver "Cosas para tener en cuenta"), no es seguridad real. Este campo no
   es decorativo: `consultar_vencimientos.php` ya lo lee como default
   cuando el chatbot no especifica `dias`.
4. **Sesión** — botón "Cerrar sesión" (`cerrarSesion()` de `auth.js`).

**Pendiente**: el comando de voz "ir a ajustes" de `voz_busqueda.js`
todavía no navega acá — ver "Gaps conocidos".

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
   su propio CSS) incluido igual en las tres pantallas de rol, pero ya no
   como `<script>` estático: `auth.js` lo inyecta dinámicamente después de
   validar la sesión (`insertarChatbotWidget(usuarioSesion)`), poniendo
   `data-rol` y `data-usuario-id` con los valores reales del usuario
   logueado — `chatbot_widget.js` los lee de `document.currentScript` al
   cargar, por eso el `<script>` se crea recién con esos atributos ya
   puestos, en vez de editarlos después sobre uno estático.
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

### Flujo búsqueda por voz (Speech-to-Text)

Corre **entero en el navegador**: no pasa por FastAPI ni por PHP, no escribe
nada en la base y no agrega dependencias (la Web Speech API es nativa).

1. `inventario_tabla.js` renderiza la barra de búsqueda con un botón de
   micrófono (`[data-invt-voz]`) y una línea de estado
   (`[data-invt-voz-estado]`), y se los pasa a `initBusquedaPorVoz()`.
   Si `voz_busqueda.js` no está cargado, saca el botón y la búsqueda escrita
   sigue igual (*progressive enhancement*).
2. El usuario toca el micrófono → `SpeechRecognition` arranca en `es-AR`
   (fallback automático a `es-ES` si el navegador no tiene ese idioma), con
   `interimResults` para mostrar en vivo lo que va escuchando.
3. Con el resultado final, `interpretarComando()` normaliza el texto
   (minúsculas, sin acentos ni puntuación) y devuelve una acción:

   | Dictado | Acción |
   |---|---|
   | "buscar Dior", "filtrá Carolina Herrera", "Dior Sauvage" | `{tipo:"buscar", termino}` |
   | "¿tenemos stock de Eros?", "¿cuánto queda de Le Male?" | `{tipo:"buscar", termino}` |
   | "ir a inventario", "ver stock" | `{tipo:"navegar", destino:"inventario"}` |
   | "ir a ventas", "andá a caja" | `{tipo:"navegar", destino:"ventas"}` |
   | "ir a ajustes", "configuración" | `{tipo:"navegar", destino:"ajustes"}` |
   | "limpiar", "borrar", "mostrar todo" | `{tipo:"limpiar"}` |

   Lo que no cae en ningún comando se toma como el nombre de un producto
   (búsqueda). El término conserva los acentos aunque el parsing se haga sin
   ellos, porque el filtro de la tabla hace un `includes` literal y "unica"
   no matchearía "Única".
4. **Búsqueda**: rellena el input y dispara sus eventos `input`/`change`
   nativos, así el filtrado lo termina haciendo el listener que la tabla ya
   tenía (el módulo no se acopla a la función de filtrado de cada página).
5. **Navegación**: primero se le ofrece el destino a la página vía
   `onNavegar(destino)`; si devuelve `true`, ya lo resolvió por su cuenta
   (`repositor.html` cambia de pantalla en vez de recargar,
   `administrador.html` recarga la tabla). Si no, `navegarPorDefecto()` usa
   una tabla de rutas que **respeta el rol de la sesión** (los mismos
   permisos que `exigirSesion()` en `auth.js`): si un cajero pide el panel
   de administrador, se le avisa en vez de mandarlo a una redirección que
   `exigirSesion()` le iba a rebotar igual.
6. Estados visuales del botón (CSS propio del módulo, prefijo `voz-`):
   `.voz-mic` inactivo, `.voz-mic--escuchando` con animación de pulso
   (desactivada si el usuario pidió `prefers-reduced-motion`), y
   `disabled` + tooltip explicativo cuando no se puede dictar.
7. Manejo de errores, siempre con mensaje en la línea de estado: navegador
   sin la API, contexto inseguro (ni https ni localhost), permiso denegado
   (`not-allowed`), sin micrófono (`audio-capture`), sin voz detectada
   (`no-speech`) y sin red (`network`). Además hay un watchdog de 12s que
   corta el reconocimiento si el navegador se queda escuchando de gusto.

## Esquema de base de datos

Definido en `schema.sql`, base `stockiate` (utf8mb4).

- **`usuarios`** — `id`, `nombre`, `apellido`, `email` (único),
  `password_hash`, `rol ENUM('repositor', 'cajero', 'dueño')`, `creado_en`.
  `rol = 'dueño'` es el valor interno (coincide con el ENUM y con
  `chatbot_ia.py`); en la UI de `registro.html` se etiqueta
  "Administrador". Se llena a través de `registrar_usuario.php`
  (`login.html`/`registro.html` — ver "Flujo login / registro"); antes no
  tenía código que la usara.
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
- **`configuracion`** — tabla clave-valor simple para ajustes de negocio.
  Único valor precargado: `umbral_dias_vencimiento = 30`. Se lee/escribe
  desde `consultar_configuracion.php`/`actualizar_configuracion.php`
  (sección "Preferencias del negocio" de `ajustes.html`, sólo rol `dueño`)
  y `consultar_vencimientos.php` ya la usa como default cuando el chatbot
  no especifica `dias` — no es un valor decorativo.
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
- **La restricción de herramientas por rol del chatbot sigue sin ser
  seguridad real.** Hay login y contraseñas hasheadas ahora, pero
  `/chatbot` (y en general los endpoints PHP) siguen sin validar ninguna
  sesión de servidor: `rol` y `usuario_id` llegan como campos del body que
  manda el frontend (leídos de la sesión en `localStorage`), y cualquiera
  que le pegue directo al endpoint puede mandar `rol: "dueño"` y
  `usuario_id` de otra persona sin que nada lo verifique del lado
  servidor. La sesión en `localStorage` protege la UI (qué páginas se
  pueden navegar), no los endpoints. Los endpoints nuevos de `ajustes.html`
  heredan el mismo gap: `actualizar_usuario.php` y `cambiar_password.php`
  confían en el `usuario_id` que manda el body (cualquiera puede editar el
  perfil o la contraseña de otro usuario si conoce su id), y
  `actualizar_configuracion.php` restringe por `rol` de la misma forma que
  `/chatbot` — no es una regresión nueva, es el mismo diseño ya aceptado en
  el resto del proyecto.
- **`stockiate-panel-admin.html`** es un archivo vacío (0 bytes), sin
  contenido ni uso actual.
- **La sesión vive sólo en el navegador (`localStorage`), no en el
  servidor.** `login.html`/`registro.html` + `iniciar_sesion.php`/
  `registrar_usuario.php` cubren registro, login con contraseña hasheada
  (`password_hash`/`password_verify`) y separación de acceso por rol en el
  frontend (`auth.js`, `exigirSesion()`) — ver "Flujo login / registro".
  Lo que sigue faltando es lo típico de una sesión real de servidor:
  cookies, tokens (JWT o similar), `session_start()`, expiración, o
  invalidación server-side al cerrar sesión. Cualquiera con acceso a la
  consola del navegador puede escribir un objeto arbitrario en
  `localStorage['stockiate_usuario']` y pasar los guards de rol del
  frontend — de nuevo, protege la navegación de la UI, no es un
  reemplazo de autenticación real del lado servidor.
- **La búsqueda por voz depende del navegador y de internet.** La Web
  Speech API existe en Chrome, Edge y Safari, pero **no en Firefox**, y
  necesita contexto seguro (https o `localhost`: abrir por Apache, nunca con
  `file://`). En Chrome además el audio se transcribe en servidores de
  Google, así que —a diferencia del resto del flujo, que cae a carga
  manual— el dictado **no funciona offline**. En todos esos casos el botón
  del micrófono queda deshabilitado con un tooltip que explica por qué, y la
  búsqueda escrita sigue funcionando igual.
- **El comando de voz "ir a ajustes" todavía no apunta a `ajustes.html`.**
  El módulo de ajustes ya existe (ver "Flujo ajustes" más arriba), pero
  `voz_busqueda.js` sigue sin actualizarse: el destino `"ajustes"` en
  `navegarPorDefecto()` (`voz_busqueda.js:367-376`) sigue con el caso
  especial que abre el menú desplegable como fallback, en vez de navegar
  directo a la página real. Falta: agregar
  `ajustes: { url: "ajustes.html", roles: null, etiqueta: "Ajustes" }` al
  mapa `RUTAS` (`voz_busqueda.js:343-348`) y borrar ese caso especial.
- **`cajero.html` no tiene micrófono** porque no tiene barra de búsqueda:
  su flujo es foto → Red de Seguridad. Si se le agrega un buscador, el
  dictado se engancha con una línea (`initBusquedaPorVoz(input)`).
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
