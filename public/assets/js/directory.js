(function () {
  'use strict';


  const initialBusinesses = Array.isArray(window.INITIAL_BUSINESSES) ? window.INITIAL_BUSINESSES : [];
  const initialPagination = (window.INITIAL_PAGINATION && typeof window.INITIAL_PAGINATION === 'object')
    ? window.INITIAL_PAGINATION
    : {
        page: 1,
        per_page: 10,
        total: initialBusinesses.length,
        total_pages: Math.max(1, Math.ceil(initialBusinesses.length / 10)),
      };

  const state = {
    businesses: initialBusinesses,
    pagination: initialPagination,
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
    bookable: false,
    userLat: null,
    userLng: null,
    userAccuracy: null,
    isCustomOrigin: false,
    originKey: 'gps',
    originLabel: 'My Current Browser Location',
    radiusKm: '',
    searchQuery: '',
    shortlistIds: new Set(),
    shortlistedRecords: new Map(),
    activeBusiness: null,
    focusedBusinessId: null,
    activeRoute: null, // { biz, fromLat, fromLng, originLabel, data }
    isLoading: false,
    fetchError: '',
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

  // Security (#186): Strictly enforce explicit http:// or https:// absolute URLs; reject javascript:, data:, vbscript:, or malformed inputs.
  function sanitizeHttpUrl(rawUrl) {
    if (typeof rawUrl !== 'string') return '';
    const trimmed = rawUrl.trim();
    if (!trimmed || !/^https?:\/\//i.test(trimmed)) return '';
    try {
      const u = new URL(trimmed);
      if (u.protocol === 'http:' || u.protocol === 'https:') {
        return u.toString();
      }
    } catch (_) {}
    return '';
  }

  function withOutboundAttribution(rawUrl) {
    const clean = sanitizeHttpUrl(rawUrl);
    if (!clean) return '';
    try {
      const u = new URL(clean);
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
    } catch (_) {
      return '';
    }
  }

  function withBookingAttribution(rawUrl) {
    const clean = sanitizeHttpUrl(rawUrl);
    if (!clean) return '';
    try {
      const u = new URL(clean);
      if (!u.searchParams.has('utm_source')) {
        u.searchParams.set('utm_source', 'busherianbiasharas.com');
      }
      if (!u.searchParams.has('utm_medium')) {
        u.searchParams.set('utm_medium', 'referral');
      }
      if (!u.searchParams.has('utm_campaign')) {
        u.searchParams.set('utm_campaign', 'appointment_booking');
      }
      return u.toString();
    } catch (_) {
      return '';
    }
  }

  function getTodayEatIsoDate() {
    try {
      return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Africa/Nairobi',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      }).format(new Date());
    } catch (_) {
      return new Date().toISOString().slice(0, 10);
    }
  }

  function hasMapTileConsent() {
    // Unbundled consent (#217 M-09): "Allow for Analysis" NEVER enables third-party OpenStreetMap tiles.
    // Map tiles load ONLY after explicit click on #btn-enable-osm-map ('bbiz_map_tiles_enabled' === 'yes').
    try {
      return localStorage.getItem('bbiz_map_tiles_enabled') === 'yes';
    } catch (_) {
      return false;
    }
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

  function pluralizeUnitLabel(unitLabel) {
    if (!unitLabel) return 'Counties / States / Districts';
    const map = {
      'County': 'Counties',
      'State': 'States',
      'District': 'Districts',
      'Province': 'Provinces',
      'Region': 'Regions',
      'Governorate': 'Governorates',
      'Prefecture': 'Prefectures',
      'Parish': 'Parishes',
      'Emirate': 'Emirates',
      'Canton': 'Cantons',
      'Municipality': 'Municipalities',
      'Department': 'Departments',
      'Division': 'Divisions',
      'Province / Territory': 'Provinces & Territories',
      'State / Territory': 'States & Territories',
      'State / Union Territory': 'States & Union Territories',
      'County / Council': 'Counties & Councils'
    };
    return map[unitLabel] || (unitLabel + 's');
  }

  function populateCitiesDropdown(countryName) {
    if (!els.citySelect) return;
    const unitLabels = window.PLACE_UNIT_LABELS || {};
    const unitPlural = (countryName && unitLabels[countryName])
      ? pluralizeUnitLabel(unitLabels[countryName])
      : 'Counties / States / Districts';
    els.citySelect.innerHTML = `<option value="">📍 All ${escapeHtml(unitPlural)}</option>`;
    const places = new Set();
    if (countryName && Array.isArray(state.placeHierarchy[countryName])) {
      state.placeHierarchy[countryName].forEach(p => places.add(p));
    } else if (!countryName) {
      if (Array.isArray(state.placeHierarchy['Kenya'])) {
        state.placeHierarchy['Kenya'].forEach(p => places.add(p));
      }
      (state.businesses || []).forEach(b => {
        if (b.city_county) places.add(b.city_county);
      });
    }
    (state.businesses || []).forEach(b => {
      if ((!countryName || String(b.country || 'Kenya').toLowerCase() === String(countryName).toLowerCase()) && b.city_county) {
        places.add(b.city_county);
      }
    });
    Array.from(places).sort((a, b) => a.localeCompare(b)).forEach(city => {
      const opt = document.createElement('option');
      opt.value = city;
      opt.textContent = city;
      els.citySelect.appendChild(opt);
    });
  }

  function populateSpecialtiesDropdown(sectorId) {
    if (!els.specialtySelect) return;
    els.specialtySelect.innerHTML = '<option value="">All Specialties</option>';
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

  const ALLOWED_PAGE_SIZES = [10, 25, 50, 100];
  state.currentPage = Number(state.pagination.page) || 1;
  state.perPage = ALLOWED_PAGE_SIZES.includes(Number(state.pagination.per_page)) ? Number(state.pagination.per_page) : 10;

  let activeFetchController = null;
  let latestRequestSeq = 0;

  let offlineCatalogCache = Array.isArray(initialBusinesses) ? initialBusinesses.slice() : [];

  function setOfflineBannerVisible(visible) {
    const banner = document.getElementById('pwa-offline-banner');
    if (!banner) return;
    banner.hidden = !visible;
  }

  function filterBusinessesClientSide(pool) {
    const list = Array.isArray(pool) ? pool : [];
    const qLower = (state.searchQuery || '').trim().toLowerCase();
    return list.filter((b) => {
      if (state.selectedSectorId) {
        const secMatch = String(b.sector_id) === String(state.selectedSectorId)
          || (b.secondary_sector_id && String(b.secondary_sector_id) === String(state.selectedSectorId));
        if (!secMatch) return false;
      }
      if (state.selectedSpecialtyId) {
        const specMatch = String(b.specialty_id) === String(state.selectedSpecialtyId)
          || (b.secondary_specialty_id && String(b.secondary_specialty_id) === String(state.selectedSpecialtyId));
        if (!specMatch) return false;
      }
      if (state.selectedCountry && String(b.country || 'Kenya').toLowerCase() !== String(state.selectedCountry).toLowerCase()) {
        return false;
      }
      if (state.selectedCity && String(b.city_county || '').toLowerCase() !== String(state.selectedCity).toLowerCase()) {
        return false;
      }
      if (state.selectedSchool === 'AHS' && b.school_badge !== 'AHS' && b.school_badge !== 'BOTH') {
        return false;
      }
      if (state.selectedSchool === 'AGHS' && b.school_badge !== 'AGHS' && b.school_badge !== 'BOTH') {
        return false;
      }
      if (state.openNow && (!b.hours_status || b.hours_status.state !== 'OPEN')) {
        return false;
      }
      if (state.hasPerk && !b.alumni_perk) {
        return false;
      }
      if (state.b2bReady && !b.b2b_ready && !b.accepts_lpo) {
        return false;
      }
      if (state.bookable && !b.is_bookable && !b.booking_enabled && !b.booking_url) {
        return false;
      }
      if (qLower) {
        const hay = [
          b.name,
          b.tagline,
          b.description,
          b.sector_name,
          b.specialty_name,
          b.secondary_sector_name,
          b.secondary_specialty_name,
          b.area_neighborhood,
          b.city_county,
          b.street_road,
          b.building_name,
          b.alumni_perk,
        ].filter(Boolean).join(' ').toLowerCase();
        if (!hay.includes(qLower)) return false;
      }
      return true;
    });
  }

  async function fetchBusinesses(options) {
    const opts = options || {};
    if (opts.resetPage !== false) {
      state.currentPage = 1;
    }

    if (activeFetchController && typeof activeFetchController.abort === 'function') {
      try { activeFetchController.abort(); } catch (_) {}
    }
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    activeFetchController = controller;
    const reqSeq = ++latestRequestSeq;

    state.isLoading = true;
    state.fetchError = '';
    if (els.cardsGrid) {
      els.cardsGrid.setAttribute('aria-busy', 'true');
    }
    if (els.resultsCount) {
      els.resultsCount.textContent = 'Updating Busherian Biasharas…';
    }

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
    if (state.bookable) params.set('bookable', '1');
    if (state.userLat !== null && state.userLng !== null) {
      params.set('lat', String(state.userLat));
      params.set('lng', String(state.userLng));
      if (state.radiusKm) params.set('radius_km', String(state.radiusKm));
    }
    params.set('page', String(state.currentPage));
    params.set('per_page', String(state.perPage));

    try {
      const res = await fetch('/api/businesses.php?' + params.toString(), controller ? { signal: controller.signal } : undefined);
      if (reqSeq !== latestRequestSeq) return;
      if (!res.ok) {
        throw new Error(`Directory request failed (HTTP ${res.status}).`);
      }
      const isOfflineSnapshot = res.headers.get('X-BBiz-Offline-Snapshot') === '1';
      const data = await res.json();
      if (reqSeq !== latestRequestSeq) return;
      if (!data || data.status !== 'ok') {
        throw new Error((data && data.message) || 'Could not load directory listings.');
      }

      state.isLoading = false;
      state.fetchError = '';
      if (isOfflineSnapshot) {
        const rawPool = Array.isArray(data.businesses) ? data.businesses : [];
        if (rawPool.length > offlineCatalogCache.length) {
          offlineCatalogCache = rawPool.slice();
        }
        const filtered = filterBusinessesClientSide(rawPool);
        const totalFiltered = filtered.length;
        const totalPages = Math.max(1, Math.ceil(totalFiltered / state.perPage));
        state.currentPage = Math.min(state.currentPage, totalPages);
        const startOffset = (state.currentPage - 1) * state.perPage;
        state.businesses = filtered.slice(startOffset, startOffset + state.perPage);
        state.pagination = {
          page: state.currentPage,
          per_page: state.perPage,
          total: totalFiltered,
          total_pages: totalPages,
        };
        setOfflineBannerVisible(true);
      } else {
        state.businesses = Array.isArray(data.businesses) ? data.businesses : [];
        if (!state.searchQuery && !state.selectedSectorId && !state.selectedSpecialtyId && state.currentPage === 1 && state.businesses.length >= offlineCatalogCache.length) {
          offlineCatalogCache = state.businesses.slice();
        }
        const pag = (data.pagination && typeof data.pagination === 'object')
          ? data.pagination
          : {
              page: state.currentPage,
              per_page: state.perPage,
              total: typeof data.total_count === 'number' ? data.total_count : state.businesses.length,
              total_pages: Math.max(1, Math.ceil((typeof data.total_count === 'number' ? data.total_count : state.businesses.length) / state.perPage)),
            };
        state.pagination = pag;
        state.currentPage = Number(pag.page) || 1;
        state.perPage = ALLOWED_PAGE_SIZES.includes(Number(pag.per_page)) ? Number(pag.per_page) : state.perPage;
        setOfflineBannerVisible(!navigator.onLine);
      }

      state.businesses.forEach(b => {
        if (state.shortlistIds.has(b.id)) {
          state.shortlistedRecords.set(b.id, b);
        }
      });

      if (state.activeRoute && !state.businesses.some(b => Number(b.id) === Number(state.activeRoute.biz.id))) {
        state.activeRoute = null;
      }
      if (els.cardsGrid) {
        els.cardsGrid.removeAttribute('aria-busy');
      }
      renderCards();
      renderInteractiveMap();
    } catch (err) {
      if (err && err.name === 'AbortError') {
        return;
      }
      if (reqSeq !== latestRequestSeq) {
        return;
      }
      state.isLoading = false;
      if (navigator.onLine === false && offlineCatalogCache.length > 0) {
        const filtered = filterBusinessesClientSide(offlineCatalogCache);
        const totalFiltered = filtered.length;
        const totalPages = Math.max(1, Math.ceil(totalFiltered / state.perPage));
        state.currentPage = Math.min(state.currentPage, totalPages);
        const startOffset = (state.currentPage - 1) * state.perPage;
        state.businesses = filtered.slice(startOffset, startOffset + state.perPage);
        state.pagination = {
          page: state.currentPage,
          per_page: state.perPage,
          total: totalFiltered,
          total_pages: totalPages,
        };
        state.fetchError = '';
        setOfflineBannerVisible(true);
      } else {
        state.fetchError = (err && err.message) ? err.message : 'Network error while loading directory listings.';
      }
      if (els.cardsGrid) {
        els.cardsGrid.removeAttribute('aria-busy');
      }
      renderCards();
    }
  }

  function renderPaginationControls(totalItems, currentPage, totalPages, startIdx, endIdx) {
    const navEl = document.getElementById('directory-pagination');
    if (!navEl) return;
    if (totalItems === 0 || state.fetchError) {
      navEl.innerHTML = '';
      return;
    }

    const pageBtns = [];
    for (let p = 1; p <= totalPages; p++) {
      if (totalPages > 7 && p !== 1 && p !== totalPages && Math.abs(p - currentPage) > 2) {
        if (p === 2 || p === totalPages - 1) {
          pageBtns.push('<span style="padding:0 0.25rem;color:#9CA3AF;">&hellip;</span>');
        }
        continue;
      }
      if (p === currentPage) {
        pageBtns.push(`<button type="button" aria-current="page" class="btn btn-green" style="padding:0.28rem 0.62rem;font-size:0.78rem;">${p}</button>`);
      } else {
        pageBtns.push(`<button type="button" data-goto-page="${p}" class="btn btn-outline" style="padding:0.28rem 0.62rem;font-size:0.78rem;">${p}</button>`);
      }
    }

    navEl.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.65rem;background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:0.6rem 0.9rem;">
        <div style="font-size:0.8rem;color:#4B5563;">
          Page <strong>${currentPage}</strong> of <strong>${totalPages}</strong>
          <span style="color:#9CA3AF;">•</span>
          Showing <strong>${startIdx + 1}&ndash;${endIdx}</strong> of <strong>${totalItems}</strong>
        </div>
        <div style="display:flex;gap:0.32rem;align-items:center;flex-wrap:wrap;">
          <button type="button" ${currentPage > 1 ? `data-goto-page="${currentPage - 1}"` : 'disabled'} class="btn btn-outline" style="padding:0.28rem 0.62rem;font-size:0.78rem;${currentPage > 1 ? '' : 'opacity:0.45;cursor:not-allowed;'}">&larr; Prev</button>
          ${pageBtns.join('')}
          <button type="button" ${currentPage < totalPages ? `data-goto-page="${currentPage + 1}"` : 'disabled'} class="btn btn-outline" style="padding:0.28rem 0.62rem;font-size:0.78rem;${currentPage < totalPages ? '' : 'opacity:0.45;cursor:not-allowed;'}">Next &rarr;</button>
        </div>
      </div>
    `;
  }

  const perPageSelectEl = document.getElementById('per-page-select');
  if (perPageSelectEl) {
    perPageSelectEl.addEventListener('change', (e) => {
      const nextSize = Number(e.target.value);
      state.perPage = ALLOWED_PAGE_SIZES.includes(nextSize) ? nextSize : 10;
      state.currentPage = 1;
      fetchBusinesses({ resetPage: true });
    });
  }

  document.addEventListener('click', (e) => {
    const retryBtn = e.target.closest('[data-testid="directory-fetch-retry"]');
    if (retryBtn) {
      e.preventDefault();
      fetchBusinesses({ resetPage: false });
      return;
    }

    const enableOsmBtn = e.target.closest('#btn-enable-osm-map');
    if (enableOsmBtn) {
      e.preventDefault();
      try {
        localStorage.setItem('bbiz_map_tiles_enabled', 'yes');
      } catch (_) {}
      renderInteractiveMap();
      return;
    }

    const pageBtn = e.target.closest('[data-goto-page]');
    if (pageBtn) {
      e.preventDefault();
      e.stopPropagation();
      const targetPage = Number(pageBtn.getAttribute('data-goto-page'));
      if (Number.isFinite(targetPage) && targetPage >= 1 && targetPage !== state.currentPage) {
        state.currentPage = targetPage;
        fetchBusinesses({ resetPage: false });
        const pane = document.getElementById('pane-results');
        if (pane && typeof pane.scrollIntoView === 'function') {
          pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    }
  });

  function renderCards() {
    if (!els.cardsGrid) return;

    if (state.fetchError) {
      if (els.resultsCount) {
        els.resultsCount.textContent = 'Unable to update directory listings';
      }
      els.cardsGrid.innerHTML = `
        <div data-testid="directory-fetch-error" role="alert" style="grid-column:1/-1;background:#FEF2F2;padding:1.5rem;border-radius:12px;text-align:center;border:2px solid #DC2626;">
          <h3 style="margin:0 0 0.45rem 0;color:#991B1B;">Could not load directory listings</h3>
          <p style="margin:0 0 0.9rem 0;color:#7F1D1D;font-size:0.88rem;">${escapeHtml(state.fetchError)}</p>
          <button type="button" id="btn-retry-fetch" data-testid="directory-fetch-retry" class="btn btn-crimson">🔄 Retry Loading Listings</button>
        </div>
      `;
      renderPaginationControls(0, 1, 1, 0, 0);
      return;
    }

    const perPage = ALLOWED_PAGE_SIZES.includes(Number(state.perPage)) ? Number(state.perPage) : 10;
    const pag = state.pagination || {};
    const pagedBusinesses = Array.isArray(state.businesses) ? state.businesses : [];
    const totalItems = typeof pag.total === 'number' ? pag.total : pagedBusinesses.length;
    const totalPages = typeof pag.total_pages === 'number' ? Math.max(1, pag.total_pages) : Math.max(1, Math.ceil(totalItems / perPage));
    const currentPage = Math.min(Math.max(1, Number(pag.page) || state.currentPage || 1), totalPages);
    state.currentPage = currentPage;

    const startIdx = totalItems === 0 ? 0 : (currentPage - 1) * perPage;
    const endIdx = startIdx + pagedBusinesses.length;

    if (els.resultsCount) {
      if (totalItems === 0 || pagedBusinesses.length === 0) {
        els.resultsCount.textContent = '0 Verified Busherian Biasharas';
      } else {
        els.resultsCount.textContent = `Showing ${startIdx + 1}–${endIdx} of ${totalItems} Verified Busherian ${totalItems === 1 ? 'Biashara' : 'Biasharas'}`;
      }
    }

    if (totalItems === 0 || pagedBusinesses.length === 0) {
      els.cardsGrid.innerHTML = `
        <div style="grid-column:1/-1;background:#fff;padding:2rem;border-radius:12px;text-align:center;border:1px dashed #D1D5DB;">
          <h3 style="margin:0 0 0.5rem 0;color:#7B1113;">No matching businesses found</h3>
          <p style="margin:0;color:#6B7280;font-size:0.9rem;">Try clearing filters or searching another Kenyan/Swahili keyword (e.g., wakili, daktari, solar, coffee).</p>
        </div>
      `;
      renderPaginationControls(0, 1, 1, 0, 0);
      return;
    }

    els.cardsGrid.innerHTML = pagedBusinesses.map(biz => {
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
        <article class="biz-card" data-testid="biz-card" data-biz-id="${biz.id}">
          <div>
            ${cardPhotosHtml}
            <div class="card-top-badges" style="justify-content:space-between;">
              <span style="display:inline-flex;gap:0.35rem;align-items:center;flex-wrap:wrap;">
                ${biz.can_edit ? `<span style="background:#ECFDF5;color:#065F46;border:1px solid #059669;border-radius:999px;padding:0.15rem 0.55rem;font-size:0.7rem;font-weight:800;">✓ Your Linked Business</span>` : ''}
                ${(biz.is_bookable || biz.booking_enabled || biz.booking_url) ? `<span class="badge-bookable" data-testid="biz-card-bookable-badge">📅 Book Appointment</span>` : ''}
              </span>
              ${distBadge}
            </div>
            <h3 class="card-title" data-testid="biz-card-title">${escapeHtml(biz.name)}</h3>
            <div class="card-taxonomy">${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}</div>
            ${biz.secondary_sector_name && biz.secondary_specialty_name
              ? `<div class="card-taxonomy card-taxonomy-secondary" data-testid="biz-card-secondary-taxonomy">+ Also: ${escapeHtml(biz.secondary_sector_name)} &rarr; ${escapeHtml(biz.secondary_specialty_name)}</div>`
              : ''}
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

    renderPaginationControls(totalItems, state.currentPage, totalPages, startIdx, endIdx);
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

    if (!hasMapTileConsent()) {
      if (leafletMap) {
        try { leafletMap.remove(); } catch (_) {}
        leafletMap = null;
        markersLayer = null;
        routeLayer = null;
        mapOverlayBadge = null;
      }
      els.mapCanvas.innerHTML = `
        <div data-testid="map-consent-placeholder" style="display:flex;flex-direction:column;height:100%;min-height:320px;align-items:center;justify-content:center;background:#F9FAFB;padding:1.5rem;text-align:center;gap:0.75rem;">
          <div style="font-size:1.85rem;" aria-hidden="true">🗺️</div>
          <h3 style="margin:0;font-size:1rem;color:#114B3E;">Interactive OpenStreetMap Pin Map</h3>
          <p style="margin:0;max-width:360px;font-size:0.81rem;color:#4B5563;line-height:1.5;">
            Interactive street map tiles are served by <strong>OpenStreetMap</strong> (<code>tile.openstreetmap.org</code>). Loading map tiles shares your browser IP address and map view with OpenStreetMap.
          </p>
          <button type="button" id="btn-enable-osm-map" data-testid="btn-enable-osm-map" class="btn btn-green" style="font-size:0.84rem;padding:0.55rem 1.05rem;">
            🗺️ Load Interactive Map (OpenStreetMap)
          </button>
          <span style="font-size:0.73rem;color:#6B7280;">${visiblePinned.length} verified location pin(s) ready on this page • <a href="/privacy.php" style="color:#7B1113;font-weight:600;">Privacy Notice</a></span>
        </div>
      `;
      return;
    }

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
      const isAghs = b.school_badge === 'AGHS' || b.school_badge === 'BOTH';
      const bg = isAghs
        ? 'linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%)'
        : '#114B3E';
      const pinSchoolClass = isAghs ? 'bbiz-pin-aghs' : 'bbiz-pin-ahs';
      const label = b.name.length > 24 ? b.name.slice(0, 22) + '…' : b.name;
      const area = b.area_neighborhood || b.city_county || '';
      const isFocused = focused && Number(focused.id) === Number(b.id);

      const icon = window.L.divIcon({
        className: 'bbiz-leaflet-pin',
        html: `<div class="bbiz-pin-pill ${pinSchoolClass} ${isFocused ? 'bbiz-pin-focused' : ''}" style="background:${bg};" title="${escapeHtml(b.name)} (${escapeHtml(b.school_badge || 'AHS')}) — ${escapeHtml(area)}">📍 ${escapeHtml(label)}</div>`,
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

  let lastHumanInteractionToken = '';
  function refreshHumanInteractionToken() {
    lastHumanInteractionToken = 'human_verified_' + Date.now();
  }
  ['pointerdown', 'keydown', 'touchstart', 'focusin'].forEach(evName => {
    document.addEventListener(evName, refreshHumanInteractionToken, { passive: true });
  });

  function recordCheckout(businessId, signalType) {
    if (!businessId || !signalType || !lastHumanInteractionToken) return;
    try {
      fetch('/api/record-checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        keepalive: signalType === 'phone' || signalType === 'email',
        body: JSON.stringify({
          business_id: Number(businessId),
          signal_type: signalType,
          interaction_token: lastHumanInteractionToken,
        }),
      }).catch(() => {});
    } catch (_) {}
  }

  function trackClick(businessId, eventType) {
    if (eventType && eventType !== 'shortlist') {
      recordCheckout(businessId, eventType);
      return;
    }
    if (!window.BBizMetrics || !window.BBizMetrics.hasConsent()) return;
    try {
      fetch('/api/track-click.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ business_id: businessId, event_type: eventType }),
      });
    } catch (_) {}
  }

  function openBusinessModal(bizId, opts) {
    const biz = state.businesses.find(b => Number(b.id) === Number(bizId));
    if (!biz || !els.modalBackdrop || !els.modalContent) return;
    state.activeBusiness = biz;
    state.focusedBusinessId = biz.id;
    const isConcierge = Boolean(opts && opts.source === 'concierge');
    recordCheckout(biz.id, isConcierge ? 'concierge_ref' : 'profile_checkout');
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

    const safeCustomGmaps = sanitizeHttpUrl(biz.google_maps_url);
    const gmapsUrl = safeCustomGmaps
      || (biz.latitude !== null && biz.longitude !== null
        ? `https://www.google.com/maps/search/?api=1&query=${biz.latitude},${biz.longitude}`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(biz.name + ' ' + biz.city_county)}`);

    const wazeUrl = (biz.latitude !== null && biz.longitude !== null)
      ? `https://waze.com/ul?ll=${biz.latitude},${biz.longitude}&navigate=yes`
      : '';

    const safeWebsiteHref = withOutboundAttribution(biz.website_url);
    const safeBookingHref = withBookingAttribution(biz.booking_url);
    const isBuiltInBookable = !safeBookingHref && Boolean(biz.booking_enabled || biz.is_bookable);
    const minBookingDate = getTodayEatIsoDate();

    const socials = biz.socials || {};
    const socialBadges = [
      ['instagram', 'Instagram', socials.instagram],
      ['linkedin', 'LinkedIn', socials.linkedin],
      ['x', 'X (Twitter)', socials.x],
      ['facebook', 'Facebook', socials.facebook],
      ['tiktok', 'TikTok', socials.tiktok],
      ['youtube', 'YouTube', socials.youtube],
    ].map(([key, label, url]) => [key, label, withOutboundAttribution(url)])
     .filter(s => !!s[2]).map(([key, label, safeHref]) => `
      <a href="${escapeHtml(safeHref)}" target="_blank" rel="noopener noreferrer" data-track="social" class="btn btn-outline" style="font-size:0.8rem;padding:0.4rem 0.75rem;">
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
            ${(safeBookingHref || isBuiltInBookable) ? '<span class="badge-bookable">📅 Direct Booking Available</span>' : ''}
            ${biz.b2b_ready ? '<span class="badge-distance">🏢 B2B & LPO Ready</span>' : ''}
            ${biz.regulator_name ? `<span class="badge-distance">🛡️ ${escapeHtml(biz.regulator_name)}${biz.regulator_license ? ': ' + escapeHtml(biz.regulator_license) : ''}</span>` : ''}
          </div>
          <h2 style="margin:0;font-size:1.4rem;color:#111827;">${escapeHtml(biz.name)}</h2>
          <div style="font-size:0.86rem;font-weight:700;color:#7B1113;margin-top:0.2rem;">
            ${escapeHtml(biz.sector_name)} &rarr; ${escapeHtml(biz.specialty_name)}
          </div>
          ${biz.secondary_sector_name && biz.secondary_specialty_name ? `
            <div data-testid="modal-secondary-taxonomy" style="font-size:0.8rem;font-weight:700;color:#114B3E;margin-top:0.18rem;">
              + Secondary Field: ${escapeHtml(biz.secondary_sector_name)} &rarr; ${escapeHtml(biz.secondary_specialty_name)}
            </div>
          ` : ''}
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
          ${safeBookingHref
            ? `<a href="${escapeHtml(safeBookingHref)}" target="_blank" rel="noopener noreferrer" data-track="booking" data-testid="modal-book-appointment-cta" class="btn btn-booking">📅 Book Appointment &nearr;</a>`
            : (isBuiltInBookable
                ? `<button type="button" data-open-booking-drawer="${biz.id}" data-testid="modal-book-appointment-cta" aria-expanded="false" aria-controls="modal-booking-drawer-${biz.id}" class="btn btn-booking">📅 Book Appointment</button>`
                : '')}
          ${safeWebsiteHref ? `<a href="${escapeHtml(safeWebsiteHref)}" target="_blank" rel="noopener noreferrer" data-track="website" class="btn btn-crimson">🌐 Visit Website &nearr;</a>` : ''}
          ${biz.phone_primary ? `<a href="tel:${escapeHtml(biz.phone_primary)}" data-track="call" class="btn btn-green">📞 Call ${escapeHtml(biz.phone_primary)}</a>` : ''}
          ${waUrl ? `<a href="${escapeHtml(waUrl)}" target="_blank" rel="noopener noreferrer" data-track="whatsapp" class="btn btn-gold">💬 WhatsApp Now</a>` : ''}
          ${biz.email_public ? `<a href="mailto:${escapeHtml(biz.email_public)}" data-track="email" class="btn btn-outline">✉️ Email Business</a>` : ''}
        </div>

        ${isBuiltInBookable ? `
          <div id="modal-booking-drawer-${biz.id}" data-testid="modal-booking-drawer" class="modal-booking-drawer" hidden>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.65rem;flex-wrap:wrap;margin-bottom:0.65rem;">
              <div>
                <h4 style="margin:0;color:#114B3E;font-size:0.98rem;">📅 Request an Appointment / Consultation with ${escapeHtml(biz.name)}</h4>
                <p style="margin:0.15rem 0 0 0;font-size:0.78rem;color:#4B5563;">Share your preferred slot below — your request goes directly to the business with zero platform commission.</p>
              </div>
              <button type="button" data-close-booking-drawer="${biz.id}" class="btn btn-outline" style="padding:0.25rem 0.6rem;font-size:0.76rem;">✕ Close</button>
            </div>
            <form data-booking-inquiry-form="${biz.id}" data-testid="modal-booking-form" novalidate>
              <input type="hidden" name="business_id" value="${biz.id}">
              <input type="hidden" name="csrf_token" value="">
              <input type="hidden" name="form_ts" value="">
              <input type="hidden" name="form_nonce" value="">
              <input type="hidden" name="form_sig" value="">
              <input type="hidden" name="js_interaction_token" value="">
              <div class="bbiz-contact-ext-field" aria-hidden="true">
                <label for="booking-ext-web-${biz.id}">Organization Website</label>
                <input type="text" id="booking-ext-web-${biz.id}" name="website" value="" autocomplete="nope" tabindex="-1">
              </div>

              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:0.7rem;">
                <div>
                  <label for="booking-name-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Your Full Name *</label>
                  <input type="text" id="booking-name-${biz.id}" name="requester_name" data-testid="booking-requester-name" class="input-control" maxlength="120" required placeholder="e.g. Jane Wanjiku">
                </div>
                <div>
                  <label for="booking-email-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Your Email *</label>
                  <input type="email" id="booking-email-${biz.id}" name="requester_email" data-testid="booking-requester-email" class="input-control" maxlength="190" required placeholder="you@example.com">
                </div>
                <div>
                  <label for="booking-phone-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Your Phone / WhatsApp</label>
                  <input type="tel" id="booking-phone-${biz.id}" name="requester_phone" data-testid="booking-requester-phone" class="input-control" maxlength="40" placeholder="+2547...">
                </div>
              </div>

              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:0.7rem;margin-top:0.7rem;">
                <div>
                  <label for="booking-date-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Preferred Date (EAT) *</label>
                  <input type="date" id="booking-date-${biz.id}" name="preferred_date" data-testid="booking-preferred-date" class="input-control" min="${escapeHtml(minBookingDate)}" value="${escapeHtml(minBookingDate)}" required>
                </div>
                <div>
                  <label for="booking-slot-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Preferred Time Window *</label>
                  <select id="booking-slot-${biz.id}" name="preferred_slot" data-testid="booking-preferred-slot" class="select-control" required>
                    <option value="Morning (8:00 AM - 12:00 PM EAT)">Morning (8:00 AM – 12:00 PM EAT)</option>
                    <option value="Midday / Early Afternoon (12:00 PM - 3:00 PM EAT)">Midday / Early Afternoon (12:00 PM – 3:00 PM EAT)</option>
                    <option value="Late Afternoon (3:00 PM - 6:00 PM EAT)">Late Afternoon (3:00 PM – 6:00 PM EAT)</option>
                    <option value="Evening / Flexible (By Arrangement)">Evening / Flexible (By Arrangement)</option>
                  </select>
                </div>
              </div>

              <div style="margin-top:0.7rem;">
                <label for="booking-topic-${biz.id}" style="font-weight:700;font-size:0.79rem;display:block;margin-bottom:0.2rem;">Brief Consultation Topic / Note *</label>
                <textarea id="booking-topic-${biz.id}" name="topic" data-testid="booking-topic" rows="2" maxlength="500" class="input-control" required placeholder="Briefly describe the service or consultation you would like to book..."></textarea>
              </div>

              <div style="margin-top:0.65rem;background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:0.6rem 0.75rem;font-size:0.77rem;color:#374151;">
                <label for="booking-age-${biz.id}" style="display:flex;align-items:flex-start;gap:0.45rem;margin-bottom:0.35rem;cursor:pointer;">
                  <input type="checkbox" id="booking-age-${biz.id}" name="age_attestation_18" data-testid="booking-age-checkbox" value="1" required style="margin-top:0.15rem;">
                  <span>I confirm that I am 18 years of age or older. *</span>
                </label>
                <label for="booking-privacy-${biz.id}" style="display:flex;align-items:flex-start;gap:0.45rem;cursor:pointer;">
                  <input type="checkbox" id="booking-privacy-${biz.id}" name="privacy_acknowledged" data-testid="booking-privacy-checkbox" value="1" required style="margin-top:0.15rem;">
                  <span>I consent to sharing the details above with <strong>${escapeHtml(biz.name)}</strong> to arrange this appointment in accordance with the <a href="/privacy.php" target="_blank" style="color:#7B1113;text-decoration:underline;">Privacy Notice</a>. *</span>
                </label>
              </div>

              <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;margin-top:0.75rem;">
                ${biz.has_booking_email ? `<button type="submit" data-testid="booking-submit-email-btn" class="btn btn-crimson" style="font-size:0.82rem;">✉️ Send Request by Email</button>` : ''}
                ${waNumber ? `<button type="button" data-booking-whatsapp-btn="${biz.id}" data-wa-digits="${escapeHtml(waNumber)}" data-testid="booking-submit-whatsapp-btn" class="btn btn-green" style="font-size:0.82rem;">💬 Request via WhatsApp &nearr;</button>` : ''}
              </div>
              <div id="booking-drawer-status-${biz.id}" data-testid="booking-drawer-status" role="status" aria-live="polite" style="margin-top:0.6rem;font-size:0.82rem;font-weight:600;display:none;"></div>
            </form>
          </div>
        ` : ''}

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

        <div style="margin-top:1.15rem;padding:0.8rem 0.95rem;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.65rem;">
          <div style="font-size:0.8rem;color:#065F46;">
            ${biz.can_edit && biz.edit_url
              ? `<strong>✓ You manage this listing.</strong> Update hours, photos, contacts, or map pin anytime.`
              : `<strong>👤 Own this business? Sign in to edit</strong>${Array.isArray(biz.owner_email_hints) && biz.owner_email_hints.length > 0 ? ` <span style="color:#047857;">(Linked email: <strong>${escapeHtml(biz.owner_email_hints.join(' or '))}</strong>)</span>` : ''}`}
          </div>
          <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            ${biz.can_edit && biz.edit_url
              ? `<a href="${escapeHtml(biz.edit_url)}" class="btn btn-green" style="font-size:0.78rem;padding:0.38rem 0.8rem;">✏️ Edit My Business Listing</a>`
              : `<a href="/owner/login.php?biz=${biz.id}" class="btn btn-green" style="font-size:0.78rem;padding:0.38rem 0.8rem;">🔑 Owner Sign In to Edit</a>`}
            <a href="/takedown.php?business_id=${biz.id}" style="color:#7B1113;font-weight:600;font-size:0.76rem;">Report or Remove Listing</a>
          </div>
        </div>
      </div>
    `;

    els.modalBackdrop.classList.add('open');
    renderRouteDrawerAndModalBox();
  }

  function exportB2BShortlist() {
    const selected = Array.from(state.shortlistIds)
      .map(id => state.shortlistedRecords.get(id) || state.businesses.find(b => Number(b.id) === Number(id)))
      .filter(Boolean);
    if (selected.length === 0) {
      alert('Select at least one business using "+ B2B Shortlist" on any card to export a procurement summary.');
      return;
    }
    const headers = ['Business Name', 'Industry Sector', 'Specialty', 'City/Area', 'B2B Ready', 'Accepts LPO', 'Regulator', 'Phone', 'Email', 'Website', 'Booking'];
    const sanitizeCsv = (val) => {
      const s = String(val ?? '').trim();
      const safe = (/^[=+\-@|\t\r]/.test(s)) ? ("'" + s) : s;
      return '"' + safe.replace(/"/g, '""') + '"';
    };
    const rows = selected.map(b => [
      b.name,
      b.secondary_sector_name ? `${b.sector_name} (+ ${b.secondary_sector_name})` : b.sector_name,
      b.secondary_specialty_name ? `${b.specialty_name} (+ ${b.secondary_specialty_name})` : b.specialty_name,
      `${b.area_neighborhood || ''} ${b.city_county || ''}`.trim(),
      b.b2b_ready ? 'Yes' : 'No',
      b.accepts_lpo ? 'Yes' : 'No',
      b.regulator_name ? (b.regulator_license ? `${b.regulator_name} (${b.regulator_license})` : b.regulator_name) : '',
      b.phone_primary,
      b.email_public,
      b.website_url,
      b.booking_url || ((b.booking_enabled || b.is_bookable) ? 'Direct Request' : 'No'),
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
        state.bookable = false;
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
      } else if (f === 'bookable') {
        state.bookable = !state.bookable;
        btn.classList.toggle('active', state.bookable);
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

  async function ensureBookingFormTokens(formEl, statusEl) {
    if (!formEl) return false;
    const csrfInput = formEl.querySelector('input[name="csrf_token"]');
    const tsInput = formEl.querySelector('input[name="form_ts"]');
    const nonceInput = formEl.querySelector('input[name="form_nonce"]');
    const sigInput = formEl.querySelector('input[name="form_sig"]');
    if (csrfInput && csrfInput.value && nonceInput && nonceInput.value && sigInput && sigInput.value) {
      return true;
    }
    try {
      const res = await fetch('/api/book-appointment.php?action=tokens', { credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok || !data || data.status !== 'ok' || !data.tokens) {
        throw new Error((data && data.message) || 'Could not initialize booking security token.');
      }
      if (csrfInput) csrfInput.value = String(data.tokens.csrf_token || '');
      if (tsInput) tsInput.value = String(data.tokens.form_ts || '');
      if (nonceInput) nonceInput.value = String(data.tokens.form_nonce || '');
      if (sigInput) sigInput.value = String(data.tokens.form_sig || '');
      return true;
    } catch (err) {
      if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#991B1B';
        statusEl.textContent = '⚠️ ' + ((err && err.message) || 'Could not prepare booking form. You can still contact the business directly.');
      }
      return false;
    }
  }

  document.addEventListener('submit', async (e) => {
    const bookingForm = e.target.closest('[data-booking-inquiry-form]');
    if (bookingForm) {
      e.preventDefault();
      const bizId = Number(bookingForm.getAttribute('data-booking-inquiry-form'));
      const statusEl = document.getElementById(`booking-drawer-status-${bizId}`);
      const submitBtn = bookingForm.querySelector('[data-testid="booking-submit-email-btn"]');

      const nameVal = (bookingForm.querySelector('[name="requester_name"]')?.value || '').trim();
      const emailVal = (bookingForm.querySelector('[name="requester_email"]')?.value || '').trim();
      const dateVal = (bookingForm.querySelector('[name="preferred_date"]')?.value || '').trim();
      const slotVal = (bookingForm.querySelector('[name="preferred_slot"]')?.value || '').trim();
      const topicVal = (bookingForm.querySelector('[name="topic"]')?.value || '').trim();
      const ageChecked = Boolean(bookingForm.querySelector('[name="age_attestation_18"]')?.checked);
      const privChecked = Boolean(bookingForm.querySelector('[name="privacy_acknowledged"]')?.checked);

      if (!nameVal || !emailVal || !dateVal || !slotVal || !topicVal) {
        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.color = '#991B1B';
          statusEl.textContent = '⚠️ Please fill in your name, email, preferred date, time window, and brief consultation topic.';
        }
        return;
      }
      if (!ageChecked || !privChecked) {
        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.color = '#991B1B';
          statusEl.textContent = '⚠️ Please confirm both the 18+ attestation and Privacy Notice consent checkboxes.';
        }
        return;
      }

      const tokenReady = await ensureBookingFormTokens(bookingForm, statusEl);
      if (!tokenReady) return;

      if (submitBtn) submitBtn.disabled = true;
      if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#114B3E';
        statusEl.textContent = '⏳ Sending your appointment request...';
      }

      try {
        const fd = new FormData(bookingForm);
        const res = await fetch('/api/book-appointment.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const payload = await res.json();
        if (!res.ok || !payload || payload.status !== 'ok') {
          const nonceEl = bookingForm.querySelector('input[name="form_nonce"]');
          if (nonceEl) nonceEl.value = '';
          await ensureBookingFormTokens(bookingForm, null);
          throw new Error((payload && payload.message) || 'Could not send appointment request right now.');
        }

        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.color = '#065F46';
          statusEl.textContent = '✓ ' + (payload.message || 'Your appointment request has been sent!');
        }
        if (window.BBizMetrics && state.activeBusiness) {
          window.BBizMetrics.track('booking', { business_id: bizId, meta_label: state.activeBusiness.name });
        }
        bookingForm.reset();
        const nonceEl = bookingForm.querySelector('input[name="form_nonce"]');
        if (nonceEl) nonceEl.value = '';
      } catch (err) {
        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.color = '#991B1B';
          statusEl.textContent = '⚠️ ' + ((err && err.message) || 'Could not send appointment request.');
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
      return;
    }

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
        state.shortlistedRecords.delete(id);
      } else {
        if (state.shortlistIds.size >= 5) {
          alert('You can shortlist up to 5 alumni vendors at a time.');
          return;
        }
        state.shortlistIds.add(id);
        const foundBiz = state.businesses.find(b => Number(b.id) === id);
        if (foundBiz) state.shortlistedRecords.set(id, foundBiz);
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

    const openBookingBtn = e.target.closest('[data-open-booking-drawer]');
    if (openBookingBtn) {
      e.stopPropagation();
      const bizId = Number(openBookingBtn.getAttribute('data-open-booking-drawer'));
      const drawer = document.getElementById(`modal-booking-drawer-${bizId}`);
      if (drawer) {
        const willOpen = drawer.hidden;
        drawer.hidden = !willOpen;
        openBookingBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
          const bForm = drawer.querySelector('[data-booking-inquiry-form]');
          const statusEl = document.getElementById(`booking-drawer-status-${bizId}`);
          if (bForm && !drawer.dataset.humanBound) {
            drawer.dataset.humanBound = '1';
            const markBookingHuman = () => {
              const jt = bForm.querySelector('input[name="js_interaction_token"]');
              if (jt && !jt.value) {
                jt.value = 'human_verified_' + Date.now();
              }
            };
            ['focusin', 'keydown', 'pointerdown', 'touchstart'].forEach(evName => {
              drawer.addEventListener(evName, markBookingHuman, { passive: true });
            });
          }
          ensureBookingFormTokens(bForm, statusEl);
          const firstField = drawer.querySelector('input[name="requester_name"]');
          if (firstField) firstField.focus();
        }
      }
      return;
    }

    const closeBookingBtn = e.target.closest('[data-close-booking-drawer]');
    if (closeBookingBtn) {
      e.stopPropagation();
      const bizId = Number(closeBookingBtn.getAttribute('data-close-booking-drawer'));
      const drawer = document.getElementById(`modal-booking-drawer-${bizId}`);
      const toggleBtn = els.modalContent ? els.modalContent.querySelector(`[data-open-booking-drawer="${bizId}"]`) : null;
      if (drawer) drawer.hidden = true;
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
      return;
    }

    const waBookingBtn = e.target.closest('[data-booking-whatsapp-btn]');
    if (waBookingBtn) {
      e.stopPropagation();
      const bizId = Number(waBookingBtn.getAttribute('data-booking-whatsapp-btn'));
      const waDigits = waBookingBtn.getAttribute('data-wa-digits') || '';
      const bForm = els.modalContent ? els.modalContent.querySelector(`[data-booking-inquiry-form="${bizId}"]`) : null;
      const statusEl = document.getElementById(`booking-drawer-status-${bizId}`);
      if (!bForm || !waDigits) return;

      const nameVal = (bForm.querySelector('[name="requester_name"]')?.value || '').trim();
      const dateVal = (bForm.querySelector('[name="preferred_date"]')?.value || '').trim();
      const slotVal = (bForm.querySelector('[name="preferred_slot"]')?.value || '').trim();
      const topicVal = (bForm.querySelector('[name="topic"]')?.value || '').trim();
      const ageChecked = Boolean(bForm.querySelector('[name="age_attestation_18"]')?.checked);
      const privChecked = Boolean(bForm.querySelector('[name="privacy_acknowledged"]')?.checked);

      if (!nameVal || !dateVal || !slotVal || !topicVal || !ageChecked || !privChecked) {
        if (statusEl) {
          statusEl.style.display = 'block';
          statusEl.style.color = '#991B1B';
          statusEl.textContent = '⚠️ Please enter your name, preferred date, time window, topic, and confirm both checkboxes before opening WhatsApp.';
        }
        return;
      }

      const bizName = (state.activeBusiness && state.activeBusiness.name) ? state.activeBusiness.name : 'your business';
      const msgLines = [
        `Hi ${bizName}, I'd like to request an appointment via Busherian Biasharas:`,
        `• Name: ${nameVal}`,
        `• Preferred Date: ${dateVal} (${slotVal})`,
        `• Topic: ${topicVal}`,
      ];
      trackClick(bizId, 'booking');
      if (window.BBizMetrics) window.BBizMetrics.track('booking', { business_id: bizId, meta_label: bizName });
      window.open(`https://wa.me/${waDigits}?text=${encodeURIComponent(msgLines.join('\n'))}`, '_blank', 'noopener,noreferrer');
      return;
    }

    const trackEl = e.target.closest('[data-track]');
    if (trackEl && state.activeBusiness) {
      const evtType = trackEl.getAttribute('data-track');
      trackClick(state.activeBusiness.id, evtType);
      if (evtType === 'booking' && window.BBizMetrics) {
        window.BBizMetrics.track('booking', { business_id: state.activeBusiness.id, meta_label: state.activeBusiness.name });
      }
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

  window.addEventListener('bbiz:consent-changed', () => {
    renderInteractiveMap();
  });

  // Progressive Web App (PWA): Install Prompt, Guide Modal, Offline Status & Service Worker (#287, #289, #290)
  const btnInstallApp = document.getElementById('btn-install-app');
  const pwaInstallModal = document.getElementById('pwa-install-modal');
  const btnClosePwaModal = document.getElementById('btn-close-pwa-modal');
  const pwaGuideIos = document.getElementById('pwa-guide-ios');
  const pwaGuideOther = document.getElementById('pwa-guide-other');
  let deferredInstallPrompt = null;

  function isStandaloneApp() {
    try {
      if (window.navigator && window.navigator.standalone === true) return true;
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
    } catch (_) {}
    return false;
  }

  function isAppleTouchDevice() {
    const ua = String((window.navigator && window.navigator.userAgent) || '');
    if (/iPad|iPhone|iPod/i.test(ua)) return true;
    return /Macintosh/i.test(ua) && Number((window.navigator && window.navigator.maxTouchPoints) || 0) > 1;
  }

  function openPwaInstallModal() {
    if (!pwaInstallModal) return;
    const onIos = isAppleTouchDevice();
    if (pwaGuideIos) pwaGuideIos.classList.toggle('recommended', onIos);
    if (pwaGuideOther) pwaGuideOther.classList.toggle('recommended', !onIos);
    pwaInstallModal.hidden = false;
    pwaInstallModal.classList.add('open');
  }

  function closePwaInstallModal() {
    if (!pwaInstallModal) return;
    pwaInstallModal.classList.remove('open');
    pwaInstallModal.hidden = true;
  }

  if (btnInstallApp) {
    if (!isStandaloneApp()) {
      btnInstallApp.hidden = false;
    }

    window.addEventListener('beforeinstallprompt', (ev) => {
      ev.preventDefault();
      deferredInstallPrompt = ev;
      if (!isStandaloneApp()) {
        btnInstallApp.hidden = false;
      }
    });

    window.addEventListener('appinstalled', () => {
      deferredInstallPrompt = null;
      btnInstallApp.hidden = true;
      closePwaInstallModal();
    });

    btnInstallApp.addEventListener('click', async () => {
      if (deferredInstallPrompt && typeof deferredInstallPrompt.prompt === 'function') {
        const promptEvt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        try {
          await promptEvt.prompt();
          const choice = promptEvt.userChoice ? await promptEvt.userChoice : null;
          if (choice && choice.outcome === 'accepted') {
            btnInstallApp.hidden = true;
          }
        } catch (_) {
          openPwaInstallModal();
        }
        return;
      }
      openPwaInstallModal();
    });
  }

  if (btnClosePwaModal) {
    btnClosePwaModal.addEventListener('click', closePwaInstallModal);
  }
  if (pwaInstallModal) {
    pwaInstallModal.addEventListener('click', (ev) => {
      if (ev.target === pwaInstallModal) {
        closePwaInstallModal();
      }
    });
  }
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && pwaInstallModal && !pwaInstallModal.hidden) {
      closePwaInstallModal();
    }
    if (ev.key === 'Escape' && charterModal && !charterModal.hidden) {
      closeCharterModal();
    }
  });

  // Community Charter Quick-View Modal (#298, #300)
  const btnOpenCharterModal = document.getElementById('btn-open-charter-modal');
  const charterModal = document.getElementById('community-charter-modal');
  const btnCloseCharterModal = document.getElementById('btn-close-charter-modal');

  function openCharterModal() {
    if (!charterModal) return;
    charterModal.hidden = false;
    charterModal.classList.add('open');
  }

  function closeCharterModal() {
    if (!charterModal) return;
    charterModal.classList.remove('open');
    charterModal.hidden = true;
  }

  if (btnOpenCharterModal && charterModal) {
    btnOpenCharterModal.addEventListener('click', (ev) => {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
      ev.preventDefault();
      openCharterModal();
    });
  }
  if (btnCloseCharterModal) {
    btnCloseCharterModal.addEventListener('click', closeCharterModal);
  }
  if (charterModal) {
    charterModal.addEventListener('click', (ev) => {
      if (ev.target === charterModal) {
        closeCharterModal();
      }
    });
  }

  window.addEventListener('offline', () => setOfflineBannerVisible(true));
  window.addEventListener('online', () => {
    setOfflineBannerVisible(false);
    fetchBusinesses({ resetPage: false });
  });
  if (!navigator.onLine) {
    setOfflineBannerVisible(true);
  }

  function registerPwaServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    const swBase = (typeof window.BBIZ_BASE_PATH === 'string' && window.BBIZ_BASE_PATH)
      ? window.BBIZ_BASE_PATH.replace(/\/$/, '')
      : '';
    if (!swBase && window.location.pathname.startsWith('/admin/')) {
      return;
    }
    navigator.serviceWorker.register((swBase || '') + '/sw.js', { scope: (swBase || '') + '/' }).catch(() => {});
  }

  if (document.readyState === 'complete') {
    registerPwaServiceWorker();
  } else {
    window.addEventListener('load', registerPwaServiceWorker, { once: true });
  }

  // Initial Render + PWA Shortcut Query Deep-Links (?filter=bookable|has_perk|b2b_ready|open_now|AHS|AGHS, ?q=...)
  renderCards();
  renderInteractiveMap();

  try {
    const initParams = new URLSearchParams(window.location.search);
    const initQ = (initParams.get('q') || '').trim();
    const initFilter = (initParams.get('filter') || '').trim();
    let needsInitialFetch = false;

    if (initQ && els.searchInput) {
      els.searchInput.value = initQ;
      state.searchQuery = initQ;
      if (els.searchClearBtn) els.searchClearBtn.style.display = 'inline-flex';
      needsInitialFetch = true;
    }

    if (initFilter && ['open_now', 'has_perk', 'b2b_ready', 'bookable', 'AHS', 'AGHS'].includes(initFilter)) {
      document.querySelectorAll('[data-quick-filter="all"]').forEach(b => b.classList.remove('active'));
      if (initFilter === 'open_now') state.openNow = true;
      if (initFilter === 'has_perk') state.hasPerk = true;
      if (initFilter === 'b2b_ready') state.b2bReady = true;
      if (initFilter === 'bookable') state.bookable = true;
      if (initFilter === 'AHS' || initFilter === 'AGHS') state.selectedSchool = initFilter;
      const targetPill = document.querySelector(`[data-quick-filter="${initFilter}"]`);
      if (targetPill) targetPill.classList.add('active');
      needsInitialFetch = true;
    }

    if (window.location.hash === '#search-q' && els.searchInput) {
      els.searchInput.focus();
    }

    if (needsInitialFetch) {
      fetchBusinesses();
    }
  } catch (_) {}
})();
