<?php

declare(strict_types=1);

namespace App\Media;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGenerator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Rasterises page 1 of a PDF so the library shows a thumbnail rather than a wall
 * of identical red icons.
 *
 * This replaces spatie's own `Pdf` generator, which routes through the Imagick
 * PHP extension. Shelling out to poppler or Ghostscript instead avoids two real
 * problems:
 *
 *  - Imagick delegates PDF rendering to Ghostscript anyway, so the extension is
 *    a layer with no rendering of its own.
 *  - Ubuntu ships an ImageMagick `policy.xml` that denies the PDF coder by
 *    default. Thumbnails then fail silently and the symptom is indistinguishable
 *    from "Ghostscript is not installed" — a support call nobody enjoys.
 *
 * `poppler-utils` is one apt package and needs no PHP extension at all. When
 * neither binary is present, `requirementsAreInstalled()` returns false,
 * medialibrary skips conversion, and the file falls back to a mime icon. That
 * degradation is the expected state on a machine without either — including this
 * one — and never an error.
 */
class PdfPageImageGenerator extends ImageGenerator
{
    /** Rendering a poster at more than this wastes time; `thumb` is 400px. */
    private const DPI = 150;

    /** A cover page should take well under this; a hung binary must not wedge a queue worker. */
    private const TIMEOUT_SECONDS = 60;

    public function convert(string $file, ?Conversion $conversion = null): ?string
    {
        $page = $conversion?->getPdfPageNumber() ?? 1;

        $prefix = pathinfo($file, PATHINFO_DIRNAME)
            .DIRECTORY_SEPARATOR
            .pathinfo($file, PATHINFO_FILENAME);

        if ($this->renderWithPoppler($file, $prefix, $page)) {
            return $prefix.'.jpg';
        }

        if ($this->renderWithGhostscript($file, $prefix.'.jpg', $page)) {
            return $prefix.'.jpg';
        }

        // Null tells medialibrary there is no image. The upload itself already
        // succeeded, so the cost is a missing thumbnail rather than a lost file.
        return null;
    }

    public function requirementsAreInstalled(): bool
    {
        return $this->popplerBinary() !== null || $this->ghostscriptBinary() !== null;
    }

    /** @return Collection<int, string> */
    public function supportedExtensions(): Collection
    {
        return collect(['pdf']);
    }

    /** @return Collection<int, string> */
    public function supportedMimeTypes(): Collection
    {
        return collect(['application/pdf']);
    }

    private function renderWithPoppler(string $file, string $prefix, int $page): bool
    {
        $binary = $this->popplerBinary();

        if ($binary === null) {
            return false;
        }

        // -singlefile makes the output name exactly "<prefix>.jpg"; without it
        // poppler appends a zero-padded page number whose width varies with the
        // document's page count, which is not predictable from here.
        return $this->run([
            $binary,
            '-jpeg',
            '-f', (string) $page,
            '-l', (string) $page,
            '-r', (string) self::DPI,
            '-singlefile',
            $file,
            $prefix,
        ]) && is_file($prefix.'.jpg');
    }

    private function renderWithGhostscript(string $file, string $target, int $page): bool
    {
        $binary = $this->ghostscriptBinary();

        if ($binary === null) {
            return false;
        }

        return $this->run([
            $binary,
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            // Not optional. Ghostscript has a history of PostScript escapes, and
            // the files here are uploaded by a person rather than authored by us.
            '-dSAFER',
            '-sDEVICE=jpeg',
            '-dJPEGQ=85',
            '-dFirstPage='.$page,
            '-dLastPage='.$page,
            '-r'.self::DPI,
            '-sOutputFile='.$target,
            $file,
        ]) && is_file($target);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): bool
    {
        // An array command never reaches a shell, so nothing in a file name can
        // be read as an argument or an operator.
        $process = new Process($command, timeout: self::TIMEOUT_SECONDS);

        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('PDF thumbnail generation failed.', [
                'binary' => $command[0],
                'exit_code' => $process->getExitCode(),
                'error' => trim($process->getErrorOutput()),
            ]);

            return false;
        }

        return true;
    }

    private function popplerBinary(): ?string
    {
        return $this->find('pdftoppm');
    }

    private function ghostscriptBinary(): ?string
    {
        // gswin64c is the Windows console build; plain "gs" does not exist there.
        return $this->find('gs') ?? $this->find('gswin64c');
    }

    private function find(string $name): ?string
    {
        /** @var array<string, string|null> $cache */
        static $cache = [];

        if (! array_key_exists($name, $cache)) {
            $cache[$name] = (new ExecutableFinder)->find($name);
        }

        return $cache[$name];
    }
}
