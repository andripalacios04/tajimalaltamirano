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
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

:root{
    --azul:#1877f2;
    --azul2:#0ea5e9;
    --azul-neon-fuerte:#0099ff;
    --cyan:#8fefff;
    --rojo:#ff4b5c;
    --rojo2:#ff2f45;
    --texto:#111827;
    --gris:#6b7280;
    --blanco:#ffffff;
    --sombra-azul:0 0 0 3px rgba(24,119,242,.22),0 12px 28px rgba(24,119,242,.14);
    --sombra-roja:0 0 0 3px rgba(255,75,92,.22),0 12px 28px rgba(255,75,92,.14);
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

body.retame-oficial-page > .bg-particles,
body.retame-oficial-page > .sidebar:not(.retame-oficial-sidebar),
body.retame-oficial-page > .menu-toggle:not(.retame-oficial-menu-toggle),
body.retame-oficial-page > .overlay:not(.retame-oficial-overlay),
body.retame-oficial-page > .btn-ligas,
body.retame-oficial-page > .btn-retar{
    display:none !important;
}

body.retame-oficial-page > .main-content{
    position:relative !important;
    z-index:2 !important;
    width:min(1180px,100%) !important;
    max-width:1180px !important;
    margin-left:0 !important;
    margin-right:auto !important;
    padding-top:16px !important;
}

body.retame-oficial-page .main-content > h1{
    width:100%;
    margin:0 0 22px !important;
    padding:24px 28px;
    border-radius:28px;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,.20),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,.17),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,.08),rgba(255,255,255,.02) 46%,rgba(255,75,92,.08)),
        rgba(255,255,255,.94) !important;
    border:1px solid rgba(17,24,39,.05) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.10),
        0 0 0 1px rgba(24,119,242,.12),
        0 0 18px rgba(0,153,255,.14) !important;
    color:var(--azul) !important;
    font-family:'Orbitron',sans-serif !important;
    font-size:clamp(1.45rem,4vw,2rem) !important;
    font-weight:700 !important;
    line-height:1.25;
    text-align:left !important;
}

body.retame-oficial-page .card,
body.retame-oficial-page .search-section,
body.retame-oficial-page .seleccion-equipo-section{
    position:relative;
    overflow:hidden;
    width:100%;
    margin:0 0 24px !important;
    padding:clamp(20px,3vw,30px) !important;
    border-radius:28px !important;
    background:rgba(255,255,255,.94) !important;
    border:1px solid rgba(17,24,39,.05) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.10),
        0 0 0 1px rgba(24,119,242,.12),
        0 0 18px rgba(0,153,255,.14) !important;
    backdrop-filter:none !important;
}

body.retame-oficial-page .card::before,
body.retame-oficial-page .search-section::before,
body.retame-oficial-page .seleccion-equipo-section::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        radial-gradient(circle at 8% 5%,rgba(24,119,242,.09),transparent 250px),
        radial-gradient(circle at 94% 92%,rgba(255,75,92,.08),transparent 280px);
}

body.retame-oficial-page .card > *,
body.retame-oficial-page .search-section > *,
body.retame-oficial-page .seleccion-equipo-section > *{
    position:relative;
    z-index:1;
}

body.retame-oficial-page .card h2,
body.retame-oficial-page .search-header h2,
body.retame-oficial-page .seleccion-equipo-section h2{
    color:var(--azul) !important;
    font-family:'Orbitron',sans-serif !important;
    font-size:clamp(1.15rem,3vw,1.55rem) !important;
    font-weight:700 !important;
}

body.retame-oficial-page .card h2{
    margin:0 0 20px !important;
    padding-bottom:12px;
    border-bottom:2px solid rgba(0,153,255,.20) !important;
}

body.retame-oficial-page .search-header{
    display:flex;
    align-items:center;
    gap:14px;
    margin-bottom:20px !important;
}

body.retame-oficial-page .search-header h2{
    margin:0 !important;
}

body.retame-oficial-page .search-header p[style]{
    color:#6b7280 !important;
}

body.retame-oficial-page .search-icon{
    width:50px !important;
    height:50px !important;
    min-width:50px;
    border-radius:17px !important;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#ffffff !important;
    color:var(--azul) !important;
    border:2px solid rgba(0,153,255,.35) !important;
    box-shadow:var(--sombra-azul) !important;
    font-size:1.3rem !important;
}

body.retame-oficial-page .user-info{
    margin:18px 0 20px !important;
    padding:16px 18px !important;
    border-radius:18px !important;
    background:rgba(24,119,242,.055) !important;
    border:2px dashed rgba(0,153,255,.25) !important;
}

body.retame-oficial-page .info-row{
    display:flex;
    align-items:flex-start;
    gap:10px;
    margin-bottom:8px !important;
    color:#4b5563;
    font-size:13px;
    line-height:1.5;
}

body.retame-oficial-page .info-row:last-child{
    margin-bottom:0 !important;
}

body.retame-oficial-page .info-label{
    min-width:145px !important;
    color:#111827 !important;
    font-weight:900 !important;
}

body.retame-oficial-page .info-value{
    color:#4b5563 !important;
    font-weight:600;
}

body.retame-oficial-page .search-type{
    display:grid !important;
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:12px !important;
    margin-bottom:16px !important;
}

body.retame-oficial-page .search-option{
    text-align:center;
}

body.retame-oficial-page .search-option input[type="radio"]{
    display:none;
}

body.retame-oficial-page .search-option label{
    display:flex !important;
    align-items:center;
    justify-content:center;
    min-height:48px;
    padding:10px 14px !important;
    border-radius:16px !important;
    background:#ffffff !important;
    color:#374151 !important;
    border:2px solid rgba(0,153,255,.20) !important;
    box-shadow:0 6px 14px rgba(0,0,0,.04);
    cursor:pointer;
    font-size:13px;
    font-weight:900 !important;
    transition:.22s ease !important;
}

body.retame-oficial-page .search-option label:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,.55) !important;
}

body.retame-oficial-page .search-option input[type="radio"]:checked + label{
    background:#f8fbff !important;
    color:var(--azul) !important;
    border-color:var(--azul-neon-fuerte) !important;
    box-shadow:var(--sombra-azul) !important;
    transform:translateY(-1px);
}

body.retame-oficial-page .search-input-group{
    display:flex !important;
    align-items:stretch;
    gap:10px !important;
    margin-bottom:0 !important;
}

body.retame-oficial-page .search-input{
    flex:1;
    min-width:0;
    min-height:50px;
    padding:12px 15px !important;
    border:2px solid rgba(0,153,255,.32) !important;
    border-radius:16px !important;
    outline:none !important;
    background:#ffffff !important;
    color:#111827 !important;
    font-family:'Poppins',sans-serif !important;
    font-size:14px !important;
    font-weight:500;
    box-shadow:0 6px 15px rgba(0,0,0,.04) !important;
    transition:.22s ease !important;
}

body.retame-oficial-page .search-input::placeholder{
    color:#9ca3af !important;
}

body.retame-oficial-page .search-input:focus{
    border-color:var(--azul-neon-fuerte) !important;
    box-shadow:var(--sombra-azul) !important;
}

body.retame-oficial-page .search-button,
body.retame-oficial-page .cancel-button,
body.retame-oficial-page .action-button,
body.retame-oficial-page .btn-cancelar-seleccion,
body.retame-oficial-page .btn-confirmar-inscripcion{
    min-height:48px;
    padding:11px 17px !important;
    border-radius:16px !important;
    font-family:'Poppins',sans-serif !important;
    font-size:13px !important;
    font-weight:900 !important;
    cursor:pointer;
    transition:.22s ease !important;
    text-align:center;
}

body.retame-oficial-page .search-button,
body.retame-oficial-page .btn-detalles{
    border:0 !important;
    background:linear-gradient(135deg,#1877f2,#0ea5e9) !important;
    color:#ffffff !important;
    box-shadow:0 9px 18px rgba(24,119,242,.18) !important;
}

body.retame-oficial-page .btn-unirse,
body.retame-oficial-page .btn-confirmar-inscripcion{
    border:0 !important;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045) !important;
    color:#ffffff !important;
    box-shadow:0 9px 18px rgba(255,75,92,.20) !important;
}

body.retame-oficial-page .cancel-button,
body.retame-oficial-page .btn-cancelar-seleccion{
    background:#ffffff !important;
    color:var(--rojo2) !important;
    border:2px solid rgba(255,75,92,.34) !important;
    box-shadow:0 8px 16px rgba(255,75,92,.08) !important;
}

body.retame-oficial-page .search-button:hover,
body.retame-oficial-page .cancel-button:hover,
body.retame-oficial-page .action-button:hover,
body.retame-oficial-page .btn-cancelar-seleccion:hover,
body.retame-oficial-page .btn-confirmar-inscripcion:hover:not(:disabled){
    transform:translateY(-2px) !important;
}

body.retame-oficial-page .btn-confirmar-inscripcion:disabled{
    background:#cbd5e1 !important;
    color:#64748b !important;
    box-shadow:none !important;
    cursor:not-allowed;
    opacity:1 !important;
}

body.retame-oficial-page .alert{
    width:100%;
    margin:0 0 20px !important;
    padding:15px 17px !important;
    border-radius:18px !important;
    font-size:13px;
    font-weight:700;
    line-height:1.55;
    box-shadow:0 10px 22px rgba(0,0,0,.07);
}

body.retame-oficial-page .alert-success{
    background:#f0fdf4 !important;
    border:2px solid rgba(34,197,94,.34) !important;
    color:#15803d !important;
}

body.retame-oficial-page .alert-error{
    background:#fff5f7 !important;
    border:2px solid rgba(255,75,92,.38) !important;
    color:#b91c1c !important;
}

body.retame-oficial-page .alert-info{
    background:#eff6ff !important;
    border:2px solid rgba(24,119,242,.28) !important;
    color:#1d4ed8 !important;
}

body.retame-oficial-page .alert a[style]{
    color:var(--azul) !important;
    font-weight:900;
}

body.retame-oficial-page .results-count{
    display:inline-flex !important;
    align-items:center;
    min-height:34px;
    margin:0 0 16px !important;
    padding:7px 12px !important;
    border-radius:999px !important;
    background:#ffffff !important;
    color:var(--azul) !important;
    border:2px solid rgba(0,153,255,.25) !important;
    box-shadow:0 0 0 2px rgba(255,75,92,.07);
    font-size:11px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .ligas-container{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(280px,1fr)) !important;
    gap:16px !important;
    max-height:620px;
    overflow-y:auto;
    padding:4px 8px 4px 2px !important;
}

body.retame-oficial-page .ligas-container::-webkit-scrollbar{
    width:9px;
}

body.retame-oficial-page .ligas-container::-webkit-scrollbar-track{
    background:rgba(24,119,242,.07);
    border-radius:999px;
}

body.retame-oficial-page .ligas-container::-webkit-scrollbar-thumb{
    background:linear-gradient(180deg,#1877f2,#ff4b5c);
    border-radius:999px;
}

body.retame-oficial-page .liga-card{
    position:relative;
    overflow:visible;
    min-width:0;
    padding:16px !important;
    border-radius:22px !important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border:2px solid rgba(255,75,92,.62) !important;
    border-left:2px solid rgba(255,75,92,.62) !important;
    box-shadow:
        0 10px 22px rgba(0,0,0,.07),
        0 0 0 2px rgba(0,153,255,.18),
        0 0 14px rgba(0,153,255,.12) !important;
    transition:.22s ease !important;
}

body.retame-oficial-page .liga-card:hover{
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border-color:rgba(255,75,92,.95) !important;
    box-shadow:
        0 14px 28px rgba(0,0,0,.10),
        0 0 0 3px rgba(0,153,255,.23),
        0 0 18px rgba(0,153,255,.16) !important;
}

body.retame-oficial-page .liga-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px;
    margin-bottom:13px !important;
    padding-bottom:13px;
    border-bottom:1px solid rgba(17,24,39,.08);
}

body.retame-oficial-page .liga-title{
    min-width:0;
    flex:1;
}

body.retame-oficial-page .liga-nombre{
    margin-bottom:6px !important;
    color:#111827 !important;
    font-size:16px !important;
    font-weight:900 !important;
    line-height:1.3;
}

body.retame-oficial-page .liga-id{
    display:inline-flex !important;
    max-width:100%;
    padding:5px 9px !important;
    border-radius:999px !important;
    background:rgba(24,119,242,.08) !important;
    color:var(--azul) !important;
    border:1px solid rgba(24,119,242,.18);
    font-size:10px !important;
    font-weight:900 !important;
    word-break:break-all;
}

body.retame-oficial-page .liga-estado{
    padding:6px 9px !important;
    border-radius:999px !important;
    font-size:10px !important;
    font-weight:900 !important;
    white-space:nowrap;
}

body.retame-oficial-page .estado-activa{
    background:rgba(34,197,94,.12) !important;
    color:#15803d !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

body.retame-oficial-page .estado-inscripciones{
    background:rgba(24,119,242,.12) !important;
    color:#1d4ed8 !important;
    border:1px solid rgba(24,119,242,.24) !important;
}

body.retame-oficial-page .liga-details{
    display:grid !important;
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:10px !important;
    margin-bottom:14px !important;
}

body.retame-oficial-page .detail-item{
    min-width:0;
    font-size:12px !important;
}

body.retame-oficial-page .detail-label{
    display:block;
    margin-bottom:3px !important;
    color:#6b7280 !important;
    font-size:10px !important;
    font-weight:800;
}

body.retame-oficial-page .detail-value{
    display:block;
    color:#374151 !important;
    font-size:12px !important;
    font-weight:700 !important;
    line-height:1.45;
    word-break:break-word;
}

body.retame-oficial-page .detail-value span[style]{
    color:var(--azul) !important;
    font-weight:900;
}

body.retame-oficial-page .liga-actions{
    display:flex;
    gap:9px !important;
    margin-top:6px !important;
}

body.retame-oficial-page .action-button{
    flex:1;
    border:none;
}

body.retame-oficial-page .sugerencia-badge{
    position:absolute;
    top:-9px !important;
    right:12px !important;
    z-index:3;
    padding:6px 10px !important;
    border-radius:999px !important;
    background:#ffffff !important;
    color:var(--rojo2) !important;
    border:2px solid rgba(255,75,92,.30) !important;
    box-shadow:0 6px 14px rgba(0,0,0,.08),0 0 0 2px rgba(0,153,255,.08) !important;
    font-size:10px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .no-results,
body.retame-oficial-page .no-equipos-compatibles{
    min-height:180px;
    margin-top:0 !important;
    padding:26px !important;
    border-radius:22px !important;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    background:rgba(24,119,242,.05) !important;
    border:2px dashed rgba(0,153,255,.28) !important;
    color:#4b5563 !important;
}

body.retame-oficial-page .no-results h3,
body.retame-oficial-page .no-equipos-compatibles h3{
    margin:8px 0;
    color:#111827 !important;
    font-weight:900;
}

body.retame-oficial-page .no-results-icon,
body.retame-oficial-page .no-equipos-icon{
    margin-bottom:10px !important;
    font-size:2.2rem !important;
    opacity:.8 !important;
}

body.retame-oficial-page .no-results a,
body.retame-oficial-page .no-equipos-compatibles a{
    color:var(--azul) !important;
}

body.retame-oficial-page .equipos-section{
    margin-top:0 !important;
}

body.retame-oficial-page .equipos-section > p[style]{
    color:#6b7280 !important;
}

body.retame-oficial-page .equipos-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(190px,1fr)) !important;
    gap:14px !important;
    margin-top:16px !important;
}

body.retame-oficial-page .equipo-card{
    padding:15px !important;
    border-radius:18px !important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border:2px solid rgba(0,153,255,.22) !important;
    box-shadow:0 8px 18px rgba(0,0,0,.05);
    text-align:center;
}

body.retame-oficial-page .equipo-nombre{
    margin-bottom:7px !important;
    color:#111827 !important;
    font-size:14px;
    font-weight:900 !important;
}

body.retame-oficial-page .equipo-rol{
    display:inline-flex !important;
    padding:5px 9px !important;
    border-radius:999px !important;
    background:rgba(24,119,242,.09) !important;
    color:var(--azul) !important;
    border:1px solid rgba(24,119,242,.16);
    font-size:10px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .seleccion-equipo-section{
    animation:none !important;
}

body.retame-oficial-page .liga-info-box{
    margin-bottom:22px !important;
    padding:18px !important;
    border-radius:20px !important;
    background:rgba(24,119,242,.055) !important;
    border:2px dashed rgba(0,153,255,.25) !important;
}

body.retame-oficial-page .liga-info-header{
    display:flex;
    align-items:center;
    gap:13px !important;
    margin-bottom:15px !important;
}

body.retame-oficial-page .liga-info-icon{
    width:54px !important;
    height:54px !important;
    min-width:54px;
    border-radius:17px !important;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#1877f2,#0ea5e9) !important;
    color:#ffffff !important;
    box-shadow:0 9px 18px rgba(24,119,242,.18);
    font-size:1.5rem !important;
}

body.retame-oficial-page .liga-info-header h2[style]{
    color:var(--azul) !important;
    margin-bottom:5px !important;
}

body.retame-oficial-page .liga-info-header p[style]{
    color:#6b7280 !important;
}

body.retame-oficial-page .seleccion-equipo-section > h3[style]{
    color:#111827 !important;
}

body.retame-oficial-page .seleccion-equipo-section > p[style]{
    color:#6b7280 !important;
}

body.retame-oficial-page .equipos-compatibles-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(260px,1fr)) !important;
    gap:15px !important;
    margin-top:20px !important;
}

body.retame-oficial-page .equipo-compatible-card{
    position:relative;
    overflow:hidden;
    padding:0 !important;
    border-radius:20px !important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border:2px solid rgba(0,153,255,.25) !important;
    box-shadow:0 9px 20px rgba(0,0,0,.06);
    cursor:pointer;
    transition:.22s ease !important;
}

body.retame-oficial-page .equipo-compatible-card:hover{
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border-color:rgba(255,75,92,.65) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.08),0 0 0 2px rgba(0,153,255,.10) !important;
}

body.retame-oficial-page .equipo-compatible-card.selected{
    background:#f8fbff !important;
    border-color:var(--azul-neon-fuerte) !important;
    box-shadow:var(--sombra-azul) !important;
}

body.retame-oficial-page .radio-equipo-label{
    display:block;
    width:100%;
    min-height:100%;
    padding:16px !important;
    cursor:pointer;
}

body.retame-oficial-page .equipo-compatible-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:8px;
    margin-bottom:13px !important;
    padding-right:30px;
}

body.retame-oficial-page .equipo-compatible-nombre{
    color:#111827 !important;
    font-size:15px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .equipo-compatible-rol{
    padding:5px 8px !important;
    border-radius:999px !important;
    background:rgba(24,119,242,.09) !important;
    color:var(--azul) !important;
    border:1px solid rgba(24,119,242,.16);
    font-size:10px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .equipo-compatible-details{
    display:grid !important;
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:9px !important;
    margin-bottom:10px !important;
}

body.retame-oficial-page .equipo-detail{
    font-size:11px !important;
}

body.retame-oficial-page .equipo-detail-label{
    display:block;
    margin-bottom:3px !important;
    color:#6b7280 !important;
    font-size:10px !important;
    font-weight:800;
}

body.retame-oficial-page .equipo-detail-value{
    display:block;
    color:#374151 !important;
    font-size:11px !important;
    font-weight:700 !important;
}

body.retame-oficial-page .equipo-detail-value[style],
body.retame-oficial-page .radio-equipo-label > div[style] span[style]{
    color:var(--azul) !important;
    font-weight:900 !important;
}

body.retame-oficial-page .radio-equipo{
    position:absolute;
    opacity:0;
    width:0;
    height:0;
}

body.retame-oficial-page .radio-checkmark{
    position:absolute;
    top:14px !important;
    right:14px !important;
    width:23px !important;
    height:23px !important;
    border-radius:50%;
    border:2px solid rgba(0,153,255,.35) !important;
    background:#ffffff !important;
    transition:.22s ease;
}

body.retame-oficial-page .equipo-compatible-card.selected .radio-checkmark{
    background:var(--azul) !important;
    border-color:var(--azul) !important;
}

body.retame-oficial-page .equipo-compatible-card.selected .radio-checkmark::after{
    content:"✓";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    transform:none !important;
    color:#ffffff !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .selection-indicator{
    display:none !important;
}

body.retame-oficial-page .botones-seleccion{
    display:flex !important;
    gap:10px !important;
    margin-top:22px !important;
    padding-top:18px !important;
    border-top:1px solid rgba(17,24,39,.08) !important;
}

body.retame-oficial-page .btn-cancelar-seleccion{
    flex:1 !important;
}

body.retame-oficial-page .btn-confirmar-inscripcion{
    flex:2 !important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .card,
body.retame-oficial-page.dark-mode .search-section,
body.retame-oficial-page.dark-mode .seleccion-equipo-section,
body.retame-oficial-page.dark-mode .liga-card,
body.retame-oficial-page.dark-mode .equipo-card,
body.retame-oficial-page.dark-mode .equipo-compatible-card{
    background:#111827 !important;
    color:#e5e7eb !important;
    border-color:rgba(255,75,92,.76) !important;
    box-shadow:
        0 10px 24px rgba(0,0,0,.28),
        0 0 0 2px rgba(0,153,255,.22),
        0 0 18px rgba(0,153,255,.16) !important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .card h2,
body.retame-oficial-page.dark-mode .search-header h2,
body.retame-oficial-page.dark-mode .liga-info-header h2[style]{
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .search-header p[style],
body.retame-oficial-page.dark-mode .equipos-section > p[style],
body.retame-oficial-page.dark-mode .liga-info-header p[style],
body.retame-oficial-page.dark-mode .seleccion-equipo-section > p[style]{
    color:#cbd5e1 !important;
}

body.retame-oficial-page.dark-mode .user-info,
body.retame-oficial-page.dark-mode .liga-info-box{
    background:rgba(14,165,233,.07) !important;
    border-color:rgba(77,184,255,.28) !important;
}

body.retame-oficial-page.dark-mode .info-label,
body.retame-oficial-page.dark-mode .liga-nombre,
body.retame-oficial-page.dark-mode .equipo-nombre,
body.retame-oficial-page.dark-mode .equipo-compatible-nombre,
body.retame-oficial-page.dark-mode .seleccion-equipo-section > h3[style],
body.retame-oficial-page.dark-mode .no-results h3,
body.retame-oficial-page.dark-mode .no-equipos-compatibles h3{
    color:#f8fafc !important;
}

body.retame-oficial-page.dark-mode .info-value,
body.retame-oficial-page.dark-mode .detail-label,
body.retame-oficial-page.dark-mode .detail-value,
body.retame-oficial-page.dark-mode .equipo-detail-label,
body.retame-oficial-page.dark-mode .equipo-detail-value,
body.retame-oficial-page.dark-mode .no-results,
body.retame-oficial-page.dark-mode .no-equipos-compatibles{
    color:#cbd5e1 !important;
}

body.retame-oficial-page.dark-mode .search-option label,
body.retame-oficial-page.dark-mode .search-input,
body.retame-oficial-page.dark-mode .cancel-button,
body.retame-oficial-page.dark-mode .btn-cancelar-seleccion,
body.retame-oficial-page.dark-mode .results-count,
body.retame-oficial-page.dark-mode .sugerencia-badge,
body.retame-oficial-page.dark-mode .radio-checkmark{
    background:#0b1220 !important;
    color:#e5e7eb !important;
}

body.retame-oficial-page.dark-mode .search-option label,
body.retame-oficial-page.dark-mode .search-input{
    border-color:rgba(77,184,255,.32) !important;
}

body.retame-oficial-page.dark-mode .search-option input[type="radio"]:checked + label{
    color:var(--cyan) !important;
    border-color:rgba(0,153,255,.95) !important;
}

body.retame-oficial-page.dark-mode .search-input::placeholder{
    color:#94a3b8 !important;
}

body.retame-oficial-page.dark-mode .liga-header{
    border-bottom-color:rgba(255,255,255,.08) !important;
}

body.retame-oficial-page.dark-mode .liga-id,
body.retame-oficial-page.dark-mode .equipo-rol,
body.retame-oficial-page.dark-mode .equipo-compatible-rol{
    background:rgba(14,165,233,.10) !important;
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .estado-inscripciones{
    color:#93c5fd !important;
}

body.retame-oficial-page.dark-mode .detail-value span[style],
body.retame-oficial-page.dark-mode .equipo-detail-value[style],
body.retame-oficial-page.dark-mode .radio-equipo-label > div[style] span[style]{
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .equipo-compatible-card.selected{
    background:#0b1220 !important;
}

body.retame-oficial-page.dark-mode .botones-seleccion{
    border-top-color:rgba(255,255,255,.08) !important;
}

body.retame-oficial-page.dark-mode .radio-checkmark{
    border-color:rgba(77,184,255,.40) !important;
}

body.retame-oficial-page.dark-mode .equipo-compatible-card.selected .radio-checkmark{
    background:var(--azul) !important;
}

@media screen and (max-width:820px){
    body.retame-oficial-page > .main-content{
        width:100% !important;
        max-width:100% !important;
        padding-top:12px !important;
    }

    body.retame-oficial-page .ligas-container{
        grid-template-columns:repeat(auto-fill,minmax(250px,1fr)) !important;
    }
}

@media screen and (max-width:620px){
    body.retame-oficial-page .main-content > h1{
        margin-bottom:16px !important;
        padding:20px 17px !important;
        border-radius:24px !important;
        text-align:center !important;
        font-size:1.3rem !important;
    }

    body.retame-oficial-page .card,
    body.retame-oficial-page .search-section,
    body.retame-oficial-page .seleccion-equipo-section{
        margin-bottom:18px !important;
        padding:20px 16px !important;
        border-radius:24px !important;
    }

    body.retame-oficial-page .search-header{
        align-items:flex-start;
    }

    body.retame-oficial-page .search-type{
        grid-template-columns:1fr !important;
    }

    body.retame-oficial-page .search-input-group,
    body.retame-oficial-page .liga-actions,
    body.retame-oficial-page .botones-seleccion{
        flex-direction:column !important;
    }

    body.retame-oficial-page .search-button,
    body.retame-oficial-page .cancel-button,
    body.retame-oficial-page .action-button,
    body.retame-oficial-page .btn-cancelar-seleccion,
    body.retame-oficial-page .btn-confirmar-inscripcion{
        width:100% !important;
        flex:auto !important;
    }

    body.retame-oficial-page .ligas-container{
        grid-template-columns:1fr !important;
        max-height:none !important;
        overflow:visible;
        padding-right:0 !important;
    }

    body.retame-oficial-page .liga-details,
    body.retame-oficial-page .equipo-compatible-details{
        grid-template-columns:1fr !important;
    }

    body.retame-oficial-page .equipos-grid,
    body.retame-oficial-page .equipos-compatibles-grid{
        grid-template-columns:1fr !important;
    }

    body.retame-oficial-page .info-row{
        flex-direction:column;
        gap:3px;
    }

    body.retame-oficial-page .info-label{
        min-width:0 !important;
    }
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