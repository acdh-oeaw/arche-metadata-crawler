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

use acdhOeaw\arche\metadataCrawler\EntityListWorksheet;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdfIo\Util as RdfIoUtil;

/**
 * Description of NamedEntitiesTest
 *
 * @author zozlak
 */
class EntityListWorksheetTest extends TestBase {

    public function testIdsLabels(): void {
        $nn = new EntityListWorksheet(__DIR__ . '/data/EntityListWorksheetIdsLabels.xlsx', self::$ontology, self::$schema, self::DEFAULT_LANG);
        $d  = new Dataset();
        $n  = 0;
        foreach ($nn->readEntities() as $i) {
            $d->add($i);
            $n++;
        }
        $ref = new Dataset();
        $ref->add(RdfIoUtil::parse(__DIR__ . '/data/EntityListWorksheetIdsLabels.ttl', new DF()));
        $this->assertEquals("", (string) $ref->xor($d));
        $this->assertEquals(8, $n);
    }
}
