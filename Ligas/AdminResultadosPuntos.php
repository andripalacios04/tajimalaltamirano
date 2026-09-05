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

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

// Variables
$mensajeError = '';
$mensajeExito = '';
$id_liga_admin = '';
$nombre_liga = '';
$id_partido = isset($_GET['id_partido']) ? $_GET['id_partido'] : '';
$liga = isset($_GET['liga']) ? $_GET['liga'] : '';
$partido_data = null;
$equipo_local = null;
$equipo_visitante = null;
$id_deporte = '';
$jugadores_local = [];
$jugadores_visitante = [];
$mostrar_opciones = true;
$mostrar_formulario = false;
$estado_seleccionado = '';

// ======================================================================
// 1. VERIFICAR PERMISOS
// ======================================================================
if (!empty($Id_Retador) && !empty($id_partido) && !empty($liga)) {
    $sql_verificar_permiso = "SELECT * FROM adminsolicitud 
                             WHERE id_retador = ? 
                             AND id_admin = ? 
                             AND Estado = 'Activo' 
                             AND Tipo = 'partido'";
    
    $stmt_verificar = $conn->prepare($sql_verificar_permiso);
    if ($stmt_verificar) {
        $stmt_verificar->bind_param("ss", $Id_Retador, $id_partido);
        $stmt_verificar->execute();
        $res_verificar = $stmt_verificar->get_result();
        
        if ($res_verificar->num_rows > 0) {
            $permiso_data = $res_verificar->fetch_assoc();
            $id_liga_admin = $liga;
            
            // ======================================================================
            // 2. DATOS DE LA LIGA
            // ======================================================================
            $sql_liga_info = "SELECT Nombre FROM ligas WHERE Id_Liga = ?";
            $stmt_liga = $conn->prepare($sql_liga_info);
            $stmt_liga->bind_param("s", $id_liga_admin);
            $stmt_liga->execute();
            $res_liga = $stmt_liga->get_result();
            
            if ($res_liga->num_rows > 0) {
                $liga_info = $res_liga->fetch_assoc();
                $nombre_liga = $liga_info['Nombre'];
            }
            $stmt_liga->close();
            
            // ======================================================================
            // 3. DATOS DEL PARTIDO
            // ======================================================================
            $sql_partido = "SELECT 
                c.*,
                el.Id_Equipo as id_equipo_local,
                el.Nombre as nombre_local,
                ev.Id_Equipo as id_equipo_visitante,
                ev.Nombre as nombre_visitante,
                d.Id_Deporte as id_deporte_partido,
                d.Nombre as nombre_deporte,
                c.Jornada
            FROM Calendario c
            LEFT JOIN equipo el ON c.id_equipolocal = el.Id_Equipo
            LEFT JOIN equipo ev ON c.id_equipovicitante = ev.Id_Equipo
            LEFT JOIN deporte d ON c.id_deporte = d.Id_Deporte
            WHERE c.Id_Partido = ? AND c.id_liga = ?";
            
            $stmt_partido = $conn->prepare($sql_partido);
            $stmt_partido->bind_param("ss", $id_partido, $id_liga_admin);
            $stmt_partido->execute();
            $res_partido = $stmt_partido->get_result();
            
            if ($res_partido->num_rows > 0) {
                $partido_data = $res_partido->fetch_assoc();
                $id_deporte = $partido_data['id_deporte_partido'];
                
                $equipo_local = [
                    'id_equipo' => $partido_data['id_equipo_local'],
                    'nombre' => $partido_data['nombre_local']
                ];
                
                $equipo_visitante = [
                    'id_equipo' => $partido_data['id_equipo_visitante'],
                    'nombre' => $partido_data['nombre_visitante']
                ];
                
                // ======================================================================
// 4. CONSULTAR JUGADORES Y CAPITANES (MODIFICADO CON TARJETAS)
// ======================================================================
// Consulta para obtener TODOS los miembros del equipo (jugadores y capitán) CON SUS TARJETAS
$sql_miembros_local = "SELECT 
    r.Id_Retador,
    r.Nombre,
    r.Apellido,
    CASE 
        WHEN ej.tipo = 'capitan' THEN 'capitan'
        ELSE 'jugador'
    END as tipo_miembro,
    CONCAT(r.Nombre, ' ', r.Apellido) as nombre_completo,
    CASE 
        WHEN COALESCE(es.tarjetas_rojas, 0) >= 1 THEN 0  -- Si tiene rojas, amarillas = 0
        WHEN COALESCE(es.tarjetas_amarillas, 0) >= 2 THEN 0  -- Si tiene 2 o más amarillas, mostrar 0 (se convirtieron a roja)
        ELSE COALESCE(es.tarjetas_amarillas, 0)
    END as tarjetas_amarillas,
    CASE 
        WHEN COALESCE(es.tarjetas_amarillas, 0) >= 2 THEN 1  -- Si tiene 2 o más amarillas, mostrar 1 roja
        ELSE COALESCE(es.tarjetas_rojas, 0)
    END as tarjetas_rojas
FROM equipo_jugador ej
INNER JOIN retador r ON ej.Id_Jugador = r.Id_Retador
LEFT JOIN estadisticasretador es ON r.Id_Retador = es.id_retador 
    AND es.id_liga = ? 
    AND es.id_equipo = ?
WHERE ej.Id_Equipo = ?
ORDER BY 
    CASE WHEN ej.tipo = 'capitan' THEN 1 ELSE 2 END,
    r.Nombre, r.Apellido";
                
                $stmt_miembros_local = $conn->prepare($sql_miembros_local);
                $stmt_miembros_local->bind_param("sss", $id_liga_admin, $equipo_local['id_equipo'], $equipo_local['id_equipo']);
                $stmt_miembros_local->execute();
                $res_miembros_local = $stmt_miembros_local->get_result();
                
                while ($miembro = $res_miembros_local->fetch_assoc()) {
                    $jugadores_local[] = $miembro;
                }
                $stmt_miembros_local->close();
                
               $sql_miembros_visitante = "SELECT 
    r.Id_Retador,
    r.Nombre,
    r.Apellido,
    CASE 
        WHEN ej.tipo = 'capitan' THEN 'capitan'
        ELSE 'jugador'
    END as tipo_miembro,
    CONCAT(r.Nombre, ' ', r.Apellido) as nombre_completo,
    CASE 
        WHEN COALESCE(es.tarjetas_rojas, 0) >= 1 THEN 0  -- Si tiene rojas, amarillas = 0
        WHEN COALESCE(es.tarjetas_amarillas, 0) >= 2 THEN 0  -- Si tiene 2 o más amarillas, mostrar 0 (se convirtieron a roja)
        ELSE COALESCE(es.tarjetas_amarillas, 0)
    END as tarjetas_amarillas,
    CASE 
        WHEN COALESCE(es.tarjetas_amarillas, 0) >= 2 THEN 1  -- Si tiene 2 o más amarillas, mostrar 1 roja
        ELSE COALESCE(es.tarjetas_rojas, 0)
    END as tarjetas_rojas
FROM equipo_jugador ej
INNER JOIN retador r ON ej.Id_Jugador = r.Id_Retador
LEFT JOIN estadisticasretador es ON r.Id_Retador = es.id_retador 
    AND es.id_liga = ? 
    AND es.id_equipo = ?
WHERE ej.Id_Equipo = ?
ORDER BY 
    CASE WHEN ej.tipo = 'capitan' THEN 1 ELSE 2 END,
    r.Nombre, r.Apellido";
                
                $stmt_miembros_visitante = $conn->prepare($sql_miembros_visitante);
                $stmt_miembros_visitante->bind_param("sss", $id_liga_admin, $equipo_visitante['id_equipo'], $equipo_visitante['id_equipo']);
                $stmt_miembros_visitante->execute();
                $res_miembros_visitante = $stmt_miembros_visitante->get_result();
                
                while ($miembro = $res_miembros_visitante->fetch_assoc()) {
                    $jugadores_visitante[] = $miembro;
                }
                $stmt_miembros_visitante->close();
                
                // ======================================================================
                // 5. CLASIFICACIÓN ACTUAL
                // ======================================================================
                $clasificaciones = [];
                $sql_clasificacion = "SELECT cg.* FROM clasificacion_general cg
                                    INNER JOIN (
                                        SELECT id_equipo, MAX(fecha_actualizacion) as ultima_fecha
                                        FROM clasificacion_general
                                        WHERE id_liga = ?
                                        GROUP BY id_equipo
                                    ) ultima ON cg.id_equipo = ultima.id_equipo AND cg.fecha_actualizacion = ultima.ultima_fecha
                                    WHERE cg.id_liga = ?";
                
                $stmt_clasificacion = $conn->prepare($sql_clasificacion);
                $stmt_clasificacion->bind_param("ss", $id_liga_admin, $id_liga_admin);
                $stmt_clasificacion->execute();
                $res_clasificacion = $stmt_clasificacion->get_result();
                
                $clasificacion_local = null;
                $clasificacion_visitante = null;
                
                while ($clasif = $res_clasificacion->fetch_assoc()) {
                    if ($clasif['id_equipo'] == $equipo_local['id_equipo']) {
                        $clasificacion_local = $clasif;
                    } elseif ($clasif['id_equipo'] == $equipo_visitante['id_equipo']) {
                        $clasificacion_visitante = $clasif;
                    }
                }
                $stmt_clasificacion->close();
                
                // ======================================================================
                // 6. PROCESAR SELECCIÓN DE ESTADO
                // ======================================================================
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['seleccionar_estado'])) {
                    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                        $mensajeError = "❌ Token de seguridad inválido.";
                    } else {
                        $estado_seleccionado = $_POST['estado_partido'];
                        
                        switch ($estado_seleccionado) {
                            case 'FINALIZADO':
                                $mostrar_opciones = false;
                                $mostrar_formulario = true;
                                break;
                                
                            case 'POSPUESTO':
                                $mostrar_opciones = false;
                                $mostrar_formulario = true;
                                break;
                                
                            case 'SUSPENDIDO':
                                $mostrar_opciones = false;
                                $mostrar_formulario = true;
                                break;
                                
                            case 'CANCELADO':
                                $mostrar_opciones = false;
                                $mostrar_formulario = true;
                                break;
                                
                            default:
                                $mensajeError = "❌ Estado no válido.";
                        }
                    }
                }
                
                // ======================================================================
                // 7. PROCESAR FORMULARIO DE FINALIZADO (CON NUEVA LÓGICA DE GOLES)
                // ======================================================================
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_finalizado'])) {
                    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                        $mensajeError = "❌ Token de seguridad inválido.";
                    } else {
                        // NUEVO: Recoger goles por jugador como JSON
                        $goles_local_json = isset($_POST['goles_jugadores_local']) ? $_POST['goles_jugadores_local'] : '[]';
                        $goles_visitante_json = isset($_POST['goles_jugadores_visitante']) ? $_POST['goles_jugadores_visitante'] : '[]';
                        
                        // Decodificar el JSON
                        $goles_jugadores_local = json_decode($goles_local_json, true) ?: [];
                        $goles_jugadores_visitante = json_decode($goles_visitante_json, true) ?: [];
                        
                        // Contar goles: cada jugador en el array cuenta como 1 gol
                        // Si un jugador aparece 2 veces en el array, cuenta como 2 goles
                        $goles_local = count($goles_jugadores_local);
                        $goles_visitante = count($goles_jugadores_visitante);
                        
                        // Datos adicionales
                        $tiempo_jugado = isset($_POST['tiempo_jugado']) ? intval($_POST['tiempo_jugado']) : 90;
                        $descripcion_finalizado = isset($_POST['descripcion_finalizado']) ? trim($_POST['descripcion_finalizado']) : '';
                        
                        // Tarjetas
                        $tarjetas_amarillas_local = isset($_POST['tarjetas_amarillas_local']) ? $_POST['tarjetas_amarillas_local'] : [];
                        $tarjetas_rojas_local = isset($_POST['tarjetas_rojas_local']) ? $_POST['tarjetas_rojas_local'] : [];
                        $tarjetas_amarillas_visitante = isset($_POST['tarjetas_amarillas_visitante']) ? $_POST['tarjetas_amarillas_visitante'] : [];
                        $tarjetas_rojas_visitante = isset($_POST['tarjetas_rojas_visitante']) ? $_POST['tarjetas_rojas_visitante'] : [];
                        
                        // Faltas
                        $faltas_local = isset($_POST['faltas_local']) ? intval($_POST['faltas_local']) : 0;
                        $faltas_visitante = isset($_POST['faltas_visitante']) ? intval($_POST['faltas_visitante']) : 0;
                        
                        if ($tiempo_jugado < 0 || $tiempo_jugado > 120) {
                            $mensajeError = "❌ El tiempo jugado debe estar entre 0 y 120 minutos.";
                        } else {
                            $conn->begin_transaction();
                            
                            try {
                                // ======================================================================
                                // 7.1. REGISTRAR GOLES POR JUGADOR (NUEVO)
                                // ======================================================================
                                // Contar goles por cada jugador individualmente
                                $goles_por_jugador_local = array_count_values($goles_jugadores_local);
                                $goles_por_jugador_visitante = array_count_values($goles_jugadores_visitante);
                                
                                // Registrar goles de cada jugador en estadisticasretador
                                registrarGolesJugadores($conn, $goles_por_jugador_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                                registrarGolesJugadores($conn, $goles_por_jugador_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                                
                                // ======================================================================
                                // 7.2. CALCULAR PUNTOS Y RESULTADOS
                                // ======================================================================
                                $puntos_local = 0;
                                $puntos_visitante = 0;
                                $ganador_local = false;
                                $ganador_visitante = false;
                                $empate = false;
                                
                                if ($goles_local > $goles_visitante) {
                                    $puntos_local = 3;
                                    $puntos_visitante = 0;
                                    $ganador_local = true;
                                } elseif ($goles_local < $goles_visitante) {
                                    $puntos_local = 0;
                                    $puntos_visitante = 3;
                                    $ganador_visitante = true;
                                } else {
                                    $puntos_local = 1;
                                    $puntos_visitante = 1;
                                    $empate = true;
                                }
                                
                                // ======================================================================
                                // 7.3. ACTUALIZAR O CREAR CLASIFICACIÓN
                                // ======================================================================
                                
                                // Obtener o crear clasificaciones actuales
                                $clasif_local = obtenerActualizarClasificacion($conn, $equipo_local['id_equipo'], $id_liga_admin, $id_deporte, $partido_data['Jornada']);
                                $clasif_visitante = obtenerActualizarClasificacion($conn, $equipo_visitante['id_equipo'], $id_liga_admin, $id_deporte, $partido_data['Jornada']);
                                
                                // Calcular nuevos valores para LOCAL
                                $partidos_jugados_local_calc = $clasif_local['partidos_jugados'] + 1;
                                $partidos_ganados_local_calc = $clasif_local['partidos_ganados'] + ($ganador_local ? 1 : 0);
                                $partidos_empatados_local_calc = $clasif_local['partidos_empatados'] + ($empate ? 1 : 0);
                                $partidos_perdidos_local_calc = $clasif_local['partidos_perdidos'] + ($ganador_visitante ? 1 : 0);
                                $goles_favor_local_calc = $clasif_local['goles_favor'] + $goles_local;
                                $goles_contra_local_calc = $clasif_local['goles_contra'] + $goles_visitante;
                                $diferencia_goles_local_calc = $goles_favor_local_calc - $goles_contra_local_calc;
                                $puntos_total_local_calc = $clasif_local['puntos'] + $puntos_local;
                                $tarjetas_amarillas_total_local_calc = $clasif_local['tarjetas_amarillas'] + count($tarjetas_amarillas_local);
                                $tarjetas_rojas_total_local_calc = $clasif_local['tarjetas_rojas'] + count($tarjetas_rojas_local);
                                $faltas_total_local_calc = $clasif_local['faltas_cometidas'] + $faltas_local;
                                
                                // Calcular nuevos valores para VISITANTE
                                $partidos_jugados_visitante_calc = $clasif_visitante['partidos_jugados'] + 1;
                                $partidos_ganados_visitante_calc = $clasif_visitante['partidos_ganados'] + ($ganador_visitante ? 1 : 0);
                                $partidos_empatados_visitante_calc = $clasif_visitante['partidos_empatados'] + ($empate ? 1 : 0);
                                $partidos_perdidos_visitante_calc = $clasif_visitante['partidos_perdidos'] + ($ganador_local ? 1 : 0);
                                $goles_favor_visitante_calc = $clasif_visitante['goles_favor'] + $goles_visitante;
                                $goles_contra_visitante_calc = $clasif_visitante['goles_contra'] + $goles_local;
                                $diferencia_goles_visitante_calc = $goles_favor_visitante_calc - $goles_contra_visitante_calc;
                                $puntos_total_visitante_calc = $clasif_visitante['puntos'] + $puntos_visitante;
                                $tarjetas_amarillas_total_visitante_calc = $clasif_visitante['tarjetas_amarillas'] + count($tarjetas_amarillas_visitante);
                                $tarjetas_rojas_total_visitante_calc = $clasif_visitante['tarjetas_rojas'] + count($tarjetas_rojas_visitante);
                                $faltas_total_visitante_calc = $clasif_visitante['faltas_cometidas'] + $faltas_visitante;
                                
                                // Determinar si es UPDATE o INSERT para LOCAL
                                if ($clasif_local['id_clasificacion']) {
                                    // UPDATE existente
                                    $sql_update_local = "UPDATE clasificacion_general 
                                                        SET puntos = ?, 
                                                            partidos_jugados = ?, partidos_ganados = ?, partidos_empatados = ?, partidos_perdidos = ?,
                                                            goles_favor = ?, goles_contra = ?, diferencia_goles = ?, 
                                                            tarjetas_amarillas = ?, tarjetas_rojas = ?, faltas_cometidas = ?,
                                                            Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                                        WHERE id_clasificacion = ?";
                                    
                                    $stmt_update_local = $conn->prepare($sql_update_local);
                                    $stmt_update_local->bind_param(
                                        "iiiiiiiiiiisss",
                                        $puntos_total_local_calc,
                                        $partidos_jugados_local_calc,
                                        $partidos_ganados_local_calc,
                                        $partidos_empatados_local_calc,
                                        $partidos_perdidos_local_calc,
                                        $goles_favor_local_calc,
                                        $goles_contra_local_calc,
                                        $diferencia_goles_local_calc,
                                        $tarjetas_amarillas_total_local_calc,
                                        $tarjetas_rojas_total_local_calc,
                                        $faltas_total_local_calc,
                                        $partido_data['Jornada'],
                                        $descripcion_finalizado,
                                        $clasif_local['id_clasificacion']
                                    );
                                    $stmt_update_local->execute();
                                    $stmt_update_local->close();
                                } else {
                                    // INSERT nuevo
                                    $id_clasificacion_local = generarIdUnico($conn, 'clasificacion_general', 'id_clasificacion', 7);
                                    
                                    $sql_insert_local = "INSERT INTO clasificacion_general 
                                                        (id_clasificacion, id_liga, id_equipo, id_deporte, puntos, 
                                                         partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                                         goles_favor, goles_contra, diferencia_goles, tarjetas_amarillas, 
                                                         tarjetas_rojas, faltas_cometidas, Jornada, fecha_creacion, 
                                                         fecha_actualizacion, descripcion)
                                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                                                               CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)";
                                    
                                    $stmt_insert_local = $conn->prepare($sql_insert_local);
                                    $stmt_insert_local->bind_param(
                                        "isssiiiiiiiiiiiss",
                                        $id_clasificacion_local,
                                        $id_liga_admin,
                                        $equipo_local['id_equipo'],
                                        $id_deporte,
                                        $puntos_total_local_calc,
                                        $partidos_jugados_local_calc,
                                        $partidos_ganados_local_calc,
                                        $partidos_empatados_local_calc,
                                        $partidos_perdidos_local_calc,
                                        $goles_favor_local_calc,
                                        $goles_contra_local_calc,
                                        $diferencia_goles_local_calc,
                                        $tarjetas_amarillas_total_local_calc,
                                        $tarjetas_rojas_total_local_calc,
                                        $faltas_total_local_calc,
                                        $partido_data['Jornada'],
                                        $descripcion_finalizado
                                    );
                                    $stmt_insert_local->execute();
                                    $stmt_insert_local->close();
                                }
                                
                                // Determinar si es UPDATE o INSERT para VISITANTE
                                if ($clasif_visitante['id_clasificacion']) {
                                    // UPDATE existente
                                    $sql_update_visitante = "UPDATE clasificacion_general 
                                                            SET puntos = ?, 
                                                                partidos_jugados = ?, partidos_ganados = ?, partidos_empatados = ?, partidos_perdidos = ?,
                                                                goles_favor = ?, goles_contra = ?, diferencia_goles = ?, 
                                                                tarjetas_amarillas = ?, tarjetas_rojas = ?, faltas_cometidas = ?,
                                                                Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                                            WHERE id_clasificacion = ?";
                                    
                                    $stmt_update_visitante = $conn->prepare($sql_update_visitante);
                                    $stmt_update_visitante->bind_param(
                                        "iiiiiiiiiiisss",
                                        $puntos_total_visitante_calc,
                                        $partidos_jugados_visitante_calc,
                                        $partidos_ganados_visitante_calc,
                                        $partidos_empatados_visitante_calc,
                                        $partidos_perdidos_visitante_calc,
                                        $goles_favor_visitante_calc,
                                        $goles_contra_visitante_calc,
                                        $diferencia_goles_visitante_calc,
                                        $tarjetas_amarillas_total_visitante_calc,
                                        $tarjetas_rojas_total_visitante_calc,
                                        $faltas_total_visitante_calc,
                                        $partido_data['Jornada'],
                                        $descripcion_finalizado,
                                        $clasif_visitante['id_clasificacion']
                                    );
                                    $stmt_update_visitante->execute();
                                    $stmt_update_visitante->close();
                                } else {
                                    // INSERT nuevo
                                    $id_clasificacion_visitante = generarIdUnico($conn, 'clasificacion_general', 'id_clasificacion', 7);
                                    
                                    $sql_insert_visitante = "INSERT INTO clasificacion_general 
                                                            (id_clasificacion, id_liga, id_equipo, id_deporte, puntos, 
                                                             partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                                             goles_favor, goles_contra, diferencia_goles, tarjetas_amarillas, 
                                                             tarjetas_rojas, faltas_cometidas, Jornada, fecha_creacion, 
                                                             fecha_actualizacion, descripcion)
                                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                                                                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)";
                                    
                                    $stmt_insert_visitante = $conn->prepare($sql_insert_visitante);
                                    $stmt_insert_visitante->bind_param(
                                        "isssiiiiiiiiiiiss",
                                        $id_clasificacion_visitante,
                                        $id_liga_admin,
                                        $equipo_visitante['id_equipo'],
                                        $id_deporte,
                                        $puntos_total_visitante_calc,
                                        $partidos_jugados_visitante_calc,
                                        $partidos_ganados_visitante_calc,
                                        $partidos_empatados_visitante_calc,
                                        $partidos_perdidos_visitante_calc,
                                        $goles_favor_visitante_calc,
                                        $goles_contra_visitante_calc,
                                        $diferencia_goles_visitante_calc,
                                        $tarjetas_amarillas_total_visitante_calc,
                                        $tarjetas_rojas_total_visitante_calc,
                                        $faltas_total_visitante_calc,
                                        $partido_data['Jornada'],
                                        $descripcion_finalizado
                                    );
                                    $stmt_insert_visitante->execute();
                                    $stmt_insert_visitante->close();
                                }
                                
                                // ======================================================================
                                // 7.4. ACTUALIZAR POSICIONES
                                // ======================================================================
                                actualizarPosicionesClasificacion($conn, $id_liga_admin);
                                
                                // ======================================================================
                                // 7.5. ACTUALIZAR CALENDARIO
                                // ======================================================================
                                $sql_update_calendario = "UPDATE Calendario 
                                                         SET Estado = 'FINALIZADO', 
                                                             goles_local = ?, 
                                                             goles_visitante = ?, 
                                                             Tiempo_Jugado = ?,
                                                             descripcion = ?,
                                                             fecha_actualizacion = CURRENT_TIMESTAMP
                                                         WHERE Id_Partido = ?";
                                
                                $stmt_update_calendario = $conn->prepare($sql_update_calendario);
                                $stmt_update_calendario->bind_param("iiiss", $goles_local, $goles_visitante, $tiempo_jugado, $descripcion_finalizado, $id_partido);
                                $stmt_update_calendario->execute();
                                $stmt_update_calendario->close();
                                
                                // ======================================================================
                                // 7.6. REGISTRAR TARJETAS (CON LÓGICA MODIFICADA)
                                // ======================================================================
                                registrarTarjetasConLogica($conn, $tarjetas_amarillas_local, $tarjetas_rojas_local, 
                                                          $equipo_local['id_equipo'], $id_partido, $id_liga_admin);
                                registrarTarjetasConLogica($conn, $tarjetas_amarillas_visitante, $tarjetas_rojas_visitante, 
                                                          $equipo_visitante['id_equipo'], $id_partido, $id_liga_admin);
                                
                                $conn->commit();
                                
                                // Eliminar permiso
                                $sql_eliminar = "DELETE FROM adminsolicitud 
                                                WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                                $stmt_eliminar = $conn->prepare($sql_eliminar);
                                $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                                $stmt_eliminar->execute();
                                $stmt_eliminar->close();
                                
                                $mensajeExito = "✅ Partido finalizado correctamente. Goles registrados: $goles_local - $goles_visitante";
                                
                                echo '<script>
                                    setTimeout(function() {
                                        window.location.href = "AdminCalendario.php";
                                    }, 2000);
                                </script>';
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $mensajeError = "❌ Error: " . $e->getMessage();
                            }
                        }
                    }
                }

// ======================================================================
// 8. PROCESAR FORMULARIO DE SUSPENDIDO (CON NUEVA LÓGICA DE GOLES Y CLASIFICACIÓN)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_suspendido'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $mensajeError = "❌ Token de seguridad inválido.";
    } else {
        // NUEVO: Goles por jugador para suspendido como JSON
        $goles_local_json = isset($_POST['goles_jugadores_local']) ? $_POST['goles_jugadores_local'] : '[]';
        $goles_visitante_json = isset($_POST['goles_jugadores_visitante']) ? $_POST['goles_jugadores_visitante'] : '[]';
        
        // Decodificar el JSON
        $goles_jugadores_local = json_decode($goles_local_json, true) ?: [];
        $goles_jugadores_visitante = json_decode($goles_visitante_json, true) ?: [];
        
        $goles_local = count($goles_jugadores_local);
        $goles_visitante = count($goles_jugadores_visitante);
        
        $nueva_fecha = $_POST['nueva_fecha'];
        $nueva_hora = $_POST['nueva_hora'];
        $nueva_direccion = $_POST['nueva_direccion'];
        $descripcion_suspension = trim($_POST['descripcion_suspension']);
        $tiempo_jugado = isset($_POST['tiempo_jugado']) ? intval($_POST['tiempo_jugado']) : 0;
        
        $tarjetas_amarillas_local = isset($_POST['tarjetas_amarillas_local']) ? $_POST['tarjetas_amarillas_local'] : [];
        $tarjetas_rojas_local = isset($_POST['tarjetas_rojas_local']) ? $_POST['tarjetas_rojas_local'] : [];
        $tarjetas_amarillas_visitante = isset($_POST['tarjetas_amarillas_visitante']) ? $_POST['tarjetas_amarillas_visitante'] : [];
        $tarjetas_rojas_visitante = isset($_POST['tarjetas_rojas_visitante']) ? $_POST['tarjetas_rojas_visitante'] : [];
        
        $faltas_local = isset($_POST['faltas_local']) ? intval($_POST['faltas_local']) : 0;
        $faltas_visitante = isset($_POST['faltas_visitante']) ? intval($_POST['faltas_visitante']) : 0;
        
        if (empty($descripcion_suspension)) {
            $mensajeError = "❌ La razón de la suspensión es obligatoria.";
        } else {
            $conn->begin_transaction();
            
            try {
                // ======================================================================
                // 8.1. REGISTRAR GOLES POR JUGADOR
                // ======================================================================
                if (!empty($goles_jugadores_local)) {
                    $goles_por_jugador_local = array_count_values($goles_jugadores_local);
                    registrarGolesJugadores($conn, $goles_por_jugador_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                }
                
                if (!empty($goles_jugadores_visitante)) {
                    $goles_por_jugador_visitante = array_count_values($goles_jugadores_visitante);
                    registrarGolesJugadores($conn, $goles_por_jugador_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                }
                
                // ======================================================================
                // 8.2. ACTUALIZAR CLASIFICACIÓN GENERAL PARA SUSPENDIDO
                // ======================================================================
                $descripcion_clasificacion = "SUSPENDIDO - " . $descripcion_suspension . 
                                            " | Resultado parcial: " . $goles_local . "-" . $goles_visitante . 
                                            " | Tiempo jugado: " . $tiempo_jugado . " minutos";
                
                // Obtener o crear clasificaciones actuales
                $clasif_local_sus = obtenerActualizarClasificacion($conn, $equipo_local['id_equipo'], $id_liga_admin, $id_deporte, $partido_data['Jornada']);
                $clasif_visitante_sus = obtenerActualizarClasificacion($conn, $equipo_visitante['id_equipo'], $id_liga_admin, $id_deporte, $partido_data['Jornada']);
                
                // Calcular nuevos valores para LOCAL (SUSPENDIDO)
                $partidos_jugados_local_sus = $clasif_local_sus['partidos_jugados'] + 1;
                $goles_favor_local_sus = $clasif_local_sus['goles_favor'] + $goles_local;
                $goles_contra_local_sus = $clasif_local_sus['goles_contra'] + $goles_visitante;
                $diferencia_goles_local_sus = $goles_favor_local_sus - $goles_contra_local_sus;
                $tarjetas_amarillas_total_local_sus = $clasif_local_sus['tarjetas_amarillas'] + count($tarjetas_amarillas_local);
                $tarjetas_rojas_total_local_sus = $clasif_local_sus['tarjetas_rojas'] + count($tarjetas_rojas_local);
                $faltas_total_local_sus = $clasif_local_sus['faltas_cometidas'] + $faltas_local;
                
                // Calcular nuevos valores para VISITANTE (SUSPENDIDO)
                $partidos_jugados_visitante_sus = $clasif_visitante_sus['partidos_jugados'] + 1;
                $goles_favor_visitante_sus = $clasif_visitante_sus['goles_favor'] + $goles_visitante;
                $goles_contra_visitante_sus = $clasif_visitante_sus['goles_contra'] + $goles_local;
                $diferencia_goles_visitante_sus = $goles_favor_visitante_sus - $goles_contra_visitante_sus;
                $tarjetas_amarillas_total_visitante_sus = $clasif_visitante_sus['tarjetas_amarillas'] + count($tarjetas_amarillas_visitante);
                $tarjetas_rojas_total_visitante_sus = $clasif_visitante_sus['tarjetas_rojas'] + count($tarjetas_rojas_visitante);
                $faltas_total_visitante_sus = $clasif_visitante_sus['faltas_cometidas'] + $faltas_visitante;
                
                // Para suspendido, los puntos y resultados no cambian
                $puntos_total_local_sus = $clasif_local_sus['puntos'];
                $partidos_ganados_local_sus = $clasif_local_sus['partidos_ganados'];
                $partidos_empatados_local_sus = $clasif_local_sus['partidos_empatados'];
                $partidos_perdidos_local_sus = $clasif_local_sus['partidos_perdidos'];
                
                $puntos_total_visitante_sus = $clasif_visitante_sus['puntos'];
                $partidos_ganados_visitante_sus = $clasif_visitante_sus['partidos_ganados'];
                $partidos_empatados_visitante_sus = $clasif_visitante_sus['partidos_empatados'];
                $partidos_perdidos_visitante_sus = $clasif_visitante_sus['partidos_perdidos'];
                
                // Determinar si es UPDATE o INSERT para LOCAL (SUSPENDIDO)
                if ($clasif_local_sus['id_clasificacion']) {
                    // UPDATE existente
                    $sql_update_local = "UPDATE clasificacion_general 
                                        SET partidos_jugados = ?,
                                            goles_favor = ?, goles_contra = ?, diferencia_goles = ?, 
                                            tarjetas_amarillas = ?, tarjetas_rojas = ?, faltas_cometidas = ?,
                                            Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                        WHERE id_clasificacion = ?";
                    
                    $stmt_update_local = $conn->prepare($sql_update_local);
                    $stmt_update_local->bind_param(
                        "iiiiiiisss",
                        $partidos_jugados_local_sus,
                        $goles_favor_local_sus,
                        $goles_contra_local_sus,
                        $diferencia_goles_local_sus,
                        $tarjetas_amarillas_total_local_sus,
                        $tarjetas_rojas_total_local_sus,
                        $faltas_total_local_sus,
                        $partido_data['Jornada'],
                        $descripcion_clasificacion,
                        $clasif_local_sus['id_clasificacion']
                    );
                    $stmt_update_local->execute();
                    $stmt_update_local->close();
                } else {
                    // INSERT nuevo (solo si no existe)
                    $id_clasificacion_local = generarIdUnico($conn, 'clasificacion_general', 'id_clasificacion', 7);
                    
                    $sql_insert_local = "INSERT INTO clasificacion_general 
                                        (id_clasificacion, id_liga, id_equipo, id_deporte, puntos, 
                                         partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                         goles_favor, goles_contra, diferencia_goles, tarjetas_amarillas, 
                                         tarjetas_rojas, faltas_cometidas, Jornada, fecha_creacion, 
                                         fecha_actualizacion, descripcion)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                                               CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)";
                    
                    $stmt_insert_local = $conn->prepare($sql_insert_local);
                    $stmt_insert_local->bind_param(
                        "isssiiiiiiiiiiiss",
                        $id_clasificacion_local,
                        $id_liga_admin,
                        $equipo_local['id_equipo'],
                        $id_deporte,
                        $puntos_total_local_sus,
                        $partidos_jugados_local_sus,
                        $partidos_ganados_local_sus,
                        $partidos_empatados_local_sus,
                        $partidos_perdidos_local_sus,
                        $goles_favor_local_sus,
                        $goles_contra_local_sus,
                        $diferencia_goles_local_sus,
                        $tarjetas_amarillas_total_local_sus,
                        $tarjetas_rojas_total_local_sus,
                        $faltas_total_local_sus,
                        $partido_data['Jornada'],
                        $descripcion_clasificacion
                    );
                    $stmt_insert_local->execute();
                    $stmt_insert_local->close();
                }
                
                // Determinar si es UPDATE o INSERT para VISITANTE (SUSPENDIDO)
                if ($clasif_visitante_sus['id_clasificacion']) {
                    // UPDATE existente
                    $sql_update_visitante = "UPDATE clasificacion_general 
                                            SET partidos_jugados = ?,
                                                goles_favor = ?, goles_contra = ?, diferencia_goles = ?, 
                                                tarjetas_amarillas = ?, tarjetas_rojas = ?, faltas_cometidas = ?,
                                                Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                            WHERE id_clasificacion = ?";
                    
                    $stmt_update_visitante = $conn->prepare($sql_update_visitante);
                    $stmt_update_visitante->bind_param(
                        "iiiiiiisss",
                        $partidos_jugados_visitante_sus,
                        $goles_favor_visitante_sus,
                        $goles_contra_visitante_sus,
                        $diferencia_goles_visitante_sus,
                        $tarjetas_amarillas_total_visitante_sus,
                        $tarjetas_rojas_total_visitante_sus,
                        $faltas_total_visitante_sus,
                        $partido_data['Jornada'],
                        $descripcion_clasificacion,
                        $clasif_visitante_sus['id_clasificacion']
                    );
                    $stmt_update_visitante->execute();
                    $stmt_update_visitante->close();
                } else {
                    // INSERT nuevo (solo si no existe)
                    $id_clasificacion_visitante = generarIdUnico($conn, 'clasificacion_general', 'id_clasificacion', 7);
                    
                    $sql_insert_visitante = "INSERT INTO clasificacion_general 
                                            (id_clasificacion, id_liga, id_equipo, id_deporte, puntos, 
                                             partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                             goles_favor, goles_contra, diferencia_goles, tarjetas_amarillas, 
                                             tarjetas_rojas, faltas_cometidas, Jornada, fecha_creacion, 
                                             fecha_actualizacion, descripcion)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                                                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)";
                    
                    $stmt_insert_visitante = $conn->prepare($sql_insert_visitante);
                    $stmt_insert_visitante->bind_param(
                        "isssiiiiiiiiiiiss",
                        $id_clasificacion_visitante,
                                        $id_liga_admin,
                                        $equipo_visitante['id_equipo'],
                                        $id_deporte,
                                        $puntos_total_visitante_sus,
                                        $partidos_jugados_visitante_sus,
                                        $partidos_ganados_visitante_sus,
                                        $partidos_empatados_visitante_sus,
                                        $partidos_perdidos_visitante_sus,
                                        $goles_favor_visitante_sus,
                                        $goles_contra_visitante_sus,
                                        $diferencia_goles_visitante_sus,
                                        $tarjetas_amarillas_total_visitante_sus,
                                        $tarjetas_rojas_total_visitante_sus,
                                        $faltas_total_visitante_sus,
                                        $partido_data['Jornada'],
                                        $descripcion_clasificacion
                                    );
                    $stmt_insert_visitante->execute();
                    $stmt_insert_visitante->close();
                }
                
                // ======================================================================
                // 8.3. ACTUALIZAR POSICIONES EN CLASIFICACIÓN
                // ======================================================================
                actualizarPosicionesClasificacion($conn, $id_liga_admin);
                
                // ======================================================================
                // 8.4. ACTUALIZAR CALENDARIO
                // ======================================================================
                $descripcion_completa = "SUSPENDIDO - " . $descripcion_suspension . 
                                        " | Resultado parcial: " . $goles_local . "-" . $goles_visitante . 
                                        " | Tiempo jugado: " . $tiempo_jugado . " minutos";
                
                $sql_update = "UPDATE Calendario 
                              SET Estado = 'SUSPENDIDO', 
                                  fecha = ?, hora = ?, direccion = ?, 
                                  descripcion = ?, goles_local = ?, 
                                  goles_visitante = ?, Tiempo_Jugado = ?,
                                  fecha_actualizacion = CURRENT_TIMESTAMP
                              WHERE Id_Partido = ?";
                
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("ssssiiis", $nueva_fecha, $nueva_hora, $nueva_direccion, 
                                       $descripcion_completa, $goles_local, $goles_visitante, $tiempo_jugado, $id_partido);
                
                if ($stmt_update->execute()) {
                    // ======================================================================
                    // 8.5. REGISTRAR TARJETAS CON LÓGICA MODIFICADA
                    // ======================================================================
                    registrarTarjetasConLogica($conn, $tarjetas_amarillas_local, $tarjetas_rojas_local, 
                                              $equipo_local['id_equipo'], $id_partido, $id_liga_admin);
                    registrarTarjetasConLogica($conn, $tarjetas_amarillas_visitante, $tarjetas_rojas_visitante, 
                                              $equipo_visitante['id_equipo'], $id_partido, $id_liga_admin);
                    
                    $conn->commit();
                    
                    $mensajeExito = "✅ Partido suspendido y reprogramado. Goles parciales: $goles_local - $goles_visitante";
                    
                    // ======================================================================
                    // 8.6. ELIMINAR PERMISO
                    // ======================================================================
                    $sql_eliminar = "DELETE FROM adminsolicitud 
                                    WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                    $stmt_eliminar = $conn->prepare($sql_eliminar);
                    $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                    $stmt_eliminar->execute();
                    $stmt_eliminar->close();
                    
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "AdminCalendario.php";
                        }, 2000);
                    </script>';
                } else {
                    throw new Exception("Error al actualizar el partido.");
                }
                $stmt_update->close();
                
            } catch (Exception $e) {
                $conn->rollback();
                $mensajeError = "❌ Error: " . $e->getMessage();
            }
        }
    }
}
                
                // ======================================================================
                // 9. PROCESAR FORMULARIO DE POSPUESTO
                // ======================================================================
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_pospuesto'])) {
                    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                        $mensajeError = "❌ Token de seguridad inválido.";
                    } else {
                        $nueva_fecha = $_POST['nueva_fecha'];
                        $nueva_hora = $_POST['nueva_hora'];
                        $nueva_direccion = $_POST['nueva_direccion'];
                        $descripcion = trim($_POST['descripcion']);
                        
                        if (empty($descripcion)) {
                            $mensajeError = "❌ La razón del pospuesto es obligatoria.";
                        } else {
                            $sql_update = "UPDATE Calendario 
                                          SET Estado = 'POSPUESTO',
                                              fecha = ?, hora = ?, direccion = ?, descripcion = ?,
                                              fecha_actualizacion = CURRENT_TIMESTAMP
                                          WHERE Id_Partido = ?";
                            
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->bind_param("sssss", $nueva_fecha, $nueva_hora, $nueva_direccion, $descripcion, $id_partido);
                            
                            if ($stmt_update->execute()) {
                                $mensajeExito = "✅ Partido reprogramado correctamente.";
                                
                                $sql_eliminar = "DELETE FROM adminsolicitud 
                                                WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                                $stmt_eliminar = $conn->prepare($sql_eliminar);
                                $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                                $stmt_eliminar->execute();
                                $stmt_eliminar->close();
                                
                                echo '<script>
                                    setTimeout(function() {
                                        window.location.href = "AdminCalendario.php";
                                    }, 2000);
                                </script>';
                            } else {
                                $mensajeError = "❌ Error al reprogramar el partido.";
                            }
                            $stmt_update->close();
                        }
                    }
                }
                
                // ======================================================================
                // 10. PROCESAR FORMULARIO DE CANCELADO
                // ======================================================================
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_cancelado'])) {
                    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                        $mensajeError = "❌ Token de seguridad inválido.";
                    } else {
                        $descripcion_cancelacion = trim($_POST['descripcion_cancelacion']);
                        
                        if (empty($descripcion_cancelacion)) {
                            $mensajeError = "❌ La razón de la cancelación es obligatoria.";
                        } else {
                            $sql_update = "UPDATE Calendario 
                                          SET Estado = 'CANCELADO', descripcion = ?, 
                                              fecha_actualizacion = CURRENT_TIMESTAMP
                                          WHERE Id_Partido = ?";
                            
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->bind_param("ss", $descripcion_cancelacion, $id_partido);
                            
                            if ($stmt_update->execute()) {
                                $mensajeExito = "✅ Partido cancelado correctamente.";
                                
                                $sql_eliminar = "DELETE FROM adminsolicitud 
                                                WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                                $stmt_eliminar = $conn->prepare($sql_eliminar);
                                $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                                $stmt_eliminar->execute();
                                $stmt_eliminar->close();
                                
                                echo '<script>
                                    setTimeout(function() {
                                        window.location.href = "AdminCalendario.php";
                                    }, 2000);
                                </script>';
                            } else {
                                $mensajeError = "❌ Error al cancelar el partido.";
                            }
                            $stmt_update->close();
                        }
                    }
                }
                
            } else {
                $mensajeError = "❌ No se encontró el partido especificado.";
            }
            $stmt_partido->close();
            
        } else {
            $mensajeError = "❌ No tienes permisos para acceder a este partido.";
        }
        $stmt_verificar->close();
    }
} else {
    $mensajeError = "❌ Datos incompletos. Se requiere ID de partido y liga.";
}

// ======================================================================
// FUNCIONES AUXILIARES NUEVAS
// ======================================================================

function generarIdUnico($conn, $tabla, $campo_id, $longitud = 7) {
    $id_unico = false;
    $intentos = 0;
    
    while (!$id_unico && $intentos < 100) {
        $min = pow(10, $longitud - 1);
        $max = pow(10, $longitud) - 1;
        $id_generado = rand($min, $max);
        
        $sql_verificar = "SELECT $campo_id FROM $tabla WHERE $campo_id = ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->bind_param("i", $id_generado);
        $stmt_verificar->execute();
        $res_verificar = $stmt_verificar->get_result();
        
        if ($res_verificar->num_rows == 0) {
            $id_unico = $id_generado;
        }
        
        $stmt_verificar->close();
        $intentos++;
    }
    
    if (!$id_unico) {
        $id_unico = intval(date('YmdHis')) % pow(10, $longitud);
    }
    
    return $id_unico;
}

function actualizarPosicionesClasificacion($conn, $id_liga) {
    $sql = "SELECT cg.* FROM clasificacion_general cg
            INNER JOIN (
                SELECT id_equipo, MAX(fecha_actualizacion) as ultima_fecha
                FROM clasificacion_general
                WHERE id_liga = ?
                GROUP BY id_equipo
            ) ultima ON cg.id_equipo = ultima.id_equipo AND cg.fecha_actualizacion = ultima.ultima_fecha
            WHERE cg.id_liga = ?
            ORDER BY cg.puntos DESC, cg.diferencia_goles DESC, cg.goles_favor DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $id_liga, $id_liga);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $posicion = 1;
    while ($clasif = $result->fetch_assoc()) {
        $sql_update = "UPDATE clasificacion_general 
                      SET posicion = ? 
                      WHERE id_clasificacion = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ii", $posicion, $clasif['id_clasificacion']);
        $stmt_update->execute();
        $stmt_update->close();
        $posicion++;
    }
    
    $stmt->close();
}

// ======================================================================
// FUNCIÓN PARA OBTENER O CREAR REGISTRO DE CLASIFICACIÓN
// ======================================================================
function obtenerActualizarClasificacion($conn, $id_equipo, $id_liga, $id_deporte, $jornada) {
    // Buscar si ya existe clasificación para este equipo en esta liga
    $sql_buscar = "SELECT * FROM clasificacion_general 
                  WHERE id_equipo = ? AND id_liga = ? 
                  ORDER BY fecha_actualizacion DESC LIMIT 1";
    $stmt_buscar = $conn->prepare($sql_buscar);
    $stmt_buscar->bind_param("ss", $id_equipo, $id_liga);
    $stmt_buscar->execute();
    $res_buscar = $stmt_buscar->get_result();
    
    if ($res_buscar->num_rows > 0) {
        // Ya existe, devolver datos existentes
        $clasif = $res_buscar->fetch_assoc();
        $stmt_buscar->close();
        return $clasif;
    } else {
        // No existe, crear nuevo registro vacío
        $stmt_buscar->close();
        return [
            'id_clasificacion' => null,
            'puntos' => 0,
            'partidos_jugados' => 0,
            'partidos_ganados' => 0,
            'partidos_empatados' => 0,
            'partidos_perdidos' => 0,
            'goles_favor' => 0,
            'goles_contra' => 0,
            'diferencia_goles' => 0,
            'tarjetas_amarillas' => 0,
            'tarjetas_rojas' => 0,
            'faltas_cometidas' => 0
        ];
    }
}

// ======================================================================
// FUNCIÓN ACTUALIZADA PARA REGISTRAR GOLES DE JUGADORES
// ======================================================================
function registrarGolesJugadores($conn, $goles_por_jugador, $id_equipo, $id_liga, $id_partido) {
    foreach ($goles_por_jugador as $id_jugador => $cantidad_goles) {
        if ($cantidad_goles > 0) {
            // Verificar si ya existe estadística para este jugador en esta liga y equipo
            $sql_check = "SELECT id_estadistica, goles FROM estadisticasretador 
                         WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();
            
            if ($res_check->num_rows > 0) {
                // ACTUALIZAR estadística existente
                $estadistica = $res_check->fetch_assoc();
                $nuevos_goles = $estadistica['goles'] + $cantidad_goles;
                
                $sql_update = "UPDATE estadisticasretador 
                              SET goles = ?, fecha_actualizacion = CURDATE(), 
                                  hora_actualizacion = CURTIME(),
                                  Actualizacion_anterior = CONCAT(COALESCE(Actualizacion_anterior, ''), ' | Goles añadidos: ', ?, ' en partido ', ?)
                              WHERE id_estadistica = ?";
                $stmt_update = $conn->prepare($sql_update);
                $descripcion = "$cantidad_goles gol(es) en partido $id_partido";
                $stmt_update->bind_param("issi", $nuevos_goles, $descripcion, $id_partido, $estadistica['id_estadistica']);
                $stmt_update->execute();
                $stmt_update->close();
            } else {
                // Crear nueva estadística solo si no existe
                $id_estadistica = 'EST_' . $id_jugador . '_' . $id_liga . '_' . time();
                
                $sql_insert = "INSERT INTO estadisticasretador 
                              (id_estadistica, id_retador, id_equipo, id_liga, goles,
                               fecha_actualizacion, hora_actualizacion, descripciones, Actualizacion_anterior)
                              VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                $descripcion = "Estadísticas para jugador";
                $actualizacion = "Registro inicial: $cantidad_goles gol(es) en partido $id_partido";
                $stmt_insert->bind_param("ssssiss", $id_estadistica, $id_jugador, $id_equipo, $id_liga, 
                                        $cantidad_goles, $descripcion, $actualizacion);
                $stmt_insert->execute();
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
    }
}

function registrarTarjetasConLogica($conn, $amarillas, $rojas, $id_equipo, $id_partido, $id_liga) {
    // ======================================================================
    // PRIMERO: VERIFICAR Y ACTUALIZAR TODOS LOS JUGADORES DEL EQUIPO
    // ======================================================================
    
    // 1. Obtener partidos_jugados del equipo desde clasificacion_general
    $sql_partidos_equipo = "SELECT partidos_jugados FROM clasificacion_general 
                           WHERE id_equipo = ? AND id_liga = ? 
                           ORDER BY fecha_actualizacion DESC LIMIT 1";
    $stmt_partidos_equipo = $conn->prepare($sql_partidos_equipo);
    $stmt_partidos_equipo->bind_param("ss", $id_equipo, $id_liga);
    $stmt_partidos_equipo->execute();
    $res_partidos_equipo = $stmt_partidos_equipo->get_result();
    
    $partidos_jugados_equipo = 0;
    if ($res_partidos_equipo->num_rows > 0) {
        $equipo_data = $res_partidos_equipo->fetch_assoc();
        $partidos_jugados_equipo = $equipo_data['partidos_jugados'];
    }
    $stmt_partidos_equipo->close();
    
    // DEBUG: Mostrar partidos_jugados del equipo
    error_log("DEBUG: Equipo $id_equipo tiene $partidos_jugados_equipo partidos jugados");
    
    // 2. Obtener TODOS los jugadores del equipo (no solo los que tienen tarjetas en este partido)
    $sql_todos_jugadores = "SELECT DISTINCT ej.Id_Jugador 
                           FROM equipo_jugador ej
                           WHERE ej.Id_Equipo = ?";
    $stmt_todos_jugadores = $conn->prepare($sql_todos_jugadores);
    $stmt_todos_jugadores->bind_param("s", $id_equipo);
    $stmt_todos_jugadores->execute();
    $res_todos_jugadores = $stmt_todos_jugadores->get_result();
    
    $jugadores_del_equipo = [];
    while ($row = $res_todos_jugadores->fetch_assoc()) {
        $jugadores_del_equipo[] = $row['Id_Jugador'];
    }
    $stmt_todos_jugadores->close();
    
    error_log("DEBUG: Equipo $id_equipo tiene " . count($jugadores_del_equipo) . " jugadores");
    
    // 3. Para CADA jugador del equipo, verificar si ya cumplió suspensión
    foreach ($jugadores_del_equipo as $id_jugador) {
        // Consultar tarjetas existentes del jugador
        $sql_jugador = "SELECT id_estadistica, tarjetas_amarillas, tarjetas_rojas 
                       FROM estadisticasretador 
                       WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?
                       ORDER BY fecha_actualizacion DESC LIMIT 1";
        $stmt_jugador = $conn->prepare($sql_jugador);
        $stmt_jugador->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
        $stmt_jugador->execute();
        $res_jugador = $stmt_jugador->get_result();
        
        if ($res_jugador->num_rows > 0) {
            $jugador_data = $res_jugador->fetch_assoc();
            $rojas_existentes = $jugador_data['tarjetas_rojas'];
            $id_estadistica = $jugador_data['id_estadistica'];
            
            // Verificar si ya cumplió suspensión
            if ($rojas_existentes > 0) {
                error_log("DEBUG: Jugador $id_jugador tiene $rojas_existentes rojas. Partidos equipo: $partidos_jugados_equipo");
                
                if ($rojas_existentes == $partidos_jugados_equipo) {
                    // ¡YA CUMPLIÓ LA SUSPENSIÓN!
                    $descripcion_log = "¡SUSPENSIÓN CUMPLIDA! Partidos equipo: $partidos_jugados_equipo = Rojas jugador: $rojas_existentes";
                    
                    $sql_update = "UPDATE estadisticasretador 
                                  SET tarjetas_rojas = 0, 
                                      tarjetas_amarillas = 0,
                                      fecha_actualizacion = CURDATE(), 
                                      hora_actualizacion = CURTIME(),
                                      Actualizacion_anterior = CONCAT(COALESCE(Actualizacion_anterior, ''), ' | ', ?)
                                  WHERE id_estadistica = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->bind_param("ss", $descripcion_log, $id_estadistica);
                    
                    if ($stmt_update->execute()) {
                        error_log("ÉXITO: Jugador $id_jugador cumplió suspensión. Tarjetas rojas puestas a 0.");
                    }
                    $stmt_update->close();
                }
            }
        }
        $stmt_jugador->close();
    }
    
    // ======================================================================
    // SEGUNDO: PROCESAR TARJETAS DEL PARTIDO ACTUAL
    // ======================================================================
    
    // Contar tarjetas amarillas por jugador
    $amarillas_por_jugador = array_count_values($amarillas);
    $rojas_por_jugador = array_count_values($rojas);
    
    // Procesar todos los jugadores con tarjetas en ESTE partido
    $todos_jugadores_partido = array_unique(array_merge(array_keys($amarillas_por_jugador), array_keys($rojas_por_jugador)));
    
    foreach ($todos_jugadores_partido as $id_jugador) {
        $cantidad_amarillas = $amarillas_por_jugador[$id_jugador] ?? 0;
        $cantidad_rojas_directas = $rojas_por_jugador[$id_jugador] ?? 0;
        
        // ======================================================================
        // CONSULTAR TARJETAS EXISTENTES DEL JUGADOR (después de la limpieza)
        // ======================================================================
        $sql_existentes = "SELECT id_estadistica, tarjetas_amarillas, tarjetas_rojas 
                          FROM estadisticasretador 
                          WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?
                          ORDER BY fecha_actualizacion DESC LIMIT 1";
        $stmt_existentes = $conn->prepare($sql_existentes);
        $stmt_existentes->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
        $stmt_existentes->execute();
        $res_existentes = $stmt_existentes->get_result();
        
        $amarillas_existentes = 0;
        $rojas_existentes = 0;
        $id_estadistica = null;
        
        if ($res_existentes->num_rows > 0) {
            $existentes_data = $res_existentes->fetch_assoc();
            $amarillas_existentes = $existentes_data['tarjetas_amarillas'];
            $rojas_existentes = $existentes_data['tarjetas_rojas'];
            $id_estadistica = $existentes_data['id_estadistica'];
            
            error_log("DEBUG: Jugador $id_jugador - Después de limpieza: Amarillas=$amarillas_existentes, Rojas=$rojas_existentes");
        }
        $stmt_existentes->close();
        
        // ======================================================================
        // LÓGICA: 2 AMARILLAS ACUMULADAS = 1 ROJA
        // ======================================================================
        // Sumar amarillas existentes + nuevas amarillas
        $amarillas_totales = $amarillas_existentes + $cantidad_amarillas;
        
        // Verificar si acumula 2 amarillas
        $conversion_por_amarillas = floor($amarillas_totales / 2);
        
        // Calcular rojas totales (directas + por conversión de amarillas)
        $rojas_totales = $cantidad_rojas_directas + $conversion_por_amarillas;
        
        // ======================================================================
        // NUEVA LÓGICA PARA TARJETAS ROJAS
        // ======================================================================
        $rojas_final = 0;
        $amarillas_final = 0;
        
        // Si hay rojas nuevas en este partido
        if ($rojas_totales > 0) {
            // ASIGNAR NUEVA SUSPENSIÓN
            // tarjetas_rojas = partidos_jugados + 1
            $rojas_final = $partidos_jugados_equipo + 1;
            $amarillas_final = $amarillas_totales % 2; // Solo quedan las amarillas sobrantes
            
            error_log("DEBUG: Nueva roja para jugador $id_jugador - Rojas final: $rojas_final");
        } else {
            // No hay rojas nuevas
            // Solo actualizar amarillas (el residuo después de posibles conversiones)
            $amarillas_final = $amarillas_totales % 2;
            $rojas_final = 0;
            
            // Si ya tenía rojas existentes, mantenerlas (ya fueron verificadas arriba)
            if ($rojas_existentes > 0) {
                $rojas_final = $rojas_existentes;
                $amarillas_final = 0; // Si tiene rojas, amarillas = 0
            }
        }
        // ======================================================================
// VALIDACIÓN: Si tiene tarjetas_rojas > 0, entonces tarjetas_amarillas debe ser 0
// ======================================================================
if ($rojas_final > 0) {
    $amarillas_final = 0;
    error_log("DEBUG: Jugador $id_jugador tiene rojas ($rojas_final), se fuerza amarillas a 0");
}
        
        // ======================================================================
        // ACTUALIZAR ESTADÍSTICAS DEL JUGADOR
        // ======================================================================
        if ($id_estadistica) {
            // ACTUALIZAR estadística existente
            $descripcion_log = "Partido: $id_partido | ";
            $descripcion_log .= "Tarjetas en partido: $cantidad_amarillas amarilla(s), $cantidad_rojas_directas roja(s) | ";
            $descripcion_log .= "Acumuladas: $amarillas_totales amarillas | ";
            $descripcion_log .= "Partidos equipo: $partidos_jugados_equipo | ";
            
            if ($rojas_totales > 0) {
                $descripcion_log .= "Nueva suspensión: $rojas_final partidos";
            } else {
                $descripcion_log .= "Estado final: $amarillas_final amarilla(s), $rojas_final roja(s)";
            }
            
            $sql_update = "UPDATE estadisticasretador 
                          SET tarjetas_amarillas = ?, 
                              tarjetas_rojas = ?,
                              fecha_actualizacion = CURDATE(), 
                              hora_actualizacion = CURTIME(),
                              Actualizacion_anterior = CONCAT(COALESCE(Actualizacion_anterior, ''), ' | ', ?)
                          WHERE id_estadistica = ?";
            $stmt_update = $conn->prepare($sql_update);
            
            $stmt_update->bind_param("iiss", 
                $amarillas_final,          // i - tarjetas_amarillas
                $rojas_final,              // i - tarjetas_rojas
                $descripcion_log,          // s - descripción para log
                $id_estadistica            // s - id_estadistica
            );
            
            if ($stmt_update->execute()) {
                error_log("ÉXITO: Actualizado jugador $id_jugador - Amarillas: $amarillas_final, Rojas: $rojas_final");
            } else {
                error_log("ERROR: No se pudo actualizar jugador $id_jugador: " . $stmt_update->error);
            }
            $stmt_update->close();
        } else {
            // Crear nueva estadística solo si no existe y hay datos que guardar
            if ($amarillas_final > 0 || $rojas_final > 0) {
                $nuevo_id_estadistica = 'EST_' . $id_jugador . '_' . $id_liga . '_' . time();
                
                $sql_insert = "INSERT INTO estadisticasretador 
                              (id_estadistica, id_retador, id_equipo, id_liga, 
                               tarjetas_amarillas, tarjetas_rojas,
                               fecha_actualizacion, hora_actualizacion, descripciones, Actualizacion_anterior)
                              VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                $descripcion = "Estadísticas de tarjetas";
                
                if ($rojas_final > 0) {
                    $actualizacion = "Nueva tarjeta roja: suspensión $rojas_final partidos";
                } else {
                    $actualizacion = "Tarjetas: $amarillas_final amarilla(s)";
                }
                
                $stmt_insert->bind_param("ssssiiss", 
                    $nuevo_id_estadistica,  // s - id_estadistica
                    $id_jugador,            // s - id_retador
                    $id_equipo,             // s - id_equipo
                    $id_liga,               // s - id_liga
                    $amarillas_final,       // i - tarjetas_amarillas
                    $rojas_final,           // i - tarjetas_rojas
                    $descripcion,           // s - descripciones
                    $actualizacion          // s - Actualizacion_anterior
                );
                
                $stmt_insert->execute();
                $stmt_insert->close();
            }
        }
    }
    
    return true;
}
// ======================================================================

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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrar Resultados - RETAME</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
/* Estilos CSS del anterior código (mantenidos) */
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}
.container {
    max-width: 1400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}
.header {
    text-align: center;
    margin-bottom: 40px;
    padding-bottom: 25px;
    border-bottom: 3px solid #667eea;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    padding: 25px;
    border-radius: 10px;
}
.error {
    background: #ffebee;
    color: #c62828;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
    border-left: 6px solid #c62828;
    font-size: 16px;
}
.success {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
    border-left: 6px solid #2e7d32;
    font-size: 16px;
}
.equipos-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
}
.equipo-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-top: 5px solid;
}
.equipo-card.local {
    border-color: #667eea;
    background: linear-gradient(to bottom, #f0f4ff, #ffffff);
}
.equipo-card.visitante {
    border-color: #ff6b6b;
    background: linear-gradient(to bottom, #fff0f0, #ffffff);
}
.form-group {
    margin-bottom: 25px;
}
.form-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: bold;
    color: #333;
    font-size: 16px;
}
.seleccion-count {
    background: #667eea;
    color: white;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 14px;
    font-weight: bold;
    margin-left: 10px;
}
.marcador-goles {
    text-align: center;
    font-size: 28px;
    font-weight: bold;
    margin: 20px 0;
    padding: 15px;
    background: linear-gradient(135deg, #4CAF50, #2e7d32);
    color: white;
    border-radius: 10px;
}
.btn-guardar {
    background: linear-gradient(135deg, #4CAF50, #2e7d32);
    color: white;
    border: none;
    padding: 18px 40px;
    border-radius: 10px;
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    transition: background 0.3s;
    margin-top: 20px;
}
.btn-guardar:hover {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
}
.jugador-info {
    font-size: 12px;
    color: #666;
    margin-left: 5px;
}
.jugador-info.capitan {
    color: #ff6b00;
    font-weight: bold;
}
.jugador-info.jugador {
    color: #2196F3;
}
/* Estilos para opciones de estado */
.opciones-estado {
    background: white;
    border-radius: 12px;
    padding: 40px;
    margin: 30px 0;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.opcion-estado {
    display: flex;
    align-items: center;
    padding: 20px;
    margin: 15px 0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid #ddd;
}
.opcion-estado:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.opcion-estado input[type="radio"] {
    margin-right: 20px;
    transform: scale(1.5);
}
.opcion-estado label {
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    flex-grow: 1;
}
.descripcion-opcion {
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}
.opcion-finalizado {
    border-color: #4CAF50;
    background: linear-gradient(to right, #f1f8e9, white);
}
.opcion-pospuesto {
    border-color: #FF9800;
    background: linear-gradient(to right, #FFF3E0, white);
}
.opcion-suspendido {
    border-color: #2196F3;
    background: linear-gradient(to right, #E3F2FD, white);
}
.opcion-cancelado {
    border-color: #F44336;
    background: linear-gradient(to right, #FFEBEE, white);
}
.btn-continuar {
    background: linear-gradient(135deg, #2196F3, #0d47a1);
    color: white;
    border: none;
    padding: 18px 40px;
    border-radius: 10px;
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    transition: background 0.3s;
    margin-top: 20px;
}
.btn-continuar:hover {
    background: linear-gradient(135deg, #0d47a1, #002171);
}
.btn-regresar {
    display: inline-block;
    background: #9c27b0;
    color: white;
    padding: 12px 25px;
    border-radius: 8px;
    text-decoration: none;
    margin-bottom: 20px;
    font-weight: bold;
    transition: background 0.3s;
}
.btn-regresar:hover {
    background: #6a1b9a;
    color: white;
    text-decoration: none;
}
/* Estilos nuevos para la selección de goles */
.btn-agregar-gol {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    margin-top: 10px;
    cursor: pointer;
    font-size: 14px;
}
.btn-agregar-gol:hover {
    background: #2e7d32;
}
.lista-goles {
    margin-top: 10px;
    min-height: 50px;
    border: 1px dashed #ddd;
    padding: 10px;
    border-radius: 5px;
}
.gol-item {
    background: #e8f5e9;
    padding: 8px;
    margin: 5px 0;
    border-radius: 5px;
    border-left: 4px solid #4CAF50;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.btn-eliminar-gol {
    background: #ff6b6b;
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-eliminar-gol:hover {
    background: #c62828;
}
/* Estilos para select */
.select-jugadores {
    width: 100%;
    margin-bottom: 10px;
}
/* Estilos para íconos de tarjetas */
.tarjeta-icono {
    font-size: 16px;
    margin-left: 3px;
    vertical-align: middle;
}
.tarjeta-icono.amarilla {
    color: #FFD700;
}
.tarjeta-icono.roja {
    color: #FF0000;
}

/* Estilo para jugadores deshabilitados */
.select2-results__option[aria-disabled=true] {
    color: #ccc;
    background-color: #ffebee !important;
    cursor: not-allowed;
}

.select2-results__option[aria-disabled=true]:hover {
    background-color: #ffebee !important;
}

/* Estilo para mostrar tarjetas en la selección */
.select2-selection__rendered .tarjeta-icono {
    font-size: 14px;
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Registrar Resultados - RETAME'); } ?>
<div class="container">
    <a href="AdminCalendario.php" class="btn-regresar">
        ↩️ Regresar a Calendario
    </a>
    
    <div class="header">
        <h1>📊 Registrar Estado del Partido</h1>
        <?php if (!empty($nombre_liga)): ?>
        <p><strong>Liga:</strong> <?php echo htmlspecialchars($nombre_liga); ?></p>
        <?php endif; ?>
        <?php if ($partido_data): ?>
        <p><strong>Jornada:</strong> <?php echo htmlspecialchars($partido_data['Jornada']); ?> | 
           <strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($partido_data['fecha'])); ?> |
           <strong>Hora:</strong> <?php echo date('H:i', strtotime($partido_data['hora'])); ?></p>
        <p><strong>Partido:</strong> <?php echo htmlspecialchars($equipo_local['nombre']); ?> vs <?php echo htmlspecialchars($equipo_visitante['nombre']); ?></p>
        <?php endif; ?>
    </div>
    
    <?php if ($mensajeError): ?>
        <div class="error">
            <strong>⚠️ Error:</strong> <?php echo $mensajeError; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($mensajeExito): ?>
        <div class="success">
            <strong>✅ Éxito:</strong> <?php echo $mensajeExito; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($partido_data && $equipo_local && $equipo_visitante): ?>
        
        <!-- OPCIONES DE ESTADO (solo si no se ha seleccionado nada) -->
        <?php if ($mostrar_opciones && !$mostrar_formulario): ?>
        <div class="opciones-estado">
            <h3>📋 Seleccione el estado del partido:</h3>
            
            <form method="POST" action="" id="formSeleccionEstado">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="opcion-estado opcion-finalizado">
                    <input type="radio" id="estado_finalizado" name="estado_partido" value="FINALIZADO" required>
                    <div style="flex-grow: 1;">
                        <label for="estado_finalizado">1️⃣ FINALIZADO</label>
                        <div class="descripcion-opcion">El partido se ha completado. Podrás registrar el resultado final, goles por jugador, tarjetas y faltas.</div>
                    </div>
                </div>
                
                <div class="opcion-estado opcion-pospuesto">
                    <input type="radio" id="estado_pospuesto" name="estado_partido" value="POSPUESTO">
                    <div style="flex-grow: 1;">
                        <label for="estado_pospuesto">2️⃣ POSPUESTO</label>
                        <div class="descripcion-opcion">El partido se reprogramará para otra fecha. Deberás ingresar los nuevos datos y la razón.</div>
                    </div>
                </div>
                
                <div class="opcion-estado opcion-suspendido">
                    <input type="radio" id="estado_suspendido" name="estado_partido" value="SUSPENDIDO">
                    <div style="flex-grow: 1;">
                        <label for="estado_suspendido">3️⃣ SUSPENDIDO</label>
                        <div class="descripcion-opcion">El partido se suspendió y se reprogramará. Podrás registrar el resultado parcial hasta el momento de la suspensión.</div>
                    </div>
                </div>
                
                <div class="opcion-estado opcion-cancelado">
                    <input type="radio" id="estado_cancelado" name="estado_partido" value="CANCELADO">
                    <div style="flex-grow: 1;">
                        <label for="estado_cancelado">4️⃣ CANCELADO</label>
                        <div class="descripcion-opcion">El partido se cancela definitivamente. Deberás ingresar la razón de la cancelación.</div>
                    </div>
                </div>
                
                <button type="submit" name="seleccionar_estado" class="btn-continuar">
                    ➡️ CONTINUAR
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- FORMULARIO PARA FINALIZADO -->
        <?php if ($mostrar_formulario && $estado_seleccionado == 'FINALIZADO'): ?>
        <div class="opciones-estado">
            <h3>🏁 Registrar Partido Finalizado</h3>
            <p>Selecciona los jugadores que anotaron goles (cada selección cuenta como 1 gol):</p>
            
            <form method="POST" action="" id="formFinalizado">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="equipos-container">
                    <!-- EQUIPO LOCAL -->
                    <div class="equipo-card local">
                        <div class="equipo-header">
                            <div class="equipo-nombre local"><?php echo htmlspecialchars($equipo_local['nombre']); ?></div>
                            <div style="background: #667eea; color: white; padding: 8px 20px; border-radius: 25px; font-weight: bold;">EQUIPO LOCAL</div>
                        </div>
                        
                        <!-- GOLES POR JUGADOR (NUEVO SISTEMA) -->
                        <div class="form-group">
                            <label for="goles_jugadores_local">
                                ⚽ Jugadores que anotaron goles: 
                                <span id="count-goles-local" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Cada selección cuenta como 1 gol. Puedes hacer clic en el mismo jugador varias veces.
                                </small>
                                <button type="button" class="btn-agregar-gol" data-equipo="local" style="background: #4CAF50; color: white; border: none; padding: 8px 15px; border-radius: 5px; margin-top: 10px; cursor: pointer;">
                                    ➕ Agregar gol
                                </button>
                            </label>
                            
                            <!-- Este es el select oculto para el nombre del jugador -->
                            <select id="select_jugadores_local" class="select-jugadores select-jugadores-finalizado" data-equipo="local" style="width: 100%; margin-bottom: 10px;">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>" 
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <!-- Aquí se mostrarán los goles seleccionados -->
                            <div id="lista-goles-local" class="lista-goles" data-equipo="local" style="margin-top: 10px; min-height: 50px; border: 1px dashed #ddd; padding: 10px; border-radius: 5px;">
                                <!-- Los goles se agregarán aquí dinámicamente -->
                            </div>
                            
                            <!-- Input oculto que enviará los datos reales -->
                            <input type="hidden" id="goles_jugadores_local_input" name="goles_jugadores_local" value="[]">
                        </div>
                        
                        <!-- Tarjetas Amarillas -->
                        <div class="form-group">
                            <label for="tarjetas_amarillas_local">
                                🟡 Tarjetas Amarillas: 
                                <span id="count-amarillas-local" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_amarillas_local" name="tarjetas_amarillas_local[]" 
                                    class="select-tarjetas" multiple="multiple" data-equipo="local" data-tipo="amarilla">
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Tarjetas Rojas -->
                        <div class="form-group">
                            <label for="tarjetas_rojas_local">
                                🟥 Tarjetas Rojas: 
                                <span id="count-rojas-local" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Nota: 2 tarjetas amarillas se convertirán automáticamente en 1 roja
                                </small>
                            </label>
                            <select id="tarjetas_rojas_local" name="tarjetas_rojas_local[]" 
                                    class="select-tarjetas" multiple="multiple" data-equipo="local" data-tipo="roja">
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- EQUIPO VISITANTE -->
                    <div class="equipo-card visitante">
                        <div class="equipo-header">
                            <div class="equipo-nombre visitante"><?php echo htmlspecialchars($equipo_visitante['nombre']); ?></div>
                            <div style="background: #ff6b6b; color: white; padding: 8px 20px; border-radius: 25px; font-weight: bold;">EQUIPO VISITANTE</div>
                        </div>
                        
                        <!-- GOLES POR JUGADOR (NUEVO SISTEMA) -->
                        <div class="form-group">
                            <label for="goles_jugadores_visitante">
                                ⚽ Jugadores que anotaron goles: 
                                <span id="count-goles-visitante" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Cada selección cuenta como 1 gol. Puedes hacer clic en el mismo jugador varias veces.
                                </small>
                                <button type="button" class="btn-agregar-gol" data-equipo="visitante" style="background: #4CAF50; color: white; border: none; padding: 8px 15px; border-radius: 5px; margin-top: 10px; cursor: pointer;">
                                    ➕ Agregar gol
                                </button>
                            </label>
                            
                            <select id="select_jugadores_visitante" class="select-jugadores select-jugadores-finalizado" data-equipo="visitante" style="width: 100%; margin-bottom: 10px;">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div id="lista-goles-visitante" class="lista-goles" data-equipo="visitante" style="margin-top: 10px; min-height: 50px; border: 1px dashed #ddd; padding: 10px; border-radius: 5px;">
                                <!-- Los goles se agregarán aquí dinámicamente -->
                            </div>
                            
                            <input type="hidden" id="goles_jugadores_visitante_input" name="goles_jugadores_visitante" value="[]">
                        </div>
                        
                        <!-- Tarjetas Amarillas -->
                        <div class="form-group">
                            <label for="tarjetas_amarillas_visitante">
                                🟡 Tarjetas Amarillas: 
                                <span id="count-amarillas-visitante" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_amarillas_visitante" name="tarjetas_amarillas_visitante[]" 
                                    class="select-tarjetas" multiple="multiple" data-equipo="visitante" data-tipo="amarilla">
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Tarjetas Rojas -->
                        <div class="form-group">
                            <label for="tarjetas_rojas_visitante">
                                🟥 Tarjetas Rojas: 
                                <span id="count-rojas-visitante" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Nota: 2 tarjetas amarillas se convertirán automáticamente en 1 roja
                                </small>
                            </label>
                            <select id="tarjetas_rojas_visitante" name="tarjetas_rojas_visitante[]" 
                                    class="select-tarjetas" multiple="multiple" data-equipo="visitante" data-tipo="roja">
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- MARCADOR ACTUAL (calculado dinámicamente) -->
                <div class="marcador-goles">
                    🏆 MARCADOR ACTUAL: 
                    <span id="goles-local-display">0</span> - 
                    <span id="goles-visitante-display">0</span>
                </div>
                
                <!-- Tiempo Jugado -->
                <div class="form-group">
                    <label for="tiempo_jugado">⏱️ Tiempo Jugado (minutos):</label>
                    <input type="number" id="tiempo_jugado" name="tiempo_jugado" 
                           min="0" max="120" value="90" required>
                </div>
                
                <!-- Descripción -->
                <div class="form-group">
                    <label for="descripcion_finalizado">📝 Descripción adicional (opcional):</label>
                    <textarea id="descripcion_finalizado" name="descripcion_finalizado" 
                              placeholder="Observaciones sobre el partido..."></textarea>
                </div>
                
                <button type="submit" name="guardar_finalizado" class="btn-guardar">
                    💾 FINALIZAR PARTIDO Y GUARDAR RESULTADOS
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- FORMULARIO PARA SUSPENDIDO (CON NUEVA LÓGICA DE GOLES) -->
        <?php if ($mostrar_formulario && $estado_seleccionado == 'SUSPENDIDO'): ?>
        <div class="opciones-estado">
            <h3>⚠️ Partido Suspendido</h3>
            <p>Selecciona los jugadores que anotaron goles hasta el momento de la suspensión:</p>
            
            <form method="POST" action="" id="formSuspendido">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <!-- Razón de suspensión (OBLIGATORIA) -->
                <div class="form-group">
                    <label for="descripcion_suspension">📝 ¿Por qué se suspendió el partido? (obligatorio)</label>
                    <textarea id="descripcion_suspension" name="descripcion_suspension" required
                              placeholder="Describa el motivo de la suspensión..."></textarea>
                </div>
                
                <div class="equipos-container">
                    <!-- EQUIPO LOCAL -->
                    <div class="equipo-card local">
                        <div class="equipo-header">
                            <div class="equipo-nombre local"><?php echo htmlspecialchars($equipo_local['nombre']); ?></div>
                            <div style="background: #667eea; color: white; padding: 8px 20px; border-radius: 25px; font-weight: bold;">EQUIPO LOCAL</div>
                        </div>
                        
                        <!-- GOLES POR JUGADOR (NUEVO SISTEMA PARA SUSPENDIDO) -->
                        <div class="form-group">
                            <label for="goles_jugadores_local_sus">
                                ⚽ Jugadores que anotaron goles: 
                                <span id="count-goles-local-sus" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Cada selección cuenta como 1 gol. Resultado parcial hasta la suspensión.
                                </small>
                                <button type="button" class="btn-agregar-gol-sus" data-equipo="local" style="background: #4CAF50; color: white; border: none; padding: 8px 15px; border-radius: 5px; margin-top: 10px; cursor: pointer;">
                                    ➕ Agregar gol
                                </button>
                            </label>
                            
                            <select id="select_jugadores_local_sus" class="select-jugadores select-jugadores-suspendido" data-equipo="local" style="width: 100%; margin-bottom: 10px;">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div id="lista-goles-local-sus" class="lista-goles" data-equipo="local" style="margin-top: 10px; min-height: 50px; border: 1px dashed #ddd; padding: 10px; border-radius: 5px;">
                                <!-- Los goles se agregarán aquí dinámicamente -->
                            </div>
                            
                            <input type="hidden" id="goles_jugadores_local_sus_input" name="goles_jugadores_local" value="[]">
                        </div>
                        
                        <!-- Tarjetas -->
                        <div class="form-group">
                            <label for="tarjetas_amarillas_local_sus">
                                🟡 Tarjetas Amarillas: 
                                <span id="count-amarillas-local-sus" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_amarillas_local_sus" name="tarjetas_amarillas_local[]" 
                                    class="select-tarjetas-sus" multiple="multiple" data-equipo="local" data-tipo="amarilla">
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="tarjetas_rojas_local_sus">
                                🟥 Tarjetas Rojas: 
                                <span id="count-rojas-local-sus" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_rojas_local_sus" name="tarjetas_rojas_local[]" 
                                    class="select-tarjetas-sus" multiple="multiple" data-equipo="local" data-tipo="roja">
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- EQUIPO VISITANTE -->
                    <div class="equipo-card visitante">
                        <div class="equipo-header">
                            <div class="equipo-nombre visitante"><?php echo htmlspecialchars($equipo_visitante['nombre']); ?></div>
                            <div style="background: #ff6b6b; color: white; padding: 8px 20px; border-radius: 25px; font-weight: bold;">EQUIPO VISITANTE</div>
                        </div>
                        
                        <!-- GOLES POR JUGADOR (NUEVO SISTEMA PARA SUSPENDIDO) -->
                        <div class="form-group">
                            <label for="goles_jugadores_visitante_sus">
                                ⚽ Jugadores que anotaron goles: 
                                <span id="count-goles-visitante-sus" class="seleccion-count">0</span>
                                <small style="display: block; color: #666; margin-top: 5px;">
                                    Cada selección cuenta como 1 gol. Resultado parcial hasta la suspensión.
                                </small>
                                <button type="button" class="btn-agregar-gol-sus" data-equipo="visitante" style="background: #4CAF50; color: white; border: none; padding: 8px 15px; border-radius: 5px; margin-top: 10px; cursor: pointer;">
                                    ➕ Agregar gol
                                </button>
                            </label>
                            
                            <select id="select_jugadores_visitante_sus" class="select-jugadores select-jugadores-suspendido" data-equipo="visitante" style="width: 100%; margin-bottom: 10px;">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div id="lista-goles-visitante-sus" class="lista-goles" data-equipo="visitante" style="margin-top: 10px; min-height: 50px; border: 1px dashed #ddd; padding: 10px; border-radius: 5px;">
                                <!-- Los goles se agregarán aquí dinámicamente -->
                            </div>
                            
                            <input type="hidden" id="goles_jugadores_visitante_sus_input" name="goles_jugadores_visitante" value="[]">
                        </div>
                        
                        <!-- Tarjetas -->
                        <div class="form-group">
                            <label for="tarjetas_amarillas_visitante_sus">
                                🟡 Tarjetas Amarillas: 
                                <span id="count-amarillas-visitante-sus" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_amarillas_visitante_sus" name="tarjetas_amarillas_visitante[]" 
                                    class="select-tarjetas-sus" multiple="multiple" data-equipo="visitante" data-tipo="amarilla">
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="tarjetas_rojas_visitante_sus">
                                🟥 Tarjetas Rojas: 
                                <span id="count-rojas-visitante-sus" class="seleccion-count">0</span>
                            </label>
                            <select id="tarjetas_rojas_visitante_sus" name="tarjetas_rojas_visitante[]" 
                                    class="select-tarjetas-sus" multiple="multiple" data-equipo="visitante" data-tipo="roja">
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>"
                                            data-tarjetas-amarillas="<?php echo $jugador['tarjetas_amarillas']; ?>"
                                            data-tarjetas-rojas="<?php echo $jugador['tarjetas_rojas']; ?>"
                                            <?php echo $jugador['tarjetas_rojas'] >= 1 ? 'disabled style="color: #ccc; background-color: #ffebee;"' : ''; ?>>
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                        <?php if ($jugador['tarjetas_amarillas'] >= 1): ?>
                                            🟨
                                        <?php endif; ?>
                                        <?php if ($jugador['tarjetas_rojas'] >= 1): ?>
                                            🟥
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- MARCADOR PARCIAL -->
                <div class="marcador-goles" style="background: linear-gradient(135deg, #FF9800, #F57C00);">
                    ⚠️ MARCADOR PARCIAL (suspendido): 
                    <span id="goles-local-display-sus">0</span> - 
                    <span id="goles-visitante-display-sus">0</span>
                </div>
                
                <!-- Tiempo Jugado -->
                <div class="form-group">
                    <label for="tiempo_jugado">⏱️ Tiempo Jugado hasta la suspensión (minutos):</label>
                    <input type="number" id="tiempo_jugado" name="tiempo_jugado" 
                           min="0" max="120" value="0" required>
                </div>
                
                <!-- Datos de reprogramación -->
                <h4>📅 Datos de Reprogramación:</h4>
                <div class="form-group">
                    <label for="nueva_fecha">Nueva Fecha:</label>
                    <input type="date" id="nueva_fecha" name="nueva_fecha" 
                           value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required
                           min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="nueva_hora">Nueva Hora:</label>
                    <input type="time" id="nueva_hora" name="nueva_hora" 
                           value="18:00" required>
                </div>
                
                <div class="form-group">
                    <label for="nueva_direccion">Nueva Dirección:</label>
                    <input type="text" id="nueva_direccion" name="nueva_direccion" 
                           value="<?php echo htmlspecialchars($partido_data['direccion']); ?>" required>
                </div>
                
                <button type="submit" name="guardar_suspendido" class="btn-guardar" style="background: linear-gradient(135deg, #FF9800, #F57C00);">
                    💾 GUARDAR SUSPENSIÓN Y REPROGRAMAR
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- FORMULARIO PARA POSPUESTO -->
        <?php if ($mostrar_formulario && $estado_seleccionado == 'POSPUESTO'): ?>
        <div class="opciones-estado">
            <h3>📅 Reprogramar Partido (Pospuesto)</h3>
            <p>Ingrese los nuevos datos para el partido:</p>
            
            <form method="POST" action="" id="formPospuesto">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <!-- Datos de reprogramación -->
                <div class="form-group">
                    <label for="nueva_fecha">📅 Nueva Fecha:</label>
                    <input type="date" id="nueva_fecha" name="nueva_fecha" 
                           value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required
                           min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="nueva_hora">⏰ Nueva Hora:</label>
                    <input type="time" id="nueva_hora" name="nueva_hora" 
                           value="18:00" required>
                </div>
                
                <div class="form-group">
                    <label for="nueva_direccion">📍 Nueva Dirección:</label>
                    <input type="text" id="nueva_direccion" name="nueva_direccion" 
                           value="<?php echo htmlspecialchars($partido_data['direccion']); ?>" required>
                </div>
                
                <!-- Razón del pospuesto (OBLIGATORIA) -->
                <div class="form-group">
                    <label for="descripcion">📝 Razón del pospuesto (obligatorio):</label>
                    <textarea id="descripcion" name="descripcion" required
                              placeholder="Explique por qué se pospone el partido..."></textarea>
                </div>
                
                <button type="submit" name="guardar_pospuesto" class="btn-guardar" style="background: linear-gradient(135deg, #2196F3, #0d47a1);">
                    💾 REPROGRAMAR PARTIDO
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- FORMULARIO PARA CANCELADO -->
        <?php if ($mostrar_formulario && $estado_seleccionado == 'CANCELADO'): ?>
        <div class="opciones-estado">
            <h3>❌ Cancelar Partido</h3>
            <p>Ingrese la razón de la cancelación:</p>
            
            <form method="POST" action="" id="formCancelado">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <!-- Razón de cancelación (OBLIGATORIA) -->
                <div class="form-group">
                    <label for="descripcion_cancelacion">📝 Razón de la cancelación (obligatorio):</label>
                    <textarea id="descripcion_cancelacion" name="descripcion_cancelacion" required
                              placeholder="Explique por qué se cancela el partido..."></textarea>
                </div>
                
                <!-- Confirmación -->
                <div class="form-group">
                    <label>
                        <input type="checkbox" required> Confirmo que este partido se cancela definitivamente y no se reprogramará.
                    </label>
                </div>
                
                <button type="submit" name="guardar_cancelado" class="btn-guardar" style="background: linear-gradient(135deg, #F44336, #c62828);">
                    ❌ CONFIRMAR CANCELACIÓN
                </button>
            </form>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="text-align: center; padding: 50px; background: #f8f9fa; border-radius: 10px;">
            <h3>🔒 Acceso restringido o datos no encontrados</h3>
            <p>No se encontró información del partido o no tienes permisos para acceder.</p>
            <a href="AdminCalendario.php" class="btn-regresar" style="margin-top: 20px;">
                ↩️ Volver a Calendario
            </a>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/es.js"></script>

<script>
$(document).ready(function() {
    // Configurar fecha mínima
    $('input[type="date"]').attr('min', new Date().toISOString().split('T')[0]);
    
    // Función para verificar si un jugador puede ser seleccionado
    function puedeSerSeleccionado(jugadorId, equipo, tipo) {
        const sufijo = tipo === 'finalizado' ? '' : '_sus';
        const selectId = tipo === 'finalizado' ? '#select_jugadores_' : '#select_jugadores_';
        const selectElement = $(selectId + equipo + sufijo + ' option[value="' + jugadorId + '"]');
        
        if (selectElement.length) {
            const tarjetasRojas = selectElement.data('tarjetas-rojas') || 0;
            const jugadorNombre = selectElement.text().split(' (')[0];
            
            if (tarjetasRojas >= 1) {
                alert('⚠️ ' + jugadorNombre + ' no puede ser seleccionado para agregar goles porque tiene una tarjeta roja.');
                return false;
            }
        }
        return true;
    }
    
    // Función para verificar tarjetas antes de agregar (en selects múltiples)
    function verificarTarjetaRojasAlSeleccionar(selectElement) {
        const valoresSeleccionados = selectElement.val() || [];
        const opcionesDeshabilitadas = [];
        
        // Verificar cada opción seleccionada
        valoresSeleccionados.forEach(function(jugadorId) {
            const option = selectElement.find('option[value="' + jugadorId + '"]');
            const tarjetasRojas = option.data('tarjetas-rojas') || 0;
            
            if (tarjetasRojas >= 1) {
                opcionesDeshabilitadas.push({
                    id: jugadorId,
                    nombre: option.text().split(' (')[0]
                });
            }
        });
        
        // Si hay jugadores con tarjeta roja seleccionados
        if (opcionesDeshabilitadas.length > 0) {
            // Mostrar mensaje
            const nombres = opcionesDeshabilitadas.map(j => j.nombre).join(', ');
            alert('⚠️ Los siguientes jugadores no pueden ser seleccionados porque tienen tarjeta roja:\n' + nombres);
            
            // Remover los jugadores con tarjeta roja de la selección
            opcionesDeshabilitadas.forEach(function(jugador) {
                const index = valoresSeleccionados.indexOf(jugador.id);
                if (index !== -1) {
                    valoresSeleccionados.splice(index, 1);
                }
            });
            
            // Actualizar el select
            selectElement.val(valoresSeleccionados).trigger('change');
        }
    }
    
    // Inicializar Select2 para selects de jugadores (FINALIZADO)
    $('.select-jugadores-finalizado').select2({
        placeholder: 'Selecciona un jugador...',
        allowClear: true,
        language: 'es',
        width: '100%',
        templateResult: formatJugador,
        templateSelection: formatJugadorSelection
    });
    
    // Inicializar Select2 para selects de jugadores (SUSPENDIDO)
    $('.select-jugadores-suspendido').select2({
        placeholder: 'Selecciona un jugador...',
        allowClear: true,
        language: 'es',
        width: '100%',
        templateResult: formatJugador,
        templateSelection: formatJugadorSelection
    });
    
    // Inicializar Select2 para tarjetas con validación
    $('.select-tarjetas, .select-tarjetas-sus').select2({
        placeholder: 'Selecciona jugadores...',
        allowClear: true,
        language: 'es',
        width: '100%',
        closeOnSelect: false,
        templateResult: formatJugador,
        templateSelection: formatJugadorSelection
    }).on('select2:select', function(e) {
        // Verificar si el jugador tiene tarjeta roja
        var tarjetasRojas = $(e.params.data.element).data('tarjetas-rojas') || 0;
        
        if (tarjetasRojas >= 1) {
            // Mostrar notificación
            var jugadorNombre = $(e.params.data.element).text().split(' (')[0];
            alert('⚠️ ' + jugadorNombre + ' no puede ser seleccionado porque tiene una tarjeta roja.');
            
            // Deseleccionar el jugador
            var select = $(this);
            var currentValues = select.val();
            var index = currentValues.indexOf(e.params.data.id);
            
            if (index !== -1) {
                currentValues.splice(index, 1);
                select.val(currentValues).trigger('change');
            }
        }
    });
    
    // Agrega esta verificación al evento change de los selects de tarjetas
    $('.select-tarjetas, .select-tarjetas-sus').on('change', function() {
        verificarTarjetaRojasAlSeleccionar($(this));
        // Llamar también a la función de actualizar contador
        if ($(this).hasClass('select-tarjetas')) {
            actualizarContadorTarjetas();
        } else {
            actualizarContadorTarjetasSuspendido();
        }
    });
    
    // ======================================================================
    // FUNCIONES PARA FINALIZADO
    // ======================================================================
    
    // Función para manejar la adición de goles (FINALIZADO)
    $(document).on('click', '.btn-agregar-gol', function() {
        const equipo = $(this).data('equipo');
        const select = $('#select_jugadores_' + equipo);
        const jugadorId = select.val();
        const jugadorNombre = select.find('option:selected').text().split(' (')[0]; // Solo el nombre
        
        if (!jugadorId) {
            alert('⚠️ Por favor, selecciona un jugador primero.');
            return;
        }
        
        // Verificar si el jugador tiene tarjeta roja
        if (!puedeSerSeleccionado(jugadorId, equipo, 'finalizado')) {
            return;
        }
        
        agregarGol(equipo, jugadorId, jugadorNombre, 'finalizado');
        select.val(''); // Resetear el select
        select.trigger('change'); // Actualizar Select2
    });
    
    // Función para manejar la adición de goles (SUSPENDIDO)
    $(document).on('click', '.btn-agregar-gol-sus', function() {
        const equipo = $(this).data('equipo');
        const select = $('#select_jugadores_' + equipo + '_sus');
        const jugadorId = select.val();
        const jugadorNombre = select.find('option:selected').text().split(' (')[0]; // Solo el nombre
        
        if (!jugadorId) {
            alert('⚠️ Por favor, selecciona un jugador primero.');
            return;
        }
        
        // Verificar si el jugador tiene tarjeta roja
        if (!puedeSerSeleccionado(jugadorId, equipo, 'suspendido')) {
            return;
        }
        
        agregarGol(equipo, jugadorId, jugadorNombre, 'suspendido');
        select.val(''); // Resetear el select
        select.trigger('change'); // Actualizar Select2
    });
    
    // Función para agregar un gol a la lista
    function agregarGol(equipo, jugadorId, jugadorNombre, tipo) {
        const timestamp = Date.now(); // ID único para cada gol
        const sufijo = tipo === 'finalizado' ? '' : '-sus';
        const golId = 'gol_' + equipo + '_' + jugadorId + '_' + timestamp;
        
        // Crear elemento visual para el gol
        const golElement = `
            <div id="${golId}" class="gol-item" style="background: #e8f5e9; padding: 8px; margin: 5px 0; border-radius: 5px; border-left: 4px solid #4CAF50; display: flex; justify-content: space-between; align-items: center;">
                <span>
                    ⚽ ${jugadorNombre}
                    <small style="color: #666; font-size: 12px;">(${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})})</small>
                </span>
                <button type="button" class="btn-eliminar-gol" data-gol-id="${golId}" data-equipo="${equipo}" data-tipo="${tipo}" style="background: #ff6b6b; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center;">
                    ×
                </button>
            </div>
        `;
        
        // Agregar a la lista visual
        $('#lista-goles-' + equipo + sufijo).append(golElement);
        
        // Actualizar el input oculto
        actualizarGolesInput(equipo, tipo);
        
        // Actualizar contador
        actualizarContadorGoles(equipo, tipo);
        
        // Actualizar marcador
        actualizarMarcador(tipo);
    }
    
    // Función para eliminar un gol
    $(document).on('click', '.btn-eliminar-gol', function() {
        const golId = $(this).data('gol-id');
        const equipo = $(this).data('equipo');
        const tipo = $(this).data('tipo');
        
        $('#' + golId).remove();
        actualizarGolesInput(equipo, tipo);
        actualizarContadorGoles(equipo, tipo);
        actualizarMarcador(tipo);
    });
    
    // Función para actualizar el input oculto con los datos
    function actualizarGolesInput(equipo, tipo) {
        const sufijo = tipo === 'finalizado' ? '' : '_sus';
        const goles = [];
        $('#lista-goles-' + equipo + sufijo + ' .gol-item').each(function() {
            // Extraer el ID del jugador del ID del elemento
            const golId = $(this).attr('id');
            const jugadorId = golId.split('_')[2]; // Formato: gol_local_IDJUGADOR_TIMESTAMP
            goles.push(jugadorId);
        });
        
        // Actualizar el input oculto
        $('#goles_jugadores_' + equipo + sufijo + '_input').val(JSON.stringify(goles));
    }
    
    // Función para actualizar el contador
    function actualizarContadorGoles(equipo, tipo) {
        const sufijo = tipo === 'finalizado' ? '' : '-sus';
        const count = $('#lista-goles-' + equipo + sufijo + ' .gol-item').length;
        $('#count-goles-' + equipo + (tipo === 'finalizado' ? '' : '-sus')).text(count);
    }
    
    // Función para actualizar el marcador
    function actualizarMarcador(tipo) {
        const sufijo = tipo === 'finalizado' ? '' : '-sus';
        const displaySufijo = tipo === 'finalizado' ? '' : '-sus';
        
        const golesLocal = $('#lista-goles-local' + sufijo + ' .gol-item').length || 0;
        const golesVisitante = $('#lista-goles-visitante' + sufijo + ' .gol-item').length || 0;
        
        $('#goles-local-display' + displaySufijo).text(golesLocal);
        $('#goles-visitante-display' + displaySufijo).text(golesVisitante);
    }
    
    // Actualizar contadores de tarjetas (FINALIZADO)
    function actualizarContadorTarjetas() {
        const amarillasLocal = $('#tarjetas_amarillas_local').val() || [];
        const rojasLocal = $('#tarjetas_rojas_local').val() || [];
        const amarillasVisitante = $('#tarjetas_amarillas_visitante').val() || [];
        const rojasVisitante = $('#tarjetas_rojas_visitante').val() || [];
        
        $('#count-amarillas-local').text(amarillasLocal.length);
        $('#count-rojas-local').text(rojasLocal.length);
        $('#count-amarillas-visitante').text(amarillasVisitante.length);
        $('#count-rojas-visitante').text(rojasVisitante.length);
    }
    
    // Actualizar contadores de tarjetas (SUSPENDIDO)
    function actualizarContadorTarjetasSuspendido() {
        const amarillasLocal = $('#tarjetas_amarillas_local_sus').val() || [];
        const rojasLocal = $('#tarjetas_rojas_local_sus').val() || [];
        const amarillasVisitante = $('#tarjetas_amarillas_visitante_sus').val() || [];
        const rojasVisitante = $('#tarjetas_rojas_visitante_sus').val() || [];
        
        $('#count-amarillas-local-sus').text(amarillasLocal.length);
        $('#count-rojas-local-sus').text(rojasLocal.length);
        $('#count-amarillas-visitante-sus').text(amarillasVisitante.length);
        $('#count-rojas-visitante-sus').text(rojasVisitante.length);
    }
    
    // Validación de formularios
    $('#formFinalizado').on('submit', function(e) {
        const tiempo = parseInt($('#tiempo_jugado').val()) || 0;
        
        // Asegurarnos que los inputs ocultos tengan datos
        actualizarGolesInput('local', 'finalizado');
        actualizarGolesInput('visitante', 'finalizado');
        
        if (tiempo < 0 || tiempo > 120) {
            e.preventDefault();
            alert('❌ El tiempo jugado debe estar entre 0 y 120 minutos.');
            return false;
        }
        
        const golesLocal = $('#lista-goles-local .gol-item').length;
        const golesVisitante = $('#lista-goles-visitante .gol-item').length;
        
        if (tiempo > 0 && golesLocal === 0 && golesVisitante === 0) {
            if (!confirm('⚠️ El marcador está 0-0. ¿Es correcto este resultado?')) {
                e.preventDefault();
                return false;
            }
        }
        
        if (!confirm('⚠️ ¿ESTÁS SEGURO DE FINALIZAR EL PARTIDO?\n\nLos datos se guardarán en estadísticas y clasificación.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
    
    $('#formSuspendido').on('submit', function(e) {
        const descripcion = $('#descripcion_suspension').val().trim();
        const tiempo = parseInt($('#tiempo_jugado').val()) || 0;
        
        // Asegurarnos que los inputs ocultos tengan datos
        actualizarGolesInput('local', 'suspendido');
        actualizarGolesInput('visitante', 'suspendido');
        
        if (!descripcion) {
            e.preventDefault();
            alert('❌ La razón de la suspensión es obligatoria.');
            return false;
        }
        if (tiempo < 0 || tiempo > 120) {
            e.preventDefault();
            alert('❌ El tiempo jugado debe estar entre 0 y 120 minutos.');
            return false;
        }
        if (!confirm('⚠️ ¿CONFIRMAS LA SUSPENSIÓN DEL PARTIDO?\n\nSe guardará el resultado parcial y se reprogramará.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
    
    $('#formPospuesto').on('submit', function(e) {
        const descripcion = $('#descripcion').val().trim();
        if (!descripcion) {
            e.preventDefault();
            alert('❌ La razón del pospuesto es obligatoria.');
            return false;
        }
        if (!confirm('⚠️ ¿ESTÁS SEGURO DE REPROGRAMAR EL PARTIDO?\n\nEl partido quedará marcado como POSPUESTO.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
    
    $('#formCancelado').on('submit', function(e) {
        const descripcion = $('#descripcion_cancelacion').val().trim();
        const checkbox = $('#formCancelado input[type="checkbox"]').is(':checked');
        
        if (!descripcion || !checkbox) {
            e.preventDefault();
            alert('❌ Debe ingresar la razón y confirmar la cancelación.');
            return false;
        }
        if (!confirm('⚠️ ⚠️ ⚠️ ADVERTENCIA FINAL\n\n¿ESTÁS SEGURO DE CANCELAR DEFINITIVAMENTE EL PARTIDO?\n\nEsta acción NO se puede deshacer.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
});
// Formatear jugador en Select2 con íconos de tarjetas
function formatJugador(jugador) {
    if (!jugador.id) return jugador.text;
    
    var $container = $('<span></span>');
    
    // Extraer el texto original
    var originalText = jugador.text;
    var displayText = originalText.split(' (')[0]; // Solo el nombre
    
    // Verificar si es capitán o jugador
    var esCapitan = originalText.includes('(Capitán)');
    var esJugador = originalText.includes('(Jugador)');
    
    // Obtener datos de tarjetas del elemento option
    var optionElement = $(jugador.element);
    var tarjetasAmarillas = optionElement.data('tarjetas-amarillas') || 0;
    var tarjetasRojas = optionElement.data('tarjetas-rojas') || 0;
    
    $container.text(displayText + ' ');
    
    // Agregar UN SOLO ícono de tarjeta amarilla si tiene (aunque tenga varias)
    if (tarjetasAmarillas >= 1) {
        $container.append('<span class="tarjeta-icono amarilla" style="color: #FFD700; margin-left: 3px; font-size: 16px;" title="' + tarjetasAmarillas + ' tarjeta(s) amarilla(s)">🟨</span>');
    }
    
    // Agregar UN SOLO ícono de tarjeta roja si tiene (aunque tenga varias)
    if (tarjetasRojas >= 1) {
        $container.append('<span class="tarjeta-icono roja" style="color: #FF0000; margin-left: 3px; font-size: 16px;" title="' + tarjetasRojas + ' tarjeta(s) roja(s)">🟥</span>');
    }
    
    // Agregar tipo de jugador
    if (esCapitan) {
        $container.append('<span class="jugador-info capitan" style="margin-left: 5px;"> (Capitán)</span>');
    } else if (esJugador) {
        $container.append('<span class="jugador-info jugador" style="margin-left: 5px;"> (Jugador)</span>');
    }
    
    return $container;
}

function formatJugadorSelection(jugador) {
    if (!jugador.id) return jugador.text;
    
    var originalText = jugador.text;
    var displayText = originalText.split(' (')[0]; // Solo el nombre
    
    // Verificar tarjetas
    var optionElement = $(jugador.element);
    var tarjetasAmarillas = optionElement.data('tarjetas-amarillas') || 0;
    var tarjetasRojas = optionElement.data('tarjetas-rojas') || 0;
    
    var result = displayText;
    
    // Agregar UN SOLO ícono en la selección por cada tipo de tarjeta
    if (tarjetasAmarillas >= 1) {
        result += ' 🟨';
    }
    
    if (tarjetasRojas >= 1) {
        result += ' 🟥';
    }
    
    return result;
}
</script>
<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>
