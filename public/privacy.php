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
<?= bbiz_head_meta_tags('Privacy Notice — Busherian Biasharas', 'Plain-language Privacy Notice and Data Protection Information for Busherian Biasharas.', '/privacy.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Plain-Language Privacy Notice (Notice Version 2026-11-v3)</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
        <a href="/takedown.php" class="btn btn-gold">Report or Remove a Listing</a>
      </nav>
    </div>
  </header>

  <main style="max-width:880px;margin:2rem auto;padding:0 1.25rem;">
    <article data-testid="privacy-notice-article" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);line-height:1.6;">
      <h2 style="margin-top:0;color:#7B1113;">1. Who We Are &amp; Lawful Basis for Processing</h2>
      <p>
        <strong>Busherian Biasharas</strong> operates a community business directory for adult alumni (18+) of
        <strong>Alliance High School (AHS)</strong> and <strong>Alliance Girls High School (AGHS)</strong>, guided by the principles and data-subject rights set out in the
        <strong>Kenya Data Protection Act, 2019</strong>.
        For privacy inquiries, access requests, or removal requests, contact our directory administrators at
        <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:600;">admin@busherianbiasharas.com</a>.
      </p>
      <ul>
        <li><strong>Public Business Listings (Consent &amp; Provenance):</strong> Self-submitted and owner-claimed listings are published on the basis of explicit directory publication consent recorded with the active notice version and timestamp. Administrator-curated alumni business listings record their documented source basis (such as direct founder authorization or a public corporate/chapter registry) and may be paused or removed anytime at the owner's request.</li>
        <li><strong>Adult-Only Scope (18+):</strong> All submission, claiming, and curation workflows require confirmation that listed contacts and photographs involve only adults aged 18 or older. Any report involving a person under 18 can be flagged for urgent priority removal on our <a href="/takedown.php" style="color:#7B1113;font-weight:600;">Report or Remove a Listing</a> page.</li>
      </ul>

      <h2 style="color:#114B3E;">2. One-Time Alumni Verification &amp; Short-Lived Browser Tab Drafts</h2>
      <div class="vetting-privacy-banner">
        <strong>How We Handle School Verification Details &amp; Form Drafts:</strong>
        <ul>
          <li>When you submit a business for review, Step 1 asks for your <strong>School</strong> (AHS or AGHS), <strong>House</strong>, <strong>Year Left</strong>, and optional <strong>School Number</strong>, along with your submitter contact details.</li>
          <li>Your House, Year Left, and School Number are reviewed <strong>strictly once</strong> inside our restricted administrator console to confirm alumni affiliation, are <strong>never</strong> included in notification emails, and <strong>never</strong> appear in the public directory or Owner Portal.</li>
          <li>As soon as an administrator approves or declines your submission, those school verification fields are permanently deleted from our active database (and any unreviewed submission older than 30 days is automatically purged).</li>
          <li><strong>Temporary 2-Hour Browser Tab Drafts:</strong> While you type on the registration form, non-sensitive business profile text is temporarily held in your current browser tab's session storage for up to 2 hours so an accidental refresh does not lose your work. Your House, Year Left, School Number, uploaded photos, and consent checkboxes are excluded from browser draft storage and are cleared when you close the tab or submit the form.</li>
        </ul>
      </div>

      <h2 style="color:#114B3E;">3. Cookies, First-Party Usage Analysis &amp; Separate Map Permission</h2>
      <ul>
        <li><strong>Essential Security &amp; Sign-In State (Always Active When Needed):</strong> To protect forms and signed-in accounts, we use an essential session security token, an optional 30-day Owner Account sign-in cookie when you log in as a business owner, and one-way keyed security hashes of IP addresses (never raw IP addresses) to block automated spam and login abuse.</li>
        <li><strong>Allow only Essential (Default Before You Choose):</strong> No usage-analysis cookies are set, no visit beacons are sent, and third-party street map tiles remain off. Searching, filtering, and contacting businesses work normally.</li>
        <li><strong>Allow for Analysis (First-Party Directory Statistics Only):</strong> If you choose <strong>Allow for Analysis</strong>, we store your consent preference and a <strong>pseudonymous browser visitor cookie</strong> for up to 12 months to count overall page views, listing clicks, and minimized search topics (email addresses, phone numbers, and website links are automatically stripped from search labels before storage). Choosing <strong>Allow for Analysis</strong> does <strong>not</strong> load third-party map tiles.</li>
        <li><strong>Separate OpenStreetMap Tile, Address Lookup &amp; Road Routing Consent:</strong> Interactive street map tiles are served by <strong>OpenStreetMap</strong> (tile.openstreetmap.org) and load <strong>only if you explicitly click "Load Interactive Map (OpenStreetMap)"</strong> on the map panel, because loading map tiles shares your browser IP address and viewed map area with OpenStreetMap. When you search a starting street or request driving directions, our server queries <strong>OpenStreetMap Nominatim</strong> (address lookup) and <strong>OSRM</strong> (road route calculation) with the street query or pin coordinates needed to calculate the route (note that coordinates or street names for a home-based business can correspond to an identifiable location).</li>
      </ul>
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.9rem 1.1rem;margin:0.9rem 0 1.25rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;font-size:0.86rem;">
        <div>
          <div><strong>Your Current Analysis Cookie Preference:</strong> <span id="dpa-cookie-status-label">Checking...</span></div>
          <div style="margin-top:0.2rem;color:#4B5563;font-size:0.81rem;"><strong>Interactive OpenStreetMap Tiles:</strong> <span id="dpa-map-status-label">Checking...</span></div>
        </div>
        <button type="button" onclick="if(window.BBizMetrics) window.BBizMetrics.resetChoice();" class="btn btn-outline" style="font-size:0.8rem;padding:0.35rem 0.8rem;">Reset Cookie &amp; Map Choices</button>
      </div>

      <h2 style="color:#114B3E;">4. Optional User-Triggered Integrations, Service Providers &amp; Cross-Border Transfers</h2>
      <p>
        We do not sell personal data or embed third-party advertising trackers. Certain optional features communicate with external service providers when you use them:
      </p>
      <ul>
        <li><strong>Optional Google Sign-In for Business Owners:</strong> If you click <em>Continue with Google</em> on Owner Sign-In, your browser connects to Google (accounts.google.com and openidconnect.googleapis.com) to confirm your verified email address, display name, and Google account identifier so we can sign you into your Owner Account.</li>
        <li><strong>Optional Website Auto-Fill &amp; Map-Link Pin Extraction:</strong> When a business submitter, owner, or administrator enters a public website address for 1-click auto-fill or pastes a shortened Google Maps link, our server makes a bounded outbound HTTPS request to that public URL solely to extract public business contact metadata or map coordinates.</li>
        <li><strong>Hosting, Email Delivery &amp; Cross-Border Safeguards (Kenya DPA ss. 48–49):</strong> Our application database and web files run on shared web-hosting infrastructure and dispatch transactional verification and moderation emails through our configured SMTP mail provider. Where hosting, email delivery, Google Sign-In, or OpenStreetMap servers operate outside Kenya, transfers occur over TLS encryption either at your direct request to complete an action you initiated (such as signing in with Google, resolving a map route, or receiving a verification code) or with your explicit opt-in (such as loading interactive OpenStreetMap tiles). Routine hosting disaster-recovery backups are controlled by the hosting operator strictly for infrastructure restoration and age out under the provider's backup rotation schedule.</li>
      </ul>

      <h2 style="color:#114B3E;">5. Data Retention Schedule Across Records</h2>
      <ul>
        <li><strong>Alumni Vetting Queue Details (House, Year Left, School Number):</strong> Deleted immediately upon listing approval or rejection, or automatically after <strong>30 days</strong> if unreviewed.</li>
        <li><strong>Transactional Email Outbox Records:</strong> Recipient address, reply-to address, subject line, and message body are automatically redacted after <strong>30 days</strong>.</li>
        <li><strong>Authentication Codes &amp; Magic-Link Tokens:</strong> Single-use verification codes expire within 15–30 minutes and token records are purged after <strong>7 days</strong>.</li>
        <li><strong>Owner Sessions &amp; Anti-Spam Rate-Limit Hashes:</strong> Revoked or expired owner session records are purged during scheduled cleanup, and anti-spam rate-limit hashes are cleared after <strong>24 hours</strong>.</li>
        <li><strong>Resolved Listing Takedown &amp; Correction Requests:</strong> Requester name, email address, narrative reason, and security hash are redacted <strong>90 days</strong> after resolution while keeping non-personal status counts.</li>
        <li><strong>Custom Category Submitter Emails:</strong> Cleared once the suggested category is reviewed (or after <strong>30 days</strong>).</li>
        <li><strong>Consented Usage Statistics &amp; Listing Click Counters:</strong> Automatically deleted after <strong>6 months (180 days)</strong>.</li>
        <li><strong>Moderation Audit Trail:</strong> Personal actor email identifiers and IP hashes are pseudonymized after <strong>12 months (365 days)</strong>.</li>
      </ul>

      <h2 style="color:#114B3E;">6. Your Data-Subject Rights: Access, JSON Export, Correction, Pause &amp; Complete Erasure</h2>
      <p>
        Under the <strong>Kenya Data Protection Act, 2019</strong>, you may exercise access, correction, portability, objection, restriction, and erasure rights at any time:
      </p>
      <ul>
        <li><strong>Self-Service JSON Data Export &amp; Complete Account Erasure:</strong> Sign in at <a href="/owner/login.php" style="color:#114B3E;font-weight:700;">Owner Sign-In</a> and open <strong>My Businesses</strong> to download a complete JSON copy of your Owner Account profile, linked listings, session history, and correspondence records, or to permanently erase your Owner Account, sessions, authentication tokens, and correspondence metadata (while preserving any co-owned listings for your co-owners, or optionally deleting sole-owned listings).</li>
        <li><strong>Immediate Owner Listing Pause:</strong> Signed-in owners — or owners confirming a single-use email link from our <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing Form</a> — can hide a listing and restrict its uploaded photos immediately while our team completes review.</li>
        <li><strong>Third-Party Accuracy, Privacy &amp; Minor-Data Reports:</strong> Anyone may submit a correction, privacy objection, or urgent under-18 data report via <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing</a> or by emailing <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:700;">admin@busherianbiasharas.com</a>.</li>
      </ul>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
