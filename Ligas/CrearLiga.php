<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    if (file_exists(__DIR__ . '/includes/retame_global.php')) {
        include_once __DIR__ . '/includes/retame_global.php';
    } elseif (file_exists(__DIR__ . '/../includes/retame_global.php')) {
        include_once __DIR__ . '/../includes/retame_global.php';
    } elseif (file_exists(__DIR__ . '/../../includes/retame_global.php')) {
        include_once __DIR__ . '/../../includes/retame_global.php';
    }
}
$retame_ajax_temprano = true;
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

// CONEXIÓN A LA BASE DE DATOS
try {
    // Cambia estos valores según tu configuración
    $host = 'localhost';
    $dbname = 'retame_bd';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("❌ Error de conexión a la base de datos: " . $e->getMessage());
}

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$Id_Retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';

$mensaje = '';
$tipo_mensaje = ''; // 'success' o 'error'
$liga_creada = false;
$info_liga = null;

// Consultar deportes para el select - SOLO los de tipo "equipo"
$deportes = [];
try {
    // MODIFICADO: Solo deportes donde Tipo = 'equipo'
    $sql_deportes = "SELECT Id_Deporte, Nombre FROM deporte WHERE Tipo = 'equipo' ORDER BY Nombre";
    $stmt_deportes = $pdo->query($sql_deportes);
    $deportes = $stmt_deportes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error al cargar deportes: " . $e->getMessage();
    $tipo_mensaje = 'error';
}

// Procesar el formulario cuando se envíe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_liga'])) {
    // Validar y sanitizar datos
    $nombre_liga = trim($_POST['nombre_liga'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $id_deporte = $_POST['id_deporte'] ?? '';
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    // Validaciones básicas
    if (empty($nombre_liga) || empty($codigo_postal) || empty($fecha_inicio) || empty($id_deporte)) {
        $mensaje = "⚠️ Por favor completa todos los campos obligatorios";
        $tipo_mensaje = 'error';
    } 
    // Validar que el nombre no inicie con número y no sea solo números
    elseif (preg_match('/^\d/', $nombre_liga)) {
        $mensaje = "⚠️ El nombre de la liga no puede iniciar con un número";
        $tipo_mensaje = 'error';
    }
    elseif (preg_match('/^\d+$/', $nombre_liga)) {
        $mensaje = "⚠️ El nombre de la liga no puede ser solo números";
        $tipo_mensaje = 'error';
    }
    // Validar que el código postal solo contenga números
    elseif (!preg_match('/^\d+$/', $codigo_postal)) {
        $mensaje = "⚠️ El código postal solo puede contener números";
        $tipo_mensaje = 'error';
    }
    elseif (strlen($nombre_liga) > 255) {
        $mensaje = "⚠️ El nombre de la liga es demasiado largo";
        $tipo_mensaje = 'error';
    } elseif (!empty($fecha_fin) && strtotime($fecha_fin) < strtotime($fecha_inicio)) {
        $mensaje = "⚠️ La fecha de fin no puede ser anterior a la fecha de inicio";
        $tipo_mensaje = 'error';
    } else {
        try {
            // Generar ID de liga según la regla: nombre + fecha creación
            $fecha_creacion = date('Ymd');
            $id_liga_base = preg_replace('/[^a-zA-Z0-9]/', '', $nombre_liga);
            $id_liga = substr($id_liga_base, 0, 30) . '_' . $fecha_creacion;
            
            // Verificar si el ID ya existe (añadir número si es necesario)
            $contador = 1;
            $id_liga_original = $id_liga;
            
            while (true) {
                $sql_check = "SELECT COUNT(*) FROM ligas WHERE Id_Liga = ?";
                $stmt_check = $pdo->prepare($sql_check);
                $stmt_check->execute([$id_liga]);
                $existe = $stmt_check->fetchColumn();
                
                if ($existe == 0) {
                    break;
                }
                $id_liga = $id_liga_original . '_' . $contador;
                $contador++;
            }
            
            // PREPARAR LA CONSULTA DE INSERCIÓN - SIN CantidadEquipos
            $sql = "INSERT INTO ligas (
                Id_Liga, 
                Nombre, 
                CodigoPostal, 
                FechaCreacion, 
                FechaInicio, 
                FechaFin, 
                Estado,
                Id_Deporte,
                Id_Creador,
                Descripcion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            
            // Ejecutar la inserción - SIN CantidadEquipos
            $resultado = $stmt->execute([
                $id_liga,
                $nombre_liga,
                $codigo_postal,
                date('Y-m-d'), // FechaCreacion (actual)
                $fecha_inicio,
                empty($fecha_fin) ? null : $fecha_fin,
                'Inscripciones', // Estado por defecto
                $id_deporte,
                $Id_Retador,
                empty($descripcion) ? null : $descripcion
            ]);
            
            if ($resultado) {
                $mensaje = "✅ Liga creada exitosamente!";
                $tipo_mensaje = 'success';
                $liga_creada = true;
                
                // Obtener información completa de la liga recién creada
                try {
                    $sql_info = "SELECT l.*, d.Nombre as NombreDeporte 
                                FROM ligas l 
                                JOIN deporte d ON l.Id_Deporte = d.Id_Deporte 
                                WHERE l.Id_Liga = ?";
                    $stmt_info = $pdo->prepare($sql_info);
                    $stmt_info->execute([$id_liga]);
                    $info_liga = $stmt_info->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Si hay error al obtener info, al menos tenemos el ID
                    $info_liga = ['Id_Liga' => $id_liga, 'Nombre' => $nombre_liga];
                }
            } else {
                $mensaje = "❌ Error al crear la liga";
                $tipo_mensaje = 'error';
            }
            
        } catch (PDOException $e) {
            $mensaje = "❌ Error en la base de datos: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$equipos_usuario = [];

if (file_exists(__DIR__ . '/includes/retame_global.php')) {
    include_once __DIR__ . '/includes/retame_global.php';
} elseif (file_exists(__DIR__ . '/../includes/retame_global.php')) {
    include_once __DIR__ . '/../includes/retame_global.php';
} elseif (file_exists(__DIR__ . '/../../includes/retame_global.php')) {
    include_once __DIR__ . '/../../includes/retame_global.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Crear Liga - RETAME</title>
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
    --blanco:#ffffff;
    --sombra-azul:0 0 0 3px rgba(24,119,242,0.24),0 12px 28px rgba(24,119,242,0.16);
    --sombra-roja:0 0 0 3px rgba(255,75,92,0.26),0 12px 28px rgba(255,75,92,0.16);
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

body.retame-oficial-page > .bg-particles,
body.retame-oficial-page > .sidebar:not(.retame-oficial-sidebar),
body.retame-oficial-page > .menu-toggle:not(.retame-oficial-menu-toggle),
body.retame-oficial-page > .overlay:not(.retame-oficial-overlay),
body.retame-oficial-page > .btn-ligas,
body.retame-oficial-page > .btn-retar{
    display:none !important;
}

body.retame-oficial-page > .main-content{
    position:relative !important;
    z-index:2 !important;
    width:min(1180px,100%) !important;
    max-width:1180px !important;
    margin-left:0 !important;
    margin-right:auto !important;
    padding-top:16px !important;
}

body.retame-oficial-page .main-content > h1{
    width:min(900px,100%);
    margin:0 auto 22px !important;
    padding:24px 28px;
    border-radius:28px;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,0.20),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,0.17),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,0.08),rgba(255,255,255,0.02) 46%,rgba(255,75,92,0.08)),
        rgba(255,255,255,0.94) !important;
    border:1px solid rgba(17,24,39,0.05) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14) !important;
    color:var(--azul) !important;
    font-family:'Orbitron',sans-serif !important;
    font-size:clamp(1.45rem,4vw,2rem) !important;
    font-weight:700 !important;
    line-height:1.25;
    text-align:left !important;
}

body.retame-oficial-page .user-info{
    width:min(900px,100%);
    margin:0 auto 22px !important;
    padding:16px 18px !important;
    border-radius:22px !important;
    background:rgba(255,255,255,0.96) !important;
    border:2px solid rgba(0,153,255,0.26) !important;
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.09) !important;
}

body.retame-oficial-page .user-info p{
    margin:4px 0 !important;
    color:#4b5563 !important;
    font-size:13px !important;
    line-height:1.55;
}

body.retame-oficial-page .user-info strong{
    color:#111827 !important;
    font-weight:900 !important;
}

body.retame-oficial-page .form-liga-container,
body.retame-oficial-page .resumen-liga-container{
    width:min(900px,100%) !important;
    max-width:900px !important;
    margin:0 auto 28px !important;
    padding:clamp(22px,3vw,30px) !important;
    border-radius:28px !important;
    background:rgba(255,255,255,0.94) !important;
    border:1px solid rgba(17,24,39,0.05) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14) !important;
    backdrop-filter:none !important;
}

body.retame-oficial-page .form-liga-container h2,
body.retame-oficial-page .resumen-liga-container h2{
    margin:0 0 24px !important;
    color:var(--azul) !important;
    font-family:'Orbitron',sans-serif !important;
    font-size:clamp(1.25rem,3vw,1.65rem) !important;
    font-weight:700 !important;
    text-align:left !important;
}

body.retame-oficial-page .form-group{
    margin-bottom:18px !important;
}

body.retame-oficial-page .form-group label{
    display:block;
    margin-bottom:7px !important;
    color:#374151 !important;
    font-size:13px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .required::after{
    content:" *";
    color:var(--rojo2) !important;
}

body.retame-oficial-page .form-group input[type="text"],
body.retame-oficial-page .form-group input[type="date"],
body.retame-oficial-page .form-group select,
body.retame-oficial-page .form-group textarea{
    display:block;
    width:100% !important;
    min-height:50px;
    margin:0 !important;
    padding:12px 14px !important;
    border:2px solid rgba(0,153,255,0.42) !important;
    border-radius:16px !important;
    outline:none !important;
    background:#ffffff !important;
    color:#111827 !important;
    font-family:'Poppins',sans-serif !important;
    font-size:14px !important;
    font-weight:500;
    box-shadow:0 6px 15px rgba(0,0,0,0.05) !important;
    transition:border-color .22s ease,box-shadow .22s ease,transform .22s ease !important;
}

body.retame-oficial-page .form-group textarea{
    min-height:115px;
    resize:vertical;
    line-height:1.55;
}

body.retame-oficial-page .form-group input::placeholder,
body.retame-oficial-page .form-group textarea::placeholder{
    color:#9ca3af !important;
    opacity:1;
}

body.retame-oficial-page .form-group select option{
    background:#ffffff;
    color:#111827;
}

body.retame-oficial-page .form-group input[type="text"]:focus,
body.retame-oficial-page .form-group input[type="date"]:focus,
body.retame-oficial-page .form-group select:focus,
body.retame-oficial-page .form-group textarea:focus{
    border-color:var(--azul-neon-fuerte) !important;
    box-shadow:var(--sombra-azul) !important;
    transform:translateY(-1px);
}

body.retame-oficial-page .form-group small{
    display:block;
    margin-top:6px !important;
    color:#6b7280 !important;
    font-size:11px !important;
    font-weight:600;
    line-height:1.45;
}

body.retame-oficial-page #error_nombre,
body.retame-oficial-page #error_cp{
    color:var(--rojo2) !important;
    font-weight:800 !important;
}

body.retame-oficial-page .form-liga-container .form-group > div[style]{
    padding:16px !important;
    border-radius:18px !important;
    background:
        radial-gradient(circle at 10% 10%,rgba(24,119,242,0.10),transparent 170px),
        #f8fbff !important;
    border:2px solid rgba(0,153,255,0.28) !important;
    box-shadow:
        0 8px 18px rgba(0,0,0,0.05),
        0 0 0 2px rgba(255,75,92,0.05) !important;
}

body.retame-oficial-page .form-liga-container .form-group > div[style] > p{
    margin:0 !important;
    color:var(--azul) !important;
    font-size:13px !important;
    font-weight:900 !important;
}

body.retame-oficial-page .form-liga-container .form-group > div[style] > ul{
    margin:10px 0 0 20px !important;
    color:#374151 !important;
    font-size:13px;
    line-height:1.75;
}

body.retame-oficial-page .form-liga-container .form-group > div[style] li::marker{
    color:var(--rojo2);
}

body.retame-oficial-page .btn-submit,
body.retame-oficial-page .btn-finalizar{
    display:flex !important;
    align-items:center;
    justify-content:center;
    width:100% !important;
    min-height:50px;
    padding:12px 18px !important;
    border:2px solid transparent !important;
    border-radius:18px !important;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045) !important;
    color:#ffffff !important;
    font-family:'Poppins',sans-serif !important;
    font-size:13px !important;
    font-weight:900 !important;
    cursor:pointer;
    text-decoration:none !important;
    box-shadow:var(--sombra-roja) !important;
    transition:.25s ease !important;
}

body.retame-oficial-page .btn-submit{
    margin-top:8px !important;
}

body.retame-oficial-page .btn-finalizar{
    margin-top:0 !important;
}

body.retame-oficial-page .btn-submit:hover,
body.retame-oficial-page .btn-finalizar:hover{
    transform:translateY(-3px) !important;
    box-shadow:
        0 0 0 3px rgba(255,75,92,0.30),
        0 15px 30px rgba(255,75,92,0.22) !important;
}

body.retame-oficial-page .alert{
    width:min(900px,100%);
    margin:0 auto 22px !important;
    padding:15px 16px !important;
    border-radius:18px !important;
    font-size:13px;
    font-weight:900 !important;
    line-height:1.5;
    text-align:center;
    animation:fadeIn .35s ease;
}

@keyframes fadeIn{
    from{opacity:0;transform:translateY(-8px);}
    to{opacity:1;transform:translateY(0);}
}

body.retame-oficial-page .alert-success{
    background:#f0fdf4 !important;
    border:2px solid rgba(34,197,94,0.34) !important;
    color:#15803d !important;
    box-shadow:0 10px 22px rgba(34,197,94,0.09) !important;
}

body.retame-oficial-page .alert-error{
    background:#fff5f7 !important;
    border:2px solid rgba(255,75,92,0.38) !important;
    color:#b91c1c !important;
    box-shadow:0 10px 22px rgba(255,75,92,0.10) !important;
}

body.retame-oficial-page .info-grid{
    display:grid !important;
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:14px !important;
    margin-bottom:26px !important;
}

body.retame-oficial-page .info-item{
    min-width:0;
    padding:16px !important;
    border-radius:20px !important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb) !important;
    border:2px solid rgba(0,153,255,0.26) !important;
    box-shadow:
        0 9px 20px rgba(0,0,0,0.06),
        0 0 0 2px rgba(255,75,92,0.06) !important;
    transition:.25s ease;
}

body.retame-oficial-page .info-item:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,0.70) !important;
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 3px rgba(0,153,255,0.18) !important;
}

body.retame-oficial-page .info-item strong{
    display:block;
    margin-bottom:6px !important;
    color:#111827 !important;
    font-size:11px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.35px;
}

body.retame-oficial-page .info-item span{
    display:block;
    color:#4b5563 !important;
    font-size:14px !important;
    font-weight:600;
    line-height:1.5;
    word-break:break-word;
}

body.retame-oficial-page .info-item span[style]{
    color:var(--azul) !important;
    font-weight:900 !important;
}

body.retame-oficial-page .descripcion-item{
    grid-column:1 / -1 !important;
}

body.retame-oficial-page .resumen-liga-container > div:last-child{
    margin-top:28px !important;
    padding-top:22px !important;
    border-top:1px solid rgba(17,24,39,0.08) !important;
}

body.retame-oficial-page .resumen-liga-container > div:last-child > p:first-child{
    margin-bottom:20px !important;
    color:var(--azul) !important;
    font-weight:800;
}

body.retame-oficial-page .resumen-liga-container > div:last-child > p:last-child{
    margin-top:18px !important;
    color:#6b7280 !important;
}

body.retame-oficial-page .resumen-liga-container > div:last-child > p:last-child strong{
    color:#111827 !important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .user-info,
body.retame-oficial-page.dark-mode .form-liga-container,
body.retame-oficial-page.dark-mode .resumen-liga-container,
body.retame-oficial-page.dark-mode .info-item{
    background:#111827 !important;
    color:#e5e7eb !important;
    border-color:rgba(255,75,92,0.78) !important;
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 18px rgba(0,153,255,0.18) !important;
}

body.retame-oficial-page.dark-mode .main-content > h1,
body.retame-oficial-page.dark-mode .form-liga-container h2,
body.retame-oficial-page.dark-mode .resumen-liga-container h2{
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .user-info p,
body.retame-oficial-page.dark-mode .info-item span{
    color:#d1d5db !important;
}

body.retame-oficial-page.dark-mode .user-info strong,
body.retame-oficial-page.dark-mode .form-group label,
body.retame-oficial-page.dark-mode .info-item strong,
body.retame-oficial-page.dark-mode .resumen-liga-container > div:last-child > p:last-child strong{
    color:#f9fafb !important;
}

body.retame-oficial-page.dark-mode .form-group input[type="text"],
body.retame-oficial-page.dark-mode .form-group input[type="date"],
body.retame-oficial-page.dark-mode .form-group select,
body.retame-oficial-page.dark-mode .form-group textarea{
    background:#0b1220 !important;
    color:#e5e7eb !important;
    border-color:rgba(0,153,255,0.45) !important;
}

body.retame-oficial-page.dark-mode .form-group input::placeholder,
body.retame-oficial-page.dark-mode .form-group textarea::placeholder,
body.retame-oficial-page.dark-mode .form-group small{
    color:#9ca3af !important;
}

body.retame-oficial-page.dark-mode .form-group select option{
    background:#0b1220 !important;
    color:#e5e7eb !important;
}

body.retame-oficial-page.dark-mode .form-liga-container .form-group > div[style]{
    background:
        radial-gradient(circle at 10% 10%,rgba(0,153,255,0.13),transparent 170px),
        #0b1220 !important;
    border-color:rgba(0,153,255,0.45) !important;
    box-shadow:0 8px 20px rgba(0,0,0,0.22) !important;
}

body.retame-oficial-page.dark-mode .form-liga-container .form-group > div[style] > p{
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .form-liga-container .form-group > div[style] > ul{
    color:#d1d5db !important;
}

body.retame-oficial-page.dark-mode .info-item span[style],
body.retame-oficial-page.dark-mode .resumen-liga-container > div:last-child > p:first-child{
    color:var(--cyan) !important;
}

body.retame-oficial-page.dark-mode .resumen-liga-container > div:last-child{
    border-top-color:rgba(255,255,255,0.08) !important;
}

body.retame-oficial-page.dark-mode .resumen-liga-container > div:last-child > p:last-child{
    color:#9ca3af !important;
}

@media screen and (max-width:820px){
    body.retame-oficial-page > .main-content{
        width:100% !important;
        max-width:100% !important;
        padding-top:12px !important;
    }

    body.retame-oficial-page .main-content > h1,
    body.retame-oficial-page .user-info,
    body.retame-oficial-page .form-liga-container,
    body.retame-oficial-page .resumen-liga-container{
        width:100% !important;
        max-width:100% !important;
    }
}

@media screen and (max-width:620px){
    body.retame-oficial-page .main-content > h1{
        margin-bottom:16px !important;
        padding:20px 17px !important;
        border-radius:24px !important;
        text-align:center !important;
        font-size:1.3rem !important;
    }

    body.retame-oficial-page .user-info{
        margin-bottom:16px !important;
        padding:14px 15px !important;
        border-radius:20px !important;
    }

    body.retame-oficial-page .form-liga-container,
    body.retame-oficial-page .resumen-liga-container{
        margin-bottom:18px !important;
        padding:20px 16px !important;
        border-radius:24px !important;
    }

    body.retame-oficial-page .form-liga-container h2,
    body.retame-oficial-page .resumen-liga-container h2{
        margin-bottom:22px !important;
        text-align:center !important;
    }

    body.retame-oficial-page .form-group input[type="text"],
    body.retame-oficial-page .form-group input[type="date"],
    body.retame-oficial-page .form-group select,
    body.retame-oficial-page .form-group textarea{
        font-size:16px !important;
    }

    body.retame-oficial-page .info-grid{
        grid-template-columns:1fr !important;
    }

    body.retame-oficial-page .descripcion-item{
        grid-column:auto !important;
    }
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Liga - RETAME'); } ?>

<!-- BURBUJAS DE FONDO -->
<div class="bg-particles" id="particles"></div>

<!-- Botón hamburguesa solo para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <img src="assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
    <h2>🏥 RETAME</h2>
    <ul class="menu">
        <li><a href="liga.php">🏠 Inicio</a></li>
        <li><a href="UnirmeLiga.php">👥 Unirme a una liga</a></li>
        <li><a href="CrearLiga.php">👥 Crear Una liga</a></li>
        <li><a href="Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<div class="main-content">
    <h1>👤 Perfil de <?php echo $Nombre; ?></h1>
    
    <!-- Información del usuario -->
    <div class="user-info">
        <p><strong>Usuario ID:</strong> <?php echo htmlspecialchars($Id_Retador); ?></p>
        <p><strong>Nombre:</strong> <?php echo $Nombre; ?></p>
    </div>
    
    <!-- Mostrar mensajes -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($liga_creada && $info_liga): ?>
    <!-- RESULTADO: Mostrar información de la liga creada -->
    <div class="resumen-liga-container">
        <h2>🏆 Liga Creada Exitosamente</h2>
        
        <div class="info-grid">
            <div class="info-item">
                <strong>ID de la Liga:</strong>
                <span><?php echo htmlspecialchars($info_liga['Id_Liga'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Nombre de la Liga:</strong>
                <span><?php echo htmlspecialchars($info_liga['Nombre'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Código Postal:</strong>
                <span><?php echo htmlspecialchars($info_liga['CodigoPostal'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Creación:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaCreacion'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaCreacion']));
                    } else {
                        echo date('d/m/Y');
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Inicio:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaInicio'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaInicio']));
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Fecha de Fin:</strong>
                <span>
                    <?php 
                    if (isset($info_liga['FechaFin']) && !empty($info_liga['FechaFin'])) {
                        echo date('d/m/Y', strtotime($info_liga['FechaFin']));
                    } else {
                        echo 'No definida';
                    }
                    ?>
                </span>
            </div>
            
            <div class="info-item">
                <strong>Deporte:</strong>
                <span><?php echo htmlspecialchars($info_liga['NombreDeporte'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-item">
                <strong>Estado Actual:</strong>
                <span style="color: #00ffc6; font-weight: bold;">
                    <?php echo htmlspecialchars($info_liga['Estado'] ?? 'Inscripciones'); ?>
                </span>
            </div>
            
            <?php if (!empty($info_liga['Descripcion'])): ?>
            <div class="info-item descripcion-item">
                <strong>Descripción:</strong>
                <span><?php echo nl2br(htmlspecialchars($info_liga['Descripcion'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <p style="color: #00ffc6; margin-bottom: 20px;">
                ✅ La liga ha sido registrada exitosamente en el sistema.
            </p>
            
            <a href="liga.php" class="btn-finalizar">
                🏁 Finalizar y Volver a Inicio
            </a>
            
            <p style="color: #aaa; margin-top: 20px; font-size: 14px;">
                <small>Guarda el ID de la liga: <strong><?php echo htmlspecialchars($info_liga['Id_Liga'] ?? ''); ?></strong> para futuras referencias.</small>
            </p>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Formulario para crear liga - SOLO SE MUESTRA SI NO HAY LIGA CREADA -->
    <div class="form-liga-container">
        <h2>🏆 Crear Nueva Liga</h2>
        
        <form method="POST" action="" id="formLiga">
            <!-- Nombre de la Liga -->
            <div class="form-group">
                <label for="nombre_liga" class="required">Nombre de la Liga</label>
                <input type="text" 
                       id="nombre_liga" 
                       name="nombre_liga" 
                       value="<?php echo isset($_POST['nombre_liga']) ? htmlspecialchars($_POST['nombre_liga']) : ''; ?>" 
                       placeholder="Ej: Liga de Fútbol Primavera 2024" 
                       maxlength="255" 
                       required
                       oninput="validarNombre(this)">
                <small id="error_nombre" style="color:#ff5555; display:none; margin-top:5px;"></small>
            </div>
            
            <!-- Código Postal -->
            <div class="form-group">
                <label for="codigo_postal" class="required">Código Postal</label>
                <input type="text" 
                       id="codigo_postal" 
                       name="codigo_postal" 
                       value="<?php echo isset($_POST['codigo_postal']) ? htmlspecialchars($_POST['codigo_postal']) : ''; ?>" 
                       placeholder="Ej: 28001" 
                       maxlength="50" 
                       required
                       oninput="validarCodigoPostal(this)">
                <small id="error_cp" style="color:#ff5555; display:none; margin-top:5px;"></small>
            </div>
            
            <!-- Fecha de Inicio -->
            <div class="form-group">
                <label for="fecha_inicio" class="required">Fecha de Inicio</label>
                <input type="date" 
                       id="fecha_inicio" 
                       name="fecha_inicio" 
                       value="<?php echo isset($_POST['fecha_inicio']) ? htmlspecialchars($_POST['fecha_inicio']) : ''; ?>" 
                       min="<?php echo date('Y-m-d'); ?>" 
                       required>
            </div>
            
            <!-- Fecha de Fin (Opcional) -->
            <div class="form-group">
                <label for="fecha_fin">Fecha de Fin (Opcional)</label>
                <input type="date" 
                       id="fecha_fin" 
                       name="fecha_fin" 
                       value="<?php echo isset($_POST['fecha_fin']) ? htmlspecialchars($_POST['fecha_fin']) : ''; ?>" 
                       min="<?php echo date('Y-m-d'); ?>">
                <small style="color: #aaa; display: block; margin-top: 5px;">Si no se especifica, la liga no tendrá fecha de finalización definida.</small>
            </div>
            
            <!-- Selección de Deporte -->
            <div class="form-group">
                <label for="id_deporte" class="required">Deporte</label>
                <select id="id_deporte" name="id_deporte" required>
                    <option value="">-- Selecciona un deporte --</option>
                    <?php foreach ($deportes as $deporte): ?>
                        <option value="<?php echo htmlspecialchars($deporte['Id_Deporte']); ?>"
                            <?php echo (isset($_POST['id_deporte']) && $_POST['id_deporte'] == $deporte['Id_Deporte']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($deporte['Nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Descripción -->
            <div class="form-group">
                <label for="descripcion">Descripción (Opcional)</label>
                <textarea id="descripcion" 
                          name="descripcion" 
                          placeholder="Describe tu liga, reglas especiales, premios, etc."><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
            </div>
            
            <!-- Información generada automáticamente - MODIFICADO -->
            <div class="form-group">
                <div style="background: rgba(0, 255, 198, 0.1); padding: 15px; border-radius: 10px; border: 1px solid rgba(0, 255, 198, 0.3);">
                    <p style="color: #00ffc6; margin: 0; font-weight: bold;">⚠️ Información generada automáticamente:</p>
                    <ul style="color: white; margin: 10px 0 0 20px;">
                        <li><strong>Codigo de  Liga:</strong> Se generará automáticamente </li>
                        <li><strong>Fecha Creación:</strong> <?php echo date('d/m/Y'); ?></li>
                        <li><strong>Creador:</strong> <?php echo $Nombre; ?> (ID: <?php echo htmlspecialchars($Id_Retador); ?>)</li>
                        <li><strong>Estado Inicial:</strong> "Inscripciones"</li>
                    </ul>
                </div>
            </div>
            
            <!-- Botón de enviar -->
            <button type="submit" name="crear_liga" class="btn-submit">
                🏆 Crear Liga
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- BOTONES FLOTANTES FUERA DEL CONTENIDO PRINCIPAL -->
<a href="../Perfil2.php" class="btn-ligas">🏆 INICIO</a>
<a href="Retar/retar.php" class="btn-retar">⚔️ RETAR</a>

<script>
// Crear partículas/burbujas dinámicas
document.addEventListener('DOMContentLoaded', function() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 25;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        // Tamaño aleatorio entre 5px y 25px
        const size = Math.random() * 20 + 5;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        
        // Posición aleatoria
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        
        // Opacidad aleatoria
        particle.style.opacity = Math.random() * 0.2 + 0.1;
        
        // Animación con duración y delay aleatorios
        const duration = Math.random() * 25 + 20;
        const delay = Math.random() * 5;
        particle.style.animation = `float ${duration}s ${delay}s infinite linear`;
        
        particlesContainer.appendChild(particle);
    }
    
    // Configurar fecha mínima para fecha fin
    const fechaInicio = document.getElementById('fecha_inicio');
    const fechaFin = document.getElementById('fecha_fin');
    
    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', function() {
            fechaFin.min = this.value;
            if (fechaFin.value && fechaFin.value < this.value) {
                fechaFin.value = this.value;
            }
        });
    }
});

// Función para mostrar/ocultar sidebar en móviles
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

// Cerrar sidebar al hacer clic en un enlace (en móviles)
document.querySelectorAll('.menu a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// Ajustar sidebar según tamaño de pantalla
window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.overlay');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    }
});

// Validar que fecha fin no sea anterior a fecha inicio
const formLiga = document.getElementById('formLiga');
if (formLiga) {
    formLiga.addEventListener('submit', function(e) {
        const fechaInicio = document.getElementById('fecha_inicio').value;
        const fechaFin = document.getElementById('fecha_fin').value;
        
        if (fechaFin && fechaFin < fechaInicio) {
            e.preventDefault();
            alert('⚠️ La fecha de fin no puede ser anterior a la fecha de inicio');
            return false;
        }
    });
}

// Función para validar nombre en tiempo real
function validarNombre(input) {
    const valor = input.value.trim();
    const errorElement = document.getElementById('error_nombre');
    
    // Si el nombre inicia con número
    if (/^\d/.test(valor)) {
        errorElement.textContent = 'El nombre no puede iniciar con un número';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si el nombre es solo números
    if (/^\d+$/.test(valor)) {
        errorElement.textContent = 'El nombre no puede ser solo números';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si está bien
    errorElement.style.display = 'none';
    input.style.borderColor = 'rgba(0, 255, 198, 0.3)';
    return true;
}

// Función para validar código postal en tiempo real
function validarCodigoPostal(input) {
    const valor = input.value.trim();
    const errorElement = document.getElementById('error_cp');
    
    // Si contiene letras
    if (!/^\d+$/.test(valor) && valor !== '') {
        errorElement.textContent = 'El código postal solo puede contener números';
        errorElement.style.display = 'block';
        input.style.borderColor = '#ff5555';
        return false;
    }
    
    // Si está bien
    errorElement.style.display = 'none';
    input.style.borderColor = 'rgba(0, 255, 198, 0.3)';
    return true;
}

// Validar formulario antes de enviar
if (formLiga) {
    formLiga.addEventListener('submit', function(e) {
        const nombreInput = document.getElementById('nombre_liga');
        const cpInput = document.getElementById('codigo_postal');
        
        // Validar nombre
        if (!validarNombre(nombreInput)) {
            e.preventDefault();
            return false;
        }
        
        // Validar código postal
        if (!validarCodigoPostal(cpInput)) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
}
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>