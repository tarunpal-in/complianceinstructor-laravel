<?php

declare(strict_types=1);

namespace App\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Filament's authenticator-app provider, with the QR code fixed.
 *
 * **The bug.** Filament's `generateQrCodeDataUri()` carries a fallback "for
 * when `bacon/bacon-qr-code` is installed but the `imagick` extension is not",
 * which base64-encodes the library's output and prefixes it with
 * `data:image/svg+xml;base64,`. But `pragmarx/google2fa-qrcode` already does
 * exactly that itself when imagick is missing — it falls back to
 * `SvgImageBackEnd` and returns a complete data URI.
 *
 * So the string gets wrapped twice, and the browser decodes a data URI whose
 * payload is the literal text of another data URI rather than any SVG. The
 * image is broken; the manual key beside it is fine, which is what makes it
 * look like a rendering quirk rather than a bug.
 *
 * It only bites where imagick is absent, which is most installs — including
 * this one and, per §11, the Ubuntu VPS this ships to unless somebody
 * deliberately adds the extension.
 *
 * **The fix** is to stop guessing. The library says what it produced by
 * prefixing it; anything already carrying a `data:` scheme is passed through
 * untouched, and only a bare SVG document gets wrapped. That stays correct if
 * imagick is later installed, if the library changes its default backend, or
 * if Filament fixes this upstream.
 */
class AppAuthentication extends BaseAppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        /** @var HasAppAuthentication $user */
        $user = Filament::auth()->user();

        $qrCode = $this->google2FA->getQRCodeInline(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );

        // Already a data URI — whichever backend produced it has said so.
        if (Str::startsWith($qrCode, 'data:')) {
            return $qrCode;
        }

        // A bare SVG document, from a backend that did not prefix it.
        return 'data:image/svg+xml;base64,'.base64_encode($qrCode);
    }
}
