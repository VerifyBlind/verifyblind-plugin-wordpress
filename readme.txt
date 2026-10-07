=== VerifyBlind ===
Contributors: verifyblind
Tags: age verification, woocommerce, one account per person, privacy, identity
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age and one-person-one-account verification with a chipped Turkish ID card. The site only receives an eligible / not eligible answer.

== Description ==

Rules decide where a verification is asked (sign-up, content lock, comments, WooCommerce checkout, product pages, site entrance, coupons, product reviews, or only a role for another plugin) and what is asked (an age condition and/or the one-person check). Every gate is enforced on the server.

= External service: VerifyBlind =

This plugin connects your site to the VerifyBlind service (https://verifyblind.com), which performs the verification. It cannot work without it.

What is sent to the service, and when:

* When a visitor starts a verification, your site asks the VerifyBlind API (https://api.verifyblind.com) for a verification session using your site's API key and the requested conditions (for example "18+").
* The visitor's browser loads the VerifyBlind widget script from https://cdn.verifyblind.com and shows a QR code. The visitor scans it with the VerifyBlind mobile app, which reads the ID card on the phone and checks it against a live face check.
* When the verification finishes, the service returns the answer (eligible / not eligible for the requested conditions) and a pseudonymous per-site code to your site. Your site does not receive the visitor's ID number, name or photo.

The VerifyBlind enclave and mobile apps are published as public source code (review-only licence); the SDKs and this plugin are open source (the plugin is GPL).

* Terms of service: https://verifyblind.com/en/terms
* Privacy policy: https://verifyblind.com/en/privacy

== Frequently Asked Questions ==

= Is locked content hidden everywhere? =

Locked content is removed on the server through WordPress and WooCommerce filters: the post body, excerpts, feeds, the REST API, the WooCommerce Store API and product data, and the meta descriptions of Yoast SEO and Rank Math. A theme or plugin that reads the post content straight from the database (instead of through these filters) bypasses them. With such a theme, put the locked part inside the "VerifyBlind lock" block or the [verifyblind_gate] shortcode: the gate then decides on the server wherever the content is rendered.

= I use an inline lock and an SEO plugin. =

SEO plugins that generate a description from the raw page content may include text from an inline lock. Give such pages a custom meta description.

= Does the whole-site entrance hide everything? =

The whole-site entrance (age gate for the shop) hides front-end pages only. REST API responses and direct media (uploads) URLs are not hidden by it. Purge your page caches when you enable this placement, so that cached copies are not served to visitors who have not verified.
