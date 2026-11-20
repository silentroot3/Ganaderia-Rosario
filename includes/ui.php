<?php
/**
 * Utilidades de interfaz y seguridad compartidas por los modulos nuevos
 * (escape HTML, CSRF en formularios POST, estilos de campos)
 */

require_once __DIR__ . '/costos.php';   // dinero() y calculo de costos disponibles en todos los modulos

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Campo oculto con el token CSRF para formularios POST */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . h(generarCSRF()) . '">';
}

/** Lanza excepcion si el POST no trae un token CSRF valido */
function exigirCSRF() {
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        throw new Exception('Token de seguridad invalido. Recarga la pagina e intenta de nuevo.');
    }
}

const ESTILO_INPUT = 'width:100%;';
const ESTILO_LABEL = 'display:block;margin-bottom:5px;font-weight:600;font-size:0.8rem;color:#4b5563;';

function fechaCorta($f) {
    return $f ? date('d/m/Y', strtotime($f)) : '-';
}

function fechaHora($f) {
    return $f ? date('d/m/Y H:i', strtotime($f)) : '-';
}

/** Etiqueta de color simple */
function pill($texto, $fondo = 'var(--f-gris)', $color = 'var(--texto-2)') {
    return '<span style="background:' . $fondo . ';color:' . $color . ';padding:2px 9px;border-radius:10px;font-size:0.72rem;font-weight:600;white-space:nowrap;">'
         . h($texto) . '</span>';
}

function pillEstado($estado) {
    $m = [
        'en_corral' => ['var(--f-verde)', 'var(--t-verde)'], 'clasificado' => ['var(--f-verde)', 'var(--t-verde)'],
        'apto' => ['var(--f-verde)', 'var(--t-verde)'], 'recibido' => ['var(--f-azul)', 'var(--t-azul)'],
        'revision' => ['var(--f-amarillo)', 'var(--t-amarillo)'], 'observacion' => ['var(--f-amarillo)', 'var(--t-amarillo)'],
        'en_cuarentena' => ['var(--f-rojo)', 'var(--t-rojo)'], 'rechazado' => ['var(--f-rojo)', 'var(--t-rojo)'],
        'muerto' => ['#343a40', '#ffffff'], 'vendido' => ['var(--f-morado)', 'var(--t-morado)'],
        'devuelto' => ['var(--f-gris)', 'var(--texto-2)'],
    ];
    $c = $m[$estado] ?? ['var(--f-gris)', 'var(--texto-2)'];
    return pill(str_replace('_', ' ', $estado), $c[0], $c[1]);
}
