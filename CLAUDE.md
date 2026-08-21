# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mod_paper` is a Moodle activity plugin (lives at `public/mod/paper` inside a full Moodle install, currently Moodle 5.1 / `m51`). It lets teachers distribute paper worksheets that are later scanned, OCR'd and graded by AI (OpenAI GPT-4o/vision), then printed back for students who never need to log in to Moodle. See `README.md` for the feature/workflow overview and `DEVELOPER.md` for the full architecture guide (pipeline diagram, DB schema tables, directory map, past milestones) — read `DEVELOPER.md` first for anything non-trivial, it is kept up to date and more detailed than what's below.

This plugin has no `tests/` directory yet (no PHPUnit coverage) and is at `MATURITY_ALPHA` (`version.php`).

## Commands

This site runs in Docker, so anything needing the database must go through the webserver
container — the host shell has no route to the `db` host and none of the `MOODLE_DOCKER_*`
env vars that `config.php` reads. Inside the container the Moodle root is `/var/www/html`.
Build/lint tooling has no such requirement and runs on the host from `/home/ubuntu/moodles/m51`.

Note the directory layout: `dirroot` is `public/`, but `admin/cli/` deliberately sits
*outside* it (Moodle 5.x keeps CLI scripts out of the docroot). So it is `admin/cli/…` and
`public/admin/tool/…` — not `public/admin/cli/…`, which does not exist.

```bash
# Run the two adhoc tasks manually instead of waiting for cron.
# Use --classname, NOT --execute=<class>: --execute is a valueless boolean flag, so passing
# a class to it casts to true and runs EVERY queued adhoc task on the site. --classname
# implies --execute and scopes the run to that task.
docker exec moodle51-webserver-1 php /var/www/html/admin/cli/adhoc_task.php \
    --classname='\mod_paper\task\process_submissions_task'
docker exec moodle51-webserver-1 php /var/www/html/admin/cli/adhoc_task.php \
    --classname='\mod_paper\task\evaluate_submissions_task'

# Purge caches (needed after changing lib.php, settings.php, templates, lang strings)
docker exec moodle51-webserver-1 php /var/www/html/admin/cli/purge_caches.php

# After changing db/install.xml or adding a db/upgrade.php step: bump version.php then
docker exec moodle51-webserver-1 php /var/www/html/admin/cli/upgrade.php --non-interactive

# Rebuild AMD JS after editing amd/src/*.js (constants.js, setup.js, view_eval.js, reports.js).
# Run on the host. Do NOT use `npx` - it resolves to the Windows Node over WSL interop and
# fails on the UNC path. --force is needed to get past an unrelated ignorefiles warning about
# a missing local/codechecker PHPCompatibility path.
node node_modules/.bin/grunt amd --components=mod_paper --force

# --components does not scope eslint (it lints the whole tree), so to lint just this plugin:
node node_modules/.bin/eslint public/mod/paper/amd/src/

# PHP coding standard (Moodle ruleset, configured at repo root via phpcs.xml.dist).
# phpcs is NOT in the root vendor/bin - Moodle core does not depend on it. The binary comes
# from the local_codechecker plugin, which bundles moodlehq/moodle-cs and registers the
# "moodle" standard that phpcs.xml.dist refers to. Runs on the host.
public/local/codechecker/vendor/bin/phpcs public/mod/paper

# PHPUnit (no plugin-specific tests currently exist). Needs the DB, so runs in the container.
docker exec moodle51-webserver-1 php /var/www/html/public/admin/tool/phpunit/cli/init.php
docker exec moodle51-webserver-1 /var/www/html/vendor/bin/phpunit --filter <TestName> path/to/test.php
```

For one-off inspection scripts, write the file locally, `docker cp` it to `/tmp` in the
webserver container, run it, then delete it. Such scripts need `define('CLI_SCRIPT', true);`
before requiring `/var/www/html/config.php`.

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
- **Area types** live in `mdl_paper_response_areas.areatype` (graded / name / username / display-only / ungraded). Never compare it to a raw integer: the values are `mod_paper\constants::M_AREATYPE_*` and the checks are `utils::is_graded_area()`, `is_name_area()`, `is_displayonly_area()` and `has_ocr_text()`. `DEVELOPER.md` documents what each type does.
- **Grading config per response area** is split into three independent modes, not one: `correctanswermode` (none/exactly/relevant/samemeaning), `grammarcorrections` (no/major/all), `feedbackmode` (none/grammatical/custom), `gradingmode` (none/incorrect/overall) — each with its own instructions field. Don't conflate these when adding UI or prompt logic.
- **Rendering** (`classes/pdf_processor.php`): loads the original worksheet image as a TCPDF background and writes corrected text/feedback into the `fb_*` boxes using the per-activity `targetlanguagefont`/`feedbacklanguagefont`. `classes/diff.php` computes the word-level diff (`classes/utils.php` formats it as `paper_del`/`paper_ins` spans) used both in the web review UI and the printed PDF.

### Presets

`mdl_paper_grading_presets` and `mdl_paper_feedback_presets` store reusable prompt/criteria text independent of any single paper instance, managed via `presets.php` and `classes/form/{preset_form,feedback_preset_form}.php`. Prefer adding new reusable prompt content as a preset type rather than hardcoding it into `openai_handler`.

### Pages are Mustache-rendered

`view.php`, `reports.php`, and `view_eval.php` were migrated to Mustache templates (`templates/*.mustache`); avoid reintroducing inline HTML generation in these controllers — build a template context array and render instead. `amd/src/view_eval.js` drives live inline editing/recalculation on the review page against `classes/external/external_api.php` (the plugin's web service endpoints — note this class extends Moodle's `external_api` and namespace/inheritance here has broken before, per `DEVELOPER.md`'s history).

### Database

All tables are prefixed `paper_` (`mdl_paper`, `mdl_paper_response_areas`, `mdl_paper_evaluations`, `mdl_paper_eval_items`, `mdl_paper_grading_presets`, `mdl_paper_feedback_presets`). Full column-level schema is documented in `DEVELOPER.md` — consult it before writing queries or migrations rather than reverse-engineering `db/install.xml`.
