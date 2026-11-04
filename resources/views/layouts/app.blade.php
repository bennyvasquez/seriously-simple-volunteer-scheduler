<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<link rel="preload" href="/fonts/atkinson-hyperlegible-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/atkinson-hyperlegible-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="bar">
@hasSection('bar')
@yield('bar')
@else
<div class="bar-name">@hasSection('header')@yield('header')@else{{ \App\Models\Organization::query()->value('name') ?? config('app.name') }}@endif</div>
@endif
</header>
<main class="@yield('main-class', 'page')">
@yield('content')
</main>
@stack('scripts')
</body>
</html>
