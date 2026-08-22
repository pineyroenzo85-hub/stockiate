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
   responde 503.
3. **Frontend**: sin paso de build. Abrir las páginas a través de Apache,
   nunca con `file://`, ej. `http://localhost/stockiate/tesis_enzo/landing_page.html`.

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

- **El servicio Python nunca toca MySQL.** Solo hace de puente hacia
  Roboflow; toda la persistencia pasa por los endpoints PHP, a los que el
  frontend les pega directamente.
- **Offline-first**: si Roboflow no responde, `/procesar-imagen` devuelve
  `modo_offline: true` y el frontend cae a carga manual sin romper el flujo.
- **`registrar_venta.php`** usa `SELECT ... FOR UPDATE` para lockear la fila
  del producto y evitar condiciones de carrera, valida stock disponible, y
  usa siempre `productos.precio_venta` del servidor (nunca un precio que
  mande el cliente).
- Los flujos de repositor y cajero implementan la pantalla de validación
  ("Red de Seguridad") **cada uno por su cuenta** — no es un componente
  compartido, hay lógica duplicada entre `repositor.html` y `cajero.html`.
- `administrador.html` sigue siendo mock en su dashboard (KPIs y tabla de
  inventario usan `localStorage`, no MySQL), **pero el chatbot ya no lo
  es**: fue reemplazado por un asistente real (Groq, inferencia gratuita
  con límites de uso) que responde con datos reales de la base.
- **Chatbot IA**: widget flotante compartido (`chatbot_widget.js`), incluido
  igual en `repositor.html`, `cajero.html` y `administrador.html`, inyectado
  dinámicamente por `auth.js` con el `rol`/`usuario_id` de la sesión real
  (login) en vez de un `data-rol` fijo por página. El frontend le pega a
  `POST http://localhost:8000/chatbot` (`main.py`), que usa Groq (API
  compatible con el formato de tool-calling de OpenAI) con **tool-use sobre
  un set fijo de herramientas** (no texto-a-SQL libre) — `chatbot_ia.py`
  restringe qué herramientas puede usar cada rol
  (`repositor`/`cajero`/`dueño`) y, para resolver cada una, le pega a un
  endpoint PHP de solo lectura nuevo (`consultar_stock.php`,
  `consultar_ventas.php`, `consultar_vencimientos.php`,
  `consultar_productos.php`) — el servicio Python sigue sin tocar MySQL
  directamente. Cada intercambio se loguea en `chatbot_conversaciones` vía
  `registrar_chatbot_log.php` (best-effort).
- La configuración está partida entre capas: el lado Python lee `.env`
  (`ROBOFLOW_API_KEY`, `GROQ_API_KEY`, etc.) vía `python-dotenv`; el
  lado PHP tiene las credenciales de MySQL hardcodeadas en `conexion.php`.
  No comparten configuración.

Ver [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) para el detalle completo:
tabla de responsabilidades por archivo, los flujos paso a paso con payloads,
el esquema de base de datos tabla por tabla, y las convenciones de nombres.

## Cosas para tener en cuenta

- **Nunca commitear el `.env`**: contiene las API keys de Roboflow y Groq.
  El repo tiene remoto público en GitHub. Está cubierto por `.gitignore`
  junto con `venv/`, `.venv/` y `__pycache__/`; la plantilla sin valores,
  que sí va a git, es `.env.example`.
- `index.html` + `script.js` es un prototipo viejo del flujo repositor,
  superado por `repositor.html`. Su URL a `guardar_stock.php` también le
  falta `/tesis_enzo` y da 404 — no editar este par pensando que es la
  versión vigente.
- `stockiate-panel-admin.html` está vacío (0 bytes), sin usar.
- **Hay registro y login** (`registro.html`, `login.html` +
  `registrar_usuario.php`/`iniciar_sesion.php`), con contraseñas hasheadas
  (`password_hash`/`password_verify`) y `usuarios.rol` en
  `{repositor, cajero, dueño}` (`dueño` se muestra como "Administrador" en
  la UI). Pero **sigue sin haber sesión de servidor**: todo vive en
  `localStorage` (`auth.js`, clave `stockiate_usuario`), y cada página de
  rol (`repositor.html`, `cajero.html`, `administrador.html`,
  `landing_page.html`) exige esa sesión y filtra por rol del lado
  frontend (`exigirSesion()`) — no hay cookies, tokens ni
  `session_start()`. `usuarioId = 1` ya no está hardcodeado: sale de la
  sesión real. Esto también limita al chatbot: `/chatbot` sigue recibiendo
  `rol` y `usuario_id` como campos del body (ahora poblados desde la
  sesión del frontend en vez de hardcodeados) y confía en ellos para
  decidir qué herramientas habilitar — sin sesión validada del lado
  servidor, cualquiera que le pegue directo al endpoint puede mandar
  `rol: "dueño"` y saltarse la restricción pensada para la UI. Ver
  [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), sección "Flujo login /
  registro", para el detalle.
- `detector.py` es un script de debug suelto (no lo importa `main.py`) que
  tiene una API key de Roboflow hardcodeada en el código, duplicando el uso
  correcto vía `.env` que sí hace `roboflow_workflow.py`.
- CORS está completamente abierto (`*`) tanto en FastAPI como en los
  endpoints PHP — pensado para desarrollo local, no para producción.
