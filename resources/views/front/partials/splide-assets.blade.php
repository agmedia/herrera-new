@once
    @push('scripts')
        <script defer src="{{ asset('vendor/splide/splide.min.js') }}?v={{ filemtime(public_path('vendor/splide/splide.min.js')) }}"></script>
    @endpush
@endonce
