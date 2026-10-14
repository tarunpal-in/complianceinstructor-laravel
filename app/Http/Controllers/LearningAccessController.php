<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hands a learner the thing they are entitled to — CLAUDE.md §5.2 / §9.
 *
 * The one gate is `Entitlement::grants()`: no order, subscription or request
 * field decides access here (§14 #9). A format the learner does not hold is a
 * 403, full stop. What they do hold is served without ever exposing a guessable
 * path (§14 #11): an externally hosted item (a live join link, a recording on a
 * video host) is a redirect to its URL; a file we hold is a redirect to a
 * signed, expiring URL on the private disk (§9), never the disk path itself.
 *
 * The join link for a live session is withheld until the session's
 * `available_from` passes — `WebinarAsset::isReleased()` — so a link cannot leak
 * before it should work.
 */
class LearningAccessController extends Controller
{
    public function access(Webinar $webinar, string $format): RedirectResponse
    {
        $formatEnum = Format::tryFrom($format);

        if ($formatEnum === null) {
            throw new NotFoundHttpException;
        }

        /** @var User $user */
        $user = auth()->user();

        abort_unless(Entitlement::grants($user, $webinar, $formatEnum), 403);

        $asset = $webinar->assets()
            ->where('format', $formatEnum)
            ->where('is_active', true)
            ->first();

        // Entitled, but there is nothing released to open yet — a recording not
        // uploaded, or a join link before its release time.
        if ($asset === null || ! $asset->isReleased()) {
            throw new NotFoundHttpException;
        }

        if (filled($asset->url)) {
            return redirect()->away((string) $asset->url);
        }

        if (filled($asset->disk_path)) {
            return redirect()->to(
                Storage::disk('local')->temporaryUrl((string) $asset->disk_path, now()->addMinutes(10)),
            );
        }

        throw new NotFoundHttpException;
    }
}
