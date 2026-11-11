# FAQElement

A MediaWiki extension that renders collapsible FAQ sections and records their
questions and answers for [FAQPage](https://schema.org/FAQPage) structured data.
The JSON-LD itself is emitted by
[StructuredData](https://github.com/kolzchut/mediawiki-extensions-StructuredData),
together with the page's other schema.org data.

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
recorded for the page's FAQPage structured data.

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

## Structured data

Since version 2.0.0 this extension emits no JSON-LD. Each `{{#faq:}}` call
stores its question and answer, as plain text, in the parser output's extension
data under `faqelement_items` (`Hooks::DATA_KEY`): a list of
`[ 'question' => …, 'answer' => … ]` in page order. All questions from the
page, standalone or inside groups, are in that one flat list; group titles are
not, as the schema.org FAQPage type has no concept of groups.

[StructuredData](https://github.com/kolzchut/mediawiki-extensions-StructuredData)
reads that list and adds the FAQPage to the page's JSON-LD `@graph`. The key and
the shape of its value are therefore an interface between the two extensions.

The `$wgFAQElementOutputSchema` setting is gone. StructuredData's
`$wgStructuredDataFAQ` switches the FAQPage on and off.

## How it works

1. **Parsing** — When MediaWiki expands a page's wikitext, each `{{#faq:}}` call
   invokes `Hooks::renderFAQItem()`. This renders the HTML widget and writes the
   question/answer pair into `ParserOutput` extension data under the key
   `faqelement_items`. Because MediaWiki expands inner templates before outer
   ones, `{{#faq:}}` items inside a `{{#faq_group:}}` are processed first and
   their data is accumulated before the group wrapper runs. The group itself
   (`Hooks::renderFAQGroup()`) only wraps the already-rendered child HTML in a
   `<div>` — it does not touch the FAQ data at all.

2. **Styles** — `OutputPageParserOutput` adds the `ext.faqelement.styles`
   module to pages that have FAQ items. Building the FAQPage JSON-LD from the
   accumulated items is StructuredData's job.

3. **Parser cache** — The FAQ item data is stored via
   `ParserOutput::setExtensionData()`, which is cache-aware. Cached pages
   correctly retain their FAQ data across requests without re-parsing.

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
