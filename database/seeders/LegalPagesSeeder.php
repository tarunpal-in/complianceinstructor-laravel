<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The four policy pages §7 names — Terms of Service, Privacy, Refund and Cookie —
 * as ordinary page-builder pages, edited under Pages in the admin.
 *
 * Their slugs match the footer's legal links exactly
 * (App\Support\Navigation::footer): /terms-of-service, /privacy-policy,
 * /refund-policy, /cookie-policy — so every link in that column resolves.
 *
 * Published so those links work, but the copy is deliberately PROVISIONAL
 * boilerplate: §12 makes the client's own counsel-reviewed policy a launch
 * dependency, and each page says so at the top. Idempotent (updateOrCreate on the
 * slug), so re-running refreshes the same four pages rather than duplicating them.
 */
class LegalPagesSeeder extends Seeder
{
    /** The banner every provisional policy opens with. */
    private const PROVISIONAL = '<p><strong>Provisional template — not legal advice.</strong> Replace this page with your own policy, reviewed by qualified counsel, before you launch. Edit it under <em>Pages</em> in the admin.</p>';

    public function run(): void
    {
        $this->page('Terms of Service', 'terms-of-service',
            self::PROVISIONAL
            .'<p>These terms govern your use of Compliance Instructor and the purchase of our continuing-education webinars, packages and memberships. By creating an account or placing an order you agree to them.</p>'
            .'<h3>Your account</h3><p>You are responsible for the accuracy of your account details and for keeping your password secure. You must be acting on your own behalf or with authority to act for your organisation.</p>'
            .'<h3>Purchases and CEU credit</h3><p>Continuing-education credit is awarded by the accrediting bodies (HRCI, SHRM, CPE, APA) under their rules, which we apply but do not set. Credit is issued to the named attendee who meets the completion requirement for a session.</p>'
            .'<h3>Refunds</h3><p>Orders are covered by our <a href="/refund-policy">Refund Policy</a>.</p>'
            .'<h3>Acceptable use</h3><p>Course materials, recordings and transcripts are licensed to you for your own professional development. You may not resell, share or redistribute them without written permission.</p>'
            .'<h3>Changes and contact</h3><p>We may update these terms from time to time; material changes will be posted here. Questions? Reach us through the <a href="/contact">contact page</a>.</p>',
        );

        $this->page('Privacy Policy', 'privacy-policy',
            self::PROVISIONAL
            .'<p>This policy explains what personal information Compliance Instructor collects, why, and the choices you have.</p>'
            .'<h3>What we collect</h3><p>Account details (name, email, employer, job title), billing information, purchase and attendance history, and the records needed to issue your certificates.</p>'
            .'<h3>How we use it</h3><p>To provide the training you buy, award and verify CEU credit, process payments, send service messages and — where you have opted in — occasional updates about new sessions.</p>'
            .'<h3>Sharing</h3><p>We share data only with the providers who help us run the service (payment processing, email delivery, hosting) and with accrediting bodies as required to validate credit. We do not sell your data.</p>'
            .'<h3>Retention and your rights</h3><p>We keep records for as long as needed to evidence the credit you earned and to meet our legal obligations. You may ask to access, correct or delete your data, subject to those obligations.</p>'
            .'<h3>Contact</h3><p>Write to us through the <a href="/contact">contact page</a> with any privacy request.</p>',
        );

        $this->page('Refund Policy', 'refund-policy',
            self::PROVISIONAL
            .'<p>We want you to be confident booking with us. Most purchases are covered by a 7-day refund window.</p>'
            .'<h3>7-day refund</h3><p>If you are not satisfied, request a refund within 7 days of purchase and before you have attended the live session or watched the recording in full. We will refund the amount paid to your original payment method.</p>'
            .'<h3>How to request</h3><p>Contact us through the <a href="/contact">contact page</a> with your order number and the reason for the request. Refunds are usually processed within 5–10 business days once approved.</p>'
            .'<h3>What is not refundable</h3><p>A certificate that has already been issued, a live session already attended, or a recording already watched in full cannot be refunded, as the credit has been earned. Memberships are covered by their own cancellation terms.</p>',
        );

        $this->page('Cookie Policy', 'cookie-policy',
            self::PROVISIONAL
            .'<p>This policy explains how Compliance Instructor uses cookies and similar technologies.</p>'
            .'<h3>Essential cookies</h3><p>Needed for the site to work — keeping you signed in, remembering your cart, and securing checkout. The site cannot function without these.</p>'
            .'<h3>Analytics cookies</h3><p>Help us understand which sessions are popular and how to improve the catalogue. These are aggregated and do not identify you personally.</p>'
            .'<h3>Managing cookies</h3><p>You can control or delete cookies through your browser settings. Blocking essential cookies may stop parts of the site, such as checkout, from working.</p>',
        );
    }

    private function page(string $title, string $slug, string $body): void
    {
        $page = Page::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $title,
                'status' => PublishStatus::Published,
                'published_at' => now()->subWeek(),
                'layout' => 'standard',
            ],
        );

        // A single prose block, keyed on position so a re-run edits it in place.
        $page->sections()->updateOrCreate(
            ['sort_order' => 0],
            ['type' => 'rich_text', 'data' => ['heading' => $title, 'body' => $body], 'is_visible' => true],
        );
    }
}
