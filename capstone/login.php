<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/login_snapshot.php';
require_once __DIR__ . '/includes/security_headers.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$_SESSION['login_captcha_n1'] = random_int(1, 12);
$_SESSION['login_captcha_n2'] = random_int(1, 12);
$_SESSION['login_captcha_expected'] = (int)$_SESSION['login_captcha_n1'] + (int)$_SESSION['login_captcha_n2'];

$snapshot  = vip_login_snapshot($conn);
$captchaN1 = (int)$_SESSION['login_captcha_n1'];
$captchaN2 = (int)$_SESSION['login_captcha_n2'];
$csrfToken = (string)$_SESSION['login_csrf'];

function vip_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Villanueva Ice Plant</title>
<meta name="description" content="Secure operator login for Villanueva Ice Plant management system.">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@1.16.0/dist/umd/lucide.min.js" integrity="sha384-ZgnJ3Zpr70Xoify35DjOZWqHib1iYJBpYpQUIEpDASG9+fJ745WzNQuC004dwU0W" crossorigin="anonymous"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: {
          sans: ['Plus Jakarta Sans', 'Poppins', 'sans-serif'],
        },
        colors: {
          brand: {
            50: '#f5f3ff',
            100: '#ede9fe',
            200: '#ddd6fe',
            300: '#c4b5fd',
            400: '#a78bfa',
            500: '#8b5cf6',
            600: '#7c3aed',
            700: '#6d28d9',
            800: '#5b21b6',
            900: '#4c1d95',
          }
        },
        keyframes: {
          slideUp: {
            '0%': { opacity: '0', transform: 'translateY(20px)' },
            '100%': { opacity: '1', transform: 'translateY(0)' },
          },
          floatImg: {
            '0%, 100%': { transform: 'translateY(0)' },
            '50%': { transform: 'translateY(-20px)' },
          },
          shimmer: {
            '100%': { transform: 'translateX(100%)' },
          }
        },
        animation: {
          'slide-1': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both',
          'slide-2': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s both',
          'slide-3': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both',
          'slide-4': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.4s both',
          'slide-5': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.5s both',
          'float': 'floatImg 8s ease-in-out infinite',
          'slide-left': {
            '0%': { transform: 'translateX(0)' },
            '100%': { transform: 'translateX(-100%)' },
          },
          'slide-right': {
            '0%': { transform: 'translateX(-100%)' },
            '100%': { transform: 'translateX(0)' },
          }
        }
      }
    }
  }
</script>
<style>
/* Remove number spinners */
input[type=number]::-webkit-inner-spin-button, 
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }

.glass-panel {
    background: rgba(255, 255, 255, 0.8) !important;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08);
}

.input-container {
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.input-container:focus-within {
    transform: translateY(-2px);
}

.floating-label-input:focus ~ label,
.floating-label-input:not(:placeholder-shown) ~ label {
    transform: translateY(-40px);
    opacity: 0;
    pointer-events: none;
}

.floating-label {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    pointer-events: none;
    color: #94a3b8;
}

.modal-backdrop {
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

.animate-shimmer {
    position: relative;
    overflow: hidden;
}

.animate-shimmer::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    animation: shimmer 2s infinite;
}
</style>
</head>
<body class="overflow-hidden antialiased text-gray-800 bg-[#f8f9fc]">
<?php include __DIR__ . '/includes/loading_screen.php'; ?>

<div class="flex flex-col lg:flex-row h-screen w-full relative overflow-hidden">

    <!-- ══════════ LEFT — Immersive Background ══════════ -->
    <div class="hidden lg:flex absolute inset-y-0 left-0 w-[60%] z-0 bg-gradient-to-br from-blue-50 via-sky-100 to-white overflow-hidden">
        <!-- Animated Background Effects -->
        <div class="absolute inset-0 opacity-80">
            <div class="absolute top-[-15%] left-[-15%] w-[800px] h-[800px] bg-sky-300/60 rounded-full blur-[160px] animate-pulse"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[900px] h-[900px] bg-blue-200/50 rounded-full blur-[180px]"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-white/40 rounded-full blur-[100px]"></div>
        </div>
        
        <!-- Subtle noise overlay (inline SVG data URI) -->
        <div class="absolute inset-0 opacity-[0.04]" style="background-image: url(&quot;data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E&quot;); background-repeat: repeat; background-size: 256px 256px;"></div>

        <!-- The Illustration -->
        <div class="absolute inset-0 flex items-center justify-center z-10 transition-all duration-1000">
            <img src="assets/img/Gemini_Generated_Image_wkru09wkru09wkru.png" class="w-full h-full object-cover object-center scale-110" alt="VIP Ice Plant Illustration">
        </div>

        <!-- Brand Text Overlay (Temporarily Removed)
        <div class="absolute top-[40%] right-12 z-20 animate-slide-1 text-right -translate-y-1/2">
            ...
        </div>
        -->
    </div>

    <!-- ══════════ RIGHT — Glass Form Panel ══════════ -->
    <div class="w-full lg:w-[45%] h-full bg-slate-50 lg:bg-transparent flex items-center justify-center p-6 sm:p-10 lg:px-12 lg:py-8 relative z-10 lg:ml-auto">
        
        <!-- ══════════ THE FORM SLIDER ══════════ -->
        <div class="w-full max-w-[520px] relative z-10 overflow-hidden">
            <div id="formTrack" class="flex w-[200%] transition-transform duration-700 ease-[cubic-bezier(0.16,1,0.3,1)]">
                
                <!-- LOGIN PANEL -->
                <div id="loginPanel" class="w-1/2 flex-shrink-0 animate-slide-1 transition-opacity duration-300">
                    <!-- Headers -->
                    <div class="mb-6 text-center lg:text-left">
                        <div class="flex items-center justify-center lg:justify-start gap-3 mb-6">
                            <div class="w-12 h-12 bg-brand-600 rounded-2xl flex items-center justify-center shadow-lg shadow-brand-500/40">
                                <i data-lucide="shield-check" class="text-white w-6 h-6"></i>
                            </div>
                        </div>
                        <h2 class="text-3xl font-black text-slate-900 tracking-tight mb-2">Login to your portal</h2>
                        <p class="text-slate-500 font-medium">Enter your credentials to access the VIP system.</p>
                    </div>

                    <!-- Error bar -->
                    <div id="errBar" class="hidden animate-slide-2 bg-rose-50 text-rose-600 px-5 py-4 rounded-2xl text-sm font-semibold border border-rose-100 items-start gap-3 mb-8 shadow-sm">
                        <i data-lucide="alert-circle" class="w-5 h-5 shrink-0 text-rose-500"></i>
                        <span id="errText" class="pt-0.5"></span>
                    </div>

                    <input type="hidden" id="csrfToken" value="<?php echo vip_h($csrfToken); ?>">

                    <!-- Form -->
                    <div class="space-y-6">
                        <div class="input-container animate-slide-2">
                            <div class="relative group">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-brand-600 transition-colors z-20">
                                    <i data-lucide="user" class="w-5 h-5"></i>
                                </div>
                                <input type="text" id="usernameInput" placeholder=" " class="floating-label-input w-full bg-white/50 border border-slate-200 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 pl-12 pr-4 py-3.5 rounded-2xl text-base font-semibold text-slate-900 outline-none transition-all placeholder:opacity-0" autocomplete="username">
                                <label class="floating-label left-12 text-slate-400 font-medium">Username or email</label>
                            </div>
                        </div>

                        <div class="input-container animate-slide-3 mt-5">
                            <div class="relative group">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-brand-600 transition-colors z-20">
                                    <i data-lucide="lock" class="w-5 h-5"></i>
                                </div>
                                <input type="password" id="passwordInput" placeholder=" " class="floating-label-input w-full bg-white/50 border border-slate-200 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 pl-12 pr-12 py-3.5 rounded-2xl text-base font-semibold text-slate-900 outline-none transition-all placeholder:opacity-0" autocomplete="current-password">
                                <label class="floating-label left-12 text-slate-400 font-medium">Password</label>
                                <button type="button" id="pwEye" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-brand-600 transition-colors z-20">
                                    <i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Captcha -->
                        <div class="animate-slide-4 bg-slate-100/50 hover:bg-slate-100 transition-colors rounded-3xl border border-slate-200 p-5 flex items-center justify-between gap-4 mt-5">
                            <div class="flex flex-col">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Human Check</span>
                                <div class="text-xl font-extrabold text-slate-800 tracking-tight" id="captchaEq">
                                    <?php echo vip_h($captchaN1); ?> <span class="text-brand-500 mx-1">+</span> <?php echo vip_h($captchaN2); ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xl font-black text-slate-300">=</span>
                                <input type="number" id="captchaInput" placeholder="?" class="w-16 h-12 bg-white border border-slate-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 text-center text-xl font-bold text-brand-700 outline-none transition-all" autocomplete="off">
                            </div>
                        </div>

                        <div class="animate-slide-4 flex justify-end items-center mt-3">
                            <button type="button" id="forgotLink" class="text-sm font-bold text-brand-600 hover:text-brand-800 transition-colors">Forgot Password?</button>
                        </div>

                        <div class="animate-slide-5 mt-5 relative group">
                            <button type="button" id="loginBtn" class="w-full bg-brand-600 hover:bg-brand-700 text-white rounded-2xl py-4.5 px-6 font-bold text-[15px] flex items-center justify-center gap-3 transition-all active:scale-[0.98] shadow-xl shadow-brand-500/25 animate-shimmer h-[58px]">
                                <span id="btnText">Continue to Workspace</span>
                                <span id="btnIcon"><i data-lucide="arrow-right" class="w-5 h-5"></i></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- FORGOT PASSWORD PANEL -->
                <div id="forgotPanel" class="w-1/2 flex-shrink-0 px-1 opacity-0 transition-opacity duration-300">
                    <div class="mb-8">
                        <button type="button" id="closeForgot" class="flex items-center gap-2 text-slate-500 hover:text-brand-600 font-bold text-sm transition-colors mb-6 group">
                            <i data-lucide="chevron-left" class="w-5 h-5 group-hover:-translate-x-1 transition-transform"></i>
                            Back to Login
                        </button>
                        <div class="text-[11px] font-black tracking-widest text-brand-600 uppercase mb-2">Account Recovery</div>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tight">Reset Password</h3>
                    </div>

                    <!-- Step 1 -->
                    <div id="fStep1" class="space-y-6">
                        <div class="space-y-4">
                            <div class="relative group">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 ml-1">Username</label>
                                <input type="text" id="fUser" class="w-full bg-white/50 border border-slate-200 rounded-2xl px-5 py-4 text-base font-semibold focus:border-brand-500 focus:bg-white focus:ring-4 focus:ring-brand-500/10 outline-none transition-all" placeholder="Enter your username">
                            </div>
                            <div class="relative group">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 ml-1">Full Name</label>
                                <input type="text" id="fName" class="w-full bg-white/50 border border-slate-200 rounded-2xl px-5 py-4 text-base font-semibold focus:border-brand-500 focus:bg-white focus:ring-4 focus:ring-brand-500/10 outline-none transition-all" placeholder="Your account's registered name">
                            </div>
                            <div class="relative group">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 ml-1">Registered Email</label>
                                <input type="email" id="fEmail" class="w-full bg-white/50 border border-slate-200 rounded-2xl px-5 py-4 text-base font-semibold focus:border-brand-500 focus:bg-white focus:ring-4 focus:ring-brand-500/10 outline-none transition-all" placeholder="name@example.com">
                            </div>
                        </div>
                        
                        <button type="button" id="sendCodeBtn" class="w-full bg-brand-600 hover:bg-brand-700 text-white rounded-2xl py-4.5 px-6 font-bold text-[15px] flex items-center justify-center gap-3 transition-all active:scale-[0.98] shadow-xl shadow-brand-500/25 animate-shimmer h-[58px]">
                            <span id="sendBtnText">Send Verification Code</span>
                            <span id="sendBtnIcon"><i data-lucide="send" class="w-5 h-5"></i></span>
                        </button>
                        <div id="fMsg1" class="text-sm font-semibold text-center min-h-[20px]"></div>
                    </div>

                    <!-- Step 2 -->
                    <div id="fStep2" class="space-y-6 hidden">
                        <div class="bg-brand-50 border border-brand-100 rounded-2xl p-5 mb-2">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs font-bold text-brand-700 uppercase tracking-wide">Enter 6-Digit Code</span>
                                <span id="expiryTxt" class="text-sm font-black text-brand-600 tabular-nums">5:00</span>
                            </div>
                            <div class="flex gap-2 justify-between">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                                <input type="text" maxlength="1" class="code-char w-10 h-14 text-center text-xl font-black text-brand-600 bg-white border border-brand-200 rounded-xl focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 ml-1">New Password</label>
                                <input type="password" id="fNewPw" class="w-full bg-white/50 border border-slate-200 rounded-2xl px-5 py-4 text-base font-semibold focus:border-brand-500 outline-none transition-all" placeholder="At least 10 characters">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 ml-1">Confirm New Password</label>
                                <input type="password" id="fConfPw" class="w-full bg-white/50 border border-slate-200 rounded-2xl px-5 py-4 text-base font-semibold focus:border-brand-500 outline-none transition-all" placeholder="Must match exactly">
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" id="backBtn" class="flex-1 py-4 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl font-bold text-sm transition-all active:scale-[0.98]">Cancel</button>
                            <button type="button" id="resetBtn" class="flex-[2] bg-brand-600 hover:bg-brand-700 text-white rounded-2xl py-4 font-bold text-sm flex items-center justify-center gap-2 transition-all shadow-xl shadow-brand-500/25 active:scale-[0.98] animate-shimmer">
                                <span id="resetBtnText">Verify & Reset</span>
                                <span id="resetBtnIcon"><i data-lucide="shield-check" class="w-4 h-4"></i></span>
                            </button>
                        </div>
                        <div id="fMsg2" class="text-sm font-semibold text-center min-h-[20px]"></div>
                    </div>

                    <!-- Step 3: Success State -->
                    <div id="fStepSuccess" class="hidden text-center py-4">
                        <div class="w-20 h-20 bg-gradient-to-br from-green-400 to-green-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-xl shadow-green-500/20 rotate-3">
                            <i data-lucide="check" class="text-white w-10 h-10"></i>
                        </div>
                        <h3 class="text-3xl font-black text-slate-900 mb-4">You're All Set!</h3>
                        <p class="text-slate-500 font-medium mb-10 leading-relaxed">Your password has been successfully reset. You can now use your new credentials to log in.</p>
                        <button type="button" id="okBtn" class="w-full bg-slate-900 hover:bg-black text-white rounded-2xl py-4.5 font-bold text-[15px] transition-all shadow-lg active:scale-[0.98]">
                            Return to Login
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Footer decoration -->
            <div class="mt-8 text-center">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">Authorized Access Only</p>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', () => {

    lucide.createIcons();

    /* Password Eye */
    const pwInput = document.getElementById('passwordInput');
    const pwEye   = document.getElementById('pwEye');
    const eyeIcon = document.getElementById('eyeIcon');

    pwEye.addEventListener('click', () => {
        const isPw = pwInput.type === 'password';
        pwInput.type = isPw ? 'text' : 'password';
        
        // Update Lucide icon
        eyeIcon.setAttribute('data-lucide', isPw ? 'eye-off' : 'eye');
        lucide.createIcons();
        
        pwEye.classList.toggle('text-brand-600');
    });

    /* Login Action */
    const loginBtn = document.getElementById('loginBtn');
    const btnText  = document.getElementById('btnText');
    const btnIcon  = document.getElementById('btnIcon');
    const errBar   = document.getElementById('errBar');
    const errText  = document.getElementById('errText');

    function showError(msg) {
        errText.textContent = msg;
        errBar.classList.remove('hidden');
        errBar.classList.add('flex');
    }
    function hideError() {
        errBar.classList.add('hidden');
        errBar.classList.remove('flex');
    }

    loginBtn.addEventListener('click', async () => {
        const u = document.getElementById('usernameInput').value.trim();
        const p = pwInput.value;
        const c = parseInt(document.getElementById('captchaInput').value, 10);
        const csrf = document.getElementById('csrfToken').value;

        hideError();
        if (!u || !p) { showError('Username and password are required.'); return; }
        if (isNaN(c)) { showError('Please solve the security check.'); return; }

        loginBtn.disabled = true;
        btnText.textContent = 'Authenticating...';
        btnIcon.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i>`;
        lucide.createIcons();

        let data;
        try {
            const res = await fetch('api/auth/session_login.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ username:u, password:p, captcha:c, csrf })
            });
            data = await res.json().catch(() => ({}));
            
            if (data.success && data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            
            showError(data.message || 'Login failed. Please try again.');

            if (data.captcha) {
                document.getElementById('captchaEq').innerHTML = `${data.captcha.n1} <span class="text-brand-500 mx-1">+</span> ${data.captcha.n2}`;
                document.getElementById('captchaInput').value = '';
            }

            if (data.retry_after) {
                let remaining = data.retry_after;
                loginBtn.disabled = true;
                btnText.textContent = `Retry in ${remaining}s`;
                const timer = setInterval(() => {
                    remaining--;
                    if (remaining <= 0) {
                        clearInterval(timer);
                        location.reload();
                    } else {
                        btnText.textContent = `Retry in ${remaining}s`;
                        showError(`Too many attempts. Try again in ${remaining} seconds.`);
                    }
                }, 1000);
                return;
            }
            
        } catch(e) {
            showError('Network error. Check your connection.');
        } finally {
            if (!data || !data.retry_after) {
                loginBtn.disabled = false;
                btnText.textContent = 'Continue to Workspace';
                btnIcon.innerHTML = '<i data-lucide="arrow-right" class="w-5 h-5"></i>';
                lucide.createIcons();
            }
        }
    });

    document.getElementById('captchaInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') loginBtn.click();
    });

    /* Sliding logic */
    const formTrack   = document.getElementById('formTrack');
    const loginPanel  = document.getElementById('loginPanel');
    const forgotPanel = document.getElementById('forgotPanel');
    const forgotLink  = document.getElementById('forgotLink');
    const closeForgot = document.getElementById('closeForgot');
    const backBtn     = document.getElementById('backBtn');
    const okBtn       = document.getElementById('okBtn');

    function showForgotView() {
        formTrack.style.transform = 'translateX(-50%)';
        loginPanel.classList.add('opacity-0');
        forgotPanel.classList.remove('opacity-0');
        
        // Reset forgot steps
        document.getElementById('fStep1').classList.remove('hidden');
        document.getElementById('fStep2').classList.add('hidden');
        document.getElementById('fStepSuccess').classList.add('hidden');
        document.getElementById('fMsg1').textContent = '';
    }

    function showLoginView() {
        formTrack.style.transform = 'translateX(0)';
        loginPanel.classList.remove('opacity-0');
        forgotPanel.classList.add('opacity-0');
    }

    forgotLink.addEventListener('click', showForgotView);
    closeForgot.addEventListener('click', showLoginView);
    backBtn.addEventListener('click', showLoginView);
    okBtn.addEventListener('click', showLoginView);

    /* Forgot Step 1 */
    const fMsg1 = document.getElementById('fMsg1');
    document.getElementById('sendCodeBtn').addEventListener('click', async () => {
        const u = document.getElementById('fUser').value.trim();
        const n = document.getElementById('fName').value.trim();
        const em = document.getElementById('fEmail').value.trim();
        if (!u || !n || !em) {
            fMsg1.textContent = 'All fields are required.'; 
            fMsg1.className = 'text-sm font-semibold text-center text-rose-500'; 
            return;
        }
        
        const btn = document.getElementById('sendCodeBtn');
        const btnText = document.getElementById('sendBtnText');
        const btnIcon = document.getElementById('sendBtnIcon');
        
        btn.disabled = true;
        btnText.textContent = 'Sending code...'; 
        btnIcon.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i>';
        lucide.createIcons();
        
        try {
            const res = await fetch('api/forgot_password.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ action:'send_code', csrf:document.getElementById('csrfToken').value, username:u, full_name:n, email:em })
            });
            const data = await res.json();
            fMsg1.textContent = data.message;
            fMsg1.className = `text-sm font-semibold text-center ${data.success ? 'text-emerald-500' : 'text-rose-500'}`;
            
            if (data.success) {
                setTimeout(() => {
                    document.getElementById('fStep1').classList.add('hidden');
                    document.getElementById('fStep2').classList.remove('hidden');
                    startExpiry(300);
                    document.querySelectorAll('.code-char')[0].focus();
                }, 800);
            }
        } catch(e) {
            fMsg1.textContent = 'Request failed. Try again.'; 
            fMsg1.className = 'text-sm font-semibold text-center text-rose-500';
        } finally {
            btn.disabled = false;
            btnText.textContent = 'Send Verification Code';
            btnIcon.innerHTML = '<i data-lucide="send" class="w-5 h-5"></i>';
            lucide.createIcons();
        }
    });

    /* Code Inputs */
    const codes = document.querySelectorAll('.code-char');
    codes.forEach((c, i) => {
        c.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0,1);
            if(this.value && i < codes.length-1) codes[i+1].focus();
        });
        c.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && i > 0) codes[i-1].focus();
        });
    });

    /* Reset Password Result Handler */
    const fMsg2 = document.getElementById('fMsg2');
    document.getElementById('resetBtn').addEventListener('click', async () => {
        const code = Array.from(codes).map(c=>c.value).join('');
        if (!/^\d{6}$/.test(code)) { 
            fMsg2.textContent='Enter complete 6-digit code.'; 
            fMsg2.className='text-sm font-semibold text-center text-rose-500'; 
            return; 
        }
        const pw = document.getElementById('fNewPw').value;
        const cpw = document.getElementById('fConfPw').value;
        if(pw.length < 10) { 
            fMsg2.textContent='Password too short.'; 
            fMsg2.className='text-sm font-semibold text-center text-rose-500'; 
            return; 
        }
        if(pw !== cpw) { 
            fMsg2.textContent='Passwords do not match.'; 
            fMsg2.className='text-sm font-semibold text-center text-rose-500'; 
            return; 
        }

        const btn = document.getElementById('resetBtn');
        const rBtnText = document.getElementById('resetBtnText');
        const rBtnIcon = document.getElementById('resetBtnIcon');
        
        btn.disabled = true;
        rBtnText.textContent = 'Processing...'; 
        rBtnIcon.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        lucide.createIcons();

        try {
            const u = document.getElementById('fUser').value.trim();
            const n = document.getElementById('fName').value.trim();
            const em = document.getElementById('fEmail').value.trim();
            
            const res = await fetch('api/forgot_password.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'reset_password',csrf:document.getElementById('csrfToken').value,username:u,full_name:n,email:em,code,new_password:pw,confirm_password:cpw})
            });
            const data = await res.json();
            
            fMsg2.textContent = data.message;
            fMsg2.className = `text-sm font-semibold text-center ${data.success ? 'text-emerald-500' : 'text-rose-500'}`;
            
            if (data.success) {
                setTimeout(() => {
                    document.getElementById('fStep2').classList.add('hidden');
                    document.getElementById('fStepSuccess').classList.remove('hidden');
                    lucide.createIcons();
                }, 800);
            }
        } catch(e) {
            fMsg2.textContent = 'Reset failed.'; 
            fMsg2.className = 'text-sm font-semibold text-center text-rose-500';
        } finally {
            btn.disabled = false;
            rBtnText.textContent = 'Verify & Reset';
            rBtnIcon.innerHTML = '<i data-lucide="shield-check" class="w-4 h-4"></i>';
            lucide.createIcons();
        }
    });

    let timer;
    function startExpiry(secs) {
        if(timer) clearInterval(timer);
        let r = secs;
        const el = document.getElementById('expiryTxt');
        timer = setInterval(() => {
            const m = Math.floor(r/60);
            const s = r % 60;
            el.textContent = m + ':' + String(s).padStart(2,'0');
            el.className = r <= 60 ? 'text-sm font-black text-rose-500 tabular-nums' : 'text-sm font-black text-brand-600 tabular-nums';
            if (r <= 0) {
                clearInterval(timer);
                el.textContent = 'Expired';
            }
            r--;
        }, 1000);
    }
});
</script>

</body>
</html>
