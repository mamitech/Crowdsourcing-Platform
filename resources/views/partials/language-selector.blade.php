@php use Illuminate\Support\Facades\Route; @endphp
<li class="nav-item dropdown">
    <a class="nav-link" href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-expanded="false">
        {{ strtoupper(app()->getLocale()) }}
        <i class="fas fa-caret-down"></i>
    </a>

    <ul class="dropdown-menu">
        @foreach ($languages as $language)
            <li>
                <a class="dropdown-item"
                   href="{{ $language->currentRouteLink }}"
                   @if (app()->getLocale() == $language->language_code) style="font-weight: bold; text-decoration: underline" @endif>{{ strtoupper($language->language_code) . ", ".strtoupper($language->language_name) }}</a>
            </li>
        @endforeach
    </ul>
</li>
