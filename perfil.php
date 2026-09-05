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

include_once 'conexion.php';

$usuario = $_SESSION['usuario_data'] ?? [];
$Id_Retador = isset($usuario['Id_Retador']) ? trim((string)$usuario['Id_Retador']) : '';
$indicacionesVistas = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'marcar_indicaciones_vistas') {
    header('Content-Type: application/json; charset=utf-8');

    if ($Id_Retador === '') {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró el usuario en sesión.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sqlActualizar = "UPDATE retador SET estado_equipo = 1 WHERE Id_Retador = ? LIMIT 1";
    $stmtActualizar = $conn->prepare($sqlActualizar);

    if (!$stmtActualizar) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo preparar la actualización.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtActualizar->bind_param("s", $Id_Retador);
    $okActualizar = $stmtActualizar->execute();
    $stmtActualizar->close();

    if ($okActualizar) {
        $_SESSION['usuario_data']['estado_equipo'] = 1;

        echo json_encode([
            'ok' => true,
            'valor' => 1
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo guardar la preferencia.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($Id_Retador !== '') {
    $sqlConsulta = "SELECT estado_equipo FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmtConsulta = $conn->prepare($sqlConsulta);

    if ($stmtConsulta) {
        $stmtConsulta->bind_param("s", $Id_Retador);
        $stmtConsulta->execute();
        $resConsulta = $stmtConsulta->get_result();

        if ($filaConsulta = $resConsulta->fetch_assoc()) {
            $indicacionesVistas = (int)($filaConsulta['estado_equipo'] ?? 0);
            $_SESSION['usuario_data']['estado_equipo'] = $indicacionesVistas;
        }

        $stmtConsulta->close();
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
  <title>RETAME - Perfil informativo</title>

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

    :root {
      --azul: #00c3ff;
      --azul-fuerte: #008ee6;
      --azul-profundo: #0b5ed7;
      --rojo: #ff4b5c;
      --rojo-fuerte: #d92c3f;
      --texto: #111827;
      --texto-suave: #4b5563;
      --blanco: #ffffff;
      --sombra: 0 12px 38px rgba(0, 0, 0, 0.10);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      width: 100%;
      min-height: 100%;
      scroll-behavior: smooth;
    }

    body {
      min-height: 100dvh;
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #ffffff 0%, #f5fbff 50%, #fff7f9 100%);
      color: var(--texto);
      overflow-x: hidden;
      position: relative;
      padding: clamp(20px, 4vw, 45px);
    }

    .halo-bg {
      position: fixed;
      inset: 0;
      overflow: hidden;
      z-index: 0;
      background: linear-gradient(135deg, #ffffff 0%, #f5fbff 50%, #fff7f9 100%);
      pointer-events: none;
    }

    .rings {
      position: absolute;
      width: max(1900px, 230vmax);
      height: max(1900px, 230vmax);
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      transform-origin: center;
    }

    .rings-blue {
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

    .rings-red {
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

    .center-wave {
      position: absolute;
      width: clamp(650px, 95vw, 1300px);
      height: clamp(650px, 95vw, 1300px);
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      background:
        radial-gradient(circle,
          rgba(0, 195, 255, 0.13) 0%,
          rgba(0, 195, 255, 0.07) 18%,
          rgba(255, 75, 92, 0.09) 32%,
          transparent 58%);
      filter: blur(8px);
      animation: pulsoCentro 7s ease-in-out infinite;
    }

    .glow {
      position: absolute;
      border-radius: 50%;
      filter: blur(clamp(45px, 6vw, 90px));
      opacity: 1;
    }

    .glow-blue {
      width: clamp(420px, 55vw, 900px);
      height: clamp(420px, 55vw, 900px);
      left: clamp(-240px, -10vw, -80px);
      top: clamp(-80px, 2vw, 40px);
      background: radial-gradient(circle, rgba(0, 195, 255, 0.27), transparent 65%);
      animation: flotarAzul 10s ease-in-out infinite alternate;
    }

    .glow-red {
      width: clamp(420px, 55vw, 900px);
      height: clamp(420px, 55vw, 900px);
      right: clamp(-240px, -10vw, -80px);
      bottom: clamp(-180px, -5vw, -60px);
      background: radial-gradient(circle, rgba(255, 75, 92, 0.23), transparent 65%);
      animation: flotarRojo 12s ease-in-out infinite alternate;
    }

    @keyframes ondasAzules {
      0% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
      50% { transform: translate(-50%, -50%) scale(1.07) rotate(1deg); }
      100% { transform: translate(-50%, -50%) scale(1.14) rotate(-1deg); }
    }

    @keyframes ondasRojas {
      0% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
      50% { transform: translate(-50%, -50%) scale(1.05) rotate(-1deg); }
      100% { transform: translate(-50%, -50%) scale(1.12) rotate(1deg); }
    }

    @keyframes pulsoCentro {
      0% { transform: translate(-50%, -50%) scale(0.95); opacity: 0.75; }
      50% { transform: translate(-50%, -50%) scale(1.05); opacity: 1; }
      100% { transform: translate(-50%, -50%) scale(1.12); opacity: 0.82; }
    }

    @keyframes flotarAzul {
      0% { transform: translate(0px, 0px) scale(1); }
      100% { transform: translate(70px, 35px) scale(1.14); }
    }

    @keyframes flotarRojo {
      0% { transform: translate(0px, 0px) scale(1); }
      100% { transform: translate(-60px, -35px) scale(1.12); }
    }

    .page {
      position: relative;
      z-index: 2;
      width: min(100%, 1180px);
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: clamp(22px, 4vw, 36px);
      padding-bottom: clamp(20px, 5vw, 45px);
    }

    .hero {
      min-height: clamp(330px, 64dvh, 560px);
      display: flex;
      justify-content: center;
      align-items: center;
      text-align: center;
    }

    .hero-card,
    .section {
      border: 2px solid transparent;
      background:
        linear-gradient(rgba(255,255,255,0.96), rgba(255,255,255,0.96)) padding-box,
        linear-gradient(135deg, var(--azul), var(--rojo)) border-box;
      box-shadow:
        0 0 35px rgba(0, 195, 255, 0.23),
        0 0 42px rgba(255, 75, 92, 0.15),
        var(--sombra);
      backdrop-filter: blur(8px);
      animation: aparecer 0.8s ease;
    }

    .hero-card {
      width: min(100%, 920px);
      padding: clamp(32px, 6vw, 58px);
      border-radius: clamp(24px, 5vw, 34px);
    }

    .logo-circle {
      width: clamp(88px, 18vw, 125px);
      height: clamp(88px, 18vw, 125px);
      margin: 0 auto 22px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: clamp(2.5rem, 8vw, 4rem);
      border: 3px solid transparent;
      background:
        linear-gradient(rgba(255,255,255,0.92), rgba(255,255,255,0.92)) padding-box,
        linear-gradient(135deg, var(--azul), var(--rojo)) border-box;
      box-shadow:
        0 0 24px rgba(0, 195, 255, 0.25),
        0 0 28px rgba(255, 75, 92, 0.16),
        var(--sombra);
    }

    .hero h1 {
      font-family: 'Orbitron', sans-serif;
      font-size: clamp(2.4rem, 10vw, 5rem);
      color: var(--azul-fuerte);
      text-shadow: 0 0 12px rgba(255, 75, 92, 0.28);
      letter-spacing: clamp(2px, 1vw, 5px);
      margin-bottom: 16px;
      line-height: 1.1;
    }

    .hero h2 {
      font-size: clamp(1.1rem, 4vw, 1.65rem);
      color: var(--rojo-fuerte);
      margin-bottom: 18px;
      line-height: 1.35;
    }

    .hero p,
    .section-text {
      color: var(--texto-suave);
      line-height: 1.75;
      max-width: 850px;
      margin: 0 auto;
    }

    .hero p {
      font-size: clamp(0.95rem, 2.7vw, 1.12rem);
    }

    .section {
      width: 100%;
      padding: clamp(26px, 5vw, 44px);
      border-radius: clamp(22px, 4vw, 30px);
    }

    .section-title {
      font-family: 'Orbitron', sans-serif;
      font-size: clamp(1.35rem, 5vw, 2.2rem);
      color: var(--azul-fuerte);
      margin-bottom: 16px;
      text-align: center;
      line-height: 1.25;
    }

    .section-text {
      font-size: clamp(0.92rem, 2.6vw, 1.05rem);
      text-align: center;
    }

    .cards,
    .icon-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: clamp(16px, 3vw, 24px);
      margin-top: clamp(24px, 5vw, 34px);
    }

    .card,
    .icon-card,
    .step {
      background: rgba(255, 255, 255, 0.78);
      border-radius: 22px;
      border: 1px solid rgba(0, 195, 255, 0.24);
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.06);
      transition: 0.3s ease;
      text-align: center;
    }

    .card {
      padding: clamp(20px, 4vw, 28px);
    }

    .card:hover,
    .icon-card:hover,
    .step:hover {
      transform: translateY(-6px);
      box-shadow:
        0 0 20px rgba(0, 195, 255, 0.20),
        0 0 20px rgba(255, 75, 92, 0.14),
        0 12px 28px rgba(0, 0, 0, 0.08);
    }

    .card-icon {
      font-size: clamp(2.4rem, 7vw, 3.2rem);
      margin-bottom: 14px;
    }

    .card h3,
    .icon-card h3 {
      color: var(--rojo-fuerte);
      font-size: clamp(1rem, 3vw, 1.25rem);
      margin-bottom: 10px;
      line-height: 1.3;
    }

    .card p,
    .icon-card p,
    .step p {
      color: var(--texto-suave);
      font-size: clamp(0.85rem, 2.4vw, 0.96rem);
      line-height: 1.65;
    }

    .icon-card {
      padding: clamp(18px, 3.8vw, 26px);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .icon-box {
      width: 62px;
      height: 62px;
      border-radius: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      background:
        linear-gradient(rgba(255,255,255,0.92), rgba(255,255,255,0.92)) padding-box,
        linear-gradient(135deg, var(--azul), var(--rojo)) border-box;
      border: 2px solid transparent;
      box-shadow:
        0 0 16px rgba(0, 195, 255, 0.20),
        0 0 16px rgba(255, 75, 92, 0.14);
      font-size: 1.9rem;
      overflow: hidden;
    }

    .icon-box img {
      width: 38px;
      height: 38px;
      object-fit: contain;
      display: block;
    }

    .visual-box {
      margin-top: 28px;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .visual-item {
      padding: 22px;
      border-radius: 22px;
      background: rgba(0, 195, 255, 0.07);
      border: 1px solid rgba(0, 195, 255, 0.20);
      text-align: center;
    }

    .visual-item strong {
      display: block;
      color: var(--azul-profundo);
      margin-bottom: 8px;
      font-size: 1rem;
    }

    .visual-item span {
      color: var(--texto-suave);
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .steps {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: clamp(14px, 3vw, 22px);
      margin-top: clamp(24px, 5vw, 34px);
    }

    .step {
      padding: 22px 16px;
      border-color: rgba(255, 75, 92, 0.20);
    }

    .step-number {
      width: 44px;
      height: 44px;
      margin: 0 auto 12px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--azul), var(--rojo));
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Orbitron', sans-serif;
      font-weight: 700;
      box-shadow:
        0 0 16px rgba(0, 195, 255, 0.25),
        0 0 16px rgba(255, 75, 92, 0.18);
    }

    .start-area {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 16px;
      padding: clamp(10px, 3vw, 20px) 0 clamp(20px, 4vw, 35px);
    }

    .preferencia-indicaciones {
      width: min(100%, 520px);
      min-height: 58px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 18px;
      border: 2px solid rgba(0, 195, 255, 0.32);
      background: rgba(255, 255, 255, 0.86);
      box-shadow:
        0 0 18px rgba(0, 195, 255, 0.16),
        0 0 18px rgba(255, 75, 92, 0.10),
        0 10px 24px rgba(0, 0, 0, 0.07);
      color: var(--texto-suave);
      font-weight: 700;
      text-align: left;
      cursor: pointer;
      user-select: none;
    }

    .preferencia-indicaciones input {
      width: 22px;
      height: 22px;
      min-width: 22px;
      accent-color: var(--azul-fuerte);
      cursor: pointer;
    }

    .preferencia-indicaciones span {
      font-size: clamp(0.88rem, 2.7vw, 1rem);
      line-height: 1.35;
    }

    .preferencia-estado {
      width: min(100%, 520px);
      min-height: 20px;
      text-align: center;
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--azul-fuerte);
    }

    .btn-comenzar {
      width: min(100%, 380px);
      min-height: 56px;
      padding: 16px 24px;
      border-radius: 18px;
      border: none;
      text-decoration: none;
      color: #ffffff;
      background: linear-gradient(90deg, var(--azul), var(--rojo));
      font-family: 'Orbitron', sans-serif;
      font-size: clamp(1rem, 4vw, 1.25rem);
      font-weight: 700;
      letter-spacing: 1px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow:
        0 0 24px rgba(0, 195, 255, 0.32),
        0 0 24px rgba(255, 75, 92, 0.24),
        0 12px 30px rgba(0, 0, 0, 0.12);
      transition: 0.3s ease;
    }

    .btn-comenzar:hover {
      transform: scale(1.05);
      box-shadow:
        0 0 30px rgba(0, 195, 255, 0.45),
        0 0 30px rgba(255, 75, 92, 0.34),
        0 16px 34px rgba(0, 0, 0, 0.14);
    }

    @keyframes aparecer {
      from { opacity: 0; transform: translateY(18px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 1024px) {
      .cards,
      .icon-grid,
      .visual-box {
        grid-template-columns: repeat(2, 1fr);
      }

      .steps {
        grid-template-columns: repeat(2, 1fr);
      }

      .rings {
        width: max(1500px, 240vmax);
        height: max(1500px, 240vmax);
      }
    }

    @media (max-width: 768px) {
      body {
        padding: 16px;
      }

      .hero {
        min-height: auto;
        padding-top: 20px;
      }

      .cards,
      .icon-grid,
      .visual-box {
        grid-template-columns: 1fr;
      }

      .section {
        padding: 28px 18px;
      }

      .rings {
        width: max(1200px, 260vmax);
        height: max(1200px, 260vmax);
      }

      .center-wave {
        width: 850px;
        height: 850px;
      }
    }

    @media (max-width: 520px) {
      body {
        padding: 12px;
      }

      .hero-card {
        padding: 30px 18px;
      }

      .steps {
        grid-template-columns: 1fr;
      }

      .card,
      .icon-card,
      .step {
        padding: 22px 16px;
      }

      .btn-comenzar {
        width: 100%;
        min-height: 54px;
      }

      .rings {
        width: max(950px, 290vmax);
        height: max(950px, 290vmax);
      }

      .center-wave {
        width: 680px;
        height: 680px;
      }

      .glow-blue,
      .glow-red {
        width: 520px;
        height: 520px;
      }
    }

    @media (max-width: 360px) {
      .hero-card,
      .section {
        padding: 24px 14px;
      }

      .logo-circle {
        width: 80px;
        height: 80px;
      }
    }
  </style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>

<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('RETAME - Perfil informativo'); } ?>
  <div class="halo-bg">
    <div class="rings rings-blue"></div>
    <div class="rings rings-red"></div>
    <div class="center-wave"></div>
    <div class="glow glow-blue"></div>
    <div class="glow glow-red"></div>
  </div>

  <main class="page">
    <section class="hero">
      <div class="hero-card">
        <div class="logo-circle">🏆</div>
        <h1>RETAME</h1>
        <h2>Organiza, reta y compite de una forma más fácil</h2>
        <p>
          RETAME es una plataforma deportiva creada para conectar jugadores, equipos, canchas,
          ligas y aficionados en un solo sistema. Su función principal es ayudarte a organizar
          retas, administrar equipos, programar partidos y consultar información deportiva de forma rápida,
          ordenada y moderna.
        </p>
      </div>
    </section>

    <section class="section">
      <h2 class="section-title">¿Qué es RETAME?</h2>
      <p class="section-text">
        RETAME funciona como un centro deportivo digital. Desde aquí un usuario puede crear su perfil,
        formar o unirse a equipos, publicar retas, entrar a salas de espera, consultar sus partidos agendados,
        recibir notificaciones, revisar canchas disponibles y participar en ligas organizadas.
      </p>

      <div class="cards">
        <div class="card">
          <div class="card-icon">⚔️</div>
          <h3>Retas deportivas</h3>
          <p>
            Permite publicar partidos, buscar rivales, entrar a salas de espera y confirmar equipos antes de programar la reta.
          </p>
        </div>

        <div class="card">
          <div class="card-icon">👥</div>
          <h3>Equipos</h3>
          <p>
            Cada usuario puede crear equipos, unirse a equipos existentes y gestionar sus integrantes según sus permisos.
          </p>
        </div>

        <div class="card">
          <div class="card-icon">🏆</div>
          <h3>Gestor de ligas</h3>
          <p>
            Sirve para organizar competencias, registrar equipos participantes, consultar ligas y controlar mejor el avance deportivo.
          </p>
        </div>

        <div class="card">
          <div class="card-icon">🏟️</div>
          <h3>Gestión de canchas</h3>
          <p>
            Ayuda a consultar canchas, ver su información, ubicación, estado, costo y disponibilidad para jugar.
          </p>
        </div>

        <div class="card">
          <div class="card-icon">📣</div>
          <h3>Visualización de aficionados</h3>
          <p>
            Permite que más personas puedan conocer equipos, retas, ligas y actividad deportiva, ayudando a dar mayor alcance a los partidos.
          </p>
        </div>

        <div class="card">
          <div class="card-icon">📊</div>
          <h3>Control deportivo</h3>
          <p>
            Centraliza información de jugadores, equipos, partidos, solicitudes, favoritos y agenda deportiva.
          </p>
        </div>
      </div>
    </section>

    <section class="section">
      <h2 class="section-title">¿Para qué sirve cada botón del sistema?</h2>
      <p class="section-text">
        Estos son los botones principales que aparecen dentro de RETAME. Cada uno tiene una función específica para moverte dentro del sistema.
      </p>

      <div class="icon-grid">
        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgInicio.png" alt="Inicio" onerror="this.replaceWith(document.createTextNode('🏠'))"></div>
          <h3>Inicio</h3>
          <p>Te lleva al panel principal, donde puedes ver accesos rápidos y entrar a las secciones más importantes.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgUnion.png" alt="Unirme equipo" onerror="this.replaceWith(document.createTextNode('🤝'))"></div>
          <h3>Unirme equipo</h3>
          <p>Sirve para buscar un equipo existente y mandar o realizar la unión según el flujo del sistema.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgCreacion.png" alt="Crear equipo" onerror="this.replaceWith(document.createTextNode('➕'))"></div>
          <h3>Crear equipo</h3>
          <p>Permite registrar un nuevo equipo, definir sus datos y comenzar a administrarlo como creador o capitán.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgSolicitud.png" alt="Solicitud" onerror="this.replaceWith(document.createTextNode('📋'))"></div>
          <h3>Solicitud</h3>
          <p>Muestra solicitudes relacionadas con equipos, invitaciones o procesos pendientes dentro de la plataforma.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgEquipo.png" alt="Equipo" onerror="this.replaceWith(document.createTextNode('👥'))"></div>
          <h3>Equipo</h3>
          <p>Te permite consultar tus equipos, integrantes, información y opciones disponibles según tu rol.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgLigas.png" alt="Ligas" onerror="this.replaceWith(document.createTextNode('🏆'))"></div>
          <h3>Ligas</h3>
          <p>Abre el gestor de ligas para consultar, crear o administrar competencias deportivas.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgReta.png" alt="Retar" onerror="this.replaceWith(document.createTextNode('⚔️'))"></div>
          <h3>Retar</h3>
          <p>Sirve para publicar una reta, ver retas disponibles, entrar a la sala de espera y confirmar partidos.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgCanchas.png" alt="Canchas" onerror="this.replaceWith(document.createTextNode('🏟️'))"></div>
          <h3>Canchas</h3>
          <p>Muestra canchas registradas, su ubicación, estado, costo y datos importantes para jugar.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgAmigos.png" alt="Amigos" onerror="this.replaceWith(document.createTextNode('🧑‍🤝‍🧑'))"></div>
          <h3>Amigos</h3>
          <p>Permite consultar o gestionar contactos deportivos dentro de RETAME.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgAgenda.png" alt="Agenda" onerror="this.replaceWith(document.createTextNode('📅'))"></div>
          <h3>Agenda</h3>
          <p>Te muestra tus retas programadas, partidos pendientes y actividades deportivas en las que participas.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgNoti.png" alt="Notificaciones" onerror="this.replaceWith(document.createTextNode('🔔'))"></div>
          <h3>Notificaciones</h3>
          <p>Concentra avisos importantes, solicitudes, movimientos y actualizaciones de tu actividad deportiva.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgPerfil.png" alt="Perfil" onerror="this.replaceWith(document.createTextNode('👤'))"></div>
          <h3>Perfil</h3>
          <p>Te lleva a tu información personal, foto, datos deportivos y configuraciones de usuario.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box"><img src="Imagenes/ImgCerrar.png" alt="Cerrar sesión" onerror="this.replaceWith(document.createTextNode('🚪'))"></div>
          <h3>Cerrar sesión</h3>
          <p>Finaliza tu acceso al sistema y protege tu cuenta cuando termines de usar RETAME.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box">🌙</div>
          <h3>Modo oscuro</h3>
          <p>Cambia la apariencia visual del sistema a modo nocturno para usarlo con colores oscuros.</p>
        </div>

        <div class="icon-card">
          <div class="icon-box">ℹ️</div>
          <h3>Información, Acerca de y Soporte</h3>
          <p>Sirven para conocer detalles del proyecto, consultar ayuda y acceder al soporte técnico.</p>
        </div>
      </div>
    </section>

    <section class="section">
      <h2 class="section-title">Gestor de ligas, aficionados y canchas</h2>
      <p class="section-text">
        Además de retas y equipos, RETAME integra módulos para organizar competencias, mostrar actividad deportiva y apoyar la administración de espacios deportivos.
      </p>

      <div class="visual-box">
        <div class="visual-item">
          <strong>🏆 Gestor de ligas</strong>
          <span>Ayuda a registrar competencias, manejar participantes y tener control de la organización deportiva.</span>
        </div>

        <div class="visual-item">
          <strong>📣 Aficionados</strong>
          <span>Permite que la actividad deportiva tenga mayor visibilidad para jugadores, equipos y comunidad.</span>
        </div>

        <div class="visual-item">
          <strong>🏟️ Canchas</strong>
          <span>Facilita consultar datos de canchas, ubicación, precios, estado y detalles para organizar partidos.</span>
        </div>
      </div>
    </section>

    <section class="section">
      <h2 class="section-title">¿Cómo funciona?</h2>
      <p class="section-text">
        RETAME guía al usuario paso a paso para que pueda integrarse a la comunidad deportiva y comenzar a participar.
      </p>

      <div class="steps">
        <div class="step">
          <div class="step-number">1</div>
          <p>Crea tu cuenta o inicia sesión con tu usuario.</p>
        </div>

        <div class="step">
          <div class="step-number">2</div>
          <p>Crea un equipo o únete a uno que ya exista.</p>
        </div>

        <div class="step">
          <div class="step-number">3</div>
          <p>Publica una reta, busca rivales o participa en una liga.</p>
        </div>

        <div class="step">
          <div class="step-number">4</div>
          <p>Consulta tu agenda, notificaciones, canchas y actividad deportiva.</p>
        </div>
      </div>
    </section>

    <div class="start-area">
      <label class="preferencia-indicaciones" for="noMostrarIndicaciones">
        <input
          type="checkbox"
          id="noMostrarIndicaciones"
          <?php echo $indicacionesVistas === 1 ? 'checked disabled' : ''; ?>
        >
        <span>No mostrar indicaciones de nuevo</span>
      </label>
      <div class="preferencia-estado" id="preferenciaEstado">
        <?php echo $indicacionesVistas === 1 ? 'Preferencia guardada.' : ''; ?>
      </div>
      <a href="Perfil2.php" class="btn-comenzar">COMENZAR</a>
    </div>
  </main>
<script>
const noMostrarIndicaciones = document.getElementById('noMostrarIndicaciones');
const preferenciaEstado = document.getElementById('preferenciaEstado');
let guardandoPreferencia = false;

if (noMostrarIndicaciones) {
  noMostrarIndicaciones.addEventListener('change', () => {
    if (!noMostrarIndicaciones.checked || guardandoPreferencia) {
      return;
    }

    guardandoPreferencia = true;
    noMostrarIndicaciones.disabled = true;

    if (preferenciaEstado) {
      preferenciaEstado.textContent = 'Guardando preferencia...';
    }

    const datos = new FormData();
    datos.append('accion', 'marcar_indicaciones_vistas');

    fetch(window.location.href, {
      method: 'POST',
      body: datos,
      credentials: 'same-origin'
    })
    .then(respuesta => respuesta.json())
    .then(resultado => {
      if (!resultado.ok) {
        throw new Error(resultado.mensaje || 'No se pudo guardar la preferencia.');
      }

      if (preferenciaEstado) {
        preferenciaEstado.textContent = 'Preferencia guardada.';
      }
    })
    .catch(() => {
      noMostrarIndicaciones.checked = false;
      noMostrarIndicaciones.disabled = false;

      if (preferenciaEstado) {
        preferenciaEstado.textContent = 'No se pudo guardar. Intenta nuevamente.';
      }
    })
    .finally(() => {
      guardandoPreferencia = false;
    });
  });
}
</script>

<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>
