<?php
/**
 * stockIAte - sesion_actual.php
 * ================================
 * Fuente de verdad de la sesión para el frontend. Devuelve el usuario de
 * $_SESSION, o 401 si no hay sesión.
 *
 * Lo usan dos consumidores:
 * - `confirmarSesion()` en auth.js: al cargar cada página de rol, contrasta
 *   el caché de localStorage contra esto. Si acá dice 401, borra el caché y
 *   manda a login; si el rol difiere del cacheado, lo corrige y redirige.
 * - `chatbot_ia.py`: reenvía la cookie del browser para averiguar con qué rol
 *   está hablando, en vez de creerle al `rol` que venía en el body.
 *
 * Devuelve además `modo_demo`: es la única vía por la que el frontend se
 * entera de contra qué base está trabajando (ver conexion.php). De ahí salen
 * la banda naranja de auth.js y el botón de regenerar datos de preferencias.js.
 * Va acá y no en un endpoint propio porque toda página de rol ya llama a
 * esto al cargar: un dato más en la misma respuesta no cuesta un request.
 *
 * GET -> {"ok":true,"modo_demo":bool,"usuario":{id,negocio_id,nombre,apellido,email,rol,negocio_nombre}}
 *     -> 401 {"ok":false,"codigo":"SIN_SESION"}
 */

require_once 'sesion.php';

cabeceras_json('GET, OPTIONS');
exigir_metodo('GET');

$usuario = exigir_sesion();

// El nombre del negocio no se guarda en $_SESSION (podría renombrarse
// mientras la sesión sigue abierta), así que se lee fresco.
try {
    $stmt = $pdo->prepare("SELECT nombre FROM negocios WHERE id = :id");
    $stmt->execute([':id' => $usuario['negocio_id']]);
    $usuario['negocio_nombre'] = (string) $stmt->fetchColumn();
} catch (PDOException $e) {
    $usuario['negocio_nombre'] = '';
}

responder([
    'ok' => true,
    'modo_demo' => STOCKIATE_MODO_DEMO,
    'usuario' => $usuario,
]);
