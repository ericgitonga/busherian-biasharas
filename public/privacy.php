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
  <title>Privacy Notice — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Privacy Notice — Busherian Biasharas', 'Plain-language Privacy Notice and Alumni Data Protection Commitments for Busherian Biasharas.', '/privacy.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Plain-Language Privacy Notice &amp; Alumni Privacy Guarantee</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
        <a href="/takedown.php" class="btn btn-gold">Report or Remove a Listing</a>
      </nav>
    </div>
  </header>

  <main style="max-width:860px;margin:2rem auto;padding:0 1.25rem;">
    <article style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
      <h2 style="margin-top:0;color:#7B1113;">1. Who We Are &amp; Why We Collect Information</h2>
      <p>
        <strong>Busherian Biasharas</strong> operates as a verified business directory for alumni of
        <strong>Alliance High School (AHS)</strong> and <strong>Alliance Girls High School (AGHS)</strong>, in full compliance with the
        <strong>Kenya Data Protection Act, 2019</strong>.
        For any privacy questions or requests, you can reach our team directly at
        <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:600;">admin@busherianbiasharas.com</a>.
      </p>

      <h2 style="color:#114B3E;">2. One-Time Alumni Verification &amp; Immediate Deletion of School Details</h2>
      <div class="vetting-privacy-banner">
        <strong>Your School Details Are Never Shown Publicly &amp; Are Deleted Immediately After Review:</strong>
        <ul>
          <li>When you list a business, Step 1 asks for your <strong>School</strong> (AHS or AGHS), <strong>House</strong>, <strong>Year Left</strong>, and optional <strong>School Number</strong>.</li>
          <li>These details are used <strong>strictly once</strong> by our verification team to confirm that the business belongs to a fellow Busherian.</li>
          <li><strong>Your House, Year Left, and School Number DO NOT appear anywhere in the public directory.</strong></li>
          <li>The moment your listing is reviewed (whether approved or declined), your House, Year Left, and School Number are <strong>permanently deleted from our records</strong>.</li>
        </ul>
      </div>

      <h2 style="color:#114B3E;">3. Your Cookie Choice &amp; Anonymous Directory Statistics</h2>
      <ul>
        <li><strong>Your Choice Comes First (Accept or Reject All):</strong> On your first visit, we show a simple banner asking whether you wish to <strong>Accept</strong> or <strong>Reject All</strong> optional cookies. Your choice is remembered on your browser and can be changed anytime using the button below.</li>
        <li><strong>If You Click "Reject All" (or Before You Choose):</strong> No usage cookies are saved and no visit statistics are recorded. Every directory feature — searching, filtering, maps, and contacting businesses — works 100% normally.</li>
        <li><strong>If You Click "Accept":</strong> We save a single anonymous browser cookie to help us count overall page visits, popular search terms, and clicks to businesses so we can improve the directory. These anonymous counts are cleared automatically after 6 months.</li>
        <li><strong>Zero Outside Ad Trackers:</strong> All fonts, maps, icons, and scripts are hosted directly by Busherian Biasharas. We never share your browsing activity with outside advertisers.</li>
      </ul>
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.9rem 1.1rem;margin:0.9rem 0 1.25rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;font-size:0.86rem;">
        <div><strong>Your Current Cookie Preference:</strong> <span id="dpa-cookie-status-label">Checking...</span></div>
        <button type="button" onclick="if(window.BBizMetrics) window.BBizMetrics.resetChoice();" class="btn btn-outline" style="font-size:0.8rem;padding:0.35rem 0.8rem;">Change Cookie Choice</button>
      </div>

      <h2 style="color:#114B3E;">4. Updating or Removing a Business Listing Anytime</h2>
      <p>
        Under the <strong>Kenya Data Protection Act, 2019</strong>, you have the right to update, correct, or remove any business listing that includes your business or contact details:
      </p>
      <ul>
        <li><strong>Update Your Own Listing Anytime:</strong> Sign in via the <a href="/owner/login.php" style="color:#114B3E;font-weight:700;">Owner Sign-In</a> page using your email address to edit your business details directly.</li>
        <li><strong>Immediately Hide or Remove a Listing:</strong> Use our <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing Form</a> at any time to immediately hide a listing from public view while our team completes your removal or correction request.</li>
      </ul>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
