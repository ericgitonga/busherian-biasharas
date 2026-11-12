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
  const cityLabel = document.getElementById('city_county_label');
  const cityCustom = document.getElementById('city_county_custom');
  const placeWrap = countrySel ? countrySel.closest('[data-place-hierarchy]') : null;

  if (countrySel && citySel && placeWrap) {
    let hierarchy = {};
    let unitLabels = {};
    try {
      hierarchy = JSON.parse(placeWrap.getAttribute('data-place-hierarchy') || '{}');
    } catch (_) {}
    try {
      unitLabels = JSON.parse(placeWrap.getAttribute('data-place-unit-labels') || '{}');
    } catch (_) {}

    function syncCityOptionsForCountry(preferredCity) {
      const cVal = countrySel.value;
      const isCustomCountry = (cVal === '__custom__');
      if (countryCustom) {
        countryCustom.style.display = isCustomCountry ? 'block' : 'none';
        countryCustom.required = isCustomCountry;
      }

      const unitLabel = (!isCustomCountry && unitLabels[cVal]) ? unitLabels[cVal] : 'County / State / District';
      if (cityLabel) {
        cityLabel.textContent = unitLabel + ' *';
      }
      if (cityCustom) {
        cityCustom.placeholder = 'Enter ' + unitLabel + '...';
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
      customOpt.textContent = '+ Other ' + unitLabel + ' (Type Below)';
      citySel.appendChild(customOpt);

      if (isCustomCountry) {
        citySel.value = '__custom__';
      } else if (preferredCity && list.includes(preferredCity)) {
        citySel.value = preferredCity;
      } else if (preferredCity && preferredCity !== '__custom__') {
        citySel.value = '__custom__';
        if (cityCustom) cityCustom.value = preferredCity;
      } else if (cVal === 'Kenya' && list.includes('Nairobi')) {
        citySel.value = 'Nairobi';
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

  // =========================================================================
  // Natural-Language & Type-Ahead Specialty Finder (#274, #276)
  // Understands first-person sentences ("I am an arthropod macro photographer"),
  // occupational stems, 116-specialty domain concepts, Swahili terms & typos.
  // =========================================================================
  function bindSpecialtyQuickFinder() {
    const finderWrap = document.querySelector('[data-specialty-finder]');
    const finderInput = document.getElementById('specialty-quick-finder');
    const clearBtn = document.getElementById('specialty-finder-clear');
    const resultsBox = document.getElementById('specialty-finder-results');
    const statusBox = document.getElementById('specialty-finder-status');
    const sectorSel = document.getElementById('sector_id');
    const specSel = document.getElementById('specialty_id');

    if (!finderWrap || !finderInput || !resultsBox || !sectorSel || !specSel) return;

    let taxonomy = [];
    let nlPayload = {};
    try {
      taxonomy = JSON.parse(finderWrap.getAttribute('data-taxonomy-json') || '[]');
    } catch (_) {}
    try {
      nlPayload = JSON.parse(finderWrap.getAttribute('data-synonyms-json') || '{}');
    } catch (_) {}

    const synonyms = (nlPayload && nlPayload.synonyms && typeof nlPayload.synonyms === 'object')
      ? nlPayload.synonyms
      : (nlPayload || {});
    const stemMap = (nlPayload && nlPayload.stems && typeof nlPayload.stems === 'object')
      ? nlPayload.stems
      : {};
    const stopSet = new Set(Array.isArray(nlPayload && nlPayload.stopwords) ? nlPayload.stopwords : []);

    const SUFFIX_RULES = [
      ['ographer', 'ograph'], ['ographers', 'ograph'], ['ography', 'ograph'], ['ographic', 'ograph'],
      ['ologist', 'olog'], ['ologists', 'olog'], ['ology', 'olog'],
      ['iatrician', 'iatr'], ['iatrics', 'iatr'],
      ['ications', 'ic'], ['ication', 'ic'], ['ations', 'at'], ['ation', 'at'],
      ['ments', ''], ['ment', ''], ['nesses', ''], ['ness', ''],
      ['ings', ''], ['ing', ''], ['ists', ''], ['ist', ''],
      ['ers', ''], ['er', ''], ['ors', ''], ['or', ''],
      ['ies', 'y'], ['es', ''], ['s', '']
    ];

    function stemWord(raw) {
      const w = String(raw || '').toLowerCase().replace(/[^a-z0-9]/g, '');
      if (!w) return '';
      if (stemMap[w]) return stemMap[w];
      if (w.length <= 4) return w;
      for (let i = 0; i < SUFFIX_RULES.length; i++) {
        const suf = SUFFIX_RULES[i][0];
        const rep = SUFFIX_RULES[i][1];
        if (w.length - suf.length >= 4 && w.endsWith(suf)) {
          return w.slice(0, w.length - suf.length) + rep;
        }
      }
      return w;
    }

    function diceBigram(a, b) {
      if (a === b) return a ? 1 : 0;
      if (a.length < 2 || b.length < 2) return 0;
      const bg = Object.create(null);
      for (let i = 0; i < a.length - 1; i++) {
        const k = a.slice(i, i + 2);
        bg[k] = (bg[k] || 0) + 1;
      }
      let inter = 0;
      for (let j = 0; j < b.length - 1; j++) {
        const k = b.slice(j, j + 2);
        if (bg[k] > 0) {
          inter++;
          bg[k]--;
        }
      }
      return (2 * inter) / ((a.length - 1) + (b.length - 1));
    }

    function extractMeaningfulTokens(rawQuery) {
      const clean = String(rawQuery || '').toLowerCase().slice(0, 180).trim();
      if (!clean) return [];
      const rawTokens = clean.split(/[^a-z0-9]+/i).filter(t => t.length >= 2);
      const kept = [];
      rawTokens.forEach(t => {
        if (!stopSet.has(t) && !kept.includes(t)) {
          kept.push(t);
        }
      });
      if (kept.length === 0) {
        rawTokens.forEach(t => {
          if (!kept.includes(t)) kept.push(t);
        });
      }
      return kept;
    }

    const flatSpecs = [];
    if (Array.isArray(taxonomy)) {
      taxonomy.forEach(sec => {
        if (!sec || !Array.isArray(sec.specialties)) return;
        const secName = String(sec.name || '');
        const secLower = secName.toLowerCase();
        const secWords = secLower.split(/[^a-z0-9]+/i).filter(Boolean);
        const secStems = new Set(secWords.map(stemWord).filter(Boolean));

        sec.specialties.forEach(sp => {
          if (!sp || !sp.id || !sp.name) return;
          const specName = String(sp.name);
          const specLower = specName.toLowerCase();
          const specWords = specLower.split(/[^a-z0-9]+/i).filter(Boolean);
          const specWordSet = new Set(specWords);
          const specStems = new Set(specWords.map(stemWord).filter(Boolean));

          const conceptLower = String(sp.concepts || '').toLowerCase();
          const conceptWords = conceptLower.split(/[^a-z0-9]+/i).filter(Boolean);
          const conceptWordSet = new Set(conceptWords);
          const conceptStems = new Set(conceptWords.map(stemWord).filter(Boolean));

          flatSpecs.push({
            specialtyId: Number(sp.id),
            specialtyName: specName,
            specialtyLower: specLower,
            specWords,
            specWordSet,
            specStems,
            conceptLower,
            conceptWords,
            conceptWordSet,
            conceptStems,
            sectorId: Number(sec.id),
            sectorName: secName,
            sectorLower: secLower,
            secWordSet: new Set(secWords),
            secStems,
            isRegulated: Boolean(sp.is_regulated)
          });
        });
      });
    }

    let currentMatches = [];
    let activeIndex = -1;

    function expandSynonyms(tokens) {
      const phrases = new Set();
      tokens.forEach(tok => {
        const st = stemWord(tok);
        if (Array.isArray(synonyms[tok])) {
          synonyms[tok].forEach(m => phrases.add(String(m).toLowerCase()));
        }
        if (tok.length >= 4) {
          Object.keys(synonyms).forEach(lexKey => {
            if ((lexKey.startsWith(tok) || stemWord(lexKey) === st) && Array.isArray(synonyms[lexKey])) {
              synonyms[lexKey].forEach(m => phrases.add(String(m).toLowerCase()));
            }
          });
        }
      });
      return Array.from(phrases);
    }

    function rankSpecialties(rawQuery) {
      const clean = String(rawQuery || '').trim().toLowerCase().slice(0, 180);
      if (!clean) return [];
      const tokens = extractMeaningfulTokens(clean);
      if (tokens.length === 0) return [];

      const meaningfulPhrase = tokens.join(' ');
      const synPhrases = expandSynonyms(tokens);
      const scored = [];

      flatSpecs.forEach(item => {
        let score = 0;
        const matchedSignals = [];

        if (item.specialtyLower === clean || (meaningfulPhrase && item.specialtyLower === meaningfulPhrase)) {
          score += 36;
        } else if (meaningfulPhrase.length >= 3 && item.specialtyLower.startsWith(meaningfulPhrase)) {
          score += 20;
        } else if (meaningfulPhrase.length >= 3 && item.specialtyLower.includes(meaningfulPhrase)) {
          score += 15;
        } else if (meaningfulPhrase.length >= 4 && item.conceptLower && item.conceptLower.includes(meaningfulPhrase)) {
          score += 13;
        }

        tokens.forEach(tok => {
          const st = stemWord(tok);
          let tokMatched = false;
          const escTok = tok.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          const wbRe = tok.length >= 3 ? new RegExp('\\b' + escTok, 'i') : null;

          // 1. Direct word / prefix / morphological stem match on specialty title
          if (item.specWordSet.has(tok)) {
            score += 14;
            tokMatched = true;
          } else if (wbRe && wbRe.test(item.specialtyName)) {
            score += 11;
            tokMatched = true;
          } else if (st.length >= 3 && item.specStems.has(st)) {
            score += 12.5;
            tokMatched = true;
          }

          // 2. Domain concept vocabulary match ("macro", "arthropod", "root canal", "mombasa", etc.)
          if (item.conceptWordSet.has(tok)) {
            score += 9.5;
            tokMatched = true;
          } else if (tok.length >= 5 && wbRe && item.conceptLower && wbRe.test(item.conceptLower)) {
            score += 7.5;
            tokMatched = true;
          } else if (st.length >= 4 && item.conceptStems.has(st)) {
            score += 8;
            tokMatched = true;
          }

          // 3. Parent sector word / stem match
          if (!tokMatched && (item.secWordSet.has(tok) || (st.length >= 4 && item.secStems.has(st)))) {
            score += 3;
            tokMatched = true;
          }

          // 4. Typo-tolerant character-bigram fallback ("photografer", "ortodontist")
          if (!tokMatched && tok.length >= 5) {
            let bestDice = 0;
            const pool = item.specWords.concat(item.conceptWords);
            for (let k = 0; k < pool.length; k++) {
              const cw = pool[k];
              if (cw.length < 4 || Math.abs(cw.length - tok.length) > 3) continue;
              const d = diceBigram(tok, cw);
              if (d > bestDice) bestDice = d;
            }
            if (bestDice >= 0.76) {
              score += Math.round(bestDice * 750) / 100;
              tokMatched = true;
            }
          }

          if (tokMatched && !matchedSignals.includes(tok)) {
            matchedSignals.push(tok);
          }
        });

        synPhrases.forEach(syn => {
          if (!syn) return;
          const synStem = stemWord(syn);
          if (item.specialtyLower.includes(syn) || (synStem && item.specStems.has(synStem))) {
            score += 6.5;
          } else if (item.conceptLower && item.conceptLower.includes(syn)) {
            score += 4;
          } else if (item.sectorLower.includes(syn)) {
            score += 2;
          }
        });

        if (matchedSignals.length >= 2) {
          score += (matchedSignals.length - 1) * 4;
        }

        if (score > 0) {
          scored.push({ item, score });
        }
      });

      scored.sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score;
        return a.item.specialtyName.localeCompare(b.item.specialtyName);
      });

      return scored.slice(0, 8).map(s => s.item);
    }

    function closeDropdown() {
      resultsBox.hidden = true;
      finderInput.setAttribute('aria-expanded', 'false');
      finderInput.removeAttribute('aria-activedescendant');
      activeIndex = -1;
    }

    function syncActiveHighlight() {
      const buttons = resultsBox.querySelectorAll('.specialty-finder-option');
      buttons.forEach((btn, idx) => {
        const isAct = (idx === activeIndex);
        btn.classList.toggle('is-active', isAct);
        btn.setAttribute('aria-selected', isAct ? 'true' : 'false');
        if (isAct) {
          finderInput.setAttribute('aria-activedescendant', btn.id);
          if (typeof btn.scrollIntoView === 'function') {
            btn.scrollIntoView({ block: 'nearest' });
          }
        }
      });
    }

    function chooseMatch(match) {
      if (!match) return;
      const jsToken = document.getElementById('js_interaction_token');
      if (jsToken && !jsToken.value) {
        jsToken.value = 'human_verified_' + Date.now();
      }

      sectorSel.value = String(match.sectorId);
      sectorSel.dispatchEvent(new Event('change', { bubbles: true }));
      specSel.value = String(match.specialtyId);
      specSel.dispatchEvent(new Event('change', { bubbles: true }));

      [sectorSel, specSel].forEach(el => {
        el.style.borderColor = '#059669';
        el.style.backgroundColor = '#ECFDF5';
      });

      finderInput.value = match.specialtyName;
      if (clearBtn) clearBtn.hidden = false;
      closeDropdown();

      if (statusBox) {
        statusBox.textContent = '✓ Auto-selected: ' + match.sectorName + ' → ' + match.specialtyName;
      }
    }

    function renderMatches() {
      const q = finderInput.value.trim();
      if (clearBtn) clearBtn.hidden = (q === '');
      if (q.length < 2) {
        currentMatches = [];
        resultsBox.innerHTML = '';
        closeDropdown();
        return;
      }

      currentMatches = rankSpecialties(q);
      resultsBox.innerHTML = '';
      activeIndex = currentMatches.length > 0 ? 0 : -1;

      if (currentMatches.length === 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'specialty-finder-empty';
        const supportsCustom = Array.from(specSel.options || []).some(o => o.value === '__custom__')
          || Boolean(document.getElementById('custom-specialty-wrap'));
        emptyDiv.textContent = supportsCustom
          ? 'No exact match yet — choose the closest Industry Sector below and select "+ Add New Specialty" to propose yours.'
          : 'No matching specialty found — try describing your main product/service or select from Industry Sector below.';
        resultsBox.appendChild(emptyDiv);
        resultsBox.hidden = false;
        finderInput.setAttribute('aria-expanded', 'true');
        return;
      }

      currentMatches.forEach((m, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'spec-finder-opt-' + m.specialtyId;
        btn.className = 'specialty-finder-option' + (idx === 0 ? ' is-active' : '');
        btn.setAttribute('role', 'option');
        btn.setAttribute('aria-selected', idx === 0 ? 'true' : 'false');
        btn.setAttribute('data-specialty-id', String(m.specialtyId));
        btn.setAttribute('data-sector-id', String(m.sectorId));

        const nameSpan = document.createElement('span');
        nameSpan.className = 'specialty-finder-opt-name';
        nameSpan.textContent = m.specialtyName;

        const secSpan = document.createElement('span');
        secSpan.className = 'specialty-finder-opt-sector';
        secSpan.textContent = m.sectorName;

        btn.appendChild(nameSpan);
        btn.appendChild(secSpan);

        btn.addEventListener('mousedown', e => {
          e.preventDefault();
        });
        btn.addEventListener('click', () => chooseMatch(m));
        resultsBox.appendChild(btn);
      });

      resultsBox.hidden = false;
      finderInput.setAttribute('aria-expanded', 'true');
      syncActiveHighlight();
    }

    finderInput.addEventListener('input', () => {
      if (statusBox) statusBox.textContent = '';
      renderMatches();
    });

    finderInput.addEventListener('focus', () => {
      if (finderInput.value.trim().length >= 2) {
        renderMatches();
      }
    });

    finderInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        closeDropdown();
        return;
      }
      if (resultsBox.hidden || currentMatches.length === 0) {
        if (e.key === 'Enter' && finderInput.value.trim().length >= 2) {
          e.preventDefault();
          renderMatches();
          if (currentMatches.length > 0) {
            chooseMatch(currentMatches[0]);
          }
        }
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = (activeIndex + 1) % currentMatches.length;
        syncActiveHighlight();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = (activeIndex - 1 + currentMatches.length) % currentMatches.length;
        syncActiveHighlight();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const chosen = currentMatches[activeIndex >= 0 ? activeIndex : 0];
        if (chosen) chooseMatch(chosen);
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        finderInput.value = '';
        clearBtn.hidden = true;
        if (statusBox) statusBox.textContent = '';
        closeDropdown();
        finderInput.focus();
      });
    }

    document.addEventListener('click', e => {
      if (!finderWrap.contains(e.target)) {
        closeDropdown();
      }
    });
  }

  bindSpecialtyQuickFinder();


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

  // =========================================================================
  // Registration Form Draft Auto-Save & Restoration (#95, #210 M-02)
  // Ephemeral sessionStorage (2-hour TTL), strictly excluding vetting PII, photos & consent checkboxes.
  // =========================================================================
  const LEGACY_DRAFT_KEY = 'bbiz_submit_draft_v1';
  const DRAFT_KEY = 'bbiz_submit_draft_v2';
  const DRAFT_TTL_MS = 2 * 60 * 60 * 1000; // 2 hours
  const submitForm = document.getElementById('business-submit-form');
  const restoredBanner = document.getElementById('draft-restored-banner');
  const clearDraftBtn = document.getElementById('btn-clear-draft');
  const autosaveIndicator = document.getElementById('draft-autosave-indicator');

  // Always purge any legacy indefinite localStorage draft on load (#210 M-02)
  try { localStorage.removeItem(LEGACY_DRAFT_KEY); } catch (_) {}

  const EXCLUDED_DRAFT_NAMES = new Set([
    'csrf_token',
    'form_ts',
    'form_nonce',
    'form_sig',
    'js_interaction_token',
    'website',
    'business_photos[]',
    'business_photos_data_json',
    'house_name',
    'house_custom',
    'year_left',
    'school_number',
    'age_attestation_18',
    'dpa_consent_directory'
  ]);

  if (window.BBIZ_SUBMIT_SUCCESS === true) {
    try { sessionStorage.removeItem(DRAFT_KEY); } catch (_) {}
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
      'submitter_phone', 'phone_primary', 'whatsapp_number', 'email_public',
      'website_url', 'building_name', 'street_road', 'area_neighborhood',
      'alumni_perk', 'regulator_license'
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
        sessionStorage.setItem(DRAFT_KEY, JSON.stringify({
          saved_at: Date.now(),
          expires_at: Date.now() + DRAFT_TTL_MS,
          fields: draft
        }));
        if (autosaveIndicator) {
          const nowStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
          autosaveIndicator.textContent = `💾 Non-sensitive draft saved for this tab at ${nowStr} (expires in 2 hours; vetting details & photos are not stored).`;
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

    // 4. Require fresh consent checkboxes on every draft restoration (#210 M-02)
    ['age_attestation_18', 'dpa_consent_directory'].forEach(cName => {
      const cEl = submitForm.querySelector(`[name="${cName}"]`);
      if (cEl) cEl.checked = false;
    });
  }

  if (submitForm && window.BBIZ_SUBMIT_SUCCESS !== true) {
    let restoredFields = null;
    if (window.BBIZ_SUBMIT_POST_DRAFT && typeof window.BBIZ_SUBMIT_POST_DRAFT === 'object') {
      restoredFields = window.BBIZ_SUBMIT_POST_DRAFT;
    } else {
      try {
        const raw = sessionStorage.getItem(DRAFT_KEY);
        if (raw) {
          const parsed = JSON.parse(raw);
          const savedAt = Number(parsed && parsed.saved_at ? parsed.saved_at : 0);
          const isFresh = savedAt > 0 && (Date.now() - savedAt) <= DRAFT_TTL_MS;
          if (!isFresh) {
            sessionStorage.removeItem(DRAFT_KEY);
          } else if (parsed && parsed.fields && hasMeaningfulDraft(parsed.fields)) {
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
        try { sessionStorage.removeItem(DRAFT_KEY); localStorage.removeItem(LEGACY_DRAFT_KEY); } catch (_) {}
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

  // =========================================================================
  // Live Maps Link & Plus Code -> Latitude / Longitude Auto-Fill (#138, #140)
  // =========================================================================
  function bindMapsPinAutoFill() {
    const mapsInput = document.getElementById('google_maps_url') || document.querySelector('input[name="google_maps_url"]');
    const plusInput = document.getElementById('plus_code') || document.querySelector('input[name="plus_code"]');
    const latInput = document.getElementById('latitude') || document.querySelector('input[name="latitude"]');
    const lngInput = document.getElementById('longitude') || document.querySelector('input[name="longitude"]');
    if (!latInput || !lngInput || (!mapsInput && !plusInput)) return;

    let statusEl = document.getElementById('maps-pin-autofill-status');
    const anchor = mapsInput || plusInput;
    if (!statusEl && anchor && anchor.parentElement) {
      statusEl = document.createElement('div');
      statusEl.id = 'maps-pin-autofill-status';
      statusEl.style.cssText = 'font-size:0.75rem;margin-top:0.28rem;font-weight:600;display:none;';
      anchor.parentElement.appendChild(statusEl);
    }

    let debounceTimer = null;
    let lastResolvedValue = '';

    async function resolvePinInput(rawValue, forceUpdateCoords) {
      const val = (rawValue || '').trim();
      if (!val || val.length < 4 || val === lastResolvedValue) return;

      const coordsEmpty = !latInput.value.trim() || !lngInput.value.trim();
      const wasAutoFilled = latInput.dataset.autofilled === '1' || lngInput.dataset.autofilled === '1';
      if (!coordsEmpty && !wasAutoFilled && !forceUpdateCoords) {
        return;
      }

      lastResolvedValue = val;
      if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#114B3E';
        statusEl.textContent = '⏳ Extracting map pin coordinates...';
      }

      try {
        const res = await fetch('/api/geocode.php?q=' + encodeURIComponent(val));
        const payload = await res.json();
        if (!res.ok || !payload || payload.status !== 'ok' || !payload.location) {
          if (statusEl) {
            statusEl.style.color = '#6B7280';
            statusEl.textContent = 'Coordinate preview unavailable right now — server will check on save.';
          }
          return;
        }
        const loc = payload.location;
        if (loc.latitude !== undefined && loc.longitude !== undefined) {
          latInput.value = String(loc.latitude);
          lngInput.value = String(loc.longitude);
          latInput.dataset.autofilled = '1';
          lngInput.dataset.autofilled = '1';
          latInput.style.borderColor = '#059669';
          lngInput.style.borderColor = '#059669';
          if (plusInput && !plusInput.value.trim() && loc.plus_code) {
            plusInput.value = String(loc.plus_code);
          }
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.color = '#065F46';
            statusEl.textContent = '✓ Coordinates (' + loc.latitude + ', ' + loc.longitude + ') filled automatically — Directions button will be active!';
          }
          latInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } catch (_) {
        if (statusEl) {
          statusEl.style.display = 'none';
        }
      }
    }

    [mapsInput, plusInput].forEach(inp => {
      if (!inp) return;
      inp.addEventListener('paste', () => {
        setTimeout(() => resolvePinInput(inp.value, inp === mapsInput), 60);
      });
      inp.addEventListener('change', () => {
        resolvePinInput(inp.value, inp === mapsInput);
      });
      inp.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => resolvePinInput(inp.value, false), 500);
      });
    });

    // If a listing already has a Maps link or Plus Code loaded in the editor with blank lat/lng, auto-fill immediately
    if ((!latInput.value.trim() || !lngInput.value.trim())) {
      const initialPin = (mapsInput && mapsInput.value.trim()) || (plusInput && plusInput.value.trim()) || '';
      if (initialPin) {
        resolvePinInput(initialPin, false);
      }
    }
  }

  bindMapsPinAutoFill();
})();
