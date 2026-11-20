<?php
// ============================================
// TRAZABILIDAD - Buscador por arete SINIIGA o por UPP del rancho de origen (HU-016)
// ============================================

$titulo = 'Trazabilidad';
$modulo = 'trazabilidad';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$q = trim($_GET['q'] ?? '');
$resultados = [];
$porUpp = false;

if ($q !== '') {
    // Primero UPP exacta (todos los animales que vinieron de ese rancho), si no, arete parecido
    $sqlBase = "
        SELECT b.id, b.id_siniiga, b.sexo, b.categoria, b.estado, c.nombre AS corral,
               COALESCE(comp.origen_upp, p.upp) AS upp, p.nombre AS rancho, comp.folio AS compra, DATE(r.fecha_recepcion) AS llego
        FROM bovinos b
        LEFT JOIN compras comp ON comp.id = b.compra_id
        LEFT JOIN recepciones r ON r.id = b.recepcion_id
        LEFT JOIN proveedores p ON p.id = COALESCE(comp.proveedor_id, r.proveedor_id)
        LEFT JOIN corrales_todos c ON c.id = b.corral_id
        WHERE %s
        ORDER BY r.fecha_recepcion DESC, b.id_siniiga LIMIT 200
    ";
    $stmt = $db->prepare(sprintf($sqlBase, 'comp.origen_upp = ? OR p.upp = ?'));
    $stmt->execute([$q, $q]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $porUpp = (bool)$resultados;
    if (!$resultados) {
        $stmt = $db->prepare(sprintf($sqlBase, 'b.id_siniiga LIKE ?'));
        $stmt->execute(["%$q%"]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($resultados) === 1) {
            header('Location: ' . BASE_URL . '/trazabilidad/ver?arete=' . urlencode($resultados[0]['id_siniiga']));
            exit;
        }
    }
}
?>
<div class="table-container" style="max-width:900px;margin:0 auto 25px;">
    <div class="table-header"><h2>Hoja de vida del animal</h2></div>
    <form method="GET" style="padding:25px;display:flex;gap:10px;flex-wrap:wrap;">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Arete (12 digitos) o UPP del rancho" autofocus
               style="flex:1;min-width:200px;padding:12px;border:1px solid var(--linea);border-radius:6px;font-size:1.1rem;">
        <button class="btn btn-primary" type="submit">Buscar</button>
    </form>
</div>

<?php if ($q !== ''): ?>
<div class="table-container" style="max-width:900px;margin:0 auto;">
    <?php if ($resultados): ?>
    <?php if ($porUpp): ?><div class="table-header"><h2>UPP <?= h($q) ?> &middot; <?= h($resultados[0]['rancho'] ?? '') ?> (<?= count($resultados) ?> animales)</h2></div><?php endif; ?>
    <div style="overflow-x:auto;"><table><thead><tr><th>Arete</th><th>Sexo</th><th>Categoria</th><th>Estado</th><th>Corral</th><th>Rancho (UPP)</th><th>Llego</th><th></th></tr></thead><tbody>
    <?php foreach ($resultados as $r): ?>
        <tr><td><strong><?= h($r['id_siniiga']) ?></strong></td><td><?= $r['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td><td><?= h($r['categoria']) ?></td><td><?= pillEstado($r['estado']) ?></td>
            <td><?= h($r['corral'] ?? '-') ?></td><td><?= h($r['rancho'] ?? '-') ?><?= $r['upp'] ? '<br><small style="color:var(--tenue);">' . h($r['upp']) . '</small>' : '' ?></td>
            <td><?= $r['llego'] ? fechaCorta($r['llego']) : '-' ?></td>
            <td><a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($r['id_siniiga']) ?>">Ver hoja de vida</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><div style="padding:30px;text-align:center;color:var(--tenue);">No se encontro ningun arete ni UPP con "<?= h($q) ?>".</div><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
