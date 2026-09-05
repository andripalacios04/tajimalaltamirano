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
$NombreUser = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

$id_liga = isset($_GET['id_liga']) ? trim($_GET['id_liga']) : '';
$id_adminsolicitud = isset($_GET['id_adminsolicitud']) ? trim($_GET['id_adminsolicitud']) : '';

if ($id_liga === '') {
    header("Location: liga.php");
    exit();
}

function h($v) { return htmlspecialchars((string)$v); }

$orden = isset($_GET['orden']) ? trim($_GET['orden']) : 'nombre_az';

$ordenSql = "r.Nombre ASC";
$ordenLabel = "Nombre (A - Z)";

if ($orden === 'nombre_za') {
    $ordenSql = "r.Nombre DESC";
    $ordenLabel = "Nombre (Z - A)";
} elseif ($orden === 'goles_desc') {
    $ordenSql = "er.goles DESC, r.Nombre ASC";
    $ordenLabel = "Goles (Mayor a menor)";
} elseif ($orden === 'goles_asc') {
    $ordenSql = "er.goles ASC, r.Nombre ASC";
    $ordenLabel = "Goles (Menor a mayor)";
}

$ligaNombre = $id_liga;
$sqlLiga = "SELECT Nombre FROM ligas WHERE Id_Liga = ? LIMIT 1";
$stmtLiga = $conn->prepare($sqlLiga);
if ($stmtLiga) {
    $stmtLiga->bind_param("s", $id_liga);
    $stmtLiga->execute();
    $resLiga = $stmtLiga->get_result();
    if ($resLiga && $resLiga->num_rows > 0) {
        $r = $resLiga->fetch_assoc();
        $ligaNombre = $r['Nombre'];
    }
    $stmtLiga->close();
}

$stats = [];

$sql = "SELECT
            er.id_estadistica,
            er.id_retador,
            r.Nombre AS retador_nombre,
            er.id_equipo,
            e.Nombre AS equipo_nombre,
            er.tarjetas_amarillas,
            er.tarjetas_rojas,
            er.goles,
            er.asistencias,
            er.faltas_cometidas,
            er.expulsion,
            er.lesiones,
            er.fecha_actualizacion,
            er.hora_actualizacion
        FROM estadisticasretador er
        INNER JOIN retador r ON r.Id_Retador = er.id_retador
        INNER JOIN equipo e ON e.Id_Equipo = er.id_equipo
        INNER JOIN liga_equipo le ON le.Id_Equipo = er.id_equipo AND le.Id_Liga = er.id_liga
        WHERE er.id_liga = ?
        ORDER BY $ordenSql";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparando consulta: " . $conn->error);
}
$stmt->bind_param("s", $id_liga);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $stats[] = $row;
}
$stmt->close();

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
<title>Estadísticas Retador - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins', sans-serif;}
body{display:flex;min-height:100vh;background:linear-gradient(135deg,#140f27,#203a43,#1c2a92);color:#eee;overflow-x:hidden;position:relative;}

.sidebar{width:250px;background:#111820;padding:20px;display:flex;flex-direction:column;align-items:center;box-shadow:5px 0 20px rgba(9,5,138,0.7);position:fixed;height:100vh;z-index:1000;}
.sidebar h2{color:#fff;margin-bottom:20px;text-align:center;font-size:18px;}
.sidebar img{width:90px;height:90px;margin-bottom:10px;border-radius:50%;border:3px solid #00ffc6;}
.menu{list-style:none;width:100%;margin-top:20px;}
.menu li{padding:12px;margin:10px 0;border-radius:8px;background:rgba(0,255,198,0.1);text-align:center;transition:all 0.3s ease;border:1px solid rgba(0,255,198,0.2);}
.menu li a{color:#fff;text-decoration:none;font-weight:bold;display:block;font-size:14px;}
.menu li:hover{background:rgba(244,16,16,0.3);transform:translateX(5px);border-color:rgba(244,16,16,0.5);}

.main-content{flex:1;padding:40px;margin-left:250px;min-height:100vh;position:relative;z-index:1;}
.main-content h1{font-size:32px;color:#ffffff;margin-bottom:18px;text-align:center;}

.card{background:rgba(27,31,39,0.9);padding:26px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:1250px;margin:0 auto;text-align:center;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}
.subtext{opacity:0.9;font-size:14px;margin-top:8px;}

.controls{display:flex;gap:12px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:18px;margin-bottom:18px;}
select{padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.25);color:#fff;outline:none;}
.btn{border:none;padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:900;letter-spacing:0.5px;font-size:14px;transition:transform 0.2s ease,opacity 0.2s ease;text-decoration:none;display:inline-block;}
.btn:active{transform:scale(0.98);}
.btn-secondary{background:rgba(255,255,255,0.10);color:#fff;border:1px solid rgba(255,255,255,0.18);}
.btn-primary{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#0b1220;box-shadow:0 0 18px rgba(0,255,198,0.25);}

.table-wrap{margin-top:16px;overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,0.12);}
table{width:100%;border-collapse:collapse;min-width:1200px;background:rgba(0,0,0,0.22);}
th,td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,0.10);text-align:center;font-size:13px;white-space:nowrap;}
th{background:rgba(0,26,255,0.22);color:#00ffc6;font-weight:900;}
tr:hover td{background:rgba(255,255,255,0.05);}
.left{text-align:left;font-weight:900;}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(0,255,198,0.14);border:1px solid rgba(0,255,198,0.22);font-weight:900;font-size:12px;}
.empty{padding:18px;border-radius:16px;border:1px dashed rgba(255,255,255,0.25);background:rgba(0,0,0,0.22);max-width:780px;margin:18px auto 0 auto;}
@media(max-width:768px){.main-content{margin-left:0;padding:20px;}}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Estadísticas Retador - RETAME'); } ?>

<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio Liga</a></li>
        <li><a href="Solicitudes.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">📋 Solicitudes</a></li>
        <li><a href="AdminEquipos.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">👥 Equipos</a></li>
        <li><a href="AdminCalendario.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">📅 Calendario</a></li>
        <li><a href="AdminTablaGeneral.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">📊 Tabla General</a></li>
        <li><a href="AdminJugadores.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">👤 Jugadores</a></li>
        <li><a href="AdminEstadisticasRetador.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">📈 Estadísticas Retador</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>📈 Estadísticas de Retadores</h1>

    <div class="card">
        <div class="subtext">Liga: <b><?php echo h($ligaNombre); ?></b> • Usuario: <b><?php echo $NombreUser; ?></b></div>
        <div class="subtext">Orden actual: <span class="badge"><?php echo h($ordenLabel); ?></span></div>

        <form class="controls" method="GET" action="">
            <input type="hidden" name="id_liga" value="<?php echo h($id_liga); ?>">
            <input type="hidden" name="id_adminsolicitud" value="<?php echo h($id_adminsolicitud); ?>">

            <select name="orden">
                <option value="nombre_az" <?php echo ($orden==='nombre_az')?'selected':''; ?>>Nombre (A - Z)</option>
                <option value="nombre_za" <?php echo ($orden==='nombre_za')?'selected':''; ?>>Nombre (Z - A)</option>
                <option value="goles_desc" <?php echo ($orden==='goles_desc')?'selected':''; ?>>Goles (Mayor a menor)</option>
                <option value="goles_asc" <?php echo ($orden==='goles_asc')?'selected':''; ?>>Goles (Menor a mayor)</option>
            </select>

            <button class="btn btn-primary" type="submit">Filtrar</button>

            <a class="btn btn-secondary" href="AdminEstadisticasRetador.php?id_liga=<?php echo h($id_liga); ?>&id_adminsolicitud=<?php echo h($id_adminsolicitud); ?>">Reset</a>
        </form>

        <?php if (empty($stats)): ?>
            <div class="empty">No hay estadísticas registradas para retadores en esta liga.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Equipo</th>
                            <th>Retador</th>
                            <th>Goles</th>
                            <th>Asistencias</th>
                            <th>TA</th>
                            <th>TR</th>
                            <th>Faltas</th>
                            <th>Lesiones</th>
                            <th>Expulsión</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($stats as $r): ?>
                        <tr>
                            <td><b><?php echo $i; ?></b></td>
                            <td class="left"><?php echo h($r['equipo_nombre']); ?></td>
                            <td class="left"><?php echo h($r['retador_nombre']); ?></td>
                            <td><b><?php echo (int)$r['goles']; ?></b></td>
                            <td><?php echo (int)$r['asistencias']; ?></td>
                            <td><?php echo (int)$r['tarjetas_amarillas']; ?></td>
                            <td><?php echo (int)$r['tarjetas_rojas']; ?></td>
                            <td><?php echo (int)$r['faltas_cometidas']; ?></td>
                            <td><?php echo (int)$r['lesiones']; ?></td>
                            <td><?php echo h($r['expulsion']); ?></td>
                            <td><?php echo h($r['fecha_actualizacion']); ?></td>
                            <td><?php echo h($r['hora_actualizacion']); ?></td>
                        </tr>
                        <?php $i++; endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="subtext" style="margin-top:14px;">
                Mostrando: <b><?php echo count($stats); ?></b> registros
            </div>
        <?php endif; ?>

        <div style="margin-top:20px;">
            <a class="btn btn-secondary" href="liga.php">Volver</a>
        </div>
    </div>
</div>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>