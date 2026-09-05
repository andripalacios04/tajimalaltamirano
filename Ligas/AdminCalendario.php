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

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];
$Id_Retador = $usuarios['Id_Retador'] ?? '';

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerLigaAdministrada($conn, $idRetador) {
    $sql = "SELECT a.id_admin
            FROM adminsolicitud a
            INNER JOIN ligas l ON l.Id_Liga = a.id_admin
            WHERE a.id_retador = ? AND a.Estado = 'Activo'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param("s", $idRetador);
    $stmt->execute();
    $res = $stmt->get_result();

    $idLiga = '';
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $idLiga = $row['id_admin'];
    }

    $stmt->close();
    return $idLiga;
}

function obtenerTipoRegistroDeLiga($conn, $idLiga) {
    $sql = "SELECT d.tiporegistro
            FROM ligas l
            INNER JOIN deporte d ON d.Id_Deporte = l.Id_Deporte
            WHERE l.Id_Liga = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param("s", $idLiga);
    $stmt->execute();
    $res = $stmt->get_result();

    $tipoRegistro = '';
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $tipoRegistro = $row['tiporegistro'] ?? '';
    }

    $stmt->close();
    return $tipoRegistro;
}

function obtenerPaginaPorTipoRegistro($tipoRegistro) {
    $tipo = strtolower(trim((string)$tipoRegistro));
    $tipo = str_replace(' ', '', $tipo);

    $mapa = [
        'tiempo'   => 'AdminResultadosTiempo.php',
        'canastas' => 'AdminResultadosCanastas.php',
        'puntos'   => 'AdminResultadosPuntos.php',
        'sets'     => 'AdminResultadosSets.php',
        'carreras' => 'AdminResultadosCarreras.php',
        'goles'    => 'AdminResultadosGoles.php'
    ];

    return $mapa[$tipo] ?? '';
}

function generarClaveEnfrentamiento($equipoA, $equipoB) {
    $pares = [(string)$equipoA, (string)$equipoB];
    sort($pares, SORT_STRING);
    return $pares[0] . '__VS__' . $pares[1];
}

function obtenerFechaJornadaSemanal($fechaInicio, $numeroJornada) {
    $base = strtotime($fechaInicio);
    if ($base === false) {
        $base = time();
    }

    $semanas = max(0, ((int)$numeroJornada) - 1);
    return date('Y-m-d', strtotime('+' . $semanas . ' week', $base));
}

function construirJornadasBaseRoundRobin($equiposIds) {
    $ids = array_values($equiposIds);

    if (count($ids) < 2) {
        return [
            'jornadas_base' => [],
            'descansos_base' => [],
            'es_impar' => false
        ];
    }

    $esImpar = (count($ids) % 2 !== 0);
    if ($esImpar) {
        $ids[] = 'DESCANSO';
    }

    $rotacion = $ids;
    $num = count($rotacion);
    $jornadasBase = [];
    $descansosBase = [];

    for ($jornada = 1; $jornada < $num; $jornada++) {
        $jornadasBase[$jornada] = [];

        for ($i = 0; $i < $num / 2; $i++) {
            $equipo1 = $rotacion[$i];
            $equipo2 = $rotacion[$num - 1 - $i];

            if ($equipo1 === 'DESCANSO') {
                $descansosBase[$jornada][] = $equipo2;
                continue;
            }

            if ($equipo2 === 'DESCANSO') {
                $descansosBase[$jornada][] = $equipo1;
                continue;
            }

            $jornadasBase[$jornada][] = [
                'equipo1' => $equipo1,
                'equipo2' => $equipo2
            ];
        }

        $ultimo = array_pop($rotacion);
        array_splice($rotacion, 1, 0, [$ultimo]);
    }

    return [
        'jornadas_base' => $jornadasBase,
        'descansos_base' => $descansosBase,
        'es_impar' => $esImpar
    ];
}

function decidirLocalVisitanteBalanceado($equipo1, $equipo2, $jornadaReal, $indicePartido, $ultimoRol, $conteoLocal, $conteoVisitante) {
    $preferirEquipo1Local = false;

    if ($jornadaReal % 2 !== 0) {
        $preferirEquipo1Local = ($indicePartido % 2 === 0);
    } else {
        $preferirEquipo1Local = ($indicePartido % 2 !== 0);
    }

    $puntajeOpcion1 = 0;
    $puntajeOpcion2 = 0;

    if (($ultimoRol[$equipo1] ?? '') === 'L') {
        $puntajeOpcion1 += 4;
    }
    if (($ultimoRol[$equipo2] ?? '') === 'V') {
        $puntajeOpcion1 += 4;
    }

    if (($ultimoRol[$equipo1] ?? '') === 'V') {
        $puntajeOpcion2 += 4;
    }
    if (($ultimoRol[$equipo2] ?? '') === 'L') {
        $puntajeOpcion2 += 4;
    }

    $balanceEquipo1 = ($conteoLocal[$equipo1] ?? 0) - ($conteoVisitante[$equipo1] ?? 0);
    $balanceEquipo2 = ($conteoLocal[$equipo2] ?? 0) - ($conteoVisitante[$equipo2] ?? 0);

    if ($balanceEquipo1 > 0) {
        $puntajeOpcion1 += 2;
    }
    if ($balanceEquipo1 < 0) {
        $puntajeOpcion2 += 2;
    }

    if ($balanceEquipo2 > 0) {
        $puntajeOpcion2 += 2;
    }
    if ($balanceEquipo2 < 0) {
        $puntajeOpcion1 += 2;
    }

    if ($preferirEquipo1Local) {
        $puntajeOpcion2 += 1;
    } else {
        $puntajeOpcion1 += 1;
    }

    if ($puntajeOpcion1 <= $puntajeOpcion2) {
        return [
            'local' => $equipo1,
            'visitante' => $equipo2
        ];
    }

    return [
        'local' => $equipo2,
        'visitante' => $equipo1
    ];
}

function generarCalendarioBalanceado($equipos, $canRondas) {
    $equiposIds = array_column($equipos, 'Id_Equipo');
    $equiposNombres = array_column($equipos, 'Nombre', 'Id_Equipo');

    $base = construirJornadasBaseRoundRobin($equiposIds);
    $jornadasBase = $base['jornadas_base'];
    $descansosBase = $base['descansos_base'];

    $partidosPorJornada = [];
    $descansosPorJornada = [];
    $ultimoRol = [];
    $conteoLocal = [];
    $conteoVisitante = [];
    $ultimoEnfrentamiento = [];

    $totalJornadasBase = count($jornadasBase);

    if ($totalJornadasBase === 0) {
        return [
            'partidos_por_jornada' => [],
            'descansos_por_jornada' => []
        ];
    }

    for ($ronda = 1; $ronda <= $canRondas; $ronda++) {
        foreach ($jornadasBase as $jornadaBase => $enfrentamientos) {
            $jornadaReal = $jornadaBase + (($ronda - 1) * $totalJornadasBase);

            foreach ($enfrentamientos as $indicePartido => $par) {
                $equipo1 = $par['equipo1'];
                $equipo2 = $par['equipo2'];
                $clave = generarClaveEnfrentamiento($equipo1, $equipo2);

                if (isset($ultimoEnfrentamiento[$clave])) {
                    $orientacion = [
                        'local' => $ultimoEnfrentamiento[$clave]['visitante'],
                        'visitante' => $ultimoEnfrentamiento[$clave]['local']
                    ];
                } else {
                    $orientacion = decidirLocalVisitanteBalanceado(
                        $equipo1,
                        $equipo2,
                        $jornadaReal,
                        $indicePartido,
                        $ultimoRol,
                        $conteoLocal,
                        $conteoVisitante
                    );
                }

                $local = $orientacion['local'];
                $visitante = $orientacion['visitante'];

                $partidosPorJornada[$jornadaReal][] = [
                    'ronda' => $ronda,
                    'jornada' => $jornadaReal,
                    'local' => $local,
                    'visitante' => $visitante,
                    'nombre_local' => $equiposNombres[$local] ?? $local,
                    'nombre_visitante' => $equiposNombres[$visitante] ?? $visitante
                ];

                $ultimoRol[$local] = 'L';
                $ultimoRol[$visitante] = 'V';

                $conteoLocal[$local] = ($conteoLocal[$local] ?? 0) + 1;
                $conteoVisitante[$visitante] = ($conteoVisitante[$visitante] ?? 0) + 1;

                $ultimoEnfrentamiento[$clave] = [
                    'local' => $local,
                    'visitante' => $visitante
                ];
            }

            if (isset($descansosBase[$jornadaBase])) {
                foreach ($descansosBase[$jornadaBase] as $equipoDescansa) {
                    $descansosPorJornada[$jornadaReal][] = [
                        'equipo_id' => $equipoDescansa,
                        'nombre_equipo' => $equiposNombres[$equipoDescansa] ?? $equipoDescansa
                    ];
                }
            }
        }
    }

    ksort($partidosPorJornada);
    ksort($descansosPorJornada);

    return [
        'partidos_por_jornada' => $partidosPorJornada,
        'descansos_por_jornada' => $descansosPorJornada
    ];
}

function guardarCalendarioGenerado($conn, $calendarioGenerado, $idLiga, $idDeporte, $fechaInicioBase, $horaBase = '18:00:00') {
    $partidosInsertados = 0;
    $erroresDuplicados = 0;

    foreach ($calendarioGenerado['partidos_por_jornada'] as $jornada_num => $partidos_jornada) {
        $fecha_jornada = obtenerFechaJornadaSemanal($fechaInicioBase, $jornada_num);

        foreach ($partidos_jornada as $partido) {
            $id_partido = 'PART_' . $idLiga . '_' . $partido['local'] . '_' . $partido['visitante'] . '_' . $jornada_num . '_' . uniqid();

            $sql_insert = "INSERT INTO Calendario
                           (Id_Partido, id_equipolocal, id_equipovicitante, id_deporte, fecha, hora, Jornada, Estado, direccion, codigo_postal, descripcion, id_liga, Grupo)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $conn->prepare($sql_insert);

            if ($stmt_insert) {
                $estado = 'PROGRAMADO';
                $direccion = 'Por definir';
                $codigo_postal = '00000';
                $descripcion = "Partido de la jornada {$jornada_num} - Ronda {$partido['ronda']}";
                $grupo = 'A';

                $stmt_insert->bind_param(
                    "ssssssissssss",
                    $id_partido,
                    $partido['local'],
                    $partido['visitante'],
                    $idDeporte,
                    $fecha_jornada,
                    $horaBase,
                    $jornada_num,
                    $estado,
                    $direccion,
                    $codigo_postal,
                    $descripcion,
                    $idLiga,
                    $grupo
                );

                if ($stmt_insert->execute()) {
                    $partidosInsertados++;
                } else {
                    if ($conn->errno == 1062) {
                        $erroresDuplicados++;
                    }
                }

                $stmt_insert->close();
            }
        }
    }

    foreach ($calendarioGenerado['descansos_por_jornada'] as $jornada_num => $descansos_jornada) {
        $fecha_jornada = obtenerFechaJornadaSemanal($fechaInicioBase, $jornada_num);

        foreach ($descansos_jornada as $descanso) {
            $id_partido = 'DESC_' . $idLiga . '_' . $descanso['equipo_id'] . '_' . $jornada_num . '_' . uniqid();
            $estado = 'DESCANSO';
            $direccion = 'Por definir';
            $codigo_postal = '00000';
            $descripcion = "Descansa: " . $descanso['nombre_equipo'];
            $grupo = 'A';

            $sql_descanso = "INSERT INTO Calendario
                             (Id_Partido, id_equipolocal, id_equipovicitante, id_deporte, fecha, hora, Jornada, Estado, direccion, codigo_postal, descripcion, id_liga, Grupo)
                             VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_descanso = $conn->prepare($sql_descanso);

            if ($stmt_descanso) {
                $stmt_descanso->bind_param(
                    "ssssissssss",
                    $id_partido,
                    $idDeporte,
                    $fecha_jornada,
                    $horaBase,
                    $jornada_num,
                    $estado,
                    $direccion,
                    $codigo_postal,
                    $descripcion,
                    $idLiga,
                    $grupo
                );

                if ($stmt_descanso->execute()) {
                    $partidosInsertados++;
                } else {
                    if ($conn->errno == 1062) {
                        $erroresDuplicados++;
                    }
                }

                $stmt_descanso->close();
            }
        }
    }

    return [
        'insertados' => $partidosInsertados,
        'duplicados' => $erroresDuplicados
    ];
}

$mensajeError = '';
$mensajeExito = '';
$id_liga_admin = '';
$nombre_liga = '';
$estado_liga = '';
$partidos = [];
$equipos_disponibles = [];
$equipos_sin_partidos = [];
$mostrar_botones_header = false;
$mostrar_generar_partidos = true;
$primera_vez = '';

if (!empty($Id_Retador)) {
    $sql_limpiar = "DELETE asol
                    FROM adminsolicitud asol
                    WHERE asol.Estado = 'Activo'
                    AND asol.id_retador = ?
                    AND asol.Tipo = 'partido'
                    AND asol.id_admin IN (
                        SELECT Id_Partido FROM Calendario
                    )";

    $stmt_limpiar = $conn->prepare($sql_limpiar);
    if ($stmt_limpiar) {
        $stmt_limpiar->bind_param("s", $Id_Retador);
        $stmt_limpiar->execute();
        $stmt_limpiar->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_partido'])) {
    $id_partido = trim($_POST['id_partido_finalizar'] ?? '');
    $liga = trim($_POST['liga'] ?? '');

    if ($id_partido === '' || $liga === '' || $Id_Retador === '') {
        $mensajeError = "❌ Datos incompletos para finalizar el partido.";
    } else {
        $id_liga_admin_post = obtenerLigaAdministrada($conn, $Id_Retador);

        if ($id_liga_admin_post === '') {
            $mensajeError = "❌ No se encontró una liga activa que estés administrando.";
        } elseif ($id_liga_admin_post !== $liga) {
            $mensajeError = "❌ La liga enviada no coincide con la liga que administras.";
        } else {
            $sql_partido = "SELECT Id_Partido, Estado
                            FROM Calendario
                            WHERE Id_Partido = ? AND id_liga = ?
                            LIMIT 1";

            $stmt_partido = $conn->prepare($sql_partido);

            if (!$stmt_partido) {
                $mensajeError = "❌ Error al validar el partido.";
            } else {
                $stmt_partido->bind_param("ss", $id_partido, $liga);
                $stmt_partido->execute();
                $res_partido = $stmt_partido->get_result();

                if ($res_partido->num_rows === 0) {
                    $mensajeError = "❌ El partido no existe o no pertenece a la liga administrada.";
                    $stmt_partido->close();
                } else {
                    $partido_data = $res_partido->fetch_assoc();
                    $estado_actual = strtoupper(trim($partido_data['Estado'] ?? ''));
                    $stmt_partido->close();

                    if ($estado_actual === 'DESCANSO') {
                        $mensajeError = "❌ No puedes finalizar un registro de descanso.";
                    } else {
                        $tiporegistro = obtenerTipoRegistroDeLiga($conn, $liga);
                        $pagina_destino = obtenerPaginaPorTipoRegistro($tiporegistro);

                        if ($tiporegistro === '') {
                            $mensajeError = "❌ No se encontró el tipo de registro del deporte de la liga.";
                        } elseif ($pagina_destino === '') {
                            $mensajeError = "❌ El tipo de registro '" . e($tiporegistro) . "' no tiene página asignada.";
                        } else {
                            $todoBien = true;

                            if (method_exists($conn, 'begin_transaction')) {
                                $conn->begin_transaction();
                            }

                            $sql_borrar_prev = "DELETE FROM adminsolicitud
                                                WHERE id_admin = ? AND id_retador = ? AND Tipo = 'partido'";
                            $stmt_borrar_prev = $conn->prepare($sql_borrar_prev);

                            if ($stmt_borrar_prev) {
                                $stmt_borrar_prev->bind_param("ss", $id_partido, $Id_Retador);
                                if (!$stmt_borrar_prev->execute()) {
                                    $todoBien = false;
                                }
                                $stmt_borrar_prev->close();
                            } else {
                                $todoBien = false;
                            }

                            $id_adminsolicitud = 'ASOL_' . strtoupper(uniqid());

                            if ($todoBien) {
                                $sql_insert_adminsolicitud = "INSERT INTO adminsolicitud
                                                              (id_adminsolicitud, Estado, id_admin, id_retador, Tipo)
                                                              VALUES (?, 'Activo', ?, ?, 'partido')";
                                $stmt_insert = $conn->prepare($sql_insert_adminsolicitud);

                                if ($stmt_insert) {
                                    $stmt_insert->bind_param("sss", $id_adminsolicitud, $id_partido, $Id_Retador);
                                    if (!$stmt_insert->execute()) {
                                        $todoBien = false;
                                    }
                                    $stmt_insert->close();
                                } else {
                                    $todoBien = false;
                                }
                            }

                            if ($todoBien) {
                                $sql_update_partido = "UPDATE Calendario
                                                       SET Estado = 'FINALIZADO'
                                                       WHERE Id_Partido = ?";
                                $stmt_update = $conn->prepare($sql_update_partido);

                                if ($stmt_update) {
                                    $stmt_update->bind_param("s", $id_partido);
                                    if (!$stmt_update->execute()) {
                                        $todoBien = false;
                                    }
                                    $stmt_update->close();
                                } else {
                                    $todoBien = false;
                                }
                            }

                            if ($todoBien) {
                                if (method_exists($conn, 'commit')) {
                                    $conn->commit();
                                }

                                $query = http_build_query([
                                    'id_partido' => $id_partido,
                                    'liga' => $liga
                                ]);

                                header("Location: " . $pagina_destino . "?" . $query);
                                exit();
                            } else {
                                if (method_exists($conn, 'rollback')) {
                                    $conn->rollback();
                                }
                                $mensajeError = "❌ Ocurrió un error al preparar la administración del resultado.";
                            }
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_calendario'])) {
    $id_liga_admin = obtenerLigaAdministrada($conn, $Id_Retador);

    if ($id_liga_admin !== '') {
        $sql_borrar = "DELETE FROM Calendario WHERE id_liga = ?";
        $stmt_borrar = $conn->prepare($sql_borrar);

        if ($stmt_borrar) {
            $stmt_borrar->bind_param("s", $id_liga_admin);

            if ($stmt_borrar->execute()) {
                $filas_afectadas = $stmt_borrar->affected_rows;
                $mensajeExito = "✅ Se borraron {$filas_afectadas} partidos de la liga.";

                $sql_update_primera_vez = "UPDATE GenerarPartidos
                                           SET PrimeraVez = 'Sí'
                                           WHERE id_liga = ?
                                           ORDER BY id DESC
                                           LIMIT 1";
                $stmt_update_primera_vez = $conn->prepare($sql_update_primera_vez);

                if ($stmt_update_primera_vez) {
                    $stmt_update_primera_vez->bind_param("s", $id_liga_admin);
                    $stmt_update_primera_vez->execute();
                    $stmt_update_primera_vez->close();
                    $mensajeExito .= "<br>✅ Se actualizó PrimeraVez a 'Sí'.";
                }

                $sql_liga_info = "SELECT Nombre, Estado FROM ligas WHERE Id_Liga = ?";
                $stmt_liga_info = $conn->prepare($sql_liga_info);
                $stmt_liga_info->bind_param("s", $id_liga_admin);
                $stmt_liga_info->execute();
                $res_liga_info = $stmt_liga_info->get_result();

                $estado_liga_temp = 'Inscripciones';
                if ($res_liga_info->num_rows > 0) {
                    $liga_data = $res_liga_info->fetch_assoc();
                    $estado_liga_temp = $liga_data['Estado'];
                }
                $stmt_liga_info->close();

                if ($estado_liga_temp === 'Inscripciones') {
                    $sql_deporte = "SELECT Id_Deporte FROM ligas WHERE Id_Liga = ?";
                    $stmt_deporte = $conn->prepare($sql_deporte);
                    $stmt_deporte->bind_param("s", $id_liga_admin);
                    $stmt_deporte->execute();
                    $res_deporte = $stmt_deporte->get_result();

                    $id_deporte = '';
                    if ($res_deporte->num_rows > 0) {
                        $deporte_data = $res_deporte->fetch_assoc();
                        $id_deporte = $deporte_data['Id_Deporte'];
                    }
                    $stmt_deporte->close();

                    $sql_equipos = "SELECT le.Id_Equipo, e.Nombre
                                    FROM liga_equipo le
                                    INNER JOIN equipo e ON le.Id_Equipo = e.Id_Equipo
                                    WHERE le.Id_Liga = ? AND le.Estado = 'Activo'
                                    ORDER BY e.Nombre";
                    $stmt_equipos = $conn->prepare($sql_equipos);
                    $stmt_equipos->bind_param("s", $id_liga_admin);
                    $stmt_equipos->execute();
                    $res_equipos = $stmt_equipos->get_result();

                    $equipos = [];
                    while ($equipo = $res_equipos->fetch_assoc()) {
                        $equipos[] = $equipo;
                    }
                    $stmt_equipos->close();

                    $num_equipos = count($equipos);

                    if ($num_equipos >= 2) {
                        $canRondas = 1;
                        $formaGeneracion = 'TODOS_CONTRA_TODOS';
                        $fecha_inicio_calendario = date('Y-m-d');
                        $hora_base = '18:00:00';

                        $calendarioGenerado = generarCalendarioBalanceado($equipos, $canRondas);
                        $resultadoGuardado = guardarCalendarioGenerado(
                            $conn,
                            $calendarioGenerado,
                            $id_liga_admin,
                            $id_deporte,
                            $fecha_inicio_calendario,
                            $hora_base
                        );

                        $id_generacion = 'GEN_' . $id_liga_admin . '_' . date('YmdHis');
                        $sql_generar = "INSERT INTO GenerarPartidos
                                        (id, id_liga, id_retador, CanRondas, FormaGeneracion, PrimeraVez)
                                        VALUES (?, ?, ?, ?, ?, 'No')";
                        $stmt_generar = $conn->prepare($sql_generar);

                        if ($stmt_generar) {
                            $stmt_generar->bind_param("sssis", $id_generacion, $id_liga_admin, $Id_Retador, $canRondas, $formaGeneracion);
                            if ($stmt_generar->execute()) {
                                $mensajeExito .= "<br>✅ Se regeneraron {$resultadoGuardado['insertados']} registros automáticamente.";
                                $mensajeExito .= "<br>📅 Las jornadas fueron programadas semanalmente desde la fecha de hoy.";
                            }
                            $stmt_generar->close();
                        }
                    } else {
                        $mensajeExito .= "<br>ℹ️ No hay suficientes equipos para generar partidos.";
                    }
                } else {
                    $mensajeExito .= "<br>ℹ️ La liga no está en estado 'Inscripciones'.";
                }

                echo '<script>
                    setTimeout(function() {
                        window.location.href = window.location.href.split("?")[0];
                    }, 1300);
                </script>';
            } else {
                $mensajeError = "❌ Error al borrar partidos: " . $conn->error;
            }

            $stmt_borrar->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['borrar_todos'])) {
    $id_liga_admin = obtenerLigaAdministrada($conn, $Id_Retador);

    if ($id_liga_admin !== '') {
        $sql_borrar = "DELETE FROM Calendario WHERE id_liga = ?";
        $stmt_borrar = $conn->prepare($sql_borrar);

        if ($stmt_borrar) {
            $stmt_borrar->bind_param("s", $id_liga_admin);

            if ($stmt_borrar->execute()) {
                $filas_afectadas = $stmt_borrar->affected_rows;
                $mensajeExito = "✅ Se borraron {$filas_afectadas} partidos de la liga.";

                $sql_update_primera_vez = "UPDATE GenerarPartidos
                                           SET PrimeraVez = 'Sí'
                                           WHERE id_liga = ?
                                           ORDER BY id DESC
                                           LIMIT 1";
                $stmt_update_primera_vez = $conn->prepare($sql_update_primera_vez);

                if ($stmt_update_primera_vez) {
                    $stmt_update_primera_vez->bind_param("s", $id_liga_admin);
                    $stmt_update_primera_vez->execute();
                    $stmt_update_primera_vez->close();
                    $mensajeExito .= "<br>✅ Se actualizó PrimeraVez a 'Sí'.";
                }

                echo '<script>
                    setTimeout(function() {
                        window.location.href = window.location.href.split("?")[0];
                    }, 1300);
                </script>';
            } else {
                $mensajeError = "❌ Error al borrar partidos: " . $conn->error;
            }

            $stmt_borrar->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_partido'])) {
    $id_partido = trim($_POST['id_partido'] ?? '');
    $id_equipolocal = trim($_POST['id_equipolocal'] ?? '');
    $id_equipovicitante = trim($_POST['id_equipovicitante'] ?? '');
    $fecha = trim($_POST['fecha'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $grupo = trim($_POST['grupo'] ?? '');

    if ($id_equipolocal === $id_equipovicitante) {
        $mensajeError = "❌ El equipo local y visitante no pueden ser el mismo.";
    } else {
        $sql_update = "UPDATE Calendario
                       SET id_equipolocal = ?,
                           id_equipovicitante = ?,
                           fecha = ?,
                           hora = ?,
                           Estado = ?,
                           direccion = ?,
                           codigo_postal = ?,
                           descripcion = ?,
                           Grupo = ?,
                           fecha_actualizacion = CURRENT_TIMESTAMP
                       WHERE Id_Partido = ?";

        $stmt_update = $conn->prepare($sql_update);

        if ($stmt_update) {
            $stmt_update->bind_param(
                "ssssssssss",
                $id_equipolocal,
                $id_equipovicitante,
                $fecha,
                $hora,
                $estado,
                $direccion,
                $codigo_postal,
                $descripcion,
                $grupo,
                $id_partido
            );

            if ($stmt_update->execute()) {
                $mensajeExito = "✅ Partido actualizado correctamente.";
            } else {
                $mensajeError = "❌ Error al actualizar partido: " . $conn->error;
            }

            $stmt_update->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_partidos'])) {
    $canRondas = intval($_POST['canRondas'] ?? 0);
    $formaGeneracion = trim($_POST['formaGeneracion'] ?? '');
    $id_liga_admin_generar = obtenerLigaAdministrada($conn, $Id_Retador);

    if ($formaGeneracion === 'GRUPOS') {
        if ($id_liga_admin_generar !== '') {
            header("Location: AdminCalendarioGrupos.php?id_liga=" . urlencode($id_liga_admin_generar) . "&canRondas=" . urlencode($canRondas));
            exit();
        } else {
            $mensajeError = "❌ No se encontró la liga administrada.";
        }
    }

    if ($canRondas <= 0) {
        $mensajeError = "❌ La cantidad de rondas debe ser mayor a 0.";
    } elseif ($id_liga_admin_generar !== '') {
        $id_liga_admin = $id_liga_admin_generar;

        $sql_deporte = "SELECT Id_Deporte FROM ligas WHERE Id_Liga = ?";
        $stmt_deporte = $conn->prepare($sql_deporte);
        $stmt_deporte->bind_param("s", $id_liga_admin);
        $stmt_deporte->execute();
        $res_deporte = $stmt_deporte->get_result();

        $id_deporte = '';
        if ($res_deporte->num_rows > 0) {
            $deporte_data = $res_deporte->fetch_assoc();
            $id_deporte = $deporte_data['Id_Deporte'];
        }
        $stmt_deporte->close();

        $sql_equipos = "SELECT le.Id_Equipo, e.Nombre
                        FROM liga_equipo le
                        INNER JOIN equipo e ON le.Id_Equipo = e.Id_Equipo
                        WHERE le.Id_Liga = ? AND le.Estado = 'Activo'
                        ORDER BY e.Nombre";
        $stmt_equipos = $conn->prepare($sql_equipos);
        $stmt_equipos->bind_param("s", $id_liga_admin);
        $stmt_equipos->execute();
        $res_equipos = $stmt_equipos->get_result();

        $equipos = [];
        while ($equipo = $res_equipos->fetch_assoc()) {
            $equipos[] = $equipo;
        }
        $stmt_equipos->close();

        $num_equipos = count($equipos);

        if ($num_equipos < 2) {
            $mensajeError = "❌ Se necesitan al menos 2 equipos para generar partidos.";
        } else {
            $fecha_inicio_calendario = date('Y-m-d');
            $hora_base = '18:00:00';

            $calendarioGenerado = generarCalendarioBalanceado($equipos, $canRondas);
            $resultadoGuardado = guardarCalendarioGenerado(
                $conn,
                $calendarioGenerado,
                $id_liga_admin,
                $id_deporte,
                $fecha_inicio_calendario,
                $hora_base
            );

            $id_generacion = 'GEN_' . $id_liga_admin . '_' . date('YmdHis');
            $sql_generar = "INSERT INTO GenerarPartidos
                            (id, id_liga, id_retador, CanRondas, FormaGeneracion, PrimeraVez)
                            VALUES (?, ?, ?, ?, ?, 'No')";
            $stmt_generar = $conn->prepare($sql_generar);

            if ($stmt_generar) {
                $stmt_generar->bind_param("sssis", $id_generacion, $id_liga_admin, $Id_Retador, $canRondas, $formaGeneracion);
                if ($stmt_generar->execute()) {
                    $mensajeExito = "✅ Se generaron {$resultadoGuardado['insertados']} registros.";
                    $mensajeExito .= "<br>📅 Cada jornada fue programada con separación de una semana, iniciando desde hoy.";
                    $mensajeExito .= "<br>🏠✈️ La localía se alternó entre jornadas y se invierte cuando dos equipos se vuelven a enfrentar.";

                    if ($resultadoGuardado['duplicados'] > 0) {
                        $mensajeExito .= "<br>⚠️ Se omitieron {$resultadoGuardado['duplicados']} registros duplicados.";
                    }
                }
                $stmt_generar->close();
            }
        }
    } else {
        $mensajeError = "❌ No tienes ligas activas para administrar.";
    }
}

if (!empty($Id_Retador)) {
    $id_liga_admin = obtenerLigaAdministrada($conn, $Id_Retador);

    if ($id_liga_admin !== '') {
        $sql_liga_info = "SELECT Nombre, Estado FROM ligas WHERE Id_Liga = ?";
        $stmt_liga_info = $conn->prepare($sql_liga_info);
        $stmt_liga_info->bind_param("s", $id_liga_admin);
        $stmt_liga_info->execute();
        $res_liga_info = $stmt_liga_info->get_result();

        if ($res_liga_info->num_rows > 0) {
            $liga_data = $res_liga_info->fetch_assoc();
            $nombre_liga = $liga_data['Nombre'] ?? '';
            $estado_liga = $liga_data['Estado'] ?? '';

            if ($estado_liga === 'Inscripciones') {
                $mostrar_botones_header = true;

                $sql_equipos_liga = "SELECT le.Id_Equipo, e.Nombre
                                     FROM liga_equipo le
                                     INNER JOIN equipo e ON le.Id_Equipo = e.Id_Equipo
                                     WHERE le.Id_Liga = ? AND le.Estado = 'Activo'
                                     ORDER BY e.Nombre";
                $stmt_equipos_liga = $conn->prepare($sql_equipos_liga);
                $stmt_equipos_liga->bind_param("s", $id_liga_admin);
                $stmt_equipos_liga->execute();
                $res_equipos_liga = $stmt_equipos_liga->get_result();

                $todos_equipos = [];
                while ($equipo = $res_equipos_liga->fetch_assoc()) {
                    $todos_equipos[$equipo['Id_Equipo']] = $equipo['Nombre'];
                }
                $stmt_equipos_liga->close();

                $sql_equipos_con_partidos = "SELECT DISTINCT id_equipolocal as Id_Equipo FROM Calendario WHERE id_liga = ? AND id_equipolocal IS NOT NULL
                                             UNION
                                             SELECT DISTINCT id_equipovicitante as Id_Equipo FROM Calendario WHERE id_liga = ? AND id_equipovicitante IS NOT NULL";
                $stmt_equipos_con_partidos = $conn->prepare($sql_equipos_con_partidos);
                $stmt_equipos_con_partidos->bind_param("ss", $id_liga_admin, $id_liga_admin);
                $stmt_equipos_con_partidos->execute();
                $res_equipos_con_partidos = $stmt_equipos_con_partidos->get_result();

                $equipos_con_partidos = [];
                while ($equipo = $res_equipos_con_partidos->fetch_assoc()) {
                    $equipos_con_partidos[] = $equipo['Id_Equipo'];
                }
                $stmt_equipos_con_partidos->close();

                foreach ($todos_equipos as $id_equipo => $nombre) {
                    if (!in_array($id_equipo, $equipos_con_partidos, true)) {
                        $equipos_sin_partidos[] = [
                            'Id_Equipo' => $id_equipo,
                            'Nombre' => $nombre
                        ];
                    }
                }
            }
        }
        $stmt_liga_info->close();

        $sql_primera_vez = "SELECT PrimeraVez FROM GenerarPartidos WHERE id_liga = ? ORDER BY id DESC LIMIT 1";
        $stmt_primera_vez = $conn->prepare($sql_primera_vez);
        $stmt_primera_vez->bind_param("s", $id_liga_admin);
        $stmt_primera_vez->execute();
        $res_primera_vez = $stmt_primera_vez->get_result();

        if ($res_primera_vez->num_rows > 0) {
            $primera_vez_data = $res_primera_vez->fetch_assoc();
            $primera_vez = $primera_vez_data['PrimeraVez'] ?? '';

            if ($primera_vez === 'No') {
                $mostrar_generar_partidos = false;
            }

            if ($primera_vez === 'Si' || $primera_vez === 'Sí') {
                $mostrar_generar_partidos = true;
            }
        }
        $stmt_primera_vez->close();

        $sql_equipos = "SELECT Id_Equipo, Nombre
                        FROM equipo
                        WHERE Id_Equipo IN (
                            SELECT Id_Equipo
                            FROM liga_equipo
                            WHERE Id_Liga = ? AND Estado = 'Activo'
                        )
                        ORDER BY Nombre";
        $stmt_equipos = $conn->prepare($sql_equipos);
        $stmt_equipos->bind_param("s", $id_liga_admin);
        $stmt_equipos->execute();
        $res_equipos = $stmt_equipos->get_result();

        while ($equipo = $res_equipos->fetch_assoc()) {
            $equipos_disponibles[$equipo['Id_Equipo']] = $equipo['Nombre'];
        }
        $stmt_equipos->close();

        $sql_partidos = "SELECT
                            c.*,
                            el.Nombre as nombre_local,
                            ev.Nombre as nombre_visitante,
                            d.Nombre as nombre_deporte
                         FROM Calendario c
                         LEFT JOIN equipo el ON c.id_equipolocal = el.Id_Equipo
                         LEFT JOIN equipo ev ON c.id_equipovicitante = ev.Id_Equipo
                         LEFT JOIN deporte d ON c.id_deporte = d.Id_Deporte
                         WHERE c.id_liga = ?
                         ORDER BY c.Jornada ASC, c.fecha ASC, c.hora ASC";
        $stmt_partidos = $conn->prepare($sql_partidos);

        if ($stmt_partidos) {
            $stmt_partidos->bind_param("s", $id_liga_admin);
            $stmt_partidos->execute();
            $res_partidos = $stmt_partidos->get_result();

            while ($partido = $res_partidos->fetch_assoc()) {
                $partidos[] = $partido;
            }
            $stmt_partidos->close();
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
<title>Administración de Liga - RETAME</title>
<style>
:root{
    --bg-1:#081120;
    --bg-2:#0f1f3a;
    --bg-3:#17325c;
    --panel:#111b2c;
    --panel-2:#1a2639;
    --panel-3:#202f4f;
    --line:rgba(0,245,212,.22);
    --cyan:#00f5d4;
    --cyan-2:#06d6f0;
    --blue:#1a73ff;
    --violet:#6f5cff;
    --red:#ff3d71;
    --orange:#ff9f43;
    --green:#21d07a;
    --text:#f4f7fb;
    --muted:#9db1c9;
    --shadow:0 20px 60px rgba(0,0,0,.38);
    --radius:24px;
}
*{
    box-sizing:border-box;
}
html{
    scroll-behavior:smooth;
}
body{
    margin:0;
    font-family:Segoe UI, Tahoma, Geneva, Verdana, sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at 15% 20%, rgba(26,115,255,.22), transparent 18%),
        radial-gradient(circle at 85% 12%, rgba(0,245,212,.11), transparent 18%),
        radial-gradient(circle at 78% 84%, rgba(111,92,255,.20), transparent 20%),
        linear-gradient(115deg, var(--bg-1) 0%, var(--bg-2) 42%, var(--bg-3) 100%);
    min-height:100vh;
    overflow-x:hidden;
}
body::before,
body::after{
    content:"";
    position:fixed;
    inset:auto;
    pointer-events:none;
    z-index:0;
    border-radius:50%;
    filter:blur(80px);
    opacity:.65;
}
body::before{
    width:240px;
    height:240px;
    top:100px;
    left:280px;
    background:rgba(26,115,255,.25);
}
body::after{
    width:260px;
    height:260px;
    right:100px;
    bottom:40px;
    background:rgba(0,245,212,.15);
}
.app-shell{
    display:flex;
    min-height:100vh;
    position:relative;
    z-index:1;
}
.sidebar{
    width:240px;
    min-width:240px;
    background:linear-gradient(180deg, rgba(5,12,23,.96) 0%, rgba(6,17,32,.96) 100%);
    border-right:1px solid rgba(26,115,255,.35);
    box-shadow:0 0 30px rgba(0,0,0,.25);
    padding:22px 18px;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
}
.brand-box{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:12px;
    margin-bottom:26px;
}
.brand-ring{
    width:92px;
    height:92px;
    border-radius:50%;
    border:3px solid var(--cyan);
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    font-size:22px;
    color:#fff;
    box-shadow:0 0 24px rgba(0,245,212,.25), inset 0 0 18px rgba(0,245,212,.12);
    background:radial-gradient(circle at 30% 30%, rgba(255,255,255,.16), transparent 45%), rgba(0,0,0,.18);
}
.brand-title{
    font-size:28px;
    font-weight:900;
    letter-spacing:.8px;
}
.brand-sub{
    color:var(--muted);
    font-size:13px;
    margin-top:-4px;
}
.sidebar-nav{
    display:flex;
    flex-direction:column;
    gap:12px;
}
.sidebar-nav a{
    text-decoration:none;
    color:#ecf4ff;
    padding:14px 16px;
    border-radius:14px;
    background:linear-gradient(180deg, rgba(0,245,212,.09), rgba(0,245,212,.05));
    border:1px solid rgba(0,245,212,.22);
    font-weight:700;
    transition:.25s ease;
    display:flex;
    align-items:center;
    gap:10px;
}
.sidebar-nav a:hover{
    transform:translateX(4px);
    box-shadow:0 8px 26px rgba(0,245,212,.12);
    background:linear-gradient(180deg, rgba(0,245,212,.16), rgba(26,115,255,.12));
}
.main-content{
    margin-left:240px;
    width:calc(100% - 240px);
    padding:26px 28px 80px;
}
.page-title{
    text-align:center;
    margin:6px 0 22px;
}
.page-title h1{
    margin:0;
    font-size:clamp(28px, 3vw, 44px);
    font-weight:900;
    text-shadow:0 6px 24px rgba(0,0,0,.25);
}
.page-title p{
    margin:8px 0 0;
    color:var(--muted);
    font-size:15px;
}
.main-card{
    max-width:1180px;
    margin:0 auto;
    background:linear-gradient(180deg, rgba(17,27,44,.94), rgba(14,23,38,.94));
    border:1px solid rgba(26,115,255,.38);
    border-radius:30px;
    box-shadow:var(--shadow), 0 0 38px rgba(26,115,255,.18);
    overflow:hidden;
}
.section-head{
    padding:26px 34px 18px;
    text-align:center;
}
.section-head h2{
    margin:0;
    color:var(--cyan);
    font-size:clamp(24px, 2.4vw, 36px);
}
.section-head .divider{
    width:100%;
    height:2px;
    margin:16px auto 0;
    background:linear-gradient(90deg, transparent, var(--cyan), transparent);
    opacity:.75;
}
.content-wrap{
    padding:0 22px 28px;
}
.alert{
    border-radius:18px;
    padding:16px 18px;
    margin:0 0 18px;
    border:1px solid transparent;
    box-shadow:0 10px 28px rgba(0,0,0,.16);
}
.alert.error{
    background:rgba(255,61,113,.12);
    color:#ffd7e3;
    border-color:rgba(255,61,113,.35);
}
.alert.success{
    background:rgba(33,208,122,.12);
    color:#ddffe9;
    border-color:rgba(33,208,122,.35);
}
.header-tools{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:20px;
}
.tool-left,
.tool-right{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}
.btn{
    border:none;
    outline:none;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    min-height:48px;
    padding:0 20px;
    border-radius:16px;
    font-weight:800;
    color:#fff;
    transition:.25s ease;
    letter-spacing:.2px;
}
.btn:hover{
    transform:translateY(-2px);
}
.btn-back{
    background:linear-gradient(135deg, #7b2ff7, #3a66ff);
    box-shadow:0 12px 28px rgba(83,76,255,.28);
}
.btn-update{
    background:linear-gradient(135deg, #00c6ff, #0072ff);
    box-shadow:0 12px 28px rgba(0,114,255,.24);
}
.btn-delete{
    background:linear-gradient(135deg, #ff4d6d, #d90429);
    box-shadow:0 12px 28px rgba(217,4,41,.22);
}
.btn-generate{
    width:100%;
    background:linear-gradient(135deg, #0fd850, #00b09b);
    box-shadow:0 14px 32px rgba(0,176,155,.22);
}
.btn-edit{
    background:linear-gradient(135deg, #3b82f6, #1d4ed8);
    box-shadow:0 10px 24px rgba(37,99,235,.22);
}
.btn-save{
    background:linear-gradient(135deg, #2dd4bf, #0f766e);
}
.btn-cancel{
    background:linear-gradient(135deg, #64748b, #334155);
}
.btn-finish{
    background:linear-gradient(135deg, #22c55e, #15803d);
    box-shadow:0 10px 24px rgba(34,197,94,.22);
}
.section-box{
    background:linear-gradient(180deg, rgba(24,36,58,.96), rgba(18,28,46,.96));
    border:1px solid rgba(255,255,255,.06);
    border-radius:24px;
    overflow:hidden;
    margin-bottom:22px;
    box-shadow:0 12px 28px rgba(0,0,0,.18);
}
.section-box-title{
    padding:18px 22px;
    background:linear-gradient(90deg, rgba(0,245,212,.12), rgba(26,115,255,.12));
    border-bottom:1px solid rgba(0,245,212,.16);
    font-size:22px;
    font-weight:900;
    color:var(--cyan);
}
.league-grid{
    display:grid;
    grid-template-columns:260px 1fr;
}
.league-label{
    padding:18px 22px;
    background:linear-gradient(180deg, rgba(41,52,151,.55), rgba(33,43,122,.55));
    border-right:1px solid rgba(0,245,212,.18);
    border-bottom:1px solid rgba(255,255,255,.06);
    font-weight:800;
    color:var(--cyan);
    display:flex;
    align-items:center;
    gap:10px;
}
.league-value{
    padding:18px 18px;
    background:rgba(255,255,255,.02);
    border-bottom:1px solid rgba(255,255,255,.06);
}
.value-box{
    width:100%;
    min-height:44px;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.09);
    background:rgba(255,255,255,.05);
    color:#f7fbff;
    padding:11px 14px;
    font-weight:600;
}
.value-help{
    margin-top:8px;
    color:var(--muted);
    font-size:13px;
}
.count-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:88px;
    height:42px;
    border-radius:999px;
    font-weight:900;
    color:#fff;
    background:linear-gradient(135deg, #00c6ff, #0072ff);
    box-shadow:0 8px 22px rgba(0,114,255,.26);
}
.form-grid{
    padding:22px;
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    gap:18px;
}
.form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.form-group.wide{
    grid-column:1 / -1;
}
.form-label{
    font-size:14px;
    font-weight:800;
    color:#e9f4ff;
}
.form-input,
.form-select,
.form-textarea{
    width:100%;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.10);
    background:rgba(255,255,255,.06);
    color:#fff;
    padding:13px 14px;
    outline:none;
    font-size:14px;
}
.form-input:focus,
.form-select:focus,
.form-textarea:focus{
    border-color:rgba(0,245,212,.5);
    box-shadow:0 0 0 4px rgba(0,245,212,.10);
}
.form-textarea{
    min-height:110px;
    resize:vertical;
}
.helper-box{
    margin:0 22px 22px;
    border-radius:18px;
    background:rgba(0,245,212,.07);
    border:1px solid rgba(0,245,212,.16);
    padding:16px 18px;
    color:#defefa;
}
.helper-box p{
    margin:8px 0;
}
.warning-box{
    padding:20px 22px;
    margin-bottom:22px;
    border-radius:22px;
    background:rgba(255,159,67,.10);
    border:1px solid rgba(255,159,67,.25);
}
.warning-box h3{
    margin:0 0 8px;
    color:#ffd29b;
}
.badges{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:14px;
}
.team-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border-radius:999px;
    background:linear-gradient(135deg, #ff9f43, #ff6b00);
    color:#fff;
    font-weight:800;
    box-shadow:0 8px 20px rgba(255,107,0,.20);
}
.matches-title{
    margin:6px 0 18px;
    font-size:28px;
    font-weight:900;
}
.jornada-section{
    background:linear-gradient(180deg, rgba(22,35,58,.98), rgba(18,28,44,.98));
    border:1px solid rgba(255,255,255,.06);
    border-radius:24px;
    overflow:hidden;
    margin-bottom:22px;
    box-shadow:0 14px 34px rgba(0,0,0,.18);
}
.jornada-header{
    padding:18px 22px;
    background:linear-gradient(90deg, rgba(20,80,255,.78), rgba(0,198,255,.55));
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    font-size:20px;
    font-weight:900;
}
.partidos-grid{
    padding:20px;
    display:grid;
    gap:18px;
}
.partido-card{
    border-radius:22px;
    padding:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
    border:1px solid rgba(255,255,255,.06);
    box-shadow:0 12px 28px rgba(0,0,0,.18);
}
.partido-card.descanso-card{
    background:linear-gradient(180deg, rgba(111,92,255,.15), rgba(111,92,255,.05));
    border-color:rgba(111,92,255,.26);
}
.partido-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
}
.partido-id{
    color:var(--muted);
    font-size:13px;
    font-weight:700;
    word-break:break-all;
}
.estado-badge{
    padding:8px 14px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.estado-programado{background:rgba(255,159,67,.18); color:#ffd4ab; border:1px solid rgba(255,159,67,.38);}
.estado-en-curso{background:rgba(26,115,255,.18); color:#d4e4ff; border:1px solid rgba(26,115,255,.36);}
.estado-finalizado{background:rgba(33,208,122,.18); color:#d8ffe8; border:1px solid rgba(33,208,122,.36);}
.estado-cancelado{background:rgba(255,61,113,.18); color:#ffe0e8; border:1px solid rgba(255,61,113,.36);}
.estado-suspendido{background:rgba(111,92,255,.18); color:#e4ddff; border:1px solid rgba(111,92,255,.36);}
.estado-descanso{background:rgba(157,78,221,.18); color:#eedcff; border:1px solid rgba(157,78,221,.36);}
.match-board{
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:18px;
    align-items:center;
    text-align:center;
    margin:18px 0 16px;
}
.team-box{
    padding:18px 16px;
    border-radius:18px;
    font-weight:900;
    font-size:20px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
}
.team-box.local{
    color:#79d8ff;
}
.team-box.visitante{
    color:#ff8ba7;
}
.vs-box{
    width:78px;
    height:78px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    font-weight:900;
    color:#fff;
    background:linear-gradient(135deg, #1a73ff, #00c6ff);
    box-shadow:0 10px 24px rgba(0,114,255,.28);
}
.detail-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    gap:10px 16px;
    margin-top:12px;
}
.detail-item{
    border-radius:14px;
    padding:12px 14px;
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.05);
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    transition:.2s ease;
}
.detail-item:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,.05);
}
.detail-item span:first-child{
    color:var(--muted);
    font-weight:700;
}
.detail-item span:last-child{
    font-weight:800;
    text-align:right;
}
.descanso-text{
    padding:18px;
    border-radius:18px;
    text-align:center;
    font-size:22px;
    font-weight:900;
    color:#f3eaff;
    background:rgba(255,255,255,.04);
}
.partido-actions{
    display:flex;
    justify-content:flex-end;
    gap:12px;
    flex-wrap:wrap;
    margin-top:18px;
}
.edit-form{
    display:none;
    margin-top:18px;
    padding-top:18px;
    border-top:1px solid rgba(255,255,255,.08);
}
.edit-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    gap:14px 16px;
}
.edit-grid .wide{
    grid-column:1 / -1;
}
.action-row{
    margin-top:16px;
    display:flex;
    flex-wrap:wrap;
    gap:12px;
}
.empty-box{
    text-align:center;
    padding:34px 20px;
    border-radius:22px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
    color:var(--muted);
}
.fab-group{
    position:fixed;
    right:24px;
    bottom:24px;
    display:flex;
    flex-direction:column;
    gap:16px;
    z-index:10;
}
.fab{
    width:86px;
    height:86px;
    border-radius:50%;
    text-decoration:none;
    color:#fff;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:8px;
    box-shadow:0 16px 32px rgba(0,0,0,.28);
    border:2px solid rgba(255,255,255,.18);
}
.fab.blue{
    background:linear-gradient(135deg, #1aa4ff, #0d5eff);
}
.fab.red{
    background:linear-gradient(135deg, #ff2d55, #ff0000);
}
.small-note{
    color:var(--muted);
    font-size:13px;
}
@media (max-width:1100px){
    .league-grid{
        grid-template-columns:1fr;
    }
    .league-label{
        border-right:none;
    }
}
@media (max-width:900px){
    .sidebar{
        position:relative;
        width:100%;
        min-width:100%;
        height:auto;
        border-right:none;
        border-bottom:1px solid rgba(26,115,255,.35);
    }
    .main-content{
        margin-left:0;
        width:100%;
        padding:18px 16px 90px;
    }
    .app-shell{
        flex-direction:column;
    }
}
@media (max-width:720px){
    .section-head{
        padding:22px 18px 16px;
    }
    .content-wrap{
        padding:0 14px 18px;
    }
    .match-board{
        grid-template-columns:1fr;
    }
    .vs-box{
        margin:0 auto;
    }
    .partido-actions{
        justify-content:stretch;
    }
    .partido-actions .btn,
    .action-row .btn,
    .header-tools .btn{
        width:100%;
    }
    .fab{
        width:74px;
        height:74px;
        font-size:13px;
    }
}
</style>
<?php if (function_exists('retame_render_global_css')) { retame_render_global_css(); } ?>
</head>
<body class="<?php echo function_exists('retame_body_class') ? retame_body_class('retame-oficial-page') : 'retame-oficial-page'; ?>">
<?php if (function_exists('retame_render_shell')) { retame_render_shell('Administración de Liga - RETAME'); } ?>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand-box">
            <div class="brand-ring">R</div>
            <div class="brand-title">RETAME</div>
            <div class="brand-sub">Panel de administración</div>
        </div>

        <nav class="sidebar-nav">
            <a href="Adminliga.php">🏠 Inicio</a>
            <a href="UnirmeLiga.php">👥 Unirme a una liga</a>
            <a href="CrearLiga.php">🛡️ Crear una liga</a>
            <a href="Solicitudes.php">📋 Solicitudes</a>
            <a href="logout.php">🚪 Cerrar sesión</a>
        </nav>
    </aside>

    <main class="main-content" id="topPage">
        <div class="page-title">
            <h1>⚙️ Administración de Liga</h1>
            <p>Gestión visual de partidos, calendario y resultados</p>
        </div>

        <section class="main-card">
            <div class="section-head">
                <h2>📊 Información de la Liga</h2>
                <div class="divider"></div>
            </div>

            <div class="content-wrap">
                <div class="header-tools">
                    <div class="tool-left">
                        <a href="Adminliga.php" class="btn btn-back">↩️ Regresar a Liga</a>
                    </div>

                    <?php if (!empty($id_liga_admin) && $mostrar_botones_header): ?>
                    <div class="tool-right">
                        <form method="POST" action="" onsubmit="return confirmarActualizacion()">
                            <button type="submit" name="actualizar_calendario" class="btn btn-update">🔄 Actualizar Calendario</button>
                        </form>
                        <form method="POST" action="" onsubmit="return confirmarBorrado()">
                            <button type="submit" name="borrar_todos" class="btn btn-delete">🗑️ Borrar Todos</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($mensajeError !== ''): ?>
                    <div class="alert error"><?php echo $mensajeError; ?></div>
                <?php endif; ?>

                <?php if ($mensajeExito !== ''): ?>
                    <div class="alert success"><?php echo $mensajeExito; ?></div>
                <?php endif; ?>

                <?php if (!empty($id_liga_admin)): ?>
                    <div class="section-box">
                        <div class="section-box-title">🏆 Datos Generales</div>

                        <div class="league-grid">
                            <div class="league-label">🏆 Nombre de la Liga</div>
                            <div class="league-value">
                                <div class="value-box"><?php echo e($nombre_liga !== '' ? $nombre_liga : 'Liga'); ?></div>
                            </div>

                            <div class="league-label">🔢 ID de la Liga</div>
                            <div class="league-value">
                                <div class="value-box"><?php echo e($id_liga_admin); ?></div>
                            </div>

                            <div class="league-label">📌 Estado</div>
                            <div class="league-value">
                                <div class="value-box"><?php echo e($estado_liga !== '' ? $estado_liga : 'Sin estado'); ?></div>
                            </div>

                            <div class="league-label">👥 Equipos Activos</div>
                            <div class="league-value">
                                <div class="value-box"><?php echo count($equipos_disponibles); ?> equipos</div>
                            </div>

                            <div class="league-label">📅 Total de Registros</div>
                            <div class="league-value">
                                <div class="count-badge"><?php echo count($partidos); ?></div>
                            </div>

                            <div class="league-label">🛠️ Primera Vez</div>
                            <div class="league-value">
                                <div class="value-box"><?php echo e($primera_vez !== '' ? $primera_vez : 'No registrado'); ?></div>
                                <div class="value-help">Control interno de generación de calendario.</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($estado_liga === 'Inscripciones' && !empty($equipos_sin_partidos)): ?>
                        <div class="warning-box">
                            <h3>⚠️ Equipos inscritos sin partidos programados</h3>
                            <div>Estos equipos están activos en la liga pero aún no aparecen en el calendario.</div>
                            <div class="badges">
                                <?php foreach ($equipos_sin_partidos as $equipo): ?>
                                    <div class="team-badge">⚽ <?php echo e($equipo['Nombre']); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="small-note" style="margin-top:14px;">Usa “Actualizar Calendario” para incluirlos automáticamente.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($mostrar_generar_partidos): ?>
                        <div class="section-box">
                            <div class="section-box-title">🎯 Generar Nuevos Partidos</div>

                            <form method="POST" action="" id="formGenerarPartidos">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="canRondas">Cantidad de Rondas</label>
                                        <input class="form-input" type="number" id="canRondas" name="canRondas" min="1" max="10" value="1" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="formaGeneracion">Forma de Generación</label>
                                        <select class="form-select" id="formaGeneracion" name="formaGeneracion" required>
                                            <option value="TODOS_CONTRA_TODOS">Todos contra Todos</option>
                                            <option value="GRUPOS">Sistema por Grupos</option>
                                        </select>
                                    </div>

                                    <div class="form-group wide">
                                        <button type="submit" name="generar_partidos" class="btn btn-generate">⚽ Generar Nuevos Partidos</button>
                                    </div>
                                </div>
                            </form>

                            <div class="helper-box">
                                <p><strong>Modo Todos contra Todos:</strong> genera jornadas automáticas con Round Robin.</p>
                                <p><strong>Modo por Grupos:</strong> te envía a la configuración de grupos.</p>
                                <p><strong>Programación semanal:</strong> cada jornada se agenda cada 7 días, iniciando desde el día en que generas el calendario.</p>
                                <p><strong>Local / Visitante:</strong> el sistema intenta alternar la localía entre jornadas y revierte la localía cuando los equipos se vuelven a enfrentar.</p>
                                <p><strong>Equipos:</strong> <?php echo count($equipos_disponibles); ?> | <strong>Tipo:</strong> <?php echo (count($equipos_disponibles) % 2 === 0) ? 'Par' : 'Impar'; ?></p>
                                <p><strong>Jornadas por ronda:</strong> <?php echo (count($equipos_disponibles) > 0) ? ((count($equipos_disponibles) % 2 === 0) ? count($equipos_disponibles) - 1 : count($equipos_disponibles)) : 0; ?></p>
                                <p><strong>Partidos por jornada:</strong> <?php echo floor(count($equipos_disponibles) / 2); ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="section-box">
                            <div class="section-box-title">📅 Calendario Generado</div>
                            <div class="helper-box" style="margin:22px;">
                                <p>El calendario ya fue generado anteriormente.</p>
                                <p>Puedes editar los partidos o registrar resultados desde abajo.</p>
                                <?php if ($estado_liga === 'Inscripciones'): ?>
                                    <p><strong>Sugerencia:</strong> si agregaste equipos nuevos, usa “Actualizar Calendario”.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="matches-title">📅 Partidos Programados</div>

                    <?php if (!empty($partidos)): ?>
                        <?php
                        $partidos_por_jornada = [];
                        foreach ($partidos as $partido) {
                            $jornada = $partido['Jornada'];
                            if (!isset($partidos_por_jornada[$jornada])) {
                                $partidos_por_jornada[$jornada] = [];
                            }
                            $partidos_por_jornada[$jornada][] = $partido;
                        }
                        ksort($partidos_por_jornada);
                        ?>

                        <?php foreach ($partidos_por_jornada as $jornada_num => $partidos_jornada): ?>
                            <div class="jornada-section">
                                <div class="jornada-header">
                                    <div>🏆 Jornada <?php echo e($jornada_num); ?></div>
                                    <div><?php echo count($partidos_jornada); ?> registro(s)</div>
                                </div>

                                <div class="partidos-grid">
                                    <?php foreach ($partidos_jornada as $partido): ?>
                                        <?php
                                        $estadoClase = strtolower(str_replace([' ', '_'], '-', (string)$partido['Estado']));
                                        $fechaTexto = !empty($partido['fecha']) ? date('d/m/Y', strtotime($partido['fecha'])) : 'Sin fecha';
                                        $horaTexto = !empty($partido['hora']) ? date('H:i', strtotime($partido['hora'])) : 'Sin hora';
                                        ?>
                                        <?php if (($partido['Estado'] ?? '') === 'DESCANSO'): ?>
                                            <div class="partido-card descanso-card" id="partido-<?php echo e($partido['Id_Partido']); ?>">
                                                <div class="partido-header">
                                                    <div class="partido-id">ID: <?php echo e($partido['Id_Partido']); ?></div>
                                                    <div class="estado-badge estado-descanso">DESCANSO</div>
                                                </div>

                                                <div class="descanso-text">🏖️ <?php echo e($partido['descripcion']); ?></div>

                                                <div class="detail-grid">
                                                    <div class="detail-item"><span>Fecha</span><span><?php echo e($fechaTexto); ?></span></div>
                                                    <div class="detail-item"><span>Hora</span><span><?php echo e($horaTexto); ?></span></div>
                                                    <div class="detail-item"><span>Jornada</span><span><?php echo e($partido['Jornada']); ?></span></div>
                                                    <div class="detail-item"><span>Deporte</span><span><?php echo e($partido['nombre_deporte'] ?: 'No especificado'); ?></span></div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="partido-card" id="partido-<?php echo e($partido['Id_Partido']); ?>">
                                                <div class="partido-header">
                                                    <div class="partido-id">ID: <?php echo e($partido['Id_Partido']); ?></div>
                                                    <div class="estado-badge estado-<?php echo e($estadoClase); ?>"><?php echo e($partido['Estado']); ?></div>
                                                </div>

                                                <div class="match-board">
                                                    <div class="team-box local"><?php echo e($partido['nombre_local'] ?: $partido['id_equipolocal']); ?></div>
                                                    <div class="vs-box">VS</div>
                                                    <div class="team-box visitante"><?php echo e($partido['nombre_visitante'] ?: $partido['id_equipovicitante']); ?></div>
                                                </div>

                                                <div class="detail-grid">
                                                    <div class="detail-item"><span>Fecha</span><span><?php echo e($fechaTexto); ?></span></div>
                                                    <div class="detail-item"><span>Hora</span><span><?php echo e($horaTexto); ?></span></div>
                                                    <div class="detail-item"><span>Grupo</span><span><?php echo e($partido['Grupo'] ?: 'A'); ?></span></div>
                                                    <div class="detail-item"><span>Deporte</span><span><?php echo e($partido['nombre_deporte'] ?: 'No especificado'); ?></span></div>
                                                    <div class="detail-item"><span>Dirección</span><span><?php echo e($partido['direccion'] ?: 'Por definir'); ?></span></div>
                                                    <div class="detail-item"><span>Código Postal</span><span><?php echo e($partido['codigo_postal'] ?: '00000'); ?></span></div>
                                                    <div class="detail-item"><span>Descripción</span><span><?php echo e($partido['descripcion'] ?: 'Sin descripción'); ?></span></div>
                                                    <?php if (!empty($partido['fecha_actualizacion'])): ?>
                                                        <div class="detail-item"><span>Actualizado</span><span><?php echo e(date('d/m/Y H:i', strtotime($partido['fecha_actualizacion']))); ?></span></div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="edit-form" id="edit-form-<?php echo e($partido['Id_Partido']); ?>">
                                                    <form method="POST" action="" class="edit-match-form">
                                                        <input type="hidden" name="id_partido" value="<?php echo e($partido['Id_Partido']); ?>">

                                                        <div class="edit-grid">
                                                            <div class="form-group">
                                                                <label class="form-label">Equipo Local</label>
                                                                <select class="form-select" name="id_equipolocal" required>
                                                                    <?php foreach ($equipos_disponibles as $id => $nombre): ?>
                                                                        <option value="<?php echo e($id); ?>" <?php echo ($id == $partido['id_equipolocal']) ? 'selected' : ''; ?>>
                                                                            <?php echo e($nombre); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Equipo Visitante</label>
                                                                <select class="form-select" name="id_equipovicitante" required>
                                                                    <?php foreach ($equipos_disponibles as $id => $nombre): ?>
                                                                        <option value="<?php echo e($id); ?>" <?php echo ($id == $partido['id_equipovicitante']) ? 'selected' : ''; ?>>
                                                                            <?php echo e($nombre); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Fecha</label>
                                                                <input class="form-input" type="date" name="fecha" value="<?php echo e($partido['fecha']); ?>" required>
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Hora</label>
                                                                <input class="form-input" type="time" name="hora" value="<?php echo e(!empty($partido['hora']) ? date('H:i', strtotime($partido['hora'])) : ''); ?>" required>
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Estado</label>
                                                                <select class="form-select" name="estado" required>
                                                                    <option value="PROGRAMADO" <?php echo ($partido['Estado'] == 'PROGRAMADO') ? 'selected' : ''; ?>>PROGRAMADO</option>
                                                                    <option value="EN_CURSO" <?php echo ($partido['Estado'] == 'EN_CURSO') ? 'selected' : ''; ?>>EN CURSO</option>
                                                                    <option value="FINALIZADO" <?php echo ($partido['Estado'] == 'FINALIZADO') ? 'selected' : ''; ?>>FINALIZADO</option>
                                                                    <option value="SUSPENDIDO" <?php echo ($partido['Estado'] == 'SUSPENDIDO') ? 'selected' : ''; ?>>SUSPENDIDO</option>
                                                                    <option value="CANCELADO" <?php echo ($partido['Estado'] == 'CANCELADO') ? 'selected' : ''; ?>>CANCELADO</option>
                                                                </select>
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Grupo</label>
                                                                <select class="form-select" name="grupo">
                                                                    <?php foreach (['A','B','C','D','E','F','G','H'] as $letra): ?>
                                                                        <option value="<?php echo $letra; ?>" <?php echo ($partido['Grupo'] == $letra) ? 'selected' : ''; ?>><?php echo $letra; ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <div class="form-group wide">
                                                                <label class="form-label">Dirección</label>
                                                                <input class="form-input" type="text" name="direccion" value="<?php echo e($partido['direccion']); ?>">
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="form-label">Código Postal</label>
                                                                <input class="form-input" type="text" name="codigo_postal" value="<?php echo e($partido['codigo_postal']); ?>">
                                                            </div>

                                                            <div class="form-group wide">
                                                                <label class="form-label">Descripción</label>
                                                                <textarea class="form-textarea" name="descripcion"><?php echo e($partido['descripcion']); ?></textarea>
                                                            </div>
                                                        </div>

                                                        <div class="action-row">
                                                            <button type="submit" name="editar_partido" class="btn btn-save">💾 Guardar Cambios</button>
                                                            <button type="button" class="btn btn-cancel" onclick="toggleEdit('<?php echo e($partido['Id_Partido']); ?>')">✖️ Cancelar</button>
                                                        </div>
                                                    </form>
                                                </div>

                                                <div class="partido-actions">
                                                    <form method="POST" action="" class="form-finalizar">
                                                        <input type="hidden" name="id_partido_finalizar" value="<?php echo e($partido['Id_Partido']); ?>">
                                                        <input type="hidden" name="liga" value="<?php echo e($id_liga_admin); ?>">
                                                        <button type="submit" name="finalizar_partido" class="btn btn-finish">
                                                            <?php echo (strtoupper((string)$partido['Estado']) === 'FINALIZADO') ? '📊 Abrir Resultados' : '🏁 Finalizar Partido'; ?>
                                                        </button>
                                                    </form>

                                                    <button type="button" class="btn btn-edit btn-editar-toggle" data-partido="<?php echo e($partido['Id_Partido']); ?>" onclick="toggleEdit('<?php echo e($partido['Id_Partido']); ?>')">✏️ Editar Partido</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-box">
                            <h3>📭 No hay partidos programados</h3>
                            <p><?php echo $mostrar_generar_partidos ? 'Usa el formulario para generar nuevos partidos.' : 'Todavía no hay partidos registrados para esta liga.'; ?></p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-box">
                        <h3>🔒 Acceso restringido</h3>
                        <p>No estás administrando ninguna liga actualmente.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <div class="fab-group">
        <a href="Adminliga.php" class="fab blue">🏆<br>INICIO</a>
        <a href="#topPage" class="fab red">⬆️<br>TOP</a>
    </div>
</div>

<script>
function confirmarActualizacion() {
    const equiposSinPartidos = <?php echo json_encode($equipos_sin_partidos); ?>;
    let mensaje = "⚠️ ¿Actualizar el calendario?\n\n";
    mensaje += "1. Se borrarán los partidos actuales\n";
    mensaje += "2. Se generará un nuevo calendario\n";
    mensaje += "3. Se incluirán todos los equipos activos\n";
    mensaje += "4. Las jornadas quedarán programadas semanalmente desde hoy\n\n";

    if (equiposSinPartidos.length > 0) {
        mensaje += "Equipos que se agregarán:\n";
        equiposSinPartidos.forEach(function(equipo, index) {
            mensaje += (index + 1) + ". " + equipo.Nombre + "\n";
        });
        mensaje += "\n";
    }

    mensaje += "Esta acción no se puede deshacer.";
    return confirm(mensaje);
}

function confirmarBorrado() {
    const primera = confirm("⚠️ ¿Seguro que deseas borrar todos los partidos de esta liga?");
    if (!primera) {
        return false;
    }
    return confirm("⚠️ Confirmación final:\n\nSe eliminarán <?php echo count($partidos); ?> registros.");
}

function toggleEdit(partidoId) {
    const form = document.getElementById('edit-form-' + partidoId);
    const btn = document.querySelector('.btn-editar-toggle[data-partido="' + partidoId + '"]');

    if (!form || !btn) {
        return;
    }

    if (form.style.display === 'block') {
        form.style.display = 'none';
        btn.innerHTML = '✏️ Editar Partido';
    } else {
        form.style.display = 'block';
        btn.innerHTML = '✖️ Cerrar Edición';
    }
}

document.querySelectorAll('.edit-match-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        const local = this.querySelector('[name="id_equipolocal"]').value;
        const visitante = this.querySelector('[name="id_equipovicitante"]').value;

        if (local === visitante) {
            e.preventDefault();
            alert('❌ El equipo local y visitante no pueden ser el mismo.');
            return false;
        }

        const estado = this.querySelector('[name="estado"]').value;
        const fecha = this.querySelector('[name="fecha"]').value;
        const hora = this.querySelector('[name="hora"]').value;

        if ((estado === 'PROGRAMADO' || estado === 'EN_CURSO') && fecha && hora) {
            const fechaHora = new Date(fecha + 'T' + hora);
            const ahora = new Date();

            if (fechaHora < ahora) {
                const continuar = confirm('⚠️ La fecha y hora están en el pasado.\n\n¿Deseas continuar?');
                if (!continuar) {
                    e.preventDefault();
                    return false;
                }
            }
        }
    });
});

document.querySelectorAll('.form-finalizar').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        const ok = confirm('🏁 Se abrirá la pantalla de resultados según el deporte de la liga.\n\n¿Deseas continuar?');
        if (!ok) {
            e.preventDefault();
            return false;
        }
    });
});

const generarBtn = document.querySelector('[name="generar_partidos"]');
if (generarBtn) {
    generarBtn.addEventListener('click', function(e) {
        const formaGeneracion = document.getElementById('formaGeneracion').value;
        const rondas = parseInt(document.getElementById('canRondas').value || '0', 10);
        const numEquipos = <?php echo count($equipos_disponibles); ?>;

        if (formaGeneracion === 'GRUPOS') {
            return true;
        }

        if (numEquipos >= 2 && rondas > 0) {
            const partidosPorRonda = (numEquipos % 2 === 0)
                ? (numEquipos / 2) * (numEquipos - 1)
                : ((numEquipos - 1) / 2) * numEquipos;

            const total = partidosPorRonda * rondas;

            if (total > 50) {
                const ok = confirm("⚠️ Vas a generar aproximadamente " + total + " partidos.\n\nLas jornadas serán semanales desde hoy.\n\n¿Continuar?");
                if (!ok) {
                    e.preventDefault();
                }
            }
        }
    });
}
</script>
<?php if (function_exists('retame_render_global_js')) { retame_render_global_js(); } ?>
</body>
</html>