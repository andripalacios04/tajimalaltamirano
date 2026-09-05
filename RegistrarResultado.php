<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('America/Mexico_City');
}

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header('Location: login.php');
    exit();
}

if (file_exists(__DIR__ . '/conexion.php')) {
    include_once __DIR__ . '/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    include_once __DIR__ . '/../conexion.php';
} else {
    die('No se encontró el archivo de conexión.');
}

if (!isset($conn) && isset($conexion)) {
    $conn = $conexion;
}

if (!isset($conn) || !$conn) {
    die('No se pudo conectar con la base de datos.');
}

$usuarios = $_SESSION['usuario_data'];

function limpiarTexto($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerSesionResultado($datos, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return $default;
}

function normalizarTextoResultado($valor) {
    $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
    return str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $valor);
}

function obtenerColumnasResultado($conn, $tabla) {
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

function tablaExisteResultado($conn, $tabla) {
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function asegurarCampoResultado($conn, $tabla, $campo, $definicion) {
    $columnas = obtenerColumnasResultado($conn, $tabla);
    if (isset($columnas[$campo])) {
        return true;
    }
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $campo = preg_replace('/[^a-zA-Z0-9_]/', '', $campo);
    $conn->query("ALTER TABLE `$tabla` ADD COLUMN `$campo` $definicion");
    $columnas = obtenerColumnasResultado($conn, $tabla);
    return isset($columnas[$campo]);
}

function asegurarTablaResultadosRetas($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS r_resultadosretas (
        id_resultado INT AUTO_INCREMENT PRIMARY KEY,
        id_reta VARCHAR(120) NOT NULL,
        id_equipo1 INT NOT NULL DEFAULT 0,
        id_equipo2 INT NOT NULL DEFAULT 0,
        nombre_equipo1 VARCHAR(150) NOT NULL DEFAULT '',
        nombre_equipo2 VARCHAR(150) NOT NULL DEFAULT '',
        id_capitan_equipo1 VARCHAR(60) NOT NULL DEFAULT '',
        id_capitan_equipo2 VARCHAR(60) NOT NULL DEFAULT '',
        nombre_capitan_equipo1 VARCHAR(180) NOT NULL DEFAULT '',
        nombre_capitan_equipo2 VARCHAR(180) NOT NULL DEFAULT '',
        hora_registro TIME NOT NULL,
        fecha DATE NOT NULL,
        fecha_partido DATE NOT NULL,
        hora_partido TIME NOT NULL,
        estado VARCHAR(80) NOT NULL DEFAULT 'Esperando confirmación',
        resultado_equipo1 INT NOT NULL DEFAULT 0,
        resultado_equipo2 INT NOT NULL DEFAULT 0,
        id_anotadores LONGTEXT NULL,
        quejas LONGTEXT NULL,
        expulciones LONGTEXT NULL,
        cancha VARCHAR(180) NOT NULL DEFAULT '',
        direccion VARCHAR(255) NOT NULL DEFAULT '',
        resultado_capitan1_equipo1 INT NULL,
        resultado_capitan1_equipo2 INT NULL,
        resultado_capitan2_equipo1 INT NULL,
        resultado_capitan2_equipo2 INT NULL,
        id_anotadores_capitan1 LONGTEXT NULL,
        id_anotadores_capitan2 LONGTEXT NULL,
        quejas_capitan1 LONGTEXT NULL,
        quejas_capitan2 LONGTEXT NULL,
        expulciones_capitan1 LONGTEXT NULL,
        expulciones_capitan2 LONGTEXT NULL,
        registrado_por_capitan1 TINYINT NOT NULL DEFAULT 0,
        registrado_por_capitan2 TINYINT NOT NULL DEFAULT 0,
        registro_capitan1_en DATETIME NULL,
        registro_capitan2_en DATETIME NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_resultados_reta (id_reta),
        INDEX idx_resultados_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        return false;
    }

    asegurarCampoResultado($conn, 'r_resultadosretas', 'resultado_capitan1_equipo1', 'INT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'resultado_capitan1_equipo2', 'INT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'resultado_capitan2_equipo1', 'INT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'resultado_capitan2_equipo2', 'INT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'id_anotadores_capitan1', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'id_anotadores_capitan2', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'quejas_capitan1', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'quejas_capitan2', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'expulciones_capitan1', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'expulciones_capitan2', 'LONGTEXT NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'registrado_por_capitan1', 'TINYINT NOT NULL DEFAULT 0');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'registrado_por_capitan2', 'TINYINT NOT NULL DEFAULT 0');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'registro_capitan1_en', 'DATETIME NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'registro_capitan2_en', 'DATETIME NULL');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'creado_en', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    asegurarCampoResultado($conn, 'r_resultadosretas', 'actualizado_en', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');

    return true;
}

function normalizarFechaSQLResultado($fecha, $default = '') {
    $fecha = trim((string)$fecha);
    if ($fecha === '') {
        return $default !== '' ? $default : date('Y-m-d');
    }
    $fecha = str_replace('/', '-', $fecha);
    $time = strtotime($fecha);
    if ($time === false) {
        return $default !== '' ? $default : date('Y-m-d');
    }
    return date('Y-m-d', $time);
}

function normalizarHoraSQLResultado($hora, $default = '') {
    $hora = trim((string)$hora);
    if ($hora === '') {
        return $default !== '' ? $default : date('H:i:s');
    }
    $partes = preg_split('/\s*-\s*/', $hora);
    $hora = trim((string)$partes[0]);
    if (preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
        $hora .= ':00';
    }
    $time = strtotime($hora);
    if ($time === false) {
        return $default !== '' ? $default : date('H:i:s');
    }
    return date('H:i:s', $time);
}

function timestampRetaResultado($fecha, $hora) {
    $fechaSQL = normalizarFechaSQLResultado($fecha, '');
    if ($fechaSQL === '') {
        return null;
    }
    $horaSQL = normalizarHoraSQLResultado($hora, '00:00:00');
    $time = strtotime($fechaSQL . ' ' . $horaSQL);
    return $time === false ? null : $time;
}

function resultadoDisponibleResultado($fecha, $hora) {
    $time = timestampRetaResultado($fecha, $hora);
    if ($time === null) {
        return false;
    }
    return time() >= $time;
}

function obtenerNombreRetadorResultado($conn, $idRetador) {
    $idRetador = trim((string)$idRetador);
    if ($idRetador === '' || $idRetador === '0') {
        return '';
    }
    $stmt = $conn->prepare("SELECT Nombre, Apellido FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('s', $idRetador);
    $stmt->execute();
    $res = $stmt->get_result();
    $nombre = '';
    if ($fila = $res->fetch_assoc()) {
        $nombre = trim((string)($fila['Nombre'] ?? '') . ' ' . (string)($fila['Apellido'] ?? ''));
    }
    $stmt->close();
    return $nombre;
}

function obtenerAgendaResultado($conn, $idReta) {
    if (!tablaExisteResultado($conn, 'Agenda')) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM Agenda WHERE (LOWER(TRIM(categoria)) = 'reta' OR TRIM(categoria) = '') AND (CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)) ORDER BY id_agenda DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $idReta, $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $fila ? $fila : null;
}

function obtenerProgramadaResultado($conn, $idReta) {
    if (!tablaExisteResultado($conn, 'R_retasprogramadas')) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM R_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $fila ? $fila : null;
}

function obtenerCapitanEquipoTempResultado($conn, $idReta, $idEquipo) {
    if (!tablaExisteResultado($conn, 'r_equipotemp')) {
        return '';
    }
    $columnas = obtenerColumnasResultado($conn, 'r_equipotemp');
    $capitan = '';
    if (isset($columnas['id_reta'])) {
        $stmt = $conn->prepare("SELECT capitan FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND capitan IS NOT NULL AND TRIM(capitan) <> '' AND CAST(capitan AS CHAR) <> '0' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT capitan FROM r_equipotemp WHERE id_equipo = ? AND capitan IS NOT NULL AND TRIM(capitan) <> '' AND CAST(capitan AS CHAR) <> '0' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }
    if (isset($stmt) && $stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $capitan = trim((string)$fila['capitan']);
        }
        $stmt->close();
    }
    return $capitan;
}

function obtenerNombreEquipoTempResultado($conn, $idReta, $idEquipo, $default) {
    if (!tablaExisteResultado($conn, 'r_equipotemp')) {
        return $default;
    }
    $columnas = obtenerColumnasResultado($conn, 'r_equipotemp');
    $nombre = '';
    if (isset($columnas['id_reta'])) {
        $stmt = $conn->prepare("SELECT nombre FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND TRIM(nombre) <> '' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT nombre FROM r_equipotemp WHERE id_equipo = ? AND TRIM(nombre) <> '' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }
    if (isset($stmt) && $stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $nombre = trim((string)$fila['nombre']);
        }
        $stmt->close();
    }
    return $nombre !== '' ? $nombre : $default;
}

function jugadoresDesdeJsonResultado($json) {
    $salida = [];
    $datos = json_decode((string)$json, true);
    if (!is_array($datos)) {
        return $salida;
    }
    foreach ($datos as $jugador) {
        if (!is_array($jugador)) {
            continue;
        }
        $id = trim((string)($jugador['id_retador'] ?? ''));
        if ($id === '' || $id === '0') {
            continue;
        }
        $nombre = trim((string)($jugador['nombre'] ?? '') . ' ' . (string)($jugador['apellido'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Jugador ' . $id;
        }
        $salida[] = [
            'id_retador' => $id,
            'nombre' => $nombre,
            'numero_jugador' => $jugador['numero_jugador'] ?? '',
            'posicion' => $jugador['posicion'] ?? ''
        ];
    }
    return $salida;
}

function cargarJugadoresEquipoResultado($conn, $idReta, $idEquipo, $jsonFallback = '') {
    $jugadores = [];
    if (tablaExisteResultado($conn, 'r_equipotemp')) {
        $columnas = obtenerColumnasResultado($conn, 'r_equipotemp');
        $base = "SELECT et.*, r.Nombre AS nombre_bd, r.Apellido AS apellido_bd FROM r_equipotemp et LEFT JOIN retador r ON CAST(r.Id_Retador AS CHAR) = CAST(et.id_retador AS CHAR) WHERE ";
        $orden = " ORDER BY CASE WHEN CAST(et.id_retador AS CHAR) = CAST(et.capitan AS CHAR) THEN 0 ELSE 1 END, et.numero_jugador ASC, et.nombre_retador ASC";
        if (isset($columnas['id_reta'])) {
            $stmt = $conn->prepare($base . "et.id_reta = ? AND et.id_equipo = ? AND CAST(et.id_retador AS CHAR) <> '' AND CAST(et.id_retador AS CHAR) <> '0'" . $orden);
            if ($stmt) {
                $stmt->bind_param('si', $idReta, $idEquipo);
            }
        } else {
            $stmt = $conn->prepare($base . "et.id_equipo = ? AND CAST(et.id_retador AS CHAR) <> '' AND CAST(et.id_retador AS CHAR) <> '0'" . $orden);
            if ($stmt) {
                $stmt->bind_param('i', $idEquipo);
            }
        }
        if (isset($stmt) && $stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($fila = $res->fetch_assoc()) {
                $id = trim((string)($fila['id_retador'] ?? ''));
                $nombre = trim((string)($fila['nombre_retador'] ?? ''));
                $apellido = trim((string)($fila['apellido_retador'] ?? ''));
                if ($nombre === '') {
                    $nombre = trim((string)($fila['nombre_bd'] ?? ''));
                }
                if ($apellido === '') {
                    $apellido = trim((string)($fila['apellido_bd'] ?? ''));
                }
                $nombreCompleto = trim($nombre . ' ' . $apellido);
                if ($nombreCompleto === '') {
                    $nombreCompleto = 'Jugador ' . $id;
                }
                $jugadores[] = [
                    'id_retador' => $id,
                    'nombre' => $nombreCompleto,
                    'numero_jugador' => $fila['numero_jugador'] ?? '',
                    'posicion' => $fila['posicion'] ?? ''
                ];
            }
            $stmt->close();
        }
    }
    if (empty($jugadores)) {
        $jugadores = jugadoresDesdeJsonResultado($jsonFallback);
    }
    return $jugadores;
}

function obtenerResultadoExistente($conn, $idReta) {
    if (!asegurarTablaResultadosRetas($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM r_resultadosretas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) ORDER BY id_resultado DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $fila ? $fila : null;
}

function crearResultadoBaseSiNoExiste($conn, $datosReta) {
    $existente = obtenerResultadoExistente($conn, $datosReta['id_reta']);
    if ($existente) {
        return true;
    }
    $horaRegistro = date('H:i:s');
    $fechaRegistro = date('Y-m-d');
    $estado = 'Esperando confirmación';
    $resultado1 = 0;
    $resultado2 = 0;
    $vacio = '';
    $sql = "INSERT INTO r_resultadosretas
        (id_reta, id_equipo1, id_equipo2, nombre_equipo1, nombre_equipo2, id_capitan_equipo1, id_capitan_equipo2, nombre_capitan_equipo1, nombre_capitan_equipo2, hora_registro, fecha, fecha_partido, hora_partido, estado, resultado_equipo1, resultado_equipo2, id_anotadores, quejas, expulciones, cancha, direccion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'siisssssssssssiiissss',
        $datosReta['id_reta'],
        $datosReta['id_equipo1'],
        $datosReta['id_equipo2'],
        $datosReta['nombre_equipo1'],
        $datosReta['nombre_equipo2'],
        $datosReta['id_capitan_equipo1'],
        $datosReta['id_capitan_equipo2'],
        $datosReta['nombre_capitan_equipo1'],
        $datosReta['nombre_capitan_equipo2'],
        $horaRegistro,
        $fechaRegistro,
        $datosReta['fecha_partido'],
        $datosReta['hora_partido'],
        $estado,
        $resultado1,
        $resultado2,
        $vacio,
        $vacio,
        $vacio,
        $datosReta['cancha'],
        $datosReta['direccion']
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function mapaJugadoresValidosResultado($jugadores) {
    $mapa = [];
    foreach ($jugadores as $jugador) {
        $id = trim((string)($jugador['id_retador'] ?? ''));
        if ($id !== '') {
            $mapa[$id] = $jugador;
        }
    }
    return $mapa;
}

function validarAnotadoresResultado($seleccionados, $total, $jugadoresValidos, $nombreEquipo) {
    $salida = [];
    $limpios = [];
    foreach ((array)$seleccionados as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $limpios[] = $id;
        }
    }
    if (count($limpios) !== (int)$total) {
        return [false, [], 'En ' . $nombreEquipo . ' debes seleccionar exactamente ' . (int)$total . ' anotador(es).'];
    }
    foreach ($limpios as $id) {
        if (!isset($jugadoresValidos[$id])) {
            return [false, [], 'Seleccionaste un anotador inválido en ' . $nombreEquipo . '.'];
        }
        $salida[] = [
            'id_retador' => $id,
            'nombre' => $jugadoresValidos[$id]['nombre'] ?? ('Jugador ' . $id)
        ];
    }
    return [true, $salida, ''];
}

function reconstruirJsonGlobalResultado($row) {
    $data = [
        'capitan1' => [
            'resultado_equipo1' => isset($row['resultado_capitan1_equipo1']) ? $row['resultado_capitan1_equipo1'] : null,
            'resultado_equipo2' => isset($row['resultado_capitan1_equipo2']) ? $row['resultado_capitan1_equipo2'] : null,
            'anotadores' => json_decode((string)($row['id_anotadores_capitan1'] ?? ''), true)
        ],
        'capitan2' => [
            'resultado_equipo1' => isset($row['resultado_capitan2_equipo1']) ? $row['resultado_capitan2_equipo1'] : null,
            'resultado_equipo2' => isset($row['resultado_capitan2_equipo2']) ? $row['resultado_capitan2_equipo2'] : null,
            'anotadores' => json_decode((string)($row['id_anotadores_capitan2'] ?? ''), true)
        ]
    ];
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function actualizarEstadoResultadoReta($conn, $idReta) {
    $row = obtenerResultadoExistente($conn, $idReta);
    if (!$row) {
        return false;
    }
    $reg1 = (int)($row['registrado_por_capitan1'] ?? 0) === 1;
    $reg2 = (int)($row['registrado_por_capitan2'] ?? 0) === 1;
    $estado = 'Esperando confirmación';
    $resultadoEquipo1 = (int)($row['resultado_equipo1'] ?? 0);
    $resultadoEquipo2 = (int)($row['resultado_equipo2'] ?? 0);
    if ($reg1 && $reg2) {
        $c1e1 = (int)$row['resultado_capitan1_equipo1'];
        $c1e2 = (int)$row['resultado_capitan1_equipo2'];
        $c2e1 = (int)$row['resultado_capitan2_equipo1'];
        $c2e2 = (int)$row['resultado_capitan2_equipo2'];
        if ($c1e1 === $c2e1 && $c1e2 === $c2e2) {
            $estado = 'Confirmado';
            $resultadoEquipo1 = $c1e1;
            $resultadoEquipo2 = $c1e2;
        } else {
            $estado = 'Resultado en revisión';
        }
    }
    $idAnotadores = reconstruirJsonGlobalResultado($row);
    $quejas = json_encode([
        'capitan1' => $row['quejas_capitan1'] ?? '',
        'capitan2' => $row['quejas_capitan2'] ?? ''
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $expulciones = json_encode([
        'capitan1' => $row['expulciones_capitan1'] ?? '',
        'capitan2' => $row['expulciones_capitan2'] ?? ''
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare("UPDATE r_resultadosretas SET estado = ?, resultado_equipo1 = ?, resultado_equipo2 = ?, id_anotadores = ?, quejas = ?, expulciones = ?, hora_registro = ?, fecha = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $hora = date('H:i:s');
    $fecha = date('Y-m-d');
    $stmt->bind_param('siissssss', $estado, $resultadoEquipo1, $resultadoEquipo2, $idAnotadores, $quejas, $expulciones, $hora, $fecha, $idReta);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok && tablaExisteResultado($conn, 'Agenda')) {
        $estadoAgenda = $estado === 'Confirmado' ? 'Finalizada' : $estado;
        $stmtA = $conn->prepare("UPDATE Agenda SET estado = ? WHERE (CAST(id_partido AS CHAR) = CAST(? AS CHAR) OR CAST(id_categoria AS CHAR) = CAST(? AS CHAR)) AND (LOWER(TRIM(categoria)) = 'reta' OR TRIM(categoria) = '')");
        if ($stmtA) {
            $stmtA->bind_param('sss', $estadoAgenda, $idReta, $idReta);
            $stmtA->execute();
            $stmtA->close();
        }
    }
    return $ok;
}

function guardarRegistroCapitanResultado($conn, $datosReta, $ladoCapitan, $resultado1, $resultado2, $anotadoresJson, $quejas, $expulciones) {
    if (!crearResultadoBaseSiNoExiste($conn, $datosReta)) {
        return [false, 'No se pudo crear el registro base del resultado.'];
    }
    $resultado1 = (int)$resultado1;
    $resultado2 = (int)$resultado2;
    $ahora = date('Y-m-d H:i:s');
    if ((int)$ladoCapitan === 1) {
        $stmt = $conn->prepare("UPDATE r_resultadosretas SET resultado_capitan1_equipo1 = ?, resultado_capitan1_equipo2 = ?, id_anotadores_capitan1 = ?, quejas_capitan1 = ?, expulciones_capitan1 = ?, registrado_por_capitan1 = 1, registro_capitan1_en = ?, resultado_equipo1 = ?, resultado_equipo2 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if (!$stmt) {
            return [false, 'No se pudo preparar el guardado del capitán 1.'];
        }
        $stmt->bind_param('iissssiis', $resultado1, $resultado2, $anotadoresJson, $quejas, $expulciones, $ahora, $resultado1, $resultado2, $datosReta['id_reta']);
    } else {
        $stmt = $conn->prepare("UPDATE r_resultadosretas SET resultado_capitan2_equipo1 = ?, resultado_capitan2_equipo2 = ?, id_anotadores_capitan2 = ?, quejas_capitan2 = ?, expulciones_capitan2 = ?, registrado_por_capitan2 = 1, registro_capitan2_en = ?, resultado_equipo1 = ?, resultado_equipo2 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if (!$stmt) {
            return [false, 'No se pudo preparar el guardado del capitán 2.'];
        }
        $stmt->bind_param('iissssiis', $resultado1, $resultado2, $anotadoresJson, $quejas, $expulciones, $ahora, $resultado1, $resultado2, $datosReta['id_reta']);
    }
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return [false, 'No se pudo guardar el resultado.'];
    }
    actualizarEstadoResultadoReta($conn, $datosReta['id_reta']);
    $row = obtenerResultadoExistente($conn, $datosReta['id_reta']);
    $estado = $row['estado'] ?? 'Esperando confirmación';
    if ($estado === 'Confirmado') {
        return [true, 'Los dos capitanes registraron el mismo resultado. El resultado quedó confirmado.'];
    }
    if ($estado === 'Resultado en revisión') {
        return [true, 'Los resultados de los capitanes no coinciden. La reta quedó en revisión.'];
    }
    return [true, 'Tu resultado fue guardado. Falta que el otro capitán registre su resultado.'];
}

function obtenerDatosCompletosRetaResultado($conn, $idReta) {
    $agenda = obtenerAgendaResultado($conn, $idReta);
    $programada = obtenerProgramadaResultado($conn, $idReta);

    if (!$agenda && !$programada) {
        return null;
    }

    $idEquipo1 = (int)($agenda['id_mi_equipo'] ?? ($programada['id_equipo1'] ?? 0));
    $idEquipo2 = (int)($agenda['id_equipo_rival'] ?? ($programada['id_equipo2'] ?? 0));
    $nombreEquipo1 = $programada['nombre_equipo1'] ?? 'Equipo 1';
    $nombreEquipo2 = $programada['nombre_equipo2'] ?? 'Equipo 2';
    $nombreEquipo1 = obtenerNombreEquipoTempResultado($conn, $idReta, $idEquipo1, $nombreEquipo1);
    $nombreEquipo2 = obtenerNombreEquipoTempResultado($conn, $idReta, $idEquipo2, $nombreEquipo2);
    $capitan1 = obtenerCapitanEquipoTempResultado($conn, $idReta, $idEquipo1);
    $capitan2 = obtenerCapitanEquipoTempResultado($conn, $idReta, $idEquipo2);
    if ($capitan1 === '' && $programada) {
        $capitan1 = trim((string)($programada['capitan_equipo1'] ?? ''));
    }
    if ($capitan2 === '' && $programada) {
        $capitan2 = trim((string)($programada['capitan_equipo2'] ?? ''));
    }
    $fechaPartido = normalizarFechaSQLResultado($agenda['fecha'] ?? ($programada['fecha_reta'] ?? ''), date('Y-m-d'));
    $horaPartido = normalizarHoraSQLResultado($agenda['hora'] ?? ($programada['hora_reta'] ?? ''), '00:00:00');
    $jugadores1 = cargarJugadoresEquipoResultado($conn, $idReta, $idEquipo1, $programada['jugadores_equipo1'] ?? '');
    $jugadores2 = cargarJugadoresEquipoResultado($conn, $idReta, $idEquipo2, $programada['jugadores_equipo2'] ?? '');

    return [
        'id_reta' => (string)$idReta,
        'agenda' => $agenda,
        'programada' => $programada,
        'id_equipo1' => $idEquipo1,
        'id_equipo2' => $idEquipo2,
        'nombre_equipo1' => $nombreEquipo1,
        'nombre_equipo2' => $nombreEquipo2,
        'id_capitan_equipo1' => $capitan1,
        'id_capitan_equipo2' => $capitan2,
        'nombre_capitan_equipo1' => obtenerNombreRetadorResultado($conn, $capitan1),
        'nombre_capitan_equipo2' => obtenerNombreRetadorResultado($conn, $capitan2),
        'fecha_partido' => $fechaPartido,
        'hora_partido' => $horaPartido,
        'cancha' => trim((string)($agenda['cancha'] ?? ($programada['cancha'] ?? 'Cancha por definir'))),
        'direccion' => trim((string)($agenda['direccion'] ?? ($programada['direccion'] ?? ''))),
        'estado_agenda' => trim((string)($agenda['estado'] ?? ($programada['estado_programada'] ?? 'Programada'))),
        'jugadores_equipo1' => $jugadores1,
        'jugadores_equipo2' => $jugadores2
    ];
}

function textoFechaHoraResultado($fecha, $hora) {
    $fechaTxt = trim((string)$fecha) !== '' ? date('d/m/Y', strtotime($fecha)) : 'fecha pendiente';
    $horaTxt = trim((string)$hora) !== '' ? date('H:i', strtotime($hora)) : 'hora pendiente';
    return $fechaTxt . ' · ' . $horaTxt;
}

$Nombre = obtenerSesionResultado($usuarios, ['Nombre', 'nombre'], 'Usuario');
$Apellido = obtenerSesionResultado($usuarios, ['Apellido', 'apellido'], '');
$Id_Retador = obtenerSesionResultado($usuarios, ['Id_Retador', 'id_retador', 'Id_Usuario', 'id_usuario'], '');
$ModoPerfil = obtenerSesionResultado($usuarios, ['ModoPerfil', 'modoPerfil'], '');

if ($Id_Retador === '') {
    header('Location: login.php');
    exit();
}

$stmtPerfil = $conn->prepare("SELECT Nombre, Apellido, ModoPerfil FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
if ($stmtPerfil) {
    $stmtPerfil->bind_param('s', $Id_Retador);
    $stmtPerfil->execute();
    $resPerfil = $stmtPerfil->get_result();
    if ($filaPerfil = $resPerfil->fetch_assoc()) {
        if (trim((string)($filaPerfil['Nombre'] ?? '')) !== '') {
            $Nombre = trim((string)$filaPerfil['Nombre']);
        }
        if (trim((string)($filaPerfil['Apellido'] ?? '')) !== '') {
            $Apellido = trim((string)$filaPerfil['Apellido']);
        }
        $ModoPerfil = trim((string)($filaPerfil['ModoPerfil'] ?? ''));
        $_SESSION['usuario_data']['ModoPerfil'] = $ModoPerfil;
    }
    $stmtPerfil->close();
}

$modoOscuroActivo = normalizarTextoResultado($ModoPerfil) === 'modo oscuro';
$idReta = isset($_GET['id_reta']) ? trim((string)$_GET['id_reta']) : '';
if (isset($_POST['id_reta'])) {
    $idReta = trim((string)$_POST['id_reta']);
}

$errores = [];
$mensajeOk = '';
$mostrarFormulario = false;
$datosReta = null;
$resultadoActual = null;
$ladoCapitanActual = 0;

if ($idReta === '') {
    $errores[] = 'No se recibió la ID de la reta.';
} else {
    if (!asegurarTablaResultadosRetas($conn)) {
        $errores[] = 'No se pudo crear o revisar la tabla r_resultadosretas.';
    }
    $datosReta = obtenerDatosCompletosRetaResultado($conn, $idReta);
    if (!$datosReta) {
        $errores[] = 'No se encontró la reta en Agenda.';
    }
}

if ($datosReta) {
    if ((string)$Id_Retador === (string)$datosReta['id_capitan_equipo1']) {
        $ladoCapitanActual = 1;
    } elseif ((string)$Id_Retador === (string)$datosReta['id_capitan_equipo2']) {
        $ladoCapitanActual = 2;
    }

    if ($ladoCapitanActual === 0) {
        $errores[] = 'Solo los capitanes pueden registrar el resultado de esta reta.';
    }

    if (!resultadoDisponibleResultado($datosReta['fecha_partido'], $datosReta['hora_partido'])) {
        $errores[] = 'Todavía no llega la fecha y hora programada de esta reta.';
    }

    $resultadoActual = obtenerResultadoExistente($conn, $idReta);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'elegir_finalizar') {
    $mostrarFormulario = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_resultado' && $datosReta && empty($errores)) {
    $resultadoActual = obtenerResultadoExistente($conn, $idReta);
    if ($resultadoActual && ($resultadoActual['estado'] ?? '') === 'Confirmado') {
        $errores[] = 'Este resultado ya fue confirmado por ambos capitanes.';
    } else {
        $resultadoEquipo1 = isset($_POST['resultado_equipo1']) ? (int)$_POST['resultado_equipo1'] : -1;
        $resultadoEquipo2 = isset($_POST['resultado_equipo2']) ? (int)$_POST['resultado_equipo2'] : -1;
        $usarAnotadores = isset($_POST['usar_anotadores']) && (string)$_POST['usar_anotadores'] === '1';
        $quejas = isset($_POST['quejas']) ? trim((string)$_POST['quejas']) : '';
        $expulciones = isset($_POST['expulciones']) ? trim((string)$_POST['expulciones']) : '';

        if ($resultadoEquipo1 < 0 || $resultadoEquipo2 < 0) {
            $errores[] = 'Los resultados no pueden ser negativos.';
        }

        $anotadoresData = [
            'registrado_por' => $Id_Retador,
            'lado_capitan' => $ladoCapitanActual,
            'equipo1' => [],
            'equipo2' => [],
            'usado' => $usarAnotadores
        ];

        if ($usarAnotadores && empty($errores)) {
            $mapaEquipo1 = mapaJugadoresValidosResultado($datosReta['jugadores_equipo1']);
            $mapaEquipo2 = mapaJugadoresValidosResultado($datosReta['jugadores_equipo2']);
            [$okA1, $listaA1, $errorA1] = validarAnotadoresResultado($_POST['anotadores_equipo1'] ?? [], $resultadoEquipo1, $mapaEquipo1, $datosReta['nombre_equipo1']);
            [$okA2, $listaA2, $errorA2] = validarAnotadoresResultado($_POST['anotadores_equipo2'] ?? [], $resultadoEquipo2, $mapaEquipo2, $datosReta['nombre_equipo2']);
            if (!$okA1) {
                $errores[] = $errorA1;
            }
            if (!$okA2) {
                $errores[] = $errorA2;
            }
            if ($okA1 && $okA2) {
                $anotadoresData['equipo1'] = $listaA1;
                $anotadoresData['equipo2'] = $listaA2;
            }
        }

        if (empty($errores)) {
            $anotadoresJson = json_encode($anotadoresData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            [$okGuardar, $mensajeGuardar] = guardarRegistroCapitanResultado($conn, $datosReta, $ladoCapitanActual, $resultadoEquipo1, $resultadoEquipo2, $anotadoresJson, $quejas, $expulciones);
            if ($okGuardar) {
                $mensajeOk = $mensajeGuardar;
                $mostrarFormulario = false;
                $resultadoActual = obtenerResultadoExistente($conn, $idReta);
            } else {
                $errores[] = $mensajeGuardar;
                $mostrarFormulario = true;
            }
        } else {
            $mostrarFormulario = true;
        }
    }
}

if ($resultadoActual && ($resultadoActual['estado'] ?? '') !== 'Confirmado' && $mostrarFormulario === false && isset($_GET['editar'])) {
    $mostrarFormulario = true;
}

$prefillEquipo1 = 0;
$prefillEquipo2 = 0;
if ($resultadoActual) {
    if ($ladoCapitanActual === 1 && (int)($resultadoActual['registrado_por_capitan1'] ?? 0) === 1) {
        $prefillEquipo1 = (int)($resultadoActual['resultado_capitan1_equipo1'] ?? 0);
        $prefillEquipo2 = (int)($resultadoActual['resultado_capitan1_equipo2'] ?? 0);
    } elseif ($ladoCapitanActual === 2 && (int)($resultadoActual['registrado_por_capitan2'] ?? 0) === 1) {
        $prefillEquipo1 = (int)($resultadoActual['resultado_capitan2_equipo1'] ?? 0);
        $prefillEquipo2 = (int)($resultadoActual['resultado_capitan2_equipo2'] ?? 0);
    }
}

$jugadoresEquipo1JS = $datosReta ? $datosReta['jugadores_equipo1'] : [];
$jugadoresEquipo2JS = $datosReta ? $datosReta['jugadores_equipo2'] : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registrar resultado - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
:root{--azul:#1877f2;--azul2:#0ea5e9;--rojo:#ff4b5c;--rojo2:#ff2f45;--texto:#111827;--gris:#6b7280;--fondo:#f0f2f5;--cyan:#8fefff;--sidebar:280px}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100dvh;font-family:'Poppins',sans-serif;color:var(--texto);background:var(--fondo);overflow-x:hidden;padding-bottom:96px}
.bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.20),transparent 410px),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
.topbar{position:fixed;top:0;left:0;right:0;height:74px;z-index:900;display:flex;align-items:center;gap:14px;padding:12px clamp(14px,4vw,32px);background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:3px solid rgba(0,153,255,.88);box-shadow:0 4px 18px rgba(0,0,0,.07)}
.menu-btn{width:48px;height:48px;border-radius:16px;border:2px solid rgba(0,153,255,.42);background:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#111827;font-size:22px;font-weight:900}
.topbar-title{flex:1;min-width:0}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.05rem,3vw,1.7rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-user{padding:9px 16px;border-radius:999px;background:#fff;box-shadow:0 6px 15px rgba(0,0,0,.08);font-weight:900;color:#374151;white-space:nowrap}
.main-content{position:relative;z-index:2;padding:104px clamp(14px,4vw,42px) 120px}.wrapper{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:20px}.hero,.card{border-radius:30px;background:rgba(255,255,255,.95);border:1px solid rgba(17,24,39,.06);box-shadow:0 16px 34px rgba(0,0,0,.10),0 0 18px rgba(0,153,255,.14);padding:clamp(20px,4vw,34px);position:relative;overflow:hidden}.hero:before,.card:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 12% 18%,rgba(24,119,242,.22),transparent 260px),radial-gradient(circle at 88% 82%,rgba(255,75,92,.18),transparent 290px);pointer-events:none}.hero>*,.card>*{position:relative;z-index:1}.chip{display:inline-flex;align-items:center;gap:8px;width:max-content;max-width:100%;padding:9px 14px;border-radius:999px;background:#fff;border:2px solid rgba(0,153,255,.35);color:#1877f2;font-size:13px;font-weight:1000;margin-bottom:12px}.hero h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.55rem,5vw,2.45rem);margin-bottom:8px}.hero p{max-width:900px;color:#4b5563;line-height:1.65;font-size:.98rem}.grid-info{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:18px}.dato{border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.20);padding:13px 14px;box-shadow:0 8px 18px rgba(0,0,0,.05)}.dato span{display:block;color:#6b7280;font-size:11px;font-weight:1000;text-transform:uppercase;margin-bottom:5px}.dato strong{color:#111827;font-size:14px}.alerta{border-radius:18px;padding:14px 16px;font-weight:900;box-shadow:0 10px 22px rgba(0,0,0,.06)}.alerta.ok{background:#f0fdf4;border:2px solid rgba(34,197,94,.28);color:#166534}.alerta.error{background:#fff5f7;border:2px solid rgba(255,75,92,.38);color:#b91c1c}.acciones{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.btn{min-height:48px;border:0;text-decoration:none;border-radius:17px;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;font-size:13px;font-weight:1000;cursor:pointer;transition:.25s ease;font-family:'Poppins',sans-serif}.btn:hover{transform:translateY(-2px)}.btn-primary{background:linear-gradient(135deg,var(--rojo),var(--rojo2));color:#fff}.btn-blue{background:linear-gradient(135deg,var(--azul),var(--azul2));color:#fff}.btn-gray{background:#e5e7eb;color:#111827}.estado-box{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.estado-item{border-radius:20px;background:#fff;border:2px solid rgba(0,153,255,.20);padding:14px}.estado-item span{display:block;font-size:12px;font-weight:1000;color:#6b7280;margin-bottom:5px}.estado-item strong{display:block;color:#111827}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.campo{display:flex;flex-direction:column;gap:7px}.campo label{font-weight:1000;color:#374151;font-size:13px}.campo input,.campo select,.campo textarea{width:100%;min-height:48px;border-radius:16px;border:2px solid rgba(0,153,255,.24);background:#fff;color:#111827;padding:10px 13px;font-family:'Poppins',sans-serif;font-weight:800;outline:none}.campo textarea{min-height:94px;resize:vertical}.full{grid-column:1/-1}.anotadores-panel{display:none;margin-top:14px;border-radius:24px;border:2px solid rgba(0,153,255,.22);background:rgba(24,119,242,.05);padding:16px}.anotadores-panel.activo{display:block}.anotadores-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.selects-list{display:flex;flex-direction:column;gap:8px;margin-top:8px}.ayuda{display:block;color:#6b7280;font-weight:800;font-size:12px;line-height:1.45;margin-top:5px}.lista-jugadores{display:flex;flex-direction:column;gap:8px}.jugador-linea{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border-radius:15px;background:#fff;border:1px solid rgba(24,119,242,.14);font-size:12px;font-weight:900;color:#374151}.bottom-nav{position:fixed;left:0;right:0;bottom:0;height:84px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);padding:8px 12px;border-radius:22px 22px 0 0;background:#fff;border-top:2px solid rgba(0,153,255,.72);box-shadow:0 -6px 18px rgba(0,0,0,.06)}.bottom-nav a{display:flex;align-items:center;justify-content:center;border-radius:16px;position:relative}.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain}.bottom-nav a.active:after{content:"";position:absolute;width:54px;height:54px;border-radius:15px;border:2px solid rgba(255,75,92,.98);box-shadow:0 0 0 2px rgba(0,153,255,.98)}
body.dark-mode{background:#0b1220;color:#e5e7eb}body.dark-mode .bg-particles{background:linear-gradient(135deg,#0b1220 0%,#111827 45%,#1f1117 100%)}body.dark-mode .topbar,body.dark-mode .hero,body.dark-mode .card,body.dark-mode .bottom-nav{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.70);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.18)}body.dark-mode .menu-btn,body.dark-mode .topbar-user,body.dark-mode .chip,body.dark-mode .dato,body.dark-mode .estado-item,body.dark-mode .campo input,body.dark-mode .campo select,body.dark-mode .campo textarea,body.dark-mode .jugador-linea{background:#0b1220;color:#e5e7eb;border-color:rgba(0,153,255,.35)}body.dark-mode .topbar-title h1,body.dark-mode .hero h2{color:var(--cyan)}body.dark-mode .hero p,body.dark-mode .dato span,body.dark-mode .dato strong,body.dark-mode .estado-item span,body.dark-mode .estado-item strong,body.dark-mode .campo label,body.dark-mode .ayuda,body.dark-mode .jugador-linea{color:#e5e7eb}body.dark-mode .btn-gray{background:#243044;color:#e5e7eb}body.dark-mode .anotadores-panel{background:rgba(14,165,233,.08);border-color:rgba(0,153,255,.32)}body.dark-mode .bottom-nav a img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05)}
@media(max-width:900px){.grid-info{grid-template-columns:repeat(2,1fr)}.estado-box,.anotadores-grid{grid-template-columns:1fr}}@media(max-width:620px){.topbar-user{display:none}.main-content{padding-top:94px}.grid-info,.form-grid{grid-template-columns:1fr}.bottom-nav{height:76px;padding:6px 4px}.bottom-nav a img{width:34px;height:34px}.bottom-nav a.active:after{width:44px;height:44px}}
</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<header class="topbar">
    <a href="Agenda.php" class="menu-btn">←</a>
    <div class="topbar-title"><h1>Registrar resultado</h1></div>
    <div class="topbar-user">👤 <?php echo limpiarTexto($Nombre); ?></div>
</header>

<main class="main-content">
<section class="wrapper">
    <div class="hero">
        <span class="chip">🏁 Resultado de reta</span>
        <h2><?php echo $datosReta ? limpiarTexto($datosReta['nombre_equipo1'] . ' vs ' . $datosReta['nombre_equipo2']) : 'Registrar resultado'; ?></h2>
        <p>Primero el capitán elige finalizar. Después registra el marcador. Cuando el otro capitán registre el mismo marcador, el sistema confirma automáticamente el resultado. Si no coincide, queda en revisión.</p>
        <?php if ($datosReta): ?>
            <div class="grid-info">
                <div class="dato"><span>ID reta</span><strong><?php echo limpiarTexto($idReta); ?></strong></div>
                <div class="dato"><span>Fecha y hora</span><strong><?php echo limpiarTexto(textoFechaHoraResultado($datosReta['fecha_partido'], $datosReta['hora_partido'])); ?></strong></div>
                <div class="dato"><span>Cancha</span><strong><?php echo limpiarTexto($datosReta['cancha']); ?></strong></div>
                <div class="dato"><span>Tu rol</span><strong><?php echo $ladoCapitanActual === 1 ? 'Capitán equipo 1' : ($ladoCapitanActual === 2 ? 'Capitán equipo 2' : 'Sin permiso'); ?></strong></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($mensajeOk !== ''): ?><div class="alerta ok">✅ <?php echo limpiarTexto($mensajeOk); ?></div><?php endif; ?>
    <?php if (!empty($errores)): ?><div class="alerta error"><?php foreach ($errores as $e): ?><div>⚠️ <?php echo limpiarTexto($e); ?></div><?php endforeach; ?></div><?php endif; ?>

    <?php if ($datosReta && empty($errores)): ?>
        <div class="card">
            <span class="chip">📌 Estado actual</span>
            <div class="estado-box">
                <div class="estado-item"><span>Estado</span><strong><?php echo limpiarTexto($resultadoActual['estado'] ?? 'Sin registrar'); ?></strong></div>
                <div class="estado-item"><span><?php echo limpiarTexto($datosReta['nombre_equipo1']); ?></span><strong><?php echo isset($resultadoActual['resultado_equipo1']) ? (int)$resultadoActual['resultado_equipo1'] : 0; ?></strong></div>
                <div class="estado-item"><span><?php echo limpiarTexto($datosReta['nombre_equipo2']); ?></span><strong><?php echo isset($resultadoActual['resultado_equipo2']) ? (int)$resultadoActual['resultado_equipo2'] : 0; ?></strong></div>
            </div>
            <?php if ($resultadoActual): ?>
                <div class="estado-box" style="margin-top:12px">
                    <div class="estado-item"><span>Capitán equipo 1</span><strong><?php echo ((int)($resultadoActual['registrado_por_capitan1'] ?? 0) === 1) ? 'Registrado' : 'Pendiente'; ?></strong></div>
                    <div class="estado-item"><span>Capitán equipo 2</span><strong><?php echo ((int)($resultadoActual['registrado_por_capitan2'] ?? 0) === 1) ? 'Registrado' : 'Pendiente'; ?></strong></div>
                    <div class="estado-item"><span>Confirmación</span><strong><?php echo limpiarTexto($resultadoActual['estado'] ?? 'Pendiente'); ?></strong></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$resultadoActual || ($resultadoActual['estado'] ?? '') !== 'Confirmado'): ?>
            <?php if (!$mostrarFormulario): ?>
                <div class="card">
                    <span class="chip">✅ Elige una opción</span>
                    <h3 style="font-family:Orbitron,sans-serif;color:var(--azul);margin-bottom:10px">¿Qué quieres hacer?</h3>
                    <p class="ayuda" style="margin-bottom:16px">Usa esta opción cuando la reta ya terminó y quieras registrar el marcador final.</p>
                    <form method="POST" action="RegistrarResultado.php?id_reta=<?php echo urlencode((string)$idReta); ?>">
                        <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                        <input type="hidden" name="accion" value="elegir_finalizar">
                        <div class="acciones">
                            <button type="submit" class="btn btn-primary">1 - Finalizar</button>
                            <a href="Agenda.php" class="btn btn-gray">Volver</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="card">
                    <span class="chip">📝 Finalizar reta</span>
                    <form method="POST" action="RegistrarResultado.php?id_reta=<?php echo urlencode((string)$idReta); ?>" id="formResultado">
                        <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                        <input type="hidden" name="accion" value="guardar_resultado">
                        <div class="form-grid">
                            <div class="campo">
                                <label>Resultado <?php echo limpiarTexto($datosReta['nombre_equipo1']); ?></label>
                                <input type="number" min="0" name="resultado_equipo1" id="resultadoEquipo1" value="<?php echo (int)$prefillEquipo1; ?>" required>
                            </div>
                            <div class="campo">
                                <label>Resultado <?php echo limpiarTexto($datosReta['nombre_equipo2']); ?></label>
                                <input type="number" min="0" name="resultado_equipo2" id="resultadoEquipo2" value="<?php echo (int)$prefillEquipo2; ?>" required>
                            </div>
                            <div class="campo full">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                    <input type="checkbox" name="usar_anotadores" value="1" id="usarAnotadores" style="width:20px;height:20px;min-height:20px">
                                    Registrar anotadores ahora
                                </label>
                                <small class="ayuda">No es obligatorio. Si lo activas, debes seleccionar exactamente la misma cantidad de anotadores que el marcador de cada equipo. Puedes repetir al mismo jugador si anotó varias veces.</small>
                            </div>
                        </div>

                        <div class="anotadores-panel" id="anotadoresPanel">
                            <div class="anotadores-grid">
                                <div>
                                    <h3 style="font-family:Orbitron,sans-serif;color:var(--azul);font-size:1rem"><?php echo limpiarTexto($datosReta['nombre_equipo1']); ?></h3>
                                    <small class="ayuda" id="ayudaEquipo1"></small>
                                    <div class="selects-list" id="selectsEquipo1"></div>
                                </div>
                                <div>
                                    <h3 style="font-family:Orbitron,sans-serif;color:var(--azul);font-size:1rem"><?php echo limpiarTexto($datosReta['nombre_equipo2']); ?></h3>
                                    <small class="ayuda" id="ayudaEquipo2"></small>
                                    <div class="selects-list" id="selectsEquipo2"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid" style="margin-top:16px">
                            <div class="campo">
                                <label>Quejas o detalles opcionales</label>
                                <textarea name="quejas" placeholder="Ejemplo: hubo una jugada discutida, marcador acordado por ambos, etc."></textarea>
                            </div>
                            <div class="campo">
                                <label>Expulsiones opcionales</label>
                                <textarea name="expulciones" placeholder="Escribe si hubo expulsiones o deja vacío."></textarea>
                            </div>
                        </div>

                        <div class="acciones" style="margin-top:18px">
                            <button type="submit" class="btn btn-primary">Guardar resultado</button>
                            <a href="RegistrarResultado.php?id_reta=<?php echo urlencode((string)$idReta); ?>" class="btn btn-gray">Cancelar</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card">
                <span class="chip">🏆 Resultado confirmado</span>
                <p class="ayuda">Los dos capitanes registraron el mismo marcador. Ya no se puede modificar desde esta pantalla.</p>
                <div class="acciones" style="margin-top:14px"><a href="Agenda.php" class="btn btn-blue">Volver a agenda</a></div>
            </div>
        <?php endif; ?>

        <div class="card">
            <span class="chip">👥 Jugadores disponibles</span>
            <div class="anotadores-grid">
                <div>
                    <h3 style="font-family:Orbitron,sans-serif;color:var(--azul);font-size:1rem;margin-bottom:10px"><?php echo limpiarTexto($datosReta['nombre_equipo1']); ?></h3>
                    <div class="lista-jugadores">
                        <?php if (empty($datosReta['jugadores_equipo1'])): ?><div class="jugador-linea"><span>No hay jugadores cargados.</span></div><?php endif; ?>
                        <?php foreach ($datosReta['jugadores_equipo1'] as $j): ?>
                            <div class="jugador-linea"><span><?php echo limpiarTexto($j['nombre']); ?></span><span><?php echo trim((string)$j['numero_jugador']) !== '' ? '#' . limpiarTexto($j['numero_jugador']) : limpiarTexto($j['id_retador']); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h3 style="font-family:Orbitron,sans-serif;color:var(--azul);font-size:1rem;margin-bottom:10px"><?php echo limpiarTexto($datosReta['nombre_equipo2']); ?></h3>
                    <div class="lista-jugadores">
                        <?php if (empty($datosReta['jugadores_equipo2'])): ?><div class="jugador-linea"><span>No hay jugadores cargados.</span></div><?php endif; ?>
                        <?php foreach ($datosReta['jugadores_equipo2'] as $j): ?>
                            <div class="jugador-linea"><span><?php echo limpiarTexto($j['nombre']); ?></span><span><?php echo trim((string)$j['numero_jugador']) !== '' ? '#' . limpiarTexto($j['numero_jugador']) : limpiarTexto($j['id_retador']); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
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
const jugadoresEquipo1 = <?php echo json_encode($jugadoresEquipo1JS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const jugadoresEquipo2 = <?php echo json_encode($jugadoresEquipo2JS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const usarAnotadores = document.getElementById('usarAnotadores');
const panel = document.getElementById('anotadoresPanel');
const input1 = document.getElementById('resultadoEquipo1');
const input2 = document.getElementById('resultadoEquipo2');
const cont1 = document.getElementById('selectsEquipo1');
const cont2 = document.getElementById('selectsEquipo2');
const ayuda1 = document.getElementById('ayudaEquipo1');
const ayuda2 = document.getElementById('ayudaEquipo2');
const formResultado = document.getElementById('formResultado');
function crearSelect(nombre, jugadores, indice){
    const select = document.createElement('select');
    select.name = nombre;
    select.required = true;
    const op0 = document.createElement('option');
    op0.value = '';
    op0.textContent = 'Selecciona anotador ' + indice;
    select.appendChild(op0);
    jugadores.forEach(j => {
        const op = document.createElement('option');
        op.value = j.id_retador;
        const numero = j.numero_jugador ? ' #' + j.numero_jugador : '';
        op.textContent = j.nombre + numero;
        select.appendChild(op);
    });
    return select;
}
function construirSelects(contenedor, ayuda, total, nombre, jugadores){
    if(!contenedor){return}
    contenedor.innerHTML = '';
    total = Number(total || 0);
    if(total < 0){total = 0}
    ayuda.textContent = total === 0 ? 'Este equipo no anotó. No debes seleccionar anotadores.' : 'Debes seleccionar exactamente ' + total + ' anotador(es).';
    for(let i=1;i<=total;i++){
        contenedor.appendChild(crearSelect(nombre, jugadores, i));
    }
}
function actualizarAnotadores(){
    if(!panel || !usarAnotadores){return}
    const activo = usarAnotadores.checked;
    panel.classList.toggle('activo', activo);
    if(activo){
        construirSelects(cont1, ayuda1, input1 ? input1.value : 0, 'anotadores_equipo1[]', jugadoresEquipo1);
        construirSelects(cont2, ayuda2, input2 ? input2.value : 0, 'anotadores_equipo2[]', jugadoresEquipo2);
    }else{
        if(cont1){cont1.innerHTML = ''}
        if(cont2){cont2.innerHTML = ''}
    }
}
if(usarAnotadores){usarAnotadores.addEventListener('change', actualizarAnotadores)}
if(input1){input1.addEventListener('input', actualizarAnotadores)}
if(input2){input2.addEventListener('input', actualizarAnotadores)}
if(formResultado){
    formResultado.addEventListener('submit', function(e){
        if(!usarAnotadores || !usarAnotadores.checked){return}
        const vacios = formResultado.querySelectorAll('.anotadores-panel select:invalid');
        if(vacios.length > 0){
            e.preventDefault();
            alert('Selecciona exactamente los anotadores que corresponden al marcador registrado.');
        }
    });
}
actualizarAnotadores();
</script>
</body>
</html>
