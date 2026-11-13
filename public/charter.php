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
  <title>Community Charter &amp; Free-Forever Guarantee — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Community Charter & Free-Forever Guarantee — Busherian Biasharas', 'How Busherian Biasharas keeps core AHS & AGHS alumni listings, direct bookings, and B2B inquiries 100% free forever while sustaining secure hosting and verification.', '/charter.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest" aria-hidden="true">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Community Charter &amp; Free-Forever Core Guarantee</p>
        </div>
      </a>
      <nav class="header-actions" aria-label="Charter Navigation">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
        <a href="/privacy.php" class="btn btn-outline-light">🔒 Privacy Notice</a>
        <a href="/submit.php" class="btn btn-gold">+ List Your Biashara</a>
      </nav>
    </div>
  </header>

  <main style="max-width:920px;margin:2rem auto;padding:0 1.25rem;">
    <article data-testid="community-charter-article" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);line-height:1.6;">
      <div style="display:inline-flex;align-items:center;gap:0.4rem;background:#ECFDF5;color:#065F46;border:1px solid #6EE7B7;border-radius:999px;padding:0.22rem 0.75rem;font-size:0.76rem;font-weight:800;margin-bottom:0.75rem;">
        📜 Community Charter &bull; Keep the Front Door 100% Free
      </div>
      <h2 style="margin:0 0 0.55rem 0;color:#7B1113;font-size:1.45rem;">Our Promise to Every Busherian Founder &amp; Visitor</h2>
      <p style="margin:0 0 1.35rem 0;color:#374151;font-size:0.95rem;">
        <strong>Busherian Biasharas</strong> exists so verified alumni of <strong>Alliance High School (AHS)</strong> and <strong>Alliance Girls High School (AGHS)</strong> can discover, trust, and hire one another without paywalls at the door. Here are the four plain-language commitments governing listings, bookings, optional tools, and verification integrity:
      </p>

<?= bbiz_render_community_charter_clauses() ?>

      <hr style="border:none;border-top:1px solid #E5E7EB;margin:1.75rem 0;">

      <h3 style="margin:0 0 0.85rem 0;color:#114B3E;font-size:1.12rem;">Founder Frequently Asked Questions</h3>
      <div style="display:grid;gap:0.9rem;">
        <div style="background:#FAF8F5;border:1px solid #E5E7EB;border-radius:10px;padding:0.95rem 1.1rem;">
          <strong style="color:#111827;display:block;margin-bottom:0.25rem;">Will my free listing ever be placed behind a paywall later?</strong>
          <span style="font-size:0.88rem;color:#374151;">No. Standard verified alumni listings &mdash; including your Primary + Secondary specialties, up to 5 photos, live map pin, road directions, and direct Call / WhatsApp / Email / Website / Booking links &mdash; remain 100% free forever.</span>
        </div>
        <div style="background:#FAF8F5;border:1px solid #E5E7EB;border-radius:10px;padding:0.95rem 1.1rem;">
          <strong style="color:#111827;display:block;margin-bottom:0.25rem;">Do you charge a fee or commission when someone books an appointment or exports my firm on a B2B shortlist?</strong>
          <span style="font-size:0.88rem;color:#374151;">Never on direct bookings or self-served leads. Whether a client opens your external scheduler (Calendly, Cal.com, Google) or sends a built-in WhatsApp/Email slot request, 100% of that relationship is directly between you and your client with zero platform fee.</span>
        </div>
        <div style="background:#FAF8F5;border:1px solid #E5E7EB;border-radius:10px;padding:0.95rem 1.1rem;">
          <strong style="color:#111827;display:block;margin-bottom:0.25rem;">Can a corporate sponsor or Pro upgrade override organic search oralumni verification?</strong>
          <span style="font-size:0.88rem;color:#374151;">No. Every alumni business undergoes the exact same one-time AHS/AGHS verification. Non-alumni companies can never buy an alumni listing; any corporate partner is restricted to at most 1 clearly labeled Sponsor slot per sector, and any optional alumni spotlight is capped at 2 clearly badged spots per category.</span>
        </div>
      </div>

      <div style="margin-top:1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
        <a href="/submit.php" class="btn btn-crimson">+ List Your Biashara (100% Free)</a>
        <a href="/" class="btn btn-outline">Browse Verified Directory</a>
        <a href="/privacy.php" class="btn btn-outline">Read Full Privacy Notice</a>
      </div>
    </article>
  </main>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
