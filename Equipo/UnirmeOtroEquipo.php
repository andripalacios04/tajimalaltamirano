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
include_once '../conexion.php';

if (!isset($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

$usuario = $_SESSION['usuario_data'];
$Id_Retador = isset($usuario['Id_Retador']) ? $usuario['Id_Retador'] : '';
$Nombre = isset($usuario['Nombre']) ? $usuario['Nombre'] : 'Usuario';

$equiposEncontrados = [];
$equipoDetallado = null;
$error = '';
$success = '';

$equipoActual = null;
$enEquipoSeleccionado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['buscar'])) {
        $busqueda = trim($_POST['busqueda']);
        $tipoBusqueda = $_POST['tipo_busqueda'];

        if (!empty($busqueda)) {
            if ($tipoBusqueda === 'id') {
                $sql = "SELECT e.*, d.Nombre as DeporteNombre 
                       FROM equipo e 
                       LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte 
                       WHERE e.Id_Equipo = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $busqueda);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $equiposEncontrados = [$result->fetch_assoc()];
                } else {
                    $error = "No se encontró ningún equipo con ese código";
                }
                $stmt->close();
            } else {
                $busquedaLike = "%" . $busqueda . "%";
                $sql = "SELECT e.*, d.Nombre as DeporteNombre 
                       FROM equipo e 
                       LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte 
                       WHERE e.Nombre LIKE ? 
                       ORDER BY e.Nombre, e.Pais";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $busquedaLike);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $equiposEncontrados[] = $row;
                    }
                } else {
                    $error = "No se encontraron equipos con ese nombre";
                }
                $stmt->close();
            }
        } else {
            $error = "Por favor ingresa un valor para buscar";
        }
    }

    if (isset($_POST['ver_detalles']) && isset($_POST['id_equipo'])) {
        $idEquipoDetalles = $_POST['id_equipo'];

        $sql = "SELECT e.*, d.Nombre as DeporteNombre 
               FROM equipo e 
               LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte 
               WHERE e.Id_Equipo = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $idEquipoDetalles);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $equipoDetallado = $result->fetch_assoc();

            if (!empty($Id_Retador)) {
                $sqlVerificar = "SELECT Id_EquipoJugador FROM equipo_jugador WHERE Id_Equipo = ? AND Id_Jugador = ? LIMIT 1";
                $stmtVer = $conn->prepare($sqlVerificar);
                $stmtVer->bind_param("ss", $idEquipoDetalles, $Id_Retador);
                $stmtVer->execute();
                $stmtVer->store_result();
                $enEquipoSeleccionado = ($stmtVer->num_rows > 0);
                $stmtVer->close();
            }
        }
        $stmt->close();
    }

    if (isset($_POST['cancelar_detalles'])) {
        $equipoDetallado = null;
        $enEquipoSeleccionado = false;
    }

    if (isset($_POST['confirmar_union']) && isset($_POST['id_equipo'])) {
        $idEquipoUnirse = $_POST['id_equipo'];

        if (empty($Id_Retador)) {
            $error = "No se pudo identificar tu usuario. Por favor, inicia sesión nuevamente.";
        } else {

            $sqlYaMiembro = "SELECT Id_EquipoJugador FROM equipo_jugador WHERE Id_Equipo = ? AND Id_Jugador = ? LIMIT 1";
            $stmtYa = $conn->prepare($sqlYaMiembro);
            $stmtYa->bind_param("ss", $idEquipoUnirse, $Id_Retador);
            $stmtYa->execute();
            $stmtYa->store_result();

            if ($stmtYa->num_rows > 0) {
                $error = "Ya eres miembro de este equipo. No puedes solicitar unirte de nuevo.";
                $enEquipoSeleccionado = true;
                $stmtYa->close();
            } else {
                $stmtYa->close();

                $sqlSolicitud = "SELECT id_solicitud FROM solicitudes 
                                 WHERE id_solicitante = ? AND id_areadesolicitud = ? AND estado = 'PENDIENTE'
                                 LIMIT 1";
                $stmtSol = $conn->prepare($sqlSolicitud);
                $stmtSol->bind_param("ss", $Id_Retador, $idEquipoUnirse);
                $stmtSol->execute();
                $stmtSol->store_result();

                if ($stmtSol->num_rows > 0) {
                    $error = "Ya tienes una solicitud PENDIENTE para este equipo. Espera respuesta del capitán.";
                    $stmtSol->close();
                } else {
                    $stmtSol->close();

                    $sqlExisteEquipo = "SELECT Id_Equipo, Nombre FROM equipo WHERE Id_Equipo = ? LIMIT 1";
                    $stmtEq = $conn->prepare($sqlExisteEquipo);
                    $stmtEq->bind_param("s", $idEquipoUnirse);
                    $stmtEq->execute();
                    $resEq = $stmtEq->get_result();

                    if ($resEq->num_rows == 0) {
                        $error = "El equipo no existe.";
                        $stmtEq->close();
                    } else {
                        $eqInfo = $resEq->fetch_assoc();
                        $stmtEq->close();

                        $id_solicitud = 'SOL-' . uniqid() . '-' . time();
                        $fecha = date('Y-m-d');
                        $estado = 'PENDIENTE';

                        $sqlInsertSol = "INSERT INTO solicitudes (id_solicitud, id_solicitante, id_areadesolicitud, fecha, estado)
                                         VALUES (?, ?, ?, ?, ?)";
                        $stmtIns = $conn->prepare($sqlInsertSol);
                        $stmtIns->bind_param("sssss", $id_solicitud, $Id_Retador, $idEquipoUnirse, $fecha, $estado);

                        if ($stmtIns->execute()) {
                             



                        $tipo = 'Solicitud Equipo';
$descripcion = $Nombre . " envio una solicitud para unirse a tu equipo.";
$estadoNoti = 'Ver';

$sqlReceptores = "SELECT Id_Jugador 
                  FROM equipo_jugador
                  WHERE Id_Equipo = ?
                  AND tipo IN ('capitan','asistente1','asistente2','asistente3','asistente4','entrenador')
                  AND Id_Jugador IS NOT NULL";
$stmtRec = $conn->prepare($sqlReceptores);
$stmtRec->bind_param("s", $idEquipoUnirse);
$stmtRec->execute();
$resRec = $stmtRec->get_result();

$sqlNoti = "INSERT INTO notificaciones (Id_notificacion, id_retador, id_area, fecha, Tipo, descripcion, Estado)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
$stmtNoti = $conn->prepare($sqlNoti);

while ($rowRec = $resRec->fetch_assoc()) {
    $Id_notificacion = date('Ymd') . random_int(10000, 99999) . substr(uniqid(), -4);
    $id_retador_receptor = $rowRec['Id_Jugador'];
    $id_area = $idEquipoUnirse;

    $stmtNoti->bind_param("sssssss", $Id_notificacion, $id_retador_receptor, $id_area, $fecha, $tipo, $descripcion, $estadoNoti);
    $stmtNoti->execute();
}

$stmtNoti->close();
$stmtRec->close();
                          

                            $success = "✅ Solicitud enviada al equipo <strong>" . htmlspecialchars($eqInfo['Nombre']) . "</strong>. Espera aprobación.";
                            $enEquipoSeleccionado = false;
                        } else {
                            $error = "Error al enviar la solicitud: " . $stmtIns->error;
                        }
                        $stmtIns->close();
                    }
                }
            }
        }

        $sql = "SELECT e.*, d.Nombre as DeporteNombre 
               FROM equipo e 
               LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte 
               WHERE e.Id_Equipo = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $idEquipoUnirse);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $equipoDetallado = $result->fetch_assoc();
        }
        $stmt->close();
    }
}

$misEquipos = [];
if (!empty($Id_Retador)) {
    $sqlMisEquipos = "SELECT e.*, d.Nombre as DeporteNombre 
                      FROM equipo_jugador ej 
                      INNER JOIN equipo e ON ej.Id_Equipo = e.Id_Equipo 
                      LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte 
                      WHERE ej.Id_Jugador = ?";
    $stmtMis = $conn->prepare($sqlMisEquipos);
    $stmtMis->bind_param("s", $Id_Retador);
    $stmtMis->execute();
    $resultMis = $stmtMis->get_result();
    while ($row = $resultMis->fetch_assoc()) {
        $misEquipos[] = $row;
    }
    $stmtMis->close();
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
<title>Unirse a Equipo - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Poppins',sans-serif;
}

html,
body{
    width:100%;
    min-height:100%;
}

.bg-particles,
.particle{
    display:none;
}

.team-modal-overlay{
    position:fixed;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:2000;
}

.team-modal{
    width:100%;
    max-width:650px;
    max-height:90vh;
    overflow-y:auto;
}

.search-type{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}

.search-option input[type="radio"]{
    display:none;
}

.search-input{
    display:flex;
    gap:10px;
}

.teams-list{
    display:flex;
    flex-direction:column;
    gap:15px;
}

.team-item-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
}

.team-item-details{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
}

.modal-team-info{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}

.modal-actions{
    display:flex;
    gap:12px;
}

@media(max-width:620px){
    .search-type,
    .team-item-details,
    .modal-team-info{
        grid-template-columns:1fr;
    }

    .search-input,
    .modal-actions{
        flex-direction:column;
    }

    .team-item-header{
        flex-direction:column;
    }
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
<style id="retame-equipo-fix-css">
:root{
    --azul:#1877f2;
    --azul2:#0ea5e9;
    --azul-neon:#0099ff;
    --cyan:#8fefff;
    --rojo:#ff4b5c;
    --rojo2:#ff2f45;
    --texto:#111827;
    --gris:#6b7280;
    --blanco:#ffffff;
    --sombra-azul:0 0 0 3px rgba(24,119,242,.22),0 12px 28px rgba(24,119,242,.14);
    --sombra-roja:0 0 0 3px rgba(255,75,92,.22),0 12px 28px rgba(255,75,92,.14);
}

body.retame-oficial-page > .bg-particles,
body.retame-oficial-page > .sidebar:not(.retame-oficial-sidebar),
body.retame-oficial-page > .menu-toggle:not(.retame-oficial-menu-toggle),
body.retame-oficial-page > .overlay:not(.retame-oficial-overlay),
body.retame-oficial-page > .btn-ligas,
body.retame-oficial-page > .btn-retar{
    display:none!important;
}

body.retame-oficial-page > .main-content{
    position:relative!important;
    z-index:2!important;
    width:min(1180px,100%)!important;
    max-width:1180px!important;
    margin-left:0!important;
    margin-right:auto!important;
    padding-top:16px!important;
}

body.retame-oficial-page .main-content > h1{
    width:100%;
    margin:0 0 22px!important;
    padding:24px 28px!important;
    border-radius:28px!important;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,.20),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,.17),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,.08),rgba(255,255,255,.02) 46%,rgba(255,75,92,.08)),
        rgba(255,255,255,.94)!important;
    border:1px solid rgba(17,24,39,.05)!important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.10),
        0 0 0 1px rgba(24,119,242,.12),
        0 0 18px rgba(0,153,255,.14)!important;
    color:var(--azul)!important;
    font-family:'Orbitron',sans-serif!important;
    font-size:clamp(1.45rem,4vw,2rem)!important;
    font-weight:700!important;
    line-height:1.25!important;
    text-align:left!important;
    letter-spacing:0!important;
    text-shadow:none!important;
}

body.retame-oficial-page .search-section,
body.retame-oficial-page .card{
    position:relative!important;
    overflow:hidden!important;
    width:100%!important;
    margin:0 0 24px!important;
    padding:clamp(20px,3vw,30px)!important;
    border-radius:28px!important;
    background:rgba(255,255,255,.94)!important;
    border:1px solid rgba(17,24,39,.05)!important;
    border-top:1px solid rgba(17,24,39,.05)!important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.10),
        0 0 0 1px rgba(24,119,242,.12),
        0 0 18px rgba(0,153,255,.14)!important;
    backdrop-filter:none!important;
}

body.retame-oficial-page .search-section::before,
body.retame-oficial-page .card::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        radial-gradient(circle at 8% 6%,rgba(24,119,242,.09),transparent 240px),
        radial-gradient(circle at 94% 92%,rgba(255,75,92,.08),transparent 280px);
}

body.retame-oficial-page .search-section > *,
body.retame-oficial-page .card > *{
    position:relative;
    z-index:1;
}

body.retame-oficial-page .search-section h2,
body.retame-oficial-page .card h2{
    margin:0 0 22px!important;
    padding:0 0 12px!important;
    border-bottom:2px solid rgba(0,153,255,.18)!important;
    color:var(--azul)!important;
    font-family:'Orbitron',sans-serif!important;
    font-size:clamp(1.15rem,3vw,1.55rem)!important;
    font-weight:700!important;
    text-align:left!important;
}

body.retame-oficial-page .search-type{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
    margin-bottom:18px!important;
}

body.retame-oficial-page .search-option{
    min-width:0;
    text-align:center;
}

body.retame-oficial-page .search-option input[type="radio"]{
    display:none!important;
}

body.retame-oficial-page .search-option label{
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-height:48px!important;
    padding:10px 14px!important;
    border-radius:16px!important;
    background:#ffffff!important;
    color:#374151!important;
    border:2px solid rgba(0,153,255,.20)!important;
    box-shadow:0 6px 14px rgba(0,0,0,.04)!important;
    cursor:pointer!important;
    font-size:13px!important;
    font-weight:900!important;
    transition:.22s ease!important;
}

body.retame-oficial-page .search-option label:hover{
    transform:translateY(-2px)!important;
    border-color:rgba(255,75,92,.55)!important;
}

body.retame-oficial-page .search-option input[type="radio"]:checked + label{
    background:#f8fbff!important;
    color:var(--azul)!important;
    border-color:var(--azul-neon)!important;
    box-shadow:var(--sombra-azul)!important;
    transform:translateY(-1px)!important;
}

body.retame-oficial-page .search-input{
    display:flex!important;
    align-items:stretch!important;
    gap:10px!important;
    margin-bottom:0!important;
}

body.retame-oficial-page .search-input input{
    flex:1!important;
    min-width:0!important;
    min-height:50px!important;
    margin:0!important;
    padding:12px 15px!important;
    border-radius:16px!important;
    border:2px solid rgba(0,153,255,.32)!important;
    outline:none!important;
    background:#ffffff!important;
    color:#111827!important;
    font-family:'Poppins',sans-serif!important;
    font-size:14px!important;
    font-weight:500!important;
    box-shadow:0 6px 15px rgba(0,0,0,.04)!important;
    transition:.22s ease!important;
}

body.retame-oficial-page .search-input input::placeholder{
    color:#9ca3af!important;
}

body.retame-oficial-page .search-input input:focus{
    border-color:var(--azul-neon)!important;
    box-shadow:var(--sombra-azul)!important;
    transform:none!important;
}

body.retame-oficial-page .search-button,
body.retame-oficial-page .view-details-button,
body.retame-oficial-page .modal-button{
    min-height:48px!important;
    border:none!important;
    border-radius:16px!important;
    padding:11px 18px!important;
    font-family:'Poppins',sans-serif!important;
    font-size:13px!important;
    font-weight:900!important;
    cursor:pointer!important;
    transition:.22s ease!important;
    text-decoration:none!important;
}

body.retame-oficial-page .search-button,
body.retame-oficial-page .view-details-button{
    background:linear-gradient(135deg,#1877f2,#0ea5e9)!important;
    color:#ffffff!important;
    box-shadow:0 9px 18px rgba(24,119,242,.18)!important;
}

body.retame-oficial-page .search-button{
    width:auto!important;
    padding-left:24px!important;
    padding-right:24px!important;
    white-space:nowrap!important;
}

body.retame-oficial-page .search-button:hover,
body.retame-oficial-page .view-details-button:hover,
body.retame-oficial-page .modal-button:hover:not(:disabled){
    transform:translateY(-2px)!important;
}

body.retame-oficial-page .alert{
    width:100%!important;
    margin:0 0 20px!important;
    padding:15px 17px!important;
    border-radius:18px!important;
    border-left:2px solid!important;
    font-size:13px!important;
    font-weight:700!important;
    line-height:1.55!important;
    box-shadow:0 10px 22px rgba(0,0,0,.07)!important;
}

body.retame-oficial-page .alert-success{
    background:#f0fdf4!important;
    border-color:rgba(34,197,94,.34)!important;
    color:#15803d!important;
}

body.retame-oficial-page .alert-error{
    background:#fff5f7!important;
    border-color:rgba(255,75,92,.38)!important;
    color:#b91c1c!important;
}

body.retame-oficial-page .teams-list{
    display:grid!important;
    grid-template-columns:repeat(auto-fill,minmax(280px,1fr))!important;
    gap:16px!important;
    max-height:620px!important;
    overflow-y:auto!important;
    padding:4px 8px 4px 2px!important;
}

body.retame-oficial-page .teams-list::-webkit-scrollbar{
    width:9px;
}

body.retame-oficial-page .teams-list::-webkit-scrollbar-track{
    background:rgba(24,119,242,.07);
    border-radius:999px;
}

body.retame-oficial-page .teams-list::-webkit-scrollbar-thumb{
    background:linear-gradient(180deg,#1877f2,#ff4b5c);
    border-radius:999px;
}

body.retame-oficial-page .team-item{
    min-width:0!important;
    margin:0!important;
    padding:16px!important;
    border-radius:22px!important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb)!important;
    border:2px solid rgba(255,75,92,.62)!important;
    border-top:2px solid rgba(255,75,92,.62)!important;
    border-left:2px solid rgba(255,75,92,.62)!important;
    box-shadow:
        0 10px 22px rgba(0,0,0,.07),
        0 0 0 2px rgba(0,153,255,.18),
        0 0 14px rgba(0,153,255,.12)!important;
    transition:.22s ease!important;
}

body.retame-oficial-page .team-item:hover{
    transform:translateY(-3px)!important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb)!important;
    border-color:rgba(255,75,92,.95)!important;
    box-shadow:
        0 14px 28px rgba(0,0,0,.10),
        0 0 0 3px rgba(0,153,255,.23),
        0 0 18px rgba(0,153,255,.16)!important;
}

body.retame-oficial-page .team-item-header{
    display:flex!important;
    justify-content:space-between!important;
    align-items:flex-start!important;
    gap:10px!important;
    margin-bottom:13px!important;
    padding-bottom:13px!important;
    border-bottom:1px solid rgba(17,24,39,.08)!important;
}

body.retame-oficial-page .team-item-name{
    min-width:0!important;
    color:#111827!important;
    font-size:16px!important;
    font-weight:900!important;
    line-height:1.3!important;
}

body.retame-oficial-page .team-item-id{
    display:inline-flex!important;
    align-items:center!important;
    width:auto!important;
    max-width:100%!important;
    padding:5px 9px!important;
    border-radius:999px!important;
    background:rgba(24,119,242,.08)!important;
    color:var(--azul)!important;
    border:1px solid rgba(24,119,242,.18)!important;
    font-size:10px!important;
    font-weight:900!important;
    word-break:break-all!important;
}

body.retame-oficial-page .team-item-details{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:10px!important;
    margin-bottom:14px!important;
}

body.retame-oficial-page .team-item-detail{
    min-width:0!important;
    color:#374151!important;
    font-size:12px!important;
    font-weight:700!important;
    line-height:1.45!important;
    word-break:break-word!important;
}

body.retame-oficial-page .team-item-detail .label{
    display:block!important;
    margin-bottom:3px!important;
    color:#6b7280!important;
    font-size:10px!important;
    font-weight:800!important;
}

body.retame-oficial-page .view-details-button{
    width:100%!important;
}

body.retame-oficial-page .card > div[style*="text-align:center"]{
    padding:35px 20px!important;
    color:#6b7280!important;
    border:2px dashed rgba(0,153,255,.24)!important;
    border-radius:20px!important;
    background:rgba(24,119,242,.04)!important;
}

body.retame-oficial-page .team-modal-overlay{
    position:fixed!important;
    inset:0!important;
    z-index:2000!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:18px!important;
    background:rgba(15,23,42,.54)!important;
    backdrop-filter:blur(8px)!important;
}

body.retame-oficial-page .team-modal{
    width:min(700px,100%)!important;
    max-width:700px!important;
    max-height:90dvh!important;
    overflow:auto!important;
    padding:0!important;
    border-radius:28px!important;
    background:#ffffff!important;
    color:#111827!important;
    border:2px solid rgba(0,153,255,.26)!important;
    border-top:2px solid rgba(0,153,255,.26)!important;
    box-shadow:
        0 22px 55px rgba(0,0,0,.22),
        0 0 0 3px rgba(255,75,92,.08)!important;
}

body.retame-oficial-page .modal-header{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:12px!important;
    margin:0!important;
    padding:22px 24px!important;
    border-bottom:1px solid rgba(17,24,39,.08)!important;
}

body.retame-oficial-page .modal-header h3{
    margin:0!important;
    color:var(--azul)!important;
    font-family:'Orbitron',sans-serif!important;
    font-size:clamp(1.2rem,3vw,1.55rem)!important;
    font-weight:700!important;
}

body.retame-oficial-page .modal-close{
    width:42px!important;
    height:42px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:0!important;
    border:none!important;
    border-radius:50%!important;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045)!important;
    color:#ffffff!important;
    font-size:24px!important;
    font-weight:900!important;
    cursor:pointer!important;
    box-shadow:0 8px 16px rgba(255,75,92,.18)!important;
}

body.retame-oficial-page .modal-body{
    padding:24px!important;
}

body.retame-oficial-page .modal-team-info{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
    margin-bottom:22px!important;
}

body.retame-oficial-page .info-group{
    min-width:0!important;
    padding:14px!important;
    border-radius:17px!important;
    background:#f8fbff!important;
    border:2px solid rgba(0,153,255,.18)!important;
}

body.retame-oficial-page .info-label{
    display:block!important;
    margin-bottom:5px!important;
    color:#6b7280!important;
    font-size:10px!important;
    font-weight:800!important;
}

body.retame-oficial-page .info-value{
    display:block!important;
    color:#111827!important;
    font-size:13px!important;
    font-weight:900!important;
    line-height:1.45!important;
    word-break:break-word!important;
}

body.retame-oficial-page .modal-actions{
    display:flex!important;
    justify-content:center!important;
    align-items:stretch!important;
    gap:10px!important;
    flex-wrap:nowrap!important;
    margin-top:0!important;
}

body.retame-oficial-page .modal-button{
    flex:1!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:8px!important;
}

body.retame-oficial-page .modal-button-cancel{
    background:#ffffff!important;
    color:var(--rojo2)!important;
    border:2px solid rgba(255,75,92,.34)!important;
    box-shadow:0 8px 16px rgba(255,75,92,.08)!important;
}

body.retame-oficial-page .modal-button-join{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045)!important;
    color:#ffffff!important;
    border:2px solid transparent!important;
    box-shadow:var(--sombra-roja)!important;
}

body.retame-oficial-page .modal-button-join:disabled{
    background:#cbd5e1!important;
    color:#64748b!important;
    border-color:transparent!important;
    box-shadow:none!important;
    cursor:not-allowed!important;
    opacity:1!important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .search-section,
body.retame-oficial-page.dark-mode .card,
body.retame-oficial-page.dark-mode .team-item,
body.retame-oficial-page.dark-mode .team-modal{
    background:#111827!important;
    color:#e5e7eb!important;
    border-color:rgba(255,75,92,.76)!important;
    border-top-color:rgba(255,75,92,.76)!important;
    box-shadow:
        0 10px 24px rgba(0,0,0,.28),
        0 0 0 2px rgba(0,153,255,.22),
        0 0 18px rgba(0,153,255,.16)!important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .search-section h2,
body.retame-oficial-page.dark-mode .card h2,
body.retame-oficial-page.dark-mode .modal-header h3{
    color:var(--cyan)!important;
}

body.retame-oficial-page.dark-mode .search-option label,
body.retame-oficial-page.dark-mode .search-input input,
body.retame-oficial-page.dark-mode .modal-button-cancel,
body.retame-oficial-page.dark-mode .info-group{
    background:#0b1220!important;
    color:#e5e7eb!important;
}

body.retame-oficial-page.dark-mode .search-option label,
body.retame-oficial-page.dark-mode .search-input input,
body.retame-oficial-page.dark-mode .info-group{
    border-color:rgba(77,184,255,.32)!important;
}

body.retame-oficial-page.dark-mode .search-option input[type="radio"]:checked + label{
    background:#0b1220!important;
    color:var(--cyan)!important;
    border-color:rgba(0,153,255,.95)!important;
}

body.retame-oficial-page.dark-mode .search-input input::placeholder{
    color:#94a3b8!important;
}

body.retame-oficial-page.dark-mode .team-item-header,
body.retame-oficial-page.dark-mode .modal-header{
    border-bottom-color:rgba(255,255,255,.08)!important;
}

body.retame-oficial-page.dark-mode .team-item-name,
body.retame-oficial-page.dark-mode .info-value{
    color:#f8fafc!important;
}

body.retame-oficial-page.dark-mode .team-item-detail,
body.retame-oficial-page.dark-mode .team-item-detail .label,
body.retame-oficial-page.dark-mode .info-label{
    color:#cbd5e1!important;
}

body.retame-oficial-page.dark-mode .team-item-id{
    background:rgba(14,165,233,.10)!important;
    color:var(--cyan)!important;
    border-color:rgba(77,184,255,.25)!important;
}

body.retame-oficial-page.dark-mode .card > div[style*="text-align:center"]{
    color:#cbd5e1!important;
    background:rgba(14,165,233,.06)!important;
    border-color:rgba(77,184,255,.24)!important;
}

body.retame-oficial-page.dark-mode .modal-button-cancel{
    color:#fb7185!important;
    border-color:rgba(255,75,92,.40)!important;
}

@media(max-width:820px){
    body.retame-oficial-page > .main-content{
        width:100%!important;
        max-width:100%!important;
        padding-top:12px!important;
    }

    body.retame-oficial-page .teams-list{
        grid-template-columns:repeat(auto-fill,minmax(250px,1fr))!important;
    }
}

@media(max-width:620px){
    body.retame-oficial-page .main-content > h1{
        margin-bottom:16px!important;
        padding:20px 17px!important;
        border-radius:24px!important;
        text-align:center!important;
        font-size:1.3rem!important;
    }

    body.retame-oficial-page .search-section,
    body.retame-oficial-page .card{
        margin-bottom:18px!important;
        padding:20px 16px!important;
        border-radius:24px!important;
    }

    body.retame-oficial-page .search-type{
        grid-template-columns:1fr!important;
    }

    body.retame-oficial-page .search-input,
    body.retame-oficial-page .modal-actions{
        flex-direction:column!important;
    }

    body.retame-oficial-page .search-button,
    body.retame-oficial-page .modal-button{
        width:100%!important;
    }

    body.retame-oficial-page .teams-list{
        grid-template-columns:1fr!important;
        max-height:none!important;
        overflow:visible!important;
        padding-right:0!important;
    }

    body.retame-oficial-page .team-item-header{
        flex-direction:column!important;
    }

    body.retame-oficial-page .team-item-details,
    body.retame-oficial-page .modal-team-info{
        grid-template-columns:1fr!important;
    }

    body.retame-oficial-page .modal-header{
        padding:18px!important;
    }

    body.retame-oficial-page .modal-body{
        padding:18px!important;
    }
}
</style>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Unirse a Equipo - RETAME'); } ?>

<div class="bg-particles" id="particles"></div>

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
    <h1>🤝 Unirse a un Equipo</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><strong>⚠️ Error:</strong> <?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><strong>✅ Éxito:</strong> <?php echo $success; ?></div>
    <?php endif; ?>

    <div class="search-section">
        <h2>🔍 Buscar Equipo</h2>

        <form method="POST" action="">
            <div class="search-type">
                <div class="search-option">
                    <input type="radio" id="search-id" name="tipo_busqueda" value="id" checked>
                    <label for="search-id">Por Código del Equipo</label>
                </div>
                <div class="search-option">
                    <input type="radio" id="search-name" name="tipo_busqueda" value="nombre">
                    <label for="search-name">Por Nombre del Equipo</label>
                </div>
            </div>

            <div class="search-input">
                <input type="text" id="busqueda" name="busqueda" placeholder="Ingresa el código o nombre del equipo"
                       value="<?php echo isset($_POST['busqueda']) ? htmlspecialchars($_POST['busqueda']) : ''; ?>" required>
                <button type="submit" name="buscar" class="search-button">Buscar</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>🏆 Equipos Encontrados</h2>

        <?php if (!empty($equiposEncontrados)): ?>
            <div class="teams-list">
                <?php foreach ($equiposEncontrados as $equipo): ?>
                    <div class="team-item">
                        <div class="team-item-header">
                            <div class="team-item-name"><?php echo htmlspecialchars($equipo['Nombre']); ?></div>
                            <div class="team-item-id">ID: <?php echo htmlspecialchars($equipo['Id_Equipo']); ?></div>
                        </div>

                        <div class="team-item-details">
                            <div class="team-item-detail"><span class="label">Deporte:</span><?php echo htmlspecialchars($equipo['DeporteNombre'] ?? 'No especificado'); ?></div>
                            <div class="team-item-detail"><span class="label">País:</span><?php echo htmlspecialchars($equipo['Pais'] ?? 'No especificado'); ?></div>
                            <div class="team-item-detail"><span class="label">Capitán:</span><?php echo htmlspecialchars($equipo['Capitan'] ?? 'No especificado'); ?></div>
                            <div class="team-item-detail"><span class="label">Entrenador:</span><?php echo htmlspecialchars($equipo['Entrenador'] ?? 'No especificado'); ?></div>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="id_equipo" value="<?php echo htmlspecialchars($equipo['Id_Equipo']); ?>">
                            <button type="submit" name="ver_detalles" class="view-details-button">👁️ Ver Detalles Completos</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar'])): ?>
            <div style="text-align:center;padding:40px 20px;color:#aaaaaa;">No se encontraron equipos. Intenta con otro código o nombre.</div>
        <?php else: ?>
            <div style="text-align:center;padding:40px 20px;color:#aaaaaa;">Busca un equipo para ver resultados.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($equipoDetallado): ?>
<div class="team-modal-overlay">
    <div class="team-modal">
        <div class="modal-header">
            <h3>👥 Detalles del Equipo</h3>
            <form method="POST" action="" style="display:inline;">
                <button type="submit" name="cancelar_detalles" class="modal-close">×</button>
            </form>
        </div>

        <div class="modal-body">
            <div class="modal-team-info">
                <div class="info-group"><span class="info-label">Nombre</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Nombre']); ?></span></div>
                <div class="info-group"><span class="info-label">Código</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Id_Equipo']); ?></span></div>
                <div class="info-group"><span class="info-label">Deporte</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['DeporteNombre'] ?? 'No especificado'); ?></span></div>
                <div class="info-group"><span class="info-label">País</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Pais'] ?? 'No especificado'); ?></span></div>
                <div class="info-group"><span class="info-label">Capitán</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Capitan'] ?? 'No especificado'); ?></span></div>
                <div class="info-group"><span class="info-label">Entrenador</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Entrenador'] ?? 'No especificado'); ?></span></div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="id_equipo" value="<?php echo htmlspecialchars($equipoDetallado['Id_Equipo']); ?>">
                <div class="modal-actions">
                    <button type="submit" name="cancelar_detalles" class="modal-button modal-button-cancel">❌ Cancelar</button>

                    <?php if (!$enEquipoSeleccionado): ?>
                        <button type="submit" name="confirmar_union" class="modal-button modal-button-join">📩 Enviar solicitud</button>
                    <?php else: ?>
                        <button type="button" disabled class="modal-button modal-button-join">✅ Ya eres miembro</button>
                    <?php endif; ?>
                </div>
            </form>

        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 20;

    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        const size = Math.random() * 15 + 5;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        particle.style.opacity = Math.random() * 0.2 + 0.1;
        const duration = Math.random() * 25 + 20;
        const delay = Math.random() * 5;
        particle.style.animation = `float ${duration}s ${delay}s infinite linear`;
        particlesContainer.appendChild(particle);
    }

    const searchId = document.getElementById('search-id');
    const searchName = document.getElementById('search-name');
    const searchInput = document.getElementById('busqueda');

    function updatePlaceholder() {
        if (searchId.checked) {
            searchInput.placeholder = 'Ejemplo: EQ-2024-001';
            searchInput.title = 'Buscar por código exacto del equipo';
        } else {
            searchInput.placeholder = 'Ejemplo: Los Tigres';
            searchInput.title = 'Buscar equipos con nombres similares';
        }
    }
    searchId.addEventListener('change', updatePlaceholder);
    searchName.addEventListener('change', updatePlaceholder);
    updatePlaceholder();
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>