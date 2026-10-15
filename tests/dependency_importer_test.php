<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas;

use tool_salasatipicas\local\dependency_importer;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression coverage for spreadsheet encoding and reference worksheets.
 *
 * @covers \tool_salasatipicas\local\dependency_importer
 */
final class dependency_importer_test extends \advanced_testcase {
    /** UTF-8 must remain unchanged; ANSI and BOM-marked Unicode must preserve accents. */
    public function test_excel_encodings_preserve_portuguese(): void {
        $text = "disciplina;professor;aluno\r\n\"Ética, Programação; AÇÃO e Extensão 3ª\";prof.exemplo;00001234567\r\n";
        $cases = [
            'UTF-8' => $text,
            'UTF-8 BOM' => "\xEF\xBB\xBF" . $text,
            'Windows-1252' => \core_text::convert($text, 'UTF-8', 'Windows-1252'),
            'ISO-8859-1' => \core_text::convert($text, 'UTF-8', 'ISO-8859-1'),
            'UTF-16LE' => "\xFF\xFE" . \core_text::convert($text, 'UTF-8', 'UTF-16LE'),
            'UTF-16BE' => "\xFE\xFF" . \core_text::convert($text, 'UTF-8', 'UTF-16BE'),
            'UTF-32LE' => "\xFF\xFE\x00\x00" . \core_text::convert($text, 'UTF-8', 'UTF-32LE'),
            'UTF-32BE' => "\x00\x00\xFE\xFF" . \core_text::convert($text, 'UTF-8', 'UTF-32BE'),
        ];
        $normalise = new \ReflectionMethod(dependency_importer::class, 'normalise_csv_encoding');
        $delimiter = new \ReflectionMethod(dependency_importer::class, 'detect_delimiter');
        foreach ($cases as $label => $bytes) {
            $converted = $normalise->invoke(null, $bytes);
            $this->assertSame($text, $converted, $label);
            $path = tempnam(make_request_directory(), 'encoding_test_');
            file_put_contents($path, $converted);
            try {
                $this->assertSame(';', $delimiter->invoke(null, $path), $label);
                $reader = new Csv();
                $reader->setReadDataOnly(true);
                $reader->setInputEncoding('UTF-8');
                $reader->setDelimiter(';');
                $book = $reader->load($path);
                try {
                    $this->assertSame('Ética, Programação; AÇÃO e Extensão 3ª',
                        $book->getSheet(0)->getCell('A2')->getValue(), $label);
                    $this->assertSame('00001234567', $book->getSheet(0)->getCell('C2')->getValue(), $label);
                } finally {
                    $book->disconnectWorksheets();
                }
            } finally {
                unlink($path);
            }
        }
    }

    /** Non-Western valid UTF-8 must never be passed through ANSI conversion. */
    public function test_valid_utf8_is_not_reencoded(): void {
        $method = new \ReflectionMethod(dependency_importer::class, 'normalise_csv_encoding');
        $text = "São João, ç, ~, ã, õ, 日本語, 😀\n";
        $this->assertSame($text, $method->invoke(null, $text));
    }

    /** An explicit UTF-8 BOM must not be overridden when the payload is invalid. */
    public function test_invalid_declared_utf8_is_rejected(): void {
        $method = new \ReflectionMethod(dependency_importer::class, 'normalise_csv_encoding');
        $this->expectException(\moodle_exception::class);
        $method->invoke(null, "\xEF\xBB\xBFPrograma\xE7\xE3o");
    }

    /** Damaged CSV is blocked before any definition or course creation. */
    public function test_replacement_character_is_rejected(): void {
        $path = tempnam(make_request_directory(), 'damaged_test_');
        file_put_contents($path, "processo;disciplina;professor;aluno;periodo;categoria\n1/2026;Programa�ão;prof.exemplo;aluno.exemplo;02/2026;1\n");
        try {
            try {
                dependency_importer::parse($path, 'damaged.csv');
                $this->fail('Damaged CSV text was not rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('dep_importdamagedtext', $exception->errorcode);
            }
        } finally {
            unlink($path);
        }
    }

    /** Semicolon, comma and tab detection must still work after normalization. */
    public function test_delimiters_after_normalization(): void {
        $method = new \ReflectionMethod(dependency_importer::class, 'detect_delimiter');
        foreach ([';', ',', "\t"] as $separator) {
            $path = tempnam(make_request_directory(), 'delimiter_test_');
            try {
                file_put_contents($path, implode($separator, ['processo', 'disciplina', 'professor']) . "\n");
                $this->assertSame($separator, $method->invoke(null, $path));
            } finally {
                unlink($path);
            }
        }
    }

    /** Opening Professores last must not change which worksheet gets imported. */
    public function test_named_import_sheet_overrides_active_reference_sheet(): void {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Dependencias');
        $book->getActiveSheet()->setCellValue('A1', 'Programa�ão');
        $book->createSheet()->setTitle('Professores');
        $book->setActiveSheetIndex(1);
        $book->getActiveSheet()->setCellValue('A1', 'Nome completo');
        $path = tempnam(make_request_directory(), 'sheet_test_');
        try {
            (new Xlsx($book))->save($path);
            try {
                dependency_importer::parse($path, 'model.xlsx');
                $this->fail('Damaged import text was not rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('dep_importdamagedtext', $exception->errorcode);
            }
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
