<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\metadataCrawler\tests;

use acdhOeaw\arche\metadataCrawler\MetadataChecker;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use zozlak\RdfConstants as RDF;
use zozlak\logging\Log;

/**
 * Description of MetadataCheckerTest
 *
 * @author zozlak
 */
class MetadataCheckerTest extends TestBase {

    /**
     * Checks it the MetadataChecker allows to use as a triple object an unresolvable
     * URI of an acdh:Organization existing in the dataset being checked with only
     * type, id and title (which already makes a valid Organisation)
     */
    public function testMinimalOrganizationAsObject(): void {
        $lProp = DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasLicensor');
        $o     = DF::namedNode('https://id.acdh.oeaw.ac.at/testOrganisation');
        $s     = DF::namedNode('https://id.acdh.oeaw.ac.at/testResource');

        $d = new Dataset();
        $d->add(DF::quad($o, DF::namedNode(RDF::RDF_TYPE), self::$schema->classes->organisation));
        $d->add(DF::quad($o, self::$schema->id, $o));
        $d->add(DF::quad($o, self::$schema->label, DF::literal('test organisation', 'en')));
        $d->add(DF::quad($s, DF::namedNode(RDF::RDF_TYPE), self::$schema->classes->resource));
        $d->add(DF::quad($s, $lProp, $o));

        $mc  = new MetadataChecker(self::$ontology, self::$schema, new Log(self::LOG_FILE));
        $mc->check($d);
        $log = file_get_contents(self::LOG_FILE);
        $this->assertStringNotContainsString('Failed to fetch RDF data', $log);

        $badO = DF::namedNode('https://id.acdh.oeaw.ac.at/notDefined');
        $d    = new Dataset();
        $d->add(DF::quad($s, DF::namedNode(RDF::RDF_TYPE), self::$schema->classes->resource));
        $d->add(DF::quad($s, $lProp, $badO));
        $mc->check($d);
        $log  = file_get_contents(self::LOG_FILE);
        $this->assertStringContainsString("Failed to fetch RDF data from $badO", $log);
    }
}
