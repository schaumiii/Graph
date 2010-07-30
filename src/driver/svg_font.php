<?php
/**
 * File containing the ezcGraphSVGDriver class
 *
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 * 
 *   http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 *
 * @package Graph
 * @version //autogentag//
 * @copyright Copyright (C) 2005-2010 eZ Systems AS. All rights reserved.
 * @author Freddie Witherden
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Helper class, offering requrired calculation basics and font metrics to use
 * SVG fonts with the SVG driver.
 *
 * You may convert any ttf font into a SVG font using the `ttf2svg` bianry from
 * the batik package. Depending on the distribution it may only be available as
 * `batik-ttf2svg-<version>`.
 *
 * Usage:
 * <code>
 *  $font = new ezcGraphSvgFont();
 *  var_dump(
 *      $font->calculateStringWidth( '../tests/data/font.svg', 'Just a test string.' ),
 *      $font->calculateStringWidth( '../tests/data/font2.svg', 'Just a test string.' )
 *  );
 * </code>
 *
 * @version //autogentag//
 * @package Graph
 * @mainclass
 */
class ezcGraphSvgFont
{
    /**
     * Units per EM
     *
     * @var float
     */
    protected $unitsPerEm;

    /**
     * Used glyphs
     *
     * @var array
     */
    protected $usedGlyphs = array();

    /**
     * Cache for glyph size to save XPath lookups.
     * 
     * @var array
     */
    protected $glyphCache = array();

    /**
     * Used kernings
     *
     * @var array
     */
    protected $usedKerns = array();

    /**
     * Path to font
     *
     * @var string
     */
    protected $fonts = array();

    /**
     * Initialize SVG font
     *
     * Loads the SVG font $filename. This should be the path to the file
     * generated by ttf2svg.
     *
     * Returns the (normlized) name of the initilized font.
     *
     * @param string $fontPath
     * @return string
     */
    protected function initializeFont( $fontPath )
    {
        if ( isset( $this->fonts[$fontPath] ) )
        {
            return $fontPath;
        }

        // Check for existance of font file
        if ( !is_file( $fontPath ) || !is_readable( $fontPath ) )
        {
            throw new ezcBaseFileNotFoundException( $fontPath );
        }

        $this->fonts[$fontPath] = simplexml_load_file( $fontPath )->defs->font;

        // SimpleXML requires us to register a namespace for XPath to work
        $this->fonts[$fontPath]->registerXPathNamespace( 'svg', 'http://www.w3.org/2000/svg' );

        // Extract the number of units per Em
        $this->unitsPerEm[$fontPath] = (int) $this->fonts[$fontPath]->{'font-face'}['units-per-em'];

        return $fontPath;
    }

    /**
     * Get name of font
     *
     * Get the name of the given font, by extracting its font family from the
     * SVG font file.
     * 
     * @param string $fontPath 
     * @return string
     */
    public static function getFontName( $fontPath )
    {
        $font = simplexml_load_file( $fontPath )->defs->font;

        // SimpleXML requires us to register a namespace for XPath to work
        $font->registerXPathNamespace( 'svg', 'http://www.w3.org/2000/svg' );

        // Extract the font family name
        return (string) $font->{'font-face'}['font-family'];
    }

    /**
     * XPath has no standard means of escaping ' and ", with the only solution
     * being to delimit your string with the opposite type of quote. ( And if
     * your string contains both concat(  ) it ).
     *
     * This method will correctly delimit $char with the appropriate quote type
     * so that it can be used in an XPath expression.
     *
     * @param string $char
     * @return string
     */
    protected static function xpathEscape( $char )
    {
        return "'" . str_replace( 
            array( '\'', '\\' ),
            array( '\\\'', '\\\\' ),
            $char ) . "'";
    }

    /**
     * Returns the <glyph> associated with $char.
     *
     * @param string $fontPath
     * @param string $char
     * @return float
     */
    protected function getGlyph( $fontPath, $char )
    {
        // Check if glyphwidth has already been calculated.
        if ( isset( $this->glyphCache[$fontPath][$char] ) )
        {
            return $this->glyphCache[$fontPath][$char];
        }

        $matches = $this->fonts[$fontPath]->xpath(
            $query = "glyph[@unicode=" . self::xpathEscape( $char ) . "]"
        );

        if ( count( $matches ) === 0 )
        {
             // Just ignore missing glyphs. The client will still render them
             // using a default font. We try to estimate some width by using a
             // common other character.
            return $this->glyphCache[$fontPath][$char] = 
                ( $char === 'o' ? false : $this->getGlyph( $fontPath, 'o' ) );
        }

        $glyph = $matches[0];
        if ( !in_array( $glyph, $this->usedGlyphs ) )
        {
            $this->usedGlyphs[$fontPath][] = $glyph;
        }

        // There should only ever be one match
        return $this->glyphCache[$fontPath][$char] = $glyph;
    }

    /**
     * Returns the amount of kerning to apply for glyphs $g1 and $g2. If no
     * valid kerning pair can be found 0 is returned.
     *
     * @param string $fontPath
     * @param SimpleXMLElement $g1
     * @param SimpleXMLElement $g2
     * @return int
     */
    public function getKerning( $fontPath, SimpleXMLElement $glyph1, SimpleXMLElement $glyph2 )
    {
        // Get the glyph names
        $g1Name = self::xpathEscape( ( string ) $glyph1['glyph-name'] );
        $g2Name = self::xpathEscape( ( string ) $glyph2['glyph-name'] );

        // Get the unicode character names
        $g1Uni = self::xpathEscape( ( string ) $glyph1['unicode'] );
        $g2Uni = self::xpathEscape( ( string ) $glyph2['unicode'] );

        // Search for kerning pairs
        $pair = $this->fonts[$fontPath]->xpath( 
            "svg:hkern[( @g1=$g1Name and @g2=$g2Name )
                or
             ( @u1=$g1Uni and @g2=$g2Uni )]"
        );

        // If we found anything return it
        if ( count( $pair ) )
        {
            if ( !in_array( $pair[0], $this->usedKerns ) )
            {
                $this->usedKerns[$fontPath][] = $pair[0];
            }

            return ( int ) $pair[0]['k'];
        }
        else
        {
            return 0;
        }
    }

    /**
     * Calculates the width of $string in the current font in Em's.
     *
     * @param string $fontPath
     * @param string $string
     * @return float
     */
    public function calculateStringWidth( $fontPath, $string )
    {
        // Ensure font is properly initilized
        $fontPath = $this->initializeFont( $fontPath );

        $strlen = strlen( $string );
        $prevCharInfo = null;
        $length = 0;
        // @TODO: Add UTF-8 support here - iterating over the bytes does not
        // really help.
        for ( $i = 0; $i < $strlen; ++$i )
        {
            // Find the font information for the character
            $charInfo = $this->getGlyph( $fontPath, $string[$i] );

            // Handle missing glyphs
            if ( $charInfo === false )
            {
                $prevCharInfo = null;
                $length += .5 * $this->unitsPerEm[$fontPath];
                continue;
            }

            // Add the horizontal advance for the character to the length
            $length += (float) $charInfo['horiz-adv-x'];

            // If we are not the first character, look for kerning pairs
            if ( $prevCharInfo !== null )
            {
                // Apply kerning (if any)
                $length -= $this->getKerning( $fontPath, $prevCharInfo, $charInfo );
            }

            $prevCharInfo = clone $charInfo;
        }

        // Divide by _unitsPerEm to get the length in Em
        return (float) $length / $this->unitsPerEm[$fontPath];
    }

    /**
     * Add font definitions to SVG document
     *
     * Add the SVG font definition paths for all used glyphs and kernings to
     * the given SVG document.
     * 
     * @param DOMDocument $document 
     * @return void
     */
    public function addFontToDocument( DOMDocument $document )
    {
        $defs = $document->getElementsByTagName( 'defs' )->item( 0 );

        $fontNr = 0;
        foreach ( $this->fonts as $path => $definition )
        {
            // Just import complete font for now.
            // @TODO: Only import used characters.
            $font = dom_import_simplexml( $definition );
            $font = $document->importNode( $font, true );
            $font->setAttribute( 'id', 'Font' . ++$fontNr );

            $defs->appendChild( $font );
        }
    }
}

?>
