#!/usr/bin/env bash
#
# stockIAte - backup_stockiate.sh
# ============================================================
# Dump comprimido de la base, con retención y con fallas ruidosas.
#
# POR QUÉ EXISTE
# --------------
# El sistema va a tener datos reales de un comercio. Perder la base sería
# perder el piloto y, con él, los datos de la tesis: las ventas, las
# correcciones de la IA y todo lo que sostiene los números del informe.
#
# LAS TRES COSAS QUE NO SON OBVIAS
# --------------------------------
# 1. LA CONTRASEÑA NUNCA VA EN LA LÍNEA DE COMANDOS. Un `mysqldump -pclave`
#    aparece en `ps aux` para cualquier usuario del servidor mientras el
#    dump corre. Acá se escribe un archivo temporal con formato my.cnf, con
#    permisos 600 y borrado por `trap` pase lo que pase, y se lo pasa con
#    `--defaults-extra-file`. Tampoco va en este script: sale de
#    `backup.conf`, que está gitignoreado.
#
# 2. FALLA RUIDOSAMENTE, A PROPÓSITO. Se verifican cuatro cosas y cualquiera
#    que falle corta con exit != 0 y deja el motivo en el log:
#      - que `mysqldump` haya terminado bien (y no sólo `gzip`, que es lo
#        que devolvería un pipe sin `pipefail`);
#      - que el .gz esté íntegro (`gzip -t`);
#      - que el dump termine con la marca "Dump completed", que es lo único
#        que distingue un dump completo de uno cortado a la mitad;
#      - que el archivo pese más que TAMANO_MINIMO_BYTES.
#    Un backup que falla en silencio es peor que no tener backup, porque te
#    da confianza falsa justo hasta el día que lo necesitás.
#
# 3. EL SEMANAL NO ES UN DUMP APARTE. Es una copia del diario del día que
#    diga DIA_SEMANAL. Hacer dos dumps del mismo momento sería el doble de
#    carga sobre la base para guardar exactamente los mismos bytes.
#
# Uso:
#   ./backup_stockiate.sh [ruta/a/backup.conf]
#
# Cron (todos los días a las 3:15 de la mañana):
#   15 3 * * * /ruta/al/repo/scripts/backup_stockiate.sh >> /var/log/stockiate-backup.log 2>&1
#
# Salida: 0 si el backup quedó bien, 1 si falló (y el motivo, en el log).

set -Eeuo pipefail

DIRECTORIO_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVO_CONF="${1:-$DIRECTORIO_SCRIPT/backup.conf}"

# El log todavía no tiene destino (sale de la config), así que los errores de
# arranque van a stderr. `registrar()` se redefine más abajo, cuando ya
# sabemos dónde escribir.
ARCHIVO_LOG=""

registrar() {
    local linea
    linea="$(date '+%Y-%m-%d %H:%M:%S') $*"
    echo "$linea" >&2
    # `if` y no `[ ... ] && echo`: un `&&` cuyo lado izquierdo da falso deja a
    # la función con estado 1 y dispara el `trap ERR` de abajo, o sea que no
    # tener log configurado se reportaría como un error del backup.
    if [ -n "$ARCHIVO_LOG" ]; then
        echo "$linea" >>"$ARCHIVO_LOG"
    fi
    return 0
}

morir() {
    registrar "ERROR: $*"
    exit 1
}

# Cualquier error no manejado (por el `set -e`) también tiene que quedar
# registrado: sin esto, un fallo en medio del script salía sin decir nada.
trap 'morir "el script se cortó en la línea $LINENO"' ERR

# ------------------------------------------------------------------
# 1. Configuración
# ------------------------------------------------------------------
[ -f "$ARCHIVO_CONF" ] || morir "no existe '$ARCHIVO_CONF'. Copiá backup.conf.example y completalo."

# Los permisos importan: el archivo tiene la contraseña de la base.
# `stat -c` es GNU; en macOS/BSD es `stat -f`. Si no hay ninguno (o sea, no
# se puede saber), se avisa y se sigue en vez de frenar el backup.
PERMISOS="$(stat -c '%a' "$ARCHIVO_CONF" 2>/dev/null || stat -f '%Lp' "$ARCHIVO_CONF" 2>/dev/null || echo '')"
if [ -n "$PERMISOS" ] && [ "$PERMISOS" != "600" ] && [ "$PERMISOS" != "400" ]; then
    echo "AVISO: '$ARCHIVO_CONF' tiene permisos $PERMISOS y contiene una contraseña. Corregilo con: chmod 600 '$ARCHIVO_CONF'" >&2
fi

# shellcheck source=/dev/null
. "$ARCHIVO_CONF"

: "${DB_NOMBRE:?falta DB_NOMBRE en la configuración}"
: "${DESTINO:?falta DESTINO en la configuración}"
DB_HOST="${DB_HOST:-localhost}"
DB_USUARIO="${DB_USUARIO:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
RETENER_DIARIOS="${RETENER_DIARIOS:-14}"
RETENER_SEMANALES="${RETENER_SEMANALES:-3}"
DIA_SEMANAL="${DIA_SEMANAL:-7}"
TAMANO_MINIMO_BYTES="${TAMANO_MINIMO_BYTES:-51200}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"

DIR_DIARIOS="$DESTINO/diarios"
DIR_SEMANALES="$DESTINO/semanales"
mkdir -p "$DIR_DIARIOS" "$DIR_SEMANALES"

ARCHIVO_LOG="$DESTINO/backup.log"

command -v "$MYSQLDUMP" >/dev/null 2>&1 || morir "no se encuentra '$MYSQLDUMP'. Poné la ruta completa en MYSQLDUMP."

# ------------------------------------------------------------------
# 2. Credenciales fuera de la línea de comandos
# ------------------------------------------------------------------
# `umask 077` ANTES del mktemp: si se crea con permisos abiertos y se
# corrigen después, queda una ventana en la que la contraseña es legible.
umask 077
ARCHIVO_CREDENCIALES="$(mktemp "${TMPDIR:-/tmp}/stockiate-backup.XXXXXX")"
trap 'rm -f "$ARCHIVO_CREDENCIALES"' EXIT

cat >"$ARCHIVO_CREDENCIALES" <<CNF
[client]
host=$DB_HOST
user=$DB_USUARIO
password=$DB_PASSWORD
CNF

# ------------------------------------------------------------------
# 3. El dump
# ------------------------------------------------------------------
MARCA_TIEMPO="$(date '+%Y-%m-%d_%H%M')"
DESTINO_ARCHIVO="$DIR_DIARIOS/${DB_NOMBRE}_${MARCA_TIEMPO}.sql.gz"
PARCIAL="$DESTINO_ARCHIVO.parcial"

# Restos de una corrida anterior que se murió a mitad del dump. Sin esto se
# acumulan para siempre: la retención no los toca (filtra por `.sql.gz`, y
# éstos terminan en `.parcial`, que es justamente lo que los hace
# inconfundibles con un backup bueno).
find "$DIR_DIARIOS" -maxdepth 1 -name '*.parcial' -mmin +60 -delete 2>/dev/null || true

registrar "Backup de '$DB_NOMBRE' -> $DESTINO_ARCHIVO"

# `--single-transaction` toma una foto consistente sin lockear las tablas:
# con InnoDB permite que el comercio siga vendiendo mientras corre el dump.
# `--routines --triggers --events` para no perder nada que no sean tablas.
# `--default-character-set=utf8mb4` porque el ENUM del rol 'dueño' tiene una
# eñe, y el proyecto ya se quemó una vez con eso (ver CLAUDE.md).
#
# El `set -e` no alcanza para un pipe: sin `pipefail`, un mysqldump que
# revienta seguido de un gzip que anda bien devuelve 0. Está activado arriba.
if ! "$MYSQLDUMP" \
        --defaults-extra-file="$ARCHIVO_CREDENCIALES" \
        --single-transaction \
        --routines --triggers --events \
        --default-character-set=utf8mb4 \
        "$DB_NOMBRE" 2>>"$ARCHIVO_LOG" | gzip -9 >"$PARCIAL"; then
    rm -f "$PARCIAL"
    morir "mysqldump falló. Mirá las líneas de arriba en $ARCHIVO_LOG."
fi

# ------------------------------------------------------------------
# 4. Verificaciones
# ------------------------------------------------------------------
gzip -t "$PARCIAL" 2>/dev/null || { rm -f "$PARCIAL"; morir "el archivo comprimido está corrupto"; }

# Un dump cortado a la mitad puede pesar bastante y descomprimir sin error.
# Lo único que lo delata es que le falte la línea final que escribe
# mysqldump. Se leen sólo las últimas líneas, no el archivo entero.
if ! gzip -dc "$PARCIAL" | tail -c 512 | grep -q 'Dump completed'; then
    rm -f "$PARCIAL"
    morir "el dump no termina en 'Dump completed': quedó truncado"
fi

TAMANO="$(stat -c '%s' "$PARCIAL" 2>/dev/null || stat -f '%z' "$PARCIAL")"
if [ "$TAMANO" -lt "$TAMANO_MINIMO_BYTES" ]; then
    rm -f "$PARCIAL"
    morir "el backup pesa $TAMANO bytes, menos que el mínimo de $TAMANO_MINIMO_BYTES. Se descartó."
fi

# Recién ahora pasa a llamarse como corresponde. Mientras se escribía tenía
# la extensión `.parcial`: si el proceso se muere a mitad de camino (o el
# servidor se reinicia), lo que queda no puede confundirse con un backup
# bueno, ni acá ni al mirar la carpeta.
mv "$PARCIAL" "$DESTINO_ARCHIVO"
registrar "OK: $(basename "$DESTINO_ARCHIVO") ($((TAMANO / 1024)) KB)"

# ------------------------------------------------------------------
# 5. Copia semanal
# ------------------------------------------------------------------
DIA_HOY="$(date '+%u')"   # 1 = lunes ... 7 = domingo
if [ "$DIA_HOY" = "$DIA_SEMANAL" ]; then
    cp "$DESTINO_ARCHIVO" "$DIR_SEMANALES/"
    registrar "Copiado también como semanal."
fi

# ------------------------------------------------------------------
# 6. Retención
# ------------------------------------------------------------------
# Se borra por POSICIÓN en la lista ordenada, no por antigüedad en días: si
# el cron estuvo caído una semana, "borrar lo que tenga más de 14 días" se
# lleva puestos TODOS los backups y deja la carpeta vacía. Contando, siempre
# quedan los N últimos, sean de ayer o del mes pasado.
podar() {
    local carpeta="$1" conservar="$2" etiqueta="$3"
    local borrados=0 archivo lista

    # `ls -1` sobre nombres con fecha ordena cronológicamente (el formato
    # AAAA-MM-DD_HHMM está pensado justo para eso). Se invierte y se saltean
    # los primeros N. Parsear la salida de `ls` es seguro acá porque los
    # nombres los genera este mismo script y no tienen espacios.
    #
    # El `|| true` NO es un descuido: con `pipefail`, una carpeta vacía hace
    # que `grep` devuelva 1 y todo el pipe falle, y eso dispararía el `trap
    # ERR` -- o sea que "no hay nada para borrar" se reportaría como un error
    # del backup. Pasó, y el síntoma era un backup correcto con un ERROR en el
    # log.
    lista="$(ls -1 "$carpeta" 2>/dev/null | grep -E '\.sql\.gz$' | sort -r | tail -n "+$((conservar + 1))" || true)"

    if [ -n "$lista" ]; then
        while IFS= read -r archivo; do
            [ -n "$archivo" ] || continue
            rm -f "$carpeta/$archivo"
            borrados=$((borrados + 1))
        done <<<"$lista"
    fi

    if [ "$borrados" -gt 0 ]; then
        registrar "Retención $etiqueta: se borraron $borrados archivo(s), quedan $conservar."
    fi
    return 0
}

podar "$DIR_DIARIOS" "$RETENER_DIARIOS" "diaria"
podar "$DIR_SEMANALES" "$RETENER_SEMANALES" "semanal"

registrar "Listo."
exit 0
