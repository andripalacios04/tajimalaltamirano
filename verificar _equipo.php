<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("conexion.php");

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_equipo'])) {
    $id_equipo = trim($_POST['id_equipo']);
    
    if (empty($id_equipo)) {
        echo json_encode(['status' => 'error', 'mensaje' => '❌ ID de equipo vacío.']);
        exit;
    }
    
    // Verificar que el equipo exista
    $sql_equipo = "SELECT * FROM equipo WHERE Id_Equipo = '$id_equipo'";
    $result_equipo = mysqli_query($conn, $sql_equipo);
    
    if (mysqli_num_rows($result_equipo) == 0) {
        echo json_encode(['status' => 'error', 'mensaje' => '❌ El equipo no existe.']);
        exit;
    }
    
    $equipo_data = mysqli_fetch_assoc($result_equipo);
    
    // Contar jugadores actuales
    $sql_contar = "SELECT COUNT(*) as total_jugadores FROM equipo_jugador WHERE id_equipo = '$id_equipo'";
    $result_contar = mysqli_query($conn, $sql_contar);
    $contar_data = mysqli_fetch_assoc($result_contar);
    
    $jugadores_actuales = $contar_data['total_jugadores'];
    $capacidad = $equipo_data['cantidad'];
    $espacios_disponibles = $capacidad - $jugadores_actuales;
    
    echo json_encode([
        'status' => 'success',
        'nombre_equipo' => $equipo_data['Nombre'],
        'capacidad' => $capacidad,
        'jugadores_actuales' => $jugadores_actuales,
        'espacios_disponibles' => $espacios_disponibles
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'mensaje' => '❌ Solicitud inválida.']);
?>