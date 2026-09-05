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
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';
$CodigoPostal = isset($usuarios['CodigoPostal']) ? $usuarios['CodigoPostal'] : '';

// Variables para resultados
$ligasSugeridas = [];
$ligasBuscadas = [];
$equiposUsuario = [];
$mensajeError = '';
$mensajeExito = '';
$mostrarSugerencias = true;
$mostrarSeleccionEquipo = false;
$ligaSeleccionada = null;
$equiposCompatibles = [];
$deporteLiga = '';

// Obtener equipos del usuario para mostrar en el modal
if (!empty($Id_Retador)) {
    $sql_equipos = "SELECT DISTINCT e.Id_Equipo, e.Nombre, e.Id_Deporte,
                   CASE WHEN e.Capitan = ? THEN 'Capitán' ELSE 'Miembro' END as Rol
                   FROM equipo e 
                   LEFT JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo 
                   WHERE e.Capitan = ? OR ej.Id_Jugador = ?";
    $stmt_eq = $conn->prepare($sql_equipos);
    if ($stmt_eq) {
        $stmt_eq->bind_param("sss", $Id_Retador, $Id_Retador, $Id_Retador);
        $stmt_eq->execute();
        $res_eq = $stmt_eq->get_result();
        while ($fila = $res_eq->fetch_assoc()) {
            $equiposUsuario[] = $fila;
        }
        $stmt_eq->close();
    }
}

// Obtener sugerencias basadas en código postal del usuario
if (!empty($CodigoPostal) && $mostrarSugerencias) {
    $sql_sugerencias = "SELECT l.*, d.Nombre as DeporteNombre,
                       COUNT(le.Id_Equipo) as EquiposInscritos,
                       CASE 
                           WHEN l.CodigoPostal = ? THEN 0
                           WHEN LEFT(l.CodigoPostal, 3) = LEFT(?, 3) THEN 1
                           ELSE 2
                       END as Prioridad
                       FROM ligas l 
                       LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                       LEFT JOIN liga_equipo le ON l.Id_Liga = le.Id_Liga AND le.Estado = 'activo'
                       WHERE l.Estado IN ('activa', 'inscripciones')
                       AND (l.CodigoPostal = ? OR LEFT(l.CodigoPostal, 3) = LEFT(?, 3))
                       GROUP BY l.Id_Liga
                       ORDER BY Prioridad, l.FechaInicio DESC
                       LIMIT 7";
    
    $stmt_sug = $conn->prepare($sql_sugerencias);
    if ($stmt_sug) {
        $stmt_sug->bind_param("ssss", $CodigoPostal, $CodigoPostal, $CodigoPostal, $CodigoPostal);
        $stmt_sug->execute();
        $res_sug = $stmt_sug->get_result();
        while ($fila = $res_sug->fetch_assoc()) {
            $ligasSugeridas[] = $fila;
        }
        $stmt_sug->close();
    }
}

// Procesar búsqueda
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['buscar_liga'])) {
        $busqueda = trim($_POST['busqueda']);
        $tipo_busqueda = $_POST['tipo_busqueda'];
        
        if (!empty($busqueda)) {
            $mostrarSugerencias = false;
            $mostrarSeleccionEquipo = false;
            
            if ($tipo_busqueda === 'id') {
                $sql_busqueda = "SELECT l.*, d.Nombre as DeporteNombre,
                               COUNT(le.Id_Equipo) as EquiposInscritos
                               FROM ligas l 
                               LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                               LEFT JOIN liga_equipo le ON l.Id_Liga = le.Id_Liga AND le.Estado = 'activo'
                               WHERE l.Id_Liga = ?
                               GROUP BY l.Id_Liga
                               LIMIT 20";
                $stmt = $conn->prepare($sql_busqueda);
                if ($stmt) {
                    $stmt->bind_param("s", $busqueda);
                    $stmt->execute();
                    $resultado = $stmt->get_result();
                    
                    if ($resultado->num_rows > 0) {
                        $ligasBuscadas = [$resultado->fetch_assoc()];
                    } else {
                        $mensajeError = "No se encontró ninguna liga con ese código";
                    }
                    $stmt->close();
                }
                
            } elseif ($tipo_busqueda === 'nombre') {
                $busquedaLike = "%" . $busqueda . "%";
                $sql_busqueda = "SELECT l.*, d.Nombre as DeporteNombre,
                               COUNT(le.Id_Equipo) as EquiposInscritos,
                               CASE 
                                   WHEN l.Nombre LIKE ? THEN 0
                                   WHEN l.Nombre LIKE ? THEN 1
                                   ELSE 2
                               END as Prioridad
                               FROM ligas l 
                               LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                               LEFT JOIN liga_equipo le ON l.Id_Liga = le.Id_Liga AND le.Estado = 'activo'
                               WHERE (l.Nombre LIKE ? OR l.Descripcion LIKE ?)
                               AND l.Estado IN ('activa', 'inscripciones')
                               GROUP BY l.Id_Liga
                               ORDER BY Prioridad, l.FechaInicio DESC
                               LIMIT 20";
                $stmt = $conn->prepare($sql_busqueda);
                if ($stmt) {
                    $busquedaExacta = $busqueda . "%";
                    $stmt->bind_param("ssss", $busquedaExacta, $busquedaLike, $busquedaLike, $busquedaLike);
                    $stmt->execute();
                    $resultado = $stmt->get_result();
                    
                    if ($resultado->num_rows > 0) {
                        while ($fila = $resultado->fetch_assoc()) {
                            $ligasBuscadas[] = $fila;
                        }
                    } else {
                        $mensajeError = "No se encontraron ligas con ese nombre";
                    }
                    $stmt->close();
                }
            }
        } else {
            $mensajeError = "Por favor ingresa un valor para buscar";
            $mostrarSugerencias = true;
        }
    }
    
    // Cancelar búsqueda
    if (isset($_POST['cancelar_busqueda'])) {
        $ligasBuscadas = [];
        $mostrarSugerencias = true;
        $mostrarSeleccionEquipo = false;
    }
    
    // Mostrar selección de equipo para una liga específica
    if (isset($_POST['seleccionar_equipo']) && isset($_POST['id_liga'])) {
        $idLiga = $_POST['id_liga'];
        
        // Obtener información de la liga
        $sql_liga = "SELECT l.*, d.Nombre as DeporteNombre 
                    FROM ligas l 
                    LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte 
                    WHERE l.Id_Liga = ?";
        $stmt_liga = $conn->prepare($sql_liga);
        if ($stmt_liga) {
            $stmt_liga->bind_param("s", $idLiga);
            $stmt_liga->execute();
            $result_liga = $stmt_liga->get_result();
            
            if ($result_liga->num_rows > 0) {
                $ligaSeleccionada = $result_liga->fetch_assoc();
                $deporteLiga = $ligaSeleccionada['Id_Deporte'];
                $mostrarSeleccionEquipo = true;
                
                // Verificar si el equipo ya está inscrito en esta liga
                $sql_verificar_inscripcion = "SELECT Id_LigaEquipo FROM liga_equipo 
                                            WHERE Id_Liga = ? AND Id_Equipo IN (
                                                SELECT Id_Equipo FROM equipo_jugador 
                                                WHERE Id_Jugador = ?
                                            )";
                $stmt_ver = $conn->prepare($sql_verificar_inscripcion);
                if ($stmt_ver) {
                    $stmt_ver->bind_param("ss", $idLiga, $Id_Retador);
                    $stmt_ver->execute();
                    $stmt_ver->store_result();
                    
                    if ($stmt_ver->num_rows > 0) {
                        $mensajeError = "Ya tienes un equipo inscrito en esta liga.";
                        $mostrarSeleccionEquipo = false;
                    }
                    $stmt_ver->close();
                }
                
                // Obtener equipos del usuario que coinciden con el deporte de la liga
                if ($mostrarSeleccionEquipo) {
                    // Primero verificar si el usuario tiene equipos del deporte correcto
                    $sql_verificar_equipos = "SELECT DISTINCT e.Id_Equipo, e.Nombre 
                                             FROM equipo e 
                                             LEFT JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo 
                                             WHERE (e.Capitan = ? OR ej.Id_Jugador = ?)
                                             AND e.Id_Deporte = ?";
                    $stmt_ver_eq = $conn->prepare($sql_verificar_equipos);
                    $stmt_ver_eq->bind_param("sss", $Id_Retador, $Id_Retador, $deporteLiga);
                    $stmt_ver_eq->execute();
                    $res_ver_eq = $stmt_ver_eq->get_result();
                    $tieneEquiposDeporte = ($res_ver_eq->num_rows > 0);
                    $stmt_ver_eq->close();
                    
                    if ($tieneEquiposDeporte) {
                        // Ahora obtener solo equipos donde el usuario tiene permiso (Capitán, Entrenador o Asistente)
                        $sql_equipos_compatibles = "SELECT DISTINCT e.Id_Equipo, e.Nombre, e.Id_Deporte, 
                                                   d.Nombre as DeporteNombre,
                                                   (SELECT COUNT(*) FROM equipo_jugador WHERE Id_Equipo = e.Id_Equipo) as Miembros,
                                                   e.cantidad as Capacidad,
                                                   CASE 
                                                       WHEN e.Capitan = ? THEN 'Capitán'
                                                       WHEN e.Entrenador = ? THEN 'Entrenador'
                                                       WHEN e.Asistente1 = ? OR e.Asistente2 = ? OR e.Asistente3 = ? OR 
                                                            e.Asistente4 = ? THEN 'Asistente'
                                                       ELSE 'Miembro'
                                                   END as Rol
                                                   FROM equipo e 
                                                   LEFT JOIN deporte d ON e.Id_Deporte = d.Id_Deporte
                                                   LEFT JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo 
                                                   WHERE (e.Capitan = ? OR e.Entrenador = ? OR 
                                                         e.Asistente1 = ? OR e.Asistente2 = ? OR e.Asistente3 = ? OR 
                                                         e.Asistente4 = ? OR ej.Id_Jugador = ?)
                                                   AND e.Id_Deporte = ?
                                                   GROUP BY e.Id_Equipo
                                                   HAVING Miembros >= 1";
                        
                        $stmt_eq_comp = $conn->prepare($sql_equipos_compatibles);
                        if ($stmt_eq_comp) {
                            $stmt_eq_comp->bind_param("ssssssssssssss", 
                                $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador,
                                $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador,
                                $Id_Retador, $deporteLiga);
                            $stmt_eq_comp->execute();
                            $res_eq_comp = $stmt_eq_comp->get_result();
                            
                            if ($res_eq_comp->num_rows > 0) {
                                while ($fila = $res_eq_comp->fetch_assoc()) {
                                    $equiposCompatibles[] = $fila;
                                }
                            } else {
                                $mensajeError = "⛔ No tienes permiso para registrar equipos en esta liga.<br><br>
                                               <strong>Necesitas ser:</strong><br>
                                               • Capitán del equipo<br>
                                               • Entrenador del equipo<br>
                                               • Asistente (1-4) del equipo<br><br>
                                               Contacta al líder de tu equipo para que te asigne uno de estos roles.";
                            }
                            $stmt_eq_comp->close();
                        }
                    } else {
                        $mensajeError = "No tienes equipos que jueguen " . htmlspecialchars($ligaSeleccionada['DeporteNombre'] ?? 'este deporte') . ". 
                                       Debes unirte o crear un equipo de " . htmlspecialchars($ligaSeleccionada['DeporteNombre'] ?? 'este deporte') . " primero.";
                    }
                }
            } else {
                $mensajeError = "La liga seleccionada no existe.";
            }
            $stmt_liga->close();
        }
    }
    
    // Cancelar selección de equipo
    if (isset($_POST['cancelar_seleccion'])) {
        $mostrarSeleccionEquipo = false;
        $ligaSeleccionada = null;
        $equiposCompatibles = [];
    }
    
    // Procesar inscripción del equipo a la liga (MODIFICADO PARA USAR TABLA solicitudes)
    if (isset($_POST['confirmar_inscripcion']) && isset($_POST['id_liga']) && isset($_POST['id_equipo'])) {
        $idLiga = $_POST['id_liga'];
        $idEquipo = $_POST['id_equipo'];
        
        // Verificar que el usuario tiene permiso (Capitán, Entrenador o Asistente)
        $sql_verificar_permiso = "SELECT Id_Equipo FROM equipo 
                                WHERE Id_Equipo = ? AND 
                                (Capitan = ? OR Entrenador = ? OR 
                                 Asistente1 = ? OR Asistente2 = ? OR Asistente3 = ? OR 
                                 Asistente4 = ?)";
        $stmt_ver_perm = $conn->prepare($sql_verificar_permiso);
        $stmt_ver_perm->bind_param("sssssss", $idEquipo, $Id_Retador, $Id_Retador, 
                                   $Id_Retador, $Id_Retador, $Id_Retador, $Id_Retador);
        $stmt_ver_perm->execute();
        $stmt_ver_perm->store_result();
        
        if ($stmt_ver_perm->num_rows === 0) {
            $mensajeError = "⛔ No tienes permiso para registrar este equipo en la liga.<br>
                           <strong>Necesitas ser Capitán, Entrenador o Asistente del equipo.</strong><br>
                           Contacta al líder de tu equipo para obtener autorización.";
            $stmt_ver_perm->close();
        } else {
            $stmt_ver_perm->close();
            
            // Verificar que el usuario pertenece al equipo (como miembro)
            $sql_verificar_equipo = "SELECT Id_Equipo FROM equipo_jugador 
                                    WHERE Id_Equipo = ? AND Id_Jugador = ?";
            $stmt_ver_eq = $conn->prepare($sql_verificar_equipo);
            $stmt_ver_eq->bind_param("ss", $idEquipo, $Id_Retador);
            $stmt_ver_eq->execute();
            $stmt_ver_eq->store_result();
            
            if ($stmt_ver_eq->num_rows === 0) {
                $mensajeError = "No perteneces a este equipo o el equipo no existe.";
            } else {
                // Verificar que no haya una solicitud pendiente para este equipo y liga
                $sql_verificar_solicitud = "SELECT id_solicitud FROM solicitudes 
                                          WHERE id_solicitante = ? AND id_areadesolicitud = ? 
                                          AND estado = 'En proceso'";
                $stmt_ver_sol = $conn->prepare($sql_verificar_solicitud);
                $stmt_ver_sol->bind_param("ss", $idEquipo, $idLiga);
                $stmt_ver_sol->execute();
                $stmt_ver_sol->store_result();
                
                if ($stmt_ver_sol->num_rows > 0) {
                    $mensajeError = "Ya existe una solicitud pendiente para este equipo en esta liga.";
                } else {
                    // Generar id_solicitud según el formato requerido (fecha + 2 números random)
                    $fecha_actual = date('Ymd'); // Formato: YYYYMMDD
                    $numeros_random = sprintf("%02d", mt_rand(0, 99)); // 2 dígitos con ceros a la izquierda
                    $id_solicitud = $fecha_actual . $numeros_random;
                    
                    // Insertar en tabla solicitudes (NUEVO CÓDIGO)
                    $sql_insertar_solicitud = "INSERT INTO solicitudes 
                                              (id_solicitud, id_solicitante, id_areadesolicitud, fecha, estado) 
                                              VALUES (?, ?, ?, CURDATE(), 'En proceso')";
                    $stmt_ins = $conn->prepare($sql_insertar_solicitud);
                    $stmt_ins->bind_param("sss", $id_solicitud, $idEquipo, $idLiga);
                    
                    if ($stmt_ins->execute()) {
                        $mensajeExito = "✅ Solicitud enviada correctamente. 
                                       <br><br>
                                       <strong>📋 Detalles de la solicitud:</strong>
                                       <br>• ID Solicitud: <strong>{$id_solicitud}</strong>
                                       <br>• Equipo solicitante: <strong>{$idEquipo}</strong>
                                       <br>• Liga solicitada: <strong>{$idLiga}</strong>
                                       <br>• Fecha: <strong>" . date('d/m/Y') . "</strong>
                                       <br>• Estado: <strong>En proceso</strong>
                                       <br><br>
                                       ⏳ La solicitud será revisada por el administrador de la liga.";
                        
                        // Resetear variables para volver a la búsqueda
                        $mostrarSeleccionEquipo = false;
                        $ligaSeleccionada = null;
                        $equiposCompatibles = [];
                        $ligasBuscadas = [];
                        $mostrarSugerencias = true;
                    } else {
                        // Si hay error de clave duplicada (id_solicitud), generar uno nuevo
                        if ($stmt_ins->errno == 1062) { // Error de clave duplicada
                            // Intentar con otro ID
                            $id_solicitud = $fecha_actual . sprintf("%02d", mt_rand(0, 99));
                            $stmt_ins->bind_param("sss", $id_solicitud, $idEquipo, $idLiga);
                            
                            if ($stmt_ins->execute()) {
                                $mensajeExito = "✅ Solicitud enviada correctamente. 
                                               <br><br>
                                               <strong>📋 Detalles de la solicitud:</strong>
                                               <br>• ID Solicitud: <strong>{$id_solicitud}</strong>
                                               <br>• Equipo solicitante: <strong>{$idEquipo}</strong>
                                               <br>• Liga solicitada: <strong>{$idLiga}</strong>
                                               <br>• Fecha: <strong>" . date('d/m/Y') . "</strong>
                                               <br>• Estado: <strong>En proceso</strong>
                                               <br><br>
                                               ⏳ La solicitud será revisada por el administrador de la liga.";
                                
                                // Resetear variables para volver a la búsqueda
                                $mostrarSeleccionEquipo = false;
                                $ligaSeleccionada = null;
                                $equiposCompatibles = [];
                                $ligasBuscadas = [];
                                $mostrarSugerencias = true;
                            } else {
                                $mensajeError = "Error al enviar la solicitud: " . $stmt_ins->error;
                            }
                        } else {
                            $mensajeError = "Error al enviar la solicitud: " . $stmt_ins->error;
                        }
                    }
                    $stmt_ins->close();
                }
                $stmt_ver_sol->close();
            }
            $stmt_ver_eq->close();
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
    <title>Unirse a Liga - RETAME</title>
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
        position: relative;
    }

    /* BURBUJAS DE FONDO */
    .bg-particles {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
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
        position: relative;
        z-index: 1;
        width: calc(100% - 250px);
    }

    .main-content h1 {
        font-size:32px;
        color:#ffffff;
        margin-bottom:30px;
        text-align:center;
    }

    /* TARJETAS */
    .card {
        background:rgba(27,31,39,0.9);
        padding:30px;
        border-radius:20px;
        box-shadow:0 0 30px rgba(0,26,255,0.5);
        margin-bottom:30px;
        backdrop-filter:blur(10px);
        border:1px solid rgba(0,26,255,0.2);
        animation: fadeInUp 0.5s ease-out;
    }

    @keyframes fadeInUp {
        from { opacity:0; transform:translateY(20px); }
        to { opacity:1; transform:translateY(0); }
    }

    .card h2 {
        color:#00ffc6;
        margin-bottom:25px;
        font-size:24px;
        border-bottom:2px solid rgba(0,255,198,0.3);
        padding-bottom:10px;
    }

    /* BÚSQUEDA */
    .search-section {
        background:rgba(27,31,39,0.9);
        padding:30px;
        border-radius:20px;
        box-shadow:0 0 30px rgba(0,26,255,0.5);
        margin-bottom:30px;
        backdrop-filter:blur(10px);
        border:1px solid rgba(0,26,255,0.2);
    }

    .search-header {
        display:flex;
        align-items:center;
        gap:15px;
        margin-bottom:20px;
    }

    .search-icon {
        width:50px;
        height:50px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:1.5rem;
        background:rgba(0,26,255,0.2);
        color:#001aff;
    }

    .search-type {
        display:flex;
        gap:15px;
        margin-bottom:20px;
    }

    .search-option {
        flex:1;
        text-align:center;
    }

    .search-option input[type="radio"] {
        display:none;
    }

    .search-option label {
        display:block;
        padding:12px;
        background:rgba(255,255,255,0.05);
        border:2px solid rgba(255,255,255,0.1);
        border-radius:12px;
        cursor:pointer;
        transition:all 0.3s ease;
        font-weight:500;
        color:#eee;
    }

    .search-option input[type="radio"]:checked + label {
        background:rgba(0,26,255,0.3);
        border-color:#001aff;
        transform:translateY(-3px);
    }

    .search-input-group {
        display:flex;
        gap:10px;
        margin-bottom:20px;
    }

    .search-input {
        flex:1;
        padding:15px 20px;
        background:rgba(255,255,255,0.08);
        border:2px solid rgba(255,255,255,0.2);
        border-radius:12px;
        color:#eee;
        font-size:1rem;
        transition:all 0.3s ease;
    }

    .search-input:focus {
        outline:none;
        border-color:#00ffc6;
        box-shadow:0 0 15px rgba(0,255,198,0.3);
    }

    .search-button {
        padding:15px 30px;
        background:linear-gradient(135deg,#001aff,#0015cc);
        border:none;
        border-radius:12px;
        color:#fff;
        font-weight:600;
        cursor:pointer;
        transition:all 0.3s ease;
    }

    .search-button:hover {
        transform:translateY(-3px);
        box-shadow:0 10px 25px rgba(0,26,255,0.4);
    }

    .cancel-button {
        padding:15px 25px;
        background:rgba(255,255,255,0.1);
        border:2px solid rgba(255,255,255,0.2);
        border-radius:12px;
        color:#fff;
        font-weight:600;
        cursor:pointer;
        transition:all 0.3s ease;
    }

    .cancel-button:hover {
        background:rgba(255,0,0,0.2);
        border-color:#ff0000;
    }

    /* INFORMACIÓN USUARIO */
    .user-info {
        background:rgba(0,255,198,0.05);
        border-radius:15px;
        padding:20px;
        margin-top:20px;
        border:1px solid rgba(0,255,198,0.2);
    }

    .info-row {
        display:flex;
        align-items:center;
        gap:15px;
        margin-bottom:10px;
    }

    .info-label {
        color:#00ffc6;
        font-weight:600;
        min-width:120px;
    }

    .info-value {
        color:#fff;
    }

    /* LISTA DE LIGAS */
    .ligas-container {
        display:flex;
        flex-direction:column;
        gap:20px;
        max-height:600px;
        overflow-y:auto;
        padding-right:10px;
    }

    .ligas-container::-webkit-scrollbar {
        width:8px;
    }

    .ligas-container::-webkit-scrollbar-track {
        background:rgba(255,255,255,0.05);
        border-radius:10px;
    }

    .ligas-container::-webkit-scrollbar-thumb {
        background:#001aff;
        border-radius:10px;
    }

    /* TARJETA DE LIGA */
    .liga-card {
        background:rgba(255,255,255,0.05);
        border-radius:15px;
        padding:25px;
        border-left:4px solid #001aff;
        transition:all 0.3s ease;
        position:relative;
    }

    .liga-card:hover {
        background:rgba(255,255,255,0.08);
        transform:translateX(5px);
        box-shadow:0 8px 25px rgba(0,0,0,0.2);
    }

    .liga-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        margin-bottom:15px;
    }

    .liga-title {
        flex:1;
    }

    .liga-nombre {
        font-size:1.4rem;
        font-weight:600;
        color:#fff;
        margin-bottom:5px;
    }

    .liga-id {
        background:rgba(0,26,255,0.1);
        padding:4px 10px;
        border-radius:15px;
        font-size:0.8rem;
        color:#001aff;
        display:inline-block;
    }

    .liga-estado {
        padding:6px 12px;
        border-radius:20px;
        font-size:0.85rem;
        font-weight:600;
    }

    .estado-activa {
        background:rgba(0,255,198,0.1);
        color:#00ffc6;
        border:1px solid rgba(0,255,198,0.3);
    }

    .estado-inscripciones {
        background:rgba(255,193,7,0.1);
        color:#ffc107;
        border:1px solid rgba(255,193,7,0.3);
    }

    .liga-details {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));
        gap:15px;
        margin-bottom:20px;
    }

    .detail-item {
        font-size:0.95rem;
    }

    .detail-label {
        color:#aaaaaa;
        display:block;
        font-size:0.85rem;
        margin-bottom:4px;
    }

    .detail-value {
        color:#fff;
        font-weight:500;
    }

    .liga-actions {
        display:flex;
        gap:10px;
        margin-top:15px;
    }

    .action-button {
        flex:1;
        padding:12px;
        border:none;
        border-radius:10px;
        font-weight:600;
        cursor:pointer;
        transition:all 0.3s ease;
        text-align:center;
    }

    .btn-detalles {
        background:linear-gradient(135deg,#9d00ff,#7a00cc);
        color:#fff;
    }

    .btn-detalles:hover {
        transform:translateY(-2px);
        box-shadow:0 5px 15px rgba(157,0,255,0.3);
    }

    .btn-unirse {
        background:linear-gradient(135deg,#00ffc6,#00cc9d);
        color:#000;
    }

    .btn-unirse:hover {
        transform:translateY(-2px);
        box-shadow:0 5px 15px rgba(0,255,198,0.3);
    }

    /* BADGE DE SUGERENCIA */
    .sugerencia-badge {
        position:absolute;
        top:-10px;
        right:-10px;
        background:linear-gradient(135deg,#ff9800,#ff5722);
        color:white;
        padding:4px 12px;
        border-radius:20px;
        font-size:0.75rem;
        font-weight:600;
        box-shadow:0 3px 10px rgba(255,152,0,0.3);
    }

    /* ALERTAS */
    .alert {
        padding:20px;
        border-radius:12px;
        margin-bottom:20px;
        animation:fadeIn 0.5s ease;
    }

    .alert-success {
        background:rgba(0,255,198,0.1);
        border-left:4px solid #00ffc6;
        color:#00ffc6;
    }

    .alert-error {
        background:rgba(255,0,0,0.1);
        border-left:4px solid #ff0000;
        color:#ff6b6b;
    }

    .alert-info {
        background:rgba(0,100,255,0.1);
        border-left:4px solid #0064ff;
        color:#64b5f6;
    }

    /* NO RESULTADOS */
    .no-results {
        text-align:center;
        padding:50px 20px;
        color:#aaaaaa;
    }

    .no-results-icon {
        font-size:3.5rem;
        margin-bottom:20px;
        opacity:0.5;
    }

    .results-count {
        color:#00ffc6;
        font-size:1.1rem;
        margin-bottom:20px;
        padding:10px 15px;
        background:rgba(0,255,198,0.1);
        border-radius:10px;
        display:inline-block;
    }

    /* EQUIPOS USUARIO */
    .equipos-section {
        margin-top:40px;
    }

    .equipos-grid {
        display:grid;
        grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));
        gap:15px;
        margin-top:20px;
    }

    .equipo-card {
        background:rgba(255,255,255,0.05);
        border-radius:12px;
        padding:15px;
        text-align:center;
        border:1px solid rgba(0,255,198,0.2);
    }

    .equipo-nombre {
        font-weight:600;
        color:#fff;
        margin-bottom:8px;
    }

    .equipo-rol {
        font-size:0.85rem;
        color:#00ffc6;
        background:rgba(0,255,198,0.1);
        padding:3px 10px;
        border-radius:15px;
        display:inline-block;
    }

    /* SECCIÓN DE SELECCIÓN DE EQUIPO */
    .seleccion-equipo-section {
        background: rgba(27, 31, 39, 0.95);
        border-radius: 20px;
        padding: 30px;
        margin-bottom: 30px;
        border: 2px solid #00ffc6;
        box-shadow: 0 0 30px rgba(0, 255, 198, 0.3);
        animation: slideInRight 0.5s ease-out;
    }
    
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(50px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .liga-info-box {
        background: rgba(0, 26, 255, 0.1);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid rgba(0, 26, 255, 0.3);
    }
    
    .liga-info-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .liga-info-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #001aff, #0015cc);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        color: white;
    }
    
    .equipos-compatibles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 25px;
    }
    
    .equipo-compatible-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 15px;
        padding: 20px;
        border: 2px solid rgba(0, 255, 198, 0.2);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    
    .equipo-compatible-card:hover {
        background: rgba(0, 255, 198, 0.05);
        transform: translateY(-5px);
        border-color: #00ffc6;
        box-shadow: 0 10px 25px rgba(0, 255, 198, 0.2);
    }
    
    .equipo-compatible-card.selected {
        background: rgba(0, 255, 198, 0.1);
        border-color: #00ffc6;
        box-shadow: 0 0 20px rgba(0, 255, 198, 0.3);
    }
    
    .equipo-compatible-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .equipo-compatible-nombre {
        font-size: 1.3rem;
        font-weight: 600;
        color: #fff;
    }
    
    .equipo-compatible-rol {
        background: rgba(0, 255, 198, 0.2);
        color: #00ffc6;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .equipo-compatible-details {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .equipo-detail {
        font-size: 0.9rem;
    }
    
    .equipo-detail-label {
        color: #aaaaaa;
        display: block;
        font-size: 0.85rem;
        margin-bottom: 3px;
    }
    
    .equipo-detail-value {
        color: #fff;
        font-weight: 500;
    }
    
    .radio-equipo {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .radio-equipo-label {
        display: block;
        width: 100%;
        cursor: pointer;
    }
    
    .radio-checkmark {
        position: absolute;
        top: 15px;
        right: 15px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.3);
        background: rgba(255, 255, 255, 0.05);
        transition: all 0.3s ease;
    }
    
    .equipo-compatible-card.selected .radio-checkmark {
        background: #00ffc6;
        border-color: #00ffc6;
    }
    
    .equipo-compatible-card.selected .radio-checkmark::after {
        content: '✓';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: #000;
        font-weight: bold;
        font-size: 14px;
    }
    
    .botones-seleccion {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .btn-cancelar-seleccion {
        padding: 15px 25px;
        background: rgba(255, 255, 255, 0.1);
        border: 2px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        flex: 1;
        text-align: center;
    }
    
    .btn-cancelar-seleccion:hover {
        background: rgba(255, 0, 0, 0.2);
        border-color: #ff0000;
        transform: translateY(-3px);
    }
    
    .btn-confirmar-inscripcion {
        padding: 15px 25px;
        background: linear-gradient(135deg, #00ffc6, #00cc9d);
        border: none;
        border-radius: 12px;
        color: #000;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        flex: 2;
    }
    
    .btn-confirmar-inscripcion:hover:not(:disabled) {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(0, 255, 198, 0.4);
    }
    
    .btn-confirmar-inscripcion:disabled {
        background: #666;
        cursor: not-allowed;
        opacity: 0.6;
    }
    
    /* SIN EQUIPOS COMPATIBLES */
    .no-equipos-compatibles {
        text-align: center;
        padding: 40px 20px;
        color: #ff9800;
        background: rgba(255, 152, 0, 0.1);
        border-radius: 15px;
        margin-top: 20px;
        border: 1px solid rgba(255, 152, 0, 0.3);
    }
    
    .no-equipos-icon {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.7;
    }
    
    /* INDICADOR DE SELECCIÓN */
    .selection-indicator {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #00ffc6;
        color: #000;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
        opacity: 0;
        transform: scale(0.5);
        transition: all 0.3s ease;
    }
    
    .equipo-compatible-card.selected .selection-indicator {
        opacity: 1;
        transform: scale(1);
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

    /* RESPONSIVE */
    @media screen and (max-width: 1024px) {
        .sidebar { width: 200px; }
        .main-content { 
            margin-left: 200px; 
            width: calc(100% - 200px);
            padding: 20px; 
        }
        .equipos-compatibles-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
        .sidebar.active { left: 0; }
        .main-content { 
            margin-left: 0; 
            width: 100%;
            padding: 20px; 
        }
        
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
        
        .liga-details { grid-template-columns: 1fr; }
        .search-type { flex-direction: column; gap: 10px; }
        .search-input-group { flex-direction: column; }
        .equipos-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        .equipos-compatibles-grid { grid-template-columns: 1fr; }
        .botones-seleccion { flex-direction: column; }
        .equipo-compatible-details { grid-template-columns: 1fr; }
        
        .btn-retar, .btn-ligas {
            width: 70px;
            height: 70px;
            font-size: 14px;
            bottom: 15px;
            right: 15px;
        }
        .btn-ligas { bottom: 100px; }
    }

    @media screen and (max-width: 480px) {
        .card { padding: 20px; }
        .main-content { padding: 15px; }
        .liga-actions { flex-direction: column; }
        .alert { padding: 15px; }
        .seleccion-equipo-section { padding: 20px; }
        .equipo-compatible-card { padding: 15px; }
    }
    </style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Unirse a Liga - RETAME'); } ?>

<!-- BURBUJAS DE FONDO -->
<div class="bg-particles" id="particles"></div>

<!-- Botón hamburguesa para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar -->
<div class="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <img src="../assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏆 RETAME</h2>
    <ul class="menu">
        <li><a href="../Perfil2.php">🏠 Inicio</a></li>
        <li><a href="UnirmeLiga.php">👥 Unirme a una liga</a></li>
        <li><a href="CrearLiga.php">⭐ Crear Una Liga</a></li>
        <li><a href="../Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="../logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- CONTENIDO PRINCIPAL -->
<div class="main-content">
    <h1>🏆 Unirse a una Liga</h1>
    
    <!-- Mostrar alertas -->
    <?php if ($mensajeError): ?>
        <div class="alert alert-error">
            <strong>⚠️ Error:</strong> <?php echo $mensajeError; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($mensajeExito): ?>
        <div class="alert alert-success">
            <strong>✅ Éxito:</strong> <?php echo $mensajeExito; ?>
        </div>
    <?php endif; ?>
    
    <!-- SECCIÓN DE SELECCIÓN DE EQUIPO (solo se muestra si hay una liga seleccionada) -->
    <?php if ($mostrarSeleccionEquipo && $ligaSeleccionada): ?>
    <div class="seleccion-equipo-section">
        <div class="liga-info-box">
            <div class="liga-info-header">
                <div class="liga-info-icon">🏆</div>
                <div>
                    <h2 style="color: #00ffc6; margin-bottom: 5px;">Selecciona tu equipo</h2>
                    <p style="color: #cccccc;">Elige el equipo que deseas inscribir en la liga</p>
                </div>
            </div>
            
            <div class="liga-details">
                <div class="detail-item">
                    <span class="detail-label">🏆 Liga:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($ligaSeleccionada['Nombre']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">🎯 Deporte:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($ligaSeleccionada['DeporteNombre'] ?? 'No especificado'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">📍 Código Postal:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($ligaSeleccionada['CodigoPostal']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">📅 Inicio:</span>
                    <span class="detail-value"><?php echo date('d/m/Y', strtotime($ligaSeleccionada['FechaInicio'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">👥 Equipos Inscritos:</span>
                    <span class="detail-value"><?php echo isset($ligaSeleccionada['CantidadEquipos']) ? htmlspecialchars($ligaSeleccionada['CantidadEquipos']) : 'Sin límite'; ?></span>
                </div>
            </div>
        </div>
        
        <?php if (!empty($equiposCompatibles)): ?>
            <h3 style="color: #fff; margin-bottom: 20px;">✅ Equipos compatibles con esta liga:</h3>
            <p style="color: #aaaaaa; margin-bottom: 15px;">Selecciona un equipo para inscribirlo en la liga:</p>
            
            <form method="POST" action="" id="formSeleccionEquipo">
                <input type="hidden" name="id_liga" value="<?php echo htmlspecialchars($ligaSeleccionada['Id_Liga']); ?>">
                
                <div class="equipos-compatibles-grid">
                    <?php foreach ($equiposCompatibles as $equipo): ?>
                    <div class="equipo-compatible-card" onclick="seleccionarEquipo('<?php echo htmlspecialchars($equipo['Id_Equipo']); ?>')">
                        <input type="radio" 
                               class="radio-equipo" 
                               id="equipo_<?php echo htmlspecialchars($equipo['Id_Equipo']); ?>" 
                               name="id_equipo" 
                               value="<?php echo htmlspecialchars($equipo['Id_Equipo']); ?>"
                               required>
                        <label for="equipo_<?php echo htmlspecialchars($equipo['Id_Equipo']); ?>" class="radio-equipo-label">
                            <div class="radio-checkmark"></div>
                            <div class="selection-indicator">✓</div>
                            
                            <div class="equipo-compatible-header">
                                <div class="equipo-compatible-nombre"><?php echo htmlspecialchars($equipo['Nombre']); ?></div>
                                <div class="equipo-compatible-rol"><?php echo htmlspecialchars($equipo['Rol']); ?></div>
                            </div>
                            
                            <div class="equipo-compatible-details">
                                <div class="equipo-detail">
                                    <span class="equipo-detail-label">🎯 Deporte:</span>
                                    <span class="equipo-detail-value"><?php echo htmlspecialchars($equipo['DeporteNombre'] ?? 'No especificado'); ?></span>
                                </div>
                                <div class="equipo-detail">
                                    <span class="equipo-detail-label">👥 Miembros:</span>
                                    <span class="equipo-detail-value"><?php echo $equipo['Miembros']; ?> / <?php echo $equipo['Capacidad']; ?></span>
                                </div>
                                <div class="equipo-detail">
                                    <span class="equipo-detail-label">🎖️ Permiso:</span>
                                    <span class="equipo-detail-value" style="color: #00ffc6;">✅ Autorizado</span>
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin-top: 10px;">
                                <span style="color: #00ffc6; font-weight: 600;">⬅️ Haz clic para seleccionar</span>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="botones-seleccion">
                    <button type="submit" name="cancelar_seleccion" class="btn-cancelar-seleccion">
                        ❌ Cancelar
                    </button>
                    <button type="submit" name="confirmar_inscripcion" class="btn-confirmar-inscripcion" id="btnConfirmar" disabled>
                        ✅ Enviar Solicitud de Inscripción
                    </button>
                </div>
            </form>
            
        <?php else: ?>
            <div class="no-equipos-compatibles">
                <div class="no-equipos-icon">⚠️</div>
                <h3>No tienes equipos compatibles</h3>
                <p>No tienes equipos que jueguen <strong><?php echo htmlspecialchars($ligaSeleccionada['DeporteNombre'] ?? 'este deporte'); ?></strong> o no tienes permiso para registrarlos.</p>
                <p style="margin-top: 15px;">
                    <strong>⚠️ Requisitos:</strong><br>
                    1. Debes ser <strong>Capitán, Entrenador o Asistente</strong> del equipo<br>
                    2. El equipo debe jugar <strong><?php echo htmlspecialchars($ligaSeleccionada['DeporteNombre'] ?? 'este deporte'); ?></strong>
                </p>
                <form method="POST" action="" style="margin-top: 20px;">
                    <button type="submit" name="cancelar_seleccion" class="btn-cancelar-seleccion">
                        ↩️ Volver a la búsqueda
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- SECCIÓN DE BÚSQUEDA (solo se muestra si no hay selección de equipo activa) -->
    <?php if (!$mostrarSeleccionEquipo): ?>
    <div class="search-section">
        <div class="search-header">
            <div class="search-icon">🔍</div>
            <div>
                <h2>Buscar Liga</h2>
                <p style="color: #cccccc; margin-top: 5px;">Encuentra ligas para inscribir tu equipo</p>
            </div>
        </div>
        
        <!-- Información del usuario -->
        <?php if (!empty($CodigoPostal)): ?>
        <div class="user-info">
            <div class="info-row">
                <span class="info-label">📮 Tu código postal:</span>
                <span class="info-value"><?php echo htmlspecialchars($CodigoPostal); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">📍 Ubicación sugerida:</span>
                <span class="info-value">Mostrando ligas cercanas a tu ubicación</span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Formulario de búsqueda -->
        <form method="POST" action="">
            <div class="search-type">
                <div class="search-option">
                    <input type="radio" id="search-id" name="tipo_busqueda" value="id" 
                           <?php echo (isset($_POST['tipo_busqueda']) && $_POST['tipo_busqueda'] === 'id') ? 'checked' : ''; ?>>
                    <label for="search-id">🔢 Por Código de Liga</label>
                </div>
                <div class="search-option">
                    <input type="radio" id="search-name" name="tipo_busqueda" value="nombre"
                           <?php echo (isset($_POST['tipo_busqueda']) && $_POST['tipo_busqueda'] === 'nombre') ? 'checked' : 'checked'; ?>>
                    <label for="search-name">📝 Por Nombre de Liga</label>
                </div>
            </div>
            
            <div class="search-input-group">
                <input type="text" 
                       id="busqueda" 
                       name="busqueda" 
                       class="search-input"
                       placeholder="Ingresa el código o nombre de la liga"
                       value="<?php echo isset($_POST['busqueda']) ? htmlspecialchars($_POST['busqueda']) : ''; ?>"
                       required>
                <button type="submit" name="buscar_liga" class="search-button">Buscar</button>
                
                <?php if (!empty($ligasBuscadas) || !$mostrarSugerencias): ?>
                    <button type="submit" name="cancelar_busqueda" class="cancel-button">Cancelar</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- SECCIÓN DE RESULTADOS -->
    <div class="card">
        <?php if (!empty($ligasBuscadas)): ?>
            <!-- Mostrar resultados de búsqueda -->
            <h2>🔍 Resultados de Búsqueda</h2>
            <div class="results-count">
                <?php 
                $count = count($ligasBuscadas);
                echo $count . " liga" . ($count !== 1 ? 's' : '') . " encontrada" . ($count !== 1 ? 's' : '');
                ?>
            </div>
            
            <div class="ligas-container">
                <?php foreach ($ligasBuscadas as $liga): ?>
                    <div class="liga-card">
                        <div class="liga-header">
                            <div class="liga-title">
                                <div class="liga-nombre"><?php echo htmlspecialchars($liga['Nombre']); ?></div>
                                <span class="liga-id">ID: <?php echo htmlspecialchars($liga['Id_Liga']); ?></span>
                            </div>
                            <div class="liga-estado <?php echo 'estado-' . strtolower($liga['Estado']); ?>">
                                <?php echo ucfirst($liga['Estado']); ?>
                            </div>
                        </div>
                        
                        <div class="liga-details">
                            <div class="detail-item">
                                <span class="detail-label">🎯 Deporte:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($liga['DeporteNombre'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📍 Código Postal:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($liga['CodigoPostal']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📅 Inicio:</span>
                                <span class="detail-value"><?php echo date('d/m/Y', strtotime($liga['FechaInicio'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">🏆 Equipos Inscritos:</span>
                                <span class="detail-value"><?php echo $liga['EquiposInscritos']; ?> equipos</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">👤 Creador:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($liga['Id_Creador']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📝 Descripción:</span>
                                <span class="detail-value"><?php echo htmlspecialchars(substr($liga['Descripcion'] ?? 'Sin descripción', 0, 100)) . '...'; ?></span>
                            </div>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="id_liga" value="<?php echo htmlspecialchars($liga['Id_Liga']); ?>">
                            <div class="liga-actions">
                                <button type="button" class="action-button btn-detalles" onclick="verDetalles('<?php echo htmlspecialchars($liga['Id_Liga']); ?>')">
                                    👁️ Ver Detalles
                                </button>
                                <button type="submit" name="seleccionar_equipo" class="action-button btn-unirse">
                                    ✅ Inscribir Equipo
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php elseif ($mostrarSugerencias && !empty($ligasSugeridas)): ?>
            <!-- Mostrar sugerencias por código postal -->
            <h2>📍 Ligas Cercanas a tu Ubicación</h2>
            <div class="results-count">
                <?php 
                $count = count($ligasSugeridas);
                echo $count . " sugerencia" . ($count !== 1 ? 's' : '') . " basada" . ($count !== 1 ? 's' : '') . " en tu código postal";
                ?>
            </div>
            
            <div class="ligas-container">
                <?php foreach ($ligasSugeridas as $index => $liga): ?>
                    <div class="liga-card">
                        <?php if ($liga['CodigoPostal'] === $CodigoPostal): ?>
                            <div class="sugerencia-badge">📍 Mismo CP</div>
                        <?php elseif (substr($liga['CodigoPostal'], 0, 3) === substr($CodigoPostal, 0, 3)): ?>
                            <div class="sugerencia-badge">📍 Zona cercana</div>
                        <?php endif; ?>
                        
                        <div class="liga-header">
                            <div class="liga-title">
                                <div class="liga-nombre"><?php echo htmlspecialchars($liga['Nombre']); ?></div>
                                <span class="liga-id">ID: <?php echo htmlspecialchars($liga['Id_Liga']); ?></span>
                            </div>
                            <div class="liga-estado <?php echo 'estado-' . strtolower($liga['Estado']); ?>">
                                <?php echo ucfirst($liga['Estado']); ?>
                            </div>
                        </div>
                        
                        <div class="liga-details">
                            <div class="detail-item">
                                <span class="detail-label">🎯 Deporte:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($liga['DeporteNombre'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📍 Código Postal:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($liga['CodigoPostal']); ?>
                                    <?php if ($liga['CodigoPostal'] === $CodigoPostal): ?>
                                        <span style="color:#00ffc6; font-size:0.8rem;">(igual al tuyo)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">📅 Inicio:</span>
                                <span class="detail-value"><?php echo date('d/m/Y', strtotime($liga['FechaInicio'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">🏆 Equipos Inscritos:</span>
                                <span class="detail-value"><?php echo $liga['EquiposInscritos']; ?> equipos</span>
                            </div>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="id_liga" value="<?php echo htmlspecialchars($liga['Id_Liga']); ?>">
                            <div class="liga-actions">
                                <button type="button" class="action-button btn-detalles" onclick="verDetalles('<?php echo htmlspecialchars($liga['Id_Liga']); ?>')">
                                    👁️ Ver Detalles
                                </button>
                                <button type="submit" name="seleccionar_equipo" class="action-button btn-unirse">
                                    ✅ Inscribir Equipo
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_liga'])): ?>
            <!-- No se encontraron resultados -->
            <div class="no-results">
                <div class="no-results-icon">🔍</div>
                <h3>No se encontraron ligas</h3>
                <p>Intenta con otro código o nombre de liga</p>
                <form method="POST" action="" style="margin-top: 20px;">
                    <button type="submit" name="cancelar_busqueda" class="search-button">
                        🔄 Volver a sugerencias
                    </button>
                </form>
            </div>
            
        <?php elseif (empty($ligasSugeridas) && $mostrarSugerencias): ?>
            <!-- No hay sugerencias disponibles -->
            <div class="no-results">
                <div class="no-results-icon">📍</div>
                <h3>No hay ligas cercanas disponibles</h3>
                <p>Busca ligas por código o nombre</p>
                <?php if (empty($CodigoPostal)): ?>
                    <p style="color: #ff9800; margin-top: 10px;">
                        ⚠️ No tienes código postal registrado. 
                        <a href="../perfil.php" style="color: #00ffc6;">Actualiza tu perfil</a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- SECCIÓN DE EQUIPOS DEL USUARIO -->
    <?php if (!empty($equiposUsuario)): ?>
    <div class="card equipos-section">
        <h2>⭐ Mis Equipos Disponibles</h2>
        <p style="color: #cccccc; margin-bottom: 15px;">Selecciona un equipo para inscribir en la liga</p>
        
        <div class="equipos-grid">
            <?php foreach ($equiposUsuario as $equipo): ?>
                <div class="equipo-card">
                    <div class="equipo-nombre"><?php echo htmlspecialchars($equipo['Nombre']); ?></div>
                    <div class="equipo-rol"><?php echo htmlspecialchars($equipo['Rol']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($equiposUsuario) === 0): ?>
            <div class="alert alert-info">
                <strong>ℹ️ Información:</strong> No tienes equipos disponibles. 
                <a href="CrearEquipo.php" style="color: #00ffc6;">Crea un equipo</a> o 
                <a href="UnirmeOtroEquipo.php" style="color: #00ffc6;">únete a uno</a> primero.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- BOTONES FLOTANTES -->
<a href="../Perfil2.php" class="btn-ligas">🏠 INICIO</a>
<a href="../Retar/retar.php" class="btn-retar">⚔️ RETAR</a>

<script>
// Crear partículas dinámicas
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 25;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        const size = Math.random() * 20 + 5;
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
    
    // Cambiar placeholder según tipo de búsqueda
    const searchId = document.getElementById('search-id');
    const searchName = document.getElementById('search-name');
    const searchInput = document.getElementById('busqueda');
    
    function updatePlaceholder() {
        if (searchId && searchId.checked) {
            searchInput.placeholder = 'Ejemplo: LIGA-2024-001';
            searchInput.title = 'Buscar por código exacto de la liga';
        } else if (searchName) {
            searchInput.placeholder = 'Ejemplo: Liga Regional Fútbol';
            searchInput.title = 'Buscar ligas con nombres similares';
        }
    }
    
    if (searchId) searchId.addEventListener('change', updatePlaceholder);
    if (searchName) searchName.addEventListener('change', updatePlaceholder);
    if (searchInput) updatePlaceholder();
    
    // Función para ver detalles
    function verDetalles(idLiga) {
        alert('Detalles de la liga ID: ' + idLiga + '\n\nFuncionalidad de detalles en desarrollo.');
    }
    
    // Función para seleccionar equipo (en la sección de selección)
    window.seleccionarEquipo = function(idEquipo) {
        // Desmarcar todos los radios
        document.querySelectorAll('.radio-equipo').forEach(radio => {
            radio.checked = false;
        });
        
        // Deseleccionar todas las tarjetas
        document.querySelectorAll('.equipo-compatible-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Marcar el radio seleccionado
        const radioSeleccionado = document.getElementById('equipo_' + idEquipo);
        if (radioSeleccionado) {
            radioSeleccionado.checked = true;
            
            // Marcar la tarjeta como seleccionada
            const tarjetaSeleccionada = radioSeleccionado.closest('.equipo-compatible-card');
            if (tarjetaSeleccionada) {
                tarjetaSeleccionada.classList.add('selected');
            }
            
            // Habilitar el botón de confirmar
            const btnConfirmar = document.getElementById('btnConfirmar');
            if (btnConfirmar) {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = '✅ Enviar Solicitud con "' + 
                    tarjetaSeleccionada.querySelector('.equipo-compatible-nombre').textContent + '"';
            }
        }
    };
    
    // Función para mostrar/ocultar sidebar en móviles
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.overlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    };
    
    // Cerrar sidebar al hacer clic en un enlace
    document.querySelectorAll('.menu a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        });
    });
    
    // Ajustar sidebar según tamaño de pantalla
    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.overlay');
        if (window.innerWidth > 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    });
    
    // Efectos hover en tarjetas de equipo
    document.querySelectorAll('.equipo-compatible-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            if (!this.classList.contains('selected')) {
                this.style.transform = 'translateY(-5px)';
            }
        });
        
        card.addEventListener('mouseleave', function() {
            if (!this.classList.contains('selected')) {
                this.style.transform = 'translateY(0)';
            }
        });
    });
    
    // Validar selección antes de enviar formulario
    const formSeleccion = document.getElementById('formSeleccionEquipo');
    if (formSeleccion) {
        formSeleccion.addEventListener('submit', function(e) {
            const equipoSeleccionado = this.querySelector('input[name="id_equipo"]:checked');
            if (!equipoSeleccionado) {
                e.preventDefault();
                alert('⚠️ Por favor selecciona un equipo antes de continuar.');
                return false;
            }
            
            // Mostrar mensaje de confirmación
            const confirmar = confirm('¿Estás seguro de que deseas enviar la solicitud de inscripción para este equipo?');
            if (!confirmar) {
                e.preventDefault();
                return false;
            }
            
            // Deshabilitar botón para evitar doble envío
            const btnSubmit = this.querySelector('[type="submit"]');
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '⏳ Enviando solicitud...';
            }
        });
    }
});

// Efectos hover en tarjetas de liga
document.querySelectorAll('.liga-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateX(5px)';
    });
    
    card.addEventListener('mouseleave', function() {
        this.style.transform = 'translateX(0)';
    });
});

// Cerrar overlay al hacer clic
document.querySelector('.overlay').addEventListener('click', function() {
    if (window.innerWidth <= 768) {
        toggleSidebar();
    }
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>