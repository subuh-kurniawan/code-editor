<?php
include $_SERVER['DOCUMENT_ROOT'] . "/admin/fungsi/koneksi.php";

// ==========================================
// 1. KONFIGURASI
// ==========================================
$APP_USERNAME = 'subuhkurniawan'; 
$APP_PASSWORD = 'admin123'; 


// ==========================================
// 2. BACKEND (PHP)
// ==========================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

function historyDir($filePath) {
    return __DIR__ . '/.history/' . dirname($filePath);
}

function cleanPath($path) { 
    $path = str_replace('\\', '/', $path);
    return preg_replace('#/+#', '/', $path); 
}

function getSafePath($base, $req) {
    $base = cleanPath($base); $req = cleanPath($req);
    $target = $req ? $base . '/' . $req : $base;
    $target = cleanPath($target);
    $real_base = cleanPath(realpath($base));
    $real_target = realpath($target); 
    if (!$real_target) {
        if (is_dir($target)) return ['valid' => true, 'path' => $target];
        $parent = dirname($target); $real_parent = realpath($parent);
        if ($real_parent && strpos(cleanPath($real_parent), $real_base) === 0) return ['valid' => true, 'path' => $target];
        return ['valid' => false, 'reason' => 'Path not found'];
    }
    $real_target = cleanPath($real_target);
    if (strpos($real_target, $real_base) === 0) return ['valid' => true, 'path' => $real_target];
    return ['valid' => false, 'reason' => 'Forbidden'];
}

function deleteRecursive($dir) {
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) if ($item != '.' && $item != '..') deleteRecursive($dir . '/' . $item);
    return rmdir($dir);
}

if (isset($_POST['action'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['username']) || $_POST['username'] !== $APP_USERNAME || 
            !isset($_POST['password']) || $_POST['password'] !== $APP_PASSWORD) {
            throw new Exception("Unauthorized");
        }
        
        $base_dir = __DIR__;
        $req_path = $_POST['path'] ?? '';
        $check = getSafePath($base_dir, $req_path);

        if ($_POST['action'] == 'login') { echo json_encode(['status' => 'success']); exit; }
        
        // --- MODIFIKASI: SQL RUN menggunakan $koneksi ---
        if ($_POST['action'] == 'sql_run') {
            global $koneksi;
            
            // Pastikan $koneksi tersedia dan tidak ada error koneksi
            if (!isset($koneksi) || $koneksi->connect_error) {
                 throw new Exception("DB Connection Failed or not initialized. Error: " . ($koneksi->connect_error ?? 'N/A'));
            }

            $sql = $_POST['query'];
            $result = $koneksi->query($sql);
            
            if ($result === true) {
                echo json_encode(['status' => 'success', 'msg' => "Query OK. Affected rows: " . $koneksi->affected_rows]);
            } elseif ($result instanceof mysqli_result) {
                // Jika ini adalah SELECT
                $data = $result->fetch_all(MYSQLI_ASSOC);
                echo json_encode(['status' => 'success', 'data' => $data]);
            } else {
                // Jika terjadi error SQL
                throw new Exception("SQL Error: " . $koneksi->error);
            }
            exit;
        }
        
        // --- MODIFIKASI: SQL SCHEMA menggunakan $koneksi ---
        if ($_POST['action'] == 'sql_schema') {
            global $koneksi;
            
            if (!isset($koneksi) || $koneksi->connect_error) {
                 throw new Exception("DB Connection Failed or not initialized. Error: " . ($koneksi->connect_error ?? 'N/A'));
            }

            $tables = [];
            $res = $koneksi->query("SHOW TABLES");
            while($row = $res->fetch_array()) {
                $tableName = $row[0];
                $columns = [];
                $resCol = $koneksi->query("DESCRIBE `$tableName`");
                while($col = $resCol->fetch_assoc()) {
                    $columns[] = ['Field' => $col['Field'], 'Type' => $col['Type']]; // Ubah format output untuk diagram
                }
                $tables[] = ['name' => $tableName, 'columns' => $columns];
            }
            echo json_encode(['status' => 'success', 'tables' => $tables]);
            exit;
        }
        
        // --- FITUR BARU: CODE HEALTH & SECURITY SCANNER ---
        elseif ($_POST['action'] == 'code_scan') {
            // Hanya izinkan scan pada file, bukan folder
            if (!$check['valid']) throw new Exception($check['reason']);
            if (is_dir($check['path'])) throw new Exception("Can only scan files, not directories.");
            
            $content = file_get_contents($check['path']);
            $filename = basename($check['path']);
            $findings = [];
            $lines = explode("\n", $content);

            // Periksa apakah file adalah PHP
            if (!in_array(strtolower(pathinfo($check['path'], PATHINFO_EXTENSION)), ['php', 'phtml'])) {
                 throw new Exception("File type not supported for security scanning (PHP/PHTML only)");
            }

            // Regex for direct unsanitized input near a MySQLi query/execute (High Priority)
            $sql_injection_pattern = '/(\$koneksi|\$conn).*?(query|prepare|execute).*\((.*?)(\$_POST|\$_GET|\$_REQUEST|\$_COOKIE).*?\)/is';
            
            // Regex for XSS potential (Medium Priority)
            $xss_pattern = '/(echo|print|\<\?=)\s*(.*?)(\$_POST|\$_GET|\$_REQUEST|\$_COOKIE).*?;/i';


            foreach ($lines as $i => $line) {
                $lineNumber = $i + 1;
                $trimmedLine = trim($line);
                
                // SQL Injection Scan
                if (preg_match($sql_injection_pattern, $line, $matches)) {
                    // Filter false positives jika ditemukan bind_param atau prepare (indikasi prepared statements)
                    if (!preg_match('/(bind_param|prepare|mysqli_stmt_init)/i', $line)) {
                         $findings[] = [
                            'severity' => 'Critical',
                            'message' => 'Potential SQL Injection Risk (Unprepared Statement or unsanitized input near query/execute)',
                            'line' => $lineNumber,
                            'code' => htmlspecialchars($trimmedLine),
                            'suggestion' => 'Use Prepared Statements (mysqli_prepare/bind_param) or sanitize input using mysqli_real_escape_string().',
                        ];
                    }
                }
                
                // XSS Scan
                if (preg_match($xss_pattern, $line, $matches)) {
                    // Pastikan tidak ada escaping yang digunakan
                    if (!preg_match('/(htmlspecialchars|strip_tags|json_encode)/i', $line)) {
                        $findings[] = [
                            'severity' => 'Medium',
                            'message' => 'Potential XSS Risk (Unescaped user input echoed directly)',
                            'line' => $lineNumber,
                            'code' => htmlspecialchars($trimmedLine),
                            'suggestion' => 'Always use htmlspecialchars() atau strip_tags() when outputting user data.',
                        ];
                    }
                }
            }

            echo json_encode(['status' => 'success', 'filename' => $filename, 'findings' => $findings]);
            exit;
        }
        
        // --- TERMINAL EXECUTION ---
        if ($_POST['action'] == 'terminal') {
            $cmd = $_POST['cmd'];
            $cwd = is_dir($check['path']) ? $check['path'] : dirname($check['path']);
            
            // Security: Limit commands if necessary, current setup is permissive for dev
            chdir($cwd);
            $output = shell_exec($cmd . " 2>&1");
            echo json_encode(['status' => 'success', 'output' => $output ?: 'No output or command failed.', 'cwd' => getcwd()]);
            exit;
        }

        if ($_POST['action'] == 'upload') {
    if (!isset($_FILES['files'])) throw new Exception("No files selected");
    
    $req_path = $_POST['path'] ?? '';
    $check = getSafePath($base_dir, $req_path);
    if (!$check['valid']) throw new Exception("Path Error: " . $check['reason']);
    
    $targetDir = rtrim($check['path'], '/') . '/';

    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
        $name = $_FILES['files']['name'][$key];
        $dest = $targetDir . $name;
        
        // --- LOGIKA OVERWRITE ---
        // Jika file sudah ada, hapus dulu untuk memastikan file bisa ditulis ulang
        if (file_exists($dest)) {
            if (!is_writable($dest)) {
                // Jika file tidak bisa ditulis, coba ubah permission-nya
                chmod($dest, 0664); 
            }
            unlink($dest); 
        }
        
        if (!move_uploaded_file($tmp_name, $dest)) {
            throw new Exception("Gagal menulis file ke: " . $name);
        }
    }
    echo json_encode(['status' => 'success']); 
    exit;
}

        if ($_POST['action'] == 'scan') {
            if (!$check['valid']) throw new Exception($check['reason']);
            $scanDir = is_dir($check['path']) ? $check['path'] : dirname($check['path']);
            $files = scandir($scanDir); $data = [];
            if (cleanPath($scanDir) !== cleanPath($base_dir)) {
                $parent = dirname($scanDir);
                $relParent = ltrim(str_replace(cleanPath($base_dir), '', cleanPath($parent)), '/');
                $data[] = ['name'=>'..','type'=>'folder','path'=>$relParent,'protected'=>true];
            }
            foreach ($files as $f) {
                if ($f == '.' || $f == '..' || $f == basename(__FILE__)) continue;
                $full = $scanDir . '/' . $f;
                $rel = ltrim(str_replace(cleanPath($base_dir), '', cleanPath($full)), '/'); 
                $data[] = ['name'=>$f, 'type'=>is_dir($full)?'folder':'file', 'path'=>$rel];
            }
            usort($data, function($a,$b){ return ($a['type']==$b['type']) ? strcasecmp($a['name'],$b['name']) : (($a['type']=='folder')?-1:1); });
            echo json_encode(['status'=>'success', 'data'=>$data, 'current_path'=>ltrim(str_replace(cleanPath($base_dir), '', cleanPath($scanDir)), '/')]);
        }
        elseif ($_POST['action'] == 'create') {
            $targetPath = $check['path'] . '/' . $_POST['name'];
            if (file_exists($targetPath)) throw new Exception("Already exists!");
            ($_POST['type'] == 'folder') ? mkdir($targetPath) : file_put_contents($targetPath, "");
            echo json_encode(['status'=>'success']);
        }
        elseif ($_POST['action'] === 'diff') {
            $snapFile = historyDir($check['path']) . '/' . $_POST['snapshot'];
            if (!file_exists($snapFile)) throw new Exception("Snapshot not found");
            echo json_encode(['status' => 'success', 'old' => file_get_contents($snapFile), 'new' => file_get_contents($check['path'])]);
        }
        elseif ($_POST['action'] == 'rename') {
            $targetDir = dirname($check['path']);
            $newName = $targetDir . '/' . $_POST['new_name'];
            rename($check['path'], $newName);
            echo json_encode(['status'=>'success']);
        }
        elseif ($_POST['action'] == 'delete') {
            deleteRecursive($check['path']); echo json_encode(['status'=>'success']);
        }
        elseif ($_POST['action'] == 'open') {
            echo json_encode(['status'=>'success', 'content'=>file_get_contents($check['path'])]);
        }
        elseif ($_POST['action'] == 'save') {
    $filePath = $check['path'];
    $content  = $_POST['content'];
    $hDir = historyDir($filePath);
    if (!is_dir($hDir)) mkdir($hDir, 0777, true);

    if (file_exists($filePath)) {
        $baseName = basename($filePath);
        
        // 1. Ambil daftar backup yang sudah ada
        $existingBackups = [];
        foreach (scandir($hDir) as $f) {
            if (str_starts_with($f, $baseName) && str_ends_with($f, '.bak')) {
                $existingBackups[] = $f;
            }
        }
        rsort($existingBackups); // Urutkan dari yang terbaru

        // 2. Jika sudah ada 5 atau lebih, hapus yang paling lama
        // Kita biarkan sisa 4, agar saat kita tambah 1 yang baru sekarang, totalnya jadi 5.
        if (count($existingBackups) >= 5) {
            $toDelete = array_slice($existingBackups, 4); // Ambil indeks ke-4 dan seterusnya (file terlama)
            foreach ($toDelete as $oldFile) {
                @unlink($hDir . '/' . $oldFile);
            }
        }

        // 3. Buat snapshot baru
        $snapName = $baseName . '_' . date('Y-m-d_H-i-s') . '.bak';
        file_put_contents($hDir . '/' . $snapName, file_get_contents($filePath));
    }

    file_put_contents($filePath, $content);
    echo json_encode(['status'=>'success']);
}
       elseif ($_POST['action'] == 'history') {
    $hDir = historyDir($check['path']);
    $list = [];
    if (is_dir($hDir)) {
        foreach (scandir($hDir) as $f) {
            // Memastikan file adalah backup dari file yang sedang dibuka
            if (str_starts_with($f, basename($check['path'])) && str_ends_with($f, '.bak')) {
                $list[] = $f;
            }
        }
    }
    // Urutkan dari yang terbaru (berdasarkan nama file yang mengandung timestamp)
    rsort($list);
    
    // Potong array hanya mengambil 5 entri teratas
    $topFive = array_slice($list, 0, 5);
    
    echo json_encode(['status' => 'success', 'data' => $topFive]);
}
// Tambahkan ini di dalam blok if (isset($_POST['action'])) { ... }
elseif ($_POST['action'] == 'delete_all_history') {
    $hDir = historyDir($check['path']);
    if (is_dir($hDir)) {
        $files = scandir($hDir);
        $baseName = basename($check['path']);
        foreach ($files as $f) {
            // Hapus hanya file backup yang terkait dengan file aktif ini
            if (str_starts_with($f, $baseName) && str_ends_with($f, '.bak')) {
                @unlink($hDir . '/' . $f);
            }
        }
    }
    echo json_encode(['status' => 'success']);
}
        elseif ($_POST['action'] == 'restore') {
            $snapFile = historyDir($check['path']) . '/' . $_POST['snapshot'];
            file_put_contents($check['path'], file_get_contents($snapFile));
            echo json_encode(['status'=>'success']);
        }
        elseif ($_POST['action'] == 'sys_info') {
            echo json_encode(['status' => 'success', 'info' => [
                'os' => PHP_OS, 'php' => PHP_VERSION, 
                'space' => round(disk_free_space("/") / (1024 * 1024 * 1024), 2) . " GB",
                'upload' => ini_get('upload_max_filesize')
            ]]);
        }
        exit;
    } catch (Exception $e) { echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]); exit; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secured IDE Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-palenight.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/monokai.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/nord.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/oceanic-next.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/ayu-mirage.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/tomorrow-night-eighties.min.css">
    <style>
        :root { 
            --bg-body: #f8fafc;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.8);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.2);
            --sidebar-hover: rgba(99, 102, 241, 0.05);
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }

        .dark-mode {
            --bg-body: #020617;
            --glass-bg: rgba(15, 15, 20, 0.85);
            --glass-border: rgba(255, 255, 255, 0.05);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #818cf8;
            --accent-glow: rgba(129, 140, 248, 0.25);
            --sidebar-hover: rgba(255, 255, 255, 0.03);
            --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }

        body { 
            height: 100vh; overflow:hidden; display:flex; flex-direction:column; 
            background-color: var(--bg-body); color: var(--text-primary); 
            font-family: 'Inter', sans-serif; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ZEN MODE STYLES */
        body.zen-active nav, body.zen-active aside { display: none !important; }
        body.zen-active .workspace { width: 100%; height: 100vh; }
        body.zen-active #zen-exit-btn { display: flex !important; }

        .glass {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            box-shadow: var(--card-shadow);
        }

        /* Sidebar Styling */
        .sidebar { width: 280px; flex-shrink: 0; transition: all 0.3s ease; z-index: 50; }
        .sidebar.collapsed { margin-left: -280px; }

        /* Right Sidebar Styling */
        .right-sidebar { 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            z-index: 45; 
            flex-shrink: 0;
            overflow: hidden;
            border-left: 1px solid var(--glass-border);
        }
        
        .right-sidebar.collapsed { width: 0 !important; border-left: none; }
        .right-sidebar.expanded { width: 260px !important; }

        /* Workspace & Tabs */
        .workspace { flex: 1; display: flex; flex-direction: column; min-width: 0; position: relative; }
        .tab-container { display: flex; height: 48px; overflow-x: auto; gap: 4px; padding: 6px 12px 0; border-bottom: 1px solid var(--glass-border); }
        .tab { 
            display: flex; align-items: center; gap: 8px; padding: 0 14px; height: 100%; font-size: 11px; font-weight: 500;
            color: var(--text-secondary); background: transparent; border-radius: 8px 8px 0 0; cursor: pointer; 
            transition: all 0.2s ease; border: 1px solid transparent; border-bottom: none;
        }
        .tab:hover { color: var(--text-primary); background: var(--sidebar-hover); }
        .tab.active { 
            color: var(--accent); background: var(--glass-bg); 
            border-color: var(--glass-border); font-weight: 600;
            box-shadow: 0 -2px 10px var(--accent-glow);
        }

        /* Editor Styling */
        .CodeMirror { 
            position: absolute !important; inset: 0; height: 100% !important; 
            font-family: 'Fira Code', monospace; font-size: 14px; 
            background: var(--glass-bg) !important; transition: background 0.4s ease;
        }
        .dark-mode .CodeMirror { background: #0b1222 !important; }

        /* File List Items */
        .file-item { 
            transition: all 0.2s ease; border-radius: 10px; margin: 2px 0; 
            padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;
        }
        .file-item:hover { background: var(--sidebar-hover); transform: translateX(4px); }
        .file-item.active-file { background: var(--accent-glow); border-left: 3px solid var(--accent); }

        /* UI Buttons */
        .btn-ui {
            transition: all 0.2s ease; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }
        .btn-ui:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-ui:active { transform: translateY(0px); }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; opacity: 0.3; }

        #search-panel { 
            position: absolute; top: 15px; right: 30px; width: 340px; z-index: 1000; 
            display: none; padding: 20px; border-radius: 16px;
        }

        /* Terminal Styling */
        #terminal-panel {
            position: absolute; bottom: 0; left: 0; right: 0; height: 35%; 
            z-index: 200; transform: translateY(100%); transition: transform 0.3s ease;
            display: flex; flex-direction: column;
        }
        #terminal-panel.active { transform: translateY(0); }
        .terminal-out { font-family: 'Fira Code', monospace; font-size: 12px; }

        input[type="text"], input[type="password"] {
            background: rgba(124, 58, 237, 0.03);
            border: 1px solid var(--glass-border);
            transition: all 0.2s ease;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        #modal-msg,
#modal-msg * {
    color: rgba(255, 255, 255, 0.95) !important;
}
#login-overlay {
    background: rgba(0, 0, 0, 0.5) url('https://res.allmacwallpaper.com/get/macbook-air-wallpapers/abstract-blur-4k-5k/21899-720.jpg') no-repeat center center; 
    background-size: cover; /* This ensures the background image covers the entire overlay */
}
/* Menyesuaikan warna scrollbar otomatis berdasarkan tema CodeMirror */
.CodeMirror-vscrollbar, .CodeMirror-hscrollbar {
    background: transparent !important;
}

.dark-mode .CodeMirror {
    /* Jika di dark mode, pastikan background editor tidak bentrok dengan glassmorphism */
    background: rgba(15, 23, 42, 0.95) !important;
}
/* Warna highlight saat navigasi panah */
.file-item.kb-focus {
    background: var(--accent-glow);
    outline: 1px solid var(--accent);
    transform: translateX(6px);
}
#context-menu {
    transform-origin: top left;
    animation: menuFade 0.15s ease-out;
}
@keyframes menuFade {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

/* Schema Diagram Specific CSS (using dark mode colors) */
.schema-node {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5);
    color: #f8fafc;
    min-width: 250px;
}
.schema-header {
    background: #0f172a;
    padding: 10px 15px;
    border-radius: 12px 12px 0 0;
    font-weight: bold;
    font-size: 12px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #a5b4fc;
}
.schema-column {
    padding: 6px 15px;
    border-bottom: 1px dashed #33415555;
    font-size: 10px;
    font-family: 'Fira Code', monospace;
}
.schema-column:last-child {
    border-bottom: none;
}
    </style>
</head>
<body>

<div id="zen-exit-btn" onclick="toggleZen()" class="hidden fixed top-4 right-4 z-[9999] w-12 h-12 bg-red-500/20 text-red-500 rounded-full cursor-pointer items-center justify-center hover:bg-red-500 hover:text-white transition-all">
    <i class="fas fa-compress"></i>
</div>

<div id="command-palette" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md hidden z-[999] flex items-start justify-center pt-32 p-4">
  <div class="w-full max-w-xl glass rounded-2xl shadow-2xl overflow-hidden transform transition-all">
    <div class="p-4 border-b border-white/10 flex items-center gap-3">
        <i class="fas fa-terminal opacity-40"></i>
        <input id="cmd-input" class="w-full bg-transparent outline-none text-current text-sm" placeholder="Apa yang ingin Anda kerjakan hari ini?..." autocomplete="off">
    </div>
    <div id="cmd-list" class="max-h-72 overflow-y-auto p-2 space-y-1"></div>
  </div>
</div>
<div id="sql-modal"
  class="fixed inset-0 z-[600] hidden
         flex items-center justify-center
         p-2 sm:p-4
         bg-slate-900/80 backdrop-blur-sm
         overflow-y-auto">

  <div
    class="bg-white w-full max-w-6xl
           rounded-2xl overflow-hidden
           shadow-2xl border border-white/20
           h-full max-h-[95svh] sm:max-h-[85svh]
           flex flex-col">

    <!-- Header -->
    <div
      class="px-4 sm:px-6 py-3 sm:py-4
             border-b border-white/10
             flex justify-between items-center
             bg-slate-800 shrink-0">
      <h3 class="font-bold text-[10px] sm:text-xs uppercase tracking-[0.15em] text-white">
        <i class="fas fa-database mr-2"></i>SQL Manager Pro
      </h3>
      <button onclick="toggleSQLModal(true)"
        class="text-white opacity-50 hover:opacity-100">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Content -->
    <div class="flex-1 flex flex-col md:flex-row overflow-hidden min-h-0">

      <!-- Left Sidebar: Schema Explorer (Tabbed) -->
      <div
        class="w-full md:w-72
               bg-slate-900/50
               border-b md:border-b-0 md:border-r
               border-white/10
               flex flex-col
               max-h-[35svh] md:max-h-none
               shrink-0">

        <!-- Sidebar Tabs -->
        <div class="flex border-b border-white/5 bg-black/10 shrink-0">
            <button id="btn-sql-tab-explorer" onclick="switchSQLTab('explorer')" class="flex-1 p-3 text-[10px] text-white/80 font-bold uppercase tracking-wider border-r border-white/5 border-b-2 border-orange-500">
                <i class="fas fa-list-alt"></i> Explorer
            </button>
            <button id="btn-sql-tab-tools" onclick="switchSQLTab('tools')" class="flex-1 p-3 text-[10px] text-white/50 font-bold uppercase tracking-wider">
                <i class="fas fa-wrench"></i> Tools
            </button>
        </div>

        <!-- Explorer Content -->
        <div id="sql-tab-explorer" class="flex-1 flex flex-col min-h-0">
            <div class="p-4 border-b border-white/5 flex justify-between items-center shrink-0">
                <span class="text-[9px] text-white opacity-40 uppercase tracking-widest">
                    Database Schema
                </span>
                <button onclick="loadSQLSchema()"
                    class="text-orange-800 hover:rotate-180 transition-all duration-500">
                    <i class="fas fa-sync-alt text-xs"></i>
                </button>
            </div>
            <div id="sql-schema-list"
                class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3 text-[11px] text-white/90">
                <div class="animate-pulse opacity-20 italic">Loading schema...</div>
            </div>
        </div>

        <!-- Tools Content -->
        <div id="sql-tab-tools" class="hidden flex-col p-4 space-y-4 flex-1 min-h-0 overflow-y-auto">
            <h4 class="text-[10px] uppercase font-bold text-white/60 tracking-wider">Schema Diagram Mockup</h4>
            <div class="space-y-3">
                <select id="select-table-a" class="w-full p-2 text-xs bg-slate-700/50 border border-white/10 rounded-lg text-white/80"></select>
                <select id="select-table-b" class="w-full p-2 text-xs bg-slate-700/50 border border-white/10 rounded-lg text-white/80"></select>
                <button onclick="generateSchemaDiagram()" class="w-full py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-[11px] font-bold uppercase">
                    Generate Diagram
                </button>
            </div>
            <hr class="border-white/10">
            <div id="schema-diagram-output" class="p-3 bg-black/50 rounded-lg text-white/80 flex flex-col items-center justify-center gap-6 min-h-[200px] text-center italic text-xs">
                Select tables and click Generate.
            </div>
        </div>


      </div>

      <!-- Main Content (Editor/Results) -->
      <div id="sql-main-panel" class="flex-1 flex flex-col p-4 sm:p-6 bg-slate-950/30 overflow-hidden min-h-0">

        <!-- SQL Editor -->
        <div class="mb-4 shrink-0">
          <textarea id="sql-query-input"
            class="w-full h-32 sm:h-40
                   bg-black/90 text-white
                   font-mono text-xs sm:text-sm
                   p-3 sm:p-4 rounded-xl
                   outline-none border border-white/10
                   focus:border-orange-500/50
                   transition-colors resize-none"
            placeholder="Contoh: SELECT * FROM users LIMIT 10;"></textarea>

          <div class="flex flex-col sm:flex-row justify-between gap-2 sm:items-center mt-2">
            <span class="text-[9px] opacity-30 font-mono italic">
              Tip: Klik nama tabel di kiri untuk memasukkannya ke editor.
            </span>
            <button onclick="runSQL()"
              class="px-6 sm:px-8 py-2
                     bg-orange-600 hover:bg-orange-500
                     text-white rounded-xl
                     text-[9px] sm:text-[10px]
                     font-black uppercase
                     shadow-lg shadow-orange-900/20">
              Execute Query
            </button>
          </div>
        </div>

        <!-- Result -->
        <div
          id="sql-results"
          class="flex-1 min-h-0
                 bg-black/90 rounded-xl
                 border border-white/5
                 overflow-auto
                 p-3 sm:p-4
                 relative text-xs">

          <div class="absolute inset-0 flex items-center justify-center opacity-20 italic pointer-events-none">
            Result set will appear here
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<div id="modal-container" class="fixed inset-0 z-[500] hidden items-center justify-center p-6 bg-slate-950/70 backdrop-blur-md">
    <div class="glass relative w-full max-w-6xl rounded-3xl overflow-hidden shadow-[0_30px_80px_-20px_rgba(0,0,0,0.6)] border border-white/10 bg-gradient-to-br from-blue-500/70 via-indigo-500/60 to-purple-500/70">
        
        <!-- Header -->
        <div class="px-8 py-5 border-b border-white/10 flex justify-between items-center">
            <h3 id="modal-title" class="font-semibold text-[11px] uppercase tracking-[0.25em] text-white/90">
                System Notification
            </h3>
            <button onclick="closeModal()" class="w-9 h-9 rounded-full hover:bg-white/10 transition-all flex items-center justify-center text-white/70 hover:text-white">
                <i class="fas fa-times text-sm"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="p-2">
            <div id="modal-msg" class="text-sm text-white/80 mb-8 text-center leading-relaxed max-w-7xl mx-auto"></div>

            <input
                type="text"
                id="modal-input"
                class="w-full rounded-2xl p-4 text-white bg-white/5 border border-white/10 outline-none mb-8 hidden focus:ring-2 focus:ring-purple-500/40 placeholder:text-white/40"
                placeholder="..."
            >

            <!-- Snippet Grid -->
            <div id="snippet-grid" class="hidden grid-cols-2 md:grid-cols-3 gap-5 mb-8">
                <button onclick="insertSnippet('html5')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-purple-300 mb-1">HTML5 Boilerplate</div>
                    <div class="text-[11px] text-white/40">Basic HTML Structure</div>
                </button>

                <button onclick="insertSnippet('php_start')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-blue-300 mb-1">PHP Init</div>
                    <div class="text-[11px] text-white/40">&lt;?php ... ?&gt;</div>
                </button>

                <button onclick="insertSnippet('tailwind_card')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-teal-300 mb-1">Tailwind Card</div>
                    <div class="text-[11px] text-white/40">Glassmorphism card</div>
                </button>

                <button onclick="insertSnippet('php_conn')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-orange-300 mb-1">DB Connection</div>
                    <div class="text-[11px] text-white/40">MySQLi connection</div>
                </button>

                <button onclick="insertSnippet('html_form')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-orange-300 mb-1">HTML Form</div>
                    <div class="text-[11px] text-white/40">Form with Tailwind CSS</div>
                </button>

                <button onclick="insertSnippet('tailwind_grid')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-purple-300 mb-1">Tailwind Grid</div>
                    <div class="text-[11px] text-white/40">Responsive grid layout</div>
                </button>

                <button onclick="insertSnippet('tailwind_flex')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-blue-300 mb-1">Tailwind Flex</div>
                    <div class="text-[11px] text-white/40">Flexbox example</div>
                </button>

                <button onclick="insertSnippet('tailwind_center')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-teal-300 mb-1">Tailwind Center</div>
                    <div class="text-[11px] text-white/40">Centered content</div>
                </button>

                <button onclick="insertSnippet('tailwind_columns')" class="group p-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-left transition-all hover:-translate-y-0.5">
                    <div class="font-semibold text-orange-300 mb-1">Tailwind Columns</div>
                    <div class="text-[11px] text-white/40">Text columns layout</div>
                </button>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-4">
                <button onclick="closeModal()" class="px-6 py-2.5 text-xs font-semibold uppercase text-white/40 hover:text-white transition-all">
                    Batalkan
                </button>
                <button id="modal-btn-confirm" class="px-7 py-2.5 text-xs font-semibold uppercase rounded-2xl text-white bg-gradient-to-r from-purple-600 to-indigo-600 shadow-lg shadow-purple-500/30 hover:shadow-indigo-500/40 transition-all hover:-translate-y-0.5">
                    Lanjutkan
                </button>
            </div>
        </div>
    </div>
</div>
<div id="quick-search" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md hidden z-[1000] flex items-start justify-center pt-32 p-4">
  <div class="w-full max-w-xl glass rounded-2xl shadow-2xl overflow-hidden transform transition-all border border-white/10">
    <div class="p-4 border-b border-white/10 flex items-center gap-3">
        <i class="fas fa-search opacity-40 text-purple-500"></i>
        <input id="quick-search-input" class="w-full bg-transparent outline-none text-current text-sm" placeholder="Ketik nama file untuk membuka..." autocomplete="off">
    </div>
    <div id="quick-search-list" class="max-h-80 overflow-y-auto p-2 space-y-1">
        </div>
  </div>
</div>
<div id="login-overlay" class="fixed inset-0 bg-slate-50 dark:bg-slate-950 z-[300] flex items-center justify-center p-6 transition-all duration-700">
    <div class="glass p-10 md:p-14 rounded-[32px] w-full max-w-md shadow-2xl text-center border border-white/20">
        <div class="w-24 h-24 bg-gradient-to-tr from-purple-500/20 to-indigo-500/20 rounded-[28px] flex items-center justify-center mx-auto mb-10 shadow-inner">
            <i class="fas fa-fingerprint text-4xl text-purple-500 animate-pulse"></i>
        </div>
        <h2 class="text-3xl font-black tracking-tight mb-2">IDE Multi Mode</h2>
        <p class="text-xs font-medium opacity-40 uppercase tracking-widest mb-10">Protected Workspace</p>
        <form onsubmit="doLogin(event)" class="space-y-4">
            <input type="text" id="login-user" class="w-full rounded-2xl p-4 text-center outline-none" placeholder="Username" required>
            <input type="password" id="login-pass" class="w-full rounded-2xl p-4 text-center outline-none" placeholder="Security Token" required>
            <button class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 py-4 rounded-2xl font-bold text-white shadow-xl hover:scale-[0.98] transition-all mt-4">Akses Workspace</button>
        </form>
        <p class="text-sm  opacity-40  tracking-widest mb-10">kurniawansubuh@gmail.com</p>
    </div>
</div>

<nav class="h-16 glass flex items-center justify-between px-8 shrink-0 z-[60] border-b border-white/10">
    <div class="flex items-center gap-8">
        <button onclick="toggleSidebar()" class="opacity-50 hover:opacity-100 transition-opacity"><i class="fas fa-bars-staggered"></i></button>
        <div class="flex items-center gap-3">
            <span class="w-2 h-2 rounded-full bg-purple-500"></span>
            <span class="font-bold text-[11px] uppercase tracking-[0.25em] opacity-80">IDE <span class="text-purple-500">PRO</span> v5.0</span>
             <div id="sys-info-content" class="text-[10px] font-mono opacity-80 leading-tight"></div>
        </div>
    </div>
    <div class="flex items-center gap-5">
        <button onclick="toggleDarkMode()" id="theme-toggle" class="btn-ui w-10 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-sm">
            <i class="fas fa-moon"></i>
        </button>
        <select id="theme-selector" onchange="changeTheme(this.value)" 
    class="bg-slate-200/50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-xl px-3 py-1 text-[10px] font-bold outline-none text-slate-900 dark:text-white cursor-pointer hover:bg-slate-300/50 dark:hover:bg-white/10 transition-all focus:ring-2 focus:ring-purple-500/50">
    <optgroup label="Dark Themes" class="bg-slate-900 text-white">
        <option value="dracula" selected>Dracula (Default)</option>
        <option value="material-palenight">Material Palenight</option>
        <option value="monokai">Monokai</option>
        <option value="nord">Nord</option>
        <option value="oceanic-next">Oceanic Next</option>
        <option value="tomorrow-night-eighties">Tomorrow Night</option>
    </optgroup>
    <optgroup label="Light Themes" class="bg-white text-slate-900">
        <option value="default">Classic Light</option>
        <option value="neo">Neo Light</option>
    </optgroup>
</select>
        <div id="save-status" class="text-[9px] font-bold opacity-30 uppercase tracking-widest mr-4 hidden md:block">Ready</div>
        <div class="h-8 w-[1px] bg-white/10"></div>
        <div class="flex items-center gap-2">
            
            <button onclick="openSnippetModal()" class="btn-ui w-10 h-10 bg-pink-500/10 text-pink-500 border border-pink-500/20" title="Snippets"><i class="fas fa-puzzle-piece text-xs"></i></button>
            <button onclick="toggleZen()" class="btn-ui w-10 h-10 bg-indigo-500/10 text-indigo-500 border border-indigo-500/20" title="Zen Mode"><i class="fas fa-expand text-xs"></i></button>
            <button onclick="toggleSQLModal()" class="btn-ui w-10 h-10 bg-orange-500/10 text-orange-500 border border-orange-500/20" title="SQL Manager"><i class="fas fa-database text-xs"></i></button>
            <div class="h-4 w-[1px] bg-white/10 mx-1"></div>

            <button onclick="runCodeScan()" class="btn-ui w-10 h-10 bg-red-500/10 text-red-500 border border-red-500/20" title="Security Scan"><i class="fas fa-shield-virus text-xs"></i></button>
            <button onclick="toggleRightSidebar()" class="btn-ui w-10 h-10 bg-purple-500/10 text-purple-500 border border-purple-500/20" title="Toggle Outline/Notes"><i class="fas fa-columns text-xs"></i></button>
            
            <button onclick="toggleSearchPanel()" class="btn-ui bg-white/5 px-4 h-10 text-[10px] font-bold border border-white/10 opacity-70 hover:opacity-100">CARI</button>
            
            <button onclick="formatCode()" class="btn-ui w-10 h-10 bg-orange-500/10 text-orange-500 border border-orange-500/20" title="Format Code (Alt+Shift+F)"><i class="fas fa-magic text-xs"></i></button>
            <button onclick="togglePreview()" class="btn-ui w-10 h-10 bg-blue-500/10 text-blue-500 border border-blue-500/20"><i class="fas fa-play text-xs"></i></button>
            <button onclick="saveFile()" class="btn-ui w-10 h-10 bg-emerald-500/10 text-emerald-500 border border-emerald-500/20"><i class="fas fa-save text-xs"></i></button>
            <button onclick="logout()" class="btn-ui w-10 h-10 opacity-30 hover:text-red-500 hover:opacity-100"><i class="fas fa-power-off text-xs"></i></button>
        </div>
    </div>
</nav>

<div class="flex-1 flex min-h-0 overflow-hidden relative">
    
    <aside class="sidebar glass border-r border-white/10 flex flex-col" id="main-sidebar">
        <div class="p-6 space-y-4">
            <div class="flex gap-2">
                <button onclick="promptCreateModal('file')" class="flex-1 bg-white/5 py-3 rounded-xl text-[10px] font-bold border border-white/10 hover:bg-white/10 transition-all">+ FILE</button>
                <button onclick="promptCreateModal('folder')" class="flex-1 bg-white/5 py-3 rounded-xl text-[10px] font-bold border border-white/10 hover:bg-white/10 transition-all">+ DIR</button>
            </div>
            <button onclick="document.getElementById('upload-input').click()" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-3.5 rounded-xl text-[10px] font-black uppercase shadow-lg shadow-purple-500/20 tracking-wider">Upload Aset</button>
            <input type="file" id="upload-input" multiple class="hidden" onchange="handleUpload(this)">
        </div>

        <div class="px-6 mb-4">
            <div class="relative group">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-[10px] opacity-30 group-focus-within:text-purple-500 transition-colors"></i>
                <input type="text" id="file-search-input" class="w-full rounded-xl py-2.5 pl-10 pr-4 text-[11px] outline-none" placeholder="Filter berkas...">
            </div>
        </div>

        <div id="file-list" class="flex-1 overflow-y-auto px-4 py-2 space-y-1"></div>
        
    </aside>

    <main class="workspace">
        <div class="tab-container glass" id="tab-container"></div>
        <div class="flex-1 relative min-h-0">
            <textarea id="code-editor"></textarea>
            
            <div id="search-panel" class="glass shadow-2xl border border-white/20">
                <div class="flex justify-between items-center mb-5">
                    <span class="text-[10px] font-black opacity-30 uppercase tracking-[0.2em]">Cari & Ganti</span>
                    <button onclick="toggleSearchPanel()" class="opacity-50 hover:opacity-100"><i class="fas fa-times"></i></button>
                </div>
                <div class="space-y-3">
                    <div class="relative">
                        <input type="text" id="find-input" class="w-full rounded-xl p-3 text-xs outline-none pl-10" placeholder="Temukan teks...">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-[10px] opacity-30"></i>
                    </div>
                    <div class="relative">
                        <input type="text" id="replace-input" class="w-full rounded-xl p-3 text-xs outline-none pl-10" placeholder="Ganti dengan...">
                        <i class="fas fa-sync absolute left-4 top-1/2 -translate-y-1/2 text-[10px] opacity-30"></i>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button onclick="findNext()" class="flex-1 bg-white/10 py-3 text-[10px] font-bold rounded-xl border border-white/10">BERIKUTNYA</button>
                    <button onclick="replaceAll()" class="flex-1 bg-purple-600 py-3 text-[10px] font-bold rounded-xl text-white shadow-lg">GANTI SEMUA</button>
                </div>
            </div>

            <div class="absolute bottom-6 right-6 z-[60] flex flex-col gap-3 items-end">
                <button onclick="toggleIframeModal()" class="w-12 h-12 glass rounded-2xl flex items-center justify-center text-purple-500 shadow-2xl hover:scale-110 transition-all border border-purple-500/20">
                    <i class="fas fa-project-diagram"></i>
                </button>
            </div>

            <div id="terminal-panel" class="glass border-t border-white/10 shadow-[0_-10px_40px_rgba(0,0,0,0.5)]">
                <div class="h-8 bg-slate-900/50 flex items-center justify-between px-4 border-b border-white/5 cursor-ns-resize">
                    <span class="text-[10px] font-mono opacity-50"><i class="fas fa-terminal mr-2"></i>CONSOLE</span>
                    <button onclick="toggleTerminal()" class="text-white/30 hover:text-white"><i class="fas fa-times"></i></button>
                </div>
                <div class="flex-1 bg-slate-950 p-4 overflow-y-auto terminal-out text-green-400" id="terminal-output">
                    <div class="opacity-50 mb-2">Welcome to Secure IDE Console. Type command below.</div>
                </div>
                <div class="p-2 bg-slate-900 flex gap-2">
                    <span class="text-green-500 font-mono text-sm">$</span>
                    <input type="text" id="terminal-input" class="bg-transparent border-none outline-none text-white font-mono text-sm w-full" placeholder="ls -la" onkeydown="handleTerminal(event)">
                </div>
            </div>

            <div id="iframe-modal" class="fixed inset-0 z-[1000] bg-black/75 backdrop-blur-sm hidden items-center justify-center p-5" onclick="if(event.target === this) toggleIframeModal()">
                <div class="w-[95%] h-[90%] bg-white rounded-2xl overflow-hidden flex flex-col shadow-2xl">
                    <div class="h-12 bg-[#0d1117] flex items-center justify-between px-5 text-white">
                        <span class="text-[10px] font-black uppercase">External Module</span>
                        <button onclick="toggleIframeModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <iframe src="fo3.php" class="flex-1 border-none"></iframe>
                </div>
            </div>
<div id="image-viewer" class="absolute inset-0 z-30 bg-slate-100/50 dark:bg-slate-900/50 backdrop-blur-xl hidden flex-col items-center justify-center p-12">
    <div id="editor-toolbar" class="mb-6 flex gap-3 glass p-3 rounded-2xl border border-white/20 hidden">
        <button onclick="cropper.setDragMode('crop')" class="btn-ui w-10 h-10 bg-white/5" title="Crop Mode"><i class="fas fa-crop-alt"></i></button>
        <button onclick="cropper.rotate(-90)" class="btn-ui w-10 h-10 bg-white/5" title="Rotate Left"><i class="fas fa-undo"></i></button>
        <button onclick="cropper.rotate(90)" class="btn-ui w-10 h-10 bg-white/5" title="Rotate Right"><i class="fas fa-redo"></i></button>
        <button onclick="cropper.scaleX(-1)" class="btn-ui w-10 h-10 bg-white/5" title="Flip Horizontal"><i class="fas fa-arrows-alt-h"></i></button>
        <div class="h-8 w-[1px] bg-white/10 mx-1"></div>
        <button onclick="applyImageEdit()" class="btn-ui px-4 bg-emerald-500/20 text-emerald-500 font-bold text-[10px]">SIMPAN PERUBAHAN</button>
        <button onclick="cancelImageEdit()" class="btn-ui px-4 bg-red-500/20 text-red-500 font-bold text-[10px]">BATAL</button>
    </div>

    <div class="relative group max-w-4xl max-h-[70vh]">
        <button id="start-edit-btn" onclick="startPhotoEditor()" class="absolute -top-12 left-0 bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-lg hover:bg-blue-700 transition-all">
            <i class="fas fa-magic mr-2"></i> EDIT GAMBAR
        </button>
        <img id="image-display" src="" class="max-w-full max-h-full object-contain shadow-2xl rounded-xl border-4 border-white/20">
    </div>
</div>
<div id="media-viewer" class="absolute inset-0 z-30 bg-slate-100/50 dark:bg-slate-900/50 backdrop-blur-xl hidden items-center justify-center p-12">
    <div class="max-w-4xl w-full bg-black/20 p-8 rounded-3xl border border-white/20 shadow-2xl flex flex-col items-center">
        <video id="video-display" controls class="max-w-full max-h-[70vh] rounded-xl hidden shadow-lg"></video>
        <audio id="audio-display" controls class="w-full hidden mt-4"></audio>
        <div id="media-title" class="mt-4 text-xs font-mono opacity-60"></div>
    </div>
</div>
            <div id="preview-container" class="absolute inset-0 bg-white z-[60] flex flex-col translate-y-full transition-all duration-500 shadow-2xl">
                <div class="h-10 glass flex items-center justify-between px-4 border-b border-white/10">
                    <div class="flex items-center gap-2">
                        <button onclick="setPreviewSize('100%')" class="p-2 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg text-xs" title="Desktop"><i class="fas fa-desktop"></i></button>
                        <button onclick="setPreviewSize('768px')" class="p-2 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg text-xs" title="Tablet"><i class="fas fa-tablet-alt"></i></button>
                        <button onclick="setPreviewSize('375px')" class="p-2 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg text-xs" title="Mobile"><i class="fas fa-mobile-alt"></i></button>
                        <div class="h-4 w-[1px] bg-gray-300 dark:bg-gray-600 mx-2"></div>
                        <span class="text-[10px] font-bold opacity-50 uppercase tracking-widest">Preview</span>
                    </div>
                    <button onclick="togglePreview()" class="text-red-500 hover:rotate-90 transition-all duration-300"><i class="fas fa-times"></i></button>
                </div>
                <div class="flex-1 bg-gray-100 dark:bg-gray-900 overflow-auto flex justify-center py-4">
                    <iframe id="preview-frame" class="w-full h-full bg-white border-none shadow-xl transition-all" src="about:blank"></iframe>
                </div>
            </div>
        </div>
    </main>

    <aside id="right-sidebar" class="right-sidebar glass absolute right-0 top-0 bottom-0 h-full lg:relative lg:block collapsed lg:expanded">
        <div class="h-full flex flex-col w-[260px]"> 
            <div class="px-6 py-4 border-b border-white/10 flex justify-between items-center bg-white/5">
                <div class="flex gap-4">
                    <button onclick="switchRightTab('outline')" id="btn-tab-outline" class="text-[9px] opacity-100 uppercase font-black tracking-widest border-b-2 border-purple-500 pb-1">Outline</button>
                    <button onclick="switchRightTab('notes')" id="btn-tab-notes" class="text-[9px] opacity-40 uppercase font-black tracking-widest pb-1">Notes</button>
                </div>
                <button onclick="toggleRightSidebar()" class="lg:hidden opacity-50 hover:opacity-100"><i class="fas fa-times"></i></button>
            </div>
            
            <div id="outline-panel" class="flex-1 overflow-y-auto p-4 space-y-1 text-[11px] opacity-80">
                <div class="opacity-40 italic text-center mt-10">No active file</div>
            </div>

            <div id="notes-panel" class="hidden flex-1 flex-col p-4 h-full">
                <textarea id="scratchpad" class="w-full h-full bg-black/10 rounded-xl p-3 text-[11px] outline-none font-mono resize-none border border-white/10 text-current" placeholder="Catatan cepat (tersimpan otomatis)..."></textarea>
            </div>
        </div>
    </aside>

</div>

<footer class="status-bar shrink-0 flex items-center justify-between">
    <div class="flex items-center gap-6">
        <div id="path-indicator" class="opacity-60 font-mono text-[10px] lowercase tracking-tight">/root</div>
    </div>
    <div class="text-[8px] font-black uppercase tracking-tighter opacity-40 hidden lg:block">Ready to compile • Elegance Pro 5.0</div>
</footer>

<div id="toast" class="fixed bottom-12 left-1/2 -translate-x-1/2 bg-slate-900 text-white dark:bg-white dark:text-slate-900 px-8 py-3 rounded-full text-[10px] font-black z-[200] opacity-0 translate-y-10 transition-all duration-500 uppercase shadow-2xl flex items-center gap-3">
    <div id="toast-icon" class="w-4 h-4 rounded-full flex items-center justify-center text-[8px] bg-emerald-500 text-white">✓</div>
    <span id="toast-msg">Success</span>
</div>
<div id="context-menu" class="fixed hidden z-[1000] w-48 glass rounded-xl shadow-2xl border border-white/10 overflow-hidden py-1">
    <div onclick="ctxAction('open')" class="px-4 py-2.5 text-[11px] font-medium hover:bg-purple-500 hover:text-white cursor-pointer transition-colors flex items-center gap-3">
        <i class="fas fa-external-link-alt opacity-50"></i> Buka Berkas
    </div>
    <div onclick="ctxAction('rename')" class="px-4 py-2.5 text-[11px] font-medium hover:bg-purple-500 hover:text-white cursor-pointer transition-colors flex items-center gap-3">
        <i class="fas fa-edit opacity-50"></i> Ganti Nama
    </div>
    <div class="h-[1px] bg-white/5 my-1"></div>
    <div onclick="ctxAction('delete')" class="px-4 py-2.5 text-[11px] font-medium text-red-400 hover:bg-red-500 hover:text-white cursor-pointer transition-colors flex items-center gap-3">
        <i class="fas fa-trash-alt opacity-50"></i> Hapus Permanen
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js"></script>

<script src="https://unpkg.com/prettier@2.8.8/standalone.js"></script>
<script src="https://unpkg.com/prettier@2.8.8/parser-html.js"></script>
<script src="https://unpkg.com/prettier@2.8.8/parser-babel.js"></script>
<script src="https://unpkg.com/prettier@2.8.8/parser-postcss.js"></script>
<script src="https://unpkg.com/@prettier/plugin-php@0.19.7/standalone.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
let cropper;
let dbSchemaData = []; // Variabel global untuk menyimpan data skema tabel

function startPhotoEditor() {
    const image = document.getElementById('image-display');
    document.getElementById('editor-toolbar').classList.remove('hidden');
    document.getElementById('start-edit-btn').classList.add('hidden');
    
    cropper = new Cropper(image, {
        viewMode: 1,
        dragMode: 'move',
        autoCropArea: 1,
        restore: false,
        guides: true,
        center: true,
        highlight: false,
        cropBoxMovable: true,
        cropBoxResizable: true,
        toggleDragModeOnDblclick: true,
    });
}
// Logika Debounce: Menunggu user selesai mengetik (200ms) baru menjalankan filter
let filterTimeout;
document.getElementById('file-search-input').addEventListener('input', (e) => {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        filterFileList();
    }, 200); 
});
function cancelImageEdit() {
    if (cropper) {
        cropper.destroy();
        document.getElementById('editor-toolbar').classList.add('hidden');
        document.getElementById('start-edit-btn').classList.remove('hidden');
    }
}

async function applyImageEdit() {
    if (!cropper) return;
    
    // Ambil data hasil edit dalam format Base64
    const canvas = cropper.getCroppedCanvas({
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    });
    
    const dataURL = canvas.toDataURL('image/jpeg', 0.9);
    
    // Ubah Base64 ke Blob untuk dikirim ke server
    const response = await fetch(dataURL);
    const blob = await response.blob();
    const file = new File([blob], activeFile.split('/').pop(), { type: "image/jpeg" });

    // Gunakan fungsi upload yang sudah ada
    const f = new FormData();
    f.append('username', localStorage.getItem('ide_u_v4'));
    f.append('password', localStorage.getItem('ide_p_v4'));
    f.append('action', 'upload');
    f.append('path', activeFile.split('/').slice(0, -1).join('/')); // Folder asal
    f.append('files[]', file);

    showToast("Saving image...");
    
    fetch('?', { method: 'POST', body: f }).then(() => {
        showToast("Image updated successfully!");
        cancelImageEdit();
        // Refresh gambar agar perubahan terlihat
        const timestamp = new Date().getTime();
        document.getElementById('image-display').src = openFiles[activeFile].url + '?t=' + timestamp;
    });
}
    function changeTheme(themeName) {
    // 1. Update CodeMirror
    editor.setOption("theme", themeName);
    
    // 2. Simpan ke LocalStorage
    localStorage.setItem('ide_theme_name', themeName);
    
    // 3. Logika penyesuaian UI global (Opsional)
    // Jika tema yang dipilih adalah tema terang, kita bisa mematikan dark-mode class pada body
    const lightThemes = ['default', 'neo'];
    if (lightThemes.includes(themeName)) {
        document.body.classList.remove('dark-mode');
        updateThemeIcon(false);
    } else {
        document.body.classList.add('dark-mode');
        updateThemeIcon(true);
    }
    
    showToast(`Theme: ${themeName.toUpperCase()}`);
}

// Update bagian inisialisasi pada fungsi checkAuth atau init:
function applySavedTheme() {
    const savedTheme = localStorage.getItem('ide_theme_name') || 'dracula';
    document.getElementById('theme-selector').value = savedTheme;
    editor.setOption("theme", savedTheme);
    
    // Sesuaikan class body
    const lightThemes = ['default', 'neo'];
    if (lightThemes.includes(savedTheme)) {
        document.body.classList.remove('dark-mode');
    } else {
        document.body.classList.add('dark-mode');
    }
}

// Panggil applySavedTheme() di akhir fungsi init() Anda
    function toggleDarkMode() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('ide_dark_mode', isDark);
        updateThemeIcon(isDark);
        editor.setOption("theme", isDark ? "material-palenight" : "default");
    }

    function updateThemeIcon(isDark) {
        const icon = document.querySelector('#theme-toggle i');
        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    if (localStorage.getItem('ide_dark_mode') === 'true') {
        document.body.classList.add('dark-mode');
        updateThemeIcon(true);
    }

    let curPath="", openFiles={}, activeFile=null, allFiles=[];
    const editor = CodeMirror.fromTextArea(
  document.getElementById("code-editor"),
{
  /* Mode & Tema */
  mode: "htmlmixed",
  theme: "dracula",

  /* Tampilan Dasar */
  lineNumbers: true,
  lineWrapping: true,
  indentUnit: 4,
  tabSize: 4,
  indentWithTabs: false,

  /* UX Modern */
  styleActiveLine: true,
  scrollPastEnd: true,
  cursorBlinkRate: 530,
  smoothScroll: true,

  /* Code Folding */
  foldGutter: true,
  gutters: [
    "CodeMirror-linenumbers",
    "CodeMirror-foldgutter"
  ],

  /* Auto Assistance */
  autoCloseTags: true,
  autoCloseBrackets: true,
  matchTags: { bothTags: true },
  matchBrackets: true,

  /* Linting HTML / CSS / JS */
  lint: true,

  /* Highlight & Search */
  highlightSelectionMatches: {
    showToken: true,
    annotateScrollbar: true
  },

  /* Performa */
  viewportMargin: Infinity,

  /* Shortcut */
  extraKeys: {
    /* Shortcut custom milikmu (dipertahankan) */
    "Ctrl-S": saveFile,
    "Ctrl-F": toggleSearchPanel,
    "Esc": () => {
      document.getElementById('search-panel').style.display = 'none';
      closeModal();
      closePalette();
      // Close terminal if open
      document.getElementById('terminal-panel').classList.remove('active');
    },
    
    /* SHORTCUT FORMAT BARU */
    "Alt-Shift-F": formatCode,

    /* Search Modern */
    "Ctrl-H": "replace",
    "Ctrl-G": "findNext",
    "Shift-Ctrl-G": "findPrev",
    "Alt-G": "jumpToLine",

    /* Produktivitas */
    "Ctrl-Space": "autocomplete",
    "Ctrl-/": "toggleComment",
    "Alt-Up": "swapLineUp",
    "Alt-Down": "swapLineDown",
    "Ctrl-`": toggleTerminal // Toggle Terminal
  }
});


    // --- MODAL POPUP SYSTEM ---
    function openModal({title, msg, input=false, placeholder="", showSnippets=false, onConfirm}) {
        const container = document.getElementById('modal-container');
        const inputEl = document.getElementById('modal-input');
        const confirmBtn = document.getElementById('modal-btn-confirm');
        const snippetGrid = document.getElementById('snippet-grid');

        document.getElementById('modal-title').innerText = title;
        document.getElementById('modal-msg').innerHTML = msg;
        
        if(input) {
            inputEl.classList.remove('hidden');
            inputEl.value = "";
            inputEl.placeholder = placeholder;
            setTimeout(() => inputEl.focus(), 100);
        } else {
            inputEl.classList.add('hidden');
        }

        // Snippet Logic
        if(showSnippets) {
            snippetGrid.classList.remove('hidden');
            snippetGrid.classList.add('grid');
            document.getElementById('modal-msg').style.display = 'none';
            confirmBtn.style.display = 'none'; // Snippets auto-close on click
        } else {
            snippetGrid.classList.add('hidden');
            snippetGrid.classList.remove('grid');
            document.getElementById('modal-msg').style.display = 'block';
            confirmBtn.style.display = 'block';
        }

        confirmBtn.onclick = () => {
            const val = input ? inputEl.value : true;
            if(input && !val) return;
            onConfirm(val);
            closeModal();
        };

        container.classList.replace('hidden', 'flex');
    }

    function closeModal() {
        document.getElementById('modal-container').classList.replace('flex', 'hidden');
    }

    /* ================= COMMAND PALETTE ================= */
    const commands = [
      { name: "Save File", action: () => saveFile() },
      { name: "Format Code", action: () => formatCode() }, 
      { name: "Security Scan", action: () => runCodeScan() },
      { name: "Create File", action: () => promptCreateModal('file') },
      { name: "Create Folder", action: () => promptCreateModal('folder') },
      { name: "Toggle Sidebar", action: () => toggleSidebar() },
      { name: "Toggle Terminal", action: () => toggleTerminal() },
      { name: "Zen Mode", action: () => toggleZen() },
      { name: "Insert Snippet", action: () => openSnippetModal() },
      { name: "Reload Project", action: () => loadDir(curPath) },
      { name: "Close Active Tab", action: () => { if(activeFile) closeTab(activeFile); } }
    ];

    const palette = document.getElementById('command-palette');
    const cmdInput = document.getElementById('cmd-input');
    const cmdList = document.getElementById('cmd-list');
    let cmdIndex = 0;

    function openPalette() {
      palette.classList.remove('hidden');
      cmdInput.value = '';
      renderCommands(commands);
      cmdInput.focus();
    }
 async function clearAllHistory(path) {
    if (!confirm("Apakah Anda yakin ingin menghapus SEMUA snapshot history untuk file ini?")) return;
    
    const r = await api({ action: 'delete_all_history', path: path });
    if (r.status === 'success') {
        showToast("All history cleared");
        closeModal(); // Tutup modal history
    } else {
        showToast("Failed to clear history", "error");
    }
}
    function closePalette() { palette.classList.add('hidden'); }

    function renderCommands(list) {
      cmdList.innerHTML = '';
      list.forEach((c, i) => {
        const div = document.createElement('div');
        div.className = 'cmd-item' + (i === 0 ? ' active' : '');
        div.textContent = c.name;
        div.onclick = () => { closePalette(); c.action(); };
        cmdList.appendChild(div);
      });
      cmdIndex = 0;
    }

    cmdInput.addEventListener('input', () => {
      const q = cmdInput.value.toLowerCase();
      renderCommands(commands.filter(c => c.name.toLowerCase().includes(q)));
    });

    cmdInput.addEventListener('keydown', e => {
      const items = document.querySelectorAll('.cmd-item');
      if (e.key === 'ArrowDown') { e.preventDefault(); cmdIndex = (cmdIndex + 1) % items.length; }
      if (e.key === 'ArrowUp') { e.preventDefault(); cmdIndex = (cmdIndex - 1 + items.length) % items.length; }
      if (e.key === 'Enter') { e.preventDefault(); items[cmdIndex]?.click(); }
      items.forEach((el,i)=>el.classList.toggle('active',i===cmdIndex));
    });
 // --- SQL MANAGER WITH SCHEMA EXPLORER ---
    function toggleSQLModal(forceClose = false) {
        const m = document.getElementById('sql-modal');
        if(forceClose || m.classList.contains('flex')) { m.classList.replace('flex', 'hidden'); }
        else { 
            m.classList.replace('hidden', 'flex'); 
            // Pastikan kita load skema saat modal dibuka
            loadSQLSchema().then(() => {
                // Setelah skema diload, aktifkan tools tab juga
                loadSchemaTools();
                switchSQLTab('explorer'); // Default ke explorer
            });
            setTimeout(() => document.getElementById('sql-query-input').focus(), 100);
        }
    }
    
    function switchSQLTab(tab) {
        document.getElementById('sql-tab-explorer').classList.toggle('hidden', tab !== 'explorer');
        document.getElementById('sql-tab-tools').classList.toggle('hidden', tab !== 'tools');
        
        document.getElementById('btn-sql-tab-explorer').classList.toggle('border-orange-500', tab === 'explorer');
        document.getElementById('btn-sql-tab-explorer').classList.toggle('text-white/80', tab === 'explorer');
        document.getElementById('btn-sql-tab-explorer').classList.toggle('border-b-2', tab === 'explorer');
        document.getElementById('btn-sql-tab-explorer').classList.toggle('text-white/50', tab !== 'explorer');
        
        document.getElementById('btn-sql-tab-tools').classList.toggle('border-orange-500', tab === 'tools');
        document.getElementById('btn-sql-tab-tools').classList.toggle('text-white/80', tab === 'tools');
        document.getElementById('btn-sql-tab-tools').classList.toggle('border-b-2', tab === 'tools');
        document.getElementById('btn-sql-tab-tools').classList.toggle('text-white/50', tab !== 'tools');
    }

    async function loadSQLSchema() {
        const list = document.getElementById('sql-schema-list');
        list.innerHTML = '<div class="text-center mt-5 animate-pulse opacity-40 italic">Syncing tables...</div>';
        const r = await api({action: 'sql_schema'});
        if(r.status === 'success') {
            dbSchemaData = r.tables; // Simpan data skema
            
            // Render Explorer
            list.innerHTML = dbSchemaData.map(t => `
                <div class="group">
                    <div class="flex items-center gap-2 cursor-pointer text-white/90 hover:text-orange-400 font-bold transition-colors" 
                         onclick="insertAtCursor('sql-query-input', '${t.name} '); this.nextElementSibling.classList.toggle('hidden')">
                        <i class="fas fa-table opacity-30 text-[9px]"></i>
                        <span>${t.name}</span>
                    </div>
                    <div class="hidden ml-4 mt-2 border-l border-white/10 pl-3 space-y-1 opacity-50 font-mono text-white/70 text-[9px]">
                        ${t.columns.map(c => {
                            return `<div class="hover:text-white cursor-pointer" onclick="event.stopPropagation(); insertAtCursor('sql-query-input', '${c.Field}, ')">${c.Field} (${c.Type})</div>`;
                        }).join('')}
                    </div>
                </div>
            `).join('');
            
            // Muat data ke Tools (Select Options)
            loadSchemaTools();
            
        } else {
            list.innerHTML = '<div class="text-red-500 text-center mt-5">Koneksi DB Error.</div>';
        }
    }
    
    function loadSchemaTools() {
        const selectA = document.getElementById('select-table-a');
        const selectB = document.getElementById('select-table-b');
        selectA.innerHTML = '<option value="">-- Pilih Tabel A --</option>';
        selectB.innerHTML = '<option value="">-- Pilih Tabel B --</option>';

        dbSchemaData.forEach(t => {
            selectA.innerHTML += `<option value="${t.name}">${t.name}</option>`;
            selectB.innerHTML += `<option value="${t.name}">${t.name}</option>`;
        });
    }

    function generateSchemaDiagram() {
        const tableA = document.getElementById('select-table-a').value;
        const tableB = document.getElementById('select-table-b').value;
        const outputDiv = document.getElementById('schema-diagram-output');

        if (!tableA || !tableB) {
            outputDiv.innerHTML = `<div class="text-yellow-400"><i class="fas fa-exclamation-circle mr-1"></i> Harap pilih dua tabel.</div>`;
            return;
        }

        const dataA = dbSchemaData.find(t => t.name === tableA);
        const dataB = dbSchemaData.find(t => t.name === tableB);

        if (!dataA || !dataB) {
            outputDiv.innerHTML = `<div class="text-red-400">Data tabel tidak ditemukan.</div>`;
            return;
        }

        let diagramHtml = `
            <div class="flex flex-col md:flex-row items-start md:justify-around w-full gap-8 p-4">
                ${renderTableNode(dataA)}
                <div class="flex flex-col items-center justify-center text-white/50 self-center">
                    <i class="fas fa-arrow-right text-3xl mb-2 hidden md:block"></i>
                    <i class="fas fa-arrow-down text-3xl mb-2 md:hidden"></i>
                    <span class="text-[10px] uppercase font-mono">Possible Join</span>
                </div>
                ${renderTableNode(dataB)}
            </div>
        `;
        
        outputDiv.classList.remove('justify-center', 'items-center');
        outputDiv.innerHTML = diagramHtml;
    }
    
    function renderTableNode(tableData) {
        // Cek kolom yang umum ditemukan sebagai ID (PK/FK)
        const highlightCols = ['id', tableData.name.slice(0, -1) + '_id', 'user_id', 'nis', 'nisn'];
        
        let colsHtml = tableData.columns.map(col => {
            const isKey = highlightCols.includes(col.Field) || col.Field.toLowerCase().includes('id');
            const colorClass = isKey ? 'text-orange-400 font-bold' : 'text-white/80';
            
            return `
                <div class="schema-column flex justify-between">
                    <span class="${colorClass} truncate max-w-[100px]">${col.Field}</span>
                    <span class="text-white/50">${col.Type.replace(/\(.*\)/, '')}</span>
                </div>
            `;
        }).join('');

        return `
            <div class="schema-node flex-1 min-w-[200px] w-full">
                <div class="schema-header">${tableData.name}</div>
                <div class="p-0 space-y-0">
                    ${colsHtml}
                </div>
            </div>
        `;
    }

    async function runSQL() {
        const q = document.getElementById('sql-query-input').value;
        const resDiv = document.getElementById('sql-results');
        if(!q) return;
        resDiv.innerHTML = '<div class="text-center mt-10 animate-pulse text-orange-400 font-bold">Executing...</div>';
        const r = await api({action: 'sql_run', query: q});
        if(r.status === 'success') {
            if(r.data) {
                if(r.data.length === 0) { resDiv.innerHTML = '<div class="text-center mt-10 opacity-30 italic">Empty set</div>'; } 
                else {
                    const cols = Object.keys(r.data[0]);
                    let html = '<table class="w-full text-[10px] text-left border-collapse">';
                    html += '<thead><tr class="text-white border-b border-white/20 bg-white/5">';
                    cols.forEach(c => html += `<th class="p-3">${c}</th>`);
                    html += '</tr></thead><tbody class="divide-y divide-white/5">';
                    r.data.forEach(row => {
                        html += '<tr class="hover:bg-white/5 transition-colors">';
                        cols.forEach(c => html += `<td class="p-3 font-mono text-gray-400 truncate max-w-[200px]">${row[c]}</td>`);
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    resDiv.innerHTML = html;
                }
            } else { resDiv.innerHTML = `<div class="text-emerald-400 font-mono p-4">${r.msg}</div>`; }
        } else { resDiv.innerHTML = `<div class="text-red-400 font-mono p-4">${r.message}</div>`; }
    }

    function insertAtCursor(id, text) {
        const el = document.getElementById(id);
        const start = el.selectionStart;
        const end = el.selectionEnd;
        el.value = el.value.substring(0, start) + text + el.value.substring(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + text.length;
    }
    // --- API & AUTH ---
    
    async function api(d){
        const f=new FormData(); 
        f.append('username', localStorage.getItem('ide_u_v4') || '');
        f.append('password', localStorage.getItem('ide_p_v4') || '');
        for(let k in d) f.append(k,d[k]);
        try {
            const r = await fetch('?',{method:'POST',body:f});
            return await r.json();
        } catch(e) { showToast("Server Error", "error"); }
    }

    function checkAuth(){
        const u = localStorage.getItem('ide_u_v4'), p = localStorage.getItem('ide_p_v4');
        if(u && p) api({action:'login'}).then(r=>{if(r.status=='success')init(); else showLogin()}); else showLogin();
    }
    
    function showLogin() { document.getElementById('login-overlay').classList.remove('hidden'); }

    async function doLogin(e){
        e.preventDefault();
        localStorage.setItem('ide_u_v4', document.getElementById('login-user').value);
        localStorage.setItem('ide_p_v4', document.getElementById('login-pass').value);
        const r = await api({action:'login'});
        if(r.status=='success'){ document.getElementById('login-overlay').classList.add('hidden'); init(); }
        else { openModal({title: "Error", msg: "Invalid username or token!"}); localStorage.clear(); }
    }

    function logout(){ localStorage.clear(); location.reload(); }
    
    function init(){ 
        loadDir(); 
        updateSysInfo();
        loadScratchpad();
        setInterval(updateSysInfo, 30000);
        // Default Right Sidebar State Logic
        if(window.innerWidth < 1024) {
            document.getElementById('right-sidebar').classList.add('collapsed');
            document.getElementById('right-sidebar').classList.remove('expanded');
        }
    }

    async function updateSysInfo() {
        const r = await api({action: 'sys_info'});
        if(r && r.status == 'success') {
            document.getElementById('sys-info-content').innerHTML = `
                PHP ${r.info.php} | Disk: ${r.info.space}<br>
                OS: ${r.info.os} | Up: ${r.info.upload}
            `;
        }
    }

    // --- AUTO-SAVE & OUTLINE ---
    let saveTimeout, outlineTimer;
    editor.on("change", () => {
    if (!activeFile || openFiles[activeFile].type === 'image') return;
    
    // Simpan Draft ke Browser (Autosave Local)
    saveDraftToLocal(activeFile, editor.getValue());

    document.getElementById('save-status').innerText = "Typing...";
    document.getElementById('save-status').classList.replace('text-gray-500', 'text-yellow-500');
    
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => saveFile(true), 2000); 

    clearTimeout(outlineTimer);
    outlineTimer = setTimeout(renderOutline, 600);
});
// Simpan draft mentah ke localStorage
function saveDraftToLocal(path, content) {
    const draftKey = 'draft_' + btoa(path); // Gunakan Base64 agar path aman jadi key
    localStorage.setItem(draftKey, content);
}

// Hapus draft setelah file sukses tersimpan di server
function clearDraft(path) {
    localStorage.removeItem('draft_' + btoa(path));
}
    // --- FILE OPERATIONS ---
    async function loadDir(p=""){
        const r=await api({action:'scan',path:p});
        if(r.status=='success'){
            curPath=r.current_path; allFiles = r.data;
            document.getElementById('path-indicator').innerText = '/' + curPath;
            renderFileList(allFiles);
        }
    }

   function renderFileList(data) {
    const l = document.getElementById('file-list');
    
    // 1. Reset state navigasi keyboard setiap kali list dirender ulang
    focusedFileIndex = -1;
    l.innerHTML = '';
    
    // 2. Gunakan DocumentFragment untuk efisiensi render
    const fragment = document.createDocumentFragment();

    data.forEach((i, index) => {
        const d = document.createElement('div');
        
        // Tambahkan atribut data untuk memudahkan logika navigasi & identifikasi
        d.className = `file-item group ${activeFile === i.path ? 'active-file' : ''} flex items-center justify-between p-2 cursor-pointer text-[11px]`;
        d.setAttribute('data-path', i.path);
        d.setAttribute('data-type', i.type);
        d.setAttribute('data-index', index);
        
        const content = document.createElement('div');
        content.className = "flex items-center flex-1 truncate";
        
        const iconClass = i.type === 'folder' ? 'fa-folder text-yellow-500' : 'fa-file text-gray-400';
        content.innerHTML = `<i class="fas ${iconClass} mr-2 w-4 text-center"></i><span class="truncate">${i.name}</span>`;
        
        // Mouse Click Handler
        content.onclick = () => { 
            if (i.type === 'folder') loadDir(i.path); 
            else openFile(i.path); 
        };
        
        d.appendChild(content);

        // Action Buttons (Rename/Delete)
        if (!i.protected) {
            const actions = document.createElement('div');
            actions.className = "hidden group-hover:flex items-center gap-2 pr-1";
            actions.innerHTML = `
                <i class="fas fa-edit text-gray-500 hover:text-blue-400 p-1 transition-colors" 
                   onclick="event.stopPropagation(); promptRenameModal('${i.path}', '${i.name}')"></i>
                <i class="fas fa-trash text-gray-500 hover:text-red-500 p-1 transition-colors" 
                   onclick="event.stopPropagation(); confirmDeleteModal('${i.path}')"></i>
            `;
            d.appendChild(actions);
        }
        
        fragment.appendChild(d);
    });

    // 3. Tempelkan ke DOM sekaligus
    l.appendChild(fragment);
}
let focusedFileIndex = -1;

document.getElementById('file-search-input').addEventListener('keydown', (e) => {
    // Ambil elemen yang terlihat saja (berguna saat user melakukan filtering)
    const items = Array.from(document.querySelectorAll('#file-list .file-item'))
                       .filter(el => el.style.display !== 'none');

    if (items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        // PERBAIKAN: Jika belum ada fokus, mulai dari 0
        focusedFileIndex = (focusedFileIndex < 0) ? 0 : (focusedFileIndex + 1) % items.length;
        updateUIFocus(items);
    } 
    else if (e.key === 'ArrowUp') {
        e.preventDefault();
        // PERBAIKAN: Jika ditekan di posisi paling atas, pindah ke paling bawah
        focusedFileIndex = (focusedFileIndex <= 0) ? items.length - 1 : focusedFileIndex - 1;
        updateUIFocus(items);
    }
    else if (e.key === 'Enter') {
        e.preventDefault();
        if (focusedFileIndex > -1) {
            const selected = items[focusedFileIndex];
            const path = selected.getAttribute('data-path');
            const type = selected.getAttribute('data-type');
            
            if (type === 'folder') loadDir(path);
            else openFile(path);
        }
    }
});

// Fungsi pembantu untuk mengupdate tampilan fokus keyboard
function updateUIFocus(items) {
    items.forEach((item, idx) => {
        if (idx === focusedFileIndex) {
            item.classList.add('kb-focus'); // Tambahkan CSS class .kb-focus di style Anda
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            item.classList.remove('kb-focus');
        }
    });
}
    function promptCreateModal(type) {
        openModal({
            title: `Create New ${type}`,
            msg: `Enter a name for the new ${type}:`,
            input: true,
            placeholder: `my-${type}-name`,
            onConfirm: (val) => {
                api({action:'create', type, name: val, path: curPath}).then(r => {
                    if(r.status=='success') { showToast(`${type} created`); loadDir(curPath); }
                    else showToast(r.message, "error");
                });
            }
        });
    }

    function promptRenameModal(path, oldName) {
        openModal({
            title: "Rename Item",
            msg: `Enter new name for "${oldName}":`,
            input: true,
            placeholder: oldName,
            onConfirm: (val) => {
                api({action:'rename', path: path, new_name: val}).then(r => {
                    if(r.status=='success') { showToast("Renamed successfully"); loadDir(curPath); }
                });
            }
        });
    }

    function confirmDeleteModal(path) {
        openModal({
            title: "Delete Permanent",
            msg: `Are you sure you want to delete this? This action cannot be undone.`,
            onConfirm: () => {
                api({action:'delete', path: path}).then(r => {
                    if(r.status=='success') { showToast("Deleted successfully"); loadDir(curPath); }
                });
            }
        });
    }

    function filterFileList() {
    const q = document.getElementById('file-search-input').value.toLowerCase();
    const items = document.querySelectorAll('#file-list .file-item');

    // Menggunakan requestAnimationFrame untuk memastikan sinkronisasi dengan refresh rate monitor
    window.requestAnimationFrame(() => {
        items.forEach(item => {
            // Ambil nama file dari span di dalam item
            const name = item.querySelector('span').textContent.toLowerCase();
            
            // Selalu tampilkan ".." (tombol back) agar user tidak terjebak
            const isBackNavigation = name === "..";
            const isMatch = name.includes(q);

            if (isMatch || isBackNavigation) {
                item.style.display = 'flex';
                // Opsional: berikan highlight pada teks yang cocok
            } else {
                item.style.display = 'none';
            }
        });
    });
}

   async function openFile(p){
    if(openFiles[p]) return switchTab(p);
    const ext = p.split('.').pop().toLowerCase();
    
    // Daftar Ekstensi
    const imgExt = ['jpg','jpeg','png','gif','svg','webp'];
    const vidExt = ['mp4', 'webm', 'ogg'];
    const audExt = ['mp3', 'wav', 'ogg'];

    const url = window.location.href.split('?')[0].split('/').slice(0,-1).join('/') + '/' + p;

    if(imgExt.includes(ext)) {
        openFiles[p] = { type: 'image', url: url, tab: createTab(p) }; 
        switchTab(p);
    } 
    else if(vidExt.includes(ext) || audExt.includes(ext)) {
        openFiles[p] = { type: 'media', mediaType: vidExt.includes(ext) ? 'video' : 'audio', url: url, tab: createTab(p) };
        switchTab(p);
    }
    else {
        const r = await api({action:'open',path:p});
        if(r.status=='success'){
    let content = r.content;
    const draft = localStorage.getItem('draft_' + btoa(p));
    
    // Jika ada draft di browser yang berbeda dengan isi server, tawarkan pemulihan
    if (draft && draft !== content) {
        if (confirm("Ditemukan perubahan yang belum tersimpan di browser untuk file ini. Pulihkan?")) {
            content = draft;
        }
    }
    
    openFiles[p] = { type: 'text', doc: CodeMirror.Doc(content, 'htmlmixed'), tab: createTab(p) }; 
    switchTab(p);
        } else openModal({title:"Error", msg: r.message});
    }
}
    function createTab(p) {
        const t = document.createElement('div');
        t.className = 'tab';
        t.innerHTML = `
          <span class="truncate max-w-[90px]">${p.split('/').pop()}</span>
          <i class="fas fa-clock text-gray-400 hover:text-purple-400 ml-1" title="History" onclick="openHistory('${p}', event)"></i>
          <i class="fas fa-times ml-2 hover:text-red-500" onclick="closeTab('${p}',event)"></i>
        `;
        t.onclick = () => switchTab(p);
        document.getElementById('tab-container').appendChild(t);
        return t;
    }
function switchTab(p) {
    if (!p || !openFiles[p]) return;
    
    activeFile = p; 
    Object.keys(openFiles).forEach(k => openFiles[k].tab.classList.toggle('active', k === p));
    
    const v = document.getElementById('image-viewer');
    const mv = document.getElementById('media-viewer');
    const cm = document.querySelector('.CodeMirror');
    const vDisp = document.getElementById('video-display');
    const aDisp = document.getElementById('audio-display');
    
    // Reset Viewers
    [v, mv, cm].forEach(el => el.style.display = 'none');
    [vDisp, aDisp].forEach(el => { el.classList.add('hidden'); el.pause(); });
    
    if (cropper) { cropper.destroy(); cropper = null; }
    document.getElementById('editor-toolbar').classList.add('hidden');
    document.getElementById('start-edit-btn').classList.remove('hidden');

    if (openFiles[p].type === 'image') {
        v.style.display = 'flex';
        document.getElementById('image-display').src = openFiles[p].url;
    } 
    else if (openFiles[p].type === 'media') {
        mv.style.display = 'flex';
        if (openFiles[p].mediaType === 'video') {
            vDisp.classList.remove('hidden');
            vDisp.src = openFiles[p].url;
        } else {
            aDisp.classList.remove('hidden');
            aDisp.src = openFiles[p].url;
        }
    }
    else {
        cm.style.display = 'block';
        editor.swapDoc(openFiles[p].doc);
        requestAnimationFrame(() => {
            editor.refresh();
            editor.focus();
        });
        renderOutline();
        document.getElementById('path-indicator').innerText = '/' + p;
    }
    
    renderFileList(allFiles);

    // PERSISTENCE: Simpan status ke browser
    localStorage.setItem('ide_active_file', p);
    saveSession(); 
}
// Fungsi untuk menyimpan daftar path file yang sedang terbuka
function saveSession() {
    const sessionPaths = Object.keys(openFiles);
    localStorage.setItem('ide_open_tabs', JSON.stringify(sessionPaths));
}

// Fungsi untuk memulihkan sesi saat IDE pertama kali dimuat
async function restoreSession() {
    const savedTabs = localStorage.getItem('ide_open_tabs');
    const lastActive = localStorage.getItem('ide_active_file');

    if (savedTabs) {
        const paths = JSON.parse(savedTabs);
        
        // Gunakan Promise.all agar loading file lebih cepat (paralel)
        const openPromises = paths.map(path => openFile(path));
        await Promise.all(openPromises);

        // Setelah semua terbuka, kembalikan ke tab yang terakhir aktif
        if (lastActive && openFiles[lastActive]) {
            switchTab(lastActive);
        }
    }
}
function init() { 
    loadDir(); 
    updateSysInfo();
    loadScratchpad();
    
    // TAMBAHKAN INI: Pulihkan sesi tab yang lama
    restoreSession(); 
    
    setInterval(updateSysInfo, 30000);
    if(window.innerWidth < 1024) {
        document.getElementById('right-sidebar').classList.add('collapsed');
    }
}
// Tambahkan penghenti suara saat tab ditutup
function closeTab(p, e){
    if(e) e.stopPropagation();
    if(openFiles[p].type === 'media') {
        document.getElementById('video-display').pause();
        document.getElementById('audio-display').pause();
    }
    openFiles[p].tab.remove(); 
    delete openFiles[p];
    // ... sisa kode closeTab Anda yang lama ...
    if(activeFile == p) { 
        activeFile = null; 
        editor.setValue(''); 
        document.getElementById('image-viewer').style.display = 'none'; 
        document.getElementById('media-viewer').style.display = 'none'; // Sembunyikan media viewer
        document.getElementById('save-status').innerText = "Ready";
        document.getElementById('outline-panel').innerHTML = '<div class="opacity-40 italic text-center mt-10">No active file</div>';
    }
}

    function closeTab(p, e){
        if(e) e.stopPropagation();
        openFiles[p].tab.remove(); delete openFiles[p];
        if(activeFile == p) { 
            activeFile = null; 
            editor.setValue(''); 
            document.getElementById('image-viewer').style.display = 'none'; 
            document.getElementById('save-status').innerText = "Ready";
            document.getElementById('outline-panel').innerHTML = '<div class="opacity-40 italic text-center mt-10">No active file</div>';
        }
    }

    async function openHistory(path, e) {
    if(e) e.stopPropagation();
    const r = await api({ action:'history', path });
    if(r.status !== 'success') return;

    // Header Tambahan: Tombol Hapus Semua
    let headerHtml = '';
    if (r.data.length > 0) {
        headerHtml = `
            <div class="flex justify-end mb-4">
                <button onclick="clearAllHistory('${path}')" class="text-[10px] bg-red-500/20 text-red-400 hover:bg-red-500 hover:text-white px-3 py-1 rounded-lg transition-all font-bold uppercase tracking-wider">
                    <i class="fas fa-trash-alt mr-1"></i> Clear All History
                </button>
            </div>
        `;
    }

    let listHtml = r.data.length ? r.data.map(f => `
      <div class="flex justify-between items-center py-2 text-xs border-b border-white/5">
        <span class="font-mono text-gray-300">${f.replace('.bak','')}</span>
        <div class="flex gap-2">
          <button onclick="showDiff('${path}','${f}')" class="text-blue-400 hover:text-white font-bold">Diff</button>
          <button onclick="restoreSnapshot('${path}','${f}')" class="text-purple-400 hover:text-white font-bold">Restore</button>
        </div>
      </div>`).join('') : `<div class="text-center text-gray-500 text-xs py-10">No snapshots found`;

    openModal({ 
        title: "Version History", 
        msg: headerHtml + listHtml, 
        onConfirm: () => {} 
    });
}
    async function showDiff(path, snapshot) {
        const r = await api({ action:'diff', path, snapshot });
        if (r.status !== 'success') return;
        const diffs = diffLines(r.old, r.new);
        let left = '', right = '';
        diffs.forEach(d => {
            if (d.type === 'same') {
                left  += `<div class="diff-line">${escapeHtml(d.text)}</div>`;
                right += `<div class="diff-line">${escapeHtml(d.text)}</div>`;
            } else if (d.type === 'removed') {
                left  += `<div class="diff-line bg-red-500/20 text-red-200">-${escapeHtml(d.text)}</div>`;
            } else if (d.type === 'added') {
                right += `<div class="diff-line bg-green-500/20 text-green-200">+${escapeHtml(d.text)}</div>`;
            }
        });
        const html = `
          <div class="grid grid-cols-2 gap-2 text-[10px] font-mono max-h-[60vh] overflow-auto text-left">
            <div class="border border-white/10 rounded p-2 bg-black/30">
              <div class="text-red-400 font-bold mb-1 uppercase tracking-tighter">Snapshot</div>${left}
            </div>
            <div class="border border-white/10 rounded p-2 bg-black/30">
              <div class="text-green-400 font-bold mb-1 uppercase tracking-tighter">Current</div>${right}
            </div>
          </div>`;
        openModal({ title: "Visual Diff View", msg: html, onConfirm: ()=>{} });
    }

    function diffLines(oldStr, newStr) {
        const oldLines = oldStr.split('\n'), newLines = newStr.split('\n');
        const max = Math.max(oldLines.length, newLines.length), diff = [];
        for (let i = 0; i < max; i++) {
            if (oldLines[i] === newLines[i]) diff.push({ type: 'same', text: oldLines[i] || '' });
            else {
                if (oldLines[i] !== undefined) diff.push({ type: 'removed', text: oldLines[i] });
                if (newLines[i] !== undefined) diff.push({ type: 'added', text: newLines[i] });
            }
        }
        return diff;
    }

    async function restoreSnapshot(path, snap) {
        const r = await api({ action:'restore', path, snapshot: snap });
        if(r.status === 'success') { showToast("Version restored"); closeModal(); openFile(path); }
    }

    // --- SEARCH ---
    function toggleSearchPanel() {
        const p = document.getElementById('search-panel');
        const isHidden = p.style.display !== 'block';
        p.style.display = isHidden ? 'block' : 'none';
        if(isHidden) {
            const sel = editor.getSelection();
            if(sel) document.getElementById('find-input').value = sel;
            document.getElementById('find-input').focus();
        }
    }

    function findNext() {
        const q = document.getElementById('find-input').value;
        if(!q) return;
        let cursor = editor.getSearchCursor(q);
        if(!cursor.findNext()) {
            cursor = editor.getSearchCursor(q, {line:0, ch:0});
            if(!cursor.findNext()) return showToast("No matches", "error");
        }
        editor.setSelection(cursor.from(), cursor.to());
        editor.scrollIntoView({from: cursor.from(), to: cursor.to()}, 150);
    }

    function replaceAll() {
        const q = document.getElementById('find-input').value, r = document.getElementById('replace-input').value;
        if(!q) return;
        let cursor = editor.getSearchCursor(q);
        while(cursor.findNext()) cursor.replace(r);
        showToast("Replaced all");
    }

    // --- PREVIEW ---
    function togglePreview() {
        const c = document.getElementById('preview-container'), iframe = document.getElementById('preview-frame');
        if (c.classList.contains('translate-y-0')) {
            c.classList.replace('translate-y-0', 'translate-y-full');
            setTimeout(() => { iframe.src = 'about:blank'; }, 300);
        } else {
            if (!activeFile) return showToast("Open a file first", "error");
            iframe.src = window.location.href.split('?')[0].split('/').slice(0,-1).join('/') + '/' + activeFile;
            c.classList.replace('translate-y-full', 'translate-y-0');
        }
    }
 function setPreviewSize(width) { document.getElementById('preview-frame').style.width = width; }
    // --- OUTLINE & SCRATCHPAD ---
    function switchRightTab(tab) {
        const outline = document.getElementById('outline-panel');
        const notes = document.getElementById('notes-panel');
        const btnO = document.getElementById('btn-tab-outline');
        const btnN = document.getElementById('btn-tab-notes');

        if(tab === 'outline') {
            outline.classList.remove('hidden'); notes.classList.add('hidden'); notes.classList.remove('flex');
            btnO.classList.replace('opacity-40', 'opacity-100'); btnO.classList.add('border-b-2', 'border-purple-500');
            btnN.classList.replace('opacity-100', 'opacity-40'); btnN.classList.remove('border-b-2', 'border-purple-500');
        } else {
            outline.classList.add('hidden'); notes.classList.remove('hidden'); notes.classList.add('flex');
            btnN.classList.replace('opacity-40', 'opacity-100'); btnN.classList.add('border-b-2', 'border-purple-500');
            btnO.classList.replace('opacity-100', 'opacity-40'); btnO.classList.remove('border-b-2', 'border-purple-500');
        }
    }

    function loadScratchpad() {
        const pad = document.getElementById('scratchpad');
        pad.value = localStorage.getItem('ide_scratchpad') || '';
        pad.addEventListener('input', () => {
            localStorage.setItem('ide_scratchpad', pad.value);
        });
    }

    function renderOutline() {
        const panel = document.getElementById('outline-panel');
        if (!activeFile || openFiles[activeFile]?.type !== 'text') {
            panel.innerHTML = '<div class="opacity-40 italic text-center mt-10">No active file</div>';
            return;
        }
        const code = editor.getValue(), lines = code.split('\n'), outline = [];
        lines.forEach((line, i) => {
            let m;
            if (m = line.match(/class\s+(\w+)/)) outline.push({ type: 'class', name: m[1], line: i });
            else if (m = line.match(/function\s+(\w+)\s*\(/)) outline.push({ type: 'function', name: m[1], line: i });
            else if (m = line.match(/<(header|main|section|footer|nav|article|div|h1|h2|h3)\s+id=["']([^"']+)["']/i)) outline.push({ type: 'id', name: `#${m[2]} (${m[1]})`, line: i });
        });
        panel.innerHTML = outline.length ? outline.map(o => `
            <div onclick="goToLine(${o.line})" class="cursor-pointer px-3 py-2 rounded-lg hover:bg-purple-500/10 border border-transparent hover:border-purple-500/20 transition-all mb-1">
              <div class="flex items-center gap-2">
                 <span class="opacity-50 font-mono">${{class:'📦', function:'ƒ', id:'#'}[o.type] || '•'}</span>
                 <span class="font-medium truncate">${o.name}</span>
              </div>
            </div>`).join('') : '<div class="opacity-40 italic text-center mt-4">No structure found</div>';
    }

    function goToLine(line) {
        editor.focus(); editor.setCursor({ line, ch: 0 });
        editor.scrollIntoView({ line, ch: 0 }, 120);
        if(window.innerWidth < 1024) toggleRightSidebar(); // Close on mobile after click
    }

    // --- TERMINAL LOGIC ---
    function toggleTerminal() {
        const term = document.getElementById('terminal-panel');
        term.classList.toggle('active');
        if(term.classList.contains('active')) document.getElementById('terminal-input').focus();
    }

    async function handleTerminal(e) {
        if(e.key === 'Enter') {
            const cmd = e.target.value;
            if(!cmd) return;
            const outputDiv = document.getElementById('terminal-output');
            outputDiv.innerHTML += `<div class="mb-1"><span class="text-green-500">$</span> ${cmd}</div>`;
            e.target.value = '';
            
            if(cmd === 'clear') { outputDiv.innerHTML = ''; return; }
            
            outputDiv.innerHTML += `<div class="text-yellow-400 opacity-50 mb-1">Processing...</div>`;
            outputDiv.scrollTop = outputDiv.scrollHeight;

            const r = await api({action:'terminal', cmd, path: curPath});
            outputDiv.lastChild.remove(); // remove processing msg
            
            if(r.status === 'success') {
                outputDiv.innerHTML += `<div class="mb-3 text-white/80 whitespace-pre-wrap">${escapeHtml(r.output)}</div>`;
            } else {
                outputDiv.innerHTML += `<div class="mb-3 text-red-400">Error executing command</div>`;
            }
            outputDiv.scrollTop = outputDiv.scrollHeight;
        }
    }

    // --- SNIPPETS LOGIC ---
    function openSnippetModal() {
        openModal({
            title: "Insert Snippet",
            msg: "",
            showSnippets: true,
            onConfirm: () => {}
        });
    }

    function insertSnippet(type) {
    const snippets = {
        'html5': `<!DOCTYPE html>\n<html lang="en">\n<head>\n    <meta charset="UTF-8">\n    <meta name="viewport" content="width=device-width, initial-scale=1.0">\n    <title>Document</title>\n    <script src="https://cdn.tailwindcss.com"><\/script>\n</head>\n<body>\n\n</body>\n</html>`,
        'php_start': `<?php\n\n?>`,
        'tailwind_card': `<div class="bg-white/10 backdrop-blur-lg border border-white/20 p-6 rounded-2xl shadow-xl">\n    <h2 class="text-xl font-bold mb-2">Title</h2>\n    <p class="opacity-80">Content goes here...</p>\n</div>`,
        'php_conn': `$conn = new mysqli("localhost", "root", "", "database");\nif ($conn->connect_error) die("Connection failed: " . $conn->connect_error);`,
        'html_form': `<form action="#" method="POST" class="space-y-4">\n    <div>\n        <label for="name" class="block text-sm font-medium text-gray-700">Name</label>\n        <input type="text" id="name" name="name" class="mt-1 p-2 border rounded-lg w-full">\n    </div>\n    <div>\n        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>\n        <input type="email" id="email" name="email" class="mt-1 p-2 border rounded-lg w-full">\n    </div>\n    <div>\n        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded-lg hover:bg-blue-600">Submit</button>\n    </div>\n</form>`,
        'tailwind_grid': `<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">\n    <div class="bg-gray-200 p-4 rounded-xl">Item 1</div>\n    <div class="bg-gray-200 p-4 rounded-xl">Item 2</div>\n    <div class="bg-gray-200 p-4 rounded-xl">Item 3</div>\n    <div class="bg-gray-200 p-4 rounded-xl">Item 4</div>\n</div>`,
        'tailwind_flex': `<div class="flex space-x-4">\n    <div class="flex-1 bg-blue-500 p-4 text-white rounded-lg">Item 1</div>\n    <div class="flex-1 bg-green-500 p-4 text-white rounded-lg">Item 2</div>\n    <div class="flex-1 bg-red-500 p-4 text-white rounded-lg">Item 3</div>\n</div>`,
        'tailwind_center': `<div class="flex items-center justify-center min-h-screen bg-gray-100">\n    <div class="bg-white p-8 rounded-xl shadow-lg">\n        <h2 class="text-xl font-bold text-center">Centered Content</h2>\n        <p class="mt-4 text-center">This content is centered both vertically and horizontally.</p>\n    </div>\n</div>`,
        'tailwind_columns': `<div class="columns-2 sm:columns-3 md:columns-4 lg:columns-5 gap-4">\n    <p class="mb-4">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus lacinia odio vitae vestibulum.</p>\n    <p class="mb-4">Curabitur quis facilisis turpis. Proin ac orci at dolor facilisis lobortis a ut elit.</p>\n    <p class="mb-4">Aliquam erat volutpat. Suspendisse ut urna fringilla, feugiat felis non, dapibus neque.</p>\n    <p class="mb-4">Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae.</p>\n    <p class="mb-4">Duis gravida sem a risus malesuada, at vulputate justo dapibus. Sed consequat dui felis.</p>\n</div>`
   

        };
        if(snippets[type]) {
            editor.replaceSelection(snippets[type]);
            formatCode();
            showToast("Snippet Inserted");
            closeModal();
            editor.focus();
        }
    }

    // --- CODE SCANNER LOGIC ---
    async function runCodeScan() {
        if (!activeFile || openFiles[activeFile]?.type !== 'text') {
            return showToast("Please open a text file (PHP recommended) first.", "error");
        }
        
        showToast("Starting security scan...", "success");
        
        const r = await api({action: 'code_scan', path: activeFile});

        if (r.status === 'error') {
            return openModal({ title: "Scan Error", msg: `<div class="text-red-400 font-bold">${r.message}</div>`, onConfirm: ()=>{} });
        }

        renderScanResults(r.filename, r.findings);
    }
    
    function renderScanResults(filename, findings) {
        let severityClasses = {
            'Critical': 'bg-red-500/20 text-red-400',
            'Medium': 'bg-yellow-500/20 text-yellow-400',
            'Low': 'bg-blue-500/20 text-blue-400'
        };

        let totalCritical = findings.filter(f => f.severity === 'Critical').length;
        let totalMedium = findings.filter(f => f.severity === 'Medium').length;
        let totalFindings = findings.length;

        let icon = (totalCritical > 0 || totalMedium > 0) ? '<i class="fas fa-exclamation-triangle mr-2"></i>' : '<i class="fas fa-check-circle mr-2"></i>';
        let titleClass = (totalCritical > 0) ? 'text-red-400' : (totalMedium > 0 ? 'text-yellow-400' : 'text-emerald-400');
        
        let htmlContent = `
            <div class="max-h-[70vh] overflow-y-auto">
            <h4 class="${titleClass} font-black text-lg mb-3 tracking-wide">
                ${icon} Analisis Keamanan: ${filename}
            </h4>
            <div class="grid grid-cols-3 gap-4 mb-6 text-white/80 text-center">
                <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div class="text-2xl font-bold ${totalCritical > 0 ? 'text-red-500' : 'text-white/60'}">${totalCritical}</div>
                    <div class="text-[10px] uppercase opacity-70">CRITICAL</div>
                </div>
                <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div class="text-2xl font-bold ${totalMedium > 0 ? 'text-yellow-500' : 'text-white/60'}">${totalMedium}</div>
                    <div class="text-[10px] uppercase opacity-70">MEDIUM</div>
                </div>
                <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                    <div class="text-2xl font-bold">${totalFindings}</div>
                    <div class="text-[10px] uppercase opacity-70">TOTAL ISSUES</div>
                </div>
            </div>`;

        if (totalFindings === 0) {
            htmlContent += '<div class="text-center py-10 text-emerald-400 opacity-90 text-sm font-bold">Kode terlihat aman! Lanjutkan kerja.</div>';
        } else {
            htmlContent += findings.map(f => `
                <div class="glass p-4 mb-4 rounded-xl border border-white/10 hover:shadow-xl transition-shadow">
                    <div class="flex justify-between items-start mb-2">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase ${severityClasses[f.severity]}">${f.severity}</span>
                        <span class="text-[10px] opacity-60">LINE: ${f.line}</span>
                    </div>
                    <p class="text-sm font-medium text-white mb-2">${f.message}</p>
                    <div class="bg-black/40 p-2 rounded-lg text-[11px] font-mono whitespace-pre-wrap">
                        ${f.code}
                    </div>
                    <p class="text-[11px] mt-2 italic text-purple-300">
                        Saran: ${f.suggestion}
                    </p>
                    <button onclick="goToLine(${f.line - 1})" class="mt-3 text-[10px] text-blue-400 hover:text-white font-bold transition-colors">
                        <i class="fas fa-search-location mr-1"></i> Lihat di Editor
                    </button>
                </div>
            `).join('');
        }

        htmlContent += '</div>';

        openModal({
            title: "Code Security Scan Report",
            msg: htmlContent,
            onConfirm: closeModal
        });
    }

    // --- ZEN MODE ---
    function toggleZen() {
        document.body.classList.toggle('zen-active');
        editor.refresh(); // Resize codemirror
        if(document.body.classList.contains('zen-active')) {
            showToast("Zen Mode Active (Press Esc to exit)", "success");
        }
    }

    // --- UTILS ---
    async function saveFile(silent = false){
    if(!activeFile || openFiles[activeFile].type === 'image') return;
    const r = await api({action:'save', path:activeFile, content:editor.getValue()});
    if(r.status === 'success') {
        clearDraft(activeFile); // <--- TAMBAHKAN INI: Draft dihapus karena sudah aman di server
        
        if(!silent) showToast("Workspace Synced");
        document.getElementById('save-status').innerText = "All changes saved";
        document.getElementById('save-status').classList.replace('text-yellow-500', 'text-gray-500');
    }
}

    function showToast(msg, type="success") {
        const t = document.getElementById('toast'), m = document.getElementById('toast-msg');
        const icon = document.getElementById('toast-icon');

        m.innerText = msg;
        if(type === "error") {
            t.style.background = "#ef4444";
            icon.innerHTML = '✕';
            icon.style.background = "#b91c1c";
        } else {
            t.style.background = "#10b981";
            icon.innerHTML = '✓';
            icon.style.background = "#059669";
        }

        t.classList.replace('opacity-0', 'opacity-100');
        t.classList.replace('translate-y-4', 'translate-y-0');
        setTimeout(() => {
            t.classList.replace('opacity-100', 'opacity-0');
            t.classList.replace('translate-y-0', 'translate-y-4');
        }, 2500);
    }

    function toggleSidebar() { document.getElementById('main-sidebar').classList.toggle('collapsed'); }
    
    function toggleRightSidebar() { 
        const rs = document.getElementById('right-sidebar');
        if(rs.classList.contains('collapsed')) {
            rs.classList.remove('collapsed');
            rs.classList.add('expanded');
        } else {
            rs.classList.remove('expanded');
            rs.classList.add('collapsed');
        }
    }

    function toggleIframeModal() {
        const m = document.getElementById('iframe-modal');
        if(m.classList.contains('hidden')) { m.classList.replace('hidden', 'flex'); }
        else { m.classList.replace('flex', 'hidden'); }
    }
    function escapeHtml(str) { return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }
    
    async function handleUpload(input) {
        if (!input.files.length) return;
        const f = new FormData();
        f.append('username', localStorage.getItem('ide_u_v4'));
        f.append('password', localStorage.getItem('ide_p_v4'));
        f.append('action', 'upload'); f.append('path', curPath);
        for(let file of input.files) f.append('files[]', file);
        fetch('?',{method:'POST',body:f}).then(() => { showToast("Assets uploaded"); loadDir(curPath); });
    }

    // --- FORMAT CODE LOGIC ---
    async function formatCode() {
        if (!activeFile) return;
        
        // Indikator loading
        document.getElementById('save-status').innerText = "Formatting...";
        
        const ext = activeFile.split('.').pop().toLowerCase();
        const content = editor.getValue();
        let parser = 'html';
        let plugins = prettierPlugins; // Global dari CDN

        // Tentukan parser berdasarkan ekstensi
        if (['js', 'json'].includes(ext)) parser = 'babel';
        else if (['css', 'scss'].includes(ext)) parser = 'css';
        else if (['php'].includes(ext)) parser = 'php'; // Plugin PHP Loaded via CDN
        
        try {
            // Format menggunakan Prettier Standalone
            const formatted = prettier.format(content, {
                parser: parser,
                plugins: plugins,
                tabWidth: 4,
                printWidth: 120,
                singleQuote: true,
                trailingComma: 'none',
                arrowParens: 'avoid'
            });

            // Simpan posisi kursor agar tidak loncat ke atas
            const cursor = editor.getCursor();
            
            // Update editor
            editor.setValue(formatted);
            editor.setCursor(cursor);
            
            showToast("Code Formatted ✨");
        } catch (err) {
            showToast("Format Error (Check Syntax)", "error");
            console.error(err);
        } finally {
            document.getElementById('save-status').innerText = "Unsaved Changes";
        }
    }
let ctxTarget = null; // Menyimpan path file yang sedang diklik kanan

// 1. Tangkap event Klik Kanan di File List
document.getElementById('file-list').addEventListener('contextmenu', e => {
    const item = e.target.closest('.file-item');
    if (!item) return;

    e.preventDefault();
    ctxTarget = {
        path: item.getAttribute('data-path'),
        type: item.getAttribute('data-type'),
        name: item.querySelector('span').textContent
    };

    const menu = document.getElementById('context-menu');
    menu.classList.remove('hidden');
    
    // Posisikan menu agar tidak keluar layar
    let x = e.clientX;
    let y = e.clientY;
    const winW = window.innerWidth;
    const winH = window.innerHeight;
    
    if (x + 200 > winW) x -= 200;
    if (y + 150 > winH) y -= 150;

    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
});

// 2. Tutup menu saat klik di mana saja
document.addEventListener('click', () => {
    document.getElementById('context-menu').classList.add('hidden');
});

// 3. Handler Aksi Menu
function ctxAction(action) {
    if (!ctxTarget) return;

    switch(action) {
        case 'open':
            if (ctxTarget.type === 'folder') loadDir(ctxTarget.path);
            else openFile(ctxTarget.path);
            break;
        case 'rename':
            promptRenameModal(ctxTarget.path, ctxTarget.name);
            break;
        case 'delete':
            confirmDeleteModal(ctxTarget.path);
            break;
    }
}
    window.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 'q') { e.preventDefault(); if(activeFile) closeTab(activeFile); }
        if (e.ctrlKey && e.key === 'b') { e.preventDefault(); toggleSidebar(); }
        if (e.ctrlKey && e.shiftKey && e.key.toUpperCase() === 'P') { e.preventDefault(); openPalette(); }
        if (e.ctrlKey && e.key === 'p') { 
    e.preventDefault(); 
    openQuickSearch(); 
}
        if (e.key === 'Escape' && document.body.classList.contains('zen-active')) { toggleZen(); }
    });

    checkAuth();
    let quickSearchIndex = 0;

function openQuickSearch() {
    const modal = document.getElementById('quick-search');
    const input = document.getElementById('quick-search-input');
    modal.classList.remove('hidden');
    input.value = '';
    renderQuickSearchFiles(""); // Tampilkan semua file di awal
    input.focus();
}

function closeQuickSearch() {
    document.getElementById('quick-search').classList.add('hidden');
}

function renderQuickSearchFiles(query) {
    const list = document.getElementById('quick-search-list');
    list.innerHTML = '';
    
    // Filter file dari variabel allFiles yang tipenya bukan folder
    // Catatan: Jika project besar, Anda mungkin perlu memanggil API scan rekursif ke server
    const filtered = allFiles.filter(f => 
        f.type === 'file' && f.name.toLowerCase().includes(query.toLowerCase())
    );

    if (filtered.length === 0) {
        list.innerHTML = '<div class="p-4 text-center opacity-40 text-xs">File tidak ditemukan...</div>';
        return;
    }

    filtered.forEach((file, i) => {
        const div = document.createElement('div');
        div.className = `p-3 rounded-xl flex items-center gap-3 cursor-pointer transition-all text-xs ${i === 0 ? 'bg-purple-500/20 border border-purple-500/30' : 'hover:bg-white/5'}`;
        div.innerHTML = `
            <i class="fas fa-file opacity-40"></i>
            <div class="flex flex-col">
                <span class="font-bold">${file.name}</span>
                <span class="text-[10px] opacity-40">${file.path}</span>
            </div>
        `;
        div.onclick = () => {
            openFile(file.path);
            closeQuickSearch();
        };
        list.appendChild(div);
    });
    quickSearchIndex = 0;
}

// Event Listeners untuk Input
document.getElementById('quick-search-input').addEventListener('input', (e) => {
    renderQuickSearchFiles(e.target.value);
});

document.getElementById('quick-search-input').addEventListener('keydown', (e) => {
    const items = document.querySelectorAll('#quick-search-list > div');
    if (e.key === 'Escape') {
        e.preventDefault();
        closeQuickSearch();
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        quickSearchIndex = (quickSearchIndex + 1) % items.length;
        updateQuickSearchFocus(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        quickSearchIndex = (quickSearchIndex - 1 + items.length) % items.length;
        updateQuickSearchFocus(items);
    } else if (e.key === 'Enter') {
        items[quickSearchIndex]?.click();
    } else if (e.key === 'Escape') {
        closeQuickSearch();
    }
});

function updateQuickSearchFocus(items) {
    items.forEach((item, i) => {
        if (i === quickSearchIndex) {
            item.classList.add('bg-purple-500/20', 'border', 'border-purple-500/30');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('bg-purple-500/20', 'border', 'border-purple-500/30');
        }
    });
}
(function() {
    // 1. Matikan Klik Kanan
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    // 2. Blokir Shortcut Keyboard (F12, Ctrl+Shift-I, Ctrl+U, dll)
    document.onkeydown = function(e) {
        if (
            e.keyCode === 123 || // F12
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || // Ctrl+Shift-I/J/C
            (e.ctrlKey && e.keyCode === 85) // Ctrl+U (View Source)
        ) {
            return false;
        }
    };

    // 3. Debugger Trap (Membuat DevTools 'Lag' atau Berhenti)
    // Script ini akan memicu pause otomatis jika konsol dibuka
    setInterval(function() {
        (function() {
            return false;
        }['constructor']('debugger')['call']());
    }, 50);
})();
</script>
</body>
</html>
