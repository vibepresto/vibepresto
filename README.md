# VibePresto

WordPress plugin for uploading versioned static site bundles and serving them as page takeovers or multi-route deployments.

## Overview

VibePresto turns WordPress into a destination for static landing pages, campaign pages, and exported frontend experiences.

Instead of rebuilding a page inside the WordPress editor, you can:

- create or manage WordPress pages
- upload a static HTML/CSS/JS bundle
- assign that bundle to a single page or a multi-page deployment
- keep version history for updates and rollback
- map exported routes from a static frontend build onto WordPress pages
- render WordPress page data inside uploaded HTML through `data-vp-*` placeholders

When a page has an assigned VibePresto bundle, visitors see the uploaded bundle instead of the normal WordPress theme output for that page.

## Core capabilities

- bundle upload and storage
- bundle lineage versioning and rollback
- multi-route deployment records
- page creation and lifecycle management
- page assignment and mixed-mode deployment mapping
- homepage assignment
- posts page assignment
- front-end takeover rendering
- WordPress placeholder rendering for page data
- device-style CLI authorization support

## Recommended workflow

VibePresto works best together with:

- the published CLI: `npx vibepresto`
- the VibePresto Codex skill

Typical flow:

1. Install and activate the plugin on the target WordPress site.
2. Use `npx vibepresto login --site https://your-site.example`.
3. Create, search, or manage pages through the CLI.
4. Upload a single bundle or deploy a multi-route static build.
5. Promote, inspect, or roll back bundle and deployment history when needed.

## Example CLI flow

Single-page upload:

```bash
npx vibepresto login --site https://your-site.example
npx vibepresto pages create --site https://your-site.example --title "Landing Page" --status draft --json
npx vibepresto upload --site https://your-site.example --site-dir ./landing-page --page-id 123 --json
npx vibepresto pages set-status --site https://your-site.example --page-id 123 --status publish --json
```

Framework-aware multi-route deployment:

```bash
npx vibepresto login --site https://your-site.example
npx vibepresto build --project-dir ./my-app --json
npx vibepresto routes inspect --output-dir ./my-app/dist --json
npx vibepresto deploy --site https://your-site.example --output-dir ./my-app/dist --create-missing-pages --json
```

## Important behavior

- If you upload to a page that already has an assigned bundle, VibePresto creates a new version in the same bundle lineage.
- Older versions remain available for inspection and rollback.
- Multi-page deployments are driven by route manifests and page mappings.
- The plugin stores and serves static artifacts only. It does not run Node, SSR, or application servers inside WordPress.
- Placeholder rendering is page-only in v1 and resolves values from the current queried WordPress page object.
- The configured WordPress posts page can also use a VibePresto bundle and placeholder rendering.

## WordPress placeholders

Uploaded HTML can request WordPress values at render time with:

- `data-vp-source="post"`
- `data-vp-field="<wp-style-field>"`

Supported fields:

- `post_title`
- `post_name`
- `post_excerpt`
- `post_content`
- `post_date`
- `post_modified`
- `post_author`
- `permalink`
- `featured_image_url`
- `author_display_name`

Example:

```html
<article>
  <h1 data-vp-source="post" data-vp-field="post_title">Fallback title</h1>
  <p data-vp-source="post" data-vp-field="post_excerpt">Fallback excerpt</p>
  <p data-vp-source="post" data-vp-field="post_date">Fallback date</p>
</article>
```

Notes:

- V1 replaces element text content only.
- Missing or unsupported values leave the authored fallback text in place.
- `post_content` is emitted as plain text, not raw HTML.
- On a WordPress posts page takeover, `data-vp-source="post"` resolves from the configured posts page object, not each individual post entry.

## Install on WordPress

Build the plugin zip:

```bash
./scripts/package-release.sh
```

That produces:

```text
../releases/vibepresto-<version>.zip
```

Then in WordPress admin:

1. Go to `Plugins > Add New Plugin > Upload Plugin`
2. Upload `vibepresto-<version>.zip`
3. Activate `VibePresto`
4. Open the `VibePresto` admin menu
5. Approve CLI logins from the built-in authorization page when `npx vibepresto login` is used

## Release packaging

To produce a release asset:

```bash
./scripts/package-release.sh
```

Recommended release sequence:

1. Update the version in [`vibepresto.php`](./vibepresto.php)
2. Update [`readme.txt`](./readme.txt) if needed
3. Rebuild the zip with `./scripts/package-release.sh`
4. Create a Git tag and GitHub release
5. Upload `vibepresto-<version>.zip` as the release asset
