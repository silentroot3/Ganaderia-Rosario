<?php
// ============================================
// REPORTES - Exportar a Excel (CSV con separador ; y UTF-8 BOM)
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';

Permisos::protegerPagina('reportes', 'ver');

[$desde, $hasta] = normalizarRango($_GET['desde'] ?? '', $_GET['hasta'] ?? '');
$tipo = ($_GET['tipo'] ?? 'rentabilidad') === 'ventas' ? 'ventas' : 'rentabilidad';

/** Evita inyeccion de formulas al abrir en Excel */
function celda($v) {
    $v = (string)$v;
    return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . $desde . '_' . $hasta . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($tipo === 'ventas') {
    fputcsv($out, ['Folio', 'Fecha', 'Comprador', 'Cabezas', 'Kilos', 'Subtotal', 'IVA', 'Total', 'Factura'], ';', '"', '\\');
    foreach (datosVentas($desde, $hasta) as $v) {
        fputcsv($out, [celda($v['folio']), $v['fecha'], celda($v['comprador']), $v['cabezas'], $v['kilos'], $v['subtotal'], $v['iva'], $v['total'], celda($v['factura'])], ';', '"', '\\');
    }
} else {
    $r = datosRentabilidad($desde, $hasta);
    fputcsv($out, ['Fecha', 'Folio', 'Arete', 'Comprador', 'Peso final', 'Precio kg', 'Ingreso', 'Costo compra', 'Costo alimento', 'Costo medicina', 'Otros', 'Costo total', 'Ganancia', 'Margen %'], ';', '"', '\\');
    foreach ($r['filas'] as $f) {
        fputcsv($out, [$f['fecha'], celda($f['folio']), celda($f['id_siniiga']), celda($f['comprador']), $f['peso_final'], $f['precio_kg'], $f['subtotal'],
            $f['c_compra'], $f['c_alimentacion'], $f['c_veterinario'], $f['c_otros'], $f['costo'], $f['ganancia'],
            $f['margen'] !== null ? round($f['margen'], 1) : ''], ';', '"', '\\');
    }
    $t = $r['totales'];
    fputcsv($out, ['TOTAL', '', '', '', $t['kilos'], '', $t['ingreso'], $t['compra'], $t['alimentacion'], $t['veterinario'], $t['otros'], $t['costo'], $t['ganancia'], ''], ';', '"', '\\');
    fputcsv($out, ['Gastos generales sin repartir (dias sin animales)', '', '', '', '', '', '', '', '', '', '', $r['generales']], ';', '"', '\\');
    fputcsv($out, ['Perdidas por mortalidad (' . count($r['muertes']) . ' cabezas)', '', '', '', '', '', '', '', '', '', '', $r['perdidas']], ';', '"', '\\');
    fputcsv($out, ['GANANCIA NETA', '', '', '', '', '', '', '', '', '', '', '', $r['neta']], ';', '"', '\\');
}
fclose($out);
