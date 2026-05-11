# VibePresto

WordPress plugin for uploading static HTML/CSS/JS bundles and assigning them as full-page takeovers.

## Scope

- bundle upload and storage
- page assignment
- front-end takeover rendering
- device-style CLI authorization support

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
