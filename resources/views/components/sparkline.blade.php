@props([
    'data' => [],
    'color' => '#ea580c',
    'height' => 32,
])

@php
    $points = collect($data)->values()->all();
    $count = count($points);
    if ($count === 0) {
        $points = [0, 0];
        $count = 2;
    }
    $max = max($points) ?: 1;
    $width = 100;
    $h = $height;
    $step = $count > 1 ? $width / ($count - 1) : 0;

    $svgPoints = [];
    foreach ($points as $i => $val) {
        $x = round($i * $step, 2);
        $y = round($h - (($val / $max) * ($h - 4)) - 2, 2);
        $svgPoints[] = "$x,$y";
    }
    $polylinePoints = implode(' ', $svgPoints);

    // Build area path (closed below)
    $areaPath = 'M '.$svgPoints[0].' L '.implode(' L ', array_slice($svgPoints, 1))." L $width,$h L 0,$h Z";
@endphp

<svg viewBox="0 0 {{ $width }} {{ $h }}" preserveAspectRatio="none"
     class="mt-2 h-8 w-full" {{ $attributes }}>
    <path d="{{ $areaPath }}" fill="{{ $color }}" fill-opacity="0.12" />
    <polyline points="{{ $polylinePoints }}" fill="none" stroke="{{ $color }}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
</svg>
