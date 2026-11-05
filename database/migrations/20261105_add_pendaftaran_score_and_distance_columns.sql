-- Additive, rerunnable migration for registration values used by the app.
-- Existing rows are preserved; historical values are not inferred.
DELIMITER //

DROP PROCEDURE IF EXISTS `__migration_20261105_pendaftaran_fields`//
CREATE PROCEDURE `__migration_20261105_pendaftaran_fields`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'jarak_rumah_sekolah'
    ) THEN
        ALTER TABLE `pendaftaran`
            ADD COLUMN `jarak_rumah_sekolah` DECIMAL(10,2) NULL
            COMMENT 'Jarak ke sekolah pilihan 1 dalam kilometer';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'jarak_sekolah_2'
    ) THEN
        ALTER TABLE `pendaftaran`
            ADD COLUMN `jarak_sekolah_2` DECIMAL(10,2) NULL
            COMMENT 'Jarak ke sekolah pilihan 2 dalam kilometer';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'nilai_semester_1'
    ) THEN
        ALTER TABLE `pendaftaran` ADD COLUMN `nilai_semester_1` DECIMAL(5,2) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'nilai_semester_2'
    ) THEN
        ALTER TABLE `pendaftaran` ADD COLUMN `nilai_semester_2` DECIMAL(5,2) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'nilai_semester_3'
    ) THEN
        ALTER TABLE `pendaftaran` ADD COLUMN `nilai_semester_3` DECIMAL(5,2) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'nilai_semester_4'
    ) THEN
        ALTER TABLE `pendaftaran` ADD COLUMN `nilai_semester_4` DECIMAL(5,2) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND column_name = 'nilai_semester_5'
    ) THEN
        ALTER TABLE `pendaftaran` ADD COLUMN `nilai_semester_5` DECIMAL(5,2) NULL;
    END IF;
END//

CALL `__migration_20261105_pendaftaran_fields`()//
DROP PROCEDURE `__migration_20261105_pendaftaran_fields`//
DELIMITER ;
