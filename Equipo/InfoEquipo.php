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

$usuario = $_SESSION['usuario_data'];
$Id_Retador = isset($usuario['Id_Retador']) ? $usuario['Id_Retador'] : '';
$NombreUser = isset($usuario['Nombre']) ? htmlspecialchars($usuario['Nombre']) : 'Usuario';

if ($Id_Retador === '') {
    header("Location: login.php");
    exit();
}

$id_equipo = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id_equipo === '') {
    header("Location: Mis_Equipos.php");
    exit();
}

$view = isset($_GET['view']) ? strtolower(trim($_GET['view'])) : 'equipo';
$viewsValidas = ['equipo', 'integrantes', 'calendario', 'estadisticas'];
if (!in_array($view, $viewsValidas)) $view = 'equipo';

$sqlRol = "SELECT tipo, numero
           FROM equipo_jugador
           WHERE Id_Equipo = ? AND Id_Jugador = ?
           LIMIT 1";
$stmtRol = $conn->prepare($sqlRol);
$stmtRol->bind_param("ss", $id_equipo, $Id_Retador);
$stmtRol->execute();
$resRol = $stmtRol->get_result();
$rowRol = $resRol ? $resRol->fetch_assoc() : null;
$stmtRol->close();

if (!$rowRol) {
    header("Location: Mis_Equipos.php");
    exit();
}

$tipo = strtolower(trim((string)$rowRol['tipo']));
$tipo = str_replace("á", "a", $tipo);
$rolTexto = ($tipo === 'capitan') ? 'Capitán' : 'Jugador';
$numeroActual = isset($rowRol['numero']) ? (int)$rowRol['numero'] : 0;

$sqlEquipo = "SELECT Id_Equipo, Nombre
              FROM equipo
              WHERE Id_Equipo = ?
              LIMIT 1";
$stmtEq = $conn->prepare($sqlEquipo);
$stmtEq->bind_param("s", $id_equipo);
$stmtEq->execute();
$resEq = $stmtEq->get_result();
$equipo = $resEq ? $resEq->fetch_assoc() : null;
$stmtEq->close();

if (!$equipo) {
    header("Location: Mis_Equipos.php");
    exit();
}

$NombreEquipo = htmlspecialchars($equipo['Nombre']);
$IdEquipoSafe = htmlspecialchars($equipo['Id_Equipo']);
$badge = mb_strtoupper(mb_substr($NombreEquipo, 0, 2));

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = isset($_POST['accion']) ? trim($_POST['accion']) : '';

    if ($accion === 'guardar_numero') {
        $nuevoNumero = isset($_POST['numero']) ? (int)$_POST['numero'] : 0;

        if ($nuevoNumero < 0 || $nuevoNumero > 999) {
            $msg = "Número inválido.";
        } else {
            $sqlUp = "UPDATE equipo_jugador
                      SET numero = ?
                      WHERE Id_Equipo = ? AND Id_Jugador = ?
                      LIMIT 1";
            $stmtUp = $conn->prepare($sqlUp);
            $stmtUp->bind_param("iss", $nuevoNumero, $id_equipo, $Id_Retador);
            $ok = $stmtUp->execute();
            $stmtUp->close();

            if ($ok) {
                $numeroActual = $nuevoNumero;
                $msg = "Número actualizado correctamente.";
            } else {
                $msg = "No se pudo actualizar el número.";
            }
        }
        $view = 'equipo';
    }

    if ($accion === 'salir_equipo') {
        if ($tipo === 'capitan') {
            $msg = "No puedes salir del equipo porque eres el capitán.";
            $view = 'equipo';
        } else {
            $sqlDel = "DELETE FROM equipo_jugador
                       WHERE Id_Equipo = ? AND Id_Jugador = ?
                       LIMIT 1";
            $stmtDel = $conn->prepare($sqlDel);
            $stmtDel->bind_param("ss", $id_equipo, $Id_Retador);
            $ok = $stmtDel->execute();
            $stmtDel->close();

            if ($ok) {
                header("Location: Mis_Equipos.php");
                exit();
            } else {
                $msg = "No se pudo salir del equipo. Intenta de nuevo.";
                $view = 'equipo';
            }
        }
    }
}

$partido = null;
$sqlPartido = "SELECT Id_Partido, id_equipolocal, id_equipovicitante, fecha, hora, Jornada, Estado, direccion, descripcion
               FROM calendario
               WHERE (id_equipolocal = ? OR id_equipovicitante = ?)
                 AND (fecha > CURDATE() OR (fecha = CURDATE() AND (hora IS NULL OR hora >= CURTIME())))
               ORDER BY fecha ASC, hora ASC
               LIMIT 1";
$stmtP = $conn->prepare($sqlPartido);
$stmtP->bind_param("ss", $id_equipo, $id_equipo);
$stmtP->execute();
$resP = $stmtP->get_result();
$partido = $resP ? $resP->fetch_assoc() : null;
$stmtP->close();

$localName = "";
$visName = "";
if ($partido) {
    $idL = $partido['id_equipolocal'];
    $idV = $partido['id_equipovicitante'];

    $sqlN = "SELECT Id_Equipo, Nombre FROM equipo WHERE Id_Equipo IN (?, ?)";
    $stmtN = $conn->prepare($sqlN);
    $stmtN->bind_param("ss", $idL, $idV);
    $stmtN->execute();
    $resN = $stmtN->get_result();
    $map = [];
    while ($r = $resN->fetch_assoc()) {
        $map[$r['Id_Equipo']] = $r['Nombre'];
    }
    $stmtN->close();

    $localName = isset($map[$idL]) ? htmlspecialchars($map[$idL]) : htmlspecialchars($idL);
    $visName = isset($map[$idV]) ? htmlspecialchars($map[$idV]) : htmlspecialchars($idV);
}

$integrantes = [];
$partidos = [];

if ($view === 'integrantes') {
    $sql = "SELECT ej.Id_Jugador, ej.tipo, ej.numero, r.Nombre
            FROM equipo_jugador ej
            INNER JOIN retador r ON r.Id_Retador = ej.Id_Jugador
            WHERE ej.Id_Equipo = ?
            ORDER BY (LOWER(ej.tipo)='capitán' OR LOWER(ej.tipo)='capitan') DESC, r.Nombre ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $id_equipo);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $integrantes[] = $r;
    }
    $stmt->close();
}

if ($view === 'calendario') {
    $sql = "SELECT Id_Partido, id_equipolocal, id_equipovicitante, fecha, hora, Jornada, Estado, direccion, descripcion, Goles_local, Goles_visitante, resultado_final
            FROM calendario
            WHERE id_equipolocal = ? OR id_equipovicitante = ?
            ORDER BY fecha DESC, hora DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $id_equipo, $id_equipo);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $partidos[] = $r;
    }
    $stmt->close();

    $ids = [];
    foreach ($partidos as $p) {
        $ids[$p['id_equipolocal']] = true;
        $ids[$p['id_equipovicitante']] = true;
    }

    $mapEq = [];
    $keys = array_keys($ids);
    if (count($keys) >= 2) {
        $sqlN = "SELECT Id_Equipo, Nombre FROM equipo WHERE Id_Equipo IN (?, ?)";
        $stmtN = $conn->prepare($sqlN);
        $stmtN->bind_param("ss", $keys[0], $keys[1]);
        $stmtN->execute();
        $resN = $stmtN->get_result();
        while ($r = $resN->fetch_assoc()) {
            $mapEq[$r['Id_Equipo']] = $r['Nombre'];
        }
        $stmtN->close();
    } elseif (count($keys) === 1) {
        $sqlN = "SELECT Id_Equipo, Nombre FROM equipo WHERE Id_Equipo = ?";
        $stmtN = $conn->prepare($sqlN);
        $stmtN->bind_param("s", $keys[0]);
        $stmtN->execute();
        $resN = $stmtN->get_result();
        while ($r = $resN->fetch_assoc()) {
            $mapEq[$r['Id_Equipo']] = $r['Nombre'];
        }
        $stmtN->close();
    }
}



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
<title>Equipo - RETAME</title>
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
.menu .active{background:rgba(244,16,16,0.35);border-color:rgba(244,16,16,0.6);}
.menu .active a{color:#fff;}
.main-content{flex:1;padding:40px;margin-left:250px;min-height:100vh;position:relative;z-index:1;}
.main-content h1{font-size:32px;color:#ffffff;margin-bottom:18px;text-align:center;}

.card{background:rgba(27,31,39,0.9);padding:28px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:980px;margin:0 auto;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}
.top{display:flex;gap:16px;align-items:center;justify-content:center;flex-wrap:wrap;margin-bottom:18px;text-align:center;}
.badge{width:88px;height:88px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(0,255,198,0.75);background:rgba(0,255,198,0.10);font-weight:800;letter-spacing:1px;font-size:14px;color:#fff;}
.info{min-width:260px;text-align:left;}
.info .name{font-weight:900;font-size:18px;margin-bottom:6px;}
.info .meta{font-size:12px;opacity:0.85;margin-bottom:4px;}
.pill{display:inline-block;margin-top:6px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;background:rgba(0,255,198,0.15);border:1px solid rgba(0,255,198,0.25);}

.row{display:flex;gap:14px;justify-content:center;align-items:flex-end;flex-wrap:wrap;margin-top:12px;}
.field{flex:0 0 260px;text-align:left;}
label{display:block;font-weight:900;font-size:12px;opacity:0.9;margin-bottom:8px;}
input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,0.16);background:rgba(0,0,0,0.25);color:#fff;outline:none;}
.btn{border:none;padding:12px 18px;border-radius:12px;cursor:pointer;font-weight:900;letter-spacing:0.5px;font-size:14px;transition:transform 0.2s ease,opacity 0.2s ease,box-shadow 0.2s ease;text-decoration:none;display:inline-block;}
.btn:active{transform:scale(0.98);}
.btn-primary{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#0b1220;box-shadow:0 0 18px rgba(0,255,198,0.25);}
.btn-secondary{background:rgba(255,255,255,0.10);color:#fff;border:1px solid rgba(255,255,255,0.18);}
.btn-danger{background:rgba(244,16,16,0.22);color:#fff;border:1px solid rgba(244,16,16,0.35);}
.btn-primary:hover,.btn-secondary:hover,.btn-danger:hover{opacity:0.95;}

.section{margin-top:18px;padding:16px;border-radius:16px;background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.12);}
.section h3{font-size:16px;margin-bottom:10px;color:#00ffc6;letter-spacing:1px;text-align:center;}
.section .sub{margin-top:10px;font-size:13px;opacity:0.9;text-align:center;}
.section .meta{margin-top:10px;font-size:12px;opacity:0.85;text-align:center;}

.grid-btn{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px;}
.tile{border-radius:16px;padding:22px 16px;text-align:center;font-weight:900;cursor:pointer;transition:transform 0.2s ease,opacity 0.2s ease;user-select:none;}
.tile:hover{transform:translateY(-3px);opacity:0.96;}
.tile a{color:#fff;text-decoration:none;display:block;}
.t1{background:linear-gradient(90deg,#7a00ff,#9a00ff);}
.t2{background:linear-gradient(90deg,#00d4a6,#00ffcc);color:#0b1220;}
.t2 a{color:#0b1220;}
.t3{background:linear-gradient(90deg,#ff7a00,#ff9800);}
.t4{background:linear-gradient(90deg,#1e90ff,#1b6fd1);}
.t5{background:linear-gradient(90deg,#35b24a,#2f9c41);}
.t6{background:linear-gradient(90deg,#ffb300,#ffcc00);color:#0b1220;}
.t6 a{color:#0b1220;}
.t7{background:linear-gradient(90deg,#e91e63,#d81b60);}
.t8{background:linear-gradient(90deg,#607d8b,#546e7a);}

.actions{display:flex;gap:12px;justify-content:center;margin-top:18px;flex-wrap:wrap;}
.alert{margin-top:14px;padding:12px 14px;border-radius:14px;background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.12);font-size:13px;text-align:center;}

.menu-toggle{display:none;}
@media screen and (max-width:768px){
    body{flex-direction:column;}
    .sidebar{transform:translateX(-100%);transition:transform 0.3s ease;width:260px;}
    .sidebar.active{transform:translateX(0);}
    .main-content{margin-left:0;padding:20px;}
    .menu-toggle{display:block;position:fixed;top:20px;left:20px;background:rgba(255,0,0,0.8);color:white;border:none;padding:10px 15px;border-radius:8px;cursor:pointer;z-index:1001;font-size:1.2rem;}
    .grid-btn{grid-template-columns:repeat(2,1fr);}
}
@media screen and (max-width:480px){
    .grid-btn{grid-template-columns:1fr;}
    .card{padding:20px;}
}
.overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999;}
.overlay.active{display:block;}
.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}
@media(max-width:850px){.grid2{grid-template-columns:1fr;}}
.item{background:rgba(27,31,39,0.9);border:1px solid rgba(255,255,255,0.12);border-radius:18px;padding:14px;}
.item .name{font-weight:900;font-size:14px;margin-bottom:6px;}
.item .meta{font-size:12px;opacity:0.88;line-height:1.35;}

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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Equipo - RETAME'); } ?>

<button class="menu-toggle" onclick="toggleSidebar()">☰</button>
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
    <h1>👥 Equipo</h1>

    <div class="card">
        <div class="top">
            <div class="badge"><?php echo $badge; ?></div>
            <div class="info">
                <div class="name"><?php echo $NombreEquipo; ?></div>
                <div class="meta">ID: <?php echo $IdEquipoSafe; ?></div>
                <div class="meta">Usuario: <b><?php echo $NombreUser; ?></b></div>
                <div class="pill">Tu rol: <?php echo $rolTexto; ?></div>
            </div>
        </div>

        <div class="grid-btn">
            <div class="tile t1"><a href="infoequipo.php?id=<?php echo urlencode($id_equipo); ?>&view=equipo">Equipo</a></div>
            <div class="tile t2"><a href="infoequipo.php?id=<?php echo urlencode($id_equipo); ?>&view=integrantes">Integrantes</a></div>
            <div class="tile t3"><a href="infoequipo.php?id=<?php echo urlencode($id_equipo); ?>&view=calendario">Calendario</a></div>
            <div class="tile t5"><a href="infoequipo.php?id=<?php echo urlencode($id_equipo); ?>&view=estadisticas">Estadísticas</a></div>
            <div class="tile t7"><a href="#" onclick="return salirConfirm();">Salir del equipo</a></div>
        </div>

        <?php if ($view === 'equipo'): ?>
            <form method="POST" style="margin-top:16px;">
                <div class="row">
                    <div class="field">
                        <label>Número (único campo editable)</label>
                        <input type="number" name="numero" value="<?php echo (int)$numeroActual; ?>" min="0" max="999" required>
                    </div>
                    <div class="field" style="flex:0 0 180px;">
                        <input type="hidden" name="accion" value="guardar_numero">
                        <button class="btn btn-primary" type="submit" style="width:100%;">Guardar</button>
                    </div>
                </div>
            </form>

            <?php if ($msg !== ""): ?>
                <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <div class="section">
                <h3>Partido más cercano</h3>

                <?php if (!$partido): ?>
                    <div class="sub">Este equipo no tiene partidos agendados.</div>
                <?php else: ?>
                    <div class="sub">
                        <b><?php echo $localName; ?></b> vs <b><?php echo $visName; ?></b>
                    </div>
                    <div class="meta">
                        Fecha: <b><?php echo htmlspecialchars((string)$partido['fecha']); ?></b>
                        <?php if (!empty($partido['hora'])): ?>
                            • Hora: <b><?php echo htmlspecialchars((string)$partido['hora']); ?></b>
                        <?php endif; ?>
                        <?php if (!empty($partido['Jornada'])): ?>
                            • Jornada: <b><?php echo (int)$partido['Jornada']; ?></b>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($partido['Estado']) || !empty($partido['direccion'])): ?>
                        <div class="meta">
                            <?php if (!empty($partido['Estado'])): ?>
                                Estado: <b><?php echo htmlspecialchars((string)$partido['Estado']); ?></b>
                            <?php endif; ?>
                            <?php if (!empty($partido['direccion'])): ?>
                                • Dirección: <?php echo htmlspecialchars((string)$partido['direccion']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($partido['descripcion'])): ?>
                        <div class="meta" style="margin-top:8px;">
                            <?php echo htmlspecialchars((string)$partido['descripcion']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'integrantes'): ?>

            <div class="section">
                <h3>Integrantes</h3>

                <?php if (empty($integrantes)): ?>
                    <div class="sub">No hay integrantes registrados.</div>
                <?php else: ?>
                    <div class="grid2">
                        <?php foreach ($integrantes as $i):
                            $nom = htmlspecialchars($i['Nombre']);
                            $idJ = htmlspecialchars($i['Id_Jugador']);
                            $num = isset($i['numero']) ? (int)$i['numero'] : 0;
                            $t = strtolower(trim((string)$i['tipo']));
                            $t = str_replace("á", "a", $t);
                            $rol = ($t === 'capitan') ? 'Capitán' : 'Jugador';
                        ?>
                            <div class="item">
                                <div class="name"><?php echo $nom; ?></div>
                                <div class="meta">ID: <?php echo $idJ; ?> • Rol: <?php echo $rol; ?> • Número: <?php echo $num; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'calendario'): ?>

            <div class="section">
                <h3>Calendario</h3>

                <?php if (empty($partidos)): ?>
                    <div class="sub">Este equipo no tiene partidos registrados en calendario.</div>
                <?php else: ?>
                    <div class="grid2">
                        <?php foreach ($partidos as $p):
                            $idL = $p['id_equipolocal'];
                            $idV = $p['id_equipovicitante'];
                            $nL = isset($mapEq[$idL]) ? htmlspecialchars($mapEq[$idL]) : htmlspecialchars($idL);
                            $nV = isset($mapEq[$idV]) ? htmlspecialchars($mapEq[$idV]) : htmlspecialchars($idV);

                            $fecha = !empty($p['fecha']) ? htmlspecialchars((string)$p['fecha']) : '';
                            $hora = !empty($p['hora']) ? htmlspecialchars((string)$p['hora']) : '';
                            $jor = isset($p['Jornada']) ? (int)$p['Jornada'] : 0;
                            $est = !empty($p['Estado']) ? htmlspecialchars((string)$p['Estado']) : '';
                            $dir = !empty($p['direccion']) ? htmlspecialchars((string)$p['direccion']) : '';
                            $des = !empty($p['descripcion']) ? htmlspecialchars((string)$p['descripcion']) : '';
                            $gl = isset($p['Goles_local']) ? (int)$p['Goles_local'] : 0;
                            $gv = isset($p['Goles_visitante']) ? (int)$p['Goles_visitante'] : 0;
                            $rf = !empty($p['resultado_final']) ? htmlspecialchars((string)$p['resultado_final']) : '';
                        ?>
                            <div class="item">
                                <div class="name"><?php echo $nL; ?> vs <?php echo $nV; ?></div>
                                <div class="meta">
                                    <?php echo $fecha; ?><?php echo $hora ? " • $hora" : ""; ?>
                                    <?php echo $jor ? " • Jornada: $jor" : ""; ?>
                                    <?php echo $est ? " • Estado: $est" : ""; ?>
                                </div>
                                <div class="meta">
                                    Marcador: <?php echo $gl; ?> - <?php echo $gv; ?>
                                    <?php echo $rf ? " • Resultado: $rf" : ""; ?>
                                </div>
                                <?php if ($dir): ?><div class="meta">Dirección: <?php echo $dir; ?></div><?php endif; ?>
                                <?php if ($des): ?><div class="meta"><?php echo $des; ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="section">
                <h3>Estadísticas</h3>
                <div class="sub">Aquí irá el módulo de estadísticas (aún no implementado).</div>
            </div>

        <?php endif; ?>

        <div class="actions">
            <a class="btn btn-secondary" href="Mis_Equipos.php">Volver</a>
        </div>
    </div>

    <form id="formSalir" method="POST" style="display:none;">
        <input type="hidden" name="accion" value="salir_equipo">
    </form>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

function salirConfirm() {
    const msg =
`¿Seguro que quieres salir del equipo?

Consecuencias:
- Ya no aparecerás en la lista de integrantes.
- No podrás ver el calendario del equipo.
- No podrás participar en funciones del equipo (retas/estadísticas/etc. si aplica).
- Para volver, necesitarás volver a unirte según las reglas del sistema.`;

    if (confirm(msg)) {
        document.getElementById('formSalir').submit();
    }
    return false;
}
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>