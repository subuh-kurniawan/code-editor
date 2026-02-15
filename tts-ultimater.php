<?php
include "admin/fungsi/koneksi.php";
// [PERBAIKAN ENCODING KARAKTER]
// Pastikan koneksi koneksi database menggunakan UTF-8 untuk penanganan karakter yang benar
if (isset($koneksi)) {
    mysqli_set_charset($koneksi, "utf8");
}
// ------------------------------

// [MODIFIKASI PHP UNTUK API SWITCHING]
// 1. Ambil SEMUA API Key yang aktif dan urutkan berdasarkan usage_count
$sql = mysqli_query($koneksi, "
    SELECT api_key
    FROM api_keys
    WHERE usage_count = (SELECT MIN(usage_count) FROM api_keys)
    ORDER BY RAND()
    LIMIT 1
");

$apiKeysList = [];
if ($sql && mysqli_num_rows($sql) > 0) {
    while ($row = mysqli_fetch_assoc($sql)) {
        $apiKeysList[] = $row['api_key'];
    }
} else {
    // Fallback jika tidak ada API key di database (Ganti dengan kunci cadangan Anda)
    // HARUS ADA KUNCI DUMMY ATAU REAL DI SINI UNTUK APLIKASI BERJALAN
    $apiKeysList[] = "AIzaSyAYYBCPplYs1pd3vqu5e13YsbF1hgQz8EY"; 
}
$apiKeyJson = json_encode($apiKeysList);

// Pilih API Key pertama sebagai default yang akan dicoba pertama kali
$apiKey = $apiKeysList[0]; 

// Ambil data sekolah (Jika diperlukan oleh aplikasi)
$sql = mysqli_query($koneksi, "SELECT * FROM datasekolah");
$data = mysqli_fetch_assoc($sql);

// Ambil Model
$models = [];
$sql = "SELECT model_name
        FROM api_model
        WHERE is_supported = 1
        ORDER BY id ASC";
$res = $koneksi->query($sql);
while ($row = $res->fetch_assoc()) {
    $models[] = $row['model_name'];
}
// Fallback jika database kosong atau tidak ada model yang didukung
if (empty($models)) {
    $models[] = "gemini-2.5-flash-preview-09-2025"; // Model Default
}
// Pilih model pertama / default
$model = $models[0];

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $protocol . $_SERVER['HTTP_HOST'];
// Tentukan path gambar OG
$ogImage = $domain . "game/og.jpg";
// Tentukan URL halaman saat ini
$currentUrl = $domain . $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini TTS Studio - Web Edition</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
   <style>
    :root {
        /* Base Dynamic Hues (Akan diupdate oleh JS) */
        --h: 100; 
        --s: 75%;
        --l: 55%;
        
        /* Accent Colors */
        --color-accent: hsl(var(--h), var(--s), var(--l));
        --color-accent-hover: hsl(var(--h), var(--s), calc(var(--l) - 8%));
        --color-accent-soft: hsl(var(--h), var(--s), 95%);
        --color-accent-glow: hsl(var(--h), var(--s), var(--l), 0.15);
        
        /* Dynamic Background & Surfaces */
        /* l: 98% membuat background sangat tipis, hampir putih tapi ber-rona */
        --color-bg-dynamic: hsl(var(--h), 25%, 98%);
        --color-sidebar: hsl(var(--h), 15%, 95%);
        --color-card: #FFFFFF;
        
        /* Text & Borders */
        --color-text-main: #1D1D1F;
        --color-text-sec: hsl(var(--h), 10%, 45%);
        --color-border: hsl(var(--h), 15%, 90%);
        --color-danger: #FF3B30;
        --color-success: #34C759;
    }

    /* --- Base Resets --- */
    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--color-bg-dynamic) !important;
        color: var(--color-text-main);
        transition: background-color 0.8s ease, color 0.5s ease;
    }
.sidebar, .glass-card, .segmented-btn, .btn-macos {
    transition: all 0.5s ease;
}
    /* --- Sidebar & Layout --- */
    .sidebar { 
        background-color: var(--color-sidebar); 
        border-color: var(--color-border);
        transition: all 0.3s ease;
    }

    /* --- Utility Overrides (Tailwind Hijacking) --- */
    .bg-blue-600, .bg-indigo-600 { background-color: var(--color-accent) !important; }
    .hover\:bg-blue-700:hover, .hover\:bg-indigo-700:hover { background-color: var(--color-accent-hover) !important; }
    .text-blue-600, .text-indigo-600, .text-blue-500 { color: var(--color-accent) !important; }
    .bg-blue-50, .bg-indigo-50 { background-color: var(--color-accent-soft) !important; }
    .border-blue-500, .focus\:ring-blue-500:focus { border-color: var(--color-accent) !important; --tw-ring-color: var(--color-accent) !important; }
    .shadow-blue-200 { box-shadow: 0 10px 25px -5px var(--color-accent-glow) !important; }
    .accent-blue-600 { accent-color: var(--color-accent) !important; }

    /* --- Cards & Glassmorphism --- */
    .glass-card { 
        background: var(--color-card); 
        border: 1px solid var(--color-border); 
        box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.03);
    }

    /* --- Custom UI Elements --- */
    .segmented-btn { 
        background: hsl(var(--h), 10%, 90%); 
        padding: 3px; 
        border-radius: 12px; 
    }
    .segmented-item { 
        padding: 6px 14px; 
        border-radius: 9px; 
        font-size: 11px; 
        font-weight: 600; 
        cursor: pointer; 
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
    }
    .segmented-item.active { 
        background: white; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
        color: var(--color-accent); 
    }

    /* --- Animations --- */
    @keyframes fadeIn { 
        from { opacity: 0; transform: translateY(12px); } 
        to { opacity: 1; transform: translateY(0); } 
    }
    .animate-fade-in { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    /* --- Scrollbar --- */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { 
        background: hsl(var(--h), 10%, 80%); 
        border-radius: 10px; 
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { 
        background: hsl(var(--h), 15%, 70%); 
    }

    /* --- Apple Style Button Interaction --- */
    .btn-macos { 
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
    }
    .btn-macos:active { transform: scale(0.96); filter: brightness(0.9); }
    
    /* --- Modal Backdrop --- */
    #devModal {
        transition: opacity 0.5s ease;
    }
    .animate-spin-slow {
        animation: spin 3s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
/* Penyesuaian Select2 agar senada dengan UI */
.select2-container--default .select2-selection--single {
    border-radius: 12px !important;
    border: 1px solid var(--color-border) !important;
    height: 42px !important;
    display: flex;
    align-items: center;
    background-color: white !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px !important;
}
.select2-dropdown {
    border-radius: 12px !important;
    border: 1px solid var(--color-border) !important;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
    overflow: hidden;
}
.select2-search__field {
    border-radius: 8px !important;
    outline: none !important;
}
#guideModal {
    /* Menggunakan HSLA agar transparan namun tetap memiliki rona warna tema */
    background-color: hsla(var(--h), 50%, 50%, 0.7) !important;
}
    /* Memastikan overlay mengikuti warna tema dinamis */
    #aiOverlay .from-blue-600 { --tw-gradient-from: var(--color-accent) !important; }
    #aiOverlay .to-blue-400 { --tw-gradient-to: hsl(var(--h), var(--s), 70%) !important; }
    #aiOverlay .bg-blue-500\/20 { background-color: hsla(var(--h), var(--s), var(--l), 0.2) !important; }
</style>
</head>
<body class="min-h-screen lg:h-screen flex flex-col lg:flex-row overflow-x-hidden" style="background-color: rgba(0,0,0,0.4);">

    <!-- MOBILE HEADER -->
    <header class="lg:hidden flex items-center justify-between p-4 bg-white border-b border-gray-200 sticky top-0 z-40">
        <div class="flex flex-col">
            
            <h1 class="text-base font-bold text-gray-800">Studio Narasi</h1>
            <p class="text-[8px] font-bold text-blue-600 uppercase tracking-widest">Web v2.5</p>
        </div>
        <button id="menuBtn" class="p-2 hover:bg-gray-100 rounded-lg btn-macos">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </header>

    <!-- SIDEBAR OVERLAY (MOBILE) -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/30 backdrop-blur-sm z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <aside id="sidebarMenu" class="sidebar fixed lg:static inset-y-0 left-0 w-72 flex flex-col h-full flex-shrink-0 border-r z-50 transform -translate-x-full lg:translate-x-0">
        <div class="p-8 text-center hidden lg:block">
            <div class="w-10 h-10 rounded-xl overflow-hidden shadow-inner border border-slate-100">
    <img src="admin/avatar/6862753d82078.png" alt="Dev" class="w-full h-full object-cover">
  </div>
            <h1 class="text-xl font-bold tracking-tight text-gray-800">Studio Narasi</h1>
            <p class="text-[10px] font-bold text-blue-600 uppercase tracking-widest mt-1">Web Engine v2.5</p>
        </div>

        <!-- Mobile Sidebar Header -->
        <div class="lg:hidden flex items-center justify-between p-6 border-b border-gray-300">
         <div class="w-8 h-8 rounded-lg overflow-hidden border border-gray-200">
                <img src="admin/avatar/6862753d82078.png" alt="Dev" class="w-full h-full object-cover">
            </div>
            <span class="font-bold text-gray-800">Pengaturan</span>
            <button onclick="toggleSidebar()" class="p-2 text-gray-500 hover:text-gray-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-6 lg:py-0 space-y-6 custom-scrollbar">
              <section class="w-full">
    <label class="text-[9px] font-bold text-gray-500 uppercase px-2 mb-2 block">Karakter Vokal</label>
    <div class="flex gap-2">
        <select id="voiceSelect" class="flex-1 bg-white border border-gray-300 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500"></select>
        <button onclick="openVoiceLibrary()" class="p-2 bg-blue-600 text-white rounded-xl hover:shadow-lg transition-all" title="Buka Katalog Visual">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
    </div>
</section>

            <!-- Gaya -->
            <section class="w-full">
                <label class="text-[9px] font-bold text-gray-500 uppercase px-2 mb-2 block">Gaya Bicara AI</label>
                <input type="text" id="styleSearch" placeholder="Cari gaya..." class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2 text-sm mb-2 outline-none">
                <select id="styleSelect" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2 text-sm outline-none">
                </select>
            </section>

            <!-- Artikulasi -->
            <section class="w-full">
                <label class="text-[9px] font-bold text-gray-500 uppercase px-2 mb-2 block">Energi Vokal</label>
                <div class="segmented-btn flex text-center" id="energySelector">
                    <div data-val="Bisik" class="segmented-item flex-1">Bisik</div>
                    <div data-val="Normal" class="segmented-item flex-1 active">Normal</div>
                    <div data-val="Energi" class="segmented-item flex-1">Energi</div>
                </div>
            </section>

            <!-- Tempo -->
            <section class="w-full lg:mb-6">
                <div class="flex justify-between items-center mb-1">
                    <label class="text-[9px] font-bold text-gray-500 uppercase px-2 block">Tempo</label>
                    <span id="speedVal" class="text-[10px] text-gray-400 font-bold">Normal</span>
                </div>
                <input type="range" id="speedRange" min="0" max="4" step="1" value="2" class="w-full accent-blue-600">
            </section>
            
        </div>

        <div class="p-6 border-t border-gray-300 space-y-3">
            <button id="mergeBtn" disabled class="w-full py-3 bg-gray-900 text-white rounded-xl text-xs font-bold btn-macos disabled:opacity-30 disabled:cursor-not-allowed">
                GABUNGKAN SEMUA
            </button>
           <button onclick="openDevModal()" class="group relative flex items-center gap-3 p-2 pr-6 bg-white/10 backdrop-blur-md border border-slate-200 rounded-2xl hover:bg-white hover:shadow-xl hover:shadow-indigo-100 transition-all duration-300">
  <div class="w-10 h-10 rounded-xl overflow-hidden shadow-inner border border-slate-100">
    <img src="admin/avatar/6862753d82078.png" alt="Dev" class="w-full h-full object-cover">
  </div>
  
  <div class="text-left">
    <p class="text-[10px] uppercase tracking-widest text-slate-400 font-bold leading-none mb-1">Developed by</p>
    <p class="text-sm font-semibold text-slate-700 group-hover:text-indigo-600 transition-colors">Subuh Kurniawan</p>
  </div>
  <button onclick="openGuideModal()" class="group relative flex items-center gap-3 p-2 pr-6 bg-white/10 backdrop-blur-md border border-slate-200 rounded-2xl hover:bg-white hover:shadow-xl hover:shadow-indigo-100 transition-all duration-300">
  <div class="w-10 h-10 rounded-xl overflow-hidden shadow-inner border border-slate-100">
    <img src="https://cdn-icons-png.freepik.com/256/8415/8415559.png" alt="Dev" class="w-full h-full object-cover">
  </div>
  
  <div class="text-left">
    <p class="text-[10px] uppercase tracking-widest text-slate-400 font-bold leading-none mb-1">Petunjuk</p>
    
  </div>
  <div class="absolute inset-0 rounded-2xl bg-indigo-500/0 group-hover:bg-indigo-500/5 transition-all duration-300"></div>
  
</button>
        </div>
        
    </aside>

    <!-- MAIN CONTENT -->
    <main class="flex-1 flex flex-col min-w-0 p-4 sm:p-6 lg:p-8 h-full lg:overflow-hidden overflow-visible">
        <!-- Header Controls -->
        <header class="flex flex-col sm:flex-row justify-between items-start sm:items-end mb-4 px-2 gap-4">
            <div class="order-2 sm:order-1">
                <p id="configLabel" class="text-[10px] lg:text-xs font-bold text-gray-800 uppercase tracking-wide">KONFIGURASI: AUTENTIK • NETRAL</p>
            </div>
            <div class="segmented-btn flex self-end sm:self-auto order-1 sm:order-2" id="modeSelector">
                <div data-val="Autentik" class="segmented-item active px-4">Autentik</div>
                <div data-val="Cerdas" class="segmented-item px-4">Cerdas</div>
            </div>
        </header>

        <!-- Editor -->
        <div class="glass-card flex-shrink-0 h-80 sm:h-96 lg:flex-1 flex flex-col rounded-2xl overflow-hidden mb-6">
            <div class="p-2 sm:p-3 bg-white border-b border-gray-100 flex flex-wrap gap-2">
                <button onclick="processAI('refine')" class="flex-1 sm:flex-none px-3 py-1.5 bg-blue-50 text-blue-600 text-[10px] font-bold rounded-lg hover:bg-blue-100 btn-macos whitespace-nowrap">✨ OPTIMASI AI</button>
                <div class="relative group flex-1 sm:flex-none">
                    <button class="w-full sm:w-auto px-3 py-1.5 bg-gray-50 text-gray-600 text-[10px] font-bold rounded-lg hover:bg-gray-100 btn-macos whitespace-nowrap">🌐 TERJEMAH</button>
                    <div class="absolute hidden group-hover:block bg-white border border-gray-200 shadow-xl rounded-lg z-30 w-32 py-1 mt-1">
                        <button onclick="processAI('translate', 'Inggris')" class="w-full text-left px-4 py-2 text-[11px] hover:bg-blue-50">Inggris</button>
                        <button onclick="processAI('translate', 'Jepang')" class="w-full text-left px-4 py-2 text-[11px] hover:bg-blue-50">Jepang</button>
                        <button onclick="processAI('translate', 'Arab')" class="w-full text-left px-4 py-2 text-[11px] hover:bg-blue-50">Arab</button>
                    </div>
                </div>
                <button onclick="clearText()" class="flex-1 sm:flex-none px-3 py-1.5 bg-gray-50 text-gray-600 text-[10px] font-bold rounded-lg hover:bg-gray-100 btn-macos whitespace-nowrap">🧹 BERSERSIHKAN</button>
            </div>
            <textarea id="mainInput" class="flex-1 p-4 sm:p-6 text-base sm:text-lg outline-none resize-none placeholder-gray-300 leading-relaxed custom-scrollbar" placeholder="Tulis naskah narasi atau instruksi pembuatan narasi di sini..."></textarea>
            <div class="px-4 sm:px-6 py-2 bg-gray-50 flex justify-between items-center text-[10px] text-gray-400 font-semibold border-t border-gray-100">
                <span id="estDuration">Estimasi: 0s</span>
                <span id="charCount">0 Karakter</span>
            </div>
        </div>

        <!-- Final Result Box -->
        <div id="masterBox" class="hidden glass-card bg-gray-900 rounded-2xl p-4 sm:p-6 mb-6 text-white animate-fade-in flex-shrink-0">
            <h3 class="text-center font-bold text-xs sm:text-sm mb-4">HASIL GABUNGAN FINAL</h3>
            <div class="bg-gray-800 rounded-xl p-3 sm:p-4 mb-4">
                <p id="mergedTextPreview" class="text-[11px] sm:text-xs text-gray-400 italic line-clamp-3">Memuat pratinjau narasi...</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                <button id="playMergedBtn" class="flex-1 py-3 bg-blue-600 rounded-xl font-bold text-[11px] btn-macos">▶ PUTAR HASIL</button>
                <button onclick="stopAudio()" class="flex-1 py-3 bg-red-600 rounded-xl font-bold text-[11px] btn-macos">■ BERHENTI</button>
                <button id="copyMergedBtn" class="flex-1 py-3 bg-purple-600 rounded-xl font-bold text-[11px] btn-macos">📋 SALIN NARASI</button>
                <button id="saveMergedBtn" class="flex-1 py-3 bg-green-600 rounded-xl font-bold text-[11px] btn-macos">💾 SIMPAN .WAV</button>
            </div>
        </div>

        <!-- Progress & Controls -->
        <div class="flex flex-col md:flex-row gap-4 items-stretch md:items-center flex-shrink-0">
            <div class="flex gap-2 sm:gap-3 flex-1">
                <button id="startBtn" class="flex-1 sm:flex-none sm:px-10 py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 btn-macos text-sm">
                    MULAI PROSES
                </button>
                <button id="stopBtn" class="px-6 sm:px-8 py-4 bg-red-500 text-white rounded-2xl font-bold btn-macos text-sm">
                    STOP
                </button>
            </div>
            <div class="w-full md:w-auto md:min-w-[300px] lg:min-w-[400px] glass-card p-4 rounded-2xl flex flex-col justify-center">
                <div class="flex justify-between items-center mb-1">
                    <span id="progressStatus" class="text-[10px] sm:text-[11px] font-bold text-gray-700 uppercase">Sistem Siap</span>
                    <span id="progressPercent" class="text-[10px] sm:text-[11px] font-bold text-blue-600">0%</span>
                </div>
                <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                    <div id="progressBar" class="h-full bg-blue-600 transition-all duration-300 w-0"></div>
                </div>
            </div>
        </div>

        <!-- Results List -->
        <div id="resultsList" class="mt-8 flex-1 overflow-y-auto custom-scrollbar space-y-4 pb-12 lg:pb-0">
            <!-- Results parts injected here -->
        </div>
    </main>

    <div id="aiOverlay" class="fixed inset-0 bg-white/40 backdrop-blur-md hidden z-[100] flex flex-col items-center justify-center opacity-0 transition-opacity duration-500">
    <div class="relative flex items-center justify-center">
        <div class="absolute w-32 h-32 bg-blue-500/20 rounded-full animate-ping"></div>
        <div class="absolute w-24 h-24 bg-blue-600/30 rounded-full animate-pulse delay-75"></div>
        
        <div class="relative w-16 h-16 bg-gradient-to-tr from-blue-600 to-blue-400 rounded-full shadow-[0_0_30px_rgba(0,0,0,0.1)] flex items-center justify-center border-2 border-white/50">
            <svg class="w-8 h-8 text-white animate-spin-slow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83" stroke-linecap="round"/>
            </svg>
        </div>
    </div>
    
    <div class="mt-8 text-center">
        <h3 class="text-lg font-bold text-gray-800 tracking-tight">AI Sedang Memproses</h3>
        <p class="text-[11px] font-bold text-blue-600 uppercase tracking-[0.2em] animate-pulse">Menyusun Narasi Terbaik...</p>
    </div>
</div>
    <!-- POPUP FINISH NOTIFICATION -->
    <div id="finishModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden z-[60] flex items-center justify-center p-4">
        <div class="glass-card max-w-sm w-full p-6 text-center animate-fade-in">
            <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gray-800 mb-2">Konversi Selesai!</h3>
            <p class="text-sm text-gray-600 mb-6">Seluruh bagian narasi telah siap. Silakan klik tombol <span class="font-bold text-gray-800">Gabungkan Semua</span> di bagian bawah panel pengaturan untuk menyatukan hasil audio.</p>
            <button onclick="closeFinishModal()" class="w-full py-3 bg-blue-600 text-white rounded-xl font-bold btn-macos shadow-lg shadow-blue-200">Mengerti</button>
        </div>
    </div>
<div id="devModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-xl hidden opacity-0 transition-all duration-500" style="background-color: rgba(0,0,0,0.4);">
  
  <div class="relative w-full max-w-md bg-white/80 backdrop-blur-2xl rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.1)] border border-white/50 overflow-hidden transform scale-95 transition-all duration-500">
    <div class="absolute top-0 left-0 w-full h-32 bg-gradient-to-br from-indigo-500/20 to-purple-500/20 -z-10"></div>

    <button onclick="closeDevModal()" class="absolute top-5 right-5 p-2 rounded-full bg-white/50 hover:bg-white shadow-sm transition-all text-slate-400 hover:text-slate-800">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
    </button>

    <div class="p-8 pt-12 flex flex-col items-center">
      
      <div class="relative group">
        <div class="absolute -inset-1 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-[2rem] blur opacity-25 group-hover:opacity-50 transition duration-1000 group-hover:duration-200"></div>
        <div class="relative w-28 h-28 rounded-[1.8rem] overflow-hidden border-4 border-white shadow-inner">
          <img src="admin/avatar/6862753d82078.png" alt="Dev" class="w-full h-full object-cover">
        </div>
      </div>

      <div class="mt-6 text-center">
        <h3 class="text-2xl font-extrabold text-slate-800 tracking-tight">Subuh Kurniawan</h3>
        <p class="inline-block px-3 py-1 mt-1 text-xs font-semibold tracking-wider text-indigo-600 uppercase bg-indigo-50 rounded-full">
          Oprekers
        </p>
      </div>

      <div class="flex gap-8 mt-6 mb-8 py-4 px-6 bg-slate-50/50 rounded-2xl border border-slate-100 w-full justify-center">
        <div class="text-center">
          <span class="block text-lg font-bold text-slate-800">50+</span>
          <span class="text-[10px] text-slate-400 uppercase tracking-widest">Projects</span>
        </div>
        <div class="h-10 w-[1px] bg-slate-200"></div>
        <div class="text-center">
          <span class="block text-lg font-bold text-slate-800">4.9</span>
          <span class="text-[10px] text-slate-400 uppercase tracking-widest">Rating</span>
        </div>
      </div>

      <div class="w-full space-y-3">
        <a href="https://wa.me/6281366344788" class="flex items-center justify-center gap-3 w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-semibold shadow-[0_10px_20px_rgba(79,70,229,0.3)] transition-all active:scale-95">
          <span>Hubungi Saya</span>
          <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.025 3.212l-.539 1.967 2.011-.528c.903.54 1.861.825 2.82.825 3.187 0 5.773-2.587 5.774-5.767 0-3.18-2.587-5.775-5.923-5.775zm3.176 8.161c-.144.406-.833.774-1.141.823-.299.047-.687.088-1.109-.044-.242-.076-.554-.153-.948-.323-1.677-.722-2.756-2.422-2.84-2.533-.083-.111-.682-.906-.682-1.724 0-.819.43-1.22.583-1.387.153-.167.334-.208.445-.208.111 0 .222.001.32.005.101.004.227-.038.354.271.127.309.435 1.063.474 1.139.04.077.067.167.014.271-.053.103-.08.167-.159.257-.079.09-.166.202-.236.271-.077.077-.157.16-.067.313.091.153.402.664.862 1.074.594.528 1.093.691 1.253.768.16.077.254.064.347-.043.093-.106.398-.464.505-.623.106-.16.212-.133.356-.08.145.053.918.433 1.076.512.159.08.264.12.304.188.04.067.04.391-.104.797z"/></svg>
        </a>
        
        <div class="grid grid-cols-2 gap-3">
          <a href="#" class="flex items-center justify-center py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition-all text-sm font-medium">Portfolio</a>
          <a href="#" class="flex items-center justify-center py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition-all text-sm font-medium">GitHub</a>
        </div>
      </div>

    </div>
  </div>
</div>
<div id="guideModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-xl hidden opacity-0 transition-all duration-500" style="background-color: rgba(0,0,0,0.4);">
  
  <div class="relative w-full max-w-md bg-white/80 backdrop-blur-2xl rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.1)] border border-white/50 overflow-hidden transform scale-95 transition-all duration-500">
    <div class="absolute top-0 left-0 w-full h-32 bg-gradient-to-br from-green-500/20 to-blue-500/20 -z-10"></div>

    <button onclick="closeGuideModal()" class="absolute top-5 right-5 p-2 rounded-full bg-white/50 hover:bg-white shadow-sm transition-all text-slate-400 hover:text-slate-800">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
    </button>

    <div class="p-8 pt-10 flex flex-col">
      
      <div class="flex items-center gap-4 mb-6">
        <div class="w-12 h-12 rounded-2xl bg-green-500 flex items-center justify-center text-white shadow-lg shadow-green-200">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
        </div>
        <div>
          <h3 class="text-xl font-extrabold text-slate-800 leading-none">Panduan Cepat</h3>
          <p class="text-[10px] text-green-600 font-bold uppercase tracking-widest mt-1">Studio Narasi V2.5</p>
        </div>
      </div>

      <div class="space-y-3">
        <div class="group flex items-start gap-4 p-3.5 rounded-[1.5rem] bg-white/60 border border-white hover:border-green-200 transition-all">
          <div class="flex-shrink-0 w-7 h-7 rounded-full bg-slate-800 text-white flex items-center justify-center font-bold text-xs">1</div>
          <div>
            <p class="text-[13px] font-bold text-slate-800">Konfigurasi Vokal</p>
            <p class="text-[11px] text-slate-500 leading-snug">Pilih karakter, gaya bicara, dan tingkat energi (Bisik/Normal) di panel samping kiri.</p>
          </div>
        </div>

        <div class="group flex items-start gap-4 p-3.5 rounded-[1.5rem] bg-white/60 border border-white hover:border-green-200 transition-all">
          <div class="flex-shrink-0 w-7 h-7 rounded-full bg-slate-800 text-white flex items-center justify-center font-bold text-xs">2</div>
          <div>
            <p class="text-[13px] font-bold text-slate-800">Input Naskah</p>
            <p class="text-[11px] text-slate-500 leading-snug">Tulis teks Anda di area editor. Gunakan <span class="text-green-600 font-semibold">Optimasi AI</span> untuk menyempurnakan kalimat secara otomatis.</p>
          </div>
        </div>

        <div class="group flex items-start gap-4 p-3.5 rounded-[1.5rem] bg-white/60 border border-white hover:border-green-200 transition-all">
          <div class="flex-shrink-0 w-7 h-7 rounded-full bg-slate-800 text-white flex items-center justify-center font-bold text-xs">3</div>
          <div>
            <p class="text-[13px] font-bold text-slate-800">Eksekusi & Pantau</p>
            <p class="text-[11px] text-slate-500 leading-snug">Klik <span class="text-green-600 font-semibold">Mulai Proses</span>. Pantau bar progres di kanan bawah hingga sistem selesai memproses suara.</p>
          </div>
        </div>

        <div class="group flex items-start gap-4 p-3.5 rounded-[1.5rem] bg-white/60 border border-white hover:border-green-200 transition-all">
          <div class="flex-shrink-0 w-7 h-7 rounded-full bg-slate-800 text-white flex items-center justify-center font-bold text-xs">4</div>
          <div>
            <p class="text-[13px] font-bold text-slate-800">Finalisasi</p>
            <p class="text-[11px] text-slate-500 leading-snug">Jika membuat beberapa bagian, gunakan tombol <span class="font-semibold">Gabungkan Semua</span> untuk menyatukan file audio.</p>
          </div>
        </div>
      </div>

      <button onclick="closeGuideModal()" class="mt-8 w-full py-4 bg-green-500 hover:bg-green-600 text-white rounded-2xl font-bold shadow-[0_10px_20px_rgba(34,197,94,0.3)] transition-all active:scale-95 flex items-center justify-center gap-2">
        <span>Mulai Berkarya</span>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
      </button>

    </div>
  </div>
</div>
<div id="voiceLibraryModal" class="fixed inset-0 z-[70] hidden opacity-0 transition-all duration-500 flex items-center justify-center p-4 sm:p-6">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-xl" onclick="closeVoiceLibrary()"></div>
    
    <div class="relative w-full max-w-5xl h-[90vh] bg-white/90 backdrop-blur-2xl rounded-[3rem] shadow-2xl border border-white/50 flex flex-col overflow-hidden transform scale-95 transition-all duration-500" id="libraryContent">
        
        <div class="p-6 sm:p-10 border-b border-slate-200/50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tight">Katalog Vokal AI</h2>
                <p class="text-slate-500 text-sm font-medium">Dengarkan pratinjau karakter suara sebelum memilih.</p>
            </div>
            <button onclick="closeVoiceLibrary()" class="p-3 rounded-full bg-slate-100 hover:bg-white shadow-sm transition-all text-slate-400 hover:text-slate-800">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 sm:p-10 custom-scrollbar">
            <div id="voiceGridContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                </div>
        </div>

        <div class="p-6 bg-slate-50/50 border-t border-slate-200/50 text-center">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Klik "Pilih" pada kartu untuk menerapkan karakter vokal</p>
        </div>
    </div>
</div>

<audio id="previewAudioElement" class="hidden"></audio>
    <script>
       // --- KONFIGURASI API & DATA ---
const API_KEYS_LIST = <?php echo $apiKeyJson; ?>;
let ACTIVE_API_KEY = API_KEYS_LIST[0]; 
let currentApiKeyIndex = 0;

const VOICES = [
    "Zephyr", "Puck", "Charon", "Kore", "Fenrir", "Leda", "Orus", "Aoede", 
    "Callirrhoe", "Autonoe", "Enceladus", "Iapetus", "Umbriel", "Algieba", 
    "Despina", "Erinome", "Algenib", "Rasalgethi", "Laomedeia", "Achernar", 
    "Alnilam", "Schedar", "Gacrux", "Pulcherrima", "Achird", "Zubenelgenubi", 
    "Vindemiatrix", "Sadachbia", "Sadaltager", "Sulafat"
];

const TEMPLATES = [
            {"name": "Netral", "prompt": "Gaya bicara alami, stabil, dan jernih tanpa emosi berlebih."},
            {"name": "Guru Gaul (Gen-Z)", "prompt": "Sangat energetik, menggunakan intonasi santai seperti kreator TikTok, akrab, dan menggunakan penekanan pada kata-kata penting agar tidak membosankan."},
            {"name": "Dosen Formal", "prompt": "Wibawa tinggi, tempo bicara teratur, artikulasi sangat jelas, dan memberikan kesan akademisi yang serius namun cerdas."},
            {"name": "Guru Lucu & Ceria", "prompt": "Penuh tawa di sela kalimat, nada bicara naik turun secara ekspresif, humoris, dan sangat menghibur layaknya guru TK/SD yang asyik."},
            {"name": "Storytelling Sejarah", "prompt": "Khidmat, dramatis, penuh penghayatan pada momen penting, membuat pendengar merasa berada di masa lalu."},
            {"name": "Eksplorasi Sains", "prompt": "Nada penuh rasa ingin tahu (curiosity), antusias saat menjelaskan fakta unik, dan tempo sedikit melambat pada istilah teknis."},
            {"name": "Kuis Interaktif", "prompt": "Cepat, menantang, penuh semangat, dan memberikan jeda seolah menunggu jawaban dari siswa."},
            {"name": "Tutorial Step-by-Step", "prompt": "Sangat sabar, artikulasi lambat dan jelas, memberikan penekanan pada instruksi perintah."},
            {"name": "Akademisi Intelek", "prompt": "Nada bicara tenang, penuh logika, menggunakan intonasi yang memprovokasi pemikiran kritis."},
            {"name": "Pengumuman Resmi", "prompt": "Tegas, formal, informatif, dan memberikan kesan otoritas sekolah yang tertib."},
            {"name": "Promosi Sekolah (Eksklusif)", "prompt": "Elegan, profesional, membanggakan, dengan tempo yang memberikan kesan sekolah kelas atas (luxury)."},
            {"name": "Sambutan Kepala Sekolah", "prompt": "Hangat, mengayomi, kebapakan/keibuan, penuh harapan, dan berwibawa."},
            {"name": "Liputan Event (Reporter)", "prompt": "Gaya jurnalistik lapangan, cepat, dinamis, dan memberikan suasana kemeriahan acara."},
            {"name": "Iklan Sosmed (Hard Sell)", "prompt": "To-the-point, sangat persuasif, energik, dan memiliki ajakan (call to action) yang kuat."},
            {"name": "Dokumentasi Wisuda", "prompt": "Melankolis, penuh haru, membanggakan, dan ritme bicara yang lambat penuh perasaan."},
            {"name": "Testimoni Alumni", "prompt": "Tulus, bersemangat, memberikan kesan sukses dan penuh rasa terima kasih."},
            {"name": "Podcast Santai", "prompt": "Akrab, seperti sedang ngobrol di warung kopi, penuh jeda alami dan tawa tipis."},
            {"name": "Kakek Bijak", "prompt": "Suara berat, tempo sangat lambat, penuh kebijaksanaan, dan terdengar tua."},
            {"name": "Anak Kecil Ceria", "prompt": "Nada tinggi, sangat lincah, polos, dan penuh energi kebahagiaan."},
            {"name": "Trailer Film Epik", "prompt": "Deep voice, dramatis, penuh penekanan misterius dan kekuatan besar."},
            {"name": "Anime Ekspresif", "prompt": "Hiperaktif, reaksi emosional yang berlebihan, khas karakter animasi Jepang."},
            {"name": "Robot AI Futuristik", "prompt": "Datar, stabil, tanpa emosi, namun sangat jernih dan berteknologi tinggi."},
            {"name": "Horor/Misteri", "prompt": "Berbisik, mencekam, penuh jeda yang mengintimidasi, dan dingin."},
            {"name": "Dialek Jawa Medok", "prompt": "Logat Jawa kental, penekanan b,d,g,j yang tebal, sopan, dan mendayu."},
            {"name": "Dialek Jawa Ngapak", "prompt": "Logat Banyumasan (Ngapak) yang sangat tegas, lugas, bicara apa adanya (blak-blakan), dan memiliki ritme vokal yang unik."},
            {"name": "Dialek Lampung A (Api)", "prompt": "Logat Lampung A (Api) yang dominan menggunakan vokal /a/ di akhir kata, ciri kosakata khas Lampung Pesisir dengan intonasi hangat dan aksen vokal kuat. Dalam gaya pelafalan kreatif ini, huruf R diucapkan sebagai bunyi 'kh' yang halus namun terdengar, memberikan nuansa lokal yang khas."},
            {"name": "Dialek Lampung O (Nyo)", "prompt": "Logat Lampung O (Nyo) yang dominan memakai vokal /o/ di akhir kata, dipakai oleh masyarakat Pepadun; nada bicara cenderung lebih lembut dengan variasi vokal /o/ kuat. Dalam adaptasi kreatifnya, pelafalan huruf R diubah menjadi bunyi 'kh', menambah nuansa kultur lokal dalam dialog."},
            {"name": "Dialek Betawi Asli", "prompt": "Gaya bicara masyarakat Betawi pinggiran, santai, ceplas-ceplos, banyak menggunakan akhiran 'e', dan humoris."},
            {"name": "Anak Jaksel (English Mix)", "prompt": "Gaya bicara anak muda Jakarta Selatan, mencampur bahasa Indonesia dengan istilah Inggris secara berlebihan (code-switching), intonasi 'up-talk', dan kasual."},
            {"name": "Dialek Batak Tegas", "prompt": "Intonasi naik turun dengan tegas, lantang, lugas, dan berapi-api khas Sumatera Utara."},
            {"name": "Dialek Sunda Halus", "prompt": "Logat Sunda yang sangat lembut, intonasi mendayu, penuh partikel ramah seperti 'teh' atau 'mah', dan sopan."},
            {"name": "Dialek Bali Sopan", "prompt": "Logat Bali yang khas dengan penekanan tajam pada huruf 't' (th), intonasi yang mengayun tenang, dan sangat menghargai lawan bicara."},
            {"name": "Dialek Minang Padang", "prompt": "Logat Minangkabau yang tegas, ritme bicara bertenaga, intonasi khas di akhir kata, dan penuh wibawa."},
            {"name": "Dialek Makassar (Sulawesi)", "prompt": "Gaya bicara Sulawesi Selatan, cepat, tegas, sering menggunakan partikel 'mi', 'ji', 'ki', bertenaga dan lugas."},
            {"name": "Dialek Ambon Manise", "prompt": "Irama bicara yang berlagu, penekanan kuat pada setiap suku kata, dan memberikan kesan persahabatan yang erat."},
            {"name": "Suroboyoan (Jawa Timur)", "prompt": "Logat Surabaya yang sangat tegas, lugas, bertenaga, blak-blakan, dan memiliki kesan berani serta akrab."},
            {"name": "Dialek Melayu Puitis", "prompt": "Lembut, ritme berirama puitis, sopan, dan sangat mendayu khas Melayu."},
            {"name": "Bahasa Indonesia Formal Edukatif", "prompt": "Pengucapan Bahasa Indonesia baku, artikulasi jelas, tempo sedang, intonasi tenang dan profesional untuk keperluan pendidikan dan publikasi resmi."},
            {"name": "Bahasa Indonesia Santai Naratif", "prompt": "Bahasa Indonesia nonformal yang hangat, mengalir alami, cocok untuk storytelling edukatif dan konten kreator."},
            {"name": "Dialek Jawa Krama Halus", "prompt": "Logat Jawa krama yang sangat sopan, lembut, intonasi rendah dan mendayu, mencerminkan unggah-ungguh dan rasa hormat."},
            {"name": "Dialek Madura Halus", "prompt": "Logat Madura lembut, artikulasi jelas, intonasi stabil, sopan dan komunikatif untuk penyampaian edukatif."},
            {"name": "Dialek Banjar (Kalimantan Selatan)", "prompt": "Logat Banjar khas, tempo bicara sedang-cepat, intonasi bersahabat dan ringan, mudah diterima untuk narasi publik."},
            {"name": "Dialek Bugis Sopan", "prompt": "Logat Bugis yang tegas namun santun, artikulasi kuat, intonasi stabil, mencerminkan keteguhan dan rasa hormat."},
            {"name": "Dialek Toraja Lembut", "prompt": "Irama bicara tenang, artikulasi jelas, nada mendayu lembut dan reflektif, cocok untuk konten budaya dan edukasi."},
            {"name": "Dialek Papua Bersahabat", "prompt": "Intonasi hangat dan terbuka, tempo bicara sedang, artikulasi ekspresif yang menimbulkan kesan ramah dan inklusif."},
            {"name": "Dialek Sasak Lombok", "prompt": "Logat Sasak dengan ritme ringan, intonasi alami, sopan, dan komunikatif untuk narasi pembelajaran budaya."},
            {"name": "Dialek Aceh Santun", "prompt": "Logat Aceh dengan penekanan khas, tempo terkontrol, intonasi tegas namun sopan dan berwibawa."},
            {"name": "Dialek Melayu Riau", "prompt": "Pengucapan lembut, ritme stabil, bernuansa sastra dan puitis, sangat cocok untuk konten literasi dan sejarah."},
            {"name": "Dialek Dayak Netral", "prompt": "Logat Dayak netral dengan artikulasi jelas, tempo sedang, intonasi tenang dan bersahabat untuk edukasi budaya."},
            {"name": "Dialek Osing Banyuwangi", "prompt": "Logat Osing khas Banyuwangi, artikulasi tegas, intonasi naik turun lembut, terdengar unik dan ekspresif untuk konten budaya."},
            {"name": "Dialek Cirebonan", "prompt": "Logat Cirebon dengan pengaruh Jawa–Sunda, ritme ringan, lugas namun tetap ramah dan komunikatif."},
            {"name": "Dialek Tegal Ngapak", "prompt": "Logat Tegal ngapak yang jelas dan terbuka, artikulasi kuat, ritme cepat, terasa akrab dan apa adanya."},
            {"name": "Dialek Palembang Musi", "prompt": "Logat Palembang khas Sungai Musi, tempo sedang, intonasi bersahabat dan santun, cocok untuk narasi publik."},
            {"name": "Dialek Jambi Melayu", "prompt": "Logat Melayu Jambi lembut, stabil, artikulasi jelas, bernuansa hangat dan komunikatif."},
            {"name": "Dialek Bengkulu", "prompt": "Logat Bengkulu dengan tekanan khas, tempo bicara seimbang, sopan dan mudah dipahami."},
            {"name": "Dialek Kutai (Kalimantan Timur)", "prompt": "Logat Kutai lembut, ritme tenang, artikulasi halus, cocok untuk konten sejarah dan budaya lokal."},
            {"name": "Dialek Banjar Hulu", "prompt": "Logat Banjar Hulu dengan intonasi lebih kuat, ritme bicara hidup namun tetap sopan dan edukatif."},
            {"name": "Dialek Manado Halus", "prompt": "Logat Manado ringan, ceria, intonasi naik turun lembut, memberi kesan ramah dan optimis."},
            {"name": "Dialek Gorontalo", "prompt": "Logat Gorontalo dengan tekanan konsonan khas, tempo sedang, ekspresif dan komunikatif."},
            {"name": "Dialek Nusa Tenggara Timur (NTT)", "prompt": "Intonasi tegas namun hangat, artikulasi jelas, ritme stabil yang memberi kesan jujur dan bersahabat."},
            {"name": "Dialek Flores Lembut", "prompt": "Irama bicara pelan dan teratur, intonasi halus, cocok untuk narasi reflektif dan edukatif."},
            {"name": "Dialek Tidore–Ternate", "prompt": "Logat Maluku Utara dengan artikulasi kuat, intonasi berwibawa, cocok untuk konten sejarah dan dokumenter."},
            {"name": "Dialek Melayu Pontianak", "prompt": "Logat Melayu Pontianak lembut, ritme santai, artikulasi jelas dan komunikatif."},
            {"name": "Gaya Narator Dokumenter", "prompt": "Intonasi stabil dan berwibawa, tempo terukur, artikulasi sangat jelas untuk dokumenter dan video edukasi."},
            {"name": "Gaya Guru Inspiratif", "prompt": "Nada hangat dan memotivasi, artikulasi jelas, tempo sedang, mendorong rasa ingin tahu dan semangat belajar."},
            {"name": "Gaya Storyteller Anak", "prompt": "Nada ceria dan ekspresif, tempo dinamis, artikulasi jelas dan ramah untuk pendengar usia anak."},
            {"name": "Gaya Podcast Edukatif", "prompt": "Santai namun fokus, intonasi natural, artikulasi bersih, nyaman didengar dalam durasi panjang."},
            {"name": "Storytelling Hangat Netral", "prompt": "Nada suara hangat dan mengalir, tempo sedang, artikulasi jelas, membangun kedekatan emosional dengan pendengar."},
            {"name": "Storytelling Dramatis Lembut", "prompt": "Intonasi naik turun halus, tempo terkontrol, jeda emosional yang terasa, cocok untuk cerita reflektif dan inspiratif."},
            {"name": "Storytelling Epik", "prompt": "Nada berwibawa dan dalam, tempo perlahan, artikulasi kuat untuk membangun suasana heroik dan bersejarah."},
            {"name": "Storytelling Misterius", "prompt": "Suara agak rendah, tempo pelan, intonasi penuh teka-teki dengan jeda yang disengaja untuk membangun rasa penasaran."},
            {"name": "Storytelling Ceria Anak", "prompt": "Nada cerah dan ekspresif, tempo dinamis, artikulasi jelas dan ramah, memicu imajinasi anak-anak."},
            {"name": "Storytelling Emosional", "prompt": "Nada lembut dan menyentuh, tempo lambat, penekanan emosi pada kata kunci untuk membangun empati."},
            {"name": "Storytelling Petualangan", "prompt": "Tempo hidup dan progresif, intonasi berenergi, memberi kesan perjalanan dan eksplorasi."},
            {"name": "Storytelling Legenda Nusantara", "prompt": "Intonasi mendayu dan berirama, tempo sedang, nuansa tradisional yang kuat untuk kisah legenda dan folklore."},
            {"name": "Storytelling Religius Reflektif", "prompt": "Nada tenang dan khidmat, tempo perlahan, artikulasi lembut untuk cerita bernilai spiritual dan moral."},
            {"name": "Storytelling Motivasi Inspiratif", "prompt": "Nada positif dan membangkitkan semangat, tempo stabil, artikulasi tegas namun hangat."},
            {"name": "Storytelling Sejarah Naratif", "prompt": "Intonasi netral-berwibawa, tempo terukur, artikulasi jelas untuk alur cerita berbasis peristiwa sejarah."},
            {"name": "Storytelling Romantis Halus", "prompt": "Nada lembut dan mendayu, tempo lambat, intonasi penuh rasa untuk kisah hubungan dan perasaan."},
            {"name": "Storytelling Komedi Ringan", "prompt": "Intonasi lincah, tempo fleksibel, penekanan lucu yang natural tanpa berlebihan."},
            {"name": "Storytelling Kontemplatif", "prompt": "Nada rendah dan tenang, tempo pelan, memberi ruang hening untuk renungan dan makna cerita."},
            {"name": "Storytelling Sinematik", "prompt": "Nada dalam dan imersif, tempo perlahan dengan jeda dramatis, membangun visual kuat seolah adegan film."},
            {"name": "Storytelling Imajinatif Fantasi", "prompt": "Intonasi variatif dan penuh warna, tempo fleksibel, membawa pendengar ke dunia khayalan yang hidup."},
            {"name": "Storytelling Dark Fantasy", "prompt": "Nada rendah dan misterius, tempo pelan, atmosfer gelap namun elegan, memancing rasa penasaran."},
            {"name": "Storytelling Thriller Tegang", "prompt": "Tempo meningkat bertahap, intonasi tajam dan terkontrol, menciptakan ketegangan berlapis."},
            {"name": "Storytelling Horor Psikologis Halus", "prompt": "Suara ditahan dan berbisik lembut, tempo lambat, jeda sunyi untuk membangun rasa tidak nyaman."},
            {"name": "Storytelling Surealis", "prompt": "Intonasi mengalun tidak terduga, tempo bebas, nuansa mimpi yang abstrak dan artistik."},
            {"name": "Storytelling Realisme Kehidupan", "prompt": "Nada natural dan jujur, tempo stabil, terasa dekat dengan pengalaman sehari-hari."},
            {"name": "Storytelling Nostalgia", "prompt": "Nada lembut dan hangat, tempo pelan, membangkitkan kenangan dan rasa rindu."},
            {"name": "Storytelling Monolog Batin", "prompt": "Suara rendah dan intim, tempo lambat, seolah berbicara dalam pikiran tokoh."},
            {"name": "Storytelling Puitis Modern", "prompt": "Irama berayun seperti puisi bebas, intonasi lembut dan ekspresif, artistik namun mudah dipahami."},
            {"name": "Storytelling Cepat Cinematic Cut", "prompt": "Tempo cepat dengan transisi tegas, intonasi fokus dan padat, cocok untuk short video dan reels."},
            {"name": "Storytelling Interaktif", "prompt": "Nada komunikatif, tempo variatif, seolah mengajak pendengar terlibat langsung dalam cerita."},
            {"name": "Storytelling Ironis & Satir Halus", "prompt": "Intonasi tenang dengan penekanan makna tersembunyi, humor cerdas dan reflektif."},
            {"name": "Storytelling Eksperimental Audio", "prompt": "Permainan tempo dan intonasi ekstrem, jeda tidak biasa, menciptakan pengalaman audio yang unik."},
            {"name": "Motivasi Membara", "prompt": "Penuh inspirasi, suara lantang, menggerakkan jiwa, dan sangat bersemangat."},
            {"name": "Sarkastik/Sinisme", "prompt": "Nada menyindir, sinis, tajam, dan meremehkan."},
            {"name": "ASMR Intim", "prompt": "Suara sangat lembut, dekat dengan mikrofon, intim, dan pelan."},
            {"name": "Meditasi/Zen", "prompt": "Sangat tenang, menghanyutkan, lembut, dengan jeda antar kalimat yang sangat panjang."}
        ];

let state = {
    isGenerating: false,
    shouldStop: false,
    selectedVoice: "Kore",
    selectedStyle: TEMPLATES[0],
    energy: "Normal",
    mode: "Autentik",
    speed: "Normal",
    chunks: [],
    results: {},
    isSidebarOpen: false
};

let activeAudio = null;

// --- INITIALIZATION ---
function init() {
    const vSelect = document.getElementById('voiceSelect');
    VOICES.forEach(v => {
        const opt = document.createElement('option');
        opt.value = v; opt.innerText = v;
        vSelect.appendChild(opt);
    });
    vSelect.value = state.selectedVoice;

    populateStyles(TEMPLATES);
    setupListeners();

    // Inisialisasi Select2
    $(document).ready(function() {
        $('#voiceSelect').select2({ width: '100%' });
        $('#styleSelect').select2({ width: '100%' });

        $('#voiceSelect').on('change', function(e) {
            state.selectedVoice = e.target.value;
        });

        $('#styleSelect').on('change', function(e) {
            state.selectedStyle = TEMPLATES.find(t => t.name === e.target.value);
            updateStatusLabel();
        });
    });

    initTheme();
}

function populateStyles(list) {
    const sSelect = document.getElementById('styleSelect');
    sSelect.innerHTML = '';
    list.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.name; opt.innerText = t.name;
        sSelect.appendChild(opt);
    });
}

// --- CORE PROCESS FUNCTIONS ---
async function startProcess() {
    const text = document.getElementById('mainInput').value.trim();
    if (!text || state.isGenerating) return;

    state.isGenerating = true;
    state.shouldStop = false;
    state.results = {};
    document.getElementById('startBtn').innerText = "PROSES...";
    document.getElementById('resultsList').innerHTML = '';
    document.getElementById('masterBox').classList.add('hidden');
    updateProgress(10, "Menganalisis Naskah...");

    try {
        let analyzePrompt = state.mode === "Autentik" 
            ? "TUGAS: BAGI teks input menjadi potongan logis PANJANG 600-1000 karakter. JANGAN ubah kata asli. HASIL JSON: [{'text': '...', 'direction': '...'}]"
            : `TUGAS: BUAT narasi lengkap gaya: ${state.selectedStyle.prompt}. BAGI menjadi potongan 600-1000 karakter. HASIL JSON: [{'text': '...', 'direction': '...'}]`;

        const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=${ACTIVE_API_KEY}`;
        const analyzeRes = await fetchWithRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                contents: [{ parts: [{ text: text }] }],
                systemInstruction: { parts: [{ text: analyzePrompt }] },
                generationConfig: { responseMimeType: "application/json" }
            })
        });

        state.chunks = JSON.parse(analyzeRes.candidates[0].content.parts[0].text);
        updateProgress(20, `Konversi (0/${state.chunks.length})`);

        const batchSize = 3;
        for (let i = 0; i < state.chunks.length; i += batchSize) {
            if (state.shouldStop) break;
            const batch = state.chunks.slice(i, i + batchSize);
            await Promise.all(batch.map((chunk, index) => processTTS(chunk, i + index)));
        }

        if (!state.shouldStop) {
            updateProgress(100, "Selesai!");
            document.getElementById('mergeBtn').disabled = false;
            showFinishModal();
        }
    } catch (err) {
        console.error(err);
        updateProgress(0, "Gagal memproses.");
    } finally {
        state.isGenerating = false;
        document.getElementById('startBtn').innerText = "MULAI PROSES";
    }
}

async function processTTS(chunk, idx) {
    if (state.shouldStop) return;
    const instr = `[SISTEM: Karakter ${state.selectedVoice}. Konteks: ${chunk.direction}. Tempo: ${state.speed}. Energi: ${state.energy}] `;
    const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key=${ACTIVE_API_KEY}`;
    try {
        const result = await fetchWithRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                contents: [{ parts: [{ text: instr + chunk.text }] }],
                generationConfig: { 
                    responseModalities: ["AUDIO"], 
                    speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: state.selectedVoice } } } 
                },
                model: "gemini-2.5-flash-preview-tts"
            })
        });
        const base64Data = result.candidates[0].content.parts[0].inlineData.data;
        const binaryData = atob(base64Data);
        const arrayBuffer = new Uint8Array(binaryData.length);
        for (let i = 0; i < binaryData.length; i++) arrayBuffer[i] = binaryData.charCodeAt(i);
        
        const wavBlob = pcmToWav(arrayBuffer, 24000);
        state.results[idx] = { blob: wavBlob, text: chunk.text };
        addPartUI(idx, chunk.text, wavBlob);
        updateProgress(Math.floor((Object.keys(state.results).length / state.chunks.length) * 80) + 20, `Konversi (${Object.keys(state.results).length}/${state.chunks.length})`);
    } catch (err) { console.error(err); }
}

async function processAI(type, extra = "") {
    const text = document.getElementById('mainInput').value.trim();
    if (!text) return;
    toggleAILoading(true);
    const prompt = type === 'refine' 
        ? "Perbaiki naskah agar enak didengar narasi. Tanda baca harus tepat. Hanya kembalikan teks hasil perbaikan." 
        : `Terjemahkan naskah berikut ke bahasa ${extra}. Hanya kembalikan teks hasil terjemahan.`;
    try {
        const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=${ACTIVE_API_KEY}`;
        const result = await fetchWithRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contents: [{ parts: [{ text: `${prompt}\n\nNASKAH:\n${text}` }] }] })
        });
        const newText = result.candidates?.[0]?.content?.parts?.[0]?.text;
        if (newText) {
            document.getElementById('mainInput').value = newText.trim();
            updateCounters();
        }
    } finally {
        toggleAILoading(false);
    }
}

// --- AUDIO UTILS ---
function pcmToWav(pcmData, sampleRate) {
    const header = new ArrayBuffer(44);
    const view = new DataView(header);
    const writeString = (o, s) => { for (let i = 0; i < s.length; i++) view.setUint8(o + i, s.charCodeAt(i)); };
    writeString(0, 'RIFF'); view.setUint32(4, 32 + pcmData.length, true); writeString(8, 'WAVE'); writeString(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true); view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true); writeString(36, 'data');
    view.setUint32(40, pcmData.length, true);
    return new Blob([header, pcmData], { type: 'audio/wav' });
}

function playAudio(url) {
    stopAudio();
    activeAudio = new Audio(url);
    activeAudio.play();
}

function stopAudio() {
    if (activeAudio) {
        activeAudio.pause();
        activeAudio.currentTime = 0;
        activeAudio = null;
    }
}

async function mergeResults() {
    const sortedIndices = Object.keys(state.results).sort((a, b) => a - b);
    if (sortedIndices.length === 0) return;
    const buffers = [];
    let totalLength = 0;
    const fullText = [];
    for (const idx of sortedIndices) {
        const blob = state.results[idx].blob;
        const buffer = await blob.arrayBuffer();
        const pcm = buffer.slice(44); 
        buffers.push(new Uint8Array(pcm));
        totalLength += pcm.byteLength;
        fullText.push(state.results[idx].text);
    }
    const mergedPcm = new Uint8Array(totalLength);
    let offset = 0;
    for (const b of buffers) { mergedPcm.set(b, offset); offset += b.length; }
    const finalWavBlob = pcmToWav(mergedPcm, 24000);
    const finalUrl = URL.createObjectURL(finalWavBlob);
    const combinedText = fullText.join("\n\n");
    document.getElementById('masterBox').classList.remove('hidden');
    document.getElementById('mergedTextPreview').innerText = combinedText;
    document.getElementById('playMergedBtn').onclick = () => playAudio(finalUrl);
    document.getElementById('saveMergedBtn').onclick = () => {
        const a = document.createElement('a'); a.href = finalUrl; a.download = "Narasi_Final.wav"; a.click();
    };
    document.getElementById('copyMergedBtn').onclick = () => copyToClipboard(combinedText);
}

// --- THEME & UI FUNCTIONS ---
function initTheme() {
    let hue = Math.floor(Math.random() * 360);
    if (hue > 45 && hue < 120) hue += 40; 
    document.documentElement.style.setProperty('--h', hue);
    console.log(`Theme Updated: HSL(${hue}, 75%, 55%)`);
}

function updateCounters() {
    const text = document.getElementById('mainInput').value;
    document.getElementById('charCount').innerText = `${text.length} Karakter`;
    const words = text.trim().split(/\s+/).length;
    const seconds = Math.floor((words / 150) * 60) || 0;
    document.getElementById('estDuration').innerText = `Estimasi: ${seconds}s`;
}

function updateStatusLabel() {
    document.getElementById('configLabel').innerText = `KONFIGURASI: ${state.mode.toUpperCase()} • ${state.selectedStyle.name.toUpperCase()}`;
}

function updateProgress(val, status) {
    document.getElementById('progressBar').style.width = `${val}%`;
    document.getElementById('progressPercent').innerText = `${val}%`;
    document.getElementById('progressStatus').innerText = status;
}

function toggleAILoading(show) {
    const overlay = document.getElementById('aiOverlay');
    if (show) {
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        setTimeout(() => overlay.classList.replace('opacity-0', 'opacity-100'), 10);
    } else {
        overlay.classList.replace('opacity-100', 'opacity-0');
        setTimeout(() => {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }, 500);
    }
}

// --- MODAL CONTROLS ---
function openDevModal() {
    const modal = document.getElementById('devModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    void modal.offsetWidth; 
    modal.classList.replace('opacity-0', 'opacity-100');
    modal.querySelector('.transform').classList.replace('scale-95', 'scale-100');
}

function closeDevModal() {
    const modal = document.getElementById('devModal');
    modal.classList.replace('opacity-100', 'opacity-0');
    modal.querySelector('.transform').classList.replace('scale-100', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 500);
}

function openGuideModal() {
    const modal = document.getElementById('guideModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    void modal.offsetWidth;
    modal.classList.replace('opacity-0', 'opacity-100');
    modal.querySelector('.transform').classList.replace('scale-95', 'scale-100');
}

function closeGuideModal() {
    const modal = document.getElementById('guideModal');
    modal.classList.replace('opacity-100', 'opacity-0');
    modal.querySelector('.transform').classList.replace('scale-100', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 500);
}

function showFinishModal() { document.getElementById('finishModal').classList.remove('hidden'); }
function closeFinishModal() { document.getElementById('finishModal').classList.add('hidden'); }

// --- SIDEBAR & OTHERS ---
function toggleSidebar() {
    state.isSidebarOpen = !state.isSidebarOpen;
    const sidebar = document.getElementById('sidebarMenu');
    const overlay = document.getElementById('sidebarOverlay');
    if (state.isSidebarOpen) {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
}

function stopProcess() {
    state.shouldStop = true;
    updateProgress(0, "Berhenti");
    stopAudio();
}

function clearText() { document.getElementById('mainInput').value = ''; updateCounters(); }

function copyToClipboard(text) {
    const el = document.createElement('textarea'); el.value = text; document.body.appendChild(el); el.select();
    document.execCommand('copy'); document.body.removeChild(el);
}

function addPartUI(idx, text, blob) {
    const url = URL.createObjectURL(blob);
    const list = document.getElementById('resultsList');
    const item = document.createElement('div');
    item.className = 'glass-card p-4 sm:p-5 rounded-2xl animate-fade-in';
    item.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <span class="text-[9px] sm:text-[10px] font-bold text-blue-600 tracking-wider">BAGIAN ${(idx + 1).toString().padStart(2, '0')}</span>
            <div class="flex gap-2">
                <button onclick="playAudio('${url}')" class="w-8 h-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 btn-macos">▶</button>
                <button onclick="stopAudio()" class="w-8 h-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-100 btn-macos">■</button>
                <button onclick="copyToClipboard('${text.replace(/'/g, "\\'")}')" class="w-8 h-8 flex items-center justify-center bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100 btn-macos">📋</button>
                <a href="${url}" download="Part_${idx+1}.wav" class="w-8 h-8 flex items-center justify-center bg-green-50 text-green-600 rounded-lg hover:bg-green-100 btn-macos">💾</a>
            </div>
        </div>
        <p class="text-xs sm:text-[13px] text-gray-700 leading-relaxed">${text}</p>
    `;
    list.appendChild(item);
}

async function fetchWithRetry(url, options, retries = 5, backoff = 1000) {
    for (let i = 0; i < retries; i++) {
        try {
            const response = await fetch(url, options);
            if (response.ok) return await response.json();
            if (response.status === 429 || response.status >= 500) throw new Error('Retry');
            return await response.json();
        } catch (err) {
            if (i === retries - 1) throw err;
            await new Promise(r => setTimeout(r, backoff * Math.pow(2, i)));
        }
    }
}

function setupListeners() {
    document.getElementById('menuBtn').addEventListener('click', toggleSidebar);

    document.querySelectorAll('.segmented-item').forEach(btn => {
        btn.addEventListener('click', function() {
            const parent = this.parentElement;
            parent.querySelectorAll('.segmented-item').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (parent.id === 'energySelector') state.energy = this.dataset.val;
            if (parent.id === 'modeSelector') {
                state.mode = this.dataset.val;
                updateStatusLabel();
                initTheme();
            }
        });
    });

    document.getElementById('styleSearch').addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase();
        const filtered = TEMPLATES.filter(t => t.name.toLowerCase().includes(query));
        populateStyles(filtered);
        $('#styleSelect').select2({ width: '100%' });
    });

    document.getElementById('speedRange').addEventListener('input', (e) => {
        const modes = ["Sangat Lambat", "Lambat", "Normal", "Cepat", "Sangat Cepat"];
        state.speed = modes[parseInt(e.target.value)];
        document.getElementById('speedVal').innerText = state.speed;
    });

    document.getElementById('mainInput').addEventListener('input', updateCounters);
    document.getElementById('startBtn').addEventListener('click', startProcess);
    document.getElementById('stopBtn').addEventListener('click', stopProcess);
    document.getElementById('mergeBtn').addEventListener('click', mergeResults);

    // Modal Background Clicks
    document.getElementById('devModal').addEventListener('click', (e) => { if (e.target.id === 'devModal') closeDevModal(); });
    document.getElementById('guideModal').addEventListener('click', (e) => { if (e.target.id === 'guideModal') closeGuideModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeDevModal(); closeGuideModal(); }
    });
}

// Menjalankan inisialisasi
init();
// Data warna dan deskripsi vokal agar modal terlihat "Premium"
const VOICES_CONFIG = [
    { id: "Zephyr", desc: "Terang", color: "bg-sky-500 border-sky-100 text-sky-600", light: "bg-sky-50" },
    { id: "Puck", desc: "Ceria", color: "bg-orange-500 border-orange-100 text-orange-600", light: "bg-orange-50" },
    { id: "Charon", desc: "Informative", color: "bg-slate-500 border-slate-200 text-slate-600", light: "bg-slate-100" },
    { id: "Kore", desc: "Tegas", color: "bg-rose-500 border-rose-100 text-rose-600", light: "bg-rose-50" },
    { id: "Fenrir", desc: "Antusias", color: "bg-red-500 border-red-100 text-red-600", light: "bg-red-50" },
    { id: "Leda", desc: "Muda", color: "bg-purple-500 border-purple-100 text-purple-600", light: "bg-purple-50" },
    { id: "Orus", desc: "Tegas", color: "bg-indigo-500 border-indigo-100 text-indigo-600", light: "bg-indigo-50" },
    { id: "Aoede", desc: "Ringan", color: "bg-teal-500 border-teal-100 text-teal-600", light: "bg-teal-50" },
    { id: "Callirrhoe", desc: "Santai", color: "bg-green-500 border-green-100 text-green-600", light: "bg-green-50" },
    { id: "Autonoe", desc: "Terang", color: "bg-amber-500 border-amber-100 text-amber-600", light: "bg-amber-50" },
    { id: "Enceladus", desc: "Berdesah", color: "bg-zinc-500 border-zinc-200 text-zinc-600", light: "bg-zinc-100" },
    { id: "Iapetus", desc: "Jelas", color: "bg-cyan-500 border-cyan-100 text-cyan-600", light: "bg-cyan-50" },
    { id: "Umbriel", desc: "Santai", color: "bg-blue-500 border-blue-100 text-blue-600", light: "bg-blue-50" },
    { id: "Algieba", desc: "Lancar", color: "bg-emerald-500 border-emerald-100 text-emerald-600", light: "bg-emerald-50" },
    { id: "Despina", desc: "Lancar", color: "bg-lime-500 border-lime-100 text-lime-600", light: "bg-lime-50" },
    { id: "Erinome", desc: "Jelas", color: "bg-yellow-500 border-yellow-100 text-yellow-600", light: "bg-yellow-50" },
    { id: "Algenib", desc: "Serak", color: "bg-stone-500 border-stone-200 text-stone-600", light: "bg-stone-100" },
    { id: "Rasalgethi", desc: "Informatif", color: "bg-violet-500 border-violet-100 text-violet-600", light: "bg-violet-50" },
    { id: "Laomedeia", desc: "Semangat", color: "bg-fuchsia-500 border-fuchsia-100 text-fuchsia-600", light: "bg-fuchsia-50" },
    { id: "Achernar", desc: "Lembut", color: "bg-pink-500 border-pink-100 text-pink-600", light: "bg-pink-50" },
    { id: "Alnilam", desc: "Tegas", color: "bg-neutral-500 border-neutral-200 text-neutral-600", light: "bg-neutral-100" },
    { id: "Schedar", desc: "Datar", color: "bg-gray-500 border-gray-200 text-gray-600", light: "bg-gray-100" },
    { id: "Gacrux", desc: "Matang", color: "bg-orange-600 border-orange-200 text-orange-700", light: "bg-orange-100" },
    { id: "Pulcherrima", desc: "Maju", color: "bg-rose-600 border-rose-200 text-rose-700", light: "bg-rose-100" },
    { id: "Achird", desc: "Ramah", color: "bg-blue-600 border-blue-200 text-blue-700", light: "bg-blue-100" },
    { id: "Zubenelgenubi", desc: "Kasual", color: "bg-teal-600 border-teal-200 text-teal-700", light: "bg-teal-100" },
    { id: "Vindemiatrix", desc: "Lembut", color: "bg-purple-600 border-purple-200 text-purple-700", light: "bg-purple-100" },
    { id: "Sadachbia", desc: "Lincah", color: "bg-red-600 border-red-200 text-red-700", light: "bg-red-100" },
    { id: "Sadaltager", desc: "Pintar", color: "bg-indigo-600 border-indigo-200 text-indigo-700", light: "bg-indigo-100" },
    { id: "Sulafat", desc: "Hangat", color: "bg-yellow-600 border-yellow-200 text-yellow-700", light: "bg-yellow-100" },
];

const SAMPLE_BASE_URL = "./samples/"; // Ganti dengan URL path audio asli kamu

function openVoiceLibrary() {
    const modal = document.getElementById('voiceLibraryModal');
    const container = document.getElementById('voiceGridContainer');
    
    // Render Kartu jika belum ada
    if (container.innerHTML.trim() === "") {
        VOICES_CONFIG.forEach(v => {
            const card = document.createElement('div');
            const colorParts = v.color.split(' '); // [bg-sky-500, border-sky-100, text-sky-600]
            card.className = `voice-card p-5 rounded-[2rem] border-2 border-opacity-40 flex flex-col items-center text-center transition-all duration-300 hover:shadow-xl hover:-translate-y-2 ${v.light} ${colorParts[1]}`;
            
            card.innerHTML = `
                <div class="w-16 h-16 ${colorParts[0]} rounded-2xl flex items-center justify-center text-white shadow-lg mb-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                </div>
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs">${v.id}</h3>
                <p class="text-[9px] font-bold ${colorParts[2]} uppercase mt-1">(${v.desc})</p>
                <div class="mt-6 flex gap-2 w-full">
                    <button onclick="previewLocalVoice(event, '${v.id}')" class="flex-1 py-2 bg-white/60 border border-slate-200 rounded-xl text-[10px] font-black hover:bg-white transition-all shadow-sm">DENGARKAN</button>
                    <button onclick="selectVoiceFromLibrary('${v.id}')" class="flex-1 py-2 ${colorParts[0]} text-white rounded-xl text-[10px] font-black shadow-md hover:brightness-110 transition-all">PILIH</button>
                </div>
            `;
            container.appendChild(card);
        });
    }

    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.replace('opacity-0', 'opacity-100');
        document.getElementById('libraryContent').classList.replace('scale-95', 'scale-100');
    }, 10);
}

function closeVoiceLibrary() {
    const modal = document.getElementById('voiceLibraryModal');
    modal.classList.replace('opacity-100', 'opacity-0');
    document.getElementById('libraryContent').classList.replace('scale-100', 'scale-95');
    setTimeout(() => modal.classList.add('hidden'), 500);
    document.getElementById('previewAudioElement').pause();
}

function previewLocalVoice(e, id) {
    e.stopPropagation();
    const audio = document.getElementById('previewAudioElement');
    audio.src = `${SAMPLE_BASE_URL}${id}.wav`; // Sesuaikan ekstensi (.mp3 atau .wav)
    audio.play().catch(err => console.log("File audio tidak ditemukan"));
}

function selectVoiceFromLibrary(id) {
    state.selectedVoice = id;
    $('#voiceSelect').val(id).trigger('change');
    closeVoiceLibrary();
}
    </script>
</body>
</html>
