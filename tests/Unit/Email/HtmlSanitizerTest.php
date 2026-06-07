<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Support\HtmlSanitizer;

/*
| The purifier is defence-in-depth (threat model): it must strip active content
| (scripts, event handlers, javascript: URIs) while KEEPING the markup email
| layout needs (tables, inline styles, links, images). Over-tightening would
| break legitimate email without buying real safety.
*/

beforeEach(function () {
    $this->sanitizer = new HtmlSanitizer;
});

it('strips a script tag', function () {
    expect($this->sanitizer->clean('<p>Hi</p><script>alert(1)</script>'))
        ->toContain('<p>Hi</p>')
        ->not->toContain('<script')
        ->not->toContain('alert(1)');
});

it('strips an inline event handler', function () {
    expect($this->sanitizer->clean('<a href="https://x.test" onclick="steal()">x</a>'))
        ->not->toContain('onclick')
        ->not->toContain('steal');
});

it('strips a javascript: URI', function () {
    expect($this->sanitizer->clean('<a href="javascript:alert(1)">x</a>'))
        ->not->toContain('javascript:');
});

it('strips an iframe', function () {
    expect($this->sanitizer->clean('<iframe src="https://evil.test"></iframe>hi'))
        ->not->toContain('<iframe');
});

it('keeps email-friendly table markup with inline styles', function () {
    $html = '<table style="width:100%"><tr><td style="padding:8px;color:#333">Cell</td></tr></table>';

    expect($this->sanitizer->clean($html))
        ->toContain('<table')
        ->toContain('<td')
        ->toContain('padding:8px')
        ->toContain('Cell');
});

it('keeps a safe link and image', function () {
    $html = '<a href="https://x.test" target="_blank">link</a><img src="https://x.test/a.png" alt="a">';

    expect($this->sanitizer->clean($html))
        ->toContain('href="https://x.test"')
        ->toContain('<img')
        ->toContain('alt="a"');
});
