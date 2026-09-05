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

$ligaInfo = null;
$mensajeError = '';
$mensajeExito = '';

$deportes = [];
$sql_deportes = "SELECT Id_Deporte, Nombre FROM deporte ORDER BY Nombre";
$result_deportes = $conn->query($sql_deportes);
if ($result_deportes && $result_deportes->num_rows > 0) {
    while ($row = $result_deportes->fetch_assoc()) {
        $deportes[$row['Id_Deporte']] = $row['Nombre'];
    }
}

$estados = ['inscripciones', 'Iniciado', 'Pausado', 'Cancelado', 'Finalizado'];

$id_admin = '';
$adminsolicitud_id = '';
$id_adminsolicitud = isset($_GET['id_adminsolicitud']) ? trim($_GET['id_adminsolicitud']) : '';

if (!empty($Id_Retador)) {
    $sql_admin = "SELECT id_admin, id_adminsolicitud
                  FROM adminsolicitud
                  WHERE id_retador = ? AND Estado = 'Activo' AND Tipo = 'liga'
                  LIMIT 1";

    $stmt_admin = $conn->prepare($sql_admin);
    if ($stmt_admin) {
        $stmt_admin->bind_param("s", $Id_Retador);
        $stmt_admin->execute();
        $stmt_admin->store_result();

        if ($stmt_admin->num_rows > 0) {
            $stmt_admin->bind_result($id_admin, $adminsolicitud_id);
            $stmt_admin->fetch();
        } else {
            $mensajeError = "No tienes ligas activas para administrar.";
        }
        $stmt_admin->close();
    } else {
        $mensajeError = "Error al preparar consulta de administración.";
    }
}

if (!empty($id_admin) && !empty($adminsolicitud_id)) {

    if (empty($id_adminsolicitud)) {
        $id_adminsolicitud = $adminsolicitud_id;
    } else {
        if ($id_adminsolicitud !== $adminsolicitud_id) {
            $id_adminsolicitud = $adminsolicitud_id;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_cambios'])) {
        $id_liga_original = trim($_POST['id_liga_original']);
        $nuevo_nombre = trim($_POST['Nombre']);
        $nuevo_deporte = isset($_POST['Id_Deporte']) ? trim($_POST['Id_Deporte']) : '';
        $nuevo_cp = trim($_POST['CodigoPostal']);
        $nuevo_fecha_inicio = isset($_POST['FechaInicio']) ? trim($_POST['FechaInicio']) : '';
        $nuevo_fecha_fin = isset($_POST['FechaFin']) ? trim($_POST['FechaFin']) : '';
        $nuevo_estado = isset($_POST['Estado']) ? trim($_POST['Estado']) : '';
        $nueva_descripcion = trim($_POST['Descripcion']);
        $nombre_original = isset($_POST['nombre_original']) ? trim($_POST['nombre_original']) : '';

        $errores = [];

        if (empty($nuevo_nombre)) {
            $errores[] = "El nombre no puede estar vacío";
        } elseif (preg_match('/^\d/', $nuevo_nombre)) {
            $errores[] = "El nombre no puede iniciar con un número";
        }

        if (empty($nuevo_cp)) {
            $errores[] = "El código postal no puede estar vacío";
        } elseif (!is_numeric($nuevo_cp)) {
            $errores[] = "El código postal debe contener solo números";
        }

        if (empty($nuevo_fecha_inicio)) {
            $errores[] = "La fecha de inicio no puede estar vacía";
        }

        if (empty($nuevo_estado)) {
            $errores[] = "El estado no puede estar vacío";
        }

        if (empty($nuevo_deporte)) {
            $errores[] = "El deporte no puede estar vacío";
        }

        $id_liga_actualizar = $id_liga_original;

        $nueva_id_liga = null;

        if (empty($errores)) {
            if ($nuevo_nombre != $nombre_original) {
                $sql_fecha = "SELECT FechaCreacion FROM ligas WHERE Id_Liga = ?";
                $stmt_fecha = $conn->prepare($sql_fecha);
                if ($stmt_fecha) {
                    $stmt_fecha->bind_param("s", $id_liga_original);
                    $stmt_fecha->execute();
                    $stmt_fecha->bind_result($fecha_creacion);
                    $stmt_fecha->fetch();
                    $stmt_fecha->close();
                } else {
                    $errores[] = "No se pudo preparar consulta para fecha de creación.";
                }

                if (empty($errores)) {
                    $nueva_id_liga = preg_replace('/[^a-zA-Z0-9]/', '', $nuevo_nombre) . '_' . date('Ymd', strtotime($fecha_creacion));

                    $sql_check = "SELECT COUNT(*) FROM ligas WHERE Id_Liga = ? AND Id_Liga != ?";
                    $stmt_check = $conn->prepare($sql_check);
                    if ($stmt_check) {
                        $stmt_check->bind_param("ss", $nueva_id_liga, $id_liga_original);
                        $stmt_check->execute();
                        $stmt_check->bind_result($count);
                        $stmt_check->fetch();
                        $stmt_check->close();
                    } else {
                        $errores[] = "No se pudo preparar verificación de ID.";
                    }

                    if (empty($errores) && $count > 0) {
                        $errores[] = "Ya existe una liga con ese nombre. Por favor, elige otro nombre.";
                        $nueva_id_liga = null;
                    } else {
                        $id_liga_actualizar = $nueva_id_liga;
                    }
                }
            }

            if (empty($errores)) {
                $fecha_fin_valor = empty($nuevo_fecha_fin) ? '' : $nuevo_fecha_fin;
                $descripcion_valor = empty($nueva_descripcion) ? '' : $nueva_descripcion;

                $sql_update = "UPDATE ligas SET 
                                Id_Liga = ?,
                                Nombre = ?,
                                Id_Deporte = ?,
                                CodigoPostal = ?,
                                FechaInicio = ?,
                                FechaFin = NULLIF(?, ''),
                                Estado = ?,
                                Descripcion = NULLIF(?, '')
                               WHERE Id_Liga = ?";

                $stmt_update = $conn->prepare($sql_update);
                if ($stmt_update) {
                    $stmt_update->bind_param(
                        "sssssssss",
                        $id_liga_actualizar,
                        $nuevo_nombre,
                        $nuevo_deporte,
                        $nuevo_cp,
                        $nuevo_fecha_inicio,
                        $fecha_fin_valor,
                        $nuevo_estado,
                        $descripcion_valor,
                        $id_liga_original
                    );

                    if ($stmt_update->execute()) {

                        if (!empty($nueva_id_liga) && $nueva_id_liga != $id_liga_original) {

                            $sql1 = "UPDATE liga_equipo SET Id_Liga = ? WHERE Id_Liga = ?";
                            $st1 = $conn->prepare($sql1);
                            if ($st1) {
                                $st1->bind_param("ss", $nueva_id_liga, $id_liga_original);
                                $st1->execute();
                                $st1->close();
                            }

                            $sql2 = "UPDATE calendario SET id_liga = ? WHERE id_liga = ?";
                            $st2 = $conn->prepare($sql2);
                            if ($st2) {
                                $st2->bind_param("ss", $nueva_id_liga, $id_liga_original);
                                $st2->execute();
                                $st2->close();
                            }

                            $sql_update_admin = "UPDATE adminsolicitud SET id_admin = ? WHERE id_adminsolicitud = ? AND id_retador = ? AND Tipo = 'liga' LIMIT 1";
                            $stmt_update_admin = $conn->prepare($sql_update_admin);
                            if ($stmt_update_admin) {
                                $stmt_update_admin->bind_param("sss", $nueva_id_liga, $adminsolicitud_id, $Id_Retador);
                                $stmt_update_admin->execute();
                                $stmt_update_admin->close();
                            }

                            $id_admin = $nueva_id_liga;
                            $id_liga_original = $nueva_id_liga;
                        }

                        $mensajeExito = "Los cambios se guardaron correctamente.";
                    } else {
                        $errores[] = "Error al guardar los cambios: " . $conn->error;
                    }
                    $stmt_update->close();
                } else {
                    $errores[] = "Error al preparar actualización.";
                }
            }
        }

        if (!empty($errores)) {
            $mensajeError = implode("<br>", $errores);
        }
    }

    $sql_liga = "SELECT l.*, d.Nombre as DeporteNombre,
                (SELECT COUNT(*) FROM liga_equipo WHERE Id_Liga = l.Id_Liga) as EquiposInscritos
                FROM ligas l 
                LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte 
                WHERE l.Id_Liga = ?";

    $stmt_liga = $conn->prepare($sql_liga);
    if ($stmt_liga) {
        $stmt_liga->bind_param("s", $id_admin);
        $stmt_liga->execute();
        $res_liga = $stmt_liga->get_result();

        if ($res_liga && $res_liga->num_rows > 0) {
            $ligaInfo = $res_liga->fetch_assoc();
        } else {
            $mensajeError = "No se encontró información de la liga.";
        }
        $stmt_liga->close();
    } else {
        $mensajeError = "Error al preparar consulta de liga.";
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
<title>Administrar Liga - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif; }
body { display:flex; min-height:100vh; background:linear-gradient(135deg,#140f27,#203a43,#1c2a92); color:#eee; overflow-x:hidden; position: relative; }
.bg-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; }
.particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 20s infinite linear; }
@keyframes float { 0%, 100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-100vh) rotate(180deg); } }
.sidebar { width:250px; background:#111820; padding:20px; display:flex; flex-direction:column; align-items:center; box-shadow:5px 0 20px rgba(9,5,138,0.7); position:fixed; height:100vh; z-index:1000; }
.sidebar h2 { color:#fff; margin-bottom:20px; text-align:center; font-size:18px; }
.sidebar img { width:90px; height:90px; margin-bottom:10px; border-radius:50%; border:3px solid #00ffc6; }
.menu { list-style:none; width:100%; margin-top:20px; }
.menu li { padding:12px; margin:10px 0; border-radius:8px; background:rgba(0,255,198,0.1); text-align:center; transition:all 0.3s ease; border:1px solid rgba(0,255,198,0.2); }
.menu li a { color:#fff; text-decoration:none; font-weight:bold; display:block; font-size:14px; }
.menu li:hover { background:rgba(244,16,16,0.3); transform:translateX(5px); border-color:rgba(244,16,16,0.5); }
.main-content { flex:1; padding:40px; margin-left:250px; min-height:100vh; position: relative; z-index: 1; }
.main-content h1 { font-size:32px; color:#ffffff; margin-bottom:30px; text-align:center; }
.liga-info-section { background: rgba(27, 31, 39, 0.9); padding: 40px; border-radius: 20px; box-shadow: 0 0 30px rgba(0, 26, 255, 0.5); max-width: 1000px; margin: 0 auto 40px; backdrop-filter: blur(10px); border: 1px solid rgba(0, 26, 255, 0.2); }
.liga-info-section h2 { color: #00ffc6; margin-bottom: 30px; font-size: 28px; text-align: center; border-bottom: 2px solid rgba(0, 255, 198, 0.3); padding-bottom: 15px; }
.info-table { width: 100%; border-collapse: collapse; margin: 25px 0; background: rgba(255, 255, 255, 0.05); border-radius: 15px; overflow: hidden; }
.info-table th { background: rgba(0, 26, 255, 0.3); color: #00ffc6; padding: 20px; text-align: left; font-size: 1.1rem; border-bottom: 2px solid rgba(0, 255, 198, 0.3); }
.info-table td { padding: 20px; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.1); font-size: 1rem; position:relative; }
.info-table tr:last-child td { border-bottom: none; }
.info-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
.acciones-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 40px; padding: 30px; background: rgba(27, 31, 39, 0.8); border-radius: 20px; box-shadow: 0 0 20px rgba(0, 255, 198, 0.2); border: 1px solid rgba(0, 255, 198, 0.3); }
.accion-btn { background: linear-gradient(135deg, #001aff, #0015cc); color: white; padding: 20px; border: none; border-radius: 15px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center; min-height: 120px; }
.accion-btn:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0, 26, 255, 0.4); background: linear-gradient(135deg, #0015cc, #0011aa); }
.accion-btn.solicitudes { background: linear-gradient(135deg, #9d00ff, #7a00cc); }
.accion-btn.solicitudes:hover { background: linear-gradient(135deg, #7a00cc, #5a0099); box-shadow: 0 10px 25px rgba(157, 0, 255, 0.4); }
.accion-btn.equipos { background: linear-gradient(135deg, #00ffc6, #00cc9d); }
.accion-btn.equipos:hover { background: linear-gradient(135deg, #00cc9d, #00aa7a); box-shadow: 0 10px 25px rgba(0, 255, 198, 0.4); }
.accion-btn.calendario { background: linear-gradient(135deg, #ff9800, #ff5722); }
.accion-btn.calendario:hover { background: linear-gradient(135deg, #ff5722, #e64a19); box-shadow: 0 10px 25px rgba(255, 152, 0, 0.4); }
.accion-btn.tabla-general { background: linear-gradient(135deg, #2196F3, #1976D2); }
.accion-btn.tabla-general:hover { background: linear-gradient(135deg, #1976D2, #1565C0); box-shadow: 0 10px 25px rgba(33, 150, 243, 0.4); }
.accion-btn.estadisticas { background: linear-gradient(135deg, #4CAF50, #388E3C); }
.accion-btn.estadisticas:hover { background: linear-gradient(135deg, #388E3C, #2E7D32); box-shadow: 0 10px 25px rgba(76, 175, 80, 0.4); }
.accion-btn.canchas { background: linear-gradient(135deg, #FFC107, #FFA000); }
.accion-btn.canchas:hover { background: linear-gradient(135deg, #FFA000, #FF8F00); box-shadow: 0 10px 25px rgba(255, 193, 7, 0.4); }
.accion-btn.arbitros { background: linear-gradient(135deg, #E91E63, #C2185B); }
.accion-btn.arbitros:hover { background: linear-gradient(135deg, #C2185B, #AD1457); box-shadow: 0 10px 25px rgba(233, 30, 99, 0.4); }
.accion-btn.jugadores { background: linear-gradient(135deg, #607D8B, #455A64); }
.accion-btn.jugadores:hover { background: linear-gradient(135deg, #455A64, #37474F); box-shadow: 0 10px 25px rgba(96, 125, 139, 0.4); }

.alert { padding: 20px; border-radius: 12px; margin-bottom: 30px; animation: fadeIn 0.5s ease; text-align: center; }
.alert-error { background: rgba(255, 0, 0, 0.1); border-left: 4px solid #ff0000; color: #ff6b6b; }
.alert-info { background: rgba(0, 100, 255, 0.1); border-left: 4px solid #0064ff; color: #64b5f6; }
.alert-success { background: rgba(0, 255, 0, 0.1); border-left: 4px solid #00ff00; color: #aaffaa; padding: 15px; border-radius: 8px; margin-bottom: 20px; animation: fadeIn 0.5s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

.btn-retar { position: fixed; bottom: 20px; right: 20px; width: 90px; height: 90px; background: linear-gradient(135deg,#ff0000,#e00000); color: #fff; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 16px; font-weight: bold; text-decoration: none; box-shadow: 0 0 20px rgba(255,0,0,0.7); transition: all 0.3s ease; z-index: 999; border: 2px solid rgba(255,255,255,0.3); }
.btn-ligas { position: fixed; bottom: 130px; right: 20px; width: 90px; height: 90px; background: linear-gradient(135deg,#00aaff,#0066cc); color: #fff; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 16px; font-weight: bold; text-decoration: none; box-shadow: 0 0 20px rgba(0,100,255,0.7); transition: all 0.3s ease; z-index: 998; border: 2px solid rgba(255,255,255,0.3); }
.btn-retar:hover, .btn-ligas:hover { transform: scale(1.1); }
.menu-toggle { display:none; }
@media screen and (max-width: 768px) {
    .sidebar { position: fixed; top: 0; left: -250px; width: 250px; height: 100%; transition: left 0.3s ease; z-index: 1000; }
    .sidebar.active { left: 0; }
    .main-content { margin-left: 0; padding: 20px; }
    .menu-toggle { display:block; position: fixed; top: 20px; left: 20px; background: rgba(255, 0, 0, 0.8); color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; z-index: 1001; font-size: 1.2rem; }
    .acciones-container { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; padding: 20px; }
    .accion-btn { padding: 15px; font-size: 14px; min-height: 100px; }
    .info-table th, .info-table td { padding: 15px 10px; font-size: 0.9rem; }
}
.overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 999; }
.overlay.active { display: block; }

.editable-field { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(0, 255, 198, 0.3); border-radius: 5px; padding: 8px 12px; color: #fff; width: 100%; transition: all 0.3s ease; }
.editable-field:focus { outline: none; border-color: #00ffc6; box-shadow: 0 0 10px rgba(0, 255, 198, 0.3); }
.editable-field[readonly] { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
.editable-select { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(0, 255, 198, 0.3); border-radius: 5px; padding: 8px 12px; color: #fff; width: 100%; }
.editable-select option { background: #1b1f27; color: #fff; }
.editable-select:disabled { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
.editable-date { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(0, 255, 198, 0.3); border-radius: 5px; padding: 8px 12px; color: #fff; width: 100%; }
.editable-date::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
.editable-date:disabled { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
.editable-textarea { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(0, 255, 198, 0.3); border-radius: 5px; padding: 8px 12px; color: #fff; width: 100%; min-height: 100px; resize: vertical; font-family: inherit; }
.editable-textarea:disabled { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }

.btn-editar, .btn-guardar, .btn-cancelar { padding: 12px 24px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; margin: 5px; font-size: 16px; }
.btn-editar { background: linear-gradient(135deg, #2196F3, #1976D2); color: white; }
.btn-editar:hover { background: linear-gradient(135deg, #1976D2, #1565C0); transform: translateY(-2px); }
.btn-guardar { background: linear-gradient(135deg, #4CAF50, #388E3C); color: white; }
.btn-guardar:hover { background: linear-gradient(135deg, #388E3C, #2E7D32); transform: translateY(-2px); }
.btn-cancelar { background: linear-gradient(135deg, #f44336, #d32f2f); color: white; }
.btn-cancelar:hover { background: linear-gradient(135deg, #d32f2f, #c62828); transform: translateY(-2px); }
.btn-editar:disabled, .btn-guardar:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.btn-container { display: flex; justify-content: center; gap: 15px; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
.editing-indicator { display: inline-block; background: #ff9800; color: #000; padding: 3px 8px; border-radius: 3px; font-size: 0.8em; margin-left: 10px; animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 0.7; } 50% { opacity: 1; } 100% { opacity: 0.7; } }
.field-error { border-color: #ff0000 !important; box-shadow: 0 0 5px rgba(255, 0, 0, 0.5) !important; }
.error-message { color: #ff6b6b; font-size: 0.9em; margin-top: 5px; display: none; }
.nueva-id-preview { color: #00ffc6; font-size: 0.9em; margin-top: 5px; display: none; }
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Administrar Liga - RETAME'); } ?>

<div class="bg-particles" id="particles"></div>
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>
<div class="overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="UnirmeLiga.php">👥 Unirme a una liga</a></li>
        <li><a href="CrearLiga.php">👥 Crear Una liga</a></li>
        <li><a href="Retar/Retas_Program.php">📋 Solicitudes</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>⚙️ Administración de Liga</h1>

    <?php if ($mensajeError): ?>
        <div class="alert alert-error">
            <strong>⚠️ Error:</strong> <?php echo $mensajeError; ?>
        </div>
    <?php endif; ?>

    <?php if ($mensajeExito): ?>
        <div class="alert-success">
            <strong>✅ Éxito:</strong> <?php echo $mensajeExito; ?>
        </div>
    <?php endif; ?>

    <?php if ($ligaInfo): ?>
    <div class="liga-info-section">
        <h2>📊 Información de la Liga 
            <span id="editing-indicator" class="editing-indicator" style="display: none;">🔄 Editando</span>
        </h2>

        <form id="form-editar-liga" method="POST" action="">
            <input type="hidden" name="id_liga_original" value="<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>">
            <input type="hidden" name="nombre_original" value="<?php echo htmlspecialchars($ligaInfo['Nombre']); ?>">

            <table class="info-table">
                <tr>
                    <th width="30%">🏆 Nombre de la Liga</th>
                    <td>
                        <input type="text" 
                               class="editable-field" 
                               name="Nombre" 
                               value="<?php echo htmlspecialchars($ligaInfo['Nombre']); ?>"
                               readonly
                               data-original="<?php echo htmlspecialchars($ligaInfo['Nombre']); ?>"
                               oninput="validarNombre(this)">
                        <div class="error-message" id="error-nombre"></div>
                        <div class="nueva-id-preview" id="nueva-id-preview"></div>
                        <small style="color: #aaa; font-size: 0.8em;">Si cambias el nombre, la ID se actualizará automáticamente</small>
                    </td>
                </tr>
                <tr>
                    <th>🔢 ID de la Liga</th>
                    <td>
                        <input type="text" class="editable-field" value="<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>" readonly id="display-id-liga">
                    </td>
                </tr>
                <tr>
                    <th>🎯 Deporte</th>
                    <td>
                        <select class="editable-select" name="Id_Deporte" disabled>
                            <option value="">Seleccionar deporte</option>
                            <?php foreach ($deportes as $id => $nombre): ?>
                                <option value="<?php echo htmlspecialchars($id); ?>" <?php echo ($id == $ligaInfo['Id_Deporte']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="error-deporte"></div>
                    </td>
                </tr>
                <tr>
                    <th>📍 Código Postal</th>
                    <td>
                        <input type="text" 
                               class="editable-field" 
                               name="CodigoPostal" 
                               value="<?php echo htmlspecialchars($ligaInfo['CodigoPostal']); ?>"
                               readonly
                               data-original="<?php echo htmlspecialchars($ligaInfo['CodigoPostal']); ?>"
                               oninput="validarCodigoPostal(this)"
                               maxlength="5">
                        <div class="error-message" id="error-cp"></div>
                    </td>
                </tr>
                <tr>
                    <th>📅 Fecha de Creación</th>
                    <td>
                        <input type="text" class="editable-field" value="<?php echo date('d/m/Y', strtotime($ligaInfo['FechaCreacion'])); ?>" readonly>
                    </td>
                </tr>
                <tr>
                    <th>📅 Fecha de Inicio</th>
                    <td>
                        <input type="date" class="editable-date" name="FechaInicio" value="<?php echo htmlspecialchars($ligaInfo['FechaInicio']); ?>" disabled data-original="<?php echo htmlspecialchars($ligaInfo['FechaInicio']); ?>">
                        <div class="error-message" id="error-fecha-inicio"></div>
                    </td>
                </tr>
                <tr>
                    <th>📅 Fecha de Fin</th>
                    <td>
                        <input type="date" class="editable-date" name="FechaFin" value="<?php echo !empty($ligaInfo['FechaFin']) ? htmlspecialchars($ligaInfo['FechaFin']) : ''; ?>" disabled data-original="<?php echo !empty($ligaInfo['FechaFin']) ? htmlspecialchars($ligaInfo['FechaFin']) : ''; ?>">
                    </td>
                </tr>
                <tr>
                    <th>📊 Estado</th>
                    <td>
                        <select class="editable-select" name="Estado" disabled>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo htmlspecialchars($estado); ?>" <?php echo (strtolower($ligaInfo['Estado']) == strtolower($estado)) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($estado)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="error-estado"></div>
                    </td>
                </tr>
                <tr>
                    <th>👥 Equipos Inscritos</th>
                    <td>
                        <input type="text" class="editable-field" value="<?php echo $ligaInfo['EquiposInscritos']; ?> equipos" readonly>
                    </td>
                </tr>
                <tr>
                    <th>👤 Creador</th>
                    <td>
                        <input type="text" class="editable-field" value="<?php echo htmlspecialchars($ligaInfo['Id_Creador']); ?>" readonly>
                    </td>
                </tr>
                <tr>
                    <th>📝 Descripción</th>
                    <td>
                        <textarea class="editable-textarea" name="Descripcion" disabled data-original="<?php echo htmlspecialchars($ligaInfo['Descripcion'] ?? ''); ?>"><?php echo htmlspecialchars($ligaInfo['Descripcion'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>🆔 ID Administración</th>
                    <td>
                        <input type="text" class="editable-field" value="<?php echo htmlspecialchars($id_adminsolicitud); ?>" readonly>
                    </td>
                </tr>
            </table>

            <div class="btn-container">
                <button type="button" class="btn-editar" id="btn-editar" onclick="activarEdicion()">✏️ Editar Información</button>
                <button type="submit" class="btn-guardar" id="btn-guardar" name="guardar_cambios" disabled>💾 Guardar Cambios</button>
                <button type="button" class="btn-cancelar" id="btn-cancelar" onclick="cancelarEdicion()" style="display: none;">❌ Cancelar</button>
            </div>
        </form>

        <h3 style="color: #00ffc6; margin-top: 40px; text-align: center;">🔧 Herramientas de Administración</h3>

        <div class="acciones-container">
            <a href="Solicitudes.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn solicitudes">
                <span style="font-size: 24px;">📋</span><span>Solicitudes</span>
            </a>

            <a href="AdminEquipos.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn equipos">
                <span style="font-size: 24px;">👥</span><span>Equipos</span>
            </a>

            <a href="AdminCalendario.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn calendario">
                <span style="font-size: 24px;">📅</span><span>Calendario</span>
            </a>

            <a href="AdminTablaGeneral.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn tabla-general">
                <span style="font-size: 24px;">📊</span><span>Tabla General</span>
            </a>

            <a href="AdminEstadisticas.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn estadisticas">
                <span style="font-size: 24px;">📈</span><span>Estadísticas</span>
            </a>

            <a href="AdminCanchas.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn canchas">
                <span style="font-size: 24px;">🏟️</span><span>Canchas</span>
            </a>

            <a href="AdminArbitros.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn arbitros">
                <span style="font-size: 24px;">⚖️</span><span>Árbitros</span>
            </a>

            <a href="AdminJugadores.php?id_liga=<?php echo htmlspecialchars($ligaInfo['Id_Liga']); ?>&id_adminsolicitud=<?php echo htmlspecialchars($id_adminsolicitud); ?>" class="accion-btn jugadores">
                <span style="font-size: 24px;">👤</span><span>Jugadores</span>
            </a>
        </div>

        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <p style="color: #aaaaaa; font-size: 0.9rem;">
                ID de Administración: <strong><?php echo htmlspecialchars($id_adminsolicitud); ?></strong>
                <br>
                Usuario: <strong><?php echo htmlspecialchars($Nombre); ?></strong>
            </p>
        </div>
    </div>
    <?php else: ?>
        <div class="alert alert-info">
            <strong>ℹ️ Información:</strong> No hay información de liga para mostrar.
            <br><br>
            <a href="../Perfil2.php" style="color: #00ffc6; text-decoration: none; font-weight: bold;">↩️ Volver al perfil para seleccionar una liga</a>
        </div>
    <?php endif; ?>
</div>

<a href="../Perfil2.php" class="btn-ligas">🏆 INICIO</a>
<a href="Retar/retar.php" class="btn-retar">⚔️ RETAR</a>

<script>
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

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

document.querySelectorAll('.menu a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.overlay');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    }
});

function activarEdicion() {
    $('.editable-field[name="Nombre"]').prop('readonly', false);
    $('.editable-field[name="CodigoPostal"]').prop('readonly', false);
    $('.editable-select').prop('disabled', false);
    $('.editable-date').prop('disabled', false);
    $('.editable-textarea').prop('disabled', false);

    $('#btn-editar').hide();
    $('#btn-guardar').prop('disabled', false);
    $('#btn-cancelar').show();
    $('#editing-indicator').show();
    guardarEstadoOriginal();
}

function cancelarEdicion() {
    $('.editable-field, .editable-select, .editable-date, .editable-textarea').each(function() {
        if ($(this).data('original') !== undefined) {
            $(this).val($(this).data('original'));
        }
    });

    $('.editable-field').prop('readonly', true);
    $('.editable-select').prop('disabled', true);
    $('.editable-date').prop('disabled', true);
    $('.editable-textarea').prop('disabled', true);

    $('#btn-editar').show();
    $('#btn-guardar').prop('disabled', true);
    $('#btn-cancelar').hide();
    $('#editing-indicator').hide();

    $('.field-error').removeClass('field-error');
    $('.error-message').hide().text('');
    $('#nueva-id-preview').hide().text('');
}

function guardarEstadoOriginal() {
    $('.editable-field, .editable-select, .editable-date, .editable-textarea').each(function() {
        $(this).data('original', $(this).val());
    });
}

function validarNombre(input) {
    const nombre = $(input).val().trim();
    const errorElement = $('#error-nombre');
    const previewElement = $('#nueva-id-preview');

    $(input).removeClass('field-error');
    errorElement.hide();
    previewElement.hide();

    if (nombre === '') {
        $(input).addClass('field-error');
        errorElement.text('El nombre no puede estar vacío').show();
        return false;
    }

    if (/^\d/.test(nombre)) {
        $(input).addClass('field-error');
        errorElement.text('El nombre no puede iniciar con un número').show();
        return false;
    }

    const nombreOriginal = $(input).data('original');
    if (nombre !== nombreOriginal) {
        const nombreLimpio = nombre.replace(/[^a-zA-Z0-9]/g, '');
        const fechaCreacion = '<?php echo date("Ymd", strtotime($ligaInfo['FechaCreacion'] ?? date('Y-m-d'))); ?>';
        const nuevoId = nombreLimpio + '_' + fechaCreacion;
        previewElement.html('Nueva ID generada: <strong>' + nuevoId + '</strong>').show();
    }

    return true;
}

function validarCodigoPostal(input) {
    const cp = $(input).val().trim();
    const errorElement = $('#error-cp');

    $(input).removeClass('field-error');
    errorElement.hide();

    if (cp === '') {
        $(input).addClass('field-error');
        errorElement.text('El código postal no puede estar vacío').show();
        return false;
    }

    if (!/^\d+$/.test(cp)) {
        $(input).addClass('field-error');
        errorElement.text('El código postal debe contener solo números').show();
        return false;
    }

    return true;
}

function validarFormulario() {
    let valido = true;

    if (!validarNombre($('.editable-field[name="Nombre"]')[0])) valido = false;
    if (!validarCodigoPostal($('.editable-field[name="CodigoPostal"]')[0])) valido = false;

    const fechaInicio = $('.editable-date[name="FechaInicio"]').val();
    if (!fechaInicio) {
        $('.editable-date[name="FechaInicio"]').addClass('field-error');
        $('#error-fecha-inicio').text('La fecha de inicio no puede estar vacía').show();
        valido = false;
    } else {
        $('.editable-date[name="FechaInicio"]').removeClass('field-error');
        $('#error-fecha-inicio').hide();
    }

    const deporte = $('.editable-select[name="Id_Deporte"]').val();
    if (!deporte) {
        $('.editable-select[name="Id_Deporte"]').addClass('field-error');
        $('#error-deporte').text('Debes seleccionar un deporte').show();
        valido = false;
    } else {
        $('.editable-select[name="Id_Deporte"]').removeClass('field-error');
        $('#error-deporte').hide();
    }

    const estado = $('.editable-select[name="Estado"]').val();
    if (!estado) {
        $('.editable-select[name="Estado"]').addClass('field-error');
        $('#error-estado').text('Debes seleccionar un estado').show();
        valido = false;
    } else {
        $('.editable-select[name="Estado"]').removeClass('field-error');
        $('#error-estado').hide();
    }

    return valido;
}

$('#form-editar-liga').submit(function(e) {
    if (!validarFormulario()) {
        e.preventDefault();
        alert('Por favor, corrige los errores en el formulario antes de guardar.');
        return false;
    }

    const nombreOriginal = $('.editable-field[name="Nombre"]').data('original');
    const nombreNuevo = $('.editable-field[name="Nombre"]').val();

    if (nombreOriginal !== nombreNuevo) {
        const confirmacion = confirm('⚠️ ADVERTENCIA: Estás cambiando el nombre de la liga.\n\nEsto generará una nueva ID automáticamente.\n\n¿Deseas continuar?');
        if (!confirmacion) {
            e.preventDefault();
            return false;
        }
    }

    return true;
});

$('.editable-field[name="Nombre"]').on('input', function() { validarNombre(this); });
$('.editable-field[name="CodigoPostal"]').on('input', function() { validarCodigoPostal(this); });
$('.editable-date[name="FechaInicio"], .editable-select[name="Id_Deporte"], .editable-select[name="Estado"]').on('blur', function() { validarFormulario(); });
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>