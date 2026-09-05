<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuario = $_SESSION['usuario_data'];
$NombreSesion = obtenerSesionTemp($usuario, ['Nombre', 'nombre'], 'Usuario');
$ApellidoSesion = obtenerSesionTemp($usuario, ['Apellido', 'apellido'], '');
$Id_Retador = obtenerSesionTemp($usuario, ['Id_Retador', 'id_retador'], '');
$mensajeOk = '';
$mensajeError = '';
$nuevas_count = 0;

function temp_esc($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerSesionTemp($datos, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return $default;
}

function temp_valor($valor, $default = 'Sin información') {
    $valor = trim((string)$valor);
    return $valor !== '' ? $valor : $default;
}

function temp_normalizar($valor) {
    $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
    return str_replace(['á','é','í','ó','ú','ü','ñ',' ', '-'], ['a','e','i','o','u','u','n','_','_'], $valor);
}

function temp_columnas($conn, $tabla) {
    $columnas = [];
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
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

function temp_columna($columnas, $posibles) {
    foreach ($posibles as $posible) {
        if (isset($columnas[$posible])) {
            return $posible;
        }
    }
    $mapa = [];
    foreach ($columnas as $columna => $_) {
        $mapa[temp_normalizar($columna)] = $columna;
    }
    foreach ($posibles as $posible) {
        $clave = temp_normalizar($posible);
        if (isset($mapa[$clave])) {
            return $mapa[$clave];
        }
    }
    return '';
}

function temp_ruta_foto($foto) {
    $foto = trim(str_replace('\\', '/', (string)$foto));
    if ($foto === '') {
        return '';
    }
    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $foto)) {
        return $foto;
    }
    $foto = ltrim($foto, '/');
    if (strpos($foto, '../') === 0 || strpos($foto, './') === 0) {
        return $foto;
    }
    $rutas = [
        '../' . $foto,
        '../Imagenes/' . $foto,
        '../imagenes/' . $foto,
        '../uploads/' . $foto,
        '../FotosPerfil/' . $foto,
        '../assets/' . $foto,
        '../img/' . $foto,
        $foto,
        'Imagenes/' . $foto,
        'imagenes/' . $foto,
        'uploads/' . $foto,
        'FotosPerfil/' . $foto,
        'assets/' . $foto,
        'img/' . $foto
    ];
    foreach ($rutas as $ruta) {
        if (file_exists(__DIR__ . '/' . $ruta)) {
            return $ruta;
        }
    }
    return '../' . $foto;
}

function temp_cargar_perfil($conn, $idRetador, $datosSesion) {
    $perfil = [
        'Nombre' => obtenerSesionTemp($datosSesion, ['Nombre', 'nombre'], 'Usuario'),
        'Apellido' => obtenerSesionTemp($datosSesion, ['Apellido', 'apellido'], ''),
        'FotoPerfil' => obtenerSesionTemp($datosSesion, ['FotoPerfil', 'Fotoperfil', 'fotoPerfil', 'foto_perfil'], ''),
        'ModoPerfil' => obtenerSesionTemp($datosSesion, ['ModoPerfil', 'modoPerfil'], '')
    ];

    $stmt = $conn->prepare("SELECT Nombre, Apellido, FotoPerfil, ModoPerfil FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $idRetador);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            foreach ($fila as $k => $v) {
                if ($v !== null && trim((string)$v) !== '') {
                    $perfil[$k] = trim((string)$v);
                    $_SESSION['usuario_data'][$k] = trim((string)$v);
                }
            }
        }
        $stmt->close();
    }

    return $perfil;
}

function temp_obtener_programada($conn, $idReta, $idProgramada) {
    if ($idProgramada !== '') {
        $stmt = $conn->prepare("SELECT * FROM r_retasprogramadas WHERE CAST(id_programada AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $idProgramada);
            $stmt->execute();
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($fila) {
                return $fila;
            }
        }
    }

    if ($idReta !== '') {
        $stmt = $conn->prepare("SELECT * FROM r_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $idReta);
            $stmt->execute();
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($fila) {
                return $fila;
            }
        }
    }

    return null;
}

function temp_obtener_membresia($conn, $idReta, $idEquipo, $idRetador) {
    if ($idReta !== '' && $idEquipo !== '') {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $idEquipoInt = (int)$idEquipo;
            $stmt->bind_param('sis', $idReta, $idEquipoInt, $idRetador);
            $stmt->execute();
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($fila) {
                return $fila;
            }
        }
    }

    if ($idReta !== '') {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_reta = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $idReta, $idRetador);
            $stmt->execute();
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($fila) {
                return $fila;
            }
        }
    }

    if ($idEquipo !== '') {
        $stmt = $conn->prepare("SELECT * FROM r_equipotemp WHERE id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
        if ($stmt) {
            $idEquipoInt = (int)$idEquipo;
            $stmt->bind_param('is', $idEquipoInt, $idRetador);
            $stmt->execute();
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($fila) {
                return $fila;
            }
        }
    }

    return null;
}

function temp_capitan_equipo($conn, $idReta, $idEquipo) {
    $capitan = '';
    $stmt = $conn->prepare("SELECT capitan FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND TRIM(CAST(capitan AS CHAR)) <> '' AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0' LIMIT 1");
    if ($stmt) {
        $idEquipoInt = (int)$idEquipo;
        $stmt->bind_param('si', $idReta, $idEquipoInt);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $capitan = trim((string)$fila['capitan']);
        }
        $stmt->close();
    }
    return $capitan;
}

function temp_cargar_integrantes($conn, $idReta, $idEquipo) {
    $integrantes = [];
    $stmt = $conn->prepare("SELECT et.*, r.Nombre AS retador_nombre_bd, r.Apellido AS retador_apellido_bd, r.FotoPerfil AS retador_foto_bd, r.Rango_Estrellas AS retador_rango_bd FROM r_equipotemp et LEFT JOIN retador r ON CAST(r.Id_Retador AS CHAR) = CAST(et.id_retador AS CHAR) WHERE et.id_reta = ? AND et.id_equipo = ? AND CAST(et.id_retador AS CHAR) <> '' AND CAST(et.id_retador AS CHAR) <> '0' ORDER BY CASE WHEN CAST(et.id_retador AS CHAR) = CAST(et.capitan AS CHAR) THEN 0 ELSE 1 END, CASE WHEN et.orden_union > 0 THEN et.orden_union ELSE et.numero_jugador END ASC, et.numero_jugador ASC");
    if ($stmt) {
        $idEquipoInt = (int)$idEquipo;
        $stmt->bind_param('si', $idReta, $idEquipoInt);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($fila = $res->fetch_assoc()) {
            $integrantes[] = $fila;
        }
        $stmt->close();
    }
    return $integrantes;
}

function temp_jugador_nombre($jugador) {
    $nombre = trim((string)($jugador['retador_nombre_bd'] ?? ''));
    if ($nombre !== '') {
        return $nombre;
    }
    return trim((string)($jugador['nombre_retador'] ?? ''));
}

function temp_jugador_apellido($jugador) {
    $apellido = trim((string)($jugador['retador_apellido_bd'] ?? ''));
    if ($apellido !== '') {
        return $apellido;
    }
    return trim((string)($jugador['apellido_retador'] ?? ''));
}

function temp_jugador_rango($jugador) {
    $rango = trim((string)($jugador['retador_rango_bd'] ?? ''));
    if ($rango !== '') {
        return $rango;
    }
    return trim((string)($jugador['rango'] ?? ''));
}

function temp_exportar_jugadores_json($jugadores) {
    $salida = [];
    foreach ($jugadores as $jugador) {
        $salida[] = [
            'id_retador' => (string)($jugador['id_retador'] ?? ''),
            'nombre' => temp_jugador_nombre($jugador),
            'apellido' => temp_jugador_apellido($jugador),
            'numero_jugador' => (int)($jugador['numero_jugador'] ?? 0),
            'posicion' => (string)($jugador['posicion'] ?? ''),
            'estado_jugador' => (string)($jugador['estado_jugador'] ?? ''),
            'rango' => temp_jugador_rango($jugador)
        ];
    }
    return json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function temp_sincronizar_programada($conn, $idReta, $idEquipo) {
    $stmt = $conn->prepare("SELECT id_equipo1, id_equipo2 FROM r_retasprogramadas WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $idReta);
    $stmt->execute();
    $res = $stmt->get_result();
    $programada = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$programada) {
        return;
    }

    $idEquipoInt = (int)$idEquipo;
    $lado = 0;
    if ((int)$programada['id_equipo1'] === $idEquipoInt) {
        $lado = 1;
    } elseif ((int)$programada['id_equipo2'] === $idEquipoInt) {
        $lado = 2;
    }

    if ($lado === 0) {
        return;
    }

    $jugadores = temp_cargar_integrantes($conn, $idReta, $idEquipoInt);
    $jsonJugadores = temp_exportar_jugadores_json($jugadores);
    $total = count($jugadores);
    $capitan = temp_capitan_equipo($conn, $idReta, $idEquipoInt);

    if ($lado === 1) {
        $stmtU = $conn->prepare("UPDATE r_retasprogramadas SET jugadores_equipo1 = ?, total_equipo1 = ?, capitan_equipo1 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    } else {
        $stmtU = $conn->prepare("UPDATE r_retasprogramadas SET jugadores_equipo2 = ?, total_equipo2 = ?, capitan_equipo2 = ? WHERE CAST(id_reta AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    }

    if ($stmtU) {
        $stmtU->bind_param('siss', $jsonJugadores, $total, $capitan, $idReta);
        $stmtU->execute();
        $stmtU->close();
    }
}

function temp_reasignar_capitan($conn, $idReta, $idEquipo) {
    $idEquipoInt = (int)$idEquipo;
    $stmt = $conn->prepare("SELECT id_retador FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) <> '' AND CAST(id_retador AS CHAR) <> '0' ORDER BY CASE WHEN orden_union > 0 THEN orden_union ELSE numero_jugador END ASC, numero_jugador ASC LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('si', $idReta, $idEquipoInt);
    $stmt->execute();
    $res = $stmt->get_result();
    $nuevoCapitan = '';
    if ($fila = $res->fetch_assoc()) {
        $nuevoCapitan = trim((string)$fila['id_retador']);
    }
    $stmt->close();

    if ($nuevoCapitan !== '') {
        $stmtU = $conn->prepare("UPDATE r_equipotemp SET capitan = ?, estado_jugador = CASE WHEN CAST(id_retador AS CHAR) = CAST(? AS CHAR) THEN 'Capitán' ELSE 'En espera' END WHERE id_reta = ? AND id_equipo = ?");
        if ($stmtU) {
            $stmtU->bind_param('sssi', $nuevoCapitan, $nuevoCapitan, $idReta, $idEquipoInt);
            $stmtU->execute();
            $stmtU->close();
        }
    }

    temp_sincronizar_programada($conn, $idReta, $idEquipoInt);
}

function temp_actualizar_jugador($conn, $idReta, $idEquipo, $idActual, $idObjetivo, $numero, $posicion, $esCapitan) {
    $idObjetivo = trim((string)$idObjetivo);
    $idActual = trim((string)$idActual);
    if ($idObjetivo === '') {
        return ['ok' => false, 'mensaje' => 'No se recibió el jugador.'];
    }
    if (!$esCapitan && $idObjetivo !== $idActual) {
        return ['ok' => false, 'mensaje' => 'Solo puedes modificar tu propio número y posición.'];
    }

    $numero = (int)$numero;
    if ($numero < 0) {
        $numero = 0;
    }
    $posicion = trim((string)$posicion);
    if (mb_strlen($posicion, 'UTF-8') > 50) {
        $posicion = mb_substr($posicion, 0, 50, 'UTF-8');
    }

    $stmt = $conn->prepare("UPDATE r_equipotemp SET numero_jugador = ?, posicion = ? WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'mensaje' => 'No se pudo preparar la actualización.'];
    }
    $idEquipoInt = (int)$idEquipo;
    $stmt->bind_param('issis', $numero, $posicion, $idReta, $idEquipoInt, $idObjetivo);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        temp_sincronizar_programada($conn, $idReta, $idEquipoInt);
    }

    return ['ok' => $ok, 'mensaje' => $ok ? 'Jugador actualizado correctamente.' : 'No se pudo actualizar al jugador.'];
}

function temp_eliminar_jugador($conn, $idReta, $idEquipo, $idActual, $idObjetivo, $esCapitan) {
    $idObjetivo = trim((string)$idObjetivo);
    $idActual = trim((string)$idActual);
    if (!$esCapitan) {
        return ['ok' => false, 'mensaje' => 'Solo el capitán puede eliminar jugadores.'];
    }
    if ($idObjetivo === '') {
        return ['ok' => false, 'mensaje' => 'No se recibió el jugador.'];
    }
    if ($idObjetivo === $idActual) {
        return ['ok' => false, 'mensaje' => 'Para salir del equipo usa el botón de salir.'];
    }

    $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'mensaje' => 'No se pudo preparar la eliminación.'];
    }
    $idEquipoInt = (int)$idEquipo;
    $stmt->bind_param('sis', $idReta, $idEquipoInt, $idObjetivo);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        temp_reasignar_capitan($conn, $idReta, $idEquipoInt);
        temp_sincronizar_programada($conn, $idReta, $idEquipoInt);
    }

    return ['ok' => $ok, 'mensaje' => $ok ? 'Jugador eliminado del equipo.' : 'No se pudo eliminar al jugador.'];
}

function temp_salir_equipo($conn, $idReta, $idEquipo, $idRetador) {
    $stmt = $conn->prepare("DELETE FROM r_equipotemp WHERE id_reta = ? AND id_equipo = ? AND CAST(id_retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if ($stmt) {
        $idEquipoInt = (int)$idEquipo;
        $stmt->bind_param('sis', $idReta, $idEquipoInt, $idRetador);
        $stmt->execute();
        $stmt->close();
        temp_reasignar_capitan($conn, $idReta, $idEquipoInt);
        temp_sincronizar_programada($conn, $idReta, $idEquipoInt);
    }
}

if ($Id_Retador === '') {
    header("Location: ../login.php");
    exit();
}

$perfil = temp_cargar_perfil($conn, $Id_Retador, $usuario);
$Nombre = temp_valor($perfil['Nombre'] ?? $NombreSesion, 'Usuario');
$Apellido = trim((string)($perfil['Apellido'] ?? $ApellidoSesion));
$FotoPerfilUsuario = temp_ruta_foto($perfil['FotoPerfil'] ?? '');
if ($FotoPerfilUsuario === '') {
    $FotoPerfilUsuario = '../assets/doctor.png';
}
$modoNormalizado = temp_normalizar($perfil['ModoPerfil'] ?? '');
$modoOscuroActivo = ($modoNormalizado === 'modo_oscuro' || $modoNormalizado === 'moso_oscuro');
$iniciales = mb_strtoupper(mb_substr($Nombre, 0, 1, 'UTF-8') . mb_substr($Apellido, 0, 1, 'UTF-8'), 'UTF-8');
if (trim($iniciales) === '') {
    $iniciales = 'U';
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
    }
    echo json_encode(['ok' => $okModo, 'modo' => $nuevoModoPerfil], JSON_UNESCAPED_UNICODE);
    exit();
}

$idReta = isset($_GET['id_reta']) ? trim((string)$_GET['id_reta']) : '';
$idProgramada = isset($_GET['id_programada']) ? trim((string)$_GET['id_programada']) : '';
$idEquipo = isset($_GET['id_equipo']) ? trim((string)$_GET['id_equipo']) : '';

if (isset($_POST['id_reta'])) {
    $idReta = trim((string)$_POST['id_reta']);
}
if (isset($_POST['id_programada'])) {
    $idProgramada = trim((string)$_POST['id_programada']);
}
if (isset($_POST['id_equipo'])) {
    $idEquipo = trim((string)$_POST['id_equipo']);
}

$programada = temp_obtener_programada($conn, $idReta, $idProgramada);
if ($programada && $idReta === '') {
    $idReta = trim((string)$programada['id_reta']);
}

$miRegistro = temp_obtener_membresia($conn, $idReta, $idEquipo, $Id_Retador);
if ($miRegistro && $idEquipo === '') {
    $idEquipo = (string)$miRegistro['id_equipo'];
}
if ($miRegistro && $idReta === '') {
    $idReta = (string)$miRegistro['id_reta'];
}

$capitanActual = ($idReta !== '' && $idEquipo !== '') ? temp_capitan_equipo($conn, $idReta, $idEquipo) : '';
$esCapitan = ($capitanActual !== '' && (string)$capitanActual === (string)$Id_Retador);

if (!$miRegistro) {
    $mensajeError = 'No se encontró tu registro dentro de este equipo temporal.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $miRegistro) {
    $accion = trim((string)$_POST['accion']);

    if ($accion === 'actualizar_jugador') {
        $idJugador = isset($_POST['id_jugador']) ? trim((string)$_POST['id_jugador']) : '';
        $numero = isset($_POST['numero_jugador']) ? (int)$_POST['numero_jugador'] : 0;
        $posicion = isset($_POST['posicion']) ? trim((string)$_POST['posicion']) : '';
        $res = temp_actualizar_jugador($conn, $idReta, $idEquipo, $Id_Retador, $idJugador, $numero, $posicion, $esCapitan);
        if ($res['ok']) {
            header('Location: MiEquipoTemp.php?' . http_build_query(['id_reta' => $idReta, 'id_programada' => $idProgramada, 'id_equipo' => $idEquipo, 'ok' => $res['mensaje']]));
            exit();
        }
        $mensajeError = $res['mensaje'];
    }

    if ($accion === 'eliminar_jugador') {
        $idJugador = isset($_POST['id_jugador']) ? trim((string)$_POST['id_jugador']) : '';
        $res = temp_eliminar_jugador($conn, $idReta, $idEquipo, $Id_Retador, $idJugador, $esCapitan);
        if ($res['ok']) {
            header('Location: MiEquipoTemp.php?' . http_build_query(['id_reta' => $idReta, 'id_programada' => $idProgramada, 'id_equipo' => $idEquipo, 'ok' => $res['mensaje']]));
            exit();
        }
        $mensajeError = $res['mensaje'];
    }

    if ($accion === 'salir_equipo') {
        temp_salir_equipo($conn, $idReta, $idEquipo, $Id_Retador);
        header('Location: ../MisRetas.php');
        exit();
    }
}

if (isset($_GET['ok']) && trim((string)$_GET['ok']) !== '') {
    $mensajeOk = trim((string)$_GET['ok']);
}

$miRegistro = temp_obtener_membresia($conn, $idReta, $idEquipo, $Id_Retador);
$capitanActual = ($idReta !== '' && $idEquipo !== '') ? temp_capitan_equipo($conn, $idReta, $idEquipo) : '';
$esCapitan = ($capitanActual !== '' && (string)$capitanActual === (string)$Id_Retador);
$integrantes = ($idReta !== '' && $idEquipo !== '') ? temp_cargar_integrantes($conn, $idReta, $idEquipo) : [];
$nombreEquipo = $miRegistro ? temp_valor($miRegistro['nombre'] ?? '', 'Mi equipo') : 'Mi equipo';
$deporte = $programada ? temp_valor($programada['deporte'] ?? '', 'Deporte') : 'Deporte';
$cancha = $programada ? temp_valor($programada['cancha'] ?? '', 'Cancha por definir') : 'Cancha por definir';
$fecha = $programada ? temp_valor($programada['fecha_reta'] ?? $programada['fecha_programada'] ?? '', 'Fecha pendiente') : 'Fecha pendiente';
$hora = $programada ? temp_valor($programada['hora_reta'] ?? $programada['hora_programada'] ?? '', 'Hora pendiente') : 'Hora pendiente';
$rolUsuario = $esCapitan ? 'Capitán' : 'Retador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mi Equipo Temporal - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
:root{--azul:#1877f2;--azul2:#0ea5e9;--azul-neon:#0099ff;--cyan:#8fefff;--rojo:#ff4b5c;--rojo2:#ff2f45;--texto:#111827;--gris:#6b7280;--sidebar:280px;--fondo:#f0f2f5}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100dvh;font-family:'Poppins',sans-serif;color:var(--texto);background:var(--fondo);overflow-x:hidden;padding-bottom:104px}
.bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.20),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,.18),transparent 360px),linear-gradient(90deg,rgba(24,119,242,.14) 0%,rgba(255,255,255,.02) 48%,rgba(255,75,92,.15) 100%),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
.sidebar{width:var(--sidebar);height:100dvh;position:fixed;left:0;top:0;z-index:1000;padding:22px 14px 112px;background:rgba(255,255,255,.98);backdrop-filter:blur(14px);border-right:3px solid var(--azul-neon);box-shadow:8px 0 24px rgba(0,0,0,.08),0 0 0 2px rgba(24,119,242,.18),0 0 18px rgba(0,153,255,.32);overflow-y:auto;transition:.3s ease}body.sidebar-hidden .sidebar{transform:translateX(-105%)}
.logo-area{display:flex;align-items:center;gap:12px;margin-bottom:22px}.doctor-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff}.logo-text h2{font-family:'Orbitron',sans-serif;font-size:19px;color:var(--azul)}.logo-text p{font-size:12px;color:var(--gris)}.sidebar-boceto{display:flex;flex-direction:column;gap:16px}.perfil-sidebar-card{min-height:78px;display:flex;align-items:center;gap:12px;padding:12px;text-decoration:none;border-radius:22px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.62);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.22);color:#374151}.perfil-sidebar-foto{width:52px;height:52px;border-radius:18px;overflow:hidden;border:2px solid rgba(0,153,255,.76);display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--azul);background:#fff}.perfil-sidebar-foto img{width:100%;height:100%;object-fit:cover}.perfil-sidebar-info span{font-size:11px;font-weight:900;color:var(--rojo2)}.perfil-sidebar-info strong{display:block;font-size:14px;font-weight:900;color:#374151}
.acciones-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.accion-boceto{min-height:95px;text-decoration:none;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.62);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.22);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#374151;font-weight:900;font-size:12px;text-align:center;transition:.25s ease}.accion-boceto img{width:38px;height:38px;object-fit:contain}.accion-boceto:hover,.accion-boceto.active{transform:translateY(-3px);color:var(--rojo2);border-color:rgba(255,75,92,.96)}.cerrar-boceto,.info-boceto a{min-height:48px;text-decoration:none;border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.32);display:flex;align-items:center;justify-content:center;gap:8px;color:#374151;font-weight:900;font-size:12px}.cerrar-boceto{border-color:rgba(255,75,92,.62);color:var(--rojo2)}.cerrar-boceto img{width:26px;height:26px}.info-boceto{display:flex;flex-direction:column;gap:10px}.info-boceto a{justify-content:flex-start;padding:0 14px}.modo-oscuro-panel{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px;border-radius:20px;background:#fff;border:2px solid rgba(0,153,255,.28)}.modo-oscuro-texto{display:flex;gap:7px;font-size:12px;font-weight:900}.switch-modo{width:54px;height:30px;border:none;border-radius:999px;background:#e5e7eb;position:relative;cursor:pointer;box-shadow:inset 0 2px 5px rgba(0,0,0,.16),0 0 0 2px rgba(255,75,92,.28)}.switch-modo span{position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(0,0,0,.25);transition:.25s ease}
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:74px;z-index:900;display:flex;align-items:center;gap:16px;padding:12px 24px;background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:3px solid var(--azul-neon);box-shadow:0 4px 18px rgba(0,0,0,.07);transition:.3s ease}body.sidebar-hidden .topbar{left:0}.menu-toggle{width:50px;height:50px;border:3px solid rgba(0,153,255,.50);border-radius:17px;background:#fff;color:#111827;cursor:pointer;display:flex;align-items:center;justify-content:center}.menu-toggle span{width:25px;height:2px;background:currentColor;position:relative;border-radius:999px}.menu-toggle span:before,.menu-toggle span:after{content:"";position:absolute;left:0;width:25px;height:2px;background:currentColor;border-radius:999px}.menu-toggle span:before{top:-8px}.menu-toggle span:after{top:8px}.topbar-title{flex:1}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.15rem,3vw,2rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-user{display:flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;background:#fff;box-shadow:0 6px 15px rgba(0,0,0,.08);font-weight:900;color:#374151}
.main-content{position:relative;z-index:2;min-height:100dvh;margin-left:var(--sidebar);padding:104px clamp(16px,4vw,42px) 122px;transition:.3s ease}body.sidebar-hidden .main-content{margin-left:0}.page{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:22px}.hero,.panel{border-radius:30px;background:rgba(255,255,255,.94);border:1px solid rgba(17,24,39,.06);box-shadow:0 16px 34px rgba(0,0,0,.10),0 0 18px rgba(0,153,255,.14);padding:clamp(22px,4vw,34px);position:relative;overflow:hidden}.hero:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 14% 18%,rgba(24,119,242,.26),transparent 270px),radial-gradient(circle at 86% 82%,rgba(255,75,92,.25),transparent 320px),linear-gradient(90deg,rgba(24,119,242,.14),rgba(255,255,255,.02) 46%,rgba(255,75,92,.15))}.hero>*{position:relative;z-index:1}.kicker{display:inline-flex;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;color:var(--rojo2);background:#fff;border:2px solid rgba(255,75,92,.35);margin-bottom:12px}.hero h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.7rem,5vw,2.7rem);margin-bottom:10px}.hero p{color:#4b5563;font-size:1rem;line-height:1.7}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:20px}.summary-card{border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.22);padding:13px}.summary-card span{display:block;font-size:11px;font-weight:900;color:#6b7280;text-transform:uppercase}.summary-card strong{display:block;margin-top:4px;color:#111827;font-size:14px}.acciones-head{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.btn{min-height:46px;border:0;text-decoration:none;border-radius:17px;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;font-size:13px;font-weight:900;cursor:pointer;transition:.25s ease;font-family:'Poppins',sans-serif}.btn:hover{transform:translateY(-2px)}.btn-azul{background:linear-gradient(135deg,var(--azul),var(--azul2));color:#fff}.btn-rojo{background:linear-gradient(135deg,var(--rojo),var(--rojo2));color:#fff}.btn-claro{background:#fff;color:var(--azul);border:2px solid rgba(0,153,255,.35)}.alerta{border-radius:18px;padding:14px 16px;font-weight:900}.alerta.ok{background:#f0fdf4;border:2px solid rgba(34,197,94,.28);color:#166534}.alerta.error{background:#fff5f7;border:2px solid rgba(255,75,92,.38);color:#b91c1c}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}.panel-head h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.1rem,3vw,1.6rem)}.badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(24,119,242,.10);color:#1d4ed8;border:1px solid rgba(24,119,242,.22)}.badge.cap{background:rgba(255,75,92,.12);color:#b91c1c;border-color:rgba(255,75,92,.22)}.tabla-wrap{width:100%;overflow:auto;border-radius:22px;border:1px solid rgba(0,153,255,.22);box-shadow:0 10px 22px rgba(0,0,0,.06)}.tabla{width:100%;border-collapse:separate;border-spacing:0;min-width:880px;background:#fff}.tabla th{background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#fff;text-align:left;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.35px;padding:13px 12px}.tabla td{padding:12px;border-bottom:1px solid rgba(17,24,39,.07);font-size:12px;font-weight:800;color:#374151;vertical-align:middle}.tabla tr:nth-child(even) td{background:rgba(24,119,242,.045)}.jugador-mini{display:flex;align-items:center;gap:10px;min-width:220px}.avatar{width:46px;height:46px;border-radius:16px;background:linear-gradient(135deg,var(--azul),var(--rojo));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-family:'Orbitron',sans-serif;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:cover}.jugador-mini strong{display:block;color:#111827}.jugador-mini span{font-size:11px;color:#6b7280}.inline-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.input-mini{width:90px;min-height:38px;border-radius:13px;border:2px solid rgba(0,153,255,.24);padding:8px 10px;font-family:'Poppins',sans-serif;font-weight:900;outline:none}.input-pos{width:170px}.acciones-row{display:flex;gap:8px;flex-wrap:wrap}.btn-mini{min-height:38px;border:0;border-radius:13px;padding:8px 11px;font-size:12px;font-weight:900;cursor:pointer;color:#fff;font-family:'Poppins',sans-serif}.btn-guardar{background:linear-gradient(135deg,var(--azul),var(--azul2))}.btn-eliminar{background:linear-gradient(135deg,var(--rojo),var(--rojo2))}.solo-texto{color:#6b7280;font-weight:900}.empty{min-height:150px;border-radius:22px;display:flex;align-items:center;justify-content:center;text-align:center;padding:28px;background:rgba(24,119,242,.06);border:2px dashed rgba(0,153,255,.28);color:#4b5563;font-weight:900;line-height:1.6}.bottom-nav{position:fixed;left:var(--sidebar);right:0;bottom:0;height:88px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);padding:8px 18px;border-radius:22px 22px 0 0;background:#fff;border-top:2px solid rgba(0,153,255,.72);box-shadow:0 -6px 18px rgba(0,0,0,.06);transition:.3s ease}body.sidebar-hidden .bottom-nav{left:0}.bottom-nav a{position:relative;display:flex;align-items:center;justify-content:center;border-radius:16px}.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain;padding:3px;border-radius:14px}.bottom-nav a.active:after{content:"";position:absolute;width:54px;height:54px;border-radius:15px;border:2px solid rgba(255,75,92,.98);box-shadow:0 0 0 2px rgba(0,153,255,.98)}.mobile-overlay{display:none}
body.dark-mode{color:#e5e7eb;background:#0b1220}body.dark-mode .bg-particles{background:radial-gradient(circle at 18% 20%,rgba(0,153,255,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.18),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,.14),transparent 360px),linear-gradient(135deg,#0b1220 0%,#111827 45%,#1f1117 100%)}body.dark-mode .sidebar,body.dark-mode .topbar,body.dark-mode .bottom-nav,body.dark-mode .hero,body.dark-mode .panel,body.dark-mode .perfil-sidebar-card,body.dark-mode .accion-boceto,body.dark-mode .modo-oscuro-panel,body.dark-mode .info-boceto a,body.dark-mode .cerrar-boceto,body.dark-mode .summary-card{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.78);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.24),0 0 18px rgba(0,153,255,.18)}body.dark-mode .topbar-user,body.dark-mode .kicker,body.dark-mode .btn-claro,.switch-modo{background:#0b1220;color:#e5e7eb}body.dark-mode .logo-text h2,body.dark-mode .topbar-title h1,body.dark-mode .hero h2,body.dark-mode .panel-head h3{color:var(--cyan)}body.dark-mode .hero p,body.dark-mode .summary-card span,body.dark-mode .summary-card strong,body.dark-mode .perfil-sidebar-info strong,body.dark-mode .accion-boceto,body.dark-mode .modo-oscuro-texto,body.dark-mode .info-boceto a,body.dark-mode .cerrar-boceto{color:#d1d5db}body.dark-mode .switch-modo{background:linear-gradient(135deg,#1877f2,#0ea5e9)}body.dark-mode .switch-modo span{transform:translateX(24px)}body.dark-mode .tabla,body.dark-mode .tabla td{background:#0b1220;color:#d1d5db;border-color:rgba(255,255,255,.08)}body.dark-mode .tabla tr:nth-child(even) td{background:rgba(0,153,255,.06)}body.dark-mode .jugador-mini strong{color:#f9fafb}body.dark-mode .jugador-mini span,body.dark-mode .solo-texto{color:#d1d5db}body.dark-mode .input-mini{background:#111827;color:#e5e7eb;border-color:rgba(0,153,255,.35)}body.dark-mode .bottom-nav a img,body.dark-mode .accion-boceto img,body.dark-mode .cerrar-boceto img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05)}
@media screen and (max-width:1000px){.summary{grid-template-columns:repeat(2,1fr)}}@media screen and (max-width:820px){.sidebar{transform:translateX(-105%)}body.sidebar-open .sidebar{transform:translateX(0)}.topbar,body.sidebar-hidden .topbar{left:0}.main-content,body.sidebar-hidden .main-content{margin-left:0}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0}.topbar-user{display:none}.mobile-overlay{display:block;position:fixed;inset:0;z-index:950;background:rgba(17,24,39,.28);opacity:0;visibility:hidden;transition:.25s ease}body.sidebar-open .mobile-overlay{opacity:1;visibility:visible}}@media screen and (max-width:620px){body{padding-bottom:92px}.summary{grid-template-columns:1fr}.hero,.panel{border-radius:24px}.bottom-nav{height:76px;padding:6px 4px}.bottom-nav a img{width:34px;height:34px}.bottom-nav a.active:after{width:44px;height:44px}.acciones-head .btn{width:100%}}
</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="logo-area"><img src="../assets/doctor.png" alt="Logo" class="doctor-logo" onerror="this.style.display='none'"><div class="logo-text"><h2>RETAME</h2><p>Panel deportivo</p></div></div>
    <div class="sidebar-boceto">
        <a href="../MiPerfil.php" class="perfil-sidebar-card"><div class="perfil-sidebar-foto"><?php if ($FotoPerfilUsuario !== ''): ?><img src="<?php echo temp_esc($FotoPerfilUsuario); ?>" alt="Foto" onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='<?php echo temp_esc($iniciales); ?>';"><?php else: ?><?php echo temp_esc($iniciales); ?><?php endif; ?></div><div class="perfil-sidebar-info"><span>Perfil</span><strong><?php echo temp_esc($Nombre); ?></strong></div></a>
        <div class="acciones-grid">
            <a href="../Equipo/UnirmeOtroEquipo.php" class="accion-boceto"><img src="../Imagenes/ImgUnion.png" alt=""><span>Unirme equipo</span></a>
            <a href="../Equipo/CrearEquipo.php" class="accion-boceto"><img src="../Imagenes/ImgCreacion.png" alt=""><span>Crear equipo</span></a>
            <a href="../Solicitudes.php" class="accion-boceto"><img src="../Imagenes/ImgSolicitud.png" alt=""><span>Solicitud</span></a>
            <a href="../Equipo/Mis_Equipos.php" class="accion-boceto active"><img src="../Imagenes/ImgEquipo.png" alt=""><span>Equipo</span></a>
            <a href="../Ligas/liga.php" class="accion-boceto"><img src="../Imagenes/ImgLigas.png" alt=""><span>Ligas</span></a>
            <a href="retar.php" class="accion-boceto"><img src="../Imagenes/ImgReta.png" alt=""><span>Retar</span></a>
            <a href="../Canchas/Canchas.php" class="accion-boceto"><img src="../Imagenes/ImgCanchas.png" alt=""><span>Canchas</span></a>
            <a href="../Amigos.php" class="accion-boceto"><img src="../Imagenes/ImgAmigos.png" alt=""><span>Amigos</span></a>
        </div>
        <a href="../login.php" class="cerrar-boceto"><img src="../Imagenes/ImgCerrar.png" alt=""><span>Cerrar sesión</span></a>
        <div class="info-boceto"><a href="../Informacion.php">Información</a><a href="../AcercaDe.php">Acerca de</a><a href="../SoporteTecnico.php">Soporte técnico</a></div>
        <div class="modo-oscuro-panel"><div class="modo-oscuro-texto"><span>🌙</span><strong>Modo oscuro</strong></div><button type="button" class="switch-modo" id="darkModeToggle"><span></span></button></div>
    </div>
</aside>
<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="topbar-title"><h1>Mi equipo de <?php echo temp_esc($Nombre); ?></h1></div>
    <div class="topbar-user"><span>👤</span><span><?php echo temp_esc($Nombre); ?></span></div>
</header>
<main class="main-content">
    <div class="page">
        <section class="hero">
            <span class="kicker">👥 Equipo temporal</span>
            <h2><?php echo temp_esc($nombreEquipo); ?></h2>
            <p>Consulta los integrantes de tu equipo. Si eres capitán puedes modificar número y posición de todos, además de eliminar jugadores. Si eres retador normal, solo puedes modificar tu propio número y posición.</p>
            <div class="summary">
                <div class="summary-card"><span>ID reta</span><strong><?php echo temp_esc(temp_valor($idReta)); ?></strong></div>
                <div class="summary-card"><span>ID equipo</span><strong><?php echo temp_esc(temp_valor($idEquipo)); ?></strong></div>
                <div class="summary-card"><span>Deporte</span><strong><?php echo temp_esc($deporte); ?></strong></div>
                <div class="summary-card"><span>Tu rol</span><strong><?php echo temp_esc($rolUsuario); ?></strong></div>
                <div class="summary-card"><span>Cancha</span><strong><?php echo temp_esc($cancha); ?></strong></div>
                <div class="summary-card"><span>Fecha</span><strong><?php echo temp_esc($fecha); ?></strong></div>
                <div class="summary-card"><span>Hora</span><strong><?php echo temp_esc($hora); ?></strong></div>
                <div class="summary-card"><span>Integrantes</span><strong><?php echo count($integrantes); ?></strong></div>
            </div>
            <div class="acciones-head">
                <a class="btn btn-azul" href="../MisRetas.php">⬅️ Volver a Mis Retas</a>
                <?php if ($miRegistro): ?>
                    <form method="POST" action="MiEquipoTemp.php?<?php echo temp_esc(http_build_query(['id_reta' => $idReta, 'id_programada' => $idProgramada, 'id_equipo' => $idEquipo])); ?>" onsubmit="return confirm('¿Seguro que quieres salir de este equipo?');">
                        <input type="hidden" name="accion" value="salir_equipo">
                        <input type="hidden" name="id_reta" value="<?php echo temp_esc($idReta); ?>">
                        <input type="hidden" name="id_programada" value="<?php echo temp_esc($idProgramada); ?>">
                        <input type="hidden" name="id_equipo" value="<?php echo temp_esc($idEquipo); ?>">
                        <button type="submit" class="btn btn-rojo">Salir del equipo</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($mensajeOk !== ''): ?><div class="alerta ok">✅ <?php echo temp_esc($mensajeOk); ?></div><?php endif; ?>
        <?php if ($mensajeError !== ''): ?><div class="alerta error">⚠️ <?php echo temp_esc($mensajeError); ?></div><?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h3>Jugadores registrados</h3><span class="badge <?php echo $esCapitan ? 'cap' : ''; ?>"><?php echo $esCapitan ? 'Permisos de capitán' : 'Permisos de retador'; ?></span></div>
            <?php if (empty($integrantes)): ?>
                <div class="empty">No hay jugadores registrados en este equipo temporal.</div>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Jugador</th>
                                <th>ID retador</th>
                                <th>Número</th>
                                <th>Posición</th>
                                <th>Rango</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($integrantes as $jugador): ?>
                                <?php
                                    $idJugador = trim((string)($jugador['id_retador'] ?? ''));
                                    $soyYo = ((string)$idJugador === (string)$Id_Retador);
                                    $puedeEditar = $esCapitan || $soyYo;
                                    $puedeEliminar = $esCapitan && !$soyYo;
                                    $nombreJ = temp_jugador_nombre($jugador);
                                    $apellidoJ = temp_jugador_apellido($jugador);
                                    $fotoJ = temp_ruta_foto($jugador['retador_foto_bd'] ?? '');
                                    $inicialJ = mb_strtoupper(mb_substr($nombreJ, 0, 1, 'UTF-8') . mb_substr($apellidoJ, 0, 1, 'UTF-8'), 'UTF-8');
                                    if (trim($inicialJ) === '') { $inicialJ = 'J'; }
                                ?>
                                <tr>
                                    <td>
                                        <div class="jugador-mini">
                                            <div class="avatar"><?php if ($fotoJ !== ''): ?><img src="<?php echo temp_esc($fotoJ); ?>" alt="Foto" onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='<?php echo temp_esc($inicialJ); ?>';"><?php else: ?><?php echo temp_esc($inicialJ); ?><?php endif; ?></div>
                                            <div><strong><?php echo temp_esc(temp_valor(trim($nombreJ . ' ' . $apellidoJ), 'Jugador')); ?></strong><span><?php echo ((string)$idJugador === (string)$capitanActual) ? '⭐ Capitán' : ($soyYo ? 'Tú' : 'Integrante'); ?></span></div>
                                        </div>
                                    </td>
                                    <td><?php echo temp_esc(temp_valor($idJugador)); ?></td>
                                    <td colspan="2">
                                        <?php if ($puedeEditar): ?>
                                            <form class="inline-form" method="POST" action="MiEquipoTemp.php?<?php echo temp_esc(http_build_query(['id_reta' => $idReta, 'id_programada' => $idProgramada, 'id_equipo' => $idEquipo])); ?>">
                                                <input type="hidden" name="accion" value="actualizar_jugador">
                                                <input type="hidden" name="id_reta" value="<?php echo temp_esc($idReta); ?>">
                                                <input type="hidden" name="id_programada" value="<?php echo temp_esc($idProgramada); ?>">
                                                <input type="hidden" name="id_equipo" value="<?php echo temp_esc($idEquipo); ?>">
                                                <input type="hidden" name="id_jugador" value="<?php echo temp_esc($idJugador); ?>">
                                                <input class="input-mini" type="number" name="numero_jugador" min="0" value="<?php echo temp_esc($jugador['numero_jugador'] ?? 0); ?>" required>
                                                <input class="input-mini input-pos" type="text" name="posicion" maxlength="50" value="<?php echo temp_esc($jugador['posicion'] ?? ''); ?>" placeholder="Posición">
                                                <button class="btn-mini btn-guardar" type="submit">Guardar</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="solo-texto">#<?php echo temp_esc(temp_valor($jugador['numero_jugador'] ?? '')); ?> · <?php echo temp_esc(temp_valor($jugador['posicion'] ?? '', 'Sin posición')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo temp_esc(temp_valor(temp_jugador_rango($jugador), 'Sin rango')); ?></td>
                                    <td><?php echo temp_esc(temp_valor($jugador['estado_jugador'] ?? '', 'En espera')); ?></td>
                                    <td>
                                        <div class="acciones-row">
                                            <?php if ($puedeEliminar): ?>
                                                <form method="POST" action="MiEquipoTemp.php?<?php echo temp_esc(http_build_query(['id_reta' => $idReta, 'id_programada' => $idProgramada, 'id_equipo' => $idEquipo])); ?>" onsubmit="return confirm('¿Eliminar a este jugador del equipo?');">
                                                    <input type="hidden" name="accion" value="eliminar_jugador">
                                                    <input type="hidden" name="id_reta" value="<?php echo temp_esc($idReta); ?>">
                                                    <input type="hidden" name="id_programada" value="<?php echo temp_esc($idProgramada); ?>">
                                                    <input type="hidden" name="id_equipo" value="<?php echo temp_esc($idEquipo); ?>">
                                                    <input type="hidden" name="id_jugador" value="<?php echo temp_esc($idJugador); ?>">
                                                    <button class="btn-mini btn-eliminar" type="submit">Eliminar</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="solo-texto"><?php echo $soyYo ? 'Tu registro' : 'Solo lectura'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<nav class="bottom-nav">
    <a href="../Perfil2.php"><img src="../Imagenes/ImgInicio.png" alt=""></a>
    <a href="retar.php"><img src="../Imagenes/ImgReta.png" alt=""></a>
    <a href="../Ligas/liga.php"><img src="../Imagenes/ImgLigas.png" alt=""></a>
    <a href="../Agenda.php"><img src="../Imagenes/ImgAgenda.png" alt=""></a>
    <a href="../Notificaciones.php"><img src="../Imagenes/ImgNoti.png" alt=""><?php if ($nuevas_count > 0): ?><span class="bottom-noti">!</span><?php endif; ?></a>
    <a href="../MiPerfil.php"><img src="../Imagenes/ImgPerfil.png" alt=""></a>
</nav>
<script>
const body=document.body;
const menuToggle=document.getElementById('menuToggle');
const mobileOverlay=document.getElementById('mobileOverlay');
const darkModeToggle=document.getElementById('darkModeToggle');
if(menuToggle){menuToggle.addEventListener('click',()=>{if(window.innerWidth<=820){body.classList.toggle('sidebar-open')}else{body.classList.toggle('sidebar-hidden')}})}
if(mobileOverlay){mobileOverlay.addEventListener('click',()=>body.classList.remove('sidebar-open'))}
window.addEventListener('resize',()=>{if(window.innerWidth>820){body.classList.remove('sidebar-open')}});
if(darkModeToggle){darkModeToggle.addEventListener('click',()=>{const activar=!body.classList.contains('dark-mode');body.classList.toggle('dark-mode',activar);const datos=new FormData();datos.append('accion','actualizar_modo_perfil');datos.append('modo',activar?'oscuro':'predeterminado');fetch(window.location.href,{method:'POST',body:datos,credentials:'same-origin'}).catch(()=>{})})}
</script>
</body>
</html>
