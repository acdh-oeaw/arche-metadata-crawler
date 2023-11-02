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
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of MetadataHorizontal
 *
 * @author zozlak
 */
class MetadataHorizontal implements IteratorAggregate {

    private Dataset $meta;
    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface | null $log;

    public function __construct(string $path, Ontology $ontology,
                                Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;
        $this->meta     = new Dataset();
    }

    public function getIterator(): Traversable {
        return $this->meta->getIterator();
    }

    private function foo(): void {

        $predicateCol  = $valueStartCol = null;
        for ($row = 1; $row <= 20; $row++) {
            for ($col = 1; $col < 100; $col++) {
                $val = mb_strtolower(trim($sheet->getCell([$col, $row])->getValue()));
                $val = str_replace([' ', '_', '-'], '', $val);
                if ($val === 'machinename') {
                    $predicateCol = $col;
                } elseif ($val === 'value1') {
                    $valueStartCol = $col;
                }
            }
            if ($predicateCol !== null && $valueStartCol !== null) {
                break;
            }
        }
        if ($predicateCol === null || $valueStartCol === null) {
            throw new RuntimeException('unrecognized format');
        }
        $rowMax = $sheet->getHighestDataRow();
        for ($row = $row + 1; $row <= $rowMax; $row++) {
            $predicate = trim($sheet->getCell([$predicateCol, $row])->getValue());
            if (empty($predicate)) {
                continue;
            }
            $colMax = PhpOffice\PhpSpreadsheet\Cell\Coordinate::indexesFromString($sheet->getHighestDataColumn($row) . '1')[0];
            for ($col = $valueStartCol; $col <= $colMax; $col++) {
                $value = trim($sheet->getCell([$col, $row])->getValue());
                "$predicate $value\n";
                if (!empty($value)) {
                    echo "$predicate $value\n";
                }
            }
        }
    }
}
