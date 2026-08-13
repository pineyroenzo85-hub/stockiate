---
name: run
description: Levanta el stack local de stockIAte (XAMPP, servicio FastAPI de IA en el puerto 8000, y abre la página del rol correspondiente por Apache). Usar cuando el usuario pida correr, iniciar o probar la app de stockIAte.
---

# Levantar stockIAte en local

stockIAte necesita tres cosas corriendo a la vez: MySQL/Apache (XAMPP), el
servicio Python de IA (FastAPI, puerto 8000), y el frontend abierto a través
de Apache (nunca `file://`). Seguí estos pasos en orden.

## 1. XAMPP (Apache + MySQL)

No se puede iniciar XAMPP automáticamente desde acá. Pedile al usuario que
confirme (o abrí el panel de control de XAMPP) que:

- **Apache** está corriendo.
- **MySQL** está corriendo.
- La base `stockiate` existe y tiene el esquema de `schema.sql` importado
  (si no, importarlo con phpMyAdmin o `mysql -u root stockiate <
  schema.sql`).

Si algo de esto no está confirmado, avisale al usuario y esperá antes de
seguir — sin esto, los endpoints PHP (`guardar_stock.php`,
`registrar_venta.php`) van a fallar.

## 2. Servicio de IA (FastAPI, puerto 8000)

1. Verificar que exista un archivo `.env` en la raíz del proyecto
   (`C:\xampp\htdocs\stockiate\tesis_enzo\.env`) con `ROBOFLOW_API_KEY`
   definido. Si no existe, `main.py` va a fallar al arrancar — avisar al
   usuario en vez de intentar adivinar una key.
2. Si el entorno virtual `venv/` no tiene las dependencias instaladas,
   correr `pip install -r requirements.txt`.
3. Verificar si ya hay algo escuchando en el puerto 8000 (por ejemplo con
   `curl http://localhost:8000/` o revisando procesos). Si ya responde
   `{"status": "stockIAte backend activo"}`, no hace falta levantarlo de
   nuevo.
4. Si no está corriendo, iniciarlo en background desde la raíz del
   proyecto:
   ```
   uvicorn main:app --reload
   ```
5. Confirmar que levantó bien pegándole a `GET http://localhost:8000/` y
   esperando `{"status": "stockIAte backend activo"}`. Si no responde,
   revisar el log del proceso — la causa más común es `ROBOFLOW_API_KEY`
   faltante en `.env`.

## 3. Abrir el frontend

Siempre a través de Apache, nunca abriendo el `.html` directo desde el
disco (`file://` rompe los `fetch` a rutas absolutas `http://localhost/...`
que usan las páginas).

- Sin rol específico: abrir
  `http://localhost/stockiate/tesis_enzo/landing_page.html`.
- Si el usuario pide un rol puntual, abrir directo:
  - Repositor: `http://localhost/stockiate/tesis_enzo/repositor.html`
  - Cajero: `http://localhost/stockiate/tesis_enzo/cajero.html`
  - Administrador: `http://localhost/stockiate/tesis_enzo/administrador.html`
    (recordar que este último es un mock client-side, no usa el backend
    real — ver `docs/ARCHITECTURE.md`).

No abrir ni ofrecer `index.html` — es el prototipo viejo y superado del
flujo repositor (ver "Gaps conocidos" en `CLAUDE.md`).
