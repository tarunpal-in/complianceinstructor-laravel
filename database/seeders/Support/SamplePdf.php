<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * Builds a small but genuinely valid one-page PDF.
 *
 * Used by the seeder and the media factory so both have documents that really
 * parse. A hand-waved "%PDF-1.4 ... %%EOF" string looks like a PDF and is not
 * one: every real parser wants a cross-reference table, so a fixture without one
 * makes the text-extraction path untestable and gives a seeded library that
 * cannot demonstrate content search.
 */
class SamplePdf
{
    /**
     * @param  string  $title  Rendered onto the page, so extraction has something to find.
     */
    public static function singlePage(string $title): string
    {
        $content = 'BT /F1 24 Tf 72 700 Td ('.self::escape($title).') Tj ET';

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]'
                .'/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
            '<</Length '.strlen($content).">>\nstream\n".$content."\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            // The byte offset of each object is what the xref table records, so
            // it has to be measured as the file is assembled.
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $startXref = strlen($pdf);
        $size = count($objects) + 1;

        // Every xref entry is exactly 20 bytes, padding included. Parsers seek by
        // multiplying, so a byte out here is a file that will not open.
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<</Size {$size}/Root 1 0 R>>\nstartxref\n{$startXref}\n%%EOF";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    }
}
