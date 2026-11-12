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
    <article data-testid="privacy-notice-article" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
      <h2 style="margin-top:0;color:#7B1113;">1. Who We Are &amp; Why We Collect Information</h2>
      <p>
        <strong>Busherian Biasharas</strong> operates as a verified business directory for alumni of
        <strong>Alliance High School (AHS)</strong> and <strong>Alliance Girls High School (AGHS)</strong>, in full compliance with the
        <strong>Kenya Data Protection Act, 2019</strong>.
        For any privacy questions or requests, you can reach our team directly at
        <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:600;">admin@busherianbiasharas.com</a>.
      </p>

      <h2 style="color:#114B3E;">2. One-Time Alumni Verification &amp; Retention of School Details</h2>
      <div class="vetting-privacy-banner">
        <strong>Your School Details Are Never Emailed, Never Shown Publicly &amp; Cleared From Active Records After Review:</strong>
        <ul>
          <li>When you list a business, Step 1 asks for your <strong>School</strong> (AHS or AGHS), <strong>House</strong>, <strong>Year Left</strong>, and optional <strong>School Number</strong>.</li>
          <li>These details are used <strong>strictly once</strong> inside our secure administrator review console to confirm that the business belongs to a fellow Busherian.</li>
          <li><strong>Your House, Year Left, and School Number are never included in notification emails or email delivery logs, and DO NOT appear anywhere in the public directory or Owner Portal.</strong></li>
          <li>As soon as an administrator approves or declines your listing, your House, Year Left, and School Number are removed from our active database (and any unreviewed submission older than 30 days is automatically cleared from the active verification queue).</li>
          <li>Routine transactional email delivery logs (which exclude school verification details) are automatically redacted after 30 days. Encrypted hosting provider database backups may briefly retain historical database snapshots during routine disaster-recovery backup rotation before aging out automatically; those backups are strictly restricted to system recovery and are never used for directory operations.</li>
        </ul>
      </div>

      <h2 style="color:#114B3E;">3. Your Cookie Choice, Interactive Maps &amp; Anonymous Directory Statistics</h2>
      <ul>
        <li><strong>Your Choice Comes First (Allow for Analysis or Allow only Essential):</strong> On your first visit, we show a simple banner asking whether you wish to <strong>Allow for Analysis</strong> or <strong>Allow only Essential</strong> cookies. Your choice is remembered on your browser and can be changed anytime using the button below.</li>
        <li><strong>If You Click "Allow only Essential" (or Before You Choose):</strong> No usage cookies are saved, no visit statistics are recorded, and no external map tiles are loaded automatically. Searching, filtering, and contacting businesses work 100% normally.</li>
        <li><strong>If You Click "Allow for Analysis":</strong> We save a single anonymous browser cookie to help us count overall page visits, popular search terms, and clicks to businesses so we can improve the directory. These anonymous counts are cleared automatically after 6 months.</li>
        <li><strong>Consent-Gated OpenStreetMap Tiles, Address Lookup &amp; Road Routing:</strong> Fonts, icons, and map controls are hosted directly by Busherian Biasharas with zero outside advertising trackers. Interactive street map tiles are served by <strong>OpenStreetMap</strong> (tile.openstreetmap.org) and load <strong>only after you click "Allow for Analysis" or click "Load Interactive Map (OpenStreetMap)"</strong> on the map panel, because loading map tiles shares your browser IP address and viewed map region with OpenStreetMap. When you enter a starting street or request driving directions, our server queries <strong>OpenStreetMap Nominatim</strong> (address lookup) and <strong>OSRM</strong> (road route calculation) using only the coordinates or street name needed to compute the route — never your personal identity.</li>
      </ul>
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.9rem 1.1rem;margin:0.9rem 0 1.25rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;font-size:0.86rem;">
        <div><strong>Your Current Cookie Preference:</strong> <span id="dpa-cookie-status-label">Checking...</span></div>
        <button type="button" onclick="if(window.BBizMetrics) window.BBizMetrics.resetChoice();" class="btn btn-outline" style="font-size:0.8rem;padding:0.35rem 0.8rem;">Change Cookie Choice</button>
      </div>

      <h2 style="color:#114B3E;">4. Updating, Pausing, or Removing a Business Listing Anytime</h2>
      <p>
        Under the <strong>Kenya Data Protection Act, 2019</strong>, you have the right to update, correct, or remove any business listing that includes your business or contact details:
      </p>
      <ul>
        <li><strong>Update or Delete Your Own Listing Anytime:</strong> Sign in via the <a href="/owner/login.php" style="color:#114B3E;font-weight:700;">Owner Sign-In</a> page using your verified business email address to edit or permanently delete your listing directly, or to sign out across all active devices.</li>
        <li><strong>Verified Owner Immediate Pause:</strong> When you submit our <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing Form</a> while signed in as the verified owner (or confirm the single-use link sent to the listing's registered owner email), your listing is hidden from the public directory immediately while our team completes your request.</li>
        <li><strong>Third-Party Privacy or Accuracy Reports:</strong> Anyone may report an inaccurate or unauthorized listing through our <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing Form</a>. Third-party reports are routed immediately to our administrators for priority review while keeping verified alumni listings protected against unverified third-party disruption during review.</li>
      </ul>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
