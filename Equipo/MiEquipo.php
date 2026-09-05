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

include_once 'conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

$equipos_usuario = [];



$nuevas_notificaciones = [];
$nuevas_count = 0;

function esStaffEquipo($conn, $idEquipo, $idUsuario) {
    $sql = "SELECT Id_Equipo
            FROM equipo
            WHERE Id_Equipo = ?
              AND (
                    Capitan = ?
                 OR Entrenador = ?
                 OR Asistente1 = ?
                 OR Asistente2 = ?
                 OR Asistente3 = ?
                 OR Asistente4 = ?
              )
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $idEquipo, $idUsuario, $idUsuario, $idUsuario, $idUsuario, $idUsuario, $idUsuario);
    $stmt->execute();
    $stmt->store_result();
    $ok = ($stmt->num_rows > 0);
    $stmt->close();
    return $ok;
}

function extraerIdSolicitanteDesdeDescripcion($descripcion) {
    $descripcion = trim((string)$descripcion);
    if ($descripcion === '') return '';
    $partes = preg_split('/\s+/', $descripcion);
    return isset($partes[0]) ? trim($partes[0]) : '';
}

if (!empty($Id_Retador)) {

    $sqlNewNoti = "SELECT 
                        n.Id_notificacion,
                        n.id_retador,
                        n.descripcion,
                        n.tipo,
                        n.fecha,
                        n.id_area,
                        e.Nombre AS nombre_equipo,
                        (ej.Id_Jugador IS NOT NULL) AS es_miembro
                   FROM notificaciones n
                   INNER JOIN equipo e ON e.Id_Equipo = n.id_area
                   LEFT JOIN equipo_jugador ej 
                          ON ej.Id_Equipo = n.id_area 
                         AND ej.Id_Jugador = ?
                   WHERE n.id_retador = ?
                     AND (n.Estado IS NULL OR n.Estado = '' OR UPPER(n.Estado) = 'VER')
                   ORDER BY n.fecha DESC
                   LIMIT 30";

    $stmtNew = $conn->prepare($sqlNewNoti);
    if ($stmtNew) {
        $stmtNew->bind_param("ss", $Id_Retador, $Id_Retador);
        $stmtNew->execute();
        $resNew = $stmtNew->get_result();

        $tmp = [];
        $idsParaVisto = [];

        while ($rowN = $resNew->fetch_assoc()) {

            $tipo = isset($rowN['tipo']) ? trim($rowN['tipo']) : '';
            $idEquipo = $rowN['id_area'];
            $esMiembro = !empty($rowN['es_miembro']);
            $esStaff = esStaffEquipo($conn, $idEquipo, $Id_Retador);

            if ($tipo === 'Solicitud Equipo') {
                if (!$esStaff) continue;
            } elseif ($tipo === 'Aceptacion Equipo') {
                if (!$esMiembro && !$esStaff) continue;
            } else {
                if (!$esMiembro && !$esStaff) continue;
            }

            if ($tipo === 'Aceptacion Equipo') {
                $idSolicitante = extraerIdSolicitanteDesdeDescripcion($rowN['descripcion']);
                if ($idSolicitante !== '' && $Id_Retador === $idSolicitante) {
                    $rowN['descripcion'] = "Fuiste aceptado en el equipo " . $rowN['nombre_equipo'];
                }
            }

            $tmp[] = $rowN;
            $idsParaVisto[] = $rowN['Id_notificacion'];
        }

        $nuevas_notificaciones = array_slice($tmp, 0, 5);
        $nuevas_count = count($tmp);

        $stmtNew->close();

        if (!empty($idsParaVisto)) {
            $placeholders = implode(',', array_fill(0, count($idsParaVisto), '?'));
            $sqlUpd = "UPDATE notificaciones 
                       SET Estado = 'Visto'
                       WHERE id_retador = ?
                         AND Id_notificacion IN ($placeholders)";

            $stmtUpd = $conn->prepare($sqlUpd);
            if ($stmtUpd) {
                $types = str_repeat('s', 1 + count($idsParaVisto));
                $params = array_merge([$Id_Retador], $idsParaVisto);

                $bind_names = [];
                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $bind_names[] = &$params[$i];
                }
                call_user_func_array([$stmtUpd, 'bind_param'], $bind_names);

                $stmtUpd->execute();
                $stmtUpd->close();
            }
        }
    }
}

// Obtener equipos del usuario
if (!empty($Id_Retador)) {
    $sql_equipos = "SELECT e.Nombre FROM equipo e INNER JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo WHERE ej.Id_Jugador = ?";
    $stmt_eq = $conn->prepare($sql_equipos);
    if ($stmt_eq) {
        $stmt_eq->bind_param("s", $Id_Retador);
        $stmt_eq->execute();
        $res_eq = $stmt_eq->get_result();
        while ($fila_eq = $res_eq->fetch_assoc()) {
            $equipos_usuario[] = $fila_eq['Nombre'];
        }
        $stmt_eq->close();
    }
}

// Obtener deportes de tipo equipo
$deportes = [];
$res = $conn->query("SELECT Id_Deporte, Nombre FROM deporte WHERE Tipo='Equipo'");
while($fila = $res->fetch_assoc()) {
    $deportes[] = $fila;
}

// Función generar Id de equipo
function generarIdEquipo($nombre, $conn){
    $base = preg_replace("/[^a-zA-Z0-9]/", "", $nombre);
    if($base == "") $base = "EQ";
    do {
        $rand = rand(100,999);
        $id_equipo = $base . $rand;
        $check = $conn->query("SELECT Id_Equipo FROM equipo WHERE Id_Equipo='$id_equipo'");
    } while($check->num_rows > 0);
    return $id_equipo;
}

// Función generar Id_EquipoJugador
function generarIdEquipoJugador($id_equipo, $id_jugador, $conn){
    do {
        $id_ej = $id_jugador . $id_equipo . rand(10,99);
        $check = $conn->query("SELECT Id_EquipoJugador FROM equipo_jugador WHERE Id_EquipoJugador='$id_ej'");
    } while($check->num_rows > 0);
    return $id_ej;
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
<title>Perfil - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
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

.modal-content input, .modal-content select { 
    margin:8px 0; 
    padding:12px; 
    width:100%; 
    border-radius:8px; 
    border:1px solid rgba(0,255,198,0.3);
    background:rgba(255,255,255,0.1);
    color:white;
    font-size:14px;
}

.modal-content input::placeholder {
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
    .perfil-card {
        padding: 20px;
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

.btn-retar{position:fixed;bottom:20px;right:20px;width:90px;height:90px;background:linear-gradient(135deg,#ff0000,#e00000);color:#fff;border-radius:50%;display:flex;justify-content:center;align-items:center;font-size:16px;font-weight:bold;text-decoration:none;box-shadow:0 0 20px rgba(255,0,0,0.7);transition:all 0.3s ease;z-index:999;border:2px solid rgba(255,255,255,0.3);}
.btn-ligas{position:fixed;bottom:130px;right:20px;width:90px;height:90px;background:linear-gradient(135deg,#00aaff,#0066cc);color:#fff;border-radius:50%;display:flex;justify-content:center;align-items:center;font-size:16px;font-weight:bold;text-decoration:none;box-shadow:0 0 20px rgba(0,100,255,0.7);transition:all 0.3s ease;z-index:998;border:2px solid rgba(255,255,255,0.3);}
.btn-retar:hover,.btn-ligas:hover{transform:scale(1.1);box-shadow:0 0 30px rgba(255,0,0,0.9);}
.btn-ligas:hover{box-shadow:0 0 30px rgba(0,100,255,0.9);}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>

<style id="retame-equipo-fix-css">
body.retame-oficial-page .btn-retar,
body.retame-oficial-page .btn-ligas,
body.retame-oficial-page .bg-particles,
body.retame-oficial-page .particle{
    display:none!important;
}
body.retame-oficial-page > .main-content,
body.retame-oficial-page > .container{
    width:min(1180px,100%)!important;
    max-width:1180px!important;
    margin:0 auto!important;
    padding:18px 0 26px!important;
    position:relative!important;
    z-index:2!important;
}
body.retame-oficial-page > .container .main-content{
    margin:0!important;
    width:100%!important;
    max-width:100%!important;
}
body.retame-oficial-page h1{
    color:#1877f2!important;
    text-align:left!important;
    font-weight:900!important;
    letter-spacing:-.03em!important;
    text-shadow:none!important;
    margin:0 0 22px!important;
    font-size:clamp(26px,3.4vw,40px)!important;
}
body.retame-oficial-page.dark-mode h1{
    color:#4db8ff!important;
}
body.retame-oficial-page .header{
    text-align:left!important;
    margin-bottom:22px!important;
}
body.retame-oficial-page .header p,
body.retame-oficial-page .subtext,
body.retame-oficial-page .small,
body.retame-oficial-page .info-box p,
body.retame-oficial-page .empty p,
body.retame-oficial-page .no-equipos p,
body.retame-oficial-page .no-results p{
    color:#64748b!important;
}
body.retame-oficial-page.dark-mode .header p,
body.retame-oficial-page.dark-mode .subtext,
body.retame-oficial-page.dark-mode .small,
body.retame-oficial-page.dark-mode .info-box p,
body.retame-oficial-page.dark-mode .empty p,
body.retame-oficial-page.dark-mode .no-equipos p,
body.retame-oficial-page.dark-mode .no-results p{
    color:#cbd5e1!important;
}
body.retame-oficial-page .form-card,
body.retame-oficial-page .perfil-card,
body.retame-oficial-page .card,
body.retame-oficial-page .team-item,
body.retame-oficial-page .equipo-card,
body.retame-oficial-page .info-box,
body.retame-oficial-page .success-info,
body.retame-oficial-page .team-modal,
body.retame-oficial-page .empty,
body.retame-oficial-page .no-equipos{
    background:rgba(255,255,255,.95)!important;
    color:#111827!important;
    border:1px solid rgba(0,153,255,.22)!important;
    border-top:4px solid rgba(0,153,255,.86)!important;
    border-radius:24px!important;
    box-shadow:0 18px 42px rgba(15,23,42,.12),0 0 0 1px rgba(255,255,255,.72)!important;
    backdrop-filter:blur(14px)!important;
}
body.retame-oficial-page.dark-mode .form-card,
body.retame-oficial-page.dark-mode .perfil-card,
body.retame-oficial-page.dark-mode .card,
body.retame-oficial-page.dark-mode .team-item,
body.retame-oficial-page.dark-mode .equipo-card,
body.retame-oficial-page.dark-mode .info-box,
body.retame-oficial-page.dark-mode .success-info,
body.retame-oficial-page.dark-mode .team-modal,
body.retame-oficial-page.dark-mode .empty,
body.retame-oficial-page.dark-mode .no-equipos{
    background:rgba(17,24,39,.94)!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.40)!important;
    border-top-color:#0099ff!important;
    box-shadow:0 18px 42px rgba(0,0,0,.32),0 0 24px rgba(0,153,255,.14)!important;
}
body.retame-oficial-page .form-card,
body.retame-oficial-page .perfil-card,
body.retame-oficial-page .card{
    padding:clamp(20px,3.2vw,36px)!important;
}
body.retame-oficial-page .form-card h3,
body.retame-oficial-page .perfil-card h3,
body.retame-oficial-page .card h2,
body.retame-oficial-page .card h3,
body.retame-oficial-page .info-box h3,
body.retame-oficial-page .empty h4,
body.retame-oficial-page .no-equipos h3,
body.retame-oficial-page .no-results h3{
    color:#1877f2!important;
    text-align:left!important;
    border-bottom:none!important;
    padding-bottom:0!important;
    margin:0 0 18px!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .form-card h3,
body.retame-oficial-page.dark-mode .perfil-card h3,
body.retame-oficial-page.dark-mode .card h2,
body.retame-oficial-page.dark-mode .card h3,
body.retame-oficial-page.dark-mode .info-box h3,
body.retame-oficial-page.dark-mode .empty h4,
body.retame-oficial-page.dark-mode .no-equipos h3,
body.retame-oficial-page.dark-mode .no-results h3{
    color:#4db8ff!important;
}
body.retame-oficial-page .form-group,
body.retame-oficial-page .field{
    margin-bottom:18px!important;
}
body.retame-oficial-page label,
body.retame-oficial-page .info-label,
body.retame-oficial-page .label{
    color:#1877f2!important;
    font-weight:800!important;
    letter-spacing:-.01em!important;
}
body.retame-oficial-page.dark-mode label,
body.retame-oficial-page.dark-mode .info-label,
body.retame-oficial-page.dark-mode .label{
    color:#83caff!important;
}
body.retame-oficial-page input,
body.retame-oficial-page select,
body.retame-oficial-page textarea{
    width:100%;
    border-radius:16px!important;
    border:1px solid rgba(0,153,255,.25)!important;
    background:#f8fafc!important;
    color:#111827!important;
    padding:13px 15px!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.85)!important;
}
body.retame-oficial-page input:focus,
body.retame-oficial-page select:focus,
body.retame-oficial-page textarea:focus{
    outline:none!important;
    border-color:#0099ff!important;
    box-shadow:0 0 0 4px rgba(0,153,255,.14)!important;
    transform:none!important;
}
body.retame-oficial-page.dark-mode input,
body.retame-oficial-page.dark-mode select,
body.retame-oficial-page.dark-mode textarea{
    background:#0f172a!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.42)!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04)!important;
}
body.retame-oficial-page .submit-button,
body.retame-oficial-page .search-button,
body.retame-oficial-page .success-button,
body.retame-oficial-page .view-details-button,
body.retame-oficial-page .btn,
body.retame-oficial-page .modal-button,
body.retame-oficial-page .back-button,
body.retame-oficial-page .circle-equipo{
    border:none!important;
    border-radius:16px!important;
    text-decoration:none!important;
    color:#fff!important;
    font-weight:900!important;
    letter-spacing:.01em!important;
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    box-shadow:0 12px 24px rgba(24,119,242,.24)!important;
    transition:transform .2s ease,box-shadow .2s ease,filter .2s ease!important;
}
body.retame-oficial-page .submit-button:hover,
body.retame-oficial-page .search-button:hover,
body.retame-oficial-page .success-button:hover,
body.retame-oficial-page .view-details-button:hover,
body.retame-oficial-page .btn:hover,
body.retame-oficial-page .modal-button:hover,
body.retame-oficial-page .back-button:hover,
body.retame-oficial-page .circle-equipo:hover{
    transform:translateY(-2px)!important;
    filter:saturate(1.08)!important;
    box-shadow:0 16px 30px rgba(24,119,242,.32)!important;
}
body.retame-oficial-page .btn-danger,
body.retame-oficial-page .modal-button-cancel{
    background:linear-gradient(135deg,#ef4444,#ff4b5c)!important;
    box-shadow:0 12px 24px rgba(239,68,68,.22)!important;
}
body.retame-oficial-page .btn-secondary{
    background:linear-gradient(135deg,#475569,#64748b)!important;
    box-shadow:0 12px 24px rgba(71,85,105,.20)!important;
}
body.retame-oficial-page .actions,
body.retame-oficial-page .modal-actions{
    display:flex!important;
    justify-content:center!important;
    align-items:center!important;
    gap:12px!important;
    flex-wrap:wrap!important;
    margin-top:18px!important;
}
body.retame-oficial-page .search-input{
    display:flex!important;
    gap:12px!important;
    align-items:stretch!important;
}
body.retame-oficial-page .search-input input{
    min-width:0!important;
}
body.retame-oficial-page .search-button{
    width:auto!important;
    padding:0 24px!important;
    white-space:nowrap!important;
}
body.retame-oficial-page .search-type{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
}
body.retame-oficial-page .search-option label{
    background:#f8fafc!important;
    color:#334155!important;
    border:1px solid rgba(0,153,255,.25)!important;
    box-shadow:0 8px 18px rgba(15,23,42,.08)!important;
}
body.retame-oficial-page .search-option input[type="radio"]:checked + label{
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    color:#fff!important;
    border-color:transparent!important;
    transform:none!important;
}
body.retame-oficial-page.dark-mode .search-option label{
    background:#0f172a!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.42)!important;
}
body.retame-oficial-page .team-item{
    padding:20px!important;
    margin-bottom:16px!important;
}
body.retame-oficial-page .team-item-header,
body.retame-oficial-page .team-item-details{
    gap:12px!important;
}
body.retame-oficial-page .team-item-name,
body.retame-oficial-page .equipo-nombre,
body.retame-oficial-page .info-value{
    color:#111827!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .team-item-name,
body.retame-oficial-page.dark-mode .equipo-nombre,
body.retame-oficial-page.dark-mode .info-value{
    color:#f8fafc!important;
}
body.retame-oficial-page .team-item-id,
body.retame-oficial-page .equipo-id,
body.retame-oficial-page .badge{
    display:inline-flex!important;
    align-items:center!important;
    width:max-content!important;
    border-radius:999px!important;
    padding:6px 10px!important;
    background:rgba(255,75,92,.10)!important;
    color:#e11d48!important;
    border:1px solid rgba(255,75,92,.24)!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .team-item-id,
body.retame-oficial-page.dark-mode .equipo-id,
body.retame-oficial-page.dark-mode .badge{
    background:rgba(255,75,92,.16)!important;
    color:#fb7185!important;
    border-color:rgba(255,75,92,.35)!important;
}
body.retame-oficial-page .equipos-container,
body.retame-oficial-page .equipos-grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr))!important;
    gap:16px!important;
    margin-top:18px!important;
}
body.retame-oficial-page .circle-equipo,
body.retame-oficial-page .equipo-card{
    min-height:126px!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    text-align:center!important;
    padding:18px!important;
}
body.retame-oficial-page .equipo-badge{
    width:58px!important;
    height:58px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    border-radius:20px!important;
    background:linear-gradient(135deg,#ff4b5c,#1877f2)!important;
    color:#fff!important;
    font-weight:900!important;
    margin-bottom:12px!important;
}
body.retame-oficial-page .equipo-link{
    text-decoration:none!important;
}
body.retame-oficial-page .grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(230px,1fr))!important;
    gap:16px!important;
}
body.retame-oficial-page .tabs{
    display:flex!important;
    flex-wrap:wrap!important;
    gap:10px!important;
    margin-bottom:22px!important;
}
body.retame-oficial-page .tabs a{
    text-decoration:none!important;
    color:#334155!important;
    font-weight:900!important;
    padding:11px 14px!important;
    border-radius:999px!important;
    background:#f8fafc!important;
    border:1px solid rgba(0,153,255,.22)!important;
}
body.retame-oficial-page .tabs a.active{
    color:#fff!important;
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    border-color:transparent!important;
}
body.retame-oficial-page.dark-mode .tabs a{
    color:#e5e7eb!important;
    background:#0f172a!important;
    border-color:rgba(0,153,255,.38)!important;
}
body.retame-oficial-page table{
    width:100%!important;
    border-collapse:separate!important;
    border-spacing:0!important;
    overflow:hidden!important;
    border-radius:18px!important;
    background:rgba(255,255,255,.72)!important;
    box-shadow:0 10px 24px rgba(15,23,42,.08)!important;
}
body.retame-oficial-page th{
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    color:#fff!important;
    font-weight:900!important;
}
body.retame-oficial-page th,
body.retame-oficial-page td{
    padding:13px 14px!important;
    border-bottom:1px solid rgba(15,23,42,.08)!important;
    color:#111827!important;
}
body.retame-oficial-page.dark-mode table{
    background:rgba(15,23,42,.76)!important;
}
body.retame-oficial-page.dark-mode th,
body.retame-oficial-page.dark-mode td{
    color:#e5e7eb!important;
    border-bottom-color:rgba(255,255,255,.08)!important;
}
body.retame-oficial-page .alert,
body.retame-oficial-page .error-message{
    border-radius:18px!important;
    padding:16px 18px!important;
    margin-bottom:18px!important;
    border:1px solid rgba(239,68,68,.24)!important;
    background:rgba(239,68,68,.10)!important;
    color:#b91c1c!important;
}
body.retame-oficial-page .alert.ok,
body.retame-oficial-page .success-message{
    border-color:rgba(16,185,129,.30)!important;
    background:rgba(16,185,129,.10)!important;
    color:#047857!important;
}
body.retame-oficial-page.dark-mode .alert,
body.retame-oficial-page.dark-mode .error-message{
    color:#fecaca!important;
    background:rgba(239,68,68,.14)!important;
}
body.retame-oficial-page.dark-mode .alert.ok,
body.retame-oficial-page.dark-mode .success-message{
    color:#bbf7d0!important;
    background:rgba(16,185,129,.14)!important;
}
body.retame-oficial-page .team-modal-overlay{
    position:fixed!important;
    inset:0!important;
    z-index:1500!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:18px!important;
    background:rgba(15,23,42,.54)!important;
    backdrop-filter:blur(8px)!important;
}
body.retame-oficial-page .team-modal{
    width:min(760px,100%)!important;
    max-height:90dvh!important;
    overflow:auto!important;
    padding:26px!important;
}
body.retame-oficial-page .modal-header{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:12px!important;
    margin-bottom:18px!important;
}
body.retame-oficial-page .modal-close{
    width:42px!important;
    height:42px!important;
    border-radius:50%!important;
    border:none!important;
    color:#fff!important;
    background:linear-gradient(135deg,#ef4444,#ff4b5c)!important;
    font-size:24px!important;
    cursor:pointer!important;
}
body.retame-oficial-page .modal-team-info{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(210px,1fr))!important;
    gap:12px!important;
}
body.retame-oficial-page .info-group{
    background:rgba(0,153,255,.07)!important;
    border:1px solid rgba(0,153,255,.16)!important;
    border-radius:16px!important;
    padding:14px!important;
}
body.retame-oficial-page.dark-mode .info-group{
    background:rgba(0,153,255,.10)!important;
    border-color:rgba(0,153,255,.26)!important;
}

body.retame-oficial-page .perfil-card [style*="rgba(0,255,198"],
body.retame-oficial-page .form-card [style*="rgba(0,255,198"],
body.retame-oficial-page .card [style*="rgba(0,255,198"]{
    background:rgba(0,153,255,.08)!important;
    border-color:rgba(0,153,255,.22)!important;
    border-radius:18px!important;
    color:inherit!important;
}
body.retame-oficial-page.dark-mode .perfil-card [style*="rgba(0,255,198"],
body.retame-oficial-page.dark-mode .form-card [style*="rgba(0,255,198"],
body.retame-oficial-page.dark-mode .card [style*="rgba(0,255,198"]{
    background:rgba(0,153,255,.12)!important;
    border-color:rgba(0,153,255,.32)!important;
}
@media(max-width:760px){
    body.retame-oficial-page .search-input{flex-direction:column!important;}
    body.retame-oficial-page .search-button{width:100%!important;padding:14px 18px!important;}
    body.retame-oficial-page .search-type{grid-template-columns:1fr!important;}
    body.retame-oficial-page table{display:block!important;overflow-x:auto!important;white-space:nowrap!important;}
    body.retame-oficial-page .tabs{overflow:auto!important;flex-wrap:nowrap!important;padding-bottom:4px!important;}
}
</style>

</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Mi Equipo - RETAME'); } ?>

<!-- Botón hamburguesa solo para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="overlay" onclick="toggleSidebar()"></div>
<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="../Perfil2.php">🏠 Inicio</a></li>
        <li><a href="UnirmeOtroEquipo.php">👥 Unirme a un equipo</a></li>
        <li><a href="CrearEquipo.php">👥 Crear Un Equipo</a></li>
        <li><a href="../Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="Mis_Equipos.php">👥📋 Mis Equipos</a></li>
        <li><a href="../Solicitudes.php">Solicitudes</a></li>
        <li>
            <a href="../Notificaciones.php">
                Notificaciones
                <?php if ($nuevas_count > 0): ?>
                    <span class="noti-alert-badge">!</span>
                <?php endif; ?>
            </a>
        </li>
        <li><a href="../login.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>
<div class="main-content">
    <h1>👥 Mi Equipo</h1>
    
    <div class="perfil-card">
        <?php if(!empty($equipos_usuario)): ?>
            <h3>🏆 Mis Equipos</h3>
            <div class="equipos-container">
                <?php foreach($equipos_usuario as $equipo): ?>
                    <a class="circle-equipo" href="Mis_Equipos.php">
                        <?php echo htmlspecialchars($equipo); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top:40px; padding:20px; background:rgba(0,255,198,0.1); border-radius:15px; border:1px solid rgba(0,255,198,0.3);">
                <h4>📊 Estadísticas</h4>
                <p>Tienes <strong><?php echo count($equipos_usuario); ?></strong> equipo(s) registrado(s)</p>
                <p>Haz clic en cualquier equipo para gestionarlo</p>
            </div>
        <?php else: ?>
            <div class="no-equipos">
                <h3>😕 No tienes equipos registrados</h3>
                <p>Aún no perteneces a ningún equipo. ¡Únete o crea uno para comenzar a jugar!</p>
                
                <div class="actions" style="margin-top:25px;">
                    <a class="btn btn-primary" href="UnirmeEquipo.php">👥 Unirme a un equipo</a>
                    <a class="btn btn-secondary" href="CrearEquipo.php">🏆 Crear un equipo</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const tarjetas = document.querySelectorAll('.circle-equipo');
    tarjetas.forEach(function(tarjeta) {
        tarjeta.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') tarjeta.click();
        });
    });
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>