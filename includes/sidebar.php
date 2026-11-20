<?php
/**
 * Menu lateral: logo, modulos agrupados por seccion (segun permisos) y usuario.
 */
if (!isset($_SESSION['user_id'])) {
    return;
}
require_once __DIR__ . '/iconos.php';

$modulosVisibles = Permisos::modulosVisibles();
$rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$infoUsuario = [
    'nombre' => $_SESSION['nombre'] ?? 'Usuario',
    'apellido' => $_SESSION['apellido'] ?? '',
    'rol' => $_SESSION['rol'] ?? 'invitado',
];
$inicial = mb_strtoupper(mb_substr($infoUsuario['nombre'], 0, 1));
$nombresRol = ['admin' => 'Administrador', 'recepcionista' => 'Recepción', 'veterinario' => 'Veterinario',
               'clasificador' => 'Clasificador', 'alimentador' => 'Alimentador', 'vendedor' => 'Vendedor'];
$imgV = @filemtime(BASE_PATH . '/assets/img/logo-horizontal.png');
?>
<div class="sidebar-velo" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
    <a href="<?= BASE_URL ?>/dashboard" class="sidebar-brand" title="Ganadería El Rosario">
        <img src="<?= BASE_URL ?>/assets/img/logo-horizontal.png?v=<?= $imgV ?>" alt="Ganadería El Rosario" width="196" height="53">
    </a>

    <nav class="sidebar-nav">
        <?php $seccion = null;
        foreach ($modulosVisibles as $clave => $info):
            if (($info['seccion'] ?? '') !== $seccion):
                $seccion = $info['seccion'] ?? '';
                if ($seccion !== ''): ?><div class="nav-seccion"><?= htmlspecialchars($seccion) ?></div><?php endif;
            endif;
            $activo = false;
            foreach (array_merge([$info['url']], $info['alias'] ?? []) as $u) {
                if ($rutaActual === $u || str_starts_with($rutaActual, $u . '/')) $activo = true;
            } ?>
            <a href="<?= BASE_URL . $info['url'] ?>" class="nav-item<?= $activo ? ' active' : '' ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
                <?= icono($info['icono']) ?>
                <span class="nav-text"><?= htmlspecialchars($info['titulo']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-user">
        <div class="user-avatar rol-<?= htmlspecialchars($infoUsuario['rol']) ?>"><?= htmlspecialchars($inicial) ?></div>
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars(trim($infoUsuario['nombre'] . ' ' . $infoUsuario['apellido'])) ?></span>
            <span class="user-rol"><?= htmlspecialchars($nombresRol[$infoUsuario['rol']] ?? $infoUsuario['rol']) ?></span>
        </div>
        <a href="<?= BASE_URL ?>/logout" class="btn-salir" title="Cerrar sesión"><?= icono('salir', 18) ?></a>
    </div>
</aside>
<script>
// Recordar donde estaba la lista de modulos al cambiar de pagina
(function () {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) return;
    var k = 'sidebarScroll';
    try { var y = sessionStorage.getItem(k); if (y !== null) nav.scrollTop = +y; } catch (e) {}
    var act = nav.querySelector('.nav-item.active');
    if (act) {
        var top = act.offsetTop - nav.offsetTop, bottom = top + act.offsetHeight;
        if (top < nav.scrollTop || bottom > nav.scrollTop + nav.clientHeight) nav.scrollTop = top - nav.clientHeight / 2 + act.offsetHeight / 2;
    }
    var guardar = function () { try { sessionStorage.setItem(k, nav.scrollTop); } catch (e) {} };
    nav.addEventListener('scroll', guardar, {passive: true});
    nav.addEventListener('click', guardar);
})();
</script>
