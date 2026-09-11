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

include_once '../conexion.php';

/* =========================
   VALIDAR SESIÓN
========================= */
if (!isset($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$id_retador_actual = $_SESSION['usuario_data']['Id_Retador'];
$conexion = $conn;

/* =========================
   FUNCIÓN PARA MOSTRAR EQUIPOS DISPONIBLES
========================= */
function mostrarEquiposDisponibles($conexion, $id_retador_actual) {
    $sql = "SELECT 
                et.Id_EquipoTemporal,
                et.Nombre as nombre_equipo,
                et.Id_Deporte,
                d.Nombre as nombre_deporte,
                et.Cantidad,
                et.Capitan,
                r.Nombre as nombre_capitan,
                r.Apellido as apellido_capitan,
                et.Fecha_Creacion,
                et.Estado,
                -- Contar cuántos jugadores ya se han unido
                (SELECT COUNT(*) 
                 FROM equiporetador_temporal ert
                 WHERE ert.Id_EquipoTemporal = et.Id_EquipoTemporal) as jugadores_unidos
            FROM equipo_temporal et
            JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
            JOIN retador r ON et.Capitan = r.Id_Retador
            WHERE et.Estado = 'Esperando'
            -- FILTRAR: NO mostrar equipos donde el usuario ya está
            AND NOT EXISTS (
                SELECT 1 
                FROM equiporetador_temporal ert2
                WHERE ert2.Id_EquipoTemporal = et.Id_EquipoTemporal
                AND ert2.Id_Retador = '$id_retador_actual'
            )
            ORDER BY et.Fecha_Creacion DESC";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    return $resultado;
}

/* =========================
   FUNCIÓN PARA MOSTRAR MIS EQUIPOS
========================= */
function mostrarMisEquipos($conexion, $id_retador_actual) {
    $sql = "SELECT 
                et.Id_EquipoTemporal,
                et.Nombre as nombre_equipo,
                et.Id_Deporte,
                d.Nombre as nombre_deporte,
                et.Cantidad,
                et.Capitan,
                r.Nombre as nombre_capitan,
                r.Apellido as apellido_capitan,
                et.Fecha_Creacion,
                et.Estado,
                -- Contar cuántos jugadores ya se han unido
                (SELECT COUNT(*) 
                 FROM equiporetador_temporal ert
                 WHERE ert.Id_EquipoTemporal = et.Id_EquipoTemporal) as jugadores_unidos
            FROM equipo_temporal et
            JOIN deporte d ON et.Id_Deporte = d.Id_Deporte
            JOIN retador r ON et.Capitan = r.Id_Retador
            WHERE et.Estado = 'Esperando'
            -- FILTRAR: MOSTRAR SOLO equipos donde el usuario ya está
            AND EXISTS (
                SELECT 1 
                FROM equiporetador_temporal ert2
                WHERE ert2.Id_EquipoTemporal = et.Id_EquipoTemporal
                AND ert2.Id_Retador = '$id_retador_actual'
            )
            ORDER BY et.Fecha_Creacion DESC";
    
    $resultado = mysqli_query($conexion, $sql);
    
    if (!$resultado) {
        die("❌ Error en la consulta: " . mysqli_error($conexion));
    }
    
    return $resultado;
}

/* =========================
   FUNCIÓN PARA UNIRSE A UN EQUIPO
========================= */
if (isset($_GET['unirse']) && isset($_GET['equipo'])) {
    $id_equipo = mysqli_real_escape_string($conexion, $_GET['equipo']);
    $id_retador = $_SESSION['usuario_data']['Id_Retador'];
    
    // Verificar que el equipo aún esté disponible
    $sql_verificar = "SELECT Estado, Cantidad FROM equipo_temporal 
                      WHERE Id_EquipoTemporal = '$id_equipo'";
    $result_verificar = mysqli_query($conexion, $sql_verificar);
    $equipo_info = mysqli_fetch_assoc($result_verificar);
    
    if ($equipo_info['Estado'] !== 'Esperando') {
        echo "<script>alert('⚠️ Este equipo ya no está disponible.');</script>";
    } else {
        // Verificar si el usuario ya está en el equipo
        $sql_verificar_miembro = "SELECT * FROM equiporetador_temporal 
                                  WHERE Id_EquipoTemporal = '$id_equipo' 
                                  AND Id_Retador = '$id_retador'";
        $result_miembro = mysqli_query($conexion, $sql_verificar_miembro);
        
        if (mysqli_num_rows($result_miembro) > 0) {
            echo "<script>alert('⚠️ Ya estás en este equipo.');</script>";
        } else {
            // Contar jugadores actuales
            $sql_contar = "SELECT COUNT(*) as total FROM equiporetador_temporal 
                          WHERE Id_EquipoTemporal = '$id_equipo'";
            $result_contar = mysqli_query($conexion, $sql_contar);
            $contador = mysqli_fetch_assoc($result_contar);
            $jugadores_actuales = $contador['total'];
            
            // Obtener deporte del equipo
            $sql_deporte = "SELECT Id_Deporte FROM equipo_temporal 
                           WHERE Id_EquipoTemporal = '$id_equipo'";
            $result_deporte = mysqli_query($conexion, $sql_deporte);
            $deporte_info = mysqli_fetch_assoc($result_deporte);
            $id_deporte = $deporte_info['Id_Deporte'];
            
            // Generar ID para la relación
            $id_relacion = "EQRT" . uniqid() . rand(100, 999);
            
            // Insertar en la tabla de unión
            $sql_insertar = "INSERT INTO equiporetador_temporal
                            (Id_EquipoRetadorTemp, Id_EquipoTemporal, Id_Retador, Id_Deporte)
                            VALUES
                            ('$id_relacion', '$id_equipo', '$id_retador', '$id_deporte')";
            
            if (mysqli_query($conexion, $sql_insertar)) {
                // Verificar si el equipo ya se llenó
                $jugadores_actuales++;
                
               
                
                echo "<script>alert('✅ Te has unido al equipo exitosamente.');</script>";
                echo "<script>window.location.href = 'equipos_disponibles.php';</script>";
                exit;
            } else {
                echo "<script>alert('❌ Error al unirse al equipo: " . mysqli_error($conexion) . "');</script>";
            }
        }
    }
}

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipos Disponibles - RETAME</title>
    <style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

:root{
    --azul:#1877f2;
    --azul2:#0ea5e9;
    --azul-neon:#0099ff;
    --cyan:#8fefff;
    --rojo:#ff4b5c;
    --rojo2:#ff2f45;
    --texto:#111827;
    --gris:#6b7280;
    --blanco:#ffffff;
    --sombra-azul:0 0 0 3px rgba(24,119,242,.22),0 12px 28px rgba(24,119,242,.14);
    --sombra-roja:0 0 0 3px rgba(255,75,92,.22),0 12px 28px rgba(255,75,92,.14);
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Poppins',sans-serif;
}

html,
body{
    width:100%;
    min-height:100%;
}

html body.retame-oficial-page > .sidebar:not(.retame-oficial-sidebar),
html body.retame-oficial-page > .menu-toggle:not(.retame-oficial-menu-toggle),
html body.retame-oficial-page > .overlay:not(.retame-oficial-overlay){
    display:none!important;
}

html body.retame-oficial-page > .main-content{
    position:relative!important;
    z-index:2!important;
    width:min(1180px,100%)!important;
    max-width:1180px!important;
    margin-left:0!important;
    margin-right:auto!important;
    padding-top:16px!important;
}

html body.retame-oficial-page .main-content > .container{
    width:100%!important;
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    background:transparent!important;
    border:none!important;
    border-top:none!important;
    border-radius:0!important;
    box-shadow:none!important;
    backdrop-filter:none!important;
}

html body.retame-oficial-page .page-header{
    position:relative!important;
    overflow:hidden!important;
    width:100%!important;
    margin:0 0 22px!important;
    padding:24px 28px!important;
    border-radius:28px!important;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,.20),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,.17),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,.08),rgba(255,255,255,.02) 46%,rgba(255,75,92,.08)),
        rgba(255,255,255,.94)!important;
    border:1px solid rgba(17,24,39,.05)!important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.10),
        0 0 0 1px rgba(24,119,242,.12),
        0 0 18px rgba(0,153,255,.14)!important;
    text-align:left!important;
}

html body.retame-oficial-page .page-header h1{
    margin:0 0 7px!important;
    padding:0!important;
    color:var(--azul)!important;
    font-family:'Orbitron',sans-serif!important;
    font-size:clamp(1.45rem,4vw,2rem)!important;
    font-weight:700!important;
    line-height:1.25!important;
    text-align:left!important;
    letter-spacing:0!important;
    text-shadow:none!important;
}

html body.retame-oficial-page .page-header .subtitle{
    margin:0!important;
    color:#6b7280!important;
    font-size:13px!important;
    font-weight:600!important;
    line-height:1.5!important;
}

html body.retame-oficial-page .controls{
    display:flex!important;
    justify-content:space-between!important;
    align-items:center!important;
    gap:12px!important;
    flex-wrap:wrap!important;
    margin:0 0 20px!important;
    padding:16px!important;
    border-radius:20px!important;
    background:rgba(255,255,255,.94)!important;
    border:2px solid rgba(0,153,255,.22)!important;
    box-shadow:0 10px 22px rgba(0,0,0,.06)!important;
}

html body.retame-oficial-page .btn{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-height:46px!important;
    padding:10px 17px!important;
    border:none!important;
    border-radius:16px!important;
    background:linear-gradient(135deg,#1877f2,#0ea5e9)!important;
    color:#ffffff!important;
    font-family:'Poppins',sans-serif!important;
    font-size:13px!important;
    font-weight:900!important;
    text-decoration:none!important;
    cursor:pointer!important;
    box-shadow:0 9px 18px rgba(24,119,242,.18)!important;
    transition:.22s ease!important;
}

html body.retame-oficial-page .btn:hover{
    transform:translateY(-2px)!important;
    box-shadow:0 13px 24px rgba(24,119,242,.24)!important;
}

html body.retame-oficial-page .btn-secondary{
    background:#ffffff!important;
    color:var(--rojo2)!important;
    border:2px solid rgba(255,75,92,.32)!important;
    box-shadow:0 8px 16px rgba(255,75,92,.08)!important;
}

html body.retame-oficial-page .tabs{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
    margin:0 0 22px!important;
    padding:0!important;
    border:none!important;
    border-bottom:none!important;
    border-radius:0!important;
    background:transparent!important;
    overflow:visible!important;
}

html body.retame-oficial-page .tab-btn{
    position:relative!important;
    min-height:48px!important;
    padding:11px 14px!important;
    border-radius:16px!important;
    border:2px solid rgba(0,153,255,.20)!important;
    background:#ffffff!important;
    color:#374151!important;
    font-size:13px!important;
    font-weight:900!important;
    cursor:pointer!important;
    text-align:center!important;
    box-shadow:0 6px 14px rgba(0,0,0,.04)!important;
    transition:.22s ease!important;
}

html body.retame-oficial-page .tab-btn:hover:not(.active){
    transform:translateY(-2px)!important;
    background:#ffffff!important;
    color:var(--azul)!important;
    border-color:rgba(255,75,92,.55)!important;
}

html body.retame-oficial-page .tab-btn.active{
    background:#f8fbff!important;
    color:var(--azul)!important;
    border-color:var(--azul-neon)!important;
    box-shadow:var(--sombra-azul)!important;
}

html body.retame-oficial-page .tab-btn.active::after{
    display:none!important;
}

html body.retame-oficial-page .tab-content{
    display:none;
}

html body.retame-oficial-page .tab-content.active{
    display:block;
}

html body.retame-oficial-page .mis-equipos-header{
    margin:0 0 20px!important;
    padding:18px!important;
    border-radius:20px!important;
    background:rgba(24,119,242,.055)!important;
    border:2px dashed rgba(0,153,255,.24)!important;
    text-align:left!important;
}

html body.retame-oficial-page .mis-equipos-header h3{
    margin:0 0 6px!important;
    color:var(--azul)!important;
    font-family:'Orbitron',sans-serif!important;
    font-size:clamp(1.05rem,3vw,1.35rem)!important;
    font-weight:700!important;
    text-shadow:none!important;
}

html body.retame-oficial-page .mis-equipos-header p{
    margin:0!important;
    color:#6b7280!important;
    font-size:13px!important;
    line-height:1.5!important;
}

html body.retame-oficial-page .equipos-grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fill,minmax(280px,1fr))!important;
    gap:16px!important;
    margin-top:0!important;
}

html body.retame-oficial-page .equipo-card{
    position:relative!important;
    overflow:hidden!important;
    min-width:0!important;
    min-height:0!important;
    padding:0!important;
    border-radius:22px!important;
    background:linear-gradient(180deg,#ffffff,#fbfbfb)!important;
    border:2px solid rgba(255,75,92,.62)!important;
    border-top:2px solid rgba(255,75,92,.62)!important;
    box-shadow:
        0 10px 22px rgba(0,0,0,.07),
        0 0 0 2px rgba(0,153,255,.18),
        0 0 14px rgba(0,153,255,.12)!important;
    backdrop-filter:none!important;
    transition:.22s ease!important;
}

html body.retame-oficial-page .equipo-card:hover{
    transform:translateY(-3px)!important;
    border-color:rgba(255,75,92,.95)!important;
    box-shadow:
        0 14px 28px rgba(0,0,0,.10),
        0 0 0 3px rgba(0,153,255,.23),
        0 0 18px rgba(0,153,255,.16)!important;
}

html body.retame-oficial-page .equipo-header{
    padding:18px!important;
    background:
        linear-gradient(135deg,rgba(24,119,242,.96),rgba(14,165,233,.96))!important;
    color:#ffffff!important;
    text-align:left!important;
}

html body.retame-oficial-page .equipo-nombre{
    margin:0 0 5px!important;
    color:#ffffff!important;
    font-size:16px!important;
    font-weight:900!important;
    line-height:1.3!important;
    text-shadow:none!important;
}

html body.retame-oficial-page .equipo-deporte{
    color:rgba(255,255,255,.90)!important;
    font-size:11px!important;
    font-weight:800!important;
}

html body.retame-oficial-page .equipo-body{
    padding:17px!important;
    background:transparent!important;
}

html body.retame-oficial-page .equipo-info{
    margin-bottom:14px!important;
}

html body.retame-oficial-page .info-item{
    display:flex!important;
    justify-content:space-between!important;
    align-items:flex-start!important;
    gap:12px!important;
    margin:0!important;
    padding:9px 0!important;
    border:none!important;
    border-bottom:1px solid rgba(17,24,39,.08)!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
    min-height:0!important;
}

html body.retame-oficial-page .info-item:last-child{
    border-bottom:none!important;
}

html body.retame-oficial-page .info-label{
    color:#6b7280!important;
    font-size:10px!important;
    font-weight:800!important;
    text-transform:none!important;
    letter-spacing:0!important;
}

html body.retame-oficial-page .info-value{
    color:#111827!important;
    font-size:12px!important;
    font-weight:900!important;
    text-align:right!important;
    word-break:break-word!important;
}

html body.retame-oficial-page .info-value[style]{
    color:var(--azul)!important;
}

html body.retame-oficial-page .jugadores-progress{
    margin:16px 0 0!important;
}

html body.retame-oficial-page .progress-bar{
    height:9px!important;
    margin-bottom:7px!important;
    border-radius:999px!important;
    overflow:hidden!important;
    background:rgba(24,119,242,.10)!important;
}

html body.retame-oficial-page .progress-fill{
    height:100%!important;
    border-radius:999px!important;
    background:linear-gradient(90deg,#1877f2,#0ea5e9,#ff4b5c)!important;
    transition:width .5s ease!important;
}

html body.retame-oficial-page .progress-text{
    display:flex!important;
    justify-content:space-between!important;
    gap:10px!important;
    color:#6b7280!important;
    font-size:10px!important;
    font-weight:700!important;
}

html body.retame-oficial-page .equipo-footer{
    padding:14px 17px 17px!important;
    border-top:1px solid rgba(17,24,39,.08)!important;
    background:#fbfdff!important;
    text-align:center!important;
}

html body.retame-oficial-page .btn-unirse,
html body.retame-oficial-page .btn-retar,
html body.retame-oficial-page .btn-ya-unido{
    width:100%!important;
    min-height:46px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:10px 16px!important;
    border:none!important;
    border-radius:16px!important;
    color:#ffffff!important;
    font-size:13px!important;
    font-weight:900!important;
    text-decoration:none!important;
    transition:.22s ease!important;
}

html body.retame-oficial-page .btn-unirse,
html body.retame-oficial-page .btn-retar{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045)!important;
    box-shadow:var(--sombra-roja)!important;
    cursor:pointer!important;
}

html body.retame-oficial-page .btn-unirse:hover,
html body.retame-oficial-page .btn-retar:hover{
    transform:translateY(-2px)!important;
    box-shadow:
        0 0 0 3px rgba(255,75,92,.28),
        0 13px 24px rgba(255,75,92,.20)!important;
    opacity:1!important;
}

html body.retame-oficial-page .btn-ya-unido,
html body.retame-oficial-page .btn-unirse:disabled,
html body.retame-oficial-page .btn-retar:disabled{
    background:#cbd5e1!important;
    color:#64748b!important;
    box-shadow:none!important;
    cursor:not-allowed!important;
    transform:none!important;
}

html body.retame-oficial-page .empty-state{
    min-height:220px!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    text-align:center!important;
    padding:35px 20px!important;
    border-radius:22px!important;
    background:rgba(24,119,242,.05)!important;
    border:2px dashed rgba(0,153,255,.28)!important;
    color:#6b7280!important;
}

html body.retame-oficial-page .empty-icon{
    margin-bottom:12px!important;
    color:var(--rojo2)!important;
    font-size:2.4rem!important;
    opacity:.85!important;
}

html body.retame-oficial-page .empty-text{
    margin:0 0 7px!important;
    color:#111827!important;
    font-size:16px!important;
    font-weight:900!important;
}

html body.retame-oficial-page .empty-state p{
    color:#6b7280!important;
    font-size:12px!important;
    line-height:1.5!important;
}

html body.retame-oficial-page.dark-mode .page-header,
html body.retame-oficial-page.dark-mode .controls,
html body.retame-oficial-page.dark-mode .equipo-card{
    background:#111827!important;
    color:#e5e7eb!important;
    border-color:rgba(255,75,92,.76)!important;
    border-top-color:rgba(255,75,92,.76)!important;
    box-shadow:
        0 10px 24px rgba(0,0,0,.28),
        0 0 0 2px rgba(0,153,255,.22),
        0 0 18px rgba(0,153,255,.16)!important;
}

html body.retame-oficial-page.dark-mode .page-header h1,
html body.retame-oficial-page.dark-mode .mis-equipos-header h3{
    color:var(--cyan)!important;
}

html body.retame-oficial-page.dark-mode .page-header .subtitle,
html body.retame-oficial-page.dark-mode .mis-equipos-header p,
html body.retame-oficial-page.dark-mode .info-label,
html body.retame-oficial-page.dark-mode .progress-text,
html body.retame-oficial-page.dark-mode .empty-state p{
    color:#cbd5e1!important;
}

html body.retame-oficial-page.dark-mode .tab-btn,
html body.retame-oficial-page.dark-mode .btn-secondary{
    background:#0b1220!important;
    color:#e5e7eb!important;
    border-color:rgba(77,184,255,.32)!important;
}

html body.retame-oficial-page.dark-mode .tab-btn.active{
    color:var(--cyan)!important;
    border-color:rgba(0,153,255,.95)!important;
}

html body.retame-oficial-page.dark-mode .mis-equipos-header{
    background:rgba(14,165,233,.07)!important;
    border-color:rgba(77,184,255,.28)!important;
}

html body.retame-oficial-page.dark-mode .equipo-body,
html body.retame-oficial-page.dark-mode .equipo-footer{
    background:#111827!important;
}

html body.retame-oficial-page.dark-mode .equipo-footer,
html body.retame-oficial-page.dark-mode .info-item{
    border-color:rgba(255,255,255,.08)!important;
}

html body.retame-oficial-page.dark-mode .info-value,
html body.retame-oficial-page.dark-mode .empty-text{
    color:#f8fafc!important;
}

html body.retame-oficial-page.dark-mode .info-value[style]{
    color:var(--cyan)!important;
}

html body.retame-oficial-page.dark-mode .progress-bar{
    background:rgba(77,184,255,.12)!important;
}

html body.retame-oficial-page.dark-mode .empty-state{
    background:rgba(14,165,233,.06)!important;
    border-color:rgba(77,184,255,.24)!important;
}

@media(max-width:820px){
    html body.retame-oficial-page > .main-content{
        width:100%!important;
        max-width:100%!important;
        padding-top:12px!important;
    }

    html body.retame-oficial-page .equipos-grid{
        grid-template-columns:repeat(auto-fill,minmax(250px,1fr))!important;
    }
}

@media(max-width:620px){
    html body.retame-oficial-page .page-header{
        margin-bottom:16px!important;
        padding:20px 17px!important;
        border-radius:24px!important;
        text-align:center!important;
    }

    html body.retame-oficial-page .page-header h1{
        text-align:center!important;
        font-size:1.3rem!important;
    }

    html body.retame-oficial-page .page-header .subtitle{
        text-align:center!important;
    }

    html body.retame-oficial-page .controls{
        flex-direction:column!important;
        align-items:stretch!important;
    }

    html body.retame-oficial-page .controls .btn{
        width:100%!important;
    }

    html body.retame-oficial-page .tabs{
        grid-template-columns:1fr!important;
    }

    html body.retame-oficial-page .equipos-grid{
        grid-template-columns:1fr!important;
    }

    html body.retame-oficial-page .info-item{
        flex-direction:column!important;
        gap:3px!important;
    }

    html body.retame-oficial-page .info-value{
        text-align:left!important;
    }
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Equipos Disponibles - RETAME'); } ?>

    <!-- Botón hamburguesa solo para móviles -->
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Overlay para cerrar sidebar en móviles -->
    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <img src="../assets/doctor.png" class="doctor-logo" alt="Logo Doctor">
        <h2>🏥 RETAME</h2>
        <ul class="menu">
            <li><a href="../Perfil2.php">🏠 Inicio</a></li>
            <li><a href="../agendar.html">📅 Agendar Reta</a></li>
            <li><a href="../mis_citas.html">📋 Mis Retas</a></li>
            <li><a href="../Configurar mi perfil.php">👤 Configurar Perfil</a></li> 
            <li><a href="equipos_disponibles.php">👥 Equipos Disponibles</a></li> 
            <li><a href="../lougout.php">🚪 Cerrar Sesión</a></li> 
        </ul>
    </div>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>⚽ Equipos Temporales</h1>
                <p class="subtitle">Gestiona tu participación en equipos temporales</p>
            </div>
            
            <div class="controls">
                <a href="../Perfil2.php" class="btn btn-secondary">← Volver al Perfil</a>
                <div>
                    <a href="crear_equipo_temporal.php" class="btn">➕ Crear Nuevo Equipo</a>
                </div>
            </div>
            
            <!-- PESTAÑAS -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('disponibles')">📋 Equipos Disponibles</button>
                <button class="tab-btn" onclick="switchTab('misequipos')">✅ Mis Equipos</button>
            </div>
            
            <!-- PESTAÑA 1: EQUIPOS DISPONIBLES -->
            <div id="disponibles" class="tab-content active">
                <?php
                $equipos_disponibles = mostrarEquiposDisponibles($conexion, $id_retador_actual);
                
                if (mysqli_num_rows($equipos_disponibles) > 0) {
                ?>
                <div class="equipos-grid">
                    <?php while ($equipo = mysqli_fetch_assoc($equipos_disponibles)) { 
                        $porcentaje = ($equipo['jugadores_unidos'] / $equipo['Cantidad']) * 100;
                    ?>
                    <div class="equipo-card">
                        <div class="equipo-header">
                            <div class="equipo-nombre"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></div>
                            <div class="equipo-deporte"><?php echo htmlspecialchars($equipo['nombre_deporte']); ?></div>
                        </div>
                        
                        <div class="equipo-body">
                            <div class="equipo-info">
                                <div class="info-item">
                                    <span class="info-label">Capitán:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($equipo['nombre_capitan'] . ' ' . $equipo['apellido_capitan']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Estado:</span>
                                    <span class="info-value" style="color: #28a745;"><?php echo htmlspecialchars($equipo['Estado']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Fecha creación:</span>
                                    <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($equipo['Fecha_Creacion'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="jugadores-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <span>Jugadores unidos</span>
                                    <span><strong><?php echo $equipo['jugadores_unidos']; ?></strong> de <?php echo $equipo['Cantidad']; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="equipo-footer">
                            <form method="GET" style="display: inline;">
                                <input type="hidden" name="equipo" value="<?php echo $equipo['Id_EquipoTemporal']; ?>">
                                <button type="submit" name="unirse" value="1" class="btn-unirse">
                                    🤝 Unirse a este equipo
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <?php } else { ?>
                <div class="empty-state">
                    <div class="empty-icon">🏐</div>
                    <h2 class="empty-text">No hay equipos disponibles para unirte</h2>
                    <p>Ya estás en todos los equipos disponibles o no hay equipos creados</p>
                    <a href="crear_equipo_temporal.php" class="btn" style="margin-top: 20px;">Crear nuevo equipo</a>
                </div>
                <?php } ?>
            </div>
            
            <!-- PESTAÑA 2: MIS EQUIPOS -->
            <div id="misequipos" class="tab-content">
                <div class="mis-equipos-header">
                    <h3>✅ Equipos en los que participas</h3>
                    <p>Estos son los equipos temporales a los que ya te has unido</p>
                </div>
                
                <?php
                $mis_equipos = mostrarMisEquipos($conexion, $id_retador_actual);
                
                if (mysqli_num_rows($mis_equipos) > 0) {
                ?>
                <div class="equipos-grid">
                    <?php while ($equipo = mysqli_fetch_assoc($mis_equipos)) { 
                        $porcentaje = ($equipo['jugadores_unidos'] / $equipo['Cantidad']) * 100;
                        // Determinar si es capitán
                        $es_capitan = ($equipo['Capitan'] == $id_retador_actual);
                        // Determinar si el equipo está lleno
                        $equipo_lleno = ($equipo['jugadores_unidos'] >= $equipo['Cantidad']);
                        // Determinar si debe mostrar botón "Retar"
                        $mostrar_retar = ($es_capitan && $equipo_lleno);
                    ?>
                    <div class="equipo-card">
                        <div class="equipo-header">
                            <div class="equipo-nombre"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></div>
                            <div class="equipo-deporte"><?php echo htmlspecialchars($equipo['nombre_deporte']); ?></div>
                        </div>
                        
                        <div class="equipo-body">
                            <div class="equipo-info">
                                <div class="info-item">
                                    <span class="info-label">Capitán:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($equipo['nombre_capitan'] . ' ' . $equipo['apellido_capitan']); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Tu rol:</span>
                                    <span class="info-value" style="color: <?php echo $es_capitan ? '#ffd700' : '#43e97b'; ?>;">
                                        <?php echo $es_capitan ? '👑 Capitán' : '🤝 Jugador'; ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Estado:</span>
                                    <span class="info-value" style="color: <?php echo $equipo_lleno ? '#43e97b' : '#28a745'; ?>;">
                                        <?php echo $equipo_lleno ? 'Completo' : htmlspecialchars($equipo['Estado']); ?>
                                    </span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Fecha creación:</span>
                                    <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($equipo['Fecha_Creacion'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="jugadores-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%; background: <?php echo $equipo_lleno ? '#43e97b' : '#4facfe'; ?>;"></div>
                                </div>
                                <div class="progress-text">
                                    <span>Jugadores unidos</span>
                                    <span><strong><?php echo $equipo['jugadores_unidos']; ?></strong> de <?php echo $equipo['Cantidad']; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="equipo-footer">
                            <?php if ($mostrar_retar): ?>
                                <!-- Botón RETAR solo para capitán cuando el equipo está lleno -->
                              <a href="../Retar/selecciondeopcionestemp.php"<?php echo $equipo['Id_EquipoTemporal']; ?>" class="btn-retar">
    🏆 Retar
</a>
                            <?php else: ?>
                                <!-- Botón normal para jugadores o equipos no llenos -->
                                <button class="btn-ya-unido" disabled>
                                    <?php if ($es_capitan && !$equipo_lleno): ?>
                                        ⏳ Esperando jugadores (<?php echo $equipo['jugadores_unidos']; ?>/<?php echo $equipo['Cantidad']; ?>)
                                    <?php else: ?>
                                        ✅ Ya estás en este equipo
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <?php } else { ?>
                <div class="empty-state">
                    <div class="empty-icon">🤔</div>
                    <h2 class="empty-text">No estás en ningún equipo temporal</h2>
                    <p>Únete a un equipo disponible o crea uno nuevo</p>
                    <button class="btn" style="margin-top: 20px;" onclick="switchTab('disponibles')">Ver equipos disponibles</button>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <script>
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
        
        // Función para cambiar pestañas
        function switchTab(tabName) {
            // Ocultar todas las pestañas
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Desactivar todos los botones de pestaña
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar la pestaña seleccionada
            document.getElementById(tabName).classList.add('active');
            
            // Activar el botón de la pestaña seleccionada
            event.target.classList.add('active');
        }
        
        // Confirmación al unirse a un equipo
        document.querySelectorAll('.btn-unirse').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('¿Estás seguro de que quieres unirte a este equipo?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Confirmación al hacer clic en Retar
        document.querySelectorAll('.btn-retar').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('¿Estás seguro de que quieres retar con este equipo?')) {
                    e.preventDefault();
                }
            });
        });
    </script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>