<?php
/**
 * File: admin/seleksi/reset.php
 * Deskripsi: Reset seluruh hasil seleksi secara konsisten
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once 'fungsi_seleksi.php';

check_admin();
check_super_admin(); // Only super_admin can reset selection

$error = '';
$success = '';
$tahun_ajaran = TAHUN_AJARAN;

// Ambil daftar jalur
$list_jalur = get_semua_jadwal_jalur();

// Proses Reset Seleksi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('Sesi tidak valid. Silakan muat ulang halaman.');
    }

    $action = $_POST['action'];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $transaction_started = false;
    $held_locks = [];

    try {
        if ($action !== 'reset_semua') {
            throw new RuntimeException("Reset parsial dinonaktifkan karena alokasi Pilihan 2 bergantung pada hasil Pilihan 1 seluruh sekolah. Gunakan reset global agar hasil tetap konsisten.");
        }

        $year_lock_name = nama_lock_tahun_seleksi($tahun_ajaran);
        $stmt_year_lock = mysqli_prepare($conn, "SELECT GET_LOCK(?, 0)");
        mysqli_stmt_bind_param($stmt_year_lock, "s", $year_lock_name);
        mysqli_stmt_execute($stmt_year_lock);
        $year_lock_result_set = mysqli_stmt_get_result($stmt_year_lock);
        $year_lock_result = $year_lock_result_set ? mysqli_fetch_row($year_lock_result_set) : null;
        if ($year_lock_result_set) {
            mysqli_free_result($year_lock_result_set);
        }
        mysqli_stmt_close($stmt_year_lock);
        if (!$year_lock_result || (int)$year_lock_result[0] !== 1) {
            throw new RuntimeException("Proses seleksi tahun ajaran ini sedang berjalan. Reset dibatalkan.");
        }
        $held_locks[] = $year_lock_name;

        foreach ($list_jalur as $jalur) {
            $lock_name = nama_lock_seleksi($tahun_ajaran, $jalur['id_jalur']);
            $stmt_lock = mysqli_prepare($conn, "SELECT GET_LOCK(?, 0)");
            mysqli_stmt_bind_param($stmt_lock, "s", $lock_name);
            mysqli_stmt_execute($stmt_lock);
            $lock_result_set = mysqli_stmt_get_result($stmt_lock);
            $lock_result = $lock_result_set ? mysqli_fetch_row($lock_result_set) : null;
            if ($lock_result_set) {
                mysqli_free_result($lock_result_set);
            }
            mysqli_stmt_close($stmt_lock);
            if (!$lock_result || (int)$lock_result[0] !== 1) {
                throw new RuntimeException("Proses seleksi atau publikasi jalur {$jalur['nama_jalur']} sedang berjalan. Reset dibatalkan.");
            }
            $held_locks[] = $lock_name;
        }

        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException("Gagal memulai transaksi reset.");
        }
        $transaction_started = true;

        $stmt_published = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) FROM hasil_seleksi WHERE tahun_ajaran = ? AND dipublikasikan = 1"
        );
        mysqli_stmt_bind_param($stmt_published, "s", $tahun_ajaran);
        mysqli_stmt_execute($stmt_published);
        $published_count = (int)mysqli_fetch_row(mysqli_stmt_get_result($stmt_published))[0];
        if ($published_count > 0) {
            throw new RuntimeException("Reset dibatalkan karena terdapat hasil yang sudah dipublikasikan. Hasil publikasi tidak dihapus.");
        }

        $stmt_delete = mysqli_prepare($conn, "DELETE FROM hasil_seleksi WHERE tahun_ajaran = ?");
        mysqli_stmt_bind_param($stmt_delete, "s", $tahun_ajaran);
        mysqli_stmt_execute($stmt_delete);
        $deleted = mysqli_stmt_affected_rows($stmt_delete);

        $stmt_reset = mysqli_prepare($conn, "UPDATE kuota_jalur SET kuota_terpakai = 0 WHERE tahun_ajaran = ?");
        mysqli_stmt_bind_param($stmt_reset, "s", $tahun_ajaran);
        mysqli_stmt_execute($stmt_reset);

        log_aktivitas($_SESSION['id_user'], 'Reset Seleksi', "Reset SEMUA seleksi tahun $tahun_ajaran ($deleted hasil dihapus)");
        if (!mysqli_commit($conn)) {
            throw new RuntimeException("Gagal menyelesaikan transaksi reset.");
        }
        $transaction_started = false;
        $success = "Berhasil reset <strong>SEMUA</strong> hasil seleksi tahun $tahun_ajaran. $deleted hasil dihapus.";
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($conn);
        }
        $error = "Gagal reset seleksi: " . $e->getMessage();
    } finally {
        foreach ($held_locks as $lock_name) {
            $stmt_release = mysqli_prepare($conn, "SELECT RELEASE_LOCK(?)");
            if ($stmt_release) {
                mysqli_stmt_bind_param($stmt_release, "s", $lock_name);
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

// Hitung total hasil seleksi
$sql_total = "SELECT COUNT(*) as total FROM hasil_seleksi hs
              WHERE hs.tahun_ajaran = ?";
$stmt_total = mysqli_prepare($conn, $sql_total);
mysqli_stmt_bind_param($stmt_total, "s", $tahun_ajaran);
mysqli_stmt_execute($stmt_total);
$total_hasil = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_total))['total'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Seleksi - Admin PPDB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f5f7fa; }
    </style>
</head>
<body>
    <?php include '../../includes/navbar_admin.php'; ?>
    
    <div class="container-fluid mt-4 mb-5">
        <div class="row">
            <div class="col-md-3">
                <?php include '../../includes/sidebar_admin.php'; ?>
            </div>
            
            <div class="col-md-9">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-undo me-2"></i>Reset Hasil Seleksi</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Peringatan!</h5>
                            <p class="mb-0">Reset akan menghapus hasil seleksi. 
                            Data pendaftaran dan verifikasi tetap aman. Proses ini tidak dapat dibatalkan.</p>
                        </div>
                        
                        <div class="alert alert-info">
                            Karena alokasi Pilihan 2 bergantung pada hasil Pilihan 1 semua sekolah, reset parsial tidak tersedia. Reset global juga ditolak jika ada hasil yang telah dipublikasikan.
                        </div>
                        
                        <!-- Reset Semua -->
                        <div class="card border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0">⚠️ Reset SEMUA Seleksi</h5>
                            </div>
                            <div class="card-body">
                                <p>Total hasil seleksi saat ini: <strong><?php echo $total_hasil; ?></strong> data</p>
                                
                                <?php if ($total_hasil > 0): ?>
                                <form method="POST" onsubmit="return confirm('PERINGATAN! Anda akan menghapus SEMUA hasil seleksi. Yakin?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="reset_semua">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-trash me-1"></i> Reset SEMUA Hasil Seleksi
                                    </button>
                                </form>
                                <?php else: ?>
                                <p class="text-muted mb-0"><i class="fas fa-info-circle me-1"></i>Tidak ada hasil seleksi untuk direset.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <a href="/ppdb-smp/admin/seleksi/proses.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-1"></i> Kembali ke Proses Seleksi
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
