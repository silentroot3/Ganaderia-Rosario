<?php
/**
 * Generador de archivos de intercambio
 * Formato TXT para envio a BECEMAR / otros compradores
 * Formato CSV para Excel
 */

/**
 * Genera archivo TXT con el lote de bovinos para venta
 */
function generarArchivoLote($recepcion_id, $bovinos, $datosRecepcion) {
    $dir = BASE_PATH . '/uploads/archivos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $folio = $datosRecepcion['folio'] ?? 'LOTE-' . $recepcion_id;
    $fecha = date('Ymd-His');
    $nombre = "lote_{$folio}_{$fecha}.txt";
    $ruta = $dir . $nombre;
    
    $totalAnimales = count($bovinos);
    $pesoTotal = 0;
    $valorTotal = 0;
    
    foreach ($bovinos as $b) {
        $pesoTotal += $b['peso_entrada'];
        $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
        $valorTotal += $cat['precio_total'];
    }
    
    $contenido  = "===============================================\n";
    $contenido .= "  GANADERIA EL ROSARIO\n";
    $contenido .= "  Reporte de Lote para Venta\n";
    $contenido .= "===============================================\n\n";
    $contenido .= "Folio Recepcion: {$folio}\n";
    $contenido .= "Fecha: " . date('d/m/Y H:i') . "\n";
    $contenido .= "Proveedor: " . ($datosRecepcion['proveedor_nombre'] ?? 'N/A') . "\n";
    $contenido .= "Transporte: " . ($datosRecepcion['transporte'] ?? 'N/A') . "\n";
    $contenido .= "Placas: " . ($datosRecepcion['placas'] ?? 'N/A') . "\n";
    $contenido .= "Chofer: " . ($datosRecepcion['chofer'] ?? 'N/A') . "\n\n";
    $contenido .= "===============================================\n";
    $contenido .= "  DETALLE DE ANIMALES\n";
    $contenido .= "===============================================\n\n";
    $contenido .= sprintf("%-15s %-4s %-10s %-12s %-8s %-10s %-12s\n", 
        "SINIIGA", "SEXO", "RAZA", "CATEGORIA", "PESO", "PRECIO/KG", "SUBTOTAL");
    $contenido .= str_repeat("-", 85) . "\n";
    
    foreach ($bovinos as $b) {
        $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
        $contenido .= sprintf("%-15s %-4s %-10s %-12s %-8.2f %-10.2f %-12.2f\n",
            $b['id_siniiga'],
            $b['sexo'],
            substr($b['raza'] ?? 'N/A', 0, 10),
            $cat['subcategoria'],
            $b['peso_entrada'],
            $cat['precio_kg'],
            $cat['precio_total']);
    }
    
    $contenido .= str_repeat("-", 85) . "\n";
    $contenido .= sprintf("TOTAL: %d animales | Peso: %.2f kg | Valor: $%.2f MXN\n\n", 
        $totalAnimales, $pesoTotal, $valorTotal);
    $contenido .= "Contacto: 657-136-32-83\n";
    $contenido .= "===============================================\n";
    
    file_put_contents($ruta, $contenido);
    
    return [
        'ruta' => $ruta,
        'nombre' => $nombre,
        'total_animales' => $totalAnimales,
        'peso_total' => $pesoTotal,
        'valor_total' => $valorTotal
    ];
}

/**
 * Genera el ACTA DE RECEPCION con resumen de bajas
 */
function generarActaRecepcion($recepcion_id, $bovinos, $bajas, $datosRecepcion) {
    $dir = BASE_PATH . '/uploads/archivos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $folio = $datosRecepcion['folio'] ?? 'REC-' . $recepcion_id;
    $fecha = date('Ymd-His');
    $nombre = "acta_{$folio}_{$fecha}.txt";
    $ruta = $dir . $nombre;
    
    // Contar estados
    $totalRecibidos = 0;
    $totalMuertos = 0;
    $totalDevueltos = 0;
    $pesoTotal = 0;
    $valorTotal = 0;
    $machos = 0;
    $hembras = 0;
    $enHospital = 0;
    $bovinosVivos = [];
    $bovinosMuertos = [];
    $bovinosDevueltos = [];
    
    foreach ($bovinos as $b) {
        if ($b['estado'] === 'muerto') {
            $totalMuertos++;
            $bovinosMuertos[] = $b;
            continue;
        }
        if ($b['estado'] === 'devuelto') {
            $totalDevueltos++;
            $bovinosDevueltos[] = $b;
            continue;
        }
        
        $totalRecibidos++;
        $bovinosVivos[] = $b;
        $pesoTotal += $b['peso_entrada'];
        $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
        $valorTotal += $cat['precio_total'];
        if ($b['sexo'] === 'M') $machos++; else $hembras++;
        if (!empty($b['en_hospital'])) $enHospital++;
    }
    
    $totalBajas = $totalMuertos + $totalDevueltos;
    $cantidadLlegada = $datosRecepcion['cantidad_animales'] ?? ($totalRecibidos + $totalBajas);
    
    $contenido  = "===============================================\n";
    $contenido .= "  GANADERIA EL ROSARIO\n";
    $contenido .= "  ACTA DE RECEPCION\n";
    $contenido .= "===============================================\n\n";
    
    $contenido .= "Folio: {$folio}\n";
    $contenido .= "Fecha: " . date('d/m/Y H:i') . "\n";
    $contenido .= "Proveedor: " . ($datosRecepcion['proveedor_nombre'] ?? 'N/A') . "\n";
    $contenido .= "Transporte: " . ($datosRecepcion['transporte'] ?? 'N/A') . "\n";
    $contenido .= "Placas: " . ($datosRecepcion['placas'] ?? 'N/A') . "\n";
    $contenido .= "Chofer: " . ($datosRecepcion['chofer'] ?? 'N/A') . "\n\n";
    
    $contenido .= "===============================================\n";
    $contenido .= "  RESUMEN DE RECEPCION\n";
    $contenido .= "===============================================\n\n";
    $contenido .= sprintf("%-40s %d\n", "Total de animales que llegaron:", $cantidadLlegada);
    $contenido .= sprintf("%-40s %d\n", "Bajas registradas:", $totalBajas);
    $contenido .= sprintf("%-40s %d\n", "  - Muertes:", $totalMuertos);
    $contenido .= sprintf("%-40s %d\n", "  - Devoluciones:", $totalDevueltos);
    $contenido .= sprintf("%-40s %d\n", "TOTAL RECIBIDO:", $totalRecibidos);
    $contenido .= "\n";
    $contenido .= sprintf("%-40s %d\n", "Machos:", $machos);
    $contenido .= sprintf("%-40s %d\n", "Hembras:", $hembras);
    $contenido .= sprintf("%-40s %d\n", "En hospital:", $enHospital);
    $contenido .= "\n";
    $contenido .= sprintf("%-40s %.2f kg\n", "Peso total recibido:", $pesoTotal);
    $contenido .= sprintf("%-40s %.2f kg\n", "Peso promedio:", $totalRecibidos > 0 ? $pesoTotal / $totalRecibidos : 0);
    $contenido .= sprintf("%-40s $%.2f MXN\n", "Valor estimado:", $valorTotal);
    $contenido .= "\n";
    
    // Detalle de muertos
    if (count($bovinosMuertos) > 0) {
        $contenido .= "===============================================\n";
        $contenido .= "  BOVINOS MUERTOS\n";
        $contenido .= "===============================================\n\n";
        foreach ($bovinosMuertos as $b) {
            $contenido .= sprintf("SINIIGA: %s\n", $b['id_siniiga']);
            $contenido .= sprintf("  Sexo: %s | Peso: %.2f kg | Raza: %s\n", 
                $b['sexo'], $b['peso_entrada'], $b['raza'] ?? 'N/A');
            $contenido .= sprintf("  Motivo: %s\n", $b['motivo_baja'] ?? 'No especificado');
            if (!empty($b['observaciones_baja'])) {
                $contenido .= sprintf("  Observaciones: %s\n", $b['observaciones_baja']);
            }
            $contenido .= "\n";
        }
    }
    
    // Detalle de devueltos
    if (count($bovinosDevueltos) > 0) {
        $contenido .= "===============================================\n";
        $contenido .= "  BOVINOS DEVUELTOS\n";
        $contenido .= "===============================================\n\n";
        foreach ($bovinosDevueltos as $b) {
            $contenido .= sprintf("SINIIGA: %s\n", $b['id_siniiga']);
            $contenido .= sprintf("  Sexo: %s | Peso: %.2f kg | Raza: %s\n", 
                $b['sexo'], $b['peso_entrada'], $b['raza'] ?? 'N/A');
            $contenido .= sprintf("  Motivo: %s\n", $b['motivo_baja'] ?? 'No especificado');
            if (!empty($b['observaciones_baja'])) {
                $contenido .= sprintf("  Observaciones: %s\n", $b['observaciones_baja']);
            }
            $contenido .= "\n";
        }
    }
    
    // Detalle de bovinos vivos
    $contenido .= "===============================================\n";
    $contenido .= "  DETALLE DE BOVINOS RECIBIDOS (VIVOS)\n";
    $contenido .= "===============================================\n\n";
    $contenido .= sprintf("%-15s %-4s %-10s %-12s %-8s %-10s\n", 
        "SINIIGA", "SEXO", "RAZA", "CATEGORIA", "PESO", "ESTADO");
    $contenido .= str_repeat("-", 75) . "\n";
    
    foreach ($bovinosVivos as $b) {
        $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
        $estado = !empty($b['en_hospital']) ? 'HOSPITAL' : 'SANO';
        $contenido .= sprintf("%-15s %-4s %-10s %-12s %-8.2f %-10s\n",
            $b['id_siniiga'],
            $b['sexo'],
            substr($b['raza'] ?? 'N/A', 0, 10),
            $cat['subcategoria'],
            $b['peso_entrada'],
            $estado);
    }
    
    $contenido .= str_repeat("-", 75) . "\n";
    $contenido .= sprintf("TOTAL: %d animales | Peso: %.2f kg | Valor: $%.2f MXN\n\n", 
        $totalRecibidos, $pesoTotal, $valorTotal);
    $contenido .= "Contacto: 657-136-32-83\n";
    $contenido .= "===============================================\n";
    
    file_put_contents($ruta, $contenido);
    
    return [
        'ruta' => $ruta,
        'nombre' => $nombre,
        'total_animales' => $totalRecibidos,
        'total_bajas' => $totalBajas,
        'total_muertos' => $totalMuertos,
        'total_devueltos' => $totalDevueltos,
        'peso_total' => $pesoTotal,
        'valor_total' => $valorTotal
    ];
}
 
/**
 * Genera archivo CSV para Excel
 */
function generarArchivoCSV($recepcion_id, $bovinos, $datosRecepcion) {
    $dir = BASE_PATH . '/uploads/archivos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $folio = $datosRecepcion['folio'] ?? 'LOTE-' . $recepcion_id;
    $fecha = date('Ymd-His');
    $nombre = "lote_{$folio}_{$fecha}.csv";
    $ruta = $dir . $nombre;
    
    $fp = fopen($ruta, 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($fp, [
        'SINIIGA', 'Sexo', 'Raza', 'Color', 'Edad (meses)',
        'Peso (kg)', 'Categoria', 'Subcategoria',
        'Precio/kg', 'Subtotal'
    ]);
    
    foreach ($bovinos as $b) {
        $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
        fputcsv($fp, [
            $b['id_siniiga'],
            $b['sexo'] === 'M' ? 'Macho' : 'Hembra',
            $b['raza'] ?? '',
            $b['color'] ?? '',
            $b['edad_meses'] ?? '',
            $b['peso_entrada'],
            $cat['categoria'],
            $cat['subcategoria'],
            $cat['precio_kg'],
            $cat['precio_total']
        ]);
    }
    
    fclose($fp);
    
    return [
        'ruta' => $ruta,
        'nombre' => $nombre
    ];
}
