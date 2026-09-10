# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Resumen del proyecto

stockIAte es un proyecto de tesis: un sistema de gestión de inventario asistido
por IA para un comercio minorista (perfumería). Está
compuesto por tres capas que colaboran pero **no comparten código ni
framework**: persistencia en PHP plano (PDO/MySQL), un microservicio Python
(FastAPI) que orquesta la detección por imagen vía Roboflow, y un frontend
estático (HTML/CSS/JS vanilla, sin build). Corre en local sobre XAMPP.

## Cómo correr el proyecto

No hay build, linter ni test runner configurado en este repo. Para levantar
todo:

1. **XAMPP**: iniciar Apache y MySQL. Importar `schema.sql` en una base
   `stockiate` (el script hace `CREATE DATABASE IF NOT EXISTS stockiate`).
   **Importalo en UTF-8** o se rompe el rol `dueño` en silencio (ver "Cosas
   para tener en cuenta"):
   ```
   mysql -u root --default-character-set=utf8mb4 < schema.sql
   ```
   Si la base ya existía de antes, correr también, en este orden:
   - `migracion_proveedores_costo.sql` — tabla `proveedores` y columnas
     `precio_costo`/`proveedor_id` en `productos`.
   - `migracion_multitenant.sql` — negocios, invitaciones y `negocio_id` en
     todas las tablas.
   - `migracion_costo_ventas.sql` — `ventas.costo_unitario`, el snapshot del
     costo al momento de vender, del que salen los márgenes históricos.
   - `migracion_productos_activo.sql` — `productos.activo` / `archivado_en`,
     la baja lógica que reemplazó al borrado de productos.
   - `migracion_notificaciones_whatsapp.sql` — la tabla `notificaciones` (la
     cola de avisos) y las tres filas de `configuracion` por negocio. Deja
     todo **desactivado**: después de correrla no sale ningún mensaje hasta
     que un dueño cargue su número en el panel.
   - `migracion_reposicion.sql` — las tres filas de `configuracion` de la
     lista de reposición (`reposicion_dias_entrega`,
     `reposicion_dias_objetivo`, `reposicion_factor_seguridad`). Aditiva e
     idempotente; sin ella los lectores caen igual al default de
     `CONFIG_INICIAL_NEGOCIO`, así que no rompe nada correrla tarde.
   - `migracion_preferencias_negocio.sql` — las dos filas de `configuracion`
     que faltaban (`ventana_notificaciones_horas`, `stock_minimo_default`),
     con los mismos valores que ya eran el comportamiento hardcodeado. No
     toca tablas y es idempotente: no pisa lo que el dueño haya configurado.

   Las cuatro últimas son aditivas y con DEFAULT: se corren de una sola vez y
   son seguras con el código viejo andando.
   **Ojo: `migracion_multitenant.sql` se corre en dos
   tandas** — la PARTE A es segura con el código viejo andando, la PARTE B
   (que pone `NOT NULL` y cierra las FK compuestas) va comentada y sólo se
   descomenta después de desplegar el código nuevo y verificar que no
   quedaron `negocio_id` en NULL. El archivo explica el porqué y trae las
   queries de verificación.
   El proyecto debe estar bajo `htdocs` para que los endpoints PHP resuelvan
   en `http://localhost/stockiate/tesis_enzo/*.php`.
2. **Servicio de IA** (proceso aparte, necesario para la detección por
   imagen — sin él el frontend cae a carga manual):
   ```
   pip install -r requirements.txt
   uvicorn main:app --reload
   ```
   (o `python main.py`). Escucha en el puerto 8000. Requiere un archivo
   `.env` en la raíz del proyecto (copiar `.env.example` y completar los
   valores; el `.env` real está gitignoreado) con `ROBOFLOW_API_KEY` — `main.py` lanza
   un error al arrancar si falta. El chatbot IA (`/chatbot`) además necesita
   `GROQ_API_KEY` en ese mismo `.env` (se consigue gratis en
   [console.groq.com/keys](https://console.groq.com/keys)), pero a
   diferencia de Roboflow esa validación es *lazy*: si falta, el resto del
   backend (detección de imagen) sigue funcionando y solo `/chatbot`
   responde 503. Las keys de Groq se leen **en cada llamada**, no al
   importar el módulo: si editás el `.env`, el `--reload` de uvicorn alcanza
   (leyéndolas arriba quedaba cacheada la vieja y Groq tiraba 401 con un
   `.env` ya corregido). Se pueden cargar **keys de repuesto**
   `GROQ_API_KEY_2` .. `GROQ_API_KEY_9`: ver "Rotación de API keys de Groq".
3. **Frontend**: sin paso de build. Abrir las páginas a través de Apache,
   nunca con `file://`, ej. `http://localhost/stockiate/tesis_enzo/landing_page.html`.

### Modo demostración

Para mostrar el sistema lleno sin depender de que el comercio del piloto haya
vendido algo ese día:

```
# en el .env
STOCKIATE_MODO=demo
# y después
php seed_demo.php
```

Para volver: `STOCKIATE_MODO=normal` (o borrar la línea). Nada más — la base
real no se tocó en ningún momento. Cuentas y detalle de lo que se genera, en
el [README](README.md).

No hay test framework. Lo más cercano a un test es `smoke_test_roboflow.py`,
un script manual (no pytest, no hay runner configurado):
```
python smoke_test_roboflow.py [ruta/a/imagen.jpg]
```
También requiere `.env`. `debug_roboflow_raw.py` es otro script manual que
vuelca la respuesta cruda del workflow de Roboflow para debugging.

## Arquitectura

Flujo típico (repositor o cajero): el usuario saca una foto desde
`repositor.html` o `cajero.html` → se envía por `POST` a
`http://localhost:8000/procesar-imagen` (FastAPI, `main.py`) → éste llama a
`roboflow_workflow.py`, que ejecuta el Workflow de Roboflow (detección YOLO +
OCR de marca) → el frontend muestra la "Red de Seguridad": una pantalla de
validación humana donde se confirma/corrige cantidad, producto y vencimiento
antes de escribir nada en la base → recién ahí el frontend llama directo a
los endpoints PHP (`guardar_stock.php` o `registrar_venta.php`), que
persisten en MySQL vía `conexion.php` (PDO), dentro de una transacción.

Puntos importantes de este diseño:

- **Modo demo: dos BASES, no un flag.** `conexion.php` ya no tiene el nombre
  de la base en el DSN: sale de `STOCKIATE_BASE`, que es `stockiate` o
  `stockiate_demo` según `STOCKIATE_MODO` en el `.env`. La alternativa obvia
  era una columna `es_demo` en cada tabla, y se descartó: alcanza con que UNA
  de las ~20 queries se olvide de filtrarla para que datos inventados se
  cuelen en un promedio del piloto, meses después y sin nada que lo delate.
  Dos bases no se mezclan aunque el código tenga bugs.
  `seed_demo.php` las siembra: aplica el **mismo `schema.sql`** (descartando
  sus `CREATE DATABASE`/`USE`, que apuntan a la base real) para que agregar
  una tabla no obligue a mantener dos esquemas, y antes de escribir le
  pregunta al motor `SELECT DATABASE()` — no le cree a la constante, que es
  justo lo que alguien podría cambiar sin querer. Es **determinístico**
  (`mt_srand`), así que una captura del informe se puede rehacer idéntica.
  Tres cosas que parecen detalles y no lo son:
  **la sesión guarda contra qué base se abrió** (`$_SESSION['usuario']['base']`,
  chequeado en `sesion_actual()`): los IDs de las dos bases son numeraciones
  independientes, y sin eso cambiar de modo con la sesión abierta te dejaba
  logueado como el usuario que tuviera ESE id en la otra base;
  **la banda naranja de `auth.js`** (`mostrarBandaDemo()`, z-index 1000001 —
  arriba del panel del negocio, que es 1000000) no se puede cerrar y no usa
  las variables del tema, para que se vea igual en claro, oscuro e impreso: su
  razón de ser es que una captura de la demo no se pueda confundir con una del
  piloto;
  y **el negocio de la demo NO se llama `'Negocio Demo'`**, que es el nombre
  reservado del backdoor `sesion_demo.php`, ni sus cuentas usan
  `@stockiate.test`. Son dos mecanismos distintos y no se tienen que pisar.
- **Multi-tenant: cada comercio es un `negocio` y sus datos están
  aislados.** La regla que sostiene todo el aislamiento es una sola: **el
  `negocio_id` nunca viaja en el body, sale de `$_SESSION`**. Su corolario
  es que toda query de un endpoint lleva `negocio_id = :negocio_id` en el
  WHERE (o inserta la columna) — verificable con un grep. MySQL no tiene
  RLS como el Postgres de Supabase, así que el aislamiento lo garantiza el
  código PHP (`sesion.php` → `exigir_sesion()`), apoyado en FK compuestas
  `(producto_id, negocio_id)` que impiden a nivel de motor que una venta o
  un lote apunten a un producto de otro negocio.
  `negocio_id` es una **columna en cada tabla de datos**, no algo que se
  deriva por JOIN: varios endpoints agregan sobre `ventas` sin joinear
  `productos`, y con la columna el filtro correcto es idéntico joineen o no.
- **El servicio Python nunca toca MySQL.** Solo hace de puente hacia
  Roboflow; toda la persistencia pasa por los endpoints PHP, a los que el
  frontend les pega directamente.
- **Sesión de servidor real** (`sesion.php`): cookie `STOCKIATE_SID`,
  `session_start()`, y cada endpoint deriva `usuario_id`/`rol`/`negocio_id`
  de ahí. `localStorage` sigue existiendo en `auth.js` pero **degradado a
  caché de UX** (para redirigir y pintar el nombre sin round-trip), ya no es
  seguridad. Los fetch a PHP usan rutas relativas para que la cookie viaje
  sin CORS; ver `config.js`.
- **Offline-first**: si Roboflow no responde, `/procesar-imagen` devuelve
  `modo_offline: true` y el frontend cae a carga manual sin romper el flujo.
- **`registrar_venta.php`** recibe el **ticket completo** (`items: [...]`,
  con compatibilidad hacia atrás con el formato de un solo producto) y lo
  registra de forma **atómica**: lockea con `SELECT ... FOR UPDATE`, valida
  el stock de todos los renglones antes de escribir y, si alguno falla,
  responde 409 con el detalle por producto sin registrar ninguno. Usa
  siempre `productos.precio_venta` del servidor (nunca un precio que mande
  el cliente). El repositor (`guardar_stock.php`) todavía manda un request
  por producto.
- Los flujos de repositor y cajero implementan la pantalla de validación
  ("Red de Seguridad") **cada uno por su cuenta** — no es un componente
  compartido, hay lógica duplicada entre `repositor.html` y `cajero.html`.
- **Los productos NO se borran: se archivan.** El botón 📦 de la tabla de
  inventario le pega a `archivar_producto.php`, que pone `productos.activo =
  0`. Antes había un `eliminar_producto.php` con un DELETE real, pero
  `ventas`, `lotes_stock` y `correcciones_ia` tienen FK a `productos` **sin
  ON DELETE CASCADE** (a propósito: borrar en cascada destruiría ventas
  reales), así que cualquier producto con historial era imposible de borrar —
  sobre la base real, 1 de 36. **Todas las queries del catálogo filtran
  `activo = 1`, con dos excepciones deliberadas**: `consultar_ventas.php`
  (reporta historial; filtrarlo haría desaparecer facturación de meses
  cerrados al archivar) y el generador de SKU de `crear_producto.php` (el SKU
  de un archivado sigue ocupado, si no restaurar podría chocar). Se puede
  archivar con stock > 0 — el `confirm()` avisa cuántas unidades dejan de
  contarse — y se restaura desde "Ver archivados"
  (`consultar_inventario.php` con `{"archivados": true}`). Archivar,
  restaurar y ver archivados son **sólo del rol `dueño`**.
- **Carga de costos**: el bloque "Costos y márgenes" de `administrador.html`
  (`costos_tabla.js` + `actualizar_costo.php`, ambos sólo `dueño`) existe
  porque en la Red de Seguridad el costo es opcional y con el cliente
  esperando casi nunca se carga — y sin `precio_costo` todo lo que responde
  el chatbot sobre margen sale parcial. Lee del **mismo**
  `consultar_rentabilidad.php` que usa el chatbot, así que la tabla y el
  asistente nunca muestran números distintos. Guarda **campo por campo** al
  salir de cada input, no la fila entera: mandar los tres siempre haría que
  editar el costo pisara un precio de venta cambiado en otra pestaña.
- **Alta de producto**: en esa misma pantalla se puede crear un producto que
  no está en el catálogo. Sólo el nombre es obligatorio: el nombre y la marca
  se autocompletan con lo que leyó el OCR, el proveedor (`<datalist>` que
  alimenta `consultar_proveedores.php`, acepta uno nuevo escrito a mano) y el
  precio de costo son opcionales, y el **SKU se genera solo** con formato
  `PREFIJO-NNN`. El frontend sólo sugiere el SKU; quien garantiza que no se
  pisen dos productos es `crear_producto.php`, que lo regenera y reintenta
  ante colisión (salvo que el usuario lo haya escrito a mano, en cuyo caso
  devuelve 409). El SKU es **único por negocio**, así que dos comercios
  pueden tener cada uno su `PER-001`. Los proveedores también son por
  negocio (`UNIQUE (negocio_id, nombre)`).
- **Altas de usuario: dos caminos, y en ninguno se elige el rol.** El
  registro abierto con `<select>` de rol se retiró (`registrar_usuario.php`
  quedó como stub 410). Ahora: `crear_negocio.php` (negocio nuevo, quien lo
  crea queda `dueño`) o `aceptar_invitacion.php` (se suma a un negocio
  existente con el rol que fijó la invitación). Un `dueño` genera links
  desde `equipo.html`; **no se manda mail** — no hay mailer en el stack, el
  link se copia a mano. `info_invitacion.php` es público a propósito, para
  que `invitacion.html` muestre "te invitaron a X" antes de que la persona
  tenga sesión, pero expone sólo `negocio_nombre`/`email`/`rol`.
- `administrador.html` **usa datos reales** de MySQL (KPIs y tabla de
  inventario salen de `consultar_inventario.php` vía `inventario_tabla.js`);
  lo único fijo es la KPI "Estado del Servidor IA". El chatbot tampoco es
  mock: es un asistente real (Groq, inferencia gratuita con límites de uso)
  que responde con datos de la base.
- **Notificaciones por WhatsApp**: cuatro avisos (stock crítico, producto sin
  stock, vencimientos próximos, resumen diario) que salen por la
  **WhatsApp Cloud API** de Meta. **Nada manda un mensaje en el momento**:
  los disparadores sólo hacen un INSERT en la tabla `notificaciones`
  (`notificaciones.php`), y `tareas_notificaciones.php` —un script de línea
  de comandos que corre desde el Programador de tareas de Windows— la vacía
  cada unos minutos. Es lo que evita que una venta dependa de que la API de
  Meta conteste: `registrar_venta.php` encola **después del commit, fuera de
  la transacción y con un catch mudo**, así que un WhatsApp caído no puede
  hacerle rollback a una venta real ni sumarle latencia al cobro (medido: la
  venta sigue en ~30 ms). El envío en sí vive sólo en `whatsapp.php`, que es
  el primer y único PHP del proyecto que sale a internet. La deduplicación
  (`clave_dedup` + una ventana de horas) es lo que lo hace usable: sin ella,
  vender diez unidades de a una manda diez veces el mismo aviso.
  Se configura por negocio desde el panel "Preferencias del negocio", que abre
  el chip del negocio en la topbar de `administrador.html` (`menu_negocio.js` +
  `preferencias.js`, sólo `dueño`), y que además muestra
  las últimas 10 notificaciones con el error crudo de Meta — es la herramienta
  de diagnóstico de "no me llega nada", que tiene como cinco causas distintas
  y todas se ven igual desde afuera.
- **Preferencias del negocio**: los ajustes que el dueño puede cambiar sin que
  nadie toque código ni base viven todos en la tabla `configuracion` (PK
  `(negocio_id, clave)`) y se editan desde un solo lugar
  (`preferencias.js` + `consultar_preferencias.php`/`guardar_preferencias.php`,
  sólo `dueño`): teléfono de WhatsApp, avisos activados, hora del resumen,
  días de anticipación de vencimiento, **horas entre avisos repetidos** y
  **stock mínimo por defecto**. Los dos últimos eran números escritos en el
  código (el default de 24 h en la firma de `encolar_notificacion()` y el
  `DEFAULT 5` de la columna `productos.stock_minimo`), invisibles desde la
  aplicación. Los defaults viven en `CONFIG_INICIAL_NEGOCIO`
  (`configuracion.php`) y son los mismos valores de antes, así que un negocio
  que no toca nada se comporta igual. `leer_config_int()` es el lector
  compartido: cae a esa constante si falta la fila, para que el panel y el
  backend no puedan mostrar números distintos.
  **La ventana de avisos sólo aplica a los avisos disparados por una venta**
  (stock crítico / sin stock). Los diarios (vencimientos, riesgo de quiebre,
  resumen) llevan la fecha en su `clave_dedup`, así que ya son uno por día por
  construcción; pasarles una ventana de 6 h haría salir el resumen diario tres
  veces por noche. Por eso `tareas_notificaciones.php` sigue con el default de
  24 h y el único que lee la config es `registrar_venta.php`.
  El **stock mínimo por defecto** sólo afecta a los productos que se crean de
  ahí en adelante: los que ya existen conservan el suyo (se cambia por
  producto, no acá).
- **La topbar del panel: el chip ES el menú del negocio.** El chip de arriba a
  la derecha de `administrador.html` muestra el **nombre de la tienda** (y
  debajo, chiquito, quién está logueado), y al tocarlo abre un panel
  superpuesto (`menu_negocio.js`, prefijo `mng-`) con las preferencias del
  negocio adentro, más "Equipo" y "Cerrar sesión" — los dos botones que antes
  estaban sueltos en la barra. Las preferencias **ya no son un card del
  `.main-grid`**: `initPreferencias()` se monta **recién la primera vez que se
  abre el panel** (después sólo `recargar()`), así que sus dos fetch dejaron de
  pagarse en cada carga de la página. Detalles que importan si se toca:
  z-index **1000000**, que es lo único que lo deja por encima del botón del
  chatbot (999999); y `.mng-overlay[hidden]{display:none}` es **obligatorio**,
  porque el `display:flex` del overlay le gana al `hidden` nativo y sin esa
  regla el panel nace abierto y no cierra nunca (la misma trampa que ya
  documenta `chatbot_widget.js`).
- **Los paneles grandes del dashboard arrancan PLEGADOS.** Hoy son dos —
  "Inventario en Tiempo Real" y "Costos y márgenes" — y el mecanismo es
  compartido: `plegable.js` (`plegableLeer()` + `plegableConectar()`). Cada
  panel es dueño de su clave de `localStorage`
  (`stockiate_inventario_plegado`, `stockiate_costos_plegado`, mismo patrón que
  `tema.js`) y de SU CSS, porque qué hijos se esconden cambia de panel a panel;
  lo compartido es sólo el mecanismo.
  Es una opción **opt-in** (`{ plegable: true }`) que sólo pasa
  `administrador.html`: `repositor.html` usa la misma tabla de inventario en
  una pantalla donde la tabla *es* todo el contenido, y ahí plegarla dejaría
  una pantalla vacía. (`repositor.html` igual carga `plegable.js`, para que la
  dependencia no quede implícita.)
  Tres cosas que parecen de más y no lo son:
  **el fetch se hace igual aunque el panel esté plegado** — del inventario
  salen, vía `onDatos`, los KPI cards del panel, y de costos sale el resumen
  del pie: diferirlo dejaría el dashboard vacío;
  **lo que queda visible plegado es el resumen** (el subtítulo "N productos · N
  requieren atención" y la línea de capital inmovilizado / margen promedio),
  que es justamente el valor de tener el panel cerrado;
  y **el buscador y los toggles se esconden junto con la tabla**, lo que obligó
  a sacar el `style` inline que tenían las acciones del inventario y ponerles
  la clase `.invt-head-acciones` — un `style` inline le gana a cualquier
  selector sin `!important`, así que con él la regla de ocultarlos no hacía
  nada.
- **Gráficos del panel** (`graficos.js`, prefijo `grf-`): cuatro — estado del
  stock y capital por proveedor (tortas), ventas por día (línea) y top
  productos (barras). Dos cosas para saber antes de tocarlos:
  **los dos primeros no piden nada a la red**: se alimentan de los `onDatos`
  que ya emiten `inventario_tabla.js` y `costos_tabla.js`, así que el gráfico y
  la tabla de al lado no pueden mostrar números distintos (la torta de stock
  usa `estadoDeProducto()`, la MISMA función que pinta los badges de la tabla).
  Sólo los dos de ventas pegan a `consultar_ventas.php`.
  Y **el `<canvas>` no hereda del CSS**: los colores se leen de las variables
  del tema con `getComputedStyle` y hay un `MutationObserver` sobre el
  `data-tema` del `<html>` que repinta al cambiar de tema — sin él, pasar a
  claro dejaba los ejes con el gris del tema oscuro. Al repintar se llama
  `destroy()` sobre la instancia anterior: Chart.js deja listeners por gráfico
  y reconstruir sobre el mismo canvas sin destruir apila instancias.
  **Chart.js va servido desde el repo** (`vendor/chart.umd.min.js`, v4.4.7), no
  desde un CDN: el día de la defensa un wifi caído no puede dejar el panel sin
  gráficos. Lo mismo vale para jsPDF (`vendor/jspdf.umd.min.js`, v2.5.2).
- **Ventas de un producto**: el `<select>` del bloque de gráficos filtra la
  serie de ventas a un solo producto (`consultar_ventas.php` ya aceptaba
  `producto_id`; sólo faltaba de dónde elegirlo). La lista sale del **catálogo
  del inventario**, no de las ventas, y es a propósito: así se puede elegir un
  producto que no vendió NADA, que es justamente una respuesta útil. El gráfico
  de top productos no se filtra — es el ranking, y filtrarlo por un producto no
  querría decir nada.
- **Exportar a PDF** (botón del bloque de gráficos, `exportarPDF()` en
  `graficos.js`): arma un reporte con el nombre del negocio, un resumen en
  números, los cuatro gráficos y —si hay un producto elegido— el detalle día
  por día de ese producto. No copia lo que hay en pantalla: **redibuja cada
  gráfico con `GRF_TEMA_PDF`**, una paleta fija oscuro-sobre-blanco, porque
  exportar estando en modo oscuro daba ejes gris claro sobre papel blanco.
  Tres cosas medidas que no se pueden tocar sin volver a medir:
  **`compress: true`** en el constructor de jsPDF (sin él el mismo reporte de
  2 páginas pesaba **33 MB**; con él, ~280 KB);
  **las imágenes van en JPEG y no PNG** (en PNG pesan menos, pero jsPDF los
  deflaciona en JavaScript y el reporte tardaba **2060 ms contra 212 ms**, con
  la pantalla congelada);
  y **el fondo blanco se pinta DESPUÉS de construir el Chart, con
  `destination-over`** — Chart.js redimensiona el canvas al construirse y eso lo
  borra, y como el JPEG no tiene alpha, lo transparente se componía sobre negro:
  los cuatro gráficos salían con fondo negro y los ejes ilegibles.
- **`consultar_ventas.php` agrupado por `dia` devuelve la serie cronológica
  completa**, no el top 20. Antes ordenaba siempre por facturación y cortaba en
  20 filas, lo cual para producto/marca es exactamente lo que se quiere pero
  para una serie temporal no: preguntar por el último mes devolvía "los 20 días
  que más facturaron", sin orden y con días del medio faltando sin que nada lo
  dijera. Ahora `dia` ordena por fecha ascendente con límite 366; el resto
  quedó igual. Lo usan el gráfico de ventas y la herramienta `consultar_ventas`
  del chatbot.
- **Dónde vive la regla de "stock crítico"**: en `alertas.php`
  (`es_critico()`), que comparten `consultar_inventario.php` y el detector de
  notificaciones. Antes cada uno tenía la suya; que el KPI del panel y el
  WhatsApp usen la misma función es lo que evita que se contradigan.
  (`inventario_tabla.js` **sigue** pintando sus badges con otro criterio, un
  `< 5` fijo: es una diferencia preexistente, del lado del cliente.)
- **Reposición al proveedor** (`consultar_reposicion.php` +
  `reposicion_tabla.js`, prefijo `rep-`, sólo `dueño`): qué pedir y cuánto. Es
  el espejo del riesgo de quiebre — aquél avisa que algo se acaba, éste dice
  cuántas unidades comprar y cuánto sale el pedido.
  La cuenta es `punto_pedido = venta_diaria * dias_entrega * (1 +
  factor_seguridad)` y `sugerido = venta_diaria * dias_objetivo -
  stock_actual`, con los tres parámetros en `configuracion`
  (`reposicion_dias_entrega` 7, `reposicion_dias_objetivo` 30,
  `reposicion_factor_seguridad` 1.5) y editables desde Preferencias. **El
  factor es el único valor decimal de la tabla**, y por eso existe
  `leer_config_float()`; en el panel va por `guardarCampo` y no por
  `guardarNumero`, que parsea con `parseInt` y convertiría 1.5 en 1 sin avisar.
  Dos exclusiones que NO son un olvido y están puestas a propósito:
  **un producto sin ventas en 90 días no entra en la lista** (no se repone lo
  que no se vende: eso se liquida — si algún día hay un recomendador de
  ofertas y un producto aparece en las dos listas, hay un bug de criterio),
  y **uno con menos de 21 días desde su primera carga tampoco** (con dos
  semanas de historia, "vendió 3 en 4 días" se proyecta a 67 por mes y alguien
  va a pedir eso). Los excluidos se cuentan en `diagnostico` y el panel los
  muestra, para poder contestar "¿por qué no aparece tal producto?" sin entrar
  a la base.
  **El texto para WhatsApp sale agrupado por proveedor**, no como una lista
  corrida: un pedido se le manda a UN proveedor, y una lista mezclada de seis
  hay que editarla a mano antes de mandarla — justo el trabajo que el botón
  vino a evitar. El `navigator.clipboard` tiene respaldo en un `<textarea>`
  porque no existe fuera de contexto seguro, y el sistema se usa por LAN.
- **Métricas del modelo** (`consultar_metricas_ia.php` + `metricas_ia.js`,
  prefijo `mia-`, sólo `dueño`): el panel que lee `correcciones_ia`, la tabla
  que la Red de Seguridad viene llenando desde que arrancó el piloto y que
  hasta ahora nadie leía. Cuatro cosas para saber antes de tocarlo:
  **son CUATRO categorías, no dos** — acierto, error de producto, error de
  cantidad y **no reconocido** (`producto_detectado_id` NULL: el modelo vio un
  envase pero el OCR no leyó la etiqueta). La última no es un error del
  clasificador y meterla en la bolsa de los errores hunde el acierto por algo
  que el sistema maneja bien; los dos errores del medio se separan porque uno
  es el OCR y el otro es el detector contando cajas, y se arreglan distinto.
  **Un registro puede ser los dos errores a la vez**, así que los conteos de
  error no suman el total.
  **Todo porcentaje viaja con su `n`**, y abajo de 30 registros la respuesta
  trae `muestra_insuficiente: true` y la pantalla muestra conteos en vez de un
  porcentaje grande: un 100% sobre 3 casos no es un 100%, y el número grande es
  justo lo que alguien recorta para una presentación.
  **Los gráficos son SVG a mano, no Chart.js**, y a propósito: un SVG inline
  hereda el CSS y se adapta solo al tema: nada del `getComputedStyle` +
  `MutationObserver` que necesita el `<canvas>` de `graficos.js`.
  Los colores de las categorías (`MIA_COLORES`) **no son** los de estado del
  proyecto: acá "error de producto" es una categoría de un gráfico, no una
  alarma, y pintarla de rojo hace leer el panel como si algo estuviera roto.
- **Chatbot IA**: widget flotante compartido (`chatbot_widget.js`), incluido
  igual en `repositor.html`, `cajero.html` y `administrador.html`, inyectado
  dinámicamente por `auth.js`. El frontend le pega a `POST /chatbot`
  (`main.py`), que usa Groq (API compatible con el formato de tool-calling
  de OpenAI) con **tool-use sobre un set fijo de herramientas** (no
  texto-a-SQL libre). Para resolver cada herramienta le pega a un endpoint
  PHP de solo lectura (`consultar_stock.php`, `consultar_ventas.php`,
  `consultar_vencimientos.php`, `consultar_productos.php`,
  `consultar_rentabilidad.php`) — el servicio Python sigue sin tocar MySQL
  directamente. Cada intercambio se loguea en `chatbot_conversaciones` vía
  `registrar_chatbot_log.php` (best-effort).
- **El chatbot está recortado por sección, no sólo por rol.** `auth.js` le
  pasa al widget un `data-contexto` según la página
  (`repositor` / `cajero` / `admin`) y éste lo manda en el body.
  `chatbot_ia.py` cruza `CONTEXT_TOOLS` (sección) con `ROLE_TOOLS` (rol de
  la sesión) y se queda con la **intersección**: un `dueño` parado en
  `repositor.html` pregunta por stock y vencimientos, pero para facturación
  el asistente lo manda a Caja. El contexto viene del cliente, así que
  **sólo puede recortar** — el rol de sesión es el techo y PHP el candado.
  El mismo set filtra la **ejecución**, no sólo la oferta: `gpt-oss-20b` a
  veces inventa un `tool_call` que no se le ofreció (se lo vio llamar a
  `consultar_ventas` desde el contexto repositor), así que `_ejecutar_tool()`
  lo rechaza antes de pegarle a PHP. Filtrando sólo la oferta, el recorte era
  una sugerencia.
- **Costos, márgenes y proveedores** los responde `consultar_rentabilidad.php`
  (herramienta `consultar_rentabilidad`), **sólo para `dueño`**
  (`exigir_sesion(['dueño'])`): expone la estructura de costos del negocio.
  Devuelve por producto costo/venta, margen unitario y en %, unidades
  vendidas y ganancia estimada en el rango, stock y capital inmovilizado;
  más un resumen por proveedor y totales. El prompt le suma pautas para que
  cruce margen con rotación y no invente costos que no están cargados.
- **Cómo el chatbot sabe quién le habla**: el navegador manda su cookie de
  sesión a `/chatbot`, FastAPI la **reenvía tal cual** a los endpoints PHP,
  y PHP resuelve usuario/rol/negocio. Python sólo copia un header opaco, así
  que sigue sin tocar MySQL. `rol` y `usuario_id` ya **no** viajan en el body
  (antes sí, y cualquiera podía mandar `rol: "dueño"` para desbloquear todas
  las herramientas). El recorte de `chatbot_ia.py` (`ROLE_TOOLS` ∩
  `CONTEXT_TOOLS`) hace dos cosas: no ofrecerle al modelo herramientas que no
  corresponden, y rechazar el `tool_call` si igual las pide. Pero **el
  control de acceso real está en cada endpoint PHP**, que valida el rol
  contra `$_SESSION` por su cuenta.
- La configuración está partida entre capas: el lado Python lee `.env`
  (`ROBOFLOW_API_KEY`, `GROQ_API_KEY`, etc.) vía `python-dotenv`; el
  lado PHP tiene las credenciales de MySQL hardcodeadas en `conexion.php`.
  No comparten configuración.

Ver [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) para el detalle completo:
tabla de responsabilidades por archivo, los flujos paso a paso con payloads,
el esquema de base de datos tabla por tabla, y las convenciones de nombres.

## Cosas para tener en cuenta

- **Rotación de API keys de Groq.** `chatbot_ia.py` (`_claves()`) arma una
  lista con `GROQ_API_KEY` y, si están, `GROQ_API_KEY_2` .. `GROQ_API_KEY_9`.
  Cada llamada al modelo pasa por `_completar()`, que si la key activa
  responde 429 (sin cupo) o la rechazan, pasa sola a la siguiente. El índice
  es **sticky**: una vez que rota, las próximas preguntas arrancan por la
  nueva, para no pagar un 429 al pedo en cada mensaje; cuando la última se
  agota da la vuelta y reintenta la primera, que para entonces normalmente
  ya se le reseteó la ventana. Sólo rota por cupo o key rechazada — un
  timeout o un 500 de Groq se propagan, porque cambiar de key no arregla
  nada. Si se agotan todas, `ChatbotIALimitError` → **429** en `/chatbot`
  (no 502: la key está bien y el servicio anda, lo que se acabó es la
  cuota, y un 502 manda a revisar el servidor que es justo lo que no hay
  que tocar). Ojo con el uso: los términos de Groq **no permiten abrir
  cuentas extra para esquivar los límites del plan gratuito**.
- **El backup NO es opcional, y la restauración hay que probarla.**
  `scripts/backup_stockiate.sh` (cron diario) + `scripts/restaurar_backup.sh`,
  configurados por `scripts/backup.conf` (gitignoreado, tiene la contraseña).
  El procedimiento completo está en el [README](README.md). Cuatro cosas que
  parecen paranoia y no lo son:
  **la contraseña nunca va en la línea de comandos** (`mysqldump -pclave` se ve
  en `ps aux`): se escribe un temporal 600 y se pasa con
  `--defaults-extra-file`;
  **se chequea que el dump termine con `Dump completed`**, que es lo único que
  distingue un dump completo de uno cortado a la mitad — un truncado
  descomprime sin error y pesa parecido;
  **la retención borra por POSICIÓN y no por antigüedad en días**, porque si el
  cron estuvo caído una semana, "borrar lo de más de 14 días" vacía la carpeta
  entera;
  y **el archivo se llama `.parcial` hasta pasar todas las verificaciones**, así
  que una corrida muerta a mitad de camino no deja algo que parezca un backup
  bueno.
  `restaurar_backup.sh` **se niega a escribir sobre la base de producción** y
  pide escribir el nombre de la base destino a mano: un "s/n" con un enter de
  más no puede ser suficiente para borrar una base.
- **Nunca commitear el `.env`**: contiene las API keys de Roboflow y Groq.
  El repo tiene remoto público en GitHub. Está cubierto por `.gitignore`
  junto con `venv/`, `.venv/` y `__pycache__/`; la plantilla sin valores,
  que sí va a git, es `.env.example`.
- **Login con sesión de servidor** (`registro.html`, `login.html`,
  `invitacion.html` + `crear_negocio.php`/`iniciar_sesion.php`/
  `aceptar_invitacion.php`), contraseñas hasheadas
  (`password_hash`/`password_verify`) y `usuarios.rol` en
  `{repositor, cajero, dueño}` (`dueño` se muestra como "Administrador" en
  la UI y significa administrador **de su negocio**). La sesión es una
  cookie `STOCKIATE_SID` (`sesion.php`); `auth.js` mantiene `localStorage`
  sólo como caché de UX. El guard sincrónico `exigirSesion()` del `<head>`
  sigue existiendo para que no parpadee el HTML, pero la contraparte real
  son `confirmarSesion()` (contrasta contra `sesion_actual.php` al cargar)
  y `fetchApi()` (manda la cookie y expulsa ante 401). Falsear el
  `localStorage` sólo consigue ver un cascarón vacío ~200 ms: todos los
  datos los sirve PHP contra `$_SESSION`.
- **`usuarios.email` es UNIQUE global**, no por negocio: una persona = una
  cuenta = un negocio. Si alguien trabaja en dos comercios necesita dos
  emails. Es una decisión consciente (`iniciar_sesion.php` busca sólo por
  email; hacerlo por negocio obligaría a una pantalla de "elegí tu
  negocio"). La solución completa sería una tabla `membresias` N:M — es un
  rediseño del login, fuera de alcance.
- **`sesion_demo.php` sigue siendo un backdoor** (`MODO_PRUEBA = true`):
  cualquiera que le pegue se lleva una sesión sin contraseña. Sus 3 cuentas
  caen en un negocio aislado, así que no ven datos de comercios reales.
  Antes de mostrar el sistema fuera de tu máquina, poné `MODO_PRUEBA` en
  `false`.
- **El nombre `'Negocio Demo'` está reservado.** `sesion_demo.php`
  (`negocio_demo_id()`) resuelve el negocio del backdoor **buscando ese
  nombre exacto**, y lo crea si no existe. Por eso
  `migracion_multitenant.sql` adopta los datos reales en un negocio con otro
  nombre (editable en el paso A2) y crea `'Negocio Demo'` aparte en A4b,
  moviendo ahí las cuentas `%@stockiate.test` preexistentes. Si el negocio
  de los datos reales se llamara `'Negocio Demo'`, el backdoor daría acceso
  directo a todo el inventario real.
- **"No detecta nada": lo PRIMERO es chequear que el servicio Python esté
  arriba.** Con `STOCKIATE_IA_BASE = ""` (ver `config.js`), el navegador pide
  `/procesar-imagen` a Apache, que lo proxea al 8000. Si uvicorn no está
  corriendo, Apache devuelve un **503 en HTML**, `resp.json()` explota y la
  pantalla muestra el banner de offline o una tarjeta vacía — el mismo
  síntoma que "el modelo no ve nada", con causas totalmente distintas.
  Chequeo de un comando: `curl -s -o /dev/null -w "%{http_code}"
  http://localhost:8000/` — si no da 200, el problema no es la IA.
- **Cuidado con los dos entornos de Python: `venv/` y `.venv/`.** Conviven en
  el repo y es fácil arrancar uvicorn con el que no tiene las dependencias.
  Pasó: `venv/` se creó antes de que `groq` entrara en `requirements.txt`,
  uvicorn moría con `ModuleNotFoundError: No module named 'groq'` al importar
  `main.py`, nunca bindeaba el puerto, y el síntoma visible era "la IA no
  detecta nada" — con la detección de imagen intacta.
  Dos cosas lo cubren ahora: `chatbot_ia.py` importa `groq` **dentro de
  `_cliente_para()`**, no a nivel de módulo (si falta el paquete, sólo
  `/chatbot` responde 503 y el resto del backend sigue de pie, que es lo que
  esta guía ya prometía para `GROQ_API_KEY`); y `.claude/launch.json` apunta
  explícitamente al `.venv`. Si levantás a mano, usá siempre el mismo:
  `.venv/Scripts/python.exe -m uvicorn main:app --reload`.
  Y verificá que quede **un solo** uvicorn: si quedan dos, el segundo no
  bindea y te confunde el diagnóstico.
- **El recorte manual (`recorte_imagen.js`) va ANTES de subir, y el envase
  tiene que quedar entero adentro.** Después de elegir la foto se abre un
  recuadro que la persona mueve y estira con el dedo para sacar el fondo.
  Medido sobre `images/hawas.png`:

  | lo que se manda | detecciones | OCR |
  |---|---|---|
  | foto entera | 1 | `حسن HAWAS For Him` |
  | recorte 8–92 % (el default) | 1 | `HAWAS For Him BLACK` |
  | recorte pegado a la etiqueta | **0** | — |

  O sea: encuadrar **mejora** el OCR, pero **cortar por dentro del producto lo
  rompe** — el detector busca un objeto completo y medio frasco sin bordes no
  lo es. Por eso el recuadro arranca holgado (8–92 %) y el texto de ayuda lo
  aclara.
  Recortar obliga a re-encodear, y ahí vale lo mismo que para
  `image_utils.js`: **`RCI_CALIDAD_JPEG` está en 0.97 y el piso medido es
  0.95** (a 0.90 el recorte se ve bien y da cero detecciones). El recorte sale
  ya escalado a 1280px, así que `redimensionarImagen()` lo deja pasar y hay
  **un solo re-encode** en todo el pipeline. "Usar toda la foto" devuelve el
  archivo original sin tocar.
- **Comprimir la foto de más rompe la DETECCIÓN, no sólo el OCR.** Es la
  primera causa a descartar cuando alguien dice "la IA no detecta nada con
  fotos que antes andaban". `image_utils.js` re-encodeaba SIEMPRE a JPEG
  calidad 0.8 antes de subir, incluso cuando la foto ya entraba en los 1280px
  y no había nada que achicar. Medido sobre `images/hawas.png` (340x491):
  PNG original y JPEG q=1.00 detectan; **q=0.92 y q=0.80 dan cero**. Como el
  corte cae entre 0.92 y 1.00, cada foto se rompía o no según su contenido, y
  el síntoma era intermitente. Ahora `redimensionarImagen()` tiene dos reglas:
  si la foto ya entra en el límite se sube **tal cual** (sin pasar por
  canvas), y si el re-encode no salió más chico que el original se descarta.
  El ahorro de payload sigue donde importa: una foto de celular de 3000x4000
  baja de 2,8 MB a 400 KB (86 %) y detecta igual. **Si tocás
  `IMGU_CALIDAD_JPEG` o `IMGU_LADO_MAXIMO`, re-medí la detección, no sólo el
  peso** — y subí el `?v=` de `image_utils.js` en las dos páginas.
- **Qué modelo corre el workflow NO se decide en este repo**: se elige en el
  editor de Roboflow y puede cambiar sin tocar una línea de código. Con
  `use_cache=False` en `roboflow_workflow.py`, el cambio aplica en la llamada
  siguiente. Para saber cuál está corriendo, o mirás la definición viva
  (`GET https://api.roboflow.com/{workspace}/workflows/{workflow_id}`,
  campo `specification.steps[0].model_id`), o te fijás en el `clase_yolo` que
  llega al frontend: si son las 80 clases de COCO (`remote`, `cell phone`,
  `person`…) es un YOLO de fábrica; si son `shampoo`/`makeup`/`perfume`/`Soin`
  es el modelo del proyecto (`products-vweue-1d62m-1-yolov8n-t1`).
  **Verificado el 09/09/2026: hoy NO está corriendo el modelo del proyecto.**
  La definición viva dice `model_id: "yolo26n-640"`, un YOLO genérico de COCO.
  Alguien lo cambió en el editor de Roboflow, y todo lo que dice esta guía
  sobre tasas de detección se midió con el otro modelo. Antes de citar
  cualquier número de detección, chequear el `model_id`.
  **La diferencia entre uno y otro es enorme** — medido sobre `images/`:

  | foto | COCO (`yolov8n-640`) | modelo del proyecto |
  |---|---|---|
  | `local.jpeg` | 1 (`remote`) | **5** (`shampoo`, .79–.95) |
  | `5rexonasiguales` | **0** | **5** (los cinco Dove) |
  | `sauvage` | **0** | **1** (`perfume`, .92) |
  | `hawas` | 1 (`cell phone`) | 1 (`Soin`, .42) |
  | `rasta` | 0 | 0 |

  Con COCO un envase se detecta sólo si se parece a alguna de sus clases, así
  que la tasa depende de la foto y no del producto: de ahí la sensación de que
  "anda a veces". El modelo del proyecto además acierta los conteos (cinco
  envases = cinco detecciones) y con confianzas mucho más altas. **Si alguien
  reporta "dejó de detectar", lo primero es chequear si cambió el model_id.**
  Para esta arquitectura la cantidad de clases del modelo es irrelevante: el
  nombre del producto sale SIEMPRE del OCR, y al detector sólo se le pide la
  caja alrededor del envase para que el `dynamic_crop` recorte y el OCR lea.
- **El punto ciego del modelo propio: lo que no se parece a un envase de
  perfumería.** Está entrenado con 4 clases (`shampoo`/`makeup`/`perfume`/
  `Soin`) sobre fotos de frascos y pomos, así que una caja de cartón o un
  alfajor caen fuera de su distribución y devuelve cero — no es un umbral mal
  puesto, el modelo directamente no aprendió esa forma. Fue el motivo por el
  que en algún momento se cambió el workflow a COCO, que al menos tiene
  `book`, `cake` o `sandwich` y pesca algo de rebote.
  **Cambiar a COCO no es la solución**: cuesta mucho más de lo que arregla
  (ver la tabla de arriba — 0 de 5 Dove, 0 en `sauvage`). La solución es
  entrenar de nuevo con dos cambios: (1) **colapsar las 4 clases en una sola**
  (en Roboflow es "Modify Classes" al crear la versión, no hay que
  re-etiquetar las 1218 imágenes) porque la clase se descarta igual y así el
  modelo dedica toda su capacidad a "acá hay un artículo"; y (2) **agregar
  fotos de las formas que faltan** (cajas, alfajores, lo que se venda), que es
  lo único que realmente cubre el punto ciego — colapsar clases por sí solo no
  le enseña una forma que nunca vio.
  La detección en sí es **determinística**: la misma foto da la misma clase y
  la misma confianza (medido, 5 corridas de `hawas.png`: siempre
  `cell phone` 0.44). Si "antes andaba y ahora no", no es azar del modelo —
  buscá un cambio en el workflow, en el pipeline de imagen del cliente
  (ver `image_utils.js`) o en la foto.
- **El `confidence` que se manda por código NO llega al modelo.** Medido el
  09/09/2026 con `test_threshold.py` sobre las 5 fotos de `images/`: en las 10
  combinaciones foto/variante la cantidad de detecciones fue **idéntica** con
  `confidence` en 0.5, 0.4, 0.3 y 0.2, y **16 de las 32 detecciones volvieron
  con una confianza MENOR al umbral pedido** (una de 0.267 con el umbral en
  0.5). Si el filtro se aplicara, eso sería imposible.
  La causa está en la definición del workflow: el step del modelo no tiene su
  campo `confidence` enlazado a ningún input, y el único input del workflow es
  `image`. O sea que `ejecutar_workflow_stock(..., confidence=X)` y
  `CONFIDENCE_THRESHOLD` en `main.py` **aparentan** configurar algo y no lo
  hacen; el umbral hay que cambiarlo a mano en el editor de Roboflow y
  publicar. El experimento completo, con tabla y gráfico listos para el
  informe, está en [docs/umbral_confianza.md](docs/umbral_confianza.md); se
  rehace con `python test_threshold.py <carpeta>` y después
  `python analizar_threshold.py`.
- **`resultados_threshold.csv` no tiene verdad de referencia.** Guarda qué
  detectó el modelo y con qué confianza, pero no qué producto era realmente
  cada foto, así que de ahí NO salen "aciertos" ni "falsos positivos". Esos
  números salen de `correcciones_ia` (el panel de métricas del modelo), que es
  la única fuente del proyecto que tiene el dato corregido por una persona al
  lado del detectado.
- **El modelo tiene una tasa alta de "no detecta nada", y eso NO es un bug
  del código.** Medido sobre las 5 fotos de `images/`: `local.jpeg` y
  `hawas.png` detectan; `5rexonasiguales.png`, `sauvage.png` y `rasta.png`
  devuelven **cero predicciones desde Roboflow**, antes de que el código toque
  nada. Probado con `confidence` en `None`/0.5/0.4/0.2/0.05, como PNG
  original, como JPEG RGB y al doble de tamaño: 0 detecciones en los 15 casos.
  El patrón visible es el contraste — el frasco oscuro de `hawas.png` sale, los
  aerosoles blancos sobre fondo blanco de `5rexonasiguales.png` no.
  **Cómo se distingue de un bug**: si `predictions` viene `[]` y
  `image: {width: null, height: null}`, el modelo no vio nada; si el problema
  fuera de serialización, fallarían TODAS las fotos, no algunas.
  Cuando pasa, la Red de Seguridad muestra el aviso
  `.aviso-sin-detecciones` ("La IA no reconoció ningún envase") arriba de la
  tarjeta manual: antes salía una tarjeta vacía idéntica a la de "Cargar
  manualmente" y parecía que la app había fallado. Para barrer una carpeta de
  fotos y ver el patrón, `test_threshold.py`.
- **El modelo de YOLO es genérico: sus clases son ruido, no señal.** No es
  un detector de perfumería, es COCO. Medido contra las fotos de `images/`:
  `local.jpeg` (un gel) sale como `'remote'` y `hawas.png` (un perfume) como
  `'cell phone'`. Por eso aparecían `"person"` y `"markdown"` sobre productos
  reales. **No filtres por clase**: hubo un intento de denylist
  (`CLASES_NO_PRODUCTO`) y tiraba perfumes con la marca leída perfecta, con
  el síntoma "la IA no detecta nada". Como un frasco alto puede caer en
  `'person'` igual que en `'remote'`, tampoco hay una sublista segura. Las
  clases crudas se loguean en `/procesar-imagen` y no se usan para nada más.
- **La clase cruda de YOLO nunca se usa como nombre de producto.** Antes
  `main.py` hacía `clase = marca if marca else clase_generica`: cuando el OCR
  no leía la etiqueta viajaba la clase del modelo y el frontend la prellenaba
  en el alta, así que una foto con una mano en cuadro podía dar de alta un
  producto llamado "person". Ahora `clase` es siempre el texto del OCR, y si
  el OCR no leyó nada la detección viaja con `identificado: false` y `clase`
  vacío: la tarjeta sale en rojo con el badge "Sin identificar", no prellena
  nada, y el botón **Confirmar la rechaza** hasta que una persona elija el
  producto. Ése es el candado que evita que se cargue basura — no filtrar
  detecciones, sino no dejar confirmar las que nadie identificó.
  Va en el mismo paquete `opcionesCatalogo()`: cuando no hay match con el
  catálogo antepone un `<option value="">— Elegí el producto —</option>`.
  Sin él el navegador auto-seleccionaba el **primer producto de la lista**, y
  una detección que no matcheó cargaba stock (o cobraba) el producto
  equivocado sin que nadie lo notara. Todo esto está duplicado entre
  `repositor.html` y `cajero.html`, como el resto de la Red de Seguridad.
- **WhatsApp: la app del celular NO es la API.** Es la confusión que hace
  perder la primera tarde. "WhatsApp Business" (la de Play Store, con
  catálogo y respuestas rápidas) no tiene API y no se puede automatizar. Lo
  que se automatiza es la **WhatsApp Business Platform / Cloud API**, que se
  administra en `developers.facebook.com`. Peor: **un número registrado en la
  app queda bloqueado para la Cloud API** hasta que lo borres de la app (y
  ahí perdés el historial de chats). Por eso el proyecto usa el **número de
  prueba gratuito** que da Meta, que manda gratis a **hasta 5 destinatarios
  verificados**. Ojo con esos 5: **no se pueden borrar ni cambiar después**,
  así que elegí bien antes de cargarlos.
- **El token temporal de Meta dura 24 h.** El que muestra la pantalla de
  "Configuración de la API" se vence solo, y el síntoma es que las
  notificaciones dejan de salir en silencio con un `code 190` guardado en la
  columna `error`. Antes de cualquier demo hay que sacar el permanente:
  `business.facebook.com` -> Configuración del negocio -> Usuarios del
  sistema -> generar token con `whatsapp_business_messaging` +
  `whatsapp_business_management` y caducidad **Nunca**.
- **El teléfono argentino: va el 9, no va el 15.** Las dos cosas hacen que
  Meta acepte el mensaje con un 200 y **nunca llegue**, que es el peor modo
  de falla porque no hay error que mirar. `normalizar_telefono()` en
  `whatsapp.php` se encarga (`011 15-5555-4444`, `+54 9 11 5555-4444` y
  `1155554444` dan todos `5491155554444`), y el panel muestra el número ya
  normalizado al lado del input justamente para que se vea si faltó un
  dígito.
- **Las plantillas hay que crearlas a mano en Meta y esperar la aprobación.**
  Fuera de las 24 h posteriores a que el usuario te escriba -que es siempre
  nuestro caso, porque acá el que inicia la conversación es el sistema- sólo
  se pueden mandar plantillas aprobadas. Son tres: `alerta_stock`,
  `alerta_vencimientos` y `resumen_diario`, todas categoría **Utilidad** e
  idioma español. Los nombres tienen que coincidir **exactamente** con
  `PLANTILLA_POR_TIPO` en `notificaciones.php`, o falla con "Template name
  does not exist". Reglas de Meta que hacen que rechacen una plantilla:
  ninguna variable al principio ni al final del cuerpo, y nunca dos variables
  pegadas.
- **Los avisos se encolan aunque falten las credenciales, pero no se
  intentan.** `whatsapp_habilitado()` mira sólo si el NEGOCIO activó los
  avisos y tiene teléfono; que el servidor tenga token o no es otra pregunta,
  y la contesta `tareas_notificaciones.php`, que **no vacía la cola** si
  falta el token. Es deliberado en las dos direcciones: si no encoláramos, se
  perderían los avisos de las horas anteriores a que alguien complete el
  `.env`; y si intentáramos igual, cada corrida gastaría uno de los 3
  intentos que tiene cada notificación y a la tercera quedarían `fallida`
  para siempre, esperando un token que estaba por llegar.
- **Programar la tarea en Windows** (equivalente del cron), cada 5 minutos:
  ```
  schtasks /create /tn "stockIAte notificaciones" /sc minute /mo 5 /tr "C:\xampp\php\php.exe C:\xampp\htdocs\stockiate\tesis_enzo\tareas_notificaciones.php"
  ```
  Para probar a mano, corré ese mismo `php tareas_notificaciones.php`: el
  script loguea a stdout qué encoló y qué mandó. Es el ÚNICO código que
  recorre todos los negocios sin filtrar por sesión, y por eso tiene una
  guarda `php_sapi_name() !== 'cli'` que lo hace inaccesible por HTTP.
- **El `.htaccess` ahora también bloquea `.env` y `.sql`.** Apache sólo trae
  de fábrica la regla para `.ht*`: hasta que se agregó el bloque,
  `curl http://localhost/stockiate/tesis_enzo/.env` devolvía las API keys de
  Roboflow y Groq en texto plano, y con el túnel de ngrok levantado eso era
  público en internet. Verificación: ese `curl` tiene que dar **403**.
  `env.php` es el lector de `.env` del lado PHP (Python usa python-dotenv);
  se hizo para no inventar un segundo archivo de secretos sólo para el token
  de WhatsApp.
- **Para diagnosticar "no me llega ningún WhatsApp"**, en este orden:
  1. `php probar_whatsapp.php <tu numero>` — manda `hello_world`. Si esto no
     llega, el problema es la config de Meta y no hay nada que buscar en el
     código de la app.
  2. La tabla del panel de administrador: la columna `error` tiene el mensaje
     crudo de Meta, que casi siempre dice exactamente qué falta.
  3. `SELECT * FROM notificaciones ORDER BY id DESC` — si no hay filas, el
     problema es el encolado (negocio desactivado, teléfono inválido), no el
     envío.
- **Caché del navegador: hay un `.htaccess`** que manda
  `Cache-Control: no-cache, must-revalidate` para `.html`/`.js`/`.css` y
  `no-store` para los `.php`. Sin eso Apache sólo mandaba `Last-Modified` y
  el navegador aplicaba caché heurística, quedándose con un `auth.js` viejo
  mezclado con un HTML nuevo. El síntoma es engañoso: una función que
  todavía no existía tira `ReferenceError`, cae en el `catch` del submit y
  la pantalla dice "No se pudo conectar con el servidor" con el servidor
  andando perfecto. Los `<script>` además llevan `?v=N`; si tocás `auth.js`
  o `config.js`, **subí ese número**.
- **`mensajeDeError(err)` en `auth.js`**: los `catch` de las pantallas ya no
  asumen que toda excepción es de red. Sólo un `TypeError` (que es lo único
  que tira `fetch` cuando no llega al servidor) muestra "No se pudo
  conectar"; el resto avisa que es un error de la página y loguea la
  excepción real en la consola. Usalo en cualquier `catch` nuevo.
- **CORS ya no es `*`**: no puede serlo, porque el navegador rechaza el
  comodín junto con cookies. `sesion.php` (`ORIGENES_PERMITIDOS`) y
  `main.py` tienen cada uno una allowlist explícita que hay que mantener
  sincronizada si cambia el dominio de ngrok. Los fetch del frontend a PHP
  usan rutas relativas, así que en la práctica son mismo origen y ni pasan
  por CORS.
- **Importá el SQL en UTF-8.** `mysql -u root < schema.sql` desde la consola
  de Windows usa el codepage de la consola y guarda el ENUM como
  `'due├▒o'`; después todo INSERT con `'dueño'` no matchea y MariaDB (modo
  no estricto) guarda `''` **en silencio**, dejándote usuarios sin rol sin
  ningún error visible. Usá siempre
  `mysql -u root --default-character-set=utf8mb4 < schema.sql` (phpMyAdmin
  ya importa bien). Para verificar: `SHOW CREATE TABLE usuarios\G` tiene que
  decir `'dueño'`.
