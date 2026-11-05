<?php
/**
 * File: admin/seleksi/proses.php
 * Deskripsi: Proses seleksi otomatis PPDB dengan algoritma per jalur (Dual Choice Support)
 * 
 * ALGORITMA SELEKSI (DUAL CHOICE):
 * 1. TAHAP 1: Proses semua Pilihan 1.
 * 2. TAHAP 2: Proses Pilihan 2 bagi yang gagal di Tahap 1, memperebutkan sisa kuota.
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once 'fungsi_seleksi.php'; // Load logic function

check_admin();
check_super_admin(); // Only super_admin can run selection

$error = '';
$success = '';
$log_proses = array();
$tahun_ajaran = TAHUN_AJARAN;

// Cek status PPDB - harus dalam periode "seleksi"
$status_ppdb = get_status_ppdb();
$seleksi_diizinkan = ($status_ppdb == 'seleksi');

// ==========================================
// HANDLE REQUEST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['proses_seleksi_global'])) {
    
    // Validasi: hanya bisa proses saat status "seleksi"
    if (!verify_csrf()) {
        http_response_code(403);
        exit('Sesi tidak valid. Silakan muat ulang halaman.');
    } elseif (!$seleksi_diizinkan) {
        $error = "Proses seleksi hanya dapat dilakukan saat status PPDB adalah <strong>Seleksi</strong>. Status saat ini: <strong>" . ucfirst($status_ppdb) . "</strong>. Silakan ubah status di menu Pengaturan.";
    } else {
        $id_jalur = (int)clean_input($_POST['id_jalur']);
        $jadwal_jalur = get_jadwal_jalur($id_jalur);
        if (!$jadwal_jalur || $jadwal_jalur['status'] !== 'seleksi') {
            $error = "Jalur yang dipilih tidak memiliki jadwal aktif pada tahap seleksi untuk tahun ajaran $tahun_ajaran.";
        } else {
            $lock_names = [
                nama_lock_tahun_seleksi($tahun_ajaran),
                nama_lock_seleksi($tahun_ajaran, $id_jalur)
            ];
            $held_locks = [];
            $lock_acquired = true;
            foreach ($lock_names as $lock_name) {
                $lock_stmt = mysqli_prepare($conn, "SELECT GET_LOCK(?, 0)");
                if (!$lock_stmt) {
                    $error = "Gagal mempersiapkan penguncian proses seleksi: " . mysqli_error($conn);
                    $lock_acquired = false;
                    break;
                }

                mysqli_stmt_bind_param($lock_stmt, "s", $lock_name);
                if (!mysqli_stmt_execute($lock_stmt)) {
                    $error = "Gagal mengunci proses seleksi: " . mysqli_stmt_error($lock_stmt);
                    mysqli_stmt_close($lock_stmt);
                    $lock_acquired = false;
                    break;
                }

                $lock_result_set = mysqli_stmt_get_result($lock_stmt);
                $lock_result = $lock_result_set ? mysqli_fetch_row($lock_result_set) : null;
                if ($lock_result_set) {
                    mysqli_free_result($lock_result_set);
                }
                mysqli_stmt_close($lock_stmt);
                if (!$lock_result || (int)$lock_result[0] !== 1) {
                    $error = "Proses seleksi untuk tahun ajaran atau jalur ini sedang berjalan. Silakan coba lagi.";
                    $lock_acquired = false;
                    break;
                }
                $held_locks[] = $lock_name;
            }

            if (!$lock_acquired) {
                foreach ($held_locks as $held_lock_name) {
                    $release_stmt = mysqli_prepare($conn, "SELECT RELEASE_LOCK(?)");
                    if ($release_stmt) {
                        mysqli_stmt_bind_param($release_stmt, "s", $held_lock_name);
                        if (mysqli_stmt_execute($release_stmt)) {
                            $release_result = mysqli_stmt_get_result($release_stmt);
                            if ($release_result) {
                                mysqli_fetch_row($release_result);
                                mysqli_free_result($release_result);
                            }
                        }
                        mysqli_stmt_close($release_stmt);
                    }
                }
            }

        if ($lock_acquired ?? false) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $transaction_started = false;
            try {
                if (!mysqli_begin_transaction($conn)) {
                    throw new RuntimeException("Gagal memulai transaksi seleksi.");
                }
                $transaction_started = true;

                $stmt_inconsistent = mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*)
                     FROM hasil_seleksi hs
                     JOIN pendaftaran p ON p.id_pendaftaran = hs.id_pendaftaran
                     WHERE (
                            (p.tahun_ajaran = ? AND p.id_jalur = ?)
                            OR (hs.tahun_ajaran = ? AND hs.id_jalur = ?)
                           )
                       AND (hs.tahun_ajaran <> p.tahun_ajaran OR hs.id_jalur <> p.id_jalur)"
                );
                mysqli_stmt_bind_param(
                    $stmt_inconsistent,
                    "sisi",
                    $tahun_ajaran,
                    $id_jalur,
                    $tahun_ajaran,
                    $id_jalur
                );
                mysqli_stmt_execute($stmt_inconsistent);
                $inconsistent_count = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt_inconsistent))[0];
                if ($inconsistent_count > 0) {
                    throw new RuntimeException(
                        "Ditemukan $inconsistent_count hasil seleksi dengan tahun ajaran atau jalur yang tidak konsisten dengan pendaftarannya. Tidak ada hasil yang dihapus."
                    );
                }

                $stmt_published = mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*)
                     FROM hasil_seleksi hs
                     JOIN pendaftaran p ON p.id_pendaftaran = hs.id_pendaftaran
                     WHERE p.tahun_ajaran = ? AND p.id_jalur = ?
                       AND hs.tahun_ajaran = ? AND hs.id_jalur = ?
                       AND hs.dipublikasikan = 1"
                );
                mysqli_stmt_bind_param(
                    $stmt_published,
                    "sisi",
                    $tahun_ajaran,
                    $id_jalur,
                    $tahun_ajaran,
                    $id_jalur
                );
                mysqli_stmt_execute($stmt_published);
                $published_count = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt_published))[0];
                if ($published_count > 0) {
                    throw new RuntimeException("Hasil jalur ini sudah dipublikasikan. Batalkan publikasi secara resmi sebelum seleksi ulang.");
                }

                // Rebuild only unpublished results; the transaction preserves the previous set on failure.
                $stmt_delete = mysqli_prepare(
                    $conn,
                    "DELETE hs
                     FROM hasil_seleksi hs
                     JOIN pendaftaran p ON p.id_pendaftaran = hs.id_pendaftaran
                     WHERE p.tahun_ajaran = ? AND p.id_jalur = ?
                       AND hs.tahun_ajaran = ? AND hs.id_jalur = ?"
                );
                mysqli_stmt_bind_param(
                    $stmt_delete,
                    "sisi",
                    $tahun_ajaran,
                    $id_jalur,
                    $tahun_ajaran,
                    $id_jalur
                );
                mysqli_stmt_execute($stmt_delete);

                $stmt_reset_quota = mysqli_prepare(
                    $conn,
                    "UPDATE kuota_jalur SET kuota_terpakai = 0 WHERE id_jalur = ? AND tahun_ajaran = ?"
                );
                mysqli_stmt_bind_param($stmt_reset_quota, "is", $id_jalur, $tahun_ajaran);
                mysqli_stmt_execute($stmt_reset_quota);

                $stmt_schools = mysqli_prepare(
                    $conn,
                    "SELECT s.id_sekolah, s.nama_sekolah, s.status,
                            q.id_kuota, COALESCE(q.jumlah_kuota, 0) AS jumlah_kuota
                     FROM sekolah s
                     LEFT JOIN kuota_jalur q
                       ON q.id_sekolah = s.id_sekolah
                      AND q.id_jalur = ?
                      AND q.tahun_ajaran = ?
                     WHERE s.tahun_ajaran = ?
                     ORDER BY s.id_sekolah"
                );
                mysqli_stmt_bind_param($stmt_schools, "iss", $id_jalur, $tahun_ajaran, $tahun_ajaran);
                mysqli_stmt_execute($stmt_schools);
                $schools = mysqli_fetch_all(mysqli_stmt_get_result($stmt_schools), MYSQLI_ASSOC);

                $accepted_first_choice = [];
                $log_proses[] = "=== Tahap 1: seluruh sekolah, Pilihan 1 ===";
                foreach ($schools as $school) {
                    $capacity = $school['status'] === 'aktif' ? (int)$school['jumlah_kuota'] : 0;
                    $log_proses[] = "=== Memproses Pilihan 1: {$school['nama_sekolah']} (kuota $capacity) ===";
                    $accepted_first_choice[(int)$school['id_sekolah']] = proses_seleksi_pilihan_pertama(
                        $conn,
                        (int)$school['id_sekolah'],
                        $id_jalur,
                        $capacity,
                        $tahun_ajaran
                    );
                }

                $log_proses[] = "=== Tahap 2: Pilihan 2 bagi pendaftar yang belum lulus Pilihan 1 ===";
                foreach ($schools as $school) {
                    if ($school['status'] !== 'aktif' || empty($school['id_kuota'])) {
                        continue;
                    }

                    $school_id = (int)$school['id_sekolah'];
                    $capacity = (int)$school['jumlah_kuota'];
                    $accepted_p1 = $accepted_first_choice[$school_id] ?? 0;
                    $remaining_capacity = max(0, $capacity - $accepted_p1);
                    $accepted_p2 = proses_seleksi_pilihan_kedua(
                        $conn,
                        $school_id,
                        $id_jalur,
                        $tahun_ajaran,
                        $remaining_capacity,
                        $accepted_p1
                    );

                    $stmt_update_quota = mysqli_prepare(
                        $conn,
                        "UPDATE kuota_jalur SET kuota_terpakai = ? WHERE id_kuota = ?"
                    );
                    $used_capacity = $accepted_p1 + $accepted_p2;
                    mysqli_stmt_bind_param($stmt_update_quota, "ii", $used_capacity, $school['id_kuota']);
                    mysqli_stmt_execute($stmt_update_quota);
                }

                log_aktivitas($_SESSION['id_user'], 'Proses Seleksi', "Proses seleksi jalur ID: $id_jalur");
                if (!mysqli_commit($conn)) {
                    throw new RuntimeException("Gagal menyelesaikan transaksi seleksi.");
                }
                $transaction_started = false;
                $success = "Seleksi global selesai. Pilihan 1 diproses untuk semua sekolah sebelum alokasi Pilihan 2.";
            } catch (Throwable $e) {
                if ($transaction_started) {
                    mysqli_rollback($conn);
                }
                $error = "Seleksi gagal dan seluruh perubahan dibatalkan: " . $e->getMessage();
            } finally {
                foreach ($held_locks as $held_lock_name) {
                    $stmt_release = mysqli_prepare($conn, "SELECT RELEASE_LOCK(?)");
                    if ($stmt_release) {
                        mysqli_stmt_bind_param($stmt_release, "s", $held_lock_name);
                        if (mysqli_stmt_execute($stmt_release)) {
                            $release_result = mysqli_stmt_get_result($stmt_release);
                            if ($release_result) {
                                mysqli_fetch_row($release_result);
                                mysqli_free_result($release_result);
                            }
                        }
                        mysqli_stmt_close($stmt_release);
                    }
                }
            }
        }
        }
    }
}

// Data for UI
$list_jalur = get_semua_jadwal_jalur();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Proses Seleksi Global - Admin PPDB</title>
    <?php include '../../includes/head_admin.php'; ?>
</head>
<body>
    <?php include '../../includes/navbar_admin.php'; ?>
    <div class="container mt-4">
        
        <!-- Back Button -->
        <div class="mb-3">
            <a href="/ppdb-smp/admin/index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
            </a>
        </div>
        
        <div class="card shadow">
            <div class="card-header bg-danger text-white">
                <h4 class="mb-0"><i class="fas fa-bolt me-2"></i>Seleksi Otomatis (Global Jalur)</h4>
            </div>
            <div class="card-body">
                
                <?php if (!$seleksi_diizinkan): ?>
                <!-- Warning: Status bukan seleksi -->
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Proses Seleksi Tidak Tersedia</h5>
                    <p class="mb-2">Proses seleksi hanya dapat dilakukan saat status PPDB adalah <strong>Seleksi</strong>.</p>
                    <p class="mb-2">Status saat ini: <strong class="text-primary"><?php echo ucfirst($status_ppdb); ?></strong></p>
                    <hr>
                    <a href="/ppdb-smp/admin/pengaturan.php" class="btn btn-primary">
                        <i class="fas fa-cog me-2"></i>Ubah Status di Pengaturan
                    </a>
                </div>
                <?php else: ?>
                
                <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Status PPDB: <strong class="text-success">Seleksi</strong> - Proses seleksi dapat dilakukan.
                </div>
                
                <form method="POST" onsubmit="return confirm('Yakin ingin menjalankan proses seleksi? Proses ini akan menentukan kelulusan siswa.');">
                    <?php echo csrf_input(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Jalur yang akan diproses:</label>
                        <select name="id_jalur" class="form-select form-select-lg" required>
                            <?php while($j = mysqli_fetch_assoc($list_jalur)): ?>
                            <option value="<?php echo $j['id_jalur']; ?>"><?php echo $j['nama_jalur']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <button type="submit" name="proses_seleksi_global" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-play-circle me-2"></i>Mulai Seleksi Global
                    </button>
                    <div class="text-danger mt-2">
                        <small><i class="fas fa-exclamation-circle me-1"></i>Proses ini akan menjalankan seleksi untuk SELURUH SEKOLAH sekaligus demi keadilan Pilihan 1 & 2.</small>
                    </div>
                </form>
                
                <?php endif; ?>

                <?php if (!empty($log_proses)): ?>
                    <hr>
                    <h6><i class="fas fa-terminal me-2"></i>Log Proses:</h6>
                    <div class="bg-dark text-success p-3 rounded" style="max-height:400px; overflow-y:auto; font-family: monospace;">
                        <pre class="mb-0"><?php echo implode("\n", $log_proses); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include '../../includes/scripts_admin.php'; ?>
</body>
</html>
