<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("conexion.php");

// Función para generar un ID único basado en el nombre y la primera letra del apellido
function generarId($conn, $nombre, $apellido) {
    do {
        $num = rand(10, 99);
        $id = ucfirst(strtolower($nombre)) . strtoupper(substr($apellido, 0, 1)) . $num;
        $sql = "SELECT Id_Retador FROM Retador WHERE Id_Retador = '$id'";
        $resultado = mysqli_query($conn, $sql);
    } while (mysqli_num_rows($resultado) > 0);
    return $id;
}

function iniciaConLetra($valor) {
    return preg_match('/^\p{L}/u', $valor) === 1;
}

function soloNumeros($valor) {
    return preg_match('/^[0-9]+$/', $valor) === 1;
}

$mensaje = "";
$id_generado = "";
$datos_usuario = [];

// === Paso 1: Registrar datos personales ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['registro_datos'])) {

    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $codigo_postal = trim($_POST['codigo_postal']);
    $pais = trim($_POST['pais']);
    $estado = trim($_POST['estado']);
    $edad = trim($_POST['edad']);

    if (empty($nombre) || empty($apellido) || empty($codigo_postal) || empty($pais) || empty($estado) || empty($edad)) {
        $mensaje = "❌ Por favor completa todos los campos.";
    } elseif (!iniciaConLetra($nombre)) {
        $mensaje = "❌ El nombre debe comenzar con una letra. No puede iniciar con número o símbolo.";
    } elseif (!iniciaConLetra($apellido)) {
        $mensaje = "❌ El apellido debe comenzar con una letra. No puede iniciar con número o símbolo.";
    } elseif (!iniciaConLetra($pais)) {
        $mensaje = "❌ El país debe comenzar con una letra. No puede iniciar con número o símbolo.";
    } elseif (!iniciaConLetra($estado)) {
        $mensaje = "❌ El estado debe comenzar con una letra. No puede iniciar con número o símbolo.";
    } elseif (!soloNumeros($codigo_postal)) {
        $mensaje = "❌ El código postal solo debe contener números.";
    } elseif (!soloNumeros($edad)) {
        $mensaje = "❌ La edad solo debe contener números.";
    } else {
        $nombre = ucfirst(strtolower($nombre));
        $apellido = ucfirst(strtolower($apellido));
        $pais = ucfirst(strtolower($pais));
        $estado = ucfirst(strtolower($estado));

        $id_retador = generarId($conn, $nombre, $apellido);
        $sql = "INSERT INTO Retador (Id_Retador, Nombre, Apellido, CodigoPostal, Pais, Estado, Edad)
                VALUES ('$id_retador', '$nombre', '$apellido', '$codigo_postal', '$pais', '$estado', '$edad')";
        if (mysqli_query($conn, $sql)) {
            mysqli_query($conn, "UPDATE Retador SET estado_equipo = 0 WHERE Id_Retador='$id_retador'");
            $id_generado = $id_retador;
            $datos_usuario = [
                "Id_Retador" => $id_retador,
                "Nombre" => ucfirst(strtolower($nombre)),
                "Apellido" => ucfirst(strtolower($apellido)),
                "CodigoPostal" => $codigo_postal,
                "Pais" => ucfirst(strtolower($pais)),
                "Estado" => ucfirst(strtolower($estado)),
                "Edad" => $edad
            ];
        } else {
            $mensaje = "❌ Error al registrar: " . mysqli_error($conn);
        }
    }
}

// === Paso 2: Guardar contraseña ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['guardar_contrasena'])) {
    $id_retador = $_POST['id_retador'];
    $contrasena = $_POST['contrasena'];
    $confirmar = $_POST['confirmar'];

    if (empty($contrasena) || empty($confirmar)) {
        $mensaje = "⚠️ Debes ingresar y confirmar la contraseña.";
    } elseif ($contrasena !== $confirmar) {
        $mensaje = "❌ Las contraseñas no coinciden.";
        $id_generado = $id_retador; 
    } else {
        $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);
        $sql = "UPDATE Retador SET Contrasena='$contrasena_hash' WHERE Id_Retador='$id_retador'";
        if (mysqli_query($conn, $sql)) {
            $consulta = mysqli_query($conn, "SELECT * FROM Retador WHERE Id_Retador='$id_retador'");
            $usuario = mysqli_fetch_assoc($consulta);
            $datos_usuario = $usuario;
            $id_generado = $id_retador;
            $mensaje = "✅ Contraseña guardada correctamente.";
        } else {
            $mensaje = "❌ Error al guardar la contraseña: " . mysqli_error($conn);
        }
    }
}

// === VERIFICAR EQUIPO ANTES DE UNIRSE ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verificar_equipo'])) {
    $id_equipo = trim($_POST['id_equipo'] ?? '');
    
    if (empty($id_equipo)) {
        echo json_encode(['status' => 'error', 'mensaje' => '❌ ID de equipo vacío.']);
        exit;
    }
    
    // Verificar que el equipo exista
    $sql_equipo = "SELECT * FROM equipo WHERE Id_Equipo = '$id_equipo'";
    $result_equipo = mysqli_query($conn, $sql_equipo);
    
    if (mysqli_num_rows($result_equipo) == 0) {
        echo json_encode(['status' => 'error', 'mensaje' => '❌ El equipo no existe.']);
        exit;
    }
    
    $equipo_data = mysqli_fetch_assoc($result_equipo);
    
    // Obtener cantidad máxima del equipo
    $capacidad = $equipo_data['cantidad'];
    
    // Contar cuántos jugadores ya están en el equipo
    $sql_contar = "SELECT COUNT(*) as total_jugadores FROM equipo_jugador WHERE Id_Equipo = '$id_equipo'";
    $result_contar = mysqli_query($conn, $sql_contar);
    $contar_data = mysqli_fetch_assoc($result_contar);
    
    $jugadores_actuales = $contar_data['total_jugadores'];
    
    // Calcular espacios disponibles
    $espacios_disponibles = $capacidad - $jugadores_actuales;
    
    echo json_encode([
        'status' => 'success',
        'id_equipo' => $id_equipo,
        'nombre_equipo' => $equipo_data['Nombre'],
        'capacidad' => $capacidad,
        'jugadores_actuales' => $jugadores_actuales,
        'espacios_disponibles' => $espacios_disponibles,
        'hay_espacio' => ($jugadores_actuales < $capacidad)
    ]);
    exit;
}

// === Paso 3: Unirse a un equipo vía AJAX ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_unirse_equipo'])) {
    $id_retador = $_POST['id_retador'] ?? '';
    $id_equipo = trim($_POST['id_equipo'] ?? '');

    if (empty($id_retador) || empty($id_equipo)) {
        echo json_encode(['status'=>'error','mensaje'=>'⚠️ Faltan datos.']);
        exit;
    }

    // 1. Verificar que el equipo exista y obtener su capacidad
    $sql_equipo = "SELECT cantidad, Nombre FROM equipo WHERE Id_Equipo='$id_equipo'";
    $result_equipo = mysqli_query($conn, $sql_equipo);
    if (mysqli_num_rows($result_equipo) == 0) {
        echo json_encode(['status'=>'error','mensaje'=>'❌ El equipo no existe.']);
        exit;
    }
    
    $equipo_data = mysqli_fetch_assoc($result_equipo);
    $capacidad_equipo = $equipo_data['cantidad'];
    $nombre_equipo = $equipo_data['Nombre'];

    // 2. Contar cuántos jugadores ya están en el equipo
    $sql_contar = "SELECT COUNT(*) as total_jugadores FROM equipo_jugador WHERE Id_Equipo='$id_equipo'";
    $result_contar = mysqli_query($conn, $sql_contar);
    $contar_data = mysqli_fetch_assoc($result_contar);
    $jugadores_actuales = $contar_data['total_jugadores'];

    // 3. VERIFICAR CAPACIDAD DEL EQUIPO
    // Si la cantidad de jugadores actuales es IGUAL o MAYOR que la capacidad, NO PERMITIR
    if ($jugadores_actuales >= $capacidad_equipo) {
        echo json_encode([
            'status'=>'error',
            'mensaje'=>'❌ El equipo <strong>' . htmlspecialchars($nombre_equipo) . '</strong> está COMPLETO.<br>'
                     . 'Capacidad máxima: ' . $capacidad_equipo . ' jugadores<br>'
                     . 'Jugadores actuales: ' . $jugadores_actuales . ' jugadores<br>'
                     . '<strong>No hay espacio disponible</strong>'
        ]);
        exit;
    }

    // 4. Verificar si ya está en el equipo
    $sql_check = "SELECT * FROM equipo_jugador WHERE Id_Jugador='$id_retador' AND Id_Equipo='$id_equipo'";
    $result_check = mysqli_query($conn, $sql_check);
    if (mysqli_num_rows($result_check) > 0) {
        echo json_encode(['status'=>'error','mensaje'=>'⚠️ Ya eres miembro de este equipo.']);
        exit;
    }

    // 5. Si hay espacio disponible, insertar en equipo_jugador con tipo 'Jugador'
    // NOTA: NO ACTUALIZAMOS LA TABLA EQUIPO
    $id_equipojugador = 'EJ-' . uniqid() . '-' . time();
    $sql_insert = "INSERT INTO equipo_jugador (Id_EquipoJugador, Id_Jugador, Id_Equipo, tipo) 
                   VALUES ('$id_equipojugador','$id_retador','$id_equipo','Jugador')";
    
    if (mysqli_query($conn, $sql_insert)) {
        // Actualizar estado_equipo a 1 (TRUE) en Retador
        mysqli_query($conn, "UPDATE Retador SET estado_equipo = 1 WHERE Id_Retador='$id_retador'");
        
        // NO ACTUALIZAMOS LA TABLA EQUIPO - solo consultamos
        $nuevo_total = $jugadores_actuales + 1;
        
        echo json_encode([
            'status'=>'success',
            'mensaje'=>'✅ ¡Te has unido al equipo <strong>' . htmlspecialchars($nombre_equipo) . '</strong> exitosamente!<br>'
                     . 'Capacidad: ' . $nuevo_total . ' / ' . $capacidad_equipo . ' jugadores<br>'
                     . 'Tipo asignado: <strong>Jugador</strong>',
            'nombre_equipo' => $nombre_equipo,
            'capacidad' => $capacidad_equipo,
            'jugadores_actuales' => $nuevo_total
        ]);
    } else {
        echo json_encode(['status'=>'error','mensaje'=>'❌ Error al unirte al equipo: ' . mysqli_error($conn)]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva Cuenta - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --azul: #00c3ff;
        --azul-oscuro: #00aeea;
        --rojo: #ff4b5c;
        --rojo-suave: rgba(255, 75, 92, 0.18);
        --texto: #151515;
        --texto-suave: #4b5563;
        --blanco: #ffffff;
        --fondo-azul: #f5fbff;
        --fondo-rojo: #fff7f9;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    html {
        width: 100%;
        min-height: 100%;
    }

    body {
        min-height: 100dvh;
        display: flex;
        justify-content: center;
        align-items: center;
        color: var(--texto);
        position: relative;
        overflow-x: hidden;
        overflow-y: auto;
        padding: clamp(16px, 4vw, 45px);
        background: linear-gradient(135deg, #ffffff 0%, #f5fbff 50%, #fff7f9 100%);
    }

    .bg-particles {
        position: fixed;
        inset: 0;
        z-index: 0;
        overflow: hidden;
        pointer-events: none;
        background: linear-gradient(135deg, #ffffff 0%, #f5fbff 50%, #fff7f9 100%);
    }

    .bg-particles::before,
    .bg-particles::after {
        content: "";
        position: absolute;
        width: max(1900px, 230vmax);
        height: max(1900px, 230vmax);
        left: 50%;
        top: 50%;
        border-radius: 50%;
        transform: translate(-50%, -50%);
        transform-origin: center;
    }

    .bg-particles::before {
        background:
            repeating-radial-gradient(
                circle at center,
                rgba(0, 195, 255, 0.24) 0px,
                rgba(0, 195, 255, 0.17) 14px,
                rgba(0, 195, 255, 0.06) 28px,
                transparent 28px,
                transparent 185px
            );
        animation: ondasAzules 15s ease-in-out infinite alternate;
    }

    .bg-particles::after {
        background:
            repeating-radial-gradient(
                circle at center,
                rgba(255, 75, 92, 0.20) 0px,
                rgba(255, 75, 92, 0.13) 14px,
                rgba(255, 75, 92, 0.05) 30px,
                transparent 30px,
                transparent 245px
            );
        animation: ondasRojas 19s ease-in-out infinite alternate;
    }

    .particle {
        position: absolute;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(0, 195, 255, 0.35), rgba(255, 75, 92, 0.16), transparent 70%);
        filter: blur(18px);
        animation: float 20s infinite linear;
    }

    @keyframes ondasAzules {
        0% {
            transform: translate(-50%, -50%) scale(1) rotate(0deg);
            opacity: 0.85;
        }
        50% {
            transform: translate(-50%, -50%) scale(1.07) rotate(1deg);
            opacity: 1;
        }
        100% {
            transform: translate(-50%, -50%) scale(1.14) rotate(-1deg);
            opacity: 0.9;
        }
    }

    @keyframes ondasRojas {
        0% {
            transform: translate(-50%, -50%) scale(1) rotate(0deg);
            opacity: 0.70;
        }
        50% {
            transform: translate(-50%, -50%) scale(1.05) rotate(-1deg);
            opacity: 0.92;
        }
        100% {
            transform: translate(-50%, -50%) scale(1.12) rotate(1deg);
            opacity: 0.80;
        }
    }

    @keyframes float {
        0% {
            transform: translateY(0) translateX(0) scale(1);
            opacity: 0.30;
        }
        50% {
            transform: translateY(-55vh) translateX(35px) scale(1.25);
            opacity: 0.55;
        }
        100% {
            transform: translateY(-110vh) translateX(-25px) scale(1);
            opacity: 0.25;
        }
    }

    .contenedor {
        position: relative;
        z-index: 2;
        width: min(100%, 470px);
        padding: clamp(28px, 5vw, 42px);
        border-radius: clamp(20px, 4vw, 28px);
        color: var(--texto);
        background:
            linear-gradient(rgba(255,255,255,0.96), rgba(255,255,255,0.96)) padding-box,
            linear-gradient(135deg, var(--azul), var(--rojo)) border-box;
        border: 2px solid transparent;
        box-shadow:
            0 0 35px rgba(0, 195, 255, 0.25),
            0 0 45px rgba(255, 75, 92, 0.18),
            0 14px 42px rgba(0, 0, 0, 0.10);
        backdrop-filter: blur(7px);
        animation: fadeInUp 0.5s ease-out, pulse 2.8s infinite alternate;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(22px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes pulse {
        0% {
            box-shadow:
                0 0 24px rgba(0, 195, 255, 0.20),
                0 0 28px rgba(255, 75, 92, 0.12),
                0 10px 30px rgba(0, 0, 0, 0.08);
        }
        100% {
            box-shadow:
                0 0 40px rgba(0, 195, 255, 0.32),
                0 0 44px rgba(255, 75, 92, 0.22),
                0 16px 42px rgba(0, 0, 0, 0.13);
        }
    }

    h2 {
        text-align: center;
        color: var(--azul-oscuro);
        margin-bottom: clamp(20px, 4vw, 28px);
        font-size: clamp(1.45rem, 5vw, 1.8rem);
        padding-bottom: 12px;
        border-bottom: 2px solid transparent;
        border-image: linear-gradient(90deg, var(--azul), var(--rojo)) 1;
        text-shadow: 0 0 10px rgba(255, 75, 92, 0.22);
        line-height: 1.25;
    }

    label {
        display: block;
        margin-top: 15px;
        font-weight: 600;
        color: #333;
        font-size: clamp(0.86rem, 3.4vw, 0.95rem);
    }

    input {
        display: block;
        width: 100%;
        min-height: 48px;
        padding: clamp(12px, 3.5vw, 15px) clamp(14px, 4vw, 16px);
        margin-top: 8px;
        border-radius: 14px;
        outline: none;
        font-size: clamp(0.95rem, 3.6vw, 1rem);
        color: var(--texto);
        background: #ffffff;
        border: 2px solid rgba(0, 195, 255, 0.45);
        box-shadow:
            inset 0 0 0 1px rgba(255, 75, 92, 0.16),
            0 4px 12px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
    }

    input::placeholder {
        color: #6b7280;
        font-weight: 500;
    }

    input:focus {
        border-color: var(--azul);
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(0, 195, 255, 0.16),
            0 0 16px rgba(0, 195, 255, 0.26),
            inset 0 0 0 1px rgba(255, 75, 92, 0.20);
        transform: scale(1.015);
    }

    input.campo-valido {
        border-color: rgba(34, 197, 94, 0.85);
        box-shadow:
            0 0 0 3px rgba(34, 197, 94, 0.12),
            inset 0 0 0 1px rgba(34, 197, 94, 0.18);
    }

    input.campo-error {
        border-color: rgba(255, 75, 92, 0.95);
        box-shadow:
            0 0 0 3px rgba(255, 75, 92, 0.14),
            0 0 14px rgba(255, 75, 92, 0.20);
    }

    .ayuda-validacion {
        display: block;
        min-height: 18px;
        margin-top: 6px;
        font-size: clamp(0.74rem, 2.8vw, 0.82rem);
        line-height: 1.35;
        color: #6b7280;
    }

    .ayuda-validacion.error-texto {
        color: var(--rojo);
        text-shadow: 0 0 5px rgba(255, 75, 92, 0.16);
    }

    .ayuda-validacion.ok-texto {
        color: #16a34a;
    }

    button:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: none !important;
    }

    button {
        width: 100%;
        min-height: 50px;
        background: linear-gradient(90deg, var(--azul), var(--rojo));
        color: white;
        font-weight: 700;
        padding: clamp(13px, 3.5vw, 15px);
        border: none;
        border-radius: 14px;
        margin-top: 20px;
        cursor: pointer;
        transition: transform 0.25s ease, box-shadow 0.3s ease, opacity 0.3s ease;
        font-size: clamp(0.95rem, 3.6vw, 1rem);
    }

    button:hover {
        transform: translateY(-3px) scale(1.015);
        box-shadow:
            0 0 22px rgba(0, 195, 255, 0.45),
            0 0 22px rgba(255, 75, 92, 0.35);
    }

    button:disabled {
        opacity: 0.65;
        cursor: not-allowed;
        transform: none;
    }

    .btn-login {
        background: linear-gradient(90deg, var(--azul), #008fe8);
        color: #fff;
    }

    .btn-login:hover {
        box-shadow: 0 0 24px rgba(0, 195, 255, 0.45);
    }

    .btn-success {
        background: linear-gradient(90deg, var(--azul), var(--rojo));
        color: #fff;
    }

    .btn-success:hover {
        box-shadow:
            0 0 22px rgba(0, 195, 255, 0.45),
            0 0 22px rgba(255, 75, 92, 0.35);
    }

    .btn-danger {
        background: linear-gradient(90deg, #ff4b5c, #d92035);
        color: #fff;
    }

    .btn-danger:hover {
        box-shadow: 0 0 24px rgba(255, 75, 92, 0.45);
    }

    .btn-purple {
        background: linear-gradient(90deg, var(--azul), var(--rojo));
        color: #fff;
    }

    .btn-purple:hover {
        box-shadow:
            0 0 22px rgba(0, 195, 255, 0.45),
            0 0 22px rgba(255, 75, 92, 0.35);
    }

    .mensaje {
        text-align: center;
        font-weight: 600;
        margin: 15px 0;
        padding: 12px;
        border-radius: 12px;
        animation: fadeIn 0.5s ease;
        line-height: 1.45;
        word-wrap: break-word;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .mensaje-error {
        background: rgba(255, 75, 92, 0.10);
        border-left: 5px solid var(--rojo);
        color: #d92035;
    }

    .mensaje-success {
        background: rgba(0, 195, 255, 0.10);
        border-left: 5px solid var(--azul);
        color: #008fc4;
    }

    .info {
        text-align: left;
        line-height: 1.85;
        background:
            linear-gradient(#ffffff, #ffffff) padding-box,
            linear-gradient(135deg, rgba(0,195,255,0.55), rgba(255,75,92,0.38)) border-box;
        border: 2px solid transparent;
        padding: clamp(16px, 4vw, 22px);
        border-radius: 18px;
        margin: 20px 0;
        font-size: clamp(0.88rem, 3.4vw, 0.95rem);
        color: #333;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.07);
        word-wrap: break-word;
    }

    .info strong {
        color: var(--azul-oscuro);
    }


    .usuario-login-box {
        background:
            linear-gradient(#ffffff, #ffffff) padding-box,
            linear-gradient(135deg, rgba(0,195,255,0.75), rgba(255,75,92,0.58)) border-box;
        border: 2px solid transparent;
        border-radius: 18px;
        padding: clamp(14px, 4vw, 18px);
        margin-bottom: 18px;
        box-shadow:
            0 0 18px rgba(0, 195, 255, 0.18),
            0 0 18px rgba(255, 75, 92, 0.10);
    }

    .usuario-login-header {
        color: var(--azul-oscuro);
        font-weight: 700;
        margin-bottom: 10px;
        line-height: 1.35;
    }

    .usuario-login-row {
        display: flex;
        align-items: stretch;
        gap: 10px;
        width: 100%;
    }

    .usuario-generado {
        flex: 1;
        display: flex;
        align-items: center;
        min-height: 48px;
        padding: 12px 14px;
        border-radius: 14px;
        background: #f8fbff;
        border: 2px solid rgba(0, 195, 255, 0.42);
        color: #111;
        font-weight: 700;
        letter-spacing: 0.4px;
        overflow-wrap: anywhere;
        box-shadow: inset 0 0 0 1px rgba(255, 75, 92, 0.12);
    }

    .btn-copiar-usuario {
        width: auto;
        min-width: 110px;
        min-height: 48px;
        margin-top: 0;
        padding: 12px 14px;
        white-space: nowrap;
    }

    .usuario-aviso {
        margin-top: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        background: rgba(0, 195, 255, 0.08);
        border-left: 5px solid var(--azul);
        color: #006f99;
        font-size: clamp(0.82rem, 3.2vw, 0.92rem);
        font-weight: 600;
        line-height: 1.45;
    }

    .notificacion-copia {
        position: fixed;
        left: 50%;
        bottom: 24px;
        transform: translateX(-50%) translateY(120px);
        z-index: 2000;
        width: min(92%, 430px);
        padding: 14px 18px;
        border-radius: 16px;
        text-align: center;
        font-weight: 700;
        color: #ffffff;
        background: linear-gradient(90deg, var(--azul), var(--rojo));
        box-shadow:
            0 0 24px rgba(0, 195, 255, 0.36),
            0 0 24px rgba(255, 75, 92, 0.28),
            0 12px 32px rgba(0, 0, 0, 0.18);
        opacity: 0;
        pointer-events: none;
        transition: transform 0.35s ease, opacity 0.35s ease;
    }

    .notificacion-copia.mostrar {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
    }

    .password-container {
        position: relative;
    }

    .password-container input {
        padding-right: 48px;
    }

    .toggle {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        font-size: 16px;
        color: #555;
        user-select: none;
        transition: color 0.3s ease, transform 0.3s ease;
    }

    .toggle:hover {
        color: var(--rojo);
        transform: translateY(-50%) scale(1.1);
    }

    #popup {
        position: fixed;
        inset: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.62);
        backdrop-filter: blur(8px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1000;
        animation: fadeIn 0.3s ease;
        padding: clamp(14px, 4vw, 24px);
        overflow-y: auto;
    }

    #popup-contenido {
        background:
            linear-gradient(rgba(255,255,255,0.97), rgba(255,255,255,0.97)) padding-box,
            linear-gradient(135deg, var(--azul), var(--rojo)) border-box;
        padding: clamp(22px, 5vw, 32px);
        border-radius: 24px;
        text-align: center;
        width: min(100%, 470px);
        max-height: calc(100dvh - 36px);
        overflow-y: auto;
        border: 2px solid transparent;
        color: var(--texto);
        box-shadow:
            0 0 32px rgba(0, 195, 255, 0.30),
            0 0 40px rgba(255, 75, 92, 0.20),
            0 16px 45px rgba(0, 0, 0, 0.16);
        animation: slideUp 0.4s ease;
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    #popup-titulo {
        color: var(--azul-oscuro);
        margin-bottom: 20px;
        font-size: clamp(1.2rem, 4.5vw, 1.45rem);
        line-height: 1.3;
        text-shadow: 0 0 8px rgba(255, 75, 92, 0.20);
    }

    #mensaje-equipo {
        min-height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 15px;
        padding: 12px;
        border-radius: 12px;
        font-size: clamp(0.86rem, 3.4vw, 0.95rem);
        line-height: 1.45;
    }

    .capacidad-info,
    .info-equipo,
    .capacidad-disponible,
    .capacidad-llena {
        padding: 15px;
        border-radius: 14px;
        margin: 15px 0;
        font-size: clamp(0.86rem, 3.4vw, 0.95rem);
        text-align: center;
        line-height: 1.55;
        word-wrap: break-word;
    }

    .capacidad-info {
        background: rgba(0, 195, 255, 0.07);
        color: #333;
        border: 1px solid rgba(0, 195, 255, 0.25);
    }

    .info-equipo {
        background: rgba(0, 195, 255, 0.08);
        border: 1px solid rgba(0, 195, 255, 0.25);
        color: #333;
    }

    .capacidad-disponible {
        background: rgba(0, 195, 255, 0.10);
        border: 2px solid var(--azul);
        color: #007ca9;
        font-weight: 600;
    }

    .capacidad-llena {
        background: rgba(255, 75, 92, 0.10);
        border: 2px solid var(--rojo);
        color: #c5162b;
        font-weight: 600;
    }

    #popup input {
        margin: 10px 0;
    }

    @media (max-width: 1024px) {
        .bg-particles::before,
        .bg-particles::after {
            width: max(1500px, 240vmax);
            height: max(1500px, 240vmax);
        }
    }

    @media (max-width: 768px) {
        body {
            padding: 24px;
            align-items: center;
        }

        .contenedor {
            width: min(100%, 440px);
        }

        .bg-particles::before,
        .bg-particles::after {
            width: max(1200px, 260vmax);
            height: max(1200px, 260vmax);
        }
    }

    @media (max-width: 480px) {
        body {
            padding: 16px;
        }

        .contenedor {
            width: 100%;
            padding: 28px 20px;
            border-radius: 22px;
        }

        h2 {
            font-size: 1.35rem;
        }

        input,
        button {
            min-height: 47px;
        }

        #popup {
            align-items: center;
            padding: 14px;
        }

        #popup-contenido {
            padding: 22px 18px;
            border-radius: 20px;
        }


        .usuario-login-row {
            flex-direction: column;
        }

        .btn-copiar-usuario {
            width: 100%;
        }

        .bg-particles::before,
        .bg-particles::after {
            width: max(950px, 290vmax);
            height: max(950px, 290vmax);
        }
    }

    @media (max-width: 360px) {
        body {
            padding: 12px;
        }

        .contenedor {
            padding: 24px 16px;
        }

        label {
            font-size: 0.82rem;
        }

        .info {
            font-size: 0.82rem;
        }
    }

    @media (max-height: 620px) {
        body {
            align-items: flex-start;
            padding-top: 18px;
            padding-bottom: 18px;
        }

        .contenedor {
            padding-top: 24px;
            padding-bottom: 24px;
        }

        h2 {
            margin-bottom: 18px;
        }

        input {
            min-height: 44px;
        }

        button {
            min-height: 46px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            scroll-behavior: auto !important;
        }
    }
</style>
</head>
<body>

<!-- Partículas de fondo -->
<div class="bg-particles" id="particles"></div>

<div class="contenedor">

<?php if (empty($id_generado) && !isset($_POST['guardar_contrasena'])): ?>
    <!-- PASO 1: Registro de datos personales -->
    <h2>👤 Registro de Nuevo Retador</h2>
    <?php if (!empty($mensaje)): ?>
        <div class="mensaje mensaje-error"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    
    <form method="POST" action="" id="form-registro" novalidate>
        <input type="hidden" name="registro_datos" value="1">

        <label for="nombre">Nombre:</label>
        <input type="text" id="nombre" name="nombre" required placeholder="Ej: Juan" autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-nombre">Debe comenzar con una letra.</small>

        <label for="apellido">Apellido:</label>
        <input type="text" id="apellido" name="apellido" required placeholder="Ej: Pérez" autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-apellido">Debe comenzar con una letra.</small>

        <label for="codigo_postal">Código Postal:</label>
        <input type="text" id="codigo_postal" name="codigo_postal" required placeholder="Ej: 28001" inputmode="numeric" pattern="[0-9]*" autocomplete="postal-code" value="<?php echo htmlspecialchars($_POST['codigo_postal'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-codigo_postal">Solo se permiten números.</small>

        <label for="pais">País:</label>
        <input type="text" id="pais" name="pais" required placeholder="Ej: México" autocomplete="country-name" value="<?php echo htmlspecialchars($_POST['pais'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-pais">Debe comenzar con una letra.</small>

        <label for="estado">Estado:</label>
        <input type="text" id="estado" name="estado" required placeholder="Ej: Chiapas" autocomplete="address-level1" value="<?php echo htmlspecialchars($_POST['estado'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-estado">Debe comenzar con una letra.</small>

        <label for="edad">Edad:</label>
        <input type="text" id="edad" name="edad" required placeholder="Ej: 25" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?php echo htmlspecialchars($_POST['edad'] ?? ''); ?>">
        <small class="ayuda-validacion" id="ayuda-edad">Solo se permiten números.</small>

        <button type="submit" id="btn-registrar">📝 Registrar</button>
    </form>

<?php elseif (!empty($id_generado) && empty($datos_usuario['Contrasena'])): ?>
    <!-- PASO 2: Crear contraseña -->
    <h2>🔒 Crear Contraseña</h2>
    <?php if (!empty($mensaje)): ?>
        <div class="mensaje mensaje-error"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <input type="hidden" name="guardar_contrasena" value="1">
        <input type="hidden" name="id_retador" value="<?php echo htmlspecialchars($id_generado); ?>">
        
        <label>Contraseña:</label>
        <div class="password-container">
            <input type="password" name="contrasena" id="contrasena" required placeholder="Ingresa tu contraseña">
            <span class="toggle" onclick="togglePassword('contrasena')">👁️</span>
        </div>
        
        <label>Confirmar Contraseña:</label>
        <div class="password-container">
            <input type="password" name="confirmar" id="confirmar" required placeholder="Confirma tu contraseña">
            <span class="toggle" onclick="togglePassword('confirmar')">👁️</span>
        </div>
        
        <button type="submit" class="btn-success">💾 Guardar Contraseña</button>
    </form>

<?php elseif (!empty($id_generado) && isset($datos_usuario['Contrasena']) && !empty($datos_usuario['Contrasena'])): ?>
    <!-- PASO 3: Registro completo -->
    
    <!-- Popup para unirse a equipo -->
    <div id="popup">
        <div id="popup-contenido">
            <h3 id="popup-titulo">🤝 ¿Quieres unirte a un equipo?</h3>
            <div id="popup-body">
                <div id="mensaje-equipo" class="mensaje"></div>
                <button onclick="mostrarInputEquipo()" class="btn-purple">🏆 Unirme al Equipo</button>
            </div>
            <br>
            <button onclick="cerrarPopup()" class="btn-danger">❌ Cerrar</button>
        </div>
    </div>

    <h2>🎉 Registro Completo</h2>
    
    <?php if (!empty($mensaje) && !isset($_POST['ajax_unirse_equipo'])): ?>
        <div class="mensaje mensaje-success"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    
    <div class="info">
        <div class="usuario-login-box">
            <div class="usuario-login-header">👤 <strong>Usuario para iniciar sesión</strong></div>
            <div class="usuario-login-row">
                <span id="usuario-generado" class="usuario-generado"><?php echo htmlspecialchars($datos_usuario["Id_Retador"]); ?></span>
                <button type="button" class="btn-copiar-usuario" onclick="copiarUsuario()">📋 Copiar</button>
            </div>
            <div class="usuario-aviso">⚠️ Este usuario es para iniciar sesión. Cópialo y guárdalo para entrar a tu cuenta.</div>
        </div>

        👤 <strong>Nombre:</strong> <?php echo htmlspecialchars($datos_usuario["Nombre"] . " " . $datos_usuario["Apellido"]); ?><br>
        📍 <strong>Código Postal:</strong> <?php echo htmlspecialchars($datos_usuario["CodigoPostal"]); ?><br>
        🌎 <strong>País:</strong> <?php echo htmlspecialchars($datos_usuario["Pais"]); ?><br>
        🏙️ <strong>Estado:</strong> <?php echo htmlspecialchars($datos_usuario["Estado"]); ?><br>
        🎂 <strong>Edad:</strong> <?php echo htmlspecialchars($datos_usuario["Edad"]); ?><br>
        🔒 <strong>Contraseña:</strong> Configurada correctamente<br>
        🏆 <strong>Estado:</strong> <?php echo ($datos_usuario['estado_equipo'] == 1) ? 'Con equipo' : 'Sin equipo'; ?>
    </div>

    <button onclick="abrirPopup()" class="btn-purple" style="margin-bottom: 15px;">
        🏆 Unirme a un Equipo
    </button>

    <form action="index.html" method="GET">
        <button type="submit" class="btn-login">🚪 Iniciar Sesión</button>
    </form>

<?php endif; ?>
</div>

<div id="notificacion-copia" class="notificacion-copia"></div>

<script>
// Crear partículas dinámicas
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 15;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        const size = Math.random() * 15 + 5;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        particle.style.opacity = Math.random() * 0.2 + 0.1;
        
        const duration = Math.random() * 25 + 20;
        const delay = Math.random() * 5;
        particle.style.animation = `float ${duration}s ${delay}s infinite linear`;
        
        particlesContainer.appendChild(particle);
    }
    
    iniciarValidacionesRegistro();

    <?php if (!empty($id_generado) && isset($datos_usuario['Contrasena']) && !empty($datos_usuario['Contrasena'])): ?>
    // Mostrar popup automáticamente al terminar el registro
    setTimeout(() => {
        abrirPopup();
    }, 500);
    <?php endif; ?>
});


function iniciarValidacionesRegistro() {
    const formulario = document.getElementById('form-registro');
    if (!formulario) {
        return;
    }

    const campos = {
        nombre: {
            input: document.getElementById('nombre'),
            ayuda: document.getElementById('ayuda-nombre'),
            tipo: 'letra',
            error: 'El nombre debe comenzar con una letra. No uses número ni símbolo al inicio.',
            ok: 'Nombre válido.'
        },
        apellido: {
            input: document.getElementById('apellido'),
            ayuda: document.getElementById('ayuda-apellido'),
            tipo: 'letra',
            error: 'El apellido debe comenzar con una letra. No uses número ni símbolo al inicio.',
            ok: 'Apellido válido.'
        },
        pais: {
            input: document.getElementById('pais'),
            ayuda: document.getElementById('ayuda-pais'),
            tipo: 'letra',
            error: 'El país debe comenzar con una letra. No uses número ni símbolo al inicio.',
            ok: 'País válido.'
        },
        estado: {
            input: document.getElementById('estado'),
            ayuda: document.getElementById('ayuda-estado'),
            tipo: 'letra',
            error: 'El estado debe comenzar con una letra. No uses número ni símbolo al inicio.',
            ok: 'Estado válido.'
        },
        codigo_postal: {
            input: document.getElementById('codigo_postal'),
            ayuda: document.getElementById('ayuda-codigo_postal'),
            tipo: 'numero',
            error: 'El código postal solo debe contener números.',
            ok: 'Código postal válido.'
        },
        edad: {
            input: document.getElementById('edad'),
            ayuda: document.getElementById('ayuda-edad'),
            tipo: 'numero',
            error: 'La edad solo debe contener números.',
            ok: 'Edad válida.'
        }
    };

    const boton = document.getElementById('btn-registrar');
    const regexLetraInicial = /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/;
    const regexSoloNumeros = /^[0-9]+$/;

    function validarCampo(config, mostrarVacio = false) {
        if (!config.input || !config.ayuda) {
            return true;
        }

        const valor = config.input.value.trim();

        if (config.tipo === 'numero') {
            const limpio = config.input.value.replace(/[^0-9]/g, '');
            if (config.input.value !== limpio) {
                config.input.value = limpio;
            }
        }

        const valorActual = config.input.value.trim();

        if (valorActual === '') {
            config.input.classList.remove('campo-valido', 'campo-error');
            config.ayuda.classList.remove('ok-texto', 'error-texto');
            config.ayuda.textContent = config.tipo === 'letra' ? 'Debe comenzar con una letra.' : 'Solo se permiten números.';
            if (mostrarVacio) {
                config.input.classList.add('campo-error');
                config.ayuda.classList.add('error-texto');
                config.ayuda.textContent = 'Este campo es obligatorio.';
            }
            return false;
        }

        let valido = false;

        if (config.tipo === 'letra') {
            valido = regexLetraInicial.test(valorActual);
        } else {
            valido = regexSoloNumeros.test(valorActual);
        }

        config.input.classList.toggle('campo-valido', valido);
        config.input.classList.toggle('campo-error', !valido);
        config.ayuda.classList.toggle('ok-texto', valido);
        config.ayuda.classList.toggle('error-texto', !valido);
        config.ayuda.textContent = valido ? config.ok : config.error;

        return valido;
    }

    function validarFormulario(mostrarVacios = false) {
        const resultados = Object.values(campos).map(config => validarCampo(config, mostrarVacios));
        const formularioValido = resultados.every(Boolean);

        if (boton) {
            boton.disabled = !formularioValido;
        }

        return formularioValido;
    }

    Object.values(campos).forEach(config => {
        if (!config.input) {
            return;
        }

        config.input.addEventListener('input', () => {
            validarCampo(config);
            validarFormulario(false);
        });

        config.input.addEventListener('blur', () => {
            validarCampo(config, true);
            validarFormulario(false);
        });
    });

    formulario.addEventListener('submit', function(event) {
        if (!validarFormulario(true)) {
            event.preventDefault();
        }
    });

    validarFormulario(false);
}

function togglePassword(id) {
    const input = document.getElementById(id);
    input.type = input.type === "password" ? "text" : "password";
}

function copiarUsuario() {
    const usuarioElemento = document.getElementById('usuario-generado');
    if (!usuarioElemento) {
        return;
    }

    const usuario = usuarioElemento.textContent.trim();

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(usuario)
            .then(() => mostrarNotificacionCopia('✅ Usuario copiado. Ahora puedes pegarlo al iniciar sesión.'))
            .catch(() => copiarUsuarioManual(usuario));
    } else {
        copiarUsuarioManual(usuario);
    }
}

function copiarUsuarioManual(usuario) {
    const temporal = document.createElement('textarea');
    temporal.value = usuario;
    temporal.setAttribute('readonly', '');
    temporal.style.position = 'fixed';
    temporal.style.left = '-9999px';
    document.body.appendChild(temporal);
    temporal.select();

    try {
        document.execCommand('copy');
        mostrarNotificacionCopia('✅ Usuario copiado. Ahora puedes pegarlo al iniciar sesión.');
    } catch (error) {
        mostrarNotificacionCopia('⚠️ No se pudo copiar. Selecciona el usuario manualmente.');
    }

    document.body.removeChild(temporal);
}

function mostrarNotificacionCopia(texto) {
    const notificacion = document.getElementById('notificacion-copia');
    if (!notificacion) {
        return;
    }

    notificacion.textContent = texto;
    notificacion.classList.add('mostrar');

    clearTimeout(window.timeoutNotificacionCopia);
    window.timeoutNotificacionCopia = setTimeout(() => {
        notificacion.classList.remove('mostrar');
    }, 2800);
}

// Funciones para el popup de unirse a equipo
function abrirPopup() {
    document.getElementById('popup').style.display = 'flex';
    document.getElementById('popup-body').innerHTML = `
        <div id="mensaje-equipo" class="mensaje"></div>
        <button onclick="mostrarInputEquipo()" class="btn-purple">🏆 Unirme al Equipo</button>
    `;
    document.getElementById('popup-titulo').innerText = "🤝 ¿Quieres unirte a un equipo?";
}

function cerrarPopup() {
    document.getElementById('popup').style.display = 'none';
}

function mostrarInputEquipo() {
    document.getElementById('popup-body').innerHTML = `
        <div id="mensaje-equipo" class="mensaje"></div>
        <input type="text" id="id_equipo_input" placeholder="Ingresa el ID del equipo (Ej: EQ-2024-001)" 
               style="width:100%;padding:12px 15px;margin:10px 0;border-radius:14px;border:2px solid rgba(0,195,255,0.45);background:#ffffff;color:#111;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
        <div id="info-equipo" style="display:none;"></div>
        <div id="info-capacidad" style="display:none;"></div>
        <button onclick="verificarCapacidadEquipo()" class="btn-success" style="margin-top:10px;">🔍 Verificar Capacidad</button>
        <button onclick="abrirPopup()" class="btn-danger" style="margin-top:10px;">⬅ Volver</button>
    `;
    document.getElementById('popup-titulo').innerText = "🔍 Verificar Capacidad del Equipo";
}

function verificarCapacidadEquipo() {
    const id_equipo = document.getElementById('id_equipo_input').value.trim();
    const popupBody = document.getElementById('popup-body');
    const mensajeDiv = document.getElementById('mensaje-equipo');
    
    // LIMPIAR completamente el contenido anterior
    popupBody.innerHTML = '';
    
    // Crear nueva estructura
    popupBody.innerHTML = `
        <div id="mensaje-equipo" class="mensaje"></div>
        <input type="text" id="id_equipo_input" placeholder="Ingresa el ID del equipo (Ej: EQ-2024-001)" 
               value="${id_equipo || ''}"
               style="width:100%;padding:12px 15px;margin:10px 0;border-radius:14px;border:2px solid rgba(0,195,255,0.45);background:#ffffff;color:#111;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
        <div id="info-equipo" style="display:none;"></div>
        <div id="info-capacidad" style="display:none;"></div>
        <button onclick="verificarCapacidadEquipo()" class="btn-success" style="margin-top:10px;">🔍 Verificar Capacidad</button>
        <button onclick="abrirPopup()" class="btn-danger" style="margin-top:10px;">⬅ Volver</button>
    `;
    
    // Obtener referencias actualizadas
    const mensajeDivNuevo = document.getElementById('mensaje-equipo');
    const infoDiv = document.getElementById('info-equipo');
    const capacidadDiv = document.getElementById('info-capacidad');
    
    if (!id_equipo) {
        mensajeDivNuevo.innerHTML = '⚠️ Debes ingresar el ID del equipo.';
        mensajeDivNuevo.className = 'mensaje mensaje-error';
        return;
    }
    
    // Mostrar carga
    mensajeDivNuevo.innerHTML = '🔍 Verificando capacidad del equipo...';
    mensajeDivNuevo.className = 'mensaje';
    
    // Verificar capacidad del equipo vía AJAX
    const formData = new FormData();
    formData.append('verificar_equipo', '1');
    formData.append('id_equipo', id_equipo);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'error') {
            mensajeDivNuevo.innerHTML = data.mensaje;
            mensajeDivNuevo.className = 'mensaje mensaje-error';
        } else {
            // Mostrar información del equipo
            infoDiv.innerHTML = `
                <div class="info-equipo">
                    <strong>🏆 ${data.nombre_equipo}</strong><br>
                    ID: ${data.id_equipo}
                </div>
            `;
            infoDiv.style.display = 'block';
            
            // Mostrar información de capacidad
            if (data.hay_espacio) {
                // HAY ESPACIO DISPONIBLE
                capacidadDiv.innerHTML = `
                    <div class="capacidad-disponible">
                        ✅ <strong>HAY ESPACIO DISPONIBLE</strong><br>
                        Capacidad máxima: ${data.capacidad} jugadores<br>
                        Jugadores actuales: ${data.jugadores_actuales} jugadores<br>
                        <strong>Espacios disponibles: ${data.espacios_disponibles}</strong>
                    </div>
                    <button onclick="unirseEquipo('${data.id_equipo}', ${data.capacidad}, ${data.jugadores_actuales})" 
                            class="btn-success" style="margin-top:10px;width:100%;">
                        ✅ Unirme a este Equipo
                    </button>
                `;
                mensajeDivNuevo.innerHTML = '✅ El equipo tiene espacio disponible';
                mensajeDivNuevo.className = 'mensaje mensaje-success';
            } else {
                // EQUIPO LLENO
                capacidadDiv.innerHTML = `
                    <div class="capacidad-llena">
                        🚫 <strong>EQUIPO COMPLETO</strong><br>
                        Capacidad máxima: ${data.capacidad} jugadores<br>
                        Jugadores actuales: ${data.jugadores_actuales} jugadores<br>
                        <strong>NO HAY ESPACIOS DISPONIBLES</strong>
                    </div>
                    <button disabled class="btn-danger" style="margin-top:10px;width:100%;opacity:0.6;cursor:not-allowed;">
                        ❌ No se puede unir (Equipo lleno)
                    </button>
                `;
                mensajeDivNuevo.innerHTML = '❌ El equipo está completo';
                mensajeDivNuevo.className = 'mensaje mensaje-error';
            }
            capacidadDiv.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mensajeDivNuevo.innerHTML = '❌ Error al verificar el equipo. Intenta nuevamente.';
        mensajeDivNuevo.className = 'mensaje mensaje-error';
    });
}

function unirseEquipo(id_equipo, capacidad, jugadores_actuales) {
    const popupBody = document.getElementById('popup-body');
    const mensajeDiv = document.getElementById('mensaje-equipo');
    const capacidadDiv = document.getElementById('info-capacidad');
    
    // Verificar nuevamente que haya espacio
    if (jugadores_actuales >= capacidad) {
        mensajeDiv.innerHTML = '❌ El equipo ya está lleno. No se puede unir.';
        mensajeDiv.className = 'mensaje mensaje-error';
        return;
    }
    
    mensajeDiv.innerHTML = '🔄 Uniéndote al equipo...';
    mensajeDiv.className = 'mensaje';
    
    const formData = new FormData();
    formData.append('ajax_unirse_equipo', '1');
    formData.append('id_retador', '<?php echo $id_generado; ?>');
    formData.append('id_equipo', id_equipo);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        mensajeDiv.innerHTML = data.mensaje;
        
        if (data.status === 'success') {
            mensajeDiv.className = 'mensaje mensaje-success';
            
            // Actualizar información de capacidad
            if (capacidadDiv) {
                const nuevoTotal = jugadores_actuales + 1;
                const espaciosRestantes = capacidad - nuevoTotal;
                
                capacidadDiv.innerHTML = `
                    <div class="capacidad-disponible">
                        ✅ <strong>¡UNIDO EXITOSAMENTE!</strong><br>
                        Jugadores actuales: ${nuevoTotal} / ${capacidad} jugadores<br>
                        Espacios restantes: ${espaciosRestantes}<br>
                        <strong>Tipo asignado: Jugador</strong>
                    </div>
                `;
            }
            
            // LIMPIAR y mostrar solo botón de cierre
            const inputsAndButtons = popupBody.querySelectorAll('input, button:not(#mensaje-equipo)');
            inputsAndButtons.forEach(el => {
                if (el.id !== 'mensaje-equipo') {
                    el.style.display = 'none';
                }
            });
            
            // Agregar solo un botón de cierre
            const cerrarBtn = document.createElement('button');
            cerrarBtn.className = 'btn-success';
            cerrarBtn.style.marginTop = '10px';
            cerrarBtn.style.width = '100%';
            cerrarBtn.innerHTML = '🎉 ¡Listo! Cerrar';
            cerrarBtn.onclick = cerrarPopup;
            
            // Asegurar que solo haya un botón de cierre
            const existingCloseBtn = popupBody.querySelector('.btn-success[onclick="cerrarPopup()"]');
            if (!existingCloseBtn) {
                popupBody.appendChild(cerrarBtn);
            }
            
            // Actualizar estado en la página principal
            const estadoElement = document.querySelector('.info strong:last-child');
            if (estadoElement) {
                estadoElement.textContent = 'Con equipo';
            }
        } else {
            mensajeDiv.className = 'mensaje mensaje-error';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mensajeDiv.innerHTML = '❌ Error al procesar la solicitud. Intenta nuevamente.';
        mensajeDiv.className = 'mensaje mensaje-error';
    });
}
</script>

</body>
</html>