<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
bbiz_send_security_headers(false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Privacy Notice (Kenya DPA 2019 Section 29) — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Kenya Data Protection Act, 2019 (Section 29) Privacy &amp; Data Disposal Notice</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
        <a href="/takedown.php" class="btn btn-gold">Section 40 Takedown / Erasure</a>
      </nav>
    </div>
  </header>

  <main style="max-width:860px;margin:2rem auto;padding:0 1.25rem;">
    <article style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
      <h2 style="margin-top:0;color:#7B1113;">1. Data Controller &amp; Purpose of Collection (DPA s.29)</h2>
      <p>
        <strong>Busherian Biasharas</strong> (<code>busherianbiasharas.com</code>) operates as a verified business directory for alumni of
        <strong>Alliance High School (AHS)</strong> and <strong>Alliance Girls High School (AGHS)</strong>.
        Contact for all Data Protection inquiries: <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:600;">admin@busherianbiasharas.com</a>.
      </p>

      <h2 style="color:#114B3E;">2. One-Time Alumni Vetting &amp; Immediate Cryptographic Disposal (DPA ss. 25, 39)</h2>
      <div class="vetting-privacy-banner">
        <strong>Zero Public Exposure &amp; Immediate Deletion of Vetting Data:</strong>
        <ul>
          <li>When you submit a business, Step 1 collects your <strong>School</strong> (AHS or AGHS), <strong>House</strong> (compulsory), <strong>Year Left</strong> (compulsory), and <strong>School Number</strong> (optional).</li>
          <li>These credentials are stored in an isolated table (<code>business_vetting_queue</code>) strictly for one-time alumni verification.</li>
          <li><strong>Houses, Years Left, and School Numbers DO NOT feature anywhere in the public directory.</strong></li>
          <li>The moment an administrator approves or rejects your submission, the system executes an immediate hard-delete (<code>DELETE FROM business_vetting_queue WHERE business_id = :id</code>), permanently destroying your House, Year Left, and School Number.</li>
        </ul>
      </div>

            <h2 style="color:#114B3E;">3. First-Party Cookie Consent &amp; Anonymous Usage Metrics (DPA ss. 25, 29, 32)</h2>
      <ul>
        <li><strong>Strictly Opt-In Consent (<code>Accept</code> / <code>Reject All</code>):</strong> On your first visit, we present a clear two-option cookie banner (<strong>Accept</strong> or <strong>Reject All</strong>). Your decision persists on your browser until you clear browsing data or reset it below.</li>
        <li><strong>What Happens When You Click <code>Reject All</code> (or Before Choosing):</strong> Zero usage-metric cookies are set and zero analytics events are recorded. All directory search, filtering, map, and business contact features remain 100% available.</li>
        <li><strong>What Happens When You Click <code>Accept</code>:</strong> A random first-party cookie (<code>bbiz_vid</code>) is stored and hashed via keyed <code>HMAC-SHA256</code> on our server to count anonymous page views, search terms, and outbound business clicks. Records are automatically purged after 180 days.</li>
        <li><strong>100% Self-Hosted Assets &amp; Keyed IP Pseudonymization:</strong> All stylesheets and scripts are served directly from <code>busherianbiasharas.com</code> with zero third-party ad/analytics trackers, and anti-spam rate limits store only 24-hour keyed <code>HMAC-SHA256</code> hashes.</li>
      </ul>
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.9rem 1.1rem;margin:0.9rem 0 1.25rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;font-size:0.86rem;">
        <div><strong>Current Browser Consent Status:</strong> <span id="dpa-cookie-status-label">Checking...</span></div>
        <button type="button" onclick="if(window.BBizMetrics) window.BBizMetrics.resetChoice();" class="btn btn-outline" style="font-size:0.8rem;padding:0.35rem 0.8rem;">Change Cookie Choice</button>
      </div>

      <h2 style="color:#114B3E;">4. Your Statutory Rights Under the Kenya Data Protection Act, 2019 (ss. 26, 40)</h2>
      <p>
        Under Sections 26 and 40 of the Kenya Data Protection Act, 2019, you have the right to access, rectify, object to processing, or demand immediate erasure of any listing referencing your business or contact details.
        Use our self-service <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Section 40 Instant Quarantine &amp; Takedown Form</a> at any time to immediately hide a disputed listing from public view pending review.
      </p>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
