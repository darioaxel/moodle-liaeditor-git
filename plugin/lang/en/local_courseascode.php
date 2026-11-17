<?php
// Strings del plugin (convención Moodle: inglés).

/**
 * Language strings.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Course as Code';
$string['catalog'] = 'Catalog';
$string['bookversion'] = 'Book version';
$string['admin'] = 'Course as Code administration';
$string['courseascode:viewcatalog'] = 'View the Course as Code catalog';
$string['courseascode:deploy'] = 'Deploy and update Course as Code books';
$string['courseascode:manage'] = 'Manage Course as Code (system-wide)';
$string['courseascode:edit'] = 'Edit LiaScript materials in the LiaEditor';
$string['courseascode:editmain'] = 'Edit materials directly on the main branch';
$string['book_not_found'] = 'The book does not exist';
$string['chapter_not_found'] = 'The chapter stable id does not exist in this book';
$string['invalid_course'] = 'The course does not exist';
$string['invalid_deployed_at'] = 'deployed_at is not a valid ISO 8601 datetime';
$string['privacy:metadata:reason'] = 'The plugin stores deployment metadata only; personal data is managed by the courseascode service audit log.';
$string['preview'] = 'Preview';
$string['addtocourse'] = 'Add to course';
$string['chaptercount'] = 'Chapters';
$string['latest'] = 'Latest version';
$string['version'] = 'Version';
$string['versions'] = 'Versions';
$string['servicenotconfigured'] = 'The courseascode service is not configured. Set $CFG->local_courseascode_service_url (see README).';
$string['servicerror'] = 'The courseascode service returned an error: {$a}';

// Catalog page (§12.2).
$string['catalogempty'] = 'There are no sources visible for you in the catalog.';
$string['nobooksinsource'] = 'This source has no released books yet.';

// Book version page (§21) and plan (§17.1, §22).
$string['content'] = 'Content';
$string['publishedversion'] = 'Published version';
$string['availableversion'] = 'Available';
$string['gitcommit'] = 'Git commit';
$string['publishedat'] = 'Published';
$string['deployedby'] = 'Deployed by';
$string['deployed'] = 'Deployed';
$string['deploy'] = 'Deploy';
$string['update'] = 'Update';
$string['changes'] = 'Changes';
$string['nochanges'] = 'No content changes: the deployment is already up to date.';
$string['viewchanges'] = 'View changes';
$string['critical'] = 'CRITICAL';
$string['deletionswarning'] = 'This deployment will delete the following chapters from the Moodle book:';
$string['confirmdeletions'] = 'I understand that those chapters will be permanently deleted from this course.';
$string['deploysuccess'] = 'Version {$a} deployed successfully.';
$string['deployfailed'] = 'The deployment failed: {$a}';
$string['noversions'] = 'This book has no tagged versions.';

// Course panel (§23.1).
$string['state'] = 'State';
$string['actions'] = 'Actions';
$string['viewincourse'] = 'View in course';
$string['nobooksdeployed'] = 'This course has no Course as Code books yet.';
$string['gotocatalog'] = 'Go to the catalog';

// Deployment states (§19).
$string['state_not_deployed'] = 'Not deployed';
$string['state_up_to_date'] = 'Up to date';
$string['state_update_available'] = 'Update available';
$string['state_drift_detected'] = 'Manual changes detected';
$string['state_deploying'] = 'Deploying';
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
$string['publish_file'] = 'Artifact file (.zip / .h5p / .epub / .pdf)';
$string['publishupdate'] = 'This material is already published in the course: submitting will update the existing module.';
$string['publishok'] = '«{$a}» published in the course and linked to the material version.';
$string['publishnosha'] = 'No known version of this material: save it from the editor first.';
$string['publishnofile'] = 'No artifact uploaded or the upload failed.';
$string['invalidartifacttype'] = 'Invalid artifact type: {$a}';
