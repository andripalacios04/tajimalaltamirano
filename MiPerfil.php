<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once 'conexion.php';

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

$Id_Retador = obtenerValorSesion($usuarios, ['Id_Retador', 'id_retador']);

if ($Id_Retador === '') {
    header("Location: login.php");
    exit();
}

$NombreSesion = obtenerValorSesion($usuarios, ['Nombre', 'nombre']);
if ($NombreSesion === '') {
    $NombreSesion = 'Usuario';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';

    $sqlModoUpdate = "UPDATE retador SET ModoPerfil = ? WHERE Id_Retador = ? LIMIT 1";
    $stmtModoUpdate = $conn->prepare($sqlModoUpdate);

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

$mensajeExito = '';
$mensajeError = '';
$avisoFotoPerfil = '';

function columnaExisteRetador($conn, $columna) {
    $columnaSafe = $conn->real_escape_string($columna);
    $res = $conn->query("SHOW COLUMNS FROM retador LIKE '$columnaSafe'");
    return ($res && $res->num_rows > 0);
}

function enlazarParametros($stmt, $tipos, &$params) {
    $referencias = [];
    $referencias[] = $tipos;
    for ($i = 0; $i < count($params); $i++) {
        $referencias[] = &$params[$i];
    }
    return call_user_func_array([$stmt, 'bind_param'], $referencias);
}

$fotoPerfilDisponible = columnaExisteRetador($conn, 'FotoPerfil');

if (!$fotoPerfilDisponible) {
    $avisoFotoPerfil = 'Para activar la foto de perfil, primero ejecuta el archivo SQL que agrega el campo FotoPerfil en la tabla retador.';
}

$datosPerfil = [
    'Id_Retador' => $Id_Retador,
    'Nombre' => '',
    'Apellido' => '',
    'Edad' => '',
    'Estado' => '',
    'Pais' => '',
    'Telefono' => '',
    'Correo' => '',
    'FotoPerfil' => '',
    'ModoPerfil' => ''
];

function cargarPerfil($conn, $Id_Retador, &$datosPerfil, $fotoPerfilDisponible) {
    $campos = "Id_Retador, Nombre, Apellido, Edad, Estado, Pais, Telefono, Correo, ModoPerfil";
    if ($fotoPerfilDisponible) {
        $campos .= ", FotoPerfil";
    }

    $sql = "SELECT $campos FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $Id_Retador);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($fila = $res->fetch_assoc()) {
        foreach ($datosPerfil as $clave => $valor) {
            if (array_key_exists($clave, $fila)) {
                $datosPerfil[$clave] = $fila[$clave] !== null ? (string)$fila[$clave] : '';
            }
        }
        $stmt->close();
        return true;
    }

    $stmt->close();
    return false;
}

if (!cargarPerfil($conn, $Id_Retador, $datosPerfil, $fotoPerfilDisponible)) {
    $mensajeError = 'No se encontró la información del usuario en la tabla retador.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_perfil') {
    $nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
    $apellido = isset($_POST['apellido']) ? trim((string)$_POST['apellido']) : '';
    $edad = isset($_POST['edad']) ? trim((string)$_POST['edad']) : '';
    $estado = isset($_POST['estado']) ? trim((string)$_POST['estado']) : '';
    $pais = isset($_POST['pais']) ? trim((string)$_POST['pais']) : '';
    $telefono = isset($_POST['telefono']) ? trim((string)$_POST['telefono']) : '';
    $correo = isset($_POST['correo']) ? trim((string)$_POST['correo']) : '';
    $contrasenaNueva = isset($_POST['contrasena_nueva']) ? trim((string)$_POST['contrasena_nueva']) : '';
    $contrasenaConfirmar = isset($_POST['contrasena_confirmar']) ? trim((string)$_POST['contrasena_confirmar']) : '';

    $errores = [];
    $fotoPerfilNueva = $datosPerfil['FotoPerfil'];
    $fotoSubidaTemporal = '';
    $fotoAnterior = $datosPerfil['FotoPerfil'];

    if ($nombre === '') $errores[] = 'Escribe tu nombre.';
    if ($apellido === '') $errores[] = 'Escribe tu apellido.';
    if ($edad === '') $errores[] = 'Escribe tu edad.';
    if ($estado === '') $errores[] = 'Escribe tu estado.';
    if ($pais === '') $errores[] = 'Escribe tu país.';

    if ($nombre !== '' && mb_strlen($nombre, 'UTF-8') > 255) $errores[] = 'El nombre no debe pasar de 255 caracteres.';
    if ($apellido !== '' && mb_strlen($apellido, 'UTF-8') > 255) $errores[] = 'El apellido no debe pasar de 255 caracteres.';
    if ($estado !== '' && mb_strlen($estado, 'UTF-8') > 50) $errores[] = 'El estado no debe pasar de 50 caracteres.';
    if ($pais !== '' && mb_strlen($pais, 'UTF-8') > 255) $errores[] = 'El país no debe pasar de 255 caracteres.';

    if ($edad !== '' && (!ctype_digit($edad) || (int)$edad < 1 || (int)$edad > 120)) {
        $errores[] = 'La edad debe ser un número válido.';
    }

    if ($telefono !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $telefono)) {
        $errores[] = 'El teléfono solo debe llevar números, espacios o signos básicos.';
    }

    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico no tiene un formato válido.';
    }

    if ($correo !== '' && mb_strlen($correo, 'UTF-8') > 50) {
        $errores[] = 'El correo no debe pasar de 50 caracteres.';
    }

    if ($contrasenaNueva !== '' || $contrasenaConfirmar !== '') {
        if ($contrasenaNueva === '') {
            $errores[] = 'Escribe la nueva contraseña.';
        }
        if ($contrasenaConfirmar === '') {
            $errores[] = 'Confirma la nueva contraseña.';
        }
        if ($contrasenaNueva !== '' && mb_strlen($contrasenaNueva, 'UTF-8') < 4) {
            $errores[] = 'La contraseña debe tener al menos 4 caracteres.';
        }
        if ($contrasenaNueva !== $contrasenaConfirmar) {
            $errores[] = 'Las contraseñas no coinciden.';
        }
        if (mb_strlen($contrasenaNueva, 'UTF-8') > 255) {
            $errores[] = 'La contraseña no debe pasar de 255 caracteres.';
        }
    }

    $hayFotoPerfil = isset($_FILES['foto_perfil']) && is_array($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hayFotoPerfil) {
        if (!$fotoPerfilDisponible) {
            $errores[] = 'No se puede guardar la foto porque falta el campo FotoPerfil en la tabla retador.';
        } elseif ($_FILES['foto_perfil']['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir la foto de perfil.';
        } elseif ($_FILES['foto_perfil']['size'] > 1048576) {
            $errores[] = 'La foto de perfil debe pesar menos de 1 MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['foto_perfil']['tmp_name']);
            finfo_close($finfo);

            $extensiones = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($extensiones[$mime])) {
                $errores[] = 'La foto de perfil debe ser JPG, PNG o WEBP.';
            } else {
                $directorioFotos = __DIR__ . '/uploads/perfiles';
                if (!is_dir($directorioFotos)) {
                    mkdir($directorioFotos, 0775, true);
                }

                $idArchivo = preg_replace('/[^a-zA-Z0-9]/', '', $Id_Retador);
                if ($idArchivo === '') {
                    $idArchivo = 'perfil';
                }

                $nombreArchivoFoto = 'perfil_' . $idArchivo . '_' . time() . '.' . $extensiones[$mime];
                $rutaDestino = $directorioFotos . '/' . $nombreArchivoFoto;

                if (!move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $rutaDestino)) {
                    $errores[] = 'No se pudo guardar la foto en la carpeta uploads/perfiles.';
                } else {
                    $fotoSubidaTemporal = $rutaDestino;
                    $fotoPerfilNueva = 'uploads/perfiles/' . $nombreArchivoFoto;
                }
            }
        }
    }

    if (empty($errores)) {
        $camposUpdate = ['Nombre = ?', 'Apellido = ?', 'Edad = ?', 'Estado = ?', 'Pais = ?', 'Telefono = ?', 'Correo = ?'];
        $paramsUpdate = [$nombre, $apellido, $edad, $estado, $pais, $telefono, $correo];
        $tiposUpdate = 'sssssss';

        if ($fotoPerfilDisponible) {
            $camposUpdate[] = 'FotoPerfil = ?';
            $paramsUpdate[] = $fotoPerfilNueva;
            $tiposUpdate .= 's';
        }

        if ($contrasenaNueva !== '') {
            $camposUpdate[] = 'Contrasena = ?';
            $paramsUpdate[] = $contrasenaNueva;
            $tiposUpdate .= 's';
        }

        $paramsUpdate[] = $Id_Retador;
        $tiposUpdate .= 's';

        $sqlUpdate = 'UPDATE retador SET ' . implode(', ', $camposUpdate) . ' WHERE Id_Retador = ? LIMIT 1';
        $stmtUpdate = $conn->prepare($sqlUpdate);

        if (!$stmtUpdate) {
            if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                unlink($fotoSubidaTemporal);
            }
            $mensajeError = 'No se pudo preparar la actualización del perfil.';
        } else {
            enlazarParametros($stmtUpdate, $tiposUpdate, $paramsUpdate);
            if ($stmtUpdate->execute()) {
                if ($fotoSubidaTemporal !== '' && $fotoAnterior !== '' && $fotoAnterior !== $fotoPerfilNueva) {
                    $rutaAnterior = __DIR__ . '/' . ltrim($fotoAnterior, '/');
                    if (is_file($rutaAnterior) && strpos(realpath($rutaAnterior), realpath(__DIR__ . '/uploads/perfiles')) === 0) {
                        @unlink($rutaAnterior);
                    }
                }

                $_SESSION['usuario_data']['Nombre'] = $nombre;
                $_SESSION['usuario_data']['nombre'] = $nombre;
                $_SESSION['usuario_data']['Apellido'] = $apellido;
                $_SESSION['usuario_data']['Edad'] = $edad;
                $_SESSION['usuario_data']['Estado'] = $estado;
                $_SESSION['usuario_data']['Pais'] = $pais;
                $_SESSION['usuario_data']['Telefono'] = $telefono;
                $_SESSION['usuario_data']['Correo'] = $correo;
                if ($fotoPerfilDisponible) {
                    $_SESSION['usuario_data']['FotoPerfil'] = $fotoPerfilNueva;
                }
                $NombreSesion = $nombre;
                $mensajeExito = 'Tu perfil se actualizó correctamente.';
                cargarPerfil($conn, $Id_Retador, $datosPerfil, $fotoPerfilDisponible);
            } else {
                if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
                    unlink($fotoSubidaTemporal);
                }
                $mensajeError = 'No se pudo actualizar el perfil.';
            }
            $stmtUpdate->close();
        }
    } else {
        if ($fotoSubidaTemporal !== '' && file_exists($fotoSubidaTemporal)) {
            unlink($fotoSubidaTemporal);
        }
        $mensajeError = implode(' ', $errores);
        $datosPerfil['Nombre'] = $nombre;
        $datosPerfil['Apellido'] = $apellido;
        $datosPerfil['Edad'] = $edad;
        $datosPerfil['Estado'] = $estado;
        $datosPerfil['Pais'] = $pais;
        $datosPerfil['Telefono'] = $telefono;
        $datosPerfil['Correo'] = $correo;
        $datosPerfil['FotoPerfil'] = $fotoPerfilNueva;
    }
}

$modoPerfilNormalizado = mb_strtolower(trim((string)$datosPerfil['ModoPerfil']), 'UTF-8');
$modoOscuroActivo = ($modoPerfilNormalizado === 'modo oscuro' || $modoPerfilNormalizado === 'moso oscuro');

$nuevas_count = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mi perfil - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
:root{--azul:#1877f2;--azul2:#0ea5e9;--cyan:#8fefff;--rojo:#ff4b5c;--rojo2:#ff2f45;--texto:#111827;--gris:#6b7280;--fondo:#f0f2f5;--blanco:#ffffff;--sidebar:280px;--azul-neon-fuerte:rgba(0,153,255,0.95)}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;min-height:100%}
body{min-height:100dvh;font-family:'Poppins',sans-serif;color:var(--texto);background:var(--fondo);overflow-x:hidden;padding-bottom:104px}
.bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,0.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,0.20),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,0.18),transparent 360px),linear-gradient(90deg,rgba(24,119,242,0.14) 0%,rgba(255,255,255,0.02) 48%,rgba(255,75,92,0.15) 100%),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
.sidebar{width:var(--sidebar);height:100dvh;position:fixed;left:0;top:0;z-index:1000;padding:22px 14px 112px;background:rgba(255,255,255,0.98);backdrop-filter:blur(14px);border-right:3px solid var(--azul-neon-fuerte);box-shadow:8px 0 24px rgba(0,0,0,0.08),0 0 0 2px rgba(24,119,242,0.18),0 0 18px rgba(0,153,255,0.32),0 0 32px rgba(0,153,255,0.18);overflow-y:auto;transform:translateX(0);transition:transform 0.3s ease}
body.sidebar-hidden .sidebar{transform:translateX(-105%)}
.logo-area{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.doctor-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff;box-shadow:0 8px 18px rgba(24,119,242,0.14),0 0 0 2px rgba(24,119,242,0.12)}
.logo-text h2{font-family:'Orbitron',sans-serif;font-size:19px;color:var(--azul);line-height:1}.logo-text p{font-size:12px;color:var(--gris);margin-top:5px}
.sidebar-boceto{width:100%;display:flex;flex-direction:column;gap:16px}.acciones-grid{width:100%;display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.accion-boceto{min-height:95px;text-decoration:none;border-radius:20px;background:linear-gradient(180deg,#ffffff,#fbfbfb);border:2px solid rgba(255,75,92,0.62);box-shadow:0 8px 18px rgba(0,0,0,0.07),0 0 0 2px rgba(0,153,255,0.22),0 0 14px rgba(0,153,255,0.15);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#374151;font-weight:900;font-size:12px;text-align:center;line-height:1.15;transition:0.25s ease;padding:10px 6px}
.accion-boceto img{width:38px;height:38px;object-fit:contain;display:block}.accion-boceto:hover,.accion-boceto.active{transform:translateY(-3px);color:var(--rojo2);border-color:rgba(255,75,92,0.96);box-shadow:0 12px 22px rgba(0,0,0,0.10),0 0 0 3px rgba(0,153,255,0.26),0 0 18px rgba(0,153,255,0.20)}
.cerrar-boceto{width:min(180px,100%);min-height:52px;margin:0 auto;text-decoration:none;border-radius:18px;background:linear-gradient(180deg,#fff7f8,#ffffff);border:2px solid rgba(255,75,92,0.82);box-shadow:0 8px 18px rgba(0,0,0,0.07),0 0 0 2px rgba(0,153,255,0.24),0 0 14px rgba(0,153,255,0.14);display:flex;align-items:center;justify-content:center;gap:9px;color:var(--rojo2);font-weight:900;font-size:12px;transition:0.25s ease}.cerrar-boceto img{width:26px;height:26px;object-fit:contain}.cerrar-boceto:hover{transform:translateY(-2px);border-color:rgba(255,75,92,0.98);box-shadow:0 12px 22px rgba(0,0,0,0.10),0 0 0 3px rgba(0,153,255,0.28),0 0 18px rgba(0,153,255,0.18)}
.info-boceto{width:100%;display:flex;flex-direction:column;gap:9px;margin-top:4px}.info-boceto a{min-height:46px;text-decoration:none;border-radius:16px;background:#ffffff;border:2px solid rgba(0,153,255,0.56);box-shadow:0 6px 14px rgba(0,0,0,0.05),0 0 12px rgba(0,153,255,0.12);display:flex;align-items:center;padding:0 16px;color:#374151;font-weight:900;font-size:13px;transition:0.25s ease}.info-boceto a:hover{color:var(--azul);transform:translateX(4px);border-color:rgba(0,153,255,0.96);box-shadow:0 8px 16px rgba(0,0,0,0.06),0 0 18px rgba(0,153,255,0.18)}
.modo-oscuro-panel{width:100%;min-height:58px;margin-top:4px;border-radius:18px;background:#ffffff;border:2px solid rgba(0,153,255,0.60);box-shadow:0 8px 18px rgba(0,0,0,0.06),0 0 14px rgba(0,153,255,0.14);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px}.modo-oscuro-texto{display:flex;align-items:center;gap:8px;color:#374151;font-size:13px;font-weight:900}.modo-oscuro-texto span{font-size:18px}
.switch-modo{width:54px;height:30px;border:none;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 2px 5px rgba(0,0,0,0.16),0 0 0 2px rgba(255,75,92,0.28),0 0 0 4px rgba(0,153,255,0.12);position:relative;cursor:pointer;transition:0.25s ease;flex:0 0 auto}.switch-modo span{position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#ffffff;box-shadow:0 3px 8px rgba(0,0,0,0.25);transition:0.25s ease}.switch-modo:disabled{opacity:0.65;cursor:not-allowed}
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:74px;z-index:900;display:flex;align-items:center;gap:16px;padding:12px 24px;background:rgba(255,255,255,0.94);backdrop-filter:blur(14px);border-bottom:3px solid var(--azul-neon-fuerte);box-shadow:0 4px 18px rgba(0,0,0,0.07),0 0 0 1px rgba(24,119,242,0.14),0 0 16px rgba(0,153,255,0.24);transition:left 0.3s ease,height 0.25s ease,padding 0.25s ease,box-shadow 0.25s ease}body.sidebar-hidden .topbar{left:0}body.topbar-compact .topbar{height:58px;padding:7px 22px;box-shadow:0 3px 14px rgba(0,0,0,0.08),0 0 0 1px rgba(24,119,242,0.16),0 0 18px rgba(0,153,255,0.24)}
.menu-toggle{width:50px;height:50px;border:3px solid rgba(0,153,255,0.50);border-radius:17px;background:#ffffff;color:#111827;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(0,0,0,0.10),0 0 0 2px rgba(24,119,242,0.10),0 0 16px rgba(0,153,255,0.24),0 0 28px rgba(0,153,255,0.12);transition:0.25s ease}.menu-toggle:hover{transform:translateY(-2px);color:var(--azul)}body.topbar-compact .menu-toggle{width:42px;height:42px;border-radius:14px}.menu-toggle span{width:25px;height:2px;background:currentColor;position:relative;border-radius:999px}.menu-toggle span::before,.menu-toggle span::after{content:"";position:absolute;left:0;width:25px;height:2px;background:currentColor;border-radius:999px}.menu-toggle span::before{top:-8px}.menu-toggle span::after{top:8px}
.topbar-title{flex:1;min-width:0}.topbar-title h1{font-family:'Orbitron',sans-serif;font-size:clamp(1.25rem,3vw,2rem);color:var(--azul);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}body.topbar-compact .topbar-title h1{font-size:clamp(1rem,2.4vw,1.45rem)}.topbar-user{max-width:330px;display:flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;background:#ffffff;box-shadow:0 6px 15px rgba(0,0,0,0.08);font-weight:800;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}body.topbar-compact .topbar-user{padding:7px 14px}
.main-content{position:relative;z-index:2;min-height:100dvh;margin-left:var(--sidebar);padding:104px clamp(16px,4vw,42px) 122px;transition:margin-left 0.3s ease}body.sidebar-hidden .main-content{margin-left:0}
.perfil-wrapper{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:22px}.perfil-head,.form-panel{width:100%;border-radius:30px;background:rgba(255,255,255,0.95);border:1px solid rgba(17,24,39,0.06);box-shadow:0 16px 34px rgba(0,0,0,0.10),0 0 0 1px rgba(24,119,242,0.12),0 0 18px rgba(0,153,255,0.14);position:relative;overflow:hidden}.perfil-head{padding:clamp(22px,4vw,34px);display:flex;justify-content:space-between;align-items:center;gap:20px}.perfil-head::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 12% 18%,rgba(24,119,242,0.25),transparent 260px),radial-gradient(circle at 88% 82%,rgba(255,75,92,0.23),transparent 290px),linear-gradient(90deg,rgba(24,119,242,0.12),rgba(255,255,255,0.02) 48%,rgba(255,75,92,0.13))}.perfil-head>*{position:relative;z-index:1}.perfil-chip{display:inline-flex;align-items:center;gap:8px;width:max-content;max-width:100%;padding:9px 14px;border-radius:999px;background:#ffffff;border:2px solid rgba(0,153,255,0.35);color:#1877f2;font-size:13px;font-weight:900;box-shadow:0 8px 18px rgba(0,0,0,0.06);margin-bottom:12px}.perfil-head h2{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.65rem,4vw,2.45rem);margin-bottom:8px}.perfil-head p{max-width:720px;color:#4b5563;line-height:1.65;font-size:0.98rem}.perfil-head-img{width:150px;height:150px;border-radius:34px;background:radial-gradient(circle at 25% 20%,rgba(255,255,255,0.58),transparent 55px),linear-gradient(135deg,#1877f2,#0ea5e9 45%,#ff4b5c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:70px;flex:0 0 auto;box-shadow:0 18px 34px rgba(0,0,0,0.16)}
.perfil-head-img img{width:100%;height:100%;object-fit:cover;display:block}.foto-perfil-box{grid-column:1/-1;border-radius:24px;background:rgba(248,250,252,0.85);border:2px solid rgba(0,153,255,0.22);padding:18px;display:grid;grid-template-columns:180px 1fr;gap:16px;align-items:center}.preview-foto-perfil{width:180px;height:180px;border-radius:32px;border:3px solid rgba(0,153,255,0.42);background:radial-gradient(circle at 30% 20%,rgba(24,119,242,0.20),transparent 120px),linear-gradient(135deg,#eaf4ff,#fff2f4);display:flex;align-items:center;justify-content:center;color:#1877f2;font-size:64px;font-weight:900;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,0.10)}.preview-foto-perfil img{width:100%;height:100%;object-fit:cover;display:block}.foto-perfil-info{display:flex;flex-direction:column;gap:10px}.file-label{min-height:54px;width:max-content;max-width:100%;border-radius:18px;padding:14px 18px;background:linear-gradient(135deg,#1877f2,#0ea5e9);color:#ffffff;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;box-shadow:0 12px 24px rgba(0,0,0,0.14)}.file-label input{display:none}.form-panel{padding:clamp(18px,4vw,30px)}.form-title{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px}.form-title h3{font-family:'Orbitron',sans-serif;color:var(--azul);font-size:clamp(1.1rem,3vw,1.45rem)}.form-title span{color:#6b7280;font-size:13px;font-weight:800}.perfil-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.form-group{display:flex;flex-direction:column;gap:8px}.form-group.full{grid-column:1/-1}.form-group label{color:#374151;font-size:13px;font-weight:900}.form-group input{width:100%;min-height:52px;border-radius:18px;border:2px solid rgba(0,153,255,0.35);background:#ffffff;color:#111827;outline:none;padding:13px 15px;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;box-shadow:0 8px 16px rgba(0,0,0,0.05),0 0 0 2px rgba(24,119,242,0.06);transition:0.25s ease}.form-group input:focus{border-color:rgba(255,75,92,0.88);box-shadow:0 10px 20px rgba(0,0,0,0.08),0 0 0 3px rgba(0,153,255,0.18)}.input-readonly{background:#f8fafc!important;color:#475569!important;cursor:not-allowed}.form-note{color:#6b7280;font-size:12px;font-weight:700;line-height:1.5}.form-note.alerta-note{color:#ff3045}.alerta{border-radius:22px;padding:15px 17px;margin-bottom:18px;font-weight:800;line-height:1.55;display:flex;gap:10px;align-items:flex-start}.alerta.error{background:#fff1f2;color:#b91c1c;border:2px solid rgba(255,75,92,0.42)}.alerta.exito{background:#ecfdf5;color:#047857;border:2px solid rgba(16,185,129,0.40)}.password-box{grid-column:1/-1;border-radius:24px;background:rgba(248,250,252,0.85);border:2px solid rgba(0,153,255,0.22);padding:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.password-box h4{grid-column:1/-1;font-family:'Orbitron',sans-serif;color:var(--azul);font-size:1rem}.btn-row{grid-column:1/-1;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;padding-top:8px}.btn-retame{min-height:52px;border-radius:18px;padding:13px 20px;border:none;text-decoration:none;color:#ffffff;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 12px 24px rgba(0,0,0,0.14);transition:0.25s ease;text-align:center;cursor:pointer;font-family:'Poppins',sans-serif}.btn-retame:hover{transform:translateY(-3px);box-shadow:0 18px 32px rgba(0,0,0,0.18)}.btn-retame.azul{background:linear-gradient(135deg,#1877f2,#0ea5e9);border:2px solid rgba(0,153,255,0.45)}.btn-retame.rojo{background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);border:2px solid rgba(255,75,92,0.52)}.btn-retame.blanco{color:#1877f2;background:#ffffff;border:2px solid rgba(0,153,255,0.45)}
.bottom-nav{position:fixed;left:var(--sidebar);right:0;bottom:0;width:auto;height:88px;z-index:1600;display:grid;grid-template-columns:repeat(6,1fr);gap:0;padding:8px 18px;border-radius:22px 22px 0 0;background:#ffffff;border-top:2px solid rgba(0,153,255,0.72);box-shadow:0 -6px 18px rgba(0,0,0,0.06),0 0 16px rgba(0,153,255,0.14);backdrop-filter:blur(16px);transition:left 0.3s ease,opacity 0.25s ease,transform 0.25s ease}body.sidebar-hidden .bottom-nav{left:0}body.bottom-nav-hidden .bottom-nav{opacity:0;transform:translateY(115%);pointer-events:none}.bottom-nav a{position:relative;min-width:0;text-decoration:none;display:flex;align-items:center;justify-content:center;background:transparent;border:none;box-shadow:none;outline:none;transition:0.25s ease;overflow:visible;border-radius:16px}.bottom-nav a img{width:clamp(34px,4vw,50px);height:clamp(34px,4vw,50px);object-fit:contain;display:block;transition:0.25s ease;filter:none;opacity:0.94;padding:3px;border-radius:14px;background:transparent}.bottom-nav a::after{content:"";position:absolute;width:54px;height:54px;left:50%;top:50%;transform:translate(-50%,-50%);border-radius:15px;opacity:0;transition:0.25s ease;pointer-events:none}.bottom-nav a.active::after{opacity:1;border:2px solid rgba(255,75,92,0.98);box-shadow:0 0 0 2px rgba(0,153,255,0.98),0 0 12px rgba(0,153,255,0.25),0 0 8px rgba(255,75,92,0.18);background:transparent}.bottom-nav a.active img{transform:scale(1.03);opacity:1}.bottom-noti{position:absolute;top:12px;right:calc(50% - 26px);width:16px;height:16px;border-radius:50%;background:#ff2f45;color:#fff;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff}.mobile-overlay{display:none}
body.dark-mode{background:#0b1220;color:#e5e7eb}body.dark-mode .bg-particles{background:radial-gradient(circle at 18% 20%,rgba(0,153,255,0.28),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,0.24),transparent 410px),radial-gradient(circle at 50% 70%,rgba(87,117,255,0.16),transparent 360px),linear-gradient(90deg,rgba(0,153,255,0.16) 0%,rgba(10,18,32,0.34) 48%,rgba(255,75,92,0.16) 100%),linear-gradient(135deg,#070b14 0%,#0b1220 48%,#160a12 100%)}body.dark-mode .sidebar,body.dark-mode .topbar,body.dark-mode .bottom-nav{background:rgba(12,18,31,0.96);color:#e5e7eb}body.dark-mode .sidebar{border-right-color:rgba(0,153,255,0.95);box-shadow:8px 0 24px rgba(0,0,0,0.28),0 0 0 2px rgba(0,153,255,0.20),0 0 24px rgba(0,153,255,0.28)}body.dark-mode .topbar{border-bottom-color:rgba(0,153,255,0.95);box-shadow:0 5px 20px rgba(0,0,0,0.28),0 0 0 1px rgba(0,153,255,0.16),0 0 20px rgba(0,153,255,0.22)}body.dark-mode .bottom-nav{border-top-color:rgba(0,153,255,0.95);box-shadow:0 -8px 24px rgba(0,0,0,0.28),0 0 18px rgba(0,153,255,0.20)}body.dark-mode .doctor-logo,body.dark-mode .menu-toggle,body.dark-mode .topbar-user,body.dark-mode .perfil-head,body.dark-mode .form-panel,body.dark-mode .perfil-chip,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-panel,body.dark-mode .form-group input,body.dark-mode .password-box,body.dark-mode .foto-perfil-box{background:#111827;color:#e5e7eb}body.dark-mode .perfil-head,body.dark-mode .form-panel,body.dark-mode .perfil-chip,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a,body.dark-mode .modo-oscuro-panel,body.dark-mode .password-box,body.dark-mode .foto-perfil-box{border-color:rgba(255,75,92,0.72);box-shadow:0 10px 24px rgba(0,0,0,0.28),0 0 0 2px rgba(0,153,255,0.22),0 0 18px rgba(0,153,255,0.16)}body.dark-mode .perfil-head::before{background:radial-gradient(circle at 12% 18%,rgba(0,153,255,0.26),transparent 260px),radial-gradient(circle at 88% 82%,rgba(255,75,92,0.22),transparent 290px),linear-gradient(90deg,rgba(0,153,255,0.14),rgba(17,24,39,0.10) 48%,rgba(255,75,92,0.14))}body.dark-mode .logo-text h2,body.dark-mode .topbar-title h1,body.dark-mode .perfil-head h2,body.dark-mode .form-title h3,body.dark-mode .perfil-chip,body.dark-mode .password-box h4{color:#4db8ff}body.dark-mode .logo-text p,body.dark-mode .perfil-head p,body.dark-mode .form-title span,body.dark-mode .form-group label,body.dark-mode .form-note,body.dark-mode .modo-oscuro-texto,body.dark-mode .topbar-user,body.dark-mode .accion-boceto,body.dark-mode .cerrar-boceto,body.dark-mode .info-boceto a{color:#e5e7eb}body.dark-mode .form-group input{border-color:rgba(0,153,255,0.42)}body.dark-mode .input-readonly{background:#0b1220!important;color:#d1d5db!important}body.dark-mode .preview-foto-perfil{background:radial-gradient(circle at 30% 20%,rgba(0,153,255,0.24),transparent 130px),linear-gradient(135deg,#0b1220,#160a12);color:#4db8ff}body.dark-mode .bottom-nav a img,body.dark-mode .accion-boceto img,body.dark-mode .cerrar-boceto img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05)}body.dark-mode .switch-modo{background:linear-gradient(135deg,#1877f2,#0ea5e9);box-shadow:inset 0 2px 5px rgba(0,0,0,0.25),0 0 0 2px rgba(255,75,92,0.44),0 0 16px rgba(0,153,255,0.26)}body.dark-mode .switch-modo span{transform:translateX(24px);background:#ffffff}body.dark-mode .mobile-overlay{background:rgba(0,0,0,0.48)}
@media screen and (max-width:1050px){.perfil-head{align-items:flex-start;flex-direction:column}.perfil-head-img{width:100%;height:120px}}
@media screen and (max-width:820px){.foto-perfil-box{grid-template-columns:1fr}.preview-foto-perfil{width:160px;height:160px}.sidebar{transform:translateX(-105%);padding:20px 14px 108px}body.sidebar-open .sidebar{transform:translateX(0)}body.sidebar-hidden .sidebar{transform:translateX(-105%)}.topbar,body.sidebar-hidden .topbar{left:0;height:68px;padding:10px 14px}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;right:0;height:84px;border-radius:24px 24px 0 0;padding:8px 10px;gap:7px}body.sidebar-open .bottom-nav{opacity:0;transform:translateY(110%);pointer-events:none}.main-content,body.sidebar-hidden .main-content{margin-left:0;padding-top:94px}body.topbar-compact .topbar{height:58px;padding:7px 12px}.topbar-user{display:none}.mobile-overlay{display:block;position:fixed;inset:0;z-index:950;background:rgba(17,24,39,0.28);opacity:0;visibility:hidden;transition:0.25s ease}body.sidebar-open .mobile-overlay{opacity:1;visibility:visible}.perfil-form,.password-box{grid-template-columns:1fr}.acciones-grid{gap:10px}.accion-boceto{min-height:88px}}
@media screen and (max-width:560px){body{padding-bottom:92px}.topbar-title h1{font-size:1rem}.bottom-nav,body.sidebar-hidden .bottom-nav{left:0;right:0;width:auto;height:76px;bottom:0;border-radius:18px 18px 0 0;gap:0;padding:6px 4px;border-top:2px solid rgba(0,153,255,0.72)}.bottom-nav a img{width:34px;height:34px}.bottom-nav a::after{width:44px;height:44px;border-radius:13px}.btn-row{flex-direction:column}.btn-retame{width:100%}}
@media screen and (max-width:360px){.acciones-grid{grid-template-columns:1fr}.accion-boceto{min-height:74px}}
</style>
</head>
<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">
<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="logo-area sidebar-logo-boceto">
        <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
        <div class="logo-text"><h2>RETAME</h2><p>Panel deportivo</p></div>
    </div>
    <div class="sidebar-boceto">
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
        <div class="info-boceto"><a href="Informacion.php">Información</a><a href="AcercaDe.php">Acerca de</a><a href="SoporteTecnico.php">Soporte técnico</a></div>
        <div class="modo-oscuro-panel"><div class="modo-oscuro-texto"><span>🌙</span><strong>Modo oscuro</strong></div><button type="button" class="switch-modo" id="darkModeToggle" aria-label="Activar modo oscuro"><span></span></button></div>
    </div>
</aside>
<header class="topbar">
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="topbar-title"><h1>Mi perfil</h1></div>
    <div class="topbar-user"><span>👤</span><span><?php echo limpiarTexto($NombreSesion); ?></span></div>
</header>
<main class="main-content">
    <section class="perfil-wrapper">
        <div class="perfil-head">
            <div>
                <span class="perfil-chip">👤 ID: <?php echo limpiarTexto($datosPerfil['Id_Retador']); ?></span>
                <h2>Editar mi perfil</h2>
                <p>Actualiza tus datos personales. Tu ID de retador no se puede modificar porque identifica tu cuenta dentro de RETAME.</p>
            </div>
            <div class="perfil-head-img">
                <?php if (trim((string)$datosPerfil['FotoPerfil']) !== ''): ?>
                    <img src="<?php echo limpiarTexto($datosPerfil['FotoPerfil']); ?>" alt="Foto de perfil">
                <?php else: ?>
                    👤
                <?php endif; ?>
            </div>
        </div>
        <div class="form-panel">
            <div class="form-title"><h3>Datos personales</h3><span>Los cambios se guardan directamente en la tabla retador</span></div>
            <?php if ($mensajeError !== ''): ?><div class="alerta error"><span>⚠️</span><div><?php echo limpiarTexto($mensajeError); ?></div></div><?php endif; ?>
            <?php if ($mensajeExito !== ''): ?><div class="alerta exito"><span>✅</span><div><?php echo limpiarTexto($mensajeExito); ?></div></div><?php endif; ?>
            <?php if ($avisoFotoPerfil !== ''): ?><div class="alerta error"><span>⚠️</span><div><?php echo limpiarTexto($avisoFotoPerfil); ?></div></div><?php endif; ?>
            <form class="perfil-form" method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="accion" value="actualizar_perfil">
                <div class="form-group"><label for="id_retador">ID de retador</label><input class="input-readonly" type="text" id="id_retador" value="<?php echo limpiarTexto($datosPerfil['Id_Retador']); ?>" readonly><span class="form-note">Este dato no se puede modificar.</span></div>
                <div class="foto-perfil-box">
                    <div class="preview-foto-perfil" id="previewFotoPerfil">
                        <?php if (trim((string)$datosPerfil['FotoPerfil']) !== ''): ?>
                            <img src="<?php echo limpiarTexto($datosPerfil['FotoPerfil']); ?>" alt="Foto de perfil actual">
                        <?php else: ?>
                            👤
                        <?php endif; ?>
                    </div>
                    <div class="foto-perfil-info">
                        <label class="file-label">
                            <span>📷 Cambiar foto de perfil</span>
                            <input type="file" id="foto_perfil" name="foto_perfil" accept="image/jpeg,image/png,image/webp" <?php echo $fotoPerfilDisponible ? '' : 'disabled'; ?>>
                        </label>
                        <span class="form-note" id="fotoPerfilTexto">JPG, PNG o WEBP. Peso máximo: 1 MB. Se guardará en tu perfil.</span>
                    </div>
                </div>
                <div class="form-group"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" maxlength="255" required value="<?php echo limpiarTexto($datosPerfil['Nombre']); ?>"></div>
                <div class="form-group"><label for="apellido">Apellido</label><input type="text" id="apellido" name="apellido" maxlength="255" required value="<?php echo limpiarTexto($datosPerfil['Apellido']); ?>"></div>
                <div class="form-group"><label for="edad">Edad</label><input type="number" id="edad" name="edad" min="1" max="120" required value="<?php echo limpiarTexto($datosPerfil['Edad']); ?>"></div>
                <div class="form-group"><label for="estado">Estado</label><input type="text" id="estado" name="estado" maxlength="50" required value="<?php echo limpiarTexto($datosPerfil['Estado']); ?>"></div>
                <div class="form-group"><label for="pais">País</label><input type="text" id="pais" name="pais" maxlength="255" required value="<?php echo limpiarTexto($datosPerfil['Pais']); ?>"></div>
                <div class="form-group"><label for="telefono">Número de teléfono</label><input type="tel" id="telefono" name="telefono" maxlength="20" value="<?php echo limpiarTexto($datosPerfil['Telefono']); ?>" placeholder="Ej. 9630000000"><?php if (trim((string)$datosPerfil['Telefono']) === ''): ?><span class="form-note alerta-note">Para una mejor experiencia pon tu número de teléfono.</span><?php endif; ?></div>
                <div class="form-group"><label for="correo">Correo electrónico</label><input type="email" id="correo" name="correo" maxlength="50" value="<?php echo limpiarTexto($datosPerfil['Correo']); ?>" placeholder="correo@ejemplo.com"><?php if (trim((string)$datosPerfil['Correo']) === ''): ?><span class="form-note alerta-note">Para una mejor experiencia pon tu correo.</span><?php endif; ?></div>
                <div class="password-box">
                    <h4>Editar contraseña</h4>
                    <div class="form-group"><label for="contrasena_nueva">Nueva contraseña</label><input type="password" id="contrasena_nueva" name="contrasena_nueva" maxlength="255" placeholder="Deja vacío si no quieres cambiarla"><span class="form-note">Solo se actualizará si escribes una nueva contraseña.</span></div>
                    <div class="form-group"><label for="contrasena_confirmar">Confirmar contraseña</label><input type="password" id="contrasena_confirmar" name="contrasena_confirmar" maxlength="255" placeholder="Confirma la nueva contraseña"></div>
                </div>
                <div class="btn-row"><a href="Perfil2.php" class="btn-retame blanco">← Regresar</a><button type="submit" class="btn-retame rojo">💾 Guardar cambios</button></div>
            </form>
        </div>
    </section>
</main>
<nav class="bottom-nav">
    <a href="Perfil2.php" aria-label="Inicio"><img src="Imagenes/ImgInicio.png" alt=""></a>
    <a href="Retar/retar.php" aria-label="Retar"><img src="Imagenes/ImgReta.png" alt=""></a>
    <a href="Ligas/liga.php" aria-label="Ligas"><img src="Imagenes/ImgLigas.png" alt=""></a>
    <a href="Agenda.php" aria-label="Agenda"><img src="Imagenes/ImgAgenda.png" alt=""></a>
    <a href="Notificaciones.php" aria-label="Notificaciones"><img src="Imagenes/ImgNoti.png" alt=""><?php if ($nuevas_count > 0): ?><span class="bottom-noti">!</span><?php endif; ?></a>
    <a href="miperfil.php" class="active" aria-label="Perfil"><img src="Imagenes/ImgPerfil.png" alt=""></a>
</nav>
<script>
const menuToggle=document.getElementById('menuToggle');
const mobileOverlay=document.getElementById('mobileOverlay');
const darkModeToggle=document.getElementById('darkModeToggle');
const modoOscuroInicial=<?php echo $modoOscuroActivo ? 'true' : 'false'; ?>;
function esMovil(){return window.innerWidth<=820}
menuToggle.addEventListener('click',function(){if(esMovil()){document.body.classList.toggle('sidebar-open')}else{document.body.classList.toggle('sidebar-hidden')}});
mobileOverlay.addEventListener('click',function(){document.body.classList.remove('sidebar-open')});
window.addEventListener('resize',function(){if(!esMovil()){document.body.classList.remove('sidebar-open')}});
let ultimaPosicionScroll=window.scrollY||document.documentElement.scrollTop||0;
function controlarBarrasPorScroll(){const posicionActual=window.scrollY||document.documentElement.scrollTop||0;if(posicionActual>18){document.body.classList.add('topbar-compact')}else{document.body.classList.remove('topbar-compact')}if(document.body.classList.contains('sidebar-open')){document.body.classList.remove('bottom-nav-hidden');ultimaPosicionScroll=posicionActual;return}const estaBajando=posicionActual>ultimaPosicionScroll&&posicionActual>90;const estaSubiendo=posicionActual<ultimaPosicionScroll;if(estaBajando){document.body.classList.add('bottom-nav-hidden')}if(estaSubiendo||posicionActual<90){document.body.classList.remove('bottom-nav-hidden')}ultimaPosicionScroll=Math.max(posicionActual,0)}
window.addEventListener('scroll',controlarBarrasPorScroll,{passive:true});
function aplicarModoOscuroVisual(estado){if(estado){document.body.classList.add('dark-mode');if(darkModeToggle){darkModeToggle.setAttribute('aria-label','Desactivar modo oscuro')}}else{document.body.classList.remove('dark-mode');if(darkModeToggle){darkModeToggle.setAttribute('aria-label','Activar modo oscuro')}}}
function actualizarModoPerfilBD(estado){const datos=new FormData();datos.append('accion','actualizar_modo_perfil');datos.append('modo',estado?'oscuro':'predeterminado');return fetch(window.location.href,{method:'POST',body:datos,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(respuesta=>respuesta.json())}
document.addEventListener('DOMContentLoaded',function(){aplicarModoOscuroVisual(modoOscuroInicial);controlarBarrasPorScroll();if(darkModeToggle){darkModeToggle.addEventListener('click',function(){const nuevoEstado=!document.body.classList.contains('dark-mode');const estadoAnterior=!nuevoEstado;aplicarModoOscuroVisual(nuevoEstado);darkModeToggle.disabled=true;actualizarModoPerfilBD(nuevoEstado).then(data=>{if(!data||!data.ok){aplicarModoOscuroVisual(estadoAnterior);alert(data&&data.mensaje?data.mensaje:'No se pudo guardar el modo de perfil.')}}).catch(()=>{aplicarModoOscuroVisual(estadoAnterior);alert('No se pudo conectar con la base de datos para guardar el modo.')}).finally(()=>{darkModeToggle.disabled=false})})}});

const fotoPerfilInput=document.getElementById('foto_perfil');
const previewFotoPerfil=document.getElementById('previewFotoPerfil');
const fotoPerfilTexto=document.getElementById('fotoPerfilTexto');
if(fotoPerfilInput){fotoPerfilInput.addEventListener('change',function(){const archivo=fotoPerfilInput.files[0];if(!archivo){return}if(archivo.size>1048576){alert('La foto de perfil debe pesar menos de 1 MB.');fotoPerfilInput.value='';return}fotoPerfilTexto.textContent=archivo.name;const reader=new FileReader();reader.onload=function(e){previewFotoPerfil.innerHTML='<img src="'+e.target.result+'" alt="Vista previa de foto de perfil">'};reader.readAsDataURL(archivo)})}
</script>
</body>
</html>
