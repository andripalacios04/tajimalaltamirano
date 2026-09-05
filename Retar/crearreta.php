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

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Nombre = isset($usuarios['Nombre']) ? htmlspecialchars($usuarios['Nombre']) : 'Usuario';

// Lista de posibles llaves para detectar el ID del usuario
$possibleKeys = ['Id_Retador', 'Id_Jugador', 'Id_Usuario', 'IdUsuario', 'id_retador', 'id_jugador', 'id_usuario'];
$possibleIds = [];

foreach ($possibleKeys as $k) {
    if (isset($usuarios[$k]) && $usuarios[$k] !== '') {
        $possibleIds[] = (string)$usuarios[$k];
    }
}

// Buscar equipos del usuario
$misEquipos = [];

$sql = "SELECT e.Id_Equipo, e.Nombre FROM Equipo_Jugador ej
        INNER JOIN Equipo e ON ej.Id_Equipo = e.Id_Equipo
        WHERE ej.Id_Jugador = ? LIMIT 100";

$stmt = $conn->prepare($sql);
if ($stmt) {
    foreach ($possibleIds as $pid) {
        $stmt->bind_param("s", $pid);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $misEquipos[] = $row;
            }
            break;
        }
    }
    $stmt->close();
}

// ============================
//   PROCESAR CREAR RETA
// ============================
if (isset($_POST['crearReta'])) {

    $Id_Equipo1 = $_POST['Id_Equipo1']; // varchar
    $Id_Equipo2 = null; // varchar
    $Direccion = $_POST['Direccion'];
    $Lugar = $_POST['Lugar'];
    $Fecha = $_POST['Fecha'];
    $Hora = $_POST['Hora']; // ahora viene del formulario
    $EstadoReta = "En proceso";

    // ==========================
    // Obtener Id_Deporte (varchar)
    // ==========================
    $sqlDep = "SELECT Id_Deporte FROM Equipo WHERE Id_Equipo = ?";
    $stmtDep = $conn->prepare($sqlDep);
    $stmtDep->bind_param("s", $Id_Equipo1); // s para varchar
    $stmtDep->execute();
    $resDep = $stmtDep->get_result();

    if ($resDep && $resDep->num_rows > 0) {
        $rowDep = $resDep->fetch_assoc();
        $Id_Deporte = $rowDep['Id_Deporte']; // varchar
    } else {
        echo "<script>alert('Error: No se encontró el deporte del equipo.');</script>";
        exit();
    }
    $stmtDep->close();

    // ==========================
    // Generar Id_Reta
    // ==========================
    $Id_Reta = rand(10,99) . date("Ymd");

    // ==========================
    // Insertar la Reta
    // ==========================
    $sqlInsert = "INSERT INTO reta (Id_Reta, Id_Equipo1, Id_Equipo2, Direccion, Lugar, Fecha, Hora, EstadoReta, Id_Deporte)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsert = $conn->prepare($sqlInsert);

    // TODOS los campos son VARCHAR, usar "s"
    $stmtInsert->bind_param(
        "sssssssss",
        $Id_Reta,
        $Id_Equipo1,
        $Id_Equipo2,
        $Direccion,
        $Lugar,
        $Fecha,
        $Hora,
        $EstadoReta,
        $Id_Deporte
    );

    if ($stmtInsert->execute()) {
        echo "<script>alert('Reta creada correctamente');</script>";
    } else {
        echo "<script>alert('Error al crear la reta: ".$stmtInsert->error."');</script>";
    }

    $stmtInsert->close();
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
    <title>Perfil de Usuario - RETAME</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif; }
body { display:flex; flex-wrap:wrap; min-height:100vh; background:linear-gradient(135deg, #140f27ff, #203a43, #1c2a92ff); color:#eeeeee; overflow-x:hidden; }
.sidebar { width:250px; background:#111820; padding:20px; display:flex; flex-direction:column; align-items:center; box-shadow:5px 0px 20px #09058aff; }
.sidebar h2 { font-size:18px; text-align:center; color:#ffffff; margin-bottom:20px; }
.doctor-logo { width:90px; margin-bottom:10px; }
.menu { list-style:none; width:100%; }
.menu li { padding:12px; margin:10px 0; text-align:center; border-radius:8px; transition:0.3s; background-color:rgba(0,255,198,0.1); }
.menu li a { color:#ffffff; text-decoration:none; font-weight:bold; display:block; }
.menu li:hover { background-color:rgba(244,16,16,0.3); transform:scale(1.05); }
.perfil-card { background:#1b1f27; padding:30px; border-radius:15px; box-shadow:0px 0px 20px #001affff; max-width:600px; margin:auto; text-align:center; width:100%; }
.perfil-card h2 { color:#ff0000ff; margin-bottom:40px; }
.form-reta { display:none; margin-top:25px; }
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Perfil de Usuario - RETAME'); } ?>

<div class="sidebar">
    <img src="assets/doctor.png" class="doctor-logo">
    <h2>RETAME</h2>
    <ul class="menu">
        <li><a href="dashboard.html">Inicio</a></li>
        <li><a href="agendar.html">Agendar Reta</a></li>
        <li><a href="mis_citas.html">Mis Retas</a></li>
        <li><a href="Configurar mi perfil.php">Configurar Perfil</a></li>
        <li><a href="Configurar mi perfil.php">Unirme a un equipo</a></li>
        <li><a href="logout.php">Cerrar Sesión</a></li>
    </ul>
</div>

<div class="perfil-card">
    <h2>Selecciona tu equipo retador</h2>

    <?php if (!empty($misEquipos)): ?>
        <label>Mis equipos:</label>
        <select id="equipoSelect" style="width:100%; padding:10px;">
            <option value="">-- Selecciona un equipo --</option>
            <?php foreach ($misEquipos as $eq): ?>
                <option value="<?php echo $eq['Id_Equipo']; ?>">
                    <?php echo $eq['Nombre']; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <form id="formReta" class="form-reta" method="POST" action="">
            <input type="hidden" name="Id_Equipo1" id="Id_Equipo1">

            <label>Dirección:</label>
            <input type="text" name="Direccion" required style="width:100%; padding:10px; margin-bottom:10px;">

            <label>Lugar:</label>
            <input type="text" name="Lugar" required style="width:100%; padding:10px; margin-bottom:10px;">

            <label>Fecha:</label>
            <input type="date" name="Fecha" required style="width:100%; padding:10px; margin-bottom:10px;">

            <label>Hora:</label>
            <input type="time" name="Hora" required style="width:100%; padding:10px; margin-bottom:20px;">

            <button type="submit" name="crearReta"
                style="background:#ff0000; padding:12px; border:none; color:white; font-weight:bold; width:100%;">
                Crear Reta
            </button>
        </form>
    <?php else: ?>
        <p>No perteneces a ningún equipo.</p>
    <?php endif; ?>
</div>

<script>
const select = document.getElementById("equipoSelect");
const form = document.getElementById("formReta");
const inputEquipo = document.getElementById("Id_Equipo1");

select.addEventListener("change", function() {
    if(this.value !== "") {
        form.style.display = "block";
        inputEquipo.value = this.value;
    } else {
        form.style.display = "none";
        inputEquipo.value = "";
    }
});
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>
