# wordpress.org directory assets — StoreCanvas Lite

These files are **SVN `/assets/` art**, not part of the plugin zip.
`seo-fixes/build_storecanvas_lite_zip.py` and `tools/build-release.sh`
exclude `.wordpress-org/` from the distributable.

| File | Purpose |
|---|---|
| `banner-1544x500.png` / `banner-772x250.png` | Directory page banners (Shopfront coral `#ff5a3c` on warm white `#fff9f4`) |
| `icon-256x256.png` / `icon-128x128.png` | Plugin icon |
| `screenshot-1.png` | Mug product page: fields, conditional gift note, group fields, live price |
| `screenshot-2.png` | Products → StoreCanvas Lite help (demo data, support form) |
| `screenshot-3.png` | Tumbler product: builder with Field IDs, =price @stock choices, Show if |
| `screenshot-4.png` | Tumbler product page: sold-out choice, conditional engraving, live price |
| `screenshot-5.png` | Products → Option groups list |
| `screenshot-6.png` | Option group editor (fields JSON, product IDs, categories) |
| `screenshot-7.png` | Product using a group: inherited fields listed, local builder below |
| `screenshot-8.png` | Order line item with saved choices |

Recaptured 13 Oct 2026 (1.0.11) on a clean MariaDB store (`competitor-lab/sclt_shots_lab.sh`,
127.0.0.1:8341, Storefront theme) with ONLY StoreCanvas Lite + WooCommerce active. Seed:
`sclt_shots_seed.php` (+ `seed2`, `order`); capture: `competitor-lab/sclt_shots_capture.py`.

Old note — Captured 10 Sep 2026 on the local SQLite verify stack (`wp-verify/stack`,
127.0.0.1:8123) with ONLY StoreCanvas Lite + WooCommerce active — no paid
plugins, no license UI. Demo data: `wp-verify/seed_storecanvas_lite.php`;
capture scripts: `wp-verify/capture_storecanvas_lite.py`,
`capture_sclt_234.py`, `recapture_sclt_2.py`.

Screenshot numbering maps 1:1 to `readme.txt == Screenshots ==` captions.
After slug approval these go to SVN `/assets/` (sibling of `trunk/`), never
into `trunk/`.
