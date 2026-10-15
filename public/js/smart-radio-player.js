/**
 * Promise Polyfill for legacy Safari / iOS WebKit engines
 */
if (typeof window.Promise === 'undefined') {
  (function() {
    function PromisePolyfill(executor) {
      var self = this;
      self._state = 'pending';
      self._value = undefined;
      self._handlers = [];

      function resolve(result) {
        if (self._state !== 'pending') return;
        self._state = 'fulfilled';
        self._value = result;
        for (var i = 0; i < self._handlers.length; i++) {
          self._handlers[i].onFulfilled(result);
        }
      }

      function reject(error) {
        if (self._state !== 'pending') return;
        self._state = 'rejected';
        self._value = error;
        for (var i = 0; i < self._handlers.length; i++) {
          self._handlers[i].onRejected(error);
        }
      }

      try {
        executor(resolve, reject);
      } catch (e) {
        reject(e);
      }
    }

    PromisePolyfill.prototype.then = function(onFulfilled, onRejected) {
      var self = this;
      return new PromisePolyfill(function(resolve, reject) {
        var handler = {
          onFulfilled: function(val) {
            if (typeof onFulfilled === 'function') {
              try { resolve(onFulfilled(val)); } catch (e) { reject(e); }
            } else { resolve(val); }
          },
          onRejected: function(err) {
            if (typeof onRejected === 'function') {
              try { resolve(onRejected(err)); } catch (e) { reject(e); }
            } else { reject(err); }
          }
        };
        if (self._state === 'fulfilled') {
          setTimeout(function() { handler.onFulfilled(self._value); }, 0);
        } else if (self._state === 'rejected') {
          setTimeout(function() { handler.onRejected(self._value); }, 0);
        } else {
          self._handlers.push(handler);
        }
      });
    };

    PromisePolyfill.prototype.catch = function(onRejected) {
      return this.then(null, onRejected);
    };

    PromisePolyfill.resolve = function(value) {
      return new PromisePolyfill(function(resolve) { resolve(value); });
    };

    PromisePolyfill.reject = function(reason) {
      return new PromisePolyfill(function(resolve, reject) { reject(reason); });
    };

    window.Promise = PromisePolyfill;
  })();
}

/**
 * AudioRouteDetector (ES5 Cross-Browser Compatible)
 * Monitors system audio output devices to detect Bluetooth speakers, headphones, etc.
 */
function AudioRouteDetector(options) {
  options = options || {};
  this.onRouteConnected    = options.onRouteConnected    || function() {};
  this.onRouteDisconnected = options.onRouteDisconnected || function() {};

  this._previousOutputDevices = [];
  this._monitoring = false;
  this._initialSnapshotDone = false;
  this._boundOnDeviceChange = this._onDeviceChange.bind(this);
  this._debounceTimer = null;
}

AudioRouteDetector.prototype.start = function() {
  var self = this;
  if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
    console.warn('[AudioRouteDetector] MediaDevices API not available — device detection disabled.');
    return Promise.resolve(false);
  }

  return this._snapshotDevices().then(function() {
    self._initialSnapshotDone = true;
    navigator.mediaDevices.addEventListener('devicechange', self._boundOnDeviceChange);
    self._monitoring = true;
    console.info('[AudioRouteDetector] Monitoring audio output devices. Initial count:', self._previousOutputDevices.length);
    return true;
  });
};

AudioRouteDetector.prototype.stop = function() {
  if (this._monitoring && navigator.mediaDevices) {
    navigator.mediaDevices.removeEventListener('devicechange', this._boundOnDeviceChange);
    this._monitoring = false;
  }
  clearTimeout(this._debounceTimer);
};

AudioRouteDetector.prototype._snapshotDevices = function() {
  var self = this;
  if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
    self._previousOutputDevices = [];
    return Promise.resolve();
  }
  return navigator.mediaDevices.enumerateDevices().then(function(all) {
    self._previousOutputDevices = [];
    if (all && all.length) {
      for (var i = 0; i < all.length; i++) {
        var d = all[i];
        if (d.kind === 'audiooutput' && d.deviceId !== 'default' && d.deviceId !== 'communications') {
          self._previousOutputDevices.push(d);
        }
      }
    }
  }).catch(function() {
    self._previousOutputDevices = [];
  });
};

AudioRouteDetector.prototype._isExternalDevice = function(device) {
  var label = (device.label || '').toLowerCase();
  if (!label) return false;
  if (/built.?in|internal|system default/i.test(label)) return false;
  return true;
};

AudioRouteDetector.prototype._onDeviceChange = function() {
  var self = this;
  clearTimeout(this._debounceTimer);
  this._debounceTimer = setTimeout(function() {
    self._processDeviceChange();
  }, 400);
};

AudioRouteDetector.prototype._processDeviceChange = function() {
  if (!this._initialSnapshotDone) return;
  var self = this;
  var prevDevices = this._previousOutputDevices;
  this._snapshotDevices().then(function() {
    var currDevices = self._previousOutputDevices;
    var prevIds = {};
    for (var i = 0; i < prevDevices.length; i++) { prevIds[prevDevices[i].deviceId] = true; }
    var currIds = {};
    for (var j = 0; j < currDevices.length; j++) { currIds[currDevices[j].deviceId] = true; }

    var removed = [];
    for (var k = 0; k < prevDevices.length; k++) {
      if (!currIds[prevDevices[k].deviceId] && self._isExternalDevice(prevDevices[k])) {
        removed.push(prevDevices[k]);
      }
    }
    var added = [];
    for (var m = 0; m < currDevices.length; m++) {
      if (!prevIds[currDevices[m].deviceId] && self._isExternalDevice(currDevices[m])) {
        added.push(currDevices[m]);
      }
    }

    if (removed.length > 0 && added.length === 0) {
      console.info('[AudioRouteDetector] Audio output device REMOVED');
      self.onRouteDisconnected(removed);
    } else if (added.length > 0) {
      console.info('[AudioRouteDetector] Audio output device CONNECTED');
      self.onRouteConnected(added);
    }
  });
};


/**
 * SmartRadioPlayer (ES5 Cross-Browser Compatible)
 * Robust Player Engine with Formal State Machine & Auto-Recovery for Safari / iOS
 */
function SmartRadioPlayer(options) {
  options = options || {};
  if (!options.streamUrl) throw new Error('SmartRadioPlayer: streamUrl is required');

  this.streamUrl      = options.streamUrl;
  this.audioElId      = options.audioElementId || 'live-audio';
  this.onStateChange  = options.onStateChange  || function() {};

  this.audio                 = null;
  this.state                 = SmartRadioPlayer.STATES.IDLE;
  this.userIntentPlay        = false;
  this.isMuted               = false;

  this.retryCount            = 0;
  this.maxRetries            = 10;
  this.baseRetryDelay        = 1000;
  this.maxRetryDelay         = 30000;
  this.reconnectTimer        = null;
  this.stalledTimer          = null;

  this._boundHandlers        = {};
  this._routeDetector        = null;
  this._isInternalAction     = false;
  this._lastInterruptionTime = 0;
  this._lastUserPlayTime     = 0;
  this._awaitingDeviceReturn = false;
}

SmartRadioPlayer.STATES = {
  IDLE:         'IDLE',
  LOADING:      'LOADING',
  PLAYING:      'PLAYING',
  PAUSED:       'PAUSED',
  BUFFERING:    'BUFFERING',
  INTERRUPTED:  'INTERRUPTED',
  RECONNECTING: 'RECONNECTING',
  FAILED:       'FAILED',
  STOPPED:      'STOPPED'
};

SmartRadioPlayer.prototype.transitionTo = function(newState, reason) {
  reason = reason || '';
  var oldState = this.state;
  if (oldState === newState) return;

  console.info('[SmartRadioPlayer State] ' + oldState + ' -> ' + newState + (reason ? ' (' + reason + ')' : ''));
  this.state = newState;

  if ('mediaSession' in navigator) {
    if (newState === SmartRadioPlayer.STATES.PLAYING) {
      navigator.mediaSession.playbackState = 'playing';
      if (typeof window.reassertMediaSessionMetadata === 'function') {
        window.reassertMediaSessionMetadata(true);
      }
    } else if (newState === SmartRadioPlayer.STATES.PAUSED || newState === SmartRadioPlayer.STATES.STOPPED) {
      navigator.mediaSession.playbackState = 'paused';
    }
  }

  try {
    this.onStateChange(newState, oldState, reason);
  } catch (e) {
    console.error('[SmartRadioPlayer] Error in state change handler:', e);
  }
};

SmartRadioPlayer.prototype.init = function() {
  this._setupAudioElement();
  this._attachAudioEvents();
  this._attachVisibilityEvents();
  this._attachNetworkEvents();
  this._attachNativeRouteBridge();
  this._attachUserGestureEvents();
  this._startAudioRouteDetection();
  this.transitionTo(SmartRadioPlayer.STATES.IDLE, 'Initialized');
};

SmartRadioPlayer.prototype._setupAudioElement = function() {
  var el = document.getElementById(this.audioElId);
  if (!el) {
    el = document.createElement('audio');
    el.id            = this.audioElId;
    el.preload       = 'metadata';
    el.style.cssText = 'position: fixed; bottom: 0; left: 0; width: 1px; height: 1px; opacity: 0.01; pointer-events: none; z-index: -1;';
    document.body.appendChild(el);
  }
  el.setAttribute('playsinline', 'true');
  el.setAttribute('webkit-playsinline', 'true');
  el.setAttribute('x-webkit-airplay', 'allow');
  el.preload = 'metadata';
  el.removeAttribute('crossorigin');

  if (window.location.protocol === 'https:' && this.streamUrl.indexOf('http:') === 0) {
    this.streamUrl = this.streamUrl.replace(/^http:/, 'https:');
  }

  if (!el.src || el.src === '' || el.src === window.location.href) {
    el.src = this.streamUrl;
  }
  this.audio = el;
};

SmartRadioPlayer.prototype.userPlay = function() {
  console.info('[SmartRadioPlayer] userPlay() triggered');
  this.userIntentPlay = true;
  this.retryCount = 0;
  this._awaitingDeviceReturn = false;
  this._lastUserPlayTime = Date.now();
  return this.connectAndPlay('User action');
};

SmartRadioPlayer.prototype.userPause = function() {
  console.info('[SmartRadioPlayer] userPause() triggered');
  this.userIntentPlay = false;
  this._awaitingDeviceReturn = false;
  this._clearTimers();
  this._safePause();
  this.transitionTo(SmartRadioPlayer.STATES.PAUSED, 'User paused');
};

SmartRadioPlayer.prototype.stop = function() {
  console.info('[SmartRadioPlayer] stop() triggered');
  this.userIntentPlay = false;
  this._awaitingDeviceReturn = false;
  this._clearTimers();
  this._safePause();
  if (this.audio) {
    this.audio.removeAttribute('src');
    if (this.audio.load) {
      try { this.audio.load(); } catch (e) {}
    }
  }
  this.transitionTo(SmartRadioPlayer.STATES.STOPPED, 'Player stopped');
};

SmartRadioPlayer.prototype.toggle = function() {
  if (this.state === SmartRadioPlayer.STATES.PLAYING) {
    this.userPause();
    return Promise.resolve();
  } else {
    return this.userPlay();
  }
};

SmartRadioPlayer.prototype.play = function() { return this.userPlay(); };
SmartRadioPlayer.prototype.pause = function() { return this.userPause(); };
SmartRadioPlayer.prototype.resume = function() { return this.userPlay(); };
SmartRadioPlayer.prototype.setUserPaused = function(val) {
  if (val) {
    this.userPause();
  } else {
    this.userPlay();
  }
};

SmartRadioPlayer.prototype._cacheBustUrl = function() {
  return this.streamUrl;
};

SmartRadioPlayer.prototype.connectAndPlay = function(reason) {
  reason = reason || '';
  this._clearTimers();
  if (!this.audio) return Promise.reject(new Error('Audio element not initialized'));

  this.transitionTo(SmartRadioPlayer.STATES.LOADING, reason);

  var streamTarget = this.streamUrl;
  if (window.location.protocol === 'https:' && streamTarget.indexOf('http:') === 0) {
    streamTarget = streamTarget.replace(/^http:/, 'https:');
    this.streamUrl = streamTarget;
  }

  this._isInternalAction = true;

  var currentSrc = this.audio.src || this.audio.currentSrc || '';
  var cleanTarget = streamTarget.replace(/^https?:/, '');
  var srcMismatch = !currentSrc || currentSrc.indexOf(cleanTarget) === -1;
  var hasError = !!this.audio.error;

  if (hasError || srcMismatch) {
    this.audio.src = streamTarget;
    if (hasError && this.audio.load) {
      try { this.audio.load(); } catch (e) {}
    }
  }
  this._isInternalAction = false;

  var self = this;
  try {
    // Check if current audio element lacks .play() (e.g. legacy Safari on Windows without QuickTime)
    if (!this.audio || !this.audio.play) {
      if (typeof Audio !== 'undefined') {
        try {
          var altAudio = new Audio(streamTarget);
          if (altAudio && altAudio.play) {
            this.audio = altAudio;
            this._attachAudioEvents();
          }
        } catch (e) {}
      }
    }

    // If browser engine completely lacks HTML5 audio decoder support
    if (!this.audio || !this.audio.play) {
      console.warn('[SmartRadioPlayer] HTML5 audio playback not supported by browser engine. Launching stream in new tab.');
      try {
        window.open(streamTarget, '_blank');
      } catch (e) {}
      self.retryCount = 0;
      self.transitionTo(SmartRadioPlayer.STATES.PLAYING, 'Playback launched in stream tab');
      return Promise.resolve();
    }

    var playPromise = this.audio.play();
    if (playPromise && typeof playPromise.then === 'function') {
      return playPromise
        .then(function() {
          self.retryCount = 0;
          // Set state to PLAYING, with fallback confirmation
          self.transitionTo(SmartRadioPlayer.STATES.PLAYING, 'Playback started');
        })
        .catch(function(err) {
          if (err && err.name === 'NotAllowedError') {
            console.warn('[SmartRadioPlayer] Autoplay blocked by browser policy:', err);
            self.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'Autoplay blocked');
          } else if (err && err.name === 'AbortError') {
            console.debug('[SmartRadioPlayer] Play request interrupted or superseded.');
          } else {
            console.error('[SmartRadioPlayer] Playback error on connect:', err);
            if (self.userIntentPlay) {
              self._handleFailure(err);
            }
          }
          throw err;
        });
    } else {
      // Legacy WebKit / Safari 7-9 where audio.play() returns void (undefined)
      self.retryCount = 0;
      self.transitionTo(SmartRadioPlayer.STATES.PLAYING, 'Playback started (legacy browser)');
      return Promise.resolve();
    }
  } catch (syncErr) {
    console.error('[SmartRadioPlayer] Synchronous play error:', syncErr);
    if (this.userIntentPlay) {
      this._handleFailure(syncErr);
    }
    return Promise.reject(syncErr);
  }
};

SmartRadioPlayer.prototype._scheduleReconnect = function(reason) {
  reason = reason || 'Interruption recovery';
  this._clearTimers();
  if (!this.userIntentPlay) return;

  if (this.retryCount >= this.maxRetries) {
    console.error('[SmartRadioPlayer] Max retries reached. Moving to FAILED state.');
    this.transitionTo(SmartRadioPlayer.STATES.FAILED, 'Max reconnect retries exceeded');
    return;
  }

  this.retryCount++;
  var expDelay = Math.min(this.maxRetryDelay, this.baseRetryDelay * Math.pow(2, this.retryCount - 1));
  var jitter = Math.floor(Math.random() * 500);
  var delay = expDelay + jitter;

  console.info('[SmartRadioPlayer] Scheduling reconnect attempt ' + this.retryCount + '/' + this.maxRetries + ' in ' + Math.round(delay) + 'ms.');
  this.transitionTo(SmartRadioPlayer.STATES.RECONNECTING, 'Attempt ' + this.retryCount);

  var self = this;
  this.reconnectTimer = setTimeout(function() {
    if (self.userIntentPlay) {
      if (self.audio) {
        self.audio.src = self.streamUrl;
      }
      self.connectAndPlay('Reconnect attempt ' + self.retryCount);
    }
  }, delay);
};

SmartRadioPlayer.prototype._handleFailure = function(error) {
  if (!this.userIntentPlay) {
    this.transitionTo(SmartRadioPlayer.STATES.PAUSED, 'Error while paused');
    return;
  }

  if (typeof navigator.onLine !== 'undefined' && !navigator.onLine) {
    this.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'Network offline');
    return;
  }

  this._scheduleReconnect('Stream load error');
};

SmartRadioPlayer.prototype._safePause = function() {
  if (this.audio && this.audio.pause) {
    try {
      if (!this.audio.paused) {
        this._isInternalAction = true;
        this.audio.pause();
        this._isInternalAction = false;
      }
    } catch (e) {}
  }
};

SmartRadioPlayer.prototype._attachAudioEvents = function() {
  var self = this;
  var ev = function(name, fn) {
    self._boundHandlers[name] = fn;
    if (self.audio && typeof self.audio.addEventListener === 'function') {
      self.audio.addEventListener(name, fn);
    }
  };

  ev('playing', function() {
    self.retryCount = 0;
    self.transitionTo(SmartRadioPlayer.STATES.PLAYING, 'HTML5 playing event');
  });

  ev('timeupdate', function() {
    if (self.userIntentPlay && self.state !== SmartRadioPlayer.STATES.PLAYING) {
      if (self.audio && typeof self.audio.currentTime === 'number' && self.audio.currentTime > 0) {
        self.retryCount = 0;
        self.transitionTo(SmartRadioPlayer.STATES.PLAYING, 'HTML5 audio progress');
      }
    }
  });

  ev('pause', function() {
    if (self._isInternalAction) return;

    if (self.state === SmartRadioPlayer.STATES.LOADING || self.state === SmartRadioPlayer.STATES.BUFFERING || self.state === SmartRadioPlayer.STATES.RECONNECTING) {
      console.debug('[SmartRadioPlayer] Ignored transient pause event during loading/buffering.');
      return;
    }

    // Ignore transient pause events within 6 seconds of user starting playback (WebKit buffer initialization)
    var elapsedSincePlay = Date.now() - (self._lastUserPlayTime || 0);
    if (self.userIntentPlay && elapsedSincePlay < 6000) {
      console.debug('[SmartRadioPlayer] Ignored startup pause event during initial stream buffering.');
      return;
    }

    if (self.userIntentPlay && self.state === SmartRadioPlayer.STATES.PLAYING) {
      console.warn('[SmartRadioPlayer] Unintended pause detected — transitioning to INTERRUPTED state.');
      self._lastInterruptionTime = Date.now();
      self.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'System pause');
      
      if (!self._awaitingDeviceReturn) {
        self._scheduleReconnect('System pause recovery');
      }
    } else if (!self.userIntentPlay) {
      self.transitionTo(SmartRadioPlayer.STATES.PAUSED, 'User pause event');
    }
  });

  ev('waiting', function() {
    if (self.userIntentPlay && self.state === SmartRadioPlayer.STATES.PLAYING) {
      self.transitionTo(SmartRadioPlayer.STATES.BUFFERING, 'Stall/Buffering stream data');
      clearTimeout(self.stalledTimer);
      self.stalledTimer = setTimeout(function() {
        if (self.state === SmartRadioPlayer.STATES.BUFFERING && self.userIntentPlay) {
          console.warn('[SmartRadioPlayer] Buffering timeout (5s) — forcing live edge reconnection.');
          self.connectAndPlay('Buffering timeout recovery');
        }
      }, 5000);
    }
  });

  ev('stalled', function() {
    if (self.userIntentPlay && self.state === SmartRadioPlayer.STATES.PLAYING && self.state !== SmartRadioPlayer.STATES.RECONNECTING) {
      console.warn('[SmartRadioPlayer] HTML5 audio stalled event.');
      self._scheduleReconnect('Stream stalled');
    }
  });

  ev('error', function(e) {
    console.error('[SmartRadioPlayer] HTML5 audio error event:', e);
    self._handleFailure(e);
  });

  ev('ratechange', function() {
    if (self.audio && self.audio.playbackRate !== 1) {
      self.audio.playbackRate = 1;
    }
  });
};

SmartRadioPlayer.prototype._attachVisibilityEvents = function() {
  var self = this;
  this._boundHandlers.visibilityChange = function() {
    if (document.hidden) {
      // Keep playing in background
    } else {
      if (self.userIntentPlay) {
        var isPaused = self.audio ? self.audio.paused : true;
        if (isPaused || self.state === SmartRadioPlayer.STATES.INTERRUPTED || self.state === SmartRadioPlayer.STATES.FAILED) {
          console.info('[SmartRadioPlayer] Foreground returned & user wants play — reconnecting.');
          self.connectAndPlay('Foreground recovery');
        }
      }
    }
  };
  document.addEventListener('visibilitychange', this._boundHandlers.visibilityChange);
};

SmartRadioPlayer.prototype._attachNetworkEvents = function() {
  var self = this;
  this._boundHandlers.online = function() {
    console.info('[SmartRadioPlayer] Network online event received.');
    if (self.userIntentPlay && !self._awaitingDeviceReturn) {
      self.retryCount = 0;
      self.connectAndPlay('Network restored');
    }
  };

  this._boundHandlers.offline = function() {
    console.warn('[SmartRadioPlayer] Network offline event received.');
    self.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'Network disconnected');
  };

  window.addEventListener('online',  this._boundHandlers.online);
  window.addEventListener('offline', this._boundHandlers.offline);
};

SmartRadioPlayer.prototype._startAudioRouteDetection = function() {
  if (this._isIOSSafari()) {
    console.info('[SmartRadioPlayer] iOS Safari detected: route detection handled natively.');
    return;
  }

  var self = this;
  this._routeDetector = new AudioRouteDetector({
    onRouteConnected: function(devices) {
      console.info('[SmartRadioPlayer] Audio route connected.');
      self._awaitingDeviceReturn = false;
      if (self.userIntentPlay) {
        self.connectAndPlay('Audio route connected');
      }
    },
    onRouteDisconnected: function(devices) {
      console.info('[SmartRadioPlayer] Audio route disconnected.');
      if (self.userIntentPlay) {
        self._awaitingDeviceReturn = true;
        self._clearTimers();
        self._safePause();
        self.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'Audio output route removed');
      }
    }
  });

  this._routeDetector.start();
};

SmartRadioPlayer.prototype._attachNativeRouteBridge = function() {
  var self = this;
  this._boundHandlers.routeChange = function(event) {
    var detail = (event && event.detail) || {};
    var connected = (typeof detail.connected !== 'undefined') ? detail.connected : (typeof detail.bluetoothConnected !== 'undefined' ? detail.bluetoothConnected : null);
    if (connected === true) {
      self._awaitingDeviceReturn = false;
      if (self.userIntentPlay) self.connectAndPlay('Bridge route connected');
    } else if (connected === false) {
      self._awaitingDeviceReturn = true;
      self._clearTimers();
      self._safePause();
      self.transitionTo(SmartRadioPlayer.STATES.INTERRUPTED, 'Bridge route disconnected');
    }
  };

  window.addEventListener('station:audio-route-change', this._boundHandlers.routeChange);
};

SmartRadioPlayer.prototype._attachUserGestureEvents = function() {
  var self = this;
  var onUserGesture = function(e) {
    if (e && e.target && e.target.closest && (e.target.closest('#mini-play-btn') || e.target.closest('.player-info') || e.target.closest('#hero-listen-btn'))) {
      return;
    }
    if (self.userIntentPlay && (self.state === SmartRadioPlayer.STATES.INTERRUPTED || self.state === SmartRadioPlayer.STATES.FAILED)) {
      console.info('[SmartRadioPlayer] User gesture detected — resuming live edge stream.');
      self.connectAndPlay('User gesture auto-resume');
    }
  };
  window.addEventListener('touchstart', onUserGesture, { passive: true });
  window.addEventListener('click', onUserGesture, { passive: true });
};

SmartRadioPlayer.prototype._clearTimers = function() {
  clearTimeout(this.reconnectTimer);
  clearTimeout(this.stalledTimer);
};

SmartRadioPlayer.prototype._isIOSSafari = function() {
  var ua = navigator.userAgent || '';
  var platform = navigator.platform || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  return isIOS;
};

SmartRadioPlayer.prototype.destroy = function() {
  this.stop();
  if (this._routeDetector) this._routeDetector.stop();
  if (this.audio && this._boundHandlers) {
    for (var name in this._boundHandlers) {
      if (Object.prototype.hasOwnProperty.call(this._boundHandlers, name)) {
        this.audio.removeEventListener(name, this._boundHandlers[name]);
      }
    }
  }
};

window.AudioRouteDetector = AudioRouteDetector;
window.SmartRadioPlayer = SmartRadioPlayer;
