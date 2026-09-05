<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];

$Nombre = 'Usuario';
if (isset($usuarios['Nombre']) && trim((string)$usuarios['Nombre']) !== '') {
    $Nombre = htmlspecialchars((string)$usuarios['Nombre'], ENT_QUOTES, 'UTF-8');
} elseif (isset($usuarios['nombre']) && trim((string)$usuarios['nombre']) !== '') {
    $Nombre = htmlspecialchars((string)$usuarios['nombre'], ENT_QUOTES, 'UTF-8');
}

$Id_Retador = '';
if (isset($usuarios['Id_Retador'])) {
    $Id_Retador = $usuarios['Id_Retador'];
} elseif (isset($usuarios['id_retador'])) {
    $Id_Retador = $usuarios['id_retador'];
}

$modoPerfilActual = '';
$modoOscuroActivo = false;

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

if (!empty($Id_Retador)) {
    $sqlModoSelect = "SELECT ModoPerfil FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmtModoSelect = $conn->prepare($sqlModoSelect);

    if ($stmtModoSelect) {
        $stmtModoSelect->bind_param("s", $Id_Retador);
        $stmtModoSelect->execute();
        $resModoSelect = $stmtModoSelect->get_result();

        if ($filaModo = $resModoSelect->fetch_assoc()) {
            $modoPerfilActual = isset($filaModo['ModoPerfil']) ? trim((string)$filaModo['ModoPerfil']) : '';
            $_SESSION['usuario_data']['ModoPerfil'] = $modoPerfilActual;
        }

        $stmtModoSelect->close();
    }
}

$modoPerfilNormalizado = mb_strtolower(trim((string)$modoPerfilActual), 'UTF-8');
$modoOscuroActivo = ($modoPerfilNormalizado === 'modo oscuro' || $modoPerfilNormalizado === 'moso oscuro');


$equipos_usuario = [];

if (!empty($Id_Retador)) {
    $sql_equipos = "SELECT e.Nombre 
                    FROM equipo e 
                    INNER JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo 
                    WHERE ej.Id_Jugador = ?";
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
    if (!$stmt) return false;
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

$deportes = [];
$res = $conn->query("SELECT Id_Deporte, Nombre FROM deporte ORDER BY Nombre ASC");
if ($res) {
    while($fila = $res->fetch_assoc()) {
        $deportes[] = $fila;
    }
}

function generarIdEquipo($nombre, $conn){
    $base = preg_replace("/[^a-zA-Z0-9]/", "", $nombre);
    if($base == "") $base = "EQ";
    do {
        $rand = rand(100,999);
        $id_equipo = $base . $rand;
        $check = $conn->query("SELECT Id_Equipo FROM equipo WHERE Id_Equipo='$id_equipo'");
    } while($check && $check->num_rows > 0);
    return $id_equipo;
}

function generarIdEquipoJugador($id_equipo, $id_jugador, $conn){
    do {
        $id_ej = $id_jugador . $id_equipo . rand(10,99);
        $check = $conn->query("SELECT Id_EquipoJugador FROM equipo_jugador WHERE Id_EquipoJugador='$id_ej'");
    } while($check && $check->num_rows > 0);
    return $id_ej;
}

$Nombre = $Nombre ?? 'Usuario';
$nuevas_count = $nuevas_count ?? 0;
$nuevas_notificaciones = $nuevas_notificaciones ?? [];

$Codigo_Postal_Usuario = '';
$canchas_cercanas = [];
$mensaje_canchas = '';

function limpiarTexto($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerValorSesion($datos, $llaves) {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return '';
}

function nombreColumnaSeguro($columna) {
    return '`' . str_replace('`', '``', $columna) . '`';
}

function resolverTablaCanchas($conn) {
    $opciones = ['c_canchas', 'C_Canchas', 'Canchas', 'canchas'];
    foreach ($opciones as $tabla) {
        $tablaSafe = $conn->real_escape_string($tabla);
        $res = $conn->query("SHOW TABLES LIKE '$tablaSafe'");
        if ($res && $res->num_rows > 0) {
            return $tabla;
        }
    }
    return 'c_canchas';
}

function tablaSqlSeguro($tabla) {
    return '`' . str_replace('`', '``', $tabla) . '`';
}

function existeColumnaCancha($columnas, $nombre) {
    foreach ($columnas as $columna) {
        if (mb_strtolower($columna, 'UTF-8') === mb_strtolower($nombre, 'UTF-8')) {
            return true;
        }
    }
    return false;
}

$Codigo_Postal_Usuario = obtenerValorSesion($usuarios, [
    'Codigo_Postal',
    'CodigoPostal',
    'codigo_postal',
    'codigoPostal',
    'Codigo postal',
    'codigo postal',
    'CP',
    'cp'
]);

if ($Codigo_Postal_Usuario === '' && !empty($Id_Retador)) {
    $columnasRetador = [];
    $resColumnas = $conn->query("SHOW COLUMNS FROM retador");

    if ($resColumnas) {
        while ($col = $resColumnas->fetch_assoc()) {
            if (isset($col['Field'])) {
                $columnasRetador[] = $col['Field'];
            }
        }
    }

    $posiblesColumnas = [
        'Codigo_Postal',
        'CodigoPostal',
        'codigo_postal',
        'codigoPostal',
        'Codigo postal',
        'codigo postal',
        'CP',
        'cp'
    ];

    $columnaCP = '';
    foreach ($posiblesColumnas as $posible) {
        foreach ($columnasRetador as $columna) {
            $normalA = mb_strtolower(str_replace(['_', ' '], '', $posible), 'UTF-8');
            $normalB = mb_strtolower(str_replace(['_', ' '], '', $columna), 'UTF-8');
            if ($normalA === $normalB) {
                $columnaCP = $columna;
                break 2;
            }
        }
    }

    if ($columnaCP !== '') {
        $sqlCP = "SELECT " . nombreColumnaSeguro($columnaCP) . " AS CodigoPostalUsuario FROM retador WHERE Id_Retador = ? LIMIT 1";
        $stmtCP = $conn->prepare($sqlCP);

        if ($stmtCP) {
            $stmtCP->bind_param("s", $Id_Retador);
            $stmtCP->execute();
            $resCP = $stmtCP->get_result();
            if ($filaCP = $resCP->fetch_assoc()) {
                $Codigo_Postal_Usuario = trim((string)$filaCP['CodigoPostalUsuario']);
            }
            $stmtCP->close();
        }
    }
}

$tablaCanchas = resolverTablaCanchas($conn);
$tablaCanchasSql = tablaSqlSeguro($tablaCanchas);
$columnasCanchas = [];
$resColumnasCanchas = $conn->query("SHOW COLUMNS FROM $tablaCanchasSql");

if ($resColumnasCanchas) {
    while ($colCancha = $resColumnasCanchas->fetch_assoc()) {
        if (isset($colCancha['Field'])) {
            $columnasCanchas[] = $colCancha['Field'];
        }
    }
}

$selectDiasDisponibles = existeColumnaCancha($columnasCanchas, 'Dias_Disponibles')
    ? "Dias_Disponibles"
    : (existeColumnaCancha($columnasCanchas, 'Horarios') ? "Horarios AS Dias_Disponibles" : "'' AS Dias_Disponibles");

$selectHorarioApertura = existeColumnaCancha($columnasCanchas, 'Horario_Apertura')
    ? "Horario_Apertura"
    : "'' AS Horario_Apertura";

$selectHorarioClausura = existeColumnaCancha($columnasCanchas, 'Horario_Clausura')
    ? "Horario_Clausura"
    : "'' AS Horario_Clausura";


$mensajeAccion = '';
$tipoMensajeAccion = '';
$canchaEditando = null;

$estadosDisponibilidad = ['Disponible', 'Clausurado', 'En remodelacion', 'No disponible'];
$tiposCanchaBase = ['Pasto sintético', 'Pasto natural', 'Concreto', 'Arcilla', 'Otro'];
$diasPermitidosCancha = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

function baseIdCanchaEdicion($nombre) {
    $base = trim((string)$nombre);
    $convertida = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
    if ($convertida !== false) {
        $base = $convertida;
    }
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
    if ($base === '') {
        $base = 'CANCHA';
    }
    return substr($base, 0, 45);
}

function generarIdCanchaEdicion($conn, $tablaSql, $nombre, $idActual) {
    $base = baseIdCanchaEdicion($nombre);
    $sql = "SELECT Id_Cancha FROM $tablaSql WHERE Id_Cancha = ? AND Id_Cancha <> ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $base . random_int(1000, 9999);
    }

    do {
        $nuevoId = $base . random_int(1000, 9999);
        $stmt->bind_param('ss', $nuevoId, $idActual);
        $stmt->execute();
        $res = $stmt->get_result();
        $existe = ($res && $res->num_rows > 0);
    } while ($existe);

    $stmt->close();
    return $nuevoId;
}

function existeDeporteCancha($conn, $idDeporte) {
    if (trim((string)$idDeporte) === '') {
        return true;
    }
    $sql = "SELECT Id_Deporte FROM deporte WHERE Id_Deporte = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $idDeporte);
    $stmt->execute();
    $stmt->store_result();
    $ok = ($stmt->num_rows > 0);
    $stmt->close();
    return $ok;
}

function consultarCanchaUsuario($conn, $tablaSql, $idCancha, $idDueno, $selectDiasDisponibles, $selectHorarioApertura, $selectHorarioClausura) {
    $sql = "SELECT 
                Id_Cancha,
                Nombre,
                Dueno,
                Id_Dueno,
                Pais,
                Estado,
                Ciudad,
                Direccion,
                Codigo_Postal,
                Foto,
                Correo_Electronico,
                Numero_Telefono,
                Tipo_Cancha,
                Deporte1,
                Deporte2,
                Deporte3,
                Estado_Cancha,
                Calificacion,
                $selectDiasDisponibles,
                $selectHorarioApertura,
                $selectHorarioClausura,
                Comentarios,
                Costo
           FROM $tablaSql
           WHERE Id_Cancha = ? AND Id_Dueno = ?
           LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $idCancha, $idDueno);
    $stmt->execute();
    $res = $stmt->get_result();
    $cancha = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $cancha ?: null;
}

function tomarPostCancha($nombre) {
    return isset($_POST[$nombre]) ? trim((string)$_POST[$nombre]) : '';
}

function bindParamsDinamico($stmt, $types, &$params) {
    $bind = [];
    $bind[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind[] = &$params[$i];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_estado_cancha') {
    $idEstado = tomarPostCancha('id_cancha_estado');
    $nuevoEstado = tomarPostCancha('nuevo_estado_cancha');

    if ($Id_Retador === '') {
        $mensajeAccion = 'No se encontró el usuario que inició sesión.';
        $tipoMensajeAccion = 'error';
    } elseif ($idEstado === '') {
        $mensajeAccion = 'No se encontró la cancha que deseas actualizar.';
        $tipoMensajeAccion = 'error';
    } elseif (!in_array($nuevoEstado, $estadosDisponibilidad, true)) {
        $mensajeAccion = 'Selecciona una disponibilidad válida.';
        $tipoMensajeAccion = 'error';
    } else {
        $sqlEstado = "UPDATE $tablaCanchasSql SET Estado_Cancha = ? WHERE Id_Cancha = ? AND Id_Dueno = ? LIMIT 1";
        $stmtEstado = $conn->prepare($sqlEstado);

        if ($stmtEstado) {
            $stmtEstado->bind_param('sss', $nuevoEstado, $idEstado, $Id_Retador);
            if ($stmtEstado->execute() && $stmtEstado->affected_rows >= 0) {
                $mensajeAccion = 'Disponibilidad actualizada correctamente.';
                $tipoMensajeAccion = 'exito';
            } else {
                $mensajeAccion = 'No se pudo actualizar la disponibilidad.';
                $tipoMensajeAccion = 'error';
            }
            $stmtEstado->close();
        } else {
            $mensajeAccion = 'No se pudo preparar la actualización de disponibilidad.';
            $tipoMensajeAccion = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_edicion_cancha') {
    $idOriginal = tomarPostCancha('id_cancha_original');
    $canchaActual = consultarCanchaUsuario($conn, $tablaCanchasSql, $idOriginal, $Id_Retador, $selectDiasDisponibles, $selectHorarioApertura, $selectHorarioClausura);

    if (!$canchaActual) {
        $mensajeAccion = 'No se encontró la cancha o no pertenece a tu usuario.';
        $tipoMensajeAccion = 'error';
    } else {
        $nombreNuevo = tomarPostCancha('nombre_cancha');
        $paisNuevo = tomarPostCancha('pais');
        $estadoNuevo = tomarPostCancha('estado');
        $ciudadNueva = tomarPostCancha('ciudad');
        $direccionNueva = tomarPostCancha('direccion');
        $cpNuevo = tomarPostCancha('codigo_postal');
        $correoNuevo = tomarPostCancha('correo');
        $telefonoNuevo = tomarPostCancha('telefono');
        $tipoCanchaNuevo = tomarPostCancha('tipo_cancha');
        $tipoOtroNuevo = tomarPostCancha('tipo_otro');
        $deporte1Nuevo = tomarPostCancha('deporte1');
        $deporte2Nuevo = tomarPostCancha('deporte2');
        $deporte3Nuevo = tomarPostCancha('deporte3');
        $estadoCanchaNuevo = tomarPostCancha('estado_cancha');
        $horarioAperturaNuevo = tomarPostCancha('horario_apertura');
        $horarioClausuraNuevo = tomarPostCancha('horario_clausura');
        $comentariosNuevo = tomarPostCancha('comentarios');
        $costoNuevo = tomarPostCancha('costo');
        $diasSeleccionados = isset($_POST['dias_disponibles']) && is_array($_POST['dias_disponibles']) ? $_POST['dias_disponibles'] : [];
        $diasSeleccionados = array_values(array_intersect($diasPermitidosCancha, $diasSeleccionados));
        $erroresEdicion = [];

        if ($nombreNuevo === '') $erroresEdicion[] = 'Escribe el nombre de la cancha.';
        if ($paisNuevo === '') $erroresEdicion[] = 'Escribe el país.';
        if ($estadoNuevo === '') $erroresEdicion[] = 'Escribe el estado.';
        if ($ciudadNueva === '') $erroresEdicion[] = 'Escribe la ciudad.';
        if ($direccionNueva === '') $erroresEdicion[] = 'Escribe la dirección.';
        if ($cpNuevo === '') $erroresEdicion[] = 'Escribe el código postal.';
        if ($correoNuevo === '') $erroresEdicion[] = 'Escribe el correo electrónico.';
        if ($telefonoNuevo === '') $erroresEdicion[] = 'Escribe el teléfono.';
        if ($tipoCanchaNuevo === '') $erroresEdicion[] = 'Selecciona el tipo de cancha.';
        if ($tipoCanchaNuevo === 'Otro' && $tipoOtroNuevo === '') $erroresEdicion[] = 'Escribe el otro tipo de cancha.';
        if ($deporte1Nuevo === '') $erroresEdicion[] = 'Selecciona el deporte principal.';
        if ($estadoCanchaNuevo === '') $erroresEdicion[] = 'Selecciona la disponibilidad.';
        if (empty($diasSeleccionados)) $erroresEdicion[] = 'Selecciona al menos un día disponible.';
        if ($horarioAperturaNuevo === '') $erroresEdicion[] = 'Selecciona el horario de apertura.';
        if ($horarioClausuraNuevo === '') $erroresEdicion[] = 'Selecciona el horario de clausura.';
        if ($comentariosNuevo === '') $erroresEdicion[] = 'Escribe los comentarios.';
        if ($costoNuevo === '') $erroresEdicion[] = 'Escribe el costo.';

        if ($correoNuevo !== '' && !filter_var($correoNuevo, FILTER_VALIDATE_EMAIL)) {
            $erroresEdicion[] = 'El correo electrónico no tiene un formato válido.';
        }

        if ($telefonoNuevo !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $telefonoNuevo)) {
            $erroresEdicion[] = 'El teléfono solo debe llevar números, espacios o signos básicos.';
        }

        if (strlen($cpNuevo) > 10) {
            $erroresEdicion[] = 'El código postal no debe pasar de 10 caracteres.';
        }

        if (!in_array($estadoCanchaNuevo, $estadosDisponibilidad, true)) {
            $erroresEdicion[] = 'Selecciona una disponibilidad válida.';
        }

        if ($tipoCanchaNuevo !== '' && !in_array($tipoCanchaNuevo, $tiposCanchaBase, true)) {
            $erroresEdicion[] = 'Selecciona un tipo de cancha válido.';
        }

        if (!existeDeporteCancha($conn, $deporte1Nuevo) || !existeDeporteCancha($conn, $deporte2Nuevo) || !existeDeporteCancha($conn, $deporte3Nuevo)) {
            $erroresEdicion[] = 'Uno de los deportes seleccionados no existe en la tabla deporte.';
        }

        $deportesElegidos = array_filter([$deporte1Nuevo, $deporte2Nuevo, $deporte3Nuevo], function($v) {
            return trim((string)$v) !== '';
        });

        if (count($deportesElegidos) !== count(array_unique($deportesElegidos))) {
            $erroresEdicion[] = 'No repitas el mismo deporte.';
        }

        $tipoCanchaFinal = ($tipoCanchaNuevo === 'Otro') ? $tipoOtroNuevo : $tipoCanchaNuevo;
        $diasDisponiblesFinal = implode(', ', $diasSeleccionados);
        $horariosFinal = $diasDisponiblesFinal . ' de ' . $horarioAperturaNuevo . ' a ' . $horarioClausuraNuevo;
        $nombreAnterior = isset($canchaActual['Nombre']) ? trim((string)$canchaActual['Nombre']) : '';
        $nuevoIdCancha = $idOriginal;
        $cambioId = false;

        if (mb_strtolower($nombreNuevo, 'UTF-8') !== mb_strtolower($nombreAnterior, 'UTF-8')) {
            $nuevoIdCancha = generarIdCanchaEdicion($conn, $tablaCanchasSql, $nombreNuevo, $idOriginal);
            $cambioId = true;
        }

        $fotoRutaBD = '';
        $fotoSubidaTemporal = '';

        if (empty($erroresEdicion) && isset($_FILES['foto_fachada']) && is_array($_FILES['foto_fachada']) && $_FILES['foto_fachada']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['foto_fachada']['error'] !== UPLOAD_ERR_OK) {
                $erroresEdicion[] = 'No se pudo subir la nueva foto.';
            } elseif ($_FILES['foto_fachada']['size'] > 1048576) {
                $erroresEdicion[] = 'La foto debe pesar menos de 1 MB.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['foto_fachada']['tmp_name']);
                finfo_close($finfo);

                $extensiones = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($extensiones[$mime])) {
                    $erroresEdicion[] = 'La foto debe ser JPG, PNG o WEBP.';
                } else {
                    $directorioFotos = __DIR__ . '/uploads/canchas';
                    if (!is_dir($directorioFotos)) {
                        mkdir($directorioFotos, 0775, true);
                    }
                    $nombreArchivoFoto = $nuevoIdCancha . '_' . time() . '.' . $extensiones[$mime];
                    $rutaDestino = $directorioFotos . '/' . $nombreArchivoFoto;
                    $fotoRutaBD = 'uploads/canchas/' . $nombreArchivoFoto;

                    if (!move_uploaded_file($_FILES['foto_fachada']['tmp_name'], $rutaDestino)) {
                        $erroresEdicion[] = 'No se pudo guardar la nueva foto.';
                    } else {
                        $fotoSubidaTemporal = $rutaDestino;
                    }
                }
            }
        }

        if (empty($erroresEdicion)) {
            $camposActualizar = [
                'Id_Cancha' => $nuevoIdCancha,
                'Nombre' => $nombreNuevo,
                'Pais' => $paisNuevo,
                'Estado' => $estadoNuevo,
                'Ciudad' => $ciudadNueva,
                'Direccion' => $direccionNueva,
                'Codigo_Postal' => $cpNuevo,
                'Correo_Electronico' => $correoNuevo,
                'Numero_Telefono' => $telefonoNuevo,
                'Tipo_Cancha' => $tipoCanchaFinal,
                'Deporte1' => $deporte1Nuevo,
                'Deporte2' => $deporte2Nuevo,
                'Deporte3' => $deporte3Nuevo,
                'Estado_Cancha' => $estadoCanchaNuevo,
                'Dias_Disponibles' => $diasDisponiblesFinal,
                'Horario_Apertura' => $horarioAperturaNuevo,
                'Horario_Clausura' => $horarioClausuraNuevo,
                'Horarios' => $horariosFinal,
                'Comentarios' => $comentariosNuevo,
                'Costo' => $costoNuevo
            ];

            if ($fotoRutaBD !== '') {
                $camposActualizar['Foto'] = $fotoRutaBD;
            }

            $sets = [];
            $params = [];
            foreach ($camposActualizar as $columna => $valor) {
                if (existeColumnaCancha($columnasCanchas, $columna)) {
                    $sets[] = nombreColumnaSeguro($columna) . ' = ?';
                    $params[] = $valor;
                }
            }

            $params[] = $idOriginal;
            $params[] = $Id_Retador;
            $types = str_repeat('s', count($params));
            $sqlUpdateCancha = "UPDATE $tablaCanchasSql SET " . implode(', ', $sets) . " WHERE Id_Cancha = ? AND Id_Dueno = ? LIMIT 1";
            $stmtUpdateCancha = $conn->prepare($sqlUpdateCancha);

            if ($stmtUpdateCancha) {
                bindParamsDinamico($stmtUpdateCancha, $types, $params);
                if ($stmtUpdateCancha->execute()) {
                    if ($cambioId) {
                        $mensajeAccion = 'Cancha actualizada correctamente. Advertencia: como cambiaste el nombre de la cancha, también cambió su ID de ' . $idOriginal . ' a ' . $nuevoIdCancha . '.';
                    } else {
                        $mensajeAccion = 'Cancha actualizada correctamente.';
                    }
                    $tipoMensajeAccion = 'exito';
                } else {
                    if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                        unlink($fotoSubidaTemporal);
                    }
                    $mensajeAccion = 'No se pudo actualizar la cancha: ' . $stmtUpdateCancha->error;
                    $tipoMensajeAccion = 'error';
                    $canchaEditando = $canchaActual;
                }
                $stmtUpdateCancha->close();
            } else {
                if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                    unlink($fotoSubidaTemporal);
                }
                $mensajeAccion = 'No se pudo preparar la actualización: ' . $conn->error;
                $tipoMensajeAccion = 'error';
                $canchaEditando = $canchaActual;
            }
        } else {
            $mensajeAccion = implode(' ', $erroresEdicion);
            $tipoMensajeAccion = 'error';
            $canchaEditando = $canchaActual;
            $canchaEditando['Nombre'] = $nombreNuevo;
            $canchaEditando['Pais'] = $paisNuevo;
            $canchaEditando['Estado'] = $estadoNuevo;
            $canchaEditando['Ciudad'] = $ciudadNueva;
            $canchaEditando['Direccion'] = $direccionNueva;
            $canchaEditando['Codigo_Postal'] = $cpNuevo;
            $canchaEditando['Correo_Electronico'] = $correoNuevo;
            $canchaEditando['Numero_Telefono'] = $telefonoNuevo;
            $canchaEditando['Tipo_Cancha'] = $tipoCanchaFinal;
            $canchaEditando['Deporte1'] = $deporte1Nuevo;
            $canchaEditando['Deporte2'] = $deporte2Nuevo;
            $canchaEditando['Deporte3'] = $deporte3Nuevo;
            $canchaEditando['Estado_Cancha'] = $estadoCanchaNuevo;
            $canchaEditando['Dias_Disponibles'] = $diasDisponiblesFinal;
            $canchaEditando['Horario_Apertura'] = $horarioAperturaNuevo;
            $canchaEditando['Horario_Clausura'] = $horarioClausuraNuevo;
            $canchaEditando['Comentarios'] = $comentariosNuevo;
            $canchaEditando['Costo'] = $costoNuevo;
        }
    }
}

if ($canchaEditando === null && isset($_GET['editar']) && trim((string)$_GET['editar']) !== '' && $Id_Retador !== '') {
    $canchaEditando = consultarCanchaUsuario($conn, $tablaCanchasSql, trim((string)$_GET['editar']), $Id_Retador, $selectDiasDisponibles, $selectHorarioApertura, $selectHorarioClausura);
    if (!$canchaEditando) {
        $mensajeAccion = 'No se encontró la cancha seleccionada o no pertenece a tu usuario.';
        $tipoMensajeAccion = 'error';
    }
}

if ($Id_Retador === '') {
    $mensaje_canchas = 'No se encontró el usuario que inició sesión.';
} elseif (!existeColumnaCancha($columnasCanchas, 'Id_Dueno')) {
    $mensaje_canchas = 'No se encontró la columna Id_Dueno en la tabla de canchas.';
} else {
    $sqlCanchas = "SELECT 
                        Id_Cancha,
                        Nombre,
                        Dueno,
                        Id_Dueno,
                        Pais,
                        Estado,
                        Ciudad,
                        Direccion,
                        Codigo_Postal,
                        Foto,
                        Correo_Electronico,
                        Numero_Telefono,
                        Tipo_Cancha,
                        Deporte1,
                        Deporte2,
                        Deporte3,
                        Estado_Cancha,
                        Calificacion,
                        $selectDiasDisponibles,
                        $selectHorarioApertura,
                        $selectHorarioClausura,
                        Comentarios,
                        Costo,
                        0 AS Distancia_CP,
                        1 AS CP_Numerico
                   FROM $tablaCanchasSql
                   WHERE Id_Dueno = ?
                   ORDER BY Nombre ASC";

    $stmtCanchas = $conn->prepare($sqlCanchas);

    if ($stmtCanchas) {
        $stmtCanchas->bind_param("s", $Id_Retador);
        $stmtCanchas->execute();
        $resCanchas = $stmtCanchas->get_result();

        while ($filaCancha = $resCanchas->fetch_assoc()) {
            $canchas_cercanas[] = $filaCancha;
        }

        $stmtCanchas->close();
    } else {
        $mensaje_canchas = 'No se pudo consultar la tabla de canchas. Revisa que la tabla exista y tenga los campos correctos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mis canchas - RETAME</title>
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
    height:2px;
    background:currentColor;
    position:relative;
    border-radius:999px;
}

.menu-toggle span::before,
.menu-toggle span::after{
    content:"";
    position:absolute;
    left:0;
    width:25px;
    height:2px;
    background:currentColor;
    border-radius:999px;
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

body.bottom-nav-hidden .bottom-nav{
    opacity:0;
    transform:translateY(115%);
    pointer-events:none;
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


.canchas-wrapper{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:22px;
}

.canchas-head{
    width:100%;
    border-radius:30px;
    background:rgba(255,255,255,0.95);
    border:1px solid rgba(17,24,39,0.06);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(22px,4vw,34px);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    position:relative;
    overflow:hidden;
}

.canchas-head::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 12% 18%,rgba(24,119,242,0.25),transparent 260px),
        radial-gradient(circle at 88% 82%,rgba(255,75,92,0.23),transparent 290px),
        linear-gradient(90deg,rgba(24,119,242,0.12),rgba(255,255,255,0.02) 48%,rgba(255,75,92,0.13));
}

.canchas-head > *{
    position:relative;
    z-index:1;
}

.canchas-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    width:max-content;
    max-width:100%;
    padding:9px 14px;
    border-radius:999px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.35);
    color:#1877f2;
    font-size:13px;
    font-weight:900;
    box-shadow:0 8px 18px rgba(0,0,0,0.06);
    margin-bottom:12px;
}

.canchas-head h2{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.65rem,4vw,2.45rem);
    margin-bottom:8px;
}

.canchas-head p{
    max-width:680px;
    color:#4b5563;
    line-height:1.65;
    font-size:0.98rem;
}

.canchas-botones{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:12px;
    min-width:260px;
}

.btn-cancha{
    min-height:50px;
    border-radius:18px;
    padding:13px 18px;
    text-decoration:none;
    color:#ffffff;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    box-shadow:0 12px 24px rgba(0,0,0,0.14);
    transition:0.25s ease;
    text-align:center;
}

.btn-cancha:hover{
    transform:translateY(-3px);
    box-shadow:0 18px 32px rgba(0,0,0,0.18);
}

.btn-cancha.azul{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    border:2px solid rgba(0,153,255,0.45);
}

.btn-cancha.rojo{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    border:2px solid rgba(255,75,92,0.52);
}

.canchas-panel{
    width:100%;
    border-radius:30px;
    background:rgba(255,255,255,0.95);
    border:1px solid rgba(17,24,39,0.06);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(18px,4vw,30px);
}

.canchas-panel-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    margin-bottom:18px;
}

.canchas-panel-title h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.45rem);
}

.canchas-panel-title span{
    color:#6b7280;
    font-size:13px;
    font-weight:800;
}

.canchas-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.cancha-card{
    border-radius:24px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.28);
    box-shadow:
        0 12px 26px rgba(0,0,0,0.08),
        0 0 0 2px rgba(24,119,242,0.08);
    overflow:hidden;
    display:grid;
    grid-template-columns:170px 1fr;
    min-height:210px;
}

.cancha-foto{
    width:100%;
    height:100%;
    min-height:210px;
    background:
        radial-gradient(circle at 30% 20%,rgba(24,119,242,0.24),transparent 130px),
        linear-gradient(135deg,#eaf4ff,#fff2f4);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#1877f2;
    font-size:44px;
    font-weight:900;
}

.cancha-foto img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.cancha-info{
    padding:18px;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.cancha-info h4{
    color:#111827;
    font-size:1.08rem;
    font-weight:900;
    line-height:1.25;
}

.cancha-meta{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.cancha-tag{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:7px 10px;
    border-radius:999px;
    background:#f3f8ff;
    border:1px solid rgba(24,119,242,0.16);
    color:#1f2937;
    font-size:12px;
    font-weight:800;
}

.cancha-ubicacion,
.cancha-contacto,
.cancha-extra{
    color:#4b5563;
    font-size:13px;
    line-height:1.55;
}

.cancha-extra{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
}

.cancha-costo{
    margin-top:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding-top:8px;
    border-top:1px solid rgba(17,24,39,0.08);
}

.cancha-costo strong{
    color:#ff3045;
    font-size:1.05rem;
}

.cancha-costo span{
    color:#1877f2;
    font-weight:900;
    font-size:13px;
}

.canchas-vacio{
    min-height:210px;
    border-radius:24px;
    background:rgba(255,255,255,0.86);
    border:2px dashed rgba(0,153,255,0.42);
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:28px;
    color:#4b5563;
    line-height:1.7;
    font-weight:700;
}

.mis-canchas-vacio{
    min-height:360px;
}

.mis-canchas-vacio > div{
    max-width:560px;
}

.vacio-acciones{
    display:flex;
    justify-content:center;
    margin-top:20px;
}

.vacio-acciones .btn-cancha{
    min-width:230px;
}


.alerta-accion{
    border-radius:22px;
    padding:15px 17px;
    font-weight:900;
    line-height:1.55;
    display:flex;
    gap:10px;
    align-items:flex-start;
    border:2px solid rgba(0,153,255,0.34);
    box-shadow:0 10px 20px rgba(0,0,0,0.08);
}

.alerta-accion.exito{
    background:#ecfdf5;
    color:#047857;
    border-color:rgba(16,185,129,0.40);
}

.alerta-accion.error{
    background:#fff1f2;
    color:#b91c1c;
    border-color:rgba(255,75,92,0.42);
}

.editar-panel{
    width:100%;
    border-radius:30px;
    background:rgba(255,255,255,0.95);
    border:1px solid rgba(17,24,39,0.06);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(18px,4vw,30px);
}

.editar-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    margin-bottom:18px;
}

.editar-title h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.45rem);
}

.editar-title p{
    color:#6b7280;
    font-size:13px;
    font-weight:800;
    line-height:1.5;
}

.editar-form{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.form-group.full{
    grid-column:1 / -1;
}

.form-group label,
.group-label{
    color:#374151;
    font-size:13px;
    font-weight:900;
}

.form-group input,
.form-group select,
.form-group textarea{
    width:100%;
    min-height:52px;
    border-radius:18px;
    border:2px solid rgba(0,153,255,0.35);
    background:#ffffff;
    color:#111827;
    outline:none;
    padding:13px 15px;
    font-family:'Poppins',sans-serif;
    font-size:14px;
    font-weight:700;
    box-shadow:
        0 8px 16px rgba(0,0,0,0.05),
        0 0 0 2px rgba(24,119,242,0.06);
    transition:0.25s ease;
}

.form-group textarea{
    min-height:120px;
    resize:vertical;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    border-color:rgba(255,75,92,0.88);
    box-shadow:
        0 10px 20px rgba(0,0,0,0.08),
        0 0 0 3px rgba(0,153,255,0.18);
}

.input-readonly{
    background:#f8fafc !important;
    color:#475569 !important;
}

.form-note{
    color:#6b7280;
    font-size:12px;
    font-weight:700;
    line-height:1.5;
}

.check-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
}

.check-card{
    min-height:48px;
    border-radius:16px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.34);
    display:flex;
    align-items:center;
    gap:9px;
    padding:10px 12px;
    color:#374151;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    box-shadow:0 6px 14px rgba(0,0,0,0.05);
}

.check-card input{
    width:18px;
    height:18px;
    min-height:18px;
    box-shadow:none;
}

.btn-row{
    grid-column:1 / -1;
    display:flex;
    justify-content:flex-end;
    gap:12px;
    flex-wrap:wrap;
    padding-top:8px;
}

.btn-accion-card{
    min-height:42px;
    border-radius:15px;
    padding:9px 13px;
    border:none;
    text-decoration:none;
    color:#ffffff;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    box-shadow:0 10px 20px rgba(0,0,0,0.12);
    transition:0.25s ease;
    cursor:pointer;
    font-family:'Poppins',sans-serif;
    font-size:12px;
}

.btn-accion-card:hover{
    transform:translateY(-2px);
    box-shadow:0 14px 26px rgba(0,0,0,0.16);
}

.btn-accion-card.azul{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    border:2px solid rgba(0,153,255,0.45);
}

.btn-accion-card.rojo{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    border:2px solid rgba(255,75,92,0.52);
}

.btn-accion-card.blanco{
    color:#1877f2;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.45);
}

.cancha-acciones{
    display:flex;
    flex-wrap:wrap;
    gap:9px;
    align-items:center;
}

.disponibilidad-box{
    position:relative;
}

.disponibilidad-box summary{
    list-style:none;
}

.disponibilidad-box summary::-webkit-details-marker{
    display:none;
}

.disponibilidad-form{
    margin-top:9px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    padding:12px;
    border-radius:18px;
    background:#f8fafc;
    border:2px solid rgba(0,153,255,0.24);
}

.disponibilidad-form select{
    min-height:42px;
    border-radius:14px;
    border:2px solid rgba(0,153,255,0.35);
    padding:8px 10px;
    font-family:'Poppins',sans-serif;
    font-weight:800;
    outline:none;
    background:#ffffff;
    color:#111827;
}

.foto-actual-mini{
    width:100%;
    min-height:105px;
    border-radius:18px;
    border:2px dashed rgba(0,153,255,0.34);
    background:#f8fafc;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    color:#1877f2;
    font-size:34px;
    font-weight:900;
}

.foto-actual-mini img{
    width:100%;
    height:100%;
    max-height:180px;
    object-fit:cover;
    display:block;
}

body.dark-mode .canchas-head,
body.dark-mode .canchas-panel,
body.dark-mode .cancha-card,
body.dark-mode .editar-panel,
body.dark-mode .check-card,
body.dark-mode .disponibilidad-form,
body.dark-mode .foto-actual-mini,
body.dark-mode .canchas-chip,
body.dark-mode .canchas-vacio{
    background:#111827;
    border-color:rgba(255,75,92,0.72);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 18px rgba(0,153,255,0.16);
}

body.dark-mode .canchas-head::before{
    background:
        radial-gradient(circle at 12% 18%,rgba(0,153,255,0.26),transparent 260px),
        radial-gradient(circle at 88% 82%,rgba(255,75,92,0.22),transparent 290px),
        linear-gradient(90deg,rgba(0,153,255,0.14),rgba(17,24,39,0.10) 48%,rgba(255,75,92,0.14));
}

body.dark-mode .canchas-head h2,
body.dark-mode .canchas-panel-title h3,
body.dark-mode .canchas-chip{
    color:#4db8ff;
}

body.dark-mode .canchas-head p,
body.dark-mode .canchas-panel-title span,
body.dark-mode .cancha-info h4,
body.dark-mode .cancha-ubicacion,
body.dark-mode .cancha-contacto,
body.dark-mode .cancha-extra,
body.dark-mode .canchas-vacio,
body.dark-mode .cancha-tag{
    color:#e5e7eb;
}

body.dark-mode .form-group input,
body.dark-mode .form-group select,
body.dark-mode .form-group textarea,
body.dark-mode .disponibilidad-form select{
    background:#0b1220;
    color:#e5e7eb;
    border-color:rgba(0,153,255,0.42);
}

body.dark-mode .input-readonly{
    background:#0b1220 !important;
    color:#d1d5db !important;
}

body.dark-mode .editar-title h3,
body.dark-mode .form-group label,
body.dark-mode .group-label,
body.dark-mode .form-note,
body.dark-mode .editar-title p,
body.dark-mode .check-card{
    color:#e5e7eb;
}

body.dark-mode .cancha-tag{
    background:#0b1220;
    border-color:rgba(0,153,255,0.30);
}

body.dark-mode .cancha-foto{
    background:
        radial-gradient(circle at 30% 20%,rgba(0,153,255,0.24),transparent 130px),
        linear-gradient(135deg,#0b1220,#160a12);
    color:#4db8ff;
}

body.dark-mode .cancha-costo{
    border-top-color:rgba(255,255,255,0.10);
}

@media(max-width:1040px){
    .canchas-head{
        flex-direction:column;
        align-items:flex-start;
    }

    .canchas-botones{
        justify-content:flex-start;
        min-width:0;
        width:100%;
    }

    .btn-cancha{
        flex:1 1 210px;
    }

    .canchas-grid{
        grid-template-columns:1fr;
    }

    .check-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}


@media(max-width:620px){
    .canchas-panel-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .cancha-card{
        grid-template-columns:1fr;
    }

    .cancha-foto{
        min-height:170px;
    }

    .cancha-extra{
        grid-template-columns:1fr;
    }

    .cancha-costo{
        align-items:flex-start;
        flex-direction:column;
    }

    .editar-form{
        grid-template-columns:1fr;
    }

    .check-grid{
        grid-template-columns:1fr;
    }

    .btn-row{
        flex-direction:column;
    }

    .btn-accion-card{
        width:100%;
    }
}


</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

<div class="toast-container" id="toastContainer"></div>
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

        <div class="acciones-grid">
            <a href="../Equipo/UnirmeOtroEquipo.php" class="accion-boceto">
                <img src="../Imagenes/ImgUnion.png" alt="">
                <span>Unirme equipo</span>
            </a>

            <a href="../Equipo/CrearEquipo.php" class="accion-boceto">
                <img src="../Imagenes/ImgCreacion.png" alt="">
                <span>Crear equipo</span>
            </a>

            <a href="../Solicitudes.php" class="accion-boceto">
                <img src="../Imagenes/ImgSolicitud.png" alt="">
                <span>Solicitud</span>
            </a>

            <a href="../Equipo/Mis_Equipos.php" class="accion-boceto">
                <img src="../Imagenes/ImgEquipo.png" alt="">
                <span>Equipo</span>
            </a>

            <a href="../Ligas/liga.php" class="accion-boceto">
                <img src="../Imagenes/ImgLigas.png" alt="">
                <span>Ligas</span>
            </a>

            <a href="../Retar/retar.php" class="accion-boceto">
                <img src="../Imagenes/ImgReta.png" alt="">
                <span>Retar</span>
            </a>

            <a href="Canchas.php" class="accion-boceto">
                <img src="../Imagenes/ImgCanchas.png" alt="">
                <span>Canchas</span>
            </a>

            <a href="../Amigos.php" class="accion-boceto">
                <img src="../Imagenes/ImgAmigos.png" alt="">
                <span>Amigos</span>
            </a>
        </div>

        <a href="../login.php" class="cerrar-boceto">
            <img src="../Imagenes/ImgCerrar.png" alt="">
            <span>Cerrar sesión</span>
        </a>

        <div class="info-boceto">
            <a href="../Informacion.php">Información</a>
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
        <h1>Mis canchas</h1>
    </div>

    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo $Nombre; ?></span>
    </div>
</header>

<main class="main-content">
    <section class="canchas-wrapper">

        <div class="canchas-head">
            <div>
                <span class="canchas-chip">👤 ID dueño: <?php echo limpiarTexto($Id_Retador !== '' ? $Id_Retador : 'No encontrado'); ?></span>
                <h2>Mis canchas registradas</h2>
                <p>
                    Aquí aparecerán únicamente las canchas que tú registraste en la plataforma. El sistema consulta tu ID en el campo Id_Dueno de la tabla de canchas.
                </p>
            </div>

            <div class="canchas-botones">
                <a href="Canchas.php" class="btn-cancha azul">← Regresar</a>
                <?php if (!empty($canchas_cercanas)): ?>
                    <a href="registrarcanchas.php" class="btn-cancha rojo">➕ Registrar cancha</a>
                <?php endif; ?>
            </div>
        </div>


        <?php if ($mensajeAccion !== ''): ?>
            <div class="alerta-accion <?php echo limpiarTexto($tipoMensajeAccion); ?>">
                <span><?php echo $tipoMensajeAccion === 'exito' ? '✅' : '⚠️'; ?></span>
                <div><?php echo limpiarTexto($mensajeAccion); ?></div>
            </div>
        <?php endif; ?>

        <?php if (is_array($canchaEditando)): ?>
            <?php
                $tipoCanchaEdit = isset($canchaEditando['Tipo_Cancha']) ? trim((string)$canchaEditando['Tipo_Cancha']) : '';
                $tipoOtroEdit = '';
                if ($tipoCanchaEdit !== '' && !in_array($tipoCanchaEdit, ['Pasto sintético', 'Pasto natural', 'Concreto', 'Arcilla'], true)) {
                    $tipoOtroEdit = $tipoCanchaEdit;
                    $tipoCanchaEdit = 'Otro';
                }
                $diasEdit = isset($canchaEditando['Dias_Disponibles']) ? array_map('trim', explode(',', (string)$canchaEditando['Dias_Disponibles'])) : [];
                $fotoEdit = isset($canchaEditando['Foto']) ? trim((string)$canchaEditando['Foto']) : '';
            ?>
            <div class="editar-panel" id="editar-cancha">
                <div class="editar-title">
                    <div>
                        <h3>Editar cancha</h3>
                        <p>La ID de cancha, ID dueño y nombre del dueño no se editan manualmente. Si cambias el nombre de la cancha, el sistema generará una nueva ID que no exista.</p>
                    </div>
                    <a href="MisCanchas.php" class="btn-accion-card blanco">Cancelar edición</a>
                </div>

                <form class="editar-form" method="POST" enctype="multipart/form-data" id="formEditarCancha">
                    <input type="hidden" name="accion" value="guardar_edicion_cancha">
                    <input type="hidden" name="id_cancha_original" value="<?php echo limpiarTexto($canchaEditando['Id_Cancha']); ?>">

                    <div class="form-group">
                        <label>ID cancha actual</label>
                        <input type="text" class="input-readonly" value="<?php echo limpiarTexto($canchaEditando['Id_Cancha']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>ID dueño</label>
                        <input type="text" class="input-readonly" value="<?php echo limpiarTexto($canchaEditando['Id_Dueno']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Nombre del dueño</label>
                        <input type="text" class="input-readonly" value="<?php echo limpiarTexto($canchaEditando['Dueno']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="nombre_cancha_edit">Nombre de la cancha</label>
                        <input type="text" id="nombre_cancha_edit" name="nombre_cancha" maxlength="100" required value="<?php echo limpiarTexto($canchaEditando['Nombre']); ?>" data-original="<?php echo limpiarTexto($canchaEditando['Nombre']); ?>">
                        <span class="form-note">Si cambias este nombre, también cambiará automáticamente la ID de la cancha.</span>
                    </div>

                    <div class="form-group">
                        <label for="pais_edit">País</label>
                        <input type="text" id="pais_edit" name="pais" maxlength="80" required value="<?php echo limpiarTexto($canchaEditando['Pais']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="estado_edit">Estado</label>
                        <input type="text" id="estado_edit" name="estado" maxlength="80" required value="<?php echo limpiarTexto($canchaEditando['Estado']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="ciudad_edit">Ciudad</label>
                        <input type="text" id="ciudad_edit" name="ciudad" maxlength="80" required value="<?php echo limpiarTexto($canchaEditando['Ciudad']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="codigo_postal_edit">Código postal</label>
                        <input type="text" id="codigo_postal_edit" name="codigo_postal" maxlength="10" required value="<?php echo limpiarTexto($canchaEditando['Codigo_Postal']); ?>">
                    </div>

                    <div class="form-group full">
                        <label for="direccion_edit">Dirección</label>
                        <input type="text" id="direccion_edit" name="direccion" maxlength="200" required value="<?php echo limpiarTexto($canchaEditando['Direccion']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="correo_edit">Correo electrónico</label>
                        <input type="email" id="correo_edit" name="correo" maxlength="120" required value="<?php echo limpiarTexto($canchaEditando['Correo_Electronico']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="telefono_edit">Número de teléfono</label>
                        <input type="tel" id="telefono_edit" name="telefono" maxlength="20" required value="<?php echo limpiarTexto($canchaEditando['Numero_Telefono']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="tipo_cancha_edit">Tipo de cancha</label>
                        <select id="tipo_cancha_edit" name="tipo_cancha" required>
                            <option value="">Selecciona una opción</option>
                            <?php foreach ($tiposCanchaBase as $tipoBase): ?>
                                <option value="<?php echo limpiarTexto($tipoBase); ?>" <?php echo ($tipoCanchaEdit === $tipoBase) ? 'selected' : ''; ?>><?php echo limpiarTexto($tipoBase); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="grupoTipoOtroEdit">
                        <label for="tipo_otro_edit">Otro tipo de cancha</label>
                        <input type="text" id="tipo_otro_edit" name="tipo_otro" maxlength="80" value="<?php echo limpiarTexto($tipoOtroEdit); ?>">
                    </div>

                    <div class="form-group">
                        <label for="deporte1_edit">Deporte 1</label>
                        <select id="deporte1_edit" name="deporte1" required>
                            <option value="">Selecciona un deporte</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ((string)$canchaEditando['Deporte1'] === (string)$dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="deporte2_edit">Deporte 2</label>
                        <select id="deporte2_edit" name="deporte2">
                            <option value="">Opcional</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ((string)$canchaEditando['Deporte2'] === (string)$dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="deporte3_edit">Deporte 3</label>
                        <select id="deporte3_edit" name="deporte3">
                            <option value="">Opcional</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ((string)$canchaEditando['Deporte3'] === (string)$dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="estado_cancha_edit">Disponibilidad</label>
                        <select id="estado_cancha_edit" name="estado_cancha" required>
                            <?php foreach ($estadosDisponibilidad as $estadoDisp): ?>
                                <option value="<?php echo limpiarTexto($estadoDisp); ?>" <?php echo ((string)$canchaEditando['Estado_Cancha'] === (string)$estadoDisp) ? 'selected' : ''; ?>><?php echo limpiarTexto($estadoDisp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <span class="group-label">Días disponibles</span>
                        <div class="check-grid">
                            <?php foreach ($diasPermitidosCancha as $dia): ?>
                                <label class="check-card">
                                    <input type="checkbox" name="dias_disponibles[]" value="<?php echo limpiarTexto($dia); ?>" <?php echo in_array($dia, $diasEdit, true) ? 'checked' : ''; ?>>
                                    <span><?php echo limpiarTexto($dia); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="horario_apertura_edit">Horario de apertura</label>
                        <input type="time" id="horario_apertura_edit" name="horario_apertura" required value="<?php echo limpiarTexto($canchaEditando['Horario_Apertura']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="horario_clausura_edit">Horario de clausura</label>
                        <input type="time" id="horario_clausura_edit" name="horario_clausura" required value="<?php echo limpiarTexto($canchaEditando['Horario_Clausura']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Foto actual</label>
                        <div class="foto-actual-mini">
                            <?php if ($fotoEdit !== ''): ?>
                                <img src="<?php echo limpiarTexto($fotoEdit); ?>" alt="Foto actual">
                            <?php else: ?>
                                📷
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="foto_fachada_edit">Cambiar foto</label>
                        <input type="file" id="foto_fachada_edit" name="foto_fachada" accept="image/jpeg,image/png,image/webp">
                        <span class="form-note">Opcional. Si adjuntas una nueva foto, debe pesar menos de 1 MB.</span>
                    </div>

                    <div class="form-group">
                        <label for="costo_edit">Costo</label>
                        <input type="text" id="costo_edit" name="costo" maxlength="120" required value="<?php echo limpiarTexto($canchaEditando['Costo']); ?>">
                    </div>

                    <div class="form-group full">
                        <label for="comentarios_edit">Comentarios</label>
                        <textarea id="comentarios_edit" name="comentarios" required><?php echo limpiarTexto($canchaEditando['Comentarios']); ?></textarea>
                    </div>

                    <div class="btn-row">
                        <a href="MisCanchas.php" class="btn-accion-card blanco">Cancelar</a>
                        <button type="submit" class="btn-accion-card rojo">Guardar cambios</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="canchas-panel">
            <div class="canchas-panel-title">
                <h3>Tus canchas</h3>
                <span><?php echo count($canchas_cercanas); ?> cancha(s) registrada(s)</span>
            </div>

            <?php if ($mensaje_canchas !== ''): ?>
                <div class="canchas-vacio">
                    <div>
                        <strong>⚠️ <?php echo limpiarTexto($mensaje_canchas); ?></strong><br>
                        Revisa que tu sesión esté activa y que la tabla de canchas tenga el campo Id_Dueno.
                    </div>
                </div>
            <?php elseif (empty($canchas_cercanas)): ?>
                <div class="canchas-vacio mis-canchas-vacio">
                    <div>
                        <strong>🏟️ No tienes canchas registradas.</strong><br>
                        Cuando registres una cancha, aparecerá aquí con todos sus datos.
                        <div class="vacio-acciones">
                            <a href="registrarcanchas.php" class="btn-cancha rojo">➕ Registrar cancha</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="canchas-grid">
                    <?php foreach ($canchas_cercanas as $cancha): ?>
                        <?php
                            $fotoCancha = isset($cancha['Foto']) ? trim((string)$cancha['Foto']) : '';
                            $calificacion = ($cancha['Calificacion'] !== null && $cancha['Calificacion'] !== '') ? number_format((float)$cancha['Calificacion'], 2) : 'Sin calificación';
                            $costoGuardado = isset($cancha['Costo']) ? trim((string)$cancha['Costo']) : '';
                            if ($costoGuardado === '' || (is_numeric($costoGuardado) && (float)$costoGuardado == 0)) {
                                $costoTexto = 'Gratis';
                            } elseif (is_numeric($costoGuardado)) {
                                $costoTexto = '$' . number_format((float)$costoGuardado, 2);
                            } else {
                                $costoTexto = $costoGuardado;
                            }

                            $distanciaCP = isset($cancha['Distancia_CP']) ? (int)$cancha['Distancia_CP'] : 0;
                            $distanciaTexto = 'ID ' . (isset($cancha['Id_Cancha']) ? trim((string)$cancha['Id_Cancha']) : '');
                            $cercaniaTexto = 'Registrada por ti';
                            $diasDisponibles = isset($cancha['Dias_Disponibles']) ? trim((string)$cancha['Dias_Disponibles']) : '';
                            $horarioApertura = isset($cancha['Horario_Apertura']) ? trim((string)$cancha['Horario_Apertura']) : '';
                            $horarioClausura = isset($cancha['Horario_Clausura']) ? trim((string)$cancha['Horario_Clausura']) : '';
                            $deportesCancha = [];
                            if (!empty($cancha['Deporte1'])) $deportesCancha[] = $cancha['Deporte1'];
                            if (!empty($cancha['Deporte2'])) $deportesCancha[] = $cancha['Deporte2'];
                            if (!empty($cancha['Deporte3'])) $deportesCancha[] = $cancha['Deporte3'];
                        ?>

                        <article class="cancha-card">
                            <div class="cancha-foto">
                                <?php if ($fotoCancha !== ''): ?>
                                    <img src="<?php echo limpiarTexto($fotoCancha); ?>" alt="Foto de <?php echo limpiarTexto($cancha['Nombre']); ?>">
                                <?php else: ?>
                                    🏟️
                                <?php endif; ?>
                            </div>

                            <div class="cancha-info">
                                <h4><?php echo limpiarTexto($cancha['Nombre']); ?></h4>

                                <div class="cancha-meta">
                                    <span class="cancha-tag">📌 CP <?php echo limpiarTexto($cancha['Codigo_Postal']); ?></span>
                                    <span class="cancha-tag">🆔 <?php echo limpiarTexto($distanciaTexto); ?></span>
                                    <span class="cancha-tag">⭐ <?php echo limpiarTexto($calificacion); ?></span>
                                    <span class="cancha-tag"><?php echo limpiarTexto($cancha['Estado_Cancha']); ?></span>
                                </div>

                                <div class="cancha-acciones">
                                    <a href="MisCanchas.php?editar=<?php echo urlencode((string)$cancha['Id_Cancha']); ?>#editar-cancha" class="btn-accion-card azul">✏️ Editar</a>
                                    <details class="disponibilidad-box">
                                        <summary class="btn-accion-card rojo">⚙️ Disponibilidad</summary>
                                        <form method="POST" class="disponibilidad-form">
                                            <input type="hidden" name="accion" value="actualizar_estado_cancha">
                                            <input type="hidden" name="id_cancha_estado" value="<?php echo limpiarTexto($cancha['Id_Cancha']); ?>">
                                            <select name="nuevo_estado_cancha" required>
                                                <?php foreach ($estadosDisponibilidad as $estadoDisp): ?>
                                                    <option value="<?php echo limpiarTexto($estadoDisp); ?>" <?php echo ((string)$cancha['Estado_Cancha'] === (string)$estadoDisp) ? 'selected' : ''; ?>><?php echo limpiarTexto($estadoDisp); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-accion-card azul">Actualizar</button>
                                        </form>
                                    </details>
                                </div>

                                <div class="cancha-ubicacion">
                                    <strong>Ubicación:</strong> <?php echo limpiarTexto($cancha['Direccion']); ?>, <?php echo limpiarTexto($cancha['Ciudad']); ?>, <?php echo limpiarTexto($cancha['Estado']); ?>, <?php echo limpiarTexto($cancha['Pais']); ?>
                                </div>

                                <div class="cancha-extra">
                                    <div><strong>Tipo:</strong> <?php echo limpiarTexto($cancha['Tipo_Cancha']); ?></div>
                                    <div><strong>Deportes:</strong> <?php echo limpiarTexto(implode(', ', $deportesCancha)); ?></div>
                                    <div><strong>Días:</strong> <?php echo limpiarTexto($diasDisponibles !== '' ? $diasDisponibles : 'No especificado'); ?></div>
                                    <div><strong>Apertura:</strong> <?php echo limpiarTexto($horarioApertura !== '' ? $horarioApertura : 'No especificado'); ?></div>
                                    <div><strong>Clausura:</strong> <?php echo limpiarTexto($horarioClausura !== '' ? $horarioClausura : 'No especificado'); ?></div>
                                    <div><strong>Dueño:</strong> <?php echo limpiarTexto($cancha['Dueno']); ?></div>
                                </div>

                                <div class="cancha-contacto">
                                    <strong>Contacto:</strong> <?php echo limpiarTexto($cancha['Numero_Telefono']); ?> · <?php echo limpiarTexto($cancha['Correo_Electronico']); ?>
                                </div>

                                <?php if (!empty($cancha['Comentarios'])): ?>
                                    <div class="cancha-contacto">
                                        <strong>Comentarios:</strong> <?php echo limpiarTexto($cancha['Comentarios']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="cancha-costo">
                                    <strong><?php echo limpiarTexto($costoTexto); ?></strong>
                                    <span>Registrada por ti</span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </section>
</main>

<nav class="bottom-nav">
    <a href="../Perfil2.php" aria-label="Inicio">
        <img src="../Imagenes/ImgInicio.png" alt="">
    </a>

    <a href="../Retar/retar.php" aria-label="Retar">
        <img src="../Imagenes/ImgReta.png" alt="">
    </a>

    <a href="../Ligas/liga.php" aria-label="Ligas">
        <img src="../Imagenes/ImgLigas.png" alt="">
    </a>

    <a href="../Agenda.php" aria-label="Agenda">
        <img src="../Imagenes/ImgAgenda.png" alt="">
    </a>

    <a href="../Notificaciones.php" aria-label="Notificaciones">
        <img src="../Imagenes/ImgNoti.png" alt="">
        <?php if ($nuevas_count > 0): ?>
            <span class="bottom-noti">!</span>
        <?php endif; ?>
    </a>

    <a href="../configurarperfil.php" aria-label="Perfil">
        <img src="../Imagenes/ImgPerfil.png" alt="">
    </a>
</nav>

<script>
const nuevasNotificaciones = <?php echo json_encode($nuevas_notificaciones, JSON_UNESCAPED_UNICODE); ?>;

const menuToggle = document.getElementById('menuToggle');
const mobileOverlay = document.getElementById('mobileOverlay');

function esMovil(){
    return window.innerWidth <= 820;
}

menuToggle.addEventListener('click', function(){
    if(esMovil()){
        document.body.classList.toggle('sidebar-open');
    }else{
        document.body.classList.toggle('sidebar-hidden');
    }
});

mobileOverlay.addEventListener('click', function(){
    document.body.classList.remove('sidebar-open');
});

window.addEventListener('resize', function(){
    if(!esMovil()){
        document.body.classList.remove('sidebar-open');
    }
});

let ultimaPosicionScroll = window.scrollY || document.documentElement.scrollTop || 0;

function controlarBarrasPorScroll(){
    const posicionActual = window.scrollY || document.documentElement.scrollTop || 0;

    if(posicionActual > 18){
        document.body.classList.add('topbar-compact');
    }else{
        document.body.classList.remove('topbar-compact');
    }

    if(document.body.classList.contains('sidebar-open')){
        document.body.classList.remove('bottom-nav-hidden');
        ultimaPosicionScroll = posicionActual;
        return;
    }

    const estaBajando = posicionActual > ultimaPosicionScroll && posicionActual > 90;
    const estaSubiendo = posicionActual < ultimaPosicionScroll;

    if(estaBajando){
        document.body.classList.add('bottom-nav-hidden');
    }

    if(estaSubiendo || posicionActual < 90){
        document.body.classList.remove('bottom-nav-hidden');
    }

    ultimaPosicionScroll = Math.max(posicionActual, 0);
}

window.addEventListener('scroll', controlarBarrasPorScroll, { passive:true });

function mostrarToastsNotificaciones(){
    if(!Array.isArray(nuevasNotificaciones) || nuevasNotificaciones.length === 0) return;

    const cont = document.getElementById('toastContainer');
    if(!cont) return;

    nuevasNotificaciones.forEach((n, idx) => {
        const toast = document.createElement('div');
        toast.className = 'toast';

        const tipo = (n.tipo || 'Tipo').toString();
        const fecha = (n.fecha || '').toString();
        const msg = (n.descripcion || '').toString();

        toast.innerHTML = `
            <div class="icon">🔔</div>
            <div class="content">
                <div class="title">${escapeHtml(tipo)}</div>
                <div class="msg">${escapeHtml(msg)}</div>
                <div class="meta">
                    ${fecha ? `<span>${escapeHtml(fecha)}</span>` : ``}
                    <span>Nueva</span>
                </div>
            </div>
        `;

        cont.appendChild(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity 240ms ease, transform 240ms ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            setTimeout(() => {
                if(toast && toast.parentNode) toast.parentNode.removeChild(toast);
            }, 260);
        }, 5000 + (idx * 120));
    });
}

function escapeHtml(str){
    return String(str)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

document.addEventListener('DOMContentLoaded', function(){
    mostrarToastsNotificaciones();
    controlarBarrasPorScroll();
});

const darkModeToggle = document.getElementById('darkModeToggle');
const modoOscuroInicial = <?php echo $modoOscuroActivo ? 'true' : 'false'; ?>;

function aplicarModoOscuroVisual(estado){
    if(estado){
        document.body.classList.add('dark-mode');
        if(darkModeToggle){
            darkModeToggle.setAttribute('aria-label', 'Desactivar modo oscuro');
        }
    }else{
        document.body.classList.remove('dark-mode');
        if(darkModeToggle){
            darkModeToggle.setAttribute('aria-label', 'Activar modo oscuro');
        }
    }
}

function actualizarModoPerfilBD(estado){
    const datos = new FormData();
    datos.append('accion', 'actualizar_modo_perfil');
    datos.append('modo', estado ? 'oscuro' : 'predeterminado');

    return fetch(window.location.href, {
        method: 'POST',
        body: datos,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(respuesta => respuesta.json());
}

document.addEventListener('DOMContentLoaded', function(){
    aplicarModoOscuroVisual(modoOscuroInicial);

    if(darkModeToggle){
        darkModeToggle.addEventListener('click', function(){
            const nuevoEstado = !document.body.classList.contains('dark-mode');
            const estadoAnterior = !nuevoEstado;

            aplicarModoOscuroVisual(nuevoEstado);
            darkModeToggle.disabled = true;

            actualizarModoPerfilBD(nuevoEstado)
                .then(data => {
                    if(!data || !data.ok){
                        aplicarModoOscuroVisual(estadoAnterior);
                        alert(data && data.mensaje ? data.mensaje : 'No se pudo guardar el modo de perfil.');
                    }
                })
                .catch(() => {
                    aplicarModoOscuroVisual(estadoAnterior);
                    alert('No se pudo conectar con la base de datos para guardar el modo.');
                })
                .finally(() => {
                    darkModeToggle.disabled = false;
                });
        });
    }
});


const tipoCanchaEdit = document.getElementById('tipo_cancha_edit');
const grupoTipoOtroEdit = document.getElementById('grupoTipoOtroEdit');
const tipoOtroEdit = document.getElementById('tipo_otro_edit');
const nombreCanchaEdit = document.getElementById('nombre_cancha_edit');
const formEditarCancha = document.getElementById('formEditarCancha');
const fotoFachadaEdit = document.getElementById('foto_fachada_edit');

function controlarTipoOtroEdit(){
    if(!tipoCanchaEdit || !grupoTipoOtroEdit || !tipoOtroEdit) return;
    const esOtro = tipoCanchaEdit.value === 'Otro';
    grupoTipoOtroEdit.style.display = esOtro ? 'flex' : 'none';
    tipoOtroEdit.required = esOtro;
    if(!esOtro){
        tipoOtroEdit.value = '';
    }
}

if(tipoCanchaEdit){
    tipoCanchaEdit.addEventListener('change', controlarTipoOtroEdit);
    controlarTipoOtroEdit();
}

if(nombreCanchaEdit){
    nombreCanchaEdit.addEventListener('change', function(){
        const original = (nombreCanchaEdit.dataset.original || '').trim().toLowerCase();
        const actual = nombreCanchaEdit.value.trim().toLowerCase();
        if(original !== '' && actual !== '' && original !== actual){
            alert('Advertencia: si cambias el nombre de la cancha, también cambiará la ID de la cancha. El sistema generará una nueva ID disponible automáticamente.');
        }
    });
}

if(fotoFachadaEdit){
    fotoFachadaEdit.addEventListener('change', function(){
        const archivo = fotoFachadaEdit.files[0];
        if(archivo && archivo.size > 1048576){
            alert('La foto debe pesar menos de 1 MB.');
            fotoFachadaEdit.value = '';
        }
    });
}

if(formEditarCancha){
    formEditarCancha.addEventListener('submit', function(e){
        const dias = document.querySelectorAll('input[name="dias_disponibles[]"]:checked');
        if(dias.length === 0){
            e.preventDefault();
            alert('Selecciona al menos un día disponible.');
            return;
        }

        const d1 = document.getElementById('deporte1_edit') ? document.getElementById('deporte1_edit').value : '';
        const d2 = document.getElementById('deporte2_edit') ? document.getElementById('deporte2_edit').value : '';
        const d3 = document.getElementById('deporte3_edit') ? document.getElementById('deporte3_edit').value : '';
        const seleccionados = [d1, d2, d3].filter(v => v !== '');
        const unicos = new Set(seleccionados);

        if(seleccionados.length !== unicos.size){
            e.preventDefault();
            alert('No repitas el mismo deporte.');
            return;
        }

        if(nombreCanchaEdit){
            const original = (nombreCanchaEdit.dataset.original || '').trim().toLowerCase();
            const actual = nombreCanchaEdit.value.trim().toLowerCase();
            if(original !== '' && actual !== '' && original !== actual){
                const confirmar = confirm('Cambiaste el nombre de la cancha. También cambiará la ID de la cancha. ¿Deseas guardar los cambios?');
                if(!confirmar){
                    e.preventDefault();
                }
            }
        }
    });
}

</script>

</body>
</html>
