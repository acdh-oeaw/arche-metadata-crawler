<?php

include 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\CellAddress;
use PhpOffice\PhpSpreadsheet\Worksheet\ColumnCellIterator;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Repo;
use zozlak\logging\Log;
use Psr\Log\LogLevel;
use acdhOeaw\arche\metadataCrawler\EntityListWorksheet;
use acdhOeaw\arche\metadataCrawler\TemplateCreator;

$log      = new Log('log', LogLevel::INFO);
$repo     = Repo::factoryFromUrl('http://127.0.0.1/api');
$ontology = Ontology::factoryRest('http://127.0.0.1/api', 'ontology.cache', 36000);
#new EntityListWorksheet('tests/data/__named_entities.xlsx', $ontology, $repo->getSchema(), EntityListWorksheet::STRICT_OPTIONAL, $log);

$classes = [
    'https://vocabs.acdh.oeaw.ac.at/schema#Person',
    'https://vocabs.acdh.oeaw.ac.at/schema#Organisation',
    'https://vocabs.acdh.oeaw.ac.at/schema#Place',
    'https://vocabs.acdh.oeaw.ac.at/schema#Publication',
    'https://vocabs.acdh.oeaw.ac.at/schema#Project',
];
$creator = new TemplateCreator($ontology, $repo->getBaseUrl());
$creator->createTemplate(__DIR__ . '/tests/data/foo.xlsx', $classes, $repo->getSchema(), "foo\nbar a very long line lets see if it works\nbaz");

exit();

$ctrlDict = [];
$file     = IOFactory::load('tests/data/__named_entities.xlsx');
$sheet    = $file->getSheet(4);
$cell     = $sheet->getCell('G7');
$rules    = $cell->getDataValidation();
if ($rules->getType() === 'list') {
    $ctrl = $rules->getFormula1();
    if (!isset($rulesDict[$ctrl])) {
        list($ctrlSheet, $ctrlRange) = explode('!', $ctrl);
        list($ctrlFrom, $ctrlTo) = array_map(fn($x) => new CellAddress($x), explode(':', $ctrlRange));
        $ctrlSheet = $file->getSheetByName($ctrlSheet);
        $keyIter   = new ColumnCellIterator($ctrlSheet, $ctrlFrom->columnName(), $ctrlFrom->rowId(), $ctrlTo->rowId());
        $valIter   = new ColumnCellIterator($ctrlSheet, $ctrlFrom->nextColumn()->columnName(), $ctrlFrom->rowId(), $ctrlTo->rowId());
        $ctrlMap   = [];
        while ($keyIter->valid()) {
            $ctrlMap[$keyIter->current()->getValue()] = $valIter->current()->getValue();
            $keyIter->next();
            $valIter->next();
        }
        $ctrlDict[$ctrl] = $ctrlMap;
    }
    echo $ctrlDict[$ctrl][$cell->getValue()] . "\n";
}


exit();

$file          = IOFactory::load('tests/data/__metadata.xlsx');
$sheet         = $file->getSheet(0);
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
