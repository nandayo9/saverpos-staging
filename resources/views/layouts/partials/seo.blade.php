{{--
    Search-engine and link-preview tags, included in every layout's <head>.

    Layouts pass the default policy:
        @include('layouts.partials.seo')                                   public, indexable
        @include('layouts.partials.seo', ['robots' => 'noindex, nofollow'])  private

    A page can override either default from its own view:
        @section('meta_description', 'One or two sentences about this page.')
        @section('meta_robots', 'noindex, nofollow')

    Canonical, Open Graph and Twitter tags are only emitted for indexable
    pages; private pages (the signed-in app, invoices, payment links) have
    nothing to advertise and should not leak titles into link previews.
--}}
@php
    $seoAppName = config('app.name', 'SAVERPOS');
    $seoRobots = trim($__env->yieldContent('meta_robots')) ?: ($robots ?? 'index, follow');
    $seoIndexable = ! str_contains($seoRobots, 'noindex');
    $seoDescription = trim($__env->yieldContent('meta_description'))
        ?: (env('APP_TITLE') ? $seoAppName.' - '.env('APP_TITLE') : $seoAppName.' is a point-of-sale and inventory platform: sales, stock, purchases, repairs and trade-ins in one place.');
    $seoTitle = trim($__env->yieldContent('title')) ? trim($__env->yieldContent('title')).' - '.$seoAppName : $seoAppName;
    $seoImage = asset('img/saverpos-logo.png');
@endphp
<meta name="description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 160, '') }}">
<meta name="robots" content="{{ $seoRobots }}">
@if ($seoIndexable)
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $seoAppName }}">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 200, '') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $seoImage }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit($seoDescription, 200, '') }}">
    <meta name="twitter:image" content="{{ $seoImage }}">
@endif
<link rel="apple-touch-icon" href="{{ $seoImage }}">
