(function () {
  const settings = window.VoiceNotesSettings || {};

  const formatTime = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  };

  const supportsRecording = () => {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  };

  const getSupportedMimeType = () => {
    const types = [
      'audio/webm;codecs=opus',
      'audio/mp4;codecs=mp4a.40.2',
      'audio/ogg;codecs=opus',
    ];
    return types.find((type) => window.MediaRecorder && window.MediaRecorder.isTypeSupported(type)) || '';
  };

  const trapFocus = (modal) => {
    const focusable = modal.querySelectorAll('button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) {
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    modal.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') {
        return;
      }
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  };

  const createWaveform = (canvas, analyser) => {
    if (!canvas || !analyser) {
      return;
    }
    const ctx = canvas.getContext('2d');
    const bufferLength = analyser.fftSize;
    const dataArray = new Uint8Array(bufferLength);

    const draw = () => {
      analyser.getByteTimeDomainData(dataArray);
      ctx.fillStyle = '#f6f2f2';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.lineWidth = 2;
      ctx.strokeStyle = '#d31725';
      ctx.beginPath();

      const sliceWidth = (canvas.width * 1.0) / bufferLength;
      let x = 0;
      for (let i = 0; i < bufferLength; i += 1) {
        const v = dataArray[i] / 128.0;
        const y = (v * canvas.height) / 2;
        if (i === 0) {
          ctx.moveTo(x, y);
        } else {
          ctx.lineTo(x, y);
        }
        x += sliceWidth;
      }
      ctx.lineTo(canvas.width, canvas.height / 2);
      ctx.stroke();
      canvas.dataset.animating = requestAnimationFrame(draw);
    };

    draw();
  };

  const stopWaveform = (canvas) => {
    if (!canvas) {
      return;
    }
    const frame = canvas.dataset.animating;
    if (frame) {
      cancelAnimationFrame(Number(frame));
    }
  };

  const initModal = (modal) => {
    const dialog = modal.querySelector('.vn-modal__dialog');
    const closeButtons = modal.querySelectorAll('[data-vn-close]');
    const openButton = document.querySelector(`[data-vn-instance="${modal.dataset.vnModal}"] [data-vn-open]`);
    const overlay = modal.querySelector('[data-vn-overlay]');
    const micButton = modal.querySelector('[data-vn-mic]');
    const timer = modal.querySelector('[data-vn-timer]');
    const timerMax = timer.querySelector('.vn-timer__max');
    const waveform = modal.querySelector('[data-vn-waveform]');
    const captured = modal.querySelector('[data-vn-capture]');
    const capturedText = modal.querySelector('[data-vn-captured-text]');
    const startOver = modal.querySelector('[data-vn-start-over]');
    const submitButton = modal.querySelector('[data-vn-submit]');
    const submitWrapper = modal.querySelector('[data-vn-submit-wrapper]');
    const consentCheckbox = modal.querySelector('input[name="vn_consent"]');
    const errorBox = modal.querySelector('[data-vn-error]');
    const stateIdle = modal.querySelector('[data-vn-state="idle"]');
    const stateSuccess = modal.querySelector('[data-vn-state="success"]');
    const nameField = modal.querySelector('input[name="vn_name"]');
    const companyField = modal.querySelector('input[name="vn_company"]');
    const honeypotField = modal.querySelector('input[name="website"]');

    let mediaRecorder = null;
    let mediaStream = null;
    let audioChunks = [];
    let recordingStart = null;
    let duration = 0;
    let audioBlob = null;
    let waveformCanvas = null;
    let audioContext = null;
    let analyser = null;
    let openTimestamp = null;

    const minSeconds = Number(modal.dataset.minSeconds || settings.minSeconds || 30);
    const maxSeconds = Number(modal.dataset.maxSeconds || settings.hardMaxSeconds || 90);
    const recordLimit = Number(settings.recordLimit || 900);

    const updateSubmitState = () => {
      const hasRecording = !!audioBlob;
      const canSubmit = hasRecording && consentCheckbox.checked;
      submitButton.disabled = !canSubmit;
      const tooltipText = settings.strings?.consentTooltip || 'Please tick the consent checkbox to submit your comment.';
      if (!consentCheckbox.checked) {
        submitWrapper?.setAttribute('title', tooltipText);
      } else {
        submitWrapper?.removeAttribute('title');
      }
    };

    const resetState = () => {
      mediaRecorder = null;
      audioChunks = [];
      duration = 0;
      audioBlob = null;
      recordingStart = null;
      captured.hidden = true;
      waveform.hidden = false;
      micButton.classList.remove('is-recording');
      micButton.setAttribute('aria-label', settings.strings?.startRecording || 'Start recording');
      timer.textContent = `${formatTime(0)} `;
      timer.appendChild(timerMax);
      timerMax.textContent = `max ${formatTime(maxSeconds)}`;
      updateSubmitState();
      hideError();
      if (waveformCanvas) {
        stopWaveform(waveformCanvas);
        waveform.innerHTML = '';
        waveformCanvas = null;
      }
    };

    const showError = (message) => {
      errorBox.textContent = message;
      errorBox.hidden = false;
    };

    const hideError = () => {
      errorBox.textContent = '';
      errorBox.hidden = true;
    };

    const openModal = () => {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('vn-modal-open');
      openTimestamp = Math.floor(Date.now() / 1000);
      trapFocus(modal);
      const focusTarget = modal.querySelector('.vn-mic');
      if (focusTarget) {
        focusTarget.focus();
      }
    };

    const closeModal = () => {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('vn-modal-open');
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
      }
      resetState();
    };

    const startRecording = async () => {
      if (!supportsRecording()) {
        showError(settings.strings?.noSupport || 'Recording is not supported in this browser. Prefer to call instead?');
        return;
      }

      hideError();
      try {
        mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (error) {
        showError(settings.strings?.permissionDenied || 'Microphone access was denied. Enable microphone access or prefer to call instead.');
        return;
      }

      const mimeType = getSupportedMimeType();
      mediaRecorder = new MediaRecorder(mediaStream, mimeType ? { mimeType } : undefined);
      audioChunks = [];
      mediaRecorder.addEventListener('dataavailable', (event) => {
        if (event.data && event.data.size > 0) {
          audioChunks.push(event.data);
        }
      });

      mediaRecorder.addEventListener('stop', () => {
        audioBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        duration = Math.floor((Date.now() - recordingStart) / 1000);
        if (settings.strings?.captured) {
          capturedText.textContent = settings.strings.captured.replace('%s', duration);
        } else {
          capturedText.textContent = `Recording captured (${duration} seconds)`;
        }
        captured.hidden = false;
        waveform.hidden = true;
        stopWaveform(waveformCanvas);
        if (mediaStream) {
          mediaStream.getTracks().forEach((track) => track.stop());
        }
        if (audioContext) {
          audioContext.close();
        }
        updateSubmitState();
      });

      micButton.classList.add('is-recording');
      micButton.setAttribute('aria-label', settings.strings?.stopRecording || 'Stop recording');
      recordingStart = Date.now();
      mediaRecorder.start();

      if (mediaStream && mediaStream.getAudioTracks().length) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const source = audioContext.createMediaStreamSource(mediaStream);
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;
        source.connect(analyser);
        waveformCanvas = document.createElement('canvas');
        waveformCanvas.width = waveform.clientWidth || 280;
        waveformCanvas.height = 60;
        waveform.innerHTML = '';
        waveform.appendChild(waveformCanvas);
        createWaveform(waveformCanvas, analyser);
      }

      const tick = () => {
        if (!recordingStart) {
          return;
        }
        const elapsed = Math.floor((Date.now() - recordingStart) / 1000);
        timer.textContent = `${formatTime(elapsed)} `;
        timer.appendChild(timerMax);
        timerMax.textContent = `max ${formatTime(maxSeconds)}`;
        if (mediaRecorder && mediaRecorder.state === 'recording' && elapsed < recordLimit) {
          requestAnimationFrame(tick);
        } else if (mediaRecorder && mediaRecorder.state === 'recording') {
          mediaRecorder.stop();
        }
      };
      tick();
    };

    const stopRecording = () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
      }
      if (mediaStream) {
        mediaStream.getTracks().forEach((track) => track.stop());
      }
    };

    const submitRecording = async () => {
      hideError();
      if (!audioBlob) {
        showError(settings.strings?.noRecording || 'Please record your voice note before submitting.');
        return;
      }
      if (!consentCheckbox.checked) {
        showError(settings.strings?.consentRequired || 'Please confirm consent to continue.');
        return;
      }
      if (duration < minSeconds) {
        const message = settings.strings?.tooShort
          ? settings.strings.tooShort.replace('%s', minSeconds)
          : `Recording must be at least ${minSeconds} seconds.`;
        showError(message);
        return;
      }
      if (maxSeconds > 0 && duration > maxSeconds) {
        const message = settings.strings?.tooLong
          ? settings.strings.tooLong.replace('%s', maxSeconds)
          : `Recording must be under ${maxSeconds} seconds.`;
        showError(message);
        return;
      }

      submitButton.disabled = true;
      submitButton.classList.add('is-loading');

      const formData = new FormData();
      const extensionMap = {
        'audio/webm': 'webm',
        'audio/ogg': 'ogg',
        'audio/mp4': 'm4a',
        'audio/x-m4a': 'm4a',
        'video/mp4': 'mp4',
      };
      const mimeType = audioBlob.type.split(';')[0];
      const extension = extensionMap[mimeType] || 'webm';

      formData.append('audio', audioBlob, `voice-note.${extension}`);
      formData.append('name', nameField.value);
      formData.append('company', companyField.value);
      formData.append('consent', consentCheckbox.checked ? '1' : '0');
      formData.append('duration', String(duration));
      formData.append('page_url', window.location.href);
      formData.append('recipient_email', modal.dataset.recipient || '');
      formData.append('opened_at', String(openTimestamp || ''));
      formData.append('website', honeypotField.value);

      try {
        const response = await fetch(settings.restUrl, {
          method: 'POST',
          headers: {
            'X-WP-Nonce': settings.nonce,
          },
          body: formData,
        });

        if (!response.ok) {
          const data = await response.json();
          const message = data && data.message ? data.message : settings.strings?.uploadFailed || 'Upload failed. Please try again.';
          showError(message);
          submitButton.disabled = false;
          submitButton.classList.remove('is-loading');
          return;
        }

        stateIdle.hidden = true;
        stateSuccess.hidden = false;
      } catch (error) {
        showError(settings.strings?.networkFailed || 'Upload failed. Please check your connection and try again.');
        submitButton.disabled = false;
        submitButton.classList.remove('is-loading');
      }
    };

    micButton.addEventListener('click', () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopRecording();
      } else {
        startRecording();
      }
    });

    startOver.addEventListener('click', () => {
      resetState();
    });

    consentCheckbox.addEventListener('change', updateSubmitState);

    submitButton.addEventListener('click', submitRecording);

    closeButtons.forEach((button) => {
      button.addEventListener('click', closeModal);
    });

    overlay.addEventListener('click', () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        showError(settings.strings?.stopBeforeClose || 'Stop the recording before closing.');
        return;
      }
      closeModal();
    });

    document.addEventListener('keydown', (event) => {
      if (!modal.classList.contains('is-open')) {
        return;
      }
      if (event.key === 'Escape') {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          showError(settings.strings?.stopBeforeClose || 'Stop the recording before closing.');
        } else {
          closeModal();
        }
      }
    });

    if (openButton) {
      openButton.addEventListener('click', openModal);
    }

    if (modal.dataset.autoOpen === 'true') {
      openModal();
    }

    resetState();
  };

  document.addEventListener('DOMContentLoaded', () => {
    const modals = document.querySelectorAll('[data-vn-modal]');
    if (!modals.length) {
      return;
    }
    modals.forEach((modal) => initModal(modal));
  });
})();
