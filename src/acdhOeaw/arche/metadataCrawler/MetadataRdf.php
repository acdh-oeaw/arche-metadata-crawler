<?php

/*
 * The MIT License
 *
 * Copyright 2023 zozlak.
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

namespace acdhOeaw\arche\metadataCrawler;

use IteratorAggregate;
use Traversable;
use Psr\Log\LoggerInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdfIo\Util as RdfIoUtil;
use quickRdfIo\RdfIoException;

/**
 * Description of MetadataRdf
 *
 * @author zozlak
 */
class MetadataRdf implements IteratorAggregate {

    private Dataset $meta;
    private LoggerInterface | null $log;

    public function __construct(string $path, LoggerInterface | null $log = null) {
        $this->log = $log;

        $this->log?->info("Reading metadat from $path");
        $this->meta = new Dataset();
        try {
            $this->meta->add(RdfIoUtil::parse($path, new DF()));
            $this->log?->info("\t" . count($this->meta) . " triples read");
        } catch (RdfIoException $ex) {
            
        }
    }

    public function getIterator(): Traversable {
        return $this->meta->getIterator();
    }
}
