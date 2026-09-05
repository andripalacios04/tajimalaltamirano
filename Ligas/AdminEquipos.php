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

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$equiposLiga = [];
$mensajeError = '';
$mensajeOk = '';
$nombre_liga = '';
$editando = false;

$id_liga_admin = isset($_GET['id_liga']) ? trim($_GET['id_liga']) : '';

$equipoEditar = [
    'Id_Equipo' => '',
    'Nombre' => '',
    'cantidad' => '',
    'Id_Deporte' => '',
    'CodigoPostal' => '',
    'Pais' => ''
];

$deportes = [];
$sqlDeportes = "SELECT Id_Deporte, Nombre FROM deporte ORDER BY Nombre ASC";
$resDeportes = $conn->query($sqlDeportes);
if ($resDeportes) {
    while ($dep = $resDeportes->fetch_assoc()) {
        $deportes[] = $dep;
    }
}

if (empty($Id_Retador)) {
    $mensajeError = "Usuario no identificado.";
} elseif ($id_liga_admin === '') {
    $sqlLigaAuto = "SELECT id_admin FROM adminsolicitud WHERE id_retador = ? AND Estado = 'Activo' LIMIT 1";
    $stmtLigaAuto = $conn->prepare($sqlLigaAuto);
    if ($stmtLigaAuto) {
        $stmtLigaAuto->bind_param("s", $Id_Retador);
        $stmtLigaAuto->execute();
        $resLigaAuto = $stmtLigaAuto->get_result();
        if ($resLigaAuto && $resLigaAuto->num_rows > 0) {
            $rowLigaAuto = $resLigaAuto->fetch_assoc();
            $id_liga_admin = $rowLigaAuto['id_admin'];
        } else {
            $mensajeError = "No tienes ligas activas para administrar.";
        }
        $stmtLigaAuto->close();
    } else {
        $mensajeError = "No se pudo verificar la liga administrada.";
    }
}

if ($mensajeError === '' && $id_liga_admin !== '') {
    $sqlValidarAdmin = "SELECT * FROM adminsolicitud WHERE id_retador = ? AND id_admin = ? AND Estado = 'Activo' LIMIT 1";
    $stmtValidarAdmin = $conn->prepare($sqlValidarAdmin);

    if ($stmtValidarAdmin) {
        $stmtValidarAdmin->bind_param("ss", $Id_Retador, $id_liga_admin);
        $stmtValidarAdmin->execute();
        $resValidarAdmin = $stmtValidarAdmin->get_result();

        if (!$resValidarAdmin || $resValidarAdmin->num_rows === 0) {
            $mensajeError = "No tienes permisos activos para administrar esta liga.";
        }

        $stmtValidarAdmin->close();
    } else {
        $mensajeError = "No se pudo validar el acceso a la liga.";
    }
}

if ($mensajeError === '' && $id_liga_admin !== '') {
    $sqlNombreLiga = "SELECT Nombre FROM ligas WHERE Id_Liga = ? LIMIT 1";
    $stmtNombreLiga = $conn->prepare($sqlNombreLiga);
    if ($stmtNombreLiga) {
        $stmtNombreLiga->bind_param("s", $id_liga_admin);
        $stmtNombreLiga->execute();
        $resNombreLiga = $stmtNombreLiga->get_result();
        if ($resNombreLiga && $resNombreLiga->num_rows > 0) {
            $rowNombreLiga = $resNombreLiga->fetch_assoc();
            $nombre_liga = $rowNombreLiga['Nombre'];
        }
        $stmtNombreLiga->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mensajeError === '' && $id_liga_admin !== '') {
    $accion = isset($_POST['accion']) ? trim($_POST['accion']) : '';
    $id_liga_post = isset($_POST['id_liga']) ? trim($_POST['id_liga']) : '';

    if ($id_liga_post !== '' && $id_liga_post !== $id_liga_admin) {
        $mensajeError = "La liga enviada no coincide con la liga administrada.";
    } else {
        if ($accion === 'eliminar') {
            $id_equipo = isset($_POST['id_equipo']) ? trim($_POST['id_equipo']) : '';

            if ($id_equipo === '') {
                $mensajeError = "No se recibió el ID del equipo.";
            } else {
                $sqlDelete = "DELETE FROM liga_equipo WHERE Id_Liga = ? AND Id_Equipo = ?";
                $stmtDelete = $conn->prepare($sqlDelete);

                if ($stmtDelete) {
                    $stmtDelete->bind_param("ss", $id_liga_admin, $id_equipo);
                    if ($stmtDelete->execute()) {
                        $mensajeOk = "El equipo fue eliminado de la liga correctamente.";
                    } else {
                        $mensajeError = "No se pudo eliminar el equipo de la liga.";
                    }
                    $stmtDelete->close();
                } else {
                    $mensajeError = "Error al preparar la eliminación.";
                }
            }
        }

        if ($accion === 'actualizar') {
            $id_equipo = isset($_POST['id_equipo']) ? trim($_POST['id_equipo']) : '';
            $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
            $cantidad = isset($_POST['cantidad']) ? trim($_POST['cantidad']) : '';
            $id_deporte = isset($_POST['id_deporte']) ? trim($_POST['id_deporte']) : '';
            $codigo_postal = isset($_POST['codigo_postal']) ? trim($_POST['codigo_postal']) : '';
            $pais = isset($_POST['pais']) ? trim($_POST['pais']) : '';

            if ($id_equipo === '' || $nombre === '' || $cantidad === '' || $id_deporte === '') {
                $mensajeError = "Completa los campos obligatorios para editar el equipo.";
            } elseif (!ctype_digit($cantidad) || (int)$cantidad <= 0) {
                $mensajeError = "La cantidad de jugadores debe ser un número válido.";
            } elseif ($codigo_postal !== '' && !preg_match('/^[0-9]{5}$/', $codigo_postal)) {
                $mensajeError = "El código postal debe tener 5 dígitos.";
            } else {
                $sqlValidarEquipo = "SELECT Id_Equipo FROM liga_equipo WHERE Id_Liga = ? AND Id_Equipo = ? LIMIT 1";
                $stmtValidarEquipo = $conn->prepare($sqlValidarEquipo);

                if ($stmtValidarEquipo) {
                    $stmtValidarEquipo->bind_param("ss", $id_liga_admin, $id_equipo);
                    $stmtValidarEquipo->execute();
                    $resValidarEquipo = $stmtValidarEquipo->get_result();

                    if ($resValidarEquipo && $resValidarEquipo->num_rows > 0) {
                        $sqlUpdate = "UPDATE equipo SET Nombre = ?, cantidad = ?, Id_Deporte = ?, CodigoPostal = ?, Pais = ? WHERE Id_Equipo = ?";
                        $stmtUpdate = $conn->prepare($sqlUpdate);

                        if ($stmtUpdate) {
                            $stmtUpdate->bind_param("sissss", $nombre, $cantidad, $id_deporte, $codigo_postal, $pais, $id_equipo);
                            if ($stmtUpdate->execute()) {
                                $mensajeOk = "Equipo actualizado correctamente.";
                            } else {
                                $mensajeError = "No se pudo actualizar el equipo.";
                            }
                            $stmtUpdate->close();
                        } else {
                            $mensajeError = "Error al preparar la actualización.";
                        }
                    } else {
                        $mensajeError = "Ese equipo no pertenece a esta liga.";
                    }

                    $stmtValidarEquipo->close();
                } else {
                    $mensajeError = "No se pudo validar el equipo.";
                }
            }
        }
    }
}

if ($mensajeError === '' && $id_liga_admin !== '' && isset($_GET['editar']) && trim($_GET['editar']) !== '') {
    $idEditar = trim($_GET['editar']);

    $sqlEditar = "SELECT e.Id_Equipo, e.Nombre, e.cantidad, e.Id_Deporte, e.CodigoPostal, e.Pais
                  FROM liga_equipo le
                  INNER JOIN equipo e ON le.Id_Equipo = e.Id_Equipo
                  WHERE le.Id_Liga = ? AND e.Id_Equipo = ?
                  LIMIT 1";
    $stmtEditar = $conn->prepare($sqlEditar);

    if ($stmtEditar) {
        $stmtEditar->bind_param("ss", $id_liga_admin, $idEditar);
        $stmtEditar->execute();
        $resEditar = $stmtEditar->get_result();

        if ($resEditar && $resEditar->num_rows > 0) {
            $equipoEditar = $resEditar->fetch_assoc();
            $editando = true;
        }

        $stmtEditar->close();
    }
}

if ($mensajeError === '' && $id_liga_admin !== '') {
    $sqlEquipos = "SELECT 
        le.Id_LigaEquipo,
        le.Id_Equipo,
        le.FechaInscripcion,
        le.Estado,
        e.Nombre AS NombreEquipo,
        e.cantidad,
        e.Id_Deporte,
        d.Nombre AS Deporte,
        e.CodigoPostal,
        e.Pais,
        e.Capitan,
        r.Nombre AS NombreCapitan,
        r.Apellido AS ApellidoCapitan
    FROM liga_equipo le
    INNER JOIN equipo e ON le.Id_Equipo = e.Id_Equipo
    LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte
    LEFT JOIN retador r ON e.Capitan = r.Id_Retador
    WHERE le.Id_Liga = ?
    ORDER BY e.Nombre ASC";

    $stmtEquipos = $conn->prepare($sqlEquipos);

    if ($stmtEquipos) {
        $stmtEquipos->bind_param("s", $id_liga_admin);
        $stmtEquipos->execute();
        $resEquipos = $stmtEquipos->get_result();

        while ($equipo = $resEquipos->fetch_assoc()) {
            $equiposLiga[] = $equipo;
        }

        $stmtEquipos->close();
    } else {
        $mensajeError = "No se pudieron consultar los equipos de la liga.";
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
<title>Administrar Equipos de la Liga</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{min-height:100vh;background:linear-gradient(135deg,#140f27,#203a43,#1c2a92);color:#eee;overflow-x:hidden;}
.sidebar{width:250px;background:#111820;padding:20px;display:flex;flex-direction:column;align-items:center;box-shadow:5px 0 20px rgba(9,5,138,0.7);position:fixed;top:0;left:0;height:100vh;z-index:1000;overflow-y:auto;}
.sidebar h2{color:#fff;margin-bottom:20px;text-align:center;font-size:18px;}
.sidebar img{width:90px;height:90px;margin-bottom:10px;border-radius:50%;border:3px solid #00ffc6;object-fit:cover;}
.menu{list-style:none;width:100%;margin-top:20px;}
.menu li{padding:12px;margin:10px 0;border-radius:8px;background:rgba(0,255,198,0.1);text-align:center;transition:all 0.3s ease;border:1px solid rgba(0,255,198,0.2);}
.menu li a{color:#fff;text-decoration:none;font-weight:bold;display:block;font-size:14px;}
.menu li:hover{background:rgba(244,16,16,0.3);transform:translateX(5px);border-color:rgba(244,16,16,0.5);}
.main-content{margin-left:250px;padding:35px;min-height:100vh;}
.main-content h1{font-size:32px;color:#fff;margin-bottom:18px;text-align:center;}
.card{background:rgba(27,31,39,0.9);padding:26px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:1350px;margin:0 auto 25px auto;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}
.subtext{opacity:.92;font-size:14px;margin-top:8px;line-height:1.5;text-align:center;}
.top-grid{display:grid;grid-template-columns:1.3fr .7fr .7fr;gap:16px;margin-top:20px;}
.mini-card{background:rgba(255,255,255,0.06);padding:18px;border-radius:18px;border:1px solid rgba(255,255,255,0.12);box-shadow:0 0 18px rgba(0,255,198,0.08);}
.mini-card h3{font-size:15px;color:#00ffc6;margin-bottom:8px;}
.mini-card p{font-size:13px;opacity:.9;line-height:1.6;}
.badge{display:inline-block;padding:6px 12px;border-radius:999px;background:rgba(0,255,198,0.14);border:1px solid rgba(0,255,198,0.22);font-weight:900;font-size:12px;color:#fff;word-break:break-all;}
.alert{margin-top:18px;padding:14px 16px;border-radius:14px;font-weight:700;text-align:center;}
.alert.ok{background:rgba(0,255,198,0.12);border:1px solid rgba(0,255,198,0.25);color:#d8fff7;}
.alert.error{background:rgba(255,77,109,0.12);border:1px solid rgba(255,77,109,0.28);color:#ffe1e7;}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px;}
.field{display:flex;flex-direction:column;gap:8px;}
label{font-size:13px;font-weight:700;color:#fff;}
input[type="text"],input[type="number"],select{width:100%;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.23);color:#fff;outline:none;font-size:14px;transition:all .25s ease;}
input[type="text"]:focus,input[type="number"]:focus,select:focus{border-color:#00ffc6;box-shadow:0 0 0 3px rgba(0,255,198,0.12);}
select option{color:#111;}
.actions{display:flex;gap:12px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:22px;}
.btn{border:none;padding:12px 16px;border-radius:12px;cursor:pointer;font-weight:900;letter-spacing:.4px;font-size:14px;transition:transform .2s ease,opacity .2s ease,box-shadow .2s ease;text-decoration:none;display:inline-block;}
.btn:hover{opacity:.95;box-shadow:0 0 12px rgba(255,255,255,0.14);}
.btn:active{transform:scale(.98);}
.btn-primary{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#0b1220;box-shadow:0 0 18px rgba(0,255,198,0.25);}
.btn-secondary{background:rgba(255,255,255,0.10);color:#fff;border:1px solid rgba(255,255,255,0.18);}
.tools-bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-top:18px;margin-bottom:14px;}
.search-box{width:100%;max-width:360px;}
.table-wrap{margin-top:16px;overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,0.12);}
table{width:100%;border-collapse:collapse;min-width:1300px;background:rgba(0,0,0,0.22);}
th,td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,0.10);text-align:center;font-size:13px;white-space:nowrap;}
th{background:rgba(0,26,255,0.22);color:#00ffc6;font-weight:900;}
tr:hover td{background:rgba(255,255,255,0.05);}
.left{text-align:left;font-weight:700;}
.status-chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(255,255,255,0.15);background:rgba(0,255,198,0.14);color:#b9fff0;}
.empty{padding:18px;border-radius:16px;border:1px dashed rgba(255,255,255,0.25);background:rgba(0,0,0,0.22);max-width:780px;margin:18px auto 0 auto;text-align:center;}
.tiny-actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.tiny-actions a,.tiny-actions button{padding:8px 10px;border-radius:10px;font-size:12px;font-weight:900;text-decoration:none;border:none;cursor:pointer;}
.edit-link{background:rgba(0,255,198,0.12);color:#b9fff0;border:1px solid rgba(0,255,198,0.22);}
.delete-link{background:rgba(255,77,109,0.12);color:#ffd8e1;border:1px solid rgba(255,77,109,0.22);}
.view-link{background:rgba(0,123,255,0.14);color:#d6e9ff;border:1px solid rgba(0,123,255,0.24);}
.req{color:#00ffc6;font-weight:900;}
@media(max-width:992px){
    .top-grid,.form-grid{grid-template-columns:1fr;}
}
@media(max-width:768px){
    .sidebar{position:relative;width:100%;height:auto;box-shadow:none;}
    .main-content{margin-left:0;padding:20px;}
    .main-content h1{font-size:24px;}
    .card{padding:18px;}
    .actions .btn{width:100%;max-width:320px;text-align:center;}
    table{min-width:1100px;}
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Administrar Equipos de la Liga'); } ?>

<div class="sidebar">
    <img src="assets/doctor.png" alt="Logo">
    <h2>🏆 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio Liga</a></li>
        <li><a href="AdminEquipos.php?id_liga=<?php echo urlencode($id_liga_admin); ?>">👥 Equipos</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>👥 Equipos de la Liga</h1>

    <div class="card">
        <div class="subtext">Liga administrando: <b><?php echo h($nombre_liga ?: $id_liga_admin); ?></b></div>
        <div class="subtext">Usuario: <b><?php echo h($NombreUser); ?></b></div>

        <?php if ($mensajeError !== ''): ?>
            <div class="alert error"><?php echo h($mensajeError); ?></div>
        <?php endif; ?>

        <?php if ($mensajeOk !== ''): ?>
            <div class="alert ok"><?php echo h($mensajeOk); ?></div>
        <?php endif; ?>

        <?php if ($id_liga_admin !== ''): ?>
            <div class="top-grid">
                <div class="mini-card">
                    <h3>📋 Administración</h3>
                    <p>Aquí aparecen todos los equipos registrados dentro de la liga que administras. Puedes editar su información, eliminarlos de la liga o usar el botón de ver cuando esté disponible.</p>
                </div>
                <div class="mini-card">
                    <h3>🏷️ ID Liga</h3>
                    <p><span class="badge"><?php echo h($id_liga_admin); ?></span></p>
                </div>
                <div class="mini-card">
                    <h3>👥 Total Equipos</h3>
                    <p><span class="badge" id="contadorFilas"><?php echo count($equiposLiga); ?> registros</span></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($editando): ?>
        <div class="card">
            <div class="subtext">Editar equipo seleccionado</div>

            <form method="POST" action="AdminEquipos.php?id_liga=<?php echo urlencode($id_liga_admin); ?>">
                <input type="hidden" name="accion" value="actualizar">
                <input type="hidden" name="id_liga" value="<?php echo h($id_liga_admin); ?>">
                <input type="hidden" name="id_equipo" value="<?php echo h($equipoEditar['Id_Equipo']); ?>">

                <div class="form-grid">
                    <div class="field">
                        <label>ID Equipo</label>
                        <input type="text" value="<?php echo h($equipoEditar['Id_Equipo']); ?>" readonly>
                    </div>

                    <div class="field">
                        <label>Nombre del equipo <span class="req">*</span></label>
                        <input type="text" name="nombre" required maxlength="100" value="<?php echo h($equipoEditar['Nombre']); ?>">
                    </div>

                    <div class="field">
                        <label>Cantidad de jugadores <span class="req">*</span></label>
                        <input type="number" name="cantidad" min="1" required value="<?php echo h($equipoEditar['cantidad']); ?>">
                    </div>

                    <div class="field">
                        <label>Deporte <span class="req">*</span></label>
                        <select name="id_deporte" required>
                            <option value="">Selecciona un deporte</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?php echo h($dep['Id_Deporte']); ?>" <?php echo ($equipoEditar['Id_Deporte'] == $dep['Id_Deporte']) ? 'selected' : ''; ?>>
                                    <?php echo h($dep['Nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Código postal</label>
                        <input type="text" name="codigo_postal" maxlength="5" value="<?php echo h($equipoEditar['CodigoPostal']); ?>">
                    </div>

                    <div class="field">
                        <label>País</label>
                        <input type="text" name="pais" maxlength="100" value="<?php echo h($equipoEditar['Pais']); ?>">
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Actualizar equipo</button>
                    <a href="AdminEquipos.php?id_liga=<?php echo urlencode($id_liga_admin); ?>" class="btn btn-secondary">Cancelar edición</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="subtext">Listado de equipos registrados en la liga</div>

        <?php if (!empty($equiposLiga)): ?>
            <div class="tools-bar">
                <input type="text" id="busquedaTabla" class="search-box" placeholder="Buscar por nombre, ID, deporte, capitán, país, estado o código postal">
            </div>

            <div class="table-wrap">
                <table id="tablaEquipos">
                    <thead>
                        <tr>
                            <th>ID Equipo</th>
                            <th>Nombre</th>
                            <th>Deporte</th>
                            <th>Jugadores</th>
                            <th>Código Postal</th>
                            <th>País</th>
                            <th>Capitán</th>
                            <th>Fecha Inscripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equiposLiga as $equipo): ?>
                            <tr>
                                <td><b><?php echo h($equipo['Id_Equipo']); ?></b></td>
                                <td class="left"><?php echo h($equipo['NombreEquipo']); ?></td>
                                <td><?php echo h($equipo['Deporte']); ?></td>
                                <td><?php echo h($equipo['cantidad']); ?></td>
                                <td><?php echo h($equipo['CodigoPostal']); ?></td>
                                <td><?php echo h($equipo['Pais']); ?></td>
                                <td class="left">
                                    <?php
                                    $capitanNombre = trim(($equipo['NombreCapitan'] ?? '') . ' ' . ($equipo['ApellidoCapitan'] ?? ''));
                                    echo h($capitanNombre !== '' ? $capitanNombre : 'Sin capitán');
                                    ?>
                                </td>
                                <td>
                                    <?php echo !empty($equipo['FechaInscripcion']) ? h(date('d/m/Y', strtotime($equipo['FechaInscripcion']))) : ''; ?>
                                </td>
                                <td><span class="status-chip"><?php echo h($equipo['Estado']); ?></span></td>
                                <td>
                                    <div class="tiny-actions">
                                        <a class="edit-link" href="AdminEquipos.php?id_liga=<?php echo urlencode($id_liga_admin); ?>&editar=<?php echo urlencode($equipo['Id_Equipo']); ?>">Editar</a>

                                        <form method="POST" action="AdminEquipos.php?id_liga=<?php echo urlencode($id_liga_admin); ?>" style="display:inline;">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_liga" value="<?php echo h($id_liga_admin); ?>">
                                            <input type="hidden" name="id_equipo" value="<?php echo h($equipo['Id_Equipo']); ?>">
                                            <button type="submit" class="delete-link btnEliminar">Eliminar</button>
                                        </form>

                                        <button type="button" class="view-link btnVer">Ver</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">
                <h3>📭 No hay equipos registrados</h3>
                <p>No se encontraron equipos en la tabla <b>liga_equipo</b> para esta liga.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const busquedaTabla = document.getElementById('busquedaTabla');
const tablaEquipos = document.getElementById('tablaEquipos');
const contadorFilas = document.getElementById('contadorFilas');
const botonesEliminar = document.querySelectorAll('.btnEliminar');
const botonesVer = document.querySelectorAll('.btnVer');

if (busquedaTabla && tablaEquipos) {
    busquedaTabla.addEventListener('input', function() {
        const texto = this.value.toLowerCase().trim();
        const filas = tablaEquipos.querySelectorAll('tbody tr');
        let visibles = 0;

        filas.forEach(fila => {
            const contenido = fila.textContent.toLowerCase();
            const mostrar = contenido.includes(texto);
            fila.style.display = mostrar ? '' : 'none';
            if (mostrar) visibles++;
        });

        if (contadorFilas) {
            contadorFilas.textContent = visibles + ' registros';
        }
    });
}

botonesEliminar.forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('¿Seguro que deseas eliminar este equipo de la liga?')) {
            e.preventDefault();
        }
    });
});

botonesVer.forEach(btn => {
    btn.addEventListener('click', function() {
        alert('Esta función está en desarrollo.');
    });
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>