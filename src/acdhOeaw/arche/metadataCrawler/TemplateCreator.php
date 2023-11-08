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

use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\CellAddress;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\schema\SkosConceptDesc;
use acdhOeaw\arche\lib\Schema;
use zozlak\RdfConstants as RDF;

/**
 * Description of TemplateCreator
 *
 * @author zozlak
 */
class TemplateCreator {

    public const LOCK_PSWD             = 'ARCHE';
    private const HORIZONTAL_ROW_HEIGHT = 2;
    private const FIRST_ROW_ID_ONLY     = 8;
    private const FIRST_ROW_OTHER       = 7;
    private const VOCABULARY_SHEET      = 'Vocabularies';
    private const ENTRY_ROWS            = 200;
    private const SKIP_PROPERTIES       = [
        'https://vocabs.acdh.oeaw.ac.at/schema#hasPid'
    ];
    private const URL_FORMULA           = '=LEFT(%cell%, 4)="http"';
    private const ID_ONLY_CLASSES       = [
        'https://vocabs.acdh.oeaw.ac.at/schema#Person',
        'https://vocabs.acdh.oeaw.ac.at/schema#Organisation',
        'https://vocabs.acdh.oeaw.ac.at/schema#Place',
    ];
    private const AGENT_CLASS           = 'https://vocabs.acdh.oeaw.ac.at/schema#Agent';
    private const STYLES                = [
        'title'   => [
            'font'      => [
                'name' => 'Calibri',
                'size' => 14,
                'bold' => true,
            ],
            'fill'      => [
                'fillType'   => 'solid',
                'startColor' => ['argb' => 'FF88DBDF'],
            ],
            'alignment' => [
                'wrapText'   => true,
                'horizontal' => 'center',
                'vertical'   => 'center',
            ],
        ],
        'header1' => [
            'font'      => [
                'name' => 'Calibri',
                'size' => 11,
                'bold' => false,
            ],
            'fill'      => [
                'fillType'   => 'solid',
                'startColor' => ['argb' => 'FFCCCCCC'],
            ],
            'alignment' => [
                'wrapText'   => true,
                'horizontal' => 'center',
                'vertical'   => 'bottom',
            ],
        ],
        'header2' => [
            'font'      => [
                'name' => 'Calibri',
                'size' => 11,
                'bold' => false,
            ],
            'fill'      => [
                'fillType'   => 'solid',
                'startColor' => ['argb' => 'FFDDDDDD'],
            ],
            'alignment' => [
                'wrapText'   => true,
                'horizontal' => 'center',
                'vertical'   => 'bottom',
            ],
        ],
        'header3' => [
            'font'      => [
                'name' => 'Calibri',
                'size' => 11,
                'bold' => false,
            ],
            'fill'      => [
                'fillType'   => 'solid',
                'startColor' => ['argb' => 'FFEEEEEE'],
            ],
            'alignment' => [
                'wrapText'   => true,
                'horizontal' => 'center',
                'vertical'   => 'bottom',
            ],
        ],
        'content' => [
            'font'      => [
                'name' => 'Calibri',
                'size' => 11,
            ],
            'alignment' => [
                'wrapText'   => true,
                'horizontal' => 'left',
                'vertical'   => 'bottom',
            ],
        ]
    ];

    private Ontology $ontology;
    private Schema $schema;
    private string $repoBaseUrl;
    private LoggerInterface | null $log = null;

    public function __construct(Ontology $ontology, Schema $schema,
                                string $repoBaseUrl,
                                ?LoggerInterface $log = null) {
        $this->ontology    = $ontology;
        $this->schema      = $schema;
        $this->repoBaseUrl = $repoBaseUrl;
        $this->log         = $log;
    }

    public function createHorizontalTemplate(string $path, string $class,
                                             string $guidelines): void {
        $classDesc = $this->ontology->getClass($class);
        if ($classDesc === null) {
            throw new MetadataCrawlerException("Unknown class $class");
        }
        $idProp      = (string) $this->schema->id;
        $labelProp   = (string) $this->schema->label;
        $spreadsheet = new Spreadsheet();

        $this->addGuidelines($spreadsheet->getActiveSheet(), $guidelines);

        $properties = $classDesc->getProperties();
        $properties = array_filter($properties, fn(PropertyDesc $x) => !$x->automatedFill && 0 === count(array_intersect($x->property, self::SKIP_PROPERTIES)));
        $orderFn    = fn(PropertyDesc $x) => -999999 * in_array($labelProp, $x->property) - 888888 * in_array($idProp, $x->property) - 777777 * ($x->min > 0) - 666666 * $x->recommendedClass + $x->ordering;
        usort($properties, fn(PropertyDesc $a, PropertyDesc $b) => $orderFn($a) <=> $orderFn($b));
        $properties = array_values($properties);

        $shortName = $this->shortenUri($class);
        $sheet     = new Worksheet(null, $shortName);
        $spreadsheet->addSheet($sheet, 0);
        $this->protectSheet($sheet);

        $titleStyle = $this->getStyle('title');
        $sheet->setCellValue('A1', "Properties for $shortName (" . date('d.m.Y', time()) . ')');
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1:G1')->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight($titleStyle['font']['size'] * 1.8, 'pt');
        $sheet->setCellValue('A2', 'Please see the guidelines in the "Guidelines" sheet');
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2:G2')->applyFromArray($titleStyle);
        $sheet->getRowDimension(2)->setRowHeight($titleStyle['font']['size'] * 1.8, 'pt');

        $header = [
            'A' => ['Property', 4.5],
            'B' => ['Ordinality', 3],
            'C' => ['Description', 6],
            'D' => ['Vocabulary', 4],
            'E' => ['Value 1', 6],
            'F' => ['Value 2', 6],
            'G' => ['Value 3', 6],
        ];
        foreach ($header as $col => $def) {
            $sheet->setCellValue($col . '4', $def[0]);
            $sheet->getStyle($col . '4')->applyFromArray($this->getStyle('title', true, true));
            $sheet->getColumnDimension($col)->setWidth($def[1], 'cm');
        }
        $sheet->getRowDimension(4)->setRowHeight($titleStyle['font']['size'] * 1.8, 'pt');

        $styleContent = $this->getStyle('content', false, true);
        for ($i = 0, $row = 5; $i < count($properties); $i++, $row++) {
            /* @var $prop PropertyDesc */
            $prop  = $properties[$i];
            $style = $prop->min > 0 ? 'header1' : ($prop->recommendedClass ? 'header2' : 'header3');

            $sheet->setCellValue("A$row", $this->shortenUri($prop->uri));
            $sheet->getStyle("A$row")->applyFromArray($this->getStyle($style, $i < 2, true));

            $sheet->setCellValue("B$row", $this->getCardinality($prop));
            $sheet->getStyle("B$row")->applyFromArray($this->getStyle($style, false, true));

            $sheet->setCellValue("C$row", $prop->comment['en'] ?? reset($prop->comment));
            $sheet->getStyle("C$row")->applyFromArray($this->getStyle($style, false, true, 'left'));

            if (!empty($prop->vocabs)) {
                $sheet->setCellValue("D$row", $prop->vocabs);
            }
            $sheet->getStyle("D$row")->applyFromArray($this->getStyle($style, false, true, 'left'));

            $styleNa = $this->getStyle($style, false, true);
            if ($prop->max === 1) {
                $sheet->setCellValue("F$row", 'Not Applicable');
                $sheet->setCellValue("G$row", 'Not Applicable');
                $sheet->getStyle("E$row")->applyFromArray($styleContent);
                $sheet->getStyle("F$row:G$row")->applyFromArray($styleNa);
                $unprotectRange = "E$row";
            } else {
                $sheet->getStyle("E$row:G$row")->applyFromArray($styleContent);
                $unprotectRange = "E$row:N$row";
            }
            $sheet->getStyle($unprotectRange)
                ->getProtection()
                ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);

            $sheet->getRowDimension($row)->setRowHeight(self::HORIZONTAL_ROW_HEIGHT, 'cm');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    /**
     * 
     * @param array<string> $classes
     */
    public function createNamedEntitiesTemplate(string $path, array $classes,
                                                string $guidelines): void {
        $idProp      = (string) $this->schema->id;
        $labelProp   = (string) $this->schema->label;
        $spreadsheet = new Spreadsheet();

        $this->addGuidelines($spreadsheet->getActiveSheet(), $guidelines);

        $vocabularySheet = new Worksheet(null, self::VOCABULARY_SHEET);
        $spreadsheet->addSheet($vocabularySheet);
        $this->protectSheet($vocabularySheet);
        $vocabularies    = [];

        // pseudo-vocabularies for ranges covering few classes, e.g. acdh:Agent or acdh:AgentOrPlace
        $aggRanges      = [];
        $coveredClasses = [];
        foreach ($classes as $class) {
            $class = $this->ontology->getClass($class);
            foreach (array_filter($class->getProperties(), fn($x) => $x->type === RDF::OWL_OBJECT_PROPERTY) as $prop) {
                foreach (array_filter($prop->range, fn($x) => !str_starts_with($x, $this->repoBaseUrl) && !in_array($x, $classes)) as $i) {
                    $aggRanges[$i] = '';
                }
            }
            foreach ($class->class as $i) {
                $coveredClasses[$i] = '';
            }
        }
        $coveredClasses = array_keys($coveredClasses);
        foreach (array_keys($aggRanges) as $i) {
            if ($this->covers($coveredClasses, $this->ontology->getClass($i))) {
                $classesTmp       = $this->ontology->getChildClasses($i);
                $classesTmp       = array_intersect($classes, array_merge(...array_map(fn($x) => $x->class, $classesTmp)));
                $vocabularies[$i] = $this->createInstanceList($i, $classesTmp, $vocabularySheet, end($vocabularies) ?: 'A1:A1');
            }
        }

        // sheets for classes
        $orderFn = fn(PropertyDesc $x) => -999999 * in_array($labelProp, $x->property) - 888888 * in_array($idProp, $x->property) - 777777 * ($x->min > 0) - 666666 * $x->recommendedClass + $x->ordering;
        foreach ($classes as $class) {
            $this->log?->info($class);
            $classDesc  = $this->ontology->getClass($class);
            $properties = $classDesc->getProperties();
            $properties = array_filter($properties, fn(PropertyDesc $x) => !$x->automatedFill && 0 === count(array_intersect($x->property, self::SKIP_PROPERTIES)));
            usort($properties, fn(PropertyDesc $a, PropertyDesc $b) => $orderFn($a) <=> $orderFn($b));
            $properties = array_values($properties);

            $shortName = $this->shortenUri($class);
            $sheet     = new Worksheet(null, $shortName);
            $spreadsheet->addSheet($sheet, 0);

            $lastCol  = CellAddress::fromColumnAndRow(count($properties), 1)->columnName();
            $firstRow = $this->getFirstRow($class);
            if (in_array($class, self::ID_ONLY_CLASSES)) {
                $addr  = 'C' . ($firstRow - 4);
                $sheet->setCellValue($addr, 'provide ONLY if hasIdentifier is empty');
                $sheet->mergeCells($addr . ':' . $lastCol . ($firstRow - 4));
                $style = $this->getStyle('header2', true, true, 'left');
                $sheet->getStyle($addr . ':' . $lastCol . ($firstRow - 4))->applyFromArray($style);
                $sheet->getRowDimension($firstRow - 4)->setRowHeight($style['font']['size'] * 1.6, 'pt');
            }
            $lastRow = $firstRow + self::ENTRY_ROWS;

            $titleStyle = $this->getStyle('title');
            $sheet->setCellValue('A1', "Properties for $shortName (" . date('d.m.Y', time()) . ')');
            $sheet->mergeCells('A1:K1');
            $sheet->getStyle('A1:K1')->applyFromArray($titleStyle);
            $sheet->getRowDimension(1)->setRowHeight($titleStyle['font']['size'] * 1.8, 'pt');
            $sheet->setCellValue('A2', 'Please see the guidelines in the "Guidelines" sheet');
            $sheet->mergeCells('A2:K2');
            $sheet->getStyle('A2:K2')->applyFromArray($titleStyle);
            $sheet->getRowDimension(2)->setRowHeight($titleStyle['font']['size'] * 1.8, 'pt');

            for ($i = 0; $i < count($properties); $i++) {
                /* @var $prop PropertyDesc */
                $prop  = $properties[$i];
                $style = $prop->min > 0 ? 'header1' : ($prop->recommendedClass ? 'header2' : 'header3');
                $col   = CellAddress::fromColumnAndRow($i + 1, $firstRow)->columnName();

                $addr = $col . ($firstRow - 3);
                $sheet->setCellValue($addr, $this->getCardinality($prop));
                $sheet->getStyle($addr)->applyFromArray($this->getStyle($style, false, true));

                $addr = $col . ($firstRow - 2);
                $sheet->setCellValue($addr, $prop->comment['en'] ?? reset($prop->comment));
                $sheet->getStyle($addr)->applyFromArray($this->getStyle($style, false, true, 'left'));

                $addr = $col . ($firstRow - 1);
                $sheet->setCellValue($addr, $this->shortenUri($prop->uri));
                $sheet->getStyle($addr)->applyFromArray($this->getStyle($style, $i < 2, true));

                $sheet->getColumnDimensionByColumn($i + 1)->setWidth(4, 'cm');

                $valuesRange = $col . $firstRow . ':' . $col . $lastRow;
                $allowBlank  = ($prop->min ?? 0) === 0;
                $targetClass = array_intersect($prop->range, $classes);
                $targetRange = array_intersect($prop->range, array_keys($vocabularies));
                $targetRange = reset($targetRange);
                if (!empty($prop->vocabs)) {
                    if (!isset($vocabularies[$prop->vocabs])) {
                        $vocabularies[$prop->vocabs] = $this->importVocabulary($prop->vocabs, $prop->getVocabularyValues(), $vocabularySheet, end($vocabularies) ?: 'A1:A1');
                    }
                    $this->setValidation($sheet, DataValidation::TYPE_LIST, $valuesRange, "'" . self::VOCABULARY_SHEET . "'!" . $vocabularies[$prop->vocabs], '', $allowBlank);
                } elseif (count($targetClass) > 0) {
                    $targetClass    = reset($targetClass);
                    $targetSheet    = $this->shortenUri($targetClass);
                    $targetFirstRow = $this->getFirstRow($targetClass);
                    $targetRange    = "'$targetSheet'!" . '$A$' . $targetFirstRow . ':$A$' . ($targetFirstRow + self::ENTRY_ROWS);
                    $this->setValidation($sheet, DataValidation::TYPE_LIST, $valuesRange, $targetRange, '', $allowBlank);
                } elseif (in_array(RDF::XSD_DATE, $prop->range)) {
                    $this->setValidation($sheet, DataValidation::TYPE_DATE, $valuesRange, '', '', $allowBlank);
                } elseif (in_array(RDF::XSD_DATE_TIME, $prop->range)) {
                    $this->setValidation($sheet, DataValidation::TYPE_TIME, $valuesRange, '', '', $allowBlank);
                } elseif (isset($vocabularies[$targetRange])) {
                    $this->setValidation($sheet, DataValidation::TYPE_LIST, $valuesRange, "'" . self::VOCABULARY_SHEET . "'!" . $vocabularies[$targetRange], '', $allowBlank);
                } elseif (in_array(RDF::XSD_ANY_URI, $prop->range) || $prop->type === RDF::OWL_OBJECT_PROPERTY) {
                    $this->setValidation($sheet, DataValidation::TYPE_CUSTOM, $valuesRange, str_replace('%cell%', $col . $firstRow, self::URL_FORMULA), '', $allowBlank);
                }
            }
            $style  = $this->getStyle('content', false, true);
            $height = $style['font']['size'] * 1.6;
            $sheet->getStyle("A$firstRow:$lastCol$lastRow")->applyFromArray($style);
            $sheet->getRowDimension($firstRow - 3)->setRowHeight($height, 'pt');
            $sheet->getRowDimension($firstRow - 2)->setRowHeight(300, 'pt');
            for ($i = $firstRow - 1; $i <= $lastRow; $i++) {
                $sheet->getRowDimension($i)->setRowHeight($height, 'pt');
            }

            $this->protectSheet($sheet);
            $sheet->getStyle("A$firstRow:" . $lastCol . ($firstRow + self::ENTRY_ROWS))
                ->getProtection()
                ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function setValidation(Worksheet $sheet, string $type,
                                   string $range, string $formula1,
                                   string $formula2, bool $allowBlank): void {
        $this->log?->debug("setting validation of $range to $type: $formula1");
        $firstCell  = explode(':', $range)[0];
        $validation = $sheet->getCell($firstCell)->getDataValidation();
        $validation->setType($type);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        if ($type === DataValidation::TYPE_LIST) {
            $validation->setOperator(DataValidation::OPERATOR_EQUAL);
            $validation->setShowDropDown(true);
        } elseif ($type === DataValidation::TYPE_DATE && empty($formula1)) {
            // there's no way to avoid actual value veryfication
            // so for "any date" we need to set "!= dateWhichWillNeverBeUsed"
            $validation->setOperator(DataValidation::OPERATOR_NOTEQUAL);
            $formula1 = Date::stringToExcel('01.01.2300');
        }
        $validation->setShowErrorMessage(true);
        $validation->setAllowBlank($allowBlank);
        $validation->setFormula1($formula1);
        $validation->setFormula2($formula2);
        $validation->setSqref($range);
    }

    private function protectSheet(Worksheet $sheet): void {
        $protection = $sheet->getProtection();
        $protection->setPassword(self::LOCK_PSWD);
        $protection->setSheet(true);
        $protection->setSort(true);
        $protection->setDeleteColumns(true);
        $protection->setDeleteRows(true);
        $protection->setFormatCells(false);
        $protection->setInsertColumns(true);
        $protection->setInsertHyperlinks(true);
        $protection->setInsertRows(true);
        $protection->setObjects(true);
        $protection->setPivotTables(true);
    }

    /**
     * 
     * @param string $name
     * @param array<SkosConceptDesc> $values
     * @param Worksheet $sheet
     * @param string $prevRange
     * @return string
     */
    private function importVocabulary(string $name, array $values,
                                      Worksheet $sheet, string $prevRange): string {
        list($colLabel, $colUri) = $this->initVocabulary($sheet, $name, $prevRange);
        $row = 2;
        foreach ($values as $c) {
            /* @var $c SkosConceptDesc */
            $sheet->setCellValue($colLabel . $row, $c->label['en'] ?? reset($c->label));
            $sheet->setCellValue($colUri . $row, $c->uri);
            $row++;
        }
        $sheet->getStyle($colLabel . '2:' . $colUri . ($row - 1))->applyFromArray($this->getStyle('content', false, true));
        return '$' . $colLabel . '$2:$' . $colLabel . '$' . ($row - 1);
    }

    private function createInstanceList(string $name, array $classes,
                                        Worksheet $sheet, string $prevRange): string {
        $name = $this->shortenUri($name);
        list($colLabel, $colUri) = $this->initVocabulary($sheet, $name, $prevRange);
        $row  = 2;
        foreach ($classes as $class) {
            $srcSheet = $this->shortenUri($class);
            $srcRow   = $this->getFirstRow($class);
            for ($i = 0; $i < self::ENTRY_ROWS; $i++) {
                $sheet->setCellValue($colLabel . $row, "='$srcSheet'!\$A\$$srcRow");
                $sheet->setCellValue($colUri . $row, "='$srcSheet'!\$B\$$srcRow");
                $srcRow++;
                $row++;
            }
        }
        $sheet->getStyle($colLabel . '2:' . $colUri . ($row - 1))->applyFromArray($this->getStyle('content', false, true));
        return '$' . $colLabel . '$2:$' . $colLabel . '$' . ($row - 1);
    }

    private function initVocabulary(Worksheet $sheet, string $name,
                                    string $prevRange): array {
        $colLabel = CellAddress::fromCellAddress(explode(':', $prevRange)[1])->columnId() + 3;
        $colUri   = CellAddress::fromColumnAndRow($colLabel + 1, 1)->columnName();
        $colLabel = CellAddress::fromColumnAndRow($colLabel, 1)->columnName();
        $sheet->getColumnDimension($colLabel)->setWidth(4, 'cm');
        $sheet->getColumnDimension($colUri)->setWidth(4, 'cm');

        $sheet->getStyle($colLabel . '1:' . $colUri . '1')->applyFromArray($this->getStyle('header1', true, true));
        $sheet->mergeCells($colLabel . '1:' . $colUri . '1');
        $sheet->setCellValue($colLabel . '1', $name);

        return [$colLabel, $colUri];
    }

    private function getFirstRow(string $class): int {
        return in_array($class, self::ID_ONLY_CLASSES) ? self::FIRST_ROW_ID_ONLY : self::FIRST_ROW_OTHER;
    }

    private function getStyle(string $name, ?bool $bold = null,
                              ?bool $border = null, ?string $align = null): array {
        $style = self::STYLES[$name];
        if ($bold !== null) {
            $style['font']['bold'] = $bold;
        }
        if ($border === false) {
            unset($style['borders']);
        } elseif ($border === true) {
            foreach (['bottom', 'left', 'right', 'top', 'inside'] as $i) {
                $style['borders'][$i] = [
                    'borderStyle' => 'thin',
                    'color'       => ['argb' => 'FF000000'],
                ];
            }
        }
        if (!empty($align)) {
            $style['alignment']['horizontal'] = $align;
        }
        return $style;
    }

    private function covers(array $coveredClasses, ClassDesc $class): bool {
        $notCovered = $this->ontology->getChildClasses($class);
        $notCovered = array_filter($notCovered, fn($x) => count(array_diff($x->class, $class->class)) > 0);
        if (count($notCovered) === 0) {
            // leaf which wasn't found in $coveredClasses so far
            return false;
        }
        $notCovered = array_filter($notCovered, fn($x) => count(array_diff($x->class, $coveredClasses)) > 0);
        if (count($notCovered) === 0) {
            return true;
        }
        foreach ($notCovered as $i) {
            if (!$this->covers($coveredClasses, $i)) {
                return false;
            }
        }
        return true;
    }

    private function addGuidelines(Worksheet $sheet, string $guidelines): void {
        $sheet->setTitle('Guidelines');
        $sheet->setCellValue('A1', 'Guidelines');
        $guidelines = explode("\n", $guidelines);
        for ($i = 0;
            $i < count($guidelines);
            $i++) {
            $sheet->setCellValue('A' . ($i + 2), $guidelines[$i]);
        }
        $sheet->getStyle('A1:A' . ($i + 1))->applyFromArray($this->getStyle('content'));
        $sheet->getStyle('A1')->applyFromArray($this->getStyle('title'));
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->refreshColumnDimensions();
    }

    private function getCardinality(PropertyDesc $prop): string {
        $cardinality = $prop->min > 0 ? 'required' : ($prop->recommendedClass ? 'recommended' : 'optional');
        if (!($prop->max === 1)) {
            $cardinality .= '*';
        }
        return $cardinality;
    }

    private function shortenUri(string $uri): string {
        return preg_replace('`^.*[/#]`', '', $uri);
    }
}
