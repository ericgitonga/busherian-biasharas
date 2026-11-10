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

  // =========================================================================
  // 2-Level Place Selector (Country -> City / County / State) (#97)
  // =========================================================================
  const countrySel = document.getElementById('country');
  const countryCustom = document.getElementById('country_custom');
  const citySel = document.getElementById('city_county');
  const cityCustom = document.getElementById('city_county_custom');
  const placeWrap = countrySel ? countrySel.closest('[data-place-hierarchy]') : null;

  if (countrySel && citySel && placeWrap) {
    let hierarchy = {};
    try {
      hierarchy = JSON.parse(placeWrap.getAttribute('data-place-hierarchy') || '{}');
    } catch (_) {}

    function syncCityOptionsForCountry(preferredCity) {
      const cVal = countrySel.value;
      const isCustomCountry = (cVal === '__custom__');
      if (countryCustom) {
        countryCustom.style.display = isCustomCountry ? 'block' : 'none';
        countryCustom.required = isCustomCountry;
      }

      const list = (!isCustomCountry && Array.isArray(hierarchy[cVal])) ? hierarchy[cVal] : [];
      citySel.innerHTML = '';
      list.forEach(placeName => {
        const opt = document.createElement('option');
        opt.value = placeName;
        opt.textContent = placeName;
        citySel.appendChild(opt);
      });
      const customOpt = document.createElement('option');
      customOpt.value = '__custom__';
      customOpt.textContent = '+ Other City / County / State (Type Below)';
      citySel.appendChild(customOpt);

      if (isCustomCountry) {
        citySel.value = '__custom__';
      } else if (preferredCity && list.includes(preferredCity)) {
        citySel.value = preferredCity;
      } else if (preferredCity && preferredCity !== '__custom__') {
        citySel.value = '__custom__';
        if (cityCustom) cityCustom.value = preferredCity;
      } else if (list.length > 0) {
        citySel.value = list[0];
      } else {
        citySel.value = '__custom__';
      }
      syncCustomCityVisibility();
    }

    function syncCustomCityVisibility() {
      const isCustomCity = (citySel.value === '__custom__');
      if (cityCustom) {
        cityCustom.style.display = isCustomCity ? 'block' : 'none';
        cityCustom.required = isCustomCity;
      }
    }

    countrySel.addEventListener('change', () => {
      syncCityOptionsForCountry(null);
    });
    citySel.addEventListener('change', syncCustomCityVisibility);
  }

  // =========================================================================
  // "Same as Direct Phone above" Checkboxes for Public Phone & WhatsApp (#115)
  // =========================================================================
  const directPhoneInput = document.getElementById('submitter_phone');
  const primaryPhoneInput = document.getElementById('phone_primary');
  const whatsappPhoneInput = document.getElementById('whatsapp_number');
  const samePrimaryChk = document.getElementById('same_as_direct_phone_primary');
  const sameWhatsappChk = document.getElementById('same_as_direct_phone_whatsapp');

  function bindSameAsDirectPhone(chkEl, targetInput) {
    if (!chkEl || !targetInput || !directPhoneInput) return;
    function syncFromDirect() {
      if (chkEl.checked) {
        targetInput.value = directPhoneInput.value;
      }
    }
    chkEl.addEventListener('change', () => {
      if (chkEl.checked) {
        targetInput.value = directPhoneInput.value;
        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    directPhoneInput.addEventListener('input', () => {
      if (chkEl.checked) {
        targetInput.value = directPhoneInput.value;
      }
    });
    targetInput.addEventListener('input', () => {
      if (chkEl.checked && targetInput.value.trim() !== directPhoneInput.value.trim()) {
        chkEl.checked = false;
      }
    });
    syncFromDirect();
  }

  bindSameAsDirectPhone(samePrimaryChk, primaryPhoneInput);
  bindSameAsDirectPhone(sameWhatsappChk, whatsappPhoneInput);

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
  // Keep photo hidden JSON synced with draft storage
  if (hiddenJsonInput) {
    const origUpdate = updateSummaryAndHiddenField;
    updateSummaryAndHiddenField = function () {
      origUpdate();
      if (typeof scheduleSaveDraft === 'function') scheduleSaveDraft();
    };
  }

  // =========================================================================
  // Registration Form Draft Auto-Save & Restoration (#95)
  // =========================================================================
  const DRAFT_KEY = 'bbiz_submit_draft_v1';
  const submitForm = document.getElementById('business-submit-form');
  const restoredBanner = document.getElementById('draft-restored-banner');
  const clearDraftBtn = document.getElementById('btn-clear-draft');
  const autosaveIndicator = document.getElementById('draft-autosave-indicator');

  const EXCLUDED_DRAFT_NAMES = new Set([
    'csrf_token',
    'form_ts',
    'form_nonce',
    'form_sig',
    'js_interaction_token',
    'website',
    'business_photos[]'
  ]);

  if (window.BBIZ_SUBMIT_SUCCESS === true) {
    try { localStorage.removeItem(DRAFT_KEY); } catch (_) {}
  }

  function collectSubmitDraft() {
    if (!submitForm) return null;
    const data = {};
    const elements = Array.from(submitForm.elements || []);
    elements.forEach(el => {
      const name = el.name;
      if (!name || EXCLUDED_DRAFT_NAMES.has(name)) return;
      if (el.type === 'file' || el.type === 'submit' || el.type === 'button') return;
      if (el.type === 'checkbox') {
        data[name] = el.checked ? (el.value || '1') : '__unchecked__';
      } else if (el.type === 'radio') {
        if (el.checked) data[name] = el.value;
      } else {
        data[name] = el.value;
      }
    });
    return data;
  }

  function hasMeaningfulDraft(draft) {
    if (!draft || typeof draft !== 'object') return false;
    const checkKeys = [
      'name', 'tagline', 'description', 'submitter_name', 'submitter_email',
      'submitter_phone', 'year_left', 'school_number', 'phone_primary',
      'whatsapp_number', 'email_public', 'website_url', 'building_name',
      'street_road', 'area_neighborhood', 'alumni_perk', 'regulator_license',
      'business_photos_data_json'
    ];
    return checkKeys.some(k => typeof draft[k] === 'string' && draft[k].trim() !== '');
  }

  let saveTimer = null;
  function saveSubmitDraft() {
    if (!submitForm || window.BBIZ_SUBMIT_SUCCESS === true) return;
    const draft = collectSubmitDraft();
    if (!draft) return;
    try {
      if (hasMeaningfulDraft(draft)) {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({
          saved_at: Date.now(),
          fields: draft
        }));
        if (autosaveIndicator) {
          const nowStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
          autosaveIndicator.textContent = `💾 Draft saved automatically at ${nowStr} on this device.`;
        }
      }
    } catch (_) {}
  }

  function scheduleSaveDraft() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveSubmitDraft, 220);
  }

  function applyDraftToSubmitForm(fields) {
    if (!submitForm || !fields || typeof fields !== 'object') return;

    // 1. Cascading school -> house
    const schoolSel = submitForm.querySelector('[name="school_code"]');
    const houseSel = submitForm.querySelector('[name="house_name"]');
    if (schoolSel && fields.school_code) {
      schoolSel.value = String(fields.school_code);
      schoolSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (houseSel && fields.house_name) {
      houseSel.value = String(fields.house_name);
    }

    // 1b. Cascading country -> city_county (#97)
    const countryField = submitForm.querySelector('[name="country"]');
    const cityField = submitForm.querySelector('[name="city_county"]');
    if (countryField && fields.country) {
      countryField.value = String(fields.country);
      countryField.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (cityField && fields.city_county) {
      cityField.value = String(fields.city_county);
      cityField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 2. Cascading sector -> specialty -> custom specialty & regulator
    const sectorSel = submitForm.querySelector('[name="sector_id"]');
    const specSel = submitForm.querySelector('[name="specialty_id"]');
    if (sectorSel && fields.sector_id) {
      sectorSel.value = String(fields.sector_id);
      sectorSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (specSel && fields.specialty_id) {
      specSel.value = String(fields.specialty_id);
      specSel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // 3. Populate remaining inputs, selects, textareas, checkboxes
    const elements = Array.from(submitForm.elements || []);
    elements.forEach(el => {
      const name = el.name;
      if (!name || EXCLUDED_DRAFT_NAMES.has(name)) return;
      if (name === 'school_code' || name === 'house_name' || name === 'sector_id' || name === 'specialty_id' || name === 'country' || name === 'city_county') return;
      if (!(name in fields)) return;
      const val = fields[name];

      if (el.type === 'checkbox') {
        const shouldCheck = val !== '__unchecked__' && val !== '' && val !== null && val !== false && val !== '0';
        if (el.checked !== shouldCheck) {
          el.checked = shouldCheck;
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } else if (el.type === 'radio') {
        el.checked = (String(el.value) === String(val));
      } else if (el.type !== 'file' && el.type !== 'submit' && el.type !== 'button') {
        if (val !== null && val !== undefined) {
          el.value = String(val);
        }
      }
    });

    // 4. Restore queued client-resized photos if present
    if (typeof fields.business_photos_data_json === 'string' && fields.business_photos_data_json.trim() !== '') {
      try {
        const parsedPhotos = JSON.parse(fields.business_photos_data_json);
        if (Array.isArray(parsedPhotos) && parsedPhotos.length > 0) {
          queuedDataUrls = parsedPhotos.slice(0, MAX_PHOTOS);
          updateSummaryAndHiddenField();
        }
      } catch (_) {}
    }
  }

  if (submitForm && window.BBIZ_SUBMIT_SUCCESS !== true) {
    let restoredFields = null;
    if (window.BBIZ_SUBMIT_POST_DRAFT && typeof window.BBIZ_SUBMIT_POST_DRAFT === 'object') {
      restoredFields = window.BBIZ_SUBMIT_POST_DRAFT;
    } else {
      try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed && parsed.fields && hasMeaningfulDraft(parsed.fields)) {
            restoredFields = parsed.fields;
          }
        }
      } catch (_) {}
    }

    if (restoredFields) {
      applyDraftToSubmitForm(restoredFields);
      if (restoredBanner) {
        restoredBanner.style.display = 'flex';
      }
      saveSubmitDraft();
    }

    submitForm.addEventListener('input', scheduleSaveDraft);
    submitForm.addEventListener('change', scheduleSaveDraft);

    if (clearDraftBtn) {
      clearDraftBtn.addEventListener('click', () => {
        try { localStorage.removeItem(DRAFT_KEY); } catch (_) {}
        submitForm.reset();
        queuedDataUrls = [];
        updateSummaryAndHiddenField();
        const schoolSel = submitForm.querySelector('[name="school_code"]');
        if (schoolSel) schoolSel.dispatchEvent(new Event('change', { bubbles: true }));
        const sectorSel = submitForm.querySelector('[name="sector_id"]');
        if (sectorSel) sectorSel.dispatchEvent(new Event('change', { bubbles: true }));
        bindDayClosedToggle('hours_sat_closed', 'hours_sat_open', 'hours_sat_close', '09:00', '14:00');
        bindDayClosedToggle('hours_sun_closed', 'hours_sun_open', 'hours_sun_close', '10:00', '16:00');
        if (restoredBanner) restoredBanner.style.display = 'none';
        if (autosaveIndicator) {
          autosaveIndicator.textContent = '💾 Draft cleared — starting with a fresh form.';
        }
      });
    }
  }
})();
