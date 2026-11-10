/**
 * Busherian Biasharas — Operating Hours (Mon–Sun) & Client-Side Photo Gallery Optimizer
 */
(function () {
  'use strict';

  function bindDayClosedToggle(checkboxId, openId, closeId, defaultOpen, defaultClose) {
    const chk = document.getElementById(checkboxId);
    const openEl = document.getElementById(openId);
    const closeEl = document.getElementById(closeId);
    if (!chk || !openEl || !closeEl) return;

    function sync() {
      if (chk.checked) {
        openEl.disabled = true;
        closeEl.disabled = true;
      } else {
        openEl.disabled = false;
        closeEl.disabled = false;
        if (!openEl.value) openEl.value = defaultOpen;
        if (!closeEl.value) closeEl.value = defaultClose;
      }
    }
    chk.addEventListener('change', sync);
    sync();
  }

  bindDayClosedToggle('hours_sat_closed', 'hours_sat_open', 'hours_sat_close', '09:00', '14:00');
  bindDayClosedToggle('hours_sun_closed', 'hours_sun_open', 'hours_sun_close', '10:00', '16:00');

  const fileInput = document.getElementById('business_photos');
  const hiddenJsonInput = document.getElementById('business_photos_data_json');
  const previewWrap = document.getElementById('business-photos-preview');
  const statusEl = document.getElementById('business-photos-status');
  const badgeEl = document.getElementById('photo-count-badge');

  if (!fileInput || !hiddenJsonInput || !previewWrap) return;

  const MAX_PHOTOS = 5;
  const MAX_DIM = 1200;
  let queuedDataUrls = [];

  function countKeptExistingPhotos() {
    const items = document.querySelectorAll('.existing-photo-item');
    let kept = 0;
    items.forEach(item => {
      const rm = item.querySelector('.remove-photo-checkbox');
      if (!rm || !rm.checked) kept++;
    });
    return kept;
  }

  function updateSummaryAndHiddenField() {
    const keptExisting = countKeptExistingPhotos();
    const allowedNew = Math.max(0, MAX_PHOTOS - keptExisting);
    if (queuedDataUrls.length > allowedNew) {
      queuedDataUrls = queuedDataUrls.slice(0, allowedNew);
    }
    hiddenJsonInput.value = queuedDataUrls.length > 0 ? JSON.stringify(queuedDataUrls) : '';

    const total = keptExisting + queuedDataUrls.length;
    if (badgeEl) {
      badgeEl.textContent = `${total} / ${MAX_PHOTOS} photos selected (3–5 recommended)`;
    }

    previewWrap.innerHTML = '';
    queuedDataUrls.forEach((dataUrl, idx) => {
      const card = document.createElement('div');
      card.style.cssText = 'border:1px solid #059669;border-radius:10px;padding:0.4rem;background:#fff;width:130px;text-align:center;position:relative;';
      const img = document.createElement('img');
      img.src = dataUrl;
      img.alt = `New Photo ${idx + 1}`;
      img.style.cssText = 'width:100%;height:82px;object-fit:cover;border-radius:6px;display:block;margin-bottom:0.35rem;';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = '✕ Remove';
      btn.style.cssText = 'font-size:0.72rem;color:#991B1B;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:6px;padding:0.15rem 0.45rem;cursor:pointer;font-weight:700;';
      btn.addEventListener('click', () => {
        queuedDataUrls.splice(idx, 1);
        updateSummaryAndHiddenField();
      });
      card.appendChild(img);
      card.appendChild(btn);
      previewWrap.appendChild(card);
    });
  }

  document.querySelectorAll('.remove-photo-checkbox').forEach(chk => {
    chk.addEventListener('change', updateSummaryAndHiddenField);
  });

  function resizeFileToDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('Could not read image file'));
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error('Invalid image format'));
        img.onload = () => {
          let w = img.naturalWidth || img.width;
          let h = img.naturalHeight || img.height;
          if (w > MAX_DIM || h > MAX_DIM) {
            const ratio = Math.min(MAX_DIM / w, MAX_DIM / h);
            w = Math.max(1, Math.round(w * ratio));
            h = Math.max(1, Math.round(h * ratio));
          }
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(0, 0, w, h);
          ctx.drawImage(img, 0, 0, w, h);
          resolve(canvas.toDataURL('image/jpeg', 0.84));
        };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }

  fileInput.addEventListener('change', async () => {
    const files = Array.from(fileInput.files || []);
    if (files.length === 0) return;

    const keptExisting = countKeptExistingPhotos();
    const remainingSlots = Math.max(0, MAX_PHOTOS - keptExisting - queuedDataUrls.length);

    if (remainingSlots <= 0) {
      if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#B45309';
        statusEl.textContent = `Maximum of ${MAX_PHOTOS} photos reached. Remove a photo first if you wish to replace it.`;
      }
      fileInput.value = '';
      return;
    }

    const slice = files.slice(0, remainingSlots);
    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.style.color = '#065F46';
      statusEl.textContent = `Optimizing ${slice.length} photo(s) for fast upload...`;
    }

    for (const f of slice) {
      try {
        const dataUrl = await resizeFileToDataUrl(f);
        if (dataUrl && queuedDataUrls.length + keptExisting < MAX_PHOTOS) {
          queuedDataUrls.push(dataUrl);
        }
      } catch (_) {}
    }

    fileInput.value = '';
    updateSummaryAndHiddenField();

    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.style.color = '#065F46';
      statusEl.textContent = files.length > remainingSlots
        ? `Added ${slice.length} photo(s) (capped at ${MAX_PHOTOS} photos maximum).`
        : `✓ ${queuedDataUrls.length} new photo(s) optimized and ready to save.`;
    }
  });

  updateSummaryAndHiddenField();
})();
