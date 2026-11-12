(function () {
  'use strict';

  const STORAGE_KEY = 'bbiz_cookie_consent';
  const CONSENT_META_KEY = 'bbiz_cookie_consent_meta';
  const CONSENT_NOTICE_VERSION = '2026-11-v4';
  const CONSENT_COOKIE = 'bbiz_consent';
  const VID_COOKIE = 'bbiz_vid';
  const ONE_YEAR_SECONDS = 31536000; // Fixed 365-day ceiling (non-rolling)

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([$?*|{}()\[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookieUntil(name, value, expiresAtMs) {
    const clampedExp = Math.min(Date.now() + (ONE_YEAR_SECONDS * 1000), Math.floor(Number(expiresAtMs) || 0));
    if (clampedExp <= Date.now()) {
      deleteCookie(name);
      return;
    }
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    const expStr = new Date(clampedExp).toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; Expires=${expStr}; Path=/; SameSite=Lax${secure}`;
  }

  function deleteCookie(name) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  }

  function clearStoredConsentState() {
    try {
      localStorage.removeItem(STORAGE_KEY);
      localStorage.removeItem(CONSENT_META_KEY);
    } catch (_) {}
    deleteCookie(CONSENT_COOKIE);
    deleteCookie(VID_COOKIE);
  }

  function readConsentMeta() {
    try {
      const raw = localStorage.getItem(CONSENT_META_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return (parsed && typeof parsed === 'object') ? parsed : null;
    } catch (_) {
      return null;
    }
  }

  function getRemainingConsentSeconds() {
    const meta = readConsentMeta();
    if (!meta || typeof meta.expires_at_ms !== 'number') {
      return 0;
    }
    return Math.max(0, Math.floor((meta.expires_at_ms - Date.now()) / 1000));
  }

  function readPersistedDecision() {
    let ls = '';
    try {
      ls = localStorage.getItem(STORAGE_KEY) || '';
    } catch (_) {}
    const ck = getCookie(CONSENT_COOKIE);
    const val = (ls === 'accepted' || ls === 'rejected') ? ls : ((ck === 'accepted' || ck === 'rejected') ? ck : '');
    if (val !== 'accepted' && val !== 'rejected') {
      return '';
    }

    const meta = readConsentMeta();
    // Enforce notice-version match and fixed non-rolling 12-month expiry (#245 M-05)
    if (!meta || meta.notice_version !== CONSENT_NOTICE_VERSION || typeof meta.expires_at_ms !== 'number') {
      clearStoredConsentState();
      return '';
    }
    const remainingSec = Math.floor((meta.expires_at_ms - Date.now()) / 1000);
    if (remainingSec <= 0) {
      clearStoredConsentState();
      return '';
    }

    try {
      localStorage.setItem(STORAGE_KEY, val);
    } catch (_) {}
    // Restore cookie ONLY if missing, pinned to original fixed expires_at_ms (strictly non-rolling)
    if (ck !== val) {
      setCookieUntil(CONSENT_COOKIE, val, meta.expires_at_ms);
    }
    return val;
  }

  function ensureVisitorId(expiresAtMsOverride) {
    let vid = getCookie(VID_COOKIE);
    const meta = readConsentMeta();
    const targetExpMs = (typeof expiresAtMsOverride === 'number' && expiresAtMsOverride > Date.now())
      ? expiresAtMsOverride
      : ((meta && typeof meta.expires_at_ms === 'number') ? meta.expires_at_ms : (Date.now() + ONE_YEAR_SECONDS * 1000));
    if (!vid || vid.length < 12) {
      const arr = new Uint8Array(12);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(arr);
      } else {
        for (let i = 0; i < arr.length; i++) arr[i] = Math.floor(Math.random() * 256);
      }
      vid = Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
      setCookieUntil(VID_COOKIE, vid, targetExpMs);
    }
    return vid;
  }

  function syncServerConsent(decision, vid) {
    try {
      return fetch('/api/consent.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          decision: decision,
          policy_version: CONSENT_NOTICE_VERSION,
          visitor_id: vid || ''
        }),
        keepalive: true,
      }).then(r => r.ok ? r.json().catch(() => null) : null).catch(() => null);
    } catch (_) {
      return Promise.resolve(null);
    }
  }

  let currentDecision = readPersistedDecision();
  if (currentDecision === 'accepted') {
    ensureVisitorId();
  } else if (currentDecision === 'rejected') {
    deleteCookie(VID_COOKIE);
  }

  function track(eventType, payload) {
    if (currentDecision !== 'accepted') return;
    const remSec = getRemainingConsentSeconds();
    if (remSec <= 0) {
      currentDecision = '';
      clearStoredConsentState();
      return;
    }
    ensureVisitorId();
    const body = Object.assign(
      { event_type: eventType, page_path: window.location.pathname || '/' },
      payload || {}
    );
    try {
      fetch('/api/track-metric.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true,
      });
    } catch (_) {}
  }

  function applyDecision(decision) {
    currentDecision = decision;
    const nowMs = Date.now();
    const expiresAtMs = nowMs + (ONE_YEAR_SECONDS * 1000);
    try {
      localStorage.setItem(STORAGE_KEY, decision);
      localStorage.setItem(CONSENT_META_KEY, JSON.stringify({
        decision: decision,
        notice_version: CONSENT_NOTICE_VERSION,
        recorded_at: new Date(nowMs).toISOString(),
        granted_at_ms: nowMs,
        expires_at_ms: expiresAtMs
      }));
    } catch (_) {}
    setCookieUntil(CONSENT_COOKIE, decision, expiresAtMs);

    const banner = document.getElementById('bbiz-cookie-banner');
    if (banner) banner.remove();

    if (decision === 'accepted') {
      const vid = ensureVisitorId(expiresAtMs);
      syncServerConsent('accepted', vid).then(res => {
        if (res && typeof res.expires_at_unix === 'number') {
          const serverExpMs = res.expires_at_unix * 1000;
          try {
            localStorage.setItem(CONSENT_META_KEY, JSON.stringify({
              decision: 'accepted',
              notice_version: CONSENT_NOTICE_VERSION,
              recorded_at: new Date(nowMs).toISOString(),
              granted_at_ms: nowMs,
              expires_at_ms: serverExpMs
            }));
          } catch (_) {}
          setCookieUntil(CONSENT_COOKIE, 'accepted', serverExpMs);
          setCookieUntil(VID_COOKIE, vid, serverExpMs);
        }
        track('page_view');
      });
    } else {
      const prevVid = getCookie(VID_COOKIE);
      deleteCookie(VID_COOKIE);
      syncServerConsent('rejected', prevVid);
    }

    updatePrivacyPageStatus();
    try {
      window.dispatchEvent(new CustomEvent('bbiz:consent-changed', { detail: { decision: decision } }));
    } catch (_) {}
  }

  function resetChoice() {
    const prevVid = getCookie(VID_COOKIE);
    currentDecision = '';
    try {
      localStorage.removeItem('bbiz_map_tiles_enabled');
    } catch (_) {}
    clearStoredConsentState();
    syncServerConsent('revoked', prevVid);
    renderBannerIfNeeded();
    updatePrivacyPageStatus();
    try {
      window.dispatchEvent(new CustomEvent('bbiz:consent-changed', { detail: { decision: '' } }));
    } catch (_) {}
  }

  function renderBannerIfNeeded() {
    if (currentDecision === 'accepted' || currentDecision === 'rejected') return;
    if (document.getElementById('bbiz-cookie-banner')) return;

    const bar = document.createElement('aside');
    bar.id = 'bbiz-cookie-banner';
    bar.setAttribute('data-testid', 'cookie-banner');
    bar.className = 'bbiz-cookie-banner';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Cookie and Usage Metrics Consent');
    bar.innerHTML = `
      <div class="bbiz-cookie-inner">
        <div class="bbiz-cookie-text">
          <strong>🍪 Cookie &amp; Privacy Choice:</strong>
          With your permission, we use a pseudonymous browser cookie (valid for a fixed 12-month period under the active Privacy Notice) for first-party directory visit and search statistics (cleared after 6 months). Interactive OpenStreetMap tiles remain off unless you load the map separately.
          <a href="/privacy.php">Privacy Notice</a>
        </div>
        <div class="bbiz-cookie-actions">
          <button type="button" id="btn-cookie-reject" data-testid="cookie-reject-btn" class="bbiz-cookie-btn bbiz-cookie-btn-reject">Allow only Essential</button>
          <button type="button" id="btn-cookie-accept" data-testid="cookie-accept-btn" class="bbiz-cookie-btn bbiz-cookie-btn-accept">Allow for Analysis</button>
        </div>
      </div>
    `;
    document.body.appendChild(bar);

    const btnReject = document.getElementById('btn-cookie-reject');
    const btnAccept = document.getElementById('btn-cookie-accept');
    if (btnReject) btnReject.addEventListener('click', () => applyDecision('rejected'));
    if (btnAccept) btnAccept.addEventListener('click', () => applyDecision('accepted'));
  }

  function updatePrivacyPageStatus() {
    const labelEl = document.getElementById('dpa-cookie-status-label');
    if (labelEl) {
      if (currentDecision === 'accepted') {
        labelEl.textContent = 'Allow for Analysis (First-party pseudonymous usage statistics enabled; map tiles remain separate)';
      } else if (currentDecision === 'rejected') {
        labelEl.textContent = 'Allow only Essential (Usage analysis cookies disabled on this browser)';
      } else {
        labelEl.textContent = 'No choice recorded yet (Only essential functionality active)';
      }
    }
    const mapLabelEl = document.getElementById('dpa-map-status-label');
    if (mapLabelEl) {
      let mapEnabled = false;
      try {
        mapEnabled = localStorage.getItem('bbiz_map_tiles_enabled') === 'yes';
      } catch (_) {}
      mapLabelEl.textContent = mapEnabled
        ? 'Enabled by explicit map opt-in on this browser'
        : 'Disabled (OpenStreetMap tiles will not load unless you click Load Interactive Map)';
    }
  }

  window.BBizMetrics = {
    hasConsent: () => currentDecision === 'accepted' && getRemainingConsentSeconds() > 0,
    track,
    resetChoice,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      renderBannerIfNeeded();
      updatePrivacyPageStatus();
      if (currentDecision === 'accepted') track('page_view');
    });
  } else {
    renderBannerIfNeeded();
    updatePrivacyPageStatus();
    if (currentDecision === 'accepted') track('page_view');
  }
})();
