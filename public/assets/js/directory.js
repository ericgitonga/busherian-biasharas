(function () {
  'use strict';


  const state = {
    businesses: window.INITIAL_BUSINESSES || [],
    sectors: window.INITIAL_SECTORS || [],
    placeHierarchy: window.PLACE_HIERARCHY || {},
    selectedSectorId: '',
    selectedSpecialtyId: '',
    selectedCountry: '',
    selectedCity: '',
    selectedSchool: '',
    openNow: false,
    hasPerk: false,
    b2bReady: false,
    userLat: null,
    userLng: null,
    userAccuracy: null,
    isCustomOrigin: false,
    originKey: 'gps',
    originLabel: 'My Current Browser Location',
    radiusKm: '',
    searchQuery: '',
    shortlistIds: new Set(),
    activeBusiness: null,
    focusedBusinessId: null,
    activeRoute: null, // { biz, fromLat, fromLng, originLabel, data }
  };

  const els = {
    searchInput: document.getElementById('search-q'),
    searchClearBtn: document.getElementById('btn-clear-search'),
    sectorSelect: document.getElementById('filter-sector'),
    specialtySelect: document.getElementById('filter-specialty'),
    countrySelect: document.getElementById('filter-country'),
    citySelect: document.getElementById('filter-city'),
    radiusSelect: document.getElementById('filter-radius'),
    nearMeBtn: document.getElementById('btn-near-me'),
    cardsGrid: document.getElementById('cards-grid'),
    resultsCount: document.getElementById('results-count'),
    mapCanvas: document.getElementById('map-canvas'),
    mapFocusActions: document.getElementById('map-focus-actions'),
    mapRouteDrawer: document.getElementById('map-route-drawer'),
    modalBackdrop: document.getElementById('biz-modal-backdrop'),
    modalContent: document.getElementById('biz-modal-content'),
    shortlistCount: document.getElementById('shortlist-count'),
    shortlistExportBtn: document.getElementById('btn-export-shortlist'),
  };

  function withOutboundAttribution(rawUrl) {
    if (!rawUrl) return '';
    try {
      const u = new URL(rawUrl, window.location.origin);
      if (u.protocol === 'http:' || u.protocol === 'https:') {
        if (!u.searchParams.has('utm_source')) {
          u.searchParams.set('utm_source', 'busherianbiasharas.com');
        }
        if (!u.searchParams.has('utm_medium')) {
          u.searchParams.set('utm_medium', 'referral');
        }
        if (!u.searchParams.has('utm_campaign')) {
          u.searchParams.set('utm_campaign', 'alumni_directory');
        }
        return u.toString();
      }
    } catch (e) {}
    return rawUrl;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function populateCitiesDropdown(countryName) {
    if (!els.citySelect) return;
    els.citySelect.innerHTML = '<option value="">📍 All Cities / Counties / States</option>';
    const places = new Set();
    if (countryName && Array.isArray(state.placeHierarchy[countryName])) {
      state.placeHierarchy[countryName].forEach(p => places.add(p));
    } else if (!countryName) {
      (state.businesses || []).forEach(b => {
        if (b.city_county) places.add(b.city_county);
      });
      if (Array.isArray(state.placeHierarchy['Kenya'])) {
        state.placeHierarchy['Kenya'].slice(0, 12).forEach(p => places.add(p));
      }
    }
    (state.businesses || []).forEach(b => {
      if ((!countryName || String(b.country || 'Kenya').toLowerCase() === String(countryName).toLowerCase()) && b.city_county) {
        places.add(b.city_county);
      }
    });
    Array.from(places).forEach(city => {
      const opt = document.createElement('option');
      opt.value = city;
      opt.textContent = city;
      els.citySelect.appendChild(opt);
    });
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

  function getFocusedBusiness() {
    const pinned = state.businesses.filter(b => b.latitude !== null && b.longitude !== null);
    if (pinned.length === 1) {
      return pinned[0];
    }
    if (state.focusedBusinessId !== null) {
      const found = pinned.find(b => Number(b.id) === Number(state.focusedBusinessId));
      if (found) return found;
    }
    return null;
  }

  async function fetchBusinesses() {
    const params = new URLSearchParams();
    if (state.searchQuery) params.set('q', state.searchQuery);
    if (state.selectedSectorId) params.set('sector_id', state.selectedSectorId);
    if (state.selectedSpecialtyId) params.set('specialty_id', state.selectedSpecialtyId);
    if (state.selectedCountry) params.set('country', state.selectedCountry);
    if (state.selectedCity) params.set('city_county', state.selectedCity);
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
      if (state.activeRoute && !state.businesses.some(b => Number(b.id) === Number(state.activeRoute.biz.id))) {
        state.activeRoute = null;
      }
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
      const distBadge = (biz.distance_km !== null && biz.distance_km !== undefined)
        ? `<span class="badge-distance">📍 ${Number(biz.distance_km).toFixed(1)} km away</span>`
        : `<span style="font-size:0.75rem;color:#6B7280;">📍 ${escapeHtml(biz.area_neighborhood || biz.city_county)}</span>`;
      const hours = biz.hours_status || { state: 'CLOSED', badge_text: 'Closed' };
      const perkHtml = biz.alumni_perk
        ? `<div class="card-Perk">🎁 <strong>Busherian Perk:</strong> ${escapeHtml(biz.alumni_perk)}</div>`
        : '';
      const inShortlist = state.shortlistIds.has(biz.id);
      const hasCoords = biz.latitude !== null && biz.longitude !== null;
      const photos = Array.isArray(biz.photos) ? biz.photos : [];
      const cardPhotosHtml = photos.length > 0 ? `
        <div class="card-photo-strip">
          ${photos.slice(0, 4).map((url, idx) => `<img src="${escapeHtml(url)}" alt="${escapeHtml(biz.name)} photo ${idx + 1}" loading="lazy">`).join('')}
          ${photos.length > 4 ? `<span class="card-photo-more">+${photos.length - 4}</span>` : ''}
        </div>
      ` : '';

      // STRICT PRIVACY GUARANTEE: Zero House, Year Left, or School Number ever rendered
      return `
        <article class="biz-card" data-biz-id="${biz.id}">
          <div>
            ${cardPhotosHtml}
            <div class="card-top-badges" style="justify-content:space-between;">
              ${biz.can_edit ? `<span style="background:#ECFDF5;color:#065F46;border:1px solid #059669;border-radius:999px;padding:0.15rem 0.55rem;font-size:0.7rem;font-weight:800;">✓ Your Linked Business</span>` : '<span></span>'}
              ${distBadge}
            </div>
            <h3 class="card-title">${escapeHtml(biz.name)}</h3>
            <div class="card-taxonomy">${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}</div>
            <p class="card-tagline">${escapeHtml(biz.tagline)}</p>
            ${perkHtml}
          </div>
          <div class="card-footer">
            <span class="status-dot status-${escapeHtml(hours.state)}">● ${escapeHtml(hours.badge_text)}</span>
            <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
              ${hasCoords ? `
                <button type="button" data-route-biz-id="${biz.id}" title="Get distance and directions to ${escapeHtml(biz.name)}" style="background:#FFFBEB;color:#78350F;border:1px solid #D4AF37;border-radius:6px;padding:0.25rem 0.55rem;font-size:0.74rem;font-weight:700;cursor:pointer;">
                  🧭 Directions
                </button>
              ` : ''}
              ${biz.can_edit && biz.edit_url ? `
                <a href="${escapeHtml(biz.edit_url)}" data-edit-biz-link="1" title="Edit ${escapeHtml(biz.name)}" style="background:#114B3E;color:#fff;text-decoration:none;border-radius:6px;padding:0.25rem 0.6rem;font-size:0.74rem;font-weight:700;display:inline-flex;align-items:center;gap:0.25rem;">
                  ✏️ Edit My Business
                </a>
              ` : ''}
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
  let routeLayer = null;
  let mapOverlayBadge = null;

  function renderMapFocusBar() {
    if (!els.mapFocusActions) return;
    const focused = getFocusedBusiness();
    if (!focused) {
      els.mapFocusActions.innerHTML = `<span style="font-size:0.74rem;color:#6B7280;">Click any pin or card to focus</span>`;
      return;
    }
    const shortName = focused.name.length > 22 ? focused.name.slice(0, 20) + '…' : focused.name;
    const showAllBtn = (state.focusedBusinessId !== null || state.activeRoute !== null)
      ? `<button type="button" data-clear-route="1" class="btn btn-outline" style="padding:0.32rem 0.65rem;font-size:0.74rem;background:#fff;">🗺️ Show All Businesses</button>`
      : '';
    els.mapFocusActions.innerHTML = `
      <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
        <button type="button" data-route-biz-id="${focused.id}" class="btn btn-gold" style="padding:0.32rem 0.7rem;font-size:0.76rem;">
          🧭 Distance &amp; Directions: ${escapeHtml(shortName)}
        </button>
        ${showAllBtn}
      </div>
    `;
  }

  function saveCustomOriginToSession(lat, lng, label) {
    state.userLat = Number(lat);
    state.userLng = Number(lng);
    state.userAccuracy = 15;
    state.isCustomOrigin = true;
    state.originLabel = label || 'Custom Starting Location';
    try {
      sessionStorage.setItem('bbiz_custom_origin', JSON.stringify({
        lat: state.userLat,
        lng: state.userLng,
        label: state.originLabel,
      }));
    } catch (_) {}
  }

  function clearCustomOriginFromSession() {
    state.isCustomOrigin = false;
    try {
      sessionStorage.removeItem('bbiz_custom_origin');
    } catch (_) {}
  }

  function loadCustomOriginFromSession() {
    try {
      const raw = sessionStorage.getItem('bbiz_custom_origin');
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed.lat === 'number' && typeof parsed.lng === 'number') {
        state.userLat = parsed.lat;
        state.userLng = parsed.lng;
        state.userAccuracy = 15;
        state.isCustomOrigin = true;
        state.originLabel = parsed.label || 'Custom Starting Location';
        return {
          lat: state.userLat,
          lng: state.userLng,
          accuracy: 15,
          isCustomOrigin: true,
          label: state.originLabel,
        };
      }
    } catch (_) {}
    return null;
  }

  function buildRouteCardHtml(activeRoute) {
    const { biz, fromLat, fromLng, originLabel, accuracy, isCustomOrigin, data } = activeRoute;
    const isCoarseIp = !isCustomOrigin && Number(accuracy || 0) > 400;
    // If laptop returned a coarse ISP location (e.g. Pumwani), let Google Maps determine origin itself unless user set a precise origin
    const gmapsDirUrl = isCoarseIp
      ? `https://www.google.com/maps/dir/?api=1&destination=${biz.latitude},${biz.longitude}&travelmode=driving`
      : `https://www.google.com/maps/dir/?api=1&origin=${fromLat},${fromLng}&destination=${biz.latitude},${biz.longitude}&travelmode=driving`;
    const wazeDirUrl = `https://waze.com/ul?ll=${biz.latitude},${biz.longitude}&navigate=yes`;
    const destCues = [biz.building_name, biz.street_road, biz.area_neighborhood, biz.city_county].filter(Boolean).join(', ');
    const landmarkCue = biz.landmark_directions ? `Landmark: ${biz.landmark_directions}` : '';
    const overviewItems = (data.steps || []).map(st => (
      `<li>${escapeHtml(st.instruction)}${st.distance ? ` <strong style="color:#114B3E;">(${escapeHtml(st.distance)})</strong>` : ''}</li>`
    ));
    if (destCues) {
      overviewItems.push(`<li>Destination area: <strong>${escapeHtml(destCues)}</strong>${landmarkCue ? ` — <em>${escapeHtml(landmarkCue)}</em>` : ''}</li>`);
    }
    const stepsHtml = overviewItems.join('');

    const coarseBanner = isCoarseIp ? `
      <div style="background:#FFFBEB;border:1px solid #F59E0B;border-radius:8px;padding:0.45rem 0.65rem;margin-top:0.45rem;font-size:0.75rem;color:#92400E;">
        ⚠️ <strong>Laptop coarse ISP location detected (±${Math.max(1, Math.round(Number(accuracy) / 1000))} km):</strong> Laptops without GPS hardware often report your ISP's central Nairobi exchange (e.g. Pumwani). Type your street/estate below (e.g. <em>Marist Lane, Karen</em>) or drag the blue pin on the map:
      </div>
    ` : '';

    return `
      <div class="route-summary-row">
        <div>
          <span class="route-metrics-pill">🚗 ~${Number(data.road_km).toFixed(1)} km drive • ~${Number(data.duration_min)} min</span>
          <span style="font-size:0.75rem;color:#4B5563;margin-left:0.35rem;">(${Number(data.straight_km).toFixed(1)} km direct to <strong>${escapeHtml(biz.name)}</strong>)</span>
        </div>
        <button type="button" data-clear-route="1" style="background:none;border:none;color:#7B1113;font-weight:800;font-size:0.76rem;cursor:pointer;">✕ Show All Businesses</button>
      </div>

      ${coarseBanner}

      <form data-origin-search-form="${biz.id}" style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;margin-top:0.5rem;">
        <span style="font-size:0.74rem;font-weight:700;color:#114B3E;">📍 Start (${escapeHtml(originLabel || 'My Location')}):</span>
        <input type="text" name="origin_query" class="input-control" style="flex:1;min-width:190px;padding:0.26rem 0.55rem;font-size:0.75rem;"
          placeholder="Type your street/area (e.g. Marist Lane, Karen) or drag blue pin..."
          value="${isCustomOrigin ? escapeHtml(originLabel) : ''}">
        <button type="submit" class="btn btn-crimson" style="padding:0.26rem 0.6rem;font-size:0.74rem;">Set Start</button>
        <button type="button" data-refresh-gps="${biz.id}" class="btn btn-outline" style="padding:0.26rem 0.55rem;font-size:0.72rem;background:#fff;" title="Capture live browser GPS coordinates">
          🔄 Use GPS
        </button>
        <a href="${escapeHtml(gmapsDirUrl)}" target="_blank" rel="noopener noreferrer" data-track="map_pin" class="btn btn-green" style="padding:0.26rem 0.6rem;font-size:0.74rem;">
          🗺️ Open in Google Maps
        </a>
        <a href="${escapeHtml(wazeDirUrl)}" target="_blank" rel="noopener noreferrer" data-track="map_pin" class="btn btn-outline" style="padding:0.26rem 0.6rem;font-size:0.74rem;background:#fff;">
          🚗 Waze
        </a>
      </form>

      ${stepsHtml ? `
        <div style="margin-top:0.5rem;">
          <div style="font-size:0.76rem;font-weight:700;color:#7B1113;margin-bottom:0.2rem;">General Route Overview</div>
          <ul class="route-steps-list" style="list-style:disc;padding-left:1.15rem;">${stepsHtml}</ul>
        </div>
      ` : ''}
    `;
  }

  function renderRouteDrawerAndModalBox() {
    if (els.mapRouteDrawer) {
      if (state.activeRoute) {
        els.mapRouteDrawer.style.display = 'block';
        els.mapRouteDrawer.innerHTML = buildRouteCardHtml(state.activeRoute);
      } else {
        els.mapRouteDrawer.style.display = 'none';
        els.mapRouteDrawer.innerHTML = '';
      }
    }

    const modalRouteBox = document.getElementById('modal-route-box');
    if (modalRouteBox && state.activeBusiness) {
      if (state.activeRoute && Number(state.activeRoute.biz.id) === Number(state.activeBusiness.id)) {
        modalRouteBox.style.display = 'block';
        modalRouteBox.innerHTML = buildRouteCardHtml(state.activeRoute);
      } else {
        modalRouteBox.style.display = 'none';
        modalRouteBox.innerHTML = '';
      }
    }
  }

  /**
   * Capture the user's real browser location via Geolocation API using watchPosition
   * with maximumAge: 0 so coarse cached ISP IP fixes (e.g. Pumwani on laptops) can be
   * refined by Wi-Fi positioning if available, while preserving any user-specified street origin.
   */
  function captureBrowserLocation(forceRefreshGps) {
    return new Promise((resolve, reject) => {
      if (forceRefreshGps) {
        clearCustomOriginFromSession();
      } else {
        const savedCustom = loadCustomOriginFromSession();
        if (savedCustom) {
          resolve(savedCustom);
          return;
        }
        if (state.userLat !== null && state.userLng !== null && state.userAccuracy !== null && state.userAccuracy <= 150) {
          resolve({
            lat: state.userLat,
            lng: state.userLng,
            accuracy: state.userAccuracy,
            isCustomOrigin: state.isCustomOrigin,
            label: state.originLabel || 'My Current Location',
          });
          return;
        }
      }

      if (!navigator.geolocation) {
        reject(new Error('Browser Geolocation is not supported on this device/browser.'));
        return;
      }

      let bestPos = null;
      let settled = false;
      let watchId = null;
      let settleTimer = null;

      const finishWithPos = (pos) => {
        if (settled) return;
        settled = true;
        if (watchId !== null) {
          try { navigator.geolocation.clearWatch(watchId); } catch (_) {}
        }
        if (settleTimer !== null) clearTimeout(settleTimer);
        state.userLat = pos.coords.latitude;
        state.userLng = pos.coords.longitude;
        state.userAccuracy = Math.round(pos.coords.accuracy || 0);
        state.isCustomOrigin = false;
        state.originLabel = state.userAccuracy > 400 ? 'Browser ISP Estimate' : 'My Current GPS Location';
        resolve({
          lat: state.userLat,
          lng: state.userLng,
          accuracy: state.userAccuracy,
          isCustomOrigin: false,
          label: state.originLabel,
        });
      };

      watchId = navigator.geolocation.watchPosition(
        (pos) => {
          if (!bestPos || (pos.coords.accuracy && pos.coords.accuracy < bestPos.coords.accuracy)) {
            bestPos = pos;
          }
          // If mobile GPS or accurate Wi-Fi BSSID fix (<= 150m), resolve immediately
          if (pos.coords.accuracy && pos.coords.accuracy <= 150) {
            finishWithPos(pos);
          } else if (settleTimer === null) {
            // Coarse initial reading (e.g. laptop ISP centroid): wait up to 2.0s for a finer Wi-Fi scan fix
            settleTimer = setTimeout(() => {
              if (bestPos) finishWithPos(bestPos);
            }, 2000);
          }
        },
        (err) => {
          if (bestPos) {
            finishWithPos(bestPos);
            return;
          }
          if (watchId !== null) {
            try { navigator.geolocation.clearWatch(watchId); } catch (_) {}
          }
          if (err && err.code === 1) {
            reject(new Error('Browser location access was blocked. Type your street/estate below (e.g. Marist Lane, Karen) or allow location access in your browser.'));
            return;
          }
          navigator.geolocation.getCurrentPosition(
            (pos2) => finishWithPos(pos2),
            () => reject(new Error('Could not determine your location automatically. Type your street/estate below (e.g. Marist Lane, Karen).')),
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 0 }
          );
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
      );
    });
  }

  async function calculateRouteFromOrigin(biz, origin) {
    const qs = new URLSearchParams({
      from_lat: String(origin.lat),
      from_lng: String(origin.lng),
      to_lat: String(biz.latitude),
      to_lng: String(biz.longitude),
    });
    const res = await fetch('/api/route-directions.php?' + qs.toString());
    const json = await res.json();
    if (json && json.status === 'ok' && json.route) {
      state.activeRoute = {
        biz,
        fromLat: origin.lat,
        fromLng: origin.lng,
        originLabel: origin.label,
        accuracy: origin.accuracy || 0,
        isCustomOrigin: Boolean(origin.isCustomOrigin),
        data: json.route,
      };
      trackClick(biz.id, 'map_pin');
      if (window.BBizMetrics) window.BBizMetrics.track('route_request', { business_id: biz.id, meta_label: biz.name });
      renderRouteDrawerAndModalBox();
      renderInteractiveMap();
    }
  }

  async function geocodeAndSetCustomOrigin(bizId, queryText) {
    const biz = state.businesses.find(b => Number(b.id) === Number(bizId));
    if (!biz || !queryText.trim()) return;
    const loadingHtml = `<div style="font-weight:700;color:#114B3E;">📍 Locating "${escapeHtml(queryText.trim())}" &amp; calculating route to ${escapeHtml(biz.name)}...</div>`;
    if (els.mapRouteDrawer) {
      els.mapRouteDrawer.style.display = 'block';
      els.mapRouteDrawer.innerHTML = loadingHtml;
    }
    const modalRouteBox = document.getElementById('modal-route-box');
    if (modalRouteBox) {
      modalRouteBox.style.display = 'block';
      modalRouteBox.innerHTML = loadingHtml;
    }
    try {
      const res = await fetch('/api/geocode.php?q=' + encodeURIComponent(queryText.trim()));
      const json = await res.json();
      if (!res.ok || !json || json.status !== 'ok' || !json.location) {
        throw new Error((json && json.message) || 'Could not find that street or area.');
      }
      saveCustomOriginToSession(json.location.latitude, json.location.longitude, json.location.label);
      await calculateRouteFromOrigin(biz, {
        lat: json.location.latitude,
        lng: json.location.longitude,
        accuracy: 15,
        isCustomOrigin: true,
        label: json.location.label,
      });
    } catch (err) {
      alert(err.message || 'Could not find that street or area. Try adding the neighborhood (e.g. "Marist Lane, Karen").');
      renderRouteDrawerAndModalBox();
    }
  }

  async function requestDistanceAndDirections(bizId, forceRefreshGps) {
    const biz = state.businesses.find(b => Number(b.id) === Number(bizId));
    if (!biz || biz.latitude === null || biz.longitude === null) return;

    // Isolate this specific business on the map immediately
    state.focusedBusinessId = biz.id;
    renderInteractiveMap();

    const loadingHtml = `<div style="font-weight:700;color:#114B3E;">📍 Getting your location &amp; calculating route to ${escapeHtml(biz.name)}...</div>`;
    if (els.mapRouteDrawer) {
      els.mapRouteDrawer.style.display = 'block';
      els.mapRouteDrawer.innerHTML = loadingHtml;
    }
    const modalRouteBox = document.getElementById('modal-route-box');
    if (modalRouteBox) {
      modalRouteBox.style.display = 'block';
      modalRouteBox.innerHTML = loadingHtml;
    }

    let origin;
    try {
      origin = await captureBrowserLocation(Boolean(forceRefreshGps));
    } catch (geoErr) {
      const gmapsDirect = `https://www.google.com/maps/dir/?api=1&destination=${biz.latitude},${biz.longitude}&travelmode=driving`;
      const errHtml = `
        <div style="color:#991B1B;font-weight:600;font-size:0.82rem;margin-bottom:0.45rem;">
          ⚠️ ${escapeHtml(geoErr.message || 'Please enter your starting street/estate below or allow browser location access.')}
        </div>
        <form data-origin-search-form="${biz.id}" style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
          <input type="text" name="origin_query" class="input-control" style="flex:1;min-width:190px;padding:0.28rem 0.55rem;font-size:0.75rem;"
            placeholder="Type your street/area (e.g. Marist Lane, Karen)..." required>
          <button type="submit" class="btn btn-crimson" style="padding:0.28rem 0.65rem;font-size:0.75rem;">📍 Set Start</button>
          <button type="button" data-refresh-gps="${biz.id}" class="btn btn-gold" style="padding:0.28rem 0.65rem;font-size:0.75rem;">🔄 Retry GPS</button>
          <a href="${escapeHtml(gmapsDirect)}" target="_blank" rel="noopener noreferrer" class="btn btn-green" style="padding:0.28rem 0.65rem;font-size:0.75rem;">🗺️ Open in Google Maps</a>
          <button type="button" data-clear-route="1" class="btn btn-outline" style="padding:0.28rem 0.65rem;font-size:0.75rem;background:#fff;">✕ Show All Businesses</button>
        </form>
      `;
      if (els.mapRouteDrawer) els.mapRouteDrawer.innerHTML = errHtml;
      if (modalRouteBox) modalRouteBox.innerHTML = errHtml;
      return;
    }

    try {
      await calculateRouteFromOrigin(biz, origin);
    } catch (_) {
      if (els.mapRouteDrawer) {
        els.mapRouteDrawer.innerHTML = `<div style="color:#991B1B;font-weight:600;">Could not calculate route right now. Try opening Google Maps directly.</div>`;
      }
    }
  }

  /**
   * Real Interactive Spatial Map powered by self-hosted Leaflet + OpenStreetMap Road & Feature Tiles.
   * When a specific business is selected (Directions or Focus), ONLY that business is shown on the map;
   * all other businesses are blanked out until returned to the generic directory view.
   */
  function renderInteractiveMap() {
    renderMapFocusBar();
    if (!els.mapCanvas) return;
    const allPinned = state.businesses.filter(b => b.latitude !== null && b.longitude !== null);

    // Isolate the single selected business on the map whenever a specific business is selected or routed
    const isolatedBizId = state.activeRoute
      ? Number(state.activeRoute.biz.id)
      : (state.focusedBusinessId !== null ? Number(state.focusedBusinessId) : null);

    const visiblePinned = isolatedBizId !== null
      ? allPinned.filter(b => Number(b.id) === isolatedBizId)
      : allPinned;

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

      routeLayer = window.L.layerGroup().addTo(leafletMap);
      markersLayer = window.L.layerGroup().addTo(leafletMap);

      mapOverlayBadge = document.createElement('div');
      mapOverlayBadge.style.cssText = 'position:absolute;top:10px;right:12px;background:rgba(255,255,255,0.95);padding:5px 11px;border-radius:8px;font-size:0.74rem;font-weight:700;color:#114B3E;border:1px solid #D1D5DB;z-index:1000;box-shadow:0 2px 6px rgba(0,0,0,0.12);pointer-events:none;';
      els.mapCanvas.appendChild(mapOverlayBadge);
    }

    markersLayer.clearLayers();
    routeLayer.clearLayers();

    if (mapOverlayBadge) {
      if (isolatedBizId !== null && visiblePinned.length === 1) {
        mapOverlayBadge.textContent = `📍 Showing only: ${visiblePinned[0].name}`;
      } else {
        mapOverlayBadge.textContent = `📍 ${visiblePinned.length} ${visiblePinned.length === 1 ? 'Location' : 'Locations'} • Click any pin for details & directions`;
      }
    }

    const boundsPoints = [];
    const focused = getFocusedBusiness();

    if (state.userLat !== null && state.userLng !== null && !state.activeRoute) {
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

    visiblePinned.forEach((b) => {
      const isOpen = b.hours_status && b.hours_status.is_open;
      const bg = isOpen ? '#114B3E' : '#7B1113';
      const label = b.name.length > 24 ? b.name.slice(0, 22) + '…' : b.name;
      const area = b.area_neighborhood || b.city_county || '';
      const isFocused = focused && Number(focused.id) === Number(b.id);

      const icon = window.L.divIcon({
        className: 'bbiz-leaflet-pin',
        html: `<div class="bbiz-pin-pill ${isFocused ? 'bbiz-pin-focused' : ''}" style="background:${bg};" title="${escapeHtml(b.name)} — ${escapeHtml(area)}">📍 ${escapeHtml(label)}</div>`,
        iconSize: [0, 0],
        iconAnchor: [0, 0],
      });

      const marker = window.L.marker([b.latitude, b.longitude], { icon }).addTo(markersLayer);
      marker.on('click', () => {
        state.focusedBusinessId = b.id;
        renderInteractiveMap();
        openBusinessModal(b.id);
      });
      if (!state.activeRoute) {
        boundsPoints.push([b.latitude, b.longitude]);
      }
    });

    if (state.activeRoute && Array.isArray(state.activeRoute.data.polyline) && state.activeRoute.data.polyline.length >= 2) {
      const polyPts = state.activeRoute.data.polyline;
      // Gold outer casing + Crimson inner route line
      window.L.polyline(polyPts, { color: '#D4AF37', weight: 8, opacity: 0.85 }).addTo(routeLayer);
      const mainLine = window.L.polyline(polyPts, { color: '#7B1113', weight: 4.5, opacity: 0.95 }).addTo(routeLayer);

      const startIcon = window.L.divIcon({
        className: 'bbiz-start-pin',
        html: '<div style="width:20px;height:20px;border-radius:50%;background:#2563EB;border:3px solid #ffffff;box-shadow:0 2px 6px rgba(0,0,0,0.35);cursor:grab;" title="Drag to adjust your exact starting location"></div>',
        iconSize: [20, 20],
        iconAnchor: [10, 10],
      });
      const startMarker = window.L.marker([state.activeRoute.fromLat, state.activeRoute.fromLng], {
        icon: startIcon,
        draggable: true,
      }).addTo(routeLayer);
      startMarker.bindTooltip(`Start: ${state.activeRoute.originLabel} (Drag blue dot to adjust)`, { permanent: false, direction: 'top' });
      startMarker.on('dragend', async (ev) => {
        const pos = ev.target.getLatLng();
        if (pos && state.activeRoute && state.activeRoute.biz) {
          const customLbl = `Pinned on Map (${pos.lat.toFixed(4)}, ${pos.lng.toFixed(4)})`;
          saveCustomOriginToSession(pos.lat, pos.lng, customLbl);
          await calculateRouteFromOrigin(state.activeRoute.biz, {
            lat: pos.lat,
            lng: pos.lng,
            accuracy: 10,
            isCustomOrigin: true,
            label: customLbl,
          });
        }
      });

      leafletMap.fitBounds(mainLine.getBounds(), { padding: [50, 50], maxZoom: 16, animate: true });
      return;
    }

    if (boundsPoints.length === 1) {
      leafletMap.setView(boundsPoints[0], 15, { animate: true });
    } else if (boundsPoints.length > 1) {
      const bounds = window.L.latLngBounds(boundsPoints);
      leafletMap.fitBounds(bounds, { padding: [45, 45], maxZoom: 15, animate: true });
    }
  }

  function trackClick(businessId, eventType) {
    if (!window.BBizMetrics || !window.BBizMetrics.hasConsent()) return;
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
    state.focusedBusinessId = biz.id;
    if (window.BBizMetrics) window.BBizMetrics.track('modal_open', { business_id: biz.id, meta_label: biz.name });
    renderInteractiveMap();

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
      <a href="${escapeHtml(withOutboundAttribution(url))}" target="_blank" rel="noopener" class="btn btn-outline" style="font-size:0.8rem;padding:0.4rem 0.75rem;">
        ${escapeHtml(label)} &nearr;
      </a>
    `).join('');

    const schoolLabel = biz.school_badge === 'BOTH' ? 'AHS & AGHS' : `${biz.school_badge}`;
    const hasCoords = biz.latitude !== null && biz.longitude !== null;
    const modalPhotos = Array.isArray(biz.photos) ? biz.photos : [];
    const galleryHtml = modalPhotos.length > 0 ? `
      <div class="biz-photo-carousel" data-carousel-index="0">
        <div class="carousel-stage">
          <img id="modal-carousel-main-img" src="${escapeHtml(modalPhotos[0])}" alt="${escapeHtml(biz.name)} photo 1">
          ${modalPhotos.length > 1 ? `
            <button type="button" class="carousel-nav prev" data-carousel-step="-1" aria-label="Previous photo">&lsaquo;</button>
            <button type="button" class="carousel-nav next" data-carousel-step="1" aria-label="Next photo">&rsaquo;</button>
            <span id="modal-carousel-counter" class="carousel-counter">1 / ${modalPhotos.length}</span>
          ` : ''}
        </div>
        ${modalPhotos.length > 1 ? `
          <div class="carousel-thumbs">
            ${modalPhotos.map((u, idx) => `
              <button type="button" class="carousel-thumb ${idx === 0 ? 'active' : ''}" data-carousel-goto="${idx}">
                <img src="${escapeHtml(u)}" alt="Thumbnail ${idx + 1}">
              </button>
            `).join('')}
          </div>
        ` : ''}
      </div>
    ` : '';

    els.modalContent.innerHTML = `
      <div class="modal-header">
        <div>
          <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:0.4rem;">
            <span class="status-dot status-${escapeHtml(hours.state)}">● ${escapeHtml(hours.badge_text)}</span>
            ${biz.b2b_ready ? '<span class="badge-distance">🏢 B2B & LPO Ready</span>' : ''}
            ${biz.regulator_name ? `<span class="badge-distance">🛡️ ${escapeHtml(biz.regulator_name)}${biz.regulator_license ? ': ' + escapeHtml(biz.regulator_license) : ''}</span>` : ''}
          </div>
          <h2 style="margin:0;font-size:1.4rem;color:#111827;">${escapeHtml(biz.name)}</h2>
          <div style="font-size:0.86rem;font-weight:700;color:#7B1113;margin-top:0.2rem;">
            ${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}
          </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
          ${biz.can_edit && biz.edit_url ? `<a href="${escapeHtml(biz.edit_url)}" class="btn btn-green" style="font-size:0.82rem;padding:0.4rem 0.85rem;">✏️ Edit My Business</a>` : ''}
          <button type="button" id="btn-close-modal" class="btn btn-outline" aria-label="Close modal">✕ Close</button>
        </div>
      </div>
      <div class="modal-body">
        ${galleryHtml}
        <p style="margin-top:0;color:#374151;font-size:0.95rem;">${escapeHtml(biz.description)}</p>

        ${biz.alumni_perk ? `
          <div class="card-Perk" style="font-size:0.88rem;padding:0.65rem 0.9rem;">
            🎁 <strong>Verified Busherian Alumni Offer:</strong> ${escapeHtml(biz.alumni_perk)}
          </div>
        ` : ''}

        <div class="cta-grid">
          ${biz.website_url ? `<a href="${escapeHtml(withOutboundAttribution(biz.website_url))}" target="_blank" rel="noopener" data-track="website" class="btn btn-crimson">🌐 Visit Website &nearr;</a>` : ''}
          ${biz.phone_primary ? `<a href="tel:${escapeHtml(biz.phone_primary)}" data-track="call" class="btn btn-green">📞 Call ${escapeHtml(biz.phone_primary)}</a>` : ''}
          ${waUrl ? `<a href="${escapeHtml(waUrl)}" target="_blank" rel="noopener noreferrer" data-track="whatsapp" class="btn btn-gold">💬 WhatsApp Now</a>` : ''}
          ${biz.email_public ? `<a href="mailto:${escapeHtml(biz.email_public)}" data-track="email" class="btn btn-outline">✉️ Email Business</a>` : ''}
        </div>

        ${socialBadges ? `
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.6rem;">
            ${socialBadges}
          </div>
        ` : ''}

        <div id="modal-route-box" class="map-route-drawer" style="display:none;border-radius:10px;border:1px solid #D4AF37;margin-top:1rem;"></div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.15rem;margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #E5E7EB;">
          <div>
            <h4 style="margin:0 0 0.5rem 0;color:#114B3E;font-size:0.9rem;">📍 Physical Location & Navigation Pin</h4>
            <p style="margin:0 0 0.35rem 0;font-size:0.88rem;color:#111827;">
              <strong>${escapeHtml(biz.building_name || biz.branch_name || biz.name)}</strong>
              ${biz.floor_suite ? `, ${escapeHtml(biz.floor_suite)}` : ''}
            </p>
            <p style="margin:0 0 0.35rem 0;font-size:0.85rem;color:#4B5563;">
              ${escapeHtml([biz.street_road, biz.area_neighborhood, biz.city_county, (biz.country && biz.country !== 'Kenya') ? biz.country : ''].filter(Boolean).join(', '))}
            </p>
            ${biz.landmark_directions ? `<p style="margin:0 0 0.5rem 0;font-size:0.8rem;color:#6B7280;"><em>Landmark: ${escapeHtml(biz.landmark_directions)}</em></p>` : ''}
            ${biz.plus_code ? `<p style="margin:0 0 0.65rem 0;font-size:0.78rem;color:#114B3E;font-family:monospace;">Plus Code: ${escapeHtml(biz.plus_code)}</p>` : ''}
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
              ${hasCoords ? `
                <button type="button" data-route-biz-id="${biz.id}" class="btn btn-gold" style="font-size:0.8rem;">
                  🧭 Get Distance &amp; Directions
                </button>
              ` : ''}
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

        <div style="margin-top:1.15rem;padding-top:0.85rem;border-top:1px solid #F3F4F6;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.65rem;font-size:0.76rem;color:#6B7280;">
          <span>Verified Busherian Biashara</span>
          <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
            ${biz.can_edit && biz.edit_url
              ? `<a href="${escapeHtml(biz.edit_url)}" style="color:#114B3E;font-weight:800;">✏️ Edit My Business Listing</a>`
              : `<a href="/owner/login.php?biz=${biz.id}" style="color:#114B3E;font-weight:700;">👤 Own this business? Sign in to edit</a>`}
            <a href="/takedown.php?business_id=${biz.id}" style="color:#7B1113;font-weight:600;">Report / DPA Takedown</a>
          </div>
        </div>
      </div>
    `;

    els.modalBackdrop.classList.add('open');
    renderRouteDrawerAndModalBox();
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
      b.regulator_name ? (b.regulator_license ? `${b.regulator_name} (${b.regulator_license})` : b.regulator_name) : '',
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
      timer = setTimeout(() => { if (state.searchQuery.length >= 2 && window.BBizMetrics) window.BBizMetrics.track('search', { meta_label: state.searchQuery }); fetchBusinesses(); }, 220);
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

  if (els.countrySelect) {
    populateCitiesDropdown('');
    els.countrySelect.addEventListener('change', (e) => {
      state.selectedCountry = e.target.value;
      state.selectedCity = '';
      populateCitiesDropdown(state.selectedCountry);
      fetchBusinesses();
    });
  }

  if (els.citySelect) {
    els.citySelect.addEventListener('change', (e) => {
      state.selectedCity = e.target.value;
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
    els.nearMeBtn.addEventListener('click', async () => {
      els.nearMeBtn.textContent = '📍 Locating...';
      try {
        await captureBrowserLocation(true);
        els.nearMeBtn.textContent = '📍 Near Me (Active)';
        els.nearMeBtn.classList.add('active');
        fetchBusinesses();
      } catch (err) {
        els.nearMeBtn.textContent = '📍 Near Me';
        alert(err.message || 'Please allow browser location access to filter businesses near you.');
      }
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
        state.selectedCountry = '';
        state.selectedCity = '';
        if (els.countrySelect) els.countrySelect.value = '';
        if (els.citySelect) {
          populateCitiesDropdown('');
          els.citySelect.value = '';
        }
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
      if (window.BBizMetrics) window.BBizMetrics.track('filter', { meta_label: f });
      fetchBusinesses();
    });
  });

  document.addEventListener('submit', (e) => {
    const originForm = e.target.closest('[data-origin-search-form]');
    if (originForm) {
      e.preventDefault();
      const bizId = Number(originForm.getAttribute('data-origin-search-form'));
      const input = originForm.querySelector('input[name="origin_query"]');
      if (input && input.value.trim() !== '') {
        geocodeAndSetCustomOrigin(bizId, input.value.trim());
      }
    }
  });

  document.addEventListener('click', (e) => {
    const carouselStepBtn = e.target.closest('[data-carousel-step]');
    if (carouselStepBtn && state.activeBusiness && Array.isArray(state.activeBusiness.photos)) {
      e.stopPropagation();
      const photos = state.activeBusiness.photos;
      const wrap = document.querySelector('.biz-photo-carousel');
      if (wrap && photos.length > 1) {
        const cur = Number(wrap.getAttribute('data-carousel-index') || 0);
        const step = Number(carouselStepBtn.getAttribute('data-carousel-step') || 1);
        const nextIdx = (cur + step + photos.length) % photos.length;
        wrap.setAttribute('data-carousel-index', String(nextIdx));
        const mainImg = document.getElementById('modal-carousel-main-img');
        if (mainImg) mainImg.src = photos[nextIdx];
        const counter = document.getElementById('modal-carousel-counter');
        if (counter) counter.textContent = `${nextIdx + 1} / ${photos.length}`;
        wrap.querySelectorAll('.carousel-thumb').forEach((th, i) => th.classList.toggle('active', i === nextIdx));
      }
      return;
    }

    const carouselGotoBtn = e.target.closest('[data-carousel-goto]');
    if (carouselGotoBtn && state.activeBusiness && Array.isArray(state.activeBusiness.photos)) {
      e.stopPropagation();
      const photos = state.activeBusiness.photos;
      const nextIdx = Number(carouselGotoBtn.getAttribute('data-carousel-goto') || 0);
      const wrap = document.querySelector('.biz-photo-carousel');
      if (wrap && photos[nextIdx]) {
        wrap.setAttribute('data-carousel-index', String(nextIdx));
        const mainImg = document.getElementById('modal-carousel-main-img');
        if (mainImg) mainImg.src = photos[nextIdx];
        const counter = document.getElementById('modal-carousel-counter');
        if (counter) counter.textContent = `${nextIdx + 1} / ${photos.length}`;
        wrap.querySelectorAll('.carousel-thumb').forEach((th, i) => th.classList.toggle('active', i === nextIdx));
      }
      return;
    }

    const refreshGpsBtn = e.target.closest('[data-refresh-gps]');
    if (refreshGpsBtn) {
      e.stopPropagation();
      const id = Number(refreshGpsBtn.getAttribute('data-refresh-gps'));
      requestDistanceAndDirections(id, true);
      return;
    }

    const clearRouteBtn = e.target.closest('[data-clear-route]');
    if (clearRouteBtn) {
      e.stopPropagation();
      state.activeRoute = null;
      state.focusedBusinessId = null;
      renderRouteDrawerAndModalBox();
      renderInteractiveMap();
      return;
    }

    const routeBtn = e.target.closest('[data-route-biz-id]');
    if (routeBtn) {
      e.stopPropagation();
      const id = Number(routeBtn.getAttribute('data-route-biz-id'));
      requestDistanceAndDirections(id);
      return;
    }

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

    const editBizLink = e.target.closest('[data-edit-biz-link]');
    if (editBizLink) {
      e.stopPropagation();
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
      state.activeBusiness = null;
      if (!state.activeRoute) {
        state.focusedBusinessId = null;
        renderInteractiveMap();
      }
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
