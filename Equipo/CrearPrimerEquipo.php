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

  .back-button {
            position: absolute;
            top: 20px; left: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 2px solid var(--accent-green);
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .back-button:hover {
            background: var(--accent-green);
            color: #000;
            transform: translateX(-5px);
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
     .back-button {
            position: absolute;
            top: 20px; left: 20px;
            background: rgba(86, 238, 255, 0.1);
            color: var(--text-light);
            border: 2px solid var(--accent-green);
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .back-button:hover {
            background: var(--accent-green);
            color: #000;
            transform: translateX(-5px);
        }

</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Crear Equipo - RETAME'); } ?>

<!-- BURBUJAS DE FONDO -->
<div class="bg-particles" id="particles"></div>

<!-- Botón hamburguesa solo para móviles -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>

<!-- Overlay para cerrar sidebar en móviles -->
<div class="overlay" onclick="toggleSidebar()"></div>
 <a href="../Perfil2.php" class="back-button">⬅ Volver </a>


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