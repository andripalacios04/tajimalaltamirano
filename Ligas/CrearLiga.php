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

// CONEXIÓN A LA BASE DE DATOS
try {
    // Cambia estos valores según tu configuración
    $host = 'localhost';
    $dbname = 'retame_bd';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("❌ Error de conexión a la base de datos: " . $e->getMessage());
}

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

$mensaje = '';
$tipo_mensaje = ''; // 'success' o 'error'
$liga_creada = false;
$info_liga = null;

// Consultar deportes para el select - SOLO los de tipo "equipo"
$deportes = [];
try {
    // MODIFICADO: Solo deportes donde Tipo = 'equipo'
    $sql_deportes = "SELECT Id_Deporte, Nombre FROM deporte WHERE Tipo = 'equipo' ORDER BY Nombre";
    $stmt_deportes = $pdo->query($sql_deportes);
    $deportes = $stmt_deportes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error al cargar deportes: " . $e->getMessage();
    $tipo_mensaje = 'error';
}

// Procesar el formulario cuando se envíe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_liga'])) {
    // Validar y sanitizar datos
    $nombre_liga = trim($_POST['nombre_liga'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $id_deporte = $_POST['id_deporte'] ?? '';
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    // Validaciones básicas
    if (empty($nombre_liga) || empty($codigo_postal) || empty($fecha_inicio) || empty($id_deporte)) {
        $mensaje = "⚠️ Por favor completa todos los campos obligatorios";
        $tipo_mensaje = 'error';
    } 
    // Validar que el nombre no inicie con número y no sea solo números
    elseif (preg_match('/^\d/', $nombre_liga)) {
        $mensaje = "⚠️ El nombre de la liga no puede iniciar con un número";
        $tipo_mensaje = 'error';
    }
    elseif (preg_match('/^\d+$/', $nombre_liga)) {
        $mensaje = "⚠️ El nombre de la liga no puede ser solo números";
        $tipo_mensaje = 'error';
    }
    // Validar que el código postal solo contenga números
    elseif (!preg_match('/^\d+$/', $codigo_postal)) {
        $mensaje = "⚠️ El código postal solo puede contener números";
        $tipo_mensaje = 'error';
    }
    elseif (strlen($nombre_liga) > 255) {
        $mensaje = "⚠️ El nombre de la liga es demasiado largo";
        $tipo_mensaje = 'error';
    } elseif (!empty($fecha_fin) && strtotime($fecha_fin) < strtotime($fecha_inicio)) {
        $mensaje = "⚠️ La fecha de fin no puede ser anterior a la fecha de inicio";
        $tipo_mensaje = 'error';
    } else {
        try {
            // Generar ID de liga según la regla: nombre + fecha creación
            $fecha_creacion = date('Ymd');
            $id_liga_base = preg_replace('/[^a-zA-Z0-9]/', '', $nombre_liga);
            $id_liga = substr($id_liga_base, 0, 30) . '_' . $fecha_creacion;
            
            // Verificar si el ID ya existe (añadir número si es necesario)
            $contador = 1;
            $id_liga_original = $id_liga;
            
            while (true) {
                $sql_check = "SELECT COUNT(*) FROM ligas WHERE Id_Liga = ?";
                $stmt_check = $pdo->prepare($sql_check);
                $stmt_check->execute([$id_liga]);
                $existe = $stmt_check->fetchColumn();
                
                if ($existe == 0) {
                    break;
                }
                $id_liga = $id_liga_original . '_' . $contador;
                $contador++;
            }
            
            // PREPARAR LA CONSULTA DE INSERCIÓN - SIN CantidadEquipos
            $sql = "INSERT INTO ligas (
                Id_Liga, 
                Nombre, 
                CodigoPostal, 
                FechaCreacion, 
                FechaInicio, 
                FechaFin, 
                Estado,
                Id_Deporte,
                Id_Creador,
                Descripcion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            
            // Ejecutar la inserción - SIN CantidadEquipos
            $resultado = $stmt->execute([
                $id_liga,
                $nombre_liga,
                $codigo_postal,
                date('Y-m-d'), // FechaCreacion (actual)
                $fecha_inicio,
                empty($fecha_fin) ? null : $fecha_fin,
                'Inscripciones', // Estado por defecto
                $id_deporte,
                $Id_Retador,
                empty($descripcion) ? null : $descripcion
            ]);
            
            if ($resultado) {
                $mensaje = "✅ Liga creada exitosamente!";
                $tipo_mensaje = 'success';
                $liga_creada = true;
                
                // Obtener información completa de la liga recién creada
                try {
                    $sql_info = "SELECT l.*, d.Nombre as NombreDeporte 
                                FROM ligas l 
                                JOIN deporte d ON l.Id_Deporte = d.Id_Deporte 
                                WHERE l.Id_Liga = ?";
                    $stmt_info = $pdo->prepare($sql_info);
                    $stmt_info->execute([$id_liga]);
                    $info_liga = $stmt_info->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Si hay error al obtener info, al menos tenemos el ID
                    $info_liga = ['Id_Liga' => $id_liga, 'Nombre' => $nombre_liga];
                }
            } else {
                $mensaje = "❌ Error al crear la liga";
                $tipo_mensaje = 'error';
            }
            
        } catch (PDOException $e) {
            $mensaje = "❌ Error en la base de datos: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$equipos_usuario = [];

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
<title>Crear Liga - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* TODO TU CSS ORIGINAL PERMANECE IGUAL */
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
}

/* TARJETA DE PERFIL */
.perfil-card { 
    background:rgba(27,31,39,0.9); 
    padding:40px; 
    border-radius:20px; 
    box-shadow:0 0 30px rgba(0,26,255,0.5);
    max-width:900px;
    margin:0 auto;
    text-align:center;
    backdrop-filter:blur(10px);
    border:1px solid rgba(0,26,255,0.2);
}

.perfil-card h3 {
    color:#00ffc6;
    margin-bottom:30px;
    font-size:24px;
}

/* EQUIPOS EN CÍRCULOS */
.equipos-container {
    display:flex;
    flex-wrap:wrap;
    justify-content:center;
    gap:25px;
    margin-top:30px;
}

.circle-equipo { 
    width:120px; 
    height:120px; 
    border-radius:50%; 
    background:linear-gradient(135deg,#ff0000,#e00000);
    color:#fff; 
    font-weight:bold; 
    border:none; 
    cursor:pointer; 
    display:flex; 
    justify-content:center; 
    align-items:center; 
    transition:all 0.3s ease; 
    font-size:14px;
    text-align:center;
    padding:10px;
    border:3px solid rgba(255,255,255,0.2);
    box-shadow:0 5px 15px rgba(255,0,0,0.3);
}

.circle-equipo:hover { 
    transform:scale(1.1); 
    box-shadow:0 10px 25px rgba(255,0,0,0.5);
    border-color:rgba(255,255,255,0.5);
}

/* SIN EQUIPOS */
.no-equipos {
    background:rgba(255,0,0,0.1);
    border:1px solid rgba(255,0,0,0.3);
    border-radius:15px;
    padding:40px;
    margin:30px 0;
    color:#ff9999;
    text-align:center;
}

/* MODAL */
.modal { 
    display:none; 
    position:fixed; 
    top:0; 
    left:0; 
    width:100%; 
    height:100%; 
    background:rgba(0,0,0,0.8); 
    justify-content:center; 
    align-items:center; 
    z-index:2000; 
    backdrop-filter:blur(5px);
}

.modal-content { 
    background:#1b1f27; 
    padding:30px; 
    border-radius:15px; 
    text-align:center; 
    color:#fff; 
    max-width:400px;
    width:90%;
    box-shadow:0 10px 30px rgba(0,0,0,0.5);
    border:1px solid rgba(0,255,198,0.3);
}

.modal-content h3 {
    color:#00ffc6;
    margin-bottom:20px;
    font-size:22px;
}

.modal-content button { 
    margin:10px; 
    padding:12px 25px; 
    border:none; 
    border-radius:8px; 
    cursor:pointer; 
    font-weight:bold;
    background:linear-gradient(135deg,#ff0000,#e00000);
    color:white;
    transition:all 0.3s ease;
}

.modal-content button:hover {
    background:linear-gradient(135deg,#e00000,#c00000);
    transform:translateY(-2px);
}

.modal-content input, .modal-content select, .modal-content textarea { 
    margin:8px 0; 
    padding:12px; 
    width:100%; 
    border-radius:8px; 
    border:1px solid rgba(0,255,198,0.3);
    background:rgba(255,255,255,0.1);
    color:white;
    font-size:14px;
}

.modal-content input::placeholder, .modal-content textarea::placeholder {
    color:#aaa;
}

#notificacion { 
    margin-top:15px; 
    font-weight:bold; 
    color:#00ff00;
    padding:10px;
    background:rgba(0,255,0,0.1);
    border-radius:8px;
    border:1px solid #00ff00;
}

#joinForm, #createForm {
    margin-top:20px;
    padding-top:20px;
    border-top:1px solid rgba(255,255,255,0.1);
}

.btn-cerrar {
    background:rgba(100,100,100,0.5) !important;
    border:1px solid #666 !important;
}

.btn-cerrar:hover {
    background:rgba(150,150,150,0.5) !important;
}

/* BOTONES FLOTANTES */
.btn-retar {
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

.btn-retar:hover, .btn-ligas:hover {
    transform: scale(1.1);
    box-shadow: 0 0 30px rgba(255,0,0,0.9);
}

.btn-ligas:hover {
    box-shadow: 0 0 30px rgba(0,100,255,0.9);
}

/* FORMULARIO CREAR LIGA */
.form-liga-container {
    background: rgba(27, 31, 39, 0.9);
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 0 30px rgba(0, 100, 255, 0.5);
    max-width: 700px;
    margin: 30px auto;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 100, 255, 0.3);
}

.form-liga-container h2 {
    color: #00ffc6;
    text-align: center;
    margin-bottom: 30px;
    font-size: 28px;
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
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border-radius: 10px;
    border: 2px solid rgba(0, 255, 198, 0.3);
    background: rgba(0, 0, 0, 0.3);
    color: white;
    font-size: 16px;
    transition: all 0.3s ease;
}

.form-group input[type="text"]:focus,
.form-group input[type="date"]:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #00ffc6;
    box-shadow: 0 0 10px rgba(0, 255, 198, 0.5);
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.required::after {
    content: " *";
    color: #ff5555;
}

.btn-submit {
    background: linear-gradient(135deg, #00aaff, #0066cc);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 10px;
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    margin-top: 20px;
    transition: all 0.3s ease;
}

.btn-submit:hover {
    background: linear-gradient(135deg, #0066cc, #004499);
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(0, 100, 255, 0.4);
}

/* INFORMACIÓN DEL USUARIO */
.user-info {
    background: rgba(0, 100, 255, 0.1);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid rgba(0, 100, 255, 0.3);
}

.user-info p {
    margin: 5px 0;
    color: #00ffc6;
}

.user-info strong {
    color: white;
}

/* MENSAJES DE ALERTA */
.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    font-weight: bold;
    text-align: center;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.alert-success {
    background: rgba(0, 255, 100, 0.1);
    border: 2px solid #00ff64;
    color: #00ff64;
}

.alert-error {
    background: rgba(255, 50, 50, 0.1);
    border: 2px solid #ff3232;
    color: #ff3232;
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
    .perfil-card, .form-liga-container {
        margin: 20px auto;
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
    .btn-retar, .btn-ligas {
        width: 70px;
        height: 70px;
        font-size: 14px;
        bottom: 15px;
        right: 15px;
    }
    .btn-ligas {
        bottom: 100px;
    }
    
    .form-liga-container {
        padding: 25px;
        margin: 15px auto;
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
        gap: 15px;
    }
    .circle-equipo {
        width: 100px;
        height: 100px;
        font-size: 12px;
    }
    .perfil-card, .form-liga-container {
        padding: 20px;
    }
    
    .form-liga-container h2 {
        font-size: 24px;
    }
    
    .btn-submit {
        padding: 12px 20px;
        font-size: 16px;
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

/* AÑADO SOLO LOS ESTILOS NUEVOS QUE NECESITAS */
.resumen-liga-container {
    background: rgba(27, 31, 39, 0.9);
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 0 30px rgba(0, 255, 100, 0.3);
    max-width: 800px;
    margin: 30px auto;
    backdrop-filter: blur(10px);
    border: 2px solid rgba(0, 255, 100, 0.5);
}

.resumen-liga-container h2 {
    color: #00ff64;
    text-align: center;
    margin-bottom: 30px;
    font-size: 28px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.info-item {
    background: rgba(0, 255, 198, 0.1);
    padding: 15px;
    border-radius: 10px;
    border: 1px solid rgba(0, 255, 198, 0.3);
}

.info-item strong {
    color: #00ffc6;
    display: block;
    margin-bottom: 5px;
    font-size: 14px;
}

.info-item span {
    color: white;
    font-size: 16px;
    word-break: break-word;
}

.descripcion-item {
    grid-column: 1 / -1;
}

.btn-finalizar {
    background: linear-gradient(135deg, #ff0000, #e00000);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 10px;
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    margin-top: 20px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.btn-finalizar:hover {
    background: linear-gradient(135deg, #e00000, #c00000);
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(255, 0, 0, 0.4);
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Liga - RETAME'); } ?>

<!-- BURBUJAS DE FONDO -->
<div class="bg-particles" id="particles"></div>

<!-- Botón hamburguesa solo para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="UnirmeLiga.php">👥 Unirme a una liga</a></li>
        <li><a href="CrearLiga.php">👥 Crear Una liga</a></li>
        <li><a href="Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>👤 Perfil de <?php echo $Nombre; ?></h1>
    
    <!-- Información del usuario -->
    <div class="user-info">
        <p><strong>Usuario ID:</strong> <?php echo htmlspecialchars($Id_Retador); ?></p>
        <p><strong>Nombre:</strong> <?php echo $Nombre; ?></p>
    </div>
    
    <!-- Mostrar mensajes -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($liga_creada && $info_liga): ?>
    <!-- RESULTADO: Mostrar información de la liga creada -->
    <div class="resumen-liga-container">
        <h2>🏆 Liga Creada Exitosamente</h2>
        
        <div class="info-grid">
            <div class="info-item">
                <strong>ID de la Liga:</strong>
                <span><?php echo htmlspecialchars($info_liga['Id_Liga'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Nombre de la Liga:</strong>
                <span><?php echo htmlspecialchars($info_liga['Nombre'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Código Postal:</strong>
                <span><?php echo htmlspecialchars($info_liga['CodigoPostal'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Creación:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaCreacion'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaCreacion']));
                    } else {
                        echo date('d/m/Y');
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Inicio:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaInicio'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaInicio']));
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Fin:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaFin']) && !empty($info_liga['FechaFin'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaFin']));
                    } else {
                        echo 'No definida';
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Deporte:</strong>
                <span><?php echo htmlspecialchars($info_liga['NombreDeporte'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Estado Actual:</strong>
                <span style="color: #00ffc6; font-weight: bold;">
                    <?php echo htmlspecialchars($info_liga['Estado'] ?? 'Inscripciones'); ?>
                </span>
            </div>
            
            <?php if (!empty($info_liga['Descripcion'])): ?>
            <div class="info-item descripcion-item">
                <strong>Descripción:</strong>
                <span><?php echo nl2br(htmlspecialchars($info_liga['Descripcion'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <p style="color: #00ffc6; margin-bottom: 20px;">
                ✅ La liga ha sido registrada exitosamente en el sistema.
            </p>
            
            <a href="liga.php" class="btn-finalizar">
                🏁 Finalizar y Volver a Inicio
            </a>
            
            <p style="color: #aaa; margin-top: 20px; font-size: 14px;">
                <small>Guarda el ID de la liga: <strong><?php echo htmlspecialchars($info_liga['Id_Liga'] ?? ''); ?></strong> para futuras referencias.</small>
            </p>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Formulario para crear liga - SOLO SE MUESTRA SI NO HAY LIGA CREADA -->
    <div class="form-liga-container">
        <h2>🏆 Crear Nueva Liga</h2>
        
        <form method="POST" action="" id="formLiga">
            <!-- Nombre de la Liga -->
            <div class="form-group">
                <label for="nombre_liga" class="required">Nombre de la Liga</label>
                <input type="text" 
                       id="nombre_liga" 
                       name="nombre_liga" 
                       value="<?php echo isset($_POST['nombre_liga']) ? htmlspecialchars($_POST['nombre_liga']) : ''; ?>" 
                       placeholder="Ej: Liga de Fútbol Primavera 2024" 
                       maxlength="255" 
                       required
                       oninput="validarNombre(this)">
                <small id="error_nombre" style="color:#ff5555; display:none; margin-top:5px;"></small>
            </div>
            
            <!-- Código Postal -->
            <div class="form-group">
                <label for="codigo_postal" class="required">Código Postal</label>
                <input type="text" 
                       id="codigo_postal" 
                       name="codigo_postal" 
                       value="<?php echo isset($_POST['codigo_postal']) ? htmlspecialchars($_POST['codigo_postal']) : ''; ?>" 
                       placeholder="Ej: 28001" 
                       maxlength="50" 
                       required
                       oninput="validarCodigoPostal(this)">
                <small id="error_cp" style="color:#ff5555; display:none; margin-top:5px;"></small>
            </div>
            
            <!-- Fecha de Inicio -->
            <div class="form-group">
                <label for="fecha_inicio" class="required">Fecha de Inicio</label>
                <input type="date" 
                       id="fecha_inicio" 
                       name="fecha_inicio" 
                       value="<?php echo isset($_POST['fecha_inicio']) ? htmlspecialchars($_POST['fecha_inicio']) : ''; ?>" 
                       min="<?php echo date('Y-m-d'); ?>" 
                       required>
            </div>
            
            <!-- Fecha de Fin (Opcional) -->
            <div class="form-group">
                <label for="fecha_fin">Fecha de Fin (Opcional)</label>
                <input type="date" 
                       id="fecha_fin" 
                       name="fecha_fin" 
                       value="<?php echo isset($_POST['fecha_fin']) ? htmlspecialchars($_POST['fecha_fin']) : ''; ?>" 
                       min="<?php echo date('Y-m-d'); ?>">
                <small style="color: #aaa; display: block; margin-top: 5px;">Si no se especifica, la liga no tendrá fecha de finalización definida.</small>
            </div>
            
            <!-- Selección de Deporte -->
            <div class="form-group">
                <label for="id_deporte" class="required">Deporte</label>
                <select id="id_deporte" name="id_deporte" required>
                    <option value="">-- Selecciona un deporte --</option>
                    <?php foreach ($deportes as $deporte): ?>
                        <option value="<?php echo htmlspecialchars($deporte['Id_Deporte']); ?>"
                            <?php echo (isset($_POST['id_deporte']) && $_POST['id_deporte'] == $deporte['Id_Deporte']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($deporte['Nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Descripción -->
            <div class="form-group">
                <label for="descripcion">Descripción (Opcional)</label>
                <textarea id="descripcion" 
                          name="descripcion" 
                          placeholder="Describe tu liga, reglas especiales, premios, etc."><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
            </div>
            
            <!-- Información generada automáticamente - MODIFICADO -->
            <div class="form-group">
                <div style="background: rgba(0, 255, 198, 0.1); padding: 15px; border-radius: 10px; border: 1px solid rgba(0, 255, 198, 0.3);">
                    <p style="color: #00ffc6; margin: 0; font-weight: bold;">⚠️ Información generada automáticamente:</p>
                    <ul style="color: white; margin: 10px 0 0 20px;">
                        <li><strong>Codigo de  Liga:</strong> Se generará automáticamente </li>
                        <li><strong>Fecha Creación:</strong> <?php echo date('d/m/Y'); ?></li>
                        <li><strong>Creador:</strong> <?php echo $Nombre; ?> (ID: <?php echo htmlspecialchars($Id_Retador); ?>)</li>
                        <li><strong>Estado Inicial:</strong> "Inscripciones"</li>
                    </ul>
                </div>
            </div>
            
            <!-- Botón de enviar -->
            <button type="submit" name="crear_liga" class="btn-submit">
                🏆 Crear Liga
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- BOTONES FLOTANTES FUERA DEL CONTENIDO PRINCIPAL -->
<a href="../Perfil2.php" class="btn-ligas">🏆 INICIO</a>
<a href="Retar/retar.php" class="btn-retar">⚔️ RETAR</a>

<script>
// Crear partículas/burbujas dinámicas
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 25;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        // Tamaño aleatorio entre 5px y 25px
        const size = Math.random() * 20 + 5;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        
        // Posición aleatoria
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        
        // Opacidad aleatoria
        particle.style.opacity = Math.random() * 0.2 + 0.1;
        
        // Animación con duración y delay aleatorios
        const duration = Math.random() * 25 + 20;
        const delay = Math.random() * 5;
        particle.style.animation = `float ${duration}s ${delay}s infinite linear`;
        
        particlesContainer.appendChild(particle);
    }
    
    // Configurar fecha mínima para fecha fin
    const fechaInicio = document.getElementById('fecha_inicio');
    const fechaFin = document.getElementById('fecha_fin');
    
    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', function() {
            fechaFin.min = this.value;
            if (fechaFin.value && fechaFin.value < this.value) {
                fechaFin.value = this.value;
            }
        });
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

// Validar que fecha fin no sea anterior a fecha inicio
const formLiga = document.getElementById('formLiga');
if (formLiga) {
    formLiga.addEventListener('submit', function(e) {
        const fechaInicio = document.getElementById('fecha_inicio').value;
        const fechaFin = document.getElementById('fecha_fin').value;
        
        if (fechaFin && fechaFin < fechaInicio) {
            e.preventDefault();
            alert('⚠️ La fecha de fin no puede ser anterior a la fecha de inicio');
            return false;
        }
    });
}

// Función para validar nombre en tiempo real
function validarNombre(input) {
    const valor = input.value.trim();
    const errorElement = document.getElementById('error_nombre');
    
    // Si el nombre inicia con número
    if (/^\d/.test(valor)) {
        errorElement.textContent = 'El nombre no puede iniciar con un número';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si el nombre es solo números
    if (/^\d+$/.test(valor)) {
        errorElement.textContent = 'El nombre no puede ser solo números';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si está bien
    errorElement.style.display = 'none';
    input.style.borderColor = 'rgba(0, 255, 198, 0.3)';
    return true;
}

// Función para validar código postal en tiempo real
function validarCodigoPostal(input) {
    const valor = input.value.trim();
    const errorElement = document.getElementById('error_cp');
    
    // Si contiene letras
    if (!/^\d+$/.test(valor) && valor !== '') {
        errorElement.textContent = 'El código postal solo puede contener números';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si está bien
    errorElement.style.display = 'none';
    input.style.borderColor = 'rgba(0, 255, 198, 0.3)';
    return true;
}

// Validar formulario antes de enviar
if (formLiga) {
    formLiga.addEventListener('submit', function(e) {
        const nombreInput = document.getElementById('nombre_liga');
        const cpInput = document.getElementById('codigo_postal');
        
        // Validar nombre
        if (!validarNombre(nombreInput)) {
            e.preventDefault();
            return false;
        }
        
        // Validar código postal
        if (!validarCodigoPostal(cpInput)) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
}
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>