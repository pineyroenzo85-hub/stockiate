<?php
require 'whatsapp.php';
require 'notificaciones.php';

$resultado = enviar_plantilla_whatsapp('541160256564', 'hello_world', []);
var_dump($resultado);