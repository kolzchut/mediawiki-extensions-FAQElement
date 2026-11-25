<?php

namespace MediaWiki\Extension\FAQElement\Tests\Integration;

use DOMDocument;
use DOMXPath;
use MediaWiki\Extension\FAQElement\Hooks;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWikiIntegrationTestCase;

/**
 * Runs {{#faq:}} through the real parser and checks the markup of the
 * question: bold, inline, and not wrapped in a paragraph.
 *
 * In the Database group because a full parse looks the page up (content model,
 * page language); nothing is written.
 *
 * @group Database
 * @covers \MediaWiki\Extension\FAQElement\Hooks
 */
class FAQItemParserTest extends MediaWikiIntegrationTestCase {

	private function parse( string $wikitext ): ParserOutput {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		return $parser->parse(
			$wikitext,
			PageReferenceValue::localReference( NS_MAIN, 'FAQElement_item_parser_test' ),
			ParserOptions::newFromAnon()
		);
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		// libxml's HTML parser does not know HTML5 elements such as <details>,
		// and says so; it still builds the tree.
		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		return new DOMXPath( $doc );
	}

	public function testQuestionIsBoldWithoutAParagraph(): void {
		$html = $this->parse( '{{#faq: What is foo? | Foo is bar. }}' )->getRawText();

		$this->assertStringContainsString(
			'<summary class="faq-summary"><b>What is foo?</b></summary>', $html );
		$this->assertStringNotContainsString( '<p>What is foo?', $html );
	}

	public function testQuestionMarkupStillRenders(): void {
		$html = $this->parse(
			"{{#faq: Can I get ''this'' from [https://example.org/ the office]? | A }}"
		)->getRawText();
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//summary//p' )->length, $html );
		$this->assertSame( 1, $xpath->query( '//summary[@class="faq-summary"]/b/i' )->length, $html );
		$this->assertSame( 1,
			$xpath->query( '//summary[@class="faq-summary"]/b/a[contains(@class, "external")]' )->length,
			$html );
	}

	public function testAnswerKeepsItsParagraphs(): void {
		$html = $this->parse( "{{#faq: Q | First paragraph.\n\nSecond paragraph. }}" )->getRawText();
		$xpath = $this->xpath( $html );

		$this->assertSame( 2, $xpath->query( '//div[@class="faq-content"]/p' )->length, $html );
	}

	public function testNoQuestionInAGroupHasAParagraph(): void {
		// The shape the wrapper templates produce on the guide pages: a group of
		// items one per line, answers carrying links.
		$output = $this->parse(
			"{{#faq_group: Group title\n" .
			"| {{#faq: First question | First answer }}\n" .
			"{{#faq: Second question | Second answer, see [https://example.org/ a link] }}\n" .
			"{{#faq: Third question | Third answer }}\n" .
			"}}"
		);
		$xpath = $this->xpath( $output->getRawText() );

		$questions = $xpath->query( '//details[@class="faq-item"]/summary[@class="faq-summary"]/b' );
		$this->assertSame( 3, $questions->length, $output->getRawText() );
		$this->assertSame( 0, $xpath->query( '//summary[@class="faq-summary"]//p' )->length,
			$output->getRawText() );
		$this->assertSame( 'First question', $questions->item( 0 )->textContent );
		$this->assertSame( 'Third question', $questions->item( 2 )->textContent );

		// The structured-data text of the questions is unchanged.
		$data = $output->getExtensionData( Hooks::DATA_KEY );
		$this->assertSame( [ 'First question', 'Second question', 'Third question' ],
			array_column( $data, 'question' ) );
	}
}
