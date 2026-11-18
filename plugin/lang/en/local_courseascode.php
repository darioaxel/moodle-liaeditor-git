<?php
// Strings del plugin (convención Moodle: inglés).

/**
 * Language strings.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Materiales Editables';
$string['courseascode:viewcatalog'] = 'View the editable materials catalog';
$string['courseascode:deploy'] = 'Publish and update editable materials';
$string['courseascode:manage'] = 'Manage Materiales Editables (system-wide)';
$string['courseascode:edit'] = 'Edit LiaScript materials in the LiaEditor';
$string['courseascode:editmain'] = 'Edit materials directly on the main branch';
$string['book_not_found'] = 'The book does not exist';
$string['chapter_not_found'] = 'The chapter stable id does not exist in this book';
$string['invalid_course'] = 'The course does not exist';
$string['invalid_deployed_at'] = 'deployed_at is not a valid ISO 8601 datetime';
$string['privacy:metadata:reason'] = 'The plugin stores deployment metadata only; personal data is managed by the courseascode service audit log.';
$string['servicenotconfigured'] = 'The courseascode service is not configured. Set $CFG->local_courseascode_service_url (see README).';
$string['servicerror'] = 'The courseascode service returned an error: {$a}';

// Material states (by SHA comparison, §5.3).
$string['state_up_to_date'] = 'Up to date';
$string['state_update_available'] = 'Update available';
$string['state_error'] = 'Error';

// LiaEditor: settings (LIA-012).
$string['settings_liaeditor_url'] = 'LiaEditor URL';
$string['settings_liaeditor_url_desc'] = 'Base URL of the self-hosted LiaEdit instance (e.g. https://liascript-editor.example.com).';
$string['settings_cors_origin'] = 'CORS origin for the editor';
$string['settings_cors_origin_desc'] = 'Origin allowed in content.php responses. If empty, the origin of the LiaEditor URL is used.';
$string['settings_token_ttl'] = 'Edit token lifetime (seconds)';
$string['settings_token_ttl_desc'] = 'One-time token lifetime for editor sessions. The editor renews it while working; default 900 (15 minutes).';
$string['settings_max_file_size'] = 'Maximum material size (bytes)';
$string['settings_max_file_size_desc'] = 'Markdown files larger than this are rejected by save.php; default 1048576 (1 MB).';

// LiaEditor: tokens and save flow (LIA-011/013/014/015).
$string['tokeninvalid'] = 'The edit token is invalid or was revoked. Reopen the material from Moodle.';
$string['tokenexpired'] = 'The edit token expired. Reopen the material from Moodle.';
$string['tokenmismatch'] = 'The edit token does not match this file and branch.';
$string['editornotconfigured'] = 'The LiaEditor is not configured. Set the LiaEditor URL in the plugin settings.';
$string['invalidfile'] = 'Invalid material path: {$a}';
$string['savedok'] = 'Changes saved to your editing branch.';
$string['saveconflict'] = 'The material changed on the server while you were editing. Your work is safe in a resolution branch.';

// LiaEditor: materials page (LIA-016/018).
$string['materials'] = 'Materials';
$string['materials_catalog'] = 'Master materials';
$string['materials_empty'] = 'No LiaScript materials found in the repository.';
$string['materials_minedit'] = 'My edits';
$string['materials_minedit_empty'] = 'You have no active editing sessions.';
$string['materials_published'] = 'Published in this course';
$string['materials_published_empty'] = 'No materials published in this course yet.';
$string['material_title'] = 'Title';
$string['material_file'] = 'Material';
$string['material_branch'] = 'Editing branch';
$string['material_last_save'] = 'Last save';
$string['material_last_sha'] = 'Last SHA';
$string['material_module'] = 'Course module';
$string['material_published_sha'] = 'Published SHA';
$string['material_state'] = 'State';
$string['material_preview'] = 'Preview';
$string['material_edit'] = 'Edit';
$string['material_continue'] = 'Continue editing';
$string['material_publish'] = 'Publish';
$string['material_update'] = 'Update';

// LiaEditor: publish page (LIA-017).
$string['publish_name'] = 'Module name';
$string['publish_type'] = 'Artifact type';
$string['publish_section'] = 'Course section';
$string['publish_file'] = 'Artifact file (.h5p / .epub / .pdf)';
$string['publishupdate'] = 'This material is already published in the course: submitting will update the existing module.';
$string['publishok'] = '«{$a}» published in the course and linked to the material version.';
$string['publishnosha'] = 'No known version of this material: save it from the editor first.';
$string['publishnofile'] = 'No artifact uploaded or the upload failed.';
$string['invalidartifacttype'] = 'Invalid artifact type: {$a}';
