<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Qr\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a string (the QR sign-in URL) into a base64-encoded SVG.
 *
 * A thin wrapper around bacon/bacon-qr-code so the controller never binds to a
 * vendor facade directly — the library is swappable and the generator is easy to
 * fake in tests (bind a stub for {@see QrCodeGenerator} in the container). The
 * donor used the simplesoftwareio/simple-qrcode Laravel wrapper; the core uses
 * the maintained, framework-agnostic engine underneath it instead.
 */
class QrCodeGenerator
{
    /**
     * Generate a QR code for the given payload as a base64-encoded SVG string,
     * suitable for an `<img src="data:image/svg+xml;base64,...">`.
     */
    public function svgBase64(string $payload, int $size = 400): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($payload);

        return base64_encode($svg);
    }
}
