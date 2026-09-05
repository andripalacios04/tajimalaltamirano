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

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once '../conexion.php'; 

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

// Variables para el formulario
$mostrarFormulario = false;
$equipoSeleccionado = null;
$idDeporteEquipo = '';
$nombreDeporte = '';
$idEquipoSeleccionado = '';
$nombreEquipoSeleccionado = '';

// Verificar si se seleccionó un equipo para retar
if (isset($_GET['equipo']) && !empty($_GET['equipo'])) {
    $idEquipoSeleccionado = $_GET['equipo'];
    
    // Obtener información del equipo seleccionado CON JOIN a deporte
    $sqlEquipo = "SELECT et.*, d.Nombre as nombre_deporte 
                  FROM equipo_temporal et
                  LEFT JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
                  WHERE et.Id_EquipoTemporal = ?";
    
    $stmt = $conn->prepare($sqlEquipo);
    $stmt->bind_param("s", $idEquipoSeleccionado);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($equipoSeleccionado = $result->fetch_assoc()) {
        $mostrarFormulario = true;
        $idDeporteEquipo = $equipoSeleccionado['Id_Deporte'];
        $nombreDeporte = isset($equipoSeleccionado['nombre_deporte']) ? $equipoSeleccionado['nombre_deporte'] : 'Sin nombre';
        $nombreEquipoSeleccionado = $equipoSeleccionado['Nombre'];
    }
    $stmt->close();
}

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_reta'])) {
    // Recibir datos del formulario
    $direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
    $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
    $hora = isset($_POST['hora']) ? trim($_POST['hora']) : '';
    $lugar = isset($_POST['lugar']) ? trim($_POST['lugar']) : '';
    $id_equipo1 = isset($_POST['id_equipo1']) ? trim($_POST['id_equipo1']) : '';
    $id_deporte = isset($_POST['id_deporte']) ? trim($_POST['id_deporte']) : '';
    
    // Validar datos requeridos
    if (empty($direccion) || empty($fecha) || empty($hora) || empty($lugar) || empty($id_equipo1) || empty($id_deporte)) {
        $error = "Por favor, completa todos los campos obligatorios.";
    } else {
        // Generar ID de reta: T + 2 números random + fecha (sin guiones)
        $fechaSinGuiones = str_replace('-', '', $fecha);
        $numerosRandom = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
        $id_reta = 'T' . $numerosRandom . $fechaSinGuiones;
        
        // Verificar que el ID no exista ya
        $sqlCheckId = "SELECT Id_Reta FROM reta WHERE Id_Reta = ?";
        $stmtCheck = $conn->prepare($sqlCheckId);
        $stmtCheck->bind_param("s", $id_reta);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        
        if ($stmtCheck->num_rows > 0) {
            // Si el ID ya existe, generar uno nuevo
            $numerosRandom = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
            $id_reta = 'T' . $numerosRandom . $fechaSinGuiones;
        }
        $stmtCheck->close();
        
        // Estado por defecto
        $estadoReta = 'En proceso';
        
        // Insertar en la tabla reta
        $sqlInsert = "INSERT INTO reta (Id_Reta, Direccion, EstadoReta, Fecha, Hora, Id_Deporte, Id_Equipo1, Id_Equipo2, Lugar) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)";
        
        $stmt = $conn->prepare($sqlInsert);
        $stmt->bind_param("ssssssss", $id_reta, $direccion, $estadoReta, $fecha, $hora, $id_deporte, $id_equipo1, $lugar);
        
        if ($stmt->execute()) {
            $success = "¡Reta creada exitosamente! ID: " . $id_reta;
            $mostrarFormulario = false; // Ocultar formulario después de éxito
        } else {
            $error = "Error al crear la reta: " . $conn->error;
        }
        $stmt->close();
    }
}

// Si no estamos mostrando el formulario, mostrar la lista de equipos
if (!$mostrarFormulario) {
    // Consulta para obtener equipos completos (código anterior)
    $equiposCompletos = [];
    $mensaje = '';
    
    if (!empty($Id_Retador)) {
        // PRIMERO: Obtener todos los equipos a los que pertenece el retador
        $sqlEquiposRetador = "SELECT DISTINCT ert.Id_EquipoTemporal 
                             FROM equiporetador_temporal ert 
                             WHERE ert.Id_Retador = ?";
        
        $stmt1 = $conn->prepare($sqlEquiposRetador);
        $stmt1->bind_param("s", $Id_Retador);
        $stmt1->execute();
        $result1 = $stmt1->get_result();
        
        $equiposDelRetador = [];
        while ($row = $result1->fetch_assoc()) {
            $equiposDelRetador[] = $row['Id_EquipoTemporal'];
        }
        $stmt1->close();
        
        // SEGUNDO: Para cada equipo, verificar si está completo
        if (!empty($equiposDelRetador)) {
            $placeholders = str_repeat('?,', count($equiposDelRetador) - 1) . '?';
            
            // Consulta con JOIN para obtener nombre del deporte
            $sql = "SELECT 
                        et.Id_EquipoTemporal,
                        et.Nombre AS nombre_equipo,
                        et.Id_Deporte,
                        d.Nombre as nombre_deporte,
                        et.Cantidad AS capacidad_maxima,
                        et.Estado,
                        et.Capitan,
                        (SELECT COUNT(*) 
                         FROM equiporetador_temporal ert2 
                         WHERE ert2.Id_EquipoTemporal = et.Id_EquipoTemporal) AS miembros_actuales
                    FROM equipo_temporal et
                    LEFT JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
                    WHERE et.Id_EquipoTemporal IN ($placeholders)";
            
            $stmt2 = $conn->prepare($sql);
            $types = str_repeat('s', count($equiposDelRetador));
            $stmt2->bind_param($types, ...$equiposDelRetador);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            
            while ($equipo = $result2->fetch_assoc()) {
                if ($equipo['miembros_actuales'] >= $equipo['capacidad_maxima']) {
                    $equiposCompletos[] = $equipo;
                }
            }
            $stmt2->close();
            
            if (count($equiposCompletos) === 0) {
                $mensaje = "Tienes " . count($equiposDelRetador) . " equipo(s), pero ninguno está completo.";
            }
        } else {
            $mensaje = "No perteneces a ningún equipo. Únete a un equipo primero.";
        }
    } else {
        $mensaje = "Error: No se pudo identificar tu ID de retador.";
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
    <title>Crear Reta - RETAME</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #140f27, #203a43, #1c2a92);
            color: #eeeeee;
            overflow-x: hidden;
        }
        
        /* SIDEBAR */
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
        
        /* CONTENIDO PRINCIPAL */
        .main-content {
            flex: 1;
            padding: 40px;
            margin-left: 250px;
            min-height: 100vh;
        }
        .header h1 {
            font-size: 32px;
            color: #ffffff;
            margin-bottom: 30px;
            text-align: center;
        }
        
        /* TARJETA DE CREAR RETA */
        .crearreta-card {
            background: rgba(27, 31, 39, 0.9);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0px 0px 30px rgba(0, 26, 255, 0.5);
            max-width: 1000px;
            margin: 50px auto;
            text-align: center;
            width: 100%;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        .crearreta-card h2 {
            color: #ff0000;
            margin-bottom: 30px;
            font-size: 28px;
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.3);
        }
        
        /* PANEL DE INFORMACIÓN */
        .info-panel {
            display: flex;
            justify-content: space-between;
            background: rgba(0, 40, 80, 0.3);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 30px;
            border: 1px solid rgba(0, 150, 255, 0.3);
        }
        
        .info-box {
            text-align: center;
            flex: 1;
        }
        
        .info-box .number {
            font-size: 2rem;
            font-weight: bold;
            color: #00ffc6;
            display: block;
        }
        
        .info-box .label {
            font-size: 0.9rem;
            color: #aaa;
        }
        
        /* FORMULARIO DE CREAR RETA */
        .formulario-reta {
            background: rgba(40, 45, 60, 0.9);
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
            text-align: left;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #00ffc6;
            font-weight: bold;
        }
        
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="time"],
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(0, 255, 198, 0.3);
            border-radius: 8px;
            color: white;
            font-size: 1rem;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .info-equipo {
            background: rgba(0, 100, 200, 0.2);
            border: 1px solid rgba(0, 150, 255, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .info-equipo h3 {
            color: #00ffc6;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .equipo-detalles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .detalle-item {
            background: rgba(0, 0, 0, 0.2);
            padding: 10px;
            border-radius: 8px;
            border-left: 3px solid #00ffc6;
        }
        
        .detalle-item strong {
            color: #ffffff;
            display: block;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .detalle-item span {
            color: #cccccc;
            font-size: 1rem;
        }
        
        /* BOTONES */
        .btn-submit {
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 1.1rem;
            width: 100%;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            background: linear-gradient(135deg, #e00000, #c00000);
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.7);
        }
        
        .btn-cancel {
            background: rgba(100, 100, 100, 0.5);
            color: white;
            border: 1px solid #666;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 15px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-cancel:hover {
            background: rgba(150, 150, 150, 0.5);
            transform: translateY(-2px);
        }
        
        /* MENSAJES */
        .alert-success {
            background: rgba(0, 200, 0, 0.2);
            border: 1px solid #4CAF50;
            color: #66ff99;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .alert-error {
            background: rgba(255, 0, 0, 0.2);
            border: 1px solid #ff0000;
            color: #ff9999;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .alert-message {
            background: rgba(255, 204, 0, 0.1);
            border: 1px solid rgba(255, 204, 0, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 30px;
            color: #ffcc00;
            text-align: left;
        }
        
        /* LISTA DE EQUIPOS */
        .equipos-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .equipo-card {
            background: rgba(40, 45, 60, 0.9);
            border-radius: 15px;
            padding: 25px;
            border: 2px solid rgba(0, 255, 198, 0.3);
            transition: all 0.3s ease;
            text-align: left;
            position: relative;
        }
        
        .equipo-card.completo {
            border-color: #4CAF50;
            box-shadow: 0 0 15px rgba(76, 175, 80, 0.3);
        }
        
        .equipo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .badge-completo {
            background: #4CAF50;
            color: white;
        }
        
        .equipo-card h3 {
            color: #00ffc6;
            margin-bottom: 15px;
            font-size: 1.3rem;
            padding-right: 70px;
        }
        
        .equipo-info {
            margin: 15px 0;
            font-size: 0.95rem;
            color: #cccccc;
        }
        
        .equipo-info strong {
            color: #ffffff;
        }
        
        .btn-crear {
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 20px;
            width: 100%;
            transition: all 0.3s ease;
            font-size: 1rem;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        
        .btn-crear:hover {
            background: linear-gradient(135deg, #e00000, #c00000);
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.7);
        }
        
        /* SIN EQUIPOS */
        .no-equipos {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid rgba(255, 0, 0, 0.3);
            border-radius: 15px;
            padding: 40px;
            margin: 30px 0;
            color: #ff9999;
            text-align: center;
        }
        
        .action-links {
            margin-top: 20px;
        }
        
        .action-links a {
            display: inline-block;
            background: rgba(0, 255, 198, 0.2);
            color: #00ffc6;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            margin: 0 10px;
            border: 1px solid rgba(0, 255, 198, 0.3);
            transition: all 0.3s ease;
        }
        
        .action-links a:hover {
            background: rgba(0, 255, 198, 0.4);
            transform: translateY(-2px);
        }
        
        .success-message {
            background: rgba(0, 200, 0, 0.1);
            border: 1px solid rgba(0, 200, 0, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 30px;
            color: #66ff99;
            text-align: left;
        }
        
        /* RESPONSIVE */
        @media screen and (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }
            .main-content {
                margin-left: 200px;
                padding: 20px;
            }
            .crearreta-card {
                margin: 30px auto;
                padding: 30px;
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
                padding: 20px;
            }
            .crearreta-card {
                margin: 20px auto;
                padding: 25px;
            }
            .info-panel {
                flex-direction: column;
                gap: 15px;
            }
            .equipo-detalles {
                grid-template-columns: 1fr;
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
            .equipos-container {
                grid-template-columns: 1fr;
            }
            .action-links a {
                display: block;
                margin: 10px 0;
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Reta - RETAME'); } ?>

    <!-- Botón hamburguesa solo para móviles -->
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Overlay para cerrar sidebar en móviles -->
    <div class="overlay" onclick="toggleSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <img src="assets/doctor.png" class="doctor-logo" alt="Logo Doctor">
        <h2>🏥 RETAME</h2>
        <ul class="menu">
            <li><a href="dashboard.html">🏠 Inicio</a></li>
            <li><a href="crearretatemp.php">🔥 Crear Reta</a></li>
            <li><a href="mis_citas.html">📋 Mis Retas</a></li>
            <li><a href="Configurar mi perfil.php">👤 Configurar Perfil</a></li> 
            <li><a href="unirme_equipo.php">👥 Unirme a un equipo</a></li> 
            <li><a href="logout.php">🚪 Cerrar Sesión</a></li> 
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>🔥 Crear Nueva Reta</h1>
        </div>
        
        <div class="crearreta-card">
            <?php if ($mostrarFormulario): ?>
                <!-- FORMULARIO PARA CREAR RETA -->
                <h2>Completa los detalles de la reta</h2>
                
                <?php if (isset($error)): ?>
                    <div class="alert-error">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert-success">
                        <?php echo $success; ?>
                        <br><br>
                        <a href="crearretatemp.php" class="btn-cancel">Volver a crear otra reta</a>
                    </div>
                <?php else: ?>
                
                <div class="info-equipo">
                    <h3>📋 Información del equipo seleccionado</h3>
                    <div class="equipo-detalles">
                        <div class="detalle-item">
                            <strong>🏆 Equipo:</strong>
                            <span><?php echo htmlspecialchars($nombreEquipoSeleccionado); ?></span>
                        </div>
                        <div class="detalle-item">
                            <strong>⚽ Deporte:</strong>
                            <span><?php echo htmlspecialchars($nombreDeporte); ?></span>
                        </div>
                        <div class="detalle-item">
                            <strong>🆔 ID Equipo:</strong>
                            <span><?php echo htmlspecialchars($idEquipoSeleccionado); ?></span>
                        </div>
                        <div class="detalle-item">
                            <strong>🆔 ID Deporte:</strong>
                            <span><?php echo htmlspecialchars($idDeporteEquipo); ?></span>
                        </div>
                    </div>
                </div>
                
                <form method="POST" action="" class="formulario-reta">
                    <div class="form-group">
                        <label for="direccion">📍 Dirección:</label>
                        <input type="text" id="direccion" name="direccion" 
                               placeholder="Ej: Calle Principal #123, Colonia Centro" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="lugar">🏟️ Lugar (nombre específico):</label>
                        <input type="text" id="lugar" name="lugar" 
                               placeholder="Ej: Cancha 'La Fortaleza', Parque Central, Gimnasio Municipal" 
                               required>
                    </div>
                    
                    <div class="form-row" style="display: flex; gap: 20px;">
                        <div class="form-group" style="flex: 1;">
                            <label for="fecha">📅 Fecha:</label>
                            <input type="date" id="fecha" name="fecha" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group" style="flex: 1;">
                            <label for="hora">⏰ Hora:</label>
                            <input type="time" id="hora" name="hora" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="observaciones">📝 Observaciones (opcional):</label>
                        <textarea id="observaciones" name="observaciones" 
                                  placeholder="Detalles adicionales, reglas específicas, requisitos, etc."></textarea>
                    </div>
                    
                    <!-- Campos ocultos con datos del equipo -->
                    <input type="hidden" name="id_equipo1" value="<?php echo htmlspecialchars($idEquipoSeleccionado); ?>">
                    <input type="hidden" name="id_deporte" value="<?php echo htmlspecialchars($idDeporteEquipo); ?>">
                    <input type="hidden" name="crear_reta" value="1">
                    
                    <button type="submit" class="btn-submit">
                        🏆 Crear Reta
                    </button>
                    
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="crearretatemp.php" class="btn-cancel">❌ Cancelar y volver</a>
                    </div>
                </form>
                
                <?php endif; ?>
                
            <?php else: ?>
                <!-- LISTA DE EQUIPOS COMPLETOS -->
                <h2>Selecciona un equipo para retar</h2>
                
                <?php if (isset($success)): ?>
                    <div class="alert-success">
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <div class="info-panel">
                    <div class="info-box">
                        <span class="number"><?php echo isset($equiposDelRetador) ? count($equiposDelRetador) : 0; ?></span>
                        <span class="label">Equipos Totales</span>
                    </div>
                    <div class="info-box">
                        <span class="number"><?php echo count($equiposCompletos); ?></span>
                        <span class="label">Equipos Completos</span>
                    </div>
                </div>
                
                <?php if (!empty($mensaje)): ?>
                    <div class="alert-error">
                        <strong>⚠️ Información:</strong> <?php echo $mensaje; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($equiposCompletos)): ?>
                    <div class="no-equipos">
                        <h3>😕 No hay equipos completos disponibles</h3>
                        <p>Para crear una reta, necesitas estar en al menos un equipo que tenga todos sus miembros.</p>
                        
                        <div class="action-links">
                            <a href="unirme_equipo.php">👥 Unirme a un equipo</a>
                            <a href="dashboard.html">🏠 Volver al inicio</a>
                        </div>
                    </div>
                <?php else: ?>
                
                <div class="success-message">
                    <strong>✅ ¡Perfecto!</strong> Tienes <strong><?php echo count($equiposCompletos); ?></strong> equipo(s) completo(s) listo(s) para retar.
                </div>
                
                <div class="equipos-container">
                    <?php foreach ($equiposCompletos as $equipo): 
                        $porcentaje = ($equipo['miembros_actuales'] / $equipo['capacidad_maxima']) * 100;
                    ?>
                    <div class="equipo-card completo">
                        <div class="badge badge-completo">COMPLETO</div>
                        
                        <h3><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></h3>
                        
                        <div class="equipo-info">
                            <p><strong>⚽ Deporte:</strong> <?php echo htmlspecialchars($equipo['nombre_deporte'] ?? 'Sin nombre'); ?></p>
                            <p><strong>👑 Capitán:</strong> <?php echo htmlspecialchars($equipo['Capitan']); ?></p>
                            <p><strong>👥 Miembros:</strong> <?php echo $equipo['miembros_actuales']; ?>/<?php echo $equipo['capacidad_maxima']; ?></p>
                            <p><strong>📊 Estado:</strong> <span style="color: #4CAF50;"><?php echo htmlspecialchars($equipo['Estado']); ?></span></p>
                        </div>
                        
                        <a href="crearretatemp.php?equipo=<?php echo urlencode($equipo['Id_EquipoTemporal']); ?>" 
                           class="btn-crear">
                            🏆 Crear Reta con este equipo
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php endif; ?>
            <?php endif; ?>
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
        
        // Configurar fecha mínima como hoy
        document.addEventListener('DOMContentLoaded', function() {
            const fechaInput = document.getElementById('fecha');
            if (fechaInput) {
                const today = new Date().toISOString().split('T')[0];
                fechaInput.min = today;
                
                // Si no hay valor, establecer fecha por defecto (mañana)
                if (!fechaInput.value) {
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    fechaInput.value = tomorrow.toISOString().split('T')[0];
                }
            }
            
            // Configurar hora por defecto (6:00 PM)
            const horaInput = document.getElementById('hora');
            if (horaInput && !horaInput.value) {
                horaInput.value = '18:00';
            }
            
            // Confirmación al hacer clic en el botón de crear reta
            const crearRetaLinks = document.querySelectorAll('a.btn-crear');
            crearRetaLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const equipoNombre = this.closest('.equipo-card').querySelector('h3').textContent;
                    const deporteNombre = this.closest('.equipo-card').querySelector('.equipo-info p:nth-child(1) span').textContent;
                    
                    if (!confirm(`¿Crear reta con el equipo "${equipoNombre}"?\n\nDeporte: ${deporteNombre}\n\nSerás redirigido al formulario para completar los detalles.`)) {
                        e.preventDefault();
                    }
                });
            });
            
            // Validación del formulario antes de enviar
            const formulario = document.querySelector('form.formulario-reta');
            if (formulario) {
                formulario.addEventListener('submit', function(e) {
                    const fecha = document.getElementById('fecha').value;
                    const hora = document.getElementById('hora').value;
                    
                    if (fecha && hora) {
                        const fechaHora = new Date(fecha + 'T' + hora);
                        const ahora = new Date();
                        
                        if (fechaHora < ahora) {
                            e.preventDefault();
                            alert('⚠️ La fecha y hora deben ser futuras. No puedes crear una reta en el pasado.');
                            return false;
                        }
                    }
                    
                    return confirm('¿Estás seguro de crear esta reta?\n\nUna vez creada, no podrás modificarla.');
                });
            }
        });
    </script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>