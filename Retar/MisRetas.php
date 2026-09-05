<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

$base = file_exists(__DIR__ . '/conexion.php') ? '' : '../';
if (file_exists(__DIR__ . '/' . $base . 'conexion.php')) {
    include_once __DIR__ . '/' . $base . 'conexion.php';
} else {
    include_once '../conexion.php';
}

$usuario = $_SESSION['usuario_data'];
$Nombre = $usuario['Nombre'] ?? 'Usuario';
$Apellido = $usuario['Apellido'] ?? '';
$Id_Retador = $usuario['Id_Retador'] ?? '';
$modoPerfilActual = '';
$modoOscuroActivo = false;
$FotoPerfilUsuario = $base . 'assets/doctor.png';
$nuevas_count = 0;
$misRetas = [];
$mensajeError = '';
$jugadoresEquipoTemp = [];
$retadoresNombre = [];
$columnasEquipoTemp = [];
$columnaEquipoTemp = '';
$mensajeEquipoTemp = '';

function mis_retas_esc($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mis_retas_valor($valor) {
    $valor = trim((string)$valor);
    return $valor !== '' ? $valor : 'Sin información';
}

function mis_retas_fecha($fecha) {
    $fecha = trim((string)$fecha);
    if ($fecha === '' || $fecha === '0000-00-00') {
        return 'Sin fecha';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y', $ts) : $fecha;
}

function mis_retas_hora($hora) {
    $hora = trim((string)$hora);
    if ($hora === '' || $hora === '00:00:00') {
        return 'Sin hora';
    }
    $ts = strtotime($hora);
    return $ts ? date('H:i', $ts) : $hora;
}

function mis_retas_estado_clase($estado) {
    $estado = strtolower(trim((string)$estado));
    if ($estado === 'programada' || $estado === 'activa' || $estado === 'publicada') {
        return 'estado-activa';
    }
    if ($estado === 'pendiente' || $estado === 'en espera' || $estado === 'solicitada') {
        return 'estado-pendiente';
    }
    if ($estado === 'finalizada' || $estado === 'cerrada' || $estado === 'cancelada') {
        return 'estado-finalizada';
    }
    return 'estado-default';
}

function mis_retas_obtener_columnas_tabla($conn, $tabla) {
    $columnas = [];
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
    if ($tabla === '') {
        return $columnas;
    }

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($fila = $res->fetch_assoc()) {
            if (isset($fila['Field'])) {
                $columnas[] = $fila['Field'];
            }
        }
        $res->free();
    }

    return $columnas;
}

function mis_retas_columna_equipo_temp($columnas) {
    $posibles = ['id_equipo','Id_Equipo','ID_Equipo','IdEquipo','idEquipo','equipo_id','EquipoID'];

    foreach ($posibles as $posible) {
        if (in_array($posible, $columnas, true)) {
            return $posible;
        }
    }

    foreach ($columnas as $columna) {
        $normalizada = strtolower(str_replace(['-', ' '], '_', (string)$columna));
        if ($normalizada === 'id_equipo' || strpos($normalizada, 'id_equipo') !== false || strpos($normalizada, 'equipo_id') !== false) {
            return $columna;
        }
    }

    return '';
}

function mis_retas_valor_columna_temp($fila, $posibles) {
    foreach ($posibles as $posible) {
        if (isset($fila[$posible]) && trim((string)$fila[$posible]) !== '') {
            return (string)$fila[$posible];
        }
    }

    $normalizadas = [];
    foreach ($fila as $campo => $valor) {
        $clave = strtolower((string)$campo);
        $clave = str_replace(['á','é','í','ó','ú','ñ','-',' '], ['a','e','i','o','u','n','_','_'], $clave);
        $normalizadas[$clave] = $valor;
    }

    foreach ($posibles as $posible) {
        $clavePosible = strtolower((string)$posible);
        $clavePosible = str_replace(['á','é','í','ó','ú','ñ','-',' '], ['a','e','i','o','u','n','_','_'], $clavePosible);

        if (isset($normalizadas[$clavePosible]) && trim((string)$normalizadas[$clavePosible]) !== '') {
            return (string)$normalizadas[$clavePosible];
        }
    }

    return '';
}

function mis_retas_id_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'id_retador','Id_Retador','ID_Retador','IdRetador','retador_id',
        'id_jugador','Id_Jugador','ID_Jugador','idJugador','IdJugador',
        'id_usuario','Id_Usuario','ID_Usuario','jugador_id'
    ]);
}

function mis_retas_nombre_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'retador_nombre_bd',
        'nombre_retador',
        'Nombre_Retador',
        'NombreRetador',
        'nombre_jugador',
        'Nombre_Jugador',
        'NombreJugador',
        'nombreJugador',
        'nombres',
        'Nombres'
    ]);
}

function mis_retas_apellido_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'retador_apellido_bd',
        'apellido_retador',
        'Apellido_Retador',
        'ApellidoRetador',
        'apellido_jugador',
        'Apellido_Jugador',
        'ApellidoJugador',
        'apellidoJugador',
        'apellidos',
        'Apellidos',
        'apellido_paterno',
        'Apellido_Paterno',
        'apellido_materno',
        'Apellido_Materno'
    ]);
}

function mis_retas_numero_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'numero_jugador','Numero_Jugador','Número','numero','Numero','num_jugador',
        'dorsal','Dorsal','no_jugador','No_Jugador','player_number'
    ]);
}

function mis_retas_posicion_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'posicion','Posicion','posición','Posición','posicion_jugador','Posicion_Jugador',
        'puesto','Puesto','position'
    ]);
}

function mis_retas_rango_jugador_temp($fila) {
    return mis_retas_valor_columna_temp($fila, [
        'retador_rango_bd','Rango_Estrellas','rango','Rango','nivel','Nivel',
        'categoria','Categoria','rango_jugador','Rango_Jugador','tipo','Tipo'
    ]);
}

function mis_retas_rol($reta, $id) {
    $roles = [];
    $id = trim((string)$id);

    if ($id === '') {
        return 'Participante';
    }

    if (trim((string)($reta['id_creador'] ?? '')) === $id) {
        $roles[] = 'Creador';
    }
    if (trim((string)($reta['capitan_equipo1'] ?? '')) === $id) {
        $roles[] = 'Capitán equipo 1';
    }
    if (trim((string)($reta['capitan_equipo2'] ?? '')) === $id) {
        $roles[] = 'Capitán equipo 2';
    }
    if (strpos((string)($reta['jugadores_equipo1'] ?? ''), $id) !== false) {
        $roles[] = 'Jugador equipo 1';
    }
    if (strpos((string)($reta['jugadores_equipo2'] ?? ''), $id) !== false) {
        $roles[] = 'Jugador equipo 2';
    }

    return empty($roles) ? 'Participante' : implode(' / ', array_unique($roles));
}

function mis_retas_id_equipo_usuario($reta, $id) {
    $id = trim((string)$id);

    if ($id === '') {
        return '';
    }

    if (trim((string)($reta['capitan_equipo1'] ?? '')) === $id || strpos((string)($reta['jugadores_equipo1'] ?? ''), $id) !== false) {
        return (string)($reta['id_equipo1'] ?? '');
    }

    if (trim((string)($reta['capitan_equipo2'] ?? '')) === $id || strpos((string)($reta['jugadores_equipo2'] ?? ''), $id) !== false) {
        return (string)($reta['id_equipo2'] ?? '');
    }

    if (trim((string)($reta['id_creador'] ?? '')) === $id) {
        return (string)($reta['id_equipo1'] ?? '');
    }

    return '';
}

function mis_retas_nombre_equipo_usuario($reta, $id) {
    $idEquipo = mis_retas_id_equipo_usuario($reta, $id);

    if ($idEquipo !== '' && trim((string)($reta['id_equipo1'] ?? '')) === trim((string)$idEquipo)) {
        return mis_retas_valor($reta['nombre_equipo1'] ?? '');
    }

    if ($idEquipo !== '' && trim((string)($reta['id_equipo2'] ?? '')) === trim((string)$idEquipo)) {
        return mis_retas_valor($reta['nombre_equipo2'] ?? '');
    }

    return 'Sin equipo';
}

function mis_retas_nombre_retador_por_id($id, $mapa) {
    $id = trim((string)$id);
    if ($id === '') {
        return 'Sin información';
    }

    return isset($mapa[$id]) && trim((string)$mapa[$id]) !== '' ? $mapa[$id] : $id;
}

function mis_retas_ruta_foto($foto, $base) {
    $foto = trim(str_replace('\\', '/', (string)$foto));
    if ($foto === '') {
        return $base . 'assets/doctor.png';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $foto)) {
        return $foto;
    }

    $foto = ltrim($foto, '/');
    $rutas = [
        $base . $foto,
        $base . 'Imagenes/' . $foto,
        $base . 'uploads/' . $foto,
        $base . 'FotosPerfil/' . $foto,
        $base . 'assets/' . $foto,
        $base . 'img/' . $foto
    ];

    foreach ($rutas as $ruta) {
        if (file_exists(__DIR__ . '/' . $ruta)) {
            return $ruta;
        }
    }

    return $base . $foto;
}

if (!empty($Id_Retador) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';

    $stmtModoUpdate = $conn->prepare("UPDATE retador SET ModoPerfil = ? WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if (!$stmtModoUpdate) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo preparar la actualización del modo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtModoUpdate->bind_param("ss", $nuevoModoPerfil, $Id_Retador);
    $okModo = $stmtModoUpdate->execute();
    $stmtModoUpdate->close();

    if ($okModo) {
        $_SESSION['usuario_data']['ModoPerfil'] = $nuevoModoPerfil;
        echo json_encode(['ok' => true, 'modo' => $nuevoModoPerfil], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo actualizar el modo.'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!empty($Id_Retador)) {
    $stmtPerfil = $conn->prepare("SELECT Nombre, Apellido, ModoPerfil, FotoPerfil FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");

    if ($stmtPerfil) {
        $stmtPerfil->bind_param("s", $Id_Retador);
        $stmtPerfil->execute();
        $resPerfil = $stmtPerfil->get_result();

        if ($filaPerfil = $resPerfil->fetch_assoc()) {
            if (isset($filaPerfil['Nombre']) && trim((string)$filaPerfil['Nombre']) !== '') {
                $Nombre = trim((string)$filaPerfil['Nombre']);
            }

            if (isset($filaPerfil['Apellido']) && trim((string)$filaPerfil['Apellido']) !== '') {
                $Apellido = trim((string)$filaPerfil['Apellido']);
            }

            $modoPerfilActual = isset($filaPerfil['ModoPerfil']) ? trim((string)$filaPerfil['ModoPerfil']) : '';
            $_SESSION['usuario_data']['ModoPerfil'] = $modoPerfilActual;

            $fotoPerfilBD = isset($filaPerfil['FotoPerfil']) ? trim((string)$filaPerfil['FotoPerfil']) : '';
            $FotoPerfilUsuario = mis_retas_ruta_foto($fotoPerfilBD, $base);
        }

        $stmtPerfil->close();
    }

    $likeRetador = '%' . $Id_Retador . '%';
    $sqlMisRetas = "SELECT
                        id_programada,
                        id_reta,
                        id_creador,
                        deporte,
                        cancha,
                        direccion,
                        codigo_postal,
                        fecha_reta,
                        hora_reta,
                        estado_reta_publicada,
                        descripcion,
                        id_equipo1,
                        nombre_equipo1,
                        capitan_equipo1,
                        total_equipo1,
                        jugadores_equipo1,
                        id_equipo2,
                        nombre_equipo2,
                        capitan_equipo2,
                        total_equipo2,
                        jugadores_equipo2,
                        cantidad_minima,
                        cantidad_maxima,
                        fecha_programada,
                        hora_programada,
                        estado_programada,
                        creado_en
                    FROM r_retasprogramadas
                    WHERE CAST(id_creador AS CHAR) = CAST(? AS CHAR)
                       OR CAST(capitan_equipo1 AS CHAR) = CAST(? AS CHAR)
                       OR CAST(capitan_equipo2 AS CHAR) = CAST(? AS CHAR)
                       OR jugadores_equipo1 LIKE ?
                       OR jugadores_equipo2 LIKE ?
                    ORDER BY fecha_programada DESC, hora_programada DESC, creado_en DESC";

    $stmtMisRetas = $conn->prepare($sqlMisRetas);

    if ($stmtMisRetas) {
        $stmtMisRetas->bind_param("sssss", $Id_Retador, $Id_Retador, $Id_Retador, $likeRetador, $likeRetador);
        $stmtMisRetas->execute();
        $resMisRetas = $stmtMisRetas->get_result();

        while ($fila = $resMisRetas->fetch_assoc()) {
            $misRetas[] = $fila;
        }

        $stmtMisRetas->close();
    } else {
        $mensajeError = 'No se pudo preparar la consulta de tus retas.';
    }
}

$idsEquiposTemp = [];

foreach ($misRetas as $retaTempConsulta) {
    $idEquipo1Temp = trim((string)($retaTempConsulta['id_equipo1'] ?? ''));
    $idEquipo2Temp = trim((string)($retaTempConsulta['id_equipo2'] ?? ''));

    if ($idEquipo1Temp !== '') {
        $idsEquiposTemp[] = $idEquipo1Temp;
    }

    if ($idEquipo2Temp !== '') {
        $idsEquiposTemp[] = $idEquipo2Temp;
    }
}

$idsEquiposTemp = array_values(array_unique($idsEquiposTemp));

if (!empty($idsEquiposTemp)) {
    $columnasEquipoTemp = mis_retas_obtener_columnas_tabla($conn, 'r_equipotemp');
    $columnaEquipoTemp = mis_retas_columna_equipo_temp($columnasEquipoTemp);

    if ($columnaEquipoTemp !== '') {
        $placeholdersTemp = implode(',', array_fill(0, count($idsEquiposTemp), '?'));
        $sqlEquipoTemp = "SELECT
                            et.*,
                            r.Nombre AS retador_nombre_bd,
                            r.Apellido AS retador_apellido_bd,
                            r.FotoPerfil AS retador_foto_bd,
                            r.Rango_Estrellas AS retador_rango_bd
                        FROM r_equipotemp et
                        LEFT JOIN retador r ON CAST(r.Id_Retador AS CHAR) = CAST(et.id_retador AS CHAR)
                        WHERE CAST(et.`$columnaEquipoTemp` AS CHAR) IN ($placeholdersTemp)
                        ORDER BY et.`$columnaEquipoTemp` ASC,
                                 CASE WHEN CAST(et.id_retador AS CHAR) = CAST(et.capitan AS CHAR) THEN 0 ELSE 1 END,
                                 et.numero_jugador ASC,
                                 et.id_retador ASC";
        $stmtEquipoTemp = $conn->prepare($sqlEquipoTemp);

        if ($stmtEquipoTemp) {
            $tiposEquipoTemp = str_repeat('s', count($idsEquiposTemp));
            $parametrosEquipoTemp = [$tiposEquipoTemp];

            foreach ($idsEquiposTemp as $indiceEquipoTemp => $valorEquipoTemp) {
                $idsEquiposTemp[$indiceEquipoTemp] = (string)$valorEquipoTemp;
                $parametrosEquipoTemp[] = &$idsEquiposTemp[$indiceEquipoTemp];
            }

            call_user_func_array([$stmtEquipoTemp, 'bind_param'], $parametrosEquipoTemp);
            $stmtEquipoTemp->execute();
            $resEquipoTemp = $stmtEquipoTemp->get_result();

            while ($filaEquipoTemp = $resEquipoTemp->fetch_assoc()) {
                $llaveEquipoTemp = trim((string)($filaEquipoTemp[$columnaEquipoTemp] ?? ''));
                $idJugadorMapa = mis_retas_id_jugador_temp($filaEquipoTemp);
                $nombreMapa = trim(mis_retas_nombre_jugador_temp($filaEquipoTemp) . ' ' . mis_retas_apellido_jugador_temp($filaEquipoTemp));

                if ($idJugadorMapa !== '' && $nombreMapa !== '') {
                    $retadoresNombre[$idJugadorMapa] = $nombreMapa;
                }

                if ($llaveEquipoTemp !== '') {
                    if (!isset($jugadoresEquipoTemp[$llaveEquipoTemp])) {
                        $jugadoresEquipoTemp[$llaveEquipoTemp] = [];
                    }

                    $jugadoresEquipoTemp[$llaveEquipoTemp][] = $filaEquipoTemp;
                }
            }

            $stmtEquipoTemp->close();
        } else {
            $mensajeEquipoTemp = 'No se pudo preparar la consulta de r_equipotemp.';
        }
    } else {
        $mensajeEquipoTemp = 'No se encontró una columna de id de equipo en r_equipotemp.';
    }
}

$modoNormalizado = function_exists('mb_strtolower')
    ? mb_strtolower(trim((string)$modoPerfilActual), 'UTF-8')
    : strtolower(trim((string)$modoPerfilActual));

$modoOscuroActivo = ($modoNormalizado === 'modo oscuro');
$totalProgramadas = 0;
$totalFinalizadas = 0;
$totalPendientes = 0;

foreach ($misRetas as $retaResumen) {
    $estadoResumen = strtolower(trim((string)($retaResumen['estado_programada'] ?? '')));

    if ($estadoResumen === 'programada' || $estadoResumen === 'activa' || $estadoResumen === 'publicada') {
        $totalProgramadas++;
    } elseif ($estadoResumen === 'finalizada' || $estadoResumen === 'cerrada' || $estadoResumen === 'cancelada') {
        $totalFinalizadas++;
    } else {
        $totalPendientes++;
    }
}

$iniciales = mb_strtoupper(mb_substr($Nombre, 0, 1, 'UTF-8') . mb_substr($Apellido, 0, 1, 'UTF-8'), 'UTF-8');
if (trim($iniciales) === '') {
    $iniciales = 'U';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mis Retas - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
:root{--azul:#1877f2;--azul2:#0ea5e9;--cyan:#8fefff;--rojo:#ff4b5c;--rojo2:#ff2f45;--texto:#111827;--gris:#6b7280;--sidebar:280px;--azul-neon:#0099ff}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100dvh;font-family:'Poppins',sans-serif;color:var(--texto);background:#f0f2f5;overflow-x:hidden;padding-bottom:104px}
.bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.20),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,.18),transparent 360px),linear-gradient(90deg,rgba(24,119,242,.14) 0%,rgba(255,255,255,.02) 48%,rgba(255,75,92,.15) 100%),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
.sidebar{width:var(--sidebar);height:100dvh;position:fixed;left:0;top:0;z-index:1000;padding:22px 14px 112px;background:rgba(255,255,255,.98);backdrop-filter:blur(14px);border-right:3px solid var(--azul-neon);box-shadow:8px 0 24px rgba(0,0,0,.08),0 0 18px rgba(0,153,255,.32);overflow-y:auto;transition:transform .3s ease}
body.sidebar-hidden .sidebar{transform:translateX(-105%)}
.logo-area{display:flex;align-items:center;gap:12px;margin-bottom:22px}.doctor-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff}.logo-text h2{font-family:'Orbitron',sans-serif;font-size:19px;color:var(--azul);line-height:1}.logo-text p{font-size:12px;color:var(--gris);margin-top:5px}
.sidebar-boceto{display:flex;flex-direction:column;gap:16px}.perfil-sidebar-card{min-height:78px;display:flex;align-items:center;gap:12px;padding:12px;text-decoration:none;border-radius:22px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.62);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.22);color:#374151}.perfil-sidebar-foto{width:52px;height:52px;min-width:52px;border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.76);overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--azul);font-weight:900}.perfil-sidebar-foto img{width:100%;height:100%;object-fit:cover;border-radius:16px}.perfil-sidebar-info span{font-size:11px;font-weight:900;color:var(--rojo2)}.perfil-sidebar-info strong{display:block;font-size:14px;font-weight:900;color:#374151}
.acciones-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.accion-boceto{min-height:95px;text-decoration:none;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.62);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.22);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#374151;font-weight:900;font-size:12px;text-align:center;line-height:1.15;padding:10px 6px;transition:.25s}.accion-boceto img,.cerrar-boceto img{width:38px;height:38px;object-fit:contain}.accion-boceto:hover,.accion-boceto.active{transform:translateY(-3px);color:var(--rojo2);border-color:rgba(255,75,92,.96)}
.cerrar-boceto{width:min(180px,100%);min-height:52px;margin:0 auto;text-decoration:none;border-radius:18px;background:linear-gradient(180deg,#fff,#fbfbfb);color:#374151;border:2px solid rgba(255,75,92,.62);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:900}.cerrar-boceto img{width:26px;height:26px}.info-boceto{display:flex;flex-direction:column;gap:10px}.info-boceto a{min-height:44px;text-decoration:none;border-radius:17px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(0,153,255,.24);display:flex;align-items:center;padding:0 14px;color:#374151;font-size:12px;font-weight:900}
.modo-oscuro-panel{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(0,153,255,.28);box-shadow:0 8px 18px rgba(0,0,0,.06)}.modo-oscuro-texto{display:flex;align-items:center;gap:7px;font-size:12px;color:#374151;font-weight:900}.switch-modo{width:54px;height:30px;border:0;border-radius:999px;background:#e5e7eb;padding:3px;cursor:pointer;transition:.25s;box-shadow:inset 0 2px 5px rgba(0,0,0,.16),0 0 0 2px rgba(255,75,92,.28)}.switch-modo span{width:24px;height:24px;border-radius:50%;background:#fff;display:block;box-shadow:0 3px 8px rgba(0,0,0,.22);transition:.25s}
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:74px;z-index:900;display:flex;align-items:center;gap:16px;padding:12px 24px;background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:3px solid var(--azul-neon);box-shadow:0 4px 18px rgba(0,0,0,.07);transition:left .3s}.topbar-title{flex:1;min-width:0}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.25rem,3vw,2rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-user{display:flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;background:#fff;font-weight:800;color:#374151}.menu-toggle{width:50px;height:50px;border:3px solid rgba(0,153,255,.50);border-radius:17px;background:#fff;color:#111827;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(0,0,0,.10)}.menu-toggle span{width:25px;height:2px;background:currentColor;position:relative;border-radius:999px}.menu-toggle span:before,.menu-toggle span:after{content:"";position:absolute;left:0;width:25px;height:2px;background:currentColor;border-radius:999px}.menu-toggle span:before{top:-8px}.menu-toggle span:after{top:8px}
body.sidebar-hidden .topbar{left:0}.main-content{position:relative;z-index:2;min-height:100dvh;margin-left:var(--sidebar);padding:104px clamp(16px,4vw,42px) 122px;transition:margin-left .3s}body.sidebar-hidden .main-content{margin-left:0}
.retas-page{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:22px}.retas-hero,.retas-section{border-radius:28px;background:rgba(255,255,255,.94);box-shadow:0 16px 34px rgba(0,0,0,.10),0 0 18px rgba(0,153,255,.14);border:1px solid rgba(17,24,39,.05);padding:clamp(18px,3vw,34px);overflow:hidden;position:relative}.retas-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 14% 18%,rgba(24,119,242,.26),transparent 270px),radial-gradient(circle at 86% 82%,rgba(255,75,92,.25),transparent 320px),linear-gradient(90deg,rgba(24,119,242,.14),rgba(255,255,255,.02) 46%,rgba(255,75,92,.15))}.retas-hero>*{position:relative;z-index:1}.kicker{display:inline-flex;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;color:var(--rojo2);background:#fff;border:2px solid rgba(255,75,92,.35);margin-bottom:12px}.retas-hero h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.8rem,5vw,2.7rem);margin-bottom:10px}.retas-hero p{color:#4b5563;font-size:clamp(.96rem,2.4vw,1.08rem);line-height:1.7}.hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px}.hero-btn{min-height:50px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 18px;border-radius:18px;text-decoration:none;font-size:13px;font-weight:900;transition:.25s}.hero-btn.primary{background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);color:#fff}.hero-btn.secondary{background:#fff;color:var(--azul);border:2px solid rgba(0,153,255,.42)}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:22px}.mini-stat{min-height:116px;border-radius:22px;background:rgba(255,255,255,.96);border:2px solid rgba(0,153,255,.26);padding:16px;display:flex;flex-direction:column;justify-content:center}.mini-title{font-size:11px;color:#6b7280;font-weight:900;text-transform:uppercase;letter-spacing:.45px;margin-bottom:6px}.mini-value{font-family:'Orbitron',sans-serif;font-size:clamp(1.4rem,4vw,2.05rem);font-weight:900;color:var(--azul)}
.alert{border-radius:18px;padding:15px 16px;background:#fff5f7;border:2px solid rgba(255,75,92,.38);color:#b91c1c;font-weight:900}.section-header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}.section-title{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.1rem,3vw,1.55rem)}.section-count{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;color:var(--rojo2);background:#fff;border:2px solid rgba(255,75,92,.35)}
.retas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;align-items:start}.reta-card{width:100%;border-radius:24px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(0,153,255,.28);box-shadow:0 12px 24px rgba(0,0,0,.08),0 0 0 2px rgba(255,75,92,.09);overflow:hidden;transition:.25s}.reta-card:hover{transform:translateY(-4px);border-color:rgba(255,75,92,.70)}.card-top{padding:14px;border-bottom:1px solid rgba(17,24,39,.07);background:radial-gradient(circle at 10% 10%,rgba(24,119,242,.18),transparent 150px),radial-gradient(circle at 90% 90%,rgba(255,75,92,.16),transparent 170px)}.card-title{font-size:16px;font-weight:900;color:#111827;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.card-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.deporte{color:#4b5563;font-size:12px;font-weight:900}.badge-estado{padding:6px 10px;border-radius:999px;font-size:10px;font-weight:900}.estado-activa{background:rgba(34,197,94,.12);color:#15803d}.estado-pendiente{background:rgba(24,119,242,.12);color:#1d4ed8}.estado-finalizada{background:rgba(107,114,128,.13);color:#4b5563}.estado-default{background:rgba(255,75,92,.10);color:var(--rojo2)}.rol-chip{display:inline-flex;margin-top:10px;padding:7px 11px;border-radius:999px;background:#fff;color:var(--azul);font-size:11px;font-weight:900;border:1px solid rgba(0,153,255,.25)}
.card-body{padding:12px;display:flex;flex-direction:column;gap:10px}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.info-item{border-radius:14px;background:rgba(24,119,242,.055);border:1px solid rgba(0,153,255,.18);padding:8px}.info-item.full{grid-column:1 / -1}.info-label{display:block;font-size:9px;font-weight:900;color:#6b7280;text-transform:uppercase;letter-spacing:.35px;margin-bottom:3px}.info-value{font-size:12px;font-weight:800;color:#1f2937;line-height:1.25;word-break:break-word}.resumen-equipos{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:7px;padding:9px 10px;border-radius:16px;background:rgba(24,119,242,.055);border:1px solid rgba(0,153,255,.18);font-size:11px;font-weight:900;color:#374151;text-align:center}.resumen-equipos span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.resumen-equipos strong{font-family:'Orbitron',sans-serif;color:var(--rojo2);font-size:11px}.card-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px}.card-actions .btn-ver-equipo{grid-column:1/-1}
.btn-mas-info,.btn-mi-equipo,.btn-ver-equipo,.btn-cerrar-modal{min-height:42px;border:0;text-decoration:none;border-radius:15px;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;font-size:12px;font-weight:900;cursor:pointer;text-align:center;font-family:'Poppins',sans-serif}.btn-mas-info{background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);color:#fff}.btn-mi-equipo,.btn-ver-equipo{background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#fff}.btn-cerrar-modal{background:#fff;color:var(--rojo2);border:2px solid rgba(255,75,92,.40)}
.empty{min-height:170px;border-radius:22px;display:flex;align-items:center;justify-content:center;text-align:center;padding:28px;background:rgba(24,119,242,.06);border:2px dashed rgba(0,153,255,.28);color:#4b5563;font-weight:900;line-height:1.6}
.modal-reta{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;padding:18px}.modal-reta.activa{display:flex}.modal-backdrop{position:absolute;inset:0;background:rgba(10,17,32,.58);backdrop-filter:blur(6px)}.modal-contenido{position:relative;width:min(920px,100%);max-height:88dvh;overflow:hidden;border-radius:28px;background:rgba(255,255,255,.98);border:2px solid rgba(0,153,255,.34);box-shadow:0 24px 60px rgba(0,0,0,.22),0 0 28px rgba(0,153,255,.22);display:flex;flex-direction:column}.modal-header{padding:18px 20px;border-bottom:1px solid rgba(17,24,39,.08);display:flex;align-items:flex-start;justify-content:space-between;gap:14px;background:radial-gradient(circle at 10% 10%,rgba(24,119,242,.18),transparent 180px),radial-gradient(circle at 92% 80%,rgba(255,75,92,.16),transparent 210px)}.modal-header h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.1rem,3vw,1.7rem);line-height:1.2}.modal-cerrar{width:42px;height:42px;min-width:42px;border:0;border-radius:15px;background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);color:#fff;font-size:28px;line-height:1;cursor:pointer;font-weight:900}.modal-body{padding:18px 20px;overflow:auto;display:flex;flex-direction:column;gap:16px}.modal-section{border-radius:22px;background:rgba(24,119,242,.04);border:1px solid rgba(0,153,255,.16);padding:14px}.modal-section h4{font-family:'Orbitron',sans-serif;color:var(--rojo2);font-size:14px;margin-bottom:12px}.modal-info-grid,.modal-equipos{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.modal-actions{padding:14px 20px 18px;border-top:1px solid rgba(17,24,39,.08);display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:rgba(248,251,255,.96)}body.modal-abierta{overflow:hidden}.equipo-panel{border-radius:18px;background:#fff;border:2px solid rgba(255,75,92,.20);padding:12px}.equipo-panel h4{font-size:13px;font-weight:900;color:var(--rojo2);margin-bottom:8px}.equipo-panel p{font-size:12px;color:#374151;line-height:1.55;font-weight:700}.jugadores-text{max-height:170px;overflow:auto;margin-top:7px;border-radius:12px;background:rgba(24,119,242,.06);padding:8px;font-size:11px;color:#374151;line-height:1.45}
.equipo-resumen-section{background:linear-gradient(135deg,rgba(24,119,242,.06),rgba(255,75,92,.045))}.equipo-resumen-header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px}.equipo-resumen-header strong{display:block;font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.05rem,3vw,1.45rem);line-height:1.2;margin-top:4px}.equipo-resumen-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.equipo-resumen-card{border-radius:17px;background:#fff;border:1px solid rgba(0,153,255,.18);padding:12px}.equipo-resumen-card span{display:block;font-size:10px;font-weight:900;color:#6b7280;text-transform:uppercase;margin-bottom:5px}.equipo-resumen-card strong{display:block;font-size:13px;font-weight:900;color:#1f2937}.jugadores-tabla-wrap{width:100%;overflow:auto;border-radius:18px;border:1px solid rgba(0,153,255,.22)}.jugadores-tabla{width:100%;border-collapse:separate;border-spacing:0;min-width:720px;background:#fff;overflow:hidden}.jugadores-tabla th{position:sticky;top:0;background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#fff;text-align:left;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.35px;padding:12px 11px;white-space:nowrap}.jugadores-tabla td{padding:11px;border-bottom:1px solid rgba(17,24,39,.07);font-size:12px;font-weight:800;color:#374151;vertical-align:top}.jugadores-tabla tbody tr:nth-child(even){background:rgba(24,119,242,.045)}
.bottom-nav{position:fixed;left:var(--sidebar);right:0;bottom:0;height:88px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);padding:8px 18px;border-radius:22px 22px 0 0;background:#fff;border-top:2px solid rgba(0,153,255,.72);box-shadow:0 -6px 18px rgba(0,0,0,.06);transition:left .3s,opacity .25s,transform .25s}.bottom-nav a{position:relative;text-decoration:none;display:flex;align-items:center;justify-content:center;border-radius:16px}.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain;display:block;transition:.25s;filter:none;opacity:.94;padding:3px;border-radius:14px;background:transparent}.bottom-nav a:after{content:"";position:absolute;width:54px;height:54px;left:50%;top:50%;transform:translate(-50%,-50%);border-radius:15px;opacity:0;transition:.25s}.bottom-nav a.active:after{opacity:1;border:2px solid rgba(255,75,92,.98);box-shadow:0 0 0 2px rgba(0,153,255,.98),0 0 12px rgba(0,153,255,.25)}body.sidebar-hidden .bottom-nav{left:0}body.bottom-nav-hidden .bottom-nav{opacity:0;transform:translateY(115%);pointer-events:none}.mobile-overlay{display:none}
body.dark-mode{color:#e5e7eb;background:#0b1220}body.dark-mode .bg-particles{background:radial-gradient(circle at 18% 20%,rgba(0,153,255,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.18),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,.14),transparent 360px),linear-gradient(135deg,#0b1220 0%,#111827 45%,#1f1117 100%)}body.dark-mode .sidebar,body.dark-mode .topbar,body.dark-mode .bottom-nav,body.dark-mode .retas-hero,body.dark-mode .retas-section,body.dark-mode .mini-stat,body.dark-mode .reta-card,body.dark-mode .perfil-sidebar-card,body.dark-mode .accion-boceto,body.dark-mode .modo-oscuro-panel,body.dark-mode .info-boceto a,body.dark-mode .cerrar-boceto,body.dark-mode .equipo-panel{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.78);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.24),0 0 18px rgba(0,153,255,.18)}body.dark-mode .topbar-user,body.dark-mode .kicker,body.dark-mode .section-count,body.dark-mode .hero-btn.secondary,body.dark-mode .rol-chip{background:#0b1220;color:#e5e7eb}body.dark-mode .logo-text h2,body.dark-mode .topbar-title h1,body.dark-mode .retas-hero h2,body.dark-mode .section-title,body.dark-mode .mini-value{color:#8fefff}body.dark-mode .logo-text p,body.dark-mode .retas-hero p,body.dark-mode .mini-title,body.dark-mode .deporte,body.dark-mode .empty,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-texto,body.dark-mode .perfil-sidebar-info strong,body.dark-mode .accion-boceto,body.dark-mode .topbar-user,body.dark-mode .cerrar-boceto,body.dark-mode .info-value,body.dark-mode .equipo-panel p,body.dark-mode .jugadores-text{color:#d1d5db}body.dark-mode .switch-modo{background:linear-gradient(135deg,#1877f2,#0ea5e9)}body.dark-mode .switch-modo span{transform:translateX(24px)}body.dark-mode .perfil-sidebar-info span,body.dark-mode .equipo-panel h4{color:#ff7b87}body.dark-mode .card-top{border-bottom-color:rgba(255,255,255,.08)}body.dark-mode .card-title{color:#f9fafb}body.dark-mode .info-item,body.dark-mode .jugadores-text,body.dark-mode .resumen-equipos,body.dark-mode .modal-section{background:rgba(0,153,255,.08);border-color:rgba(0,153,255,.20);color:#d1d5db}body.dark-mode .info-label{color:#9ca3af}body.dark-mode .modal-contenido{background:#111827;color:#e5e7eb;border-color:rgba(255,75,92,.78)}body.dark-mode .modal-header,body.dark-mode .modal-actions{background:#0b1220;border-color:rgba(255,255,255,.08)}body.dark-mode .modal-header h3,body.dark-mode .equipo-resumen-header strong{color:#8fefff}body.dark-mode .equipo-resumen-card,body.dark-mode .jugadores-tabla{background:#0b1220;color:#e5e7eb;border-color:rgba(0,153,255,.22)}body.dark-mode .equipo-resumen-card strong,body.dark-mode .jugadores-tabla td{color:#d1d5db}body.dark-mode .bottom-nav a img,body.dark-mode .accion-boceto img,body.dark-mode .cerrar-boceto img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05)}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:820px){.sidebar{transform:translateX(-105%)}body.sidebar-open .sidebar{transform:translateX(0)}.topbar,body.sidebar-hidden .topbar{left:0;height:68px;padding:10px 14px}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;height:84px}.main-content,body.sidebar-hidden .main-content{margin-left:0;padding-top:94px}.topbar-user{display:none}.mobile-overlay{display:block;position:fixed;inset:0;z-index:950;background:rgba(17,24,39,.28);opacity:0;visibility:hidden;transition:.25s}body.sidebar-open .mobile-overlay{opacity:1;visibility:visible}}@media(max-width:720px){.retas-grid{grid-template-columns:1fr}.card-actions{grid-template-columns:1fr}.card-actions .btn-ver-equipo{grid-column:auto}.modal-info-grid,.modal-equipos,.equipo-resumen-grid{grid-template-columns:1fr}.modal-actions{display:grid;grid-template-columns:1fr}.jugadores-tabla{min-width:680px}}@media(max-width:620px){.summary-grid,.info-grid{grid-template-columns:1fr}.hero-actions{flex-direction:column}.hero-btn{width:100%}.bottom-nav{height:76px;padding:6px 4px}.bottom-nav a img{width:34px;height:34px}.bottom-nav a:after{width:44px;height:44px}}
</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area">
        <img src="<?php echo mis_retas_esc($base); ?>assets/doctor.png" alt="Logo Doctor" class="doctor-logo" onerror="this.style.display='none'">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <a href="<?php echo mis_retas_esc($base); ?>MiPerfil.php" class="perfil-sidebar-card">
            <div class="perfil-sidebar-foto">
                <?php if ($FotoPerfilUsuario !== ''): ?>
                    <img src="<?php echo mis_retas_esc($FotoPerfilUsuario); ?>" alt="Foto de perfil" onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='<?php echo mis_retas_esc($iniciales); ?>';">
                <?php else: ?>
                    <?php echo mis_retas_esc($iniciales); ?>
                <?php endif; ?>
            </div>
            <div class="perfil-sidebar-info">
                <span>Perfil</span>
                <strong><?php echo mis_retas_esc($Nombre); ?></strong>
            </div>
        </a>

        <div class="acciones-grid">
            <a href="<?php echo mis_retas_esc($base); ?>Equipo/UnirmeOtroEquipo.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgUnion.png" alt=""><span>Unirme equipo</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Equipo/CrearEquipo.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgCreacion.png" alt=""><span>Crear equipo</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Solicitudes.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgSolicitud.png" alt=""><span>Solicitud</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Equipo/Mis_Equipos.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgEquipo.png" alt=""><span>Equipo</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Ligas/liga.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgLigas.png" alt=""><span>Ligas</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Retar/retar.php" class="accion-boceto active"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgReta.png" alt=""><span>Retar</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Canchas/Canchas.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgCanchas.png" alt=""><span>Canchas</span></a>
            <a href="<?php echo mis_retas_esc($base); ?>Amigos.php" class="accion-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgAmigos.png" alt=""><span>Amigos</span></a>
        </div>

        <a href="<?php echo mis_retas_esc($base); ?>login.php" class="cerrar-boceto"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgCerrar.png" alt=""><span>Cerrar sesión</span></a>

        <div class="info-boceto">
            <a href="<?php echo mis_retas_esc($base); ?>Informacion.php">Información</a>
            <a href="<?php echo mis_retas_esc($base); ?>AcercaDe.php">Acerca de</a>
            <a href="<?php echo mis_retas_esc($base); ?>SoporteTecnico.php">Soporte técnico</a>
        </div>

        <div class="modo-oscuro-panel">
            <div class="modo-oscuro-texto"><span>🌙</span><strong>Modo oscuro</strong></div>
            <button type="button" class="switch-modo" id="darkModeToggle" aria-label="<?php echo $modoOscuroActivo ? 'Desactivar modo oscuro' : 'Activar modo oscuro'; ?>"><span></span></button>
        </div>
    </div>
</aside>

<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="topbar-title"><h1>Mis Retas de <?php echo mis_retas_esc($Nombre); ?></h1></div>
    <div class="topbar-user"><span>👤</span><span><?php echo mis_retas_esc($Nombre); ?></span></div>
</header>

<main class="main-content">
    <div class="retas-page">
        <section class="retas-hero">
            <div class="hero-top">
                <div class="hero-copy">
                    <span class="kicker">⚽ Panel de retas</span>
                    <h2>MIS RETAS</h2>
                    <p>Consulta todas las retas programadas donde apareces como creador, capitán o jugador registrado dentro de los equipos.</p>
                </div>
            </div>

            <div class="hero-actions">
                <a class="hero-btn primary" href="<?php echo mis_retas_esc($base); ?>Retar/retar.php">🔥 Ir a Retar</a>
                <a class="hero-btn secondary" href="<?php echo mis_retas_esc($base); ?>Retar/PublicarReta.php">➕ Publicar reta</a>
            </div>

            <div class="summary-grid">
                <div class="mini-stat"><div class="mini-title">Total de mis retas</div><div class="mini-value"><?php echo count($misRetas); ?></div></div>
                <div class="mini-stat"><div class="mini-title">Programadas / activas</div><div class="mini-value"><?php echo $totalProgramadas; ?></div></div>
                <div class="mini-stat"><div class="mini-title">Pendientes u otras</div><div class="mini-value"><?php echo $totalPendientes; ?></div></div>
                <div class="mini-stat"><div class="mini-title">Finalizadas / cerradas</div><div class="mini-value"><?php echo $totalFinalizadas; ?></div></div>
            </div>
        </section>

        <?php if ($mensajeError !== ''): ?>
            <div class="alert"><?php echo mis_retas_esc($mensajeError); ?></div>
        <?php endif; ?>

        <section class="retas-section">
            <div class="section-header">
                <div class="section-title">📋 Retas encontradas</div>
                <div class="section-count"><?php echo count($misRetas); ?> registros</div>
            </div>

            <?php if (!empty($misRetas)): ?>
                <div class="retas-grid">
                    <?php foreach ($misRetas as $reta): ?>
                        <?php
                            $idEquipoUsuarioReta = mis_retas_id_equipo_usuario($reta, $Id_Retador);
                            $urlMiEquipoTemp = 'MiEquipoTemp.php?' . http_build_query([
                                'id_reta' => $reta['id_reta'],
                                'id_programada' => $reta['id_programada'],
                                'id_equipo' => $idEquipoUsuarioReta
                            ]);
                            $modalId = 'modal-reta-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$reta['id_programada']);
                            $nombreEquipoUsuarioReta = mis_retas_nombre_equipo_usuario($reta, $Id_Retador);
                            $idCapitanEquipo = ($idEquipoUsuarioReta !== '' && trim((string)$reta['id_equipo1']) === trim((string)$idEquipoUsuarioReta)) ? $reta['capitan_equipo1'] : (($idEquipoUsuarioReta !== '' && trim((string)$reta['id_equipo2']) === trim((string)$idEquipoUsuarioReta)) ? $reta['capitan_equipo2'] : '');
                            $capitanEquipoUsuarioReta = mis_retas_nombre_retador_por_id($idCapitanEquipo, $retadoresNombre);
                            $modalEquipoId = 'modal-equipo-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$reta['id_programada']) . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$idEquipoUsuarioReta);
                            $jugadoresEquipoActual = ($idEquipoUsuarioReta !== '' && isset($jugadoresEquipoTemp[(string)$idEquipoUsuarioReta])) ? $jugadoresEquipoTemp[(string)$idEquipoUsuarioReta] : [];
                            $totalIntegrantesEquipo = count($jugadoresEquipoActual);
                        ?>

                        <article class="reta-card compact-card">
                            <div class="card-top">
                                <div class="card-title"><?php echo mis_retas_esc(mis_retas_valor($reta['id_reta'])); ?></div>
                                <div class="card-meta">
                                    <div class="deporte"><?php echo mis_retas_esc(mis_retas_valor($reta['deporte'])); ?></div>
                                    <div class="badge-estado <?php echo mis_retas_estado_clase($reta['estado_programada']); ?>"><?php echo mis_retas_esc(mis_retas_valor($reta['estado_programada'])); ?></div>
                                </div>
                                <div class="rol-chip"><?php echo mis_retas_esc(mis_retas_rol($reta, $Id_Retador)); ?></div>
                            </div>

                            <div class="card-body compact-body">
                                <div class="info-grid compact-info">
                                    <div class="info-item"><span class="info-label">Cancha</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['cancha'])); ?></span></div>
                                    <div class="info-item"><span class="info-label">Fecha</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_fecha($reta['fecha_programada'])); ?></span></div>
                                    <div class="info-item"><span class="info-label">Hora</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_hora($reta['hora_programada'])); ?></span></div>
                                    <div class="info-item"><span class="info-label">C.P.</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['codigo_postal'])); ?></span></div>
                                </div>

                                <div class="resumen-equipos">
                                    <span><?php echo mis_retas_esc(mis_retas_valor($reta['nombre_equipo1'])); ?></span>
                                    <strong>VS</strong>
                                    <span><?php echo mis_retas_esc(mis_retas_valor($reta['nombre_equipo2'])); ?></span>
                                </div>

                                <div class="card-actions triple-acciones">
                                    <button type="button" class="btn-mas-info" data-open-modal="<?php echo mis_retas_esc($modalId); ?>">ℹ️ Más información</button>
                                    <a class="btn-mi-equipo" href="<?php echo mis_retas_esc($urlMiEquipoTemp); ?>">👥 Mi equipo</a>
                                    <button type="button" class="btn-ver-equipo" data-open-modal="<?php echo mis_retas_esc($modalEquipoId); ?>">👀 Ver equipo <?php echo mis_retas_esc($nombreEquipoUsuarioReta); ?></button>
                                </div>
                            </div>
                        </article>

                        <div class="modal-reta" id="<?php echo mis_retas_esc($modalId); ?>" aria-hidden="true">
                            <div class="modal-backdrop" data-close-modal="<?php echo mis_retas_esc($modalId); ?>"></div>
                            <div class="modal-contenido" role="dialog" aria-modal="true" aria-labelledby="titulo-<?php echo mis_retas_esc($modalId); ?>">
                                <div class="modal-header">
                                    <div>
                                        <span class="kicker modal-kicker">🏟️ Información completa</span>
                                        <h3 id="titulo-<?php echo mis_retas_esc($modalId); ?>"><?php echo mis_retas_esc(mis_retas_valor($reta['id_reta'])); ?></h3>
                                    </div>
                                    <button type="button" class="modal-cerrar" data-close-modal="<?php echo mis_retas_esc($modalId); ?>">×</button>
                                </div>

                                <div class="modal-body">
                                    <section class="modal-section">
                                        <h4>Datos generales</h4>
                                        <div class="modal-info-grid">
                                            <div class="info-item"><span class="info-label">ID programada</span><span class="info-value"><?php echo mis_retas_esc($reta['id_programada']); ?></span></div>
                                            <div class="info-item"><span class="info-label">ID creador</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['id_creador'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Deporte</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['deporte'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Cancha</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['cancha'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Código postal</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['codigo_postal'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Estado publicada</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['estado_reta_publicada'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Estado programada</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['estado_programada'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Creado en</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['creado_en'])); ?></span></div>
                                        </div>
                                    </section>

                                    <section class="modal-section">
                                        <h4>Fecha, hora y ubicación</h4>
                                        <div class="modal-info-grid">
                                            <div class="info-item"><span class="info-label">Fecha reta</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_fecha($reta['fecha_reta'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Hora reta</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_hora($reta['hora_reta'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Fecha programada</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_fecha($reta['fecha_programada'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Hora programada</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_hora($reta['hora_programada'])); ?></span></div>
                                            <div class="info-item full"><span class="info-label">Dirección</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['direccion'])); ?></span></div>
                                            <div class="info-item full"><span class="info-label">Descripción</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['descripcion'])); ?></span></div>
                                            <div class="info-item"><span class="info-label">Cantidad mínima</span><span class="info-value"><?php echo mis_retas_esc($reta['cantidad_minima']); ?></span></div>
                                            <div class="info-item"><span class="info-label">Cantidad máxima</span><span class="info-value"><?php echo mis_retas_esc(mis_retas_valor($reta['cantidad_maxima'])); ?></span></div>
                                        </div>
                                    </section>

                                    <section class="modal-section">
                                        <h4>Equipos</h4>
                                        <div class="equipos-box modal-equipos">
                                            <div class="equipo-panel">
                                                <h4>Equipo 1</h4>
                                                <p><strong>ID:</strong> <?php echo mis_retas_esc($reta['id_equipo1']); ?></p>
                                                <p><strong>Nombre:</strong> <?php echo mis_retas_esc(mis_retas_valor($reta['nombre_equipo1'])); ?></p>
                                                <p><strong>Capitán:</strong> <?php echo mis_retas_esc(mis_retas_nombre_retador_por_id($reta['capitan_equipo1'], $retadoresNombre)); ?></p>
                                                <p><strong>Total:</strong> <?php echo mis_retas_esc($reta['total_equipo1']); ?></p>
                                                <div class="jugadores-text"><?php echo nl2br(mis_retas_esc(mis_retas_valor($reta['jugadores_equipo1']))); ?></div>
                                            </div>

                                            <div class="equipo-panel">
                                                <h4>Equipo 2</h4>
                                                <p><strong>ID:</strong> <?php echo mis_retas_esc($reta['id_equipo2']); ?></p>
                                                <p><strong>Nombre:</strong> <?php echo mis_retas_esc(mis_retas_valor($reta['nombre_equipo2'])); ?></p>
                                                <p><strong>Capitán:</strong> <?php echo mis_retas_esc(mis_retas_nombre_retador_por_id($reta['capitan_equipo2'], $retadoresNombre)); ?></p>
                                                <p><strong>Total:</strong> <?php echo mis_retas_esc($reta['total_equipo2']); ?></p>
                                                <div class="jugadores-text"><?php echo nl2br(mis_retas_esc(mis_retas_valor($reta['jugadores_equipo2']))); ?></div>
                                            </div>
                                        </div>
                                    </section>
                                </div>

                                <div class="modal-actions">
                                    <a class="btn-mi-equipo" href="<?php echo mis_retas_esc($urlMiEquipoTemp); ?>">👥 Mi equipo</a>
                                    <button type="button" class="btn-cerrar-modal" data-close-modal="<?php echo mis_retas_esc($modalId); ?>">Cerrar</button>
                                </div>
                            </div>
                        </div>

                        <div class="modal-reta modal-equipo-temp" id="<?php echo mis_retas_esc($modalEquipoId); ?>" aria-hidden="true">
                            <div class="modal-backdrop" data-close-modal="<?php echo mis_retas_esc($modalEquipoId); ?>"></div>
                            <div class="modal-contenido modal-contenido-equipo" role="dialog" aria-modal="true" aria-labelledby="titulo-<?php echo mis_retas_esc($modalEquipoId); ?>">
                                <div class="modal-header">
                                    <div>
                                        <span class="kicker modal-kicker">👥 Equipo registrado</span>
                                        <h3 id="titulo-<?php echo mis_retas_esc($modalEquipoId); ?>"><?php echo mis_retas_esc($nombreEquipoUsuarioReta); ?></h3>
                                    </div>
                                    <button type="button" class="modal-cerrar" data-close-modal="<?php echo mis_retas_esc($modalEquipoId); ?>">×</button>
                                </div>

                                <div class="modal-body">
                                    <section class="modal-section equipo-resumen-section">
                                        <div class="equipo-resumen-header">
                                            <div>
                                                <span class="info-label">Nombre del equipo</span>
                                                <strong><?php echo mis_retas_esc($nombreEquipoUsuarioReta); ?></strong>
                                            </div>
                                            <span class="badge-estado estado-activa"><?php echo (int)$totalIntegrantesEquipo; ?> integrantes</span>
                                        </div>

                                        <div class="equipo-resumen-grid">
                                            <div class="equipo-resumen-card">
                                                <span>Deporte</span>
                                                <strong><?php echo mis_retas_esc(mis_retas_valor($reta['deporte'])); ?></strong>
                                            </div>

                                            <div class="equipo-resumen-card">
                                                <span>Capitán</span>
                                                <strong><?php echo mis_retas_esc($capitanEquipoUsuarioReta); ?></strong>
                                            </div>

                                            <div class="equipo-resumen-card">
                                                <span>ID equipo</span>
                                                <strong><?php echo mis_retas_esc(mis_retas_valor($idEquipoUsuarioReta)); ?></strong>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="modal-section">
                                        <h4>Jugadores registrados</h4>

                                        <?php if ($idEquipoUsuarioReta === ''): ?>
                                            <div class="empty">No se pudo detectar el ID del equipo vinculado a tu usuario en esta reta.</div>
                                        <?php elseif ($columnaEquipoTemp === ''): ?>
                                            <div class="empty"><?php echo mis_retas_esc($mensajeEquipoTemp !== '' ? $mensajeEquipoTemp : 'No se encontró la columna del equipo en r_equipotemp.'); ?></div>
                                        <?php elseif (empty($jugadoresEquipoActual)): ?>
                                            <div class="empty">No hay jugadores registrados en r_equipotemp para el equipo <?php echo mis_retas_esc($nombreEquipoUsuarioReta); ?>.</div>
                                        <?php else: ?>
                                            <div class="jugadores-tabla-wrap">
                                                <table class="jugadores-tabla">
                                                    <thead>
                                                        <tr>
                                                            <th>ID jugador</th>
                                                            <th>Nombre</th>
                                                            <th>Apellido</th>
                                                            <th>Número</th>
                                                            <th>Posición</th>
                                                            <th>Rango</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($jugadoresEquipoActual as $jugadorTemp): ?>
                                                            <tr>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_id_jugador_temp($jugadorTemp))); ?></td>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_nombre_jugador_temp($jugadorTemp))); ?></td>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_apellido_jugador_temp($jugadorTemp))); ?></td>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_numero_jugador_temp($jugadorTemp))); ?></td>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_posicion_jugador_temp($jugadorTemp))); ?></td>
                                                                <td><?php echo mis_retas_esc(mis_retas_valor(mis_retas_rango_jugador_temp($jugadorTemp))); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </div>

                                <div class="modal-actions">
                                    <a class="btn-mi-equipo" href="<?php echo mis_retas_esc($urlMiEquipoTemp); ?>">👥 Mi equipo</a>
                                    <button type="button" class="btn-cerrar-modal" data-close-modal="<?php echo mis_retas_esc($modalEquipoId); ?>">Cerrar</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No se encontraron retas programadas relacionadas con tu usuario.<br>Cuando tu ID aparezca como creador, capitán o jugador, aquí se mostrarán tus retas.</div>
            <?php endif; ?>
        </section>
    </div>
</main>

<nav class="bottom-nav">
    <a href="<?php echo mis_retas_esc($base); ?>Perfil2.php" aria-label="Inicio"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgInicio.png" alt=""></a>
    <a href="<?php echo mis_retas_esc($base); ?>Retar/retar.php" class="active" aria-label="Retar"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgReta.png" alt=""></a>
    <a href="<?php echo mis_retas_esc($base); ?>Ligas/liga.php" aria-label="Ligas"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgLigas.png" alt=""></a>
    <a href="<?php echo mis_retas_esc($base); ?>Agenda.php" aria-label="Agenda"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgAgenda.png" alt=""></a>
    <a href="<?php echo mis_retas_esc($base); ?>Notificaciones.php" aria-label="Notificaciones"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgNoti.png" alt=""><?php if ($nuevas_count > 0): ?><span class="bottom-noti">!</span><?php endif; ?></a>
    <a href="<?php echo mis_retas_esc($base); ?>MiPerfil.php" aria-label="Perfil"><img src="<?php echo mis_retas_esc($base); ?>Imagenes/ImgPerfil.png" alt=""></a>
</nav>

<script>
const body = document.body;
const menuToggle = document.getElementById('menuToggle');
const mobileOverlay = document.getElementById('mobileOverlay');
const darkModeToggle = document.getElementById('darkModeToggle');
let lastScrollY = window.scrollY;

function closeSidebar(){
    body.classList.remove('sidebar-open');
}

if(menuToggle){
    menuToggle.addEventListener('click', () => {
        if(window.innerWidth <= 820){
            body.classList.toggle('sidebar-open');
        }else{
            body.classList.toggle('sidebar-hidden');
        }
    });
}

if(mobileOverlay){
    mobileOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', () => {
    if(window.innerWidth > 820){
        body.classList.remove('sidebar-open');
    }
});

window.addEventListener('scroll', () => {
    const currentY = window.scrollY;

    if(currentY > 20){
        body.classList.add('topbar-compact');
    }else{
        body.classList.remove('topbar-compact');
    }

    if(currentY > lastScrollY && currentY > 160){
        body.classList.add('bottom-nav-hidden');
    }else{
        body.classList.remove('bottom-nav-hidden');
    }

    lastScrollY = currentY;
}, {passive:true});

function abrirModalReta(id){
    const modal = document.getElementById(id);
    if(!modal){
        return;
    }

    modal.classList.add('activa');
    modal.setAttribute('aria-hidden', 'false');
    body.classList.add('modal-abierta');
}

function cerrarModalReta(id){
    const modal = document.getElementById(id);
    if(!modal){
        return;
    }

    modal.classList.remove('activa');
    modal.setAttribute('aria-hidden', 'true');
    body.classList.remove('modal-abierta');
}

document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => abrirModalReta(btn.getAttribute('data-open-modal')));
});

document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', () => cerrarModalReta(btn.getAttribute('data-close-modal')));
});

document.addEventListener('keydown', e => {
    if(e.key === 'Escape'){
        document.querySelectorAll('.modal-reta.activa').forEach(modal => cerrarModalReta(modal.id));
    }
});

if(darkModeToggle){
    darkModeToggle.addEventListener('click', () => {
        const activarOscuro = !body.classList.contains('dark-mode');
        body.classList.toggle('dark-mode', activarOscuro);
        darkModeToggle.disabled = true;

        const datos = new FormData();
        datos.append('accion', 'actualizar_modo_perfil');
        datos.append('modo', activarOscuro ? 'oscuro' : 'predeterminado');

        fetch(window.location.href, {
            method:'POST',
            body:datos,
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'}
        }).then(r => r.json()).then(data => {
            if(!data || !data.ok){
                body.classList.toggle('dark-mode', !activarOscuro);
                alert(data && data.mensaje ? data.mensaje : 'No se pudo guardar el modo.');
            }
        }).catch(() => {
            body.classList.toggle('dark-mode', !activarOscuro);
            alert('No se pudo conectar con la base de datos.');
        }).finally(() => {
            darkModeToggle.disabled = false;
        });
    });
}
</script>
</body>
</html>
