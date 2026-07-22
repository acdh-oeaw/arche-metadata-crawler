<?php

/*
 * The MIT License
 *
 * Copyright 2026 zozlak.
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

use Generator;
use termTemplates\QuadTemplate as QT;
use rdfInterface\QuadInterface;
use rdfInterface\TermIteratorInterface;
use rdfInterface\QuadIteratorInterface;
use rdfInterface\DatasetInterface;

/**
 * Class used to provide a transparent sorting of a dataset by subject
 *
 * @author zozlak
 * @implements \Iterator<int, QuadInterface>
 */
class SortedDataset implements \Iterator {

    private int $n;
    private TermIteratorInterface $sbjIter;
    private QuadIteratorInterface $quadIter;

    public function __construct(private DatasetInterface $data) {
        $this->rewind();
    }

    public function current(): QuadInterface | null {
        return $this->quadIter->current();
    }

    public function key(): int {
        return $this->n;
    }

    public function next(): void {
        $this->quadIter->next();
        $quad = $this->quadIter->current();
        if ($quad === null) {
            $this->sbjIter->next();
            $sbj = $this->sbjIter->current();
            if ($sbj !== null) {
                $this->quadIter = $this->data->getIterator(new QT($sbj));
            }
        }
        $this->n++;
    }

    public function rewind(): void {
        $this->n       = -1;
        $this->sbjIter = $this->data->listSubjects();
        $sbj           = $this->sbjIter->current();
        if ($sbj !== null) {
            $this->quadIter = $this->data->getIterator(new QT($sbj));
        }
    }

    public function valid(): bool {
        return $this->sbjIter->valid() && $this->quadIter->valid();
    }
}
