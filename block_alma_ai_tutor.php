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

            $this->title = $this->generate_instance_title();
        }

        // Keep the displayed title aligned with the block instance scope.
        //$this->title = $this->build_display_title();
    }

    private function generate_instance_title(): string
    {
        global $DB, $COURSE;

        $block_config = !empty($this->instance->configdata)
            ? unserialize(base64_decode($this->instance->configdata))
            : null;
        $saved_sectionid = ($block_config && isset($block_config->sectionid))
            ? (int)$block_config->sectionid
            : 0;

        $all_instances = $DB->get_records_sql(
            "SELECT bi.id
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid
              WHERE bi.blockname = 'alma_ai_tutor'
                AND ctx.contextlevel = :contextlevel
                AND ctx.instanceid   = :courseid
              ORDER BY bi.id ASC",
            [
                'contextlevel' => CONTEXT_COURSE,
                'courseid'     => $COURSE->id,
            ]
        );

        $position = 1;
        $counter = 0;
        foreach ($all_instances as $inst) {
            $inst_record = $DB->get_record('block_instances', ['id' => $inst->id]);
            $inst_config = !empty($inst_record->configdata)
                ? @unserialize(base64_decode($inst_record->configdata))
                : null;
            $inst_sectionid = ($inst_config && isset($inst_config->sectionid))
                ? (int)$inst_config->sectionid
                : 0;

            if ($inst_sectionid === $saved_sectionid) {
                $counter++;
                if ((int)$inst->id === (int)$this->instance->id) {
                    $position = $counter;
                }
            }
        }

        if ($saved_sectionid > 0) {
            $section = $DB->get_record('course_sections', ['id' => $saved_sectionid], 'name, section');
            if ($section) {
                $context_name = !empty($section->name)
                    ? $section->name
                    : get_string('section') . ' ' . $section->section;
            } else {
                $context_name = $COURSE->fullname;
            }
        } else {
            $context_name = $COURSE->fullname;
        }
 
        return 'AI Tutor ' . $position . ' - ' . $context_name;
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
        $this->title = $this->generate_instance_title();
        
        $existing_prompt = $DB->get_record(
            'block_alma_ai_tutor_prompts', 
            ['userid' => $USER->id, 'courseid' => $COURSE->id, 'instanceid' => $instanceid]
        );

        $existing_files_records = $DB->get_records(
            'block_alma_ai_tutor_files',
            [
                'courseid' => $COURSE->id,
                'sectionid' => $saved_sectionid,
                'instanceid' => $instanceid,
            ],
            'timecreated DESC',
            'id, filename, timecreated'
        );

        $existing_files = [];
        foreach ($existing_files_records as $f) {
            $existing_files[] = [
                'fileid' => (int)$f->id,
                'filename' => $f->filename,
                'timecreated' => userdate($f->timecreated),
            ];
        }

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
            'sesskey' => sesskey(),
            'existing_files' => $existing_files,
            'has_existing_files' =>!empty($existing_files),
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
            $instanceid,
            array_column($existing_files, 'filename'),
        ]);

        // Path to the CSS file within the plugin directory
        $cssFile = 'block_alma_ai_tutor/styles.css';

        // Add the CSS file to the page using Moodle's API
        $PAGE->requires->css(new moodle_url('/blocks/alma_ai_tutor/' . $cssFile));

        $this->content->footer = '';

        return $this->content;
    }

    /**
     * Called automatically by Moodle when this block instance is deleted.
     *
     * Removes all S3 files belonging to this instance from the Bedrock
     * Knowledge Base and cleans up all related DB records.
     *
     * @return bool
     */
    public function instance_delete() {
        global $DB, $CFG;

        $instanceid    = (int)$this->instance->id;
        $savedsectionid = $this->get_saved_sectionid();

        // Resolve course ID from the parent context.
        $parentcontext = \context::instance_by_id($this->instance->parentcontextid, IGNORE_MISSING);
        if ($parentcontext && $parentcontext->contextlevel == CONTEXT_COURSE) {
            $courseid = (int)$parentcontext->instanceid;
        } else {
            $courseid = (int)($this->page->course->id ?? 0);
        }

        // --- Delete files from S3 and trigger KB re-sync ---
        if ($courseid > 0) {
            $bedrock_region        = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
            $bedrock_access_key    = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
            $bedrock_secret_key    = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
            $bedrock_kb_id         = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
            $bedrock_model_id      = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));
            $bedrock_data_source_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_data_source_id'));
            $bedrock_s3_bucket     = trim((string)get_config('block_alma_ai_tutor', 'bedrock_s3_bucket'));
            $bedrock_rag_model_arn = trim((string)get_config('block_alma_ai_tutor', 'bedrock_rag_model_arn'));

            if (!empty($bedrock_region) && !empty($bedrock_access_key)
                    && !empty($bedrock_secret_key) && !empty($bedrock_kb_id)
                    && !empty($bedrock_data_source_id) && !empty($bedrock_s3_bucket)) {

                require_once($CFG->dirroot . '/blocks/alma_ai_tutor/classes/weaviate_connector.php');

                $connector = new \block_alma_ai_tutor\weaviate_connector(
                    $bedrock_region,
                    $bedrock_access_key,
                    $bedrock_secret_key,
                    $bedrock_kb_id,
                    !empty($bedrock_model_id) ? $bedrock_model_id : 'cohere.command-r-v1:0',
                    $bedrock_data_source_id,
                    $bedrock_s3_bucket,
                    $bedrock_rag_model_arn
                );

                $connector->delete_instance_files(
                    (string)$courseid,
                    (string)$savedsectionid,
                    (string)$instanceid
                );
            }
        }

        // --- Clean up all DB records for this instance ---
        $DB->delete_records('block_alma_ai_tutor_files',         ['instanceid' => $instanceid]);
        $DB->delete_records('block_alma_ai_tutor_conversations', ['instanceid' => $instanceid]);
        $DB->delete_records('block_alma_ai_tutor_chat_sessions', ['instanceid' => $instanceid]);
        $DB->delete_records('block_alma_ai_tutor_prompts',       ['instanceid' => $instanceid]);

        return true;
    }

}
