# Kirby Content Normalizer

Small Kirby CLI plugin to normalize content files by re-saving site content, all pages/drafts, and file metadata sidecars.

## Command

Run from the project root:

```bash
php vendor/bin/kirby content:normalize
```

## What It Does

- Normalizes content files to Kirby's standard content structure
- Re-saves top-level site content (`site.txt`, `site.<lang>.txt`) with current stored payload
- Iterates through all pages and drafts (`$site->index(true)`)
- Impersonates the `kirby` user for write access
- Re-saves each page with its current stored content payload
- Re-saves each file attached to those pages with its current stored metadata payload
- Initializes missing UUID values
- Creates missing file sidecar metadata files (for example `image.png.txt`) via Kirby's content layer

The command prints progress per page and a final summary.

## Why It Exists

When content is AI-generated in bulk, file formatting and field serialization may not match Kirby's exact storage output. That can create noisy Git diffs and unnecessary content churn.

This plugin re-saves content through Kirby's own model layer so files are normalized consistently and avoid this kind of drift.
