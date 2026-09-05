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

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

// Variables
$solicitudesEquipos = [];
$mensajeError = '';
$mensajeExito = '';
$id_liga_admin = '';
$id_adminsolicitud = '';

// Procesar aceptar solicitud
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aceptar_solicitud'])) {
    $id_solicitud = $_POST['id_solicitud'];
    $id_liga = $_POST['id_liga'];
    $id_equipo = $_POST['id_equipo'];
    
    // 1. Actualizar estado de la solicitud a 'Aceptada'
    $sql_update_solicitud = "UPDATE solicitudes SET estado = 'Aceptada' WHERE id_solicitud = ?";
    $stmt_update = $conn->prepare($sql_update_solicitud);
    $stmt_update->bind_param("s", $id_solicitud);
    
    if ($stmt_update->execute()) {
        // 2. Crear ID para liga_equipo
        $id_liga_equipo = $id_liga . '_' . $id_equipo;
        
        // 3. Insertar en liga_equipo
        $sql_insert_liga_equipo = "INSERT INTO liga_equipo 
                                 (Id_LigaEquipo, Id_Liga, Id_Equipo, FechaInscripcion, Estado) 
                                 VALUES (?, ?, ?, CURDATE(), 'Activo')";
        
        $stmt_insert = $conn->prepare($sql_insert_liga_equipo);
        $stmt_insert->bind_param("sss", $id_liga_equipo, $id_liga, $id_equipo);
        
        if ($stmt_insert->execute()) {
            $mensajeExito = "✅ Solicitud aceptada. El equipo ha sido registrado en la liga.";
        } else {
            $mensajeError = "Error al registrar equipo en la liga: " . $conn->error;
        }
        $stmt_insert->close();
    } else {
        $mensajeError = "Error al actualizar solicitud: " . $conn->error;
    }
    $stmt_update->close();
}

// Consultar las ligas que el usuario administra
if (!empty($Id_Retador)) {
    // 1. Consultar en AdminSolicitud si el usuario está registrado y activo
    $sql_admin = "SELECT id_admin, id_adminsolicitud 
                  FROM adminsolicitud 
                  WHERE id_retador = ? 
                  AND Estado = 'Activo'";
    
    $stmt_admin = $conn->prepare($sql_admin);
    if ($stmt_admin) {
        $stmt_admin->bind_param("s", $Id_Retador);
        $stmt_admin->execute();
        $res_admin = $stmt_admin->get_result();
        
        if ($res_admin->num_rows > 0) {
            $admin_data = $res_admin->fetch_assoc();
            $id_liga_admin = $admin_data['id_admin'];
            $id_adminsolicitud = $admin_data['id_adminsolicitud'];
            
            // 2. Consultar en Solicitudes si hay solicitudes para esta liga con estado 'En proceso'
            $sql_solicitudes = "SELECT s.*, 
                               COALESCE(e.Nombre, 'Sin nombre') as NombreEquipo,
                               COALESCE(e.Capitan, 'No definido') as Capitan,
                               COALESCE(e.cantidad, 0) as cantidad,
                               COALESCE(e.CodigoPostal, 'No definido') as CodigoPostal,
                               COALESCE(e.Pais, 'No definido') as Pais,
                               COALESCE(e.Asistente1, 'No definido') as Asistente1,
                               COALESCE(e.Asistente2, 'No definido') as Asistente2,
                               COALESCE(e.Asistente3, 'No definido') as Asistente3,
                               COALESCE(e.Asistente4, 'No definido') as Asistente4,
                               COALESCE(e.Entrenador, 'No definido') as Entrenador,
                               COALESCE(d.Nombre, 'Desconocido') as NombreDeporte,
                               COALESCE(r.Nombre, 'Desconocido') as NombreCapitan,
                               COALESCE(r.Apellido, '') as ApellidoCapitan,
                               COALESCE(r.Edad, 0) as EdadCapitan
                        FROM solicitudes s
                        LEFT JOIN equipo e ON s.id_solicitante = e.Id_Equipo
                        LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte
                        LEFT JOIN retador r ON e.Capitan = r.Id_Retador
                        WHERE s.id_areadesolicitud = ?
                        AND s.estado = 'En proceso'
                        ORDER BY s.fecha DESC";
            
            $stmt_solicitudes = $conn->prepare($sql_solicitudes);
            if ($stmt_solicitudes) {
                $stmt_solicitudes->bind_param("s", $id_liga_admin);
                $stmt_solicitudes->execute();
                $res_solicitudes = $stmt_solicitudes->get_result();
                
                while ($solicitud = $res_solicitudes->fetch_assoc()) {
                    $solicitudesEquipos[] = $solicitud;
                }
                
                if (empty($solicitudesEquipos)) {
                    $mensajeInfo = "No hay solicitudes en proceso para tu liga.";
                }
                $stmt_solicitudes->close();
            }
        } else {
            $mensajeError = "⚠️ No tienes ligas activas para administrar.";
        }
        $stmt_admin->close();
    }
} else {
    $mensajeError = "⚠️ Usuario no identificado.";
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
<title>Solicitudes de Equipos - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- Agregar jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Font Awesome para iconos -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ========== CSS BASE (IGUAL AL DE TU PERFIL) ========== */
* { 
    margin:0; 
    padding:0; 
    box-sizing:border-box; 
    font-family:'Poppins', sans-serif; 
}

body { 
    display:flex; 
    min-height:100vh; 
    background:linear-gradient(135deg,#140f27,#203a43,#1c2a92); 
    color:#eee; 
    overflow-x:hidden; 
    position: relative;
}

/* BURBUJAS/PARTÍCULAS DE FONDO */
.bg-particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
    overflow: hidden;
}

.particle {
    position: absolute;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    animation: float 20s infinite linear;
}

@keyframes float {
    0%, 100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-100vh) rotate(180deg);
    }
}

/* SIDEBAR */
.sidebar { 
    width:250px; 
    background:#111820; 
    padding:20px; 
    display:flex; 
    flex-direction:column; 
    align-items:center; 
    box-shadow:5px 0 20px rgba(9,5,138,0.7);
    position:fixed;
    height:100vh;
    z-index:1000;
}

.sidebar h2 { 
    color:#fff; 
    margin-bottom:20px; 
    text-align:center;
    font-size:18px;
}

.sidebar img { 
    width:90px; 
    height:90px;
    margin-bottom:10px; 
    border-radius:50%;
    border:3px solid #00ffc6;
}

.menu { 
    list-style:none; 
    width:100%; 
    margin-top:20px;
}

.menu li { 
    padding:12px; 
    margin:10px 0; 
    border-radius:8px; 
    background:rgba(0,255,198,0.1); 
    text-align:center; 
    transition:all 0.3s ease;
    border:1px solid rgba(0,255,198,0.2);
}

.menu li a { 
    color:#fff; 
    text-decoration:none; 
    font-weight:bold; 
    display:block; 
    font-size:14px;
}

.menu li:hover { 
    background:rgba(244,16,16,0.3); 
    transform:translateX(5px);
    border-color:rgba(244,16,16,0.5);
}

/* CONTENIDO PRINCIPAL */
.main-content { 
    flex:1; 
    padding:40px; 
    margin-left:250px;
    min-height:100vh;
    position: relative;
    z-index: 1;
}

.main-content h1 {
    font-size:32px;
    color:#ffffff;
    margin-bottom:30px;
    text-align:center;
    text-shadow: 0 0 10px rgba(0, 255, 198, 0.5);
}

/* ALERTAS */
.alert {
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 30px;
    animation: fadeIn 0.5s ease;
    text-align: center;
}

.alert-error {
    background: rgba(255, 0, 0, 0.1);
    border-left: 4px solid #ff0000;
    color: #ff6b6b;
}

.alert-success {
    background: rgba(0, 255, 0, 0.1);
    border-left: 4px solid #00ff00;
    color: #aaffaa;
}

.alert-info {
    background: rgba(0, 100, 255, 0.1);
    border-left: 4px solid #0064ff;
    color: #64b5f6;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* SECCIÓN DE SOLICITUDES */
.solicitudes-section {
    background: rgba(27, 31, 39, 0.9);
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 0 30px rgba(0, 26, 255, 0.5);
    max-width: 1200px;
    margin: 0 auto 40px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 26, 255, 0.2);
}

.solicitudes-section h2 {
    color: #00ffc6;
    margin-bottom: 30px;
    font-size: 28px;
    text-align: center;
    border-bottom: 2px solid rgba(0, 255, 198, 0.3);
    padding-bottom: 15px;
}

/* ENCABEZADO DE ADMINISTRADOR */
.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 25px;
    background: rgba(17, 24, 32, 0.8);
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.info-admin h3 {
    color: #00ffc6;
    margin-bottom: 10px;
    font-size: 20px;
}

.info-admin p {
    color: #aaaaaa;
    font-size: 14px;
    margin-bottom: 5px;
}

.contador-solicitudes {
    font-size: 18px;
    color: #00ffc6;
}

.contador-solicitudes .numero {
    font-size: 28px;
    font-weight: bold;
    background: linear-gradient(135deg, #00ffc6, #00cc9d);
    padding: 8px 20px;
    border-radius: 10px;
    margin-left: 10px;
}

/* CONTENEDOR DE TARJETAS DE SOLICITUD */
.solicitudes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 25px;
    margin-top: 20px;
}

/* TARJETA DE SOLICITUD */
.solicitud-card {
    background: rgba(17, 24, 32, 0.9);
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border-left: 4px solid #ff9800;
}

.solicitud-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
    background: rgba(17, 24, 32, 1);
}

/* ENCABEZADO DE TARJETA */
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.equipo-info h3 {
    color: #00ffc6;
    font-size: 22px;
    margin-bottom: 8px;
}

.deporte-tag {
    display: inline-block;
    background: rgba(0, 26, 255, 0.3);
    color: #00ffc6;
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

.fecha-solicitud {
    color: #aaaaaa;
    font-size: 14px;
    background: rgba(255, 255, 255, 0.05);
    padding: 6px 12px;
    border-radius: 10px;
}

/* CONTENIDO DE TARJETA */
.card-content {
    margin-bottom: 25px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
}

.info-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-label {
    color: #aaaaaa;
    font-weight: 500;
    font-size: 14px;
}

.info-value {
    color: #ffffff;
    font-weight: 600;
    text-align: right;
    font-size: 14px;
}

.info-value.no-definido {
    color: #ff9800;
    font-style: italic;
}

/* BOTÓN DE ACEPTAR */
.card-actions {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}

.btn-aceptar {
    flex: 1;
    padding: 15px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: linear-gradient(135deg, #4CAF50, #388E3C);
    color: white;
}

.btn-aceptar:hover {
    background: linear-gradient(135deg, #388E3C, #2E7D32);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(76, 175, 80, 0.4);
}

/* SIN SOLICITUDES */
.no-solicitudes {
    text-align: center;
    padding: 60px 20px;
    background: rgba(17, 24, 32, 0.8);
    border-radius: 15px;
    margin-top: 40px;
}

.no-solicitudes i {
    font-size: 60px;
    color: #aaaaaa;
    margin-bottom: 20px;
}

.no-solicitudes h3 {
    color: #ffffff;
    margin-bottom: 15px;
    font-size: 24px;
}

.no-solicitudes p {
    color: #aaaaaa;
    font-size: 16px;
    max-width: 600px;
    margin: 0 auto 25px;
    line-height: 1.6;
}

.info-box {
    margin-top: 30px;
    padding: 25px;
    background: rgba(0, 255, 198, 0.1);
    border-radius: 10px;
    border-left: 4px solid #00ffc6;
}

.info-box h4 {
    color: #00ffc6;
    margin-bottom: 15px;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-box p {
    color: #aaaaaa;
    margin-bottom: 8px;
}

/* BOTONES FLOTANTES */
.btn-flotante {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg,#ff0000,#e00000);
    color: #fff;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 16px;
    font-weight: bold;
    text-decoration: none;
    box-shadow: 0 0 20px rgba(255,0,0,0.7);
    transition: all 0.3s ease;
    z-index: 999;
    border: 2px solid rgba(255,255,255,0.3);
}

.btn-ligas {
    position: fixed;
    bottom: 130px;
    right: 20px;
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg,#00aaff,#0066cc);
    color: #fff;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 16px;
    font-weight: bold;
    text-decoration: none;
    box-shadow: 0 0 20px rgba(0,100,255,0.7);
    transition: all 0.3s ease;
    z-index: 998;
    border: 2px solid rgba(255,255,255,0.3);
}

.btn-flotante:hover, .btn-ligas:hover {
    transform: scale(1.1);
    box-shadow: 0 0 30px rgba(255,0,0,0.9);
}

.btn-ligas:hover {
    box-shadow: 0 0 30px rgba(0,100,255,0.9);
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
    .solicitudes-section {
        padding: 30px;
    }
    .solicitudes-grid {
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
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
    
    .solicitudes-section {
        padding: 20px;
    }
    
    .solicitudes-grid {
        grid-template-columns: 1fr;
    }
    
    .admin-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .btn-flotante, .btn-ligas {
        width: 70px;
        height: 70px;
        font-size: 14px;
        bottom: 15px;
        right: 15px;
    }
    
    .btn-ligas {
        bottom: 100px;
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
    .solicitud-card {
        padding: 20px;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .fecha-solicitud {
        align-self: flex-end;
    }
    
    .solicitudes-section {
        padding: 15px;
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

/* BADGE DE ESTADO */
.estado-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    padding: 6px 15px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    background: rgba(255, 152, 0, 0.2);
    color: #ff9800;
    border: 1px solid rgba(255, 152, 0, 0.3);
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Solicitudes de Equipos - RETAME'); } ?>

<!-- BURBUJAS DE FONDO -->
<div class="bg-particles" id="particles"></div>

<!-- Botón hamburguesa solo para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR (MISMA ESTRUCTURA QUE TU PERFIL) -->
<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="UnirmeLiga.php">👥 Unirme a una liga</a></li>
        <li><a href="CrearLiga.php">👥 Crear Una liga</a></li>
        <li><a href="AdminLiga.php">📋 Administrar Liga</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- CONTENIDO PRINCIPAL -->
<div class="main-content">
    <h1><i class="fas fa-users"></i> Solicitudes de Equipos</h1>
    
    <!-- Mostrar alertas -->
    <?php if ($mensajeError): ?>
        <div class="alert alert-error">
            <strong>⚠️ Error:</strong> <?php echo $mensajeError; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($mensajeExito): ?>
        <div class="alert alert-success">
            <strong>✅ Éxito:</strong> <?php echo $mensajeExito; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($mensajeInfo)): ?>
        <div class="alert alert-info">
            <strong>ℹ️ Información:</strong> <?php echo $mensajeInfo; ?>
        </div>
    <?php endif; ?>
    
    <div class="solicitudes-section">
        <?php if (!empty($solicitudesEquipos) && !empty($id_liga_admin)): ?>
            <!-- ENCABEZADO DE ADMINISTRADOR -->
            <div class="admin-header">
                <div class="info-admin">
                    <h3><i class="fas fa-user-shield"></i> Administrador: <?php echo $Nombre; ?></h3>
                    <p><i class="fas fa-trophy"></i> Liga administrando: <?php echo htmlspecialchars($id_liga_admin); ?></p>
                </div>
                <div class="contador-solicitudes">
                    Solicitudes en proceso: 
                    <span class="numero"><?php echo count($solicitudesEquipos); ?></span>
                </div>
            </div>
            
            <!-- GRID DE SOLICITUDES -->
            <div class="solicitudes-grid" id="solicitudes-grid">
                <?php foreach ($solicitudesEquipos as $solicitud): ?>
                    <div class="solicitud-card">
                        <div class="estado-badge">
                            <i class="fas fa-clock"></i> En proceso
                        </div>
                        
                        <div class="card-header">
                            <div class="equipo-info">
                                <h3>
                                    <?php echo htmlspecialchars($solicitud['NombreEquipo']); ?>
                                </h3>
                                <?php if ($solicitud['NombreDeporte'] != 'Desconocido'): ?>
                                <span class="deporte-tag">
                                    <i class="fas fa-basketball-ball"></i> 
                                    <?php echo htmlspecialchars($solicitud['NombreDeporte']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="fecha-solicitud">
                                <i class="far fa-calendar"></i> 
                                <?php echo date('d/m/Y', strtotime($solicitud['fecha'])); ?>
                            </div>
                        </div>
                        
                        <div class="card-content">
                            <div class="info-item">
                                <span class="info-label">ID Solicitud:</span>
                                <span class="info-value"><?php echo $solicitud['id_solicitud']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">ID Equipo:</span>
                                <span class="info-value"><?php echo $solicitud['id_solicitante']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">ID Liga:</span>
                                <span class="info-value"><?php echo $solicitud['id_areadesolicitud']; ?></span>
                            </div>
                            <?php if ($solicitud['NombreCapitan'] != 'Desconocido'): ?>
                            <div class="info-item">
                                <span class="info-label">Capitán:</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($solicitud['NombreCapitan'] . ' ' . $solicitud['ApellidoCapitan']); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if ($solicitud['EdadCapitan'] > 0): ?>
                            <div class="info-item">
                                <span class="info-label">Edad Capitán:</span>
                                <span class="info-value"><?php echo $solicitud['EdadCapitan']; ?> años</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($solicitud['cantidad'] > 0): ?>
                            <div class="info-item">
                                <span class="info-label">N° Jugadores:</span>
                                <span class="info-value"><?php echo $solicitud['cantidad']; ?> jugadores</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($solicitud['Pais'] != 'No definido'): ?>
                            <div class="info-item">
                                <span class="info-label">País:</span>
                                <span class="info-value"><?php echo htmlspecialchars($solicitud['Pais']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($solicitud['Entrenador'] != 'No definido'): ?>
                            <div class="info-item">
                                <span class="info-label">Entrenador:</span>
                                <span class="info-value"><?php echo htmlspecialchars($solicitud['Entrenador']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" class="card-actions" onsubmit="return confirmarAceptacion(this);">
                            <input type="hidden" name="id_solicitud" value="<?php echo $solicitud['id_solicitud']; ?>">
                            <input type="hidden" name="id_liga" value="<?php echo $id_liga_admin; ?>">
                            <input type="hidden" name="id_equipo" value="<?php echo $solicitud['id_solicitante']; ?>">
                            
                            <button type="submit" name="aceptar_solicitud" class="btn-aceptar">
                                <i class="fas fa-check"></i> Aceptar Solicitud
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php elseif (!empty($id_liga_admin)): ?>
            <!-- NO HAY SOLICITUDES PERO SÍ ES ADMINISTRADOR -->
            <div class="no-solicitudes">
                <i class="fas fa-inbox"></i>
                <h3>No hay solicitudes en proceso</h3>
                <p>Actualmente no hay equipos solicitando unirse a tu liga con estado "En proceso".</p>
                <p>Las solicitudes aparecerán aquí cuando los equipos soliciten unirse a la liga que administras.</p>
                
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Información del Administrador</h4>
                    <p><strong>Usuario:</strong> <?php echo $Nombre; ?></p>
                    <p><strong>ID Retador:</strong> <?php echo $Id_Retador; ?></p>
                    <p><strong>Liga administrando:</strong> <?php echo $id_liga_admin; ?></p>
                    <p><strong>Nota:</strong> El sistema busca solicitudes con estado "En proceso"</p>
                </div>
            </div>
        <?php else: ?>
            <!-- NO ES ADMINISTRADOR -->
            <div class="no-solicitudes">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>No puedes ver solicitudes</h3>
                <p>No estás registrado como administrador de ninguna liga activa.</p>
                <p>Para administrar solicitudes, primero debes ser asignado como administrador de una liga.</p>
                
                <div style="margin-top: 30px;">
                    <a href="AdminLiga.php" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #001aff, #0015cc); color: white; text-decoration: none; border-radius: 10px; font-weight: 600;">
                        <i class="fas fa-arrow-left"></i> Volver a Administración
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- BOTONES FLOTANTES (MISMOS QUE TU PERFIL) -->
<a href="liga.php" class="btn-ligas">🏆 INICIO</a>
<a href="AdminLiga.php" class="btn-flotante" title="Volver a Administración">
    ⚙️ ADMIN
</a>

<script>
// ========== FUNCIONES DE LA INTERFAZ (IGUALES A TU PERFIL) ==========

// Crear partículas/burbujas dinámicas
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 25;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        const size = Math.random() * 20 + 5;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        
        particle.style.opacity = Math.random() * 0.2 + 0.1;
        
        const duration = Math.random() * 25 + 20;
        const delay = Math.random() * 5;
        particle.style.animation = `float ${duration}s ${delay}s infinite linear`;
        
        particle.style.background = `rgba(255, 255, 255, ${Math.random() * 0.2 + 0.1})`;
        
        particlesContainer.appendChild(particle);
    }
});

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

// Confirmación de aceptación
function confirmarAceptacion(form) {
    if (confirm('¿Estás seguro de aceptar este equipo en la liga?\n\nEsta acción cambiará el estado de la solicitud a "Aceptada" y registrará al equipo en la liga.')) {
        return true;
    }
    return false;
}

// Auto-refresh cada 30 segundos si hay solicitudes
<?php if (!empty($solicitudesEquipos)): ?>
setTimeout(() => {
    location.reload();
}, 30000);
<?php endif; ?>
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>