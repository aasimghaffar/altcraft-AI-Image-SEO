=== AltCraft AI – Image SEO & Auto Alt Text Generator ===
Contributors: cubixsol
Tags: alt text, image seo, accessibility, webp, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-written image ALT text, titles and captions (Google Gemini or OpenAI) with a bulk scanner, nightly cron, WebP copies and WooCommerce context.

== Description ==

**AltCraft AI** looks at every image in your Media Library and writes accurate, natural ALT text for it – the kind that helps screen-reader users and ranks in Google Images. Bring your own Google Gemini or OpenAI API key; everything else is included, with no upsells.

= What it does =

* **Generate on upload** – new images get ALT text (and optionally a title and caption) during the upload, so it is already there when you insert the image in the editor.
* **Media SEO Table** – a dedicated screen listing every image with its ALT text, title and caption. Edit inline, filter by *Missing ALT* / *Optimized* / *WooCommerce*, search the whole library, and paginate through thousands of images.
* **Bulk Vision Scanner** – process the entire library from one screen with a live progress bar, counters and a log. Stops cleanly, retries when the provider rate-limits you, and skips images that already have manual ALT text (or rewrites everything, if you ask it to).
* **Media Library integration** – an *AI Alt Text* column with a Generate button in the list view, and a *Generate ALT text with AI* button inside the media modal and the attachment edit screen.
* **Nightly background scan** – WP-Cron quietly fixes images that are still missing ALT text, in small batches that respect shared-hosting time limits.
* **WebP copies** – creates a lighter `.webp` version of the original and every thumbnail size (originals are never modified) and can optionally serve them to browsers that support WebP. Copies are removed when you delete the image.
* **WooCommerce context** – product images are described with the product title, categories and SKU in mind (featured image and gallery images are both detected). Add store or brand keywords and the AI uses them only where they fit.
* **Your rules** – choose what the AI looks at (the image and its filename, the image only, or the filename only without ever sending the image), the ALT style (concise SEO, descriptive accessibility or keyword focused), the output language (30+ languages) and whether existing ALT text may be overwritten.
* **Two providers, current models** – Google Gemini (3.7 / 3.6 / 3.5 Flash, Flash-Lite, 2.5) and OpenAI (GPT-5.6 Sol / Terra / Luna and older models), with a custom model field so you are never stuck when a provider retires a model, plus a *Test connection* button.
* **Developer friendly** – filters for the prompt, the result, the context, the model and the image size; an action after every generation.

= Privacy =

Only a downscaled copy of the image (max 1024 px) and a little context (the related product/post title, product categories and SKU, your brand keywords, the filename) is sent to the AI provider you choose. In "Filename only" mode the image itself is never sent. No user data, no site URL and no other content leaves your site. Nothing is sent until you add an API key. The plugin does not phone home to Cubixsol and contains no tracking.

== External services ==

This plugin connects to third-party AI services to analyse your images. It only does so after you have entered an API key for one of them and only when ALT text is generated (on upload, from the Generate buttons, from the bulk scanner or from the nightly cron).

**Google Gemini API** (Google LLC) – used when "Google Gemini" is the selected provider. The plugin sends a resized copy of the image (omitted in "Filename only" mode), the generation prompt (including the related post/product title, categories, SKU, brand keywords and filename hint) and your API key to `https://generativelanguage.googleapis.com/`.
[Terms of service](https://ai.google.dev/gemini-api/terms) · [Privacy policy](https://policies.google.com/privacy)

**OpenAI API** (OpenAI, L.L.C.) – used when "OpenAI" is the selected provider. The plugin sends the same data to `https://api.openai.com/`. Requests are made with `store: false`, so OpenAI does not retain them for its stored-responses feature.
[Terms of use](https://openai.com/policies/terms-of-use) · [Privacy policy](https://openai.com/policies/privacy-policy)

You are responsible for the usage costs and for complying with the provider's terms.

== Installation ==

1. Upload the `altcraft-ai-image-seo` folder to `/wp-content/plugins/`, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin.
3. Go to **AltCraft AI → Settings & API**, choose a provider, paste your API key, click *Test connection* and save.
4. Upload an image – or open **AltCraft AI → Bulk Scanner** to fix your existing library.

== Frequently Asked Questions ==

= Where do I get an API key? =

Google Gemini keys are created in Google AI Studio (aistudio.google.com/apikey) and include a free tier. OpenAI keys are created at platform.openai.com/api-keys and are billed per use.

= Does it overwrite ALT text I wrote myself? =

Not unless you tell it to. Automatic generation (upload and nightly cron) only fills empty ALT text by default. Turn on *Overwrite existing ALT* in the settings, or choose *Every image* in the bulk scanner, to rewrite everything. The Generate buttons always regenerate the image you clicked.

= Which image is sent to the AI? =

A copy no larger than 1024 px on its longest side, usually one of the thumbnail sizes WordPress already created. Your original file is never uploaded. Use the `altcraft_ai_max_image_dimension` filter to change the size.

= Can I avoid sending my images to the AI at all? =

Yes. Set *What the AI looks at* to *Filename only*. The plugin then sends just the cleaned-up filename and the related post/product title, so the AI can only describe what the name implies. Descriptive filenames (`red-leather-handbag.jpg`) work well; camera names (`IMG_4821.jpg`) on images that are not attached to anything are skipped with a clear message.

= I get "The AI provider did not respond within 60 seconds". =

The image upload from your server to the provider took too long, usually because the image is large and the hosting has limited outbound bandwidth. The plugin normally sends a small thumbnail; if WordPress could not create thumbnails for that image (common with very large PNGs on low-memory hosting) the original file is sent instead. Raise the request timeout under *Settings → Advanced*, regenerate thumbnails for the image, or upload a smaller copy.

= The bulk scanner says "model not found". =

The provider retired the model you selected. Open the settings, pick a newer model from the list (or enter any model ID in the custom field), test the connection and save.

= I get "cURL error 28 … timed out" but the connection test works. =

Real generation uploads a copy of your image; normally a 768–1024 px thumbnail. When WordPress could not create thumbnails for an image (large PNGs on hosts with a low memory limit, or servers without GD/Imagick) the plugin sends the original instead, which can be several megabytes and may not upload within the timeout. Check *Settings → Advanced → Server capabilities*, regenerate thumbnails for the affected images (or raise the PHP memory limit), or increase *Request timeout* under Advanced. The connection test now sends a small image through the same pipeline, so a passing test means the key, model and vision support are all fine.

= Does WebP delivery work with caching plugins and CDNs? =

WebP copies are always created safely. *Serving* them swaps image URLs when the visitor's browser sends `Accept: image/webp`, so a full-page cache must vary its cache by the Accept header (the plugin sends a `Vary: Accept` header to help). If your CDN already converts images to WebP, leave delivery off.

= Can I hard-code the API key in wp-config.php? =

Yes: `add_filter( 'altcraft_ai_api_key', function ( $key, $provider ) { return 'gemini' === $provider ? 'YOUR-KEY' : $key; }, 10, 2 );`

= What happens when I uninstall the plugin? =

Settings, transients and scheduled events are always removed. Generated ALT text, titles and captions stay in your Media Library. WebP copies and generation logs are deleted only if you enable *Clean up on uninstall* in the Advanced tab first.

== Screenshots ==

1. Settings & API – provider, model, test connection and coverage statistics.
2. Media SEO Table – inline editing, filters and search across the whole library.
3. Bulk Vision Scanner – live progress, counters and log.
4. The Generate button inside the media modal.
5. The AI Alt Text column in the Media Library list view.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
