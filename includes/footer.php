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

<!-- Universal Self PIN Reset Modal -->
<div class="modal fade" id="selfPinModal" tabindex="-1" aria-labelledby="selfPinModalLabel" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
    <div class="modal-content" style="border-radius: 12px; border: 1px solid var(--bd); box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
      <div class="modal-header py-3 bg-light" style="border-bottom: 1px solid var(--bd);">
        <h6 class="modal-title fw-bold" id="selfPinModalLabel" style="color: var(--nv);">
          <i class="fa-solid fa-key me-2 text-warning"></i> Reset My Secret PIN (اپنا پن تبدیل کریں)
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="selfPinForm" onsubmit="submitSelfPinReset(event)">
        <div class="modal-body p-4">
          <div id="selfPinAlert" class="alert d-none mb-3" style="font-size: 13px;"></div>
          
          <div class="mb-3">
            <label class="form-label fw-bold" style="font-size: 13px;">Current Secret PIN (موجودہ پن کوڈ) <span class="text-danger">*</span></label>
            <input type="password" id="current_pin" maxlength="6" class="form-control text-center" required placeholder="••••" style="letter-spacing: 3px;" autocomplete="current-password">
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold" style="font-size: 13px;">New Secret PIN (نیا پن کوڈ) <span class="text-danger">*</span></label>
            <input type="password" id="new_pin" maxlength="6" class="form-control text-center" required placeholder="••••" style="letter-spacing: 3px;" autocomplete="new-password">
            <small class="text-muted" style="font-size: 11.5px;">Must be at least 4 digits</small>
          </div>

          <div class="mb-2">
            <label class="form-label fw-bold" style="font-size: 13px;">Confirm New PIN (نیا پن کوڈ دوبارہ درج کریں) <span class="text-danger">*</span></label>
            <input type="password" id="confirm_pin" maxlength="6" class="form-control text-center" required placeholder="••••" style="letter-spacing: 3px;" autocomplete="new-password">
          </div>
        </div>
        <div class="modal-footer py-2 bg-light d-flex justify-content-between">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="btnSaveSelfPin" class="btn btn-sm btn-zb-primary px-3">
            <i class="fa-solid fa-check me-1"></i> Update My PIN (محفوظ کریں)
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let _selfPinModalInstance = null;
function openSelfPinModal() {
  const el = document.getElementById('selfPinModal');
  if (!el) return;
  document.getElementById('selfPinForm').reset();
  const alertEl = document.getElementById('selfPinAlert');
  alertEl.className = 'alert d-none mb-3';
  alertEl.innerText = '';
  
  if (!_selfPinModalInstance && window.bootstrap) {
    _selfPinModalInstance = new bootstrap.Modal(el);
  }
  if (_selfPinModalInstance) {
    _selfPinModalInstance.show();
    setTimeout(() => { document.getElementById('current_pin')?.focus(); }, 300);
  }
}

async function submitSelfPinReset(e) {
  e.preventDefault();
  const curPin = document.getElementById('current_pin').value.trim();
  const newPin = document.getElementById('new_pin').value.trim();
  const cnfPin = document.getElementById('confirm_pin').value.trim();
  const alertEl = document.getElementById('selfPinAlert');
  const btn = document.getElementById('btnSaveSelfPin');

  alertEl.className = 'alert d-none mb-3';
  alertEl.innerText = '';

  if (newPin.length < 4) {
    alertEl.className = 'alert alert-danger mb-3';
    alertEl.innerText = 'New PIN must be at least 4 digits long.';
    return;
  }
  if (newPin !== cnfPin) {
    alertEl.className = 'alert alert-danger mb-3';
    alertEl.innerText = 'New PIN and confirmation PIN do not match.';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

  try {
    const fd = new FormData();
    fd.append('current_pin', curPin);
    fd.append('new_pin', newPin);
    fd.append('confirm_pin', cnfPin);

    const res = await fetch('ajax/auth.php?action=change_own_pin', {
      method: 'POST',
      body: fd
    });
    const json = await res.json();

    if (json.success) {
      alertEl.className = 'alert alert-success mb-3';
      alertEl.innerText = json.message || 'PIN updated successfully!';
      setTimeout(() => {
        if (_selfPinModalInstance) _selfPinModalInstance.hide();
      }, 1500);
    } else {
      alertEl.className = 'alert alert-danger mb-3';
      alertEl.innerText = json.message || 'Failed to update PIN.';
    }
  } catch (err) {
    alertEl.className = 'alert alert-danger mb-3';
    alertEl.innerText = 'Network error. Please try again.';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Update My PIN (محفوظ کریں)';
  }
}
</script>
</body>
</html>
