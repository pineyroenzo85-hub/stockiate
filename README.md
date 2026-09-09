# stockiate

Sistema de gestión de inventario asistido por IA para comercio minorista
(tesis). La guía completa para trabajar sobre el código está en
[CLAUDE.md](CLAUDE.md); acá va sólo lo que hace falta para levantarlo.

## Modo demostración

Sirve para mostrar el sistema lleno de datos sin depender de que el comercio
haya vendido algo ese día, y sin ensuciar los datos reales. **Son dos bases
separadas**: `stockiate` (los datos del comercio) y `stockiate_demo` (los
inventados). No se mezclan nunca.

Para entrar en modo demo:

1. En el `.env`, poner `STOCKIATE_MODO=demo`.
2. Sembrar la base (la borra y la regenera entera):

   ```
   php seed_demo.php
   ```

Para volver al modo normal: `STOCKIATE_MODO=normal` en el `.env` (o borrar la
línea). No hay que tocar nada más — la base real quedó intacta todo el tiempo.

Mientras el modo demo está activo, todas las pantallas muestran una **banda
naranja** arriba que dice "Modo demostración". Es a propósito y no se puede
cerrar: una captura de pantalla de la demo no se tiene que poder confundir con
una del piloto.

### Cuentas de la demo

Todas con la contraseña **`demo1234`**:

| Rol                      | Email                   |
|--------------------------|-------------------------|
| dueño (Administrador)    | `duenio@aroma.demo`     |
| cajero                   | `cajero@aroma.demo`     |
| repositor                | `repositor@aroma.demo`  |

Son de un negocio llamado *Perfumería Aroma (demo)*. No confundir con las
cuentas `demo.*@stockiate.test` del botón "Modo prueba" del login, que son
otra cosa (ver `sesion_demo.php`).

### Qué genera el seed

40 productos, 90 días de ventas con estacionalidad (viernes y sábados fuertes,
domingos flojos, pico a fin de mes), lotes con vencimientos, y 60 correcciones
de la IA. Es **determinístico**: la misma semilla da siempre los mismos
números, así que una captura del informe se puede rehacer igual meses después.

También se puede regenerar desde el panel: el chip del negocio ->
Preferencias -> "Regenerar datos de demo". Ese botón sólo aparece en modo demo.
