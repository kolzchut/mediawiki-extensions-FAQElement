# FAQElement

A MediaWiki extension that renders collapsible FAQ sections and automatically
injects [FAQPage](https://schema.org/FAQPage) JSON-LD structured data into the
page `<head>`, improving how search engines (particularly Google) understand and
display FAQ content.

## Requirements

- MediaWiki 1.35 or later
- PHP 7.4 or later

## Installation

1. Copy the `FAQElement/` directory into your `extensions/` folder.
2. Add the following line to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'FAQElement' );
   ```
3. Done. No database changes are required.

## Usage

The extension provides two parser functions.

### `{{#faq:}}` — a single question and answer

```wikitext
{{#faq: What are your opening hours? | We are open Monday to Friday, 9am–5pm. }}
```

Renders as a `<details>`/`<summary>` disclosure widget: the question is always
visible, the answer is revealed on click. The question and answer are also
recorded for the page's JSON-LD schema output.

### `{{#faq_group:}}` — a titled group of questions

```wikitext
{{#faq_group: Getting started
| {{#faq: How do I register? | Click "Sign up" on the homepage. }}
  {{#faq: Is registration free? | Yes, completely free. }}
}}
```

Wraps a set of `{{#faq:}}` items under a visual heading. The group title is
**not** a question and does **not** appear in the schema — it is purely a
presentational label.

Groups and standalone items can be mixed freely on the same page:

```wikitext
{{#faq_group: Eligibility
| {{#faq: Who can apply? | Any resident over 18. }}
  {{#faq: Do I need a referral? | No referral is needed. }}
}}

{{#faq: How long does processing take? | Usually 5–7 business days. }}
```

## Wrapper templates (optional)

If you prefer template syntax over parser function syntax, you can create two
thin wrapper templates that call the parser functions internally. This keeps
article wikitext familiar to editors and decouples the visible syntax from the
underlying implementation.

**`Template:שאלה ותשובה`** (single item):
```wikitext
<includeonly>{{#faq: {{{1|}}} | {{{2|}}} }}</includeonly>
```

**`Template:שאלות ותשובות`** (group):
```wikitext
<includeonly>{{#faq_group: {{{1|}}} | {{{2|}}} }}</includeonly>
```

With these templates in place, editors can write:
```wikitext
{{שאלה ותשובה
| How do I register?
| Click "Sign up" on the homepage.
}}
```

## Configuration

Add any of the following to `LocalSettings.php` to override defaults:

```php
// Disable JSON-LD schema output (HTML widgets are still rendered normally)
$wgFAQElementOutputSchema = false;
```

| Variable | Default | Description |
|---|---|---|
| `$wgFAQElementOutputSchema` | `true` | Inject a FAQPage JSON-LD `<script>` block into the `<head>` of pages that contain `{{#faq:}}` calls. Set to `false` to render the collapsible widgets without emitting structured data. |

## Schema output

When a page contains at least one `{{#faq:}}` call and `$wgFAQElementOutputSchema`
is `true` (the default), the extension injects a single
`<script type="application/ld+json">` block into the page `<head>`:

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "How do I register?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Click \"Sign up\" on the homepage."
      }
    }
  ]
}
```

All questions from the page — whether standalone or inside groups — are
collected into a single flat `mainEntity` array. The `schema.org` FAQPage type
has no concept of groups, so group titles are intentionally excluded.

## How it works

1. **Parsing** — When MediaWiki expands a page's wikitext, each `{{#faq:}}` call
   invokes `Hooks::renderFAQItem()`. This renders the HTML widget and writes the
   question/answer pair into `ParserOutput` extension data under the key
   `faqelement_items`. Because MediaWiki expands inner templates before outer
   ones, `{{#faq:}}` items inside a `{{#faq_group:}}` are processed first and
   their data is accumulated before the group wrapper runs. The group itself
   (`Hooks::renderFAQGroup()`) only wraps the already-rendered child HTML in a
   `<div>` — it does not touch the schema data at all.

2. **Schema injection** — The `BeforePageDisplay` hook fires once per page view
   after parsing is complete. `Hooks::onBeforePageDisplay()` reads the
   accumulated items from `ParserOutput` and, if any exist, serialises them as
   JSON-LD and calls `$out->addHeadItem()` to insert the `<script>` block.
   `BeforePageDisplay` is used (rather than `OutputPageBeforeHTML`) because it
   fires exactly once per page view and its output is correctly cached.

3. **Parser cache** — The FAQ item data is stored via
   `ParserOutput::setExtensionData()`, which is cache-aware. Cached pages
   correctly retain their schema data across requests without re-parsing.

## Migration from `{{שאלה ותשובה}}`

If your wiki currently uses the old single-template pattern (one template for
both flat items and groups, distinguished only by whether param 2 contains
nested calls), the bundled maintenance script migrates all pages automatically.

```bash
# Preview changes without saving
php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php --dry-run

# Interactive migration — confirm each page individually
php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php

# Migrate to wrapper templates instead of raw parser functions
php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php --mode=template

# Fully automatic — no prompts
php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php --auto
```

During interactive mode the script shows a diff for each page and prompts:
`[y]es / [n]o / [a]ll / [q]uit`. Choosing `a` applies all remaining pages
without further prompting.

Alternatively, `maintenance/migrate_faq.py` provides the same migration
functionality as a [Pywikibot](https://www.mediawiki.org/wiki/Manual:Pywikibot)
script, which may be more convenient if you already have Pywikibot set up:

```bash
python pwb.py migrate_faq
python pwb.py migrate_faq --mode=template
python pwb.py migrate_faq --always
```

## Wrapper templates

The `wikitemplates/` directory contains ready-to-import Hebrew wrapper templates
for the two parser functions (`Template:שאלה ותשובה` and
`Template:שאלות ותשובות`). See the [Wrapper templates](#wrapper-templates-optional)
section above for details on what these do and why you might want them.
