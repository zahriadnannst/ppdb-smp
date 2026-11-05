-- Additive schema alignment for installations created from earlier schemas.
-- Does not remove application data. Stops before unique constraints if duplicates
-- or orphaned parent records prevent a safe migration.
DELIMITER //

DROP PROCEDURE IF EXISTS `__migration_20261106_align_application_schema`//
CREATE PROCEDURE `__migration_20261106_align_application_schema`()
BEGIN
    DECLARE duplicate_rows INT DEFAULT 0;
    DECLARE named_index_parts INT DEFAULT 0;
    DECLARE matching_index_parts INT DEFAULT 0;
    DECLARE matching_any_index_count INT DEFAULT 0;
    DECLARE foreign_key_count INT DEFAULT 0;
    DECLARE orphan_rows INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_active'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_login'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `last_login` DATETIME NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'siswa' AND column_name = 'alamat_lengkap'
    ) THEN
        ALTER TABLE `siswa` ADD COLUMN `alamat_lengkap` TEXT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'siswa' AND column_name = 'rt'
    ) THEN
        ALTER TABLE `siswa` ADD COLUMN `rt` VARCHAR(5) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'siswa' AND column_name = 'rw'
    ) THEN
        ALTER TABLE `siswa` ADD COLUMN `rw` VARCHAR(5) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'jalur_ppdb' AND column_name = 'kode_jalur'
    ) THEN
        ALTER TABLE `jalur_ppdb` ADD COLUMN `kode_jalur` VARCHAR(20) NULL;
    END IF;

    CREATE TABLE IF NOT EXISTS `orang_tua` (
        `id_orang_tua` INT AUTO_INCREMENT PRIMARY KEY,
        `id_siswa` INT NOT NULL UNIQUE,
        `nama_ayah` VARCHAR(100) NULL,
        `nik_ayah` VARCHAR(20) NULL,
        `pekerjaan_ayah` VARCHAR(50) NULL,
        `penghasilan_ayah` DECIMAL(15,2) NULL,
        `nama_ibu` VARCHAR(100) NULL,
        `nik_ibu` VARCHAR(20) NULL,
        `pekerjaan_ibu` VARCHAR(50) NULL,
        `penghasilan_ibu` DECIMAL(15,2) NULL,
        `no_telepon_ortu` VARCHAR(20) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'jadwal_jalur'
          AND column_name = 'status' AND column_type LIKE '%daftar_ulang%'
    ) THEN
        ALTER TABLE `jadwal_jalur`
            MODIFY COLUMN `status`
            ENUM('belum_dibuka','dibuka','ditutup','seleksi','pengumuman','daftar_ulang','selesai')
            NULL DEFAULT 'belum_dibuka';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'hasil_seleksi'
          AND column_name = 'keterangan' AND data_type = 'text'
    ) THEN
        ALTER TABLE `hasil_seleksi` MODIFY COLUMN `keterangan` TEXT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'hasil_seleksi'
          AND column_name = 'tanggal_publikasi' AND data_type = 'datetime'
    ) THEN
        ALTER TABLE `hasil_seleksi`
            MODIFY COLUMN `tanggal_publikasi` DATETIME NULL DEFAULT NULL;
    END IF;

    UPDATE `jalur_ppdb`
    SET `kode_jalur` = CASE LOWER(`nama_jalur`)
        WHEN 'zonasi' THEN 'ZON'
        WHEN 'prestasi' THEN 'PRE'
        WHEN 'afirmasi' THEN 'AFI'
        WHEN 'mutasi' THEN 'MUT'
        ELSE CONCAT('J', LPAD(`id_jalur`, 6, '0'))
    END
    WHERE `kode_jalur` IS NULL OR `kode_jalur` = '';

    SELECT COUNT(*) INTO duplicate_rows
    FROM (
        SELECT `kode_jalur` FROM `jalur_ppdb`
        GROUP BY `kode_jalur` HAVING COUNT(*) > 1
    ) AS duplicate_codes;
    IF duplicate_rows > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate jalur_ppdb.kode_jalur values exist; resolve them before continuing.';
    END IF;
    ALTER TABLE `jalur_ppdb` MODIFY COLUMN `kode_jalur` VARCHAR(20) NOT NULL;

    SELECT COUNT(*) INTO named_index_parts
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'jalur_ppdb'
      AND index_name = 'uq_jalur_ppdb_kode_jalur';
    IF named_index_parts > 0 THEN
        SELECT COUNT(*) INTO matching_index_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'jalur_ppdb'
          AND index_name = 'uq_jalur_ppdb_kode_jalur'
          AND non_unique = 0 AND seq_in_index = 1
          AND column_name = 'kode_jalur' AND sub_part IS NULL;
        IF named_index_parts <> 1 OR matching_index_parts <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Index uq_jalur_ppdb_kode_jalur exists with an unexpected definition.';
        END IF;
    ELSE
        SELECT COUNT(*) INTO matching_any_index_count
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'jalur_ppdb'
              AND non_unique = 0 AND sub_part IS NULL
            GROUP BY index_name
            HAVING COUNT(*) = 1
               AND SUM(seq_in_index = 1 AND column_name = 'kode_jalur') = 1
        ) AS exact_code_indexes;
        IF matching_any_index_count = 0 THEN
            ALTER TABLE `jalur_ppdb`
                ADD UNIQUE KEY `uq_jalur_ppdb_kode_jalur` (`kode_jalur`);
        END IF;
    END IF;

    SELECT COUNT(*) INTO orphan_rows
    FROM `orang_tua` ot
    LEFT JOIN `siswa` s ON s.id_siswa = ot.id_siswa
    WHERE s.id_siswa IS NULL;
    IF orphan_rows > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Orphan orang_tua rows exist; repair references before adding the foreign key.';
    END IF;

    SELECT COUNT(*) INTO foreign_key_count
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE() AND table_name = 'orang_tua'
      AND column_name = 'id_siswa' AND referenced_table_name = 'siswa'
      AND referenced_column_name = 'id_siswa';
    IF foreign_key_count = 0 THEN
        ALTER TABLE `orang_tua`
            ADD CONSTRAINT `fk_orang_tua_siswa`
            FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id_siswa`) ON DELETE CASCADE;
    END IF;

    SELECT COUNT(*) INTO named_index_parts
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
      AND index_name = 'uq_pendaftaran_siswa_tahun';
    IF named_index_parts > 0 THEN
        SELECT COUNT(*) INTO matching_index_parts
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
          AND index_name = 'uq_pendaftaran_siswa_tahun'
          AND non_unique = 0 AND sub_part IS NULL
          AND ((seq_in_index = 1 AND column_name = 'id_siswa')
            OR (seq_in_index = 2 AND column_name = 'tahun_ajaran'));
        IF named_index_parts <> 2 OR matching_index_parts <> 2 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Index uq_pendaftaran_siswa_tahun exists with an unexpected definition.';
        END IF;
    ELSE
        SELECT COUNT(*) INTO duplicate_rows
        FROM (
            SELECT `id_siswa`, `tahun_ajaran`
            FROM `pendaftaran`
            GROUP BY `id_siswa`, `tahun_ajaran`
            HAVING COUNT(*) > 1
        ) AS duplicate_registrations;
        IF duplicate_rows > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Multiple registrations per student and school year exist; resolve them before adding the unique index.';
        END IF;
        SELECT COUNT(*) INTO matching_any_index_count
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'pendaftaran'
              AND non_unique = 0 AND sub_part IS NULL
            GROUP BY index_name
            HAVING COUNT(*) = 2
               AND SUM(seq_in_index = 1 AND column_name = 'id_siswa') = 1
               AND SUM(seq_in_index = 2 AND column_name = 'tahun_ajaran') = 1
        ) AS exact_registration_indexes;
        IF matching_any_index_count = 0 THEN
            ALTER TABLE `pendaftaran`
                ADD UNIQUE KEY `uq_pendaftaran_siswa_tahun` (`id_siswa`, `tahun_ajaran`);
        END IF;
    END IF;
END//

CALL `__migration_20261106_align_application_schema`()//
DROP PROCEDURE `__migration_20261106_align_application_schema`//
DELIMITER ;
