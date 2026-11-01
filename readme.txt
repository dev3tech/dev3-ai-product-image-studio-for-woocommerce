=== DEV3 AI Product Image Studio for WooCommerce ===
Contributors: dev3tech
Tags: woocommerce, product images, background removal, alt text, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI background removal, white-background pack shots, lifestyle scenes, upscaling and alt text for WooCommerce product images, with batch mode and before/after approval.

== Description ==

DEV3 AI Product Image Studio turns everyday product photos into clean, consistent shop images, right inside WooCommerce.

* **Remove background** to pure white, any colour, or a transparent PNG.
* **White pack shots**: centred, evenly lit, square canvas with your fill % and an optional soft shadow (marketplace-ready).
* **Lifestyle scenes**: place the product on a kitchen counter, marble, wood, outdoors… or describe your own setting. 1-4 variants, any aspect ratio, brand style.
* **AI upscale**: restore detail, then enlarge 2-4x.
* **Custom edit**: "remove the price sticker", "straighten the label".
* **Alt text, title and caption** written from the photo itself, or from a template without AI.
* **Batch mode**: run any edit or recipe over a category, a selection or your whole catalogue in the background.
* **Originals are never overwritten.** Every result is a new image that waits in the review queue with a before/after slider. Approve to replace the image in the product (or add it to the gallery); revert at any time.
* **Works without AI**: square canvas, resize, flatten, format conversion, alt templates, the image audit and the review queue need no API key.
* Product image library with health filters (missing alt, low resolution, not on white, not square), product metabox, Products bulk action and media library link.
* "Before & After Image" block and `[dev3is_compare]` shortcode for your storefront.

AI is powered by Google Gemini with your own API key. The key stays on your server.

== Installation ==

1. Upload the zip in Plugins › Add New › Upload Plugin and activate (WooCommerce must be active).
2. Open **DEV3 AI Image Studio › API Config** and paste your Google Gemini API key (free at https://aistudio.google.com/apikey).
3. Open **Product images**, pick an image and choose an edit. Full documentation is in the Documentation folder of the download.

== Frequently Asked Questions ==

= Are my original photos changed? =
No. Results are saved as new media files. Approving swaps the attachment used by the product; the original stays in your media library and Revert puts it back.

= Can it make real transparent PNGs? =
Yes. The AI paints the product on a key colour and the plugin cuts it out on your server into a PNG with alpha.

= What does it cost? =
You pay Google for your own API usage. Set a monthly budget in API Config; AI pauses when it is reached.

= Which data is sent to Google? =
Only the product image being edited (downscaled to 1536 px), the product name, and for alt text the category and attributes.

== Privacy ==

When you use an AI feature, the image and the product name are sent to Google Gemini (Google LLC) as processor. A privacy-policy snippet is added to Settings › Privacy.

== Screenshots ==

1. Dashboard with image health and latest results.
2. Studio: pick an edit, compare before and after.
3. Review queue with bulk approve.
4. Batch mode.
5. Product image library with filters.
6. Storefront before/after slider on mobile.

== Changelog ==

= 1.0.0 =
* Initial release.
