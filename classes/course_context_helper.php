<?php
/**
 * Helper to extract Moodle course/section context for the chatbot.
 *
 * @package    block_alma_ai_tutor
 */

namespace block_alma_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class course_context_helper {

    /**
     * Build a plain-text context string for a course section (or full course).
     *
     * @param int $courseid
     * @param int $sectionid  0 = full course context
     * @return string
     */

    public static function get_context_text(int $courseid, int $sectionid = 0): string {
        global $DB;

        $parts = [];

        // --- 1. Course name and description ---
        $course = $DB->get_record('course', ['id' => $courseid], 'fullname, summary');
        if ($course) {
            $parts[] = '=== Course: ' . $course->fullname . ' ===';
            $summary = self::clean_html($course->summary);
            if (!empty($summary)) {
                $parts[] = $summary;
            }
        }

        // --- 2. Get sections to process ---
        if ($sectionid > 0) {
            $sections = $DB->get_records(
                'course_sections',
                ['course' => $courseid, 'id' => $sectionid],
                'section ASC'
            );
        } else {
            $sections = $DB->get_records(
                'course_sections',
                ['course' => $courseid],
                'section ASC'
            );
        }

        if (empty($sections)) {
            return implode("\n\n", $parts);
        }

        foreach ($sections as $section) {
            $section_parts = [];

            // Section title
            $section_name = !empty($section->name)
                ? $section->name
                : get_string('section') . ' ' . $section->section;
            $section_parts[] = '--- Section: ' . $section_name . ' ---';

            //Section description/summary
            $section_summary = self::clean_html($section->summary ?? '');
            if (!empty($section_summary)) {
                $section_parts[] = $section_summary;
            }

            // --- 3. Activities in this section ---
            if (!empty($section->sequence)) {
                $cmids = array_filter(explode(',', $section->sequence));
                $activity_texts = self::get_activities_text($courseid, $cmids);
                if (!empty($activity_texts)) {
                    $section_parts[] = $activity_texts;
                }
            }

            if (count($section_parts) > 1) {
                $parts[] = implode("\n", $section_parts);
            }
        }

        return implode("\n\n", $parts);
    }
    /**
     * Extract text content from activities in a section.
     *
     * @param int   $courseid
     * @param array $cmids  list of course module IDs (as strings from sequence)
     * @return string
     */

    private static function get_activities_text(int $courseid, array $cmids): string {
        global $DB;

        if (empty($cmids)) {
            return '';
        }

        // Load course modules with module type name
        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_QM);

        $sql = "SELECT cm.id, cm.instance, cm.visible, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id {$insql}
                   AND cm.course = ?
                   AND cm.visible = 1";
        $inparams[] = $courseid;
        $cms = $DB->get_records_sql($sql, $inparams);

        if (empty($cms)) {
            return '';
        }

        // Group by module type for batch queries
        $by_type = [];
        foreach ($cms as $cm) {
            $by_type[$cm->modname][] = $cm;
        }

        $lines = [];

        foreach ($by_type as $modname => $modcms) {
            $instances = array_column($modcms, 'instance');

            switch ($modname) {
                case 'page':
                    $lines[] = self::get_page_texts($instances);
                    break;

                case 'label':
                    $lines[] = self::get_label_texts($instances);
                    break;

                case 'assign':
                    $lines[] = self::get_generic_texts('assign', $instances, 'name', 'intro');
                    break;

                case 'quiz':
                    $lines[] = self::get_generic_texts('quiz', $instances, 'name', 'intro');
                    break;

                case 'forum':
                    $lines[] = self::get_generic_texts('forum', $instances, 'name', 'intro');
                    break;

                case 'url':
                    $lines[] = self::get_generic_texts('url', $instances, 'name', 'intro');
                    break;

                case 'folder':
                    $lines[] = self::get_generic_texts('folder', $instances, 'name', 'intro');
                    break;

                case 'resource':
                    $lines[] = self::get_generic_texts('resource', $instances, 'name', 'intro');
                    break;

                case 'book':
                    $lines[] = self::get_book_texts($instances);
                    break;

                default:
                    // For unknown module types, try to get at least name + intro
                    try {
                        $lines[] = self::get_generic_texts($modname, $instances, 'name', 'intro');
                    } catch (\Exception $e) {
                        // Table may not have these fields, skip silently
                    }
                    break;
            }
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * Get full content of Page resources.
     */
    private static function get_page_texts(array $ids): string {
        global $DB;
        list($sql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_QM);
        $records = $DB->get_records_select('page', "id {$sql}", $params, '', 'id, name, intro, content');
        $lines = [];
        foreach ( $records as $r) {
            $name = self::clean_html($r->name);
            $intro = self::clean_html($r->intro ?? '');
            $content = self::clean_html($r->content ?? '');
            $line = '[Page] ' . $name;
            if (!empty($intro)) $line .= "\n" . $intro;
            if (!empty($content)) $line .= "\n" . $content;
            $lines[] = $line;
        }
        return implode("\n\n", $lines);
    }

    /**
     * Get content of Label activities (pure HTML labels embedded in section).
     */
    private static function get_label_texts(array $ids): string {
        global $DB;

        list($sql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_QM);
        $records = $DB->get_records_select('label', "id {$sql}", $params, '', 'id, name, intro');
        $lines = [];
        foreach ($records as $r) {
            $content = self::clean_html($r->intro ?? $r->name ?? '');
            if (!empty($content)) {
                $lines[] = '[Label] ' . $content;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Get name + intro for any standard module table.
     */
    private static function get_generic_texts(string $table, array $ids, string $name_field, string $intro_field): string {
        global $DB;
        list($sql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_QM);
        $records = $DB->get_records_select($table, "id {$sql}", $params, '', "id, {$name_field}, {$intro_field}");
        $lines = [];
        foreach ($records as $r) {
            $name = self::clean_html($r->$name_field ?? '');
            $intro = self::clean_html($r->$intro_field ?? '');
            $label = strtoupper($table);
            $line = "[{$label}] " . $name;
            if (!empty($intro)) $line .= "\n" . $intro;
            $lines[] = $line;
        }
        return implode("\n\n", $lines);
    }

    /**
     * Get book chapters content.
     */
    private static function get_book_texts(array $ids): string {
        global $DB;
        list($sql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_QM);
        $books = $DB->get_records_select('book', "id {$sql}", $params, '', 'id, name, intro');

        $lines = [];
        foreach ($books as $book) {
            $line = '[Book] ' . self::clean_html($book->name);
            $intro = self::clean_html($book->intro ?? '');
            if (!empty($intro)) $line .= "\n" . $intro;

            // Get chapters
            $chapters = $DB->get_records(
                'book_chapters',
                ['bookid' => $book->id, 'hidden' => 0],
                'pagenum ASC',
                'id, title, content'
            );
            foreach ($chapters as $chapter) {
                $title = self::clean_html($chapter->title ?? '');
                $content = self::clean_html($chapter->content ?? '');
                if (!empty($title) || !empty($content)) {
                    $line .= "\n  [Chapter] " . $title;
                    if (!empty($content)) $line .= "\n  " . $content;
                }
            }
            $lines[] = $line;
        }
        return implode("\n\n", $lines);
    }
    /**
     * Strip HTML tags and decode entities, returning clean plain text.
     */
    private static function clean_html(string $html): string {
        if (empty($html)) return '';
        // Remove scripts and styles completely
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
        // Convert block-level tags to newlines before stripping
        $html = preg_replace('/<\/?(p|br|div|h[1-6]|li|tr|td|th)[^>]*>/i', "\n", $html);
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $html = preg_replace('/[ \t]+/', ' ', $html);
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        return trim($html);
    }
}