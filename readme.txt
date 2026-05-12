=== VibePresto ===
Contributors: vibepresto
Tags: static site, landing page, page takeover, deployment
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/license/mit

Create and manage WordPress pages, then upload versioned static HTML, CSS, and JavaScript bundles and assign them as full-page takeovers.

== Description ==

VibePresto lets administrators create and manage pages, upload a static site bundle, store version history inside WordPress, and assign it to a page. It also exposes a CLI-friendly authorization and upload API for the published `vibepresto` CLI.

Features:

* Upload a prepared ZIP bundle
* Upload separate HTML, CSS, JS, and asset files
* Keep bundle version history and roll back to earlier versions
* Create WordPress pages from the CLI
* List pages and change page status from the CLI
* Set a page as the default homepage
* Assign a bundle to a WordPress page
* Render the assigned bundle as a full-page takeover
* Approve and manage CLI sessions from wp-admin

== Installation ==

1. In WordPress admin, go to Plugins > Add New Plugin > Upload Plugin.
2. Upload the `vibepresto.zip` file.
3. Activate the plugin.
4. Open the `VibePresto` admin menu.
5. Upload a bundle manually or connect with the CLI using `npx vibepresto login --site https://your-site.example`.

== FAQ ==

= Does this build my frontend app? =

No. VibePresto currently expects plain static HTML, CSS, JavaScript, and assets that are already prepared for deployment.

= Can non-admin users upload bundles? =

No. Bundle upload and assignment are restricted to administrators.

== Changelog ==

= 0.1.0 =

* Initial public release.
