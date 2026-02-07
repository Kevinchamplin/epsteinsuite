<?php
declare(strict_types=1);

if (!function_exists('render_donate_button')) {
    function render_donate_button(array $options = []): string
    {
        $label = $options['label'] ?? 'Support the Archive';
        $size = $options['size'] ?? 'md'; // sm|md|lg
        $fullWidth = (bool)($options['full_width'] ?? false);
        $variant = $options['variant'] ?? 'solid'; // solid|outline
        $href = 'https://buy.stripe.com/6oU4gB0vMbasdXqat82VG02';

        $sizeClasses = [
            'sm' => 'px-3 py-1.5 text-xs',
            'md' => 'px-4 py-2 text-sm',
            'lg' => 'px-5 py-3 text-base',
        ];

        $styles = [
            'solid' => 'bg-amber-500 text-white hover:bg-amber-600 shadow',
            'outline' => 'border border-amber-500 text-amber-600 hover:bg-amber-50',
        ];

        $base = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500';
        $classes = $base . ' ' . ($sizeClasses[$size] ?? $sizeClasses['md']) . ' ' . ($styles[$variant] ?? $styles['solid']);
        if ($fullWidth) {
            $classes .= ' w-full';
        }

        $icon = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21C12 21 5 13.364 5 8.5C5 5.462 7.462 3 10.5 3C11.89 3 13.21 3.571 14 4.5C14.79 3.571 16.11 3 17.5 3C20.538 3 23 5.462 23 8.5C23 13.364 16 21 16 21H12Z" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="%s">%s<span>%s</span></a>',
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($classes, ENT_QUOTES, 'UTF-8'),
            $icon,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
}
