<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class WebsiteEnricher
{
    private const MAX_BYTES = 524288; // 512 KB cap
    private const MAX_REDIRECTS = 3;
    private const TIMEOUT_SECONDS = 5;

    /**
     * L1 Adversarial SSRF Guard:
     * Validates URL scheme (http/https), port (80/443), resolves DNS, and blocks any
     * loopback, private, link-local, cloud-metadata (169.254.169.254), or reserved IP range.
     *
     * @return array{url: string, host: string, port: int, ip: string}
     */
    public static function validateSafeUrlAndResolveIp(string $rawUrl, ?callable $dnsResolver = null): array
    {
        $url = trim($rawUrl);
        if ($url === '') {
            throw new InvalidArgumentException('Please enter a website URL.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid website URL format.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS website URLs are permitted.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in URLs are not permitted.');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Only standard web ports (80 and 443) are permitted.');
        }

        $rawHost = trim((string)$parts['host'], '[]');
        if (str_contains($rawHost, '%')) {
            throw new InvalidArgumentException('SSRF Guard: IPv6 zone identifiers are forbidden.');
        }
        $host = strtolower($rawHost);
        $blockedHosts = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', 'metadata.google.internal', 'metadata'];
        if (in_array($host, $blockedHosts, true) ||
            str_ends_with($host, '.local') ||
            str_ends_with($host, '.internal') ||
            str_ends_with($host, '.localhost') ||
            str_ends_with($host, '.lan')) {
            throw new InvalidArgumentException('SSRF Guard: Local or internal hostnames are forbidden.');
        }

        // Reject shorthand, decimal-integer, octal, or hex numeric IPv4 notations (e.g. 2130706433, 0177.0.0.1, 0x7f000001, 127.1)
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            !str_contains($host, ':') &&
            preg_match('/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}$/i', $host)) {
            throw new InvalidArgumentException('SSRF Guard: Non-canonical numeric IP literals are forbidden.');
        }

        // If host is already an IP literal, validate & canonicalize it directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new InvalidArgumentException('SSRF Guard: Private, loopback, link-local, or reserved IP addresses are forbidden.');
            }
            $canonIp = self::canonicalizeIp($host) ?? $host;
            return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $canonIp];
        }

        // Resolve DNS records and verify EVERY resolved IP is globally routable
        $ips = $dnsResolver ? $dnsResolver($host) : self::resolveHostIps($host);
        if (empty($ips)) {
            throw new InvalidArgumentException('Could not resolve website domain DNS.');
        }

        $verifiedIps = [];
        foreach ($ips as $ip) {
            $ipStr = trim((string)$ip, '[]');
            if (!self::isPublicIp($ipStr)) {
                throw new InvalidArgumentException('SSRF Guard: Domain resolves to a private, loopback, or reserved IP address.');
            }
            $verifiedIps[] = self::canonicalizeIp($ipStr) ?? $ipStr;
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'ip' => (string)$verifiedIps[0],
        ];
    }

    /**
     * Format host:port:ip entry for CURLOPT_RESOLVE, bracketing IPv6 addresses so libcurl parses colons safely (#166).
     */
    public static function formatCurlResolveEntry(string $host, int $port, string $ip): string
    {
        $cleanHost = trim($host, '[]');
        $cleanIp = trim($ip, '[]');
        $formattedHost = str_contains($cleanHost, ':') ? "[{$cleanHost}]" : $cleanHost;
        $formattedIp = str_contains($cleanIp, ':') ? "[{$cleanIp}]" : $cleanIp;
        return "{$formattedHost}:{$port}:{$formattedIp}";
    }

    /**
     * Canonicalize an IP literal (unwrapping IPv4-mapped IPv6 ::ffff:0:0/96 to dotted IPv4 when applicable).
     */
    public static function canonicalizeIp(string $ip): ?string
    {
        $clean = trim($ip, '[]');
        if ($clean === '' || str_contains($clean, '%')) {
            return null;
        }
        $bin = @inet_pton($clean);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $v4 = @inet_ntop(substr($bin, 12, 4));
            return is_string($v4) ? $v4 : null;
        }
        $canon = @inet_ntop($bin);
        return is_string($canon) ? $canon : null;
    }

    /**
     * Strict IANA Special-Purpose Address Registry check (#166):
     * Unwraps IPv4-mapped (::ffff:0:0/96), IPv4-translated (::ffff:0:0:0/96), IPv4-compatible (::/96),
     * NAT64 (64:ff9b::/96, 64:ff9b:1::/48), 6to4 (2002::/16), and Teredo (2001::/32) addresses,
     * and blocks all non-globally-routable IPv4 & IPv6 CIDR ranges including CGNAT (100.64.0.0/10),
     * IETF protocol assignments (192.0.0.0/24), benchmarking (198.18.0.0/15), ULA (fc00::/7), link-local,
     * loopback, unspecified, documentation, and multicast ranges.
     */
    public static function isPublicIp(string $ip): bool
    {
        $clean = trim($ip, '[]');
        if ($clean === '' || str_contains($clean, '%')) {
            return false;
        }
        $bin = @inet_pton($clean);
        if ($bin === false) {
            return false;
        }

        if (strlen($bin) === 4) {
            return self::isPublicIpv4Bin($bin);
        }

        if (strlen($bin) === 16) {
            return self::isPublicIpv6Bin($bin);
        }

        return false;
    }

    private static function isPublicIpv4Bin(string $bin): bool
    {
        if (strlen($bin) !== 4) {
            return false;
        }
        $blockedV4Cidrs = [
            ['0.0.0.0', 8],          // "This network" / unspecified
            ['10.0.0.0', 8],         // RFC 1918 Private-Use
            ['100.64.0.0', 10],      // RFC 6598 Shared Address Space (CGNAT)
            ['127.0.0.0', 8],        // Loopback
            ['169.254.0.0', 16],     // Link-Local & Cloud Metadata (169.254.169.254)
            ['172.16.0.0', 12],      // RFC 1918 Private-Use
            ['192.0.0.0', 24],       // RFC 6890 IETF Protocol Assignments
            ['192.0.2.0', 24],       // RFC 5737 TEST-NET-1
            ['192.88.99.0', 24],     // Deprecated 6to4 Relay Anycast
            ['192.168.0.0', 16],     // RFC 1918 Private-Use
            ['198.18.0.0', 15],      // RFC 2544 Benchmarking
            ['198.51.100.0', 24],    // RFC 5737 TEST-NET-2
            ['203.0.113.0', 24],     // RFC 5737 TEST-NET-3
            ['224.0.0.0', 4],        // Multicast
            ['240.0.0.0', 4],        // Reserved for Future Use & Limited Broadcast (255.255.255.255)
        ];
        foreach ($blockedV4Cidrs as [$netStr, $prefixLen]) {
            $netBin = @inet_pton($netStr);
            if ($netBin !== false && self::binMatchesPrefix($bin, $netBin, $prefixLen)) {
                return false;
            }
        }
        return true;
    }

    private static function isPublicIpv6Bin(string $bin): bool
    {
        if (strlen($bin) !== 16) {
            return false;
        }

        // 1. IPv4-mapped IPv6 (::ffff:0:0/96) -> unwrap embedded IPv4 and reject non-public ranges
        if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return self::isPublicIpv4Bin(substr($bin, 12, 4));
        }

        // 2. IPv4-translated SIIT (::ffff:0:0:0/96) -> unwrap embedded IPv4
        if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00") {
            return self::isPublicIpv4Bin(substr($bin, 12, 4));
        }

        // 3. Deprecated IPv4-compatible IPv6 (::/96), including ::/128 (unspecified) and ::1/128 (loopback)
        if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00") {
            return false;
        }

        // 4. Local-Use IPv4/IPv6 Translation (64:ff9b:1::/48, RFC 8215) -> always non-global
        $nat64Local = @inet_pton('64:ff9b:1::');
        if ($nat64Local !== false && self::binMatchesPrefix($bin, $nat64Local, 48)) {
            return false;
        }

        // 5. Well-Known NAT64 Prefix (64:ff9b::/96, RFC 6052) -> unwrap embedded IPv4 in bytes 12..15
        $nat64WellKnown = @inet_pton('64:ff9b::');
        if ($nat64WellKnown !== false && self::binMatchesPrefix($bin, $nat64WellKnown, 96)) {
            return self::isPublicIpv4Bin(substr($bin, 12, 4));
        }

        // 6. 6to4 Transition Prefix (2002::/16, deprecated anycast RFC 7526): reject outright (also embeds IPv4 in bytes 2..5)
        if (substr($bin, 0, 2) === "\x20\x02") {
            return false;
        }

        // 7. Teredo Tunneling (2001:0000::/32, RFC 4380) & IETF Protocol Assignments (2001::/23): reject outright
        $ietf2001 = @inet_pton('2001::');
        if ($ietf2001 !== false && self::binMatchesPrefix($bin, $ietf2001, 23)) {
            return false;
        }

        // 8. Remaining IANA IPv6 Special-Purpose Ranges
        $blockedV6Cidrs = [
            ['100::', 64],           // Discard-Only (RFC 6666)
            ['2001:db8::', 32],      // Documentation (RFC 3849)
            ['fc00::', 7],           // Unique Local Addresses (ULA, fc00:: - fdff::)
            ['fe80::', 10],          // Link-Local Unicast
            ['fec0::', 10],          // Deprecated Site-Local
            ['ff00::', 8],           // Multicast
        ];
        foreach ($blockedV6Cidrs as [$netStr, $prefixLen]) {
            $netBin = @inet_pton($netStr);
            if ($netBin !== false && self::binMatchesPrefix($bin, $netBin, $prefixLen)) {
                return false;
            }
        }

        // 9. Defense-in-depth: require Global Unicast allocation (2000::/3 -> top 3 bits 001)
        return ((ord($bin[0]) & 0xE0) === 0x20);
    }

    private static function binMatchesPrefix(string $ipBin, string $netBin, int $prefixBits): bool
    {
        $fullBytes = intdiv($prefixBits, 8);
        $remBits = $prefixBits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    private static function resolveHostIps(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Fetch URL with SSRF IP pinning (CURLOPT_RESOLVE), manual redirect validation, and 512KB cap,
     * then extract structured business metadata to populate the registration form.
     */
    public static function fetchAndExtract(string $rawUrl, ?PDO $pdo = null): array
    {
        $currentUrl = $rawUrl;
        $html = '';
        $finalUrl = $rawUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $validated = self::validateSafeUrlAndResolveIp($currentUrl);
            $finalUrl = $validated['url'];

            $ch = curl_init($validated['url']);
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize HTTP client.');
            }

            $buffer = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false, // Validate each redirect manually against SSRF
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT => 'BusherianBiasharas-AutoFill/1.0 (+https://www.busherianbiasharas.com)',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-KE,en;q=0.9',
                ],
                // Pin DNS resolution to the verified public IP to defeat DNS rebinding
                CURLOPT_RESOLVE => [self::formatCurlResolveEntry($validated['host'], $validated['port'], $validated['ip'])],
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
                    if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                        $remaining = self::MAX_BYTES - strlen($buffer);
                        if ($remaining > 0) {
                            $buffer .= substr($chunk, 0, $remaining);
                        }
                        return 0; // Stop transfer once 512 KB cap is reached
                    }
                    $buffer .= $chunk;
                    return strlen($chunk);
                },
            ]);

            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($httpCode === 0 && $buffer === '') {
                throw new RuntimeException('Could not connect to the website within the 5-second timeout.');
            }

            $rawHeaders = substr($buffer, 0, $headerSize);
            $body = substr($buffer, $headerSize);

            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                if ($hop === self::MAX_REDIRECTS) {
                    throw new RuntimeException('Too many redirects from website URL.');
                }
                if (preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $m)) {
                    $nextUrl = trim($m[1]);
                    if (str_starts_with($nextUrl, '/')) {
                        $parts = parse_url($validated['url']);
                        $nextUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $nextUrl;
                    }
                    $currentUrl = $nextUrl;
                    continue;
                }
            }

            $html = $body;
            break;
        }

        return self::extractFromHtml($html, $finalUrl, $pdo);
    }

    /**
     * Extract Business Name, Tagline, Description, Phone, WhatsApp, Email, Social Media profiles,
     * Address, Coordinates, Operating Hours, and Suggested 2-Level Category from HTML.
     */
    public static function extractFromHtml(string $html, string $sourceUrl, ?PDO $pdo = null): array
    {
        if (!preg_match('#^https?://#i', $sourceUrl)) {
            $sourceUrl = 'https://' . trim($sourceUrl);
        }

        $data = [
            'website_url' => $sourceUrl,
            'name' => '',
            'tagline' => '',
            'description' => '',
            'phone_primary' => '',
            'whatsapp_number' => '',
            'email_public' => '',
            'street_road' => '',
            'area_neighborhood' => '',
            'city_county' => 'Nairobi',
            'building_name' => '',
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => '',
            'logo_url' => '',
            'social_instagram' => '',
            'social_linkedin' => '',
            'social_x' => '',
            'social_facebook' => '',
            'social_tiktok' => '',
            'social_youtube' => '',
            'operating_hours' => null,
            'suggested_category' => null,
            'populated_fields' => [],
            'field_provenance' => [],
        ];

        // 1. Parse Schema.org JSON-LD (<script type="application/ld+json">)
        if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $jsonRaw) {
                $decoded = json_decode(trim($jsonRaw), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $nodes = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    $nodes = $decoded;
                }
                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    if ($data['name'] === '' && !empty($node['name']) && is_string($node['name'])) {
                        $data['name'] = self::cleanText($node['name'], 160);
                    }
                    if ($data['tagline'] === '' && !empty($node['slogan']) && is_string($node['slogan'])) {
                        $data['tagline'] = self::cleanText($node['slogan'], 175);
                    }
                    if ($data['description'] === '' && !empty($node['description']) && is_string($node['description'])) {
                        $data['description'] = self::cleanText($node['description'], 800);
                    }
                    if ($data['phone_primary'] === '' && !empty($node['telephone']) && is_string($node['telephone'])) {
                        $data['phone_primary'] = self::normalizeKenyanPhone($node['telephone']);
                    }
                    if ($data['email_public'] === '' && !empty($node['email']) && is_string($node['email'])) {
                        $data['email_public'] = trim(str_replace('mailto:', '', $node['email']));
                    }
                    if (isset($node['address']) && is_array($node['address'])) {
                        $addr = $node['address'];
                        if (!empty($addr['streetAddress']) && is_string($addr['streetAddress'])) {
                            $data['street_road'] = self::cleanText($addr['streetAddress'], 140);
                        }
                        if (!empty($addr['addressLocality']) && is_string($addr['addressLocality'])) {
                            $data['city_county'] = self::cleanText($addr['addressLocality'], 80);
                        }
                        if (!empty($addr['addressRegion']) && is_string($addr['addressRegion']) && $data['area_neighborhood'] === '') {
                            $data['area_neighborhood'] = self::cleanText($addr['addressRegion'], 100);
                        }
                    }
                    if (isset($node['geo']) && is_array($node['geo'])) {
                        if (isset($node['geo']['latitude'], $node['geo']['longitude'])) {
                            $data['latitude'] = round((float)$node['geo']['latitude'], 6);
                            $data['longitude'] = round((float)$node['geo']['longitude'], 6);
                        }
                    }
                    if (!empty($node['sameAs'])) {
                        $sameAs = is_array($node['sameAs']) ? $node['sameAs'] : [$node['sameAs']];
                        foreach ($sameAs as $sUrl) {
                            if (is_string($sUrl)) {
                                self::assignSocialUrl($data, $sUrl);
                            }
                        }
                    }
                }
            }
        }

        // 2. OpenGraph & HTML Meta / Title Fallbacks
        $metaTitle = self::extractMeta($html, 'og:site_name')
            ?: self::extractMeta($html, 'og:title')
            ?: self::extractTag($html, 'title');

        if ($data['name'] === '' && $metaTitle !== '') {
            // Strip common suffix like " | Home" or " - Official Website"
            $parts = preg_split('/\s*[|\-–—]\s*/u', $metaTitle) ?: [$metaTitle];
            $data['name'] = self::cleanText($parts[0], 160);
            if ($data['tagline'] === '' && isset($parts[1]) && strlen(trim($parts[1])) >= 10) {
                $data['tagline'] = self::cleanText($parts[1], 175);
            }
        }

        $metaDesc = self::extractMeta($html, 'og:description')
            ?: self::extractMeta($html, 'description')
            ?: self::extractMeta($html, 'twitter:description');

        if ($data['description'] === '' && $metaDesc !== '') {
            $data['description'] = self::cleanText($metaDesc, 800);
        }
        if ($data['tagline'] === '' && $data['description'] !== '') {
            $data['tagline'] = self::cleanText($data['description'], 160);
        }

        $ogImage = self::extractMeta($html, 'og:image');
        if ($ogImage !== '') {
            $data['logo_url'] = $ogImage;
        }

        // 3. Extract Phone, WhatsApp, Email & Social Links from anchors and page text
        if (preg_match_all('#href=["\']([^"\']+)["\']#i', $html, $hrefMatches)) {
            foreach ($hrefMatches[1] as $href) {
                $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with(strtolower($href), 'tel:') && $data['phone_primary'] === '') {
                    $data['phone_primary'] = self::normalizeKenyanPhone(substr($href, 4));
                } elseif (str_starts_with(strtolower($href), 'mailto:') && $data['email_public'] === '') {
                    $mail = explode('?', substr($href, 7))[0];
                    if (filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                        $data['email_public'] = strtolower(trim($mail));
                    }
                } elseif (preg_match('#(?:wa\.me/|api\.whatsapp\.com/send\?[^"\']*phone=)([+0-9]+)#i', $href, $wm)) {
                    $data['whatsapp_number'] = self::normalizeKenyanPhone($wm[1]);
                } elseif (LocationEngine::isMapsUrl($href) || str_contains($href, 'google.com/maps') || str_contains($href, 'maps.app.goo.gl') || str_contains($href, 'goo.gl/maps')) {
                    if ($data['google_maps_url'] === '') {
                        $data['google_maps_url'] = $href;
                    }
                    $allowExtMaps = (getenv('BBIZ_OFFLINE_ROUTING') !== '1');
                    $coords = LocationEngine::resolveCoordinatesFromUrlOrInput($href, $allowExtMaps, $data['city_county'] !== '' ? $data['city_county'] : null);
                    if ($coords && $data['latitude'] === null) {
                        $data['latitude'] = $coords['latitude'];
                        $data['longitude'] = $coords['longitude'];
                    }
                } else {
                    self::assignSocialUrl($data, $href);
                }
            }
        }

        // Regex fallback for Kenyan phone numbers in visible text (+254 7xx xxx xxx or 07xx xxx xxx or 01xx xxx xxx)
        $plainText = strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html);
        if ($data['phone_primary'] === '') {
            if (preg_match('/(?:\+254|0)\s*[17]\d{1,2}[\s\-]?\d{3}[\s\-]?\d{3}/', $plainText, $pm)) {
                $data['phone_primary'] = self::normalizeKenyanPhone($pm[0]);
            }
        }
        if ($data['whatsapp_number'] === '' && $data['phone_primary'] !== '') {
            $data['whatsapp_number'] = $data['phone_primary'];
        }

        // Regex fallback for email in visible text
        if ($data['email_public'] === '') {
            if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,10}/', $plainText, $em)) {
                $candidate = strtolower($em[0]);
                if (!str_ends_with($candidate, '.png') && !str_ends_with($candidate, '.jpg') && !str_contains($candidate, 'example.com') && !str_contains($candidate, 'sentry.io')) {
                    $data['email_public'] = $candidate;
                }
            }
        }

        // Embedded Google Maps iframe coordinate check
        if ($data['latitude'] === null && preg_match('#<iframe[^>]+src=["\']([^"\']*google\.com/maps[^"\']*)["\']#i', $html, $im)) {
            $coords = LocationEngine::parseCoordinatesFromInput($im[1]);
            if ($coords) {
                $data['latitude'] = $coords['latitude'];
                $data['longitude'] = $coords['longitude'];
            }
        }

        // Detect Kenyan neighborhood / building cues from text if not in JSON-LD
        if ($data['area_neighborhood'] === '') {
            $areas = ['Westlands', 'Upper Hill', 'Kilimani', 'Karen', 'Lavington', 'Riverside', 'Parklands', 'Kileleshwa', 'CBD', 'Industrial Area', 'Mombasa Road', 'Thika Road', 'Waiyaki Way', 'Gigiri', 'Runda', 'Muthaiga', 'Ngong Road', 'Kikuyu', 'Thika', 'Nyali'];
            foreach ($areas as $area) {
                if (stripos($plainText, $area) !== false) {
                    $data['area_neighborhood'] = $area;
                    break;
                }
            }
        }

        // 4. Auto-Suggest Level 1 Sector & Level 2 Specialty using CategoryMatcher
        if ($pdo !== null) {
            $combinedText = implode(' ', [
                $data['name'],
                $data['tagline'],
                $data['description'],
                self::extractMeta($html, 'keywords'),
            ]);
            $data['suggested_category'] = CategoryMatcher::suggestCategoryFromText($pdo, $combinedText);
        }

        // Record which fields were populated and attach INDRA-style evidentiary provenance tags
        $hasJsonLd = (bool)preg_match('#<script[^>]*type=["\x27]application/ld\+json["\x27]#i', $html);
        foreach ([
            'name', 'tagline', 'description', 'phone_primary', 'whatsapp_number',
            'email_public', 'street_road', 'area_neighborhood', 'city_county',
            'latitude', 'longitude', 'social_instagram', 'social_linkedin',
            'social_x', 'social_facebook', 'social_tiktok', 'social_youtube',
        ] as $field) {
            if (!empty($data[$field])) {
                $data['populated_fields'][] = $field;
                if (in_array($field, ['name', 'tagline', 'description'], true)) {
                    $data['field_provenance'][$field] = $hasJsonLd ? 'schema_org_json_ld' : 'html_opengraph_meta';
                } elseif (in_array($field, ['phone_primary', 'whatsapp_number', 'email_public'], true)) {
                    $data['field_provenance'][$field] = $hasJsonLd ? 'schema_org_json_ld' : 'dom_contact_link';
                } elseif (str_starts_with($field, 'social_')) {
                    $data['field_provenance'][$field] = 'verified_social_anchor';
                } else {
                    $data['field_provenance'][$field] = $hasJsonLd ? 'schema_org_postal_geo' : 'kenya_locality_detector';
                }
            }
        }
        if ($data['suggested_category'] !== null) {
            $data['populated_fields'][] = 'suggested_category';
            $conf = $data['suggested_category']['confidence'] ?? 0.85;
            $data['field_provenance']['suggested_category'] = "taxonomy_classifier:confidence={$conf}";
        }

        return $data;
    }

    private static function extractMeta(string $html, string $nameOrProperty): string
    {
        $quoted = preg_quote($nameOrProperty, '#');
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m) ||
            preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\']#i', $html, $m)) {
            return self::cleanText($m[1], 800);
        }
        return '';
    }

    private static function extractTag(string $html, string $tag): string
    {
        $quoted = preg_quote($tag, '#');
        if (preg_match('#<' . $quoted . '[^>]*>(.*?)</' . $quoted . '>#is', $html, $m)) {
            return self::cleanText(strip_tags($m[1]), 300);
        }
        return '';
    }

    private static function assignSocialUrl(array &$data, string $url): void
    {
        $lower = strtolower($url);
        if (str_contains($lower, 'instagram.com/') && $data['social_instagram'] === '') {
            $data['social_instagram'] = $url;
        } elseif (str_contains($lower, 'linkedin.com/') && $data['social_linkedin'] === '') {
            $data['social_linkedin'] = $url;
        } elseif ((str_contains($lower, 'x.com/') || str_contains($lower, 'twitter.com/')) && $data['social_x'] === '') {
            $data['social_x'] = $url;
        } elseif (str_contains($lower, 'facebook.com/') && !str_contains($lower, 'sharer') && $data['social_facebook'] === '') {
            $data['social_facebook'] = $url;
        } elseif (str_contains($lower, 'tiktok.com/') && $data['social_tiktok'] === '') {
            $data['social_tiktok'] = $url;
        } elseif (str_contains($lower, 'youtube.com/') && $data['social_youtube'] === '') {
            $data['social_youtube'] = $url;
        }
    }

    public static function normalizeKenyanPhone(string $raw): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($raw)) ?? '';
        if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
            return '+254' . $m[1];
        }
        if (preg_match('/^254([17]\d{8})$/', $digits, $m)) {
            return '+254' . $m[1];
        }
        return $digits;
    }

    private static function cleanText(string $text, int $maxLength = 500): string
    {
        $decoded = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = trim(preg_replace('/\s+/u', ' ', $decoded) ?? '');
        if (mb_strlen($collapsed) > $maxLength) {
            return mb_substr($collapsed, 0, $maxLength - 1) . '…';
        }
        return $collapsed;
    }
}
