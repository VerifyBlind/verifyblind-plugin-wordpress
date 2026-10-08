=== VerifyBlind ===
Contributors: verifyblind
Tags: age verification, age gate, woocommerce, one account per person, privacy
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age and one-person-one-account checks with a chipped Turkish ID card. Your site only gets an eligible / not eligible answer.

== Description ==

VerifyBlind lets visitors prove an age condition (for example 18+, 21+, 16-18 or 65+) or that they are one person with one account, with their chipped Turkish ID card and the VerifyBlind mobile app. The card is read on the visitor's phone and matched to a live face check in a sealed environment (an AWS Nitro Enclave). Your site receives only an eligible / not eligible answer and, for the one-person check, a pseudonymous code issued for your site only. Names, ID numbers, birth dates and photos never reach your site.

**Rules** decide where a verification is asked and what is asked:

* Where: sign-up (WordPress and WooCommerce), pages, posts and categories (or the "VerifyBlind lock" block and the `[verifyblind_gate]` shortcode anywhere), comments, WooCommerce checkout, product pages, the whole shop entrance, coupons, product reviews, or only a role for another plugin.
* What: an age condition (at least N, younger than N, or a range) and/or the one-person check.
* Same person on another account: reject the verification, block the action, accept and notify you, or move the verification to the new account.
* For those who pass: an optional extra role (next to their existing role) and a validity period.

Every gate is enforced on the server: locked text is removed before the page is sent, and orders, coupons, sign-ups and comments are refused on the server when the check is missing.

Also included: a setup wizard with ready-made rules, a "Verified with VerifyBlind" badge for comments, reviews and author boxes, a "Verified members" screen, the WordPress personal data export and erase tools, a suggested privacy policy paragraph, a revoke address that removes a result when the person withdraws consent in the VerifyBlind app, and Turkish and English translations.

Cost: each requested item counts as one verification (age = 1, one-person check = 1). 2,000 verifications a month are free.

= External services =

This plugin connects your site to VerifyBlind (https://verifyblind.com), the service that performs the verification. It cannot work without it. This is everything that is sent, where, and when.

From your site's server to the VerifyBlind API (https://api.verifyblind.com):

* When a visitor presses "Verify with VerifyBlind": `POST /api/pop/generate` with your site's API key, a one-time public key created in the visitor's browser for this verification, the conditions the rule asks for (for example age 18+ and/or the one-person check), the Cloudflare Turnstile token when bot protection is on, the widget version, the language the visitor's browser asks for (the Accept-Language header), and the plugin version (header `X-VerifyBlind-Client: wordpress/1.0.0`). No name, e-mail address or IP address of the visitor is sent.
* When you test the connection (setup wizard or Settings): the same call with VerifyBlind's own public key and an age 18+ condition. The session is never used and not billed.
* When a verification result arrives: `GET /api/public/enclave-key` (VerifyBlind's public key, kept for about a minute) to check the result's signature on your server.
* When VerifyBlind calls your revoke address: `GET /api/public/webhook-signing-key` (kept for an hour) to check that the call really comes from VerifyBlind.

From the visitor's browser, only on pages that show a verification box:

* The VerifyBlind widget https://cdn.verifyblind.com/sdk/v1.0.1/verifyblind.js (a fixed version, checked with a Subresource Integrity hash). The widget in turn loads its QR code library https://cdn.verifyblind.com/sdk/v1.0.1/vendor/qr-code-styling.min.js and logo https://cdn.verifyblind.com/images/qrlogo.png.
* While the QR code is on screen, the browser asks https://api.verifyblind.com/api/pop/result/ (followed by the session code) every two seconds for the result, which is encrypted for that browser only. Like any web request, this shows VerifyBlind the visitor's IP address; VerifyBlind does not pass it to your site.
* The QR code holds a link to https://app.verifyblind.com that the visitor opens with the VerifyBlind app on their phone.

From VerifyBlind to your site: when a person withdraws consent in the VerifyBlind app, VerifyBlind sends a signed `POST` to your revoke address (`/wp-json/verifyblind/v1/revoke`) with the session code, and the plugin deletes the results tied to it.

VerifyBlind terms of service: https://verifyblind.com/en/terms
VerifyBlind privacy policy: https://verifyblind.com/en/privacy
VerifyBlind data processing terms: https://verifyblind.com/en/dpa

Cloudflare Turnstile (bot protection, off by default): only when the site owner turns bot protection on under VerifyBlind → Settings → Bot protection, the widget loads https://challenges.cloudflare.com/turnstile/v0/api.js in the visitor's browser and runs an invisible bot check; its token goes to VerifyBlind with the request above. While bot protection is off, nothing is loaded from Cloudflare.

Cloudflare terms: https://www.cloudflare.com/website-terms/
Cloudflare Turnstile privacy policy: https://www.cloudflare.com/turnstile-privacy-policy/

The badge images ship with the plugin; readers' browsers never fetch them from VerifyBlind. The VerifyBlind enclave and mobile apps are published as public source code (review-only licence); the SDKs are open source and this plugin is GPL.

== Installation ==

1. Install the plugin (Plugins → Add New → Upload Plugin with the zip, or search for "VerifyBlind") and activate it.
2. The setup wizard opens. Create a free partner account at https://partner.verifyblind.com, copy the API key from Settings and paste it into the wizard; the wizard tests the connection.
3. Choose what kind of site this is: the wizard adds ready-made rules. Open a rule to choose its pages, products or coupons.
4. Copy the revoke address the wizard shows into the partner portal → Settings → Revoke URL.
5. Add the suggested paragraph from Settings → Privacy → Policy guide to your privacy policy.

You can open the wizard again under VerifyBlind → Setup wizard.

== Frequently Asked Questions ==

= Does my site receive identity data? =

No. Your site receives only whether the visitor meets the condition the rule asks (eligible / not eligible) and, for the one-person check, a pseudonymous code issued for your site only. The same person gets a different code on every other site.

= Who can be verified? =

VerifyBlind verifies people aged 15 and over; a rule that only admits younger visitors can never be met.

= Which documents work? =

Chipped Turkish ID cards (T.C. Kimlik Kartı). Passports and other documents are not supported.

= Can I try it without a real ID card? =

Test mode (VerifyBlind → Settings) accepts the demo card of the VerifyBlind app. The demo card only works with a test partner account; write to support@verifyblind.com for one. With a regular partner account, verify with your own ID card. Turn test mode off on a live site.

= Is locked content hidden everywhere? =

Locked content is removed on the server through WordPress and WooCommerce filters: the post body, excerpts, feeds, the REST API, the WooCommerce Store API and product data, and the meta descriptions of Yoast SEO and Rank Math. A theme or plugin that reads the post content straight from the database (instead of through these filters) bypasses them. With such a theme, put the locked part inside the "VerifyBlind lock" block or the [verifyblind_gate] shortcode: the gate then decides on the server wherever the content is rendered.

= I use an inline lock and an SEO plugin. =

SEO plugins that generate a description from the raw page content may include text from an inline lock. Give such pages a custom meta description.

= Does the whole-site entrance hide everything? =

The whole-site entrance (age gate for the shop) hides front-end pages only. REST API responses and direct media (uploads) URLs are not hidden by it. Purchases are gated on the server whatever the request: until the visitor verifies, adding to the cart and placing an order are refused (classic, AJAX and block checkout, and the Store API), and passing orders carry the verification note. Purge your page caches when you enable this placement, so that cached copies are not served to visitors who have not verified.

= Does it work with page caching? =

Pages with a gate are marked as not cacheable (`DONOTCACHEPAGE` and no-cache headers), which WP Super Cache, W3 Total Cache and LiteSpeed Cache respect. A cache that ignores them (some hosting or CDN full-page caches) must exclude the gated pages, the cart, the checkout and My Account; otherwise one visitor's opened page may be served to another. Purge the cache after you add or change a rule.

= What happens when VerifyBlind cannot be reached? =

The gate stays closed and visitors see "Verification is not available right now". You get an admin notice (and at most one e-mail a day) for problems with your account: a rejected API key, no free quota or balance left, an unverified partner e-mail, or a plugin version VerifyBlind no longer accepts (then update the plugin).

= Is multisite supported? =

Not in 1.0.

= Are there hooks for developers? =

Filters: `verifyblind_bypass_gate` (bool $bypass, WP_Post $post) opens a content gate, `verifyblind_placements` adds placements, `verifyblind_generate_per_minute` changes the site-wide limit of verification starts per minute (default 30). Rule roles let other plugins (forums, memberships, courses) restrict access by role.

== Screenshots ==

1. Setup wizard: choose what kind of site this is; the wizard adds ready-made rules.
2. Rules: where a verification is asked, what is asked and what happens when the same person comes back with another account.
3. Rule editor.
4. The verification box on a locked page.
5. Verified members.

== Changelog ==

= 1.0.0 =
* First release: rules for sign-up, content lock (block and shortcode), comments, WooCommerce checkout, product pages, shop entrance, coupons and product reviews; age conditions and the one-person check; setup wizard; verified badge; verified members screen; privacy tools; revoke address; Turkish translation.

== Upgrade Notice ==

= 1.0.0 =
First release.
