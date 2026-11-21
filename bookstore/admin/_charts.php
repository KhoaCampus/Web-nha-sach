<?php
// Biểu đồ SVG thuần PHP (không cần thư viện JS, chạy được cả khi offline)

function fmt_short(float $v): string
{
    $fmt = static function (float $n): string {
        return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
    };
    if ($v >= 1e9) { return $fmt($v / 1e9) . ' tỷ'; }
    if ($v >= 1e6) { return $fmt($v / 1e6) . ' tr'; }
    if ($v >= 1e3) { return $fmt($v / 1e3) . 'k'; }
    return (string)(int)$v;
}

function nice_max(float $v): float
{
    if ($v <= 0) { return 1; }
    $exp = floor(log10($v));
    $f   = $v / pow(10, $exp);
    $nf  = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10));
    return $nf * pow(10, $exp);
}

/** Khung chung: lưới + nhãn trục. Trả về [svgOpen, plotX, plotY, plotW, plotH, max] */
function chart_frame(array $labels, float $max, int $w, int $h): array
{
    $l = 52; $r = 14; $t = 14; $b = 30;
    $pw = $w - $l - $r;
    $ph = $h - $t - $b;
    $svg = '<svg class="a-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" preserveAspectRatio="xMidYMid meet">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $t + $ph - $ph * $i / 4;
        $svg .= '<line x1="' . $l . '" y1="' . round($y, 1) . '" x2="' . ($w - $r) . '" y2="' . round($y, 1) . '" stroke="#e8eaf0" stroke-width="1"/>';
        $svg .= '<text x="' . ($l - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="11" fill="#8a8fa3">' . e(fmt_short($max * $i / 4)) . '</text>';
    }
    $n    = count($labels);
    $step = max(1, (int)ceil($n / 8));
    foreach ($labels as $i => $lb) {
        if ($i % $step !== 0) { continue; }
        $x = $l + ($n > 1 ? $pw * ($i + 0.5) / $n : $pw / 2);
        $svg .= '<text x="' . round($x, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="11" fill="#8a8fa3">' . e($lb) . '</text>';
    }
    return [$svg, $l, $t, $pw, $ph];
}

function svg_bars(array $labels, array $values, int $w = 560, int $h = 230, string $color = '#e11d2e'): string
{
    $max = nice_max((float)max($values ?: [0]));
    [$svg, $l, $t, $pw, $ph] = chart_frame($labels, $max, $w, $h);
    $n  = max(1, count($values));
    $bw = min(40, $pw / $n * 0.6);
    foreach ($values as $i => $v) {
        $bh = $max > 0 ? $ph * $v / $max : 0;
        $x  = $l + $pw * ($i + 0.5) / $n - $bw / 2;
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($t + $ph - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1)
              . '" rx="3" fill="' . $color . '"><title>' . e(($labels[$i] ?? '') . ': ' . number_format((float)$v, 0, ',', '.')) . '</title></rect>';
    }
    return $svg . '</svg>';
}

function svg_line(array $labels, array $values, int $w = 560, int $h = 230, string $color = '#e11d2e'): string
{
    $max = nice_max((float)max($values ?: [0]));
    [$svg, $l, $t, $pw, $ph] = chart_frame($labels, $max, $w, $h);
    $n   = count($values);
    $pts = [];
    foreach ($values as $i => $v) {
        $x = $l + ($n > 1 ? $pw * ($i + 0.5) / $n : $pw / 2);
        $y = $t + $ph - ($max > 0 ? $ph * $v / $max : 0);
        $pts[] = [round($x, 1), round($y, 1), $v, $labels[$i] ?? ''];
    }
    if ($pts) {
        $line = implode(' ', array_map(static fn($p) => $p[0] . ',' . $p[1], $pts));
        $base = $t + $ph;
        $area = $pts[0][0] . ',' . $base . ' ' . $line . ' ' . end($pts)[0] . ',' . $base;
        $svg .= '<polygon points="' . $area . '" fill="' . $color . '" opacity="0.08"/>';
        $svg .= '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
        if ($n <= 40) {
            foreach ($pts as $p) {
                $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="3.5" fill="#fff" stroke="' . $color . '" stroke-width="2"><title>'
                      . e($p[3] . ': ' . number_format((float)$p[2], 0, ',', '.') . ' đ') . '</title></circle>';
            }
        }
    }
    return $svg . '</svg>';
}
