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

use acdhOeaw\arche\metadataCrawler\MetadataHorizontal;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;

/**
 * Description of MetadataSpreadsheetTraitTest
 *
 * @author zozlak
 */
class MetadataSpreadsheetTraitTest extends TestBase {

    public function testDatesRange(): void {
        $idPrefix = 'https://id.acdh.oeaw.ac.at/GustavMahlerArchiv';
        $tmpl1    = new QT(DF::namedNode($idPrefix), DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasCoverageStartDate'));
        $tmpl2    = new QT(DF::namedNode($idPrefix), DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasCoverageEndDate'));

        $mh = new MetadataHorizontal(__DIR__ . '/data/datesExcel.xlsx', self::$ontology, self::$schema, $idPrefix, self::DEFAULT_LANG);
        $d  = new Dataset();
        $d->add($mh->getIterator());
        $this->assertEquals('1960-02-01', $d->getObjectValue($tmpl1));
        $this->assertEquals('1890-01-01', $d->getObjectValue($tmpl2));

        $mh = new MetadataHorizontal(__DIR__ . '/data/datesLibreoffice.xlsx', self::$ontology, self::$schema, $idPrefix, self::DEFAULT_LANG);
        $d  = new Dataset();
        $d->add($mh->getIterator());
        $this->assertEquals('1860-02-01', $d->getObjectValue($tmpl1));
        $v2 = $d->listObjects($tmpl2)->getValues();
        sort($v2);
        $this->assertEquals(['1230-05-06', '1890-01-01'], $v2);
    }
}
