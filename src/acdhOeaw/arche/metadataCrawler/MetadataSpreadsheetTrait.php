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

use DateTime;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use zozlak\RdfConstants as RDF;
use quickRdf\DataFactory as DF;
use quickRdf\NamedNode;
use quickRdf\Literal;
use acdhOeaw\arche\lib\schema\PropertyDesc;

/**
 * Description of MetadataSpreadsheetTrait
 *
 * @author zozlak
 */
trait MetadataSpreadsheetTrait {

    private function getValue(Cell $cell, PropertyDesc $propDesc,
                              ?string $defaultLang): NamedNode | Literal | null {
        $value = trim($cell->getCalculatedValue());
        if (empty($value)) {
            return null;
        }
        if ($propDesc->type === RDF::OWL_DATATYPE_PROPERTY) {
            if (in_array(RDF::XSD_DATE, $propDesc->range)) {
                return DF::literal(Date::excelToDateTimeObject($value)->format('Y-m-d'), null, RDF::XSD_DATE);
            } elseif (in_array(RDF::XSD_DATE_TIME, $propDesc->range)) {
                return DF::literal(Date::excelToDateTimeObject($value)->format(DateTime::ISO8601), null, RDF::XSD_DATE_TIME);
            } elseif ($propDesc->type === RDF::OWL_DATATYPE_PROPERTY) {
                $lang  = $defaultLang;
                $value = explode('@', $value);
                if (count($value) > 1) {
                    $lang = array_pop($value);
                }
                $value = implode('@', $value);
                return DF::literal($value, $lang);
            }
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
}
