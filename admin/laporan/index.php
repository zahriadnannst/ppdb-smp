<?php
/**
 * File: admin/laporan/index.php
 * Deskripsi: Halaman laporan PPDB untuk admin
 * Fitur: Export data pendaftar, statistik per jalur, rekap hasil seleksi
 */

session_start();
require_once '../../config/database.php';

check_admin();

$tahun_ajaran = TAHUN_AJARAN;

// Operators are always scoped to their assigned school; super-admins may filter.
$operator_sekolah = get_sekolah_filter();
if (is_operator() && !$operator_sekolah) {
    http_response_code(403);
    exit('Akun operator belum memiliki sekolah yang ditugaskan.');
}
$id_sekolah = is_operator()
    ? (int)$operator_sekolah
    : max(0, (int)($_GET['sekolah'] ?? 0));
$id_jalur = max(0, (int)($_GET['jalur'] ?? 0));
$sekolah_condition = $id_sekolah
    ? " AND (p.id_sekolah = $id_sekolah OR p.id_sekolah_pilihan_2 = $id_sekolah)"
    : "";
$jalur_condition = $id_jalur ? " AND p.id_jalur = $id_jalur" : "";
$sekolah_filter_list = mysqli_query(
    $conn,
    "SELECT id_sekolah, nama_sekolah FROM sekolah WHERE status = 'aktif' ORDER BY nama_sekolah"
);
$jalur_filter_list = mysqli_query($conn, "SELECT id_jalur, nama_jalur FROM jalur_ppdb ORDER BY urutan");

// Statistik Keseluruhan (filtered for operators)
$sql_stats = "SELECT 
    COUNT(*) as total_pendaftar,
    COALESCE(SUM(CASE WHEN p.status_verifikasi = 'terverifikasi' THEN 1 ELSE 0 END), 0) as terverifikasi,
    COALESCE(SUM(CASE WHEN p.status_verifikasi = 'pending' THEN 1 ELSE 0 END), 0) as pending,
    COALESCE(SUM(CASE WHEN p.status_verifikasi = 'ditolak' THEN 1 ELSE 0 END), 0) as ditolak
    FROM pendaftaran p WHERE p.tahun_ajaran = ?" . $sekolah_condition . $jalur_condition;
$stmt = mysqli_prepare($conn, $sql_stats);
mysqli_stmt_bind_param($stmt, "s", $tahun_ajaran);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Statistik per Jalur (filtered for operators)
$sql_jalur = "SELECT j.nama_jalur, j.kode_jalur,
              COUNT(DISTINCT p.id_pendaftaran) as total,
              COUNT(DISTINCT CASE WHEN p.status_verifikasi = 'terverifikasi' THEN p.id_pendaftaran END) as verified,
              COUNT(DISTINCT CASE WHEN hs.status_seleksi = 'lulus' THEN hs.id_pendaftaran END) as lulus
              FROM jalur_ppdb j
              LEFT JOIN pendaftaran p ON j.id_jalur = p.id_jalur AND p.tahun_ajaran = ?" . $sekolah_condition . $jalur_condition . "
              LEFT JOIN hasil_seleksi hs ON p.id_pendaftaran = hs.id_pendaftaran
                                          AND hs.id_jalur = j.id_jalur
                                          AND hs.tahun_ajaran = p.tahun_ajaran
                                          AND hs.dipublikasikan = 1"
                                          . ($id_sekolah ? " AND hs.id_sekolah = $id_sekolah" : "") . "
              WHERE (? = 0 OR j.id_jalur = ?)
              GROUP BY j.id_jalur";
$stmt_jalur = mysqli_prepare($conn, $sql_jalur);
mysqli_stmt_bind_param($stmt_jalur, "sii", $tahun_ajaran, $id_jalur, $id_jalur);
mysqli_stmt_execute($stmt_jalur);
$jalur_stats = mysqli_stmt_get_result($stmt_jalur);

// Aplikasi menghitung pendaftar dari pilihan awal dan kelulusan dari sekolah penerima.
$sql_sekolah = "SELECT s.id_sekolah, s.nama_sekolah, s.npsn,
                (SELECT SUM(k.jumlah_kuota) FROM kuota_jalur k
                 WHERE k.id_sekolah = s.id_sekolah AND k.tahun_ajaran = ?"
                 . ($id_jalur ? " AND k.id_jalur = $id_jalur" : "") . ") AS kuota_dynamic,
                (SELECT COUNT(*) FROM pendaftaran p
                 WHERE (p.id_sekolah = s.id_sekolah OR p.id_sekolah_pilihan_2 = s.id_sekolah)
                   AND p.tahun_ajaran = ?" . $jalur_condition . ") AS total_pendaftar,
                (SELECT COUNT(*) FROM pendaftaran p
                 WHERE (p.id_sekolah = s.id_sekolah OR p.id_sekolah_pilihan_2 = s.id_sekolah)
                   AND p.tahun_ajaran = ?
                   AND p.status_verifikasi = 'terverifikasi'" . $jalur_condition . ") AS terverifikasi,
                (SELECT COUNT(DISTINCT hs.id_pendaftaran)
                 FROM hasil_seleksi hs
                 JOIN pendaftaran p ON hs.id_pendaftaran = p.id_pendaftaran
                                     AND hs.tahun_ajaran = p.tahun_ajaran
                                     AND hs.id_jalur = p.id_jalur
                 WHERE hs.id_sekolah = s.id_sekolah AND hs.tahun_ajaran = ?
                   AND hs.status_seleksi = 'lulus' AND hs.dipublikasikan = 1" . $jalur_condition . ") AS lulus
                FROM sekolah s";
$sql_sekolah .= $id_sekolah ? " WHERE s.id_sekolah = ?" : " WHERE s.status = 'aktif'";
$sql_sekolah .= " ORDER BY s.nama_sekolah";
$stmt_sekolah = mysqli_prepare($conn, $sql_sekolah);
if ($id_sekolah) {
    mysqli_stmt_bind_param($stmt_sekolah, "ssssi", $tahun_ajaran, $tahun_ajaran, $tahun_ajaran, $tahun_ajaran, $id_sekolah);
} else {
    mysqli_stmt_bind_param($stmt_sekolah, "ssss", $tahun_ajaran, $tahun_ajaran, $tahun_ajaran, $tahun_ajaran);
}
mysqli_stmt_execute($stmt_sekolah);
$sekolah_stats = mysqli_stmt_get_result($stmt_sekolah);

// Export CSV jika diminta
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_ppdb_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // BOM untuk Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, ['No', 'No Pendaftaran', 'NISN', 'NIK', 'Nama Lengkap', 'Jenis Kelamin', 
                      'Asal Sekolah', 'Sekolah Tujuan', 'Jalur', 'Nilai Rata-rata', 
                      'Jarak (km)', 'Status Verifikasi', 'Status Seleksi', 'Ranking'], ',', '"', '');
    
    // Data (filtered for operators)
    $sql_export = "SELECT p.nomor_pendaftaran, s.nisn, s.nik, s.nama_lengkap, s.jenis_kelamin,
                   s.asal_sekolah, COALESCE(sch_result.nama_sekolah, sch_pilihan.nama_sekolah) AS nama_sekolah,
                   COALESCE(j_result.nama_jalur, j_pilihan.nama_jalur) AS nama_jalur, p.nilai_rapor_rata,
                   CASE WHEN hs.pilihan_diterima = 2 THEN p.jarak_sekolah_2 ELSE p.jarak_rumah_sekolah END AS jarak_rumah,
                   p.status_verifikasi,
                   COALESCE(hs.status_seleksi, '-') as status_seleksi,
                   COALESCE(hs.ranking, '-') as ranking
                   FROM pendaftaran p
                   JOIN siswa s ON p.id_siswa = s.id_siswa
                   JOIN sekolah sch_pilihan ON p.id_sekolah = sch_pilihan.id_sekolah
                   JOIN jalur_ppdb j_pilihan ON p.id_jalur = j_pilihan.id_jalur
                   LEFT JOIN hasil_seleksi hs ON p.id_pendaftaran = hs.id_pendaftaran
                                               AND hs.tahun_ajaran = p.tahun_ajaran
                                               AND hs.id_jalur = p.id_jalur
                   LEFT JOIN sekolah sch_result ON hs.id_sekolah = sch_result.id_sekolah
                   LEFT JOIN jalur_ppdb j_result ON hs.id_jalur = j_result.id_jalur
                   WHERE p.tahun_ajaran = ?";
    if ($id_sekolah) {
        $sql_export .= " AND (
                            hs.id_sekolah = ?
                            OR (hs.id_sekolah IS NULL AND
                                (p.id_sekolah = ? OR p.id_sekolah_pilihan_2 = ?))
                         )";
    }
    if ($id_jalur) {
        $sql_export .= " AND p.id_jalur = ?";
    }
    $sql_export .= " ORDER BY nama_sekolah, nama_jalur, hs.ranking";
    
    $stmt_export = mysqli_prepare($conn, $sql_export);
    if ($id_sekolah && $id_jalur) {
        mysqli_stmt_bind_param($stmt_export, "siiii", $tahun_ajaran, $id_sekolah, $id_sekolah, $id_sekolah, $id_jalur);
    } elseif ($id_sekolah) {
        mysqli_stmt_bind_param($stmt_export, "siii", $tahun_ajaran, $id_sekolah, $id_sekolah, $id_sekolah);
    } elseif ($id_jalur) {
        mysqli_stmt_bind_param($stmt_export, "si", $tahun_ajaran, $id_jalur);
    } else {
        mysqli_stmt_bind_param($stmt_export, "s", $tahun_ajaran);
    }
    mysqli_stmt_execute($stmt_export);
    $result = mysqli_stmt_get_result($stmt_export);
    
    $no = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $no++,
            $row['nomor_pendaftaran'],
            $row['nisn'],
            $row['nik'],
            $row['nama_lengkap'],
            $row['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan',
            $row['asal_sekolah'],
            $row['nama_sekolah'],
            $row['nama_jalur'],
            number_format($row['nilai_rapor_rata'], 2),
            $row['jarak_rumah'],
            ucfirst($row['status_verifikasi']),
            $row['status_seleksi'] != '-' ? strtoupper($row['status_seleksi']) : '-',
            $row['ranking']
        ], ',', '"', '');
    }
    
    fclose($output);
    
    log_aktivitas($_SESSION['id_user'], 'Export Laporan', 'Export data pendaftar ke CSV');
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan PPDB - Admin PPDB</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f5f7fa; }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        .stat-icon.blue { background: #e3f2fd; color: #1976d2; }
        .stat-icon.green { background: #e8f5e9; color: #388e3c; }
        .stat-icon.orange { background: #fff3e0; color: #f57c00; }
        .stat-icon.red { background: #ffebee; color: #d32f2f; }
        
        .report-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .card { box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <?php include '../../includes/navbar_admin.php'; ?>
    </div>
    
    <div class="container-fluid mt-4 mb-5">
        <div class="row">
            <div class="col-md-3 no-print">
                <?php include '../../includes/sidebar_admin.php'; ?>
            </div>
            
            <div class="col-md-9">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="fas fa-file-alt text-primary me-2"></i>
                            Laporan PPDB
                        </h4>
                        <p class="text-muted mb-0">Tahun Ajaran <?php echo $tahun_ajaran; ?></p>
                    </div>
                    <div>
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="fas fa-print me-2"></i>Cetak
                        </button>
                        <a href="export_pdf.php?sekolah=<?php echo $id_sekolah; ?>&amp;jalur=<?php echo $id_jalur; ?>" target="_blank" class="btn btn-danger">
                            <i class="fas fa-file-pdf me-2"></i>Export PDF
                        </a>
                        <a href="?export=csv&amp;sekolah=<?php echo $id_sekolah; ?>&amp;jalur=<?php echo $id_jalur; ?>" class="btn btn-success">
                            <i class="fas fa-file-csv me-2"></i>Export CSV
                        </a>
                    </div>
                </div>

                <form method="GET" class="card card-body mb-4 no-print">
                    <div class="row g-3 align-items-end">
                        <?php if (!is_operator()): ?>
                        <div class="col-md-5">
                            <label for="filterSekolah" class="form-label">Sekolah</label>
                            <select class="form-select" name="sekolah" id="filterSekolah">
                                <option value="0">Semua sekolah</option>
                                <?php while ($school_option = mysqli_fetch_assoc($sekolah_filter_list)): ?>
                                <option value="<?php echo (int)$school_option['id_sekolah']; ?>"
                                    <?php echo $id_sekolah === (int)$school_option['id_sekolah'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school_option['nama_sekolah'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <div class="col-md-5">
                            <label class="form-label">Sekolah</label>
                            <input class="form-control" value="Sekolah yang ditugaskan" readonly>
                            <input type="hidden" name="sekolah" value="<?php echo $id_sekolah; ?>">
                        </div>
                        <?php endif; ?>
                        <div class="col-md-5">
                            <label for="filterJalur" class="form-label">Jalur</label>
                            <select class="form-select" name="jalur" id="filterJalur">
                                <option value="0">Semua jalur</option>
                                <?php while ($path_option = mysqli_fetch_assoc($jalur_filter_list)): ?>
                                <option value="<?php echo (int)$path_option['id_jalur']; ?>"
                                    <?php echo $id_jalur === (int)$path_option['id_jalur'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($path_option['nama_jalur'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-primary">Terapkan</button>
                        </div>
                    </div>
                </form>
                
                <!-- Statistik Keseluruhan -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <i class="fas fa-users"></i>
                            </div>
                            <h3 class="fw-bold text-primary mb-1"><?php echo number_format($stats['total_pendaftar']); ?></h3>
                            <p class="text-muted mb-0">Total Pendaftar</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon green">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h3 class="fw-bold text-success mb-1"><?php echo number_format($stats['terverifikasi']); ?></h3>
                            <p class="text-muted mb-0">Terverifikasi</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon orange">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h3 class="fw-bold text-warning mb-1"><?php echo number_format($stats['pending']); ?></h3>
                            <p class="text-muted mb-0">Pending</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon red">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <h3 class="fw-bold text-danger mb-1"><?php echo number_format($stats['ditolak']); ?></h3>
                            <p class="text-muted mb-0">Ditolak</p>
                        </div>
                    </div>
                </div>
                
                <!-- Laporan per Jalur -->
                <div class="report-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-route me-2"></i>
                            Rekap per Jalur PPDB
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Jalur</th>
                                        <th class="text-center">Total Pendaftar</th>
                                        <th class="text-center">Terverifikasi</th>
                                        <th class="text-center">Lulus</th>
                                        <th class="text-center">% Kelulusan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_all = 0;
                                    $verified_all = 0;
                                    $lulus_all = 0;
                                    while ($j = mysqli_fetch_assoc($jalur_stats)): 
                                        $total_all += $j['total'];
                                        $verified_all += $j['verified'];
                                        $lulus_all += $j['lulus'];
                                        $persen = $j['verified'] > 0 ? round(($j['lulus'] / $j['verified']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-info"><?php echo $j['kode_jalur']; ?></span></td>
                                        <td class="fw-bold"><?php echo $j['nama_jalur']; ?></td>
                                        <td class="text-center"><?php echo $j['total']; ?></td>
                                        <td class="text-center"><?php echo $j['verified']; ?></td>
                                        <td class="text-center text-success fw-bold"><?php echo $j['lulus']; ?></td>
                                        <td class="text-center">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $persen; ?>%">
                                                    <?php echo $persen; ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr class="fw-bold">
                                        <td colspan="2">TOTAL</td>
                                        <td class="text-center"><?php echo $total_all; ?></td>
                                        <td class="text-center"><?php echo $verified_all; ?></td>
                                        <td class="text-center text-success"><?php echo $lulus_all; ?></td>
                                        <td class="text-center">
                                            <?php echo $verified_all > 0 ? round(($lulus_all / $verified_all) * 100, 1) : 0; ?>%
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Laporan per Sekolah -->
                <div class="report-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-school me-2"></i>
                            Rekap per Sekolah
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Sekolah</th>
                                        <th>NPSN</th>
                                        <th class="text-center">Kuota</th>
                                        <th class="text-center">Pendaftar</th>
                                        <th class="text-center">Terverifikasi</th>
                                        <th class="text-center">Lulus</th>
                                        <th class="text-center">Sisa Kuota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    while ($s = mysqli_fetch_assoc($sekolah_stats)): 
                                        $kuota_fix = $s['kuota_dynamic'] ?? 0;
                                        $sisa = $kuota_fix - ($s['lulus'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td class="fw-bold"><?php echo $s['nama_sekolah']; ?></td>
                                        <td><?php echo $s['npsn']; ?></td>
                                        <td class="text-center"><?php echo $kuota_fix; ?></td>
                                        <td class="text-center"><?php echo $s['total_pendaftar']; ?></td>
                                        <td class="text-center"><?php echo $s['terverifikasi']; ?></td>
                                        <td class="text-center text-success fw-bold"><?php echo $s['lulus'] ?? 0; ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php echo $sisa > 0 ? 'warning' : 'success'; ?>">
                                                <?php echo $sisa > 0 ? $sisa : 'Penuh'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Footer Laporan -->
                <div class="text-center mt-4 text-muted">
                    <small>
                        <i class="fas fa-info-circle me-1"></i>
                        Laporan digenerate pada: <?php echo date('d F Y H:i:s'); ?> WIB
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
