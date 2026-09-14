<?php
/**
 * stockIAte - probar_notificacion.php
 * ================================
 * El botón "Mandar mensaje de prueba" del panel: encola una notificación de
 * prueba y la manda EN EL ACTO, sin esperar a la corrida del script
 * programado.
 *
 * Es la única parte del sistema que le pega a Meta desde una request del
 * navegador, y es a propósito: acá sí hay alguien mirando la pantalla que
 * quiere saber si funciona. Todo lo demás pasa por la cola justamente para
 * que nadie tenga que esperar a Meta.
 *
 * Recorre exactamente el mismo camino que un aviso real (misma tabla, misma
 * plantilla, misma función de envío que usa `tareas_notificaciones.php`), así
 * que si la prueba llega, las alertas de verdad también van a llegar. Un
 * "probar" que mandara el mensaje por otra vía no probaría nada.
 *
 * Sólo el rol 'dueño'.
 */

require_once 'sesion.php'; // trae también conexion.php ($pdo)
require_once 'notificaciones.php';

cabeceras_json();
exigir_metodo('POST');

$negocio_id = exigir_sesion(['dueño'])['negocio_id'];

try {
    $config = config_avisos($pdo, $negocio_id);

    // La prueba sale por los MISMOS canales que un aviso real: si el negocio
    // tiene los dos activos, se prueban los dos. Alcanza con que uno esté
    // listo — exigir WhatsApp como antes haría imposible probar el mail, que
    // es justamente el canal que sí funciona hoy.
    $whatsapp_listo = whatsapp_configurado() && whatsapp_habilitado($config);
    $mail_listo = mail_configurado() && email_habilitado($config);

    if (!$whatsapp_listo && !$mail_listo) {
        // Se dice exactamente cuál de las cuatro cosas falta: son cuatro
        // causas distintas, se arreglan en dos lugares distintos (el `.env` lo
        // toca quien instala, la config del negocio la toca el dueño) y desde
        // afuera se ven todas igual.
        $motivos = [];

        if (!whatsapp_configurado()) {
            $motivos[] = 'faltan WHATSAPP_TOKEN / WHATSAPP_PHONE_NUMBER_ID en el .env';
        } elseif (!whatsapp_habilitado($config)) {
            $motivos[] = 'WhatsApp está desactivado o sin un teléfono válido';
        }

        if (!mail_configurado()) {
            $motivos[] = 'falta la configuración SMTP en el .env (probalo con probar_mail.php)';
        } elseif (!email_habilitado($config)) {
            $motivos[] = 'el mail está desactivado o sin una casilla válida';
        }

        responder([
            'ok' => false,
            'mensaje' => 'No hay ningún canal listo para probar: ' . implode('; ', $motivos) . '.',
        ], 503);
    }

    if (!$config['activo']) {
        responder([
            'ok' => false,
            'mensaje' => 'Las notificaciones están desactivadas. Activalas para poder probar.',
        ], 400);
    }

    // Prueba de todas las plantillas. Manda un mensaje por cada una para
    // descartar cuál está rota y cuál funciona.
    $nombre_negocio = nombre_negocio($pdo, $negocio_id);
    $hoy = date('Y-m-d');
    $manana = date('Y-m-d', strtotime('+1 day'));
    $timestamp = time();

    $plantillas_prueba = [
        [
            'tipo' => 'prueba_simple',
            'parametros' => [],
        ],
        [
            'tipo' => 'stock_critico',
            'parametros' => [$nombre_negocio, 'Stock Crítico - Producto de Prueba', 3, 10],
        ],
        [
            'tipo' => 'vencimientos',
            'parametros' => [$nombre_negocio, 2, 5, $manana],
        ],
        [
            'tipo' => 'resumen_diario',
            'parametros' => [$hoy, $hoy, 15, '450.00', 3],
        ],
    ];

    $resultados = [];
    foreach ($plantillas_prueba as $prueba) {
        // Devuelve un id POR CANAL activo: con los dos prendidos, esto
        // encola dos filas y prueba las dos.
        $ids = encolar_notificacion(
            $pdo,
            $negocio_id,
            $config,
            $prueba['tipo'],
            'prueba_' . $prueba['tipo'] . ':' . $timestamp,
            $prueba['parametros']
        );

        if (count($ids) === 0) {
            $resultados[] = [
                'plantilla' => $prueba['tipo'],
                'ok' => false,
                'error' => 'No se pudo encolar',
            ];
            continue;
        }

        // `canal` y `tipo` van en el SELECT porque enviar_notificacion() los
        // necesita: el primero para elegir por dónde sale, el segundo para
        // armar el cuerpo del mail.
        $stmt = $pdo->prepare(
            "SELECT id, tipo, canal, destino, plantilla, parametros FROM notificaciones
              WHERE id = :id AND negocio_id = :negocio_id"
        );

        foreach ($ids as $notificacion_id) {
            $stmt->execute([':id' => $notificacion_id, ':negocio_id' => $negocio_id]);
            $fila = $stmt->fetch();

            if ($fila === false) {
                continue;
            }

            $resultado = enviar_notificacion($pdo, $fila);

            $resultados[] = [
                'plantilla' => $prueba['tipo'] . ' (' . $fila['canal'] . ')',
                'ok' => $resultado['ok'],
                'error' => $resultado['error'] ?? null,
            ];
        }
    }

    // Resumir resultados
    $exitos = array_filter($resultados, fn($r) => $r['ok']);
    $fallos = array_filter($resultados, fn($r) => !$r['ok']);

    if (!empty($exitos)) {
        $msg = count($exitos) . ' plantilla(s) enviada(s): ' . implode(', ', array_map(fn($r) => $r['plantilla'], $exitos));
        if (!empty($fallos)) {
            $msg .= '. Fallos: ' . implode('; ', array_map(
                fn($r) => $r['plantilla'] . ' (' . substr($r['error'], 0, 50) . '...)',
                $fallos
            ));
        }
        responder([
            'ok' => true,
            'mensaje' => $msg,
            'resultados' => $resultados,
        ]);
    }

    responder([
        'ok' => false,
        'mensaje' => 'Todas las plantillas fallaron. Errores: ' . implode('; ', array_map(
            fn($r) => $r['plantilla'] . ': ' . $r['error'],
            $fallos
        )),
        'resultados' => $resultados,
    ], 502);
} catch (PDOException $e) {
    responder([
        'ok' => false,
        'mensaje' => 'Error al mandar la prueba',
        'error' => $e->getMessage(),
    ], 500);
}
