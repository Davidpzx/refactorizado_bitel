<?php

namespace App\Services;

// Helper de geodistancia compartido entre AsistenciaController (marcación) y
// AsistenciaPresenciaController (ping de presencia APP-04). Extraído del método
// privado inline de AsistenciaController para no duplicar la fórmula.
class GeoService
{
    // Distancia en metros entre dos coordenadas (haversine, radio terrestre 6371 km).
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
