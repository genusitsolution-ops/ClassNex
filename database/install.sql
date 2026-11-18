-- ClassNex full installation SQL (MySQL 8+ / MariaDB 10.4+), generated 2026-11-18 from database/migrations/.
-- Import into an EMPTY database, then create the Super Admin: php cli/create-superadmin.php "Name" email mobile password
-- (or open install.php, which also writes config/config.php). Do NOT import this into a database that already has ClassNex tables:
-- for upgrades run php cli/migrate.php, which applies only the new migrations.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================ 001_core_schema
-- ClassNex core schema (MySQL 8+ / MariaDB 10.6+)
-- All money columns are stored as integer paise (BIGINT) to avoid floating point errors.
-- Every tenant-owned table carries academy_id and is filtered by it in the backend.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- platform
CREATE TABLE IF NOT EXISTS packages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  price_paise BIGINT NOT NULL DEFAULT 0,
  billing_cycle ENUM('monthly','quarterly','half_yearly','yearly','lifetime') NOT NULL DEFAULT 'monthly',
  trial_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  student_limit INT UNSIGNED NULL COMMENT 'NULL = unlimited',
  teacher_limit INT UNSIGNED NULL,
  activity_limit INT UNSIGNED NULL,
  batch_limit INT UNSIGNED NULL,
  branch_limit INT UNSIGNED NULL,
  storage_limit_mb INT UNSIGNED NULL,
  monthly_whatsapp_limit INT UNSIGNED NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_features (
  package_id INT UNSIGNED NOT NULL,
  feature_key VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, feature_key),
  CONSTRAINT fk_pf_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,
  code VARCHAR(12) NOT NULL UNIQUE COMMENT 'short prefix for IDs',
  slug VARCHAR(120) NOT NULL UNIQUE COMMENT 'public online-admission link',
  name VARCHAR(160) NOT NULL,
  logo_path VARCHAR(255) NULL,
  address TEXT NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  mobile VARCHAR(20) NULL,
  whatsapp VARCHAR(20) NULL,
  email VARCHAR(160) NULL,
  website VARCHAR(200) NULL,
  contact_person VARCHAR(120) NULL,
  gstin VARCHAR(20) NULL,
  business_details TEXT NULL,
  signature_path VARCHAR(255) NULL,
  receipt_footer VARCHAR(255) NULL,
  settings JSON NULL,
  online_admission_enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_acad_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users holds every login: super admins, owners, teachers, students and parents.
-- (super_admins / owners are represented by role, which keeps one auth system for web + Android.)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL COMMENT 'NULL for super admins',
  role ENUM('super_admin','owner','teacher','student','parent') NOT NULL,
  name VARCHAR(120) NOT NULL,
  country_code VARCHAR(5) NOT NULL DEFAULT '+91',
  mobile VARCHAR(15) NULL,
  email VARCHAR(160) NULL,
  password_hash VARCHAR(255) NULL COMMENT 'NULL until invitation is activated',
  photo_path VARCHAR(255) NULL,
  status ENUM('active','inactive','invited','suspended') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  mobile_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_mobile_role (country_code, mobile, role, academy_id),
  KEY idx_user_email (email),
  KEY idx_user_academy (academy_id, role),
  CONSTRAINT fk_user_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE academies ADD CONSTRAINT fk_acad_owner FOREIGN KEY (owner_user_id) REFERENCES users(id);

CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  client ENUM('web','android','api') NOT NULL DEFAULT 'android',
  device_name VARCHAR(120) NULL,
  ip VARCHAR(45) NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tok_user (user_id),
  CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('activation','password_reset') NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  fcm_token VARCHAR(255) NOT NULL UNIQUE,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dt_user (user_id),
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  role_key VARCHAR(30) PRIMARY KEY,
  name VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  perm_key VARCHAR(50) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  module VARCHAR(40) NOT NULL,
  is_financial TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_key VARCHAR(30) NOT NULL,
  perm_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (role_key, perm_key),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_key) REFERENCES roles(role_key) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_key) REFERENCES permissions(perm_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- per-user overrides (owner grants extra permissions to a specific teacher)
CREATE TABLE IF NOT EXISTS user_permissions (
  user_id INT UNSIGNED NOT NULL,
  perm_key VARCHAR(50) NOT NULL,
  granted TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, perm_key),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY (perm_key) REFERENCES permissions(perm_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NOT NULL,
  status ENUM('trial','active','grace','expired','suspended','cancelled') NOT NULL DEFAULT 'trial',
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  starts_on DATE NOT NULL,
  ends_on DATE NULL COMMENT 'NULL = lifetime',
  grace_until DATE NULL,
  price_paise BIGINT NOT NULL DEFAULT 0,
  billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
  custom_limits JSON NULL COMMENT 'per-owner limit overrides',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sub_academy (academy_id, is_current),
  KEY idx_sub_status (status, ends_on),
  CONSTRAINT fk_sub_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_sub_package FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NULL,
  package_id INT UNSIGNED NOT NULL,
  amount_paise BIGINT NOT NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'manual',
  reference VARCHAR(120) NULL,
  gateway_order_id VARCHAR(80) NULL UNIQUE,
  gateway_payment_id VARCHAR(80) NULL UNIQUE,
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  period_from DATE NULL,
  period_to DATE NULL,
  paid_at DATETIME NULL,
  recorded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sp_academy (academy_id),
  CONSTRAINT fk_sp_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_sp_package FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- structure
CREATE TABLE IF NOT EXISTS academy_branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  address TEXT NULL,
  contact_number VARCHAR(20) NULL,
  manager_name VARCHAR(120) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_br_academy (academy_id),
  CONSTRAINT fk_br_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teachers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  country_code VARCHAR(5) NOT NULL DEFAULT '+91',
  mobile VARCHAR(15) NOT NULL,
  email VARCHAR(160) NULL,
  photo_path VARCHAR(255) NULL,
  address TEXT NULL,
  specialization VARCHAR(160) NULL,
  joining_date DATE NULL,
  salary_type ENUM('none','monthly','per_class','per_student','other') NOT NULL DEFAULT 'none',
  salary_paise BIGINT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teacher_mobile (academy_id, mobile),
  CONSTRAINT fk_t_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_t_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_t_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  registration_fee_paise BIGINT NOT NULL DEFAULT 0,
  one_time_fee_paise BIGINT NOT NULL DEFAULT 0,
  monthly_fee_paise BIGINT NOT NULL DEFAULT 0,
  duration VARCHAR(60) NULL,
  instructor_id INT UNSIGNED NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_activity_name (academy_id, name),
  CONSTRAINT fk_a_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_a_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id),
  CONSTRAINT fk_a_teacher FOREIGN KEY (instructor_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  activity_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  teacher_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  capacity INT UNSIGNED NULL,
  location VARCHAR(160) NULL,
  monthly_fee_paise BIGINT NULL COMMENT 'NULL = use activity fee',
  status ENUM('active','inactive','completed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_batch_name (activity_id, name),
  KEY idx_batch_academy (academy_id, status),
  CONSTRAINT fk_b_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_b_activity FOREIGN KEY (activity_id) REFERENCES activities(id),
  CONSTRAINT fk_b_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id),
  CONSTRAINT fk_b_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 7=Sun',
  start_time TIME NULL,
  end_time TIME NULL,
  UNIQUE KEY uq_sched (batch_id, weekday),
  CONSTRAINT fk_bs_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- additional teachers on a batch (batches.teacher_id is the primary teacher)
CREATE TABLE IF NOT EXISTS teacher_batches (
  teacher_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, batch_id),
  CONSTRAINT fk_tb_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_tb_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- students/parents
CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL COMMENT 'login for adult students',
  student_code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  photo_path VARCHAR(255) NULL,
  country_code VARCHAR(5) NOT NULL DEFAULT '+91',
  whatsapp VARCHAR(15) NOT NULL,
  alt_mobile VARCHAR(15) NULL,
  email VARCHAR(160) NULL,
  dob DATE NULL,
  gender ENUM('male','female','other') NULL,
  is_minor TINYINT(1) NOT NULL DEFAULT 1,
  guardian_name VARCHAR(120) NULL,
  guardian_relation VARCHAR(30) NULL,
  address TEXT NULL,
  school_name VARCHAR(160) NULL,
  blood_group VARCHAR(5) NULL,
  medical_notes VARCHAR(255) NULL,
  emergency_contact VARCHAR(20) NULL,
  admission_date DATE NOT NULL,
  source ENUM('manual','online','import') NOT NULL DEFAULT 'manual',
  consent_given TINYINT(1) NOT NULL DEFAULT 0,
  consent_by VARCHAR(120) NULL,
  consent_at DATETIME NULL,
  whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  status ENUM('active','inactive','left') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_code (academy_id, student_code),
  KEY idx_student_name (academy_id, name),
  KEY idx_student_phone (academy_id, whatsapp),
  CONSTRAINT fk_s_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_s_branch FOREIGN KEY (branch_id) REFERENCES academy_branches(id),
  CONSTRAINT fk_s_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  country_code VARCHAR(5) NOT NULL DEFAULT '+91',
  whatsapp VARCHAR(15) NOT NULL,
  email VARCHAR(160) NULL,
  address TEXT NULL,
  occupation VARCHAR(120) NULL,
  whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_parent_phone (academy_id, country_code, whatsapp),
  CONSTRAINT fk_p_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_p_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parent_student_mapping (
  parent_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  relation VARCHAR(30) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (parent_id, student_id),
  KEY idx_psm_student (student_id),
  CONSTRAINT fk_psm_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
  CONSTRAINT fk_psm_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  doc_type ENUM('aadhaar','birth_certificate','photo_id','school_id','medical','other') NOT NULL DEFAULT 'other',
  title VARCHAR(120) NULL,
  storage_path VARCHAR(255) NOT NULL COMMENT 'encrypted file inside storage/private',
  original_name VARCHAR(200) NULL,
  mime VARCHAR(80) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  ocr_fields JSON NULL COMMENT 'fields confirmed by user (never stores Aadhaar number)',
  retention_until DATE NULL,
  uploaded_by INT UNSIGNED NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_student (student_id),
  CONSTRAINT fk_sd_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_sd_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- enrolment history; transfers close the old row and open a new one
CREATE TABLE IF NOT EXISTS student_activity_mapping (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  activity_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('active','transferred','completed','dropped') NOT NULL DEFAULT 'active',
  transferred_from_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sam_student (student_id, status),
  KEY idx_sam_batch (batch_id, status),
  CONSTRAINT fk_sam_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_sam_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_sam_activity FOREIGN KEY (activity_id) REFERENCES activities(id),
  CONSTRAINT fk_sam_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_name VARCHAR(120) NOT NULL,
  parent_name VARCHAR(120) NULL,
  country_code VARCHAR(5) NOT NULL DEFAULT '+91',
  whatsapp VARCHAR(15) NOT NULL,
  email VARCHAR(160) NULL,
  dob DATE NULL,
  address TEXT NULL,
  activity_id INT UNSIGNED NULL,
  batch_id INT UNSIGNED NULL,
  photo_path VARCHAR(255) NULL,
  document_path VARCHAR(255) NULL,
  message TEXT NULL,
  consent_given TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected','enquiry') NOT NULL DEFAULT 'pending',
  student_id INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_adm_academy (academy_id, status),
  CONSTRAINT fk_adm_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ attendance
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  session_date DATE NOT NULL,
  marked_by INT UNSIGNED NULL,
  is_holiday TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (batch_id, session_date),
  KEY idx_as_academy_date (academy_id, session_date),
  CONSTRAINT fk_as_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_as_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status ENUM('present','absent','late','leave','holiday') NOT NULL,
  remarks VARCHAR(160) NULL,
  marked_by INT UNSIGNED NULL,
  notified_status VARCHAR(10) NULL COMMENT 'last status a notification was sent for',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (session_id, student_id),
  KEY idx_att_student (student_id),
  CONSTRAINT fk_att_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(10) NOT NULL,
  new_status VARCHAR(10) NOT NULL,
  reason VARCHAR(255) NULL,
  corrected_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ac_att FOREIGN KEY (attendance_id) REFERENCES attendance(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  title VARCHAR(120) NOT NULL,
  batch_id INT UNSIGNED NULL COMMENT 'NULL = whole academy',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hol (academy_id, holiday_date),
  CONSTRAINT fk_hol_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  audience ENUM('all','students','teachers','batch') NOT NULL DEFAULT 'all',
  batch_id INT UNSIGNED NULL,
  publish_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notify TINYINT(1) NOT NULL DEFAULT 1,
  notified_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ann (academy_id, publish_at),
  CONSTRAINT fk_ann_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_leaves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tl_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_tl_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- fees
CREATE TABLE IF NOT EXISTS fee_structures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  activity_id INT UNSIGNED NULL,
  batch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  fee_type ENUM('admission','registration','one_time','recurring','additional') NOT NULL,
  frequency_months TINYINT UNSIGNED NULL COMMENT '1,3,6,12 for recurring; NULL one-off',
  amount_paise BIGINT NOT NULL,
  due_day TINYINT UNSIGNED NOT NULL DEFAULT 5,
  late_fee_paise BIGINT NOT NULL DEFAULT 0,
  late_fee_after_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  auto_assign TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'assign automatically on enrolment',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fs_academy (academy_id, status),
  CONSTRAINT fk_fs_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_fs_activity FOREIGN KEY (activity_id) REFERENCES activities(id),
  CONSTRAINT fk_fs_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('discount','scholarship','concession') NOT NULL DEFAULT 'discount',
  value_type ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  value INT UNSIGNED NOT NULL COMMENT 'percent x100 (e.g. 1050 = 10.5%) or paise',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_disc_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_fee_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  fee_structure_id INT UNSIGNED NULL,
  enrollment_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  fee_type ENUM('admission','registration','one_time','recurring','additional') NOT NULL,
  frequency_months TINYINT UNSIGNED NULL,
  amount_paise BIGINT NOT NULL COMMENT 'student-level amount (overrides structure)',
  discount_id INT UNSIGNED NULL,
  discount_paise BIGINT NOT NULL DEFAULT 0 COMMENT 'flat discount per invoice',
  due_day TINYINT UNSIGNED NOT NULL DEFAULT 5,
  late_fee_paise BIGINT NOT NULL DEFAULT 0,
  late_fee_after_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  next_period_start DATE NULL,
  prorate_first TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','paused','stopped','completed') NOT NULL DEFAULT 'active',
  paused_until DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sfa_student (student_id, status),
  KEY idx_sfa_next (status, next_period_start),
  CONSTRAINT fk_sfa_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_sfa_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_sfa_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id),
  CONSTRAINT fk_sfa_discount FOREIGN KEY (discount_id) REFERENCES discounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  assignment_id INT UNSIGNED NULL,
  invoice_no VARCHAR(40) NOT NULL,
  title VARCHAR(160) NOT NULL,
  period_start DATE NULL,
  period_end DATE NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  subtotal_paise BIGINT NOT NULL DEFAULT 0,
  discount_paise BIGINT NOT NULL DEFAULT 0,
  late_fee_paise BIGINT NOT NULL DEFAULT 0,
  total_paise BIGINT NOT NULL DEFAULT 0,
  paid_paise BIGINT NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','paid','cancelled','waived') NOT NULL DEFAULT 'unpaid',
  dedupe_key VARCHAR(80) NULL COMMENT 'assignment+period; stops duplicate billing',
  cancelled_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_no (academy_id, invoice_no),
  UNIQUE KEY uq_invoice_dedupe (academy_id, dedupe_key),
  KEY idx_inv_student (student_id, status),
  KEY idx_inv_due (academy_id, status, due_date),
  CONSTRAINT fk_inv_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_inv_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_inv_assign FOREIGN KEY (assignment_id) REFERENCES student_fee_assignments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  item_type ENUM('fee','discount','late_fee','adjustment') NOT NULL DEFAULT 'fee',
  description VARCHAR(200) NOT NULL,
  amount_paise BIGINT NOT NULL COMMENT 'negative for discounts',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fii_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  amount_paise BIGINT NOT NULL,
  refunded_paise BIGINT NOT NULL DEFAULT 0,
  method ENUM('cash','upi','bank_transfer','online','cheque','card','other') NOT NULL,
  reference VARCHAR(120) NULL,
  paid_on DATE NOT NULL,
  notes VARCHAR(255) NULL,
  status ENUM('success','pending','failed','reversed') NOT NULL DEFAULT 'success',
  idempotency_key VARCHAR(64) NOT NULL,
  gateway VARCHAR(30) NULL,
  gateway_order_id VARCHAR(80) NULL,
  gateway_payment_id VARCHAR(80) NULL,
  collected_by INT UNSIGNED NULL,
  reversed_by INT UNSIGNED NULL,
  reversed_at DATETIME NULL,
  reversal_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_idem (academy_id, idempotency_key),
  UNIQUE KEY uq_pay_gateway (gateway, gateway_payment_id),
  KEY idx_pay_student (student_id),
  KEY idx_pay_date (academy_id, paid_on),
  CONSTRAINT fk_pay_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- append-only ledger: reversals and refunds insert negative rows, nothing is edited
CREATE TABLE IF NOT EXISTS payment_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount_paise BIGINT NOT NULL,
  reason ENUM('payment','advance_applied','reversal','refund') NOT NULL DEFAULT 'payment',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pa_payment (payment_id),
  KEY idx_pa_invoice (invoice_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_pa_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL,
  amount_paise BIGINT NOT NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'cash',
  reference VARCHAR(120) NULL,
  reason VARCHAR(255) NOT NULL,
  refunded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_ref_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL UNIQUE COMMENT 'one receipt per payment',
  receipt_no VARCHAR(40) NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  amount_paise BIGINT NOT NULL,
  balance_after_paise BIGINT NOT NULL DEFAULT 0,
  status ENUM('valid','cancelled') NOT NULL DEFAULT 'valid',
  public_token CHAR(32) NOT NULL UNIQUE COMMENT 'unguessable share link',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_no (academy_id, receipt_no),
  CONSTRAINT fk_rc_academy FOREIGN KEY (academy_id) REFERENCES academies(id),
  CONSTRAINT fk_rc_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_rc_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequences (
  academy_id INT UNSIGNED NOT NULL DEFAULT 0,
  seq_name VARCHAR(40) NOT NULL,
  next_value INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (academy_id, seq_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_gateway_configs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL COMMENT 'NULL = platform (subscriptions)',
  provider VARCHAR(30) NOT NULL DEFAULT 'razorpay',
  key_id VARCHAR(120) NULL,
  key_secret_enc TEXT NULL,
  webhook_secret_enc TEXT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pg (academy_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gateway_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL,
  purpose ENUM('fee','subscription') NOT NULL,
  ref_id INT UNSIGNED NOT NULL COMMENT 'student_id or subscription_payment id',
  invoice_ids VARCHAR(255) NULL,
  provider VARCHAR(30) NOT NULL,
  order_id VARCHAR(80) NOT NULL UNIQUE,
  amount_paise BIGINT NOT NULL,
  status ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  payment_id VARCHAR(80) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- notifications
CREATE TABLE IF NOT EXISTS notification_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL COMMENT 'NULL = platform default',
  event_key VARCHAR(50) NOT NULL,
  channel ENUM('whatsapp','push','inapp','email','sms') NOT NULL,
  title VARCHAR(160) NULL,
  body TEXT NOT NULL,
  wa_template_name VARCHAR(120) NULL COMMENT 'approved Meta template name',
  wa_language VARCHAR(10) NULL DEFAULT 'en',
  wa_params JSON NULL COMMENT 'ordered list of variable names for {{1}},{{2}}..',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl (academy_id, event_key, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
  academy_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(50) NOT NULL,
  channel ENUM('whatsapp','push','inapp','email','sms') NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (academy_id, event_key, channel),
  CONSTRAINT fk_np_academy FOREIGN KEY (academy_id) REFERENCES academies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- in-app inbox
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NULL,
  event_key VARCHAR(50) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  data JSON NULL,
  dedupe_key VARCHAR(120) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_n_dedupe (user_id, dedupe_key),
  KEY idx_n_user (user_id, read_at),
  CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL,
  event_key VARCHAR(50) NOT NULL,
  channel ENUM('whatsapp','push','email','sms') NOT NULL,
  recipient VARCHAR(255) NOT NULL COMMENT 'E.164 phone, email, or user id for push',
  recipient_name VARCHAR(120) NULL,
  user_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  subject VARCHAR(200) NULL,
  body TEXT NOT NULL,
  payload JSON NULL,
  priority TINYINT NOT NULL DEFAULT 5,
  status ENUM('pending','processing','sent','delivered','read','failed','cancelled','paused') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  locked_by VARCHAR(40) NULL,
  locked_at DATETIME NULL,
  provider_message_id VARCHAR(160) NULL,
  last_error VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_q_pick (status, next_attempt_at, priority),
  KEY idx_q_academy (academy_id, created_at),
  KEY idx_q_provider (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_id BIGINT UNSIGNED NULL,
  academy_id INT UNSIGNED NULL,
  channel VARCHAR(20) NOT NULL,
  action VARCHAR(30) NOT NULL COMMENT 'attempt, success, error, webhook, simulated',
  http_status SMALLINT NULL,
  request_summary VARCHAR(500) NULL,
  response_body TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_nl_queue (queue_id),
  KEY idx_nl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_opt_outs (
  phone VARCHAR(20) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  opted_out_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source VARCHAR(30) NULL,
  PRIMARY KEY (phone, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_configurations (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  mode ENUM('off','test','live') NOT NULL DEFAULT 'test',
  business_account_id VARCHAR(60) NULL,
  phone_number_id VARCHAR(60) NULL,
  access_token_enc TEXT NULL,
  app_secret_enc TEXT NULL,
  api_version VARCHAR(10) NOT NULL DEFAULT 'v21.0',
  webhook_verify_token VARCHAR(120) NULL,
  rate_per_minute INT UNSIGNED NOT NULL DEFAULT 60,
  last_test_at DATETIME NULL,
  last_test_result VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS firebase_configurations (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  mode ENUM('off','test','live') NOT NULL DEFAULT 'test',
  project_id VARCHAR(120) NULL,
  service_account_enc MEDIUMTEXT NULL,
  last_test_result VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_configurations (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  mode ENUM('off','test','live') NOT NULL DEFAULT 'test',
  host VARCHAR(160) NULL,
  port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
  username VARCHAR(160) NULL,
  password_enc TEXT NULL,
  from_email VARCHAR(160) NULL,
  from_name VARCHAR(120) NULL DEFAULT 'ClassNex',
  last_test_result VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_configurations (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  mode ENUM('off','test','live') NOT NULL DEFAULT 'off',
  provider VARCHAR(40) NULL COMMENT 'generic HTTP gateway',
  endpoint_url VARCHAR(255) NULL,
  http_method ENUM('GET','POST') NOT NULL DEFAULT 'POST',
  params_template TEXT NULL COMMENT 'JSON with {to} {message} placeholders',
  api_key_enc TEXT NULL,
  sender_id VARCHAR(20) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL COMMENT 'NULL = platform default',
  offset_days SMALLINT NOT NULL COMMENT 'negative = before due date',
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_rr (academy_id, offset_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_reminders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  offset_days SMALLINT NOT NULL,
  scheduled_for DATE NOT NULL,
  status ENUM('pending','sent','skipped') NOT NULL DEFAULT 'pending',
  processed_at DATETIME NULL,
  UNIQUE KEY uq_sr (invoice_id, offset_days),
  KEY idx_sr_due (status, scheduled_for),
  CONSTRAINT fk_sr_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- platform support
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_academy (academy_id, created_at),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academy_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  scope ENUM('platform','academy') NOT NULL DEFAULT 'platform' COMMENT 'platform: owner->super admin; academy: parent->owner',
  subject VARCHAR(200) NOT NULL,
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_st (scope, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_str_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NULL,
  email VARCHAR(160) NULL,
  academy_name VARCHAR(160) NULL,
  category VARCHAR(80) NULL,
  message TEXT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'contact',
  status ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS website_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_pages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  content MEDIUMTEXT NULL,
  meta_title VARCHAR(160) NULL,
  meta_description VARCHAR(300) NULL,
  status ENUM('published','draft') NOT NULL DEFAULT 'published',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS testimonials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  designation VARCHAR(160) NULL,
  message TEXT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task VARCHAR(50) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','ok','error','skipped') NOT NULL DEFAULT 'running',
  summary VARCHAR(500) NULL,
  KEY idx_cr (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(120) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(80) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================ 002_core_seed
-- Reference data required by the application (safe to re-run)
SET NAMES utf8mb4;

INSERT IGNORE INTO roles (role_key, name) VALUES
('super_admin','Super Admin'),('owner','Class Owner'),('teacher','Teacher'),('student','Student'),('parent','Parent');

INSERT IGNORE INTO permissions (perm_key, name, module, is_financial) VALUES
('dashboard.view','View dashboard','dashboard',0),
('students.view','View students','students',0),
('students.manage','Add / edit students','students',0),
('documents.manage','Upload and view student documents','students',0),
('admissions.manage','Approve online admissions','students',0),
('teachers.manage','Manage teachers','teachers',0),
('structure.manage','Manage activities, batches, branches','structure',0),
('attendance.mark','Mark attendance','attendance',0),
('attendance.correct','Correct past attendance','attendance',0),
('fees.view','View fees and dues','fees',1),
('fees.manage','Configure fee plans and invoices','fees',1),
('fees.collect','Collect fee payments','fees',1),
('payments.reverse','Reverse or refund payments','fees',1),
('reports.view','View reports','reports',0),
('reports.financial','View financial reports','reports',1),
('notifications.send','Send reminders and announcements','notifications',0),
('settings.manage','Manage academy settings','settings',0);

-- Owners implicitly hold every permission. Teachers get these defaults; owner can grant more per teacher.
INSERT IGNORE INTO role_permissions (role_key, perm_key) VALUES
('teacher','dashboard.view'),('teacher','students.view'),('teacher','attendance.mark');

INSERT IGNORE INTO packages (id, name, slug, description, price_paise, billing_cycle, trial_days, student_limit, teacher_limit, activity_limit, batch_limit, branch_limit, storage_limit_mb, monthly_whatsapp_limit, is_featured, sort_order) VALUES
(1,'Starter','starter','For small classes getting organised.',49900,'monthly',14,75,3,3,6,1,500,0,0,1),
(2,'Professional','professional','Automated reminders, WhatsApp and reports for growing academies.',119900,'monthly',14,300,15,10,30,1,2048,2000,1,2),
(3,'Enterprise','enterprise','Multiple branches, advanced automation and priority support.',299900,'monthly',14,NULL,NULL,NULL,NULL,10,10240,10000,0,3);

INSERT IGNORE INTO package_features (package_id, feature_key, enabled) VALUES
(1,'attendance',1),(1,'fees',1),(1,'inapp',1),(1,'manual_reminders',1),(1,'parent_access',1),(1,'push',1),
(2,'attendance',1),(2,'fees',1),(2,'inapp',1),(2,'manual_reminders',1),(2,'parent_access',1),(2,'push',1),
(2,'auto_reminders',1),(2,'whatsapp',1),(2,'email',1),(2,'reports',1),(2,'documents',1),(2,'online_admission',1),(2,'online_payments',1),(2,'staff_management',1),
(3,'attendance',1),(3,'fees',1),(3,'inapp',1),(3,'manual_reminders',1),(3,'parent_access',1),(3,'push',1),
(3,'auto_reminders',1),(3,'whatsapp',1),(3,'email',1),(3,'sms',1),(3,'reports',1),(3,'advanced_reports',1),(3,'documents',1),
(3,'online_admission',1),(3,'online_payments',1),(3,'staff_management',1),(3,'custom_templates',1),(3,'multi_branch',1),(3,'api_access',1),(3,'priority_support',1);

INSERT IGNORE INTO reminder_rules (academy_id, offset_days, is_enabled) VALUES
(NULL,-7,1),(NULL,-3,1),(NULL,-1,1),(NULL,0,1),(NULL,3,1),(NULL,7,1);

-- Platform default templates. WhatsApp templates must be approved in Meta Business Manager
-- with the same name and the variables in the order given in wa_params.
INSERT IGNORE INTO notification_templates (academy_id, event_key, channel, title, body, wa_template_name, wa_language, wa_params) VALUES
(NULL,'admission_confirmation','whatsapp','Admission confirmed','Welcome {student_name}! Admission to {activity_name} at {academy_name} is confirmed. Student ID: {student_code}.','classnex_admission','en','["student_name","activity_name","academy_name","student_code"]'),
(NULL,'admission_confirmation','inapp','Admission confirmed','Welcome {student_name}! Admission to {activity_name} at {academy_name} is confirmed. Student ID: {student_code}.',NULL,NULL,NULL),
(NULL,'admission_confirmation','push','Admission confirmed','{student_name} is now enrolled in {activity_name}.',NULL,NULL,NULL),
(NULL,'admission_confirmation','email','Admission confirmed – {academy_name}','Dear {recipient_name},\n\nAdmission of {student_name} to {activity_name} at {academy_name} is confirmed.\nStudent ID: {student_code}\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'attendance_present','whatsapp','Attendance','{student_name} was marked Present today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_present','inapp','Present today','{student_name} was marked Present on {date}.',NULL,NULL,NULL),
(NULL,'attendance_present','push','Present today','{student_name} was marked Present on {date}.',NULL,NULL,NULL),
(NULL,'attendance_absent','whatsapp','Attendance','{student_name} was marked Absent today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_absent','inapp','Absent today','{student_name} was marked Absent on {date}.',NULL,NULL,NULL),
(NULL,'attendance_absent','push','Absent today','{student_name} was marked Absent on {date}.',NULL,NULL,NULL),
(NULL,'attendance_late','whatsapp','Attendance','{student_name} was marked Late today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_late','inapp','Late today','{student_name} was marked Late on {date}.',NULL,NULL,NULL),
(NULL,'attendance_late','push','Late today','{student_name} was marked Late on {date}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','inapp','Attendance updated','Attendance of {student_name} for {date} was updated to {status}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','push','Attendance updated','Attendance of {student_name} for {date} was updated to {status}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','whatsapp','Attendance updated','Attendance of {student_name} for {date} was updated to {status} at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'fee_due','whatsapp','Fee reminder','Dear Parent, the {fee_title} of {amount} for {student_name} is due on {due_date}. Please complete the payment. Thank you – {academy_name}.','classnex_fee_due','en','["fee_title","amount","student_name","due_date","academy_name"]'),
(NULL,'fee_due','inapp','Fee due on {due_date}','{fee_title} of {amount} for {student_name} is due on {due_date}.',NULL,NULL,NULL),
(NULL,'fee_due','push','Fee due on {due_date}','{fee_title} of {amount} for {student_name} is due on {due_date}.',NULL,NULL,NULL),
(NULL,'fee_due','email','Fee reminder – {student_name}','Dear {recipient_name},\n\nThe {fee_title} of {amount} for {student_name} is due on {due_date}.\nPlease complete the payment.\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'fee_due','sms','Fee reminder','{fee_title} of {amount} for {student_name} is due on {due_date}. -{academy_name}',NULL,NULL,NULL),
(NULL,'fee_overdue','whatsapp','Fee overdue','Dear Parent, the {fee_title} of {amount} for {student_name} was due on {due_date} and is still pending. Please pay at the earliest. – {academy_name}','classnex_fee_overdue','en','["fee_title","amount","student_name","due_date","academy_name"]'),
(NULL,'fee_overdue','inapp','Fee overdue','{fee_title} of {amount} for {student_name} was due on {due_date} and is pending.',NULL,NULL,NULL),
(NULL,'fee_overdue','push','Fee overdue','{fee_title} of {amount} for {student_name} is overdue.',NULL,NULL,NULL),
(NULL,'fee_overdue','email','Fee overdue – {student_name}','Dear {recipient_name},\n\nThe {fee_title} of {amount} for {student_name} was due on {due_date} and is still pending.\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'fee_overdue','sms','Fee overdue','{fee_title} of {amount} for {student_name} is overdue since {due_date}. -{academy_name}',NULL,NULL,NULL),
(NULL,'payment_success','whatsapp','Payment received','Payment of {amount} received for {student_name}. Receipt No: {receipt_no}. Balance: {balance}. Download: {receipt_link} – {academy_name}','classnex_payment','en','["amount","student_name","receipt_no","balance","receipt_link","academy_name"]'),
(NULL,'payment_success','inapp','Payment received','Payment of {amount} received for {student_name}. Receipt {receipt_no}. Balance: {balance}.',NULL,NULL,NULL),
(NULL,'payment_success','push','Payment received','{amount} received for {student_name}. Receipt {receipt_no}.',NULL,NULL,NULL),
(NULL,'payment_success','email','Payment receipt {receipt_no}','Dear {recipient_name},\n\nWe have received {amount} for {student_name}.\nReceipt No: {receipt_no}\nBalance: {balance}\nDownload receipt: {receipt_link}\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'batch_change','whatsapp','Batch changed','{student_name} has been moved to batch {batch_name} ({activity_name}) at {academy_name}.','classnex_general','en','["message"]'),
(NULL,'batch_change','inapp','Batch changed','{student_name} has been moved to batch {batch_name} ({activity_name}).',NULL,NULL,NULL),
(NULL,'batch_change','push','Batch changed','{student_name} moved to {batch_name}.',NULL,NULL,NULL),
(NULL,'class_cancelled','whatsapp','Class cancelled','{message}','classnex_general','en','["message"]'),
(NULL,'class_cancelled','inapp','Class cancelled','{message}',NULL,NULL,NULL),
(NULL,'class_cancelled','push','Class cancelled','{message}',NULL,NULL,NULL),
(NULL,'holiday','whatsapp','Holiday','{message}','classnex_general','en','["message"]'),
(NULL,'holiday','inapp','Holiday: {title}','{message}',NULL,NULL,NULL),
(NULL,'holiday','push','Holiday: {title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','whatsapp','Announcement','{message}','classnex_general','en','["message"]'),
(NULL,'announcement','inapp','{title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','push','{title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','email','{title}','{message}\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'announcement','sms','{title}','{message} -{academy_name}',NULL,NULL,NULL),
(NULL,'manual_message','whatsapp','Message','{message}','classnex_general','en','["message"]'),
(NULL,'manual_message','inapp','{title}','{message}',NULL,NULL,NULL),
(NULL,'manual_message','push','{title}','{message}',NULL,NULL,NULL),
(NULL,'manual_message','email','{title}','{message}\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'manual_message','sms','{title}','{message} -{academy_name}',NULL,NULL,NULL),
(NULL,'account_invite','whatsapp','Your ClassNex account','{academy_name} has invited you to ClassNex. Activate your account: {link}','classnex_invite','en','["academy_name","link"]'),
(NULL,'account_invite','email','Activate your ClassNex account','Hello {recipient_name},\n\n{academy_name} has invited you to ClassNex.\nActivate your account here: {link}\n\nThis link is valid for 7 days.',NULL,NULL,NULL),
(NULL,'account_invite','sms','ClassNex invite','{academy_name} invited you to ClassNex. Activate: {link}',NULL,NULL,NULL),
(NULL,'password_reset','whatsapp','Password reset','Reset your ClassNex password: {link} (valid for 1 hour). Ignore if you did not request this.','classnex_password_reset','en','["link"]'),
(NULL,'password_reset','email','Reset your ClassNex password','Hello {recipient_name},\n\nReset your password using this link (valid for 1 hour):\n{link}\n\nIf you did not request this, ignore this email.',NULL,NULL,NULL),
(NULL,'password_reset','sms','Password reset','Reset your ClassNex password: {link}',NULL,NULL,NULL),
(NULL,'subscription_expiring','email','Your ClassNex plan expires on {date}','Dear {recipient_name},\n\nYour {package_name} plan for {academy_name} expires on {date}. Renew from your ClassNex dashboard to avoid interruption.\n\nTeam ClassNex',NULL,NULL,NULL),
(NULL,'subscription_expiring','inapp','Plan expires on {date}','Your {package_name} plan expires on {date}. Renew to avoid interruption.',NULL,NULL,NULL),
(NULL,'subscription_expiring','push','Plan expires on {date}','Your {package_name} plan expires on {date}.',NULL,NULL,NULL),
(NULL,'subscription_expiring','whatsapp','Plan expiring','Your ClassNex {package_name} plan for {academy_name} expires on {date}. Please renew from your dashboard.','classnex_subscription','en','["package_name","academy_name","date"]');

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('platform_name','ClassNex'),
('support_email','support@classnex.in'),
('support_phone',''),
('default_country_code','+91'),
('timezone','Asia/Kolkata'),
('currency_symbol','₹'),
('grace_days','7'),
('expired_mode','read_only'),
('invoice_advance_days','7'),
('queue_batch_size','50'),
('queue_paused_whatsapp','0'),
('queue_paused_push','0'),
('queue_paused_email','0'),
('queue_paused_sms','0'),
('attendance_notify_quiet_minutes','30'),
('document_retention_days','1095'),
('self_registration_enabled','1'),
('default_trial_package_id','2'),
('cron_last_run',''),
('ocr_provider','client'),
('google_vision_key_enc','');

INSERT IGNORE INTO website_settings (setting_key, setting_value) VALUES
('hero', '{"title":"Run your academy from one simple app","subtitle":"Students, attendance, fees, receipts and WhatsApp reminders — for cricket, dance, yoga, music, coaching and every kind of class.","cta_primary":"Start Free Trial","cta_secondary":"See How It Works","image":""}'),
('features', '[{"icon":"bi-person-plus","title":"30-second admissions","text":"Snap a photo, type a name and WhatsApp number — done. Scan a document to auto-fill the rest."},{"icon":"bi-calendar-check","title":"One-tap attendance","text":"Mark the whole batch present in one tap, fix the exceptions, and parents are informed automatically."},{"icon":"bi-cash-coin","title":"Fees that collect themselves","text":"Monthly, quarterly or one-time fees are billed automatically, with partial payments and advance credit handled for you."},{"icon":"bi-receipt","title":"Instant receipts","text":"Every payment creates a numbered PDF receipt you can print or share on WhatsApp in a tap."},{"icon":"bi-whatsapp","title":"Automatic WhatsApp reminders","text":"Official WhatsApp Business API reminders before and after due dates — no more awkward follow-up calls."},{"icon":"bi-phone","title":"Web + Android","text":"Owners, teachers, parents and students use one app on web or Android — always in sync."},{"icon":"bi-people","title":"Parent app","text":"Parents see attendance, fees and receipts for all their children, and switch between them instantly."},{"icon":"bi-graph-up","title":"Clear reports","text":"Collections, dues, attendance and admissions — filter and export to PDF, Excel or CSV."}]'),
('categories', '["Cricket","Football","Yoga","Karate","Martial Arts","Dance","Singing","Music","Swimming","Drawing & Art","Gym & Fitness","Coaching & Tuition","Languages","Other Activities"]'),
('steps', '[{"title":"Create your academy","text":"Register, add your activities and batches in minutes."},{"title":"Add students","text":"Quick registration with photo, WhatsApp number and batch."},{"title":"Mark attendance & collect fees","text":"From web or Android, by you or your teachers."},{"title":"Relax","text":"Receipts, reminders and parent updates go out automatically."}]'),
('stats', '[{"value":"30 sec","label":"to register a student"},{"value":"1 tap","label":"to mark a full batch"},{"value":"6","label":"automatic fee reminders"},{"value":"100%","label":"data isolation per academy"}]'),
('about', '{"title":"About ClassNex","body":"ClassNex is an all-in-one class management platform built in India for academies, institutes and activity centres of every kind. We believe running a class should be about teaching — not chasing fees on the phone or maintaining registers. ClassNex brings admissions, attendance, fee collection, receipts and parent communication into one simple app that anyone can use."}'),
('contact', '{"email":"support@classnex.in","phone":"","whatsapp":"","address":"India","map_embed":""}'),
('social', '{"facebook":"","instagram":"","youtube":"","linkedin":"","twitter":""}'),
('seo', '{"title":"ClassNex – All-in-One Class Management Software","description":"Manage students, attendance, fees, receipts and WhatsApp reminders for your academy, coaching or activity class. Web + Android.","keywords":"class management software, academy management, coaching institute software, fee management, attendance app, WhatsApp fee reminder"}'),
('footer', '{"text":"ClassNex – All-in-One Class Management. Simple software for academies, institutes and activity classes.","copyright":"ClassNex. All rights reserved."}'),
('banners', '[]'),
('faq', '[{"q":"Do my parents need to install an app?","a":"No. Parents get WhatsApp updates automatically. They can optionally use the ClassNex app or web portal to see attendance, fees and receipts."},{"q":"Is Aadhaar required?","a":"Never. Documents are optional, encrypted, and only visible to your academy."},{"q":"Can my teachers see fees?","a":"Not unless you allow it. Teachers only see their own batches by default."},{"q":"What happens if my plan expires?","a":"Your data is never deleted. After a short grace period the account becomes read-only until you renew."}]');

INSERT IGNORE INTO cms_pages (slug, title, content, meta_title, meta_description) VALUES
('privacy-policy','Privacy Policy','<p>This Privacy Policy explains how ClassNex ("we") collects, uses and protects information when academies, teachers, parents and students use the ClassNex website, web application and Android application.</p><h3>Information we process</h3><p>Academies enter student and parent details such as name, WhatsApp number, photograph and, optionally, address, date of birth and supporting documents. Each academy controls its own data; ClassNex processes it on the academy''s behalf.</p><h3>Children''s data</h3><p>For students under 18, academies must obtain verifiable consent from a parent or guardian. ClassNex records the consent and never uses children''s data for advertising or profiling.</p><h3>Identity documents</h3><p>Uploading any identity document is optional. Aadhaar is never mandatory. Documents are encrypted at rest, stored privately and are not publicly accessible. ClassNex never collects Aadhaar OTPs or biometric data, and does not store Aadhaar numbers extracted by OCR.</p><h3>Messaging</h3><p>Notifications are sent through the official WhatsApp Business Platform, email, SMS and push notifications. Recipients may opt out of WhatsApp messages by replying STOP.</p><h3>Retention and deletion</h3><p>Academies can delete documents at any time and request deletion of student records, subject to financial record-keeping obligations. Documents are automatically deleted after the configured retention period.</p><h3>Your rights</h3><p>Under the Digital Personal Data Protection Act, 2023 you may request access, correction or erasure of your personal data by contacting your academy or us at the support email.</p>','Privacy Policy – ClassNex','How ClassNex protects academy, student and parent data.'),
('terms','Terms and Conditions','<p>By registering for or using ClassNex you agree to these terms.</p><h3>Accounts</h3><p>The academy owner is responsible for all users added to the academy and for keeping login credentials secure.</p><h3>Subscriptions</h3><p>Plans are billed per the selected billing cycle. When a subscription expires, a grace period applies, after which the account becomes read-only until renewal. Data is never deleted because a plan expires.</p><h3>Acceptable use</h3><p>You must have the right to process the personal data you enter, obtain parental consent for minors, and send only messages your recipients have agreed to receive.</p><h3>Payments</h3><p>Fee payments recorded in ClassNex are records of the academy. Online payments are processed by the academy''s configured payment gateway.</p><h3>Liability</h3><p>ClassNex is provided on an "as is" basis. Our liability is limited to the subscription fees paid in the preceding three months.</p>','Terms and Conditions – ClassNex','Terms of use for the ClassNex platform.'),
('refund-policy','Refund Policy','<p>Subscription fees are payable in advance. If you cancel within 7 days of your first paid subscription, contact support for a full refund. Renewals are non-refundable but you may cancel future renewals at any time.</p>','Refund Policy – ClassNex','ClassNex subscription refund policy.');

INSERT IGNORE INTO testimonials (name, designation, message, rating, sort_order) VALUES
('Academy Owner','Cricket Academy','Fee follow-ups used to take hours every month. Now reminders go out on WhatsApp automatically and parents pay on time.',5,1),
('Studio Director','Dance Studio','Registering a new student takes less than a minute, even with the photo. Our teachers mark attendance from their phones.',5,2),
('Coaching Centre Owner','Tuition Classes','Parents love getting the receipt on WhatsApp right after paying. Very simple to use.',5,3);

INSERT IGNORE INTO whatsapp_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO firebase_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO email_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO sms_configurations (id, mode) VALUES (1,'off');

-- ============================================================ 003_branches_student_quota
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

-- ============================================================ 004_history_duplicates_whatsapp_modes
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
(NULL,'academy_approved','whatsapp','Academy approved','Your academy {academy_name} is approved on ClassNex. Log in: {link}','classnex_general','en','["message"]'),
(NULL,'academy_approved','email','Your ClassNex academy is approved','Hello {recipient_name},\n\nYour academy {academy_name} has been approved. You can log in now: {link}\n\nTeam ClassNex',NULL,NULL,NULL),
(NULL,'academy_approved','sms','Academy approved','ClassNex: {academy_name} is approved. Log in: {link}',NULL,NULL,NULL),
(NULL,'academy_rejected','whatsapp','Registration not approved','Your ClassNex registration for {academy_name} was not approved. {reason} Contact us for help.','classnex_general','en','["message"]'),
(NULL,'registration_pending','inapp','New academy registration','{academy_name} registered on the website and is waiting for your approval (Super Admin → Registration requests).',NULL,NULL,NULL),
(NULL,'academy_rejected','email','ClassNex registration','Hello {recipient_name},\n\nYour registration for {academy_name} was not approved. {reason}\n\nPlease reply to this email if you need help.\n\nTeam ClassNex',NULL,NULL,NULL);

CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations (version) VALUES ('001_core_schema');
INSERT IGNORE INTO schema_migrations (version) VALUES ('002_core_seed');
INSERT IGNORE INTO schema_migrations (version) VALUES ('003_branches_student_quota');
INSERT IGNORE INTO schema_migrations (version) VALUES ('004_history_duplicates_whatsapp_modes');
