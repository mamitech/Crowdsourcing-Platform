<script>
    window.Laravel = {!! json_encode([
                'baseUrl' => url("/"),
                'locale' => app()->getLocale(),
                'routes' => collect(\Route::getRoutes())->mapWithKeys(function ($route) { return [$route->getName() => $route->uri()]; })
            ]) !!};
</script>
<script  src="{{ mix('dist/js/manifest.js') }}"></script> {{-- The Webpack manifest runtime--}}
<script  src="{{ mix('dist/js/vendor.js') }}"></script> {{-- Vendor libraries like jQuery, bootstrap --}}
<script  src="{{ mix('dist/js/common.js')}}"></script> {{-- our application common code --}}
@stack('scripts')
