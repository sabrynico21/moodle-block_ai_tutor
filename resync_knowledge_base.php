<?php
/**
 * Knowledge Base re-sync page.
 * 
 * Triggers a manual re-sync of the Bedrock Knowledge Base data source.
 * This clears cached indexed content and re-processes all S3 files.
 *
 * @package    block_alma_ai_tutor
 * @copyright  2025 Universite TELUQ and the UNIVERSITE GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('classes/weaviate_connector.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/blocks/alma_ai_tutor/resync_knowledge_base.php');
$PAGE->set_title(get_string('resync_knowledge_base', 'block_alma_ai_tutor'));
$PAGE->set_heading(get_string('resync_knowledge_base', 'block_alma_ai_tutor'));

echo $OUTPUT->header();

// Get config
$region = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
$access_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
$secret_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
$kb_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
$model_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));
$data_source_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_data_source_id'));
$s3_bucket = trim((string)get_config('block_alma_ai_tutor', 'bedrock_s3_bucket'));

// Check if resync was requested
$action = optional_param('action', '', PARAM_TEXT);
$resync_result = null;
$resync_success = false;

if ($action === 'resync') {
    if (empty($region) || empty($access_key) || empty($secret_key) || empty($kb_id) || empty($data_source_id)) {
        $resync_result = 'ERROR: Missing required configuration (region, credentials, KB ID, or data source ID). Update plugin settings first.';
    } else {
        // Trigger resync
        $connector = new \block_alma_ai_tutor\weaviate_connector(
            $region, $access_key, $secret_key, $kb_id,
            !empty($model_id) ? $model_id : 'cohere.command-r-v1:0',
            $data_source_id, $s3_bucket
        );

        if ($connector->resync_knowledge_base()) {
            $resync_success = true;
            $resync_result = 'Success! Knowledge Base re-sync initiated. The indexing process will take 1-5 minutes. Your cached old file versions will be cleared and replaced with current S3 content.';
        } else {
            $resync_result = 'ERROR: ' . ($connector->get_last_error() ?: 'Unknown error during resync request.');
        }
    }
}

?>

<div style="max-width: 800px; margin: 20px auto;">
    
    <h2><?php echo get_string('resync_knowledge_base', 'block_alma_ai_tutor'); ?></h2>
    
    <p>
        <strong>Purpose:</strong> When you upload a file with the same name or if an old version is cached, 
        use this tool to force the Bedrock Knowledge Base to re-index all S3 files and clear outdated cached content.
    </p>
    
    <?php if ($resync_result): ?>
        <div class="alert <?php echo $resync_success ? 'alert-success' : 'alert-danger'; ?>" style="margin: 20px 0;">
            <strong><?php echo $resync_success ? 'Success' : 'Error'; ?>:</strong><br>
            <?php echo htmlspecialchars($resync_result); ?>
        </div>
    <?php endif; ?>
    
    <div style="border: 1px solid #ddd; padding: 15px; margin: 20px 0; background-color: #f9f9f9;">
        <h4>Current Configuration</h4>
        <table style="width: 100%;">
            <tr>
                <td style="width: 40%;"><strong>Region:</strong></td>
                <td><?php echo empty($region) ? '<span style="color: red;">NOT SET</span>' : htmlspecialchars($region); ?></td>
            </tr>
            <tr>
                <td><strong>Access Key:</strong></td>
                <td><?php echo empty($access_key) ? '<span style="color: red;">NOT SET</span>' : '****' . substr($access_key, -4); ?></td>
            </tr>
            <tr>
                <td><strong>Knowledge Base ID:</strong></td>
                <td><?php echo empty($kb_id) ? '<span style="color: red;">NOT SET</span>' : htmlspecialchars($kb_id); ?></td>
            </tr>
            <tr>
                <td><strong>Data Source ID:</strong></td>
                <td><?php echo empty($data_source_id) ? '<span style="color: red;">NOT SET</span>' : htmlspecialchars($data_source_id); ?></td>
            </tr>
            <tr>
                <td><strong>S3 Bucket:</strong></td>
                <td><?php echo empty($s3_bucket) ? '<span style="color: orange;">NOT SET</span>' : htmlspecialchars($s3_bucket); ?></td>
            </tr>
        </table>
    </div>
    
    <div style="margin: 20px 0;">
        <form method="get" style="display: inline;">
            <input type="hidden" name="action" value="resync">
            <button type="submit" class="btn btn-warning" style="padding: 10px 20px; font-size: 16px;">
                🔄 Force Re-sync Knowledge Base Now
            </button>
        </form>
    </div>
    
    <div style="background-color: #e8f4f8; padding: 15px; margin: 20px 0; border-left: 4px solid #0066cc;">
        <h4 style="margin-top: 0;">ℹ️ How to fix outdated retrieval results:</h4>
        <ol>
            <li><strong>Delete old files from S3 bucket</strong> (optional, but recommended)</li>
            <li><strong>Upload new/updated files</strong> via Moodle file upload</li>
            <li><strong>Click "Force Re-sync Knowledge Base Now"</strong> button above</li>
            <li><strong>Wait 1-5 minutes</strong> for re-indexing to complete</li>
            <li><strong>Test your chatbot question</strong> — you should now get the correct answer from the new file version</li>
        </ol>
    </div>
    
    <div style="background-color: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ff9800;">
        <h4 style="margin-top: 0;">⚠️ What this does:</h4>
        <ul>
            <li>Triggers a new ingestion job in AWS Bedrock</li>
            <li>Clears cached indexed content from old file versions</li>
            <li>Re-processes all S3 objects in the knowledge base data source</li>
            <li>Takes 1-5 minutes to complete</li>
            <li>Does not delete your S3 files (you can keep or remove them)</li>
        </ul>
    </div>
    
    <hr style="margin: 30px 0;">
    
    <p>
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'blocksettingchatbot']); ?>" class="btn btn-default">
            ← Back to Plugin Settings
        </a>
    </p>
</div>

<?php

echo $OUTPUT->footer();
