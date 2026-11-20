            </div> <!-- /.content -->
        </main>
    </div> <!-- /.app-container -->

    <script>
    // Menu lateral en celular / pantallas chicas
    function toggleSidebar() {
        var s = document.querySelector('.sidebar'), v = document.querySelector('.sidebar-velo');
        var abierto = s.classList.toggle('sidebar-open');
        if (v) v.classList.toggle('on', abierto);
    }

    (function () {
        // Modo oscuro / claro (se recuerda en este navegador)
        var bt = document.getElementById('btnTema');
        if (bt) bt.addEventListener('click', function () {
            var osc = document.documentElement.getAttribute('data-tema') !== 'oscuro';
            if (osc) document.documentElement.setAttribute('data-tema', 'oscuro'); else document.documentElement.removeAttribute('data-tema');
            try { localStorage.setItem('tema', osc ? 'oscuro' : 'claro'); } catch (e) {}
        });

        // Barra superior con linea al desplazarse
        var barra = document.querySelector('.top-bar');
        if (barra) {
            var marcar = function () { barra.classList.toggle('con-sombra', window.scrollY > 4); };
            window.addEventListener('scroll', marcar, {passive: true}); marcar();
        }

        // Avisos: los de exito se cierran solos; los de error se quedan hasta cerrarlos (x)
        document.querySelectorAll('.alert').forEach(function (al) {
            if (al.querySelector('.alert-cerrar')) return;
            var x = document.createElement('button');
            x.type = 'button'; x.className = 'alert-cerrar'; x.innerHTML = '&times;'; x.title = 'Cerrar';
            x.onclick = function () { al.style.opacity = '0'; setTimeout(function () { al.remove(); }, 300); };
            al.appendChild(x);
            if (al.classList.contains('alert-success')) setTimeout(x.onclick, 6000);
        });

        // Boton "procesando" al enviar: evita doble clic y muestra que esta trabajando
        document.addEventListener('submit', function (e) {
            var f = e.target, b = e.submitter || f.querySelector('button[type=submit], button:not([type])');
            if (e.defaultPrevented || f.target === '_blank' || f.method.toLowerCase() === 'get' || !b) return;
            setTimeout(function () { b.classList.add('cargando'); b.disabled = true; }, 0);
            setTimeout(function () { b.classList.remove('cargando'); b.disabled = false; }, 15000);
        });
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('.btn.cargando').forEach(function (b) { b.classList.remove('cargando'); b.disabled = false; });
        });

        // ---------- Captura de datos mas rapida ----------
        // 1) La rueda del mouse no cambia los numeros por accidente
        document.addEventListener('wheel', function (e) {
            if (e.target.type === 'number' && document.activeElement === e.target) e.target.blur();
        }, {passive: true});

        // 2) Enter pasa al siguiente campo en formularios de captura (en el ultimo, guarda).
        //    Ctrl+Enter guarda desde cualquier campo (tambien en textos largos).
        var esCampo = function (el) {
            return el && el.matches('input, select, textarea') && !el.disabled && !el.readOnly
                && el.type !== 'hidden' && el.offsetParent !== null;
        };
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.defaultPrevented || e.isComposing) return;
            var el = e.target, f = el.form;
            if (!f || !el.matches('input, select')) return;
            if (e.ctrlKey || e.metaKey) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); return; }
            if (f.method.toLowerCase() !== 'post' || f.dataset.enter === 'enviar') return;
            if (['checkbox', 'radio', 'submit', 'button', 'search', 'file'].indexOf(el.type) >= 0) return;
            var campos = Array.prototype.filter.call(f.elements, esCampo);
            if (campos.length < 3) return;
            var i = campos.indexOf(el);
            if (i < 0 || i === campos.length - 1) return;   // ultimo campo: Enter guarda
            e.preventDefault();
            var sig = campos[i + 1];
            sig.focus();
            if (sig.select && sig.matches('input:not([type=date]):not([type=checkbox]):not([type=radio])')) sig.select();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && e.target.matches && e.target.matches('textarea') && e.target.form) {
                e.preventDefault(); e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.submit();
            }
        });

        // 3) Asterisco de obligatorio en rojo
        document.querySelectorAll('label, .campo > span').forEach(function (l) {
            var t = l.lastChild;
            while (t && t.nodeType === 3 && !t.nodeValue.trim()) t = t.previousSibling;
            if (t && t.nodeType === 3 && /\*\s*$/.test(t.nodeValue)) {
                t.nodeValue = t.nodeValue.replace(/\s*\*\s*$/, ' ');
                var r = document.createElement('span'); r.className = 'req'; r.textContent = '*'; r.title = 'Obligatorio';
                l.insertBefore(r, t.nextSibling);
            }
        });

        // Numeros de las tarjetas: cuentan desde 0
        document.querySelectorAll('.stat-value').forEach(function (el) {
            var t = el.textContent.trim();
            if (!/^\d{1,6}$/.test(t)) return;
            var fin = parseInt(t, 10); if (fin < 2) return;
            var ini = performance.now(), dur = 450;
            (function paso(ahora) {
                var p = Math.min(1, (ahora - ini) / dur);
                el.textContent = Math.round(fin * (1 - Math.pow(1 - p, 3)));
                if (p < 1) requestAnimationFrame(paso);
            })(ini);
        });

        // Buscador rapido en tablas largas (no en formularios, para no ocultar filas seleccionadas)
        document.querySelectorAll('.table-container table').forEach(function (tb) {
            if (tb.closest('form') || tb.classList.contains('sin-filtro')) return;
            var filas = tb.querySelectorAll('tbody tr');
            if (filas.length < 12) return;
            var caja = document.createElement('div');
            caja.className = 'filtro-tabla';
            caja.innerHTML = '<input type="search" placeholder="Buscar en esta tabla..."><small>' + filas.length + ' filas</small>';
            var cont = tb.parentElement.classList.contains('table-container') ? tb : tb.parentElement;
            cont.parentElement.insertBefore(caja, cont);
            var inp = caja.querySelector('input'), info = caja.querySelector('small');
            inp.addEventListener('input', function () {
                var q = inp.value.toLowerCase().trim(), n = 0;
                filas.forEach(function (tr) { var ok = !q || tr.textContent.toLowerCase().indexOf(q) >= 0; tr.hidden = !ok; if (ok) n++; });
                info.textContent = q ? n + ' de ' + filas.length : filas.length + ' filas';
            });
        });
    })();
    </script>

    <?php if (isset($scripts_extra)): ?>
        <?php foreach ($scripts_extra as $script): ?>
            <script src="<?= BASE_URL ?>/assets/js/<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
