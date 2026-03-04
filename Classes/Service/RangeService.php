<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Service;

use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RangeService
{
    // match against => 2:24
    private const PATTERN_ROW_RANGE = '/^(\d+):(\d+)$/';

    // match against => 2 (single integer/row value)
    private const PATTERN_SINGLE_ROW = '/^(\d+)$/';

    // match against => B:D
    private const PATTERN_COLUMN_RANGE = '/^([a-zA-Z]+):([a-zA-Z]+)$/';

    // match against => B (single string/column value)
    private const PATTERN_SINGLE_COLUMN = '/^([a-zA-Z]+)$/';

    // match against => 2:B
    private const PATTERN_ROW_COLON_COLUMN = '/^(\d+):([a-zA-Z]+)$/';

    // match against => B:24
    private const PATTERN_COLUMN_COLON_ROW = '/^([a-zA-Z]+):(\d+)$/';

    // match against => B2:24
    private const PATTERN_CELL_COLON_ROW = '/^([a-zA-Z]+)(\d+):(\d+)$/';

    // match against => B2:D
    private const PATTERN_CELL_COLON_COLUMN = '/^([a-zA-Z]+)(\d+):([a-zA-Z]+)$/';

    // match against => 2:D24
    private const PATTERN_ROW_COLON_CELL = '/^(\d+):([a-zA-Z]+)(\d+)$/';

    // match against => B:D24
    private const PATTERN_COLUMN_COLON_CELL = '/^([a-zA-Z]+):([a-zA-Z]+)(\d+)$/';

    /**
     * @throws SpreadsheetException
     */
    public function convert(Worksheet $sheet, string $range): string
    {
        if (
            $sheet->getHighestColumn() === 'A'
            && $sheet->getHighestRow() === 1
            && $sheet->cellExists('A1') === false
        ) {
            return '';
        }

        if (preg_match(self::PATTERN_ROW_RANGE, $range, $matches) === 1) {
            // return range => A2:D24 (if highest Column is D)
            $range = $this->buildRange('A', (int)$matches[1], $sheet->getHighestColumn(), (int)$matches[2]);
        } elseif (preg_match(self::PATTERN_SINGLE_ROW, $range, $matches) === 1) {
            // return range => A2:D2 (if highest Column is D)
            $range = $this->buildRange('A', (int)$matches[1], $sheet->getHighestColumn(), (int)$matches[1]);
        } elseif (preg_match(self::PATTERN_COLUMN_RANGE, $range, $matches) === 1) {
            // return range => B1:D24 (if highest row is 24)
            $range = $this->buildRange($matches[1], 1, $matches[2], (int)$sheet->getHighestRow());
        } elseif (preg_match(self::PATTERN_SINGLE_COLUMN, $range, $matches) === 1) {
            // return range => B1:B24 (if highest row is 24)
            $range = $this->buildRange($matches[1], 1, $matches[1], (int)$sheet->getHighestRow());
        } elseif (preg_match(self::PATTERN_ROW_COLON_COLUMN, $range, $matches) === 1) {
            // return single cell => B2
            $range = $this->buildRange($matches[2], (int)$matches[1]);
        } elseif (preg_match(self::PATTERN_COLUMN_COLON_ROW, $range, $matches) === 1) {
            // return single cell => B24
            $range = $this->buildRange($matches[1], (int)$matches[2]);
        } elseif (preg_match(self::PATTERN_CELL_COLON_ROW, $range, $matches) === 1) {
            // return range => B2:B24 (cause first part sets column)
            $range = $this->buildRange($matches[1], (int)$matches[2], $matches[1], (int)$matches[3]);
        } elseif (preg_match(self::PATTERN_CELL_COLON_COLUMN, $range, $matches) === 1) {
            // return range => B2:D2 (cause first part sets row)
            $range = $this->buildRange($matches[1], (int)$matches[2], $matches[3], (int)$matches[2]);
        } elseif (preg_match(self::PATTERN_ROW_COLON_CELL, $range, $matches) === 1) {
            // return range => D2:D24 (cause second part sets column)
            $range = $this->buildRange($matches[2], (int)$matches[1], $matches[2], (int)$matches[3]);
        } elseif (preg_match(self::PATTERN_COLUMN_COLON_CELL, $range, $matches) === 1) {
            // return range => B24:D24 (cause second part sets row)
            $range = $this->buildRange($matches[1], (int)$matches[3], $matches[2], (int)$matches[3]);
        }

        return $sheet->shrinkRangeToFit($range);
    }

    private function buildRange(
        string $startColumn,
        int $startRow,
        ?string $endColumn = null,
        ?int $endRow = null
    ): string {
        return sprintf('%s%d:%s%d', $startColumn, $startRow, $endColumn ?? $startColumn, $endRow ?? $startRow);
    }
}
