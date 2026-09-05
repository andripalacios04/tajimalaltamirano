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
            flex-wrap: wrap;
            min-height: 100vh;
            background: linear-gradient(135deg, #140f27ff, #203a43, #1c2a92ff);
            color: #eeeeee;
            position: relative;
            overflow-x: hidden;
        }
        .sidebar {
            width: 250px;
            background: #111820;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 5px 0px 20px #09058aff;
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
        }
        .menu {
            list-style: none;
            width: 100%;
        }
        .menu li {
            padding: 12px;
            margin: 10px 0;
            text-align: center;
            border-radius: 8px;
            transition: all 0.3s ease;
            background-color: rgba(0, 255, 198, 0.1);
        }
        .menu li a {
            color: #ffffff;
            text-decoration: none;
            font-weight: bold;
            display: block;
        }
        .menu li:hover {
            background-color: rgba(244, 16, 16, 0.3);
            transform: scale(1.05);
        }
        .main-content {
            flex: 1;
            padding: 40px;
            position: relative;
        }
        .header h1 {
            font-size: 32px;
            color: #ffffff;
            margin-bottom: 30px;
        }
        .perfil-card {
            background: #1b1f27;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0px 0px 20px #001affff;
            max-width: 600px;
            margin: auto;
            text-align: center;
            width: 100%;
        }
        .perfil-card h2 {
            text-align: center;
            color: #ff0000ff;
            margin-bottom: 40px;
        }

        /* Botón circular principal */
        .circle-btn {
            width: 160px;
            height: 160px;
            background-color: #ff0000ff;
            border-radius: 50%;
            color: #ffffff;
            font-weight: bold;
            font-size: 1.5rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto;
            box-shadow: 0 0 30px #ff0000ff;
            text-align: center;
        }
        .circle-btn:hover {
            background-color: #e00000ff;
            transform: scale(1.1);
            box-shadow: 0 0 50px #ff1100ff;
        }

        /* Contenedor fijo inferior */
        .bottom-buttons {
            position: fixed;
            bottom: 40px;
            left: 50%;
            transform: translateX(-70%);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 60px;
            z-index: 100;
        }

        @media screen and (max-width: 768px) {
            .sidebar {
                display: none;
            }
            .main-content {
                width: 100%;
                padding: 20px;
            }
            .perfil-card {
                width: 100%;
            }
            .circle-btn {
                width: 120px;
                height: 120px;
                font-size: 1.2rem;
            }
            .bottom-buttons {
                gap: 30px;
                bottom: 20px;
            }
        }
    </style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Perfil de Usuario - RETAME'); } ?>

    <div class="sidebar">
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

 <!-- BOTONES CIRCULARES PARA RETAS -->
<div class="bottom-buttons" style="display: flex; justify-content: center; gap: 30px; margin-top: 20px;">

    <!-- BOTÓN CREAR RETA -->
    <a href="crearretatemp.php">
        <button class="circle-btn">CREAR<br>RETA</button>
    </a>

    <!-- BOTÓN UNIRME A RETA -->
    <a href="unirmeretatemp.php">
        <button class="circle-btn">UNIRME<br>A RETA</button>
    </a>

</div>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>
