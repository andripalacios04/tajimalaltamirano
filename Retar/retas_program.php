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
   FUNCIÓN PARA OBTENER LOS EQUIPOS DEL USUARIO
========================= */
function obtenerEquiposUsuario($conexion, $id_retador_actual) {
    $sql = "SELECT DISTINCT Id_Equipo 
            FROM equipo_jugador 
            WHERE Id_Jugador = '$id_retador_actual'";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    $equipos = [];
    while ($row = mysqli_fetch_assoc($resultado)) {
        $equipos[] = $row['Id_Equipo'];
    }
    
    return $equipos;
}

/* =========================
   FUNCIÓN PARA OBTENER RETAS DEL USUARIO
========================= */
function obtenerRetasProgramadas($conexion, $id_retador_actual) {
    // Primero obtener los equipos del usuario
    $equipos_usuario = obtenerEquiposUsuario($conexion, $id_retador_actual);
    
    if (empty($equipos_usuario)) {
        return []; // No tiene equipos, no hay retas
    }
    
    // Convertir array de equipos a string para la consulta SQL
    $equipos_str = "'" . implode("','", $equipos_usuario) . "'";
    
    $sql = "SELECT 
                rp.id_retaprogramada,
                rp.fecha,
                rp.hora,
                rp.lugar,
                rp.direccion,
                rp.estado_reta,
                
                -- Información del equipo 1
                rp.id_equipo1,
                e1.Nombre as equipo1_nombre,
                e1.Capitan as equipo1_capitan_id,
                r1.Nombre as equipo1_capitan_nombre,
                r1.Apellido as equipo1_capitan_apellido,
                
                -- Información del equipo 2
                rp.id_equipo2,
                e2.Nombre as equipo2_nombre,
                e2.Capitan as equipo2_capitan_id,
                r2.Nombre as equipo2_capitan_nombre,
                r2.Apellido as equipo2_capitan_apellido,
                
                -- Verificar si el usuario está en equipo1
                CASE WHEN rp.id_equipo1 IN ($equipos_str) THEN 1 ELSE 0 END as en_equipo1,
                
                -- Verificar si el usuario está en equipo2
                CASE WHEN rp.id_equipo2 IN ($equipos_str) THEN 1 ELSE 0 END as en_equipo2
                
            FROM retas_programadas rp
            LEFT JOIN equipo e1 ON rp.id_equipo1 = e1.Id_Equipo
            LEFT JOIN equipo e2 ON rp.id_equipo2 = e2.Id_Equipo
            LEFT JOIN retador r1 ON e1.Capitan = r1.Id_Retador
            LEFT JOIN retador r2 ON e2.Capitan = r2.Id_Retador
            
            -- Filtrar retas donde el usuario está en alguno de los equipos
            WHERE rp.id_equipo1 IN ($equipos_str) 
               OR rp.id_equipo2 IN ($equipos_str)
            
            ORDER BY rp.fecha DESC, rp.hora DESC";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    return $resultado;
}

/* =========================
   FUNCIÓN PARA CONTAR RETAS POR ESTADO
========================= */
function contarRetasPorEstado($conexion, $id_retador_actual) {
    // Primero obtener los equipos del usuario
    $equipos_usuario = obtenerEquiposUsuario($conexion, $id_retador_actual);
    
    if (empty($equipos_usuario)) {
        return []; // No tiene equipos, no hay retas
    }
    
    // Convertir array de equipos a string para la consulta SQL
    $equipos_str = "'" . implode("','", $equipos_usuario) . "'";
    
    $sql = "SELECT 
                rp.estado_reta,
                COUNT(*) as total
            FROM retas_programadas rp
            WHERE rp.id_equipo1 IN ($equipos_str) 
               OR rp.id_equipo2 IN ($equipos_str)
            GROUP BY rp.estado_reta";
    
    $resultado = mysqli_query($conexion, $sql);
    $estados = [];
    
    if ($resultado) {
        while ($row = mysqli_fetch_assoc($resultado)) {
            $estados[$row['estado_reta']] = $row['total'];
        }
    }
    
    return $estados;
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
    <title>Mis Retas Programadas - RETAME</title>
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
        
        /* CONTENEDOR PRINCIPAL */
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
        
        /* DEBUG INFO (temporal para verificar) */
        .debug-info {
            background: rgba(255, 0, 0, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 0, 0, 0.3);
            font-size: 0.9em;
            color: #ff9999;
        }
        
        /* CONTADORES DE ESTADO */
        .estados-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .estado-card {
            background: rgba(40, 44, 52, 0.7);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid rgba(0, 26, 255, 0.2);
            transition: all 0.3s ease;
        }
        
        .estado-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 26, 255, 0.3);
        }
        
        .estado-titulo {
            font-size: 1em;
            color: #a0a0a0;
            margin-bottom: 10px;
        }
        
        .estado-valor {
            font-size: 2em;
            font-weight: bold;
            color: #ffffff;
        }
        
        /* TARJETA DE RETA DETALLADA */
        .reta-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 26, 255, 0.2);
            transition: all 0.3s ease;
        }
        
        .reta-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 26, 255, 0.3);
            border-color: rgba(255, 0, 0, 0.5);
        }
        
        .reta-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .reta-id {
            font-weight: bold;
            color: #4facfe;
            font-size: 1.1em;
        }
        
        .reta-fecha-hora {
            display: flex;
            gap: 20px;
            color: #a0a0a0;
        }
        
        .reta-equipos {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 20px;
            align-items: center;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .equipo-info {
            padding: 15px;
            background: rgba(40, 44, 52, 0.5);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .equipo-nombre {
            font-size: 1.2em;
            font-weight: bold;
            margin-bottom: 5px;
            color: #ffffff;
        }
        
        .equipo-captian {
            font-size: 0.9em;
            color: #a0a0a0;
        }
        
        .vs-badge {
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            padding: 10px 20px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 1.2em;
        }
        
        .reta-detalles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .detalle-item {
            display: flex;
            flex-direction: column;
        }
        
        .detalle-label {
            color: #a0a0a0;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .detalle-valor {
            color: #ffffff;
            font-weight: 500;
        }
        
        .mi-equipo-badge {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
            color: #000;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* ESTILOS DE ESTADO */
        .estado-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            display: inline-block;
            margin-left: 10px;
        }
        
        .estado-Pendiente, .estado-pendiente {
            background: linear-gradient(135deg, #ffd166, #ffb347);
            color: #000;
        }
        
        .estado-Confirmada, .estado-confirmada {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
            color: #000;
        }
        
        .estado-Cancelada, .estado-cancelada {
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
        }
        
        .estado-Completada, .estado-completada {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #000;
        }
        
        .estado-Programada, .estado-programada {
            background: linear-gradient(135deg, #9d4edd, #560bad);
            color: white;
        }
        
        /* VISTA SIN RETAS */
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
        
        /* BOTONES */
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
        
        /* RESPONSIVE */
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
            .reta-equipos {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .vs-badge {
                order: 2;
                margin: 10px 0;
            }
            .equipo-info:first-child {
                order: 1;
            }
            .equipo-info:last-child {
                order: 3;
            }
            .reta-header {
                flex-direction: column;
                gap: 10px;
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
            .page-header h1 {
                font-size: 2em;
            }
            .estados-container {
                grid-template-columns: 1fr;
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Mis Retas Programadas - RETAME'); } ?>

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
            <li><a href="mis_retas.php">📋 Mis Retas</a></li>
            <li><a href="../Configurar mi perfil.php">👤 Configurar Perfil</a></li> 
            <li><a href="equipos_disponibles.php">👥 Equipos Disponibles</a></li> 
            <li><a href="../lougout.php">🚪 Cerrar Sesión</a></li> 
        </ul>
    </div>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>📋 Mis Retas Programadas</h1>
                <p class="subtitle">Consulta todas las retas en las que participas</p>
            </div>
            
            <div class="controls">
                <a href="../Perfil2.php" class="btn btn-secondary">← Volver al Perfil</a>
                <a href="../agendar.html" class="btn">➕ Agendar Nueva Reta</a>
            </div>
            
            <?php
            // DEBUG: Mostrar información para diagnóstico
         
            
            echo '</div>';
            
            // Obtener contadores por estado
            $contadores_estado = contarRetasPorEstado($conexion, $id_retador_actual);
            $total_retas = array_sum($contadores_estado);
            ?>
            
            <!-- CONTADORES POR ESTADO -->
            <div class="estados-container">
                <div class="estado-card">
                    <div class="estado-titulo">Total de Retas</div>
                    <div class="estado-valor"><?php echo $total_retas; ?></div>
                </div>
                
                <?php foreach ($contadores_estado as $estado => $total): ?>
                <div class="estado-card">
                    <div class="estado-titulo"><?php echo htmlspecialchars($estado); ?></div>
                    <div class="estado-valor"><?php echo $total; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- LISTA DE RETAS -->
            <?php
            $retas = obtenerRetasProgramadas($conexion, $id_retador_actual);
            
            if (mysqli_num_rows($retas) > 0) {
            ?>
            <div class="retas-lista">
                <?php while ($reta = mysqli_fetch_assoc($retas)) { 
                    // Determinar en qué equipo está el usuario
                    $en_equipo1 = $reta['en_equipo1'] == 1;
                    $en_equipo2 = $reta['en_equipo2'] == 1;
                    $mi_equipo = $en_equipo1 ? 'Equipo 1' : ($en_equipo2 ? 'Equipo 2' : '');
                    
                    // Formatear fecha y hora
                    $fecha_formateada = date('d/m/Y', strtotime($reta['fecha']));
                    $hora_formateada = date('H:i', strtotime($reta['hora']));
                    
                    // Determinar clase CSS para el estado
                    $estado_lower = strtolower($reta['estado_reta']);
                    $clase_estado = 'estado-' . $estado_lower;
                ?>
                <div class="reta-card">
                    <div class="reta-header">
                        <div class="reta-id">
                            Reta #<?php echo substr($reta['id_retaprogramada'], 0, 8); ?>
                            <span class="estado-badge <?php echo $clase_estado; ?> estado-<?php echo htmlspecialchars($reta['estado_reta']); ?>">
                                <?php echo htmlspecialchars($reta['estado_reta']); ?>
                            </span>
                        </div>
                        <div class="reta-fecha-hora">
                            <span>📅 <?php echo $fecha_formateada; ?></span>
                            <span>⏰ <?php echo $hora_formateada; ?></span>
                        </div>
                    </div>
                    
                    <div class="reta-equipos">
                        <div class="equipo-info">
                            <div class="equipo-nombre">
                                <?php echo htmlspecialchars($reta['equipo1_nombre'] ?? 'Equipo 1'); ?>
                                <?php if ($en_equipo1): ?>
                                    <span class="mi-equipo-badge">Mi equipo</span>
                                <?php endif; ?>
                            </div>
                            <div class="equipo-captian">
                                Capitán: <?php echo htmlspecialchars($reta['equipo1_capitan_nombre'] ?? 'N/A'); ?> 
                                <?php echo htmlspecialchars($reta['equipo1_capitan_apellido'] ?? ''); ?>
                            </div>
                        </div>
                        
                        <div class="vs-badge">VS</div>
                        
                        <div class="equipo-info">
                            <div class="equipo-nombre">
                                <?php echo htmlspecialchars($reta['equipo2_nombre'] ?? 'Equipo 2'); ?>
                                <?php if ($en_equipo2): ?>
                                    <span class="mi-equipo-badge">Mi equipo</span>
                                <?php endif; ?>
                            </div>
                            <div class="equipo-captian">
                                Capitán: <?php echo htmlspecialchars($reta['equipo2_capitan_nombre'] ?? 'N/A'); ?> 
                                <?php echo htmlspecialchars($reta['equipo2_capitan_apellido'] ?? ''); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="reta-detalles">
                        <div class="detalle-item">
                            <span class="detalle-label">📍 Lugar:</span>
                            <span class="detalle-valor"><?php echo htmlspecialchars($reta['lugar'] ?? 'Por definir'); ?></span>
                        </div>
                        
                        <div class="detalle-item">
                            <span class="detalle-label">🏠 Dirección:</span>
                            <span class="detalle-valor"><?php echo htmlspecialchars($reta['direccion'] ?? 'Por definir'); ?></span>
                        </div>
                        
                        <div class="detalle-item">
                            <span class="detalle-label">👤 Tu equipo:</span>
                            <span class="detalle-valor" style="color: #43e97b; font-weight: bold;">
                                <?php echo $mi_equipo ?: 'No identificado'; ?>
                            </span>
                        </div>
                        
                        <div class="detalle-item">
                            <span class="detalle-label">📋 ID Reta:</span>
                            <span class="detalle-valor"><?php echo htmlspecialchars($reta['id_retaprogramada']); ?></span>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php } else { ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h2 class="empty-text">No tienes retas programadas</h2>
                <p>
                    <?php if (empty($equipos_usuario)): ?>
                        Primero necesitas unirte a un equipo para poder participar en retas.
                    <?php else: ?>
                        No se encontraron retas programadas para tus equipos.
                    <?php endif; ?>
                </p>
                <?php if (empty($equipos_usuario)): ?>
                    <a href="equipos_disponibles.php" class="btn" style="margin-top: 20px;">Unirme a un equipo</a>
                <?php else: ?>
                    <a href="../agendar.html" class="btn" style="margin-top: 20px;">Agendar mi primera reta</a>
                <?php endif; ?>
            </div>
            <?php } ?>
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
    </script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>