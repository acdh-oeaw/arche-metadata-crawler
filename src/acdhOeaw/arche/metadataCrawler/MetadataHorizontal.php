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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use acdhOeaw\arche\metadataCrawler\container\PropertyMapping;

/**
 * Description of MetadataHorizontal
 *
 * @author zozlak
 */
class MetadataHorizontal implements IteratorAggregate {

    use MetadataSpreadsheetTrait;

    private const COL_VALUE    = '/^value *[1-9]$/';
    private const COL_PROPERTY = 'property';
    private const MAP_ROW_FROM = 1;
    private const MAP_ROW_TO   = 20;
    private const MAP_COL_FROM = 1;
    private const MAP_COL_TO   = 20;

    private Dataset $meta;
    private Ontology $ontology;
    private Schema $schema;
    private string $idPrefix;
    private LoggerInterface | null $log;

    /**
     * 
     * @var array<string, PropertyMapping>
     */
    private array $mapping;
    private int $valueColumn;

    public function __construct(string $path, Ontology $ontology,
                                Schema $schema, string $idPrefix,
                                string $defaultLang,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->idPrefix = $idPrefix;
        $this->log      = $log;
        $this->meta     = new Dataset();

        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getSheet(0);
        $this->log?->debug("Trying to map $path as a horizontal metadata file");
        if ($this->mapStructure($sheet, $defaultLang)) {
            $this->log?->info("Reading $path as a horizontal metadata file");
            $this->readMetadata($sheet);
        }
    }

    public function getIterator(): Traversable {
        return $this->meta->getIterator();
    }

    private function mapStructure(Worksheet $sheet, string $defaultLang): bool {
        for ($row = self::MAP_ROW_FROM; $row <= self::MAP_ROW_TO; $row++) {
            $propCol  = null;
            $valueCol = null;
            for ($col = self::MAP_COL_FROM; $col <= self::MAP_COL_TO; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $val  = mb_strtolower(trim($cell->getCalculatedValue()));
                if ($val === self::COL_PROPERTY && $propCol === null) {
                    $propCol = $cell->getColumn();
                } elseif ($valueCol === null && preg_match(self::COL_VALUE, $val)) {
                    $valueCol = $col;
                }
            }
            if ($propCol !== null && $valueCol !== null) {
                $row++;
                break;
            }
        }
        if ($propCol === null || $valueCol === null) {
            $this->log?->debug("\tFailed to find a property column or value columns");
            return false;
        }

        $nmsp    = $this->ontology->getNamespace();
        $idProp  = (string) $this->schema->id;
        $rowMax  = $sheet->getHighestDataRow();
        $mapping = [];
        for ($row; $row <= $rowMax; $row++) {
            $val = $sheet->getCell($propCol . $row)->getCalculatedValue();
            if (!str_starts_with($val, $nmsp)) {
                $val = $nmsp . $val;
            }
            $property = $this->ontology->getProperty(null, $val);
            if ($property !== null) {
                $mapping[] = new PropertyMapping($property, $property->langTag ? $defaultLang : null, row: $row);
            }
        }
        $idMapping = array_filter($mapping, fn(PropertyMapping $x) => $x->propDesc->uri === $idProp);
        if (count($idMapping) === 0) {
            $this->log?->debug("\tFailed to find an id property");
            return false;
        }

        $this->mapping     = $mapping;
        $this->valueColumn = $valueCol;
        return true;
    }

    private function readMetadata(Worksheet $sheet): void {
        $sbj    = null;
        $idProp = (string) $this->schema->id;
        $idMap  = array_filter($this->mapping, fn(PropertyMapping $x) => $x->propDesc->uri === $idProp);
        $idRow  = reset($idMap)->row;
        for ($col = $this->valueColumn; $col <= self::MAP_COL_TO; $col++) {
            $val = trim($sheet->getCell([$col, $idRow])->getCalculatedValue());
            // the second condition is an exact match on the top collection
            if (str_starts_with($val, $this->idPrefix) || $val === substr($this->idPrefix, 0, -1)) {
                $sbj = $val;
                break;
            }
        }
        if ($sbj === null) {
            $this->log?->warning("\tFailed to find an id matching the id prefix of $this->idPrefix");
            return;
        }
        $sbj = DF::namedNode($sbj);

        foreach ($this->mapping as $desc) {
            $property = DF::namedNode($desc->propDesc->uri);
            for ($col = $this->valueColumn; $col <= self::MAP_COL_TO; $col++) {
                $cell  = $sheet->getCell([$col, $desc->row]);
                $value = $this->getValue($cell, $desc->propDesc, $desc->defaultLang);
                if ($value !== null) {
                    $this->meta->add(DF::quad($sbj, $property, $value));
                }
            }
        }
        $this->log?->info("\t" . count($this->meta) . " triples read");
    }
}
