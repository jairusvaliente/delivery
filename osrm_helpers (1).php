<?php
/**
 * osrm_helpers.php — Helpers for OSRM routing + polyline distance math
 */

/**
 * Ask OSRM for a driving route and return [polyline, distance_m, duration_s].
 * Returns null on failure.
 */
function osrm_fetch_route($originLat, $originLng, $destLat, $destLng) {
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=polyline',
        $originLng, $originLat, $destLng, $destLat
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'UAM-Logistics/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) return null;

    $data = json_decode($resp, true);
    if (!isset($data['routes'][0])) return null;

    $r = $data['routes'][0];
    return [
        'polyline'   => $r['geometry'],
        'distance_m' => (int)$r['distance'],
        'duration_s' => (int)$r['duration'],
    ];
}

/**
 * Decode a Google encoded polyline into an array of [lat, lng].
 */
function decode_polyline($str, $precision = 5) {
    $index = 0; $lat = 0; $lng = 0; $coords = [];
    $factor = pow(10, $precision);
    $len = strlen($str);

    while ($index < $len) {
        $shift = 0; $result = 0;
        do {
            $b = ord($str[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlat = ($result & 1) ? ~($result >> 1) : ($result >> 1);
        $lat += $dlat;

        $shift = 0; $result = 0;
        do {
            $b = ord($str[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlng = ($result & 1) ? ~($result >> 1) : ($result >> 1);
        $lng += $dlng;

        $coords[] = [$lat / $factor, $lng / $factor];
    }
    return $coords;
}

/**
 * Haversine distance in meters between two [lat, lng] points.
 */
function haversine_m($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $R * asin(min(1, sqrt($a)));
}

/**
 * Shortest distance (meters) from a point to a line segment AB.
 */
function distance_point_to_segment_m($pLat, $pLng, $aLat, $aLng, $bLat, $bLng) {
    $mPerDegLat = 111320.0;
    $mPerDegLng = 111320.0 * cos(deg2rad(($aLat + $bLat) / 2));

    $ax = 0.0;             $ay = 0.0;
    $bx = ($bLng - $aLng) * $mPerDegLng;
    $by = ($bLat - $aLat) * $mPerDegLat;
    $px = ($pLng - $aLng) * $mPerDegLng;
    $py = ($pLat - $aLat) * $mPerDegLat;

    $dx = $bx - $ax;
    $dy = $by - $ay;
    $lenSq = $dx * $dx + $dy * $dy;

    if ($lenSq < 1e-9) {
        return sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
    }

    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
    $t = max(0.0, min(1.0, $t));

    $cx = $ax + $t * $dx;
    $cy = $ay + $t * $dy;

    return sqrt(($px - $cx) ** 2 + ($py - $cy) ** 2);
}

/**
 * Minimum distance from a point to any segment of a polyline.
 * $polyline = [[lat, lng], [lat, lng], ...]
 * Returns ['distance_m' => float, 'nearest_lat' => float, 'nearest_lng' => float]
 */
function min_distance_to_polyline($pLat, $pLng, $polyline) {
    $n = count($polyline);
    if ($n === 0) {
        return ['distance_m' => null, 'nearest_lat' => null, 'nearest_lng' => null];
    }
    if ($n === 1) {
        return [
            'distance_m'  => haversine_m($pLat, $pLng, $polyline[0][0], $polyline[0][1]),
            'nearest_lat' => $polyline[0][0],
            'nearest_lng' => $polyline[0][1],
        ];
    }

    $bestDist = PHP_FLOAT_MAX;
    $bestLat  = null;
    $bestLng  = null;

    for ($i = 0; $i < $n - 1; $i++) {
        $a = $polyline[$i];
        $b = $polyline[$i + 1];

        $d = distance_point_to_segment_m($pLat, $pLng, $a[0], $a[1], $b[0], $b[1]);
        if ($d < $bestDist) {
            $bestDist = $d;
            $bestLat  = ($a[0] + $b[0]) / 2;
            $bestLng  = ($a[1] + $b[1]) / 2;
        }
    }

    return [
        'distance_m'  => $bestDist,
        'nearest_lat' => $bestLat,
        'nearest_lng' => $bestLng,
    ];
}