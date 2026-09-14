# Arquitectura de stockIAte

Complemento de [CLAUDE.md](../CLAUDE.md) con el detalle completo: qué hace
cada archivo, los flujos paso a paso con sus payloads reales, el esquema de
base de datos y las convenciones del proyecto.

## Capas y responsabilidades

| Archivo | Capa | Rol |
|---|---|---|
| `conexion.php` | PHP/persistencia | Conexión PDO compartida a MySQL (`stockiate`), credenciales hardcodeadas (`root`/sin password, default de XAMPP). Todos los endpoints PHP hacen `require_once` de este archivo. |
| `guardar_stock.php` | PHP/persistencia | Endpoint de carga de stock (rol repositor). Inserta un lote en `lotes_stock` e incrementa `productos.stock_actual`, en una transacción. |
| `registrar_venta.php` | PHP/persistencia | Endpoint de venta (rol cajero). Recibe el ticket completo (`items: [...]`), lockea las filas de todos los productos (`SELECT ... FOR UPDATE`), valida el stock de todos antes de escribir, inserta en `ventas` con el precio del servidor y decrementa `productos.stock_actual`, todo en una transacción atómica (o entra el ticket entero, o no entra nada). |
| `registrar_correccion.php` | PHP/persistencia | **No existe.** Referenciado por `main.py` pero nunca creado — ver "Gaps conocidos". |
| `consultar_stock.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Devuelve stock de productos, con filtros opcionales por término/categoría/stock bajo. |
| `consultar_ventas.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA y fuente de los gráficos de ventas del panel). Devuelve ventas agregadas (por producto/marca/día) en un rango de fechas. **El orden depende de la agrupación**: por producto o marca, los 20 que más facturaron; por día, la serie cronológica completa (fecha ascendente, límite 366). Antes ordenaba siempre por facturación con límite 20, que para una serie temporal devolvía "los 20 días que más facturaron", desordenados y con huecos silenciosos. |
| `consultar_vencimientos.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Devuelve lotes de `lotes_stock` próximos a vencer o ya vencidos. |
| `consultar_productos.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA). Busca en el catálogo por nombre/marca/sku/categoría. |
| `consultar_proveedores.php` | PHP/persistencia | Endpoint de solo lectura. Devuelve la lista de proveedores para el `<datalist>` del alta de producto (Red de Seguridad). |
| `archivar_producto.php` | PHP/persistencia | Baja lógica de un producto (`activo = 0`) y su restauración (`{"restaurar": true}`), **sólo rol `dueño`**. Reemplaza al viejo `eliminar_producto.php`, que hacía un DELETE real y por eso fallaba con cualquier producto que tuviera ventas, lotes o correcciones (FK sin ON DELETE CASCADE). Permite archivar con `stock_actual > 0` y devuelve `stock_al_archivar` para que el aviso del frontend diga la verdad. |
| `actualizar_costo.php` | PHP/persistencia | Guarda `precio_costo`, `precio_venta` y proveedor de un producto existente, **sólo rol `dueño`**. Actualización PARCIAL: sólo toca los campos presentes en el body (usa `array_key_exists`, no `isset`, para distinguir "no lo mandé" de "mandé null" = borrar el costo). Es la escritura del bloque "Costos y márgenes" del panel. |
| `proveedores.php` | PHP/persistencia | Helper compartido: `resolverProveedorId()` (reusa el proveedor del negocio si existe, lo crea si no). Lo incluyen `crear_producto.php` y `actualizar_costo.php`; vive en un archivo aparte porque la regla "el proveedor es por negocio" no puede desincronizarse entre dos copias. |
| `env.php` | PHP/persistencia | Lector mínimo del `.env` para el lado PHP (Python usa python-dotenv). Existe porque el token de WhatsApp es un secreto real y el repo tiene remoto público: en vez de inventar un segundo archivo de secretos, PHP lee el mismo `.env` que ya está gitignoreado. Cachea en una `static`; si el archivo no existe devuelve vacío en vez de fallar. |
| `configuracion.php` | PHP/persistencia | Dueño de la tabla `configuracion` (PK `(negocio_id, clave)`): `CONFIG_INICIAL_NEGOCIO` (la semilla de un negocio nuevo), `leer_configs()`, `guardar_config()` (INSERT ... ON DUPLICATE KEY UPDATE) y `sembrar_config_inicial()`. Existe porque la semilla estaba copiada en `crear_negocio.php` y `sesion_demo.php`, y al pasar de una clave a cuatro las dos copias iban a divergir solas. |
| `alertas.php` | PHP/persistencia | Las queries que definen "acá hay un problema": `es_critico()` (LA definición de stock crítico del backend), `contar_criticos()`, `productos_criticos()`, `lotes_por_vencer()`, `dias_vencimiento_config()`, `ventas_del_dia()`. Lo comparten `consultar_inventario.php`, `consultar_vencimientos.php` y `tareas_notificaciones.php`, para que el KPI del panel y el WhatsApp no puedan contradecirse. |
| `notificaciones.php` | PHP/persistencia | La cola de avisos: `config_whatsapp()`, `whatsapp_habilitado()`, `encolar_notificacion()` (con deduplicación por `clave_dedup` + ventana de horas), `enviar_notificacion()` (manda una fila y anota el resultado) y `PLANTILLA_POR_TIPO` (el mapa tipo de aviso -> plantilla aprobada en Meta). |
| `mailer.php` | PHP/integración | **El único PHP que manda mails.** Envuelve a PHPMailer (`vendor/phpmailer/`, tres archivos sin Composer): `mail_configurado()`, `mail_remitente()` y `enviar_mail()`. No lanza — devuelve `['ok', 'error']` con el diálogo SMTP crudo, mismo criterio que `whatsapp.php`. Existe porque la recuperación de contraseña necesita un canal que pruebe la titularidad de la cuenta, y el link copiado a mano de las invitaciones no sirve para el caso que hay que cubrir (el dueño, que no tiene a nadie arriba). |
| `reset_password.php` | PHP/persistencia | Lo compartido entre las tres piezas del reseteo: `RESET_VIGENCIA_MINUTOS` (60), `RESET_MAX_POR_HORA` (3), `generar_token_reset()` / `hash_token_reset()`, `url_base_app()`, `buscar_reset_vigente()`, `motivo_reset_invalido()`, `enmascarar_email()` y `armar_mail_reset()`. Aparte de `sesion.php` porque eso lo requiere todo el proyecto y esto sólo tres archivos. |
| `solicitar_reset.php` | PHP/persistencia | **Público.** Paso 1: recibe un email y manda el link. **Responde siempre lo mismo** exista o no la cuenta, esté o no agotado el límite por hora, y falle o no el envío — si distinguiera, sería un enumerador de cuentas. Anula los tokens vivos anteriores del usuario, inserta el nuevo y manda el mail **después del commit y fuera de la transacción** (mismo criterio que el encolado de notificaciones en `registrar_venta.php`), guardando el error del SMTP en `password_resets.error`. |
| `info_reset.php` | PHP/persistencia | **Público.** Paso 2a: dice si el token del link todavía sirve, para que `recuperar.html` no muestre un formulario que va a fallar (el mismo motivo por el que existe `info_invitacion.php`). Expone sólo el nombre y el email ENMASCARADO. |
| `restablecer_password.php` | PHP/persistencia | **Público.** Paso 2b: canjea el token por una contraseña nueva. `SELECT ... FOR UPDATE` sobre el token (dos pestañas no pueden canjearlo dos veces), escribe `password_hash` y `password_cambiado_en` en el mismo UPDATE, marca `usada_at` y anula los otros tokens vivos. **No hace auto-login**, a diferencia de `aceptar_invitacion.php`: el objetivo es que después del reseteo no quede ninguna sesión viva. |
| `probar_mail.php` | PHP/diagnóstico | Script de línea de comandos (guarda `php_sapi_name() !== 'cli'`, como `tareas_notificaciones.php`) que muestra la config SMTP y manda un mail de prueba. Existe porque `solicitar_reset.php` **no puede** decir qué pasó sin volverse un enumerador de cuentas: ese silencio es correcto de cara a internet y malísimo para diagnosticar, así que el diagnóstico vive acá. Equivalente de `probar_whatsapp.php`. |
| `notificaciones.php` (canal mail) | PHP/persistencia | Además de la cola, traduce un aviso a un mail: `CAMPOS_POR_TIPO` (qué significa cada posición del array posicional), `ASUNTO_POR_TIPO`, `armar_mail_notificacion()` y los dos armadores de cuerpo (HTML con estilos inline + texto plano). Vive junto a `PLANTILLA_POR_TIPO` a propósito: las dos traducen el MISMO array y tienen que moverse juntas. Un tipo desconocido no se calla — cae a listar los parámetros crudos. |
| `whatsapp.php` | PHP/integración | **El único PHP que sale a internet.** `enviar_plantilla_whatsapp()` (POST a la Graph API de Meta vía cURL, timeout 10 s) y `normalizar_telefono()` (E.164 sin `+`; para Argentina agrega el 9 y saca el 15, las dos causas de "Meta contesta 200 y el mensaje nunca llega"). Nunca lanza: devuelve `['ok', 'wamid', 'error', 'http']` para que quien llama pueda guardar el error y seguir. |
| `consultar_notificaciones.php` | PHP/persistencia | Endpoint de solo lectura, **sólo rol `dueño`**. Las últimas 10 notificaciones con su estado y el error crudo de Meta: la herramienta de diagnóstico de "no me llega nada". La config del negocio, que antes también salía por acá, se mudó a `consultar_preferencias.php`. |
| `consultar_preferencias.php` | PHP/persistencia | Endpoint de solo lectura, **sólo rol `dueño`**. TODAS las claves de `configuracion` del negocio (teléfono, avisos activados, hora del resumen, días de vencimiento, horas entre avisos repetidos, stock mínimo por defecto) más el teléfono normalizado y `servidor_configurado` (el `.env`, que se arregla en otro lado que la config del negocio). Uno solo y no uno por tema: todas viven en la misma tabla y las pinta el mismo formulario. |
| `guardar_preferencias.php` | PHP/persistencia | Escribe cualquiera de esas claves en `configuracion`, **sólo rol `dueño`**. Reemplaza al viejo `guardar_config_whatsapp.php` (que hacía lo mismo con sólo las tres de WhatsApp). Actualización PARCIAL con `array_key_exists`, igual que `actualizar_costo.php`. Valida el teléfono contra `normalizar_telefono()`, la hora contra `HH:MM` (el formato importa: el script programado la compara como texto) y los tres umbrales contra su rango, con 400 en vez de recortar en silencio: el valor se guarda, y recortarlo dejaría al dueño mirando un número distinto del que escribió. |
| `probar_notificacion.php` | PHP/persistencia | El botón "Mandar mensaje de prueba" del panel, **sólo rol `dueño`**. Encola una notificación de tipo `prueba` y la manda en el acto con la misma función que usa el script programado — si la prueba llega, las alertas reales también. Único lugar que le pega a Meta desde una request del navegador, porque acá sí hay alguien esperando el resultado. |
| `tareas_notificaciones.php` | PHP/script CLI | El "cron". Detecta lo que no tiene evento de usuario (vencimientos, resumen diario), lo encola, y vacía la cola mandando por Meta. Corre desde el Programador de tareas de Windows cada 5 min. Es el ÚNICO código que recorre todos los negocios sin filtrar por sesión, y por eso tiene una guarda `php_sapi_name() !== 'cli'`. Si faltan las credenciales encola igual pero no intenta enviar, para no gastar los 3 intentos de cada fila. |
| `probar_whatsapp.php` | PHP/script CLI | Test de humo manual de la integración con Meta (el equivalente de `smoke_test_roboflow.py`): manda `hello_world` a un número sin tocar la base ni la app. Si esto no llega, el problema es la config de Meta y no hay nada que buscar en el código. |
| `consultar_rentabilidad.php` | PHP/persistencia | Endpoint de solo lectura (herramienta del chatbot IA), **sólo rol `dueño`**. Devuelve por producto costo/venta, margen unitario y en %, unidades vendidas y ganancia estimada en un rango, stock y capital inmovilizado; más un resumen por proveedor y totales del negocio. La ganancia usa `COALESCE(v.costo_unitario, p.precio_costo)`: el costo congelado en la venta, y sólo cae al costo actual para ventas viejas. |
| `registrar_chatbot_log.php` | PHP/persistencia | Inserta cada intercambio pregunta/respuesta del chatbot en `chatbot_conversaciones`. Lo llama `main.py` en modo best-effort. |
| `registrar_usuario.php` | PHP/persistencia | Endpoint de registro. Valida y hashea la contraseña (`password_hash`, `PASSWORD_DEFAULT`) e inserta en `usuarios`. Devuelve el usuario creado (sin el hash). |
| `iniciar_sesion.php` | PHP/persistencia | Endpoint de login. Busca por `email`, valida con `password_verify`, y devuelve el usuario (sin el hash) si coincide. Mensaje de error genérico en ambos casos de fallo (email inexistente o contraseña incorrecta) para no filtrar qué emails están registrados. |
| `schema.sql` | PHP/persistencia | DDL completo: crea la base `stockiate` y sus tablas (incluye `chatbot_conversaciones`). |
| `migracion_usuarios_apellido.sql` | PHP/persistencia | Migración puntual para bases creadas con un `schema.sql` anterior sin columna `apellido` en `usuarios`. No hace falta si la base se crea desde cero. |
| `migracion_costo_ventas.sql` | PHP/persistencia | Migración puntual: agrega `ventas.costo_unitario` (snapshot del costo al momento de vender). Aditiva y nullable, segura con el código viejo corriendo. No hace falta si la base se crea desde cero. |
| `migracion_productos_activo.sql` | PHP/persistencia | Migración puntual: agrega `productos.activo` / `archivado_en` (baja lógica) más el índice `(negocio_id, activo)`. Aditiva y con DEFAULT, segura con el código viejo corriendo. No hace falta si la base se crea desde cero. |
| `migracion_notificaciones_whatsapp.sql` | PHP/persistencia | Migración puntual: crea la tabla `notificaciones` (la cola de avisos) y siembra las tres claves de WhatsApp en `configuracion` para los negocios existentes, todas **desactivadas**. Aditiva y segura con el código viejo corriendo. No hace falta si la base se crea desde cero. |
| `migracion_preferencias_negocio.sql` | PHP/persistencia | Migración puntual: siembra `ventana_notificaciones_horas` y `stock_minimo_default` en `configuracion` para los negocios existentes, con los mismos valores que ya eran el comportamiento hardcodeado. No crea ni altera tablas, y es idempotente (`ON DUPLICATE KEY UPDATE valor = valor` es un no-op deliberado: correrla de nuevo no pisa lo que el dueño configuró). No hace falta si la base se crea desde cero. |
| `main.py` | Python/IA | App FastAPI ("Motor de IA"). Expone `/`, `/procesar-imagen`, `/registrar-correccion` y `/chatbot`. No accede a MySQL. |
| `roboflow_workflow.py` | Python/IA | Cliente del Workflow de Roboflow (`inference-sdk`, `InferenceHTTPClient.run_workflow`). Encapsula la llamada, reintentos con backoff, y el parseo de la respuesta (predicciones YOLO + texto OCR de marca). |
| `chatbot_ia.py` | Python/IA | Cliente de la API de Groq (inferencia gratuita con límites de uso, vía el SDK `groq`, formato de tool-calling compatible con OpenAI) con tool-use. Define las 5 herramientas (`consultar_stock`, `consultar_ventas`, `consultar_vencimientos`, `consultar_productos`, `consultar_rentabilidad`) y recorta cuáles se pueden usar por **rol** (`ROLE_TOOLS`) y por **sección** (`CONTEXT_TOOLS`), quedándose con la intersección (`_nombres_permitidos()`). Ese mismo set filtra la ejecución, no sólo la oferta: si el modelo inventa un `tool_call` fuera de la lista, `_ejecutar_tool()` lo rechaza sin pegarle a PHP. Corre el loop de tool-use y llama a `registrar_chatbot_log.php` al final. |
| `costos_tabla.js` | Frontend | Bloque "Costos y márgenes" de `administrador.html`, **plegable** (`{ plegable: true }`, arranca cerrado; plegado deja a la vista el resumen del pie): una fila por producto con costo, precio de venta y proveedor editables, margen recalculado en vivo mientras se tipea, filtro "solo los que faltan" y guardado por campo al salir del input. Lee de `consultar_rentabilidad.php` (el mismo endpoint que usa el chatbot, así que tabla y asistente muestran los mismos números) y escribe con `actualizar_costo.php`. |
| `graficos.js` | Frontend | El bloque "Gráficos" de `administrador.html` (el primero de la grilla, y lo que queda a la vista con las tablas plegadas): estado del stock y capital por proveedor en tortas, ventas por día en línea y top productos en barras. Los dos primeros **no hacen ningún request**: se alimentan de los `onDatos` de `inventario_tabla.js` y `costos_tabla.js`, para no duplicar dos consultas pesadas y para que el gráfico no pueda contradecir a la tabla de al lado (la torta de stock clasifica con `estadoDeProducto()`, la misma función que pinta los badges). Sólo los de ventas pegan a `consultar_ventas.php`, agrupado por `dia` y por `producto`. Como el `<canvas>` no hereda del CSS, lee los colores de las variables del tema y un `MutationObserver` sobre `data-tema` repinta al alternar claro/oscuro, destruyendo antes cada instancia previa. Plegable, pero **arranca abierto**. Prefijo `grf-`. |
| `vendor/chart.umd.min.js` | Frontend | Chart.js 4.4.7, build UMD, servido desde el repo y no desde un CDN a propósito: los gráficos tienen que andar sin internet (una demo o una defensa no puede depender del wifi de la sala). Vive en `vendor/` junto al resto de lo que no es código del proyecto. |
| `vendor/jspdf.umd.min.js` | Frontend | jsPDF 2.5.2, build UMD, también servido desde el repo. Lo usa `exportarPDF()` de `graficos.js`; expone `window.jspdf.jsPDF`. |
| `plegable.js` | Frontend | El mecanismo de los paneles plegables del dashboard, compartido por `inventario_tabla.js` y `costos_tabla.js`: `plegableLeer()` (estado inicial, con el default de cada panel), `plegableGuardar()` y `plegableConectar()` (cablea el botón ya renderizado y toma el estado inicial de la clase que trae el markup, así el HTML no puede desincronizarse del comportamiento). Arrancó adentro de `inventario_tabla.js`; salió al hacer plegable el segundo panel, para no duplicar el try/catch de `localStorage` (obligatorio: en incógnito con el almacenamiento bloqueado el acceso lanza) ni las etiquetas del botón. Cada panel sigue siendo dueño de su clave de guardado y de su CSS, porque qué hijos se esconden cambia de panel a panel. |
| `menu_negocio.js` | Frontend | El chip de la topbar de `administrador.html` y el panel superpuesto que abre, sólo `dueño`. Es dueño de los dos para que el nombre del negocio y sus iniciales se calculen en un solo lugar (`pintarSesion()`, llamada dos veces: sincrónica con el caché de `localStorage` para que el chip nazca con el nombre real, y de nuevo cuando contesta `sesion_actual.php`). Adentro monta `initPreferencias()` de forma **perezosa** — la primera apertura lo monta, las siguientes sólo `recargar()` —, así que sus dos fetch dejaron de pagarse en cada carga. Cierra con ✕, con clic en el fondo y con Escape (el primero del proyecto), bloquea el scroll de atrás y devuelve el foco al chip. z-index 1000000: lo único que lo deja por encima del botón del chatbot (999999). Prefijo `mng-`. |
| `preferencias.js` | Frontend | El contenido del panel "Preferencias del negocio" (lo monta `menu_negocio.js`; hasta que existió el panel era un card del `.main-grid`), sólo `dueño`. Reemplaza a `notificaciones_config.js`, que configuraba sólo WhatsApp: al exponer los umbrales que estaban en el código, la alternativa era un segundo card al lado del primero para lo que el dueño vive como una sola cosa. Dos grupos — **Avisos por WhatsApp** (teléfono con el número ya normalizado debajo del input para ver de una si faltó un dígito, switch de activado, hora del resumen, botón de prueba) y **Umbrales** (días de vencimiento, horas entre avisos repetidos, stock mínimo por defecto) — más la tabla de las últimas 10 notificaciones con el error crudo de Meta. Guarda campo por campo al salir del input, igual que `costos_tabla.js`. Pide los ajustes y el historial por separado, así uno puede fallar sin llevarse al otro. Prefijo `prf-`. |
| `recorte_imagen.js` | Frontend | Recortador táctil que se abre entre elegir la foto y mandarla al detector: recuadro movible y estirable con pointer events (anda con dedo y con mouse), máscara oscura afuera y guías de tercios. Devuelve el recorte ya escalado a 1280px en JPEG q=0.97 — un solo re-encode en todo el pipeline — o el archivo original si se elige "Usar toda la foto". Autocontenido, prefijo `rci-`. |
| `chatbot_widget.js` | Frontend | Widget de chat flotante compartido, autocontenido (inyecta su propio CSS). Se incluye igual en `repositor.html`, `cajero.html` y `administrador.html`, inyectado dinámicamente por `auth.js` (`insertarChatbotWidget()`) con el `rol` de la sesión real y el `data-contexto` de la página (`repositor` / `cajero` / `admin`), en vez de un `data-rol` fijo por página. El contexto elige el copy y los chips, y viaja al backend para recortar las herramientas. |
| `debug_roboflow_raw.py` | Python/IA (script suelto) | Vuelca la respuesta cruda del workflow para debugging manual. |
| `smoke_test_roboflow.py` | Python/IA (script suelto) | Test de humo manual (no pytest) para verificar conectividad con Roboflow. |
| `login.html` | Frontend | Formulario de login (email + contraseña). Pega a `iniciar_sesion.php`, guarda el usuario devuelto en `localStorage` (`auth.js`) y redirige al módulo de su rol. |
| `registro.html` | Frontend | Formulario de registro (nombre, apellido, email, contraseña, rol). Pega a `registrar_usuario.php`; si sale bien, inicia sesión automáticamente igual que `login.html`. |
| `auth.js` | Frontend | Sesión de usuario en `localStorage` (sin backend de sesión). Expone `exigirSesion(rolesPermitidos)` — usado como guard sincrónico en `<head>` de cada página protegida —, `cerrarSesion()`, `rutaParaRol()` e `insertarChatbotWidget()`. Lo cargan `landing_page.html`, `login.html`, `registro.html`, `repositor.html`, `cajero.html` y `administrador.html`. |
| `landing_page.html` | Frontend | Selector de módulo, ahora detrás de `exigirSesion()`: exige sesión iniciada y sólo muestra los paneles que le corresponden al rol logueado (`dueño`/Administrador ve los tres; `repositor`/`cajero` sólo el suyo). |
| `repositor.html` | Frontend | Flujo de carga de stock (versión vigente). Protegido con `exigirSesion(["repositor", "dueño"])`. |
| `cajero.html` | Frontend | Flujo de venta. Protegido con `exigirSesion(["cajero", "dueño"])`. |
| `administrador.html` | Frontend | Dashboard de admin: KPIs y tabla de inventario siguen mockeados (`localStorage`, ver "Gaps conocidos"), pero el chatbot ya es real. Protegido con `exigirSesion(["dueño"])` — sólo el rol Administrador entra. |
| `styles.css` | Frontend | Hoja de estilos compartida por `repositor.html`, `cajero.html`, `landing_page.html`, `login.html`, `registro.html` (fuentes Fraunces/Inter de Google Fonts). `landing_page.html` y `administrador.html` usan además Tailwind vía CDN. |

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
   (`clase`, `confianza`, `marca`, `volumen`, `identificado`). El frontend
   renderiza la "Red de Seguridad": una tarjeta editable por detección con
   badge de confianza, donde el usuario confirma o corrige cantidad, producto
   y fecha de vencimiento opcional.

   **Las clases que devuelve el modelo son ruido.** No es un detector de
   perfumería: es genérico (COCO), y etiqueta los envases con lo que se le
   parecen. Contra las fotos de `images/`: `local.jpeg` (un gel) sale como
   `'remote'`, `hawas.png` (un perfume) como `'cell phone'`. Hubo un intento
   de denylist por clase (`person`, `marker`, `cell phone`…) y descartaba
   productos reales con la marca leída perfecta, con el síntoma "la IA no
   detecta nada". Las clases crudas se loguean y no se usan para decidir nada.

   Lo que sí protege la pantalla son dos reglas:
   - **La clase cruda nunca sale como nombre de producto.** `clase` es
     siempre el texto del OCR. Si el OCR no leyó la etiqueta, viaja vacío e
     `identificado: false`: la tarjeta se pinta en rojo con el badge "Sin
     identificar", no prellena nada, y **el botón Confirmar la rechaza**
     hasta que una persona elija un producto del catálogo o borre la tarjeta.
     Antes se mandaba `clase_generica` como fallback y el frontend lo
     prellenaba, así que una mano en cuadro podía dar de alta un producto
     llamado "person".
   - `opcionesCatalogo()` antepone un `<option value="">— Elegí el producto —`
     cuando la detección no matcheó con el catálogo: sin él, el navegador
     auto-seleccionaba el primer producto de la lista y se cargaba stock del
     producto equivocado en silencio.
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
inline de `cajero.html` (no comparte código con `repositor.html`). Una
detección agrupada por clase = un renglón del ticket, cada uno con su
producto y su cantidad editables.

Al confirmar hace **un solo** `POST` a
`http://localhost/stockiate/tesis_enzo/registrar_venta.php` con el ticket
entero:
```json
{
  "items": [
    { "producto_id": 12, "cantidad": 2 },
    { "producto_id": 30, "cantidad": 1 }
  ],
  "usuario_id": 1
}
```
(También acepta el formato viejo de un solo producto —
`{"producto_id": 12, "cantidad": 2, "usuario_id": 1}` — tratado como ticket
de un ítem.)

`registrar_venta.php`, en una sola transacción PDO:
1. Normaliza los ítems y **suma las cantidades del mismo `producto_id`** (dos
   renglones apuntando al mismo artículo se validan juntos, si no cada uno
   validaría el stock por su cuenta y entre los dos podrían llevarse más
   unidades de las que hay). Ordena por id para que dos cajas cobrando a la
   vez lockeen en el mismo orden y no se traben (deadlock).
2. `SELECT id, nombre, precio_venta, stock_actual FROM productos WHERE id = :producto_id FOR UPDATE`
   por cada ítem — lockea las filas para evitar que dos ventas simultáneas
   dejen el stock negativo.
3. **Valida todo el ticket antes de escribir nada**: junta los errores de
   todos los renglones (producto inexistente o stock insuficiente) y, si hay
   al menos uno, hace `rollBack()` y responde `409` con
   `errores: [{producto_id, nombre, pedido, disponible, mensaje}]`. El
   frontend marca esos renglones en rojo (`.item-card-error`) y no avanza:
   no se registró nada, así que el cajero corrige y vuelve a confirmar el
   mismo ticket sin riesgo de cobrar dos veces.
4. Recién si todo el ticket es válido, inserta en `ventas` usando
   `producto.precio_venta` (el precio que venga del cliente, si viene, se
   ignora — nunca se confía en un precio del navegador) y hace
   `UPDATE productos SET stock_actual = stock_actual - :cantidad` por ítem.
5. Devuelve `{"ok": true, "items": [...], "cantidad_productos": 2,
   "unidades": 3, "total": 12000}`.

> Antes el frontend mandaba **un `POST` por producto**: si a un renglón le
> faltaba stock, los otros ya habían quedado cobrados y descontados mientras
> la pantalla seguía en validación mostrando solo un error, y volver a
> confirmar los cobraba de nuevo. El equivalente del repositor
> (`guardar_stock.php`) sigue siendo un request por producto — ahí una carga
> parcial no descuadra la caja, pero es el mismo patrón a revisar.

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

### Flujo recuperación de contraseña

Autoservicio por email. Es el único camino de alta o recuperación que no
depende de que haya otra persona del otro lado — de ahí que exista: un `dueño`
es el único usuario al que nadie más puede resetearle la contraseña.

1. `login.html` -> "¿Olvidaste tu contraseña?" -> `olvide_password.html`.
2. La persona escribe su email. `POST solicitar_reset.php`, que:
   busca al usuario; chequea el límite de **3 pedidos por cuenta por hora**;
   **anula los tokens vivos anteriores** (`expira_at = NOW()`, no se borran,
   para que el conteo del límite los siga viendo); genera un token de 32 bytes
   y guarda **su SHA-256**, nunca el token; y **después del commit**, fuera de
   la transacción, manda el mail con `mailer.php`.
3. **La respuesta es siempre la misma**, exista o no la cuenta. Ver el
   comentario largo del endpoint: si distinguiera los casos sería un
   enumerador de cuentas. La contracara es que el endpoint no puede decir qué
   falló, y por eso el error crudo del SMTP se guarda en
   `password_resets.error` y existe `probar_mail.php`.
4. El mail trae `recuperar.html?token=<hex>`. La base del link sale de
   `APP_BASE_URL` del `.env` y **no del header `Host`**: derivarlo del `Host`
   es el agujero clásico del reseteo por mail (se pide el reseteo de la cuenta
   de otro con un `Host` propio, y a la víctima le llega un mail legítimo cuyo
   link apunta al servidor del atacante).
5. `recuperar.html` pregunta primero a `info_reset.php` si el token sirve
   (tres estados, calcado de `invitacion.html`) y recién ahí muestra el
   formulario, con el email enmascarado para que la persona confirme de qué
   cuenta se trata.
6. `POST restablecer_password.php` con el token y la contraseña nueva.
   Transacción con `SELECT ... FOR UPDATE` sobre el token: valida que no esté
   usado ni vencido, escribe `password_hash` **y `password_cambiado_en`** en
   el mismo UPDATE, marca `usada_at` y anula los demás tokens vivos del
   usuario.
7. **No hay auto-login** (a diferencia de `aceptar_invitacion.php`): la
   persona vuelve a `login.html` y entra con su contraseña nueva.

**Lo que hace que el reseteo sirva de algo: echa a las sesiones abiertas.**
El caso a cubrir es "me entraron a la cuenta"; si el atacante ya tiene su
cookie, cambiar la contraseña sin echarlo lo deja adentro igual que antes. Las
sesiones de PHP son archivos en disco y no se pueden barrer por usuario, así
que la invalidación es indirecta: `establecer_sesion()` guarda en la sesión la
foto de `usuarios.password_cambiado_en` del momento del login, y
`sesion_actual()` la compara en cada request contra la de la base. La que no
coincide deja de valer. Cuesta un `SELECT` por PK por request y se paga a
propósito.

Si no se puede consultar la base (MySQL caído, o una instalación donde no se
corrió `migracion_reset_password.sql` y la columna no existe),
`estado_usuario_en_base()` devuelve `false` y **no se toma ninguna decisión**:
la sesión sigue. Un hipo de MySQL no puede desloguear a todo el mundo.

**El vencimiento lo compara MySQL, no PHP.** `motivo_reset_invalido()` usa un
`(expira_at <= NOW())` calculado en la query y no `strtotime(...) < time()`.
No es preferencia de estilo: `expira_at` lo escribe MySQL con su reloj y
`time()` es el de PHP con la timezone de `php.ini`, y en la máquina de
desarrollo esos dos relojes están a **cinco horas** (PHP en `Europe/Berlin`,
MySQL en hora local). Con la comparación en PHP, un token de una hora nacía
vencido siempre. `aceptar_invitacion.php` tiene el mismo patrón y el mismo
desfasaje, pero como una invitación dura 7 días no se nota — es la razón por
la que estaba ahí sin que nadie lo viera.

### Flujo administrador (dashboard mockeado, chatbot real)

El dashboard de `administrador.html` (KPIs, tabla de inventario) sigue sin
llamar a ningún endpoint PHP ni a FastAPI: usa un objeto `MockDB` que
persiste un array hardcodeado (`CATALOGO_BASE`) en `localStorage` (clave
`stockiate_inventory_v1`). El widget de chat que tenía esta página (pattern-
matching en JS que fabricaba un string de SQL para *mostrar*, sin ejecutar
nada) fue reemplazado por el chatbot IA real — ver "Flujo chatbot IA"
abajo.

### Avisos por mail (segundo canal)

WhatsApp está construido y **no manda nada**: las tres plantillas nunca se
aprobaron en Meta, así que cada intento vuelve con
`(#132001) Template name does not exist`. Sobre la base real había 50 avisos
encolados desde el 03/09/2026, todos con ese error. El mail no necesita la
aprobación de nadie.

Los dos canales son **independientes**: cada uno con su interruptor
(`email_activo`, `whatsapp_activo`) y su destino (`email_destino`,
`whatsapp_telefono`) en `configuracion`. Un negocio puede tener los dos, uno o
ninguno. WhatsApp no se sacó — el día que las plantillas se aprueben, empieza a
andar sin tocar código.

Lo que cambió en el camino que ya existía:

1. `notificaciones.canal` (`ENUM('whatsapp','email')`, DEFAULT `'whatsapp'`)
   dice por dónde sale cada fila. **Una fila por canal**, no una fila con dos
   destinos: cada envío tiene su propio estado, sus propios intentos y su
   propio error; con una sola fila habría que elegir cuál de los dos resultados
   guardar.
2. `encolar_notificacion()` ya no devuelve un id sino un **array de ids**, uno
   por canal activo. Un array vacío sigue siendo falsy, así que los
   `if ($encolado)` de quien llama siguen valiendo.
3. **La deduplicación es POR CANAL**: la query lleva `AND canal = :canal` y el
   índice pasó a ser `(negocio_id, clave_dedup, canal, creada_en)`. Sin eso, la
   fila del WhatsApp deduplica a la del mail y **activar el segundo canal no
   manda nada**, sin dejar ningún error en ningún lado.
4. La guarda de los disparadores (`registrar_venta.php`,
   `tareas_notificaciones.php`) pasó de `whatsapp_habilitado()` a
   `avisos_habilitados()`. Con la vieja, un negocio que sólo tiene el mail
   activado no recibe nada — el error más fácil de cometer al sumar un canal.
5. El vaciado de la cola **filtra por canal enviable** (`canal IN (...)`) en vez
   de cortar entero cuando falta una credencial. Antes, sin token de Meta el
   script se iba sin mandar nada; eso dejaría un mail perfectamente enviable
   trabado por una credencial que no tiene nada que ver con él.
6. `enviar_notificacion()` se bifurca por `canal` y normaliza la respuesta del
   mail a la misma forma que devuelve WhatsApp (`ok`/`wamid`/`error`/`http`,
   con `wamid` en null porque es un id de Meta y en mail no existe). Todo lo de
   abajo — contar intentos, marcar `enviada`/`fallida`, guardar el error — es
   idéntico para los dos y no se duplica.

**`destino` pasó de VARCHAR(20) a VARCHAR(150)**: 20 alcanza para un teléfono
E.164 y no para una dirección de correo. Sin ese ALTER, MariaDB en modo no
estricto guardaría el mail truncado y **en silencio**, y el envío fallaría con
un error de dirección inválida que no se parece en nada a la causa.

**El acople que queda a la vista**: los `parametros` son posicionales porque la
Cloud API de Meta los quiere así (`{{1}}`, `{{2}}`, ...). `CAMPOS_POR_TIPO`
documenta qué es cada posición, pero si alguien agrega un parámetro a un aviso
y toca sólo uno de los dos renderizadores, el otro canal manda un mensaje con
un hueco. Cambiarlos a un array asociativo obligaba a tocar tres disparadores y
a migrar el JSON de las filas ya encoladas; se dejó como está, documentado.

### Flujo chatbot IA

1. `chatbot_widget.js` es un componente compartido (autocontenido, inyecta
   su propio CSS) incluido igual en las tres pantallas de rol, pero ya no
   como `<script>` estático: `auth.js` lo inyecta dinámicamente después de
   validar la sesión (`insertarChatbotWidget(usuarioSesion)`), poniendo
   `data-rol` con el rol real del usuario logueado y `data-contexto` con la
   sección de la página (`CONTEXTO_CHATBOT_POR_PAGINA` en `auth.js`) —
   `chatbot_widget.js` los lee de `document.currentScript` al cargar, por eso
   el `<script>` se crea recién con esos atributos ya puestos, en vez de
   editarlos después sobre uno estático.
2. El widget mantiene un historial corto en memoria (no persiste entre
   recargas) y hace `POST` a `/chatbot` con:
   ```json
   {
     "pregunta": "¿qué productos tienen stock bajo?",
     "contexto": "repositor",
     "historial": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]
   }
   ```
   `rol` y `usuario_id` **no** viajan en el body: los resuelve el backend
   reenviando la cookie de sesión a `sesion_actual.php`.
3. `main.py` (endpoint `/chatbot`) resuelve el rol desde la sesión y llama a
   `chatbot_ia.responder_pregunta()`, que arma un mensaje a Groq
   (inferencia gratuita con límites de uso, API compatible con el formato
   de tool-calling de OpenAI) con **tool-use restringido por rol Y por
   sección**:
   - `ROLE_TOOLS` — `repositor` → `consultar_stock`,
     `consultar_vencimientos`, `consultar_productos`; `cajero` →
     `consultar_ventas`, `consultar_stock`, `consultar_productos`; `dueño` →
     las cinco sin restricción.
   - `CONTEXT_TOOLS` — mismo formato, pero por pantalla: `repositor` y
     `cajero` igual que arriba, `admin` sin restricción.
   - `_nombres_permitidos()` devuelve la **intersección**. Un `dueño` parado
     en `repositor.html` puede preguntar por stock y vencimientos pero no por
     facturación; el rol sigue siendo el techo, así que falsear el contexto
     no habilita nada.

   No hay texto-a-SQL libre: cada herramienta ejecuta una query fija y
   parametrizada del lado PHP.
4. Cuando Groq decide usar una herramienta, `chatbot_ia.py` **primero valida
   que esté en el set permitido** y recién ahí le pega (vía `requests`) al
   endpoint PHP correspondiente (`consultar_stock.php`,
   `consultar_ventas.php`, `consultar_vencimientos.php`,
   `consultar_productos.php` o `consultar_rentabilidad.php`), le devuelve el
   resultado JSON a Groq como mensaje `role: "tool"`, y repite el loop (máx.
   5 iteraciones) hasta que el modelo devuelve una respuesta final en texto.
   El servicio Python sigue sin tocar MySQL directamente.

   Ese chequeo no es teórico: `gpt-oss-20b` arma los `tool_calls` a partir
   del texto de la pregunta y se lo vio llamar a `consultar_ventas` desde el
   contexto `repositor`, donde esa herramienta ni siquiera se le había
   ofrecido. Filtrando sólo la oferta, el recorte por sección era una
   sugerencia; filtrando también la ejecución, es una regla.
5. `main.py` llama a `chatbot_ia.registrar_log()`, que hace `POST` a
   `registrar_chatbot_log.php` para guardar el intercambio en
   `chatbot_conversaciones` (best-effort: si falla, no rompe la respuesta
   ya devuelta al usuario).
6. Manejo de errores en `/chatbot`: si falta `GROQ_API_KEY` →
   **503** (validación *lazy*, a diferencia de `ROBOFLOW_API_KEY` que
   aborta el arranque de todo el proceso — así `/procesar-imagen` sigue
   funcionando aunque el chatbot no esté configurado); error de conexión
   con Groq → **502**.

### Baja de producto (archivar) y carga de costos

Las dos operaciones son del panel de administrador y sólo del rol `dueño`.

**Archivar.** El botón 📦 de la tabla de inventario hace `POST` a
`archivar_producto.php`, que pone `productos.activo = 0` y sella
`archivado_en`. No borra la fila: `ventas`, `lotes_stock` y
`correcciones_ia` le apuntan con FK sin `ON DELETE CASCADE`, así que
cualquier producto con historial era imposible de borrar — sobre una base
real con 36 productos, exactamente 1 lo era.

Qué filtra `activo = 1` y qué no:

| Filtra | No filtra |
|---|---|
| `consultar_inventario.php`, `consultar_productos.php`, `consultar_stock.php`, `consultar_vencimientos.php`, `consultar_rentabilidad.php` | `consultar_ventas.php` |
| `guardar_stock.php`, `actualizar_stock.php`, `registrar_venta.php`, `actualizar_costo.php` | el scan de SKU de `crear_producto.php` |

Los dos casos que **no** filtran son deliberados y cada uno tiene su
comentario en el código: `consultar_ventas.php` reporta historial (filtrarlo
haría desaparecer facturación de meses ya cerrados al archivar un producto), y
el SKU de un archivado sigue ocupado (si el generador lo ignorara, restaurar
podría chocar contra un SKU tomado mientras tanto).

Se puede archivar con `stock_actual > 0`: exigir stock en cero dejaba 26 de 36
productos intocables. El `confirm()` del frontend avisa cuántas unidades dejan
de contarse. El stock **no** se pone en cero — si se restaura, vuelve con lo
que tenía.

`consultar_inventario.php` acepta `{"archivados": true}` para la vista de
restauración, que es sólo del `dueño` (el mismo `inventario_tabla.js` lo usa
`repositor.html`, así que el toggle y el botón 📦 se ocultan por rol además de
bloquearse server-side).

**Carga de costos.** `costos_tabla.js` resuelve el problema de que
`crear_producto.php` acepte el costo pero lo tenga como opcional: en la Red de
Seguridad, con el cliente esperando, casi nunca se carga, y los productos
viejos tampoco lo tenían. Sin `precio_costo` todo lo que el chatbot responde
sobre margen sale parcial. La tabla guarda **campo por campo** al salir de
cada input (no la fila entera: mandar los tres siempre haría que editar el
costo pisara un precio de venta cambiado en otra pestaña) y recalcula el
margen mientras se tipea, antes de guardar.

### Alta de producto y SKU automático

Cuando la detección no matchea con ningún producto del catálogo (o el
usuario elige "+ Crear producto nuevo"), la pantalla de validación muestra
un formulario de alta — duplicado en `repositor.html` y `cajero.html`, como
el resto de la Red de Seguridad. Obligatorio hay uno solo: **nombre**.

- **Autocompletado**: el nombre se precarga con lo que leyó el OCR
  (`clase` + `volumen`, pasado a mayúscula inicial), y la marca con la
  primera palabra de ese texto. Como la marca y el nombre se pueden editar,
  el SKU sugerido se recalcula mientras se tipea.
- **SKU automático**: se sugiere `PREFIJO-NNN` (prefijo = 3 alfanuméricos de
  la marca, o del nombre si no hay marca, sin acentos y en mayúscula;
  fallback `PRD`) con el primer número libre. El frontend lo calcula contra
  el catálogo que ya tiene en memoria, pero **la unicidad real la garantiza
  `crear_producto.php`**: si el SKU vino vacío o marcado como automático
  (`sku_auto: true`), el servidor lo regenera y reintenta hasta 5 veces ante
  una colisión, así dos altas simultáneas no se pisan. Si el usuario lo
  escribió a mano y ya existe, responde **409** en vez de cambiárselo.
- **Proveedor y precio de costo** son opcionales. El proveedor es un
  `<input list="lista-proveedores">`: se elige uno existente (traído por
  `consultar_proveedores.php`) o se escribe uno nuevo, que
  `crear_producto.php` inserta en `proveedores` y asocia. El precio de costo
  vacío se guarda como `NULL` (distinto de `0`).

### Flujo de notificaciones por WhatsApp

Es el único flujo del sistema que **no arranca con alguien mirando una
pantalla**, y está partido en dos mitades que no se conocen: quien detecta
encola, y quien envía lo hace después. Esa separación es todo el diseño.

**Mitad 1 — encolar (rápido, local, nunca falla hacia afuera).**

1. Hay dos disparadores.
   - `registrar_venta.php`, **después del `commit()` y fuera de la
     transacción**: por cada ítem del ticket compara el stock resultante
     (`stock_actual - cantidad`, que ya tiene de la fila lockeada) contra
     `stock_minimo` usando `es_critico()`. Si quedó en 0 encola `sin_stock`;
     si quedó en o bajo el mínimo, `stock_critico`.
   - `tareas_notificaciones.php`, para lo que no tiene evento de usuario:
     lotes por vencer (`lotes_por_vencer()`) y el resumen del día, cuando la
     hora actual pasó `whatsapp_hora_resumen`.
2. `encolar_notificacion()` (en `notificaciones.php`) chequea que el negocio
   tenga los avisos activados y un teléfono interpretable, deduplica contra
   `clave_dedup` dentro de una ventana de horas, y hace **un INSERT** en
   `notificaciones` con el destino ya normalizado, la plantilla y los
   parámetros como JSON.
   La ventana sale de la configuración del negocio
   (`ventana_notificaciones_config()`) **sólo en el camino de la venta**, que
   es el único donde la `clave_dedup` no lleva fecha (`'stock_critico:42'`) y
   diez ventas del mismo producto serían diez avisos idénticos. Los avisos
   diarios del script llevan la fecha en la clave
   (`'resumen_diario:2026-09-05'`), así que ya son uno por día por
   construcción y se quedan con el default de 24 h: con una ventana de 6 h el
   resumen diario saldría tres veces por noche.
3. Nada más. La venta responde con su `200` sin haber salido a internet
   (medido: sigue en ~30 ms).

**Mitad 2 — enviar (lento, remoto, puede fallar todo lo que quiera).**

4. El Programador de tareas de Windows corre
   `php tareas_notificaciones.php` cada 5 minutos.
5. Si faltan las credenciales del `.env`, **no toca la cola** y termina: cada
   intento gastaría uno de los 3 que tiene cada fila, y a la tercera quedaría
   `fallida` esperando un token que estaba por llegar.
6. Con credenciales, levanta hasta 20 pendientes con `intentos < 3` en orden
   de llegada y llama a `enviar_notificacion()`, que arma el request y le pega
   a `https://graph.facebook.com/{version}/{phone_number_id}/messages` con
   `type: template`.
7. Marca el resultado en la misma fila: `enviada` con el `wamid` de Meta, o
   suma un intento y guarda el `error` crudo. Vuelve a `pendiente` si le
   quedan intentos, `fallida` si era el último.

**Qué ve el dueño.** `administrador.html` -> el chip de la topbar
(`menu_negocio.js`) -> `preferencias.js`, que pide los
ajustes a `consultar_preferencias.php` y las últimas 10 filas (con su estado y
su error) a `consultar_notificaciones.php`. El botón de prueba
(`probar_notificacion.php`) encola una fila de tipo `prueba` y la manda en el
acto **con la misma función** que usa el script: es lo que hace que la prueba
pruebe algo.

**Por qué esto y no un envío directo.** Un `curl` a Meta adentro de
`registrar_venta.php` le sumaría la latencia de internet a cada cobro,
acoplaría la caja a un servicio de terceros, no tendría reintentos, y —si
quedara dentro de la transacción— un WhatsApp fallido haría rollback de una
venta real. Con la cola, lo peor que puede pasar es que un aviso llegue cinco
minutos tarde.

**Payload de ejemplo** (lo que queda en la fila y lo que sale):

```
-- fila en `notificaciones`
tipo        = 'stock_critico'
clave_dedup = 'stock_critico:60'
destino     = '5491155554444'
plantilla   = 'alerta_stock'
parametros  = ["Perfumería Centro","Queda poco stock de Sauvage 100ml",5,5]
estado      = 'pendiente'
```

```json
POST https://graph.facebook.com/v23.0/{phone_number_id}/messages
{
  "messaging_product": "whatsapp",
  "to": "5491155554444",
  "type": "template",
  "template": {
    "name": "alerta_stock",
    "language": { "code": "es" },
    "components": [{
      "type": "body",
      "parameters": [
        { "type": "text", "text": "Perfumería Centro" },
        { "type": "text", "text": "Queda poco stock de Sauvage 100ml" },
        { "type": "text", "text": "5" },
        { "type": "text", "text": "5" }
      ]
    }]
  }
}
```

## Esquema de base de datos

Definido en `schema.sql`, base `stockiate` (utf8mb4).
Sobre una base creada con una versión anterior hay que correr
`migracion_proveedores_costo.sql` (tabla `proveedores` + columnas
`precio_costo` / `proveedor_id`), `migracion_usuarios_apellido.sql`,
`migracion_costo_ventas.sql` (columna `ventas.costo_unitario`),
`migracion_productos_activo.sql` (columnas `productos.activo` /
`archivado_en`) y `migracion_reset_password.sql` (tabla `password_resets` +
columna `usuarios.password_cambiado_en`).

- **`usuarios`** — `id`, `nombre`, `apellido`, `email` (único),
  `password_hash`, `password_cambiado_en`, `rol ENUM('repositor', 'cajero',
  'dueño')`, `creado_en`. `password_cambiado_en` no es auditoría: es lo que
  echa a las sesiones abiertas cuando alguien resetea su contraseña (ver
  "Flujo recuperación de contraseña"). NULL = nunca la cambió.
  `rol = 'dueño'` es el valor interno (coincide con el ENUM y con
  `chatbot_ia.py`); en la UI de `registro.html` se etiqueta
  "Administrador". Se llena a través de `registrar_usuario.php`
  (`login.html`/`registro.html` — ver "Flujo login / registro"); antes no
  tenía código que la usara.
- **`password_resets`** — los tokens de un solo uso del "olvidé mi
  contraseña". `usuario_id` (FK), `token_hash` (**SHA-256 en hex del token
  real; el token en claro no se guarda nunca**), `expira_at` (1 hora, no 7
  días como una invitación), `usada_at`, `enviado_at`, `error` (el diálogo
  crudo del SMTP, para diagnosticar "no me llegó nada"), `ip_solicitud`,
  `creado_en`. **Es la excepción deliberada a "toda tabla lleva
  `negocio_id`"**: se consulta antes de que exista una sesión, o sea sin
  ningún `negocio_id` con el cual filtrar; la búsqueda es por token, que es un
  secreto global (mismo criterio que el `token` de `invitaciones`), y el
  negocio se deriva del usuario.

- **`productos`** — catálogo base. `activo` (baja lógica: el botón 📦 del
  panel lo pone en 0, la fila nunca se borra) y `archivado_en`. `sku` (único), `nombre`, `marca`,
  `variante` (para distinguir variantes con el mismo envase, ej. dos
  fragancias de 100ml), `categoria`, `precio_venta`, `precio_costo`
  (nullable, opcional), `proveedor_id` (FK nullable a `proveedores`),
  `stock_actual`, `stock_minimo` (umbral configurable por producto para
  alertas de stock bajo). El `sku` lo genera `crear_producto.php` con
  formato `PREFIJO-NNN` (ver "Alta de producto y SKU automático").
- **`proveedores`** — `id`, `nombre` (único), `creado_en`. Se dan de alta
  solos: si en el formulario de alta de producto se escribe un proveedor que
  no existe, `crear_producto.php` lo inserta y lo asocia.
- **`lotes_stock`** — un producto puede tener varios lotes con distinta
  fecha de carga/vencimiento. `producto_id` (FK), `cantidad`,
  `fecha_carga` (default `CURRENT_TIMESTAMP`), `fecha_vencimiento`
  (nullable — si es `NULL` ese lote no participa del control de
  vencimientos), `usuario_id` (FK, quién cargó el lote). Índice
  `idx_lotes_vencimiento` sobre `fecha_vencimiento`.
- **`ventas`** — `producto_id` (FK), `cantidad`, `precio_unitario`
  (copiado de `productos.precio_venta` al momento de la venta),
  `costo_unitario` (copiado de `productos.precio_costo` al momento de la
  venta; `NULL` si el producto no tiene costo cargado, o si la venta es
  anterior a `migracion_costo_ventas.sql`), `usuario_id` (FK), `fecha`.
  Índices `idx_ventas_fecha` e `idx_ventas_producto`.

  `costo_unitario` no es redundante con `productos.precio_costo`: ésa es el
  costo de HOY y se pisa cuando el proveedor aumenta. Sin el snapshot, el
  margen de una venta de marzo se recalcularía a precios de agosto cada vez
  que se consulta. `consultar_rentabilidad.php` lo usa con
  `COALESCE(v.costo_unitario, p.precio_costo)` y reporta cuántas unidades
  quedaron sin costo, para que el chatbot pueda avisar cuándo el número es
  parcial.
- **`correcciones_ia`** — feedback loop de la "Red de Seguridad":
  `producto_detectado_id` (lo que la IA pensó que era, FK nullable),
  `producto_corregido_id` (lo que el repositor confirmó, FK nullable),
  `cantidad_detectada`, `cantidad_corregida`, `confianza_ia` (score que
  devuelve Roboflow), `usuario_id`, `fecha`. Se llena a través de
  `registrar_correccion.php`, que todavía no existe.
- **`configuracion`** — tabla clave-valor por negocio (PK
  `(negocio_id, clave)`) para los ajustes editables desde el panel. La semilla
  de un negocio nuevo está en `CONFIG_INICIAL_NEGOCIO`
  (`configuracion.php`): `umbral_dias_vencimiento = 30`,
  `ventana_notificaciones_horas = 24`, `stock_minimo_default = 5`,
  `whatsapp_telefono = ''`, `whatsapp_activo = '0'`,
  `whatsapp_hora_resumen = '20:00'`. Los avisos arrancan **desactivados** a
  propósito: dar de alta un negocio no puede tener como efecto que le empiecen
  a llegar mensajes a alguien.
  Esa constante es además **la tabla de defaults del proyecto**:
  `leer_config_int()` cae ahí cuando el negocio no tiene la fila, así que el
  default vive en un solo lugar en vez de repetido en cada consumidor. Sus
  valores son los mismos que antes estaban hardcodeados (24 h era el default
  en la firma de `encolar_notificacion()`; 5 es el `DEFAULT` de la columna
  `productos.stock_minimo`), de modo que sembrar la config no le cambia el
  comportamiento a ningún negocio que ya venía andando.
- **`notificaciones`** — la cola de avisos, que es a la vez el log.
  `negocio_id`, `tipo` (`stock_critico` | `sin_stock` | `prediccion_quiebre` |
  `vencimientos` | `resumen_diario` | `prueba` | `prueba_simple`), **`canal`**
  (`ENUM('whatsapp','email')`, DEFAULT `'whatsapp'`), `clave_dedup`
  (identifica el HECHO, no la fila: `'stock_critico:42'`), `destino` (snapshot
  del destino al encolar, para que cambiarlo en el panel no redirija avisos ya
  encolados), `plantilla`, `parametros` (JSON con las variables `{{1}}..{{n}}`
  en orden), `estado` (`pendiente` | `enviada` | `fallida`), `intentos`,
  `error` (el mensaje crudo de Meta o el diálogo del SMTP), `wamid` (sólo
  WhatsApp: es un id de Meta), `creada_en`, `enviada_en`. Índices
  `idx_notif_pendientes (estado, creada_en)` para el barrido del script y
  `idx_notif_dedup_canal (negocio_id, clave_dedup, canal, creada_en)` para la
  deduplicación — **el canal es parte de la clave**, si no la fila de un canal
  deduplica a la del otro y activar el segundo no manda nada.
  **`destino` es VARCHAR(150) y no VARCHAR(20)**: 20 alcanza para un teléfono
  E.164 y no para un email, y MariaDB en modo no estricto lo truncaría en
  silencio. Los tipos `prediccion_quiebre` y `prueba_simple` faltaban en el
  ENUM original: sobre la base real hay 2 filas con el tipo guardado como
  cadena vacía por ese motivo, imposibles de reconstruir.
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
  pueden navegar), no los endpoints.
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
- **Credenciales de MySQL hardcodeadas** en `conexion.php` (`root`, sin
  password) — es el default de XAMPP en local, pero no está pensado para
  otro entorno.
- **CORS abierto (`*`)** tanto en los endpoints PHP (headers manuales) como
  en FastAPI (`CORSMiddleware`) — aceptable en desarrollo local, no
  pensado para exponer el servicio más allá de `localhost`.
