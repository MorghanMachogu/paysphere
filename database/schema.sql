-- =====================================================================
-- PaySphere: Smart Payroll & HR Automation System for Kenyan SMEs
-- MySQL 8.0 schema (also runs on MariaDB 10.6+)
--
-- Entities follow the ERD in Chapter Three (Figure 5):
--   User (employer_users / employee_users), Employee, Payroll (payroll_runs),
--   Payslip, LeaveRequest, Attendance, Subscription
-- Every business table is scoped to an organisation (multi-tenant SaaS).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS paysphere CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE paysphere;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log, login_logs, user_sessions, payslips, payroll_runs,
  attendance, leave_requests, leave_types, salary_structures, employee_users,
  employees, positions, departments, employer_users, subscriptions, organizations;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Organisations and subscriptions
-- ---------------------------------------------------------------------
CREATE TABLE organizations (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_name VARCHAR(150) NOT NULL,
  organization_code VARCHAR(20)  NOT NULL UNIQUE,
  kra_pin           VARCHAR(11)  NULL,
  email             VARCHAR(150) NOT NULL,
  phone             VARCHAR(20)  NULL,
  address           VARCHAR(255) NULL,
  county            VARCHAR(60)  NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  plan            ENUM('trial','starter','growth','enterprise') NOT NULL DEFAULT 'trial',
  max_employees   INT UNSIGNED NOT NULL DEFAULT 25,
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  status          ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- HR / admin users (employer side)
-- Roles: super_admin (everything), hr_manager (HR + payroll),
--        payroll_officer (payroll + reports), manager (leave + attendance)
-- ---------------------------------------------------------------------
CREATE TABLE employer_users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id       INT UNSIGNED NOT NULL,
  username              VARCHAR(60)  NOT NULL UNIQUE,
  email                 VARCHAR(150) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  first_name            VARCHAR(60)  NOT NULL,
  last_name             VARCHAR(60)  NOT NULL,
  phone_number          VARCHAR(20)  NULL,
  role                  ENUM('super_admin','hr_manager','payroll_officer','manager') NOT NULL DEFAULT 'hr_manager',
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME     NULL,
  last_login            DATETIME     NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_eu_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organisational structure
-- ---------------------------------------------------------------------
CREATE TABLE departments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(100) NOT NULL,
  description     VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dept_code (organization_id, code),
  CONSTRAINT fk_dept_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE positions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  code            VARCHAR(20)  NOT NULL,
  title           VARCHAR(100) NOT NULL,
  description     VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pos_code (organization_id, code),
  CONSTRAINT fk_pos_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Employees and their login accounts (1:1, per the ERD)
-- ---------------------------------------------------------------------
CREATE TABLE employees (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  employee_number VARCHAR(20)  NOT NULL,
  first_name      VARCHAR(60)  NOT NULL,
  last_name       VARCHAR(60)  NOT NULL,
  national_id     VARCHAR(20)  NOT NULL,
  kra_pin         VARCHAR(11)  NULL,
  nssf_number     VARCHAR(20)  NULL,
  shif_number     VARCHAR(20)  NULL,
  date_of_birth   DATE         NULL,
  gender          ENUM('male','female','other') NULL,
  phone_number    VARCHAR(20)  NULL,
  work_email      VARCHAR(150) NULL,
  personal_email  VARCHAR(150) NULL,
  department_id   INT UNSIGNED NULL,
  position_id     INT UNSIGNED NULL,
  employment_type ENUM('permanent','contract','casual','intern') NOT NULL DEFAULT 'permanent',
  hire_date       DATE         NOT NULL,
  payment_method  ENUM('mpesa','bank_transfer','cash','cheque') NOT NULL DEFAULT 'mpesa',
  mpesa_number    VARCHAR(15)  NULL,
  bank_name       VARCHAR(100) NULL,
  bank_account    VARCHAR(40)  NULL,
  status          ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_number (organization_id, employee_number),
  UNIQUE KEY uq_emp_national_id (organization_id, national_id),
  KEY idx_emp_status (organization_id, status),
  CONSTRAINT fk_emp_org  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_emp_dept FOREIGN KEY (department_id)   REFERENCES departments(id)   ON DELETE SET NULL,
  CONSTRAINT fk_emp_pos  FOREIGN KEY (position_id)     REFERENCES positions(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employee_users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id           INT UNSIGNED NOT NULL UNIQUE,
  username              VARCHAR(60)  NOT NULL UNIQUE,
  password_hash         VARCHAR(255) NOT NULL,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  force_password_change TINYINT(1)   NOT NULL DEFAULT 1,
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME     NULL,
  last_login            DATETIME     NULL,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_eus_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Salary history: the newest active row on or before the payroll period is used.
CREATE TABLE salary_structures (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT UNSIGNED  NOT NULL,
  basic_salary        DECIMAL(12,2) NOT NULL,
  housing_allowance   DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
  medical_allowance   DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_allowances    DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_deductions    DECIMAL(12,2) NOT NULL DEFAULT 0,
  effective_date      DATE          NOT NULL,
  is_active           TINYINT(1)    NOT NULL DEFAULT 1,
  created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_salary_emp (employee_id, is_active, effective_date),
  CONSTRAINT fk_sal_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Leave and attendance
-- ---------------------------------------------------------------------
CREATE TABLE leave_types (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(60)  NOT NULL,
  days_per_year   INT UNSIGNED NOT NULL,
  is_paid         TINYINT(1)   NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_lt_code (organization_id, code),
  CONSTRAINT fk_lt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  leave_type_id  INT UNSIGNED NOT NULL,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  days           DECIMAL(5,1) NOT NULL,
  reason         VARCHAR(500) NULL,
  status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME     NULL,
  review_comment VARCHAR(500) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lr_emp (employee_id, status),
  CONSTRAINT fk_lr_emp  FOREIGN KEY (employee_id)   REFERENCES employees(id)      ON DELETE CASCADE,
  CONSTRAINT fk_lr_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
  CONSTRAINT fk_lr_rev  FOREIGN KEY (reviewed_by)   REFERENCES employer_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE attendance (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  attendance_date DATE         NOT NULL,
  clock_in        TIME         NULL,
  clock_out       TIME         NULL,
  status          ENUM('present','late','absent','on_leave','half_day') NOT NULL DEFAULT 'present',
  overtime_hours  DECIMAL(5,2) NOT NULL DEFAULT 0,
  notes           VARCHAR(255) NULL,
  recorded_by     ENUM('employee','hr') NOT NULL DEFAULT 'hr',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att_day (employee_id, attendance_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Payroll: one run per organisation per month (Payroll entity),
-- one payslip per employee per run (Payslip entity)
-- ---------------------------------------------------------------------
CREATE TABLE payroll_runs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  INT UNSIGNED  NOT NULL,
  period_month     TINYINT UNSIGNED NOT NULL,
  period_year      SMALLINT UNSIGNED NOT NULL,
  status           ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  employee_count   INT UNSIGNED  NOT NULL DEFAULT 0,
  total_gross      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_net        DECIMAL(14,2) NOT NULL DEFAULT 0,
  generated_by     INT UNSIGNED  NULL,
  generated_at     DATETIME      NULL,
  processing_ms    INT UNSIGNED  NULL,
  approved_by      INT UNSIGNED  NULL,
  approved_at      DATETIME      NULL,
  paid_by          INT UNSIGNED  NULL,
  paid_at          DATETIME      NULL,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_run_period (organization_id, period_year, period_month),
  CONSTRAINT fk_run_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payslips (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payroll_run_id        INT UNSIGNED  NOT NULL,
  employee_id           INT UNSIGNED  NOT NULL,
  -- earnings
  basic_salary          DECIMAL(12,2) NOT NULL,
  housing_allowance     DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_allowance   DECIMAL(12,2) NOT NULL DEFAULT 0,
  medical_allowance     DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_allowances      DECIMAL(12,2) NOT NULL DEFAULT 0,
  overtime_hours        DECIMAL(6,2)  NOT NULL DEFAULT 0,
  overtime_pay          DECIMAL(12,2) NOT NULL DEFAULT 0,
  absent_days           DECIMAL(5,1)  NOT NULL DEFAULT 0,
  absence_deduction     DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross_pay             DECIMAL(12,2) NOT NULL,
  -- statutory deductions
  nssf_tier1            DECIMAL(12,2) NOT NULL DEFAULT 0,
  nssf_tier2            DECIMAL(12,2) NOT NULL DEFAULT 0,
  nssf_employee         DECIMAL(12,2) NOT NULL DEFAULT 0,
  shif                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  housing_levy          DECIMAL(12,2) NOT NULL DEFAULT 0,
  taxable_pay           DECIMAL(12,2) NOT NULL DEFAULT 0,
  paye_before_relief    DECIMAL(12,2) NOT NULL DEFAULT 0,
  personal_relief       DECIMAL(12,2) NOT NULL DEFAULT 0,
  paye                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_deductions      DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deductions      DECIMAL(12,2) NOT NULL,
  net_pay               DECIMAL(12,2) NOT NULL,
  -- employer contributions (cost to company, shown on reports)
  employer_nssf         DECIMAL(12,2) NOT NULL DEFAULT 0,
  employer_housing_levy DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- payment
  payment_method        ENUM('mpesa','bank_transfer','cash','cheque') NOT NULL DEFAULT 'mpesa',
  payment_reference     VARCHAR(60)   NULL,
  payment_status        ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  paid_at               DATETIME      NULL,
  emailed_at            DATETIME      NULL,
  created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payslip (payroll_run_id, employee_id),
  KEY idx_payslip_emp (employee_id),
  CONSTRAINT fk_ps_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_emp FOREIGN KEY (employee_id)    REFERENCES employees(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Security and audit
-- ---------------------------------------------------------------------
CREATE TABLE user_sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type     ENUM('employer','employee') NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  session_token CHAR(64)     NOT NULL UNIQUE,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME     NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  KEY idx_sess_user (user_type, user_id)
) ENGINE=InnoDB;

CREATE TABLE login_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type  ENUM('employer','employee','unknown') NOT NULL,
  user_id    INT UNSIGNED NULL,
  organization_id INT UNSIGNED NULL,
  username   VARCHAR(60)  NOT NULL,
  success    TINYINT(1)   NOT NULL,
  reason     VARCHAR(100) NULL,
  ip_address VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ll_org (organization_id, created_at)
) ENGINE=InnoDB;

-- Append-only record of every change to employees, salaries, payroll and leave.
CREATE TABLE audit_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  user_type       ENUM('employer','employee','system') NOT NULL,
  user_id         INT UNSIGNED NULL,
  action          VARCHAR(60)  NOT NULL,
  entity          VARCHAR(40)  NOT NULL,
  entity_id       INT UNSIGNED NULL,
  details         TEXT         NULL,
  ip_address      VARCHAR(45)  NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_org (organization_id, created_at)
) ENGINE=InnoDB;
