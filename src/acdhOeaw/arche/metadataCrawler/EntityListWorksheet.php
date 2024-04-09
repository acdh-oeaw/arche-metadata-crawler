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

use Generator;
use SplObjectStorage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\ColumnCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use acdhOeaw\arche\lib\schema\Ontology;
use quickRdf\DatasetNode;
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
use zozlak\RdfConstants as RDF;
use Psr\Log\LoggerInterface;
use acdhOeaw\arche\metadataCrawler\container\WorksheetConfig;

/**
 * Description of EntityList
 *
 * @author zozlak
 */
class EntityListWorksheet {

    use MetadataSpreadsheetTrait;

    public const STRICT_REQUIRED      = 1;
    public const STRICT_RECOMMENDED   = 2;
    public const STRICT_OPTIONAL      = 3;
    private const ORGANISATION_CLASSES = [
        'https://vocabs.acdh.oeaw.ac.at/schema#Organisation',
        'https://vocabs.acdh.oeaw.ac.at/schema#Agent',
        'https://vocabs.acdh.oeaw.ac.at/schema#Person',
    ];
    private const SKIP_PROPERTIES      = [
        'https://vocabs.acdh.oeaw.ac.at/schema#hasPid'
    ];

    private Ontology $ontology;
    private Schema $schema;
    private string $defaultLang;
    private string $path;

    /**
     * 
     * @var array<string, WorksheetConfig>
     */
    private array $classes = [];
    private LoggerInterface | null $log     = null;

    public function __construct(string $path, Ontology $ontology,
                                Schema $schema, string $defaultLang,
                                int $strictness = self::STRICT_OPTIONAL,
                                ?LoggerInterface $log = null) {
        $spreadsheet       = IOFactory::load($path);
        $this->path        = $path;
        $this->ontology    = $ontology;
        $this->schema      = $schema;
        $this->defaultLang = $defaultLang;
        $this->log         = $log;
        $this->horizontal  = false;

        $this->log?->info("Trying to map $path as an entities database");
        $this->mapWorksheets($spreadsheet, $strictness);
    }

    /**
     * 
     * @return Generator<DatasetNode>
     */
    public function readEntities(): Generator {
        if (count($this->classes) > 0) {
            $this->log?->info("Reading $this->path as a named entities file");
        }
        foreach ($this->classes as $sheetCfg) {
            yield from $this->loadEntities($sheetCfg);
        }
    }

    private function mapWorksheets(Spreadsheet $spreadsheet, int $strictness): void {
        $propFilter = match ($strictness) {
            self::STRICT_REQUIRED => fn(PropertyDesc $x) => !$x->automatedFill && !in_array($x->uri, self::SKIP_PROPERTIES) && $x->min > 0,
            self::STRICT_RECOMMENDED => fn(PropertyDesc $x) => !$x->automatedFill && !in_array($x->uri, self::SKIP_PROPERTIES) && ($x->min > 0 || $x->recommendedClass),
            self::STRICT_OPTIONAL => fn(PropertyDesc $x) => !$x->automatedFill && !in_array($x->uri, self::SKIP_PROPERTIES),
        };

        $nmsp       = $this->ontology->getNamespace();
        $allClasses = $this->ontology->getNamespaceClasses();
        for ($i = 0; $i < $spreadsheet->getSheetCount(); $i++) {
            $sheetName = $spreadsheet->getSheetNames()[$i];
            $sheet     = $spreadsheet->getSheet($i);
            $row       = null;
            foreach (new ColumnCellIterator($sheet, 'A', 1, 20) as $cell) {
                $value = $cell->getCalculatedValue();
                if (!empty($value) && $this->ontology->getProperty(null, $value) !== null || $this->ontology->getProperty(null, $nmsp . $value) !== null) {
                    $row = $cell->getRow();
                }
            }
            if ($row === null) {
                continue;
            }
            // find classes matching predicates in the worksheet
            $breakingValue   = null;
            $matchingClasses = $allClasses;
            $properties      = [];
            foreach (new RowCellIterator($sheet, $row, 'A', 'ZZ') as $cell) {
                $value = $cell->getCalculatedValue();
                if (empty($value)) {
                    break;
                }
                if (!str_starts_with($value, $nmsp)) {
                    $value = $nmsp . $value;
                }
                $properties[$cell->getColumn()] = $value;
                $matchingClasses                = array_filter($matchingClasses, fn($x) => isset($x->properties[$value]));
                if (count($matchingClasses) === 0) {
                    $breakingValue = $value;
                }
            }
            $matchingClasses = array_filter($matchingClasses, function (ClassDesc $class) use ($properties,
                                                                                               $propFilter): bool {
                $missing = new SplObjectStorage();
                array_map(fn($x) => $missing->attach($x), array_filter($class->properties, $propFilter));
                foreach ($properties as $i) {
                    $missing->detach($class->properties[$i]);
                }
                return $missing->count() === 0;
            });
            // corner case for the acdh:Organisation
            if (count($matchingClasses) > 1 && count(array_intersect(array_keys($matchingClasses), self::ORGANISATION_CLASSES)) === count($matchingClasses)) {
                $matchingClasses = [self::ORGANISATION_CLASSES[0] => $matchingClasses[self::ORGANISATION_CLASSES[0]]];
            }
            // skip no matches and unambiguous matches
            if (count($matchingClasses) > 1) {
                $this->log?->warning("\tSheet $sheetName matches multiple classes: " . implode(', ', array_keys($matchingClasses)));
                continue;
            } elseif (count($matchingClasses) === 0) {
                $this->log?->warning("\tSheet $sheetName matches no classes ($breakingValue property not matched)");
                continue;
            }
            $classDesc             = reset($matchingClasses);
            $class                 = $classDesc->uri;
            $properties            = array_map(fn($x) => $classDesc->properties[$x], $properties);
            $this->log?->debug("\tSheet $sheetName mapped to class $class");
            $this->classes[$class] = new WorksheetConfig($classDesc, $sheet, $row, $properties);
        }
        if (count($this->classes) === 0) {
            $this->log?->debug("\tFailed to find a mapping for any named entities class");
        }
    }

    private function loadEntities(WorksheetConfig $cfg): array {
        $idProp    = (string) $this->schema->id;
        $labelProp = (string) $this->schema->label;
        $rdfType   = DF::namedNode(RDF::RDF_TYPE);
        $sheet     = $cfg->worksheet;

        $labelCol = array_key_first(array_filter($cfg->propertyMap, fn(PropertyDesc $x) => $x->uri === $labelProp));
        $idCol    = array_key_first(array_filter($cfg->propertyMap, fn(PropertyDesc $x) => $x->uri === $idProp));
        $predMap  = array_map(fn(PropertyDesc $x) => DF::namedNode($x->uri), $cfg->propertyMap);

        $this->log?->info("\tReading entities of class " . $cfg->class->uri);
        $this->mapReferenceCols($cfg);
        $highestDataRow = $sheet->getHighestDataRow();
        $entity         = $curLabel       = $entityRow      = null;
        $uniqueIds      = [];
        $uniqueLabels   = [];
        $entities       = [];
        for ($row = $cfg->headerRow + 1; $row < $highestDataRow; $row++) {
            $label = $sheet->getCell($labelCol . $row)->getCalculatedValue();
            if (!empty($label) && $label !== $curLabel) {
                if ($entity !== null && $this->checkEntity($entity, $cfg->propertyMap)) {
                    $entities[] = $entity;
                    $this->log?->debug("\t\tEntity read at row $entityRow:\n" . (string) $entity);
                }
                $entity    = $curLabel  = $entityRow = $sbj       = null;
                $sbj       = $sheet->getCell($idCol . $row)->getCalculatedValue();
                if (empty($sbj)) {
                    $this->log?->debug("\t\tSkipping row $row because of an empty id column");
                } else {
                    $entity    = new DatasetNode(DF::namedNode($sbj));
                    $entity->add(DF::quadNoSubject($rdfType, DF::namedNode($cfg->class->uri)));
                    $curLabel  = $label;
                    $entityRow = $row;
                    if (isset($uniqueLabels[$label])) {
                        $this->log?->error("\t\tTitle $label used more than once in row $row");
                    }
                    $uniqueLabels[$label] = ($uniqueLabels[$label] ?? 0) + 1;
                }
            }
            if ($curLabel === null) {
                continue;
            }
            foreach ($cfg->propertyMap as $col => $prop) {
                $cell = $sheet->getCell($col . $row);
                $obj  = $this->getValue($cell, $prop, $this->defaultLang);
                if ($obj === null) {
                    continue;
                }
                if ($col === $idCol) {
                    if (isset($uniqueIds[(string) $obj])) {
                        $this->log?->error("\t\tIdentifier $obj used more than once in row $row");
                    }
                    $uniqueIds[(string) $obj] = ($uniqueIds[(string) $obj] ?? 0) + 1;
                }
                $entity->add(DF::quadNoSubject($predMap[$col], $obj));
            }
        }
        if ($entity !== null && self::checkEntity($entity, $cfg->propertyMap)) {
            $entities[] = $entity;
            $this->log?->debug("\t\tEntity read at row $entityRow:\n" . (string) $entity);
        }

        if (count(array_filter($uniqueLabels, fn($x) => $x > 1)) > 0 || count(array_filter($uniqueIds, fn($x) => $x > 1)) > 0) {
            $this->log?->info("\t\tAborting entities reading because of duplicates ids and/or titles");
            return [];
        }
        $this->log?->info("\t\t" . count($entities) . " entities read");
        return $entities;
    }

    /**
     * 
     * @param DatasetNode $entity
     * @param array<string, PropertyDesc> $properties
     * @return bool
     */
    private function checkEntity(DatasetNode $entity, array $properties): bool {
        $valid = true;
        foreach ($properties as $prop) {
            /* @var $prop PropertyDesc */
            if ($prop->automatedFill || $prop->min === 0 && !$prop->recommendedClass) {
                continue;
            }
            if ($entity->none(new PT(DF::namedNode($prop->uri)))) {
                if ($prop->min > 0) {
                    $this->log?->error("\t\tEntity " . $entity->getNode() . " missing value of required property $prop->uri");
                    $valid = false;
                } else {
                    $this->log?->debug("\t\tEntity " . $entity->getNode() . " missing value of recommended property $prop->uri");
                }
            }
        }
        return $valid;
    }
}
