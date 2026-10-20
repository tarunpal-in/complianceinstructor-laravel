<?php

declare(strict_types=1);

/*
 * Error pages extend the public layout, whose footer newsletter form uses
 * @error('email'). A URL that matches no route 404s before the web middleware
 * shares $errors, so without a default errors bag the 404 page itself threw
 * "Undefined variable $errors" and the visitor got a 500 instead of a 404.
 */

it('renders a 404 page instead of a 500 for an unmatched URL', function (): void {
    $this->get('/contact/webinars?type=live')->assertNotFound();
});

it('still shows a 404 for a missing page slug', function (): void {
    $this->get('/no-such-page')->assertNotFound();
});
