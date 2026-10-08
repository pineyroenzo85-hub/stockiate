<?php
/**
 * stockIAte - sesion_db.php
 * ================================
 * Sesiones de PHP guardadas en MySQL, para cuando el disco no sirve.
 *
 * POR QUÉ EXISTE
 * --------------
 * PHP guarda las sesiones como archivos en disco. En Heroku el disco es
 * efímero: cada reinicio del dyno (como mínimo uno por día) lo borra, y todo el
 * mundo queda deslogueado de golpe; con más de un dyno, además, cada uno vería
 * un disco distinto. Con la sesión en la base sobrevive a los dos casos.
 *
 * Se activa sola cuando la base es administrada (STOCKIATE_DB_REMOTA) o con
 * `STOCKIATE_SESIONES=db` en el entorno, que sirve para probarlo en local. En
 * XAMPP sigue usándose el disco, como siempre.
 *
 * DOS DECISIONES
 * --------------
 * - La tabla `sesiones` se crea sola la primera vez que se escribe y falta.
 *   No va en schema.sql: seed_demo.php dropea todas las tablas al regenerar,
 *   y una sesión que apunta a una tabla borrada no debe romper el login.
 * - Vida fija de 8 horas desde la última escritura, en vez de leer
 *   `session.gc_maxlifetime` (24 min por defecto, y nadie lo tocó): en un
 *   comercio, que la sesión del cajero muera a media mañana por inactividad
 *   no es una medida de seguridad, es un cierre de sesión en plena venta.
 *
 * LÍMITE CONOCIDO: no hay bloqueo de sesión (los archivos sí lo tienen). Dos
 * requests simultáneos de la misma sesión que escriban datos distintos pisan
 * el último. Hoy la sesión se escribe al loguear y casi nunca después, así que
 * no pasa; si algún día se usa `$_SESSION` como estado mutable, hay que
 * agregar GET_LOCK() acá.
 */

const SESION_DB_VIDA_SEGUNDOS = 28800; // 8 h

function sesiones_en_base(): bool
{
    return (defined('STOCKIATE_DB_REMOTA') && STOCKIATE_DB_REMOTA)
        || strtolower((string) leer_env('STOCKIATE_SESIONES', '')) === 'db';
}

class SesionEnBase implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $st = $this->pdo->prepare('SELECT datos FROM sesiones WHERE id = :id AND actualizado_en > :minimo');
            $st->execute([':id' => $id, ':minimo' => time() - SESION_DB_VIDA_SEGUNDOS]);
            $datos = $st->fetchColumn();
            return $datos === false ? '' : (string) $datos;
        } catch (PDOException $e) {
            // Sin tabla todavía = sin sesiones: el que entra tiene que loguearse
            // y ahí write() la crea. No tumbar el request por esto.
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return $this->guardar($id, $data);
        } catch (PDOException $e) {
            $this->crear_tabla();
            return $this->guardar($id, $data);
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare('DELETE FROM sesiones WHERE id = :id')->execute([':id' => $id]);
        } catch (PDOException $e) {
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $st = $this->pdo->prepare('DELETE FROM sesiones WHERE actualizado_en < :limite');
            $st->execute([':limite' => time() - SESION_DB_VIDA_SEGUNDOS]);
            return $st->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function guardar(string $id, string $data): bool
    {
        $ahora = time();
        $st = $this->pdo->prepare(
            'INSERT INTO sesiones (id, datos, actualizado_en) VALUES (:id, :datos, :t)
             ON DUPLICATE KEY UPDATE datos = :datos2, actualizado_en = :t2'
        );
        return $st->execute([':id' => $id, ':datos' => $data, ':t' => $ahora, ':datos2' => $data, ':t2' => $ahora]);
    }

    private function crear_tabla(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sesiones (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                datos MEDIUMBLOB NOT NULL,
                actualizado_en INT UNSIGNED NOT NULL,
                KEY idx_sesiones_actualizado (actualizado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
