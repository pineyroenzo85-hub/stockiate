#!/usr/bin/env bash
#
# stockIAte - restaurar_backup.sh
# ============================================================
# Restaura un backup sobre una base de PRUEBA.
#
# POR QUÉ PIDE LA BASE EXPLÍCITAMENTE
# -----------------------------------
# Porque restaurar es destructivo: lo primero que hace es borrar la base
# destino. Un script que tomara el nombre de `backup.conf` restauraría por
# omisión sobre la base de producción, y "me equivoqué de terminal" sería
# suficiente para perder las ventas del día encima de un backup de anoche.
#
# Por eso hay tres frenos, y ninguno se puede saltear con una opción:
#   1. La base destino va como argumento, no tiene default.
#   2. Se rechaza si es la base de producción (la de `backup.conf`) o si se
#      llama literalmente `stockiate`.
#   3. Se pide escribir el nombre de la base a mano para confirmar. No un
#      "s/n": un enter de más no puede alcanzar para borrar una base.
#
# UN BACKUP QUE NUNCA SE RESTAURÓ NO ESTÁ PROBADO
# -----------------------------------------------
# Esa es la razón de fondo de este archivo. El procedimiento completo, con
# los pasos exactos para probarlo una vez, está en el README.
#
# Uso:
#   ./restaurar_backup.sh <archivo.sql.gz> <base_destino> [ruta/a/backup.conf]
#
# Ejemplo:
#   ./restaurar_backup.sh /var/backups/stockiate/diarios/stockiate_2026-09-09_0315.sql.gz stockiate_restore_test

set -Eeuo pipefail

DIRECTORIO_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ARCHIVO_BACKUP="${1:-}"
BASE_DESTINO="${2:-}"
ARCHIVO_CONF="${3:-$DIRECTORIO_SCRIPT/backup.conf}"

morir() { echo "ERROR: $*" >&2; exit 1; }

if [ -z "$ARCHIVO_BACKUP" ] || [ -z "$BASE_DESTINO" ]; then
    cat >&2 <<'USO'
Uso: ./restaurar_backup.sh <archivo.sql.gz> <base_destino> [backup.conf]

  <archivo.sql.gz>  el backup a restaurar
  <base_destino>    la base sobre la que restaurar. NO puede ser la de
                    producción: este script es para probar que el backup
                    sirve, no para recuperar en caliente.
USO
    exit 1
fi

[ -f "$ARCHIVO_BACKUP" ] || morir "no existe el archivo '$ARCHIVO_BACKUP'."
[ -f "$ARCHIVO_CONF" ] || morir "no existe '$ARCHIVO_CONF'. Copiá backup.conf.example y completalo."

# shellcheck source=/dev/null
. "$ARCHIVO_CONF"

DB_HOST="${DB_HOST:-localhost}"
DB_USUARIO="${DB_USUARIO:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
MYSQL="${MYSQL:-mysql}"

command -v "$MYSQL" >/dev/null 2>&1 || morir "no se encuentra '$MYSQL'. Poné la ruta completa en MYSQL."

# ------------------------------------------------------------------
# Los frenos
# ------------------------------------------------------------------
if [ "$BASE_DESTINO" = "${DB_NOMBRE:-stockiate}" ]; then
    morir "'$BASE_DESTINO' es la base de PRODUCCIÓN. Restaurá sobre otra (por ejemplo '${BASE_DESTINO}_restore_test') y recién si el contenido está bien decidí qué hacer con la real."
fi

if [ "$BASE_DESTINO" = "stockiate" ]; then
    morir "'stockiate' es el nombre de la base real del sistema. Usá otro."
fi

# El .gz tiene que estar sano ANTES de borrar nada: descubrir que el backup
# estaba corrupto después de haber dropeado la base destino sería el peor
# orden posible.
gzip -t "$ARCHIVO_BACKUP" 2>/dev/null || morir "'$ARCHIVO_BACKUP' está corrupto o no es un .gz."

gzip -dc "$ARCHIVO_BACKUP" | tail -c 512 | grep -q 'Dump completed' \
    || morir "'$ARCHIVO_BACKUP' no termina en 'Dump completed': está truncado. NO se restauró nada."

echo
echo "  Archivo : $ARCHIVO_BACKUP"
echo "  Tamaño  : $(( $(stat -c '%s' "$ARCHIVO_BACKUP" 2>/dev/null || stat -f '%z' "$ARCHIVO_BACKUP") / 1024 )) KB"
echo "  Destino : $BASE_DESTINO  (en $DB_HOST)"
echo
echo "  Se va a BORRAR la base '$BASE_DESTINO' entera y reemplazarla por el contenido del backup."
echo
printf "  Escribí el nombre de la base para confirmar: "
read -r CONFIRMACION

[ "$CONFIRMACION" = "$BASE_DESTINO" ] || morir "no coincide. No se tocó nada."

# ------------------------------------------------------------------
# Credenciales fuera de la línea de comandos (igual que en el backup)
# ------------------------------------------------------------------
umask 077
ARCHIVO_CREDENCIALES="$(mktemp "${TMPDIR:-/tmp}/stockiate-restore.XXXXXX")"
trap 'rm -f "$ARCHIVO_CREDENCIALES"' EXIT

cat >"$ARCHIVO_CREDENCIALES" <<CNF
[client]
host=$DB_HOST
user=$DB_USUARIO
password=$DB_PASSWORD
CNF

# ------------------------------------------------------------------
# Restauración
# ------------------------------------------------------------------
echo "Recreando '$BASE_DESTINO'..."
"$MYSQL" --defaults-extra-file="$ARCHIVO_CREDENCIALES" --default-character-set=utf8mb4 \
    -e "DROP DATABASE IF EXISTS \`$BASE_DESTINO\`; CREATE DATABASE \`$BASE_DESTINO\` CHARACTER SET utf8mb4;"

echo "Restaurando..."
# El dump de mysqldump NO trae CREATE DATABASE ni USE (se hizo sobre una base
# concreta), así que se le indica acá sobre cuál aplicarlo.
if ! gzip -dc "$ARCHIVO_BACKUP" | "$MYSQL" --defaults-extra-file="$ARCHIVO_CREDENCIALES" \
        --default-character-set=utf8mb4 "$BASE_DESTINO"; then
    morir "la restauración falló. La base '$BASE_DESTINO' quedó a medio cargar."
fi

# ------------------------------------------------------------------
# Verificación: que haya quedado algo adentro
# ------------------------------------------------------------------
# Restaurar sin error y quedarse con una base vacía es posible (un dump de
# una base que ya estaba vacía). Contar es lo que convierte "el script no
# falló" en "el backup sirve".
TABLAS="$("$MYSQL" --defaults-extra-file="$ARCHIVO_CREDENCIALES" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BASE_DESTINO';")"

[ "$TABLAS" -gt 0 ] || morir "la base quedó sin ninguna tabla. El backup no sirve."

echo
echo "Listo: '$BASE_DESTINO' quedó con $TABLAS tabla(s)."
echo
echo "Ahora verificá que el CONTENIDO esté bien, no sólo que haya tablas."
echo "Corré esto contra la base restaurada y contra la real, y compará:"
echo
echo "  SELECT (SELECT COUNT(*) FROM $BASE_DESTINO.productos) productos,"
echo "         (SELECT COUNT(*) FROM $BASE_DESTINO.ventas)    ventas,"
echo "         (SELECT MAX(fecha)  FROM $BASE_DESTINO.ventas) ultima_venta;"
echo
echo "La última venta tiene que ser de antes del backup, y las cantidades"
echo "parecidas a las de la base real. Si eso da bien, el backup está probado."
exit 0
