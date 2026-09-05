<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    if (file_exists(__DIR__ . '/includes/retame_global.php')) {
        include_once __DIR__ . '/includes/retame_global.php';
    } elseif (file_exists(__DIR__ . '/../includes/retame_global.php')) {
        include_once __DIR__ . '/../includes/retame_global.php';
    } elseif (file_exists(__DIR__ . '/../../includes/retame_global.php')) {
        include_once __DIR__ . '/../../includes/retame_global.php';
    }
}
$retame_ajax_temprano = true;
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once '../conexion.php';

/* =========================
   VALIDAR SESIÓN
========================= */
if (!isset($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$id_retador_actual = $_SESSION['usuario_data']['Id_Retador'];
$conexion = $conn;

/* =========================
   FUNCIÓN PARA MOSTRAR EQUIPOS DISPONIBLES
========================= */
function mostrarEquiposDisponibles($conexion, $id_retador_actual) {
    $sql = "SELECT 
                et.Id_EquipoTemporal,
                et.Nombre as nombre_equipo,
                et.Id_Deporte,
                d.Nombre as nombre_deporte,
                et.Cantidad,
                et.Capitan,
                r.Nombre as nombre_capitan,
                r.Apellido as apellido_capitan,
                et.Fecha_Creacion,
                et.Estado,
                -- Contar cuántos jugadores ya se han unido
                (SELECT COUNT(*) 
                 FROM equiporetador_temporal ert
                 WHERE ert.Id_EquipoTemporal = et.Id_EquipoTemporal) as jugadores_unidos
            FROM equipo_temporal et
            JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
            JOIN retador r ON et.Capitan = r.Id_Retador
            WHERE et.Estado = 'Esperando'
            -- FILTRAR: NO mostrar equipos donde el usuario ya está
            AND NOT EXISTS (
                SELECT 1 
                FROM equiporetador_temporal ert2
                WHERE ert2.Id_EquipoTemporal = et.Id_EquipoTemporal
                AND ert2.Id_Retador = '$id_retador_actual'
            )
            ORDER BY et.Fecha_Creacion DESC";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    return $resultado;
}

/* =========================
   FUNCIÓN PARA MOSTRAR MIS EQUIPOS
========================= */
function mostrarMisEquipos($conexion, $id_retador_actual) {
    $sql = "SELECT 
                et.Id_EquipoTemporal,
                et.Nombre as nombre_equipo,
                et.Id_Deporte,
                d.Nombre as nombre_deporte,
                et.Cantidad,
                et.Capitan,
                r.Nombre as nombre_capitan,
                r.Apellido as apellido_capitan,
                et.Fecha_Creacion,
                et.Estado,
                -- Contar cuántos jugadores ya se han unido
                (SELECT COUNT(*) 
                 FROM equiporetador_temporal ert
                 WHERE ert.Id_EquipoTemporal = et.Id_EquipoTemporal) as jugadores_unidos
            FROM equipo_temporal et
            JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
            JOIN retador r ON et.Capitan = r.Id_Retador
            WHERE et.Estado = 'Esperando'
            -- FILTRAR: MOSTRAR SOLO equipos donde el usuario ya está
            AND EXISTS (
                SELECT 1 
                FROM equiporetador_temporal ert2
                WHERE ert2.Id_EquipoTemporal = et.Id_EquipoTemporal
                AND ert2.Id_Retador = '$id_retador_actual'
            )
            ORDER BY et.Fecha_Creacion DESC";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    return $resultado;
}

/* =========================
   FUNCIÓN PARA UNIRSE A UN EQUIPO
========================= */
if (isset($_GET['unirse']) && isset($_GET['equipo'])) {
    $id_equipo = mysqli_real_escape_string($conexion, $_GET['equipo']);
    $id_retador = $_SESSION['usuario_data']['Id_Retador'];
    
    // Verificar que el equipo aún esté disponible
    $sql_verificar = "SELECT Estado, Cantidad FROM equipo_temporal 
                      WHERE Id_EquipoTemporal = '$id_equipo'";
    $result_verificar = mysqli_query($conexion, $sql_verificar);
    $equipo_info = mysqli_fetch_assoc($result_verificar);
    
    if ($equipo_info['Estado'] !== 'Esperando') {
        echo "<script>alert('⚠️ Este equipo ya no está disponible.');</script>";
    } else {
        // Verificar si el usuario ya está en el equipo
        $sql_verificar_miembro = "SELECT * FROM equiporetador_temporal 
                                  WHERE Id_EquipoTemporal = '$id_equipo' 
                                  AND Id_Retador = '$id_retador'";
        $result_miembro = mysqli_query($conexion, $sql_verificar_miembro);
        
        if (mysqli_num_rows($result_miembro) > 0) {
            echo "<script>alert('⚠️ Ya estás en este equipo.');</script>";
        } else {
            // Contar jugadores actuales
            $sql_contar = "SELECT COUNT(*) as total FROM equiporetador_temporal 
                          WHERE Id_EquipoTemporal = '$id_equipo'";
            $result_contar = mysqli_query($conexion, $sql_contar);
            $contador = mysqli_fetch_assoc($result_contar);
            $jugadores_actuales = $contador['total'];
            
            // Obtener deporte del equipo
            $sql_deporte = "SELECT Id_Deporte FROM equipo_temporal 
                           WHERE Id_EquipoTemporal = '$id_equipo'";
            $result_deporte = mysqli_query($conexion, $sql_deporte);
            $deporte_info = mysqli_fetch_assoc($result_deporte);
            $id_deporte = $deporte_info['Id_Deporte'];
            
            // Generar ID para la relación
            $id_relacion = "EQRT" . uniqid() . rand(100, 999);
            
            // Insertar en la tabla de unión
            $sql_insertar = "INSERT INTO equiporetador_temporal
                            (Id_EquipoRetadorTemp, Id_EquipoTemporal, Id_Retador, Id_Deporte)
                            VALUES
                            ('$id_relacion', '$id_equipo', '$id_retador', '$id_deporte')";
            
            if (mysqli_query($conexion, $sql_insertar)) {
                // Verificar si el equipo ya se llenó
                $jugadores_actuales++;
                
               
                
                echo "<script>alert('✅ Te has unido al equipo exitosamente.');</script>";
                echo "<script>window.location.href = 'equipos_disponibles.php';</script>";
                exit;
            } else {
                echo "<script>alert('❌ Error al unirse al equipo: " . mysqli_error($conexion) . "');</script>";
            }
        }
    }
}

if (file_exists(__DIR__ . '/includes/retame_global.php')) {
    include_once __DIR__ . '/includes/retame_global.php';
} elseif (file_exists(__DIR__ . '/../includes/retame_global.php')) {
    include_once __DIR__ . '/../includes/retame_global.php';
} elseif (file_exists(__DIR__ . '/../../includes/retame_global.php')) {
    include_once __DIR__ . '/../../includes/retame_global.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipos Disponibles - RETAME</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        /* ESTRUCTURA GENERAL - IGUAL AL DASHBOARD */
        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #140f27, #203a43, #1c2a92);
            color: #eeeeee;
            overflow-x: hidden;
        }
        
        /* SIDEBAR - IGUAL AL DASHBOARD */
        .sidebar {
            width: 250px;
            background: #111820;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 5px 0px 20px rgba(9, 5, 138, 0.7);
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }
        .sidebar h2 {
            font-size: 18px;
            text-align: center;
            color: #ffffff;
            margin-bottom: 20px;
        }
        .doctor-logo {
            width: 90px;
            margin-bottom: 10px;
            border-radius: 50%;
            border: 3px solid #00ffc6;
        }
        .menu {
            list-style: none;
            width: 100%;
            margin-top: 20px;
        }
        .menu li {
            padding: 12px;
            margin: 10px 0;
            text-align: center;
            border-radius: 8px;
            transition: all 0.3s ease;
            background-color: rgba(0, 255, 198, 0.1);
            border: 1px solid rgba(0, 255, 198, 0.2);
        }
        .menu li a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 500;
            display: block;
            font-size: 14px;
        }
        .menu li:hover {
            background-color: rgba(244, 16, 16, 0.3);
            transform: translateX(5px);
            border-color: rgba(244, 16, 16, 0.5);
        }
        
        /* CONTENIDO PRINCIPAL - IGUAL AL DASHBOARD */
        .main-content {
            flex: 1;
            padding: 40px;
            margin-left: 250px; /* Espacio para sidebar */
            min-height: 100vh;
        }
        .header h1 {
            font-size: 32px;
            color: #ffffff;
            margin-bottom: 30px;
            text-align: center;
        }
        
        /* ESTILOS ESPECÍFICOS DE EQUIPOS DISPONIBLES (MANTENIDOS) */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(27, 31, 39, 0.9);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0px 0px 30px rgba(0, 26, 255, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(0, 26, 255, 0.3);
        }
        
        .page-header h1 {
            color: #ffffff;
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
        }
        
        .subtitle {
            color: #a0a0a0;
            font-size: 1.1em;
        }
        
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px;
            background: rgba(40, 44, 52, 0.7);
            border-radius: 15px;
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        
        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 0, 0, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #545b62);
        }
        
        .btn-secondary:hover {
            box-shadow: 0 10px 20px rgba(108, 117, 125, 0.3);
        }
        
        /* PESTAÑAS */
        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid rgba(0, 26, 255, 0.3);
            background: rgba(40, 44, 52, 0.5);
            border-radius: 10px 10px 0 0;
            overflow: hidden;
        }
        
        .tab-btn {
            padding: 15px 30px;
            background: transparent;
            color: #a0a0a0;
            border: none;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            flex: 1;
            text-align: center;
        }
        
        .tab-btn.active {
            color: #ffffff;
            background: rgba(255, 0, 0, 0.1);
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(135deg, #ff0000, #e00000);
        }
        
        .tab-btn:hover:not(.active) {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .mis-equipos-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(40, 44, 52, 0.5);
            border-radius: 15px;
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        
        .mis-equipos-header h3 {
            font-size: 1.8em;
            margin-bottom: 10px;
            color: #43e97b;
            text-shadow: 0 0 10px rgba(67, 233, 123, 0.3);
        }
        
        .mis-equipos-header p {
            color: #a0a0a0;
            font-size: 1em;
        }
        
        /* GRID DE EQUIPOS - ESTILO MANTENIDO */
        .equipos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .equipo-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(0, 26, 255, 0.2);
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }
        
        .equipo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 26, 255, 0.3);
            border-color: rgba(255, 0, 0, 0.5);
        }
        
        .equipo-header {
            background: linear-gradient(135deg, rgba(240, 147, 251, 0.8), rgba(245, 87, 108, 0.8));
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .equipo-nombre {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .equipo-deporte {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .equipo-body {
            padding: 20px;
        }
        
        .equipo-info {
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .info-label {
            color: #a0a0a0;
            font-weight: 500;
        }
        
        .info-value {
            color: #ffffff;
            font-weight: 600;
        }
        
        .jugadores-progress {
            margin: 20px 0;
        }
        
        .progress-bar {
            height: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.9em;
            color: #a0a0a0;
        }
        
        .equipo-footer {
            padding: 15px 20px;
            background: rgba(40, 44, 52, 0.5);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .btn-unirse {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-unirse:hover {
            opacity: 0.9;
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(67, 233, 123, 0.3);
        }
        
        .btn-unirse:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-ya-unido {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #6c757d, #545b62);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: default;
        }
        
        .btn-retar {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: all 0.3s;
        }
        
        .btn-retar:hover {
            opacity: 0.9;
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(255, 0, 0, 0.3);
        }
        
        .btn-retar:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #a0a0a0;
        }
        
        .empty-icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #ff0000;
        }
        
        .empty-text {
            font-size: 1.2em;
            margin-bottom: 20px;
            color: #ffffff;
        }
        
        /* RESPONSIVE - COMBINADO */
        @media screen and (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }
            .main-content {
                margin-left: 200px;
                padding: 20px;
            }
            .container {
                padding: 20px;
            }
        }
        
        @media screen and (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -250px;
                width: 250px;
                height: 100%;
                transition: left 0.3s ease;
                z-index: 1000;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            .container {
                padding: 15px;
            }
            .equipos-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            .controls {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }
            .tabs {
                flex-direction: column;
            }
            .tab-btn {
                padding: 12px;
                text-align: center;
            }
            
            /* Botón hamburguesa para móviles */
            .menu-toggle {
                position: fixed;
                top: 20px;
                left: 20px;
                background: rgba(255, 0, 0, 0.8);
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 5px;
                cursor: pointer;
                z-index: 1001;
                font-size: 1.2rem;
            }
        }
        
        @media screen and (max-width: 480px) {
            .equipo-card {
                margin: 0 5px;
            }
            .page-header h1 {
                font-size: 2em;
            }
            .btn {
                padding: 10px 20px;
                font-size: 0.9em;
            }
            .empty-icon {
                font-size: 3em;
            }
            .empty-text {
                font-size: 1em;
            }
        }
        
        /* OVERLAY PARA MÓVIL */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }
        .overlay.active {
            display: block;
        }
    </style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Equipos Disponibles - RETAME'); } ?>

    <!-- Botón hamburguesa solo para móviles -->
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Overlay para cerrar sidebar en móviles -->
    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <img src="../assets/doctor.png" class="doctor-logo" alt="Logo Doctor">
        <h2>🏥 RETAME</h2>
        <ul class="menu">
            <li><a href="../Perfil2.php">🏠 Inicio</a></li>
            <li><a href="../agendar.html">📅 Agendar Reta</a></li>
            <li><a href="../mis_citas.html">📋 Mis Retas</a></li>
            <li><a href="../Configurar mi perfil.php">👤 Configurar Perfil</a></li> 
            <li><a href="equipos_disponibles.php">👥 Equipos Disponibles</a></li> 
            <li><a href="../lougout.php">🚪 Cerrar Sesión</a></li> 
        </ul>
    </div>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>⚽ Equipos Temporales</h1>
                <p class="subtitle">Gestiona tu participación en equipos temporales</p>
            </div>
            
            <div class="controls">
                <a href="../Perfil2.php" class="btn btn-secondary">← Volver al Perfil</a>
                <div>
                    <a href="crear_equipo_temporal.php" class="btn">➕ Crear Nuevo Equipo</a>
                </div>
            </div>
            
            <!-- PESTAÑAS -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('disponibles')">📋 Equipos Disponibles</button>
                <button class="tab-btn" onclick="switchTab('misequipos')">✅ Mis Equipos</button>
            </div>
            
            <!-- PESTAÑA 1: EQUIPOS DISPONIBLES -->
            <div id="disponibles" class="tab-content active">
                <?php
                $equipos_disponibles = mostrarEquiposDisponibles($conexion, $id_retador_actual);
                
                if (mysqli_num_rows($equipos_disponibles) > 0) {
                ?>
                <div class="equipos-grid">
                    <?php while ($equipo = mysqli_fetch_assoc($equipos_disponibles)) { 
                        $porcentaje = ($equipo['jugadores_unidos'] / $equipo['Cantidad']) * 100;
                    ?>
                    <div class="equipo-card">
                        <div class="equipo-header">
                            <div class="equipo-nombre"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></div>
                            <div class="equipo-deporte"><?php echo htmlspecialchars($equipo['nombre_deporte']); ?></div>
                        </div>
                        
                        <div class="equipo-body">
                            <div class="equipo-info">
                                <div class="info-item">
                                    <span class="info-label">Capitán:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($equipo['nombre_capitan'] . ' ' . $equipo['apellido_capitan']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Estado:</span>
                                    <span class="info-value" style="color: #28a745;"><?php echo htmlspecialchars($equipo['Estado']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Fecha creación:</span>
                                    <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($equipo['Fecha_Creacion'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="jugadores-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <span>Jugadores unidos</span>
                                    <span><strong><?php echo $equipo['jugadores_unidos']; ?></strong> de <?php echo $equipo['Cantidad']; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="equipo-footer">
                            <form method="GET" style="display: inline;">
                                <input type="hidden" name="equipo" value="<?php echo $equipo['Id_EquipoTemporal']; ?>">
                                <button type="submit" name="unirse" value="1" class="btn-unirse">
                                    🤝 Unirse a este equipo
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <?php } else { ?>
                <div class="empty-state">
                    <div class="empty-icon">🏐</div>
                    <h2 class="empty-text">No hay equipos disponibles para unirte</h2>
                    <p>Ya estás en todos los equipos disponibles o no hay equipos creados</p>
                    <a href="crear_equipo_temporal.php" class="btn" style="margin-top: 20px;">Crear nuevo equipo</a>
                </div>
                <?php } ?>
            </div>
            
            <!-- PESTAÑA 2: MIS EQUIPOS -->
            <div id="misequipos" class="tab-content">
                <div class="mis-equipos-header">
                    <h3>✅ Equipos en los que participas</h3>
                    <p>Estos son los equipos temporales a los que ya te has unido</p>
                </div>
                
                <?php
                $mis_equipos = mostrarMisEquipos($conexion, $id_retador_actual);
                
                if (mysqli_num_rows($mis_equipos) > 0) {
                ?>
                <div class="equipos-grid">
                    <?php while ($equipo = mysqli_fetch_assoc($mis_equipos)) { 
                        $porcentaje = ($equipo['jugadores_unidos'] / $equipo['Cantidad']) * 100;
                        // Determinar si es capitán
                        $es_capitan = ($equipo['Capitan'] == $id_retador_actual);
                        // Determinar si el equipo está lleno
                        $equipo_lleno = ($equipo['jugadores_unidos'] >= $equipo['Cantidad']);
                        // Determinar si debe mostrar botón "Retar"
                        $mostrar_retar = ($es_capitan && $equipo_lleno);
                    ?>
                    <div class="equipo-card">
                        <div class="equipo-header">
                            <div class="equipo-nombre"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></div>
                            <div class="equipo-deporte"><?php echo htmlspecialchars($equipo['nombre_deporte']); ?></div>
                        </div>
                        
                        <div class="equipo-body">
                            <div class="equipo-info">
                                <div class="info-item">
                                    <span class="info-label">Capitán:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($equipo['nombre_capitan'] . ' ' . $equipo['apellido_capitan']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Tu rol:</span>
                                    <span class="info-value" style="color: <?php echo $es_capitan ? '#ffd700' : '#43e97b'; ?>;">
                                        <?php echo $es_capitan ? '👑 Capitán' : '🤝 Jugador'; ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Estado:</span>
                                    <span class="info-value" style="color: <?php echo $equipo_lleno ? '#43e97b' : '#28a745'; ?>;">
                                        <?php echo $equipo_lleno ? 'Completo' : htmlspecialchars($equipo['Estado']); ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Fecha creación:</span>
                                    <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($equipo['Fecha_Creacion'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="jugadores-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%; background: <?php echo $equipo_lleno ? '#43e97b' : '#4facfe'; ?>;"></div>
                                </div>
                                <div class="progress-text">
                                    <span>Jugadores unidos</span>
                                    <span><strong><?php echo $equipo['jugadores_unidos']; ?></strong> de <?php echo $equipo['Cantidad']; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="equipo-footer">
                            <?php if ($mostrar_retar): ?>
                                <!-- Botón RETAR solo para capitán cuando el equipo está lleno -->
                              <a href="../Retar/selecciondeopcionestemp.php"<?php echo $equipo['Id_EquipoTemporal']; ?>" class="btn-retar">
    🏆 Retar
</a>
                            <?php else: ?>
                                <!-- Botón normal para jugadores o equipos no llenos -->
                                <button class="btn-ya-unido" disabled>
                                    <?php if ($es_capitan && !$equipo_lleno): ?>
                                        ⏳ Esperando jugadores (<?php echo $equipo['jugadores_unidos']; ?>/<?php echo $equipo['Cantidad']; ?>)
                                    <?php else: ?>
                                        ✅ Ya estás en este equipo
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <?php } else { ?>
                <div class="empty-state">
                    <div class="empty-icon">🤔</div>
                    <h2 class="empty-text">No estás en ningún equipo temporal</h2>
                    <p>Únete a un equipo disponible o crea uno nuevo</p>
                    <button class="btn" style="margin-top: 20px;" onclick="switchTab('disponibles')">Ver equipos disponibles</button>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <script>
        // Función para mostrar/ocultar sidebar en móviles
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }
        
        // Cerrar sidebar al hacer clic en un enlace (en móviles)
        document.querySelectorAll('.menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });
        
        // Ajustar sidebar según tamaño de pantalla
        window.addEventListener('resize', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.overlay');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });
        
        // Función para cambiar pestañas
        function switchTab(tabName) {
            // Ocultar todas las pestañas
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Desactivar todos los botones de pestaña
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar la pestaña seleccionada
            document.getElementById(tabName).classList.add('active');
            
            // Activar el botón de la pestaña seleccionada
            event.target.classList.add('active');
        }
        
        // Confirmación al unirse a un equipo
        document.querySelectorAll('.btn-unirse').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('¿Estás seguro de que quieres unirte a este equipo?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Confirmación al hacer clic en Retar
        document.querySelectorAll('.btn-retar').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('¿Estás seguro de que quieres retar con este equipo?')) {
                    e.preventDefault();
                }
            });
        });
    </script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>