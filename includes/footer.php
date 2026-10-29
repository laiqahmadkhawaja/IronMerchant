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
  function updateLiveClock() {
    const timeEl = document.getElementById('liveClockTime');
    const dateEl = document.getElementById('liveClockDate');
    const legacyEl = document.getElementById('liveClock');
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const dateStr = now.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });

    if (timeEl) {
      timeEl.innerText = timeStr;
    }
    if (dateEl) {
      dateEl.innerText = dateStr;
    }
    if (!timeEl && legacyEl) {
      legacyEl.innerHTML = `<b>${timeStr}</b><span>${dateStr}</span>`;
    }
  }
  setInterval(updateLiveClock, 1000);
</script>

</body>
</html>
