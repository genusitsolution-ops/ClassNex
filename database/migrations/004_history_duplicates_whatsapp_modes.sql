-- 004: attendance history by branch, duplicate overrides, WhatsApp package modes, manual WhatsApp history.
-- Safe to run on existing data: only adds columns/tables and maps old values.

-- 1. Attendance sessions remember the branch they were taken at (history stays correct after transfers)
ALTER TABLE attendance_sessions ADD COLUMN branch_id INT UNSIGNED NULL AFTER batch_id,
  ADD KEY idx_as_branch_date (academy_id, branch_id, session_date),
  ADD CONSTRAINT fk_as_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id);
UPDATE attendance_sessions se JOIN batches b ON b.id = se.batch_id SET se.branch_id = b.branch_id WHERE se.branch_id IS NULL;
ALTER TABLE attendance ADD KEY idx_att_session_status (session_id, status);

-- 2. Faster student search for large academies
ALTER TABLE students ADD KEY idx_student_whatsapp (academy_id, whatsapp), ADD KEY idx_student_branch (academy_id, branch_id, status);

-- 3. WhatsApp package modes: off | manual (click-to-chat) | api (official Cloud API)
--    old 'full'          -> 'api'
--    old 'transactional' -> 'api' without the auto_reminders feature (same behaviour as before)
ALTER TABLE packages MODIFY whatsapp_mode ENUM('off','transactional','full','manual','api') NOT NULL DEFAULT 'api';
DELETE pf FROM package_features pf JOIN packages p ON p.id = pf.package_id WHERE p.whatsapp_mode = 'transactional' AND pf.feature_key = 'auto_reminders';
UPDATE packages SET whatsapp_mode = 'api' WHERE whatsapp_mode IN ('full','transactional');
ALTER TABLE packages MODIFY whatsapp_mode ENUM('off','manual','api') NOT NULL DEFAULT 'api';
DELETE pf FROM package_features pf JOIN packages p ON p.id = pf.package_id WHERE p.whatsapp_mode = 'off' AND pf.feature_key = 'whatsapp';

-- 4. Manual (click-to-chat) WhatsApp history
CREATE TABLE IF NOT EXISTS manual_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  recipient_name VARCHAR(120) NULL,
  purpose ENUM('fee_reminder','receipt','attendance','admission','announcement','custom') NOT NULL DEFAULT 'custom',
  ref_type VARCHAR(30) NULL,
  ref_id INT UNSIGNED NULL,
  message TEXT NOT NULL,
  status ENUM('prepared','opened') NOT NULL DEFAULT 'prepared' COMMENT 'opened = user tapped Send and WhatsApp was opened',
  batch_ref VARCHAR(40) NULL COMMENT 'groups messages prepared together (bulk reminders)',
  created_by INT UNSIGNED NULL,
  opened_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_academy (academy_id, created_at),
  KEY idx_mm_student (student_id),
  KEY idx_mm_batch (academy_id, batch_ref),
  CONSTRAINT fk_mm_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_mm_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed Starter package uses manual click-to-send WhatsApp — only on a fresh install
--    (never changes a package that already has subscribers)
UPDATE packages p SET p.whatsapp_mode = 'manual'
 WHERE p.slug = 'starter' AND NOT EXISTS (SELECT 1 FROM owner_subscriptions s WHERE s.package_id = p.id);
DELETE pf FROM package_features pf JOIN packages p ON p.id = pf.package_id
 WHERE p.slug = 'starter' AND p.whatsapp_mode = 'manual' AND pf.feature_key = 'auto_reminders';

-- 6. Business terminology: the customer's administrator is the "Class Admin" (role key stays 'owner')
UPDATE cms_pages SET content = REPLACE(content, 'The academy owner is responsible', 'The Class Admin (academy administrator) is responsible') WHERE slug = 'terms';
UPDATE website_settings SET setting_value = JSON_SET(setting_value, '$.operated_by', 'Genus IT Solution')
 WHERE setting_key = 'footer' AND JSON_VALID(setting_value) AND JSON_EXTRACT(setting_value, '$.operated_by') IS NULL;

-- 7. Academy registration with Super Admin approval
ALTER TABLE academies MODIFY status ENUM('pending','active','inactive','suspended','rejected') NOT NULL DEFAULT 'active',
  ADD COLUMN requested_package_id INT UNSIGNED NULL AFTER status,
  ADD COLUMN approved_at DATETIME NULL AFTER requested_package_id,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER approved_at,
  ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER approved_by;
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('registration_requires_approval', '1');
INSERT IGNORE INTO notification_templates (academy_id, event_key, channel, title, body, wa_template_name, wa_language, wa_params) VALUES
(NULL,'academy_approved','whatsapp','Academy approved','Your academy {academy_name} is approved on ClassNex. Log in: {link}','classnex_message','en','["message"]'),
(NULL,'academy_approved','email','Your ClassNex academy is approved','Hello {recipient_name},\n\nYour academy {academy_name} has been approved. You can log in now: {link}\n\nTeam ClassNex',NULL,NULL,NULL),
(NULL,'academy_approved','sms','Academy approved','ClassNex: {academy_name} is approved. Log in: {link}',NULL,NULL,NULL),
(NULL,'academy_rejected','whatsapp','Registration not approved','Your ClassNex registration for {academy_name} was not approved. {reason} Contact us for help.','classnex_message','en','["message"]'),
(NULL,'registration_pending','inapp','New academy registration','{academy_name} registered on the website and is waiting for your approval (Super Admin → Registration requests).',NULL,NULL,NULL),
(NULL,'academy_rejected','email','ClassNex registration','Hello {recipient_name},\n\nYour registration for {academy_name} was not approved. {reason}\n\nPlease reply to this email if you need help.\n\nTeam ClassNex',NULL,NULL,NULL);
