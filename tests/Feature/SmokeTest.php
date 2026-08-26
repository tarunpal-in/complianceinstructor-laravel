<?php

declare(strict_types=1);

// CLAUDE.md §10 — every public route asserts 200 and key content. Cheap, and it
// catches broken views after a refactor. Grows one line per route as P2/P6 land.
it('serves the home page', function (): void {
    $this->get('/')->assertSuccessful();
});
