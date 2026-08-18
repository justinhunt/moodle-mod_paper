# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mod_paper` is a Moodle activity plugin (lives at `public/mod/paper` inside a full Moodle install, currently Moodle 5.1 / `m51`). It lets teachers distribute paper worksheets that are later scanned, OCR'd and graded by AI (OpenAI GPT-4o/vision), then printed back for students who never need to log in to Moodle. See `README.md` for the feature/workflow overview and `DEVELOPER.md` for the full architecture guide (pipeline diagram, DB schema tables, directory map, past milestones) — read `DEVELOPER.md` first for anything non-trivial, it is kept up to date and more detailed than what's below.

This plugin has no `tests/` directory yet (no PHPUnit coverage) and is at `MATURITY_ALPHA` (`version.php`).

## Commands

All commands are run from the Moodle root (`/home/ubuntu/moodles/m51`), not from this plugin directory, since they rely on Moodle core CLI/build tooling.

```bash
# Run the two adhoc tasks manually instead of waiting for cron
php public/admin/cli/adhoc_task.php --execute=\\mod_paper\\task\\process_submissions_task
php public/admin/cli/adhoc_task.php --execute=\\mod_paper\\task\\evaluate_submissions_task

# Rebuild AMD JS after editing amd/src/*.js (setup.js, view_eval.js, reports.js)
npx grunt amd --components=mod_paper

# Purge caches (needed after changing lib.php, settings.php, templates, lang strings)
php public/admin/cli/purge_caches.php

# After changing db/install.xml or adding a db/upgrade.php step: bump version.php then
php public/admin/cli/upgrade.php --non-interactive

# PHP coding standard (Moodle ruleset, configured at repo root via phpcs.xml.dist)
vendor/bin/phpcs public/mod/paper

# PHPUnit (no plugin-specific tests currently exist)
php public/admin/tool/phpunit/cli/init.php   # one-time environment init
vendor/bin/phpunit --filter <TestName> path/to/test.php
```

## Architecture

### Two-stage decoupled AI pipeline

Submission processing is deliberately split into two separate adhoc tasks so a teacher can re-run grading without re-uploading/re-OCRing:

1. **`classes/task/process_submissions_task.php`** — bursts the uploaded multi-page PDF into page images via Ghostscript (path configured in admin settings), crops each response area using percentage-based bounding boxes, and runs OpenAI Vision OCR on each crop. Writes `mdl_paper_evaluations` (one per detected student page) and `mdl_paper_eval_items` (one per response area per student).
2. **`classes/task/evaluate_submissions_task.php`** — batches the OCR'd text to OpenAI for grammar correction, qualitative feedback, and numeric grading, updating `mdl_paper_eval_items` and the rollup `totalgrade`.

`process_submissions.php` (upload handler) queues stage 1; `re_evaluate.php` re-queues only stage 2 against existing OCR text — use this distinction when deciding which task a change belongs to.

### AI layer indirection

`classes/ai_manager.php` is a thin façade in front of `classes/openai_handler.php`, explicitly built for future multi-provider support — `openai_handler` is currently the only implementation. New AI calls should be added to `openai_handler` and exposed through `ai_manager`, not called directly from tasks/pages.

### Template setup vs. rendering

- **Setup** (`setup.php` + `amd/src/setup.js`): HTML5 canvas over the blank worksheet image. Each response area has two independent bounding boxes stored in `mdl_paper_response_areas`: `box_*` (red — where OCR reads student handwriting) and `fb_*` (feedback box — where corrections/feedback get printed on the output PDF). If `fb_*` is unset, `utils::get_effective_feedback_box()` derives a default position from `box_*`.
- **Grading config per response area** is split into three independent modes, not one: `correctanswermode` (none/exactly/relevant/samemeaning), `grammarcorrections` (no/major/all), `feedbackmode` (none/grammatical/custom), `gradingmode` (none/incorrect/overall) — each with its own instructions field. Don't conflate these when adding UI or prompt logic.
- **Rendering** (`classes/pdf_processor.php`): loads the original worksheet image as a TCPDF background and writes corrected text/feedback into the `fb_*` boxes using the per-activity `targetlanguagefont`/`feedbacklanguagefont`. `classes/diff.php` computes the word-level diff (`classes/utils.php` formats it as `paper_del`/`paper_ins` spans) used both in the web review UI and the printed PDF.

### Presets

`mdl_paper_grading_presets` and `mdl_paper_feedback_presets` store reusable prompt/criteria text independent of any single paper instance, managed via `presets.php` and `classes/form/{preset_form,feedback_preset_form}.php`. Prefer adding new reusable prompt content as a preset type rather than hardcoding it into `openai_handler`.

### Pages are Mustache-rendered

`view.php`, `reports.php`, and `view_eval.php` were migrated to Mustache templates (`templates/*.mustache`); avoid reintroducing inline HTML generation in these controllers — build a template context array and render instead. `amd/src/view_eval.js` drives live inline editing/recalculation on the review page against `classes/external/external_api.php` (the plugin's web service endpoints — note this class extends Moodle's `external_api` and namespace/inheritance here has broken before, per `DEVELOPER.md`'s history).

### Database

All tables are prefixed `paper_` (`mdl_paper`, `mdl_paper_response_areas`, `mdl_paper_evaluations`, `mdl_paper_eval_items`, `mdl_paper_grading_presets`, `mdl_paper_feedback_presets`). Full column-level schema is documented in `DEVELOPER.md` — consult it before writing queries or migrations rather than reverse-engineering `db/install.xml`.
