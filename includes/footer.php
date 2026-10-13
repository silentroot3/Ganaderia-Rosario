            </div> <!-- /.content -->
        </main>
    </div> <!-- /.app-container -->
    
    <script>
    // Toggle sidebar en móvil
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('sidebar-open');
    }
    
    // Auto-cerrar alertas después de 5 segundos
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
    </script>
    
    <?php if (isset($scripts_extra)): ?>
        <?php foreach ($scripts_extra as $script): ?>
            <script src="<?= BASE_URL ?>/assets/js/<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
