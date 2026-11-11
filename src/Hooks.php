<?php

namespace MediaWiki\Extension\FAQElement;

use MediaWiki\Hook\OutputPageParserOutputHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;

/**
 * Hook handlers for the FAQElement extension.
 *
 * Registers two parser functions:
 *   {{#faq: question | answer }}
 *   {{#faq_group: title | ...faq items... }}
 *
 * Each {{#faq:}} call accumulates its question/answer into ParserOutput
 * extension data under DATA_KEY. Extension:StructuredData reads that data and
 * builds the page's FAQPage JSON-LD from it; this extension no longer emits
 * any JSON-LD itself.
 */
class Hooks implements ParserFirstCallInitHook, OutputPageParserOutputHook {

	/**
	 * ParserOutput extension data key holding the page's questions, as a list of
	 * [ 'question' => plain text, 'answer' => plain text ] in page order.
	 * Extension:StructuredData reads it to build the FAQPage JSON-LD, so the key
	 * and the shape of its value are an interface: change them only together
	 * with StructuredData.
	 */
	public const DATA_KEY = 'faqelement_items';

	/**
	 * @inheritDoc
	 * @throws \MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'faq', [ self::class, 'renderFAQItem' ] );
		$parser->setFunctionHook( 'faq_group', [ self::class, 'renderFAQGroup' ] );
	}

	// ---------------------------------------------------------------------------
	// Parser function handlers
	// ---------------------------------------------------------------------------

	/**
	 * {{#faq: question | answer }}
	 *
	 * Renders a <details>/<summary> disclosure widget and records the
	 * question/answer pair for later JSON-LD output.
	 *
	 * @param Parser $parser
	 * @param string $question
	 * @param string $answer
	 * @return array [ html, 'noparse' => true, 'isHTML' => true ]
	 */
	public static function renderFAQItem( Parser $parser, string $question = '', string $answer = '' ): array {
		$question = trim( $question );
		$answer = trim( $answer );

		$parsedQuestion = self::parseToHtml( $parser, $question );
		$parsedAnswer = self::parseToHtml( $parser, $answer );

		self::accumulateItem( $parser, $parsedQuestion, $parsedAnswer );

		$html = '<details class="faq-item">'
			. '<summary class="faq-summary"><b>'
			. $parsedQuestion
			. '</b></summary>'
			. '<div class="faq-content">'
			. $parsedAnswer
			. '</div>'
			. '</details>';

		return [ $html, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * {{#faq_group: title | ...faq items... | open=yes }}
	 *
	 * Wraps a set of {{#faq:}} items under a collapsible group heading.
	 * The title is NOT a question and does not appear in the schema.
	 * Child {{#faq:}} calls have already been expanded and their data
	 * already accumulated by the time this function runs.
	 *
	 * Named parameters:
	 *   open=yes   Render the group expanded by default.
	 *
	 * @param Parser $parser
	 * @param string $title Group heading (display only)
	 * @param string $content Already-rendered HTML of child faq items
	 * @param mixed ...$rest
	 * @return array [ html, 'noparse' => true, 'isHTML' => true ]
	 */
	public static function renderFAQGroup( Parser $parser, string $title = '', string $content = '', ...$rest ): array {
		$open = false;
		foreach ( $rest as $arg ) {
			if ( preg_match( '/^\s*open\s*=\s*(.+)/i', trim( $arg ), $m ) ) {
				$val = strtolower( trim( $m[1] ) );
				$open = ( $val === 'yes' || $val === '1' || $val === 'true' );
			} else {
				$content .= $arg;
			}
		}

		$html = '<details class="faq-group"' . ( $open ? ' open' : '' ) . '>'
			. '<summary class="faq-group-title"><b>'
			. htmlspecialchars( trim( $title ), ENT_QUOTES )
			. '</b></summary>'
			. '<div class="faq-group-content">'
			. $content
			. '</div>'
			. '</details>';

		return [ $html, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Load the CSS module on pages that have FAQ items.
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		if ( $parserOutput->getExtensionData( self::DATA_KEY ) ) {
			$out->addModuleStyles( 'ext.faqelement.styles' );
		}
	}

	/**
	 * Internal helpers
	 */

	/**
	 * Parse wikitext and return rendered HTML.
	 *
	 * @param Parser $parser
	 * @param string $wikitext
	 * @return string
	 */
	private static function parseToHtml( Parser $parser, string $wikitext ): string {
		return $parser->recursiveTagParseFully( $wikitext );
	}

	/**
	 * Strip all HTML tags from a parsed HTML string and normalize whitespace —
	 * suitable for plain-text contexts such as JSON-LD.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function htmlToPlainText( string $html ): string {
		return preg_replace( '/\s+/', ' ', trim( strip_tags( $html ) ) );
	}

	/**
	 * Append a question/answer pair to the ParserOutput extension data list.
	 * Accepts already-parsed HTML; strips it to plain text for the schema.
	 * Uses setExtensionData rather than global state so it is cache-safe. The
	 * stored list is what Extension:StructuredData reads (see DATA_KEY).
	 *
	 * @param Parser $parser
	 * @param string $parsedQuestion Already-parsed HTML of the question
	 * @param string $parsedAnswer Already-parsed HTML of the answer
	 */
	private static function accumulateItem( Parser $parser, string $parsedQuestion, string $parsedAnswer ): void {
		$output = $parser->getOutput();
		$items = $output->getExtensionData( self::DATA_KEY ) ?? [];
		$items[] = [
			'question' => self::htmlToPlainText( $parsedQuestion ),
			'answer'   => self::htmlToPlainText( $parsedAnswer ),
		];
		$output->setExtensionData( self::DATA_KEY, $items );
	}
}
