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

/* EL USUARIO LOGUEADO ES UN RETADOR */
$id_retador = $_SESSION['usuario_data']['Id_Retador'];

/* Igualamos el nombre de la conexión */
$conexion = $conn;

/* =========================
   FUNCIÓN PARA GENERAR ID ÚNICO
========================= */
function generarIdEquipoTemporal($conexion, $nombre) {
    $base = str_replace(' ', '', $nombre);

    do {
        $random = rand(100, 999);
        $id = $base . $random;

        $sql = "SELECT 1 FROM equipo_temporal 
                WHERE Id_EquipoTemporal = '$id'";
        $resultado = mysqli_query($conexion, $sql);

    } while (mysqli_num_rows($resultado) > 0);

    return $id;
}

/* =========================
   FUNCIÓN PARA GENERAR ID DE RELACIÓN
========================= */
function generarIdRelacion() {
    return "EQRT" . uniqid() . rand(100, 999);
}

/* =========================
   GUARDAR EQUIPO TEMPORAL
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre     = mysqli_real_escape_string($conexion, $_POST['Nombre']);
    $id_deporte = mysqli_real_escape_string($conexion, $_POST['Id_Deporte']);
    $cantidad   = intval($_POST['Cantidad']);

    $id_equipo_temporal = generarIdEquipoTemporal($conexion, $nombre);

    // Iniciar transacción para asegurar que ambas inserciones se completen
    mysqli_begin_transaction($conexion);

    try {
        // 1. Insertar en equipo_temporal
        $sql_equipo = "INSERT INTO equipo_temporal
                      (Id_EquipoTemporal, Nombre, Id_Deporte, Cantidad, Capitan, Estado)
                      VALUES
                      ('$id_equipo_temporal', '$nombre', '$id_deporte', $cantidad, '$id_retador', 'Esperando')";

        if (!mysqli_query($conexion, $sql_equipo)) {
            throw new Exception("❌ Error al crear el equipo temporal: " . mysqli_error($conexion));
        }

        // 2. Insertar al capitán en equiporetador_temporal
        $id_relacion = generarIdRelacion();
        
        $sql_capitan = "INSERT INTO equiporetador_temporal
                       (Id_EquipoRetadorTemp, Id_EquipoTemporal, Id_Retador, Id_Deporte)
                       VALUES
                       ('$id_relacion', '$id_equipo_temporal', '$id_retador', '$id_deporte')";
        
        if (!mysqli_query($conexion, $sql_capitan)) {
            throw new Exception("❌ Error al registrar al capitán en el equipo: " . mysqli_error($conexion));
        }

        // Confirmar transacción si todo salió bien
        mysqli_commit($conexion);

        // Mostrar mensaje de éxito con el estilo del dashboard
        $mensaje_exito = true;
        $datos_equipo = [
            'id' => $id_equipo_temporal,
            'nombre' => $nombre,
            'cantidad' => $cantidad,
            'capitan' => $id_retador
        ];

    } catch (Exception $e) {
        // Revertir transacción en caso de error
        mysqli_rollback($conexion);
        $error = $e->getMessage();
    }
}

/* =========================
   OBTENER DEPORTES
========================= */
$deportes = mysqli_query($conexion, "SELECT Id_Deporte, Nombre FROM deporte ORDER BY Nombre");

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
    <title>Crear Equipo Temporal - RETAME</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        /* CONTENEDOR DEL FORMULARIO */
        .form-container {
            background: rgba(27, 31, 39, 0.9);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0px 0px 30px rgba(0, 26, 255, 0.5);
            width: 100%;
            max-width: 500px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            color: #ffffff;
            font-size: 2em;
            margin-bottom: 10px;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
        }
        
        .subtitle {
            color: #a0a0a0;
            font-size: 1em;
        }
        
        /* MENSAJES DE ÉXITO/ERROR */
        .message-container {
            text-align: center;
            padding: 40px;
            background: rgba(27, 31, 39, 0.9);
            border-radius: 20px;
            box-shadow: 0px 0px 30px rgba(0, 26, 255, 0.5);
            max-width: 600px;
            width: 100%;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        
        .success-message h3 {
            color: #43e97b;
            font-size: 1.8em;
            margin-bottom: 20px;
        }
        
        .error-message h3 {
            color: #ff0000;
            font-size: 1.8em;
            margin-bottom: 20px;
        }
        
        .info-box {
            background: rgba(40, 44, 52, 0.7);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #ff0000;
            text-align: left;
        }
        
        .info-box p {
            color: #a0a0a0;
            font-size: 0.9em;
            margin: 8px 0;
            line-height: 1.4;
        }
        
        .info-box strong {
            color: #ffffff;
        }
        
        /* FORMULARIO */
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #ffffff;
            font-weight: 500;
            font-size: 0.95em;
        }
        
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(0, 26, 255, 0.3);
            border-radius: 10px;
            color: #ffffff;
            font-size: 1em;
            transition: all 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="number"]:focus,
        select:focus {
            border-color: #ff0000;
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 0, 0, 0.1);
        }
        
        input::placeholder {
            color: #888;
        }
        
        select option {
            background: #111820;
            color: #ffffff;
        }
        
        button[type="submit"] {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #ff0000, #e00000);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        button[type="submit"]:hover {
            background: linear-gradient(135deg, #e00000, #c00000);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 0, 0, 0.3);
        }
        
        /* BOTONES DE ACCIÓN */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: center;
        }
        
        .action-btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 123, 255, 0.3);
        }
        
        .action-btn-success {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
        }
        
        .action-btn-secondary {
            background: linear-gradient(135deg, #6c757d, #545b62);
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .back-link a {
            color: #4facfe;
            text-decoration: none;
            font-weight: 500;
            margin: 0 10px;
        }
        
        .back-link a:hover {
            text-decoration: underline;
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
            .form-container {
                padding: 25px;
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
            .form-container {
                padding: 20px;
            }
            .page-header h2 {
                font-size: 1.6em;
            }
            button[type="submit"] {
                padding: 14px;
                font-size: 1em;
            }
            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }
            .action-btn {
                width: 100%;
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Equipo Temporal - RETAME'); } ?>

    <!-- Botón hamburguesa solo para móviles -->
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Overlay para cerrar sidebar en móviles -->
    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
 
<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="../Perfil2.php">🏠 Inicio</a></li>
        <li><a href="Equipo/UnirmeOtroEquipo.php">👥 Unirme a un equipo</a></li>
        <li><a href="Equipo/CrearEquipo.php">👥 Crear Un Equipo</a></li>
        <li><a href="Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="Equipo/Mis_Equipos.php">👥📋 Mis Equipos</a></li>
        <li><a href="Solicitudes.php">Solicitudes</a></li>
        <li>
            <a href="Notificaciones.php">
                Notificaciones
                <?php if ($nuevas_count > 0): ?>
                    <span class="noti-alert-badge">!</span>
                <?php endif; ?>
            </a>
        </li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="main-content">
        <?php if (isset($mensaje_exito) && $mensaje_exito): ?>
            <!-- MENSAJE DE ÉXITO -->
            <div class="message-container">
                <div class="success-message">
                    <h3>✅ Equipo temporal creado correctamente</h3>
                    <div class="info-box">
                        <p><strong>ID del equipo:</strong> <?php echo htmlspecialchars($datos_equipo['id']); ?></p>
                        <p><strong>Nombre del equipo:</strong> <?php echo htmlspecialchars($datos_equipo['nombre']); ?></p>
                        <p><strong>Cantidad de jugadores:</strong> <?php echo htmlspecialchars($datos_equipo['cantidad']); ?></p>
                        <p><strong>Capitán (Id_Retador):</strong> <?php echo htmlspecialchars($datos_equipo['capitan']); ?></p>
                        <p><strong>Estado:</strong> Esperando (1/<?php echo htmlspecialchars($datos_equipo['cantidad']); ?> jugadores)</p>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="../Perfil2.php" class="action-btn action-btn-secondary">← Volver al Perfil</a>
                        <a href="equipos_disponibles.php" class="action-btn action-btn-success">Ver equipos disponibles</a>
                        <a href="crear_equipo_temporal.php" class="action-btn">Crear otro equipo</a>
                    </div>
                </div>
            </div>
            
        <?php elseif (isset($error)): ?>
            <!-- MENSAJE DE ERROR -->
            <div class="message-container">
                <div class="error-message">
                    <h3>❌ Error</h3>
                    <div class="info-box">
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="crear_equipo_temporal.php" class="action-btn action-btn-secondary">← Volver a intentar</a>
                        <a href="../Perfil2.php" class="action-btn">Volver al Perfil</a>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <!-- FORMULARIO DE CREACIÓN -->
            <div class="form-container">
                <div class="page-header">
                    <h2>🏆 Crear Equipo Temporal</h2>
                    <p class="subtitle">Completa el formulario para crear tu equipo</p>
                </div>
                
                <div class="info-box">
                    <p><strong>⚠️ Información importante:</strong></p>
                    <p>• Serás automáticamente el capitán del equipo</p>
                    <p>• Tu equipo aparecerá en la lista de equipos disponibles</p>
                    <p>• Otros jugadores podrán unirse a tu equipo</p>
                    <p>• El equipo se activará cuando se complete la cantidad de jugadores</p>
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="Nombre">Nombre del equipo:</label>
                        <input type="text" id="Nombre" name="Nombre" required 
                               placeholder="Ej: Los Tigres, Águilas FC, etc.">
                    </div>
                    
                    <div class="form-group">
                        <label for="Id_Deporte">Deporte:</label>
                        <select id="Id_Deporte" name="Id_Deporte" required>
                            <option value="">Seleccione un deporte</option>
                            <?php while ($dep = mysqli_fetch_assoc($deportes)) { ?>
                                <option value="<?= htmlspecialchars($dep['Id_Deporte']); ?>">
                                    <?= htmlspecialchars($dep['Nombre']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="Cantidad">Cantidad total de jugadores (incluyéndote):</label>
                        <input type="number" id="Cantidad" name="Cantidad" min="2" max="50" 
                               required placeholder="Mínimo 2 jugadores">
                    </div>
                    
                    <button type="submit">Crear equipo temporal</button>
                </form>
                
                <div class="back-link">
                    <a href="../Perfil2.php">← Volver al Perfil</a> | 
                    <a href="equipos_disponibles.php">Ver equipos disponibles</a>
                </div>
            </div>
        <?php endif; ?>
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
        
        // Validación del formulario
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const nombre = document.getElementById('Nombre').value.trim();
            const deporte = document.getElementById('Id_Deporte').value;
            const cantidad = document.getElementById('Cantidad').value;
            
            if (!nombre) {
                alert('Por favor, ingresa un nombre para el equipo.');
                e.preventDefault();
                return;
            }
            
            if (!deporte) {
                alert('Por favor, selecciona un deporte.');
                e.preventDefault();
                return;
            }
            
            if (cantidad < 2) {
                alert('La cantidad mínima de jugadores es 2.');
                e.preventDefault();
                return;
            }
        });
    </script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>