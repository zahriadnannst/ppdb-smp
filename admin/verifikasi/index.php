<?php
/**
 * File: admin/verifikasi/index.php
 * Deskripsi: Verifikasi dokumen dan pendaftaran siswa
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';

check_admin();

$tahun_ajaran = TAHUN_AJARAN;
$operator_sekolah = get_sekolah_filter();
$error = '';
$success = '';

if (is_operator() && !$operator_sekolah) {
    http_response_code(403);
    exit('Akun operator belum memiliki sekolah yang ditugaskan.');
}
$operator_sekolah = $operator_sekolah ? (int)$operator_sekolah : null;

function dokumen_umum_akan_valid(mysqli $conn, int $id_siswa): bool {
    $required_types = ['kk', 'skl', 'rapor', 'foto'];
    $document_statuses = [];
    $stmt_documents = mysqli_prepare(
        $conn,
        "SELECT id_dokumen_siswa, LOWER(jenis_dokumen) AS jenis_dokumen, status_verifikasi
         FROM dokumen_siswa
         WHERE id_siswa = ? AND kategori = 'umum'"
    );
    mysqli_stmt_bind_param($stmt_documents, "i", $id_siswa);
    mysqli_stmt_execute($stmt_documents);
    $result_documents = mysqli_stmt_get_result($stmt_documents);
    while ($document = mysqli_fetch_assoc($result_documents)) {
        $document_statuses[(int)$document['id_dokumen_siswa']] = [
            'jenis' => $document['jenis_dokumen'],
            'status' => $document['status_verifikasi']
        ];
    }
    mysqli_stmt_close($stmt_documents);

    $submitted_count = filter_var($_POST['doc_count'] ?? 0, FILTER_VALIDATE_INT);
    if ($submitted_count === false || $submitted_count < 0 || $submitted_count > 100) {
        return false;
    }
    $allowed_document_statuses = ['pending', 'valid', 'tidak_valid'];
    for ($i = 0; $i < $submitted_count; $i++) {
        $document_id = $_POST["doc_id_$i"] ?? null;
        $status = $_POST["doc_status_$i"] ?? null;
        if (!is_scalar($document_id) || !is_scalar($status)) {
            continue;
        }
        $document_id = (int)$document_id;
        if (isset($document_statuses[$document_id])) {
            if (!in_array($status, $allowed_document_statuses, true)) {
                return false;
            }
            $document_statuses[$document_id]['status'] = $status;
        }
    }

    $valid_types = [];
    foreach ($document_statuses as $document) {
        if (in_array($document['jenis'], $required_types, true) && $document['status'] === 'valid') {
            $valid_types[$document['jenis']] = true;
        }
    }

    return count($valid_types) === count($required_types);
}

// Proses verifikasi
if (isset($_POST['verify']) || isset($_POST['verify_doc'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('Token CSRF tidak valid.');
    }
}

if (isset($_POST['verify'])) {
    $id_pendaftaran = filter_var($_POST['id_pendaftaran'] ?? null, FILTER_VALIDATE_INT);
    $id_pendaftaran = $id_pendaftaran === false ? 0 : $id_pendaftaran;
    $status = is_string($_POST['status'] ?? null) ? clean_input($_POST['status']) : '';
    $catatan = is_string($_POST['catatan'] ?? null) ? clean_input($_POST['catatan']) : '';
    $doc_count = filter_var($_POST['doc_count'] ?? 0, FILTER_VALIDATE_INT);

    $pendaftaran = null;
    if (is_operator()) {
        $sql_akses = "SELECT id_siswa FROM pendaftaran
                      WHERE id_pendaftaran = ? AND tahun_ajaran = ?
                        AND ? IN (id_sekolah, id_sekolah_pilihan_2)";
        $stmt_akses = mysqli_prepare($conn, $sql_akses);
        mysqli_stmt_bind_param($stmt_akses, "isi", $id_pendaftaran, $tahun_ajaran, $operator_sekolah);
        mysqli_stmt_execute($stmt_akses);
        $pendaftaran = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_akses));
    } else {
        $sql_akses = "SELECT id_siswa FROM pendaftaran WHERE id_pendaftaran = ?";
        $stmt_akses = mysqli_prepare($conn, $sql_akses);
        mysqli_stmt_bind_param($stmt_akses, "i", $id_pendaftaran);
        mysqli_stmt_execute($stmt_akses);
        $pendaftaran = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_akses));
    }

    if (!$pendaftaran) {
        http_response_code(403);
        $error = "Anda tidak memiliki akses ke data pendaftaran ini.";
    } elseif (!in_array($status, ['pending', 'terverifikasi', 'ditolak'], true)) {
        $error = "Status verifikasi tidak valid.";
    } elseif ($doc_count === false || $doc_count < 0 || $doc_count > 100) {
        $error = "Daftar dokumen untuk diverifikasi tidak valid.";
    } elseif ($status === 'terverifikasi' && !dokumen_umum_akan_valid($conn, (int)$pendaftaran['id_siswa'])) {
        $error = "Pendaftaran belum dapat diverifikasi. Keempat dokumen umum (KK, SKL, rapor, dan pas foto) harus berstatus valid.";
    } else {
        $sql_update = "UPDATE pendaftaran SET 
                       status_verifikasi = ?, 
                       catatan_verifikasi = ?,
                       verifikasi_at = NOW(),
                       verifikasi_by = ?
                       WHERE id_pendaftaran = ?";
        if (is_operator()) {
            $sql_update .= " AND tahun_ajaran = ? AND ? IN (id_sekolah, id_sekolah_pilihan_2)";
        }

        $stmt = mysqli_prepare($conn, $sql_update);
        if (is_operator()) {
            mysqli_stmt_bind_param($stmt, "ssiisi", $status, $catatan, $_SESSION['id_user'], $id_pendaftaran, $tahun_ajaran, $operator_sekolah);
        } else {
            mysqli_stmt_bind_param($stmt, "ssii", $status, $catatan, $_SESSION['id_user'], $id_pendaftaran);
        }

        if (mysqli_stmt_execute($stmt)) {
        // Update status dokumen di tabel dokumen_siswa
        if ($doc_count > 0) {
            for ($i = 0; $i < $doc_count; $i++) {
                if (isset($_POST["doc_id_$i"]) && isset($_POST["doc_status_$i"])) {
                    $id_dok = (int)$_POST["doc_id_$i"];
                    $status_dok = $_POST["doc_status_$i"];
                    if (!in_array($status_dok, ['pending', 'valid', 'tidak_valid'], true)) {
                        continue;
                    }
                    $catatan_dok = isset($_POST["doc_catatan_$i"]) && is_string($_POST["doc_catatan_$i"])
                        ? trim($_POST["doc_catatan_$i"])
                        : '';
                    
                    $sql_doc = "UPDATE dokumen_siswa SET status_verifikasi = ?, catatan = ?, verified_at = NOW(), verified_by = ? WHERE id_dokumen_siswa = ? AND id_siswa = ?";
                    $stmt_doc = mysqli_prepare($conn, $sql_doc);
                    mysqli_stmt_bind_param($stmt_doc, "ssiii", $status_dok, $catatan_dok, $_SESSION['id_user'], $id_dok, $pendaftaran['id_siswa']);
                    mysqli_stmt_execute($stmt_doc);
                }
            }
        }
        
        log_aktivitas($_SESSION['id_user'], 'Verifikasi', "Verifikasi pendaftaran ID: $id_pendaftaran - Status: $status");
        $success = "Verifikasi pendaftaran dan dokumen berhasil disimpan!";
    } else {
        $error = "Gagal melakukan verifikasi!";
    }
    }
}

// Proses verifikasi dokumen individual
if (isset($_POST['verify_doc'])) {
    $id_dokumen = filter_var($_POST['id_dokumen'] ?? null, FILTER_VALIDATE_INT);
    $id_dokumen = $id_dokumen === false ? 0 : $id_dokumen;
    $status_doc = is_string($_POST['status_doc'] ?? null) ? clean_input($_POST['status_doc']) : '';
    $catatan_doc = is_string($_POST['catatan_doc'] ?? null) ? clean_input($_POST['catatan_doc']) : '';

    if (!in_array($status_doc, ['pending', 'valid', 'tidak_valid'], true)) {
        http_response_code(400);
        $error = "Status dokumen tidak valid.";
    } else {

    if (is_operator()) {
        $sql_akses = "SELECT ds.id_dokumen_siswa
                      FROM dokumen_siswa ds
                      JOIN pendaftaran p ON p.id_siswa = ds.id_siswa
                      WHERE ds.id_dokumen_siswa = ? AND p.tahun_ajaran = ?
                        AND ? IN (p.id_sekolah, p.id_sekolah_pilihan_2)
                      LIMIT 1";
        $stmt_akses = mysqli_prepare($conn, $sql_akses);
        mysqli_stmt_bind_param($stmt_akses, "isi", $id_dokumen, $tahun_ajaran, $operator_sekolah);
        mysqli_stmt_execute($stmt_akses);
        $dokumen_akses = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_akses));
    } else {
        $sql_akses = "SELECT id_dokumen_siswa FROM dokumen_siswa WHERE id_dokumen_siswa = ?";
        $stmt_akses = mysqli_prepare($conn, $sql_akses);
        mysqli_stmt_bind_param($stmt_akses, "i", $id_dokumen);
        mysqli_stmt_execute($stmt_akses);
        $dokumen_akses = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_akses));
    }

    if (!$dokumen_akses) {
        http_response_code(403);
        $error = "Anda tidak memiliki akses ke dokumen ini.";
    } else {
        $sql_update_doc = "UPDATE dokumen_siswa SET status_verifikasi = ?, catatan = ?, verified_at = NOW(), verified_by = ? WHERE id_dokumen_siswa = ?";
        if (is_operator()) {
            $sql_update_doc .= " AND EXISTS (
                SELECT 1 FROM pendaftaran p
                WHERE p.id_siswa = dokumen_siswa.id_siswa
                  AND p.tahun_ajaran = ?
                  AND ? IN (p.id_sekolah, p.id_sekolah_pilihan_2)
            )";
        }
        $stmt_doc = mysqli_prepare($conn, $sql_update_doc);
        if (is_operator()) {
            mysqli_stmt_bind_param($stmt_doc, "ssiisi", $status_doc, $catatan_doc, $_SESSION['id_user'], $id_dokumen, $tahun_ajaran, $operator_sekolah);
        } else {
            mysqli_stmt_bind_param($stmt_doc, "ssii", $status_doc, $catatan_doc, $_SESSION['id_user'], $id_dokumen);
        }

        if (mysqli_stmt_execute($stmt_doc)) {
            $success = "Status dokumen berhasil diupdate!";
        } else {
            $error = "Gagal memperbarui status dokumen!";
        }
    }
    }
}

// Filter
$allowed_statuses = ['pending', 'terverifikasi', 'ditolak'];
$requested_status = $_GET['status'] ?? 'pending';
$filter_status = in_array($requested_status, $allowed_statuses, true) ? $requested_status : '';
$filter_jalur = isset($_GET['jalur']) ? (int)$_GET['jalur'] : 0;

// Query pendaftar dengan jumlah dokumen dari dokumen_siswa
$sql = "SELECT p.*, s.nama_lengkap, s.nisn, s.no_telepon, s.id_siswa,
        sch.nama_sekolah, j.nama_jalur,
        (SELECT COUNT(*) FROM dokumen_siswa WHERE id_siswa = s.id_siswa) as jumlah_dokumen
        FROM pendaftaran p
        JOIN siswa s ON p.id_siswa = s.id_siswa
        JOIN sekolah sch ON p.id_sekolah = sch.id_sekolah
        JOIN jalur_ppdb j ON p.id_jalur = j.id_jalur
        WHERE p.tahun_ajaran = ? ";
$params = [$tahun_ajaran];
$types = "s";

if ($operator_sekolah) {
    $sql .= "AND ? IN (p.id_sekolah, p.id_sekolah_pilihan_2) ";
    $params[] = (int)$operator_sekolah;
    $types .= "i";
}

if ($filter_status) {
    $sql .= "AND p.status_verifikasi = ? ";
    $params[] = $filter_status;
    $types .= "s";
}
if ($filter_jalur) {
    $sql .= "AND p.id_jalur = ? ";
    $params[] = $filter_jalur;
    $types .= "i";
}

$sql .= "ORDER BY p.tanggal_daftar DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$pendaftar_list = mysqli_stmt_get_result($stmt);

// Statistik - also filtered by school for operators
if ($operator_sekolah) {
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status_verifikasi = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status_verifikasi = 'terverifikasi' THEN 1 ELSE 0 END) as terverifikasi,
        SUM(CASE WHEN status_verifikasi = 'ditolak' THEN 1 ELSE 0 END) as ditolak
        FROM pendaftaran
        WHERE tahun_ajaran = ? AND ? IN (id_sekolah, id_sekolah_pilihan_2)";
    $stmt_stats = mysqli_prepare($conn, $sql_stats);
    mysqli_stmt_bind_param($stmt_stats, "si", $tahun_ajaran, $operator_sekolah);
} else {
    $sql_stats = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status_verifikasi = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status_verifikasi = 'terverifikasi' THEN 1 ELSE 0 END) as terverifikasi,
        SUM(CASE WHEN status_verifikasi = 'ditolak' THEN 1 ELSE 0 END) as ditolak
        FROM pendaftaran WHERE tahun_ajaran = ?";
    $stmt_stats = mysqli_prepare($conn, $sql_stats);
    mysqli_stmt_bind_param($stmt_stats, "s", $tahun_ajaran);
}
mysqli_stmt_execute($stmt_stats);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_stats));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pendaftar - Admin PPDB</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f5f7fa; }
        .pendaftar-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .pendaftar-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
        }
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
                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-primary mb-0"><?php echo $stats['total']; ?></h3>
                                <small class="text-muted">Total Pendaftar</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-warning mb-0"><?php echo $stats['pending']; ?></h3>
                                <small class="text-muted">Menunggu</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-success mb-0"><?php echo $stats['terverifikasi']; ?></h3>
                                <small class="text-muted">Terverifikasi</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-danger mb-0"><?php echo $stats['ditolak']; ?></h3>
                                <small class="text-muted">Ditolak</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-check-double me-2"></i>
                            Verifikasi Pendaftar
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <!-- Filter -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <select class="form-select" onchange="window.location.href='?status=' + this.value + '&jalur=<?php echo $filter_jalur; ?>'">
                                    <option value="">Semua Status</option>
                                    <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="terverifikasi" <?php echo $filter_status == 'terverifikasi' ? 'selected' : ''; ?>>Terverifikasi</option>
                                    <option value="ditolak" <?php echo $filter_status == 'ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <select class="form-select" onchange="window.location.href='?status=<?php echo $filter_status; ?>&jalur=' + this.value">
                                    <option value="0">Semua Jalur</option>
                                    <option value="1" <?php echo $filter_jalur == 1 ? 'selected' : ''; ?>>Zonasi</option>
                                    <option value="2" <?php echo $filter_jalur == 2 ? 'selected' : ''; ?>>Prestasi</option>
                                    <option value="3" <?php echo $filter_jalur == 3 ? 'selected' : ''; ?>>Afirmasi</option>
                                    <option value="4" <?php echo $filter_jalur == 4 ? 'selected' : ''; ?>>Mutasi</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- List Pendaftar -->
                        <?php while ($p = mysqli_fetch_assoc($pendaftar_list)): ?>
                        <div class="pendaftar-card">
                            <div class="row align-items-center">
                                <div class="col-md-1">
                                    <div class="avatar">
                                        <?php echo strtoupper(substr($p['nama_lengkap'], 0, 1)); ?>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <h6 class="fw-bold mb-1"><?php echo $p['nama_lengkap']; ?></h6>
                                    <p class="mb-1 small text-muted">
                                        <i class="fas fa-id-card me-1"></i> NISN: <?php echo $p['nisn']; ?>
                                    </p>
                                    <p class="mb-1 small">
                                        <i class="fas fa-school me-1"></i> <?php echo $p['nama_sekolah']; ?>
                                    </p>
                                    <span class="badge bg-info"><?php echo $p['nama_jalur']; ?></span>
                                </div>
                                <div class="col-md-3 text-center">
                                    <small class="text-muted d-block">Dokumen</small>
                                    <h5 class="mb-0"><?php echo $p['jumlah_dokumen']; ?>/4</h5>
                                    <span class="badge bg-<?php echo $p['status_berkas'] == 'lengkap' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($p['status_berkas']); ?>
                                    </span>
                                </div>
                                <div class="col-md-3 text-end">
                                    <span class="badge bg-<?php 
                                        echo $p['status_verifikasi'] == 'terverifikasi' ? 'success' : 
                                            ($p['status_verifikasi'] == 'ditolak' ? 'danger' : 'warning'); 
                                    ?> mb-2">
                                        <?php echo ucfirst($p['status_verifikasi']); ?>
                                    </span>
                                    <div>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="showDetail(<?php echo $p['id_pendaftaran']; ?>)">
                                            <i class="fas fa-eye me-1"></i> Detail
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Detail -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail Pendaftaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
        
        function showDetail(id) {
            detailModal.show();
            fetch('detail_verifikasi.php?id=' + id)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                });
        }
        
    </script>
</body>
</html>