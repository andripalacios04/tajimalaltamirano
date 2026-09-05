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

if (!isset($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuario = $_SESSION['usuario_data'];
$Id_Retador = isset($usuario['Id_Retador']) ? $usuario['Id_Retador'] : '';

$id_equipo = isset($_GET['id']) ? $_GET['id'] : '';
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'equipo';

if ($id_equipo == '' || $Id_Retador == '') {
    header("Location: ../Mis_Equipos.php");
    exit();
}

$sqlEquipo = "SELECT * FROM equipo WHERE Id_Equipo = ?";
$stmtEquipo = $conn->prepare($sqlEquipo);
$stmtEquipo->bind_param("s", $id_equipo);
$stmtEquipo->execute();
$resultEquipo = $stmtEquipo->get_result();
$equipo = $resultEquipo->fetch_assoc();
$stmtEquipo->close();

if (!$equipo) {
    header("Location: ../Mis_Equipos.php");
    exit();
}

$sqlRol = "SELECT tipo
           FROM equipo_jugador
           WHERE Id_Equipo = ? AND Id_Jugador = ?
           LIMIT 1";
$stmtRol = $conn->prepare($sqlRol);
$stmtRol->bind_param("ss", $id_equipo, $Id_Retador);
$stmtRol->execute();
$resRol = $stmtRol->get_result();
$rolRow = $resRol ? $resRol->fetch_assoc() : null;
$stmtRol->close();

if (!$rolRow) {
    header("Location: ../Mis_Equipos.php");
    exit();
}

$rol = strtolower(trim($rolRow['tipo']));
$rol = str_replace(["á","é","í","ó","ú"], ["a","e","i","o","u"], $rol);

if ($rol !== 'capitan') {
    header("Location: InfoEquipo.php?id=" . urlencode($id_equipo));
    exit();
}

$jugadores = [];
$sqlJugadoresSelect = "SELECT ej.Id_Jugador, COALESCE(r.Nombre, ej.Id_Jugador) AS Nombre
                       FROM equipo_jugador ej
                       LEFT JOIN retador r ON r.Id_Retador = ej.Id_Jugador
                       WHERE ej.Id_Equipo = ? AND ej.Id_Jugador != ?
                       ORDER BY Nombre ASC";
$stmtJS = $conn->prepare($sqlJugadoresSelect);
$stmtJS->bind_param("ss", $id_equipo, $equipo['Capitan']);
$stmtJS->execute();
$resJS = $stmtJS->get_result();
while ($row = $resJS->fetch_assoc()) {
    $jugadores[] = $row;
}
$stmtJS->close();

$idsValidos = [];
foreach ($jugadores as $j) $idsValidos[$j['Id_Jugador']] = true;

$msgOk = "";
$msgErr = "";

/* =======================
   GUARDAR EQUIPO
======================= */
if (isset($_POST['guardar_equipo'])) {
    $NombreEquipo = isset($_POST['Nombre']) ? trim($_POST['Nombre']) : '';
    $Pais = isset($_POST['Pais']) ? trim($_POST['Pais']) : '';
    $CodigoPostal = isset($_POST['CodigoPostal']) ? trim($_POST['CodigoPostal']) : '';
    $cantidad = isset($_POST['cantidad']) ? trim($_POST['cantidad']) : '';

    $Entrenador = isset($_POST['Entrenador']) ? trim($_POST['Entrenador']) : '';
    $Asistente1 = isset($_POST['Asistente1']) ? trim($_POST['Asistente1']) : '';
    $Asistente2 = isset($_POST['Asistente2']) ? trim($_POST['Asistente2']) : '';
    $Asistente3 = isset($_POST['Asistente3']) ? trim($_POST['Asistente3']) : '';
    $Asistente4 = isset($_POST['Asistente4']) ? trim($_POST['Asistente4']) : '';

    $lista = [$Entrenador, $Asistente1, $Asistente2, $Asistente3, $Asistente4];
    foreach ($lista as $val) {
        if ($val !== '' && !isset($idsValidos[$val])) {
            $msgErr = "Solo puedes seleccionar entrenador/asistentes que pertenezcan a tu equipo (sin el capitán).";
            break;
        }
    }

    if ($msgErr === '') {
        if ($cantidad !== '' && !ctype_digit($cantidad)) {
            $msgErr = "La cantidad debe ser un número entero.";
        }
    }

    if ($msgErr === '') {
        $sqlUpdateEquipo = "UPDATE equipo
                            SET Nombre = ?, Pais = ?, CodigoPostal = ?, cantidad = ?,
                                Entrenador = ?, Asistente1 = ?, Asistente2 = ?, Asistente3 = ?, Asistente4 = ?
                            WHERE Id_Equipo = ?";
        $stmtUE = $conn->prepare($sqlUpdateEquipo);
        $cantInt = ($cantidad === '') ? null : intval($cantidad);

        $stmtUE->bind_param(
            "sssissssss",
            $NombreEquipo, $Pais, $CodigoPostal, $cantInt,
            $Entrenador, $Asistente1, $Asistente2, $Asistente3, $Asistente4,
            $id_equipo
        );
        $stmtUE->execute();
        $stmtUE->close();

        $msgOk = "Datos del equipo actualizados.";

        $stmtEquipo = $conn->prepare($sqlEquipo);
        $stmtEquipo->bind_param("s", $id_equipo);
        $stmtEquipo->execute();
        $resultEquipo = $stmtEquipo->get_result();
        $equipo = $resultEquipo->fetch_assoc();
        $stmtEquipo->close();

        $tab = "equipo";
    }
}

/* =======================
   ACTUALIZAR NUMERO
======================= */
if (isset($_POST['actualizar_numero'])) {
    $id_equipoJugador = isset($_POST['id_equipoJugador']) ? $_POST['id_equipoJugador'] : '';
    $numero = isset($_POST['numero']) ? $_POST['numero'] : '';

    if ($id_equipoJugador !== '' && $numero !== '' && ctype_digit((string)$numero)) {
        $sqlUpdate = "UPDATE equipo_jugador 
                      SET numero = ? 
                      WHERE Id_EquipoJugador = ? 
                      AND Id_Equipo = ? 
                      AND Id_Jugador != ?";
        $stmt = $conn->prepare($sqlUpdate);
        $numInt = intval($numero);
        $stmt->bind_param("isss", $numInt, $id_equipoJugador, $id_equipo, $equipo['Capitan']);
        $stmt->execute();
        $stmt->close();
        $msgOk = "Número actualizado.";
        $tab = "retadores";
    }
}

/* =======================
   ELIMINAR JUGADOR
======================= */
if (isset($_POST['eliminar_jugador'])) {
    $id_equipoJugador = isset($_POST['id_equipoJugador']) ? $_POST['id_equipoJugador'] : '';

    if ($id_equipoJugador !== '') {
        $sqlDelete = "DELETE FROM equipo_jugador 
                      WHERE Id_EquipoJugador = ? 
                      AND Id_Equipo = ?
                      AND Id_Jugador != ?";
        $stmt = $conn->prepare($sqlDelete);
        $stmt->bind_param("sss", $id_equipoJugador, $id_equipo, $equipo['Capitan']);
        $stmt->execute();
        $stmt->close();
        $msgOk = "Jugador eliminado del equipo.";
        $tab = "retadores";
    }
}

function esc($v){ return htmlspecialchars((string)$v); }

/* =======================
   SOLICITUDES (VER / ACEPTAR / RECHAZAR)
======================= */
date_default_timezone_set('America/Mexico_City');

if ($tab === 'solicitudes') {

    if (isset($_POST['aceptar_solicitud']) && isset($_POST['id_solicitud'])) {
        $id_solicitud = trim($_POST['id_solicitud']);

        $sqlGetSol = "SELECT id_solicitud, id_solicitante, id_areadesolicitud, estado
                      FROM solicitudes
                      WHERE id_solicitud = ? AND id_areadesolicitud = ?
                      LIMIT 1";
        $st = $conn->prepare($sqlGetSol);
        $st->bind_param("ss", $id_solicitud, $id_equipo);
        $st->execute();
        $rs = $st->get_result();
        $sol = $rs->fetch_assoc();
        $st->close();

        if (!$sol) {
            $msgErr = "La solicitud no existe o no pertenece a este equipo.";
        } else {
            $estadoSol = strtoupper(trim($sol['estado']));
            if ($estadoSol !== 'PENDIENTE') {
                $msgErr = "Esta solicitud ya fue atendida (Estado: " . esc($sol['estado']) . ").";
            } else {
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

                    $sqlCap = "SELECT cantidad FROM equipo WHERE Id_Equipo = ? LIMIT 1";
                    $stC = $conn->prepare($sqlCap);
                    $stC->bind_param("s", $id_equipo);
                    $stC->execute();
                    $rsC = $stC->get_result();
                    $capRow = $rsC->fetch_assoc();
                    $stC->close();

                    $capacidadEquipo = $capRow ? intval($capRow['cantidad']) : 0;

                    $sqlCount = "SELECT COUNT(*) AS total FROM equipo_jugador WHERE Id_Equipo = ?";
                    $stK = $conn->prepare($sqlCount);
                    $stK->bind_param("s", $id_equipo);
                    $stK->execute();
                    $rsK = $stK->get_result();
                    $countRow = $rsK->fetch_assoc();
                    $stK->close();

                    $jugadoresActuales = $countRow ? intval($countRow['total']) : 0;

                    if ($capacidadEquipo > 0 && $jugadoresActuales >= $capacidadEquipo) {
                        $msgErr = "No se puede aceptar: el equipo está lleno. (" . $jugadoresActuales . "/" . $capacidadEquipo . ")";
                    } else {
                        $conn->begin_transaction();

                        try {
                            // 1) Insert EJ
                            $Id_EquipoJugador = 'EJ-' . uniqid() . '-' . time();
                            $tipo = 'Jugador';

                            $sqlInsertEJ = "INSERT INTO equipo_jugador (Id_EquipoJugador, Id_Equipo, Id_Jugador, tipo)
                                            VALUES (?, ?, ?, ?)";
                            $stI = $conn->prepare($sqlInsertEJ);
                            $stI->bind_param("ssss", $Id_EquipoJugador, $id_equipo, $id_solicitante, $tipo);
                            $stI->execute();
                            $stI->close();

                            // 2) Update retador
                            $sqlUpdRet = "UPDATE retador SET estado_equipo = 1 WHERE Id_Retador = ?";
                            $stR = $conn->prepare($sqlUpdRet);
                            $stR->bind_param("s", $id_solicitante);
                            $stR->execute();
                            $stR->close();

                            // 3) Update solicitud
                            $nuevoEstado = 'ACEPTADA';
                            $sqlUpdSol = "UPDATE solicitudes SET estado = ? WHERE id_solicitud = ? AND id_areadesolicitud = ?";
                            $stS = $conn->prepare($sqlUpdSol);
                            $stS->bind_param("sss", $nuevoEstado, $id_solicitud, $id_equipo);
                            $stS->execute();
                            $stS->close();

                           // 4) INSERT NOTIFICACIONES A TODO EL EQUIPO (NUEVA LOGICA)
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
                              // 4.1) INSERT NOTIFICACION EXTRA (PARA EL SOLICITANTE)
$Id_notificacion2 = date('Ymd') . random_int(10000, 99999);
$id_retador_noti2 = $id_solicitante;    // DESTINATARIO: quien envió la solicitud
$id_area_noti2 = $id_equipo;            // mismo equipo
$fecha_noti2 = date('Y-m-d');
$tipo_noti2 = 'Aceptacion Equipo';
$estado_noti2 = 'Ver';
$descripcion_noti2 = $equipo['Nombre'] . " acepto tu solicitud";

$sqlNoti2 = "INSERT INTO notificaciones
             (Id_notificacion, id_retador, id_area, fecha, tipo, Estado, descripcion)
             VALUES (?, ?, ?, ?, ?, ?, ?)";
$stN2 = $conn->prepare($sqlNoti2);
$stN2->bind_param(
    "sssssss",
    $Id_notificacion2,
    $id_retador_noti2,
    $id_area_noti2,
    $fecha_noti2,
    $tipo_noti2,
    $estado_noti2,
    $descripcion_noti2
);
$stN2->execute();
$stN2->close();
                            $conn->commit();
                            $msgOk = "Solicitud aceptada. El jugador fue agregado al equipo.";
                            $tab = "solicitudes";
                        } catch (Exception $e) {
                            $conn->rollback();
                            $msgErr = "Error al aceptar la solicitud.";
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['rechazar_solicitud']) && isset($_POST['id_solicitud'])) {
        $id_solicitud = trim($_POST['id_solicitud']);

        $sqlGetSol = "SELECT id_solicitud, estado
                      FROM solicitudes
                      WHERE id_solicitud = ? AND id_areadesolicitud = ?
                      LIMIT 1";
        $st = $conn->prepare($sqlGetSol);
        $st->bind_param("ss", $id_solicitud, $id_equipo);
        $st->execute();
        $rs = $st->get_result();
        $sol = $rs->fetch_assoc();
        $st->close();

        if (!$sol) {
            $msgErr = "La solicitud no existe o no pertenece a este equipo.";
        } else {
            $estadoSol = strtoupper(trim($sol['estado']));
            if ($estadoSol !== 'PENDIENTE') {
                $msgErr = "Esta solicitud ya fue atendida (Estado: " . esc($sol['estado']) . ").";
            } else {
                $nuevoEstado = 'RECHAZADA';
                $sqlUpdSol = "UPDATE solicitudes SET estado = ? WHERE id_solicitud = ? AND id_areadesolicitud = ?";
                $stS = $conn->prepare($sqlUpdSol);
                $stS->bind_param("sss", $nuevoEstado, $id_solicitud, $id_equipo);
                $stS->execute();
                $stS->close();

                $msgOk = "Solicitud rechazada.";
                $tab = "solicitudes";
            }
        }
    }
}

/* =======================
   CALENDARIO
======================= */
$now = new DateTime('now');
$nowStr = $now->format('Y-m-d H:i:s');

$partidos = [];
$nextMatchId = null;

if ($tab === 'calendario') {
    $sqlPartidos = "SELECT c.Id_Partido, c.id_equipolocal, c.id_equipovicitante, c.id_deporte, c.fecha, c.hora, c.Jornada, c.Estado,
                           c.direccion, c.codigo_postal, c.descripcion, c.id_liga, c.Goles_local, c.Goles_visitante, c.resultado_final,
                           c.fecha_creacion, c.fecha_actualizacion, c.Grupo, c.Tiempo_Jugado,
                           el.Nombre AS nombre_local, ev.Nombre AS nombre_visitante
                    FROM calendario c
                    LEFT JOIN equipo el ON el.Id_Equipo = c.id_equipolocal
                    LEFT JOIN equipo ev ON ev.Id_Equipo = c.id_equipovicitante
                    WHERE c.id_equipolocal = ? OR c.id_equipovicitante = ?
                    ORDER BY c.fecha ASC, c.hora ASC";
    $stmtCal = $conn->prepare($sqlPartidos);
    $stmtCal->bind_param("ss", $id_equipo, $id_equipo);
    $stmtCal->execute();
    $resCal = $stmtCal->get_result();
    while ($row = $resCal->fetch_assoc()) {
        $partidos[] = $row;
    }
    $stmtCal->close();

    foreach ($partidos as $p) {
        $fecha = $p['fecha'] ? $p['fecha'] : '';
        $hora = $p['hora'] ? $p['hora'] : '00:00:00';
        $dtStr = $fecha !== '' ? ($fecha . ' ' . $hora) : '';
        $estadoRaw = isset($p['Estado']) ? $p['Estado'] : '';
        $estado = strtolower(trim($estadoRaw));
        $estado = str_replace(["á","é","í","ó","ú"], ["a","e","i","o","u"], $estado);

        if ($dtStr !== '') {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dtStr);
            if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i', $fecha . ' ' . substr($hora,0,5));
            if ($dt) {
                if ($dt->format('Y-m-d H:i:s') >= $nowStr) {
                    if ($estado !== 'finalizado' && $estado !== 'canselado' && $estado !== 'cancelado') {
                        $nextMatchId = $p['Id_Partido'];
                        break;
                    }
                }
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
<title>Admin Equipo</title>
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

.card{background:rgba(27,31,39,0.9);padding:26px;border-radius:20px;box-shadow:0 0 30px rgba(0,26,255,0.5);max-width:1200px;margin:0 auto;backdrop-filter:blur(10px);border:1px solid rgba(0,26,255,0.2);}

.tabs{display:flex;gap:10px;justify-content:center;margin-top:8px;flex-wrap:wrap;}
.tabs a{padding:10px 14px;background:rgba(255,255,255,0.08);border-radius:12px;text-decoration:none;color:white;font-weight:900;font-size:13px;letter-spacing:0.4px;border:1px solid rgba(255,255,255,0.14);}
.tabs a.active{background:rgba(0,255,198,0.16);border-color:rgba(0,255,198,0.35);color:white;}

.alert{width:100%;max-width:1200px;margin:0 auto 16px auto;padding:12px 14px;border-radius:12px;border:1px solid rgba(244,16,16,0.45);background:rgba(244,16,16,0.12);color:#fff;font-weight:800;font-size:14px;}
.alert.ok{border-color:rgba(0,255,198,0.45);background:rgba(0,255,198,0.10);}

.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:16px;}
.field{display:flex;flex-direction:column;gap:8px;background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.10);border-radius:16px;padding:14px;}
label{font-weight:900;font-size:13px;letter-spacing:0.3px;color:#00ffc6;}
input, select{width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.06);color:#fff;outline:none;font-weight:800;}
select option{color:#000;}
.readonly{opacity:0.85;border-color:rgba(255,255,255,0.10);background:rgba(255,255,255,0.03);}

.actions{display:flex;gap:12px;justify-content:center;margin-top:18px;flex-wrap:wrap;}
.btn{border:none;padding:12px 18px;border-radius:12px;cursor:pointer;font-weight:900;letter-spacing:0.5px;font-size:14px;transition:transform 0.2s ease,opacity 0.2s ease,box-shadow 0.2s ease;text-decoration:none;display:inline-block;}
.btn:active{transform:scale(0.98);}
.btn-primary{background:linear-gradient(90deg,#00ffc6,#1c2a92);color:#0b1220;box-shadow:0 0 18px rgba(0,255,198,0.25);}
.btn-secondary{background:rgba(255,255,255,0.10);color:#fff;border:1px solid rgba(255,255,255,0.18);}
.btn-danger{background:rgba(255,59,59,0.95);color:#fff;}
.btn-warn{background:rgba(255,215,0,0.95);color:#111;}
.btn-primary:hover,.btn-secondary:hover,.btn-danger:hover,.btn-warn:hover{opacity:0.95;}

table{width:100%;margin-top:16px;border-collapse:collapse;background:rgba(0,0,0,0.18);border-radius:14px;overflow:hidden;}
table th, table td{padding:12px;border-bottom:1px solid rgba(255,255,255,0.10);text-align:left;vertical-align:top;}
table th{color:#00ffc6;font-size:13px;letter-spacing:0.3px;}
input[type="number"]{width:90px;}

.row-next{background:rgba(255, 105, 180, 0.18);}
.row-finalizado{background:rgba(0, 255, 120, 0.14);}
.row-canselado{background:rgba(255, 0, 0, 0.16);}
.row-suspendido{background:rgba(0, 255, 255, 0.14);}
.row-pospuesto{background:rgba(255, 215, 0, 0.16);}
.row-programado{background:rgba(0, 140, 255, 0.14);}

.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;letter-spacing:0.3px;border:1px solid rgba(255,255,255,0.14);background:rgba(255,255,255,0.06);}
.small{font-size:12px;opacity:0.9;margin-top:4px;}
.wrap{white-space:normal;word-break:break-word;}

@media screen and (max-width:900px){
    .grid{grid-template-columns:1fr;}
    .main-content{padding:20px;margin-left:0;}
    .sidebar{display:none;}
    table th, table td{padding:10px;}
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Admin Equipo'); } ?>


<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="../Perfil2.php">🏠 Inicio</a></li>
        <li><a href="UnirmeEquipo.php">👥 Unirme a un equipo</a></li>
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
    <h1>⚙️ Administrar Equipo</h1>

    <?php if ($msgErr !== ''): ?>
        <div class="alert"><?php echo esc($msgErr); ?></div>
    <?php endif; ?>

    <?php if ($msgOk !== ''): ?>
        <div class="alert ok"><?php echo esc($msgOk); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="tabs">
            <a href="?id=<?php echo urlencode($id_equipo); ?>&tab=equipo" class="<?php echo ($tab=='equipo')?'active':''; ?>">Equipo</a>
            <a href="?id=<?php echo urlencode($id_equipo); ?>&tab=retadores" class="<?php echo ($tab=='retadores')?'active':''; ?>">1- Retadores</a>
            <a href="?id=<?php echo urlencode($id_equipo); ?>&tab=calendario" class="<?php echo ($tab=='calendario')?'active':''; ?>">2- Calendario</a>
            <a href="?id=<?php echo urlencode($id_equipo); ?>&tab=solicitudes" class="<?php echo ($tab=='solicitudes')?'active':''; ?>">3- Solicitudes</a>
            <a href="?id=<?php echo urlencode($id_equipo); ?>&tab=estadisticas" class="<?php echo ($tab=='estadisticas')?'active':''; ?>">4- Estadísticas</a>
        </div>

        <?php if ($tab == 'equipo'): ?>

            <form method="POST">
                <input type="hidden" name="guardar_equipo" value="1">
                <div class="grid">
                    <div class="field">
                        <label>Id_Equipo (no editable)</label>
                        <input class="readonly" type="text" value="<?php echo esc($equipo['Id_Equipo']); ?>" readonly>
                    </div>

                    <div class="field">
                        <label>Id_Deporte (no editable)</label>
                        <input class="readonly" type="text" value="<?php echo esc($equipo['Id_Deporte']); ?>" readonly>
                    </div>

                    <div class="field">
                        <label>Capitán (no editable)</label>
                        <input class="readonly" type="text" value="<?php echo esc($equipo['Capitan']); ?>" readonly>
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input type="text" name="Nombre" value="<?php echo esc($equipo['Nombre']); ?>" required>
                    </div>

                    <div class="field">
                        <label>País</label>
                        <input type="text" name="Pais" value="<?php echo esc($equipo['Pais']); ?>">
                    </div>

                    <div class="field">
                        <label>Código Postal</label>
                        <input type="text" name="CodigoPostal" value="<?php echo esc($equipo['CodigoPostal']); ?>">
                    </div>

                    <div class="field">
                        <label>Cantidad</label>
                        <input type="text" name="cantidad" value="<?php echo esc($equipo['cantidad']); ?>">
                    </div>

                    <div class="field">
                        <label>Entrenador</label>
                        <select name="Entrenador">
                            <option value="">Sin asignar</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?php echo esc($j['Id_Jugador']); ?>" <?php echo ($equipo['Entrenador'] == $j['Id_Jugador']) ? 'selected' : ''; ?>>
                                    <?php echo esc($j['Nombre']); ?> (<?php echo esc($j['Id_Jugador']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Asistente 1</label>
                        <select name="Asistente1">
                            <option value="">Sin asignar</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?php echo esc($j['Id_Jugador']); ?>" <?php echo ($equipo['Asistente1'] == $j['Id_Jugador']) ? 'selected' : ''; ?>>
                                    <?php echo esc($j['Nombre']); ?> (<?php echo esc($j['Id_Jugador']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Asistente 2</label>
                        <select name="Asistente2">
                            <option value="">Sin asignar</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?php echo esc($j['Id_Jugador']); ?>" <?php echo ($equipo['Asistente2'] == $j['Id_Jugador']) ? 'selected' : ''; ?>>
                                    <?php echo esc($j['Nombre']); ?> (<?php echo esc($j['Id_Jugador']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Asistente 3</label>
                        <select name="Asistente3">
                            <option value="">Sin asignar</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?php echo esc($j['Id_Jugador']); ?>" <?php echo ($equipo['Asistente3'] == $j['Id_Jugador']) ? 'selected' : ''; ?>>
                                    <?php echo esc($j['Nombre']); ?> (<?php echo esc($j['Id_Jugador']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Asistente 4</label>
                        <select name="Asistente4">
                            <option value="">Sin asignar</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?php echo esc($j['Id_Jugador']); ?>" <?php echo ($equipo['Asistente4'] == $j['Id_Jugador']) ? 'selected' : ''; ?>>
                                    <?php echo esc($j['Nombre']); ?> (<?php echo esc($j['Id_Jugador']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Guardar Equipo</button>
                    <a class="btn btn-secondary" href="../Mis_Equipos.php">Volver</a>
                </div>
            </form>

        <?php elseif ($tab == 'retadores'): ?>

            <?php
            $sqlJugadores = "SELECT ej.Id_EquipoJugador, ej.Id_Jugador, ej.numero, ej.tipo, r.Nombre
                             FROM equipo_jugador ej
                             LEFT JOIN retador r ON r.Id_Retador = ej.Id_Jugador
                             WHERE ej.Id_Equipo = ?
                             AND ej.Id_Jugador != ?
                             ORDER BY r.Nombre ASC";
            $stmt = $conn->prepare($sqlJugadores);
            $stmt->bind_param("ss", $id_equipo, $equipo['Capitan']);
            $stmt->execute();
            $result = $stmt->get_result();
            ?>

            <table>
                <tr>
                    <th>Jugador</th>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Número</th>
                    <th>Acciones</th>
                </tr>

                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo esc($row['Nombre'] ? $row['Nombre'] : $row['Id_Jugador']); ?></td>
                    <td><?php echo esc($row['Id_Jugador']); ?></td>
                    <td><span class="badge"><?php echo esc($row['tipo']); ?></span></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id_equipoJugador" value="<?php echo esc($row['Id_EquipoJugador']); ?>">
                            <input type="number" name="numero" value="<?php echo esc($row['numero']); ?>" required>
                            <button type="submit" name="actualizar_numero" class="btn btn-primary">Guardar</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id_equipoJugador" value="<?php echo esc($row['Id_EquipoJugador']); ?>">
                            <button type="submit" name="eliminar_jugador" class="btn btn-danger" onclick="return confirm('¿Eliminar jugador del equipo?');">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>

            <?php $stmt->close(); ?>

        <?php elseif ($tab == 'calendario'): ?>

            <?php if (empty($partidos)): ?>
                <div style="margin-top:16px;padding:16px;border-radius:14px;background:rgba(0,0,0,0.18);border:1px solid rgba(255,255,255,0.10);">
                    No hay partidos registrados para este equipo.
                </div>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Partido</th>
                        <th>Fecha / Hora</th>
                        <th>Jornada</th>
                        <th>Estado</th>
                        <th>Dirección</th>
                        <th>Resultado</th>
                        <th>Detalles</th>
                    </tr>

                    <?php foreach ($partidos as $p):
                        $estadoRaw = isset($p['Estado']) ? $p['Estado'] : '';
                        $estadoNorm = strtolower(trim($estadoRaw));
                        $estadoNorm = str_replace(["á","é","í","ó","ú"], ["a","e","i","o","u"], $estadoNorm);

                        $cls = "";
                        if ($estadoNorm === 'finalizado') $cls = "row-finalizado";
                        else if ($estadoNorm === 'canselado' || $estadoNorm === 'cancelado') $cls = "row-canselado";
                        else if ($estadoNorm === 'suspendido') $cls = "row-suspendido";
                        else if ($estadoNorm === 'pospuesto') $cls = "row-pospuesto";
                        else if ($estadoNorm === 'programado') $cls = "row-programado";

                        if ($nextMatchId !== null && $p['Id_Partido'] === $nextMatchId) {
                            $cls = trim($cls . " row-next");
                        }

                        $local = $p['nombre_local'] ? $p['nombre_local'] : $p['id_equipolocal'];
                        $vis = $p['nombre_visitante'] ? $p['nombre_visitante'] : $p['id_equipovicitante'];

                        $fecha = $p['fecha'] ? $p['fecha'] : '';
                        $hora = $p['hora'] ? substr($p['hora'],0,5) : '';
                        $jornada = $p['Jornada'] !== null ? $p['Jornada'] : '';
                        $grupo = $p['Grupo'] !== null ? $p['Grupo'] : '';
                        $liga = $p['id_liga'] !== null ? $p['id_liga'] : '';
                        $tiempo = $p['Tiempo_Jugado'] !== null ? $p['Tiempo_Jugado'] : '';

                        $dir = $p['direccion'] ? $p['direccion'] : '';
                        $cp = $p['codigo_postal'] ? $p['codigo_postal'] : '';
                        $desc = $p['descripcion'] ? $p['descripcion'] : '';

                        $gl = $p['Goles_local'] !== null ? $p['Goles_local'] : 0;
                        $gv = $p['Goles_visitante'] !== null ? $p['Goles_visitante'] : 0;
                        $resFinal = $p['resultado_final'] ? $p['resultado_final'] : ($gl . " - " . $gv);
                    ?>
                    <tr class="<?php echo esc($cls); ?>">
                        <td class="wrap">
                            <div style="font-weight:900;"><?php echo esc($local); ?> vs <?php echo esc($vis); ?></div>
                            <div class="small">ID Partido: <?php echo esc($p['Id_Partido']); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:900;"><?php echo esc($fecha); ?></div>
                            <div class="small"><?php echo esc($hora); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:900;"><?php echo esc($jornada); ?></div>
                            <div class="small">Grupo: <?php echo esc($grupo); ?></div>
                        </td>
                        <td><span class="badge"><?php echo esc($estadoRaw); ?></span></td>
                        <td class="wrap">
                            <div><?php echo esc($dir); ?></div>
                            <div class="small">CP: <?php echo esc($cp); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:900;"><?php echo esc($resFinal); ?></div>
                            <div class="small"><?php echo esc($gl); ?> - <?php echo esc($gv); ?></div>
                        </td>
                        <td class="wrap">
                            <div class="small">Liga: <?php echo esc($liga); ?> | Deporte: <?php echo esc($p['id_deporte']); ?></div>
                            <div class="small">Tiempo jugado: <?php echo esc($tiempo); ?></div>
                            <div class="small"><?php echo esc($desc); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
                    <span class="badge" style="background:rgba(255,105,180,0.18);">Próximo partido</span>
                    <span class="badge" style="background:rgba(0,255,120,0.14);">FINALIZADO</span>
                    <span class="badge" style="background:rgba(255,0,0,0.16);">CANSELADO</span>
                    <span class="badge" style="background:rgba(0,255,255,0.14);">SUSPENDIDO</span>
                    <span class="badge" style="background:rgba(255,215,0,0.16);">POSPUESTO</span>
                    <span class="badge" style="background:rgba(0,140,255,0.14);">PROGRAMADO</span>
                </div>
            <?php endif; ?>

        <?php elseif ($tab == 'solicitudes'): ?>

            <?php
            $solicitudes = [];
            $sqlSolicitudes = "SELECT s.id_solicitud, s.id_solicitante, s.id_areadesolicitud, s.fecha, s.estado,
                                      COALESCE(r.Nombre, s.id_solicitante) AS nombre_solicitante
                               FROM solicitudes s
                               LEFT JOIN retador r ON r.Id_Retador = s.id_solicitante
                               WHERE s.id_areadesolicitud = ?
                               ORDER BY 
                                   CASE WHEN UPPER(s.estado)='PENDIENTE' THEN 0 ELSE 1 END,
                                   s.fecha DESC";
            $stSolList = $conn->prepare($sqlSolicitudes);
            $stSolList->bind_param("s", $id_equipo);
            $stSolList->execute();
            $rsSolList = $stSolList->get_result();
            while ($row = $rsSolList->fetch_assoc()) $solicitudes[] = $row;
            $stSolList->close();
            ?>

            <?php if (empty($solicitudes)): ?>
                <div style="margin-top:16px;padding:16px;border-radius:14px;background:rgba(0,0,0,0.18);border:1px solid rgba(255,255,255,0.10);">
                    No hay solicitudes para este equipo.
                </div>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Solicitante</th>
                        <th>ID Solicitante</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>

                    <?php foreach ($solicitudes as $s):
                        $estadoSol = strtoupper(trim($s['estado']));
                        $isPend = ($estadoSol === 'PENDIENTE');
                    ?>
                    <tr>
                        <td class="wrap">
                            <div style="font-weight:900;"><?php echo esc($s['nombre_solicitante']); ?></div>
                            <div class="small">Solicitud: <?php echo esc($s['id_solicitud']); ?></div>
                        </td>
                        <td><?php echo esc($s['id_solicitante']); ?></td>
                        <td><?php echo esc($s['fecha']); ?></td>
                        <td><span class="badge"><?php echo esc($s['estado']); ?></span></td>
                        <td>
                            <?php if ($isPend): ?>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitud" value="<?php echo esc($s['id_solicitud']); ?>">
                                        <button type="submit" name="aceptar_solicitud" class="btn btn-primary" onclick="return confirm('¿Aceptar solicitud y agregar al jugador al equipo?');">Aceptar</button>
                                    </form>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitud" value="<?php echo esc($s['id_solicitud']); ?>">
                                        <button type="submit" name="rechazar_solicitud" class="btn btn-danger" onclick="return confirm('¿Rechazar solicitud?');">Rechazar</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="small">Sin acciones</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
                    <span class="badge">PENDIENTE = Acciones</span>
                    <span class="badge">ACEPTADA / RECHAZADA = Solo vista</span>
                </div>
            <?php endif; ?>

        <?php elseif ($tab == 'estadisticas'): ?>
            <h3 style="margin-top:16px;">Estadísticas (Próximamente)</h3>
        <?php endif; ?>

    </div>
</div>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>