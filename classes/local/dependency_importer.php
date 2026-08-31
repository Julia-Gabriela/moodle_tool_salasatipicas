<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\local;

defined('MOODLE_INTERNAL') || die();

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * CSV/XLSX importer.
 *
 * @package tool_salasatipicas
 */
class dependency_importer {
    /** Maximum number of data rows per import in the production candidate. */
    public const MAX_ROWS = 50;

    /** Maximum accepted upload size (2 MiB). */
    public const MAX_FILE_BYTES = 2097152;

    /**
     * Parse and validate an uploaded spreadsheet.
     *
     * @param string $path Temporary file path.
     * @param string $filename Original filename.
     * @return array
     */
    public static function parse(string $path, string $filename): array {
        if (!is_file($path) || filesize($path) === false || filesize($path) > self::MAX_FILE_BYTES) {
            throw new \moodle_exception('dep_importfiletoolarge', 'tool_salasatipicas', '', display_size(self::MAX_FILE_BYTES));
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new \moodle_exception('dep_unsupportedfile', 'tool_salasatipicas');
        }

        if ($extension === 'csv') {
            $reader = new Csv();
            $reader->setReadDataOnly(true);
            $reader->setDelimiter(self::detect_delimiter($path));
            $reader->setEnclosure('"');
            $reader->setSheetIndex(0);
            $spreadsheet = $reader->load($path);
        } else {
            $spreadsheet = IOFactory::load($path, 0, [IOFactory::READER_XLSX]);
        }

        $rows = $spreadsheet->getActiveSheet()->toArray(null, false, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (!$rows) {
            throw new \moodle_exception('dep_emptyimport', 'tool_salasatipicas');
        }

        $headers = array_map([self::class, 'normalise_header'], array_shift($rows));
        $headerindex = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $headerindex[$header] = $index;
            }
        }

        $aliases = [
            'processo' => ['processo', 'numero_processo', 'n_processo', 'processnumber'],
            'disciplina' => ['disciplina', 'subject'],
            'professor' => ['professor', 'professores', 'professor_username', 'teacher', 'teacherusername'],
            'aluno' => ['aluno', 'alunos', 'aluno_username', 'student', 'studentusername'],
            'periodo' => ['periodo', 'semestre', 'period'],
            'categoria' => ['categoria', 'category'],
            'coordenacao' => ['coordenacao', 'coordenador', 'coordenadores', 'coordination'],
            'papel_professor' => ['papel_professor', 'papel_professores', 'role_professor', 'teacherrole'],
            'papel_coordenacao' => ['papel_coordenacao', 'role_coordenacao', 'coordinationrole'],
            'papel_aluno' => ['papel_aluno', 'papel_alunos', 'role_aluno', 'studentrole'],
        ];

        $positions = [];
        $requiredcolumns = ['processo', 'disciplina', 'professor', 'aluno', 'periodo', 'categoria'];
        foreach ($aliases as $canonical => $possible) {
            foreach ($possible as $candidate) {
                if (array_key_exists($candidate, $headerindex)) {
                    $positions[$canonical] = $headerindex[$candidate];
                    break;
                }
            }
            if (in_array($canonical, $requiredcolumns, true) && !isset($positions[$canonical])) {
                throw new \moodle_exception('dep_missingimportcolumn', 'tool_salasatipicas', $canonical);
            }
        }

        $preview = [];
        $seen = [];
        $datarowcount = 0;
        foreach ($rows as $offset => $row) {
            if (self::row_is_empty($row)) {
                continue;
            }
            $datarowcount++;
            if ($datarowcount > self::MAX_ROWS) {
                throw new \moodle_exception('dep_importrowlimit', 'tool_salasatipicas', self::MAX_ROWS);
            }

            $categoryraw = self::normalise_scalar($row[$positions['categoria']] ?? '');
            $category = dependency_manager::resolve_category_input($categoryraw);
            $teacherraw = self::normalise_scalar($row[$positions['professor']] ?? '');
            $studentraw = self::normalise_scalar($row[$positions['aluno']] ?? '');
            $coordinationraw = isset($positions['coordenacao']) ? self::normalise_scalar($row[$positions['coordenacao']] ?? '') : '';
            $periodraw = self::normalise_scalar($row[$positions['periodo']] ?? '');

            $teacherusernames = self::normalise_identifier_list($teacherraw);
            $studentusernames = self::normalise_identifier_list($studentraw);
            $coordinationusernames = self::normalise_identifier_list($coordinationraw);

            $defaultteacherroleid = (int)dependency_manager::get_config_value('teacherroleid', dependency_manager::role_id_by_shortname('editingteacher'));
            $defaultstudentroleid = (int)dependency_manager::get_config_value('studentroleid', dependency_manager::role_id_by_shortname('student'));
            $defaultcoordroleid = (int)dependency_manager::get_config_value('coordinationroleid', $defaultteacherroleid);
            $teacherroleinput = isset($positions['papel_professor']) ? self::normalise_scalar($row[$positions['papel_professor']] ?? '') : '';
            $studentroleinput = isset($positions['papel_aluno']) ? self::normalise_scalar($row[$positions['papel_aluno']] ?? '') : '';
            $coordroleinput = isset($positions['papel_coordenacao']) ? self::normalise_scalar($row[$positions['papel_coordenacao']] ?? '') : '';
            $teacherroleid = dependency_manager::resolve_role_input($teacherroleinput, $defaultteacherroleid);
            $studentroleid = dependency_manager::resolve_role_input($studentroleinput, $defaultstudentroleid);
            $coordinationroleid = dependency_manager::resolve_role_input($coordroleinput, $defaultcoordroleid);

            $raw = [
                'processnumber' => self::normalise_scalar($row[$positions['processo']] ?? ''),
                'subject' => self::normalise_scalar($row[$positions['disciplina']] ?? ''),
                'teacherusernames' => $teacherusernames,
                'studentusernames' => $studentusernames,
                'coordinationusernames' => $coordinationusernames,
                'teacherroleid' => $teacherroleid,
                'studentroleid' => $studentroleid,
                'coordinationroleid' => $coordinationroleid,
                'period' => dependency_manager::normalise_period($periodraw),
                'categoryconfigid' => $category ? (int)$category->id : 0,
                'categoryinput' => $categoryraw,
            ];

            $errors = [];
            if (!$category) {
                $errors[] = get_string('dep_importcategorynotfound', 'tool_salasatipicas', $categoryraw);
            }
            if ($teacherroleid <= 0) {
                $errors[] = get_string('dep_importrolenotfound', 'tool_salasatipicas', $teacherroleinput);
            }
            if ($studentroleid <= 0) {
                $errors[] = get_string('dep_importrolenotfound', 'tool_salasatipicas', $studentroleinput);
            }
            if ($coordinationusernames && $coordinationroleid <= 0) {
                $errors[] = get_string('dep_importrolenotfound', 'tool_salasatipicas', $coordroleinput);
            }
            $prepared = dependency_manager::prepare_definition($raw);
            $errors = array_merge($errors, $prepared['errors']);
            $definition = $prepared['definition'];

            // A common Excel mistake is converting an 11-digit CPF-like username to a number
            // and dropping a leading zero. Never guess the missing digit; give a precise hint.
            if (!$definition) {
                foreach ($studentusernames as $studentidentifier) {
                    if (!preg_match('/^\d{1,10}$/', $studentidentifier)) {
                        continue;
                    }
                    foreach ($errors as &$error) {
                        if (strpos($error, $studentidentifier) !== false) {
                            $error .= ' ' . get_string('dep_possibleleadingzero', 'tool_salasatipicas');
                            break 2;
                        }
                    }
                    unset($error);
                }
            }

            if ($definition && isset($seen[$definition['targetshortname']])) {
                $errors[] = get_string('dep_duplicateinfile', 'tool_salasatipicas', $definition['targetshortname']);
            }
            if ($definition) {
                $seen[$definition['targetshortname']] = true;
            }

            $preview[] = [
                'line' => $offset + 2,
                'raw' => $raw,
                'valid' => empty($errors),
                'errors' => $errors,
                'fullname' => $definition['targetfullname'] ?? '',
                'shortname' => $definition['targetshortname'] ?? '',
            ];
        }

        if ($datarowcount === 0) {
            throw new \moodle_exception('dep_emptyimport', 'tool_salasatipicas');
        }

        return [
            'source' => $extension,
            'rows' => $preview,
        ];
    }

    /**
     * Get the first file name from a draft item id.
     *
     * @param int $draftitemid
     * @param int $userid
     * @return string
     */
    public static function draft_filename(int $draftitemid, int $userid): string {
        $fs = get_file_storage();
        $context = \context_user::instance($userid);
        $files = $fs->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id ASC', false);
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return $file->get_filename();
    }

    private static function normalise_header($value): string {
        $value = trim((string)$value);
        $value = \core_text::strtolower(\core_text::specialtoascii($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim($value, '_');
    }

    /** Normalise a generic spreadsheet scalar without inventing data. */
    private static function normalise_scalar($value): string {
        if ($value === null) {
            return '';
        }
        if (is_float($value) && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }
        return trim((string)$value);
    }

    /**
     * Normalise a username-like identifier. Scientific notation is expanded when safe,
     * but leading zeroes cannot be reconstructed and are never guessed.
     */
    private static function normalise_identifier($value): string {
        $value = self::normalise_scalar($value);
        if (preg_match('/^([0-9]+)\.0+$/', $value, $m)) {
            return $m[1];
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?[Ee][+-]?[0-9]+$/', $value)) {
            $number = (float)$value;
            if (is_finite($number) && $number >= 0 && $number <= 999999999999999) {
                return sprintf('%.0f', $number);
            }
        }
        return $value;
    }

    /** Split multiple usernames from one cell. Use | so CSV delimiters remain unambiguous. */
    private static function normalise_identifier_list($value): array {
        $value = self::normalise_scalar($value);
        if ($value === '') {
            return [];
        }
        $items = preg_split('/\s*\|\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        foreach ($items as $item) {
            $normalised = self::normalise_identifier($item);
            if ($normalised !== '') {
                $result[$normalised] = $normalised;
            }
        }
        return array_values($result);
    }

    private static function row_is_empty(array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function detect_delimiter(string $path): string {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return ';';
        }
        $line = fgets($handle) ?: '';
        fclose($handle);
        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ';';
    }
}
