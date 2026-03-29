<?php

namespace MediaWiki\Extension\FAQElement\Maintenance;

use CommentStoreComment;
use ContentHandler;
use MediaWiki\Revision\SlotRecord;
use Title;
use User;
use WikiPage;

// Maintenance scripts inside extensions must manually include the base class
// when run via the legacy runner. The modern runner (maintenance/run.php,
// available since MW 1.40) handles this automatically.
$maintenancePath = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once $maintenancePath . '/maintenance/Maintenance.php';

/**
 * Maintenance script: migrate {{שאלה ותשובה}} to either
 *   - parser functions:  {{#faq:}} / {{#faq_group:}}
 *   - wrapper templates: {{שאלה ותשובה}} / {{קבוצת שאלות ותשובות}}
 *
 * Usage:
 *   php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php
 *   php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php --mode=template
 *   php maintenance/run.php extensions/FAQElement/maintenance/MigrateFAQTemplate.php --auto
 */
class MigrateFAQTemplate extends \Maintenance {

	private const OLD_TEMPLATE = 'שאלה ותשובה';

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			"Migrate {{שאלה ותשובה}} to {{#faq:}}/{{#faq_group:}} parser functions\n"
			. "or to {{שאלה ותשובה}}/{{קבוצת שאלות ותשובות}} wrapper templates."
		);

		$this->addOption(
			'mode',
			'Conversion target: "parser" (default) or "template"',
			false,
			true
		);
		$this->addOption(
			'auto',
			'Apply all changes without prompting (dry-run is still skipped if combined with --dry-run)'
		);
		$this->addOption(
			'dry-run',
			'Show what would change without saving anything'
		);

		$this->requireExtension( 'FAQElement' );
	}

	public function execute() {
		$mode   = $this->getOption( 'mode', 'parser' );
		$auto   = $this->hasOption( 'auto' );
		$dryRun = $this->hasOption( 'dry-run' );

		if ( !in_array( $mode, [ 'parser', 'template' ], true ) ) {
			$this->fatalError( "Unknown mode \"$mode\". Use \"parser\" or \"template\"." );
		}

		if ( $dryRun ) {
			$this->output( "[DRY RUN — no pages will be saved]\n" );
		}
		$this->output( "Mode: $mode\n\n" );

		$pages = $this->getPagesTranscluding( self::OLD_TEMPLATE );

		if ( !$pages ) {
			$this->output( "No pages found transcluding {{" . self::OLD_TEMPLATE . "}}.\n" );
			return;
		}

		$saved = $skipped = $unchanged = 0;

		foreach ( $pages as $title ) {
			$result = $this->processPage( $title, $mode, $auto, $dryRun );
			if ( $result === 'saved' ) {
				$saved++;
			} elseif ( $result === 'skipped' ) {
				$skipped++;
			} else {
				$unchanged++;
			}
		}

		$this->output( "\nDone.  Saved: $saved  Skipped: $skipped  Unchanged: $unchanged\n" );
	}

	// ---------------------------------------------------------------------------
	// Page processing
	// ---------------------------------------------------------------------------

	/**
	 * Process one page: convert, diff, prompt, save.
	 * Returns 'saved', 'skipped', or 'unchanged'.
	 *
	 * @param Title $title
	 * @param string $mode
	 * @param bool $auto
	 * @param bool $dryRun
	 * @return string
	 * @throws \MWException
	 */
	private function processPage( Title $title, string $mode, bool $auto, bool $dryRun ): string {
		$page = WikiPage::factory( $title );
		$content = $page->getContent();

		if ( $content === null ) {
			return 'unchanged';
		}

		$original  = $content->getText();
		$converted = $this->convertAllCalls( $original, $mode );

		if ( $converted === $original ) {
			$this->output( "  Unchanged: " . $title->getPrefixedText() . "\n" );
			return 'unchanged';
		}

		$this->output( "\n=== " . $title->getPrefixedText() . " ===\n" );
		$this->showDiff( $original, $converted );

		if ( $dryRun ) {
			return 'skipped';
		}

		if ( !$auto ) {
			$choice = $this->prompt( 'Apply changes? [y]es / [n]o / [a]ll / [q]uit: ' );

			if ( $choice === 'q' ) {
				$this->output( "Aborted.\n" );
				exit( 0 );
			}
			if ( $choice === 'a' ) {
				$auto = true;
			}
			if ( $choice === 'n' ) {
				$this->output( "  Skipped.\n" );
				return 'skipped';
			}
		}

		$this->savePage( $page, $converted, $mode );
		return 'saved';
	}

	/**
	 * Save converted wikitext back to the page.
	 *
	 * @param WikiPage $page
	 * @param string $newText
	 * @param string $mode
	 * @throws \MWContentSerializationException
	 * @throws \MWException
	 */
	private function savePage( WikiPage $page, string $newText, string $mode ): void {
		$summary = $mode === 'parser'
			? 'המרת תבנית שאלה ותשובה ל-#faq / #faq_group'
			: 'המרת תבנית שאלה ותשובה לתבניות שאלה ותשובה / קבוצת שאלות ותשובות';

		$updater       = $page->newPageUpdater( User::newSystemUser( 'Maintenance script', [ 'steal' => true ] ) );
		$newContent    = ContentHandler::makeContent( $newText, $page->getTitle() );

		$updater->setContent( SlotRecord::MAIN, $newContent );
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_MINOR
		);

		$this->output( "  Saved: " . $page->getTitle()->getPrefixedText() . "\n" );
	}

	// ---------------------------------------------------------------------------
	// Wikitext conversion (using mwparserfromhell via shell — see note below)
	// ---------------------------------------------------------------------------

	/**
	 * Convert all {{OLD_TEMPLATE}} calls in $text.
	 *
	 * mwparserfromhell is a Python library and not directly callable from PHP.
	 * We therefore use MediaWiki's own preprocessor to find template calls,
	 * which is the correct PHP-native approach.
	 *
	 * Strategy: repeatedly find the innermost (no nested OLD_TEMPLATE) calls
	 * first, convert them, then iterate outward. This mirrors the
	 * deepest-first approach from the pywikibot script.
	 * @param string $text
	 * @param string $mode
	 * @return string
	 */
	private function convertAllCalls( string $text, string $mode ): string {
		// Keep converting until no more instances remain
		$previous = null;
		while ( $text !== $previous ) {
			$previous = $text;
			$text = $this->convertOnePass( $text, $mode );
		}
		return $text;
	}

	/**
	 * One pass: find and replace all {{OLD_TEMPLATE}} calls whose param2
	 * does NOT itself contain {{OLD_TEMPLATE}} (i.e. the innermost ones).
	 * After one pass the previously-outer calls become innermost, so
	 * iterating until stable handles all nesting depths.
	 *
	 * @param string $text
	 * @param string $mode
	 * @return string
	 */
	private function convertOnePass( string $text, string $mode ): string {
		$templateName = self::OLD_TEMPLATE;

		// Matches the opening of the template, capturing everything until
		// we find a matching }}. We do this with a depth-aware loop rather
		// than a regex, for correctness with nested templates.
		$result   = '';
		$offset   = 0;
		$len      = strlen( $text );
		// Build a pattern that matches the template opening
		$openPattern = '/\{\{\s*' . preg_quote( $templateName, '/' ) . '\s*(\||\}\})/u';

		while ( $offset < $len ) {
			if ( !preg_match( $openPattern, $text, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				// No more template calls; append the rest and stop
				$result .= substr( $text, $offset );
				break;
			}

			$matchStart  = $m[0][1];
			$afterOpener = $matchStart + strlen( $m[0][0] );

			// Append text before this template call unchanged
			$result .= substr( $text, $offset, $matchStart - $offset );

			if ( $m[1][0] === '}}' ) {
				// Template with no parameters — leave unchanged
				$result .= $m[0][0];
				$offset  = $afterOpener;
				continue;
			}

			// Parse parameters respecting brace depth, starting after the '|'
			[ $params, $callEnd ] = $this->parseParams( $text, $afterOpener );

			$param1 = trim( $params[0] ?? '' );
			$param2 = trim( $params[1] ?? '' );

			// Only convert if param2 contains NO remaining OLD_TEMPLATE calls
			// (i.e. this is an innermost call or a flat Q&A)
			if ( strpos( $param2, '{{' . $templateName ) === false
				&& stripos( $param2, '{{' . $templateName ) === false
			) {
				$result .= $this->makeReplacement( $param1, $param2, $mode );
			} else {
				// Not innermost yet — copy verbatim and let the next pass handle it
				$result .= substr( $text, $matchStart, $callEnd - $matchStart );
			}

			$offset = $callEnd;
		}

		return $result;
	}

	/**
	 * Parse pipe-separated template parameters starting at $pos (just after
	 * the opening '|'), respecting {{ }}, [[ ]], and {| |} nesting.
	 *
	 * Returns [ params[], endPos ] where endPos is the index after '}}'.
	 *
	 * @param string $text
	 * @param int $pos
	 * @return array
	 */
	private function parseParams( string $text, int $pos ): array {
		$len    = strlen( $text );
		$params = [];
		$current = '';
		$depth  = 1; // we are inside the outer {{ already

		$i = $pos;
		while ( $i < $len ) {
			$c2 = substr( $text, $i, 2 );

			if ( $c2 === '{{' || $c2 === '{|' ) {
				$depth++;
				$current .= $c2;
				$i += 2;
			} elseif ( $c2 === '}}' ) {
				$depth--;
				if ( $depth === 0 ) {
					$params[] = $current;
					return [ $params, $i + 2 ];
				}
				$current .= $c2;
				$i += 2;
			} elseif ( $c2 === '|}' ) {
				$depth--;
				$current .= $c2;
				$i += 2;
			} elseif ( $c2 === '[[' ) {
				// Wikilinks can contain | but must not be treated as param separators
				$current .= '[[';
				$i += 2;
				while ( $i < $len ) {
					$inner2 = substr( $text, $i, 2 );
					$current .= $inner2[0];
					if ( $inner2 === ']]' ) {
						$current .= ']';
						$i += 2;
						break;
					}
					$i++;
				}
			} elseif ( $text[$i] === '|' && $depth === 1 ) {
				$params[] = $current;
				$current  = '';
				$i++;
			} else {
				$current .= $text[$i];
				$i++;
			}
		}

		// Unclosed template — best effort
		$params[] = $current;
		return [ $params, $len ];
	}

	/**
	 * Build the replacement string for a single converted template call.
	 * $param2 here is either a plain answer (flat Q&A) or already-converted
	 * child items (group).
	 *
	 * @param string $param1
	 * @param string $param2
	 * @param string $mode
	 * @return string
	 */
	private function makeReplacement( string $param1, string $param2, string $mode ): string {
		// Detect group: param2 consists only of already-converted calls + whitespace
		$isGroup = $this->isConvertedGroup( $param2, $mode );

		if ( $isGroup ) {
			if ( $mode === 'template' ) {
				return "{{קבוצת שאלות ותשובות\n| $param1\n| $param2\n}}";
			} else {
				return "{{#faq_group: $param1\n| $param2\n}}";
			}
		} else {
			if ( $mode === 'template' ) {
				return "{{שאלה ותשובה\n| $param1\n| $param2\n}}";
			} else {
				return "{{#faq: $param1 | $param2}}";
			}
		}
	}

	/**
	 * Return true if $param2 consists entirely of already-converted FAQ items
	 * (plus whitespace), meaning this call is a group wrapper.
	 *
	 * @param string $param2
	 * @param string $mode
	 * @return bool
	 */
	private function isConvertedGroup( string $param2, string $mode ): bool {
		$stripped = trim( $param2 );
		if ( $stripped === '' ) {
			return false;
		}

		if ( $mode === 'template' ) {
			$itemPattern   = '/^\s*\{\{\s*שאלה ותשובה\s*\n/u';
			$groupStart    = '{{שאלה ותשובה';
		} else {
			$itemPattern   = '/^\s*\{\{#faq:/u';
			$groupStart    = '{{#faq:';
		}

		// Remove all converted item calls and see if anything non-whitespace remains
		$remaining = $stripped;
		$previous  = null;
		while ( $remaining !== $previous ) {
			$previous  = $remaining;
			$remaining = $this->stripOutermostCalls( $remaining, $mode );
		}

		return trim( $remaining ) === '';
	}

	/**
	 * Strip one layer of converted FAQ item calls from $text (for group detection).
	 *
	 * @param string $text
	 * @param string $mode
	 * @return string
	 */
	private function stripOutermostCalls( string $text, string $mode ): string {
		$opener = $mode === 'template' ? '{{שאלה ותשובה' : '{{#faq:';
		$result = '';
		$offset = 0;
		$len    = strlen( $text );

		while ( $offset < $len ) {
			$pos = strpos( $text, $opener, $offset );
			if ( $pos === false ) {
				$result .= substr( $text, $offset );
				break;
			}
			$result .= substr( $text, $offset, $pos - $offset );
			[ , $end ] = $this->parseParams( $text, $pos + strlen( $opener ) + 1 );
			$offset = $end;
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Diff display
	// ---------------------------------------------------------------------------

	/**
	 * @param string $original
	 * @param string $converted
	 * @param int $context
	 * @return void
	 */
	private function showDiff(string $original, string $converted, int $context = 3 ): void {
		$origLines = explode( "\n", $original );
		$convLines = explode( "\n", $converted );
		$maxLen    = max( count( $origLines ), count( $convLines ) );

		$changes = [];
		for ( $i = 0; $i < $maxLen; $i++ ) {
			if ( ( $origLines[$i] ?? null ) !== ( $convLines[$i] ?? null ) ) {
				$changes[] = $i;
			}
		}

		if ( !$changes ) {
			return;
		}

		// Group into hunks
		$hunks      = [];
		$hunkStart  = $hunkEnd = $changes[0];
		foreach ( array_slice( $changes, 1 ) as $idx ) {
			if ( $idx - $hunkEnd <= $context * 2 ) {
				$hunkEnd = $idx;
			} else {
				$hunks[]   = [ $hunkStart, $hunkEnd ];
				$hunkStart = $hunkEnd = $idx;
			}
		}
		$hunks[] = [ $hunkStart, $hunkEnd ];

		foreach ( $hunks as [ $hStart, $hEnd ] ) {
			$lo    = max( 0, $hStart - $context );
			$hi    = $hEnd + $context + 1;
			$hiOrig = min( count( $origLines ), $hi );
			$hiConv = min( count( $convLines ), $hi );

			$this->output( sprintf( "@@ lines %d–%d @@\n", $lo + 1, max( $hiOrig, $hiConv ) ) );

			for ( $i = $lo; $i < max( $hiOrig, $hiConv ); $i++ ) {
				$o = $origLines[$i] ?? null;
				$c = $convLines[$i] ?? null;
				if ( $o === $c ) {
					$this->output( "  $o\n" );
				} else {
					if ( $o !== null ) {
						$this->output( "- $o\n" );
					}
					if ( $c !== null ) {
						$this->output( "+ $c\n" );
					}
				}
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Database: find transclusions
	// ---------------------------------------------------------------------------

	/**
	 * Return all Title objects for pages that transclude {{OLD_TEMPLATE}},
	 * excluding the template's own page and redirects.
	 *
	 * @param string $templateName
	 * @return Title[]
	 */
	private function getPagesTranscluding( string $templateName ): array {
		$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
		if ( !$templateTitle || !$templateTitle->exists() ) {
			$this->output( "Template \"$templateName\" not found.\n" );
			return [];
		}

		$dbr   = $this->getDB( DB_REPLICA );
		$res   = $dbr->select(
			[ 'templatelinks', 'page' ],
			[ 'page_namespace', 'page_title' ],
			[
				'tl_namespace' => NS_TEMPLATE,
				'tl_title'     => $templateTitle->getDBkey(),
				'page_is_redirect' => 0,
			],
			__METHOD__,
			[],
			[
				'page' => [ 'INNER JOIN', 'page_id = tl_from' ],
			]
		);

		$titles = [];
		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			// Skip the template namespace itself (doc pages etc.)
			if ( $title && $title->getNamespace() !== NS_TEMPLATE ) {
				$titles[] = $title;
			}
		}

		return $titles;
	}

	// ---------------------------------------------------------------------------
	// Interactive prompt
	// ---------------------------------------------------------------------------

	/**
	 * Prompt the user and return a single lowercased character.
	 * Falls back to 'y' when stdin is not a TTY (e.g. piped input).
	 *
	 * @param string $message
	 * @return string
	 */
	private function prompt( string $message ): string {
		$this->output( $message );
		$line = fgets( STDIN );
		if ( $line === false ) {
			return 'y';
		}
		return strtolower( trim( $line ) )[0] ?? 'n';
	}
}

$maintClass = MigrateFAQTemplate::class;
require_once RUN_MAINTENANCE_IF_MAIN;
