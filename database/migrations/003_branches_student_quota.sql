-- Business model update: unlimited branches, one centralized student-based subscription per academy.
-- Branches no longer consume licenses; packages are priced on total active unique students.
SET NAMES utf8mb4;

-- packages: custom billing durations, WhatsApp mode, branch limit is no longer part of normal pricing
ALTER TABLE packages
  MODIFY billing_cycle ENUM('monthly','quarterly','half_yearly','yearly','lifetime','custom') NOT NULL DEFAULT 'monthly',
  ADD COLUMN billing_days SMALLINT UNSIGNED NULL COMMENT 'length of a custom billing cycle in days' AFTER billing_cycle,
  ADD COLUMN whatsapp_mode ENUM('off','transactional','full') NOT NULL DEFAULT 'full' COMMENT 'off | transactional (receipts, attendance, manual) | full (+ automatic fee reminders)' AFTER monthly_whatsapp_limit;

UPDATE packages SET branch_limit = NULL;
DELETE FROM package_features WHERE feature_key = 'multi_branch';

-- branches: manager from the teacher list
ALTER TABLE academy_branches
  ADD COLUMN manager_teacher_id INT UNSIGNED NULL AFTER manager_name,
  ADD COLUMN email VARCHAR(160) NULL AFTER contact_number,
  ADD CONSTRAINT fk_br_manager FOREIGN KEY (manager_teacher_id) REFERENCES teachers(id);

-- branch-wise finance: invoices and payments record the branch they belong to
ALTER TABLE fee_invoices
  ADD COLUMN branch_id INT UNSIGNED NULL AFTER student_id,
  ADD KEY idx_inv_branch (academy_id, branch_id, status),
  ADD CONSTRAINT fk_inv_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id);
ALTER TABLE payments
  ADD COLUMN branch_id INT UNSIGNED NULL AFTER student_id,
  ADD KEY idx_pay_branch (academy_id, branch_id, paid_on),
  ADD CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id);
ALTER TABLE receipts
  ADD COLUMN branch_id INT UNSIGNED NULL AFTER student_id;

UPDATE fee_invoices i JOIN students s ON s.id = i.student_id SET i.branch_id = s.branch_id WHERE i.branch_id IS NULL;
UPDATE payments p JOIN students s ON s.id = p.student_id SET p.branch_id = s.branch_id WHERE p.branch_id IS NULL;
UPDATE receipts r JOIN payments p ON p.id = r.payment_id SET r.branch_id = p.branch_id WHERE r.branch_id IS NULL;

-- student quota is checked per academy: speed up the active-student count
ALTER TABLE students ADD KEY idx_student_active (academy_id, status);

-- sample student-based packages (Super Admin can edit or add unlimited packages)
UPDATE packages SET name = 'Starter', price_paise = 10000, student_limit = 15, teacher_limit = NULL, activity_limit = NULL, batch_limit = NULL,
  description = 'Up to 15 active students across all your branches.', whatsapp_mode = 'transactional' WHERE slug = 'starter';
INSERT IGNORE INTO packages (name, slug, description, price_paise, billing_cycle, trial_days, student_limit, storage_limit_mb, monthly_whatsapp_limit, whatsapp_mode, is_featured, sort_order)
VALUES ('Growth', 'growth', 'Up to 100 active students across unlimited branches, with automatic WhatsApp reminders.', 20000, 'monthly', 14, 100, 2048, 2000, 'full', 1, 2);
INSERT IGNORE INTO package_features (package_id, feature_key, enabled)
SELECT p.id, f.k, 1 FROM packages p JOIN (
  SELECT 'attendance' k UNION SELECT 'fees' UNION SELECT 'inapp' UNION SELECT 'push' UNION SELECT 'manual_reminders' UNION SELECT 'parent_access'
  UNION SELECT 'auto_reminders' UNION SELECT 'whatsapp' UNION SELECT 'email' UNION SELECT 'reports' UNION SELECT 'documents' UNION SELECT 'online_admission'
  UNION SELECT 'online_payments' UNION SELECT 'staff_management'
) f WHERE p.slug = 'growth';
UPDATE packages SET is_featured = 0, sort_order = 3, description = 'Configurable student limit, automation and reports for larger academies.' WHERE slug = 'professional';
UPDATE packages SET sort_order = 4, description = 'Custom student limit, advanced automation, API access and priority support.' WHERE slug = 'enterprise';
INSERT IGNORE INTO package_features (package_id, feature_key, enabled) SELECT id, 'whatsapp', 1 FROM packages WHERE slug = 'starter';

UPDATE website_settings SET setting_value = REPLACE(setting_value, '"Multiple branches', '"Unlimited branches') WHERE setting_key = 'features';
