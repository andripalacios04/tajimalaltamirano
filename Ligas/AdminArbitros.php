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

function normalizarBaseIdArbitro($nombre, $apellidos) {
    $txt = trim($nombre . ' ' . $apellidos);
    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($conv !== false) {
        $txt = $conv;
    }
    $txt = strtoupper($txt);
    $txt = preg_replace('/\s+/', '', $txt);
    $txt = preg_replace('/[^A-Z0-9]/', '', $txt);
    return $txt !== '' ? $txt : 'ARBITRO';
}

function existeIdArbitro($conn, $id) {
    $sql = "SELECT id_arbitro FROM arbitros WHERE id_arbitro = ? LIMIT 1";
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

function generarIdArbitro($conn, $nombre, $apellidos) {
    $base = normalizarBaseIdArbitro($nombre, $apellidos);
    $longitud = 4;

    while (true) {
        for ($i = 0; $i < 400; $i++) {
            $random = '';
            for ($j = 0; $j < $longitud; $j++) {
                $random .= (string) random_int(0, 9);
            }
            $id = $base . $random;
            if (!existeIdArbitro($conn, $id)) {
                return $id;
            }
        }
        $longitud++;
    }
}

$mensaje = '';
$tipoMensaje = '';
$editando = false;

$arbitroEditar = [
    'id_arbitro' => '',
    'nombre' => '',
    'apellidos' => '',
    'codigo_postal' => '',
    'tipo' => '',
    'deporte1' => '',
    'deporte2' => '',
    'deporte3' => '',
    'deporte4' => '',
    'deporte5' => '',
    'estado' => '',
    'descripcion' => '',
    'califiacion' => ''
];

$estadosPermitidos = ['Disponible', 'Ocupado', 'Suspendido', 'Inactivo'];
$calificacionesPermitidas = ['1 estrella', '2 estrellas', '3 estrellas', '4 estrellas', '5 estrellas'];

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
    $stmtDel = $conn->prepare("DELETE FROM arbitros WHERE id_arbitro = ?");
    if ($stmtDel) {
        $stmtDel->bind_param("s", $idEliminar);
        if ($stmtDel->execute()) {
            $mensaje = 'Árbitro eliminado correctamente.';
            $tipoMensaje = 'ok';
        } else {
            $mensaje = 'No se pudo eliminar el árbitro.';
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
    $apellidos = isset($_POST['apellidos']) ? trim($_POST['apellidos']) : '';
    $codigo_postal = isset($_POST['codigo_postal']) ? trim($_POST['codigo_postal']) : '';
    $tipo = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    $califiacion = isset($_POST['califiacion']) ? trim($_POST['califiacion']) : '';

    $dep1 = isset($_POST['deporte1']) ? trim($_POST['deporte1']) : '';
    $dep2 = isset($_POST['deporte2']) ? trim($_POST['deporte2']) : '';
    $dep3 = isset($_POST['deporte3']) ? trim($_POST['deporte3']) : '';
    $dep4 = isset($_POST['deporte4']) ? trim($_POST['deporte4']) : '';
    $dep5 = isset($_POST['deporte5']) ? trim($_POST['deporte5']) : '';

    $deportesCapturados = [$dep1, $dep2, $dep3, $dep4, $dep5];
    $deportesUnicos = [];

    foreach ($deportesCapturados as $dep) {
        if ($dep !== '' && !in_array($dep, $deportesUnicos, true)) {
            $deportesUnicos[] = $dep;
        }
    }

    while (count($deportesUnicos) < 5) {
        $deportesUnicos[] = '';
    }

    $dep1 = $deportesUnicos[0];
    $dep2 = $deportesUnicos[1];
    $dep3 = $deportesUnicos[2];
    $dep4 = $deportesUnicos[3];
    $dep5 = $deportesUnicos[4];

    if (
        $nombre === '' ||
        $apellidos === '' ||
        $codigo_postal === '' ||
        $tipo === '' ||
        $dep1 === '' ||
        $estado === '' ||
        $califiacion === ''
    ) {
        $mensaje = 'Completa los campos obligatorios.';
        $tipoMensaje = 'error';
    } elseif (!preg_match('/^\d{5}$/', $codigo_postal)) {
        $mensaje = 'El código postal debe tener 5 dígitos.';
        $tipoMensaje = 'error';
    } elseif (!in_array($estado, $estadosPermitidos, true)) {
        $mensaje = 'Estado no válido.';
        $tipoMensaje = 'error';
    } elseif (!in_array($califiacion, $calificacionesPermitidas, true)) {
        $mensaje = 'Calificación no válida.';
        $tipoMensaje = 'error';
    } else {
        if ($accion === 'guardar') {
            $nuevoId = generarIdArbitro($conn, $nombre, $apellidos);

            $sqlInsert = "INSERT INTO arbitros
            (id_arbitro, nombre, apellidos, codigo_postal, tipo, deporte1, deporte2, deporte3, deporte4, deporte5, estado, descripcion, califiacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmtIns = $conn->prepare($sqlInsert);

            if ($stmtIns) {
                $stmtIns->bind_param(
                    "sssssssssssss",
                    $nuevoId,
                    $nombre,
                    $apellidos,
                    $codigo_postal,
                    $tipo,
                    $dep1,
                    $dep2,
                    $dep3,
                    $dep4,
                    $dep5,
                    $estado,
                    $descripcion,
                    $califiacion
                );

                if ($stmtIns->execute()) {
                    $mensaje = 'Árbitro registrado correctamente con ID: ' . $nuevoId;
                    $tipoMensaje = 'ok';
                    $arbitroEditar = [
                        'id_arbitro' => '',
                        'nombre' => '',
                        'apellidos' => '',
                        'codigo_postal' => '',
                        'tipo' => '',
                        'deporte1' => '',
                        'deporte2' => '',
                        'deporte3' => '',
                        'deporte4' => '',
                        'deporte5' => '',
                        'estado' => '',
                        'descripcion' => '',
                        'califiacion' => ''
                    ];
                } else {
                    $mensaje = 'No se pudo registrar el árbitro.';
                    $tipoMensaje = 'error';
                }
                $stmtIns->close();
            } else {
                $mensaje = 'Error al preparar el registro.';
                $tipoMensaje = 'error';
            }
        }

        if ($accion === 'actualizar' && $idOriginal !== '') {
            $sqlUpd = "UPDATE arbitros SET
                nombre = ?,
                apellidos = ?,
                codigo_postal = ?,
                tipo = ?,
                deporte1 = ?,
                deporte2 = ?,
                deporte3 = ?,
                deporte4 = ?,
                deporte5 = ?,
                estado = ?,
                descripcion = ?,
                califiacion = ?
                WHERE id_arbitro = ?";

            $stmtUpd = $conn->prepare($sqlUpd);

            if ($stmtUpd) {
                $stmtUpd->bind_param(
                    "sssssssssssss",
                    $nombre,
                    $apellidos,
                    $codigo_postal,
                    $tipo,
                    $dep1,
                    $dep2,
                    $dep3,
                    $dep4,
                    $dep5,
                    $estado,
                    $descripcion,
                    $califiacion,
                    $idOriginal
                );

                if ($stmtUpd->execute()) {
                    $mensaje = 'Árbitro actualizado correctamente.';
                    $tipoMensaje = 'ok';
                } else {
                    $mensaje = 'No se pudo actualizar el árbitro.';
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
    $stmtEd = $conn->prepare("SELECT * FROM arbitros WHERE id_arbitro = ? LIMIT 1");
    if ($stmtEd) {
        $stmtEd->bind_param("s", $idEditar);
        $stmtEd->execute();
        $resEd = $stmtEd->get_result();
        if ($resEd && $resEd->num_rows > 0) {
            $arbitroEditar = $resEd->fetch_assoc();
            $editando = true;
        }
        $stmtEd->close();
    }
}

$arbitros = [];
$sqlArbitros = "SELECT * FROM arbitros ORDER BY nombre ASC, apellidos ASC";
$resArbitros = $conn->query($sqlArbitros);
if ($resArbitros) {
    while ($rowA = $resArbitros->fetch_assoc()) {
        $arbitros[] = $rowA;
    }
}

$totalArbitros = count($arbitros);
$totalDisponibles = 0;
$totalOcupados = 0;
$totalSuspendidos = 0;

foreach ($arbitros as $a) {
    if ($a['estado'] === 'Disponible') $totalDisponibles++;
    if ($a['estado'] === 'Ocupado') $totalOcupados++;
    if ($a['estado'] === 'Suspendido') $totalSuspendidos++;
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
<title>Administrar Árbitros - RETAME</title>
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
.card{background:rgba(27,31,39,0.9);padding:26px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:1320px;margin:0 auto 25px auto;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}
.subtext{opacity:.92;font-size:14px;margin-top:8px;line-height:1.5;text-align:center;}
.badge{display:inline-block;padding:6px 12px;border-radius:999px;background:rgba(0,255,198,0.14);border:1px solid rgba(0,255,198,0.22);font-weight:900;font-size:12px;color:#fff;}
.top-grid{display:grid;grid-template-columns:1.2fr .8fr .8fr .8fr;gap:16px;margin-top:20px;}
.mini-card{background:rgba(255,255,255,0.06);padding:18px;border-radius:18px;border:1px solid rgba(255,255,255,0.12);box-shadow:0 0 18px rgba(0,255,198,0.08);}
.mini-card h3{font-size:15px;color:#00ffc6;margin-bottom:8px;}
.mini-card p{font-size:13px;opacity:.9;line-height:1.6;}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px;}
.field{display:flex;flex-direction:column;gap:8px;}
.field.full{grid-column:1/-1;}
label{font-size:13px;font-weight:700;color:#fff;}
input[type="text"],textarea,select{width:100%;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.23);color:#fff;outline:none;font-size:14px;transition:all .25s ease;}
input[type="text"]:focus,textarea:focus,select:focus{border-color:#00ffc6;box-shadow:0 0 0 3px rgba(0,255,198,0.12);}
textarea{resize:vertical;min-height:100px;}
select option{color:#111;}
.hint{font-size:12px;opacity:.78;}
.id-preview{padding:12px 14px;border-radius:14px;background:rgba(0,255,198,0.09);border:1px dashed rgba(0,255,198,0.35);font-weight:900;color:#00ffc6;letter-spacing:.8px;min-height:48px;display:flex;align-items:center;}
.sports-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.choice-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;}
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
.alert{margin-top:18px;padding:14px 16px;border-radius:14px;font-weight:700;text-align:center;}
.alert.ok{background:rgba(0,255,198,0.12);border:1px solid rgba(0,255,198,0.25);color:#d8fff7;}
.alert.error{background:rgba(255,77,109,0.12);border:1px solid rgba(255,77,109,0.28);color:#ffe1e7;}
.tools-bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-top:16px;margin-bottom:14px;}
.search-box{width:100%;max-width:360px;}
.table-wrap{margin-top:16px;overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,0.12);}
table{width:100%;border-collapse:collapse;min-width:1300px;background:rgba(0,0,0,0.22);}
th,td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,0.10);text-align:center;font-size:13px;white-space:nowrap;}
th{background:rgba(0,26,255,0.22);color:#00ffc6;font-weight:900;}
tr:hover td{background:rgba(255,255,255,0.05);}
.left{text-align:left;font-weight:700;}
.empty{padding:18px;border-radius:16px;border:1px dashed rgba(255,255,255,0.25);background:rgba(0,0,0,0.22);max-width:780px;margin:18px auto 0 auto;text-align:center;}
.status-chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid rgba(255,255,255,0.15);}
.estado-disponible{background:rgba(0,255,198,0.14);color:#b9fff0;}
.estado-ocupado{background:rgba(255,193,7,0.16);color:#ffeaa7;}
.estado-suspendido{background:rgba(255,77,109,0.16);color:#ffd8e1;}
.estado-inactivo{background:rgba(180,180,180,0.14);color:#f0f0f0;}
.tiny-actions{display:flex;gap:8px;justify-content:center;}
.tiny-actions a{padding:8px 10px;border-radius:10px;font-size:12px;font-weight:900;text-decoration:none;}
.edit-link{background:rgba(0,255,198,0.12);color:#b9fff0;border:1px solid rgba(0,255,198,0.22);}
.delete-link{background:rgba(255,77,109,0.12);color:#ffd8e1;border:1px solid rgba(255,77,109,0.22);}
.req{color:#00ffc6;font-weight:900;}
@media(max-width:1100px){
    .top-grid{grid-template-columns:1fr 1fr;}
    .form-grid,.sports-grid{grid-template-columns:1fr;}
    .estrellas{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:768px){
    .sidebar{position:relative;width:100%;height:auto;box-shadow:none;}
    .main-content{margin-left:0;padding:20px;}
    .main-content h1{font-size:24px;}
    .card{padding:18px;}
    .top-grid{grid-template-columns:1fr;}
    .choice-grid{grid-template-columns:1fr 1fr;}
    .estrellas{grid-template-columns:1fr;}
    .actions .btn{width:100%;max-width:320px;text-align:center;}
    table{min-width:1150px;}
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Administrar Árbitros - RETAME'); } ?>

<div class="sidebar">
    <img src="assets/doctor.png" alt="Logo">
    <h2>⚖️ RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="AdminArbitros.php">⚖️ Administrar Árbitros</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>⚖️ Administración de Árbitros</h1>

    <div class="card">
        <div class="subtext">Usuario: <b><?php echo $NombreUser; ?></b></div>
        <div class="subtext">Registra, edita y administra árbitros con una interfaz más visual, rápida e interactiva.</div>

        <div class="top-grid">
            <div class="mini-card">
                <h3>🧠 ID automático</h3>
                <p>El sistema genera el <b>id_arbitro</b> con nombre y apellidos en mayúsculas, sin espacios, más números aleatorios. Si ya existe, prueba otra combinación automáticamente.</p>
            </div>
            <div class="mini-card">
                <h3>📊 Total</h3>
                <p><span class="badge"><?php echo $totalArbitros; ?></span> árbitros registrados</p>
            </div>
            <div class="mini-card">
                <h3>✅ Disponibles</h3>
                <p><span class="badge"><?php echo $totalDisponibles; ?></span> árbitros disponibles</p>
            </div>
            <div class="mini-card">
                <h3>🕒 Ocupados</h3>
                <p><span class="badge"><?php echo $totalOcupados; ?></span> árbitros ocupados</p>
            </div>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert <?php echo h($tipoMensaje); ?>"><?php echo h($mensaje); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
            <input type="hidden" name="id_original" value="<?php echo h($arbitroEditar['id_arbitro']); ?>">

            <div class="form-grid">
                <div class="field">
                    <label>Nombre <span class="req">*</span></label>
                    <input type="text" name="nombre" id="nombre" maxlength="100" required value="<?php echo h($arbitroEditar['nombre']); ?>" placeholder="Ejemplo: José">
                </div>

                <div class="field">
                    <label>Apellidos <span class="req">*</span></label>
                    <input type="text" name="apellidos" id="apellidos" maxlength="150" required value="<?php echo h($arbitroEditar['apellidos']); ?>" placeholder="Ejemplo: Pérez Gómez">
                </div>

                <div class="field">
                    <label>Vista previa del ID</label>
                    <div class="id-preview" id="idPreview"><?php echo $editando ? h($arbitroEditar['id_arbitro']) : 'ARBITRO1234'; ?></div>
                </div>

                <div class="field">
                    <label>Código postal <span class="req">*</span></label>
                    <input type="text" name="codigo_postal" maxlength="5" pattern="[0-9]{5}" required value="<?php echo h($arbitroEditar['codigo_postal']); ?>" placeholder="Ejemplo: 30000">
                </div>

                <div class="field full">
                    <label>Tipo <span class="req">*</span></label>
                    <input type="text" name="tipo" list="tiposArbitro" maxlength="100" required value="<?php echo h($arbitroEditar['tipo']); ?>" placeholder="Ejemplo: Principal, Asistente, Auxiliar, VAR">
                    <datalist id="tiposArbitro">
                        <option value="Principal">
                        <option value="Asistente">
                        <option value="Auxiliar">
                        <option value="VAR">
                        <option value="Cuarto árbitro">
                        <option value="Anotador">
                    </datalist>
                </div>

                <div class="field full">
                    <label>Deportes <span class="req">*</span></label>
                    <div class="sports-grid">
                        <div class="field">
                            <label>Deporte 1 <span class="req">*</span></label>
                            <select name="deporte1" class="deporte-select" required>
                                <option value="">Selecciona un deporte</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($arbitroEditar['deporte1'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 2</label>
                            <select name="deporte2" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($arbitroEditar['deporte2'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 3</label>
                            <select name="deporte3" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($arbitroEditar['deporte3'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 4</label>
                            <select name="deporte4" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($arbitroEditar['deporte4'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Deporte 5</label>
                            <select name="deporte5" class="deporte-select">
                                <option value="">Opcional</option>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?php echo h($dep); ?>" <?php echo ($arbitroEditar['deporte5'] === $dep) ? 'selected' : ''; ?>><?php echo h($dep); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field full">
                    <label>Estado <span class="req">*</span></label>
                    <div class="choice-grid">
                        <input type="radio" name="estado" id="estado1" value="Disponible" <?php echo ($arbitroEditar['estado'] === 'Disponible') ? 'checked' : ''; ?> required>
                        <label for="estado1">✅ Disponible</label>

                        <input type="radio" name="estado" id="estado2" value="Ocupado" <?php echo ($arbitroEditar['estado'] === 'Ocupado') ? 'checked' : ''; ?>>
                        <label for="estado2">🕒 Ocupado</label>

                        <input type="radio" name="estado" id="estado3" value="Suspendido" <?php echo ($arbitroEditar['estado'] === 'Suspendido') ? 'checked' : ''; ?>>
                        <label for="estado3">⛔ Suspendido</label>

                        <input type="radio" name="estado" id="estado4" value="Inactivo" <?php echo ($arbitroEditar['estado'] === 'Inactivo') ? 'checked' : ''; ?>>
                        <label for="estado4">⚪ Inactivo</label>
                    </div>
                </div>

                <div class="field full">
                    <label>Calificación <span class="req">*</span></label>
                    <div class="estrellas">
                        <input type="radio" name="califiacion" id="cal1" value="1 estrella" <?php echo ($arbitroEditar['califiacion'] === '1 estrella') ? 'checked' : ''; ?> required>
                        <label for="cal1"><span>⭐</span>1 estrella</label>

                        <input type="radio" name="califiacion" id="cal2" value="2 estrellas" <?php echo ($arbitroEditar['califiacion'] === '2 estrellas') ? 'checked' : ''; ?>>
                        <label for="cal2"><span>⭐⭐</span>2 estrellas</label>

                        <input type="radio" name="califiacion" id="cal3" value="3 estrellas" <?php echo ($arbitroEditar['califiacion'] === '3 estrellas') ? 'checked' : ''; ?>>
                        <label for="cal3"><span>⭐⭐⭐</span>3 estrellas</label>

                        <input type="radio" name="califiacion" id="cal4" value="4 estrellas" <?php echo ($arbitroEditar['califiacion'] === '4 estrellas') ? 'checked' : ''; ?>>
                        <label for="cal4"><span>⭐⭐⭐⭐</span>4 estrellas</label>

                        <input type="radio" name="califiacion" id="cal5" value="5 estrellas" <?php echo ($arbitroEditar['califiacion'] === '5 estrellas') ? 'checked' : ''; ?>>
                        <label for="cal5"><span>⭐⭐⭐⭐⭐</span>5 estrellas</label>
                    </div>
                </div>

                <div class="field full">
                    <label>Descripción</label>
                    <textarea name="descripcion" maxlength="500" placeholder="Experiencia, torneos, observaciones, disponibilidad o datos importantes"><?php echo h($arbitroEditar['descripcion']); ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary"><?php echo $editando ? 'Actualizar árbitro' : 'Guardar árbitro'; ?></button>
                <a href="AdminArbitros.php" class="btn btn-secondary">Limpiar formulario</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="subtext">Listado y administración de árbitros</div>

        <div class="tools-bar">
            <input type="text" id="busquedaTabla" class="search-box" placeholder="Buscar por ID, nombre, apellidos, tipo, deporte o estado">
            <span class="badge" id="contadorFilas"><?php echo count($arbitros); ?> registros</span>
        </div>

        <?php if (empty($arbitros)): ?>
            <div class="empty">No hay árbitros registrados todavía.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table id="tablaArbitros">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre completo</th>
                            <th>CP</th>
                            <th>Tipo</th>
                            <th>Deportes</th>
                            <th>Estado</th>
                            <th>Calificación</th>
                            <th>Descripción</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($arbitros as $a): ?>
                            <?php
                                $estadoClase = '';
                                if ($a['estado'] === 'Disponible') $estadoClase = 'estado-disponible';
                                if ($a['estado'] === 'Ocupado') $estadoClase = 'estado-ocupado';
                                if ($a['estado'] === 'Suspendido') $estadoClase = 'estado-suspendido';
                                if ($a['estado'] === 'Inactivo') $estadoClase = 'estado-inactivo';

                                $deps = [];
                                if (!empty($a['deporte1'])) $deps[] = $a['deporte1'];
                                if (!empty($a['deporte2'])) $deps[] = $a['deporte2'];
                                if (!empty($a['deporte3'])) $deps[] = $a['deporte3'];
                                if (!empty($a['deporte4'])) $deps[] = $a['deporte4'];
                                if (!empty($a['deporte5'])) $deps[] = $a['deporte5'];
                            ?>
                            <tr>
                                <td><b><?php echo h($a['id_arbitro']); ?></b></td>
                                <td class="left"><?php echo h($a['nombre'] . ' ' . $a['apellidos']); ?></td>
                                <td><?php echo h($a['codigo_postal']); ?></td>
                                <td><?php echo h($a['tipo']); ?></td>
                                <td class="left"><?php echo h(implode(', ', $deps)); ?></td>
                                <td><span class="status-chip <?php echo h($estadoClase); ?>"><?php echo h($a['estado']); ?></span></td>
                                <td><?php echo h($a['califiacion']); ?></td>
                                <td class="left"><?php echo h($a['descripcion']); ?></td>
                                <td>
                                    <div class="tiny-actions">
                                        <a class="edit-link" href="AdminArbitros.php?editar=<?php echo urlencode($a['id_arbitro']); ?>">Editar</a>
                                        <a class="delete-link deleteBtn" href="AdminArbitros.php?eliminar=<?php echo urlencode($a['id_arbitro']); ?>">Eliminar</a>
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
const apellidosInput = document.getElementById('apellidos');
const idPreview = document.getElementById('idPreview');
const deporteSelects = document.querySelectorAll('.deporte-select');
const deleteBtns = document.querySelectorAll('.deleteBtn');
const busquedaTabla = document.getElementById('busquedaTabla');
const tablaArbitros = document.getElementById('tablaArbitros');
const contadorFilas = document.getElementById('contadorFilas');

function normalizarTexto(texto) {
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
    const base = normalizarTexto((nombreInput.value || '') + (apellidosInput.value || '')) || 'ARBITRO';
    idPreview.textContent = base + randomDigits(4);
}

if (nombreInput && apellidosInput) {
    nombreInput.addEventListener('input', actualizarVistaId);
    apellidosInput.addEventListener('input', actualizarVistaId);
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
        if (!confirm('¿Seguro que deseas eliminar este árbitro?')) {
            e.preventDefault();
        }
    });
});

if (busquedaTabla && tablaArbitros) {
    busquedaTabla.addEventListener('input', function() {
        const texto = this.value.toLowerCase().trim();
        const filas = tablaArbitros.querySelectorAll('tbody tr');
        let visibles = 0;

        filas.forEach(fila => {
            const contenido = fila.textContent.toLowerCase();
            const mostrar = contenido.includes(texto);
            fila.style.display = mostrar ? '' : 'none';
            if (mostrar) visibles++;
        });

        contadorFilas.textContent = visibles + ' registros';
    });
}
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>