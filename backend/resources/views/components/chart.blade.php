@props(['type' => 'line', 'labels' => [], 'datasets' => [], 'suffix' => '', 'height' => 'h-72', 'label' => '차트', 'xy' => false, 'legend' => true])

<div x-data="chart({{ Illuminate\Support\Js::from(['type' => $type, 'labels' => $labels, 'datasets' => $datasets, 'suffix' => $suffix, 'xy' => $xy, 'legend' => $legend]) }})"
     {{ $attributes->class(['relative w-full', $height]) }}>
    <canvas x-ref="canvas" role="img" aria-label="{{ $label }}"></canvas>
</div>
