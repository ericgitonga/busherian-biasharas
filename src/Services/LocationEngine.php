<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

final class LocationEngine
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const OLC_ALPHABET = '23456789CFGHJMPQRVWX';

    private const KENYA_CITY_CENTROIDS = [
        'nairobi'  => [-1.286389, 36.817223],
        'kikuyu'   => [-1.246300, 36.662900],
        'mombasa'  => [-4.043477, 39.668206],
        'kisumu'   => [-0.091702, 34.767956],
        'nakuru'   => [-0.303099, 36.080025],
        'eldoret'  => [0.514277, 35.269779],
        'thika'    => [-1.033260, 37.069330],
        'nyeri'    => [-0.420130, 36.947590],
        'kiambu'   => [-1.171390, 36.835560],
        'machakos' => [-1.517680, 37.263410],
    ];

    /**
     * Exact Spherical Haversine distance in kilometers.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Bounding-box pre-filter coordinates for indexed SQL queries before exact Haversine sorting.
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.045;
        $cosLat = max(0.01, abs(cos(deg2rad($lat))));
        $lngDelta = $radiusKm / (111.045 * $cosLat);

        return [
            'minLat' => round($lat - $latDelta, 7),
            'maxLat' => round($lat + $latDelta, 7),
            'minLng' => round($lng - $lngDelta, 7),
            'maxLng' => round($lng + $lngDelta, 7),
        ];
    }

    /**
     * Parse coordinates from a Google Maps URL or raw "lat, lng" string.
     */
    public static function parseCoordinatesFromInput(string $input): ?array
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }

        // Pattern 1: !3d-1.2647!4d36.8021 (exact pin in Google Maps URLs)
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $raw, $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 2: @-1.2647,36.8021 or ?q=-1.2647,36.8021 or ll=-1.2647,36.8021
        if (preg_match('/(?:@|[?&](?:q|ll|center| destination)=)(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/i', urldecode($raw), $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 3: Raw "lat, lng"
        if (preg_match('/^\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\s*$/', $raw, $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 4: Open Location Code / Plus Code (e.g. "6GCRPPP3+9M" or "PPP3+9M Nairobi")
        return self::decodePlusCode($raw);
    }

    /**
     * Decode full (8+2) or short (4+2 with city name) Open Location Code (Plus Code).
     */
    public static function decodePlusCode(string $input): ?array
    {
        $clean = strtoupper(trim($input));
        if (!str_contains($clean, '+')) {
            return null;
        }

        if (!preg_match('/([23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3})(?:\s+([A-Z]+))?/', $clean, $m)) {
            return null;
        }

        $code = $m[1];
        $locality = strtolower($m[2] ?? 'nairobi');
        [$prefix, $suffix] = explode('+', $code, 2);

        // If short code (4 or 6 chars before '+'), recover full 8-char prefix using reference city centroid
        if (strlen($prefix) < 8) {
            $ref = self::KENYA_CITY_CENTROIDS[$locality] ?? self::KENYA_CITY_CENTROIDS['nairobi'];
            $fullRef = self::encodePlusCode($ref[0], $ref[1]);
            $fullPrefix = substr($fullRef, 0, 8 - strlen($prefix)) . $prefix;
            $prefix = $fullPrefix;
        }

        $full = $prefix . $suffix;
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $lat = -90.0;
        $lng = -180.0;

        $pairs = min(5, intdiv(strlen($full), 2));
        for ($i = 0; $i < $pairs; $i++) {
            $latChar = $full[$i * 2];
            $lngChar = $full[$i * 2 + 1];
            $latIdx = strpos(self::OLC_ALPHABET, $latChar);
            $lngIdx = strpos(self::OLC_ALPHABET, $lngChar);
            if ($latIdx === false || $lngIdx === false) {
                return null;
            }
            $lat += $latIdx * $weights[$i];
            $lng += $lngIdx * $weights[$i];
        }

        $res = $weights[$pairs - 1];
        return self::validateCoords(round($lat + ($res / 2.0), 6), round($lng + ($res / 2.0), 6));
    }

    public static function encodePlusCode(float $lat, float $lng): string
    {
        $adjLat = max(0.0, min(179.999999, $lat + 90.0));
        $adjLng = max(0.0, min(359.999999, $lng + 180.0));
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $w = $weights[$i];
            $latDigit = (int)floor($adjLat / $w) % 20;
            $lngDigit = (int)floor($adjLng / $w) % 20;
            $out .= self::OLC_ALPHABET[$latDigit] . self::OLC_ALPHABET[$lngDigit];
            $adjLat -= $latDigit * $w;
            $adjLng -= $lngDigit * $w;
            if ($i === 3) {
                $out .= '+';
            }
        }
        return $out;
    }

    private static function validateCoords(float $lat, float $lng): ?array
    {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }
        return ['latitude' => round($lat, 6), 'longitude' => round($lng, 6)];
    }

    /**
     * Compute human-readable 8-point compass bearing from (lat1,lng1) to (lat2,lng2).
     */
    public static function compassBearing(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
           - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLng);
        $brng = fmod((rad2deg(atan2($y, $x)) + 360.0), 360.0);
        $dirs = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        return $dirs[(int)round($brng / 45.0) % 8];
    }

    /**
     * Calculate exact straight-line distance, driving road distance, estimated drive duration,
     * road polyline geometry, and turn-by-turn street directions (OSRM with automatic offline fallback).
     */
    public static function calculateRouteAndDirections(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        bool $allowExternalRouting = true
    ): array {
        $straightKm = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
        $bearing = self::compassBearing($fromLat, $fromLng, $toLat, $toLng);

        if ($allowExternalRouting && function_exists('curl_init')) {
            $osrmUrl = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%0.6F,%0.6F;%0.6F,%0.6F?overview=full&geometries=geojson&steps=true',
                $fromLng,
                $fromLat,
                $toLng,
                $toLat
            );
            $ch = curl_init($osrmUrl);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'BusherianBiasharasDirectory/0.3 (+https://www.busherianbiasharas.com)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($raw) && $code === 200) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && ($json['code'] ?? '') === 'Ok' && !empty($json['routes'][0])) {
                        $route = $json['routes'][0];
                        $roadKm = round(((float)($route['distance'] ?? 0)) / 1000.0, 2);
                        $durationMin = max(1, (int)round(((float)($route['duration'] ?? 0)) / 60.0));

                        $latLngs = [];
                        foreach (($route['geometry']['coordinates'] ?? []) as $pt) {
                            if (is_array($pt) && count($pt) >= 2) {
                                $latLngs[] = [round((float)$pt[1], 6), round((float)$pt[0], 6)];
                            }
                        }

                        $steps = [];
                        foreach (($route['legs'][0]['steps'] ?? []) as $st) {
                            $maneuver = $st['maneuver'] ?? [];
                            $type = (string)($maneuver['type'] ?? 'continue');
                            $modifier = (string)($maneuver['modifier'] ?? '');
                            $roadName = trim((string)($st['name'] ?? ''));
                            $distM = (float)($st['distance'] ?? 0);
                            if ($distM < 8 && $type !== 'arrive') {
                                continue;
                            }
                            $distLabel = $distM >= 1000
                                ? round($distM / 1000.0, 1) . ' km'
                                : (int)round($distM) . ' m';

                            if ($type === 'depart') {
                                $text = 'Head ' . ($modifier ?: $bearing) . ($roadName !== '' ? " on {$roadName}" : '');
                            } elseif ($type === 'arrive') {
                                $text = 'Arrive at destination' . ($roadName !== '' ? " ({$roadName})" : '');
                                $distLabel = '';
                            } elseif ($type === 'roundabout' || $type === 'rotary') {
                                $exit = isset($maneuver['exit']) ? " (exit {$maneuver['exit']})" : '';
                                $text = "At the roundabout, take exit{$exit}" . ($roadName !== '' ? " onto {$roadName}" : '');
                            } elseif ($type === 'turn' || $type === 'end of road' || $type === 'fork') {
                                $dir = $modifier !== '' ? $modifier : 'ahead';
                                $text = ucfirst("Turn {$dir}") . ($roadName !== '' ? " onto {$roadName}" : '');
                            } else {
                                $text = 'Continue' . ($modifier !== '' ? " {$modifier}" : '') . ($roadName !== '' ? " on {$roadName}" : '');
                            }

                            $steps[] = [
                                'instruction' => $text,
                                'distance' => $distLabel,
                            ];
                        }

                        if (!empty($latLngs)) {
                            return [
                                'mode' => 'osrm_driving',
                                'straight_km' => $straightKm,
                                'road_km' => $roadKm,
                                'duration_min' => $durationMin,
                                'polyline' => $latLngs,
                                'steps' => array_slice($steps, 0, 18),
                            ];
                        }
                    }
                }
            }
        }

        $estRoadKm = round($straightKm * 1.28, 2);
        $estDurationMin = max(2, (int)round(($estRoadKm / 30.0) * 60.0));
        return [
            'mode' => 'estimated_direct',
            'straight_km' => $straightKm,
            'road_km' => $estRoadKm,
            'duration_min' => $estDurationMin,
            'polyline' => [
                [round($fromLat, 6), round($fromLng, 6)],
                [round($toLat, 6), round($toLng, 6)],
            ],
            'steps' => [
                [
                    'instruction' => "Head {$bearing} toward destination",
                    'distance' => "{$estRoadKm} km",
                ],
                [
                    'instruction' => 'Open Google Maps or Waze below for live turn-by-turn voice navigation',
                    'distance' => '',
                ],
            ],
        ];
    }
}
