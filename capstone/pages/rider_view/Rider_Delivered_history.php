    <?php if ($can_rider_history): ?>
        <div id="tab-history" class="tab-content staggered-group" style="display:<?= $activeTab === 'history' ? 'block' : 'none' ?>">
        <div class="bg-white rounded-[24px] p-6 shadow-xl border border-slate-100 relative overflow-hidden animate-fade-in-up">
            <!-- Background gradient effect for history -->
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="text-center pb-6 border-b border-slate-100 mb-5">
                <div class="text-5xl font-black text-emerald-500 tracking-tight" id="historyTotalCount">0</div>
                <div class="text-xs font-bold text-emerald-500 tracking-widest uppercase mt-2">Delivered Deliveries (Your History)</div>
                <div class="flex justify-center gap-3 mt-4">
                    <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-violet-50 border border-violet-100 text-sm font-bold text-violet-700 shadow-sm transition-transform hover:scale-105">
                        <i data-lucide="wallet" class="w-4 h-4"></i> <span id="historyCodTotal">₱0</span>
                    </span>
                    <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-slate-50 border border-slate-200 text-sm font-bold text-slate-600 shadow-sm transition-transform hover:scale-105">
                        <i data-lucide="list-checks" class="w-4 h-4"></i> Last 200
                    </span>
                </div>
            </div>
            
            <div class="text-xs font-black text-slate-800 uppercase tracking-widest mb-4">Recent Delivered History</div>
            
            <div id="historyList" class="space-y-3 staggered-group" style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem;">
                <style>
                    #historyList::-webkit-scrollbar { width: 6px; }
                    #historyList::-webkit-scrollbar-track { background: transparent; }
                    #historyList::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
                    #historyList::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                </style>
                <!-- JS injected items will go here -->
            </div>
            
            <div id="historyPagination" class="flex justify-center items-center gap-4 pt-5 mt-4 border-t border-slate-100">
                <button id="histPrev" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-violet-600 transition-colors shadow-sm" onclick="changeHistoryPage(-1)"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                <span id="histPageInfo" class="text-sm font-semibold text-slate-500">Page 1 of 1</span>
                <button id="histNext" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-violet-600 transition-colors shadow-sm" onclick="changeHistoryPage(1)"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
