(function () {
  'use strict';

  const state = {
    businesses: window.INITIAL_BUSINESSES || [],
    sectors: window.INITIAL_SECTORS || [],
    selectedSectorId: '',
    selectedSpecialtyId: '',
    selectedSchool: '',
    openNow: false,
    hasPerk: false,
    b2bReady: false,
    userLat: null,
    userLng: null,
    radiusKm: '',
    searchQuery: '',
    shortlistIds: new Set(),
    activeBusiness: null,
  };

  const els = {
    searchInput: document.getElementById('search-q'),
    searchClearBtn: document.getElementById('btn-clear-search'),
    sectorSelect: document.getElementById('filter-sector'),
    specialtySelect: document.getElementById('filter-specialty'),
    radiusSelect: document.getElementById('filter-radius'),
    nearMeBtn: document.getElementById('btn-near-me'),
    cardsGrid: document.getElementById('cards-grid'),
    resultsCount: document.getElementById('results-count'),
    mapCanvas: document.getElementById('map-canvas'),
    modalBackdrop: document.getElementById('biz-modal-backdrop'),
    modalContent: document.getElementById('biz-modal-content'),
    shortlistCount: document.getElementById('shortlist-count'),
    shortlistExportBtn: document.getElementById('btn-export-shortlist'),
  };

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function populateSpecialtiesDropdown(sectorId) {
    if (!els.specialtySelect) return;
    els.specialtySelect.innerHTML = '<option value="">All Specialties (Level 2)</option>';
    const sec = state.sectors.find(s => String(s.id) === String(sectorId));
    if (!sec || !Array.isArray(sec.specialties)) return;
    sec.specialties.forEach(sp => {
      const opt = document.createElement('option');
      opt.value = sp.id;
      opt.textContent = sp.name;
      els.specialtySelect.appendChild(opt);
    });
  }

  async function fetchBusinesses() {
    const params = new URLSearchParams();
    if (state.searchQuery) params.set('q', state.searchQuery);
    if (state.selectedSectorId) params.set('sector_id', state.selectedSectorId);
    if (state.selectedSpecialtyId) params.set('specialty_id', state.selectedSpecialtyId);
    if (state.selectedSchool) params.set('school', state.selectedSchool);
    if (state.openNow) params.set('open_now', '1');
    if (state.hasPerk) params.set('has_perk', '1');
    if (state.b2bReady) params.set('b2b_ready', '1');
    if (state.userLat !== null && state.userLng !== null) {
      params.set('lat', String(state.userLat));
      params.set('lng', String(state.userLng));
      if (state.radiusKm) params.set('radius_km', String(state.radiusKm));
    }

    const res = await fetch('/api/businesses.php?' + params.toString());
    const data = await res.json();
    if (data && data.status === 'ok') {
      state.businesses = data.businesses || [];
      renderCards();
      renderInteractiveMap();
    }
  }

  function renderCards() {
    if (!els.cardsGrid) return;
    if (els.resultsCount) {
      els.resultsCount.textContent = `${state.businesses.length} Verified Busherian ${state.businesses.length === 1 ? 'Biashara' : 'Biasharas'}`;
    }

    if (state.businesses.length === 0) {
      els.cardsGrid.innerHTML = `
        <div style="grid-column:1/-1;background:#fff;padding:2rem;border-radius:12px;text-align:center;border:1px dashed #D1D5DB;">
          <h3 style="margin:0 0 0.5rem 0;color:#7B1113;">No matching businesses found</h3>
          <p style="margin:0;color:#6B7280;font-size:0.9rem;">Try clearing filters or searching another Kenyan/Swahili keyword (e.g., wakili, daktari, solar, coffee).</p>
        </div>
      `;
      return;
    }

    els.cardsGrid.innerHTML = state.businesses.map(biz => {
      const schoolClass = biz.school_badge === 'AGHS' ? 'badge-school-aghs' : 'badge-school-ahs';
      const schoolLabel = biz.school_badge === 'BOTH' ? 'AHS & AGHS' : `${biz.school_badge}`;
      const distBadge = (biz.distance_km !== null && biz.distance_km !== undefined)
        ? `<span class="badge-distance">📍 ${Number(biz.distance_km).toFixed(1)} km away</span>`
        : `<span style="font-size:0.75rem;color:#6B7280;">📍 ${escapeHtml(biz.area_neighborhood || biz.city_county)}</span>`;
      const hours = biz.hours_status || { state: 'CLOSED', badge_text: 'Closed' };
      const perkHtml = biz.alumni_perk
        ? `<div class="card-Perk">🎁 <strong>Busherian Perk:</strong> ${escapeHtml(biz.alumni_perk)}</div>`
        : '';
      const inShortlist = state.shortlistIds.has(biz.id);

      // STRICT PRIVACY GUARANTEE: Zero House, Year Left, or School Number ever rendered
      return `
        <article class="biz-card" data-biz-id="${biz.id}">
          <div>
            <div class="card-top-badges">
              <span class="badge-verified ${schoolClass}">✓ Verified Busherian (${escapeHtml(schoolLabel)})</span>
              ${distBadge}
            </div>
            <h3 class="card-title">${escapeHtml(biz.name)}</h3>
            <div class="card-taxonomy">${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}</div>
            <p class="card-tagline">${escapeHtml(biz.tagline)}</p>
            ${perkHtml}
          </div>
          <div class="card-footer">
            <span class="status-dot status-${escapeHtml(hours.state)}">● ${escapeHtml(hours.badge_text)}</span>
            <div style="display:flex;gap:0.4rem;align-items:center;">
              <button type="button" class="btn-shortlist-toggle" data-shortlist-id="${biz.id}" title="Add to B2B Procurement Shortlist" style="background:${inShortlist ? '#114B3E' : '#F3F4F6'};color:${inShortlist ? '#fff' : '#374151'};border:none;border-radius:6px;padding:0.25rem 0.55rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                ${inShortlist ? '✓ Shortlisted' : '+ B2B Shortlist'}
              </button>
              <span style="font-weight:700;color:#7B1113;">Details &rarr;</span>
            </div>
          </div>
        </article>
      `;
    }).join('');
  }

  let leafletMap = null;
  let markersLayer = null;
  let mapOverlayBadge = null;

  /**
   * Real Interactive Spatial Map powered by self-hosted Leaflet + OpenStreetMap Road & Feature Tiles
   */
  function renderInteractiveMap() {
    if (!els.mapCanvas) return;
    const pinned = state.businesses.filter(b => b.latitude !== null && b.longitude !== null);

    if (typeof window.L === 'undefined') {
      els.mapCanvas.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:#6B7280;padding:1rem;text-align:center;">Interactive map loading...</div>';
      return;
    }

    if (!leafletMap) {
      els.mapCanvas.innerHTML = '';
      leafletMap = window.L.map(els.mapCanvas, {
        center: [-1.286389, 36.817223],
        zoom: 12,
        scrollWheelZoom: true,
        zoomControl: true,
      });

      window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
      }).addTo(leafletMap);

      markersLayer = window.L.layerGroup().addTo(leafletMap);

      mapOverlayBadge = document.createElement('div');
      mapOverlayBadge.style.cssText = 'position:absolute;top:10px;right:12px;background:rgba(255,255,255,0.95);padding:5px 11px;border-radius:8px;font-size:0.74rem;font-weight:700;color:#114B3E;border:1px solid #D1D5DB;z-index:1000;box-shadow:0 2px 6px rgba(0,0,0,0.12);pointer-events:none;';
      els.mapCanvas.appendChild(mapOverlayBadge);
    }

    markersLayer.clearLayers();

    if (mapOverlayBadge) {
      mapOverlayBadge.textContent = `📍 ${pinned.length} ${pinned.length === 1 ? 'Location' : 'Locations'} • Click any pin for details & directions`;
    }

    const boundsPoints = [];

    if (state.userLat !== null && state.userLng !== null) {
      const radiusMeters = (Number(state.radiusKm) || 2) * 1000;
      window.L.circle([state.userLat, state.userLng], {
        radius: radiusMeters,
        color: '#114B3E',
        weight: 2,
        dashArray: '6, 6',
        fillColor: '#114B3E',
        fillOpacity: 0.08,
      }).addTo(markersLayer);

      const userDot = window.L.circleMarker([state.userLat, state.userLng], {
        radius: 8,
        color: '#ffffff',
        weight: 3,
        fillColor: '#2563EB',
        fillOpacity: 1,
      }).addTo(markersLayer);
      userDot.bindTooltip('Your Current Location', { direction: 'top', offset: [0, -8] });
      boundsPoints.push([state.userLat, state.userLng]);
    }

    pinned.forEach((b) => {
      const isOpen = b.hours_status && b.hours_status.is_open;
      const bg = isOpen ? '#114B3E' : '#7B1113';
      const label = b.name.length > 24 ? b.name.slice(0, 22) + '…' : b.name;
      const area = b.area_neighborhood || b.city_county || '';

      const icon = window.L.divIcon({
        className: 'bbiz-leaflet-pin',
        html: `<div class="bbiz-pin-pill" style="background:${bg};" title="${escapeHtml(b.name)} — ${escapeHtml(area)}">📍 ${escapeHtml(label)}</div>`,
        iconSize: [0, 0],
        iconAnchor: [0, 0],
      });

      const marker = window.L.marker([b.latitude, b.longitude], { icon }).addTo(markersLayer);
      marker.on('click', () => {
        openBusinessModal(b.id);
      });
      boundsPoints.push([b.latitude, b.longitude]);
    });

    if (boundsPoints.length === 1) {
      leafletMap.setView(boundsPoints[0], 15, { animate: true });
    } else if (boundsPoints.length > 1) {
      const bounds = window.L.latLngBounds(boundsPoints);
      leafletMap.fitBounds(bounds, { padding: [45, 45], maxZoom: 15, animate: true });
    }
  }

  function trackClick(businessId, eventType) {
    try {
      fetch('/api/track-click.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ business_id: businessId, event_type: eventType }),
      });
    } catch (_) {}
  }

  function openBusinessModal(bizId) {
    const biz = state.businesses.find(b => Number(b.id) === Number(bizId));
    if (!biz || !els.modalBackdrop || !els.modalContent) return;
    state.activeBusiness = biz;

    const hours = biz.hours_status || { state: 'CLOSED', badge_text: 'Closed', today_key: 'mon', schedule_table: {} };
    const scheduleRows = Object.entries(hours.schedule_table || {}).map(([key, row]) => {
      const isToday = key === hours.today_key;
      return `<tr class="${isToday ? 'today-row' : ''}">
        <td>${escapeHtml(row.label)} ${isToday ? '(Today)' : ''}</td>
        <td style="text-align:right;">${escapeHtml(row.hours)}</td>
      </tr>`;
    }).join('');

    const waNumber = (biz.whatsapp_number || biz.phone_primary || '').replace(/[^0-9]/g, '');
    const waText = encodeURIComponent(`Hi ${biz.name}, I found your business on Busherian Biasharas!`);
    const waUrl = waNumber ? `https://wa.me/${waNumber}?text=${waText}` : '';

    const gmapsUrl = biz.google_maps_url
      || (biz.latitude !== null && biz.longitude !== null
        ? `https://www.google.com/maps/search/?api=1&query=${biz.latitude},${biz.longitude}`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(biz.name + ' ' + biz.city_county)}`);

    const wazeUrl = (biz.latitude !== null && biz.longitude !== null)
      ? `https://waze.com/ul?ll=${biz.latitude},${biz.longitude}&navigate=yes`
      : '';

    const socials = biz.socials || {};
    const socialBadges = [
      ['instagram', 'Instagram', socials.instagram],
      ['linkedin', 'LinkedIn', socials.linkedin],
      ['x', 'X (Twitter)', socials.x],
      ['facebook', 'Facebook', socials.facebook],
      ['tiktok', 'TikTok', socials.tiktok],
      ['youtube', 'YouTube', socials.youtube],
    ].filter(s => !!s[2]).map(([key, label, url]) => `
      <a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline" style="font-size:0.8rem;padding:0.4rem 0.75rem;">
        ${escapeHtml(label)} &nearr;
      </a>
    `).join('');

    const schoolLabel = biz.school_badge === 'BOTH' ? 'AHS & AGHS' : `${biz.school_badge}`;

    els.modalContent.innerHTML = `
      <div class="modal-header">
        <div>
          <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:0.4rem;">
            <span class="badge-verified">✓ Verified Busherian (${escapeHtml(schoolLabel)})</span>
            <span class="status-dot status-${escapeHtml(hours.state)}">● ${escapeHtml(hours.badge_text)}</span>
            ${biz.b2b_ready ? '<span class="badge-distance">🏢 B2B & LPO Ready</span>' : ''}
            ${biz.regulator_name ? `<span class="badge-distance">🛡️ ${escapeHtml(biz.regulator_name)}: ${escapeHtml(biz.regulator_license || 'Verified')}</span>` : ''}
          </div>
          <h2 style="margin:0;font-size:1.4rem;color:#111827;">${escapeHtml(biz.name)}</h2>
          <div style="font-size:0.86rem;font-weight:700;color:#7B1113;margin-top:0.2rem;">
            ${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}
          </div>
        </div>
        <button type="button" id="btn-close-modal" class="btn btn-outline" aria-label="Close modal">✕ Close</button>
      </div>
      <div class="modal-body">
        <p style="margin-top:0;color:#374151;font-size:0.95rem;">${escapeHtml(biz.description)}</p>

        ${biz.alumni_perk ? `
          <div class="card-Perk" style="font-size:0.88rem;padding:0.65rem 0.9rem;">
            🎁 <strong>Exclusive Busherian Alumni Offer:</strong> ${escapeHtml(biz.alumni_perk)}
          </div>
        ` : ''}

        <h4 style="margin:1.1rem 0 0.5rem 0;color:#114B3E;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.04em;">Direct Contact & Online Channels</h4>
        <div class="action-grid">
          ${biz.website_url ? `<a href="${escapeHtml(biz.website_url)}" target="_blank" rel="noopener noreferrer" data-track="website" class="btn btn-crimson">🌐 Visit Website</a>` : ''}
          ${biz.phone_primary ? `<a href="tel:${escapeHtml(biz.phone_primary)}" data-track="call" class="btn btn-green">📞 Call ${escapeHtml(biz.phone_primary)}</a>` : ''}
          ${waUrl ? `<a href="${escapeHtml(waUrl)}" target="_blank" rel="noopener noreferrer" data-track="whatsapp" class="btn btn-gold">💬 WhatsApp Now</a>` : ''}
          ${biz.email_public ? `<a href="mailto:${escapeHtml(biz.email_public)}" data-track="email" class="btn btn-outline">✉️ Email Business</a>` : ''}
        </div>

        ${socialBadges ? `
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.6rem;">
            ${socialBadges}
          </div>
        ` : ''}

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.15rem;margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #E5E7EB;">
          <div>
            <h4 style="margin:0 0 0.5rem 0;color:#114B3E;font-size:0.9rem;">📍 Physical Location & Navigation Pin</h4>
            <p style="margin:0 0 0.35rem 0;font-size:0.88rem;color:#111827;">
              <strong>${escapeHtml(biz.building_name || biz.branch_name || biz.name)}</strong>
              ${biz.floor_suite ? `, ${escapeHtml(biz.floor_suite)}` : ''}
            </p>
            <p style="margin:0 0 0.35rem 0;font-size:0.85rem;color:#4B5563;">
              ${escapeHtml([biz.street_road, biz.area_neighborhood, biz.city_county].filter(Boolean).join(', '))}
            </p>
            ${biz.landmark_directions ? `<p style="margin:0 0 0.5rem 0;font-size:0.8rem;color:#6B7280;"><em>Landmark: ${escapeHtml(biz.landmark_directions)}</em></p>` : ''}
            ${biz.plus_code ? `<p style="margin:0 0 0.65rem 0;font-size:0.78rem;color:#114B3E;font-family:monospace;">Plus Code: ${escapeHtml(biz.plus_code)}</p>` : ''}
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
              <a href="${escapeHtml(gmapsUrl)}" target="_blank" rel="noopener noreferrer" data-track="map_pin" class="btn btn-outline" style="font-size:0.8rem;">🗺️ Open in Google Maps</a>
              ${wazeUrl ? `<a href="${escapeHtml(wazeUrl)}" target="_blank" rel="noopener noreferrer" data-track="map_pin" class="btn btn-outline" style="font-size:0.8rem;">🚗 Navigate in Waze</a>` : ''}
            </div>
          </div>

          <div>
            <h4 style="margin:0 0 0.5rem 0;color:#114B3E;font-size:0.9rem;">🕒 Operating Hours (East Africa Time)</h4>
            <table class="hours-table">
              <tbody>${scheduleRows}</tbody>
            </table>
          </div>
        </div>

        <div style="margin-top:1.15rem;padding-top:0.85rem;border-top:1px solid #F3F4F6;display:flex;justify-content:space-between;align-items:center;font-size:0.76rem;color:#6B7280;">
          <span>Verified Busherian Biashara</span>
          <a href="/takedown.php?business_id=${biz.id}" style="color:#7B1113;font-weight:600;">Report / DPA Takedown</a>
        </div>
      </div>
    `;

    els.modalBackdrop.classList.add('open');
  }

  function exportB2BShortlist() {
    const selected = state.businesses.filter(b => state.shortlistIds.has(b.id));
    if (selected.length === 0) {
      alert('Select at least one business using "+ B2B Shortlist" on any card to export a procurement summary.');
      return;
    }
    const headers = ['Business Name', 'Industry Sector', 'Specialty', 'City/Area', 'B2B Ready', 'Accepts LPO', 'Regulator', 'Phone', 'Email', 'Website'];
    const sanitizeCsv = (val) => {
      const s = String(val ?? '').trim();
      const safe = (/^[=+\-@|\t\r]/.test(s)) ? ("'" + s) : s;
      return '"' + safe.replace(/"/g, '""') + '"';
    };
    const rows = selected.map(b => [
      b.name,
      b.sector_name,
      b.specialty_name,
      `${b.area_neighborhood || ''} ${b.city_county || ''}`.trim(),
      b.b2b_ready ? 'Yes' : 'No',
      b.accepts_lpo ? 'Yes' : 'No',
      b.regulator_name ? `${b.regulator_name} (${b.regulator_license || ''})` : '',
      b.phone_primary,
      b.email_public,
      b.website_url,
    ].map(sanitizeCsv).join(','));

    const csvContent = [headers.map(sanitizeCsv).join(','), ...rows].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'busherian-b2b-procurement-shortlist.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  // Wire Event Listeners
  if (els.searchInput) {
    let timer = null;
    const syncClearBtn = () => {
      if (els.searchClearBtn) {
        els.searchClearBtn.style.display = els.searchInput.value.length > 0 ? 'inline-flex' : 'none';
      }
    };
    els.searchInput.addEventListener('input', (e) => {
      syncClearBtn();
      state.searchQuery = e.target.value.trim();
      clearTimeout(timer);
      timer = setTimeout(fetchBusinesses, 220);
    });
    if (els.searchClearBtn) {
      els.searchClearBtn.addEventListener('click', () => {
        els.searchInput.value = '';
        syncClearBtn();
        state.searchQuery = '';
        clearTimeout(timer);
        fetchBusinesses();
        els.searchInput.focus();
      });
    }
    syncClearBtn();
  }

  if (els.sectorSelect) {
    els.sectorSelect.addEventListener('change', (e) => {
      state.selectedSectorId = e.target.value;
      state.selectedSpecialtyId = '';
      populateSpecialtiesDropdown(state.selectedSectorId);
      fetchBusinesses();
    });
  }

  if (els.specialtySelect) {
    els.specialtySelect.addEventListener('change', (e) => {
      state.selectedSpecialtyId = e.target.value;
      fetchBusinesses();
    });
  }

  if (els.radiusSelect) {
    els.radiusSelect.addEventListener('change', (e) => {
      state.radiusKm = e.target.value;
      fetchBusinesses();
    });
  }

  if (els.nearMeBtn) {
    els.nearMeBtn.addEventListener('click', () => {
      if (!navigator.geolocation) {
        // Fallback to Nairobi CBD centroid for desktop testing
        state.userLat = -1.286389;
        state.userLng = 36.817223;
        fetchBusinesses();
        return;
      }
      els.nearMeBtn.textContent = '📍 Locating...';
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          state.userLat = pos.coords.latitude;
          state.userLng = pos.coords.longitude;
          els.nearMeBtn.textContent = '📍 Near Me (Active)';
          els.nearMeBtn.classList.add('active');
          fetchBusinesses();
        },
        () => {
          state.userLat = -1.286389;
          state.userLng = 36.817223;
          els.nearMeBtn.textContent = '📍 Nairobi Central';
          fetchBusinesses();
        },
        { timeout: 6000 }
      );
    });
  }

  document.querySelectorAll('[data-quick-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
      const f = btn.getAttribute('data-quick-filter');
      if (f === 'all') {
        state.openNow = false;
        state.hasPerk = false;
        state.b2bReady = false;
        state.selectedSchool = '';
        document.querySelectorAll('[data-quick-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
      } else if (f === 'open_now') {
        state.openNow = !state.openNow;
        btn.classList.toggle('active', state.openNow);
      } else if (f === 'has_perk') {
        state.hasPerk = !state.hasPerk;
        btn.classList.toggle('active', state.hasPerk);
      } else if (f === 'b2b_ready') {
        state.b2bReady = !state.b2bReady;
        btn.classList.toggle('active', state.b2bReady);
      } else if (f === 'AHS' || f === 'AGHS') {
        state.selectedSchool = (state.selectedSchool === f) ? '' : f;
        document.querySelectorAll('[data-quick-filter="AHS"],[data-quick-filter="AGHS"]').forEach(b => {
          b.classList.toggle('active', b.getAttribute('data-quick-filter') === state.selectedSchool);
        });
      }
      fetchBusinesses();
    });
  });

  document.addEventListener('click', (e) => {
    const shortlistBtn = e.target.closest('[data-shortlist-id]');
    if (shortlistBtn) {
      e.stopPropagation();
      const id = Number(shortlistBtn.getAttribute('data-shortlist-id'));
      if (state.shortlistIds.has(id)) {
        state.shortlistIds.delete(id);
      } else {
        if (state.shortlistIds.size >= 5) {
          alert('You can shortlist up to 5 alumni vendors at a time.');
          return;
        }
        state.shortlistIds.add(id);
        trackClick(id, 'shortlist');
      }
      if (els.shortlistCount) {
        els.shortlistCount.textContent = String(state.shortlistIds.size);
      }
      renderCards();
      return;
    }

    const cardOrPin = e.target.closest('[data-biz-id]');
    if (cardOrPin) {
      const id = Number(cardOrPin.getAttribute('data-biz-id'));
      openBusinessModal(id);
      return;
    }

    if (e.target.id === 'btn-close-modal' || e.target === els.modalBackdrop) {
      if (els.modalBackdrop) els.modalBackdrop.classList.remove('open');
      return;
    }

    const trackEl = e.target.closest('[data-track]');
    if (trackEl && state.activeBusiness) {
      trackClick(state.activeBusiness.id, trackEl.getAttribute('data-track'));
    }
  });

  if (els.shortlistExportBtn) {
    els.shortlistExportBtn.addEventListener('click', exportB2BShortlist);
  }

  // Mobile View Toggle (List vs Map)
  const btnList = document.getElementById('btn-mobile-list');
  const btnMap = document.getElementById('btn-mobile-map');
  const paneResults = document.getElementById('pane-results');
  const paneMap = document.getElementById('pane-map');
  if (btnList && btnMap && paneResults && paneMap) {
    btnList.addEventListener('click', () => {
      paneResults.classList.remove('mobile-hidden');
      paneMap.classList.add('mobile-hidden');
      btnList.classList.add('btn-crimson');
      btnMap.classList.remove('btn-crimson');
    });
    btnMap.addEventListener('click', () => {
      paneMap.classList.remove('mobile-hidden');
      if (leafletMap) setTimeout(() => leafletMap.invalidateSize(), 80);
      paneResults.classList.add('mobile-hidden');
      btnMap.classList.add('btn-crimson');
      btnList.classList.remove('btn-crimson');
    });
  }

  // Initial Render
  renderCards();
  renderInteractiveMap();
})();
