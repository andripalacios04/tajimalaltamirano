<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: login.php");
    exit();
}

include_once '../conexion.php';

$usuario = $_SESSION['usuario_data'];
$Nombre = $usuario['Nombre'] ?? 'Usuario';
$Id_Retador = $usuario['Id_Retador'] ?? '';
$CodigoPostalUsuario = trim((string)($usuario['CodigoPostal'] ?? ''));

$equipos_usuario = [];
$ligasAdmin = [];
$ligasNoAdmin = [];
$sugerenciasLigas = [];
$mensajeError = '';

if (!function_exists('liga_esc')) {
    function liga_esc($valor) {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('liga_fecha')) {
    function liga_fecha($fecha) {
        if (empty($fecha) || $fecha === '0000-00-00') {
            return 'Sin fecha';
        }
        return date('d/m/Y', strtotime($fecha));
    }
}

if (!function_exists('liga_estado_clase')) {
    function liga_estado_clase($estado) {
        $estado = strtolower(trim((string)$estado));

        if ($estado === 'activa' || $estado === 'iniciado' || $estado === 'iniciada' || $estado === 'inicio') {
            return 'estado-activa';
        }

        if ($estado === 'inscripciones' || $estado === 'inscripcion' || $estado === 'inscripción') {
            return 'estado-inscripciones';
        }

        if ($estado === 'finalizada' || $estado === 'cerrada') {
            return 'estado-finalizada';
        }

        return 'estado-default';
    }
}

if (!function_exists('liga_etiqueta_cp')) {
    function liga_etiqueta_cp($cpLiga, $cpUsuario) {
        $cpLiga = trim((string)$cpLiga);
        $cpUsuario = trim((string)$cpUsuario);

        if ($cpLiga === '' || $cpUsuario === '') {
            return '';
        }

        if ($cpLiga === $cpUsuario) {
            return 'Mismo código postal';
        }

        return 'Código postal cercano';
    }
}

if (!function_exists('generar_id_adminsolicitud_liga')) {
    function generar_id_adminsolicitud_liga($conn) {
        do {
            $nuevoId = 'ADL' . date('ymdHis') . rand(10, 99);

            $sql = "SELECT 1 FROM adminsolicitud WHERE id_adminsolicitud = ? LIMIT 1";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                return $nuevoId;
            }

            $stmt->bind_param("s", $nuevoId);
            $stmt->execute();
            $stmt->store_result();
            $existe = $stmt->num_rows > 0;
            $stmt->close();
        } while ($existe);

        return $nuevoId;
    }
}


$modoPerfilActual = '';
$modoOscuroActivo = false;
$FotoPerfilUsuario = '../assets/doctor.png';
$nuevas_count = 0;

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

if (!empty($Id_Retador) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seleccionar_admin_liga'])) {
    $id_liga_admin = trim($_POST['id_liga_admin'] ?? '');

    if ($id_liga_admin === '') {
        $mensajeError = 'No se recibió la liga a administrar.';
    } else {
        $sqlValidarLigaAdmin = "SELECT Id_Liga
                                FROM ligas
                                WHERE Id_Liga = ?
                                  AND (
                                        Id_Creador = ?
                                        OR COALESCE(Encargado1, '') = ?
                                        OR COALESCE(Encargado2, '') = ?
                                        OR COALESCE(Encargado3, '') = ?
                                        OR COALESCE(Encargado4, '') = ?
                                        OR COALESCE(Encargado5, '') = ?
                                  )
                                LIMIT 1";

        $stmtValidarLigaAdmin = $conn->prepare($sqlValidarLigaAdmin);

        if ($stmtValidarLigaAdmin) {
            $stmtValidarLigaAdmin->bind_param(
                "sssssss",
                $id_liga_admin,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador
            );
            $stmtValidarLigaAdmin->execute();
            $stmtValidarLigaAdmin->store_result();

            if ($stmtValidarLigaAdmin->num_rows > 0) {
                $stmtValidarLigaAdmin->close();

                try {
                    $conn->begin_transaction();

                    $id_adminsolicitud = '';

                    $sqlBuscarActiva = "SELECT id_adminsolicitud
                                        FROM adminsolicitud
                                        WHERE id_retador = ?
                                          AND Tipo = 'liga'
                                          AND Estado = 'Activo'
                                        ORDER BY id_adminsolicitud DESC
                                        LIMIT 1";
                    $stmtBuscarActiva = $conn->prepare($sqlBuscarActiva);

                    if ($stmtBuscarActiva) {
                        $stmtBuscarActiva->bind_param("s", $Id_Retador);
                        $stmtBuscarActiva->execute();
                        $stmtBuscarActiva->bind_result($id_adminsolicitud_tmp);

                        if ($stmtBuscarActiva->fetch()) {
                            $id_adminsolicitud = $id_adminsolicitud_tmp;
                        }

                        $stmtBuscarActiva->close();
                    }

                    if ($id_adminsolicitud !== '') {
                        $sqlActualizarAdmin = "UPDATE adminsolicitud
                                               SET id_admin = ?, Estado = 'Activo', Tipo = 'liga'
                                               WHERE id_adminsolicitud = ? AND id_retador = ?";
                        $stmtActualizarAdmin = $conn->prepare($sqlActualizarAdmin);

                        if (!$stmtActualizarAdmin) {
                            throw new Exception('No se pudo actualizar adminsolicitud.');
                        }

                        $stmtActualizarAdmin->bind_param("sss", $id_liga_admin, $id_adminsolicitud, $Id_Retador);

                        if (!$stmtActualizarAdmin->execute()) {
                            throw new Exception('No se pudo guardar la liga seleccionada.');
                        }

                        $stmtActualizarAdmin->close();
                    } else {
                        $id_adminsolicitud = generar_id_adminsolicitud_liga($conn);

                        $sqlInsertarAdmin = "INSERT INTO adminsolicitud (id_adminsolicitud, id_retador, id_admin, Estado, Tipo)
                                             VALUES (?, ?, ?, 'Activo', 'liga')";
                        $stmtInsertarAdmin = $conn->prepare($sqlInsertarAdmin);

                        if (!$stmtInsertarAdmin) {
                            throw new Exception('No se pudo crear adminsolicitud.');
                        }

                        $stmtInsertarAdmin->bind_param("sss", $id_adminsolicitud, $Id_Retador, $id_liga_admin);

                        if (!$stmtInsertarAdmin->execute()) {
                            throw new Exception('No se pudo registrar la liga seleccionada.');
                        }

                        $stmtInsertarAdmin->close();
                    }

                    $conn->commit();

                    header("Location: AdminLiga.php?id_liga=" . urlencode($id_liga_admin) . "&id_adminsolicitud=" . urlencode($id_adminsolicitud));
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $mensajeError = $e->getMessage();
                }
            } else {
                $stmtValidarLigaAdmin->close();
                $mensajeError = 'No tienes permisos para administrar esa liga.';
            }
        } else {
            $mensajeError = 'No se pudo validar la liga seleccionada.';
        }
    }
}

if (!empty($Id_Retador)) {
    $sqlEquipos = "SELECT DISTINCT e.Id_Equipo, e.Nombre
                   FROM equipo e
                   INNER JOIN equipo_jugador ej ON e.Id_Equipo = ej.Id_Equipo
                   WHERE ej.Id_Jugador = ?";
    $stmtEquipos = $conn->prepare($sqlEquipos);

    if ($stmtEquipos) {
        $stmtEquipos->bind_param("s", $Id_Retador);
        $stmtEquipos->execute();
        $resEquipos = $stmtEquipos->get_result();

        while ($fila = $resEquipos->fetch_assoc()) {
            $equipos_usuario[] = $fila;
        }

        $stmtEquipos->close();
    }

    $sqlAdmin = "SELECT DISTINCT
                    l.Id_Liga,
                    l.Nombre,
                    l.CodigoPostal,
                    l.FechaCreacion,
                    l.FechaInicio,
                    l.FechaFin,
                    l.Estado,
                    l.Id_Deporte,
                    l.Id_Creador,
                    l.Descripcion,
                    l.Encargado1,
                    l.Encargado2,
                    l.Encargado3,
                    l.Encargado4,
                    l.Encargado5,
                    d.Nombre AS DeporteNombre,
                    (
                        SELECT COUNT(*)
                        FROM liga_equipo le2
                        WHERE le2.Id_Liga = l.Id_Liga
                    ) AS EquiposInscritos
                 FROM ligas l
                 LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                 WHERE l.Id_Creador = ?
                    OR COALESCE(l.Encargado1, '') = ?
                    OR COALESCE(l.Encargado2, '') = ?
                    OR COALESCE(l.Encargado3, '') = ?
                    OR COALESCE(l.Encargado4, '') = ?
                    OR COALESCE(l.Encargado5, '') = ?
                 ORDER BY l.FechaCreacion DESC";
    $stmtAdmin = $conn->prepare($sqlAdmin);

    if ($stmtAdmin) {
        $stmtAdmin->bind_param(
            "ssssss",
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador
        );
        $stmtAdmin->execute();
        $resAdmin = $stmtAdmin->get_result();

        while ($fila = $resAdmin->fetch_assoc()) {
            $ligasAdmin[] = $fila;
        }

        $stmtAdmin->close();
    }

    $sqlNoAdmin = "SELECT DISTINCT
                        l.Id_Liga,
                        l.Nombre,
                        l.CodigoPostal,
                        l.FechaCreacion,
                        l.FechaInicio,
                        l.FechaFin,
                        l.Estado,
                        l.Id_Deporte,
                        l.Id_Creador,
                        l.Descripcion,
                        d.Nombre AS DeporteNombre,
                        (
                            SELECT COUNT(*)
                            FROM liga_equipo le2
                            WHERE le2.Id_Liga = l.Id_Liga
                        ) AS EquiposInscritos
                   FROM ligas l
                   LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                   INNER JOIN liga_equipo le ON le.Id_Liga = l.Id_Liga
                   INNER JOIN equipo_jugador ej ON ej.Id_Equipo = le.Id_Equipo
                   WHERE ej.Id_Jugador = ?
                     AND l.Id_Creador <> ?
                     AND COALESCE(l.Encargado1, '') <> ?
                     AND COALESCE(l.Encargado2, '') <> ?
                     AND COALESCE(l.Encargado3, '') <> ?
                     AND COALESCE(l.Encargado4, '') <> ?
                     AND COALESCE(l.Encargado5, '') <> ?
                   ORDER BY l.FechaCreacion DESC";
    $stmtNoAdmin = $conn->prepare($sqlNoAdmin);

    if ($stmtNoAdmin) {
        $stmtNoAdmin->bind_param(
            "sssssss",
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador,
            $Id_Retador
        );
        $stmtNoAdmin->execute();
        $resNoAdmin = $stmtNoAdmin->get_result();

        while ($fila = $resNoAdmin->fetch_assoc()) {
            $ligasNoAdmin[] = $fila;
        }

        $stmtNoAdmin->close();
    }

    $idsSugeridas = [];

    if ($CodigoPostalUsuario !== '' && preg_match('/^\d+$/', $CodigoPostalUsuario)) {
        $sqlSugerenciasIgual = "SELECT DISTINCT
                                    l.Id_Liga,
                                    l.Nombre,
                                    l.CodigoPostal,
                                    l.FechaCreacion,
                                    l.FechaInicio,
                                    l.FechaFin,
                                    l.Estado,
                                    l.Id_Deporte,
                                    l.Id_Creador,
                                    l.Descripcion,
                                    d.Nombre AS DeporteNombre,
                                    (
                                        SELECT COUNT(*)
                                        FROM liga_equipo le2
                                        WHERE le2.Id_Liga = l.Id_Liga
                                    ) AS EquiposInscritos,
                                    0 AS distancia_cp
                                FROM ligas l
                                LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                                WHERE TRIM(COALESCE(l.CodigoPostal, '')) = ?
                                  AND l.Id_Creador <> ?
                                  AND COALESCE(l.Encargado1, '') <> ?
                                  AND COALESCE(l.Encargado2, '') <> ?
                                  AND COALESCE(l.Encargado3, '') <> ?
                                  AND COALESCE(l.Encargado4, '') <> ?
                                  AND COALESCE(l.Encargado5, '') <> ?
                                  AND NOT EXISTS (
                                      SELECT 1
                                      FROM liga_equipo le
                                      INNER JOIN equipo_jugador ej ON ej.Id_Equipo = le.Id_Equipo
                                      WHERE le.Id_Liga = l.Id_Liga
                                        AND ej.Id_Jugador = ?
                                  )
                                ORDER BY l.FechaCreacion DESC";

        $stmtIgual = $conn->prepare($sqlSugerenciasIgual);

        if ($stmtIgual) {
            $stmtIgual->bind_param(
                "ssssssss",
                $CodigoPostalUsuario,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador
            );
            $stmtIgual->execute();
            $resIgual = $stmtIgual->get_result();

            while ($fila = $resIgual->fetch_assoc()) {
                $sugerenciasLigas[] = $fila;
                $idsSugeridas[] = $fila['Id_Liga'];
            }

            $stmtIgual->close();
        }

        if (count($sugerenciasLigas) < 7) {
            $sqlSugerenciasCercanas = "SELECT DISTINCT
                                            l.Id_Liga,
                                            l.Nombre,
                                            l.CodigoPostal,
                                            l.FechaCreacion,
                                            l.FechaInicio,
                                            l.FechaFin,
                                            l.Estado,
                                            l.Id_Deporte,
                                            l.Id_Creador,
                                            l.Descripcion,
                                            d.Nombre AS DeporteNombre,
                                            (
                                                SELECT COUNT(*)
                                                FROM liga_equipo le2
                                                WHERE le2.Id_Liga = l.Id_Liga
                                            ) AS EquiposInscritos,
                                            ABS(
                                                CAST(TRIM(l.CodigoPostal) AS SIGNED) - CAST(? AS SIGNED)
                                            ) AS distancia_cp
                                        FROM ligas l
                                        LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                                        WHERE TRIM(COALESCE(l.CodigoPostal, '')) REGEXP '^[0-9]+$'
                                          AND TRIM(COALESCE(l.CodigoPostal, '')) <> ?
                                          AND l.Id_Creador <> ?
                                          AND COALESCE(l.Encargado1, '') <> ?
                                          AND COALESCE(l.Encargado2, '') <> ?
                                          AND COALESCE(l.Encargado3, '') <> ?
                                          AND COALESCE(l.Encargado4, '') <> ?
                                          AND COALESCE(l.Encargado5, '') <> ?
                                          AND NOT EXISTS (
                                              SELECT 1
                                              FROM liga_equipo le
                                              INNER JOIN equipo_jugador ej ON ej.Id_Equipo = le.Id_Equipo
                                              WHERE le.Id_Liga = l.Id_Liga
                                                AND ej.Id_Jugador = ?
                                          )
                                        ORDER BY distancia_cp ASC, l.FechaCreacion DESC
                                        LIMIT 5";

            $stmtCercanas = $conn->prepare($sqlSugerenciasCercanas);

            if ($stmtCercanas) {
                $stmtCercanas->bind_param(
                    "sssssssss",
                    $CodigoPostalUsuario,
                    $CodigoPostalUsuario,
                    $Id_Retador,
                    $Id_Retador,
                    $Id_Retador,
                    $Id_Retador,
                    $Id_Retador,
                    $Id_Retador,
                    $Id_Retador
                );
                $stmtCercanas->execute();
                $resCercanas = $stmtCercanas->get_result();

                while ($fila = $resCercanas->fetch_assoc()) {
                    if (!in_array($fila['Id_Liga'], $idsSugeridas, true)) {
                        $sugerenciasLigas[] = $fila;
                        $idsSugeridas[] = $fila['Id_Liga'];
                    }
                }

                $stmtCercanas->close();
            }
        }
    } else {
        $sqlSugerencias = "SELECT DISTINCT
                                l.Id_Liga,
                                l.Nombre,
                                l.CodigoPostal,
                                l.FechaCreacion,
                                l.FechaInicio,
                                l.FechaFin,
                                l.Estado,
                                l.Id_Deporte,
                                l.Id_Creador,
                                l.Descripcion,
                                d.Nombre AS DeporteNombre,
                                (
                                    SELECT COUNT(*)
                                    FROM liga_equipo le2
                                    WHERE le2.Id_Liga = l.Id_Liga
                                ) AS EquiposInscritos
                           FROM ligas l
                           LEFT JOIN deporte d ON l.Id_Deporte = d.Id_Deporte
                           WHERE l.Id_Creador <> ?
                             AND COALESCE(l.Encargado1, '') <> ?
                             AND COALESCE(l.Encargado2, '') <> ?
                             AND COALESCE(l.Encargado3, '') <> ?
                             AND COALESCE(l.Encargado4, '') <> ?
                             AND COALESCE(l.Encargado5, '') <> ?
                             AND NOT EXISTS (
                                 SELECT 1
                                 FROM liga_equipo le
                                 INNER JOIN equipo_jugador ej ON ej.Id_Equipo = le.Id_Equipo
                                 WHERE le.Id_Liga = l.Id_Liga
                                   AND ej.Id_Jugador = ?
                             )
                           ORDER BY l.FechaCreacion DESC
                           LIMIT 10";

        $stmtSugerencias = $conn->prepare($sqlSugerencias);

        if ($stmtSugerencias) {
            $stmtSugerencias->bind_param(
                "sssssss",
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador,
                $Id_Retador
            );
            $stmtSugerencias->execute();
            $resSugerencias = $stmtSugerencias->get_result();

            while ($fila = $resSugerencias->fetch_assoc()) {
                $sugerenciasLigas[] = $fila;
            }

            $stmtSugerencias->close();
        }
    }
}

if (!empty($Id_Retador)) {
    $sqlModoSelect = "SELECT ModoPerfil, FotoPerfil FROM retador WHERE Id_Retador = ? LIMIT 1";
    $stmtModoSelect = $conn->prepare($sqlModoSelect);

    if ($stmtModoSelect) {
        $stmtModoSelect->bind_param("s", $Id_Retador);
        $stmtModoSelect->execute();
        $resModoSelect = $stmtModoSelect->get_result();

        if ($filaModo = $resModoSelect->fetch_assoc()) {
            $modoPerfilActual = isset($filaModo['ModoPerfil']) ? trim((string)$filaModo['ModoPerfil']) : '';
            $_SESSION['usuario_data']['ModoPerfil'] = $modoPerfilActual;

            $fotoPerfilBD = isset($filaModo['FotoPerfil']) ? trim((string)$filaModo['FotoPerfil']) : '';

            if ($fotoPerfilBD !== '') {
                if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $fotoPerfilBD)) {
                    $FotoPerfilUsuario = $fotoPerfilBD;
                } elseif (file_exists('../' . $fotoPerfilBD)) {
                    $FotoPerfilUsuario = '../' . $fotoPerfilBD;
                } elseif (file_exists('../Imagenes/' . $fotoPerfilBD)) {
                    $FotoPerfilUsuario = '../Imagenes/' . $fotoPerfilBD;
                } elseif (file_exists('../uploads/' . $fotoPerfilBD)) {
                    $FotoPerfilUsuario = '../uploads/' . $fotoPerfilBD;
                } elseif (file_exists('../FotosPerfil/' . $fotoPerfilBD)) {
                    $FotoPerfilUsuario = '../FotosPerfil/' . $fotoPerfilBD;
                } else {
                    $FotoPerfilUsuario = $fotoPerfilBD;
                }
            }
        }

        $stmtModoSelect->close();
    }
}

$modoNormalizado = function_exists('mb_strtolower')
    ? mb_strtolower(trim((string)$modoPerfilActual), 'UTF-8')
    : strtolower(trim((string)$modoPerfilActual));
$modoOscuroActivo = ($modoNormalizado === 'modo oscuro' || $modoNormalizado === 'moso oscuro');

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ligas - RETAME</title>
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
    --sidebar:280px;
    --sombra-azul:0 0 0 3px rgba(24,119,242,0.24), 0 12px 28px rgba(24,119,242,0.16);
    --sombra-roja:0 0 0 3px rgba(255,75,92,0.26), 0 12px 28px rgba(255,75,92,0.16);
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

.perfil-sidebar-card{
    width:100%;
    min-height:78px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px;
    text-decoration:none;
    border-radius:22px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(255,75,92,0.62);
    box-shadow:
        0 8px 18px rgba(0,0,0,0.07),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 14px rgba(0,153,255,0.15);
    transition:0.25s ease;
}

.perfil-sidebar-card:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,0.96);
    box-shadow:
        0 12px 22px rgba(0,0,0,0.10),
        0 0 0 3px rgba(0,153,255,0.26),
        0 0 18px rgba(0,153,255,0.20);
}

.perfil-sidebar-foto{
    width:52px;
    height:52px;
    min-width:52px;
    border-radius:18px;
    padding:3px;
    background:#ffffff;
    border:2px solid rgba(0,153,255,0.76);
    box-shadow:
        0 6px 14px rgba(0,0,0,0.09),
        0 0 0 2px rgba(255,75,92,0.18);
    overflow:hidden;
}

.perfil-sidebar-foto img{
    width:100%;
    height:100%;
    object-fit:cover;
    border-radius:14px;
    display:block;
}

.perfil-sidebar-info{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:2px;
    line-height:1.15;
}

.perfil-sidebar-info span{
    font-size:11px;
    font-weight:900;
    color:var(--rojo2);
}

.perfil-sidebar-info strong{
    max-width:140px;
    font-size:14px;
    font-weight:900;
    color:#374151;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
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

.cerrar-boceto:hover{
    transform:translateY(-2px);
    border-color:rgba(255,75,92,0.98);
    box-shadow:
        0 12px 22px rgba(0,0,0,0.10),
        0 0 0 3px rgba(0,153,255,0.28),
        0 0 18px rgba(0,153,255,0.18);
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

.info-boceto a:hover{
    color:var(--azul);
    transform:translateX(4px);
    border-color:rgba(0,153,255,0.96);
    box-shadow:
        0 8px 16px rgba(0,0,0,0.06),
        0 0 18px rgba(0,153,255,0.18);
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

.modo-oscuro-texto span{
    font-size:18px;
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
    padding:0;
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

body.dark-mode .switch-modo{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    box-shadow:
        inset 0 2px 5px rgba(0,0,0,0.25),
        0 0 0 2px rgba(255,75,92,0.44),
        0 0 16px rgba(0,153,255,0.26);
}

body.dark-mode .switch-modo span{
    transform:translateX(24px);
    background:#ffffff;
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

body.sidebar-hidden .topbar{
    left:0;
}

body.topbar-compact .topbar{
    height:58px;
    padding:7px 22px;
    box-shadow:
        0 3px 14px rgba(0,0,0,0.08),
        0 0 0 1px rgba(24,119,242,0.16),
        0 0 18px rgba(0,153,255,0.24);
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

.menu-toggle:hover{
    transform:translateY(-2px);
    color:var(--azul);
}

.menu-toggle span{
    width:25px;
    height:3px;
    background:#ffffff;
    position:relative;
    border-radius:999px;
    display:block;
    box-shadow:0 0 8px rgba(255,255,255,0.55);
}

.menu-toggle span::before,
.menu-toggle span::after{
    content:"";
    position:absolute;
    left:0;
    width:25px;
    height:3px;
    background:#ffffff;
    border-radius:999px;
    display:block;
    box-shadow:0 0 8px rgba(255,255,255,0.55);
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
    font-size:clamp(1.25rem,3vw,2rem);
    color:var(--azul);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

body.topbar-compact .topbar-title h1{
    font-size:clamp(1rem,2.4vw,1.45rem);
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

.ligas-page{
    max-width:1180px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:22px;
}

.ligas-hero{
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    border:1px solid rgba(17,24,39,0.05);
    padding:clamp(24px,4vw,42px);
    overflow:hidden;
    position:relative;
}

.ligas-hero::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 14% 18%,rgba(24,119,242,0.26),transparent 270px),
        radial-gradient(circle at 86% 82%,rgba(255,75,92,0.25),transparent 320px),
        linear-gradient(90deg,rgba(24,119,242,0.14),rgba(255,255,255,0.02) 46%,rgba(255,75,92,0.15));
}

.ligas-hero > *{
    position:relative;
    z-index:1;
}

.hero-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
}

.hero-copy{
    max-width:720px;
}

.kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo2);
    background:#fff;
    border:2px solid rgba(255,75,92,0.35);
    box-shadow:0 0 0 2px rgba(0,153,255,0.14);
    margin-bottom:12px;
}

.ligas-hero h2{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.8rem,5vw,2.7rem);
    margin-bottom:10px;
}

.ligas-hero p{
    color:#4b5563;
    font-size:clamp(0.96rem,2.4vw,1.08rem);
    line-height:1.7;
}

.hero-actions{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:20px;
}

.hero-btn{
    min-height:50px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 18px;
    border-radius:18px;
    text-decoration:none;
    font-size:13px;
    font-weight:900;
    transition:0.25s ease;
    border:2px solid transparent;
}

.hero-btn.primary{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    color:#fff;
    box-shadow:var(--sombra-roja);
}

.hero-btn.secondary{
    background:#ffffff;
    color:var(--azul);
    border-color:rgba(0,153,255,0.42);
    box-shadow:var(--sombra-azul);
}

.hero-btn:hover{
    transform:translateY(-3px);
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-top:22px;
}

.mini-stat{
    min-height:116px;
    border-radius:22px;
    background:rgba(255,255,255,0.96);
    border:2px solid rgba(0,153,255,0.26);
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.09);
    padding:16px;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

.mini-title{
    font-size:11px;
    color:#6b7280;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.45px;
    margin-bottom:6px;
}

.mini-value{
    font-family:'Orbitron',sans-serif;
    font-size:clamp(1.4rem,4vw,2.05rem);
    font-weight:900;
    color:var(--azul);
}

.equipos-chips{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:3px;
}

.chip{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 10px;
    border-radius:999px;
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    color:#ffffff;
    font-size:11px;
    font-weight:900;
    box-shadow:0 8px 16px rgba(255,75,92,0.18);
}

.alert{
    border-radius:18px;
    padding:15px 16px;
    background:#fff5f7;
    border:2px solid rgba(255,75,92,0.38);
    color:#b91c1c;
    font-weight:900;
    box-shadow:0 10px 22px rgba(255,75,92,0.10);
}

.liga-section{
    border-radius:28px;
    background:rgba(255,255,255,0.94);
    border:1px solid rgba(17,24,39,0.05);
    box-shadow:
        0 16px 34px rgba(0,0,0,0.10),
        0 0 0 1px rgba(24,119,242,0.12),
        0 0 18px rgba(0,153,255,0.14);
    padding:clamp(18px,3vw,26px);
}

.section-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.section-title{
    font-family:'Orbitron',sans-serif;
    color:var(--azul);
    font-size:clamp(1.1rem,3vw,1.55rem);
}

.section-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:8px 13px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo2);
    background:#ffffff;
    border:2px solid rgba(255,75,92,0.35);
    box-shadow:0 0 0 2px rgba(0,153,255,0.12);
}

.scroll-area{
    max-height:470px;
    overflow-y:auto;
    padding:4px 6px 4px 2px;
}

.scroll-area::-webkit-scrollbar{
    width:10px;
}

.scroll-area::-webkit-scrollbar-track{
    background:rgba(24,119,242,0.08);
    border-radius:999px;
}

.scroll-area::-webkit-scrollbar-thumb{
    background:linear-gradient(180deg,#ff4b5c,#1877f2);
    border-radius:999px;
}

.cards{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(250px,1fr));
    gap:16px;
}

.liga-card{
    min-height:300px;
    border-radius:24px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(0,153,255,0.28);
    box-shadow:
        0 12px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(255,75,92,0.09),
        0 0 15px rgba(0,153,255,0.10);
    overflow:hidden;
    display:flex;
    flex-direction:column;
    transition:0.25s ease;
}

.liga-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,75,92,0.70);
    box-shadow:
        0 18px 32px rgba(0,0,0,0.12),
        0 0 0 3px rgba(0,153,255,0.18),
        0 0 20px rgba(255,75,92,0.13);
}

.card-top{
    padding:16px 16px 12px;
    border-bottom:1px solid rgba(17,24,39,0.07);
    background:
        radial-gradient(circle at 10% 10%,rgba(24,119,242,0.18),transparent 150px),
        radial-gradient(circle at 90% 90%,rgba(255,75,92,0.16),transparent 170px);
}

.card-title{
    font-size:17px;
    font-weight:900;
    line-height:1.25;
    color:#111827;
    margin-bottom:11px;
    min-height:44px;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.card-meta{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.deporte{
    color:#4b5563;
    font-size:12px;
    font-weight:900;
}

.badge-estado{
    padding:6px 10px;
    border-radius:999px;
    font-size:10px;
    font-weight:900;
    white-space:nowrap;
}

.estado-activa{
    background:rgba(34,197,94,0.12);
    color:#15803d;
    border:1px solid rgba(34,197,94,0.24);
}

.estado-inscripciones{
    background:rgba(24,119,242,0.12);
    color:#1d4ed8;
    border:1px solid rgba(24,119,242,0.24);
}

.estado-finalizada{
    background:rgba(107,114,128,0.13);
    color:#4b5563;
    border:1px solid rgba(107,114,128,0.20);
}

.estado-default{
    background:rgba(255,75,92,0.10);
    color:var(--rojo2);
    border:1px solid rgba(255,75,92,0.22);
}

.tag-cp{
    display:inline-flex;
    margin-top:9px;
    padding:6px 10px;
    border-radius:999px;
    background:#ffffff;
    color:var(--azul);
    font-size:10px;
    font-weight:900;
    border:1px solid rgba(0,153,255,0.25);
}

.card-body{
    padding:14px 16px;
    flex:1;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.info{
    color:#374151;
    font-size:13px;
    line-height:1.45;
}

.info strong{
    color:#111827;
    font-weight:900;
}

.desc{
    color:#4b5563;
    font-size:13px;
    line-height:1.45;
    display:-webkit-box;
    -webkit-line-clamp:3;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.card-footer{
    padding:0 16px 16px;
}

.btn{
    width:100%;
    min-height:46px;
    border:0;
    text-decoration:none;
    border-radius:17px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:11px 14px;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    transition:0.25s ease;
}

.btn-admin{
    background:linear-gradient(135deg,#ff6d76,#ff4b5c,#ff3045);
    color:#fff;
    box-shadow:0 10px 18px rgba(255,75,92,0.20);
}

.btn-ver,
.btn-unir{
    background:linear-gradient(135deg,#1877f2,#0ea5e9);
    color:#fff;
    box-shadow:0 10px 18px rgba(24,119,242,0.18);
}

.btn:hover{
    transform:translateY(-2px);
}

.empty{
    min-height:110px;
    border-radius:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:24px;
    background:rgba(24,119,242,0.06);
    border:2px dashed rgba(0,153,255,0.28);
    color:#4b5563;
    font-weight:900;
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

.bottom-nav a:hover img{
    transform:scale(1.02);
    opacity:1;
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

.bottom-nav a.active img{
    transform:scale(1.03);
    opacity:1;
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
    color:#e5e7eb;
    background:#0b1220;
}

body.dark-mode .bg-particles{
    background:
        radial-gradient(circle at 18% 20%,rgba(0,153,255,0.22),transparent 390px),
        radial-gradient(circle at 84% 22%,rgba(255,75,92,0.18),transparent 410px),
        radial-gradient(circle at 50% 70%,rgba(87,117,255,0.14),transparent 360px),
        linear-gradient(135deg,#0b1220 0%,#111827 45%,#1f1117 100%);
}

body.dark-mode .sidebar,
body.dark-mode .topbar,
body.dark-mode .bottom-nav,
body.dark-mode .ligas-hero,
body.dark-mode .liga-section,
body.dark-mode .mini-stat,
body.dark-mode .liga-card,
body.dark-mode .perfil-sidebar-card,
body.dark-mode .accion-boceto,
body.dark-mode .modo-oscuro-panel,
body.dark-mode .info-boceto{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(255,75,92,0.78);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.24),
        0 0 18px rgba(0,153,255,0.18);
}

body.dark-mode .topbar-user,
body.dark-mode .menu-toggle,
body.dark-mode .kicker,
body.dark-mode .section-count,
body.dark-mode .hero-btn.secondary,
body.dark-mode .tag-cp{
    background:#0b1220;
    color:#e5e7eb;
}

body.dark-mode .logo-text h2,
body.dark-mode .topbar-title h1,
body.dark-mode .ligas-hero h2,
body.dark-mode .section-title,
body.dark-mode .mini-value,
body.dark-mode .action-card strong{
    color:#8fefff;
}

body.dark-mode .logo-text p,
body.dark-mode .ligas-hero p,
body.dark-mode .mini-title,
body.dark-mode .deporte,
body.dark-mode .info,
body.dark-mode .desc,
body.dark-mode .empty,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-texto,
body.dark-mode .perfil-sidebar-info strong,
body.dark-mode .accion-boceto,
body.dark-mode .topbar-user{
    color:#d1d5db;
}

body.dark-mode .perfil-sidebar-info span{
    color:#ff7b87;
}

body.dark-mode .perfil-sidebar-foto{
    background:#0b1220;
    border-color:rgba(0,153,255,0.90);
}

body.dark-mode .card-top{
    border-bottom-color:rgba(255,255,255,0.08);
}

body.dark-mode .card-title,
body.dark-mode .info strong{
    color:#f9fafb;
}

body.dark-mode .bottom-nav a img,
body.dark-mode .accion-boceto img,
body.dark-mode .cerrar-boceto img{
    filter:invert(1) hue-rotate(180deg) saturate(1.15) brightness(1.05);
}

body.dark-mode .info-boceto{
    background:transparent;
    border:0;
    box-shadow:none;
}

body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel{
    background:#111827;
    color:#e5e7eb;
    border-color:rgba(255,75,92,0.72);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.22),
        0 0 18px rgba(0,153,255,0.16);
}

body.dark-mode .menu-toggle span,
body.dark-mode .menu-toggle span::before,
body.dark-mode .menu-toggle span::after{
    background:#ffffff;
    box-shadow:0 0 8px rgba(255,255,255,0.60);
}

.switch-modo:disabled{
    opacity:0.65;
    cursor:not-allowed;
}

@media screen and (max-width:1050px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
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

    body.topbar-compact .topbar{
        height:58px;
        padding:7px 12px;
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
}

@media screen and (max-width:620px){
    body{
        padding-bottom:92px;
    }

    .topbar-title h1{
        font-size:1rem;
    }

    .ligas-hero,
    .liga-section{
        border-radius:24px;
    }

    .summary-grid{
        grid-template-columns:1fr;
    }

    .cards{
        grid-template-columns:1fr;
    }

    .card-title{
        min-height:auto;
    }

    .hero-actions{
        flex-direction:column;
    }

    .hero-btn{
        width:100%;
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
}
</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area sidebar-logo-boceto">
        <img src="../assets/doctor.png" alt="Logo Doctor" class="doctor-logo">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <a href="../MiPerfil.php" class="perfil-sidebar-card">
            <div class="perfil-sidebar-foto">
                <img src="<?php echo liga_esc($FotoPerfilUsuario); ?>" alt="Foto de perfil">
            </div>

            <div class="perfil-sidebar-info">
                <span>Perfil</span>
                <strong><?php echo liga_esc($Nombre); ?></strong>
            </div>
        </a>

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

            <a href="liga.php" class="accion-boceto active">
                <img src="../Imagenes/ImgLigas.png" alt="">
                <span>Ligas</span>
            </a>

            <a href="../Retar/retar.php" class="accion-boceto">
                <img src="../Imagenes/ImgReta.png" alt="">
                <span>Retar</span>
            </a>

            <a href="../Canchas/Canchas.php" class="accion-boceto">
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
            <a href="../AcercaDe.php">Acerca de</a>
            <a href="../SoporteTecnico.php">Soporte técnico</a>
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
        <h1>Ligas de <?php echo liga_esc($Nombre); ?></h1>
    </div>

    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo liga_esc($Nombre); ?></span>
    </div>
</header>

<main class="main-content">
    <div class="ligas-page">
        <section class="ligas-hero">
            <div class="hero-top">
                <div class="hero-copy">
                    <span class="kicker">🏆 Panel de ligas</span>
                    <h2>LIGAS</h2>
                    <p>
                        Administra tus ligas, revisa en cuáles participas y encuentra sugerencias cercanas
                        para seguir compitiendo dentro de RETAME.
                    </p>
                </div>
            </div>

            <div class="hero-actions">
                <a class="hero-btn primary" href="CrearLiga.php">➕ Crear una liga</a>
                <a class="hero-btn secondary" href="UnirmeLiga.php">🤝 Unirse a una liga</a>
            </div>

            <div class="summary-grid">
                <div class="mini-stat">
                    <div class="mini-title">Ligas que administras</div>
                    <div class="mini-value"><?php echo count($ligasAdmin); ?></div>
                </div>

                <div class="mini-stat">
                    <div class="mini-title">Ligas donde participas</div>
                    <div class="mini-value"><?php echo count($ligasNoAdmin); ?></div>
                </div>

                <div class="mini-stat">
                    <div class="mini-title">Sugerencias para unirte</div>
                    <div class="mini-value"><?php echo count($sugerenciasLigas); ?></div>
                </div>

                <div class="mini-stat">
                    <div class="mini-title">Tus equipos</div>
                    <?php if (!empty($equipos_usuario)): ?>
                        <div class="equipos-chips">
                            <?php foreach ($equipos_usuario as $equipo): ?>
                                <span class="chip"><?php echo liga_esc($equipo['Nombre']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="mini-value" style="font-size:16px;">Sin equipos</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($mensajeError !== ''): ?>
            <div class="alert"><?php echo liga_esc($mensajeError); ?></div>
        <?php endif; ?>

        <section class="liga-section">
            <div class="section-header">
                <div class="section-title">⚙️ Ligas que administro</div>
                <div class="section-count"><?php echo count($ligasAdmin); ?> ligas</div>
            </div>

            <div class="scroll-area">
                <?php if (!empty($ligasAdmin)): ?>
                    <div class="cards">
                        <?php foreach ($ligasAdmin as $liga): ?>
                            <div class="liga-card">
                                <div class="card-top">
                                    <div class="card-title"><?php echo liga_esc($liga['Nombre']); ?></div>
                                    <div class="card-meta">
                                        <div class="deporte"><?php echo liga_esc($liga['DeporteNombre'] ?: 'Sin deporte'); ?></div>
                                        <div class="badge-estado <?php echo liga_estado_clase($liga['Estado']); ?>">
                                            <?php echo liga_esc($liga['Estado'] ?: 'Sin estado'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="info"><strong>ID:</strong> <?php echo liga_esc($liga['Id_Liga']); ?></div>
                                    <div class="info"><strong>Código postal:</strong> <?php echo liga_esc($liga['CodigoPostal']); ?></div>
                                    <div class="info"><strong>Fecha inicio:</strong> <?php echo liga_fecha($liga['FechaInicio']); ?></div>
                                    <div class="info"><strong>Equipos inscritos:</strong> <?php echo (int)$liga['EquiposInscritos']; ?></div>
                                    <div class="desc"><?php echo liga_esc(trim($liga['Descripcion'] ?? '') !== '' ? $liga['Descripcion'] : 'Sin descripción disponible.'); ?></div>
                                </div>

                                <div class="card-footer">
                                    <form method="POST" action="" class="admin-form">
                                        <input type="hidden" name="id_liga_admin" value="<?php echo liga_esc($liga['Id_Liga']); ?>">
                                        <button type="submit" name="seleccionar_admin_liga" class="btn btn-admin">Administrar</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">No administras ninguna liga por el momento.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="liga-section">
            <div class="section-header">
                <div class="section-title">👁️ Ligas donde participo</div>
                <div class="section-count"><?php echo count($ligasNoAdmin); ?> ligas</div>
            </div>

            <div class="scroll-area">
                <?php if (!empty($ligasNoAdmin)): ?>
                    <div class="cards">
                        <?php foreach ($ligasNoAdmin as $liga): ?>
                            <div class="liga-card">
                                <div class="card-top">
                                    <div class="card-title"><?php echo liga_esc($liga['Nombre']); ?></div>
                                    <div class="card-meta">
                                        <div class="deporte"><?php echo liga_esc($liga['DeporteNombre'] ?: 'Sin deporte'); ?></div>
                                        <div class="badge-estado <?php echo liga_estado_clase($liga['Estado']); ?>">
                                            <?php echo liga_esc($liga['Estado'] ?: 'Sin estado'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="info"><strong>ID:</strong> <?php echo liga_esc($liga['Id_Liga']); ?></div>
                                    <div class="info"><strong>Código postal:</strong> <?php echo liga_esc($liga['CodigoPostal']); ?></div>
                                    <div class="info"><strong>Fecha inicio:</strong> <?php echo liga_fecha($liga['FechaInicio']); ?></div>
                                    <div class="info"><strong>Equipos inscritos:</strong> <?php echo (int)$liga['EquiposInscritos']; ?></div>
                                    <div class="desc"><?php echo liga_esc(trim($liga['Descripcion'] ?? '') !== '' ? $liga['Descripcion'] : 'Sin descripción disponible.'); ?></div>
                                </div>

                                <div class="card-footer">
                                    <a class="btn btn-ver" href="VerLiga.php?id_liga=<?php echo urlencode($liga['Id_Liga']); ?>">Ver</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">No estás participando en ligas donde seas solo miembro.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="liga-section">
            <div class="section-header">
                <div class="section-title">➕ Sugerencias de ligas</div>
                <div class="section-count"><?php echo count($sugerenciasLigas); ?> sugerencias</div>
            </div>

            <div class="scroll-area">
                <?php if (!empty($sugerenciasLigas)): ?>
                    <div class="cards">
                        <?php foreach ($sugerenciasLigas as $liga): ?>
                            <div class="liga-card">
                                <div class="card-top">
                                    <div class="card-title"><?php echo liga_esc($liga['Nombre']); ?></div>
                                    <div class="card-meta">
                                        <div class="deporte"><?php echo liga_esc($liga['DeporteNombre'] ?: 'Sin deporte'); ?></div>
                                        <div class="badge-estado <?php echo liga_estado_clase($liga['Estado']); ?>">
                                            <?php echo liga_esc($liga['Estado'] ?: 'Sin estado'); ?>
                                        </div>
                                    </div>
                                    <?php if ($CodigoPostalUsuario !== ''): ?>
                                        <div class="tag-cp"><?php echo liga_esc(liga_etiqueta_cp($liga['CodigoPostal'], $CodigoPostalUsuario)); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <div class="info"><strong>ID:</strong> <?php echo liga_esc($liga['Id_Liga']); ?></div>
                                    <div class="info"><strong>Código postal:</strong> <?php echo liga_esc($liga['CodigoPostal']); ?></div>
                                    <div class="info"><strong>Fecha inicio:</strong> <?php echo liga_fecha($liga['FechaInicio']); ?></div>
                                    <div class="info"><strong>Equipos inscritos:</strong> <?php echo (int)$liga['EquiposInscritos']; ?></div>
                                    <div class="desc"><?php echo liga_esc(trim($liga['Descripcion'] ?? '') !== '' ? $liga['Descripcion'] : 'Sin descripción disponible.'); ?></div>
                                </div>

                                <div class="card-footer">
                                    <a class="btn btn-unir" href="Unirmeliga.php?id_liga=<?php echo urlencode($liga['Id_Liga']); ?>">Unir equipo</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">No hay sugerencias disponibles por ahora.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<nav class="bottom-nav">
    <a href="../Perfil2.php" aria-label="Inicio">
        <img src="../Imagenes/ImgInicio.png" alt="">
    </a>

    <a href="../Retar/retar.php" aria-label="Retar">
        <img src="../Imagenes/ImgReta.png" alt="">
    </a>

    <a href="liga.php" class="active" aria-label="Ligas">
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

    <a href="../MiPerfil.php" aria-label="Perfil">
        <img src="../Imagenes/ImgPerfil.png" alt="">
    </a>
</nav>

<script>
const body = document.body;
const menuToggle = document.getElementById('menuToggle');
const mobileOverlay = document.getElementById('mobileOverlay');
const darkModeToggle = document.getElementById('darkModeToggle');
let lastScrollY = window.scrollY;

function closeSidebar(){
    body.classList.remove('sidebar-open');
}

if(menuToggle){
    menuToggle.addEventListener('click', () => {
        if(window.innerWidth <= 820){
            body.classList.toggle('sidebar-open');
        }else{
            body.classList.toggle('sidebar-hidden');
        }
    });
}

if(mobileOverlay){
    mobileOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', () => {
    if(window.innerWidth > 820){
        body.classList.remove('sidebar-open');
    }
});

window.addEventListener('scroll', () => {
    const currentY = window.scrollY;

    if(currentY > 20){
        body.classList.add('topbar-compact');
    }else{
        body.classList.remove('topbar-compact');
    }

    if(currentY > lastScrollY && currentY > 160){
        body.classList.add('bottom-nav-hidden');
    }else{
        body.classList.remove('bottom-nav-hidden');
    }

    lastScrollY = currentY;
}, { passive:true });

if(darkModeToggle){
    darkModeToggle.addEventListener('click', () => {
        const activarOscuro = !body.classList.contains('dark-mode');
        body.classList.toggle('dark-mode', activarOscuro);

        const datos = new FormData();
        datos.append('accion', 'actualizar_modo_perfil');
        datos.append('modo', activarOscuro ? 'oscuro' : 'predeterminado');

        fetch(window.location.href, {
            method:'POST',
            body:datos,
            credentials:'same-origin'
        }).catch(() => {});
    });
}
</script>

</body>
</html>
