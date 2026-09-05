<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];

function limpiarTexto($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerSesion($datos, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return $default;
}

function normalizarTexto($valor) {
    $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
    $valor = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $valor);
    return $valor;
}

function obtenerRutaFotoPerfil($foto) {
    $foto = trim(str_replace('\\', '/', (string)$foto));

    if ($foto === '') {
        return '../assets/doctor.png';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $foto)) {
        return $foto;
    }

    if (strpos($foto, '../') === 0 || strpos($foto, './') === 0) {
        return $foto;
    }

    $rutas = [
        '../' . ltrim($foto, '/'),
        '../Imagenes/' . ltrim($foto, '/'),
        '../uploads/' . ltrim($foto, '/'),
        '../FotosPerfil/' . ltrim($foto, '/'),
        '../assets/' . ltrim($foto, '/'),
        '../img/' . ltrim($foto, '/')
    ];

    foreach ($rutas as $ruta) {
        if (file_exists(__DIR__ . '/' . $ruta)) {
            return $ruta;
        }
    }

    return '../' . ltrim($foto, '/');
}

function obtenerColumnasTabla($conn, $tabla) {
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

function buscarColumna($columnas, $posibles) {
    foreach ($posibles as $columna) {
        if (isset($columnas[$columna])) {
            return $columna;
        }
    }

    $mapa = [];
    foreach ($columnas as $columna => $_) {
        $mapa[normalizarTexto($columna)] = $columna;
    }

    foreach ($posibles as $columna) {
        $normalizada = normalizarTexto($columna);
        if (isset($mapa[$normalizada])) {
            return $mapa[$normalizada];
        }
    }

    return '';
}

function asegurarCampoIdRetaTemp($conn) {
    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');

    if (isset($columnas['id_reta'])) {
        return true;
    }

    $conn->query("ALTER TABLE r_equipotemp ADD COLUMN id_reta VARCHAR(120) NOT NULL DEFAULT '' AFTER id_equipo");
    $conn->query("ALTER TABLE r_equipotemp ADD INDEX idx_r_equipotemp_id_reta (id_reta)");
    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');

    return isset($columnas['id_reta']);
}

function asegurarCampoOrdenUnionTemp($conn) {
    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');

    if (isset($columnas['orden_union'])) {
        return true;
    }

    $conn->query("ALTER TABLE r_equipotemp ADD COLUMN orden_union INT NOT NULL DEFAULT 0 AFTER numero_jugador");
    $conn->query("ALTER TABLE r_equipotemp ADD INDEX idx_r_equipotemp_orden_union (id_reta, id_equipo, orden_union)");
    $conn->query("UPDATE r_equipotemp SET orden_union = numero_jugador WHERE orden_union = 0");

    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');

    return isset($columnas['orden_union']);
}

function siguienteOrdenUnionEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion) {
    if (!$usarOrdenUnion) {
        return contarJugadoresEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta) + 1;
    }

    $orden = 0;

    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(orden_union), 0) AS orden FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ?");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(orden_union), 0) AS orden FROM r_equipotemp WHERE id_equipo = ?");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $orden = (int)$fila['orden'];
        }
        $stmt->close();
    }

    return $orden + 1;
}

function cargarRetadorSesion($conn, $idRetador, $usuarios) {
    $datos = [
        'Id_Retador' => $idRetador,
        'Nombre' => obtenerSesion($usuarios, ['Nombre', 'nombre'], 'Usuario'),
        'Apellido' => obtenerSesion($usuarios, ['Apellido', 'apellido'], ''),
        'CodigoPostal' => obtenerSesion($usuarios, ['CodigoPostal', 'Codigo_Postal', 'codigo_postal', 'codigoPostal', 'CP', 'cp'], ''),
        'FotoPerfil' => obtenerSesion($usuarios, ['FotoPerfil', 'fotoPerfil', 'foto_perfil'], ''),
        'ModoPerfil' => obtenerSesion($usuarios, ['ModoPerfil', 'modoPerfil'], ''),
        'Rango' => obtenerSesion($usuarios, ['Rango', 'rango', 'Rango_Estrellas'], '')
    ];

    $stmt = $conn->prepare("SELECT Id_Retador, Nombre, Apellido, CodigoPostal, FotoPerfil, ModoPerfil, Rango_Estrellas FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if ($stmt) {
        $stmt->bind_param('s', $idRetador);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($fila = $res->fetch_assoc()) {
            foreach ($fila as $k => $v) {
                if ($v !== null && trim((string)$v) !== '') {
                    if ($k === 'Rango_Estrellas') {
                        $datos['Rango'] = trim((string)$v);
                    } else {
                        $datos[$k] = trim((string)$v);
                    }
                }
            }
        }

        $stmt->close();
    }

    return $datos;
}

function cargarRetaPublicada($conn, $idReta) {
    $columnas = obtenerColumnasTabla($conn, 'r_retaspublicadas');
    $idColumna = buscarColumna($columnas, ['id_reta', 'Id_Reta', 'ID_RETA', 'Id_RetaPublicada', 'id_reta_publicada', 'ID_RETA_PUBLICADA', 'id', 'Id', 'ID']);

    if ($idColumna === '') {
        return null;
    }

    $sql = "SELECT * FROM r_retaspublicadas WHERE CAST(`$idColumna` AS CHAR) = CAST(? AS CHAR) LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $idReta = trim((string)$idReta);
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($fila) {
        $fila['_id_columna_reta'] = $idColumna;
    }

    return $fila ? $fila : null;
}

function datoReta($reta, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($reta[$llave]) && trim((string)$reta[$llave]) !== '') {
            return trim((string)$reta[$llave]);
        }
    }
    return $default;
}

function nombreEquipoReta($reta, $lado) {
    if ($lado === 1) {
        return datoReta($reta, ['equipo1', 'Equipo1', 'equipo_1', 'Equipo_1', 'Nombre_Equipo1', 'nombre_equipo1'], 'Equipo 1');
    }

    return datoReta($reta, ['equipo2', 'Equipo2', 'equipo_2', 'Equipo_2', 'Nombre_Equipo2', 'nombre_equipo2'], 'Equipo 2');
}

function columnaNombreEquipoReta($conn, $lado) {
    $columnas = obtenerColumnasTabla($conn, 'r_retaspublicadas');

    if ($lado === 1) {
        return buscarColumna($columnas, ['equipo1', 'Equipo1', 'equipo_1', 'Equipo_1', 'Nombre_Equipo1', 'nombre_equipo1']);
    }

    return buscarColumna($columnas, ['equipo2', 'Equipo2', 'equipo_2', 'Equipo_2', 'Nombre_Equipo2', 'nombre_equipo2']);
}

function condicionRetaTemp($usarIdReta) {
    return $usarIdReta ? 'id_reta = ? AND id_equipo = ?' : 'id_equipo = ?';
}

function bindRetaEquipo($stmt, $usarIdReta, $idReta, $idEquipo) {
    if ($usarIdReta) {
        $stmt->bind_param('si', $idReta, $idEquipo);
    } else {
        $stmt->bind_param('i', $idEquipo);
    }
}

function obtenerMembresiaJugador($conn, $idReta, $idRetador, $idEquipo1, $idEquipo2, $usarIdReta) {
    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_reta = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $idReta, $idRetador);
        }
    } else {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE CAST(id_retador AS CHAR) = CAST(? AS CHAR) AND id_equipo IN (?, ?) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sii', $idRetador, $idEquipo1, $idEquipo2);
        }
    }

    if (!$stmt) {
        return null;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $fila ? $fila : null;
}

function contarJugadoresEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta) {
    $total = 0;

    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_equipo = ? AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $total = (int)$fila['total'];
        }
        $stmt->close();
    }

    return $total;
}

function capitanEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta) {
    $capitan = '';

    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT capitan FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND capitan IS NOT NULL AND TRIM(capitan) <> '' AND CAST(capitan AS CHAR) <> '0' AND CAST(id_retador AS CHAR) <> '0' AND CAST(id_retador AS CHAR) <> '' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT capitan FROM r_equipotemp WHERE id_equipo = ? AND capitan IS NOT NULL AND TRIM(capitan) <> '' AND CAST(capitan AS CHAR) <> '0' AND CAST(id_retador AS CHAR) <> '0' AND CAST(id_retador AS CHAR) <> '' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $capitan = trim((string)$fila['capitan']);
        }
        $stmt->close();
    }

    return $capitan;
}

function usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipo, $idRetador, $usarIdReta) {
    $capitan = capitanEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta);
    return $capitan !== '' && (string)$capitan === (string)$idRetador;
}

function nombreActualEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta, $default) {
    $nombre = '';

    if ($usarIdReta) {
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

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $nombre = trim((string)$fila['nombre']);
        }
        $stmt->close();
    }

    return $nombre !== '' ? $nombre : $default;
}


function salaTieneEquiposTemporales($conn, $idReta, $idEquipo1, $idEquipo2, $usarIdReta) {
    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_reta = ? AND id_equipo IN (?, ?)");
        if ($stmt) {
            $stmt->bind_param('sii', $idReta, $idEquipo1, $idEquipo2);
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_equipo IN (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ii', $idEquipo1, $idEquipo2);
        }
    }

    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0;

    if ($fila = $res->fetch_assoc()) {
        $total = (int)$fila['total'];
    }

    $stmt->close();
    return $total > 0;
}

function eliminarPlaceholderEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta) {
    if ($usarIdReta) {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND (CAST(id_retador AS CHAR) = '0' OR TRIM(CAST(id_retador AS CHAR)) = '')");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_equipo = ? AND (CAST(id_retador AS CHAR) = '0' OR TRIM(CAST(id_retador AS CHAR)) = '')");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function insertarJugadorTemp($conn, $idReta, $reta, $idEquipo, $lado, $retador, $capitan, $usarIdReta, $usarOrdenUnion = false) {
    $nombreEquipo = nombreActualEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta, nombreEquipoReta($reta, $lado));
    $numeroJugador = contarJugadoresEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta) + 1;
    $ordenUnion = siguienteOrdenUnionEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion);
    $idRetador = trim((string)$retador['Id_Retador']);
    $nombreRetador = trim((string)$retador['Nombre']);
    $apellidoRetador = trim((string)$retador['Apellido']);
    $codigoPostal = trim((string)$retador['CodigoPostal']);
    $rango = trim((string)$retador['Rango']);
    $posicion = '';
    $estadoJugador = ((string)$capitan === (string)$idRetador) ? 'Capitán' : 'En espera';
    $resultadosJugador = '';
    $quejas = '';

    eliminarPlaceholderEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta);

    if ($usarIdReta && $usarOrdenUnion) {
        $sql = "INSERT INTO r_equipotemp (id_equipo, id_reta, nombre, capitan, id_retador, nombre_retador, apellido_retador, codigo_postal, numero_jugador, orden_union, posicion, estado_jugador, resultados_jugador, quejas, rango) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isssssssiisssss', $idEquipo, $idReta, $nombreEquipo, $capitan, $idRetador, $nombreRetador, $apellidoRetador, $codigoPostal, $numeroJugador, $ordenUnion, $posicion, $estadoJugador, $resultadosJugador, $quejas, $rango);
    } elseif ($usarIdReta) {
        $sql = "INSERT INTO r_equipotemp (id_equipo, id_reta, nombre, capitan, id_retador, nombre_retador, apellido_retador, codigo_postal, numero_jugador, posicion, estado_jugador, resultados_jugador, quejas, rango) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isssssssisssss', $idEquipo, $idReta, $nombreEquipo, $capitan, $idRetador, $nombreRetador, $apellidoRetador, $codigoPostal, $numeroJugador, $posicion, $estadoJugador, $resultadosJugador, $quejas, $rango);
    } elseif ($usarOrdenUnion) {
        $sql = "INSERT INTO r_equipotemp (id_equipo, nombre, capitan, id_retador, nombre_retador, apellido_retador, codigo_postal, numero_jugador, orden_union, posicion, estado_jugador, resultados_jugador, quejas, rango) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('issssssiisssss', $idEquipo, $nombreEquipo, $capitan, $idRetador, $nombreRetador, $apellidoRetador, $codigoPostal, $numeroJugador, $ordenUnion, $posicion, $estadoJugador, $resultadosJugador, $quejas, $rango);
    } else {
        $sql = "INSERT INTO r_equipotemp (id_equipo, nombre, capitan, id_retador, nombre_retador, apellido_retador, codigo_postal, numero_jugador, posicion, estado_jugador, resultados_jugador, quejas, rango) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('issssssisssss', $idEquipo, $nombreEquipo, $capitan, $idRetador, $nombreRetador, $apellidoRetador, $codigoPostal, $numeroJugador, $posicion, $estadoJugador, $resultadosJugador, $quejas, $rango);
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function asegurarJugadorEnSala($conn, $idReta, $reta, $idEquipo1, $idEquipo2, $retador, $usarIdReta, $usarOrdenUnion = false, $cantidadMaximaEquipo = 0) {
    $existe = obtenerMembresiaJugador($conn, $idReta, $retador['Id_Retador'], $idEquipo1, $idEquipo2, $usarIdReta);

    if ($existe) {
        return ['ok' => true, 'mensaje' => 'Ya estabas registrado en esta sala de espera.', 'nuevo' => false];
    }

    $capitan1 = capitanEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta);
    $capitan2 = capitanEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta);

    if ($capitan1 === '') {
        $lado = 1;
        $idEquipo = $idEquipo1;
        $capitan = $retador['Id_Retador'];
        $tipo = 'capitan';
    } elseif ($capitan2 === '') {
        $lado = 2;
        $idEquipo = $idEquipo2;
        $capitan = $retador['Id_Retador'];
        $tipo = 'capitan';
    } else {
        $total1 = contarJugadoresEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta);
        $total2 = contarJugadoresEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta);
        $maximo = (int)$cantidadMaximaEquipo;

        if ($maximo > 0 && $total1 >= $maximo && $total2 >= $maximo) {
            return ['ok' => false, 'mensaje' => 'La reta ya tiene los dos equipos completos según la cantidad máxima del deporte.', 'nuevo' => false];
        }

        if ($maximo > 0 && $total1 >= $maximo) {
            $lado = 2;
        } elseif ($maximo > 0 && $total2 >= $maximo) {
            $lado = 1;
        } else {
            $lado = ($total1 <= $total2) ? 1 : 2;
        }

        $idEquipo = ($lado === 1) ? $idEquipo1 : $idEquipo2;
        $capitan = ($lado === 1) ? $capitan1 : $capitan2;
        $tipo = 'retador';
    }

    $ok = insertarJugadorTemp($conn, $idReta, $reta, $idEquipo, $lado, $retador, $capitan, $usarIdReta, $usarOrdenUnion);

    if (!$ok) {
        return ['ok' => false, 'mensaje' => 'No se pudo asignarte a la sala de espera.', 'nuevo' => false];
    }

    $mensaje = $tipo === 'capitan'
        ? 'Fuiste asignado como capitán del equipo ' . $lado . '.'
        : 'Fuiste asignado como retador al equipo ' . $lado . '.';

    return ['ok' => true, 'mensaje' => $mensaje, 'nuevo' => true];
}

function cargarIntegrantesEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion = false) {
    $integrantes = [];

    $base = "SELECT et.*, r.Nombre AS nombre_usuario, r.Apellido AS apellido_usuario, r.FotoPerfil AS foto_usuario FROM r_equipotemp et LEFT JOIN retador r ON CAST(r.Id_Retador AS CHAR) = CAST(et.id_retador AS CHAR) WHERE ";
    $ordenUnion = $usarOrdenUnion ? "CASE WHEN et.orden_union > 0 THEN et.orden_union ELSE et.numero_jugador END ASC," : "";
    $order = " ORDER BY CASE WHEN CAST(et.id_retador AS CHAR) = CAST(et.capitan AS CHAR) THEN 0 ELSE 1 END, " . $ordenUnion . " et.numero_jugador ASC, et.nombre_retador ASC";

    if ($usarIdReta) {
        $stmt = $conn->prepare($base . "et.id_reta = ? AND et.id_equipo = ? AND CAST(et.id_retador AS CHAR) <> '' AND CAST(et.id_retador AS CHAR) <> '0'" . $order);
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare($base . "et.id_equipo = ? AND CAST(et.id_retador AS CHAR) <> '' AND CAST(et.id_retador AS CHAR) <> '0'" . $order);
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($fila = $res->fetch_assoc()) {
            $integrantes[] = $fila;
        }
        $stmt->close();
    }

    return $integrantes;
}

function actualizarNombreEquipoTemp($conn, $idReta, $idEquipo, $lado, $nuevoNombre, $reta, $usarIdReta) {
    if ($usarIdReta) {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET nombre = ? WHERE id_reta = ? AND id_equipo = ?");
        if ($stmt) {
            $stmt->bind_param('ssi', $nuevoNombre, $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET nombre = ? WHERE id_equipo = ?");
        if ($stmt) {
            $stmt->bind_param('si', $nuevoNombre, $idEquipo);
        }
    }

    $okTemp = false;

    if ($stmt) {
        $okTemp = $stmt->execute();
        $stmt->close();
    }

    $columnaEquipo = columnaNombreEquipoReta($conn, $lado);
    $idColumna = isset($reta['_id_columna_reta']) ? $reta['_id_columna_reta'] : '';

    if ($columnaEquipo !== '' && $idColumna !== '') {
        $sql = "UPDATE r_retaspublicadas SET `$columnaEquipo` = ? WHERE CAST(`$idColumna` AS CHAR) = CAST(? AS CHAR) LIMIT 1";
        $stmtR = $conn->prepare($sql);
        if ($stmtR) {
            $stmtR->bind_param('ss', $nuevoNombre, $idReta);
            $stmtR->execute();
            $stmtR->close();
        }
    }

    return $okTemp;
}

function buscarJugadorTemp($conn, $idReta, $idEquipo, $idRetadorObjetivo, $usarIdReta) {
    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sis', $idReta, $idEquipo, $idRetadorObjetivo);
        }
    } else {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $idEquipo, $idRetadorObjetivo);
        }
    }

    if (!$stmt) {
        return null;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $fila ? $fila : null;
}

function actualizarJugadorTemp($conn, $idReta, $idEquipo, $idActual, $idObjetivo, $nombre, $apellido, $numero, $posicion, $usarIdReta) {
    $jugador = buscarJugadorTemp($conn, $idReta, $idEquipo, $idObjetivo, $usarIdReta);

    if (!$jugador) {
        return ['ok' => false, 'mensaje' => 'No se encontró el jugador en este equipo.'];
    }

    $esPropio = (string)$idActual === (string)$idObjetivo;
    $esCapitan = usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipo, $idActual, $usarIdReta);

    if (!$esPropio && !$esCapitan) {
        return ['ok' => false, 'mensaje' => 'No tienes permiso para modificar a este jugador.'];
    }

    $nombre = trim((string)$nombre);
    $apellido = trim((string)$apellido);
    $posicion = trim((string)$posicion);

    if ($nombre === '') {
        return ['ok' => false, 'mensaje' => 'El nombre del jugador no puede quedar vacío.'];
    }

    if (mb_strlen($nombre, 'UTF-8') > 100) {
        $nombre = mb_substr($nombre, 0, 100, 'UTF-8');
    }

    if (mb_strlen($apellido, 'UTF-8') > 100) {
        $apellido = mb_substr($apellido, 0, 100, 'UTF-8');
    }

    if (mb_strlen($posicion, 'UTF-8') > 50) {
        $posicion = mb_substr($posicion, 0, 50, 'UTF-8');
    }

    $numero = (int)$numero;
    if ($numero < 0) {
        $numero = 0;
    }

    if ($usarIdReta) {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET nombre_retador = ?, apellido_retador = ?, numero_jugador = ?, posicion = ? WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssissis', $nombre, $apellido, $numero, $posicion, $idReta, $idEquipo, $idObjetivo);
        }
    } else {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET nombre_retador = ?, apellido_retador = ?, numero_jugador = ?, posicion = ? WHERE id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssisis', $nombre, $apellido, $numero, $posicion, $idEquipo, $idObjetivo);
        }
    }

    if (!$stmt) {
        return ['ok' => false, 'mensaje' => 'No se pudo preparar la actualización del jugador.'];
    }

    $ok = $stmt->execute();
    $stmt->close();

    return ['ok' => $ok, 'mensaje' => $ok ? 'Datos del jugador actualizados correctamente.' : 'No se pudieron actualizar los datos del jugador.'];
}

function reasignarCapitanSiHaceFalta($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion = false) {
    $capitanActual = capitanEquipoTemp($conn, $idReta, $idEquipo, $usarIdReta);

    if ($capitanActual !== '') {
        $existeCapitan = buscarJugadorTemp($conn, $idReta, $idEquipo, $capitanActual, $usarIdReta);
        if ($existeCapitan) {
            return;
        }
    }

    $orden = $usarOrdenUnion
        ? "ORDER BY CASE WHEN orden_union > 0 THEN orden_union ELSE numero_jugador END ASC, numero_jugador ASC LIMIT 1"
        : "ORDER BY numero_jugador ASC LIMIT 1";

    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT id_retador FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) <> '0' AND TRIM(CAST(id_retador AS CHAR)) <> '' " . $orden);
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT id_retador FROM r_equipotemp WHERE id_equipo = ? AND CAST(id_retador AS CHAR) <> '0' AND TRIM(CAST(id_retador AS CHAR)) <> '' " . $orden);
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    $nuevoCapitan = '';

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $nuevoCapitan = trim((string)$fila['id_retador']);
        }
        $stmt->close();
    }

    if ($nuevoCapitan !== '') {
        if ($usarIdReta) {
            $stmtU = $conn->prepare("UPDATE r_equipotemp SET capitan = ?, estado_jugador = CASE WHEN CAST(id_retador AS CHAR) = CAST(? AS CHAR) THEN 'Capitán' ELSE 'En espera' END WHERE id_reta = ? AND id_equipo = ?");
            if ($stmtU) {
                $stmtU->bind_param('sssi', $nuevoCapitan, $nuevoCapitan, $idReta, $idEquipo);
            }
        } else {
            $stmtU = $conn->prepare("UPDATE r_equipotemp SET capitan = ?, estado_jugador = CASE WHEN CAST(id_retador AS CHAR) = CAST(? AS CHAR) THEN 'Capitán' ELSE 'En espera' END WHERE id_equipo = ?");
            if ($stmtU) {
                $stmtU->bind_param('ssi', $nuevoCapitan, $nuevoCapitan, $idEquipo);
            }
        }

        if ($stmtU) {
            $stmtU->execute();
            $stmtU->close();
        }
    }
}

function eliminarJugadorTemp($conn, $idReta, $idEquipo, $idActual, $idObjetivo, $usarIdReta, $usarOrdenUnion = false) {
    if ((string)$idActual === (string)$idObjetivo) {
        return ['ok' => false, 'mensaje' => 'Para salir de la reta usa el botón general de salir de la reta.'];
    }

    if (!usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipo, $idActual, $usarIdReta)) {
        return ['ok' => false, 'mensaje' => 'Solo el capitán puede eliminar retadores de su equipo.'];
    }

    if ($usarIdReta) {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sis', $idReta, $idEquipo, $idObjetivo);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $idEquipo, $idObjetivo);
        }
    }

    if (!$stmt) {
        return ['ok' => false, 'mensaje' => 'No se pudo preparar la eliminación.'];
    }

    $ok = $stmt->execute();
    $stmt->close();
    reasignarCapitanSiHaceFalta($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion);

    return ['ok' => $ok, 'mensaje' => $ok ? 'Retador eliminado del equipo.' : 'No se pudo eliminar al retador.'];
}

function salirDeSalaTemp($conn, $idReta, $idEquipo1, $idEquipo2, $idRetador, $usarIdReta, $usarOrdenUnion = false) {
    $membresia = obtenerMembresiaJugador($conn, $idReta, $idRetador, $idEquipo1, $idEquipo2, $usarIdReta);

    if (!$membresia) {
        return;
    }

    $idEquipo = (int)$membresia['id_equipo'];

    if ($usarIdReta) {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_reta = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $idReta, $idRetador);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE CAST(id_retador AS CHAR) = CAST(? AS CHAR) AND id_equipo IN (?, ?) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sii', $idRetador, $idEquipo1, $idEquipo2);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }

    reasignarCapitanSiHaceFalta($conn, $idReta, $idEquipo, $usarIdReta, $usarOrdenUnion);
}


function asegurarCampoEstadoRetaTemp($conn) {
    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');

    if (isset($columnas['estado'])) {
        return true;
    }

    $conn->query("ALTER TABLE r_equipotemp ADD COLUMN estado VARCHAR(50) NOT NULL DEFAULT 'Pendiente' AFTER rango");
    $conn->query("ALTER TABLE r_equipotemp ADD INDEX idx_r_equipotemp_estado_reta (id_reta, id_equipo, estado)");

    $columnas = obtenerColumnasTabla($conn, 'r_equipotemp');
    return isset($columnas['estado']);
}

function asegurarTablaRetasProgramadas($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS R_retasprogramadas (
        id_programada INT AUTO_INCREMENT PRIMARY KEY,
        id_reta VARCHAR(120) NOT NULL,
        id_creador VARCHAR(50) NULL,
        deporte VARCHAR(100) NOT NULL,
        cancha VARCHAR(150) NOT NULL,
        direccion TEXT NULL,
        codigo_postal VARCHAR(15) NULL,
        fecha_reta VARCHAR(30) NULL,
        hora_reta VARCHAR(40) NULL,
        estado_reta_publicada VARCHAR(60) NULL,
        descripcion TEXT NULL,
        id_equipo1 INT NOT NULL,
        nombre_equipo1 VARCHAR(100) NOT NULL,
        capitan_equipo1 VARCHAR(50) NULL,
        total_equipo1 INT NOT NULL DEFAULT 0,
        jugadores_equipo1 LONGTEXT NULL,
        id_equipo2 INT NOT NULL,
        nombre_equipo2 VARCHAR(100) NOT NULL,
        capitan_equipo2 VARCHAR(50) NULL,
        total_equipo2 INT NOT NULL DEFAULT 0,
        jugadores_equipo2 LONGTEXT NULL,
        cantidad_minima INT NOT NULL DEFAULT 1,
        cantidad_maxima INT NULL,
        fecha_programada DATE NOT NULL,
        hora_programada TIME NOT NULL,
        estado_programada VARCHAR(60) NOT NULL DEFAULT 'Programada',
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reta_programada_id_reta (id_reta),
        INDEX idx_retasprogramadas_fecha (fecha_programada, hora_programada),
        INDEX idx_retasprogramadas_deporte (deporte)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $conn->query($sql) ? true : false;
}

function obtenerReglaDeporteSala($conn, $nombreDeporte) {
    $regla = [
        'cantidad_minima' => 1,
        'cantidad_maxima' => 0
    ];

    $nombreDeporte = trim((string)$nombreDeporte);
    if ($nombreDeporte === '') {
        return $regla;
    }

    $columnas = obtenerColumnasTabla($conn, 'deporte');
    if (empty($columnas)) {
        return $regla;
    }

    $colNombre = buscarColumna($columnas, ['Nombre', 'nombre']);
    $colMinima = buscarColumna($columnas, ['CantidadMinima', 'cantidadMinima', 'cantidad_minima', 'cantidad minima', 'Cantidad_Minima']);
    $colMaxima = buscarColumna($columnas, ['cantidadMaxima', 'CantidadMaxima', 'cantidad_maxima', 'cantidad maxima', 'Cantidad_Maxima']);

    if ($colNombre === '') {
        return $regla;
    }

    $selectMin = $colMinima !== '' ? "`$colMinima` AS cantidad_minima" : "1 AS cantidad_minima";
    $selectMax = $colMaxima !== '' ? "`$colMaxima` AS cantidad_maxima" : "0 AS cantidad_maxima";
    $sql = "SELECT $selectMin, $selectMax FROM deporte WHERE LOWER(TRIM(`$colNombre`)) = LOWER(TRIM(?)) LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return $regla;
    }

    $stmt->bind_param('s', $nombreDeporte);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($fila = $res->fetch_assoc()) {
        $minima = isset($fila['cantidad_minima']) ? (int)$fila['cantidad_minima'] : 1;
        $maxima = isset($fila['cantidad_maxima']) ? (int)$fila['cantidad_maxima'] : 0;

        $regla['cantidad_minima'] = $minima > 0 ? $minima : 1;
        $regla['cantidad_maxima'] = $maxima > 0 ? $maxima : 0;
    }

    $stmt->close();
    return $regla;
}

function equipoEstaListoTemp($conn, $idReta, $idEquipo, $usarIdReta, $usarEstado) {
    if (!$usarEstado) {
        return false;
    }

    if ($usarIdReta) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND estado = 'Listo' AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM r_equipotemp WHERE id_equipo = ? AND estado = 'Listo' AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0;

    if ($fila = $res->fetch_assoc()) {
        $total = (int)$fila['total'];
    }

    $stmt->close();
    return $total > 0;
}

function marcarEquipoListoTemp($conn, $idReta, $idEquipo, $usarIdReta, $usarEstado) {
    if (!$usarEstado) {
        return false;
    }

    if ($usarIdReta) {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET estado = 'Listo' WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('si', $idReta, $idEquipo);
        }
    } else {
        $stmt = $conn->prepare("UPDATE r_equipotemp SET estado = 'Listo' WHERE id_equipo = ? AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0'");
        if ($stmt) {
            $stmt->bind_param('i', $idEquipo);
        }
    }

    if (!$stmt) {
        return false;
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function equipoPuedeRetarSala($totalEquipo, $cantidadMinima, $cantidadMaxima) {
    $totalEquipo = (int)$totalEquipo;
    $cantidadMinima = (int)$cantidadMinima;
    $cantidadMaxima = (int)$cantidadMaxima;

    if ($cantidadMinima < 1) {
        $cantidadMinima = 1;
    }

    if ($totalEquipo < $cantidadMinima) {
        return false;
    }

    if ($cantidadMaxima > 0 && $totalEquipo > $cantidadMaxima) {
        return false;
    }

    return true;
}

function exportarJugadoresProgramados($jugadores) {
    $salida = [];

    foreach ($jugadores as $jugador) {
        $salida[] = [
            'id_retador' => isset($jugador['id_retador']) ? (string)$jugador['id_retador'] : '',
            'nombre' => isset($jugador['nombre_retador']) ? (string)$jugador['nombre_retador'] : '',
            'apellido' => isset($jugador['apellido_retador']) ? (string)$jugador['apellido_retador'] : '',
            'numero_jugador' => isset($jugador['numero_jugador']) ? (int)$jugador['numero_jugador'] : 0,
            'posicion' => isset($jugador['posicion']) ? (string)$jugador['posicion'] : '',
            'estado_jugador' => isset($jugador['estado_jugador']) ? (string)$jugador['estado_jugador'] : '',
            'rango' => isset($jugador['rango']) ? (string)$jugador['rango'] : ''
        ];
    }

    return json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function retaProgramadaExiste($conn, $idReta) {
    asegurarTablaRetasProgramadas($conn);

    $stmt = $conn->prepare("SELECT id_programada FROM R_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $existe = ($res && $res->num_rows > 0);
    $stmt->close();

    return $existe;
}

function eliminarRetaPublicadaDespuesDeProgramar($conn, $idReta, $reta = null) {
    $columnasReta = obtenerColumnasTabla($conn, 'r_retaspublicadas');
    $idColumna = '';

    if (is_array($reta) && isset($reta['_id_columna_reta']) && trim((string)$reta['_id_columna_reta']) !== '') {
        $idColumna = trim((string)$reta['_id_columna_reta']);
    }

    if ($idColumna === '' || !isset($columnasReta[$idColumna])) {
        $idColumna = buscarColumna($columnasReta, ['id_reta', 'Id_Reta', 'ID_RETA', 'Id_RetaPublicada', 'id_reta_publicada', 'ID_RETA_PUBLICADA', 'id', 'Id', 'ID']);
    }

    if ($idColumna === '') {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM r_retaspublicadas WHERE CAST(`$idColumna` AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $idReta);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function guardarRetaProgramadaSiLista($conn, $idReta, $reta, $idEquipo1, $idEquipo2, $usarIdReta, $usarOrdenUnion, $usarEstado, $cantidadMinima, $cantidadMaxima) {
    if (!$usarEstado) {
        return false;
    }

    $equipo1Listo = equipoEstaListoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarEstado);
    $equipo2Listo = equipoEstaListoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarEstado);

    if (!$equipo1Listo || !$equipo2Listo) {
        return false;
    }

    if (retaProgramadaExiste($conn, $idReta)) {
        eliminarRetaPublicadaDespuesDeProgramar($conn, $idReta, $reta);
        return true;
    }

    if (!asegurarTablaRetasProgramadas($conn)) {
        return false;
    }

    $jugadores1 = cargarIntegrantesEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarOrdenUnion);
    $jugadores2 = cargarIntegrantesEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarOrdenUnion);

    $nombreEquipo1 = nombreActualEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta, nombreEquipoReta($reta, 1));
    $nombreEquipo2 = nombreActualEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta, nombreEquipoReta($reta, 2));
    $capitanEquipo1 = capitanEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta);
    $capitanEquipo2 = capitanEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta);

    $idCreador = datoReta($reta, ['id_creador', 'Id_Creador', 'ID_CREADOR'], '');
    $deporte = datoReta($reta, ['deporte', 'Deporte', 'Tipo_Deporte', 'tipo_deporte'], 'Reta');
    $cancha = datoReta($reta, ['cancha', 'Cancha', 'nombre_cancha', 'Nombre_Cancha', 'NombreCancha', 'Nombre'], 'Cancha por definir');
    $direccion = datoReta($reta, ['direccion', 'Direccion', 'Ubicacion', 'ubicacion', 'Lugar', 'lugar'], '');
    $codigoPostal = datoReta($reta, ['codigo_postal', 'Codigo_Postal', 'CodigoPostal', 'CP', 'cp'], '');
    $fechaReta = datoReta($reta, ['fecha', 'Fecha', 'Fecha_Reta', 'fecha_reta'], '');
    $horaReta = datoReta($reta, ['hora', 'Hora', 'hora_inicio', 'Hora_Inicio', 'HoraInicio'], '');
    $estadoPublicada = datoReta($reta, ['estado_reta', 'Estado_Reta', 'Estado', 'estado'], 'Publicada');
    $descripcion = datoReta($reta, ['descripcion', 'Descripcion', 'Descripción', 'comentarios', 'Comentarios'], '');
    $jsonEquipo1 = exportarJugadoresProgramados($jugadores1);
    $jsonEquipo2 = exportarJugadoresProgramados($jugadores2);
    $totalEquipo1 = count($jugadores1);
    $totalEquipo2 = count($jugadores2);
    $fechaProgramada = date('Y-m-d');
    $horaProgramada = date('H:i:s');
    $estadoProgramada = 'Programada';

    $sql = "INSERT INTO R_retasprogramadas
        (id_reta, id_creador, deporte, cancha, direccion, codigo_postal, fecha_reta, hora_reta, estado_reta_publicada, descripcion,
         id_equipo1, nombre_equipo1, capitan_equipo1, total_equipo1, jugadores_equipo1,
         id_equipo2, nombre_equipo2, capitan_equipo2, total_equipo2, jugadores_equipo2,
         cantidad_minima, cantidad_maxima, fecha_programada, hora_programada, estado_programada)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $cantidadMinima = (int)$cantidadMinima;
    $cantidadMaxima = (int)$cantidadMaxima;

    $stmt->bind_param(
        'ssssssssssissisissisiisss',
        $idReta,
        $idCreador,
        $deporte,
        $cancha,
        $direccion,
        $codigoPostal,
        $fechaReta,
        $horaReta,
        $estadoPublicada,
        $descripcion,
        $idEquipo1,
        $nombreEquipo1,
        $capitanEquipo1,
        $totalEquipo1,
        $jsonEquipo1,
        $idEquipo2,
        $nombreEquipo2,
        $capitanEquipo2,
        $totalEquipo2,
        $jsonEquipo2,
        $cantidadMinima,
        $cantidadMaxima,
        $fechaProgramada,
        $horaProgramada,
        $estadoProgramada
    );

    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        eliminarRetaPublicadaDespuesDeProgramar($conn, $idReta, $reta);
    }

    return $ok;
}

function inicialesJugador($nombre, $apellido) {
    $n = trim((string)$nombre);
    $a = trim((string)$apellido);
    $i1 = $n !== '' ? mb_substr($n, 0, 1, 'UTF-8') : 'R';
    $i2 = $a !== '' ? mb_substr($a, 0, 1, 'UTF-8') : '';
    return mb_strtoupper($i1 . $i2, 'UTF-8');
}

function imprimirJugador($jugador, $idReta, $idEquipo, $idActual, $esCapitanActual) {
    $nombreJ = trim((string)($jugador['nombre_retador'] !== '' ? $jugador['nombre_retador'] : ($jugador['nombre_usuario'] ?? '')));
    $apellidoJ = trim((string)($jugador['apellido_retador'] !== '' ? $jugador['apellido_retador'] : ($jugador['apellido_usuario'] ?? '')));
    $fotoJ = obtenerRutaFotoPerfil($jugador['foto_usuario'] ?? '');
    $esCap = (string)$jugador['id_retador'] === (string)$jugador['capitan'];
    $soyYo = (string)$jugador['id_retador'] === (string)$idActual;
    $puedeEditar = $soyYo || $esCapitanActual;
    $puedeEliminar = $esCapitanActual && !$soyYo;
    ?>
    <div class="jugador-card">
        <div class="jugador-foto">
            <?php if ($fotoJ !== '../assets/doctor.png'): ?>
                <img src="<?php echo limpiarTexto($fotoJ); ?>" alt="Foto">
            <?php else: ?>
                <?php echo limpiarTexto(inicialesJugador($nombreJ, $apellidoJ)); ?>
            <?php endif; ?>
        </div>

        <div class="jugador-info">
            <div class="jugador-encabezado">
                <h4><?php echo limpiarTexto(trim($nombreJ . ' ' . $apellidoJ)); ?></h4>
                <?php if ($esCap): ?><span class="capitan-badge">⭐ Capitán</span><?php endif; ?>
            </div>

            <div class="jugador-tags">
                <span>ID: <?php echo limpiarTexto($jugador['id_retador']); ?></span>
                <span># <?php echo limpiarTexto($jugador['numero_jugador']); ?></span>
                <span>Posición: <?php echo limpiarTexto(trim((string)$jugador['posicion']) !== '' ? $jugador['posicion'] : 'Sin definir'); ?></span>
                <span>Estado: <?php echo limpiarTexto(trim((string)$jugador['estado_jugador']) !== '' ? $jugador['estado_jugador'] : 'En espera'); ?></span>
                <span>Rango: <?php echo limpiarTexto(trim((string)$jugador['rango']) !== '' ? $jugador['rango'] : 'Sin rango'); ?></span>
            </div>

            <?php if ($puedeEditar): ?>
                <form class="editar-jugador" method="POST" action="SalaEspera.php?id_reta=<?php echo urlencode((string)$idReta); ?>">
                    <input type="hidden" name="accion" value="actualizar_jugador">
                    <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                    <input type="hidden" name="id_equipo" value="<?php echo (int)$idEquipo; ?>">
                    <input type="hidden" name="id_jugador" value="<?php echo limpiarTexto($jugador['id_retador']); ?>">
                    <input class="form-control" type="text" name="nombre_retador" value="<?php echo limpiarTexto($nombreJ); ?>" placeholder="Nombre" required>
                    <input class="form-control" type="text" name="apellido_retador" value="<?php echo limpiarTexto($apellidoJ); ?>" placeholder="Apellido">
                    <input class="form-control" type="number" name="numero_jugador" min="0" value="<?php echo limpiarTexto($jugador['numero_jugador']); ?>" placeholder="Número">
                    <input class="form-control" type="text" name="posicion" value="<?php echo limpiarTexto($jugador['posicion']); ?>" placeholder="Posición">
                    <button class="btn-editar" type="submit">Guardar</button>
                </form>
            <?php endif; ?>

            <?php if ($puedeEliminar): ?>
                <form class="eliminar-jugador" method="POST" action="SalaEspera.php?id_reta=<?php echo urlencode((string)$idReta); ?>" onsubmit="return confirm('¿Eliminar a este retador de la sala?');">
                    <input type="hidden" name="accion" value="eliminar_jugador">
                    <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                    <input type="hidden" name="id_equipo" value="<?php echo (int)$idEquipo; ?>">
                    <input type="hidden" name="id_jugador" value="<?php echo limpiarTexto($jugador['id_retador']); ?>">
                    <button class="btn-eliminar" type="submit">Eliminar retador</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$Id_Retador = obtenerSesion($usuarios, ['Id_Retador', 'id_retador', 'Id_Usuario', 'id_usuario'], '');

if ($Id_Retador === '') {
    header("Location: ../login.php");
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

$retador = cargarRetadorSesion($conn, $Id_Retador, $usuarios);
$Nombre = $retador['Nombre'] !== '' ? $retador['Nombre'] : 'Usuario';
$Apellido = $retador['Apellido'] ?? '';
$FotoPerfilUsuario = obtenerRutaFotoPerfil($retador['FotoPerfil']);
$modoPerfilNormalizado = normalizarTexto($retador['ModoPerfil']);
$modoOscuroActivo = ($modoPerfilNormalizado === 'modo oscuro' || $modoPerfilNormalizado === 'moso oscuro');
$idReta = '';

if (isset($_GET['id_reta'])) {
    $idReta = trim((string)$_GET['id_reta']);
}

if (isset($_POST['id_reta'])) {
    $idReta = trim((string)$_POST['id_reta']);
}

$errores = [];
$mensajeOk = '';
$usarIdReta = asegurarCampoIdRetaTemp($conn);
$usarOrdenUnion = asegurarCampoOrdenUnionTemp($conn);
$usarEstadoRetaTemp = asegurarCampoEstadoRetaTemp($conn);
$reta = $idReta !== '' ? cargarRetaPublicada($conn, $idReta) : null;
$deporteParaReglas = $reta ? datoReta($reta, ['deporte', 'Deporte', 'Tipo_Deporte', 'tipo_deporte'], '') : '';
$reglaDeporteSala = obtenerReglaDeporteSala($conn, $deporteParaReglas);
$cantidadMinimaEquipo = (int)$reglaDeporteSala['cantidad_minima'];
$cantidadMaximaEquipo = (int)$reglaDeporteSala['cantidad_maxima'];
$idEquipoBase = 0;
if ($idReta !== '') {
    if (preg_match('/^\d+$/', (string)$idReta)) {
        $idEquipoBase = (int)$idReta;
    } else {
        $idEquipoBase = (abs(crc32((string)$idReta)) % 900000) + 100000;
    }
}
$idEquipo1 = $idEquipoBase > 0 ? ($idEquipoBase * 10) + 1 : 0;
$idEquipo2 = $idEquipoBase > 0 ? ($idEquipoBase * 10) + 2 : 0;

if ($idReta === '') {
    $errores[] = 'No se recibió la ID de la reta.';
}

if ($idReta !== '' && !$reta) {
    $errores[] = 'No se encontró la reta publicada seleccionada.';
}

$salaTempRegistrada = false;

if ($reta && $idEquipo1 > 0 && $idEquipo2 > 0) {
    $salaTempRegistrada = salaTieneEquiposTemporales($conn, $idReta, $idEquipo1, $idEquipo2, $usarIdReta);

    if (!$salaTempRegistrada) {
        $errores[] = 'Esta reta todavía no tiene equipos temporales registrados con esta id_reta. Revisa que al publicar la reta se hayan creado Equipo 1 y Equipo 2 en r_equipotemp.';
    }
}

if ($reta && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'salir_reta') {
    salirDeSalaTemp($conn, $idReta, $idEquipo1, $idEquipo2, $Id_Retador, $usarIdReta, $usarOrdenUnion);
    header("Location: retar.php");
    exit();
}

if ($reta && $salaTempRegistrada) {
    $autoAsignacion = asegurarJugadorEnSala($conn, $idReta, $reta, $idEquipo1, $idEquipo2, $retador, $usarIdReta, $usarOrdenUnion, $cantidadMaximaEquipo);

    if (!$autoAsignacion['ok']) {
        $errores[] = $autoAsignacion['mensaje'];
    } elseif ($autoAsignacion['nuevo']) {
        $mensajeOk = $autoAsignacion['mensaje'];
    }
}


if ($reta && $salaTempRegistrada && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'estado_reta_sala') {
    header('Content-Type: application/json; charset=utf-8');

    $equipo1ListoAjax = equipoEstaListoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarEstadoRetaTemp);
    $equipo2ListoAjax = equipoEstaListoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarEstadoRetaTemp);

    if ($equipo1ListoAjax && $equipo2ListoAjax) {
        guardarRetaProgramadaSiLista($conn, $idReta, $reta, $idEquipo1, $idEquipo2, $usarIdReta, $usarOrdenUnion, $usarEstadoRetaTemp, $cantidadMinimaEquipo, $cantidadMaximaEquipo);
    }

    echo json_encode([
        'ok' => true,
        'equipo1_listo' => $equipo1ListoAjax,
        'equipo2_listo' => $equipo2ListoAjax,
        'programada' => retaProgramadaExiste($conn, $idReta),
        'total_equipo1' => contarJugadoresEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta),
        'total_equipo2' => contarJugadoresEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta),
        'cantidad_minima' => $cantidadMinimaEquipo,
        'cantidad_maxima' => $cantidadMaximaEquipo
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($reta && $salaTempRegistrada && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'marcar_equipo_listo') {
    header('Content-Type: application/json; charset=utf-8');

    $ladoEquipoListo = isset($_POST['lado_equipo']) ? (int)$_POST['lado_equipo'] : 0;
    $idEquipoListo = $ladoEquipoListo === 1 ? $idEquipo1 : ($ladoEquipoListo === 2 ? $idEquipo2 : 0);

    if ($idEquipoListo === 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se encontró el equipo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipoListo, $Id_Retador, $usarIdReta)) {
        echo json_encode(['ok' => false, 'mensaje' => 'Solo el capitán puede marcar su equipo como listo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $totalEquipoListo = contarJugadoresEquipoTemp($conn, $idReta, $idEquipoListo, $usarIdReta);

    if (!equipoPuedeRetarSala($totalEquipoListo, $cantidadMinimaEquipo, $cantidadMaximaEquipo)) {
        $mensajeValidacion = 'Tu equipo necesita mínimo ' . $cantidadMinimaEquipo . ' jugador(es) para retar.';

        if ($cantidadMaximaEquipo > 0 && $totalEquipoListo > $cantidadMaximaEquipo) {
            $mensajeValidacion = 'Tu equipo supera la cantidad máxima permitida para este deporte.';
        }

        echo json_encode(['ok' => false, 'mensaje' => $mensajeValidacion], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $okListo = marcarEquipoListoTemp($conn, $idReta, $idEquipoListo, $usarIdReta, $usarEstadoRetaTemp);

    if (!$okListo) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo marcar el equipo como listo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $equipo1ListoAjax = equipoEstaListoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarEstadoRetaTemp);
    $equipo2ListoAjax = equipoEstaListoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarEstadoRetaTemp);
    $programada = false;

    if ($equipo1ListoAjax && $equipo2ListoAjax) {
        $programada = guardarRetaProgramadaSiLista($conn, $idReta, $reta, $idEquipo1, $idEquipo2, $usarIdReta, $usarOrdenUnion, $usarEstadoRetaTemp, $cantidadMinimaEquipo, $cantidadMaximaEquipo);
    }

    echo json_encode([
        'ok' => true,
        'mensaje' => $programada ? 'Los dos equipos están listos. La reta fue programada.' : 'Equipo listo para retar.',
        'lado_equipo' => $ladoEquipoListo,
        'equipo1_listo' => $equipo1ListoAjax,
        'equipo2_listo' => $equipo2ListoAjax,
        'programada' => $programada || retaProgramadaExiste($conn, $idReta)
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($reta && $salaTempRegistrada && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    if ($accion === 'cambiar_nombre_equipo') {
        $lado = isset($_POST['lado_equipo']) ? (int)$_POST['lado_equipo'] : 0;
        $nuevoNombre = isset($_POST['nuevo_nombre']) ? trim((string)$_POST['nuevo_nombre']) : '';
        $idEquipoCambio = $lado === 1 ? $idEquipo1 : ($lado === 2 ? $idEquipo2 : 0);

        if ($nuevoNombre === '') {
            $errores[] = 'Escribe el nuevo nombre del equipo.';
        } elseif ($idEquipoCambio === 0) {
            $errores[] = 'No se encontró el equipo a modificar.';
        } elseif (!usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipoCambio, $Id_Retador, $usarIdReta)) {
            $errores[] = 'Solo el capitán puede cambiar el nombre de su equipo.';
        } else {
            $okNombre = actualizarNombreEquipoTemp($conn, $idReta, $idEquipoCambio, $lado, $nuevoNombre, $reta, $usarIdReta);
            if ($okNombre) {
                $mensajeOk = 'Nombre del equipo actualizado correctamente.';
                $reta = cargarRetaPublicada($conn, $idReta);
            } else {
                $errores[] = 'No se pudo actualizar el nombre del equipo.';
            }
        }
    }

    if ($accion === 'actualizar_jugador') {
        $idEquipoPost = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
        $idJugadorPost = isset($_POST['id_jugador']) ? trim((string)$_POST['id_jugador']) : '';
        $nombrePost = isset($_POST['nombre_retador']) ? trim((string)$_POST['nombre_retador']) : '';
        $apellidoPost = isset($_POST['apellido_retador']) ? trim((string)$_POST['apellido_retador']) : '';
        $numeroPost = isset($_POST['numero_jugador']) ? (int)$_POST['numero_jugador'] : 0;
        $posicionPost = isset($_POST['posicion']) ? trim((string)$_POST['posicion']) : '';
        $resUpdate = actualizarJugadorTemp($conn, $idReta, $idEquipoPost, $Id_Retador, $idJugadorPost, $nombrePost, $apellidoPost, $numeroPost, $posicionPost, $usarIdReta);

        if ($resUpdate['ok']) {
            $mensajeOk = $resUpdate['mensaje'];
        } else {
            $errores[] = $resUpdate['mensaje'];
        }
    }

    if ($accion === 'eliminar_jugador') {
        $idEquipoPost = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
        $idJugadorPost = isset($_POST['id_jugador']) ? trim((string)$_POST['id_jugador']) : '';
        $resEliminar = eliminarJugadorTemp($conn, $idReta, $idEquipoPost, $Id_Retador, $idJugadorPost, $usarIdReta, $usarOrdenUnion);

        if ($resEliminar['ok']) {
            $mensajeOk = $resEliminar['mensaje'];
        } else {
            $errores[] = $resEliminar['mensaje'];
        }
    }
}

if ($reta && $salaTempRegistrada) {
    $equipo1Nombre = nombreActualEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta, nombreEquipoReta($reta, 1));
    $equipo2Nombre = nombreActualEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta, nombreEquipoReta($reta, 2));
    $integrantesEquipo1 = cargarIntegrantesEquipoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarOrdenUnion);
    $integrantesEquipo2 = cargarIntegrantesEquipoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarOrdenUnion);
    $esCapitanEquipo1 = usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipo1, $Id_Retador, $usarIdReta);
    $esCapitanEquipo2 = usuarioEsCapitanEquipoTemp($conn, $idReta, $idEquipo2, $Id_Retador, $usarIdReta);
    $membresiaActual = obtenerMembresiaJugador($conn, $idReta, $Id_Retador, $idEquipo1, $idEquipo2, $usarIdReta);
} else {
    $equipo1Nombre = 'Equipo 1';
    $equipo2Nombre = 'Equipo 2';
    $integrantesEquipo1 = [];
    $integrantesEquipo2 = [];
    $esCapitanEquipo1 = false;
    $esCapitanEquipo2 = false;
    $membresiaActual = null;
}


$totalEquipo1Sala = count($integrantesEquipo1);
$totalEquipo2Sala = count($integrantesEquipo2);
$equipo1Listo = ($reta && $salaTempRegistrada) ? equipoEstaListoTemp($conn, $idReta, $idEquipo1, $usarIdReta, $usarEstadoRetaTemp) : false;
$equipo2Listo = ($reta && $salaTempRegistrada) ? equipoEstaListoTemp($conn, $idReta, $idEquipo2, $usarIdReta, $usarEstadoRetaTemp) : false;
$equipo1PuedeRetar = $esCapitanEquipo1 && !$equipo1Listo && equipoPuedeRetarSala($totalEquipo1Sala, $cantidadMinimaEquipo, $cantidadMaximaEquipo);
$equipo2PuedeRetar = $esCapitanEquipo2 && !$equipo2Listo && equipoPuedeRetarSala($totalEquipo2Sala, $cantidadMinimaEquipo, $cantidadMaximaEquipo);
$retaYaProgramada = ($reta && $salaTempRegistrada) ? retaProgramadaExiste($conn, $idReta) : false;

$deporteReta = $reta ? datoReta($reta, ['deporte', 'Deporte', 'Tipo_Deporte', 'tipo_deporte'], 'Reta') : 'Reta';
$fechaReta = $reta ? datoReta($reta, ['fecha', 'Fecha', 'Fecha_Reta', 'fecha_reta'], 'Fecha pendiente') : 'Fecha pendiente';
$horaInicio = $reta ? datoReta($reta, ['hora_inicio', 'Hora_Inicio', 'HoraInicio', 'Hora', 'hora'], '') : '';
$horaTermino = $reta ? datoReta($reta, ['hora_termino', 'Hora_Termino', 'HoraTermino'], '') : '';
$canchaReta = $reta ? datoReta($reta, ['cancha', 'Cancha', 'nombre_cancha', 'Nombre_Cancha', 'NombreCancha', 'Nombre'], 'Cancha por definir') : 'Cancha por definir';
$estadoReta = $reta ? datoReta($reta, ['estado_reta', 'Estado_Reta', 'Estado', 'estado'], 'Publicada') : 'Publicada';
$rolActual = 'Retador';

if ($membresiaActual && (string)$membresiaActual['id_retador'] === (string)$membresiaActual['capitan']) {
    $rolActual = 'Capitán';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Sala de espera - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
:root{--azul:#1877f2;--azul2:#0ea5e9;--rojo:#ff4b5c;--rojo2:#ff2f45;--texto:#111827;--gris:#6b7280;--fondo:#f0f2f5;--sidebar:280px;--cyan:#8fefff;--azul-neon-fuerte:rgba(0,153,255,.95)}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100dvh;font-family:'Poppins',sans-serif;color:var(--texto);background:var(--fondo);overflow-x:hidden;padding-bottom:104px}
.bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.20),transparent 410px),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
.sidebar{width:var(--sidebar);height:100dvh;position:fixed;left:0;top:0;z-index:1000;padding:22px 14px 112px;background:rgba(255,255,255,.98);backdrop-filter:blur(14px);border-right:3px solid var(--azul-neon-fuerte);box-shadow:8px 0 24px rgba(0,0,0,.08),0 0 18px rgba(0,153,255,.32);overflow-y:auto;transition:transform .3s ease}
body.sidebar-hidden .sidebar{transform:translateX(-105%)}
.logo-area{display:flex;align-items:center;gap:12px;margin-bottom:22px}.doctor-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff}.logo-text h2{font-family:'Orbitron',sans-serif;font-size:19px;color:var(--azul);line-height:1}.logo-text p{font-size:12px;color:var(--gris);margin-top:5px}
.sidebar-boceto{width:100%;display:flex;flex-direction:column;gap:16px}.acciones-grid{width:100%;display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.accion-boceto{min-height:95px;text-decoration:none;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.62);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.22);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#374151;font-weight:900;font-size:12px;text-align:center;line-height:1.15;transition:.25s ease;padding:10px 6px}.accion-boceto img{width:38px;height:38px;object-fit:contain;display:block}.accion-boceto:hover,.accion-boceto.active{transform:translateY(-3px);color:var(--rojo2);border-color:rgba(255,75,92,.96)}.cerrar-boceto{width:min(180px,100%);min-height:52px;margin:0 auto;text-decoration:none;border-radius:18px;background:linear-gradient(180deg,#fff7f8,#fff);border:2px solid rgba(255,75,92,.82);display:flex;align-items:center;justify-content:center;gap:9px;color:var(--rojo2);font-weight:900;font-size:12px}.cerrar-boceto img{width:26px;height:26px;object-fit:contain}.info-boceto{width:100%;display:flex;flex-direction:column;gap:9px}.info-boceto a{min-height:46px;text-decoration:none;border-radius:16px;background:#fff;border:2px solid rgba(0,153,255,.56);display:flex;align-items:center;padding:0 16px;color:#374151;font-weight:900;font-size:13px}.modo-oscuro-panel{width:100%;min-height:58px;border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.60);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px}.modo-oscuro-texto{display:flex;align-items:center;gap:8px;color:#374151;font-size:13px;font-weight:900}.switch-modo{width:54px;height:30px;border:none;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 2px 5px rgba(0,0,0,.16),0 0 0 2px rgba(255,75,92,.28);position:relative;cursor:pointer;transition:.25s ease}.switch-modo span{position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(0,0,0,.25);transition:.25s ease}
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:74px;z-index:900;display:flex;align-items:center;gap:16px;padding:12px 24px;background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:3px solid var(--azul-neon-fuerte);box-shadow:0 4px 18px rgba(0,0,0,.07),0 0 16px rgba(0,153,255,.24);transition:left .3s ease}body.sidebar-hidden .topbar{left:0}.menu-toggle{width:50px;height:50px;border:3px solid rgba(0,153,255,.50);border-radius:17px;background:#fff;color:#111827;cursor:pointer;display:flex;align-items:center;justify-content:center}.menu-toggle span{width:25px;height:2px;background:currentColor;position:relative;border-radius:999px}.menu-toggle span:before,.menu-toggle span:after{content:"";position:absolute;left:0;width:25px;height:2px;background:currentColor;border-radius:999px}.menu-toggle span:before{top:-8px}.menu-toggle span:after{top:8px}.topbar-title{flex:1;min-width:0}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.1rem,3vw,2rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-user{max-width:330px;display:flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;background:#fff;box-shadow:0 6px 15px rgba(0,0,0,.08);font-weight:800;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.main-content{position:relative;z-index:2;min-height:100dvh;margin-left:var(--sidebar);padding:104px clamp(16px,4vw,42px) 122px;transition:margin-left .3s ease}body.sidebar-hidden .main-content{margin-left:0}.sala-wrapper{max-width:1250px;margin:0 auto;display:flex;flex-direction:column;gap:22px}.sala-head,.sala-panel{width:100%;border-radius:30px;background:rgba(255,255,255,.95);border:1px solid rgba(17,24,39,.06);box-shadow:0 16px 34px rgba(0,0,0,.10),0 0 18px rgba(0,153,255,.14);padding:clamp(22px,4vw,34px);position:relative;overflow:hidden}.sala-head:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 12% 18%,rgba(24,119,242,.25),transparent 260px),radial-gradient(circle at 88% 82%,rgba(255,75,92,.23),transparent 290px),linear-gradient(90deg,rgba(24,119,242,.12),rgba(255,255,255,.02) 48%,rgba(255,75,92,.13))}.sala-head>*{position:relative;z-index:1}.sala-chip{display:inline-flex;align-items:center;gap:8px;width:max-content;max-width:100%;padding:9px 14px;border-radius:999px;background:#fff;border:2px solid rgba(0,153,255,.35);color:#1877f2;font-size:13px;font-weight:900;margin-bottom:12px}.sala-head h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.65rem,4vw,2.45rem);margin-bottom:8px}.sala-head p{max-width:900px;color:#4b5563;line-height:1.65;font-size:.98rem}.resumen-reta{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:18px}.dato-reta{border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.22);padding:13px 14px;box-shadow:0 8px 18px rgba(0,0,0,.05)}.dato-reta span{display:block;color:#6b7280;font-size:12px;font-weight:900;margin-bottom:4px}.dato-reta strong{color:#111827;font-size:14px}.acciones-sala{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.btn-salir,.btn-volver{min-height:46px;border:0;text-decoration:none;border-radius:17px;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;font-size:13px;font-weight:900;cursor:pointer;transition:.25s ease}.btn-salir{background:linear-gradient(135deg,var(--rojo),var(--rojo2));color:#fff}.btn-volver{background:linear-gradient(135deg,var(--azul),var(--azul2));color:#fff}.equipos-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}.equipo-card{border-radius:30px;background:rgba(255,255,255,.96);border:2px solid rgba(0,153,255,.32);box-shadow:0 14px 28px rgba(0,0,0,.10),0 0 0 2px rgba(24,119,242,.10);padding:22px;display:flex;flex-direction:column;gap:18px;min-height:430px}.equipo-card.rojo{border-color:rgba(255,75,92,.48);box-shadow:0 14px 28px rgba(0,0,0,.10),0 0 0 2px rgba(255,75,92,.12)}.equipo-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;border-bottom:1px solid rgba(17,24,39,.08);padding-bottom:16px}.equipo-title h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.15rem,3vw,1.55rem);line-height:1.2}.equipo-title span{display:inline-flex;margin-top:8px;padding:7px 10px;border-radius:999px;background:#f3f8ff;border:1px solid rgba(24,119,242,.16);font-size:12px;font-weight:900;color:#1f2937}.renombrar-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.mini-input{min-height:42px;border-radius:14px;border:2px solid rgba(0,153,255,.28);padding:9px 12px;font-family:'Poppins',sans-serif;font-weight:800;outline:none;max-width:180px}.mini-btn{min-height:42px;border:none;border-radius:14px;padding:9px 12px;background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#fff;font-weight:900;cursor:pointer}.integrantes-lista{display:flex;flex-direction:column;gap:14px}.jugador-card{border-radius:22px;background:#fff;border:2px solid rgba(0,153,255,.22);box-shadow:0 10px 22px rgba(0,0,0,.07);padding:14px;display:grid;grid-template-columns:64px 1fr;gap:14px;align-items:start}.jugador-foto{width:64px;height:64px;border-radius:22px;overflow:hidden;background:linear-gradient(135deg,#1877f2,#ff4b5c);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Orbitron',sans-serif;font-size:22px;font-weight:900}.jugador-foto img{width:100%;height:100%;object-fit:cover;display:block}.jugador-info h4{color:#111827;font-size:1rem;font-weight:900;line-height:1.25}.jugador-encabezado{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px}.capitan-badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:rgba(255,75,92,.12);color:#b91c1c;font-size:11px;font-weight:900}.jugador-tags{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px}.jugador-tags span{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:#f3f8ff;border:1px solid rgba(24,119,242,.16);font-size:11px;font-weight:900;color:#374151}.editar-jugador{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:10px}.form-control{min-height:40px;border-radius:13px;border:2px solid rgba(0,153,255,.24);padding:8px 10px;font-family:'Poppins',sans-serif;font-weight:800;outline:none;width:100%}.btn-editar,.btn-eliminar{min-height:40px;border:0;border-radius:13px;color:#fff;font-weight:900;cursor:pointer;padding:8px 10px}.btn-editar{background:linear-gradient(135deg,#1877f2,#0ea5e9)}.eliminar-jugador{margin-top:8px}.btn-eliminar{width:100%;background:linear-gradient(135deg,#ff4b5c,#ff2f45)}.equipo-vacio{min-height:96px;border-radius:18px;border:2px dashed rgba(0,153,255,.28);display:flex;align-items:center;justify-content:center;text-align:center;color:#4b5563;font-weight:900;padding:18px}.alerta{border-radius:18px;padding:14px 16px;font-weight:900;box-shadow:0 10px 22px rgba(0,0,0,.06)}.alerta.ok{background:#f0fdf4;border:2px solid rgba(34,197,94,.28);color:#166534}.alerta.error{background:#fff5f7;border:2px solid rgba(255,75,92,.38);color:#b91c1c}.bottom-nav{position:fixed;left:var(--sidebar);right:0;bottom:0;height:88px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);padding:8px 18px;border-radius:22px 22px 0 0;background:#fff;border-top:2px solid rgba(0,153,255,.72);box-shadow:0 -6px 18px rgba(0,0,0,.06);transition:left .3s ease}body.sidebar-hidden .bottom-nav{left:0}.bottom-nav a{display:flex;align-items:center;justify-content:center;border-radius:16px;position:relative}.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain}.bottom-nav a.active:after{content:"";position:absolute;width:54px;height:54px;border-radius:15px;border:2px solid rgba(255,75,92,.98);box-shadow:0 0 0 2px rgba(0,153,255,.98)}.mobile-overlay{display:none}
body.dark-mode{color:#e5e7eb;background:#0b1220}body.dark-mode .bg-particles{background:linear-gradient(135deg,#0b1220 0%,#111827 45%,#1f1117 100%)}body.dark-mode .sidebar,body.dark-mode .topbar,body.dark-mode .bottom-nav,body.dark-mode .sala-head,body.dark-mode .sala-panel,body.dark-mode .equipo-card,body.dark-mode .jugador-card,body.dark-mode .dato-reta,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-panel{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.70);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.18)}body.dark-mode .topbar-user,body.dark-mode .menu-toggle,body.dark-mode .sala-chip,body.dark-mode .equipo-title span,body.dark-mode .jugador-tags span,body.dark-mode .form-control,body.dark-mode .mini-input{background:#0b1220;color:#e5e7eb}body.dark-mode .sala-head h2,body.dark-mode .equipo-title h3,body.dark-mode .topbar-title h1,body.dark-mode .logo-text h2{color:var(--cyan)}body.dark-mode .sala-head p,body.dark-mode .dato-reta span,body.dark-mode .dato-reta strong,body.dark-mode .jugador-info h4,body.dark-mode .equipo-vacio,body.dark-mode .modo-oscuro-texto,body.dark-mode .topbar-user,body.dark-mode .info-boceto a,body.dark-mode .accion-boceto{color:#e5e7eb}body.dark-mode .switch-modo{background:linear-gradient(135deg,#1877f2,#0ea5e9)}body.dark-mode .switch-modo span{transform:translateX(24px)}
@media screen and (max-width:1000px){.resumen-reta{grid-template-columns:repeat(2,minmax(0,1fr))}.equipos-grid{grid-template-columns:1fr}}
@media screen and (max-width:820px){.sidebar{transform:translateX(-105%)}body.sidebar-open .sidebar{transform:translateX(0)}.topbar,body.sidebar-hidden .topbar{left:0;height:68px;padding:10px 14px}.main-content,body.sidebar-hidden .main-content{margin-left:0;padding-top:94px}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;right:0;height:84px;padding:8px 10px}.topbar-user{display:none}.mobile-overlay{display:block;position:fixed;inset:0;z-index:950;background:rgba(17,24,39,.28);opacity:0;visibility:hidden;transition:.25s ease}body.sidebar-open .mobile-overlay{opacity:1;visibility:visible}}
@media screen and (max-width:620px){body{padding-bottom:92px}.resumen-reta{grid-template-columns:1fr}.equipo-top{flex-direction:column}.renombrar-form,.mini-input,.mini-btn{width:100%;max-width:none}.jugador-card{grid-template-columns:1fr}.editar-jugador{grid-template-columns:1fr}.bottom-nav{height:76px;padding:6px 4px}.bottom-nav a img{width:34px;height:34px}.bottom-nav a.active:after{width:44px;height:44px}}

.estado-equipo-listo{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:8px 11px;border-radius:999px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.26);color:#15803d;font-size:12px;font-weight:1000;margin-top:8px}.estado-equipo-pendiente{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:8px 11px;border-radius:999px;background:rgba(255,75,92,.10);border:1px solid rgba(255,75,92,.24);color:#b91c1c;font-size:12px;font-weight:1000;margin-top:8px}.regla-deporte{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:8px 11px;border-radius:999px;background:#fff;border:1px solid rgba(0,153,255,.24);color:#1f2937;font-size:12px;font-weight:1000;margin-top:8px}.retar-circular-wrap{display:flex;flex-direction:column;align-items:center;gap:7px}.btn-retar-circular{width:82px;height:82px;border-radius:999px;border:0;background:linear-gradient(135deg,#ff4b5c,#ff2f45);color:#fff;font-family:'Orbitron',sans-serif;font-size:13px;font-weight:1000;letter-spacing:.5px;cursor:pointer;box-shadow:0 0 0 4px rgba(255,75,92,.18),0 16px 30px rgba(255,75,92,.32);transition:.25s ease;display:flex;align-items:center;justify-content:center;text-transform:uppercase}.btn-retar-circular:hover{transform:translateY(-3px) scale(1.04);box-shadow:0 0 0 5px rgba(0,153,255,.18),0 18px 34px rgba(255,75,92,.38)}.btn-retar-circular:disabled{opacity:.65;cursor:not-allowed;transform:none}.retar-ayuda{max-width:170px;text-align:center;font-size:11px;font-weight:900;color:#6b7280;line-height:1.35}.retado-overlay{position:fixed;inset:0;z-index:9900;display:flex;align-items:center;justify-content:center;background:rgba(8,15,30,.42);backdrop-filter:blur(14px);animation:retadoFondo .25s ease}.retado-card{width:min(520px,calc(100vw - 28px));min-height:220px;border-radius:34px;background:linear-gradient(135deg,rgba(24,119,242,.96),rgba(255,75,92,.96));display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35),0 0 0 4px rgba(255,255,255,.25);padding:28px;animation:retadoZoom .35s ease}.retado-card h2{font-family:'Orbitron',sans-serif;font-size:clamp(3rem,12vw,6.5rem);line-height:1;text-transform:uppercase;text-shadow:0 8px 24px rgba(0,0,0,.24)}.retado-card p{margin-top:14px;font-size:clamp(1rem,4vw,1.4rem);font-weight:1000}.programada-chip{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:9px 13px;border-radius:999px;background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.30);color:#166534;font-size:12px;font-weight:1000;margin-top:10px}@keyframes retadoZoom{from{opacity:0;transform:scale(.82)}to{opacity:1;transform:scale(1)}}@keyframes retadoFondo{from{opacity:0}to{opacity:1}}body.dark-mode .estado-equipo-listo{background:rgba(34,197,94,.18);color:#bbf7d0}body.dark-mode .estado-equipo-pendiente{background:rgba(255,75,92,.18);color:#fecaca}body.dark-mode .regla-deporte,body.dark-mode .retar-ayuda{background:#0b1220;color:#e5e7eb;border-color:rgba(0,153,255,.28)}body.dark-mode .programada-chip{background:rgba(34,197,94,.18);color:#bbf7d0}@media screen and (max-width:620px){.retar-circular-wrap{width:100%;align-items:stretch}.btn-retar-circular{width:100%;height:54px;border-radius:18px}.retar-ayuda{max-width:none}.equipo-top{gap:16px}}


.retar-centro-acciones{width:100%;display:flex;justify-content:center;align-items:center;margin:4px 0 8px;position:relative;z-index:3}.retar-centro-card{width:min(520px,100%);border-radius:30px;background:rgba(255,255,255,.96);border:2px solid rgba(0,153,255,.32);box-shadow:0 14px 30px rgba(0,0,0,.10),0 0 0 2px rgba(255,75,92,.10);padding:18px 22px;display:flex;justify-content:center;align-items:center;gap:24px;flex-wrap:wrap}.retar-centro-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;text-align:center}.retar-centro-item strong{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:14px;line-height:1.2}.retar-centro-card .retar-ayuda{max-width:230px}.equipos-grid + .retar-centro-acciones{margin-top:20px}body.dark-mode .retar-centro-card{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.70);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.18)}body.dark-mode .retar-centro-item strong{color:var(--cyan)}@media screen and (max-width:620px){.retar-centro-card{width:100%;padding:16px}.retar-centro-item,.retar-centro-item .btn-retar-circular{width:100%}}
</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area">
        <img src="../assets/doctor.png" alt="Logo Doctor" class="doctor-logo" onerror="this.style.display='none'">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <div class="acciones-grid">
            <a href="../Equipo/UnirmeOtroEquipo.php" class="accion-boceto"><img src="../Imagenes/ImgUnion.png" alt=""><span>Unirme equipo</span></a>
            <a href="../Equipo/CrearEquipo.php" class="accion-boceto"><img src="../Imagenes/ImgCreacion.png" alt=""><span>Crear equipo</span></a>
            <a href="../Solicitudes.php" class="accion-boceto"><img src="../Imagenes/ImgSolicitud.png" alt=""><span>Solicitud</span></a>
            <a href="../Equipo/Mis_Equipos.php" class="accion-boceto"><img src="../Imagenes/ImgEquipo.png" alt=""><span>Equipo</span></a>
            <a href="../Ligas/liga.php" class="accion-boceto"><img src="../Imagenes/ImgLigas.png" alt=""><span>Ligas</span></a>
            <a href="../Retar/retar.php" class="accion-boceto active"><img src="../Imagenes/ImgReta.png" alt=""><span>Retar</span></a>
            <a href="../Canchas/Canchas.php" class="accion-boceto"><img src="../Imagenes/ImgCanchas.png" alt=""><span>Canchas</span></a>
            <a href="../Amigos.php" class="accion-boceto"><img src="../Imagenes/ImgAmigos.png" alt=""><span>Amigos</span></a>
        </div>

        <a href="../login.php" class="cerrar-boceto"><img src="../Imagenes/ImgCerrar.png" alt=""><span>Cerrar sesión</span></a>

        <div class="info-boceto">
            <a href="../Informacion.php">Información</a>
            <a href="../AcercaDe.php">Acerca de</a>
            <a href="../SoporteTecnico.php">Soporte técnico</a>
        </div>

        <div class="modo-oscuro-panel">
            <div class="modo-oscuro-texto"><span>🌙</span><strong>Modo oscuro</strong></div>
            <button type="button" class="switch-modo" id="darkModeToggle" aria-label="Modo oscuro"><span></span></button>
        </div>
    </div>
</aside>

<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="topbar-title"><h1>Sala de espera de <?php echo limpiarTexto($Nombre); ?></h1></div>
    <div class="topbar-user"><span>👤</span><span><?php echo limpiarTexto($Nombre); ?></span></div>
</header>

<main class="main-content">
    <section class="sala-wrapper">
        <div class="sala-head">
            <span class="sala-chip">⚔️ Sala de espera</span>
            <h2><?php echo limpiarTexto($deporteReta); ?></h2>
            <p>El sistema carga la reta seleccionada, revisa tu ID de retador en la sala y, si aún no estabas registrado, te asigna automáticamente siguiendo el orden de capitán del equipo 1, capitán del equipo 2 y después retadores normales uno y uno.</p>

            <div class="resumen-reta">
                <div class="dato-reta"><span>ID reta</span><strong><?php echo limpiarTexto($idReta !== '' ? $idReta : 'No recibida'); ?></strong></div>
                <div class="dato-reta"><span>Cancha</span><strong><?php echo limpiarTexto($canchaReta); ?></strong></div>
                <div class="dato-reta"><span>Fecha</span><strong><?php echo limpiarTexto($fechaReta); ?></strong></div>
                <div class="dato-reta"><span>Horario</span><strong><?php echo limpiarTexto(trim($horaInicio . ' - ' . $horaTermino, ' -') !== '' ? trim($horaInicio . ' - ' . $horaTermino, ' -') : 'Pendiente'); ?></strong></div>
                <div class="dato-reta"><span>Tu rol</span><strong><?php echo limpiarTexto($rolActual); ?></strong></div>
                <div class="dato-reta"><span>Mínimo por equipo</span><strong><?php echo (int)$cantidadMinimaEquipo; ?></strong></div>
                <div class="dato-reta"><span>Máximo por equipo</span><strong><?php echo $cantidadMaximaEquipo > 0 ? (int)$cantidadMaximaEquipo : 'Sin límite'; ?></strong></div>
            </div>

            <div class="acciones-sala">
                <a href="retar.php" class="btn-volver">Volver a retas</a>
                <?php if ($reta && $membresiaActual): ?>
                    <form method="POST" action="SalaEspera.php?id_reta=<?php echo urlencode((string)$idReta); ?>" onsubmit="return confirm('¿Seguro que quieres salir de esta reta?');">
                        <input type="hidden" name="accion" value="salir_reta">
                        <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                        <button type="submit" class="btn-salir">Salir de la reta</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mensajeOk !== ''): ?><div class="alerta ok">✅ <?php echo limpiarTexto($mensajeOk); ?></div><?php endif; ?>
        <?php if (!$usarIdReta): ?><div class="alerta error">⚠️ No se pudo crear o detectar el campo id_reta en r_equipotemp. Ejecuta el SQL que te dejé junto con este archivo.</div><?php endif; ?>
        <?php if (!$usarEstadoRetaTemp): ?><div class="alerta error">⚠️ No se pudo crear o detectar el campo estado en r_equipotemp. Ejecuta el SQL actualizado.</div><?php endif; ?>
        <?php if (!empty($errores)): ?><div class="alerta error"><?php foreach ($errores as $e): ?><div>⚠️ <?php echo limpiarTexto($e); ?></div><?php endforeach; ?></div><?php endif; ?>

        <?php if ($reta): ?>
            <div class="equipos-grid">
                <article class="equipo-card">
                    <div class="equipo-top">
                        <div class="equipo-title">
                            <h3><?php echo limpiarTexto($equipo1Nombre); ?></h3>
                            <span><?php echo count($integrantesEquipo1); ?> integrante(s)</span>
                            <span class="regla-deporte">Mín. <?php echo (int)$cantidadMinimaEquipo; ?><?php echo $cantidadMaximaEquipo > 0 ? ' · Máx. ' . (int)$cantidadMaximaEquipo : ''; ?></span>
                            <span class="<?php echo $equipo1Listo ? 'estado-equipo-listo' : 'estado-equipo-pendiente'; ?>" id="estadoEquipo1"><?php echo $equipo1Listo ? 'Listo' : 'Pendiente'; ?></span>
                            <?php if ($retaYaProgramada): ?><span class="programada-chip">Reta programada</span><?php endif; ?>
                        </div>

                        <?php if ($esCapitanEquipo1): ?>
                            <form class="renombrar-form" method="POST" action="SalaEspera.php?id_reta=<?php echo urlencode((string)$idReta); ?>">
                                <input type="hidden" name="accion" value="cambiar_nombre_equipo">
                                <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                                <input type="hidden" name="lado_equipo" value="1">
                                <input class="mini-input" type="text" name="nuevo_nombre" placeholder="Nuevo nombre" required>
                                <button class="mini-btn" type="submit">Cambiar</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="integrantes-lista">
                        <?php if (empty($integrantesEquipo1)): ?>
                            <div class="equipo-vacio">Aún no hay jugadores en este equipo.</div>
                        <?php else: ?>
                            <?php foreach ($integrantesEquipo1 as $jugador): ?>
                                <?php imprimirJugador($jugador, $idReta, $idEquipo1, $Id_Retador, $esCapitanEquipo1); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="equipo-card rojo">
                    <div class="equipo-top">
                        <div class="equipo-title">
                            <h3><?php echo limpiarTexto($equipo2Nombre); ?></h3>
                            <span><?php echo count($integrantesEquipo2); ?> integrante(s)</span>
                            <span class="regla-deporte">Mín. <?php echo (int)$cantidadMinimaEquipo; ?><?php echo $cantidadMaximaEquipo > 0 ? ' · Máx. ' . (int)$cantidadMaximaEquipo : ''; ?></span>
                            <span class="<?php echo $equipo2Listo ? 'estado-equipo-listo' : 'estado-equipo-pendiente'; ?>" id="estadoEquipo2"><?php echo $equipo2Listo ? 'Listo' : 'Pendiente'; ?></span>
                            <?php if ($retaYaProgramada): ?><span class="programada-chip">Reta programada</span><?php endif; ?>
                        </div>

                        <?php if ($esCapitanEquipo2): ?>
                            <form class="renombrar-form" method="POST" action="SalaEspera.php?id_reta=<?php echo urlencode((string)$idReta); ?>">
                                <input type="hidden" name="accion" value="cambiar_nombre_equipo">
                                <input type="hidden" name="id_reta" value="<?php echo limpiarTexto($idReta); ?>">
                                <input type="hidden" name="lado_equipo" value="2">
                                <input class="mini-input" type="text" name="nuevo_nombre" placeholder="Nuevo nombre" required>
                                <button class="mini-btn" type="submit">Cambiar</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="integrantes-lista">
                        <?php if (empty($integrantesEquipo2)): ?>
                            <div class="equipo-vacio">Esperando integrantes para el equipo 2.</div>
                        <?php else: ?>
                            <?php foreach ($integrantesEquipo2 as $jugador): ?>
                                <?php imprimirJugador($jugador, $idReta, $idEquipo2, $Id_Retador, $esCapitanEquipo2); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <?php if ($esCapitanEquipo1 || $esCapitanEquipo2): ?>
                <div class="retar-centro-acciones">
                    <div class="retar-centro-card">
                        <?php if ($esCapitanEquipo1): ?>
                            <div class="retar-centro-item">
                                <strong><?php echo limpiarTexto($equipo1Nombre); ?></strong>
                                <?php if ($equipo1Listo): ?>
                                    <button type="button" class="btn-retar-circular" disabled>Listo</button>
                                    <small class="retar-ayuda">Tu equipo ya fue retado.</small>
                                <?php elseif ($equipo1PuedeRetar): ?>
                                    <button type="button" class="btn-retar-circular btn-marcar-retar" data-lado="1" data-equipo="<?php echo limpiarTexto($equipo1Nombre); ?>">Retar</button>
                                    <small class="retar-ayuda">Tu equipo cumple el mínimo.</small>
                                <?php else: ?>
                                    <button type="button" class="btn-retar-circular" disabled>Retar</button>
                                    <small class="retar-ayuda">Necesitas mínimo <?php echo (int)$cantidadMinimaEquipo; ?> integrante(s).</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($esCapitanEquipo2): ?>
                            <div class="retar-centro-item">
                                <strong><?php echo limpiarTexto($equipo2Nombre); ?></strong>
                                <?php if ($equipo2Listo): ?>
                                    <button type="button" class="btn-retar-circular" disabled>Listo</button>
                                    <small class="retar-ayuda">Tu equipo ya fue retado.</small>
                                <?php elseif ($equipo2PuedeRetar): ?>
                                    <button type="button" class="btn-retar-circular btn-marcar-retar" data-lado="2" data-equipo="<?php echo limpiarTexto($equipo2Nombre); ?>">Retar</button>
                                    <small class="retar-ayuda">Tu equipo cumple el mínimo.</small>
                                <?php else: ?>
                                    <button type="button" class="btn-retar-circular" disabled>Retar</button>
                                    <small class="retar-ayuda">Necesitas mínimo <?php echo (int)$cantidadMinimaEquipo; ?> integrante(s).</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<nav class="bottom-nav">
    <a href="../Perfil2.php"><img src="../Imagenes/ImgInicio.png" alt=""></a>
    <a href="../Retar/retar.php" class="active"><img src="../Imagenes/ImgReta.png" alt=""></a>
    <a href="../Ligas/liga.php"><img src="../Imagenes/ImgLigas.png" alt=""></a>
    <a href="../Agenda.php"><img src="../Imagenes/ImgAgenda.png" alt=""></a>
    <a href="../Notificaciones.php"><img src="../Imagenes/ImgNoti.png" alt=""></a>
    <a href="../MiPerfil.php"><img src="../Imagenes/ImgPerfil.png" alt=""></a>
</nav>

<script>
const menuToggle=document.getElementById('menuToggle');
const mobileOverlay=document.getElementById('mobileOverlay');
function esMovil(){return window.innerWidth<=820}
if(menuToggle){menuToggle.addEventListener('click',function(){if(esMovil()){document.body.classList.toggle('sidebar-open')}else{document.body.classList.toggle('sidebar-hidden')}})}
if(mobileOverlay){mobileOverlay.addEventListener('click',function(){document.body.classList.remove('sidebar-open')})}
window.addEventListener('resize',function(){if(!esMovil())document.body.classList.remove('sidebar-open')});
const darkModeToggle=document.getElementById('darkModeToggle');
function aplicarModoOscuroVisual(estado){document.body.classList.toggle('dark-mode',estado)}
function actualizarModoPerfilBD(estado){const datos=new FormData();datos.append('accion','actualizar_modo_perfil');datos.append('modo',estado?'oscuro':'predeterminado');return fetch(window.location.href,{method:'POST',body:datos,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())}
if(darkModeToggle){darkModeToggle.addEventListener('click',function(){const nuevo=!document.body.classList.contains('dark-mode');const anterior=!nuevo;aplicarModoOscuroVisual(nuevo);darkModeToggle.disabled=true;actualizarModoPerfilBD(nuevo).then(data=>{if(!data||!data.ok){aplicarModoOscuroVisual(anterior);alert(data&&data.mensaje?data.mensaje:'No se pudo guardar el modo.')}}).catch(()=>{aplicarModoOscuroVisual(anterior);alert('No se pudo conectar con la base de datos.')}).finally(()=>{darkModeToggle.disabled=false})})}

const idRetaSala = <?php echo json_encode((string)$idReta, JSON_UNESCAPED_UNICODE); ?>;
let estadoEquipo1ListoJS = <?php echo $equipo1Listo ? 'true' : 'false'; ?>;
let estadoEquipo2ListoJS = <?php echo $equipo2Listo ? 'true' : 'false'; ?>;
let redireccionProgramadaJS = false;

function mostrarRetadoGrande(nombreEquipo){
    const overlay = document.createElement('div');
    overlay.className = 'retado-overlay';
    overlay.innerHTML = `
        <div class="retado-card">
            <h2>Retado</h2>
            <p>${String(nombreEquipo || 'Equipo').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))} listo</p>
        </div>
    `;
    document.body.appendChild(overlay);

    setTimeout(() => {
        overlay.style.transition = 'opacity .35s ease, transform .35s ease';
        overlay.style.opacity = '0';
        setTimeout(() => {
            if(overlay.parentNode){
                overlay.parentNode.removeChild(overlay);
            }
        }, 380);
    }, 5000);
}

function actualizarEstadoVisual(data){
    const e1 = document.getElementById('estadoEquipo1');
    const e2 = document.getElementById('estadoEquipo2');

    if(e1){
        e1.textContent = data.equipo1_listo ? 'Listo' : 'Pendiente';
        e1.className = data.equipo1_listo ? 'estado-equipo-listo' : 'estado-equipo-pendiente';
    }

    if(e2){
        e2.textContent = data.equipo2_listo ? 'Listo' : 'Pendiente';
        e2.className = data.equipo2_listo ? 'estado-equipo-listo' : 'estado-equipo-pendiente';
    }
}

function redirigirDespuesDeProgramar(){
    if(redireccionProgramadaJS){
        return;
    }

    redireccionProgramadaJS = true;

    setTimeout(() => {
        window.location.href = 'retar.php';
    }, 5200);
}

async function consultarEstadoSala(){
    if(!idRetaSala){
        return;
    }

    const datos = new FormData();
    datos.append('accion', 'estado_reta_sala');
    datos.append('id_reta', idRetaSala);

    try{
        const respuesta = await fetch(window.location.href, {
            method:'POST',
            body:datos,
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'}
        });

        const data = await respuesta.json();
        if(!data || !data.ok){
            return;
        }

        if(data.equipo1_listo && !estadoEquipo1ListoJS){
            mostrarRetadoGrande('<?php echo limpiarTexto($equipo1Nombre); ?>');
        }

        if(data.equipo2_listo && !estadoEquipo2ListoJS){
            mostrarRetadoGrande('<?php echo limpiarTexto($equipo2Nombre); ?>');
        }

        estadoEquipo1ListoJS = !!data.equipo1_listo;
        estadoEquipo2ListoJS = !!data.equipo2_listo;
        actualizarEstadoVisual(data);

        if(data.programada){
            redirigirDespuesDeProgramar();
        }
    }catch(error){
        console.error(error);
    }
}

document.querySelectorAll('.btn-marcar-retar').forEach((boton) => {
    boton.addEventListener('click', async () => {
        if(boton.disabled){
            return;
        }

        const lado = boton.dataset.lado || '';
        const equipo = boton.dataset.equipo || 'Equipo';
        const datos = new FormData();
        datos.append('accion', 'marcar_equipo_listo');
        datos.append('id_reta', idRetaSala);
        datos.append('lado_equipo', lado);

        boton.disabled = true;

        try{
            const respuesta = await fetch(window.location.href, {
                method:'POST',
                body:datos,
                credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });

            const data = await respuesta.json();

            if(!data || !data.ok){
                alert(data && data.mensaje ? data.mensaje : 'No se pudo marcar el equipo como listo.');
                boton.disabled = false;
                return;
            }

            mostrarRetadoGrande(equipo);
            boton.textContent = 'Listo';
            boton.classList.remove('btn-marcar-retar');
            actualizarEstadoVisual(data);
            estadoEquipo1ListoJS = !!data.equipo1_listo;
            estadoEquipo2ListoJS = !!data.equipo2_listo;

            if(data.programada){
                redirigirDespuesDeProgramar();
            }
        }catch(error){
            console.error(error);
            alert('No se pudo conectar con la base de datos.');
            boton.disabled = false;
        }
    });
});

if(idRetaSala){
    setInterval(consultarEstadoSala, 2500);
}

</script>
</body>
</html>
