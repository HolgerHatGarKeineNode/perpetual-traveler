@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-ok dark:text-ok-bright']) }}>
        {{ $status }}
    </div>
@endif
