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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use rdfInterface\QuadInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\ingest\util\FileId;
use acdhOeaw\arche\metadataCrawler\container\PropertyMapping;

/**
 * Description of MetadataVertical
 *
 * @author zozlak
 * 
 * @implements IteratorAggregate<QuadInterface>
 */
class MetadataVertical implements IteratorAggregate {

    use MetadataSpreadsheetTrait;

    private const HEADER_ROW_MAX  = 10;
    private const COLUMN_PATH     = 'path';
    private const COLUMN_DIR      = 'directory';
    private const COLUMN_FILENAME = 'filename';

    private Ontology $ontology;

    private Schema $schema;
    private FileId $idgen;
    private Dataset $meta;

    /**
     * 
     * @var array<int, PropertyMapping>
     */
    private array $mapping;
    private ?string $colPath;
    private ?string $colDir;
    private ?string $colFilename;
    private int $firstRow;

    public function __construct(string $path, Ontology $ontology,
                                Schema $schema, string $idPrefix,
                                string $defaultLang,
                                LoggerInterface | null $log = null) {
        if (substr($idPrefix, -1) !== '/') {
            $idPrefix .= '/';
        }
        $this->ontology   = $ontology;
        $this->schema     = $schema;
        $this->idgen      = new FileId($idPrefix);
        $this->log        = $log;
        $this->meta       = new Dataset();
        $this->horizontal = false;

        $spreadsheet = IOFactory::load($path);
        foreach($spreadsheet->getWorksheetIterator() as $n => $sheet) {
            $this->log?->info("Trying to map $path sheet $n as a vertical metadata file");
            if ($this->mapStructure($sheet, $defaultLang)) {
                $this->log?->info("Reading $path as a vertical metadata file");
                $this->readMetadata($sheet);
            }
        }
    }

    /**
     * 
     * @return Traversable<QuadInterface>
     */
    public function getIterator(): Traversable {
        return $this->meta->getIterator();
    }

    private function mapStructure(Worksheet $sheet, string $defaultLang): bool {
        $nmsp   = $this->ontology->getNamespace();
        $colMax = $sheet->getHighestDataColumn();
        for ($headerRow = 1; $headerRow <= self::HEADER_ROW_MAX; $headerRow++) {
            $propMapping = [];
            $fileMapping = [
                self::COLUMN_PATH     => null,
                self::COLUMN_DIR      => null,
                self::COLUMN_FILENAME => null,
            ];
            foreach (new RowCellIterator($sheet, $headerRow, 'A', $colMax) as $cell) {
                /* @var $cell Cell */
                $col = $cell->getColumn();
                $v   = mb_strtolower($cell->getCalculatedValue());
                if (array_key_exists($v, $fileMapping)) {
                    $fileMapping[$v] = $col;
                    continue;
                }
                $v = $cell->getCalculatedValue();
                if (empty($v)) {
                    continue;
                }
                if (!str_starts_with($v, $nmsp)) {
                    $v = $nmsp . $v;
                }
                list($v, $lang) = $this->getPropertyLang($v, $defaultLang);
                $property = $this->ontology->getProperty(null, $v);
                if ($property !== null) {
                    $propMapping[] = new PropertyMapping($property, $property->langTag ? $lang : null, column: $col);
                }
            }
            if (count($propMapping) > 0 && ($fileMapping[self::COLUMN_PATH] !== null || $fileMapping[self::COLUMN_DIR] !== null && $fileMapping[self::COLUMN_FILENAME] !== null)) {
                break;
            }
        }
        if ($headerRow > self::HEADER_ROW_MAX) {
            $this->log?->debug("\tFailed to find required columns");
            return false;
        }
        $this->mapping     = $propMapping;
        $this->colDir      = $fileMapping[self::COLUMN_DIR];
        $this->colFilename = $fileMapping[self::COLUMN_FILENAME];
        $this->colPath     = $fileMapping[self::COLUMN_PATH];
        $this->firstRow    = $headerRow + 1;
        return true;
    }

    private function readMetadata(Worksheet $sheet): void {
        $map      = $this->mapping;
        $rowMax   = $sheet->getHighestDataRow();
        $prevPath = '';
        $prevDir  = '';
        $sbj      = null;
        $n        = 0;

        for ($row = $this->firstRow; $row <= $rowMax; $row++) {
            $path = $this->getPaths($sheet, $row, $prevDir);
            if (empty($path)) {
                $path = $prevPath;
            } elseif ($path !== $prevPath) {
                /** @phpstan-ignore empty.variable */
                if (!empty($path)) {
                    $sbj = DF::namedNode($this->idgen->getId($path));
                    $n++;
                }
                $prevPath = $path;
            }
            if (empty($path)) {
                continue;
            }
            foreach ($map as $desc) {
                $cell  = $sheet->getCell($desc->column . $row);
                $value = $this->getValue($cell, $desc->propDesc, $desc->defaultLang);
                if ($value !== null) {
                    $this->meta->add(DF::quad($sbj, DF::namedNode($desc->propDesc->uri), $value));
                }
            }
        }
        $this->log?->info("\tMetadata of $n files read");
    }

    private function getPaths(Worksheet $sheet, int $row, string &$prevDir): string {
        $path1 = $path2 = '';
        if (isset($this->colPath)) {
            $path1 = trim($sheet->getCell($this->colPath . $row)->getCalculatedValue());
        }
        if (isset($this->colFilename) && isset($this->colDir)) {
            $dir = trim((string) $sheet->getCell($this->colDir . $row)->getCalculatedValue());
            if (empty($dir)) {
                $dir = $prevDir;
            } else {
                $prevDir = $dir;
            }
            if (!empty($dir) && substr($dir, -1) !== '/') {
                $dir .= '/';
            }
            $filename = trim($sheet->getCell($this->colFilename . $row)->getCalculatedValue());
            if (!empty($filename)) {
                $path2 = $dir . $filename;
            }
        }
        return empty($path1) ? $path2 : $path1;
    }
}
