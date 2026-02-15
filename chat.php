<?php
include "admin/fungsi/koneksi.php";
session_start();

// Pengecekan sesi yang ketat
if (!isset($_SESSION['id_login']) || 
    !isset($_SESSION['level']) || 
    $_SESSION['status'] !== 'active' || 
    !in_array($_SESSION['level'], ['guru', 'admin'])) {
    
    header("location: /admin/login.php");
    exit();
}
// 1. Logic Pemilihan API Key (PHP Orisinal)
$sql = mysqli_query($koneksi, "
    SELECT api_key 
    FROM api_keys 
    WHERE usage_count = (SELECT MIN(usage_count) FROM api_keys) 
    ORDER BY RAND() 
    LIMIT 1
");

if (!$sql) {
    die("Error fetching API key: " . mysqli_error($koneksi));
}

$dataApiKey = mysqli_fetch_assoc($sql);

if ($dataApiKey) {
    $apiKey = $dataApiKey['api_key'];
    $updateSql = "UPDATE api_keys SET usage_count = usage_count + 1 WHERE api_key = '$apiKey'";
    if (!mysqli_query($koneksi, $updateSql)) {
        echo "Gagal memperbarui penggunaan API: " . mysqli_error($koneksi);
    }
} else {
    die("No API keys found in the database.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Native Specialist | macOS Edition</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: url('https://images.unsplash.com/photo-1614850523296-d8c1af93d400?q=80&w=2070') no-repeat center center fixed; background-size: cover; height: 100vh; overflow: hidden; }
        
        .glass { background: rgba(255, 255, 255, 0.72); backdrop-filter: blur(25px) saturate(180%); border: 1px solid rgba(255, 255, 255, 0.3); }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        
        /* Loader Animation */
        .mac-loader { display: flex; gap: 4px; align-items: center; }
        .mac-dot { width: 12px; height: 12px; border-radius: 50%; animation: mac-loading 1.4s infinite ease-in-out both; }
        .mac-dot:nth-child(1) { animation-delay: -0.32s; background: #FF5F56; }
        .mac-dot:nth-child(2) { animation-delay: -0.16s; background: #FFBD2E; }
        .mac-dot:nth-child(3) { background: #27C93F; }
        @keyframes mac-loading { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; } 40% { transform: scale(1.1); opacity: 1; } }

        /* Code Block */
        .code-block-container { background: #1e1e1e; border-radius: 12px; overflow: hidden; margin: 1.25rem 0; box-shadow: 0 15px 35px rgba(0,0,0,0.3); }
        .code-header { background: #2d2d2d; border-bottom: 1px solid #3d3d3d; padding: 0.6rem 1rem; display: flex; justify-content: space-between; align-items: center; }
        pre.code-display { padding: 1.25rem; color: #d4d4d4; font-size: 13px; line-height: 1.6; font-family: 'Fira Code', monospace; }
        
        .message-animate { animation: slideUp 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        
        .modal-show { opacity: 1 !important; pointer-events: auto !important; transform: scale(1) !important; }
        
    </style>
</head>
<body class="flex flex-col items-center p-4 md:p-6 h-screen">

  <nav
  class="w-full max-w-7xl
         glass rounded-2xl
         px-5 py-3
         flex items-center justify-between
         mb-4 shadow-2xl z-40">

  <!-- LEFT -->
  <div class="flex items-center gap-4">

    <!-- macOS window dots -->
    <div class="flex gap-1.5 shrink-0">
      <div class="w-3 h-3 rounded-full bg-[#FF5F56]"></div>
      <div class="w-3 h-3 rounded-full bg-[#FFBD2E]"></div>
      <div class="w-3 h-3 rounded-full bg-[#27C93F]"></div>
    </div>

    <div class="h-6 w-px bg-black/10"></div>

    <!-- LOGO -->
    <div
      class="bg-white/20 backdrop-blur-md
             p-2 rounded-xl
             ring-1 ring-white/20
             shadow-sm shrink-0">
      <img
        src="admin/foto/<?php echo $ds['logo']; ?>"
        alt="Logo"
        class="w-10 h-10 object-contain"
        onerror="this.onerror=null;this.src='/assets/img/logo-default.png';">
    </div>

    <!-- TITLE + STATUS -->
    <div class="flex flex-col leading-tight">
      <span class="text-sm font-bold text-gray-800">
        PHP AI Specialist <?php echo $ds['nama']; ?>
      </span>
      <div class="flex items-center gap-1.5 mt-1">
        <div id="status-dot"
             class="w-1.5 h-1.5 rounded-full bg-yellow-500"></div>
        <span id="status-text"
              class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">
          Ready
        </span>
      </div>
    </div>

  </div>

  <!-- RIGHT ACTIONS -->
  <div class="flex items-center gap-1">
    <button id="open-history-btn"
      class="p-2 hover:bg-black/5 rounded-lg text-gray-600">
      <i data-lucide="archive" class="w-4 h-4"></i>
    </button>

    <button id="save-session-btn"
      class="p-2 hover:bg-black/5 rounded-lg text-blue-600">
      <i data-lucide="save" class="w-4 h-4"></i>
    </button>

    <button id="sync-btn"
      class="p-2 hover:bg-black/5 rounded-lg text-gray-600">
      <i data-lucide="database" class="w-4 h-4"></i>
    </button>

    <button id="new-session-btn"
      class="p-2 hover:bg-black/5 rounded-lg text-red-500">
      <i data-lucide="plus-circle" class="w-4 h-4"></i>
    </button>
  </div>

</nav>

    <main id="chat-container" class="w-full max-w-7xl flex-1 overflow-y-auto scrollbar-hide px-2">
        <div id="messages-list" class="space-y-6 pb-32"></div>
    </main>

    <footer class="w-full max-w-7xl fixed bottom-8 px-4">
        <div class="glass rounded-[2.5rem] p-3 shadow-2xl border border-white/50">
            <form id="chat-form" class="flex items-end gap-2 px-1" onsubmit="return false;">
                <input type="file" id="file-input" class="hidden">
                <button type="button" id="attach-btn" class="p-3 text-gray-500 hover:text-blue-600 transition-all"><i data-lucide="paperclip" class="w-5 h-5"></i></button>
                <textarea id="user-input" placeholder="Tulis pesan..." class="flex-1 bg-transparent border-none focus:ring-0 text-sm py-3 resize-none max-h-40 outline-none font-medium" rows="1"></textarea>
                <button type="button" id="submit-btn" class="p-3.5 bg-blue-600 text-white rounded-full hover:bg-blue-700 active:scale-90 shadow-lg shadow-blue-400/40 transition-all">
                    <i data-lucide="arrow-up" class="w-5 h-5"></i>
                </button>
            </form>
        </div>
    </footer>

    <div id="history-modal" class="fixed inset-0 bg-black/30 backdrop-blur-sm z-50 flex items-center justify-center opacity-0 pointer-events-none scale-95 transition-all duration-300">
        <div class="glass w-full max-w-md rounded-3xl p-6 shadow-2xl border border-white/50">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-widest flex items-center gap-2"><i data-lucide="history" class="w-4 h-4"></i> Riwayat Sesi</h3>
                <button id="close-modal-btn" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div id="history-list" class="space-y-3 max-h-72 overflow-y-auto scrollbar-hide"></div>
        </div>
    </div>

    <script>
        const apiKey = "<?php echo $apiKey; ?>";
        const GEMINI_MODEL = "gemini-2.5-flash-preview-09-2025";
        
        let dbStructure = "STRUKTUR DATABASE BELUM DISINKRONKAN.";
        let chatHistory = [];
        let attachedFile = null;
        let isProcessing = false;

        const messagesList = document.getElementById('messages-list');
        const userInput = document.getElementById('user-input');
        const submitBtn = document.getElementById('submit-btn');
        const statusDot = document.getElementById('status-dot');
        const statusText = document.getElementById('status-text');
        const historyModal = document.getElementById('history-modal');
        const historyList = document.getElementById('history-list');

        lucide.createIcons();

        // --- FUNGSI SYNC DATABASE (ORISINAL) ---
        async function syncDatabaseStructure() {
            try {
                statusDot.className = "w-2 h-2 rounded-full bg-yellow-500 animate-pulse";
                statusText.innerText = "Syncing...";
                const response = await fetch('get_db_structure.php');
                if (!response.ok) throw new Error('Gagal mengambil data struktur');
                const data = await response.json();
                if (data.structure) {
                    dbStructure = data.structure;
                    statusDot.className = "w-2 h-2 rounded-full bg-green-500";
                    statusText.innerText = "DB Sinkron";
                } else {
                    throw new Error(data.error || 'Struktur kosong');
                }
            } catch (error) {
                dbStructure = "GAGAL SINKRONISASI: " + error.message;
                statusDot.className = "w-2 h-2 rounded-full bg-red-500";
                statusText.innerText = "Gagal Sinkron";
            }
        }

        // --- MANAJEMEN SESI ---
        document.getElementById('save-session-btn').onclick = () => {
            if (chatHistory.length === 0) return;
            const title = prompt("Judul Sesi:", "Proyek PHP - " + new Date().toLocaleTimeString());
            if (title) {
                const sessions = JSON.parse(localStorage.getItem('expert_sessions') || '[]');
                sessions.push({ id: Date.now(), title, history: chatHistory });
                localStorage.setItem('expert_sessions', JSON.stringify(sessions));
                alert("Sesi disimpan!");
            }
        };

        document.getElementById('open-history-btn').onclick = () => {
            const sessions = JSON.parse(localStorage.getItem('expert_sessions') || '[]');
            historyList.innerHTML = sessions.length ? '' : '<p class="text-xs text-center text-gray-400 py-6">Kosong.</p>';
            sessions.forEach(s => {
                const item = document.createElement('div');
                item.className = "flex justify-between items-center p-4 bg-white/40 hover:bg-white/80 rounded-2xl cursor-pointer border border-white/50 group";
                item.innerHTML = `<div class="flex-1" onclick="loadSession(${s.id})"><p class="text-xs font-bold text-gray-800">${s.title}</p></div><button onclick="deleteSession(${s.id})" class="text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all p-1"><i data-lucide="trash-2" class="w-4 h-4"></i></button>`;
                historyList.appendChild(item);
            });
            lucide.createIcons();
            historyModal.classList.add('modal-show');
        };

        window.loadSession = (id) => {
            const s = JSON.parse(localStorage.getItem('expert_sessions')).find(x => x.id === id);
            if (s) { chatHistory = s.history; messagesList.innerHTML = ''; chatHistory.forEach(m => createMessageElement(m.role === 'user' ? 'user' : 'assistant', m.content)); }
            historyModal.classList.remove('modal-show');
        };

        window.deleteSession = (id) => { if(confirm("Hapus?")) { localStorage.setItem('expert_sessions', JSON.stringify(JSON.parse(localStorage.getItem('expert_sessions')).filter(x => x.id !== id))); document.getElementById('open-history-btn').click(); } };
        document.getElementById('close-modal-btn').onclick = () => historyModal.classList.remove('modal-show');

        // --- CORE UI ---
        function createMessageElement(role, content) {
            const isUser = role === 'user';
            const div = document.createElement('div');
            div.className = `flex ${isUser ? 'justify-end' : 'justify-start'} message-animate`;
            div.innerHTML = `<div class="max-w-[85%] ${isUser ? 'bg-blue-600 text-white rounded-tr-none' : 'glass text-gray-800 rounded-tl-none'} p-4 rounded-3xl shadow-xl border border-white/30 text-sm leading-relaxed">${parseMarkdown(content)}</div>`;
            messagesList.appendChild(div);
            lucide.createIcons();
            document.getElementById('chat-container').scrollTo({ top: messagesList.scrollHeight, behavior: 'smooth' });
        }

        function createLoadingElement() {
            const div = document.createElement('div');
            div.id = "loader-msg";
            div.className = "flex justify-start message-animate";
            div.innerHTML = `<div class="glass p-4 rounded-3xl rounded-tl-none border border-white/30 flex items-center gap-3"><div class="mac-loader"><div class="mac-dot"></div><div class="mac-dot"></div><div class="mac-dot"></div></div><span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?php echo $ds['admin']; ?> berfikir...</span></div>`;
            messagesList.appendChild(div);
            return div;
        }

        function parseMarkdown(text) {
            if (!text) return "";
            return text.split(/(```[\s\S]*?```)/g).map(part => {
                if (part.startsWith('```')) {
                    const match = part.match(/```(\w*)\n([\s\S]*?)```/);
                    const lang = match ? match[1] : 'code';
                    const code = match ? match[2].trim() : part.slice(3, -3).trim();
                    return `<div class="code-block-container"><div class="code-header"><span class="text-[10px] text-gray-400 font-bold uppercase">${lang}</span><button onclick="copyToClipboard('${btoa(unescape(encodeURIComponent(code)))}', this)" class="text-gray-400 hover:text-white flex items-center gap-1 text-[10px] font-bold transition-all"><i data-lucide="copy" class="w-3 h-3"></i> SALIN</button></div><pre class="code-display"><code>${code.replace(/&/g, "&amp;").replace(/</g, "&lt;")}</code></pre></div>`;
                }
                return part.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
            }).join('');
        }

        function copyToClipboard(base64, btn) {
            const code = decodeURIComponent(escape(atob(base64)));
            navigator.clipboard.writeText(code).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = `<i data-lucide="check" class="w-3 h-3 text-green-400"></i> COPIED`;
                lucide.createIcons();
                setTimeout(() => { btn.innerHTML = original; lucide.createIcons(); }, 2000);
            });
        }

        async function handleSendMessage() {
            const text = userInput.value.trim();
            if (!text || isProcessing) return;
            isProcessing = true; submitBtn.disabled = true;

            createMessageElement('user', text);
            chatHistory.push({ role: 'user', content: text });
            userInput.value = '';

            const loader = createLoadingElement();

            try {
                // --- systemPrompt Orisinal ---
                const systemPrompt = `ROLE: SENIOR FULL-STACK DEVELOPER (SPECIALIST PHP NATIVE)

PRINSIP INTERAKSI SANGAT KETAT:
1. SATU SESI SATU TOPIK: Selalu perhatikan konteks percakapan terakhir.
2. DISKUSI vs KODE: 
   - JIKA user bertanya tentang "cara kerja", "belajar", "konsep", "saran", atau diskusi teoritis, JAWAB dengan NARASI TEKSTUAL terstruktur (gunakan heading ###, poin-poin, dan bold).
   - DILARANG KERAS memberikan blok kode (markdown code blocks) jika user hanya ingin berdiskusi atau belajar konsep.
3. KODE HANYA JIKA DIMINTA EKSPLISIT:
   - Hanya berikan blok kode jika user menggunakan kata kerja perintah: "buatkan kode...", "tulis skrip...", "codingkan...".
   - Gunakan format markdown yang rapi.

ATURAN TEKNIS (KHUSUS SAAT ADA PERINTAH KODE):
- Gunakan include \$_SERVER['DOCUMENT_ROOT'] . "/admin/fungsi/koneksi.php"; di baris pertama blok PHP.
- Gunakan variabel \$koneksi.
- Gunakan Prepared Statements (mysqli_prepare/bind_param).
- Gunakan Tailwind CSS dengan mengikuti tren layout kekinian.
- akhir block js dengan kode (function() {
    // 1. Matikan Klik Kanan
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    // 2. Blokir Shortcut Keyboard (F12, Ctrl+Shift+I, Ctrl+U, dll)
    document.onkeydown = function(e) {
        if (
            e.keyCode === 123 || // F12
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || // Ctrl+Shift+I/J/C
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

STRUKTUR DATABASE AKTIF:
${dbStructure}`;
                
                const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${GEMINI_MODEL}:generateContent?key=${apiKey}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ contents: chatHistory.map(m => ({ role: m.role === 'user' ? 'user' : 'model', parts: [{ text: m.content }] })), systemInstruction: { parts: [{ text: systemPrompt }] } })
                });
                const data = await response.json();
                messagesList.removeChild(loader);
                const aiRes = data.candidates[0].content.parts[0].text;
                createMessageElement('assistant', aiRes);
                chatHistory.push({ role: 'assistant', content: aiRes });
            } catch (e) {
                messagesList.removeChild(loader);
                createMessageElement('assistant', "Error.");
            } finally { isProcessing = false; submitBtn.disabled = false; }
        }

        submitBtn.onclick = handleSendMessage;
        userInput.onkeydown = (e) => { if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSendMessage(); } };
        document.getElementById('new-session-btn').onclick = () => { if(confirm("Reset?")) { messagesList.innerHTML = ''; chatHistory = []; } };
        document.getElementById('sync-btn').onclick = syncDatabaseStructure;

        window.onload = () => { syncDatabaseStructure(); createMessageElement('assistant', "Selamat Datang Saya <?php echo $ds['admin']; ?> AI assisten Koding anda!"); };
    </script>
</body>
</html>
