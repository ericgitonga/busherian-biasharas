<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
bbiz_send_security_headers(false);
if (!headers_sent()) {
    header('X-BBiz-Public-Cacheable: 1');
}
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
          <p class="brand-subtitle">Plain-Language Privacy Notice (Notice Version 2026-11-v4)</p>
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
        <li><strong>Public Business Listings (Consent &amp; Provenance):</strong> Self-submitted and owner-claimed listings are published on the basis of explicit directory publication consent recorded with the active notice version and timestamp. Administrator-curated alumni business listings record their documented source note (such as direct founder authorization or a public corporate/alumni registry reference) and may be paused or removed at any time upon the owner's objection or request.</li>
        <li><strong>Adult-Only Scope (18+):</strong> Submission, claiming, first-time Google Owner Account onboarding, owner listing edits, and administrator curation each require confirmation that the account holder, listed contacts, and uploaded photographs involve only adults aged 18 or older. Any report involving a person under 18 can be flagged for urgent priority removal on our <a href="/takedown.php" style="color:#7B1113;font-weight:600;">Report or Remove a Listing</a> page.</li>
      </ul>

      <h2 style="color:#114B3E;">2. One-Time Alumni Verification &amp; Reload-Checked Browser Tab Drafts</h2>
      <div class="vetting-privacy-banner">
        <strong>How We Handle School Verification Details &amp; Form Drafts:</strong>
        <ul>
          <li>When you submit a business for review, Step 1 asks for your <strong>School</strong> (AHS or AGHS), <strong>House</strong>, <strong>Year Left</strong>, and optional <strong>School Number</strong>, along with your submitter contact details.</li>
          <li>Your House, Year Left, and School Number are reviewed <strong>strictly once</strong> inside our restricted administrator console to confirm alumni affiliation, are <strong>never</strong> included in notification emails, and <strong>never</strong> appear in the public directory or Owner Portal.</li>
          <li>As soon as an administrator approves or declines your submission, those school verification fields are permanently deleted from our active database (and any unreviewed submission older than 30 days is automatically purged).</li>
          <li><strong>Temporary Browser Tab Drafts:</strong> While you type on the registration form, non-sensitive business profile text is held in your current browser tab's session storage with a 2-hour timestamp check evaluated when the form reloads (and cleared automatically when you submit the form or close the browser tab). Your House, Year Left, School Number, uploaded photos, and consent checkboxes are excluded from draft storage.</li>
        </ul>
      </div>

      <h2 style="color:#114B3E;">3. Cookies, First-Party Usage Analysis &amp; Separate Map Permission</h2>
      <ul>
        <li><strong>Essential Security &amp; Sign-In State (Always Active When Needed):</strong> To protect forms and signed-in accounts, we use an essential session security token, an optional 30-day Owner Account sign-in cookie when you log in as a business owner, and one-way keyed security hashes of IP addresses (never raw IP addresses) to block automated spam and login abuse.</li>
        <li><strong>Allow only Essential (Default Before You Choose):</strong> No usage-analysis cookies are set, no visit beacons are sent, and third-party street map tiles remain off. Searching, filtering, and contacting businesses work normally.</li>
        <li><strong>Allow for Analysis (Fixed 12-Month Non-Rolling Consent &amp; Server Provenance):</strong> If you choose <strong>Allow for Analysis</strong>, we record a pseudonymous consent event on our server tied to Notice Version 2026-11-v4 with a <strong>fixed, non-rolling 12-month expiry</strong> (revisiting pages does not extend that 12-month window, and a new notice version re-prompts for fresh consent). While active, we count sanitized application route views, listing clicks, and minimized search topics (query strings, email addresses, phone numbers, and website links are stripped before storage). Choosing <strong>Reset Cookie &amp; Map Choices</strong> below immediately revokes server-side consent and deletes associated visitor metrics. Choosing <strong>Allow for Analysis</strong> does <strong>not</strong> load third-party map tiles.</li>
        <li><strong>Separate OpenStreetMap Tile, Address Lookup &amp; Road Routing Consent:</strong> Interactive street map tiles are served by <strong>OpenStreetMap</strong> (tile.openstreetmap.org) and load <strong>only if you explicitly click "Load Interactive Map (OpenStreetMap)"</strong> on the map panel, because loading map tiles shares your browser IP address and viewed map area with OpenStreetMap. When you search a starting street or request driving directions, our server proxies the lookup to <strong>OpenStreetMap Nominatim</strong> (nominatim.openstreetmap.org) or <strong>OSRM</strong> (router.project-osrm.org) without forwarding your browser IP address (note that coordinates or street names for a home origin or home-based business can correspond to an identifiable location).</li>
      </ul>
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.9rem 1.1rem;margin:0.9rem 0 1.25rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;font-size:0.86rem;">
        <div>
          <div><strong>Your Current Analysis Cookie Preference:</strong> <span id="dpa-cookie-status-label">Checking...</span></div>
          <div style="margin-top:0.2rem;color:#4B5563;font-size:0.81rem;"><strong>Interactive OpenStreetMap Tiles:</strong> <span id="dpa-map-status-label">Checking...</span></div>
        </div>
        <button type="button" onclick="if(window.BBizMetrics) window.BBizMetrics.resetChoice();" class="btn btn-outline" style="font-size:0.8rem;padding:0.35rem 0.8rem;">Reset Cookie &amp; Map Choices</button>
      </div>

      <h2 style="color:#114B3E;">4. Optional Integrations, Service Providers &amp; Cross-Border Transfers</h2>
      <p>
        We do not sell personal data or embed third-party advertising trackers. Our internal Sub-Processor &amp; Cross-Border Transfer Register documents each external integration under Sections 48–50 of the Kenya Data Protection Act, 2019:
      </p>
      <ul>
        <li><strong>Optional Google Sign-In for Business Owners:</strong> If you click <em>Continue with Google</em> on Owner Sign-In, your browser redirects to Google (accounts.google.com) and our server exchanges the authorization code over HTTPS with oauth2.googleapis.com and openidconnect.googleapis.com to verify your email address, display name, and Google account identifier. First-time Google users complete an on-screen 18+ and Privacy Notice confirmation before an Owner Account record is saved.</li>
        <li><strong>Optional Website Auto-Fill &amp; Map-Link Pin Extraction:</strong> When a submitter, owner, or administrator enters a public website URL (http:// or https://) for 1-click auto-fill or pastes a shortened map link, our server performs a bounded, private-network-filtered HTTP request to that public URL solely to read public business metadata or map coordinates.</li>
        <li><strong>Optional Appointment &amp; Consultation Requests:</strong> When a listing enables direct appointment booking and an adult visitor (18+) submits an optional built-in appointment request, the visitor's name, contact email/phone, preferred date and time window, and brief consultation topic are delivered directly to that listed business so the business can confirm the slot (or opened directly in WhatsApp or the business's external scheduling link if chosen). Outbound email queue records follow the 30-day redaction schedule below, and third-party requester details are redacted from owner data exports.</li>
        <li><strong>Hosting, TLS-Enforced Email Delivery &amp; Cross-Border Transfer Grounds (Kenya DPA ss. 48–50):</strong> Our web application and MySQL database run on shared Linux hosting infrastructure, and transactional verification/moderation emails are sent exclusively through our configured SMTP relay with mandatory TLS encryption in production (unencrypted local local mail fallback is disabled by default; failed deliveries remain queued for encrypted retry). Where our hosting provider, SMTP relay, Google Identity Services, or OpenStreetMap operate servers outside Kenya, transfers rely on appropriate provider security/contractual safeguards (s. 48) and necessity to perform actions you initiate or explicitly opt into (s. 49(1)(a)–(b)). Infrastructure disaster-recovery snapshots are managed by the hosting operator for system restoration and age out under the hosting plan's rolling backup schedule.</li>
      </ul>

      <h2 style="color:#114B3E;">5. Data Retention Schedule Across Records</h2>
      <ul>
        <li><strong>Alumni Vetting Queue Details (House, Year Left, School Number):</strong> Deleted immediately upon listing approval or rejection, or automatically after <strong>30 days</strong> if unreviewed.</li>
        <li><strong>Transactional Email Outbox Records:</strong> Recipient address, reply-to address, subject line, and message body are automatically redacted after <strong>30 days</strong>.</li>
        <li><strong>Authentication Codes &amp; Magic-Link Tokens:</strong> Single-use verification codes expire within 15–30 minutes and token records are purged after <strong>7 days</strong>.</li>
        <li><strong>Owner Sessions &amp; Anti-Spam Rate-Limit Hashes:</strong> Revoked or expired owner session records are purged during scheduled cleanup, and anti-spam rate-limit hashes are cleared after <strong>24 hours</strong>.</li>
        <li><strong>Analytics Consent Records &amp; Usage Statistics:</strong> Server-side analytics consent provenance expires after a fixed <strong>12-month (365-day)</strong> period (or earlier upon withdrawal), while consented visit statistics and listing click events are deleted after <strong>6 months (180 days)</strong>.</li>
        <li><strong>Resolved Listing Takedown &amp; Correction Requests:</strong> Requester name, email address, narrative reason, and security hash are redacted <strong>90 days</strong> after resolution while keeping non-personal status counts.</li>
        <li><strong>Custom Category Submitter Emails:</strong> Cleared once the suggested category is reviewed (or after <strong>30 days</strong>).</li>
        <li><strong>Moderation Audit Trail:</strong> Personal actor email identifiers and IP hashes are pseudonymized after <strong>12 months (365 days)</strong>.</li>
      </ul>

      <h2 style="color:#114B3E;">6. Your Data-Subject Rights: Access, JSON Export, Correction, Pause &amp; Erasure</h2>
      <p>
        Under Sections 26, 38, and 40 of the <strong>Kenya Data Protection Act, 2019</strong>, you may exercise access, correction, portability, objection, restriction, and erasure rights at any time:
      </p>
      <ul>
        <li><strong>Self-Service JSON Data Export &amp; Account Erasure:</strong> Sign in at <a href="/owner/login.php" style="color:#114B3E;font-weight:700;">Owner Sign-In</a> and open <strong>My Businesses</strong> to download a JSON export of your Owner Account profile, linked business listings, active/past session metadata, takedown/correction records, and held correspondence (with third-party email addresses redacted to protect other individuals' privacy), or to erase your Owner Account, sessions, authentication tokens, and personal identifiers in retained correspondence (preserving co-owned listings for co-owners, or optionally deleting sole-owned listings with durable background media cleanup if any file removal requires retry).</li>
        <li><strong>Immediate Owner Listing Pause:</strong> Signed-in linked owners — or authoritative listing owners confirming a single-use email link from our <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing Form</a> — can quarantine a listing and move its uploaded photos out of public web access immediately while review is completed.</li>
        <li><strong>Third-Party Accuracy, Privacy &amp; Minor-Data Reports:</strong> Anyone may submit a correction, privacy objection, or urgent under-18 data report via <a href="/takedown.php" style="color:#7B1113;font-weight:700;">Report or Remove a Listing</a> or by emailing <a href="mailto:admin@busherianbiasharas.com" style="color:#7B1113;font-weight:700;">admin@busherianbiasharas.com</a>.</li>
      </ul>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
