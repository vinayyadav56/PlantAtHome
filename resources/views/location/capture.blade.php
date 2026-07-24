<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Share Your Location — PlantAtHome</title>
<style>
  :root { --green:#4E8B31; --green-dark:#2E5E2A; --forest:#16301A; --soft:#EAF4E6; --ink:#1f2937; --muted:#6b7280; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#F6F5F0; color:var(--ink); min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px; }
  .brand { display:flex; align-items:center; gap:10px; margin-bottom:28px; }
  .brand-mark { width:36px; height:36px; border-radius:10px; background:var(--green); display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; }
  .brand-name { font-weight:700; color:var(--forest); font-size:18px; }
  .brand-sub { font-size:11px; color:var(--muted); letter-spacing:.04em; }
  .card { background:#fff; border:1px solid #E7E5DC; border-radius:20px; box-shadow:0 10px 30px rgba(22,48,26,.07); padding:40px 32px; max-width:460px; width:100%; text-align:center; }
  .icon { width:88px; height:88px; margin:0 auto 24px; border-radius:50%; background:var(--soft); display:flex; align-items:center; justify-content:center; }
  .icon svg { width:44px; height:44px; }
  h1 { color:var(--forest); font-size:24px; margin-bottom:12px; }
  p.desc { color:var(--muted); font-size:14.5px; line-height:1.65; margin-bottom:8px; }
  p.small { color:#9ca3af; font-size:12.5px; line-height:1.6; margin-top:14px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; margin-top:24px; background:var(--green); color:#fff; border:none; border-radius:12px; padding:14px 30px; font-size:15px; font-weight:600; cursor:pointer; width:100%; transition:background .15s; font-family:inherit; }
  .btn:hover { background:var(--green-dark); }
  .btn:disabled { opacity:.6; cursor:wait; }
  .error-box { display:none; margin-top:18px; background:#FBF1F1; border:1px solid #F0D9D9; color:#B23B3B; border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.55; text-align:left; }
  .spinner { display:none; width:20px; height:20px; border:3px solid rgba(255,255,255,.35); border-top-color:#fff; border-radius:50%; animation:spin .8s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .footer { margin-top:26px; color:#9ca3af; font-size:12px; text-align:center; }
</style>
</head>
<body>
  <div class="brand">
    <div class="brand-mark">&#127807;</div>
    <div>
      <div class="brand-name">Plant At Home</div>
      <div class="brand-sub">BRINGING NATURE TO YOU</div>
    </div>
  </div>

@if ($state === 'capture')
  <div class="card" id="capture-card">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#4E8B31" stroke-width="1.7">
        <path d="M12 21s-6.5-5.3-6.5-10.2A6.5 6.5 0 0112 4.5a6.5 6.5 0 016.5 6.3C18.5 15.7 12 21 12 21z" stroke-linejoin="round"/>
        <circle cx="12" cy="10.8" r="2.2"/>
      </svg>
    </div>
    <h1>Share Your Location</h1>
    <p class="desc">PlantAtHome requires your precise location to improve deliveries and connect you with nearby nurseries.</p>
    <p class="desc">Your location is securely stored and only used for delivery and service purposes.</p>
    <button class="btn" id="share-btn" type="button">
      <span class="spinner" id="spinner"></span>
      <span id="btn-label">Share My Location</span>
    </button>
    <div class="error-box" id="error-box"></div>
    <p class="small">Works best on your phone with GPS / precise location enabled.</p>
  </div>

  <div class="card" id="success-card" style="display:none">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#4E8B31" stroke-width="2">
        <circle cx="12" cy="12" r="9.5" stroke-width="1.7"/>
        <path d="M7.5 12.5l3 3 6-6.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1>Location Saved Successfully</h1>
    <p class="desc">Thank you. Your location has been securely stored.</p>
    <p class="desc">You may now close this page.</p>
  </div>

  <script>
    (function () {
      var btn = document.getElementById('share-btn');
      var label = document.getElementById('btn-label');
      var spinner = document.getElementById('spinner');
      var errorBox = document.getElementById('error-box');
      var timedOutOnce = false;

      function setBusy(busy, text) {
        btn.disabled = busy;
        spinner.style.display = busy ? 'inline-block' : 'none';
        label.textContent = text;
      }
      function showError(message, retryText) {
        errorBox.innerHTML = message.replace(/\n/g, '<br>');
        errorBox.style.display = 'block';
        setBusy(false, retryText || 'Retry');
      }

      function submit(position) {
        setBusy(true, 'Saving your location…');
        fetch('{{ url('/location/submit') }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
          body: JSON.stringify({
            token: @json($token),
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy
          })
        })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          if (result.ok && result.data.success) {
            document.getElementById('capture-card').style.display = 'none';
            document.getElementById('success-card').style.display = 'block';
          } else {
            showError(result.data.message || 'Could not save your location. Please try again.');
          }
        })
        .catch(function () {
          showError('Network problem while saving your location.\nPlease check your connection and try again.');
        });
      }

      function locate() {
        if (!('geolocation' in navigator)) {
          showError('Your browser does not support location sharing.\nPlease open this link on your phone (Chrome or Safari) and try again.', 'Try Again');
          return;
        }
        errorBox.style.display = 'none';
        setBusy(true, 'Requesting your location…');
        navigator.geolocation.getCurrentPosition(
          submit,
          function (err) {
            if (err.code === 1) { // permission denied
              showError('Location permission denied.\nPlease enable location permission for this site in your browser settings and try again.');
            } else if (err.code === 3 && !timedOutOnce) { // timeout → auto-retry once
              timedOutOnce = true;
              locate();
            } else if (err.code === 3) {
              showError('Locating you took too long.\nMove near a window or outdoors and try again.');
            } else {
              showError('Could not determine your location.\nPlease try again.');
            }
          },
          { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
      }

      btn.addEventListener('click', locate);
    })();
  </script>

@elseif ($state === 'expired')
  <div class="card">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#B98A2E" stroke-width="1.7">
        <circle cx="12" cy="12" r="8.5"/>
        <path d="M12 7.5V12l3 2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1>Link Expired</h1>
    <p class="desc">This location request has expired.</p>
    <p class="desc">Please request a new location capture email.</p>
  </div>

@elseif ($state === 'used')
  <div class="card">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#4E8B31" stroke-width="2">
        <circle cx="12" cy="12" r="9.5" stroke-width="1.7"/>
        <path d="M7.5 12.5l3 3 6-6.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1>Already Saved</h1>
    <p class="desc">Your location has already been captured for this request.</p>
    <p class="desc">You may close this page.</p>
  </div>

@else
  <div class="card">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#B23B3B" stroke-width="1.7">
        <circle cx="12" cy="12" r="8.5"/>
        <path d="M9 9l6 6M15 9l-6 6" stroke-linecap="round"/>
      </svg>
    </div>
    <h1>Invalid Request</h1>
    <p class="desc">Invalid location request.</p>
  </div>
@endif

  <div class="footer">&copy; {{ date('Y') }} Plant At Home &middot; plantathome.in</div>
</body>
</html>
