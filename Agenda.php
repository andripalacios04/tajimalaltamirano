<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('America/Mexico_City');
}

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

if (file_exists(__DIR__ . '/conexion.php')) {
    include_once __DIR__ . '/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    include_once __DIR__ . '/../conexion.php';
} else {
    die('No se encontró el archivo de conexión.');
}

$usuarios = $_SESSION['usuario_data'];

function limpiarTexto($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerDatoAgenda($datos, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return $default;
}

function normalizarTextoAgenda($valor) {
    $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
    return str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $valor);
}

function resolverRutaFotoAgenda($fotoPerfilBD) {
    $fotoPerfilBD = trim(str_replace('\\', '/', (string)$fotoPerfilBD));

    if ($fotoPerfilBD === '') {
        return '';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $fotoPerfilBD)) {
        return $fotoPerfilBD;
    }

    if (strpos($fotoPerfilBD, '../') === 0 || strpos($fotoPerfilBD, './') === 0) {
        return $fotoPerfilBD;
    }

    $fotoPerfilBD = ltrim($fotoPerfilBD, '/');
    $rutas = [
        $fotoPerfilBD,
        '../' . $fotoPerfilBD,
        'Imagenes/' . $fotoPerfilBD,
        '../Imagenes/' . $fotoPerfilBD,
        'uploads/' . $fotoPerfilBD,
        '../uploads/' . $fotoPerfilBD,
        'FotosPerfil/' . $fotoPerfilBD,
        '../FotosPerfil/' . $fotoPerfilBD,
        'assets/' . $fotoPerfilBD,
        '../assets/' . $fotoPerfilBD,
        'img/' . $fotoPerfilBD,
        '../img/' . $fotoPerfilBD
    ];

    foreach ($rutas as $ruta) {
        if (file_exists(__DIR__ . '/' . $ruta)) {
            return $ruta;
        }
    }

    return '../' . $fotoPerfilBD;
}

function tablaExisteAgenda($conn, $tabla) {
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function jugadorEnListaAgenda($json, $idRetador) {
    $json = trim((string)$json);
    $idRetador = trim((string)$idRetador);

    if ($json === '' || $idRetador === '') {
        return null;
    }

    $datos = json_decode($json, true);

    if (is_array($datos)) {
        foreach ($datos as $jugador) {
            if (is_array($jugador) && isset($jugador['id_retador']) && (string)$jugador['id_retador'] === (string)$idRetador) {
                return $jugador;
            }
        }
    }

    if (strpos($json, '"id_retador":"' . $idRetador . '"') !== false || strpos($json, '"id_retador":' . $idRetador) !== false) {
        return ['id_retador' => $idRetador];
    }

    return null;
}

function analizarParticipacionAgenda($reta, $idRetador) {
    $participacion = [
        'participa' => false,
        'rol' => '',
        'equipo' => '',
        'nombre_equipo' => '',
        'numero' => '',
        'posicion' => ''
    ];

    if ((isset($reta['id_creador']) && (string)$reta['id_creador'] === (string)$idRetador)) {
        $participacion['participa'] = true;
        $participacion['rol'] = 'Creador';
    }

    if (isset($reta['capitan_equipo1']) && (string)$reta['capitan_equipo1'] === (string)$idRetador) {
        $participacion['participa'] = true;
        $participacion['rol'] = 'Capitán';
        $participacion['equipo'] = 'Equipo 1';
        $participacion['nombre_equipo'] = $reta['nombre_equipo1'] ?? 'Equipo 1';
    }

    if (isset($reta['capitan_equipo2']) && (string)$reta['capitan_equipo2'] === (string)$idRetador) {
        $participacion['participa'] = true;
        $participacion['rol'] = 'Capitán';
        $participacion['equipo'] = 'Equipo 2';
        $participacion['nombre_equipo'] = $reta['nombre_equipo2'] ?? 'Equipo 2';
    }

    $jugador1 = jugadorEnListaAgenda($reta['jugadores_equipo1'] ?? '', $idRetador);
    if ($jugador1) {
        $participacion['participa'] = true;
        if ($participacion['rol'] === '') {
            $participacion['rol'] = 'Retador';
        }
        $participacion['equipo'] = 'Equipo 1';
        $participacion['nombre_equipo'] = $reta['nombre_equipo1'] ?? 'Equipo 1';
        $participacion['numero'] = $jugador1['numero_jugador'] ?? '';
        $participacion['posicion'] = $jugador1['posicion'] ?? '';
    }

    $jugador2 = jugadorEnListaAgenda($reta['jugadores_equipo2'] ?? '', $idRetador);
    if ($jugador2) {
        $participacion['participa'] = true;
        if ($participacion['rol'] === '') {
            $participacion['rol'] = 'Retador';
        }
        $participacion['equipo'] = 'Equipo 2';
        $participacion['nombre_equipo'] = $reta['nombre_equipo2'] ?? 'Equipo 2';
        $participacion['numero'] = $jugador2['numero_jugador'] ?? '';
        $participacion['posicion'] = $jugador2['posicion'] ?? '';
    }

    if ($participacion['rol'] === '' && $participacion['participa']) {
        $participacion['rol'] = 'Participante';
    }

    return $participacion;
}

function obtenerRetasAgendadas($conn, $idRetador) {
    $retas = [];

    if (!tablaExisteAgenda($conn, 'R_retasprogramadas')) {
        return $retas;
    }

    $sql = "SELECT * FROM R_retasprogramadas ORDER BY fecha_reta ASC, hora_reta ASC, creado_en DESC";
    $res = $conn->query($sql);

    if ($res) {
        while ($fila = $res->fetch_assoc()) {
            $participacion = analizarParticipacionAgenda($fila, $idRetador);
            if ($participacion['participa']) {
                $fila['_participacion'] = $participacion;
                $retas[] = $fila;
            }
        }
        $res->free();
    }

    usort($retas, function($a, $b) {
        $fechaA = trim((string)($a['fecha_reta'] ?? ''));
        $fechaB = trim((string)($b['fecha_reta'] ?? ''));
        $horaA = trim((string)($a['hora_reta'] ?? ''));
        $horaB = trim((string)($b['hora_reta'] ?? ''));
        return strcmp($fechaA . ' ' . $horaA, $fechaB . ' ' . $horaB);
    });

    return $retas;
}


function obtenerTimestampRetaAgenda($fecha, $hora) {
    $fecha = trim((string)$fecha);
    $hora = trim((string)$hora);

    if ($fecha === '') {
        return null;
    }

    $fecha = str_replace('/', '-', $fecha);
    $timestampFecha = strtotime($fecha);

    if ($timestampFecha === false) {
        return null;
    }

    $fechaSQL = date('Y-m-d', $timestampFecha);

    if ($hora === '') {
        $hora = '00:00:00';
    }

    $partesHora = preg_split('/\s*-\s*/', $hora);
    $hora = trim((string)$partesHora[0]);

    if (preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
        $hora .= ':00';
    }

    $timestampCompleto = strtotime($fechaSQL . ' ' . $hora);

    if ($timestampCompleto === false) {
        $timestampCompleto = strtotime($fechaSQL . ' 00:00:00');
    }

    return $timestampCompleto === false ? null : $timestampCompleto;
}

function resultadoDisponibleAgenda($fecha, $hora) {
    $timestampReta = obtenerTimestampRetaAgenda($fecha, $hora);

    if ($timestampReta === null) {
        return false;
    }

    return time() >= $timestampReta;
}

function textoDisponibleResultadoAgenda($fecha, $hora) {
    $fecha = trim((string)$fecha);
    $hora = trim((string)$hora);

    if ($fecha === '' && $hora === '') {
        return 'Se habilita cuando llegue la fecha y hora programada.';
    }

    if ($fecha === '') {
        return 'Se habilita cuando llegue la fecha programada.';
    }

    if ($hora === '') {
        return 'Se habilita el ' . $fecha . '.';
    }

    return 'Se habilita el ' . $fecha . ' a las ' . $hora . '.';
}



function obtenerColumnasTablaAgenda($conn, $tabla) {
    $columnas = [];
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");

    if ($res) {
        while ($fila = $res->fetch_assoc()) {
            if (isset($fila['Field'])) {
                $columnas[$fila['Field']] = true;
            }
        }
        $res->free();
    }

    return $columnas;
}

function asegurarCamposModificacionRetaProgramadaAgenda($conn) {
    if (!tablaExisteAgenda($conn, 'R_retasprogramadas')) {
        return false;
    }

    $columnas = obtenerColumnasTablaAgenda($conn, 'R_retasprogramadas');

    if (!isset($columnas['modificacion_por'])) {
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN modificacion_por VARCHAR(50) NULL AFTER estado_programada");
    }

    if (!isset($columnas['modificacion_equipo'])) {
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN modificacion_equipo INT NULL AFTER modificacion_por");
    }

    $columnas = obtenerColumnasTablaAgenda($conn, 'R_retasprogramadas');

    if (!isset($columnas['modificacion_en'])) {
        $afterModificacion = isset($columnas['modificacion_equipo']) ? 'modificacion_equipo' : 'modificacion_por';
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN modificacion_en TIMESTAMP NULL DEFAULT NULL AFTER `$afterModificacion`");
    }

    $columnas = obtenerColumnasTablaAgenda($conn, 'R_retasprogramadas');

    if (!isset($columnas['cancelado_por'])) {
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN cancelado_por VARCHAR(50) NULL AFTER modificacion_en");
    }

    if (!isset($columnas['cancelado_equipo'])) {
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN cancelado_equipo INT NULL AFTER cancelado_por");
    }

    if (!isset($columnas['cancelado_en'])) {
        $conn->query("ALTER TABLE R_retasprogramadas ADD COLUMN cancelado_en TIMESTAMP NULL DEFAULT NULL AFTER cancelado_equipo");
    }

    $columnas = obtenerColumnasTablaAgenda($conn, 'R_retasprogramadas');
    return isset($columnas['modificacion_por']) && isset($columnas['modificacion_equipo']) && isset($columnas['modificacion_en']) && isset($columnas['cancelado_por']) && isset($columnas['cancelado_equipo']) && isset($columnas['cancelado_en']);
}

function normalizarFechaBDAgenda($fecha) {
    $fecha = trim((string)$fecha);
    $fecha = str_replace('/', '-', $fecha);
    $timestamp = strtotime($fecha);

    if ($fecha === '' || $timestamp === false) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function normalizarHoraBDAgenda($hora) {
    $hora = trim((string)$hora);

    if ($hora === '') {
        return '';
    }

    $partes = preg_split('/\s*-\s*/', $hora);
    $hora = trim((string)$partes[0]);

    if (preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
        $hora .= ':00';
    }

    $timestamp = strtotime($hora);

    if ($timestamp === false) {
        return '';
    }

    return date('H:i:s', $timestamp);
}

function usuarioEsCapitanRetaTempAgenda($conn, $idReta, $idRetador) {
    $idReta = trim((string)$idReta);
    $idRetador = trim((string)$idRetador);

    if ($idReta === '' || $idRetador === '' || !tablaExisteAgenda($conn, 'r_equipotemp')) {
        return false;
    }

    $stmt = $conn->prepare("SELECT 1 FROM r_equipotemp WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) AND CAST(capitan AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $idReta, $idRetador);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();

    return $ok;
}


function obtenerEquipoCapitanRetaAgenda($conn, $idReta, $idRetador) {
    $idReta = trim((string)$idReta);
    $idRetador = trim((string)$idRetador);

    if ($idReta === '' || $idRetador === '' || !tablaExisteAgenda($conn, 'r_equipotemp')) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id_equipo FROM r_equipotemp WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) AND CAST(capitan AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('ss', $idReta, $idRetador);
    $stmt->execute();
    $res = $stmt->get_result();
    $idEquipo = 0;

    if ($fila = $res->fetch_assoc()) {
        $idEquipo = (int)$fila['id_equipo'];
    }

    $stmt->close();
    return $idEquipo;
}

function obtenerRutaSalaEsperaAgenda() {
    if (file_exists(__DIR__ . '/Retar/SalaEspera.php')) {
        return 'Retar/SalaEspera.php';
    }

    if (file_exists(__DIR__ . '/SalaEspera.php')) {
        return 'SalaEspera.php';
    }

    return 'Retar/SalaEspera.php';
}

function eliminarAgendaGeneralReta($conn, $idReta) {
    if (!tablaExisteAgenda($conn, 'Agenda')) {
        return true;
    }

    $stmt = $conn->prepare("DELETE FROM Agenda WHERE CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $idReta, $idReta);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function actualizarAgendaEstadoCancelada($conn, $idReta) {
    if (!tablaExisteAgenda($conn, 'Agenda')) {
        return true;
    }

    $estado = 'CANCELADO';
    $stmt = $conn->prepare("UPDATE Agenda SET estado = ? WHERE CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sss', $estado, $idReta, $idReta);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function cancelarRetaProgramadaAgenda($conn, $idReta, $idRetador) {
    if (!asegurarCamposModificacionRetaProgramadaAgenda($conn)) {
        return false;
    }

    $idEquipoCancelar = obtenerEquipoCapitanRetaAgenda($conn, $idReta, $idRetador);

    if ($idEquipoCancelar <= 0) {
        return false;
    }

    $estado = 'CANCELADO';
    $inicioTransaccion = false;

    if (method_exists($conn, 'begin_transaction')) {
        $conn->begin_transaction();
        $inicioTransaccion = true;
    }

    $stmt = $conn->prepare("UPDATE R_retasprogramadas SET estado_programada = ?, cancelado_por = ?, cancelado_equipo = ?, cancelado_en = CURRENT_TIMESTAMP WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        if ($inicioTransaccion) { $conn->rollback(); }
        return false;
    }

    $stmt->bind_param('ssis', $estado, $idRetador, $idEquipoCancelar, $idReta);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $retaActual = obtenerRetaProgramadaAgenda($conn, $idReta);
        if ($retaActual) {
            if (isset($retaActual['id_equipo1']) && (int)$retaActual['id_equipo1'] === (int)$idEquipoCancelar) {
                $equipoDisponible = 'Equipo disponible';
                $capitanVacio = '';
                $totalCero = 0;
                $jugadoresVacio = null;
                $stmtEquipo = $conn->prepare("UPDATE R_retasprogramadas SET nombre_equipo1 = ?, capitan_equipo1 = ?, total_equipo1 = ?, jugadores_equipo1 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
                if ($stmtEquipo) {
                    $stmtEquipo->bind_param('ssiss', $equipoDisponible, $capitanVacio, $totalCero, $jugadoresVacio, $idReta);
                    $stmtEquipo->execute();
                    $stmtEquipo->close();
                }
            } elseif (isset($retaActual['id_equipo2']) && (int)$retaActual['id_equipo2'] === (int)$idEquipoCancelar) {
                $equipoDisponible = 'Equipo disponible';
                $capitanVacio = '';
                $totalCero = 0;
                $jugadoresVacio = null;
                $stmtEquipo = $conn->prepare("UPDATE R_retasprogramadas SET nombre_equipo2 = ?, capitan_equipo2 = ?, total_equipo2 = ?, jugadores_equipo2 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
                if ($stmtEquipo) {
                    $stmtEquipo->bind_param('ssiss', $equipoDisponible, $capitanVacio, $totalCero, $jugadoresVacio, $idReta);
                    $stmtEquipo->execute();
                    $stmtEquipo->close();
                }
            }
        }
    }

    if ($ok && tablaExisteAgenda($conn, 'r_equipotemp')) {
        $stmtEliminar = $conn->prepare("DELETE FROM r_equipotemp WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) AND id_equipo = ?");
        if ($stmtEliminar) {
            $stmtEliminar->bind_param('si', $idReta, $idEquipoCancelar);
            $ok = $stmtEliminar->execute();
            $stmtEliminar->close();
        } else {
            $ok = false;
        }
    }

    if ($ok && tablaExisteAgenda($conn, 'r_equipotemp')) {
        $stmtPendiente = $conn->prepare("UPDATE r_equipotemp SET estado = 'Pendiente' WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) AND id_equipo <> ?");
        if ($stmtPendiente) {
            $stmtPendiente->bind_param('si', $idReta, $idEquipoCancelar);
            $stmtPendiente->execute();
            $stmtPendiente->close();
        }
    }

    if ($ok) {
        $ok = actualizarAgendaEstadoCancelada($conn, $idReta);
    }

    if ($inicioTransaccion) {
        if ($ok) {
            $conn->commit();
        } else {
            $conn->rollback();
        }
    }

    return $ok;
}

function reabrirRetaCanceladaEnSalaAgenda($conn, $idReta, $idRetador) {
    if (!asegurarCamposModificacionRetaProgramadaAgenda($conn) || !tablaExisteAgenda($conn, 'r_retaspublicadas')) {
        return false;
    }

    $reta = obtenerRetaProgramadaAgenda($conn, $idReta);

    if (!$reta || normalizarTextoAgenda($reta['estado_programada'] ?? '') !== 'cancelado') {
        return false;
    }

    $canceladoPor = trim((string)($reta['cancelado_por'] ?? ''));
    $canceladoEquipo = isset($reta['cancelado_equipo']) ? (int)$reta['cancelado_equipo'] : 0;
    $idEquipoActual = obtenerEquipoCapitanRetaAgenda($conn, $idReta, $idRetador);

    if ($idEquipoActual <= 0) {
        return false;
    }

    if ($canceladoPor !== '' && (string)$canceladoPor === (string)$idRetador) {
        return false;
    }

    if ($canceladoEquipo > 0 && $idEquipoActual === $canceladoEquipo) {
        return false;
    }

    $idRetaPublicada = (int)$idReta;

    if ($idRetaPublicada <= 0) {
        return false;
    }

    $idEquipo1 = isset($reta['id_equipo1']) ? (int)$reta['id_equipo1'] : 0;
    $idEquipo2 = isset($reta['id_equipo2']) ? (int)$reta['id_equipo2'] : 0;
    $equipo1 = trim((string)($reta['nombre_equipo1'] ?? 'Equipo 1'));
    $equipo2 = trim((string)($reta['nombre_equipo2'] ?? 'Equipo 2'));
    $capitan1 = trim((string)($reta['capitan_equipo1'] ?? ''));
    $capitan2 = trim((string)($reta['capitan_equipo2'] ?? ''));

    if ($canceladoEquipo === $idEquipo1 || ($canceladoEquipo <= 0 && $idEquipoActual === $idEquipo2)) {
        $equipo1 = 'Equipo disponible';
        $capitan1 = '';
    }

    if ($canceladoEquipo === $idEquipo2 || ($canceladoEquipo <= 0 && $idEquipoActual === $idEquipo1)) {
        $equipo2 = 'Equipo disponible';
        $capitan2 = '';
    }

    if ($equipo1 === '') { $equipo1 = 'Equipo 1'; }
    if ($equipo2 === '') { $equipo2 = 'Equipo 2'; }

    $idCreador = trim((string)$idRetador);
    $codigoPostal = trim((string)($reta['codigo_postal'] ?? ''));
    $cancha = trim((string)($reta['cancha'] ?? 'Cancha por definir'));
    $deporte = trim((string)($reta['deporte'] ?? 'Reta'));
    $direccion = trim((string)($reta['direccion'] ?? ''));
    $fecha = normalizarFechaBDAgenda($reta['fecha_programada'] ?? ($reta['fecha_reta'] ?? ''));
    $hora = normalizarHoraBDAgenda($reta['hora_programada'] ?? ($reta['hora_reta'] ?? ''));
    $estadoReta = 'Publicada';
    $descripcion = trim((string)($reta['descripcion'] ?? ''));
    $resulEquipo1 = 0;
    $resulEquipo2 = 0;
    $expulsiones = '';

    if ($fecha === '') { $fecha = date('Y-m-d'); }
    if ($hora === '') { $hora = date('H:i:s'); }
    if ($cancha === '') { $cancha = 'Cancha por definir'; }
    if ($deporte === '') { $deporte = 'Reta'; }

    $inicioTransaccion = false;

    if (method_exists($conn, 'begin_transaction')) {
        $conn->begin_transaction();
        $inicioTransaccion = true;
    }

    $sql = "INSERT INTO r_retaspublicadas
        (id_reta, id_creador, equipo1, equipo2, capitan_equipo1, capitan_equipo2, codigo_postal, cancha, deporte, direccion, hora, fecha, estado_reta, descripcion, resul_equipo1, resul_equipo2, expulsiones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        id_creador = VALUES(id_creador),
        equipo1 = VALUES(equipo1),
        equipo2 = VALUES(equipo2),
        capitan_equipo1 = VALUES(capitan_equipo1),
        capitan_equipo2 = VALUES(capitan_equipo2),
        codigo_postal = VALUES(codigo_postal),
        cancha = VALUES(cancha),
        deporte = VALUES(deporte),
        direccion = VALUES(direccion),
        hora = VALUES(hora),
        fecha = VALUES(fecha),
        estado_reta = VALUES(estado_reta),
        descripcion = VALUES(descripcion),
        resul_equipo1 = VALUES(resul_equipo1),
        resul_equipo2 = VALUES(resul_equipo2),
        expulsiones = VALUES(expulsiones)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        if ($inicioTransaccion) { $conn->rollback(); }
        return false;
    }

    $tipos = 'i' . str_repeat('s', 13) . 'iis';
    $stmt->bind_param(
        $tipos,
        $idRetaPublicada,
        $idCreador,
        $equipo1,
        $equipo2,
        $capitan1,
        $capitan2,
        $codigoPostal,
        $cancha,
        $deporte,
        $direccion,
        $hora,
        $fecha,
        $estadoReta,
        $descripcion,
        $resulEquipo1,
        $resulEquipo2,
        $expulsiones
    );

    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $stmtDelete = $conn->prepare("DELETE FROM R_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmtDelete) {
            $stmtDelete->bind_param('s', $idReta);
            $ok = $stmtDelete->execute();
            $stmtDelete->close();
        } else {
            $ok = false;
        }
    }

    if ($ok) {
        eliminarAgendaGeneralReta($conn, $idReta);
    }

    if ($inicioTransaccion) {
        if ($ok) {
            $conn->commit();
        } else {
            $conn->rollback();
        }
    }

    return $ok;
}

function obtenerRetaProgramadaAgenda($conn, $idReta) {
    if (!tablaExisteAgenda($conn, 'R_retasprogramadas')) {
        return null;
    }

    asegurarCamposModificacionRetaProgramadaAgenda($conn);

    $stmt = $conn->prepare("SELECT * FROM R_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $idReta = trim((string)$idReta);
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $fila ? $fila : null;
}


function estadoFinalizadoAgenda($estado) {
    $estadoNormalizado = normalizarTextoAgenda($estado);
    return $estadoNormalizado === 'finalizada' || $estadoNormalizado === 'finalizado';
}

function obtenerResultadoFinalRetaAgenda($conn, $idReta) {
    if (!tablaExisteAgenda($conn, 'r_resultadosretas')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM r_resultadosretas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) ORDER BY id_resultado DESC LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $idReta = trim((string)$idReta);
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $fila ? $fila : null;
}

function textoEstadoResultadoFinalAgenda($resultadoFinal) {
    if (is_array($resultadoFinal) && isset($resultadoFinal['estado']) && trim((string)$resultadoFinal['estado']) !== '') {
        return trim((string)$resultadoFinal['estado']);
    }

    return 'Confirmado';
}

function actualizarAgendaTablaGeneral($conn, $idReta, $fecha, $hora, $cancha, $direccion, $estado) {
    if (!tablaExisteAgenda($conn, 'Agenda')) {
        return true;
    }

    $stmt = $conn->prepare("UPDATE Agenda SET fecha = ?, hora = ?, cancha = ?, direccion = ?, estado = ? WHERE CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sssssss', $fecha, $hora, $cancha, $direccion, $estado, $idReta, $idReta);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function guardarSolicitudModificacionRetaAgenda($conn, $idReta, $idRetador, $fecha, $hora, $cancha, $direccion) {
    if (!asegurarCamposModificacionRetaProgramadaAgenda($conn)) {
        return false;
    }

    $fechaBD = normalizarFechaBDAgenda($fecha);
    $horaBD = normalizarHoraBDAgenda($hora);
    $cancha = trim((string)$cancha);
    $direccion = trim((string)$direccion);

    if ($fechaBD === '' || $horaBD === '' || $cancha === '' || $direccion === '') {
        return false;
    }

    $idEquipoModificacion = obtenerEquipoCapitanRetaAgenda($conn, $idReta, $idRetador);

    if ($idEquipoModificacion <= 0) {
        return false;
    }

    $estado = 'En Modificacion';
    $stmt = $conn->prepare("UPDATE R_retasprogramadas SET fecha_reta = ?, hora_reta = ?, fecha_programada = ?, hora_programada = ?, cancha = ?, direccion = ?, estado_programada = ?, modificacion_por = ?, modificacion_equipo = ?, modificacion_en = CURRENT_TIMESTAMP WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssssssssis', $fechaBD, $horaBD, $fechaBD, $horaBD, $cancha, $direccion, $estado, $idRetador, $idEquipoModificacion, $idReta);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        actualizarAgendaTablaGeneral($conn, $idReta, $fechaBD, $horaBD, $cancha, $direccion, $estado);
    }

    return $ok;
}

function aceptarModificacionRetaAgenda($conn, $idReta) {
    if (!asegurarCamposModificacionRetaProgramadaAgenda($conn)) {
        return false;
    }

    $estado = 'Programada';
    $stmt = $conn->prepare("UPDATE R_retasprogramadas SET estado_programada = ?, modificacion_por = NULL, modificacion_equipo = NULL, modificacion_en = NULL WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $estado, $idReta);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && tablaExisteAgenda($conn, 'Agenda')) {
        $stmtAgenda = $conn->prepare("UPDATE Agenda SET estado = ? WHERE CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)");
        if ($stmtAgenda) {
            $stmtAgenda->bind_param('sss', $estado, $idReta, $idReta);
            $stmtAgenda->execute();
            $stmtAgenda->close();
        }
    }

    return $ok;
}

function estadoPermiteResultadoAgenda($estado) {
    return normalizarTextoAgenda($estado) === 'programada';
}

function resultadoHabilitadoAgenda($fecha, $hora, $estado) {
    return estadoPermiteResultadoAgenda($estado) && resultadoDisponibleAgenda($fecha, $hora);
}

function textoDisponibleResultadoAgendaEstado($fecha, $hora, $estado) {
    if (!estadoPermiteResultadoAgenda($estado)) {
        return 'No se habilita porque la reta está en estado: ' . trim((string)$estado) . '.';
    }

    return textoDisponibleResultadoAgenda($fecha, $hora);
}

$Nombre = obtenerDatoAgenda($usuarios, ['Nombre', 'nombre'], 'Usuario');
$Apellido = obtenerDatoAgenda($usuarios, ['Apellido', 'apellido'], '');
$Id_Retador = obtenerDatoAgenda($usuarios, ['Id_Retador', 'id_retador', 'Id_Usuario', 'id_usuario'], '');
$FotoPerfil = obtenerDatoAgenda($usuarios, ['FotoPerfil', 'fotoPerfil', 'foto_perfil'], '');
$ModoPerfil = obtenerDatoAgenda($usuarios, ['ModoPerfil', 'modoPerfil'], '');

if ($Id_Retador === '') {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';
    $stmtModo = $conn->prepare("UPDATE retador SET ModoPerfil = ? WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmtModo) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo preparar la actualización del modo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtModo->bind_param('ss', $nuevoModoPerfil, $Id_Retador);
    $okModo = $stmtModo->execute();
    $stmtModo->close();

    if ($okModo) {
        $_SESSION['usuario_data']['ModoPerfil'] = $nuevoModoPerfil;
        echo json_encode(['ok' => true, 'modo' => $nuevoModoPerfil], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo actualizar el modo.'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (isset($conn)) {
    $stmtPerfil = $conn->prepare("SELECT ModoPerfil, FotoPerfil FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if ($stmtPerfil) {
        $stmtPerfil->bind_param('s', $Id_Retador);
        $stmtPerfil->execute();
        $resPerfil = $stmtPerfil->get_result();
        if ($filaPerfil = $resPerfil->fetch_assoc()) {
            if (isset($filaPerfil['ModoPerfil'])) {
                $ModoPerfil = trim((string)$filaPerfil['ModoPerfil']);
                $_SESSION['usuario_data']['ModoPerfil'] = $ModoPerfil;
            }
            if (isset($filaPerfil['FotoPerfil']) && trim((string)$filaPerfil['FotoPerfil']) !== '') {
                $FotoPerfil = trim((string)$filaPerfil['FotoPerfil']);
                $_SESSION['usuario_data']['FotoPerfil'] = $FotoPerfil;
            }
        }
        $stmtPerfil->close();
    }
}

$FotoPerfilUsuario = resolverRutaFotoAgenda($FotoPerfil);
$iniciales = mb_strtoupper(mb_substr($Nombre, 0, 1, 'UTF-8') . mb_substr($Apellido, 0, 1, 'UTF-8'), 'UTF-8');
if (trim($iniciales) === '') {
    $iniciales = 'U';
}
$modoOscuroActivo = normalizarTextoAgenda($ModoPerfil) === 'modo oscuro';
$mensajeAgenda = '';
$erroresAgenda = [];

if (isset($conn) && tablaExisteAgenda($conn, 'R_retasprogramadas')) {
    asegurarCamposModificacionRetaProgramadaAgenda($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'posponer_reta') {
    $idRetaPost = isset($_POST['id_reta']) ? trim((string)$_POST['id_reta']) : '';
    $fechaPost = isset($_POST['fecha_reta']) ? trim((string)$_POST['fecha_reta']) : '';
    $horaPost = isset($_POST['hora_reta']) ? trim((string)$_POST['hora_reta']) : '';
    $canchaPost = isset($_POST['cancha']) ? trim((string)$_POST['cancha']) : '';
    $direccionPost = isset($_POST['direccion']) ? trim((string)$_POST['direccion']) : '';

    if ($idRetaPost === '') {
        $erroresAgenda[] = 'No se recibió la ID de la reta a modificar.';
    } elseif (!usuarioEsCapitanRetaTempAgenda($conn, $idRetaPost, $Id_Retador)) {
        $erroresAgenda[] = 'Solo el capitán registrado en la sala puede posponer o cambiar el lugar de esta reta.';
    } elseif (normalizarFechaBDAgenda($fechaPost) === '' || normalizarHoraBDAgenda($horaPost) === '' || $canchaPost === '' || $direccionPost === '') {
        $erroresAgenda[] = 'Completa fecha, horario, cancha y dirección correctamente.';
    } elseif (guardarSolicitudModificacionRetaAgenda($conn, $idRetaPost, $Id_Retador, $fechaPost, $horaPost, $canchaPost, $direccionPost)) {
        $mensajeAgenda = 'La modificación fue guardada. Ahora la reta quedó en estado En Modificacion y pendiente de aceptación del otro capitán.';
    } else {
        $erroresAgenda[] = 'No se pudo guardar la modificación de la reta.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'aceptar_modificacion_reta') {
    $idRetaPost = isset($_POST['id_reta']) ? trim((string)$_POST['id_reta']) : '';
    $retaAceptar = $idRetaPost !== '' ? obtenerRetaProgramadaAgenda($conn, $idRetaPost) : null;
    $modificacionPor = $retaAceptar ? trim((string)($retaAceptar['modificacion_por'] ?? '')) : '';
    $modificacionEquipo = $retaAceptar && isset($retaAceptar['modificacion_equipo']) ? (int)$retaAceptar['modificacion_equipo'] : 0;
    $idEquipoAceptador = $idRetaPost !== '' ? obtenerEquipoCapitanRetaAgenda($conn, $idRetaPost, $Id_Retador) : 0;

    if ($idRetaPost === '') {
        $erroresAgenda[] = 'No se recibió la ID de la reta a aceptar.';
    } elseif (!usuarioEsCapitanRetaTempAgenda($conn, $idRetaPost, $Id_Retador)) {
        $erroresAgenda[] = 'Solo el capitán registrado en la sala puede aceptar la modificación.';
    } elseif (!$retaAceptar || normalizarTextoAgenda($retaAceptar['estado_programada'] ?? '') !== 'en modificacion') {
        $erroresAgenda[] = 'Esta reta no tiene una modificación pendiente.';
    } elseif ($modificacionEquipo > 0 && $idEquipoAceptador > 0 && $modificacionEquipo === $idEquipoAceptador) {
        $erroresAgenda[] = 'La modificación debe aceptarla el capitán del otro equipo.';
    } elseif ($modificacionEquipo <= 0 && $modificacionPor !== '' && (string)$modificacionPor === (string)$Id_Retador) {
        $erroresAgenda[] = 'La modificación debe aceptarla el otro capitán.';
    } elseif (aceptarModificacionRetaAgenda($conn, $idRetaPost)) {
        $mensajeAgenda = 'La modificación fue aceptada. La reta volvió a estar Programada.';
    } else {
        $erroresAgenda[] = 'No se pudo aceptar la modificación.';
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cancelar_reta') {
    $idRetaPost = isset($_POST['id_reta']) ? trim((string)$_POST['id_reta']) : '';
    $retaCancelar = $idRetaPost !== '' ? obtenerRetaProgramadaAgenda($conn, $idRetaPost) : null;
    $estadoCancelar = $retaCancelar ? normalizarTextoAgenda($retaCancelar['estado_programada'] ?? '') : '';

    if ($idRetaPost === '') {
        $erroresAgenda[] = 'No se recibió la ID de la reta a cancelar.';
    } elseif (!$retaCancelar) {
        $erroresAgenda[] = 'No se encontró la reta programada.';
    } elseif (!usuarioEsCapitanRetaTempAgenda($conn, $idRetaPost, $Id_Retador)) {
        $erroresAgenda[] = 'Solo el capitán registrado en la sala puede cancelar esta reta.';
    } elseif ($estadoCancelar === 'finalizada') {
        $erroresAgenda[] = 'No se puede cancelar una reta finalizada.';
    } elseif ($estadoCancelar === 'cancelado') {
        $erroresAgenda[] = 'Esta reta ya está cancelada.';
    } elseif (cancelarRetaProgramadaAgenda($conn, $idRetaPost, $Id_Retador)) {
        $mensajeAgenda = 'La reta fue cancelada. Tu equipo fue eliminado de la sala de espera.';
    } else {
        $erroresAgenda[] = 'No se pudo cancelar la reta.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'ir_sala_cancelada') {
    $idRetaPost = isset($_POST['id_reta']) ? trim((string)$_POST['id_reta']) : '';

    if ($idRetaPost === '') {
        $erroresAgenda[] = 'No se recibió la ID de la reta para volver a la sala.';
    } elseif (reabrirRetaCanceladaEnSalaAgenda($conn, $idRetaPost, $Id_Retador)) {
        $rutaSala = obtenerRutaSalaEsperaAgenda();
        header('Location: ' . $rutaSala . '?id_reta=' . urlencode((string)$idRetaPost));
        exit();
    } else {
        $erroresAgenda[] = 'No se pudo regresar la reta a sala de espera.';
    }
}

$retasAgendadas = isset($conn) ? obtenerRetasAgendadas($conn, $Id_Retador) : [];
$totalRetas = count($retasAgendadas);
$proximaReta = $totalRetas > 0 ? $retasAgendadas[0] : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda - RETAME</title>
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
    --fondo:#f0f2f5;
    --sidebar:280px;
    --sombra-azul:0 0 0 3px rgba(24,119,242,0.24),0 12px 28px rgba(24,119,242,0.16);
    --sombra-roja:0 0 0 3px rgba(255,75,92,0.26),0 12px 28px rgba(255,75,92,0.16);
}

*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;min-height:100%}
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

.bg-particles::before,
.bg-particles::after{
    content:"";
    position:absolute;
    border-radius:999px;
    pointer-events:none;
}

.bg-particles::before{
    width:6px;
    height:6px;
    left:24%;
    top:34%;
    background:rgba(143,239,255,0.26);
    box-shadow:
        280px 110px 0 rgba(255,75,92,0.13),
        510px 380px 0 rgba(143,239,255,0.16),
        120px 520px 0 rgba(255,255,255,0.20);
    filter:blur(1px);
}

.bg-particles::after{
    width:420px;
    height:420px;
    right:12%;
    top:10%;
    background:radial-gradient(circle,rgba(255,75,92,0.10),transparent 70%);
    filter:blur(8px);
}

.sidebar{
    width:var(--sidebar);
    height:100dvh;
    position:fixed;
    left:0;
    top:0;
    z-index:1000;
    padding:22px 14px 112px;
    background:rgba(255,255,255,0.98);
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

body.sidebar-hidden .sidebar{transform:translateX(-105%)}
.logo-area{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.doctor-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff;box-shadow:0 8px 18px rgba(24,119,242,0.14),0 0 0 2px rgba(24,119,242,0.12)}
.logo-text h2{font-family:'Orbitron',sans-serif;font-size:19px;color:var(--azul);line-height:1}
.logo-text p{font-size:12px;color:var(--gris);margin-top:5px}
.sidebar-boceto{width:100%;display:flex;flex-direction:column;gap:16px}

.perfil-sidebar-card{
    width:100%;
    min-height:78px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px;
    text-decoration:none;
    border-radius:22px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(255,75,92,0.62);
    box-shadow:0 8px 18px rgba(0,0,0,0.07),0 0 0 2px rgba(0,153,255,0.22),0 0 14px rgba(0,153,255,0.15);
    transition:0.25s ease;
}
.perfil-sidebar-card:hover{transform:translateY(-2px);border-color:rgba(255,75,92,0.96)}
.perfil-sidebar-foto{
    width:52px;
    height:52px;
    min-width:52px;
    border-radius:18px;
    padding:3px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.76);
    box-shadow:0 6px 14px rgba(0,0,0,0.09),0 0 0 2px rgba(255,75,92,0.18);
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#1877f2;
    font-weight:1000;
}
.perfil-sidebar-foto img{width:100%;height:100%;object-fit:cover;border-radius:14px;display:block}
.perfil-sidebar-info{min-width:0;display:flex;flex-direction:column;gap:2px;line-height:1.15}
.perfil-sidebar-info span{font-size:11px;font-weight:900;color:var(--rojo2)}
.perfil-sidebar-info strong{max-width:140px;font-size:14px;font-weight:900;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.acciones-grid{width:100%;display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.accion-boceto{
    min-height:95px;
    text-decoration:none;
    border-radius:20px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(255,75,92,0.62);
    box-shadow:0 8px 18px rgba(0,0,0,0.07),0 0 0 2px rgba(0,153,255,0.22),0 0 14px rgba(0,153,255,0.15);
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
.accion-boceto img{width:38px;height:38px;object-fit:contain;display:block;background:transparent;border:none;border-radius:0;padding:0;box-shadow:none}
.accion-boceto:hover,.accion-boceto.active{transform:translateY(-3px);color:var(--rojo2);border-color:rgba(255,75,92,0.96);box-shadow:0 12px 22px rgba(0,0,0,0.10),0 0 0 3px rgba(0,153,255,0.26),0 0 18px rgba(0,153,255,0.20)}
.cerrar-boceto{width:min(180px,100%);min-height:52px;margin:0 auto;text-decoration:none;border-radius:18px;background:linear-gradient(180deg,#fff7f8,#ffffff);border:2px solid rgba(255,75,92,0.82);box-shadow:0 8px 18px rgba(0,0,0,0.07),0 0 0 2px rgba(0,153,255,0.24),0 0 14px rgba(0,153,255,0.14);display:flex;align-items:center;justify-content:center;gap:9px;color:var(--rojo2);font-weight:900;font-size:12px;transition:0.25s ease}
.cerrar-boceto img{width:26px;height:26px;object-fit:contain;background:transparent;border:none;border-radius:0;padding:0;box-shadow:none}
.info-boceto{width:100%;display:flex;flex-direction:column;gap:9px;margin-top:4px}
.info-boceto a{min-height:46px;text-decoration:none;border-radius:16px;background:#ffffff;border:2px solid rgba(0,153,255,0.56);box-shadow:0 6px 14px rgba(0,0,0,0.05),0 0 12px rgba(0,153,255,0.12);display:flex;align-items:center;padding:0 16px;color:#374151;font-weight:900;font-size:13px;transition:0.25s ease}
.info-boceto a:hover{color:var(--azul);transform:translateX(4px);border-color:rgba(0,153,255,0.96);box-shadow:0 8px 16px rgba(0,0,0,0.06),0 0 18px rgba(0,153,255,0.18)}

.modo-oscuro-panel{width:100%;min-height:58px;margin-top:4px;border-radius:18px;background:#ffffff;border:2px solid rgba(0,153,255,0.60);box-shadow:0 8px 18px rgba(0,0,0,0.06),0 0 14px rgba(0,153,255,0.14);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px}
.modo-oscuro-texto{display:flex;align-items:center;gap:8px;color:#374151;font-size:13px;font-weight:900}
.modo-oscuro-texto span{font-size:18px}
.switch-modo{width:54px;height:30px;border:none;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 2px 5px rgba(0,0,0,0.16),0 0 0 2px rgba(255,75,92,0.28),0 0 0 4px rgba(0,153,255,0.12);position:relative;cursor:pointer;transition:0.25s ease;flex:0 0 auto}
.switch-modo span{position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#ffffff;display:block;box-shadow:0 3px 8px rgba(0,0,0,0.25);transition:0.25s ease}
.switch-modo:disabled{opacity:0.65;cursor:not-allowed}

.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:74px;z-index:900;display:flex;align-items:center;gap:16px;padding:12px 24px;background:rgba(255,255,255,0.94);backdrop-filter:blur(14px);border-bottom:3px solid var(--azul-neon-fuerte);box-shadow:0 4px 18px rgba(0,0,0,0.07),0 0 0 1px rgba(24,119,242,0.14),0 0 16px rgba(0,153,255,0.24);transition:left 0.3s ease,height 0.25s ease,padding 0.25s ease,box-shadow 0.25s ease}
body.sidebar-hidden .topbar{left:0}
body.topbar-compact .topbar{height:58px;padding:7px 22px;box-shadow:0 3px 14px rgba(0,0,0,0.08),0 0 0 1px rgba(24,119,242,0.16),0 0 18px rgba(0,153,255,0.24)}
.menu-toggle{width:50px;height:50px;border:3px solid rgba(0,153,255,0.50);border-radius:17px;background:#ffffff;color:#111827;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(0,0,0,0.10),0 0 0 2px rgba(24,119,242,0.10),0 0 16px rgba(0,153,255,0.24),0 0 28px rgba(0,153,255,0.12);transition:0.25s ease}
body.topbar-compact .menu-toggle{width:42px;height:42px;border-radius:14px}
.menu-toggle:hover{transform:translateY(-2px);color:var(--azul)}
.menu-toggle span{width:25px;height:3px;background:currentColor;position:relative;border-radius:999px;display:block;box-shadow:0 0 8px rgba(255,255,255,0.35)}
.menu-toggle span::before,.menu-toggle span::after{content:"";position:absolute;left:0;width:25px;height:3px;background:currentColor;border-radius:999px;display:block;box-shadow:0 0 8px rgba(255,255,255,0.35)}
.menu-toggle span::before{top:-8px}.menu-toggle span::after{top:8px}
.topbar-title{flex:1;min-width:0}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.25rem,3vw,2rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.topbar-compact .topbar-title h1{font-size:clamp(1rem,2.4vw,1.45rem)}
.topbar-user{max-width:330px;display:flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;background:#ffffff;box-shadow:0 6px 15px rgba(0,0,0,0.08);font-weight:800;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.topbar-compact .topbar-user{padding:7px 14px}

.main-content{position:relative;z-index:2;min-height:100dvh;margin-left:var(--sidebar);padding:104px clamp(16px,4vw,42px) 122px;transition:margin-left 0.3s ease}
body.sidebar-hidden .main-content{margin-left:0}
.agenda-wrapper{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:22px}
.agenda-hero,.agenda-empty,.agenda-card{
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    box-shadow:0 16px 34px rgba(0,0,0,0.10),0 0 0 1px rgba(24,119,242,0.12),0 0 18px rgba(0,153,255,0.14);
    border:1px solid rgba(17,24,39,0.05);
    overflow:hidden;
    position:relative;
}
.agenda-hero{padding:clamp(24px,4vw,42px)}
.agenda-hero::before,.agenda-card::before,.agenda-empty::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 14% 18%,rgba(24,119,242,0.26),transparent 270px),radial-gradient(circle at 86% 82%,rgba(255,75,92,0.25),transparent 320px),linear-gradient(90deg,rgba(24,119,242,0.14),rgba(255,255,255,0.02) 46%,rgba(255,75,92,0.15));pointer-events:none}
.agenda-hero>* ,.agenda-card>* ,.agenda-empty>*{position:relative;z-index:1}
.agenda-chip{display:inline-flex;align-items:center;justify-content:center;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;color:var(--rojo2);background:#fff;border:2px solid rgba(255,75,92,0.35);box-shadow:0 0 0 2px rgba(0,153,255,0.14);margin-bottom:12px}
.agenda-hero h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.8rem,5vw,2.7rem);margin-bottom:10px}
.agenda-hero p{max-width:850px;color:#4b5563;font-size:clamp(0.96rem,2.4vw,1.08rem);line-height:1.7}
.resumen-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:22px}
.dato-agenda{min-height:76px;border-radius:18px;background:rgba(255,255,255,0.96);border:2px solid rgba(0,153,255,0.26);box-shadow:0 12px 24px rgba(0,0,0,0.08),0 0 0 2px rgba(255,75,92,0.09);padding:13px 14px;display:flex;flex-direction:column;justify-content:center}
.dato-agenda span{display:block;color:#6b7280;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.35px;margin-bottom:6px}.dato-agenda strong{color:#111827;font-size:14px;font-weight:1000}
.agenda-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:16px}
.agenda-card{padding:16px;display:flex;flex-direction:column;gap:12px;transition:.25s ease}.agenda-card:hover{transform:translateY(-4px);box-shadow:0 20px 38px rgba(0,0,0,0.15),0 0 0 2px rgba(0,153,255,0.14)}
.agenda-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.agenda-card h3{font-family:'Orbitron',sans-serif;font-size:1.05rem;color:var(--azul);line-height:1.25}.estado-pill{display:inline-flex;align-items:center;justify-content:center;padding:7px 10px;border-radius:999px;background:rgba(34,197,94,.12);color:#15803d;font-size:11px;font-weight:1000;white-space:nowrap}
.agenda-info{display:flex;flex-direction:column;gap:7px}.agenda-info p{font-size:12px;line-height:1.4;color:#374151;font-weight:800}.agenda-info p strong{color:#111827}
.agenda-equipos{border-radius:18px;background:rgba(24,119,242,.06);border:1px solid rgba(24,119,242,.15);padding:11px;display:flex;flex-direction:column;gap:7px}.agenda-equipo-linea{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;font-weight:900;color:#374151}.agenda-equipo-linea span:last-child{color:var(--rojo2)}
.mi-rol{display:inline-flex;align-items:center;justify-content:center;width:max-content;max-width:100%;min-height:32px;padding:8px 11px;border-radius:999px;background:rgba(255,75,92,.12);color:#b91c1c;font-size:12px;font-weight:1000}
.agenda-empty{min-height:180px;padding:28px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px}.agenda-empty h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:1.4rem}.agenda-empty p{color:#4b5563;font-weight:800;line-height:1.55}
.btn-volver-retar{min-height:46px;border:0;text-decoration:none;border-radius:17px;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;font-size:13px;font-weight:900;cursor:pointer;transition:.25s ease;background:linear-gradient(135deg,var(--azul),var(--azul2));color:#fff;box-shadow:0 10px 18px rgba(24,119,242,.18)}
.btn-volver-retar:hover{transform:translateY(-2px)}
.acciones-resultado{display:flex;flex-direction:column;gap:7px;margin-top:2px}
.btn-registrar-resultado{min-height:44px;border:0;text-decoration:none;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;font-size:12px;font-weight:1000;cursor:pointer;transition:.25s ease;background:linear-gradient(135deg,var(--rojo),var(--rojo2));color:#fff;box-shadow:0 10px 18px rgba(255,75,92,.20);font-family:'Poppins',sans-serif}
.btn-registrar-resultado:hover{transform:translateY(-2px)}
.btn-registrar-resultado.disabled{background:#d1d5db;color:#6b7280;box-shadow:none;cursor:not-allowed;pointer-events:none}
.resultado-ayuda{font-size:11px;font-weight:900;color:#6b7280;line-height:1.35}
body.dark-mode .btn-registrar-resultado.disabled{background:#1f2937;color:#9ca3af;border:1px solid rgba(255,255,255,.08)}
body.dark-mode .resultado-ayuda{color:#d1d5db}

.bottom-nav{position:fixed;left:var(--sidebar);right:0;bottom:0;width:auto;height:88px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);gap:0;padding:8px 18px;border-radius:22px 22px 0 0;background:#ffffff;border-top:2px solid rgba(0,153,255,0.72);box-shadow:0 -6px 18px rgba(0,0,0,0.06),0 0 16px rgba(0,153,255,0.14);backdrop-filter:blur(16px);transition:left 0.3s ease,opacity 0.25s ease,transform 0.25s ease}
body.sidebar-hidden .bottom-nav{left:0}body.bottom-nav-hidden .bottom-nav{opacity:0;transform:translateY(115%);pointer-events:none}
.bottom-nav a{position:relative;min-width:0;text-decoration:none;display:flex;align-items:center;justify-content:center;background:transparent;border:none;box-shadow:none;outline:none;transition:0.25s ease;overflow:visible;border-radius:16px}
.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain;display:block;transition:0.25s ease;filter:none;opacity:.94;padding:3px;border-radius:14px;background:transparent;box-shadow:none}
.bottom-nav a::after{content:"";position:absolute;width:54px;height:54px;left:50%;top:50%;transform:translate(-50%,-50%);border-radius:15px;opacity:0;transition:0.25s ease;pointer-events:none}.bottom-nav a:hover img{transform:scale(1.02);opacity:1}.bottom-nav a.active::after{opacity:1;border:2px solid rgba(255,75,92,0.98);box-shadow:0 0 0 2px rgba(0,153,255,0.98),0 0 12px rgba(0,153,255,0.25),0 0 8px rgba(255,75,92,0.18);background:transparent}.bottom-nav a.active img{transform:scale(1.03);opacity:1}
.mobile-overlay{display:none}

body.dark-mode{background:#0b1220;color:#e5e7eb}
body.dark-mode .bg-particles{background:radial-gradient(circle at 18% 20%,rgba(0,153,255,0.28),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,0.24),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,0.16),transparent 360px),linear-gradient(90deg,rgba(0,153,255,0.16) 0%,rgba(10,18,32,0.34) 48%,rgba(255,75,92,0.16) 100%),linear-gradient(135deg,#070b14 0%,#0b1220 48%,#160a12 100%)}
body.dark-mode .bg-particles::before{background:rgba(143,239,255,0.20);box-shadow:280px 110px 0 rgba(255,75,92,0.10),510px 380px 0 rgba(143,239,255,0.13),120px 520px 0 rgba(255,255,255,0.08)}
body.dark-mode .bg-particles::after{background:radial-gradient(circle,rgba(255,75,92,0.12),transparent 70%)}
body.dark-mode .sidebar,body.dark-mode .topbar,body.dark-mode .bottom-nav{background:rgba(12,18,31,0.96);color:#e5e7eb}
body.dark-mode .sidebar{border-right-color:rgba(0,153,255,0.95);box-shadow:8px 0 24px rgba(0,0,0,0.28),0 0 0 2px rgba(0,153,255,0.20),0 0 24px rgba(0,153,255,0.28)}
body.dark-mode .topbar{border-bottom-color:rgba(0,153,255,0.95);box-shadow:0 5px 20px rgba(0,0,0,0.28),0 0 0 1px rgba(0,153,255,0.16),0 0 20px rgba(0,153,255,0.22)}
body.dark-mode .bottom-nav{border-top-color:rgba(0,153,255,0.95);box-shadow:0 -8px 24px rgba(0,0,0,0.28),0 0 18px rgba(0,153,255,0.20)}
body.dark-mode .doctor-logo,body.dark-mode .menu-toggle,body.dark-mode .topbar-user,body.dark-mode .perfil-sidebar-card,body.dark-mode .agenda-hero,body.dark-mode .agenda-card,body.dark-mode .agenda-empty,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-panel{background:#111827;color:#e5e7eb}
body.dark-mode .agenda-hero,body.dark-mode .agenda-card,body.dark-mode .agenda-empty,body.dark-mode .perfil-sidebar-card,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-panel{border-color:rgba(255,75,92,0.72);box-shadow:0 10px 24px rgba(0,0,0,0.28),0 0 0 2px rgba(0,153,255,0.22),0 0 18px rgba(0,153,255,0.16)}
body.dark-mode .agenda-hero::before,body.dark-mode .agenda-card::before,body.dark-mode .agenda-empty::before{background:radial-gradient(circle at 14% 18%,rgba(0,153,255,0.28),transparent 270px),radial-gradient(circle at 86% 82%,rgba(255,75,92,0.24),transparent 320px),linear-gradient(90deg,rgba(0,153,255,0.16),rgba(17,24,39,0.10) 46%,rgba(255,75,92,0.16))}
body.dark-mode .agenda-chip,body.dark-mode .dato-agenda,body.dark-mode .agenda-equipos,body.dark-mode .topbar-user,body.dark-mode .menu-toggle{background:#0b1220;color:#e5e7eb;border-color:rgba(0,153,255,0.38)}
body.dark-mode .agenda-chip{color:#ff7b87;border-color:rgba(255,75,92,0.42)}
body.dark-mode .perfil-sidebar-foto{background:#0b1220;border-color:rgba(0,153,255,0.90);color:#8fefff}
body.dark-mode .logo-text h2,body.dark-mode .topbar-title h1,body.dark-mode .agenda-hero h2,body.dark-mode .agenda-card h3,body.dark-mode .agenda-empty h3{color:#8fefff;text-shadow:0 0 12px rgba(143,239,255,0.35)}
body.dark-mode .logo-text p,body.dark-mode .agenda-hero p,body.dark-mode .agenda-empty p,body.dark-mode .dato-agenda span,body.dark-mode .dato-agenda strong,body.dark-mode .agenda-info p,body.dark-mode .agenda-info p strong,body.dark-mode .agenda-equipo-linea,body.dark-mode .perfil-sidebar-info strong,body.dark-mode .modo-oscuro-texto,body.dark-mode .topbar-user,body.dark-mode .info-boceto a,body.dark-mode .accion-boceto{color:#e5e7eb}
body.dark-mode .perfil-sidebar-info span{color:#ff7b87}
body.dark-mode .mi-rol{background:rgba(255,75,92,.18);color:#fecaca;border:1px solid rgba(255,75,92,.26)}
body.dark-mode .estado-pill{background:rgba(34,197,94,.18);color:#bbf7d0;border:1px solid rgba(34,197,94,.26)}
body.dark-mode .bottom-nav a img,body.dark-mode .accion-boceto img,body.dark-mode .cerrar-boceto img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05);background:transparent;box-shadow:none}
body.dark-mode .bottom-nav a.active::after{border:2px solid rgba(255,75,92,0.98);box-shadow:0 0 0 2px rgba(0,153,255,0.98),0 0 13px rgba(0,153,255,0.30),0 0 9px rgba(255,75,92,0.22)}
body.dark-mode .switch-modo{background:linear-gradient(135deg,#1877f2,#0ea5e9);box-shadow:inset 0 2px 5px rgba(0,0,0,0.25),0 0 0 2px rgba(255,75,92,0.44),0 0 16px rgba(0,153,255,0.26)}
body.dark-mode .switch-modo span{transform:translateX(24px);background:#ffffff}
body.dark-mode .mobile-overlay{background:rgba(0,0,0,0.48)}

@media screen and (max-width:1050px){.resumen-grid{grid-template-columns:repeat(2,1fr)}}
@media screen and (max-width:900px){.agenda-grid{grid-template-columns:1fr 1fr}}
@media screen and (max-width:820px){.sidebar{transform:translateX(-105%)}body.sidebar-open .sidebar{transform:translateX(0)}body.sidebar-hidden .sidebar{transform:translateX(-105%)}.topbar,body.sidebar-hidden .topbar{left:0;height:68px;padding:10px 14px}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;right:0;height:84px;border-radius:24px 24px 0 0;padding:8px 10px;gap:7px}body.sidebar-open .bottom-nav{opacity:0;transform:translateY(110%);pointer-events:none}.main-content,body.sidebar-hidden .main-content{margin-left:0;padding-top:94px}body.topbar-compact .topbar{height:58px;padding:7px 12px}.topbar-user{display:none}.mobile-overlay{display:block;position:fixed;inset:0;z-index:950;background:rgba(17,24,39,0.28);opacity:0;visibility:hidden;transition:0.25s ease}body.sidebar-open .mobile-overlay{opacity:1;visibility:visible}}
@media screen and (max-width:620px){body{padding-bottom:92px}.topbar-title h1{font-size:1rem}.agenda-hero,.agenda-card,.agenda-empty{border-radius:24px}.resumen-grid,.agenda-grid{grid-template-columns:1fr}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;right:0;width:auto;height:76px;bottom:0;border-radius:18px 18px 0 0;gap:0;padding:6px 4px;border-top:2px solid rgba(0,153,255,0.72)}.bottom-nav a img{width:34px;height:34px}.bottom-nav a::after{width:44px;height:44px;border-radius:13px}}



.alerta-agenda{border-radius:18px;padding:14px 16px;font-weight:900;box-shadow:0 10px 22px rgba(0,0,0,.06);position:relative;z-index:2}
.alerta-agenda.ok{background:#f0fdf4;border:2px solid rgba(34,197,94,.28);color:#166534}
.alerta-agenda.error{background:#fff5f7;border:2px solid rgba(255,75,92,.38);color:#b91c1c}
.btn-posponer,.btn-ver-modificacion,.btn-aceptar-modificacion,.btn-cancelar-reta,.btn-ir-sala-cancelada{min-height:44px;border:0;text-decoration:none;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;font-size:12px;font-weight:1000;cursor:pointer;transition:.25s ease;font-family:'Poppins',sans-serif;text-align:center;width:100%}
.btn-posponer{background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#fff;box-shadow:0 10px 18px rgba(24,119,242,.20)}
.btn-ver-modificacion{background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;box-shadow:0 10px 18px rgba(249,115,22,.20)}
.btn-aceptar-modificacion{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 10px 18px rgba(34,197,94,.20)}
.btn-cancelar-reta{background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;box-shadow:0 10px 18px rgba(239,68,68,.22)}
.btn-ir-sala-cancelada{background:linear-gradient(135deg,#8b5cf6,#2563eb);color:#fff;box-shadow:0 10px 18px rgba(37,99,235,.22)}
.btn-posponer:hover,.btn-ver-modificacion:hover,.btn-aceptar-modificacion:hover,.btn-cancelar-reta:hover,.btn-ir-sala-cancelada:hover{transform:translateY(-2px)}
.modificacion-aviso,.cancelacion-aviso{border-radius:16px;background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.28);padding:11px 12px;color:#92400e;font-size:12px;font-weight:900;line-height:1.45}
.cancelacion-aviso{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.26);color:#991b1b}
.resultado-final-card{border-radius:18px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.24);padding:13px;display:flex;flex-direction:column;gap:12px}
.resultado-final-card h4{font-family:'Orbitron',sans-serif;color:#15803d;font-size:14px;line-height:1.2}
.resultado-marcador{display:grid;grid-template-columns:1fr auto 1fr;gap:10px;align-items:center;text-align:center}
.resultado-marcador div:not(.resultado-vs){border-radius:16px;background:#fff;border:1px solid rgba(34,197,94,.18);padding:10px;display:flex;flex-direction:column;gap:6px}
.resultado-marcador span{font-size:12px;font-weight:1000;color:#374151;line-height:1.2}
.resultado-marcador strong{font-family:'Orbitron',sans-serif;font-size:28px;color:#111827;line-height:1}
.resultado-vs{font-family:'Orbitron',sans-serif;font-size:12px;font-weight:1000;color:#15803d}
.resultado-detalles{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.resultado-detalles p{font-size:11px;font-weight:900;color:#374151;line-height:1.35}
.resultado-detalles p strong{color:#111827}
.modal-modificacion{position:fixed;inset:0;z-index:9000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(8,15,30,.50);backdrop-filter:blur(12px)}
.modal-modificacion.activo{display:flex}
.modal-card{width:min(560px,100%);max-height:calc(100dvh - 36px);overflow:auto;border-radius:28px;background:#fff;border:2px solid rgba(0,153,255,.32);box-shadow:0 24px 70px rgba(0,0,0,.30);padding:22px;position:relative;display:flex;flex-direction:column;gap:14px}
.modal-card h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:1.2rem;line-height:1.25}
.modal-card p{font-size:12px;font-weight:800;color:#4b5563;line-height:1.45}
.modal-close{position:absolute;right:14px;top:14px;width:36px;height:36px;border:0;border-radius:12px;background:#f3f4f6;color:#111827;font-size:22px;font-weight:900;cursor:pointer}
.form-modal{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.campo-modal{display:flex;flex-direction:column;gap:6px}
.campo-modal.full{grid-column:1/-1}
.campo-modal label{font-size:12px;font-weight:1000;color:#111827}
.campo-modal input,.campo-modal textarea{width:100%;min-height:44px;border-radius:15px;border:2px solid rgba(0,153,255,.24);padding:10px 12px;font-family:'Poppins',sans-serif;font-weight:800;outline:none;color:#111827;background:#fff}
.campo-modal textarea{min-height:78px;resize:vertical}
.modal-acciones{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}
.btn-cancelar-modal{min-height:44px;border:0;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;font-size:12px;font-weight:1000;cursor:pointer;background:#e5e7eb;color:#374151;font-family:'Poppins',sans-serif}
body.dark-mode .alerta-agenda.ok{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.30);color:#bbf7d0}
body.dark-mode .alerta-agenda.error{background:rgba(255,75,92,.14);border-color:rgba(255,75,92,.34);color:#fecaca}
body.dark-mode .modal-card{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.70);box-shadow:0 24px 70px rgba(0,0,0,.50)}
body.dark-mode .modal-card h3{color:var(--cyan)}
body.dark-mode .modal-card p,body.dark-mode .campo-modal label{color:#e5e7eb}
body.dark-mode .campo-modal input,body.dark-mode .campo-modal textarea{background:#0b1220;color:#e5e7eb;border-color:rgba(0,153,255,.28)}
body.dark-mode .modal-close,body.dark-mode .btn-cancelar-modal{background:#243044;color:#e5e7eb}
body.dark-mode .modificacion-aviso{background:rgba(245,158,11,.18);border-color:rgba(245,158,11,.34);color:#fde68a}
body.dark-mode .cancelacion-aviso{background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.34);color:#fecaca}
body.dark-mode .resultado-final-card{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.28);color:#e5e7eb}
body.dark-mode .resultado-final-card h4,body.dark-mode .resultado-vs{color:#bbf7d0}
body.dark-mode .resultado-marcador div:not(.resultado-vs){background:#0b1220;border-color:rgba(34,197,94,.22)}
body.dark-mode .resultado-marcador span,body.dark-mode .resultado-marcador strong,body.dark-mode .resultado-detalles p,body.dark-mode .resultado-detalles p strong{color:#e5e7eb}
@media(max-width:620px){.form-modal{grid-template-columns:1fr}.modal-acciones>*{width:100%}.resultado-detalles{grid-template-columns:1fr}.resultado-marcador{grid-template-columns:1fr}.resultado-vs{padding:2px 0}}



/* Ventana emergente estilo RETAME limpio */
.modal-modificacion{
    position:fixed;
    inset:0;
    z-index:9000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(15,23,42,.58);
    backdrop-filter:blur(9px);
}
.modal-modificacion.activo{display:flex}
.modal-card{
    width:min(560px,calc(100vw - 34px));
    max-height:calc(100dvh - 36px);
    overflow:auto;
    border-radius:24px;
    background:#ffffff;
    border:1px solid rgba(226,232,240,.95);
    box-shadow:0 28px 80px rgba(15,23,42,.32);
    padding:22px 22px 20px;
    display:flex;
    flex-direction:column;
    gap:14px;
}
.modal-card h3{
    font-family:'Orbitron',sans-serif;
    color:#1d7df2;
    font-size:18px;
    line-height:1.2;
    padding-right:42px;
    letter-spacing:.4px;
}
.modal-card p{font-size:12px;font-weight:900;color:#374151;line-height:1.45}
.modal-close{
    position:absolute;
    right:15px;
    top:14px;
    width:34px;
    height:34px;
    border:0;
    border-radius:50%;
    background:#f1f5f9;
    color:#0f172a;
    font-size:22px;
    font-weight:1000;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
}
.modificacion-aviso{
    border-radius:13px;
    background:#fff2dc;
    border:1px solid #fed7aa;
    padding:11px 13px;
    color:#8a3c0a;
    font-size:12px;
    font-weight:1000;
    line-height:1.45;
}
.modal-nota-propia{
    border-radius:13px;
    background:#eef6ff;
    border:1px solid rgba(29,125,242,.20);
    color:#1e3a8a;
    padding:10px 12px;
    font-size:12px;
    font-weight:900;
    line-height:1.4;
}
.form-modal{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.campo-modal{display:flex;flex-direction:column;gap:7px}
.campo-modal.full{grid-column:1/-1}
.campo-modal label{font-size:12px;font-weight:1000;color:#111827}
.campo-modal input,.campo-modal textarea{
    width:100%;
    min-height:44px;
    border-radius:13px;
    border:1.8px solid rgba(14,165,233,.24);
    padding:10px 12px;
    font-family:'Poppins',sans-serif;
    font-size:13px;
    font-weight:900;
    outline:none;
    color:#111827;
    background:#fff;
}
.campo-modal input:focus,.campo-modal textarea:focus{
    border-color:rgba(29,125,242,.65);
    box-shadow:0 0 0 3px rgba(29,125,242,.10);
}
.campo-modal textarea{min-height:74px;resize:vertical}
.modal-acciones{display:flex;flex-direction:column;gap:10px;grid-column:1/-1;margin-top:2px}
.modal-aceptar-form{display:block;margin:0}
.btn-posponer,.btn-aceptar-modificacion,.btn-cancelar-modal{
    width:100%;
    min-height:43px;
    border-radius:13px;
    border:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px 16px;
    font-family:'Poppins',sans-serif;
    font-size:12px;
    font-weight:1000;
    cursor:pointer;
    transition:.22s ease;
}
.btn-posponer{background:linear-gradient(135deg,#1d7df2,#0ea5e9);color:#fff;box-shadow:none}
.btn-aceptar-modificacion{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:none}
.btn-cancelar-modal{background:#e5e7eb;color:#374151;box-shadow:none}
.btn-posponer:hover,.btn-aceptar-modificacion:hover,.btn-cancelar-modal:hover{transform:translateY(-1px)}
body.dark-mode .modal-modificacion{background:rgba(2,6,23,.66)}
body.dark-mode .modal-card{background:#111827;border-color:rgba(255,75,92,.55);box-shadow:0 28px 80px rgba(0,0,0,.60)}
body.dark-mode .modal-card h3{color:#8fefff;text-shadow:0 0 10px rgba(143,239,255,.25)}
body.dark-mode .modal-card p,body.dark-mode .campo-modal label{color:#e5e7eb}
body.dark-mode .modal-close{background:#243044;color:#e5e7eb}
body.dark-mode .campo-modal input,body.dark-mode .campo-modal textarea{background:#0b1220;color:#e5e7eb;border-color:rgba(143,239,255,.22)}
body.dark-mode .modificacion-aviso{background:rgba(245,158,11,.18);border-color:rgba(245,158,11,.34);color:#fde68a}
body.dark-mode .modal-nota-propia{background:rgba(14,165,233,.14);border-color:rgba(14,165,233,.25);color:#bfdbfe}
@media(max-width:620px){.form-modal{grid-template-columns:1fr}.modal-card{border-radius:22px;padding:20px}.modal-acciones>*{width:100%}}

</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area">
        <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo" onerror="this.onerror=null;this.src='../assets/doctor.png';">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <a href="MiPerfil.php" class="perfil-sidebar-card">
            <div class="perfil-sidebar-foto">
                <?php if ($FotoPerfilUsuario !== ''): ?>
                    <img src="<?php echo limpiarTexto($FotoPerfilUsuario); ?>" alt="Foto de perfil" onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='<?php echo limpiarTexto($iniciales); ?>';">
                <?php else: ?>
                    <span><?php echo limpiarTexto($iniciales); ?></span>
                <?php endif; ?>
            </div>
            <div class="perfil-sidebar-info">
                <span>Perfil</span>
                <strong><?php echo limpiarTexto($Nombre); ?></strong>
            </div>
        </a>

        <div class="acciones-grid">
            <a href="Equipo/UnirmeOtroEquipo.php" class="accion-boceto"><img src="Imagenes/ImgUnion.png" alt=""><span>Unirme equipo</span></a>
            <a href="Equipo/CrearEquipo.php" class="accion-boceto"><img src="Imagenes/ImgCreacion.png" alt=""><span>Crear equipo</span></a>
            <a href="Solicitudes.php" class="accion-boceto"><img src="Imagenes/ImgSolicitud.png" alt=""><span>Solicitud</span></a>
            <a href="Equipo/Mis_Equipos.php" class="accion-boceto"><img src="Imagenes/ImgEquipo.png" alt=""><span>Equipo</span></a>
            <a href="Ligas/liga.php" class="accion-boceto"><img src="Imagenes/ImgLigas.png" alt=""><span>Ligas</span></a>
            <a href="Retar/retar.php" class="accion-boceto"><img src="Imagenes/ImgReta.png" alt=""><span>Retar</span></a>
            <a href="Canchas/Canchas.php" class="accion-boceto"><img src="Imagenes/ImgCanchas.png" alt=""><span>Canchas</span></a>
            <a href="Amigos.php" class="accion-boceto"><img src="Imagenes/ImgAmigos.png" alt=""><span>Amigos</span></a>
        </div>

        <a href="login.php" class="cerrar-boceto"><img src="Imagenes/ImgCerrar.png" alt=""><span>Cerrar sesión</span></a>

        <div class="info-boceto">
            <a href="Informacion.php">Información</a>
            <a href="AcercaDe.php">Acerca de</a>
            <a href="SoporteTecnico.php">Soporte técnico</a>
        </div>

        <div class="modo-oscuro-panel">
            <div class="modo-oscuro-texto"><span>🌙</span><strong>Modo oscuro</strong></div>
            <button type="button" class="switch-modo" id="darkModeToggle" aria-label="<?php echo $modoOscuroActivo ? 'Desactivar modo oscuro' : 'Activar modo oscuro'; ?>"><span></span></button>
        </div>
    </div>
</aside>

<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="topbar-title"><h1>Agenda de <?php echo limpiarTexto($Nombre); ?></h1></div>
    <div class="topbar-user"><span>👤</span><span><?php echo limpiarTexto($Nombre); ?></span></div>
</header>

<main class="main-content">
    <section class="agenda-wrapper">
        <div class="agenda-hero">
            <span class="agenda-chip">📅 Retas agendadas</span>
            <h2>Mi agenda deportiva</h2>
            <p>Aquí aparecen únicamente las retas programadas donde tu usuario está registrado como creador, capitán o jugador dentro de los equipos.</p>

            <div class="resumen-grid">
                <div class="dato-agenda"><span>Total agendadas</span><strong><?php echo (int)$totalRetas; ?></strong></div>
                <div class="dato-agenda"><span>Próxima reta</span><strong><?php echo $proximaReta ? limpiarTexto(($proximaReta['deporte'] ?? 'Reta') . ' · ' . ($proximaReta['fecha_reta'] ?? 'Fecha pendiente')) : 'Sin reta próxima'; ?></strong></div>
                <div class="dato-agenda"><span>Usuario</span><strong><?php echo limpiarTexto($Nombre . ($Apellido !== '' ? ' ' . $Apellido : '')); ?></strong></div>
            </div>
        </div>

        <?php if ($mensajeAgenda !== ''): ?><div class="alerta-agenda ok">✅ <?php echo limpiarTexto($mensajeAgenda); ?></div><?php endif; ?>
        <?php if (!empty($erroresAgenda)): ?><div class="alerta-agenda error"><?php foreach ($erroresAgenda as $errorAgenda): ?><div>⚠️ <?php echo limpiarTexto($errorAgenda); ?></div><?php endforeach; ?></div><?php endif; ?>

        <?php if (!tablaExisteAgenda($conn, 'R_retasprogramadas')): ?>
            <div class="agenda-empty">
                <h3>No existe la tabla R_retasprogramadas</h3>
                <p>Primero programa una reta o ejecuta el SQL que crea la tabla de retas programadas.</p>
                <a href="Retar/retar.php" class="btn-volver-retar">Ir a retas</a>
            </div>
        <?php elseif ($totalRetas === 0): ?>
            <div class="agenda-empty">
                <h3>Aún no tienes retas agendadas</h3>
                <p>Cuando participes en una reta programada, aparecerá aquí automáticamente.</p>
                <a href="Retar/retar.php" class="btn-volver-retar">Buscar retas</a>
            </div>
        <?php else: ?>
            <div class="agenda-grid">
                <?php foreach ($retasAgendadas as $reta): ?>
                    <?php
                        $p = $reta['_participacion'];
                        $deporte = $reta['deporte'] ?? 'Reta';
                        $cancha = $reta['cancha'] ?? 'Cancha por definir';
                        $fecha = $reta['fecha_reta'] ?? 'Fecha pendiente';
                        $hora = $reta['hora_reta'] ?? 'Hora pendiente';
                        $estado = $reta['estado_programada'] ?? 'Programada';
                        $equipo1 = $reta['nombre_equipo1'] ?? 'Equipo 1';
                        $equipo2 = $reta['nombre_equipo2'] ?? 'Equipo 2';
                        $total1 = isset($reta['total_equipo1']) ? (int)$reta['total_equipo1'] : 0;
                        $total2 = isset($reta['total_equipo2']) ? (int)$reta['total_equipo2'] : 0;
                        $direccion = $reta['direccion'] ?? '';
                        $codigoPostal = $reta['codigo_postal'] ?? '';
                        $idRetaResultado = $reta['id_reta'] ?? ($reta['id_partido'] ?? ($reta['id_categoria'] ?? ''));
                        $modalId = 'modalMod_' . md5((string)$idRetaResultado);
                        $esCapitanRetaAgenda = usuarioEsCapitanRetaTempAgenda($conn, $idRetaResultado, $Id_Retador);
                        $estadoNormalizadoAgenda = normalizarTextoAgenda($estado);
                        $estadoProgramadaAgenda = $estadoNormalizadoAgenda === 'programada';
                        $estadoFinalizadaAgenda = estadoFinalizadoAgenda($estado);
                        $modificacionPor = trim((string)($reta['modificacion_por'] ?? ''));
                        $modificacionEquipo = isset($reta['modificacion_equipo']) ? (int)$reta['modificacion_equipo'] : 0;
                        $idEquipoCapitanActual = $esCapitanRetaAgenda ? obtenerEquipoCapitanRetaAgenda($conn, $idRetaResultado, $Id_Retador) : 0;
                        $modificacionPendiente = $estadoNormalizadoAgenda === 'en modificacion';
                        $modificacionMismoEquipo = $modificacionEquipo > 0 && $idEquipoCapitanActual > 0 && $modificacionEquipo === $idEquipoCapitanActual;
                        $soyQuienModifico = $modificacionMismoEquipo || ($modificacionEquipo <= 0 && $modificacionPor !== '' && (string)$modificacionPor === (string)$Id_Retador);
                        $puedeAceptarModificacion = $modificacionPendiente && $esCapitanRetaAgenda && !$soyQuienModifico;
                        $canceladoPor = trim((string)($reta['cancelado_por'] ?? ''));
                        $canceladoEquipo = isset($reta['cancelado_equipo']) ? (int)$reta['cancelado_equipo'] : 0;
                        $retaCancelada = $estadoNormalizadoAgenda === 'cancelado';
                        $soyQuienCancelo = $canceladoPor !== '' && (string)$canceladoPor === (string)$Id_Retador;
                        $puedeIrSalaCancelada = $retaCancelada && $esCapitanRetaAgenda && !$soyQuienCancelo && ($canceladoEquipo <= 0 || $idEquipoCapitanActual !== $canceladoEquipo);
                        $resultadoFinalAgenda = $estadoFinalizadaAgenda ? obtenerResultadoFinalRetaAgenda($conn, $idRetaResultado) : null;
                        $resultadoEquipo1Agenda = is_array($resultadoFinalAgenda) && isset($resultadoFinalAgenda['resultado_equipo1']) ? (int)$resultadoFinalAgenda['resultado_equipo1'] : 0;
                        $resultadoEquipo2Agenda = is_array($resultadoFinalAgenda) && isset($resultadoFinalAgenda['resultado_equipo2']) ? (int)$resultadoFinalAgenda['resultado_equipo2'] : 0;
                        $fechaJugadoAgenda = is_array($resultadoFinalAgenda) && trim((string)($resultadoFinalAgenda['fecha_partido'] ?? '')) !== '' ? trim((string)$resultadoFinalAgenda['fecha_partido']) : $fecha;
                        $horaJugadoAgenda = is_array($resultadoFinalAgenda) && trim((string)($resultadoFinalAgenda['hora_partido'] ?? '')) !== '' ? trim((string)$resultadoFinalAgenda['hora_partido']) : $hora;
                        $fechaRegistroResultadoAgenda = is_array($resultadoFinalAgenda) && trim((string)($resultadoFinalAgenda['fecha'] ?? '')) !== '' ? trim((string)$resultadoFinalAgenda['fecha']) : '';
                        $horaRegistroResultadoAgenda = is_array($resultadoFinalAgenda) && trim((string)($resultadoFinalAgenda['hora_registro'] ?? '')) !== '' ? trim((string)$resultadoFinalAgenda['hora_registro']) : '';
                        $estadoResultadoFinalAgenda = textoEstadoResultadoFinalAgenda($resultadoFinalAgenda);
                        $puedeRegistrarResultado = $estadoProgramadaAgenda && resultadoDisponibleAgenda($fecha, $hora);
                        $urlRegistrarResultado = 'RegistrarResultado.php?id_reta=' . urlencode((string)$idRetaResultado);
                    ?>
                    <article class="agenda-card">
                        <div class="agenda-card-top">
                            <h3><?php echo limpiarTexto($deporte); ?></h3>
                            <span class="estado-pill"><?php echo limpiarTexto($estado); ?></span>
                        </div>

                        <span class="mi-rol"><?php echo limpiarTexto($p['rol']); ?><?php echo $p['nombre_equipo'] !== '' ? ' · ' . limpiarTexto($p['nombre_equipo']) : ''; ?></span>

                        <div class="agenda-info">
                            <p><strong>Cancha:</strong> <?php echo limpiarTexto($cancha); ?></p>
                            <p><strong>Fecha:</strong> <?php echo limpiarTexto($fecha); ?></p>
                            <p><strong>Hora:</strong> <?php echo limpiarTexto($hora); ?></p>
                            <?php if ($direccion !== ''): ?><p><strong>Ubicación:</strong> <?php echo limpiarTexto($direccion); ?></p><?php endif; ?>
                            <?php if ($codigoPostal !== ''): ?><p><strong>C.P.:</strong> <?php echo limpiarTexto($codigoPostal); ?></p><?php endif; ?>
                            <?php if ($p['numero'] !== '' || $p['posicion'] !== ''): ?>
                                <p><strong>Tus datos:</strong> <?php echo $p['numero'] !== '' ? '#' . limpiarTexto($p['numero']) : ''; ?> <?php echo $p['posicion'] !== '' ? '· ' . limpiarTexto($p['posicion']) : ''; ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="agenda-equipos">
                            <div class="agenda-equipo-linea"><span><?php echo limpiarTexto($equipo1); ?></span><span><?php echo (int)$total1; ?> jugador(es)</span></div>
                            <div class="agenda-equipo-linea"><span><?php echo limpiarTexto($equipo2); ?></span><span><?php echo (int)$total2; ?> jugador(es)</span></div>
                        </div>

                        <?php if ($estadoFinalizadaAgenda): ?>
                            <div class="resultado-final-card">
                                <h4>Resultado final</h4>
                                <div class="resultado-marcador">
                                    <div><span><?php echo limpiarTexto($equipo1); ?></span><strong><?php echo (int)$resultadoEquipo1Agenda; ?></strong></div>
                                    <div class="resultado-vs">VS</div>
                                    <div><span><?php echo limpiarTexto($equipo2); ?></span><strong><?php echo (int)$resultadoEquipo2Agenda; ?></strong></div>
                                </div>
                                <div class="resultado-detalles">
                                    <p><strong>Estado:</strong> <?php echo limpiarTexto($estadoResultadoFinalAgenda); ?></p>
                                    <p><strong>Fecha jugada:</strong> <?php echo limpiarTexto($fechaJugadoAgenda); ?></p>
                                    <p><strong>Hora del partido:</strong> <?php echo limpiarTexto($horaJugadoAgenda); ?></p>
                                    <?php if ($fechaRegistroResultadoAgenda !== '' || $horaRegistroResultadoAgenda !== ''): ?>
                                        <p><strong>Registro:</strong> <?php echo limpiarTexto(trim($fechaRegistroResultadoAgenda . ' ' . $horaRegistroResultadoAgenda)); ?></p>
                                    <?php endif; ?>
                                    <p><strong>Cancha:</strong> <?php echo limpiarTexto($cancha); ?></p>
                                    <?php if ($direccion !== ''): ?><p><strong>Dirección:</strong> <?php echo limpiarTexto($direccion); ?></p><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="acciones-resultado">
                            <?php if (!$estadoFinalizadaAgenda && $esCapitanRetaAgenda): ?>
                                <?php if ($retaCancelada): ?>
                                    <div class="cancelacion-aviso">
                                        El otro equipo canceló la reta. Ve a la sala de espera para encontrar otra reta o esperar a un nuevo rival.
                                    </div>
                                    <?php if ($puedeIrSalaCancelada): ?>
                                        <form method="POST" action="Agenda.php">
                                            <input type="hidden" name="accion" value="ir_sala_cancelada">
                                            <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idRetaResultado); ?>">
                                            <button type="submit" class="btn-ir-sala-cancelada">Ir a la sala de espera</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($modificacionPendiente): ?>
                                    <div class="modificacion-aviso">
                                        <?php if ($soyQuienModifico): ?>
                                            La modificación quedó en estado En Modificacion y pendiente de aceptación del otro capitán.
                                        <?php else: ?>
                                            La reta sufrió una modificación en el horario o lugar. Revisa la información y acepta si estás de acuerdo.
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn-ver-modificacion" data-open-modal="<?php echo limpiarTexto($modalId); ?>">Ver modificación</button>
                                <?php elseif ($estadoProgramadaAgenda): ?>
                                    <?php if ($puedeRegistrarResultado): ?>
                                        <a href="<?php echo limpiarTexto($urlRegistrarResultado); ?>" class="btn-registrar-resultado">Registrar resultado</a>
                                    <?php else: ?>
                                        <span class="btn-registrar-resultado disabled">Registrar resultado</span>
                                        <small class="resultado-ayuda"><?php echo limpiarTexto(textoDisponibleResultadoAgenda($fecha, $hora)); ?></small>
                                    <?php endif; ?>
                                    <button type="button" class="btn-posponer" data-open-modal="<?php echo limpiarTexto($modalId); ?>">Posponer o cambiar lugar</button>
                                    <form method="POST" action="Agenda.php" onsubmit="return confirm('¿Seguro que quieres cancelar esta reta? Tu equipo se eliminará de la sala de espera.');">
                                        <input type="hidden" name="accion" value="cancelar_reta">
                                        <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idRetaResultado); ?>">
                                        <button type="submit" class="btn-cancelar-reta">Cancelar reta</button>
                                    </form>
                                <?php else: ?>
                                    <div class="resultado-ayuda">No hay acciones disponibles para el estado: <?php echo limpiarTexto($estado); ?>.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($esCapitanRetaAgenda && !$estadoFinalizadaAgenda && !$retaCancelada): ?>
                            <div class="modal-modificacion" id="<?php echo limpiarTexto($modalId); ?>" aria-hidden="true">
                                <div class="modal-card">
                                    <button type="button" class="modal-close" data-close-modal="<?php echo limpiarTexto($modalId); ?>">×</button>
                                    <h3><?php echo $modificacionPendiente ? 'Revisar modificación' : 'Posponer o cambiar lugar'; ?></h3>
                                    <p><?php echo limpiarTexto($equipo1); ?> vs <?php echo limpiarTexto($equipo2); ?></p>
                                    <?php if ($modificacionPendiente): ?>
                                        <div class="modificacion-aviso">
                                            <?php if ($soyQuienModifico): ?>
                                                Tú realizaste esta modificación. El otro capitán debe aceptarla para volver a programar la reta.
                                            <?php else: ?>
                                                La reta sufrió una modificación en el horario o lugar. Puedes aceptarla o modificar los datos antes de guardar.
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($puedeAceptarModificacion): ?>
                                        <form method="POST" action="Agenda.php" class="modal-aceptar-form">
                                            <input type="hidden" name="accion" value="aceptar_modificacion_reta">
                                            <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idRetaResultado); ?>">
                                            <button type="submit" class="btn-aceptar-modificacion">Aceptar modificación</button>
                                        </form>
                                    <?php elseif ($modificacionPendiente && $soyQuienModifico): ?>
                                        <div class="modal-nota-propia">El botón de aceptar solo aparece al capitán del otro equipo.</div>
                                    <?php endif; ?>

                                    <form method="POST" action="Agenda.php" class="form-modal">
                                        <input type="hidden" name="accion" value="posponer_reta">
                                        <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idRetaResultado); ?>">
                                        <div class="campo-modal">
                                            <label>Fecha</label>
                                            <input type="date" name="fecha_reta" value="<?php echo limpiarTexto(normalizarFechaBDAgenda($fecha)); ?>" required>
                                        </div>
                                        <div class="campo-modal">
                                            <label>Horario</label>
                                            <input type="time" name="hora_reta" value="<?php echo limpiarTexto(substr(normalizarHoraBDAgenda($hora), 0, 5)); ?>" required>
                                        </div>
                                        <div class="campo-modal full">
                                            <label>Cancha</label>
                                            <input type="text" name="cancha" value="<?php echo limpiarTexto($cancha); ?>" required>
                                        </div>
                                        <div class="campo-modal full">
                                            <label>Dirección</label>
                                            <textarea name="direccion" required><?php echo limpiarTexto($direccion); ?></textarea>
                                        </div>
                                        <div class="modal-acciones campo-modal full">
                                            <button type="submit" class="btn-posponer">Guardar cambios</button>
                                            <button type="button" class="btn-cancelar-modal" data-close-modal="<?php echo limpiarTexto($modalId); ?>">Cancelar</button>
                                        </div>
                                    </form>


                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<nav class="bottom-nav">
    <a href="Perfil2.php" aria-label="Inicio"><img src="Imagenes/ImgInicio.png" alt=""></a>
    <a href="Retar/retar.php" aria-label="Retar"><img src="Imagenes/ImgReta.png" alt=""></a>
    <a href="Ligas/liga.php" aria-label="Ligas"><img src="Imagenes/ImgLigas.png" alt=""></a>
    <a href="Agenda.php" class="active" aria-label="Agenda"><img src="Imagenes/ImgAgenda.png" alt=""></a>
    <a href="Notificaciones.php" aria-label="Notificaciones"><img src="Imagenes/ImgNoti.png" alt=""></a>
    <a href="MiPerfil.php" aria-label="Perfil"><img src="Imagenes/ImgPerfil.png" alt=""></a>
</nav>

<script>
const body=document.body;
const menuToggle=document.getElementById('menuToggle');
const mobileOverlay=document.getElementById('mobileOverlay');
const darkModeToggle=document.getElementById('darkModeToggle');
function esMovil(){return window.innerWidth<=820}
if(menuToggle){menuToggle.addEventListener('click',()=>{if(esMovil()){body.classList.toggle('sidebar-open')}else{body.classList.toggle('sidebar-hidden')}})}
if(mobileOverlay){mobileOverlay.addEventListener('click',()=>body.classList.remove('sidebar-open'))}
window.addEventListener('resize',()=>{if(!esMovil())body.classList.remove('sidebar-open')});
function aplicarModoOscuroVisual(estado){body.classList.toggle('dark-mode',estado);if(darkModeToggle){darkModeToggle.setAttribute('aria-label',estado?'Desactivar modo oscuro':'Activar modo oscuro')}}
function actualizarModoPerfilBD(estado){const datos=new FormData();datos.append('accion','actualizar_modo_perfil');datos.append('modo',estado?'oscuro':'predeterminado');return fetch(window.location.href,{method:'POST',body:datos,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())}
if(darkModeToggle){darkModeToggle.addEventListener('click',()=>{const nuevo=!body.classList.contains('dark-mode');const anterior=!nuevo;aplicarModoOscuroVisual(nuevo);darkModeToggle.disabled=true;actualizarModoPerfilBD(nuevo).then(data=>{if(!data||!data.ok){aplicarModoOscuroVisual(anterior);alert(data&&data.mensaje?data.mensaje:'No se pudo guardar el modo.')}}).catch(()=>{aplicarModoOscuroVisual(anterior);alert('No se pudo conectar con la base de datos.')}).finally(()=>{darkModeToggle.disabled=false})})}


document.querySelectorAll('[data-open-modal]').forEach((boton)=>{
    boton.addEventListener('click',()=>{
        const id=boton.getAttribute('data-open-modal');
        const modal=document.getElementById(id);
        if(modal){modal.classList.add('activo');modal.setAttribute('aria-hidden','false')}
    });
});
document.querySelectorAll('[data-close-modal]').forEach((boton)=>{
    boton.addEventListener('click',()=>{
        const id=boton.getAttribute('data-close-modal');
        const modal=document.getElementById(id);
        if(modal){modal.classList.remove('activo');modal.setAttribute('aria-hidden','true')}
    });
});
document.querySelectorAll('.modal-modificacion').forEach((modal)=>{
    modal.addEventListener('click',(e)=>{
        if(e.target===modal){modal.classList.remove('activo');modal.setAttribute('aria-hidden','true')}
    });
});
document.addEventListener('keydown',(e)=>{
    if(e.key==='Escape'){
        document.querySelectorAll('.modal-modificacion.activo').forEach((modal)=>{modal.classList.remove('activo');modal.setAttribute('aria-hidden','true')});
    }
});

</script>
</body>
</html>
