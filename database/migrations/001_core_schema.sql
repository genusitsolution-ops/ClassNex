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
