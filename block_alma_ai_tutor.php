<?php

/**
 * Chatbot block class for Moodle.
 *
 * @package    block_alma_ai_tutor
 * @copyright  2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class block_alma_ai_tutor extends block_base
{
    public function init()
    {
        $this->title = get_string('pluginname', 'block_alma_ai_tutor');
    }

    /**
     * Build the chatbot display title for the current block scope.
     *
     * @param int|null $savedsectionid Persisted section scope from block config.
     * @return string
     */
    private function build_display_title($savedsectionid = null)
    {
        global $COURSE, $DB;

        $base = get_string('pluginname', 'block_alma_ai_tutor');

        if (empty($COURSE) || empty($COURSE->id)) {
            return $base;
        }

        if ($savedsectionid === null) {
            $savedsectionid = $this->get_saved_sectionid();
        }

        $savedsectionid = (int)$savedsectionid;
        if ($savedsectionid > 0) {
            // Prefer lookup by course_sections.id; fallback to section number for legacy values.
            $section = $DB->get_record(
                'course_sections',
                ['id' => $savedsectionid, 'course' => (int)$COURSE->id],
                'id, section, name'
            );

            if (!$section) {
                $section = $DB->get_record(
                    'course_sections',
                    ['course' => (int)$COURSE->id, 'section' => $savedsectionid],
                    'id, section, name'
                );
            }

            if ($section && trim((string)$section->name) !== '') {
                return $base . ' - ' . $section->name;
            }

            $fallbacksection = $section ? (int)$section->section : $savedsectionid;
            return $base . ' - Section ' . $fallbacksection;
        }

        return $base . ' - ' . $COURSE->fullname;
    }

    /**
     * Read the persisted section scope from this block instance config.
     *
     * @return int
     */
    private function get_saved_sectionid()
    {
        if (empty($this->instance->configdata)) {
            return 0;
        }

        $config = unserialize(base64_decode($this->instance->configdata));
        if ($config && isset($config->sectionid)) {
            return (int)$config->sectionid;
        }

        return 0;
    }

    public function applicable_formats()
    {
        return array(
            'course-view' => true, // Available only in courses.
            'site' => false, // Not available on the homepage.
            'mod' => false, // Not available in activities (modules).
            'my' => false, // Not available on the user dashboard.
        );
    }

    // Allow multiple instanes in the same course
    public function instance_allow_multiple()
    {
        return true; 
    }

    public function has_config()
    {
        return true;
    }

    public function specialization()
    {
        global $DB;

        if (!empty($this->instance->id)) {
            $current = $DB->get_record('block_instances', ['id' => $this->instance->id]);
            if ($current && empty($current->configdata)) {
                $sectionid_from_url = optional_param('id', 0, PARAM_INT);
                $config = new \stdClass();
                $config->sectionid = (strpos($this->page->pagetype, 'section') !== false)
                    ? $sectionid_from_url
                    : 0;
                $DB->set_field(
                    'block_instances', 
                    'configdata',
                    base64_encode(serialize($config)),
                    ['id' => $this->instance->id]
                );
            }
        }

        // Keep the displayed title aligned with the block instance scope.
        $this->title = $this->build_display_title();
    }

    public function get_content()
    {
        global $OUTPUT, $CFG, $USER, $COURSE, $DB, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;

        // Get the current course name
        $coursename = $PAGE->course->fullname;

        // Default prompt with dynamic course name
        $default_prompt = get_string('default_prompt', 'block_alma_ai_tutor', $coursename);

        // Get the current section from the URL
        $sectionid_from_url = optional_param('id', 0, PARAM_INT);
        $sectionid = (strpos($this->page->pagetype, 'section') !== false)
            ? $sectionid_from_url
            : 0;

        // Get the prompt for the separate instances of the block
        $instanceid = $this->instance->id;

        // Leggi il sectionid salvato nella configurazione del blocco (impostato alla creazione)
        $saved_sectionid = $this->get_saved_sectionid();

        // Re-apply in content render path to avoid stale default titles.
        $this->title = $this->build_display_title($saved_sectionid);
        
        $existing_prompt = $DB->get_record(
            'block_alma_ai_tutor_prompts', 
            ['userid' => $USER->id, 'courseid' => $COURSE->id, 'instanceid' => $instanceid]
        );

        // Check if the user is a teacher
        $coursecontext = context_course::instance($COURSE->id);
        $isteacher = has_capability('moodle/course:manageactivities', $coursecontext);

        // Prepare data for templates
        $templateData = [
            'has_prompt' => !empty($existing_prompt),
            'prompt_text' => $existing_prompt ? $existing_prompt->prompt : $default_prompt,
            'isteacher' => $isteacher,
            'isediting' => $PAGE->user_is_editing(),
            'wwwroot' => $CFG->wwwroot,
            'userid' => $USER->id,
            'courseid' => $COURSE->id,
            'sectionid' => $sectionid,
            'instanceid' => $instanceid,
            'analyticsurl' => (new moodle_url('/blocks/alma_ai_tutor/view_conversation_analytics.php', [
                'courseid' => $COURSE->id,
                'sectionid' => $saved_sectionid,
                'instanceid' => $instanceid,
            ]))->out(false),
            'sesskey' => sesskey()
        ];

        // Load templates
        $this->content->text = $OUTPUT->render_from_template('block_alma_ai_tutor/alma_ai_tutor', $templateData);
        $this->content->text .= $OUTPUT->render_from_template('block_alma_ai_tutor/prompt_modal', $templateData);
        $this->content->text .= $OUTPUT->render_from_template('block_alma_ai_tutor/load-course-modal', $templateData);

        $PAGE->requires->js_call_amd('block_alma_ai_tutor/alma_ai_tutor', 'init', [
            $CFG->wwwroot,
            sesskey(),
            $USER->id,
            $COURSE->id,
            $sectionid,
            $instanceid,
            $saved_sectionid,
        ]);
        $PAGE->requires->js_call_amd('block_alma_ai_tutor/fileupload', 'init', [
            $instanceid
        ]);

        // Path to the CSS file within the plugin directory
        $cssFile = 'block_alma_ai_tutor/styles.css';

        // Add the CSS file to the page using Moodle's API
        $PAGE->requires->css(new moodle_url('/blocks/alma_ai_tutor/' . $cssFile));

        $this->content->footer = '';

        return $this->content;
    }

}
