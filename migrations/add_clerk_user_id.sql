-- ===========================================================================
-- Migration: add_clerk_user_id
-- Adds the optional `clerk_user_id` column to all three user tables so that
-- accounts registered manually (email + password) can be persistently linked
-- to a Google / Clerk OAuth identity on first "Continue with Google" sign-in.
--
-- Safe to run multiple times (uses IF NOT EXISTS guards via INFORMATION_SCHEMA).
-- Run via:
--   mysql -u root student-req < migrations/add_clerk_user_id.sql
-- Or paste into phpMyAdmin → SQL tab.
-- ===========================================================================

USE `student-req`;

-- students -------------------------------------------------------------------
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS clerk_user_id VARCHAR(64) DEFAULT NULL
        COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)'
        AFTER year_level;

-- Add unique index only if it doesn't already exist
SET @idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'students'
      AND INDEX_NAME   = 'uq_students_clerk_id'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE students ADD UNIQUE KEY uq_students_clerk_id (clerk_user_id)',
    'SELECT ''Index uq_students_clerk_id already exists on students'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cashiers -------------------------------------------------------------------
ALTER TABLE cashiers
    ADD COLUMN IF NOT EXISTS clerk_user_id VARCHAR(64) DEFAULT NULL
        COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)'
        AFTER password_hash;

SET @idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cashiers'
      AND INDEX_NAME   = 'uq_cashiers_clerk_id'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE cashiers ADD UNIQUE KEY uq_cashiers_clerk_id (clerk_user_id)',
    'SELECT ''Index uq_cashiers_clerk_id already exists on cashiers'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- registrars -----------------------------------------------------------------
ALTER TABLE registrars
    ADD COLUMN IF NOT EXISTS clerk_user_id VARCHAR(64) DEFAULT NULL
        COMMENT 'Clerk/Google OAuth user ID (set on first Google sign-in)'
        AFTER password_hash;

SET @idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'registrars'
      AND INDEX_NAME   = 'uq_registrars_clerk_id'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE registrars ADD UNIQUE KEY uq_registrars_clerk_id (clerk_user_id)',
    'SELECT ''Index uq_registrars_clerk_id already exists on registrars'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration add_clerk_user_id completed successfully.' AS result;
