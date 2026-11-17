# Database-driven images — 17 October 2026

Eight AI-generated images are saved as versioned WebP files in `.replatform/laravel12-backend/database/seed-assets`. The manifest records filenames, descriptions, credits, source artifacts and prompts. Four landscape scenes cover collaboration, product design, community and energy; four portraits depict fictional demo builders. Optimized assets total approximately 1.1 MiB. The built-in image tool was used, with no paid API fallback.

## Coverage and ownership

| Image area | Database source | Admin control |
| --- | --- | --- |
| Home cover and secondary stories | Article media | Story editor and homepage lead selection |
| Latest stories, story lists, categories and article detail | Article media | Story editor |
| Search story thumbnails | Article media | Story editor |
| Startup spotlight, startup cards and profile | Startup media | Startup profile editor |
| Regional builders, builder cards and profiles, diaspora and search | Builder media | Builder profile editor |
| About hero and feature image | `site_settings.pageImages.about` / `partner` | General settings |
| Diaspora hero | `site_settings.pageImages.diaspora` | General settings |
| Home video thumbnails, video cards, video listing and detail | Stored provider thumbnail URL | Official channel synchronization |
| Campaign artwork | Campaign media | Existing campaign and media controls |

Admins choose or upload images using the searchable, paginated visual media picker in the story editor, builder/startup profile editor and General settings. Uploads pass through the existing authenticated endpoint with permission checks, CSRF, alt text, image validation and WebP processing. No manual media ID entry is needed. Selecting a different lead story changes the homepage cover image. Clearing a page selection removes that image, and deleting an image still referenced by a page or content is blocked.

The homepage hero and video blocks previously drew decoration even when their records contained an image; those areas now render the supplied media. Directory and search components also use media fields. Text-only heroes, forms and loading skeletons are not treated as missing images. Video rendering uses actual stored video records: no fake YouTube IDs, channel records or playable videos were invented. Official channel details remain pending.

## Repeatable local seed

Run from `.replatform/laravel12-backend`, not the frontend folder:

```sh
php artisan db:seed --class=MediaSeeder
```

This imports the eight manifest images into the media library. It validates the whole image set before writes, refuses conflicting versioned files, checks storage writes and preserves admin metadata edits. It does not attach one image to every record. The production `DatabaseSeeder` no longer calls the image or demo seeders automatically.

```sh
php artisan db:seed --class=DemoContentSeeder
```

This is restricted to the isolated local preview (`the_builder_replatform_preview_20261014`) or test environment. It creates 15 demo stories across the approved editions/categories, five fictional builder profiles across the focus regions, three demo startups and initial page-image selections. It repairs active articles using the former one-photo demo seed or missing media. Admin-selected media, soft-deleted content and existing page-image choices are preserved. Reruns do not duplicate records or overwrite edited demo records. The demo author is suspended with a random password.

The preview database was backed up before seeding to `.replatform/runtime/preview-before-generated-media-*` (private SQL). No production or XAMPP database was migrated or seeded. The old backend and existing immutable release packages remain preserved. Future source/API packages include seed assets through updated allowlists.

Validation: 68 tests / 782 assertions pass on SQLite and isolated MySQL; Larastan has no errors. TypeScript, ESLint, Prettier, theme/contrast, settings, editorial policy, consent, API contract and release regression checks pass. The optimized preview build completed and sealed successfully. All eight source images and optimized responses return 200, and 13 public routes return 200. Browser checks confirmed loaded homepage/secondary images, all five directory cards and About artwork. An MFA-authenticated local admin changed the About hero through the picker; the new database selection appeared on the public page. The initial selection was restored afterward, and the temporary UI fixtures were suspended with sessions revoked. Screenshots: `generated-media-home-20261017.jpg`, `generated-media-builders-20261017.jpg`, `generated-media-about-20261017.jpg`, `generated-media-admin-change-20261017.jpg`. Runtime receipts: `.replatform/runtime/generated-media-database.json` and `generated-media-http.json`.

Regression coverage includes idempotence, admin metadata preservation, failed file writes, immutable asset conflict checks, refusal to seed production, protected media reads, replacing/clearing page images and preventing deletion of referenced images. The seeded preview is not a deployed production release.

Prompts and final filenames are in `database/seed-assets/manifest.json`. Originals remain in the tool's generated-image directory; the application uses workspace WebP files. `tools/prepare-seed-images.php` performs deterministic format conversion with GD and skips existing outputs.
