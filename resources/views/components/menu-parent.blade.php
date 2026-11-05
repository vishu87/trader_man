@php $hasChildren = !$slot->isEmpty(); @endphp
<li class="parent @if($active) active @endif @if($hasChildren) has-submenu @if($active) open @endif @endif">
    @if($hasChildren)
        <a href="javascript:;" class="submenu-toggle" data-tooltip="{{ $title }}">
            <i class="{{ $iconClass }}"></i>
            <span class="nav-label">{{ $title }}</span>
            <i class="icons icon-arrow-right nav-arrow"></i>
        </a>
        <ul data-label="{{ $title }}">
            {{ $slot }}
        </ul>
    @else
        <a href="{{ $href ?? 'javascript:;' }}" data-tooltip="{{ $title }}">
            <i class="{{ $iconClass }}"></i>
            <span class="nav-label">{{ $title }}</span>
        </a>
    @endif
</li>
