<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for block_alma_ai_tutor.
 *
 * @package    block_alma_ai_tutor
 * @copyright  2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alma_ai_tutor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for the chatbot block.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $items a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table(
            'block_alma_ai_tutor_conversations',
            [
                'userid' => 'privacy:metadata:block_alma_ai_tutor_conversations:userid',
                'question' => 'privacy:metadata:block_alma_ai_tutor_conversations:question',
                'answer' => 'privacy:metadata:block_alma_ai_tutor_conversations:answer',
                'timecreated' => 'privacy:metadata:block_alma_ai_tutor_conversations:timecreated',
                'courseid' => 'privacy:metadata:block_alma_ai_tutor_conversations:courseid',
                'sectionid' => 'privacy:metadata:block_alma_ai_tutor_conversations:sectionid',
                'instanceid' => 'privacy:metadata:block_alma_ai_tutor_conversations:instanceid',
                'sessionid' => 'privacy:metadata:block_alma_ai_tutor_conversations:sessionid',
            ],
            'privacy:metadata:block_alma_ai_tutor_conversations'
        );

        $items->add_database_table(
            'block_alma_ai_tutor_chat_sessions',
            [
                'userid' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:userid',
                'courseid' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:courseid',
                'sectionid' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:sectionid',
                'instanceid' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:instanceid',
                'title' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:title',
                'timecreated' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:timecreated',
                'timemodified' => 'privacy:metadata:block_alma_ai_tutor_chat_sessions:timemodified',
            ],
            'privacy:metadata:block_alma_ai_tutor_chat_sessions'
        );

        $items->add_database_table(
            'block_alma_ai_tutor_prompts',
            [
                'prompt' => 'privacy:metadata:block_alma_ai_tutor_prompts:prompt',
                'userid' => 'privacy:metadata:block_alma_ai_tutor_prompts:userid',
                'courseid' => 'privacy:metadata:block_alma_ai_tutor_prompts:courseid',
                'timecreated' => 'privacy:metadata:block_alma_ai_tutor_prompts:timecreated',
            ],
            'privacy:metadata:block_alma_ai_tutor_prompts'
        );

        // External service Amazon Bedrock Runtime (generation).
        $items->add_external_location_link(
            'bedrock_runtime',
            [
                'question' => 'privacy:metadata:bedrock_runtime:question',
                'courseid' => 'privacy:metadata:bedrock_runtime:courseid',
                'prompt' => 'privacy:metadata:bedrock_runtime:prompt',
            ],
            'privacy:metadata:bedrock_runtime'
        );

        // External service Amazon Bedrock Knowledge Base (vector database).
        $items->add_external_location_link(
            'bedrock_knowledge_base',
            [
                'document_content' => 'privacy:metadata:bedrock_knowledge_base:document_content',
                'embeddings' => 'privacy:metadata:bedrock_knowledge_base:embeddings',
                'courseid' => 'privacy:metadata:bedrock_knowledge_base:courseid',
                'metadata' => 'privacy:metadata:bedrock_knowledge_base:metadata',
            ],
            'privacy:metadata:bedrock_knowledge_base'
        );

        // External service Amazon Bedrock Data Automation.
        $items->add_external_location_link(
            'bedrock_data_automation',
            [
                'pdf_content' => 'privacy:metadata:bedrock_data_automation:pdf_content',
                'filename' => 'privacy:metadata:bedrock_data_automation:filename',
                'extracted_text' => 'privacy:metadata:bedrock_data_automation:extracted_text',
            ],
            'privacy:metadata:bedrock_data_automation'
        );
        return $items;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Get contexts where user has conversations.
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {block_alma_ai_tutor_conversations} bc ON bc.courseid = co.id
                 WHERE bc.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ]);

        // Get contexts where user has sessions.
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {block_alma_ai_tutor_chat_sessions} bs ON bs.courseid = co.id
                 WHERE bs.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ]);

        // Get contexts where user has prompts.
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {block_alma_ai_tutor_prompts} bp ON bp.courseid = co.id
                 WHERE bp.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        // Get users with conversations in this course.
        $sql = "SELECT bc.userid
                  FROM {block_alma_ai_tutor_conversations} bc
                 WHERE bc.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        // Get users with prompts in this course.
        $sql = "SELECT bp.userid
                  FROM {block_alma_ai_tutor_prompts} bp
                 WHERE bp.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        // Get users with sessions in this course.
        $sql = "SELECT bs.userid
              FROM {block_alma_ai_tutor_chat_sessions} bs
             WHERE bs.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            // Export conversations.
            $sql = "SELECT bc.question, bc.answer, bc.timecreated
                      FROM {block_alma_ai_tutor_conversations} bc
                     WHERE bc.userid = :userid AND bc.courseid = :courseid
                  ORDER BY bc.timecreated ASC";

            $conversations = $DB->get_records_sql($sql, [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);

            if (!empty($conversations)) {
                $conversationdata = [];
                foreach ($conversations as $conversation) {
                    $conversationdata[] = [
                        'question' => $conversation->question,
                        'answer' => $conversation->answer,
                        'timecreated' => \core_privacy\local\request\transform::datetime($conversation->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_alma_ai_tutor'), get_string('conversations', 'block_alma_ai_tutor')],
                    (object) ['conversations' => $conversationdata]
                );
            }

            // Export chat sessions.
            $sql = "SELECT bs.id, bs.sectionid, bs.instanceid, bs.title, bs.timecreated, bs.timemodified
                      FROM {block_alma_ai_tutor_chat_sessions} bs
                     WHERE bs.userid = :userid AND bs.courseid = :courseid
                  ORDER BY bs.timemodified ASC";

            $sessions = $DB->get_records_sql($sql, [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);

            if (!empty($sessions)) {
                $sessiondata = [];
                foreach ($sessions as $session) {
                    $sessiondata[] = [
                        'id' => $session->id,
                        'sectionid' => $session->sectionid,
                        'instanceid' => $session->instanceid,
                        'title' => $session->title,
                        'timecreated' => \core_privacy\local\request\transform::datetime($session->timecreated),
                        'timemodified' => \core_privacy\local\request\transform::datetime($session->timemodified),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_alma_ai_tutor'), get_string('sessions', 'block_alma_ai_tutor')],
                    (object) ['sessions' => $sessiondata]
                );
            }

            // Export prompts.
            $sql = "SELECT bp.prompt, bp.timecreated
                      FROM {block_alma_ai_tutor_prompts} bp
                     WHERE bp.userid = :userid AND bp.courseid = :courseid
                  ORDER BY bp.timecreated ASC";

            $prompts = $DB->get_records_sql($sql, [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);

            if (!empty($prompts)) {
                $promptdata = [];
                foreach ($prompts as $prompt) {
                    $promptdata[] = [
                        'prompt' => $prompt->prompt,
                        'timecreated' => \core_privacy\local\request\transform::datetime($prompt->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_alma_ai_tutor'), get_string('prompts', 'block_alma_ai_tutor')],
                    (object) ['prompts' => $promptdata]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $DB->delete_records('block_alma_ai_tutor_conversations', ['courseid' => $context->instanceid]);
        $DB->delete_records('block_alma_ai_tutor_prompts', ['courseid' => $context->instanceid]);
        $DB->delete_records('block_alma_ai_tutor_chat_sessions', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('block_alma_ai_tutor_conversations', [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);

            $DB->delete_records('block_alma_ai_tutor_prompts', [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);

            $DB->delete_records('block_alma_ai_tutor_chat_sessions', [
                'userid' => $user->id,
                'courseid' => $context->instanceid
            ]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->delete_records_select('block_alma_ai_tutor_conversations',
            "courseid = :courseid AND userid {$usersql}",
            array_merge(['courseid' => $context->instanceid], $userparams)
        );

        $DB->delete_records_select('block_alma_ai_tutor_prompts',
            "courseid = :courseid AND userid {$usersql}",
            array_merge(['courseid' => $context->instanceid], $userparams)
        );

        $DB->delete_records_select('block_alma_ai_tutor_chat_sessions',
            "courseid = :courseid AND userid {$usersql}",
            array_merge(['courseid' => $context->instanceid], $userparams)
        );
    }
}