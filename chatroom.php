<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

$lock_body_scroll = true;
$page_title = 'Chat Room';
$meta_description = 'Join the Epstein Suite global chat room to discuss Epstein-related documents, findings, and news with other researchers in real time.';
$og_title = 'Chat Room';
$og_description = $meta_description;

$extra_head_tags = [];
$chatSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'DiscussionForumPosting',
    'name' => 'Epstein Suite Chat Room',
    'url' => 'https://epsteinsuite.com/chatroom.php',
    'description' => $meta_description,
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($chatSchema, JSON_UNESCAPED_SLASHES) . '</script>';

require_once __DIR__ . '/includes/header_suite.php';
?>

<!-- Nickname Modal -->
<div id="nickname-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
        <div class="text-center mb-5">
            <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-3">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900">Join the Chat</h2>
            <p class="text-sm text-slate-500 mt-1">Pick a nickname to start chatting with other researchers</p>
        </div>
        <form id="nickname-form" onsubmit="return false;">
            <input type="text" id="nickname-input" maxlength="30" placeholder="Enter a nickname..."
                class="block w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mb-3"
                autocomplete="off">
            <button type="submit" id="nickname-btn"
                class="w-full py-3 bg-slate-900 text-white font-semibold rounded-xl hover:bg-slate-800 transition-colors text-sm">
                Join Chat
            </button>
        </form>
        <p class="text-xs text-slate-400 text-center mt-3">Be respectful. No harassment or doxxing.</p>
    </div>
</div>

<!-- Chat Layout -->
<div class="flex flex-col flex-1 overflow-hidden">
    <!-- Chat Header Bar -->
    <div class="bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                </svg>
            </div>
            <div>
                <h1 class="text-sm font-bold text-slate-900">Global Chat Room</h1>
                <p class="text-xs text-slate-500">
                    <span id="online-count" class="inline-flex items-center gap-1">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        <span id="online-num">0</span> online
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span id="current-nick" class="text-xs text-slate-500 hidden sm:inline"></span>
            <button onclick="changeNickname()" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Change Name</button>
        </div>
    </div>

    <!-- Messages Area -->
    <div id="messages" class="flex-1 overflow-y-auto px-4 py-4 space-y-1" style="scroll-behavior: smooth;">
        <div id="messages-empty" class="flex items-center justify-center h-full">
            <div class="text-center text-slate-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <p class="text-sm font-medium">No messages yet</p>
                <p class="text-xs mt-1">Be the first to say something!</p>
            </div>
        </div>
    </div>

    <!-- Input Bar -->
    <div class="bg-white border-t border-slate-200 px-4 py-3 flex-shrink-0">
        <form id="chat-form" class="flex items-center gap-2" onsubmit="return false;">
            <input type="text" id="message-input" maxlength="500" placeholder="Type a message..."
                class="flex-1 px-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                autocomplete="off" disabled>
            <button type="submit" id="send-btn"
                class="px-5 py-2.5 bg-slate-900 text-white font-semibold rounded-xl hover:bg-slate-800 transition-colors text-sm disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5"
                disabled>
                <span>Send</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
            </button>
        </form>
    </div>
</div>

<script>
(function() {
    var nickname = localStorage.getItem('chat_nickname') || '';
    var lastMessageId = 0;
    var pollTimer = null;
    var isScrolledUp = false;

    var modal = document.getElementById('nickname-modal');
    var nickInput = document.getElementById('nickname-input');
    var nickForm = document.getElementById('nickname-form');
    var nickBtn = document.getElementById('nickname-btn');
    var msgInput = document.getElementById('message-input');
    var sendBtn = document.getElementById('send-btn');
    var chatForm = document.getElementById('chat-form');
    var messagesDiv = document.getElementById('messages');
    var emptyDiv = document.getElementById('messages-empty');
    var onlineNum = document.getElementById('online-num');
    var currentNick = document.getElementById('current-nick');

    // Color palette for nicknames
    var colors = [
        'bg-red-500', 'bg-blue-500', 'bg-green-500', 'bg-purple-500',
        'bg-amber-500', 'bg-pink-500', 'bg-indigo-500', 'bg-teal-500',
        'bg-orange-500', 'bg-cyan-500', 'bg-rose-500', 'bg-emerald-500'
    ];
    var textColors = [
        'text-red-600', 'text-blue-600', 'text-green-600', 'text-purple-600',
        'text-amber-600', 'text-pink-600', 'text-indigo-600', 'text-teal-600',
        'text-orange-600', 'text-cyan-600', 'text-rose-600', 'text-emerald-600'
    ];

    function hashNick(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) {
            h = ((h << 5) - h) + name.charCodeAt(i);
            h |= 0;
        }
        return Math.abs(h) % colors.length;
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function formatTime(dateStr) {
        var d = new Date(dateStr.replace(' ', 'T') + 'Z');
        var now = new Date();
        var diff = (now - d) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function renderMessage(msg) {
        // System messages (join/leave) get special styling
        if (msg.nickname === '__system__') {
            var div = document.createElement('div');
            div.className = 'flex justify-center py-2';
            div.setAttribute('data-msg-id', msg.id);
            var isJoin = msg.message.indexOf('joined') !== -1;
            var icon = isJoin
                ? '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>'
                : '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>';
            var colorClass = isJoin ? 'text-green-600 bg-green-50 border-green-200' : 'text-slate-500 bg-slate-50 border-slate-200';
            div.innerHTML = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-medium border ' + colorClass + '">'
                + icon + escapeHtml(msg.message) + '</span>';
            return div;
        }

        var ci = hashNick(msg.nickname);
        var isMe = msg.nickname === nickname;
        var div = document.createElement('div');
        div.className = 'flex items-start gap-2.5 py-1.5 ' + (isMe ? 'flex-row-reverse' : '');
        div.setAttribute('data-msg-id', msg.id);

        var avatar = '<div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0 ' + colors[ci] + '">'
            + escapeHtml(msg.nickname.charAt(0).toUpperCase()) + '</div>';

        var bubbleAlign = isMe ? 'items-end' : 'items-start';
        var bubbleBg = isMe ? 'bg-blue-50 border-blue-100' : 'bg-slate-50 border-slate-100';

        var bubble = '<div class="flex flex-col ' + bubbleAlign + ' max-w-[75%] min-w-0">'
            + '<div class="flex items-baseline gap-2 mb-0.5 ' + (isMe ? 'flex-row-reverse' : '') + '">'
            + '<span class="text-xs font-semibold ' + textColors[ci] + '">' + escapeHtml(msg.nickname) + '</span>'
            + '<span class="text-[10px] text-slate-400">' + formatTime(msg.created_at) + '</span>'
            + '</div>'
            + '<div class="px-3 py-2 rounded-xl border ' + bubbleBg + ' text-sm text-slate-800 break-words">'
            + escapeHtml(msg.message)
            + '</div></div>';

        div.innerHTML = avatar + bubble;
        return div;
    }

    function scrollToBottom() {
        if (!isScrolledUp) {
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
    }

    messagesDiv.addEventListener('scroll', function() {
        var threshold = 80;
        isScrolledUp = (messagesDiv.scrollHeight - messagesDiv.scrollTop - messagesDiv.clientHeight) > threshold;
    });

    function appendMessages(msgs) {
        if (msgs.length === 0) return;
        if (emptyDiv) {
            emptyDiv.remove();
            emptyDiv = null;
        }
        msgs.forEach(function(msg) {
            messagesDiv.appendChild(renderMessage(msg));
            var msgId = parseInt(msg.id, 10);
            if (msgId > lastMessageId) lastMessageId = msgId;
        });
        scrollToBottom();
    }

    function fetchMessages() {
        var params = [];
        if (lastMessageId > 0) params.push('after=' + lastMessageId);
        if (nickname) params.push('nick=' + encodeURIComponent(nickname));
        var url = '/api/chat.php' + (params.length ? '?' + params.join('&') : '');
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    appendMessages(data.messages || []);
                    onlineNum.textContent = data.online_count || 0;
                }
            })
            .catch(function() {});
    }

    function sendMessage() {
        var text = msgInput.value.trim();
        if (!text || !nickname) return;

        sendBtn.disabled = true;
        msgInput.disabled = true;

        fetch('/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nickname: nickname, message: text })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.message) {
                msgInput.value = '';
                appendMessages([data.message]);
            } else if (data.error) {
                alert(data.error);
            }
        })
        .catch(function() {
            alert('Failed to send. Try again.');
        })
        .finally(function() {
            sendBtn.disabled = false;
            msgInput.disabled = false;
            msgInput.focus();
        });
    }

    function joinChat(name) {
        nickname = name.trim();
        if (!nickname) return;
        localStorage.setItem('chat_nickname', nickname);
        modal.classList.add('hidden');
        msgInput.disabled = false;
        sendBtn.disabled = false;
        msgInput.focus();
        currentNick.textContent = 'Chatting as ' + nickname;
        currentNick.classList.remove('hidden');

        // Notify server of join, then start polling
        fetch('/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'join', nickname: nickname })
        })
        .then(function(r) { return r.json(); })
        .catch(function() { return {}; })
        .finally(function() {
            fetchMessages();
            pollTimer = setInterval(fetchMessages, 3000);
        });
    }

    function changeNickname() {
        if (pollTimer) clearInterval(pollTimer);
        msgInput.disabled = true;
        sendBtn.disabled = true;
        nickInput.value = nickname;
        modal.classList.remove('hidden');
        nickInput.focus();
        nickInput.select();
    }

    // Nickname form submit
    nickForm.addEventListener('submit', function() {
        joinChat(nickInput.value);
    });
    nickBtn.addEventListener('click', function() {
        joinChat(nickInput.value);
    });

    // Chat form submit
    chatForm.addEventListener('submit', function() {
        sendMessage();
    });
    sendBtn.addEventListener('click', function() {
        sendMessage();
    });

    // Enter key to send (no shift+enter for newline since it's a single-line input)
    msgInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Auto-join if nickname already saved
    if (nickname) {
        joinChat(nickname);
    } else {
        nickInput.focus();
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
