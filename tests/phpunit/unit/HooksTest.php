<?php

namespace MediaWiki\Extension\FAQElement\Tests\Unit;

use HashConfig;
use MediaWiki\Extension\FAQElement\Hooks;
use MediaWikiUnitTestCase;
use OutputPage;
use Parser;
use ParserOutput;

/**
 * @covers \MediaWiki\Extension\FAQElement\Hooks
 */
class HooksTest extends MediaWikiUnitTestCase {

	private Parser $parser;
	private ParserOutput $parserOutput;

	protected function setUp(): void {
		parent::setUp();
		$this->parserOutput = new ParserOutput();
		$this->parser = $this->createMock( Parser::class );
		$this->parser->method( 'getOutput' )->willReturn( $this->parserOutput );
		// Return wikitext unchanged so tests can match on the original strings
		$this->parser->method( 'recursiveTagParseFully' )->willReturnArgument( 0 );
	}

	// ── renderFAQItem ──────────────────────────────────────────────────────────

	public function testFAQItemRendersDetailsElement(): void {
		[ $html ] = Hooks::renderFAQItem( $this->parser, 'What is foo?', 'Foo is bar.' );

		$this->assertStringContainsString( '<details class="faq-item">', $html );
		$this->assertStringContainsString( '<summary class="faq-summary"><b>', $html );
		$this->assertStringContainsString( 'What is foo?', $html );
		$this->assertStringContainsString( '<div class="faq-content">', $html );
		$this->assertStringContainsString( 'Foo is bar.', $html );
		$this->assertStringContainsString( '</details>', $html );
	}

	public function testFAQItemReturnedWithHtmlFlags(): void {
		$result = Hooks::renderFAQItem( $this->parser, 'Q', 'A' );

		$this->assertTrue( $result['noparse'] );
		$this->assertTrue( $result['isHTML'] );
	}

	public function testFAQItemAccumulatesMultipleItems(): void {
		Hooks::renderFAQItem( $this->parser, 'Q1', 'A1' );
		Hooks::renderFAQItem( $this->parser, 'Q2', 'A2' );

		$items = $this->parserOutput->getExtensionData( 'faqelement_items' );
		$this->assertCount( 2, $items );
		$this->assertSame( 'Q1', $items[0]['question'] );
		$this->assertSame( 'A1', $items[0]['answer'] );
		$this->assertSame( 'Q2', $items[1]['question'] );
		$this->assertSame( 'A2', $items[1]['answer'] );
	}

	public function testFAQItemStripsHtmlTagsInStoredData(): void {
		Hooks::renderFAQItem( $this->parser, '<b>Bold Q</b>', '<p>Para A</p>' );

		$items = $this->parserOutput->getExtensionData( 'faqelement_items' );
		$this->assertSame( 'Bold Q', $items[0]['question'] );
		$this->assertSame( 'Para A', $items[0]['answer'] );
	}

	public function testFAQItemTrimsWhitespaceBeforeAccumulating(): void {
		Hooks::renderFAQItem( $this->parser, '  Q  ', '  A  ' );

		$items = $this->parserOutput->getExtensionData( 'faqelement_items' );
		$this->assertSame( 'Q', $items[0]['question'] );
		$this->assertSame( 'A', $items[0]['answer'] );
	}

	// ── renderFAQGroup ─────────────────────────────────────────────────────────

	public function testFAQGroupRendersStructure(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'My FAQ', '<item/>' );

		$this->assertStringContainsString( '<details class="faq-group">', $html );
		$this->assertStringContainsString( '<summary class="faq-group-title"><b>My FAQ</b></summary>', $html );
		$this->assertStringContainsString( '<div class="faq-group-content"><item/></div>', $html );
	}

	public function testFAQGroupReturnedWithHtmlFlags(): void {
		$result = Hooks::renderFAQGroup( $this->parser, 'G', '' );

		$this->assertTrue( $result['noparse'] );
		$this->assertTrue( $result['isHTML'] );
	}

	public function testFAQGroupDefaultIsClosed(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>' );

		$this->assertStringContainsString( '<details class="faq-group">', $html );
		$this->assertStringNotContainsString( ' open>', $html );
	}

	public function testFAQGroupOpenYes(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>', 'open=yes' );

		$this->assertStringContainsString( '<details class="faq-group" open>', $html );
	}

	public function testFAQGroupOpen1(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>', 'open=1' );

		$this->assertStringContainsString( '<details class="faq-group" open>', $html );
	}

	public function testFAQGroupOpenTrue(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>', 'open=true' );

		$this->assertStringContainsString( '<details class="faq-group" open>', $html );
	}

	public function testFAQGroupOpenNo(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>', 'open=no' );

		$this->assertStringContainsString( '<details class="faq-group">', $html );
		$this->assertStringNotContainsString( ' open>', $html );
	}

	public function testFAQGroupOpenIsCaseInsensitive(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', '<item/>', 'OPEN=YES' );

		$this->assertStringContainsString( '<details class="faq-group" open>', $html );
	}

	public function testFAQGroupTitleIsHtmlEscaped(): void {
		[ $html ] = Hooks::renderFAQGroup( $this->parser, '<b>Title</b>', '' );

		$this->assertStringNotContainsString( '<b>Title</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Title&lt;/b&gt;', $html );
	}

	public function testFAQGroupMultipleFAQsAsSeparateArgs(): void {
		$faq1 = '<details class="faq-item">Item1</details>';
		$faq2 = '<details class="faq-item">Item2</details>';
		$faq3 = '<details class="faq-item">Item3</details>';

		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', $faq1, $faq2, $faq3 );

		$this->assertStringContainsString( $faq1, $html );
		$this->assertStringContainsString( $faq2, $html );
		$this->assertStringContainsString( $faq3, $html );
	}

	public function testFAQGroupMultipleFAQsAsSeparateArgsWithOpen(): void {
		$faq1 = '<details class="faq-item">Item1</details>';
		$faq2 = '<details class="faq-item">Item2</details>';

		[ $html ] = Hooks::renderFAQGroup( $this->parser, 'Group', $faq1, $faq2, 'open=yes' );

		$this->assertStringContainsString( '<details class="faq-group" open>', $html );
		$this->assertStringContainsString( $faq1, $html );
		$this->assertStringContainsString( $faq2, $html );
	}

	// ── onOutputPageParserOutput ───────────────────────────────────────────────

	public function testOutputPageDoesNothingWithNoItems(): void {
		$hooks = new Hooks( new HashConfig( [ 'FAQElementOutputSchema' => true ] ) );
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->never() )->method( 'addModuleStyles' );
		$out->expects( $this->never() )->method( 'addHeadItem' );

		$hooks->onOutputPageParserOutput( $out, $this->parserOutput );
	}

	public function testOutputPageAddsStylesAndJsonLd(): void {
		$hooks = new Hooks( new HashConfig( [ 'FAQElementOutputSchema' => true ] ) );
		Hooks::renderFAQItem( $this->parser, 'What is foo?', 'Foo is bar.' );

		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )
			->method( 'addModuleStyles' )
			->with( 'ext.faqelement.styles' );

		$headItems = [];
		$out->method( 'addHeadItem' )
			->willReturnCallback( static function ( $name, $content ) use ( &$headItems ) {
				$headItems[$name] = $content;
			} );

		$hooks->onOutputPageParserOutput( $out, $this->parserOutput );

		$this->assertArrayHasKey( 'faqelement-jsonld', $headItems );
		$jsonld = $headItems['faqelement-jsonld'];
		$this->assertStringContainsString( '<script type="application/ld+json">', $jsonld );
		$this->assertStringContainsString( 'FAQPage', $jsonld );
		$this->assertStringContainsString( 'What is foo?', $jsonld );
		$this->assertStringContainsString( 'Foo is bar.', $jsonld );
	}

	public function testOutputPageAddsStylesButNoSchemaWhenDisabled(): void {
		$hooks = new Hooks( new HashConfig( [ 'FAQElementOutputSchema' => false ] ) );
		Hooks::renderFAQItem( $this->parser, 'Q', 'A' );

		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )->method( 'addModuleStyles' );
		$out->expects( $this->never() )->method( 'addHeadItem' );

		$hooks->onOutputPageParserOutput( $out, $this->parserOutput );
	}

	public function testJsonLdEscapesAngleBrackets(): void {
		$hooks = new Hooks( new HashConfig( [ 'FAQElementOutputSchema' => true ] ) );

		// Inject stored data directly to test JSON encoding independently of the parser pipeline
		$this->parserOutput->setExtensionData( 'faqelement_items', [
			[ 'question' => 'What about </script> injection?', 'answer' => 'Very dangerous.' ],
		] );

		$out = $this->createMock( OutputPage::class );
		$headItems = [];
		$out->method( 'addHeadItem' )
			->willReturnCallback( static function ( $name, $content ) use ( &$headItems ) {
				$headItems[$name] = $content;
			} );

		$hooks->onOutputPageParserOutput( $out, $this->parserOutput );

		// JSON_HEX_TAG encodes < as \u003C and > as \u003E (literal backslash-u sequences),
		// preventing </script> in data from terminating the enclosing <script> block.
		$this->assertStringContainsString( '\u003C/script\u003E', $headItems['faqelement-jsonld'] );
	}
}
