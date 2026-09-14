<?php
/**
 * stockIAte - cerrar_sesion.php
 * ================================
 * Cierra la sesión de servidor y borra la cookie STOCKIATE_SID.
 *
 * El frontend además limpia su caché de localStorage (ver `cerrarSesion()`
 * en auth.js), pero eso solo es cosmético: lo que realmente cierra la sesión
 * es este endpoint.
 */

require_once 'sesion.php';

cabeceras_json();
exigir_metodo('POST');

cerrar_sesion_stockiate();

responder(['ok' => true, 'mensaje' => 'Sesión cerrada']);
