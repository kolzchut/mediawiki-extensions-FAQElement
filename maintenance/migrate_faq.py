#!/usr/bin/env python3
"""
migrate_faq.py - Migrate {{שאלה ותשובה}} to either:
  - Parser functions:    {{#faq:}} / {{#faq_group:}}
  - Wrapper templates:   {{שאלה ותשובה}} / {{קבוצת שאלות ותשובות}}

Usage:
    pwb migrate_faq [-mode:parser|template] [-always]

Options:
    -mode:parser    Replace with {{#faq:}} / {{#faq_group:}}  (default)
    -mode:template  Replace with {{שאלה ותשובה}} / {{קבוצת שאלות ותשובות}}
    -always         Don't prompt, apply all changes automatically
"""

import sys
import mwparserfromhell
import pywikibot
from pywikibot import pagegenerators
from pywikibot.bot import ExistingPageBot, SingleSiteBot

OLD_TEMPLATE = 'שאלה ותשובה'

# ---------------------------------------------------------------------------
# Conversion
# ---------------------------------------------------------------------------

def make_faq_item( question, answer, mode ):
	if mode == 'template':
		return '{{שאלה ותשובה\n| ' + question + '\n| ' + answer + '\n}}'
	else:
		return '{{#faq: ' + question + ' | ' + answer + '}}'


def make_faq_group( title, inner, mode ):
	if mode == 'template':
		return '{{קבוצת שאלות ותשובות\n| ' + title + '\n| ' + inner.strip() + '\n}}'
	else:
		return '{{#faq_group: ' + title + '\n| ' + inner.strip() + '\n}}'


def is_group( param2_value ):
	"""Return True if param2 consists entirely of nested {{OLD_TEMPLATE}} calls
	(possibly with whitespace) — meaning this is a group wrapper, not a Q&A.
	"""
	nested = param2_value.filter_templates( matches=OLD_TEMPLATE )
	return bool( nested ) and not param2_value.strip_code().strip()


def convert_all_calls( text, mode ):
	wikicode = mwparserfromhell.parse( text )
	templates = wikicode.filter_templates( matches=OLD_TEMPLATE, recursive=True )

	# Classify all calls up front, before any replacement mutates the tree
	groups = set()
	for template in templates:
		if template.has( 2 ):
			if is_group( template.get( 2 ).value ):
				groups.add( id( template ) )

	# Replace deepest-first so inner items are already converted
	# when we reach their parent group
	for template in reversed( templates ):
		if not template.has( 1 ) or not template.has( 2 ):
			continue  # malformed, leave untouched

		param1 = str( template.get( 1 ).value ).strip()
		param2 = str( template.get( 2 ).value ).strip()

		if id( template ) in groups:
			replacement = make_faq_group( param1, param2, mode )
		else:
			replacement = make_faq_item( param1, param2, mode )

		wikicode.replace( template, replacement )

	return str( wikicode )


# ---------------------------------------------------------------------------
# Bot class
# ---------------------------------------------------------------------------

class MigrateFAQBot( SingleSiteBot, ExistingPageBot ):

	def __init__( self, **kwargs ):
		self.available_options.update( { 'mode': 'parser' } )
		super().__init__( **kwargs )
	def treat( self, page ):
		original = page.text
		converted = convert_all_calls( original, self.opt.mode )

		if converted == original:
			pywikibot.info( 'No changes: %s' % page.title() )
			return

		self.current_page = page
		pywikibot.showDiff( original, converted )

		summary = (
			'המרת תבנית שאלה ותשובה ל-#faq / #faq_group'
			if self.opt.mode == 'parser'
			else 'המרת תבנית שאלה ותשובה לתבניות שאלה ותשובה / קבוצת שאלות ותשובות'
		)
		self.userPut( page, original, converted, summary=summary, minor=False )


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main( *args ):
	options = {}
	gen_factory = pagegenerators.GeneratorFactory()

	for arg in pywikibot.handle_args( args ):
		if arg.startswith( '-mode:' ):
			mode = arg[ len( '-mode:' ): ].strip()
			if mode not in ( 'parser', 'template' ):
				pywikibot.error( 'Unknown mode "%s". Use parser or template.' % mode )
				sys.exit( 1 )
			options[ 'mode' ] = mode
		elif arg == '-always':
			options[ 'always' ] = True
		else:
			gen_factory.handle_arg( arg )

	site = pywikibot.Site()

	gen = gen_factory.getCombinedGenerator()
	if gen is None:
		# Default: all pages transcluding the old template
		template_page = pywikibot.Page( site, 'Template:' + OLD_TEMPLATE )
		gen = template_page.embeddedin( filter_redirects=False, namespaces=[ 0 ] )

	bot = MigrateFAQBot( generator=gen, site=site, **options )
	bot.run()


if __name__ == '__main__':
	main()
