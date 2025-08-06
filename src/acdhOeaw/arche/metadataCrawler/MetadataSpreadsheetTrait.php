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

use DateTime;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Calculation\Functions;
use zozlak\RdfConstants as RDF;
use quickRdf\DataFactory as DF;
use quickRdf\NamedNode;
use quickRdf\Literal;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\metadataCrawler\container\PropertyMapping;
use acdhOeaw\arche\metadataCrawler\container\WorksheetConfig;

/**
 * Description of MetadataSpreadsheetTrait
 *
 * @author zozlak
 */
trait MetadataSpreadsheetTrait {

    /**
     * Stores value maps for cells with list-controlled values.
     * 
     * Needs to be recomputed with mapReferenceCols() every time you change
     * the sheet.
     * @var array<string, array<string, NamedNode>>
     */
    private array $valueMaps;
    private bool $horizontal;
    private LoggerInterface | null $log = null;

    private function getValue(Cell $cell, PropertyDesc $propDesc,
                              ?string $defaultLang): NamedNode | Literal | null {
        static $formatGeneral = [
            NumberFormat::FORMAT_GENERAL, NumberFormat::FORMAT_NUMBER, NumberFormat::FORMAT_TEXT
        ];
        static $onlyYear      = '/^-?[0-9]+$/';

        $coordinate = (string) ($this->horizontal ? $cell->getRow() : $cell->getColumn());
        try {
            $value = trim((string) $cell->getCalculatedValue()); #TODO change to mb_trim() in PHP 8.4
        } catch (\Throwable $e) {
            print_r([$cell->getCoordinate(), $cell->getValue()]);
            throw $e;
        }
        if (empty($value)) {
            return null;
        }
        if ($propDesc->type === RDF::OWL_DATATYPE_PROPERTY) {
            $isDate     = in_array(RDF::XSD_DATE, $propDesc->range);
            $isDateTime = in_array(RDF::XSD_DATE_TIME, $propDesc->range);
            if ($isDate || $isDateTime) {
                $format = $cell->getStyle()->getNumberFormat();
                if (Date::isDateTimeFormat($format, true)) {
                    // now we have a few options:
                    // - $value is positive number - call Date::excelToDateTimeObject($value)
                    // - $value is non-positive number - the spreadsheet was created by openoffice,
                    //   so first call Functions::setCompatibilityMode(Functions::COMPATIBILITY_OPENOFFICE)
                    //   and only then Date::excelToDateTimeObject($value)
                    // - $value is not a number - try to parse it as date as it is
                    //   (which may or may not succeed)
                    if (is_numeric($value)) {
                        $value                = (float) $value;
                        $oldCompatibilityMode = Functions::getCompatibilityMode();
                        if ($value < 1) {
                            Functions::setCompatibilityMode(Functions::COMPATIBILITY_OPENOFFICE);
                        }
                        $value = Date::excelToDateTimeObject($value);
                        Functions::setCompatibilityMode($oldCompatibilityMode);
                    } else {
                        $value = new DateTime($value);
                    }
                } elseif (preg_match($onlyYear, $value)) {
                    $value = new DateTime($value . "-01-01");
                } else {
                    $value = new DateTime($value);
                }
            }

            if ($isDate) {
                return DF::literal($value->format('Y-m-d'), null, RDF::XSD_DATE);
            } elseif ($isDateTime) {
                return DF::literal($value->format(DateTime::ISO8601), null, RDF::XSD_DATE_TIME);
            } elseif ($propDesc->type === RDF::OWL_DATATYPE_PROPERTY) {
                $lang = $defaultLang;
                if (preg_match('/@[a-z]{2,3}[[:blank:]]*$/u', $value)) {
                    $value = explode('@', $value);
                    $lang  = array_pop($value);
                    $lang  = preg_replace('/[[:blank:]]+$/u', '', $lang);
                    $value = implode('@', $value);
                }
                return DF::literal($value, $lang);
            }
        } elseif (isset($this->valueMaps[$coordinate])) {
            return $this->valueMaps[$coordinate][$value] ?? throw new MetadataCrawlerException("No label-id mapping for $value in cell " . $cell->getCoordinate());
        } else {
            return DF::namedNode($value);
        }
    }

    /**
     * 
     * @param string $value
     * @param string $defaultLang
     * @return array<string>
     */
    private function getPropertyLang(string $value, string $defaultLang): array {
        $value    = explode('@', $value);
        $value[1] ??= $defaultLang;
        return $value;
    }

    private function mapReferenceCols(WorksheetConfig $cfg): void {
        $sheet           = $cfg->worksheet;
        $this->valueMaps = [];
        $row             = (string) ($cfg->headerRow + 1);
        foreach (array_keys($cfg->propertyMap) as $col) {
            $validation = $sheet->getCell($col . $row)->getDataValidation();
            $this->mapReferenceCells($sheet, $validation, $col);
        }
    }

    /**
     * 
     * @param array<PropertyMapping> $propertyMap
     * @return void
     */
    private function mapReferenceRows(Worksheet $sheet, array $propertyMap,
                                      int $valueColumn): void {
        foreach ($propertyMap as $mapping) {
            $validation = $sheet->getCell([$valueColumn, $mapping->row])->getDataValidation();
            $this->mapReferenceCells($sheet, $validation, (string) $mapping->row);
        }
    }

    private function mapReferenceCells(Worksheet $sheet,
                                       DataValidation $validation,
                                       string $mapName): void {
        if ($validation->getType() === DataValidation::TYPE_LIST) {
            $formula = $validation->getFormula1();
            if (!str_contains($formula, '!') && !str_starts_with($formula, '$A')) {
                $this->log?->error("\t\tWrong data validation formula in column $mapName: $formula");
                return;
            }
            if (str_contains($formula, '!')) {
                list($targetSheetName, $targetRange) = explode('!', $formula);
                $targetSheet = $sheet->getParent()->getSheetByName($targetSheetName);
            } else {
                $targetRange = $formula;
                $targetSheetName = $sheet->getTitle();
                $targetSheet = $sheet;
            }
            $matches                   = null;
            preg_match('`^[$]?([A-Z]+)[$]?([0-9]+)+:[$]?[A-Z]+[$]?([0-9]+)$`', $targetRange, $matches);
            list(, $labelCol, $startRow, $endRow) = $matches;
            $idCol                     = $labelCol;
            $idCol++; // works in PHP, even for multichar values (wow!)
            $this->valueMaps[$mapName] = [];
            for ($targetRow = $startRow; $targetRow < $endRow; $targetRow++) {
                try {
                    $label = $targetSheet->getCell($labelCol . $targetRow)->getCalculatedValue();
                    if (!empty($label)) {
                        $id                                = (string) $targetSheet->getCell($idCol . $targetRow)->getCalculatedValue();
                        $this->valueMaps[$mapName][$label] = DF::namedNode($id);
                    }
                } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
                    if (preg_match('`^Vocabularies![A-Z]+[0-9]+ -> Formula Error: An unexpected error occurred`', $e->getMessage())) {
                        $this->log?->warning("\t\tWrong formula in sheet $targetSheetName cell $labelCol$targetRow");
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }
}
