@props(['type' => 'line', 'labels' => [], 'datasets' => [], 'suffix' => '', 'height' => 'h-72', 'label' => '차트'])

<div x-data="chart({{ Illuminate\Support\Js::from(['type' => $type, 'labels' => $labels, 'datasets' => $datasets, 'suffix' => $suffix]) }})"
     {{ $attributes->class(['relative w-full', $height]) }}>
    <canvas x-ref="canvas" role="img" aria-label="{{ $label }}"></canvas>
</div>
