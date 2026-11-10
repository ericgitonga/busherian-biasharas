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
