@php
    $reverbKey = config('broadcasting.connections.reverb.key') ?? '';
    $reverbHost = config('broadcasting.connections.reverb.options.host') ?? '127.0.0.1';
    $reverbPort = config('broadcasting.connections.reverb.options.port') ?? '443';
    $reverbScheme = config('broadcasting.connections.reverb.options.scheme') ?? 'https';
@endphp

<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Broadcast Tester — {{ config('app.name', 'Castilo') }}</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                }
            }
        }
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Pusher & Echo CDNs -->
    <script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at top right, rgba(30, 41, 59, 0.4), transparent),
                        radial-gradient(circle at bottom left, rgba(15, 23, 42, 0.9), rgb(8, 10, 15));
        }
        /* Custom scrollbars */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.15);
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.3);
        }
    </style>
</head>
<body class="min-h-full flex flex-col antialiased">
    <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur-md sticky top-0 z-50 px-6 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/20">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-white tracking-wide">Broadcast Tester</h1>
                    <p class="text-xs text-slate-400">Communication Module Utility</p>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700">
                    Laravel Echo + Reverb
                </span>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl w-full mx-auto p-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Configuration and Control Section -->
        <section class="lg:col-span-5 flex flex-col space-y-6">
            <!-- Connection Settings Card -->
            <div class="rounded-2xl border border-slate-800/80 bg-slate-900/40 p-6 backdrop-blur-xl shadow-xl space-y-5">
                <div>
                    <h2 class="text-base font-semibold text-white">Connection Config</h2>
                    <p class="text-xs text-slate-400">Autofilled from environment configs. Adjust if necessary.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label for="reverb-key" class="text-xs font-semibold text-slate-400">Reverb App Key</label>
                        <input id="reverb-key" type="text" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors" value="{{ $reverbKey }}">
                    </div>
                    <div class="space-y-1">
                        <label for="reverb-host" class="text-xs font-semibold text-slate-400">Reverb Host</label>
                        <input id="reverb-host" type="text" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors" value="{{ $reverbHost }}">
                    </div>
                    <div class="space-y-1">
                        <label for="reverb-port" class="text-xs font-semibold text-slate-400">Reverb Port</label>
                        <input id="reverb-port" type="text" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors" value="{{ $reverbPort }}">
                    </div>
                    <div class="space-y-1">
                        <label for="reverb-scheme" class="text-xs font-semibold text-slate-400">Scheme</label>
                        <select id="reverb-scheme" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors">
                            <option value="https" {{ $reverbScheme === 'https' ? 'selected' : '' }}>HTTPS / WSS</option>
                            <option value="http" {{ $reverbScheme === 'http' ? 'selected' : '' }}>HTTP / WS</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="auth-endpoint" class="text-xs font-semibold text-slate-400">Broadcast Auth Endpoint</label>
                    <input id="auth-endpoint" type="text" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors" value="/api/communication/broadcasting/auth">
                </div>
            </div>

            <!-- Credentials Card -->
            <div class="rounded-2xl border border-slate-800/80 bg-slate-900/40 p-6 backdrop-blur-xl shadow-xl space-y-5">
                <div>
                    <h2 class="text-base font-semibold text-white">Target User & Token</h2>
                    <p class="text-xs text-slate-400">Provide user credentials to authenticate private channel.</p>
                </div>

                <div class="space-y-4">
                    <div class="space-y-1">
                        <div class="flex justify-between items-center">
                            <label for="user-id" class="text-xs font-semibold text-slate-400">User ID</label>
                            <span id="channel-preview" class="text-[10px] font-mono text-sky-400">Channel: user.{id}</span>
                        </div>
                        <input id="user-id" type="number" placeholder="e.g. 1" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-sky-500 transition-colors">
                    </div>

                    <div class="space-y-1">
                        <label for="bearer-token" class="text-xs font-semibold text-slate-400">Bearer Token (JWT)</label>
                        <textarea id="bearer-token" rows="3" placeholder="eyJhbGciOiJIUzI1NiIsIn..." class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs font-mono text-slate-200 focus:outline-none focus:border-sky-500 transition-colors resize-none"></textarea>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 pt-2">
                    <button id="btn-connect" class="flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-sky-500 to-indigo-600 hover:from-sky-600 hover:to-indigo-700 shadow-lg shadow-sky-500/20 active:scale-[0.98] transition-all">
                        <span>Connect & Subscribe</span>
                    </button>
                    <button id="btn-disconnect" disabled class="flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-400 bg-slate-800/50 hover:bg-slate-800 border border-slate-700/60 cursor-not-allowed opacity-50 active:scale-[0.98] transition-all">
                        <span>Disconnect</span>
                    </button>
                </div>
            </div>

            <!-- Connection Status Panel -->
            <div class="rounded-2xl border border-slate-800/80 bg-slate-900/40 p-5 backdrop-blur-xl shadow-xl flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="relative flex h-3 w-3">
                        <span id="status-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                        <span id="status-dot" class="relative inline-flex rounded-full h-3 w-3"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium">Status</span>
                        <h3 id="status-text" class="text-sm font-bold text-white capitalize">Disconnected</h3>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-[10px] text-slate-500 font-mono block">Socket ID</span>
                    <span id="socket-id" class="text-xs font-mono text-slate-300">N/A</span>
                </div>
            </div>
        </section>

        <!-- Live Logs Panel Section -->
        <section class="lg:col-span-7 flex flex-col h-[70vh] min-h-[500px]">
            <div class="flex-grow flex flex-col rounded-2xl border border-slate-800/80 bg-slate-950/60 backdrop-blur-xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800/80 flex items-center justify-between bg-slate-900/20">
                    <div class="flex items-center space-x-2">
                        <div class="h-2 w-2 rounded-full bg-sky-400 animate-pulse"></div>
                        <h2 class="text-sm font-semibold text-white tracking-wide uppercase">Real-Time Events & Stream Logs</h2>
                    </div>
                    <button id="btn-clear" class="text-xs font-medium text-slate-400 hover:text-slate-200 px-3 py-1.5 rounded-lg border border-slate-800 hover:bg-slate-900 transition-all">
                        Clear Logs
                    </button>
                </div>

                <!-- Logger Console Container -->
                <div id="logs-container" class="flex-grow overflow-y-auto p-6 font-mono text-xs space-y-4 bg-slate-950">
                    <div class="flex items-start space-x-3 text-slate-500">
                        <span class="text-slate-600 select-none">[{{ date('H:i:s') }}]</span>
                        <span class="text-slate-400">[SYSTEM] Broadcast tester panel initialized. Fill in parameters to subscribe.</span>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-900/80 py-4 px-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} — Castilo Communication Module Helper. All WebSocket authentications route through the configured local middleware guard.
    </footer>

    <!-- Script Logic -->
    <script>
        // DOM Elements
        const reverbKeyInput = document.getElementById('reverb-key');
        const reverbHostInput = document.getElementById('reverb-host');
        const reverbPortInput = document.getElementById('reverb-port');
        const reverbSchemeSelect = document.getElementById('reverb-scheme');
        const authEndpointInput = document.getElementById('auth-endpoint');
        const userIdInput = document.getElementById('user-id');
        const tokenInput = document.getElementById('bearer-token');
        const btnConnect = document.getElementById('btn-connect');
        const btnDisconnect = document.getElementById('btn-disconnect');
        const btnClear = document.getElementById('btn-clear');
        const logsContainer = document.getElementById('logs-container');
        const statusPing = document.getElementById('status-ping');
        const statusDot = document.getElementById('status-dot');
        const statusText = document.getElementById('status-text');
        const socketIdSpan = document.getElementById('socket-id');
        const channelPreview = document.getElementById('channel-preview');

        let echoInstance = null;
        let currentSubscribedChannelName = null;
        let connectionTimeout = null;

        // LocalStorage keys
        const STORAGE_KEY_USER_ID = 'broadcast_tester_user_id';
        const STORAGE_KEY_TOKEN = 'broadcast_tester_token';

        // Load saved values from LocalStorage
        window.addEventListener('DOMContentLoaded', () => {
            const savedUserId = localStorage.getItem(STORAGE_KEY_USER_ID);
            const savedToken = localStorage.getItem(STORAGE_KEY_TOKEN);

            if (savedUserId) {
                userIdInput.value = savedUserId;
                updateChannelPreview(savedUserId);
            }
            if (savedToken) {
                tokenInput.value = savedToken;
            }
            setConnectionUI('disconnected');
        });

        // Live preview of the channel name
        userIdInput.addEventListener('input', (e) => {
            const val = e.target.value.trim();
            updateChannelPreview(val);
            if (val) {
                localStorage.setItem(STORAGE_KEY_USER_ID, val);
            } else {
                localStorage.removeItem(STORAGE_KEY_USER_ID);
            }
        });

        tokenInput.addEventListener('input', (e) => {
            const val = e.target.value.trim();
            if (val) {
                localStorage.setItem(STORAGE_KEY_TOKEN, val);
            } else {
                localStorage.removeItem(STORAGE_KEY_TOKEN);
            }
        });

        function updateChannelPreview(val) {
            if (val) {
                channelPreview.textContent = `Channel: private-user.${val}`;
            } else {
                channelPreview.textContent = `Channel: user.{id}`;
            }
        }

        // Add Log Entry helper
        function appendLog(type, message, details = null) {
            const timestamp = new Date().toLocaleTimeString();

            // Setup type colors
            let typeColor = 'text-slate-400';
            let typeBadge = '';
            let textClass = 'text-slate-300';

            if (type === 'success') {
                typeColor = 'text-emerald-400 font-bold';
                typeBadge = '[SUCCESS] ';
                textClass = 'text-emerald-300';
            } else if (type === 'error') {
                typeColor = 'text-rose-400 font-bold';
                typeBadge = '[ERROR] ';
                textClass = 'text-rose-300';
            } else if (type === 'info') {
                typeColor = 'text-sky-400 font-semibold';
                typeBadge = '[INFO] ';
                textClass = 'text-sky-200';
            } else if (type === 'event') {
                typeColor = 'text-violet-400 font-bold';
                typeBadge = '[EVENT] ';
                textClass = 'text-indigo-200';
            }

            const logDiv = document.createElement('div');
            logDiv.className = 'flex flex-col space-y-1 pb-3 border-b border-slate-900/60 last:border-b-0';

            let detailsHtml = '';
            if (details) {
                // Check if details is JSON and try to format it
                let displayDetails = details;
                try {
                    const parsed = typeof details === 'string' ? JSON.parse(details) : details;
                    displayDetails = JSON.stringify(parsed, null, 4);
                } catch (e) {
                    // Not valid JSON or already formatted
                }

                detailsHtml = `
                    <details class="mt-1 bg-slate-900/60 border border-slate-800/60 rounded-lg p-2.5 max-w-full overflow-x-auto">
                        <summary class="text-[10px] text-slate-500 hover:text-slate-400 cursor-pointer select-none">View Payload Details</summary>
                        <pre class="mt-2 text-[10px] text-slate-400 leading-relaxed overflow-x-auto whitespace-pre-wrap break-words">${escapeHtml(displayDetails)}</pre>
                    </details>
                `;
            }

            logDiv.innerHTML = `
                <div class="flex items-start space-x-2">
                    <span class="text-slate-600 select-none">[${timestamp}]</span>
                    <span class="${typeColor}">${typeBadge}</span>
                    <span class="${textClass} break-words">${escapeHtml(message)}</span>
                </div>
                ${detailsHtml}
            `;

            logsContainer.appendChild(logDiv);
            logsContainer.scrollTop = logsContainer.scrollHeight;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Set Connection UI states
        function setConnectionUI(state) {
            statusText.textContent = state;

            // Remove all status classes
            statusDot.className = 'relative inline-flex rounded-full h-3 w-3';
            statusPing.className = 'animate-ping absolute inline-flex h-full w-full rounded-full opacity-75';

            if (state === 'connected') {
                statusDot.classList.add('bg-emerald-500');
                statusPing.classList.add('bg-emerald-400');
                btnConnect.disabled = true;
                btnConnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-500 bg-slate-800/50 border border-slate-700/60 cursor-not-allowed opacity-50 transition-all';

                btnDisconnect.disabled = false;
                btnDisconnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-rose-600 hover:bg-rose-700 active:scale-[0.98] transition-all';
            } else if (state === 'connecting' || state === 'reconnecting') {
                statusDot.classList.add('bg-amber-500');
                statusPing.classList.add('bg-amber-400');
                btnConnect.disabled = true;
                btnConnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-500 bg-slate-800/50 border border-slate-700/60 cursor-not-allowed opacity-50 transition-all';

                btnDisconnect.disabled = false;
                btnDisconnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-rose-600 hover:bg-rose-700 active:scale-[0.98] transition-all';
            } else {
                // disconnected or failed
                statusDot.classList.add('bg-slate-600');
                statusPing.classList.add('bg-slate-500');
                statusPing.classList.add('hidden'); // stop animation when disconnected

                btnConnect.disabled = false;
                btnConnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-sky-500 to-indigo-600 hover:from-sky-600 hover:to-indigo-700 shadow-lg shadow-sky-500/20 active:scale-[0.98] transition-all';

                btnDisconnect.disabled = true;
                btnDisconnect.className = 'flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-400 bg-slate-800/50 hover:bg-slate-800 border border-slate-700/60 cursor-not-allowed opacity-50 transition-all';
                socketIdSpan.textContent = 'N/A';
            }
        }

        // Connect Echo action
        btnConnect.addEventListener('click', () => {
            const key = reverbKeyInput.value.trim();
            const host = reverbHostInput.value.trim();
            const port = reverbPortInput.value.trim();
            const scheme = reverbSchemeSelect.value;
            const authEndpoint = authEndpointInput.value.trim();
            const userId = userIdInput.value.trim();
            const token = tokenInput.value.trim();

            if (!key || !host || !port) {
                appendLog('error', 'App Key, Host, and Port are required config values.');
                return;
            }

            if (!userId) {
                appendLog('error', 'User ID is required to bind private channel.');
                return;
            }

            if (!token) {
                appendLog('error', 'Bearer Token is required to authorize the private channel connection.');
                return;
            }

            // Cleanup any existing instance
            if (echoInstance) {
                try {
                    echoInstance.disconnect();
                } catch (e) {}
            }

            appendLog('info', `Configuring Echo with Host: ${host}:${port} (${scheme})...`);
            setConnectionUI('connecting');

            try {
                // Configure Echo
                echoInstance = new Echo({
                    broadcaster: 'reverb',
                    key: key,
                    wsHost: host,
                    wsPort: Number(port),
                    wssPort: Number(port),
                    forceTLS: scheme === 'https',
                    enabledTransports: ['ws', 'wss'],
                    authEndpoint: authEndpoint,
                    auth: {
                        headers: {
                            Authorization: 'Bearer ' + token
                        }
                    }
                });

                appendLog('success', 'Laravel Echo instantiated.');

                // Setup connection listeners
                const pusherConn = echoInstance.connector.pusher.connection;

                // Monitor timeout
                if (connectionTimeout) clearTimeout(connectionTimeout);
                connectionTimeout = setTimeout(() => {
                    if (pusherConn.state !== 'connected') {
                        appendLog('error', 'Connection timed out. Check Reverb server status, port binding, and firewall rules.');
                        setConnectionUI('failed');
                    }
                }, 10000);

                pusherConn.bind('state_change', (states) => {
                    appendLog('info', `Connection state changed: ${states.previous} -> ${states.current}`);
                    setConnectionUI(states.current);

                    if (states.current === 'connected') {
                        if (connectionTimeout) clearTimeout(connectionTimeout);
                        const socketId = echoInstance.socketId();
                        socketIdSpan.textContent = socketId || 'unknown';
                        appendLog('success', `WebSocket connected! Socket ID: ${socketId}`);

                        // Subscribe to the channel immediately on connection success
                        subscribeToChannel(userId);
                    }
                });

                pusherConn.bind('error', (err) => {
                    appendLog('error', 'Pusher connection error occurred.', err);
                });

            } catch (error) {
                appendLog('error', 'Failed to initialize Laravel Echo client.', error.stack || error);
                setConnectionUI('failed');
            }
        });

        // Subscribe to channels (Public 'livestreams', Private 'user.{id}', and optional Presence 'call.{id}')
        function subscribeToChannel(userId) {
            // 1. Subscribe to Public Channel: 'livestreams'
            appendLog('info', 'Subscribing to public channel: livestreams...');
            try {
                const publicChannel = echoInstance.channel('livestreams');

                if (publicChannel.subscription && typeof publicChannel.subscription.bind_global === 'function') {
                    publicChannel.subscription.bind_global((eventName, data) => {
                        if (eventName.startsWith('pusher:')) return;
                        let displayEvent = eventName.startsWith('.') ? eventName.substring(1) : eventName;
                        appendLog('event', `[livestreams] Incoming Event: ${displayEvent}`, data);
                    });
                } else {
                    const streamEvents = ['LivestreamStarted', 'LivestreamEnded', 'LivestreamUpdated'];
                    streamEvents.forEach(evt => {
                        publicChannel.listen('.' + evt, (payload) => {
                            appendLog('event', `[livestreams] Incoming Event: ${evt}`, payload);
                        });
                    });
                }
                appendLog('success', 'Successfully subscribed to public channel: livestreams');
            } catch (error) {
                appendLog('error', 'Failed to subscribe to public channel: livestreams', error.stack || error);
            }

            // 2. Subscribe to Private Channel: 'private-user.{id}'
            if (userId) {
                const channelName = `user.${userId}`;
                currentSubscribedChannelName = channelName;

                appendLog('info', `Attempting to subscribe to private channel: private-${channelName}...`);

                try {
                    const channel = echoInstance.private(channelName);

                    channel.subscribed(() => {
                        appendLog('success', `Subscription confirmed for private channel: private-${channelName}`);
                    });

                    channel.error((error) => {
                        appendLog('error', `Subscription failed for channel: private-${channelName}. Make sure the Bearer Token corresponds to User ID ${userId} and the broadcast driver middleware allows authorization.`, error);
                    });

                    if (channel.subscription && typeof channel.subscription.bind_global === 'function') {
                        channel.subscription.bind_global((eventName, data) => {
                            if (eventName.startsWith('pusher:')) return;
                            let displayEvent = eventName.startsWith('.') ? eventName.substring(1) : eventName;
                            appendLog('event', `[private-${channelName}] Incoming Event: ${displayEvent}`, data);
                        });
                    } else {
                        const events = ['MessageSent', 'ConversationUpdated', 'CallInitiated', 'LivestreamStarted', 'LivestreamEnded', 'LivestreamUpdated', 'TestBroadcastEvent'];
                        events.forEach(evt => {
                            channel.listen('.' + evt, (payload) => {
                                appendLog('event', `[private-${channelName}] Incoming Event: ${evt}`, payload);
                            });
                        });
                    }
                } catch (error) {
                    appendLog('error', `Failed to subscribe to private channel ${channelName}`, error.stack || error);
                }
            }
        }

        // Disconnect action
        btnDisconnect.addEventListener('click', () => {
            if (echoInstance) {
                appendLog('info', 'Disconnecting Echo...');
                try {
                    echoInstance.disconnect();
                } catch (e) {}
                echoInstance = null;
                currentSubscribedChannelName = null;
                setConnectionUI('disconnected');
                appendLog('success', 'Echo disconnected successfully.');
            }
        });

        // Clear Logs action
        btnClear.addEventListener('click', () => {
            logsContainer.innerHTML = '';
            appendLog('info', 'Console logs cleared.');
        });
    </script>
</body>
</html>
