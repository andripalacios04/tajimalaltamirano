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

function resolverTablaCanchas($conn) {
    $opciones = ['c_canchas', 'C_Canchas'];
    foreach ($opciones as $tabla) {
        $tablaSafe = $conn->real_escape_string($tabla);
        $res = $conn->query("SHOW TABLES LIKE '$tablaSafe'");
        if ($res && $res->num_rows > 0) {
            return $tabla;
        }
    }
    return 'c_canchas';
}

function tipoColumna($conn, $tabla, $columna) {
    $tablaSql = '`' . str_replace('`', '``', $tabla) . '`';
    $columnaSafe = $conn->real_escape_string($columna);
    $res = $conn->query("SHOW COLUMNS FROM $tablaSql LIKE '$columnaSafe'");
    if ($res && $fila = $res->fetch_assoc()) {
        return isset($fila['Type']) ? strtolower((string)$fila['Type']) : '';
    }
    return '';
}

function columnaExiste($conn, $tabla, $columna) {
    $tablaSql = '`' . str_replace('`', '``', $tabla) . '`';
    $columnaSafe = $conn->real_escape_string($columna);
    $res = $conn->query("SHOW COLUMNS FROM $tablaSql LIKE '$columnaSafe'");
    return ($res && $res->num_rows > 0);
}

function baseIdCancha($nombre) {
    $base = trim((string)$nombre);
    $convertida = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
    if ($convertida !== false) {
        $base = $convertida;
    }
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
    if ($base === '') {
        $base = 'CANCHA';
    }
    $base = substr($base, 0, 45);
    return $base;
}

function generarIdCancha($conn, $tabla, $nombre) {
    $base = baseIdCancha($nombre);
    $tablaSql = '`' . str_replace('`', '``', $tabla) . '`';
    $sql = "SELECT Id_Cancha FROM $tablaSql WHERE Id_Cancha = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $base . random_int(1000, 9999);
    }

    do {
        $idCancha = $base . random_int(1000, 9999);
        $stmt->bind_param("s", $idCancha);
        $stmt->execute();
        $res = $stmt->get_result();
        $existe = ($res && $res->num_rows > 0);
    } while ($existe);

    $stmt->close();
    return $idCancha;
}

function existeDeporte($conn, $idDeporte) {
    if (trim((string)$idDeporte) === '') {
        return false;
    }
    $sql = "SELECT Id_Deporte FROM deporte WHERE Id_Deporte = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $idDeporte);
    $stmt->execute();
    $stmt->store_result();
    $ok = ($stmt->num_rows > 0);
    $stmt->close();
    return $ok;
}

$Nombre = obtenerValorSesion($usuarios, ['Nombre', 'nombre']);
if ($Nombre === '') {
    $Nombre = 'Usuario';
}

$Id_Retador = obtenerValorSesion($usuarios, ['Id_Retador', 'id_retador']);

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

$duenoDefault = trim($Nombre);

if (!empty($Id_Retador)) {
    $sqlDueno = "SELECT Nombre, Apellido, Pais, Estado, CodigoPostal FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmtDueno = $conn->prepare($sqlDueno);
    if ($stmtDueno) {
        $stmtDueno->bind_param("s", $Id_Retador);
        $stmtDueno->execute();
        $resDueno = $stmtDueno->get_result();
        if ($filaDueno = $resDueno->fetch_assoc()) {
            $nombreD = isset($filaDueno['Nombre']) ? trim((string)$filaDueno['Nombre']) : '';
            $apellidoD = isset($filaDueno['Apellido']) ? trim((string)$filaDueno['Apellido']) : '';
            $duenoDefault = trim($nombreD . ' ' . $apellidoD);
            if ($duenoDefault === '') {
                $duenoDefault = trim($Nombre);
            }
            if (!isset($usuarios['Pais']) && !empty($filaDueno['Pais'])) {
                $usuarios['Pais'] = $filaDueno['Pais'];
            }
            if (!isset($usuarios['Estado']) && !empty($filaDueno['Estado'])) {
                $usuarios['Estado'] = $filaDueno['Estado'];
            }
            if (!isset($usuarios['CodigoPostal']) && !empty($filaDueno['CodigoPostal'])) {
                $usuarios['CodigoPostal'] = $filaDueno['CodigoPostal'];
            }
        }
        $stmtDueno->close();
    }
}

$tablaCanchas = resolverTablaCanchas($conn);
$tipoIdCancha = tipoColumna($conn, $tablaCanchas, 'Id_Cancha');
$tipoIdDueno = tipoColumna($conn, $tablaCanchas, 'Id_Dueno');
$tipoCosto = tipoColumna($conn, $tablaCanchas, 'Costo');
$estructuraAvisos = [];

if ($tipoIdCancha !== '' && strpos($tipoIdCancha, 'int') !== false) {
    $estructuraAvisos[] = 'Id_Cancha está como INT y para guardar un ID tipo Nombre1234 debe ser VARCHAR.';
}

if ($tipoIdDueno !== '' && strpos($tipoIdDueno, 'int') !== false && !ctype_digit((string)$Id_Retador)) {
    $estructuraAvisos[] = 'Id_Dueno está como INT, pero Id_Retador es texto. Conviene cambiar Id_Dueno a VARCHAR(50).';
}

if ($tipoCosto !== '' && (strpos($tipoCosto, 'decimal') !== false || strpos($tipoCosto, 'int') !== false || strpos($tipoCosto, 'float') !== false || strpos($tipoCosto, 'double') !== false)) {
    $estructuraAvisos[] = 'Costo está como número y ahora debe permitir letras. Cámbialo a VARCHAR(120).';
}

foreach (['Dias_Disponibles', 'Horario_Apertura', 'Horario_Clausura'] as $columnaNecesaria) {
    if (!columnaExiste($conn, $tablaCanchas, $columnaNecesaria)) {
        $estructuraAvisos[] = 'Falta la columna ' . $columnaNecesaria . ' en la tabla de canchas.';
    }
}

$deportes = [];
$resDeportes = $conn->query("SELECT Id_Deporte, Nombre FROM deporte ORDER BY Nombre ASC");
if ($resDeportes) {
    while ($filaDep = $resDeportes->fetch_assoc()) {
        $deportes[] = $filaDep;
    }
}

$mensajeExito = '';
$mensajeError = '';
$idGenerado = '';
$datosRegistrados = null;

$valores = [
    'nombre_cancha' => '',
    'dueno' => $duenoDefault,
    'pais' => obtenerValorSesion($usuarios, ['Pais', 'pais']),
    'estado' => obtenerValorSesion($usuarios, ['Estado', 'estado']),
    'ciudad' => '',
    'direccion' => '',
    'codigo_postal' => obtenerValorSesion($usuarios, ['CodigoPostal', 'Codigo_Postal', 'codigo_postal', 'codigoPostal']),
    'correo' => '',
    'telefono' => '',
    'tipo_cancha' => '',
    'tipo_otro' => '',
    'deporte1' => '',
    'deporte2' => '',
    'deporte3' => '',
    'estado_cancha' => 'Disponible',
    'hora_inicio' => '',
    'hora_fin' => '',
    'comentarios' => '',
    'tipo_costo' => 'gratuito',
    'cantidad_costo' => ''
];

$diasSeleccionados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'registrar_cancha') {
    foreach ($valores as $clave => $valor) {
        if (isset($_POST[$clave])) {
            $valores[$clave] = trim((string)$_POST[$clave]);
        }
    }

    $diasSeleccionados = isset($_POST['horario_dias']) && is_array($_POST['horario_dias']) ? $_POST['horario_dias'] : [];
    $diasPermitidos = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $diasSeleccionados = array_values(array_intersect($diasPermitidos, $diasSeleccionados));

    $errores = [];

    if (!empty($estructuraAvisos)) {
        $errores[] = implode(' ', $estructuraAvisos);
    }

    if ($Id_Retador === '') {
        $errores[] = 'No se encontró el usuario que inició sesión.';
    }

    if ($valores['nombre_cancha'] === '') $errores[] = 'Escribe el nombre de la cancha.';
    if ($valores['dueno'] === '') $errores[] = 'Escribe el nombre completo del dueño.';
    if ($valores['pais'] === '') $errores[] = 'Escribe el país.';
    if ($valores['estado'] === '') $errores[] = 'Escribe el estado.';
    if ($valores['ciudad'] === '') $errores[] = 'Escribe la ciudad.';
    if ($valores['direccion'] === '') $errores[] = 'Escribe la dirección.';
    if ($valores['codigo_postal'] === '') $errores[] = 'Escribe el código postal.';
    if ($valores['correo'] === '') $errores[] = 'Escribe el correo electrónico.';
    if ($valores['telefono'] === '') $errores[] = 'Escribe el número de teléfono.';
    if ($valores['tipo_cancha'] === '') $errores[] = 'Selecciona el tipo de cancha.';
    if ($valores['tipo_cancha'] === 'Otro' && $valores['tipo_otro'] === '') $errores[] = 'Escribe el otro tipo de cancha.';
    if ($valores['deporte1'] === '') $errores[] = 'Selecciona el deporte principal.';
    if ($valores['estado_cancha'] === '') $errores[] = 'Selecciona el estado de la cancha.';
    if (empty($diasSeleccionados)) $errores[] = 'Selecciona al menos un día disponible.';
    if ($valores['hora_inicio'] === '') $errores[] = 'Selecciona el horario de apertura.';
    if ($valores['hora_fin'] === '') $errores[] = 'Selecciona el horario de clausura.';
    if ($valores['comentarios'] === '') $errores[] = 'Escribe los comentarios de la cancha.';
    if ($valores['tipo_costo'] === '') $errores[] = 'Selecciona si la cancha es gratuita o pagada.';

    if ($valores['correo'] !== '' && !filter_var($valores['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico no tiene un formato válido.';
    }

    if ($valores['telefono'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $valores['telefono'])) {
        $errores[] = 'El teléfono solo debe llevar números, espacios o signos básicos.';
    }

    if (strlen($valores['codigo_postal']) > 10) {
        $errores[] = 'El código postal no debe pasar de 10 caracteres.';
    }

    $tiposValidos = ['Pasto sintético', 'Pasto natural', 'Concreto', 'Arcilla', 'Otro'];
    if ($valores['tipo_cancha'] !== '' && !in_array($valores['tipo_cancha'], $tiposValidos, true)) {
        $errores[] = 'El tipo de cancha seleccionado no es válido.';
    }

    $estadosValidos = ['Disponible', 'Clausurado', 'Suspendido'];
    if ($valores['estado_cancha'] !== '' && !in_array($valores['estado_cancha'], $estadosValidos, true)) {
        $errores[] = 'El estado de la cancha seleccionado no es válido.';
    }

    if ($valores['deporte1'] !== '' && !existeDeporte($conn, $valores['deporte1'])) {
        $errores[] = 'El deporte 1 no existe en la tabla deporte.';
    }

    if ($valores['deporte2'] !== '' && !existeDeporte($conn, $valores['deporte2'])) {
        $errores[] = 'El deporte 2 no existe en la tabla deporte.';
    }

    if ($valores['deporte3'] !== '' && !existeDeporte($conn, $valores['deporte3'])) {
        $errores[] = 'El deporte 3 no existe en la tabla deporte.';
    }

    $deportesElegidos = array_filter([$valores['deporte1'], $valores['deporte2'], $valores['deporte3']], function($v) {
        return trim((string)$v) !== '';
    });

    if (count($deportesElegidos) !== count(array_unique($deportesElegidos))) {
        $errores[] = 'No repitas el mismo deporte en los espacios 1, 2 y 3.';
    }

    $costo = 'Gratuito';
    if ($valores['tipo_costo'] === 'gratuito') {
        $costo = 'Gratuito';
    } elseif ($valores['tipo_costo'] === 'pagado') {
        if ($valores['cantidad_costo'] === '') {
            $errores[] = 'Escribe el costo de la cancha.';
        } elseif (strlen($valores['cantidad_costo']) > 120) {
            $errores[] = 'El costo no debe pasar de 120 caracteres.';
        } else {
            $costo = $valores['cantidad_costo'];
        }
    } else {
        $errores[] = 'Selecciona una opción válida de costo.';
    }

    $fotoRutaBD = '';
    $fotoSubidaTemporal = '';

    if (!isset($_FILES['foto_fachada']) || !is_array($_FILES['foto_fachada']) || $_FILES['foto_fachada']['error'] === UPLOAD_ERR_NO_FILE) {
        $errores[] = 'Adjunta una foto de la fachada menor a 1 MB.';
    } elseif ($_FILES['foto_fachada']['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'No se pudo subir la foto de la fachada.';
    } else {
        if ($_FILES['foto_fachada']['size'] > 1048576) {
            $errores[] = 'La foto debe pesar menos de 1 MB.';
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
                $errores[] = 'La foto debe ser JPG, PNG o WEBP.';
            }
        }
    }

    if (empty($errores)) {
        $idGenerado = generarIdCancha($conn, $tablaCanchas, $valores['nombre_cancha']);
        $tipoCanchaFinal = ($valores['tipo_cancha'] === 'Otro') ? $valores['tipo_otro'] : $valores['tipo_cancha'];
        $diasDisponibles = implode(', ', $diasSeleccionados);
        $horarioApertura = $valores['hora_inicio'];
        $horarioClausura = $valores['hora_fin'];
        $horarios = $diasDisponibles . ' de ' . $horarioApertura . ' a ' . $horarioClausura;

        $directorioFotos = __DIR__ . '/uploads/canchas';
        if (!is_dir($directorioFotos)) {
            mkdir($directorioFotos, 0775, true);
        }

        $extension = $extensiones[$mime];
        $nombreArchivoFoto = $idGenerado . '_' . time() . '.' . $extension;
        $rutaDestino = $directorioFotos . '/' . $nombreArchivoFoto;
        $fotoRutaBD = 'uploads/canchas/' . $nombreArchivoFoto;

        if (!move_uploaded_file($_FILES['foto_fachada']['tmp_name'], $rutaDestino)) {
            $errores[] = 'No se pudo guardar la foto en la carpeta uploads/canchas.';
        } else {
            $fotoSubidaTemporal = $rutaDestino;
        }
    }

    if (empty($errores)) {
        $tablaSql = '`' . str_replace('`', '``', $tablaCanchas) . '`';
        $sqlInsert = "INSERT INTO $tablaSql
            (Id_Cancha, Nombre, Dueno, Id_Dueno, Pais, Estado, Ciudad, Direccion, Codigo_Postal, Foto, Correo_Electronico, Numero_Telefono, Tipo_Cancha, Deporte1, Deporte2, Deporte3, Estado_Cancha, Calificacion, Dias_Disponibles, Horario_Apertura, Horario_Clausura, Horarios, Comentarios, Costo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)";

        $stmtInsert = $conn->prepare($sqlInsert);

        if (!$stmtInsert) {
            if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                unlink($fotoSubidaTemporal);
            }
            $mensajeError = 'No se pudo preparar el registro de la cancha: ' . $conn->error;
        } else {
            $deporte2 = ($valores['deporte2'] !== '') ? $valores['deporte2'] : null;
            $deporte3 = ($valores['deporte3'] !== '') ? $valores['deporte3'] : null;

            $stmtInsert->bind_param(
                "sssssssssssssssssssssss",
                $idGenerado,
                $valores['nombre_cancha'],
                $valores['dueno'],
                $Id_Retador,
                $valores['pais'],
                $valores['estado'],
                $valores['ciudad'],
                $valores['direccion'],
                $valores['codigo_postal'],
                $fotoRutaBD,
                $valores['correo'],
                $valores['telefono'],
                $tipoCanchaFinal,
                $valores['deporte1'],
                $deporte2,
                $deporte3,
                $valores['estado_cancha'],
                $diasDisponibles,
                $horarioApertura,
                $horarioClausura,
                $horarios,
                $valores['comentarios'],
                $costo
            );

            if ($stmtInsert->execute()) {
                $mensajeExito = 'Cancha registrada correctamente.';
                $datosRegistrados = [
                    'Id_Cancha' => $idGenerado,
                    'Nombre' => $valores['nombre_cancha'],
                    'Dueno' => $valores['dueno'],
                    'Id_Dueno' => $Id_Retador,
                    'Pais' => $valores['pais'],
                    'Estado' => $valores['estado'],
                    'Ciudad' => $valores['ciudad'],
                    'Direccion' => $valores['direccion'],
                    'Codigo_Postal' => $valores['codigo_postal'],
                    'Correo_Electronico' => $valores['correo'],
                    'Numero_Telefono' => $valores['telefono'],
                    'Tipo_Cancha' => $tipoCanchaFinal,
                    'Deporte1' => $valores['deporte1'],
                    'Deporte2' => $deporte2,
                    'Deporte3' => $deporte3,
                    'Estado_Cancha' => $valores['estado_cancha'],
                    'Dias_Disponibles' => $diasDisponibles,
                    'Horario_Apertura' => $horarioApertura,
                    'Horario_Clausura' => $horarioClausura,
                    'Horarios' => $horarios,
                    'Comentarios' => $valores['comentarios'],
                    'Costo' => $costo,
                    'Foto' => $fotoRutaBD
                ];
                $valores = [
                    'nombre_cancha' => '',
                    'dueno' => $duenoDefault,
                    'pais' => obtenerValorSesion($usuarios, ['Pais', 'pais']),
                    'estado' => obtenerValorSesion($usuarios, ['Estado', 'estado']),
                    'ciudad' => '',
                    'direccion' => '',
                    'codigo_postal' => obtenerValorSesion($usuarios, ['CodigoPostal', 'Codigo_Postal', 'codigo_postal', 'codigoPostal']),
                    'correo' => '',
                    'telefono' => '',
                    'tipo_cancha' => '',
                    'tipo_otro' => '',
                    'deporte1' => '',
                    'deporte2' => '',
                    'deporte3' => '',
                    'estado_cancha' => 'Disponible',
                    'hora_inicio' => '',
                    'hora_fin' => '',
                    'comentarios' => '',
                    'tipo_costo' => 'gratuito',
                    'cantidad_costo' => ''
                ];
                $diasSeleccionados = [];
            } else {
                if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                    unlink($fotoSubidaTemporal);
                }
                $mensajeError = 'No se pudo registrar la cancha: ' . $stmtInsert->error;
            }

            $stmtInsert->close();
        }
    } else {
        $mensajeError = implode(' ', $errores);
    }
}

$nuevas_count = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registrar cancha - RETAME</title>
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

.accion-boceto:hover,
.accion-boceto.active{
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
    padding:7px 20px;
    box-shadow:
        0 3px 14px rgba(0,0,0,0.08),
        0 0 0 1px rgba(24,119,242,0.14),
        0 0 12px rgba(0,153,255,0.20);
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
    font-size:clamp(1.15rem,3vw,2rem);
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

body.topbar-compact .topbar-title h1{
    font-size:clamp(0.95rem,2.3vw,1.45rem);
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

.registro-wrapper{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:22px;
}

.registro-head,
.form-panel{
    width:100%;
    border-radius:30px;
    background:rgba(255,255,255,0.95);
    border:1px solid rgba(17,24,39,0.06);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    position:relative;
    overflow:hidden;
}

.registro-head{
    padding:clamp(22px,4vw,34px);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.registro-head::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 12% 18%,rgba(24,119,242,0.25),transparent 260px),
        radial-gradient(circle at 88% 82%,rgba(255,75,92,0.23),transparent 290px),
        linear-gradient(90deg,rgba(24,119,242,0.12),rgba(255,255,255,0.02) 48%,rgba(255,75,92,0.13));
}

.registro-head > *{
    position:relative;
    z-index:1;
}

.registro-chip{
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

.registro-head h2{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.65rem,4vw,2.45rem);
    margin-bottom:8px;
}

.registro-head p{
    max-width:720px;
    color:#4b5563;
    line-height:1.65;
    font-size:0.98rem;
}

.registro-head-img{
    width:150px;
    height:150px;
    border-radius:34px;
    background:
        radial-gradient(circle at 25% 20%,rgba(255,255,255,0.58),transparent 55px),
        linear-gradient(135deg,#1877f2,#0ea5e9 45%,#ff4b5c);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:70px;
    flex:0 0 auto;
    box-shadow:0 18px 34px rgba(0,0,0,0.16);
}

.form-panel{
    padding:clamp(18px,4vw,30px);
}

.alerta{
    border-radius:22px;
    padding:15px 17px;
    margin-bottom:18px;
    font-weight:800;
    line-height:1.55;
    display:flex;
    gap:10px;
    align-items:flex-start;
}

.alerta.error{
    background:#fff1f2;
    color:#b91c1c;
    border:2px solid rgba(255,75,92,0.42);
}

.alerta.exito{
    background:#ecfdf5;
    color:#047857;
    border:2px solid rgba(16,185,129,0.40);
}

.estructura-box{
    background:#fff7ed;
    color:#9a3412;
    border:2px solid rgba(249,115,22,0.40);
    border-radius:22px;
    padding:15px 17px;
    margin-bottom:18px;
    line-height:1.55;
    font-weight:800;
}

.form-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    margin-bottom:18px;
}

.form-title h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.45rem);
}

.form-title span{
    color:#6b7280;
    font-size:13px;
    font-weight:800;
}

.registro-form{
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

.check-grid,
.radio-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
}

.radio-grid{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.check-card,
.radio-card{
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

.check-card input,
.radio-card input{
    width:18px;
    height:18px;
    min-height:18px;
    box-shadow:none;
}

.foto-box{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:16px;
    align-items:stretch;
}

.preview-foto{
    min-height:175px;
    border-radius:22px;
    border:2px dashed rgba(0,153,255,0.45);
    background:
        radial-gradient(circle at 30% 20%,rgba(24,119,242,0.20),transparent 120px),
        linear-gradient(135deg,#eaf4ff,#fff2f4);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#1877f2;
    font-size:54px;
    font-weight:900;
    overflow:hidden;
}

.preview-foto img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.file-area{
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:10px;
}

.file-label{
    min-height:54px;
    width:max-content;
    max-width:100%;
    border-radius:18px;
    padding:14px 18px;
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    color:#ffffff;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    cursor:pointer;
    box-shadow:0 12px 24px rgba(0,0,0,0.14);
}

.file-label input{
    display:none;
}

.btn-row{
    grid-column:1 / -1;
    display:flex;
    justify-content:flex-end;
    gap:12px;
    flex-wrap:wrap;
    padding-top:8px;
}

.btn-retame{
    min-height:52px;
    border-radius:18px;
    padding:13px 20px;
    border:none;
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
    cursor:pointer;
    font-family:'Poppins',sans-serif;
}

.btn-retame:hover{
    transform:translateY(-3px);
    box-shadow:0 18px 32px rgba(0,0,0,0.18);
}

.btn-retame.azul{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    border:2px solid rgba(0,153,255,0.45);
}

.btn-retame.rojo{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    border:2px solid rgba(255,75,92,0.52);
}

.btn-retame.blanco{
    color:#1877f2;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.45);
}

.resumen-registro{
    width:100%;
    border-radius:26px;
    background:linear-gradient(135deg,rgba(236,253,245,0.98),rgba(255,255,255,0.98));
    border:2px solid rgba(16,185,129,0.42);
    box-shadow:
        0 14px 30px rgba(0,0,0,0.10),
        0 0 0 2px rgba(0,153,255,0.12),
        0 0 18px rgba(16,185,129,0.12);
    padding:18px;
    margin-bottom:18px;
}

.resumen-registro h4{
    font-family:'Orbitron',sans-serif;
    color:#047857;
    font-size:1.05rem;
    margin-bottom:12px;
}

.resumen-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    margin-bottom:16px;
}

.resumen-item{
    border-radius:16px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.22);
    padding:11px 13px;
    box-shadow:0 7px 14px rgba(0,0,0,0.05);
    overflow:hidden;
}

.resumen-item.full{
    grid-column:1 / -1;
}

.resumen-item strong{
    display:block;
    color:#1877f2;
    font-size:12px;
    margin-bottom:4px;
}

.resumen-item span{
    display:block;
    color:#374151;
    font-size:13px;
    font-weight:800;
    word-break:break-word;
}

.resumen-acciones{
    display:flex;
    justify-content:flex-end;
    gap:12px;
    flex-wrap:wrap;
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
body.dark-mode .registro-head,
body.dark-mode .form-panel,
body.dark-mode .registro-chip,
body.dark-mode .form-group input,
body.dark-mode .form-group select,
body.dark-mode .form-group textarea,
body.dark-mode .check-card,
body.dark-mode .radio-card,
body.dark-mode .modo-oscuro-panel,
body.dark-mode .info-boceto a,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto,
body.dark-mode .topbar-user,
body.dark-mode .menu-toggle,
body.dark-mode .resumen-registro,
body.dark-mode .resumen-item{
    background:#111827;
    color:#e5e7eb;
}

body.dark-mode .sidebar{
    border-right-color:rgba(0,153,255,0.95);
}

body.dark-mode .topbar{
    border-bottom-color:rgba(0,153,255,0.95);
}

body.dark-mode .bottom-nav{
    border-top-color:rgba(0,153,255,0.95);
}

body.dark-mode .registro-head,
body.dark-mode .form-panel,
body.dark-mode .registro-chip,
body.dark-mode .check-card,
body.dark-mode .radio-card,
body.dark-mode .modo-oscuro-panel,
body.dark-mode .info-boceto a,
body.dark-mode .accion-boceto,
body.dark-mode .cerrar-boceto{
    border-color:rgba(255,75,92,0.72);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 18px rgba(0,153,255,0.16);
}

body.dark-mode .registro-head::before{
    background:
        radial-gradient(circle at 12% 18%,rgba(0,153,255,0.26),transparent 260px),
        radial-gradient(circle at 88% 82%,rgba(255,75,92,0.22),transparent 290px),
        linear-gradient(90deg,rgba(0,153,255,0.14),rgba(17,24,39,0.10) 48%,rgba(255,75,92,0.14));
}

body.dark-mode .logo-text h2,
body.dark-mode .topbar-title h1,
body.dark-mode .registro-head h2,
body.dark-mode .form-title h3,
body.dark-mode .registro-chip,
body.dark-mode .resumen-registro h4,
body.dark-mode .resumen-item strong{
    color:#4db8ff;
}

body.dark-mode .logo-text p,
body.dark-mode .registro-head p,
body.dark-mode .form-title span,
body.dark-mode .form-group label,
body.dark-mode .group-label,
body.dark-mode .form-note,
body.dark-mode .modo-oscuro-texto,
body.dark-mode .resumen-item span{
    color:#e5e7eb;
}

body.dark-mode .form-group input,
body.dark-mode .form-group select,
body.dark-mode .form-group textarea{
    border-color:rgba(0,153,255,0.42);
}

body.dark-mode .input-readonly{
    background:#0b1220 !important;
    color:#d1d5db !important;
}

body.dark-mode .preview-foto{
    background:
        radial-gradient(circle at 30% 20%,rgba(0,153,255,0.24),transparent 130px),
        linear-gradient(135deg,#0b1220,#160a12);
    color:#4db8ff;
}

body.dark-mode .bottom-nav a img,
body.dark-mode .accion-boceto img,
body.dark-mode .cerrar-boceto img{
    filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05);
}

body.dark-mode .switch-modo{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
}

body.dark-mode .switch-modo span{
    transform:translateX(24px);
}

.switch-modo:disabled{
    opacity:0.65;
    cursor:not-allowed;
}

@media screen and (max-width:1050px){
    .registro-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .registro-head-img{
        width:100%;
        height:120px;
    }

    .check-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
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

    body.topbar-compact .topbar,
    body.sidebar-hidden.topbar-compact .topbar{
        height:56px;
        padding:7px 12px;
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

    .registro-form{
        grid-template-columns:1fr;
    }

    .foto-box{
        grid-template-columns:1fr;
    }

    .resumen-grid{
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

    .check-grid,
    .radio-grid{
        grid-template-columns:1fr;
    }

    .btn-row,
    .resumen-acciones{
        flex-direction:column;
    }

    .btn-retame{
        width:100%;
    }
}
</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

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
            <a href="Canchas.php" class="accion-boceto active">
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
        <h1>Registrar cancha</h1>
    </div>
    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo limpiarTexto($Nombre); ?></span>
    </div>
</header>

<main class="main-content">
    <section class="registro-wrapper">
        <div class="registro-head">
            <div>
                <span class="registro-chip">🏟️ Registro de nueva cancha</span>
                <h2>Agregar cancha</h2>
                <p>Registra la cancha con su ubicación, contacto, deportes permitidos, días disponibles, horario de apertura, horario de clausura, costo y una foto de la fachada menor a 1 MB.</p>
            </div>
            <div class="registro-head-img">🏟️</div>
        </div>

        <div class="form-panel">
            <div class="form-title">
                <h3>Datos de la cancha</h3>
                <span>Todos los campos son obligatorios excepto deporte 2 y deporte 3</span>
            </div>

            <?php if (!empty($estructuraAvisos)): ?>
                <div class="estructura-box">
                    <strong>⚠️ Ajuste necesario en base de datos:</strong><br>
                    <?php echo limpiarTexto(implode(' ', $estructuraAvisos)); ?>
                </div>
            <?php endif; ?>

            <?php if ($mensajeError !== ''): ?>
                <div class="alerta error">
                    <span>⚠️</span>
                    <div><?php echo limpiarTexto($mensajeError); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($mensajeExito !== ''): ?>
                <div class="alerta exito">
                    <span>✅</span>
                    <div>
                        <?php echo limpiarTexto($mensajeExito); ?><br>
                        ID generado: <strong><?php echo limpiarTexto($idGenerado); ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (is_array($datosRegistrados)): ?>
                <div class="resumen-registro">
                    <h4>Datos registrados</h4>
                    <div class="resumen-grid">
                        <div class="resumen-item"><strong>ID cancha</strong><span><?php echo limpiarTexto($datosRegistrados['Id_Cancha']); ?></span></div>
                        <div class="resumen-item"><strong>Nombre</strong><span><?php echo limpiarTexto($datosRegistrados['Nombre']); ?></span></div>
                        <div class="resumen-item"><strong>Dueño</strong><span><?php echo limpiarTexto($datosRegistrados['Dueno']); ?></span></div>
                        <div class="resumen-item"><strong>ID dueño</strong><span><?php echo limpiarTexto($datosRegistrados['Id_Dueno']); ?></span></div>
                        <div class="resumen-item"><strong>Ubicación</strong><span><?php echo limpiarTexto($datosRegistrados['Ciudad'] . ', ' . $datosRegistrados['Estado'] . ', ' . $datosRegistrados['Pais']); ?></span></div>
                        <div class="resumen-item"><strong>Código postal</strong><span><?php echo limpiarTexto($datosRegistrados['Codigo_Postal']); ?></span></div>
                        <div class="resumen-item full"><strong>Dirección</strong><span><?php echo limpiarTexto($datosRegistrados['Direccion']); ?></span></div>
                        <div class="resumen-item"><strong>Correo</strong><span><?php echo limpiarTexto($datosRegistrados['Correo_Electronico']); ?></span></div>
                        <div class="resumen-item"><strong>Teléfono</strong><span><?php echo limpiarTexto($datosRegistrados['Numero_Telefono']); ?></span></div>
                        <div class="resumen-item"><strong>Tipo de cancha</strong><span><?php echo limpiarTexto($datosRegistrados['Tipo_Cancha']); ?></span></div>
                        <div class="resumen-item"><strong>Estado</strong><span><?php echo limpiarTexto($datosRegistrados['Estado_Cancha']); ?></span></div>
                        <div class="resumen-item"><strong>Deporte 1</strong><span><?php echo limpiarTexto($datosRegistrados['Deporte1']); ?></span></div>
                        <div class="resumen-item"><strong>Deporte 2</strong><span><?php echo limpiarTexto($datosRegistrados['Deporte2'] ?: 'No registrado'); ?></span></div>
                        <div class="resumen-item"><strong>Deporte 3</strong><span><?php echo limpiarTexto($datosRegistrados['Deporte3'] ?: 'No registrado'); ?></span></div>
                        <div class="resumen-item"><strong>Días disponibles</strong><span><?php echo limpiarTexto($datosRegistrados['Dias_Disponibles']); ?></span></div>
                        <div class="resumen-item"><strong>Horario apertura</strong><span><?php echo limpiarTexto($datosRegistrados['Horario_Apertura']); ?></span></div>
                        <div class="resumen-item"><strong>Horario clausura</strong><span><?php echo limpiarTexto($datosRegistrados['Horario_Clausura']); ?></span></div>
                        <div class="resumen-item"><strong>Costo</strong><span><?php echo limpiarTexto($datosRegistrados['Costo']); ?></span></div>
                        <div class="resumen-item full"><strong>Comentarios</strong><span><?php echo limpiarTexto($datosRegistrados['Comentarios']); ?></span></div>
                    </div>
                    <div class="resumen-acciones">
                        <a href="Miscanchasregistradas.php" class="btn-retame azul">Continuar →</a>
                    </div>
                </div>
            <?php endif; ?>

            <form class="registro-form" method="POST" enctype="multipart/form-data" id="formRegistrarCancha">
                <input type="hidden" name="accion" value="registrar_cancha">

                <div class="form-group">
                    <label for="nombre_cancha">Nombre de la cancha</label>
                    <input type="text" id="nombre_cancha" name="nombre_cancha" maxlength="100" required value="<?php echo limpiarTexto($valores['nombre_cancha']); ?>" placeholder="Ej. Cancha Los Pinos">
                    <span class="form-note">El ID se generará automáticamente con el nombre y 4 números aleatorios.</span>
                </div>

                <div class="form-group">
                    <label for="dueno">Dueño</label>
                    <input type="text" id="dueno" name="dueno" maxlength="100" required value="<?php echo limpiarTexto($valores['dueno']); ?>" placeholder="Nombre y apellido del dueño">
                    <span class="form-note">Id_Dueño se tomará automáticamente del usuario que inició sesión: <?php echo limpiarTexto($Id_Retador); ?></span>
                </div>

                <div class="form-group">
                    <label for="pais">País</label>
                    <input type="text" id="pais" name="pais" maxlength="80" required value="<?php echo limpiarTexto($valores['pais']); ?>" placeholder="México">
                </div>

                <div class="form-group">
                    <label for="estado">Estado</label>
                    <input type="text" id="estado" name="estado" maxlength="80" required value="<?php echo limpiarTexto($valores['estado']); ?>" placeholder="Chiapas">
                </div>

                <div class="form-group">
                    <label for="ciudad">Ciudad</label>
                    <input type="text" id="ciudad" name="ciudad" maxlength="80" required value="<?php echo limpiarTexto($valores['ciudad']); ?>" placeholder="Comitán">
                </div>

                <div class="form-group">
                    <label for="codigo_postal">Código postal</label>
                    <input type="text" id="codigo_postal" name="codigo_postal" maxlength="10" required value="<?php echo limpiarTexto($valores['codigo_postal']); ?>" placeholder="30000">
                </div>

                <div class="form-group full">
                    <label for="direccion">Dirección</label>
                    <input type="text" id="direccion" name="direccion" maxlength="200" required value="<?php echo limpiarTexto($valores['direccion']); ?>" placeholder="Calle, número, colonia o referencia">
                </div>

                <div class="form-group">
                    <label for="correo">Correo electrónico</label>
                    <input type="email" id="correo" name="correo" maxlength="120" required value="<?php echo limpiarTexto($valores['correo']); ?>" placeholder="correo@ejemplo.com">
                </div>

                <div class="form-group">
                    <label for="telefono">Número de teléfono</label>
                    <input type="tel" id="telefono" name="telefono" maxlength="20" required value="<?php echo limpiarTexto($valores['telefono']); ?>" placeholder="9630000000">
                </div>

                <div class="form-group">
                    <label for="tipo_cancha">Tipo de cancha</label>
                    <select id="tipo_cancha" name="tipo_cancha" required>
                        <option value="">Selecciona una opción</option>
                        <?php foreach (['Pasto sintético', 'Pasto natural', 'Concreto', 'Arcilla', 'Otro'] as $tipo): ?>
                            <option value="<?php echo limpiarTexto($tipo); ?>" <?php echo ($valores['tipo_cancha'] === $tipo) ? 'selected' : ''; ?>><?php echo limpiarTexto($tipo); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="grupoTipoOtro">
                    <label for="tipo_otro">Otro tipo de cancha</label>
                    <input type="text" id="tipo_otro" name="tipo_otro" maxlength="80" value="<?php echo limpiarTexto($valores['tipo_otro']); ?>" placeholder="Escribe el tipo de cancha">
                </div>

                <div class="form-group">
                    <label for="deporte1">Deporte 1</label>
                    <select id="deporte1" name="deporte1" required>
                        <option value="">Selecciona un deporte</option>
                        <?php foreach ($deportes as $dep): ?>
                            <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ($valores['deporte1'] === $dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="deporte2">Deporte 2</label>
                    <select id="deporte2" name="deporte2">
                        <option value="">Opcional</option>
                        <?php foreach ($deportes as $dep): ?>
                            <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ($valores['deporte2'] === $dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="deporte3">Deporte 3</label>
                    <select id="deporte3" name="deporte3">
                        <option value="">Opcional</option>
                        <?php foreach ($deportes as $dep): ?>
                            <option value="<?php echo limpiarTexto($dep['Id_Deporte']); ?>" <?php echo ($valores['deporte3'] === $dep['Id_Deporte']) ? 'selected' : ''; ?>><?php echo limpiarTexto($dep['Id_Deporte'] . ' - ' . $dep['Nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="estado_cancha">Estado de la cancha</label>
                    <select id="estado_cancha" name="estado_cancha" required>
                        <?php foreach (['Disponible', 'Clausurado', 'Suspendido'] as $estadoCancha): ?>
                            <option value="<?php echo limpiarTexto($estadoCancha); ?>" <?php echo ($valores['estado_cancha'] === $estadoCancha) ? 'selected' : ''; ?>><?php echo limpiarTexto($estadoCancha); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full">
                    <span class="group-label">Días disponibles</span>
                    <div class="check-grid">
                        <?php foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $dia): ?>
                            <label class="check-card">
                                <input type="checkbox" name="horario_dias[]" value="<?php echo limpiarTexto($dia); ?>" <?php echo in_array($dia, $diasSeleccionados, true) ? 'checked' : ''; ?>>
                                <span><?php echo limpiarTexto($dia); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="hora_inicio">Horario de apertura</label>
                    <input type="time" id="hora_inicio" name="hora_inicio" required value="<?php echo limpiarTexto($valores['hora_inicio']); ?>">
                </div>

                <div class="form-group">
                    <label for="hora_fin">Horario de clausura</label>
                    <input type="time" id="hora_fin" name="hora_fin" required value="<?php echo limpiarTexto($valores['hora_fin']); ?>">
                </div>

                <div class="form-group full">
                    <span class="group-label">Foto de fachada</span>
                    <div class="foto-box">
                        <div class="preview-foto" id="previewFoto">📷</div>
                        <div class="file-area">
                            <label class="file-label">
                                <span>Adjuntar foto</span>
                                <input type="file" id="foto_fachada" name="foto_fachada" accept="image/jpeg,image/png,image/webp" required>
                            </label>
                            <span class="form-note" id="fotoTexto">JPG, PNG o WEBP. Peso máximo: 1 MB. Debe apreciarse la fachada.</span>
                        </div>
                    </div>
                </div>

                <div class="form-group full">
                    <span class="group-label">Costo</span>
                    <div class="radio-grid">
                        <label class="radio-card">
                            <input type="radio" name="tipo_costo" value="gratuito" <?php echo ($valores['tipo_costo'] === 'gratuito') ? 'checked' : ''; ?>>
                            <span>Gratuito</span>
                        </label>
                        <label class="radio-card">
                            <input type="radio" name="tipo_costo" value="pagado" <?php echo ($valores['tipo_costo'] === 'pagado') ? 'checked' : ''; ?>>
                            <span>Pagado</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" id="grupoCostoCantidad">
                    <label for="cantidad_costo">Costo de la cancha</label>
                    <input type="text" id="cantidad_costo" name="cantidad_costo" maxlength="120" value="<?php echo limpiarTexto($valores['cantidad_costo']); ?>" placeholder="Ej. 250, $250 por hora, Doscientos cincuenta o a tratar">
                </div>

                <div class="form-group full">
                    <label for="comentarios">Comentarios</label>
                    <textarea id="comentarios" name="comentarios" required placeholder="Describe indicaciones, referencias, reglas o información importante de la cancha"><?php echo limpiarTexto($valores['comentarios']); ?></textarea>
                </div>

                <div class="btn-row">
                    <a href="Canchas.php" class="btn-retame blanco">← Volver</a>
                    <button type="submit" class="btn-retame rojo">🏟️ Registrar cancha</button>
                </div>
            </form>
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
const menuToggle = document.getElementById('menuToggle');
const mobileOverlay = document.getElementById('mobileOverlay');
const darkModeToggle = document.getElementById('darkModeToggle');
const modoOscuroInicial = <?php echo $modoOscuroActivo ? 'true' : 'false'; ?>;
const tipoCancha = document.getElementById('tipo_cancha');
const grupoTipoOtro = document.getElementById('grupoTipoOtro');
const tipoOtro = document.getElementById('tipo_otro');
const grupoCostoCantidad = document.getElementById('grupoCostoCantidad');
const cantidadCosto = document.getElementById('cantidad_costo');
const fotoInput = document.getElementById('foto_fachada');
const previewFoto = document.getElementById('previewFoto');
const fotoTexto = document.getElementById('fotoTexto');
const formRegistrarCancha = document.getElementById('formRegistrarCancha');

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

function controlarTipoOtro(){
    const esOtro = tipoCancha.value === 'Otro';
    grupoTipoOtro.style.display = esOtro ? 'flex' : 'none';
    tipoOtro.required = esOtro;
    if(!esOtro){
        tipoOtro.value = '';
    }
}

function controlarCosto(){
    const seleccionado = document.querySelector('input[name="tipo_costo"]:checked');
    const esPagado = seleccionado && seleccionado.value === 'pagado';
    grupoCostoCantidad.style.display = esPagado ? 'flex' : 'none';
    cantidadCosto.required = esPagado;
    if(!esPagado){
        cantidadCosto.value = '';
    }
}

function revisarFoto(){
    const archivo = fotoInput.files[0];
    if(!archivo){
        previewFoto.innerHTML = '📷';
        fotoTexto.textContent = 'JPG, PNG o WEBP. Peso máximo: 1 MB. Debe apreciarse la fachada.';
        return;
    }

    if(archivo.size > 1048576){
        alert('La foto debe pesar menos de 1 MB.');
        fotoInput.value = '';
        previewFoto.innerHTML = '📷';
        fotoTexto.textContent = 'JPG, PNG o WEBP. Peso máximo: 1 MB. Debe apreciarse la fachada.';
        return;
    }

    fotoTexto.textContent = archivo.name;
    const reader = new FileReader();
    reader.onload = function(e){
        previewFoto.innerHTML = '<img src="' + e.target.result + '" alt="Vista previa">';
    };
    reader.readAsDataURL(archivo);
}

document.addEventListener('DOMContentLoaded', function(){
    aplicarModoOscuroVisual(modoOscuroInicial);
    controlarTipoOtro();
    controlarCosto();

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

tipoCancha.addEventListener('change', controlarTipoOtro);
document.querySelectorAll('input[name="tipo_costo"]').forEach(radio => radio.addEventListener('change', controlarCosto));
fotoInput.addEventListener('change', revisarFoto);

formRegistrarCancha.addEventListener('submit', function(e){
    const dias = document.querySelectorAll('input[name="horario_dias[]"]:checked');
    if(dias.length === 0){
        e.preventDefault();
        alert('Selecciona al menos un día disponible.');
        return;
    }

    const d1 = document.getElementById('deporte1').value;
    const d2 = document.getElementById('deporte2').value;
    const d3 = document.getElementById('deporte3').value;
    const seleccionados = [d1, d2, d3].filter(v => v !== '');
    const unicos = new Set(seleccionados);

    if(seleccionados.length !== unicos.size){
        e.preventDefault();
        alert('No repitas el mismo deporte.');
    }
});

let ultimoScrollYBarras = window.pageYOffset || document.documentElement.scrollTop;
let scrollBarrasPendiente = false;

function controlarBarrasPorScroll(){
    const scrollActual = window.pageYOffset || document.documentElement.scrollTop;
    const bajando = scrollActual > ultimoScrollYBarras;
    const diferencia = Math.abs(scrollActual - ultimoScrollYBarras);

    if(scrollActual > 38){
        document.body.classList.add('topbar-compact');
    }else{
        document.body.classList.remove('topbar-compact');
    }

    if(!document.body.classList.contains('sidebar-open')){
        if(scrollActual > 90 && bajando && diferencia > 2){
            document.body.classList.add('bottom-nav-hidden');
        }else if((!bajando && diferencia > 2) || scrollActual <= 90){
            document.body.classList.remove('bottom-nav-hidden');
        }
    }

    ultimoScrollYBarras = Math.max(scrollActual, 0);
    scrollBarrasPendiente = false;
}

window.addEventListener('scroll', function(){
    if(!scrollBarrasPendiente){
        window.requestAnimationFrame(controlarBarrasPorScroll);
        scrollBarrasPendiente = true;
    }
}, {passive:true});

</script>

</body>
</html>
