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

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalizarBaseId($nombre) {
    $txt = trim((string)$nombre);
    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($conv !== false) {
        $txt = $conv;
    }
    $txt = strtoupper($txt);
    $txt = preg_replace('/\s+/', '', $txt);
    $txt = preg_replace('/[^A-Z0-9]/', '', $txt);
    return $txt !== '' ? $txt : 'CANCHA';
}

function existeIdCancha($conn, $id) {
    $sql = "SELECT Id_cancha FROM Canchas WHERE Id_cancha = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existe = $res && $res->num_rows > 0;
    $stmt->close();
    return $existe;
}

function generarIdCancha($conn, $nombre) {
    $base = normalizarBaseId($nombre);
    $longitud = 4;

    while (true) {
        for ($i = 0; $i < 400; $i++) {
            $random = '';
            for ($j = 0; $j < $longitud; $j++) {
                $random .= (string) random_int(0, 9);
            }
            $id = $base . $random;
            if (!existeIdCancha($conn, $id)) {
                return $id;
            }
        }
        $longitud++;
    }
}

$mensaje = '';
$tipoMensaje = '';
$editando = false;
$canchaEditar = [
    'Id_cancha' => '',
    'nombre' => '',
    'direccion' => '',
    'codigo_postal' => '',
    'descripcion' => '',
    'deporte1' => '',
    'deporte2' => '',
    'deporte3' => '',
    'deporte4' => '',
    'tipo' => '',
    'condicion' => '',
    'estado' => '',
    'horario' => '',
    'comentarios' => ''
];

$tiposPermitidos = ['Pasto natural', 'Concreto', 'Pasto artificial', 'Arena'];
$condicionesPermitidas = ['1 estrella', '2 estrellas', '3 estrellas', '4 estrellas', '5 estrellas'];
$estadosPermitidos = ['Clausurado', 'En Remodelacion', 'Abierto', 'Cerrado'];

$deportes = [];
$sqlDeportes = "SELECT Nombre FROM deporte ORDER BY Nombre ASC";
$resDeportes = $conn->query($sqlDeportes);
if ($resDeportes) {
    while ($rowDep = $resDeportes->fetch_assoc()) {
        $deportes[] = $rowDep['Nombre'];
    }
}

if (isset($_GET['eliminar']) && $_GET['eliminar'] !== '') {
    $idEliminar = trim($_GET['eliminar']);
    $stmtDel = $conn->prepare("DELETE FROM Canchas WHERE Id_cancha = ?");
    if ($stmtDel) {
        $stmtDel->bind_param("s", $idEliminar);
        if ($stmtDel->execute()) {
            $mensaje = 'Cancha eliminada correctamente.';
            $tipoMensaje = 'ok';
        } else {
            $mensaje = 'No se pudo eliminar la cancha.';
            $tipoMensaje = 'error';
        }
        $stmtDel->close();
    } else {
        $mensaje = 'Error al preparar la eliminación.';
        $tipoMensaje = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = isset($_POST['accion']) ? trim($_POST['accion']) : '';
    $idOriginal = isset($_POST['id_original']) ? trim($_POST['id_original']) : '';

    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
    $codigo_postal = isset($_POST['codigo_postal']) ? trim($_POST['codigo_postal']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    $tipo = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
    $condicion = isset($_POST['condicion']) ? trim($_POST['condicion']) : '';
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $hora_inicio = isset($_POST['hora_inicio']) ? trim($_POST['hora_inicio']) : '';
    $hora_fin = isset($_POST['hora_fin']) ? trim($_POST['hora_fin']) : '';
    $comentarios = isset($_POST['comentarios']) ? trim($_POST['comentarios']) : '';

    $dep1 = isset($_POST['deporte1']) ? trim($_POST['deporte1']) : '';
    $dep2 = isset($_POST['deporte2']) ? trim($_POST['deporte2']) : '';
    $dep3 = isset($_POST['deporte3']) ? trim($_POST['deporte3']) : '';
    $dep4 = isset($_POST['deporte4']) ? trim($_POST['deporte4']) : '';

    $deportesCapturados = [$dep1, $dep2, $dep3, $dep4];
    $deportesUnicos = [];

    foreach ($deportesCapturados as $dep) {
        if ($dep !== '' && !in_array($dep, $deportesUnicos, true)) {
            $deportesUnicos[] = $dep;
        }
    }

    while (count($deportesUnicos) < 4) {
        $deportesUnicos[] = '';
    }

    $dep1 = $deportesUnicos[0];
    $dep2 = $deportesUnicos[1];
    $dep3 = $deportesUnicos[2];
    $dep4 = $deportesUnicos[3];

    if (
        $nombre === '' ||
        $direccion === '' ||
        $codigo_postal === '' ||
        $dep1 === '' ||
        $tipo === '' ||
        $condicion === '' ||
        $estado === '' ||
        $hora_inicio === '' ||
        $hora_fin === ''
    ) {
        $mensaje = 'Completa los campos obligatorios.';
        $tipoMensaje = 'error';
    } elseif (!preg_match('/^\d{5}$/', $codigo_postal)) {
        $mensaje = 'El código postal debe tener 5 dígitos.';
        $tipoMensaje = 'error';
    } elseif (!in_array($tipo, $tiposPermitidos, true)) {
        $mensaje = 'Tipo de cancha no válido.';
        $tipoMensaje = 'error';
    } elseif (!in_array($condicion, $condicionesPermitidas, true)) {
        $mensaje = 'Condición no válida.';
        $tipoMensaje = 'error';
    } elseif (!in_array($estado, $estadosPermitidos, true)) {
        $mensaje = 'Estado no válido.';
        $tipoMensaje = 'error';
    } elseif ($hora_inicio >= $hora_fin) {
        $mensaje = 'La hora de inicio debe ser menor que la hora final.';
        $tipoMensaje = 'error';
    } else {
        $horario = $hora_inicio . ' - ' . $hora_fin;

        if ($accion === 'guardar') {
            $nuevoId = generarIdCancha($conn, $nombre);

            $sqlInsert = "INSERT INTO Canchas
            (Id_cancha, nombre, direccion, codigo_postal, descripcion, deporte1, deporte2, deporte3, deporte4, tipo, condicion, estado, horario, comentarios)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmtIns = $conn->prepare($sqlInsert);

            if ($stmtIns) {
                $stmtIns->bind_param(
                    "ssssssssssssss",
                    $nuevoId,
                    $nombre,
                    $direccion,
                    $codigo_postal,
                    $descripcion,
                    $dep1,
                    $dep2,
                    $dep3,
                    $dep4,
                    $tipo,
                    $condicion,
                    $estado,
                    $horario,
                    $comentarios
                );

                if ($stmtIns->execute()) {
                    $mensaje = 'Cancha registrada correctamente con ID: ' . $nuevoId;
                    $tipoMensaje = 'ok';
                    $canchaEditar = [
                        'Id_cancha' => '',
                        'nombre' => '',
                        'direccion' => '',
                        'codigo_postal' => '',
                        'descripcion' => '',
                        'deporte1' => '',
                        'deporte2' => '',
                        'deporte3' => '',
                        'deporte4' => '',
                        'tipo' => '',
                        'condicion' => '',
                        'estado' => '',
                        'horario' => '',
                        'comentarios' => ''
                    ];
                } else {
                    $mensaje = 'No se pudo registrar la cancha.';
                    $tipoMensaje = 'error';
                }
                $stmtIns->close();
            } else {
                $mensaje = 'Error al preparar el registro.';
                $tipoMensaje = 'error';
            }
        }

        if ($accion === 'actualizar' && $idOriginal !== '') {
            $sqlUpd = "UPDATE Canchas SET
                nombre = ?,
                direccion = ?,
                codigo_postal = ?,
                descripcion = ?,
                deporte1 = ?,
                deporte2 = ?,
                deporte3 = ?,
                deporte4 = ?,
                tipo = ?,
                condicion = ?,
                estado = ?,
                horario = ?,
                comentarios = ?
                WHERE Id_cancha = ?";

            $stmtUpd = $conn->prepare($sqlUpd);

            if ($stmtUpd) {
                $stmtUpd->bind_param(
                    "ssssssssssssss",
                    $nombre,
                    $direccion,
                    $codigo_postal,
                    $descripcion,
                    $dep1,
                    $dep2,
                    $dep3,
                    $dep4,
                    $tipo,
                    $condicion,
                    $estado,
                    $horario,
                    $comentarios,
                    $idOriginal
                );

                if ($stmtUpd->execute()) {
                    $mensaje = 'Cancha actualizada correctamente.';
                    $tipoMensaje = 'ok';
                } else {
                    $mensaje = 'No se pudo actualizar la cancha.';
                    $tipoMensaje = 'error';
                }
                $stmtUpd->close();
            } else {
                $mensaje = 'Error al preparar la actualización.';
                $tipoMensaje = 'error';
            }
        }
    }
}

if (isset($_GET['editar']) && $_GET['editar'] !== '') {
    $idEditar = trim($_GET['editar']);
    $stmtEd = $conn->prepare("SELECT * FROM Canchas WHERE Id_cancha = ? LIMIT 1");
    if ($stmtEd) {
        $stmtEd->bind_param("s", $idEditar);
        $stmtEd->execute();
        $resEd = $stmtEd->get_result();
        if ($resEd && $resEd->num_rows > 0) {
            $canchaEditar = $resEd->fetch_assoc();
            $editando = true;
        }
        $stmtEd->close();
    }
}

$canchas = [];
$sqlCanchas = "SELECT * FROM Canchas ORDER BY nombre ASC";
$resCanchas = $conn->query($sqlCanchas);
if ($resCanchas) {
    while ($rowC = $resCanchas->fetch_assoc()) {
        $canchas[] = $rowC;
    }
}

$horaInicioEdit = '';
$horaFinEdit = '';

if (!empty($canchaEditar['horario']) && strpos($canchaEditar['horario'], ' - ') !== false) {
    $partesHorario = explode(' - ', $canchaEditar['horario']);
    $horaInicioEdit = isset($partesHorario[0]) ? trim($partesHorario[0]) : '';
    $horaFinEdit = isset($partesHorario[1]) ? trim($partesHorario[1]) : '';
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
<title>Administrar Canchas - RETAME</title>
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
.card{background:rgba(27,31,39,0.9);padding:26px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:1300px;margin:0 auto 25px auto;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}
.subtext{opacity:.92;font-size:14px;margin-top:8px;line-height:1.5;text-align:center;}
.badge{display:inline-block;padding:6px 12px;border-radius:999px;background:rgba(0,255,198,0.14);border:1px solid rgba(0,255,198,0.22);font-weight:900;font-size:12px;color:#fff;}
.top-grid{display:grid;grid-template-columns:1.4fr .8fr .8fr;gap:16px;margin-top:20px;}
.mini-card{background:rgba(255,255,255,0.06);padding:18px;border-radius:18px;border:1px solid rgba(255,255,255,0.12);box-shadow:0 0 18px rgba(0,255,198,0.08);}
.mini-card h3{font-size:15px;color:#00ffc6;margin-bottom:8px;}
.mini-card p{font-size:13px;opacity:.9;line-height:1.6;}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px;}
.field{display:flex;flex-direction:column;gap:8px;}
.field.full{grid-column:1/-1;}
label{font-size:13px;font-weight:700;color:#fff;}
input[type="text"],input[type="time"],textarea,select{width:100%;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.23);color:#fff;outline:none;font-size:14px;transition:all .25s ease;}
input[type="text"]:focus,input[type="time"]:focus,textarea:focus,select:focus{border-color:#00ffc6;box-shadow:0 0 0 3px rgba(0,255,198,0.12);}
textarea{resize:vertical;min-height:100px;}
select option{color:#111;}
.hint{font-size:12px;opacity:.78;}
.id-preview{padding:12px 14px;border-radius:14px;background:rgba(0,255,198,0.09);border:1px dashed rgba(0,255,198,0.35);font-weight:900;color:#00ffc6;letter-spacing:.8px;min-height:48px;display:flex;align-items:center;}
.sports-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.choice-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;}
.choice-grid input{display:none;}
.choice-grid label{display:flex;align-items:center;justify-content:center;text-align:center;min-height:52px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);cursor:pointer;transition:all .25s ease;font-size:13px;font-weight:700;}
.choice-grid input:checked + label{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#09111f;border-color:transparent;box-shadow:0 0 18px rgba(0,255,198,0.22);}
.estrellas{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;}
.estrellas input{display:none;}
.estrellas label{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:12px 8px;border-radius:16px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);cursor:pointer;transition:all .25s ease;font-size:12px;font-weight:700;min-height:76px;}
.estrellas label span{font-size:18px;}
.estrellas input:checked + label{background:linear-gradient(90deg,#ffd700,#ff8c00);color:#141414;border-color:transparent;box-shadow:0 0 18px rgba(255,200,0,0.25);}
.actions{display:flex;gap:12px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:22px;}
.btn{border:none;padding:12px 16px;border-radius:12px;cursor:pointer;font-weight:900;letter-spacing:.4px;font-size:14px;transition:transform .2s ease,opacity .2s ease,box-shadow .2s ease;text-decoration:none;display:inline-block;}
.btn:hover{opacity:.95;box-shadow:0 0 12px rgba(255,255,255,0.14);}
.btn:active{transform:scale(.98);}
.btn-primary{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#0b1220;box-shadow:0 0 18px rgba(0,255,198,0.25);}
.btn-secondary{background:rgba(255,255,255,0.10);color:#fff;border:1px solid rgba(255,255,255,0.18);}
.btn-danger{background:linear-gradient(90deg,#ff4d6d,#c9184a);color:#fff;}
.alert{margin-top:18px;padding:14px 16px;border-radius:14px;font-weight:700;text-align:center;}
.alert.ok{background:rgba(0,255,198,0.12);border:1px solid rgba(0,255,198,0.25);color:#d8fff7;}
.alert.error{background:rgba(255,77,109,0.12);border:1px solid rgba(255,77,109,0.28);color:#ffe1e7;}
.table-wrap{margin-top:16px;overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,0.12);}
table{width:100%;border-collapse:collapse;min-width:1200px;background:rgba(0,0,0,0.22);}
th,td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,0.10);text-align:center;font-size:13px;white-space:nowrap;}
th{background:rgba(0,26,255,0.22);color:#00ffc6;font-weight:900;}
tr:hover td{background:rgba(255,255,255,0.05);}
.left{text-align:left;font-weight:700;}
.empty{padding:18px;border-radius:16px;border:1px dashed rgba(255,255,255,0.25);background:rgba(0,0,0,0.22);max-width:780px;margin:18px auto 0 auto;text-align:center;}
.status-chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(255,255,255,0.15);}
.estado-abierto{background:rgba(0,255,198,0.14);color:#b9fff0;}
.estado-cerrado{background:rgba(255,193,7,0.16);color:#ffeaa7;}
.estado-remodelacion{background:rgba(0,123,255,0.16);color:#cfe6ff;}
.estado-clausurado{background:rgba(255,77,109,0.16);color:#ffd8e1;}
.tiny-actions{display:flex;gap:8px;justify-content:center;}
.tiny-actions a{padding:8px 10px;border-radius:10px;font-size:12px;font-weight:900;text-decoration:none;}
.edit-link{background:rgba(0,255,198,0.12);color:#b9fff0;border:1px solid rgba(0,255,198,0.22);}
.delete-link{background:rgba(255,77,109,0.12);color:#ffd8e1;border:1px solid rgba(255,77,109,0.22);}
.req{color:#00ffc6;font-weight:900;}
@media(max-width:992px){
    .top-grid,.form-grid,.sports-grid{grid-template-columns:1fr;}
    .estrellas{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:768px){
    .sidebar{position:relative;width:100%;height:auto;box-shadow:none;}
    .main-content{margin-left:0;padding:20px;}
    .main-content h1{font-size:24px;}
    .card{padding:18px;}
    .choice-grid{grid-template-columns:1fr 1fr;}
    .estrellas{grid-template-columns:1fr;}
    .actions .btn{width:100%;max-width:320px;text-align:center;}
    table{min-width:1050px;}
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Administrar Canchas - RETAME'); } ?>

<div class="sidebar">
    <img src="assets/doctor.png" alt="Logo">
    <h2>🏟️ RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="AdminCanchas.php">🏟️ Administrar Canchas</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>🏟️ Administración de Canchas</h1>

    <div class="card">
        <div class="subtext">Usuario: <b><?php echo $NombreUser; ?></b></div>
        <div class="subtext">Aquí puedes registrar, editar y eliminar canchas con un diseño más visual e interactivo.</div>

        <div class="top-grid">
            <div class="mini-card">
                <h3>🧠 ID automático</h3>
                <p>El sistema genera el <b>Id_cancha</b> con el nombre en mayúsculas, sin espacios, y números aleatorios. Si ya existe, prueba otra combinación y, si hace falta, agrega más dígitos.</p>
            </div>
            <div class="mini-card">
                <h3>⚽ Deportes</h3>
                <p>El primer deporte es obligatorio. Los demás son opcionales. No se repiten para mantener todo más limpio.</p>
            </div>
            <div class="mini-card">
                <h3>📋 Registros</h3>
                <p>Total de canchas registradas: <span class="badge"><?php echo count($canchas); ?></span></p>
            </div>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert <?php echo h($tipoMensaje); ?>"><?php echo h($mensaje); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
            <input type="hidden" name="id_original" value="<?php echo h($canchaEditar['Id_cancha']); ?>">

            <div class="form-grid">
                <div class="field">
                    <label>Nombre de la cancha <span class="req">*</span></label>
                    <input type="text" name="nombre" id="nombre" maxlength="100" required value="<?php echo h($canchaEditar['nombre']); ?>" placeholder="Ejemplo: Cancha San Miguel">
                    <div class="hint">Se usará para generar el ID automático.</div>
                </div>

                <div class="field">
                    <label>Vista previa del ID</label>
                    <div class="id-preview" id="idPreview"><?php echo $editando ? h($canchaEditar['Id_cancha']) : 'SEGENERARAAUTOMATICAMENTE1234'; ?></div>
                </div>

                <div class="field">
                    <label>Dirección <span class="req">*</span></label>
                    <input type="text" name="direccion" maxlength="200" required value="<?php echo h($canchaEditar['direccion']); ?>" placeholder="Calle, barrio, colonia o referencia">
                </div>

                <div class="field">
                    <label>Código postal <span class="req">*</span></label>
                    <input type="text" name="codigo_postal" maxlength="5" pattern="[0-9]{5}" required value="<?php echo h($canchaEditar['codigo_postal']); ?>" placeholder="Ejemplo: 30000">
                </div>

                <div class="field full">
                    <label>Descripción</label>
                    <textarea name="descripcion" maxlength="500" placeholder="Describe la cancha, dimensiones, ubicación, acceso o detalles importantes"><?php echo h($canchaEditar['descripcion']); ?></textarea>
                </div>

                <div class="field full">
                    <label>Deportes <span class="req">*</span></label>
                    <div class="sports-grid">
                        <div class="field">
                            <label>Deporte 1 <span class="req">*</span></label>
                            <select name="deporte1" class="deporte-select" required>
                                <option value="">Selecciona un deporte</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($canchaEditar['deporte1'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 2</label>
                            <select name="deporte2" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($canchaEditar['deporte2'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 3</label>
                            <select name="deporte3" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($canchaEditar['deporte3'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 4</label>
                            <select name="deporte4" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($canchaEditar['deporte4'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field full">
                    <label>Tipo de cancha <span class="req">*</span></label>
                    <div class="choice-grid">
                        <input type="radio" name="tipo" id="tipo1" value="Pasto natural" <?php echo ($canchaEditar['tipo'] === 'Pasto natural') ? 'checked' : ''; ?> required>
                        <label for="tipo1">🌿 Pasto natural</label>

                        <input type="radio" name="tipo" id="tipo2" value="Concreto" <?php echo ($canchaEditar['tipo'] === 'Concreto') ? 'checked' : ''; ?>>
                        <label for="tipo2">🧱 Concreto</label>

                        <input type="radio" name="tipo" id="tipo3" value="Pasto artificial" <?php echo ($canchaEditar['tipo'] === 'Pasto artificial') ? 'checked' : ''; ?>>
                        <label for="tipo3">🟩 Pasto artificial</label>

                        <input type="radio" name="tipo" id="tipo4" value="Arena" <?php echo ($canchaEditar['tipo'] === 'Arena') ? 'checked' : ''; ?>>
                        <label for="tipo4">🏖️ Arena</label>
                    </div>
                </div>

                <div class="field full">
                    <label>Condición <span class="req">*</span></label>
                    <div class="estrellas">
                        <input type="radio" name="condicion" id="c1" value="1 estrella" <?php echo ($canchaEditar['condicion'] === '1 estrella') ? 'checked' : ''; ?> required>
                        <label for="c1"><span>⭐</span>1 estrella</label>

                        <input type="radio" name="condicion" id="c2" value="2 estrellas" <?php echo ($canchaEditar['condicion'] === '2 estrellas') ? 'checked' : ''; ?>>
                        <label for="c2"><span>⭐⭐</span>2 estrellas</label>

                        <input type="radio" name="condicion" id="c3" value="3 estrellas" <?php echo ($canchaEditar['condicion'] === '3 estrellas') ? 'checked' : ''; ?>>
                        <label for="c3"><span>⭐⭐⭐</span>3 estrellas</label>

                        <input type="radio" name="condicion" id="c4" value="4 estrellas" <?php echo ($canchaEditar['condicion'] === '4 estrellas') ? 'checked' : ''; ?>>
                        <label for="c4"><span>⭐⭐⭐⭐</span>4 estrellas</label>

                        <input type="radio" name="condicion" id="c5" value="5 estrellas" <?php echo ($canchaEditar['condicion'] === '5 estrellas') ? 'checked' : ''; ?>>
                        <label for="c5"><span>⭐⭐⭐⭐⭐</span>5 estrellas</label>
                    </div>
                </div>

                <div class="field full">
                    <label>Estado <span class="req">*</span></label>
                    <div class="choice-grid">
                        <input type="radio" name="estado" id="e1" value="Clausurado" <?php echo ($canchaEditar['estado'] === 'Clausurado') ? 'checked' : ''; ?> required>
                        <label for="e1">⛔ Clausurado</label>

                        <input type="radio" name="estado" id="e2" value="En Remodelacion" <?php echo ($canchaEditar['estado'] === 'En Remodelacion') ? 'checked' : ''; ?>>
                        <label for="e2">🛠️ En Remodelación</label>

                        <input type="radio" name="estado" id="e3" value="Abierto" <?php echo ($canchaEditar['estado'] === 'Abierto') ? 'checked' : ''; ?>>
                        <label for="e3">✅ Abierto</label>

                        <input type="radio" name="estado" id="e4" value="Cerrado" <?php echo ($canchaEditar['estado'] === 'Cerrado') ? 'checked' : ''; ?>>
                        <label for="e4">🔒 Cerrado</label>
                    </div>
                </div>

                <div class="field">
                    <label>Hora de apertura <span class="req">*</span></label>
                    <input type="time" name="hora_inicio" required value="<?php echo h($horaInicioEdit); ?>">
                </div>

                <div class="field">
                    <label>Hora de cierre <span class="req">*</span></label>
                    <input type="time" name="hora_fin" required value="<?php echo h($horaFinEdit); ?>">
                </div>

                <div class="field full">
                    <label>Comentarios</label>
                    <textarea name="comentarios" maxlength="500" placeholder="Observaciones, reglas, mantenimiento o notas adicionales"><?php echo h($canchaEditar['comentarios']); ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary"><?php echo $editando ? 'Actualizar cancha' : 'Guardar cancha'; ?></button>
                <a href="AdminCanchas.php" class="btn btn-secondary">Limpiar formulario</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="subtext">Listado de canchas registradas</div>

        <?php if (empty($canchas)): ?>
            <div class="empty">No hay canchas registradas todavía.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                            <th>CP</th>
                            <th>Deportes</th>
                            <th>Tipo</th>
                            <th>Condición</th>
                            <th>Estado</th>
                            <th>Horario</th>
                            <th>Comentarios</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($canchas as $c): ?>
                            <?php
                                $estadoClase = '';
                                if ($c['estado'] === 'Abierto') $estadoClase = 'estado-abierto';
                                if ($c['estado'] === 'Cerrado') $estadoClase = 'estado-cerrado';
                                if ($c['estado'] === 'En Remodelacion') $estadoClase = 'estado-remodelacion';
                                if ($c['estado'] === 'Clausurado') $estadoClase = 'estado-clausurado';

                                $deps = [];
                                if (!empty($c['deporte1'])) $deps[] = $c['deporte1'];
                                if (!empty($c['deporte2'])) $deps[] = $c['deporte2'];
                                if (!empty($c['deporte3'])) $deps[] = $c['deporte3'];
                                if (!empty($c['deporte4'])) $deps[] = $c['deporte4'];
                            ?>
                            <tr>
                                <td><b><?php echo h($c['Id_cancha']); ?></b></td>
                                <td class="left"><?php echo h($c['nombre']); ?></td>
                                <td class="left"><?php echo h($c['direccion']); ?></td>
                                <td><?php echo h($c['codigo_postal']); ?></td>
                                <td class="left"><?php echo h(implode(', ', $deps)); ?></td>
                                <td><?php echo h($c['tipo']); ?></td>
                                <td><?php echo h($c['condicion']); ?></td>
                                <td><span class="status-chip <?php echo h($estadoClase); ?>"><?php echo h($c['estado']); ?></span></td>
                                <td><?php echo h($c['horario']); ?></td>
                                <td class="left"><?php echo h($c['comentarios']); ?></td>
                                <td>
                                    <div class="tiny-actions">
                                        <a class="edit-link" href="AdminCanchas.php?editar=<?php echo urlencode($c['Id_cancha']); ?>">Editar</a>
                                        <a class="delete-link deleteBtn" href="AdminCanchas.php?eliminar=<?php echo urlencode($c['Id_cancha']); ?>">Eliminar</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const nombreInput = document.getElementById('nombre');
const idPreview = document.getElementById('idPreview');
const deporteSelects = document.querySelectorAll('.deporte-select');
const deleteBtns = document.querySelectorAll('.deleteBtn');

function normalizarNombre(texto) {
    return texto
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '')
        .replace(/[^A-Za-z0-9]/g, '')
        .toUpperCase();
}

function randomDigits(len) {
    let out = '';
    for (let i = 0; i < len; i++) {
        out += Math.floor(Math.random() * 10);
    }
    return out;
}

function actualizarVistaId() {
    const base = normalizarNombre(nombreInput.value || 'CANCHA');
    idPreview.textContent = base + randomDigits(4);
}

if (nombreInput) {
    nombreInput.addEventListener('input', actualizarVistaId);
    actualizarVistaId();
}

deporteSelects.forEach(select => {
    select.addEventListener('change', function() {
        const usados = [];
        deporteSelects.forEach(s => {
            const val = s.value.trim();
            if (val !== '') {
                if (usados.includes(val)) {
                    alert('Ese deporte ya fue seleccionado. Elige otro diferente.');
                    s.value = '';
                } else {
                    usados.push(val);
                }
            }
        });
    });
});

deleteBtns.forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('¿Seguro que deseas eliminar esta cancha?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>