<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Maps\AddonFormats;
use App\Import\Support\LegacyDuration;
use App\Import\Support\LegacySchedule;
use App\Models\ImportMap;
use App\Models\Webinar;
use App\Support\Html;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

/**
 * Webinars, from the WooCommerce `product` posts.
 *
 * Type is read from what the product actually sells — a Live webinar is one with
 * a live variation, everything else is Recorded (§WebinarType). The schedule is
 * the ACF date + Pacific time; duration drives credits later; the WordPress slug
 * is kept so old product URLs can 301. Speaker and industry are resolved through
 * the import map, so this must run after SpeakerImporter and IndustryImporter.
 *
 * Accreditation is deliberately not set here: it is absent from the dump and is
 * a compliance matter the client signs off (§12), not something to invent from
 * product copy.
 */
class WebinarImporter extends Importer
{
    public function label(): string
    {
        return 'Webinars';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $products = Legacy::table('posts')
            ->where('post_type', 'product')
            ->whereIn('post_status', ['publish', 'draft', 'pending'])
            ->get();

        foreach ($products as $product) {
            $id = (int) $product->ID;
            $meta = Legacy::postMetaMap($id);

            $type = $this->resolveType($id);
            $schedule = LegacySchedule::parse($meta['date'] ?? null, $meta['time_from'] ?? null);
            $status = $this->resolveStatus((string) $product->post_status, $type, $schedule['at'] !== null && $schedule['at']->isFuture());

            $speakerName = trim((string) ($meta['speaker'] ?? ''));
            $speakerId = $speakerName !== ''
                ? ImportMap::localId('speaker', SpeakerImporter::keyFor($speakerName))
                : null;

            $industryId = $this->resolveIndustryId($id);

            $webinar = $this->sync($result, 'webinar', $id, Webinar::class, function (Webinar $webinar) use ($product, $meta, $type, $status, $schedule, $speakerId, $industryId): void {
                $webinar->type = $type;
                $webinar->status = $status;
                $webinar->title = (string) $product->post_title;
                $webinar->summary = Str::limit(trim(strip_tags((string) ($product->post_excerpt ?: $product->post_content))), 300);
                $webinar->speaker_id = $speakerId;
                $webinar->industry_id = $industryId;
                $webinar->scheduled_at = $schedule['at'];
                $webinar->timezone = $schedule['timezone'];
                $webinar->duration_minutes = LegacyDuration::minutes($meta['duration'] ?? null);

                if (! $webinar->exists) {
                    $base = (string) ($product->post_name ?: Str::slug((string) $product->post_title));
                    $webinar->slug = $this->uniqueSlug(Webinar::class, $base);
                }
            });

            $this->syncSections($webinar, (string) $product->post_content);
        }

        return $result;
    }

    /**
     * Rebuild a webinar's content sections from its WordPress description.
     *
     * Delete-and-recreate rather than diff: the legacy blob is the source of
     * truth, sections carry no foreign keys anything else points at, and a
     * re-run must leave exactly the sections the current parse produces.
     */
    private function syncSections(Webinar $webinar, string $html): void
    {
        $webinar->sections()->delete();

        foreach ($this->splitContent($html) as $order => $section) {
            $webinar->sections()->create([
                'heading' => $section['heading'],
                'body' => $section['body'],
                'sort_order' => $order,
                'is_visible' => true,
            ]);
        }
    }

    /**
     * Split a WordPress product description into ordered content sections.
     *
     * The blob is an intro, then headings the admin's structured fields mirror
     * ("Session Highlights", "Why You Should Attend", "Who Should Attend"), and
     * usually a trailing delivery notice ("…the recording link … within 24–48
     * hours"). Each recognised heading opens a new section; the intro and the
     * delivery notice become heading-less-or-titled sections so nothing is lost
     * and nothing is forced under the wrong title.
     *
     * A description with no recognised heading yields a single "Webinar
     * Overview" section — the pre-split behaviour, so there is no regression.
     *
     * @return array<int, array{heading: ?string, body: string}>
     */
    private function splitContent(string $html): array
    {
        $clean = Html::sanitize($html);
        if (trim($clean) === '') {
            return [];
        }

        $heading = 'Webinar Overview';
        $current = [];
        $delivery = [];
        $sections = [];
        $inDelivery = false;

        foreach ($this->topLevelNodes($clean) as $node) {
            if ($inDelivery) {
                $delivery[] = $node;

                continue;
            }

            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $canonical = $this->canonicalHeading($this->normalize($node->textContent));
                if ($canonical !== null) {
                    $sections[] = ['heading' => $heading, 'body' => $this->nodesToHtml($current)];
                    $heading = $canonical;
                    $current = [];

                    continue; // the section carries the heading; drop the node
                }
            }

            if ($this->looksLikeDeliveryNotice($node)) {
                $inDelivery = true;
                $delivery[] = $node;

                continue;
            }

            $current[] = $node;
        }

        $sections[] = ['heading' => $heading, 'body' => $this->nodesToHtml($current)];

        if ($delivery !== []) {
            $sections[] = ['heading' => null, 'body' => $this->nodesToHtml($delivery)];
        }

        return array_values(array_filter(
            $sections,
            static fn (array $section): bool => trim(strip_tags($section['body'])) !== '' || str_contains($section['body'], '<img'),
        ));
    }

    /**
     * The direct children of the sanitised fragment, in document order.
     *
     * @return array<int, DOMNode>
     */
    private function topLevelNodes(string $html): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        // The XML pragma forces UTF-8; the wrapper gives one addressable root
        // whatever the fragment's own top-level structure is.
        $doc->loadHTML('<?xml encoding="UTF-8">'.'<div id="ci-import-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'ci-import-root') {
                $root = $div;

                break;
            }
        }

        if ($root === null) {
            return [];
        }

        $nodes = [];
        foreach ($root->childNodes as $child) {
            $nodes[] = $child;
        }

        return $nodes;
    }

    /**
     * Serialise a run of nodes back to an HTML string.
     *
     * @param  array<int, DOMNode>  $nodes
     */
    private function nodesToHtml(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $html .= $node->ownerDocument?->saveHTML($node) ?? '';
        }

        return trim($html);
    }

    /** Lower-case, whitespace-collapsed, punctuation-trimmed heading text. */
    private function normalize(string $text): string
    {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return rtrim(strtolower(trim($text)), ": .?\t");
    }

    /** The canonical heading a legacy section title maps to, or null. */
    private function canonicalHeading(string $norm): ?string
    {
        return match (true) {
            in_array($norm, ['session highlights', 'session highlight', 'webinar covers the following key topics', 'areas covered', 'areas covered in the webinar', 'key topics covered', 'key topics'], true) => 'Session Highlights',
            in_array($norm, ['why you should attend', 'why should you attend', 'why attend'], true) => 'Why You Should Attend',
            in_array($norm, ['who should attend', 'who should attend this webinar', 'who will benefit', 'who will benefit from this webinar'], true) => 'Who Should Attend',
            default => null,
        };
    }

    /** Whether a node begins the trailing delivery/notice boilerplate. */
    private function looksLikeDeliveryNotice(DOMNode $node): bool
    {
        if (! $node instanceof DOMElement) {
            return false;
        }

        $norm = $this->normalize($node->textContent);
        if ($norm === '') {
            return false;
        }

        foreach (['recording link', 'e-transcript', 'important notice', 'instruction kit', 'within 24', 'q&a session following'] as $marker) {
            if (str_contains($norm, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** Live when any variation sells a live format; Recorded otherwise. */
    private function resolveType(int $productId): WebinarType
    {
        $slugs = Legacy::table('postmeta as pm')
            ->join('posts as v', 'v.ID', '=', 'pm.post_id')
            ->where('v.post_type', 'product_variation')
            ->where('v.post_parent', $productId)
            ->where('pm.meta_key', 'attribute_pa_addons')
            ->pluck('pm.meta_value');

        foreach ($slugs as $slug) {
            if (AddonFormats::knows((string) $slug) && AddonFormats::includesLive((string) $slug)) {
                return WebinarType::Live;
            }
        }

        return WebinarType::Recorded;
    }

    private function resolveStatus(string $wpStatus, WebinarType $type, bool $isFuture): WebinarStatus
    {
        if (in_array($wpStatus, ['draft', 'pending'], true)) {
            return WebinarStatus::Draft;
        }

        // Published: a live session still ahead is Scheduled; everything else —
        // a recording, or a live session already past — is Completed, which is
        // still publicly visible and still sells its recording.
        return $type === WebinarType::Live && $isFuture
            ? WebinarStatus::Scheduled
            : WebinarStatus::Completed;
    }

    /**
     * The webinar's industry: the product's primary product_cat where WordPress
     * recorded one, otherwise its first real (non-format) category.
     */
    private function resolveIndustryId(int $productId): ?int
    {
        $termIds = Legacy::table('term_relationships as tr')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tr.object_id', $productId)
            ->where('tt.taxonomy', 'product_cat')
            ->whereNotIn('t.slug', ['recorded', 'live', 'packages', 'uncategorized', 'training', 'industry'])
            ->pluck('t.term_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($termIds === []) {
            return null;
        }

        $primary = Legacy::postMeta($productId, '_yoast_wpseo_primary_product_cat');
        $chosen = $primary !== null && in_array((int) $primary, $termIds, true)
            ? (int) $primary
            : $termIds[0];

        return ImportMap::localId('industry', $chosen);
    }
}
