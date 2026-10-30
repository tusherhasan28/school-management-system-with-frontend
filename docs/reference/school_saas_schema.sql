-- School Management SaaS - MySQL 8.0 baseline schema
-- Bangladesh-first, shared-database multi-tenancy.
-- IMPORTANT:
-- 1) Run migrations in dependency order in production; this file is a reference baseline.
-- 2) All tenant-owned tables carry school_id. Enforce tenant scope in Laravel middleware,
--    policies, services, jobs, exports and storage access. A school_id column alone is NOT security.
-- 3) Use UTC timestamps in storage; render in Asia/Dhaka.
-- 4) Never mark a payment paid from a browser redirect alone; verify gateway callbacks server-side.

CREATE DATABASE IF NOT EXISTS school_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_saas;

-- ---------- CENTRAL PLATFORM ----------

CREATE TABLE platform_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  two_factor_secret TEXT NULL,
  email_verified_at TIMESTAMP NULL,
  last_login_at TIMESTAMP NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE schools (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  institution_type ENUM('school','college','kindergarten','madrasa','combined','other') NOT NULL DEFAULT 'school',
  status ENUM('pending','trial','active','suspended','cancelled','archived') NOT NULL DEFAULT 'pending',
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  address TEXT NULL,
  district VARCHAR(100) NULL,
  division VARCHAR(100) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'BD',
  timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Dhaka',
  locale VARCHAR(10) NOT NULL DEFAULT 'bn',
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  logo_path VARCHAR(500) NULL,
  primary_color CHAR(7) NULL,
  trial_ends_at DATETIME NULL,
  activated_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE school_domains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL UNIQUE,
  is_primary BOOLEAN NOT NULL DEFAULT TRUE,
  verified_at DATETIME NULL,
  ssl_status ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT fk_school_domains_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(60) NOT NULL UNIQUE,
  description TEXT NULL,
  price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
  price_yearly DECIMAL(12,2) NOT NULL DEFAULT 0,
  trial_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE plan_features (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  feature_key VARCHAR(100) NOT NULL,
  feature_value JSON NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_plan_feature (plan_id, feature_key),
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trialing','active','past_due','grace_period','suspended','cancelled','expired') NOT NULL,
  billing_cycle ENUM('monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
  amount DECIMAL(12,2) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  trial_ends_at DATETIME NULL,
  grace_ends_at DATETIME NULL,
  auto_renew BOOLEAN NOT NULL DEFAULT FALSE,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_sub_school_status (school_id,status),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE platform_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(60) NOT NULL UNIQUE,
  subtotal DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  status ENUM('draft','unpaid','paid','overdue','void','refunded') NOT NULL DEFAULT 'unpaid',
  due_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB;

CREATE TABLE platform_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  gateway VARCHAR(50) NOT NULL,
  transaction_id VARCHAR(150) NULL,
  gateway_reference VARCHAR(150) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  status ENUM('initiated','pending','successful','failed','refunded') NOT NULL,
  verified_at DATETIME NULL,
  raw_response JSON NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_platform_gateway_tx (gateway,transaction_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (invoice_id) REFERENCES platform_invoices(id)
) ENGINE=InnoDB;

CREATE TABLE support_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  assigned_platform_user_id BIGINT UNSIGNED NULL,
  subject VARCHAR(190) NOT NULL,
  category VARCHAR(80) NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (assigned_platform_user_id) REFERENCES platform_users(id)
) ENGINE=InnoDB;

CREATE TABLE support_ticket_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  sender_type ENUM('platform','school') NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  attachments JSON NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
) ENGINE=InnoDB;

CREATE TABLE platform_activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform_user_id BIGINT UNSIGNED NULL,
  school_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  subject_type VARCHAR(120) NULL,
  subject_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NULL,
  INDEX idx_platform_log_school (school_id,created_at),
  FOREIGN KEY (platform_user_id) REFERENCES platform_users(id),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

-- ---------- TENANT IDENTITY / RBAC ----------

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  password VARCHAR(255) NOT NULL,
  preferred_locale VARCHAR(10) NOT NULL DEFAULT 'bn',
  status ENUM('invited','active','disabled','locked') NOT NULL DEFAULT 'invited',
  email_verified_at TIMESTAMP NULL,
  phone_verified_at TIMESTAMP NULL,
  last_login_at DATETIME NULL,
  two_factor_secret TEXT NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_email (school_id,email),
  UNIQUE KEY uq_school_phone (school_id,phone),
  INDEX idx_users_school_status (school_id,status),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  guard_name VARCHAR(50) NOT NULL DEFAULT 'web',
  is_system BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_role (school_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  guard_name VARCHAR(50) NOT NULL DEFAULT 'web',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE role_has_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id,permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_has_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id,role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_has_permissions (
  user_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id,permission_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE campuses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(40) NULL,
  address TEXT NULL,
  phone VARCHAR(30) NULL,
  is_main BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_campus_code (school_id,code),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE school_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value JSON NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_setting (school_id,setting_key),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

-- ---------- ACADEMIC STRUCTURE ----------

CREATE TABLE academic_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_current BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('planned','active','completed','archived') NOT NULL DEFAULT 'planned',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_session_name (school_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE classes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(40) NULL,
  level_order SMALLINT NOT NULL DEFAULT 0,
  education_stage ENUM('early_years','primary','secondary','higher_secondary','college','madrasa','other') NOT NULL DEFAULT 'other',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_class_name (school_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE sections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  capacity SMALLINT UNSIGNED NULL,
  room VARCHAR(80) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_section (school_id,class_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB;

CREATE TABLE subjects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(40) NULL,
  subject_type ENUM('compulsory','optional','elective','practical','other') NOT NULL DEFAULT 'compulsory',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_subject_code (school_id,code),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE class_subjects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  full_marks DECIMAL(7,2) NOT NULL DEFAULT 100,
  pass_marks DECIMAL(7,2) NOT NULL DEFAULT 33,
  is_optional BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_class_subject (school_id,academic_session_id,class_id,subject_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id)
) ENGINE=InnoDB;

-- ---------- PEOPLE / ENROLMENT ----------

CREATE TABLE teachers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  employee_no VARCHAR(60) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  name_bn VARCHAR(160) NULL,
  gender VARCHAR(30) NULL,
  date_of_birth DATE NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  qualification VARCHAR(190) NULL,
  designation VARCHAR(120) NULL,
  joining_date DATE NULL,
  address TEXT NULL,
  photo_path VARCHAR(500) NULL,
  status ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_teacher_no (school_id,employee_no),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE guardians (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  full_name VARCHAR(160) NOT NULL,
  name_bn VARCHAR(160) NULL,
  phone VARCHAR(30) NOT NULL,
  alternate_phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  occupation VARCHAR(120) NULL,
  address TEXT NULL,
  national_id_encrypted TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_guardian_phone (school_id,phone),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE students (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  admission_no VARCHAR(60) NOT NULL,
  roll_no VARCHAR(40) NULL,
  full_name VARCHAR(160) NOT NULL,
  name_bn VARCHAR(160) NULL,
  date_of_birth DATE NULL,
  gender ENUM('male','female','other','not_specified') NOT NULL DEFAULT 'not_specified',
  blood_group VARCHAR(5) NULL,
  religion VARCHAR(60) NULL,
  nationality VARCHAR(80) NULL DEFAULT 'Bangladeshi',
  birth_registration_no_encrypted TEXT NULL,
  photo_path VARCHAR(500) NULL,
  present_address TEXT NULL,
  permanent_address TEXT NULL,
  admission_date DATE NULL,
  status ENUM('active','inactive','graduated','transferred','withdrawn') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uq_student_admission (school_id,admission_no),
  INDEX idx_student_name (school_id,full_name),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE student_guardians (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  guardian_id BIGINT UNSIGNED NOT NULL,
  relationship VARCHAR(60) NOT NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE,
  can_pickup BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  UNIQUE KEY uq_student_guardian (student_id,guardian_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (guardian_id) REFERENCES guardians(id)
) ENGINE=InnoDB;

CREATE TABLE student_enrollments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NULL,
  roll_no VARCHAR(40) NULL,
  enrollment_status ENUM('enrolled','promoted','repeated','transferred','withdrawn','completed') NOT NULL DEFAULT 'enrolled',
  enrolled_at DATE NOT NULL,
  ended_at DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_student_session (student_id,academic_session_id),
  INDEX idx_enrollment_class (school_id,academic_session_id,class_id,section_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB;

CREATE TABLE admission_applications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  application_no VARCHAR(60) NOT NULL,
  applicant_name VARCHAR(160) NOT NULL,
  applicant_name_bn VARCHAR(160) NULL,
  date_of_birth DATE NULL,
  applied_class_id BIGINT UNSIGNED NULL,
  guardian_name VARCHAR(160) NOT NULL,
  guardian_phone VARCHAR(30) NOT NULL,
  guardian_email VARCHAR(190) NULL,
  application_data JSON NULL,
  status ENUM('submitted','under_review','approved','rejected','enrolled') NOT NULL DEFAULT 'submitted',
  reviewed_by BIGINT UNSIGNED NULL,
  submitted_at DATETIME NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_application_no (school_id,application_no),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (applied_class_id) REFERENCES classes(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE student_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(80) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  original_name VARCHAR(190) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- CLASS ALLOCATION / TIMETABLE ----------

CREATE TABLE teacher_class_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NULL,
  assignment_type ENUM('class_teacher','subject_teacher','both') NOT NULL DEFAULT 'subject_teacher',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_teacher_assignment (school_id,teacher_id,academic_session_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id)
) ENGINE=InnoDB;

CREATE TABLE class_routines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Monday ... 7=Sunday; school calendar controls working days',
  starts_at TIME NOT NULL,
  ends_at TIME NOT NULL,
  room VARCHAR(80) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_routine_section (school_id,academic_session_id,class_id,section_id,day_of_week),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

-- ---------- ATTENDANCE ----------

CREATE TABLE attendance_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  period_label VARCHAR(80) NOT NULL DEFAULT 'Daily',
  taken_by BIGINT UNSIGNED NULL,
  finalized_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_attendance_session (school_id,section_id,attendance_date,period_label),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id),
  FOREIGN KEY (taken_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE student_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  attendance_session_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  status ENUM('present','absent','late','leave','half_day') NOT NULL,
  remarks VARCHAR(500) NULL,
  marked_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_student_attendance (attendance_session_id,student_id),
  INDEX idx_student_attendance_date (school_id,student_id,attendance_session_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (marked_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE teacher_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  status ENUM('present','absent','late','leave','half_day') NOT NULL,
  remarks VARCHAR(500) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_teacher_attendance (school_id,teacher_id,attendance_date),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

-- ---------- EXAMINATION / GRADING ----------

CREATE TABLE exam_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_exam_type (school_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE examinations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  exam_type_id BIGINT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  starts_on DATE NULL,
  ends_on DATE NULL,
  result_published_at DATETIME NULL,
  status ENUM('draft','scheduled','ongoing','completed','published') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (exam_type_id) REFERENCES exam_types(id)
) ENGINE=InnoDB;

CREATE TABLE exam_subjects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  examination_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  exam_date DATE NULL,
  starts_at TIME NULL,
  duration_minutes SMALLINT UNSIGNED NULL,
  written_full_marks DECIMAL(7,2) NOT NULL DEFAULT 100,
  practical_full_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  mcq_full_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  pass_marks DECIMAL(7,2) NOT NULL DEFAULT 33,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_exam_class_subject (examination_id,class_id,subject_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (examination_id) REFERENCES examinations(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id)
) ENGINE=InnoDB;

CREATE TABLE grading_scales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE grading_scale_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  grading_scale_id BIGINT UNSIGNED NOT NULL,
  grade VARCHAR(10) NOT NULL,
  min_percentage DECIMAL(5,2) NOT NULL,
  max_percentage DECIMAL(5,2) NOT NULL,
  grade_point DECIMAL(4,2) NOT NULL,
  remarks VARCHAR(100) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_grade_range (grading_scale_id,grade),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (grading_scale_id) REFERENCES grading_scales(id)
) ENGINE=InnoDB;

CREATE TABLE student_marks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  exam_subject_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  written_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  practical_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  mcq_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  total_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  is_absent BOOLEAN NOT NULL DEFAULT FALSE,
  remarks VARCHAR(500) NULL,
  entered_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_exam_student_marks (exam_subject_id,student_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (exam_subject_id) REFERENCES exam_subjects(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (entered_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE exam_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  examination_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  total_marks DECIMAL(9,2) NOT NULL DEFAULT 0,
  obtained_marks DECIMAL(9,2) NOT NULL DEFAULT 0,
  gpa DECIMAL(4,2) NULL,
  letter_grade VARCHAR(10) NULL,
  merit_position INT UNSIGNED NULL,
  result_status ENUM('pass','fail','incomplete','withheld') NOT NULL DEFAULT 'incomplete',
  calculated_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_exam_result (examination_id,student_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (examination_id) REFERENCES examinations(id),
  FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

-- ---------- FEES / PAYMENTS / ACCOUNTING ----------

CREATE TABLE fee_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  is_recurring BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_fee_category (school_id,name),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE fee_structures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NULL,
  fee_category_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly','custom') NOT NULL,
  due_day TINYINT UNSIGNED NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
) ENGINE=InnoDB;

CREATE TABLE student_fee_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  fee_structure_id BIGINT UNSIGNED NOT NULL,
  custom_amount DECIMAL(12,2) NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id)
) ENGINE=InnoDB;

CREATE TABLE fee_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(60) NOT NULL,
  academic_session_id BIGINT UNSIGNED NULL,
  billing_period VARCHAR(30) NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  late_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  paid_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','unpaid','partially_paid','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'unpaid',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_invoice (school_id,invoice_no),
  INDEX idx_student_invoice (school_id,student_id,status,due_date),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE fee_invoice_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  fee_category_id BIGINT UNSIGNED NULL,
  description VARCHAR(190) NOT NULL,
  quantity DECIMAL(8,2) NOT NULL DEFAULT 1,
  unit_amount DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id),
  FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
) ENGINE=InnoDB;

CREATE TABLE fee_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  receipt_no VARCHAR(60) NOT NULL,
  payment_method ENUM('cash','bank','bkash','nagad','rocket','sslcommerz','card','other') NOT NULL,
  gateway VARCHAR(60) NULL,
  transaction_id VARCHAR(150) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  status ENUM('pending','successful','failed','refunded','reversed') NOT NULL DEFAULT 'pending',
  received_at DATETIME NULL,
  verified_at DATETIME NULL,
  received_by BIGINT UNSIGNED NULL,
  gateway_payload JSON NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_fee_receipt (school_id,receipt_no),
  UNIQUE KEY uq_fee_gateway_tx (gateway,transaction_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE fee_payment_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  fee_payment_id BIGINT UNSIGNED NOT NULL,
  fee_invoice_id BIGINT UNSIGNED NOT NULL,
  allocated_amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (fee_payment_id) REFERENCES fee_payments(id),
  FOREIGN KEY (fee_invoice_id) REFERENCES fee_invoices(id)
) ENGINE=InnoDB;

CREATE TABLE expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(120) NOT NULL,
  reference_no VARCHAR(80) NULL,
  description TEXT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method VARCHAR(40) NULL,
  attachment_path VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- HOMEWORK / NOTICES / COMMUNICATION ----------

CREATE TABLE assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  academic_session_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  instructions TEXT NULL,
  attachment_path VARCHAR(500) NULL,
  assigned_at DATETIME NOT NULL,
  due_at DATETIME NULL,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  FOREIGN KEY (class_id) REFERENCES classes(id),
  FOREIGN KEY (section_id) REFERENCES sections(id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE assignment_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  submission_text TEXT NULL,
  attachment_path VARCHAR(500) NULL,
  submitted_at DATETIME NULL,
  marks DECIMAL(7,2) NULL,
  feedback TEXT NULL,
  status ENUM('not_submitted','submitted','late','graded') NOT NULL DEFAULT 'not_submitted',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_assignment_student (assignment_id,student_id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (assignment_id) REFERENCES assignments(id),
  FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

CREATE TABLE notices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body LONGTEXT NOT NULL,
  audience ENUM('all','teachers','students','parents','staff','custom') NOT NULL DEFAULT 'all',
  publish_at DATETIME NULL,
  expires_at DATETIME NULL,
  attachment_path VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  channel ENUM('database','email','sms','push') NOT NULL,
  recipient VARCHAR(190) NOT NULL,
  template_key VARCHAR(100) NULL,
  payload JSON NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  provider_reference VARCHAR(150) NULL,
  sent_at DATETIME NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- LIBRARY / TRANSPORT / PAYROLL ----------

CREATE TABLE library_books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  isbn VARCHAR(30) NULL,
  title VARCHAR(190) NOT NULL,
  author VARCHAR(160) NULL,
  publisher VARCHAR(160) NULL,
  category VARCHAR(100) NULL,
  total_copies INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE library_book_copies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  accession_no VARCHAR(80) NOT NULL,
  status ENUM('available','issued','lost','damaged') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_accession (school_id,accession_no),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (book_id) REFERENCES library_books(id)
) ENGINE=InnoDB;

CREATE TABLE library_issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  book_copy_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NULL,
  teacher_id BIGINT UNSIGNED NULL,
  issued_at DATE NOT NULL,
  due_at DATE NOT NULL,
  returned_at DATE NULL,
  fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('issued','returned','overdue','lost') NOT NULL DEFAULT 'issued',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (book_copy_id) REFERENCES library_book_copies(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE transport_routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE transport_vehicles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  route_id BIGINT UNSIGNED NULL,
  registration_no VARCHAR(50) NOT NULL,
  vehicle_type VARCHAR(80) NULL,
  capacity SMALLINT UNSIGNED NULL,
  driver_name VARCHAR(160) NULL,
  driver_phone VARCHAR(30) NULL,
  status ENUM('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_vehicle_reg (school_id,registration_no),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (route_id) REFERENCES transport_routes(id)
) ENGINE=InnoDB;

CREATE TABLE transport_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  route_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NULL,
  pickup_point VARCHAR(190) NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  status ENUM('active','ended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (route_id) REFERENCES transport_routes(id),
  FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id)
) ENGINE=InnoDB;

CREATE TABLE employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  employee_no VARCHAR(60) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  designation VARCHAR(120) NULL,
  department VARCHAR(120) NULL,
  phone VARCHAR(30) NULL,
  joining_date DATE NULL,
  employment_status ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_employee_no (school_id,employee_no),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE salary_structures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  employee_type ENUM('teacher','employee') NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  basic_salary DECIMAL(12,2) NOT NULL,
  allowances JSON NULL,
  deductions JSON NULL,
  effective_from DATE NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_salary_employee (school_id,employee_type,employee_id)
) ENGINE=InnoDB;

CREATE TABLE payroll_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  payroll_month CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  status ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  approved_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_payroll_month (school_id,payroll_month),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE payroll_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  payroll_run_id BIGINT UNSIGNED NOT NULL,
  employee_type ENUM('teacher','employee') NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  basic_amount DECIMAL(12,2) NOT NULL,
  allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL,
  payment_status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id)
) ENGINE=InnoDB;

-- ---------- SCHOOL PUBLIC WEBSITE ----------

CREATE TABLE website_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  template_key VARCHAR(80) NOT NULL DEFAULT 'default',
  site_title VARCHAR(190) NULL,
  tagline VARCHAR(255) NULL,
  logo_path VARCHAR(500) NULL,
  favicon_path VARCHAR(500) NULL,
  theme_config JSON NULL,
  seo_defaults JSON NULL,
  is_published BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_website_settings (school_id),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE website_pages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  content LONGTEXT NULL,
  seo_title VARCHAR(190) NULL,
  seo_description VARCHAR(320) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_website_page_slug (school_id,slug),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE website_menus (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  menu_location VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  url VARCHAR(500) NULL,
  page_id BIGINT UNSIGNED NULL,
  parent_id BIGINT UNSIGNED NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_visible BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (page_id) REFERENCES website_pages(id),
  FOREIGN KEY (parent_id) REFERENCES website_menus(id)
) ENGINE=InnoDB;

-- ---------- AUDIT / FILES / INTEGRATIONS ----------

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  subject_type VARCHAR(120) NULL,
  subject_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  created_at TIMESTAMP NULL,
  INDEX idx_activity_school_date (school_id,created_at),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE integration_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(80) NOT NULL,
  credentials_encrypted TEXT NULL,
  settings JSON NULL,
  is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_provider (school_id,provider),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;

CREATE TABLE data_import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  import_type VARCHAR(80) NOT NULL,
  source_file_path VARCHAR(500) NOT NULL,
  status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  success_rows INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_report_path VARCHAR(500) NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- END OF BASELINE SCHEMA
-- Production Laravel migrations should add composite tenant-aware constraints,
-- additional indexes, event/outbox tables, password reset/session tables,
-- notification preferences, academic calendar, holidays, exam schedules,
-- configurable result components, hostel entities, expense approvals,
-- refunds, and any institution-specific extensions required by discovery.
