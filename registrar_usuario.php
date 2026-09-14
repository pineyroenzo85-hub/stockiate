<?php
/**
 * stockIAte - registrar_usuario.php  (RETIRADO)
 * ================================
 * Este endpoint era el registro abierto: recibía `rol` en el body y creaba el
 * usuario con el rol que le pidieran. En un sistema de un solo comercio era
 * una deuda; con multi-tenant es un agujero — un usuario sin negocio no tiene
 * sentido, y dejar elegir 'dueño' desde un formulario público significa que
 * cualquiera con la URL se daba de alta como administrador.
 *
 * Quedó como stub en vez de borrarse para que cualquier caller viejo (una
 * pestaña abierta con el registro.html anterior, un bookmark) reciba un
 * mensaje claro en lugar de un 404 o, peor, un INSERT a medias.
 *
 * Lo reemplazan los dos caminos de alta:
 *   - crear_negocio.php      -> negocio nuevo, quien lo crea queda 'dueño'
 *   - aceptar_invitacion.php -> se suma a un negocio existente con el rol
 *                               que fijó la invitación
 */

require_once 'sesion.php';

cabeceras_json();

responder([
    'ok' => false,
    'codigo' => 'ENDPOINT_RETIRADO',
    'mensaje' => 'El registro abierto ya no está disponible. Creá un negocio desde registro.html, '
               . 'o pedile a tu administrador el link de invitación.',
], 410);
