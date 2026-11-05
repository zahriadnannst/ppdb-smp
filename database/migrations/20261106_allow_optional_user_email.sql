-- Allow multiple accounts without an email while retaining uniqueness for provided email addresses.
-- Existing values are preserved. Run as a complete script in a MySQL/MariaDB client supporting DELIMITER.
DELIMITER //

DROP PROCEDURE IF EXISTS `__migration_20261106_allow_optional_user_email`//
CREATE PROCEDURE `__migration_20261106_allow_optional_user_email`()
BEGIN
    DECLARE email_column_count INT DEFAULT 0;
    DECLARE email_data_type VARCHAR(64) DEFAULT NULL;
    DECLARE email_length BIGINT DEFAULT NULL;
    DECLARE email_nullable VARCHAR(3) DEFAULT NULL;
    DECLARE named_index_parts INT DEFAULT 0;
    DECLARE matching_index_parts INT DEFAULT 0;
    DECLARE matching_unique_indexes INT DEFAULT 0;
    DECLARE duplicate_emails INT DEFAULT 0;

    SELECT COUNT(*)
    INTO email_column_count
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'email';

    IF email_column_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'users.email is missing; optional-email migration stopped.';
    END IF;

    SELECT data_type, character_maximum_length, is_nullable
    INTO email_data_type, email_length, email_nullable
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'email';

    IF LOWER(email_data_type) <> 'varchar' OR email_length <> 100 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'users.email must be VARCHAR(100); migration stopped to preserve its definition.';
    END IF;

    SELECT COUNT(*)
    INTO named_index_parts
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uq_users_email';

    IF named_index_parts > 0 THEN
        SELECT COUNT(*)
        INTO matching_index_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'uq_users_email'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'email'
          AND sub_part IS NULL;

        IF named_index_parts <> 1 OR matching_index_parts <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Index uq_users_email exists with an unexpected definition; migration stopped.';
        END IF;
    END IF;

    IF email_nullable <> 'YES' THEN
        ALTER TABLE `users`
            MODIFY COLUMN `email` VARCHAR(100) NULL DEFAULT NULL;
    END IF;

    SELECT COUNT(*)
    INTO matching_unique_indexes
    FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND non_unique = 0
          AND sub_part IS NULL
        GROUP BY index_name
        HAVING COUNT(*) = 1
           AND SUM(seq_in_index = 1 AND column_name = 'email') = 1
    ) AS exact_email_indexes;

    IF matching_unique_indexes = 0 THEN
        SELECT COUNT(*)
        INTO duplicate_emails
        FROM (
            SELECT email
            FROM users
            WHERE email IS NOT NULL
            GROUP BY email
            HAVING COUNT(*) > 1
        ) AS duplicate_email_values;

        IF duplicate_emails > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Duplicate non-NULL users.email values exist; resolve them before adding the unique index.';
        END IF;

        IF named_index_parts > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Index uq_users_email is not a valid single-column unique index; migration stopped.';
        END IF;

        ALTER TABLE `users`
            ADD UNIQUE KEY `uq_users_email` (`email`);
    END IF;
END//

CALL `__migration_20261106_allow_optional_user_email`()//
DROP PROCEDURE `__migration_20261106_allow_optional_user_email`//

DELIMITER ;
