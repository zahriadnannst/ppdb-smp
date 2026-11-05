<?php
/**
 * File: admin/backup/index.php
 * Deskripsi: Backup dan Restore Database
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
$conn = $GLOBALS['conn'];
check_admin();
check_super_admin(); // Only super_admin can access backup

// check_super_admin() already redirects non-super_admin users

$error = '';
$success = '';

function split_backup_sql_statements($sql) {
    $statements = [];
    $statement = '';
    $state = 'normal';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($state === 'line_comment') {
            if ($char === "\n" || $char === "\r") {
                $state = 'normal';
                $statement .= ' ';
            }
            continue;
        }
        if ($state === 'block_comment') {
            if ($char === '*' && $next === '/') {
                $state = 'normal';
                $statement .= ' ';
                $i++;
            }
            continue;
        }
        if ($state !== 'normal') {
            $statement .= $char;
            if ($char === '\\' && $state !== 'backtick' && $i + 1 < $length) {
                $statement .= $sql[++$i];
            } elseif (($state === 'single' && $char === "'") ||
                ($state === 'double' && $char === '"') ||
                ($state === 'backtick' && $char === '`')) {
                if ($next === $char) {
                    $statement .= $sql[++$i];
                } else {
                    $state = 'normal';
                }
            }
            continue;
        }

        if ($char === "'") {
            $state = 'single';
            $statement .= $char;
        } elseif ($char === '"') {
            $state = 'double';
            $statement .= $char;
        } elseif ($char === '`') {
            $state = 'backtick';
            $statement .= $char;
        } elseif ($char === '#' || ($char === '-' && $next === '-' &&
                  ($i + 2 >= $length || ord($sql[$i + 2]) <= 32))) {
            $state = 'line_comment';
            if ($char === '-') {
                $i++;
            }
        } elseif ($char === '/' && $next === '*') {
            $state = 'block_comment';
            $i++;
        } elseif ($char === ';') {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
            $statement = '';
        } else {
            $statement .= $char;
        }
    }

    if (in_array($state, ['single', 'double', 'backtick', 'block_comment'], true)) {
        return false;
    }
    if (trim($statement) !== '') {
        $statements[] = trim($statement);
    }
    return $statements;
}

function backup_insert_values_are_safe($values) {
    $without_strings = preg_replace("/'(?:\\\\\\\\.|''|[^'\\\\\\\\])*'/s", "''", $values);
    if ($without_strings === null) {
        return false;
    }
    $without_strings = preg_replace('/\b0x[0-9a-f]+\b/i', '0', $without_strings);
    $without_strings = preg_replace("/\b[bx]'[0-9a-f]*'/i", '0', $without_strings);
    if ($without_strings === null ||
        preg_match('/[^0-9eE+.,()\\s\\-\'"]/', $without_strings)) {
        return false;
    }
    return !preg_match('/\b(?!NULL\b|TRUE\b|FALSE\b|DEFAULT\b)[A-Za-z_][A-Za-z0-9_]*\b/i', $without_strings);
}

function backup_statement_is_allowed($statement, $allowed_tables) {
    if (preg_match('/^SET\s+(?!GLOBAL\b|PERSIST\b|PERSIST_ONLY\b|@@GLOBAL\b|@@PERSIST\b)/i', $statement)) {
        return true;
    }
    if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([A-Za-z0-9_]+)`$/i', $statement, $match) ||
        preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([A-Za-z0-9_]+)`\s*\(/i', $statement, $match)) {
        return in_array($match[1], $allowed_tables, true) &&
            !preg_match('/\)\s*(?:AS\s+)?SELECT\b|\b(?:GENERATED|CHECK|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK)\b/i', $statement);
    }
    if (preg_match('/^INSERT\s+INTO\s+`([A-Za-z0-9_]+)`(?:\s*\(([^)]*)\))?\s+VALUES\s+(.+)$/is', $statement, $match)) {
        if (!in_array($match[1], $allowed_tables, true)) {
            return false;
        }
        if (isset($match[2]) && $match[2] !== '' &&
            !preg_match('/^(?:\s*`[A-Za-z0-9_]+`\s*,?)+\s*$/', $match[2])) {
            return false;
        }
        return backup_insert_values_are_safe($match[3]);
    }
    if (preg_match('/^LOCK\s+TABLES\s+(.+)$/is', $statement, $match)) {
        if (!preg_match_all('/`([A-Za-z0-9_]+)`\s+(?:WRITE|READ)(?:\s+LOCAL)?/', $match[1], $tables)) {
            return false;
        }
        foreach ($tables[1] as $table) {
            if (!in_array($table, $allowed_tables, true)) {
                return false;
            }
        }
        return true;
    }
    return preg_match('/^UNLOCK\s+TABLES$/i', $statement) ||
        preg_match('/^ALTER\s+TABLE\s+`([A-Za-z0-9_]+)`\s+(?:DISABLE|ENABLE)\s+KEYS$/i', $statement, $match) &&
        in_array($match[1], $allowed_tables, true);
}

function is_backup_file_name($filename) {
    return is_string($filename) &&
        preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(?:_[a-f0-9]{8})?\.sql$/i', $filename);
}

function mysql_option_file_value($value) {
    return '"' . str_replace(
        ["\\", "\n", "\r", '"'],
        ["\\\\", "\\n", "\\r", '\\"'],
        $value
    ) . '"';
}

function get_backup_file_path($backup_folder, $filename) {
    if (!is_backup_file_name($filename)) {
        return false;
    }
    $path = realpath($backup_folder . DIRECTORY_SEPARATOR . $filename);
    if ($path === false || !is_file($path) ||
        strtolower(dirname($path)) !== strtolower($backup_folder)) {
        return false;
    }
    return $path;
}

// Folder backup
$backup_folder = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ppdb-smp-backups';
$storage_error = '';
if (is_link($backup_folder)) {
    $storage_error = "Direktori penyimpanan backup tidak boleh berupa symbolic link.";
} elseif (!is_dir($backup_folder) && !@mkdir($backup_folder, 0700, true) && !is_dir($backup_folder)) {
    $storage_error = "Direktori penyimpanan backup privat tidak dapat dibuat.";
}
$backup_folder_real = $storage_error === '' ? realpath($backup_folder) : false;
$document_root_real = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($backup_folder_real === false) {
    $storage_error = "Direktori penyimpanan backup privat tidak dapat diakses.";
} elseif ($document_root_real === false) {
    $storage_error = "Lokasi document root tidak dapat diverifikasi; penyimpanan backup dinonaktifkan.";
} elseif ($document_root_real !== false) {
    $backup_normalized = strtolower(str_replace('\\', '/', $backup_folder_real));
    $document_root_normalized = rtrim(strtolower(str_replace('\\', '/', $document_root_real)), '/');
    if ($backup_normalized === $document_root_normalized ||
        strpos($backup_normalized . '/', $document_root_normalized . '/') === 0) {
        $storage_error = "Penyimpanan backup berada di area publik. Direktori sementara server harus berada di luar document root.";
    }
}
if ($storage_error !== '') {
    $error = $storage_error;
} else {
    $backup_folder = $backup_folder_real;
    @chmod($backup_folder, 0700);
}

// Proses backup
if (isset($_POST['backup'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        $error = "Sesi tidak valid. Muat ulang halaman dan coba lagi.";
    } elseif ($storage_error === '') {
        $database = DB_NAME;
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $filepath = $backup_folder . DIRECTORY_SEPARATOR . $filename;
        $mysqldump_path = 'C:/xampp/mysql/bin/mysqldump.exe';
        $return_var = 1;

        if (file_exists($mysqldump_path) && function_exists('exec')) {
            $client_config = tempnam($backup_folder, '.mysqldump-');
            if ($client_config !== false && realpath(dirname($client_config)) === $backup_folder) {
                $config_content = "[client]\n" .
                    "host=" . mysql_option_file_value(DB_HOST) . "\n" .
                    "user=" . mysql_option_file_value(DB_USER) . "\n" .
                    "password=" . mysql_option_file_value(DB_PASS) . "\n";
                if (file_put_contents($client_config, $config_content, LOCK_EX) !== false) {
                    @chmod($client_config, 0600);
                    $command = escapeshellarg($mysqldump_path) .
                        ' --defaults-extra-file=' . escapeshellarg($client_config) . ' ' .
                        escapeshellarg($database) . ' > ' . escapeshellarg($filepath) . ' 2>&1';
                    try {
                        exec($command, $output, $return_var);
                    } finally {
                        @unlink($client_config);
                    }
                } else {
                    @unlink($client_config);
                }
            } elseif ($client_config !== false) {
                @unlink($client_config);
            }
        }

        if ($return_var === 0 && is_file($filepath) && filesize($filepath) > 0) {
            $success = "Backup berhasil dibuat: $filename";
            @chmod($filepath, 0600);
            log_aktivitas($_SESSION['id_user'], 'Backup Database', "Membuat backup: $filename");
        } else {
            @unlink($filepath);
            $tables = [];
            $result = mysqli_query($conn, "SHOW TABLES FROM `" . mysqli_real_escape_string($conn, $database) . "`");
            $backup_failed = !$result;
            if ($result) {
                while ($row = mysqli_fetch_row($result)) {
                    $tables[] = $row[0];
                }
            }

            $sql_dump = "-- PPDB SMP Database Backup\n";
            $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
            foreach ($tables as $table) {
                $safe_table = str_replace('`', '``', $table);
                $create_result = mysqli_query($conn, "SHOW CREATE TABLE `$safe_table`");
                $create = $create_result ? mysqli_fetch_row($create_result) : false;
                $rows = mysqli_query($conn, "SELECT * FROM `$safe_table`");
                if (!$create || !$rows) {
                    $backup_failed = true;
                    break;
                }

                $sql_dump .= "DROP TABLE IF EXISTS `$safe_table`;\n";
                $sql_dump .= $create[1] . ";\n\n";
                while ($row = mysqli_fetch_assoc($rows)) {
                    $values = array_map(function($value) use ($conn) {
                        return $value === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $value) . "'";
                    }, array_values($row));
                    $sql_dump .= "INSERT INTO `$safe_table` VALUES(" . implode(',', $values) . ");\n";
                }
                $sql_dump .= "\n";
            }
            $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

            if (!$backup_failed && file_put_contents($filepath, $sql_dump, LOCK_EX) !== false) {
                $success = "Backup berhasil dibuat: $filename (PHP fallback)";
                @chmod($filepath, 0600);
                log_aktivitas($_SESSION['id_user'], 'Backup Database', "Membuat backup: $filename");
            } else {
                @unlink($filepath);
                $error = "Backup gagal dibuat. Periksa log server dan izin database.";
            }
        }
    }
}

// Proses download
if (isset($_GET['download'])) {
    $file = (string)$_GET['download'];
    $filepath = $storage_error === '' ? get_backup_file_path($backup_folder, $file) : false;

    if ($filepath !== false) {
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    }
}

// Proses hapus
if (isset($_POST['delete_backup'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        $error = "Sesi tidak valid. Muat ulang halaman dan coba lagi.";
    } else {
        $file = (string)($_POST['backup_file'] ?? '');
        $filepath = $storage_error === '' ? get_backup_file_path($backup_folder, $file) : false;
        if ($filepath !== false && unlink($filepath)) {
            $success = "File backup berhasil dihapus.";
            log_aktivitas($_SESSION['id_user'], 'Hapus Backup', "Menghapus backup: $file");
        } elseif ($filepath !== false) {
            $error = "File backup gagal dihapus. Periksa izin penyimpanan.";
        } else {
            $error = "File backup tidak ditemukan atau nama file tidak valid.";
        }
    }
}

// Proses restore
if (isset($_POST['restore'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        $error = "Sesi tidak valid. Muat ulang halaman dan coba lagi.";
    } elseif ($storage_error !== '') {
        $error = $storage_error;
    } elseif (!isset($_FILES['restore_file'])) {
        $error = "Pilih file backup SQL untuk dipulihkan.";
    } else {
        $uploaded = $_FILES['restore_file'];
        $max_restore_size = 100 * 1024 * 1024;
        $upload_valid = isset($uploaded['error'], $uploaded['tmp_name'], $uploaded['name'], $uploaded['size']) &&
            $uploaded['error'] === UPLOAD_ERR_OK &&
            is_uploaded_file($uploaded['tmp_name']) &&
            strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION)) === 'sql' &&
            (int)$uploaded['size'] > 0 &&
            (int)$uploaded['size'] <= $max_restore_size &&
            filesize($uploaded['tmp_name']) <= $max_restore_size;

        if (!$upload_valid) {
            $error = "Upload backup tidak valid. Gunakan file .sql maksimal 100 MB.";
        } else {
            $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : false;
            $mime_type = $finfo ? $finfo->file($uploaded['tmp_name']) : false;
            $sql_content = file_get_contents($uploaded['tmp_name']);

            if ($mime_type !== 'text/plain' || $sql_content === false ||
                strpos($sql_content, "\0") !== false || !preg_match('//u', $sql_content)) {
                $error = "Isi file bukan teks SQL yang valid.";
            } else {
                $is_app_backup = strpos($sql_content, "-- PPDB SMP Database Backup") === 0;
                $is_mysql_backup = strpos($sql_content, "-- MySQL dump") === 0 &&
                    preg_match('/^--\s+Host:.*Database:\s*' . preg_quote(DB_NAME, '/') . '\s*$/mi', $sql_content);
                $statements = split_backup_sql_statements($sql_content);

                if ((!$is_app_backup && !$is_mysql_backup) || $statements === false || count($statements) === 0) {
                    $error = "File tidak dikenali sebagai backup database aplikasi yang valid.";
                } else {
                    $allowed_tables = [];
                    $table_result = mysqli_query($conn, "SHOW TABLES FROM `" . mysqli_real_escape_string($conn, DB_NAME) . "`");
                    if ($table_result) {
                        while ($table_row = mysqli_fetch_row($table_result)) {
                            $allowed_tables[] = $table_row[0];
                        }
                    }
                    $schema_path = __DIR__ . '/../../database/schema.sql';
                    if (is_file($schema_path)) {
                        $schema_content = file_get_contents($schema_path);
                        if ($schema_content !== false &&
                            preg_match_all('/CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`/i', $schema_content, $schema_tables)) {
                            $allowed_tables = array_unique(array_merge($allowed_tables, $schema_tables[1]));
                        }
                    }

                    $invalid_statement = false;
                    foreach ($statements as $statement) {
                        if (!backup_statement_is_allowed($statement, $allowed_tables)) {
                            $invalid_statement = true;
                            break;
                        }
                    }

                    if (!$table_result) {
                        $error = "Gagal memeriksa struktur database sebelum restore.";
                    } elseif ($invalid_statement) {
                        $error = "Restore ditolak: file berisi perintah SQL atau tabel yang tidak diizinkan.";
                    } elseif (!mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0")) {
                        $error = "Restore gagal dimulai. Pengaturan relasi database tidak dapat disiapkan.";
                    } else {
                        $executed_count = 0;
                        $restore_failed = false;
                        $failure_detail = '';

                        try {
                            foreach ($statements as $statement) {
                                if (preg_match('/^SET\s+/i', $statement) ||
                                    preg_match('/^(?:LOCK\s+TABLES|UNLOCK\s+TABLES|ALTER\s+TABLE\s+`[A-Za-z0-9_]+`\s+(?:DISABLE|ENABLE)\s+KEYS)/i', $statement)) {
                                    continue;
                                }
                                if (!mysqli_query($conn, $statement)) {
                                    $restore_failed = true;
                                    $failure_detail = mysqli_error($conn);
                                    break;
                                }
                                $executed_count++;
                            }
                        } catch (Throwable $e) {
                            $restore_failed = true;
                            $failure_detail = $e->getMessage();
                        } finally {
                            if (!mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1")) {
                                $restore_failed = true;
                                $failure_detail .= ' Gagal mengaktifkan kembali pemeriksaan foreign key.';
                            }
                        }

                        if ($restore_failed) {
                            error_log("Restore backup gagal setelah $executed_count perintah: " . $failure_detail);
                            $error = "Restore berhenti setelah $executed_count perintah. Perubahan sebelumnya mungkin sudah diterapkan dan tidak dapat dibatalkan otomatis; periksa database dan pulihkan dari backup yang diketahui valid.";
                        } else {
                            $success = "Restore berhasil. $executed_count perintah backup dieksekusi.";
                            log_aktivitas($_SESSION['id_user'], 'Restore Database', "Restore dari file: " . basename($uploaded['name']));
                        }
                    }
                }
            }
        }
    }
}

// List backup files
$backup_files = [];
if ($storage_error === '' && is_dir($backup_folder)) {
    $files = scandir($backup_folder);
    foreach ($files as $file) {
        $filepath = get_backup_file_path($backup_folder, $file);
        if ($filepath !== false) {
            $backup_files[] = [
                'name' => $file,
                'size' => filesize($filepath),
                'date' => filemtime($filepath)
            ];
        }
    }
    // Sort by date desc
    usort($backup_files, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Database info
$db_size = 0;
$result_tables = mysqli_query($conn, "SHOW TABLE STATUS FROM `" . mysqli_real_escape_string($conn, DB_NAME) . "`");
$table_count = $result_tables ? mysqli_num_rows($result_tables) : 0;
if ($result_tables) {
    while ($table = mysqli_fetch_assoc($result_tables)) {
        $db_size += $table['Data_length'] + $table['Index_length'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Restore - Admin PPDB</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f5f7fa; }
        
        .action-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            height: 100%;
            transition: all 0.3s;
        }
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .action-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.5rem;
        }
        
        .backup-item {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .backup-item:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="fas fa-database text-primary me-2"></i>Backup & Restore
                        </h4>
                        <p class="text-muted mb-0">Kelola backup database sistem</p>
                    </div>
                    <div class="text-end">
                        <small class="text-muted d-block">Database: <strong><?php echo htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8'); ?></strong></small>
                        <small class="text-muted"><?php echo $table_count; ?> tabel | <?php echo number_format($db_size / 1024 / 1024, 2); ?> MB</small>
                    </div>
                </div>
                
                <!-- Action Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="action-card">
                            <div class="action-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                <i class="fas fa-download"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Backup Database</h5>
                            <p class="text-muted mb-3">Buat salinan database saat ini ke file SQL</p>
                            <form method="POST">
                                <?php echo csrf_input(); ?>
                                <button type="submit" name="backup" value="1" class="btn btn-primary btn-lg">
                                    <i class="fas fa-cloud-download-alt me-2"></i>Buat Backup
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="action-card">
                            <div class="action-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                                <i class="fas fa-upload"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Restore Database</h5>
                            <p class="text-muted mb-3">Kembalikan database dari file backup SQL</p>
                            <form method="POST" enctype="multipart/form-data">
                                <?php echo csrf_input(); ?>
                                <div class="mb-3">
                                    <input type="file" class="form-control" name="restore_file" accept=".sql,text/plain" required>
                                </div>
                                <button type="submit" name="restore" class="btn btn-danger btn-lg"
                                        onclick="return confirm('PERINGATAN: Restore akan menimpa data saat ini. Pastikan Anda sudah backup terlebih dahulu. Lanjutkan?')">
                                    <i class="fas fa-cloud-upload-alt me-2"></i>Restore
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Backup Files List -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">
                            <i class="fas fa-folder-open me-2 text-primary"></i>File Backup Tersimpan
                        </h5>
                        <span class="badge bg-primary"><?php echo count($backup_files); ?> file</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($backup_files)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3"></i>
                            <p>Belum ada file backup</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($backup_files as $file): ?>
                        <div class="backup-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-file-code text-primary me-2"></i>
                                    <strong><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="badge bg-secondary ms-2">
                                        <?php echo number_format($file['size'] / 1024, 1); ?> KB
                                    </span>
                                </div>
                                <div>
                                    <small class="text-muted me-3">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('d/m/Y H:i', $file['date']); ?>
                                    </small>
                                    <a href="?download=<?php echo urlencode($file['name']); ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Hapus file backup ini?')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="backup_file"
                                               value="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" name="delete_backup" value="1"
                                                class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Warning -->
                <div class="alert alert-warning mt-4">
                    <h6 class="fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Peringatan!</h6>
                    <ul class="mb-0">
                        <li>Backup secara rutin untuk mencegah kehilangan data</li>
                        <li>Restore akan <strong>menimpa</strong> semua data saat ini</li>
                        <li>Simpan file backup di lokasi aman (cloud storage, external drive)</li>
                        <li>Jangan bagikan file backup ke pihak yang tidak berwenang</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
