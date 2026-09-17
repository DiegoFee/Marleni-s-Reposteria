<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="#9B3646" />

<link rel="icon" type="image/x-icon" href="{{ asset('images/faviconPC.ico') }}" media="(min-width: 768px)" />
<link rel="icon" type="image/x-icon" href="{{ asset('images/faviconMOVIL.ico') }}" media="(max-width: 767px)" />

<title>{{ $title ?? config('app.name') }}</title>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
