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
 * Runs {{#faq_group:}} and {{#faq:}} through the real parser, so the group
 * body is checked as the page would actually render it.
 *
 * In the Database group because a full parse looks the page up (content model,
 * page language); nothing is written.
 *
 * @group Database
 * @covers \MediaWiki\Extension\FAQElement\Hooks
 */
class FAQGroupParserTest extends MediaWikiIntegrationTestCase {

	private function parse( string $wikitext ): ParserOutput {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		return $parser->parse(
			$wikitext,
			PageReferenceValue::localReference( NS_MAIN, 'FAQElement_parser_test' ),
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

	public static function provideMarkupInGroupBody(): array {
		return [
			'tag the sanitiser does not allow' => [ '<img src=x onerror=y>', '//img' ],
			'script element' => [ '<script>y()</script>', '//script' ],
			'event handler on an allowed tag' => [ '<span onmouseover=y>x</span>', '//*[@onmouseover]' ],
		];
	}

	/**
	 * @dataProvider provideMarkupInGroupBody
	 */
	public function testGroupBodyIsSanitisedLikePageWikitext( string $markup, string $forbidden ): void {
		$html = $this->parse( "{{#faq_group:t|$markup}}" )->getRawText();
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//div[@class="faq-group-content"]' )->length,
			'the group still renders' );
		$this->assertSame( 0, $xpath->query( $forbidden )->length, $html );
		$this->assertSame( 0, $xpath->query( '//*[@onerror]' )->length, $html );
	}

	public function testDisallowedTagInGroupBodyIsShownAsText(): void {
		$html = $this->parse( '{{#faq_group:t|<img src=x onerror=y>}}' )->getRawText();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	public function testChildItemsStillRenderInsideTheGroup(): void {
		// The shape the wrapper templates produce: items one per line, the
		// first after a leading space, an answer carrying a link.
		$output = $this->parse(
			"{{#faq_group: Group title\n" .
			"| {{#faq: First question | First answer }}\n" .
			"{{#faq: Second question | Second answer, see [https://example.org/ a link] }}\n" .
			"}}"
		);
		$xpath = $this->xpath( $output->getRawText() );

		$items = $xpath->query(
			'//details[@class="faq-group"]/div[@class="faq-group-content"]//details[@class="faq-item"]'
		);
		$this->assertSame( 2, $items->length, $output->getRawText() );
		$this->assertStringContainsString( 'First question',
			$xpath->query( './/summary', $items->item( 0 ) )->item( 0 )->textContent );
		$this->assertStringContainsString( 'Second question',
			$xpath->query( './/summary', $items->item( 1 ) )->item( 0 )->textContent );
		$this->assertSame( 1, $xpath->query(
			'//div[@class="faq-group-content"]//div[@class="faq-content"]//a[contains(@class, "external")]'
		)->length, 'wikitext inside an answer still renders' );
		$this->assertSame( 'Group title',
			$xpath->query( '//summary[@class="faq-group-title"]' )->item( 0 )->textContent );

		// Parsing the group body must not record the children a second time.
		$data = $output->getExtensionData( Hooks::DATA_KEY );
		$this->assertCount( 2, $data );
		$this->assertSame( 'First question', $data[0]['question'] );
		$this->assertSame( 'Second question', $data[1]['question'] );
	}

	public function testWikitextInGroupBodyIsParsed(): void {
		$html = $this->parse( "{{#faq_group:G|'''Intro''' {{#faq:Q|A}}}}" )->getRawText();
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//div[@class="faq-group-content"]//b[. = "Intro"]' )->length, $html );
		$this->assertSame( 1,
			$xpath->query( '//div[@class="faq-group-content"]//details[@class="faq-item"]' )->length );
	}

	public function testOpenParameterStillApplies(): void {
		$html = $this->parse( '{{#faq_group:G|{{#faq:Q|A}}|open=yes}}' )->getRawText();
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//details[@class="faq-group"][@open]' )->length, $html );
		$this->assertStringNotContainsString( 'open=yes', $html );
	}
}
