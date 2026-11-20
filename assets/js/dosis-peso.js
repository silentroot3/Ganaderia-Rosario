/**
 * Dosis segun el peso del bovino.
 * - <select name="medicamento_id"> con options data-ml (ml por dosis) y data-cadakg (cada cuantos kg; vacio = dosis fija)
 * - <input name="dosis">: se llena sola mientras el veterinario no la cambie a mano
 * - Peso: #pesoBovino (value) o el arete escrito en input[name=arete] buscado en window.PESOS_BOVINOS {arete: kg}
 * - Muestra el calculo en #dosisCalc
 * Se redondea al medio ml de arriba.
 */
(function () {
    var med = document.querySelector('select[name=medicamento_id]');
    var dosis = document.querySelector('input[name=dosis]');
    var info = document.getElementById('dosisCalc');
    var arete = document.querySelector('input[name=arete]');
    var pesoFijo = document.getElementById('pesoBovino');
    if (!med || !dosis) return;
    var manual = dosis.value !== '';
    dosis.addEventListener('input', function () { manual = dosis.value !== ''; });

    function peso() {
        if (pesoFijo) return parseFloat(pesoFijo.value) || 0;
        if (arete && window.PESOS_BOVINOS) return window.PESOS_BOVINOS[arete.value.trim()] || 0;
        return 0;
    }
    function fmt(n) { return n.toLocaleString('es-MX', {maximumFractionDigits: 1}); }
    function calc() {
        var o = med.options[med.selectedIndex];
        if (!o || !o.value || !o.dataset.ml) { if (info) info.textContent = ''; return; }
        var ml = parseFloat(o.dataset.ml), cada = parseFloat(o.dataset.cadakg) || 0, kg = peso(), d, txt;
        if (cada > 0) {
            if (!kg) { if (info) info.textContent = 'Dosis por peso: ' + fmt(ml) + ' ml cada ' + fmt(cada) + ' kg. Escribe el arete para calcularla.'; return; }
            d = Math.ceil(kg * ml / cada * 2) / 2;
            txt = fmt(kg) + ' kg x ' + fmt(ml) + ' ml cada ' + fmt(cada) + ' kg = ' + fmt(d) + ' ml por dosis';
        } else {
            d = ml;
            txt = 'Dosis fija: ' + fmt(ml) + ' ml (no depende del peso)';
        }
        if (info) info.textContent = txt + (manual && parseFloat(dosis.value) !== d ? '  (escribiste otra cantidad)' : '');
        if (!manual) { dosis.value = d; dosis.dispatchEvent(new Event('input')); manual = false; }
    }
    med.addEventListener('change', function () { manual = false; calc(); });
    if (arete) { arete.addEventListener('input', calc); arete.addEventListener('change', calc); }
    calc();
})();
