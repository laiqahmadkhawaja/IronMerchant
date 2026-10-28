<?php
// includes/footer.php
declare(strict_types=1);
?>
    </main>
  </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Live Clock Script -->
<script>
  setInterval(() => {
    const el = document.getElementById('liveClock');
    if (el) {
      const now = new Date();
      el.innerHTML = `<b>${now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</b><span>${now.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })}</span>`;
    }
  }, 1000);
</script>

</body>
</html>
