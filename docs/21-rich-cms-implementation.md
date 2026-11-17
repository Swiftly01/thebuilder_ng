# Rich article CMS implementation

18 October 2026. Implemented in the Next.js frontend and clean Laravel candidate. This is a local implementation and verification record, not a production deployment approval.

## Delivered behaviour

- Next.js 16 / React 19 loads a client-only, dynamically imported Tiptap 3.32 editor. Tiptap's open-source packages supply editing behaviour; the application supplies its own toolbar, media node, upload flow and Laravel integration. No hosted editor account is needed.
- Fonts: Source Serif 4, Inter, Montserrat, Georgia, Arial, Times New Roman and monospace. The three brand fonts already ship locally with the frontend.
- Font sizes: 12, 14, 16, 18, 19, 20, 22, 24, 28, 32, 36, 40 and 48px, plus the document default. Line spacing: default, 1.2, 1.5, 1.7, 2 and 2.4.
- Text colour, highlight colour, clear colour, bold, italic, underline, strike, subscript, superscript, headings H2-H4, paragraph alignment, bullet/numbered lists, quotations, code blocks, dividers, links and removal of formatting.
- Table insertion, row/column addition/deletion, header toggling and table removal. Wide tables scroll inside a labelled, keyboard-focusable region rather than widening the page.
- Multiple images inserted at the cursor through the existing searchable/uploadable media library. Selecting text before insertion collapses to its end so adding an image does not delete that text. Slash insertion is available in an empty paragraph.
- Placement-level alt text, optional caption and credit, image replacement, movement, removal, undo/redo, and either the designer's 3:2 frame or complete-image framing. Enter can create a paragraph after an image.
- Image metadata fields stay disabled until the chosen media loads, preventing delayed library responses from overwriting freshly typed credit/alt text.
- Images use database media IDs; transient delivery URLs are stripped before persistence. Laravel resolves current storage URLs for article detail reads, so moving storage does not require rewriting the document.
- Featured images remain independent of content images and continue to supply story cards and the article hero.
- The article, editor and private preview share figure typography/styles. Body figures follow the supplied Article design: 3:2 frame, 12px corners, 2em vertical spacing, and 13px/18px captions with 10px top spacing.
- Title and summary fields grow with content and viewport width. Toolbar controls wrap, image dialogs scroll within the viewport, and article images fit the reading column. Light and dark surfaces use semantic colour tokens.
- Image/link/preview/history dialogs support Escape and keyboard focus containment; modal backgrounds do not scroll. Unsaved browser unloads warn before leaving.
- First-save URL changes preserve the mounted editor and in-flight typing. Successful save, transition and restore responses update the console's editor cache. Reading/importing an article alone does not mark its content dirty.

## Data and API

Migration: `2026_10_18_000001_add_rich_article_documents.php`.

- Adds nullable `articles.body_document`, nullable `media.width`/`height`, and indexed, foreign-key-protected `article_media_uses` for current content and retained revisions.
- `bodyDocument` is the canonical input for rich articles. Its schema is version 1, returned as `bodySchemaVersion` in console responses. Laravel validates node types, nesting, counts, typography, link protocols and media IDs, then generates escaped HTML in the existing `body` field.
- Bounds: 500 KB document/rendered HTML, 5,000 nodes and 24 nesting levels. Font families, sizes, line heights and alignments are allowlisted. Colours are six-digit hex or normalized RGB values. Client image URLs are not trusted or persisted.
- Existing create/update, optimistic `If-Match`, editorial review, scheduling and version restoration continue to apply. Submitting independently editable HTML and JSON together is rejected. HTML-only overwrites of a rich document are rejected.
- `POST /console/content-preview` validates/renders without saving; `POST /console/content-import` converts existing supported HTML without saving. Both retain session, CSRF, MFA and content permissions and return private/no-store responses.
- OpenAPI contains 86 paths / 108 operations and is mirrored to the frontend contract.
- Uploads continue through `POST /console/media`: JPEG/PNG/WebP, 10 MB and 8 megapixel limits, processed to WebP, with required alt text. Width/height are now recorded. The generated asset seeder backfills dimensions without replacing admin descriptions or credits.
- Current and revision references prevent media deletion. Compatibility checks also protect pre-migration HTML and historical covers. A failed storage deletion preserves its media record for retry.
- Restoring an older HTML revision clears any newer structured document. Restoring a rich revision restores its document, formatting and media references. A previously exposed default-type snapshot bug is also fixed.

## Existing articles and deployment

The migration is additive: existing bodies and revisions are retained. An existing HTML article is imported read-only when its editor opens. Supported content becomes a structured document on the next actual edit/save. Unsupported markup or unknown/external images produce an explanation and keep the saved original intact; there is no silent bulk conversion or remote image fetching. Pasted web typography is normalized to supported formats while preserving text and semantic emphasis.

Back up the intended environment's database and media storage before deploying the migration. Deploy backend support before the new frontend. Run the normal Laravel migration from the clean API directory with that environment's verified configuration, then deploy a newly built Next.js artifact for its intended domains. Do not promote a sealed local preview build to production.

Rollback should retain the additive schema and generated HTML. Disable new rich editing first, and retain media deletion protection while structured documents or revision references exist. Do not drop the document/reference tables as an ordinary application rollback.

Only the isolated preview database `the_builder_replatform_preview_20261014` on port 3307 was migrated here, after a guarded check and SQL backup. The preserved legacy backend, XAMPP database on port 3306 and production were not migrated or deployed by this work.

## Verification evidence

Laravel feature coverage includes typography/HTML escaping, multiple image order, private preview, publication, revision restoration, legacy restore/import, permission rejection, unsafe/oversized/deep documents, retained media protection, storage failure regression and portable storage origins. Browser checks exercise real authenticated admin controls and the public article renderer.

Final results:

- Full Laravel suite: **76 tests / 865 assertions**, passing on SQLite and isolated MySQL.
- Larastan level 6 and targeted Pint checks pass. The OpenAPI contract validates with 86 paths / 108 operations.
- Next.js production-mode local preview build compiles all routes and seals its build identity. TypeScript, formatting, settings/auth/editorial/cookie/contract/release/theme checks pass.
- Final HTTP checks return 200 for frontend and API. The served Next.js build ID matches `.next/BUILD_ID`. A second development process listening on IPv6 port 3000 was identified and stopped; only the verified preview listener remains on that port.
- Changed editor, console context, media picker and shared body components pass ESLint with `--max-warnings=0`. Repository-wide lint exits successfully with 85 existing warnings elsewhere; this change does not claim the entire repository is warning-free.
- npm audit for production dependencies reports zero vulnerabilities. Existing development-tooling findings remain governed by the earlier tooling-risk record.
- Browser verification covers applying Montserrat/24px/text colour, editing image credits, multiple images, movement, table insertion, save/reopen, first-save URL changes with continued typing, and authenticated preview. Preview body HTML matches the published body exactly; both images load successfully.
- Editor and public article checked at 390, 768 and 1280px. Page widths remain within the viewport; title/summary content heights match their fields. Mobile tables retain 480px width inside a 263–343px scroll container rather than overflowing the page.
- Dark preview and light editor/public surfaces checked; the user's original System theme preference is restored.

Screenshots:

- `rich-cms-editor-desktop-20261018.jpg`
- `rich-cms-editor-mobile-20261018.jpg`
- `rich-cms-public-desktop-20261018.jpg`
- `rich-cms-public-mobile-20261018.jpg`

Machine-readable local evidence: `.replatform/runtime/rich-cms-browser.json` and `rich-cms-verification.json`. The pre-migration backup is `preview-before-rich-cms-20261018.sql` in the private runtime directory; do not publish that database backup.

The temporary QA publication was returned to draft (version 12); the first-save test draft was soft-deleted after its persisted text was checked. Disposable QA accounts are suspended, their passwords randomized and sessions revoked. Production and XAMPP data were not changed.

## Story settings inspector update — 18 October 2026

Formatting, inline-image insertion and contextual image/table actions now appear in a collapsible **Formatting & media** section under **Story settings**. The writing canvas contains the article body and its on-demand slash insertion menu. The same mounted editor owns both the canvas and sidebar controls, preserving selection, undo history, draft saving and image dialogs. The inspector follows the existing responsive sidebar layout and can be collapsed with mouse, touch or keyboard.

Verified against the rebuilt local preview: sidebar-only toolbar and contextual image/table controls; selected-word bold and 28px font changes; undo to the original content and draft save; image dialog opening/cancellation; mobile collapse/reopen; no horizontal page overflow at 390, 768 and 1280px. TypeScript, targeted zero-warning ESLint, semantic theme checks and optimized Next.js build pass. Screenshots: `rich-cms-sidebar-desktop-20261018.jpg` and `rich-cms-sidebar-mobile-20261018.jpg`.

## Scope boundary

This implements rich formatting and single-image blocks throughout articles. Galleries, video/social embeds, collaborative editing, arbitrary uploaded fonts and arbitrary executable HTML are not added by this release. The document schema can be extended deliberately for further CMS blocks.
