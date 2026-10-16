@php
    $currentStationUrl = \App\Models\SiteSetting::get('stream_url', env('STREAM_URL', 'default'));
    $currentStationName = config('app.name', 'station');
    $stationId = 'st_' . substr(md5($currentStationUrl . '_' . $currentStationName), 0, 10);
@endphp
    <script>
        // Inline Promise polyfill for legacy Safari / older iOS engines
        if (typeof window.Promise === 'undefined') {
            window.Promise = function(fn) {
                var callbacks = [];
                var errCallbacks = [];
                var state = 'pending';
                var value;
                this.then = function(onSuccess, onError) {
                    if (state === 'fulfilled') {
                        if (onSuccess) try { onSuccess(value); } catch(e) {}
                    } else if (state === 'rejected') {
                        if (onError) try { onError(value); } catch(e) {}
                    } else {
                        if (onSuccess) callbacks.push(onSuccess);
                        if (onError) errCallbacks.push(onError);
                    }
                    return this;
                };
                this.catch = function(onError) {
                    return this.then(null, onError);
                };
                function resolve(val) {
                    state = 'fulfilled';
                    value = val;
                    for (var i = 0; i < callbacks.length; i++) {
                        try { callbacks[i](val); } catch(e) {}
                    }
                }
                function reject(err) {
                    state = 'rejected';
                    value = err;
                    for (var i = 0; i < errCallbacks.length; i++) {
                        try { errCallbacks[i](err); } catch(e) {}
                    }
                }
                try { if (typeof fn === 'function') fn(resolve, reject); } catch(e) { reject(e); }
            };
            window.Promise.resolve = function(val) {
                return new window.Promise(function(res) { res(val); });
            };
            window.Promise.reject = function(err) {
                return new window.Promise(function(res, rej) { rej(err); });
            };
        }

        // Element.closest & matches polyfills for older Safari / legacy WebKit engines
        if (typeof window.Element !== 'undefined') {
            if (!Element.prototype.matches) {
                Element.prototype.matches = Element.prototype.matchesSelector ||
                    Element.prototype.mozMatchesSelector ||
                    Element.prototype.msMatchesSelector ||
                    Element.prototype.oMatchesSelector ||
                    Element.prototype.webkitMatchesSelector ||
                    function(s) {
                        var matches = (this.document || this.ownerDocument).querySelectorAll(s),
                            i = matches.length;
                        while (--i >= 0 && matches.item(i) !== this) {}
                        return i > -1;
                    };
            }
            if (!Element.prototype.closest) {
                Element.prototype.closest = function(s) {
                    var el = this;
                    do {
                        if (el.matches && el.matches(s)) return el;
                        el = el.parentElement || el.parentNode;
                    } while (el !== null && el.nodeType === 1);
                    return null;
                };
            }
        }

        window.CURRENT_STATION_ID = @json($stationId);
        window.getStationStorageKey = function(key) {
            return 'station_' + window.CURRENT_STATION_ID + '_' + key;
        };

        var RadioAudio = (function() {
            var _el = null;
            return {
                get: function() {
                    if (_el && document.body && document.body.contains(_el)) {
                        return _el;
                    }
                    _el = document.getElementById('live-audio');
                    return _el;
                },
                reset: function() {
                    _el = null;
                },
                paused: function() {
                    var a = this.get();
                    if (!a || typeof a.play !== 'function') return true;
                    return a.paused;
                },
                play: function() {
                    var a = this.get();
                    if (typeof SilentCarrierAudio !== 'undefined') {
                        SilentCarrierAudio.start();
                    }
                    if (!a || typeof a.play !== 'function') {
                        return (typeof window.Promise !== 'undefined' ? Promise.resolve() : undefined);
                    }
                    try {
                        var p = a.play();
                        if (p && typeof p.then === 'function') {
                            return p;
                        }
                        return (typeof window.Promise !== 'undefined' ? Promise.resolve() : undefined);
                    } catch (e) {
                        return (typeof window.Promise !== 'undefined' ? Promise.reject(e) : undefined);
                    }
                },
                pause: function() {
                    var a = this.get();
                    if (a && typeof a.pause === 'function') {
                        try {
                            a.pause();
                        } catch (e) {}
                    }
                    if (window.userPaused && typeof SilentCarrierAudio !== 'undefined') {
                        SilentCarrierAudio.stop();
                    }
                },
                setSrc: function(src) {
                    var a = this.get();
                    if (a) {
                        a.src = src;
                        var source = a.querySelector ? a.querySelector('source') : null;
                        if (source) source.src = src;
                    }
                },
                load: function() {
                    var a = this.get();
                    if (a && typeof a.load === 'function') {
                        try {
                            a.load();
                        } catch (e) {}
                    }
                },
                getVolume: function() {
                    var a = this.get();
                    return (a && typeof a.volume !== 'undefined') ? a.volume : 1;
                }
            };
        })();

        // iOS Safari background keep-alive silent carrier:
        // Maintains AVAudioSession active so iOS does not suspend JavaScript execution
        // when cellular drops while driving / navigating in Google Maps.
        var SilentCarrierAudio = (function() {
            var _carrierEl = null;
            var _blobUrl = null;

            function getCarrierBlobUrl() {
                if (_blobUrl) return _blobUrl;
                try {
                    var sampleRate = 8000;
                    var numSamples = 4000;
                    var dataSize = numSamples * 2;
                    var buffer = new ArrayBuffer(44 + dataSize);
                    var view = new DataView(buffer);
                    function writeString(offset, string) {
                        for (var i = 0; i < string.length; i++) {
                            view.setUint8(offset + i, string.charCodeAt(i));
                        }
                    }
                    writeString(0, 'RIFF');
                    view.setUint32(4, 36 + dataSize, true);
                    writeString(8, 'WAVE');
                    writeString(12, 'fmt ');
                    view.setUint32(16, 16, true);
                    view.setUint16(20, 1, true); // PCM
                    view.setUint16(22, 1, true); // Mono
                    view.setUint32(24, sampleRate, true);
                    view.setUint32(28, sampleRate * 2, true);
                    view.setUint16(32, 2, true);
                    view.setUint16(34, 16, true);
                    writeString(36, 'data');
                    view.setUint32(40, dataSize, true);
                    var blob = new Blob([buffer], { type: 'audio/wav' });
                    _blobUrl = URL.createObjectURL(blob);
                } catch(e) {
                    _blobUrl = 'data:audio/wav;base64,UklGRmQfAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YUAfAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
                }
                return _blobUrl;
            }

            function getElement() {
                if (!_carrierEl) {
                    _carrierEl = document.getElementById('silent-carrier-audio');
                    if (!_carrierEl) {
                        _carrierEl = document.createElement('audio');
                        _carrierEl.id = 'silent-carrier-audio';
                        _carrierEl.setAttribute('playsinline', 'true');
                        _carrierEl.setAttribute('webkit-playsinline', 'true');
                        _carrierEl.setAttribute('preload', 'auto');
                        _carrierEl.loop = true;
                        _carrierEl.volume = 0.001;
                        _carrierEl.src = getCarrierBlobUrl();
                        _carrierEl.style.display = 'none';
                        (document.body || document.documentElement).appendChild(_carrierEl);
                    }
                }
                return _carrierEl;
            }

            return {
                start: function() {
                    try {
                        var el = getElement();
                        if (el && el.paused) {
                            el.volume = 0.001;
                            var p = el.play();
                            if (p && typeof p.catch === 'function') {
                                p.catch(function(e) {});
                            }
                        }
                    } catch(e) {}
                },
                stop: function() {
                    try {
                        var el = getElement();
                        if (el && !el.paused) {
                            el.pause();
                        }
                    } catch(e) {}
                },
                isPlaying: function() {
                    var el = getElement();
                    return !!(el && !el.paused);
                }
            };
        })();
        window.SilentCarrierAudio = SilentCarrierAudio;
    </script>
    <script>
        // Private / Incognito browsing safe storage polyfill (in-memory fallback)
        if (!window.safeLocalStorage) {
            var _memStorageMap = {};
            window.safeLocalStorage = {
                getItem: function(key) {
                    try { return window.localStorage.getItem(key); } catch(e) { return _memStorageMap[key] || null; }
                },
                setItem: function(key, val) {
                    try { window.localStorage.setItem(key, val); } catch(e) { _memStorageMap[key] = String(val); }
                },
                removeItem: function(key) {
                    try { window.localStorage.removeItem(key); } catch(e) { delete _memStorageMap[key]; }
                },
                clear: function() {
                    try { window.localStorage.clear(); } catch(e) { _memStorageMap = {}; }
                }
            };
        }
        if (!window.safeSessionStorage) {
            var _memSessionMap = {};
            window.safeSessionStorage = {
                getItem: function(key) {
                    try { return window.sessionStorage.getItem(key); } catch(e) { return _memSessionMap[key] || null; }
                },
                setItem: function(key, val) {
                    try { window.sessionStorage.setItem(key, val); } catch(e) { _memSessionMap[key] = String(val); }
                },
                removeItem: function(key) {
                    try { window.sessionStorage.removeItem(key); } catch(e) { delete _memSessionMap[key]; }
                },
                clear: function() {
                    try { window.sessionStorage.clear(); } catch(e) { _memSessionMap = {}; }
                }
            };
        }

        // Station-isolated audio persistence functionality (ES5 Compatible)
        function AudioPersistenceManager() {
            this.storageKey = window.getStationStorageKey('audio_state');
            this.tabId = this.generateTabId();
            this.isActiveTab = true;
            this.init();
        }

        AudioPersistenceManager.prototype.generateTabId = function() {
            return 'tab_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        };

        AudioPersistenceManager.prototype.init = function() {
            var self = this;
            try {
                window.safeLocalStorage.setItem(this.storageKey + '_current_tab', this.tabId);
            } catch(e) {}

            window.addEventListener('storage', function(e) {
                if (e.key === self.storageKey) {
                    self.handleAudioStateChange(JSON.parse(e.newValue || '{}'));
                } else if (e.key === self.storageKey + '_current_tab') {
                    self.handleTabChange(e.newValue);
                }
            });

            document.addEventListener('visibilitychange', function() {
                var isPlaying = window.isRealAudioPlaying ? window.isRealAudioPlaying() : (audio && !audio.paused);
                if (document.hidden) {
                    if (!isPlaying) {
                        self.isActiveTab = false;
                    }
                } else {
                    self.isActiveTab = true;
                    try {
                        window.safeLocalStorage.setItem(self.storageKey + '_current_tab', self.tabId);
                    } catch(e) {}
                    self._syncVolumeOnly();

                    // Check and verify audio health immediately on foreground return
                    if (typeof window.verifyStreamHealthOnForeground === 'function') {
                        window.verifyStreamHealthOnForeground('visibilitychange');
                    }
                }
            });

            window.addEventListener('focus', function() {
                self.isActiveTab = true;
                try {
                    window.safeLocalStorage.setItem(self.storageKey + '_current_tab', self.tabId);
                } catch(e) {}
                self._syncVolumeOnly();

                if (typeof window.verifyStreamHealthOnForeground === 'function') {
                    window.verifyStreamHealthOnForeground('focus');
                }
            });

            window.addEventListener('blur', function() {
                var isPlaying = window.isRealAudioPlaying ? window.isRealAudioPlaying() : false;
                if (!isPlaying) {
                    self.isActiveTab = false;
                }
            });

            window.addEventListener('beforeunload', function() {
                try {
                    window.safeLocalStorage.setItem(window.getStationStorageKey('tab_closed_at'), Date.now().toString());
                } catch(e) {}
                self.saveAudioState();
            });
        };

        AudioPersistenceManager.prototype.handleAudioStateChange = function(state) {
            if (!this.isActiveTab) return;
            var audio = RadioAudio.get();
            if (!audio) return;

            if (state && state.volume !== undefined) {
                audio.volume = state.volume;
            }
            this.updatePlayPauseIcons(window.isRealAudioPlaying ? window.isRealAudioPlaying() : !audio.paused);
        };

        AudioPersistenceManager.prototype._syncVolumeOnly = function() {
            try {
                var saved = JSON.parse(window.safeLocalStorage.getItem(this.storageKey) || '{}');
                var audio = RadioAudio.get();
                if (audio && saved.volume !== undefined) {
                    audio.volume = saved.volume;
                }
            } catch (e) {}
        };

        AudioPersistenceManager.prototype.handleTabChange = function(activeTabId) {
            this.isActiveTab = (activeTabId === this.tabId);
        };

        AudioPersistenceManager.prototype.saveAudioState = function() {
            var audio = RadioAudio.get();
            if (!audio) return;

            var state = {
                isPlaying: window.isRealAudioPlaying ? window.isRealAudioPlaying() : !audio.paused,
                volume: audio.volume,
                currentTime: audio.currentTime,
                timestamp: Date.now()
            };

            try {
                window.safeLocalStorage.setItem(this.storageKey, JSON.stringify(state));
            } catch(e) {}
        };

        window.PLAYER_STATE = {
            IDLE: 'idle',
            LOADING: 'loading',
            PLAYING: 'playing',
            PAUSED: 'paused',
            RECONNECTING: 'reconnecting',
            ERROR: 'error'
        };
        window._currentPlayerState = window.PLAYER_STATE.IDLE;

        // Evaluates genuine media state - NEVER relies solely on audio.paused === false
        window.isRealAudioPlaying = function() {
            var audio = RadioAudio.get();
            if (!audio) return false;
            if (audio.paused || audio.ended || audio.error) return false;
            if (typeof _isRecovering !== 'undefined' && _isRecovering) return false;
            // In HTML5 audio, readyState >= 2 (HAVE_CURRENT_DATA) is required for real audio playback
            return (audio.readyState >= 2);
        };

        // ── Stream Reconnecting Dialogue & Alert Banner Controller ──
        window.showReconnectingDialogue = function(title, message, state) {
            var dlg = document.getElementById('stream-reconnecting-dialogue');
            if (!dlg) return;
            var titleEl = document.getElementById('srd-title');
            var msgEl   = document.getElementById('srd-message');
            var iconEl  = document.getElementById('srd-icon');
            var retryBtn = document.getElementById('srd-retry-btn');

            if (titleEl && title) titleEl.textContent = title;
            if (msgEl && message) msgEl.textContent = message;

            dlg.classList.remove('srd-success', 'srd-offline');
            clearTimeout(dlg._hideTimer);

            if (state === 'success') {
                dlg.classList.add('srd-success');
                if (iconEl) iconEl.className = 'fas fa-check-circle srd-icon text-success';
                if (retryBtn) retryBtn.style.display = 'none';
                dlg._hideTimer = setTimeout(function() {
                    window.hideReconnectingDialogue();
                }, 2500);
            } else if (state === 'offline') {
                dlg.classList.add('srd-offline');
                if (iconEl) iconEl.className = 'fas fa-wifi-slash srd-icon text-danger';
                if (retryBtn) retryBtn.style.display = 'inline-block';
            } else { // reconnecting / error
                if (iconEl) iconEl.className = 'fas fa-satellite-dish fa-spin srd-icon text-warning';
                if (retryBtn) retryBtn.style.display = 'inline-block';
            }

            dlg.style.display = 'block';
        };

        window.hideReconnectingDialogue = function() {
            var dlg = document.getElementById('stream-reconnecting-dialogue');
            if (dlg) {
                clearTimeout(dlg._hideTimer);
                dlg.style.display = 'none';
            }
        };

        window.forceReconnectLiveStream = function() {
            console.log('🔄 [SmartPlayer] User invoked manual Force Reconnect Live Stream.');
            if (typeof _reconnectTimer !== 'undefined') clearTimeout(_reconnectTimer);
            if (typeof _recoverySafetyTimer !== 'undefined') clearTimeout(_recoverySafetyTimer);
            _isRecovering = false;
            _lastRecoveryAttempt = 0;
            _reconnectAttempts = 0;
            window.userPaused = false;
            window.setAudioInterrupted(false);
            savePlayingIntent(true);
            window.showReconnectingDialogue("Connecting...", "Attempting immediate live stream connection...", "reconnecting");
            window.recoverLiveStream('user-force-retry');
        };

        window.setPlayerState = function(newState, message) {
            window._currentPlayerState = newState;
            var navbarPlayIcon = document.getElementById('navbar-play-icon');
            var miniPlayIcon   = document.getElementById('mini-play-icon');
            var playIcon       = document.getElementById('play-icon');
            var songInfo       = document.querySelector('.player-song');
            var playText       = document.getElementById('play-to-listen-text');
            var stickyPlayer   = document.getElementById('sticky-player');

            if (stickyPlayer) stickyPlayer.style.display = 'flex';

            if (newState === window.PLAYER_STATE.PLAYING) {
                if (navbarPlayIcon) navbarPlayIcon.className = "fas fa-pause me-1";
                if (miniPlayIcon)   miniPlayIcon.className   = "fas fa-pause";
                if (playIcon)       playIcon.className       = "fas fa-pause";

                if (songInfo) songInfo.style.display = 'block';
                if (playText) playText.style.display = 'none';

                if ('mediaSession' in navigator) {
                    navigator.mediaSession.playbackState = 'playing';
                }
                if (typeof window.resumeLiveMediaPosition === 'function') {
                    window.resumeLiveMediaPosition('setPlayerState-playing');
                }

                // If reconnecting dialogue was showing, flash green success then fade out
                var dlg = document.getElementById('stream-reconnecting-dialogue');
                if (dlg && dlg.style.display !== 'none' && !dlg.classList.contains('srd-success')) {
                    window.showReconnectingDialogue("Connected!", "Live stream resumed.", "success");
                }
                _reconnectAttempts = 0;
            } else if (newState === window.PLAYER_STATE.LOADING || newState === window.PLAYER_STATE.RECONNECTING) {
                if (navbarPlayIcon) navbarPlayIcon.className = "fas fa-spinner fa-spin me-1";
                if (miniPlayIcon)   miniPlayIcon.className   = "fas fa-spinner fa-spin";
                if (playIcon)       playIcon.className       = "fas fa-spinner fa-spin";

                if (songInfo) songInfo.style.display = 'none';
                if (playText) {
                    playText.textContent = message || (newState === window.PLAYER_STATE.RECONNECTING ? "Reconnecting stream..." : "Connecting to stream...");
                    playText.style.display = 'block';
                }

                if ('mediaSession' in navigator) {
                    navigator.mediaSession.playbackState = 'paused';
                }
                if (typeof window.freezeLiveMediaPosition === 'function') {
                    window.freezeLiveMediaPosition('setPlayerState-reconnecting');
                }
            } else { // PAUSED, IDLE, ERROR
                if (navbarPlayIcon) navbarPlayIcon.className = "fas fa-play me-1";
                if (miniPlayIcon)   miniPlayIcon.className   = "fas fa-play";
                if (playIcon)       playIcon.className       = "fas fa-play";

                if (songInfo) songInfo.style.display = 'none';
                if (playText) {
                    playText.textContent = message || "Press Play To Listen";
                    playText.style.display = 'block';
                }

                if ('mediaSession' in navigator) {
                    navigator.mediaSession.playbackState = 'paused';
                }
                if (typeof window.freezeLiveMediaPosition === 'function') {
                    window.freezeLiveMediaPosition('setPlayerState-paused');
                }

                if (window.userPaused) {
                    window.hideReconnectingDialogue();
                }
            }
        };

        AudioPersistenceManager.prototype.updatePlayPauseIcons = function(isPlaying) {
            if (isPlaying && window.isRealAudioPlaying()) {
                window.setPlayerState(window.PLAYER_STATE.PLAYING);
            } else if (typeof _isRecovering !== 'undefined' && _isRecovering) {
                window.setPlayerState(window.PLAYER_STATE.RECONNECTING);
            } else {
                window.setPlayerState(window.PLAYER_STATE.PAUSED);
            }
        };

        var audioPersistenceManager = new AudioPersistenceManager();

        function togglePlayerDisplay(isPlaying) {
            if (isPlaying && window.isRealAudioPlaying()) {
                window.setPlayerState(window.PLAYER_STATE.PLAYING);
            } else if (typeof _isRecovering !== 'undefined' && _isRecovering) {
                window.setPlayerState(window.PLAYER_STATE.RECONNECTING);
            } else {
                window.setPlayerState(window.PLAYER_STATE.PAUSED);
            }
        }

        // Returns live stream URL with cache-buster timestamp and preserves existing parameters
        function getStreamUrl() {
            var audio = RadioAudio.get();
            if (!audio) return '';
            var base = (audio.getAttribute && audio.getAttribute('data-stream-url')) ||
                       (audio.querySelector && audio.querySelector('source') ? audio.querySelector('source').getAttribute('src') : '') ||
                       audio.src || '';
            if (!base) return '';
            if (window.location.protocol === 'https:' && base.indexOf('http:') === 0) {
                base = base.replace(/^http:/, 'https:');
            }
            // Strip any prior cache-buster parameter so query string remains clean
            base = base.replace(/([?&])_cb=\d+(&|$)/g, '$1').replace(/[?&]$/, '');
            var sep = base.indexOf('?') !== -1 ? '&' : '?';
            return base + sep + '_cb=' + Date.now();
        }

        // High-performance live stream mounter: completely flushes old decoder buffer and mounts fresh live stream
        function mountFreshStream(audio, freshUrl) {
            if (!audio) return;
            try {
                if (!audio.paused) {
                    audio.pause();
                }
            } catch(e) {}

            var targetVolume = 1;
            try {
                if (typeof audio.volume === 'number' && audio.volume > 0) {
                    targetVolume = audio.volume;
                }
            } catch(e) {}

            // Temporarily mute while mounting fresh stream to eliminate the 1-second stale buffer glitch
            audio.muted = true;

            // Remove existing child sources so browser media engine completely clears old buffer
            while (audio.firstChild) {
                audio.removeChild(audio.firstChild);
            }

            audio.src = freshUrl;
            var source = document.createElement('source');
            source.src = freshUrl;
            source.type = 'audio/mpeg';
            audio.appendChild(source);

            // Explicit load() forces WebKit & Chromium to flush the residual decoded buffer and close old socket
            try {
                audio.load();
            } catch(e) {}

            // Unmute cleanly only when genuine fresh live audio data is actively playing
            var unmuteOnLive = function() {
                if (audio.removeEventListener) {
                    audio.removeEventListener('playing', unmuteOnLive);
                    audio.removeEventListener('timeupdate', unmuteOnLive);
                    audio.removeEventListener('canplay', unmuteOnLive);
                }
                audio.muted = false;
                try { audio.volume = targetVolume; } catch(e) {}
            };

            if (audio.addEventListener) {
                audio.addEventListener('playing', unmuteOnLive);
                audio.addEventListener('timeupdate', unmuteOnLive);
                audio.addEventListener('canplay', unmuteOnLive);
            } else {
                audio.muted = false;
            }
        }
        function resetAndMountStream(audio, freshUrl) {
            mountFreshStream(audio, freshUrl);
        }

        function savePlayingIntent(shouldPlay) {
            try {
                window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent'), shouldPlay ? '1' : '0');
                if (shouldPlay) {
                    window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                }
                window.safeLocalStorage.setItem(window.getStationStorageKey('was_playing'), shouldPlay ? '1' : '0');
            } catch (e) {}
        }

        window.isIntentStillValid = function() {
            if (window.userPaused) return false;
            var intent = window.safeLocalStorage.getItem(window.getStationStorageKey('playing_intent'));
            if (intent !== '1') return false;
            var intentTimeStr = window.safeLocalStorage.getItem(window.getStationStorageKey('playing_intent_time'));
            var intentTime = intentTimeStr ? parseInt(intentTimeStr, 10) : 0;
            // Retain reconnection intent for at least 30 minutes during network loss / phone calls / car stops
            var THIRTY_MINUTES = 30 * 60 * 1000;
            if (Date.now() - intentTime > THIRTY_MINUTES) {
                return false;
            }
            return true;
        };

        window.audioInterruptedByDevice = false;
        window._lastAudioDisconnectTime = 0;
        window.setAudioInterrupted = function(val, reason) {
            window.audioInterruptedByDevice = !!val;
            try {
                if (val) {
                    var nowStr = Date.now().toString();
                    var r = reason || 'device';
                    window._lastAudioDisconnectTime = Date.now();
                    window.safeLocalStorage.setItem(window.getStationStorageKey('interrupted_by_device'), '1');
                    window.safeLocalStorage.setItem(window.getStationStorageKey('interrupted_time'), nowStr);
                    window.safeLocalStorage.setItem(window.getStationStorageKey('interrupted_disconnect_time'), nowStr);
                    window.safeLocalStorage.setItem(window.getStationStorageKey('interruption_type'), r);
                } else {
                    window.safeLocalStorage.removeItem(window.getStationStorageKey('interrupted_by_device'));
                    window.safeLocalStorage.removeItem(window.getStationStorageKey('interrupted_time'));
                    window.safeLocalStorage.removeItem(window.getStationStorageKey('interruption_type'));
                }
            } catch(e) {}
        };

        window.getLastAudioDisconnectTime = function() {
            if (window._lastAudioDisconnectTime) return window._lastAudioDisconnectTime;
            try {
                var str = window.safeLocalStorage.getItem(window.getStationStorageKey('interrupted_disconnect_time'));
                return str ? parseInt(str, 10) : 0;
            } catch(e) {
                return 0;
            }
        };

        window.isAudioInterrupted = function() {
            if (window.audioInterruptedByDevice) return true;
            try {
                var isInt = window.safeLocalStorage.getItem(window.getStationStorageKey('interrupted_by_device')) === '1';
                if (!isInt) return false;
                var intTimeStr = window.safeLocalStorage.getItem(window.getStationStorageKey('interrupted_time'));
                var intTime = intTimeStr ? parseInt(intTimeStr, 10) : 0;
                // Auto-expire device interruption after 30 minutes
                if (Date.now() - intTime > (30 * 60 * 1000)) {
                    window.setAudioInterrupted(false);
                    return false;
                }
                return true;
            } catch(e) {
                return false;
            }
        };

        var _lastToggleTime = 0;
        window.userPaused = false;

        // WakeLock reference to prevent browser from freezing audio in background (ES5 compatible)
        var _wakeLock = null;
        function requestPlayerWakeLock() {
            if ('wakeLock' in navigator && navigator.wakeLock && typeof navigator.wakeLock.request === 'function') {
                try {
                    var p = navigator.wakeLock.request('screen');
                    if (p && typeof p.then === 'function') {
                        p.then(function(lock) {
                            _wakeLock = lock;
                            if (_wakeLock && _wakeLock.addEventListener) {
                                _wakeLock.addEventListener('release', function() { _wakeLock = null; });
                            }
                        }).catch(function() {});
                    }
                } catch(e) {}
            }
        }
        function releasePlayerWakeLock() {
            if (_wakeLock && _wakeLock.release) {
                try { _wakeLock.release(); } catch(e) {}
                _wakeLock = null;
            }
        }

        var _resumeOnGestureArmed = false;
        function armResumeOnFirstInteraction() {
            if (_resumeOnGestureArmed) return;
            _resumeOnGestureArmed = true;

            var gestureEvents = ['pointerdown', 'touchstart', 'touchend', 'click', 'keydown', 'scroll', 'wheel'];
            var onGesture = function(e) {
                _resumeOnGestureArmed = false;
                for (var i = 0; i < gestureEvents.length; i++) {
                    window.removeEventListener(gestureEvents[i], onGesture, true);
                    document.removeEventListener(gestureEvents[i], onGesture, true);
                }

                // If user specifically clicked a play/pause button, that button's click handler will execute
                if (e && e.target && e.target.closest && e.target.closest('#mini-play-btn, [data-action="toggle-play"], #navbar-play-btn, #hero-listen-btn, #play-btn')) {
                    return;
                }

                if (window.isIntentStillValid() && !window.userPaused && !window.isAudioInterrupted()) {
                    var audio = RadioAudio.get();
                    if (audio && (audio.src || (audio.querySelector && audio.querySelector('source')))) {
                        var p = RadioAudio.play();
                        if (p && typeof p.then === 'function') {
                            p.then(function() {
                                window.setPlayerState(window.PLAYER_STATE.PLAYING);
                                if (typeof window.reassertMediaSessionMetadata === 'function') {
                                    window.reassertMediaSessionMetadata(true);
                                }
                                requestPlayerWakeLock();
                            }).catch(function(err) {
                                window.recoverLiveStream('user-interaction-after-back');
                            });
                            return;
                        }
                    }
                    window.recoverLiveStream('user-interaction-after-back');
                }
            };

            for (var i = 0; i < gestureEvents.length; i++) {
                window.addEventListener(gestureEvents[i], onGesture, { capture: true, passive: true });
                document.addEventListener(gestureEvents[i], onGesture, { capture: true, passive: true });
            }
        }

        window.toggleLiveAudio = function toggleLiveAudio(forceState) {
            var now = Date.now();
            if (now - _lastToggleTime < 350) {
                return; // Debounce rapid multi-clicks
            }
            _lastToggleTime = now;

            var audio = RadioAudio.get();
            if (!audio) {
                console.error('Audio element not found');
                return;
            }

            var shouldPlay = (typeof forceState === 'boolean') ? forceState : (audio.paused || !window.isRealAudioPlaying());

            if (shouldPlay) {
                _resumeOnGestureArmed = false;
                window.userPaused = false;
                window.setAudioInterrupted(false);
                savePlayingIntent(true);
                SilentCarrierAudio.start();
                _reconnectAttempts = 0;
                try { window.safeLocalStorage.removeItem(window.getStationStorageKey('tab_closed_at')); } catch(e) {}

                window.setPlayerState(window.PLAYER_STATE.LOADING, "Connecting to stream...");

                // Completely purge existing buffer to ensure no stale seconds replay
                var freshUrl = getStreamUrl();
                resetAndMountStream(audio, freshUrl);

                var playPromise = RadioAudio.play();
                if (playPromise && typeof playPromise.then === 'function') {
                    playPromise.then(function() {
                        _resumeOnGestureArmed = false;
                        _reconnectAttempts = 0;
                        SilentCarrierAudio.start();
                        window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                        audioPersistenceManager.saveAudioState();
                        window.setPlayerState(window.PLAYER_STATE.PLAYING);
                        window.showReconnectingDialogue("Connected!", "Live stream resumed.", "success");
                        if (typeof window.reassertMediaSessionMetadata === 'function') {
                            window.reassertMediaSessionMetadata();
                        }
                        requestPlayerWakeLock();
                    }).catch(function(error) {
                        console.error('Error playing audio:', error);
                        // In Safari, if play was aborted due to load() timing, re-try upon canplay
                        if (error && (error.name === 'AbortError' || error.code === 20)) {
                            var onCanPlay = function() {
                                if (audio.removeEventListener) {
                                    audio.removeEventListener('canplay', onCanPlay);
                                    audio.removeEventListener('loadedmetadata', onCanPlay);
                                }
                                if (!window.userPaused) {
                                    RadioAudio.play();
                                }
                            };
                            if (audio.addEventListener) {
                                audio.addEventListener('canplay', onCanPlay);
                                audio.addEventListener('loadedmetadata', onCanPlay);
                            }
                            return;
                        }

                        if (error && (error.name === 'NotAllowedError' || error.name === 'SecurityError')) {
                            window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                            armResumeOnFirstInteraction();
                            return;
                        }

                        var isOnline = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;
                        if (!isOnline) {
                            window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                            window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
                        } else if (window.isIntentStillValid()) {
                            window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Connecting to stream...");
                            window.showReconnectingDialogue("Connecting to Stream", "Establishing live audio connection...", "reconnecting");
                        } else {
                            window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                            window.hideReconnectingDialogue();
                        }
                    });
                } else {
                    window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                    audioPersistenceManager.saveAudioState();
                    window.setPlayerState(window.PLAYER_STATE.PLAYING);
                    requestPlayerWakeLock();
                }
            } else {
                window.userPaused = true;
                window.setAudioInterrupted(false);
                savePlayingIntent(false);
                SilentCarrierAudio.stop();
                RadioAudio.pause();
                audioPersistenceManager.saveAudioState();
                window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                window.hideReconnectingDialogue();
                releasePlayerWakeLock();
            }
        };
        toggleLiveAudio = window.toggleLiveAudio;

        var _isRecovering = false;
        var _lastRecoveryAttempt = 0;
        var _reconnectAttempts = 0;
        var _reconnectTimer = null;
        var _recoverySafetyTimer = null;
        var _maxAutoReconnectAttempts = 20;

        function getReconnectBackoff(attempt) {
            if (attempt <= 1) return 800;
            if (attempt <= 3) return 1500;
            if (attempt <= 6) return 3000;
            return 5000;
        }

        window.recoverLiveStream = function(reason) {
            var now = Date.now();
            if (_isRecovering && (now - _lastRecoveryAttempt < 2500)) return;

            if (!window.isIntentStillValid() || window.userPaused) {
                window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                window.hideReconnectingDialogue();
                SilentCarrierAudio.stop();
                _isRecovering = false;
                return;
            }

            // Ensure background keepalive audio carrier is active
            SilentCarrierAudio.start();

            // If audio was paused due to CarPlay / Bluetooth disconnect, do NOT force playback on phone speaker!
            // Wait for CarPlay / Bluetooth reconnect or explicit user / mediasession / call-ended action
            if (window.isAudioInterrupted()) {
                var intType = window.safeLocalStorage.getItem(window.getStationStorageKey('interruption_type')) || '';
                var isAuthorized = (reason.indexOf('reconnect') !== -1 ||
                                    reason.indexOf('mediasession') !== -1 ||
                                    reason.indexOf('user') !== -1 ||
                                    reason.indexOf('call-ended') !== -1 ||
                                    reason.indexOf('network') !== -1 ||
                                    reason.indexOf('online') !== -1 ||
                                    reason.indexOf('stalled') !== -1 ||
                                    reason.indexOf('waiting') !== -1 ||
                                    reason.indexOf('error') !== -1 ||
                                    reason.indexOf('watchdog') !== -1 ||
                                    reason.indexOf('starvation') !== -1 ||
                                    reason.indexOf('ping') !== -1);

                // If confirmed Bluetooth physical disconnect, only allow recovery if vehicle reconnected or user/mediasession action
                if (intType === 'bluetooth' && (reason.indexOf('vehicle-reconnected') === -1 && reason.indexOf('user') === -1 && reason.indexOf('mediasession') === -1)) {
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    window.hideReconnectingDialogue();
                    _isRecovering = false;
                    return;
                }

                if (!isAuthorized) {
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    window.hideReconnectingDialogue();
                    _isRecovering = false;
                    return;
                }
                window.setAudioInterrupted(false);
            }

            _isRecovering = true;
            _lastRecoveryAttempt = now;
            _reconnectAttempts++;

            // Safety watchdog timer: guarantees _isRecovering resets even if stream socket hangs
            clearTimeout(_recoverySafetyTimer);
            _recoverySafetyTimer = setTimeout(function() {
                _isRecovering = false;
            }, 4500);

            var isOnline = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;

            // Immediately show reconnecting in UI and update the Dialogue
            if (!isOnline) {
                window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
            } else {
                window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                var attemptLabel = _reconnectAttempts > 1 ? " (Attempt " + _reconnectAttempts + ")" : "";
                window.showReconnectingDialogue("Stream Connection Lost", "Reconnecting to live stream" + attemptLabel + "...", "reconnecting");
            }

            var audio = RadioAudio.get();
            if (!audio) {
                _isRecovering = false;
                return;
            }

            // Flush old buffer completely and mount fresh timestamped stream URL
            var freshUrl = getStreamUrl();
            resetAndMountStream(audio, freshUrl);

            var p = RadioAudio.play();
            if (p && typeof p.then === 'function') {
                p.then(function() {
                    _isRecovering = false;
                    _reconnectAttempts = 0;
                    clearTimeout(_reconnectTimer);
                    clearTimeout(_recoverySafetyTimer);
                    _resumeOnGestureArmed = false;
                    SilentCarrierAudio.start();
                    window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                    audioPersistenceManager.saveAudioState();
                    window.setPlayerState(window.PLAYER_STATE.PLAYING);
                    window.showReconnectingDialogue("Connected!", "Live stream resumed.", "success");
                    if (typeof window.reassertMediaSessionMetadata === 'function') {
                        window.reassertMediaSessionMetadata();
                    }
                    requestPlayerWakeLock();
                }).catch(function(err) {
                    console.warn('⚠️ [LiveStreamWatchdog] Reconnect attempt rejected/failed (' + reason + '):', err);
                    _isRecovering = false;

                    if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
                        window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                        window.showReconnectingDialogue("Playback Paused", "Tap 'Retry Now' or Play to resume stream.", "reconnecting");
                        armResumeOnFirstInteraction();
                        return;
                    }

                    if (window.isIntentStillValid() && !window.userPaused) {
                        clearTimeout(_reconnectTimer);
                        var delay = getReconnectBackoff(_reconnectAttempts);

                        if (_reconnectAttempts < _maxAutoReconnectAttempts) {
                            var onlineStatus = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;
                            if (!onlineStatus) {
                                window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                                window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
                            } else {
                                window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                                window.showReconnectingDialogue("Stream Connection Lost", "Reconnecting in " + Math.round(delay / 1000) + "s (Attempt " + _reconnectAttempts + ")...", "reconnecting");
                            }
                            _reconnectTimer = setTimeout(function() {
                                if (window.isIntentStillValid() && !window.userPaused) {
                                    window.recoverLiveStream('scheduled-retry-' + _reconnectAttempts);
                                }
                            }, delay);
                        } else {
                            window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Connection Lost");
                            window.showReconnectingDialogue("Connection Lost", "Unable to reach stream. Tap 'Retry Now' to reconnect.", "offline");
                        }
                    } else {
                        window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                        window.hideReconnectingDialogue();
                    }
                });
            } else {
                _isRecovering = false;
                _reconnectAttempts = 0;
                clearTimeout(_reconnectTimer);
                clearTimeout(_recoverySafetyTimer);
                SilentCarrierAudio.start();
                window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                window.setPlayerState(window.PLAYER_STATE.PLAYING);
                window.showReconnectingDialogue("Connected!", "Live stream resumed.", "success");
            }
        };

        window.verifyStreamHealthOnForeground = function(source) {
            if (!window.isIntentStillValid() || window.userPaused) return;

            var audio = RadioAudio.get();
            if (!audio) return;

            // Handle return from phone call or system interruption
            if (window.isAudioInterrupted()) {
                var intType = window.safeLocalStorage.getItem(window.getStationStorageKey('interruption_type')) || '';
                if (intType === 'call' || intType === 'system' || intType === 'device') {
                    window.setAudioInterrupted(false);
                    window.recoverLiveStream('call-ended-' + source);
                    return;
                } else if (intType === 'bluetooth') {
                    // Vehicle was disconnected - keep paused to avoid phone speaker leak
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    return;
                }
            }

            if (_resumeOnGestureArmed) return;

            // 1. If audio is paused and user intent is active, do NOT attempt to play the stale buffer!
            // Live streams cannot be resumed from a dead socket. Mount a fresh live stream immediately:
            if (audio.paused) {
                window.recoverLiveStream('foreground-' + source);
                return;
            }

            // 2. If audio has error or network state failed, trigger recovery
            if (audio.error || audio.networkState === 3) {
                console.warn('⚠️ [StreamHealth] Audio error upon returning (' + source + ') — recovering...');
                window.recoverLiveStream('foreground-' + source);
                return;
            }

            // 3. Check if currentTime advances over 800ms (detects severed HTTP live socket in Chrome)
            var sampleTime = audio.currentTime;
            setTimeout(function() {
                if (window.isIntentStillValid() && !window.isAudioInterrupted() && !window.userPaused) {
                    var currentAudio = RadioAudio.get();
                    if (currentAudio) {
                        if (currentAudio.paused || currentAudio.readyState < 2 || currentAudio.currentTime === sampleTime) {
                            console.warn('⚠️ [StreamHealth] Audio frozen/silent upon returning (' + source + ') — auto-recovering...');
                            window.recoverLiveStream('foreground-frozen-time');
                        } else {
                            window.setPlayerState(window.PLAYER_STATE.PLAYING);
                        }
                    }
                }
            }, 800);
        };

        function attemptAutoResumeOnBack(reason) {
            RadioAudio.reset();
            var audio = RadioAudio.get();
            if (!audio) return;

            setupAudioListeners();

            if (window.userPaused) {
                window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                return;
            }

            if (window.isAudioInterrupted()) {
                window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                return;
            }

            if (!window.isIntentStillValid()) {
                window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                return;
            }

            // 1. If audio is ALREADY genuinely playing, keep playing
            if (window.isRealAudioPlaying && window.isRealAudioPlaying()) {
                _resumeOnGestureArmed = false;
                window.setPlayerState(window.PLAYER_STATE.PLAYING);
                if (typeof window.reassertMediaSessionMetadata === 'function') {
                    window.reassertMediaSessionMetadata(true);
                }
                return;
            }

            // 2. Audio is not playing: mount a fresh live stream instead of attempting to play dead socket
            window.recoverLiveStream('auto-resume-' + reason);
            return;
        }

        function initAudio() {
            var liveAudio = RadioAudio.get();
            if (!liveAudio) return null;

            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.setActionHandler('play', function() {
                        window.userPaused = false;
                        window.setAudioInterrupted(false);
                        savePlayingIntent(true);
                        SilentCarrierAudio.start();
                        window.recoverLiveStream('mediasession-play');
                    });
                } catch (e) {}

                try {
                    navigator.mediaSession.setActionHandler('pause', function() {
                        window.userPaused = true;
                        window.setAudioInterrupted(false);
                        savePlayingIntent(false);
                        if (typeof _reconnectTimer !== 'undefined') clearTimeout(_reconnectTimer);
                        if (typeof _recoverySafetyTimer !== 'undefined') clearTimeout(_recoverySafetyTimer);
                        _isRecovering = false;
                        SilentCarrierAudio.stop();
                        RadioAudio.pause();
                        audioPersistenceManager.saveAudioState();
                        window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                        window.hideReconnectingDialogue();
                        releasePlayerWakeLock();
                        if ('mediaSession' in navigator) {
                            try {
                                navigator.mediaSession.playbackState = 'paused';
                            } catch(e) {}
                        }
                    });
                } catch (e) {}

                try {
                    navigator.mediaSession.setActionHandler('stop', function() {
                        window.userPaused = true;
                        window.setAudioInterrupted(false);
                        savePlayingIntent(false);
                        if (typeof _reconnectTimer !== 'undefined') clearTimeout(_reconnectTimer);
                        if (typeof _recoverySafetyTimer !== 'undefined') clearTimeout(_recoverySafetyTimer);
                        _isRecovering = false;
                        SilentCarrierAudio.stop();
                        RadioAudio.pause();
                        audioPersistenceManager.saveAudioState();
                        window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                        window.hideReconnectingDialogue();
                        releasePlayerWakeLock();
                        if ('mediaSession' in navigator) {
                            try {
                                navigator.mediaSession.playbackState = 'paused';
                            } catch(e) {}
                        }
                    });
                } catch (e) {}
            }

            return liveAudio;
        }

        function setupAudioListeners() {
            var liveAudio = RadioAudio.get();
            if (!liveAudio) return;

            if (liveAudio._hasBoundListeners) return;
            liveAudio._hasBoundListeners = true;

            liveAudio.addEventListener('playing', function() {
                _isRecovering = false;
                SilentCarrierAudio.stop();
                window.safeLocalStorage.setItem(window.getStationStorageKey('playing_intent_time'), Date.now().toString());
                audioPersistenceManager.saveAudioState();
                window.setPlayerState(window.PLAYER_STATE.PLAYING);
                if (typeof window.reassertMediaSessionMetadata === 'function') {
                    window.reassertMediaSessionMetadata();
                }
            });

            liveAudio.addEventListener('pause', function() {
                audioPersistenceManager.saveAudioState();
                releasePlayerWakeLock();

                // 1. Explicit user pause (from web button or lock screen)
                if (window.userPaused) {
                    window.setAudioInterrupted(false);
                    SilentCarrierAudio.stop();
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    window.hideReconnectingDialogue();
                    if ('mediaSession' in navigator) {
                        try {
                            navigator.mediaSession.playbackState = 'paused';
                        } catch(e) {}
                    }
                    return;
                }

                // 2. Involuntary pause: check if this is an actual Bluetooth / CarPlay device disconnect
                var timeSinceDisconnect = Date.now() - window.getLastAudioDisconnectTime();
                var isDeviceDisconnect = window.audioInterruptedByDevice || (timeSinceDisconnect < 3000);

                if (isDeviceDisconnect) {
                    window.setAudioInterrupted(true, 'bluetooth');
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    window.hideReconnectingDialogue();
                    SilentCarrierAudio.stop();
                    if ('mediaSession' in navigator) {
                        try {
                            navigator.mediaSession.playbackState = 'paused';
                        } catch(e) {}
                    }
                    return;
                }

                // 3. Network buffer starvation / cellular toggle in background:
                // Keep SilentCarrierAudio running so iOS doesn't freeze the tab!
                if (window.isIntentStillValid()) {
                    SilentCarrierAudio.start();
                    var isOnline = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;
                    if (!isOnline) {
                        window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                        window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
                    } else {
                        window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                        window.showReconnectingDialogue("Buffering...", "Reconnecting to live stream...", "reconnecting");
                    }
                    window.recoverLiveStream('stream-paused-network-starvation');
                } else {
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    window.hideReconnectingDialogue();
                    SilentCarrierAudio.stop();
                }
            });

            liveAudio.addEventListener('volumechange', function() {
                audioPersistenceManager.saveAudioState();
            });

            liveAudio.addEventListener('stalled', function() {
                if (window.isIntentStillValid() && !window.userPaused) {
                    console.warn('⚠️ [SmartPlayer] Stream stalled event detected.');
                    window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                    window.recoverLiveStream('stalled');
                }
            });

            liveAudio.addEventListener('error', function() {
                console.error('⚠️ [SmartPlayer] Live audio error:', liveAudio.error);
                if (window.isIntentStillValid() && !window.userPaused) {
                    var isOnline = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;
                    if (!isOnline) {
                        window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                        window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
                    } else {
                        window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                    }
                    window.recoverLiveStream('error');
                }
            });

            liveAudio.addEventListener('ended', function() {
                console.warn('⚠️ [SmartPlayer] Stream ended unexpectedly.');
                if (window.isIntentStillValid() && !window.userPaused) {
                    window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Reconnecting stream...");
                    window.recoverLiveStream('ended');
                }
            });

            liveAudio.addEventListener('waiting', function() {
                clearTimeout(liveAudio._waitingTimeout);
                liveAudio._waitingTimeout = setTimeout(function() {
                    if (window.isIntentStillValid() && !window.userPaused && (!window.isRealAudioPlaying() || liveAudio.readyState < 3)) {
                        console.warn('⚠️ [SmartPlayer] Audio waiting timeout (buffer exhausted).');
                        window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Buffering...");
                        window.showReconnectingDialogue("Buffering Stream", "Recovering audio connection...", "reconnecting");
                        window.recoverLiveStream('waiting-timeout');
                    }
                }, 2000);
            });
        }

        // Immediate network connection recovery listeners
        window.addEventListener('online', function() {
            if (typeof window.startMetadataUpdates === 'function') {
                window.startMetadataUpdates();
            }
            if (window.isIntentStillValid() && !window.userPaused) {
                console.log('🌐 [SmartPlayer] Network online event fired — resuming stream immediately.');
                window.showReconnectingDialogue("Internet Restored", "Reconnecting to live stream...", "reconnecting");
                _reconnectAttempts = 0;
                window.recoverLiveStream('network-online-restored');
            }
        });

        window.addEventListener('offline', function() {
            if (typeof window.freezeLiveMediaPosition === 'function') {
                window.freezeLiveMediaPosition('network-offline');
            }
            if (window.isIntentStillValid() && !window.userPaused) {
                console.log('🌐 [SmartPlayer] Network offline event fired — waiting for network.');
                window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Offline. Waiting for network...");
                window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");
            }
        });

        // Intelligent route change & device tracking for iOS Safari / CarPlay
        var _lastKnownAudioDeviceCount = null;
        var _isCheckingRouteChange = false;
        var _resumeDebounceTimer = null;

        function handleAudioRouteChange(source) {
            if (_isCheckingRouteChange) return;
            _isCheckingRouteChange = true;

            var enumerate = (typeof navigator !== 'undefined' && navigator.mediaDevices && typeof navigator.mediaDevices.enumerateDevices === 'function')
                ? navigator.mediaDevices.enumerateDevices()
                : (typeof Promise !== 'undefined' ? Promise.resolve([]) : null);

            if (!enumerate) {
                _isCheckingRouteChange = false;
                return;
            }

            enumerate.then(function(devices) {
                _isCheckingRouteChange = false;
                var currentCount = 0;
                for (var i = 0; i < devices.length; i++) {
                    if (devices[i].kind === 'audioinput' || devices[i].kind === 'audiooutput') {
                        currentCount++;
                    }
                }

                var prevCount = _lastKnownAudioDeviceCount;
                _lastKnownAudioDeviceCount = currentCount;
                var now = Date.now();

                if (prevCount === null) {
                    return; // Baseline established
                }

                var isAudioActuallyPlaying = window.isRealAudioPlaying ? window.isRealAudioPlaying() : (!RadioAudio.paused());

                // 1. DISCONNECT DETECTION:
                // Audio device disconnected (currentCount < prevCount)
                var isDisconnect = (currentCount < prevCount);

                if (isDisconnect) {
                    clearTimeout(_resumeDebounceTimer);
                    window.userPaused = false; // preserve intent to resume when reconnected
                    window.setAudioInterrupted(true, 'bluetooth');
                    SilentCarrierAudio.stop();
                    RadioAudio.pause();
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                    if ('mediaSession' in navigator) {
                        try {
                            navigator.mediaSession.playbackState = 'paused';
                        } catch(e) {}
                    }
                    releasePlayerWakeLock();
                    return;
                }

                // 2. RECONNECT DETECTION:
                // Reconnection to vehicle/headphones occurs when audio device count INCREASES
                var isReconnect = (currentCount > prevCount);

                if (isReconnect && window.isAudioInterrupted() && window.isIntentStillValid()) {
                    clearTimeout(_resumeDebounceTimer);
                    _resumeDebounceTimer = setTimeout(function() {
                        if (window.isAudioInterrupted() && window.isIntentStillValid()) {
                            window.setAudioInterrupted(false);
                            window.userPaused = false;
                            savePlayingIntent(true);
                            SilentCarrierAudio.start();
                            window.recoverLiveStream('vehicle-reconnected-' + source);
                        }
                    }, 800);
                }
            }).catch(function(err) {
                _isCheckingRouteChange = false;
            });
        }

        if (typeof navigator !== 'undefined' && navigator.mediaDevices) {
            handleAudioRouteChange('init');
            if (typeof navigator.mediaDevices.addEventListener === 'function') {
                navigator.mediaDevices.addEventListener('devicechange', function() {
                    handleAudioRouteChange('devicechange');
                });
            }
        }

        var _lastPingTime = 0;
        var _isPinging = false;
        function pingNetworkStatus(callback) {
            var now = Date.now();
            if (_isPinging || (now - _lastPingTime < 2500)) return;
            _lastPingTime = now;
            _isPinging = true;

            var img = new Image();
            var timer = setTimeout(function() {
                _isPinging = false;
                img.src = '';
                if (callback) callback(false);
            }, 2500);

            img.onload = function() {
                clearTimeout(timer);
                _isPinging = false;
                if (callback) callback(true);
            };
            img.onerror = function() {
                clearTimeout(timer);
                _isPinging = false;
                if (typeof fetch === 'function') {
                    fetch('/api/stream-metadata?ping=' + Date.now(), { method: 'HEAD', cache: 'no-store' })
                        .then(function(res) {
                            if (callback) callback(res.ok || res.status < 500);
                        })
                        .catch(function() {
                            if (callback) callback(false);
                        });
                } else {
                    if (callback) callback(false);
                }
            };
            img.src = '/favicon.ico?_ping=' + now;
        }

        function runPeriodicWatchdog(source) {
            if (_resumeOnGestureArmed) {
                return;
            }

            if (window.isIntentStillValid()) {
                // Keep silent keep-alive running while intent is active and not user paused
                if (!window.userPaused && !window.audioInterruptedByDevice) {
                    SilentCarrierAudio.start();
                }

                // If paused due to verified Bluetooth / CarPlay physical disconnect, stay paused
                if (window.isAudioInterrupted()) {
                    var intType = window.safeLocalStorage.getItem(window.getStationStorageKey('interruption_type')) || '';
                    if (intType === 'bluetooth') {
                        if (window._currentPlayerState !== window.PLAYER_STATE.PAUSED) {
                            window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                        }
                        return;
                    }
                }

                var audio = RadioAudio.get();
                if (audio) {
                    var isRealPlaying = window.isRealAudioPlaying();
                    if (!isRealPlaying || audio.paused || audio.readyState < 2 || audio.error) {
                        var isOnline = (typeof navigator.onLine === 'undefined') ? true : navigator.onLine;
                        if (isOnline) {
                            window.recoverLiveStream('watchdog-' + source);
                        } else {
                            window.setPlayerState(window.PLAYER_STATE.RECONNECTING, "Waiting for network...");
                            window.showReconnectingDialogue("Network Offline", "Waiting for internet connection...", "offline");

                            // Background ping to detect cellular restore even when iOS suppresses online event
                            pingNetworkStatus(function(nowOnline) {
                                if (nowOnline && window.isIntentStillValid() && !window.userPaused) {
                                    console.log('🌐 [SmartPlayer] Background ping detected network restored!');
                                    window.recoverLiveStream('bg-ping-restored');
                                }
                            });
                        }
                    } else if (typeof audio.currentTime === 'number') {
                        if (typeof audio._lastWatchdogTime !== 'undefined' && audio.currentTime === audio._lastWatchdogTime) {
                            audio._watchdogFrozenCount = (audio._watchdogFrozenCount || 0) + 1;
                            if (audio._watchdogFrozenCount >= 2) {
                                console.warn('⚠️ Periodic watchdog: Live audio time frozen while playing — auto-recovering fresh stream...');
                                window.recoverLiveStream('watchdog-frozen-stream');
                                audio._watchdogFrozenCount = 0;
                            }
                        } else {
                            audio._lastWatchdogTime = audio.currentTime;
                            audio._watchdogFrozenCount = 0;
                        }
                    }
                }
            } else {
                if (window._currentPlayerState === window.PLAYER_STATE.PLAYING && !window.isRealAudioPlaying()) {
                    window.setPlayerState(window.PLAYER_STATE.PAUSED, "Press Play To Listen");
                }
            }
        }

        // Periodic background interval (runs every 2.5s)
        setInterval(function() {
            runPeriodicWatchdog('interval');
        }, 2500);

        // Web Worker heartbeat: keeps background tabs actively checking every 2.5s without throttling
        // This ensures reconnection occurs even while user is in Google Maps for 30 minutes
        try {
            if (typeof window.Worker !== 'undefined' && typeof window.Blob !== 'undefined') {
                var workerBlob = new Blob([
                    'var timer = setInterval(function() { postMessage("tick"); }, 2500);'
                ], { type: 'application/javascript' });
                var workerUrl = URL.createObjectURL(workerBlob);
                var bgWorker = new Worker(workerUrl);
                bgWorker.onmessage = function() {
                    runPeriodicWatchdog('worker-heartbeat');
                };
            }
        } catch (e) {}

        // Global delegated click handler for all play/pause buttons
        document.addEventListener('click', function(e) {
            var btn = e.target && e.target.closest ? e.target.closest('#mini-play-btn, [data-action="toggle-play"], #navbar-play-btn, #hero-listen-btn') : null;
            if (btn) {
                // If button has an inline onclick handler (e.g. #mini-play-btn), let the inline onclick execute directly
                if (btn.id === 'mini-play-btn' && btn.getAttribute('onclick')) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                window.toggleLiveAudio();
            }
        });

        // Safari BFCache & page restoration lifecycle handlers
        window.addEventListener('pageshow', function(e) {
            if (audioPersistenceManager) {
                audioPersistenceManager.isActiveTab = true;
                try {
                    window.safeLocalStorage.setItem(audioPersistenceManager.storageKey + '_current_tab', audioPersistenceManager.tabId);
                } catch(err) {}
            }
            attemptAutoResumeOnBack('pageshow' + (e.persisted ? '-bfcache' : ''));
        });

        window.addEventListener('pagehide', function() {
            audioPersistenceManager.saveAudioState();
        });

        window.addEventListener('popstate', function() {
            if (audioPersistenceManager) {
                audioPersistenceManager.isActiveTab = true;
            }
            attemptAutoResumeOnBack('popstate');
        });

        document.addEventListener('htmx:historyRestore', function() {
            attemptAutoResumeOnBack('htmx-historyRestore');
        });

        document.addEventListener('DOMContentLoaded', function() {
            initAudio();
            setupAudioListeners();

            var miniPlayBtn = document.getElementById('mini-play-btn');
            if (miniPlayBtn) {
                miniPlayBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.toggleLiveAudio();
                });
            }

            var playerInfo = document.querySelector('.player-info');
            if (playerInfo) {
                playerInfo.addEventListener('click', function(e) {
                    if (e.target && e.target.closest && (e.target.closest('#mini-play-btn') || e.target.closest('a') || e.target.closest('button'))) return;
                    window.toggleLiveAudio();
                });
            }

            var stickyPlayer = document.getElementById('sticky-player');
            if (stickyPlayer) {
                stickyPlayer.style.display = 'flex';
            }
        });
    </script>
