<?php
/**
 * stockIAte - listar_equipo.php
 * ================================
 * Lista la gente del negocio del dueño logueado (usuarios + invitaciones
 * pendientes), para la pantalla equipo.html.
 *
 * Todo se filtra por el `negocio_id` de la sesión: un dueño no puede ver el
 * equipo de otro comercio ni aunque lo pida explícitamente, porque no hay
 * ningún parámetro de entrada que le permita pedirlo.
 *
 * POST (sin body) -> {"ok":true,"usuarios":[...],"invitaciones":[...]}
 */

require_once 'sesion.php';

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, apellido, email, rol, creado_en
           FROM usuarios
          WHERE negocio_id = :negocio_id
          ORDER BY creado_en ASC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);
    $usuarios = $stmt->fetchAll();

    // Sólo las que todavía sirven: ni usadas ni vencidas.
    $stmt = $pdo->prepare(
        "SELECT id, email, rol, token, expira_at, creado_en
           FROM invitaciones
          WHERE negocio_id = :negocio_id
            AND usada_at IS NULL
            AND expira_at > NOW()
          ORDER BY creado_en DESC"
    );
    $stmt->execute([':negocio_id' => $negocio_id]);
    $invitaciones = $stmt->fetchAll();

    responder([
        'ok' => true,
        'usuarios' => $usuarios,
        'invitaciones' => $invitaciones,
    ]);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al listar el equipo',
        'error' => $e->getMessage(),
    ], 500);
}
