<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Format;
use App\Models\Certificate;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use App\Models\WebinarHandout;
use Illuminate\Contracts\View\View;
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
        [, $asset] = $this->gate($webinar, $format);

        if (filled($asset->url)) {
            return redirect()->away((string) $asset->url);
        }

        if (filled($asset->disk_path)) {
            return redirect()->to($this->signedFileUrl($asset, minutes: 10));
        }

        throw new NotFoundHttpException;
    }

    /**
     * Watch a recording inside the site — the same gate, but the asset is played
     * in an embedded player rather than redirected away, so the learner never
     * leaves. A file is streamed from a longer-lived signed URL (enough to play
     * a full session through); an externally hosted recording is embedded in an
     * iframe from the URL the admin provided.
     *
     * Only an asset the admin marked playable (`embed`) is shown here. Anything
     * else — a Dropbox or share link that will not iframe — falls back to the
     * plain access redirect, so hitting this URL directly never yields a broken
     * player. The learner's study materials ride along under the player.
     */
    public function watch(Webinar $webinar, string $format): View|RedirectResponse
    {
        [$formatEnum, $asset] = $this->gate($webinar, $format);

        if (! $asset->isPlayable()) {
            return $this->access($webinar, $format);
        }

        $isFile = filled($asset->disk_path);

        return view('learning.watch', [
            'webinar' => $webinar->loadMissing('speaker'),
            'format' => $formatEnum,
            'isFile' => $isFile,
            // A few hours, so pausing and seeking through a long recording does
            // not outlive the signed URL mid-playback.
            'src' => $isFile ? $this->signedFileUrl($asset, minutes: 240) : (string) $asset->url,
            'handouts' => $webinar->handouts()->where('is_active', true)->get(),
        ]);
    }

    /**
     * A study-material file. Handouts accompany every format, so any active
     * entitlement for the webinar — live, recorded, transcript or download —
     * opens them. Served through a signed, expiring URL (§9), never the path.
     */
    public function handout(WebinarHandout $handout): RedirectResponse
    {
        if (! $handout->is_active) {
            throw new NotFoundHttpException;
        }

        /** @var User $user */
        $user = auth()->user();

        $hasAccess = Entitlement::query()
            ->where('user_id', $user->getKey())
            ->where('webinar_id', $handout->webinar_id)
            ->active()
            ->exists();

        abort_unless($hasAccess, 403);

        return redirect()->to(
            Storage::disk(config('filesystems.webinar_assets'))
                ->temporaryUrl($handout->disk_path, now()->addMinutes(10)),
        );
    }

    /**
     * A learner's own CEU certificate PDF — §8 #4 / §9.
     *
     * Owner-scoped: a certificate is personal, so another member's is a 403, not
     * a 404 that leaks that it exists. Revoked, or not yet rendered (PDF
     * generation is P8), there is nothing to hand over — a 404. Served through a
     * signed, expiring URL on the private disk, never the stored path.
     */
    public function certificate(Certificate $certificate): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($certificate->user_id === $user->getKey(), 403);

        if ($certificate->isRevoked() || ! $certificate->hasPdf()) {
            throw new NotFoundHttpException;
        }

        return redirect()->to(
            Storage::disk(config('filesystems.webinar_assets'))
                ->temporaryUrl((string) $certificate->pdf_path, now()->addMinutes(10)),
        );
    }

    /**
     * The one access gate: a valid format, an entitlement that grants it, and a
     * released asset — or a 403/404. Returns the format and its asset.
     *
     * @return array{0: Format, 1: WebinarAsset}
     */
    private function gate(Webinar $webinar, string $format): array
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

        return [$formatEnum, $asset];
    }

    private function signedFileUrl(WebinarAsset $asset, int $minutes): string
    {
        return Storage::disk(config('filesystems.webinar_assets'))
            ->temporaryUrl((string) $asset->disk_path, now()->addMinutes($minutes));
    }
}
