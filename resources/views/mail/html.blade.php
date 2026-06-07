<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
{{-- $html is already purified by the HtmlSanitizer (on save AND on render),
     so echoing it raw here is the intended defence-in-depth, not a hole. This
     minimal shell is the brandable mail layout: a host overrides it with
     `vendor:publish --tag=larafoundry-mail-views`. --}}
{!! $html !!}
</body>
</html>
