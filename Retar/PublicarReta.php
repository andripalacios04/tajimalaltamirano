ç<?php
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

function obtenerValorSesion($datos, $llaves) {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return '';
}

function tablaDisponible($conn, $candidatas) {
    foreach ($candidatas as $tabla) {
        $tablaEscapada = $conn->real_escape_string($tabla);
        $res = $conn->query("SHOW TABLES LIKE '$tablaEscapada'");
        if ($res && $res->num_rows > 0) {
            return $tabla;
        }
    }
    return '';
}

function tablaSql($tabla) {
    return '`' . str_replace('`', '``', $tabla) . '`';
}

function columnaExiste($conn, $tabla, $columna) {
    if ($tabla === '') return false;
    $tablaEscapada = $conn->real_escape_string($tabla);
    $columnaEscapada = $conn->real_escape_string($columna);
    $res = $conn->query("SHOW COLUMNS FROM `$tablaEscapada` LIKE '$columnaEscapada'");
    return ($res && $res->num_rows > 0);
}

function obtenerRetador($conn, $idRetador, $usuarios) {
    $datos = [
        'Id_Retador' => $idRetador,
        'Nombre' => obtenerValorSesion($usuarios, ['Nombre', 'nombre']),
        'Apellido' => obtenerValorSesion($usuarios, ['Apellido', 'apellido']),
        'CodigoPostal' => obtenerValorSesion($usuarios, ['CodigoPostal', 'Codigo_Postal', 'Codigo postal', 'codigo_postal', 'codigoPostal', 'codigo postal', 'CP', 'cp']),
        'FotoPerfil' => obtenerValorSesion($usuarios, ['FotoPerfil', 'Fotoperfil', 'fotoPerfil', 'foto_perfil']),
        'Rango' => obtenerValorSesion($usuarios, ['Rango', 'rango']),
        'ModoPerfil' => obtenerValorSesion($usuarios, ['ModoPerfil', 'modoPerfil'])
    ];

    $sql = "SELECT Id_Retador, Nombre, Apellido, CodigoPostal, FotoPerfil, ModoPerfil FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("s", $idRetador);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($fila = $res->fetch_assoc()) {
            foreach ($fila as $k => $v) {
                if ($v !== null && trim((string)$v) !== '') {
                    if ($k === 'CodigoPostal') {
                        $datos['CodigoPostal'] = trim((string)$v);
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

function resolverFotoPerfil($foto) {
    $foto = trim((string)$foto);

    if ($foto === '') {
        return '../Imagenes/ImgPerfil.png';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $foto)) {
        return $foto;
    }

    $candidatas = [
        $foto,
        '../' . $foto,
        '../Imagenes/' . $foto,
        '../uploads/' . $foto,
        '../FotosPerfil/' . $foto
    ];

    foreach ($candidatas as $ruta) {
        if (file_exists($ruta)) {
            return $ruta;
        }
    }

    return $foto;
}

function generarIdReta($conn) {
    do {
        $id = random_int(100000, 999999);
        $stmt = $conn->prepare("SELECT id_reta FROM R_RetasPublicadas WHERE id_reta = ? LIMIT 1");
        if (!$stmt) return $id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->store_result();
        $existe = $stmt->num_rows > 0;
        $stmt->close();
    } while ($existe);

    return $id;
}

function obtenerDeportePorId($conn, $idDeporte) {
    $sql = "SELECT Id_Deporte, Nombre, Tipo FROM deporte WHERE Id_Deporte = ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) return null;

    $stmt->bind_param("s", $idDeporte);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res->fetch_assoc();
    $stmt->close();

    return $fila ? $fila : null;
}

function obtenerCanchaPorId($conn, $idCancha, $tablaCanchas) {
    if ($tablaCanchas === '') return null;

    $tabla = tablaSql($tablaCanchas);

    $sql = "SELECT 
                Id_Cancha,
                Nombre,
                Direccion,
                Codigo_Postal,
                Ciudad,
                Estado,
                Pais,
                Tipo_Cancha,
                Calificacion,
                Foto,
                Deporte1,
                Deporte2,
                Deporte3
            FROM $tabla
            WHERE Id_Cancha = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) return null;

    $stmt->bind_param("s", $idCancha);
    $stmt->execute();
    $res = $stmt->get_result();
    $fila = $res->fetch_assoc();
    $stmt->close();

    return $fila ? $fila : null;
}

$Id_Retador = obtenerValorSesion($usuarios, ['Id_Retador', 'id_retador']);

if ($Id_Retador === '') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';

    $stmtModo = $conn->prepare("UPDATE retador SET ModoPerfil = ? WHERE Id_Retador = ? LIMIT 1");

    if (!$stmtModo) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo preparar la actualización del modo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtModo->bind_param("ss", $nuevoModoPerfil, $Id_Retador);
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

$retador = obtenerRetador($conn, $Id_Retador, $usuarios);
$Nombre = $retador['Nombre'] !== '' ? $retador['Nombre'] : 'Usuario';
$Apellido = $retador['Apellido'];
$CodigoPostalUsuario = $retador['CodigoPostal'];
$RangoUsuario = $retador['Rango'] !== '' ? $retador['Rango'] : 'Retador';
$FotoPerfilUsuario = resolverFotoPerfil($retador['FotoPerfil']);

$modoPerfilNormalizado = mb_strtolower(trim((string)$retador['ModoPerfil']), 'UTF-8');
$modoOscuroActivo = ($modoPerfilNormalizado === 'modo oscuro' || $modoPerfilNormalizado === 'moso oscuro');

$tablaCanchas = tablaDisponible($conn, ['C_Canchas', 'c_canchas']);
$tablaEquipoTemp = tablaDisponible($conn, ['R_EquipoTemp', 'r_equipotemp']);
$tablaEquipoTempTieneIdReta = ($tablaEquipoTemp !== '' && columnaExiste($conn, $tablaEquipoTemp, 'id_reta'));

$deportes = [];
$resDep = $conn->query("SELECT Id_Deporte, Nombre, Tipo FROM deporte ORDER BY Nombre ASC");

if ($resDep) {
    while ($fila = $resDep->fetch_assoc()) {
        $deportes[] = $fila;
    }
}

$idDeporteSeleccionado = '';

if (isset($_GET['id_deporte'])) {
    $idDeporteSeleccionado = trim((string)$_GET['id_deporte']);
}

if (isset($_POST['id_deporte'])) {
    $idDeporteSeleccionado = trim((string)$_POST['id_deporte']);
}

$deporteSeleccionado = null;

if ($idDeporteSeleccionado !== '') {
    $deporteSeleccionado = obtenerDeportePorId($conn, $idDeporteSeleccionado);
}

$canchas = [];
$mensajeCanchas = '';

if ($deporteSeleccionado && $tablaCanchas !== '') {
    $tabla = tablaSql($tablaCanchas);
    $nombreDeporteBuscado = trim((string)$deporteSeleccionado['Nombre']);
    $nombreDepLike = '%' . $nombreDeporteBuscado . '%';
    $cpReferencia = preg_replace('/\D+/', '', (string)$CodigoPostalUsuario);

    $sqlCanchas = "SELECT 
                        Id_Cancha,
                        Nombre,
                        Direccion,
                        Codigo_Postal,
                        Ciudad,
                        Estado,
                        Pais,
                        Tipo_Cancha,
                        Calificacion,
                        Foto,
                        Deporte1,
                        Deporte2,
                        Deporte3,
                        CASE 
                            WHEN Codigo_Postal REGEXP '^[0-9]+$' AND ? <> '' THEN ABS(CAST(Codigo_Postal AS SIGNED) - CAST(? AS SIGNED))
                            ELSE 999999
                        END AS Distancia_CP
                   FROM $tabla
                   WHERE COALESCE(Deporte1, '') LIKE ?
                      OR COALESCE(Deporte2, '') LIKE ?
                      OR COALESCE(Deporte3, '') LIKE ?
                   ORDER BY
                        CASE WHEN Codigo_Postal = ? THEN 0 ELSE 1 END ASC,
                        CASE 
                            WHEN Codigo_Postal REGEXP '^[0-9]+$' AND ? <> '' THEN ABS(CAST(Codigo_Postal AS SIGNED) - CAST(? AS SIGNED))
                            ELSE 999999
                        END ASC,
                        Nombre ASC";

    $stmtC = $conn->prepare($sqlCanchas);

    if ($stmtC) {
        $stmtC->bind_param(
            "ssssssss",
            $cpReferencia,
            $cpReferencia,
            $nombreDepLike,
            $nombreDepLike,
            $nombreDepLike,
            $CodigoPostalUsuario,
            $cpReferencia,
            $cpReferencia
        );
        $stmtC->execute();
        $resC = $stmtC->get_result();

        while ($filaC = $resC->fetch_assoc()) {
            $canchas[] = $filaC;
        }

        $stmtC->close();
    } else {
        $mensajeCanchas = 'No se pudo consultar la tabla de canchas. Revisa que tenga Deporte1, Deporte2 y Deporte3.';
    }
} elseif ($deporteSeleccionado && $tablaCanchas === '') {
    $mensajeCanchas = 'No encontré la tabla C_Canchas o c_canchas.';
}

$errores = [];
$publicada = false;
$idRetaPublicada = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'publicar_reta') {
    if (!$deporteSeleccionado) {
        $errores[] = 'Selecciona un deporte válido.';
    }

    $tipoDeporte = $deporteSeleccionado ? mb_strtolower(trim((string)$deporteSeleccionado['Tipo']), 'UTF-8') : '';
    $esEquipo = ($tipoDeporte === 'equipo' || $tipoDeporte === 'en equipo');

    $canchaModo = isset($_POST['cancha_modo']) ? trim((string)$_POST['cancha_modo']) : 'manual';
    $idCanchaSeleccionada = isset($_POST['id_cancha']) ? trim((string)$_POST['id_cancha']) : '';

    $cancha = '';
    $direccion = '';
    $codigoPostal = '';

    $hora = isset($_POST['hora']) ? trim((string)$_POST['hora']) : '';
    $fecha = isset($_POST['fecha']) ? trim((string)$_POST['fecha']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim((string)$_POST['descripcion']) : '';

    if ($canchaModo === 'registrada') {
        if ($idCanchaSeleccionada === '') {
            $errores[] = 'Selecciona una cancha registrada o usa la opción de cancha manual.';
        } else {
            $canchaBD = obtenerCanchaPorId($conn, $idCanchaSeleccionada, $tablaCanchas);

            if (!$canchaBD) {
                $errores[] = 'La cancha seleccionada no existe en la tabla C_Canchas.';
            } else {
                $cancha = trim((string)$canchaBD['Nombre']);
                $direccion = trim((string)$canchaBD['Direccion']);

                $ubicacionCancha = [];
                if (!empty($canchaBD['Ciudad'])) $ubicacionCancha[] = trim((string)$canchaBD['Ciudad']);
                if (!empty($canchaBD['Estado'])) $ubicacionCancha[] = trim((string)$canchaBD['Estado']);
                if (!empty($canchaBD['Pais'])) $ubicacionCancha[] = trim((string)$canchaBD['Pais']);

                if (!empty($ubicacionCancha)) {
                    $direccion .= ' · ' . implode(', ', $ubicacionCancha);
                }

                $codigoPostal = trim((string)$canchaBD['Codigo_Postal']);
            }
        }
    } else {
        $cancha = isset($_POST['cancha_manual']) ? trim((string)$_POST['cancha_manual']) : '';
        $direccion = isset($_POST['direccion']) ? trim((string)$_POST['direccion']) : '';
        $codigoPostal = isset($_POST['codigo_postal']) ? trim((string)$_POST['codigo_postal']) : '';
    }

    if ($cancha === '') $errores[] = 'Escribe o selecciona la cancha.';
    if ($direccion === '') $errores[] = 'Escribe la dirección.';
    if ($codigoPostal === '') $codigoPostal = $CodigoPostalUsuario;
    if ($codigoPostal === '') $errores[] = 'Escribe el código postal.';
    if ($hora === '') $errores[] = 'Selecciona la hora.';
    if ($fecha === '') $errores[] = 'Selecciona la fecha.';

    if (empty($errores)) {
        $idReta = generarIdReta($conn);
        $idEquipo1 = ($idReta * 10) + 1;
        $idEquipo2 = ($idReta * 10) + 2;
        $equipo1 = $esEquipo ? 'Equipo 1' : $Nombre;
        $equipo2 = $esEquipo ? 'Equipo 2' : 'Rival pendiente';
        $capitanEquipo1 = $Id_Retador;
        $capitanEquipo2 = '0';
        $estadoReta = 'Publicada';
        $resulEquipo1 = 0;
        $resulEquipo2 = 0;
        $expulsiones = '';
        $nombreDeporte = $deporteSeleccionado['Nombre'];
        $idCreador = $Id_Retador;

        $conn->begin_transaction();

        $sqlReta = "INSERT INTO R_RetasPublicadas
            (id_reta, id_creador, equipo1, equipo2, capitan_equipo1, capitan_equipo2, codigo_postal, cancha, deporte, direccion, hora, fecha, estado_reta, descripcion, resul_equipo1, resul_equipo2, expulsiones)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmtReta = $conn->prepare($sqlReta);

        if (!$stmtReta) {
            $errores[] = 'No se pudo preparar el guardado de la reta. Revisa la tabla R_RetasPublicadas.';
            $conn->rollback();
        } else {
            $typesReta = "i" . str_repeat("s", 13) . "iis";

            $stmtReta->bind_param(
                $typesReta,
                $idReta,
                $idCreador,
                $equipo1,
                $equipo2,
                $capitanEquipo1,
                $capitanEquipo2,
                $codigoPostal,
                $cancha,
                $nombreDeporte,
                $direccion,
                $hora,
                $fecha,
                $estadoReta,
                $descripcion,
                $resulEquipo1,
                $resulEquipo2,
                $expulsiones
            );

            if (!$stmtReta->execute()) {
                $errores[] = 'No se pudo guardar la reta: ' . $stmtReta->error;
                $stmtReta->close();
                $conn->rollback();
            } else {
                $stmtReta->close();

                $okEquipos = true;

                if ($esEquipo) {
                    if ($tablaEquipoTemp === '') {
                        $okEquipos = false;
                        $errores[] = 'No encontré la tabla R_EquipoTemp o r_equipotemp.';
                    } elseif (!$tablaEquipoTempTieneIdReta) {
                        $okEquipos = false;
                        $errores[] = 'La tabla r_equipotemp todavía no tiene la columna id_reta. Ejecuta primero el SQL de actualización.';
                    } else {
                        $tablaEquipoSql = tablaSql($tablaEquipoTemp);
                        $sqlEquipo = "INSERT INTO $tablaEquipoSql
                            (id_equipo, id_reta, nombre, capitan, id_retador, nombre_retador, apellido_retador, codigo_postal, numero_jugador, posicion, estado_jugador, resultados_jugador, quejas, rango)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                        $stmtEquipo = $conn->prepare($sqlEquipo);

                        if (!$stmtEquipo) {
                            $okEquipos = false;
                            $errores[] = 'No se pudo preparar el guardado de equipos. Revisa la tabla r_equipotemp.';
                        } else {
                            $numeroJugador = 0;
                            $posicion = '';
                            $estadoJugador = 'Capitán';
                            $resultadosJugador = '';
                            $quejas = '';
                            $idRetaTemp = (string)$idReta;
                            $typesEquipo = "i" . str_repeat("s", 7) . "i" . str_repeat("s", 5);

                            $stmtEquipo->bind_param(
                                $typesEquipo,
                                $idEquipo1,
                                $idRetaTemp,
                                $equipo1,
                                $capitanEquipo1,
                                $Id_Retador,
                                $Nombre,
                                $Apellido,
                                $codigoPostal,
                                $numeroJugador,
                                $posicion,
                                $estadoJugador,
                                $resultadosJugador,
                                $quejas,
                                $RangoUsuario
                            );

                            if (!$stmtEquipo->execute()) {
                                $okEquipos = false;
                                $errores[] = 'No se pudo guardar el capitán del equipo 1: ' . $stmtEquipo->error;
                            }

                            if ($okEquipos) {
                                $estadoJugador2 = 'Esperando jugadores';
                                $nombrePendiente = 'Esperando';
                                $apellidoPendiente = 'jugadores';
                                $idRetadorPendiente = '0';
                                $rangoPendiente = '';
                                $posicionPendiente = '';
                                $numeroPendiente = 0;

                                $stmtEquipo->bind_param(
                                    $typesEquipo,
                                    $idEquipo2,
                                    $idRetaTemp,
                                    $equipo2,
                                    $capitanEquipo2,
                                    $idRetadorPendiente,
                                    $nombrePendiente,
                                    $apellidoPendiente,
                                    $codigoPostal,
                                    $numeroPendiente,
                                    $posicionPendiente,
                                    $estadoJugador2,
                                    $resultadosJugador,
                                    $quejas,
                                    $rangoPendiente
                                );

                                if (!$stmtEquipo->execute()) {
                                    $okEquipos = false;
                                    $errores[] = 'No se pudo crear el equipo 2: ' . $stmtEquipo->error;
                                }
                            }

                            $stmtEquipo->close();
                        }
                    }
                }

                if ($okEquipos) {
                    $conn->commit();
                    $publicada = true;
                    $idRetaPublicada = $idReta;
                } else {
                    $conn->rollback();
                }
            }
        }
    }
}

$tipoVista = '';
$esEquipoVista = false;

if ($deporteSeleccionado) {
    $tipoNormalizado = mb_strtolower(trim((string)$deporteSeleccionado['Tipo']), 'UTF-8');
    $esEquipoVista = ($tipoNormalizado === 'equipo' || $tipoNormalizado === 'en equipo');
    $tipoVista = $esEquipoVista ? 'Reta en equipo' : 'Reta individual';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Publicar reta - RETAME</title>
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
    --azul-neon-fuerte:rgba(0,153,255,0.95);
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

body.sidebar-hidden .sidebar{
    transform:translateX(-105%);
}

.logo-area{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:22px;
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

.switch-modo:disabled{
    opacity:0.65;
    cursor:not-allowed;
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

body.topbar-compact .menu-toggle{
    width:42px;
    height:42px;
    border-radius:14px;
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

body.topbar-compact .topbar-title h1{
    font-size:clamp(1rem,2.4vw,1.45rem);
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

body.topbar-compact .topbar-user{
    padding:7px 14px;
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

.publicar-wrapper{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:22px;
}

.publicar-grid{
    width:100%;
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
    padding:clamp(26px,4vw,46px);
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

.perfil-card > *{
    position:relative;
    z-index:1;
}

.hero-user{
    width:100%;
    display:flex;
    justify-content:center;
    margin-bottom:14px;
}

.hero-user-inner{
    display:inline-flex;
    align-items:center;
    gap:12px;
    padding:10px 14px;
    border-radius:999px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.32);
    box-shadow:0 10px 22px rgba(0,0,0,0.08);
}

.hero-avatar{
    width:52px;
    height:52px;
    border-radius:50%;
    object-fit:cover;
    border:3px solid #ffffff;
    box-shadow:
        0 0 0 2px rgba(24,119,242,0.18),
        0 8px 16px rgba(0,0,0,0.10);
}

.hero-user-data{
    display:flex;
    flex-direction:column;
    text-align:left;
    line-height:1.1;
}

.hero-user-data strong{
    font-size:14px;
    color:#111827;
    font-weight:900;
}

.hero-user-data span{
    margin-top:4px;
    font-size:12px;
    color:#6b7280;
    font-weight:800;
}

.perfil-card h2{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.8rem,5vw,2.7rem);
    margin-bottom:15px;
    line-height:1.1;
}

.perfil-card p{
    max-width:650px;
    color:#4b5563;
    font-size:clamp(0.96rem,2.4vw,1.08rem);
    line-height:1.7;
}

.hero-chips{
    width:100%;
    display:flex;
    justify-content:center;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
}

.hero-chip,
.reta-chip{
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
}

.hero-chip.rojo{
    border-color:rgba(255,75,92,0.42);
    color:var(--rojo2);
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

.action-card.azul .big-icon{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    border-color:rgba(0,153,255,0.55);
}

.action-card strong{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
}

.action-card span:last-child{
    font-size:13px;
    color:#4b5563;
    font-weight:700;
}

.reta-panel{
    width:100%;
    border-radius:30px;
    background:rgba(255,255,255,0.95);
    border:1px solid rgba(17,24,39,0.06);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(20px,4vw,34px);
    position:relative;
    overflow:hidden;
}

.reta-panel::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 9% 0%,rgba(24,119,242,0.12),transparent 250px),
        radial-gradient(circle at 96% 20%,rgba(255,75,92,0.10),transparent 290px);
    pointer-events:none;
}

.reta-panel > *{
    position:relative;
    z-index:1;
}

.panel-title{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:18px;
}

.panel-title h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.45rem);
}

.panel-title span{
    color:#6b7280;
    font-size:13px;
    font-weight:800;
    text-align:right;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    font-weight:900;
    color:#374151;
    font-size:13px;
}

.form-control{
    width:100%;
    min-height:52px;
    border-radius:18px;
    border:2px solid rgba(0,153,255,0.28);
    background:#fff;
    padding:12px 15px;
    font-family:'Poppins',sans-serif;
    font-weight:700;
    color:#111827;
    outline:none;
    transition:0.25s ease;
    box-shadow:0 8px 18px rgba(0,0,0,0.04);
}

textarea.form-control{
    min-height:120px;
    resize:vertical;
}

.form-control:focus{
    border-color:rgba(24,119,242,0.75);
    box-shadow:
        0 0 0 3px rgba(24,119,242,0.15),
        0 10px 22px rgba(0,0,0,0.08);
}

.form-control[readonly]{
    background:#f3f4f6;
    color:#4b5563;
    cursor:not-allowed;
}

.btn-row{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:20px;
}

.btn-main{
    min-height:52px;
    border:none;
    border-radius:18px;
    padding:13px 22px;
    color:#fff;
    font-weight:900;
    font-family:'Poppins',sans-serif;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    box-shadow:0 12px 24px rgba(0,0,0,0.14);
    transition:0.25s ease;
    text-decoration:none;
    cursor:pointer;
}

.btn-main:hover{
    transform:translateY(-3px);
    box-shadow:0 18px 32px rgba(0,0,0,0.18);
}

.btn-main.azul{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    border:2px solid rgba(0,153,255,0.45);
}

.btn-main.rojo{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    border:2px solid rgba(255,75,92,0.52);
}

.btn-mini{
    min-height:52px;
    padding:11px 16px;
    font-size:13px;
    white-space:nowrap;
}

.alerta{
    border-radius:20px;
    padding:16px 18px;
    font-weight:800;
    line-height:1.55;
}

.alerta.error{
    background:#fff5f5;
    color:#991b1b;
    border:2px solid rgba(255,75,92,0.42);
}

.alerta.info{
    background:#f3f8ff;
    color:#1d4ed8;
    border:2px solid rgba(24,119,242,0.26);
}

.deporte-form{
    display:grid;
    grid-template-columns:1fr auto;
    gap:14px;
    align-items:end;
}

.cancha-selector{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.cancha-tools{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:center;
}

.cancha-ayuda{
    font-size:12px;
    font-weight:800;
    color:#6b7280;
    line-height:1.45;
}

.cancha-lista-scroll{
    width:100%;
    max-width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    padding:12px;
    border-radius:24px;
    background:#f8fbff;
    border:2px solid rgba(0,153,255,0.22);
    display:flex;
    flex-direction:row;
    gap:14px;
    scrollbar-width:thin;
    scroll-snap-type:x proximity;
}

.cancha-lista-scroll::-webkit-scrollbar{
    height:10px;
}

.cancha-lista-scroll::-webkit-scrollbar-track{
    background:#eaf4ff;
    border-radius:999px;
}

.cancha-lista-scroll::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#1877f2,#ff4b5c);
    border-radius:999px;
}

.cancha-opcion{
    flex:0 0 255px;
    min-height:245px;
    border:none;
    text-align:left;
    border-radius:24px;
    background:#fff;
    border:2px solid rgba(0,153,255,0.26);
    box-shadow:
        0 10px 22px rgba(0,0,0,0.08),
        0 0 0 2px rgba(24,119,242,0.06);
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:12px;
    cursor:pointer;
    transition:0.22s ease;
    font-family:'Poppins',sans-serif;
    color:#111827;
    scroll-snap-align:start;
    position:relative;
    overflow:hidden;
}

.cancha-opcion:hover{
    transform:translateY(-3px);
    border-color:rgba(24,119,242,0.78);
    box-shadow:
        0 16px 30px rgba(0,0,0,0.12),
        0 0 0 3px rgba(24,119,242,0.13);
}

.cancha-opcion.seleccionada{
    border-color:rgba(255,75,92,0.98);
    box-shadow:
        0 16px 32px rgba(255,75,92,0.16),
        0 0 0 3px rgba(24,119,242,0.20);
    background:linear-gradient(180deg,#fff7f8,#fff);
}

.cancha-opcion-foto{
    width:100%;
    height:86px;
    border-radius:18px;
    overflow:hidden;
    background:linear-gradient(135deg,#eaf4ff,#fff2f4);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    flex:0 0 auto;
}

.cancha-opcion-foto img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.cancha-opcion-info{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:7px;
}

.cancha-opcion-info strong{
    font-size:16px;
    font-weight:900;
    color:#1877f2;
    line-height:1.2;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.cancha-opcion-info small{
    font-size:11.5px;
    font-weight:900;
    color:#374151;
    line-height:1.35;
}

.cancha-opcion-info em{
    font-style:normal;
    font-size:12px;
    font-weight:700;
    color:#6b7280;
    line-height:1.4;
    display:-webkit-box;
    -webkit-line-clamp:3;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.cancha-chip-row{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.cancha-mini-chip{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:6px 8px;
    border-radius:999px;
    background:#f3f8ff;
    border:1px solid rgba(24,119,242,0.16);
    color:#1f2937;
    font-size:11px;
    font-weight:900;
}

.cancha-opcion-manual{
    justify-content:center;
    align-items:center;
    text-align:center;
    background:linear-gradient(180deg,#fff7f8,#ffffff);
    border:2px dashed rgba(255,75,92,0.75);
}

.cancha-opcion-manual .manual-icon{
    width:66px;
    height:66px;
    border-radius:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    color:#fff;
    font-size:30px;
    box-shadow:0 12px 24px rgba(255,75,92,0.22);
}

.cancha-opcion-manual strong{
    font-size:17px;
    color:#ff3045;
}

.cancha-opcion-manual em{
    font-size:12px;
    color:#4b5563;
}

.cancha-opcion.oculto-busqueda{
    display:none;
}

.manual-cancha{
    display:flex;
    flex-direction:column;
    gap:12px;
    margin-top:12px;
    padding:16px;
    border-radius:22px;
    background:rgba(243,248,255,0.82);
    border:2px dashed rgba(0,153,255,0.38);
}

.manual-cancha-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.manual-cancha-title strong{
    color:#1877f2;
    font-size:14px;
}

.oculto{
    display:none!important;
}

.publicando-overlay{
    position:fixed;
    inset:0;
    z-index:9000;
    background:rgba(7,11,20,0.82);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:22px;
    backdrop-filter:blur(10px);
}

.publicando-card{
    width:min(430px,100%);
    border-radius:30px;
    background:#fff;
    padding:34px;
    text-align:center;
    box-shadow:
        0 24px 60px rgba(0,0,0,0.30),
        0 0 0 3px rgba(0,153,255,0.35);
    border:2px solid rgba(255,75,92,0.55);
}

.spinner{
    width:76px;
    height:76px;
    margin:0 auto 18px;
    border-radius:50%;
    border:8px solid #e5e7eb;
    border-top-color:#1877f2;
    border-right-color:#ff4b5c;
    animation:girar 1s linear infinite;
}

.publicando-card h2{
    font-family:'Orbitron',sans-serif;
    color:#1877f2;
    margin-bottom:10px;
}

.publicando-card p{
    color:#4b5563;
    font-weight:800;
}

.barra-carga{
    width:100%;
    height:12px;
    border-radius:999px;
    background:#e5e7eb;
    margin-top:20px;
    overflow:hidden;
}

.barra-carga span{
    display:block;
    height:100%;
    width:0;
    background:linear-gradient(135deg,#1877f2,#ff4b5c);
    animation:cargar 5s linear forwards;
}

@keyframes girar{
    to{transform:rotate(360deg);}
}

@keyframes cargar{
    to{width:100%;}
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

.mobile-overlay{
    display:none;
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
body.dark-mode .bottom-nav,
body.dark-mode .perfil-card,
body.dark-mode .reta-panel,
body.dark-mode .action-card,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel,
body.dark-mode .topbar-user,
body.dark-mode .form-control,
body.dark-mode .hero-user-inner{
    background:#111827;
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
}

body.dark-mode .logo-text h2,
body.dark-mode .topbar-title h1,
body.dark-mode .perfil-card h2,
body.dark-mode .panel-title h3,
body.dark-mode .action-card strong,
body.dark-mode .hero-chip,
body.dark-mode .reta-chip,
body.dark-mode .manual-cancha-title strong,
body.dark-mode .cancha-opcion-info strong{
    color:#4db8ff;
}

body.dark-mode .logo-text p,
body.dark-mode .perfil-card p,
body.dark-mode .panel-title span,
body.dark-mode .form-group label,
body.dark-mode .modo-oscuro-texto,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .hero-user-data strong,
body.dark-mode .hero-user-data span,
body.dark-mode .action-card span:last-child,
body.dark-mode .cancha-ayuda,
body.dark-mode .cancha-opcion-info small,
body.dark-mode .cancha-opcion-info em,
body.dark-mode .cancha-mini-chip,
body.dark-mode .cancha-opcion-manual em{
    color:#e5e7eb;
}

body.dark-mode .perfil-card,
body.dark-mode .reta-panel,
body.dark-mode .action-card,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel,
body.dark-mode .hero-user-inner{
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

body.dark-mode .cancha-lista-scroll,
body.dark-mode .manual-cancha{
    background:#0b1220;
    border-color:rgba(0,153,255,0.34);
}

body.dark-mode .cancha-opcion{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(0,153,255,0.28);
}

body.dark-mode .cancha-opcion.seleccionada{
    background:linear-gradient(180deg,#1b1220,#111827);
    border-color:rgba(255,75,92,0.92);
}

body.dark-mode .cancha-mini-chip{
    background:#0b1220;
    border-color:rgba(0,153,255,0.30);
}

body.dark-mode .cancha-opcion-manual{
    background:linear-gradient(180deg,#1b1220,#111827);
    border-color:rgba(255,75,92,0.72);
}

body.dark-mode .form-control[readonly]{
    background:#0b1220;
    color:#d1d5db;
}

body.dark-mode .bottom-nav a img,
body.dark-mode .accion-boceto img,
body.dark-mode .cerrar-boceto img{
    filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05);
}

body.dark-mode .mobile-overlay{
    background:rgba(0,0,0,0.48);
}

@media screen and (max-width:1050px){
    .publicar-grid{
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
        z-index:1200;
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

    .main-content,
    body.sidebar-hidden .main-content{
        margin-left:0;
        padding-top:94px;
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

    .deporte-form,
    .cancha-tools{
        grid-template-columns:1fr;
    }

    .btn-mini{
        width:100%;
    }

    .form-grid{
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
        min-height:auto;
        border-radius:24px;
    }

    .hero-user-inner{
        width:100%;
        border-radius:22px;
        justify-content:flex-start;
    }

    .perfil-card h2{
        font-size:1.7rem;
    }

    .reta-panel{
        border-radius:24px;
    }

    .panel-title{
        flex-direction:column;
        gap:6px;
    }

    .panel-title span{
        text-align:left;
    }

    .cancha-opcion{
        flex-basis:218px;
        min-height:230px;
    }

    .cancha-opcion-foto{
        height:76px;
        border-radius:16px;
    }

    .btn-row{
        flex-direction:column;
    }

    .btn-main{
        width:100%;
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
}

@media screen and (max-width:360px){
    .acciones-grid{
        grid-template-columns:1fr;
    }

    .accion-boceto{
        min-height:74px;
    }
}
</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

<?php if ($publicada): ?>
<div class="publicando-overlay">
    <div class="publicando-card">
        <div class="spinner"></div>
        <h2>Publicando reta</h2>
        <p>Estamos preparando la sala de espera...</p>
        <div class="barra-carga"><span></span></div>
    </div>
</div>
<script>
setTimeout(function(){
    window.location.href = 'SalaEspera.php?id_reta=<?php echo (int)$idRetaPublicada; ?>';
}, 5000);
</script>
<?php endif; ?>

<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area">
        <img src="../Imagenes/ImgReta.png" alt="Logo RETAME" class="doctor-logo">
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

            <a href="../Canchas/Canchas.php" class="accion-boceto">
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
            <a href="../AcercaDe.php">Acerca de</a>
            <a href="../SoporteTecnico.php">Soporte técnico</a>
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
        <h1>Publicar reta</h1>
    </div>

    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo limpiarTexto($Nombre); ?></span>
    </div>
</header>

<main class="main-content">
    <section class="publicar-wrapper">

        <div class="publicar-grid">
            <div class="perfil-card">
                <div class="hero-user">
                    <div class="hero-user-inner">
                        <img src="<?php echo limpiarTexto($FotoPerfilUsuario); ?>" alt="Foto de perfil" class="hero-avatar">
                        <div class="hero-user-data">
                            <strong><?php echo limpiarTexto($Nombre); ?></strong>
                            <span><?php echo limpiarTexto($RangoUsuario); ?> · CP <?php echo limpiarTexto($CodigoPostalUsuario !== '' ? $CodigoPostalUsuario : 'No encontrado'); ?></span>
                        </div>
                    </div>
                </div>

                <h2>⚔️ PUBLICAR RETA</h2>

                <p>
                    Selecciona el deporte, elige una cancha registrada cercana a tu código postal o escribe una cancha manual.
                    Al publicar, el sistema prepara la sala de espera para organizar a los jugadores.
                </p>

                <div class="hero-chips">
                    <span class="hero-chip">📍 CP <?php echo limpiarTexto($CodigoPostalUsuario !== '' ? $CodigoPostalUsuario : 'No encontrado'); ?></span>
                    <span class="hero-chip rojo">🔥 Módulo Retar</span>
                    <?php if ($deporteSeleccionado): ?>
                        <span class="hero-chip">🏅 <?php echo limpiarTexto($deporteSeleccionado['Nombre']); ?></span>
                        <span class="hero-chip rojo"><?php echo limpiarTexto($tipoVista); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="side-actions">
                <a href="../Retar/retar.php" class="action-card">
                    <span class="big-icon">↩️</span>
                    <strong>Retar</strong>
                    <span>Volver al menú de retas</span>
                </a>

                <a href="../Canchas/Canchas.php" class="action-card azul">
                    <span class="big-icon">🏟️</span>
                    <strong>Canchas</strong>
                    <span>Ver canchas registradas</span>
                </a>
            </div>
        </div>

        <div class="reta-panel">
            <div class="panel-title">
                <h3>1. Selecciona el deporte</h3>
                <span><?php echo count($deportes); ?> deporte(s)</span>
            </div>

            <form method="GET" action="PublicarReta.php" class="deporte-form">
                <div class="form-group">
                    <label for="id_deporte">Deporte</label>
                    <select class="form-control" name="id_deporte" id="id_deporte" required>
                        <option value="">Selecciona un deporte</option>
                        <?php foreach ($deportes as $dep): ?>
                            <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ((string)$idDeporteSeleccionado === (string)$dep['Id_Deporte']) ? 'selected' : ''; ?>>
                                <?php echo limpiarTexto($dep['Nombre']); ?> - <?php echo limpiarTexto($dep['Tipo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-main rojo">Continuar</button>
            </form>
        </div>

        <?php if (!empty($errores)): ?>
            <div class="alerta error">
                <?php foreach ($errores as $e): ?>
                    <div>⚠️ <?php echo limpiarTexto($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($deporteSeleccionado): ?>
            <div class="reta-panel">
                <div class="panel-title">
                    <h3>2. Datos de la reta</h3>
                    <span><?php echo limpiarTexto($tipoVista); ?></span>
                </div>

                <?php if ($esEquipoVista): ?>
                    <div class="alerta info">
                        Al publicar, se crearán automáticamente <strong>Equipo 1</strong> y <strong>Equipo 2</strong>.
                        Tú quedarás como capitán del Equipo 1.
                    </div>
                <?php else: ?>
                    <div class="alerta info">
                        Este deporte está marcado como individual. La reta se publicará sin crear equipos temporales.
                    </div>
                <?php endif; ?>

                <form method="POST" action="PublicarReta.php?id_deporte=<?php echo urlencode($idDeporteSeleccionado); ?>">
                    <input type="hidden" name="accion" value="publicar_reta">
                    <input type="hidden" name="id_deporte" value="<?php echo limpiarTexto($idDeporteSeleccionado); ?>">
                    <input type="hidden" name="cancha_modo" id="cancha_modo" value="<?php echo !empty($canchas) ? 'registrada' : 'manual'; ?>">
                    <input type="hidden" name="id_cancha" id="id_cancha" value="">

                    <div class="form-grid" style="margin-top:18px">
                        <div class="form-group full">
                            <label>Cancha</label>

                            <div class="cancha-selector" id="selectorRegistrado">
                                <div class="cancha-tools">
                                    <input class="form-control" type="text" id="buscar_cancha" placeholder="Buscar por nombre, código postal o dirección">
                                    <button type="button" class="btn-main azul btn-mini" id="btnCanchaManual">Escribir cancha manual</button>
                                </div>

                                <?php if ($mensajeCanchas !== ''): ?>
                                    <div class="alerta info"><?php echo limpiarTexto($mensajeCanchas); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($canchas)): ?>
                                    <div class="cancha-lista-scroll" id="listaCanchasRegistradas">
                                        <?php foreach ($canchas as $canchaItem): ?>
                                            <?php
                                                $distanciaCP = isset($canchaItem['Distancia_CP']) ? (int)$canchaItem['Distancia_CP'] : 999999;
                                                $textoCercania = ($distanciaCP === 0) ? 'Mismo CP' : (($distanciaCP >= 999999) ? 'Registrada' : 'Dif. ' . $distanciaCP);
                                                $direccionCompleta = trim((string)$canchaItem['Direccion']);
                                                $ubicacionPartes = [];

                                                if (!empty($canchaItem['Ciudad'])) $ubicacionPartes[] = $canchaItem['Ciudad'];
                                                if (!empty($canchaItem['Estado'])) $ubicacionPartes[] = $canchaItem['Estado'];
                                                if (!empty($canchaItem['Pais'])) $ubicacionPartes[] = $canchaItem['Pais'];

                                                if (!empty($ubicacionPartes)) {
                                                    $direccionCompleta .= ' · ' . implode(', ', $ubicacionPartes);
                                                }

                                                $busquedaCancha = mb_strtolower(
                                                    $canchaItem['Nombre'] . ' ' .
                                                    $canchaItem['Codigo_Postal'] . ' ' .
                                                    $direccionCompleta . ' ' .
                                                    $canchaItem['Tipo_Cancha'] . ' ' .
                                                    $canchaItem['Deporte1'] . ' ' .
                                                    $canchaItem['Deporte2'] . ' ' .
                                                    $canchaItem['Deporte3'],
                                                    'UTF-8'
                                                );

                                                $fotoCancha = isset($canchaItem['Foto']) ? trim((string)$canchaItem['Foto']) : '';
                                                $calificacion = ($canchaItem['Calificacion'] !== null && $canchaItem['Calificacion'] !== '') ? number_format((float)$canchaItem['Calificacion'], 1) : 'Sin calificación';

                                                $deportesCancha = [];
                                                if (!empty($canchaItem['Deporte1'])) $deportesCancha[] = $canchaItem['Deporte1'];
                                                if (!empty($canchaItem['Deporte2'])) $deportesCancha[] = $canchaItem['Deporte2'];
                                                if (!empty($canchaItem['Deporte3'])) $deportesCancha[] = $canchaItem['Deporte3'];
                                            ?>
                                                <button type="button"
                                                    class="cancha-opcion"
                                                    data-id="<?php echo limpiarTexto($canchaItem['Id_Cancha']); ?>"
                                                    data-nombre="<?php echo limpiarTexto($canchaItem['Nombre']); ?>"
                                                    data-direccion="<?php echo limpiarTexto($direccionCompleta); ?>"
                                                    data-cp="<?php echo limpiarTexto($canchaItem['Codigo_Postal']); ?>"
                                                    data-busqueda="<?php echo limpiarTexto($busquedaCancha); ?>">
                                                    <span class="cancha-opcion-foto">
                                                        <?php if ($fotoCancha !== ''): ?>
                                                            <img src="<?php echo limpiarTexto($fotoCancha); ?>" alt="">
                                                        <?php else: ?>
                                                            🏟️
                                                        <?php endif; ?>
                                                    </span>

                                                    <span class="cancha-opcion-info">
                                                        <strong><?php echo limpiarTexto($canchaItem['Nombre']); ?></strong>

                                                        <span class="cancha-chip-row">
                                                            <span class="cancha-mini-chip">📌 CP <?php echo limpiarTexto($canchaItem['Codigo_Postal']); ?></span>
                                                            <span class="cancha-mini-chip">📏 <?php echo limpiarTexto($textoCercania); ?></span>
                                                        </span>

                                                        <small>⭐ <?php echo limpiarTexto($calificacion); ?> · <?php echo limpiarTexto($canchaItem['Tipo_Cancha']); ?></small>
                                                        <small>🏅 <?php echo limpiarTexto(implode(', ', $deportesCancha)); ?></small>
                                                        <em><?php echo limpiarTexto($direccionCompleta); ?></em>
                                                    </span>
                                                </button>
                                        <?php endforeach; ?>

                                        <button type="button" class="cancha-opcion cancha-opcion-manual" id="btnCanchaManualCard" data-busqueda="manual cancha no registrada escribir direccion">
                                            <span class="manual-icon">✍️</span>
                                            <strong>Escribir cancha manual</strong>
                                            <em>Úsalo si la cancha que quieres no aparece o no está registrada.</em>
                                        </button>
                                    </div>

                                    <div class="cancha-ayuda">
                                        Se muestran las canchas donde el deporte aparece en Deporte1, Deporte2 o Deporte3. Primero salen las de tu mismo código postal y después las más cercanas.
                                    </div>
                                <?php else: ?>
                                    <div class="alerta info">
                                        No encontré canchas registradas para <?php echo limpiarTexto($deporteSeleccionado['Nombre']); ?>. Puedes escribir la cancha manualmente.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="manual-cancha <?php echo !empty($canchas) ? 'oculto' : ''; ?>" id="manualCanchaBox">
                                <div class="manual-cancha-title">
                                    <strong>Cancha manual</strong>

                                    <?php if (!empty($canchas)): ?>
                                        <button type="button" class="btn-main azul btn-mini" id="btnVolverRegistradas">Ver canchas registradas</button>
                                    <?php endif; ?>
                                </div>

                                <input class="form-control" type="text" name="cancha_manual" id="cancha_manual" placeholder="Nombre de la cancha" <?php echo empty($canchas) ? 'required' : ''; ?>>
                                <div class="cancha-ayuda">Aquí puedes escribir el nombre de la cancha. También podrás escribir manualmente el código postal y la dirección.</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="codigo_postal">Código postal</label>
                            <input class="form-control" type="text" name="codigo_postal" id="codigo_postal" value="<?php echo limpiarTexto($CodigoPostalUsuario); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="fecha">Fecha</label>
                            <input class="form-control" type="date" name="fecha" id="fecha" required>
                        </div>

                        <div class="form-group">
                            <label for="hora">Hora</label>
                            <input class="form-control" type="time" name="hora" id="hora" required>
                        </div>

                        <div class="form-group full">
                            <label for="direccion">Dirección</label>
                            <input class="form-control" type="text" name="direccion" id="direccion" placeholder="Dirección de la cancha" required>
                        </div>

                        <div class="form-group full">
                            <label for="descripcion">Descripción</label>
                            <textarea class="form-control" name="descripcion" id="descripcion" placeholder="Escribe reglas, detalles o referencias de la reta"></textarea>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn-main rojo">🔥 Publicar reta</button>
                        <a href="../Retar/retar.php" class="btn-main azul">Volver</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </section>
</main>

<nav class="bottom-nav">
    <a href="../Perfil2.php" aria-label="Inicio">
        <img src="../Imagenes/ImgInicio.png" alt="">
    </a>

    <a href="../Retar/retar.php" class="active" aria-label="Retar">
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
    </a>

    <a href="../configurarperfil.php" aria-label="Perfil">
        <img src="../Imagenes/ImgPerfil.png" alt="">
    </a>
</nav>

<script>
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
document.addEventListener('DOMContentLoaded', controlarBarrasPorScroll);

const idCanchaInput = document.getElementById('id_cancha');
const buscarCancha = document.getElementById('buscar_cancha');
const direccionInput = document.getElementById('direccion');
const codigoPostalInput = document.getElementById('codigo_postal');
const canchaModo = document.getElementById('cancha_modo');
const manualCanchaBox = document.getElementById('manualCanchaBox');
const btnCanchaManual = document.getElementById('btnCanchaManual');
const btnCanchaManualCard = document.getElementById('btnCanchaManualCard');
const btnVolverRegistradas = document.getElementById('btnVolverRegistradas');
const canchaManual = document.getElementById('cancha_manual');
const opcionesCancha = Array.from(document.querySelectorAll('.cancha-opcion:not(.cancha-opcion-manual)'));
const formReta = document.querySelector('form[method="POST"]');

function normalizarTexto(valor){
    return String(valor || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
}

function bloquearCamposCancha(estado){
    if(direccionInput) direccionInput.readOnly = estado;
    if(codigoPostalInput) codigoPostalInput.readOnly = estado;
}

function seleccionarCanchaRegistrada(boton){
    if(!boton) return;

    opcionesCancha.forEach(item => item.classList.remove('seleccionada'));
    boton.classList.add('seleccionada');

    if(idCanchaInput) idCanchaInput.value = boton.dataset.id || '';
    if(canchaModo) canchaModo.value = 'registrada';
    if(direccionInput) direccionInput.value = boton.dataset.direccion || '';
    if(codigoPostalInput && boton.dataset.cp) codigoPostalInput.value = boton.dataset.cp;

    if(canchaManual){
        canchaManual.required = false;
        canchaManual.value = '';
    }

    if(manualCanchaBox) manualCanchaBox.classList.add('oculto');

    bloquearCamposCancha(true);
}

function activarCanchaManual(){
    if(canchaModo) canchaModo.value = 'manual';
    if(idCanchaInput) idCanchaInput.value = '';

    if(manualCanchaBox) manualCanchaBox.classList.remove('oculto');

    opcionesCancha.forEach(item => item.classList.remove('seleccionada'));

    if(canchaManual) canchaManual.required = true;

    if(direccionInput){
        direccionInput.value = '';
        direccionInput.readOnly = false;
    }

    if(codigoPostalInput){
        codigoPostalInput.value = '<?php echo limpiarTexto($CodigoPostalUsuario); ?>';
        codigoPostalInput.readOnly = false;
    }

    setTimeout(() => {
        if(canchaManual) canchaManual.focus();
    }, 80);
}

function activarCanchaRegistrada(){
    if(canchaModo) canchaModo.value = 'registrada';
    if(manualCanchaBox) manualCanchaBox.classList.add('oculto');

    if(canchaManual){
        canchaManual.required = false;
        canchaManual.value = '';
    }

    if(idCanchaInput) idCanchaInput.value = '';

    if(direccionInput){
        direccionInput.value = '';
        direccionInput.readOnly = false;
    }

    if(codigoPostalInput){
        codigoPostalInput.value = '<?php echo limpiarTexto($CodigoPostalUsuario); ?>';
        codigoPostalInput.readOnly = false;
    }

    opcionesCancha.forEach(item => item.classList.remove('seleccionada'));
}

opcionesCancha.forEach(boton => {
    boton.addEventListener('click', function(){
        seleccionarCanchaRegistrada(this);
    });
});

if(buscarCancha){
    buscarCancha.addEventListener('input', function(){
        const texto = normalizarTexto(this.value.trim());

        opcionesCancha.forEach(boton => {
            const base = normalizarTexto(boton.dataset.busqueda || '');
            boton.classList.toggle('oculto-busqueda', texto !== '' && !base.includes(texto));
        });
    });
}

if(btnCanchaManual) btnCanchaManual.addEventListener('click', activarCanchaManual);
if(btnCanchaManualCard) btnCanchaManualCard.addEventListener('click', activarCanchaManual);
if(btnVolverRegistradas) btnVolverRegistradas.addEventListener('click', activarCanchaRegistrada);

if(formReta){
    formReta.addEventListener('submit', function(e){
        const modo = canchaModo ? canchaModo.value : 'manual';

        if(modo === 'registrada' && idCanchaInput && idCanchaInput.value === ''){
            e.preventDefault();
            alert('Selecciona una cancha registrada de la lista o usa la opción de escribir cancha manual.');
            return;
        }

        if(modo === 'manual' && canchaManual && canchaManual.value.trim() === ''){
            e.preventDefault();
            alert('Escribe el nombre de la cancha manual.');
        }
    });
}

if(canchaModo && canchaModo.value === 'manual'){
    bloquearCamposCancha(false);
}else{
    bloquearCamposCancha(false);
}

const darkModeToggle = document.getElementById('darkModeToggle');

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
        method:'POST',
        body:datos,
        headers:{
            'X-Requested-With':'XMLHttpRequest'
        }
    }).then(respuesta => respuesta.json());
}

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
</script>

</body>
</html>
