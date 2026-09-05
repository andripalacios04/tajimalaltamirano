<?php
session_start();
include 'conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Id_Usuario = isset($usuarios['Id_Usuario']) ? intval($usuarios['Id_Usuario']) : 0;

if ($Id_Usuario == 0) {
    echo "<p>No se encontró usuario.</p>";
    exit;
}

// Consultar el equipo
$stmt = $conn->prepare("SELECT e.Nombre_Equipo, e.Descripcion FROM equipo e JOIN usuario u ON e.Id_Equipo = u.Id_Equipo WHERE u.Id_Usuario = ?");
$stmt->bind_param("i", $Id_Usuario);
$stmt->execute();
$stmt->bind_result($nombreEquipo, $descripcionEquipo);
if ($stmt->fetch()) {
    echo "<h3>Mi Equipo: " . htmlspecialchars($nombreEquipo) . "</h3>";
    echo "<p>" . htmlspecialchars($descripcionEquipo) . "</p>";
} else {
    echo "<p>No tienes un equipo asignado.</p>";
}
$stmt->close();
?>
