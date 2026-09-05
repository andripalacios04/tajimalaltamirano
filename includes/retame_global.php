<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('retame_base_path')) {
    function retame_base_path() {
        $root = realpath(dirname(__DIR__));
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
        if (!$root || !$script) {
            return '';
        }
        $dir = dirname($script);
        $rel = trim(str_replace('\\', '/', str_replace($root, '', $dir)), '/');
        if ($rel === '') {
            return '';
        }
        return str_repeat('../', count(array_filter(explode('/', $rel))));
    }
}

if (!function_exists('retame_esc')) {
    function retame_esc($valor) {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('retame_normalizar')) {
    function retame_normalizar($valor) {
        $valor = trim((string)$valor);
        if (function_exists('mb_strtolower')) {
            $valor = mb_strtolower($valor, 'UTF-8');
        } else {
            $valor = strtolower($valor);
        }
        return str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $valor);
    }
}

if (!function_exists('retame_obtener_sesion')) {
    function retame_obtener_sesion($llaves, $default = '') {
        $datos = isset($_SESSION['usuario_data']) && is_array($_SESSION['usuario_data']) ? $_SESSION['usuario_data'] : [];
        foreach ($llaves as $llave) {
            if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
                return trim((string)$datos[$llave]);
            }
        }
        return $default;
    }
}

if (!function_exists('retame_resolver_foto')) {
    function retame_resolver_foto($foto, $base = '') {
        $foto = trim(str_replace('\\', '/', (string)$foto));
        if ($foto === '') {
            return $base . 'assets/doctor.png';
        }
        if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $foto)) {
            return $foto;
        }
        $foto = ltrim($foto, '/');
        if (strpos($foto, '../') === 0 || strpos($foto, './') === 0) {
            return $foto;
        }
        $rutas = [$foto, 'Imagenes/' . $foto, 'uploads/' . $foto, 'FotosPerfil/' . $foto, 'assets/' . $foto, 'img/' . $foto];
        foreach ($rutas as $ruta) {
            if (file_exists(dirname(__DIR__) . '/' . $ruta)) {
                return $base . $ruta;
            }
        }
        return $base . $foto;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conexionRetame = dirname(__DIR__) . '/conexion.php';
    if (file_exists($conexionRetame)) {
        include_once $conexionRetame;
    }
}

$retameBasePath = retame_base_path();
$retameUsuarioDatos = isset($_SESSION['usuario_data']) && is_array($_SESSION['usuario_data']) ? $_SESSION['usuario_data'] : [];
$retameNombreUsuario = retame_obtener_sesion(['Nombre', 'nombre'], 'Usuario');
$retameApellidoUsuario = retame_obtener_sesion(['Apellido', 'apellido'], '');
$retameIdRetador = retame_obtener_sesion(['Id_Retador', 'id_retador'], '');
$retameModoPerfilActual = retame_obtener_sesion(['ModoPerfil', 'modoPerfil', 'modoperfil'], '');
$retameFotoPerfilUsuario = retame_resolver_foto(retame_obtener_sesion(['FotoPerfil', 'Fotoperfil', 'fotoPerfil', 'foto_perfil'], ''), $retameBasePath);

if (isset($conn) && $conn instanceof mysqli && $retameIdRetador !== '') {
    $stmtRetamePerfil = $conn->prepare("SELECT * FROM retador WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if ($stmtRetamePerfil) {
        $stmtRetamePerfil->bind_param('s', $retameIdRetador);
        $stmtRetamePerfil->execute();
        $resRetamePerfil = $stmtRetamePerfil->get_result();
        if ($filaRetamePerfil = $resRetamePerfil->fetch_assoc()) {
            foreach (['ModoPerfil', 'modoPerfil', 'modoperfil'] as $campoModo) {
                if (isset($filaRetamePerfil[$campoModo])) {
                    $retameModoPerfilActual = trim((string)$filaRetamePerfil[$campoModo]);
                    $_SESSION['usuario_data']['ModoPerfil'] = $retameModoPerfilActual;
                    break;
                }
            }
            foreach (['FotoPerfil', 'Fotoperfil', 'fotoPerfil', 'foto_perfil'] as $campoFoto) {
                if (isset($filaRetamePerfil[$campoFoto]) && trim((string)$filaRetamePerfil[$campoFoto]) !== '') {
                    $retameFotoPerfilUsuario = retame_resolver_foto($filaRetamePerfil[$campoFoto], $retameBasePath);
                    break;
                }
            }
            foreach (['Nombre', 'nombre'] as $campoNombre) {
                if (isset($filaRetamePerfil[$campoNombre]) && trim((string)$filaRetamePerfil[$campoNombre]) !== '') {
                    $retameNombreUsuario = trim((string)$filaRetamePerfil[$campoNombre]);
                    $_SESSION['usuario_data']['Nombre'] = $retameNombreUsuario;
                    break;
                }
            }
        }
        $stmtRetamePerfil->close();
    }
}

$retameModoNormalizado = retame_normalizar($retameModoPerfilActual);
$retameModoOscuroActivo = ($retameModoNormalizado === 'modo oscuro' || $retameModoNormalizado === 'moso oscuro');
$modoOscuroActivo = $retameModoOscuroActivo;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');
    if ($retameIdRetador === '' || !isset($conn) || !($conn instanceof mysqli)) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se encontró el usuario o la conexión.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';
    $stmtModoRetame = $conn->prepare("UPDATE retador SET ModoPerfil = ? WHERE CAST(Id_Retador AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if (!$stmtModoRetame) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo preparar la actualización del modo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $stmtModoRetame->bind_param('ss', $nuevoModoPerfil, $retameIdRetador);
    $okModoRetame = $stmtModoRetame->execute();
    $stmtModoRetame->close();
    if ($okModoRetame) {
        $_SESSION['usuario_data']['ModoPerfil'] = $nuevoModoPerfil;
        echo json_encode(['ok' => true, 'modo' => $nuevoModoPerfil], JSON_UNESCAPED_UNICODE);
        exit();
    }
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo actualizar el modo.'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!function_exists('retame_body_class')) {
    function retame_body_class($extra = '') {
        global $retameModoOscuroActivo;
        $clases = [];
        if ($retameModoOscuroActivo) {
            $clases[] = 'dark-mode';
        }
        if (trim((string)$extra) !== '') {
            $clases[] = trim((string)$extra);
        }
        return retame_esc(implode(' ', array_unique($clases)));
    }
}

if (!function_exists('retame_pagina_activa')) {
    function retame_pagina_activa() {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        $archivo = basename($script);
        if (stripos($script, '/Retar/') !== false) return 'retar';
        if (stripos($script, '/Ligas/') !== false) return 'ligas';
        if (stripos($script, '/Canchas/') !== false) return 'canchas';
        if (stripos($script, '/Equipo/') !== false) return 'equipo';
        if ($archivo === 'Agenda.php') return 'agenda';
        if ($archivo === 'Notificaciones.php') return 'notificaciones';
        if ($archivo === 'MiPerfil.php') return 'perfil';
        if ($archivo === 'Solicitudes.php') return 'solicitudes';
        return 'inicio';
    }
}

if (!function_exists('retame_render_global_css')) {
    function retame_render_global_css() {
?>
<style id="retame-oficial-css">
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');
body.retame-oficial-page{--retame-sidebar:280px;display:block!important;min-height:100dvh!important;margin:0!important;padding:96px 18px 104px calc(var(--retame-sidebar) + 24px)!important;font-family:'Poppins',sans-serif!important;background:#f0f2f5!important;color:#111827!important;overflow-x:hidden!important;transition:background .25s ease,color .25s ease}
body.retame-oficial-page.dark-mode{background:#0b1220!important;color:#e5e7eb!important}
body.retame-oficial-page .retame-oficial-bg{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(circle at 18% 20%,rgba(24,119,242,.22),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.20),transparent 410px),linear-gradient(135deg,#f8fbff 0%,#eef4fb 42%,#fff6f8 100%)}
body.retame-oficial-page.dark-mode .retame-oficial-bg{background:radial-gradient(circle at 18% 20%,rgba(0,153,255,.28),transparent 390px),radial-gradient(circle at 84% 22%,rgba(255,75,92,.24),transparent 410px),linear-gradient(135deg,#070b14 0%,#0b1220 48%,#160a12 100%)}
body.retame-oficial-page>.sidebar:not(.retame-oficial-sidebar),body.retame-oficial-page>.menu-toggle:not(.retame-oficial-menu-toggle),body.retame-oficial-page>.overlay:not(.retame-oficial-overlay),body.retame-oficial-page>#sidebarOverlay{display:none!important}
body.retame-oficial-page>.main-content,body.retame-oficial-page>.container,body.retame-oficial-page>main,body.retame-oficial-page>.page{position:relative!important;z-index:2!important;width:min(1180px,100%)!important;max-width:1180px!important;margin-left:0!important;margin-right:auto!important;padding-top:16px!important}
body.retame-oficial-page .retame-oficial-sidebar{width:var(--retame-sidebar);height:100dvh;position:fixed;left:0;top:0;z-index:1000;padding:24px 16px 112px;background:rgba(255,255,255,.97);backdrop-filter:blur(14px);border-right:3px solid rgba(0,153,255,.92);box-shadow:8px 0 24px rgba(0,0,0,.08),0 0 0 2px rgba(24,119,242,.18),0 0 18px rgba(0,153,255,.32);overflow-y:auto;transition:transform .3s ease}
body.retame-oficial-page.retame-sidebar-hidden .retame-oficial-sidebar{transform:translateX(-105%)}
body.retame-oficial-page.dark-mode .retame-oficial-sidebar{background:rgba(12,18,31,.96);border-right-color:rgba(0,153,255,.95);box-shadow:8px 0 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.20),0 0 24px rgba(0,153,255,.28)}
.retame-oficial-logo-area{display:flex;align-items:center;gap:12px;margin-bottom:18px}.retame-oficial-logo-area img{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:3px solid #fff;box-shadow:0 8px 18px rgba(24,119,242,.14),0 0 0 2px rgba(24,119,242,.12)}.retame-oficial-logo-area h2{font-family:'Orbitron',sans-serif;font-size:19px;color:#1877f2;line-height:1;margin:0}.retame-oficial-logo-area p{font-size:12px;color:#6b7280;margin:5px 0 0}.retame-oficial-profile{width:100%;min-height:74px;text-decoration:none;border-radius:22px;background:linear-gradient(180deg,#fff,#fbfbfb);border:2px solid rgba(255,75,92,.72);box-shadow:0 8px 18px rgba(0,0,0,.07),0 0 0 2px rgba(0,153,255,.24),0 0 15px rgba(0,153,255,.16);display:flex;align-items:center;gap:12px;padding:10px;margin-bottom:14px;color:#111827!important}.retame-oficial-profile img{width:50px;height:50px;border-radius:50%;object-fit:cover;border:2px solid rgba(0,153,255,.75)}.retame-oficial-profile span{display:block;font-size:11px;font-weight:900;color:#ff4b5c;text-transform:uppercase;letter-spacing:.7px}.retame-oficial-profile strong{display:block;font-size:14px;color:#111827;line-height:1.2;word-break:break-word}.retame-oficial-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.retame-oficial-link{min-height:88px;border-radius:18px;text-decoration:none;background:#fff;border:2px solid rgba(0,153,255,.50);box-shadow:0 8px 18px rgba(0,0,0,.06),0 0 0 2px rgba(255,75,92,.18);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;color:#1f2937!important;font-size:12px;font-weight:900;text-align:center;padding:10px;transition:.22s ease}.retame-oficial-link:hover{transform:translateY(-2px);border-color:rgba(255,75,92,.9);box-shadow:0 12px 22px rgba(0,0,0,.10),0 0 0 2px rgba(0,153,255,.25)}.retame-oficial-link img{width:28px;height:28px;object-fit:contain}.retame-oficial-link.active{border-color:rgba(255,75,92,.95);box-shadow:0 0 0 2px rgba(0,153,255,.72),0 10px 22px rgba(24,119,242,.12)}.retame-oficial-close{margin-top:12px;grid-column:1/-1;min-height:50px}.retame-oficial-info{display:flex;justify-content:center;gap:9px;flex-wrap:wrap;margin:13px 0}.retame-oficial-info a{font-size:11px;color:#374151!important;font-weight:800;text-decoration:none}.retame-oficial-mode{width:100%;min-height:58px;margin-top:4px;border-radius:18px;background:#fff;border:2px solid rgba(0,153,255,.60);box-shadow:0 8px 18px rgba(0,0,0,.06),0 0 14px rgba(0,153,255,.14);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px}.retame-oficial-mode strong{font-size:13px;color:#374151}.retame-switch{width:54px;height:30px;border:none;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 2px 5px rgba(0,0,0,.16),0 0 0 2px rgba(255,75,92,.28),0 0 0 4px rgba(0,153,255,.12);position:relative;cursor:pointer;transition:.25s ease}.retame-switch span{position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(0,0,0,.25);transition:.25s ease}.retame-switch:disabled{opacity:.65;cursor:not-allowed}
body.retame-oficial-page.dark-mode .retame-oficial-profile,body.retame-oficial-page.dark-mode .retame-oficial-link,body.retame-oficial-page.dark-mode .retame-oficial-mode{background:#111827;color:#e5e7eb!important;border-color:rgba(255,75,92,.72);box-shadow:0 10px 24px rgba(0,0,0,.28),0 0 0 2px rgba(0,153,255,.22),0 0 18px rgba(0,153,255,.16)}body.retame-oficial-page.dark-mode .retame-oficial-profile strong,body.retame-oficial-page.dark-mode .retame-oficial-mode strong,body.retame-oficial-page.dark-mode .retame-oficial-info a{color:#e5e7eb!important}body.retame-oficial-page.dark-mode .retame-oficial-logo-area h2{color:#4db8ff}body.retame-oficial-page.dark-mode .retame-oficial-logo-area p{color:#cbd5e1}body.retame-oficial-page.dark-mode .retame-oficial-link img,body.retame-oficial-page.dark-mode .retame-oficial-bottom a img{filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05)}body.retame-oficial-page.dark-mode .retame-switch{background:linear-gradient(135deg,#1877f2,#0ea5e9);box-shadow:inset 0 2px 5px rgba(0,0,0,.25),0 0 0 2px rgba(255,75,92,.44),0 0 16px rgba(0,153,255,.26)}body.retame-oficial-page.dark-mode .retame-switch span{transform:translateX(24px)}
.retame-oficial-topbar{height:74px;position:fixed;left:var(--retame-sidebar);right:0;top:0;z-index:900;background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:3px solid rgba(0,153,255,.86);box-shadow:0 5px 20px rgba(0,0,0,.08);display:flex;align-items:center;justify-content:space-between;gap:14px;padding:0 22px;transition:.25s ease}body.retame-oficial-page.retame-sidebar-hidden .retame-oficial-topbar{left:0}.retame-oficial-menu-toggle{width:46px;height:42px;border:none;border-radius:14px;background:#fff;box-shadow:0 6px 14px rgba(0,0,0,.10),0 0 0 2px rgba(0,153,255,.24);cursor:pointer;display:flex;align-items:center;justify-content:center}.retame-oficial-menu-toggle span,.retame-oficial-menu-toggle span:before,.retame-oficial-menu-toggle span:after{content:'';display:block;width:22px;height:3px;border-radius:999px;background:#111827;position:relative}.retame-oficial-menu-toggle span:before{position:absolute;top:-7px}.retame-oficial-menu-toggle span:after{position:absolute;top:7px}.retame-oficial-title h1{font-size:19px;color:#1877f2;margin:0;font-weight:900}.retame-oficial-user{display:flex;align-items:center;gap:8px;font-weight:800;color:#374151;background:#fff;border-radius:999px;padding:8px 12px;box-shadow:0 5px 14px rgba(0,0,0,.08)}body.retame-oficial-page.dark-mode .retame-oficial-topbar,body.retame-oficial-page.dark-mode .retame-oficial-user,body.retame-oficial-page.dark-mode .retame-oficial-menu-toggle{background:rgba(12,18,31,.96);color:#e5e7eb;border-bottom-color:rgba(0,153,255,.95)}body.retame-oficial-page.dark-mode .retame-oficial-title h1{color:#4db8ff}body.retame-oficial-page.dark-mode .retame-oficial-menu-toggle span,body.retame-oficial-page.dark-mode .retame-oficial-menu-toggle span:before,body.retame-oficial-page.dark-mode .retame-oficial-menu-toggle span:after{background:#fff}
.retame-oficial-bottom{position:fixed;left:var(--retame-sidebar);right:0;bottom:0;z-index:900;height:76px;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);border-top:3px solid rgba(0,153,255,.86);box-shadow:0 -8px 24px rgba(0,0,0,.09);display:flex;align-items:center;justify-content:center;gap:min(5vw,38px);transition:.25s ease}body.retame-oficial-page.retame-sidebar-hidden .retame-oficial-bottom{left:0}.retame-oficial-bottom a{width:52px;height:52px;border-radius:18px;display:flex;align-items:center;justify-content:center;position:relative;text-decoration:none}.retame-oficial-bottom a img{width:28px;height:28px;object-fit:contain}.retame-oficial-bottom a.active:after{content:'';position:absolute;inset:4px;border-radius:16px;border:2px solid rgba(255,75,92,.95);box-shadow:0 0 0 2px rgba(0,153,255,.75)}body.retame-oficial-page.dark-mode .retame-oficial-bottom{background:rgba(12,18,31,.96);border-top-color:rgba(0,153,255,.95);box-shadow:0 -8px 24px rgba(0,0,0,.28),0 0 18px rgba(0,153,255,.20)}
body.retame-oficial-page .retame-oficial-overlay{display:none;position:fixed;inset:0;z-index:850;background:rgba(0,0,0,.42)}body.retame-oficial-page.dark-mode input,body.retame-oficial-page.dark-mode select,body.retame-oficial-page.dark-mode textarea{background:#0f172a!important;color:#e5e7eb!important;border-color:rgba(0,153,255,.45)!important}body.retame-oficial-page.dark-mode table{color:#e5e7eb!important}body.retame-oficial-page.dark-mode .card,body.retame-oficial-page.dark-mode .section,body.retame-oficial-page.dark-mode .team-card,body.retame-oficial-page.dark-mode .form-container,body.retame-oficial-page.dark-mode .content-card,body.retame-oficial-page.dark-mode .acciones-container,body.retame-oficial-page.dark-mode .hero-card{background:rgba(17,24,39,.92)!important;color:#e5e7eb!important;border-color:rgba(0,153,255,.38)!important}body.retame-oficial-page:not(.dark-mode) .card,body.retame-oficial-page:not(.dark-mode) .section,body.retame-oficial-page:not(.dark-mode) .team-card,body.retame-oficial-page:not(.dark-mode) .form-container,body.retame-oficial-page:not(.dark-mode) .content-card,body.retame-oficial-page:not(.dark-mode) .acciones-container,body.retame-oficial-page:not(.dark-mode) .hero-card{background:rgba(255,255,255,.94)!important;color:#111827!important}
@media(max-width:820px){body.retame-oficial-page{padding:84px 12px 92px!important}.retame-oficial-topbar{left:0;height:66px;padding:0 12px}.retame-oficial-title h1{font-size:15px}.retame-oficial-user{display:none}.retame-oficial-sidebar{transform:translateX(-105%)}body.retame-oficial-page.retame-sidebar-open .retame-oficial-sidebar{transform:translateX(0)}body.retame-oficial-page.retame-sidebar-open .retame-oficial-overlay{display:block}.retame-oficial-bottom{left:0;height:68px;gap:min(4vw,22px)}.retame-oficial-bottom a{width:46px;height:46px}.retame-oficial-bottom a img{width:25px;height:25px}}
@media(max-width:430px){.retame-oficial-grid{grid-template-columns:1fr 1fr;gap:8px}.retame-oficial-link{min-height:76px;font-size:11px}.retame-oficial-title h1{max-width:210px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}
</style>
<?php
    }
}

if (!function_exists('retame_render_shell')) {
    function retame_render_shell($titulo = '') {
        global $retameBasePath, $retameNombreUsuario, $retameFotoPerfilUsuario, $retameIdRetador;
        if ($retameIdRetador === '') {
            return;
        }
        $active = retame_pagina_activa();
        $titulo = trim((string)$titulo) !== '' ? $titulo : 'RETAME';
        $base = $retameBasePath;
        $links = [
            ['key' => 'equipo', 'url' => $base . 'Equipo/UnirmeEquipo.php', 'img' => $base . 'Imagenes/ImgUnion.png', 'txt' => 'Unirme equipo'],
            ['key' => 'equipo', 'url' => $base . 'Equipo/CrearEquipo.php', 'img' => $base . 'Imagenes/ImgCreacion.png', 'txt' => 'Crear equipo'],
            ['key' => 'solicitudes', 'url' => $base . 'Solicitudes.php', 'img' => $base . 'Imagenes/ImgSolicitud.png', 'txt' => 'Solicitud'],
            ['key' => 'equipo', 'url' => $base . 'Equipo/Mis_Equipos.php', 'img' => $base . 'Imagenes/ImgEquipo.png', 'txt' => 'Equipo'],
            ['key' => 'ligas', 'url' => $base . 'Ligas/liga.php', 'img' => $base . 'Imagenes/ImgLigas.png', 'txt' => 'Ligas'],
            ['key' => 'retar', 'url' => $base . 'Retar/retar.php', 'img' => $base . 'Imagenes/ImgReta.png', 'txt' => 'Retar'],
            ['key' => 'canchas', 'url' => $base . 'Canchas/Canchas.php', 'img' => $base . 'Imagenes/ImgCanchas.png', 'txt' => 'Canchas'],
            ['key' => 'amigos', 'url' => $base . 'Amigos.php', 'img' => $base . 'Imagenes/ImgAmigos.png', 'txt' => 'Amigos']
        ];
?>
<div class="retame-oficial-bg"></div>
<div class="retame-oficial-overlay" id="retameOfficialOverlay"></div>
<aside class="retame-oficial-sidebar" id="retameOfficialSidebar">
    <div class="retame-oficial-logo-area">
        <img src="<?php echo retame_esc($base . 'assets/doctor.png'); ?>" alt="Logo">
        <div><h2>RETAME</h2><p>Panel deportivo</p></div>
    </div>
    <a href="<?php echo retame_esc($base . 'MiPerfil.php'); ?>" class="retame-oficial-profile">
        <img src="<?php echo retame_esc($retameFotoPerfilUsuario); ?>" alt="Foto de perfil">
        <div><span>Perfil</span><strong><?php echo retame_esc($retameNombreUsuario); ?></strong></div>
    </a>
    <div class="retame-oficial-grid">
        <?php foreach ($links as $link): ?>
            <a href="<?php echo retame_esc($link['url']); ?>" class="retame-oficial-link <?php echo $active === $link['key'] ? 'active' : ''; ?>">
                <img src="<?php echo retame_esc($link['img']); ?>" alt="">
                <span><?php echo retame_esc($link['txt']); ?></span>
            </a>
        <?php endforeach; ?>
        <a href="<?php echo retame_esc($base . 'login.php'); ?>" class="retame-oficial-link retame-oficial-close">
            <img src="<?php echo retame_esc($base . 'Imagenes/ImgCerrar.png'); ?>" alt="">
            <span>Cerrar sesión</span>
        </a>
    </div>
    <div class="retame-oficial-info">
        <a href="<?php echo retame_esc($base . 'Informacion.php'); ?>">Información</a>
        <a href="<?php echo retame_esc($base . 'AcercaDe.php'); ?>">Acerca de</a>
        <a href="<?php echo retame_esc($base . 'SoporteTecnico.php'); ?>">Soporte técnico</a>
    </div>
    <div class="retame-oficial-mode">
        <strong>🌙 Modo oscuro</strong>
        <button type="button" class="retame-switch" id="retameDarkModeToggle" aria-label="Activar modo oscuro"><span></span></button>
    </div>
</aside>
<header class="retame-oficial-topbar">
    <button class="retame-oficial-menu-toggle" id="retameOfficialMenuToggle" type="button" aria-label="Abrir menú"><span></span></button>
    <div class="retame-oficial-title"><h1><?php echo retame_esc($titulo); ?></h1></div>
    <div class="retame-oficial-user"><span>👤</span><span><?php echo retame_esc($retameNombreUsuario); ?></span></div>
</header>
<nav class="retame-oficial-bottom">
    <a href="<?php echo retame_esc($base . 'Perfil2.php'); ?>" class="<?php echo $active === 'inicio' ? 'active' : ''; ?>" aria-label="Inicio"><img src="<?php echo retame_esc($base . 'Imagenes/ImgInicio.png'); ?>" alt=""></a>
    <a href="<?php echo retame_esc($base . 'Retar/retar.php'); ?>" class="<?php echo $active === 'retar' ? 'active' : ''; ?>" aria-label="Retar"><img src="<?php echo retame_esc($base . 'Imagenes/ImgReta.png'); ?>" alt=""></a>
    <a href="<?php echo retame_esc($base . 'Ligas/liga.php'); ?>" class="<?php echo $active === 'ligas' ? 'active' : ''; ?>" aria-label="Ligas"><img src="<?php echo retame_esc($base . 'Imagenes/ImgLigas.png'); ?>" alt=""></a>
    <a href="<?php echo retame_esc($base . 'Agenda.php'); ?>" class="<?php echo $active === 'agenda' ? 'active' : ''; ?>" aria-label="Agenda"><img src="<?php echo retame_esc($base . 'Imagenes/ImgAgenda.png'); ?>" alt=""></a>
    <a href="<?php echo retame_esc($base . 'Notificaciones.php'); ?>" class="<?php echo $active === 'notificaciones' ? 'active' : ''; ?>" aria-label="Notificaciones"><img src="<?php echo retame_esc($base . 'Imagenes/ImgNoti.png'); ?>" alt=""></a>
    <a href="<?php echo retame_esc($base . 'MiPerfil.php'); ?>" class="<?php echo $active === 'perfil' ? 'active' : ''; ?>" aria-label="Perfil"><img src="<?php echo retame_esc($base . 'Imagenes/ImgPerfil.png'); ?>" alt=""></a>
</nav>
<?php
    }
}

if (!function_exists('retame_render_global_js')) {
    function retame_render_global_js() {
        global $retameModoOscuroActivo;
?>
<script id="retame-oficial-js">
(function(){
const body=document.body;
const menu=document.getElementById('retameOfficialMenuToggle');
const overlay=document.getElementById('retameOfficialOverlay');
const dark=document.getElementById('retameDarkModeToggle');
const modoInicial=<?php echo $retameModoOscuroActivo ? 'true' : 'false'; ?>;
function esMovil(){return window.innerWidth<=820}
function aplicarModo(estado){body.classList.toggle('dark-mode',estado);if(dark){dark.setAttribute('aria-label',estado?'Desactivar modo oscuro':'Activar modo oscuro')}}
function guardarModo(estado){const datos=new FormData();datos.append('accion','actualizar_modo_perfil');datos.append('modo',estado?'oscuro':'predeterminado');return fetch(window.location.href,{method:'POST',body:datos,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}).then(r=>r.json())}
if(menu){menu.addEventListener('click',function(){if(esMovil()){body.classList.toggle('retame-sidebar-open')}else{body.classList.toggle('retame-sidebar-hidden')}})}
if(overlay){overlay.addEventListener('click',function(){body.classList.remove('retame-sidebar-open')})}
window.addEventListener('resize',function(){if(!esMovil()){body.classList.remove('retame-sidebar-open')}});
aplicarModo(modoInicial);
if(dark){dark.addEventListener('click',function(){const nuevo=!body.classList.contains('dark-mode');const anterior=!nuevo;aplicarModo(nuevo);dark.disabled=true;guardarModo(nuevo).then(function(data){if(!data||!data.ok){aplicarModo(anterior);alert(data&&data.mensaje?data.mensaje:'No se pudo guardar el modo de perfil.')}}).catch(function(){aplicarModo(anterior);alert('No se pudo conectar con la base de datos para guardar el modo.')}).finally(function(){dark.disabled=false})})}
})();
</script>
<?php
    }
}
?>
