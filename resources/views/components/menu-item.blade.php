@props([
    'title'  => '',
    'href'   => '#',
    'active' => false,
    'badge'  => null,
    'icon'   => null,
])
<li class="nav-item">
    <a href="{{ $href }}" class="nav-link {{ $active ? 'active' : '' }}" data-nav-title="{{ $title }}">
        <span class="nav-icon">
            @if($icon)
                {{ $icon }}
            @elseif(!empty($attributes->get('data-icon')))
                <i class="{{ $attributes->get('data-icon') }}"></i>
            @endif
        </span>
        <span class="nav-label">{{ $title }}</span>
        @if($badge)
            <span class="nav-badge">{{ $badge }}</span>
        @endif
    </a>
</li>
