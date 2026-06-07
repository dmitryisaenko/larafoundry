<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Support\TemplateRenderer;

/*
| The renderer is the PRIMARY safety boundary of the email editor (threat model):
| it must substitute `{{vars}}` and NOTHING ELSE — never compile Blade, evaluate
| PHP, or re-expand injected values. These tests are the guard: if rendering ever
| starts interpreting the stored string as code, they fail.
*/

beforeEach(function () {
    $this->renderer = new TemplateRenderer;
});

it('substitutes declared placeholders', function () {
    expect($this->renderer->render('Hi {{name}}', ['name' => 'Jane']))
        ->toBe('Hi Jane');
});

it('substitutes a placeholder authored with inner whitespace', function () {
    expect($this->renderer->render('Hi {{ name }}', ['name' => 'Jane']))
        ->toBe('Hi Jane');
});

it('does NOT evaluate a Blade/php expression in the template', function () {
    // {{ 7*7 }} must never become 49 — the renderer is not an expression engine.
    expect($this->renderer->render('{{ 7*7 }}', [], keepUnknown: true))
        ->toBe('{{ 7*7 }}')
        ->not->toContain('49');
});

it('leaves a Blade directive verbatim, never executing it', function () {
    // If Blade ran, the @php...@endphp wrapper would be gone and only its echoed
    // output would remain. The directive surviving intact proves it was treated
    // as plain text, not compiled.
    $template = "@php echo 'x'; @endphp body";

    expect($this->renderer->render($template, [], keepUnknown: true))
        ->toContain('@php')
        ->toContain('@endphp');
});

it('does not re-expand a value that itself contains a placeholder token', function () {
    // strtr is single-pass: substituting {{a}} -> "{{b}}" must NOT then turn into
    // X, even though {{b}} is also in the data.
    expect($this->renderer->render('{{a}}', ['a' => '{{b}}', 'b' => 'X'], keepUnknown: true))
        ->toBe('{{b}}');
});

it('drops unfilled placeholders by default so mail never shows a raw token', function () {
    expect($this->renderer->render('Hi {{name}}, code {{missing}}', ['name' => 'Jane']))
        ->toBe('Hi Jane, code ');
});

it('keeps unfilled placeholders when asked (preview)', function () {
    expect($this->renderer->render('Hi {{name}}', [], keepUnknown: true))
        ->toBe('Hi {{name}}');
});

it('extracts the declared placeholder names from a template', function () {
    expect($this->renderer->placeholders('{{a}} and {{ b }} and {{a}}'))
        ->toEqualCanonicalizing(['a', 'b']);
});
