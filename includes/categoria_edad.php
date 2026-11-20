<?php
/**
 * Categoria del bovino a partir de sexo, edad (meses) y peso, sin depender de la BD.
 * La compra ya no captura "clasificacion": se deduce aqui y el clasificador la confirma.
 *
 *  Hembras: Becerra (<12 m) | Vaquilla (12-35 m) | Vaca (36+ m)
 *  Machos:  Becerro (<12 m) | Torete (12-35 m)   | Toro (36+ m)
 *  Sin edad capturada se usa el peso (<200 kg becerro/a; hembras <400 vaquilla; machos <450 torete).
 *  Valores del ENUM bovinos.categoria. "Novillo" (castrado) solo se asigna al clasificar.
 */
function categoriaPorEdadPeso($sexo, $meses, $peso) {
    $meses = $meses !== null && $meses !== '' ? (int)$meses : null;
    $peso = (float)$peso;
    $macho = in_array($sexo, ['M', 'MACHO'], true);

    if ($meses !== null && $meses > 0) {
        if ($meses < 12) return $macho ? 'Becerro' : 'Becerra';
        if ($meses < 36) return $macho ? 'Torete' : 'Vaquilla';
        return $macho ? 'Toro' : 'Vaca';
    }
    if ($peso > 0) {
        if ($peso < 200) return $macho ? 'Becerro' : 'Becerra';
        if ($peso < ($macho ? 450 : 400)) return $macho ? 'Torete' : 'Vaquilla';
        return $macho ? 'Toro' : 'Vaca';
    }
    return 'Sin clasificar';
}
