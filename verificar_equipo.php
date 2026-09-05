<?php
// verificar_equipo.php

header('Content-Type: application/json');

$conexion = new mysqli("localhost", "root", "", "retamedb");
if ($conexion->connect_error) {
    echo json_encode(["existe" => false]);
    exit();
}

$id_equipo = isset($_GET['id_equipo']) ? trim($_GET['id_equipo']) : '';

if ($id_equipo === '') {
    // Si está vacío, decimos que no existe pero no es error
    echo json_encode(["existe" => false]);
    exit();
}

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM equipos WHERE Id_Equipo = ?");
$stmt->bind_param("s", $id_equipo);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

echo json_encode(["existe" => $result['total'] > 0]);

$stmt->close();
$conexion->close();
