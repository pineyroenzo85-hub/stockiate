<?php
/**
 * stockIAte - revocar_invitacion.php
 * ================================
 * Anula una invitación pendiente. La usa equipo.html cuando el dueño se
 * arrepiente o mandó el link a la persona equivocada.
 *
 * Se identifica por `id` (no por token): el dueño la elige de la lista que le
 * devolvió listar_equipo.php. El DELETE lleva `negocio_id = :negocio_id` de
 * la sesión, así que pasar el id de una invitación de otro negocio no borra
 * nada — devuelve 404, igual que si no existiera.
 *
 * Espera un body tipo: { "invitacion_id": 7 }
 */

require_once 'sesion.php';

cabeceras_json();
exigir_metodo('POST');

$sesion = exigir_sesion(['dueño']);
$negocio_id = $sesion['negocio_id'];

$body = cuerpo_json();

if (!isset($body['invitacion_id'])) {
    responder(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
}

$invitacion_id = (int) $body['invitacion_id'];

try {
    $stmt = $pdo->prepare(
        "DELETE FROM invitaciones
          WHERE id = :id AND negocio_id = :negocio_id AND usada_at IS NULL"
    );
    $stmt->execute([':id' => $invitacion_id, ':negocio_id' => $negocio_id]);

    if ($stmt->rowCount() === 0) {
        responder([
            'ok' => false,
            'mensaje' => 'No se encontró una invitación pendiente con ese id',
        ], 404);
    }

    responder(['ok' => true, 'mensaje' => 'Invitación revocada']);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al revocar la invitación',
        'error' => $e->getMessage(),
    ], 500);
}
