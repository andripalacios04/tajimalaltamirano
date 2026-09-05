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
$Id_Retador = isset($usuario['Id_Retador']) ? htmlspecialchars($usuario['Id_Retador']) : '';
$Nombre = isset($usuario['Nombre']) ? htmlspecialchars($usuario['Nombre']) : 'Usuario';

$equiposEncontrados = [];
$equipoDetallado = null;
$error = '';
$success = '';

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

            $sqlYaMiembro = "SELECT Id_EquipoJugador 
                             FROM equipo_jugador 
                             WHERE Id_Equipo = ? AND Id_Jugador = ?
                             LIMIT 1";
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

                $sqlSolicitud = "SELECT id_solicitud 
                                 FROM solicitudes 
                                 WHERE id_solicitante = ? 
                                   AND id_areadesolicitud = ? 
                                   AND estado = 'PENDIENTE'
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

                    $sqlExisteEquipo = "SELECT Id_Equipo, Nombre 
                                        FROM equipo 
                                        WHERE Id_Equipo = ? 
                                        LIMIT 1";
                    $stmtEq = $conn->prepare($sqlExisteEquipo);
                    $stmtEq->bind_param("s", $idEquipoUnirse);
                    $stmtEq->execute();
                    $resEq = $stmtEq->get_result();

                    if ($resEq->num_rows == 0) {
                        $error = "El equipo no existe";
                        $stmtEq->close();
                    } else {
                        $eqInfo = $resEq->fetch_assoc();
                        $stmtEq->close();

                        $id_solicitud = 'SOL-' . uniqid() . '-' . time();
                        $fecha = date('Y-m-d');
                        $estado = 'PENDIENTE';

                        $sqlInsertSol = "INSERT INTO solicitudes 
                                            (id_solicitud, id_solicitante, id_areadesolicitud, fecha, estado)
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
                            $stmtIns->close();
                            header("Location: ../Perfil2.php");
                            exit();
                        } else {
                            $error = "Error al enviar la solicitud: " . $stmtIns->error;
                            $stmtIns->close();
                        }
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

$perfilUrl = empty($misEquipos) ? 'perfil.php' : '../Perfil2.php';

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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: linear-gradient(135deg, #140f27 0%, #203a43 50%, #1c2a92 100%);
            --card-bg: rgba(27, 31, 39, 0.9);
            --accent-red: #ff0000;
            --accent-blue: #001aff;
            --accent-green: #00ffc6;
            --accent-purple: #9d00ff;
            --text-light: #ffffff;
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-hover: 0 12px 40px rgba(0, 0, 0, 0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            min-height: 100vh;
            background: var(--primary-bg);
            color: var(--text-light);
            position: relative;
            overflow-x: hidden;
            padding: 20px;
        }

        .bg-particles {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-100vh) rotate(180deg); }
        }

        .container { width: 100%; max-width: 1400px; margin: 0 auto; padding: 20px; }

        .header { text-align: center; margin-bottom: 30px; }

        .header h1 {
            font-size: clamp(2rem, 5vw, 3rem);
            color: var(--text-light);
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            margin-bottom: 10px;
        }

        .back-button {
            position: absolute;
            top: 20px; left: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 2px solid var(--accent-green);
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .back-button:hover {
            background: var(--accent-green);
            color: #000;
            transform: translateX(-5px);
        }

        .main-content { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }

        @media (max-width: 900px) { .main-content { grid-template-columns: 1fr; } }

        .card {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 25px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .card h2 {
            color: var(--accent-green);
            margin-bottom: 25px;
            font-size: 1.8rem;
            border-bottom: 2px solid rgba(0, 255, 198, 0.3);
            padding-bottom: 10px;
        }

        .search-section { display: flex; flex-direction: column; gap: 20px; }

        .search-type { display: flex; gap: 20px; margin-bottom: 15px; }

        .search-option { flex: 1; text-align: center; }

        .search-option input[type="radio"] { display: none; }

        .search-option label {
            display: block;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .search-option input[type="radio"]:checked + label {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
            transform: translateY(-5px);
        }

        .search-input { display: flex; gap: 10px; }

        .search-input input {
            flex: 1;
            padding: 15px 20px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input input:focus {
            outline: none;
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(0, 255, 198, 0.3);
        }

        .search-button {
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--accent-blue), #0015cc);
            border: none;
            border-radius: 15px;
            color: var(--text-light);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .search-button:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0, 26, 255, 0.4); }

        .teams-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            max-height: 500px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .team-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 20px;
            border-left: 4px solid var(--accent-blue);
            transition: all 0.3s ease;
            animation: fadeInUp 0.5s ease-out;
        }

        .team-item:hover { background: rgba(255, 255, 255, 0.08); transform: translateX(5px); box-shadow: var(--shadow-hover); }

        .team-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }

        .team-item-name { font-size: 1.3rem; font-weight: 600; color: var(--text-light); }

        .team-item-id { background: rgba(0, 26, 255, 0.1); padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; color: var(--accent-blue); }

        .team-item-details { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px; }

        .team-item-detail { font-size: 0.9rem; }

        .team-item-detail .label { color: #aaaaaa; display: block; font-size: 0.85rem; }

        .view-details-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--accent-purple), #7a00cc);
            border: none;
            border-radius: 12px;
            color: var(--text-light);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-details-button:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(157, 0, 255, 0.4); }

        .team-modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
            padding: 20px;
        }

        .team-modal {
            background: var(--card-bg);
            border-radius: 25px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-hover);
            animation: slideUp 0.4s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 { color: var(--accent-green); font-size: 1.8rem; margin: 0; }

        .modal-close {
            background: none;
            border: none;
            color: #aaaaaa;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-close:hover { color: var(--accent-red); transform: rotate(90deg); }

        .modal-body { padding: 30px; }

        .modal-team-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-group { background: rgba(255, 255, 255, 0.05); padding: 20px; border-radius: 15px; }

        .info-label { color: #aaaaaa; font-size: 0.9rem; margin-bottom: 8px; display: block; }

        .info-value { font-size: 1.1rem; font-weight: 500; color: var(--text-light); }

        .modal-actions { display: flex; gap: 15px; margin-top: 20px; }

        .modal-button {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 15px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .modal-button-cancel { background: rgba(255, 255, 255, 0.1); color: var(--text-light); border: 2px solid rgba(255, 255, 255, 0.2); }
        .modal-button-cancel:hover { background: rgba(255, 0, 0, 0.2); border-color: var(--accent-red); transform: translateY(-3px); }

        .modal-button-join { background: linear-gradient(135deg, var(--accent-green), #00cc9d); color: #000; }
        .modal-button-join:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0, 255, 198, 0.4); }
        .modal-button-join:disabled { background: #666; cursor: not-allowed; transform: none; box-shadow: none; }

        .team-status-indicator {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            animation: fadeIn 0.5s ease;
        }

        .team-status-member { background: rgba(0, 255, 198, 0.1); border: 2px solid var(--accent-green); color: var(--accent-green); }
        .team-status-not-member { background: rgba(255, 255, 255, 0.05); border: 2px solid var(--accent-blue); color: var(--accent-blue); }

        .alert { padding: 20px; border-radius: 15px; margin-bottom: 25px; animation: fadeIn 0.5s ease; }
        .alert-error { background: rgba(255, 0, 0, 0.1); border-left: 5px solid var(--accent-red); color: #ff6b6b; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }

        .teams-list::-webkit-scrollbar { width: 8px; }
        .teams-list::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); border-radius: 10px; }
        .teams-list::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 10px; }
        .teams-list::-webkit-scrollbar-thumb:hover { background: var(--accent-purple); }

        @media (max-width: 768px) {
            .container { padding: 10px; }
            .card { padding: 20px; }
            .search-type { flex-direction: column; gap: 10px; }
            .search-input { flex-direction: column; }
            .back-button { position: relative; top: 0; left: 0; margin-bottom: 20px; width: fit-content; }
            .modal-actions { flex-direction: column; }
            .team-item-details { grid-template-columns: 1fr; }
        }
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
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Unirse a Equipo - RETAME'); } ?>

<div class="bg-particles" id="particles"></div>

<div class="container">
    <a href="../Perfil2.php" class="back-button">⬅ Volver </a>

    <div class="header">
        <h1>🤝 Unirse a un Equipo</h1>
        <p style="color: #cccccc; max-width: 600px; margin: 0 auto;">Busca un equipo por código o nombre y envía solicitud para unirte</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><strong>⚠️ Error:</strong> <?php echo $error; ?></div>
    <?php endif; ?>

    <div class="main-content">

        <div class="card search-section">
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

        <div class="card team-info">
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
                <div class="no-results" style="text-align:center;padding:40px 20px;color:#aaaaaa;">
                    <div class="no-results-icon">🔍</div>
                    <h3>No se encontraron equipos</h3>
                    <p>Intenta con otro código o nombre de equipo</p>
                </div>
            <?php else: ?>
                <div class="no-results" style="text-align:center;padding:40px 20px;color:#aaaaaa;">
                    <div class="no-results-icon">🤝</div>
                    <h3>Busca un equipo para ver los resultados</h3>
                    <p>Usa el código del equipo o su nombre para buscarlo</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <?php if ($equipoDetallado): ?>
    <div class="team-modal-overlay">
        <div class="team-modal">
            <div class="modal-header">
                <h3>👥 Detalles del Equipo</h3>
                <form method="POST" action="" style="display: inline;">
                    <button type="submit" name="cancelar_detalles" class="modal-close">×</button>
                </form>
            </div>

            <div class="modal-body">
                <div class="team-status-indicator <?php echo $enEquipoSeleccionado ? 'team-status-member' : 'team-status-not-member'; ?>">
                    <?php if ($enEquipoSeleccionado): ?>
                        ✅ Ya eres miembro de este equipo
                    <?php else: ?>
                        🔍 No eres miembro de este equipo - Puedes enviar solicitud
                    <?php endif; ?>
                </div>

                <div class="modal-team-info">
                    <div class="info-group"><span class="info-label">Nombre del Equipo</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Nombre']); ?></span></div>
                    <div class="info-group"><span class="info-label">Código del Equipo</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Id_Equipo']); ?></span></div>
                    <div class="info-group"><span class="info-label">Deporte</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['DeporteNombre'] ?? 'No especificado'); ?></span></div>
                    <div class="info-group"><span class="info-label">País</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['Pais'] ?? 'No especificado'); ?></span></div>
                    <div class="info-group"><span class="info-label">Código Postal</span><span class="info-value"><?php echo htmlspecialchars($equipoDetallado['CodigoPostal'] ?? 'No especificado'); ?></span></div>
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

</div>

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

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.querySelector('.team-modal-overlay')) {
            const btn = document.querySelector('[name="cancelar_detalles"]');
            if (btn) btn.click();
        }
    });
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>