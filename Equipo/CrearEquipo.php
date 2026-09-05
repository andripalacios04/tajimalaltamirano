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

include "../conexion.php";

// VALIDAMOS QUE HAYA LOGIN
if (!isset($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';
$id_retador = isset($usuarios['Id_Retador']) ? $usuarios['Id_Retador'] : '';



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















// Obtener deportes de tipo equipo
$deportes = [];
$res = $conn->query("SELECT Id_Deporte, Nombre FROM deporte WHERE Tipo='Equipo'");
while($fila = $res->fetch_assoc()) {
    $deportes[] = $fila;
}

// FUNCION GENERAR ID DEL EQUIPO
function generarId($nombre, $conn) {
    $base = str_replace(' ', '', $nombre);
    do {
        $random = rand(100, 999);
        $id_equipo = $base . $random;
        $check = mysqli_query($conn, "SELECT Id_Equipo FROM equipo WHERE Id_Equipo='$id_equipo'");
    } while(mysqli_num_rows($check) > 0);
    return $id_equipo;
}

// FUNCION GENERAR ID DEL EQUIPO_JUGADOR
function generarIdEquipoJugador($id_equipo, $id_jugador, $conn) {
    $id_ej = $id_jugador . $id_equipo;
    $check = mysqli_query($conn, "SELECT Id_EquipoJugador FROM equipo_jugador WHERE Id_EquipoJugador='$id_ej'");
    if(mysqli_num_rows($check) > 0){
        $id_ej .= rand(10,99);
    }
    return $id_ej;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre = mysqli_real_escape_string($conn, $_POST['nombre']);
    $cp = mysqli_real_escape_string($conn, $_POST['cp']);
    $pais = mysqli_real_escape_string($conn, $_POST['pais']);
    $id_deporte = $_POST['id_deporte'];
    $cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;

    // Validar que la cantidad no sea negativa
    if ($cantidad < 0) {
        $error = "La cantidad no puede ser un número negativo";
    }

    $id_equipo = generarId($nombre, $conn);

    // Insertar equipo con la cantidad
    $sql = "INSERT INTO equipo (Id_Equipo, Nombre, CodigoPostal, Pais, Id_Deporte, cantidad, Capitan, Entrenador, Asistente1, Asistente2, Asistente3, Asistente4)
            VALUES ('$id_equipo','$nombre','$cp','$pais','$id_deporte','$cantidad','$id_retador',NULL,NULL,NULL,NULL,NULL)";

    if(mysqli_query($conn, $sql)) {

        mysqli_query($conn, "UPDATE retador SET estado_equipo = TRUE WHERE Id_Retador='$id_retador'");

        $id_ej = generarIdEquipoJugador($id_equipo, $id_retador, $conn);
        $tipo = "Capitan";

        $sql_ej = "INSERT INTO equipo_jugador (Id_EquipoJugador, Id_Equipo, Id_Jugador, tipo)
                   VALUES ('$id_ej','$id_equipo','$id_retador','$tipo')";
        if(!mysqli_query($conn, $sql_ej)) {
            die("❌ Error al registrar en equipo_jugador: " . mysqli_error($conn));
        }

        $cap = mysqli_query($conn,"SELECT Nombre, Apellido FROM retador WHERE Id_Retador='$id_retador'");
        $capData = mysqli_fetch_assoc($cap);
        $nombreCapitan = $capData['Nombre']." ".$capData['Apellido'];

        // Mostrar mensaje de éxito en el mismo diseño
        $success = true;
        $success_message = "Equipo creado exitosamente!";
        $equipo_id = $id_equipo;
        $equipo_cantidad = $cantidad;
        $capitan_nombre = $nombreCapitan;
        
    } else {
        $error = "❌ Error al crear el equipo: " . mysqli_error($conn);
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
<title>Crear Equipo - RETAME</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { 
    margin:0; 
    padding:0; 
    box-sizing:border-box; 
    font-family:'Poppins', sans-serif; 
}

body { 
    display:flex; 
    min-height:100vh; 
    background:linear-gradient(135deg,#140f27,#203a43,#1c2a92); 
    color:#eee; 
    overflow-x:hidden; 
    position: relative;
}

/* BURBUJAS/PARTÍCULAS DE FONDO */
.bg-particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
    overflow: hidden;
}

.particle {
    position: absolute;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    animation: float 20s infinite linear;
}

@keyframes float {
    0%, 100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-100vh) rotate(180deg);
    }
}

/* SIDEBAR */
.sidebar { 
    width:250px; 
    background:#111820; 
    padding:20px; 
    display:flex; 
    flex-direction:column; 
    align-items:center; 
    box-shadow:5px 0 20px rgba(9,5,138,0.7);
    position:fixed;
    height:100vh;
    z-index:1000;
}

.sidebar h2 { 
    color:#fff; 
    margin-bottom:20px; 
    text-align:center;
    font-size:18px;
}

.sidebar img { 
    width:90px; 
    height:90px;
    margin-bottom:10px; 
    border-radius:50%;
    border:3px solid #00ffc6;
}

.menu { 
    list-style:none; 
    width:100%; 
    margin-top:20px;
}

.menu li { 
    padding:12px; 
    margin:10px 0; 
    border-radius:8px; 
    background:rgba(0,255,198,0.1); 
    text-align:center; 
    transition:all 0.3s ease;
    border:1px solid rgba(0,255,198,0.2);
}

.menu li a { 
    color:#fff; 
    text-decoration:none; 
    font-weight:bold; 
    display:block; 
    font-size:14px;
}

.menu li:hover { 
    background:rgba(244,16,16,0.3); 
    transform:translateX(5px);
    border-color:rgba(244,16,16,0.5);
}

/* CONTENIDO PRINCIPAL */
.main-content { 
    flex:1; 
    padding:40px; 
    margin-left:250px;
    min-height:100vh;
    position: relative;
    z-index: 1;
}

.main-content h1 {
    font-size:32px;
    color:#ffffff;
    margin-bottom:30px;
    text-align:center;
}

/* TARJETA DE FORMULARIO */
.form-card { 
    background:rgba(27,31,39,0.9); 
    padding:40px; 
    border-radius:20px; 
    box-shadow:0 0 30px rgba(0,26,255,0.5);
    max-width:900px;
    margin:0 auto;
    text-align:center;
    backdrop-filter:blur(10px);
    border:1px solid rgba(0,26,255,0.2);
}

.form-card h3 {
    color:#00ffc6;
    margin-bottom:30px;
    font-size:24px;
}

/* GRUPOS DE FORMULARIO */
.form-group {
    margin-bottom:25px;
    text-align:left;
}

.form-group label {
    display:block;
    margin-bottom:10px;
    font-weight:600;
    color:#00ffc6;
    font-size:1.1rem;
}

.form-group input,
.form-group select {
    width:100%;
    padding:15px 20px;
    background:rgba(255,255,255,0.08);
    border:2px solid rgba(255,255,255,0.2);
    border-radius:15px;
    color:#fff;
    font-size:1rem;
    transition:all 0.3s ease;
}

.form-group input:focus,
.form-group select:focus {
    outline:none;
    border-color:#00ffc6;
    box-shadow:0 0 15px rgba(0,255,198,0.3);
}

.form-group input::placeholder {
    color:#aaa;
}

/* SLIDER DE CANTIDAD */
.quantity-info {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-top:10px;
    color:#cccccc;
    font-size:0.9rem;
}

.quantity-slider {
    width:100%;
    margin-top:10px;
    -webkit-appearance:none;
    height:8px;
    background:rgba(255,255,255,0.1);
    border-radius:4px;
    outline:none;
}

.quantity-slider::-webkit-slider-thumb {
    -webkit-appearance:none;
    width:22px;
    height:22px;
    border-radius:50%;
    background:#00ffc6;
    cursor:pointer;
}

.quantity-value {
    font-weight:600;
    color:#00ffc6;
    font-size:1.2rem;
}

/* BOTÓN DE ENVÍO */
.submit-button {
    width:100%;
    padding:18px;
    background:linear-gradient(135deg,#ff0000,#e00000);
    border:none;
    border-radius:15px;
    color:#fff;
    font-weight:700;
    font-size:1.2rem;
    cursor:pointer;
    transition:all 0.3s ease;
    margin-top:20px;
    text-transform:uppercase;
    letter-spacing:1px;
}

.submit-button:hover {
    transform:translateY(-3px);
    box-shadow:0 10px 25px rgba(255,0,0,0.4);
}

/* CAJA DE INFORMACIÓN */
.info-box {
    background:rgba(0,255,198,0.1);
    border-left:4px solid #00ffc6;
    padding:20px;
    border-radius:10px;
    margin-top:30px;
    text-align:left;
}

.info-box h3 {
    color:#00ffc6;
    margin-bottom:10px;
    font-size:1.3rem;
}

.info-box p {
    color:#cccccc;
    font-size:0.95rem;
    line-height:1.5;
}

/* MENSAJES DE ERROR Y ÉXITO */
.error-message {
    background:rgba(255,0,0,0.1);
    border:1px solid rgba(255,0,0,0.3);
    border-radius:15px;
    padding:20px;
    margin:20px 0;
    color:#ff9999;
    text-align:center;
    display:none;
}

.error-message.show {
    display:block;
}

.success-message {
    background:rgba(0,255,0,0.1);
    border:1px solid rgba(0,255,0,0.3);
    border-radius:15px;
    padding:30px;
    margin:20px 0;
    color:#99ff99;
    text-align:center;
}

.success-info {
    background:rgba(27,31,39,0.9);
    padding:20px;
    border-radius:10px;
    margin:15px 0;
    border:1px solid rgba(0,255,198,0.2);
}

.success-info p {
    margin:10px 0;
    font-size:1.1rem;
}

.success-info strong {
    color:#00ffc6;
}

.success-button {
    background:linear-gradient(135deg,#00ffc6,#00cc9d);
    color:#000;
    border:none;
    padding:15px 30px;
    border-radius:10px;
    font-weight:bold;
    cursor:pointer;
    transition:all 0.3s ease;
    margin-top:20px;
    text-decoration:none;
    display:inline-block;
}

.success-button:hover {
    transform:translateY(-3px);
    box-shadow:0 10px 25px rgba(0,255,198,0.4);
}

/* BOTONES FLOTANTES */
.btn-retar {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg,#ff0000,#e00000);
    color: #fff;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 16px;
    font-weight: bold;
    text-decoration: none;
    box-shadow: 0 0 20px rgba(255,0,0,0.7);
    transition: all 0.3s ease;
    z-index: 999;
    border: 2px solid rgba(255,255,255,0.3);
}

.btn-ligas {
    position: fixed;
    bottom: 130px;
    right: 20px;
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg,#00aaff,#0066cc);
    color: #fff;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 16px;
    font-weight: bold;
    text-decoration: none;
    box-shadow: 0 0 20px rgba(0,100,255,0.7);
    transition: all 0.3s ease;
    z-index: 998;
    border: 2px solid rgba(255,255,255,0.3);
}

.btn-retar:hover, .btn-ligas:hover {
    transform: scale(1.1);
    box-shadow: 0 0 30px rgba(255,0,0,0.9);
}

.btn-ligas:hover {
    box-shadow: 0 0 30px rgba(0,100,255,0.9);
}

/* RESPONSIVE */
@media screen and (max-width: 1024px) {
    .sidebar {
        width: 200px;
    }
    .main-content {
        margin-left: 200px;
        padding: 20px;
    }
    .form-card {
        margin: 20px auto;
        padding: 30px;
    }
}

@media screen and (max-width: 768px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: -250px;
        width: 250px;
        height: 100%;
        transition: left 0.3s ease;
        z-index: 1000;
    }
    .sidebar.active {
        left: 0;
    }
    .main-content {
        margin-left: 0;
        padding: 20px;
    }
    .btn-retar, .btn-ligas {
        width: 70px;
        height: 70px;
        font-size: 14px;
        bottom: 15px;
        right: 15px;
    }
    .btn-ligas {
        bottom: 100px;
    }
    
    /* Botón hamburguesa para móviles */
    .menu-toggle {
        position: fixed;
        top: 20px;
        left: 20px;
        background: rgba(255, 0, 0, 0.8);
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 5px;
        cursor: pointer;
        z-index: 1001;
        font-size: 1.2rem;
    }
}

@media screen and (max-width: 480px) {
    .form-card {
        padding: 20px;
    }
    .form-group input,
    .form-group select {
        padding: 12px 15px;
    }
    .submit-button {
        padding: 15px;
        font-size: 1.1rem;
    }
}

/* OVERLAY PARA MÓVIL */
.overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 999;
}
.overlay.active {
    display: block;
}
.btn-retar{position:fixed;bottom:20px;right:20px;width:90px;height:90px;background:linear-gradient(135deg,#ff0000,#e00000);color:#fff;border-radius:50%;display:flex;justify-content:center;align-items:center;font-size:16px;font-weight:bold;text-decoration:none;box-shadow:0 0 20px rgba(255,0,0,0.7);transition:all 0.3s ease;z-index:999;border:2px solid rgba(255,255,255,0.3);}
.btn-ligas{position:fixed;bottom:130px;right:20px;width:90px;height:90px;background:linear-gradient(135deg,#00aaff,#0066cc);color:#fff;border-radius:50%;display:flex;justify-content:center;align-items:center;font-size:16px;font-weight:bold;text-decoration:none;box-shadow:0 0 20px rgba(0,100,255,0.7);transition:all 0.3s ease;z-index:998;border:2px solid rgba(255,255,255,0.3);}
.btn-retar:hover,.btn-ligas:hover{transform:scale(1.1);box-shadow:0 0 30px rgba(255,0,0,0.9);}
.btn-ligas:hover{box-shadow:0 0 30px rgba(0,100,255,0.9);}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>

<style id="retame-equipo-fix-css">
body.retame-oficial-page .btn-retar,
body.retame-oficial-page .btn-ligas,
body.retame-oficial-page .bg-particles,
body.retame-oficial-page .particle{
    display:none!important;
}
body.retame-oficial-page > .main-content,
body.retame-oficial-page > .container{
    width:min(1180px,100%)!important;
    max-width:1180px!important;
    margin:0 auto!important;
    padding:18px 0 26px!important;
    position:relative!important;
    z-index:2!important;
}
body.retame-oficial-page > .container .main-content{
    margin:0!important;
    width:100%!important;
    max-width:100%!important;
}
body.retame-oficial-page h1{
    color:#1877f2!important;
    text-align:left!important;
    font-weight:900!important;
    letter-spacing:-.03em!important;
    text-shadow:none!important;
    margin:0 0 22px!important;
    font-size:clamp(26px,3.4vw,40px)!important;
}
body.retame-oficial-page.dark-mode h1{
    color:#4db8ff!important;
}
body.retame-oficial-page .header{
    text-align:left!important;
    margin-bottom:22px!important;
}
body.retame-oficial-page .header p,
body.retame-oficial-page .subtext,
body.retame-oficial-page .small,
body.retame-oficial-page .info-box p,
body.retame-oficial-page .empty p,
body.retame-oficial-page .no-equipos p,
body.retame-oficial-page .no-results p{
    color:#64748b!important;
}
body.retame-oficial-page.dark-mode .header p,
body.retame-oficial-page.dark-mode .subtext,
body.retame-oficial-page.dark-mode .small,
body.retame-oficial-page.dark-mode .info-box p,
body.retame-oficial-page.dark-mode .empty p,
body.retame-oficial-page.dark-mode .no-equipos p,
body.retame-oficial-page.dark-mode .no-results p{
    color:#cbd5e1!important;
}
body.retame-oficial-page .form-card,
body.retame-oficial-page .perfil-card,
body.retame-oficial-page .card,
body.retame-oficial-page .team-item,
body.retame-oficial-page .equipo-card,
body.retame-oficial-page .info-box,
body.retame-oficial-page .success-info,
body.retame-oficial-page .team-modal,
body.retame-oficial-page .empty,
body.retame-oficial-page .no-equipos{
    background:rgba(255,255,255,.95)!important;
    color:#111827!important;
    border:1px solid rgba(0,153,255,.22)!important;
    border-top:4px solid rgba(0,153,255,.86)!important;
    border-radius:24px!important;
    box-shadow:0 18px 42px rgba(15,23,42,.12),0 0 0 1px rgba(255,255,255,.72)!important;
    backdrop-filter:blur(14px)!important;
}
body.retame-oficial-page.dark-mode .form-card,
body.retame-oficial-page.dark-mode .perfil-card,
body.retame-oficial-page.dark-mode .card,
body.retame-oficial-page.dark-mode .team-item,
body.retame-oficial-page.dark-mode .equipo-card,
body.retame-oficial-page.dark-mode .info-box,
body.retame-oficial-page.dark-mode .success-info,
body.retame-oficial-page.dark-mode .team-modal,
body.retame-oficial-page.dark-mode .empty,
body.retame-oficial-page.dark-mode .no-equipos{
    background:rgba(17,24,39,.94)!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.40)!important;
    border-top-color:#0099ff!important;
    box-shadow:0 18px 42px rgba(0,0,0,.32),0 0 24px rgba(0,153,255,.14)!important;
}
body.retame-oficial-page .form-card,
body.retame-oficial-page .perfil-card,
body.retame-oficial-page .card{
    padding:clamp(20px,3.2vw,36px)!important;
}
body.retame-oficial-page .form-card h3,
body.retame-oficial-page .perfil-card h3,
body.retame-oficial-page .card h2,
body.retame-oficial-page .card h3,
body.retame-oficial-page .info-box h3,
body.retame-oficial-page .empty h4,
body.retame-oficial-page .no-equipos h3,
body.retame-oficial-page .no-results h3{
    color:#1877f2!important;
    text-align:left!important;
    border-bottom:none!important;
    padding-bottom:0!important;
    margin:0 0 18px!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .form-card h3,
body.retame-oficial-page.dark-mode .perfil-card h3,
body.retame-oficial-page.dark-mode .card h2,
body.retame-oficial-page.dark-mode .card h3,
body.retame-oficial-page.dark-mode .info-box h3,
body.retame-oficial-page.dark-mode .empty h4,
body.retame-oficial-page.dark-mode .no-equipos h3,
body.retame-oficial-page.dark-mode .no-results h3{
    color:#4db8ff!important;
}
body.retame-oficial-page .form-group,
body.retame-oficial-page .field{
    margin-bottom:18px!important;
}
body.retame-oficial-page label,
body.retame-oficial-page .info-label,
body.retame-oficial-page .label{
    color:#1877f2!important;
    font-weight:800!important;
    letter-spacing:-.01em!important;
}
body.retame-oficial-page.dark-mode label,
body.retame-oficial-page.dark-mode .info-label,
body.retame-oficial-page.dark-mode .label{
    color:#83caff!important;
}
body.retame-oficial-page input,
body.retame-oficial-page select,
body.retame-oficial-page textarea{
    width:100%;
    border-radius:16px!important;
    border:1px solid rgba(0,153,255,.25)!important;
    background:#f8fafc!important;
    color:#111827!important;
    padding:13px 15px!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.85)!important;
}
body.retame-oficial-page input:focus,
body.retame-oficial-page select:focus,
body.retame-oficial-page textarea:focus{
    outline:none!important;
    border-color:#0099ff!important;
    box-shadow:0 0 0 4px rgba(0,153,255,.14)!important;
    transform:none!important;
}
body.retame-oficial-page.dark-mode input,
body.retame-oficial-page.dark-mode select,
body.retame-oficial-page.dark-mode textarea{
    background:#0f172a!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.42)!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04)!important;
}
body.retame-oficial-page .submit-button,
body.retame-oficial-page .search-button,
body.retame-oficial-page .success-button,
body.retame-oficial-page .view-details-button,
body.retame-oficial-page .btn,
body.retame-oficial-page .modal-button,
body.retame-oficial-page .back-button,
body.retame-oficial-page .circle-equipo{
    border:none!important;
    border-radius:16px!important;
    text-decoration:none!important;
    color:#fff!important;
    font-weight:900!important;
    letter-spacing:.01em!important;
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    box-shadow:0 12px 24px rgba(24,119,242,.24)!important;
    transition:transform .2s ease,box-shadow .2s ease,filter .2s ease!important;
}
body.retame-oficial-page .submit-button:hover,
body.retame-oficial-page .search-button:hover,
body.retame-oficial-page .success-button:hover,
body.retame-oficial-page .view-details-button:hover,
body.retame-oficial-page .btn:hover,
body.retame-oficial-page .modal-button:hover,
body.retame-oficial-page .back-button:hover,
body.retame-oficial-page .circle-equipo:hover{
    transform:translateY(-2px)!important;
    filter:saturate(1.08)!important;
    box-shadow:0 16px 30px rgba(24,119,242,.32)!important;
}
body.retame-oficial-page .btn-danger,
body.retame-oficial-page .modal-button-cancel{
    background:linear-gradient(135deg,#ef4444,#ff4b5c)!important;
    box-shadow:0 12px 24px rgba(239,68,68,.22)!important;
}
body.retame-oficial-page .btn-secondary{
    background:linear-gradient(135deg,#475569,#64748b)!important;
    box-shadow:0 12px 24px rgba(71,85,105,.20)!important;
}
body.retame-oficial-page .actions,
body.retame-oficial-page .modal-actions{
    display:flex!important;
    justify-content:center!important;
    align-items:center!important;
    gap:12px!important;
    flex-wrap:wrap!important;
    margin-top:18px!important;
}
body.retame-oficial-page .search-input{
    display:flex!important;
    gap:12px!important;
    align-items:stretch!important;
}
body.retame-oficial-page .search-input input{
    min-width:0!important;
}
body.retame-oficial-page .search-button{
    width:auto!important;
    padding:0 24px!important;
    white-space:nowrap!important;
}
body.retame-oficial-page .search-type{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
}
body.retame-oficial-page .search-option label{
    background:#f8fafc!important;
    color:#334155!important;
    border:1px solid rgba(0,153,255,.25)!important;
    box-shadow:0 8px 18px rgba(15,23,42,.08)!important;
}
body.retame-oficial-page .search-option input[type="radio"]:checked + label{
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    color:#fff!important;
    border-color:transparent!important;
    transform:none!important;
}
body.retame-oficial-page.dark-mode .search-option label{
    background:#0f172a!important;
    color:#e5e7eb!important;
    border-color:rgba(0,153,255,.42)!important;
}
body.retame-oficial-page .team-item{
    padding:20px!important;
    margin-bottom:16px!important;
}
body.retame-oficial-page .team-item-header,
body.retame-oficial-page .team-item-details{
    gap:12px!important;
}
body.retame-oficial-page .team-item-name,
body.retame-oficial-page .equipo-nombre,
body.retame-oficial-page .info-value{
    color:#111827!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .team-item-name,
body.retame-oficial-page.dark-mode .equipo-nombre,
body.retame-oficial-page.dark-mode .info-value{
    color:#f8fafc!important;
}
body.retame-oficial-page .team-item-id,
body.retame-oficial-page .equipo-id,
body.retame-oficial-page .badge{
    display:inline-flex!important;
    align-items:center!important;
    width:max-content!important;
    border-radius:999px!important;
    padding:6px 10px!important;
    background:rgba(255,75,92,.10)!important;
    color:#e11d48!important;
    border:1px solid rgba(255,75,92,.24)!important;
    font-weight:900!important;
}
body.retame-oficial-page.dark-mode .team-item-id,
body.retame-oficial-page.dark-mode .equipo-id,
body.retame-oficial-page.dark-mode .badge{
    background:rgba(255,75,92,.16)!important;
    color:#fb7185!important;
    border-color:rgba(255,75,92,.35)!important;
}
body.retame-oficial-page .equipos-container,
body.retame-oficial-page .equipos-grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr))!important;
    gap:16px!important;
    margin-top:18px!important;
}
body.retame-oficial-page .circle-equipo,
body.retame-oficial-page .equipo-card{
    min-height:126px!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    text-align:center!important;
    padding:18px!important;
}
body.retame-oficial-page .equipo-badge{
    width:58px!important;
    height:58px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    border-radius:20px!important;
    background:linear-gradient(135deg,#ff4b5c,#1877f2)!important;
    color:#fff!important;
    font-weight:900!important;
    margin-bottom:12px!important;
}
body.retame-oficial-page .equipo-link{
    text-decoration:none!important;
}
body.retame-oficial-page .grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(230px,1fr))!important;
    gap:16px!important;
}
body.retame-oficial-page .tabs{
    display:flex!important;
    flex-wrap:wrap!important;
    gap:10px!important;
    margin-bottom:22px!important;
}
body.retame-oficial-page .tabs a{
    text-decoration:none!important;
    color:#334155!important;
    font-weight:900!important;
    padding:11px 14px!important;
    border-radius:999px!important;
    background:#f8fafc!important;
    border:1px solid rgba(0,153,255,.22)!important;
}
body.retame-oficial-page .tabs a.active{
    color:#fff!important;
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    border-color:transparent!important;
}
body.retame-oficial-page.dark-mode .tabs a{
    color:#e5e7eb!important;
    background:#0f172a!important;
    border-color:rgba(0,153,255,.38)!important;
}
body.retame-oficial-page table{
    width:100%!important;
    border-collapse:separate!important;
    border-spacing:0!important;
    overflow:hidden!important;
    border-radius:18px!important;
    background:rgba(255,255,255,.72)!important;
    box-shadow:0 10px 24px rgba(15,23,42,.08)!important;
}
body.retame-oficial-page th{
    background:linear-gradient(135deg,#1877f2,#0099ff)!important;
    color:#fff!important;
    font-weight:900!important;
}
body.retame-oficial-page th,
body.retame-oficial-page td{
    padding:13px 14px!important;
    border-bottom:1px solid rgba(15,23,42,.08)!important;
    color:#111827!important;
}
body.retame-oficial-page.dark-mode table{
    background:rgba(15,23,42,.76)!important;
}
body.retame-oficial-page.dark-mode th,
body.retame-oficial-page.dark-mode td{
    color:#e5e7eb!important;
    border-bottom-color:rgba(255,255,255,.08)!important;
}
body.retame-oficial-page .alert,
body.retame-oficial-page .error-message{
    border-radius:18px!important;
    padding:16px 18px!important;
    margin-bottom:18px!important;
    border:1px solid rgba(239,68,68,.24)!important;
    background:rgba(239,68,68,.10)!important;
    color:#b91c1c!important;
}
body.retame-oficial-page .alert.ok,
body.retame-oficial-page .success-message{
    border-color:rgba(16,185,129,.30)!important;
    background:rgba(16,185,129,.10)!important;
    color:#047857!important;
}
body.retame-oficial-page.dark-mode .alert,
body.retame-oficial-page.dark-mode .error-message{
    color:#fecaca!important;
    background:rgba(239,68,68,.14)!important;
}
body.retame-oficial-page.dark-mode .alert.ok,
body.retame-oficial-page.dark-mode .success-message{
    color:#bbf7d0!important;
    background:rgba(16,185,129,.14)!important;
}
body.retame-oficial-page .team-modal-overlay{
    position:fixed!important;
    inset:0!important;
    z-index:1500!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:18px!important;
    background:rgba(15,23,42,.54)!important;
    backdrop-filter:blur(8px)!important;
}
body.retame-oficial-page .team-modal{
    width:min(760px,100%)!important;
    max-height:90dvh!important;
    overflow:auto!important;
    padding:26px!important;
}
body.retame-oficial-page .modal-header{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:12px!important;
    margin-bottom:18px!important;
}
body.retame-oficial-page .modal-close{
    width:42px!important;
    height:42px!important;
    border-radius:50%!important;
    border:none!important;
    color:#fff!important;
    background:linear-gradient(135deg,#ef4444,#ff4b5c)!important;
    font-size:24px!important;
    cursor:pointer!important;
}
body.retame-oficial-page .modal-team-info{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(210px,1fr))!important;
    gap:12px!important;
}
body.retame-oficial-page .info-group{
    background:rgba(0,153,255,.07)!important;
    border:1px solid rgba(0,153,255,.16)!important;
    border-radius:16px!important;
    padding:14px!important;
}
body.retame-oficial-page.dark-mode .info-group{
    background:rgba(0,153,255,.10)!important;
    border-color:rgba(0,153,255,.26)!important;
}

body.retame-oficial-page .perfil-card [style*="rgba(0,255,198"],
body.retame-oficial-page .form-card [style*="rgba(0,255,198"],
body.retame-oficial-page .card [style*="rgba(0,255,198"]{
    background:rgba(0,153,255,.08)!important;
    border-color:rgba(0,153,255,.22)!important;
    border-radius:18px!important;
    color:inherit!important;
}
body.retame-oficial-page.dark-mode .perfil-card [style*="rgba(0,255,198"],
body.retame-oficial-page.dark-mode .form-card [style*="rgba(0,255,198"],
body.retame-oficial-page.dark-mode .card [style*="rgba(0,255,198"]{
    background:rgba(0,153,255,.12)!important;
    border-color:rgba(0,153,255,.32)!important;
}
@media(max-width:760px){
    body.retame-oficial-page .search-input{flex-direction:column!important;}
    body.retame-oficial-page .search-button{width:100%!important;padding:14px 18px!important;}
    body.retame-oficial-page .search-type{grid-template-columns:1fr!important;}
    body.retame-oficial-page table{display:block!important;overflow-x:auto!important;white-space:nowrap!important;}
    body.retame-oficial-page .tabs{overflow:auto!important;flex-wrap:nowrap!important;padding-bottom:4px!important;}
}
</style>

</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Equipo - RETAME'); } ?>

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
        <li><a href="../Perfil2.php">🏠 Inicio</a></li>
        <li><a href="UnirmeEquipo.php">👥 Unirme a un equipo</a></li>
        <li><a href="CrearEquipo.php">👥 Crear Un Equipo</a></li>
        <li><a href="../Retar/Retas_Program.php">📋 Mis Retas</a></li>
        <li><a href="Mis_Equipos.php">👥📋 Mis Equipos</a></li>
        <li><a href="../Solicitudes.php">Solicitudes</a></li>
        <li>
            <a href="../Notificaciones.php">
                Notificaciones
                <?php if ($nuevas_count > 0): ?>
                    <span class="noti-alert-badge">!</span>
                <?php endif; ?>
            </a>
        </li>
        <li><a href="../login.php">🚪 Cerrar Sesión</a></li>
    </ul>
</div>
<div class="main-content">
    <h1>🏆 Crear Nuevo Equipo</h1>
    
    <?php if(isset($error)): ?>
        <div class="error-message show">
            <strong>❌ Error:</strong> <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if(isset($success) && $success): ?>
        <div class="form-card">
            <div class="success-message">
                <h3 style="color: #00ff00; margin-bottom: 20px;">✅ <?php echo $success_message; ?></h3>
                
                <div class="success-info">
                    <p><strong>ID del Equipo:</strong> <?php echo htmlspecialchars($equipo_id); ?></p>
                    <p><strong>Cantidad máxima de jugadores:</strong> <?php echo $equipo_cantidad; ?></p>
                    <p><strong>Capitán del Equipo:</strong> <?php echo htmlspecialchars($capitan_nombre); ?></p>
                </div>
                
                <p style="font-size:17px;color:#00ffc6;font-weight:bold;margin:20px 0;">
                    ESTE ES EL USUARIO DEL EQUIPO, PUEDES COMPARTIRLO PARA QUE OTROS RETADORES SE UNAN A TU EQUIPO
                </p>
                
                <a href="../Perfil2.php" class="success-button">
                    Ir a mi Perfil Completo
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="form-card">
            <h3>📝 Completa los datos del equipo</h3>
            
            <!-- Mensaje de error -->
            <div class="error-message" id="errorMessage"></div>
            
            <form action="" method="POST" id="createTeamForm">
                
                <div class="form-group">
                    <label for="nombre">Nombre del Equipo *</label>
                    <input type="text" 
                           id="nombre" 
                           name="nombre" 
                           placeholder="Ej: Los Tigres FC" 
                           required
                           maxlength="255">
                </div>
                
                <div class="form-group">
                    <label for="cantidad">Cantidad Máxima de Jugadores *</label>
                    <input type="range" 
                           id="cantidad" 
                           name="cantidad" 
                           min="1" 
                           max="50" 
                           value="10"
                           class="quantity-slider">
                    <div class="quantity-info">
                        <span>1 jugador</span>
                        <span class="quantity-value" id="quantityValue">10</span>
                        <span>50 jugadores</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="cp">Código Postal *</label>
                    <input type="text" 
                           id="cp" 
                           name="cp" 
                           placeholder="Ej: 28001" 
                           required
                           maxlength="50">
                </div>
                
                <div class="form-group">
                    <label for="pais">País *</label>
                    <input type="text" 
                           id="pais" 
                           name="pais" 
                           placeholder="Ej: España" 
                           required
                           maxlength="255">
                </div>
                
                <div class="form-group">
                    <label for="id_deporte">Deporte *</label>
                    <select id="id_deporte" name="id_deporte" required>
                        <?php foreach($deportes as $deporte): ?>
                            <option value="<?= htmlspecialchars($deporte['Id_Deporte']) ?>">
                                <?= htmlspecialchars($deporte['Nombre']) ?> (<?= htmlspecialchars($deporte['Id_Deporte']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="submit-button">
                    🏅 Crear Equipo
                </button>
                
            </form>
            
            <div class="info-box">
                <h3>📋 Información Importante</h3>
                <p>
                    • El ID del equipo se generará automáticamente<br>
                    • Serás asignado como Capitán del equipo<br>
                    • Podrás invitar a otros jugadores usando el ID del equipo<br>
                    • La cantidad máxima de jugadores podrá ajustarse más tarde
                </p>
            </div>
            
        </div>
    <?php endif; ?>
    
</div>
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
        
        // Color aleatorio (blanco con diferentes tonalidades)
        particle.style.background = `rgba(255, 255, 255, ${Math.random() * 0.2 + 0.1})`;
        
        particlesContainer.appendChild(particle);
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

// Control del slider de cantidad
const cantidadSlider = document.getElementById('cantidad');
const cantidadValue = document.getElementById('quantityValue');
const errorMessage = document.getElementById('errorMessage');

if (cantidadSlider && cantidadValue) {
    // Actualizar valor del slider
    cantidadSlider.addEventListener('input', function() {
        cantidadValue.textContent = this.value;
    });
    
    // Validación del formulario
    const createTeamForm = document.getElementById('createTeamForm');
    if (createTeamForm) {
        createTeamForm.addEventListener('submit', function(e) {
            let isValid = true;
            errorMessage.classList.remove('show');
            errorMessage.innerHTML = '';
            
            // Validar nombre
            const nombre = document.getElementById('nombre').value.trim();
            if (nombre.length < 3) {
                errorMessage.innerHTML += '❌ El nombre del equipo debe tener al menos 3 caracteres<br>';
                isValid = false;
            }
            
            // Validar cantidad
            const cantidad = parseInt(cantidadSlider.value);
            if (cantidad < 1) {
                errorMessage.innerHTML += '❌ La cantidad mínima de jugadores es 1<br>';
                isValid = false;
            }
            
            // Validar código postal
            const cp = document.getElementById('cp').value.trim();
            if (cp.length < 4) {
                errorMessage.innerHTML += '❌ El código postal debe tener al menos 4 caracteres<br>';
                isValid = false;
            }
            
            // Validar país
            const pais = document.getElementById('pais').value.trim();
            if (pais.length < 3) {
                errorMessage.innerHTML += '❌ El país debe tener al menos 3 caracteres<br>';
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                errorMessage.classList.add('show');
                errorMessage.scrollIntoView({ behavior: 'smooth' });
            } else {
                // Mostrar mensaje de carga
                const submitButton = document.querySelector('.submit-button');
                if (submitButton) {
                    submitButton.innerHTML = '⏳ Creando equipo...';
                    submitButton.disabled = true;
                }
            }
        });
    }
}

// Efectos en inputs
const inputs = document.querySelectorAll('input, select');
inputs.forEach(input => {
    input.addEventListener('focus', function() {
        this.style.transform = 'translateY(-2px)';
    });
    
    input.addEventListener('blur', function() {
        this.style.transform = 'translateY(0)';
    });
});

// Cerrar sidebar si se hace clic en el overlay
document.querySelector('.overlay').addEventListener('click', function() {
    toggleSidebar();
});

// Manejar tecla ESC para cerrar sidebar
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('active')) {
            toggleSidebar();
        }
    }
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>