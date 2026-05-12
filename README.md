# VibePresto

WordPress plugin for creating and managing pages, uploading versioned static bundles, and assigning them as full-page takeovers or multi-route deployments.

## What it is

VibePresto turns WordPress into a destination for static landing pages and other takeover-style experiences.

Instead of rebuilding a page inside the WordPress editor, you can:

- create or manage a WordPress page
- upload a static HTML/CSS/JS bundle
- assign that bundle to a page or deployment
- keep version history for later updates and rollback
- store route manifests and deployment mappings for multi-page static exports

When a page has an assigned VibePresto bundle, visitors see the uploaded bundle instead of the normal WordPress theme output for that page.

## Recommended setup

VibePresto works best when used together with:

- the published CLI: `npx vibepresto`
- the VibePresto Codex skill in this monorepo

The recommended workflow is:

1. Install and activate the WordPress plugin on the target site.
2. Install or configure the VibePresto skill so your agent knows to use the CLI surface.
3. Use `npx vibepresto` to log in, create/search pages, upload bundles, assign them, and roll back versions.

If you are using Codex locally, point it at the skill in [`../skill`](../skill) so it prefers the CLI flow instead of browser automation.

## Core capabilities

- bundle upload and storage
- bundle lineage versioning and rollback
- multi-route deployment records
- page creation and lifecycle management
- page assignment and mixed-mode deployment mapping
- homepage assignment
- front-end takeover rendering
- device-style CLI authorization support

## How the CLI works

After the plugin is installed on a WordPress site, the normal CLI flow is:

```bash
npx vibepresto login --site https://your-site.example
npx vibepresto pages list --site https://your-site.example --json
npx vibepresto upload --site https://your-site.example --site-dir ./landing-page --page-id 123 --json
```

Useful commands:

- `npx vibepresto whoami --site <site> --json`
- `npx vibepresto pages create --site <site> --title "Landing Page" --status draft --json`
- `npx vibepresto pages set-status --site <site> --page-id <id> --status publish --json`
- `npx vibepresto pages set-homepage --site <site> --page-id <id> --json`
- `npx vibepresto bundles list --site <site> --json`
- `npx vibepresto bundles versions --site <site> --bundle-id <id> --json`
- `npx vibepresto bundles rollback --site <site> --page-id <id> --version <n> --json`

Important behavior:

- if you upload to a page that already has an assigned bundle, VibePresto creates a new version in the same bundle lineage
- older versions stay available for inspection and rollback
- the CLI is the preferred automation surface for agents and scripted workflows

## Install on hosted WordPress

Build the uploadable plugin zip from this repo:

```bash
cd vibepresto
./scripts/package-release.sh
```

That produces:

```text
../releases/vibepresto-<version>.zip
```

Then in WordPress admin:

1. Go to `Plugins > Add New Plugin > Upload Plugin`
2. Upload the generated `vibepresto-<version>.zip`
3. Activate `VibePresto`
4. Open the `VibePresto` admin menu
5. Approve CLI logins from the built-in authorization page when `npx vibepresto login` is used

## GitHub releases

This repo is already set up to produce a release asset with:

```bash
cd vibepresto
./scripts/package-release.sh
```

Recommended release sequence:

1. Update the version in [`vibepresto.php`](./vibepresto.php)
2. Update [`readme.txt`](./readme.txt) changelog if needed
3. Rebuild the zip with `./scripts/package-release.sh`
4. Commit and tag the release, for example `v0.1.0`
5. Push the commit and tag to `origin`
6. Create a GitHub Release in `VibePresto/vibepresto`
7. Upload `releases/vibepresto-<version>.zip` as the release asset

Example git flow:

```bash
cd vibepresto
git status
git add .
git commit -m "Release v0.1.0"
git tag v0.1.0
git push origin main
git push origin v0.1.0
```

Then create the GitHub Release from the tag and attach the zip file.

## Local development

This plugin is intended to be mounted into the private `vibepresto/dev` Docker environment for live editing.

Recommended sibling layout:

```text
~/Development/Playground/
  vibepresto/
  cli/
  skill/
  dev/
```

Inside the `dev` repo, set:

```bash
VIBEPRESTO_PLUGIN_DIR=../vibepresto
```

Then start the stack from `dev` with:

```bash
make install
```
