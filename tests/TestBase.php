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

use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of TestBase
 *
 * @author zozlak
 */
class TestBase extends \PHPUnit\Framework\TestCase {

    const REPOSITORY_URL = 'https://arche.acdh.oeaw.ac.at/api';
    const DEFAULT_LANG   = 'und';
    const LOG_FILE       = __DIR__ . '/data/log';

    static protected Ontology $ontology;
    static protected Schema $schema;

    static public function setUpBeforeClass(): void {
        $repo           = Repo::factoryFromUrl(self::REPOSITORY_URL);
        self::$schema   = $repo->getSchema();
        self::$ontology = Ontology::factoryRest(self::REPOSITORY_URL, __DIR__ . '/ontology.cache', 36000);
    }

    public function setUp(): void {
        parent::setUp();

        fclose(fopen(self::LOG_FILE, 'w'));
    }

    public function tearDown(): void {
        parent::tearDown();

        if (file_exists(self::LOG_FILE)) {
            unlink(self::LOG_FILE);
        }
    }
}
