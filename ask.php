<?php
declare(strict_types=1);

$page_title = 'Ask Epstein Files';
$meta_description = 'Conversationally search the Epstein transparency archive with citations back to original documents.';
$canonical_url = 'https://epsteinsuite.com/ask.php';
$noindex = false;
$extra_head_tags = $extra_head_tags ?? [];

$softwareSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'Ask Epstein Suite',
    'url' => 'https://epsteinsuite.com/ask.php',
    'applicationCategory' => 'ResearchApplication',
    'operatingSystem' => 'Web',
    'description' => 'Ask Epstein Suite is an AI-powered research assistant that answers questions about the DOJ Epstein transparency archive with citations back to the source PDFs.',
    'offers' => [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'USD',
        'category' => 'Free',
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'Epstein Suite',
        'url' => 'https://epsteinsuite.com',
    ],
    'featureList' => [
        'Full-text search across DOJ data sets, emails, and flight logs',
        'Grounded answers with document citations',
        'Link-outs to the original PDF or email record',
    ],
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($softwareSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once __DIR__ . '/includes/header_suite.php';
?>

<style>
    .typing-indicator {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        min-width: 160px;
    }
    .typing-bubble {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }
    .typing-bubble .dot {
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 9999px;
        background-color: #94a3b8;
        animation: typingPulse 1.2s infinite ease-in-out;
    }
    .typing-bubble .dot:nth-child(2) { animation-delay: 0.15s; }
    .typing-bubble .dot:nth-child(3) { animation-delay: 0.3s; }

    @keyframes typingPulse {
        0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
        40% { transform: scale(1); opacity: 1; }
    }
</style>

<main class="flex-1 bg-slate-50 overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 h-full min-h-[calc(100vh-4rem)] flex flex-col">
        <div class="flex-1 flex flex-col lg:flex-row gap-6 h-full">
            <div class="flex-1 flex flex-col gap-6">
                <!-- Knowledge rail -->
                <section class="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 text-white p-6 shadow-xl relative overflow-hidden">
                    <div class="absolute inset-y-0 right-0 w-1/2 bg-white/5 blur-3xl"></div>
                    <p class="text-xs tracking-[0.3em] uppercase text-blue-200">Epstein Suite Labs</p>
                    <h1 class="text-2xl font-semibold mt-3">Ask Epstein Files</h1>
                    <p class="text-sm text-blue-100 mt-3 leading-relaxed">
                        Converse with the archive, surface corroborated facts, and link directly to the source PDFs, mailboxes, and flight manifests.
                    </p>
                    <ul class="mt-5 space-y-2 text-sm text-blue-100">
                        <li class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-300"></span> Cite every answer</li>
                        <li class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-300"></span> Blend DOJ, FOIA, and flight manifests</li>
                        <li class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-pink-300"></span> Link you back to the original PDFs</li>
                    </ul>
                </section>

                <!-- Chat panel -->
                <section class="flex-1 bg-white rounded-3xl shadow-xl border border-slate-100 flex flex-col overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Epstein Suite Answers</p>
                        <h2 class="text-lg font-semibold text-slate-900">Conversation</h2>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-400">Logged for reporting</p>
                        <p class="text-sm font-mono text-emerald-500" id="sessionTag">Session —</p>
                    </div>
                </div>

                <div id="chatWindow" class="flex-1 overflow-y-auto px-4 sm:px-6 py-6 space-y-4 bg-slate-50/70 min-h-0 max-h-[calc(100vh-16rem)]">
                    <div class="flex justify-start">
                        <div class="max-w-xl rounded-2xl rounded-tl-sm bg-white border border-slate-200 p-4 shadow-sm">
                            <p class="text-sm text-slate-800">
                                Ask anything about the DOJ document sets, FBI Vault files, email extractions, or flight manifests. I’ll cite the primary sources and link you back to the document viewer.
                            </p>
                            <p class="text-xs text-slate-500 mt-3">Example: “Cross-reference the 2004 flights with the contact notes mentioning Palm Beach.”</p>
                        </div>
                    </div>
                </div>

                <form id="askForm" class="border-t border-slate-100 p-4 space-y-3 bg-white">
                    <div class="relative">
                        <textarea id="questionInput" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50/50 px-4 py-3 pr-16 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ask about people, flights, documents, financial trails…" required></textarea>
                        <button type="submit" id="sendBtn" class="absolute bottom-3 right-3 bg-blue-600 text-white rounded-full w-10 h-10 flex items-center justify-center shadow-lg hover:bg-blue-700 focus:outline-none">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M5 12h14M15 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                    <div class="flex items-center justify-between text-xs text-slate-500">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            <span>Responses cite original documents.</span>
                        </div>
                        <button type="button" id="clearChat" class="text-slate-400 hover:text-slate-600">Clear thread</button>
                    </div>
                </form>
                </section>
            </div>

            <aside class="lg:w-80 flex-shrink-0 space-y-6 overflow-y-auto max-h-[calc(100vh-8rem)] pr-1">
                <section class="rounded-2xl bg-white shadow border border-slate-100 p-5 space-y-4" id="insightsPanel">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-800">Archive Pulse</h2>
                        <span class="text-xs text-slate-400" id="insightsUpdated">Refreshing…</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-center" id="insightsStats">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-[11px] uppercase tracking-wide text-slate-500">Docs</p>
                            <p class="text-xl font-semibold text-slate-900" data-stat="documents">—</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-[11px] uppercase tracking-wide text-slate-500">AI Summaries</p>
                            <p class="text-xl font-semibold text-slate-900" data-stat="processed">—</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-[11px] uppercase tracking-wide text-slate-500">Entities</p>
                            <p class="text-xl font-semibold text-slate-900" data-stat="entities">—</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-[11px] uppercase tracking-wide text-slate-500">Flights</p>
                            <p class="text-xl font-semibold text-slate-900" data-stat="flights">—</p>
                        </div>
                    </div>
                    <div id="insightsTopEntities" class="space-y-2 text-sm text-slate-600">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Most referenced</p>
                        <div class="flex flex-wrap gap-2" data-role="chips"></div>
                    </div>
                </section>

                <section class="rounded-2xl bg-white shadow border border-slate-100 p-5">
                    <h3 class="text-sm font-semibold text-slate-800">Need inspiration?</h3>
                    <p class="text-xs text-slate-500 mt-2">Tap to drop a prompt into the composer.</p>
                    <div class="mt-4 flex flex-wrap gap-2" id="promptSuggestions">
                        <?php
                        $suggestions = [
                            'Summarize what the 2006 Florida plea agreement reveals about prosecutors.',
                            'List flights in 2002 where both Epstein and Maxwell are recorded passengers.',
                            'Show emails connecting JPMorgan executives to the estate.',
                            'What did Data Set 5 document 327 say about financial transfers?',
                            'Explain how the Masseuse List cross-references the contact book.'
                        ];
                        foreach ($suggestions as $suggestion): ?>
                            <button type="button" class="px-3 py-2 rounded-full bg-slate-100 text-slate-700 text-xs hover:bg-slate-200" data-suggestion><?= htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8') ?></button>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</main>

<script>
(() => {
    const chatWindow = document.getElementById('chatWindow');
    const askForm = document.getElementById('askForm');
    const questionInput = document.getElementById('questionInput');
    const sendBtn = document.getElementById('sendBtn');
    const clearBtn = document.getElementById('clearChat');
    const sessionTag = document.getElementById('sessionTag');
    const suggestions = document.querySelectorAll('[data-suggestion]');
    const insightsUpdated = document.getElementById('insightsUpdated');
    const insightsStats = document.querySelectorAll('#insightsStats [data-stat]');
    const insightsChipContainer = document.querySelector('#insightsTopEntities [data-role="chips"]');

    let sessionToken = null;
    let isSending = false;

    const scrollToBottom = () => {
        requestAnimationFrame(() => {
            chatWindow.scrollTop = chatWindow.scrollHeight;
        });
    };

    const createMessageBubble = ({ role, content, citations = [], context = [], pending = false }) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex ' + (role === 'user' ? 'justify-end' : 'justify-start');

        const bubble = document.createElement('div');
        bubble.className = [
            'max-w-2xl p-4 rounded-2xl shadow-sm border',
            role === 'user' ? 'bg-blue-600 text-white border-blue-600 rounded-tr-sm' : 'bg-white border-slate-200 rounded-tl-sm'
        ].join(' ');

        if (pending) {
            bubble.innerHTML = `
                <div class="typing-indicator text-slate-500 text-xs">
                    <div class="typing-bubble">
                        <span class="dot"></span>
                        <span class="dot"></span>
                        <span class="dot"></span>
                    </div>
                    <div class="typing-wave">
                        <span></span>
                        <span></span>
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">Retrieving evidence…</p>
                </div>`;
        } else {
            bubble.innerHTML = content;
        }

        wrapper.appendChild(bubble);

        if (citations.length && !pending) {
            const citeList = document.createElement('div');
            citeList.className = 'mt-3 flex flex-wrap gap-2 text-xs';
            citations.forEach((cite) => {
                const link = document.createElement('a');
                const docId = cite.document_id;
                const page = cite.page_number ? `&p=${encodeURIComponent(cite.page_number)}` : '';
                link.href = `/document.php?id=${encodeURIComponent(docId)}${page}`;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'px-2 py-1 rounded-full border border-slate-200 text-slate-600 bg-slate-50 hover:border-blue-300 hover:text-blue-600 transition';
                link.textContent = `Doc #${docId}${cite.page_number ? ` · p${cite.page_number}` : ''}`;
                citeList.appendChild(link);
            });
            bubble.appendChild(citeList);
        }

        if (context.length && !pending) {
            const grouped = new Map();
            context.forEach((chunk) => {
                const id = chunk.document_id;
                if (!id) return;
                if (!grouped.has(id)) {
                    grouped.set(id, {
                        title: chunk.title || `Document #${id}`,
                        dataSet: chunk.data_set,
                        docId: id,
                        pages: new Set(),
                        snippets: [],
                        source: chunk.source || 'summary'
                    });
                }
                const entry = grouped.get(id);
                if (chunk.page_number) entry.pages.add(chunk.page_number);
                if (chunk.snippet) entry.snippets.push(chunk.snippet.trim());
            });

            if (grouped.size) {
                const details = document.createElement('details');
                details.className = 'mt-4 bg-slate-50 border border-slate-200 rounded-xl';
                const summary = document.createElement('summary');
                summary.className = 'px-4 py-2 text-xs text-slate-600 cursor-pointer select-none';
                summary.textContent = 'Evidence summaries (' + grouped.size + ')';
                details.appendChild(summary);

                const list = document.createElement('div');
                list.className = 'divide-y divide-slate-200';

                grouped.forEach((entry) => {
                    const row = document.createElement('div');
                    row.className = 'px-4 py-3 text-xs text-slate-600 space-y-2';
                    const pages = Array.from(entry.pages).sort((a, b) => a - b);
                    const snippetPreview = entry.snippets[0] ? entry.snippets[0].slice(0, 320) + (entry.snippets[0].length > 320 ? '…' : '') : 'No snippet available.';
                    row.innerHTML = `
                        <div class="flex items-center justify-between text-[11px] uppercase tracking-wide text-slate-400">
                            <span>${entry.title}</span>
                            <span>${entry.source.toUpperCase()}${pages.length ? ' · Page ' + pages.slice(0, 3).join(', ') : ''}</span>
                        </div>
                        <p class="font-mono whitespace-pre-wrap">${snippetPreview}</p>
                        <div>
                            <a href="/document.php?id=${entry.docId}${pages.length ? `&p=${pages[0]}` : ''}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-600 hover:underline">
                                View document →
                            </a>
                        </div>
                    `;
                    list.appendChild(row);
                });

                details.appendChild(list);
                bubble.appendChild(details);
            }
        }

        const renderEntityChips = (entities = []) => {
            if (!entities.length) return;
            const unique = Array.from(new Set(entities)).slice(0, 6);
            if (!unique.length) return;
            const container = document.createElement('div');
            container.className = 'mt-4';
            const label = document.createElement('p');
            label.className = 'text-[11px] uppercase tracking-wide text-slate-400 mb-2';
            label.textContent = 'Referenced entities';
            container.appendChild(label);
            const chipRow = document.createElement('div');
            chipRow.className = 'flex flex-wrap gap-2';
            unique.forEach((name) => {
                const chip = document.createElement('a');
                chip.href = `/?q=${encodeURIComponent(name)}`;
                chip.target = '_blank';
                chip.rel = 'noopener';
                chip.className = 'px-3 py-1.5 rounded-full border border-slate-200 bg-white text-xs text-slate-600 hover:border-blue-300 hover:text-blue-600 transition';
                chip.textContent = name;
                chipRow.appendChild(chip);
            });
            container.appendChild(chipRow);
            bubble.appendChild(container);
        };

        bubble.renderEntities = renderEntityChips;

        chatWindow.appendChild(wrapper);
        scrollToBottom();
        return bubble;
    };

    const describeAskError = (error) => {
        const status = error?.status ?? error?.statusCode ?? 0;
        const serverNote = typeof error?.message === 'string' ? error.message.trim() : '';
        let title = 'We hit a snag answering that.';
        let detail = serverNote || 'The archive needs another moment before it can cite the primary sources.';
        let suggestions = [];
        let allowRetry = true;

        if (status === 202) {
            title = 'Evidence still syncing';
            detail = 'Those specific files are still moving through OCR + AI summaries. As soon as they finish we’ll cite them here.';
            suggestions = [
                'List flights from 2002 mentioning Epstein',
                'Show the most mentioned entities this week',
                'Which documents mention the Florida plea agreement?'
            ];
        } else if (status === 422) {
            title = 'Let’s add a bit more detail';
            detail = 'Try including a person, time window, or document set so we can zero in on the right exhibits.';
            suggestions = [
                'Summarize the 2006 Florida plea agreement',
                'Cross-reference Palm Beach notes with 2004 flights',
                'Show emails connecting JPMorgan to the estate'
            ];
            allowRetry = false;
        } else if (status === 503) {
            title = 'AI service briefly throttled';
            detail = 'OpenAI paused this request for a moment. Give it a few seconds and try again.';
        } else if (status >= 500 || status === 0) {
            title = 'The archive blinked';
            detail = 'The evidence engine paused before it could finish citing the documents. Give it a moment and try again.';
        } else if (!serverNote) {
            detail = 'The suite could not finish that question. Please try again.';
        }

        return { status, title, detail, suggestions, allowRetry, serverNote };
    };

    const sendQuestion = async (question) => {
        if (isSending) return;
        isSending = true;
        sendBtn.disabled = true;

        createMessageBubble({ role: 'user', content: `<p class="text-sm leading-relaxed">${question}</p>` });
        const pendingBubble = createMessageBubble({ role: 'assistant', content: '', pending: true });

        try {
            const response = await fetch('/api/ask.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question, session_token: sessionToken })
            });

            const raw = await response.text();
            let data = {};
            try {
                data = raw ? JSON.parse(raw) : {};
            } catch (parseErr) {
                const err = new Error('Ask API did not return valid JSON.');
                err.status = response.status;
                throw err;
            }

            if (!response.ok || !data.ok) {
                const err = new Error((data && typeof data.error === 'string' && data.error.trim().length) ? data.error.trim() : 'Unable to process question.');
                err.status = response.status;
                err.payload = data;
                throw err;
            }

            sessionToken = data.session_token || sessionToken;
            sessionTag.textContent = sessionToken ? sessionToken.slice(0, 8) + '…' : 'Session —';

            pendingBubble.innerHTML = data.answer_html || '<p class="text-sm text-slate-600">No answer returned.</p>';

            if (data.citations && data.citations.length) {
                const citeList = document.createElement('div');
                citeList.className = 'mt-3 flex flex-wrap gap-2 text-xs';
                data.citations.forEach((cite) => {
                    const link = document.createElement('a');
                    const docId = cite.document_id;
                    const page = cite.page_number ? `&p=${encodeURIComponent(cite.page_number)}` : '';
                    link.href = `/document.php?id=${encodeURIComponent(docId)}${page}`;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.className = 'px-2 py-1 rounded-full border border-slate-200 text-slate-600 bg-slate-50 hover:border-blue-300 hover:text-blue-600 transition';
                    link.textContent = `Doc #${docId}${cite.page_number ? ` · p${cite.page_number}` : ''}`;
                    citeList.appendChild(link);
                });
                pendingBubble.appendChild(citeList);
            }

            if (data.context && data.context.length) {
                pendingBubble.renderEntities?.(data.entities || []);
                const grouped = new Map();
                data.context.forEach((chunk) => {
                    const id = chunk.document_id;
                    if (!id) return;
                    if (!grouped.has(id)) {
                        grouped.set(id, {
                            title: chunk.title || `Document #${id}`,
                            dataSet: chunk.data_set,
                            docId: id,
                            pages: new Set(),
                            snippets: [],
                            source: chunk.source || 'summary'
                        });
                    }
                    const entry = grouped.get(id);
                    if (chunk.page_number) entry.pages.add(chunk.page_number);
                    if (chunk.snippet) entry.snippets.push(chunk.snippet.trim());
                });

                if (grouped.size) {
                    const details = document.createElement('details');
                    details.className = 'mt-4 bg-slate-50 border border-slate-200 rounded-xl';
                    const summary = document.createElement('summary');
                    summary.className = 'px-4 py-2 text-xs text-slate-600 cursor-pointer select-none';
                    summary.textContent = 'Evidence summaries (' + grouped.size + ')';
                    details.appendChild(summary);

                    const list = document.createElement('div');
                    list.className = 'divide-y divide-slate-200';
                    grouped.forEach((entry) => {
                        const pages = Array.from(entry.pages).sort((a, b) => a - b);
                        const snippetPreview = entry.snippets[0] ? entry.snippets[0].slice(0, 320) + (entry.snippets[0].length > 320 ? '…' : '') : 'No snippet available.';
                        const row = document.createElement('div');
                        row.className = 'px-4 py-3 text-xs text-slate-600 space-y-2';
                        row.innerHTML = `
                            <div class="flex items-center justify-between text-[11px] uppercase tracking-wide text-slate-400">
                                <span>${entry.title}</span>
                                <span>${entry.source.toUpperCase()}${pages.length ? ' · Page ' + pages.slice(0, 3).join(', ') : ''}</span>
                            </div>
                            <p class="font-mono whitespace-pre-wrap">${snippetPreview}</p>
                            <div>
                                <a href="/document.php?id=${entry.docId}${pages.length ? `&p=${pages[0]}` : ''}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-600 hover:underline">
                                    View document →
                                </a>
                            </div>
                        `;
                        list.appendChild(row);
                    });

                    details.appendChild(list);
                    pendingBubble.appendChild(details);
                }
            } else {
                pendingBubble.renderEntities?.(data.entities || []);
            }

            if (data.follow_up_questions && data.follow_up_questions.length) {
                const follow = document.createElement('div');
                follow.className = 'mt-4 flex flex-wrap gap-2';
                data.follow_up_questions.forEach((item) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'px-3 py-1.5 rounded-full text-xs border border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-600';
                    btn.textContent = item;
                    btn.addEventListener('click', () => {
                        questionInput.value = item;
                        questionInput.focus();
                    });
                    follow.appendChild(btn);
                });
                pendingBubble.appendChild(follow);
            }

            const linkBack = document.createElement('p');
            linkBack.className = 'mt-4 text-[11px] text-slate-400';
            linkBack.innerHTML = 'Share this beta at <a href="https://epsteinsuite.com/ask.php" class="text-blue-600 hover:underline" target="_blank" rel="noopener">epsteinsuite.com/ask.php</a>.';
            pendingBubble.appendChild(linkBack);
        } catch (error) {
            const friendly = describeAskError(error);
            const systemNote = friendly.serverNote && friendly.serverNote !== friendly.detail
                ? `<p class="text-xs text-slate-400">System note: ${friendly.serverNote}</p>`
                : '';
            pendingBubble.innerHTML = `
                <div class="space-y-2">
                    <p class="text-sm font-semibold text-red-600">${friendly.title}</p>
                    <p class="text-sm text-slate-600">${friendly.detail}</p>
                    ${systemNote}
                </div>
            `;

            if (friendly.suggestions.length) {
                const suggestionWrap = document.createElement('div');
                suggestionWrap.className = 'mt-3 flex flex-wrap gap-2';
                friendly.suggestions.forEach((suggestion) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'px-3 py-1.5 rounded-full text-xs border border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-600 transition';
                    btn.textContent = suggestion;
                    btn.addEventListener('click', () => {
                        questionInput.value = suggestion;
                        questionInput.focus();
                    });
                    suggestionWrap.appendChild(btn);
                });
                pendingBubble.appendChild(suggestionWrap);
            }

            if (friendly.allowRetry) {
                const retryBtn = document.createElement('button');
                retryBtn.type = 'button';
                retryBtn.className = 'mt-4 inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700';
                retryBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 4v6h6M20 20v-6h-6" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M5 13a7 7 0 0 0 12 3l2-2M19 11a7 7 0 0 0-12-3l-2 2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Retry now
                `;
                retryBtn.addEventListener('click', () => {
                    if (questionInput.value.trim() === '') {
                        questionInput.value = question;
                    }
                    pendingBubble.innerHTML = '';
                    sendQuestion(question);
                });
                pendingBubble.appendChild(retryBtn);
            }
        } finally {
            isSending = false;
            sendBtn.disabled = false;
            askForm.reset();
            questionInput.focus();
        }
    };

    askForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const question = questionInput.value.trim();
        if (!question) return;
        sendQuestion(question);
    });

    clearBtn.addEventListener('click', () => {
        chatWindow.innerHTML = '';
        sessionToken = null;
        sessionTag.textContent = 'Session —';
    });

    suggestions.forEach((btn) => {
        btn.addEventListener('click', () => {
            questionInput.value = btn.textContent;
            questionInput.focus();
        });
    });

    questionInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) {
            return;
        }
        const text = questionInput.value.trim();
        if (!text || isSending) {
            return;
        }
        event.preventDefault();
        askForm.requestSubmit();
    });

    const params = new URLSearchParams(window.location.search);
    const prefill = params.get('prefill');
    if (prefill) {
        questionInput.value = prefill;
        questionInput.focus();
        setTimeout(() => {
            askForm.requestSubmit();
            questionInput.value = '';
            questionInput.blur();
            params.delete('prefill');
            const newUrl = window.location.pathname + (params.toString() ? `?${params.toString()}` : '');
            window.history.replaceState({}, '', newUrl);
        }, 100);
    }

    const fetchInsights = async () => {
        try {
            const res = await fetch('/api/insights.php');
            if (!res.ok) throw new Error('Unable to load insights');
            const data = await res.json();
            const fmt = new Intl.NumberFormat();
            insightsStats.forEach((el) => {
                const key = el.dataset.stat;
                if (key === 'documents') {
                    el.textContent = fmt.format(data.total_documents || 0);
                } else if (key === 'processed') {
                    el.textContent = fmt.format(data.processed_documents || 0);
                } else if (key === 'entities') {
                    el.textContent = fmt.format(data.total_entities || 0);
                } else if (key === 'flights') {
                    el.textContent = fmt.format(data.total_flights || 0);
                }
            });
            insightsUpdated.textContent = 'Updated just now';
            insightsChipContainer.innerHTML = '';
            (data.top_entities || []).slice(0, 6).forEach((entity) => {
                const chip = document.createElement('span');
                chip.className = 'px-3 py-1.5 rounded-full bg-slate-100 text-slate-700 border border-slate-200 text-xs';
                chip.textContent = `${entity.entity_name} (${entity.mention_count})`;
                insightsChipContainer.appendChild(chip);
            });
        } catch (error) {
            insightsUpdated.textContent = 'Unavailable';
        }
    };

    fetchInsights();
})();
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
