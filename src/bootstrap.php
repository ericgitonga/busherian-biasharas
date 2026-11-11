<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

spl_autoload_register(static function (string $class): void {
    $prefix = 'BusherianBiasharas\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\BusherianBiasharas\Services\PreprodSandbox::boot();

if (!function_exists('bbiz_send_security_headers')) {
    function bbiz_send_security_headers(bool $isJson = false): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if ($isJson) {
            header('Content-Type: application/json; charset=UTF-8');
        }
    }
}

if (!function_exists('bbiz_head_meta_tags')) {
    function bbiz_head_meta_tags(
        string $title = 'Busherian Biasharas — Verified Alumni Business Directory (AHS & AGHS)',
        string $description = 'Discover and support verified businesses owned by alumni of Alliance High School (AHS) and Alliance Girls High School (AGHS) — Strong to Serve & Walk in the Light.',
        string $canonicalPath = '/'
    ): string {
        $baseUrl = rtrim((string)(getenv('APP_URL') ?: 'https://www.busherianbiasharas.com'), '/');
        $cleanPath = '/' . ltrim($canonicalPath, '/');
        $fullUrl = $baseUrl . ($cleanPath === '/index.php' ? '/' : $cleanPath);
        $ogImage = $baseUrl . '/assets/img/og-preview.png';
        $squareIcon = $baseUrl . '/assets/img/bb-icon-512.png';

        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $d = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $u = htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8');
        $ogImg = htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8');
        $sqImg = htmlspecialchars($squareIcon, ENT_QUOTES, 'UTF-8');

        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Busherian Biasharas',
            'alternateName' => 'AHS & AGHS Verified Alumni Business Directory',
            'url' => $baseUrl . '/',
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Busherian Biasharas',
                'url' => $baseUrl . '/',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $squareIcon,
                    'width' => 512,
                    'height' => 512,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return <<<HTML
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#114B3E">
  <link rel="canonical" href="{$u}">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Busherian Biasharas">
  <meta property="og:locale" content="en_KE">
  <meta property="og:title" content="{$t}">
  <meta property="og:description" content="{$d}">
  <meta property="og:url" content="{$u}">
  <meta property="og:image" content="{$ogImg}">
  <meta property="og:image:secure_url" content="{$ogImg}">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS)">
  <meta property="og:image" content="{$sqImg}">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{$t}">
  <meta name="twitter:description" content="{$d}">
  <meta name="twitter:image" content="{$ogImg}">
  <script type="application/ld+json">{$jsonLd}</script>
HTML;
    }
}

if (!function_exists('bbiz_render_hours_and_photos_fields')) {
    function bbiz_render_hours_and_photos_fields(?string $hoursJson = null, ?string $photosJson = null): string
    {
        $h = \BusherianBiasharas\Services\HoursEngine::extractFormValues($hoursJson);
        $existingPhotos = \BusherianBiasharas\Services\PhotoUploader::decodePhotosJson($photosJson);

        $wkOpen = htmlspecialchars($h['weekday_open'], ENT_QUOTES, 'UTF-8');
        $wkClose = htmlspecialchars($h['weekday_close'], ENT_QUOTES, 'UTF-8');
        $satOpen = htmlspecialchars($h['sat_open'], ENT_QUOTES, 'UTF-8');
        $satClose = htmlspecialchars($h['sat_close'], ENT_QUOTES, 'UTF-8');
        $satClosedAttr = !empty($h['sat_closed']) ? 'checked' : '';
        $satDisabledAttr = !empty($h['sat_closed']) ? 'disabled' : '';
        $sunOpen = htmlspecialchars($h['sun_open'], ENT_QUOTES, 'UTF-8');
        $sunClose = htmlspecialchars($h['sun_close'], ENT_QUOTES, 'UTF-8');
        $sunClosedAttr = !empty($h['sun_closed']) ? 'checked' : '';
        $sunDisabledAttr = !empty($h['sun_closed']) ? 'disabled' : '';

        $existingPhotosHtml = '';
        if (!empty($existingPhotos)) {
            $cards = '';
            foreach ($existingPhotos as $idx => $photoUrl) {
                $safeUrl = htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8');
                $num = $idx + 1;
                $cards .= <<<CARD
                <div class="existing-photo-item" style="border:1px solid #D1D5DB;border-radius:10px;padding:0.45rem;background:#fff;width:130px;text-align:center;">
                  <img src="{$safeUrl}" alt="Business Photo {$num}" style="width:100%;height:82px;object-fit:cover;border-radius:6px;display:block;margin-bottom:0.35rem;">
                  <label style="font-size:0.74rem;color:#991B1B;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.25rem;">
                    <input type="checkbox" name="remove_photos[]" value="{$safeUrl}" class="remove-photo-checkbox"> Remove
                  </label>
                </div>
CARD;
            }
            $existingPhotosHtml = <<<WRAP
            <div style="margin-bottom:0.75rem;">
              <div style="font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:0.35rem;">Current Uploaded Photos (Check "Remove" to delete upon saving):</div>
              <div style="display:flex;gap:0.65rem;flex-wrap:wrap;" id="existing-photos-list">{$cards}</div>
            </div>
WRAP;
        }

        return <<<HTML
        <!-- OPERATING HOURS: MON-FRI, SATURDAY & SUNDAY (EAT) -->
        <div style="margin-top:1rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:1rem;">
          <div style="font-weight:800;font-size:0.88rem;color:#114B3E;margin-bottom:0.65rem;">
            🕒 Operating Hours (EAT — Monday to Sunday)
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:0.9rem;">
            <!-- Monday to Friday -->
            <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:0.75rem;">
              <div style="font-weight:700;font-size:0.82rem;color:#1F2937;margin-bottom:0.45rem;">Monday – Friday</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                  <label for="hours_weekday_open" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Open</label>
                  <input type="time" id="hours_weekday_open" name="hours_weekday_open" class="input-control" value="{$wkOpen}">
                </div>
                <div>
                  <label for="hours_weekday_close" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Close</label>
                  <input type="time" id="hours_weekday_close" name="hours_weekday_close" class="input-control" value="{$wkClose}">
                </div>
              </div>
            </div>

            <!-- Saturday -->
            <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:0.75rem;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.45rem;">
                <span style="font-weight:700;font-size:0.82rem;color:#1F2937;">Saturday</span>
                <label style="font-size:0.75rem;color:#4B5563;display:flex;align-items:center;gap:0.25rem;cursor:pointer;">
                  <input type="checkbox" id="hours_sat_closed" name="hours_sat_closed" value="1" {$satClosedAttr}> Closed
                </label>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                  <label for="hours_sat_open" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Open</label>
                  <input type="time" id="hours_sat_open" name="hours_sat_open" class="input-control" value="{$satOpen}" {$satDisabledAttr}>
                </div>
                <div>
                  <label for="hours_sat_close" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Close</label>
                  <input type="time" id="hours_sat_close" name="hours_sat_close" class="input-control" value="{$satClose}" {$satDisabledAttr}>
                </div>
              </div>
            </div>

            <!-- Sunday -->
            <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:0.75rem;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.45rem;">
                <span style="font-weight:700;font-size:0.82rem;color:#1F2937;">Sunday</span>
                <label style="font-size:0.75rem;color:#4B5563;display:flex;align-items:center;gap:0.25rem;cursor:pointer;">
                  <input type="checkbox" id="hours_sun_closed" name="hours_sun_closed" value="1" {$sunClosedAttr}> Closed
                </label>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                  <label for="hours_sun_open" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Open</label>
                  <input type="time" id="hours_sun_open" name="hours_sun_open" class="input-control" value="{$sunOpen}" {$sunDisabledAttr}>
                </div>
                <div>
                  <label for="hours_sun_close" style="font-size:0.74rem;font-weight:600;color:#4B5563;display:block;margin-bottom:0.15rem;">Close</label>
                  <input type="time" id="hours_sun_close" name="hours_sun_close" class="input-control" value="{$sunClose}" {$sunDisabledAttr}>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- BUSINESS PHOTOS UPLOAD (OPTIONAL: 3 TO 5 RECOMMENDED, MAX 5) -->
        <div style="margin-top:1rem;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:1rem;">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.35rem;">
            <label for="business_photos" style="font-weight:800;font-size:0.88rem;color:#065F46;">
              📸 Business Photos (Optional — 3 to 5 Photos Recommended, Max 5)
            </label>
            <span id="photo-count-badge" style="font-size:0.76rem;font-weight:700;background:#D1FAE5;color:#065F46;padding:0.2rem 0.6rem;border-radius:999px;">
              Up to 5 photos • Automatically resized &amp; location privacy protected
            </span>
          </div>
          <p style="margin:0 0 0.65rem 0;font-size:0.8rem;color:#047857;">
            Showcase your storefront, products, team, or work in action. Large phone photos are automatically resized in your browser for fast upload and any private location details attached by your phone camera are automatically removed.
          </p>
          {$existingPhotosHtml}
          <input type="file" id="business_photos" name="business_photos[]" accept="image/jpeg,image/png,image/webp" multiple class="input-control" style="background:#fff;padding:0.45rem;">
          <input type="hidden" id="business_photos_data_json" name="business_photos_data_json" value="">
          <div id="business-photos-preview" style="display:flex;gap:0.65rem;flex-wrap:wrap;margin-top:0.65rem;"></div>
          <div id="business-photos-status" style="font-size:0.78rem;color:#065F46;margin-top:0.35rem;display:none;"></div>
        </div>
HTML;
    }
}


if (!function_exists('bbiz_render_place_fields')) {
    /**
     * Render cascading 2-level Place selectors (Level 1: Country -> Level 2: City / County / State) (#97).
     */
    function bbiz_render_place_fields(string $currentCountry = 'Kenya', string $currentCity = 'Nairobi'): string
    {
        $hierarchy = \BusherianBiasharas\Services\LocationEngine::getPlaceHierarchy();
        $unitLabels = \BusherianBiasharas\Services\LocationEngine::getPlaceUnitLabels();
        $curCountry = trim($currentCountry) !== '' ? trim($currentCountry) : 'Kenya';
        $curCity = trim($currentCity) !== '' ? trim($currentCity) : 'Nairobi';

        $isKnownCountry = array_key_exists($curCountry, $hierarchy);
        $countrySelectVal = $isKnownCountry ? $curCountry : '__custom__';
        $countryCustomVal = $isKnownCountry ? '' : htmlspecialchars($curCountry, ENT_QUOTES, 'UTF-8');
        $countryCustomDisplay = $isKnownCountry ? 'none' : 'block';

        $unitLabel = $isKnownCountry
            ? \BusherianBiasharas\Services\LocationEngine::getUnitLabelForCountry($curCountry)
            : 'County / State / District';
        $escUnitLabel = htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8');

        $countryOptionsHtml = '';
        foreach (array_keys($hierarchy) as $cName) {
            $sel = ($countrySelectVal === $cName) ? 'selected' : '';
            $esc = htmlspecialchars($cName, ENT_QUOTES, 'UTF-8');
            $countryOptionsHtml .= "<option value=\"{$esc}\" {$sel}>{$esc}</option>";
        }
        $customCountrySel = ($countrySelectVal === '__custom__') ? 'selected' : '';
        $countryOptionsHtml .= "<option value=\"__custom__\" {$customCountrySel}>+ Other Country (Type Below)</option>";

        $knownCities = $isKnownCountry ? $hierarchy[$curCountry] : [];
        $isKnownCity = in_array($curCity, $knownCities, true);
        $citySelectVal = ($isKnownCountry && $isKnownCity) ? $curCity : '__custom__';
        $cityCustomVal = ($citySelectVal === '__custom__') ? htmlspecialchars($curCity, ENT_QUOTES, 'UTF-8') : '';
        $cityCustomDisplay = ($citySelectVal === '__custom__') ? 'block' : 'none';

        $cityOptionsHtml = '';
        foreach ($knownCities as $plName) {
            $sel = ($citySelectVal === $plName) ? 'selected' : '';
            $esc = htmlspecialchars($plName, ENT_QUOTES, 'UTF-8');
            $cityOptionsHtml .= "<option value=\"{$esc}\" {$sel}>{$esc}</option>";
        }
        $customCitySel = ($citySelectVal === '__custom__') ? 'selected' : '';
        $cityOptionsHtml .= "<option value=\"__custom__\" {$customCitySel}>+ Other {$escUnitLabel} (Type Below)</option>";

        $hierarchyJson = htmlspecialchars((string)json_encode($hierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $unitLabelsJson = htmlspecialchars((string)json_encode($unitLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $escInitCity = htmlspecialchars($curCity, ENT_QUOTES, 'UTF-8');

        return <<<HTML
          <div data-place-hierarchy="{$hierarchyJson}" data-place-unit-labels="{$unitLabelsJson}" data-initial-city="{$escInitCity}">
            <label for="country" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Country *</label>
            <select id="country" name="country" class="select-control" required>
              {$countryOptionsHtml}
            </select>
            <input type="text" id="country_custom" name="country_custom" class="input-control"
              value="{$countryCustomVal}" placeholder="Enter Country Name..."
              style="margin-top:0.4rem;display:{$countryCustomDisplay};">
          </div>
          <div>
            <label id="city_county_label" for="city_county" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">{$escUnitLabel} *</label>
            <select id="city_county" name="city_county" class="select-control" required>
              {$cityOptionsHtml}
            </select>
            <input type="text" id="city_county_custom" name="city_county_custom" class="input-control"
              value="{$cityCustomVal}" placeholder="Enter {$escUnitLabel}..."
              style="margin-top:0.4rem;display:{$cityCustomDisplay};">
          </div>
HTML;
    }
}


if (!function_exists('bbiz_render_github_delete_modal')) {
    /**
     * Render GitHub-style 2-step Delete Listing Confirmation Modal (#126, #127, #128):
     * Step 1: Ask if the user is sure of what they are doing ("Yes, I am sure — Proceed").
     * Step 2: Require typing the exact Business Name to unlock the final Delete submit button.
     */
    function bbiz_render_github_delete_modal(
        string $csrfToken,
        string $defaultActionUrl = '',
        int $defaultBizId = 0,
        string $defaultBizName = '',
        string $actionFieldName = 'post_action',
        string $actionFieldValue = 'delete'
    ): string {
        $escCsrf = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $escActionUrl = htmlspecialchars($defaultActionUrl, ENT_QUOTES, 'UTF-8');
        $escBizName = htmlspecialchars($defaultBizName, ENT_QUOTES, 'UTF-8');
        $escActionField = htmlspecialchars($actionFieldName, ENT_QUOTES, 'UTF-8');
        $escActionVal = htmlspecialchars($actionFieldValue, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div id="bbiz-github-delete-modal" hidden style="display:none;position:fixed;inset:0;background:rgba(17,24,39,0.68);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
          <div role="dialog" aria-modal="true" aria-labelledby="delete-modal-title" style="background:#ffffff;border:2px solid #DC2626;border-radius:14px;max-width:520px;width:100%;box-shadow:0 20px 45px rgba(0,0,0,0.32);overflow:hidden;">
            <div style="background:#FEF2F2;border-bottom:1px solid #FECACA;padding:1rem 1.25rem;display:flex;justify-content:space-between;align-items:center;">
              <h3 id="delete-modal-title" style="margin:0;font-size:1.05rem;color:#991B1B;font-weight:800;">
                ⚠️ Delete Business Listing
              </h3>
              <button type="button" id="btn-delete-modal-close" aria-label="Close" style="background:none;border:none;font-size:1.25rem;font-weight:800;color:#991B1B;cursor:pointer;line-height:1;">&times;</button>
            </div>

            <div style="padding:1.25rem;">
              <div style="background:#FFFBEB;border:1px solid #F59E0B;border-radius:8px;padding:0.75rem 0.9rem;margin-bottom:1rem;font-size:0.84rem;color:#92400E;">
                <strong>Unexpected bad things will happen if you don't read this!</strong><br>
                This will permanently delete <strong id="delete-modal-biz-label">{$escBizName}</strong>, its uploaded photos, and its directory record. This action cannot be undone.
              </div>

              <!-- STEP 1: Ask if they are sure of what they are doing -->
              <div id="delete-modal-step-1">
                <p style="margin:0 0 1rem 0;font-size:0.92rem;font-weight:700;color:#111827;">
                  Are you sure you know what you are doing and want to permanently delete this business listing?
                </p>
                <div style="display:flex;justify-content:flex-end;gap:0.65rem;flex-wrap:wrap;">
                  <button type="button" id="btn-delete-step1-cancel" class="btn btn-outline">No, Keep Listing</button>
                  <button type="button" id="btn-delete-step1-yes" class="btn btn-crimson">Yes, I am sure — Proceed</button>
                </div>
              </div>

              <!-- STEP 2: Type exact business name to unlock submit button -->
              <form id="delete-modal-step-2" method="POST" action="{$escActionUrl}" style="display:none;margin:0;">
                <input type="hidden" name="csrf_token" value="{$escCsrf}">
                <input type="hidden" name="business_id" id="delete-modal-biz-id" value="{$defaultBizId}">
                <input type="hidden" name="{$escActionField}" id="delete-modal-action-field" value="{$escActionVal}">

                <label for="delete-confirm-name-input" style="display:block;font-size:0.86rem;color:#111827;margin-bottom:0.45rem;">
                  To confirm deletion, type <strong id="delete-modal-expected-name" style="color:#991B1B;user-select:all;">{$escBizName}</strong> in the box below:
                </label>
                <input type="text" id="delete-confirm-name-input" name="confirm_business_name" class="input-control"
                  autocomplete="off" spellcheck="false" placeholder="Type the exact business name..." required
                  style="margin-bottom:0.9rem;border:2px solid #FCA5A5;">

                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.65rem;">
                  <button type="button" id="btn-delete-step2-cancel" class="btn btn-outline">Cancel</button>
                  <button type="submit" id="btn-delete-final-submit" class="btn btn-crimson" disabled
                    style="flex:1;justify-content:center;opacity:0.45;cursor:not-allowed;">
                    🗑️ I understand, delete this listing
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <script>
          (function () {
            const modal = document.getElementById('bbiz-github-delete-modal');
            const step1 = document.getElementById('delete-modal-step-1');
            const step2Form = document.getElementById('delete-modal-step-2');
            const bizLabel = document.getElementById('delete-modal-biz-label');
            const expectedLabel = document.getElementById('delete-modal-expected-name');
            const bizIdInput = document.getElementById('delete-modal-biz-id');
            const nameInput = document.getElementById('delete-confirm-name-input');
            const finalSubmitBtn = document.getElementById('btn-delete-final-submit');
            const yesBtn = document.getElementById('btn-delete-step1-yes');
            const closeBtns = [
              document.getElementById('btn-delete-modal-close'),
              document.getElementById('btn-delete-step1-cancel'),
              document.getElementById('btn-delete-step2-cancel')
            ];

            if (!modal || !step1 || !step2Form || !nameInput || !finalSubmitBtn || !yesBtn) return;

            let expectedName = expectedLabel ? expectedLabel.textContent.trim() : '';

            function syncSubmitState() {
              const typed = nameInput.value.trim();
              const matches = expectedName !== '' && typed === expectedName;
              finalSubmitBtn.disabled = !matches;
              finalSubmitBtn.style.opacity = matches ? '1' : '0.45';
              finalSubmitBtn.style.cursor = matches ? 'pointer' : 'not-allowed';
            }

            function openDeleteModal(bizName, bizId, actionUrl) {
              expectedName = (bizName || '').trim();
              if (bizLabel) bizLabel.textContent = expectedName;
              if (expectedLabel) expectedLabel.textContent = expectedName;
              if (bizIdInput && bizId) bizIdInput.value = String(bizId);
              if (actionUrl) step2Form.setAttribute('action', actionUrl);

              nameInput.value = '';
              syncSubmitState();
              step1.style.display = 'block';
              step2Form.style.display = 'none';
              modal.hidden = false;
              modal.style.display = 'flex';
            }

            function closeDeleteModal() {
              modal.hidden = true;
              modal.style.display = 'none';
              nameInput.value = '';
              syncSubmitState();
            }

            yesBtn.addEventListener('click', () => {
              step1.style.display = 'none';
              step2Form.style.display = 'block';
              nameInput.focus();
            });

            nameInput.addEventListener('input', syncSubmitState);

            step2Form.addEventListener('submit', (e) => {
              if (nameInput.value.trim() !== expectedName) {
                e.preventDefault();
              }
            });

            closeBtns.forEach(btn => {
              if (btn) btn.addEventListener('click', closeDeleteModal);
            });

            modal.addEventListener('click', (e) => {
              if (e.target === modal) closeDeleteModal();
            });

            document.querySelectorAll('[data-open-delete-modal]').forEach(trigger => {
              trigger.addEventListener('click', () => {
                const bName = trigger.getAttribute('data-delete-biz-name') || expectedName;
                const bId = trigger.getAttribute('data-delete-biz-id') || (bizIdInput ? bizIdInput.value : '');
                const aUrl = trigger.getAttribute('data-delete-action-url') || step2Form.getAttribute('action');
                openDeleteModal(bName, bId, aUrl);
              });
            });
          })();
        </script>
HTML;
    }
}
