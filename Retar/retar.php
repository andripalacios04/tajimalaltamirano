<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['usuario_data']) || !is_array($_SESSION['usuario_data'])) {
    header("Location: ../login.php");
    exit();
}

include_once '../conexion.php';

$usuarios = $_SESSION['usuario_data'];

function limpiarTexto($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerDato($datos, $llaves, $default = '') {
    foreach ($llaves as $llave) {
        if (isset($datos[$llave]) && trim((string)$datos[$llave]) !== '') {
            return trim((string)$datos[$llave]);
        }
    }
    return $default;
}

$Nombre = obtenerDato($usuarios, ['Nombre', 'nombre'], 'Usuario');
$Apellido = obtenerDato($usuarios, ['Apellido', 'apellido'], '');
$FotoPerfil = obtenerDato($usuarios, ['FotoPerfil', 'fotoPerfil', 'foto_perfil'], '');
$Id_Usuario = (int) obtenerDato($usuarios, ['Id_Usuario', 'id_usuario'], 0);
$Id_Retador = obtenerDato($usuarios, ['Id_Retador', 'id_retador'], '');
$NombreRetadorCompleto = trim($Nombre . ' ' . $Apellido);
if ($NombreRetadorCompleto === '') {
    $NombreRetadorCompleto = $Nombre;
}

function resolverRutaFotoPerfil($fotoPerfilBD) {
    $fotoPerfilBD = trim(str_replace('\\', '/', (string)$fotoPerfilBD));

    if ($fotoPerfilBD === '') {
        return '';
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $fotoPerfilBD)) {
        return $fotoPerfilBD;
    }

    $fotoPerfilBD = ltrim($fotoPerfilBD, '/');

    if (strpos($fotoPerfilBD, '../') === 0 || strpos($fotoPerfilBD, './') === 0) {
        return $fotoPerfilBD;
    }

    $carpetasProyecto = ['Imagenes/', 'imagenes/', 'uploads/', 'Uploads/', 'FotosPerfil/', 'assets/', 'img/'];
    foreach ($carpetasProyecto as $carpeta) {
        if (stripos($fotoPerfilBD, $carpeta) === 0) {
            return '../' . $fotoPerfilBD;
        }
    }

    $candidatas = [
        '../Imagenes/' . $fotoPerfilBD,
        '../uploads/' . $fotoPerfilBD,
        '../FotosPerfil/' . $fotoPerfilBD,
        '../assets/' . $fotoPerfilBD,
        '../img/' . $fotoPerfilBD,
        '../imagenes/' . $fotoPerfilBD,
        '../' . $fotoPerfilBD
    ];

    foreach ($candidatas as $rutaWeb) {
        $rutaFisica = __DIR__ . '/' . $rutaWeb;
        if (file_exists($rutaFisica)) {
            return $rutaWeb;
        }
    }

    if (strpos($fotoPerfilBD, '/') !== false) {
        return '../' . $fotoPerfilBD;
    }

    return '../Imagenes/' . $fotoPerfilBD;
}

$modoPerfilActual = '';
$modoOscuroActivo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_modo_perfil') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($Id_Retador)) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró el retador en sesión.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $modoSolicitado = isset($_POST['modo']) ? trim((string)$_POST['modo']) : '';
    $nuevoModoPerfil = ($modoSolicitado === 'oscuro') ? 'Modo oscuro' : 'Predeterminado';

    $stmtModoUpdate = $conn->prepare("UPDATE retador SET ModoPerfil = ? WHERE Id_Retador = ? LIMIT 1");

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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle_favorito_reta') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($Id_Retador)) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró el retador en sesión.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $idCategoriaFavorito = isset($_POST['id_categoria']) ? trim((string)$_POST['id_categoria']) : '';
    $deporteFavorito = isset($_POST['deporte']) ? trim((string)$_POST['deporte']) : '';

    if ($idCategoriaFavorito === '') {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se encontró la ID de la reta publicada.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($deporteFavorito === '') {
        $deporteFavorito = 'Deporte';
    }

    $categoriaFavorito = 'RETA PUBLICADA';
    $nombreFavorito = 'Reta ' . $deporteFavorito;

    $stmtExisteFavorito = $conn->prepare("SELECT ID_FAVORITO FROM favoritosguardados WHERE ID_CATEGORIA = ? AND ID_RETADOR = ? AND CATEGORIA = ? LIMIT 1");

    if (!$stmtExisteFavorito) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo consultar la tabla favoritosguardados. Revisa que ya exista.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtExisteFavorito->bind_param('sss', $idCategoriaFavorito, $Id_Retador, $categoriaFavorito);
    $stmtExisteFavorito->execute();
    $resExisteFavorito = $stmtExisteFavorito->get_result();
    $favoritoExistente = $resExisteFavorito ? $resExisteFavorito->fetch_assoc() : null;
    $stmtExisteFavorito->close();

    if ($favoritoExistente && isset($favoritoExistente['ID_FAVORITO'])) {
        $idFavoritoEliminar = (int)$favoritoExistente['ID_FAVORITO'];
        $stmtEliminarFavorito = $conn->prepare("DELETE FROM favoritosguardados WHERE ID_FAVORITO = ? AND ID_RETADOR = ? LIMIT 1");

        if (!$stmtEliminarFavorito) {
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No se pudo quitar el favorito.'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $stmtEliminarFavorito->bind_param('is', $idFavoritoEliminar, $Id_Retador);
        $okEliminarFavorito = $stmtEliminarFavorito->execute();
        $stmtEliminarFavorito->close();

        echo json_encode([
            'ok' => $okEliminarFavorito,
            'guardado' => false,
            'mensaje' => $okEliminarFavorito ? 'Reta quitada de favoritos.' : 'No se pudo quitar la reta de favoritos.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtInsertarFavorito = $conn->prepare("INSERT INTO favoritosguardados (ID_CATEGORIA, ID_RETADOR, NOMBRE_RETADOR, CATEGORIA, NOMBRE_FAVORITO, FECHA, HORA) VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME())");

    if (!$stmtInsertarFavorito) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo preparar el guardado del favorito.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmtInsertarFavorito->bind_param('sssss', $idCategoriaFavorito, $Id_Retador, $NombreRetadorCompleto, $categoriaFavorito, $nombreFavorito);
    $okInsertarFavorito = $stmtInsertarFavorito->execute();
    $stmtInsertarFavorito->close();

    echo json_encode([
        'ok' => $okInsertarFavorito,
        'guardado' => true,
        'mensaje' => $okInsertarFavorito ? 'Reta guardada en favoritos.' : 'No se pudo guardar la reta en favoritos.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!empty($Id_Retador) && isset($conn)) {
    $stmtPerfilSelect = $conn->prepare("SELECT ModoPerfil, FotoPerfil FROM retador WHERE Id_Retador = ? LIMIT 1");

    if ($stmtPerfilSelect) {
        $stmtPerfilSelect->bind_param("s", $Id_Retador);
        $stmtPerfilSelect->execute();
        $resPerfilSelect = $stmtPerfilSelect->get_result();

        if ($filaPerfil = $resPerfilSelect->fetch_assoc()) {
            $modoPerfilActual = isset($filaPerfil['ModoPerfil']) ? trim((string)$filaPerfil['ModoPerfil']) : '';
            $fotoPerfilBD = isset($filaPerfil['FotoPerfil']) ? trim((string)$filaPerfil['FotoPerfil']) : '';

            $_SESSION['usuario_data']['ModoPerfil'] = $modoPerfilActual;

            if ($fotoPerfilBD !== '') {
                $FotoPerfil = $fotoPerfilBD;
                $_SESSION['usuario_data']['FotoPerfil'] = $fotoPerfilBD;
            }
        }

        $stmtPerfilSelect->close();
    }
}

$modoPerfilNormalizado = mb_strtolower(trim((string)$modoPerfilActual), 'UTF-8');
$modoOscuroActivo = ($modoPerfilNormalizado === 'modo oscuro');

$tieneEquipo = false;

if ($Id_Usuario > 0 && isset($conn)) {
    $stmt = $conn->prepare("SELECT Id_Equipo FROM usuarios WHERE Id_Usuario = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $Id_Usuario);
        $stmt->execute();
        $stmt->bind_result($Id_Equipo);
        if ($stmt->fetch() && !empty($Id_Equipo)) {
            $tieneEquipo = true;
        }
        $stmt->close();
    }
}

$iniciales = mb_strtoupper(mb_substr($Nombre, 0, 1, 'UTF-8') . mb_substr($Apellido, 0, 1, 'UTF-8'), 'UTF-8');
if (trim($iniciales) === '') {
    $iniciales = 'U';
}

$FotoPerfilUsuario = resolverRutaFotoPerfil($FotoPerfil);
$nuevas_count = 0;

function rutasImagenReta($imagenBD) {
    $imagenBD = trim(str_replace('\\', '/', (string)$imagenBD));

    if ($imagenBD === '') {
        return [];
    }

    if (preg_match('/^(https?:\/\/|data:image\/|\/)/i', $imagenBD)) {
        return [$imagenBD];
    }

    $imagenBD = ltrim($imagenBD, '/');
    $rutas = [];

    $agregarRuta = function($ruta) use (&$rutas) {
        $ruta = trim((string)$ruta);
        if ($ruta !== '' && !in_array($ruta, $rutas, true)) {
            $rutas[] = $ruta;
        }
    };

    if (strpos($imagenBD, '../') === 0 || strpos($imagenBD, './') === 0) {
        $agregarRuta($imagenBD);
        $sinRelativo = preg_replace('/^(\.\.\/|\.\/)+/', '', $imagenBD);
        $agregarRuta($sinRelativo);
        $agregarRuta('../' . $sinRelativo);
        $agregarRuta('../../' . $sinRelativo);
        $agregarRuta('/' . $sinRelativo);
        return $rutas;
    }

    $agregarRuta($imagenBD);
    $agregarRuta('../' . $imagenBD);
    $agregarRuta('../Canchas/' . $imagenBD);
    $agregarRuta('../../' . $imagenBD);
    $agregarRuta('/' . $imagenBD);

    if (strpos($imagenBD, '/') === false) {
        $carpetas = [
            'uploads/canchas/',
            '../uploads/canchas/',
            '../Canchas/uploads/canchas/',
            '../../uploads/canchas/',
            '/uploads/canchas/',
            'uploads/',
            '../uploads/',
            '../Canchas/uploads/',
            '../../uploads/',
            '/uploads/',
            'Imagenes/',
            '../Imagenes/',
            '../Canchas/Imagenes/',
            '../../Imagenes/',
            '/Imagenes/'
        ];

        foreach ($carpetas as $carpeta) {
            $agregarRuta($carpeta . $imagenBD);
        }
    }

    return $rutas;
}

function primeraRutaImagenReta($imagenBD) {
    $rutas = rutasImagenReta($imagenBD);
    return isset($rutas[0]) ? $rutas[0] : '';
}

function normalizarClaveCancha($valor) {
    $valor = trim((string)$valor);
    $valor = preg_replace('/\s+/', ' ', $valor);
    return strtolower($valor);
}

function obtenerCanchaRelacionadaReta($fila, $canchasMapa) {
    $llaves = [
        'Id_Cancha',
        'id_cancha',
        'IdCancha',
        'idCancha',
        'Cancha_Id',
        'cancha_id',
        'CanchaID',
        'canchaID',
        'Id_Cancha_Reta',
        'id_cancha_reta',
        'Nombre_Cancha',
        'nombre_cancha',
        'NombreCancha',
        'nombreCancha',
        'Cancha',
        'cancha'
    ];

    foreach ($llaves as $llave) {
        if (isset($fila[$llave]) && trim((string)$fila[$llave]) !== '') {
            $clave = normalizarClaveCancha($fila[$llave]);

            if (isset($canchasMapa[$clave])) {
                return $canchasMapa[$clave];
            }
        }
    }

    return [];
}

function obtenerIdCanchaReta($fila) {
    return obtenerDato($fila, ['Id_Cancha', 'id_cancha', 'IdCancha', 'idCancha', 'Cancha_Id', 'cancha_id'], '');
}

function obtenerIdRetaPublicada($fila) {
    $idDetectado = obtenerDato($fila, [
        'ID_RETA',
        'ID_Reta',
        'Id_Reta',
        'id_reta',
        'ID_RETA_PUBLICADA',
        'ID_RetaPublicada',
        'Id_RetaPublicada',
        'Id_Reta_Publicada',
        'id_reta_publicada',
        'idRetaPublicada',
        'Id_Publicacion',
        'id_publicacion',
        'ID_PUBLICACION',
        'Id_RetasPublicadas',
        'id_retaspublicadas',
        'Id_RetaPublicadas',
        'id',
        'Id',
        'ID'
    ], '');

    if ($idDetectado !== '') {
        return $idDetectado;
    }

    $partes = [
        obtenerDato($fila, ['Id_Cancha', 'id_cancha', 'IdCancha', 'idCancha', 'Cancha', 'cancha', 'Nombre_Cancha', 'nombre_cancha'], ''),
        obtenerDato($fila, ['Deporte', 'deporte', 'Tipo_Deporte', 'tipo_deporte'], ''),
        obtenerDato($fila, ['Fecha', 'fecha', 'Fecha_Reta', 'fecha_reta'], ''),
        obtenerDato($fila, ['Hora_Inicio', 'hora_inicio', 'HoraInicio', 'horaInicio', 'Hora', 'hora'], ''),
        obtenerDato($fila, ['Hora_Termino', 'hora_termino', 'HoraTermino', 'horaTermino'], ''),
        obtenerDato($fila, ['Nombre', 'nombre', 'Titulo', 'titulo'], '')
    ];

    $base = trim(implode('|', $partes), '| ');

    if ($base === '') {
        $base = json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return 'reta_' . substr(sha1((string)$base), 0, 32);
}

function obtenerCodigoPostalReta($fila) {
    $codigoPostalReta = obtenerDato($fila, ['CodigoPostal', 'codigoPostal', 'codigo_postal', 'Codigo_Postal', 'CP', 'cp', 'Cp'], '');

    if ($codigoPostalReta !== '') {
        return $codigoPostalReta;
    }

    if (isset($fila['_cancha']) && is_array($fila['_cancha'])) {
        return obtenerDato($fila['_cancha'], ['Codigo_Postal', 'CodigoPostal', 'codigo_postal', 'CP', 'cp', 'Cp'], '');
    }

    return '';
}

function distanciaCodigoPostal($codigoUsuario, $codigoReta) {
    $codigoUsuario = trim((string)$codigoUsuario);
    $codigoReta = trim((string)$codigoReta);

    if ($codigoUsuario === '' || $codigoReta === '') {
        return PHP_INT_MAX;
    }

    if ($codigoUsuario === $codigoReta) {
        return 0;
    }

    $numeroUsuario = preg_replace('/\D+/', '', $codigoUsuario);
    $numeroReta = preg_replace('/\D+/', '', $codigoReta);

    if ($numeroUsuario !== '' && $numeroReta !== '') {
        return abs((int)$numeroReta - (int)$numeroUsuario);
    }

    return PHP_INT_MAX - 1;
}

function normalizarFiltroRetas($valor) {
    $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
    $valor = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $valor);
    $valor = preg_replace('/\s+/', ' ', $valor);
    return $valor;
}

function obtenerIdDeporteReta($fila) {
    return obtenerDato($fila, ['id_deporte', 'Id_Deporte', 'ID_DEPORTE', 'id_Deporte', 'IdDeporte', 'idDeporte'], '');
}

function obtenerNombreDeporteReta($fila) {
    return obtenerDato($fila, ['Deporte', 'deporte', 'Tipo_Deporte', 'tipo_deporte', 'Nombre_Deporte', 'nombre_deporte'], '');
}

function obtenerCiudadReta($fila) {
    $ciudad = obtenerDato($fila, ['Ciudad', 'ciudad', 'Municipio', 'municipio'], '');

    if ($ciudad !== '') {
        return $ciudad;
    }

    if (isset($fila['_cancha']) && is_array($fila['_cancha'])) {
        return obtenerDato($fila['_cancha'], ['Ciudad', 'ciudad'], '');
    }

    return '';
}

function textoContieneFiltro($texto, $filtro) {
    $texto = normalizarFiltroRetas($texto);
    $filtro = normalizarFiltroRetas($filtro);

    if ($filtro === '') {
        return true;
    }

    return strpos($texto, $filtro) !== false;
}

$codigoPostalUsuario = obtenerDato($usuarios, ['CodigoPostal', 'codigoPostal', 'codigo_postal', 'Codigo_Postal'], '');
$retasPublicadas = [];
$errorRetasPublicadas = '';
$favoritosRetasGuardados = [];
$deportesFiltro = [];

$filtroDeporteId = isset($_GET['deporte_id']) ? trim((string)$_GET['deporte_id']) : '';
$filtroCodigoPostal = isset($_GET['codigo_postal']) ? trim((string)$_GET['codigo_postal']) : '';
$filtroCiudad = isset($_GET['ciudad']) ? trim((string)$_GET['ciudad']) : '';
$ordenRetas = isset($_GET['orden']) ? trim((string)$_GET['orden']) : 'cercanas';

if (!in_array($ordenRetas, ['cercanas', 'lejanas'], true)) {
    $ordenRetas = 'cercanas';
}

$filtroDeporteNombre = '';

if (!empty($Id_Retador) && isset($conn)) {
    $resTablaFavoritos = $conn->query("SHOW TABLES LIKE 'favoritosguardados'");

    if ($resTablaFavoritos && $resTablaFavoritos->num_rows > 0) {
        $stmtFavoritosGuardados = $conn->prepare("SELECT ID_CATEGORIA FROM favoritosguardados WHERE ID_RETADOR = ? AND CATEGORIA = 'RETA PUBLICADA'");

        if ($stmtFavoritosGuardados) {
            $stmtFavoritosGuardados->bind_param('s', $Id_Retador);
            $stmtFavoritosGuardados->execute();
            $resFavoritosGuardados = $stmtFavoritosGuardados->get_result();

            while ($filaFavorito = $resFavoritosGuardados->fetch_assoc()) {
                if (isset($filaFavorito['ID_CATEGORIA'])) {
                    $favoritosRetasGuardados[] = (string)$filaFavorito['ID_CATEGORIA'];
                }
            }

            $stmtFavoritosGuardados->close();
        }
    }
}

if ($codigoPostalUsuario === '' && !empty($Id_Retador) && isset($conn)) {
    $stmtCodigoPostal = $conn->prepare("SELECT CodigoPostal FROM retador WHERE Id_Retador = ? LIMIT 1");

    if ($stmtCodigoPostal) {
        $stmtCodigoPostal->bind_param("s", $Id_Retador);
        $stmtCodigoPostal->execute();
        $stmtCodigoPostal->bind_result($codigoPostalBD);

        if ($stmtCodigoPostal->fetch() && trim((string)$codigoPostalBD) !== '') {
            $codigoPostalUsuario = trim((string)$codigoPostalBD);
            $_SESSION['usuario_data']['CodigoPostal'] = $codigoPostalUsuario;
        }

        $stmtCodigoPostal->close();
    }
}

if (isset($conn)) {
    $resultadoDeportesFiltro = $conn->query("SELECT * FROM deporte ORDER BY Nombre ASC");

    if ($resultadoDeportesFiltro) {
        while ($filaDeporteFiltro = $resultadoDeportesFiltro->fetch_assoc()) {
            $idDeporteFiltro = obtenerDato($filaDeporteFiltro, ['id_Deporte', 'Id_Deporte', 'id_deporte', 'ID_DEPORTE', 'Id', 'id'], '');
            $nombreDeporteFiltro = obtenerDato($filaDeporteFiltro, ['Nombre', 'nombre', 'Deporte', 'deporte'], '');

            if ($idDeporteFiltro !== '' && $nombreDeporteFiltro !== '') {
                $deportesFiltro[] = [
                    'id' => $idDeporteFiltro,
                    'nombre' => $nombreDeporteFiltro
                ];

                if ($filtroDeporteId !== '' && (string)$filtroDeporteId === (string)$idDeporteFiltro) {
                    $filtroDeporteNombre = $nombreDeporteFiltro;
                }
            }
        }

        $resultadoDeportesFiltro->free();
    }
}

if (isset($conn)) {
    $resultadoRetas = $conn->query("SELECT * FROM r_retaspublicadas");

    if ($resultadoRetas) {
        while ($filaReta = $resultadoRetas->fetch_assoc()) {
            $retasPublicadas[] = $filaReta;
        }

        $resultadoRetas->free();

        $canchasMapa = [];
        $resultadoCanchas = $conn->query("SELECT Id_Cancha, Nombre, Dueno, Id_Dueno, Estado, Ciudad, Direccion, Codigo_Postal, Foto, Estado_Cancha, Costo FROM c_canchas");

        if ($resultadoCanchas) {
            while ($filaCancha = $resultadoCanchas->fetch_assoc()) {
                $claveId = normalizarClaveCancha(obtenerDato($filaCancha, ['Id_Cancha'], ''));
                $claveNombre = normalizarClaveCancha(obtenerDato($filaCancha, ['Nombre'], ''));

                if ($claveId !== '') {
                    $canchasMapa[$claveId] = $filaCancha;
                }

                if ($claveNombre !== '') {
                    $canchasMapa[$claveNombre] = $filaCancha;
                }
            }

            $resultadoCanchas->free();
        }

        foreach ($retasPublicadas as $indiceReta => $filaReta) {
            $retasPublicadas[$indiceReta]['_cancha'] = obtenerCanchaRelacionadaReta($filaReta, $canchasMapa);
        }

        $retasPublicadas = array_values(array_filter($retasPublicadas, function($filaReta) use ($filtroDeporteId, $filtroDeporteNombre, $filtroCodigoPostal, $filtroCiudad) {
            if ($filtroDeporteId !== '') {
                $idDeporteReta = obtenerIdDeporteReta($filaReta);
                $nombreDeporteReta = obtenerNombreDeporteReta($filaReta);
                $coincidePorId = ($idDeporteReta !== '' && (string)$idDeporteReta === (string)$filtroDeporteId);
                $coincidePorNombre = ($filtroDeporteNombre !== '' && normalizarFiltroRetas($nombreDeporteReta) === normalizarFiltroRetas($filtroDeporteNombre));

                if (!$coincidePorId && !$coincidePorNombre) {
                    return false;
                }
            }

            if ($filtroCodigoPostal !== '') {
                $cpReta = obtenerCodigoPostalReta($filaReta);
                $cpRetaNormalizado = preg_replace('/\s+/', '', (string)$cpReta);
                $cpFiltroNormalizado = preg_replace('/\s+/', '', (string)$filtroCodigoPostal);

                if ($cpRetaNormalizado !== $cpFiltroNormalizado) {
                    return false;
                }
            }

            if ($filtroCiudad !== '') {
                $ciudadReta = obtenerCiudadReta($filaReta);

                if (!textoContieneFiltro($ciudadReta, $filtroCiudad)) {
                    return false;
                }
            }

            return true;
        }));

        usort($retasPublicadas, function($a, $b) use ($codigoPostalUsuario, $filtroCodigoPostal, $ordenRetas) {
            $codigoBase = $filtroCodigoPostal !== '' ? $filtroCodigoPostal : $codigoPostalUsuario;
            $distanciaA = distanciaCodigoPostal($codigoBase, obtenerCodigoPostalReta($a));
            $distanciaB = distanciaCodigoPostal($codigoBase, obtenerCodigoPostalReta($b));

            $distanciaOrdenA = ($distanciaA >= PHP_INT_MAX - 1) ? ($ordenRetas === 'lejanas' ? -1 : PHP_INT_MAX) : $distanciaA;
            $distanciaOrdenB = ($distanciaB >= PHP_INT_MAX - 1) ? ($ordenRetas === 'lejanas' ? -1 : PHP_INT_MAX) : $distanciaB;

            if ($distanciaOrdenA == $distanciaOrdenB) {
                $fechaA = obtenerDato($a, ['Fecha', 'fecha', 'Fecha_Reta', 'fecha_reta'], '');
                $fechaB = obtenerDato($b, ['Fecha', 'fecha', 'Fecha_Reta', 'fecha_reta'], '');

                return strcmp($fechaA, $fechaB);
            }

            if ($ordenRetas === 'lejanas') {
                return ($distanciaOrdenA > $distanciaOrdenB) ? -1 : 1;
            }

            return ($distanciaOrdenA < $distanciaOrdenB) ? -1 : 1;
        });
    } else {
        $errorRetasPublicadas = 'No se pudieron consultar las retas publicadas.';
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Retar - RETAME</title>
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
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:13px;
    border-radius:20px;
    background:linear-gradient(180deg,#ffffff,#fbfbfb);
    border:2px solid rgba(0,153,255,0.28);
    box-shadow:0 8px 18px rgba(0,0,0,0.06);
}

.modo-oscuro-texto{
    display:flex;
    align-items:center;
    gap:7px;
    font-size:12px;
    color:#374151;
}

.modo-oscuro-texto strong{
    font-weight:900;
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
}

.switch-modo span{
    position:absolute;
    width:24px;
    height:24px;
    left:3px;
    top:3px;
    border-radius:50%;
    background:#ffffff;
    display:block;
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

.switch-modo:disabled{
    opacity:0.75;
    cursor:not-allowed;
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
    height:2px;
    background:currentColor;
    position:relative;
    border-radius:999px;
}

.menu-toggle span::before,
.menu-toggle span::after{
    content:"";
    position:absolute;
    left:0;
    width:25px;
    height:2px;
    background:currentColor;
    border-radius:999px;
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

.toast-container{
    position:fixed;
    top:90px;
    right:18px;
    z-index:5000;
    display:flex;
    flex-direction:column;
    gap:10px;
    width:min(360px,calc(100vw - 36px));
    pointer-events:none;
}

.toast{
    pointer-events:none;
    background:rgba(255,255,255,0.98);
    box-shadow:0 12px 30px rgba(0,0,0,0.14);
    border:1px solid rgba(17,24,39,0.08);
    border-radius:18px;
    padding:14px;
    display:flex;
    gap:12px;
    animation:toastIn 240ms ease-out;
}

.toast .icon{
    width:40px;
    height:40px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff1f2;
    color:#ff3045;
    font-size:20px;
    font-weight:900;
}

.toast .content{
    flex:1;
    min-width:0;
}

.toast .title{
    font-weight:900;
    font-size:13px;
    color:var(--azul);
    margin-bottom:3px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.toast .msg{
    font-size:13px;
    color:#374151;
    line-height:1.35;
    display:-webkit-box;
    -webkit-line-clamp:3;
    -webkit-box-orient:vertical;
    overflow:hidden;
}

.toast .meta{
    margin-top:6px;
    font-size:11px;
    color:var(--gris);
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

@keyframes toastIn{
    from{transform:translateY(-10px);opacity:0;}
    to{transform:translateY(0);opacity:1;}
}

body.dark-mode .toast{
    background:#111827;
    border-color:rgba(255,75,92,0.34);
    box-shadow:0 12px 30px rgba(0,0,0,0.34), 0 0 0 2px rgba(0,153,255,0.14);
}

body.dark-mode .toast .icon{
    background:#ff3045;
    color:#ffffff;
}

body.dark-mode .toast .title{
    color:#8fefff;
}

body.dark-mode .toast .msg,
body.dark-mode .toast .meta{
    color:#e5e7eb;
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
body.dark-mode .cerrar-boceto,
body.dark-mode .info-boceto a,
body.dark-mode .modo-oscuro-panel{
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
body.dark-mode .cerrar-boceto,
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

.inicio-retar-page{
    width:100%;
    min-height:calc(100dvh - 84px - 116px);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    gap:24px;
    padding:34px 28px 38px;
}

.inicio-retar-grid{
    width:min(980px,100%);
    display:grid;
    grid-template-columns:minmax(0,1.55fr) minmax(250px,0.9fr);
    gap:18px;
    align-items:stretch;
    margin-top:4px;
}

.inicio-card-retar{
    position:relative;
    overflow:hidden;
    border-radius:22px;
    background:rgba(255,255,255,0.78);
    border:2px solid rgba(255,75,92,0.72);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.08),
        0 0 0 2px rgba(0,153,255,0.20),
        0 0 18px rgba(0,153,255,0.16);
    backdrop-filter:blur(10px);
}

.inicio-card-retar::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        radial-gradient(circle at 16% 18%,rgba(14,165,233,0.22),transparent 260px),
        radial-gradient(circle at 92% 18%,rgba(255,75,92,0.24),transparent 250px),
        linear-gradient(135deg,rgba(24,119,242,0.07),rgba(255,75,92,0.06));
}

.inicio-card-retar > *{
    position:relative;
    z-index:1;
}

.inicio-card-principal{
    min-height:295px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:46px 62px;
}

.inicio-icono-central{
    font-size:34px;
    line-height:1;
    margin-bottom:14px;
    filter:drop-shadow(0 0 12px rgba(255,75,92,0.28));
}

.inicio-card-principal h2{
    font-family:'Orbitron',sans-serif;
    font-size:clamp(31px,3vw,43px);
    line-height:1;
    letter-spacing:2px;
    color:var(--azul2);
    text-transform:uppercase;
    text-shadow:0 0 15px rgba(0,153,255,0.28);
    margin-bottom:22px;
}

.inicio-card-principal p{
    max-width:540px;
    font-size:16px;
    font-weight:600;
    line-height:1.65;
    color:#374151;
}

.inicio-retar-opciones{
    display:grid;
    grid-template-rows:1fr 1fr;
    gap:18px;
}

.inicio-opcion-card{
    min-height:138px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    text-decoration:none;
    padding:22px 24px;
    transition:0.22s ease;
}

.inicio-opcion-card:hover{
    transform:translateY(-3px);
    border-color:rgba(255,75,92,0.95);
    box-shadow:
        0 14px 30px rgba(0,0,0,0.11),
        0 0 0 3px rgba(0,153,255,0.25),
        0 0 20px rgba(0,153,255,0.22);
}

.inicio-opcion-icono{
    width:58px;
    height:58px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:20px;
    margin-bottom:12px;
    font-size:28px;
    background:linear-gradient(135deg,var(--rojo),var(--rojo2));
    color:white;
    box-shadow:0 10px 22px rgba(255,75,92,0.26);
}

.inicio-opcion-card h3{
    font-family:'Orbitron',sans-serif;
    color:var(--azul2);
    font-size:16px;
    font-weight:800;
    margin-bottom:8px;
    text-shadow:0 0 12px rgba(0,153,255,0.24);
}

.inicio-opcion-card p{
    color:#374151;
    font-size:14px;
    font-weight:600;
    line-height:1.45;
}

body.dark-mode .inicio-card-retar{
    background:rgba(8,15,30,0.88);
    border-color:rgba(255,47,69,0.72);
    box-shadow:
        0 12px 28px rgba(0,0,0,0.30),
        0 0 0 2px rgba(0,153,255,0.20),
        0 0 20px rgba(0,153,255,0.18);
}

body.dark-mode .inicio-card-retar::before{
    background:
        radial-gradient(circle at 16% 18%,rgba(14,165,233,0.18),transparent 260px),
        radial-gradient(circle at 92% 18%,rgba(255,75,92,0.18),transparent 250px),
        linear-gradient(135deg,rgba(14,165,233,0.05),rgba(255,75,92,0.05));
}

body.dark-mode .inicio-card-principal h2,
body.dark-mode .inicio-opcion-card h3{
    color:var(--cyan);
}

body.dark-mode .inicio-card-principal p,
body.dark-mode .inicio-opcion-card p{
    color:#e5e7eb;
}

@media screen and (max-width:1100px){
    .inicio-retar-page{
        padding:28px 20px 34px;
    }

    .inicio-retar-grid{
        width:min(900px,100%);
    }
}

@media screen and (max-width:820px){
    .inicio-retar-page{
        min-height:auto;
        padding:24px 16px 124px;
    }

    .inicio-retar-grid{
        grid-template-columns:1fr;
        gap:16px;
    }

    .inicio-card-principal{
        min-height:250px;
        padding:34px 24px;
    }

    .inicio-retar-opciones{
        grid-template-rows:none;
        grid-template-columns:1fr 1fr;
        gap:14px;
    }

    .inicio-opcion-card{
        min-height:132px;
        padding:18px 12px;
    }
}

@media screen and (max-width:520px){
    .inicio-retar-page{
        padding:20px 12px 118px;
    }

    .inicio-card-principal{
        min-height:230px;
        padding:30px 20px;
    }

    .inicio-card-principal p{
        font-size:14px;
    }

    .inicio-retar-opciones{
        grid-template-columns:1fr;
    }

    .inicio-opcion-card{
        min-height:124px;
    }
}


.retas-publicadas-section{
    width:min(980px,100%);
    margin-top:24px;
}

.retas-publicadas-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:18px;
    margin-bottom:16px;
}

.retas-publicadas-head span{
    display:inline-flex;
    align-items:center;
    gap:7px;
    font-size:12px;
    font-weight:900;
    color:var(--rojo);
    text-transform:uppercase;
    letter-spacing:0.8px;
}

.retas-publicadas-head h2{
    font-family:'Orbitron',sans-serif;
    color:var(--azul2);
    font-size:clamp(20px,3vw,28px);
    line-height:1.1;
    margin:6px 0 8px;
}

.retas-publicadas-head p{
    max-width:650px;
    color:#4b5563;
    font-size:14px;
    font-weight:600;
    line-height:1.55;
}

.retas-total{
    flex:0 0 auto;
    padding:11px 15px;
    border-radius:999px;
    color:#fff;
    font-size:12px;
    font-weight:900;
    background:linear-gradient(135deg,var(--azul),var(--azul2));
    box-shadow:var(--sombra-azul);
}

.retas-feed{
    width:100%;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:22px;
    padding:2px 0 8px;
}

.reta-publicada-card{
    width:min(760px,100%);
    overflow:hidden;
    border-radius:26px;
    background:rgba(255,255,255,0.88);
    border:2px solid rgba(255,75,92,0.65);
    box-shadow:
        0 12px 26px rgba(0,0,0,0.09),
        0 0 0 2px rgba(0,153,255,0.17),
        0 0 18px rgba(0,153,255,0.12);
    backdrop-filter:blur(12px);
}

.reta-publicada-card.oculta{
    display:none;
}

.reta-publicada-media{
    position:relative;
    height:280px;
    overflow:hidden;
    background:
        radial-gradient(circle at 20% 20%,rgba(24,119,242,0.28),transparent 220px),
        radial-gradient(circle at 90% 20%,rgba(255,75,92,0.28),transparent 220px),
        linear-gradient(135deg,#eaf4ff,#fff1f3);
}

.reta-publicada-media img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.reta-publicada-imagen-default{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:58px;
    filter:drop-shadow(0 8px 18px rgba(0,0,0,0.14));
}

.reta-publicada-deporte{
    position:absolute;
    left:14px;
    top:14px;
    max-width:calc(100% - 28px);
    padding:9px 13px;
    border-radius:999px;
    color:#fff;
    font-size:13px;
    font-weight:1000;
    text-transform:uppercase;
    letter-spacing:0.7px;
    background:linear-gradient(135deg,var(--rojo),var(--rojo2));
    box-shadow:0 10px 18px rgba(255,75,92,0.24);
}

.reta-favorito-btn{
    width:48px;
    height:48px;
    min-width:48px;
    border-radius:999px;
    border:2px solid #ff3045;
    background:#ffffff;
    color:#ff3045;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(255,48,69,0.16);
    transition:0.22s ease;
    padding:0;
    flex:0 0 auto;
}

.reta-favorito-btn svg{
    width:28px;
    height:28px;
    display:block;
    fill:transparent;
    stroke:#ff3045;
    stroke-width:1.8;
    transition:0.22s ease;
}

.reta-favorito-btn .corazon-path{
    fill:transparent;
    stroke:#ff3045;
    stroke-width:1.8;
    transition:0.22s ease;
}

.reta-favorito-btn:hover{
    transform:translateY(-2px) scale(1.04);
    box-shadow:0 14px 26px rgba(255,48,69,0.24);
}

.reta-favorito-btn.activo{
    background:#ff3045;
    color:#ffffff;
}

.reta-favorito-btn.activo svg,
.reta-favorito-btn.activo .corazon-path{
    fill:#ffffff;
    stroke:#ffffff;
}

.reta-favorito-btn:disabled{
    opacity:0.65;
    cursor:not-allowed;
    transform:none;
}

.reta-publicada-info{
    padding:16px;
}

.reta-publicada-fecha{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:14px;
    font-size:12px;
    font-weight:900;
    color:#374151;
}

.reta-publicada-fecha span{
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.reta-publicada-info h3{
    color:var(--azul);
    font-size:18px;
    line-height:1.25;
    margin-bottom:8px;
}

.reta-publicada-ubicacion{
    color:#4b5563;
    font-size:13px;
    font-weight:700;
    line-height:1.45;
    margin-bottom:10px;
}

.reta-publicada-descripcion{
    color:#374151;
    font-size:13px;
    line-height:1.55;
    min-height:42px;
    margin-bottom:13px;
}

.reta-publicada-tags{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:14px;
}

.reta-publicada-tags span{
    display:inline-flex;
    align-items:center;
    min-height:30px;
    padding:7px 10px;
    border-radius:999px;
    background:rgba(24,119,242,0.10);
    color:#1f2937;
    font-size:11px;
    font-weight:900;
}

.reta-publicada-tags .tag-cerca{
    background:rgba(255,75,92,0.13);
    color:#b91c1c;
}

.reta-publicada-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding-top:12px;
    border-top:1px solid rgba(107,114,128,0.18);
}

.reta-publicada-precio{
    display:block;
    color:var(--rojo);
    font-size:15px;
    font-weight:1000;
    margin:0 0 14px;
}

.reta-publicada-botones{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
}

.reta-publicada-accion,
.reta-mas-info-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:38px;
    padding:9px 14px;
    border-radius:15px;
    text-decoration:none;
    color:#fff;
    background:linear-gradient(135deg,var(--azul),var(--azul2));
    border:0;
    font-family:'Poppins',sans-serif;
    font-size:12px;
    font-weight:1000;
    box-shadow:0 10px 18px rgba(24,119,242,0.18);
    cursor:pointer;
    transition:0.22s ease;
}

.reta-mas-info-btn{
    background:linear-gradient(135deg,var(--rojo),var(--rojo2));
    box-shadow:0 10px 18px rgba(255,75,92,0.20);
}

.reta-publicada-accion:hover,
.reta-mas-info-btn:hover,
.reta-mas-info-btn.activo{
    transform:translateY(-2px);
}

#popup{
    position:fixed;
    inset:0;
    width:100%;
    height:100%;
    background:rgba(255,255,255,0.62);
    backdrop-filter:blur(8px);
    display:none;
    justify-content:center;
    align-items:center;
    z-index:8000;
    padding:clamp(14px,4vw,24px);
    overflow-y:auto;
    animation:fadeIn 0.3s ease;
}

#popup-contenido{
    background:
        linear-gradient(rgba(255,255,255,0.97),rgba(255,255,255,0.97)) padding-box,
        linear-gradient(135deg,var(--azul2),var(--rojo)) border-box;
    padding:clamp(22px,5vw,32px);
    border-radius:24px;
    text-align:center;
    width:min(100%,560px);
    max-height:calc(100dvh - 36px);
    overflow-y:auto;
    border:2px solid transparent;
    color:var(--texto);
    box-shadow:
        0 0 32px rgba(0,153,255,0.30),
        0 0 40px rgba(255,75,92,0.20),
        0 16px 45px rgba(0,0,0,0.16);
    animation:popupRetaSube 0.35s ease;
}

@keyframes popupRetaSube{
    from{opacity:0;transform:translateY(50px);}
    to{opacity:1;transform:translateY(0);}
}

#popup-titulo{
    color:var(--azul);
    margin-bottom:18px;
    font-size:clamp(1.2rem,4.5vw,1.45rem);
    line-height:1.3;
    text-shadow:0 0 8px rgba(255,75,92,0.20);
}

#popup-body{
    width:100%;
}

.popup-subtitulo{
    display:block;
    color:var(--rojo);
    font-size:11px;
    font-weight:1000;
    text-transform:uppercase;
    letter-spacing:0.7px;
    margin-bottom:12px;
}

.popup-detalle-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    text-align:left;
}

.popup-detalle-item{
    min-width:0;
    border-radius:14px;
    padding:10px 11px;
    background:rgba(24,119,242,0.06);
    border:1px solid rgba(24,119,242,0.16);
}

.popup-detalle-item strong{
    display:block;
    margin-bottom:4px;
    color:#111827;
    font-size:11px;
    font-weight:1000;
    word-break:break-word;
}

.popup-detalle-item span{
    display:block;
    color:#374151;
    font-size:12px;
    font-weight:700;
    line-height:1.45;
    word-break:break-word;
}

#popup .btn-danger{
    width:100%;
    min-height:50px;
    margin-top:18px;
    border:0;
    border-radius:14px;
    padding:13px 15px;
    cursor:pointer;
    color:#fff;
    font-family:'Poppins',sans-serif;
    font-size:clamp(0.95rem,3.6vw,1rem);
    font-weight:900;
    background:linear-gradient(90deg,var(--rojo),#d92035);
    transition:transform 0.25s ease, box-shadow 0.3s ease;
}

#popup .btn-danger:hover{
    transform:translateY(-3px) scale(1.015);
    box-shadow:0 0 24px rgba(255,75,92,0.45);
}

body.modal-reta-abierto{
    overflow:hidden;
}

.retas-empty,
.retas-error{
    width:100%;
    padding:26px 18px;
    border-radius:24px;
    text-align:center;
    background:rgba(255,255,255,0.82);
    border:2px dashed rgba(24,119,242,0.38);
    color:#374151;
    font-weight:800;
}

.retas-error{
    border-color:rgba(255,75,92,0.48);
    color:#b91c1c;
}

.retas-cargar-wrap{
    display:flex;
    justify-content:center;
    margin-top:14px;
}

.retas-cargar-btn{
    min-width:190px;
    min-height:46px;
    border:0;
    border-radius:18px;
    cursor:pointer;
    color:#fff;
    font-size:13px;
    font-weight:1000;
    font-family:'Poppins',sans-serif;
    background:linear-gradient(135deg,var(--rojo),var(--rojo2));
    box-shadow:var(--sombra-roja);
    transition:0.22s ease;
}

.retas-cargar-btn:hover{
    transform:translateY(-2px);
}

.retas-filtros-form{
    width:100%;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr)) auto auto;
    gap:10px;
    align-items:end;
    margin:0 0 18px;
    padding:16px;
    border-radius:24px;
    background:rgba(255,255,255,0.88);
    border:2px solid rgba(0,153,255,0.24);
    box-shadow:
        0 10px 22px rgba(0,0,0,0.07),
        0 0 0 2px rgba(255,75,92,0.07);
    backdrop-filter:blur(10px);
}

.retas-filtro-grupo{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:6px;
}

.retas-filtro-grupo label{
    color:#374151;
    font-size:11px;
    font-weight:1000;
    text-transform:uppercase;
    letter-spacing:.45px;
}

.retas-filtro-control{
    width:100%;
    min-height:42px;
    border-radius:15px;
    border:2px solid rgba(0,153,255,0.24);
    background:#ffffff;
    color:#111827;
    font-family:'Poppins',sans-serif;
    font-size:13px;
    font-weight:800;
    padding:9px 11px;
    outline:none;
    transition:.22s ease;
}

.retas-filtro-control:focus{
    border-color:rgba(0,153,255,0.75);
    box-shadow:0 0 0 4px rgba(0,153,255,0.12);
}

.retas-filtro-btn,
.retas-filtro-limpiar{
    min-height:42px;
    border:0;
    border-radius:15px;
    padding:10px 14px;
    font-family:'Poppins',sans-serif;
    font-size:12px;
    font-weight:1000;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.22s ease;
    white-space:nowrap;
}

.retas-filtro-btn{
    color:#fff;
    background:linear-gradient(135deg,var(--azul),var(--azul2));
    box-shadow:0 10px 18px rgba(24,119,242,0.18);
}

.retas-filtro-limpiar{
    color:#fff;
    background:linear-gradient(135deg,var(--rojo),var(--rojo2));
    box-shadow:0 10px 18px rgba(255,75,92,0.18);
}

.retas-filtro-btn:hover,
.retas-filtro-limpiar:hover{
    transform:translateY(-2px);
}

body.dark-mode .retas-filtros-form{
    background:rgba(8,15,30,0.88);
    border-color:rgba(255,47,69,0.60);
    box-shadow:
        0 10px 24px rgba(0,0,0,0.28),
        0 0 0 2px rgba(0,153,255,0.14);
}

body.dark-mode .retas-filtro-grupo label{
    color:#e5e7eb;
}

body.dark-mode .retas-filtro-control{
    background:#0b1220;
    color:#e5e7eb;
    border-color:rgba(0,153,255,0.32);
}

@media screen and (max-width:1050px){
    .retas-filtros-form{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media screen and (max-width:620px){
    .retas-filtros-form{
        grid-template-columns:1fr;
        padding:14px;
    }

    .retas-filtro-btn,
    .retas-filtro-limpiar{
        width:100%;
    }
}

body.dark-mode .retas-publicadas-head h2{
    color:var(--cyan);
}

body.dark-mode .retas-publicadas-head p,
body.dark-mode .reta-publicada-ubicacion,
body.dark-mode .reta-publicada-descripcion,
body.dark-mode .reta-publicada-fecha{
    color:#e5e7eb;
}

body.dark-mode .reta-publicada-card,
body.dark-mode .retas-empty,
body.dark-mode .retas-error{
    background:rgba(8,15,30,0.88);
    border-color:rgba(255,47,69,0.68);
}

body.dark-mode .reta-publicada-info h3{
    color:var(--cyan);
}

body.dark-mode .reta-publicada-tags span{
    background:rgba(143,239,255,0.12);
    color:#e5e7eb;
}

body.dark-mode .reta-publicada-tags .tag-cerca{
    background:rgba(255,75,92,0.18);
    color:#fecaca;
}

body.dark-mode .reta-favorito-btn{
    background:rgba(17,24,39,0.94);
    border-color:#ff6b7a;
    color:#ff6b7a;
    box-shadow:0 12px 24px rgba(0,0,0,0.34), 0 0 0 2px rgba(255,75,92,0.25);
}

body.dark-mode .reta-favorito-btn svg,
body.dark-mode .reta-favorito-btn .corazon-path{
    stroke:#ff6b7a;
}

body.dark-mode .reta-favorito-btn.activo{
    background:#ff3045;
    border-color:#ff3045;
    color:#ffffff;
}

body.dark-mode .reta-favorito-btn.activo svg,
body.dark-mode .reta-favorito-btn.activo .corazon-path{
    fill:#ffffff;
    stroke:#ffffff;
}

body.dark-mode #popup{
    background:rgba(8,15,30,0.72);
}

body.dark-mode #popup-contenido{
    background:
        linear-gradient(rgba(17,24,39,0.97),rgba(17,24,39,0.97)) padding-box,
        linear-gradient(135deg,var(--azul2),var(--rojo)) border-box;
    color:#e5e7eb;
    box-shadow:
        0 0 32px rgba(0,153,255,0.24),
        0 0 40px rgba(255,75,92,0.18),
        0 16px 45px rgba(0,0,0,0.34);
}

body.dark-mode #popup-titulo{
    color:var(--cyan);
}

body.dark-mode .popup-subtitulo{
    color:#ff7b87;
}

body.dark-mode .popup-detalle-item{
    background:#0b1220;
    border-color:rgba(0,153,255,0.28);
}

body.dark-mode .popup-detalle-item strong,
body.dark-mode .popup-detalle-item span{
    color:#e5e7eb;
}

@media screen and (max-width:820px){
    .retas-publicadas-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .retas-total{
        align-self:flex-start;
    }

    .reta-publicada-card{
        width:100%;
    }
}

@media screen and (max-width:520px){
    .retas-publicadas-section{
        margin-top:20px;
    }

    .reta-publicada-media{
        height:220px;
    }

    .reta-publicada-footer{
        align-items:stretch;
        flex-direction:column;
    }

    .reta-publicada-botones,
    .reta-publicada-accion,
    .reta-mas-info-btn{
        width:100%;
    }

    .popup-detalle-grid{
        grid-template-columns:1fr;
    }
}

</style>
</head>

<body class="<?php echo $modoOscuroActivo ? 'dark-mode' : ''; ?>">

<div class="toast-container" id="toastContainer"></div>

<div id="popup" aria-hidden="true">
    <div id="popup-contenido" role="dialog" aria-modal="true" aria-labelledby="popup-titulo">
        <h3 id="popup-titulo">📋 Información completa de la reta</h3>
        <div id="popup-body"></div>
        <button type="button" onclick="cerrarPopup()" class="btn-danger">❌ Cerrar</button>
    </div>
</div>

<div class="bg-particles"></div>
<div class="mobile-overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="logo-area sidebar-logo-boceto">
        <img src="../assets/doctor.png" alt="Logo Doctor" class="doctor-logo" onerror="this.style.display='none'">
        <div class="logo-text">
            <h2>RETAME</h2>
            <p>Panel deportivo</p>
        </div>
    </div>

    <div class="sidebar-boceto">
        <a href="../MiPerfil.php" class="perfil-sidebar-card">
            <div class="perfil-sidebar-foto">
                <?php if ($FotoPerfilUsuario !== ''): ?>
                    <img src="<?php echo limpiarTexto($FotoPerfilUsuario); ?>" alt="Foto de perfil" onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='<?php echo limpiarTexto($iniciales); ?>';">
                <?php else: ?>
                    <span><?php echo limpiarTexto($iniciales); ?></span>
                <?php endif; ?>
            </div>

            <div class="perfil-sidebar-info">
                <span>Perfil</span>
                <strong><?php echo limpiarTexto($Nombre); ?></strong>
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

            <a href="../Ligas/liga.php" class="accion-boceto">
                <img src="../Imagenes/ImgLigas.png" alt="">
                <span>Ligas</span>
            </a>

            <a href="retar.php" class="accion-boceto active">
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

            <button type="button" class="switch-modo" id="darkModeToggle" aria-label="<?php echo $modoOscuroActivo ? 'Desactivar modo oscuro' : 'Activar modo oscuro'; ?>">
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
        <h1>Retar de <?php echo limpiarTexto($Nombre); ?></h1>
    </div>

    <div class="topbar-user">
        <span>👤</span>
        <span><?php echo limpiarTexto($Nombre); ?></span>
    </div>
</header>

<main class="main-content">
    <section class="inicio-retar-page">
        <div class="inicio-retar-grid">
            <article class="inicio-card-retar inicio-card-principal">
                <div class="inicio-icono-central">⚔️</div>
                <h2>RETAR</h2>
                <p>
                    Organiza una nueva reta o busca partidos disponibles cerca de tu zona. Desde aquí puedes publicar una invitación deportiva o unirte a una competencia dentro de RETAME.
                </p>
            </article>

            <div class="inicio-retar-opciones">
                <a class="inicio-card-retar inicio-opcion-card" href="PublicarReta.php">
                    <div class="inicio-opcion-icono">📢</div>
                    <h3>Publicar reta</h3>
                    <p>Crea una nueva reta</p>
                </a>

                <a class="inicio-card-retar inicio-opcion-card" href="MisRetas.php">
                    <div class="inicio-opcion-icono">📋</div>
                    <h3>Mis retas</h3>
                    <p>Consulta tus retas publicadas y programadas</p>
                </a>
            </div>
        </div>

        <section class="retas-publicadas-section">
            <div class="retas-publicadas-head">
                <div>
                    <span>🔥 Retas publicadas</span>
                    <h2>Partidos cerca de ti</h2>
                    <p>Primero aparecen las retas que comparten tu mismo código postal. Después se acomodan las más cercanas a tu zona.</p>
                </div>

                <strong class="retas-total"><?php echo count($retasPublicadas); ?> disponibles</strong>
            </div>

            <form class="retas-filtros-form" method="GET" action="retar.php">
                <div class="retas-filtro-grupo">
                    <label for="deporte_id">Deporte</label>
                    <select class="retas-filtro-control" name="deporte_id" id="deporte_id">
                        <option value="">Todos los deportes</option>
                        <?php foreach ($deportesFiltro as $deporteFiltro): ?>
                            <option value="<?php echo limpiarTexto($deporteFiltro['id']); ?>" <?php echo ((string)$filtroDeporteId === (string)$deporteFiltro['id']) ? 'selected' : ''; ?>>
                                <?php echo limpiarTexto($deporteFiltro['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="retas-filtro-grupo">
                    <label for="orden">Orden</label>
                    <select class="retas-filtro-control" name="orden" id="orden">
                        <option value="cercanas" <?php echo $ordenRetas === 'cercanas' ? 'selected' : ''; ?>>Más cercanas</option>
                        <option value="lejanas" <?php echo $ordenRetas === 'lejanas' ? 'selected' : ''; ?>>Más lejanas</option>
                    </select>
                </div>

                <div class="retas-filtro-grupo">
                    <label for="codigo_postal">Código postal</label>
                    <input class="retas-filtro-control" type="text" name="codigo_postal" id="codigo_postal" value="<?php echo limpiarTexto($filtroCodigoPostal); ?>" placeholder="Ej. 30190">
                </div>

                <div class="retas-filtro-grupo">
                    <label for="ciudad">Ciudad</label>
                    <input class="retas-filtro-control" type="text" name="ciudad" id="ciudad" value="<?php echo limpiarTexto($filtroCiudad); ?>" placeholder="Ej. Comitán">
                </div>

                <button class="retas-filtro-btn" type="submit">Filtrar</button>
                <a class="retas-filtro-limpiar" href="retar.php">Limpiar</a>
            </form>

            <?php if ($errorRetasPublicadas !== ''): ?>
                <div class="retas-error"><?php echo limpiarTexto($errorRetasPublicadas); ?></div>
            <?php elseif (count($retasPublicadas) === 0): ?>
                <div class="retas-empty">Todavía no hay retas publicadas para mostrar.</div>
            <?php else: ?>
                <div class="retas-feed" id="retasFeed">
                    <?php foreach ($retasPublicadas as $indiceReta => $reta): ?>
                        <?php
                            $canchaBD = (isset($reta['_cancha']) && is_array($reta['_cancha'])) ? $reta['_cancha'] : [];
                            $idReta = obtenerIdRetaPublicada($reta);
                            $deporteReta = obtenerDato($reta, ['Deporte', 'deporte', 'Tipo_Deporte', 'tipo_deporte'], 'Deporte');
                            $imagenCanchaBD = obtenerDato($canchaBD, ['Foto', 'foto'], '');
                            $imagenRetaBD = obtenerDato($reta, ['Imagen', 'imagen', 'Foto', 'foto', 'Imagen_Reta', 'imagen_reta', 'FotoReta', 'foto_reta'], '');
                            $rutasImagenReta = rutasImagenReta($imagenCanchaBD !== '' ? $imagenCanchaBD : $imagenRetaBD);
                            $imagenReta = isset($rutasImagenReta[0]) ? $rutasImagenReta[0] : '';
                            $rutasImagenRetaJson = json_encode($rutasImagenReta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            $canchaReta = obtenerDato($canchaBD, ['Nombre'], obtenerDato($reta, ['Cancha', 'cancha', 'Nombre_Cancha', 'nombre_cancha', 'NombreCancha', 'Nombre', 'nombre'], 'Cancha por definir'));
                            $direccionCancha = obtenerDato($canchaBD, ['Direccion'], '');
                            $ciudadCancha = obtenerDato($canchaBD, ['Ciudad'], '');
                            $estadoUbicacionCancha = obtenerDato($canchaBD, ['Estado'], '');
                            $ubicacionCancha = trim($direccionCancha . (($ciudadCancha !== '') ? ', ' . $ciudadCancha : '') . (($estadoUbicacionCancha !== '') ? ', ' . $estadoUbicacionCancha : ''), ', ');
                            $ubicacionReta = ($ubicacionCancha !== '') ? $ubicacionCancha : obtenerDato($reta, ['Ubicacion', 'ubicacion', 'Lugar', 'lugar', 'Direccion', 'direccion', 'Ciudad', 'ciudad'], 'Ubicación por definir');
                            $descripcionReta = obtenerDato($reta, ['Descripcion', 'descripcion', 'Descripción', 'Comentarios', 'comentarios', 'Comentario', 'comentario'], obtenerDato($canchaBD, ['Comentarios'], 'Sin descripción disponible.'));
                            $fechaReta = obtenerDato($reta, ['Fecha', 'fecha', 'Fecha_Reta', 'fecha_reta'], 'Fecha pendiente');
                            $horaInicioReta = obtenerDato($reta, ['Hora_Inicio', 'hora_inicio', 'HoraInicio', 'horaInicio', 'Hora', 'hora'], '');
                            $horaTerminoReta = obtenerDato($reta, ['Hora_Termino', 'hora_termino', 'HoraTermino', 'horaTermino'], '');
                            $codigoPostalReta = obtenerCodigoPostalReta($reta);
                            $estadoReta = obtenerDato($reta, ['Estado_Reta', 'estado_reta', 'EstadoReta', 'estadoReta', 'Estado', 'estado'], 'Publicada');
                            $estadoCanchaReta = obtenerDato($canchaBD, ['Estado_Cancha'], obtenerDato($reta, ['Estado_Cancha', 'estado_cancha', 'EstadoCancha', 'estadoCancha'], ''));
                            $precioReta = obtenerDato($canchaBD, ['Costo'], obtenerDato($reta, ['Precio', 'precio', 'Costo', 'costo'], ''));
                            $distanciaReta = distanciaCodigoPostal($codigoPostalUsuario, $codigoPostalReta);
                            $textoCercania = 'Reta publicada';

                            if ($codigoPostalUsuario !== '' && $codigoPostalReta !== '') {
                                $textoCercania = ($distanciaReta === 0) ? 'Tu mismo C.P.' : 'C.P. cercano';
                            }

                            $urlSalaEspera = 'SalaEspera.php';

                            if ($idReta !== '') {
                                $urlSalaEspera .= '?id_reta=' . urlencode($idReta);
                            }

                            $idDetalleReta = 'detalle-reta-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$idReta);
                            if ($idDetalleReta === 'detalle-reta-') {
                                $idDetalleReta = 'detalle-reta-' . $indiceReta;
                            }

                            $detalleRetaPublicada = [];
                            foreach ($reta as $campoReta => $valorReta) {
                                if ($campoReta === '_cancha' || is_array($valorReta) || is_object($valorReta)) {
                                    continue;
                                }
                                $detalleRetaPublicada[$campoReta] = (string)$valorReta;
                            }
                            $detalleRetaJson = json_encode($detalleRetaPublicada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                            $favoritoActivo = ($idReta !== '' && in_array((string)$idReta, $favoritosRetasGuardados, true));
                        ?>

                        <article class="reta-publicada-card" data-reta-card>
                            <div class="reta-publicada-media">
                                <?php if ($imagenReta !== ''): ?>
                                    <img src="<?php echo limpiarTexto($imagenReta); ?>" data-rutas='<?php echo limpiarTexto($rutasImagenRetaJson); ?>' data-indice="0" alt="<?php echo limpiarTexto($deporteReta); ?>" onerror="probarSiguienteImagen(this);">
                                    <div class="reta-publicada-imagen-default" style="display:none;">🏟️</div>
                                <?php else: ?>
                                    <div class="reta-publicada-imagen-default">🏟️</div>
                                <?php endif; ?>

                                <div class="reta-publicada-deporte"><?php echo limpiarTexto($deporteReta); ?></div>
                            </div>

                            <div class="reta-publicada-info">
                                <div class="reta-publicada-fecha">
                                    <span>📅 <?php echo limpiarTexto($fechaReta); ?></span>
                                    <span>⏰ <?php echo limpiarTexto(trim($horaInicioReta . ' - ' . $horaTerminoReta, ' -')); ?></span>
                                </div>

                                <h3><?php echo limpiarTexto($canchaReta); ?></h3>

                                <p class="reta-publicada-ubicacion">📍 <?php echo limpiarTexto($ubicacionReta); ?></p>

                                <p class="reta-publicada-descripcion"><?php echo limpiarTexto($descripcionReta); ?></p>

                                <strong class="reta-publicada-precio">
                                    <?php echo ($precioReta !== '') ? 'Costo de la cancha: ' . limpiarTexto($precioReta) : 'Costo de la cancha: por definir'; ?>
                                </strong>

                                <div class="reta-publicada-tags">
                                    <span class="tag-cerca"><?php echo limpiarTexto($textoCercania); ?></span>

                                    <?php if ($codigoPostalReta !== ''): ?>
                                        <span>C.P. <?php echo limpiarTexto($codigoPostalReta); ?></span>
                                    <?php endif; ?>

                                    <span><?php echo limpiarTexto($estadoReta); ?></span>

                                    <?php if ($estadoCanchaReta !== ''): ?>
                                        <span><?php echo limpiarTexto($estadoCanchaReta); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="reta-publicada-footer">
                                    <button type="button" class="reta-favorito-btn <?php echo $favoritoActivo ? 'activo' : ''; ?>" data-id-categoria="<?php echo limpiarTexto($idReta); ?>" data-deporte="<?php echo limpiarTexto($deporteReta); ?>" title="<?php echo $favoritoActivo ? 'Quitar de favoritos' : 'Guardar en favoritos'; ?>" aria-label="<?php echo $favoritoActivo ? 'Quitar de favoritos' : 'Guardar en favoritos'; ?>" aria-pressed="<?php echo $favoritoActivo ? 'true' : 'false'; ?>" <?php echo $idReta === '' ? 'disabled' : ''; ?>>
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path class="corazon-path" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"></path>
                                        </svg>
                                    </button>

                                    <div class="reta-publicada-botones">
                                        <button type="button" class="reta-mas-info-btn" data-detalle='<?php echo limpiarTexto($detalleRetaJson); ?>' data-deporte="<?php echo limpiarTexto($deporteReta); ?>" data-cancha="<?php echo limpiarTexto($canchaReta); ?>" aria-expanded="false">
                                            Más información
                                        </button>

                                        <a class="reta-publicada-accion" href="<?php echo limpiarTexto($urlSalaEspera); ?>">Retar</a>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="retas-cargar-wrap">
                    <button type="button" class="retas-cargar-btn" id="cargarRetasBtn">Cargar más retas</button>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>

<nav class="bottom-nav">
    <a href="../Perfil2.php" aria-label="Inicio">
        <img src="../Imagenes/ImgInicio.png" alt="">
    </a>

    <a href="retar.php" class="active" aria-label="Retar">
        <img src="../Imagenes/ImgReta.png" alt="">
    </a>

    <a href="../Ligas/liga.php" aria-label="Ligas">
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
const modoOscuroInicial = <?php echo $modoOscuroActivo ? 'true' : 'false'; ?>;
let lastScrollY = window.scrollY;

function aplicarModoOscuroVisual(estado){
    body.classList.toggle('dark-mode', estado);

    if(darkModeToggle){
        darkModeToggle.setAttribute('aria-label', estado ? 'Desactivar modo oscuro' : 'Activar modo oscuro');
    }
}

function actualizarModoPerfilBD(estado){
    const datos = new FormData();
    datos.append('accion', 'actualizar_modo_perfil');
    datos.append('modo', estado ? 'oscuro' : 'predeterminado');

    return fetch(window.location.href, {
        method: 'POST',
        body: datos,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(respuesta => respuesta.json());
}

aplicarModoOscuroVisual(modoOscuroInicial);

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
        const nuevoEstado = !body.classList.contains('dark-mode');
        const estadoAnterior = !nuevoEstado;

        aplicarModoOscuroVisual(nuevoEstado);
        darkModeToggle.disabled = true;

        actualizarModoPerfilBD(nuevoEstado)
            .then(data => {
                if(!data || !data.ok){
                    aplicarModoOscuroVisual(estadoAnterior);
                    alert(data && data.mensaje ? data.mensaje : 'No se pudo guardar el modo de perfil.');
                }
            })
            .catch(() => {
                aplicarModoOscuroVisual(estadoAnterior);
                alert('No se pudo conectar con la base de datos para guardar el modo.');
            })
            .finally(() => {
                darkModeToggle.disabled = false;
            });
    });
}

function probarSiguienteImagen(img){
    let rutas = [];

    try{
        rutas = JSON.parse(img.getAttribute('data-rutas') || '[]');
    }catch(error){
        rutas = [];
    }

    const indiceActual = parseInt(img.getAttribute('data-indice') || '0', 10);
    const siguienteIndice = indiceActual + 1;

    if(siguienteIndice < rutas.length){
        img.setAttribute('data-indice', siguienteIndice);
        img.src = rutas[siguienteIndice];
        return;
    }

    img.style.display = 'none';

    const respaldo = img.parentElement ? img.parentElement.querySelector('.reta-publicada-imagen-default') : null;

    if(respaldo){
        respaldo.style.display = 'flex';
    }
}

const retasFeed = document.getElementById('retasFeed');
const retasCards = Array.from(document.querySelectorAll('[data-reta-card]'));
const cargarRetasBtn = document.getElementById('cargarRetasBtn');
let cantidadRetasVisibles = 4;

function pintarRetasVisibles(){
    if(retasCards.length === 0){
        return;
    }

    retasCards.forEach((card, index) => {
        card.classList.toggle('oculta', index >= cantidadRetasVisibles);
    });

    if(!cargarRetasBtn){
        return;
    }

    if(retasCards.length <= 4){
        cargarRetasBtn.style.display = 'none';
        return;
    }

    cargarRetasBtn.textContent = cantidadRetasVisibles >= retasCards.length ? 'Cargar de nuevo' : 'Cargar más retas';
}

function abrirPopup(detalle, titulo){
    const popup = document.getElementById('popup');
    const popupTitulo = document.getElementById('popup-titulo');
    const popupBody = document.getElementById('popup-body');

    if(!popup || !popupTitulo || !popupBody){
        return;
    }

    popupTitulo.textContent = titulo || '📋 Información completa de la reta';

    let html = '<span class="popup-subtitulo">Tabla r_retaspublicadas</span>';
    html += '<div class="popup-detalle-grid">';

    const llaves = Object.keys(detalle || {});

    if(llaves.length === 0){
        html += '<div class="popup-detalle-item"><strong>Información</strong><span>Sin datos para mostrar.</span></div>';
    }else{
        llaves.forEach((campo) => {
            const valor = detalle[campo];
            html += `
                <div class="popup-detalle-item">
                    <strong>${escapeHtml(campo)}</strong>
                    <span>${escapeHtml(valor !== null && valor !== undefined && String(valor).trim() !== '' ? valor : 'Sin dato')}</span>
                </div>
            `;
        });
    }

    html += '</div>';
    popupBody.innerHTML = html;
    popup.style.display = 'flex';
    popup.setAttribute('aria-hidden', 'false');
    body.classList.add('modal-reta-abierto');

    const botonCerrar = popup.querySelector('.btn-danger');
    if(botonCerrar){
        botonCerrar.focus({ preventScroll:true });
    }
}

function cerrarPopup(){
    const popup = document.getElementById('popup');

    if(!popup){
        return;
    }

    popup.style.display = 'none';
    popup.setAttribute('aria-hidden', 'true');
    body.classList.remove('modal-reta-abierto');

    document.querySelectorAll('.reta-mas-info-btn').forEach((boton) => {
        boton.classList.remove('activo');
        boton.setAttribute('aria-expanded', 'false');
        boton.textContent = 'Más información';
    });
}

function activarBotonesMasInformacionReta(){
    document.addEventListener('click', (evento) => {
        const botonInfo = evento.target.closest('.reta-mas-info-btn');

        if(botonInfo){
            evento.preventDefault();
            evento.stopPropagation();

            let detalle = {};

            try{
                detalle = JSON.parse(botonInfo.getAttribute('data-detalle') || '{}');
            }catch(error){
                detalle = {};
            }

            const deporte = botonInfo.dataset.deporte || 'Reta';
            const cancha = botonInfo.dataset.cancha || '';
            const titulo = cancha ? `📋 ${deporte} - ${cancha}` : `📋 Información de ${deporte}`;

            document.querySelectorAll('.reta-mas-info-btn').forEach((otroBoton) => {
                otroBoton.classList.remove('activo');
                otroBoton.setAttribute('aria-expanded', 'false');
                otroBoton.textContent = 'Más información';
            });

            botonInfo.classList.add('activo');
            botonInfo.setAttribute('aria-expanded', 'true');
            botonInfo.textContent = 'Más información';

            abrirPopup(detalle, titulo);
            return;
        }

        const popup = document.getElementById('popup');

        if(popup && evento.target === popup){
            cerrarPopup();
        }
    });

    document.addEventListener('keydown', (evento) => {
        if(evento.key === 'Escape'){
            cerrarPopup();
        }
    });
}

if(cargarRetasBtn){
    cargarRetasBtn.addEventListener('click', () => {
        if(cantidadRetasVisibles >= retasCards.length){
            cantidadRetasVisibles = 4;

            if(retasFeed){
                retasFeed.scrollIntoView({
                    behavior:'smooth',
                    block:'start'
                });
            }
        }else{
            cantidadRetasVisibles = Math.min(cantidadRetasVisibles + 4, retasCards.length);
        }

        pintarRetasVisibles();
    });
}

function escapeHtml(str){
    return String(str)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function mostrarMensajeFavorito(mensaje){
    const cont = document.getElementById('toastContainer');

    if(!cont){
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <div class="icon">♥</div>
        <div class="content">
            <div class="title">Favoritos</div>
            <div class="msg">${escapeHtml(mensaje)}</div>
            <div class="meta"><span>Ahora</span></div>
        </div>
    `;

    cont.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity 240ms ease, transform 240ms ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-6px)';

        setTimeout(() => {
            if(toast && toast.parentNode){
                toast.parentNode.removeChild(toast);
            }
        }, 260);
    }, 2600);
}

function activarBotonesFavoritosReta(){
    document.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('.reta-favorito-btn');

        if(!boton){
            return;
        }

        evento.preventDefault();
        evento.stopPropagation();

        if(boton.disabled || boton.dataset.procesando === '1'){
            return;
        }

        const idCategoria = boton.dataset.idCategoria || '';
        const deporte = boton.dataset.deporte || 'Deporte';

        if(idCategoria.trim() === ''){
            mostrarMensajeFavorito('No se encontró la ID de la reta publicada.');
            return;
        }

        const estadoAnterior = boton.classList.contains('activo');
        const datos = new FormData();
        datos.append('accion', 'toggle_favorito_reta');
        datos.append('id_categoria', idCategoria);
        datos.append('deporte', deporte);

        boton.dataset.procesando = '1';
        boton.disabled = true;

        try{
            const respuesta = await fetch(window.location.href, {
                method:'POST',
                body:datos,
                credentials:'same-origin',
                headers:{
                    'X-Requested-With':'XMLHttpRequest'
                }
            });

            const texto = await respuesta.text();
            let data = null;

            try{
                data = JSON.parse(texto);
            }catch(errorJson){
                console.error('Respuesta del servidor que no es JSON:', texto);
                mostrarMensajeFavorito('El servidor no respondió correctamente.');
                return;
            }

            if(!data || !data.ok){
                boton.classList.toggle('activo', estadoAnterior);
                boton.setAttribute('aria-pressed', estadoAnterior ? 'true' : 'false');
                boton.title = estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos';
                boton.setAttribute('aria-label', estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos');
                mostrarMensajeFavorito(data && data.mensaje ? data.mensaje : 'No se pudo actualizar favoritos.');
                return;
            }

            const guardado = !!data.guardado;
            boton.classList.toggle('activo', guardado);
            boton.title = guardado ? 'Quitar de favoritos' : 'Guardar en favoritos';
            boton.setAttribute('aria-label', guardado ? 'Quitar de favoritos' : 'Guardar en favoritos');
            boton.setAttribute('aria-pressed', guardado ? 'true' : 'false');
            mostrarMensajeFavorito(data.mensaje || (guardado ? 'Reta guardada en favoritos.' : 'Reta quitada de favoritos.'));
        }catch(error){
            console.error(error);
            boton.classList.toggle('activo', estadoAnterior);
            boton.setAttribute('aria-pressed', estadoAnterior ? 'true' : 'false');
            boton.title = estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos';
            boton.setAttribute('aria-label', estadoAnterior ? 'Quitar de favoritos' : 'Guardar en favoritos');
            mostrarMensajeFavorito('No se pudo conectar con la base de datos.');
        }finally{
            boton.disabled = false;
            boton.dataset.procesando = '0';
        }
    });
}

activarBotonesMasInformacionReta();
activarBotonesFavoritosReta();
pintarRetasVisibles();

</script>

</body>
</html>
