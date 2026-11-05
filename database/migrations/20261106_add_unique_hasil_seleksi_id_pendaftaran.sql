-- Enforce one final selection result per registration.
-- This migration does not modify or delete existing data.
-- Run as a complete script in a MySQL/MariaDB client that supports DELIMITER.
-- The helper routine name is reserved for this migration; no other routines are dropped.
DELIMITER //

DROP PROCEDURE IF EXISTS `__migration_20261106_add_unique_hasil_seleksi_id_pendaftaran`//
CREATE PROCEDURE `__migration_20261106_add_unique_hasil_seleksi_id_pendaftaran`()
BEGIN
    DECLARE named_index_parts INT DEFAULT 0;
    DECLARE matching_index_parts INT DEFAULT 0;
    DECLARE matching_any_index_count INT DEFAULT 0;
    DECLARE duplicate_registrations INT DEFAULT 0;

    SELECT COUNT(*)
    INTO named_index_parts
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'hasil_seleksi'
      AND index_name = 'uq_hasil_seleksi_id_pendaftaran';

    IF named_index_parts > 0 THEN
        SELECT COUNT(*)
        INTO matching_index_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'hasil_seleksi'
          AND index_name = 'uq_hasil_seleksi_id_pendaftaran'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'id_pendaftaran'
          AND sub_part IS NULL
          AND index_type = 'BTREE';

        IF named_index_parts <> 1 OR matching_index_parts <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Index uq_hasil_seleksi_id_pendaftaran exists with an unexpected definition; migration stopped.';
        END IF;
    ELSE
        SELECT COUNT(*)
        INTO matching_any_index_count
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'hasil_seleksi'
              AND non_unique = 0
              AND sub_part IS NULL
              AND index_type = 'BTREE'
            GROUP BY index_name
            HAVING COUNT(*) = 1
               AND SUM(seq_in_index = 1 AND column_name = 'id_pendaftaran') = 1
        ) AS exact_unique_indexes;

        IF matching_any_index_count = 0 THEN
            SELECT COUNT(*)
            INTO duplicate_registrations
            FROM (
                SELECT id_pendaftaran
                FROM hasil_seleksi
                GROUP BY id_pendaftaran
                HAVING COUNT(*) > 1
            ) AS duplicate_rows;

            IF duplicate_registrations > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Duplicate id_pendaftaran values exist in hasil_seleksi; resolve them before adding the unique index.';
            END IF;

            ALTER TABLE `hasil_seleksi`
                ADD UNIQUE KEY `uq_hasil_seleksi_id_pendaftaran` (`id_pendaftaran`);
        END IF;
    END IF;
END//

CALL `__migration_20261106_add_unique_hasil_seleksi_id_pendaftaran`()//
DROP PROCEDURE `__migration_20261106_add_unique_hasil_seleksi_id_pendaftaran`//

DELIMITER ;
