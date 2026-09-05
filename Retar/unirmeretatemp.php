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

$Id_Usuario = isset($usuarios['Id_Usuario']) ? intval($usuarios['Id_Usuario']) : 0;

$tieneEquipo = false;

if ($Id_Usuario > 0) {
    $sql = "SELECT Id_Equipo FROM usuarios WHERE Id_Usuario = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $Id_Usuario);
    $stmt->execute();
    $stmt->bind_result($Id_Equipo);
    if ($stmt->fetch() && !empty($Id_Equipo)) {
        $tieneEquipo = true;
    }
    $stmt->close();
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
    <title>Perfil de Usuario - RETAME</title>
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
            margin-left: 250px; /* Espacio para sidebar */
            min-height: 100vh;
        }
        .header h1 {
            font-size: 32px;
            color: #ffffff;
            margin-bottom: 30px;
            text-align: center;
        }
        
        /* TARJETA DE PERFIL */
        .perfil-card {
            background: rgba(27, 31, 39, 0.9);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0px 0px 30px rgba(0, 26, 255, 0.5);
            max-width: 800px;
            margin: 50px auto;
            text-align: center;
            width: 100%;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 26, 255, 0.2);
        }
        .perfil-card h2 {
            color: #ff0000;
            margin-bottom: 50px;
            font-size: 28px;
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.3);
        }
        
        /* GRID DE BOTONES PRINCIPALES */
        .main-buttons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
            margin: 40px 0;
        }
        
        /* BOTONES CIRCULARES */
        .circle-btn {
            width: 160px;
            height: 160px;
            background: linear-gradient(135deg, #ff0000, #e00000);
            border-radius: 50%;
            color: #ffffff;
            font-weight: bold;
            font-size: 1.2rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            box-shadow: 0 0 30px rgba(255, 0, 0, 0.5);
            flex-direction: column;
            padding: 15px;
            margin: 0 auto;
        }
        .circle-btn:hover {
            background: linear-gradient(135deg, #e00000, #c00000);
            transform: scale(1.1);
            box-shadow: 0 0 50px rgba(255, 17, 0, 0.8);
        }
        .circle-btn span {
            display: block;
            margin-top: 5px;
            font-size: 0.9rem;
            line-height: 1.2;
        }
        
        /* BOTONES INFERIORES */
        .bottom-buttons {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 80px;
            padding-bottom: 40px;
            flex-wrap: wrap;
        }
        .bottom-btn {
            width: 140px;
            height: 140px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            border-radius: 50%;
            color: #ffffff;
            font-weight: bold;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            box-shadow: 0 0 20px rgba(0, 123, 255, 0.5);
            flex-direction: column;
            padding: 10px;
        }
        .bottom-btn:hover {
            background: linear-gradient(135deg, #0056b3, #004494);
            transform: translateY(-5px);
            box-shadow: 0 0 30px rgba(0, 123, 255, 0.8);
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
            .perfil-card {
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
            .perfil-card {
                margin: 20px auto;
                padding: 25px;
            }
            .circle-btn {
                width: 140px;
                height: 140px;
                font-size: 1.1rem;
            }
            .bottom-btn {
                width: 120px;
                height: 120px;
                font-size: 0.9rem;
            }
            .bottom-buttons {
                gap: 20px;
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
            .main-buttons-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .circle-btn {
                width: 120px;
                height: 120px;
                font-size: 1rem;
            }
            .bottom-btn {
                width: 100px;
                height: 100px;
                font-size: 0.8rem;
            }
            .bottom-buttons {
                gap: 15px;
                margin-top: 40px;
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Perfil de Usuario - RETAME'); } ?>

    <!-- Botón hamburguesa solo para móviles -->
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Overlay para cerrar sidebar en móviles -->
    <div class="overlay" onclick="toggleSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <img src="assets/doctor.png" class="doctor-logo" alt="Logo Doctor">
        <h2>🏥 RETAME</h2>
        <ul class="menu">
            <li><a href="dashboard.html">🏠 Inicio</a></li>
            <li><a href="agendar.html">📅 Agendar Reta</a></li>
            <li><a href="mis_citas.html">📋 Mis Retas</a></li>
            <li><a href="Configurar mi perfil.php">👤 Configurar Perfil</a></li> 
            <li><a href="Configurar mi perfil.php">👥 Unirme a un equipo</a></li> 
            <li><a href="lougout.php">🚪 Cerrar Sesión</a></li> 
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>🏠 Bienvenido a RETAME</h1>
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