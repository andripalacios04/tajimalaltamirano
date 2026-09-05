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
                // 4. CONSULTAR JUGADORES Y CAPITANES
                // ======================================================================
                $sql_miembros_local = "SELECT 
                    r.Id_Retador,
                    r.Nombre,
                    r.Apellido,
                    CASE 
                        WHEN ej.tipo = 'capitan' THEN 'capitan'
                        ELSE 'jugador'
                    END as tipo_miembro,
                    CONCAT(r.Nombre, ' ', r.Apellido) as nombre_completo
                FROM equipo_jugador ej
                INNER JOIN retador r ON ej.Id_Jugador = r.Id_Retador
                WHERE ej.Id_Equipo = ?
                ORDER BY 
                    CASE WHEN ej.tipo = 'capitan' THEN 1 ELSE 2 END,
                    r.Nombre, r.Apellido";
                
                $stmt_miembros_local = $conn->prepare($sql_miembros_local);
                $stmt_miembros_local->bind_param("s", $equipo_local['id_equipo']);
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
                    CONCAT(r.Nombre, ' ', r.Apellido) as nombre_completo
                FROM equipo_jugador ej
                INNER JOIN retador r ON ej.Id_Jugador = r.Id_Retador
                WHERE ej.Id_Equipo = ?
                ORDER BY 
                    CASE WHEN ej.tipo = 'capitan' THEN 1 ELSE 2 END,
                    r.Nombre, r.Apellido";
                
                $stmt_miembros_visitante = $conn->prepare($sql_miembros_visitante);
                $stmt_miembros_visitante->bind_param("s", $equipo_visitante['id_equipo']);
                $stmt_miembros_visitante->execute();
                $res_miembros_visitante = $stmt_miembros_visitante->get_result();
                
                while ($miembro = $res_miembros_visitante->fetch_assoc()) {
                    $jugadores_visitante[] = $miembro;
                }
                $stmt_miembros_visitante->close();
                
                // ======================================================================
                // 5. PROCESAR SELECCIÓN DE ESTADO
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
                // 6. PROCESAR FORMULARIO DE FINALIZADO (CON LÓGICA DE CANASTAS POR JUGADOR)
                // ======================================================================
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_finalizado'])) {
                    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                        $mensajeError = "❌ Token de seguridad inválido.";
                    } else {
                        // OBTENER CANASTAS POR JUGADOR
                        $canastas_jugador_local = isset($_POST['canastas_jugador_local']) ? $_POST['canastas_jugador_local'] : [];
                        $canastas_jugador_visitante = isset($_POST['canastas_jugador_visitante']) ? $_POST['canastas_jugador_visitante'] : [];
                        
                        // CALCULAR TOTALES DE EQUIPO
                        $canastas_local = 0;
                        $canastas_visitante = 0;
                        
                        // Sumar canastas de cada jugador local
                        foreach ($canastas_jugador_local as $id_jugador => $canastas) {
                            $canastas_int = intval($canastas);
                            if ($canastas_int > 0) {
                                $canastas_local += $canastas_int;
                            }
                        }
                        
                        // Sumar canastas de cada jugador visitante
                        foreach ($canastas_jugador_visitante as $id_jugador => $canastas) {
                            $canastas_int = intval($canastas);
                            if ($canastas_int > 0) {
                                $canastas_visitante += $canastas_int;
                            }
                        }
                        
                        // Resto de datos
                        $faltas_local = isset($_POST['faltas_local']) ? intval($_POST['faltas_local']) : 0;
                        $faltas_visitante = isset($_POST['faltas_visitante']) ? intval($_POST['faltas_visitante']) : 0;
                        
                        // Expulsiones con jugador específico
                        $expulsion_jugador_local = isset($_POST['expulsion_jugador_local']) ? trim($_POST['expulsion_jugador_local']) : '';
                        $expulsion_tipo_local = isset($_POST['expulsion_tipo_local']) ? trim($_POST['expulsion_tipo_local']) : '';
                        $expulsion_local = !empty($expulsion_jugador_local) ? "Jugador: $expulsion_jugador_local - Tipo: $expulsion_tipo_local" : '';
                        
                        $expulsion_jugador_visitante = isset($_POST['expulsion_jugador_visitante']) ? trim($_POST['expulsion_jugador_visitante']) : '';
                        $expulsion_tipo_visitante = isset($_POST['expulsion_tipo_visitante']) ? trim($_POST['expulsion_tipo_visitante']) : '';
                        $expulsion_visitante = !empty($expulsion_jugador_visitante) ? "Jugador: $expulsion_jugador_visitante - Tipo: $expulsion_tipo_visitante" : '';
                        
                        // Datos adicionales
                        $tiempo_jugado = isset($_POST['tiempo_jugado']) ? intval($_POST['tiempo_jugado']) : 40;
                        $descripcion_finalizado = isset($_POST['descripcion_finalizado']) ? trim($_POST['descripcion_finalizado']) : '';
                        
                        // Verificaciones básicas
                        if ($tiempo_jugado < 0 || $tiempo_jugado > 48) {
                            $mensajeError = "❌ El tiempo jugado debe estar entre 0 y 48 minutos para deportes de canasta.";
                        } else {
                            $conn->begin_transaction();
                            
                            try {
                                // ======================================================================
                                // 6.1. CALCULAR PUNTOS Y RESULTADOS
                                // ======================================================================
                                $puntos_local = 0;
                                $puntos_visitante = 0;
                                $ganador_local = false;
                                $ganador_visitante = false;
                                $empate = false;
                                
                                if ($canastas_local > $canastas_visitante) {
                                    $puntos_local = 2;
                                    $puntos_visitante = 0;
                                    $ganador_local = true;
                                } elseif ($canastas_local < $canastas_visitante) {
                                    $puntos_local = 0;
                                    $puntos_visitante = 2;
                                    $ganador_visitante = true;
                                } else {
                                    $puntos_local = 1;
                                    $puntos_visitante = 1;
                                    $empate = true;
                                }
                                
                                // ======================================================================
                                // 6.2. ACTUALIZAR O CREAR CLASIFICACIÓN EN CLASIFICACION_CANASTAS
                                // ======================================================================
                                
                                // Obtener clasificación actual o crear nueva
                                $clasif_local = obtenerClasificacionCanastas($conn, $equipo_local['id_equipo'], $id_liga_admin);
                                $clasif_visitante = obtenerClasificacionCanastas($conn, $equipo_visitante['id_equipo'], $id_liga_admin);
                                
                                // Calcular nuevos valores para LOCAL
                                $partidos_jugados_local_calc = $clasif_local['partidos_jugados'] + 1;
                                $partidos_ganados_local_calc = $clasif_local['partidos_ganados'] + ($ganador_local ? 1 : 0);
                                $partidos_empatados_local_calc = $clasif_local['partidos_empatados'] + ($empate ? 1 : 0);
                                $partidos_perdidos_local_calc = $clasif_local['partidos_perdidos'] + ($ganador_visitante ? 1 : 0);
                                $canastas_favor_local_calc = $clasif_local['canastas_favor'] + $canastas_local;
                                $canastas_contra_local_calc = $clasif_local['canastas_contra'] + $canastas_visitante;
                                $diferencia_canastas_local_calc = $canastas_favor_local_calc - $canastas_contra_local_calc;
                                $puntos_total_local_calc = $clasif_local['puntos'] + $puntos_local;
                                $faltas_total_local_calc = $clasif_local['faltas_cometidas'] + $faltas_local;
                                $expulsion_local_final = !empty($expulsion_local) ? $expulsion_local : null;
                                
                                // Calcular nuevos valores para VISITANTE
                                $partidos_jugados_visitante_calc = $clasif_visitante['partidos_jugados'] + 1;
                                $partidos_ganados_visitante_calc = $clasif_visitante['partidos_ganados'] + ($ganador_visitante ? 1 : 0);
                                $partidos_empatados_visitante_calc = $clasif_visitante['partidos_empatados'] + ($empate ? 1 : 0);
                                $partidos_perdidos_visitante_calc = $clasif_visitante['partidos_perdidos'] + ($ganador_local ? 1 : 0);
                                $canastas_favor_visitante_calc = $clasif_visitante['canastas_favor'] + $canastas_visitante;
                                $canastas_contra_visitante_calc = $clasif_visitante['canastas_contra'] + $canastas_local;
                                $diferencia_canastas_visitante_calc = $canastas_favor_visitante_calc - $canastas_contra_visitante_calc;
                                $puntos_total_visitante_calc = $clasif_visitante['puntos'] + $puntos_visitante;
                                $faltas_total_visitante_calc = $clasif_visitante['faltas_cometidas'] + $faltas_visitante;
                                $expulsion_visitante_final = !empty($expulsion_visitante) ? $expulsion_visitante : null;
                                
                                // Preparar valores NULL
                                $expulsion_local_param = ($expulsion_local_final !== null) ? $expulsion_local_final : '';
                                $expulsion_visitante_param = ($expulsion_visitante_final !== null) ? $expulsion_visitante_final : '';
                                $descripcion_param = !empty($descripcion_finalizado) ? $descripcion_finalizado : '';
                                $jornada_param = !empty($partido_data['Jornada']) ? $partido_data['Jornada'] : '';
                                
                                // Actualizar o insertar clasificación para LOCAL
                                if ($clasif_local['id_clasificacion_canastas']) {
                                    $sql_update_local = "UPDATE clasificacion_canastas 
                                                        SET puntos = ?, 
                                                            partidos_jugados = ?, partidos_ganados = ?, partidos_empatados = ?, partidos_perdidos = ?,
                                                            canastas_favor = ?, canastas_contra = ?, diferencia_canastas = ?, 
                                                            faltas_cometidas = ?, expulsion = ?,
                                                            Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                                        WHERE id_clasificacion_canastas = ?";
                                    
                                    $stmt_update_local = $conn->prepare($sql_update_local);
                                    $stmt_update_local->bind_param(
                                        "iiiiiiiiisssi",
                                        $puntos_total_local_calc,
                                        $partidos_jugados_local_calc,
                                        $partidos_ganados_local_calc,
                                        $partidos_empatados_local_calc,
                                        $partidos_perdidos_local_calc,
                                        $canastas_favor_local_calc,
                                        $canastas_contra_local_calc,
                                        $diferencia_canastas_local_calc,
                                        $faltas_total_local_calc,
                                        $expulsion_local_param,
                                        $jornada_param,
                                        $descripcion_param,
                                        $clasif_local['id_clasificacion_canastas']
                                    );
                                    $stmt_update_local->execute();
                                    $stmt_update_local->close();
                                } else {
                                    $sql_insert_local = "INSERT INTO clasificacion_canastas 
                                                        (id_liga, id_equipo, id_deporte, posicion, puntos, 
                                                         partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                                         canastas_favor, canastas_contra, diferencia_canastas, 
                                                         faltas_cometidas, expulsion, jornada, descripcion)
                                                        VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                    
                                    $stmt_insert_local = $conn->prepare($sql_insert_local);
                                    $stmt_insert_local->bind_param(
                                        "sssiiiiiiiiisss",
                                        $id_liga_admin,
                                        $equipo_local['id_equipo'],
                                        $id_deporte,
                                        $puntos_total_local_calc,
                                        $partidos_jugados_local_calc,
                                        $partidos_ganados_local_calc,
                                        $partidos_empatados_local_calc,
                                        $partidos_perdidos_local_calc,
                                        $canastas_favor_local_calc,
                                        $canastas_contra_local_calc,
                                        $diferencia_canastas_local_calc,
                                        $faltas_total_local_calc,
                                        $expulsion_local_param,
                                        $jornada_param,
                                        $descripcion_param
                                    );
                                    $stmt_insert_local->execute();
                                    $stmt_insert_local->close();
                                }
                                
                                // Actualizar o insertar clasificación para VISITANTE
                                if ($clasif_visitante['id_clasificacion_canastas']) {
                                    $sql_update_visitante = "UPDATE clasificacion_canastas 
                                                            SET puntos = ?, 
                                                                partidos_jugados = ?, partidos_ganados = ?, partidos_empatados = ?, partidos_perdidos = ?,
                                                                canastas_favor = ?, canastas_contra = ?, diferencia_canastas = ?, 
                                                                faltas_cometidas = ?, expulsion = ?,
                                                                Jornada = ?, descripcion = ?, fecha_actualizacion = CURRENT_TIMESTAMP
                                                            WHERE id_clasificacion_canastas = ?";
                                    
                                    $stmt_update_visitante = $conn->prepare($sql_update_visitante);
                                    $stmt_update_visitante->bind_param(
                                        "iiiiiiiiisssi",
                                        $puntos_total_visitante_calc,
                                        $partidos_jugados_visitante_calc,
                                        $partidos_ganados_visitante_calc,
                                        $partidos_empatados_visitante_calc,
                                        $partidos_perdidos_visitante_calc,
                                        $canastas_favor_visitante_calc,
                                        $canastas_contra_visitante_calc,
                                        $diferencia_canastas_visitante_calc,
                                        $faltas_total_visitante_calc,
                                        $expulsion_visitante_param,
                                        $jornada_param,
                                        $descripcion_param,
                                        $clasif_visitante['id_clasificacion_canastas']
                                    );
                                    $stmt_update_visitante->execute();
                                    $stmt_update_visitante->close();
                                } else {
                                    $sql_insert_visitante = "INSERT INTO clasificacion_canastas 
                                                            (id_liga, id_equipo, id_deporte, posicion, puntos, 
                                                             partidos_jugados, partidos_ganados, partidos_empatados, partidos_perdidos,
                                                             canastas_favor, canastas_contra, diferencia_canastas, 
                                                             faltas_cometidas, expulsion, jornada, descripcion)
                                                            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                    
                                    $stmt_insert_visitante = $conn->prepare($sql_insert_visitante);
                                    $stmt_insert_visitante->bind_param(
                                        "sssiiiiiiiiisss",
                                        $id_liga_admin,
                                        $equipo_visitante['id_equipo'],
                                        $id_deporte,
                                        $puntos_total_visitante_calc,
                                        $partidos_jugados_visitante_calc,
                                        $partidos_ganados_visitante_calc,
                                        $partidos_empatados_visitante_calc,
                                        $partidos_perdidos_visitante_calc,
                                        $canastas_favor_visitante_calc,
                                        $canastas_contra_visitante_calc,
                                        $diferencia_canastas_visitante_calc,
                                        $faltas_total_visitante_calc,
                                        $expulsion_visitante_param,
                                        $jornada_param,
                                        $descripcion_param
                                    );
                                    $stmt_insert_visitante->execute();
                                    $stmt_insert_visitante->close();
                                }
                                
                                // ======================================================================
                                // 6.3. ACTUALIZAR ESTADÍSTICAS DE JUGADORES (CANASTAS Y FALTAS)
                                // ======================================================================
                                
                                // Guardar canastas por jugador para EQUIPO LOCAL
                                foreach ($canastas_jugador_local as $id_jugador => $canastas) {
                                    $canastas_int = intval($canastas);
                                    if ($canastas_int > 0) {
                                        try {
                                            guardarEstadisticasJugador($conn, $id_jugador, $equipo_local['id_equipo'], $id_liga_admin, $canastas_int, 0, $id_partido);
                                        } catch (Exception $e) {
                                            throw new Exception("Error al guardar estadísticas de jugador local $id_jugador: " . $e->getMessage());
                                        }
                                    }
                                }
                                
                                // Guardar canastas por jugador para EQUIPO VISITANTE
                                foreach ($canastas_jugador_visitante as $id_jugador => $canastas) {
                                    $canastas_int = intval($canastas);
                                    if ($canastas_int > 0) {
                                        try {
                                            guardarEstadisticasJugador($conn, $id_jugador, $equipo_visitante['id_equipo'], $id_liga_admin, $canastas_int, 0, $id_partido);
                                        } catch (Exception $e) {
                                            throw new Exception("Error al guardar estadísticas de jugador visitante $id_jugador: " . $e->getMessage());
                                        }
                                    }
                                }
                                
                                // Actualizar faltas de jugadores
                                actualizarFaltasJugadores($conn, $jugadores_local, $faltas_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                                actualizarFaltasJugadores($conn, $jugadores_visitante, $faltas_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                                
                                // ======================================================================
                                // 6.4. ACTUALIZAR POSICIONES
                                // ======================================================================
                                actualizarPosicionesClasificacionCanastas($conn, $id_liga_admin);
                      // ======================================================================
// 6.5. ACTUALIZAR CALENDARIO CON RESULTADO
// ======================================================================
// Determinar resultado final (GANADO, PERDIDO, EMPATADO)
if ($canastas_local > $canastas_visitante) {
    $resultado_final_local = 'GANADO';
    $resultado_final_visitante = 'PERDIDO';
} elseif ($canastas_local < $canastas_visitante) {
    $resultado_final_local = 'PERDIDO';
    $resultado_final_visitante = 'GANADO';
} else {
    $resultado_final_local = 'EMPATADO';
    $resultado_final_visitante = 'EMPATADO';
}

// Actualizar calendario con todos los campos necesarios
$sql_update_calendario = "UPDATE Calendario 
                         SET Estado = 'FINALIZADO', 
                             Goles_local = ?, 
                             Goles_visitante = ?, 
                             resultado_final = ?,
                             Tiempo_Jugado = ?,
                             descripcion = ?,
                             fecha_actualizacion = CURRENT_TIMESTAMP
                         WHERE Id_Partido = ?";

$stmt_update_calendario = $conn->prepare($sql_update_calendario);
$resultado_texto = $canastas_local . " - " . $canastas_visitante;
$stmt_update_calendario->bind_param("iissss", 
    $canastas_local, 
    $canastas_visitante, 
    $resultado_texto, 
    $tiempo_jugado, 
    $descripcion_param, 
    $id_partido
);
$stmt_update_calendario->execute();
$stmt_update_calendario->close();
                                
                                // ======================================================================
                                // 6.6. REGISTRAR EXPULSIONES EN ESTADÍSTICAS DEL JUGADOR
                                // ======================================================================
                                if (!empty($expulsion_jugador_local)) {
                                    registrarExpulsionJugador($conn, $expulsion_jugador_local, $expulsion_tipo_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                                }
                                
                                if (!empty($expulsion_jugador_visitante)) {
                                    registrarExpulsionJugador($conn, $expulsion_jugador_visitante, $expulsion_tipo_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                                }
                                
                                $conn->commit();
                                
                                // Eliminar permiso
                                $sql_eliminar = "DELETE FROM adminsolicitud 
                                                WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                                $stmt_eliminar = $conn->prepare($sql_eliminar);
                                $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                                $stmt_eliminar->execute();
                                $stmt_eliminar->close();
                                
                                $mensajeExito = "✅ Partido finalizado correctamente.<br>";
                                $mensajeExito .= "🏀 Resultado: $canastas_local - $canastas_visitante<br>";
                                $mensajeExito .= "📊 Canastas local: " . $canastas_local . " (de " . count($jugadores_local) . " jugadores)<br>";
                                $mensajeExito .= "📊 Canastas visitante: " . $canastas_visitante . " (de " . count($jugadores_visitante) . " jugadores)";
                                
                                echo '<script>
                                    setTimeout(function() {
                                        window.location.href = "AdminCalendario.php";
                                    }, 3000);
                                </script>';
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $mensajeError = "❌ Error: " . $e->getMessage();
                            }
                        }
                    }
                }
                
                // ======================================================================
// 7. PROCESAR FORMULARIO DE SUSPENDIDO
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_suspendido'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $mensajeError = "❌ Token de seguridad inválido.";
    } else {
        // Obtener canastas por jugador para suspendido
        $canastas_jugador_local = isset($_POST['canastas_jugador_local']) ? $_POST['canastas_jugador_local'] : [];
        $canastas_jugador_visitante = isset($_POST['canastas_jugador_visitante']) ? $_POST['canastas_jugador_visitante'] : [];
        
        // Calcular totales
        $canastas_local = 0;
        $canastas_visitante = 0;
        
        foreach ($canastas_jugador_local as $canastas) {
            $canastas_local += intval($canastas);
        }
        
        foreach ($canastas_jugador_visitante as $canastas) {
            $canastas_visitante += intval($canastas);
        }
        
        $faltas_local = isset($_POST['faltas_local']) ? intval($_POST['faltas_local']) : 0;
        $faltas_visitante = isset($_POST['faltas_visitante']) ? intval($_POST['faltas_visitante']) : 0;
        
        // Expulsiones
        $expulsion_jugador_local = isset($_POST['expulsion_jugador_local']) ? trim($_POST['expulsion_jugador_local']) : '';
        $expulsion_tipo_local = isset($_POST['expulsion_tipo_local']) ? trim($_POST['expulsion_tipo_local']) : '';
        $expulsion_local = !empty($expulsion_jugador_local) ? "Jugador: $expulsion_jugador_local - Tipo: $expulsion_tipo_local" : '';
        
        $expulsion_jugador_visitante = isset($_POST['expulsion_jugador_visitante']) ? trim($_POST['expulsion_jugador_visitante']) : '';
        $expulsion_tipo_visitante = isset($_POST['expulsion_tipo_visitante']) ? trim($_POST['expulsion_tipo_visitante']) : '';
        $expulsion_visitante = !empty($expulsion_jugador_visitante) ? "Jugador: $expulsion_jugador_visitante - Tipo: $expulsion_tipo_visitante" : '';
        
        $nueva_fecha = $_POST['nueva_fecha'];
        $nueva_hora = $_POST['nueva_hora'];
        $nueva_direccion = $_POST['nueva_direccion'];
        $descripcion_suspension = trim($_POST['descripcion_suspension']);
        $tiempo_jugado = isset($_POST['tiempo_jugado']) ? intval($_POST['tiempo_jugado']) : 0;
        
        if (empty($descripcion_suspension)) {
            $mensajeError = "❌ La razón de la suspensión es obligatoria.";
        } elseif ($tiempo_jugado < 0 || $tiempo_jugado > 48) {
            $mensajeError = "❌ El tiempo jugado debe estar entre 0 y 48 minutos.";
        } else {
            $conn->begin_transaction();
            
            try {
                // Actualizar calendario con resultado parcial
                $descripcion_completa = "SUSPENDIDO - " . $descripcion_suspension . 
                                        " | Resultado parcial: " . $canastas_local . "-" . $canastas_visitante . 
                                        " | Tiempo jugado: " . $tiempo_jugado . " minutos";
                
                // Crear resultado parcial para suspensión
                $resultado_parcial = $canastas_local . " - " . $canastas_visitante;
                
                $sql_update = "UPDATE Calendario 
                              SET Estado = 'SUSPENDIDO', 
                                  fecha = ?, hora = ?, direccion = ?, 
                                  descripcion = ?, 
                                  Goles_local = ?, 
                                  Goles_visitante = ?, 
                                  resultado_final = ?,
                                  Tiempo_Jugado = ?,
                                  fecha_actualizacion = CURRENT_TIMESTAMP
                              WHERE Id_Partido = ?";
                
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("ssssiisss", 
                    $nueva_fecha, 
                    $nueva_hora, 
                    $nueva_direccion, 
                    $descripcion_completa, 
                    $canastas_local, 
                    $canastas_visitante, 
                    $resultado_parcial,
                    $tiempo_jugado, 
                    $id_partido
                );
                
                                if ($stmt_update->execute()) {
                                    // Guardar estadísticas de jugadores (solo las canastas que tuvieron)
                                    foreach ($canastas_jugador_local as $id_jugador => $canastas) {
                                        $canastas_int = intval($canastas);
                                        if ($canastas_int > 0) {
                                            try {
                                                guardarEstadisticasJugador($conn, $id_jugador, $equipo_local['id_equipo'], $id_liga_admin, $canastas_int, 0, $id_partido);
                                            } catch (Exception $e) {
                                                throw new Exception("Error al guardar estadísticas suspendido: " . $e->getMessage());
                                            }
                                        }
                                    }
                                    
                                    foreach ($canastas_jugador_visitante as $id_jugador => $canastas) {
                                        $canastas_int = intval($canastas);
                                        if ($canastas_int > 0) {
                                            try {
                                                guardarEstadisticasJugador($conn, $id_jugador, $equipo_visitante['id_equipo'], $id_liga_admin, $canastas_int, 0, $id_partido);
                                            } catch (Exception $e) {
                                                throw new Exception("Error al guardar estadísticas suspendido: " . $e->getMessage());
                                            }
                                        }
                                    }
                                    
                                    // Actualizar faltas
                                    actualizarFaltasJugadores($conn, $jugadores_local, $faltas_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                                    actualizarFaltasJugadores($conn, $jugadores_visitante, $faltas_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                                    
                                    // Registrar expulsiones
                                    if (!empty($expulsion_jugador_local)) {
                                        registrarExpulsionJugador($conn, $expulsion_jugador_local, $expulsion_tipo_local, $equipo_local['id_equipo'], $id_liga_admin, $id_partido);
                                    }
                                    
                                    if (!empty($expulsion_jugador_visitante)) {
                                        registrarExpulsionJugador($conn, $expulsion_jugador_visitante, $expulsion_tipo_visitante, $equipo_visitante['id_equipo'], $id_liga_admin, $id_partido);
                                    }
                                    
                                    $conn->commit();
                                    
                                    // Eliminar permiso
                                    $sql_eliminar = "DELETE FROM adminsolicitud 
                                                    WHERE id_retador = ? AND id_admin = ? AND Tipo = 'partido'";
                                    $stmt_eliminar = $conn->prepare($sql_eliminar);
                                    $stmt_eliminar->bind_param("ss", $Id_Retador, $id_partido);
                                    $stmt_eliminar->execute();
                                    $stmt_eliminar->close();
                                    
                                    $mensajeExito = "✅ Partido suspendido y reprogramado. Resultado parcial: $canastas_local - $canastas_visitante";
                                    
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
// 8. PROCESAR FORMULARIO DE POSPUESTO
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
            // Resetear goles y resultado para partido pospuesto
            $sql_update = "UPDATE Calendario 
                          SET Estado = 'POSPUESTO',
                              fecha = ?, hora = ?, direccion = ?, 
                              descripcion = ?,
                              Goles_local = 0,
                              Goles_visitante = 0,
                              resultado_final = NULL,
                              Tiempo_Jugado = NULL,
                              fecha_actualizacion = CURRENT_TIMESTAMP
                          WHERE Id_Partido = ?";
            
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("sssss", 
                $nueva_fecha, 
                $nueva_hora, 
                $nueva_direccion, 
                $descripcion, 
                $id_partido
            );
            
           
              
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
// 9. PROCESAR FORMULARIO DE CANCELADO
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_cancelado'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $mensajeError = "❌ Token de seguridad inválido.";
    } else {
        $descripcion_cancelacion = trim($_POST['descripcion_cancelacion']);
        
        if (empty($descripcion_cancelacion)) {
            $mensajeError = "❌ La razón de la cancelación es obligatoria.";
        } else {
            // Resetear goles y resultado para partido cancelado
            $sql_update = "UPDATE Calendario 
                          SET Estado = 'CANCELADO', 
                              descripcion = ?,
                              Goles_local = 0,
                              Goles_visitante = 0,
                              resultado_final = NULL,
                              Tiempo_Jugado = NULL,
                              fecha_actualizacion = CURRENT_TIMESTAMP
                          WHERE Id_Partido = ?";
            
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ss", 
                $descripcion_cancelacion, 
                $id_partido
            );
            
        
                // ... (resto del código igual)
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
// FUNCIONES AUXILIARES CORREGIDAS
// ======================================================================

// Función para obtener clasificación actual
function obtenerClasificacionCanastas($conn, $id_equipo, $id_liga) {
    $sql_buscar = "SELECT * FROM clasificacion_canastas 
                  WHERE id_equipo = ? AND id_liga = ? 
                  ORDER BY fecha_actualizacion DESC LIMIT 1";
    $stmt_buscar = $conn->prepare($sql_buscar);
    if (!$stmt_buscar) {
        throw new Exception("Error al preparar consulta de clasificación: " . $conn->error);
    }
    
    $stmt_buscar->bind_param("ss", $id_equipo, $id_liga);
    $stmt_buscar->execute();
    $res_buscar = $stmt_buscar->get_result();
    
    if ($res_buscar->num_rows > 0) {
        $clasif = $res_buscar->fetch_assoc();
        $stmt_buscar->close();
        return $clasif;
    } else {
        $stmt_buscar->close();
        return [
            'id_clasificacion_canastas' => null,
            'puntos' => 0,
            'partidos_jugados' => 0,
            'partidos_ganados' => 0,
            'partidos_empatados' => 0,
            'partidos_perdidos' => 0,
            'canastas_favor' => 0,
            'canastas_contra' => 0,
            'diferencia_canastas' => 0,
            'faltas_cometidas' => 0,
            'expulsion' => null
        ];
    }
}

// Función para actualizar posiciones
function actualizarPosicionesClasificacionCanastas($conn, $id_liga) {
    $sql = "SELECT cc.* FROM clasificacion_canastas cc
            INNER JOIN (
                SELECT id_equipo, MAX(fecha_actualizacion) as ultima_fecha
                FROM clasificacion_canastas
                WHERE id_liga = ?
                GROUP BY id_equipo
            ) ultima ON cc.id_equipo = ultima.id_equipo AND cc.fecha_actualizacion = ultima.ultima_fecha
            WHERE cc.id_liga = ?
            ORDER BY cc.puntos DESC, cc.diferencia_canastas DESC, cc.canastas_favor DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $id_liga, $id_liga);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $posicion = 1;
    while ($clasif = $result->fetch_assoc()) {
        $sql_update = "UPDATE clasificacion_canastas 
                      SET posicion = ? 
                      WHERE id_clasificacion_canastas = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ii", $posicion, $clasif['id_clasificacion_canastas']);
        $stmt_update->execute();
        $stmt_update->close();
        $posicion++;
    }
    
    $stmt->close();
}

// Función para actualizar faltas de jugadores
function actualizarFaltasJugadores($conn, $jugadores, $faltas_equipo, $id_equipo, $id_liga, $id_partido) {
    if ($faltas_equipo <= 0 || count($jugadores) == 0) return;
    
    $faltas_por_jugador = floor($faltas_equipo / count($jugadores));
    $faltas_extra = $faltas_equipo % count($jugadores);
    
    $jugador_index = 0;
    foreach ($jugadores as $jugador) {
        $faltas_asignadas = $faltas_por_jugador;
        if ($jugador_index < $faltas_extra) {
            $faltas_asignadas++;
        }
        
        if ($faltas_asignadas > 0) {
            actualizarFaltasJugadorIndividual($conn, $jugador['Id_Retador'], $id_equipo, $id_liga, $faltas_asignadas, $id_partido);
        }
        
        $jugador_index++;
    }
}

// Función para actualizar faltas de jugador individual - CORREGIDA
function actualizarFaltasJugadorIndividual($conn, $id_jugador, $id_equipo, $id_liga, $faltas, $id_partido) {
    // Verificar si ya existe estadística para este jugador específico en esta liga y equipo
    $sql_check = "SELECT id_estadistica, faltas_cometidas, descripciones, Actualizacion_anterior 
                 FROM estadisticasretador 
                 WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?";
    
    $stmt_check = $conn->prepare($sql_check);
    if (!$stmt_check) {
        throw new Exception("Error al preparar consulta faltas: " . $conn->error);
    }
    
    $stmt_check->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check->num_rows > 0) {
        // Si existe, ACTUALIZAR solo ese registro específico
        $estadistica = $res_check->fetch_assoc();
        $nuevas_faltas = $estadistica['faltas_cometidas'] + $faltas;
        
        // Obtener valores existentes
        $descripciones_existente = $estadistica['descripciones'] ?: '';
        $actualizacion_existente = $estadistica['Actualizacion_anterior'] ?: '';
        
        // Preparar nueva descripción
        $nueva_descripcion = "Faltas: +$faltas en partido $id_partido";
        
        // Concatenar con descripción existente
        $descripciones_final = $descripciones_existente;
        if ($descripciones_existente) {
            $descripciones_final .= " | " . $nueva_descripcion;
        } else {
            $descripciones_final = $nueva_descripcion;
        }
        
        // Actualizar solo el registro específico
        $sql_update = "UPDATE estadisticasretador 
                      SET faltas_cometidas = ?, 
                          descripciones = ?,
                          fecha_actualizacion = CURDATE(), 
                          hora_actualizacion = CURTIME(),
                          Actualizacion_anterior = CONCAT(?, ' | Faltas partido ', ?)
                      WHERE id_estadistica = ?";
        
        $stmt_update = $conn->prepare($sql_update);
        if (!$stmt_update) {
            throw new Exception("Error al preparar actualización faltas: " . $conn->error);
        }
        
        // 5 parámetros: i, s, s, s, s
        $stmt_update->bind_param(
            "issss",
            $nuevas_faltas,
            $descripciones_final,
            $actualizacion_existente,
            $id_partido,
            $estadistica['id_estadistica']
        );
        
        if (!$stmt_update->execute()) {
            throw new Exception("Error al actualizar faltas: " . $stmt_update->error);
        }
        
        $stmt_update->close();
    } else {
        // Si no existe, INSERTAR nuevo registro específico
        $id_estadistica = 'EST_' . $id_jugador . '_' . $id_equipo . '_' . $id_liga . '_' . time();
        
        $sql_insert = "INSERT INTO estadisticasretador 
                      (id_estadistica, id_retador, id_equipo, id_liga, faltas_cometidas,
                       fecha_actualizacion, hora_actualizacion, descripciones, Actualizacion_anterior)
                      VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?)";
        
        $descripcion = "Estadísticas de faltas para jugador";
        $actualizacion = "Registro inicial: $faltas falta(s) en partido $id_partido";
        
        $stmt_insert = $conn->prepare($sql_insert);
        if (!$stmt_insert) {
            throw new Exception("Error al preparar inserción faltas: " . $conn->error);
        }
        
        // 8 parámetros: s, s, s, s, i, s, s
        $stmt_insert->bind_param(
            "ssssisss",
            $id_estadistica,
            $id_jugador,
            $id_equipo,
            $id_liga,
            $faltas,
            $descripcion,
            $actualizacion
        );
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Error al insertar faltas: " . $stmt_insert->error);
        }
        
        $stmt_insert->close();
    }
    
    $stmt_check->close();
}

// Función para registrar expulsiones de jugador - CORREGIDA
function registrarExpulsionJugador($conn, $id_jugador, $tipo_expulsion, $id_equipo, $id_liga, $id_partido) {
    // Primero obtener estadística existente solo para este jugador en esta liga y equipo
    $sql_check = "SELECT id_estadistica, tarjetas_rojas, descripciones, Actualizacion_anterior 
                 FROM estadisticasretador 
                 WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?";
    
    $stmt_check = $conn->prepare($sql_check);
    if (!$stmt_check) {
        throw new Exception("Error al preparar consulta expulsión: " . $conn->error);
    }
    
    $stmt_check->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check->num_rows > 0) {
        // Si existe, ACTUALIZAR solo ese registro específico
        $estadistica = $res_check->fetch_assoc();
        $nuevas_rojas = $estadistica['tarjetas_rojas'] + 1;
        
        // Obtener valores existentes
        $descripciones_existente = $estadistica['descripciones'] ?: '';
        $actualizacion_existente = $estadistica['Actualizacion_anterior'] ?: '';
        
        // Preparar nueva descripción
        $nueva_descripcion = "Expulsión: $tipo_expulsion en partido $id_partido";
        
        // Concatenar con descripción existente
        $descripciones_final = $descripciones_existente;
        if ($descripciones_existente) {
            $descripciones_final .= " | " . $nueva_descripcion;
        } else {
            $descripciones_final = $nueva_descripcion;
        }
        
        // Actualizar solo el registro específico
        $sql_update = "UPDATE estadisticasretador 
                      SET tarjetas_rojas = ?, 
                          descripciones = ?,
                          fecha_actualizacion = CURDATE(), 
                          hora_actualizacion = CURTIME(),
                          Actualizacion_anterior = CONCAT(?, ' | Expulsión partido ', ?)
                      WHERE id_estadistica = ?";
        
        $stmt_update = $conn->prepare($sql_update);
        if (!$stmt_update) {
            throw new Exception("Error al preparar actualización expulsión: " . $conn->error);
        }
        
        // 5 parámetros: i, s, s, s, s
        $stmt_update->bind_param(
            "issss",
            $nuevas_rojas,
            $descripciones_final,
            $actualizacion_existente,
            $id_partido,
            $estadistica['id_estadistica']
        );
        
        if (!$stmt_update->execute()) {
            throw new Exception("Error al actualizar expulsión: " . $stmt_update->error);
        }
        
        $stmt_update->close();
    } else {
        // Si no existe, INSERTAR nuevo registro específico
        $id_estadistica = 'EST_' . $id_jugador . '_' . $id_equipo . '_' . $id_liga . '_' . time();
        
        $sql_insert = "INSERT INTO estadisticasretador 
                      (id_estadistica, id_retador, id_equipo, id_liga, tarjetas_rojas,
                       fecha_actualizacion, hora_actualizacion, descripciones, Actualizacion_anterior)
                      VALUES (?, ?, ?, ?, 1, CURDATE(), CURTIME(), ?, ?)";
        
        $descripcion = "Estadísticas de expulsiones para jugador";
        $actualizacion = "Registro inicial: Expulsión $tipo_expulsion en partido $id_partido";
        
        $stmt_insert = $conn->prepare($sql_insert);
        if (!$stmt_insert) {
            throw new Exception("Error al preparar inserción expulsión: " . $conn->error);
        }
        
        // 7 parámetros: s, s, s, s, s, s
        $stmt_insert->bind_param(
            "ssssss",
            $id_estadistica,
            $id_jugador,
            $id_equipo,
            $id_liga,
            $descripcion,
            $actualizacion
        );
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Error al insertar expulsión: " . $stmt_insert->error);
        }
        
        $stmt_insert->close();
    }
    
    $stmt_check->close();
}

// Función para guardar estadísticas de jugador (CANASTAS) - COMPLETAMENTE CORREGIDA
function guardarEstadisticasJugador($conn, $id_jugador, $id_equipo, $id_liga, $canastas, $faltas, $id_partido) {
    // Verificar si ya existe una estadística para ESTE JUGADOR específico en ESTA LIGA y ESTE EQUIPO
    $sql_check = "SELECT id_estadistica, goles, faltas_cometidas, descripciones, Actualizacion_anterior 
                 FROM estadisticasretador 
                 WHERE id_retador = ? AND id_liga = ? AND id_equipo = ?";
    
    $stmt_check = $conn->prepare($sql_check);
    if (!$stmt_check) {
        throw new Exception("Error al preparar consulta: " . $conn->error);
    }
    
    $stmt_check->bind_param("sss", $id_jugador, $id_liga, $id_equipo);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check->num_rows > 0) {
        // Si existe, ACTUALIZAR solo ese registro específico
        $estadistica = $res_check->fetch_assoc();
        $nuevas_canastas = $estadistica['goles'] + $canastas;
        $nuevas_faltas = $estadistica['faltas_cometidas'] + $faltas;
        
        // Obtener valores existentes para no perder datos
        $descripciones_existente = $estadistica['descripciones'] ?: '';
        $actualizacion_existente = $estadistica['Actualizacion_anterior'] ?: '';
        
        // Preparar nueva descripción
        $nueva_descripcion = "Canastas: +$canastas en partido $id_partido";
        if ($faltas > 0) {
            $nueva_descripcion .= " | Faltas: +$faltas";
        }
        
        // Concatenar con la descripción existente
        $descripciones_final = $descripciones_existente;
        if ($descripciones_existente) {
            $descripciones_final .= " | " . $nueva_descripcion;
        } else {
            $descripciones_final = $nueva_descripcion;
        }
        
        // Actualizar solo el registro específico del jugador
        $sql_update = "UPDATE estadisticasretador 
                      SET goles = ?, 
                          faltas_cometidas = ?,
                          descripciones = ?,
                          fecha_actualizacion = CURDATE(), 
                          hora_actualizacion = CURTIME(),
                          Actualizacion_anterior = CONCAT(?, ' | Actualizado partido ', ?)
                      WHERE id_estadistica = ?";
        
        $stmt_update = $conn->prepare($sql_update);
        if (!$stmt_update) {
            throw new Exception("Error al preparar actualización: " . $conn->error);
        }
        
        // 6 parámetros: i, i, s, s, s, s
        $stmt_update->bind_param(
            "iissss",
            $nuevas_canastas,
            $nuevas_faltas,
            $descripciones_final,
            $actualizacion_existente,
            $id_partido,
            $estadistica['id_estadistica']
        );
        
        if (!$stmt_update->execute()) {
            throw new Exception("Error al actualizar estadísticas: " . $stmt_update->error);
        }
        
        $stmt_update->close();
    } else {
        // Si no existe, INSERTAR un nuevo registro solo para este jugador específico
        $id_estadistica = 'EST_' . $id_jugador . '_' . $id_equipo . '_' . $id_liga . '_' . time();
        
        // Crear descripción y actualización
        $descripcion = "Estadísticas para jugador en equipo $id_equipo, liga $id_liga";
        $actualizacion = "Registro inicial: $canastas canasta(s) en partido $id_partido";
        if ($faltas > 0) {
            $actualizacion .= " | $faltas falta(s)";
        }
        
        $sql_insert = "INSERT INTO estadisticasretador 
                      (id_estadistica, id_retador, id_equipo, id_liga, goles, faltas_cometidas,
                       fecha_actualizacion, hora_actualizacion, descripciones, Actualizacion_anterior)
                      VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?)";
        
        $stmt_insert = $conn->prepare($sql_insert);
        if (!$stmt_insert) {
            throw new Exception("Error al preparar inserción: " . $conn->error);
        }
        
        // 8 parámetros: s, s, s, s, i, i, s, s
        $stmt_insert->bind_param(
            "ssssiiss",
            $id_estadistica,
            $id_jugador,
            $id_equipo,
            $id_liga,
            $canastas,
            $faltas,
            $descripcion,
            $actualizacion
        );
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Error al insertar estadísticas: " . $stmt_insert->error);
        }
        
        $stmt_insert->close();
    }
    
    $stmt_check->close();
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
<title>Registrar Resultados de Canastas - RETAME</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}
.container {
    max-width: 1200px;
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
    gap: 30px;
    margin-bottom: 30px;
}
.equipo-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-top: 4px solid;
}
.equipo-card.local {
    border-color: #667eea;
}
.equipo-card.visitante {
    border-color: #ff6b6b;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    color: #333;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
}
.form-group .select2-container {
    width: 100% !important;
}
.marcador-canastas {
    text-align: center;
    font-size: 24px;
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
    padding: 15px 30px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    transition: background 0.3s;
}
.btn-guardar:hover {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
}
.btn-regresar {
    display: inline-block;
    background: #9c27b0;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    margin-bottom: 20px;
    font-weight: bold;
}
.opciones-estado {
    background: white;
    border-radius: 10px;
    padding: 30px;
    margin: 30px 0;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.opcion-estado {
    display: flex;
    align-items: center;
    padding: 15px;
    margin: 10px 0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid #ddd;
}
.opcion-estado:hover {
    transform: translateY(-2px);
}
.opcion-estado input[type="radio"] {
    margin-right: 15px;
}
.opcion-estado label {
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}
.btn-continuar {
    background: linear-gradient(135deg, #2196F3, #0d47a1);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    margin-top: 20px;
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
.select2-results__option {
    padding: 8px 12px;
}
.jugadores-canastas {
    max-height: 300px;
    overflow-y: auto;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    margin-bottom: 15px;
    background: #f9f9f9;
}
.jugador-canasta {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px dashed #ddd;
}
.jugador-canasta:last-child {
    border-bottom: none;
}
.jugador-canasta label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}
.jugador-canasta input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.total-canastas {
    font-weight: bold;
    font-size: 18px;
    color: #2e7d32;
    background: #e8f5e9;
    border: 2px solid #4CAF50;
    text-align: center;
    padding: 10px;
    border-radius: 5px;
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Registrar Resultados de Canastas - RETAME'); } ?>
<div class="container">
    <a href="AdminCalendario.php" class="btn-regresar">
        ↩️ Regresar a Calendario
    </a>
    
    <div class="header">
        <h1>🏀 Registrar Resultados de Canastas</h1>
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
        
        <!-- OPCIONES DE ESTADO -->
        <?php if ($mostrar_opciones && !$mostrar_formulario): ?>
        <div class="opciones-estado">
            <h3>📋 Seleccione el estado del partido:</h3>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="opcion-estado" style="border-color: #4CAF50;">
                    <input type="radio" id="estado_finalizado" name="estado_partido" value="FINALIZADO" required>
                    <label for="estado_finalizado">🏁 FINALIZADO</label>
                </div>
                
                <div class="opcion-estado" style="border-color: #FF9800;">
                    <input type="radio" id="estado_pospuesto" name="estado_partido" value="POSPUESTO">
                    <label for="estado_pospuesto">📅 POSPUESTO</label>
                </div>
                
                <div class="opcion-estado" style="border-color: #2196F3;">
                    <input type="radio" id="estado_suspendido" name="estado_partido" value="SUSPENDIDO">
                    <label for="estado_suspendido">⚠️ SUSPENDIDO</label>
                </div>
                
                <div class="opcion-estado" style="border-color: #F44336;">
                    <input type="radio" id="estado_cancelado" name="estado_partido" value="CANCELADO">
                    <label for="estado_cancelado">❌ CANCELADO</label>
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
            <h3>🏁 Registrar Partido Finalizado (Canastas por Jugador)</h3>
            
            <form method="POST" action="" id="formFinalizado">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="equipos-container">
                    <!-- EQUIPO LOCAL -->
                    <div class="equipo-card local">
                        <h3><?php echo htmlspecialchars($equipo_local['nombre']); ?></h3>
                        
                        <h4>🏀 Canastas por Jugador</h4>
                        <div class="jugadores-canastas" id="jugadores-local">
                            <?php foreach ($jugadores_local as $jugador): ?>
                            <div class="form-group jugador-canasta">
                                <label>
                                    <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                    <small style="color: <?php echo $jugador['tipo_miembro'] == 'capitan' ? '#ff6b00' : '#2196F3'; ?>">
                                        (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                    </small>
                                </label>
                                <input type="number" 
                                       name="canastas_jugador_local[<?php echo $jugador['Id_Retador']; ?>]" 
                                       class="canasta-jugador-local" 
                                       min="0" max="100" value="0" placeholder="Canastas">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Total Canastas Equipo:</strong></label>
                            <input type="text" id="total-canastas-local" value="0" readonly class="total-canastas">
                        </div>
                        
                        <div class="form-group">
                            <label for="faltas_local">⚠️ Faltas cometidas:</label>
                            <input type="number" id="faltas_local" name="faltas_local" 
                                   min="0" max="50" value="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expulsion_jugador_local">🟥 Jugador expulsado (opcional):</label>
                            <select id="expulsion_jugador_local" name="expulsion_jugador_local" class="select-jugador-expulsion">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_local as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>">
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="expulsion_tipo_local">📝 Tipo de expulsión:</label>
                            <select id="expulsion_tipo_local" name="expulsion_tipo_local">
                                <option value="">-- Seleccionar tipo --</option>
                                <option value="TECNICA">Expulsión técnica</option>
                                <option value="DIRECTA">Expulsión directa</option>
                                <option value="DESCALIFICACION">Descalificación</option>
                                <option value="OTRA">Otra razón</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- EQUIPO VISITANTE -->
                    <div class="equipo-card visitante">
                        <h3><?php echo htmlspecialchars($equipo_visitante['nombre']); ?></h3>
                        
                        <h4>🏀 Canastas por Jugador</h4>
                        <div class="jugadores-canastas" id="jugadores-visitante">
                            <?php foreach ($jugadores_visitante as $jugador): ?>
                            <div class="form-group jugador-canasta">
                                <label>
                                    <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                    <small style="color: <?php echo $jugador['tipo_miembro'] == 'capitan' ? '#ff6b00' : '#2196F3'; ?>">
                                        (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                    </small>
                                </label>
                                <input type="number" 
                                       name="canastas_jugador_visitante[<?php echo $jugador['Id_Retador']; ?>]" 
                                       class="canasta-jugador-visitante" 
                                       min="0" max="100" value="0" placeholder="Canastas">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Total Canastas Equipo:</strong></label>
                            <input type="text" id="total-canastas-visitante" value="0" readonly class="total-canastas">
                        </div>
                        
                        <div class="form-group">
                            <label for="faltas_visitante">⚠️ Faltas cometidas:</label>
                            <input type="number" id="faltas_visitante" name="faltas_visitante" 
                                   min="0" max="50" value="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expulsion_jugador_visitante">🟥 Jugador expulsado (opcional):</label>
                            <select id="expulsion_jugador_visitante" name="expulsion_jugador_visitante" class="select-jugador-expulsion">
                                <option value="">-- Seleccionar jugador --</option>
                                <?php foreach ($jugadores_visitante as $jugador): ?>
                                    <option value="<?php echo $jugador['Id_Retador']; ?>">
                                        <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                        <span class="jugador-info <?php echo $jugador['tipo_miembro']; ?>">
                                            (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                        </span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="expulsion_tipo_visitante">📝 Tipo de expulsión:</label>
                            <select id="expulsion_tipo_visitante" name="expulsion_tipo_visitante">
                                <option value="">-- Seleccionar tipo --</option>
                                <option value="TECNICA">Expulsión técnica</option>
                                <option value="DIRECTA">Expulsión directa</option>
                                <option value="DESCALIFICACION">Descalificación</option>
                                <option value="OTRA">Otra razón</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="marcador-canastas">
                    🏀 MARCADOR: 
                    <span id="canastas-local-display">0</span> - 
                    <span id="canastas-visitante-display">0</span>
                </div>
                
                <div class="form-group">
                    <label for="tiempo_jugado">⏱️ Tiempo Jugado (minutos):</label>
                    <input type="number" id="tiempo_jugado" name="tiempo_jugado" 
                           min="0" max="48" value="40" required>
                </div>
                
                <div class="form-group">
                    <label for="descripcion_finalizado">📝 Descripción adicional:</label>
                    <textarea id="descripcion_finalizado" name="descripcion_finalizado" 
                              placeholder="Observaciones sobre el partido..."></textarea>
                </div>
                
                <button type="submit" name="guardar_finalizado" class="btn-guardar">
                    💾 FINALIZAR PARTIDO Y GUARDAR RESULTADOS
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- FORMULARIO PARA SUSPENDIDO -->
        <?php if ($mostrar_formulario && $estado_seleccionado == 'SUSPENDIDO'): ?>
        <div class="opciones-estado">
            <h3>⚠️ Partido Suspendido (Canastas)</h3>
            
            <form method="POST" action="" id="formSuspendido">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="descripcion_suspension">📝 ¿Por qué se suspendió el partido? (obligatorio)</label>
                    <textarea id="descripcion_suspension" name="descripcion_suspension" required
                              placeholder="Describa el motivo de la suspensión..."></textarea>
                </div>
                
                <div class="equipos-container">
                    <div class="equipo-card local">
                        <h3><?php echo htmlspecialchars($equipo_local['nombre']); ?></h3>
                        
                        <h4>🏀 Canastas por Jugador (parcial)</h4>
                        <div class="jugadores-canastas" id="jugadores-local-suspendido">
                            <?php foreach ($jugadores_local as $jugador): ?>
                            <div class="form-group jugador-canasta">
                                <label>
                                    <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                    <small style="color: <?php echo $jugador['tipo_miembro'] == 'capitan' ? '#ff6b00' : '#2196F3'; ?>">
                                        (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                    </small>
                                </label>
                                <input type="number" 
                                       name="canastas_jugador_local[<?php echo $jugador['Id_Retador']; ?>]" 
                                       class="canasta-jugador-local" 
                                       min="0" max="100" value="0" placeholder="Canastas">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Total Canastas Equipo:</strong></label>
                            <input type="text" id="total-canastas-local-suspendido" value="0" readonly class="total-canastas">
                        </div>
                        
                        <div class="form-group">
                            <label for="faltas_local">⚠️ Faltas cometidas:</label>
                            <input type="number" id="faltas_local" name="faltas_local" 
                                   min="0" max="50" value="0" required>
                        </div>
                    </div>
                    
                    <div class="equipo-card visitante">
                        <h3><?php echo htmlspecialchars($equipo_visitante['nombre']); ?></h3>
                        
                        <h4>🏀 Canastas por Jugador (parcial)</h4>
                        <div class="jugadores-canastas" id="jugadores-visitante-suspendido">
                            <?php foreach ($jugadores_visitante as $jugador): ?>
                            <div class="form-group jugador-canasta">
                                <label>
                                    <?php echo htmlspecialchars($jugador['nombre_completo']); ?>
                                    <small style="color: <?php echo $jugador['tipo_miembro'] == 'capitan' ? '#ff6b00' : '#2196F3'; ?>">
                                        (<?php echo $jugador['tipo_miembro'] == 'capitan' ? 'Capitán' : 'Jugador'; ?>)
                                    </small>
                                </label>
                                <input type="number" 
                                       name="canastas_jugador_visitante[<?php echo $jugador['Id_Retador']; ?>]" 
                                       class="canasta-jugador-visitante" 
                                       min="0" max="100" value="0" placeholder="Canastas">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Total Canastas Equipo:</strong></label>
                            <input type="text" id="total-canastas-visitante-suspendido" value="0" readonly class="total-canastas">
                        </div>
                        
                        <div class="form-group">
                            <label for="faltas_visitante">⚠️ Faltas cometidas:</label>
                            <input type="number" id="faltas_visitante" name="faltas_visitante" 
                                   min="0" max="50" value="0" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="tiempo_jugado">⏱️ Tiempo Jugado hasta la suspensión (minutos):</label>
                    <input type="number" id="tiempo_jugado" name="tiempo_jugado" 
                           min="0" max="48" value="0" required>
                </div>
                
                <h4>📅 Datos de Reprogramación:</h4>
                <div class="form-group">
                    <label for="nueva_fecha">Nueva Fecha:</label>
                    <input type="date" id="nueva_fecha" name="nueva_fecha" 
                           value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
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
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="nueva_fecha">📅 Nueva Fecha:</label>
                    <input type="date" id="nueva_fecha" name="nueva_fecha" 
                           value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
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
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="descripcion_cancelacion">📝 Razón de la cancelación (obligatorio):</label>
                    <textarea id="descripcion_cancelacion" name="descripcion_cancelacion" required
                              placeholder="Explique por qué se cancela el partido..."></textarea>
                </div>
                
                <button type="submit" name="guardar_cancelado" class="btn-guardar" style="background: linear-gradient(135deg, #F44336, #c62828);">
                    ❌ CONFIRMAR CANCELACIÓN
                </button>
            </form>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="text-align: center; padding: 50px;">
            <h3>🔒 Acceso restringido o datos no encontrados</h3>
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
    // Inicializar Select2
    $('.select-jugador-expulsion').select2({
        placeholder: 'Seleccionar jugador...',
        allowClear: true,
        language: 'es'
    });
    
    // Función para calcular totales
    function calcularTotales() {
        // Calcular total para equipo local
        let totalLocal = 0;
        $('.canasta-jugador-local').each(function() {
            totalLocal += parseInt($(this).val()) || 0;
        });
        $('#total-canastas-local').val(totalLocal);
        $('#canastas-local-display').text(totalLocal);
        
        // Calcular total para equipo visitante
        let totalVisitante = 0;
        $('.canasta-jugador-visitante').each(function() {
            totalVisitante += parseInt($(this).val()) || 0;
        });
        $('#total-canastas-visitante').val(totalVisitante);
        $('#canastas-visitante-display').text(totalVisitante);
        
        // Para suspendido
        if ($('#total-canastas-local-suspendido').length) {
            let totalLocalSus = 0;
            $('.canasta-jugador-local').each(function() {
                totalLocalSus += parseInt($(this).val()) || 0;
            });
            $('#total-canastas-local-suspendido').val(totalLocalSus);
        }
        
        if ($('#total-canastas-visitante-suspendido').length) {
            let totalVisitanteSus = 0;
            $('.canasta-jugador-visitante').each(function() {
                totalVisitanteSus += parseInt($(this).val()) || 0;
            });
            $('#total-canastas-visitante-suspendido').val(totalVisitanteSus);
        }
    }
    
    // Calcular totales cuando cambie cualquier input de canastas
    $(document).on('input', '.canasta-jugador-local, .canasta-jugador-visitante', calcularTotales);
    
    // Inicializar cálculo
    calcularTotales();
    
    // Configurar fecha mínima
    $('input[type="date"]').attr('min', new Date().toISOString().split('T')[0]);
    
    // Validación de formulario finalizado
    $('#formFinalizado').on('submit', function(e) {
        const tiempo = parseInt($('#tiempo_jugado').val()) || 0;
        const expulsionJugadorLocal = $('#expulsion_jugador_local').val();
        const expulsionTipoLocal = $('#expulsion_tipo_local').val();
        const expulsionJugadorVisitante = $('#expulsion_jugador_visitante').val();
        const expulsionTipoVisitante = $('#expulsion_tipo_visitante').val();
        
        // Validar tiempo
        if (tiempo < 0 || tiempo > 48) {
            e.preventDefault();
            alert('❌ El tiempo jugado debe estar entre 0 y 48 minutos.');
            return false;
        }
        
        // Validar expulsiones
        if (expulsionJugadorLocal && !expulsionTipoLocal) {
            e.preventDefault();
            alert('❌ Si seleccionas un jugador expulsado del equipo local, debes especificar el tipo de expulsión.');
            return false;
        }
        
        if (expulsionJugadorVisitante && !expulsionTipoVisitante) {
            e.preventDefault();
            alert('❌ Si seleccionas un jugador expulsado del equipo visitante, debes especificar el tipo de expulsión.');
            return false;
        }
        
        // Confirmación final
        if (!confirm('⚠️ ¿ESTÁS SEGURO DE FINALIZAR EL PARTIDO?\n\nLos datos se guardarán en estadísticas y clasificación.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
    
    // Validación de formulario suspendido
    $('#formSuspendido').on('submit', function(e) {
        const descripcion = $('#descripcion_suspension').val().trim();
        const tiempo = parseInt($('#tiempo_jugado').val()) || 0;
        
        if (!descripcion) {
            e.preventDefault();
            alert('❌ La razón de la suspensión es obligatoria.');
            return false;
        }
        if (tiempo < 0 || tiempo > 48) {
            e.preventDefault();
            alert('❌ El tiempo jugado debe estar entre 0 y 48 minutos.');
            return false;
        }
        if (!confirm('⚠️ ¿CONFIRMAS LA SUSPENSIÓN DEL PARTIDO?\n\nSe guardará el resultado parcial y se reprogramará.')) {
            e.preventDefault();
            return false;
        }
        return true;
    });
});
</script>
<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>