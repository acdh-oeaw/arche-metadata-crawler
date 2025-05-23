<?php

/*
 * The MIT License
 *
 * Copyright 2023 Austrian Centre for Digital Humanities.
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
use rdfInterface\TermInterface;
use rdfInterface\QuadInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdfIo\Util as RdfIoUtil;
use quickRdfIo\RdfIoException;

/**
 * Description of MetadataRdf
 *
 * @author zozlak
 * 
 * @implements IteratorAggregate<QuadInterface>
 */
class MetadataRdf implements IteratorAggregate {

    private Dataset $meta;
    private LoggerInterface | null $log;

    public function __construct(string $path, LoggerInterface | null $log = null) {
        $this->log = $log;

        $this->log?->debug("Trying to map $path as an RDF file");
        $this->meta = new Dataset();
        try {
            $this->meta->add(RdfIoUtil::parse($path, new DF()));
            $this->log?->info("Reading $path as an RDF file");
            $this->log?->info("\t" . count($this->meta) . " triples read");
        } catch (RdfIoException $ex) {
            $this->log?->debug("\tFailed to parse $path as and RDF file");
        }
        // Commented out as it is generating triples like
        //   acdh:Resource ID acdh:Resource
        // or
        //   owl:Thing ID acdh:Resource
        // which are very problematic.
        // What was the use case?
        //foreach ($this->meta->listSubjects() as $sbj) {
        //    $this->meta->add(DF::quad($sbj, $idProp, $sbj));
        //}
    }

    public function getIterator(): Traversable {
        return $this->meta->getIterator();
    }
}
