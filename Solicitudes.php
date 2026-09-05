<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once 'conexion.php';

$usuario = $_SESSION['usuario_data'];
$Id_Retador = isset($usuario['Id_Retador']) ? $usuario['Id_Retador'] : '';

if ($Id_Retador == '') {
    header("Location: Perfil2.php");
    exit();
}

$Nombre = isset($usuario['Nombre']) ? $usuario['Nombre'] : 'Usuario';
$modoPerfilActual = '';
$modoOscuroActivo = false;
$FotoPerfilUsuario = 'assets/doctor.png';

function resolverFotoPerfilRetame($fotoBD) {
    $fotoBD = trim((string)$fotoBD);

    if ($fotoBD === '') {
        return 'assets/doctor.png';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $fotoBD)) {
        return $fotoBD;
    }

    $rutas = [
        $fotoBD,
        'Imagenes/' . $fotoBD,
        'uploads/' . $fotoBD,
        'FotosPerfil/' . $fotoBD,
        'assets/' . $fotoBD
    ];

    foreach ($rutas as $ruta) {
        if (file_exists($ruta)) {
            return $ruta;
        }
    }

    return $fotoBD;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($Id_Retador)) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró el usuario en sesión.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';

    $sqlModoUpdate = "UPDATE retador SET ModoPerfil = ? WHERE Id_Retador = ? LIMIT 1";
    $stmtModoUpdate = $conn->prepare($sqlModoUpdate);

    if (!$stmtModoUpdate) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo preparar la actualización del modo.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtModoUpdate->bind_param("ss", $nuevoModoPerfil, $Id_Retador);
    $okModo = $stmtModoUpdate->execute();
    $stmtModoUpdate->close();

    if ($okModo) {
        $_SESSION['usuario_data']['ModoPerfil'] = $nuevoModoPerfil;

        echo json_encode([
            'ok' => true,
            'modo' => $nuevoModoPerfil
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo actualizar el modo.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function esStaffDeEquipo($equipo, $idUsuario){
    if (!$equipo) return false;
    return (
        (isset($equipo['Capitan']) && $equipo['Capitan'] === $idUsuario) ||
        (isset($equipo['Entrenador']) && $equipo['Entrenador'] === $idUsuario) ||
        (isset($equipo['Asistente1']) && $equipo['Asistente1'] === $idUsuario) ||
        (isset($equipo['Asistente2']) && $equipo['Asistente2'] === $idUsuario) ||
        (isset($equipo['Asistente3']) && $equipo['Asistente3'] === $idUsuario) ||
        (isset($equipo['Asistente4']) && $equipo['Asistente4'] === $idUsuario)
    );
}

$msgOk = "";
$msgErr = "";

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



/* =======================
   ACEPTAR / RECHAZAR
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ((isset($_POST['aceptar_solicitud']) || isset($_POST['rechazar_solicitud'])) && isset($_POST['id_solicitud'])) {

        $id_solicitud = trim($_POST['id_solicitud']);

        $sqlGetSol = "SELECT id_solicitud, id_solicitante, id_areadesolicitud, estado
                      FROM solicitudes
                      WHERE id_solicitud = ?
                      LIMIT 1";
        $st = $conn->prepare($sqlGetSol);
        $st->bind_param("s", $id_solicitud);
        $st->execute();
        $rs = $st->get_result();
        $sol = $rs->fetch_assoc();
        $st->close();

        if (!$sol) {
            $msgErr = "La solicitud no existe.";
        } else {

            $id_equipo = $sol['id_areadesolicitud'];

            $sqlEquipo = "SELECT * FROM equipo WHERE Id_Equipo = ? LIMIT 1";
            $stE = $conn->prepare($sqlEquipo);
            $stE->bind_param("s", $id_equipo);
            $stE->execute();
            $rsE = $stE->get_result();
            $equipo = $rsE->fetch_assoc();
            $stE->close();

            if (!$equipo) {
                $msgErr = "El equipo de esta solicitud no existe.";
            } else {

                if (!esStaffDeEquipo($equipo, $Id_Retador)) {
                    $msgErr = "No tienes permisos para administrar solicitudes de este equipo.";
                } else {

                    $estadoSol = strtoupper(trim($sol['estado']));
                    if ($estadoSol !== 'PENDIENTE') {
                        $msgErr = "Esta solicitud ya fue atendida (Estado: " . esc($sol['estado']) . ").";
                    } else {

                        if (isset($_POST['aceptar_solicitud'])) {

                            $id_solicitante = $sol['id_solicitante'];

                            $sqlYaMiembro = "SELECT Id_EquipoJugador
                                             FROM equipo_jugador
                                             WHERE Id_Equipo = ? AND Id_Jugador = ?
                                             LIMIT 1";
                            $stM = $conn->prepare($sqlYaMiembro);
                            $stM->bind_param("ss", $id_equipo, $id_solicitante);
                            $stM->execute();
                            $stM->store_result();
                            $yaMiembro = ($stM->num_rows > 0);
                            $stM->close();

                            if ($yaMiembro) {
                                $msgErr = "El solicitante ya es miembro del equipo.";
                            } else {

                                $conn->begin_transaction();

                                try {
                                    // 1) Insertar en equipo_jugador
                                    $Id_EquipoJugador = 'EJ-' . uniqid() . '-' . time();
                                    $tipoJugador = 'Jugador';

                                    $sqlInsertEJ = "INSERT INTO equipo_jugador (Id_EquipoJugador, Id_Equipo, Id_Jugador, tipo)
                                                    VALUES (?, ?, ?, ?)";
                                    $stI = $conn->prepare($sqlInsertEJ);
                                    $stI->bind_param("ssss", $Id_EquipoJugador, $id_equipo, $id_solicitante, $tipoJugador);
                                    $stI->execute();
                                    $stI->close();

                                   

                                    $fecha_noti = date('Y-m-d');
$tipo_noti = 'Aceptacion Equipo';
$estado_noti = 'Ver';
$descripcion_noti = $id_solicitante . " se unio a tu equipo.";

$sqlIntegrantes = "SELECT Id_Jugador
                   FROM equipo_jugador
                   WHERE Id_Equipo = ?
                   AND Id_Jugador IS NOT NULL";
$stInt = $conn->prepare($sqlIntegrantes);
$stInt->bind_param("s", $id_equipo);
$stInt->execute();
$rsInt = $stInt->get_result();

$sqlNoti = "INSERT INTO notificaciones
            (Id_notificacion, id_retador, id_area, fecha, tipo, Estado, descripcion)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
$stN = $conn->prepare($sqlNoti);

while ($rowInt = $rsInt->fetch_assoc()) {
    $Id_notificacion = date('Ymd') . random_int(10000, 99999) . substr(uniqid(), -4);
    $id_retador_noti = $rowInt['Id_Jugador']; // destinatario = integrante del equipo
    $id_area_noti = $id_equipo;

    $stN->bind_param(
        "sssssss",
        $Id_notificacion,
        $id_retador_noti,
        $id_area_noti,
        $fecha_noti,
        $tipo_noti,
        $estado_noti,
        $descripcion_noti
    );
    $stN->execute();
}

$stN->close();
$stInt->close();

                                    // 3) Eliminar solicitud
                                    $sqlDelSol = "DELETE FROM solicitudes WHERE id_solicitud = ? LIMIT 1";
                                    $stD = $conn->prepare($sqlDelSol);
                                    $stD->bind_param("s", $id_solicitud);
                                    $stD->execute();
                                    $stD->close();

                                    $conn->commit();
                                    $msgOk = "Solicitud aceptada. El jugador fue agregado al equipo.";
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $msgErr = "Error al aceptar la solicitud.";
                                }
                            }

                        }

                        if (isset($_POST['rechazar_solicitud'])) {

                            $sqlDelSol = "DELETE FROM solicitudes WHERE id_solicitud = ? LIMIT 1";
                            $stD = $conn->prepare($sqlDelSol);
                            $stD->bind_param("s", $id_solicitud);
                            if ($stD->execute()) {
                                $msgOk = "Solicitud rechazada.";
                            } else {
                                $msgErr = "Error al rechazar la solicitud.";
                            }
                            $stD->close();

                        }
                    }
                }
            }
        }
    }
}

/* =======================
   LISTAR SOLICITUDES
======================= */
$solicitudes = [];

$sqlSolicitudes = "SELECT s.id_solicitud, s.id_solicitante, s.id_areadesolicitud, s.fecha, s.estado,
                          COALESCE(r.Nombre, s.id_solicitante) AS nombre_solicitante,
                          e.Nombre AS nombre_equipo,
                          e.Capitan, e.Entrenador, e.Asistente1, e.Asistente2, e.Asistente3, e.Asistente4
                   FROM solicitudes s
                   INNER JOIN equipo_jugador ej ON ej.Id_Equipo = s.id_areadesolicitud AND ej.Id_Jugador = ?
                   INNER JOIN equipo e ON e.Id_Equipo = s.id_areadesolicitud
                   LEFT JOIN retador r ON r.Id_Retador = s.id_solicitante
                   ORDER BY 
                        CASE WHEN UPPER(s.estado)='PENDIENTE' THEN 0 ELSE 1 END,
                        s.fecha DESC";
$stSolList = $conn->prepare($sqlSolicitudes);
$stSolList->bind_param("s", $Id_Retador);
$stSolList->execute();
$rsSolList = $stSolList->get_result();
while ($row = $rsSolList->fetch_assoc()) {
    if (esStaffDeEquipo($row, $Id_Retador)) {
        $solicitudes[] = $row;
    }
}
$stSolList->close();


$pendientes_count = 0;
foreach ($solicitudes as $solTmp) {
    if (strtoupper(trim((string)$solTmp['estado'])) === 'PENDIENTE') {
        $pendientes_count++;
    }
}

if (!empty($Id_Retador)) {
    $sqlPerfilVisual = "SELECT ModoPerfil, Fotoperfil AS FotoPerfil FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmtPerfilVisual = $conn->prepare($sqlPerfilVisual);

    if ($stmtPerfilVisual) {
        $stmtPerfilVisual->bind_param("s", $Id_Retador);
        $stmtPerfilVisual->execute();
        $resPerfilVisual = $stmtPerfilVisual->get_result();

        if ($filaPerfilVisual = $resPerfilVisual->fetch_assoc()) {
            $modoPerfilActual = isset($filaPerfilVisual['ModoPerfil']) ? trim((string)$filaPerfilVisual['ModoPerfil']) : '';
            $FotoPerfilUsuario = resolverFotoPerfilRetame($filaPerfilVisual['FotoPerfil'] ?? '');
            $_SESSION['usuario_data']['ModoPerfil'] = $modoPerfilActual;
        }

        $stmtPerfilVisual->close();
    }
}

$modoNormalizado = function_exists('mb_strtolower')
    ? mb_strtolower(trim((string)$modoPerfilActual), 'UTF-8')
    : strtolower(trim((string)$modoPerfilActual));
$modoOscuroActivo = ($modoNormalizado === 'modo oscuro');

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitudes - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

:root{
    --azul:#1877f2;
    --azul2:#0ea5e9;
    --cyan:#8fefff;
    --rojo:#ff4b5c;
    --rojo2:#ff2f45;
    --texto:#111827;
    --gris:#6b7280;
    --fondo:#f0f2f5;
    --blanco:#ffffff;
    --sidebar:280px;
    --sombra-azul:0 0 0 3px rgba(24,119,242,0.24), 0 12px 28px rgba(24,119,242,0.16);
    --sombra-roja:0 0 0 3px rgba(255,75,92,0.26), 0 12px 28px rgba(255,75,92,0.16);
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

html,body{
    width:100%;
    min-height:100%;
}

body{
    min-height:100dvh;
    font-family:'Poppins',sans-serif;
    color:var(--texto);
    background:var(--fondo);
    overflow-x:hidden;
    padding-bottom:104px;
}

.bg-particles{
    position:fixed;
    inset:0;
    z-index:0;
    pointer-events:none;
    background:
        radial-gradient(circle at 18% 20%,rgba(24,119,242,0.22),transparent 390px),
        radial-gradient(circle at 84% 22%,rgba(255,75,92,0.20),transparent 410px),
        radial-gradient(circle at 50% 70%,rgba(87,117,255,0.18),transparent 360px),
        linear-gradient(90deg,rgba(24,119,242,0.14) 0%,rgba(255,255,255,0.02) 48%,rgba(255,75,92,0.15) 100%),
        linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%);
}

.sidebar{
    width:var(--sidebar);
    height:100dvh;
    position:fixed;
    left:0;
    top:0;
    z-index:1000;
    padding:24px 16px 112px;
    background:rgba(255,255,255,0.97);
    backdrop-filter:blur(14px);
    border-right:3px solid var(--azul-neon-fuerte);
    box-shadow:
        8px 0 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(24,119,242,0.18),
        0 0 18px rgba(0,153,255,0.32),
        0 0 32px rgba(0,153,255,0.18);
    overflow-y:auto;
    transform:translateX(0);
    transition:transform 0.3s ease;
}

body.sidebar-hidden .sidebar{
    transform:translateX(-105%);
}

.logo-area{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:28px;
}

.doctor-logo{
    width:58px;
    height:58px;
    border-radius:50%;
    object-fit:cover;
    background:#fff;
    border:3px solid #fff;
    box-shadow:
        0 8px 18px rgba(24,119,242,0.14),
        0 0 0 2px rgba(24,119,242,0.12);
}

.logo-text h2{
    font-family:'Orbitron',sans-serif;
    font-size:19px;
    color:var(--azul);
    line-height:1;
}

.logo-text p{
    font-size:12px;
    color:var(--gris);
    margin-top:5px;
}

.menu{
    list-style:none;
    display:flex;
    flex-direction:column;
    gap:9px;
}

.menu li a{
    min-height:52px;
    display:flex;
    align-items:center;
    gap:13px;
    padding:12px 15px;
    border-radius:16px;
    text-decoration:none;
    color:#374151;
    font-size:14px;
    font-weight:800;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:3px solid rgba(255,75,92,0.34);
    box-shadow:
        0 8px 16px rgba(0,0,0,0.06),
        0 0 0 2px rgba(24,119,242,0.12);
    transition:0.25s ease;
}

.menu li a:hover{
    color:var(--rojo2);
    transform:translateX(4px);
    border-color:rgba(255,75,92,0.72);
    box-shadow:
        0 10px 18px rgba(0,0,0,0.08),
        0 0 0 3px rgba(24,119,242,0.16);
}

.menu li a.active{
    background:linear-gradient(180deg,#fff5f7,#ffffff);
    color:var(--rojo2);
    border:3px solid rgba(255,75,92,0.95);
    box-shadow:
        0 11px 22px rgba(255,75,92,0.16),
        0 0 0 3px rgba(24,119,242,0.20);
}

.menu-icon{
    width:28px;
    min-width:28px;
    height:28px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
}

.noti-alert-badge{
    margin-left:auto;
    width:20px;
    height:20px;
    border-radius:50%;
    background:#ef4444;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
}

.topbar{
    position:fixed;
    top:0;
    left:var(--sidebar);
    right:0;
    height:74px;
    z-index:900;
    display:flex;
    align-items:center;
    gap:16px;
    padding:12px 24px;
    background:rgba(255,255,255,0.94);
    backdrop-filter:blur(14px);
    border-bottom:3px solid var(--azul-neon-fuerte);
    box-shadow:
        0 4px 18px rgba(0,0,0,0.07),
        0 0 0 1px rgba(24,119,242,0.14),
        0 0 16px rgba(0,153,255,0.24);
    transition:left 0.3s ease, height 0.25s ease, padding 0.25s ease, box-shadow 0.25s ease;
}


body.topbar-compact .topbar{
    height:58px;
    padding:7px 22px;
    box-shadow:
        0 3px 14px rgba(0,0,0,0.08),
        0 0 0 1px rgba(24,119,242,0.16),
        0 0 18px rgba(0,153,255,0.24);
}

body.topbar-compact .menu-toggle{
    width:42px;
    height:42px;
    border-radius:14px;
}

body.topbar-compact .topbar-title h1{
    font-size:clamp(1rem,2.4vw,1.45rem);
}

body.topbar-compact .topbar-user{
    padding:7px 14px;
}

body.bottom-nav-hidden .bottom-nav{
    opacity:0;
    transform:translateY(115%);
    pointer-events:none;
}

body.sidebar-hidden .topbar{
    left:0;
}

.menu-toggle{
    width:50px;
    height:50px;
    border:3px solid rgba(0,153,255,0.50);
    border-radius:17px;
    background:#ffffff;
    color:#111827;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:
        0 8px 18px rgba(0,0,0,0.10),
        0 0 0 2px rgba(24,119,242,0.10),
        0 0 16px rgba(0,153,255,0.24),
        0 0 28px rgba(0,153,255,0.12);
    transition:0.25s ease;
}

.menu-toggle:hover{
    transform:translateY(-2px);
    color:var(--azul);
}

.menu-toggle span{
    width:25px;
    height:3px;
    background:#ffffff;
    position:relative;
    border-radius:999px;
    display:block;
    box-shadow:0 0 8px rgba(255,255,255,0.55);
}

.menu-toggle span::before,
.menu-toggle span::after{
    content:"";
    position:absolute;
    left:0;
    width:25px;
    height:3px;
    background:#ffffff;
    border-radius:999px;
    display:block;
    box-shadow:0 0 8px rgba(255,255,255,0.55);
}

.menu-toggle span::before{
    top:-8px;
}

.menu-toggle span::after{
    top:8px;
}

.topbar-title{
    flex:1;
    min-width:0;
}

.topbar-title h1{
    font-family:'Orbitron',sans-serif;
    font-size:clamp(1.25rem,3vw,2rem);
    color:var(--azul);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.topbar-user{
    max-width:330px;
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 18px;
    border-radius:999px;
    background:#ffffff;
    box-shadow:0 6px 15px rgba(0,0,0,0.08);
    font-weight:800;
    color:#374151;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.main-content{
    position:relative;
    z-index:2;
    min-height:100dvh;
    margin-left:var(--sidebar);
    padding:104px clamp(16px,4vw,42px) 122px;
    transition:margin-left 0.3s ease;
}

body.sidebar-hidden .main-content{
    margin-left:0;
}

.home-grid{
    max-width:1180px;
    margin:0 auto;
    display:grid;
    grid-template-columns:1.4fr 0.8fr;
    gap:24px;
}

.perfil-card{
    min-height:370px;
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    border:1px solid rgba(17,24,39,0.05);
    padding:clamp(28px,5vw,48px);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    overflow:hidden;
    position:relative;
}

.perfil-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,0.26),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,0.25),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,0.14),rgba(255,255,255,0.02) 46%,rgba(255,75,92,0.15));
}

.perfil-card h3,
.perfil-card p{
    position:relative;
    z-index:1;
}

.perfil-card h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.8rem,5vw,2.7rem);
    margin-bottom:15px;
}

.perfil-card p{
    max-width:650px;
    color:#4b5563;
    font-size:clamp(0.96rem,2.4vw,1.08rem);
    line-height:1.7;
}

.side-actions{
    display:grid;
    grid-template-columns:1fr;
    gap:20px;
}

.action-card{
    min-height:175px;
    border-radius:27px;
    background:rgba(255,255,255,0.96);
    border:2px solid rgba(0,153,255,0.34);
    box-shadow:
        0 14px 28px rgba(0,0,0,0.10),
        0 0 0 2px rgba(24,119,242,0.14),
        0 0 16px rgba(0,153,255,0.18);
    text-decoration:none;
    color:#111827;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    gap:10px;
    transition:0.25s ease;
    text-align:center;
    padding:22px;
}

.action-card:hover{
    transform:translateY(-4px);
    box-shadow:0 20px 38px rgba(0,0,0,0.15);
}

.action-card .big-icon{
    width:64px;
    height:64px;
    border-radius:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:30px;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    border:2px solid rgba(255,75,92,0.55);
    box-shadow:
        0 10px 20px rgba(255,75,92,0.22),
        0 0 0 2px rgba(24,119,242,0.14);
}

.action-card strong{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
}

.bottom-nav{
    position:fixed;
    left:var(--sidebar);
    right:0;
    bottom:0;
    width:auto;
    height:88px;
    z-index:1600;
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:0;
    padding:8px 18px;
    border-radius:22px 22px 0 0;
    background:#ffffff;
    border-top:2px solid rgba(0,153,255,0.72);
    box-shadow:
        0 -6px 18px rgba(0,0,0,0.06),
        0 0 16px rgba(0,153,255,0.14);
    backdrop-filter:blur(16px);
    transition:left 0.3s ease, opacity 0.25s ease, transform 0.25s ease;
}

body.sidebar-hidden .bottom-nav{
    left:0;
}

.bottom-nav a{
    position:relative;
    min-width:0;
    text-decoration:none;
    display:flex;
    align-items:center;
    justify-content:center;
    background:transparent;
    border:none;
    box-shadow:none;
    outline:none;
    transition:0.25s ease;
    overflow:visible;
    border-radius:16px;
}

.bottom-nav a img{
    width:clamp(34px,4vw,50px);
    height:clamp(34px,4vw,50px);
    object-fit:contain;
    display:block;
    transition:0.25s ease;
    filter:none;
    opacity:0.94;
    padding:3px;
    border-radius:14px;
    background:transparent;
}

.bottom-nav a::after{
    content:"";
    position:absolute;
    width:54px;
    height:54px;
    left:50%;
    top:50%;
    transform:translate(-50%,-50%);
    border-radius:15px;
    opacity:0;
    transition:0.25s ease;
    pointer-events:none;
}

.bottom-nav a:hover img{
    transform:scale(1.02);
    opacity:1;
    filter:none;
}

.bottom-nav a.active::after{
    opacity:1;
    border:2px solid rgba(255,75,92,0.98);
    box-shadow:
        0 0 0 2px rgba(0,153,255,0.98),
        0 0 12px rgba(0,153,255,0.25),
        0 0 8px rgba(255,75,92,0.18);
    background:transparent;
}

.bottom-nav a.active img{
    transform:scale(1.03);
    opacity:1;
    filter:none;
}



.bottom-nav a .bn-icon,
.bottom-nav a span{
    position:relative;
    z-index:1;
}



.bottom-nav a:hover{
    transform:none;
}


.bottom-nav a.active{
    background:transparent;
    border:none;
    box-shadow:none;
}

.bottom-noti{
    position:absolute;
    top:12px;
    right:calc(50% - 26px);
    width:16px;
    height:16px;
    border-radius:50%;
    background:#ff2f45;
    color:#fff;
    font-size:10px;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 0 0 2px #fff;
}

.toast-container{
    position:fixed;
    top:90px;
    right:18px;
    z-index:5000;
    display:flex;
    flex-direction:column;
    gap:10px;
    width:min(360px,calc(100vw - 36px));
    pointer-events:none;
}

.toast{
    pointer-events:none;
    background:rgba(255,255,255,0.98);
    box-shadow:0 12px 30px rgba(0,0,0,0.14);
    border:1px solid rgba(17,24,39,0.08);
    border-radius:18px;
    padding:14px;
    display:flex;
    gap:12px;
    animation:toastIn 240ms ease-out;
}

.toast .icon{
    width:40px;
    height:40px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eef4ff;
    color:var(--azul);
    font-size:19px;
}

.toast .content{
    flex:1;
    min-width:0;
}

.toast .title{
    font-weight:900;
    font-size:13px;
    color:var(--azul);
    margin-bottom:3px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.toast .msg{
    font-size:13px;
    color:#374151;
    line-height:1.35;
    display:-webkit-box;
    -webkit-line-clamp:3;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.toast .meta{
    margin-top:6px;
    font-size:11px;
    color:var(--gris);
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.mobile-overlay{
    display:none;
}

@keyframes toastIn{
    from{transform:translateY(-10px);opacity:0;}
    to{transform:translateY(0);opacity:1;}
}

@media screen and (max-width:1050px){
    .home-grid{
        grid-template-columns:1fr;
    }

    .side-actions{
        grid-template-columns:repeat(2,1fr);
    }
}

@media screen and (max-width:820px){
    .sidebar{
        transform:translateX(-105%);
    }

    body.sidebar-open .sidebar{
        transform:translateX(0);
    }

    body.sidebar-hidden .sidebar{
        transform:translateX(-105%);
    }

    .topbar,
    body.sidebar-hidden .topbar{
        left:0;
        height:68px;
        padding:10px 14px;
    }

    .bottom-nav,
    body.sidebar-hidden .bottom-nav{
        left:0;
        right:0;
        height:84px;
        border-radius:24px 24px 0 0;
        padding:8px 10px;
        gap:7px;
    }

    body.sidebar-open .bottom-nav{
        opacity:0;
        transform:translateY(110%);
        pointer-events:none;
    }

    .main-content,
    body.sidebar-hidden .main-content{
        margin-left:0;
        padding-top:94px;
    }


    body.topbar-compact .topbar{
        height:58px;
        padding:7px 12px;
    }


    .topbar-user{
        display:none;
    }

    .mobile-overlay{
        display:block;
        position:fixed;
        inset:0;
        z-index:950;
        background:rgba(17,24,39,0.28);
        opacity:0;
        visibility:hidden;
        transition:0.25s ease;
    }

    body.sidebar-open .mobile-overlay{
        opacity:1;
        visibility:visible;
    }

    .side-actions{
        grid-template-columns:1fr;
    }
}

@media screen and (max-width:560px){
    body{
        padding-bottom:92px;
    }

    .topbar-title h1{
        font-size:1rem;
    }

    .perfil-card{
        min-height:310px;
        border-radius:24px;
    }

    .bottom-nav,
    body.sidebar-hidden .bottom-nav{
        left:0;
        right:0;
        width:auto;
        height:76px;
        bottom:0;
        border-radius:18px 18px 0 0;
        gap:0;
        padding:6px 4px;
        border-top:2px solid rgba(0,153,255,0.72);
    }

    .bottom-nav a img{
        width:34px;
        height:34px;
    }

    .bottom-nav a::after{
        width:44px;
        height:44px;
        border-radius:13px;
    }

    .bottom-nav a{
        font-size:8.5px;
        border-radius:18px;
    }

    
    .toast-container{
        top:auto;
        bottom:100px;
        left:12px;
        right:12px;
        width:auto;
    }
}

.sidebar{
    padding:22px 14px 112px;
    background:rgba(255,255,255,0.98);
}

.sidebar-logo-boceto{
    margin-bottom:22px;
}

.sidebar-boceto{
    width:100%;
    display:flex;
    flex-direction:column;
    gap:16px;
}

.acciones-grid{
    width:100%;
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:12px;
}

.accion-boceto{
    min-height:95px;
    text-decoration:none;
    border-radius:20px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(255,75,92,0.62);
    box-shadow:
        0 8px 18px rgba(0,0,0,0.07),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 14px rgba(0,153,255,0.15);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    color:#374151;
    font-weight:900;
    font-size:12px;
    text-align:center;
    line-height:1.15;
    transition:0.25s ease;
    padding:10px 6px;
}

.accion-boceto img{
    width:38px;
    height:38px;
    object-fit:contain;
    display:block;
}

.accion-boceto:hover{
    transform:translateY(-3px);
    color:var(--rojo2);
    border-color:rgba(255,75,92,0.96);
    box-shadow:
        0 12px 22px rgba(0,0,0,0.10),
        0 0 0 3px rgba(0,153,255,0.26),
        0 0 18px rgba(0,153,255,0.20);
}

.cerrar-boceto{
    width:min(180px,100%);
    min-height:52px;
    margin:0 auto;
    text-decoration:none;
    border-radius:18px;
    background:linear-gradient(180deg,#fff7f8,#ffffff);
    border:2px solid rgba(255,75,92,0.82);
    box-shadow:
        0 8px 18px rgba(0,0,0,0.07),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 14px rgba(0,153,255,0.14);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    color:var(--rojo2);
    font-weight:900;
    font-size:12px;
    transition:0.25s ease;
}

.cerrar-boceto img{
    width:26px;
    height:26px;
    object-fit:contain;
}

.cerrar-boceto:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,0.98);
    box-shadow:
        0 12px 22px rgba(0,0,0,0.10),
        0 0 0 3px rgba(0,153,255,0.28),
        0 0 18px rgba(0,153,255,0.18);
}

.info-boceto{
    width:100%;
    display:flex;
    flex-direction:column;
    gap:9px;
    margin-top:4px;
}

.info-boceto a{
    min-height:46px;
    text-decoration:none;
    border-radius:16px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.56);
    box-shadow:
        0 6px 14px rgba(0,0,0,0.05),
        0 0 12px rgba(0,153,255,0.12);
    display:flex;
    align-items:center;
    padding:0 16px;
    color:#374151;
    font-weight:900;
    font-size:13px;
    transition:0.25s ease;
}

.info-boceto a:hover{
    color:var(--azul);
    transform:translateX(4px);
    border-color:rgba(0,153,255,0.96);
    box-shadow:
        0 8px 16px rgba(0,0,0,0.06),
        0 0 18px rgba(0,153,255,0.18);
}

@media screen and (max-width:820px){
    .sidebar{
        padding:20px 14px 108px;
    }

    .acciones-grid{
        gap:10px;
    }

    .accion-boceto{
        min-height:88px;
    }
}

@media screen and (max-width:360px){
    .acciones-grid{
        grid-template-columns:1fr;
    }

    .accion-boceto{
        min-height:74px;
    }
}


/* MODO OSCURO */
.modo-oscuro-panel{
    width:100%;
    min-height:58px;
    margin-top:4px;
    border-radius:18px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.60);
    box-shadow:
        0 8px 18px rgba(0,0,0,0.06),
        0 0 14px rgba(0,153,255,0.14);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
}

.modo-oscuro-texto{
    display:flex;
    align-items:center;
    gap:8px;
    color:#374151;
    font-size:13px;
    font-weight:900;
}

.modo-oscuro-texto span{
    font-size:18px;
}

.switch-modo{
    width:54px;
    height:30px;
    border:none;
    border-radius:999px;
    background:#e5e7eb;
    box-shadow:
        inset 0 2px 5px rgba(0,0,0,0.16),
        0 0 0 2px rgba(255,75,92,0.28),
        0 0 0 4px rgba(0,153,255,0.12);
    position:relative;
    cursor:pointer;
    transition:0.25s ease;
    flex:0 0 auto;
}

.switch-modo span{
    position:absolute;
    width:24px;
    height:24px;
    left:3px;
    top:3px;
    border-radius:50%;
    background:#ffffff;
    box-shadow:0 3px 8px rgba(0,0,0,0.25);
    transition:0.25s ease;
}

body.dark-mode{
    background:#0b1220;
    color:#e5e7eb;
}

body.dark-mode .bg-particles{
    background:
        radial-gradient(circle at 18% 20%,rgba(0,153,255,0.28),transparent 390px),
        radial-gradient(circle at 84% 22%,rgba(255,75,92,0.24),transparent 410px),
        radial-gradient(circle at 50% 70%,rgba(87,117,255,0.16),transparent 360px),
        linear-gradient(90deg,rgba(0,153,255,0.16) 0%,rgba(10,18,32,0.34) 48%,rgba(255,75,92,0.16) 100%),
        linear-gradient(135deg,#070b14 0%,#0b1220 48%,#160a12 100%);
}

body.dark-mode .sidebar,
body.dark-mode .topbar,
body.dark-mode .bottom-nav{
    background:rgba(12,18,31,0.96);
    color:#e5e7eb;
}

body.dark-mode .sidebar{
    border-right-color:rgba(0,153,255,0.95);
    box-shadow:
        8px 0 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.20),
        0 0 24px rgba(0,153,255,0.28);
}

body.dark-mode .topbar{
    border-bottom-color:rgba(0,153,255,0.95);
    box-shadow:
        0 5px 20px rgba(0,0,0,0.28),
        0 0 0 1px rgba(0,153,255,0.16),
        0 0 20px rgba(0,153,255,0.22);
}

body.dark-mode .bottom-nav{
    border-top-color:rgba(0,153,255,0.95);
    box-shadow:
        0 -8px 24px rgba(0,0,0,0.28),
        0 0 18px rgba(0,153,255,0.20);
}

body.dark-mode .logo-text h2,
body.dark-mode .topbar-title h1,
body.dark-mode .perfil-card h3,
body.dark-mode .action-card strong{
    color:#4db8ff;
}

body.dark-mode .logo-text p,
body.dark-mode .perfil-card p,
body.dark-mode .action-card span,
body.dark-mode .topbar-user,
body.dark-mode .modo-oscuro-texto,
body.dark-mode .info-boceto a,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto{
    color:#e5e7eb;
}

body.dark-mode .doctor-logo,
body.dark-mode .menu-toggle,
body.dark-mode .topbar-user,
body.dark-mode .perfil-card,
body.dark-mode .action-card,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel{
    background:#111827;
}

body.dark-mode .menu-toggle{
    color:#8fefff;
}

body.dark-mode .menu-toggle span,
body.dark-mode .menu-toggle span::before,
body.dark-mode .menu-toggle span::after{
    background:#ffffff;
    box-shadow:0 0 8px rgba(255,255,255,0.60);
}

body.dark-mode .perfil-card,
body.dark-mode .action-card,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel{
    border-color:rgba(255,75,92,0.72);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 18px rgba(0,153,255,0.16);
}

body.dark-mode .perfil-card::before{
    background:
        radial-gradient(circle at 14% 18%,rgba(0,153,255,0.28),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,0.24),transparent 320px),
        linear-gradient(90deg,rgba(0,153,255,0.16),rgba(17,24,39,0.10) 46%,rgba(255,75,92,0.16));
}

body.dark-mode .action-card .big-icon{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    box-shadow:
        0 10px 20px rgba(255,75,92,0.22),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 16px rgba(0,153,255,0.18);
}

body.dark-mode .bottom-nav a img,
body.dark-mode .accion-boceto img,
body.dark-mode .cerrar-boceto img{
    filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05);
}

body.dark-mode .bottom-nav a.active::after{
    border:2px solid rgba(255,75,92,0.98);
    box-shadow:
        0 0 0 2px rgba(0,153,255,0.98),
        0 0 13px rgba(0,153,255,0.30),
        0 0 9px rgba(255,75,92,0.22);
}

body.dark-mode .switch-modo{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    box-shadow:
        inset 0 2px 5px rgba(0,0,0,0.25),
        0 0 0 2px rgba(255,75,92,0.44),
        0 0 16px rgba(0,153,255,0.26);
}

body.dark-mode .switch-modo span{
    transform:translateX(24px);
    background:#ffffff;
}

body.dark-mode .mobile-overlay{
    background:rgba(0,0,0,0.48);
}


.switch-modo:disabled{
    opacity:0.65;
    cursor:not-allowed;
}


.perfil-sidebar-card{
    width:100%;
    min-height:74px;
    text-decoration:none;
    border-radius:22px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(255,75,92,0.72);
    box-shadow:
        0 8px 18px rgba(0,0,0,0.07),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 15px rgba(0,153,255,0.16);
    display:flex;
    align-items:center;
    gap:12px;
    padding:10px 12px;
    color:#374151;
    transition:0.25s ease;
}

.perfil-sidebar-card:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,0.98);
    box-shadow:
        0 12px 22px rgba(0,0,0,0.10),
        0 0 0 3px rgba(0,153,255,0.28),
        0 0 18px rgba(0,153,255,0.20);
}

.perfil-sidebar-foto{
    width:52px;
    height:52px;
    min-width:52px;
    border-radius:18px;
    padding:3px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.76);
    box-shadow:
        0 6px 14px rgba(0,0,0,0.09),
        0 0 0 2px rgba(255,75,92,0.18);
    overflow:hidden;
}

.perfil-sidebar-foto img{
    width:100%;
    height:100%;
    object-fit:cover;
    border-radius:14px;
    display:block;
}

.perfil-sidebar-info{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:2px;
    line-height:1.15;
}

.perfil-sidebar-info span{
    font-size:11px;
    font-weight:900;
    color:var(--rojo2);
}

.perfil-sidebar-info strong{
    max-width:140px;
    font-size:14px;
    font-weight:900;
    color:#374151;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

body.dark-mode .perfil-sidebar-card{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(255,75,92,0.78);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 18px rgba(0,153,255,0.18);
}

body.dark-mode .perfil-sidebar-foto{
    background:#0b1220;
    border-color:rgba(0,153,255,0.90);
}

body.dark-mode .perfil-sidebar-info strong{
    color:#e5e7eb;
}

body.dark-mode .perfil-sidebar-info span{
    color:#ff7b87;
}


.notificaciones-page{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:24px;
}

.noti-hero{
    min-height:250px;
    align-items:flex-start;
    text-align:left;
}

.noti-hero h3,
.noti-hero p,
.noti-kicker,
.noti-resumen{
    position:relative;
    z-index:1;
}

.noti-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:#ffffff;
    background:#101827;
    box-shadow:0 0 0 2px rgba(255,75,92,0.26),0 0 12px rgba(0,153,255,0.18);
    margin-bottom:14px;
}

.noti-resumen{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    width:100%;
    margin-top:24px;
}

.noti-stat{
    min-height:92px;
    border-radius:20px;
    background:rgba(255,255,255,0.96);
    border:2px solid rgba(0,153,255,0.28);
    box-shadow:
        0 10px 20px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.08);
    padding:15px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:5px;
}

.noti-stat span{
    color:#6b7280;
    font-size:11px;
    font-weight:900;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.noti-stat strong{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.25rem,4vw,1.85rem);
}

.noti-panel{
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    border:1px solid rgba(17,24,39,0.05);
    padding:clamp(18px,3vw,28px);
}

.noti-panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.noti-panel-title{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.55rem);
}

.noti-panel-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo2);
    background:#ffffff;
    border:2px solid rgba(255,75,92,0.35);
    box-shadow:0 0 0 2px rgba(0,153,255,0.12);
}

.alert{
    border-radius:18px;
    padding:15px 16px;
    background:#fff5f7;
    border:2px solid rgba(255,75,92,0.38);
    color:#b91c1c;
    font-weight:900;
    box-shadow:0 10px 22px rgba(255,75,92,0.10);
}

.noti-list{
    display:flex;
    flex-direction:column;
    gap:15px;
    max-height:620px;
    overflow-y:auto;
    padding:4px 8px 4px 2px;
}

.noti-list::-webkit-scrollbar{
    width:10px;
}

.noti-list::-webkit-scrollbar-track{
    background:rgba(24,119,242,0.08);
    border-radius:999px;
}

.noti-list::-webkit-scrollbar-thumb{
    background:linear-gradient(180deg,#ff4b5c,#1877f2);
    border-radius:999px;
}

.noti-item{
    border-radius:22px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(0,153,255,0.30);
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.08),
        0 0 15px rgba(0,153,255,0.10);
    padding:17px;
    transition:0.25s ease;
}

.noti-item:hover{
    transform:translateY(-3px);
    border-color:rgba(255,75,92,0.75);
    box-shadow:
        0 18px 32px rgba(0,0,0,0.12),
        0 0 0 3px rgba(0,153,255,0.18),
        0 0 20px rgba(255,75,92,0.13);
}

.noti-ver{
    border-color:rgba(255,75,92,0.84);
}

.noti-visto{
    border-color:rgba(0,153,255,0.34);
}

.noti-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
    flex-wrap:wrap;
}

.noti-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 11px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    color:#ffffff;
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    box-shadow:0 8px 16px rgba(24,119,242,0.18);
}

.noti-date{
    color:#6b7280;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

.noti-desc{
    color:#374151;
    line-height:1.55;
    font-size:14px;
    font-weight:600;
}

.noti-actions{
    display:flex;
    gap:10px;
    margin-top:14px;
    flex-wrap:wrap;
}

.btn{
    border:none;
    min-height:42px;
    padding:10px 15px;
    border-radius:15px;
    cursor:pointer;
    font-weight:900;
    font-size:13px;
    transition:0.25s ease;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.btn-primary{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    color:#ffffff;
    box-shadow:0 10px 18px rgba(24,119,242,0.18);
}

.btn-primary:hover{
    transform:translateY(-2px);
}

.noti-empty{
    min-height:120px;
    border-radius:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:24px;
    background:rgba(24,119,242,0.06);
    border:2px dashed rgba(0,153,255,0.28);
    color:#4b5563;
    font-weight:900;
}

body.dark-mode .noti-panel,
body.dark-mode .noti-stat,
body.dark-mode .noti-item,
body.dark-mode .noti-empty{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(255,75,92,0.78);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 18px rgba(0,153,255,0.18);
}

body.dark-mode .noti-panel-count,
body.dark-mode .noti-kicker{
    background:#0b1220;
    color:#e5e7eb;
}

body.dark-mode .noti-stat span,
body.dark-mode .noti-date,
body.dark-mode .noti-desc,
body.dark-mode .noti-empty{
    color:#d1d5db;
}

body.dark-mode .noti-stat strong,
body.dark-mode .noti-panel-title{
    color:#8fefff;
}

@media screen and (max-width:820px){
    .noti-resumen{
        grid-template-columns:1fr;
    }
}

@media screen and (max-width:620px){
    .noti-panel,
    .noti-hero{
        border-radius:24px;
    }

    .noti-top{
        align-items:flex-start;
    }
}



.solicitudes-page{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:22px;
}

.solicitudes-hero{
    text-align:left;
}

.solicitudes-panel{
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    border:1px solid rgba(17,24,39,0.05);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(18px,3vw,26px);
    overflow:hidden;
}

.solicitudes-panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.solicitudes-panel-title{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.55rem);
    font-weight:900;
}

.solicitudes-panel-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo2);
    background:#ffffff;
    border:2px solid rgba(255,75,92,0.35);
    box-shadow:0 0 0 2px rgba(0,153,255,0.12);
}

.solicitudes-table-wrap{
    width:100%;
    overflow:auto;
    border-radius:24px;
    border:2px solid rgba(0,153,255,0.24);
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.08);
}

.solicitudes-table{
    width:100%;
    min-width:850px;
    border-collapse:collapse;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
}

.solicitudes-table th{
    padding:15px 14px;
    text-align:left;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.45px;
    color:var(--azul);
    background:
        radial-gradient(circle at 10% 10%,rgba(24,119,242,0.14),transparent 170px),
        radial-gradient(circle at 90% 90%,rgba(255,75,92,0.13),transparent 170px),
        #ffffff;
    border-bottom:2px solid rgba(0,153,255,0.16);
}

.solicitudes-table td{
    padding:15px 14px;
    border-bottom:1px solid rgba(17,24,39,0.07);
    color:#374151;
    font-size:13px;
    vertical-align:top;
}

.solicitudes-table tr:last-child td{
    border-bottom:0;
}

.solicitud-main-text{
    font-weight:900;
    color:#111827;
    margin-bottom:4px;
}

.solicitud-small{
    font-size:12px;
    color:#6b7280;
    font-weight:700;
    margin-top:3px;
}

.solicitud-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 11px;
    border-radius:999px;
    background:rgba(24,119,242,0.10);
    color:#1d4ed8;
    border:1px solid rgba(24,119,242,0.22);
    font-weight:900;
    font-size:11px;
}

.solicitud-actions{
    display:flex;
    gap:9px;
    align-items:center;
    flex-wrap:wrap;
}

.solicitud-actions form{
    display:inline-flex;
}

.solicitud-btn{
    min-height:42px;
    border:0;
    text-decoration:none;
    border-radius:15px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 14px;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:0.25s ease;
}

.solicitud-btn:hover{
    transform:translateY(-2px);
}

.solicitud-btn-aceptar{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    color:#fff;
    box-shadow:0 10px 18px rgba(24,119,242,0.18);
}

.solicitud-btn-rechazar{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    color:#fff;
    box-shadow:0 10px 18px rgba(255,75,92,0.20);
}

.solicitud-foot-note{
    margin-top:14px;
    display:flex;
    justify-content:center;
}

.solicitud-foot-note span{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo2);
    background:#ffffff;
    border:2px solid rgba(255,75,92,0.35);
    box-shadow:0 0 0 2px rgba(0,153,255,0.12);
}

.solicitudes-empty{
    min-height:120px;
    border-radius:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:24px;
    background:rgba(24,119,242,0.06);
    border:2px dashed rgba(0,153,255,0.28);
    color:#4b5563;
    font-weight:900;
}

body.dark-mode .solicitudes-panel,
body.dark-mode .solicitudes-table,
body.dark-mode .solicitudes-table th,
body.dark-mode .solicitudes-panel-count,
body.dark-mode .solicitud-foot-note span{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(255,75,92,0.78);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 18px rgba(0,153,255,0.18);
}

body.dark-mode .solicitudes-panel-title,
body.dark-mode .solicitudes-table th{
    color:#8fefff;
}

body.dark-mode .solicitudes-table td,
body.dark-mode .solicitud-small,
body.dark-mode .solicitudes-empty{
    color:#d1d5db;
}

body.dark-mode .solicitud-main-text{
    color:#f9fafb;
}

body.dark-mode .solicitudes-table td{
    border-bottom-color:rgba(255,255,255,0.08);
}

@media screen and (max-width:620px){
    .solicitudes-panel{
        border-radius:24px;
        padding:16px;
    }

    .solicitudes-table{
        min-width:760px;
    }
}

</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area sidebar-logo-boceto">
        <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <a href="MiPerfil.php" class="perfil-sidebar-card">
            <div class="perfil-sidebar-foto">
                <img src="<?php echo esc($FotoPerfilUsuario); ?>" alt="Foto de perfil">
            </div>

            <div class="perfil-sidebar-info">
                <span>Perfil</span>
                <strong><?php echo esc($Nombre); ?></strong>
            </div>
        </a>

        <div class="acciones-grid">
            <a href="Equipo/UnirmeOtroEquipo.php" class="accion-boceto">
                <img src="Imagenes/ImgUnion.png" alt="">
                <span>Unirme equipo</span>
            </a>

            <a href="Equipo/CrearEquipo.php" class="accion-boceto">
                <img src="Imagenes/ImgCreacion.png" alt="">
                <span>Crear equipo</span>
            </a>

            <a href="Solicitudes.php" class="accion-boceto active">
                <img src="Imagenes/ImgSolicitud.png" alt="">
                <span>Solicitud</span>
            </a>

            <a href="Equipo/Mis_Equipos.php" class="accion-boceto">
                <img src="Imagenes/ImgEquipo.png" alt="">
                <span>Equipo</span>
            </a>

            <a href="Ligas/liga.php" class="accion-boceto">
                <img src="Imagenes/ImgLigas.png" alt="">
                <span>Ligas</span>
            </a>

            <a href="Retar/retar.php" class="accion-boceto">
                <img src="Imagenes/ImgReta.png" alt="">
                <span>Retar</span>
            </a>

            <a href="Canchas/Canchas.php" class="accion-boceto">
                <img src="Imagenes/ImgCanchas.png" alt="">
                <span>Canchas</span>
            </a>

            <a href="Amigos.php" class="accion-boceto">
                <img src="Imagenes/ImgAmigos.png" alt="">
                <span>Amigos</span>
            </a>
        </div>

        <a href="login.php" class="cerrar-boceto">
            <img src="Imagenes/ImgCerrar.png" alt="">
            <span>Cerrar sesión</span>
        </a>

        <div class="info-boceto">
            <a href="Informacion.php">Información</a>
            <a href="AcercaDe.php">Acerca de</a>
            <a href="SoporteTecnico.php">Soporte técnico</a>
        </div>

        <div class="modo-oscuro-panel">
            <div class="modo-oscuro-texto">
                <span>🌙</span>
                <strong>Modo oscuro</strong>
            </div>

            <button type="button" class="switch-modo" id="darkModeToggle" aria-label="Activar modo oscuro">
                <span></span>
            </button>
        </div>
    </div>
</aside>

<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú">
        <span></span>
    </button>

    <div class="topbar-title">
        <h1>Solicitudes de <?php echo esc($Nombre); ?></h1>
    </div>

    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo esc($Nombre); ?></span>
    </div>
</header>

<main class="main-content">
    <div class="solicitudes-page">
        <section class="perfil-card solicitudes-hero">
            <span class="noti-kicker">📩 Administración de solicitudes</span>
            <h3>SOLICITUDES</h3>
            <p>Revisa las solicitudes para unirse a tus equipos y acepta o rechaza únicamente aquellas donde tienes permisos de staff.</p>

            <div class="noti-resumen">
                <div class="noti-stat">
                    <span>Total</span>
                    <strong><?php echo count($solicitudes); ?></strong>
                </div>

                <div class="noti-stat">
                    <span>Pendientes</span>
                    <strong><?php echo (int)$pendientes_count; ?></strong>
                </div>

                <div class="noti-stat">
                    <span>Usuario</span>
                    <strong><?php echo esc($Nombre); ?></strong>
                </div>
            </div>
        </section>

        <?php if ($msgErr !== ''): ?>
            <div class="alert"><strong>⚠️ Error:</strong> <?php echo esc($msgErr); ?></div>
        <?php endif; ?>

        <?php if ($msgOk !== ''): ?>
            <div class="alert ok"><strong>✅ Listo:</strong> <?php echo esc($msgOk); ?></div>
        <?php endif; ?>

        <section class="solicitudes-panel">
            <div class="solicitudes-panel-header">
                <div class="solicitudes-panel-title">📋 Solicitudes de equipos</div>
                <div class="solicitudes-panel-count"><?php echo count($solicitudes); ?> solicitudes</div>
            </div>

            <?php if (empty($solicitudes)): ?>
                <div class="solicitudes-empty">No tienes solicitudes pendientes para administrar.</div>
            <?php else: ?>
                <div class="solicitudes-table-wrap">
                    <table class="solicitudes-table">
                        <thead>
                            <tr>
                                <th>Equipo</th>
                                <th>Solicitante</th>
                                <th>ID Solicitante</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitudes as $s): ?>
                                <?php
                                    $estadoSol = strtoupper(trim($s['estado']));
                                    $isPend = ($estadoSol === 'PENDIENTE');
                                ?>
                                <tr>
                                    <td>
                                        <div class="solicitud-main-text"><?php echo esc($s['nombre_equipo']); ?></div>
                                        <div class="solicitud-small">ID Equipo: <?php echo esc($s['id_areadesolicitud']); ?></div>
                                        <div class="solicitud-small">Solicitud: <?php echo esc($s['id_solicitud']); ?></div>
                                    </td>

                                    <td>
                                        <div class="solicitud-main-text"><?php echo esc($s['nombre_solicitante']); ?></div>
                                    </td>

                                    <td><?php echo esc($s['id_solicitante']); ?></td>
                                    <td><?php echo esc($s['fecha']); ?></td>
                                    <td><span class="solicitud-badge"><?php echo esc($s['estado']); ?></span></td>
                                    <td>
                                        <?php if ($isPend): ?>
                                            <div class="solicitud-actions">
                                                <form method="POST">
                                                    <input type="hidden" name="id_solicitud" value="<?php echo esc($s['id_solicitud']); ?>">
                                                    <button type="submit" name="aceptar_solicitud" class="solicitud-btn solicitud-btn-aceptar" onclick="return confirm('¿Aceptar solicitud y agregar al jugador al equipo?');">Aceptar</button>
                                                </form>

                                                <form method="POST">
                                                    <input type="hidden" name="id_solicitud" value="<?php echo esc($s['id_solicitud']); ?>">
                                                    <button type="submit" name="rechazar_solicitud" class="solicitud-btn solicitud-btn-rechazar" onclick="return confirm('¿Rechazar solicitud?');">Rechazar</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="solicitud-small">Sin acciones</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="solicitud-foot-note">
                    <span>Solo ves solicitudes de tus equipos donde eres staff</span>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<nav class="bottom-nav">
    <a href="Perfil2.php" aria-label="Inicio">
        <img src="Imagenes/ImgInicio.png" alt="">
    </a>

    <a href="Retar/retar.php" aria-label="Retar">
        <img src="Imagenes/ImgReta.png" alt="">
    </a>

    <a href="Ligas/liga.php" aria-label="Ligas">
        <img src="Imagenes/ImgLigas.png" alt="">
    </a>

    <a href="Agenda.php" aria-label="Agenda">
        <img src="Imagenes/ImgAgenda.png" alt="">
    </a>

    <a href="Notificaciones.php" aria-label="Notificaciones">
        <img src="Imagenes/ImgNoti.png" alt="">
        <?php if ($nuevas_count > 0): ?>
            <span class="bottom-noti">!</span>
        <?php endif; ?>
    </a>

    <a href="MiPerfil.php" aria-label="Perfil">
        <img src="Imagenes/ImgPerfil.png" alt="">
    </a>
</nav>

<script>
const body = document.body;
const menuToggle = document.getElementById('menuToggle');
const mobileOverlay = document.getElementById('mobileOverlay');
const darkModeToggle = document.getElementById('darkModeToggle');
let lastScrollY = window.scrollY;

function closeSidebar(){
    body.classList.remove('sidebar-open');
}

if(menuToggle){
    menuToggle.addEventListener('click', () => {
        if(window.innerWidth <= 820){
            body.classList.toggle('sidebar-open');
        }else{
            body.classList.toggle('sidebar-hidden');
        }
    });
}

if(mobileOverlay){
    mobileOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', () => {
    if(window.innerWidth > 820){
        body.classList.remove('sidebar-open');
    }
});

window.addEventListener('scroll', () => {
    const currentY = window.scrollY;

    if(currentY > 20){
        body.classList.add('topbar-compact');
    }else{
        body.classList.remove('topbar-compact');
    }

    if(currentY > lastScrollY && currentY > 160){
        body.classList.add('bottom-nav-hidden');
    }else{
        body.classList.remove('bottom-nav-hidden');
    }

    lastScrollY = currentY;
}, { passive:true });

if(darkModeToggle){
    darkModeToggle.addEventListener('click', () => {
        const activarOscuro = !body.classList.contains('dark-mode');
        body.classList.toggle('dark-mode', activarOscuro);

        const datos = new FormData();
        datos.append('accion', 'actualizar_modo_perfil');
        datos.append('modo', activarOscuro ? 'oscuro' : 'predeterminado');

        fetch(window.location.href, {
            method:'POST',
            body:datos,
            credentials:'same-origin'
        }).catch(() => {});
    });
}
</script>

</body>
</html>
