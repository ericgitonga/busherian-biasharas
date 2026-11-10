(function () {
  'use strict';

  const STORAGE_KEY = 'bbiz_cookie_consent';
  const CONSENT_COOKIE = 'bbiz_consent';
  const VID_COOKIE = 'bbiz_vid';
  const ONE_YEAR_SECONDS = 31536000; // 365 days

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([$?*|{}()\[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookie(name, value, maxAgeSeconds) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAgeSeconds}; Path=/; SameSite=Lax${secure}`;
  }

  function deleteCookie(name) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  }

  function readPersistedDecision() {
    let ls = '';
    try {
      ls = localStorage.getItem(STORAGE_KEY) || '';
    } catch (_) {}
    const ck = getCookie(CONSENT_COOKIE);
    const val = (ls === 'accepted' || ls === 'rejected') ? ls : ((ck === 'accepted' || ck === 'rejected') ? ck : '');

    // Keep localStorage and 365-day cookie in sync so machine decision persists until browsing data is cleared
    if (val === 'accepted' || val === 'rejected') {
      try {
        localStorage.setItem(STORAGE_KEY, val);
      } catch (_) {}
      setCookie(CONSENT_COOKIE, val, ONE_YEAR_SECONDS);
    }
    return val;
  }

  function ensureVisitorId() {
    let vid = getCookie(VID_COOKIE);
    if (!vid || vid.length < 12) {
      const arr = new Uint8Array(12);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(arr);
      } else {
        for (let i = 0; i < arr.length; i++) arr[i] = Math.floor(Math.random() * 256);
      }
      vid = Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
    }
    setCookie(VID_COOKIE, vid, ONE_YEAR_SECONDS);
    return vid;
  }

  let currentDecision = readPersistedDecision();
  if (currentDecision === 'accepted') {
    ensureVisitorId();
  } else if (currentDecision === 'rejected') {
    deleteCookie(VID_COOKIE);
  }

  function track(eventType, payload) {
    if (currentDecision !== 'accepted') return;
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
    try {
      localStorage.setItem(STORAGE_KEY, decision);
    } catch (_) {}
    setCookie(CONSENT_COOKIE, decision, ONE_YEAR_SECONDS);

    const banner = document.getElementById('bbiz-cookie-banner');
    if (banner) banner.remove();

    if (decision === 'accepted') {
      ensureVisitorId();
      track('page_view');
    } else {
      deleteCookie(VID_COOKIE);
    }

    updatePrivacyPageStatus();
  }

  function resetChoice() {
    currentDecision = '';
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (_) {}
    deleteCookie(CONSENT_COOKIE);
    deleteCookie(VID_COOKIE);
    renderBannerIfNeeded();
    updatePrivacyPageStatus();
  }

  function renderBannerIfNeeded() {
    if (currentDecision === 'accepted' || currentDecision === 'rejected') return;
    if (document.getElementById('bbiz-cookie-banner')) return;

    const bar = document.createElement('aside');
    bar.id = 'bbiz-cookie-banner';
    bar.className = 'bbiz-cookie-banner';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Cookie and Usage Metrics Consent');
    bar.innerHTML = `
      <div class="bbiz-cookie-inner">
        <div class="bbiz-cookie-text">
          <strong>🍪 Cookie &amp; Usage Metrics Notice (Kenya DPA 2019):</strong>
          We use a first-party cookie only with your consent to measure anonymous directory usage (page views, searches, and business clicks). Zero third-party trackers are used.
          <a href="/privacy.php">Privacy Notice</a>
        </div>
        <div class="bbiz-cookie-actions">
          <button type="button" id="btn-cookie-reject" class="bbiz-cookie-btn bbiz-cookie-btn-reject">Reject All</button>
          <button type="button" id="btn-cookie-accept" class="bbiz-cookie-btn bbiz-cookie-btn-accept">Accept</button>
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
    if (!labelEl) return;
    if (currentDecision === 'accepted') {
      labelEl.textContent = 'Accepted (First-party anonymous usage metrics enabled on this browser)';
    } else if (currentDecision === 'rejected') {
      labelEl.textContent = 'Rejected All (Usage metrics cookies disabled on this browser)';
    } else {
      labelEl.textContent = 'No choice recorded yet (Usage metrics inactive until Accepted)';
    }
  }

  window.BBizMetrics = {
    hasConsent: () => currentDecision === 'accepted',
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
