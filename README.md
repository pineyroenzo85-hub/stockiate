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

## Backup de la base

Los scripts están en `scripts/`. Perder la base sería perder el piloto y, con
él, los datos de la tesis, así que esto no es opcional.

### Puesta en marcha

```bash
cp scripts/backup.conf.example scripts/backup.conf
chmod 600 scripts/backup.conf
chmod +x scripts/backup_stockiate.sh scripts/restaurar_backup.sh
```

Después editar `scripts/backup.conf` con los datos de tu base. Está
gitignoreado porque tiene la contraseña de MySQL, y el script avisa si los
permisos quedaron más abiertos que 600.

**La contraseña nunca viaja por la línea de comandos**: el script escribe un
archivo temporal con permisos 600 y lo pasa con `--defaults-extra-file`, porque
un `mysqldump -pclave` es visible en `ps aux` para cualquier usuario del
servidor mientras el dump corre.

### Cron

Todos los días a las 3:15 de la mañana:

```
15 3 * * * /ruta/al/repo/scripts/backup_stockiate.sh >> /var/log/stockiate-backup.log 2>&1
```

En Windows (que es donde corre hoy, sobre XAMPP) el equivalente es el
Programador de tareas, igual que la tarea de notificaciones. Los scripts son
bash, así que hace falta Git Bash o WSL:

```
schtasks /create /tn "stockIAte backup" /sc daily /st 03:15 /tr "\"C:\Program Files\Git\bin\bash.exe\" C:/xampp/htdocs/stockiate/tesis_enzo/scripts/backup_stockiate.sh"
```

Y en `backup.conf` hay que poner las rutas completas de los binarios de XAMPP:

```
MYSQLDUMP=/c/xampp/mysql/bin/mysqldump.exe
MYSQL=/c/xampp/mysql/bin/mysql.exe
```

### Dónde quedan los archivos

Bajo el `DESTINO` de la config:

```
/var/backups/stockiate/
├── diarios/     stockiate_2026-09-09_0315.sql.gz   (se guardan los últimos 14)
├── semanales/   copia del diario del domingo        (se guardan los últimos 3)
└── backup.log   qué pasó en cada corrida
```

El semanal es una copia del diario, no un dump aparte: hacer dos dumps del
mismo momento sería el doble de carga sobre la base para guardar exactamente
los mismos bytes.

La retención borra **por posición** en la lista, no por antigüedad en días. Si
el cron estuvo caído una semana, "borrar lo que tenga más de 14 días" se lleva
puestos todos los backups y deja la carpeta vacía; contando, siempre quedan los
últimos N.

### Falla ruidosamente

El script sale con código distinto de cero (y deja el motivo en `backup.log`)
si el dump falla, si el `.gz` está corrupto, si el archivo no termina con la
marca `Dump completed` de mysqldump —lo único que distingue un dump completo de
uno cortado a la mitad— o si pesa menos que `TAMANO_MINIMO_BYTES`. Un backup
que falla en silencio es peor que no tener backup, porque te da confianza falsa
justo hasta el día que lo necesitás.

Mientras se escribe, el archivo se llama `.sql.gz.parcial`. Recién se renombra
cuando pasó todas las verificaciones: si el proceso se muere a mitad de camino,
lo que queda no se puede confundir con un backup bueno.

### Restauración: el procedimiento probado

**Un backup que nunca se restauró no está probado.** Hacelo una vez ahora y
repetilo cada tanto. Los pasos exactos:

**1.** Elegí el backup más reciente:

```bash
ls -1t /var/backups/stockiate/diarios | head -1
```

**2.** Restauralo sobre una base de prueba. Nunca sobre `stockiate`: el script
se niega, pero igual conviene tenerlo claro.

```bash
./scripts/restaurar_backup.sh /var/backups/stockiate/diarios/stockiate_2026-09-09_0315.sql.gz stockiate_restore_test
```

Va a pedirte que escribas `stockiate_restore_test` para confirmar. No es un
"s/n" a propósito: un enter de más no puede alcanzar para borrar una base.

**3.** Compará el contenido contra la base real. No alcanza con que el script
no haya fallado:

```sql
SELECT (SELECT COUNT(*) FROM stockiate.productos)              AS real_productos,
       (SELECT COUNT(*) FROM stockiate_restore_test.productos) AS backup_productos,
       (SELECT COUNT(*) FROM stockiate.ventas)                 AS real_ventas,
       (SELECT COUNT(*) FROM stockiate_restore_test.ventas)    AS backup_ventas,
       (SELECT MAX(fecha) FROM stockiate_restore_test.ventas)  AS ultima_venta;
```

Los conteos tienen que dar parecidos (el backup es de anoche, así que la base
real puede tener algunas ventas más) y `ultima_venta` tiene que ser anterior al
backup.

**4.** Verificá que el UTF-8 sobrevivió, que es donde este proyecto ya se quemó
una vez:

```sql
SHOW CREATE TABLE stockiate_restore_test.usuarios;
```

Tiene que decir `enum('repositor','cajero','dueño')` con la eñe entera. Si sale
como `due├▒o`, el backup se hizo o se restauró con el charset equivocado.

**5.** Borrá la base de prueba:

```sql
DROP DATABASE stockiate_restore_test;
```

Si los cinco pasos dan bien, el backup está probado. Anotá la fecha en que lo
hiciste.

## Carteles de góndola

Se entra desde el panel: el chip del negocio -> **🏷 Carteles**. Elegís
productos, se arma una hoja A4 con 6 u 8 carteles y se imprime con `Ctrl+P`.
No hace falta ninguna biblioteca de PDF.

El diseño se puede personalizar y **queda guardado para el negocio**: cuál de
los tres diseños usar, el tamaño del precio y del nombre, qué campos mostrar,
un texto al pie y el logo del comercio.

Los tamaños se mueven **dentro de un rango**: el precio nunca baja de 40 pt ni
el resto de 12 pt. No es una limitación pendiente de levantar — es lo único que
garantiza que el cartel se lea desde la góndola, que es su único trabajo.

### Para que el logo funcione

Dos cosas del servidor, una sola vez:

1. **Habilitar GD en PHP.** En `C:\xampp\php\php.ini`, sacarle el `;` a la
   línea `;extension=gd` y reiniciar Apache. Sin esto el resto de la pantalla
   anda igual y sólo la subida del logo avisa que falta.

   Para verificar: `php -r "var_dump(extension_loaded('gd'));"`

2. **Permiso de escritura en `uploads/logos/`.** En XAMPP sobre Windows ya
   está; en Linux, una vez:

   ```bash
   chown www-data:www-data uploads/logos && chmod 755 uploads/logos
   ```

Los logos subidos no van a git (son datos de cada instalación), pero la
carpeta sí, con un `.htaccess` que impide que Apache ejecute nada ahí adentro.
