<?php
// conexion.php
$host = "localhost";      // usualmente localhost si usas XAMPP
$usuario = "root";        // usuario por defecto de MySQL en XAMPP
$password = "";           // contraseña vacía por defecto
$basedatos = "retame_bd"; // 👈 nombre exacto de tu base

// Crear conexión
$conn = new mysqli($host, $usuario, $password, $basedatos);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Establecer el conjunto de caracteres
$conn->set_charset("utf8");
?>
