// --- 自启动逻辑 (Self-Bootstrapping) ---
(function() {
    console.log("%c>>> AI Summary Plugin v5.0 Loaded <<<", "background: #222; color: #bada55; font-size: 14px");

    const AI_Core = {
        hasInitialized: false,

        start: function() {
            if (this.hasInitialized) return;
            this.bindShortcuts();
            this.ensureFab();
            setInterval(() => this.ensureFab(), 2000);
            this.hasInitialized = true;
        },

        bindShortcuts: function() {
            window.addEventListener("keydown", (e) => {
                if (e.shiftKey && (e.key === 'A' || e.key === 'a')) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                    e.preventDefault();
                    Plugins.AI_Summary.openBatchDialog();
                }
            }, true);
        },

        ensureFab: function() {
            if (document.getElementById("ai-fab-container")) return;
            if (!document.body) return;

            try {
                const container = document.createElement("div");
                container.id = "ai-fab-container";
                container.style.cssText = "position: fixed; bottom: 30px; right: 30px; z-index: 2147483647; display: flex; flex-direction: column-reverse; align-items: flex-end; gap: 12px; pointer-events: none;";

                const mainBtn = document.createElement("div"); 
                mainBtn.innerHTML = "🤖"; 
                mainBtn.title = "AI 助手 (Shift+A)";
                mainBtn.style.cssText = "width: 56px; height: 56px; background: #3b82f6; color: white; border-radius: 50%; font-size: 28px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.4); cursor: pointer; pointer-events: auto; user-select: none; transition: transform 0.2s;";
                
                mainBtn.onmouseover = () => mainBtn.style.transform = "scale(1.1)";
                mainBtn.onmouseout = () => mainBtn.style.transform = "scale(1)";
                mainBtn.onclick = (e) => {
                    e.stopPropagation();
                    Plugins.AI_Summary.toggleFabMenu();
                };

                const menuItems = document.createElement("div");
                menuItems.id = "ai-fab-items";
                menuItems.style.cssText = "display: none; flex-direction: column; gap: 10px; align-items: flex-end; margin-bottom: 10px; pointer-events: auto;";

                const createBtn = (text, callback) => {
                    const b = document.createElement("div");
                    b.innerHTML = text;
                    b.style.cssText = "background: white; color: #333; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.2); cursor: pointer; white-space: nowrap;";
                    b.onclick = (e) => { e.stopPropagation(); callback(b); };
                    return b;
                };

                const batchBtn = createBtn("📊 批量汇总 (Shift+A)", () => {
                    Plugins.AI_Summary.openBatchDialog();
                    Plugins.AI_Summary.toggleFabMenu();
                });

                const toggleBtn = createBtn("👁️ 视图开关", () => {
                    Plugins.AI_Summary.toggleAiView();
                });
                toggleBtn.id = "ai-fab-toggle-btn";

                menuItems.appendChild(batchBtn);
                menuItems.appendChild(toggleBtn);
                container.appendChild(mainBtn);
                container.appendChild(menuItems);
                document.body.appendChild(container);

            } catch (e) {
                console.error("AI Summary: FAB Injection Failed", e);
            }
        }
    };

    if (document.readyState === "complete" || document.readyState === "interactive") {
        AI_Core.start();
    } else {
        window.addEventListener("DOMContentLoaded", () => AI_Core.start());
        window.addEventListener("load", () => AI_Core.start());
    }
})();

// --- 标准插件定义 ---
Plugins.AI_Summary = {
    aiViewEnabled: true,
    fabMenuOpen: false,
    dialog: null,
    currentArticles: [], 

    init: function() {},

    toggleFabMenu: function() {
        const menu = document.getElementById("ai-fab-items");
        if (menu) {
            this.fabMenuOpen = !this.fabMenuOpen;
            menu.style.display = this.fabMenuOpen ? "flex" : "none";
        }
    },

    toggleAiView: function() {
        this.aiViewEnabled = !this.aiViewEnabled;
        const body = document.body;
        const btn = document.getElementById("ai-fab-toggle-btn");
        if (this.aiViewEnabled) {
            body.classList.remove("ai-view-disabled");
            if (btn) btn.innerHTML = "👁️ 视图开关 (ON)";
            if (typeof Notify != 'undefined') Notify.msg("AI View ON", true);
        } else {
            body.classList.add("ai-view-disabled");
            if (btn) btn.innerHTML = "👁️ 视图开关 (OFF)";
            if (typeof Notify != 'undefined') Notify.msg("AI View OFF", true);
        }
    },

    getCsrfToken: function() {
        let token = "";
        try {
            if (typeof __csrf_token !== "undefined") token = __csrf_token;
            else if (typeof App !== "undefined" && App.getInitParam) token = App.getInitParam("csrf_token");
        } catch (e) {}
        return token;
    },

    // --- 批量汇总: 布局重构 (v5.0) ---
    openBatchDialog: function() {
        if (this.dialog) this.dialog.destroyRecursive();

        const content = `
            <style>
                .ai-batch-wrapper * { box-sizing: border-box; }
                .ai-batch-wrapper {
                    display: flex;
                    height: 100%; 
                    width: 100%;
                    border-radius: 8px;
                    background: #fff;
                    box-shadow: 0 5px 30px rgba(0,0,0,0.2);
                    overflow: hidden;
                    font-family: system-ui, -apple-system, sans-serif;
                }
                
                /* 左侧列表区 - 调整为 40% */
                .ai-batch-left {
                    flex: 4;
                    min-width: 320px;
                    border-right: 1px solid #e5e7eb;
                    display: flex;
                    flex-direction: column;
                    background: #f9fafb;
                }
                
                /* 右侧内容区 - 调整为 60% */
                .ai-batch-right {
                    flex: 6;
                    display: flex;
                    flex-direction: column;
                    background: #fff;
                    min-width: 0;
                }
                
                .ai-header {
                    padding: 12px 16px;
                    border-bottom: 1px solid #e5e7eb;
                    background: #fff;
                    flex-shrink: 0;
                }
                .ai-header-gray { background: #f9fafb; }
                
                .ai-scroll-view {
                    flex: 1;
                    overflow-y: auto;
                    overflow-x: hidden;
                }
                
                .ai-footer {
                    padding: 8px 16px;
                    border-top: 1px solid #e5e7eb;
                    background: #f9fafb;
                    font-size: 12px;
                    color: #6b7280;
                    text-align: right;
                    flex-shrink: 0;
                }

                .ai-btn {
                    padding: 5px 10px;
                    border: 1px solid #d1d5db;
                    background: #fff;
                    border-radius: 4px;
                    font-size: 12px;
                    cursor: pointer;
                    color: #374151;
                    transition: all 0.2s;
                }
                .ai-btn:hover { background: #f3f4f6; border-color: #9ca3af; }
                
                .ai-btn-primary {
                    background: #2563eb;
                    color: #fff;
                    border: 1px solid #2563eb;
                    font-weight: 600;
                    padding: 6px 16px;
                    font-size: 13px;
                }
                .ai-btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
                
                .ai-btn-danger {
                    color: #dc2626;
                    border-color: #fecaca;
                    background: #fef2f2;
                }
                .ai-btn-danger:hover { background: #fee2e2; border-color: #fca5a5; }

                @media (max-width: 768px) {
                    .ai-batch-wrapper { flex-direction: column; height: 100%; width: 100%; }
                    .ai-batch-left { flex: 1; min-height: 40%; border-right: none; border-bottom: 1px solid #e5e7eb; }
                    .ai-batch-right { flex: 1; }
                }
            </style>

            <div class="ai-batch-wrapper">
                <div style="position: absolute; top: 10px; right: 10px; z-index: 100;">
                    <button onclick="Plugins.AI_Summary.closeDialog()" 
                        style="border: none; background: rgba(0,0,0,0.05); width: 28px; height: 28px; border-radius: 50%; cursor: pointer; color: #666; font-size: 18px; line-height: 1;">&times;</button>
                </div>

                <div class="ai-batch-left">
                    <div class="ai-header ai-header-gray">
                        <div style="font-weight: 600; color: #111; margin-bottom: 10px; font-size: 14px;">📑 筛选文章</div>
                        <input type="text" id="ai-batch-search" placeholder="🔍 搜索标题..." 
                            style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; margin-bottom: 8px; outline: none; font-size: 13px;"
                            onkeyup="Plugins.AI_Summary.renderArticleList()">
                        
                        <select id="ai-batch-filter-feed" onchange="Plugins.AI_Summary.renderArticleList()" 
                            style="width: 100%; padding: 6px; border: 1px solid #d1d5db; border-radius: 4px; margin-bottom: 10px; background: #fff; font-size: 13px; max-width: 100%;">
                            <option value="">📚 所有订阅源</option>
                        </select>

                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label style="cursor: pointer; display: flex; align-items: center; font-size: 13px; user-select: none;">
                                <input type="checkbox" id="ai-batch-filter-unread" onchange="Plugins.AI_Summary.renderArticleList()" style="margin-right: 4px;"> 
                                <span style="font-weight: 600; color: #d32f2f;">仅看未读</span>
                            </label>
                            <div style="display: flex; gap: 4px;">
                                <button class="ai-btn" onclick="Plugins.AI_Summary.selectAllFiltered(true)">全选</button>
                                <button class="ai-btn" onclick="Plugins.AI_Summary.selectAllFiltered(false)">清空</button>
                                <button class="ai-btn ai-btn-danger" id="ai-btn-mark-read" onclick="Plugins.AI_Summary.markSelectedRead()">已读</button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="ai-batch-list" class="ai-scroll-view">
                        <div style="padding: 30px; text-align: center; color: #9ca3af; font-size: 13px;">加载中...</div>
                    </div>
                    
                    <div id="ai-batch-count" class="ai-footer">Total: 0</div>
                </div>

                <div class="ai-batch-right">
                    <div class="ai-header" style="display: flex; justify-content: space-between; align-items: center; padding-right: 50px;">
                        <div style="font-weight: 600; color: #111; font-size: 15px;">🤖 AI 汇总报告</div>
                        <button class="ai-btn ai-btn-primary" onclick="Plugins.AI_Summary.executeBatchSummary()">
                            ✨ 开始汇总
                        </button>
                    </div>
                    
                    <div id="ai-batch-result" class="ai-scroll-view" style="padding: 25px; line-height: 1.7; color: #374151; font-size: 15px; word-break: break-word; white-space: pre-wrap;">
                        <div style="color: #9ca3af; text-align: center; margin-top: 100px;">
                            <div style="font-size: 40px; margin-bottom: 15px; opacity: 0.3;">📝</div>
                            请在左侧勾选文章，<br>然后点击右上角的蓝色按钮。
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.dialog = new dijit.Dialog({
            title: "", 
            content: content,
            style: "width: 95vw; min-width: 1000px; height: 85vh; padding: 0; border: none; background: transparent; box-shadow: none;"
        });

        const node = this.dialog.domNode;
        if(node) {
            const container = node.querySelector('.dijitDialogPaneContent');
            if(container) {
                container.style.padding = '0';
                container.style.border = 'none';
                container.style.background = 'transparent';
                container.style.overflow = 'visible'; 
            }
            const titleBar = node.querySelector('.dijitDialogTitleBar');
            if(titleBar) titleBar.style.display = 'none'; 
        }

        this.dialog.show();
        this.populateArticleList();
    },

    closeDialog: function() {
        if (this.dialog) this.dialog.hide();
    },

    populateArticleList: function() {
        this.currentArticles = [];
        
        let rows = document.querySelectorAll("div[id^='RROW-']");
        if (rows.length === 0) rows = document.querySelectorAll(".hlRow");

        if (rows.length === 0) {
            const listContainer = document.getElementById("ai-batch-list");
            if (listContainer) listContainer.innerHTML = "<div style='padding:20px; color:#ef4444; text-align:center; font-size:13px;'>未找到文章。<br>请确保后台文章列表已加载。</div>";
            return;
        }

        const feedsSet = new Set();
        let lastFeedTitle = "其他订阅源";

        rows.forEach((row) => {
            const prevSibling = row.previousElementSibling;
            if (prevSibling && prevSibling.classList.contains("cdmGroupHeader")) {
                const groupTitle = prevSibling.querySelector(".title, a");
                if (groupTitle) lastFeedTitle = groupTitle.innerText.trim();
            }

            let id = 0;
            if (row.id && row.id.startsWith("RROW-")) {
                id = parseInt(row.id.replace("RROW-", ""));
            } else if (row.getAttribute("data-article-id")) {
                id = parseInt(row.getAttribute("data-article-id"));
            }

            if (!id) return;

            let title = "Unknown";
            const titleLink = row.querySelector("a.title, span.title");
            if (titleLink) title = titleLink.innerText.trim();

            let feedTitle = lastFeedTitle; 
            const feedLink = row.querySelector("a.feed, span.feed, div.cdmFeedTitle a");
            if (feedLink) {
                feedTitle = feedLink.innerText.trim();
                lastFeedTitle = feedTitle;
            }
            
            if (feedTitle) feedsSet.add(feedTitle);

            const isUnread = row.classList.contains("Unread") || row.className.indexOf("Unread") > -1;

            this.currentArticles.push({
                id: id,
                title: title,
                feed: feedTitle,
                isUnread: isUnread
            });
        });

        this.populateFeedSelect(feedsSet);
        this.renderArticleList();
    },

    populateFeedSelect: function(feedsSet) {
        const select = document.getElementById("ai-batch-filter-feed");
        if (!select) return;
        select.innerHTML = '<option value="">📚 所有订阅源</option>';
        Array.from(feedsSet).sort().forEach(feed => {
            if (!feed) return;
            const option = document.createElement("option");
            option.value = feed;
            option.innerText = feed;
            select.appendChild(option);
        });
    },

    renderArticleList: function() {
        const listContainer = document.getElementById("ai-batch-list");
        const countDiv = document.getElementById("ai-batch-count");
        const searchInput = document.getElementById("ai-batch-search");
        const feedSelect = document.getElementById("ai-batch-filter-feed");
        const unreadCheckbox = document.getElementById("ai-batch-filter-unread");

        if (!listContainer) return;

        const keyword = searchInput ? searchInput.value.toLowerCase() : "";
        const selectedFeed = feedSelect ? feedSelect.value : "";
        const onlyUnread = unreadCheckbox ? unreadCheckbox.checked : false;

        listContainer.innerHTML = "";
        let visibleCount = 0;

        this.currentArticles.forEach((article) => {
            if (onlyUnread && !article.isUnread) return;
            if (selectedFeed && article.feed !== selectedFeed) return;
            if (keyword && article.title.toLowerCase().indexOf(keyword) === -1) return;

            visibleCount++;

            const itemDiv = document.createElement("div");
            itemDiv.id = "ai-article-row-" + article.id;
            itemDiv.style.padding = "8px 10px";
            itemDiv.style.borderBottom = "1px solid #f3f4f6";
            itemDiv.style.fontSize = "13px";
            itemDiv.style.display = "flex";
            itemDiv.style.alignItems = "flex-start"; 
            itemDiv.style.cursor = "pointer";
            itemDiv.style.transition = "background-color 0.1s";
            
            itemDiv.onmouseover = () => itemDiv.style.backgroundColor = "#f0f9ff";
            itemDiv.onmouseout = () => itemDiv.style.backgroundColor = article.isUnread ? "#fff" : "#fafafa";

            itemDiv.onclick = (e) => {
                if (e.target.type !== 'checkbox') {
                    const cb = itemDiv.querySelector('input[type="checkbox"]');
                    if (cb) cb.checked = !cb.checked;
                }
            };
            
            if (article.isUnread) {
                itemDiv.style.fontWeight = "600";
                itemDiv.style.color = "#111";
                itemDiv.style.backgroundColor = "#fff";
                itemDiv.style.borderLeft = "3px solid #3b82f6"; 
            } else {
                itemDiv.style.color = "#6b7280";
                itemDiv.style.backgroundColor = "#fafafa";
                itemDiv.style.borderLeft = "3px solid transparent";
            }

            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.value = article.id;
            checkbox.className = "ai-batch-check";
            checkbox.id = "ai-check-" + article.id;
            checkbox.style.marginTop = "3px"; 
            checkbox.style.marginRight = "10px";
            checkbox.style.cursor = "pointer";

            const contentDiv = document.createElement("div");
            contentDiv.style.flex = "1";
            contentDiv.style.overflow = "hidden"; 

            const titleSpan = document.createElement("div");
            titleSpan.innerText = article.title;
            titleSpan.style.marginBottom = "3px";
            titleSpan.style.lineHeight = "1.4";
            titleSpan.style.wordBreak = "break-all"; 
            titleSpan.style.whiteSpace = "normal";   

            const feedSpan = document.createElement("div");
            feedSpan.innerText = article.feed;
            feedSpan.style.fontSize = "11px";
            feedSpan.style.color = "#9ca3af";
            feedSpan.style.fontWeight = "normal";
            
            contentDiv.appendChild(titleSpan);
            contentDiv.appendChild(feedSpan);

            itemDiv.appendChild(checkbox);
            itemDiv.appendChild(contentDiv);
            listContainer.appendChild(itemDiv);
        });

        if (visibleCount === 0) {
            listContainer.innerHTML = "<div style='padding:30px; text-align:center; color:#9ca3af; font-size:13px;'>无符合条件的文章</div>";
        }

        if (countDiv) countDiv.innerText = `显示 ${visibleCount} / ${this.currentArticles.length}`;
    },

    selectAllFiltered: function(checked) {
        const checks = document.querySelectorAll(".ai-batch-check");
        checks.forEach(c => c.checked = checked);
    },

    // --- 使用后端接口标记已读 (v4.8) ---
    markSelectedRead: function() {
        const checks = document.querySelectorAll(".ai-batch-check:checked");
        if (checks.length === 0) {
            alert("请至少选择一篇文章！");
            return;
        }

        const ids = Array.from(checks).map(c => c.value);
        if (!confirm(`将 ${ids.length} 篇文章标记为已读？`)) return;

        const btn = document.getElementById("ai-btn-mark-read");
        const originalText = btn.innerText;
        btn.innerText = "处理中...";
        btn.disabled = true;

        // 使用 pluginhandler 调用自定义接口
        const query = {
            op: "pluginhandler",
            plugin: "ai_summary",
            method: "mark_read",
            ids: ids.join(","),
            csrf_token: this.getCsrfToken()
        };

        dojo.xhrPost({
            url: "backend.php",
            content: query,
            load: (res) => {
                btn.innerText = originalText;
                btn.disabled = false;

                try {
                    const json = JSON.parse(res);
                    if (json.status !== "success") {
                        alert("错误: " + json.message);
                        return;
                    }
                } catch(e) {
                    alert("错误: 无法解析服务器响应");
                    return;
                }

                // 1. 本地更新弹窗内的状态
                ids.forEach(id => {
                    const art = this.currentArticles.find(a => a.id == id);
                    if (art) art.isUnread = false;
                    
                    const row = document.getElementById("ai-article-row-" + id);
                    if (row) {
                        row.style.fontWeight = "normal";
                        row.style.color = "#6b7280";
                        row.style.backgroundColor = "#fafafa";
                        row.style.borderLeft = "3px solid transparent";
                    }
                });

                // 2. 如果开启了仅看未读，刷新列表
                if (document.getElementById("ai-batch-filter-unread").checked) {
                    this.renderArticleList();
                }

                // 3. 通知主界面刷新
                try {
                    if (typeof HeadLines != 'undefined' && HeadLines.toggleUnread) {
                        HeadLines.toggleUnread(ids); 
                    }
                } catch(e) {
                    console.warn("Could not sync with HeadLines:", e);
                }

                if (typeof Notify != 'undefined') Notify.msg(`已将 ${ids.length} 篇文章标记为已读`, true);
            },
            error: (err) => {
                btn.innerText = originalText;
                btn.disabled = false;
                console.error("Mark Read Error:", err);
                alert("请求失败，请检查网络或日志。");
            }
        });
    },

    executeBatchSummary: function() {
        const checks = document.querySelectorAll(".ai-batch-check:checked");
        if (checks.length === 0) {
            alert("请至少选择一篇文章！");
            return;
        }
        const ids = Array.from(checks).map(c => c.value);
        const resultDiv = document.getElementById("ai-batch-result");
        resultDiv.innerHTML = `<div style='text-align:center; margin-top:100px; color:#374151;'>
            <div style="font-size:30px; margin-bottom:15px; animation: spin 2s linear infinite;">⏳</div>
            <div style="font-weight:600; font-size:16px;">正在智能分析 ${ids.length} 篇文章...</div>
            <div style="font-size:13px; color:#6b7280; margin-top:8px;">AI 正在阅读并提取核心观点，请稍候。</div>
        </div>`;
        
        dojo.xhrPost({
            url: "backend.php",
            content: {
                op: "pluginhandler",
                plugin: "ai_summary",
                method: "batch_summarize",
                ids: ids.join(","),
                csrf_token: this.getCsrfToken()
            },
            load: (res) => {
                try {
                    const json = JSON.parse(res);
                    if (json.status === "success") {
                        resultDiv.innerHTML = json.html;
                    } else {
                        resultDiv.innerHTML = `<div style='color:#ef4444; background:#fef2f2; padding:15px; border-radius:6px; border:1px solid #fee2e2;'>
                            <strong>生成失败:</strong> ${json.message}
                        </div>`;
                    }
                } catch (e) {
                    resultDiv.innerHTML = "<div style='color:red'>Error parsing response: " + e + "</div>";
                }
            },
            error: (err) => {
                resultDiv.innerHTML = "<div style='color:red'>Network Error.</div>";
            }
        });
    },

    // --- 单篇总结 (保持原样) ---
    summarize: function(articleId) {
        // ... (保持原有逻辑) ...
        const query = {
            op: "pluginhandler",
            plugin: "ai_summary",
            method: "manual_summarize",
            id: articleId,
            csrf_token: this.getCsrfToken()
        };

        let icon = null;
        if (window.event && window.event.target && window.event.target.tagName === 'IMG') {
            icon = window.event.target;
            icon.src = "images/indicator_tiny.gif";
        }

        dojo.xhrPost({
            url: "backend.php",
            content: query,
            load: (responseText) => {
                try {
                    const response = JSON.parse(responseText);
                    if (response.status === "success") {
                        this.injectSummaryToDom(articleId, response.html);
                    } else {
                        alert("AI Summary Failed: " + (response.message || "Unknown error"));
                    }
                } catch (e) {
                    alert("Error parsing AI response.");
                }
            },
            error: () => {
                alert("Network error.");
            }
        });
    },

    injectSummaryToDom: function(articleId, html) {
        let contentDiv = null;
        const cdmArticle = document.getElementById("CID-" + articleId);
        if (cdmArticle) contentDiv = cdmArticle.querySelector(".content, .postContent");
        
        if (!contentDiv && dijit.byId("content-insert")) {
            contentDiv = dijit.byId("content-insert").domNode.querySelector(".postContent, .content");
        }

        if (!contentDiv) {
            const allContents = document.querySelectorAll(".postContent");
            if (allContents.length === 1) contentDiv = allContents[0];
        }

        if (contentDiv) {
            const existing = contentDiv.querySelector(".ai-summary-box");
            if (existing) existing.remove();
            const tempDiv = document.createElement("div");
            tempDiv.innerHTML = html;
            if (contentDiv.firstChild) contentDiv.insertBefore(tempDiv.firstChild, contentDiv.firstChild);
            else contentDiv.appendChild(tempDiv.firstChild);
            
            if (!this.aiViewEnabled && typeof Notify != 'undefined') Notify.msg("Summary generated", true);
        }
    }
};