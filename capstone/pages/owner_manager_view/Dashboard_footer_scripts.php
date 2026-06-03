    <script>
    (function() {
        let systemBrain = null;

        async function fetchSystemBrain() {
            try {
                const res = await fetch('api/chatbot_brain.php');
                const json = await res.json();
                if (json.success) {
                    systemBrain = json.snapshot;
                    checkProactiveAlerts(systemBrain);
                }
            } catch (e) { console.error('Brain Error', e); }
        }

        let alerted = false;
        function checkProactiveAlerts(s) {
            if (alerted) return;
            alerted = true;
            let alerts = [];
            const overdueTotal = s.customers?.overdue_total || 0;
            const lowStockCount = (s.inventory?.low_stock_list || []).length;
            const deliveriesOverdue = s.deliveries?.overdue || 0;
            if (overdueTotal > 0) {
                alerts.push(`⚠️ <strong>₱${Number(overdueTotal).toLocaleString('en-PH',{minimumFractionDigits:2})}</strong> in customer AR is currently overdue across <strong>${s.customers?.overdue_count||0}</strong> customers.`);
            }
            if (lowStockCount > 0) {
                alerts.push(`📦 <strong>${lowStockCount}</strong> products are running critically low on stock.`);
            }
            if (deliveriesOverdue > 0) {
                alerts.push(`🚚 <strong>${deliveriesOverdue}</strong> deliveries are overdue and not yet completed.`);
            }
            if (alerts.length > 0) {
                setTimeout(() => {
                    appendMsg(`👋 Good day! Here's your quick system heads-up:<br><br>` + alerts.join('<br><br>') + `<br><br>Type <em>"full report"</em> or ask me anything about your system!`);
                }, 1200);
            }
        }

        function appendMsg(html, isUser = false) {
            const msgs = document.getElementById('aiMessages');
            const div = document.createElement('div');
            div.className = 'ai-msg flex gap-2.5 items-end ' + (isUser ? 'flex-row-reverse' : '');
            if (isUser) {
                div.innerHTML = `<div class="bg-violet-600 text-white rounded-2xl rounded-br-sm px-4 py-3 max-w-[80%] text-sm font-medium ml-auto">${html}</div>`;
            } else {
                div.innerHTML = `
                    <div class="w-7 h-7 bg-violet-100 rounded-full flex items-center justify-center shrink-0">
                        <i data-lucide="bot" class="w-4 h-4 text-violet-600"></i>
                    </div>
                    <div class="bg-slate-100 rounded-2xl rounded-bl-sm px-4 py-3 max-w-[80%] text-slate-800 text-sm font-medium leading-relaxed">${html}</div>`;
            }
            msgs.appendChild(div);
            lucide.createIcons({root: div});
            msgs.scrollTop = msgs.scrollHeight;
            return div;
        }

        function typingIndicator() {
            const msgs = document.getElementById('aiMessages');
            const div = document.createElement('div');
            div.className = 'ai-msg flex gap-2.5 items-end';
            div.id = 'aiTypingIndicator';
            div.innerHTML = `
                <div class="w-7 h-7 bg-violet-100 rounded-full flex items-center justify-center shrink-0">
                    <i data-lucide="bot" class="w-4 h-4 text-violet-600"></i>
                </div>
                <div class="bg-slate-100 rounded-2xl rounded-bl-sm px-4 py-3 flex gap-1.5">
                    <span class="ai-dot"></span><span class="ai-dot"></span><span class="ai-dot"></span>
                </div>`;
            msgs.appendChild(div);
            lucide.createIcons({root: div});
            msgs.scrollTop = msgs.scrollHeight;
        }

        async function sendMessage(q) {
            if (!q.trim()) return;
            const input = document.getElementById('aiInput');
            input.value = '';
            appendMsg(q.replace(/</g,'&lt;'), true);
            typingIndicator();

            try {
                const res = await fetch('api/chatbot_ask.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question: q, snapshot: systemBrain })
                });
                const data = await res.json();
                document.getElementById('aiTypingIndicator')?.remove();
                
                if (data.success) {
                    appendMsg(data.reply);
                } else {
                    appendMsg(`⚠️ System err: ${data.reply}`);
                }
            } catch (err) {
                document.getElementById('aiTypingIndicator')?.remove();
                appendMsg(`⚠️ Connection lost. Could not reach the AI core.`);
                console.error(err);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const btn    = document.getElementById('aiToggleBtn');
            const panel  = document.getElementById('aiChatPanel');
            const close  = document.getElementById('aiPanelClose');
            const send   = document.getElementById('aiSend');
            const input  = document.getElementById('aiInput');
            const badge  = document.getElementById('aiUnreadBadge');
            const chips  = document.querySelectorAll('.ai-chip');

            // Show badge after 2s to entice user
            setTimeout(() => badge?.classList.remove('hidden'), 2000);

            btn?.addEventListener('click', () => {
                const isOpen = !panel.classList.contains('hidden');
                panel.classList.toggle('hidden');
                badge?.classList.add('hidden');
                if (!isOpen) {
                    input?.focus();
                    fetchSystemBrain(); // Load data on open
                }
            });
            close?.addEventListener('click', () => panel.classList.add('hidden'));

            send?.addEventListener('click', () => sendMessage(input.value));
            input?.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(input.value); });

            chips.forEach(chip => {
                chip.addEventListener('click', () => sendMessage(chip.textContent.replace(/^[^\s]+ /, '').trim()));
            });

            lucide.createIcons({ root: document.getElementById('aiAssistantWidget') });
        });
    })();
    </script>
    <script>
    // ================================================================
    // ANTIGRAVITY INTERACTIVITY ENGINE
    // ================================================================
    (function() {
        'use strict';

        // ── 1. Cursor aura ──────────────────────────────────────────
        const aura = document.getElementById('cursorAura');
        if (aura) {
            let ax = window.innerWidth/2, ay = window.innerHeight/2;
            let cx = ax, cy = ay;
            document.addEventListener('mousemove', e => { ax = e.clientX; ay = e.clientY; });
            (function animateAura() {
                cx += (ax - cx) * 0.08;
                cy += (ay - cy) * 0.08;
                aura.style.left = cx + 'px';
                aura.style.top  = cy + 'px';
                requestAnimationFrame(animateAura);
            })();
        }

        // ── 2. Magnetic 3D tilt on stat cards ──────────────────────
        document.querySelectorAll('.tilt-card').forEach(card => {
            const shine = card.querySelector('.tilt-shine');
            card.addEventListener('mousemove', e => {
                const r   = card.getBoundingClientRect();
                const x   = e.clientX - r.left;
                const y   = e.clientY - r.top;
                const cx  = r.width  / 2;
                const cy  = r.height / 2;
                const rotX = ((y - cy) / cy) * -10;
                const rotY = ((x - cx) / cx) *  10;
                card.style.transform = `perspective(600px) rotateX(${rotX}deg) rotateY(${rotY}deg) scale(1.03)`;
                card.style.boxShadow = `${-rotY*1.2}px ${rotX*1.2}px 30px rgba(113,50,245,0.18)`;
                if (shine) {
                    shine.style.background = `radial-gradient(circle at ${x}px ${y}px, rgba(255,255,255,0.28) 0%, transparent 65%)`;
                }
            });
            card.addEventListener('mouseleave', () => {
                card.style.transform = '';
                card.style.boxShadow = '';
                if (shine) shine.style.background = '';
            });
        });

        // ── 3. Animated number counters ─────────────────────────────
        function animateCounter(el) {
            const targetRaw = parseFloat(el.dataset.value || '0');
            const decimals  = parseInt(el.dataset.decimals || '0');
            const prefix    = el.dataset.prefix || '';
            const duration  = 1400;
            const start     = performance.now();
            function step(now) {
                const elapsed = now - start;
                const progress = Math.min(elapsed / duration, 1);
                // Ease out expo
                const eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
                const current = targetRaw * eased;
                el.textContent = prefix + current.toLocaleString('en-PH', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                });
                if (progress < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        // ── 4. Progress bar fills + counter trigger via IntersectionObserver ──
        const statObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const card = entry.target;
                // Counter
                card.querySelectorAll('.stat-counter').forEach(animateCounter);
                // Bar
                const fill = card.querySelector('.stat-bar-fill');
                const pct  = parseFloat(card.dataset.barPct || '0');
                if (fill) setTimeout(() => { fill.style.width = Math.min(100, pct) + '%'; }, 200);
                statObserver.unobserve(card);
            });
        }, { threshold: 0.3 });

        document.querySelectorAll('.tilt-card').forEach(c => statObserver.observe(c));

        // ── 5. Ripple effect on table rows ──────────────────────────
        document.querySelectorAll('.ripple-row').forEach(row => {
            row.addEventListener('click', function(e) {
                const r    = this.getBoundingClientRect();
                const size = Math.max(r.width, r.height) * 1.5;
                const x    = e.clientX - r.left - size / 2;
                const y    = e.clientY - r.top  - size / 2;
                const wave = document.createElement('span');
                wave.className = 'ripple-wave';
                wave.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px`;
                this.appendChild(wave);
                setTimeout(() => wave.remove(), 600);
            });
        });

        // ── 6. Chart expand modal ───────────────────────────────────
        const chartInstances = {};
        // Store references when charts render (patched into global scope)
        window._chartRegistry = chartInstances;

        window.expandChart = function(containerId, title) {
            const modal     = document.getElementById('chartModal');
            const modalBody = document.getElementById('chartModalBody');
            const modalTitle = document.getElementById('chartModalTitle');
            if (!modal || !modalBody) return;

            modalTitle.textContent = title || 'Chart';
            modalBody.innerHTML    = `<div id="chartModalChart" style="width:100%;height:420px;"></div>`;
            modal.classList.add('open');
            document.body.classList.add('modal-active');

            // Clone the chart options from cached instance or read current SVG
            const src = document.getElementById(containerId);
            if (src) {
                const svgEl = src.querySelector('svg');
                if (svgEl) {
                    const clone = svgEl.cloneNode(true);
                    clone.style.width  = '100%';
                    clone.style.height = '420px';
                    document.getElementById('chartModalChart').appendChild(clone);
                }
            }
            lucide.createIcons({ root: modal });
        };

        window.closeChartModal = function() {
            const modal = document.getElementById('chartModal');
            modal?.classList.remove('open');
            document.body.classList.remove('modal-active');
        };

        // Close on backdrop click
        document.getElementById('chartModal')?.addEventListener('click', function(e) {
            if (e.target === this) window.closeChartModal();
        });

        // Close on Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') window.closeChartModal();
        });

        // ── 7. Chart PNG download ───────────────────────────────────
        window.downloadChart = function(containerId, filename) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const svg = container.querySelector('svg');
            if (!svg) { alert('Chart not loaded yet — please wait a moment.'); return; }

            const svgData   = new XMLSerializer().serializeToString(svg);
            const canvas    = document.createElement('canvas');
            const box       = svg.getBoundingClientRect();
            canvas.width    = box.width  * 2;
            canvas.height   = box.height * 2;
            const ctx       = canvas.getContext('2d');
            ctx.fillStyle   = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            const img       = new Image();
            const blob      = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
            const url       = URL.createObjectURL(blob);
            img.onload = () => {
                ctx.scale(2, 2);
                ctx.drawImage(img, 0, 0);
                URL.revokeObjectURL(url);
                const a    = document.createElement('a');
                a.download = (filename || 'chart') + '.png';
                a.href     = canvas.toDataURL('image/png');
                a.click();
            };
            img.src = url;
        };

        // ── 8. Slide-up re-observer for 3D cards (refreshes on scroll) ──
        const slideObserver = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const el = entry.target;
                // Determine delay from class
                let delay = 0;
                if (el.classList.contains('delay-100')) delay = 100;
                else if (el.classList.contains('delay-200')) delay = 200;
                else if (el.classList.contains('delay-300')) delay = 300;
                else if (el.classList.contains('delay-400')) delay = 400;
                else if (el.classList.contains('delay-500')) delay = 500;
                else if (el.classList.contains('delay-600')) delay = 600;
                else if (el.classList.contains('delay-700')) delay = 700;
                setTimeout(() => {
                    el.style.animation = 'none';
                    el.style.opacity   = '';
                    el.style.transform = '';
                    el.style.animation = `slide-up-3d 0.55s cubic-bezier(0.16,1,0.3,1) forwards`;
                }, delay);
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.animate-slide-up-3d').forEach(el => slideObserver.observe(el));

    })();
    </script>
</body>
</html>