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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle_favorito_cancha') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($Id_Retador)) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró el usuario en sesión.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $idCanchaFavorito = isset($_POST['id_cancha']) ? trim((string)$_POST['id_cancha']) : '';
    $nombreCanchaFavorito = isset($_POST['nombre_cancha']) ? trim((string)$_POST['nombre_cancha']) : '';

    if ($idCanchaFavorito === '') {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró la cancha.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($nombreCanchaFavorito === '') {
        $nombreCanchaFavorito = $idCanchaFavorito;
    }

    $categoriaFavorito = 'CANCHA';
    $nombreRetadorFavorito = trim((string)html_entity_decode($Nombre, ENT_QUOTES, 'UTF-8'));
    if ($nombreRetadorFavorito === '') {
        $nombreRetadorFavorito = 'Usuario';
    }
    $nombreFavorito = 'Cancha ' . $nombreCanchaFavorito;

    $sqlBuscarFavorito = "SELECT ID_FAVORITO FROM favoritosguardados WHERE ID_CATEGORIA = ? AND ID_RETADOR = ? AND CATEGORIA = ? LIMIT 1";
    $stmtBuscarFavorito = $conn->prepare($sqlBuscarFavorito);

    if (!$stmtBuscarFavorito) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo revisar favoritos.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtBuscarFavorito->bind_param('sss', $idCanchaFavorito, $Id_Retador, $categoriaFavorito);
    $stmtBuscarFavorito->execute();
    $resBuscarFavorito = $stmtBuscarFavorito->get_result();
    $filaFavorito = $resBuscarFavorito ? $resBuscarFavorito->fetch_assoc() : null;
    $stmtBuscarFavorito->close();

    if ($filaFavorito && isset($filaFavorito['ID_FAVORITO'])) {
        $idFavoritoEliminar = (int)$filaFavorito['ID_FAVORITO'];
        $sqlEliminarFavorito = "DELETE FROM favoritosguardados WHERE ID_FAVORITO = ? AND ID_RETADOR = ? LIMIT 1";
        $stmtEliminarFavorito = $conn->prepare($sqlEliminarFavorito);

        if (!$stmtEliminarFavorito) {
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No se pudo quitar de favoritos.'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $stmtEliminarFavorito->bind_param('is', $idFavoritoEliminar, $Id_Retador);
        $okEliminarFavorito = $stmtEliminarFavorito->execute();
        $stmtEliminarFavorito->close();

        echo json_encode([
            'ok' => $okEliminarFavorito,
            'favorito' => false,
            'mensaje' => $okEliminarFavorito ? 'Cancha quitada de favoritos.' : 'No se pudo quitar de favoritos.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sqlInsertarFavorito = "INSERT INTO favoritosguardados (ID_CATEGORIA, ID_RETADOR, NOMBRE_RETADOR, CATEGORIA, NOMBRE_FAVORITO, FECHA, HORA) VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME())";
    $stmtInsertarFavorito = $conn->prepare($sqlInsertarFavorito);

    if (!$stmtInsertarFavorito) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo guardar en favoritos.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtInsertarFavorito->bind_param('sssss', $idCanchaFavorito, $Id_Retador, $nombreRetadorFavorito, $categoriaFavorito, $nombreFavorito);
    $okInsertarFavorito = $stmtInsertarFavorito->execute();
    $stmtInsertarFavorito->close();

    echo json_encode([
        'ok' => $okInsertarFavorito,
        'favorito' => true,
        'mensaje' => $okInsertarFavorito ? 'Cancha guardada en favoritos.' : 'No se pudo guardar en favoritos.'
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
$res = $conn->query("SELECT Id_Deporte, Nombre FROM deporte WHERE Tipo='Equipo'");
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
$favoritosCanchasUsuario = [];
$mensaje_canchas = '';
$radio_codigo_postal = 500;

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

$cpNumericoUsuario = preg_replace('/\D+/', '', $Codigo_Postal_Usuario);
$columnasCanchas = [];
$resColumnasCanchas = $conn->query("SHOW COLUMNS FROM c_canchas");

if ($resColumnasCanchas) {
    while ($colCancha = $resColumnasCanchas->fetch_assoc()) {
        if (isset($colCancha['Field'])) {
            $columnasCanchas[] = $colCancha['Field'];
        }
    }
}

function existeColumnaCancha($columnas, $nombre) {
    foreach ($columnas as $columna) {
        if (mb_strtolower($columna, 'UTF-8') === mb_strtolower($nombre, 'UTF-8')) {
            return true;
        }
    }
    return false;
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

if ($cpNumericoUsuario !== '') {
    $cpInt = $cpNumericoUsuario;

    $sqlCanchas = "SELECT 
                        Id_Cancha,
                        Nombre,
                        Dueno,
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
                        CASE 
                            WHEN Codigo_Postal REGEXP '^[0-9]+$' THEN ABS(CAST(Codigo_Postal AS SIGNED) - CAST(? AS SIGNED))
                            ELSE 999999
                        END AS Distancia_CP,
                        CASE 
                            WHEN Codigo_Postal REGEXP '^[0-9]+$' THEN 1
                            ELSE 0
                        END AS CP_Numerico
                   FROM c_canchas
                   ORDER BY 
                        CASE WHEN Codigo_Postal = ? THEN 0 ELSE 1 END,
                        CP_Numerico DESC,
                        Distancia_CP ASC,
                        Calificacion DESC,
                        Nombre ASC";

    $stmtCanchas = $conn->prepare($sqlCanchas);

    if ($stmtCanchas) {
        $stmtCanchas->bind_param("ss", $cpInt, $cpNumericoUsuario);
        $stmtCanchas->execute();
        $resCanchas = $stmtCanchas->get_result();

        while ($filaCancha = $resCanchas->fetch_assoc()) {
            $canchas_cercanas[] = $filaCancha;
        }

        $stmtCanchas->close();
    } else {
        $mensaje_canchas = 'No se pudo consultar la tabla c_canchas. Revisa que la tabla ya exista en la base de datos.';
    }
} else {
    $sqlCanchas = "SELECT 
                        Id_Cancha,
                        Nombre,
                        Dueno,
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
                        999999 AS Distancia_CP,
                        0 AS CP_Numerico
                   FROM c_canchas
                   ORDER BY Nombre ASC";

    $stmtCanchas = $conn->prepare($sqlCanchas);

    if ($stmtCanchas) {
        $stmtCanchas->execute();
        $resCanchas = $stmtCanchas->get_result();

        while ($filaCancha = $resCanchas->fetch_assoc()) {
            $canchas_cercanas[] = $filaCancha;
        }

        $stmtCanchas->close();
    } else {
        $mensaje_canchas = 'No se pudo consultar la tabla c_canchas. Revisa que la tabla ya exista en la base de datos.';
    }
}

if (!empty($Id_Retador)) {
    $categoriaFavoritosCanchas = 'CANCHA';
    $sqlFavoritosCanchas = "SELECT ID_CATEGORIA FROM favoritosguardados WHERE ID_RETADOR = ? AND CATEGORIA = ?";
    $stmtFavoritosCanchas = $conn->prepare($sqlFavoritosCanchas);
    if ($stmtFavoritosCanchas) {
        $stmtFavoritosCanchas->bind_param('ss', $Id_Retador, $categoriaFavoritosCanchas);
        $stmtFavoritosCanchas->execute();
        $resFavoritosCanchas = $stmtFavoritosCanchas->get_result();
        while ($filaFavoritoCancha = $resFavoritosCanchas->fetch_assoc()) {
            $favoritosCanchasUsuario[(string)$filaFavoritoCancha['ID_CATEGORIA']] = true;
        }
        $stmtFavoritosCanchas->close();
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Canchas - RETAME</title>
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

.cancha-costo-texto{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.btn-favorito-cancha{
    width:48px;
    height:48px;
    min-width:48px;
    border-radius:999px;
    border:2px solid #ff3045;
    background:#ffffff;
    color:#ff3045;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(255,48,69,0.16);
    transition:0.22s ease;
    padding:0;
}

.btn-favorito-cancha svg{
    width:28px;
    height:28px;
    fill:transparent;
    stroke:#ff3045;
    stroke-width:1.8;
    transition:0.22s ease;
}

.btn-favorito-cancha:hover{
    transform:translateY(-2px) scale(1.04);
    box-shadow:0 14px 26px rgba(255,48,69,0.24);
}

.btn-favorito-cancha.activo{
    background:#ff3045;
    color:#ffffff;
}

.btn-favorito-cancha.activo svg{
    fill:#ffffff;
    stroke:#ffffff;
}

.btn-favorito-cancha:disabled{
    opacity:0.65;
    cursor:not-allowed;
    transform:none;
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

body.dark-mode .canchas-head,
body.dark-mode .canchas-panel,
body.dark-mode .cancha-card,
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

body.dark-mode .btn-favorito-cancha{
    background:#111827;
    border-color:#ff4b5c;
}

body.dark-mode .btn-favorito-cancha.activo{
    background:#ff3045;
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
        <h1>Canchas registradas</h1>
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
                <span class="canchas-chip">📍 Código postal: <?php echo limpiarTexto($Codigo_Postal_Usuario !== '' ? $Codigo_Postal_Usuario : 'No encontrado'); ?></span>
                <h2>Canchas registradas</h2>
                <p>
                    Aquí aparecerán todas las canchas registradas en la plataforma. Primero se muestran las que coinciden con tu código postal y después las más cercanas.
                </p>
            </div>

            <div class="canchas-botones">
                <a href="MisCanchas.php" class="btn-cancha azul">🏟️ Mis canchas</a>
                <a href="registrarcanchas.php" class="btn-cancha rojo">➕ Registrar cancha</a>
            </div>
        </div>

        <div class="canchas-panel">
            <div class="canchas-panel-title">
                <h3>Todas las canchas por cercanía</h3>
                <span><?php echo count($canchas_cercanas); ?> cancha(s) encontrada(s)</span>
            </div>

            <?php if ($mensaje_canchas !== ''): ?>
                <div class="canchas-vacio">
                    <div>
                        <strong>⚠️ <?php echo limpiarTexto($mensaje_canchas); ?></strong><br>
                        Agrega o revisa el código postal del usuario en sesión para mostrar canchas cercanas.
                    </div>
                </div>
            <?php elseif (empty($canchas_cercanas)): ?>
                <div class="canchas-vacio">
                    <div>
                        <strong>🏟️ No hay canchas cercanas por ahora.</strong><br>
                        Todavía no hay canchas registradas en la plataforma.
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

                            $distanciaCP = isset($cancha['Distancia_CP']) ? (int)$cancha['Distancia_CP'] : 999999;
                            $distanciaTexto = ($distanciaCP >= 999999) ? 'Sin comparación' : 'Dif. ' . $distanciaCP;
                            $cercaniaTexto = ($distanciaCP === 0) ? 'Mismo código postal' : (($distanciaCP >= 999999) ? 'Cancha registrada' : 'Cercana a tu zona');
                            $diasDisponibles = isset($cancha['Dias_Disponibles']) ? trim((string)$cancha['Dias_Disponibles']) : '';
                            $horarioApertura = isset($cancha['Horario_Apertura']) ? trim((string)$cancha['Horario_Apertura']) : '';
                            $horarioClausura = isset($cancha['Horario_Clausura']) ? trim((string)$cancha['Horario_Clausura']) : '';
                            $deportesCancha = [];
                            if (!empty($cancha['Deporte1'])) $deportesCancha[] = $cancha['Deporte1'];
                            if (!empty($cancha['Deporte2'])) $deportesCancha[] = $cancha['Deporte2'];
                            if (!empty($cancha['Deporte3'])) $deportesCancha[] = $cancha['Deporte3'];
                            $idCanchaActual = isset($cancha['Id_Cancha']) ? trim((string)$cancha['Id_Cancha']) : '';
                            $nombreCanchaActual = isset($cancha['Nombre']) ? trim((string)$cancha['Nombre']) : '';
                            $canchaFavorita = ($idCanchaActual !== '' && isset($favoritosCanchasUsuario[$idCanchaActual]));
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
                                    <span class="cancha-tag">📏 <?php echo limpiarTexto($distanciaTexto); ?></span>
                                    <span class="cancha-tag">⭐ <?php echo limpiarTexto($calificacion); ?></span>
                                    <span class="cancha-tag"><?php echo limpiarTexto($cancha['Estado_Cancha']); ?></span>
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
                                    <div class="cancha-costo-texto">
                                        <strong><?php echo limpiarTexto($costoTexto); ?></strong>
                                        <span><?php echo limpiarTexto($cercaniaTexto); ?></span>
                                    </div>
                                    <button type="button"
                                            class="btn-favorito-cancha<?php echo $canchaFavorita ? ' activo' : ''; ?>"
                                            data-id-cancha="<?php echo limpiarTexto($idCanchaActual); ?>"
                                            data-nombre-cancha="<?php echo limpiarTexto($nombreCanchaActual); ?>"
                                            aria-pressed="<?php echo $canchaFavorita ? 'true' : 'false'; ?>"
                                            title="<?php echo $canchaFavorita ? 'Quitar de favoritos' : 'Guardar en favoritos'; ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.08C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"></path>
                                        </svg>
                                    </button>
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


function mostrarMensajeFavorito(mensaje){
    const cont = document.getElementById('toastContainer');
    if(!cont){
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <div class="icon">♥</div>
        <div class="content">
            <div class="title">Favoritos</div>
            <div class="msg">${escapeHtml(mensaje)}</div>
            <div class="meta"><span>Ahora</span></div>
        </div>
    `;

    cont.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity 240ms ease, transform 240ms ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-6px)';
        setTimeout(() => {
            if(toast && toast.parentNode){
                toast.parentNode.removeChild(toast);
            }
        }, 260);
    }, 2600);
}

function actualizarFavoritoCancha(btn){
    const idCancha = btn.getAttribute('data-id-cancha') || '';
    const nombreCancha = btn.getAttribute('data-nombre-cancha') || '';

    if(idCancha.trim() === ''){
        mostrarMensajeFavorito('No se encontró la cancha.');
        return;
    }

    const estadoAnterior = btn.classList.contains('activo');
    btn.disabled = true;

    const datos = new FormData();
    datos.append('accion', 'toggle_favorito_cancha');
    datos.append('id_cancha', idCancha);
    datos.append('nombre_cancha', nombreCancha);

    fetch(window.location.href, {
        method: 'POST',
        body: datos,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(respuesta => respuesta.json())
    .then(data => {
        if(!data || !data.ok){
            btn.classList.toggle('activo', estadoAnterior);
            btn.setAttribute('aria-pressed', estadoAnterior ? 'true' : 'false');
            btn.setAttribute('title', estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos');
            mostrarMensajeFavorito(data && data.mensaje ? data.mensaje : 'No se pudo actualizar favoritos.');
            return;
        }

        const activo = !!data.favorito;
        btn.classList.toggle('activo', activo);
        btn.setAttribute('aria-pressed', activo ? 'true' : 'false');
        btn.setAttribute('title', activo ? 'Quitar de favoritos' : 'Guardar en favoritos');
        mostrarMensajeFavorito(data.mensaje || (activo ? 'Cancha guardada en favoritos.' : 'Cancha quitada de favoritos.'));
    })
    .catch(() => {
        btn.classList.toggle('activo', estadoAnterior);
        btn.setAttribute('aria-pressed', estadoAnterior ? 'true' : 'false');
        btn.setAttribute('title', estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos');
        mostrarMensajeFavorito('No se pudo conectar con la base de datos.');
    })
    .finally(() => {
        btn.disabled = false;
    });
}

document.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-favorito-cancha');
    if(!btn){
        return;
    }
    e.preventDefault();
    actualizarFavoritoCancha(btn);
});

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

</script>

</body>
</html>
