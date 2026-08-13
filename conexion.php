<?php
/**
 * stockIAte - conexion.php
 * Conexión PDO reutilizable a MySQL local (XAMPP).
 * Todos los endpoints (guardar_stock.php, registrar_correccion.php, etc.)
 * hacen require_once de este archivo.
 */

$host = "localhost";
$db   = "stockiate";
$user = "root";       // usuario por defecto de XAMPP
$pass = "";           // password por defecto de XAMPP (vacío)
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $opciones);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error de conexión a la base de datos"]);
    exit();
}
