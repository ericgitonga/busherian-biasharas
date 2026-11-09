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

      <h2 style="color:#114B3E;">3. Zero Pre-Consent Cross-Border Tracking &amp; Keyed IP Pseudonymization (DPA ss. 25(h), 31, 41, 48)</h2>
      <ul>
        <li><strong>100% Self-Hosted Assets:</strong> All stylesheets, fonts, and interactive scripts are served directly from <code>busherianbiasharas.com</code>. Zero third-party CDN requests (no Google Fonts, jsDelivr, or external analytics) fire on page load.</li>
        <li><strong>Keyed HMAC-SHA256 IP Pseudonymization:</strong> To protect our outbound mail server from spam without logging raw visitor IP addresses, rate-limit checks store only a keyed cryptographic hash (<code>HMAC-SHA256</code>) that expires automatically after 24 hours.</li>
        <li><strong>Sub-Processors:</strong> Hosting and MySQL storage are provided on Angryhosting. No personal vetting data is ever shared with third parties.</li>
      </ul>

      <h2 style="color:#114B3E;">4. Your Statutory Rights Under the Kenya Data Protection Act, 2019 (ss. 26, 40)</h2>
      <p>
        Under Sections 26 and 40 of the Kenya Data Protection Act, 2019, you have the right to access, rectify, object to processing, or demand immediate erasure of any listing referencing your business or contact details.
        Use our self-service <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Section 40 Instant Quarantine &amp; Takedown Form</a> at any time to immediately hide a disputed listing from public view pending review.
      </p>
    </article>
  </main>
</body>
</html>
