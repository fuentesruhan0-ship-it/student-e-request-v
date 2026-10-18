-- ===========================================================================
-- Student E-Request Portal  --  Unified Database Schema
-- File     : database.sql  (this is the ONLY sql file needed)
-- Database : student-req
--
-- ROLES & ID FORMATS:
--   student   -> student_no  : YYYY-NNNNN  (e.g. 2024-10001)
--   cashier   -> employee_no : CSH-XXXXX   (e.g. CSH-00001)
--   registrar -> employee_no : REG-XXXXX   (e.g. REG-00001)
--
-- AUTH RULE: ALL users MUST register/login with a @gmail.com address.
-- ===========================================================================

CREATE DATABASE IF NOT EXISTS `student-req`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `student-req`;

-- ---------------------------------------------------------------------------
-- 1. STUDENTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_no    VARCHAR(20)  NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    course        VARCHAR(120) DEFAULT NULL,
    year_level    TINYINT UNSIGNED DEFAULT NULL,
    clerk_user_id VARCHAR(64)  DEFAULT NULL COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_email      (email),
    UNIQUE KEY uq_students_student_no (student_no),
    UNIQUE KEY uq_students_clerk_id   (clerk_user_id),
    CONSTRAINT chk_students_email CHECK (email LIKE '%@gmail.com'),
    CONSTRAINT chk_students_year  CHECK (year_level IS NULL OR year_level BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. CASHIERS  (employee_no must begin with CSH-)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cashiers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_no   VARCHAR(20)  NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    clerk_user_id VARCHAR(64)  DEFAULT NULL COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cashiers_email       (email),
    UNIQUE KEY uq_cashiers_employee_no (employee_no),
    UNIQUE KEY uq_cashiers_clerk_id    (clerk_user_id),
    CONSTRAINT chk_cashiers_email  CHECK (email LIKE '%@gmail.com'),
    CONSTRAINT chk_cashiers_emp_no CHECK (employee_no LIKE 'CSH-%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. REGISTRARS  (employee_no must begin with REG-)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registrars (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_no   VARCHAR(20)  NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    clerk_user_id VARCHAR(64)  DEFAULT NULL COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_registrars_email       (email),
    UNIQUE KEY uq_registrars_employee_no (employee_no),
    UNIQUE KEY uq_registrars_clerk_id    (clerk_user_id),
    CONSTRAINT chk_registrars_email  CHECK (email LIKE '%@gmail.com'),
    CONSTRAINT chk_registrars_emp_no CHECK (employee_no LIKE 'REG-%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. SERVICES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(120) NOT NULL,
    description      VARCHAR(300) DEFAULT NULL,
    fee              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    processing_days  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_name  (name),
    KEY idx_services_active      (is_active),
    CONSTRAINT chk_services_fee  CHECK (fee >= 0),
    CONSTRAINT chk_services_days CHECK (processing_days BETWEEN 1 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4b. SITE SETTINGS  (key-value store for editable registrar-controlled text)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(1000) NOT NULL DEFAULT '',
    registrar_id  INT UNSIGNED DEFAULT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_site_settings_registrar (registrar_id),
    CONSTRAINT fk_site_settings_registrar FOREIGN KEY (registrar_id)
        REFERENCES registrars(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('services_subtitle', 'Official registrar documents with fixed, transparent fees.');

-- ---------------------------------------------------------------------------
-- 5. REQUESTS  (always owned by a student)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id     INT UNSIGNED NOT NULL,
    service_id     INT UNSIGNED NOT NULL,
    ref_no         VARCHAR(24)  NOT NULL,
    notes          VARCHAR(500) DEFAULT NULL,
    admin_note     VARCHAR(500) DEFAULT NULL,
    payment_choice ENUM('now','later') NOT NULL DEFAULT 'now',
    fee            DECIMAL(10,2) NOT NULL,
    status         ENUM('pending','approved','processing','ready','released','rejected') NOT NULL DEFAULT 'pending',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_requests_ref          (ref_no),
    KEY idx_requests_student            (student_id),
    KEY idx_requests_service            (service_id),
    KEY idx_requests_status             (status),
    KEY idx_requests_created            (created_at),
    KEY idx_requests_student_status     (student_id, status),
    CONSTRAINT fk_requests_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_requests_service FOREIGN KEY (service_id)
        REFERENCES services(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. PAYMENTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id         INT UNSIGNED NOT NULL,
    method             ENUM('gcash','maya','card','bank_transfer','mock') NOT NULL DEFAULT 'mock',
    amount             DECIMAL(10,2) NOT NULL,
    currency           CHAR(3) NOT NULL DEFAULT 'PHP',
    transaction_number VARCHAR(60) NOT NULL,
    gateway_reference  VARCHAR(120) DEFAULT NULL,
    payment_url        VARCHAR(255) DEFAULT NULL,
    status             ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    failure_reason     VARCHAR(255) DEFAULT NULL,
    proof_image        VARCHAR(255) DEFAULT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at            DATETIME DEFAULT NULL,
    UNIQUE KEY uq_payments_txn   (transaction_number),
    KEY idx_payments_request     (request_id),
    KEY idx_payments_status      (status),
    KEY idx_payments_method      (method),
    KEY idx_payments_created     (created_at),
    CONSTRAINT fk_payments_request FOREIGN KEY (request_id)
        REFERENCES requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. RECEIPTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS receipts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id     INT UNSIGNED NOT NULL,
    receipt_number VARCHAR(60) NOT NULL,
    issued_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipts_number  (receipt_number),
    UNIQUE KEY uq_receipts_payment (payment_id),
    CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id)
        REFERENCES payments(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. AUDIT LOGS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type  ENUM('student','cashier','registrar','system') NOT NULL DEFAULT 'system',
    student_id  INT UNSIGNED DEFAULT NULL,
    cashier_id  INT UNSIGNED DEFAULT NULL,
    registrar_id INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(60) NOT NULL,
    detail      VARCHAR(500) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_student   (student_id),
    KEY idx_audit_cashier  (cashier_id),
    KEY idx_audit_registrar (registrar_id),
    KEY idx_audit_type    (actor_type),
    KEY idx_audit_action  (action),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_audit_cashier FOREIGN KEY (cashier_id)
        REFERENCES cashiers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_audit_registrar FOREIGN KEY (registrar_id)
        REFERENCES registrars(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- FUNCTION, STORED PROCEDURES & TRIGGERS
-- Import this file via MySQL CLI:
--   mysql -u root `student-req` < database.sql
-- Or in phpMyAdmin: Import tab, choose this file.
-- ===========================================================================

DROP FUNCTION IF EXISTS fn_request_age_days;
DELIMITER //
CREATE FUNCTION fn_request_age_days(p_created_at DATETIME)
RETURNS INT DETERMINISTIC READS SQL DATA
BEGIN
    IF p_created_at IS NULL THEN RETURN 0; END IF;
    RETURN DATEDIFF(CURRENT_DATE, DATE(p_created_at));
END//

DROP PROCEDURE IF EXISTS sp_calculate_fee//
CREATE PROCEDURE sp_calculate_fee(
    IN  p_service_id INT UNSIGNED,
    OUT p_fee        DECIMAL(10,2),
    OUT p_name       VARCHAR(120))
BEGIN
    DECLARE v_active TINYINT(1) DEFAULT NULL;
    SELECT fee, name, is_active INTO p_fee, p_name, v_active
    FROM services WHERE id = p_service_id;
    IF p_fee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service not found.';
    END IF;
    IF v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service is not active.';
    END IF;
END//

DROP PROCEDURE IF EXISTS sp_submit_request//
CREATE PROCEDURE sp_submit_request(
    IN  p_student_id   INT UNSIGNED,
    IN  p_service_id   INT UNSIGNED,
    IN  p_notes        VARCHAR(500),
    OUT p_request_id   INT UNSIGNED,
    OUT p_ref_no       VARCHAR(24),
    OUT p_fee          DECIMAL(10,2))
BEGIN
    DECLARE v_sactive   TINYINT(1)    DEFAULT NULL;
    DECLARE v_svcactive TINYINT(1)    DEFAULT NULL;
    DECLARE v_fee       DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_open      INT           DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT 1 INTO v_sactive FROM students WHERE id = p_student_id FOR UPDATE;
    IF v_sactive IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Student account not found.';
    END IF;
    SELECT fee, is_active INTO v_fee, v_svcactive FROM services WHERE id = p_service_id;
    IF v_fee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service not found.';
    END IF;
    IF v_svcactive = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service is not available.';
    END IF;
    IF p_notes IS NOT NULL AND CHAR_LENGTH(p_notes) > 500 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notes limited to 500 characters.';
    END IF;
    SELECT COUNT(*) INTO v_open FROM requests
    WHERE student_id = p_student_id AND service_id = p_service_id
      AND status IN ('pending','approved','processing');
    IF v_open >= 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Too many open requests for this service.';
    END IF;
    SET p_ref_no = CONCAT('REQ-', DATE_FORMAT(NOW(), '%Y%m%d'), '-',
        UPPER(SUBSTRING(MD5(CONCAT(p_student_id, p_service_id, NOW(), RAND())), 1, 6)));
    INSERT INTO requests (student_id, service_id, ref_no, notes, fee, status)
    VALUES (p_student_id, p_service_id, p_ref_no, p_notes, v_fee, 'pending');
    SET p_request_id = LAST_INSERT_ID();
    SET p_fee = v_fee;
    COMMIT;
END//

DROP PROCEDURE IF EXISTS sp_start_payment//
CREATE PROCEDURE sp_start_payment(
    IN  p_request_id  INT UNSIGNED,
    IN  p_student_id  INT UNSIGNED,
    IN  p_method      VARCHAR(20),
    OUT p_payment_id  INT UNSIGNED,
    OUT p_txn         VARCHAR(60))
BEGIN
    DECLARE v_fee      DECIMAL(10,2);
    DECLARE v_status   VARCHAR(20);
    DECLARE v_owner    INT UNSIGNED;
    DECLARE v_existing INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_method NOT IN ('gcash','maya','card','bank_transfer','mock') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payment method.';
    END IF;
    START TRANSACTION;
    SELECT student_id, fee, status INTO v_owner, v_fee, v_status
    FROM requests WHERE id = p_request_id FOR UPDATE;
    IF v_owner IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request not found.';
    END IF;
    IF v_owner <> p_student_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You can only pay your own requests.';
    END IF;
    IF v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request must be approved by registrar before payment.';
    END IF;
    SELECT id INTO v_existing FROM payments
    WHERE request_id = p_request_id AND status IN ('pending','failed')
    ORDER BY id DESC LIMIT 1;
    IF v_existing IS NOT NULL THEN
        SELECT transaction_number INTO p_txn FROM payments WHERE id = v_existing;
        SET p_payment_id = v_existing;
        COMMIT;
    ELSE
        SET p_txn = CONCAT('TXN-', DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'), '-',
            UPPER(SUBSTRING(MD5(CONCAT(p_request_id, NOW(), RAND())), 1, 6)));
        INSERT INTO payments (request_id, method, amount, currency, transaction_number, status)
        VALUES (p_request_id, p_method, v_fee, 'PHP', p_txn, 'pending');
        SET p_payment_id = LAST_INSERT_ID();
        COMMIT;
    END IF;
END//

DROP PROCEDURE IF EXISTS sp_process_request//
CREATE PROCEDURE sp_process_request(
    IN p_request_id  INT UNSIGNED,
    IN p_actor_id    INT UNSIGNED,
    IN p_actor_type  VARCHAR(20),
    IN p_new_status  VARCHAR(20),
    IN p_admin_note  VARCHAR(500))
BEGIN
    DECLARE v_id  INT UNSIGNED;
    DECLARE v_ref VARCHAR(24);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_new_status NOT IN ('pending','approved','processing','ready','released','rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid request status.';
    END IF;
    START TRANSACTION;
    SELECT id, ref_no INTO v_id, v_ref FROM requests WHERE id = p_request_id FOR UPDATE;
    IF v_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request not found.';
    END IF;
    UPDATE requests SET status = p_new_status, admin_note = p_admin_note, updated_at = NOW()
    WHERE id = p_request_id;
    IF p_actor_type = 'registrar' THEN
        INSERT INTO audit_logs (actor_type, registrar_id, action, detail)
        VALUES (p_actor_type, p_actor_id, 'request_status',
                CONCAT('Set ', v_ref, ' to "', p_new_status, '"'));
    ELSEIF p_actor_type = 'cashier' THEN
        INSERT INTO audit_logs (actor_type, cashier_id, action, detail)
        VALUES (p_actor_type, p_actor_id, 'request_status',
                CONCAT('Set ', v_ref, ' to "', p_new_status, '"'));
    ELSEIF p_actor_type = 'student' THEN
        INSERT INTO audit_logs (actor_type, student_id, action, detail)
        VALUES (p_actor_type, p_actor_id, 'request_status',
                CONCAT('Set ', v_ref, ' to "', p_new_status, '"'));
    ELSE
        INSERT INTO audit_logs (actor_type, action, detail)
        VALUES (p_actor_type, 'request_status',
                CONCAT('Set ', v_ref, ' to "', p_new_status, '"'));
    END IF;
    COMMIT;
END//

DROP PROCEDURE IF EXISTS sp_payment_summary//
CREATE PROCEDURE sp_payment_summary(IN p_from DATE, IN p_to DATE)
BEGIN
    SELECT DATE(p.paid_at) AS payment_date, p.method AS payment_method,
           COUNT(*) AS txn_count, SUM(p.amount) AS total_amount
    FROM payments p
    WHERE p.status = 'paid'
      AND (p_from IS NULL OR DATE(p.paid_at) >= p_from)
      AND (p_to   IS NULL OR DATE(p.paid_at) <= p_to)
    GROUP BY DATE(p.paid_at), p.method
    ORDER BY payment_date DESC, payment_method ASC;
END//

-- TRIGGERS
DROP TRIGGER IF EXISTS trg_requests_after_insert//
CREATE TRIGGER trg_requests_after_insert
AFTER INSERT ON requests FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (actor_type, student_id, action, detail)
    VALUES ('student', NEW.student_id, 'request_submitted',
            CONCAT('Request ', NEW.ref_no, ' submitted (fee: ', NEW.fee, ')'));
END//

DROP TRIGGER IF EXISTS trg_payments_before_update//
CREATE TRIGGER trg_payments_before_update
BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF NEW.status = 'paid' AND OLD.status <> 'paid' AND NEW.paid_at IS NULL THEN
        SET NEW.paid_at = NOW();
    END IF;
    IF OLD.status = 'paid' AND NEW.status = 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot revert a paid payment to pending.';
    END IF;
END//

DROP TRIGGER IF EXISTS trg_payments_after_update//
CREATE TRIGGER trg_payments_after_update
AFTER UPDATE ON payments FOR EACH ROW
BEGIN
    IF NEW.status = 'paid' AND OLD.status <> 'paid' THEN
        IF NOT EXISTS (SELECT 1 FROM receipts WHERE payment_id = NEW.id) THEN
            INSERT INTO receipts (payment_id, receipt_number, issued_at)
            VALUES (NEW.id,
                    CONCAT('RCPT-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(NEW.id, 6, '0')),
                    NOW());
        END IF;
        INSERT INTO audit_logs (actor_type, action, detail)
        VALUES ('system', 'payment_paid',
                CONCAT('Payment ', NEW.transaction_number, ' PAID via ', NEW.method));
    END IF;
    IF NEW.status = 'failed' AND OLD.status <> 'failed' THEN
        INSERT INTO audit_logs (actor_type, action, detail)
        VALUES ('system', 'payment_failed',
                CONCAT('Payment ', NEW.transaction_number, ' FAILED: ',
                       COALESCE(NEW.failure_reason, 'unknown')));
    END IF;
END//

DELIMITER ;

-- ===========================================================================
-- SEED DATA
-- Default password for all seed accounts: Admin@1234
-- CHANGE THESE before going live!
-- ===========================================================================
INSERT IGNORE INTO registrars (employee_no, full_name, email, password_hash) VALUES
('REG-00001', 'Registrar Admin', 'registrar.admin@gmail.com',
 '$2y$10$E.Lalmi1/ZaVZ73YQDbduOg87KLNdj437qWQJEXrrUicfVy3Lmbn6');

INSERT IGNORE INTO cashiers (employee_no, full_name, email, password_hash) VALUES
('CSH-00001', 'Cashier Admin', 'cashier.admin@gmail.com',
 '$2y$10$E.Lalmi1/ZaVZ73YQDbduOg87KLNdj437qWQJEXrrUicfVy3Lmbn6');

-- SAMPLE SERVICES
INSERT IGNORE INTO services (name, description, fee, processing_days, is_active) VALUES
('Certificate of Enrollment',  'Official proof that you are currently enrolled.',      50.00,  2, 1),
('Transcript of Records',      'Certified copy of your academic records.',             150.00, 5, 1),
('Certificate of Grades',      'Certified copy of grades for the current term.',        75.00, 3, 1),
('Good Moral Certificate',     'Character reference issued by the registrar.',          50.00, 3, 1),
('Certificate of Graduation',  'Issued after completion of all degree requirements.', 100.00,  7, 1),
('Diploma Authentication',     'Official authentication/verification of diploma.',    200.00, 10, 1);
