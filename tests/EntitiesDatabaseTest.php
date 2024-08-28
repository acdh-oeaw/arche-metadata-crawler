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
use acdhOeaw\arche\metadataCrawler\EntitiesDatabase;
use quickRdf\DatasetNode;
use zozlak\logging\Log;

/**
 * Description of EntitiesDatabaseTest
 *
 * @author zozlak
 */
class EntitiesDatabaseTest extends TestBase {

    public function testLangLabel(): void {
        $l   = new Log(self::LOG_FILE);
        $elw = new EntityListWorksheet(__DIR__ . '/data/EntityListWorksheetIdsLabels.xlsx', self::$ontology, self::$schema, self::DEFAULT_LANG);
        $ed  = new EntitiesDatabase(self::$ontology, self::$schema, $l);
        $ed->add($elw->readEntities());
        $this->assertTrue($ed->exists('place 1'));
        $this->assertTrue($ed->exists('place 4'));
        $this->assertTrue($ed->exists('Platz 4'));
        $this->assertEquals('', file_get_contents(self::LOG_FILE));
    }
}
