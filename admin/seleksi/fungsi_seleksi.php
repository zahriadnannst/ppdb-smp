<?php
/**
 * File: admin/seleksi/fungsi_seleksi.php
 * Deskripsi: Kumpulan fungsi logika seleksi (Dual Choice)
 */

function filter_dokumen_jalur($sql, $nama_jalur) {
    $sql .= " AND EXISTS (
        SELECT 1
        FROM dokumen_siswa ds
        WHERE ds.id_siswa = p.id_siswa
          AND ds.kategori = 'umum'
          AND ds.status_verifikasi = 'valid'
          AND ds.jenis_dokumen IN ('kk', 'skl', 'rapor', 'foto')
        GROUP BY ds.id_siswa
        HAVING COUNT(DISTINCT ds.jenis_dokumen) = 4
    )";

    if ($nama_jalur === 'Afirmasi') {
        return $sql . " AND EXISTS (
            SELECT 1 FROM dokumen_siswa ds
            WHERE ds.id_siswa = p.id_siswa
              AND ds.kategori = 'afirmasi'
              AND ds.status_verifikasi = 'valid'
        )";
    }

    if ($nama_jalur === 'Prestasi') {
        return $sql . " AND EXISTS (
            SELECT 1 FROM dokumen_siswa ds
            WHERE ds.id_siswa = p.id_siswa
              AND ds.kategori = 'prestasi'
              AND ds.status_verifikasi = 'valid'
              AND (ds.tanggal_terbit IS NULL OR YEAR(CURDATE()) - YEAR(ds.tanggal_terbit) <= 3)
        )";
    }

    if ($nama_jalur === 'Mutasi') {
        return $sql . " AND EXISTS (
            SELECT 1 FROM dokumen_siswa ds
            WHERE ds.id_siswa = p.id_siswa
              AND ds.kategori = 'mutasi'
              AND ds.status_verifikasi = 'valid'
        )";
    }

    return $sql;
}

function nama_lock_seleksi($tahun_ajaran_val, $id_jalur) {
    return 'ppdb_selection_' . substr(hash('sha256', $tahun_ajaran_val . '|' . (int)$id_jalur), 0, 48);
}

function nama_lock_tahun_seleksi($tahun_ajaran_val) {
    return 'ppdb_selection_year_' . substr(hash('sha256', $tahun_ajaran_val), 0, 40);
}

function proses_seleksi_pilihan_pertama($conn, $id_sekolah, $id_jalur, $kuota, $tahun_ajaran_val) {
    global $log_proses;

    $nama_jalur = get_nama_jalur_func($conn, $id_jalur);
    $sql = "SELECT p.*, s.tanggal_lahir, s.nama_lengkap
            FROM pendaftaran p
            JOIN siswa s ON p.id_siswa = s.id_siswa
            WHERE p.id_sekolah = ?
              AND p.id_jalur = ?
              AND p.tahun_ajaran = ?
              AND p.status_verifikasi = 'terverifikasi'";
    $sql = filter_dokumen_jalur($sql, $nama_jalur);
    $sql .= " ORDER BY " . get_order_clause_func($nama_jalur);

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $id_sekolah, $id_jalur, $tahun_ajaran_val);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $ranking = 1;
    $accepted = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $status = $accepted < $kuota ? 'lulus' : 'tidak_lulus';
        $pilihan = $status === 'lulus' ? 1 : null;
        if ($pilihan !== null) {
            $accepted++;
        }

        simpan_hasil_func($conn, $row, $ranking, $status, $pilihan, $nama_jalur, $id_sekolah);
        if (isset($log_proses)) {
            $log_proses[] = "#$ranking [P1] {$row['nama_lengkap']} - Status: " . strtoupper($status);
        }
        $ranking++;
    }

    return $accepted;
}

function proses_seleksi_pilihan_kedua($conn, $id_sekolah, $id_jalur, $tahun_ajaran_val, $sisa_kuota, $ranking_awal) {
    global $log_proses;

    if ($sisa_kuota <= 0) {
        return 0;
    }

    $nama_jalur = get_nama_jalur_func($conn, $id_jalur);
    $sql = "SELECT p.*, s.tanggal_lahir, s.nama_lengkap
            FROM pendaftaran p
            JOIN siswa s ON p.id_siswa = s.id_siswa
            JOIN hasil_seleksi hs ON hs.id_pendaftaran = p.id_pendaftaran
                                AND hs.id_jalur = p.id_jalur
                                AND hs.tahun_ajaran = p.tahun_ajaran
            WHERE p.id_sekolah_pilihan_2 = ?
              AND p.id_jalur = ?
              AND p.tahun_ajaran = ?
              AND p.status_verifikasi = 'terverifikasi'
              AND hs.status_seleksi = 'tidak_lulus'
              AND hs.dipublikasikan = 0";
    $sql = filter_dokumen_jalur($sql, $nama_jalur);
    $sql .= " ORDER BY " . get_order_clause_p2_func($nama_jalur);

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $id_sekolah, $id_jalur, $tahun_ajaran_val);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $accepted = 0;
    $rank = $ranking_awal + 1;
    while ($row = mysqli_fetch_assoc($result)) {
        if ($accepted < $sisa_kuota) {
            $skor = $nama_jalur === 'Prestasi'
                ? (float)$row['nilai_rapor_rata']
                : 100 - (float)$row['jarak_sekolah_2'];
            $keterangan = "$nama_jalur (Pilihan 2)";
            $stmt_update = mysqli_prepare(
                $conn,
                "UPDATE hasil_seleksi
                 SET id_sekolah = ?, id_jalur = ?, tahun_ajaran = ?, ranking = ?,
                     skor_akhir = ?, status_seleksi = 'lulus', pilihan_diterima = 2,
                     keterangan = ?, tanggal_seleksi = NOW()
                 WHERE id_pendaftaran = ? AND id_jalur = ? AND tahun_ajaran = ?
                   AND status_seleksi = 'tidak_lulus' AND dipublikasikan = 0"
            );
            mysqli_stmt_bind_param(
                $stmt_update,
                "iisidsiis",
                $id_sekolah,
                $id_jalur,
                $tahun_ajaran_val,
                $rank,
                $skor,
                $keterangan,
                $row['id_pendaftaran'],
                $id_jalur,
                $tahun_ajaran_val
            );
            mysqli_stmt_execute($stmt_update);
            if (mysqli_stmt_affected_rows($stmt_update) !== 1) {
                throw new RuntimeException("Hasil Pilihan 1 untuk pendaftaran ID {$row['id_pendaftaran']} tidak dapat diperbarui menjadi hasil Pilihan 2.");
            }

            $accepted++;
            if (isset($log_proses)) {
                $log_proses[] = "#$rank [P2] {$row['nama_lengkap']} - Status: LULUS";
            }
        } elseif (isset($log_proses)) {
            $log_proses[] = "[P2] {$row['nama_lengkap']} - Status: TIDAK LULUS (Kuota Penuh)";
        }
        $rank++;
    }

    return $accepted;
}

function get_nama_jalur_func($conn, $id_jalur) {
    $stmt = mysqli_prepare($conn, "SELECT nama_jalur FROM jalur_ppdb WHERE id_jalur = ?");
    mysqli_stmt_bind_param($stmt, "i", $id_jalur);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        throw new RuntimeException("Jalur PPDB ID $id_jalur tidak ditemukan.");
    }
    return $row['nama_jalur'];
}

define('JALUR_ZONASI', 1);
define('JALUR_PRESTASI', 2);
define('JALUR_AFIRMASI', 3);
define('JALUR_MUTASI', 4);

function get_order_clause_func($nama_jalur) {
    switch ($nama_jalur) {
        case 'Zonasi':
        case 'Afirmasi':
        case 'Mutasi':
            return "p.jarak_rumah_sekolah ASC, s.tanggal_lahir ASC, p.tanggal_daftar ASC";
        case 'Prestasi':
            return "p.nilai_rapor_rata DESC, p.jarak_rumah_sekolah ASC, s.tanggal_lahir ASC, p.tanggal_daftar ASC";
        default:
            return "p.id_pendaftaran ASC";
    }
}

function get_order_clause_p2_func($nama_jalur) {
    switch ($nama_jalur) {
        case 'Zonasi':
        case 'Afirmasi':
        case 'Mutasi':
            return "p.jarak_sekolah_2 ASC, s.tanggal_lahir ASC, p.tanggal_daftar ASC";
        case 'Prestasi':
            return "p.nilai_rapor_rata DESC, p.jarak_sekolah_2 ASC, s.tanggal_lahir ASC, p.tanggal_daftar ASC";
        default:
            return "p.id_pendaftaran ASC";
    }
}

function simpan_hasil_func($conn, $row, $ranking, $status, $pilihan_diterima, $nama_jalur, $id_sekolah_hasil) {
    global $log_proses;

    $stmt_existing = mysqli_prepare(
        $conn,
        "SELECT id_hasil FROM hasil_seleksi WHERE id_pendaftaran = ? LIMIT 1"
    );
    if (!$stmt_existing) {
        throw new RuntimeException("Gagal memeriksa hasil sebelumnya: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt_existing, "i", $row['id_pendaftaran']);
    if (!mysqli_stmt_execute($stmt_existing)) {
        $error = mysqli_stmt_error($stmt_existing);
        mysqli_stmt_close($stmt_existing);
        throw new RuntimeException("Gagal memeriksa hasil sebelumnya: $error");
    }
    $existing_result = mysqli_stmt_get_result($stmt_existing);
    $has_existing_result = mysqli_fetch_assoc($existing_result) !== null;
    mysqli_stmt_close($stmt_existing);
    if ($has_existing_result) {
        throw new RuntimeException("Pendaftaran ID {$row['id_pendaftaran']} sudah memiliki hasil seleksi; penyimpanan duplikat dibatalkan.");
    }

    $skor = $nama_jalur === 'Prestasi'
        ? (float)$row['nilai_rapor_rata']
        : 100 - (float)$row['jarak_rumah_sekolah'];
    $keterangan = "$nama_jalur (Pilihan 1)";
    $pilihan = $pilihan_diterima !== null ? (int)$pilihan_diterima : null;
    $sql = "INSERT INTO hasil_seleksi
            (id_pendaftaran, id_sekolah, id_jalur, tahun_ajaran, ranking, skor_akhir,
             status_seleksi, pilihan_diterima, keterangan, tanggal_seleksi)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $error = mysqli_error($conn);
        if (isset($log_proses)) {
            $log_proses[] = "ERROR: Gagal menyiapkan hasil seleksi pendaftaran ID {$row['id_pendaftaran']}: $error";
        }
        throw new RuntimeException("Gagal menyiapkan penyimpanan hasil seleksi: $error");
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iiisidsis",
        $row['id_pendaftaran'],
        $id_sekolah_hasil,
        $row['id_jalur'],
        $row['tahun_ajaran'],
        $ranking,
        $skor,
        $status,
        $pilihan,
        $keterangan
    );
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        if (isset($log_proses)) {
            $log_proses[] = "ERROR: Gagal menyimpan hasil seleksi pendaftaran ID {$row['id_pendaftaran']}: $error";
        }
        mysqli_stmt_close($stmt);
        throw new RuntimeException("Gagal menyimpan hasil seleksi pendaftaran ID {$row['id_pendaftaran']}: $error");
    }
    mysqli_stmt_close($stmt);
}
?>
