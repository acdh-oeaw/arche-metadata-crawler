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

use ArrayIterator;
use IteratorAggregate;
use Traversable;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\ColumnCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use zozlak\RdfConstants as RDF;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdf\DatasetNode;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\ingest\util\FileId;

/**
 * Description of MetadataVertical
 *
 * @author zozlak
 */
class MetadataVertical implements IteratorAggregate {

    private const HEADER_ROW_MAX  = 10;
    private const COLUMN_PATH     = 'path';
    private const COLUMN_DIR      = 'directory';
    private const COLUMN_FILENAME = 'filename';

    private Ontology $ontology;
    private Schema $schema;
    private FileId $idgen;
    private LoggerInterface | null $log;
    private Dataset $meta;

    /**
     * 
     * @var array<string, array<string, string|PropertyDesc>>
     */
    private array $mapping;
    private int $firstRow;

    public function __construct(string $path, Ontology $ontology,
                                Schema $schema, string $idPrefix,
                                LoggerInterface | null $log = null) {
        if (substr($idPrefix, -1) !== '/') {
            $idPrefix .= '/';
        }
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->idgen    = new FileId($idPrefix);
        $this->log      = $log;
        $this->meta     = new Dataset();

        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getSheet(0);
        $this->log?->info("Mapping $path as vertical metadata file");
        $this->mapStructure($sheet);
        if (isset($this->mapping)) {
            $this->readMetadata($sheet);
        }
    }

    public function getIterator(): Traversable {
        return $this->meta;
    }

    private function mapStructure(Worksheet $sheet): void {
        $nmsp   = $this->ontology->getNamespace();
        $colMax = $sheet->getHighestDataColumn();
        for ($headerRow = 1; $headerRow <= self::HEADER_ROW_MAX; $headerRow++) {
            $mapping = [
                self::COLUMN_PATH     => null,
                self::COLUMN_DIR      => null,
                self::COLUMN_FILENAME => null,
            ];
            foreach (new RowCellIterator($sheet, $headerRow, 'A', $colMax) as $cell) {
                /* @var $cell Cell */
                $col = $cell->getColumn();
                $v   = mb_strtolower($cell->getValue());
                if (array_key_exists($v, $mapping)) {
                    $mapping[$v] = $col;
                    continue;
                }
                $v = $cell->getValue();
                if (!empty($v)) {
                    if (!str_starts_with($v, $nmsp)) {
                        $v = $nmsp . $v;
                    }
                    $property = $this->ontology->getProperty(null, $v);
                    if ($property !== null) {
                        $mapping[$property->uri] = ['column' => $col, 'description' => $property];
                    }
                }
            }
            if (($mapping[self::COLUMN_PATH] !== null || $mapping[self::COLUMN_DIR] !== null && $mapping[self::COLUMN_FILENAME] !== null) && count($mapping) > 3) {
                break;
            }
        }
        if ($headerRow <= self::HEADER_ROW_MAX) {
            $this->mapping  = $mapping;
            $this->firstRow = $headerRow + 1;
        }
    }

    private function readMetadata(Worksheet $sheet): void {
        $map      = $this->mapping;
        unset($map[self::COLUMN_PATH]);
        unset($map[self::COLUMN_DIR]);
        unset($map[self::COLUMN_FILENAME]);
        $rowMax   = $sheet->getHighestDataRow();
        $prevPath = '';
        $prevDir  = '';
        $sbj      = null;
        $n = 0;
        for ($row = $this->firstRow; $row <= $rowMax; $row++) {
            $path = $this->getPaths($sheet, $row, $prevDir);
            if (empty($path)) {
                $path = $prevPath;
            } elseif ($path !== $prevPath) {
                if (!empty($path)) {
                    $sbj = DF::namedNode($this->idgen->getId($path));
                    $n++;
                }
                $prevPath = $path;
            }
            if (!empty($path)) {
                foreach ($map as $property => $i) {
                    $value = trim($sheet->getCell($i['column'] . $row)->getValue());
                    if (!empty($value)) {
                        $value = $i['description']->type === RDF::OWL_DATATYPE_PROPERTY ? DF::literal($value) : DF::namedNode($value);
                        $this->meta->add(DF::quad($sbj, DF::namedNode($property), $value));
                    }
                }
            }
        }
        $this->log?->info("\tMetadata of $n files read");
    }

    private function getPaths(Worksheet $sheet, int $row, string &$prevDir): string {
        $path1 = $path2 = '';
        if (isset($this->mapping[self::COLUMN_PATH])) {
            $path1 = trim($sheet->getCell($this->mapping[self::COLUMN_PATH] . $row)->getValue());
        }
        if (isset($this->mapping[self::COLUMN_FILENAME]) && isset($this->mapping[self::COLUMN_DIR])) {
            $dir = trim((string) $sheet->getCell($this->mapping[self::COLUMN_DIR] . $row)->getValue());
            if (empty($dir)) {
                $dir = $prevDir;
            } else {
                $prevDir = $dir;
            }
            if (!empty($dir) && substr($dir, -1) !== '/') {
                $dir .= '/';
            }
            $filename = trim($sheet->getCell($this->mapping[self::COLUMN_FILENAME] . $row)->getValue());
            if (!empty($filename)) {
                $path2 = $dir . $filename;
            }
        }
        return empty($path1) ? $path2 : $path1;
    }
}
